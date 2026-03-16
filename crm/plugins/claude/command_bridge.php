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

        // --- Command Injection Protection ---

        // Block shell operators that could chain/inject commands
        $dangerous_patterns = [
            ';',        // command chaining
            '&&',       // conditional chaining
            '||',       // conditional chaining
            '`',        // backtick subshell
            '$(',       // subshell expansion
            '${',       // variable expansion
            '|',        // pipe (handled separately below)
            '>',        // output redirect
            '<',        // input redirect
            "\n",       // newline injection
            "\r",       // carriage return injection
        ];

        $has_dangerous_pattern = false;
        foreach ($dangerous_patterns as $pattern) {
            if (strpos($command, $pattern) !== false) {
                $has_dangerous_pattern = true;
                break;
            }
        }

        if ($has_dangerous_pattern) {
            error_log("[command_bridge] BLOCKED dangerous pattern in command: " . substr($command, 0, 200));
            $result = ['success' => false, 'error' => 'Command contains blocked shell operators'];
            break;
        }

        // Whitelist of allowed command prefixes
        $allowed_prefixes = [
            'ls ',   'ls',
            'cat ',
            'head ',
            'tail ',
            'pwd',
            'php ',
            'git ',
            'wc ',
            'find ',
            'grep ',
            'stat ',
            'file ',
            'basename ',
            'dirname ',
            'realpath ',
            'whoami',
            'date',
            'df ',    'df',
            'du ',
        ];

        $command_allowed = false;
        $trimmed = trim($command);
        foreach ($allowed_prefixes as $prefix) {
            if ($trimmed === $prefix || strpos($trimmed, $prefix) === 0) {
                $command_allowed = true;
                break;
            }
        }

        if (!$command_allowed) {
            error_log("[command_bridge] BLOCKED non-whitelisted command: " . substr($command, 0, 200));
            $result = ['success' => false, 'error' => 'Command not in whitelist. Allowed: ls, cat, head, tail, pwd, php, git, wc, find, grep, stat, file, basename, dirname, realpath, whoami, date, df, du'];
            break;
        }

        // Block dangerous subcommands even within allowed prefixes
        $blocked_substrings = [
            'rm -rf',   'rm -r',    'rm -f',    'rmdir',
            'chmod',    'chown',    'chgrp',
            'wget',     'curl',
            'sudo',     'su ',
            'dd ',      'mkfs',     'fdisk',    'mount',    'umount',
            'shutdown', 'reboot',   'init ',    'systemctl',
            'iptables', 'ufw',
            'passwd',   'useradd',  'userdel',  'usermod',
            'eval ',    'exec ',
            '/dev/sd',  '/dev/null',
            'nc ',      'ncat',     'netcat',
            'python',   'perl',     'ruby',     'node ',
        ];

        $has_blocked_substring = false;
        $lower_command = strtolower($trimmed);
        foreach ($blocked_substrings as $blocked) {
            if (strpos($lower_command, $blocked) !== false) {
                $has_blocked_substring = true;
                error_log("[command_bridge] BLOCKED dangerous substring '$blocked' in command: " . substr($command, 0, 200));
                break;
            }
        }

        if ($has_blocked_substring) {
            $result = ['success' => false, 'error' => 'Command contains a blocked keyword'];
            break;
        }

        // Execute command with timeout using escapeshellcmd for final safety layer
        $output = [];
        $return_code = 0;
        exec("timeout 30 " . escapeshellcmd($command) . " 2>&1", $output, $return_code);
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
