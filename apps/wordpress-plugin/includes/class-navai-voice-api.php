<?php

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Navai_Voice_API', false)) {
    return;
}

require_once __DIR__ . '/traits/trait-navai-voice-api-helpers.php';

class Navai_Voice_API
{
    private Navai_Voice_Settings $settings;
    private ?Navai_Voice_Guardrail_Service $guardrailService = null;
    private ?Navai_Voice_Approval_Service $approvalService = null;
    private ?Navai_Voice_Trace_Service $traceService = null;
    private ?Navai_Voice_Session_Service $sessionService = null;
    private ?Navai_Voice_Agent_Service $agentService = null;
    private ?Navai_Voice_MCP_Service $mcpService = null;
    private const OPENAI_CLIENT_SECRETS_URL = 'https://api.openai.com/v1/realtime/client_secrets';
    use Navai_Voice_API_Helpers_Trait;

    public function __construct(Navai_Voice_Settings $settings)
    {
        $this->settings = $settings;
        if (class_exists('Navai_Voice_Guardrail_Service', false)) {
            $this->guardrailService = new Navai_Voice_Guardrail_Service();
        }
        if (class_exists('Navai_Voice_Approval_Service', false)) {
            $this->approvalService = new Navai_Voice_Approval_Service();
        }
        if (class_exists('Navai_Voice_Trace_Service', false)) {
            $this->traceService = new Navai_Voice_Trace_Service();
        }
        if (class_exists('Navai_Voice_Session_Service', false)) {
            $this->sessionService = new Navai_Voice_Session_Service();
        }
        if (class_exists('Navai_Voice_Agent_Service', false)) {
            $this->agentService = new Navai_Voice_Agent_Service();
        }
        if (class_exists('Navai_Voice_MCP_Service', false)) {
            $this->mcpService = new Navai_Voice_MCP_Service();
        }
    }

    public function register_routes(): void
    {
        register_rest_route(
            'navai/v1',
            '/realtime/client-secret',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_client_secret'],
                'permission_callback' => [$this, 'can_create_client_secret'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/speech/synthesize',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'synthesize_speech'],
                'permission_callback' => [$this, 'can_create_client_secret'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/functions',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_functions'],
                'permission_callback' => [$this, 'can_access_functions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/routes',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_routes'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'navai/v1',
            '/functions/execute',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'execute_function'],
                'permission_callback' => [$this, 'can_access_functions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/functions/test',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'test_function'],
                'permission_callback' => [$this, 'can_manage_functions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/plugin-functions/upsert',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'upsert_plugin_function_setting'],
                'permission_callback' => [$this, 'can_manage_functions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/plugin-functions/(?P<id>[A-Za-z0-9_-]+)',
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_plugin_function_setting'],
                'permission_callback' => [$this, 'can_manage_functions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/settings',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'update_admin_settings'],
                'permission_callback' => [$this, 'can_manage_settings'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/guardrails',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'list_guardrails'],
                    'permission_callback' => [$this, 'can_manage_guardrails'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_guardrail'],
                    'permission_callback' => [$this, 'can_manage_guardrails'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/guardrails/(?P<id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'update_guardrail'],
                    'permission_callback' => [$this, 'can_manage_guardrails'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete_guardrail'],
                    'permission_callback' => [$this, 'can_manage_guardrails'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/guardrails/test',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'test_guardrail_match'],
                'permission_callback' => [$this, 'can_manage_guardrails'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/approvals',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_approvals'],
                'permission_callback' => [$this, 'can_manage_approvals'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/approvals/(?P<id>\d+)/approve',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'approve_approval'],
                'permission_callback' => [$this, 'can_manage_approvals'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/approvals/(?P<id>\d+)/reject',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'reject_approval'],
                'permission_callback' => [$this, 'can_manage_approvals'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/traces',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_traces'],
                'permission_callback' => [$this, 'can_manage_traces'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/traces/(?P<trace_id>[a-zA-Z0-9-]+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_trace'],
                'permission_callback' => [$this, 'can_manage_traces'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/sessions',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'list_sessions'],
                    'permission_callback' => [$this, 'can_manage_sessions'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'store_session_messages'],
                    'permission_callback' => [$this, 'can_write_session_events'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/statistics/usage',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_usage_statistics'],
                'permission_callback' => [$this, 'can_manage_sessions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/sessions/cleanup',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'cleanup_sessions'],
                'permission_callback' => [$this, 'can_manage_sessions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/sessions/(?P<id>\d+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_session'],
                'permission_callback' => [$this, 'can_manage_sessions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/sessions/(?P<id>\d+)/messages',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_session_messages'],
                'permission_callback' => [$this, 'can_manage_sessions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/sessions/(?P<id>\d+)/clear',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'clear_session'],
                'permission_callback' => [$this, 'can_manage_sessions'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/agents',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'list_agents'],
                    'permission_callback' => [$this, 'can_manage_agents'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_agent'],
                    'permission_callback' => [$this, 'can_manage_agents'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/agents/(?P<id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'update_agent'],
                    'permission_callback' => [$this, 'can_manage_agents'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete_agent'],
                    'permission_callback' => [$this, 'can_manage_agents'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/agents/handoffs',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'list_agent_handoffs'],
                    'permission_callback' => [$this, 'can_manage_agents'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_agent_handoff'],
                    'permission_callback' => [$this, 'can_manage_agents'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/agents/handoffs/(?P<id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'update_agent_handoff'],
                    'permission_callback' => [$this, 'can_manage_agents'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete_agent_handoff'],
                    'permission_callback' => [$this, 'can_manage_agents'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/mcp/servers',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'list_mcp_servers'],
                    'permission_callback' => [$this, 'can_manage_mcp'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_mcp_server'],
                    'permission_callback' => [$this, 'can_manage_mcp'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/mcp/servers/(?P<id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'update_mcp_server'],
                    'permission_callback' => [$this, 'can_manage_mcp'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete_mcp_server'],
                    'permission_callback' => [$this, 'can_manage_mcp'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/mcp/servers/(?P<id>\d+)/health',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'health_check_mcp_server'],
                'permission_callback' => [$this, 'can_manage_mcp'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/mcp/servers/(?P<id>\d+)/tools',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_mcp_server_tools'],
                'permission_callback' => [$this, 'can_manage_mcp'],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/mcp/policies',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'list_mcp_policies'],
                    'permission_callback' => [$this, 'can_manage_mcp'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_mcp_policy'],
                    'permission_callback' => [$this, 'can_manage_mcp'],
                ],
            ]
        );

        register_rest_route(
            'navai/v1',
            '/mcp/policies/(?P<id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'update_mcp_policy'],
                    'permission_callback' => [$this, 'can_manage_mcp'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete_mcp_policy'],
                    'permission_callback' => [$this, 'can_manage_mcp'],
                ],
            ]
        );
    }

    public function can_create_client_secret(WP_REST_Request $request): bool
    {
        $settings = $this->settings->get_settings();
        if (!empty($settings['allow_public_client_secret'])) {
            return true;
        }

        return current_user_can('manage_options');
    }

    public function can_access_functions(WP_REST_Request $request): bool
    {
        $settings = $this->settings->get_settings();
        if (!empty($settings['allow_public_functions'])) {
            return true;
        }

        return current_user_can('manage_options');
    }

    public function can_manage_functions(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public function can_manage_guardrails(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public function can_manage_settings(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public function can_manage_approvals(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public function can_manage_traces(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public function can_manage_sessions(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public function can_manage_agents(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public function can_manage_mcp(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public function can_write_session_events(WP_REST_Request $request): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $settings = $this->settings->get_settings();
        $publicSecrets = !empty($settings['allow_public_client_secret']);
        $publicFunctions = !empty($settings['allow_public_functions']);
        return $publicSecrets || $publicFunctions;
    }

    public function create_client_secret(WP_REST_Request $request)
    {
        if (!$this->check_rate_limit()) {
            return new WP_Error(
                'navai_rate_limit',
                'Too many requests. Try again in a moment.',
                ['status' => 429]
            );
        }

        $settings = $this->settings->get_runtime_settings();
        $apiKey = trim((string) ($settings['openai_api_key'] ?? ''));
        $speechProvider = $this->resolve_speech_provider($settings);

        if ($apiKey === '') {
            return new WP_Error(
                'navai_missing_api_key',
                'Missing OpenAI API key in NAVAI settings.',
                ['status' => 500]
            );
        }

        if ($speechProvider === 'elevenlabs') {
            $speechSettingsError = $this->validate_elevenlabs_runtime_settings($settings);
            if ($speechSettingsError instanceof WP_Error) {
                return $speechSettingsError;
            }
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $sessionContext = $this->resolve_session_context_for_input($input);

        $model = $this->read_input_or_setting($input, 'model', (string) $settings['default_model']);
        $voice = $this->read_input_or_setting($input, 'voice', (string) $settings['default_voice']);
        $baseInstructions = $this->read_input_or_setting(
            $input,
            'instructions',
            (string) $settings['default_instructions']
        );
        $language = $this->read_input_or_setting($input, 'language', (string) $settings['default_language']);
        $voiceAccent = $this->read_input_or_setting($input, 'voiceAccent', (string) $settings['default_voice_accent']);
        $voiceTone = $this->read_input_or_setting($input, 'voiceTone', (string) $settings['default_voice_tone']);

        $resolvedClientAgent = null;
        if ($this->agentService && $this->should_enforce_agents()) {
            $agentRuntime = $this->resolve_agent_runtime_for_tool(
                $request,
                '',
                [],
                [
                    'session_id' => isset($sessionContext['session_id']) && is_numeric($sessionContext['session_id'])
                        ? (int) $sessionContext['session_id']
                        : 0,
                    'session' => is_array($sessionContext['session'] ?? null) ? $sessionContext['session'] : null,
                ]
            );
            if (!empty($agentRuntime['enabled']) && is_array($agentRuntime['agent'] ?? null)) {
                $resolvedClientAgent = $agentRuntime['agent'];
                $agentInstructions = trim((string) ($resolvedClientAgent['instructions_text'] ?? ''));
                if ($agentInstructions !== '') {
                    $baseInstructions = trim($baseInstructions);
                    if ($baseInstructions !== '') {
                        $baseInstructions .= "\n\n";
                    }
                    $baseInstructions .= "Specialist agent instructions:\n" . $agentInstructions;
                }
            }
        }

        $ttl = (int) ($settings['client_secret_ttl'] ?? 600);
        if ($ttl < 10 || $ttl > 7200) {
            $ttl = 600;
        }

        $sessionPayload = [
            'type' => 'realtime',
            'model' => $model,
            'instructions' => $this->build_session_instructions(
                $baseInstructions,
                $language,
                $voiceAccent,
                $voiceTone
            ),
        ];

        if ($speechProvider === 'elevenlabs') {
            $sessionPayload['output_modalities'] = ['text'];
        } else {
            $sessionPayload['audio'] = [
                'output' => [
                    'voice' => $voice,
                ],
            ];
        }

        $payload = [
            'expires_after' => [
                'anchor' => 'created_at',
                'seconds' => $ttl,
            ],
            'session' => $sessionPayload,
        ];

        $response = wp_remote_post(
            self::OPENAI_CLIENT_SECRETS_URL,
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('navai_openai_error', $response->get_error_message(), ['status' => 500]);
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : $body;
            return new WP_Error(
                'navai_openai_http_error',
                sprintf('OpenAI client_secrets failed (%d): %s', $statusCode, $message),
                ['status' => 502]
            );
        }

        if (!is_array($data) || empty($data['value']) || !is_string($data['value'])) {
            return new WP_Error('navai_invalid_openai_response', 'Invalid response from OpenAI.', ['status' => 502]);
        }

        if (!empty($sessionContext['session_id']) && $this->sessionService) {
            $contextUpdate = [
                'current_model' => $model,
                'last_client_secret_issued_at' => current_time('mysql'),
            ];
            if (is_array($resolvedClientAgent)) {
                $contextUpdate['active_agent_key'] = sanitize_text_field((string) ($resolvedClientAgent['agent_key'] ?? ''));
                $contextUpdate['active_agent_name'] = sanitize_text_field((string) ($resolvedClientAgent['name'] ?? ''));
            }

            try {
                $this->sessionService->update_session_context((int) $sessionContext['session_id'], $contextUpdate);
            } catch (Throwable $error) {
                unset($error);
            }
        }

        if (!empty($sessionContext['session_id'])) {
            $this->log_session_message(
                (string) ($sessionContext['session_key'] ?? ''),
                [
                    'direction' => 'system',
                    'message_type' => 'event',
                    'content_text' => 'Realtime client secret issued.',
                    'content_json' => [
                        'type' => 'client_secret_issued',
                        'model' => $model,
                        'voice' => $speechProvider === 'openai' ? $voice : null,
                        'speech_provider' => $speechProvider,
                        'expires_at' => isset($data['expires_at']) ? (int) $data['expires_at'] : null,
                    ],
                ]
            );
        }

        return rest_ensure_response(
            [
                'value' => $data['value'],
                'expires_at' => isset($data['expires_at']) ? (int) $data['expires_at'] : null,
                'session' => [
                    'enabled' => !empty($sessionContext['enabled']),
                    'persisted' => !empty($sessionContext['persisted']),
                    'id' => isset($sessionContext['session_id']) && is_numeric($sessionContext['session_id'])
                        ? (int) $sessionContext['session_id']
                        : null,
                    'key' => isset($sessionContext['session_key']) ? (string) $sessionContext['session_key'] : '',
                ],
                'speech' => [
                    'provider' => $speechProvider,
                ],
                'agent' => is_array($resolvedClientAgent)
                    ? [
                        'id' => isset($resolvedClientAgent['id']) ? (int) $resolvedClientAgent['id'] : null,
                        'agent_key' => (string) ($resolvedClientAgent['agent_key'] ?? ''),
                        'name' => (string) ($resolvedClientAgent['name'] ?? ''),
                    ]
                    : null,
            ]
        );
    }

    public function synthesize_speech(WP_REST_Request $request)
    {
        if (!$this->check_rate_limit()) {
            return new WP_Error(
                'navai_rate_limit',
                'Too many requests. Try again in a moment.',
                ['status' => 429]
            );
        }

        $settings = $this->settings->get_runtime_settings();
        if ($this->resolve_speech_provider($settings) !== 'elevenlabs') {
            return new WP_Error(
                'navai_speech_provider_disabled',
                'ElevenLabs speech is not enabled for this site.',
                ['status' => 400]
            );
        }

        $speechSettingsError = $this->validate_elevenlabs_runtime_settings($settings);
        if ($speechSettingsError instanceof WP_Error) {
            return $speechSettingsError;
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $text = isset($input['text']) && is_string($input['text']) ? trim($input['text']) : '';
        if ($text === '') {
            return new WP_Error(
                'navai_missing_speech_text',
                'Missing text to synthesize.',
                ['status' => 400]
            );
        }

        $synthesizeRequest = $this->build_elevenlabs_synthesize_request($settings, $text);
        $expectedMimeType = $this->infer_audio_mime_type_from_output_format($settings);
        $response = wp_remote_post(
            $synthesizeRequest['url'],
            [
                'timeout' => 20,
                'headers' => [
                    'xi-api-key' => trim((string) ($settings['elevenlabs_api_key'] ?? '')),
                    'Accept' => $expectedMimeType,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($synthesizeRequest['body']),
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('navai_elevenlabs_error', $response->get_error_message(), ['status' => 500]);
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorPayload = json_decode($body, true);
            $message = is_array($errorPayload) && isset($errorPayload['detail']['message'])
                ? (string) $errorPayload['detail']['message']
                : (is_array($errorPayload) && isset($errorPayload['message'])
                    ? (string) $errorPayload['message']
                    : $body);

            return new WP_Error(
                'navai_elevenlabs_http_error',
                sprintf('ElevenLabs synthesis failed (%d): %s', $statusCode, $message),
                ['status' => 502]
            );
        }

        if ($body === '') {
            return new WP_Error(
                'navai_invalid_elevenlabs_response',
                'ElevenLabs returned an empty audio payload.',
                ['status' => 502]
            );
        }

        $mimeType = trim((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($mimeType === '') {
            $mimeType = $expectedMimeType;
        }

        return rest_ensure_response(
            [
                'provider' => 'elevenlabs',
                'mimeType' => $mimeType,
                'audioBase64' => base64_encode($body),
            ]
        );
    }

    public function list_functions(WP_REST_Request $request)
    {
        $registry = $this->get_functions_registry();

        $items = [];
        foreach ($registry['ordered'] as $item) {
            $metadata = $this->normalize_function_metadata($item);
            $items[] = [
                'name' => $item['name'],
                'description' => $item['description'],
                'source' => $item['source'],
                'metadata' => [
                    'requires_approval' => $metadata['requires_approval'],
                    'timeout_seconds' => $metadata['timeout_seconds'],
                    'execution_scope' => $metadata['execution_scope'],
                    'retries' => $metadata['retries'],
                    'argument_schema' => $metadata['argument_schema'],
                ],
            ];
        }

        return rest_ensure_response(
            [
                'items' => $items,
                'warnings' => $registry['warnings'],
            ]
        );
    }

    public function list_routes(WP_REST_Request $request)
    {
        $routes = $this->build_allowed_routes_catalog();
        $response = new WP_REST_Response(
            [
                'items' => $routes,
                'count' => count($routes),
            ],
            200
        );
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('X-NAVAI-Routes-Count', (string) count($routes));

        return $response;
    }

    public function execute_function(WP_REST_Request $request)
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $sessionContext = $this->resolve_session_context_for_input($input);

        $functionName = isset($input['function_name']) ? strtolower(trim((string) $input['function_name'])) : '';
        if ($functionName === '') {
            return new WP_Error('navai_invalid_function_name', 'function_name is required.', ['status' => 400]);
        }

        $payload = isset($input['payload']) && is_array($input['payload']) ? $input['payload'] : [];
        $registry = $this->get_functions_registry();

        if (!isset($registry['by_name'][$functionName])) {
            return new WP_REST_Response(
                [
                    'error' => 'Unknown or disallowed function.',
                    'available_functions' => array_map(
                        static fn(array $item): string => $item['name'],
                        $registry['ordered']
                    ),
                ],
                404
            );
        }

        /** @var array<string, mixed> $definition */
        $definition = $registry['by_name'][$functionName];
        return $this->execute_function_definition(
            $request,
            $functionName,
            $definition,
            $payload,
            [
                'session_id' => isset($sessionContext['session_id']) && is_numeric($sessionContext['session_id'])
                    ? (int) $sessionContext['session_id']
                    : null,
                'session_key' => isset($sessionContext['session_key']) ? (string) $sessionContext['session_key'] : '',
                'session' => is_array($sessionContext['session'] ?? null) ? $sessionContext['session'] : null,
            ]
        );
    }

    public function test_function(WP_REST_Request $request)
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $functionInput = isset($input['function']) && is_array($input['function'])
            ? $input['function']
            : [];
        $payload = isset($input['payload']) && is_array($input['payload']) ? $input['payload'] : [];

        $functionName = $this->normalize_function_name((string) ($functionInput['function_name'] ?? ''));
        if ($functionName === '') {
            return new WP_Error('navai_invalid_function_name', 'function_name is required.', ['status' => 400]);
        }

        $functionCode = isset($functionInput['function_code']) && is_string($functionInput['function_code'])
            ? trim($functionInput['function_code'])
            : '';
        if ($functionCode === '') {
            return new WP_Error('navai_invalid_function_code', 'function_code is required.', ['status' => 400]);
        }

        $argumentSchema = $this->normalize_function_argument_schema($functionInput['argument_schema'] ?? null);
        if (is_wp_error($argumentSchema)) {
            return $argumentSchema;
        }

        $pluginKey = isset($functionInput['plugin_key']) ? sanitize_text_field((string) $functionInput['plugin_key']) : 'wp-core';
        if ($pluginKey === '') {
            $pluginKey = 'wp-core';
        }

        $role = sanitize_key((string) ($functionInput['role'] ?? 'administrator'));
        if ($role === '') {
            $role = 'administrator';
        }

        $timeoutSeconds = is_numeric($functionInput['timeout_seconds'] ?? null) ? (int) $functionInput['timeout_seconds'] : 0;
        if ($timeoutSeconds < 0) {
            $timeoutSeconds = 0;
        }
        if ($timeoutSeconds > 600) {
            $timeoutSeconds = 600;
        }

        $retries = is_numeric($functionInput['retries'] ?? null) ? (int) $functionInput['retries'] : 0;
        if ($retries < 0) {
            $retries = 0;
        }
        if ($retries > 5) {
            $retries = 5;
        }

        $executionScope = sanitize_key((string) ($functionInput['execution_scope'] ?? 'both'));
        if (!in_array($executionScope, ['frontend', 'admin', 'both'], true)) {
            $executionScope = 'both';
        }

        $catalog = $this->build_allowed_plugins_catalog();
        $actionsRegistry = $this->get_plugin_actions_registry();
        $description = sanitize_text_field((string) ($functionInput['description'] ?? ''));
        if ($description === '') {
            $description = 'Custom plugin function configured in NAVAI dashboard.';
        }

        $testItem = [
            'id' => sanitize_key((string) ($functionInput['id'] ?? '')),
            'plugin_key' => $pluginKey,
            'plugin_label' => sanitize_text_field((string) ($functionInput['plugin_label'] ?? '')),
            'role' => $role,
            'function_name' => $functionName,
            'function_code' => $functionCode,
            'description' => $description,
            'requires_approval' => !empty($functionInput['requires_approval']),
            'timeout_seconds' => $timeoutSeconds,
            'execution_scope' => $executionScope,
            'retries' => $retries,
            'argument_schema' => $argumentSchema,
        ];

        $definitions = $this->build_custom_plugin_function_definitions([$testItem], $catalog, $actionsRegistry);
        if (count($definitions) === 0) {
            return new WP_Error(
                'navai_test_function_unavailable',
                'Unable to build test function. Check plugin selection and function code.',
                ['status' => 400]
            );
        }

        $definition = $definitions[0];
        return $this->execute_function_definition(
            $request,
            $functionName,
            $definition,
            $payload,
            [
                'skip_approval' => true,
                'skip_session' => true,
            ]
        );
    }

    public function upsert_plugin_function_setting(WP_REST_Request $request)
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $functionInput = is_array($input['function'] ?? null) ? $input['function'] : [];
        $selected = !array_key_exists('selected', $input) || !empty($input['selected']);

        $rowId = sanitize_key((string) ($functionInput['id'] ?? ''));
        if ($rowId === '') {
            $rowId = sanitize_key('fn_' . wp_generate_password(12, false, false));
        }
        if ($rowId === '') {
            return new WP_Error('navai_invalid_plugin_function_id', 'Invalid plugin function id.', ['status' => 400]);
        }

        $pluginKey = sanitize_text_field((string) ($functionInput['plugin_key'] ?? 'wp-core'));
        if ($pluginKey === '') {
            $pluginKey = 'wp-core';
        }

        $role = sanitize_key((string) ($functionInput['role'] ?? ''));
        if ($role === '') {
            return new WP_Error('navai_invalid_plugin_function_role', 'role is required.', ['status' => 400]);
        }

        $functionCode = isset($functionInput['function_code']) && is_string($functionInput['function_code'])
            ? trim($functionInput['function_code'])
            : '';
        if ($functionCode === '') {
            return new WP_Error('navai_invalid_plugin_function_code', 'function_code is required.', ['status' => 400]);
        }

        $currentSettings = $this->settings->get_settings();
        $currentFunctions = is_array($currentSettings['plugin_custom_functions'] ?? null)
            ? array_values(array_filter($currentSettings['plugin_custom_functions'], 'is_array'))
            : [];

        $functionName = sanitize_key((string) ($functionInput['function_name'] ?? ''));
        if ($functionName === '') {
            $functionName = sanitize_key('navai_custom_' . substr($rowId, 0, 20));
        }

        $timeoutSeconds = is_numeric($functionInput['timeout_seconds'] ?? null) ? (int) $functionInput['timeout_seconds'] : 0;
        if ($timeoutSeconds < 0) {
            $timeoutSeconds = 0;
        }
        if ($timeoutSeconds > 600) {
            $timeoutSeconds = 600;
        }

        $executionScope = sanitize_key((string) ($functionInput['execution_scope'] ?? 'both'));
        if (!in_array($executionScope, ['frontend', 'admin', 'both'], true)) {
            $executionScope = 'both';
        }

        $retries = is_numeric($functionInput['retries'] ?? null) ? (int) $functionInput['retries'] : 0;
        if ($retries < 0) {
            $retries = 0;
        }
        if ($retries > 5) {
            $retries = 5;
        }

        $upsertRow = [
            'id' => $rowId,
            'plugin_key' => $pluginKey,
            'plugin_label' => sanitize_text_field((string) ($functionInput['plugin_label'] ?? '')),
            'role' => $role,
            'function_name' => $functionName,
            'function_code' => $functionCode,
            'description' => sanitize_text_field((string) ($functionInput['description'] ?? '')),
            'requires_approval' => !empty($functionInput['requires_approval']),
            'timeout_seconds' => $timeoutSeconds,
            'execution_scope' => $executionScope,
            'retries' => $retries,
            'argument_schema_json' => is_string($functionInput['argument_schema_json'] ?? null)
                ? trim((string) $functionInput['argument_schema_json'])
                : '',
        ];

        $replaced = false;
        foreach ($currentFunctions as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $existingId = sanitize_key((string) ($row['id'] ?? ''));
            if ($existingId !== '' && $existingId === $rowId) {
                $currentFunctions[$index] = $upsertRow;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $currentFunctions[] = $upsertRow;
        }

        $selectedKeys = is_array($currentSettings['allowed_plugin_function_keys'] ?? null)
            ? array_values(array_filter(array_map(static function ($value): string {
                return sanitize_text_field((string) $value);
            }, $currentSettings['allowed_plugin_function_keys']), static function ($value): bool {
                return $value !== '';
            }))
            : [];
        $targetKey = 'pluginfn:' . $rowId;
        $selectedKeys = array_values(array_filter($selectedKeys, static function ($value) use ($targetKey): bool {
            return $value !== $targetKey;
        }));
        if ($selected) {
            $selectedKeys[] = $targetKey;
        }

        $nextSettingsSource = $currentSettings;
        $nextSettingsSource['plugin_custom_functions'] = array_values($currentFunctions);
        $nextSettingsSource['allowed_plugin_function_keys'] = array_values(array_unique($selectedKeys));
        $nextSettingsSource['active_tab'] = 'plugins';

        $sanitizedSettings = $this->settings->sanitize_settings($nextSettingsSource);
        if (!update_option(Navai_Voice_Settings::OPTION_KEY, $sanitizedSettings)) {
            // Continue even when WP returns false for "no changes"; we still return normalized state.
        }

        $savedSettings = $this->settings->get_settings();
        $state = $this->build_plugin_functions_settings_state($savedSettings);
        $savedItems = is_array($state['plugin_custom_functions'] ?? null) ? $state['plugin_custom_functions'] : [];
        $savedItem = null;
        foreach ($savedItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (sanitize_key((string) ($item['id'] ?? '')) === $rowId) {
                $savedItem = $item;
                break;
            }
        }

        if (!is_array($savedItem)) {
            return new WP_Error(
                'navai_plugin_function_save_failed',
                'Plugin function could not be saved. Check role/plugin values.',
                ['status' => 400]
            );
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'saved_id' => $rowId,
                'item' => $savedItem,
                'state' => $state,
            ]
        );
    }

    public function delete_plugin_function_setting(WP_REST_Request $request)
    {
        $rowId = sanitize_key((string) $request->get_param('id'));
        if ($rowId === '') {
            return new WP_Error('navai_invalid_plugin_function_id', 'Invalid plugin function id.', ['status' => 400]);
        }

        $currentSettings = $this->settings->get_settings();
        $currentFunctions = is_array($currentSettings['plugin_custom_functions'] ?? null)
            ? array_values(array_filter($currentSettings['plugin_custom_functions'], 'is_array'))
            : [];

        $filteredFunctions = [];
        $deleted = false;
        foreach ($currentFunctions as $row) {
            if (!is_array($row)) {
                continue;
            }

            $existingId = sanitize_key((string) ($row['id'] ?? ''));
            if ($existingId !== '' && $existingId === $rowId) {
                $deleted = true;
                continue;
            }

            $filteredFunctions[] = $row;
        }

        if (!$deleted) {
            return new WP_Error('navai_plugin_function_not_found', 'Plugin function not found.', ['status' => 404]);
        }

        $targetKey = 'pluginfn:' . $rowId;
        $selectedKeys = is_array($currentSettings['allowed_plugin_function_keys'] ?? null)
            ? array_values(array_filter(array_map(static function ($value): string {
                return sanitize_text_field((string) $value);
            }, $currentSettings['allowed_plugin_function_keys']), static function ($value) use ($targetKey): bool {
                return $value !== '' && $value !== $targetKey;
            }))
            : [];

        $nextSettingsSource = $currentSettings;
        $nextSettingsSource['plugin_custom_functions'] = array_values($filteredFunctions);
        $nextSettingsSource['allowed_plugin_function_keys'] = array_values(array_unique($selectedKeys));
        $nextSettingsSource['active_tab'] = 'plugins';

        $sanitizedSettings = $this->settings->sanitize_settings($nextSettingsSource);
        if (!update_option(Navai_Voice_Settings::OPTION_KEY, $sanitizedSettings)) {
            // Continue even when WP reports no changes; we still return the normalized state.
        }

        $savedSettings = $this->settings->get_settings();
        $state = $this->build_plugin_functions_settings_state($savedSettings);

        return rest_ensure_response([
            'ok' => true,
            'deleted_id' => $rowId,
            'state' => $state,
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{plugin_custom_functions: array<int, array<string, mixed>>, allowed_plugin_function_keys: array<int, string>}
     */
    private function build_plugin_functions_settings_state(array $settings): array
    {
        $functions = [];
        if (is_array($settings['plugin_custom_functions'] ?? null)) {
            foreach ($settings['plugin_custom_functions'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $functions[] = $row;
            }
        }

        $allowedKeys = [];
        if (is_array($settings['allowed_plugin_function_keys'] ?? null)) {
            foreach ($settings['allowed_plugin_function_keys'] as $value) {
                $key = sanitize_text_field((string) $value);
                if ($key === '') {
                    continue;
                }
                $allowedKeys[] = $key;
            }
        }

        return [
            'plugin_custom_functions' => array_values($functions),
            'allowed_plugin_function_keys' => array_values(array_unique($allowedKeys)),
        ];
    }

    public function update_admin_settings(WP_REST_Request $request)
    {
        $settingsInput = $request->get_param(Navai_Voice_Settings::OPTION_KEY);
        if (!is_array($settingsInput)) {
            $json = $request->get_json_params();
            if (is_array($json) && isset($json[Navai_Voice_Settings::OPTION_KEY]) && is_array($json[Navai_Voice_Settings::OPTION_KEY])) {
                $settingsInput = $json[Navai_Voice_Settings::OPTION_KEY];
            } elseif (is_array($json) && isset($json['settings']) && is_array($json['settings'])) {
                $settingsInput = $json['settings'];
            } else {
                $settingsInput = [];
            }
        }

        $settingsInput['active_tab'] = isset($settingsInput['active_tab'])
            ? sanitize_key((string) $settingsInput['active_tab'])
            : 'navigation';

        $sanitizedSettings = $this->settings->sanitize_settings($settingsInput);
        if (!update_option(Navai_Voice_Settings::OPTION_KEY, $sanitizedSettings)) {
            // WordPress returns false when values are unchanged; keep response successful.
        }

        $savedSettings = $this->settings->get_settings();

        return rest_ensure_response([
            'ok' => true,
            'saved' => [
                'dashboard_language' => isset($savedSettings['dashboard_language']) ? (string) $savedSettings['dashboard_language'] : 'en',
                'active_tab' => isset($savedSettings['active_tab']) ? (string) $savedSettings['active_tab'] : 'navigation',
            ],
        ]);
    }

    public function list_guardrails(WP_REST_Request $request)
    {
        $service = $this->guardrailService;
        if (!$service) {
            return new WP_Error('navai_guardrails_unavailable', 'Guardrails service is unavailable.', ['status' => 500]);
        }

        $filters = [];
        $scope = sanitize_key((string) $request->get_param('scope'));
        if ($scope !== '') {
            $filters['scope'] = $scope;
        }
        if ($request->has_param('enabled')) {
            $enabledRaw = strtolower(trim((string) $request->get_param('enabled')));
            $filters['enabled'] = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);
        }

        $items = $service->list_rules($filters);
        return rest_ensure_response(
            [
                'items' => $items,
                'count' => count($items),
            ]
        );
    }

    public function create_guardrail(WP_REST_Request $request)
    {
        $service = $this->guardrailService;
        if (!$service) {
            return new WP_Error('navai_guardrails_unavailable', 'Guardrails service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $created = $service->create_rule($input);
        if (is_wp_error($created)) {
            return $created;
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'item' => $created,
            ],
            201
        );
    }

    public function update_guardrail(WP_REST_Request $request)
    {
        $service = $this->guardrailService;
        if (!$service) {
            return new WP_Error('navai_guardrails_unavailable', 'Guardrails service is unavailable.', ['status' => 500]);
        }

        $id = (int) $request['id'];
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $updated = $service->update_rule($id, $input);
        if (is_wp_error($updated)) {
            return $updated;
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'item' => $updated,
            ]
        );
    }

    public function delete_guardrail(WP_REST_Request $request)
    {
        $service = $this->guardrailService;
        if (!$service) {
            return new WP_Error('navai_guardrails_unavailable', 'Guardrails service is unavailable.', ['status' => 500]);
        }

        $id = (int) $request['id'];
        $deleted = $service->delete_rule($id);

        if (!$deleted) {
            return new WP_Error('navai_guardrail_delete_failed', 'Failed to delete guardrail rule.', ['status' => 404]);
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'id' => $id,
            ]
        );
    }

    public function test_guardrail_match(WP_REST_Request $request)
    {
        $service = $this->guardrailService;
        if (!$service) {
            return new WP_Error('navai_guardrails_unavailable', 'Guardrails service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $scope = sanitize_key((string) ($input['scope'] ?? 'input'));
        $selectedRuleId = isset($input['rule_id']) ? (int) $input['rule_id'] : 0;
        $subject = [
            'function_name' => isset($input['function_name']) ? (string) $input['function_name'] : '',
            'function_source' => isset($input['function_source']) ? (string) $input['function_source'] : '',
            'payload' => isset($input['payload']) ? $input['payload'] : [],
            'result' => isset($input['result']) ? $input['result'] : null,
            'text' => isset($input['text']) ? (string) $input['text'] : '',
            'roles' => is_array($input['roles'] ?? null) ? $input['roles'] : $this->get_request_roles(),
        ];

        $evaluation = $service->evaluate($scope, $subject);
        if ($selectedRuleId > 0 && is_array($evaluation)) {
            $filteredMatches = [];
            $matches = is_array($evaluation['matches'] ?? null) ? $evaluation['matches'] : [];
            foreach ($matches as $match) {
                if (!is_array($match)) {
                    continue;
                }
                if ((int) ($match['id'] ?? 0) !== $selectedRuleId) {
                    continue;
                }
                $filteredMatches[] = $match;
            }

            $filteredWarnings = [];
            $blocked = false;
            foreach ($filteredMatches as $match) {
                $action = sanitize_key((string) ($match['action'] ?? ''));
                if ($action === 'warn') {
                    $filteredWarnings[] = $match;
                }
                if ($action === 'block') {
                    $blocked = true;
                }
            }

            $evaluation['matches'] = $filteredMatches;
            $evaluation['warnings'] = $filteredWarnings;
            $evaluation['matched_count'] = count($filteredMatches);
            $evaluation['blocked'] = $blocked;
            $evaluation['selected_rule_id'] = $selectedRuleId;
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'evaluation' => $evaluation,
            ]
        );
    }

    public function list_approvals(WP_REST_Request $request)
    {
        if (!$this->approvalService) {
            return new WP_Error('navai_approvals_unavailable', 'Approvals service is unavailable.', ['status' => 500]);
        }

        $filters = [];
        $status = sanitize_key((string) $request->get_param('status'));
        if ($status !== '') {
            $filters['status'] = $status;
        }
        $traceId = sanitize_text_field((string) $request->get_param('trace_id'));
        if ($traceId !== '') {
            $filters['trace_id'] = $traceId;
        }
        if ($request->has_param('limit')) {
            $filters['limit'] = (int) $request->get_param('limit');
        }

        $items = $this->approvalService->list_requests($filters);
        return rest_ensure_response(
            [
                'items' => $items,
                'count' => count($items),
            ]
        );
    }

    public function approve_approval(WP_REST_Request $request)
    {
        if (!$this->approvalService) {
            return new WP_Error('navai_approvals_unavailable', 'Approvals service is unavailable.', ['status' => 500]);
        }

        $approvalId = (int) $request['id'];
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $approval = $this->approvalService->get_request($approvalId);
        if (!is_array($approval)) {
            return new WP_Error('navai_approval_not_found', 'Approval request not found.', ['status' => 404]);
        }
        if (($approval['status'] ?? '') !== 'pending') {
            return new WP_Error('navai_approval_not_pending', 'Approval request is not pending.', ['status' => 409]);
        }

        $traceId = sanitize_text_field((string) ($approval['trace_id'] ?? ''));
        if ($traceId === '') {
            $traceId = wp_generate_uuid4();
        }
        $approvalSessionId = isset($approval['session_id']) && is_numeric($approval['session_id']) ? (int) $approval['session_id'] : 0;
        $approvalSessionKey = '';
        if ($approvalSessionId > 0 && $this->sessionService) {
            $approvalSession = $this->sessionService->get_session($approvalSessionId);
            if (is_array($approvalSession)) {
                $approvalSessionKey = sanitize_text_field((string) ($approvalSession['session_key'] ?? ''));
            }
        }

        $decisionNotes = isset($input['decision_notes']) ? (string) $input['decision_notes'] : '';
        $executeNow = !array_key_exists('execute_now', $input) || !empty($input['execute_now']);
        $executionResponse = null;
        $executionPayload = null;
        $executionError = '';

        if ($executeNow) {
            $registry = $this->get_functions_registry();
            $functionKey = strtolower(trim((string) ($approval['function_key'] ?? '')));
            if ($functionKey === '' || !isset($registry['by_name'][$functionKey])) {
                $executionError = 'Function is no longer available or allowed.';
            } else {
                /** @var array<string, mixed> $definition */
                $definition = $registry['by_name'][$functionKey];
                $approvalPayload = is_array($approval['payload'] ?? null) ? $approval['payload'] : [];
                $executionResponse = $this->execute_function_definition(
                    $request,
                    $functionKey,
                    $definition,
                    $approvalPayload,
                    [
                        'skip_approval' => true,
                        'trace_id' => $traceId,
                        'approval_id' => $approvalId,
                        'session_id' => $approvalSessionId > 0 ? $approvalSessionId : null,
                        'session_key' => $approvalSessionKey,
                    ]
                );
                $executionPayload = $this->extract_rest_response_data($executionResponse);
                if (is_array($executionPayload) && empty($executionPayload['ok']) && isset($executionPayload['error'])) {
                    $executionError = (string) $executionPayload['error'];
                }
            }
        }

        $approved = $this->approvalService->approve_request(
            $approvalId,
            [
                'approved_by_user_id' => get_current_user_id(),
                'decision_notes' => $decisionNotes,
                'result' => $executionPayload,
                'error_message' => $executionError !== '' ? $executionError : null,
            ]
        );
        if (is_wp_error($approved)) {
            return $approved;
        }

        $this->log_trace_event(
            'approval_resolved',
            [
                'session_id' => $approvalSessionId > 0 ? $approvalSessionId : null,
                'trace_id' => $traceId,
                'approval_id' => $approvalId,
                'status' => 'approved',
                'function_name' => (string) ($approval['function_key'] ?? ''),
                'function_source' => (string) ($approval['function_source'] ?? ''),
            ],
            'info'
        );
        if ($approvalSessionId > 0 && $approvalSessionKey !== '') {
            $this->log_session_message(
                $approvalSessionKey,
                [
                    'direction' => 'system',
                    'message_type' => 'event',
                    'content_text' => 'Admin approved pending tool execution.',
                    'content_json' => [
                        'approval_id' => $approvalId,
                        'status' => 'approved',
                        'function_name' => (string) ($approval['function_key'] ?? ''),
                    ],
                    'meta' => [
                        'trace_id' => $traceId,
                    ],
                ]
            );
        }
        do_action('navai_approval_resolved', $approvalId, 'approved', $approved);

        return rest_ensure_response(
            [
                'ok' => true,
                'item' => $approved,
                'execution' => $executionPayload,
            ]
        );
    }

    public function reject_approval(WP_REST_Request $request)
    {
        if (!$this->approvalService) {
            return new WP_Error('navai_approvals_unavailable', 'Approvals service is unavailable.', ['status' => 500]);
        }

        $approvalId = (int) $request['id'];
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }
        $approval = $this->approvalService->get_request($approvalId);
        if (!is_array($approval)) {
            return new WP_Error('navai_approval_not_found', 'Approval request not found.', ['status' => 404]);
        }

        $rejected = $this->approvalService->reject_request(
            $approvalId,
            [
                'approved_by_user_id' => get_current_user_id(),
                'decision_notes' => isset($input['decision_notes']) ? (string) $input['decision_notes'] : '',
                'error_message' => 'Rejected by admin approval workflow.',
            ]
        );
        if (is_wp_error($rejected)) {
            return $rejected;
        }

        $traceId = sanitize_text_field((string) ($approval['trace_id'] ?? ''));
        if ($traceId === '') {
            $traceId = wp_generate_uuid4();
        }
        $approvalSessionId = isset($approval['session_id']) && is_numeric($approval['session_id']) ? (int) $approval['session_id'] : 0;
        $approvalSessionKey = '';
        if ($approvalSessionId > 0 && $this->sessionService) {
            $approvalSession = $this->sessionService->get_session($approvalSessionId);
            if (is_array($approvalSession)) {
                $approvalSessionKey = sanitize_text_field((string) ($approvalSession['session_key'] ?? ''));
            }
        }
        $this->log_trace_event(
            'approval_resolved',
            [
                'session_id' => $approvalSessionId > 0 ? $approvalSessionId : null,
                'trace_id' => $traceId,
                'approval_id' => $approvalId,
                'status' => 'rejected',
                'function_name' => (string) ($approval['function_key'] ?? ''),
                'function_source' => (string) ($approval['function_source'] ?? ''),
            ],
            'warning'
        );
        if ($approvalSessionId > 0 && $approvalSessionKey !== '') {
            $this->log_session_message(
                $approvalSessionKey,
                [
                    'direction' => 'system',
                    'message_type' => 'event',
                    'content_text' => 'Admin rejected pending tool execution.',
                    'content_json' => [
                        'approval_id' => $approvalId,
                        'status' => 'rejected',
                        'function_name' => (string) ($approval['function_key'] ?? ''),
                    ],
                    'meta' => [
                        'trace_id' => $traceId,
                    ],
                ]
            );
        }
        do_action('navai_approval_resolved', $approvalId, 'rejected', $rejected);

        return rest_ensure_response(
            [
                'ok' => true,
                'item' => $rejected,
            ]
        );
    }

    public function list_traces(WP_REST_Request $request)
    {
        if (!$this->traceService) {
            return new WP_Error('navai_traces_unavailable', 'Trace service is unavailable.', ['status' => 500]);
        }

        $filters = [];
        $eventType = sanitize_key((string) $request->get_param('event_type'));
        if ($eventType !== '') {
            $filters['event_type'] = $eventType;
        }
        $severity = sanitize_key((string) $request->get_param('severity'));
        if ($severity !== '') {
            $filters['severity'] = $severity;
        }
        if ($request->has_param('limit')) {
            $filters['limit'] = (int) $request->get_param('limit');
        }

        $items = $this->traceService->list_traces($filters);
        return rest_ensure_response(
            [
                'items' => $items,
                'count' => count($items),
            ]
        );
    }

    public function get_trace(WP_REST_Request $request)
    {
        if (!$this->traceService) {
            return new WP_Error('navai_traces_unavailable', 'Trace service is unavailable.', ['status' => 500]);
        }

        $traceId = sanitize_text_field((string) ($request['trace_id'] ?? ''));
        if ($traceId === '') {
            return new WP_Error('navai_invalid_trace_id', 'trace_id is required.', ['status' => 400]);
        }

        $events = $this->traceService->get_trace_timeline($traceId, 500);
        return rest_ensure_response(
            [
                'trace_id' => $traceId,
                'events' => $events,
                'count' => count($events),
            ]
        );
    }

    public function list_sessions(WP_REST_Request $request)
    {
        if (!$this->sessionService) {
            return new WP_Error('navai_sessions_unavailable', 'Session service is unavailable.', ['status' => 500]);
        }

        $filters = [];
        $status = sanitize_key((string) $request->get_param('status'));
        if ($status !== '') {
            $filters['status'] = $status;
        }
        $search = sanitize_text_field((string) $request->get_param('search'));
        if ($search !== '') {
            $filters['search'] = $search;
        }
        if ($request->has_param('limit')) {
            $filters['limit'] = (int) $request->get_param('limit');
        }

        $items = $this->sessionService->list_sessions($filters);
        return rest_ensure_response(
            [
                'items' => $items,
                'count' => count($items),
                'memory_enabled' => $this->should_enforce_session_memory(),
            ]
        );
    }

    public function list_usage_statistics(WP_REST_Request $request)
    {
        if (!$this->sessionService) {
            return new WP_Error('navai_sessions_unavailable', 'Session service is unavailable.', ['status' => 500]);
        }

        $filters = [
            'date_from' => sanitize_text_field((string) $request->get_param('date_from')),
            'date_to' => sanitize_text_field((string) $request->get_param('date_to')),
            'model' => sanitize_text_field((string) $request->get_param('model')),
            'agent' => sanitize_text_field((string) $request->get_param('agent')),
        ];

        $stats = $this->sessionService->get_usage_statistics($filters);
        $stats['memory_enabled'] = $this->should_enforce_session_memory();

        return rest_ensure_response($stats);
    }

    public function get_session(WP_REST_Request $request)
    {
        if (!$this->sessionService) {
            return new WP_Error('navai_sessions_unavailable', 'Session service is unavailable.', ['status' => 500]);
        }

        $sessionId = (int) $request['id'];
        $item = $this->sessionService->get_session($sessionId);
        if (!is_array($item)) {
            return new WP_Error('navai_session_not_found', 'Session not found.', ['status' => 404]);
        }

        return rest_ensure_response(
            [
                'item' => $item,
            ]
        );
    }

    public function get_session_messages(WP_REST_Request $request)
    {
        if (!$this->sessionService) {
            return new WP_Error('navai_sessions_unavailable', 'Session service is unavailable.', ['status' => 500]);
        }

        $sessionId = (int) $request['id'];
        $session = $this->sessionService->get_session($sessionId);
        if (!is_array($session)) {
            return new WP_Error('navai_session_not_found', 'Session not found.', ['status' => 404]);
        }

        $limit = $request->has_param('limit') ? (int) $request->get_param('limit') : 500;
        $messages = $this->sessionService->get_session_messages($sessionId, $limit);

        return rest_ensure_response(
            [
                'session' => $session,
                'items' => $messages,
                'count' => count($messages),
            ]
        );
    }

    public function clear_session(WP_REST_Request $request)
    {
        if (!$this->sessionService) {
            return new WP_Error('navai_sessions_unavailable', 'Session service is unavailable.', ['status' => 500]);
        }

        $sessionId = (int) $request['id'];
        $cleared = $this->sessionService->clear_session($sessionId);
        if (is_wp_error($cleared)) {
            return $cleared;
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'result' => $cleared,
            ]
        );
    }

    public function cleanup_sessions(WP_REST_Request $request)
    {
        if (!$this->sessionService) {
            return new WP_Error('navai_sessions_unavailable', 'Session service is unavailable.', ['status' => 500]);
        }

        $limit = $request->has_param('limit') ? (int) $request->get_param('limit') : 250;
        $result = $this->sessionService->cleanup_retention($this->settings->get_settings(), $limit);

        return rest_ensure_response(
            [
                'ok' => true,
                'result' => $result,
            ]
        );
    }

    public function list_agents(WP_REST_Request $request)
    {
        if (!$this->agentService) {
            return new WP_Error('navai_agents_unavailable', 'Agent service is unavailable.', ['status' => 500]);
        }

        $filters = [];
        if ($request->has_param('enabled')) {
            $enabledRaw = strtolower(trim((string) $request->get_param('enabled')));
            $filters['enabled'] = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);
        }
        $search = sanitize_text_field((string) $request->get_param('search'));
        if ($search !== '') {
            $filters['search'] = $search;
        }
        if ($request->has_param('limit')) {
            $filters['limit'] = (int) $request->get_param('limit');
        }

        $items = $this->agentService->list_agents($filters);
        return rest_ensure_response(
            [
                'items' => $items,
                'count' => count($items),
                'agents_enabled' => $this->should_enforce_agents(),
            ]
        );
    }

    public function create_agent(WP_REST_Request $request)
    {
        if (!$this->agentService) {
            return new WP_Error('navai_agents_unavailable', 'Agent service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $created = $this->agentService->create_agent($input);
        if (is_wp_error($created)) {
            return $created;
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'item' => $created,
            ],
            201
        );
    }

    public function update_agent(WP_REST_Request $request)
    {
        if (!$this->agentService) {
            return new WP_Error('navai_agents_unavailable', 'Agent service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $updated = $this->agentService->update_agent((int) $request['id'], $input);
        if (is_wp_error($updated)) {
            return $updated;
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'item' => $updated,
            ]
        );
    }

    public function delete_agent(WP_REST_Request $request)
    {
        if (!$this->agentService) {
            return new WP_Error('navai_agents_unavailable', 'Agent service is unavailable.', ['status' => 500]);
        }

        $id = (int) $request['id'];
        $deleted = $this->agentService->delete_agent($id);
        if (!$deleted) {
            return new WP_Error('navai_agent_delete_failed', 'Failed to delete agent.', ['status' => 404]);
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'id' => $id,
            ]
        );
    }

    public function list_agent_handoffs(WP_REST_Request $request)
    {
        if (!$this->agentService) {
            return new WP_Error('navai_agents_unavailable', 'Agent service is unavailable.', ['status' => 500]);
        }

        $filters = [];
        if ($request->has_param('enabled')) {
            $enabledRaw = strtolower(trim((string) $request->get_param('enabled')));
            $filters['enabled'] = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);
        }
        if ($request->has_param('limit')) {
            $filters['limit'] = (int) $request->get_param('limit');
        }

        $items = $this->agentService->list_handoff_rules($filters);
        return rest_ensure_response(
            [
                'items' => $items,
                'count' => count($items),
            ]
        );
    }

    public function create_agent_handoff(WP_REST_Request $request)
    {
        if (!$this->agentService) {
            return new WP_Error('navai_agents_unavailable', 'Agent service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $created = $this->agentService->create_handoff_rule($input);
        if (is_wp_error($created)) {
            return $created;
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'item' => $created,
            ],
            201
        );
    }

    public function update_agent_handoff(WP_REST_Request $request)
    {
        if (!$this->agentService) {
            return new WP_Error('navai_agents_unavailable', 'Agent service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $updated = $this->agentService->update_handoff_rule((int) $request['id'], $input);
        if (is_wp_error($updated)) {
            return $updated;
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'item' => $updated,
            ]
        );
    }

    public function delete_agent_handoff(WP_REST_Request $request)
    {
        if (!$this->agentService) {
            return new WP_Error('navai_agents_unavailable', 'Agent service is unavailable.', ['status' => 500]);
        }

        $id = (int) $request['id'];
        $deleted = $this->agentService->delete_handoff_rule($id);
        if (!$deleted) {
            return new WP_Error('navai_handoff_delete_failed', 'Failed to delete handoff rule.', ['status' => 404]);
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'id' => $id,
            ]
        );
    }

    public function list_mcp_servers(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $filters = [];
        if ($request->has_param('enabled')) {
            $enabledRaw = strtolower(trim((string) $request->get_param('enabled')));
            $filters['enabled'] = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);
        }
        $search = sanitize_text_field((string) $request->get_param('search'));
        if ($search !== '') {
            $filters['search'] = $search;
        }
        if ($request->has_param('limit')) {
            $filters['limit'] = (int) $request->get_param('limit');
        }

        $items = $this->mcpService->list_servers($filters);
        return rest_ensure_response(
            [
                'items' => $items,
                'count' => count($items),
                'mcp_enabled' => $this->should_enforce_mcp(),
            ]
        );
    }

    public function create_mcp_server(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $created = $this->mcpService->create_server($input);
        if (is_wp_error($created)) {
            return $created;
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'item' => $created,
            ],
            201
        );
    }

    public function update_mcp_server(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $updated = $this->mcpService->update_server((int) $request['id'], $input);
        if (is_wp_error($updated)) {
            return $updated;
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'item' => $updated,
            ]
        );
    }

    public function delete_mcp_server(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $id = (int) $request['id'];
        $deleted = $this->mcpService->delete_server($id);
        if (!$deleted) {
            return new WP_Error('navai_mcp_server_delete_failed', 'Failed to delete MCP server.', ['status' => 404]);
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'id' => $id,
            ]
        );
    }

    public function health_check_mcp_server(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }
        $syncTools = true;
        if (array_key_exists('sync_tools', $input)) {
            if (is_bool($input['sync_tools'])) {
                $syncTools = (bool) $input['sync_tools'];
            } else {
                $syncRaw = strtolower(trim((string) $input['sync_tools']));
                if (in_array($syncRaw, ['1', 'true', 'yes', 'on'], true)) {
                    $syncTools = true;
                } elseif (in_array($syncRaw, ['0', 'false', 'no', 'off'], true)) {
                    $syncTools = false;
                }
            }
        }

        $result = $this->mcpService->health_check_server((int) $request['id'], $syncTools);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(
            [
                'ok' => !empty($result['ok']),
                'result' => $result,
            ]
        );
    }

    public function list_mcp_server_tools(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $refresh = false;
        if ($request->has_param('refresh')) {
            $refreshRaw = strtolower(trim((string) $request->get_param('refresh')));
            $refresh = in_array($refreshRaw, ['1', 'true', 'yes', 'on'], true);
        }

        $result = $this->mcpService->list_server_tools((int) $request['id'], $refresh);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function list_mcp_policies(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $filters = [];
        if ($request->has_param('enabled')) {
            $enabledRaw = strtolower(trim((string) $request->get_param('enabled')));
            $filters['enabled'] = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);
        }
        if ($request->has_param('server_id')) {
            $filters['server_id'] = (int) $request->get_param('server_id');
        }
        $mode = sanitize_key((string) $request->get_param('mode'));
        if ($mode !== '') {
            $filters['mode'] = $mode;
        }
        $search = sanitize_text_field((string) $request->get_param('search'));
        if ($search !== '') {
            $filters['search'] = $search;
        }
        if ($request->has_param('limit')) {
            $filters['limit'] = (int) $request->get_param('limit');
        }

        $items = $this->mcpService->list_tool_policies($filters);
        return rest_ensure_response(
            [
                'items' => $items,
                'count' => count($items),
            ]
        );
    }

    public function create_mcp_policy(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $created = $this->mcpService->create_tool_policy($input);
        if (is_wp_error($created)) {
            return $created;
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'item' => $created,
            ],
            201
        );
    }

    public function update_mcp_policy(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $updated = $this->mcpService->update_tool_policy((int) $request['id'], $input);
        if (is_wp_error($updated)) {
            return $updated;
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'item' => $updated,
            ]
        );
    }

    public function delete_mcp_policy(WP_REST_Request $request)
    {
        if (!$this->mcpService) {
            return new WP_Error('navai_mcp_unavailable', 'MCP service is unavailable.', ['status' => 500]);
        }

        $id = (int) $request['id'];
        $deleted = $this->mcpService->delete_tool_policy($id);
        if (!$deleted) {
            return new WP_Error('navai_mcp_policy_delete_failed', 'Failed to delete MCP policy.', ['status' => 404]);
        }

        return rest_ensure_response(
            [
                'ok' => true,
                'id' => $id,
            ]
        );
    }

    public function store_session_messages(WP_REST_Request $request)
    {
        if (!$this->sessionService) {
            return new WP_Error('navai_sessions_unavailable', 'Session service is unavailable.', ['status' => 500]);
        }

        if (!$this->should_enforce_session_memory()) {
            return rest_ensure_response(
                [
                    'ok' => true,
                    'persisted' => false,
                    'saved' => 0,
                    'failed' => 0,
                    'memory_enabled' => false,
                ]
            );
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = [];
        }

        $sessionKey = sanitize_text_field((string) ($input['session_key'] ?? ''));
        if ($sessionKey === '') {
            return new WP_Error('navai_invalid_session_key', 'session_key is required.', ['status' => 400]);
        }

        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        if (count($items) === 0 && is_array($input['message'] ?? null)) {
            $items = [$input['message']];
        }
        if (count($items) === 0) {
            return new WP_Error('navai_invalid_session_messages', 'items is required.', ['status' => 400]);
        }

        $result = $this->sessionService->record_messages_batch_by_session_key(
            $sessionKey,
            $items,
            $this->settings->get_settings()
        );

        return rest_ensure_response(
            [
                'ok' => !empty($result['persisted']) || ((int) ($result['saved'] ?? 0)) === 0,
                'persisted' => !empty($result['persisted']),
                'saved' => (int) ($result['saved'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'session_key' => (string) ($result['session_key'] ?? $sessionKey),
                'session' => $result['session'] ?? null,
                'memory_enabled' => true,
            ]
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    private function execute_function_definition(
        WP_REST_Request $request,
        string $functionName,
        array $definition,
        array $payload,
        array $options = []
    ): WP_REST_Response {
        $functionSource = isset($definition['source']) && is_string($definition['source'])
            ? $definition['source']
            : 'unknown';
        $callback = $definition['callback'] ?? null;
        if (!is_callable($callback)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'function_name' => $functionName,
                    'error' => 'Function callback is not callable.',
                ],
                500
            );
        }

        $traceId = isset($options['trace_id']) && is_string($options['trace_id']) && trim($options['trace_id']) !== ''
            ? sanitize_text_field((string) $options['trace_id'])
            : wp_generate_uuid4();
        $approvalId = isset($options['approval_id']) && is_numeric($options['approval_id']) ? (int) $options['approval_id'] : 0;
        $skipApproval = !empty($options['skip_approval']);
        $sessionId = isset($options['session_id']) && is_numeric($options['session_id']) ? (int) $options['session_id'] : 0;
        $sessionKey = isset($options['session_key']) && is_string($options['session_key'])
            ? sanitize_text_field((string) $options['session_key'])
            : '';
        $startedAt = microtime(true);

        $metadata = $this->normalize_function_metadata($definition);
        $payloadSchemaValidation = $this->validate_function_payload_schema($payload, $metadata['argument_schema']);
        if (!$payloadSchemaValidation['valid']) {
            $this->log_trace_event(
                'tool_validation_error',
                [
                    'session_id' => $sessionId > 0 ? $sessionId : null,
                    'trace_id' => $traceId,
                    'function_name' => $functionName,
                    'function_source' => $functionSource,
                    'scope' => 'tool',
                    'validation_errors' => $payloadSchemaValidation['errors'],
                ],
                'warning'
            );

            return new WP_REST_Response(
                [
                    'ok' => false,
                    'function_name' => $functionName,
                    'error' => 'Payload does not match configured argument schema.',
                    'validation_errors' => $payloadSchemaValidation['errors'],
                    'trace_id' => $traceId,
                    'metadata' => $metadata,
                ],
                400
            );
        }

        $agentRuntime = $this->resolve_agent_runtime_for_tool(
            $request,
            $functionName,
            $payload,
            array_merge(
                $options,
                [
                    'trace_id' => $traceId,
                    'session_id' => $sessionId,
                    'session_key' => $sessionKey,
                ]
            )
        );

        if (!empty($agentRuntime['enabled']) && !empty($agentRuntime['handoff'])) {
            $handoffData = is_array($agentRuntime['handoff']) ? $agentRuntime['handoff'] : [];
            $this->log_trace_event(
                'agent_handoff',
                [
                    'session_id' => $sessionId > 0 ? $sessionId : null,
                    'trace_id' => $traceId,
                    'function_name' => $functionName,
                    'function_source' => $functionSource,
                    'rule_id' => isset($handoffData['rule_id']) ? (int) $handoffData['rule_id'] : null,
                    'rule_name' => (string) ($handoffData['rule_name'] ?? ''),
                    'from_agent_key' => (string) ($handoffData['source_agent_key'] ?? ''),
                    'from_agent_name' => (string) ($handoffData['source_agent_name'] ?? ''),
                    'to_agent_key' => (string) ($handoffData['target_agent_key'] ?? ''),
                    'to_agent_name' => (string) ($handoffData['target_agent_name'] ?? ''),
                    'matched' => $handoffData['matched'] ?? [],
                ],
                'info'
            );

            if ($sessionId > 0 && $sessionKey !== '') {
                $this->log_session_message(
                    $sessionKey,
                    [
                        'direction' => 'system',
                        'message_type' => 'event',
                        'content_text' => 'Agent handoff executed.',
                        'content_json' => [
                            'type' => 'agent_handoff',
                            'function_name' => $functionName,
                            'rule_id' => isset($handoffData['rule_id']) ? (int) $handoffData['rule_id'] : null,
                            'from_agent_key' => (string) ($handoffData['source_agent_key'] ?? ''),
                            'to_agent_key' => (string) ($handoffData['target_agent_key'] ?? ''),
                            'matched' => $handoffData['matched'] ?? [],
                        ],
                        'meta' => [
                            'trace_id' => $traceId,
                        ],
                    ]
                );
            }
        }

        if (!empty($agentRuntime['enabled']) && empty($agentRuntime['tool_allowed'])) {
            $blockedAgent = is_array($agentRuntime['agent'] ?? null) ? $agentRuntime['agent'] : [];
            $this->log_trace_event(
                'agent_tool_blocked',
                [
                    'session_id' => $sessionId > 0 ? $sessionId : null,
                    'trace_id' => $traceId,
                    'function_name' => $functionName,
                    'function_source' => $functionSource,
                    'agent_key' => (string) ($blockedAgent['agent_key'] ?? ''),
                    'agent_name' => (string) ($blockedAgent['name'] ?? ''),
                    'reason' => (string) ($agentRuntime['tool_allowed_reason'] ?? ''),
                ],
                'warning'
            );

            return new WP_REST_Response(
                [
                    'ok' => false,
                    'function_name' => $functionName,
                    'error' => (string) ($agentRuntime['tool_allowed_reason'] ?? 'Blocked by agent tool restrictions.'),
                    'trace_id' => $traceId,
                    'agent' => $blockedAgent,
                    'handoff' => $agentRuntime['handoff'] ?? null,
                ],
                403
            );
        }

        $mcpPolicyBlock = $this->build_mcp_policy_block_response(
            $definition,
            $functionName,
            $functionSource,
            $agentRuntime,
            $traceId,
            $sessionId,
            $sessionKey
        );
        if ($mcpPolicyBlock instanceof WP_REST_Response) {
            return $mcpPolicyBlock;
        }

        if ($sessionId > 0 && $sessionKey !== '') {
            $this->log_session_message(
                $sessionKey,
                [
                    'direction' => 'tool',
                    'message_type' => 'tool_call',
                    'content_text' => $functionName,
                    'content_json' => [
                        'function_name' => $functionName,
                        'function_source' => $functionSource,
                        'payload' => $payload,
                    ],
                    'meta' => [
                        'trace_id' => $traceId,
                        'approval_id' => $approvalId > 0 ? $approvalId : null,
                        'agent_key' => is_array($agentRuntime['agent'] ?? null)
                            ? (string) (($agentRuntime['agent']['agent_key'] ?? ''))
                            : '',
                    ],
                ]
            );
        }

        $this->log_trace_event(
            'tool_start',
            [
                'session_id' => $sessionId > 0 ? $sessionId : null,
                'trace_id' => $traceId,
                'function_name' => $functionName,
                'function_source' => $functionSource,
                'agent_key' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['agent_key'] ?? '')) : '',
                'agent_name' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['name'] ?? '')) : '',
                'handoff' => $agentRuntime['handoff'] ?? null,
                'payload' => $payload,
                'scope' => 'tool',
                'approval_id' => $approvalId > 0 ? $approvalId : null,
                'timeout_seconds' => $metadata['timeout_seconds'],
                'execution_scope' => $metadata['execution_scope'],
                'retries' => $metadata['retries'],
                'has_argument_schema' => is_array($metadata['argument_schema']),
            ],
            'info'
        );

        $inputGuardrail = $this->evaluate_guardrails(
            'input',
            [
                'request' => $request,
                'function_name' => $functionName,
                'function_source' => $functionSource,
                'payload' => $payload,
            ]
        );
        if (!empty($inputGuardrail['blocked'])) {
            return $this->build_guardrail_block_response('input', $functionName, $inputGuardrail, 403, $traceId, $sessionId, $sessionKey);
        }

        $toolGuardrail = $this->evaluate_guardrails(
            'tool',
            [
                'request' => $request,
                'function_name' => $functionName,
                'function_source' => $functionSource,
                'payload' => $payload,
            ]
        );
        if (!empty($toolGuardrail['blocked'])) {
            return $this->build_guardrail_block_response('tool', $functionName, $toolGuardrail, 403, $traceId, $sessionId, $sessionKey);
        }

        if (!$skipApproval && $metadata['requires_approval'] && $this->should_enforce_approvals()) {
            $approval = $this->create_pending_approval($functionName, $functionSource, $payload, $traceId, $metadata, $sessionId);
            if (is_wp_error($approval)) {
                return new WP_REST_Response(
                    [
                        'ok' => false,
                        'function_name' => $functionName,
                        'error' => $approval->get_error_message(),
                        'trace_id' => $traceId,
                    ],
                    500
                );
            }

            $approvalId = isset($approval['id']) ? (int) $approval['id'] : 0;
            $this->log_trace_event(
                'approval_requested',
                [
                    'session_id' => $sessionId > 0 ? $sessionId : null,
                    'trace_id' => $traceId,
                    'approval_id' => $approvalId,
                    'function_name' => $functionName,
                    'function_source' => $functionSource,
                    'agent_key' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['agent_key'] ?? '')) : '',
                    'agent_name' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['name'] ?? '')) : '',
                    'payload' => $payload,
                ],
                'warning'
            );
            do_action('navai_approval_requested', $approval, $definition, $payload);

            if ($sessionId > 0 && $sessionKey !== '') {
                $this->log_session_message(
                    $sessionKey,
                    [
                        'direction' => 'tool',
                        'message_type' => 'tool_result',
                        'content_text' => 'Pending approval for tool execution.',
                        'content_json' => [
                            'function_name' => $functionName,
                            'pending_approval' => true,
                            'approval_id' => $approvalId,
                            'agent_key' => is_array($agentRuntime['agent'] ?? null)
                                ? (string) (($agentRuntime['agent']['agent_key'] ?? ''))
                                : '',
                        ],
                        'meta' => [
                            'trace_id' => $traceId,
                        ],
                    ]
                );
            }

            return new WP_REST_Response(
                [
                    'ok' => false,
                    'pending_approval' => true,
                    'trace_id' => $traceId,
                    'function_name' => $functionName,
                    'source' => $functionSource,
                    'approval' => [
                        'id' => $approvalId,
                        'status' => (string) ($approval['status'] ?? 'pending'),
                    ],
                    'guardrails' => [
                        'input' => ['matched_count' => isset($inputGuardrail['matched_count']) ? (int) $inputGuardrail['matched_count'] : 0],
                        'tool' => ['matched_count' => isset($toolGuardrail['matched_count']) ? (int) $toolGuardrail['matched_count'] : 0],
                        'output' => ['matched_count' => 0],
                    ],
                    'metadata' => $metadata,
                    'agent' => $agentRuntime['agent'] ?? null,
                    'handoff' => $agentRuntime['handoff'] ?? null,
                    'session' => $sessionId > 0
                        ? [
                            'id' => $sessionId,
                            'key' => $sessionKey,
                        ]
                        : null,
                    'message' => 'Function requires admin approval before execution.',
                ],
                202
            );
        }

        $result = null;
        $lastExecutionError = null;
        $attempts = 0;
        $maxAttempts = max(1, ((int) ($metadata['retries'] ?? 0)) + 1);

        for ($attemptNumber = 1; $attemptNumber <= $maxAttempts; $attemptNumber++) {
            $attempts = $attemptNumber;

            try {
                $result = call_user_func(
                    $callback,
                    $payload,
                    [
                        'request' => $request,
                        'trace_id' => $traceId,
                        'approval_id' => $approvalId > 0 ? $approvalId : null,
                        'timeout_seconds' => $metadata['timeout_seconds'],
                        'execution_scope' => $metadata['execution_scope'],
                        'attempt' => $attemptNumber,
                        'max_attempts' => $maxAttempts,
                        'retries' => $maxAttempts - 1,
                        'agent' => $agentRuntime['agent'] ?? null,
                        'handoff' => $agentRuntime['handoff'] ?? null,
                    ]
                );

                $lastExecutionError = null;
                break;
            } catch (Throwable $error) {
                $lastExecutionError = $error;

                if ($attemptNumber < $maxAttempts) {
                    $this->log_trace_event(
                        'tool_retry',
                        [
                            'session_id' => $sessionId > 0 ? $sessionId : null,
                            'trace_id' => $traceId,
                            'function_name' => $functionName,
                            'function_source' => $functionSource,
                            'agent_key' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['agent_key'] ?? '')) : '',
                            'agent_name' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['name'] ?? '')) : '',
                            'attempt' => $attemptNumber,
                            'max_attempts' => $maxAttempts,
                            'error' => $error->getMessage(),
                        ],
                        'warning'
                    );
                }
            }
        }

        if ($lastExecutionError instanceof Throwable) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->log_trace_event(
                'tool_error',
                [
                    'session_id' => $sessionId > 0 ? $sessionId : null,
                    'trace_id' => $traceId,
                    'function_name' => $functionName,
                    'function_source' => $functionSource,
                    'agent_key' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['agent_key'] ?? '')) : '',
                    'agent_name' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['name'] ?? '')) : '',
                    'error' => $lastExecutionError->getMessage(),
                    'scope' => 'tool',
                    'approval_id' => $approvalId > 0 ? $approvalId : null,
                    'duration_ms' => $durationMs,
                    'attempts' => $attempts,
                    'retries' => $maxAttempts - 1,
                ],
                'error'
            );
            if ($sessionId > 0 && $sessionKey !== '') {
                $this->log_session_message(
                    $sessionKey,
                    [
                        'direction' => 'tool',
                        'message_type' => 'tool_result',
                        'content_text' => 'Function execution failed.',
                        'content_json' => [
                            'function_name' => $functionName,
                            'ok' => false,
                            'error' => 'Function execution failed.',
                            'details' => $lastExecutionError->getMessage(),
                            'agent_key' => is_array($agentRuntime['agent'] ?? null)
                                ? (string) (($agentRuntime['agent']['agent_key'] ?? ''))
                                : '',
                        ],
                        'meta' => [
                            'trace_id' => $traceId,
                            'duration_ms' => $durationMs,
                            'attempts' => $attempts,
                        ],
                    ]
                );
            }
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'function_name' => (string) ($definition['name'] ?? $functionName),
                    'error' => 'Function execution failed.',
                    'details' => $lastExecutionError->getMessage(),
                    'trace_id' => $traceId,
                    'attempts' => $attempts,
                    'retries' => $maxAttempts - 1,
                    'agent' => $agentRuntime['agent'] ?? null,
                    'handoff' => $agentRuntime['handoff'] ?? null,
                ],
                500
            );
        }

        $outputGuardrail = $this->evaluate_guardrails(
            'output',
            [
                'request' => $request,
                'function_name' => $functionName,
                'function_source' => $functionSource,
                'result' => $result,
            ]
        );
        if (!empty($outputGuardrail['blocked'])) {
            return $this->build_guardrail_block_response('output', $functionName, $outputGuardrail, 403, $traceId, $sessionId, $sessionKey);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->log_trace_event(
            'tool_success',
            [
                'session_id' => $sessionId > 0 ? $sessionId : null,
                'trace_id' => $traceId,
                'function_name' => $functionName,
                'function_source' => $functionSource,
                'agent_key' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['agent_key'] ?? '')) : '',
                'agent_name' => is_array($agentRuntime['agent'] ?? null) ? (string) (($agentRuntime['agent']['name'] ?? '')) : '',
                'approval_id' => $approvalId > 0 ? $approvalId : null,
                'duration_ms' => $durationMs,
                'attempts' => $attempts,
                'retries' => $maxAttempts - 1,
                'result_preview' => $this->build_trace_result_preview($result),
            ],
            'info'
        );

        if ($sessionId > 0 && $sessionKey !== '') {
            $this->log_session_message(
                $sessionKey,
                [
                    'direction' => 'tool',
                    'message_type' => 'tool_result',
                    'content_text' => 'Tool execution completed.',
                    'content_json' => [
                            'function_name' => $functionName,
                            'ok' => true,
                            'result' => $result,
                            'agent_key' => is_array($agentRuntime['agent'] ?? null)
                                ? (string) (($agentRuntime['agent']['agent_key'] ?? ''))
                                : '',
                        ],
                        'meta' => [
                            'trace_id' => $traceId,
                            'duration_ms' => $durationMs,
                            'approval_id' => $approvalId > 0 ? $approvalId : null,
                            'attempts' => $attempts,
                        ],
                    ]
                );
            }

        return rest_ensure_response(
            [
                'ok' => true,
                'trace_id' => $traceId,
                'function_name' => (string) ($definition['name'] ?? $functionName),
                'source' => $functionSource,
                'approval_id' => $approvalId > 0 ? $approvalId : null,
                'guardrails' => [
                    'input' => [
                        'matched_count' => isset($inputGuardrail['matched_count']) ? (int) $inputGuardrail['matched_count'] : 0,
                    ],
                    'tool' => [
                        'matched_count' => isset($toolGuardrail['matched_count']) ? (int) $toolGuardrail['matched_count'] : 0,
                    ],
                    'output' => [
                        'matched_count' => isset($outputGuardrail['matched_count']) ? (int) $outputGuardrail['matched_count'] : 0,
                    ],
                ],
                'metadata' => $metadata,
                'attempts' => $attempts,
                'duration_ms' => $durationMs,
                'agent' => $agentRuntime['agent'] ?? null,
                'handoff' => $agentRuntime['handoff'] ?? null,
                'session' => $sessionId > 0
                    ? [
                        'id' => $sessionId,
                        'key' => $sessionKey,
                    ]
                    : null,
                'result' => $result,
            ]
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{
     *   requires_approval: bool,
     *   timeout_seconds: int,
     *   execution_scope: string,
     *   retries: int,
     *   argument_schema: array<string, mixed>|null,
     *   function_id: int|null
     * }
     */
    private function normalize_function_metadata(array $definition): array
    {
        $requiresApproval = !empty($definition['requires_approval']);
        $timeout = isset($definition['timeout_seconds']) && is_numeric($definition['timeout_seconds'])
            ? (int) $definition['timeout_seconds']
            : 0;
        if ($timeout < 0) {
            $timeout = 0;
        }
        if ($timeout > 600) {
            $timeout = 600;
        }

        $executionScope = sanitize_key((string) ($definition['execution_scope'] ?? 'both'));
        if (!in_array($executionScope, ['frontend', 'admin', 'both'], true)) {
            $executionScope = 'both';
        }

        $retries = isset($definition['retries']) && is_numeric($definition['retries'])
            ? (int) $definition['retries']
            : 0;
        if ($retries < 0) {
            $retries = 0;
        }
        if ($retries > 5) {
            $retries = 5;
        }

        $argumentSchema = null;
        if (array_key_exists('argument_schema', $definition)) {
            $normalizedSchema = $this->normalize_function_argument_schema($definition['argument_schema']);
            if (!is_wp_error($normalizedSchema)) {
                $argumentSchema = $normalizedSchema;
            }
        }

        $functionId = isset($definition['function_id']) && is_numeric($definition['function_id'])
            ? (int) $definition['function_id']
            : null;

        return [
            'requires_approval' => $requiresApproval,
            'timeout_seconds' => $timeout,
            'execution_scope' => $executionScope,
            'retries' => $retries,
            'argument_schema' => $argumentSchema,
            'function_id' => $functionId,
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null|WP_Error
     */
    private function normalize_function_argument_schema($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $schema = null;
        if (is_array($value)) {
            $schema = $value;
        } elseif (is_string($value)) {
            $raw = trim($value);
            if ($raw === '') {
                return null;
            }

            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return new WP_Error(
                    'navai_invalid_argument_schema',
                    'argument_schema must be a valid JSON object.',
                    ['status' => 400]
                );
            }
            $schema = $decoded;
        } else {
            return new WP_Error(
                'navai_invalid_argument_schema',
                'argument_schema must be a JSON object.',
                ['status' => 400]
            );
        }

        if (!is_array($schema)) {
            return new WP_Error(
                'navai_invalid_argument_schema',
                'argument_schema must be a JSON object.',
                ['status' => 400]
            );
        }

        if ($schema !== [] && $this->is_json_list_array($schema)) {
            return new WP_Error(
                'navai_invalid_argument_schema',
                'argument_schema root must be a JSON object.',
                ['status' => 400]
            );
        }

        $shapeValidation = $this->validate_json_schema_definition_shape($schema);
        if (!$shapeValidation['valid']) {
            return new WP_Error(
                'navai_invalid_argument_schema',
                'argument_schema is invalid: ' . (string) ($shapeValidation['errors'][0] ?? 'Invalid JSON Schema.'),
                [
                    'status' => 400,
                    'validation_errors' => $shapeValidation['errors'],
                ]
            );
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{valid: bool, errors: array<int, string>}
     */
    private function validate_json_schema_definition_shape(array $schema, string $path = '$', int $depth = 0): array
    {
        $errors = [];
        if ($depth > 12) {
            return [
                'valid' => false,
                'errors' => [sprintf('%s exceeds max schema nesting depth.', $path)],
            ];
        }

        $allowedTypes = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];
        if (array_key_exists('type', $schema)) {
            $type = $schema['type'];
            if (is_string($type)) {
                if (!in_array($type, $allowedTypes, true)) {
                    $errors[] = sprintf('%s.type is not supported.', $path);
                }
            } elseif (is_array($type)) {
                if (count($type) === 0) {
                    $errors[] = sprintf('%s.type must not be empty.', $path);
                } else {
                    foreach ($type as $index => $typeItem) {
                        if (!is_string($typeItem) || !in_array($typeItem, $allowedTypes, true)) {
                            $errors[] = sprintf('%s.type[%d] is not supported.', $path, (int) $index);
                            break;
                        }
                    }
                }
            } else {
                $errors[] = sprintf('%s.type must be string or string[].', $path);
            }
        }

        if (array_key_exists('required', $schema)) {
            if (!is_array($schema['required'])) {
                $errors[] = sprintf('%s.required must be an array of strings.', $path);
            } else {
                foreach ($schema['required'] as $index => $requiredKey) {
                    if (!is_string($requiredKey) || trim($requiredKey) === '') {
                        $errors[] = sprintf('%s.required[%d] must be a non-empty string.', $path, (int) $index);
                        break;
                    }
                }
            }
        }

        if (array_key_exists('properties', $schema)) {
            $properties = $schema['properties'];
            if (!is_array($properties) || ($properties !== [] && $this->is_json_list_array($properties))) {
                $errors[] = sprintf('%s.properties must be an object.', $path);
            } else {
                foreach ($properties as $propertyName => $propertySchema) {
                    if (!is_string($propertyName)) {
                        $errors[] = sprintf('%s.properties contains an invalid key.', $path);
                        break;
                    }
                    if (!is_array($propertySchema)) {
                        $errors[] = sprintf('%s.properties.%s must be an object.', $path, $propertyName);
                        continue;
                    }
                    $childPath = $path . '.properties.' . $propertyName;
                    $child = $this->validate_json_schema_definition_shape($propertySchema, $childPath, $depth + 1);
                    if (!$child['valid']) {
                        $errors = array_merge($errors, $child['errors']);
                        if (count($errors) >= 10) {
                            break;
                        }
                    }
                }
            }
        }

        if (array_key_exists('items', $schema)) {
            $itemsSchema = $schema['items'];
            if (!is_array($itemsSchema) || ($itemsSchema !== [] && $this->is_json_list_array($itemsSchema))) {
                $errors[] = sprintf('%s.items must be an object schema.', $path);
            } else {
                $child = $this->validate_json_schema_definition_shape($itemsSchema, $path . '.items', $depth + 1);
                if (!$child['valid']) {
                    $errors = array_merge($errors, $child['errors']);
                }
            }
        }

        if (array_key_exists('additionalProperties', $schema)) {
            $additional = $schema['additionalProperties'];
            if (!is_bool($additional)) {
                if (!is_array($additional) || ($additional !== [] && $this->is_json_list_array($additional))) {
                    $errors[] = sprintf('%s.additionalProperties must be boolean or object schema.', $path);
                } else {
                    $child = $this->validate_json_schema_definition_shape($additional, $path . '.additionalProperties', $depth + 1);
                    if (!$child['valid']) {
                        $errors = array_merge($errors, $child['errors']);
                    }
                }
            }
        }

        if (array_key_exists('enum', $schema) && !is_array($schema['enum'])) {
            $errors[] = sprintf('%s.enum must be an array.', $path);
        }

        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $integerKeyword) {
            if (array_key_exists($integerKeyword, $schema) && !is_int($schema[$integerKeyword])) {
                $errors[] = sprintf('%s.%s must be an integer.', $path, $integerKeyword);
            }
        }

        foreach (['minimum', 'maximum'] as $numberKeyword) {
            if (array_key_exists($numberKeyword, $schema) && !is_int($schema[$numberKeyword]) && !is_float($schema[$numberKeyword])) {
                $errors[] = sprintf('%s.%s must be a number.', $path, $numberKeyword);
            }
        }

        if (array_key_exists('pattern', $schema)) {
            if (!is_string($schema['pattern'])) {
                $errors[] = sprintf('%s.pattern must be a string.', $path);
            } else {
                $pattern = '/' . str_replace('/', '\/', (string) $schema['pattern']) . '/u';
                if (@preg_match($pattern, '') === false) {
                    $errors[] = sprintf('%s.pattern is not a valid regex.', $path);
                }
            }
        }

        if (isset($schema['minLength'], $schema['maxLength']) && is_int($schema['minLength']) && is_int($schema['maxLength'])) {
            if ($schema['minLength'] > $schema['maxLength']) {
                $errors[] = sprintf('%s.minLength cannot be greater than maxLength.', $path);
            }
        }

        if (isset($schema['minItems'], $schema['maxItems']) && is_int($schema['minItems']) && is_int($schema['maxItems'])) {
            if ($schema['minItems'] > $schema['maxItems']) {
                $errors[] = sprintf('%s.minItems cannot be greater than maxItems.', $path);
            }
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => array_slice(array_values($errors), 0, 10),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $schema
     * @return array{valid: bool, errors: array<int, string>}
     */
    private function validate_function_payload_schema(array $payload, ?array $schema): array
    {
        if (!is_array($schema)) {
            return [
                'valid' => true,
                'errors' => [],
            ];
        }

        $errors = [];
        $this->validate_json_schema_value($payload, $schema, '$', $errors, 0);

        return [
            'valid' => count($errors) === 0,
            'errors' => array_slice(array_values($errors), 0, 10),
        ];
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $schema
     * @param array<int, string> $errors
     */
    private function validate_json_schema_value($value, array $schema, string $path, array &$errors, int $depth): void
    {
        if (count($errors) >= 10) {
            return;
        }
        if ($depth > 12) {
            $errors[] = sprintf('%s exceeds max validation depth.', $path);
            return;
        }

        if (array_key_exists('type', $schema)) {
            $typeRule = $schema['type'];
            $allowed = is_array($typeRule) ? $typeRule : [$typeRule];
            $matchedType = false;
            foreach ($allowed as $candidateType) {
                if (is_string($candidateType) && $this->json_schema_type_matches($value, $candidateType)) {
                    $matchedType = true;
                    break;
                }
            }

            if (!$matchedType) {
                $errors[] = sprintf(
                    '%s expected type %s, got %s.',
                    $path,
                    is_array($typeRule) ? implode('|', array_filter(array_map('strval', $typeRule))) : (string) $typeRule,
                    $this->json_schema_value_type($value)
                );
                return;
            }
        }

        if (array_key_exists('enum', $schema) && is_array($schema['enum'])) {
            $enumMatched = false;
            foreach ($schema['enum'] as $enumValue) {
                if ($this->json_schema_values_equal($value, $enumValue)) {
                    $enumMatched = true;
                    break;
                }
            }
            if (!$enumMatched) {
                $errors[] = sprintf('%s is not one of the allowed enum values.', $path);
                return;
            }
        }

        if (is_string($value)) {
            $stringLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if (isset($schema['minLength']) && is_int($schema['minLength']) && $stringLength < $schema['minLength']) {
                $errors[] = sprintf('%s must have at least %d characters.', $path, $schema['minLength']);
            }
            if (isset($schema['maxLength']) && is_int($schema['maxLength']) && $stringLength > $schema['maxLength']) {
                $errors[] = sprintf('%s must have at most %d characters.', $path, $schema['maxLength']);
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $pattern = '/' . str_replace('/', '\/', $schema['pattern']) . '/u';
                if (@preg_match($pattern, $value) !== 1) {
                    $errors[] = sprintf('%s does not match required pattern.', $path);
                }
            }
        }

        if (is_int($value) || is_float($value)) {
            $numericValue = (float) $value;
            if ((isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum'])) && $numericValue < (float) $schema['minimum'])
                || (isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum'])) && $numericValue > (float) $schema['maximum'])) {
                if (isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum'])) && $numericValue < (float) $schema['minimum']) {
                    $errors[] = sprintf('%s must be >= %s.', $path, (string) $schema['minimum']);
                }
                if (isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum'])) && $numericValue > (float) $schema['maximum']) {
                    $errors[] = sprintf('%s must be <= %s.', $path, (string) $schema['maximum']);
                }
            }
        }

        if (!is_array($value)) {
            return;
        }

        $isList = $this->is_json_list_array($value);
        if ($value === [] && $isList) {
            $expectsObject = $this->schema_declares_json_type($schema, 'object');
            $expectsArray = $this->schema_declares_json_type($schema, 'array');
            if ($expectsObject && !$expectsArray) {
                $isList = false;
            }
        }
        if ($isList) {
            $itemCount = count($value);
            if (isset($schema['minItems']) && is_int($schema['minItems']) && $itemCount < $schema['minItems']) {
                $errors[] = sprintf('%s must contain at least %d items.', $path, $schema['minItems']);
            }
            if (isset($schema['maxItems']) && is_int($schema['maxItems']) && $itemCount > $schema['maxItems']) {
                $errors[] = sprintf('%s must contain at most %d items.', $path, $schema['maxItems']);
            }

            if (isset($schema['items']) && is_array($schema['items']) && count($errors) < 10) {
                foreach ($value as $index => $itemValue) {
                    $this->validate_json_schema_value($itemValue, $schema['items'], $path . '[' . (int) $index . ']', $errors, $depth + 1);
                    if (count($errors) >= 10) {
                        break;
                    }
                }
            }

            return;
        }

        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        if (!empty($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $requiredKey) {
                if (!is_string($requiredKey) || $requiredKey === '') {
                    continue;
                }
                if (!array_key_exists($requiredKey, $value)) {
                    $errors[] = sprintf('%s.%s is required.', $path, $requiredKey);
                    if (count($errors) >= 10) {
                        return;
                    }
                }
            }
        }

        foreach ($properties as $propertyName => $propertySchema) {
            if (!is_string($propertyName) || !is_array($propertySchema) || !array_key_exists($propertyName, $value)) {
                continue;
            }
            $this->validate_json_schema_value($value[$propertyName], $propertySchema, $path . '.' . $propertyName, $errors, $depth + 1);
            if (count($errors) >= 10) {
                return;
            }
        }

        if (array_key_exists('additionalProperties', $schema)) {
            $additionalProperties = $schema['additionalProperties'];
            foreach ($value as $propertyName => $propertyValue) {
                if (!is_string($propertyName)) {
                    continue;
                }
                if (array_key_exists($propertyName, $properties)) {
                    continue;
                }

                if ($additionalProperties === false) {
                    $errors[] = sprintf('%s.%s is not allowed.', $path, $propertyName);
                } elseif (is_array($additionalProperties)) {
                    $this->validate_json_schema_value(
                        $propertyValue,
                        $additionalProperties,
                        $path . '.' . $propertyName,
                        $errors,
                        $depth + 1
                    );
                }

                if (count($errors) >= 10) {
                    return;
                }
            }
        }
    }

    /**
     * @param mixed $value
     */
    private function json_schema_type_matches($value, string $type): bool
    {
        if (is_array($value) && $value === []) {
            return $type === 'object' || $type === 'array';
        }

        switch ($type) {
            case 'object':
                return is_array($value) && !$this->is_json_list_array($value);
            case 'array':
                return is_array($value) && $this->is_json_list_array($value);
            case 'string':
                return is_string($value);
            case 'number':
                return is_int($value) || is_float($value);
            case 'integer':
                return is_int($value);
            case 'boolean':
                return is_bool($value);
            case 'null':
                return $value === null;
            default:
                return false;
        }
    }

    /**
     * @param mixed $value
     */
    private function json_schema_value_type($value): string
    {
        if (is_array($value)) {
            return $this->is_json_list_array($value) ? 'array' : 'object';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'number';
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_string($value)) {
            return 'string';
        }

        return 'unknown';
    }

    /**
     * @param mixed $left
     * @param mixed $right
     */
    private function json_schema_values_equal($left, $right): bool
    {
        if (is_array($left) || is_array($right)) {
            return wp_json_encode($left) === wp_json_encode($right);
        }

        return $left === $right;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function schema_declares_json_type(array $schema, string $type): bool
    {
        if (!array_key_exists('type', $schema)) {
            return false;
        }

        $typeRule = $schema['type'];
        if (is_string($typeRule)) {
            return $typeRule === $type;
        }

        if (is_array($typeRule)) {
            foreach ($typeRule as $typeItem) {
                if (is_string($typeItem) && $typeItem === $type) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     */
    private function is_json_list_array(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $expectedKey = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>|WP_Error
     */
    private function create_pending_approval(
        string $functionName,
        string $functionSource,
        array $payload,
        string $traceId,
        array $metadata,
        int $sessionId = 0
    )
    {
        if (!$this->approvalService) {
            return new WP_Error('navai_approvals_unavailable', 'Approvals service is unavailable.', ['status' => 500]);
        }

        return $this->approvalService->create_request(
            [
                'requested_by_user_id' => is_user_logged_in() ? get_current_user_id() : null,
                'session_id' => $sessionId > 0 ? $sessionId : null,
                'function_id' => isset($metadata['function_id']) && is_numeric($metadata['function_id']) ? (int) $metadata['function_id'] : null,
                'function_key' => $functionName,
                'function_source' => $functionSource,
                'payload' => $payload,
                'reason' => 'Configured function requires admin approval.',
                'trace_id' => $traceId,
            ]
        );
    }

    /**
     * @param mixed $response
     * @return array<string, mixed>|null
     */
    private function extract_rest_response_data($response): ?array
    {
        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            return is_array($data) ? $data : ['data' => $data];
        }

        if ($response instanceof WP_Error) {
            return [
                'ok' => false,
                'error' => $response->get_error_message(),
                'code' => $response->get_error_code(),
            ];
        }

        if (is_array($response)) {
            return $response;
        }

        return ['data' => $response];
    }

    /**
     * @param mixed $result
     * @return array<string, mixed>|string
     */
    private function build_trace_result_preview($result)
    {
        if (is_array($result)) {
            $preview = $result;
            if (isset($preview['code']) && is_string($preview['code']) && strlen($preview['code']) > 200) {
                $preview['code'] = substr($preview['code'], 0, 200) . '...';
            }
            return $preview;
        }

        if (is_string($result)) {
            return strlen($result) > 250 ? (substr($result, 0, 250) . '...') : $result;
        }

        if (is_scalar($result) || $result === null) {
            return (string) wp_json_encode($result);
        }

        return '[complex result]';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function resolve_agent_runtime_for_tool(
        WP_REST_Request $request,
        string $functionName,
        array $payload,
        array $options = []
    ): array {
        if (!$this->agentService || !$this->should_enforce_agents()) {
            return [
                'enabled' => false,
                'agent' => null,
                'source_agent' => null,
                'handoff' => null,
                'tool_allowed' => true,
                'tool_allowed_reason' => '',
            ];
        }

        $requestInput = $request->get_json_params();
        if (!is_array($requestInput)) {
            $requestInput = [];
        }

        $sessionId = isset($options['session_id']) && is_numeric($options['session_id']) ? (int) $options['session_id'] : 0;
        $sessionRow = is_array($options['session'] ?? null) ? $options['session'] : null;
        if (!is_array($sessionRow) && $sessionId > 0 && $this->sessionService) {
            $sessionRow = $this->sessionService->get_session($sessionId);
        }
        $sessionContext = is_array($sessionRow['context'] ?? null) ? $sessionRow['context'] : [];

        $resolved = $this->agentService->resolve_tool_runtime(
            [
                'settings' => $this->settings->get_settings(),
                'function_name' => $functionName,
                'payload' => $payload,
                'roles' => $this->get_request_roles(),
                'requested_agent_key' => isset($requestInput['agent_key']) ? (string) $requestInput['agent_key'] : '',
                'session_context' => $sessionContext,
                'request_context' => [
                    'intent' => isset($requestInput['intent']) ? (string) $requestInput['intent'] : (string) ($payload['intent'] ?? ''),
                    'text' => isset($requestInput['text']) ? (string) $requestInput['text'] : '',
                ],
            ]
        );

        if (!empty($resolved['enabled']) && $sessionId > 0 && $this->sessionService) {
            $agent = is_array($resolved['agent'] ?? null) ? $resolved['agent'] : [];
            $agentKey = sanitize_text_field((string) ($agent['agent_key'] ?? ''));
            if ($agentKey !== '') {
                $contextUpdate = [
                    'active_agent_key' => $agentKey,
                    'active_agent_name' => sanitize_text_field((string) ($agent['name'] ?? '')),
                    'last_agent_resolution_at' => current_time('mysql'),
                ];

                $handoff = is_array($resolved['handoff'] ?? null) ? $resolved['handoff'] : [];
                if (count($handoff) > 0) {
                    $contextUpdate['last_handoff_at'] = current_time('mysql');
                    if (isset($handoff['rule_id']) && is_numeric($handoff['rule_id'])) {
                        $contextUpdate['last_handoff_rule_id'] = (int) $handoff['rule_id'];
                    }
                    $contextUpdate['last_handoff_from_agent_key'] = sanitize_text_field((string) ($handoff['source_agent_key'] ?? ''));
                }

                try {
                    $this->sessionService->update_session_context($sessionId, $contextUpdate);
                } catch (Throwable $error) {
                    unset($error);
                }
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $agentRuntime
     */
    private function build_mcp_policy_block_response(
        array $definition,
        string $functionName,
        string $functionSource,
        array $agentRuntime,
        string $traceId,
        int $sessionId = 0,
        string $sessionKey = ''
    ): ?WP_REST_Response {
        if (!$this->mcpService || !$this->should_enforce_mcp()) {
            return null;
        }

        $mcpServerId = isset($definition['mcp_server_id']) && is_numeric($definition['mcp_server_id'])
            ? (int) $definition['mcp_server_id']
            : 0;
        $mcpServerKey = isset($definition['mcp_server_key']) && is_string($definition['mcp_server_key'])
            ? sanitize_text_field((string) $definition['mcp_server_key'])
            : '';
        $mcpToolName = isset($definition['mcp_tool_name']) && is_string($definition['mcp_tool_name'])
            ? sanitize_text_field((string) $definition['mcp_tool_name'])
            : '';

        if ($mcpServerId <= 0 || $mcpToolName === '') {
            return null;
        }

        $agent = is_array($agentRuntime['agent'] ?? null) ? $agentRuntime['agent'] : [];
        $agentKey = sanitize_text_field((string) ($agent['agent_key'] ?? ''));

        $authz = $this->mcpService->authorize_tool_call(
            [
                'server_id' => $mcpServerId,
                'server_key' => $mcpServerKey,
                'tool_name' => $mcpToolName,
                'roles' => $this->get_request_roles(),
                'agent_key' => $agentKey,
            ]
        );
        if (!empty($authz['allowed'])) {
            return null;
        }

        $matchedPolicy = is_array($authz['matched_policy'] ?? null) ? $authz['matched_policy'] : null;
        $this->log_trace_event(
            'mcp_tool_blocked',
            [
                'session_id' => $sessionId > 0 ? $sessionId : null,
                'trace_id' => $traceId,
                'function_name' => $functionName,
                'function_source' => $functionSource,
                'mcp_server_id' => $mcpServerId,
                'mcp_server_key' => $mcpServerKey,
                'mcp_tool_name' => $mcpToolName,
                'agent_key' => $agentKey,
                'agent_name' => sanitize_text_field((string) ($agent['name'] ?? '')),
                'reason' => (string) ($authz['reason'] ?? ''),
                'policy' => $matchedPolicy,
            ],
            'warning'
        );

        if ($sessionId > 0 && $sessionKey !== '') {
            $this->log_session_message(
                $sessionKey,
                [
                    'direction' => 'system',
                    'message_type' => 'event',
                    'content_text' => 'MCP tool blocked by policy.',
                    'content_json' => [
                        'type' => 'mcp_tool_blocked',
                        'function_name' => $functionName,
                        'mcp_server_key' => $mcpServerKey,
                        'mcp_tool_name' => $mcpToolName,
                        'reason' => (string) ($authz['reason'] ?? ''),
                        'policy' => $matchedPolicy,
                    ],
                    'meta' => [
                        'trace_id' => $traceId,
                    ],
                ]
            );
        }

        return new WP_REST_Response(
            [
                'ok' => false,
                'function_name' => $functionName,
                'error' => (string) ($authz['reason'] ?? 'Blocked by MCP policy.'),
                'trace_id' => $traceId,
                'agent' => $agentRuntime['agent'] ?? null,
                'handoff' => $agentRuntime['handoff'] ?? null,
                'mcp' => [
                    'server_id' => $mcpServerId,
                    'server_key' => $mcpServerKey,
                    'tool_name' => $mcpToolName,
                    'policy' => $matchedPolicy,
                ],
            ],
            403
        );
    }

    /**
     * @param array<string, mixed> $subject
     * @return array<string, mixed>
     */
    private function evaluate_guardrails(string $scope, array $subject): array
    {
        if (!$this->should_enforce_guardrails() || !$this->guardrailService) {
            return [
                'scope' => $scope,
                'blocked' => false,
                'matched_count' => 0,
                'matches' => [],
                'warnings' => [],
            ];
        }

        $subject['roles'] = $this->get_request_roles();
        return $this->guardrailService->evaluate($scope, $subject);
    }

    private function should_enforce_guardrails(): bool
    {
        $settings = $this->settings->get_settings();
        return !array_key_exists('enable_guardrails', $settings) || !empty($settings['enable_guardrails']);
    }

    private function should_enforce_agents(): bool
    {
        $settings = $this->settings->get_settings();
        return !array_key_exists('enable_agents', $settings) || !empty($settings['enable_agents']);
    }

    private function should_enforce_mcp(): bool
    {
        $settings = $this->settings->get_settings();
        return !array_key_exists('enable_mcp', $settings) || !empty($settings['enable_mcp']);
    }

    /**
     * @return array<int, string>
     */
    private function get_request_roles(): array
    {
        if (!is_user_logged_in()) {
            return ['guest'];
        }

        $user = wp_get_current_user();
        if (!($user instanceof WP_User) || !is_array($user->roles)) {
            return ['authenticated'];
        }

        $roles = array_values(array_filter(array_map('sanitize_key', $user->roles)));
        if (count($roles) === 0) {
            $roles[] = 'authenticated';
        }

        return $roles;
    }

    /**
     * @param array<string, mixed> $guardrail
     */
    private function build_guardrail_block_response(
        string $scope,
        string $functionName,
        array $guardrail,
        int $status,
        string $traceId = '',
        int $sessionId = 0,
        string $sessionKey = ''
    ): WP_REST_Response
    {
        $event = [
            'scope' => $scope,
            'function_name' => $functionName,
            'matched_count' => isset($guardrail['matched_count']) ? (int) $guardrail['matched_count'] : 0,
            'matches' => is_array($guardrail['matches'] ?? null) ? $guardrail['matches'] : [],
            'roles' => is_array($guardrail['roles'] ?? null) ? $guardrail['roles'] : $this->get_request_roles(),
            'function_source' => isset($guardrail['function_source']) ? (string) $guardrail['function_source'] : '',
        ];
        if ($traceId !== '') {
            $event['trace_id'] = $traceId;
        }
        if ($sessionId > 0) {
            $event['session_id'] = $sessionId;
        }

        $traceId = $this->log_trace_event('guardrail_blocked', $event, 'warning', $traceId);
        $event['trace_id'] = $traceId;

        if ($sessionId > 0 && $sessionKey !== '') {
            $this->log_session_message(
                $sessionKey,
                [
                    'direction' => 'system',
                    'message_type' => 'event',
                    'content_text' => 'Guardrail blocked tool execution.',
                    'content_json' => [
                        'scope' => $scope,
                        'function_name' => $functionName,
                        'matched_count' => $event['matched_count'],
                        'matches' => $event['matches'],
                    ],
                    'meta' => [
                        'trace_id' => $traceId,
                    ],
                ]
            );
        }

        do_action('navai_guardrail_blocked', $scope, $functionName, $guardrail, $traceId);

        return new WP_REST_Response(
            [
                'ok' => false,
                'function_name' => $functionName,
                'error' => 'Blocked by NAVAI guardrail.',
                'guardrail' => [
                    'scope' => $scope,
                    'matched_count' => $event['matched_count'],
                    'matches' => $event['matches'],
                    'trace_id' => $traceId,
                ],
                'session' => $sessionId > 0
                    ? [
                        'id' => $sessionId,
                        'key' => $sessionKey,
                    ]
                    : null,
            ],
            $status
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function log_trace_event(string $eventType, array $event, string $severity = 'info', string $traceId = ''): string
    {
        $traceId = trim($traceId) !== '' ? sanitize_text_field($traceId) : sanitize_text_field((string) ($event['trace_id'] ?? ''));
        if ($traceId === '') {
            $traceId = wp_generate_uuid4();
        }
        $event['trace_id'] = $traceId;

        if ($this->traceService && $this->should_enforce_tracing()) {
            $this->traceService->log_event($eventType, $event, $severity);
        }

        return $traceId;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function resolve_session_context_for_input(array $input): array
    {
        $sessionKey = isset($input['session_key']) ? sanitize_text_field((string) $input['session_key']) : '';
        if (!$this->sessionService) {
            return [
                'enabled' => false,
                'persisted' => false,
                'session_key' => $sessionKey,
                'session_id' => null,
                'session' => null,
            ];
        }

        if (!$this->should_enforce_session_memory()) {
            return [
                'enabled' => false,
                'persisted' => false,
                'session_key' => $sessionKey !== '' ? $sessionKey : '',
                'session_id' => null,
                'session' => null,
            ];
        }

        $resolved = $this->sessionService->resolve_session(
            [
                'session_key' => $sessionKey,
                'context' => [
                    'last_channel' => 'realtime',
                    'last_source' => 'api',
                ],
            ],
            $this->settings->get_settings()
        );

        return [
            'enabled' => !empty($resolved['enabled']),
            'persisted' => !empty($resolved['persisted']),
            'session_key' => isset($resolved['session_key']) ? (string) $resolved['session_key'] : $sessionKey,
            'session_id' => isset($resolved['session_id']) && is_numeric($resolved['session_id'])
                ? (int) $resolved['session_id']
                : null,
            'session' => $resolved['session'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function log_session_message(string $sessionKey, array $message): void
    {
        if (!$this->sessionService || !$this->should_enforce_session_memory()) {
            return;
        }

        $sessionKey = sanitize_text_field($sessionKey);
        if ($sessionKey === '') {
            return;
        }

        try {
            $this->sessionService->record_message_by_session_key(
                $sessionKey,
                $message,
                $this->settings->get_settings()
            );
        } catch (Throwable $error) {
            unset($error);
        }
    }

    private function should_enforce_approvals(): bool
    {
        $settings = $this->settings->get_settings();
        return !array_key_exists('enable_approvals', $settings) || !empty($settings['enable_approvals']);
    }

    private function should_enforce_tracing(): bool
    {
        $settings = $this->settings->get_settings();
        return !array_key_exists('enable_tracing', $settings) || !empty($settings['enable_tracing']);
    }

    private function should_enforce_session_memory(): bool
    {
        $settings = $this->settings->get_settings();
        return !array_key_exists('enable_session_memory', $settings) || !empty($settings['enable_session_memory']);
    }
}
