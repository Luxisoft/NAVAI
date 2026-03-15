<?php

if (!defined('ABSPATH')) {
    exit;
}

trait Navai_Voice_Settings_Internals_Values_Trait
{
    private function read_text_value(
        array $source,
        array $previous,
        array $defaults,
        string $key,
        bool $fallbackToDefaultWhenEmpty
    ): string {
        $raw = array_key_exists($key, $source) ? (string) $source[$key] : (string) ($previous[$key] ?? $defaults[$key] ?? '');
        $value = sanitize_text_field($raw);

        if ($fallbackToDefaultWhenEmpty && trim($value) === '') {
            $value = sanitize_text_field((string) ($defaults[$key] ?? ''));
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private function sanitize_color_value($value, string $fallback): string
    {
        $sanitizedFallback = sanitize_hex_color($fallback);
        if (!is_string($sanitizedFallback) || trim($sanitizedFallback) === '') {
            $sanitizedFallback = '#1263dc';
        }

        $sanitized = sanitize_hex_color((string) $value);
        if (!is_string($sanitized) || trim($sanitized) === '') {
            return $sanitizedFallback;
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $defaults
     */
    private function read_textarea_value(
        array $source,
        array $previous,
        array $defaults,
        string $key,
        bool $fallbackToDefaultWhenEmpty
    ): string {
        $raw = array_key_exists($key, $source) ? (string) $source[$key] : (string) ($previous[$key] ?? $defaults[$key] ?? '');
        $value = sanitize_textarea_field($raw);

        if ($fallbackToDefaultWhenEmpty && trim($value) === '') {
            $value = sanitize_textarea_field((string) ($defaults[$key] ?? ''));
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function sanitize_menu_item_ids($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            $id = absint($item);
            if ($id > 0) {
                $clean[] = $id;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function sanitize_route_keys($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            $key = strtolower(trim((string) $item));
            $key = preg_replace('/[^a-z0-9:_-]/', '', $key);
            if (is_string($key) && $key !== '') {
                $clean[] = $key;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param mixed $value
     * @param array<int, string> $allowedKeys
     * @return array<int, string>
     */
    private function sanitize_plugin_function_keys($value, array $allowedKeys = []): array
    {
        $keys = $this->sanitize_route_keys($value);
        if (count($keys) === 0 || count($allowedKeys) === 0) {
            return $keys;
        }

        $allowedLookup = array_fill_keys($allowedKeys, true);
        $filtered = [];
        foreach ($keys as $key) {
            if (isset($allowedLookup[$key])) {
                $filtered[] = $key;
            }
        }

        return array_values(array_unique($filtered));
    }

    /**
     * @param mixed $value
     * @param array<int, string> $allowedRouteKeys
     * @return array<string, string>
     */
    private function sanitize_route_descriptions($value, array $allowedRouteKeys = []): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowedLookup = [];
        if (count($allowedRouteKeys) > 0) {
            $allowedLookup = array_fill_keys(array_values(array_unique($allowedRouteKeys)), true);
        }

        $items = [];
        foreach ($value as $rawKey => $rawDescription) {
            $key = strtolower(trim((string) $rawKey));
            $key = preg_replace('/[^a-z0-9:_-]/', '', $key);
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (count($allowedLookup) > 0 && !isset($allowedLookup[$key])) {
                continue;
            }

            $description = sanitize_text_field((string) $rawDescription);
            if (trim($description) === '') {
                continue;
            }

            // Ignore legacy auto-filled descriptions from older UI versions.
            $normalizedDescription = strtolower(trim($description));
            $legacyAutoDescriptions = [
                strtolower('Ruta publica seleccionada en menus de WordPress.'),
                strtolower('Ruta privada seleccionada en WordPress.'),
                strtolower('Ruta de menu seleccionada en WordPress.'),
                strtolower('Ruta publica seleccionada en WordPress.'),
                strtolower('Ruta privada personalizada.'),
                strtolower('Public route selected from WordPress menus.'),
                strtolower('Private route selected in WordPress.'),
                strtolower('Menu route selected in WordPress.'),
                strtolower('Public route selected in WordPress.'),
                strtolower('Custom private route.'),
                strtolower('Main site page.'),
            ];
            if (in_array($normalizedDescription, $legacyAutoDescriptions, true)) {
                continue;
            }

            $items[$key] = $description;
        }

        return $items;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function sanitize_plugin_files($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            $pluginFile = plugin_basename((string) $item);
            $pluginFile = trim($pluginFile);
            if ($pluginFile !== '') {
                $clean[] = $pluginFile;
            }
        }

        return array_values(array_unique($clean));
    }

    private function sanitize_manual_plugins(string $value): string
    {
        $parts = preg_split('/[\r\n,]+/', $value) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token !== '') {
                $clean[] = sanitize_text_field($token);
            }
        }

        return implode("\n", array_values(array_unique($clean)));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function sanitize_frontend_roles($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowed = array_merge(['guest'], array_keys($this->get_available_roles()));
        $allowedLookup = array_fill_keys($allowed, true);
        $clean = [];

        foreach ($value as $item) {
            $role = sanitize_key((string) $item);
            if ($role === '' || !isset($allowedLookup[$role])) {
                continue;
            }

            $clean[] = $role;
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param mixed $value
     */
    private function sanitize_dashboard_language($value): string
    {
        $lang = sanitize_key((string) $value);
        if (!in_array($lang, ['en', 'es', 'pt', 'fr', 'ru', 'ko', 'ja', 'zh', 'hi'], true)) {
            return 'en';
        }

        return $lang;
    }

    /**
     * Preloaded language labels for OpenAI audio/realtime usage.
     * Stored value remains the label to preserve compatibility with existing settings/API payloads.
     *
     * @return array<int, string>
     */
    private function get_realtime_language_options(): array
    {
        return [
            'Afrikaans',
            'Arabic',
            'Armenian',
            'Azerbaijani',
            'Belarusian',
            'Bosnian',
            'Bulgarian',
            'Catalan',
            'Chinese',
            'Croatian',
            'Czech',
            'Danish',
            'Dutch',
            'English',
            'Estonian',
            'Finnish',
            'French',
            'Galician',
            'German',
            'Greek',
            'Hebrew',
            'Hindi',
            'Hungarian',
            'Icelandic',
            'Indonesian',
            'Italian',
            'Japanese',
            'Kannada',
            'Kazakh',
            'Korean',
            'Latvian',
            'Lithuanian',
            'Macedonian',
            'Malay',
            'Marathi',
            'Maori',
            'Nepali',
            'Norwegian',
            'Persian',
            'Polish',
            'Portuguese',
            'Romanian',
            'Russian',
            'Serbian',
            'Slovak',
            'Slovenian',
            'Spanish',
            'Swahili',
            'Swedish',
            'Tagalog',
            'Tamil',
            'Thai',
            'Turkish',
            'Ukrainian',
            'Urdu',
            'Vietnamese',
            'Welsh',
        ];
    }

    /**
     * Preloaded OpenAI Realtime model IDs suitable for NAVAI voice agent usage.
     *
     * @return array<int, string>
     */
    private function get_realtime_model_options(): array
    {
        return [
            'gpt-realtime-mini',
            'gpt-realtime-mini-2025-10-06',
            'gpt-realtime-mini-2025-12-15',
            'gpt-realtime',
            'gpt-realtime-1.5',
            'gpt-realtime-2025-08-28',
            'gpt-4o-realtime-preview',
            'gpt-4o-realtime-preview-2024-10-01',
            'gpt-4o-realtime-preview-2024-12-17',
            'gpt-4o-realtime-preview-2025-06-03',
        ];
    }

    /**
     * Preloaded OpenAI Realtime built-in voices.
     *
     * @return array<int, string>
     */
    private function get_realtime_voice_options(): array
    {
        return [
            'alloy',
            'ash',
            'ballad',
            'coral',
            'echo',
            'sage',
            'shimmer',
            'verse',
            'marin',
            'cedar',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function get_available_roles(): array
    {
        if (!function_exists('wp_roles')) {
            return [];
        }

        $roles = wp_roles();
        if (!is_object($roles) || !isset($roles->roles) || !is_array($roles->roles)) {
            return [];
        }

        $items = [];
        foreach ($roles->roles as $roleKey => $roleData) {
            $key = sanitize_key((string) $roleKey);
            if ($key === '') {
                continue;
            }

            $label = is_array($roleData) && isset($roleData['name']) ? (string) $roleData['name'] : $key;
            $items[$key] = translate_user_role($label);
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function get_default_frontend_roles(): array
    {
        $roles = array_keys($this->get_available_roles());
        array_unshift($roles, 'guest');
        return array_values(array_unique($roles));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<int, string>
     */
    private function get_selected_route_keys(array $settings): array
    {
        $keys = $this->sanitize_route_keys($settings['allowed_route_keys'] ?? []);
        $keys = $this->map_legacy_route_keys($keys);
        if (count($keys) > 0) {
            return $keys;
        }

        $legacyIds = $this->sanitize_menu_item_ids($settings['allowed_menu_item_ids'] ?? []);
        if (count($legacyIds) === 0) {
            return [];
        }

        return $this->map_legacy_menu_item_ids_to_route_keys($legacyIds);
    }

    /**
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    private function map_legacy_route_keys(array $keys): array
    {
        if (count($keys) === 0) {
            return [];
        }

        $catalog = $this->get_navigation_catalog();
        $legacyMap = is_array($catalog['legacy_route_key_map'] ?? null) ? $catalog['legacy_route_key_map'] : [];
        if (count($legacyMap) === 0) {
            return array_values(array_unique($keys));
        }

        $mapped = [];
        foreach ($keys as $key) {
            if (isset($legacyMap[$key])) {
                $legacyTarget = $legacyMap[$key];
                if (is_string($legacyTarget) && $legacyTarget !== '') {
                    $mapped[] = $legacyTarget;
                    continue;
                }

                if (is_array($legacyTarget)) {
                    foreach ($legacyTarget as $mappedKey) {
                        if (is_string($mappedKey) && trim($mappedKey) !== '') {
                            $mapped[] = $mappedKey;
                        }
                    }
                    continue;
                }

                continue;
            }

            $mapped[] = $key;
        }

        return array_values(array_unique($mapped));
    }

    /**
     * @param array<int, int> $legacyIds
     * @return array<int, string>
     */
    private function map_legacy_menu_item_ids_to_route_keys(array $legacyIds): array
    {
        if (count($legacyIds) === 0) {
            return [];
        }

        $catalog = $this->get_navigation_catalog();
        $legacyMap = is_array($catalog['legacy_menu_id_map'] ?? null) ? $catalog['legacy_menu_id_map'] : [];

        $keys = [];
        foreach ($legacyIds as $legacyId) {
            if (isset($legacyMap[$legacyId]) && is_string($legacyMap[$legacyId])) {
                $keys[] = $legacyMap[$legacyId];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function get_current_user_roles(): array
    {
        if (!is_user_logged_in()) {
            return [];
        }

        $user = wp_get_current_user();
        if (!($user instanceof WP_User) || !is_array($user->roles)) {
            return [];
        }

        $roles = [];
        foreach ($user->roles as $role) {
            $key = sanitize_key((string) $role);
            if ($key !== '') {
                $roles[] = $key;
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param mixed $value
     */
    private function sanitize_realtime_turn_detection_mode($value): string
    {
        $mode = sanitize_key((string) $value);
        if (!in_array($mode, ['server_vad', 'semantic_vad', 'none'], true)) {
            return 'server_vad';
        }

        return $mode;
    }

    /**
     * @param mixed $value
     */
    private function sanitize_frontend_voice_input_mode($value): string
    {
        $mode = sanitize_key((string) $value);
        if (!in_array($mode, ['vad', 'ptt'], true)) {
            return 'vad';
        }

        return $mode;
    }

    /**
     * @param mixed $value
     */
    private function sanitize_int_range_value($value, int $fallback, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $fallback;
        if ($number < $min || $number > $max) {
            return $fallback;
        }

        return $number;
    }

    /**
     * @param mixed $value
     */
    private function sanitize_float_range_value($value, float $fallback, float $min, float $max, int $precision = 2): float
    {
        $number = is_numeric($value) ? (float) $value : $fallback;
        if (!is_finite($number) || $number < $min || $number > $max) {
            $number = $fallback;
        }

        return round($number, $precision);
    }

    /**
     * @return array<string, mixed>
     */
    private function apply_environment_overrides(array $settings): array
    {
        $stringOverrides = [
            'OPENAI_API_KEY' => ['key' => 'openai_api_key', 'multiline' => false],
            'OPENAI_REALTIME_MODEL' => ['key' => 'default_model', 'multiline' => false],
            'OPENAI_REALTIME_VOICE' => ['key' => 'default_voice', 'multiline' => false],
            'OPENAI_REALTIME_INSTRUCTIONS' => ['key' => 'default_instructions', 'multiline' => true],
            'OPENAI_REALTIME_LANGUAGE' => ['key' => 'default_language', 'multiline' => false],
            'OPENAI_REALTIME_VOICE_ACCENT' => ['key' => 'default_voice_accent', 'multiline' => false],
            'OPENAI_REALTIME_VOICE_TONE' => ['key' => 'default_voice_tone', 'multiline' => false],
            'NAVAI_TTS_PROVIDER' => ['key' => 'tts_provider', 'multiline' => false],
            'ELEVENLABS_API_KEY' => ['key' => 'elevenlabs_api_key', 'multiline' => false],
            'ELEVENLABS_BASE_URL' => ['key' => 'elevenlabs_base_url', 'multiline' => false],
            'ELEVENLABS_VOICE_ID' => ['key' => 'elevenlabs_voice_id', 'multiline' => false],
            'ELEVENLABS_MODEL_ID' => ['key' => 'elevenlabs_model_id', 'multiline' => false],
            'ELEVENLABS_OUTPUT_FORMAT' => ['key' => 'elevenlabs_output_format', 'multiline' => false],
        ];

        foreach ($stringOverrides as $envKey => $meta) {
            $override = $this->read_environment_override($envKey);
            if ($override === null) {
                continue;
            }

            $clean = !empty($meta['multiline'])
                ? sanitize_textarea_field((string) $override)
                : sanitize_text_field((string) $override);
            if (trim($clean) === '') {
                continue;
            }

            $settings[(string) $meta['key']] = $clean;
        }

        $ttlOverride = $this->read_environment_override('OPENAI_REALTIME_CLIENT_SECRET_TTL');
        if ($ttlOverride !== null) {
            $settings['client_secret_ttl'] = $this->sanitize_int_range_value(
                $ttlOverride,
                (int) ($settings['client_secret_ttl'] ?? 600),
                10,
                7200
            );
        }

        $optimizeLatencyOverride = $this->read_environment_override('ELEVENLABS_OPTIMIZE_STREAMING_LATENCY');
        if ($optimizeLatencyOverride !== null) {
            $settings['elevenlabs_optimize_streaming_latency'] = $this->sanitize_int_range_value(
                $optimizeLatencyOverride,
                (int) ($settings['elevenlabs_optimize_streaming_latency'] ?? 0),
                0,
                4
            );
        }

        $floatOverrides = [
            'ELEVENLABS_STABILITY' => ['key' => 'elevenlabs_stability', 'min' => 0.0, 'max' => 1.0, 'precision' => 2],
            'ELEVENLABS_SIMILARITY_BOOST' => ['key' => 'elevenlabs_similarity_boost', 'min' => 0.0, 'max' => 1.0, 'precision' => 2],
            'ELEVENLABS_STYLE' => ['key' => 'elevenlabs_style', 'min' => 0.0, 'max' => 1.0, 'precision' => 2],
        ];

        foreach ($floatOverrides as $envKey => $meta) {
            $override = $this->read_environment_override($envKey);
            if ($override === null) {
                continue;
            }

            $settings[(string) $meta['key']] = $this->sanitize_float_range_value(
                $override,
                (float) ($settings[(string) $meta['key']] ?? 0.0),
                (float) $meta['min'],
                (float) $meta['max'],
                (int) $meta['precision']
            );
        }

        $speakerBoostOverride = $this->parse_environment_bool($this->read_environment_override('ELEVENLABS_USE_SPEAKER_BOOST'));
        if ($speakerBoostOverride !== null) {
            $settings['elevenlabs_use_speaker_boost'] = $speakerBoostOverride;
        }

        $settings['tts_provider'] = $this->sanitize_tts_provider($settings['tts_provider'] ?? 'openai');

        return $settings;
    }

    /**
     * @param mixed $value
     */
    private function sanitize_tts_provider($value): string
    {
        return sanitize_key((string) $value) === 'elevenlabs' ? 'elevenlabs' : 'openai';
    }

    /**
     * @return mixed
     */
    private function read_environment_override(string $key)
    {
        if (defined($key)) {
            return constant($key);
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function parse_environment_bool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }
}

