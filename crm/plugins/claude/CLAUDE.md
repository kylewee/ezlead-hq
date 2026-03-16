# CRM Plugin - Claude Reference

## Entity Field Maps

### Entity 42 - Mechanic Jobs
| Field | Name | Type |
|-------|------|------|
| 354 | Customer Name | Text (is_heading) |
| 355 | Phone | Text |
| 356 | Email | Text |
| 357 | Address | Text |
| 358 | Vehicle Year | Text |
| 359 | Vehicle Make | Text |
| 360 | Vehicle Model | Text |
| 361 | Problem | Text |
| 362 | Stage | Dropdown (see stage table below) |
| 363 | Labor Hours | Decimal |
| 364 | Parts Cost | Decimal |
| 365 | Labor Cost | Decimal |
| 366 | Total | Decimal |
| 367 | Estimate Details | Textarea |
| 368 | Appointment | DateTime |
| 369 | Parts to Order | Text |
| 370 | Payment Link | Text |
| 371 | Payment Status | Dropdown (91=Pending, 92=Invoice Sent, 93=Paid, 94=Overdue) |
| 372 | Notes | Textarea |
| 373 | Recording | File |
| 374 | Transcript | Textarea |
| 467 | Diagnostic | Entity link (49) |
| 475 | Business | Entity link (50) |

### Mechanic Job Stages (field 362)
| Choice ID | Stage | Automation |
|-----------|-------|------------|
| 82 | New Lead | AI estimate + email |
| 83 | Estimate Sent | - |
| 84 | Accepted | - |
| 85 | Scheduled | 24hr reminder email |
| 86 | Parts Ordered | - |
| 87 | Confirmed | - |
| 88 | In Progress | - |
| 89 | Complete | Invoice + payment link |
| 90 | Paid | Follow-up in 3 days |
| 95 | Follow Up | Review request in 2 days |
| 96 | Review Request | Final stage |

### Entity 49 - Diagnostics (child of 42)
| Field | Name | Type |
|-------|------|------|
| 448 | Job | Entity link (42) |
| 449 | Status | Dropdown (129=Pending, 130=In Progress, 131=Complete) |
| 450-457 | Manual fields | DTCs, TSBs, Trouble Areas, Component Data, Torque Specs, Pan Inspection, Conclusion, Recommended Repair |
| 458-461 | M1 auto-fill | M1 Labor Hours, Parts Cost, Repair Name, Raw Data |
| 462-463 | Final estimate | Est Labor Hours, Est Parts Cost |
| 465-466 | Actuals | Actual Hours, Actual Parts Cost |

Pending triggers M1 auto-lookup (Block 7), Complete triggers estimate+PDF+email (Block 8).

### Entity 30 - Sessions
| Field | Name | Type |
|-------|------|------|
| 290 | Title | Text (is_heading) |
| 291 | Project | Entity link (21) |
| 292 | Status | Dropdown (141=Active, 142=Archived) |
| 293 | Started | DateTime |
| 294 | Ended | DateTime |
| 295 | Transcript | Textarea |
| 296 | Summary | Textarea |
| 501 | Business | Entity link (50) |

### Entity 35 - Insights (child of 30)
| Field | Name | Type |
|-------|------|------|
| 319 | Insight | Textarea |
| 320 | Category | Dropdown (Website, Business, Tech, Ideas) |
| 321 | Project | Entity link (21) |
| 426 | Label | Text (is_heading) |

### Entity 36 - Actions (top-level)
| Field | Name | Type |
|-------|------|------|
| 328 | Task | Text (is_heading) |
| 329 | Priority | Dropdown (178=High, 179=Medium, 180=Low) |
| 330 | Done | Checkboxes (181=Done) |
| 332 | Due Date | DateTime |
| 446 | Project | Entity link (21) |
| 496 | Business | Entity link (50) |
| 500 | Session | Entity link (30) |
| 502 | Branch | Dropdown (182=Mechanic, 183=Money, 184=Legal, 185=CRM/Infrastructure, 186=Lead Gen, 187=Move, 188=Family) |

### Entity 25 - Leads
| Field | Name | Type |
|-------|------|------|
| 210 | Name | Text |
| 211 | Phone | Text |
| 212 | Email | Text |
| 215 | Source | Text (domain) |
| 218 | Status | Text |

### Entity 29 - Appointments
| Field | Name | Type |
|-------|------|------|
| 255 | Title | Text |
| 257 | Date/Time | DateTime |
| 258 | Location | Text |
| 260 | Confirmed | Yes/No |

### Entity 50 - Businesses
| ID | Name |
|----|------|
| 1 | EzLead |
| 2 | Ez Mobile Mechanic |
| 3 | Claude |

## Plugin Files

### Core
| File | Purpose |
|------|---------|
| `mechanic_automation.php` | 11-stage workflow automation (5-min cron) |
| `DiagnosticService.php` | Mitchell1 auto-lookup for diagnostics |
| `sidebar.php` | CRM sidebar override (6 sections in More dropdown) |
| `dashboard.php` | Dashboard override |
| `config.php` | Centralized credentials (SignalWire, OpenAI, Anthropic) |
| `MECHANIC-WORKFLOW-GUIDE.md` | Plain-English workflow guide |

### Mission Control
| File | Purpose |
|------|---------|
| `mc3.js` | Mission Control frontend (loaded by iPage 6) |
| `mc_data.php` | Mission Control tree JSON endpoint |
| `mc_health.php` | Health probe module |
| `mc_page.php` | Mission Control page loader |

### AJAX Endpoints
| File | Purpose |
|------|---------|
| `ajax_chat.php` | AI Chat backend |
| `ajax_estimate.php` | Quick Estimate backend |
| `ajax_lead_triage.php` | Lead triage backend (no frontend yet) |
| `ajax_quick_edit.php` | Inline record editing backend |
| `includes/ajax_action.php` | Quick actions (mark_done, advance_stage) |

### Frontend
| File | Purpose |
|------|---------|
| `quick_edit.js` | Inline record editing frontend |
| `tracker.js` | Website analytics tracking (client-side) |

### Dashboards & Data
| File | Purpose |
|------|---------|
| `websites_dashboard.php` | Websites dashboard JSON endpoint (iPage 3) |
| `analytics_briefing.php` | Analytics dashboard (iPage 5) |
| `track.php` | Website analytics tracking (server-side receiver) |

### Cron / Background
| File | Purpose |
|------|---------|
| `uptime_monitor.php` | Website uptime checker (5-min cron) |
| `archive_sessions.php` | Session auto-archiver |

### Utilities
| File | Purpose |
|------|---------|
| `whisper_stt.php` | Voice-to-text via OpenAI Whisper |
| `command_bridge.php` | Sandboxed command execution for AI Chat |

### Integrations
| File | Purpose |
|------|---------|
| `zoho_api.php` | Zoho Books API integration (not yet active) |
| `zoho_callback.php` | Zoho Books OAuth callback (not yet active) |

### Modules
| Directory | Purpose |
|-----------|---------|
| `modules/with_selected/` | Bulk quick actions for record lists |

## CRM URLs

| Page | URL pattern |
|------|-----|
| Job record | `module=items/info&path=42-{ID}` |
| Jobs Kanban | `module=ext/kanban/view&id=4` |
| Calendar | `module=ext/pivot_calendars/view&id=1` |
| Sessions | `module=items/items&path=30` |
| Tasks | `module=items/items&path=36` |
| AI Chat | `module=ext/ipages/view&id=1` |
| Mission Control | `module=ext/ipages/view&id=6` |

## Rukovoditel Admin Tables

- `app_fields` - field definitions (listing_status, forms_tabs_id)
- `app_fields_choices` - dropdown options (fields_id, value, bg_color)
- `app_forms_tabs` - form tab groups (fields with forms_tabs_id=0 are invisible!)
- `app_ext_kanban` - kanban config (users_groups + assigned_to must not be empty)
- `app_listing_highlight_rules` - row color coding
- `app_records_visibility_rules` - multi-business filtering

See `~/.claude/projects/-var-www-ezlead-hq/memory/rukovoditel-patterns.md` for full patterns guide.
