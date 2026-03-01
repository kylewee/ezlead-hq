/**
 * EZ Dispatch - Main Dashboard JavaScript
 *
 * Handles:
 * - WebSocket connection for real-time events
 * - WebRTC voice/video via Cloudflare Calls (through PHP proxy)
 * - SMS send/receive
 * - UI state management
 */

const CONFIG = window.DISPATCH_CONFIG;

// ============================================================
// STATE
// ============================================================
const state = {
    ws: null,
    pc: null,               // RTCPeerConnection
    cfSessionId: null,      // Cloudflare Calls session
    localStream: null,
    activeConvo: null,      // Currently selected conversation
    conversations: [],      // All conversations
    filter: 'all',          // Business filter
    reconnectTimer: null,
    view: 'queue',          // Current view: queue, convo, compose
    queueStatus: 'pending', // Queue tab filter
    queueItems: [],         // Cached queue items
};

// ============================================================
// WEBSOCKET
// ============================================================
function connectWebSocket() {
    if (state.ws && state.ws.readyState === WebSocket.OPEN) return;

    state.ws = new WebSocket(CONFIG.wsUrl);

    state.ws.onopen = () => {
        console.log('[WS] Connected');
        document.getElementById('ws-status').classList.replace('offline', 'online');
        document.getElementById('ws-status').title = 'Connected';
        clearTimeout(state.reconnectTimer);
    };

    state.ws.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        handleWSMessage(msg);
    };

    state.ws.onclose = () => {
        console.log('[WS] Disconnected');
        document.getElementById('ws-status').classList.replace('online', 'offline');
        document.getElementById('ws-status').title = 'Disconnected';
        state.reconnectTimer = setTimeout(connectWebSocket, 5000);
    };

    state.ws.onerror = (err) => {
        console.error('[WS] Error:', err);
    };
}

function handleWSMessage(msg) {
    console.log('[WS] Event:', msg.type, msg.data);

    switch (msg.type) {
        case 'connected':
            console.log('[WS] Client ID:', msg.clientId);
            break;

        case 'incoming_call':
            showIncomingAlert(msg.data);
            break;

        case 'incoming_sms':
            handleIncomingSMS(msg.data);
            break;

        case 'webrtc_request':
            showIncomingAlert({ ...msg.data, channel: 'webrtc' });
            break;

        case 'call_state':
            updateCallState(msg.data);
            break;

        case 'queue_new':
            handleQueueNew(msg.data);
            break;

        case 'queue_update':
            handleQueueUpdate(msg.data);
            break;

        case 'presence_update':
            // Future: show other dispatchers' presence
            break;

        default:
            console.log('[WS] Unhandled:', msg.type);
    }
}

function updateCallState(data) {
    console.log('[WS] Call state update:', data);
    // Update sidebar conversation status if it matches
}

// ============================================================
// CLOUDFLARE CALLS (WebRTC via server-side proxy)
// ============================================================
class CallsProxy {
    constructor(proxyUrl) {
        this.proxyUrl = proxyUrl;
        this.sessionId = null;
    }

    async request(action, body, method = 'POST') {
        const url = `${this.proxyUrl}?action=${action}${this.sessionId ? `&sessionId=${this.sessionId}` : ''}`;
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        return res.json();
    }

    async newSession(offerSDP) {
        const result = await this.request('new_session', { sdp: offerSDP });
        if (result.sessionId) {
            this.sessionId = result.sessionId;
        }
        return result;
    }

    async newTracks(tracks, offerSDP = null) {
        const body = { tracks };
        if (offerSDP) body.sdp = offerSDP;
        return this.request('new_tracks', body);
    }

    async renegotiate(answerSDP) {
        return this.request('renegotiate', { sdp: answerSDP }, 'PUT');
    }
}

const callsProxy = new CallsProxy(CONFIG.callsProxyUrl);

/**
 * Start a WebRTC call (voice or video) to answer an incoming request.
 */
async function answerWebRTCCall(cfSessionId, withVideo = false) {
    try {
        // Create peer connection
        state.pc = new RTCPeerConnection({
            iceServers: [{ urls: 'stun:stun.cloudflare.com:3478' }],
            bundlePolicy: 'max-bundle',
        });

        // Get local media
        state.localStream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: withVideo,
        });

        const localVideo = document.getElementById('local-video');
        localVideo.srcObject = state.localStream;

        // Add local tracks as sendonly
        const transceivers = state.localStream.getTracks().map(track =>
            state.pc.addTransceiver(track, { direction: 'sendonly' })
        );

        // Create session
        await state.pc.setLocalDescription(await state.pc.createOffer());
        const sessionResult = await callsProxy.newSession(state.pc.localDescription.sdp);
        await state.pc.setRemoteDescription(new RTCSessionDescription(sessionResult.sessionDescription));
        state.cfSessionId = callsProxy.sessionId;

        // Wait for ICE connection
        await new Promise((resolve, reject) => {
            state.pc.addEventListener('iceconnectionstatechange', (ev) => {
                if (ev.target.iceConnectionState === 'connected') resolve();
            });
            setTimeout(reject, 10000, 'ICE connect timeout');
        });

        // Push local tracks
        const trackObjects = transceivers.map(t => ({
            location: 'local',
            mid: t.mid,
            trackName: t.sender.track.id,
        }));

        await state.pc.setLocalDescription(await state.pc.createOffer());
        const localResult = await callsProxy.newTracks(trackObjects, state.pc.localDescription.sdp);
        await state.pc.setRemoteDescription(new RTCSessionDescription(localResult.sessionDescription));

        // Pull remote tracks (from the customer's session)
        const remoteTrackObjects = trackObjects.map(t => ({
            location: 'remote',
            sessionId: cfSessionId,
            trackName: t.trackName,
        }));

        const remoteTracksPromise = new Promise(resolve => {
            const tracks = [];
            state.pc.ontrack = (event) => {
                tracks.push(event.track);
                if (tracks.length >= (withVideo ? 2 : 1)) resolve(tracks);
            };
        });

        const remoteResult = await callsProxy.newTracks(remoteTrackObjects);
        if (remoteResult.requiresImmediateRenegotiation) {
            await state.pc.setRemoteDescription(
                new RTCSessionDescription(remoteResult.sessionDescription)
            );
            await state.pc.setLocalDescription(await state.pc.createAnswer());
            await callsProxy.renegotiate(state.pc.localDescription.sdp);
        }

        const remoteTracks = await remoteTracksPromise;
        const remoteVideo = document.getElementById('remote-video');
        const remoteStream = new MediaStream();
        remoteTracks.forEach(t => remoteStream.addTrack(t));
        remoteVideo.srcObject = remoteStream;

        // Show media area
        showMediaArea(withVideo);

        // Update call state
        if (state.ws && state.ws.readyState === WebSocket.OPEN) {
            state.ws.send(JSON.stringify({ type: 'call_answered', data: { cfSessionId } }));
        }
    } catch (err) {
        console.error('[WebRTC] Failed to answer call:', err);
        hangupCall();
    }
}

function hangupCall() {
    if (state.localStream) {
        state.localStream.getTracks().forEach(t => t.stop());
        state.localStream = null;
    }
    if (state.pc) {
        state.pc.close();
        state.pc = null;
    }
    state.cfSessionId = null;
    hideMediaArea();

    if (state.ws && state.ws.readyState === WebSocket.OPEN) {
        state.ws.send(JSON.stringify({ type: 'call_ended', data: {} }));
    }
}

// ============================================================
// SMS
// ============================================================
async function sendSMS(to, message, from = null) {
    const body = { action: 'send', to, message };
    if (from) body.from = from;
    const res = await fetch(CONFIG.smsApiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    return res.json();
}

function handleIncomingSMS(data) {
    appendSMSMessage(data.phone, data.message, 'inbound');
    updateSMSBadge(1);
}

function appendSMSMessage(phone, message, direction) {
    const thread = document.getElementById('sms-thread');
    const div = document.createElement('div');
    div.className = `sms-msg ${direction}`;
    div.textContent = message;
    thread.appendChild(div);
    thread.scrollTop = thread.scrollHeight;
}

// ============================================================
// UI HELPERS
// ============================================================
function showIncomingAlert(data) {
    const bar = document.getElementById('incoming-bar');
    const info = document.getElementById('incoming-info');

    const name = data.customer?.name || 'Unknown Caller';
    const phone = data.phone || '';
    const type = data.channel === 'webrtc' ? 'WebRTC' : 'Phone';

    info.textContent = `${type} call: ${name} ${phone}`;

    bar.classList.remove('hidden');

    // Play ringtone
    const ringtone = document.getElementById('ringtone');
    ringtone.play().catch(() => {}); // May fail without user gesture

    // Store incoming data for answer handler
    bar.dataset.incoming = JSON.stringify(data);
}

function hideIncomingAlert() {
    document.getElementById('incoming-bar').classList.add('hidden');
    document.getElementById('ringtone').pause();
    document.getElementById('ringtone').currentTime = 0;
}

function showMediaArea(withVideo) {
    document.getElementById('media-area').classList.remove('hidden');
    document.getElementById('call-controls').classList.remove('hidden');
    document.getElementById('empty-state').classList.add('hidden');
    if (!withVideo) {
        document.getElementById('remote-video').style.display = 'none';
        document.getElementById('local-video').style.display = 'none';
    }
}

function hideMediaArea() {
    document.getElementById('media-area').classList.add('hidden');
    document.getElementById('call-controls').classList.add('hidden');
    document.getElementById('remote-video').srcObject = null;
    document.getElementById('local-video').srcObject = null;
}

function updateSMSBadge(count) {
    const badge = document.getElementById('sms-badge');
    const current = parseInt(badge.textContent) || 0;
    const newCount = current + count;
    badge.textContent = newCount;
    badge.classList.toggle('hidden', newCount === 0);
}

function selectConversation(convo) {
    state.activeConvo = convo;

    showView('convo');
    document.getElementById('convo-name').textContent = convo.name || 'Unknown';
    document.getElementById('convo-phone').textContent = convo.phone || '';
    document.getElementById('convo-vehicle').textContent = convo.vehicle || '';

    // Load SMS history for this conversation
    loadSMSHistory(convo.phone);
}

async function loadSMSHistory(phone) {
    const thread = document.getElementById('sms-thread');
    thread.innerHTML = '<div style="text-align:center;color:var(--text-dim);padding:1rem;">Loading...</div>';

    try {
        const res = await fetch(CONFIG.smsApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'history', phone }),
        });
        const data = await res.json();
        thread.innerHTML = '';

        if (data.messages && data.messages.length > 0) {
            // Show oldest first
            data.messages.reverse().forEach(msg => {
                appendSMSMessage(phone, msg.body, msg.direction);
            });
        } else {
            thread.innerHTML = '<div style="text-align:center;color:var(--text-dim);padding:1rem;">No messages yet</div>';
        }
    } catch (err) {
        console.error('[SMS] History load failed:', err);
        thread.innerHTML = '<div style="text-align:center;color:var(--accent);padding:1rem;">Failed to load messages</div>';
    }
}

// ============================================================
// QUEUE
// ============================================================
async function loadQueue(status = 'pending') {
    try {
        const res = await fetch(CONFIG.queueApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'list', status }),
        });
        const data = await res.json();
        state.queueItems = data.items || [];
        renderQueue();
        updateQueueBadge();
    } catch (err) {
        console.error('[Queue] Load failed:', err);
    }
}

function renderQueue() {
    const list = document.getElementById('queue-list');
    const empty = document.getElementById('queue-empty');

    list.innerHTML = '';

    if (state.queueItems.length === 0) {
        empty.style.display = '';
        return;
    }
    empty.style.display = 'none';

    for (const item of state.queueItems) {
        list.appendChild(createQueueCard(item));
    }
}

function createQueueCard(item) {
    const card = document.createElement('div');
    card.className = `queue-card type-${item.type}`;
    card.id = `queue-item-${item.id}`;

    const ago = timeAgo(item.created_at);
    const phone = item.phone ? formatPhone(item.phone) : '';

    let actionsHtml = '';
    if (item.status === 'pending' || item.status === 'held') {
        const sendLabel = item.type === 'estimate' ? 'Send' : item.type === 'call' ? 'Callback' : 'Approve';
        actionsHtml = `
            <div class="queue-card-actions">
                <button class="queue-btn queue-btn-send" onclick="queueAct(${item.id}, 'approve')">${sendLabel}</button>
                <button class="queue-btn queue-btn-hold" onclick="queueAct(${item.id}, 'hold')">Hold</button>
                <button class="queue-btn queue-btn-spam" onclick="queueAct(${item.id}, 'spam')">Spam</button>
            </div>
        `;
    } else {
        const resultClass = item.status;
        const resultLabel = item.action_taken ?
            item.action_taken.replace(/_/g, ' ') :
            item.status;
        actionsHtml = `<div class="queue-card-result ${resultClass}">${resultLabel}</div>`;
        card.classList.add('acted');
    }

    card.innerHTML = `
        <div class="queue-card-top">
            <span class="queue-card-type">${item.type}</span>
            <span class="queue-card-time">${ago}</span>
        </div>
        <div class="queue-card-name">${escHtml(item.name || 'Unknown')}</div>
        ${phone ? `<div class="queue-card-phone">${escHtml(phone)}</div>` : ''}
        <div class="queue-card-summary">${escHtml(item.summary)}</div>
        ${item.site ? `<div class="queue-card-site">${escHtml(item.site)}</div>` : ''}
        ${actionsHtml}
    `;

    return card;
}

async function queueAct(id, action) {
    const card = document.getElementById(`queue-item-${id}`);
    if (!card) return;

    // Disable buttons immediately
    card.querySelectorAll('.queue-btn').forEach(btn => {
        btn.disabled = true;
        btn.style.opacity = '0.5';
    });

    try {
        const res = await fetch(CONFIG.queueApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'act', id, act: action }),
        });
        const result = await res.json();

        if (result.success || result.message) {
            // Animate out and remove
            card.style.transition = 'opacity 0.3s, transform 0.3s';
            card.style.opacity = '0';
            card.style.transform = 'translateX(100px)';
            setTimeout(() => {
                card.remove();
                // Update badge
                state.queueItems = state.queueItems.filter(i => i.id !== id);
                updateQueueBadge();
                // Show empty state if no items left
                if (document.getElementById('queue-list').children.length === 0) {
                    document.getElementById('queue-empty').style.display = '';
                }
            }, 300);
        } else {
            // Re-enable on error
            card.querySelectorAll('.queue-btn').forEach(btn => {
                btn.disabled = false;
                btn.style.opacity = '';
            });
            console.error('[Queue] Action failed:', result.error);
        }
    } catch (err) {
        card.querySelectorAll('.queue-btn').forEach(btn => {
            btn.disabled = false;
            btn.style.opacity = '';
        });
        console.error('[Queue] Action error:', err);
    }
}

function handleQueueNew(data) {
    // New item pushed via WebSocket - add to list if viewing pending
    if (state.queueStatus === 'pending') {
        const item = {
            id: data.id,
            type: data.type,
            status: 'pending',
            phone: data.phone,
            name: data.name,
            site: data.site,
            summary: data.summary,
            created_at: Math.floor(Date.now() / 1000),
        };
        state.queueItems.unshift(item);

        const list = document.getElementById('queue-list');
        const card = createQueueCard(item);
        card.style.animation = 'slideUp 0.3s ease';
        list.prepend(card);

        document.getElementById('queue-empty').style.display = 'none';
    }
    updateQueueBadge();

    // Show notification if not on queue view
    if (state.view !== 'queue') {
        showView('queue');
    }
}

function handleQueueUpdate(data) {
    // Item was acted on (possibly from another tab/device)
    const card = document.getElementById(`queue-item-${data.id}`);
    if (card) {
        card.style.transition = 'opacity 0.3s';
        card.style.opacity = '0';
        setTimeout(() => card.remove(), 300);
    }
    state.queueItems = state.queueItems.filter(i => i.id !== data.id);
    updateQueueBadge();
}

function updateQueueBadge() {
    const badge = document.getElementById('queue-badge');
    const pendingCount = state.queueItems.filter(i => i.status === 'pending').length;
    badge.textContent = pendingCount;
    badge.classList.toggle('hidden', pendingCount === 0);
}

// ============================================================
// VIEW MANAGEMENT
// ============================================================
function showView(view) {
    state.view = view;

    // Hide all views
    document.getElementById('queue-panel').classList.add('hidden');
    document.getElementById('compose-panel').classList.add('hidden');
    document.getElementById('convo-header').classList.add('hidden');
    document.getElementById('media-area').classList.add('hidden');
    document.getElementById('sms-thread').classList.add('hidden');
    document.getElementById('message-bar').classList.add('hidden');
    document.getElementById('quick-actions').classList.add('hidden');
    document.getElementById('empty-state').classList.add('hidden');

    // Update sidebar nav
    document.querySelectorAll('.nav-link').forEach(el => el.classList.remove('active'));

    switch (view) {
        case 'queue':
            document.getElementById('queue-panel').classList.remove('hidden');
            document.getElementById('queue-nav').classList.add('active');
            break;
        case 'compose':
            document.getElementById('compose-panel').classList.remove('hidden');
            break;
        case 'convo':
            document.getElementById('convo-header').classList.remove('hidden');
            document.getElementById('sms-thread').classList.remove('hidden');
            document.getElementById('message-bar').classList.remove('hidden');
            document.getElementById('quick-actions').classList.remove('hidden');
            break;
        default:
            document.getElementById('empty-state').classList.remove('hidden');
    }
}

// ============================================================
// HELPERS
// ============================================================
function timeAgo(unixTs) {
    const seconds = Math.floor(Date.now() / 1000) - unixTs;
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
    if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
    return Math.floor(seconds / 86400) + 'd ago';
}

function formatPhone(phone) {
    const digits = phone.replace(/\D/g, '');
    if (digits.length === 11 && digits[0] === '1') {
        return `(${digits.slice(1,4)}) ${digits.slice(4,7)}-${digits.slice(7)}`;
    }
    if (digits.length === 10) {
        return `(${digits.slice(0,3)}) ${digits.slice(3,6)}-${digits.slice(6)}`;
    }
    return phone;
}

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ============================================================
// FILTER
// ============================================================
function setFilter(filter) {
    state.filter = filter;
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.filter === filter);
    });
    // TODO: Re-render conversation list with filter
}

// ============================================================
// EVENT LISTENERS
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    // Connect WebSocket
    connectWebSocket();

    // Load queue on startup (default view)
    loadQueue('pending');

    // Auto-open queue if URL has #queue
    if (location.hash === '#queue') {
        showView('queue');
    }

    // Queue nav click
    document.getElementById('queue-nav').addEventListener('click', () => {
        showView('queue');
        loadQueue(state.queueStatus);
    });

    // Queue tab buttons
    document.querySelectorAll('.queue-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.queue-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            state.queueStatus = tab.dataset.status;
            loadQueue(tab.dataset.status);
        });
    });

    // Filter buttons
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => setFilter(btn.dataset.filter));
    });

    // Answer button
    document.getElementById('btn-answer').addEventListener('click', () => {
        const data = JSON.parse(document.getElementById('incoming-bar').dataset.incoming || '{}');
        hideIncomingAlert();
        if (data.cf_session_id) {
            const withVideo = data.type === 'video';
            answerWebRTCCall(data.cf_session_id, withVideo);
        }
        // For PSTN calls, answering happens on the phone - just dismiss the alert
    });

    // Decline button
    document.getElementById('btn-decline').addEventListener('click', () => {
        hideIncomingAlert();
        if (state.ws && state.ws.readyState === WebSocket.OPEN) {
            state.ws.send(JSON.stringify({ type: 'call_declined', data: {} }));
        }
    });

    // Hangup
    document.getElementById('btn-hangup').addEventListener('click', hangupCall);

    // Mute toggle
    document.getElementById('btn-mute').addEventListener('click', () => {
        if (state.localStream) {
            const audioTrack = state.localStream.getAudioTracks()[0];
            if (audioTrack) {
                audioTrack.enabled = !audioTrack.enabled;
                document.getElementById('btn-mute').textContent = audioTrack.enabled ? 'Mute' : 'Unmute';
            }
        }
    });

    // Camera toggle
    document.getElementById('btn-video-toggle').addEventListener('click', () => {
        if (state.localStream) {
            const videoTrack = state.localStream.getVideoTracks()[0];
            if (videoTrack) {
                videoTrack.enabled = !videoTrack.enabled;
                document.getElementById('btn-video-toggle').textContent = videoTrack.enabled ? 'Camera Off' : 'Camera On';
            }
        }
    });

    // Send SMS
    document.getElementById('btn-send').addEventListener('click', async () => {
        const input = document.getElementById('msg-input');
        const message = input.value.trim();
        if (!message || !state.activeConvo?.phone) return;

        appendSMSMessage(state.activeConvo.phone, message, 'outbound');
        input.value = '';

        await sendSMS(state.activeConvo.phone, message);
    });

    // Send on Enter
    document.getElementById('msg-input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            document.getElementById('btn-send').click();
        }
    });

    // Send video link
    document.getElementById('btn-send-video-link').addEventListener('click', async () => {
        if (!state.activeConvo?.phone) return;
        const res = await fetch('/api/video-link.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone: state.activeConvo.phone }),
        });
        const data = await res.json();
        if (data.url) {
            await sendSMS(state.activeConvo.phone, `View your video estimate here: ${data.url}`);
            appendSMSMessage(state.activeConvo.phone, `Sent video link: ${data.url}`, 'outbound');
        }
    });

    // Create job
    document.getElementById('btn-create-job').addEventListener('click', async () => {
        if (!state.activeConvo) return;
        const res = await fetch(CONFIG.crmApiUrl + '?action=create_job', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                phone: state.activeConvo.phone,
                name: state.activeConvo.name,
            }),
        });
        const data = await res.json();
        if (data.job_id) {
            console.log(`Job #${data.job_id} created`);
        }
    });

    // Screenshot from video
    document.getElementById('btn-screenshot').addEventListener('click', () => {
        const video = document.getElementById('remote-video');
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        const link = document.createElement('a');
        link.download = `screenshot-${Date.now()}.png`;
        link.href = canvas.toDataURL();
        link.click();
    });

    // New SMS button - show compose panel
    document.getElementById('btn-new-sms').addEventListener('click', () => {
        showView('compose');
        document.getElementById('compose-to').focus();
    });

    // New Call button (placeholder - will use WebRTC later)
    document.getElementById('btn-new-call').addEventListener('click', () => {
        const phone = prompt('Phone number to call:');
        if (phone) {
            console.log('[Call] Outbound to:', phone);
            // TODO: Initiate outbound call via Cloudflare Calls
        }
    });

    // Compose: Send SMS
    document.getElementById('btn-compose-send').addEventListener('click', async () => {
        const to = document.getElementById('compose-to').value.trim();
        const from = document.getElementById('compose-from').value;
        const msg = document.getElementById('compose-msg').value.trim();

        if (!to || !msg) return;

        const btn = document.getElementById('btn-compose-send');
        btn.disabled = true;
        btn.textContent = 'Sending...';

        // Remove any previous status
        document.querySelectorAll('.send-status').forEach(el => el.remove());

        const result = await sendSMS(to, msg, from);
        btn.disabled = false;
        btn.textContent = 'Send SMS';

        const status = document.createElement('div');
        status.className = 'send-status ' + (result.success ? 'success' : 'error');

        if (result.success) {
            status.textContent = `Sent to ${to}`;
            document.getElementById('compose-msg').value = '';

            // Add to SMS thread
            appendSMSMessage(to, msg, 'outbound');

            // Add to sidebar
            addSidebarItem('sms-list', { phone: to, message: msg, direction: 'outbound', time: new Date() });
        } else {
            status.textContent = `Failed: ${result.error || 'Unknown error'}`;
            if (result.detail) {
                status.textContent += ` - ${result.detail.message || JSON.stringify(result.detail)}`;
            }
        }

        document.querySelector('.compose-actions').after(status);
        setTimeout(() => status.remove(), 5000);
    });

    // Compose: Cancel
    document.getElementById('btn-compose-cancel').addEventListener('click', () => {
        showView('queue');
    });

    // Compose: Enter in message sends
    document.getElementById('compose-msg').addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('btn-compose-send').click();
        }
    });
});

// ============================================================
// SIDEBAR HELPERS
// ============================================================
function addSidebarItem(listId, data) {
    const list = document.getElementById(listId);
    const item = document.createElement('div');
    item.className = 'convo-item';
    item.innerHTML = `
        <div class="name">${data.phone || 'Unknown'}</div>
        <div class="meta">${data.message ? data.message.substring(0, 40) : ''}</div>
    `;
    item.addEventListener('click', () => {
        selectConversation({ phone: data.phone, name: data.phone });
    });
    list.prepend(item);
}
