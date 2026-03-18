# Access Control, Users, Groups & Records Visibility

Rukovoditel v3.6.4 at `/var/www/ezlead-hq/crm/`

---

## 1. Users (Entity 1 — `app_entity_1`)

Users are stored as records in entity 1, just like any other entity. The table is `app_entity_1`.

### Key Columns

| Column | Field ID | Purpose |
|--------|----------|---------|
| `field_5` | 5 | Status (1=Active, 0=Inactive) — `fieldtype_user_status` |
| `field_6` | 6 | Access Group ID — `fieldtype_user_accessgroups` |
| `field_7` | 7 | First Name — `fieldtype_user_firstname` |
| `field_8` | 8 | Last Name — `fieldtype_user_lastname` |
| `field_9` | 9 | Email — `fieldtype_user_email` |
| `field_12` | 12 | Username — `fieldtype_user_username` |
| `field_13` | 13 | Language — `fieldtype_user_language` |
| `field_14` | 14 | Skin — `fieldtype_user_skin` |
| `field_201` | 201 | Last Login Date (Unix timestamp) — `fieldtype_user_last_login_date` |
| `field_481` | 481 | Business (entity link to 50) — custom field |
| `password` | — | Hashed password (direct column, not a field_N) |
| `multiple_access_groups` | — | Comma-separated extra group IDs (if user belongs to multiple groups) |

### Admin Detection

**`field_6 = 0` means Administrator.** This is the single most important access control fact. Admins bypass ALL access rules, visibility rules, and entity restrictions. Every access check in the codebase starts with:

```php
if ($app_user['group_id'] == 0) return true;  // or return ''
```

### Current Users

| ID | Username | Name | Group (field_6) | Business (field_481) |
|----|----------|------|-----------------|---------------------|
| 1 | sodjacksonville@gmail.com | Kyle Weerts | 0 (Admin) | — |
| 2 | test@example.com | John Smith | 6 (Buyers) | — |
| 3 | wayfakename@gmail.com | Kyle Weerts | 6 (Buyers) | — |
| 4 | kylewee | Kyle Weerts | 5 (Developer) | — |
| 5 | claude | Claude AI | 0 (Admin) | — |
| 7 | mrclaude | Claude Code | 0 (Admin) | — |
| 270 | kylewee3 | Kyle Weerts | 0 (Admin) | — |

---

## 2. Access Groups (`app_access_groups`)

Groups define permission tiers. Users are assigned to one group via `field_6`.

### Table Structure

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Group ID. **0 = Administrator** (not stored in this table — it's implicit) |
| `name` | varchar(255) | Display name |
| `is_default` | tinyint(1) | Default group for new users |
| `is_ldap_default` | tinyint(1) | Default group for LDAP users |
| `ldap_filter` | text | LDAP group filter |
| `sort_order` | int | Display order |
| `notes` | text | Description |

### Current Groups

| ID | Name | Default? | Notes |
|----|------|----------|-------|
| 0 | Administrator | — | Implicit. Full access to everything. Not a row in this table. |
| 4 | Manager | Yes (default) | — |
| 5 | Developer | No | — |
| 6 | Buyers | No | Lead buyers - contractors who purchase leads |

### How to Create a New Group via MySQL

```sql
INSERT INTO app_access_groups (name, is_default, is_ldap_default, ldap_filter, sort_order, notes)
VALUES ('Technician', 0, 0, '', 3, 'Mobile mechanic technicians');
-- Note the new ID (e.g., 7), then set entity access (see section 3)
```

After creating the group, you MUST also add rows to `app_entities_access` for every entity the group should see, otherwise the group has zero access to any entities.

---

## 3. Entity Access (`app_entities_access`)

Controls which CRUD operations each group can perform on each entity.

### Table Structure

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Auto-increment |
| `entities_id` | int | Which entity |
| `access_groups_id` | int | Which access group |
| `access_schema` | text | Comma-separated permissions |

### Permission Values

| Permission | Meaning |
|------------|---------|
| `view` | Can see all records in listing |
| `view_assigned` | Can only see records assigned to them (via user fields) |
| `create` | Can create new records |
| `update` | Can edit records |
| `delete` | Can delete records |
| `reports` | Can use reports/filters |
| `export` | Can export records |
| `action_with_assigned` | Can only act on assigned records |
| `update_creator` | Can only update records they created |
| `delete_creator` | Can only delete records they created |

### Current Entity Access Map

**Buyers (group 6) — Restricted:**

| Entity | Access |
|--------|--------|
| 21 Projects | `view_assigned` |
| 22 Tasks | (none) |
| 23 Tickets | `view_assigned,create,update,reports` |
| 24 Discussions | (none) |
| 25 Leads | `view` |
| 26 Buyers | `view,update` |
| 27 Transactions | `view` |
| 28 Sources | (none) |
| 29 Appointments | `view,create,update,delete,reports,export` |
| 49 Diagnostics | `view` |
| 50 Businesses | `view` |
| 53 Estimates | `view` |

**Developer (group 5) — Near-full:**

| Entity | Access |
|--------|--------|
| 21 Projects | `view,create,update,delete,reports,export` |
| 22 Tasks | `view,create,update,reports` |
| 24 Discussions | `view_assigned,create,update,delete,reports` |
| 25 Leads | `view,create,update,delete,reports,export` |
| 29 Appointments | `view,create,update,delete,reports,export` |
| 30 Sessions | `view,create,update,delete,reports,export` |
| 35 Insights | `view,create,update,delete,reports,export` |
| 36 Actions | `view,create,update,delete,reports,export` |
| 37 Websites | `view,create,update,delete,reports,export` |
| 42 Mechanic Jobs | `view,create,update,delete,reports,export` |
| 47 Customers | `view,create,update,delete,reports,export` |
| 48 Vehicles | `view,create,update,delete,reports,export` |
| 49 Diagnostics | `view,create,update,delete,reports,export` |
| 50 Businesses | `view,create,update,delete,reports,export` |
| 51 Conversations | `view,create,update,delete,reports,export` |
| 53 Estimates | `view,create,update,delete,reports,export` |

**Manager (group 4) — Full (minus export on some):**

Same as Developer but also has access to Sessions (30), Insights (35), Actions (36), plus `export` on some entities Developer doesn't.

### How to Grant Entity Access via MySQL

```sql
-- Give group 7 (Technician) read-only access to Mechanic Jobs
INSERT INTO app_entities_access (entities_id, access_groups_id, access_schema)
VALUES (42, 7, 'view,reports');

-- Give full CRUD
INSERT INTO app_entities_access (entities_id, access_groups_id, access_schema)
VALUES (42, 7, 'view,create,update,delete,reports,export');

-- Restrict to only assigned records
INSERT INTO app_entities_access (entities_id, access_groups_id, access_schema)
VALUES (42, 7, 'view_assigned,update,reports');
```

---

## 4. Field-Level Access (`app_fields_access`)

Controls per-field permissions for each group within each entity. Makes fields read-only or hidden for specific groups.

### Table Structure

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Auto-increment |
| `access_groups_id` | int | Which access group |
| `entities_id` | int | Which entity |
| `fields_id` | int | Which field |
| `access_schema` | varchar(64) | Permission: `view_only`, `hide`, or empty (full access) |

Currently **empty** — no field-level restrictions are configured.

### How to Make a Field Read-Only for a Group

```sql
-- Make field_371 (Payment Status) read-only for Buyers (group 6) on entity 42 (Jobs)
INSERT INTO app_fields_access (access_groups_id, entities_id, fields_id, access_schema)
VALUES (6, 42, 371, 'view_only');

-- Hide a field entirely from a group
INSERT INTO app_fields_access (access_groups_id, entities_id, fields_id, access_schema)
VALUES (6, 42, 370, 'hide');
```

### How Field Access Is Checked

The `users::get_fields_access_schema()` method queries this table. The PHP code checks:
- `view_only` — field renders but is not editable
- `hide` — field is not rendered at all
- Empty/no row — full access (can view and edit)

---

## 5. Comments Access (`app_comments_access`)

Controls which groups can view/create/update/delete comments on each entity.

### Table Structure

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Auto-increment |
| `entities_id` | int | Which entity |
| `access_groups_id` | int | Which group |
| `access_schema` | varchar(64) | Comma-separated: `view,create,update,delete` |

### Current Comments Access

| Entity | Group | Access |
|--------|-------|--------|
| 21 Projects | 6 (Buyers) | `view,create` |
| 21 Projects | 5 (Developer) | `view,create` |
| 21 Projects | 4 (Manager) | `view,create,update,delete` |
| 22 Tasks | 5 (Developer) | `view,create` |
| 22 Tasks | 4 (Manager) | `view,create,update,delete` |
| 23 Tickets | 6 (Buyers) | `view,create` |
| 23 Tickets | 4 (Manager) | `view,create,update,delete` |
| 24 Discussions | 5 (Developer) | `view,create` |
| 24 Discussions | 4 (Manager) | `view,create,update,delete` |

---

## 6. Records Visibility Rules (`app_records_visibility_rules`)

The most powerful access control mechanism. Filters which records a user can see based on field value matching between the user's profile and the entity's records.

### Table Structure

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Auto-increment |
| `entities_id` | int | Which entity this rule applies to |
| `is_active` | tinyint(1) | 1=enabled, 0=disabled |
| `users_groups` | text | Comma-separated group IDs this rule applies to |
| `merged_fields` | text | Field matching pairs: `user_field_id-entity_field_id` |
| `merged_fields_empty_values` | text | Field IDs where empty values are also shown |
| `notes` | text | Description |
| `mysql_query` | text | Custom SQL WHERE fragment (supports `[current_user_id]` and `[current_user_group_id]` placeholders) |
| `php_code` | text | Custom PHP code (sets `$output_value` as SQL fragment) |

### Current Rules — Multi-Business Filtering

All rules apply to groups 4 (Manager) and 5 (Developer). Admins (group_id=0) are always exempt.

| Rule ID | Entity | merged_fields | Notes |
|---------|--------|---------------|-------|
| 3 | 25 Leads | `481-474` | User's Business (481) must match Lead's Business (474) |
| 4 | 42 Mechanic Jobs | `481-475` | User's Business (481) must match Job's Business (475) |
| 5 | 37 Websites | `481-476` | User's Business (481) must match Website's Business (476) |
| 6 | 26 Buyers | `481-477` | User's Business (481) must match Buyer's Business (477) |
| 7 | 47 Customers | `481-478` | User's Business (481) must match Customer's Business (478) |
| 8 | 29 Appointments | `481-479` | User's Business (481) must match Appointment's Business (479) |
| 9 | 21 Projects | `481-480` | User's Business (481) must match Project's Business (480) |

### How merged_fields Works

The format is `user_field_id-entity_field_id`. The system:
1. Gets the current user's value for `field_{user_field_id}` from `app_entity_1`
2. Checks if the entity record's `field_{entity_field_id}` matches that value
3. Uses a subquery on `app_entity_{N}_values` to check multi-value fields

For the business rules above: User field 481 = Business link. If a Manager user has `field_481 = 2` (Ez Mobile Mechanic), they only see records where the entity's Business field also = 2.

### Special: `current_user` merged fields

If `user_field_id` is the literal string `current_user`, it matches the current user's ID against user/created_by fields on the entity. Supports field types:
- `fieldtype_created_by` — matches `e.created_by = user_id`
- `fieldtype_access_group` — matches group ID
- `fieldtype_users` / `fieldtype_users_ajax` — matches user ID
- `fieldtype_grouped_users` — matches via choice/user association
- `fieldtype_entity` / `fieldtype_entity_ajax` — recursive entity access check

### How Rules Combine

Multiple rules for the same entity/group are OR'd together:
```php
return " and ((" . implode(') or (', $sql) . "))";
```

This means if ANY rule matches, the record is visible.

### How to Add a New Visibility Rule

```sql
-- Make Buyers (group 6) only see Jobs assigned to their Business
INSERT INTO app_records_visibility_rules
(entities_id, is_active, users_groups, merged_fields, merged_fields_empty_values, notes, mysql_query, php_code)
VALUES (42, 1, '6', '481-475', '', 'Buyers see only their business jobs', '', '');
```

---

## 7. Claude Business Filter (Custom Plugin Layer)

Defined in `/var/www/ezlead-hq/crm/plugins/claude/application_top.php`. Provides an additional filtering layer on top of records visibility rules.

### How It Works

1. User selects a business from a sidebar dropdown, stored in `crm_biz` cookie
2. `claude_business_filter($entity_id)` returns a SQL WHERE fragment: `and e.field_{N} = {selected_biz}`
3. Called from `items::add_access_query()` AFTER records_visibility rules

### Key Difference from Records Visibility

| Feature | Records Visibility | Claude Business Filter |
|---------|-------------------|----------------------|
| Scope | Per-group (server-enforced) | Per-session (cookie-based) |
| Admin bypass | Yes (group_id=0 skips) | No (applies to everyone including admins) |
| Purpose | Security: prevent unauthorized access | Convenience: narrow view to one business |
| Data source | User's `field_481` value | `crm_biz` cookie |

### Entity-to-Business-Field Map

```php
CLAUDE_BIZ_FIELDS = [
    21 => 480,  // Projects
    25 => 474,  // Leads
    26 => 477,  // Buyers
    29 => 479,  // Appointments
    30 => 501,  // Sessions
    36 => 496,  // Actions
    37 => 476,  // Websites
    42 => 475,  // Jobs
    47 => 478,  // Customers
    48 => 549,  // Vehicles
    53 => 521,  // Estimates
    54 => 537,  // Credit Accounts
];
```

### Integration Point

In `/var/www/ezlead-hq/crm/includes/classes/items/items.php` at line ~1344:
```php
if (function_exists('claude_business_filter')) {
    $listing_sql_query .= claude_business_filter($current_entity_id);
}
```

---

## 8. Access Query Execution Order

When a listing query runs, access filters are applied in this order in `items::add_access_query()`:

1. **Entity access check** — `users::get_entities_access_schema()` reads `app_entities_access` for the user's group
2. **User entity tree check** — If entity is a child of Users (entity 1), restrict to user's own subtree
3. **view_assigned check** — If group has `view_assigned` (not `view`), add WHERE clause matching user fields
4. **Records visibility rules** — `records_visibility::add_access_query()` applies `app_records_visibility_rules`
5. **Claude business filter** — `claude_business_filter()` adds cookie-based business filtering

Admin users (group_id=0) bypass steps 1-4 entirely. Step 5 still applies.

---

## 9. Login Security

### Login Attempts (`app_login_attempt`)

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Auto-increment |
| `user_ip` | varchar(64) | IP address |
| `count_attempt` | smallint | Failed attempt count |
| `is_banned` | tinyint(1) | 1=IP is banned |
| `date_banned` | bigint(20) | Unix timestamp of ban |

**Currently disabled.** `CFG_ENABLE_LOGIN_ATTEMPT = 0`. When enabled, tracks failed login attempts per IP and can auto-ban after threshold.

### Login Log (`app_users_login_log`)

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK | Auto-increment |
| `users_id` | int | User ID |
| `username` | varchar(255) | Username used |
| `identifier` | varchar(255) | IP address |
| `is_success` | tinyint(1) | 1=successful login |
| `date_added` | bigint(20) | Unix timestamp |

Records every login attempt (successful and failed).

### Password Config

- `CFG_PASSWORD_MIN_LENGTH = 5`

---

## 10. Other Access Tables (Reference)

### `app_access_rules`

Per-field access rules with choice-level filtering. Allows restricting which dropdown choices a group can see.

| Column | Type | Purpose |
|--------|------|---------|
| `entities_id` | int | Entity |
| `fields_id` | int | Field |
| `choices` | text | Which dropdown choices to show |
| `users_groups` | text | Which groups this applies to |
| `access_schema` | text | Access level |
| `fields_view_only_access` | text | Fields to make read-only when this choice is selected |
| `comments_access_schema` | varchar(64) | Comments access when this choice applies |

Currently **empty**.

### `app_access_rules_fields`

Companion to `app_access_rules`. Links entity+field pairs for dynamic field access rules.

Currently **empty**.

### `app_user_roles`

Defines named roles within entities (not access groups). Used for role-based field visibility.

| Column | Type | Purpose |
|--------|------|---------|
| `entities_id` | int | Entity |
| `fields_id` | int | User field that determines role |
| `name` | varchar(255) | Role name |
| `sort_order` | int | Display order |

Currently **empty**.

### `app_user_roles_access`

Per-role access overrides for entities.

| Column | Type | Purpose |
|--------|------|---------|
| `user_roles_id` | int | Role ID from `app_user_roles` |
| `fields_id` | int | Field |
| `entities_id` | int | Entity |
| `access_schema` | varchar(255) | Override permissions |
| `comments_access` | varchar(64) | Comments override |
| `fields_access` | text | Per-field overrides |

Currently **empty**.

---

## 11. Quick Reference: Common Tasks

### Create a new limited-access group

```sql
-- 1. Create the group
INSERT INTO app_access_groups (name, is_default, is_ldap_default, ldap_filter, sort_order, notes)
VALUES ('Technician', 0, 0, '', 3, 'Field technicians');
-- Get the new ID: SELECT LAST_INSERT_ID();  (e.g., 7)

-- 2. Grant entity access (repeat per entity)
INSERT INTO app_entities_access (entities_id, access_groups_id, access_schema)
VALUES
(42, 7, 'view_assigned,update,reports'),  -- Jobs: see assigned, can update
(47, 7, 'view,reports'),                   -- Customers: read-only
(48, 7, 'view,reports'),                   -- Vehicles: read-only
(49, 7, 'view,create,update,reports');     -- Diagnostics: can create/edit

-- 3. Add comments access
INSERT INTO app_comments_access (entities_id, access_groups_id, access_schema)
VALUES (42, 7, 'view,create');

-- 4. Add business visibility rule
INSERT INTO app_records_visibility_rules
(entities_id, is_active, users_groups, merged_fields, merged_fields_empty_values, notes, mysql_query, php_code)
VALUES (42, 1, '7', '481-475', '', 'Techs see only their business jobs', '', '');

-- 5. Make specific fields read-only
INSERT INTO app_fields_access (access_groups_id, entities_id, fields_id, access_schema)
VALUES
(7, 42, 371, 'view_only'),  -- Payment Status: read-only
(7, 42, 366, 'view_only'),  -- Total: read-only
(7, 42, 370, 'hide');        -- Payment Link: hidden
```

### Make a field read-only for a group

```sql
INSERT INTO app_fields_access (access_groups_id, entities_id, fields_id, access_schema)
VALUES ({group_id}, {entity_id}, {field_id}, 'view_only');
```

### Hide a field from a group

```sql
INSERT INTO app_fields_access (access_groups_id, entities_id, fields_id, access_schema)
VALUES ({group_id}, {entity_id}, {field_id}, 'hide');
```

### Check a user's effective permissions for an entity

```sql
-- Get user's group
SELECT field_6 as group_id FROM app_entity_1 WHERE id = {user_id};

-- Get entity access for that group
SELECT access_schema FROM app_entities_access
WHERE entities_id = {entity_id} AND access_groups_id = {group_id};

-- Get field-level restrictions
SELECT fields_id, access_schema FROM app_fields_access
WHERE entities_id = {entity_id} AND access_groups_id = {group_id};

-- Get visibility rules
SELECT * FROM app_records_visibility_rules
WHERE entities_id = {entity_id} AND is_active = 1
AND FIND_IN_SET({group_id}, users_groups);
```

---

## 12. Source Files

| File | Purpose |
|------|---------|
| `crm/includes/classes/users/users.php` | `has_access()`, `get_entities_access_schema()`, `get_fields_access_schema()` |
| `crm/includes/classes/users/records_visibility.php` | `add_access_query()` for visibility rules |
| `crm/includes/classes/items/items.php` | `add_access_query()` — orchestrates all access layers |
| `crm/plugins/claude/application_top.php` | `claude_business_filter()` — cookie-based business filtering |
