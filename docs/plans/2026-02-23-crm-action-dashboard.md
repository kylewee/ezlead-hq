# CRM Action Dashboard Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the default Rukovoditel CRM homepage with a "Today" action feed that shows everything needing attention sorted by urgency, and simplify the sidebar to 5 core items.

**Architecture:** PHP dashboard override via the existing Claude plugin. The plugin's `includes/dashboard.php` replaces the default. A new `sidebar.php` replaces the default menu. An AJAX endpoint handles quick actions (mark done, advance stage). All data comes from direct MySQL queries against the existing CRM entities.

**Tech Stack:** PHP 8.x, MySQL, vanilla JavaScript (no frameworks — matches existing CRM), inline CSS.

**Key Discovery:** The existing `plugins/claude/dashboard.php` is in the WRONG location. The system loads from `plugins/claude/includes/dashboard.php`. The `includes/` directory doesn't exist. No `sidebar.php` exists either. So the default Rukovoditel UI has been showing this whole time.

---

## Database Reference

### Entities & Fields
- **Entity 42 (Mechanic Jobs):** field_354=name, field_355=phone, field_359=make, field_360=model, field_361=problem, field_362=stage (dropdown IDs below), field_366=total, field_368=appointment (unix ts)
- **Entity 25 (Leads):** field_210=name, field_211=phone, field_215=source, field_218=status (text), date_added=datetime
- **Entity 36 (Tasks):** field_328=task, field_329=priority (text: High/Medium/Low), field_330=done (checkbox, empty=not done), field_332=due date (bigint unix, 0=none), date_added=bigint unix
- **Entity 29 (Appointments):** field_255=title, field_257=datetime (int unix), field_258=location, field_260=confirmed, date_added=int unix

### Mechanic Job Stage IDs (field 362)
82=New Lead, 83=Estimate Sent, 84=Accepted, 85=Scheduled, 86=Parts Ordered, 87=Confirmed, 88=In Progress, 89=Complete, 90=Paid, 95=Follow Up, 96=Review Request

### Database Connection
```php
require_once(__DIR__ . '/../../config/database.php');
$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
```

### CRM URL Patterns
- Entity list: `index.php?module=items/items&path={entity_id}`
- Edit record: `index.php?module=items/item&path={entity_id}&id={record_id}`
- New record: `index.php?module=items/items&path={entity_id}&action=new`
- iPage: `index.php?module=ext/ipages/view&id={ipage_id}`

---

## Task 1: Create includes directory and back up old dashboard

**Files:**
- Create directory: `plugins/claude/includes/`
- Move: `plugins/claude/dashboard.php` → `plugins/claude/dashboard.php.bak`

**Step 1: Create directory and back up**

```bash
cd /var/www/ezlead-hq/crm
sudo mkdir -p plugins/claude/includes
sudo cp plugins/claude/dashboard.php plugins/claude/dashboard.php.bak
sudo chown -R www-data:www-data plugins/claude/includes
```

**Step 2: Verify**

```bash
ls -la plugins/claude/includes/
ls -la plugins/claude/dashboard.php.bak
```

Expected: Empty includes directory exists, backup file exists.

**Step 3: Commit**

Not a git repo — skip commits. Backups serve as safety net.

---

## Task 2: Build the Action Feed Dashboard

**Files:**
- Create: `plugins/claude/includes/dashboard.php`

This is the core deliverable. One PHP file that:
1. Queries all entities for items needing attention
2. Scores each item by urgency
3. Renders a single action feed sorted by urgency
4. Groups items into 4 sections: Overdue, Due Today, New, Upcoming

**Step 1: Write the dashboard PHP**

Create `/var/www/ezlead-hq/crm/plugins/claude/includes/dashboard.php` with this content:

```php
<?php
/**
 * "Today" Action Feed Dashboard
 * Replaces default Rukovoditel dashboard.
 * Shows everything needing attention, sorted by urgency.
 */

require_once(__DIR__ . '/../../../config/database.php');

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    die("Connection failed");
}

$now = time();
$today_start = strtotime('today midnight');
$today_end = $today_start + 86400;
$week_end = $now + (7 * 86400);
$two_days_ago = $now - (2 * 86400);
$three_days_ago = $now - (3 * 86400);

// Stage name/color lookup
$stage_names = [
    82 => 'New Lead', 83 => 'Estimate Sent', 84 => 'Accepted',
    85 => 'Scheduled', 86 => 'Parts Ordered', 87 => 'Confirmed',
    88 => 'In Progress', 89 => 'Complete', 90 => 'Paid',
    95 => 'Follow Up', 96 => 'Review Request'
];

// Collect all action items into one array
$items = [];

// ============================================================
// MECHANIC JOBS (entity 42) - check for stale/actionable jobs
// ============================================================
$result = $conn->query("
    SELECT id, field_354 as name, field_355 as phone, field_359 as make,
           field_360 as model, field_361 as problem, field_362 as stage,
           field_366 as total, field_368 as appt_time,
           date_added, date_updated
    FROM app_entity_42
    WHERE field_362 NOT IN (90, 95, 96)
    ORDER BY date_updated DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $stage = (int)$row['stage'];
        $updated = (int)$row['date_updated'];
        $age_hours = ($now - $updated) / 3600;
        $vehicle = trim($row['make'] . ' ' . $row['model']);
        $appt = (int)$row['appt_time'];

        // Determine urgency and action based on stage + time
        $urgency = 0;
        $section = '';
        $action_label = '';
        $action_url = 'index.php?module=items/item&path=42&id=' . $row['id'];

        switch ($stage) {
            case 82: // New Lead
                if ($age_hours > 24) {
                    $urgency = 90;
                    $section = 'overdue';
                    $action_label = 'Send Estimate';
                } else {
                    $urgency = 50;
                    $section = 'new';
                    $action_label = 'Send Estimate';
                }
                break;
            case 83: // Estimate Sent
                if ($age_hours > 72) {
                    $urgency = 80;
                    $section = 'overdue';
                    $action_label = 'Follow Up';
                } else {
                    $urgency = 20;
                    $section = 'upcoming';
                    $action_label = 'Waiting for Response';
                }
                break;
            case 84: // Accepted
                $urgency = 60;
                $section = 'today';
                $action_label = 'Schedule Appointment';
                break;
            case 85: // Scheduled
            case 86: // Parts Ordered
            case 87: // Confirmed
                if ($appt > 0 && $appt >= $today_start && $appt < $today_end) {
                    $urgency = 70;
                    $section = 'today';
                    $action_label = 'Job Today';
                } elseif ($appt > 0 && $appt < $now) {
                    $urgency = 85;
                    $section = 'overdue';
                    $action_label = 'Past Appointment';
                } elseif ($appt > 0 && $appt < $week_end) {
                    $urgency = 15;
                    $section = 'upcoming';
                    $action_label = date('D M j', $appt);
                } else {
                    $urgency = 10;
                    $section = 'upcoming';
                    $action_label = $stage_names[$stage];
                }
                break;
            case 88: // In Progress
                $urgency = 65;
                $section = 'today';
                $action_label = 'In Progress';
                break;
            case 89: // Complete
                if ($age_hours > 24) {
                    $urgency = 85;
                    $section = 'overdue';
                    $action_label = 'Send Invoice';
                } else {
                    $urgency = 60;
                    $section = 'today';
                    $action_label = 'Send Invoice';
                }
                break;
        }

        if ($section) {
            $items[] = [
                'type' => 'job',
                'icon' => 'fa-wrench',
                'icon_color' => '#e67e22',
                'label' => $stage_names[$stage] ?? 'Job',
                'title' => $row['name'] ?: 'Unknown',
                'subtitle' => $vehicle . ($row['problem'] ? ' - ' . substr($row['problem'], 0, 60) : ''),
                'phone' => $row['phone'],
                'time' => $updated,
                'appt_time' => $appt,
                'urgency' => $urgency,
                'section' => $section,
                'action_label' => $action_label,
                'action_url' => $action_url,
                'entity_id' => 42,
                'record_id' => $row['id'],
                'total' => $row['total'],
            ];
        }
    }
}

// ============================================================
// FOLLOW-UP & REVIEW REQUEST JOBS (stages 90, 95, 96)
// Only show if recently changed (within 7 days)
// ============================================================
$result = $conn->query("
    SELECT id, field_354 as name, field_355 as phone, field_359 as make,
           field_360 as model, field_362 as stage, field_366 as total,
           date_updated
    FROM app_entity_42
    WHERE field_362 IN (90, 95, 96)
      AND date_updated >= " . ($now - 7 * 86400) . "
    ORDER BY date_updated DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $stage = (int)$row['stage'];
        $updated = (int)$row['date_updated'];
        $age_hours = ($now - $updated) / 3600;
        $vehicle = trim($row['make'] . ' ' . $row['model']);

        $urgency = 0;
        $section = '';
        $action_label = '';

        if ($stage == 90) { // Paid - needs follow-up after 3 days
            if ($age_hours > 72) {
                $urgency = 40;
                $section = 'today';
                $action_label = 'Send Follow-up';
            }
        } elseif ($stage == 95) { // Follow Up - needs review request after 2 days
            if ($age_hours > 48) {
                $urgency = 35;
                $section = 'today';
                $action_label = 'Request Review';
            }
        }
        // Stage 96 (Review Request) = done, skip

        if ($section) {
            $items[] = [
                'type' => 'job',
                'icon' => 'fa-wrench',
                'icon_color' => '#e67e22',
                'label' => $stage_names[$stage] ?? 'Job',
                'title' => $row['name'] ?: 'Unknown',
                'subtitle' => $vehicle,
                'phone' => $row['phone'],
                'time' => $updated,
                'appt_time' => 0,
                'urgency' => $urgency,
                'section' => $section,
                'action_label' => $action_label,
                'action_url' => 'index.php?module=items/item&path=42&id=' . $row['id'],
                'entity_id' => 42,
                'record_id' => $row['id'],
                'total' => $row['total'],
            ];
        }
    }
}

// ============================================================
// LEADS (entity 25) - new/unhandled leads
// ============================================================
$result = $conn->query("
    SELECT id, field_210 as name, field_211 as phone, field_215 as source,
           field_218 as status, date_added
    FROM app_entity_25
    WHERE field_218 IS NULL OR field_218 = '' OR field_218 = 'New'
    ORDER BY date_added DESC
    LIMIT 20
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $added = strtotime($row['date_added']); // datetime column
        $age_hours = ($now - $added) / 3600;

        if ($age_hours > 48) {
            $urgency = 80;
            $section = 'overdue';
        } elseif ($age_hours > 24) {
            $urgency = 60;
            $section = 'today';
        } else {
            $urgency = 50;
            $section = 'new';
        }

        $items[] = [
            'type' => 'lead',
            'icon' => 'fa-user-plus',
            'icon_color' => '#3498db',
            'label' => 'New Lead',
            'title' => $row['name'] ?: 'Unknown',
            'subtitle' => $row['source'] ? 'From ' . $row['source'] : 'Unknown source',
            'phone' => $row['phone'],
            'time' => $added,
            'appt_time' => 0,
            'urgency' => $urgency,
            'section' => $section,
            'action_label' => 'Respond',
            'action_url' => 'index.php?module=items/item&path=25&id=' . $row['id'],
            'entity_id' => 25,
            'record_id' => $row['id'],
            'total' => null,
        ];
    }
}

// ============================================================
// TASKS (entity 36) - overdue and due today
// ============================================================
$result = $conn->query("
    SELECT id, field_328 as task, field_329 as priority,
           field_330 as done, field_332 as due_date, date_added
    FROM app_entity_36
    WHERE field_330 = '' OR field_330 IS NULL OR field_330 = '0'
    ORDER BY date_added DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $due = (int)$row['due_date'];
        $added = (int)$row['date_added'];
        $priority = $row['priority'] ?: 'Medium';

        if ($due > 0 && $due < $today_start) {
            $urgency = $priority === 'High' ? 95 : 75;
            $section = 'overdue';
        } elseif ($due > 0 && $due >= $today_start && $due < $today_end) {
            $urgency = $priority === 'High' ? 65 : 55;
            $section = 'today';
        } elseif ($due > 0 && $due < $week_end) {
            $urgency = 15;
            $section = 'upcoming';
        } else {
            // No due date — show as today if High priority, upcoming otherwise
            $urgency = $priority === 'High' ? 45 : 10;
            $section = $priority === 'High' ? 'today' : 'upcoming';
        }

        $items[] = [
            'type' => 'task',
            'icon' => 'fa-check-square-o',
            'icon_color' => '#9b59b6',
            'label' => $priority . ' Task',
            'title' => $row['task'] ?: 'Untitled Task',
            'subtitle' => $due > 0 ? 'Due: ' . date('M j', $due) : 'No due date',
            'phone' => null,
            'time' => $added,
            'appt_time' => 0,
            'urgency' => $urgency,
            'section' => $section,
            'action_label' => 'Mark Done',
            'action_url' => 'index.php?module=items/item&path=36&id=' . $row['id'],
            'entity_id' => 36,
            'record_id' => $row['id'],
            'total' => null,
            'ajax_action' => 'mark_done',
        ];
    }
}

// ============================================================
// APPOINTMENTS (entity 29) - today and upcoming
// ============================================================
$result = $conn->query("
    SELECT id, field_255 as title, field_257 as appt_time,
           field_258 as location, field_260 as confirmed
    FROM app_entity_29
    WHERE field_257 >= $today_start
    ORDER BY field_257 ASC
    LIMIT 20
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $appt = (int)$row['appt_time'];

        if ($appt >= $today_start && $appt < $today_end) {
            $urgency = 70;
            $section = 'today';
        } elseif ($appt < $week_end) {
            $urgency = 15;
            $section = 'upcoming';
        } else {
            continue; // Beyond this week, skip
        }

        $items[] = [
            'type' => 'appointment',
            'icon' => 'fa-calendar',
            'icon_color' => '#27ae60',
            'label' => 'Appointment',
            'title' => $row['title'] ?: 'Appointment',
            'subtitle' => $row['location'] ? $row['location'] : date('g:i A', $appt),
            'phone' => null,
            'time' => $appt,
            'appt_time' => $appt,
            'urgency' => $urgency,
            'section' => $section,
            'action_label' => date('g:i A', $appt),
            'action_url' => 'index.php?module=items/item&path=29&id=' . $row['id'],
            'entity_id' => 29,
            'record_id' => $row['id'],
            'total' => null,
        ];
    }
}

// Also show past appointments from today that haven't been handled
$result = $conn->query("
    SELECT id, field_255 as title, field_257 as appt_time,
           field_258 as location
    FROM app_entity_29
    WHERE field_257 >= $today_start AND field_257 < $now
    ORDER BY field_257 ASC
");
// These are already captured above if within today range

$conn->close();

// ============================================================
// Sort items: group by section, then by urgency descending
// ============================================================
$sections = [
    'overdue' => ['label' => 'OVERDUE', 'color' => '#e74c3c', 'items' => []],
    'today'   => ['label' => 'DUE TODAY', 'color' => '#f39c12', 'items' => []],
    'new'     => ['label' => 'NEW', 'color' => '#3498db', 'items' => []],
    'upcoming'=> ['label' => 'UPCOMING THIS WEEK', 'color' => '#95a5a6', 'items' => []],
];

foreach ($items as $item) {
    $sections[$item['section']]['items'][] = $item;
}

// Sort each section by urgency descending
foreach ($sections as &$sec) {
    usort($sec['items'], fn($a, $b) => $b['urgency'] - $a['urgency']);
}
unset($sec);

// Helper: relative time
function time_ago($timestamp) {
    $diff = time() - $timestamp;
    if ($diff < 0) {
        $diff = abs($diff);
        if ($diff < 3600) return 'in ' . ceil($diff / 60) . 'min';
        if ($diff < 86400) return 'in ' . ceil($diff / 3600) . 'hr';
        return 'in ' . ceil($diff / 86400) . 'd';
    }
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'min ago';
    if ($diff < 86400) return floor($diff / 3600) . 'hr ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $timestamp);
}

$total_items = array_sum(array_map(fn($s) => count($s['items']), $sections));
?>

<style>
.action-feed { padding: 15px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.feed-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.feed-header h2 { margin: 0; font-size: 22px; color: #333; }
.feed-header .date { color: #888; font-size: 14px; }
.feed-summary { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.feed-summary .count-badge { padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; color: white; }

.feed-section { margin-bottom: 24px; }
.feed-section-header { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; padding: 8px 12px; border-radius: 6px; }
.feed-section-header h3 { margin: 0; font-size: 13px; font-weight: 700; letter-spacing: 0.5px; color: white; }
.feed-section-header .section-count { background: rgba(255,255,255,0.3); padding: 2px 8px; border-radius: 10px; font-size: 11px; color: white; font-weight: 600; }

.feed-item { display: flex; align-items: center; gap: 12px; padding: 12px; margin-bottom: 6px; background: white; border-radius: 8px; border: 1px solid #eee; transition: border-color 0.15s; }
.feed-item:hover { border-color: #ccc; }
.feed-item-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 16px; flex-shrink: 0; }
.feed-item-content { flex: 1; min-width: 0; }
.feed-item-type { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #888; }
.feed-item-title { font-size: 14px; font-weight: 600; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.feed-item-sub { font-size: 12px; color: #888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.feed-item-time { font-size: 11px; color: #aaa; white-space: nowrap; text-align: right; min-width: 60px; }
.feed-item-actions { display: flex; gap: 6px; flex-shrink: 0; }
.feed-btn { padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; white-space: nowrap; }
.feed-btn-primary { background: #3498db; color: white; }
.feed-btn-primary:hover { background: #2980b9; color: white; text-decoration: none; }
.feed-btn-phone { background: #27ae60; color: white; }
.feed-btn-phone:hover { background: #219a52; color: white; text-decoration: none; }
.feed-btn-done { background: #95a5a6; color: white; }
.feed-btn-done:hover { background: #7f8c8d; color: white; text-decoration: none; }

.feed-empty { text-align: center; padding: 60px 20px; color: #aaa; }
.feed-empty i { font-size: 48px; margin-bottom: 10px; display: block; }
.feed-empty p { font-size: 16px; }

.quick-add { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.quick-add a { padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: 1px solid #ddd; color: #555; background: white; }
.quick-add a:hover { border-color: #3498db; color: #3498db; text-decoration: none; }
</style>

<div class="action-feed">
    <div class="feed-header">
        <h2>Today</h2>
        <span class="date"><?= date('l, F j') ?></span>
    </div>

    <!-- Summary badges -->
    <div class="feed-summary">
        <?php if (count($sections['overdue']['items'])): ?>
        <span class="count-badge" style="background:#e74c3c"><?= count($sections['overdue']['items']) ?> overdue</span>
        <?php endif; ?>
        <?php if (count($sections['today']['items'])): ?>
        <span class="count-badge" style="background:#f39c12"><?= count($sections['today']['items']) ?> due today</span>
        <?php endif; ?>
        <?php if (count($sections['new']['items'])): ?>
        <span class="count-badge" style="background:#3498db"><?= count($sections['new']['items']) ?> new</span>
        <?php endif; ?>
        <?php if (count($sections['upcoming']['items'])): ?>
        <span class="count-badge" style="background:#95a5a6"><?= count($sections['upcoming']['items']) ?> upcoming</span>
        <?php endif; ?>
    </div>

    <!-- Quick add buttons -->
    <div class="quick-add">
        <a href="index.php?module=items/items&path=42&action=new"><i class="fa fa-plus"></i> New Job</a>
        <a href="index.php?module=items/items&path=25&action=new"><i class="fa fa-plus"></i> New Lead</a>
        <a href="index.php?module=items/items&path=36&action=new"><i class="fa fa-plus"></i> New Task</a>
        <a href="index.php?module=items/items&path=29&action=new"><i class="fa fa-plus"></i> New Appointment</a>
    </div>

    <?php if ($total_items === 0): ?>
    <div class="feed-empty">
        <i class="fa fa-check-circle"></i>
        <p>Nothing needs attention right now.</p>
    </div>
    <?php endif; ?>

    <?php foreach ($sections as $key => $section):
        if (empty($section['items'])) continue;
    ?>
    <div class="feed-section">
        <div class="feed-section-header" style="background: <?= $section['color'] ?>">
            <h3><?= $section['label'] ?></h3>
            <span class="section-count"><?= count($section['items']) ?></span>
        </div>

        <?php foreach ($section['items'] as $item): ?>
        <div class="feed-item">
            <div class="feed-item-icon" style="background: <?= $item['icon_color'] ?>">
                <i class="fa <?= $item['icon'] ?>"></i>
            </div>
            <div class="feed-item-content">
                <div class="feed-item-type"><?= htmlspecialchars($item['label']) ?></div>
                <div class="feed-item-title"><?= htmlspecialchars($item['title']) ?></div>
                <div class="feed-item-sub"><?= htmlspecialchars($item['subtitle']) ?>
                    <?php if ($item['total']): ?> &mdash; $<?= number_format($item['total'], 0) ?><?php endif; ?>
                </div>
            </div>
            <div class="feed-item-time">
                <?= time_ago($item['time']) ?>
            </div>
            <div class="feed-item-actions">
                <?php if ($item['phone']): ?>
                <a href="tel:<?= htmlspecialchars($item['phone']) ?>" class="feed-btn feed-btn-phone" title="Call <?= htmlspecialchars($item['phone']) ?>"><i class="fa fa-phone"></i></a>
                <?php endif; ?>

                <?php if (!empty($item['ajax_action']) && $item['ajax_action'] === 'mark_done'): ?>
                <button class="feed-btn feed-btn-done" onclick="markDone(<?= $item['record_id'] ?>, this)"><i class="fa fa-check"></i> Done</button>
                <?php endif; ?>

                <a href="<?= $item['action_url'] ?>" class="feed-btn feed-btn-primary"><?= htmlspecialchars($item['action_label']) ?></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<script>
function markDone(recordId, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    fetch('plugins/claude/includes/ajax_action.php?action=mark_done&entity=36&id=' + recordId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.closest('.feed-item').style.opacity = '0.3';
                btn.closest('.feed-item').style.pointerEvents = 'none';
            } else {
                btn.innerHTML = 'Error';
            }
        })
        .catch(() => { btn.innerHTML = 'Error'; });
}
</script>
```

**Step 2: Verify it loads**

Open https://ezlead4u.com/crm/ in browser. The dashboard should now show the action feed instead of the default Rukovoditel dashboard. If the page is blank or shows an error, check PHP error log:

```bash
sudo tail -20 /var/log/php-fpm/error.log
# or
sudo tail -20 /var/log/caddy/access.log
```

---

## Task 3: Build the AJAX endpoint for quick actions

**Files:**
- Create: `plugins/claude/includes/ajax_action.php`

**Step 1: Write the AJAX endpoint**

Create `/var/www/ezlead-hq/crm/plugins/claude/includes/ajax_action.php`:

```php
<?php
/**
 * AJAX endpoint for quick actions from the Today dashboard.
 * Handles: mark_done (tasks), advance_stage (jobs)
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../../../config/database.php');

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed']);
    exit;
}

$action = $_GET['action'] ?? '';
$entity = (int)($_GET['entity'] ?? 0);
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing record ID']);
    exit;
}

$now = time();

switch ($action) {
    case 'mark_done':
        // Mark a task as done (entity 36, field_330)
        if ($entity !== 36) {
            echo json_encode(['success' => false, 'error' => 'Invalid entity for mark_done']);
            break;
        }
        $stmt = $conn->prepare("UPDATE app_entity_36 SET field_330 = '1', date_updated = ? WHERE id = ?");
        $stmt->bind_param('ii', $now, $id);
        $result = $stmt->execute();
        echo json_encode(['success' => $result]);
        $stmt->close();
        break;

    case 'advance_stage':
        // Move a mechanic job to the next stage (entity 42, field_362)
        if ($entity !== 42) {
            echo json_encode(['success' => false, 'error' => 'Invalid entity']);
            break;
        }
        $stage_order = [82, 83, 84, 85, 86, 87, 88, 89, 90, 95, 96];
        $current = $conn->query("SELECT field_362 FROM app_entity_42 WHERE id = $id")->fetch_assoc();
        if (!$current) {
            echo json_encode(['success' => false, 'error' => 'Record not found']);
            break;
        }
        $current_stage = (int)$current['field_362'];
        $current_idx = array_search($current_stage, $stage_order);
        if ($current_idx === false || $current_idx >= count($stage_order) - 1) {
            echo json_encode(['success' => false, 'error' => 'Cannot advance']);
            break;
        }
        $next_stage = $stage_order[$current_idx + 1];
        $stmt = $conn->prepare("UPDATE app_entity_42 SET field_362 = ?, date_updated = ? WHERE id = ?");
        $stmt->bind_param('iii', $next_stage, $now, $id);
        $result = $stmt->execute();
        echo json_encode(['success' => $result, 'new_stage' => $next_stage]);
        $stmt->close();
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

$conn->close();
```

**Step 2: Test the endpoint**

```bash
curl -s "https://ezlead4u.com/crm/plugins/claude/includes/ajax_action.php?action=mark_done&entity=36&id=1" | python3 -m json.tool
```

Expected: `{"success": true}` or `{"success": false, "error": "..."}`.

---

## Task 4: Build the simplified sidebar

**Files:**
- Create: `plugins/claude/sidebar.php`

This replaces the entire Rukovoditel sidebar with 5 core items plus a collapsed settings section.

**Step 1: Write the sidebar**

Create `/var/www/ezlead-hq/crm/plugins/claude/sidebar.php`:

```php
<?php
/**
 * Simplified CRM sidebar.
 * Replaces default Rukovoditel menu with 5 core items.
 */

// Count badges for sidebar
$badge_conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
$overdue_count = 0;
$new_lead_count = 0;
$task_count = 0;

if (!$badge_conn->connect_error) {
    $now = time();
    $today_start = strtotime('today midnight');

    // Overdue tasks
    $r = $badge_conn->query("SELECT COUNT(*) as c FROM app_entity_36 WHERE (field_330 = '' OR field_330 IS NULL OR field_330 = '0') AND field_332 > 0 AND field_332 < $today_start");
    if ($r) $overdue_count = (int)$r->fetch_assoc()['c'];

    // New leads
    $r = $badge_conn->query("SELECT COUNT(*) as c FROM app_entity_25 WHERE field_218 IS NULL OR field_218 = '' OR field_218 = 'New'");
    if ($r) $new_lead_count = (int)$r->fetch_assoc()['c'];

    // Open tasks
    $r = $badge_conn->query("SELECT COUNT(*) as c FROM app_entity_36 WHERE field_330 = '' OR field_330 IS NULL OR field_330 = '0'");
    if ($r) $task_count = (int)$r->fetch_assoc()['c'];

    $badge_conn->close();
}

// Determine active page
$current_module = $_GET['module'] ?? '';
$current_path = $_GET['path'] ?? '';

function sidebar_active($module, $path = '') {
    global $current_module, $current_path;
    if ($path && $current_path == $path) return 'active';
    if (!$path && ($current_module == $module || $current_module == '')) return 'active';
    return '';
}

$menu_items = [
    [
        'label' => 'Today',
        'icon' => 'fa-home',
        'url' => 'index.php?module=dashboard',
        'active' => sidebar_active('dashboard'),
        'badge' => $overdue_count > 0 ? $overdue_count : null,
        'badge_color' => '#e74c3c',
    ],
    [
        'label' => 'Jobs',
        'icon' => 'fa-wrench',
        'url' => 'index.php?module=items/items&path=42',
        'active' => sidebar_active('items/items', '42'),
        'badge' => null,
    ],
    [
        'label' => 'Leads',
        'icon' => 'fa-users',
        'url' => 'index.php?module=items/items&path=25',
        'active' => sidebar_active('items/items', '25'),
        'badge' => $new_lead_count > 0 ? $new_lead_count : null,
        'badge_color' => '#3498db',
    ],
    [
        'label' => 'Tasks',
        'icon' => 'fa-check-square-o',
        'url' => 'index.php?module=items/items&path=36',
        'active' => sidebar_active('items/items', '36'),
        'badge' => $task_count > 0 ? $task_count : null,
        'badge_color' => '#9b59b6',
    ],
    [
        'label' => 'Schedule',
        'icon' => 'fa-calendar',
        'url' => 'index.php?module=items/items&path=29',
        'active' => sidebar_active('items/items', '29'),
        'badge' => null,
    ],
];

$more_items = [
    ['label' => 'Sites', 'icon' => 'fa-globe', 'url' => 'index.php?module=items/items&path=37', 'active' => sidebar_active('items/items', '37')],
    ['label' => 'AI Chat', 'icon' => 'fa-comments', 'url' => 'index.php?module=ext/ipages/view&id=1', 'active' => sidebar_active('ext/ipages/view')],
    ['label' => 'Sessions', 'icon' => 'fa-history', 'url' => 'index.php?module=items/items&path=30', 'active' => sidebar_active('items/items', '30')],
];

$admin_items = [];
if (app_session_is_admin()) {
    $admin_items = [
        ['label' => 'Configuration', 'icon' => 'fa-cog', 'url' => 'index.php?module=configuration/index'],
        ['label' => 'App Structure', 'icon' => 'fa-sitemap', 'url' => 'index.php?module=entities/index'],
        ['label' => 'Reports', 'icon' => 'fa-bar-chart', 'url' => 'index.php?module=reports/index'],
    ];
}
?>

<ul class="page-sidebar-menu" data-keep-expanded="false" data-auto-scroll="true" data-slide-speed="200">
    <?php foreach ($menu_items as $item): ?>
    <li class="<?= $item['active'] ?>">
        <a href="<?= $item['url'] ?>">
            <i class="fa <?= $item['icon'] ?>"></i>
            <span class="title"><?= $item['label'] ?></span>
            <?php if (!empty($item['badge'])): ?>
            <span class="badge badge-roundless" style="background:<?= $item['badge_color'] ?? '#666' ?>"><?= $item['badge'] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>

    <li class="heading"><h3 class="uppercase">More</h3></li>
    <?php foreach ($more_items as $item): ?>
    <li class="<?= $item['active'] ?? '' ?>">
        <a href="<?= $item['url'] ?>">
            <i class="fa <?= $item['icon'] ?>"></i>
            <span class="title"><?= $item['label'] ?></span>
        </a>
    </li>
    <?php endforeach; ?>

    <?php if (!empty($admin_items)): ?>
    <li class="heading"><h3 class="uppercase">Admin</h3></li>
    <?php foreach ($admin_items as $item): ?>
    <li>
        <a href="<?= $item['url'] ?>">
            <i class="fa <?= $item['icon'] ?>"></i>
            <span class="title"><?= $item['label'] ?></span>
        </a>
    </li>
    <?php endforeach; ?>
    <?php endif; ?>
</ul>
```

**Step 2: Verify sidebar loads**

Refresh the CRM in browser. The sidebar should now show 5 items (Today, Jobs, Leads, Tasks, Schedule) with badge counts, plus a "More" section and admin links.

If the sidebar is broken or shows nothing, check:
- Does `app_session_is_admin()` function exist? It's a Rukovoditel built-in.
- Check PHP error log for undefined function errors.

---

## Task 5: Verify everything works end-to-end

**Step 1: Check file locations**

```bash
ls -la /var/www/ezlead-hq/crm/plugins/claude/includes/dashboard.php
ls -la /var/www/ezlead-hq/crm/plugins/claude/includes/ajax_action.php
ls -la /var/www/ezlead-hq/crm/plugins/claude/sidebar.php
ls -la /var/www/ezlead-hq/crm/plugins/claude/dashboard.php.bak
```

All 4 files should exist.

**Step 2: Test dashboard loads**

Open https://ezlead4u.com/crm/ in browser. Should see:
- "Today" header with date
- Summary badges (overdue count, due today count, new count, upcoming count)
- Quick add buttons (New Job, New Lead, New Task, New Appointment)
- Action feed items grouped by urgency section

**Step 3: Test sidebar**

Should see 5 main items with badge counts:
- Today (red badge if overdue items)
- Jobs
- Leads (blue badge if new leads)
- Tasks (purple badge if open tasks)
- Schedule

Plus "More" section and "Admin" section.

**Step 4: Test "Mark Done" button**

Click "Done" on any task item. Should:
- Show spinner
- Fade the item out
- Actually mark field_330 in database

Verify:
```bash
mysql -u root rukovoditel -e "SELECT id, field_328, field_330 FROM app_entity_36 WHERE field_330 = '1'"
```

**Step 5: Test navigation**

Click each sidebar item — should navigate to the correct entity listing.
Click "View Details" / action buttons on feed items — should open the correct record.

---

## Task 6: Fix file permissions

**Step 1: Set correct ownership**

```bash
sudo chown -R www-data:www-data /var/www/ezlead-hq/crm/plugins/claude/includes/
sudo chown www-data:www-data /var/www/ezlead-hq/crm/plugins/claude/sidebar.php
sudo chmod 644 /var/www/ezlead-hq/crm/plugins/claude/includes/dashboard.php
sudo chmod 644 /var/www/ezlead-hq/crm/plugins/claude/includes/ajax_action.php
sudo chmod 644 /var/www/ezlead-hq/crm/plugins/claude/sidebar.php
```
