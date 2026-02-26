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
async function sendSMS(to, message) {
    const res = await fetch(CONFIG.smsApiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ to, message }),
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

    document.getElementById('convo-header').classList.remove('hidden');
    document.getElementById('convo-name').textContent = convo.name || 'Unknown';
    document.getElementById('convo-phone').textContent = convo.phone || '';
    document.getElementById('convo-vehicle').textContent = convo.vehicle || '';

    document.getElementById('message-bar').classList.remove('hidden');
    document.getElementById('quick-actions').classList.remove('hidden');
    document.getElementById('empty-state').classList.add('hidden');

    // Load SMS history for this conversation
    loadSMSHistory(convo.phone);
}

async function loadSMSHistory(phone) {
    // TODO: Fetch SMS history from CRM
    document.getElementById('sms-thread').innerHTML = '';
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
});
