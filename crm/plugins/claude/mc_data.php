<?php
/**
 * Mission Control - Tree Data Endpoint
 * Returns JSON tree of Kyle's 7 life branches with task counts, next task,
 * last session, and color status.
 *
 * POST mark_done=<task_id> to mark a task done.
 * POST archive=<task_id> to archive a done task (removes from Mission Control).
 *
 * Branch field: field_502 on entity 36 (Tasks)
 * Choice IDs: 182=Mechanic, 183=Money, 184=Legal, 185=CRM/Infrastructure, 186=Lead Gen, 187=Move, 188=Family
 * Done field: field_330 (181=Done)
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../../config/database.php');
require_once(__DIR__ . '/mc_health.php');

$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($db->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// --- POST: Mark task done ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_done'])) {
    $task_id = intval($_POST['mark_done']);
    if ($task_id > 0) {
        $stmt = $db->prepare("UPDATE app_entity_36 SET field_330 = '181', date_updated = NOW() WHERE id = ?");
        $stmt->bind_param('i', $task_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok, 'id' => $task_id]);
    } else {
        echo json_encode(['error' => 'Invalid task ID']);
    }
    $db->close();
    exit;
}

// --- POST: Archive a done task (remove from Mission Control) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive'])) {
    $task_id = intval($_POST['archive']);
    if ($task_id > 0) {
        $stmt = $db->prepare("UPDATE app_entity_36 SET field_502 = '', date_updated = NOW() WHERE id = ?");
        $stmt->bind_param('i', $task_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok, 'id' => $task_id]);
    } else {
        echo json_encode(['error' => 'Invalid task ID']);
    }
    $db->close();
    exit;
}

// --- GET: Build tree data ---

// Branch choice ID => key mapping
$branch_map = [
    '182' => 'mechanic',
    '183' => 'money',
    '184' => 'legal',
    '185' => 'crm',
    '186' => 'leadgen',
    '187' => 'move',
    '188' => 'family',
];

// Branch => terminal work command (from ~/scripts/work.sh)
$work_cmds = [
    'mechanic' => 'work 2',
    'money'    => 'work 2',
    'legal'    => 'work 11',
    'crm'      => 'work 1',
    'leadgen'  => 'work 7',
    'move'     => 'work 6',
    'family'   => null,
];

// Branch => key files and directories
$branch_files = [
    'mechanic' => [
        '/var/www/ezlead-platform/' => 'Mechanic site',
        '/var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php' => '11-stage workflow',
        '/var/www/ezlead-platform/core/lib/EstimateEngine.php' => 'Estimates',
        '/var/www/ezlead-platform/core/lib/PDFGenerator.php' => 'PDFs',
    ],
    'money' => [
        '/var/www/ezlead-platform/core/lib/EstimateEngine.php' => 'Estimates/invoicing',
    ],
    'legal' => [
        '/home/kylewee/Desktop/pro se/' => 'Pro Se app + docs',
    ],
    'crm' => [
        '/var/www/ezlead-hq/crm/plugins/claude/' => 'CRM plugins',
        '/var/www/ezlead-hq/crm/plugins/claude/sidebar.php' => 'Sidebar override',
        '/home/kylewee/scripts/' => 'Scripts',
    ],
    'leadgen' => [
        '/var/www/sodjax.com/' => 'sodjax.com',
        '/var/www/sodjacksonville.com/' => 'sodjacksonville.com',
        '/var/www/sod.company.new/' => 'sod.company',
        '/var/www/nearby.contractors/' => 'nearby.contractors',
        '/home/kylewee/scripts/keyword_expander.py' => 'Keyword pipeline',
    ],
    'move' => [],
    'family' => [],
];

$pri_labels = ['178' => 'High', '179' => 'Medium', '180' => 'Low'];

// Fetch all tasks sorted by priority (178=High first)
$tasks_by_branch = [];
$open_tasks = [];
$done_tasks = [];
$result = $db->query("
    SELECT id, field_328 as task, field_502 as branch, field_329 as priority, field_330 as done
    FROM app_entity_36
    ORDER BY field_329 ASC, id ASC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $bid = $row['branch'];
        if (!$bid) continue;
        if (!isset($tasks_by_branch[$bid])) $tasks_by_branch[$bid] = [];
        $tasks_by_branch[$bid][] = $row;

        if ($row['done'] == '181') {
            // Done tasks per branch
            if (!isset($done_tasks[$bid])) $done_tasks[$bid] = [];
            $done_tasks[$bid][] = [
                'id'   => (int)$row['id'],
                'task' => $row['task'],
            ];
        } else {
            // Open tasks per branch
            if (!isset($open_tasks[$bid])) $open_tasks[$bid] = [];
            $open_tasks[$bid][] = [
                'id'       => (int)$row['id'],
                'task'     => $row['task'],
                'priority' => $pri_labels[$row['priority']] ?? 'Normal',
            ];
        }
    }
}

// Fetch last session per branch (actions link to sessions via field_500)
$last_sessions = [];
$result = $db->query("
    SELECT a.field_502 as branch,
           s.id, s.field_290 as title, s.field_296 as summary, s.date_added
    FROM app_entity_36 a
    JOIN app_entity_30 s ON a.field_500 = s.id
    WHERE a.field_502 IS NOT NULL AND a.field_502 != ''
      AND a.field_500 IS NOT NULL AND a.field_500 != '' AND a.field_500 != '0'
    ORDER BY s.date_added DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $bid = $row['branch'];
        if (!isset($last_sessions[$bid])) {
            $summary = $row['summary'] ?: '';
            if (strlen($summary) > 200) $summary = substr($summary, 0, 200) . '...';
            $last_sessions[$bid] = [
                'id'      => (int)$row['id'],
                'title'   => $row['title'],
                'summary' => $summary,
                'date'    => $row['date_added'],
            ];
        }
    }
}

// For branches with no session via actions, find most recent session overall
// (better than nothing - at least shows recent work)
if (count($last_sessions) < count($branch_map)) {
    $result = $db->query("
        SELECT id, field_290 as title, field_296 as summary, date_added
        FROM app_entity_30
        WHERE field_296 IS NOT NULL AND field_296 != '' AND field_296 != '(Computer crashed, no checkout)'
        ORDER BY date_added DESC LIMIT 1
    ");
    if ($result && $row = $result->fetch_assoc()) {
        $fallback_session = [
            'id'      => (int)$row['id'],
            'title'   => $row['title'],
            'summary' => substr($row['summary'] ?: '', 0, 200),
            'date'    => $row['date_added'],
        ];
        foreach ($branch_map as $cid => $bkey) {
            if (!isset($last_sessions[$cid])) {
                $last_sessions[$cid] = null; // explicit null, no fallback noise
            }
        }
    }
}

// Run health checks (cached 5 min)
$health = get_health($db);

$db->close();

// Define the tree structure — dynamic pieces where detectable, static where human-state
$tree = [
    'mechanic' => [
        'name' => 'Mechanic Business',
        'priority' => 1,
        'blocker' => '10DLC registration', // SMS works but not carrier-compliant yet (task #79)
        'pieces' => [
            ['name' => 'Call → voicemail → transcribe', 'note' => $health['voice_pipeline']['note']],
            ['name' => 'Estimate generated',             'note' => $health['estimates']['note']],
            ['name' => 'Mitchell1 ProDemand',            'note' => $health['mitchell1']['note']],
            ['name' => 'Lead created in CRM',            'note' => $health['crm_api']['note']],
            ['name' => 'Estimate sent to customer',      'note' => $health['signalwire_sms']['note']],
            ['name' => 'Customer accepts',               'note' => 'Not built'],
            ['name' => 'Smart scheduling',               'note' => 'Not built'],
            ['name' => '24hr reminder',                  'note' => $health['signalwire_sms']['note']],
            ['name' => 'Kyle does the work',             'note' => 'Always Kyle'],
            ['name' => 'Tap-to-pay at job site',         'note' => 'Not built'],
            ['name' => 'Follow-up / review request',     'note' => $health['signalwire_sms']['note']],
        ],
    ],
    'money' => [
        'name' => 'Money In / Money Out',
        'priority' => 2,
        'blocker' => null,
        'pieces' => [
            ['name' => 'Invoicing', 'note' => 'In your head'],
            ['name' => 'Tracking income', 'note' => 'In your head'],
            ['name' => 'Tracking expenses', 'note' => 'In your head'],
            ['name' => 'Taxes', 'note' => 'In your head'],
            ['name' => 'Knowing where you stand', 'note' => 'In your head'],
        ],
    ],
    'legal' => [
        'name' => 'Legal',
        'priority' => 3,
        'blocker' => null,
        'pieces' => [
            ['name' => 'Cases in CRM', 'note' => 'Entity 47 empty'],
            ['name' => 'Deadlines / alarms', 'note' => 'Nothing tracked'],
            ['name' => 'Documents / files', 'note' => 'Scattered'],
            ['name' => 'Pro Se app', 'note' => 'Being fine-tuned'],
            ['name' => 'Follow-up reminders', 'note' => 'Not set up'],
        ],
    ],
    'crm' => [
        'name' => 'CRM / Infrastructure',
        'priority' => 4,
        'blocker' => null,
        'pieces' => [
            ['name' => 'CRM running',           'note' => $health['crm_api']['note']],
            ['name' => 'Session tracking',       'note' => $health['session_tracking']['note']],
            ['name' => 'Mechanic automation',    'note' => $health['mechanic_automation']['note']],
            ['name' => 'Uptime monitor',         'note' => $health['uptime_sites']['note']],
            ['name' => 'Ollama / Local AI',      'note' => $health['ollama']['note']],
            ['name' => 'Server / hosting',       'note' => $health['server']['note']],
            ['name' => 'Mission Control',        'note' => 'Working'],
        ],
    ],
    'leadgen' => [
        'name' => 'Lead Gen / Websites',
        'priority' => 5,
        'blocker' => null,
        'pieces' => [
            ['name' => 'Sites exist and rank',  'note' => $health['uptime_sites']['note']],
            ['name' => 'Forms capture leads',   'note' => 'Untested'],
            ['name' => 'Analytics tracking',    'note' => 'Not set up'],
            ['name' => 'Lead buyer system',     'note' => 'No active buyers'],
        ],
    ],
    'move' => [
        'name' => 'Ready to Move',
        'priority' => 6,
        'blocker' => null,
        'pieces' => [
            ['name' => 'Equipment fix/sell/scrap', 'note' => 'Sitting'],
            ['name' => 'Where / schools / plan', 'note' => 'Undecided'],
            ['name' => 'Able to pack and go', 'note' => 'Equipment is blocker'],
        ],
    ],
    'family' => [
        'name' => 'Family Time',
        'priority' => 7,
        'blocker' => null,
        'pieces' => [
            ['name' => 'Time together', 'note' => 'Soccer, hanging out'],
            ['name' => 'Protected dedicated time', 'note' => 'Not scheduled'],
        ],
    ],
];

function compute_status($done, $total, $priority, $blocker) {
    if ($total === 0) return 'gray';
    if ($done === $total && !$blocker) return 'green';

    $pct = ($done / $total) * 100;
    $high_priority = $priority <= 3;

    if ($high_priority) {
        $status = ($pct >= 80) ? 'green' : (($pct >= 50) ? 'yellow' : 'red');
    } else {
        $status = ($pct >= 70) ? 'green' : (($pct >= 30) ? 'yellow' : 'red');
    }

    if ($blocker && $status === 'green') {
        $status = 'yellow';
    }

    return $status;
}

function piece_status($note) {
    $note = strtolower($note);
    if (str_contains($note, 'not built') || str_contains($note, 'not set up') ||
        str_contains($note, 'in your head') || str_contains($note, 'empty') ||
        str_contains($note, 'scattered') || str_contains($note, 'untested') ||
        str_contains($note, 'undecided') || str_contains($note, 'sitting') ||
        str_contains($note, 'not scheduled') || str_contains($note, 'no active') ||
        str_contains($note, 'nothing tracked')) return 'red';
    if (str_contains($note, 'working') || str_contains($note, 'done') ||
        str_contains($note, 'growing') || str_contains($note, 'always') ||
        str_contains($note, 'soccer')) return 'green';
    if (str_contains($note, 'built') || str_contains($note, 'building') ||
        str_contains($note, 'fine-tuned') || str_contains($note, 'fine tuned') ||
        str_contains($note, 'blocked') || str_contains($note, 'blocker')) return 'yellow';
    return 'red';
}

// Build output
$children = [];
foreach ($tree as $key => $branch) {
    $choice_id = array_search($key, $branch_map);
    $branch_tasks = $tasks_by_branch[$choice_id] ?? [];
    $task_total = count($branch_tasks);

    $pieces_out = [];
    $done_count = 0;
    foreach ($branch['pieces'] as $piece) {
        $ps = piece_status($piece['note']);
        if ($ps === 'green') $done_count++;
        $pieces_out[] = [
            'name' => $piece['name'],
            'status' => $ps,
            'note' => $piece['note'],
        ];
    }
    $total_pieces = count($branch['pieces']);
    $status = compute_status($done_count, $total_pieces, $branch['priority'], $branch['blocker']);

    $node = [
        'name' => $branch['name'],
        'key' => $key,
        'status' => $status,
        'priority' => $branch['priority'],
        'done' => $done_count,
        'total' => $total_pieces,
        'tasks_count' => $task_total,
        'choice_id' => $choice_id,
        'children' => $pieces_out,
        'open_tasks' => $open_tasks[$choice_id] ?? [],
        'done_tasks' => $done_tasks[$choice_id] ?? [],
        'last_session' => $last_sessions[$choice_id] ?? null,
        'work_cmd' => $work_cmds[$key] ?? null,
        'files' => $branch_files[$key] ?? [],
    ];
    if ($branch['blocker']) {
        $node['blocker'] = $branch['blocker'];
    }
    $children[] = $node;
}

$dominoes = 'SMS → Mechanic → Money → Move. Legal payout → Move. Mission Control → everything faster.';
$version = 'v2';

echo json_encode([
    'name' => 'Kyle',
    'role' => 'roots',
    'dominoes' => $dominoes,
    'version' => $version,
    'children' => $children,
], JSON_PRETTY_PRINT);
