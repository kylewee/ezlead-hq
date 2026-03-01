# EZ Dispatch - Unified Communication Dashboard

## What This Is

Browser-based dispatch dashboard for Ez Mobile Mechanic. WebRTC voice/video via Cloudflare Calls,
SMS inbox/outbox, call state tracking, all wired to the CRM.

## Current State

- **Directory structure**: Scaffolded (public/, lib/, websocket/, data/, sql/, caddy/)
- **All subdirs are EMPTY** — nothing is built yet
- **Design doc exists**: Full spec with architecture, data flow, UI wireframes
- **Implementation plan exists**: Step-by-step build order
- **Cloudflare Calls apps**: damp-sun-2b0b (prod), cool-dust-8338 (dev)
- **PSTN leg works**: Call forwarding to Kyle's cell is already working in `/var/www/ezlead-platform/core/voice/`

## What Needs to Be Built

### Phase 1: Foundation
- Node.js WebSocket notification relay server
- Basic HTML/JS dashboard shell
- Authentication (CRM session or simple token)
- Caddy config for dispatch.ezlead4u.com subdomain

### Phase 2: Voice
- Cloudflare Calls WebRTC integration (browser ↔ PSTN)
- Simultaneous ring: dashboard + Kyle's cell
- Call state machine (ringing → connected → ended)
- Call log with CRM tracking

### Phase 3: SMS
- SMS inbox/outbox in dashboard (reads from SignalWire/httpSMS)
- Real-time SMS notifications via WebSocket
- Reply from dashboard

### Phase 4: Video
- Click-to-call widget for customer websites
- Video link generation for remote diagnostics
- Integration with existing PeerJS video chat at `/var/www/ezlead-platform/video/`

### Phase 5: CRM Integration
- New Conversations entity in CRM
- Auto-log all calls, SMS, video sessions
- Link conversations to Jobs/Estimates/Customers

## Tech Stack

- **Frontend**: Vanilla JS or lightweight framework, served from `public/`
- **Backend**: Node.js for WebSocket relay (`websocket/`)
- **Voice**: Cloudflare Calls API (WebRTC)
- **SMS**: SignalWire API + httpSMS
- **Database**: SQLite in `data/` for local state, CRM for permanent records
- **Server**: Caddy reverse proxy (config in `caddy/`)

## Design Docs

- **Full design**: `~/recovered-hdd/Desktop/calling/docs/plans/2026-02-21-ez-dispatch-design.md`
- **Implementation plan**: `~/recovered-hdd/Desktop/calling/docs/plans/2026-02-21-ez-dispatch-implementation.md`
- **READ THESE FIRST** — they have the full architecture, UI wireframes, and build sequence

## Related Systems

- Voice pipeline: `/var/www/ezlead-platform/core/voice/` (incoming.php, dial_result.php, recording_processor.php)
- Video chat: `/var/www/ezlead-platform/video/` (PeerJS, partially built)
- CRM: `https://ezlead4u.com/crm/` (API: claude/badass)
- Master roadmap: `/home/kylewee/docs/plans/2026-02-26-master-feature-roadmap.md`

## Cloudflare Calls

- Prod app: damp-sun-2b0b
- Dev app: cool-dust-8338
- Use MCP tools (`mcp__claude_ai_Cloudflare_Developer_Platform__*`) to manage
