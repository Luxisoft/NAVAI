<?php

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Navai_Voice_Plugin', false)) {
    return;
}

require_once __DIR__ . '/traits/trait-navai-voice-plugin-helpers.php';

class Navai_Voice_Plugin
{
    private Navai_Voice_Settings $settings;
    private Navai_Voice_API $api;
    private ?Navai_Voice_Privacy $privacy = null;
    private const DEFAULT_WEBRTC_URL = 'https://api.openai.com/v1/realtime/calls';
    use Navai_Voice_Plugin_Helpers_Trait;

    public function __construct()
    {
        $this->settings = new Navai_Voice_Settings();
        $this->api = new Navai_Voice_API($this->settings);
        if (class_exists('Navai_Voice_Privacy', false)) {
            $this->privacy = new Navai_Voice_Privacy();
        }
    }

    public function init(): void
    {
        if (class_exists('Navai_Voice_Migrator', false)) {
            Navai_Voice_Migrator::maybe_migrate();
        }

        add_action('admin_menu', [$this->settings, 'register_menu']);
        add_action('admin_init', [$this->settings, 'register_settings']);
        add_action('rest_api_init', [$this->api, 'register_routes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('admin_enqueue_scripts', [$this, 'register_assets_for_admin']);
        add_action('wp_footer', [$this, 'render_global_voice_widget']);
        add_action('admin_footer', [$this, 'render_global_voice_widget']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_inline_styles']);
        if ($this->privacy instanceof Navai_Voice_Privacy) {
            add_action('admin_init', [$this->privacy, 'register_privacy_policy_content']);
            add_filter('wp_privacy_personal_data_exporters', [$this->privacy, 'register_exporters']);
            add_filter('wp_privacy_personal_data_erasers', [$this->privacy, 'register_erasers']);
        }

        add_filter('plugin_action_links_' . NAVAI_VOICE_BASENAME, [$this, 'add_plugin_action_links']);
        add_shortcode('navai_voice', [$this, 'render_voice_shortcode']);
    }

    /**
     * @param string $hookSuffix
     */
    public function register_assets_for_admin(string $hookSuffix): void
    {
        unset($hookSuffix);
        $this->register_assets();

        $settings = $this->settings->get_settings();
        if ($this->resolve_frontend_display_mode($settings) !== 'global') {
            return;
        }
        if (!$this->can_render_global_widget_for_current_context($settings)) {
            return;
        }

        wp_enqueue_style('navai-voice');
        wp_enqueue_style('dashicons');
        wp_enqueue_script('navai-voice');
    }

    /**
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public function add_plugin_action_links(array $links): array
    {
        $settingsUrl = admin_url('admin.php?page=' . Navai_Voice_Settings::PAGE_SLUG);
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url($settingsUrl),
            esc_html__('Ajustes', 'navai-voice')
        );

        array_unshift($links, $settingsLink);
        return $links;
    }

    /**
     * @param string $hookSuffix
     */
    public function enqueue_admin_inline_styles(string $hookSuffix): void
    {
        wp_enqueue_style('common');

        $pluginRowSelector = sprintf(
            'tr[data-plugin="%s"] .plugin-title strong',
            NAVAI_VOICE_BASENAME
        );
        $menuId = 'toplevel_page_' . Navai_Voice_Settings::PAGE_SLUG;
        $inlineCss = sprintf(
            '#%1$s .wp-menu-image img { width: 23px !important; height: 23px !important; max-width: 23px !important; max-height: 23px !important; padding-top: 5px !important; object-fit: contain !important; }',
            sanitize_html_class($menuId)
        );

        if ($hookSuffix === 'plugins.php') {
            $inlineCss .= sprintf(
                '%1$s { display: inline-flex; align-items: center; gap: 8px; } %1$s::before { content: ""; width: 20px; height: 20px; background: url("%2$s") center/contain no-repeat; display: inline-block; }',
                $pluginRowSelector,
                esc_url_raw($this->resolve_admin_icon_url())
            );
        }

        wp_add_inline_style('common', $inlineCss);
    }

    /**
     * @param string $hookSuffix
     */
    public function enqueue_admin_assets(string $hookSuffix): void
    {
        $validHookSuffixes = [
            'toplevel_page_' . Navai_Voice_Settings::PAGE_SLUG,
            'settings_page_' . Navai_Voice_Settings::PAGE_SLUG,
        ];
        if (!in_array($hookSuffix, $validHookSuffixes, true)) {
            return;
        }

        wp_enqueue_style(
            'navai-voice-admin',
            NAVAI_VOICE_URL . 'assets/css/navai-admin.css',
            [],
            $this->resolve_asset_version('assets/css/navai-admin.css')
        );

        $functionCodeEditorSettings = null;
        if (function_exists('wp_enqueue_code_editor')) {
            $functionCodeEditorSettings = wp_enqueue_code_editor([
                'type' => 'application/javascript',
            ]);
            if (is_array($functionCodeEditorSettings)) {
                if (!isset($functionCodeEditorSettings['codemirror']) || !is_array($functionCodeEditorSettings['codemirror'])) {
                    $functionCodeEditorSettings['codemirror'] = [];
                }
                $functionCodeEditorSettings['codemirror']['mode'] = 'javascript';
                $functionCodeEditorSettings['codemirror']['lineNumbers'] = true;
                $functionCodeEditorSettings['codemirror']['indentUnit'] = 2;
                $functionCodeEditorSettings['codemirror']['tabSize'] = 2;
                $functionCodeEditorSettings['codemirror']['indentWithTabs'] = false;
                $functionCodeEditorSettings['codemirror']['lineWrapping'] = false;
                wp_enqueue_style('code-editor');
            }
        }

        wp_enqueue_script(
            'navai-voice-admin-translations-extra',
            NAVAI_VOICE_URL . 'assets/js/admin/navai-admin-translations-extra.js',
            [],
            $this->resolve_asset_version('assets/js/admin/navai-admin-translations-extra.js'),
            true
        );

        wp_enqueue_script(
            'navai-voice-admin-core',
            NAVAI_VOICE_URL . 'assets/js/admin/navai-admin-core.js',
            ['navai-voice-admin-translations-extra'],
            $this->resolve_asset_version('assets/js/admin/navai-admin-core.js'),
            true
        );

        $adminScriptDeps = ['navai-voice-admin-core'];
        if (is_array($functionCodeEditorSettings)) {
            $adminScriptDeps[] = 'code-editor';
        }

        wp_enqueue_script(
            'navai-voice-admin',
            NAVAI_VOICE_URL . 'assets/js/navai-admin.js',
            $adminScriptDeps,
            $this->resolve_asset_version('assets/js/navai-admin.js'),
            true
        );

        $settings = $this->settings->get_settings();
        $activeTab = isset($settings['active_tab']) && is_string($settings['active_tab'])
            ? $settings['active_tab']
            : 'navigation';

        wp_localize_script(
            'navai-voice-admin',
            'NAVAI_VOICE_ADMIN_CONFIG',
            [
                'activeTab' => $activeTab,
                'restBaseUrl' => esc_url_raw(rest_url('navai/v1')),
                'restNonce' => wp_create_nonce('wp_rest'),
                'dashboardLanguage' => isset($settings['dashboard_language']) && is_string($settings['dashboard_language'])
                    ? $settings['dashboard_language']
                    : 'en',
                'guardrailsEnabled' => !array_key_exists('enable_guardrails', $settings) || !empty($settings['enable_guardrails']),
                'approvalsEnabled' => !array_key_exists('enable_approvals', $settings) || !empty($settings['enable_approvals']),
                'tracingEnabled' => !array_key_exists('enable_tracing', $settings) || !empty($settings['enable_tracing']),
                'sessionMemoryEnabled' => !array_key_exists('enable_session_memory', $settings) || !empty($settings['enable_session_memory']),
                'agentsEnabled' => !array_key_exists('enable_agents', $settings) || !empty($settings['enable_agents']),
                'mcpEnabled' => !array_key_exists('enable_mcp', $settings) || !empty($settings['enable_mcp']),
                'functionCodeEditor' => is_array($functionCodeEditorSettings) ? $functionCodeEditorSettings : null,
            ]
        );
    }

    public function register_assets(): void
    {
        wp_register_style(
            'navai-voice',
            NAVAI_VOICE_URL . 'assets/css/navai-voice.css',
            [],
            $this->resolve_asset_version('assets/css/navai-voice.css')
        );

        wp_register_script(
            'navai-voice-core',
            NAVAI_VOICE_URL . 'assets/js/frontend/navai-voice-core.js',
            [],
            $this->resolve_asset_version('assets/js/frontend/navai-voice-core.js'),
            true
        );

        wp_register_script(
            'navai-voice-orb',
            NAVAI_VOICE_URL . 'assets/js/frontend/navai-voice-orb.js',
            [],
            $this->resolve_asset_version('assets/js/frontend/navai-voice-orb.js'),
            true
        );

        wp_register_script(
            'navai-voice',
            NAVAI_VOICE_URL . 'assets/js/navai-voice.js',
            ['navai-voice-core', 'navai-voice-orb'],
            $this->resolve_asset_version('assets/js/navai-voice.js'),
            true
        );

        $settings = $this->settings->get_runtime_settings();
        $turnDetectionMode = $this->sanitize_realtime_turn_detection_mode($settings['realtime_turn_detection_mode'] ?? 'server_vad');
        $voiceInputMode = $this->sanitize_frontend_voice_input_mode($settings['frontend_voice_input_mode'] ?? 'vad');
        $config = [
            'restBaseUrl' => esc_url_raw(rest_url('navai/v1')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'realtimeWebrtcUrl' => self::DEFAULT_WEBRTC_URL,
            'defaults' => [
                'model' => (string) ($settings['default_model'] ?? 'gpt-realtime'),
                'voice' => (string) ($settings['default_voice'] ?? 'marin'),
                'instructions' => (string) ($settings['default_instructions'] ?? 'You are a helpful assistant.'),
                'language' => (string) ($settings['default_language'] ?? ''),
                'voiceAccent' => (string) ($settings['default_voice_accent'] ?? ''),
                'voiceTone' => (string) ($settings['default_voice_tone'] ?? ''),
            ],
            'realtime' => [
                'turnDetectionMode' => $turnDetectionMode,
                'interruptResponse' => !array_key_exists('realtime_interrupt_response', $settings) || !empty($settings['realtime_interrupt_response']),
                'vad' => [
                    'threshold' => $this->sanitize_float_range_value($settings['realtime_vad_threshold'] ?? 0.5, 0.5, 0.1, 0.99, 2),
                    'silenceDurationMs' => $this->sanitize_int_range_value($settings['realtime_vad_silence_duration_ms'] ?? 800, 800, 100, 5000),
                    'prefixPaddingMs' => $this->sanitize_int_range_value($settings['realtime_vad_prefix_padding_ms'] ?? 300, 300, 0, 2000),
                ],
                'voiceInputMode' => $voiceInputMode,
                'textInputEnabled' => !array_key_exists('frontend_text_input_enabled', $settings) || !empty($settings['frontend_text_input_enabled']),
                'textPlaceholder' => sanitize_text_field((string) ($settings['frontend_text_placeholder'] ?? 'Escribe un mensaje...')),
            ],
            'speech' => [
                'provider' => $this->sanitize_tts_provider($settings['tts_provider'] ?? 'openai'),
            ],
            'widget' => [
                'autoInitializeOnLoad' => !empty($settings['frontend_auto_initialize']),
                'allowAssistantStopTool' => !array_key_exists('frontend_allow_assistant_stop_tool', $settings) || !empty($settings['frontend_allow_assistant_stop_tool']),
            ],
            'routes' => $this->resolve_public_routes(),
            'roadmapPhases' => $this->build_frontend_roadmap_phases($settings),
            'messages' => [
                'idle' => __('Idle', 'navai-voice'),
                'requestingSecret' => __('Requesting client secret...', 'navai-voice'),
                'requestingMicrophone' => __('Requesting microphone permission...', 'navai-voice'),
                'connectingRealtime' => __('Connecting realtime session...', 'navai-voice'),
                'connected' => __('Connected', 'navai-voice'),
                'connectedText' => __('Connected (text mode)', 'navai-voice'),
                'listening' => __('Listening...', 'navai-voice'),
                'speaking' => __('Speaking...', 'navai-voice'),
                'interrupted' => __('Interrupted', 'navai-voice'),
                'sendingText' => __('Sending text...', 'navai-voice'),
                'pttHold' => __('Hold to talk', 'navai-voice'),
                'pttRelease' => __('Release to send', 'navai-voice'),
                'stopping' => __('Stopping...', 'navai-voice'),
                'stopped' => __('Stopped', 'navai-voice'),
                'failed' => __('Failed to start voice session.', 'navai-voice'),
            ],
            'sessionMemoryEnabled' => !array_key_exists('enable_session_memory', $settings) || !empty($settings['enable_session_memory']),
        ];

        /**
         * @param array<string, mixed> $config
         * @param array<string, mixed> $settings
         */
        $config = apply_filters('navai_voice_frontend_config', $config, $settings);

        wp_localize_script('navai-voice', 'NAVAI_VOICE_CONFIG', $config);
    }

    private function resolve_asset_version(string $relativePath): string
    {
        $normalizedPath = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
        $absolutePath = trailingslashit(NAVAI_VOICE_PATH) . $normalizedPath;
        $mtime = @filemtime($absolutePath);
        if (is_numeric($mtime) && (int) $mtime > 0) {
            return NAVAI_VOICE_VERSION . '.' . (string) ((int) $mtime);
        }

        return NAVAI_VOICE_VERSION;
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function render_voice_shortcode(array $atts = []): string
    {
        $settings = $this->settings->get_runtime_settings();
        $displayMode = $this->resolve_frontend_display_mode($settings);
        if ($displayMode !== 'shortcode') {
            return '';
        }

        if (!$this->can_render_widget_for_current_user($settings)) {
            return '';
        }

        wp_enqueue_style('navai-voice');
        wp_enqueue_style('dashicons');
        wp_enqueue_script('navai-voice');

        $defaults = $settings;
        $voiceInputMode = $this->sanitize_frontend_voice_input_mode($settings['frontend_voice_input_mode'] ?? 'vad');
        $attributes = shortcode_atts(
            [
                'label' => $this->resolve_frontend_button_text(
                    $settings,
                    'frontend_button_text_idle',
                    __('Start Voice', 'navai-voice')
                ),
                'stop_label' => $this->resolve_frontend_button_text(
                    $settings,
                    'frontend_button_text_active',
                    __('Stop Voice', 'navai-voice')
                ),
                'model' => (string) ($defaults['default_model'] ?? ''),
                'voice' => (string) ($defaults['default_voice'] ?? ''),
                'instructions' => (string) ($defaults['default_instructions'] ?? ''),
                'language' => (string) ($defaults['default_language'] ?? ''),
                'voice_accent' => (string) ($defaults['default_voice_accent'] ?? ''),
                'voice_tone' => (string) ($defaults['default_voice_tone'] ?? ''),
                'debug' => '0',
                'class' => '',
            ],
            $atts
        );

        return $this->render_widget_markup(
            $attributes,
            [
                'floating' => false,
                'persist_active' => false,
                'button_side' => 'left',
                'button_color_idle' => $this->resolve_frontend_button_color($settings, 'frontend_button_color_idle', '#1263dc'),
                'button_color_active' => $this->resolve_frontend_button_color($settings, 'frontend_button_color_active', '#ff5c84'),
                'show_button_text' => $this->resolve_frontend_show_button_text($settings),
                'widget_mode' => 'shortcode',
                'show_status' => true,
                'voice_input_mode' => $voiceInputMode,
                'text_input_enabled' => !array_key_exists('frontend_text_input_enabled', $settings) || !empty($settings['frontend_text_input_enabled']),
                'auto_initialize' => !empty($settings['frontend_auto_initialize']),
                'assistant_stop_tool_enabled' => !array_key_exists('frontend_allow_assistant_stop_tool', $settings) || !empty($settings['frontend_allow_assistant_stop_tool']),
                'text_placeholder' => sanitize_text_field((string) ($settings['frontend_text_placeholder'] ?? 'Escribe un mensaje...')),
            ]
        );
    }

    public function render_global_voice_widget(): void
    {
        $settings = $this->settings->get_runtime_settings();
        if (!$this->can_render_global_widget_for_current_context($settings)) {
            return;
        }

        $displayMode = $this->resolve_frontend_display_mode($settings);
        if ($displayMode !== 'global') {
            return;
        }

        wp_enqueue_style('navai-voice');
        wp_enqueue_style('dashicons');
        wp_enqueue_script('navai-voice');
        $voiceInputMode = $this->sanitize_frontend_voice_input_mode($settings['frontend_voice_input_mode'] ?? 'vad');

        $widgetAttributes = [
            'label' => $this->resolve_frontend_button_text(
                $settings,
                'frontend_button_text_idle',
                __('Talk to NAVAI', 'navai-voice')
            ),
            'stop_label' => $this->resolve_frontend_button_text(
                $settings,
                'frontend_button_text_active',
                __('Stop NAVAI', 'navai-voice')
            ),
            'model' => (string) ($settings['default_model'] ?? ''),
            'voice' => (string) ($settings['default_voice'] ?? ''),
            'instructions' => (string) ($settings['default_instructions'] ?? ''),
            'language' => (string) ($settings['default_language'] ?? ''),
            'voice_accent' => (string) ($settings['default_voice_accent'] ?? ''),
            'voice_tone' => (string) ($settings['default_voice_tone'] ?? ''),
            'debug' => '0',
            'class' => '',
        ];
        $widgetOptions = [
            'floating' => true,
            'persist_active' => true,
            'button_side' => is_admin() ? 'right' : $this->resolve_frontend_button_side($settings),
            'button_color_idle' => $this->resolve_frontend_button_color($settings, 'frontend_button_color_idle', '#1263dc'),
            'button_color_active' => $this->resolve_frontend_button_color($settings, 'frontend_button_color_active', '#ff5c84'),
            'show_button_text' => $this->resolve_frontend_show_button_text($settings),
            'widget_mode' => 'global',
            'show_status' => false,
            'voice_input_mode' => $voiceInputMode,
            'text_input_enabled' => !array_key_exists('frontend_text_input_enabled', $settings) || !empty($settings['frontend_text_input_enabled']),
            'auto_initialize' => !empty($settings['frontend_auto_initialize']),
            'assistant_stop_tool_enabled' => !array_key_exists('frontend_allow_assistant_stop_tool', $settings) || !empty($settings['frontend_allow_assistant_stop_tool']),
            'text_placeholder' => sanitize_text_field((string) ($settings['frontend_text_placeholder'] ?? 'Escribe un mensaje...')),
        ];
        $widgetMarkup = $this->render_widget_markup($widgetAttributes, $widgetOptions);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped inside render_widget_markup().
        echo $widgetMarkup;
    }
}
