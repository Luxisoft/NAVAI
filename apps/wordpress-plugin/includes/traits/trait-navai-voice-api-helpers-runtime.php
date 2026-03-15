<?php

if (!defined('ABSPATH')) {
    exit;
}

trait Navai_Voice_API_Helpers_Runtime_Trait
{
    private function build_session_instructions(
        string $baseInstructions,
        string $language,
        string $voiceAccent,
        string $voiceTone
    ): string {
        $lines = [trim($baseInstructions) !== '' ? trim($baseInstructions) : 'You are a helpful assistant.'];

        $language = trim($language);
        if ($language !== '') {
            $lines[] = sprintf('Always reply in %s.', $language);
        }

        $voiceAccent = trim($voiceAccent);
        if ($voiceAccent !== '') {
            $lines[] = sprintf('Use a %s accent while speaking.', $voiceAccent);
        }

        $voiceTone = trim($voiceTone);
        if ($voiceTone !== '') {
            $lines[] = sprintf('Use a %s tone while speaking.', $voiceTone);
        }

        return implode("\n", $lines);
    }

    private function resolve_speech_provider(array $settings): string
    {
        return sanitize_key((string) ($settings['tts_provider'] ?? 'openai')) === 'elevenlabs'
            ? 'elevenlabs'
            : 'openai';
    }

    /**
     * @return WP_Error|null
     */
    private function validate_elevenlabs_runtime_settings(array $settings): ?WP_Error
    {
        $apiKey = trim((string) ($settings['elevenlabs_api_key'] ?? ''));
        if ($apiKey === '') {
            return new WP_Error(
                'navai_missing_elevenlabs_api_key',
                'Missing ElevenLabs API key in NAVAI runtime settings.',
                ['status' => 500]
            );
        }

        $voiceId = trim((string) ($settings['elevenlabs_voice_id'] ?? ''));
        if ($voiceId === '') {
            return new WP_Error(
                'navai_missing_elevenlabs_voice_id',
                'Missing ElevenLabs voice ID in NAVAI runtime settings.',
                ['status' => 500]
            );
        }

        return null;
    }

    private function get_elevenlabs_base_url(array $settings): string
    {
        $baseUrl = trim((string) ($settings['elevenlabs_base_url'] ?? 'https://api.elevenlabs.io'));
        if ($baseUrl === '') {
            $baseUrl = 'https://api.elevenlabs.io';
        }

        return rtrim($baseUrl, '/');
    }

    private function get_elevenlabs_synthesize_url(array $settings): string
    {
        $voiceId = sanitize_text_field((string) ($settings['elevenlabs_voice_id'] ?? ''));
        $url = $this->get_elevenlabs_base_url($settings) . '/v1/text-to-speech/' . rawurlencode($voiceId);

        $queryArgs = [];
        $outputFormat = sanitize_text_field((string) ($settings['elevenlabs_output_format'] ?? 'mp3_44100_128'));
        if ($outputFormat !== '') {
            $queryArgs['output_format'] = $outputFormat;
        }

        if (isset($settings['elevenlabs_optimize_streaming_latency']) && is_numeric($settings['elevenlabs_optimize_streaming_latency'])) {
            $queryArgs['optimize_streaming_latency'] = (string) ((int) $settings['elevenlabs_optimize_streaming_latency']);
        }

        if (count($queryArgs) === 0) {
            return $url;
        }

        return add_query_arg($queryArgs, $url);
    }

    /**
     * @return array<string, mixed>
     */
    private function build_elevenlabs_voice_settings(array $settings): array
    {
        $voiceSettings = [];

        foreach (['stability', 'similarity_boost', 'style'] as $field) {
            $settingKey = 'elevenlabs_' . $field;
            if (isset($settings[$settingKey]) && is_numeric($settings[$settingKey])) {
                $voiceSettings[$field] = round((float) $settings[$settingKey], 2);
            }
        }

        if (array_key_exists('elevenlabs_use_speaker_boost', $settings)) {
            $voiceSettings['use_speaker_boost'] = !empty($settings['elevenlabs_use_speaker_boost']);
        }

        return $voiceSettings;
    }

    /**
     * @return array{url: string, body: array<string, mixed>}
     */
    private function build_elevenlabs_synthesize_request(array $settings, string $text): array
    {
        $body = [
            'text' => $text,
        ];

        $modelId = trim((string) ($settings['elevenlabs_model_id'] ?? ''));
        if ($modelId !== '') {
            $body['model_id'] = $modelId;
        }

        $voiceSettings = $this->build_elevenlabs_voice_settings($settings);
        if (count($voiceSettings) > 0) {
            $body['voice_settings'] = $voiceSettings;
        }

        return [
            'url' => $this->get_elevenlabs_synthesize_url($settings),
            'body' => $body,
        ];
    }

    private function infer_audio_mime_type_from_output_format(array $settings): string
    {
        $outputFormat = strtolower(trim((string) ($settings['elevenlabs_output_format'] ?? 'mp3_44100_128')));
        if (str_starts_with($outputFormat, 'ogg_')) {
            return 'audio/ogg';
        }

        if (str_starts_with($outputFormat, 'pcm_')) {
            return 'audio/wav';
        }

        if (str_starts_with($outputFormat, 'ulaw_')) {
            return 'audio/basic';
        }

        return 'audio/mpeg';
    }

    private function check_rate_limit(): bool
    {
        $ip = $this->get_client_ip();
        $key = 'navai_voice_rl_' . md5($ip);
        $bucket = get_transient($key);
        $now = time();

        if (!is_array($bucket) || !isset($bucket['count'], $bucket['started_at'])) {
            $bucket = [
                'count' => 0,
                'started_at' => $now,
            ];
        }

        $windowSeconds = 60;
        $maxRequestsPerWindow = 30;
        $elapsed = $now - (int) $bucket['started_at'];
        if ($elapsed >= $windowSeconds) {
            $bucket = [
                'count' => 0,
                'started_at' => $now,
            ];
        }

        if ((int) $bucket['count'] >= $maxRequestsPerWindow) {
            return false;
        }

        $bucket['count'] = (int) $bucket['count'] + 1;
        set_transient($key, $bucket, $windowSeconds);

        return true;
    }

    private function get_client_ip(): string
    {
        $candidates = [
            isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_X_FORWARDED_FOR'])) : '',
            isset($_SERVER['HTTP_CLIENT_IP']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_CLIENT_IP'])) : '',
            isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '',
        ];

        foreach ($candidates as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $parts = explode(',', $value);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return 'unknown';
    }
}

