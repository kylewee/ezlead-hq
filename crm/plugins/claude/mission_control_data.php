<?php
/**
 * Mission Control - Tree Data Endpoint
 * Returns JSON tree of Kyle's 7 life branches with task counts and color status.
 *
 * Branch field: field_502 on entity 36 (Tasks)
 * Choice IDs: 182=Mechanic, 183=Money, 184=Legal, 185=CRM/Infrastructure, 186=Lead Gen, 187=Move, 188=Family
 * Done field: field_330 (checkboxes, 181=Done)
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../../config/database.php');

$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($db->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// Fetch all tasks grouped by branch
$tasks = [];
$result = $db->query("SELECT id, field_328 as task, field_502 as branch, field_329 as priority, field_330 as done FROM app_entity_36");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $branch_id = $row['branch'];
        if (!$branch_id) continue;
        if (!isset($tasks[$branch_id])) $tasks[$branch_id] = [];
        $tasks[$branch_id][] = $row;
    }
}
$db->close();

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

// Define the tree structure
$tree = [
    'mechanic' => [
        'name' => 'Mechanic Business',
        'priority' => 1,
        'blocker' => 'SignalWire 10DLC',
        'pieces' => [
            ['name' => 'Call → voicemail → transcribe', 'note' => 'Built, not running'],
            ['name' => 'Estimate generated', 'note' => 'Built, not running'],
            ['name' => 'Lead created in CRM', 'note' => 'Built, not running'],
            ['name' => 'Estimate sent to customer', 'note' => 'Built, needs SMS'],
            ['name' => 'Customer accepts', 'note' => 'Not built'],
            ['name' => 'Smart scheduling', 'note' => 'Not built'],
            ['name' => '24hr reminder', 'note' => 'Built, needs SMS'],
            ['name' => 'Kyle does the work', 'note' => 'Always Kyle'],
            ['name' => 'Tap-to-pay at job site', 'note' => 'Not built'],
            ['name' => 'Follow-up email', 'note' => 'Built, needs SMS'],
            ['name' => 'Review request', 'note' => 'Built, needs SMS'],
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
            ['name' => 'CRM running', 'note' => 'Working'],
            ['name' => 'Session tracking', 'note' => 'Working'],
            ['name' => 'Mechanic automation', 'note' => 'SMS blocked'],
            ['name' => 'Entity structure', 'note' => 'Done'],
            ['name' => 'Server / hosting', 'note' => 'Done'],
            ['name' => 'CLAUDE.md ecosystem', 'note' => 'Done'],
            ['name' => 'Mission Control', 'note' => 'Building now'],
        ],
    ],
    'leadgen' => [
        'name' => 'Lead Gen / Websites',
        'priority' => 5,
        'blocker' => null,
        'pieces' => [
            ['name' => 'Sites exist and rank', 'note' => '22 domains growing'],
            ['name' => 'Forms capture leads', 'note' => 'Untested'],
            ['name' => 'Analytics tracking', 'note' => 'Not set up'],
            ['name' => 'Lead buyer system', 'note' => 'No active buyers'],
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
    // Red: not built, not set up, empty, scattered, in your head, untested, etc
    if (str_contains($note, 'not built') || str_contains($note, 'not set up') ||
        str_contains($note, 'in your head') || str_contains($note, 'empty') ||
        str_contains($note, 'scattered') || str_contains($note, 'untested') ||
        str_contains($note, 'undecided') || str_contains($note, 'sitting') ||
        str_contains($note, 'not scheduled') || str_contains($note, 'no active') ||
        str_contains($note, 'nothing tracked')) return 'red';
    // Green: working, done, growing, always
    if (str_contains($note, 'working') || str_contains($note, 'done') ||
        str_contains($note, 'growing') || str_contains($note, 'always') ||
        str_contains($note, 'soccer')) return 'green';
    // Yellow: built (but blocked/not running), building, fine-tuning
    if (str_contains($note, 'built') || str_contains($note, 'building') ||
        str_contains($note, 'fine-tuned') || str_contains($note, 'fine tuned') ||
        str_contains($note, 'blocked') || str_contains($note, 'blocker')) return 'yellow';
    return 'red';
}

// Build output
$children = [];
foreach ($tree as $key => $branch) {
    $choice_id = array_search($key, $branch_map);
    $branch_tasks = $tasks[$choice_id] ?? [];
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
    ];
    if ($branch['blocker']) {
        $node['blocker'] = $branch['blocker'];
    }
    $children[] = $node;
}

$dominoes = 'SMS → Mechanic → Money → Move. Legal payout → Move. Mission Control → everything faster.';

echo json_encode([
    'name' => 'Kyle',
    'role' => 'roots',
    'dominoes' => $dominoes,
    'children' => $children,
], JSON_PRETTY_PRINT);
