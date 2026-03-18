# Rukovoditel v3.6.4 - Fields, Choices & Entity Config Cheat Sheet

Database: `rukovoditel` on localhost. Connect with: `mysql --defaults-file=/home/kylewee/.my.cnf rukovoditel`

---

## Table: `app_entities`

Defines top-level and sub-entities (CRM modules).

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Entity ID. Referenced everywhere as `entities_id`. |
| `parent_id` | int(11) | `0` = top-level entity. Non-zero = sub-entity (child of that entity). Example: Diagnostics (49) has parent_id=42 (Mechanic Jobs). |
| `group_id` | int(11) | Access group restriction. `0` = visible to all groups. `1` = group 1 only. `2` = group 2 only. Admin (group_id=0 on user) bypasses all. |
| `name` | varchar(64) | Display name in menus and UI. |
| `notes` | text | Description/notes for the entity. |
| `display_in_menu` | tinyint(1) | `1` = shows in left sidebar menu. `0` = hidden (sub-entities typically hidden). |
| `sort_order` | int(11) | Menu display order. Lower = higher in menu. |

### Parent-Child Relationships (Current)

```
Projects (21)
  ├── Tasks (22)
  ├── Tickets (23)
  └── Discussions (24)
Buyers (26)
  └── Transactions (27)
Sessions (30)
  └── Insights (35)
Websites (37)
  ├── Issues (43)
  ├── Analytics (44)
  ├── Site Notes (45)
  └── Uptime Logs (46)
Mechanic Jobs (42)
  └── Diagnostics (49)
Credit Accounts (54)
  └── Credit Transactions (55)
```

Child entities show as tabs on the parent item's detail page. Items in child entities have a `fieldtype_parent_item_id` field linking back to the parent.

### Common Operations

**Create a new top-level entity:**
```sql
INSERT INTO app_entities (parent_id, group_id, name, notes, display_in_menu, sort_order)
VALUES (0, 0, 'My Entity', '', 1, 10);
```

**Create a sub-entity (child of entity 42):**
```sql
INSERT INTO app_entities (parent_id, group_id, name, notes, display_in_menu, sort_order)
VALUES (42, 0, 'My Sub-Entity', '', 0, 1);
```
After creating a sub-entity, you must also create at least one `app_forms_tabs` row and a `fieldtype_parent_item_id` field for it.

**Hide/show entity from menu:**
```sql
UPDATE app_entities SET display_in_menu = 0 WHERE id = 42;
```

---

## Table: `app_forms_tabs`

Defines the tab sections on an entity's add/edit form. Fields are grouped into tabs.

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Tab ID. Referenced by `app_fields.forms_tabs_id`. |
| `entities_id` | int(11) | Which entity this tab belongs to. |
| `parent_id` | int(11) | `0` = top-level tab. Non-zero = sub-tab (rarely used). |
| `is_folder` | tinyint(1) | `1` = collapsible folder/section. `0` = normal tab. Currently no folders in use. |
| `name` | varchar(64) | Tab display name (e.g., "Info", "Customer", "Billing"). |
| `icon` | varchar(64) | FontAwesome icon class (e.g., `fa-info-circle`). |
| `icon_color` | varchar(7) | Hex color for icon (e.g., `#ff0000`). |
| `description` | text | Optional description shown below tab name. |
| `sort_order` | int(11) | Tab display order. Lower = first. |

### Why This Table Matters (API Visibility)

**CRITICAL**: The REST API query JOINs `app_fields` to `app_forms_tabs`:

```sql
SELECT f.*, t.name as tab_name
FROM app_fields f, app_forms_tabs t
WHERE f.entities_id = '...'
  AND f.forms_tabs_id = t.id
ORDER BY t.sort_order, t.name, f.sort_order, f.name
```

This is an implicit INNER JOIN. If `forms_tabs_id = 0` and no tab row with `id = 0` exists, the field is **completely invisible** to the API. It won't appear in `select` responses or be settable via `insert`/`update`.

Fields with `forms_tabs_id = 0` are typically system fields (`fieldtype_action`, `fieldtype_id`, `fieldtype_date_added`, `fieldtype_parent_item_id`) that the UI renders separately. But if you accidentally set a user field's `forms_tabs_id = 0`, it vanishes from the API.

### Example: Entity 42 (Mechanic Jobs) Tabs

| Tab ID | Name | sort_order |
|--------|------|------------|
| 59 | Customer | 1 |
| 60 | Job Details | 2 |
| 61 | Parts | 3 |
| 62 | Billing | 4 |
| 63 | Notes & Files | 5 |

### Common Operations

**Create a new tab for an entity:**
```sql
INSERT INTO app_forms_tabs (entities_id, parent_id, is_folder, name, icon, icon_color, description, sort_order)
VALUES (42, 0, 0, 'Scheduling', '', '', '', 6);
```

**Move a field to a different tab:**
```sql
UPDATE app_fields SET forms_tabs_id = 60 WHERE id = 354;
```

**Fix a field that's invisible in the API** (forms_tabs_id pointing to nothing):
```sql
-- First find a valid tab for the entity
SELECT id, name FROM app_forms_tabs WHERE entities_id = 42;
-- Then fix the field
UPDATE app_fields SET forms_tabs_id = 59 WHERE id = 439;
```

---

## Table: `app_fields`

Defines every field on every entity. The field's data column in `app_entity_NN` is `field_<id>`.

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Field ID. Data stored in `app_entity_NN.field_<id>`. |
| `entities_id` | int(11) | Which entity this field belongs to. |
| `forms_tabs_id` | int(11) | Which tab this field appears on. **Must point to a valid `app_forms_tabs.id`** or field is invisible in API. `0` = system fields only. |
| `comments_forms_tabs_id` | int(11) | Tab ID for when this field appears in comments form. `0` = not in comments. |
| `forms_rows_position` | varchar(255) | Layout positioning within the tab (usually empty). |
| `type` | varchar(64) | Field type. See Field Types section below. |
| `name` | varchar(255) | Display label (e.g., "Customer Name", "Phone"). |
| `short_name` | varchar(64) | Abbreviated name for compact views. |
| `is_heading` | tinyint(1) | `1` = this field is the entity's title/heading. Shown in listings, link text, breadcrumbs. Multiple fields can be `is_heading=1` (they concatenate). |
| `tooltip` | text | Help tooltip text shown on the form. |
| `tooltip_display_as` | varchar(16) | How to show tooltip: empty = none, `message_info` = info box. |
| `tooltip_in_item_page` | tinyint(1) | `1` = show tooltip on item detail page too. |
| `tooltip_item_page` | text | Separate tooltip text for item detail page. |
| `notes` | text | Internal notes (not shown to users). |
| `is_required` | tinyint(1) | `1` = field is required on form submit. `0` = optional. |
| `required_message` | text | Custom validation error message when required field is empty. |
| `configuration` | text | JSON config. Contents vary by field type. See below. |
| `sort_order` | int(11) | Display order within the tab. Lower = higher. |
| `listing_status` | tinyint(4) | `1` = visible in entity listing/table view. `0` = hidden from listings. |
| `listing_sort_order` | int(11) | Column order in listing view. |
| `comments_status` | tinyint(1) | `1` = field appears in comments form. `0` = not in comments. |
| `comments_sort_order` | int(11) | Order in comments form. |

### Field Types Reference

#### Data Entry Fields

| Type | Stores | Configuration | Notes |
|------|--------|---------------|-------|
| `fieldtype_input` | varchar | `{}` | Single-line text input. Most common field type (80 in use). |
| `fieldtype_textarea` | text | `{}` | Multi-line plain text. |
| `fieldtype_textarea_wysiwyg` | text | `{"allow_search":"1"}` | Rich text (HTML) editor. |
| `fieldtype_input_numeric` | decimal | `{"prefix":"$","width":"input-small","number_format":"2/./*"}` | Numeric input. `number_format` = "decimals/decimal_sep/thousands_sep". |
| `fieldtype_input_numeric_comments` | decimal | Same as numeric | Numeric field that also appears in comments. |
| `fieldtype_input_date` | int(11) | `{"date_format":"yyyy-mm-dd",...}` | Date only. **Stored as Unix timestamp.** |
| `fieldtype_input_datetime` | int(11) | `{"date_format":"","date_format_in_calendar":"yyyy-mm-dd hh:ii",...}` | Date+time. **Stored as Unix timestamp.** |
| `fieldtype_boolean_checkbox` | tinyint | `{}` | Single yes/no checkbox. Stores `1` or `0`. |
| `fieldtype_image` | varchar | `{}` | Image upload field. |
| `fieldtype_attachments` | text | `{}` | File attachments. |
| `fieldtype_tags` | text | `{}` | Tag/label input. |

#### Dropdown/Choice Fields

| Type | Stores | Configuration | Notes |
|------|--------|---------------|-------|
| `fieldtype_dropdown` | int | `{"use_global_list":"","width":"input-medium"}` | Single-select dropdown. **Stores the choice ID** (integer), not the display text. Choices in `app_fields_choices`. |
| `fieldtype_dropdown_multilevel` | varchar | `{"level_settings":"High, Medium, Low",...}` | Hierarchical dropdown. Choices also in `app_fields_choices`. `level_settings` defines the levels as CSV. |
| `fieldtype_checkboxes` | varchar | `{"display_as":"list-column-1","use_global_list":""}` | Multi-select checkboxes. Stores comma-separated choice IDs. |

Some older dropdowns store choices inline in `configuration.choices[]` as JSON instead of in `app_fields_choices`. Both patterns exist. The inline format:
```json
{"use_global_list":"0","choices":[{"id":"1","value":"New Lead"},{"id":"2","value":"Estimate Sent"}]}
```

#### Relationship Fields

| Type | Stores | Configuration | Notes |
|------|--------|---------------|-------|
| `fieldtype_entity` | int | `{"entity_id":"50","display_as":"dropdown","display_as_link":"1"}` | Links to another entity. Dropdown of items from `entity_id`. Stores the linked item's ID. |
| `fieldtype_entity_ajax` | int | `{"entity_id":"47"}` | Same as entity but with AJAX search (for large lists). |
| `fieldtype_entity_multilevel` | varchar | `{"entity_id":"..."}` | Multi-level entity reference. |
| `fieldtype_parent_item_id` | int | N/A | Auto-set field linking child entity items to parent. One per sub-entity. |
| `fieldtype_users` | int | `{}` | Links to a user. |
| `fieldtype_grouped_users` | int | `{}` | User picker filtered by group. |

#### System/Auto Fields

| Type | Stores | Description |
|------|--------|-------------|
| `fieldtype_id` | auto | Auto-increment item ID. Every entity has one. |
| `fieldtype_action` | N/A | Action buttons column (edit/delete). Not a data field. |
| `fieldtype_date_added` | int | Auto-set creation timestamp. |
| `fieldtype_date_updated` | int | Auto-set last-modified timestamp. |
| `fieldtype_created_by` | int | Auto-set to creating user's ID. |

#### User Entity Fields (Entity 1 only)

`fieldtype_user_username`, `fieldtype_user_firstname`, `fieldtype_user_lastname`, `fieldtype_user_email`, `fieldtype_user_photo`, `fieldtype_user_status`, `fieldtype_user_language`, `fieldtype_user_skin`, `fieldtype_user_accessgroups`, `fieldtype_user_last_login_date`

These only exist on entity 1 (Users) and map to the `app_users` table instead of `app_entity_1`.

### is_heading Explained

When `is_heading = 1`, that field's value becomes part of the item's display title. Used in:
- Listing views (the clickable item name)
- Breadcrumbs
- Dropdown pickers when another entity references this one via `fieldtype_entity`
- Link text throughout the UI

If multiple fields have `is_heading = 1` on the same entity, their values are concatenated. Example: Entity 48 (Vehicles) has both Make (435) and Model (436) as headings, so items display as "Toyota Camry".

### listing_status Explained

- `1` = Field appears as a column in the entity's listing/table view
- `0` = Field exists but is hidden from the listing

This only controls the listing view. The field still appears on the add/edit form (controlled by `forms_tabs_id`) and in the item detail page.

### Common Operations

**Add a new text field to entity 42, on tab 60:**
```sql
INSERT INTO app_fields (entities_id, forms_tabs_id, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order, comments_forms_tabs_id, forms_rows_position)
VALUES (42, 60, 'fieldtype_input', 'VIN Number', '', 0, '', '', 0, '', '', 0, '', '{}', 20, 1, 20, 0, 0, 0, '');
```
Then create the column in the data table:
```sql
ALTER TABLE app_entity_42 ADD COLUMN field_<NEW_ID> VARCHAR(255) DEFAULT '';
```

**Add a new dropdown field:**
```sql
INSERT INTO app_fields (entities_id, forms_tabs_id, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order, comments_forms_tabs_id, forms_rows_position)
VALUES (42, 60, 'fieldtype_dropdown', 'Urgency', '', 0, '', '', 0, '', '', 0, '', '{"use_global_list":"","width":"input-medium"}', 21, 1, 21, 0, 0, 0, '');
```
Then add the data column AND choices:
```sql
ALTER TABLE app_entity_42 ADD COLUMN field_<NEW_ID> INT(11) DEFAULT 0;
-- Then insert choices (see app_fields_choices section)
```

**Make a field visible in listings:**
```sql
UPDATE app_fields SET listing_status = 1, listing_sort_order = 10 WHERE id = 356;
```

**Make a field the heading:**
```sql
UPDATE app_fields SET is_heading = 1 WHERE id = 354;
```

**Add an entity reference field (link to Customers):**
```sql
INSERT INTO app_fields (entities_id, forms_tabs_id, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order, comments_forms_tabs_id, forms_rows_position)
VALUES (42, 59, 'fieldtype_entity_ajax', 'Customer', '', 0, '', '', 0, '', '', 0, '', '{"entity_id":"47"}', 5, 0, 0, 0, 0, 0, '');
```

---

## Table: `app_fields_choices`

Stores dropdown/checkbox options for choice-based fields.

### Schema

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | int(11) PK | NO | Choice ID. This is the integer stored in `field_XXX` when selected. |
| `fields_id` | int(11) | NO | **Which field this choice belongs to.** FK to `app_fields.id`. |
| `parent_id` | int(11) | NO | Parent choice for nested/hierarchical dropdowns. `0` = top-level choice. Non-zero = child of that choice ID. **NOT the field ID.** |
| `is_active` | tinyint(1) | NO | `1` = active/visible. `0` = disabled (hidden from dropdowns but data preserved). |
| `name` | varchar(255) | NO | Display text shown in the dropdown. |
| `icon` | varchar(64) | NO | FontAwesome icon class. Can be empty string but **cannot be NULL**. |
| `is_default` | tinyint(1) | YES | `1` = pre-selected when creating new items. Only one choice per field should be default. |
| `bg_color` | varchar(16) | NO | Hex background color for display (e.g., `#3498db`). Used in listing pills/badges. **Cannot be NULL** (use empty string). |
| `sort_order` | int(11) | YES | Display order. Lower = first. |
| `users` | text | NO | Comma-separated user IDs who can see this choice. Empty = visible to all. **Cannot be NULL** (use empty string). |
| `value` | varchar(64) | NO | Internal/API value. Often same as `name` but can differ. **Cannot be NULL.** |
| `filename` | varchar(255) | NO | Associated filename/image. Usually empty string. **Cannot be NULL.** |

### The parent_id vs fields_id Gotcha

This is the most common mistake:

- **`fields_id`** = which field this choice belongs to (e.g., `362` for the Stage dropdown)
- **`parent_id`** = parent choice for nesting (e.g., a sub-option under another option). `0` for top-level.

**WRONG:** Setting `parent_id` to the field ID. This creates an orphaned choice nested under a nonexistent parent.
**RIGHT:** `parent_id = 0` for all normal (non-nested) choices.

### Example: Payment Status Choices (field 371)

| id | fields_id | parent_id | name | bg_color | is_default | sort_order |
|----|-----------|-----------|------|----------|------------|------------|
| 91 | 371 | 0 | Pending | #f39c12 | 1 | 1 |
| 92 | 371 | 0 | Invoice Sent | #3498db | 0 | 2 |
| 93 | 371 | 0 | Paid | #27ae60 | 0 | 3 |
| 94 | 371 | 0 | Overdue | #e74c3c | 0 | 4 |

When `field_371 = 93`, the job's payment status is "Paid".

### Common Operations

**Add a choice to an existing dropdown field:**
```sql
INSERT INTO app_fields_choices (fields_id, parent_id, is_active, name, icon, is_default, bg_color, sort_order, users, value, filename)
VALUES (362, 0, 1, 'Warranty', '', 0, '#9c27b0', 17, '', 'Warranty', '');
```

**Rename a choice:**
```sql
UPDATE app_fields_choices SET name = 'Invoice Pending', value = 'Invoice Pending' WHERE id = 92;
```

**Deactivate a choice (soft delete):**
```sql
UPDATE app_fields_choices SET is_active = 0 WHERE id = 95;
```

**Change a choice's color:**
```sql
UPDATE app_fields_choices SET bg_color = '#e91e63' WHERE id = 82;
```

**Set a new default choice:**
```sql
-- Remove old default
UPDATE app_fields_choices SET is_default = 0 WHERE fields_id = 362;
-- Set new default
UPDATE app_fields_choices SET is_default = 1 WHERE id = 84;
```

**Add nested choices (for multilevel dropdowns):**
```sql
-- Top-level choice
INSERT INTO app_fields_choices (fields_id, parent_id, is_active, name, icon, is_default, bg_color, sort_order, users, value, filename)
VALUES (500, 0, 1, 'Engine', '', 0, '', 1, '', 'Engine', '');
-- Child choice (parent_id = the ID of 'Engine' choice above)
INSERT INTO app_fields_choices (fields_id, parent_id, is_active, name, icon, is_default, bg_color, sort_order, users, value, filename)
VALUES (500, @engine_choice_id, 1, 'Oil Leak', '', 0, '', 1, '', 'Oil Leak', '');
```

---

## GOTCHAS

### 1. Integer Fields Need `0` Not Empty String

MySQL strict mode is on. Integer/numeric columns (`fieldtype_input_numeric`, `fieldtype_dropdown`, `fieldtype_entity`, `fieldtype_boolean_checkbox`) will error if you pass `''` (empty string). Always use `0`.

```sql
-- WRONG: causes "Incorrect integer value: '' for column 'field_371'"
UPDATE app_entity_42 SET field_371 = '' WHERE id = 5;

-- RIGHT:
UPDATE app_entity_42 SET field_371 = 0 WHERE id = 5;
```

API calls too: `items[field_371]=0` not `items[field_371]=`.

### 2. Date Fields Store Unix Timestamps, Not Date Strings

`fieldtype_input_date` and `fieldtype_input_datetime` store integers (Unix timestamps).

```sql
-- WRONG:
UPDATE app_entity_42 SET field_368 = '2026-03-15 10:00:00' WHERE id = 5;

-- RIGHT:
UPDATE app_entity_42 SET field_368 = UNIX_TIMESTAMP('2026-03-15 10:00:00') WHERE id = 5;
```

### 3. Choices INSERT Requires ALL NOT NULL Columns

Every column in `app_fields_choices` except `id`, `is_default`, and `sort_order` is NOT NULL. You must provide all of them, even if empty string.

```sql
-- WRONG: missing columns = silent failure or error
INSERT INTO app_fields_choices (fields_id, name) VALUES (362, 'New Status');

-- RIGHT: every NOT NULL column present
INSERT INTO app_fields_choices (fields_id, parent_id, is_active, name, icon, is_default, bg_color, sort_order, users, value, filename)
VALUES (362, 0, 1, 'New Status', '', 0, '', 17, '', 'New Status', '');
```

### 4. forms_tabs_id = 0 Makes Fields Invisible in API

The API does an INNER JOIN between `app_fields` and `app_forms_tabs`. If `forms_tabs_id = 0` and there's no tab with `id = 0`, the field is excluded from all API responses. You can't read or write it via the API.

**Fix:** Assign a valid `forms_tabs_id`:
```sql
-- Find valid tabs for the entity
SELECT id, name FROM app_forms_tabs WHERE entities_id = 42;
-- Fix the field
UPDATE app_fields SET forms_tabs_id = 59 WHERE id = 439;
```

Currently invisible fields (forms_tabs_id=0): system fields (action, id, date_added, parent_item_id) plus some data fields on entities 25, 26, 27, 28, 29, 42, 44.

### 5. Dropdown Fields Store Choice IDs, Not Text

When reading or writing dropdown fields, use the integer choice ID, not the display name.

```sql
-- WRONG:
UPDATE app_entity_42 SET field_371 = 'Paid' WHERE id = 5;

-- RIGHT:
UPDATE app_entity_42 SET field_371 = 93 WHERE id = 5;
```

To find a choice ID:
```sql
SELECT id, name FROM app_fields_choices WHERE fields_id = 371;
```

### 6. parent_id on Choices is NOT the Field ID

`parent_id` on `app_fields_choices` is for nesting choices under other choices (hierarchical dropdowns). For normal flat dropdowns, always use `parent_id = 0`.

```sql
-- WRONG: this nests the choice under choice #362, not field 362
INSERT INTO app_fields_choices (..., parent_id, ...) VALUES (..., 362, ...);

-- RIGHT: top-level choice
INSERT INTO app_fields_choices (..., parent_id, ...) VALUES (..., 0, ...);
```

### 7. New Fields Need a Data Column Too

Adding a row to `app_fields` creates the field definition, but the actual data column in `app_entity_XX` must be created separately. Rukovoditel's admin UI does this automatically; direct SQL does not.

```sql
-- After INSERT INTO app_fields ... (returns new id, e.g., 600)
ALTER TABLE app_entity_42 ADD COLUMN field_600 VARCHAR(255) DEFAULT '';
```

Column types by field type:
- `fieldtype_input`, `fieldtype_textarea`, `fieldtype_tags`: `TEXT` or `VARCHAR(255)`
- `fieldtype_input_numeric`: `DECIMAL(15,2)` or `VARCHAR(255)` (Rukovoditel stores formatted)
- `fieldtype_dropdown`, `fieldtype_entity`, `fieldtype_entity_ajax`, `fieldtype_users`: `INT(11) DEFAULT 0`
- `fieldtype_boolean_checkbox`: `TINYINT(1) DEFAULT 0`
- `fieldtype_input_date`, `fieldtype_input_datetime`: `INT(11) DEFAULT 0`
- `fieldtype_checkboxes`: `VARCHAR(255) DEFAULT ''`
- `fieldtype_textarea_wysiwyg`: `MEDIUMTEXT`
- `fieldtype_attachments`: `TEXT`

### 8. Choices with name/value Mismatch

Some choices have `name` and `value` that differ (legacy data or manual edits). The `name` is displayed to users. The `value` is used in exports and some internal lookups. When adding choices, keep them in sync unless you have a reason not to.

### 9. Entity Reference Fields Need the Target Entity to Exist

`fieldtype_entity` and `fieldtype_entity_ajax` have `configuration.entity_id` pointing to the target entity. If that entity is deleted, the field breaks silently. Always verify the target entity exists.

### 10. Checkbox Fields Store Comma-Separated IDs

`fieldtype_checkboxes` stores multiple selected choice IDs as a comma-separated string: `"178,179"`. When querying, use `FIND_IN_SET()`:
```sql
SELECT * FROM app_entity_36 WHERE FIND_IN_SET(178, field_330);
```

---

## Quick Reference: Data Table Naming

Entity data lives in `app_entity_<entity_id>`:
- Entity 42 (Mechanic Jobs) -> `app_entity_42`
- Entity 25 (Leads) -> `app_entity_25`

Each row has: `id`, `parent_item_id` (for sub-entities), `date_added`, `date_updated`, `created_by`, plus `field_XXX` for each field.

## Quick Reference: Finding Things

```sql
-- All fields for an entity
SELECT id, type, name, forms_tabs_id, listing_status FROM app_fields
WHERE entities_id = 42 ORDER BY sort_order;

-- All choices for a dropdown field
SELECT id, name, bg_color, is_default, sort_order FROM app_fields_choices
WHERE fields_id = 362 ORDER BY sort_order;

-- All tabs for an entity
SELECT id, name, sort_order FROM app_forms_tabs
WHERE entities_id = 42 ORDER BY sort_order;

-- Find which field a choice ID belongs to
SELECT fc.id, fc.name, f.name as field_name, f.entities_id
FROM app_fields_choices fc
JOIN app_fields f ON fc.fields_id = f.id
WHERE fc.id = 93;

-- Find invisible fields (forms_tabs_id=0 that aren't system fields)
SELECT id, entities_id, type, name FROM app_fields
WHERE forms_tabs_id = 0
  AND type NOT IN ('fieldtype_action','fieldtype_id','fieldtype_date_added','fieldtype_parent_item_id');
```
