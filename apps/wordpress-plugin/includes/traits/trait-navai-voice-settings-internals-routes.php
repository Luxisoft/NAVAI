<?php

if (!defined('ABSPATH')) {
    exit;
}

trait Navai_Voice_Settings_Internals_Routes_Trait
{
    private function collect_public_menu_routes(): array
    {
        if (!function_exists('wp_get_nav_menus') || !function_exists('wp_get_nav_menu_items')) {
            return [];
        }

        $menus = wp_get_nav_menus();
        if (!is_array($menus) || count($menus) === 0) {
            return [];
        }

        $itemsByDedupeKey = [];
        foreach ($menus as $menu) {
            if (!isset($menu->term_id)) {
                continue;
            }

            $items = wp_get_nav_menu_items((int) $menu->term_id, ['update_post_term_cache' => false]);
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_object($item) || !isset($item->ID, $item->title, $item->url)) {
                    continue;
                }

                $legacyId = absint((int) $item->ID);
                if ($legacyId <= 0) {
                    continue;
                }

                $url = esc_url_raw((string) $item->url);
                if (str_starts_with($url, '/')) {
                    $url = home_url($url);
                }
                if (!$this->is_navigable_url($url)) {
                    continue;
                }

                $title = trim(wp_strip_all_tags((string) $item->title));
                if ($title === '') {
                    /* translators: %d: WordPress menu item numeric ID. */
                    $title = sprintf(__('Menu item %d', 'navai-voice'), $legacyId);
                }

                $pluginHintParts = [];
                if (isset($item->type) && is_string($item->type)) {
                    $pluginHintParts[] = (string) $item->type;
                }
                if (isset($item->object) && is_string($item->object)) {
                    $pluginHintParts[] = (string) $item->object;
                }
                if (isset($item->type_label) && is_string($item->type_label)) {
                    $pluginHintParts[] = (string) $item->type_label;
                }
                $pluginMeta = $this->resolve_route_plugin_group($url, implode(' ', $pluginHintParts));

                $dedupeKey = $this->build_route_dedupe_key($title, $url);
                if (!isset($itemsByDedupeKey[$dedupeKey])) {
                    $routeKey = 'public:' . md5($dedupeKey);
                    $itemsByDedupeKey[$dedupeKey] = [
                        'key' => $routeKey,
                        'title' => $title,
                        'url' => $url,
                        'description' => __('Ruta publica seleccionada en menus de WordPress.', 'navai-voice'),
                        'synonyms' => $this->build_route_synonyms($title, $url),
                        'visibility' => 'public',
                        'roles' => [],
                        'legacy_ids' => [$legacyId],
                        'legacy_keys' => [],
                        'plugin_key' => $pluginMeta['key'],
                        'plugin_label' => $pluginMeta['label'],
                    ];
                    continue;
                }

                if (!in_array($legacyId, $itemsByDedupeKey[$dedupeKey]['legacy_ids'], true)) {
                    $itemsByDedupeKey[$dedupeKey]['legacy_ids'][] = $legacyId;
                }

                if (
                    $this->is_core_plugin_key((string) ($itemsByDedupeKey[$dedupeKey]['plugin_key'] ?? '')) &&
                    !$this->is_core_plugin_key((string) ($pluginMeta['key'] ?? ''))
                ) {
                    $itemsByDedupeKey[$dedupeKey]['plugin_key'] = (string) ($pluginMeta['key'] ?? 'wp-core');
                    $itemsByDedupeKey[$dedupeKey]['plugin_label'] = (string) ($pluginMeta['label'] ?? __('WordPress / Sitio', 'navai-voice'));
                }
            }
        }

        $items = array_values($itemsByDedupeKey);
        usort(
            $items,
            static fn(array $a, array $b): int => strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''))
        );

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collect_private_routes(array $settings): array
    {
        $customRoutes = $this->get_private_custom_routes($settings);
        if (count($customRoutes) === 0) {
            return [];
        }

        $availableRoles = $this->get_available_roles();
        $items = [];
        $seen = [];

        foreach ($customRoutes as $customRoute) {
            $roleToken = sanitize_key((string) ($customRoute['role'] ?? ''));
            if ($roleToken === '') {
                continue;
            }

            $roleLabel = isset($availableRoles[$roleToken]) ? (string) $availableRoles[$roleToken] : $roleToken;
            $url = esc_url_raw((string) ($customRoute['url'] ?? ''));
            if (!$this->is_navigable_url($url) || !$this->is_internal_site_url($url)) {
                continue;
            }

            $pluginKey = $this->sanitize_private_plugin_key((string) ($customRoute['plugin_key'] ?? 'wp-core'));
            if ($pluginKey === '') {
                $pluginKey = 'wp-core';
            }

            $pluginLabel = sanitize_text_field((string) ($customRoute['plugin_label'] ?? ''));
            if ($pluginLabel === '') {
                $pluginLabel = $this->resolve_private_plugin_label($pluginKey, '', []);
            }

            $routeId = $this->sanitize_private_custom_route_id((string) ($customRoute['id'] ?? ''));
            if ($routeId === '') {
                $routeId = $this->generate_private_custom_route_id($pluginKey, $roleToken, $url, 'persisted');
            }

            $baseDedupeKey = $this->build_url_dedupe_key($url);
            $dedupeKey = $pluginKey . '|' . $roleToken . '|' . $baseDedupeKey;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $title = $this->build_private_route_title_from_url($url);
            $customDescription = sanitize_text_field((string) ($customRoute['description'] ?? ''));
            if ($customDescription === '') {
                /* translators: %s: WordPress role label. */
                $customDescription = sprintf(__('Ruta privada personalizada para el rol %s.', 'navai-voice'), (string) $roleLabel);
            }
            $items[] = [
                'key' => 'private_custom:' . $routeId,
                'title' => $title,
                'url' => $url,
                'description' => $customDescription,
                'synonyms' => $this->build_route_synonyms($title, $url),
                'visibility' => 'private',
                'roles' => [$roleToken],
                'legacy_ids' => [],
                'legacy_keys' => [
                    'private:' . $roleToken . ':' . md5($baseDedupeKey),
                    'private:' . md5($baseDedupeKey),
                ],
                'plugin_key' => $pluginKey,
                'plugin_label' => $pluginLabel,
            ];
        }

        usort(
            $items,
            static fn(array $a, array $b): int => strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''))
        );

        return $items;
    }

    /**
     * @return array<int, array{
     *   title: string,
     *   url: string,
     *   capability: string,
     *   synonyms: array<int, string>,
     *   plugin_key: string,
     *   plugin_label: string
     * }>
     */
    private function collect_admin_panel_routes(): array
    {
        $routes = [];
        $seen = [];

        global $menu, $submenu;

        if (is_array($menu)) {
            foreach ($menu as $entry) {
                $route = $this->build_admin_route_from_menu_entry($entry, '', false);
                if (is_array($route)) {
                    $this->append_admin_route_if_new($routes, $seen, $route);
                }
            }
        }

        if (is_array($submenu)) {
            foreach ($submenu as $parentSlug => $entries) {
                if (!is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $route = $this->build_admin_route_from_menu_entry($entry, (string) $parentSlug, true);
                    if (is_array($route)) {
                        $this->append_admin_route_if_new($routes, $seen, $route);
                    }
                }
            }
        }

        foreach ($this->get_admin_fallback_routes() as $route) {
            $this->append_admin_route_if_new($routes, $seen, $route);
        }

        usort(
            $routes,
            static fn(array $a, array $b): int => strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''))
        );

        return $routes;
    }

    /**
     * @param array<int, array{
     *   title: string,
     *   url: string,
     *   capability: string,
     *   synonyms: array<int, string>,
     *   plugin_key: string,
     *   plugin_label: string
     * }> $routes
     * @param array<string, bool> $seen
     * @param array{
     *   title: string,
     *   url: string,
     *   capability: string,
     *   synonyms: array<int, string>,
     *   plugin_key: string,
     *   plugin_label: string
     * } $route
     */
    private function append_admin_route_if_new(array &$routes, array &$seen, array $route): void
    {
        $title = (string) ($route['title'] ?? '');
        $url = (string) ($route['url'] ?? '');
        if ($title === '' || !$this->is_navigable_url($url)) {
            return;
        }

        $dedupeKey = $this->build_url_dedupe_key($url);
        if (isset($seen[$dedupeKey])) {
            return;
        }

        $seen[$dedupeKey] = true;
        $routes[] = $route;
    }

    /**
     * @param mixed $entry
     * @return array{
     *   title: string,
     *   url: string,
     *   capability: string,
     *   synonyms: array<int, string>,
     *   plugin_key: string,
     *   plugin_label: string
     * }|null
     */
    private function build_admin_route_from_menu_entry($entry, string $parentSlug = '', bool $isSubmenu = false): ?array
    {
        if (!is_array($entry) || !isset($entry[0], $entry[2])) {
            return null;
        }

        $title = trim(wp_strip_all_tags((string) $entry[0]));
        if ($title === '') {
            return null;
        }

        $slug = trim((string) $entry[2]);
        if ($slug === '' || str_starts_with(strtolower($slug), 'separator')) {
            return null;
        }
        if ($this->is_non_clickable_admin_menu_slug($slug, $parentSlug, $isSubmenu)) {
            return null;
        }

        $url = $this->build_admin_menu_url($slug);
        if (!$this->is_navigable_url($url)) {
            return null;
        }
        if (!$this->is_internal_site_url($url)) {
            return null;
        }

        $capability = isset($entry[1]) ? $this->normalize_capability((string) $entry[1]) : 'read';
        $pluginMeta = $this->resolve_route_plugin_group($url, $this->sanitize_admin_menu_slug_for_plugin_hint($slug));

        return [
            'title' => $title,
            'url' => $url,
            'capability' => $capability,
            'synonyms' => $this->build_route_synonyms($title, $url),
            'plugin_key' => $pluginMeta['key'],
            'plugin_label' => $pluginMeta['label'],
        ];
    }

    private function build_admin_menu_url(string $slug): string
    {
        $cleanSlug = ltrim(trim($slug), '/');
        if ($cleanSlug === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $cleanSlug) === 1) {
            return esc_url_raw($cleanSlug);
        }

        if (str_starts_with($cleanSlug, '#')) {
            return '';
        }

        if (str_contains($cleanSlug, '.php')) {
            return esc_url_raw(admin_url($cleanSlug));
        }

        return esc_url_raw(add_query_arg('page', $cleanSlug, admin_url('admin.php')));
    }

    private function is_non_clickable_admin_menu_slug(string $slug, string $parentSlug, bool $isSubmenu): bool
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '') {
            return true;
        }

        if (preg_match('#^https?://#i', $normalizedSlug) === 1) {
            return true;
        }

        if (str_starts_with($normalizedSlug, '#')) {
            return true;
        }

        if (str_contains($normalizedSlug, '.php')) {
            return false;
        }

        if (!$isSubmenu) {
            global $submenu;
            if (is_array($submenu) && isset($submenu[$slug]) && is_array($submenu[$slug]) && count($submenu[$slug]) > 0) {
                return true;
            }
        }

        return !$this->is_registered_plugin_menu_slug($slug, $parentSlug);
    }

    private function is_registered_plugin_menu_slug(string $slug, string $parentSlug): bool
    {
        if (!function_exists('get_plugin_page_hookname')) {
            return true;
        }

        global $_registered_pages;
        if (!is_array($_registered_pages)) {
            return true;
        }

        $candidateParents = [];
        if ($parentSlug !== '') {
            $candidateParents[] = $parentSlug;
        }
        $candidateParents[] = '';

        foreach ($candidateParents as $candidateParent) {
            $hook = get_plugin_page_hookname($slug, $candidateParent);
            if (is_string($hook) && isset($_registered_pages[$hook])) {
                return true;
            }
        }

        return false;
    }

    private function sanitize_admin_menu_slug_for_plugin_hint(string $slug): string
    {
        $clean = trim($slug);
        if ($clean === '') {
            return '';
        }

        $parts = explode('?', $clean, 2);
        $pathPart = trim((string) ($parts[0] ?? ''));
        if ($pathPart === '') {
            return '';
        }

        if (!isset($parts[1])) {
            return $pathPart;
        }

        $query = trim((string) $parts[1]);
        if ($query === '') {
            return $pathPart;
        }

        $queryArgs = [];
        parse_str($query, $queryArgs);
        $hintArgs = [];
        foreach (['page', 'post_type', 'taxonomy'] as $allowedKey) {
            if (isset($queryArgs[$allowedKey]) && is_string($queryArgs[$allowedKey])) {
                $hintArgs[$allowedKey] = $queryArgs[$allowedKey];
            }
        }

        if (count($hintArgs) === 0) {
            return $pathPart;
        }

        return $pathPart . '?' . http_build_query($hintArgs);
    }

    /**
     * @return array<int, array{
     *   title: string,
     *   url: string,
     *   capability: string,
     *   synonyms: array<int, string>,
     *   plugin_key: string,
     *   plugin_label: string
     * }>
     */
    private function get_admin_fallback_routes(): array
    {
        $items = [
            ['title' => __('Escritorio', 'navai-voice'), 'url' => admin_url('index.php'), 'capability' => 'read'],
            ['title' => __('Entradas', 'navai-voice'), 'url' => admin_url('edit.php'), 'capability' => 'edit_posts'],
            ['title' => __('Medios', 'navai-voice'), 'url' => admin_url('upload.php'), 'capability' => 'upload_files'],
            ['title' => __('Paginas', 'navai-voice'), 'url' => admin_url('edit.php?post_type=page'), 'capability' => 'edit_pages'],
            ['title' => __('Comentarios', 'navai-voice'), 'url' => admin_url('edit-comments.php'), 'capability' => 'moderate_comments'],
            ['title' => __('Apariencia', 'navai-voice'), 'url' => admin_url('themes.php'), 'capability' => 'edit_theme_options'],
            ['title' => __('Plugins', 'navai-voice'), 'url' => admin_url('plugins.php'), 'capability' => 'activate_plugins'],
            ['title' => __('Usuarios', 'navai-voice'), 'url' => admin_url('users.php'), 'capability' => 'list_users'],
            ['title' => __('Herramientas', 'navai-voice'), 'url' => admin_url('tools.php'), 'capability' => 'edit_posts'],
            ['title' => __('Ajustes', 'navai-voice'), 'url' => admin_url('options-general.php'), 'capability' => 'manage_options'],
        ];

        $routes = [];
        foreach ($items as $item) {
            $title = sanitize_text_field((string) $item['title']);
            $url = esc_url_raw((string) $item['url']);
            if ($title === '' || !$this->is_navigable_url($url)) {
                continue;
            }

            $routes[] = [
                'title' => $title,
                'url' => $url,
                'capability' => $this->normalize_capability((string) ($item['capability'] ?? 'read')),
                'synonyms' => $this->build_route_synonyms($title, $url),
                'plugin_key' => 'wp-core',
                'plugin_label' => __('WordPress / Sitio', 'navai-voice'),
            ];
        }

        return $routes;
    }

    private function role_can_access_capability(string $roleKey, string $capability): bool
    {
        $cap = $this->normalize_capability($capability);
        if ($cap === '' || $cap === 'read') {
            return true;
        }

        if ($roleKey === 'administrator') {
            return true;
        }

        if (!function_exists('wp_roles')) {
            return false;
        }

        $roles = wp_roles();
        if (!is_object($roles) || !isset($roles->roles[$roleKey]) || !is_array($roles->roles[$roleKey])) {
            return false;
        }

        $roleData = $roles->roles[$roleKey];
        $capabilities = is_array($roleData['capabilities'] ?? null) ? $roleData['capabilities'] : [];
        if (count($capabilities) === 0) {
            return false;
        }

        $parts = preg_split('/[\s,|]+/', $cap) ?: [];
        foreach ($parts as $part) {
            $token = $this->normalize_capability((string) $part);
            if ($token !== '' && !empty($capabilities[$token])) {
                return true;
            }
        }

        return false;
    }

    private function normalize_capability(string $capability): string
    {
        $value = strtolower(trim($capability));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9_,|\s-]/', '', $value);
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private function is_core_plugin_key(string $pluginKey): bool
    {
        $normalized = strtolower(trim($pluginKey));
        return $normalized === '' || $normalized === 'wp-core' || $normalized === 'core' || $normalized === 'wordpress';
    }

    /**
     * @return array{key: string, label: string}
     */
    private function resolve_route_plugin_group(string $url, string $hint = ''): array
    {
        $default = [
            'key' => 'wp-core',
            'label' => __('WordPress / Sitio', 'navai-voice'),
        ];

        $lookup = $this->get_route_plugin_lookup();
        if (count($lookup) === 0) {
            return $default;
        }

        $tokens = [];
        if ($hint !== '') {
            $tokens = array_merge($tokens, $this->extract_plugin_tokens_from_string($hint));
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        if (is_string($path) && trim($path) !== '') {
            $tokens = array_merge($tokens, $this->extract_plugin_tokens_from_string($path));
            $fileName = strtolower((string) basename($path));
            if ($fileName !== '' && !in_array($fileName, ['index.php', 'admin.php'], true)) {
                $tokens[] = sanitize_title((string) preg_replace('/\.php$/i', '', $fileName));
            }
        }

        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && trim($query) !== '') {
            $queryArgs = [];
            parse_str($query, $queryArgs);

            foreach (['page', 'post_type', 'taxonomy'] as $queryKey) {
                if (!isset($queryArgs[$queryKey]) || !is_string($queryArgs[$queryKey])) {
                    continue;
                }

                $tokens = array_merge($tokens, $this->extract_plugin_tokens_from_string($queryArgs[$queryKey]));
            }
        }

        $tokens = array_values(array_unique(array_filter(array_map('sanitize_title', $tokens))));
        $matched = $this->detect_plugin_from_tokens($tokens, $lookup);
        if (is_array($matched)) {
            return $matched;
        }

        return $default;
    }

    /**
     * @param array<int, string> $tokens
     * @param array<string, array{key: string, label: string}> $lookup
     * @return array{key: string, label: string}|null
     */
    private function detect_plugin_from_tokens(array $tokens, array $lookup): ?array
    {
        foreach ($tokens as $token) {
            if (isset($lookup[$token])) {
                return $lookup[$token];
            }
        }

        foreach ($tokens as $token) {
            if (strlen($token) < 4) {
                continue;
            }

            foreach ($lookup as $slug => $meta) {
                if (
                    str_starts_with($token, $slug) ||
                    str_starts_with($slug, $token) ||
                    str_contains($token, $slug)
                ) {
                    return $meta;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extract_plugin_tokens_from_string(string $value): array
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return [];
        }

        $normalized = str_replace('\\', '/', $normalized);
        $segments = explode('/', trim($normalized, '/'));
        $tokens = [];

        foreach ($segments as $segment) {
            $segmentToken = sanitize_title($segment);
            if ($segmentToken !== '') {
                $tokens[] = $segmentToken;
            }

            $parts = preg_split('/[^a-z0-9]+/', $segment) ?: [];
            foreach ($parts as $part) {
                $clean = sanitize_key((string) $part);
                if ($clean !== '') {
                    $tokens[] = $clean;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return array<string, array{key: string, label: string}>
     */
    private function get_route_plugin_lookup(): array
    {
        static $lookup = null;
        if (is_array($lookup)) {
            return $lookup;
        }

        $lookup = [];
        foreach ($this->get_installed_plugins() as $plugin) {
            $pluginFile = isset($plugin['file']) ? (string) $plugin['file'] : '';
            $pluginName = isset($plugin['name']) ? sanitize_text_field((string) $plugin['name']) : '';
            $slug = $this->plugin_file_to_slug($pluginFile);
            if ($slug === '') {
                continue;
            }

            $meta = [
                'key' => 'plugin:' . $slug,
                'label' => $pluginName !== '' ? $pluginName : $slug,
            ];
            $lookup[$slug] = $meta;

            $slugParts = preg_split('/[-_]+/', $slug) ?: [];
            foreach ($slugParts as $part) {
                $cleanPart = sanitize_key((string) $part);
                if (strlen($cleanPart) >= 4 && !isset($lookup[$cleanPart])) {
                    $lookup[$cleanPart] = $meta;
                }
            }
        }

        return $lookup;
    }

    private function plugin_file_to_slug(string $pluginFile): string
    {
        $normalized = strtolower(plugin_basename($pluginFile));
        if (str_contains($normalized, '/')) {
            $pieces = explode('/', $normalized);
            return sanitize_title($pieces[0]);
        }

        return sanitize_title((string) (preg_replace('/\.php$/', '', $normalized) ?: $normalized));
    }

    private function build_route_dedupe_key(string $name, string $path): string
    {
        return strtolower(trim($name)) . '|' . strtolower(untrailingslashit($path));
    }

    private function build_url_dedupe_key(string $url): string
    {
        return strtolower(untrailingslashit(trim($url)));
    }

    private function build_role_badge_color(string $roleKey): string
    {
        $token = sanitize_key($roleKey);
        if ($token === '') {
            $token = 'role';
        }

        $seed = (int) hexdec(substr(md5($token), 0, 6));
        $hue = $seed % 360;
        $saturation = 62 + ($seed % 19);
        $lightness = 38 + (((int) floor($seed / 19)) % 14);

        return sprintf('hsl(%d %d%% %d%%)', $hue, $saturation, $lightness);
    }

    /**
     * @return array<int, string>
     */
    private function build_route_synonyms(string $name, string $path): array
    {
        $synonyms = [];

        $cleanName = sanitize_text_field($name);
        if ($cleanName !== '') {
            $synonyms[] = strtolower($cleanName);
        }

        foreach ($this->extract_path_segments($path) as $segment) {
            $synonyms[] = strtolower($segment);
        }

        return array_values(array_unique(array_filter($synonyms, static fn(string $value): bool => $value !== '')));
    }

    /**
     * @return array<int, string>
     */
    private function extract_path_segments(string $path): array
    {
        $segments = [];

        $relativePath = wp_parse_url($path, PHP_URL_PATH);
        if (!is_string($relativePath) || trim($relativePath) === '') {
            return $segments;
        }

        $parts = explode('/', trim($relativePath, '/'));
        foreach ($parts as $part) {
            $token = sanitize_title($part);
            if ($token !== '') {
                $segments[] = str_replace('-', ' ', $token);
            }
        }

        return array_values(array_unique($segments));
    }

    private function is_navigable_url(string $url): bool
    {
        $value = trim($url);
        if ($value === '' || $value === '#') {
            return false;
        }

        $normalized = strtolower($value);
        if (str_starts_with($normalized, 'javascript:')) {
            return false;
        }

        return true;
    }

    private function is_internal_site_url(string $url): bool
    {
        $value = trim($url);
        if ($value === '' || str_starts_with($value, '/')) {
            return true;
        }

        $targetHost = wp_parse_url($value, PHP_URL_HOST);
        if (!is_string($targetHost) || trim($targetHost) === '') {
            return true;
        }

        $targetHost = strtolower($targetHost);
        $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $adminHost = strtolower((string) wp_parse_url(admin_url('/'), PHP_URL_HOST));

        if ($targetHost === $homeHost || $targetHost === $adminHost) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{file: string, name: string}>
     */
    private function get_installed_plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        if (!is_array($plugins) || count($plugins) === 0) {
            return [];
        }

        $items = [];
        foreach ($plugins as $pluginFile => $data) {
            $name = isset($data['Name']) ? (string) $data['Name'] : $pluginFile;
            $name = trim($name) !== '' ? $name : $pluginFile;

            $items[] = [
                'file' => (string) $pluginFile,
                'name' => $name,
            ];
        }

        usort(
            $items,
            static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name'])
        );

        return $items;
    }

    private function resolve_admin_icon_url(): string
    {
        $transparentPath = NAVAI_VOICE_PATH . 'assets/img/icon_navai_transparent.png';
        if (file_exists($transparentPath)) {
            return NAVAI_VOICE_URL . 'assets/img/icon_navai_transparent.png';
        }

        return NAVAI_VOICE_URL . 'assets/img/icon_navai.jpg';
    }

    private function resolve_admin_menu_icon_url(): string
    {
        $iconUrl = $this->resolve_admin_icon_url();
        return trim($iconUrl) !== '' ? $iconUrl : 'dashicons-format-audio';
    }

    /**
     * @return array<string, mixed>
     */
    private function get_defaults(): array
    {
        return [
            'openai_api_key' => '',
            'default_model' => 'gpt-realtime',
            'default_voice' => 'marin',
            'default_instructions' => 'You are a helpful assistant.',
            'default_language' => 'English',
            'default_voice_accent' => 'neutral',
            'default_voice_tone' => 'friendly and professional',
            'tts_provider' => 'openai',
            'elevenlabs_api_key' => '',
            'elevenlabs_base_url' => 'https://api.elevenlabs.io',
            'elevenlabs_voice_id' => '',
            'elevenlabs_model_id' => '',
            'elevenlabs_output_format' => 'mp3_44100_128',
            'elevenlabs_optimize_streaming_latency' => 0,
            'elevenlabs_stability' => 0.5,
            'elevenlabs_similarity_boost' => 0.75,
            'elevenlabs_style' => 0.0,
            'elevenlabs_use_speaker_boost' => true,
            'realtime_turn_detection_mode' => 'server_vad',
            'realtime_interrupt_response' => true,
            'realtime_vad_threshold' => 0.5,
            'realtime_vad_silence_duration_ms' => 800,
            'realtime_vad_prefix_padding_ms' => 300,
            'client_secret_ttl' => 600,
            'allow_public_client_secret' => true,
            'allow_public_functions' => false,
            'enable_guardrails' => true,
            'enable_approvals' => true,
            'enable_tracing' => false,
            'enable_session_memory' => false,
            'enable_agents' => true,
            'enable_mcp' => false,
            'session_ttl_minutes' => 1440,
            'session_retention_days' => 30,
            'session_compaction_threshold' => 120,
            'session_compaction_keep_recent' => 80,
            'allowed_menu_item_ids' => [],
            'allowed_route_keys' => [],
            'allowed_plugin_files' => [],
            'manual_plugins' => '',
            'plugin_custom_functions' => [],
            'allowed_plugin_function_keys' => [],
            'frontend_display_mode' => 'global',
            'frontend_button_side' => 'left',
            'frontend_button_color_idle' => '#1263dc',
            'frontend_button_color_active' => '#ff5c84',
            'frontend_show_button_text' => true,
            'frontend_button_text_idle' => 'Talk to NAVAI',
            'frontend_button_text_active' => 'Stop NAVAI',
            'frontend_voice_input_mode' => 'vad',
            'frontend_text_input_enabled' => true,
            'frontend_text_placeholder' => 'Escribe un mensaje...',
            'frontend_auto_initialize' => false,
            'frontend_allow_assistant_stop_tool' => true,
            'private_custom_routes' => [],
            'route_descriptions' => [],
            'frontend_allowed_roles' => $this->get_default_frontend_roles(),
            'dashboard_language' => 'en',
            'active_tab' => 'navigation',
        ];
    }
}

