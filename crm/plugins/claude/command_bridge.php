<?php
/**
 * Command Bridge - Allows Claude Chat to execute local commands
 * SECURITY: Protected by API key, use with caution
 */

// API Key for authentication
define('BRIDGE_API_KEY', 'kw_bridge_2026_secure_key');

header('Content-Type: application/json');

// Verify API key
$provided_key = $_SERVER['HTTP_X_BRIDGE_KEY'] ?? $_POST['api_key'] ?? '';
if ($provided_key !== BRIDGE_API_KEY) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

$action = $_POST['action'] ?? '';
$result = ['success' => false, 'error' => 'Unknown action'];

switch ($action) {
    case 'bash':
        $command = $_POST['command'] ?? '';
        if (empty($command)) {
            $result = ['success' => false, 'error' => 'No command provided'];
            break;
        }
        // Execute command with timeout
        $output = [];
        $return_code = 0;
        exec("timeout 30 " . $command . " 2>&1", $output, $return_code);
        $result = [
            'success' => true,
            'output' => implode("\n", $output),
            'exit_code' => $return_code
        ];
        break;

    case 'read_file':
        $path = $_POST['path'] ?? '';
        if (empty($path) || !file_exists($path)) {
            $result = ['success' => false, 'error' => 'File not found: ' . $path];
            break;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            $result = ['success' => false, 'error' => 'Cannot read file'];
            break;
        }
        // Limit size
        if (strlen($content) > 50000) {
            $content = substr($content, 0, 50000) . "\n... [truncated]";
        }
        $result = ['success' => true, 'content' => $content];
        break;

    case 'write_file':
        $path = $_POST['path'] ?? '';
        $content = $_POST['content'] ?? '';
        if (empty($path)) {
            $result = ['success' => false, 'error' => 'No path provided'];
            break;
        }
        $written = file_put_contents($path, $content);
        if ($written === false) {
            $result = ['success' => false, 'error' => 'Cannot write file'];
            break;
        }
        $result = ['success' => true, 'bytes_written' => $written];
        break;

    case 'list_dir':
        $path = $_POST['path'] ?? '.';
        if (!is_dir($path)) {
            $result = ['success' => false, 'error' => 'Not a directory'];
            break;
        }
        $files = scandir($path);
        $result = ['success' => true, 'files' => $files];
        break;
}

echo json_encode($result);
