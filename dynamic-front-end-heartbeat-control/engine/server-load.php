<?php
declare(strict_types=1);

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!defined('DFEHC_CACHE_GROUP')) define('DFEHC_CACHE_GROUP', 'dfehc');
if (!defined('DFEHC_SERVER_LOAD_TTL')) define('DFEHC_SERVER_LOAD_TTL', 180);
if (!defined('DFEHC_SERVER_LOAD_CACHE_KEY')) define('DFEHC_SERVER_LOAD_CACHE_KEY', 'dfehc:server_load');
if (!defined('DFEHC_SERVER_LOAD_PAYLOAD_KEY')) define('DFEHC_SERVER_LOAD_PAYLOAD_KEY', 'dfehc_server_load_payload');
if (!defined('DFEHC_SERVER_LOAD_SCALAR_KEY')) define('DFEHC_SERVER_LOAD_SCALAR_KEY', 'dfehc:server_load_scalar');

if (!function_exists('dfehc_server_load_ttl')) {
    function dfehc_server_load_ttl(): int
    {
        $ttl = (int) apply_filters('dfehc_server_load_ttl', (int) DFEHC_SERVER_LOAD_TTL);
        return max(30, $ttl);
    }
}

if (!function_exists('dfehc_unknown_load')) {
    function dfehc_unknown_load(): float
    {
        static $v;
        if ($v === null) {
            $v = (float) apply_filters('dfehc_unknown_load', 0.404);
        }
        return $v;
    }
}

if (!function_exists('dfehc_debug_log')) {
    function dfehc_debug_log(string $message): void
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }
        if (function_exists('wp_trigger_error')) {
            wp_trigger_error('dfehc', $message);
        } elseif (function_exists('do_action')) {
            do_action('dfehc_debug_log', $message);
        }
    }
}

if (!function_exists('dfehc_host_token')) {
    function dfehc_host_token(): string
    {
        static $t = '';
        if ($t !== '') {
            return $t;
        }
        $host = @php_uname('n') ?: (defined('WP_HOME') ? (string) WP_HOME : (function_exists('home_url') ? (string) home_url() : 'unknown'));
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

if (!function_exists('dfehc_client_ip')) {
    function dfehc_client_ip(): string
    {
        $remote = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $remote = sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        }
        $remote = $remote !== '' ? $remote : '0.0.0.0';

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

                $raw = sanitize_text_field(wp_unslash((string) $_SERVER[$h]));

                if ($h === 'HTTP_X_FORWARDED_FOR') {
                    $parts = array_map('trim', explode(',', $raw));

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
                    $cand = trim($raw);
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

function dfehc_get_cache_client(): array
{
    static $cached = null;
    static $last_probe_ts = 0;

    $retryAfter = (int) apply_filters('dfehc_cache_retry_after', 90);
    $retryAfter = max(0, $retryAfter);

    if (is_array($cached) && isset($cached['type'])) {
        if ($cached['type'] !== 'none') {
            return $cached;
        }
        if ($retryAfter > 0 && (time() - (int) $last_probe_ts) < $retryAfter) {
            return $cached;
        }
    }

    $last_probe_ts = time();

    global $wp_object_cache;
    if (is_object($wp_object_cache) && isset($wp_object_cache->redis) && $wp_object_cache->redis instanceof Redis) {
        return $cached = ['client' => $wp_object_cache->redis, 'type' => 'redis'];
    }
    if (is_object($wp_object_cache) && isset($wp_object_cache->mc) && $wp_object_cache->mc instanceof Memcached) {
        return $cached = ['client' => $wp_object_cache->mc, 'type' => 'memcached'];
    }

    return $cached = ['client' => null, 'type' => 'none'];
}

function dfehc_cache_server_load(float $value): void
{
    $value = max(0.0, (float) $value);

    $ttl = dfehc_server_load_ttl();
    $jitter = 0;
    if (function_exists('random_int')) {
        try {
            $jitter = random_int(0, 8);
        } catch (Throwable $e) {
            $jitter = 0;
        }
    }
    $ttl = max(30, $ttl + $jitter);

    $scalarKey = dfehc_key(DFEHC_SERVER_LOAD_SCALAR_KEY);

    if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_set')) {
        wp_cache_set($scalarKey, $value, DFEHC_CACHE_GROUP, $ttl);
    }

    $key = dfehc_key(DFEHC_SERVER_LOAD_CACHE_KEY);

    if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_set')) {
        wp_cache_set($key, $value, DFEHC_CACHE_GROUP, $ttl);
    }

    $cc = dfehc_get_cache_client();
    $client = isset($cc['client']) ? $cc['client'] : null;
    $type = isset($cc['type']) ? (string) $cc['type'] : 'none';

    if (!$client) {
        return;
    }

    try {
        if ($type === 'redis') {
            $client->setex($key, $ttl, $value);
        } elseif ($type === 'memcached') {
            $client->set($key, $value, $ttl);
        }
    } catch (Throwable $e) {
        dfehc_debug_log('DFEHC cache write error: ' . $e->getMessage());
    }
}

function dfehc_get_server_load(): float
{
    $payloadKey = dfehc_key(DFEHC_SERVER_LOAD_PAYLOAD_KEY);

    $payload = null;
    if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
        $payload = wp_cache_get($payloadKey, DFEHC_CACHE_GROUP);
        if ($payload === false) {
            $payload = null;
        }
    } else {
        $payload = get_transient($payloadKey);
    }

    if (!(is_array($payload) && isset($payload['raw'], $payload['cores'], $payload['source']))) {
        if (!dfehc_load_acquire_lock()) {
            $fallback = dfehc_get_server_load_persistent();
            if ($fallback > 0.0) {
                return (float) $fallback;
            }
            return dfehc_unknown_load();
        }

        try {
            $data = dfehc_detect_load_raw_with_source();
            $payload = [
                'raw'    => (float) $data['load'],
                'cores'  => dfehc_get_cpu_cores(),
                'source' => (string) $data['source'],
            ];

            $ttl = dfehc_server_load_ttl();
            $jitter = 0;
            if (function_exists('random_int')) {
                try {
                    $jitter = random_int(0, 8);
                } catch (Throwable $e) {
                    $jitter = 0;
                }
            }
            $ttl = max(30, $ttl + $jitter);

            if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
                wp_cache_set($payloadKey, $payload, DFEHC_CACHE_GROUP, $ttl);
            } else {
                set_transient($payloadKey, $payload, $ttl);
            }
        } finally {
            dfehc_load_release_lock();
        }
    }

    $raw    = (float) $payload['raw'];
    $cores  = (int) ($payload['cores'] ?: dfehc_get_cpu_cores());
    $source = (string) $payload['source'];

    $divide = (bool) apply_filters('dfehc_divide_cpu_load', true, $raw, $cores, $source);
    $load = ($source === 'cpu_load' && $divide && $cores > 0) ? ($raw / $cores) : $raw;

    $load = max(0.0, (float) $load);

    $max = apply_filters('dfehc_server_load_max', null, $source);
    if (is_numeric($max)) {
        $load = min((float) $max, $load);
    }

    $load = (float) apply_filters('dfehc_contextual_load_value', $load, $source);

    if ($load > 0.0) {
        dfehc_cache_server_load($load);
    }

    return (float) $load;
}

function dfehc_detect_load_raw_with_source(): array
{
    if (function_exists('sys_getloadavg')) {
        $arr = sys_getloadavg();
        if (is_array($arr) && isset($arr[0]) && is_numeric($arr[0]) && (float) $arr[0] >= 0.0) {
            return ['load' => (float) $arr[0], 'source' => 'cpu_load'];
        }
    }

    if (is_readable('/proc/loadavg')) {
        $txt = @file_get_contents('/proc/loadavg');
        if ($txt !== false) {
            $parts = explode(' ', trim($txt));
            if (isset($parts[0]) && is_numeric($parts[0]) && (float) $parts[0] >= 0.0) {
                return ['load' => (float) $parts[0], 'source' => 'cpu_load'];
            }
        }
    }

    $allow_shell = (bool) apply_filters('dfehc_allow_shell_uptime', false);
    if ($allow_shell) {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $can_shell = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true) && !ini_get('open_basedir');
        if ($can_shell) {
            $out = @shell_exec('LANG=C uptime 2>&1');
            if (is_string($out) && $out !== '' && preg_match('/load average[s]?:\s*([0-9.]+)/', $out, $m)) {
                $v = (float) $m[1];
                if ($v >= 0.0) {
                    return ['load' => $v, 'source' => 'cpu_load'];
                }
            }
        }
    }

    $estimator_loaded = false;

    if (defined('DFEHC_PLUGIN_PATH')) {
        $est = rtrim((string) DFEHC_PLUGIN_PATH, "/\\") . '/defibrillator/load-estimator.php';
        if (is_string($est) && $est !== '' && file_exists($est)) {
            require_once $est;
            $estimator_loaded = true;
        }
    }

    if (!$estimator_loaded && class_exists('DynamicHeartbeat\\Dfehc_ServerLoadEstimator')) {
        $estimator_loaded = true;
    }

    if ($estimator_loaded && class_exists('DynamicHeartbeat\\Dfehc_ServerLoadEstimator')) {
        $pct = DynamicHeartbeat\Dfehc_ServerLoadEstimator::get_server_load();
        if (is_numeric($pct)) {
            $cores = dfehc_get_cpu_cores();
            $raw = ((float) $pct / 100.0) * max(1, $cores);
            $raw = max(0.0, $raw);
            return ['load' => $raw, 'source' => 'cpu_load'];
        }
    }

    if (function_exists('do_action')) {
        do_action('dfehc_load_detection_fell_back');
    }

    return ['load' => dfehc_unknown_load(), 'source' => 'fallback'];
}

if (!function_exists('dfehc_get_cpu_cores')) {
    function dfehc_get_cpu_cores(): int
    {
        static $cores = null;
        if ($cores !== null) {
            return (int) $cores;
        }

        $override = getenv('DFEHC_CPU_CORES') ?: (defined('DFEHC_CPU_CORES') ? DFEHC_CPU_CORES : null);
        if ($override !== null && is_numeric($override) && (int) $override > 0) {
            $cores = (int) $override;
            return (int) $cores;
        }

        $cores = 1;

        if (is_readable('/sys/fs/cgroup/cpu.max')) {
            $line = trim((string) @file_get_contents('/sys/fs/cgroup/cpu.max'));
            if ($line !== '') {
                $parts = preg_split('/\s+/', $line);
                if (is_array($parts) && count($parts) >= 2) {
                    $quota  = is_numeric($parts[0]) ? (float) $parts[0] : 0.0;
                    $period = is_numeric($parts[1]) ? (float) $parts[1] : 0.0;
                    if ($period > 0.0 && $quota > 0.0) {
                        $cores = max(1, (int) ceil($quota / $period));
                    }
                }
            }
        }

        if ($cores === 1 && is_readable('/proc/self/cgroup')) {
            $content = (string) @file_get_contents('/proc/self/cgroup');
            if ($content !== '') {
                if (preg_match('/0::\/(.+)$/m', $content, $m) || preg_match('/cpu[^:]*:(.+)$/m', $content, $m)) {
                    $rel_path = '/' . ltrim(trim($m[1]), '/');
                    $base = '/sys/fs/cgroup' . $rel_path;
                    $quotaFile = $base . '/cpu.max';
                    if (is_readable($quotaFile)) {
                        $line = trim((string) @file_get_contents($quotaFile));
                        if ($line !== '') {
                            $parts = preg_split('/\s+/', $line);
                            if (is_array($parts) && count($parts) >= 2) {
                                $quota  = is_numeric($parts[0]) ? (float) $parts[0] : 0.0;
                                $period = is_numeric($parts[1]) ? (float) $parts[1] : 0.0;
                                if ($period > 0.0 && $quota > 0.0) {
                                    $cores = max(1, (int) ceil($quota / $period));
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($cores === 1 && is_readable('/proc/cpuinfo')) {
            $info = (string) @file_get_contents('/proc/cpuinfo');
            if ($info !== '') {
                $cnt = preg_match_all('/^processor\s*:/m', $info);
                if ($cnt > 0) {
                    $cores = $cnt;
                }
            }
        }

        $cores = (int) apply_filters('dfehc_cpu_cores', max(1, $cores));
        return (int) $cores;
    }
}

function dfehc_log_server_load(): void
{
    if (!apply_filters('dfehc_enable_load_logging', false)) {
        return;
    }

    $load = dfehc_get_server_load();
    $optKey = 'dfehc_server_load_logs_' . dfehc_blog_id() . '_' . dfehc_host_token();

    $max_entries = (int) apply_filters('dfehc_load_log_max_entries', 720);
    $max_entries = max(50, min(5000, $max_entries));

    $retention = (int) apply_filters('dfehc_load_log_retention_seconds', DAY_IN_SECONDS);
    $retention = max(300, $retention);

    $max_bytes = (int) apply_filters('dfehc_load_log_option_max_bytes', 1048576);
    $max_bytes = max(65536, $max_bytes);

    global $wpdb;
    if ($wpdb instanceof wpdb && !empty($wpdb->options)) {
        $len = $wpdb->get_var($wpdb->prepare(
            "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $optKey
        ));
        if (is_numeric($len) && (int) $len > $max_bytes) {
            update_option($optKey, [['timestamp' => time(), 'load' => (float) $load]], false);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET autoload='no' WHERE option_name=%s AND autoload<>'no' LIMIT 1",
                $optKey
            ));
            return;
        }
    }

    $logs = get_option($optKey, []);
    if (!is_array($logs)) {
        $logs = [];
    }

    $now = time();
    $cutoff = $now - $retention;

    $logs = array_filter($logs, static function ($row) use ($cutoff): bool {
        return is_array($row) && isset($row['timestamp']) && is_numeric($row['timestamp']) && (int) $row['timestamp'] >= $cutoff;
    });

    if (count($logs) > $max_entries) {
        $logs = array_slice($logs, -$max_entries);
    }

    $logs[] = ['timestamp' => $now, 'load' => (float) $load];

    if (count($logs) > $max_entries) {
        $logs = array_slice($logs, -$max_entries);
    }

    update_option($optKey, array_values($logs), false);

    if ($wpdb instanceof wpdb && !empty($wpdb->options)) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET autoload='no' WHERE option_name=%s AND autoload<>'no' LIMIT 1",
            $optKey
        ));
    }
}
add_action('dfehc_log_server_load_hook', 'dfehc_log_server_load');

if (!function_exists('dfehc_get_server_load_ajax_handler')) {
    function dfehc_get_server_load_ajax_handler(): void
    {
        $allow_public = apply_filters('dfehc_allow_public_server_load', false);

        if (!$allow_public) {
            $action = 'get_server_load';
            $nonce_action = 'dfehc-' . $action;

            $valid = false;
            if (function_exists('check_ajax_referer')) {
                $valid = (bool) check_ajax_referer($nonce_action, 'nonce', false);
            } else {
                $raw_nonce = '';
                if (isset($_POST['nonce'])) {
                    $raw_nonce = (string) wp_unslash($_POST['nonce']);
                } elseif (isset($_GET['nonce'])) {
                    $raw_nonce = (string) wp_unslash($_GET['nonce']);
                }
                $nonce = sanitize_text_field($raw_nonce);
                $valid = (bool) wp_verify_nonce($nonce, $nonce_action);
            }

            if (!$valid) {
                wp_send_json_error(['message' => 'Invalid nonce.'], 403);
            }

            $cap = apply_filters('dfehc_required_capability', 'read');
            if (!current_user_can($cap)) {
                wp_send_json_error(['message' => 'Not authorised.'], 403);
            }
        } else {
            $ip = dfehc_client_ip();
            $rk = dfehc_key('dfehc_rl_' . md5($ip));

            $limit = (int) apply_filters('dfehc_public_rate_limit', 30);
            $win   = (int) apply_filters('dfehc_public_rate_window', 60);
            $limit = max(1, $limit);
            $win   = max(1, $win);

            $cnt = (int) get_transient($rk);
            if ($cnt >= $limit) {
                wp_send_json_error(['message' => 'rate_limited'], 429);
            }
            set_transient($rk, $cnt + 1, $win);
        }

        nocache_headers();
        wp_send_json_success(dfehc_get_server_load_persistent());
    }
}
add_action('wp_ajax_get_server_load', 'dfehc_get_server_load_ajax_handler');

add_action('init', function (): void {
    if (apply_filters('dfehc_allow_public_server_load', false)) {
        add_action('wp_ajax_nopriv_get_server_load', 'dfehc_get_server_load_ajax_handler');
    }
}, 0);

function dfehc_get_server_load_persistent(): float
{
    static $cached = null;
    if ($cached !== null) {
        return (float) $cached;
    }

    $scalarKey = dfehc_key(DFEHC_SERVER_LOAD_SCALAR_KEY);

    if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
        $val = wp_cache_get($scalarKey, DFEHC_CACHE_GROUP);
        if ($val !== false && $val !== '' && is_numeric($val)) {
            $cached = max(0.0, (float) $val);
            return (float) $cached;
        }
    }

    $cc = dfehc_get_cache_client();
    $client = isset($cc['client']) ? $cc['client'] : null;

    $val = false;
    if ($client) {
        try {
            $val = $client->get(dfehc_key(DFEHC_SERVER_LOAD_CACHE_KEY));
        } catch (Throwable $e) {
            dfehc_debug_log('DFEHC cache read error: ' . $e->getMessage());
        }
    }

    if ($val !== false && $val !== '' && is_numeric($val)) {
        $cached = max(0.0, (float) $val);
        return (float) $cached;
    }

    $fresh = dfehc_get_server_load();
    $fresh = max(0.0, (float) $fresh);

    dfehc_cache_server_load($fresh);

    $cached = $fresh;
    return (float) $cached;
}

function dfehc_register_minute_schedule(array $schedules): array
{
    if (!isset($schedules['dfehc_minute'])) {
        $schedules['dfehc_minute'] = [
            'interval' => 60,
            'display'  => __('Server load (DFEHC)', 'dfehc'),
        ];
    }
    return $schedules;
}
add_filter('cron_schedules', 'dfehc_register_minute_schedule', 0);

function dfehc_clear_log_server_load_cron(): void
{
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
        return;
    }
    while ($ts = wp_next_scheduled('dfehc_log_server_load_hook')) {
        wp_unschedule_event($ts, 'dfehc_log_server_load_hook');
    }
}

function dfehc_schedule_log_server_load(): void
{
    if (!apply_filters('dfehc_enable_load_logging', false)) {
        dfehc_clear_log_server_load_cron();
        return;
    }
    if (!function_exists('wp_get_schedules') || !function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }

    $schedules = wp_get_schedules();
    if (!isset($schedules['dfehc_minute'])) {
        dfehc_clear_log_server_load_cron();
        return;
    }

    if (!wp_next_scheduled('dfehc_log_server_load_hook')) {
        $interval = (int) $schedules['dfehc_minute']['interval'];
        $now      = time();
        $start    = $now - ($now % $interval) + $interval;

        $r = wp_schedule_event($start, 'dfehc_minute', 'dfehc_log_server_load_hook');
        if (is_wp_error($r)) {
            dfehc_debug_log('DFEHC scheduling error: ' . $r->get_error_message());
        }
    }
}

function dfehc_deactivate_log_server_load(): void
{
    dfehc_clear_log_server_load_cron();
}

if (function_exists('register_activation_hook')) {
    $plugin_file = defined('DFEHC_PLUGIN_FILE') ? DFEHC_PLUGIN_FILE : __FILE__;
    register_activation_hook((string) $plugin_file, 'dfehc_schedule_log_server_load');
}
if (function_exists('register_deactivation_hook')) {
    $plugin_file = defined('DFEHC_PLUGIN_FILE') ? DFEHC_PLUGIN_FILE : __FILE__;
    register_deactivation_hook((string) $plugin_file, 'dfehc_deactivate_log_server_load');
}

add_action('init', 'dfehc_schedule_log_server_load', 1);

function dfehc_load_acquire_lock(): bool
{
    $key = dfehc_key('dfehc_load_lock');

    if (class_exists('WP_Lock')) {
        $lock = new WP_Lock($key, 30);
        if ($lock->acquire()) {
            $GLOBALS['dfehc_load_lock'] = $lock;
            return true;
        }
        return false;
    }

    if (function_exists('wp_cache_add') && wp_cache_add($key, 1, DFEHC_CACHE_GROUP, 30)) {
        $GLOBALS['dfehc_load_lock_cache_key'] = $key;
        return true;
    }

    if (false !== get_transient($key)) {
        return false;
    }

    if (set_transient($key, 1, 30)) {
        $GLOBALS['dfehc_load_lock_transient_key'] = $key;
        return true;
    }

    return false;
}

function dfehc_load_release_lock(): void
{
    if (isset($GLOBALS['dfehc_load_lock']) && $GLOBALS['dfehc_load_lock'] instanceof WP_Lock) {
        $GLOBALS['dfehc_load_lock']->release();
        unset($GLOBALS['dfehc_load_lock']);
        return;
    }

    if (isset($GLOBALS['dfehc_load_lock_cache_key'])) {
        wp_cache_delete((string) $GLOBALS['dfehc_load_lock_cache_key'], DFEHC_CACHE_GROUP);
        unset($GLOBALS['dfehc_load_lock_cache_key']);
        return;
    }

    if (isset($GLOBALS['dfehc_load_lock_transient_key'])) {
        delete_transient((string) $GLOBALS['dfehc_load_lock_transient_key']);
        unset($GLOBALS['dfehc_load_lock_transient_key']);
    }
}
