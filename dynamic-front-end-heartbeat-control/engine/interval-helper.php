<?php
declare(strict_types=1);

if (!defined('DFEHC_OPTIONS_PREFIX')) define('DFEHC_OPTIONS_PREFIX', 'dfehc_');
if (!defined('DFEHC_OPTION_MIN_INTERVAL')) define('DFEHC_OPTION_MIN_INTERVAL', DFEHC_OPTIONS_PREFIX . 'min_interval');
if (!defined('DFEHC_OPTION_MAX_INTERVAL')) define('DFEHC_OPTION_MAX_INTERVAL', DFEHC_OPTIONS_PREFIX . 'max_interval');
if (!defined('DFEHC_OPTION_PRIORITY_SLIDER')) define('DFEHC_OPTION_PRIORITY_SLIDER', DFEHC_OPTIONS_PREFIX . 'priority_slider');
if (!defined('DFEHC_OPTION_EMA_ALPHA')) define('DFEHC_OPTION_EMA_ALPHA', DFEHC_OPTIONS_PREFIX . 'ema_alpha');
if (!defined('DFEHC_OPTION_MAX_SERVER_LOAD')) define('DFEHC_OPTION_MAX_SERVER_LOAD', DFEHC_OPTIONS_PREFIX . 'max_server_load');
if (!defined('DFEHC_OPTION_MAX_RESPONSE_TIME')) define('DFEHC_OPTION_MAX_RESPONSE_TIME', DFEHC_OPTIONS_PREFIX . 'max_response_time');
if (!defined('DFEHC_OPTION_SMA_WINDOW')) define('DFEHC_OPTION_SMA_WINDOW', DFEHC_OPTIONS_PREFIX . 'sma_window');
if (!defined('DFEHC_OPTION_MAX_DECREASE_RATE')) define('DFEHC_OPTION_MAX_DECREASE_RATE', DFEHC_OPTIONS_PREFIX . 'max_decrease_rate');

if (!defined('DFEHC_DEFAULT_MIN_INTERVAL')) define('DFEHC_DEFAULT_MIN_INTERVAL', 15);
if (!defined('DFEHC_DEFAULT_MAX_INTERVAL')) define('DFEHC_DEFAULT_MAX_INTERVAL', 300);
if (!defined('DFEHC_DEFAULT_MAX_SERVER_LOAD')) define('DFEHC_DEFAULT_MAX_SERVER_LOAD', 85);
if (!defined('DFEHC_DEFAULT_MAX_RESPONSE_TIME')) define('DFEHC_DEFAULT_MAX_RESPONSE_TIME', 5.0);
if (!defined('DFEHC_DEFAULT_EMA_ALPHA')) define('DFEHC_DEFAULT_EMA_ALPHA', 0.4);
if (!defined('DFEHC_DEFAULT_SMA_WINDOW')) define('DFEHC_DEFAULT_SMA_WINDOW', 5);
if (!defined('DFEHC_DEFAULT_MAX_DECREASE_RATE')) define('DFEHC_DEFAULT_MAX_DECREASE_RATE', 0.25);
if (!defined('DFEHC_DEFAULT_EMA_TTL')) define('DFEHC_DEFAULT_EMA_TTL', 900);

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
        return $t = substr(md5((string) $host . $salt), 0, 10);
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
        return "{$base}_" . dfehc_blog_id() . '_' . dfehc_host_token();
    }
}

if (!function_exists('dfehc_apply_filters')) {
    function dfehc_apply_filters(string $tag, $value, ...$args)
    {
        if (function_exists('apply_filters')) {
            return apply_filters($tag, $value, ...$args);
        }
        return $value;
    }
}

if (!function_exists('dfehc_get_option')) {
    function dfehc_get_option(string $key, $default)
    {
        if (function_exists('get_option')) {
            return get_option($key, $default);
        }
        return $default;
    }
}

if (!function_exists('dfehc_cache_get')) {
    function dfehc_cache_get(string $key)
    {
        $group = defined('DFEHC_CACHE_GROUP') ? (string) DFEHC_CACHE_GROUP : 'dfehc';

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_get')) {
            $v = wp_cache_get($key, $group);
            return $v === false ? null : $v;
        }

        if (function_exists('get_transient')) {
            $v = get_transient($key);
            return $v === false ? null : $v;
        }

        return null;
    }
}

if (!function_exists('dfehc_store_lockfree')) {
    function dfehc_store_lockfree(string $key, $value, int $ttl): bool
    {
        $group = defined('DFEHC_CACHE_GROUP') ? (string) DFEHC_CACHE_GROUP : 'dfehc';
        $ttl = max(1, $ttl);

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_set')) {
            wp_cache_set($key, $value, $group, $ttl);
            return true;
        }

        return function_exists('set_transient') ? (bool) set_transient($key, $value, $ttl) : false;
    }
}

if (!function_exists('dfehc_clamp')) {
    function dfehc_clamp(float $v, float $lo, float $hi): float
    {
        if (!is_finite($v)) return $lo;
        return max($lo, min($hi, $v));
    }
}

if (!function_exists('dfehc_abs')) {
    function dfehc_abs(float $v): float
    {
        return $v < 0 ? -$v : $v;
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
            try { return (int) random_int($min, $max); } catch (\Throwable $e) {}
        }
        if (function_exists('wp_rand')) return (int) wp_rand($min, $max);
        return (int) mt_rand($min, $max);
    }
}

if (!function_exists('dfehc_weighted_sum')) {
    function dfehc_weighted_sum(array $factors, array $weights): float
    {
        $sum = 0.0;
        foreach ($factors as $k => $v) {
            $w = isset($weights[$k]) ? (float) $weights[$k] : 0.0;
            $sum += $w * (float) $v;
        }
        return (float) $sum;
    }
}

if (!function_exists('dfehc_normalize_weights')) {
    function dfehc_normalize_weights(array $weights): array
    {
        $total = 0.0;
        foreach ($weights as $k => $w) {
            $weights[$k] = (float) $w;
            $total += $weights[$k];
        }

        if ($total <= 0.0) {
            $n = max(1, count($weights));
            $equal = 1.0 / $n;
            foreach ($weights as $k => $_) {
                $weights[$k] = $equal;
            }
            return $weights;
        }

        foreach ($weights as $k => $w) {
            $weights[$k] = (float) ($w / $total);
        }

        return $weights;
    }
}

if (!function_exists('dfehc_should_write_value')) {
    function dfehc_should_write_value(?float $prev, float $next, float $eps): bool
    {
        if (!is_finite($next)) return false;
        if ($prev === null || !is_finite($prev)) return true;
        return dfehc_abs($next - $prev) >= max(0.0, $eps);
    }
}

if (!function_exists('dfehc_apply_exponential_moving_average')) {
    function dfehc_apply_exponential_moving_average(float $current): float
    {
        static $memo = null;

        $current = is_finite($current) ? $current : 0.0;

        $alphaOpt = dfehc_get_option(DFEHC_OPTION_EMA_ALPHA, DFEHC_DEFAULT_EMA_ALPHA);
        $alpha = dfehc_clamp((float) $alphaOpt, 0.01, 1.0);

        $key = dfehc_scoped_key('dfehc_ema');

        $prev = null;
        if (is_array($memo) && array_key_exists($key, $memo)) {
            $prev = $memo[$key];
        } else {
            $pv = dfehc_cache_get($key);
            $prev = (is_numeric($pv) ? (float) $pv : null);
        }

        $ema = ($prev === null) ? $current : ($alpha * $current + (1.0 - $alpha) * (float) $prev);

        if (!is_finite($ema)) $ema = $current;

        $ttlDefault = max((int) DFEHC_DEFAULT_EMA_TTL, (int) dfehc_get_option(DFEHC_OPTION_MAX_INTERVAL, DFEHC_DEFAULT_MAX_INTERVAL) * 2);
        $ttlDefault = (int) dfehc_clamp((float) $ttlDefault, 60.0, 86400.0);
        $ttl = (int) dfehc_apply_filters('dfehc_ema_ttl', $ttlDefault, $current, $ema);
        $ttl = max(30, $ttl + dfehc_rand_jitter(0, 5));

        $eps = (float) dfehc_apply_filters('dfehc_ema_write_epsilon', 0.05, $current, $ema, $prev);
        $eps = max(0.0, $eps);

        if (function_exists('dfehc_set_transient_noautoload') && dfehc_should_write_value($prev, $ema, $eps)) {
            dfehc_set_transient_noautoload($key, $ema, $ttl);
        }

        if (!is_array($memo)) $memo = [];
        $memo[$key] = $ema;

        return (float) $ema;
    }
}

if (!function_exists('dfehc_defensive_stance')) {
    function dfehc_defensive_stance(float $proposed): float
    {
        static $memo = null;

        $proposed = is_finite($proposed) ? $proposed : 0.0;

        $key = dfehc_scoped_key('dfehc_prev_int');

        $previous = null;
        if (is_array($memo) && array_key_exists($key, $memo)) {
            $previous = $memo[$key];
        } else {
            $pv = dfehc_cache_get($key);
            $previous = (is_numeric($pv) ? (float) $pv : null);
        }

        if ($previous === null) {
            $ttl = (int) dfehc_apply_filters('dfehc_prev_interval_ttl', 1800);
            $ttl = max(60, $ttl + dfehc_rand_jitter(0, 5));

            if (function_exists('dfehc_set_transient_noautoload')) {
                dfehc_set_transient_noautoload($key, $proposed, $ttl);
            }

            if (!is_array($memo)) $memo = [];
            $memo[$key] = $proposed;

            return (float) $proposed;
        }

        $previous = (float) $previous;

        $max_drop = dfehc_clamp((float) dfehc_get_option(DFEHC_OPTION_MAX_DECREASE_RATE, DFEHC_DEFAULT_MAX_DECREASE_RATE), 0.0, 0.95);
        $max_rise = dfehc_clamp((float) dfehc_apply_filters('dfehc_max_increase_rate', 0.5), 0.0, 5.0);

        $lower = $previous * (1.0 - $max_drop);
        $upper = $previous * (1.0 + $max_rise);

        $final = dfehc_clamp($proposed, $lower, $upper);

        $ttl = (int) dfehc_apply_filters('dfehc_prev_interval_ttl', 1800);
        $ttl = max(60, $ttl + dfehc_rand_jitter(0, 5));

        $eps = (float) dfehc_apply_filters('dfehc_prev_interval_write_epsilon', 0.5, $proposed, $final, $previous);
        $eps = max(0.0, $eps);

        if (function_exists('dfehc_set_transient_noautoload') && dfehc_should_write_value($previous, $final, $eps)) {
            dfehc_set_transient_noautoload($key, $final, $ttl);
        }

        if (!is_array($memo)) $memo = [];
        $memo[$key] = $final;

        return (float) $final;
    }
}

if (!function_exists('dfehc_set_transient')) {
    function dfehc_set_transient(string $key, float $value, float $interval): void
    {
        $ttlBase = max(60, (int) ceil(max(0.0, $interval)) * 2);
        $ttl = (int) dfehc_apply_filters('dfehc_transient_ttl', $ttlBase, $key, $value, $interval);
        $ttl = max(30, $ttl + dfehc_rand_jitter(0, 5));

        if (function_exists('dfehc_set_transient_noautoload')) {
            dfehc_set_transient_noautoload($key, $value, $ttl);
        } else {
            dfehc_store_lockfree($key, $value, $ttl);
        }
    }
}

if (!function_exists('dfehc_apply_factor_overrides')) {
    function dfehc_apply_factor_overrides(array $factors, float $time_elapsed, float $load_average, float $server_response_time): array
    {
        $factors = (array) dfehc_apply_filters('dfehc_interval_factors', $factors, $time_elapsed, $load_average, $server_response_time);
        foreach ($factors as $k => $v) {
            $factors[$k] = is_numeric($v) ? (float) $v : 0.0;
        }
        return $factors;
    }
}

if (!function_exists('dfehc_calculate_recommended_interval')) {
    function dfehc_calculate_recommended_interval(float $time_elapsed, float $load_average, float $server_response_time): float
    {
        $min_interval = max(1, (int) dfehc_get_option(DFEHC_OPTION_MIN_INTERVAL, DFEHC_DEFAULT_MIN_INTERVAL));
        $max_interval = max($min_interval, (int) dfehc_get_option(DFEHC_OPTION_MAX_INTERVAL, DFEHC_DEFAULT_MAX_INTERVAL));

        $max_server_load = (float) dfehc_get_option(DFEHC_OPTION_MAX_SERVER_LOAD, DFEHC_DEFAULT_MAX_SERVER_LOAD);
        $max_server_load = max(0.1, $max_server_load);

        $max_response_time = (float) dfehc_get_option(DFEHC_OPTION_MAX_RESPONSE_TIME, DFEHC_DEFAULT_MAX_RESPONSE_TIME);
        $max_response_time = max(0.1, $max_response_time);

        $time_elapsed = max(0.0, $time_elapsed);
        $load_average = is_finite($load_average) ? $load_average : 0.0;
        $server_response_time = is_finite($server_response_time) ? $server_response_time : 0.0;

        $custom_norm = dfehc_apply_filters('dfehc_normalize_load', null, $load_average);
        if (is_numeric($custom_norm)) {
            $la = dfehc_clamp((float) $custom_norm, 0.0, 1.0);
        } else {
            if ($load_average <= 1.0) {
                $la = dfehc_clamp((float) $load_average, 0.0, 1.0);
            } elseif ($load_average <= 100.0) {
                $la = dfehc_clamp((float) $load_average / 100.0, 0.0, 1.0);
            } else {
                $assumed_cores = (float) dfehc_apply_filters('dfehc_assumed_cores_for_normalization', 8.0);
                $la = dfehc_clamp((float) $load_average / max(1.0, $assumed_cores), 0.0, 1.0);
            }
        }

        $msl_ratio = $max_server_load > 1.0 ? ($max_server_load / 100.0) : $max_server_load;
        if (!is_finite($msl_ratio) || $msl_ratio <= 0.0) $msl_ratio = 1.0;

        $server_load_factor = dfehc_clamp($la / $msl_ratio, 0.0, 1.0);

        $rt_units = dfehc_apply_filters('dfehc_response_time_is_ms', null, $server_response_time);
        $rt = (float) $server_response_time;
        if ($rt_units === true) {
            $rt = $rt / 1000.0;
        } elseif ($rt_units === null) {
            if ($rt > ($max_response_time * 3.0) && $rt <= 60000.0) {
                $rt = $rt / 1000.0;
            }
        }
        $rt = max(0.0, (float) $rt);

        $response_time_factor = $rt > 0.0 ? dfehc_clamp($rt / $max_response_time, 0.0, 1.0) : 0.0;

        $factors = [
            'user_activity' => dfehc_clamp(($max_interval > 0 ? $time_elapsed / $max_interval : 0.0), 0.0, 1.0),
            'server_load'   => $server_load_factor,
            'response_time' => $response_time_factor,
        ];
        $factors = dfehc_apply_factor_overrides($factors, $time_elapsed, $load_average, $server_response_time);

        $slider = dfehc_clamp((float) dfehc_get_option(DFEHC_OPTION_PRIORITY_SLIDER, 0.0), -1.0, 1.0);

        $weights = [
            'user_activity' => 0.4 - 0.2 * $slider,
            'server_load'   => (0.6 + 0.2 * $slider) / 2.0,
            'response_time' => (0.6 + 0.2 * $slider) / 2.0,
        ];
        $weights = (array) dfehc_apply_filters('dfehc_interval_weights', $weights, $slider);
        $weights = dfehc_normalize_weights($weights);

        $raw = (float) ($min_interval + dfehc_weighted_sum($factors, $weights) * ($max_interval - $min_interval));
        $raw = dfehc_clamp($raw, (float) $min_interval, (float) $max_interval);

        $smoothed = dfehc_apply_exponential_moving_average($raw);
        $lagged = dfehc_defensive_stance($smoothed);

        $final = (float) dfehc_apply_filters('dfehc_interval_snap', $lagged, $min_interval, $max_interval);
        $final = dfehc_clamp((float) $final, (float) $min_interval, (float) $max_interval);

        return (float) $final;
    }
}

if (!function_exists('dfehc_calculate_interval_based_on_duration')) {
    function dfehc_calculate_interval_based_on_duration(float $avg_duration, float $load_average): float
    {
    $min_interval = max(1, (int) dfehc_get_option(DFEHC_OPTION_MIN_INTERVAL, DFEHC_DEFAULT_MIN_INTERVAL));
    $max_interval = max($min_interval, (int) dfehc_get_option(DFEHC_OPTION_MAX_INTERVAL, DFEHC_DEFAULT_MAX_INTERVAL));

    if (!is_finite($avg_duration)) $avg_duration = 0.0;
    if (!is_finite($load_average)) $load_average = 0.0;

    if ($avg_duration <= $min_interval) return (float) $min_interval;
    if ($avg_duration >= $max_interval) return (float) $max_interval;

    return (float) dfehc_calculate_recommended_interval($avg_duration, $load_average, 0.0);
    }
}

if (!function_exists('dfehc_smooth_moving')) {
    function dfehc_smooth_moving(array $values): float
    {
        if (!$values) return 0.0;
        $window = max(1, (int) dfehc_get_option(DFEHC_OPTION_SMA_WINDOW, DFEHC_DEFAULT_SMA_WINDOW));
        $subset = array_slice($values, -$window);
        if (!$subset) return 0.0;

        $sum = 0.0;
        $cnt = 0;
        foreach ($subset as $v) {
            if (is_numeric($v) && is_finite((float) $v)) {
                $sum += (float) $v;
                $cnt++;
            }
        }
        return $cnt > 0 ? (float) ($sum / $cnt) : 0.0;
    }
}