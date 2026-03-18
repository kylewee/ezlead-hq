# Rukovoditel v3.6.4 - Calendars, Comments, Notifications & Misc Cheat Sheet

Database: `rukovoditel` on localhost. Connect with: `mysql --defaults-file=/home/kylewee/.my.cnf rukovoditel`

---

## PIVOT CALENDARS (Multi-Entity)

The main calendar system. Pulls date fields from multiple entities onto one calendar view.

### Table: `app_ext_pivot_calendars`

Defines a calendar instance.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | Calendar ID. Referenced in URL: `module=ext/pivot_calendars/view&id=1` |
| `name` | varchar(64) | Display name |
| `default_view` | varchar(16) | Initial view: `dayGridMonth`, `timeGridWeek`, `timeGridDay`, `listWeek` |
| `view_modes` | varchar(255) | Comma-separated list of available views |
| `event_limit` | smallint | Max events shown per day cell before "+N more" link (default 6) |
| `highlighting_weekends` | varchar(64) | Weekend background color, e.g. `#f5f5f5` |
| `min_time` | varchar(5) | Earliest time shown in week/day views, e.g. `06:00` |
| `max_time` | varchar(5) | Latest time shown, e.g. `22:00` |
| `time_slot_duration` | varchar(8) | Grid slot size, e.g. `00:30:00` |
| `display_legend` | tinyint | `1` = show color legend below calendar |
| `in_menu` | tinyint | `1` = show in sidebar menu |
| `users_groups` | text | Comma-separated group IDs that can see this calendar. Empty = all |
| `enable_ical` | tinyint | `1` = enable iCal feed URL |
| `sort_order` | int | Menu display order |

### Table: `app_ext_pivot_calendars_entities`

Links entities to a pivot calendar. Each row = one entity's events on the calendar.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `calendars_id` | int FK | Which pivot calendar this belongs to |
| `entities_id` | int FK | Which entity to pull records from |
| `bg_color` | varchar(10) | Default event background color (hex) |
| `start_date` | int | **Field ID** (not a date!) for the start date field, e.g. `257` = field_257 |
| `end_date` | int | **Field ID** for the end date field. Can be same as start_date for point events |
| `heading_template` | varchar(64) | Event title template, e.g. `[Title]`, `[Customer Name]`. Uses field names in brackets |
| `fields_in_popup` | text | Comma-separated field IDs shown in event popup on hover |
| `background` | varchar(10) | Unused in current config |
| `use_background` | int | Field ID of a dropdown field whose choice `bg_color` overrides the default color. `0` = disabled |
| `reminder_status` | tinyint | `1` = enable reminders for this entity's events |
| `reminder_type` | varchar(64) | `email` or `popup` |
| `reminder_minutes` | smallint | Minutes before event to trigger reminder |
| `reminder_item_heading` | text | Template for reminder text, e.g. `[Title] - [Date/Time]` |

### Current Configuration

Calendar ID 1 ("Calendar") has 3 entity feeds:

| Entity | Color | Start/End Field | Heading | Use Background |
|--------|-------|-----------------|---------|----------------|
| 29 (Appointments) | #27ae60 (green) | field_257 / field_257 | [Title] | 0 |
| 36 (Actions/Tasks) | #9b59b6 (purple) | field_332 / field_332 | [Task] | 0 |
| 42 (Mechanic Jobs) | #e67e22 (orange) | field_368 / field_368 | [Customer Name] | 0 |

All three have email reminders at 30 minutes.

### How to Add a New Entity Feed to the Calendar

```sql
INSERT INTO app_ext_pivot_calendars_entities (
    calendars_id, entities_id, bg_color, start_date, end_date,
    heading_template, fields_in_popup, background, use_background,
    reminder_status, reminder_type, reminder_minutes, reminder_item_heading
) VALUES (
    1,          -- calendar ID
    53,         -- entity ID (e.g. Estimates)
    '#3498db',  -- blue
    529,        -- start date field ID (must be a date/datetime field)
    529,        -- end date field ID (same = point event)
    '[Title]',  -- heading template
    '515,519,520', -- popup fields (comma-separated field IDs)
    '',         -- background (leave empty)
    0,          -- use_background field ID (0 = disabled)
    1,          -- reminder_status
    'email',    -- reminder_type
    30,         -- reminder_minutes
    '[Title]'   -- reminder heading
);
```

### How the Calendar Renders Events (Code Path)

File: `/var/www/ezlead-hq/crm/plugins/ext/modules/pivot_calendars/actions/view.php`

1. AJAX call `get_events` with `start`/`end` date range
2. Loops through `app_ext_pivot_calendars_entities` for the calendar
3. For each entity: queries `app_entity_N` filtering by date range on `field_{start_date}` / `field_{end_date}`
4. Applies access rules and filters
5. If `use_background > 0`, looks up the choice's `bg_color` from `app_fields_choices`
6. Returns JSON array of FullCalendar-compatible event objects
7. Drag-and-drop updates field values (resize = update end_date, drop = update both)

### Key Gotchas

- `start_date` and `end_date` columns store **field IDs**, not actual dates
- Date fields must store Unix timestamps (standard for Rukovoditel date/datetime fields)
- Events with `field_{start_date} = 0` are skipped
- Dynamic date fields (formulas) disable drag-and-drop editing
- The `use_background` feature maps a dropdown choice's `bg_color` to event color

---

## SINGLE-ENTITY CALENDAR

Separate from pivot calendars. One calendar per entity, configured inline.

### Table: `app_ext_calendar`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entities_id` | int FK | Which entity this calendar is for |
| `enable_ical` | tinyint | iCal feed toggle |
| `in_menu` | tinyint | Show in sidebar |
| `name` | varchar(64) | Display name |
| `default_view` / `view_modes` | varchar | Same as pivot calendars |
| `event_limit` / `highlighting_weekends` / `min_time` / `max_time` / `time_slot_duration` | | Same as pivot calendars |
| `start_date` | int | Field ID for start date |
| `end_date` | int | Field ID for end date |
| `heading_template` | varchar(64) | Event title template |
| `use_background` | int | Dropdown field ID for dynamic color |
| `fields_in_popup` | text | Comma-separated field IDs for popup |
| `filters_panel` | varchar(16) | `default` or custom filter panel |
| `reminder_status` / `reminder_type` / `reminder_minutes` / `reminder_item_heading` | | Same as pivot calendars |

Current: Entity 29 (Appointments) has a single-entity calendar (ID 1).

### Table: `app_ext_calendar_events`

Personal calendar events (not tied to entity records).

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `users_id` | int FK | Owner |
| `name` | varchar(255) | Event title |
| `description` | text | Event description |
| `start_date` / `end_date` | bigint | Unix timestamps |
| `event_type` | varchar(16) | Event category |
| `is_public` | tinyint | `1` = visible to others |
| `bg_color` | varchar(16) | Event color |
| `repeat_type` | varchar(16) | Recurrence: daily, weekly, monthly, yearly |
| `repeat_interval` | int | Every N periods |
| `repeat_days` | varchar(16) | Day-of-week mask for weekly |
| `repeat_end` | int | Unix timestamp when recurrence stops |
| `repeat_limit` | int | Max occurrences |

### Table: `app_ext_calendar_access`

Per-group access to single-entity calendars.

| Column | Type | Description |
|--------|------|-------------|
| `calendar_id` | int FK | |
| `calendar_type` | varchar(16) | Calendar type identifier |
| `access_groups_id` | int FK | |
| `access_schema` | varchar(64) | Access level |

---

## COMMENTS

### Table: `app_comments`

Comments/notes attached to entity records.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entities_id` | int FK | Which entity the commented item belongs to |
| `items_id` | int FK | Which record the comment is on |
| `created_by` | int FK | User ID who posted the comment |
| `description` | text | Comment body (supports HTML) |
| `attachments` | text | Serialized attachment references |
| `date_added` | bigint unsigned | Unix timestamp |

Currently 0 comments in the system.

### Table: `app_comments_access`

Controls which user groups can comment on which entities.

| Column | Type | Description |
|--------|------|-------------|
| `entities_id` | int FK | |
| `access_groups_id` | int FK | |
| `access_schema` | varchar(64) | Access level for comments |

### Table: `app_comments_forms_tabs`

Adds structured form fields to comment forms (beyond free text).

| Column | Type | Description |
|--------|------|-------------|
| `entities_id` | int FK | |
| `name` | varchar(64) | Tab/section name |
| `sort_order` | int | Display order |

### Table: `app_comments_history`

Tracks field values captured at comment time (structured comment data).

| Column | Type | Description |
|--------|------|-------------|
| `comments_id` | int FK | Parent comment |
| `fields_id` | int FK | Which field was captured |
| `fields_value` | text | Value at time of comment |

### Table: `app_ext_comments_templates`

Predefined comment templates (canned responses).

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entities_id` | int FK | Which entity this template is for |
| `name` | varchar(255) | Template name |
| `description` | text | Template body text |
| `users_groups` | text | Which groups can use this template |
| `assigned_to` | text | Which users can use this template |
| `sort_order` | int | Display order |
| `is_active` | tinyint | Active toggle |

### Adding a Comment via MySQL

```sql
INSERT INTO app_comments (entities_id, items_id, created_by, description, attachments, date_added)
VALUES (42, 123, 1, 'Note: Customer called to confirm appointment.', '', UNIX_TIMESTAMP());
```

---

## ATTACHMENTS

### Table: `app_attachments`

Temporary upload staging (form submissions before save).

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `form_token` | varchar(64) | Links upload to a form session |
| `filename` | varchar(255) | Original filename |
| `date_added` | date | Upload date |
| `container` | varchar(16) | Upload context identifier |

### Table: `app_file_storage`

Permanent file storage for entity record fields.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entity_id` | int FK | Entity the file belongs to |
| `field_id` | int FK | Which file/image field |
| `form_token` | varchar(64) | Links to upload session |
| `filename` | varchar(255) | Stored filename |
| `size` | int unsigned | File size in bytes |
| `sort_order` | int | Display order for multi-file fields |
| `folder` | varchar(255) | Subdirectory path |
| `filekey` | varchar(255) | Unique file key |
| `date_added` | bigint unsigned | Unix timestamp |
| `created_by` | int unsigned | Uploader user ID |

Files are stored on disk at: `crm/uploads/`

---

## EMAIL NOTIFICATIONS

Two separate email rule systems exist.

### Table: `app_ext_email_rules` (Primary System)

Event-driven email rules triggered by record changes.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entities_id` | int FK | Which entity triggers this rule |
| `action_type` | varchar(64) | Trigger type (see below) |
| `send_to_users` | text | Specific user IDs |
| `send_to_assigned_users` | text | Send to users in an "assigned to" field |
| `send_to_email` | text | Hardcoded email addresses |
| `send_to_assigned_email` | text | Field ID containing recipient email |
| `monitor_fields_id` | int | Field to watch for changes (for `change_field` type) |
| `monitor_choices` | text | Specific choice values that trigger the rule |
| `date_fields_id` | int | Date field for scheduled sends |
| `number_of_days` | varchar(32) | Offset days for scheduled sends (negative = before) |
| `subject` | varchar(255) | Email subject (supports `[field_name]` placeholders) |
| `description` | text | Email body (supports HTML + `[field_name]` / `[field_NNN]` placeholders) |
| `send_from_name` | varchar(255) | From name |
| `send_from_email` | varchar(255) | From email |
| `is_active` | tinyint | Active toggle |
| `attach_attachments` | tinyint | `1` = attach record's file attachments |
| `attach_template` | text | Print template to attach as PDF |
| `notes` | text | Admin notes |

#### Action Types

| `action_type` | Trigger |
|---------------|---------|
| `insert` | New record created |
| `change_field` | Field value changes to specified choice(s) |
| `edit_send_to_assigned_email` | Field changes, sends to email from a field on the record |
| `schedule_send_to_assigned_email` | Scheduled send N days before/after a date field |

#### Current Rules (11 active)

| ID | Entity | Type | Purpose |
|----|--------|------|---------|
| 1 | 25 (Leads) | insert | Admin notification for new leads |
| 2 | 25 (Leads) | change_field (218→Distributed) | Lead assigned notification |
| 3 | 42 (Jobs) | edit→field_356 (stage→87 Confirmed) | Appointment reminder to customer |
| 4 | 42 (Jobs) | edit→field_356 (stage→95 Follow Up) | Follow-up email |
| 5 | 42 (Jobs) | edit→field_356 (stage→96 Review Request) | Google review request |
| 6 | 42 (Jobs) | edit→field_356 (stage→76 Paid) | Payment thank-you + review link |
| 7 | 42 (Jobs) | schedule (field_368, -1 day) | Appointment reminder 1 day before |
| 8 | 42 (Jobs) | insert | Admin notification for new jobs |
| 9 | 42 (Jobs) | edit→field_356 (stage→69 Estimate Sent) | Send estimate to customer |
| 10 | 42 (Jobs) | edit→field_356 (stage→73 Confirmed) | Appointment confirmed email |
| 11 | 42 (Jobs) | edit→field_356 (stage→75 Complete) | Invoice/completion email |

### Table: `app_ext_email_notification_rules` (Dashboard Notification System)

Scheduled digest-style notifications (not event-driven).

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entities_id` | int FK | Which entity |
| `action_type` | varchar(64) | Notification type |
| `send_to_users` / `send_to_user_group` / `send_to_email` | text | Recipients |
| `subject` / `description` | | Email content |
| `is_active` | tinyint | Active toggle |
| `listing_type` | varchar(16) | How to format the listing in the email |
| `listing_html` / `listing_fields` | text | Custom HTML or field list |
| `notification_days` | varchar(255) | Days of week to send (comma-separated) |
| `notification_time` | varchar(255) | Time of day to send |

Currently no notification rules configured.

### Table: `app_emails_on_schedule`

Queue for outbound emails (used by scheduled email rules).

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `date_added` | bigint unsigned | When queued |
| `email_to` / `email_to_name` | varchar | Recipient |
| `email_subject` / `email_body` | | Content |
| `email_from` / `email_from_name` | | Sender |
| `email_attachments` | text | Attached files |

### SMTP Configuration

From `app_configuration`:
- Server: `smtp-relay.brevo.com:587` (TLS)
- Login: `a30432001@smtp-brevo.com`
- From: `noreply@mechanicstaugustine.com` / "Ez Mobile Mechanic"

---

## SMS RULES

### Table: `app_ext_sms_rules`

SMS notifications triggered by record events. Uses SignalWire via `app_ext_modules` (module ID 2).

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entities_id` | int FK | |
| `modules_id` | int FK | SMS module (2 = SignalWire) |
| `is_active` | tinyint | |
| `action_type` | varchar(64) | Trigger type |
| `fields_id` | int | Field reference |
| `monitor_fields_id` | int | Field to watch |
| `monitor_choices` | text | Choice values that trigger |
| `date_fields_id` | int | Date field for scheduled sends |
| `date_type` | varchar(16) | `hour` or `day` |
| `number_of_days` | varchar(32) | Offset (negative = before) |
| `phone` | varchar(255) | Hardcoded phone number |
| `send_to_assigned_users` | text | Send to user's phone |
| `description` | text | SMS body (supports `{{field_NNN}}` placeholders -- note double braces) |
| `notes` | text | Admin notes |

#### SMS Action Types

| `action_type` | Trigger |
|---------------|---------|
| `insert_send_to_number` | New record → send to hardcoded number |
| `edit_send_to_number_in_entity` | Field change → send to phone from a field (format: `field_id:phone_field_id`) |
| `schedule_send_to_number_in_entity` | Scheduled send before/after date field |

#### Current SMS Rules (4 active)

| ID | Entity | Type | Purpose |
|----|--------|------|---------|
| 1 | 27 (Transactions) | insert | Kyle SMS: new credit purchase |
| 2 | 29 (Appointments) | insert | Kyle SMS: new call scheduled |
| 3 | 51 (Call Records) | edit (field_485→153) | Customer receipt after call |
| 4 | 29 (Appointments) | schedule (-1 hour from field_257) | Callback reminder 1 hour before |

**Important**: SMS templates use `{{field_NNN}}` (double curly braces), while email templates use `[field_NNN]` (square brackets).

---

## SIDEBAR MENU

### Table: `app_entities_menu`

Defines the CRM sidebar navigation tree.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | Menu item ID |
| `parent_id` | int | `0` = top-level item. Non-zero = sub-item of that menu item |
| `name` | varchar(64) | Display text |
| `icon` | varchar(64) | FontAwesome class, e.g. `fa-wrench` |
| `icon_color` | varchar(7) | Hex color for icon |
| `bg_color` | varchar(7) | Background color |
| `entities_list` | text | Entity ID to link to (for `entity` type) |
| `reports_list` | text | Report ID filter |
| `pages_list` | text | Page ID (for `url` type linking to ipages) |
| `type` | varchar(16) | `entity`, `menu` (folder), or `url` |
| `url` | varchar(255) | Custom URL (for `url` type) |
| `users_groups` | text | Group visibility restriction |
| `assigned_to` | text | User visibility restriction |
| `sort_order` | int | Display order within parent |

#### Current Menu Tree (top-level groups)

| ID | Name | Type | Icon | Sort |
|----|------|------|------|------|
| 34 | Ez Mechanic | menu | fa-wrench | 0 |
| 35 | EzLead | menu | fa-bullhorn | 1 |
| 23 | Websites | menu | fa-globe | 2 |
| 14 | Claude Chat | menu | fa-comments | 3 |
| 53 | Legal | menu | fa-gavel | 4 |
| 20 | Workflow | menu | fa-sitemap | 5 |
| 44 | Businesses | entity | fa-building | 6 |

### Claude Sidebar Override

File: `/var/www/ezlead-hq/crm/plugins/claude/sidebar.php`

The claude plugin **completely replaces** the default sidebar with a custom layout:

1. **Business Switcher** - Dropdown at top to filter by business (cookie-based)
2. **Primary Nav** - Dashboard, Pipeline, Jobs, Estimates, Leads, Tasks, Calendar, Customers
3. **More Dropdown** - Organized into sections:
   - Views: Mission Control, Websites Dashboard, Analytics, Jobs Kanban, Diagnostics, Vehicles, Appointments
   - Tools: AI Chat, Video Chat, Analytics
   - Websites: All site links
   - Business: Businesses, Buyers, Transactions
   - AI & Notes: Sessions, Insights, Projects
   - Admin (admin-only): Configuration, App Structure, Reports, Users

The sidebar also injects:
- `quick_edit.js` for inline record editing
- Quick action buttons per entity (Mark as Paid, Mark Complete, etc.)
- Quick Estimate button on entity 53 listings

#### Adding a New Menu Item (Default System)

```sql
INSERT INTO app_entities_menu (parent_id, name, icon, icon_color, bg_color, entities_list, reports_list, pages_list, type, url, users_groups, assigned_to, sort_order)
VALUES (34, 'Estimates', 'fa-file-text-o', '', '', '53', '', '', 'entity', '', '', '', 7);
```

For the Claude sidebar, edit `/var/www/ezlead-hq/crm/plugins/claude/sidebar.php` directly.

---

## INFO PAGES (iPages)

### Table: `app_ext_ipages`

Custom HTML/PHP pages embedded in the CRM.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | Referenced in URL: `module=ext/ipages/view&id=N` |
| `parent_id` | int | `0` = top-level. Non-zero = child page |
| `name` | varchar(255) | Page title |
| `short_name` | varchar(64) | URL-safe slug |
| `menu_icon` | varchar(64) | FontAwesome class |
| `icon_color` / `bg_color` | varchar(7) | Colors |
| `description` | longtext | Full page content (HTML/JS/PHP) |
| `html_code` | text | Additional HTML |
| `users_groups` / `assigned_to` | text | Access restrictions |
| `sort_order` | int | |
| `is_menu` | tinyint | Show in menu |
| `attachments` | text | Attached files |

#### Current Pages

| ID | Name | Slug | Purpose |
|----|------|------|---------|
| 1 | Claude Chat | claude-chat | AI chat interface |
| 2 | Dashboard | dashboard | Main dashboard |
| 3 | Websites Dashboard | websites-dashboard | Site overview |
| 5 | Analytics Dashboard | analytics | Analytics view |
| 6 | Mission Control | mission-control | Tree-based overview |
| 7 | Quick Estimate | quick-estimate | Estimate calculator |
| 8 | Pipeline | pipeline | Pipeline view |

---

## KANBAN BOARDS

### Table: `app_ext_kanban`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | Referenced in URL: `module=ext/kanban/view&id=N` |
| `entities_id` | int FK | Which entity |
| `in_menu` | tinyint | Show in menu |
| `heading_template` | varchar | Card title template using `{field_NNN}` or `[NNN]` |
| `name` | varchar | Board name |
| `group_by_field` | int | Field ID to group columns by (must be dropdown) |
| `exclude_choices` | text | Comma-separated choice IDs to hide as columns |
| `fields_in_listing` | text | Comma-separated field IDs shown on cards |
| `sum_by_field` | int | Field ID to show column totals |
| `width` | int | Card width in pixels |
| `filters_panel` | varchar | Filter panel ID |
| `rows_per_page` | int | Cards per column |
| `users_groups` / `assigned_to` | text | Access restrictions |
| `is_active` | tinyint | Active toggle |

#### Current Boards

| ID | Entity | Name | Group By | Excludes |
|----|--------|------|----------|----------|
| 4 | 42 (Jobs) | Mechanic Jobs Board | field_362 (Stage) | 217-221 |
| 5 | 52 (Records Requests) | Records Requests | field_510 (Status) | - |
| 6 | 42 (Jobs) | Hotline Board | field_362 (Stage) | 82-90,95,96 |

---

## PROCESSES (Workflow Buttons)

### Table: `app_ext_processes`

Custom action buttons that appear on records or listings.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entities_id` | int FK | Which entity |
| `name` | varchar(255) | Process name |
| `button_title` | varchar(64) | Button label (empty = auto-triggered) |
| `button_position` | varchar(255) | Where button appears |
| `button_color` | varchar(7) | Button color |
| `button_icon` | varchar(64) | FontAwesome icon |
| `users_groups` / `assigned_to` | text | Who can see/use the button |
| `confirmation_text` | text | Confirmation dialog text |
| `is_active` | tinyint | |
| `allow_comments` | tinyint | Allow comment on execution |
| `javascript_in_from` | text | Custom JS in form |
| `javascript_onsubmit` | text | Custom JS on submit |

### Table: `app_ext_processes_actions`

Actions executed when a process runs.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `process_id` | int FK | Parent process |
| `is_active` | tinyint | |
| `type` | varchar(64) | Action type: `execute_php_script`, `change_field`, `send_email`, etc. |
| `description` | text | Description / script reference |
| `sort_order` | smallint | Execution order |
| `settings` | text | JSON settings |

#### Current Processes

| ID | Entity | Name | Button | Actions |
|----|--------|------|--------|---------|
| 1 | 25 (Leads) | Auto-Distribute Lead | (auto) | Execute PHP: distribute lead to buyers |
| 2 | 26 (Buyers) | Add Credit | "Add Credit" (green) | Execute PHP: add credit to balance |
| 3 | 1 (Users) | Create Buyer Record | (auto) | Execute PHP: create buyer for new user in Buyers group |
| 4 | 42 (Jobs) | Hotline Intake Auto-Workflow | (auto) | Execute PHP: notify Kyle via SMS |

---

## HIGHLIGHT RULES (Row Coloring)

### Table: `app_listing_highlight_rules`

Colors rows in entity listings based on field values.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entities_id` | int FK | Which entity |
| `is_active` | tinyint | |
| `fields_id` | int FK | Which field to check |
| `fields_values` | text | Choice ID(s) that trigger the color |
| `bg_color` | varchar(7) | Row background color |
| `users_groups` | text | Which groups see this highlight |
| `sort_order` | int | Priority (first match wins) |
| `notes` | text | Admin notes |

#### Current Rules

**Entity 42 (Mechanic Jobs) - by Stage (field_362):**

| Choice | Stage | Color |
|--------|-------|-------|
| 82 | New Lead | #E3F2FD (blue) |
| 83 | Estimate Sent | #F3E5F5 (purple) |
| 84 | Accepted | #E8F5E9 (green) |
| 85 | Scheduled | #FFF8E1 (yellow) |
| 86 | Parts Ordered | #FFF3E0 (orange) |
| 87 | Confirmed | #E0F2F1 (teal) |
| 88 | In Progress | #FFEBEE (red) |
| 89 | Complete | #C8E6C9 (bright green) |
| 90 | Paid | #A5D6A7 (dark green) |
| 95 | Follow Up | #ECEFF1 (gray) |
| 96 | Review Request | #FFE0B2 (orange) |

**Entity 25 (Leads) - by Status (field_268):**

| Choice | Status | Color |
|--------|--------|-------|
| 75 | New | #E3F2FD |
| 76 | Contacted | #FFF8E1 |
| 77 | Quoted | #F3E5F5 |
| 78 | Won | #C8E6C9 |
| 79 | Lost | #ECEFF1 |

**Entity 30 (Sessions):** 141=Active → #E1F0FF, 142=Archived → #F0F0F0

**Entity 52 (Records Requests):** 9 stages from Drafting through Dispute.

---

## RECORDS VISIBILITY RULES

### Table: `app_records_visibility_rules`

Filters records so users only see records matching their business assignment.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `entities_id` | int FK | Which entity to filter |
| `is_active` | tinyint | |
| `users_groups` | text | Which user groups this rule applies to (e.g. `4,5`) |
| `merged_fields` | text | Format: `user_field_id-entity_field_id`. Matches user's field to record's field |
| `mysql_query` | text | Custom SQL filter (alternative to merged_fields) |
| `php_code` | text | Custom PHP filter |
| `notes` | text | Description |

#### Current Rules

All use `merged_fields` format `481-NNN` where 481 = user's Business field:

| Entity | Entity Business Field | Notes |
|--------|----------------------|-------|
| 25 (Leads) | 474 | |
| 42 (Jobs) | 475 | |
| 37 (Websites) | 476 | |
| 26 (Buyers) | 477 | |
| 47 (Customers) | 478 | |
| 29 (Appointments) | 479 | |
| 21 (Projects) | 480 | |

Groups 4 and 5 are filtered. Admin (group_id=0) bypasses all visibility rules.

---

## TRACK CHANGES (Audit Log)

### Table: `app_ext_track_changes`

Audit log configuration.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `is_active` | tinyint | |
| `name` | varchar(64) | Log name |
| `position` | varchar(255) | Where to display |
| `menu_icon` | varchar(64) | Icon |
| `track_actions` | varchar(255) | Comma-separated: `insert`, `update`, `comment`, `delete` |
| `color_insert` / `color_update` / `color_comment` / `color_delete` | varchar(7) | Colors per action type |
| `rows_per_page` | smallint | Pagination |
| `keep_history` | smallint | Days to retain (0 = forever) |

### Table: `app_ext_track_changes_entities`

Which entities and fields to track.

| Column | Type | Description |
|--------|------|-------------|
| `reports_id` | int FK | Parent track_changes config |
| `entities_id` | int FK | Which entity to track |
| `track_fields` | text | Comma-separated field IDs to monitor (empty = all) |

### Table: `app_ext_track_changes_log` / `app_ext_track_changes_log_fields`

Actual audit log entries and their field-level diffs.

No track changes reports are currently configured.

---

## RECURRING TASKS

### Table: `app_ext_recurring_tasks`

Auto-create records on a schedule.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | |
| `created_by` | int FK | |
| `entities_id` | int FK | Entity to create records in |
| `items_id` | int FK | Template record to clone |
| `is_active` | tinyint | |
| `repeat_type` | varchar(16) | daily, weekly, monthly, yearly |
| `repeat_interval` | int | Every N periods |
| `repeat_days` | varchar(16) | Day-of-week mask |
| `repeat_start` / `repeat_end` | bigint | Unix timestamps for start/end of recurrence |
| `repeat_limit` | int | Max occurrences |
| `repeat_time` | tinyint | Time of day |

No recurring tasks currently configured.

---

## SESSIONS (PHP)

### Table: `app_sessions`

PHP session storage (not CRM "Sessions" entity 30).

| Column | Type | Description |
|--------|------|-------------|
| `sesskey` | varchar(32) PK | PHP session ID |
| `expiry` | bigint unsigned | Expiration timestamp |
| `value` | longtext | Serialized session data |

Session data includes: upload state, alerts, session token, user filters, calendar reminders, selected items, listing page positions.

---

## OTHER NOTABLE TABLES

### `app_holidays`
Date ranges to highlight on calendars. Schema: `name`, `start_date`, `end_date`.

### `app_favorites`
User bookmarks: `users_id`, `entities_id`, `items_id`. Star icon on records.

### `app_who_is_online`
Tracks active users: `users_id`, `date_updated`.

### `app_ext_timer`
Time tracking per record: `users_id`, `entities_id`, `items_id`, `seconds`.

### `app_logs`
System error/debug log: `users_id`, `ip_address`, `log_type`, `date_added`, `http_url`, `description`, `errno`, `filename`, `linenum`, `seconds`.

### `app_custom_php`
Custom PHP scripts callable from processes. Current scripts:
- ID 1: EzLead (folder)
- ID 2: ezlead_helpers
- ID 3: ezlead_distribute
- ID 4: ezlead_notify

### `app_global_vars`
System-wide variables accessible in templates/scripts. Current vars under folder "EzLead" (ID 1):
- `EZLEAD_MIN_BALANCE` = 5
- `EZLEAD_DEFAULT_PRICE` = 35
- `SIGNALWIRE_SPACE`, `SIGNALWIRE_PROJECT_ID`, `SIGNALWIRE_FROM_NUMBER`
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`
- `FROM_EMAIL`, `FROM_NAME`

### `app_ext_modules` / `app_ext_modules_cfg`
Extension modules and their config:
- Module 1 (smart_input): SmartyStreets address validation
- Module 2 (sms): SignalWire SMS

### `app_ext_public_forms`
Public-facing forms (no login required):
| ID | Entity | Name |
|----|--------|------|
| 1 | 25 | SOD Lead Form |
| 2 | 25 | Mechanic Lead Form |
| 3 | 42 | Hotline Intake Form |

### `app_users_notifications`
In-app notification feed: `users_id`, `entities_id`, `items_id`, `name` (notification text), `type`, `date_added`, `created_by`.

### `app_users_alerts`
Ephemeral alerts: `is_active`, `title`, `description`, `type` (popup/banner), `location` (where to show), `start_date`, `end_date`, `assigned_to`, `users_groups`, `created_by`.

### `app_dashboard_pages` / `app_dashboard_pages_sections`
Dashboard page layout. Current: "Website Analytics" page (ID 1) in section 1, type `reports`.

### `app_portlets`
Collapsible UI sections. Stores collapsed state per user: `name` (pattern: `filters_preview_N` or `item_info_N`), `users_id`, `is_collapsed`.

### `app_listing_types`
Listing display modes per entity: `entities_id`, `type` (list/grid/etc), `is_active`, `is_default`, `width`, `settings`.

### `app_ext_subscribe_rules`
Email subscription/mailing list rules: `entities_id`, `modules_id`, `contact_list_id`, `contact_email_field_id`, `contact_fields`.

### `app_forms_fields_rules`
Conditional field display rules (show/hide fields based on other field values).

### `app_composite_unique_fields`
Unique constraint rules across multiple fields.

### `app_blocked_forms` (actually `app_approved_items`)
Record approval system: `entities_id`, `items_id`, `fields_id`, `users_id`, `signature`, `date_added`.

---

## QUICK REFERENCE: Adding a Calendar for Jobs by Appointment Date

Already done (pivot calendar entity ID 3). To add another entity to the existing calendar:

```sql
-- Example: Add Estimates (entity 53) with a date field
INSERT INTO app_ext_pivot_calendars_entities (
    calendars_id, entities_id, bg_color, start_date, end_date,
    heading_template, fields_in_popup, background, use_background,
    reminder_status, reminder_type, reminder_minutes, reminder_item_heading
) VALUES (
    1, 53, '#3498db', 529, 529, '[Title]', '515,520,519', '', 0, 0, 'email', 30, '[Title]'
);
```

To create a completely new calendar:

```sql
-- Step 1: Create the calendar
INSERT INTO app_ext_pivot_calendars (
    name, default_view, view_modes, event_limit, highlighting_weekends,
    min_time, max_time, time_slot_duration, display_legend, in_menu, users_groups, enable_ical, sort_order
) VALUES (
    'Scheduling', 'timeGridWeek', 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
    6, '#f5f5f5', '06:00', '22:00', '00:30:00', 1, 1, '', 0, 2
);

-- Step 2: Get the new calendar ID
-- SET @cal_id = LAST_INSERT_ID();

-- Step 3: Add entity feeds
INSERT INTO app_ext_pivot_calendars_entities (
    calendars_id, entities_id, bg_color, start_date, end_date,
    heading_template, fields_in_popup, background, use_background,
    reminder_status, reminder_type, reminder_minutes, reminder_item_heading
) VALUES (
    @cal_id, 42, '#e67e22', 368, 368, '[Customer Name]', '354,361,362', '', 0, 1, 'email', 60, '[Customer Name]'
);
```

URL for new calendar: `index.php?module=ext/pivot_calendars/view&id=N`
