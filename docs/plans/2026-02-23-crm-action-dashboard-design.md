# CRM Action Dashboard Design

## Problem
Kyle doesn't use the CRM because he doesn't know what to click or what to do when he opens it. The current Rukovoditel interface has too many menus and no workflow guidance.

## Solution
Replace the CRM dashboard with a unified "Today" action feed that pulls from all entities and shows everything that needs attention, sorted by urgency. Simplify the sidebar to 5 core items.

## Design

### Today Dashboard (Homepage)

A single action feed with 4 sections, each pulling from multiple CRM entities automatically:

**1. OVERDUE (red)**
- Tasks (entity 36) where due date < today and not done
- Leads (entity 25) with no activity for 48+ hours and status != closed
- Mechanic jobs (entity 42) stuck in a stage too long:
  - New Lead > 24hrs without estimate = overdue
  - Estimate Sent > 72hrs without response = overdue
  - Complete > 24hrs without invoice = overdue
  - Paid > 3 days without follow-up = overdue
  - Follow Up > 2 days without review request = overdue

**2. DUE TODAY (orange)**
- Tasks (entity 36) due today
- Appointments (entity 29) scheduled today
- Mechanic jobs (entity 42) with appointments today
- Scheduled follow-ups and review requests due today

**3. NEW (blue)**
- Leads (entity 25) in "New" status from any source/business
- Mechanic jobs (entity 42) in "New Lead" stage
- Any entity created today that needs action

**4. UPCOMING THIS WEEK (gray)**
- Appointments (entity 29) in next 7 days
- Tasks (entity 36) due this week
- Scheduled mechanic jobs this week

### Action Feed Item Format

Each item in the feed shows:
```
[Icon] [Type Label]  [Entity Name/Title]           [Time ago / Due in]
       [Brief context: who, what vehicle, what task]
       [Primary Action Button]  [Secondary Action Button]
```

Examples:
```
[Phone] NEW LEAD    John Smith                      2 hours ago
        mechanicstaugustine.com - 2018 Camry brake noise
        [Send Estimate]  [View Details]

[Clock] OVERDUE     Oil change estimate - Mike R.   3 days overdue
        Estimate sent, no response
        [Call Back]  [View Job]

[Check] DUE TODAY   Follow up with drainage lead    Due today
        drainagejax.com - Jim P.
        [Mark Done]  [Reschedule]

[Cal]   TOMORROW    Brake job - Sarah K.            Tomorrow 9am
        123 Main St, St. Augustine
        [Get Directions]  [View Job]
```

### Primary Action Buttons Per Entity Type

| Entity | Stage/Status | Primary Action |
|--------|-------------|----------------|
| Mechanic Job | New Lead | Send Estimate |
| Mechanic Job | Estimate Sent | Call Back / Follow Up |
| Mechanic Job | Accepted | Schedule Appointment |
| Mechanic Job | Scheduled | Confirm / Get Directions |
| Mechanic Job | Complete | Send Invoice |
| Mechanic Job | Paid | Send Follow-up |
| Mechanic Job | Follow Up | Request Review |
| Lead (any) | New | Call / Respond |
| Lead (any) | No activity 48hrs | Follow Up |
| Task | Due/Overdue | Mark Done |
| Appointment | Today | Get Directions / Confirm |

### Sidebar Navigation (5 items)

```
[Home]      Today        ← the action feed (default landing page)
[Calendar]  Schedule     ← calendar view of all appointments
[Wrench]    Jobs         ← mechanic jobs kanban by stage
[Users]     Leads        ← all leads, table view, filterable by source
[Check]     Tasks        ← to-do checklist view
```

Bottom section (collapsed by default):
```
[Globe]     Sites        ← website monitoring
[Brain]     AI Sessions  ← Claude sessions
[Gear]      Settings     ← CRM admin stuff
```

### Technical Implementation

**Approach:** Custom plugin dashboard override

The Claude plugin at `/var/www/ezlead-hq/crm/plugins/claude/` already overrides the dashboard. We replace `dashboard.php` with the new action feed.

**Data queries:**
- All queries go through MySQL directly (same as current dashboard.php)
- Entity 42 (Mechanic Jobs): field_362=stage, field_354=name, field_368=appointment
- Entity 25 (Leads): field_210=name, field_215=source, field_218=status
- Entity 36 (Actions): field_328=task, field_330=done, field_332=due date
- Entity 29 (Appointments): field_255=title, field_257=datetime, field_260=confirmed
- Use date_created from each entity's table for "time ago" calculations

**Urgency scoring:**
Each item gets a numeric urgency score for sorting:
- Overdue by 3+ days = 100
- Overdue by 1-3 days = 80
- Due today = 60
- New (< 24hrs) = 50
- Upcoming tomorrow = 30
- Upcoming this week = 10

Items sorted by urgency score descending within each section.

**Action buttons:**
- Each button links to the CRM record edit page with the right module path
- "Send Estimate" triggers the existing mechanic_automation.php estimate flow
- "Mark Done" does an AJAX update to the entity field
- "Call" opens tel: link (works on mobile)

**Sidebar:**
- Override sidebar.php via Claude plugin menu.php
- Replace the full menu with 5 clean items
- Each links to: dashboard (Today), calendar report, entity 42 kanban, entity 25 listing, entity 36 listing

### What This Does NOT Include (YAGNI)
- No CSS reskin (Kyle said functionality only)
- No activity timeline on records (phase 2)
- No drag-and-drop kanban (use existing Rukovoditel kanban reports)
- No mobile app
- No real-time updates / websockets
