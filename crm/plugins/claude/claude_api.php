<?php
/**
 * Claude API Class with Tool Support
 */

require_once __DIR__ . '/config.php';

class ClaudeAPI
{
    private $api_key;
    private $api_url = 'https://api.anthropic.com/v1/messages';
    private $model = 'claude-sonnet-4-20250514';
    private $max_tokens = 4096;
    private $tools = [];

    public function __construct($api_key = null)
    {
        $this->api_key = $api_key ?? CLAUDE_API_KEY;
        $this->initTools();
    }

    private function initTools()
    {
        $this->tools = [
            [
                'name' => 'bash',
                'description' => 'Execute a bash command on the server. Use for: running scripts, checking system status, git operations, installing packages, etc.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'command' => [
                            'type' => 'string',
                            'description' => 'The bash command to execute'
                        ]
                    ],
                    'required' => ['command']
                ]
            ],
            [
                'name' => 'read_file',
                'description' => 'Read the contents of a file from the server filesystem.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'Absolute path to the file to read'
                        ]
                    ],
                    'required' => ['path']
                ]
            ],
            [
                'name' => 'write_file',
                'description' => 'Write content to a file on the server filesystem.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'Absolute path to the file to write'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Content to write to the file'
                        ]
                    ],
                    'required' => ['path', 'content']
                ]
            ],
            [
                'name' => 'list_directory',
                'description' => 'List files and directories in a given path.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'Path to the directory to list'
                        ]
                    ],
                    'required' => ['path']
                ]
            ]
        ];
    }

    public function sendConversation($messages, $system_prompt = null, $options = [])
    {
        $default_system = "You are a helpful AI assistant with access to the server filesystem and bash commands. You can read files, write files, and execute commands to help the user. When you need to perform actions, use the available tools. Always explain what you're doing.";
        
        $payload = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? $this->max_tokens,
            'system' => $system_prompt ?? $default_system,
            'messages' => $messages,
            'tools' => $this->tools
        ];

        // Make initial request
        $response = $this->makeRequest($payload);
        
        // Handle tool use loop
        $max_iterations = 10;
        $iteration = 0;
        
        while ($response['success'] && $response['stop_reason'] === 'tool_use' && $iteration < $max_iterations) {
            $iteration++;
            
            // Execute tools and collect results
            $tool_results = [];
            foreach ($response['raw_content'] as $block) {
                if ($block['type'] === 'tool_use') {
                    $tool_result = $this->executeTool($block['name'], $block['input']);
                    $tool_results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => json_encode($tool_result)
                    ];
                }
            }
            
            // Add assistant message and tool results to conversation
            $messages[] = ['role' => 'assistant', 'content' => $response['raw_content']];
            $messages[] = ['role' => 'user', 'content' => $tool_results];
            
            $payload['messages'] = $messages;
            $response = $this->makeRequest($payload);
        }
        
        return $response;
    }

    private function executeTool($name, $input)
    {
        $bridge_url = 'https://ezlead4u.com/crm/plugins/claude/command_bridge.php';
        $api_key = 'kw_bridge_2026_secure_key';
        
        $post_data = ['api_key' => $api_key];
        
        switch ($name) {
            case 'bash':
                $post_data['action'] = 'bash';
                $post_data['command'] = $input['command'];
                break;
            case 'read_file':
                $post_data['action'] = 'read_file';
                $post_data['path'] = $input['path'];
                break;
            case 'write_file':
                $post_data['action'] = 'write_file';
                $post_data['path'] = $input['path'];
                $post_data['content'] = $input['content'];
                break;
            case 'list_directory':
                $post_data['action'] = 'list_dir';
                $post_data['path'] = $input['path'];
                break;
            default:
                return ['error' => 'Unknown tool: ' . $name];
        }
        
        $ch = curl_init($bridge_url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post_data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($result, true) ?? ['error' => 'Bridge error'];
    }

    public function sendMessage($message, $system_prompt = null, $options = [])
    {
        return $this->sendConversation([['role' => 'user', 'content' => $message]], $system_prompt, $options);
    }

    private function makeRequest($payload)
    {
        $ch = curl_init($this->api_url);

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->api_key,
            'anthropic-version: 2023-06-01'
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return ['success' => false, 'content' => null, 'error' => 'cURL error: ' . $curl_error];
        }

        $data = json_decode($response, true);

        if ($http_code !== 200) {
            $error_message = $data['error']['message'] ?? 'API request failed with status ' . $http_code;
            return ['success' => false, 'content' => null, 'error' => $error_message];
        }

        // Extract text content
        $content = '';
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if ($block['type'] === 'text') {
                    $content .= $block['text'];
                }
            }
        }

        return [
            'success' => true,
            'content' => $content,
            'raw_content' => $data['content'] ?? [],
            'error' => null,
            'stop_reason' => $data['stop_reason'] ?? null,
            'usage' => $data['usage'] ?? null
        ];
    }

    public function setModel($model) { $this->model = $model; }
    public function setMaxTokens($max_tokens) { $this->max_tokens = $max_tokens; }
    public function getModel() { return $this->model; }
}
