<?php

/**
 * Minimal stand-ins for the few WordPress functions that unit-tested classes call.
 *
 * Filters run in priority order and respect `accepted_args`. Calls to `_doing_it_wrong()` are
 * recorded so tests can assert on them. Only loaded by tests/bootstrap.php.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

$GLOBALS['odds_comparison_test_filters']        = [];
$GLOBALS['odds_comparison_test_doing_it_wrong'] = [];

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['odds_comparison_test_filters'][$hook][$priority][] = [$callback, $acceptedArgs];

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $byPriority = $GLOBALS['odds_comparison_test_filters'][$hook] ?? [];
        ksort($byPriority);

        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as [$callback, $acceptedArgs]) {
                $value = $callback(...array_slice([$value, ...$args], 0, $acceptedArgs));
            }
        }

        return $value;
    }
}

if (!function_exists('remove_all_filters')) {
    function remove_all_filters(string $hook): bool
    {
        unset($GLOBALS['odds_comparison_test_filters'][$hook]);

        return true;
    }
}

if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong(string $function, string $message, string $version): void
    {
        $GLOBALS['odds_comparison_test_doing_it_wrong'][] = $message;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url, ?array $protocols = null): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $protocols !== null && $scheme !== '' && !in_array($scheme, $protocols, true) ? '' : $url;
    }
}

// Transients: in-memory store (expiry ignored).
$GLOBALS['odds_comparison_test_transients'] = [];

if (!function_exists('get_transient')) {
    function get_transient(string $name): mixed
    {
        return $GLOBALS['odds_comparison_test_transients'][$name] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $name, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['odds_comparison_test_transients'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $name): bool
    {
        unset($GLOBALS['odds_comparison_test_transients'][$name]);

        return true;
    }
}

// HTTP API: wp_safe_remote_get() returns queued responses and records requests.
$GLOBALS['odds_comparison_test_http_queue']    = [];
$GLOBALS['odds_comparison_test_http_requests'] = [];

if (!class_exists('WP_Error')) {
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps
    class WP_Error
    {
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = null
        ) {
        }

        public function get_error_message(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
        {
            return $this->message;
        }

        public function get_error_code(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
        {
            return $this->code;
        }

        public function get_error_data(): mixed // phpcs:ignore PSR1.Methods.CamelCapsMethodName
        {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_safe_remote_get')) {
    function wp_safe_remote_get(string $url, array $args = []): mixed
    {
        $GLOBALS['odds_comparison_test_http_requests'][] = ['url' => $url, 'args' => $args];

        return array_shift($GLOBALS['odds_comparison_test_http_queue'])
            ?? new WP_Error('http_request_failed', 'No queued response');
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(mixed $response): int|string
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers(mixed $response): mixed
    {
        return is_array($response) ? ($response['headers'] ?? []) : [];
    }
}

// Options: in-memory store.
$GLOBALS['odds_comparison_test_options'] = [];

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['odds_comparison_test_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['odds_comparison_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['odds_comparison_test_options'][$name]);

        return true;
    }
}

// Actions: recorded, and run through the filter registry like WordPress does.
$GLOBALS['odds_comparison_test_actions'] = [];

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        $GLOBALS['odds_comparison_test_actions'][] = [$hook, $args];
        apply_filters($hook, ...($args === [] ? [null] : $args));
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return add_filter($hook, $callback, $priority, $acceptedArgs);
    }
}

// i18n and sanitizing helpers (simplified, same contracts).
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
    }
}

if (!function_exists('add_option')) {
    function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
    {
        if (array_key_exists($name, $GLOBALS['odds_comparison_test_options'])) {
            return false;
        }

        $GLOBALS['odds_comparison_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null): string
    {
        return gmdate($format, $timestamp ?? time());
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        return true;
    }
}

// Template rendering (TableRenderer::render()): escaping, i18n output helpers, object cache.
if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return preg_match('#^https?://#i', $url) === 1 ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '';
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr($text);
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo esc_html($text);
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo esc_attr($text);
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = (string) $selected === (string) $current ? " selected='selected'" : '';

        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('locate_template')) {
    function locate_template(array $templates): string
    {
        return '';
    }
}

if (!function_exists('determine_locale')) {
    function determine_locale(): string
    {
        return 'en_GB';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data): string|false
    {
        return json_encode($data);
    }
}

$GLOBALS['odds_comparison_test_cache'] = [];

if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = ''): mixed
    {
        return $GLOBALS['odds_comparison_test_cache'][$group][$key] ?? false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
    {
        $GLOBALS['odds_comparison_test_cache'][$group][$key] = $data;

        return true;
    }
}

$GLOBALS['odds_comparison_test_cron'] = [];

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        foreach ($GLOBALS['odds_comparison_test_cron'] as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args) {
                return $event['timestamp'];
            }
        }

        return false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        $GLOBALS['odds_comparison_test_cron'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];

        return true;
    }
}

if (!function_exists('wp_unschedule_hook')) {
    function wp_unschedule_hook(string $hook): int
    {
        $before                               = count($GLOBALS['odds_comparison_test_cron']);
        $GLOBALS['odds_comparison_test_cron'] = array_values(array_filter(
            $GLOBALS['odds_comparison_test_cron'],
            static fn (array $event): bool => $event['hook'] !== $hook
        ));

        return $before - count($GLOBALS['odds_comparison_test_cron']);
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

if (!function_exists('spawn_cron')) {
    function spawn_cron(): void
    {
    }
}

$GLOBALS['odds_comparison_test_can'] = true;

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return (bool) $GLOBALS['odds_comparison_test_can'];
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Minimal REST request: route and parameters (array access, as in WordPress).
     *
     * @implements ArrayAccess<string, mixed>
     */
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses, PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps
    class WP_REST_Request implements ArrayAccess
    {
        /** @var array<string, mixed> */
        private array $params = [];

        public function __construct(private string $method = 'GET', private string $route = '')
        {
        }

        public function get_route(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
        {
            return $this->route;
        }

        public function set_param(string $key, mixed $value): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
        {
            $this->params[$key] = $value;
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->params[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->params[$offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->params[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->params[$offset]);
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    /**
     * Minimal REST response.
     */
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses, PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps
    class WP_REST_Response
    {
        public function __construct(private mixed $data = null, private int $status = 200)
        {
        }

        public function get_data(): mixed // phpcs:ignore PSR1.Methods.CamelCapsMethodName
        {
            return $this->data;
        }

        public function get_status(): int // phpcs:ignore PSR1.Methods.CamelCapsMethodName
        {
            return $this->status;
        }
    }
}

$GLOBALS['wpdb'] = new \OddsComparison\Tests\Stubs\Wpdb();
