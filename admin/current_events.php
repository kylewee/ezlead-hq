<?php
session_start();

// Simple password protection (same as dashboard)
$password = 'ezlead2024';

if (isset($_POST['logout'])) {
    unset($_SESSION['admin_logged_in']);
}

if (isset($_POST['password'])) {
    if ($_POST['password'] === $password) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        $error = 'Incorrect password';
    }
}

// Handle adding new events
$events_file = __DIR__ . '/events_log.json';
if (isset($_POST['add_event']) && isset($_SESSION['admin_logged_in'])) {
    $events = file_exists($events_file) ? json_decode(file_get_contents($events_file), true) : [];
    $events[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'type' => $_POST['event_type'] ?? 'note',
        'title' => $_POST['event_title'] ?? '',
        'description' => $_POST['event_description'] ?? '',
        'status' => $_POST['event_status'] ?? 'open'
    ];
    file_put_contents($events_file, json_encode($events, JSON_PRETTY_PRINT));
    header('Location: current_events.php');
    exit;
}

// Handle marking event as done
if (isset($_GET['complete']) && isset($_SESSION['admin_logged_in'])) {
    $events = file_exists($events_file) ? json_decode(file_get_contents($events_file), true) : [];
    $index = (int)$_GET['complete'];
    if (isset($events[$index])) {
        $events[$index]['status'] = 'done';
        $events[$index]['completed_at'] = date('Y-m-d H:i:s');
    }
    file_put_contents($events_file, json_encode($events, JSON_PRETTY_PRINT));
    header('Location: current_events.php');
    exit;
}

// Handle deleting event
if (isset($_GET['delete']) && isset($_SESSION['admin_logged_in'])) {
    $events = file_exists($events_file) ? json_decode(file_get_contents($events_file), true) : [];
    $index = (int)$_GET['delete'];
    if (isset($events[$index])) {
        array_splice($events, $index, 1);
    }
    file_put_contents($events_file, json_encode($events, JSON_PRETTY_PRINT));
    header('Location: current_events.php');
    exit;
}

// Load events
$events = file_exists($events_file) ? json_decode(file_get_contents($events_file), true) : [];

if (!isset($_SESSION['admin_logged_in'])) {
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Current Events</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #16213e; padding: 40px; border-radius: 10px; text-align: center; }
        input[type="password"] { padding: 12px; font-size: 16px; border: none; border-radius: 5px; margin: 10px 0; width: 200px; }
        button { padding: 12px 30px; font-size: 16px; background: #e94560; color: white; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #ff6b6b; }
        .error { color: #ff6b6b; margin-top: 10px; }
        h1 { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>📋 Current Events</h1>
        <form method="POST">
            <input type="password" name="password" placeholder="Enter password" required><br>
            <button type="submit">Login</button>
        </form>
        <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Current Events - EzLead4U</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        h1 { margin: 0; }
        .nav-btns a, .nav-btns button { padding: 10px 20px; background: #0f3460; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; margin-left: 10px; }
        .nav-btns a:hover, .nav-btns button:hover { background: #1a4a7a; }
        .logout-btn { background: #e94560 !important; }
        .logout-btn:hover { background: #ff6b6b !important; }
        
        .add-form { background: #16213e; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .add-form h2 { margin-top: 0; color: #4fc3f7; }
        .form-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
        .form-row input, .form-row select, .form-row textarea { padding: 10px; border: none; border-radius: 5px; background: #0f3460; color: #eee; }
        .form-row input[type="text"] { flex: 1; min-width: 200px; }
        .form-row textarea { width: 100%; min-height: 60px; }
        .add-btn { padding: 10px 25px; background: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .add-btn:hover { background: #66bb6a; }
        
        .events-list { display: flex; flex-direction: column; gap: 15px; }
        .event-card { background: #16213e; padding: 20px; border-radius: 10px; border-left: 5px solid #4fc3f7; }
        .event-card.done { opacity: 0.6; border-left-color: #4caf50; }
        .event-card.call { border-left-color: #e94560; }
        .event-card.lead { border-left-color: #ff9800; }
        .event-card.task { border-left-color: #9c27b0; }
        .event-card.note { border-left-color: #4fc3f7; }
        .event-card.roleplay { border-left-color: #00bcd4; }
        
        .event-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .event-title { font-size: 18px; font-weight: bold; margin: 0; }
        .event-type { font-size: 12px; padding: 3px 8px; border-radius: 3px; background: #0f3460; }
        .event-time { color: #888; font-size: 12px; margin-bottom: 10px; }
        .event-desc { color: #ccc; margin-bottom: 15px; white-space: pre-wrap; }
        .event-actions a { color: #4fc3f7; text-decoration: none; margin-right: 15px; font-size: 14px; }
        .event-actions a:hover { text-decoration: underline; }
        .event-actions a.delete { color: #e94560; }
        
        .filter-bar { margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; }
        .filter-bar a { padding: 8px 15px; background: #0f3460; color: #eee; text-decoration: none; border-radius: 5px; }
        .filter-bar a:hover, .filter-bar a.active { background: #4fc3f7; color: #1a1a2e; }
        
        .empty-state { text-align: center; padding: 50px; color: #888; }
        
        .status-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; margin-left: 10px; }
        .status-badge.open { background: #ff9800; color: #000; }
        .status-badge.done { background: #4caf50; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📋 Current Events</h1>
        <div class="nav-btns">
            <a href="dashboard.php">Dashboard</a>
            <form method="POST" style="display:inline;">
                <button type="submit" name="logout" value="1" class="logout-btn">Logout</button>
            </form>
        </div>
    </div>

    <div class="add-form">
        <h2>➕ Add New Event</h2>
        <form method="POST">
            <div class="form-row">
                <select name="event_type">
                    <option value="note">📝 Note</option>
                    <option value="call">📞 Call</option>
                    <option value="lead">👤 Lead</option>
                    <option value="task">✅ Task</option>
                    <option value="roleplay">🎭 Role-play Observation</option>
                </select>
                <input type="text" name="event_title" placeholder="Event title..." required>
            </div>
            <div class="form-row">
                <textarea name="event_description" placeholder="Description or details..."></textarea>
            </div>
            <button type="submit" name="add_event" value="1" class="add-btn">Add Event</button>
        </form>
    </div>

    <div class="filter-bar">
        <a href="?filter=all" class="<?php echo (!isset($_GET['filter']) || $_GET['filter'] == 'all') ? 'active' : ''; ?>">All</a>
        <a href="?filter=open" class="<?php echo (isset($_GET['filter']) && $_GET['filter'] == 'open') ? 'active' : ''; ?>">Open</a>
        <a href="?filter=done" class="<?php echo (isset($_GET['filter']) && $_GET['filter'] == 'done') ? 'active' : ''; ?>">Done</a>
        <a href="?filter=roleplay" class="<?php echo (isset($_GET['filter']) && $_GET['filter'] == 'roleplay') ? 'active' : ''; ?>">🎭 Role-play</a>
    </div>

    <div class="events-list">
        <?php
        $filter = $_GET['filter'] ?? 'all';
        $filtered_events = array_reverse($events, true);
        $has_events = false;
        
        foreach ($filtered_events as $index => $event) {
            if ($filter == 'open' && ($event['status'] ?? 'open') != 'open') continue;
            if ($filter == 'done' && ($event['status'] ?? 'open') != 'done') continue;
            if ($filter == 'roleplay' && ($event['type'] ?? 'note') != 'roleplay') continue;
            
            $has_events = true;
            $type = $event['type'] ?? 'note';
            $status = $event['status'] ?? 'open';
            $type_icons = ['note' => '📝', 'call' => '📞', 'lead' => '👤', 'task' => '✅', 'roleplay' => '🎭'];
            $icon = $type_icons[$type] ?? '📝';
        ?>
        <div class="event-card <?php echo $type; ?> <?php echo $status; ?>">
            <div class="event-header">
                <div>
                    <p class="event-title"><?php echo htmlspecialchars($event['title']); ?></p>
                    <span class="event-type"><?php echo $icon; ?> <?php echo ucfirst($type); ?></span>
                    <span class="status-badge <?php echo $status; ?>"><?php echo ucfirst($status); ?></span>
                </div>
            </div>
            <p class="event-time">🕐 <?php echo $event['timestamp']; ?>
                <?php if (isset($event['completed_at'])) echo " ✅ Completed: " . $event['completed_at']; ?>
            </p>
            <?php if (!empty($event['description'])): ?>
            <p class="event-desc"><?php echo htmlspecialchars($event['description']); ?></p>
            <?php endif; ?>
            <div class="event-actions">
                <?php if ($status != 'done'): ?>
                <a href="?complete=<?php echo $index; ?>">✅ Mark Done</a>
                <?php endif; ?>
                <a href="?delete=<?php echo $index; ?>" class="delete" onclick="return confirm('Delete this event?');">🗑️ Delete</a>
            </div>
        </div>
        <?php } ?>
        
        <?php if (!$has_events): ?>
        <div class="empty-state">
            <p>No events yet. Add one above! 👆</p>
        </div>
        <?php endif; ?>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
