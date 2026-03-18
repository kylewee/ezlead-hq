# Rukovoditel Processes, Email Rules & SMS Rules Cheat Sheet

Rukovoditel v3.6.4 at `/var/www/ezlead-hq/crm/`

---

## 1. Processes (app_ext_processes)

Processes are Rukovoditel's built-in automation system. A process = a named automation with a trigger, optional filters, and one or more actions.

### Table: `app_ext_processes`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Process ID |
| `entities_id` | int | Which entity this process belongs to |
| `name` | varchar(255) | Internal name (shown in admin) |
| `button_title` | varchar(64) | Label shown on the button (if manual trigger) |
| `button_position` | varchar(255) | **Trigger type** - comma-separated, see below |
| `button_color` | varchar(7) | Hex color for manual button |
| `button_icon` | varchar(64) | FontAwesome icon class |
| `print_template` | varchar(32) | Auto-open a print template after process runs |
| `users_groups` | text | Comma-separated group IDs that can see/run the button |
| `assigned_to` | text | Comma-separated user IDs that can see/run the button |
| `assigned_to_all` | tinyint | If 1, all users can run it |
| `access_to_assigned` | text | Field IDs - user must be assigned in one of these fields |
| `window_width` | varchar(64) | Dialog width |
| `confirmation_text` | text | Shown in confirmation dialog before running |
| `warning_text` | text | Shown when filters DON'T match (explain why button is disabled) |
| `allow_comments` | tinyint | If 1, show comment box in process dialog |
| `preview_prcess_actions` | tinyint | If 1, show what the process will do before running |
| `notes` | text | Admin notes |
| `payment_modules` | varchar(64) | Link to payment module (for checkout processes) |
| `is_active` | tinyint | 0=disabled, 1=enabled |
| `apply_fields_access_rules` | tinyint | If 1, respect field-level access rules |
| `apply_fields_display_rules` | tinyint | If 1, respect field display rules |
| `hide_entity_name` | tinyint | Hide entity name in form |
| `success_message` | text | Custom success message after process runs |
| `success_message_status` | tinyint | If 1, show success message |
| `redirect_to_items_listing` | tinyint | If 1, redirect to listing after process |
| `disable_comments` | tinyint | If 1, suppress auto-generated comment |
| `javascript_in_from` | text | Custom JS injected into the process form |
| `javascript_onsubmit` | text | Custom JS run on form submit |
| `is_form_wizard` | tinyint | If 1, use multi-step form wizard |
| `is_form_wizard_progress_bar` | tinyint | Show progress bar in wizard |
| `submit_button_title` | varchar(32) | Custom submit button label |
| `sort_order` | smallint | Display order |

### Trigger Types (button_position values)

Stored as comma-separated string. A process can have multiple positions.

| Value | Trigger | When it fires |
|-------|---------|---------------|
| `default` | In Record Page | Manual button on item detail page |
| `menu_more_actions` | More Actions Menu | Manual, in the "More Actions" dropdown |
| `menu_with_selected` | With Selected Menu | Manual, bulk action on selected items |
| `in_listing` | In Listing | Manual button above the items listing |
| `comments_section` | Comments Section | Manual button in comments area |
| `run_after_insert` | After Record Insert | **Automatic** - fires after a new record is created |
| `run_after_update` | After Record Update | **Automatic** - fires after a record is edited |
| `run_after_move` | After Record Move | **Automatic** - fires after a child record is moved (only for child entities) |
| `run_before_delete` | Before Record Delete | **Automatic** - fires before a record is deleted |
| `run_on_schedule` | Run on Schedule | **Cron** - run via `php cron/process.php <process_id> [item_id]` |
| `buttons_groups_<id>` | Button Group | Manual, grouped under a dropdown button |

### Process Filters

Each process can have filters (stored in `app_reports` with `reports_type='process<id>'`). The process only runs if the current item matches the filter conditions. This is how you make a process conditional (e.g., "only run when status = Active").

### Execution Flow (PHP)

Source: `/var/www/ezlead-hq/crm/plugins/ext/classes/processes/processes.php`

```
Item saved (UI or API)
  -> items.php / api.php calls:
     $processes = new processes($entity_id);
     $processes->run_after_insert($item_id);   // or run_after_update()
  -> get_buttons_list('run_after_insert') fetches matching processes
  -> check_buttons_filters() verifies report filters match
  -> run() iterates process actions and executes them
```

Both the **web UI** and the **REST API** trigger processes. The API handler at `/crm/plugins/ext/classes/api/api.php` calls the same `run_after_insert()`, `run_after_update()`, `send_insert_msg()`, and `send_edit_msg()` methods.

### Cron-Based Processes

For `run_on_schedule` processes:

```bash
# Run for all items matching the process filters:
php /var/www/ezlead-hq/crm/cron/process.php <process_id>

# Run for a specific item:
php /var/www/ezlead-hq/crm/cron/process.php <process_id> <item_id>
```

The cron handler requires either an item_id or filters to be configured (won't run on all items with no filter as a safety measure).

---

## 2. Process Actions (app_ext_processes_actions)

Each process has one or more actions executed in `sort_order`.

### Table: `app_ext_processes_actions`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Action ID |
| `process_id` | int FK | Parent process |
| `is_active` | tinyint | 0=disabled, 1=enabled |
| `type` | varchar(64) | Action type - see below |
| `description` | text | Human-readable description |
| `sort_order` | smallint | Execution order |
| `settings` | text | JSON settings (varies by type) |

### Action Types

The `type` column follows the pattern `<action>_item_entity_<entity_id>`:

| Type Pattern | What It Does |
|-------------|--------------|
| `edit_item_entity_<eid>` | Update fields on the current item |
| `edit_parent_item_entity_<eid>` | Update fields on the parent item |
| `edit_item_subentity_<eid>` | Update fields on child items |
| `edit_item_related_entity_<eid>` | Update fields on related entity items |
| `insert_item_entity_<eid>` | Create a new record in entity |
| `insert_item_subentity_<eid>` | Create a new child record |
| `insert_item_related_entity_<eid>` | Create a new related entity record |
| `delete_item_entity_<eid>` | Delete the item (and children) |
| `copy_item_entity_<eid>` | Copy the item |
| `clone_item_entity_<eid>` | Clone item with sub-items |
| `move_item_entity_<eid>` | Move item to different parent |
| `repeat_item_entity_<eid>` | Create recurring copies |
| `runphp_item_entity_<eid>` | Execute custom PHP code |
| `save_export_template_entity_<eid>` | Generate export/PDF from template |
| `link_records_by_mysql_query_<eid>` | Link records via SQL |
| `unlink_records_by_mysql_query_<eid>` | Unlink records via SQL |

### Custom PHP Actions (runphp_item_entity)

Settings JSON: `{"php_code": "...", "debug_mode": 0}`

The PHP code runs via `eval()`. Before execution, field placeholders like `[field_id]` are replaced with the item's actual values. Available variables:

- `$item_info` - full row from `app_entity_<eid>`
- `$current_item_id` - the item ID being processed (same as `$item_info['id']`)
- `$app_user` - current user array
- `$process_comments` - text from the process comment box (if `allow_comments=1`)
- All `[field_id]` placeholders are replaced with the item's field values (strings are quoted, nulls become 0)

### Our Custom `execute_php_script` Type

Our processes (IDs 1-5) use `type='execute_php_script'` with `settings` containing `{"php_code":"..."}`. This is a **non-standard type** we inserted directly into the database. It does NOT exist in Rukovoditel's PHP code. These processes work because their `button_position` is `after_insert` which triggers `run()`, and `run()` iterates actions -- but since `execute_php_script` doesn't match any case in the switch statement, **these actions silently do nothing through the standard process runner**.

Instead, these processes are triggered by our custom claude plugin code (e.g., `mechanic_automation.php`) which reads the settings and evals the PHP code directly. The standard Rukovoditel way to run PHP is `runphp_item_entity_<eid>`.

---

## 3. Process Action Fields (app_ext_processes_actions_fields)

Defines which fields an action sets and to what values.

### Table: `app_ext_processes_actions_fields`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Row ID |
| `actions_id` | int FK | Parent action in `app_ext_processes_actions` |
| `fields_id` | int FK | Which field to set (references `app_fields.id`) |
| `value` | text | Value to set. For dropdowns: choice ID. For dates: relative like `+1` (days from now) or timestamp. For text: literal value. |
| `allowed_value` | text | Restrict allowed values (for manual entry) |
| `enter_manually` | tinyint | 0=use preset value, 1=user enters value in process form, 2=hidden but user can edit |

### Value Patterns

- **Dropdown fields**: Store the choice ID (integer)
- **Date fields**: `+1` = tomorrow, `-1` = yesterday, ` ` (space) = clear the date, or a Unix timestamp
- **Text fields**: Literal text, can include `[field_XXX]` placeholders
- **User fields**: User ID, or `[current_user_id]` for the running user

---

## 4. Process Button Groups (app_ext_processes_buttons_groups)

Groups multiple process buttons into a single dropdown.

### Table: `app_ext_processes_buttons_groups`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Group ID |
| `entities_id` | int FK | Entity this group belongs to |
| `name` | varchar(64) | Dropdown button label |
| `button_color` | varchar(7) | Hex color |
| `button_icon` | varchar(64) | FontAwesome icon |
| `button_position` | varchar(64) | Where to show: `default`, `in_listing`, `menu_with_selected` |
| `sort_order` | int | Display order |

Processes with `button_position='buttons_groups_<group_id>'` appear inside this dropdown.

---

## 5. Email Rules (app_ext_email_rules)

Sends emails automatically based on record events.

Source: `/var/www/ezlead-hq/crm/plugins/ext/classes/email_rules.php`

### Table: `app_ext_email_rules`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Rule ID |
| `entities_id` | int FK | Entity to monitor |
| `action_type` | varchar(64) | Trigger type - see below |
| `send_to_users` | text | Comma-separated user IDs to email |
| `send_to_assigned_users` | text | Field IDs - email users assigned in these fields |
| `send_to_email` | text | Literal email address(es) |
| `send_to_assigned_email` | text | Field ID containing email address in the record |
| `monitor_fields_id` | int | Which field to watch for changes (0=any change) |
| `monitor_choices` | text | Comma-separated choice IDs - only fire when field changes TO one of these values |
| `date_fields_id` | int | Date field for scheduled rules |
| `number_of_days` | varchar(32) | Days offset for scheduled rules: `-1`=1 day before, `+3`=3 days after, comma-separated for multiple |
| `subject` | varchar(255) | Email subject (supports `[field_XXX]` placeholders) |
| `description` | text | Email body (supports `[field_XXX]` placeholders, HTML) |
| `send_from_name` | varchar(255) | Sender name |
| `send_from_email` | varchar(255) | Sender email |
| `is_active` | tinyint | 0=disabled, 1=enabled |
| `attach_attachments` | tinyint | If 1, attach file attachment fields |
| `attach_template` | text | PDF template to attach |
| `notes` | text | Admin notes |

### Email Rule Action Types

Grouped by trigger event:

**On Insert (new record created):**
| Type | Recipient |
|------|-----------|
| `insert_send_to_users` | Specific users |
| `insert_send_to_assigned_users` | Users in assigned-user fields |
| `insert_send_to_email` | Literal email address |
| `insert_send_to_assigned_email` | Email from a field in the record |
| `insert_send_by_visibility_rules` | Users matching visibility rules |

**On Edit (record updated):**
| Type | Recipient |
|------|-----------|
| `edit_send_to_users` | Specific users |
| `edit_send_to_assigned_users` | Users in assigned-user fields |
| `edit_send_to_email` | Literal email address |
| `edit_send_to_assigned_email` | Email from a field in the record |
| `edit_send_by_visibility_rules` | Users matching visibility rules |

**On Comment:**
| Type | Recipient |
|------|-----------|
| `comment_send_to_users` | Specific users |
| `comment_send_to_assigned_users` | Users in assigned-user fields |
| `comment_send_to_email` | Literal email address |
| `comment_send_to_assigned_email` | Email from a field in the record |
| `comment_send_by_visibility_rules` | Users matching visibility rules |

**Scheduled (by date field):**
| Type | Recipient |
|------|-----------|
| `schedule_send_to_users` | Specific users |
| `schedule_send_to_assigned_users` | Users in assigned-user fields |
| `schedule_send_to_email` | Literal email address |
| `schedule_send_to_assigned_email` | Email from a field in the record |
| `schedule_send_by_visibility_rules` | Users matching visibility rules |

### How Edit Rules Fire

```php
// In email_rules::send_edit_msg($previous_item_info):
// 1. Compare current field value to previous value
if ($current['field_' . $monitor_fields_id] == $previous['field_' . $monitor_fields_id])
    continue;  // Field didn't change - skip

// 2. If monitor_choices is set, check if new value matches
if (!in_array($current_value, explode(',', $monitor_choices)))
    continue;  // New value doesn't match target - skip

// 3. Check report-based filters (if any)
// 4. Send the email
```

### Email Template Placeholders

In `subject` and `description`:
- `[field_XXX]` - replaced with the field's display value
- `[url]` - link to the record
- `[XXX]` (bare field ID) - also works as a shorthand
- Subitems can be included with special template syntax
- Conditional blocks supported via `apply_conditions()`

### Scheduled Emails (Cron)

Cron file: `/var/www/ezlead-hq/crm/cron/email_by_date.php`

Calls `email_rules::email_by_date()` which finds records where the date field matches `NOW() + number_of_days`. Run daily via cron.

Emails are queued in `app_emails_on_schedule` before being sent by `/var/www/ezlead-hq/crm/cron/email.php`.

### Email Rule Filters

Like processes, email rules support report-based filters stored in `app_reports` with `reports_type='email_rules<id>'`.

### Email Rules Blocks (app_ext_email_rules_blocks)

Reusable content blocks for email templates. Currently empty in our instance.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Block ID |
| `name` | varchar(64) | Block name |
| `description` | text | HTML content |

---

## 6. SMS Rules (app_ext_sms_rules)

Sends SMS via configured SMS modules (we use SignalWire, module ID 2).

Source: `/var/www/ezlead-hq/crm/plugins/ext/classes/sms.php`

### Table: `app_ext_sms_rules`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Rule ID |
| `entities_id` | int FK | Entity to monitor |
| `modules_id` | int FK | SMS module to use (references `app_ext_modules`) |
| `is_active` | tinyint | 0=disabled, 1=enabled |
| `action_type` | varchar(64) | Trigger type - see below |
| `fields_id` | int | Phone field ID in the record (for `send_to_record_number`) or user phone field (for `send_to_user_number`) |
| `monitor_fields_id` | int | Field to watch for changes |
| `monitor_choices` | text | Comma-separated choice IDs to match |
| `date_fields_id` | int | Date field for scheduled rules |
| `date_type` | varchar(16) | `day` or `hour` - scheduling granularity |
| `number_of_days` | varchar(32) | Offset: `-1`=1 day/hour before, `+3`=3 after |
| `phone` | varchar(255) | Literal phone number(s) for `send_to_number`, OR `<field_id>:<phone_field_id>` for `send_to_number_in_entity` |
| `send_to_assigned_users` | text | Field IDs for user assignment (for `send_to_user_number`) |
| `description` | text | SMS body text (supports `{{field_XXX}}` placeholders) |
| `notes` | text | Admin notes |

### SMS Rule Action Types

**On Insert:**
| Type | Recipient |
|------|-----------|
| `insert_send_to_number` | Literal phone number in `phone` column |
| `insert_send_to_record_number` | Phone from `fields_id` field in the record |
| `insert_send_to_user_number` | Phone from assigned user's profile |
| `insert_send_to_number_in_entity` | Phone from a related entity record |

**On Edit:**
| Type | Recipient |
|------|-----------|
| `edit_send_to_number` | Literal phone number |
| `edit_send_to_record_number` | Phone from record field |
| `edit_send_to_user_number` | Phone from assigned user |
| `edit_send_to_number_in_entity` | Phone from related entity |

**Scheduled:**
| Type | Recipient |
|------|-----------|
| `schedule_send_to_number` | Literal phone number |
| `schedule_send_to_record_number` | Phone from record field |
| `schedule_send_to_user_number` | Phone from assigned user |
| `schedule_send_to_number_in_entity` | Phone from related entity |

### Related Entity Phone Resolution

When `action_type` ends with `_in_entity`, the `phone` column contains `<entity_field_id>:<phone_field_id>`:
- `entity_field_id` = field in the current record that references another entity (e.g., a Customer link field)
- `phone_field_id` = field in that related entity containing the phone number

Example: `phone='482:428'` means "look up field_482 in the current record (gets customer ID), then get field_428 from that customer record (their phone number)".

### Scheduled SMS (Cron)

Cron files:
- `/var/www/ezlead-hq/crm/cron/sms_by_date.php` - daily check (`sms::msg_by_date('day')`)
- `/var/www/ezlead-hq/crm/cron/sms_by_hour.php` - hourly check (`sms::msg_by_date('hour')`)

### SMS Filters

Like email rules, SMS rules support report-based filters stored in `app_reports` with `reports_type='sms_rules<id>'`.

---

## 7. Email Notification Rules (app_ext_email_notification_rules)

A separate, simpler notification system for digest-style emails. Currently **unused** in our instance.

| Column | Type | Purpose |
|--------|------|---------|
| `entities_id` | int | Entity to report on |
| `action_type` | varchar(64) | Trigger type |
| `send_to_users` | text | User IDs |
| `send_to_user_group` | text | Group IDs |
| `send_to_email` | text | Email addresses |
| `subject` / `description` | text | Email content |
| `listing_type` | varchar(16) | How to format the listing |
| `listing_html` | text | Custom HTML template |
| `listing_fields` | text | Fields to include |
| `notification_days` | varchar(255) | Days of week to send |
| `notification_time` | varchar(255) | Time of day to send |

---

## 8. How-To: Common Automation Recipes

### Recipe: Auto-Set Field Value When Another Field Changes

Use a **Process** with `button_position='run_after_update'`:

```sql
-- 1. Create the process
INSERT INTO app_ext_processes (entities_id, name, button_position, is_active, assigned_to_all, success_message_status)
VALUES (42, 'Auto-Set Priority on Stage Change', 'run_after_update', 1, 0, 0);
-- Note: assigned_to_all=0, users_groups/assigned_to empty is OK for auto-triggers

-- 2. Get the process ID
SET @process_id = LAST_INSERT_ID();

-- 3. Create an "edit item" action
INSERT INTO app_ext_processes_actions (process_id, is_active, type, description, sort_order, settings)
VALUES (@process_id, 1, 'edit_item_entity_42', 'Set priority field', 0, '');

-- 4. Get the action ID
SET @action_id = LAST_INSERT_ID();

-- 5. Define which field to set and to what value
INSERT INTO app_ext_processes_actions_fields (actions_id, fields_id, value, allowed_value, enter_manually)
VALUES (@action_id, 329, '178', '', 0);
-- This sets field_329 (Priority) to choice 178 (High)

-- 6. (Optional) Add a filter so it only runs when stage = specific value
-- Create a report filter in app_reports with reports_type='process<@process_id>'
```

**Important**: For auto-triggers (`run_after_insert`, `run_after_update`), access control (`users_groups`, `assigned_to`) doesn't matter because `run_after_insert()`/`run_after_update()` call `get_buttons_list()` which checks access. The executing user must have access. For API calls, the API user (group_id=0 = admin) bypasses access checks.

### Recipe: Send Email When Stage Changes

Use an **Email Rule** with `action_type='edit_send_to_assigned_email'`:

```sql
INSERT INTO app_ext_email_rules (
    entities_id, action_type,
    send_to_assigned_email,
    monitor_fields_id, monitor_choices,
    subject, description,
    send_from_name, send_from_email,
    is_active, attach_attachments, notes
) VALUES (
    42,                              -- entity: Mechanic Jobs
    'edit_send_to_assigned_email',   -- trigger: on edit, send to email field in record
    '356',                           -- field_356 = customer email field
    362,                             -- monitor field_362 = Stage field
    '87',                            -- choice 87 = Confirmed stage
    'Your Appointment is Confirmed - [field_354]',  -- subject with placeholder
    '<p>Hi [field_354],</p><p>Your appointment for [field_358] [field_359] [field_360] has been confirmed for [field_368].</p>',
    'Ez Mobile Mechanic',            -- from name
    'kyle@mobilemechanic.best',      -- from email
    1,                               -- active
    0,                               -- no attachments
    'Confirmation email on stage change to Confirmed'
);
```

### Recipe: Run Custom PHP on Record Insert

Use a **Process** with `runphp_item_entity_<eid>` action type:

```sql
-- 1. Create the process
INSERT INTO app_ext_processes (entities_id, name, button_position, is_active, assigned_to_all, success_message_status)
VALUES (25, 'Auto-Process New Lead', 'run_after_insert', 1, 0, 0);

SET @process_id = LAST_INSERT_ID();

-- 2. Create a "run PHP" action (use runphp_item_entity_<entity_id>)
INSERT INTO app_ext_processes_actions (process_id, is_active, type, description, sort_order, settings)
VALUES (@process_id, 1, 'runphp_item_entity_25', 'Custom PHP for new lead', 0,
    '{"php_code":"// $item_info has all fields\\n// $current_item_id has the item ID\\n$name = $item_info[\\\"field_210\\\"];\\ndb_query(\\\"UPDATE app_entity_25 SET field_218=\\'New\\' WHERE id=$current_item_id\\\");"}');
```

---

## 9. Processes vs. mechanic_automation.php (Claude Plugin Cron)

| Aspect | Rukovoditel Processes | mechanic_automation.php |
|--------|---------------------|------------------------|
| **Trigger** | Built-in: fires on insert/update/delete/move or via cron | External cron job running every N minutes |
| **Execution context** | Runs inline during the HTTP request (or cron) | Runs as standalone PHP script |
| **Conditions** | Report-based filters in `app_reports` | Custom PHP if/else logic |
| **Actions** | Set fields, create/delete/copy records, run PHP | Arbitrary PHP: API calls, SMS, email, external services |
| **Email/SMS** | Triggers email_rules and sms_rules automatically when process edits a record | Sends directly via SignalWire/SMTP |
| **Debugging** | Limited: `debug_mode` flag in PHP actions, or `preview_prcess_actions` | Full PHP error logging, custom log files |
| **Complexity** | Simple field-value changes, record operations | Complex multi-step workflows with business logic |
| **Chain reactions** | Process edits -> triggers email/SMS rules -> triggers other after_update processes | Explicit: each step coded manually |

### When to Use What

- **Use Processes** for: Simple field changes, record creation, straightforward "when X changes to Y, set Z to W" rules. Email/SMS notifications on field changes.
- **Use mechanic_automation.php** for: Complex multi-step workflows, external API calls (SignalWire, OpenAI), conditional logic that depends on multiple fields or external state, batch operations across multiple entities.
- **Use Email Rules** for: Sending templated emails when a field changes to a specific value.
- **Use SMS Rules** for: Sending templated SMS when a field changes to a specific value.

### Chain Reaction Behavior

When a **process action** updates a field on a record, it triggers `send_edit_msg()` for both email rules and SMS rules (see processes.php lines 1162-1169). This means:

1. Process changes Stage field to "Confirmed"
2. Email rule monitoring Stage for "Confirmed" fires automatically
3. SMS rule monitoring Stage for "Confirmed" fires automatically

This happens inline, within the same request. No cron needed.

However, a process update does **NOT** re-trigger `run_after_update()` processes -- only direct item saves from the UI/API do that. This prevents infinite loops.

---

## 10. Existing Processes in Our CRM

| ID | Entity | Name | Trigger | What It Does |
|----|--------|------|---------|-------------|
| 1 | 25 (Leads) | Auto-Distribute Lead | after_insert | Runs `ezlead_distribute_lead()` (custom PHP) |
| 2 | 26 (Buyers) | Add Credit | item_page_button | Manual: adds credit to buyer balance |
| 3 | 1 (Users) | Create Buyer Record | after_insert | Auto-creates Buyer record for new users in Buyers group |
| 4 | 42 (Jobs) | Hotline Intake Auto-Workflow | after_insert | SMS notification to Kyle for new hotline jobs |

**Note**: All use `execute_php_script` action type which is non-standard. To make these work properly through Rukovoditel's built-in system, they should be migrated to `runphp_item_entity_<eid>`.

## 11. Existing Email Rules

| ID | Entity | Trigger | Monitor | Target | Purpose |
|----|--------|---------|---------|--------|---------|
| 1 | 25 (Leads) | insert | - | sodjacksonville@gmail.com | Admin: new lead notification |
| 2 | 25 (Leads) | change_field | field_218 -> Distributed | sodjacksonville@gmail.com | Admin: lead distributed |
| 3 | 42 (Jobs) | edit -> customer email (356) | field_362 -> Confirmed (87) | Customer | Appointment reminder |
| 4 | 42 (Jobs) | edit -> customer email (356) | field_362 -> Follow Up (95) | Customer | How's your car running? |
| 5 | 42 (Jobs) | edit -> customer email (356) | field_362 -> Review Request (96) | Customer | Google review request |
| 6 | 42 (Jobs) | edit -> customer email (356) | field_362 -> Payment Received (76) | Customer | Payment thank you |
| 7 | 42 (Jobs) | schedule -> customer email (356) | date_field 368, -1 day | Customer | Appointment reminder (day before) |
| 8 | 42 (Jobs) | insert | - | sodjacksonville@gmail.com | Admin: new job notification |
| 9 | 42 (Jobs) | edit -> customer email (356) | field_362 -> Estimate Sent (69) | Customer | Estimate delivery email |
| 10 | 42 (Jobs) | edit -> customer email (356) | field_362 -> Confirmed (73) | Customer | Appointment confirmed email |

## 12. Existing SMS Rules

| ID | Entity | Module | Trigger | Target | Purpose |
|----|--------|--------|---------|--------|---------|
| 1 | 27 (Credits) | SignalWire | insert -> number | +19042175152 | Kyle: new credit purchase |
| 2 | 29 (Appointments) | SignalWire | insert -> number | +19042175152 | Kyle: call scheduled |
| 3 | 51 (Call Records) | SignalWire | edit -> entity number | 482:428 (Customer phone) | Customer: call receipt when status=Completed (153) |
| 4 | 29 (Appointments) | SignalWire | schedule -> entity number | 256:211, 1hr before | Customer: callback reminder |

---

## 13. MySQL Quick Reference

### Create a Process via MySQL

```sql
-- Step 1: Create process
INSERT INTO app_ext_processes (
    entities_id, name, button_position, is_active,
    assigned_to_all, success_message_status, disable_comments,
    sort_order
) VALUES (
    42, 'My Auto Process', 'run_after_update', 1,
    0, 0, 1,
    0
);
SET @pid = LAST_INSERT_ID();

-- Step 2: Create action (edit current entity)
INSERT INTO app_ext_processes_actions (
    process_id, is_active, type, description, sort_order, settings
) VALUES (
    @pid, 1, 'edit_item_entity_42', 'Set field value', 0, ''
);
SET @aid = LAST_INSERT_ID();

-- Step 3: Define field changes
INSERT INTO app_ext_processes_actions_fields (
    actions_id, fields_id, value, allowed_value, enter_manually
) VALUES (
    @aid, 362, '73', '', 0  -- Set field_362 (Stage) to choice 73 (Confirmed)
);

-- Step 4 (Optional): Add filter - only run when field_362 = 69 (Estimate Sent)
-- This requires creating an app_reports entry and app_reports_filters entry
-- reports_type must be 'process<process_id>'
```

### Create an Email Rule via MySQL

```sql
INSERT INTO app_ext_email_rules (
    entities_id, action_type, send_to_email,
    monitor_fields_id, monitor_choices,
    subject, description,
    send_from_name, send_from_email,
    is_active, attach_attachments, notes
) VALUES (
    42, 'edit_send_to_email', 'kyle@mobilemechanic.best',
    362, '73',
    'Stage Changed: [field_354]',
    'Job [field_354] stage changed to Confirmed.<br>View: [url]',
    'CRM Bot', 'noreply@ezlead4u.com',
    1, 0, 'Notify Kyle when job confirmed'
);
```

### Create an SMS Rule via MySQL

```sql
INSERT INTO app_ext_sms_rules (
    entities_id, modules_id, is_active, action_type,
    fields_id, monitor_fields_id, monitor_choices,
    phone, description, notes
) VALUES (
    42, 2, 1, 'edit_send_to_number',
    0, 362, '73',
    '+19042175152',
    'Job confirmed: {{field_354}} - {{field_358}} {{field_359}} {{field_360}}',
    'SMS to Kyle when job confirmed'
);
```

---

## 14. Cron Jobs Required

| Cron File | Purpose | Recommended Schedule |
|-----------|---------|---------------------|
| `cron/email_by_date.php` | Scheduled email rules (date-based) | Daily |
| `cron/email.php` | Send queued emails from `app_emails_on_schedule` | Every 5 min |
| `cron/sms_by_date.php` | Scheduled SMS rules (daily) | Daily |
| `cron/sms_by_hour.php` | Scheduled SMS rules (hourly) | Hourly |
| `cron/process.php <id>` | Run scheduled processes | As needed |
| `cron/email_notification.php` | Digest notification rules | As configured |

---

## 15. Gotchas

1. **Process access control matters even for auto-triggers.** The `get_buttons_list()` method checks `users_groups`, `assigned_to`, and `assigned_to_all`. For API-triggered processes, the API user (typically group_id=0 = admin) bypasses this. But if you call the process from cron, the fake user has `group_id=0` which also bypasses.

2. **Processes don't chain-trigger other processes.** A process updating a field does trigger email/SMS rules, but does NOT trigger other `run_after_update` processes. Only direct saves from UI/API do that.

3. **Email rule `monitor_choices` stores choice IDs, not labels.** Use the integer from `app_fields_choices.id`, not the display text.

4. **SMS placeholder syntax differs from email.** Email rules use `[field_XXX]` or `[XXX]`. SMS rules use `{{field_XXX}}`.

5. **The `change_field` action_type in email rules is actually treated the same as `edit_send_to_email`.** The PHP code groups all `edit_*` types together for the edit trigger.

6. **Scheduled email/SMS rules require cron jobs running.** If `email_by_date.php` isn't in crontab, scheduled emails won't send.

7. **Our `execute_php_script` actions don't work through the standard process runner.** They need to be `runphp_item_entity_<eid>` to work properly. The existing ones only function because our custom plugin code handles them separately.

8. **SMS `phone` column overloading.** For `send_to_number` types, it's a literal phone number. For `send_to_number_in_entity` types, it's `<entity_field_id>:<phone_field_id>` notation.
