<?php
declare(strict_types=1);

defined('DFEHC_SERVER_LOAD_TTL') || define('DFEHC_SERVER_LOAD_TTL', 60);
defined('DFEHC_SENTINEL_NO_LOAD') || define('DFEHC_SENTINEL_NO_LOAD', 0.404);
defined('DFEHC_SYSTEM_LOAD_KEY') || define('DFEHC_SYSTEM_LOAD_KEY', 'dfehc_system_load_avg');
defined('DFEHC_CACHE_GROUP') || define('DFEHC_CACHE_GROUP', 'dfehc');

if (!function_exists('dfehc_host_token')) {
    function dfehc_host_token(): string
    {
        static $t = '';
        if ($t !== '') return $t;

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
        return $t = substr(md5($host . $salt), 0, 10);
    }
}

if (!function_exists('dfehc_blog_id')) {
    function dfehc_blog_id(): int
    {
        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    }
}

if (!function_exists('dfehc_scoped_key')) {
    function dfehc_scoped_key(string $base): string
    {
        return $base . '_' . dfehc_blog_id() . '_' . dfehc_host_token();
    }
}

if (!function_exists('dfehc_rand_jitter')) {
    function dfehc_rand_jitter(int $min, int $max): int
    {
        $min = (int) $min;
        $max = (int) $max;
        if ($max < $min) { $t = $min; $min = $max; $max = $t; }
        if ($min === $max) return $min;
        if (function_exists('random_int')) {
            try { return (int) random_int($min, $max); } catch (Throwable $e) {}
        }
        return (int) (function_exists('wp_rand') ? wp_rand($min, $max) : mt_rand($min, $max));
    }
}

if (!function_exists('dfehc_cache_get_scalar')) {
    function dfehc_cache_get_scalar(string $key)
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_get')) {
            $v = wp_cache_get($key, DFEHC_CACHE_GROUP);
            if ($v !== false && $v !== null) return $v;
        }
        if (function_exists('get_transient')) {
            $v = get_transient($key);
            if ($v !== false) return $v;
        }
        return null;
    }
}

if (!function_exists('dfehc_allow_shell_fallback')) {
    function dfehc_allow_shell_fallback(): bool
    {
        $allow = (bool) apply_filters('dfehc_allow_shell_fallback', false);
        if (!$allow) return false;
        if (ini_get('open_basedir')) return false;
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) return false;
        return true;
    }
}

if (!function_exists('dfehc_exec_with_timeout')) {
    function dfehc_exec_with_timeout(string $cmd, float $timeoutSec = 1.0): string
    {
        if (!dfehc_allow_shell_fallback()) return '';

        $timeoutSec = max(0.15, min(3.0, $timeoutSec));
        $cmd = trim($cmd);
        if ($cmd === '') return '';

        if (PHP_OS_FAMILY !== 'Windows') {
            $sec = max(1, (int) ceil($timeoutSec));
            $pref = 'timeout ' . $sec . ' ';
            $cmd = $pref . $cmd;
        }

        $out = @shell_exec($cmd);
        return is_string($out) ? trim($out) : '';
    }
}

if (!function_exists('dfehc_get_cpu_cores')) {
    function dfehc_get_cpu_cores(): int
    {
        static $cached = null;
        if ($cached !== null) return (int) $cached;

        $tkey = dfehc_scoped_key('dfehc_cpu_cores');

        $v = dfehc_cache_get_scalar($tkey);
        if ($v !== null && is_numeric($v) && (int) $v > 0) {
            return $cached = (int) $v;
        }

        $override = getenv('DFEHC_CPU_CORES');
        if ($override !== false && is_numeric($override) && (int) $override > 0) {
            $cores = (int) $override;
            dfehc_set_transient_noautoload($tkey, $cores, (int) apply_filters('dfehc_cpu_cores_ttl', DAY_IN_SECONDS));
            return $cached = $cores;
        }
        if (defined('DFEHC_CPU_CORES') && is_numeric(DFEHC_CPU_CORES) && (int) DFEHC_CPU_CORES > 0) {
            $cores = (int) DFEHC_CPU_CORES;
            dfehc_set_transient_noautoload($tkey, $cores, (int) apply_filters('dfehc_cpu_cores_ttl', DAY_IN_SECONDS));
            return $cached = $cores;
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

        if ($cores === 1 && is_readable('/proc/cpuinfo')) {
            $cnt = preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo'));
            if ($cnt > 0) $cores = (int) $cnt;
        }

        $cores = (int) apply_filters('dfehc_cpu_cores', max(1, $cores));
        dfehc_set_transient_noautoload($tkey, $cores, (int) apply_filters('dfehc_cpu_cores_ttl', DAY_IN_SECONDS));
        return $cached = $cores;
    }
}

if (!function_exists('dfehc_get_system_load_average')) {
    function dfehc_get_system_load_average(): float
    {
        static $memo = null;
        if ($memo !== null) return (float) $memo;

        $ttl = (int) apply_filters('dfehc_system_load_ttl', (int) DFEHC_SERVER_LOAD_TTL);
        $ttl = max(5, min(600, $ttl));
        $ttl = $ttl + dfehc_rand_jitter(0, 6);

        $key = dfehc_scoped_key(DFEHC_SYSTEM_LOAD_KEY);

        $as_percent = false;

        $cached = dfehc_cache_get_scalar($key);
        if ($cached !== null && is_numeric($cached)) {
            $ratio = (float) $cached;
            $as_percent = (bool) apply_filters('dfehc_system_load_return_percent', false, $ratio);
            $memo = $as_percent ? (float) round($ratio * 100.0, 2) : (float) $ratio;
            return (float) $memo;
        }

        $raw = null;
        $source = '';
        $normalized_ratio = false;

        $strict = (bool) apply_filters('dfehc_system_load_strict_fallback', true);

        if ($raw === null && function_exists('dfehc_get_server_load')) {
            $val = dfehc_get_server_load();
            if (is_numeric($val)) {
                $v = (float) $val;
                if ($v !== (float) DFEHC_SENTINEL_NO_LOAD && $v >= 0.0) {
                    if ($v > 1.0) {
                        $raw = $v / 100.0;
                        $normalized_ratio = true;
                        $source = 'dfehc_get_server_load_percent';
                    } else {
                        $raw = $v;
                        $normalized_ratio = true;
                        $source = 'dfehc_get_server_load_ratio';
                    }
                }
            }
        }

        if ($raw === null && function_exists('sys_getloadavg')) {
            $arr = @sys_getloadavg();
            if (is_array($arr) && isset($arr[0]) && is_numeric($arr[0]) && (float) $arr[0] >= 0.0) {
                $raw = (float) $arr[0];
                $source = 'sys_getloadavg';
            }
        }

        if ($raw === null && PHP_OS_FAMILY !== 'Windows' && is_readable('/proc/loadavg')) {
            $txt = (string) @file_get_contents('/proc/loadavg');
            if ($txt !== '') {
                $parts = preg_split('/\s+/', trim($txt));
                if (is_array($parts) && isset($parts[0]) && is_numeric($parts[0]) && (float) $parts[0] >= 0.0) {
                    $raw = (float) $parts[0];
                    $source = 'proc_loadavg';
                }
            }
        }

        if (!$strict) {
            if ($raw === null && PHP_OS_FAMILY !== 'Windows') {
                $out = dfehc_exec_with_timeout('LANG=C uptime 2>/dev/null', 1.0);
                if ($out !== '' && preg_match('/load average[s]?:\s*([0-9.]+)/', $out, $m)) {
                    $v = (float) $m[1];
                    if ($v >= 0.0) {
                        $raw = $v;
                        $source = 'uptime';
                    }
                }
            }

            if ($raw === null && PHP_OS_FAMILY === 'Windows') {
                $out = dfehc_exec_with_timeout('wmic cpu get loadpercentage /value 2>NUL', 1.5);
                if ($out !== '' && preg_match('/loadpercentage=(\d+)/i', $out, $m)) {
                    $pct = (float) $m[1];
                    if ($pct >= 0.0 && $pct <= 100.0) {
                        $raw = ($pct / 100.0) * dfehc_get_cpu_cores();
                        $source = 'wmic';
                    }
                }
            }
        }

        if ($raw === null && class_exists('\\DynamicHeartbeat\\Dfehc_ServerLoadEstimator')) {
            $est = \DynamicHeartbeat\Dfehc_ServerLoadEstimator::get_server_load();
            if (is_numeric($est)) {
                $pct = (float) $est;
                if ($pct >= 0.0 && $pct <= 100.0) {
                    $raw = $pct / 100.0;
                    $normalized_ratio = true;
                    $source = 'estimator_percent';
                }
            }
        }

        if ($raw === null) {
            $memo = (float) DFEHC_SENTINEL_NO_LOAD;
            $as_percent = (bool) apply_filters('dfehc_system_load_return_percent', false, $memo);
            return $as_percent ? (float) round($memo * 100.0, 2) : (float) $memo;
        }

        if ($normalized_ratio) {
            $ratio = (float) $raw;
        } else {
            $divide = (bool) apply_filters('dfehc_divide_load_by_cores', true, $raw, $source);
            if ($divide) {
                $cores = dfehc_get_cpu_cores();
                $ratio = $cores > 0 ? ((float) $raw) / $cores : (float) $raw;
            } else {
                $ratio = (float) $raw;
            }
        }

        if (!is_finite($ratio)) {
            $ratio = (float) DFEHC_SENTINEL_NO_LOAD;
        }

        $ratio = max((float) DFEHC_SENTINEL_NO_LOAD, (float) $ratio);

        if ($ratio !== (float) DFEHC_SENTINEL_NO_LOAD) {
            dfehc_set_transient_noautoload($key, $ratio, $ttl);
        }

        $as_percent = (bool) apply_filters('dfehc_system_load_return_percent', false, $ratio);
        $memo = $as_percent ? (float) round($ratio * 100.0, 2) : (float) $ratio;
        return (float) $memo;
    }
}