<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

$GLOBALS['spread_em_test_state'] = [
    'roles' => [],
    'caps' => [],
    'options' => [],
    'user_meta' => [],
    'current_user_id' => 1,
    'transients' => [],
];

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value) {
        return $value;
    }
}

if (!function_exists('get_role')) {
    function get_role(string $role_name) {
        return $GLOBALS['spread_em_test_state']['roles'][$role_name] ?? null;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false) {
        return $GLOBALS['spread_em_test_state']['options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, $value): bool {
        $GLOBALS['spread_em_test_state']['options'][$key] = $value;
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $cap): bool {
        return !empty($GLOBALS['spread_em_test_state']['caps'][$cap]);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int {
        return (int) ($GLOBALS['spread_em_test_state']['current_user_id'] ?? 1);
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta(int $user_id, string $key, bool $single = false) {
        if (!isset($GLOBALS['spread_em_test_state']['user_meta'][$user_id][$key])) {
            return $single ? '' : [];
        }

        return $GLOBALS['spread_em_test_state']['user_meta'][$user_id][$key];
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key) {
        return $GLOBALS['spread_em_test_state']['transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration = 0): bool {
        $GLOBALS['spread_em_test_state']['transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool {
        unset($GLOBALS['spread_em_test_state']['transients'][$key]);
        return true;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string {
        return trim($value);
    }
}

if (!function_exists('absint')) {
    function absint($value): int {
        return abs((int) $value);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string {
        return '123e4567-e89b-12d3-a456-426614174000';
    }
}

require_once __DIR__ . '/../spread-em/includes/class-spread-em-permissions.php';
require_once __DIR__ . '/../spread-em/includes/class-spread-em-ajax.php';
