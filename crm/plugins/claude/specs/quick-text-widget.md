# Quick-Text Widget — Design Spec

**CRM Action #95** | Status: Design Only

## Overview

A HubSpot-style communication panel for sending quick SMS/email messages to customers directly from lead/job detail pages. One-click canned responses + free-text, powered by SignalWire for SMS delivery.

## Where It Lives

### Option A: Floating Action Button (Recommended)
- Fixed-position button on lead (entity 25) and job (entity 42) detail pages
- Click opens a slide-out panel on the right side
- Stays accessible while scrolling the record
- Collapsed state: small SMS icon + unread count badge

### Option B: Inline Tab
- New tab on the record's comment/form tabs area
- Pro: No overlay conflicts. Con: Requires scrolling to reach it.

## UI Components

### 1. Recipient Bar
- Auto-populated from record's phone (field_355 for jobs, field_211 for leads)
- Customer name shown as label
- Channel toggle: SMS | Email (default SMS)
- Green/red dot indicating if phone number is valid (10+ digits)

### 2. Quick Responses Grid
Canned messages displayed as clickable chips/buttons:

| Label | Message |
|-------|---------|
| On My Way | "On my way, ETA 30 min" |
| Finished Up | "Just finished up, invoice incoming" |
| Send Photo | "Can you send a photo of the issue?" |
| Running Late | "Running behind, new ETA [time]" |
| Parts Update | "Parts on order, I'll follow up when they arrive" |
| Estimate Ready | "Your estimate is ready! Check your email or reply here with questions." |
| Appointment Confirm | "Just confirming your appointment on [date]. Reply YES to confirm." |
| Payment Received | "Payment received, thank you!" |

### 3. Custom Message Area
- Free-text textarea below the quick responses
- Character count (SMS limit awareness: 160 chars)
- Clicking a quick response populates the textarea (editable before send)
- `[time]` and `[date]` placeholders auto-fill from record fields

### 4. Send Button
- Sends via SignalWire SMS API (existing integration)
- Shows sending spinner, then checkmark on success
- Logs message to record's Communication Log (field_271 for leads, field_372 for jobs)

### 5. Message History (Phase 2)
- Shows last 5 sent messages to this number
- Timestamp + delivery status from SignalWire callback

## Data Model

### New Table: `quick_text_templates`
```sql
CREATE TABLE quick_text_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    channel ENUM('sms','email','both') DEFAULT 'sms',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    business_id INT DEFAULT 0,
    created_by INT DEFAULT 0
);
```

### New Table: `quick_text_log`
```sql
CREATE TABLE quick_text_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    item_id INT NOT NULL,
    channel ENUM('sms','email') NOT NULL,
    recipient VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('sent','delivered','failed') DEFAULT 'sent',
    signalwire_sid VARCHAR(64),
    sent_by INT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Integration Points

1. **SignalWire**: Use existing `/var/www/master-template/voice/` SignalWire credentials. SMS via REST API (`Messages.create`).
2. **CRM Record**: Append sent message to Communication Log field. Format: `[2026-03-08 14:30] SMS to (904) 555-1234: "On my way, ETA 30 min" — Kyle`
3. **Pending Messages**: Replace current `pending_messages` approval flow for simple messages. Quick-text sends immediately (no approval needed for canned responses).
4. **Multi-Business**: Templates are per-business (business_id). Global templates have business_id=0.

## Permissions

- Admin: Full access, manage templates
- Mechanic role: Send only, cannot edit templates
- API user (Claude): Can send via API endpoint for automation

## API Endpoint

```
POST /crm/api/rest.php?action=quick_text
Parameters:
  key: API key
  entity_id: 42 (job) or 25 (lead)
  item_id: record ID
  channel: sms|email
  message: text (or template_id for canned)
```

## Implementation Notes

- JavaScript: Inject via `application_bottom.php` plugin hook (existing pattern)
- Only load on entity 25 and 42 detail pages (`$_GET['action'] == 'items' && $_GET['id']`)
- CSS: Match existing CRM skin variables for theme compatibility
- Mobile: Panel should be full-screen overlay on screens < 768px
- Rate limit: Max 10 SMS per record per hour to prevent spam

## Open Questions

1. Should canned responses support per-customer customization (e.g., save "Kyle prefers text over call")?
2. Approval flow for free-text messages vs. immediate send for canned?
3. Should the widget also show incoming SMS replies (requires SignalWire webhook)?
