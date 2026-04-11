<?php
namespace DynamicHeartbeat;

defined('ABSPATH') || exit;

if (!defined('DFEHC_CACHE_GROUP')) {
    define('DFEHC_CACHE_GROUP', 'dfehc');
}

class Dfehc_ServerLoadEstimator
{
    const BASELINE_TRANSIENT_PREFIX = 'dfehc_baseline_';
    const LOAD_CACHE_TRANSIENT      = 'dfehc_last_known_load';
    const LOAD_SPIKE_TRANSIENT      = 'dfehc_load_spike_score';
    const BASELINE_RESET_CD_PREFIX  = 'dfehc_baseline_reset_cd_';

    private static $requestMemo = null;
    private static $scopeSuffixMemo = null;
    private static $hostnameKeyMemo = null;
    private static $blogIdMemo = null;
    private static $sapiTagMemo = null;

    public static function get_server_load(float $duration = 0.025)
    {
        if (self::$requestMemo !== null) {
            return self::$requestMemo;
        }

        if (\apply_filters('dfehc_disable_loop_estimator', false)) {
            return self::$requestMemo = false;
        }

        if (!\function_exists('microtime') || (\defined('DFEHC_DISABLE_LOAD_ESTIMATION') && DFEHC_DISABLE_LOAD_ESTIMATION)) {
            return self::$requestMemo = false;
        }

        if (!\apply_filters('dfehc_allow_estimator_on_request', true)) {
            return self::$requestMemo = false;
        }

        $isCron = (\function_exists('wp_doing_cron') && \wp_doing_cron());
        $isAjax = (\function_exists('wp_doing_ajax') && \wp_doing_ajax());
        $isJson = (\function_exists('wp_is_json_request') && \wp_is_json_request());
        $isCli  = (\defined('WP_CLI') && WP_CLI);

        if (!$isCron && ($isAjax || $isJson || $isCli)) {
            return self::$requestMemo = false;
        }

        $duration = (float) \apply_filters('dfehc_loop_duration', $duration);
        $duration = self::normalize_duration($duration, 0.025);

        $suffix    = self::scope_suffix();
        $baselineT = self::get_baseline_transient_name($suffix);

        $cacheTtl = (int) \apply_filters('dfehc_load_cache_ttl', 90);
        if ($cacheTtl < 1) {
            $cacheTtl = 1;
        }

        $staleTtl = (int) \apply_filters('dfehc_load_cache_stale_ttl', 1800);
        if ($staleTtl < $cacheTtl) {
            $staleTtl = $cacheTtl;
        }

        $cacheKey = self::get_cache_key($suffix);
        $cached = self::get_cached_load_value($cacheKey, $cacheTtl, $staleTtl);

        if ($cached['fresh'] && $cached['value'] !== null) {
            return self::$requestMemo = (float) $cached['value'];
        }

        $allowSys = (bool) \apply_filters('dfehc_allow_sys_getloadavg', true);
        if ($allowSys) {
            $sysAvg = self::try_sys_getloadavg();
            if ($sysAvg !== null) {
                self::set_cached_load_value($cacheKey, $sysAvg, $staleTtl);
                return self::$requestMemo = (float) $sysAvg;
            }
        }

        $loopAllowed = $isCron || self::is_idle_context();
        $loopAllowed = (bool) \apply_filters('dfehc_allow_loop_estimation', $loopAllowed, $suffix);

        if (!$loopAllowed) {
            if ($cached['value'] !== null) {
                return self::$requestMemo = (float) $cached['value'];
            }
            return self::$requestMemo = false;
        }

        $sampleRate = (float) \apply_filters('dfehc_loop_estimation_sample_rate', $isCron ? 1.0 : 0.05, $suffix);
        if (!\is_finite($sampleRate) || $sampleRate < 0.0) {
            $sampleRate = 0.0;
        } elseif ($sampleRate > 1.0) {
            $sampleRate = 1.0;
        }

        if (!$isCron && $sampleRate < 1.0) {
            $r = self::rand_unit();
            if ($r > $sampleRate) {
                if ($cached['value'] !== null) {
                    return self::$requestMemo = (float) $cached['value'];
                }
                return self::$requestMemo = false;
            }
        }

        $cooldownTtl = (int) \apply_filters('dfehc_loop_estimation_cooldown_ttl', $isCron ? 0 : 30, $suffix);
        if ($cooldownTtl < 0) {
            $cooldownTtl = 0;
        }

        $cooldownKey = 'dfehc_loop_est_cd_' . $suffix;
        if ($cooldownTtl > 0 && self::get_scoped_transient($cooldownKey) !== false) {
            if ($cached['value'] !== null) {
                return self::$requestMemo = (float) $cached['value'];
            }
            return self::$requestMemo = false;
        }

        $lockKey = 'dfehc_estimating_' . $suffix;
        $estLockTtl = (int) \apply_filters('dfehc_estimation_lock_ttl', 15);
        if ($estLockTtl < 1) {
            $estLockTtl = 1;
        }

        $lock = self::acquire_lock($lockKey, $estLockTtl);
        if (!$lock) {
            if ($cached['value'] !== null) {
                return self::$requestMemo = (float) $cached['value'];
            }
            return self::$requestMemo = false;
        }

        if ($cooldownTtl > 0) {
            self::set_scoped_transient_noautoload($cooldownKey, 1, $cooldownTtl);
        }

        try {
            $baseline = self::get_baseline_value($baselineT);
            if ($baseline === false || $baseline === null || !\is_numeric($baseline) || (float) $baseline <= 0.0) {
                $baseline = self::maybe_calibrate($baselineT, $duration);
            }
            $baseline = (float) $baseline;
            if ($baseline <= 0.0) {
                $baseline = 1.0;
            }

            $loopsPerSec = self::run_loop_avg($duration);
            if ($loopsPerSec <= 0) {
                if ($cached['value'] !== null) {
                    return self::$requestMemo = (float) $cached['value'];
                }
                return self::$requestMemo = false;
            }

            $scale = (float) \apply_filters('dfehc_loop_to_percent_scale', 0.125);
            if (!\is_finite($scale) || $scale <= 0.0) {
                $scale = 0.125;
            }

            $loadRatio   = ($baseline * $scale) / \max($loopsPerSec, 1);
            $loadPercent = \round(\min(100.0, \max(0.0, $loadRatio * 100.0)), 2);
            $loadPercent = (float) \apply_filters('dfehc_computed_load_percent', $loadPercent, $baseline, $loopsPerSec);

            self::update_spike_score($loadPercent, $suffix);
            self::set_cached_load_value($cacheKey, $loadPercent, $staleTtl);

            return self::$requestMemo = (float) $loadPercent;
        } finally {
            self::release_lock($lock);
        }
    }

    public static function calibrate_baseline(float $duration = 0.025): float
    {
        $duration = (float) \apply_filters('dfehc_loop_duration', $duration);
        $duration = self::normalize_duration($duration, 0.025);
        return self::run_loop_avg($duration);
    }

    public static function maybe_calibrate_if_idle(): void
    {
        if (!self::is_idle_context()) {
            return;
        }
        $suffix  = self::scope_suffix();
        $seenKey = 'dfehc_seen_recently_' . $suffix;
        $ttl     = (int) \apply_filters('dfehc_seen_recently_ttl', 60);
        if ($ttl < 1) {
            $ttl = 1;
        }
        if (\get_transient($seenKey) !== false) {
            return;
        }
        self::set_transient_noautoload($seenKey, 1, $ttl);
        self::ensure_baseline();
    }

    public static function maybe_calibrate_during_cron(): void
    {
        if (!\function_exists('wp_doing_cron') || !\wp_doing_cron()) {
            return;
        }
        $suffix = self::scope_suffix();
        $cdKey  = 'dfehc_cron_cal_cd_' . $suffix;
        $cdTtl  = (int) \apply_filters('dfehc_cron_calibration_cooldown', 300);
        if ($cdTtl < 1) {
            $cdTtl = 1;
        }
        if (\get_transient($cdKey) !== false) {
            return;
        }
        self::set_transient_noautoload($cdKey, 1, $cdTtl);
        self::ensure_baseline();
    }

    public static function scheduled_recalibrate(string $suffix): void
    {
        $suffix = (string) $suffix;
        if ($suffix === '') {
            return;
        }
        $lock = self::acquire_lock('dfehc_sched_cal_' . $suffix, 30);
        if (!$lock) {
            return;
        }
        try {
            $baselineT = self::get_baseline_transient_name($suffix);
            self::delete_baseline_value($baselineT);
            self::ensure_baseline_for_suffix($suffix);
        } finally {
            self::release_lock($lock);
        }
    }

    private static function normalize_duration(float $duration, float $fallback): float
    {
        if (!\is_finite($duration) || $duration <= 0.0) {
            $duration = $fallback;
        }
        if ($duration < 0.01) {
            $duration = 0.01;
        } elseif ($duration > 0.5) {
            $duration = 0.5;
        }
        return $duration;
    }

    private static function rand_unit(): float
    {
        if (\function_exists('wp_rand')) {
            return (float) \wp_rand(0, 1000000) / 1000000.0;
        }
        return (float) \mt_rand(0, 1000000) / 1000000.0;
    }

    private static function is_idle_context(): bool
    {
        if (\is_admin() || \is_user_logged_in()) {
            return false;
        }
        if ((\function_exists('wp_doing_cron') && \wp_doing_cron()) ||
            (\function_exists('wp_doing_ajax') && \wp_doing_ajax()) ||
            (\function_exists('wp_is_json_request') && \wp_is_json_request()) ||
            (\defined('WP_CLI') && WP_CLI)) {
            return false;
        }
        return true;
    }

    private static function try_sys_getloadavg(): ?float
    {
        if (!\function_exists('sys_getloadavg')) {
            return null;
        }
        $avg = \sys_getloadavg();
        if (!\is_array($avg) || !isset($avg[0])) {
            return null;
        }
        $raw = (float) $avg[0];
        $raw = (float) \apply_filters('dfehc_raw_sys_load', $raw);

        $cores = 0;
        if (\defined('DFEHC_CPU_CORES')) {
            $cores = (int) DFEHC_CPU_CORES;
        } elseif (\function_exists('dfehc_get_cpu_cores')) {
            $cores = (int) \dfehc_get_cpu_cores();
        }
        $cores = (int) \apply_filters('dfehc_cpu_cores', $cores);
        if ($cores <= 0) {
            $cores = 1;
        }

        $pct = ($raw / $cores) * 100.0;
        if (!\is_finite($pct)) {
            return null;
        }

        return \min(100.0, \round(\max(0.0, $pct), 2));
    }

    private static function now(): float
    {
        if (\function_exists('hrtime')) {
            return (float) (\hrtime(true) / 1e9);
        }
        return (float) \microtime(true);
    }

    private static function run_loop(float $duration): float
    {
        $duration = self::normalize_duration($duration, 0.025);
        $duration += (float) \wp_rand(0, 2) * 0.001;

        $start = self::now();
        $end   = $start + $duration;

        $cap = (int) \apply_filters('dfehc_loop_iteration_cap', 10000000);
        if ($cap < 1000) {
            $cap = 1000;
        }

        $batch = (int) \apply_filters('dfehc_loop_timecheck_batch', 64);
        if ($batch < 8) {
            $batch = 8;
        } elseif ($batch > 2048) {
            $batch = 2048;
        }

        $cnt = 0;
        $now = $start;
        $lastNow = $start;
        $x = 0;

        while ($now < $end && $cnt < $cap) {
            for ($i = 0; $i < $batch && $cnt < $cap; $i++) {
                $x = ($x + 1) & 0xFFFFFFFF;
                $cnt++;
            }
            $now = self::now();
            if ($now < $lastNow) {
                $now = $lastNow;
            }
            $lastNow = $now;
        }

        $elapsed = $now - $start;
        return $elapsed > 0 ? $cnt / $elapsed : 0.0;
    }

    private static function run_loop_avg(float $duration): float
    {
        $a = self::run_loop($duration);
        $b = self::run_loop(\min(0.5, $duration * 1.5));
        if ($a <= 0.0 && $b <= 0.0) {
            return 0.0;
        }
        if ($a <= 0.0) {
            return $b;
        }
        if ($b <= 0.0) {

            return $a;
        }
        return ($a + $b) / 2.0;
    }

    private static function maybe_calibrate(string $baselineT, float $duration): float
    {
        $suffix   = self::scope_suffix();
        $lockKey  = 'dfehc_calibrating_' . $suffix;
        $lock     = self::acquire_lock($lockKey, 30);
        if (!$lock) {
            $existing = self::get_baseline_value($baselineT);
            if ($existing !== false && $existing !== null && \is_numeric($existing) && (float) $existing > 0.0) {
                return (float) $existing;
            }
        }

        $baseline = self::run_loop_avg($duration);
        if ($baseline <= 0.0) {
            $baseline = 1.0;
        }

        if ($lock) {
            $exp = (int) \apply_filters('dfehc_baseline_expiration', 7 * DAY_IN_SECONDS);
            if ($exp < 60) {
                $exp = 60;
            }
            self::set_baseline_value($baselineT, $baseline, $exp);
            self::release_lock($lock);
        }

        return $baseline;
    }

    private static function update_spike_score(float $loadPercent, string $suffix): void
    {
        $spikeKey   = self::get_spike_key($suffix);
        $scoreRaw   = self::get_scoped_transient($spikeKey);
        $score      = \is_numeric($scoreRaw) ? (float) $scoreRaw : 0.0;

        $decay      = (float) \apply_filters('dfehc_spike_decay', 0.5);
        $increment  = (float) \apply_filters('dfehc_spike_increment', 1.0);
        $threshold  = (float) \apply_filters('dfehc_spike_threshold', 3.0);
        $trigger    = (float) \apply_filters('dfehc_spike_trigger', 90.0);
        $resetCdTtl = (int) \apply_filters('dfehc_baseline_reset_cooldown', 3600);
        if ($resetCdTtl < 1) {
            $resetCdTtl = 1;
        }
        $resetCdKey = self::BASELINE_RESET_CD_PREFIX . $suffix;
        $scoreMax   = (float) \apply_filters('dfehc_spike_score_max', 20.0);

        if ($loadPercent > $trigger) {
            $score += $increment * (1 + (($loadPercent - $trigger) / 20.0));
        } else {
            $score = \max(0.0, $score - $decay);
        }

        $score = \min($scoreMax, \max(0.0, $score));

        if ($score >= $threshold) {
            $isCron = (\function_exists('wp_doing_cron') && \wp_doing_cron());
            $isIdle = self::is_idle_context();
            $canReset = $isCron || $isIdle;
            $canReset = (bool) \apply_filters('dfehc_allow_baseline_reset', $canReset, $loadPercent, $suffix);

            if ($canReset && \get_transient($resetCdKey) === false) {
                $baselineName = self::get_baseline_transient_name($suffix);
                self::delete_baseline_value($baselineName);
                self::set_transient_noautoload($resetCdKey, 1, $resetCdTtl);
                self::delete_scoped_transient($spikeKey);
                return;
            }

            if (\function_exists('wp_schedule_single_event') && \get_transient($resetCdKey) === false) {
                $scheduledKey = 'dfehc_sched_cal_cd_' . $suffix;
                $schedTtl = (int) \apply_filters('dfehc_scheduled_calibration_cooldown', 600, $suffix);
                if ($schedTtl < 60) {
                    $schedTtl = 60;
                }
                if (self::get_scoped_transient($scheduledKey) === false) {
                    self::set_scoped_transient_noautoload($scheduledKey, 1, $schedTtl);
                    \wp_schedule_single_event(\time() + 30, 'dfehc_calibrate_baseline_event', [$suffix]);
                }
            }
        }

        self::set_scoped_transient_noautoload($spikeKey, $score, (int) \apply_filters('dfehc_spike_score_ttl', HOUR_IN_SECONDS));
    }

    private static function ensure_baseline(): void
    {
        $suffix = self::scope_suffix();
        self::ensure_baseline_for_suffix($suffix);
    }

    private static function ensure_baseline_for_suffix(string $suffix): void
    {
        $baselineT = self::get_baseline_transient_name($suffix);
        $existing  = self::get_baseline_value($baselineT);
        if ($existing !== false && $existing !== null && \is_numeric($existing) && (float) $existing > 0.0) {
            return;
        }

        $lockKey = 'dfehc_calibrating_' . $suffix;
        $lock    = self::acquire_lock($lockKey, 30);
        if (!$lock) {
            return;
        }

        try {
            $duration = (float) \apply_filters('dfehc_loop_duration', 0.025);
            $duration = self::normalize_duration($duration, 0.025);

            $baseline = self::run_loop_avg($duration);
            if ($baseline <= 0.0) {
                $baseline = 1.0;
            }

            $exp = (int) \apply_filters('dfehc_baseline_expiration', 7 * DAY_IN_SECONDS);
            if ($exp < 60) {
                $exp = 60;
            }
            self::set_baseline_value($baselineT, $baseline, $exp);
        } finally {
            self::release_lock($lock);
        }
    }

    private static function acquire_lock(string $key, int $ttl)
    {
        $ttl = $ttl < 1 ? 1 : $ttl;
        $scopedKey = $key;

        if (\class_exists('\WP_Lock')) {
            $lock = new \WP_Lock($scopedKey, $ttl);
            return $lock->acquire() ? $lock : null;
        }

        if (\function_exists('wp_cache_add') && \wp_cache_add($scopedKey, 1, DFEHC_CACHE_GROUP, $ttl)) {
            return (object) ['type' => 'cache', 'key' => $scopedKey];
        }

        if (\get_transient($scopedKey) !== false) {
            return null;
        }

        if (\set_transient($scopedKey, 1, $ttl)) {
            return (object) ['type' => 'transient', 'key' => $scopedKey];
        }

        return null;
    }

    private static function release_lock($lock): void
    {
        if ($lock instanceof \WP_Lock) {
            $lock->release();
            return;
        }
        if (\is_object($lock) && isset($lock->type, $lock->key)) {
            if ($lock->type === 'cache') {
                if (\function_exists('wp_cache_delete')) {
                    \wp_cache_delete((string) $lock->key, DFEHC_CACHE_GROUP);
                }
                return;
            }
            if ($lock->type === 'transient') {
                \delete_transient((string) $lock->key);
                return;
            }
        }
    }

    private static function get_baseline_transient_name(string $suffix): string
    {
        return self::BASELINE_TRANSIENT_PREFIX . $suffix;
    }

    private static function get_hostname_key(): string
    {
        if (self::$hostnameKeyMemo !== null) {
            return self::$hostnameKeyMemo;
        }

        $url = \defined('WP_HOME') && WP_HOME ? WP_HOME : (\function_exists('home_url') ? \home_url() : '');
        $parts = \wp_parse_url((string) $url);
        $host = \is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if ($host === '') {
            $host = (string) @\php_uname('n');
        }
        if ($host === '') {
            $host = $url !== '' ? (string) $url : 'unknown';
        }

        $salt = \defined('DB_NAME') ? (string) DB_NAME : '';
        self::$hostnameKeyMemo = \substr(\md5($host . $salt), 0, 10);
        return self::$hostnameKeyMemo;
    }

    private static function get_blog_id(): int
    {
        if (self::$blogIdMemo !== null) {
            return (int) self::$blogIdMemo;
        }
        self::$blogIdMemo = \function_exists('get_current_blog_id') ? (int) \get_current_blog_id() : 0;
        return (int) self::$blogIdMemo;
    }

    private static function scope_suffix(): string
    {
        if (self::$scopeSuffixMemo !== null) {
            return self::$scopeSuffixMemo;
        }

        if (self::$sapiTagMemo === null) {
            $sapi = \php_sapi_name();
            $sapiTag = $sapi ? (string) \substr((string) \preg_replace('/[^a-z0-9]/i', '', \strtolower((string) $sapi)), 0, 6) : 'web';
            self::$sapiTagMemo = $sapiTag !== '' ? $sapiTag : 'web';
        }

        $suffix = self::get_hostname_key() . '_' . self::get_blog_id() . '_' . self::$sapiTagMemo;
        $override = \apply_filters('dfehc_baseline_scope_suffix', null, $suffix);
        if (\is_string($override) && $override !== '') {
            self::$scopeSuffixMemo = $override;
            return self::$scopeSuffixMemo;
        }

        self::$scopeSuffixMemo = $suffix;
        return self::$scopeSuffixMemo;
    }

    private static function get_cache_key(string $suffix): string
    {
        return self::LOAD_CACHE_TRANSIENT . '_' . $suffix;
    }

    private static function get_spike_key(string $suffix): string
    {
        return self::LOAD_SPIKE_TRANSIENT . '_' . $suffix;
    }

    private static function get_baseline_value(string $name)
    {
        return \is_multisite() ? \get_site_transient($name) : \get_transient($name);
    }

    private static function set_baseline_value(string $name, $value, int $exp): void
    {
        if ($exp < 1) {
            $exp = 1;
        }
        if (\is_multisite()) {
            self::set_site_transient_noautoload($name, $value, $exp);
        } else {
            self::set_transient_noautoload($name, $value, $exp);
        }
    }

    private static function delete_baseline_value(string $name): void
    {
        if (\is_multisite()) {
            \delete_site_transient($name);
        } else {
            \delete_transient($name);
        }
    }

    private static function get_scoped_transient(string $key)
    {
        return \is_multisite() ? \get_site_transient($key) : \get_transient($key);
    }

    private static function set_scoped_transient_noautoload(string $key, $value, int $ttl): void
    {
        if ($ttl < 1) {
            $ttl = 1;
        }
        if (\is_multisite()) {
            self::set_site_transient_noautoload($key, $value, $ttl);
        } else {
            self::set_transient_noautoload($key, $value, $ttl);
        }
    }

    private static function delete_scoped_transient(string $key): void
    {
        if (\is_multisite()) {
            \delete_site_transient($key);
        } else {
            \delete_transient($key);
        }
    }

    private static function get_cached_load_value(string $key, int $freshTtl, int $staleTtl): array
    {
        $raw = self::get_scoped_transient($key);
        $now = \time();

        $value = null;
        $age = null;

        if (\is_array($raw) && isset($raw['v'], $raw['t'])) {
            $t = (int) $raw['t'];
            if ($t > 0) {
                $age = $now - $t;
                if ($age < 0) {
                    $age = 0;
                }
                if ($age <= $staleTtl && \is_numeric($raw['v'])) {
                    $value = (float) $raw['v'];
                }
            }
        } elseif ($raw !== false && $raw !== null && \is_numeric($raw)) {
            $value = (float) $raw;
            $age = null;
        }

        $fresh = ($value !== null) && ($age === null || $age <= $freshTtl);

        return [
            'value' => $value,
            'fresh' => $fresh,
        ];
    }

    private static function set_cached_load_value(string $key, float $value, int $ttl): void
    {
        self::set_scoped_transient_noautoload($key, ['v' => (float) $value, 't' => \time()], $ttl);
    }

    private static function normalize_transient_key(string $key): string
    {
        $key = \trim($key);
        if ($key === '') {
            return '';
        }
        $key = \preg_replace('/[^a-zA-Z0-9_\-:]/', '_', $key) ?? $key;
        $key = \trim($key, "_ \t\n\r\0\x0B");
        if ($key === '') {
            return '';
        }
        if (\strlen($key) > 172) {
            $key = \substr($key, 0, 120) . '_' . \substr(\md5($key), 0, 16);
        }
        return $key;
    }

    private static function set_transient_noautoload(string $key, $value, int $ttl): void
    {
        $key = self::normalize_transient_key($key);
        if ($key === '') {
            return;
        }

        $jitter = 0;
        if (\function_exists('random_int')) {
            try {
                $jitter = \random_int(0, 5);
            } catch (\Throwable $e) {
                $jitter = 0;
            }
        }
        $ttl = (int) $ttl;
        $ttl = \max(1, $ttl + $jitter);

        if (\function_exists('wp_using_ext_object_cache') && \wp_using_ext_object_cache()) {
            if (\function_exists('wp_cache_set')) {
                \wp_cache_set($key, $value, DFEHC_CACHE_GROUP, $ttl);
            }
            return;
        }

        \set_transient($key, $value, $ttl);

        $minTtl = (int) \apply_filters('dfehc_noautoload_db_fix_min_ttl', 600);
        $doFix  = (bool) \apply_filters('dfehc_fix_transient_autoload', false, $key, $ttl);

        if (!$doFix || $ttl < $minTtl) {
            return;
        }

        global $wpdb;
        if (!($wpdb instanceof \wpdb) || empty($wpdb->options)) {
            return;
        }

        $opt_key = '_transient_' . $key;
        $opt_key_to = '_transient_timeout_' . $key;

        $prev_suppress = $wpdb->suppress_errors(true);
        try {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name = %s AND autoload <> 'no' LIMIT 1",
                $opt_key
            ));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name = %s AND autoload <> 'no' LIMIT 1",
                $opt_key_to
            ));
        } catch (\Throwable $e) {
        } finally {
            $wpdb->suppress_errors((bool) $prev_suppress);
        }
    }

    private static function set_site_transient_noautoload(string $key, $value, int $ttl): void
    {
        $key = self::normalize_transient_key($key);
        if ($key === '') {
            return;
        }

        $jitter = 0;
        if (\function_exists('random_int')) {
            try {
                $jitter = \random_int(0, 5);
            } catch (\Throwable $e) {
                $jitter = 0;
            }
        }
        $ttl = (int) $ttl;
        $ttl = \max(1, $ttl + $jitter);

        if (\function_exists('wp_using_ext_object_cache') && \wp_using_ext_object_cache()) {
            if (\function_exists('wp_cache_set')) {
                \wp_cache_set($key, $value, DFEHC_CACHE_GROUP, $ttl);
            }
            return;
        }

        \set_site_transient($key, $value, $ttl);
    }
}

\add_action('init', [Dfehc_ServerLoadEstimator::class, 'maybe_calibrate_during_cron']);
\add_action('template_redirect', [Dfehc_ServerLoadEstimator::class, 'maybe_calibrate_if_idle']);
\add_action('dfehc_calibrate_baseline_event', [Dfehc_ServerLoadEstimator::class, 'scheduled_recalibrate'], 10, 1);

\add_filter('heartbeat_settings', function ($settings) {
    if (!\class_exists(Dfehc_ServerLoadEstimator::class)) {
        return $settings;
    }
    $load = Dfehc_ServerLoadEstimator::get_server_load();
    if ($load === false) {
        return $settings;
    }
    $ths = \wp_parse_args(\apply_filters('dfehc_heartbeat_thresholds', []), [
        'low'    => 20,
        'medium' => 50,
        'high'   => 75,
    ]);
    $suggested = null;
    if (!\is_admin() && !\current_user_can('edit_posts')) {
        $suggested = $load < $ths['low'] ? 50 : ($load < $ths['medium'] ? 60 : ($load < $ths['high'] ? 120 : 180));
    } elseif (\current_user_can('manage_options')) {
        $suggested = $load < $ths['high'] ? 20 : 40;
    } elseif (\current_user_can('edit_others_posts')) {
        $suggested = $load < $ths['high'] ? 30 : 60;
    }
    if ($suggested !== null) {
        $suggested = (int) \min(\max($suggested, 15), 300);
        if (isset($settings['interval']) && \is_numeric($settings['interval'])) {
            $current = (int) $settings['interval'];
            $settings['interval'] = (int) \min(\max(\max($current, $suggested), 15), 300);
        } else {
            $settings['interval'] = $suggested;
        }
    }
    return $settings;
}, 5);