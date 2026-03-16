# CRM Template Design - Mechanic Jobs Pipeline
## Best of HubSpot + Jobber + ServiceTitan merged for Ez Mobile Mechanic

### Status: IN PROGRESS
- [x] Pipeline flow with auto-communications
- [x] SMS templates
- [x] Email templates
- [ ] PDF templates (estimates + invoices)
- [ ] Implementation plan
- [ ] Build & deploy

---

## Pipeline Flow

| Stage | CRM Value | Auto-Communication |
|-------|-----------|-------------------|
| New Lead | 82 | SMS+Email: acknowledgment |
| Estimate Sent | 83 | SMS+Email+PDF: branded estimate with approve link |
| Follow-up #1 | (auto, 2 days) | SMS: check in on estimate |
| Follow-up #2 | (auto, 6 days) | SMS: estimate still available |
| Accepted | 84 | SMS+Email: scheduling prompt |
| Scheduled | 85 | SMS+Email: appointment confirmation |
| Reminder | (auto, 24hr before) | SMS: reminder |
| Confirmed | 87 | SMS: see you tomorrow |
| On My Way | (manual trigger) | SMS: ETA |
| In Progress | 88 | (no customer comm) |
| Complete | 89 | SMS+Email: work summary, invoice coming |
| Invoice | (payment status) | SMS+Email+PDF: invoice with pay link |
| Payment Reminder | (auto, 3 days) | SMS: friendly reminder |
| Paid | 90 | Email: receipt |
| Post-Service Check-in | (auto, 1 day after paid) | SMS: "Hope your {{MAKE}} is running better? Any questions or concerns?" |
| Review Request | 96 (auto, 2 days after paid, skip if negative reply) | SMS+Email: Google review request |

---

## SMS Templates

| Trigger | Template |
|---------|----------|
| New Lead | Hey {{NAME}}, this is Kyle from Ez Mobile Mechanic. Got your request about the {{YEAR}} {{MAKE}} {{MODEL}}. I'll have an estimate for you shortly! |
| Estimate Sent | Hi {{NAME}}, here's your estimate for {{PROBLEM}} on the {{YEAR}} {{MAKE}} {{MODEL}}: ${{TOTAL_LOW}}-${{TOTAL_HIGH}}. Details: {{ESTIMATE_LINK}} Reply YES to approve or call me with questions. |
| Follow-up #1 (2 days) | Hey {{NAME}}, just checking in on that estimate for your {{MAKE}} {{MODEL}}. Any questions? - Kyle |
| Follow-up #2 (6 days) | Hi {{NAME}}, your estimate is still good if you want to move forward. No pressure, just let me know. - Kyle |
| Accepted | Awesome {{NAME}}! Let's get you scheduled. What day works best? I'm mobile so I come to you at {{ADDRESS}}. |
| Scheduled | You're booked for {{APPT_DATE}} at {{APPT_TIME}}. I'll text you when I'm on my way. - Kyle, Ez Mobile Mechanic |
| Reminder (24hr) | Reminder: I'll be at {{ADDRESS}} tomorrow at {{APPT_TIME}} for your {{MAKE}} {{MODEL}}. See you then! |
| On My Way | Hey {{NAME}}, heading your way now. About {{ETA_MINUTES}} minutes out. - Kyle |
| Job Complete | All done! {{WORK_SUMMARY}}. Invoice coming shortly. Let me know if you have any questions. |
| Invoice Sent | Hi {{NAME}}, invoice for ${{TOTAL}} is ready. Pay link: {{PAY_LINK}} - Zelle/Venmo/card all work. Thanks! |
| Payment Reminder (3 days) | Hey {{NAME}}, friendly reminder on your invoice for ${{TOTAL}}. Pay link: {{PAY_LINK}} |
| Payment Received | Got it, thanks {{NAME}}! Appreciate your business. |
| Post-Service Check-in (1 day) | Hello {{NAME}}, I hope your {{MAKE}} is running better? Please let me know if you have any questions or concerns. |
| Review Request (2 days) | Hey {{NAME}}, how's the {{MAKE}} running? If you're happy with the work, a quick Google review would mean a lot: {{REVIEW_LINK}} |

---

## Email Templates

All emails use branded wrapper:
- Header: Ez Mobile Mechanic logo + (904) 217-5152 + kyle@mobilemechanic.best
- Footer: Ez Mobile Mechanic | St. Augustine & Jacksonville, FL | We Come To You
- Colors: Primary #1e40af (blue), Accent #f59e0b (amber)

### 1. Estimate Email
Subject: Estimate for your {{YEAR}} {{MAKE}} {{MODEL}} - Ez Mobile Mechanic
Body: Vehicle info, repair name, labor hours/cost, parts range, total range, notes, APPROVE button, PDF attached

### 2. Appointment Confirmation
Subject: Your appointment is confirmed - {{APPT_DATE}}
Body: Date, time, location, vehicle, service description

### 3. Invoice Email
Subject: Invoice #{{INVOICE_NUM}} - {{YEAR}} {{MAKE}} {{MODEL}}
Body: Work summary, labor, parts, total, PAY NOW button, PDF attached

### 4. Payment Receipt
Subject: Payment received - Thank you!
Body: Amount, date, method, vehicle, service summary

### 5. Review Request
Subject: How did we do, {{NAME}}?
Body: Mention the repair, ask for 30-second Google review, LEAVE A REVIEW button

---

## PDF Templates (TODO)
- Estimate PDF: branded header, vehicle info, line items, total range, terms
- Invoice PDF: branded header, work completed, line items, payment options, pay link QR code

## Implementation Notes
- SMS sending via SignalWire API (already working for call notifications)
- Email sending via PHP mail() or integrate with Zoho Books email
- PDF generation via existing PDFGenerator.php
- Automation triggers via mechanic_automation.php (5-min cron)
- Follow-up timers use date_updated as stage-change timestamp
