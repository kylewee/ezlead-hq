# Rukovoditel v3.6.4 Reports & Charts Cheat Sheet

## Overview

Rukovoditel has multiple reporting subsystems, each stored in its own table and accessed through separate modules. The main dashboard shows **counters** (from `app_reports`) and can embed any report type via **Report Groups**.

---

## 1. Standard Reports (`app_reports`)

The core reporting table. Every entity listing, filter preset, kanban view, and dashboard counter traces back to a row here.

### Table: `app_reports`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Report ID |
| `parent_id` | int | Parent report (for chained entity filters) |
| `entities_id` | int | Which entity this report filters |
| `created_by` | int | User who created it |
| `reports_type` | varchar(64) | See report types below |
| `name` | varchar(64) | Display name |
| `menu_icon` | varchar(64) | FontAwesome class (e.g. `fa-briefcase`) |
| `icon_color` / `bg_color` | varchar(7) | Hex colors for icon and background |
| `in_menu` | tinyint | Show in left sidebar menu |
| `in_dashboard` | tinyint | 0=No, 1=Yes, 2=Yes (hide if empty) |
| `in_dashboard_counter` | tinyint | Show as counter tile on dashboard |
| `in_dashboard_icon` | tinyint | Show icon in counter tile |
| `in_dashboard_counter_color` | varchar(16) | Counter text color |
| `in_dashboard_counter_bg_color` | varchar(16) | Counter background color |
| `in_dashboard_counter_fields` | varchar(255) | Comma-separated field IDs to sum in counter |
| `dashboard_counter_hide_count` | tinyint | Hide record count (show only sums) |
| `dashboard_counter_hide_zero_count` | tinyint | Hide counter tile when 0 records match |
| `dashboard_counter_sum_by_field` | int | Replace count with sum of this numeric field |
| `in_header` | tinyint | Show in top header bar |
| `in_header_autoupdate` | tinyint | Auto-refresh header counter |
| `dashboard_sort_order` | int | Sort order on dashboard listing |
| `header_sort_order` | int | Sort order in header |
| `dashboard_counter_sort_order` | int | Sort order for counter tiles |
| `listing_order_fields` | text | Default sort fields for listing |
| `users_groups` | text | Comma-separated group IDs with access |
| `assigned_to` | text | Comma-separated user IDs with access |
| `displays_assigned_only` | tinyint | Only show records assigned to current user |
| `parent_entity_id` | int | For scoped sub-entity reports |
| `parent_item_id` | int | For scoped sub-entity reports |
| `fields_in_listing` | text | Comma-separated field IDs to show as columns |
| `rows_per_page` | int | Pagination (0 = default) |
| `notification_days` | varchar(32) | Days to send email notifications |
| `notification_time` | varchar(255) | Times to send email notifications |
| `listing_type` | varchar(16) | Listing display variant |
| `listing_col_width` | text | Column width overrides |

### `reports_type` Values

| Value | Meaning |
|-------|---------|
| `standard` | User-created named report with filters. Can appear on dashboard, in menu, as counter. |
| `common` | Shared report visible to specified groups/users (Extension feature) |
| `entity` | Auto-generated per-user default listing for an entity |
| `default` | System default template for an entity (copied when creating `entity` reports) |
| `parent` | Auto-generated for parent entity filtering (chained from another report) |
| `entity_menu` | Entity-specific menu listing |
| `kanbanN` | Kanban board view (N = kanban config ID) |
| `default_kanban_reportsN` | Default kanban filter report |
| `calendarreportN` | Calendar report |
| `calendar_reminderN` | Calendar reminder report |
| `calendar_reminder_pivotN` | Calendar reminder pivot report |
| `mail_related_items_N` | Mail-related items report |
| `fieldNNN_entity_item_info_page` | Entity field info page report |

### Creating a Standard Report via MySQL

```sql
-- Insert the report
INSERT INTO app_reports (
    entities_id, created_by, reports_type, name,
    menu_icon, icon_color, bg_color,
    in_menu, in_dashboard, in_dashboard_counter,
    fields_in_listing, description, listing_order_fields,
    users_groups, assigned_to, displays_assigned_only,
    parent_entity_id, parent_item_id, rows_per_page,
    notification_days, notification_time, listing_type,
    listing_col_width, in_dashboard_counter_color,
    in_dashboard_counter_bg_color, in_dashboard_counter_fields,
    dashboard_counter_hide_count, dashboard_counter_hide_zero_count,
    dashboard_counter_sum_by_field, in_header, in_header_autoupdate,
    dashboard_sort_order, header_sort_order, dashboard_counter_sort_order,
    in_dashboard_icon
) VALUES (
    42, 1, 'standard', 'My Report Name',
    'fa-wrench', '#333333', '',
    1, 1, 1,
    '354,362,366,371', '', '',
    '', '', 0,
    0, 0, 0,
    '', '', '',
    '', '', '', '',
    0, 0, 0, 0, 0,
    10, 0, 10, 0
);

SET @report_id = LAST_INSERT_ID();

-- Add filters
INSERT INTO app_reports_filters (reports_id, fields_id, filters_condition, filters_values, is_active)
VALUES (@report_id, 362, 'include', '82', 1);
```

### Existing Dashboard Reports (Our CRM)

| ID | Entity | Name | Filters |
|----|--------|------|---------|
| 79 | 25 (Leads) | Cold Leads (7+ days) | field_266 (days) > 7 |
| 80 | 25 (Leads) | Hot Leads | field_266 (days) <= 7 |
| 81 | 29 (Appointments) | Today Appointments | field_257 (datetime) filter_by_days=0 |
| 210 | 42 (Jobs) | New Jobs | field_362 (stage) = 82 (New) |
| 211 | 42 (Jobs) | Active Jobs | field_362 (stage) = 83,84,85,86,87,88 |
| 212 | 42 (Jobs) | Unpaid Jobs | field_362 (stage) = 89 |
| 213 | 36 (Actions) | Open Tasks | field_330 (done) is_empty |

---

## 2. Report Filters (`app_reports_filters`)

Each filter is a row that restricts which records a report shows.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `reports_id` | int FK | Links to `app_reports.id` |
| `fields_id` | int FK | Which field to filter on |
| `filters_condition` | varchar(64) | Condition type (see below) |
| `filters_values` | text | Condition value(s), comma-separated |
| `is_active` | tinyint | 1=active, 0=disabled |

### Filter Conditions

**Dropdown/Checkbox/User fields:**
| Condition | Meaning |
|-----------|---------|
| `include` | Record's field value is IN the listed choice IDs |
| `exclude` | Record's field value is NOT IN the listed choice IDs |
| `empty_value` | Field has no value |
| `not_empty_value` | Field has a value |
| `is_empty` | Alias for empty check |

**Numeric fields:**
| Condition | Meaning |
|-----------|---------|
| `include` | Supports operators: `>5`, `<10`, `>=100`, `5-10` (range), `5,10,15` (specific values) |
| `empty_value` | Field is empty/null |
| `not_empty_value` | Field has a value |

**Date fields:**
| Condition | Value | Meaning |
|-----------|-------|---------|
| `filter_by_days` | `0` | Today |
| `filter_by_days` | `-7` | Last 7 days |
| `filter_by_days` | `7` | Next 7 days |
| `filter_by_days` | `0,2026-01-01,2026-03-31` | Date range (value, from, to) |
| `filter_by_week` | `0` | This week, `-1` last week, `1` next week |
| `filter_by_month` | `0` | This month, `-1` last month |
| `filter_by_year` | `0` | This year |
| `filter_by_overdue` | | Past due dates |
| `filter_by_overdue_with_time` | | Past due (considers time) |
| `empty_value` | | No date set |
| `not_empty_value` | | Has a date |

**File/Attachment fields:** Only `empty_value` and `not_empty_value`.

**Related records:** `include` (has related) or `exclude` (no related).

### Filter Templates (`app_reports_filters_templates`)

Users can save filter configs as templates for reuse:

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `fields_id` | int | Field the template applies to |
| `users_id` | int | Owner |
| `filters_values` | text | Saved values |
| `filters_condition` | varchar(64) | Saved condition |

---

## 3. Graphic Reports (`app_ext_graphicreport`)

Time-series line or column charts. **Extension feature.**

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `entities_id` | int | Entity to chart |
| `name` | varchar(64) | Chart title |
| `xaxis` | int | Field ID for X-axis (date field) |
| `yaxis` | varchar(255) | Field ID(s) for Y-axis (numeric or count) |
| `allowed_groups` | text | Comma-separated group IDs |
| `chart_type` | varchar(16) | `line` or `column` |
| `period` | text | `hourly`, `daily`, `monthly`, `yearly` |
| `show_totals` | tinyint | Show totals on chart |
| `hide_zero` | tinyint | Hide zero values |

### Creating a Graphic Report

```sql
INSERT INTO app_ext_graphicreport (
    entities_id, name, xaxis, yaxis, allowed_groups,
    chart_type, period, show_totals, hide_zero
) VALUES (
    42,                  -- Mechanic Jobs
    'Jobs Created Per Month',
    0,                   -- 0 = use date_added as X-axis
    '',                  -- empty = count records
    '0',                 -- admin group
    'column',            -- 'line' or 'column'
    'monthly',           -- 'hourly', 'daily', 'monthly', 'yearly'
    1,                   -- show totals
    1                    -- hide zero values
);
```

**URL:** `index.php?module=ext/graphicreport/view&id=N`

**PHP module:** `/crm/plugins/ext/modules/graphicreport/`
- `actions/configuration.php` — CRUD
- `actions/configuration_form.php` — Load form data
- `actions/view.php` — Render chart
- `views/configuration_form.php` — Form UI (chart_type, period, entity, xaxis, yaxis)
- `views/view.php` — Display page

---

## 4. Funnel Charts (`app_ext_funnelchart`)

Group records by a dropdown/stage field and display as funnel, bar chart, or table. **Extension feature.**

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `entities_id` | int | Entity to chart |
| `name` | varchar(255) | Chart title |
| `type` | varchar(16) | `funnel`, `bars`, or `table` |
| `in_menu` | tinyint | Show in menu |
| `group_by_field` | int | Dropdown field ID to group by |
| `hide_zero_values` | tinyint | Hide stages with 0 records |
| `exclude_choices` | text | Choice IDs to exclude |
| `sum_by_field` | text | Numeric field to sum (instead of counting) |
| `users_groups` | text | Access control |
| `colors` | text | Custom colors per choice |

### Creating a Funnel Chart

```sql
-- Jobs by Stage as a funnel
INSERT INTO app_ext_funnelchart (
    entities_id, name, type, in_menu,
    group_by_field, hide_zero_values, exclude_choices,
    sum_by_field, users_groups, colors
) VALUES (
    42,                  -- Mechanic Jobs
    'Jobs by Stage',
    'funnel',            -- 'funnel', 'bars', or 'table'
    1,                   -- show in menu
    362,                 -- field_362 = Stage dropdown
    1,                   -- hide zero-count stages
    '',                  -- no excluded choices
    '',                  -- count records (or field ID to sum)
    '0',                 -- admin access
    ''                   -- auto colors
);
```

**URL:** `index.php?module=ext/funnelchart/view&id=N`

**PHP module:** `/crm/plugins/ext/modules/funnelchart/`

---

## 5. Pivot Reports (`app_ext_pivotreports`)

Cross-tabulation reports. Group records by one field on rows, another on columns. **Extension feature.**

### Table: `app_ext_pivotreports`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `entities_id` | int | Entity |
| `name` | varchar(64) | Report name |
| `allowed_groups` | text | Access control |
| `allow_edit` | tinyint | Allow users to edit settings |
| `cfg_numer_format` | varchar(64) | Number format config |
| `sort_order` | int | Display order |
| `reports_settings` | text | JSON/serialized settings |
| `view_mode` | tinyint | Display mode |

### Table: `app_ext_pivotreports_fields`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `pivotreports_id` | int FK | Links to pivot report |
| `entities_id` | int | Entity |
| `fields_id` | int | Field used as row/column header |
| `fields_name` | varchar(64) | Display name override |
| `cfg_date_format` | varchar(64) | Date grouping format |

### Table: `app_ext_pivotreports_settings`

Per-user saved settings for pivot reports.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `reports_id` | int FK | Pivot report ID |
| `users_id` | int | User |
| `reports_settings` | text | User's saved config |
| `view_mode` | tinyint | User's view mode |

---

## 6. Pivot Tables (`app_ext_pivot_tables`)

Different from Pivot Reports. These are summary tables with chart visualization built in. **Extension feature.**

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `entities_id` | int | Entity |
| `name` | varchar(64) | Table name |
| `in_menu` | tinyint | Show in menu |
| `filters_panel` | varchar(16) | Filter panel config |
| `height` | smallint | Table height |
| `users_groups` | text | Access control |
| `sort_order` | int | Display order |
| `chart_type` | varchar(16) | Embedded chart type |
| `chart_position` | varchar(16) | Chart position relative to table |
| `chart_height` | smallint | Chart height in px |
| `colors` | text | Chart colors |
| `chart_types` | text | Available chart type options |
| `chart_show_labels` | tinyint | Show data labels |
| `chart_number_format` | varchar(6) | Number format |
| `chart_number_prefix` | varchar(16) | e.g. `$` |
| `chart_number_suffix` | varchar(16) | e.g. `%` |

Related tables: `app_ext_pivot_tables_fields`, `app_ext_pivot_tables_settings`

---

## 7. Item Pivot Tables (`app_ext_item_pivot_tables`)

Cross-entity pivot tables shown on individual item pages. Links data from one entity to another.

**PHP module:** `/crm/plugins/ext/modules/item_pivot_tables/`

Configuration includes:
- `entities_id` — The entity where the table appears
- `related_entities_id` — The entity to pull data from
- Position (left/right column)
- Calculation formulas (`app_ext_item_pivot_tables_calcs`)

---

## 8. Kanban Boards (`app_ext_kanban`)

Visual board grouped by a dropdown field (typically stages).

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `entities_id` | int | Entity |
| `name` | varchar(255) | Board name |
| `group_by_field` | int | Dropdown field to create columns |
| `exclude_choices` | text | Choice IDs to hide |
| `fields_in_listing` | text | Fields shown on cards |
| `sum_by_field` | text | Numeric field to sum per column |
| `width` | int | Card width |
| `filters_panel` | varchar(32) | Filter panel type |
| `rows_per_page` | smallint | Cards per column |
| `users_groups` | text | Access control |
| `assigned_to` | varchar(255) | User access |
| `is_active` | tinyint | Active toggle |
| `heading_template` | varchar(255) | Card heading template |
| `in_menu` | tinyint | Show in menu |

Our CRM has kanban boards for Jobs (entity 42), Appointments (entity 29), and Dispatch Requests (entity 52).

---

## 9. Report Designer / Report Pages (`app_ext_report_page`)

Full custom report builder with blocks. Can include entity tables, raw HTML, MySQL query results. **Extension feature.**

### Table: `app_ext_report_page`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `entities_id` | int | 0 = standalone, or entity-specific |
| `is_active` | tinyint | Active toggle |
| `in_dashboard` | tinyint | Show on dashboard |
| `name` | varchar(255) | Report name |
| `description` | longtext | Report description/HTML content |
| `type` | varchar(64) | Report type |
| `icon` / `icon_color` | varchar | Display icon |
| `use_editor` | tinyint | Use WYSIWYG editor |
| `save_filename` | varchar(255) | Export filename |
| `save_as` | varchar(16) | Export format |
| `button_title` / `button_position` / `button_color` / `button_icon` | | Button styling |
| `users_groups` | varchar(255) | Access control |
| `assigned_to` | varchar(255) | User access |
| `page_orientation` | varchar(16) | PDF orientation |
| `settings` | text | Additional settings |
| `css` | longtext | Custom CSS |
| `sort_order` | int | Display order |

### Table: `app_ext_report_page_blocks`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `parent_id` | int | Parent block |
| `report_id` | int FK | Links to report page |
| `block_type` | varchar(32) | Block type (entity_table, mysql_table, html, etc.) |
| `name` | varchar(64) | Block name |
| `field_id` | int | Related field |
| `settings` | text | Block configuration |
| `sort_order` | int | Display order |

**PHP module:** `/crm/plugins/ext/modules/report_page/`

---

## 10. Other Report Types

### Timeline Reports (`app_ext_timeline_reports`)
| Column | Type | Purpose |
|--------|------|---------|
| `entities_id` | int | Entity |
| `start_date` / `end_date` | int | Date field IDs |
| `heading_template` | varchar(64) | Item label template |
| `use_background` | int | Color field ID |

### Gantt Charts (`app_ext_ganttchart`)
Full project-style Gantt with dependencies (`app_ext_ganttchart_depends`), access control, and scheduling.

### Map Reports (`app_ext_map_reports`)
Plot entity records on a map using a GPS/address field.

### Pivot Map Reports (`app_ext_pivot_map_reports`)
Multi-entity map with multiple marker types.

---

## 11. Dashboard System

### Dashboard Pages (`app_dashboard_pages`)

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `created_by` | int | User who created the page |
| `sections_id` | int | Dashboard section |
| `type` | varchar(16) | Page type (e.g. `reports`) |
| `is_active` | tinyint | |
| `name` | varchar(255) | Page name |
| `icon` | varchar(64) | Page icon |
| `description` | text | HTML content |
| `color` | varchar(16) | |
| `users_fields` | text | |
| `users_groups` | text | Access control |
| `sort_order` | int | |

### Dashboard Page Sections (`app_dashboard_pages_sections`)

Container for dashboard pages. Grid layout.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `name` | varchar(255) | Section name |
| `grid` | tinyint | Grid columns |
| `sort_order` | smallint | |

### Report Groups (`app_reports_groups`)

Tabbed groupings that appear as dashboard tabs. Each tab can contain report sections.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `name` | varchar(255) | Tab name |
| `menu_icon` | varchar(64) | Tab icon |
| `icon_color` / `bg_color` | varchar(7) | Colors |
| `in_menu` | tinyint | Show in sidebar |
| `in_dashboard` | tinyint | Show as dashboard tab |
| `sort_order` | smallint | Tab order |
| `counters_list` | text | Counter report IDs to show |
| `reports_list` | text | Report IDs to show |
| `created_by` | int | Owner |
| `is_common` | tinyint | 0=personal, 1=shared |
| `users_groups` | text | Access for shared tabs |
| `assigned_to` | text | User access for shared tabs |

### Report Sections (`app_reports_sections`)

Two-column layout sections within Report Group tabs.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | |
| `created_by` | int | Owner |
| `count_columns` | tinyint | 1 or 2 columns |
| `reports_groups_id` | int FK | Parent group (0 = main dashboard) |
| `report_left` | varchar(64) | Left column content ID (e.g. `kanban4`, `graphicreport1`) |
| `report_right` | varchar(64) | Right column content (empty if 1-column) |
| `sort_order` | smallint | |

**Content ID format** for `report_left` / `report_right`:
- `standardN` — Standard report
- `commonN` — Common report
- `kanbanN` — Kanban board
- `graphicreportN` — Graphic report
- `funnelchartN` — Funnel chart
- `pivotreportsN` — Pivot report
- `pivot_tablesN` — Pivot table
- `calendarreportN` — Calendar
- `calendar_personal` — Personal calendar
- `calendar_public` — Public calendar
- `pivot_calendarsN` — Pivot calendar
- `resource_timelineN` — Resource timeline
- `report_pageN` — Report designer page

---

## 12. How-To Recipes

### Count of Jobs by Stage (Funnel Chart)

```sql
INSERT INTO app_ext_funnelchart (
    entities_id, name, type, in_menu,
    group_by_field, hide_zero_values, exclude_choices,
    sum_by_field, users_groups, colors
) VALUES (
    42, 'Jobs by Stage', 'bars', 1,
    362, 1, '', '', '0', ''
);
```

### Revenue by Month (Graphic Report)

Requires a numeric "Revenue" field on Jobs. Assuming field_371 is payment-related or you have a custom revenue field:

```sql
INSERT INTO app_ext_graphicreport (
    entities_id, name, xaxis, yaxis, allowed_groups,
    chart_type, period, show_totals, hide_zero
) VALUES (
    42, 'Monthly Revenue', 0, 'REVENUE_FIELD_ID',
    '0', 'column', 'monthly', 1, 0
);
```

### Add a Report to the Dashboard

**Method 1: Counter tile** (shows count + optional sums)
```sql
UPDATE app_reports SET
    in_dashboard_counter = 1,
    dashboard_counter_sort_order = 10
WHERE id = YOUR_REPORT_ID;
```

**Method 2: Full listing on dashboard**
```sql
UPDATE app_reports SET
    in_dashboard = 1,  -- 1=always, 2=hide if empty
    dashboard_sort_order = 10
WHERE id = YOUR_REPORT_ID;
```

**Method 3: Report Group tab with sections**
```sql
-- Create a report group tab
INSERT INTO app_reports_groups (
    name, menu_icon, icon_color, bg_color,
    in_menu, in_dashboard, sort_order,
    counters_list, reports_list,
    created_by, is_common, users_groups, assigned_to
) VALUES (
    'Analytics', 'fa-chart-bar', '#3498db', '',
    1, 1, 1,
    '', '',
    1, 0, '', ''
);

SET @group_id = LAST_INSERT_ID();

-- Add a section with a kanban on the left, chart on the right
INSERT INTO app_reports_sections (
    created_by, count_columns, reports_groups_id,
    report_left, report_right, sort_order
) VALUES (
    1, 2, @group_id,
    'kanban4', 'funnelchart1', 1
);
```

### Show Report in Left Sidebar Menu

```sql
UPDATE app_reports SET in_menu = 1 WHERE id = YOUR_REPORT_ID;
```

### Show Report in Header Bar

```sql
UPDATE app_reports SET in_header = 1, in_header_autoupdate = 1 WHERE id = YOUR_REPORT_ID;
```

---

## 13. Key PHP Files

| File | Purpose |
|------|---------|
| `crm/modules/reports/actions/reports.php` | Standard report CRUD (save, delete, copy) |
| `crm/modules/reports/actions/filters.php` | Filter CRUD for reports |
| `crm/modules/reports/actions/filters_options.php` | Dynamic filter UI by field type |
| `crm/modules/reports/views/form.php` | Report create/edit form |
| `crm/modules/reports/views/filters_form.php` | Filter add/edit form |
| `crm/modules/reports/views/view.php` | Report listing display |
| `crm/includes/classes/reports/reports.php` | Core reports class (copy, create defaults, add_filters_query) |
| `crm/includes/classes/reports/reports_counter.php` | Dashboard counter rendering |
| `crm/includes/classes/reports/reports_groups.php` | Dashboard tab rendering |
| `crm/includes/classes/reports/reports_sections.php` | Dashboard section rendering (2-column layout, content type picker) |
| `crm/plugins/ext/modules/graphicreport/` | Graphic report module |
| `crm/plugins/ext/modules/funnelchart/` | Funnel chart module |
| `crm/plugins/ext/modules/item_pivot_tables/` | Item pivot tables module |
| `crm/plugins/ext/modules/report_page/` | Report designer module |
| `crm/plugins/ext/modules/kanban/` | Kanban board module |

---

## 14. Gotchas

- **Counter sums require numeric fields**: Only `fieldtype_input_numeric`, `fieldtype_formula`, `fieldtype_js_formula`, `fieldtype_mysql_query`, and difference fields work for `in_dashboard_counter_fields` and `dashboard_counter_sum_by_field`.
- **Graphic reports need date fields**: The X-axis field must be a date type. Use `0` for the built-in `date_added`.
- **Funnel charts need dropdown fields**: `group_by_field` must point to a dropdown, radiobox, stages, or similar choice field.
- **Report Group sections use string IDs**: The `report_left`/`report_right` columns store composite strings like `kanban4` (not just numeric IDs).
- **Admin bypass**: Users with `group_id=0` bypass all `allowed_groups` / `users_groups` checks.
- **Parent reports chain**: When a standard report is created, `reports::auto_create_parent_reports()` creates linked parent-entity filter reports. Deleting a report cascades to these via `reports::delete_parent_reports()`.
- **`in_dashboard` values**: 0=no, 1=yes always, 2=yes but hide if empty. This is a select field, not a checkbox.
- **Filter `is_active`**: Filters can be toggled without deleting. Inactive filters (is_active=0) are ignored in queries but preserved for reuse.
