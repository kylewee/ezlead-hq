/**
 * EZ Dispatch WebSocket Server
 *
 * Notification relay ONLY. No business logic. No durable state.
 *
 * Clients (dispatch dashboard) connect via ws://localhost:8765
 * PHP sends events via HTTP POST to localhost:8766/notify
 *
 * Event format: { type: "incoming_call"|"sms"|"video_request"|"call_state", data: {...} }
 */

const { WebSocketServer, WebSocket } = require('ws');
const http = require('http');

const WS_PORT = 8765;
const HTTP_PORT = 8766;
const AUTH_TOKEN = process.env.DISPATCH_WS_TOKEN || 'dispatch-dev-token';

// Track connected dashboard clients
const clients = new Set();

// Presence state (in-memory only)
const presence = new Map(); // clientId -> { status, since }

// --- WebSocket Server (dashboard clients connect here) ---
const wss = new WebSocketServer({ port: WS_PORT });

wss.on('connection', (ws, req) => {
    const clientId = `client_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    ws.clientId = clientId;
    clients.add(ws);
    presence.set(clientId, { status: 'online', since: Date.now() });

    console.log(`[WS] Client connected: ${clientId} (total: ${clients.size})`);

    // Send current state on connect
    ws.send(JSON.stringify({
        type: 'connected',
        clientId,
        presence: Object.fromEntries(presence),
    }));

    ws.on('message', (raw) => {
        try {
            const msg = JSON.parse(raw);
            handleClientMessage(ws, msg);
        } catch (e) {
            console.error('[WS] Bad message:', e.message);
        }
    });

    ws.on('close', () => {
        clients.delete(ws);
        presence.delete(clientId);
        console.log(`[WS] Client disconnected: ${clientId} (total: ${clients.size})`);
        broadcast({ type: 'presence_update', presence: Object.fromEntries(presence) });
    });

    ws.on('error', (err) => {
        console.error(`[WS] Error for ${clientId}:`, err.message);
    });
});

function handleClientMessage(ws, msg) {
    switch (msg.type) {
        case 'presence':
            presence.set(ws.clientId, { status: msg.status, since: Date.now() });
            broadcast({ type: 'presence_update', presence: Object.fromEntries(presence) });
            break;

        case 'call_answered':
        case 'call_declined':
        case 'call_ended':
            broadcast({ type: msg.type, data: msg.data });
            break;

        case 'ping':
            ws.send(JSON.stringify({ type: 'pong' }));
            break;

        default:
            console.log(`[WS] Unknown message type: ${msg.type}`);
    }
}

function broadcast(msg) {
    const payload = JSON.stringify(msg);
    for (const client of clients) {
        if (client.readyState === WebSocket.OPEN) {
            client.send(payload);
        }
    }
}

// --- HTTP Server (PHP sends notifications here) ---
const httpServer = http.createServer((req, res) => {
    if (req.method === 'POST' && req.url === '/notify') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
            const authHeader = req.headers['authorization'] || '';
            if (authHeader !== `Bearer ${AUTH_TOKEN}`) {
                res.writeHead(401);
                res.end('Unauthorized');
                return;
            }

            try {
                const event = JSON.parse(body);
                console.log(`[HTTP] Event received: ${event.type}`);
                broadcast(event);
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true, clients: clients.size }));
            } catch (e) {
                res.writeHead(400);
                res.end('Invalid JSON');
            }
        });
    } else if (req.method === 'GET' && req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'ok',
            clients: clients.size,
            presence: Object.fromEntries(presence),
            uptime: process.uptime(),
        }));
    } else {
        res.writeHead(404);
        res.end('Not found');
    }
});

httpServer.listen(HTTP_PORT, '127.0.0.1', () => {
    console.log(`[HTTP] Notification listener on 127.0.0.1:${HTTP_PORT}`);
});

console.log(`[WS] WebSocket server on port ${WS_PORT}`);
console.log(`[WS] Auth token: ${AUTH_TOKEN.slice(0, 8)}...`);
