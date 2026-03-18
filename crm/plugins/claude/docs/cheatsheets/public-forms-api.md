# Rukovoditel v3.6.4 — Public Forms, REST API & Webhooks Cheat Sheet

Last updated: 2026-03-17

---

## Table of Contents

1. [REST API](#rest-api)
2. [Public Forms](#public-forms)
3. [Plugin Hooks (Public Form Actions)](#plugin-hooks)
4. [Webhooks / Process Automation](#webhooks--process-automation)

---

## REST API

### Endpoint & Authentication

```
URL:      https://ezlead4u.com/crm/api/rest.php
Method:   POST (form-encoded or JSON body)
```

Every request requires three auth fields:

| Field      | Value |
|------------|-------|
| `key`      | `dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY` (global API key, set in CRM config `CFG_API_KEY`) |
| `username` | `claude` |
| `password` | `badass` |

The global `key` is checked first (in `rest.php`). Then the `api` class authenticates username/password against `app_entity_1` (Users entity). Admin users (`group_id=0`) bypass all entity access checks.

**IP restriction**: If `CFG_API_ALLOWED_IP` is set, only listed IPs can connect. Currently unrestricted on our install.

**JSON mode**: If POST body is empty, the API reads `php://input` and JSON-decodes it into `$_REQUEST`. So you can send either form-encoded or JSON.

### Response Format

All responses are JSON:

```json
// Success
{"status": "success", "data": { ... }}

// Error (HTTP 500)
{"status": "error", "error_code": "...", "error_message": "..."}
```

Pagination responses add `page`, `number_of_rows`, `number_of_pages` to the top level.

### Available Actions

| Action | Access | Description |
|--------|--------|-------------|
| `login` | any | Validate credentials, returns user info |
| `select` | view | Query records from any entity |
| `insert` | create | Create one or more records |
| `update` | update | Update records by field match |
| `delete` | delete | Delete records by field match |
| `insert_comment` | view | Add a comment to an existing record (can also update fields) |
| `get_entities` | admin | List all entities |
| `get_entity_fields` | admin | List fields for an entity |
| `get_field_choices` | admin | List dropdown choices for a field |
| `get_global_lists` | admin | List global lists |
| `get_global_list_choices` | admin | List choices in a global list |
| `get_export_template` | admin | Get export template for an entity |
| `get_process_buttons` | any | Get available process buttons for a record |
| `run_process` | any | Execute a process button |
| `get_users_menu` | any | Get menu structure for a user |
| `get_users_filters_panels` | any | Get filter panels for a user |
| `change_user_password` | any | Change a user's password |
| `download_attachment` | any | Download an attachment file |
| `delete_attachment` | any | Delete an attachment file |

---

### action=select (Query Records)

**Parameters:**

| Param | Required | Description |
|-------|----------|-------------|
| `entity_id` | yes | Entity to query |
| `filters[field_ID]` | no | Filter by field value (see filter syntax below) |
| `filters[id]` | no | Filter by record ID(s), comma-separated |
| `filters[parent_item_id]` | no | Filter by parent item ID |
| `reports_id` | no | Use a saved report's filters |
| `parent_item_id` | no | Filter child entities by parent |
| `select_fields` | no | Comma-separated field IDs to return (default: all visible) |
| `limit` | no | Max records to return |
| `rows_per_page` | no | Enable pagination (adds page/total info to response) |
| `related_entity_id` + `related_item_id` | no | Filter by related records |

**Filter syntax:**

Simple equality:
```
filters[field_362]=82          # field_362 equals 82
```

Advanced filter with condition:
```
filters[field_362][value]=82,83
filters[field_362][condition]=include    # include, exclude, search, search_match, empty_value, not_empty_value
```

Date filters (by relative days):
```
filters[field_368]=7           # last 7 days (filter_by_days)
filters[date_added]=30         # records added in last 30 days
```

**Example: Query leads by status**

```bash
curl -X POST https://ezlead4u.com/crm/api/rest.php \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "username=claude" \
  -d "password=badass" \
  -d "action=select" \
  -d "entity_id=25" \
  -d "filters[field_218]=New" \
  -d "limit=10"
```

**Example: Query jobs in "Scheduled" stage (choice 85)**

```bash
curl -X POST https://ezlead4u.com/crm/api/rest.php \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "username=claude" \
  -d "password=badass" \
  -d "action=select" \
  -d "entity_id=42" \
  -d "filters[field_362]=85"
```

**Example: Get a specific record by ID**

```bash
curl -X POST https://ezlead4u.com/crm/api/rest.php \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "username=claude" \
  -d "password=badass" \
  -d "action=select" \
  -d "entity_id=42" \
  -d "filters[id]=5"
```

**Response data structure:**

Each record in `data` array contains:
- `id`, `parent_item_id`, `date_added`, `date_updated`, `created_by`
- Each field as `"FIELD_ID": "rendered_value"` (human-readable)
- Dropdown/choice fields also get `"FIELD_ID_db_value": "raw_choice_id"` appended

---

### action=insert (Create Records)

**Parameters:**

| Param | Required | Description |
|-------|----------|-------------|
| `entity_id` | yes | Entity to insert into |
| `items[field_XXX]` | yes | Field values (use `field_` prefix) |
| `items[parent_item_id]` | no | Parent record ID (required for child entities) |
| `items[created_by]` | no | Override creator user ID |

For **batch inserts**, use `items[0][field_XXX]`, `items[1][field_XXX]`, etc.

**Special fields for Users entity (entity 1):**
- `items[group_id]`, `items[firstname]`, `items[lastname]`, `items[email]`, `items[username]`, `items[password]`

**Date fields**: Pass formatted date strings (the API calls `get_date_timestamp()` to convert).

**Dropdown fields**: Pass the choice ID (integer), not the display text.

**File/attachment fields**: Pass a URL string (API will curl-download it) OR an array with `name` and base64 `content`.

**Unique field check**: If a field is marked unique and a duplicate exists, the insert is skipped (not an error). The response includes `unique_fields_warning`.

**After insert, the API automatically:**
1. Updates formula/auto-calculated fields
2. Sends SMS notifications (if configured)
3. Subscribes to mailing lists
4. Fires email rules
5. Logs the change in track_changes
6. **Runs process automation (after_insert triggers)**

**Example: Create a mechanic job**

```bash
curl -X POST https://ezlead4u.com/crm/api/rest.php \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "username=claude" \
  -d "password=badass" \
  -d "action=insert" \
  -d "entity_id=42" \
  -d "items[field_354]=John Doe" \
  -d "items[field_355]=904-555-1234" \
  -d "items[field_356]=john@example.com" \
  -d "items[field_358]=2018" \
  -d "items[field_359]=Honda" \
  -d "items[field_360]=Civic" \
  -d "items[field_361]=Engine misfiring at idle" \
  -d "items[field_362]=82" \
  -d "items[field_475]=2"
```

Response: `{"status":"success","data":{"id":"123"}}`

**Example: Create a lead (JSON body)**

```bash
curl -X POST https://ezlead4u.com/crm/api/rest.php \
  -H "Content-Type: application/json" \
  -d '{
    "key": "dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY",
    "username": "claude",
    "password": "badass",
    "action": "insert",
    "entity_id": 25,
    "items": {
      "field_210": "Jane Smith",
      "field_211": "904-555-5678",
      "field_212": "jane@example.com",
      "field_215": "mobilemechanic.best",
      "field_218": "New",
      "field_474": 2
    }
  }'
```

---

### action=update (Update Records)

**Parameters:**

| Param | Required | Description |
|-------|----------|-------------|
| `entity_id` | yes | Entity to update |
| `update_by_field[FIELD]` | yes | Match field (e.g., `update_by_field[id]=5`) |
| `data[field_XXX]` | yes | New field values |

The `update_by_field` value can be a single value or an array (to update multiple records).

**After update, the API automatically:**
1. Updates formula fields
2. Sends SMS edit notifications
3. Updates mailing subscriptions
4. Fires email rules (edit triggers)
5. Logs changes in track_changes
6. **Runs process automation (after_update triggers)**

**Example: Mark job as Paid**

```bash
curl -X POST https://ezlead4u.com/crm/api/rest.php \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "username=claude" \
  -d "password=badass" \
  -d "action=update" \
  -d "entity_id=42" \
  -d "update_by_field[id]=5" \
  -d "data[field_371]=93"
```

**Example: Advance job stage to Scheduled + set appointment**

```bash
curl -X POST https://ezlead4u.com/crm/api/rest.php \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "username=claude" \
  -d "password=badass" \
  -d "action=update" \
  -d "entity_id=42" \
  -d "update_by_field[id]=5" \
  -d "data[field_362]=85" \
  -d "data[field_368]=03/20/2026 10:00"
```

**Example: Bulk update by field value (all jobs with stage=82 -> stage=83)**

```bash
curl -X POST https://ezlead4u.com/crm/api/rest.php \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "username=claude" \
  -d "password=badass" \
  -d "action=update" \
  -d "entity_id=42" \
  -d "update_by_field[field_362]=82" \
  -d "data[field_362]=83"
```

---

### action=delete (Delete Records)

**Parameters:**

| Param | Required | Description |
|-------|----------|-------------|
| `entity_id` | yes | Entity to delete from |
| `delete_by_field[FIELD]` | yes | Match field (e.g., `delete_by_field[id]=5`) |

Deletes cascade to child entity records.

**Example: Delete a job by ID**

```bash
curl -X POST https://ezlead4u.com/crm/api/rest.php \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "username=claude" \
  -d "password=badass" \
  -d "action=delete" \
  -d "entity_id=42" \
  -d "delete_by_field[id]=5"
```

---

### action=insert_comment (Add Comment to Record)

**Parameters:**

| Param | Required | Description |
|-------|----------|-------------|
| `entity_id` | yes | Entity the record belongs to |
| `item_id` | yes | Record ID to comment on |
| `comment_description` | no | Comment text (HTML allowed) |
| `comment_attachments` | no | Attachment URL(s) |
| `comment_fields[field_XXX]` | no | Update fields as part of the comment (logged in comment history) |

Comments that update fields are tracked in `app_comments_history`. This is how Rukovoditel's "update with comment" feature works.

---

### Admin-Only Actions

These require `group_id=0` (admin user):

**get_entities**: Returns all entities with their config.
```
action=get_entities
```

**get_entity_fields**: Returns field definitions for an entity.
```
action=get_entity_fields&entity_id=42
```

**get_field_choices**: Returns dropdown choices for a field.
```
action=get_field_choices&entity_id=42&field_id=362
```

---

### PHP Usage Pattern

```php
$url = 'https://ezlead4u.com/crm/api/rest.php';
$data = [
    'key'       => 'dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY',
    'username'  => 'claude',
    'password'  => 'badass',
    'action'    => 'select',
    'entity_id' => 42,
    'filters'   => ['field_362' => 82],
    'limit'     => 10,
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($response['status'] === 'success') {
    foreach ($response['data'] as $record) {
        echo $record['id'] . ': ' . $record[354] . "\n";  // field IDs as keys
    }
}
```

---

## Public Forms

### Overview

Public forms let anonymous users submit data into any CRM entity without logging in. They are standalone pages served at:

```
https://ezlead4u.com/crm/index.php?module=ext/public/form&id=FORM_ID
```

They can also be embedded in iframes on external sites.

### Database Table: `app_ext_public_forms`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int PK | Form ID (used in URL) |
| `entities_id` | int | Target entity for record creation |
| `parent_item_id` | int | Fixed parent ID for child entities (0 = none or user picks) |
| `hide_parent_item` | tinyint | 1 = hide parent selector, use fixed `parent_item_id` |
| `is_active` | tinyint | 0 = shows inactive message instead of form |
| `inactive_message` | text | Message shown when form is inactive |
| `name` | varchar(64) | Internal form name |
| `page_title` | varchar(255) | Public-facing page title |
| `button_save_title` | varchar(64) | Submit button text |
| `description` | text | Description shown above form fields |
| `successful_sending_message` | text | Success message (supports `{field_XXX}` patterns) |
| `after_submit_action` | varchar(32) | `message` (default), `display_success_text`, or `goto` |
| `after_submit_redirect` | varchar(255) | Redirect URL (when `after_submit_action=goto`) |
| `hidden_fields` | text | Comma-separated field IDs to hide from the form |
| `user_agreement` | text | Checkbox text the user must agree to before submitting |
| `customer_name` | varchar(64) | Field ID(s) for customer name (comma-separated, for email) |
| `customer_email` | int | Field ID containing customer email address |
| `customer_message_title` | varchar(255) | Subject line for customer confirmation email |
| `customer_message` | text | Body of customer confirmation email (supports `{items}` token) |
| `admin_name` | varchar(64) | Admin "From" name for notifications |
| `admin_email` | varchar(64) | Admin email for notifications |
| `admin_notification` | tinyint | 1 = send admin notification on submission |
| `check_enquiry` | tinyint | 1 = enable enquiry status check page |
| `disable_submit_form` | tinyint | 1 = disable form, only show check enquiry page |
| `check_page_title` | varchar(255) | Title for the check enquiry page |
| `check_page_fields` | text | Field IDs to show on check result |
| `check_page_comments` | tinyint | 1 = show comments on check page |
| `notify_field_change` | int | Field ID that triggers client notification on change |
| `notify_message_title` | varchar(255) | Subject for change notification email |
| `notify_message_body` | text | Body for change notification email |
| `form_css` | text | Custom CSS injected into form page |
| `form_js` | text | Custom JavaScript injected at bottom of form page |

### Current Forms on Our Install

| ID | Name | Entity | Purpose |
|----|------|--------|---------|
| 1 | SOD Lead Form | 25 (Leads) | Captures SOD installation leads |
| 2 | Mechanic Lead Form | 25 (Leads) | Captures mobile mechanic leads |
| 3 | Hotline Intake Form | 42 (Jobs) | Phone diagnosis intake, creates jobs directly |

### How Public Forms Work (Processing Flow)

**File**: `/var/www/ezlead-hq/crm/plugins/ext/modules/public/actions/form.php`

1. Form loaded via `module=ext/public/form&id=FORM_ID`
2. `app_ext_public_forms` row fetched by ID
3. If `is_active=0`, redirect to inactive page
4. **Plugin hooks execute**: loops through `AVAILABLE_PLUGINS`, loads `plugins/PLUGIN/public_form_action.php` if it exists
5. Form rendered with all entity fields EXCEPT those listed in `hidden_fields`
6. Fields with `is_default=1` choices get auto-selected
7. reCAPTCHA rendered if enabled globally
8. User agreement checkbox rendered if configured

**On submit (`action=save`):**

1. Form token verified (CSRF protection)
2. reCAPTCHA verified if enabled
3. All entity fields processed (hidden fields get default values)
4. Record inserted into `app_entity_XX` table
5. Choice values inserted for multi-value fields
6. Formula/auto fields recalculated
7. Track changes logged
8. Mailing subscriptions processed
9. **Process automation runs** (`processes->run_after_insert`)
10. Item notification email sent
11. Customer confirmation email sent (if `customer_email` field configured)
12. Admin notification email sent (if `admin_notification=1`)
13. Success message shown or redirect executed

### Pre-Populating Fields via URL

You can pre-fill form fields using GET parameters:

```
https://ezlead4u.com/crm/index.php?module=ext/public/form&id=3&fields[354]=John+Doe&fields[355]=904-555-1234
```

The form view checks `$_GET['fields'][$v['id']]` and populates matching fields.

### Hidden Fields & Default Values

Fields listed in `hidden_fields` are excluded from the form HTML. When the form is submitted, those fields get their `is_default=1` choice value automatically (from `app_fields_choices`).

For fields that need non-default hidden values, use either:
- **Plugin hook** (`public_form_action.php`) to inject `$_POST['fields'][XXX]` server-side
- **`form_js`** column to set values client-side via JavaScript

### Custom CSS and JS

The `form_css` column is served as a separate CSS file via `action=get_css` and linked in the form page. The `form_js` column is injected as inline `<script>` at the bottom of the form.

**Form 3's JS** (Hotline Intake):
```javascript
setTimeout(function(){
  var s = document.querySelector("select[name=\"fields[362]\"]");
  if(s) s.value = "217";
  var b = document.querySelector("input[name=\"fields[475]\"]");
  if(b) b.value = "2";
  else {
    var h = document.createElement("input");
    h.type = "hidden"; h.name = "fields[475]"; h.value = "2";
    document.querySelector("form").appendChild(h);
  }
}, 500);
```

### Embedding in External Sites

```html
<iframe src="https://ezlead4u.com/crm/index.php?module=ext/public/form&id=2"
        width="100%" height="600" frameborder="0"></iframe>
```

The form detects iframe context via `isIframe()` and applies form-specific CSS.

### Enquiry Check Page

When `check_enquiry=1`, submitters can check the status of their record. The check page shows fields listed in `check_page_fields` and optionally comments. If `disable_submit_form=1`, users see ONLY the check page (no form).

---

## Plugin Hooks

### public_form_action.php

**File**: `/var/www/ezlead-hq/crm/plugins/claude/public_form_action.php`

This hook runs BEFORE form rendering and BEFORE form processing. It receives:
- `$public_form` — the full row from `app_ext_public_forms`
- `$app_module_action` — empty string for render, `'save'` for submission

**Our hook** sets default values for the Hotline Intake Form (entity 42):

```php
if (isset($public_form) && $public_form['entities_id'] == 42) {
    if ($app_module_action === 'save' && isset($_POST['fields'])) {
        // Stage = 217 (Incoming) if not set
        if (!isset($_POST['fields'][362]) || empty($_POST['fields'][362])) {
            $_POST['fields'][362] = 217;
        }
        // Business = 2 (Ez Mobile Mechanic) if not set
        if (!isset($_POST['fields'][475]) || empty($_POST['fields'][475])) {
            $_POST['fields'][475] = 2;
        }
    }
}
```

This is the server-side complement to the client-side JS in `form_js`. Both ensure Stage and Business get set even if the fields are hidden.

### public_form_view.php

A second hook point exists in the form view (`views/form.php`). If `plugins/PLUGIN/public_form_view.php` exists, it is included before the form HTML renders. We do not currently use this hook.

### How to Add a New Hook

1. Create the file in your plugin directory: `plugins/PLUGINNAME/public_form_action.php`
2. The plugin must be listed in the `AVAILABLE_PLUGINS` constant (set in CRM config)
3. The file receives all variables from the form action scope — `$public_form`, `$app_module_action`, `$_POST`, etc.

---

## Webhooks / Process Automation

Rukovoditel does not have a traditional webhook system (no `app_ext_webhooks` table). Instead, it uses **Processes** — configurable automation rules that fire on record events.

### How Processes Work

The `processes` class (`run_after_insert`, `run_after_update`) fires after API inserts/updates AND after public form submissions. These are configured in the CRM admin under each entity's "Processes" tab.

Process types include:
- Send email
- Send SMS
- Update fields
- Create related records
- HTTP request (this is the closest thing to a webhook)
- Run PHP code

### Triggering Automation from API

Both `action=insert` and `action=update` call `$processes->run_after_insert($item_id)` and `$processes->run_after_update($item_id)` respectively. This means:

- Creating a job via API with `field_362=82` (New Lead) will trigger the same automation as creating it through the UI
- Updating a job's stage via API triggers the same email rules and process automation as manual updates
- The `mechanic_automation.php` cron is separate — it runs on its own schedule and queries for records in specific stages

### Our Automation Layer

We use `mechanic_automation.php` (cron every 5 min) instead of Rukovoditel's built-in Processes for most workflow automation. This gives us more control over timing, error handling, and complex multi-step logic.

See `/var/www/ezlead-hq/crm/plugins/claude/MECHANIC-WORKFLOW-GUIDE.md` for the full 11-stage workflow.

---

## Common Gotchas

1. **Integer fields need `0` not `''`** — MySQL strict mode rejects empty strings for int columns. Always pass `0` for empty dropdown/numeric fields.

2. **Date fields are timestamps** — The API converts formatted dates via `get_date_timestamp()`, but when querying, raw values are Unix timestamps. Format: `MM/DD/YYYY HH:MM` for input.

3. **`forms_tabs_id=0` = invisible** — Fields with no form tab assigned are invisible in API `select` responses because the query JOINs on `app_forms_tabs`. Fix: assign a valid `forms_tabs_id`.

4. **Dropdown fields return rendered text** — API `select` returns the display label, not the choice ID. Use the `_db_value` suffix key for the raw ID (e.g., `"362": "New Lead"` vs `"362_db_value": "82"`).

5. **Process automation fires on API writes** — Both insert and update via API trigger the same email rules, SMS rules, and process automations as the UI. This can cause duplicate notifications if your code also sends notifications.

6. **Public form submissions have `created_by=0`** — Records created via public forms are not attributed to any user. Filter for these with `WHERE created_by=0`.

7. **Unique field enforcement on insert** — If a unique field has a duplicate, the insert is silently skipped (no error). Check the `unique_fields_warning` in the response.

8. **Attachment uploads via API** — Pass a URL string and the API will curl-download it. Or pass `[{"name":"file.pdf","content":"BASE64..."}]` for inline content.

---

## Quick Reference: Entity IDs

| ID | Entity | Heading Field |
|----|--------|---------------|
| 1 | Users | field_7 + field_8 (name) |
| 21 | Projects | field_158 |
| 25 | Leads | field_210 |
| 29 | Appointments | field_255 |
| 30 | Sessions | field_290 |
| 35 | Insights | field_426 (child of 30) |
| 36 | Actions | field_328 |
| 42 | Jobs | field_354 |
| 47 | Customers | (see field map) |
| 48 | Vehicles | (see field map) |
| 49 | Diagnostics | (child of 42) |
| 50 | Businesses | field_468 |
| 53 | Estimates | field_515 |
| 54 | Credit Accounts | field_533 |
| 55 | Credit Transactions | (child of 54) |

---

## Key Source Files

| File | Purpose |
|------|---------|
| `/var/www/ezlead-hq/crm/api/rest.php` | API entry point — checks API key, loads core, delegates to `api` class |
| `/var/www/ezlead-hq/crm/plugins/ext/classes/api/api.php` | API class — all CRUD actions, auth, filtering |
| `/var/www/ezlead-hq/crm/plugins/ext/modules/public/actions/form.php` | Public form controller — load, save, file upload |
| `/var/www/ezlead-hq/crm/plugins/ext/modules/public/views/form.php` | Public form view — renders fields, tabs, JS |
| `/var/www/ezlead-hq/crm/plugins/claude/public_form_action.php` | Our plugin hook — injects defaults for entity 42 forms |
| `/var/www/ezlead-hq/crm/plugins/claude/application_top.php` | Plugin bootstrap — business filter, config loading |
