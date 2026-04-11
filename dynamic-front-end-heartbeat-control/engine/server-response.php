<?php
declare(strict_types=1);

defined('DFEHC_DEFAULT_RESPONSE_TIME') || define('DFEHC_DEFAULT_RESPONSE_TIME', 50.0);
defined('DFEHC_HEAD_NEG_TTL') || define('DFEHC_HEAD_NEG_TTL', 600);
defined('DFEHC_HEAD_POS_TTL') || define('DFEHC_HEAD_POS_TTL', WEEK_IN_SECONDS);
defined('DFEHC_SPIKE_OPT_EPS') || define('DFEHC_SPIKE_OPT_EPS', 0.1);
defined('DFEHC_BASELINE_EXP') || define('DFEHC_BASELINE_EXP', 7 * DAY_IN_SECONDS);
defined('DFEHC_CACHE_GROUP') || define('DFEHC_CACHE_GROUP', 'dfehc');

if (!function_exists('dfehc_ttl_jitter')) {
    function dfehc_ttl_jitter(int $max = 5): int
    {
        if ($max <= 0 || !function_exists('random_int')) {
            return 0;
        }
        try {
            return random_int(0, $max);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('dfehc_transient_cache_get')) {
    function dfehc_transient_cache_get(string $key, bool &$found = null)
    {
        if (!isset($GLOBALS['dfehc_transient_cache']) || !is_array($GLOBALS['dfehc_transient_cache'])) {
            $GLOBALS['dfehc_transient_cache'] = [];
        }
        if (array_key_exists($key, $GLOBALS['dfehc_transient_cache'])) {
            $found = true;
            return $GLOBALS['dfehc_transient_cache'][$key];
        }
        $found = false;
        return null;
    }
}

if (!function_exists('dfehc_transient_cache_set')) {
    function dfehc_transient_cache_set(string $key, $value): void
    {
        if (!isset($GLOBALS['dfehc_transient_cache']) || !is_array($GLOBALS['dfehc_transient_cache'])) {
            $GLOBALS['dfehc_transient_cache'] = [];
        }
        $GLOBALS['dfehc_transient_cache'][$key] = $value;
    }
}

if (!function_exists('dfehc_transient_cache_del')) {
    function dfehc_transient_cache_del(string $key): void
    {
        if (isset($GLOBALS['dfehc_transient_cache']) && is_array($GLOBALS['dfehc_transient_cache'])) {
            unset($GLOBALS['dfehc_transient_cache'][$key]);
        }
    }
}

if (!function_exists('dfehc_get_transient_cached')) {
    function dfehc_get_transient_cached(string $key)
    {
        $found = false;
        $v = dfehc_transient_cache_get($key, $found);
        if ($found) {
            return $v;
        }
        $v = get_transient($key);
        dfehc_transient_cache_set($key, $v);
        return $v;
    }
}

if (!function_exists('dfehc_delete_transient_cached')) {
    function dfehc_delete_transient_cached(string $key): bool
    {
        dfehc_transient_cache_del($key);
        return delete_transient($key);
    }
}

if (!function_exists('dfehc_host_token')) {
    function dfehc_host_token(): string
    {
        static $t = '';
        if ($t !== '') {
            return $t;
        }
        $host = @php_uname('n') ?: (defined('WP_HOME') ? WP_HOME : (function_exists('home_url') ? home_url() : 'unknown'));
        $salt = defined('DB_NAME') ? (string) DB_NAME : '';
        return $t = substr(md5((string) $host . $salt), 0, 10);
    }
}

if (!function_exists('dfehc_blog_id')) {
    function dfehc_blog_id(): int
    {
        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    }
}

if (!function_exists('dfehc_key')) {
    function dfehc_key(string $base): string
    {
        return $base . '_' . dfehc_blog_id() . '_' . dfehc_host_token();
    }
}

if (!function_exists('dfehc_store_lockfree')) {
    function dfehc_store_lockfree(string $key, $value, int $ttl): bool
    {
        if (function_exists('wp_cache_add') && wp_cache_add($key, $value, DFEHC_CACHE_GROUP, $ttl)) {
            return true;
        }
        $ok = set_transient($key, $value, $ttl);
        if ($ok) {
            dfehc_transient_cache_set($key, $value);
        }
        return (bool) $ok;
    }
}

if (!function_exists('dfehc_home_host')) {
    function dfehc_home_host(): string
    {
        static $host = null;
        if ($host !== null) {
            return $host;
        }
        $host = '';

        if (function_exists('home_url')) {
            $parsed = wp_parse_url((string) home_url());
            if (is_array($parsed) && isset($parsed['host'])) {
                $host = (string) $parsed['host'];
            }
        }
        return $host;
    }
}

if (!function_exists('dfehc_client_ip')) {
    function dfehc_client_ip(): string
    {
        $remote_raw = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        $remote_raw = sanitize_text_field($remote_raw);
        $remote = filter_var($remote_raw, FILTER_VALIDATE_IP) ? $remote_raw : '0.0.0.0';

        $is_valid_ip = static function (string $ip, bool $publicOnly): bool {
            $ip = trim($ip);
            if ($ip === '') {
                return false;
            }
            if ($publicOnly) {
                return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            }
            return filter_var($ip, FILTER_VALIDATE_IP) !== false;
        };

        $public_only = (bool) apply_filters('dfehc_client_ip_public_only', true);

        $trusted = (array) apply_filters('dfehc_trusted_proxies', []);
        $trusted = array_values(array_filter(array_map('trim', $trusted), 'strlen'));
        $remote_is_trusted = $trusted && in_array($remote, $trusted, true);

        if ($remote_is_trusted) {
            $headers = (array) apply_filters(
                'dfehc_proxy_ip_headers',
                ['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR']
            );

            foreach ($headers as $h) {
                $h = (string) $h;
                if ($h === '' || empty($_SERVER[$h])) {
                    continue;
                }

                $raw = (string) wp_unslash((string) $_SERVER[$h]);
                $raw = sanitize_text_field($raw);

                if ($h === 'HTTP_X_FORWARDED_FOR') {
                    $parts_raw = array_map('trim', explode(',', $raw));
                    $parts = [];
                    foreach ($parts_raw as $p) {
                        $p = trim(sanitize_text_field($p));
                        if ($p !== '') {
                            $parts[] = $p;
                        }
                    }

                    foreach ($parts as $cand) {
                        if ($is_valid_ip($cand, $public_only)) {
                            return (string) apply_filters('dfehc_client_ip', $cand);
                        }
                    }
                    foreach ($parts as $cand) {
                        if ($is_valid_ip($cand, false)) {
                            return (string) apply_filters('dfehc_client_ip', $cand);
                        }
                    }
                } else {
                    $cand = trim(sanitize_text_field($raw));
                    if ($is_valid_ip($cand, $public_only) || $is_valid_ip($cand, false)) {
                        return (string) apply_filters('dfehc_client_ip', $cand);
                    }
                }
            }
        }

        if (!$is_valid_ip($remote, false)) {
            $remote = '0.0.0.0';
        }

        return (string) apply_filters('dfehc_client_ip', $remote);
    }
}

function dfehc_get_server_response_time(): array
{
    $now = time();
    $default_ms = (float) apply_filters('dfehc_default_response_time', DFEHC_DEFAULT_RESPONSE_TIME);

    $defaults = [
        'main_response_ms' => null,
        'db_response_ms' => null,
        'method' => '',
        'measurements' => [],
        'recalibrated' => false,
        'timestamp' => current_time('mysql'),
        'baseline_used' => null,
        'spike_score' => 0.0,
        'ts_unix' => $now,
    ];

    $cacheKey = dfehc_key('dfehc_cached_response_data');
    $cached = dfehc_get_transient_cached($cacheKey);
    if ($cached !== false && is_array($cached)) {
        return array_merge($defaults, $cached);
    }

    if (function_exists('dfehc_is_high_traffic') && dfehc_is_high_traffic()) {
        $high = [
            'main_response_ms' => $default_ms,
            'db_response_ms' => null,
            'method' => 'throttled',
            'measurements' => [],
            'recalibrated' => false,
            'timestamp' => current_time('mysql'),
            'baseline_used' => null,
            'spike_score' => 0.0,
            'ts_unix' => $now,
        ];
        $ttl = (int) apply_filters('dfehc_high_traffic_cache_expiration', 300);
        $ttl += dfehc_ttl_jitter(5);
        dfehc_set_transient_noautoload($cacheKey, $high, $ttl);
        return $high;
    }

    if (!dfehc_rt_acquire_lock()) {
        return array_merge($defaults, is_array($cached) ? $cached : []);
    }

    try {
        $results = dfehc_perform_response_measurements($default_ms);

        $baselineKey = dfehc_key('dfehc_baseline_response_data');
        $spikeKey = dfehc_key('dfehc_spike_score');

        $baseline = dfehc_get_transient_cached($baselineKey);
        $prev_spike = (float) dfehc_get_transient_cached($spikeKey);
        $spike = $prev_spike;

        $max_age = (int) apply_filters('dfehc_max_baseline_age', DFEHC_BASELINE_EXP);

        if (is_array($baseline)) {
            $ts = isset($baseline['ts_unix']) && is_numeric($baseline['ts_unix']) ? (int) $baseline['ts_unix'] : strtotime($baseline['timestamp'] ?? 'now');
            if ($now - (int) $ts > $max_age) {
                dfehc_delete_transient_cached($baselineKey);
                $baseline = false;
            }
        }

        if ($baseline === false && $results['method'] === 'http_loopback' && $results['main_response_ms'] !== null && count((array) $results['measurements']) >= (int) apply_filters('dfehc_baseline_min_samples', 2)) {
            $exp = (int) apply_filters('dfehc_baseline_expiration', DFEHC_BASELINE_EXP);
            $results['timestamp'] = current_time('mysql');
            $results['ts_unix'] = $now;
            $exp += dfehc_ttl_jitter(5);
            dfehc_set_transient_noautoload($baselineKey, $results, $exp);
            $baseline = $results;
            $spike = 0.0;
        }

        $results['baseline_used'] = is_array($baseline);

        if (is_array($baseline) && $results['method'] === 'http_loopback' && isset($results['main_response_ms'], $baseline['main_response_ms'])) {
            $base_ms = max(1.0, (float) $baseline['main_response_ms']);
            $curr_ms = (float) $results['main_response_ms'];
            $factor = (float) apply_filters('dfehc_spike_threshold_factor', 2.0);
            $raw_inc = max(0.0, $curr_ms / $base_ms - $factor);
            $floor = (float) apply_filters('dfehc_spike_increment_floor', 0.25);
            $cap = (float) apply_filters('dfehc_spike_increment_cap', 3.0);
            $increment = min($cap, max($floor, $raw_inc));
            $decay = (float) apply_filters('dfehc_spike_decay', 0.25);
            $threshold = (float) apply_filters('dfehc_recalibrate_threshold', 5.0);

            if ($curr_ms > $base_ms * $factor) {
                $spike += $increment;
            } else {
                $spike = max(0.0, $spike - $decay);
            }

            if ($spike >= $threshold) {
                $results['timestamp'] = current_time('mysql');
                $results['ts_unix'] = $now;
                $exp = (int) apply_filters('dfehc_baseline_expiration', DFEHC_BASELINE_EXP);
                $exp += dfehc_ttl_jitter(5);
                dfehc_set_transient_noautoload($baselineKey, $results, $exp);
                $spike = 0.0;
                $results['recalibrated'] = true;
            }
        }

        $results['spike_score'] = $spike;

        if (abs($spike - $prev_spike) >= DFEHC_SPIKE_OPT_EPS) {
            $ttl = (int) DFEHC_BASELINE_EXP;
            $ttl += dfehc_ttl_jitter(5);
            dfehc_set_transient_noautoload($spikeKey, $spike, $ttl);
        }

        $exp = (int) apply_filters('dfehc_cache_expiration', 3 * MINUTE_IN_SECONDS);
        $exp += dfehc_ttl_jitter(5);
        dfehc_set_transient_noautoload($cacheKey, $results, $exp);

        return array_merge($defaults, $results);
    } finally {
        dfehc_rt_release_lock();
    }
}

function dfehc_perform_response_measurements(float $default_ms): array
{
    $now = time();
    $r = [
        'main_response_ms' => null,
        'db_response_ms' => null,
        'method' => 'http_loopback',
        'measurements' => [],
        'recalibrated' => false,
        'timestamp' => current_time('mysql'),
        'ts_unix' => $now,
    ];

    if (apply_filters('dfehc_disable_loopback', false) || (function_exists('wp_is_recovery_mode') && wp_is_recovery_mode())) {
        $r['method'] = 'throttled';
        $r['main_response_ms'] = $default_ms;
        $r['db_response_ms'] = $default_ms;
        return $r;
    }

    global $wpdb;
    try {
        $db_start = microtime(true);
        $wpdb->query('SELECT 1');
        $r['db_response_ms'] = (microtime(true) - $db_start) * 1000;
    } catch (\Throwable $e) {
        $r['db_response_ms'] = $default_ms;
    }

    $rest = function_exists('get_rest_url') ? (string) get_rest_url() : '';
    $url = $rest !== '' ? $rest : (function_exists('home_url') ? (string) home_url('/wp-json/') : '/wp-json/');
    if (function_exists('get_option') && !get_option('permalink_structure')) {
        $url = add_query_arg('rest_route', '/', home_url('/index.php'));
    }

    $ajax_fallback = function_exists('admin_url') ? (string) admin_url('admin-ajax.php?action=dfehc_ping') : '/wp-admin/admin-ajax.php?action=dfehc_ping';
    $use_ajax_fallback = false;

    $home_host = dfehc_home_host();

    $headers = (array) apply_filters('dfehc_probe_headers', [
        'Host' => $home_host ?: '',
        'Cache-Control' => 'max-age=0, must-revalidate',
        'X-DFEHC-Probe' => '1',
    ]);

    $hard_deadline = microtime(true) + (float) apply_filters('dfehc_total_timeout', (int) apply_filters('dfehc_request_timeout', 10) + 2);

    if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
        $probe_host = '';
        $probe_parsed = wp_parse_url((string) $url);
        if (is_array($probe_parsed) && isset($probe_parsed['host'])) {
            $probe_host = (string) $probe_parsed['host'];
        }

        $accessible = getenv('WP_ACCESSIBLE_HOSTS') ?: (defined('WP_ACCESSIBLE_HOSTS') ? WP_ACCESSIBLE_HOSTS : '');
        $allowed_hosts = array_filter(array_map('trim', explode(',', (string) $accessible)));

        $is_same_host = $home_host && $probe_host && strcasecmp((string) $home_host, (string) $probe_host) === 0;
        if (!$is_same_host && $probe_host !== '' && !in_array((string) $probe_host, $allowed_hosts, true)) {
            $use_ajax_fallback = true;
        }
    }

    if ($use_ajax_fallback || empty($url)) {
        $url = $ajax_fallback;
        $r['method'] = 'ajax_loopback';
    }

    $n = max(1, min((int) apply_filters('dfehc_num_requests', 3), 5));
    $sleep_us = (int) apply_filters('dfehc_request_pause_us', 50000);
    $timeout = (int) apply_filters('dfehc_request_timeout', 10);
    $sslverify = (bool) apply_filters('dfehc_ssl_verify', true);
    $get_fallback = (bool) apply_filters('dfehc_use_get_fallback', true);
    $use_head = (bool) apply_filters('dfehc_use_head_method', true);
    $redirection = (int) apply_filters('dfehc_redirection', 1);

    $scheme = '';
    $scheme_parsed = wp_parse_url((string) $url);
    if (is_array($scheme_parsed) && isset($scheme_parsed['scheme'])) {
        $scheme = (string) $scheme_parsed['scheme'];
    }

    $head_key = dfehc_key('dfehc_head_supported_' . md5($scheme . '|' . $url));
    $head_supported = dfehc_get_transient_cached($head_key);
    if ($head_supported === false) {
        $head_supported = null;
    }

    $negKey = dfehc_key('dfehc_probe_fail');

    static $probe_blocked = null;
    if ($probe_blocked === null) {
        $probe_blocked = (bool) dfehc_get_transient_cached($negKey);
    }
    if ($probe_blocked) {
        $r['method'] = 'failed';
        $r['main_response_ms'] = $default_ms;
        if ($r['db_response_ms'] === null) {
            $r['db_response_ms'] = $default_ms;
        }
        return $r;
    }

    $times = [];

    for ($i = 0; $i < $n; $i++) {
        $remaining = $hard_deadline - microtime(true);
        if ($remaining <= 0) {
            break;
        }

        $probe_url = add_query_arg('_dfehc_ts', sprintf('%.6f', microtime(true)), $url);

        $args = [
            'timeout' => max(1, min((int) ceil($remaining), $timeout)),
            'sslverify' => $sslverify,
            'headers' => $headers,
            'redirection' => min($redirection, 3),
            'reject_unsafe_urls' => true,
            'blocking' => true,
            'decompress' => false,
            'limit_response_size' => (int) apply_filters('dfehc_limit_response_size', 512),
        ];

        $start = microtime(true);
        $resp = null;

        if ($use_head && $head_supported !== 0) {
            $resp = wp_remote_head($probe_url, $args);
            if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) >= 400) {
                if ($head_supported === null) {
                    $ttl = (int) apply_filters('dfehc_head_negative_ttl', DFEHC_HEAD_NEG_TTL);
                    $ttl += dfehc_ttl_jitter(5);
                    dfehc_set_transient_noautoload($head_key, 0, $ttl);
                }
                $resp = null;
            } else {
                if ($head_supported === null) {
                    $ttl = (int) apply_filters('dfehc_head_positive_ttl', DFEHC_HEAD_POS_TTL);
                    $ttl += dfehc_ttl_jitter(5);
                    dfehc_set_transient_noautoload($head_key, 1, $ttl);
                }
            }
        }

        if ($resp === null && $get_fallback) {
            $resp = wp_remote_get($probe_url, $args);
        }

        $code = is_wp_error($resp) ? 0 : wp_remote_retrieve_response_code($resp);
        if (($code >= 200 && $code < 300) || $code === 304) {
            $times[] = (microtime(true) - $start) * 1000;
        }

        if (count($times) >= $n) {
            break;
        }

        if ($i < $n - 1 && $sleep_us > 0) {
            usleep($sleep_us);
        }
    }

    if ($times) {
        sort($times, SORT_NUMERIC);
        if (count($times) >= 3 && (bool) apply_filters('dfehc_trim_extremes', true)) {
            array_shift($times);
            array_pop($times);
        }
        $cnt = count($times);
        $r['measurements'] = $times;
        $r['main_response_ms'] = $cnt % 2 ? (float) $times[intdiv($cnt, 2)] : (float) (($times[$cnt / 2 - 1] + $times[$cnt / 2]) / 2);
    } else {
        $ttl = (int) apply_filters('dfehc_probe_fail_ttl', 60);
        $ttl += dfehc_ttl_jitter(5);
        dfehc_set_transient_noautoload($negKey, 1, $ttl);
        $probe_blocked = true;
        $r['method'] = 'failed';
    }

    if ($r['main_response_ms'] === null) {
        $r['main_response_ms'] = $default_ms;
    }
    if ($r['db_response_ms'] === null) {
        $r['db_response_ms'] = $default_ms;
    }

    return $r;
}

if (!function_exists('dfehc_rl_increment')) {
    function dfehc_rl_increment(string $key, int $ttl): int
    {
        if (wp_using_ext_object_cache() && function_exists('wp_cache_incr')) {
            wp_cache_add($key, 0, DFEHC_CACHE_GROUP, $ttl);
            $v = wp_cache_incr($key, 1, DFEHC_CACHE_GROUP);
            if ($v === false) {
                $curr = (int) wp_cache_get($key, DFEHC_CACHE_GROUP);
                $curr++;
                wp_cache_set($key, $curr, DFEHC_CACHE_GROUP, $ttl);
                return $curr;
            }
            if ((int) $v === 1) {
                wp_cache_set($key, (int) $v, DFEHC_CACHE_GROUP, $ttl);
            }
            return (int) $v;
        }

        $cnt = (int) dfehc_get_transient_cached($key);
        dfehc_set_transient_noautoload($key, $cnt + 1, $ttl);
        return $cnt + 1;
    }
}


function dfehc_ping_handler(): void
{
    $ip = dfehc_client_ip();
    $k = dfehc_key('dfehc_ping_rl_' . md5($ip));
    $window = (int) apply_filters('dfehc_ping_rl_ttl', 2);
    $limit  = (int) apply_filters('dfehc_ping_rl_limit', 2);

    $cnt = dfehc_rl_increment($k, $window);
    if ($cnt > $limit) {
        status_header(429);
        nocache_headers();
        wp_send_json_error('rate_limited', 429);
    }

    nocache_headers();
    wp_send_json_success('ok');
}

add_action('wp_ajax_dfehc_ping', 'dfehc_ping_handler');
if (apply_filters('dfehc_enable_public_ping', true)) {
    add_action('wp_ajax_nopriv_dfehc_ping', 'dfehc_ping_handler');
}

if (!function_exists('dfehc_rt_acquire_lock')) {
    function dfehc_rt_acquire_lock(): bool
    {
        $key = dfehc_key('dfehc_measure_lock');

        if (class_exists('WP_Lock')) {
            $lock = new WP_Lock($key, 60);
            if ($lock->acquire()) {
                $GLOBALS['dfehc_rt_lock'] = $lock;
                return true;
            }
            return false;
        }

        if (function_exists('wp_cache_add') && wp_cache_add($key, 1, DFEHC_CACHE_GROUP, 60)) {
            $GLOBALS['dfehc_rt_lock_cache_key'] = $key;
            return true;
        }

        $existing = get_transient($key);
        dfehc_transient_cache_set($key, $existing);

        if (false !== $existing) {
            return false;
        }

        if (set_transient($key, 1, 60)) {
            dfehc_transient_cache_set($key, 1);
            $GLOBALS['dfehc_rt_lock_transient_key'] = $key;
            return true;
        }

        return false;
    }
}

if (!function_exists('dfehc_rt_release_lock')) {
    function dfehc_rt_release_lock(): void
    {
        if (isset($GLOBALS['dfehc_rt_lock']) && $GLOBALS['dfehc_rt_lock'] instanceof WP_Lock) {
            $GLOBALS['dfehc_rt_lock']->release();
            unset($GLOBALS['dfehc_rt_lock']);
            return;
        }

        if (isset($GLOBALS['dfehc_rt_lock_cache_key'])) {
            wp_cache_delete($GLOBALS['dfehc_rt_lock_cache_key'], DFEHC_CACHE_GROUP);
            unset($GLOBALS['dfehc_rt_lock_cache_key']);
            return;
        }

        if (isset($GLOBALS['dfehc_rt_lock_transient_key'])) {
            dfehc_delete_transient_cached($GLOBALS['dfehc_rt_lock_transient_key']);
            unset($GLOBALS['dfehc_rt_lock_transient_key']);
        }
    }
}

if (!function_exists('dfehc_is_high_traffic')) {
    function dfehc_is_high_traffic(): bool
    {
        $flag_key = dfehc_key('dfehc_high_traffic_flag');
        $flag = dfehc_get_transient_cached($flag_key);
        if ($flag !== false) {
            return (bool) $flag;
        }

        $threshold = (int) apply_filters('dfehc_high_traffic_threshold', 100);
        $cnt_key = dfehc_key('dfehc_cached_visitor_cnt');
        $count = dfehc_get_transient_cached($cnt_key);
        if ($count === false) {
            $count = (int) apply_filters('dfehc_website_visitors', 0);
            dfehc_set_transient_noautoload($cnt_key, (int) $count, 60);
        }

        $load = apply_filters('dfehc_current_server_load', null);
        if (is_numeric($load)) {
            $max_load = (float) apply_filters('dfehc_high_traffic_load_threshold', 85.0);
            if ((float) $load >= $max_load) {
                dfehc_set_transient_noautoload($flag_key, 1, 60);
                return true;
            }
        }

        $high = ((int) $count) >= $threshold;
        dfehc_set_transient_noautoload($flag_key, $high ? 1 : 0, 60);

        return $high;
    }
}