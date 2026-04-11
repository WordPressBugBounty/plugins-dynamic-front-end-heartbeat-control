<?php
declare(strict_types=1);

if (!defined('DFEHC_LOAD_AVERAGES')) define('DFEHC_LOAD_AVERAGES', 'dfehc_load_averages');
if (!defined('DFEHC_SERVER_LOAD')) define('DFEHC_SERVER_LOAD', 'dfehc_server_load');
if (!defined('DFEHC_RECOMMENDED_INTERVAL')) define('DFEHC_RECOMMENDED_INTERVAL', 'dfehc_recommended_interval');
if (!defined('DFEHC_CAPABILITY')) define('DFEHC_CAPABILITY', 'read');
if (!defined('DFEHC_LOAD_LOCK_BASE')) define('DFEHC_LOAD_LOCK_BASE', 'dfehc_compute_load_lock');
if (!defined('DFEHC_CACHE_GROUP')) define('DFEHC_CACHE_GROUP', 'dfehc');

if (!function_exists('dfehc_max_server_load')) {
    function dfehc_max_server_load(): int
    {
        static $v;
        if ($v === null) {
            $v = (int) apply_filters('dfehc_max_server_load', 82);
            $v = max(1, min(100, $v));
        }
        return $v;
    }
}

if (!function_exists('dfehc_min_interval')) {
    function dfehc_min_interval(): int
    {
        static $v;
        if ($v === null) {
            $v = (int) apply_filters('dfehc_min_interval', 20);
            $v = max(5, $v);
        }
        return $v;
    }
}

if (!function_exists('dfehc_max_interval')) {
    function dfehc_max_interval(): int
    {
        static $v;
        if ($v === null) {
            $v = (int) apply_filters('dfehc_max_interval', 300);
            $v = max(dfehc_min_interval(), $v);
        }
        return $v;
    }
}

if (!function_exists('dfehc_fallback_interval')) {
    function dfehc_fallback_interval(): int
    {
        static $v;
        if ($v === null) {
            $v = (int) apply_filters('dfehc_fallback_interval', 90);
            $v = max(dfehc_min_interval(), min(dfehc_max_interval(), $v));
        }
        return $v;
    }
}

if (!function_exists('dfehc_host_token')) {
    function dfehc_host_token(): string
    {
        static $t = '';
        if ($t !== '') {
            return $t;
        }

        $url = '';
        if (defined('WP_HOME') && is_string(WP_HOME) && WP_HOME !== '') {
            $url = WP_HOME;
        } elseif (function_exists('home_url')) {
            $url = (string) home_url();
        }

        $host = '';
        if ($url !== '' && function_exists('wp_parse_url')) {
            $parts = wp_parse_url($url);
            if (is_array($parts) && isset($parts['host']) && is_string($parts['host'])) {
                $host = $parts['host'];
            }
        }

        if ($host === '') {
            $host = @php_uname('n') ?: 'unknown';
        }

        $salt = defined('DB_NAME') ? (string) DB_NAME : '';
        return $t = substr(md5((string) $host . $salt), 0, 10);
    }
}

if (!function_exists('dfehc_scoped_key')) {
    function dfehc_scoped_key(string $base): string
    {
        $blog = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '0';
        return "{$base}_{$blog}_" . dfehc_host_token();
    }
}

if (!function_exists('dfehc_rand_jitter')) {
    function dfehc_rand_jitter(int $min, int $max): int
    {
        $min = (int) $min;
        $max = (int) $max;
        if ($max < $min) {
            $t = $min;
            $min = $max;
            $max = $t;
        }
        if ($min === $max) {
            return $min;
        }
        if (function_exists('random_int')) {
            try {
                return (int) random_int($min, $max);
            } catch (Throwable $e) {
                return (int) wp_rand($min, $max);
            }
        }
        return (int) wp_rand($min, $max);
    }
}

if (!function_exists('dfehc_store_lockfree')) {
    function dfehc_store_lockfree(string $key, $value, int $ttl): bool
    {
        $ttl = max(10, $ttl);
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_add')) {
            if (wp_cache_add($key, $value, DFEHC_CACHE_GROUP, $ttl)) {
                return true;
            }
        }
        return (bool) set_transient($key, $value, $ttl);
    }
}

if (!function_exists('dfehc_cache_get')) {
    function dfehc_cache_get(string $key)
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $v = wp_cache_get($key, DFEHC_CACHE_GROUP);
            return $v === false ? null : $v;
        }
        $v = get_transient($key);
        return $v === false ? null : $v;
    }
}

if (!function_exists('dfehc_register_ajax')) {
    function dfehc_register_ajax(string $action, callable $callback): void
    {
        add_action("wp_ajax_$action", $callback);

        if ($action === 'get_server_load') {
            if ((bool) apply_filters('dfehc_allow_public_server_load', false)) {
                add_action("wp_ajax_nopriv_$action", $callback);
            }
            return;
        }

        if ($action === 'dfehc_async_heartbeat') {
            if ((bool) apply_filters('dfehc_allow_public_async', false)) {
                add_action("wp_ajax_nopriv_$action", $callback);
            }
            return;
        }

        if ((bool) apply_filters("dfehc_{$action}_allow_public", false)) {
            add_action("wp_ajax_nopriv_$action", $callback);
        }
    }
}

if (!function_exists('dfehc_get_cpu_cores')) {
    function dfehc_get_cpu_cores(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return (int) $cached;
        }

        $override = getenv('DFEHC_CPU_CORES');
        if ($override !== false && is_numeric($override) && (int) $override > 0) {
            $cached = (int) $override;
            return (int) $cached;
        }
        if (defined('DFEHC_CPU_CORES') && is_numeric(DFEHC_CPU_CORES) && (int) DFEHC_CPU_CORES > 0) {
            $cached = (int) DFEHC_CPU_CORES;
            return (int) $cached;
        }

        $detected = 1;

        if (is_readable('/sys/fs/cgroup/cpu.max')) {
            $line = trim((string) @file_get_contents('/sys/fs/cgroup/cpu.max'));
            if ($line !== '') {
                $parts = preg_split('/\s+/', $line);
                if (is_array($parts) && count($parts) >= 2) {
                    $quota  = is_numeric($parts[0]) ? (float) $parts[0] : 0.0;
                    $period = is_numeric($parts[1]) ? (float) $parts[1] : 0.0;
                    if ($period > 0.0 && $quota > 0.0) {
                        $detected = max(1, (int) ceil($quota / $period));
                    }
                }
            }
        }

        if ($detected === 1 && is_readable('/proc/cpuinfo')) {
            $content = (string) @file_get_contents('/proc/cpuinfo');
            if ($content !== '') {
                $cnt = preg_match_all('/^processor\s*:/m', $content);
                if ($cnt > 0) {
                    $detected = $cnt;
                }
            }
        }

        $detected = (int) apply_filters('dfehc_cpu_cores', max(1, $detected));
        $cached = $detected;
        return (int) $cached;
    }
}

if (!function_exists('dfehc_should_attempt_load_calculation')) {
    function dfehc_should_attempt_load_calculation(): bool
    {
        $is_cron = function_exists('wp_doing_cron') && wp_doing_cron();
        $is_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
        $is_json = function_exists('wp_is_json_request') && wp_is_json_request();
        $is_cli  = defined('WP_CLI') && WP_CLI;

        if ($is_cron) {
            return (bool) apply_filters('dfehc_allow_load_calc_in_cron', true);
        }

        if ($is_ajax || $is_json || $is_cli) {
            return (bool) apply_filters('dfehc_allow_load_calc_in_non_html', false);
        }

        if (is_admin()) {
            return (bool) apply_filters('dfehc_allow_load_calc_in_admin', false);
        }

        $rate = (float) apply_filters('dfehc_load_calc_sample_rate', 0.15);
        if (!is_finite($rate) || $rate < 0.0) $rate = 0.0;
        if ($rate > 1.0) $rate = 1.0;

        if ($rate < 1.0) {
            $r = mt_rand(0, 1000000) / 1000000;
            if ($r > $rate) {
                return false;
            }
        }

        $cd = (int) apply_filters('dfehc_load_calc_cooldown', 30);
        $cd = max(0, $cd);
        if ($cd > 0) {
            $cdKey = dfehc_scoped_key('dfehc_load_calc_cd');
            if (dfehc_cache_get($cdKey) !== null) {
                return false;
            }
            dfehc_set_transient_noautoload($cdKey, 1, $cd);
        }

        return true;
    }
}

if (!function_exists('dfehc_get_or_calculate_server_load')) {
    function dfehc_get_or_calculate_server_load()
    {
        static $requestMemo = null;
        if ($requestMemo !== null) {
            return $requestMemo;
        }

        $key = dfehc_scoped_key(DFEHC_SERVER_LOAD);
        $cached = dfehc_cache_get($key);

        $ttl = (int) apply_filters('dfehc_server_load_ttl', 120);
        $ttl = max(30, $ttl);

        $staleTtl = (int) apply_filters('dfehc_server_load_stale_ttl', max(300, $ttl * 10));
        $staleTtl = max($ttl, $staleTtl);

        $now = time();
        $cachedPayloadKey = dfehc_scoped_key('dfehc_server_load_payload');
        $payload = dfehc_cache_get($cachedPayloadKey);

        if (is_array($payload) && isset($payload['v'], $payload['t']) && is_numeric($payload['v']) && is_numeric($payload['t'])) {
            $age = $now - (int) $payload['t'];
            if ($age < 0) $age = 0;
            if ($age <= $ttl) {
                $requestMemo = (float) $payload['v'];
                return $requestMemo;
            }
            if ($age <= $staleTtl && !$cached) {
                $cached = (float) $payload['v'];
            }
        }

        if ($cached !== null && is_numeric($cached)) {
            $requestMemo = (float) $cached;
            if (!dfehc_should_attempt_load_calculation()) {
                return $requestMemo;
            }
        } else {
            if (!dfehc_should_attempt_load_calculation()) {
                $requestMemo = false;
                return $requestMemo;
            }
        }

        $lock = dfehc_acquire_lock(DFEHC_LOAD_LOCK_BASE, $ttl + 10);
        if (!$lock) {
            if ($cached !== null && is_numeric($cached)) {
                $requestMemo = (float) $cached;
                return $requestMemo;
            }
            if (is_array($payload) && isset($payload['v'], $payload['t']) && is_numeric($payload['v']) && is_numeric($payload['t'])) {
                $age = $now - (int) $payload['t'];
                if ($age < 0) $age = 0;
                if ($age <= $staleTtl) {
                    $requestMemo = (float) $payload['v'];
                    return $requestMemo;
                }
            }
            $requestMemo = false;
            return $requestMemo;
        }

        try {
            $raw = dfehc_calculate_server_load();
            if ($raw === false) {
                $requestMemo = false;
                return $requestMemo;
            }

            $cores = max(1, dfehc_get_cpu_cores());
            $load_pct = min(100.0, round(((float) $raw / $cores) * 100.0, 2));

            $payloadStore = ['v' => (float) $load_pct, 't' => (int) $now];
            dfehc_set_transient_noautoload($cachedPayloadKey, $payloadStore, $ttl + dfehc_rand_jitter(0, 10));
            dfehc_set_transient_noautoload($key, (float) $load_pct, $ttl);

            $requestMemo = (float) $load_pct;
            return $requestMemo;
        } finally {
            dfehc_release_lock($lock);
        }
    }
}

if (!function_exists('dfehc_calculate_server_load')) {
    function dfehc_calculate_server_load()
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load) && isset($load[0]) && is_numeric($load[0]) && (float) $load[0] >= 0.0) {
                return (float) $load[0];
            }
        }

        if (is_readable('/proc/loadavg')) {
            $content = (string) @file_get_contents('/proc/loadavg');
            $parts = explode(' ', trim($content));
            if (isset($parts[0]) && is_numeric($parts[0]) && (float) $parts[0] >= 0.0) {
                return (float) $parts[0];
            }
        }

        if (class_exists(\DynamicHeartbeat\Dfehc_ServerLoadEstimator::class)) {
            $pct = \DynamicHeartbeat\Dfehc_ServerLoadEstimator::get_server_load();
            if ($pct !== false && is_numeric($pct) && (float) $pct >= 0.0) {
                $cores = max(1, dfehc_get_cpu_cores());
                return (float) ($cores * (((float) $pct) / 100.0));
            }
        }

        return false;
    }
}

class Dfehc_Heartbeat_Async
{
    protected $action = 'dfehc_async_heartbeat';
    protected $scheduled = false;

    public function __construct()
    {
        add_action('init', [$this, 'maybe_schedule']);
        add_action($this->action, [$this, 'run_action']);
        dfehc_register_ajax($this->action, [$this, 'handle_async_request']);
    }

    public function maybe_schedule(): void
    {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;

        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        $recurrence = (string) apply_filters('dfehc_async_cron_recurrence', 'dfehc_5_minutes');
        $schedules = function_exists('wp_get_schedules') ? (array) wp_get_schedules() : [];

        if (!isset($schedules[$recurrence])) {
            $recurrence = 'dfehc_5_minutes';
        }
        if (!isset($schedules[$recurrence])) {
            $recurrence = 'hourly';
        }

        $interval = isset($schedules[$recurrence]['interval']) ? (int) $schedules[$recurrence]['interval'] : 3600;
        $interval = max(60, $interval);

        $now = time();
        $aligned = $now - ($now % $interval) + $interval;

        if (!wp_next_scheduled($this->action)) {
            wp_schedule_event($aligned, $recurrence, $this->action);
        }
    }

    public function handle_async_request(): void
    {
        $allow_public = (bool) apply_filters('dfehc_allow_public_async', false);
        $nonce_action = 'dfehc-' . $this->action;
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';

        if (!$allow_public) {
            $valid = function_exists('check_ajax_referer')
                ? (bool) check_ajax_referer($nonce_action, 'nonce', false)
                : (bool) wp_verify_nonce($nonce, $nonce_action);

            if (!$valid) {
                wp_send_json_error(['message' => 'invalid nonce'], 403);
            }

            $cap = apply_filters('dfehc_required_capability', DFEHC_CAPABILITY);
            if (!current_user_can((string) $cap)) {
                wp_send_json_error(['message' => 'not authorized'], 403);
            }
        } else {
            $limit = (int) apply_filters('dfehc_public_rate_limit', 20);
            $window = (int) apply_filters('dfehc_public_rate_window', 60);
            $limit = max(1, $limit);
            $window = max(1, $window);

            $ip = function_exists('dfehc_client_ip') ? (string) dfehc_client_ip() : '0.0.0.0';
            $rl_key = dfehc_scoped_key('dfehc_rl_' . md5($ip));
            $cnt = (int) get_transient($rl_key);

            if ($cnt >= $limit) {
                wp_send_json_error(['message' => 'rate limited'], 429);
            }
            dfehc_set_transient_noautoload($rl_key, $cnt + 1, $window);
        }

        $this->run_action();
        wp_send_json_success(true);
    }

    public function run_action(): void
    {
        $lock_key = dfehc_scoped_key('dfehc_async_run');
        $lock_ttl = (int) apply_filters('dfehc_async_lock_ttl', 45);
        $lock_ttl = max(10, min(120, $lock_ttl));

        $lock = dfehc_acquire_lock($lock_key, $lock_ttl);
        if (!$lock) {
            return;
        }

        try {
            $activity_key = dfehc_scoped_key('dfehc_last_user_activity');
            $last_activity = dfehc_cache_get($activity_key);
            if ($last_activity === null || !is_numeric($last_activity)) {
                dfehc_set_transient_noautoload(dfehc_scoped_key(DFEHC_RECOMMENDED_INTERVAL), dfehc_fallback_interval(), 600);
                return;
            }

            $now = time();
            $elapsed = max(0, $now - (int) $last_activity);

            $load_pct = dfehc_get_or_calculate_server_load();
            if ($load_pct === false || !is_numeric($load_pct)) {
                dfehc_set_transient_noautoload(dfehc_scoped_key(DFEHC_RECOMMENDED_INTERVAL), dfehc_fallback_interval(), 600);
                return;
            }
            $load_pct = max(0.0, min(100.0, (float) $load_pct));

            $ttl = (int) apply_filters('dfehc_server_load_ttl', 120);
            $ttl = max(30, $ttl);
            dfehc_set_transient_noautoload(dfehc_scoped_key(DFEHC_SERVER_LOAD), $load_pct, $ttl);

            $samples_key = dfehc_scoped_key(DFEHC_LOAD_AVERAGES);
            $samples = dfehc_cache_get($samples_key);
            if (!is_array($samples)) {
                $samples = [];
            }

            $samples[] = $load_pct;

            $max_samples = (int) apply_filters('dfehc_load_avg_samples', 6);
            $max_samples = max(2, min(30, $max_samples));

            if (count($samples) > $max_samples) {
                $samples = array_slice($samples, -$max_samples);
            }

            dfehc_set_transient_noautoload($samples_key, array_values($samples), 3600);

            $weights = (array) apply_filters('dfehc_load_weights', [4, 3, 2, 1]);
            $weights = array_values(array_filter($weights, static function ($v): bool {
                return is_numeric($v) && (float) $v > 0.0;
            }));
            if (!$weights) {
                $weights = [1];
            }

            $n = count($samples);
            $wCount = count($weights);

            $weighted_sum = 0.0;
            $weight_sum = 0.0;

            foreach ($samples as $i => $v) {
                $age = $n - 1 - (int) $i;
                $w = (float) ($weights[min($age, $wCount - 1)] ?? 1.0);
                $w = max(0.0001, $w);
                $weighted_sum += ((float) $v) * $w;
                $weight_sum += $w;
            }

            $avg_load = $weight_sum > 0 ? round($weighted_sum / $weight_sum, 2) : 0.0;
            $avg_load = max(0.0, min(100.0, (float) $avg_load));

            $interval = $this->calculate_interval($elapsed, $avg_load);

            $rec_ttl = (int) apply_filters('dfehc_recommended_interval_ttl', 600);
            $rec_ttl = max(30, $rec_ttl + dfehc_rand_jitter(0, 10));

            dfehc_set_transient_noautoload(dfehc_scoped_key(DFEHC_RECOMMENDED_INTERVAL), $interval, $rec_ttl);
        } finally {
            dfehc_release_lock($lock);
        }
    }

    protected function calculate_interval(int $elapsed, float $load_pct): int
    {
        $min_i = dfehc_min_interval();
        $max_i = dfehc_max_interval();
        $max_load = (float) dfehc_max_server_load();

        if ($max_i <= $min_i) {
            return $min_i;
        }

        $load_pct = max(0.0, min(100.0, $load_pct));
        $elapsed = max(0, $elapsed);

        $hard_load = (float) apply_filters('dfehc_hard_max_load_factor', 1.10);
        $hard_load = max(1.0, min(2.0, $hard_load));

        if ($elapsed <= $min_i && $load_pct < $max_load) {
            return $min_i;
        }
        if ($elapsed >= $max_i || $load_pct >= ($max_load * $hard_load)) {
            return $max_i;
        }

        $load_factor = $max_load > 0 ? min(1.0, $load_pct / $max_load) : 0.0;

        $activity_factor = ($elapsed - $min_i) / ($max_i - $min_i);
        $activity_factor = max(0.0, min(1.0, $activity_factor));

        $act_w = (float) apply_filters('dfehc_activity_weight', 0.7);
        $load_w = (float) apply_filters('dfehc_load_weight', 0.3);

        $act_w = max(0.0, min(1.0, $act_w));
        $load_w = max(0.0, min(1.0, $load_w));

        $sum = $act_w + $load_w;
        if ($sum <= 0.0) {
            $act_w = 0.7;
            $load_w = 0.3;
            $sum = 1.0;
        }
        $act_w /= $sum;
        $load_w /= $sum;

        $combined = max($load_factor, ($activity_factor * $act_w) + ($load_factor * $load_w));
        $combined = max(0.0, min(1.0, (float) $combined));

        $interval = (int) round($min_i + $combined * ($max_i - $min_i));
        $interval = max($min_i, min($max_i, $interval));

        $step = (int) apply_filters('dfehc_interval_step', 5);
        $step = max(0, $step);
        if ($step > 1) {
            $interval = (int) (round($interval / $step) * $step);
            $interval = max($min_i, min($max_i, $interval));
        }

        return $interval;
    }
}

function dfehc_weighted_average(array $values, array $weights): float
{
    if ($values === []) {
        return 0.0;
    }
    $tv = 0.0;
    $tw = 0.0;
    foreach ($values as $i => $v) {
        $w = isset($weights[$i]) && is_numeric($weights[$i]) && (float) $weights[$i] > 0.0 ? (float) $weights[$i] : 1.0;
        $tv += (float) $v * $w;
        $tw += $w;
    }
    return $tw > 0 ? round($tv / $tw, 2) : 0.0;
}

if (!function_exists('dfehc_calculate_recommended_interval_user_activity')) {
    function dfehc_calculate_recommended_interval_user_activity(float $current_load): float
    {
        $key = dfehc_scoped_key(DFEHC_RECOMMENDED_INTERVAL);
        $interval = dfehc_cache_get($key);
        if ($interval !== null && is_numeric($interval)) {
            return (float) $interval;
        }
        $current_load = max(0.0, min(100.0, $current_load));
        return $current_load >= (float) dfehc_max_server_load()
            ? (float) dfehc_max_interval()
            : (float) dfehc_min_interval();
    }
}

function dfehc_register_schedules(array $s): array
{
    if (!isset($s['dfehc_5_minutes'])) {
        $s['dfehc_5_minutes'] = ['interval' => 300, 'display' => __('Every 5 Minutes (DFEHC)', 'dfehc')];
    }
    return $s;
}
add_filter('cron_schedules', 'dfehc_register_schedules', 10);

new Dfehc_Heartbeat_Async();