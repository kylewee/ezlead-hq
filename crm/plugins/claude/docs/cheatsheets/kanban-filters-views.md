# Kanban Boards, Filters, List Views, and Saved Views

Rukovoditel v3.6.4 cheat sheet for programmatic manipulation via MySQL.

---

## Table: `app_ext_kanban`

The kanban board definition table. Each row = one kanban board.

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment ID |
| `entities_id` | int(11) | Which entity this kanban displays (e.g., 42=Jobs, 25=Leads) |
| `in_menu` | tinyint(1) | Show in left sidebar menu (1=yes, 0=no). Only works for top-level entities (parent_id=0) |
| `heading_template` | varchar(255) | Text pattern for card title. Uses `[field_id]` syntax (see below) |
| `name` | varchar(255) | Display name shown in menu and page title |
| `group_by_field` | int(11) | Field ID to group columns by. Must be dropdown, stages, autostatus, or radioboxes type |
| `exclude_choices` | text | Comma-separated choice IDs to hide as columns (e.g., `217,218,219`) |
| `fields_in_listing` | text | Comma-separated field IDs shown on each card body (e.g., `355,366`) |
| `sum_by_field` | text | Comma-separated numeric field IDs to sum in column headers (e.g., `366` for Cost) |
| `width` | int(11) | Column width in pixels. Default 300 if 0 or empty |
| `filters_panel` | varchar(32) | `default` = standard filter sidebar, `quick_filters` = horizontal quick-filter bar, empty = no filters |
| `rows_per_page` | smallint(6) | Cards per column before pagination. Default 20 if 0 |
| `users_groups` | text | Comma-separated group IDs that can see this board (e.g., `0,4,5`). Uses `FIND_IN_SET()` |
| `assigned_to` | varchar(255) | Comma-separated user IDs that can see this board. Also checked via `FIND_IN_SET()` |
| `is_active` | tinyint(1) | 1=active, 0=disabled |

### heading_template Syntax

The `heading_template` uses `[field_id]` bracket notation, processed by `fieldtype_text_pattern::output_singe_text()`.

Pattern: `preg_match_all('/\[(\w+)\]/', $pattern, $matches)` -- matches any `[word]` token.

**Field references:**
- `[354]` -- renders the output of field 354 for each item
- `[359]` -- renders field 359
- Combine with literal text: `[354] - [359] [360]` produces e.g., "John's Camry - 2019 Toyota Camry"

**Special tokens:**
- `[id]` -- the item's database ID
- `[current_user_name]` -- logged-in user's name
- `[current_user_id]` -- logged-in user's ID
- `[url]` -- URL to the item's detail page
- `[comment]` -- last comment text
- `${Y-m-d}` -- current date (PHP date format)
- `{field_id}` -- older/alternate syntax, also works

**If heading_template is empty:** Falls back to `items::get_heading_field()` which uses the entity's designated heading field.

### Live Examples

```sql
SELECT id, name, entities_id, group_by_field, heading_template, fields_in_listing,
       exclude_choices, sum_by_field, width, filters_panel
FROM app_ext_kanban;
```

| id | name | entity | group_by | heading | card_fields | excluded | sum | width |
|----|------|--------|----------|---------|-------------|----------|-----|-------|
| 4 | Mechanic Jobs Board | 42 | 362 (Stage) | `[354] - [359] [360]` | 355,366 | 217-221 | 366 | 300 |
| 6 | Hotline Board | 42 | 362 (Stage) | `[354] - [359] [360]` | 355,366 | 82-90,95,96 | 366 | 300 |
| 3 | Appointments Kanban | 29 | 425 | `[Title]` | 255,256,257,258 | | | 280 |
| 5 | Records Requests | 52 | 510 | `[503] - [508]` | 503,505,508,506 | | | 280 |

### Common Operations

**Create a kanban board:**
```sql
INSERT INTO app_ext_kanban (entities_id, in_menu, heading_template, name, group_by_field,
  exclude_choices, fields_in_listing, sum_by_field, width, filters_panel,
  rows_per_page, users_groups, assigned_to, is_active)
VALUES (25, 1, '[210]', 'Leads Pipeline', 268,
  '', '210,211,215,218', '', 280, 'default',
  50, '0,4,5', '1', 1);
```

**Change card display fields:**
```sql
UPDATE app_ext_kanban SET fields_in_listing = '354,355,362,366,371' WHERE id = 4;
```

**Exclude stages from kanban (hide "Paid" and "Cancelled" columns):**
```sql
UPDATE app_ext_kanban SET exclude_choices = '90,96' WHERE id = 4;
```

**Add a sum field to column headers:**
```sql
UPDATE app_ext_kanban SET sum_by_field = '366,371' WHERE id = 4;
```

### Why Cards Show Blank (Common Causes)

1. **`heading_template` references a field that doesn't exist** -- if field was deleted or ID is wrong, the `[123]` token renders as empty string.
2. **`heading_template` references a field with no value** -- the item's field is NULL/empty. All tokens resolve to empty, card title is blank.
3. **`heading_template` uses wrong syntax** -- `{field_349}` (curly braces) is the old syntax. The kanban renderer uses `fieldtype_text_pattern` which expects `[349]` (square brackets). The form saves whatever you type. **Use square brackets.**
4. **`fields_in_listing` references a field with `forms_tabs_id=0`** -- the field query in `kanban.php` line 48 does `select * from app_fields where id='X'` which works, but `items::prepare_field_value_by_type()` may fail if the field type handler expects tab context.
5. **`group_by_field` is invalid** -- if the referenced field doesn't exist, the kanban page shows an error: "Field X does not exist in entity Y".
6. **Empty `fields_in_listing`** -- cards show title only, no body content. Not "blank" but looks empty.
7. **Access denied** -- `users_groups` and `assigned_to` are checked via `FIND_IN_SET()`. If the user's group_id isn't listed AND their user id isn't in assigned_to, the board returns 404.

---

## Table: `app_reports`

The central table for ALL listing configurations -- entity defaults, saved views, kanban backing reports, dashboard counters, and more. The `reports_type` column determines what kind of report it is.

### Schema (Key Columns)

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment |
| `parent_id` | int(11) | 0 for top-level. Non-zero for sub-reports |
| `entities_id` | int(11) | Which entity |
| `created_by` | int(11) | User who created it. For `entity` type, each user gets their own row |
| `reports_type` | varchar(64) | **Critical discriminator** (see below) |
| `name` | varchar(64) | Display name (empty for entity defaults) |
| `description` | text | Tooltip/description |
| `menu_icon` | varchar(64) | FontAwesome class (e.g., `fa-fire`) |
| `icon_color` | varchar(7) | Hex color for icon |
| `in_menu` | tinyint(1) | Show in left sidebar |
| `in_dashboard` | tinyint(4) | Show on dashboard (0=no, 1-4 = various dashboard positions) |
| `in_dashboard_counter` | tinyint(1) | Show as counter badge on dashboard |
| `in_header` | tinyint(1) | Show in top header bar |
| `listing_order_fields` | text | Default sort, format: `field_id_direction` e.g., `209_asc`, `274_desc` |
| `fields_in_listing` | text | Comma-separated field IDs shown in list columns (e.g., `210,211,218,265,266`) |
| `rows_per_page` | int(11) | 0 = use system default |
| `users_groups` | text | Comma-separated group IDs for access |
| `assigned_to` | text | Comma-separated user IDs for access |
| `displays_assigned_only` | tinyint(1) | Only show items assigned to current user |
| `listing_type` | varchar(16) | Listing display type override |
| `listing_col_width` | text | Column width overrides |

### reports_type Values

| reports_type | Meaning | Created by |
|-------------|---------|-----------|
| `entity` | Per-user default listing config for an entity | Auto-created per user on first visit |
| `parent` | Per-user listing config when viewing as child entity | Auto-created |
| `standard` | **Saved view / named report with filters** | Admin via UI |
| `kanbanN` | Per-user backing report for kanban board N | Auto-created when user visits kanban |
| `default_kanban_reportsN` | **Admin-set default filters for kanban N** | Auto-created on first admin visit |
| `panel_kanban_reportsN` | Quick-filter panel backing report for kanban N | Auto-created |
| `mail_related_items_N` | Email module related items | Auto-created |

### How Per-User Default Config Works

When user ID 1 visits entity 25 (Leads) for the first time, the system creates:
```sql
INSERT INTO app_reports (entities_id, reports_type, created_by, ...)
VALUES (25, 'entity', 1, ...);
```

This row stores that user's personal sort order (`listing_order_fields`) and visible columns (`fields_in_listing`). Each user gets their own `entity` row per entity.

### How Saved Views (Standard Reports) Work

Saved views are `reports_type='standard'` rows. They combine:
1. **Display config** in `app_reports` (columns, sort, icon, menu placement)
2. **Filter rules** in `app_reports_filters` (which records to show)

Example -- "Cold Leads" view:
```sql
-- The report definition
SELECT * FROM app_reports WHERE id = 79;
-- reports_type='standard', name='Cold Leads (7+ days)', entities_id=25
-- fields_in_listing='210,211,218,265,266', in_menu=1, in_dashboard=1

-- The filter rules
SELECT * FROM app_reports_filters WHERE reports_id = 79;
-- fields_id=266, filters_values='>7', filters_condition='include'
-- (field 266 = "Days Since Contact", show only where > 7)
```

**Create a saved view via SQL:**
```sql
-- Step 1: Create the report
INSERT INTO app_reports (entities_id, created_by, reports_type, name, description,
  menu_icon, icon_color, in_menu, in_dashboard, in_dashboard_counter,
  fields_in_listing, listing_order_fields)
VALUES (42, 1, 'standard', 'Overdue Invoices', 'Jobs past due',
  'fa-exclamation', '#e74c3c', 1, 1, 1,
  '354,362,366,371', '366_desc');

-- Step 2: Add filter(s)
INSERT INTO app_reports_filters (reports_id, fields_id, filters_values, filters_condition, is_active)
VALUES (LAST_INSERT_ID(), 371, '94', 'include', 1);
-- field 371 = Payment Status, choice 94 = Overdue
```

---

## Table: `app_reports_filters`

Filter rules attached to reports. Each row = one filter condition.

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment |
| `reports_id` | int(11) | FK to `app_reports.id` |
| `fields_id` | int(11) | Which field to filter on |
| `filters_values` | text | The filter value(s). Format depends on field type |
| `filters_condition` | varchar(64) | `include` or `exclude` (and sometimes blank) |
| `is_active` | tinyint(1) | 1=active, 0=disabled |

### filters_values Format by Field Type

| Field Type | Example filters_values | Meaning |
|-----------|----------------------|---------|
| Dropdown/Stages | `82` or `83,84,85` | Choice IDs (comma-separated for multiple) |
| Numeric | `>7` or `<=100` | Comparison operator + value |
| Text | `john` | Substring match |
| Date | `2026-01-01\|2026-12-31` | Range with pipe separator |
| Users | `1,2,3` | User IDs |

### Live Filter Examples

```sql
-- "Cold Leads" filter: Days Since Contact > 7
INSERT INTO app_reports_filters (reports_id, fields_id, filters_values, filters_condition, is_active)
VALUES (79, 266, '>7', 'include', 1);

-- "Active Jobs" filter: Stage IN (Estimate Sent, Accepted, Scheduled, Parts Ordered, Confirmed, In Progress)
INSERT INTO app_reports_filters (reports_id, fields_id, filters_values, filters_condition, is_active)
VALUES (211, 362, '83,84,85,86,87,88', 'include', 1);

-- "Unpaid Jobs" filter: Stage = Complete (89)
INSERT INTO app_reports_filters (reports_id, fields_id, filters_values, filters_condition, is_active)
VALUES (212, 362, '89', 'include', 1);
```

---

## Table: `app_reports_filters_templates`

User-saved filter presets. Users can save their current filter selections as named templates to reuse later.

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment |
| `fields_id` | int(11) | Field this template applies to |
| `users_id` | int(11) | Which user saved it |
| `filters_values` | text | The saved filter value |
| `filters_condition` | varchar(64) | include/exclude |

Currently empty in this installation.

---

## Table: `app_users_filters`

Per-user saved filter tab/view configurations (the tabs shown above entity listings).

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment |
| `reports_id` | int(11) | FK to `app_reports.id` (the backing entity report) |
| `users_id` | int(11) | Which user owns this filter tab |
| `name` | varchar(64) | Display name of the saved filter tab |
| `fields_in_listing` | text | Column overrides for this tab (comma-separated field IDs) |
| `listing_order_fields` | text | Sort override for this tab |

These are the personal "My Filters" tabs users create from the listing page filter panel. Each tab stores a snapshot of column and sort preferences. The actual filter values are stored in `app_user_filters_values`.

---

## Table: `app_user_filters_values`

The actual filter criteria for user-saved filter tabs.

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment |
| `filters_id` | int(11) | FK to `app_users_filters.id` |
| `reports_id` | int(11) | FK to `app_reports.id` |
| `fields_id` | int(11) | Field being filtered |
| `filters_values` | text | Filter value (same format as `app_reports_filters`) |
| `filters_condition` | varchar(64) | include/exclude |
| `is_active` | tinyint(1) | 1=active |

---

## Table: `app_filters_panels`

Defines filter panel configurations (the quick-filter bars that appear above listings and kanban boards).

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment |
| `entities_id` | int(11) | Which entity |
| `type` | varchar(64) | Panel type identifier (see below) |
| `is_active` | tinyint(1) | 1=active |
| `is_active_filters` | tinyint(1) | Whether filters are enabled |
| `position` | varchar(16) | `horizontal` or `vertical` |
| `users_groups` | text | Access restriction by group |
| `width` | tinyint(1) | Width setting |
| `sort_order` | smallint(6) | Display order |

### type Values

| type Pattern | Meaning |
|-------------|---------|
| `kanban_reportsN` | Quick filter panel for kanban board N |
| `common_report_filters_panel_N` | Quick filter panel for standard report N |

### Live Data

```
kanban_reports3  -> entity 29 (Appointments kanban)
kanban_reports1  -> entity 41 (Workflows kanban)
kanban_reports4  -> entity 42 (Mechanic Jobs kanban)
common_report_filters_panel_79  -> entity 25 (Cold Leads report)
common_report_filters_panel_210 -> entity 42 (New Jobs report)
```

---

## Table: `app_filters_panels_fields`

Individual fields within a filter panel.

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment |
| `panels_id` | int(11) | FK to `app_filters_panels.id` |
| `entities_id` | int(11) | Which entity |
| `fields_id` | int(11) | Which field to filter by |
| `title` | varchar(64) | Override label (empty = use field name) |
| `width` | varchar(16) | CSS width |
| `height` | varchar(16) | CSS height |
| `display_type` | varchar(32) | How to render (dropdown, checkboxes, etc.) |
| `search_type_match` | tinyint(1) | Exact match vs contains |
| `exclude_values` | text | Choice IDs to hide from filter options |
| `exclude_values_not_in_listing` | tinyint(1) | Auto-hide choices not present in current listing |
| `sort_order` | smallint(6) | Display order |

---

## Table: `app_listing_highlight_rules`

Row-coloring rules for listings and kanban cards. Applied via `listing_highlight::apply($item)` which returns a CSS class.

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(10) PK | Auto-increment |
| `entities_id` | int(10) | Which entity |
| `is_active` | tinyint(1) | 1=active |
| `fields_id` | int(10) | Field to check |
| `fields_values` | text | Choice ID to match |
| `bg_color` | varchar(7) | Hex background color (e.g., `#E3F2FD`) |
| `users_groups` | text | Restrict to certain groups (empty = all) |
| `sort_order` | int(11) | Priority order |
| `notes` | text | Admin notes |

### Live Examples (Entity 42 - Jobs)

| Stage Choice ID | Color | Meaning |
|----------------|-------|---------|
| 82 (New Lead) | #E3F2FD | Blue |
| 83 (Estimate Sent) | #F3E5F5 | Purple |
| 84 (Accepted) | #E8F5E9 | Green |
| 85 (Scheduled) | #FFF8E1 | Yellow |
| 86 (Parts Ordered) | #FFF3E0 | Orange |
| 87 (Confirmed) | #E0F2F1 | Teal |
| 88 (In Progress) | #FFEBEE | Red |
| 89 (Complete) | #C8E6C9 | Bright green |
| 90 (Paid) | #A5D6A7 | Dark green |
| 95 (Follow Up) | #ECEFF1 | Gray |

**Create a highlight rule:**
```sql
INSERT INTO app_listing_highlight_rules
  (entities_id, is_active, fields_id, fields_values, bg_color, users_groups, sort_order, notes)
VALUES (25, 1, 268, '75', '#E3F2FD', '', 1, 'New lead - blue');
```

---

## Table: `app_listing_types`

Custom listing type templates. Defines how entity listings render (card view, compact view, etc.).

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment |
| `entities_id` | int(11) | Which entity |
| `type` | varchar(16) | Type identifier |
| `is_active` | tinyint(1) | 1=active |
| `is_default` | tinyint(4) | 1=default listing type |
| `width` | smallint(6) | Width setting |
| `settings` | text | JSON/serialized settings |

Currently empty in this installation -- all entities use default table listing.

---

## Table: `app_listing_sections`

Sections within a custom listing type (grouping fields into visual blocks on cards).

### Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | int(11) PK | Auto-increment |
| `listing_types_id` | int(11) | FK to `app_listing_types.id` |
| `name` | varchar(255) | Section name |
| `fields` | text | Comma-separated field IDs in this section |
| `display_as` | varchar(16) | Display mode |
| `display_field_names` | tinyint(1) | Show field labels |
| `text_align` | varchar(16) | CSS text alignment |
| `width` | varchar(16) | CSS width |
| `sort_order` | int(11) | Display order |

---

## Default Sort / listing_order_fields

Stored in `app_reports.listing_order_fields`. Format: `fieldId_direction`.

| Example | Meaning |
|---------|---------|
| `209_asc` | Sort by field 209 ascending |
| `274_desc` | Sort by field 274 descending |
| `371_asc` | Sort by field 371 ascending |
| (empty) | Default: sort by `parent_item_id` |

**Set default sort for a user's entity view:**
```sql
UPDATE app_reports SET listing_order_fields = '209_desc'
WHERE entities_id = 25 AND reports_type = 'entity' AND created_by = 1;
```

---

## Kanban Default Filters

Each kanban board can have admin-set default filters that apply to ALL users (in addition to any user-applied filters).

These use `app_reports` rows with `reports_type = 'default_kanban_reportsN'` where N = kanban board ID.

```sql
-- Find the default filter report for kanban board 4
SELECT id FROM app_reports WHERE reports_type = 'default_kanban_reports4';
-- Returns report ID 135

-- Add a default filter (e.g., only show jobs from Business 2)
INSERT INTO app_reports_filters (reports_id, fields_id, filters_values, filters_condition, is_active)
VALUES (135, 475, '2', 'include', 1);
```

The filter layering for a kanban board:
1. **Default kanban filters** (`default_kanban_reportsN`) -- admin-set, applies to everyone
2. **User filters** (`kanbanN` report per user) -- personal sidebar filters
3. **Quick filters** (`panel_kanban_reportsN`) -- horizontal filter bar selections
4. **exclude_choices** -- columns hidden entirely from the board

All filter layers are ANDed together via `reports::add_filters_query()`.

---

## How Kanban Card Rendering Works (PHP)

Source: `/var/www/ezlead-hq/crm/plugins/ext/classes/kanban.php`

1. `kanban::get_items_html()` is called per column (choice ID)
2. For each item in the column:
   - **Title**: If `heading_template` is set, calls `fieldtype_text_pattern::output_singe_text()` which replaces `[field_id]` tokens with rendered field values. If empty, falls back to `items::get_heading_field()`.
   - **Body**: Iterates `fields_in_listing` (comma-separated field IDs), queries each field definition, calls `items::prepare_field_value_by_type()` then `fields_types::output()` to render. Builds an HTML table of label:value rows.
   - **Highlight**: `listing_highlight::apply($item)` returns a CSS class based on `app_listing_highlight_rules`.
   - **Actions**: Edit/delete buttons based on user access schema.
3. Pagination via `split_page` class if items exceed `rows_per_page`.

Source files:
- `/var/www/ezlead-hq/crm/plugins/ext/classes/kanban.php` -- `get_items_html()` and `get_items_query()`
- `/var/www/ezlead-hq/crm/plugins/ext/modules/kanban/components/kanban.php` -- column layout, JS sortable, sum headers
- `/var/www/ezlead-hq/crm/plugins/ext/modules/kanban/actions/view.php` -- access control, report creation, drag-drop handler
- `/var/www/ezlead-hq/crm/plugins/ext/modules/kanban/actions/reports.php` -- admin CRUD for kanban boards
- `/var/www/ezlead-hq/crm/plugins/ext/modules/kanban/views/form.php` -- admin form UI
- `/var/www/ezlead-hq/crm/includes/classes/fieldstypes/fieldtype_text_pattern.php` -- `[field_id]` template engine

---

## Quick Reference: SQL Recipes

### List all kanban boards
```sql
SELECT k.id, k.name, k.entities_id, e.name as entity_name, k.group_by_field,
       k.heading_template, k.fields_in_listing, k.exclude_choices, k.is_active
FROM app_ext_kanban k
JOIN app_entities e ON k.entities_id = e.id;
```

### List all saved views (standard reports) for an entity
```sql
SELECT r.id, r.name, r.description, r.menu_icon, r.in_menu, r.in_dashboard,
       r.fields_in_listing, r.listing_order_fields
FROM app_reports r
WHERE r.reports_type = 'standard' AND r.entities_id = 42;
```

### List filters for a saved view
```sql
SELECT rf.fields_id, f.name as field_name, rf.filters_values, rf.filters_condition
FROM app_reports_filters rf
JOIN app_fields f ON rf.fields_id = f.id
WHERE rf.reports_id = 79 AND rf.is_active = 1;
```

### Get a user's current listing config for an entity
```sql
SELECT listing_order_fields, fields_in_listing
FROM app_reports
WHERE entities_id = 42 AND reports_type = 'entity' AND created_by = 1;
```

### List highlight rules for an entity
```sql
SELECT h.fields_id, f.name, h.fields_values, c.value as choice_name, h.bg_color, h.notes
FROM app_listing_highlight_rules h
JOIN app_fields f ON h.fields_id = f.id
LEFT JOIN app_fields_choices c ON h.fields_values = c.id
WHERE h.entities_id = 42 AND h.is_active = 1
ORDER BY h.sort_order;
```

### Delete a saved view and its filters
```sql
SET @report_id = 79;
DELETE FROM app_reports_filters WHERE reports_id = @report_id;
DELETE FROM app_reports WHERE id = @report_id;
```

### Create a complete saved view with filter and dashboard counter
```sql
-- Create report
INSERT INTO app_reports (entities_id, created_by, reports_type, name, description,
  menu_icon, icon_color, in_menu, in_dashboard, in_dashboard_counter,
  in_dashboard_counter_color, in_dashboard_counter_bg_color,
  fields_in_listing, listing_order_fields, dashboard_sort_order, dashboard_counter_sort_order)
VALUES (42, 1, 'standard', 'My View Name', 'Description here',
  'fa-wrench', '#e67e22', 1, 1, 1,
  '', '#e67e22',
  '354,362,366,371', '366_desc', 10, 10);

SET @rid = LAST_INSERT_ID();

-- Add filter: Stage = In Progress (choice 88)
INSERT INTO app_reports_filters (reports_id, fields_id, filters_values, filters_condition, is_active)
VALUES (@rid, 362, '88', 'include', 1);
```
