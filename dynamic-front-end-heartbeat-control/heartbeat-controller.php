<?php
/*
Plugin Name: Dynamic Front-End Heartbeat Control
Plugin URI: https://heartbeat.support
Description: An enhanced solution to optimize the performance of your WordPress website. Stabilize your website's load averages and enhance the browsing experience for visitors during high-traffic fluctuations. 
Version: 1.2.998.1
Author: Codeloghin
Author URI: https://codeloghin.com
License: GPL2
*/

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DFEHC_PLUGIN_PATH')) {
    define('DFEHC_PLUGIN_PATH', plugin_dir_path(__FILE__));
}
if (!defined('DFEHC_CACHE_GROUP')) {
    define('DFEHC_CACHE_GROUP', 'dfehc');
}
if (!defined('DFEHC_MIN_INTERVAL')) {
    define('DFEHC_MIN_INTERVAL', 15);
}
if (!defined('DFEHC_MAX_INTERVAL')) {
    define('DFEHC_MAX_INTERVAL', 300);
}
if (!defined('DFEHC_MAX_SERVER_LOAD')) {
    define('DFEHC_MAX_SERVER_LOAD', 85);
}
if (!defined('DFEHC_MAX_RESPONSE_TIME')) {
    define('DFEHC_MAX_RESPONSE_TIME', 5000);
}
if (!defined('DFEHC_BATCH_SIZE')) {
    define('DFEHC_BATCH_SIZE', 75);
}
if (!defined('DFEHC_NONCE_ACTION')) {
    define('DFEHC_NONCE_ACTION', 'dfehc_get_recommended_intervals');
}
if (!defined('DFEHC_LOCK_TTL')) {
    define('DFEHC_LOCK_TTL', 60);
}
if (!defined('DFEHC_USER_ACTIVITY_TTL')) {
    define('DFEHC_USER_ACTIVITY_TTL', HOUR_IN_SECONDS);
}

require_once DFEHC_PLUGIN_PATH . 'engine/interval-helper.php';
require_once DFEHC_PLUGIN_PATH . 'engine/server-load.php';
require_once DFEHC_PLUGIN_PATH . 'engine/server-response.php';
require_once DFEHC_PLUGIN_PATH . 'engine/system-load-fallback.php';
require_once DFEHC_PLUGIN_PATH . 'visitor/manager.php';
require_once DFEHC_PLUGIN_PATH . 'visitor/cookie-helper.php';
require_once DFEHC_PLUGIN_PATH . 'defibrillator/unclogger.php';
require_once DFEHC_PLUGIN_PATH . 'defibrillator/rest-api.php';
require_once DFEHC_PLUGIN_PATH . 'defibrillator/db-health.php';
require_once DFEHC_PLUGIN_PATH . 'widget.php';
require_once DFEHC_PLUGIN_PATH . 'settings.php';

if (!function_exists('dfehc_scoped_tkey')) {
    function dfehc_scoped_tkey(string $base): string
    {
        if (function_exists('dfehc_scoped_key')) {
            return dfehc_scoped_key($base);
        }
        $bid = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '0';
        $host = @php_uname('n') ?: (defined('WP_HOME') ? WP_HOME : (function_exists('home_url') ? home_url() : 'unknown'));
        $salt = defined('DB_NAME') ? (string) DB_NAME : '';
        $tok = substr(md5((string) $host . $salt), 0, 10);
        return "{$base}_{$bid}_{$tok}";
    }
}

add_action('wp_default_scripts', function (WP_Scripts $scripts) {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    if (isset($scripts->registered['heartbeat'])) {
        $scripts->registered['heartbeat']->src  = plugin_dir_url(__FILE__) . 'js/heartbeat.min.js';
        $scripts->registered['heartbeat']->ver  = '1.6.5';
        $scripts->registered['heartbeat']->deps = array('jquery');
    }
});

function dfehc_get_recommended_interval_for_load(float $load, float $response_time = 0.0): float
{
    $last_key = dfehc_scoped_tkey('dfehc_last_user_activity');
    $last_activity = (int) get_transient($last_key);
    $elapsed = $last_activity > 0 ? max(0.0, (float) (time() - $last_activity)) : 0.0;

    return (float) dfehc_calculate_recommended_interval($elapsed, $load, $response_time);
}

function dfehc_enqueue_scripts(): void
{
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    wp_enqueue_script('heartbeat');

    $site_key = function_exists('dfehc_scoped_key')
        ? (string) dfehc_scoped_key('site')
        : (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $site_key = $site_key ?: (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $site_key = $site_key ?: 'site';

    $ver = defined('DFEHC_VERSION') ? (string) DFEHC_VERSION : (string) filemtime(__FILE__);

    $cache_duration_ms = (int) apply_filters('dfehc_js_cache_duration_ms', 10 * 60 * 1000);
    $cache_duration_ms = max(15000, min(60 * 60 * 1000, $cache_duration_ms));

    $cache_bypass_rate = (float) apply_filters('dfehc_js_cache_bypass_rate', 0.05);
    $cache_bypass_rate = max(0.0, min(1.0, $cache_bypass_rate));

    $leader_ttl_ms  = (int) apply_filters('dfehc_js_leader_ttl_ms', 8000);
    $leader_beat_ms = (int) apply_filters('dfehc_js_leader_beat_ms', 3000);
    $leader_ttl_ms  = max(2000, min(20000, $leader_ttl_ms));
    $leader_beat_ms = max(800, min(10000, $leader_beat_ms));

    $load = function_exists('dfehc_get_server_load') ? dfehc_get_server_load() : null;
    if ($load === false || $load === null) {
        $load = (float) DFEHC_MAX_SERVER_LOAD;
    }
    $load = (float) $load;

    $rt_seconds = 0.0;
    if (function_exists('dfehc_get_server_response_time')) {
        $rt = dfehc_get_server_response_time();

        if (is_numeric($rt)) {
            $rt_seconds = (float) $rt;
        } elseif (is_array($rt)) {
            if (isset($rt['main_response_ms']) && is_numeric($rt['main_response_ms'])) {
                $rt_seconds = ((float) $rt['main_response_ms']) / 1000.0;
            } elseif (isset($rt['response_ms']) && is_numeric($rt['response_ms'])) {
                $rt_seconds = ((float) $rt['response_ms']) / 1000.0;
            } elseif (isset($rt['response_time']) && is_numeric($rt['response_time'])) {
                $rt_seconds = (float) $rt['response_time'];
            }
        } elseif (is_object($rt)) {
            $arr = (array) $rt;
            if (isset($arr['main_response_ms']) && is_numeric($arr['main_response_ms'])) {
                $rt_seconds = ((float) $arr['main_response_ms']) / 1000.0;
            } elseif (isset($arr['response_ms']) && is_numeric($arr['response_ms'])) {
                $rt_seconds = ((float) $arr['response_ms']) / 1000.0;
            } elseif (isset($arr['response_time']) && is_numeric($arr['response_time'])) {
                $rt_seconds = (float) $arr['response_time'];
            }
        }

        $rt_seconds = max(0.0, $rt_seconds);
    }

    $recommended = 60.0;

    if (function_exists('dfehc_get_recommended_interval_for_load')) {
        $recommended = (float) dfehc_get_recommended_interval_for_load($load, (float) $rt_seconds);
    } elseif (function_exists('dfehc_calculate_recommended_interval')) {
        $last_key = function_exists('dfehc_scoped_key') ? dfehc_scoped_key('dfehc_last_user_activity') : 'dfehc_last_user_activity';
        $last = function_exists('get_transient') ? (int) get_transient($last_key) : 0;
        $elapsed = $last > 0 ? max(0.0, (float) (time() - $last)) : 0.0;

        $recommended = (float) dfehc_calculate_recommended_interval($elapsed, $load, (float) $rt_seconds);
    } elseif (function_exists('dfehc_calculate_recommended_interval_user_activity')) {
        $recommended = (float) dfehc_calculate_recommended_interval_user_activity($load, DFEHC_BATCH_SIZE);
    }

    wp_localize_script(
        'heartbeat',
        'dfehc_heartbeat_vars',
        array(
            'recommendedInterval'       => (float) $recommended,
            'heartbeat_control_enabled' => get_option('dfehc_heartbeat_control_enabled', '1'),
            'nonce'                     => wp_create_nonce(DFEHC_NONCE_ACTION),
            'ver'                       => $ver,
            'cache_bypass_rate'         => (float) $cache_bypass_rate,
        )
    );
}
add_action('wp_enqueue_scripts', 'dfehc_enqueue_scripts');

function dfehc_get_user_activity_summary(int $batch_size = DFEHC_BATCH_SIZE): array
{
    $key = dfehc_scoped_tkey('dfehc_user_activity_summary');
    $cached = get_transient($key);
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }
    $summary = dfehc_gather_user_activity_data($batch_size);
    dfehc_set_transient_noautoload($key, $summary, (int) DFEHC_USER_ACTIVITY_TTL);
    return $summary;
}

function dfehc_calculate_recommended_interval_user_activity(?float $load_average = null, int $batch_size = DFEHC_BATCH_SIZE): float
{
    if ($load_average === null) {
        $load_average = function_exists('dfehc_get_server_load') ? dfehc_get_server_load() : null;
    }
    if ($load_average === false || $load_average === null) {
        $load_average = (float) DFEHC_MAX_SERVER_LOAD;
    }
    $user_data = dfehc_get_user_activity_summary($batch_size);
    if (empty($user_data['total_weight'])) {
        return (float) DFEHC_MIN_INTERVAL;
    }
    $avg_duration = (float) $user_data['total_duration'] / max(1, (int) $user_data['total_weight']);
    return (float) dfehc_calculate_interval_based_on_duration($avg_duration, (float) $load_average);
}

function dfehc_gather_user_activity_data(int $batch_size): array
{
    $total_weighted_duration = 0.0;
    $total_weight = 0;

    $batch_size = max(1, min(75, $batch_size));
    $offset = 0;

    if (is_admin() && !(function_exists('wp_doing_ajax') && wp_doing_ajax())) {
        $allow_admin = (bool) apply_filters('dfehc_allow_user_activity_scan_in_admin', false);
        if (!$allow_admin) {
            return ['total_duration' => 0.0, 'total_weight' => 0];
        }
    }

    $start_time = function_exists('microtime') ? (float) microtime(true) : (float) time();

    $time_limit = (float) apply_filters('dfehc_activity_summary_time_limit', 0.35);
    if (!is_finite($time_limit) || $time_limit <= 0.0) {
        $time_limit = 0.35;
    }
    $time_limit = max(0.10, min(1.50, $time_limit));
    $deadline = $start_time + $time_limit;

    $max_users = (int) apply_filters('dfehc_activity_summary_max_users', 75);
    $max_users = max(1, min(75, $max_users));

    $max_points_per_user = (int) apply_filters('dfehc_activity_summary_max_points_per_user', 40);
    $max_points_per_user = max(1, min(75, $max_points_per_user));

    $processed = 0;

    while (true) {
        $now = function_exists('microtime') ? (float) microtime(true) : (float) time();
        if ($now > $deadline) {
            break;
        }
        if ($processed >= $max_users) {
            break;
        }

        $userBatch = dfehc_get_users_in_batches($batch_size, $offset);
        if (!$userBatch || !is_array($userBatch)) {
            break;
        }

        foreach ($userBatch as $user) {
            $processed++;
            if ($processed > $max_users) {
                break 2;
            }

            $uid = 0;
            if (is_object($user) && isset($user->ID)) {
                $uid = (int) $user->ID;
            } elseif (is_numeric($user)) {
                $uid = (int) $user;
            }

            if ($uid <= 0) {
                continue;
            }

            $activity = get_user_meta($uid, 'dfehc_user_activity', true);
            if (!is_array($activity) || empty($activity['durations']) || !is_array($activity['durations'])) {
                continue;
            }

            $durations = $activity['durations'];
            $cnt_all = count($durations);
            if ($cnt_all <= 0) {
                continue;
            }

            if ($cnt_all > $max_points_per_user) {
                $durations = array_slice($durations, -$max_points_per_user);
            }

            $sum = 0.0;
            $cnt = 0;

            foreach ($durations as $d) {
                if (!is_numeric($d)) {
                    continue;
                }
                $v = (float) $d;
                if (!is_finite($v) || $v < 0.0) {
                    continue;
                }
                $sum += $v;
                $cnt++;
            }

            if ($cnt <= 0) {
                continue;
            }

            $avg = $sum / $cnt;
            $total_weighted_duration += $cnt * $avg;
            $total_weight += $cnt;

            $now = function_exists('microtime') ? (float) microtime(true) : (float) time();
            if ($now > $deadline) {
                break 2;
            }
        }

        $offset += $batch_size;
    }

    return ['total_duration' => $total_weighted_duration, 'total_weight' => $total_weight];
}

function dfehc_get_recommended_heartbeat_interval_async(): float
{
    if (!class_exists('Heartbeat_Async') && file_exists(__DIR__ . '/heartbeat-async.php')) {
        require_once __DIR__ . '/heartbeat-async.php';
    }

    if (!class_exists('Heartbeat_Async')) {
        $fallback = get_transient(dfehc_scoped_tkey('dfehc_recommended_interval'));
        return $fallback !== false ? (float) $fallback : (float) DFEHC_MIN_INTERVAL;
    }

    if (!class_exists('Dfehc_Get_Recommended_Heartbeat_Interval_Async')) {
        class Dfehc_Get_Recommended_Heartbeat_Interval_Async extends Heartbeat_Async
        {
            protected $action = 'dfehc_get_recommended_interval_async';

            public function dispatch(): void
            {
                $hook = 'dfehc_get_recommended_interval_async';

                if (has_action($hook)) {
                    do_action($hook);
                    return;
                }

                if (!wp_next_scheduled($hook)) {
                    wp_schedule_single_event(time() + 1, $hook);
                }
            }

            public function run_action(): void
            {
                $lock = function_exists('dfehc_acquire_lock')
                    ? dfehc_acquire_lock('dfehc_interval_calculation_lock', (int) DFEHC_LOCK_TTL)
                    : null;

                if (!$lock && function_exists('dfehc_acquire_lock')) {
                    return;
                }

                $last_key      = dfehc_scoped_tkey('dfehc_last_user_activity');
                $ri_key        = dfehc_scoped_tkey('dfehc_recommended_interval');
                $last_activity = (int) get_transient($last_key);
                $elapsed       = max(0, time() - $last_activity);

                $load = function_exists('dfehc_get_server_load') ? dfehc_get_server_load() : null;
                if ($load === false || $load === null) {
                    $load = (float) DFEHC_MAX_SERVER_LOAD;
                }

                $interval = (float) dfehc_calculate_recommended_interval((float) $elapsed, (float) $load, 0.0);
                if ($interval <= 0) {
                    $interval = (float) DFEHC_MIN_INTERVAL;
                }

                dfehc_set_transient_noautoload($ri_key, $interval, 5 * MINUTE_IN_SECONDS);

                if ($lock && function_exists('dfehc_release_lock')) {
                    dfehc_release_lock($lock);
                }
            }
        }
    }

    $vis_key = dfehc_scoped_tkey('dfehc_previous_visitor_count');
    $ri_key  = dfehc_scoped_tkey('dfehc_recommended_interval');

    $current = function_exists('dfehc_get_website_visitors') ? (int) dfehc_get_website_visitors() : 0;
    $prev    = get_transient($vis_key);
    $ratio   = (float) apply_filters('dfehc_visitors_delta_ratio', 0.2);

    if ($prev === false || ($current > 0 && abs($current - (int) $prev) > $current * $ratio)) {
        delete_transient($ri_key);
        dfehc_set_transient_noautoload($vis_key, $current, 5 * MINUTE_IN_SECONDS);
    }

    $cached = get_transient($ri_key);
    if ($cached !== false && is_numeric($cached)) {
        return (float) $cached;
    }

    $lock = function_exists('dfehc_acquire_lock')
        ? dfehc_acquire_lock('dfehc_interval_calculation_lock', (int) DFEHC_LOCK_TTL)
        : null;

    if ($lock || !function_exists('dfehc_acquire_lock')) {
        (new Dfehc_Get_Recommended_Heartbeat_Interval_Async())->dispatch();
        if ($lock && function_exists('dfehc_release_lock')) {
            dfehc_release_lock($lock);
        }
    }

    $val = get_transient($ri_key);
    if ($val === false || !is_numeric($val)) {
        return (float) dfehc_calculate_recommended_interval_user_activity();
    }

    return (float) $val;
}

function dfehc_get_recommended_intervals(): void
{
    check_ajax_referer(DFEHC_NONCE_ACTION, 'nonce');

    $ip = '';
    if (function_exists('dfehc_client_ip')) {
        $ip = (string) dfehc_client_ip();
    } else {
        $raw = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '0.0.0.0';
        $ip = sanitize_text_field($raw);
        $ip = $ip !== '' ? $ip : '0.0.0.0';
    }

    $rlk = dfehc_scoped_tkey('dfehc_rl_' . md5($ip));
    $cnt = (int) get_transient($rlk);
    $limit = (int) apply_filters('dfehc_recommend_rl_limit', 30);
    $window = (int) apply_filters('dfehc_recommend_rl_window', 60);
    $limit = max(1, $limit);
    $window = max(1, $window);

    if ($cnt >= $limit) {
        wp_send_json_error(['message' => 'rate_limited'], 429);
    }
    dfehc_set_transient_noautoload($rlk, $cnt + 1, $window);

    $now = time();

    $snap_key = dfehc_scoped_tkey('dfehc_ri_snapshot');
    $snap_ttl = (int) apply_filters('dfehc_ri_snapshot_ttl', 15);
    $snap_ttl = max(5, min(120, $snap_ttl));

    $stale_ttl = (int) apply_filters('dfehc_ri_snapshot_stale_ttl', 90);
    $stale_ttl = max($snap_ttl, min(600, $stale_ttl));

    $snapshot = get_transient($snap_key);
    $fresh = false;

    if (is_array($snapshot) && isset($snapshot['t'], $snapshot['interval'])) {
        $age = $now - (int) $snapshot['t'];
        if ($age < 0) {
            $age = 0;
        }
        if ($age <= $snap_ttl) {
            $fresh = true;
            $interval = (float) $snapshot['interval'];
            $load = isset($snapshot['load']) && is_numeric($snapshot['load']) ? (float) $snapshot['load'] : null;

            wp_send_json_success([
                'interval' => $interval > 0 ? $interval : (float) DFEHC_MIN_INTERVAL,
                'load' => $load,
                'fresh' => 1,
                'next_poll_ms' => (int) apply_filters('dfehc_ajax_next_poll_ms_fresh', 45000),
            ]);
        }
        if ($age <= $stale_ttl) {
            $interval = (float) $snapshot['interval'];
            $load = isset($snapshot['load']) && is_numeric($snapshot['load']) ? (float) $snapshot['load'] : null;

            $lock_key = dfehc_scoped_tkey('dfehc_ri_snapshot_lock');
            $lock_ttl = (int) apply_filters('dfehc_ri_snapshot_lock_ttl', 10);
            $lock_ttl = max(3, min(30, $lock_ttl));

            $do_recompute = false;

            $global_rl_key = dfehc_scoped_tkey('dfehc_ri_global_recompute_rl');
            $global_rl_win = (int) apply_filters('dfehc_ri_global_recompute_window', 10);
            $global_rl_lim = (int) apply_filters('dfehc_ri_global_recompute_limit', 4);
            $global_rl_win = max(2, min(60, $global_rl_win));
            $global_rl_lim = max(1, min(50, $global_rl_lim));

            $global_cnt = (int) get_transient($global_rl_key);
            if ($global_cnt < $global_rl_lim) {
                $do_recompute = true;
            }

            if ($do_recompute) {
                $got_lock = false;

                if (function_exists('wp_cache_add') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
                    $got_lock = wp_cache_add($lock_key, 1, DFEHC_CACHE_GROUP, $lock_ttl);
                } else {
                    $got_lock = (get_transient($lock_key) === false) && set_transient($lock_key, 1, $lock_ttl);
                }

                if ($got_lock) {
                    dfehc_set_transient_noautoload($global_rl_key, $global_cnt + 1, $global_rl_win);

                    $interval_new = dfehc_get_recommended_heartbeat_interval_async();
                    if (!is_numeric($interval_new) || (float) $interval_new <= 0.0) {
                        $interval_new = $interval;
                    }

                    $load_new = function_exists('dfehc_get_server_load') ? dfehc_get_server_load() : null;
                    $load_new = (is_numeric($load_new) && (float) $load_new >= 0.0) ? (float) $load_new : $load;

                    $snapshot_new = [
                        't' => $now,
                        'interval' => (float) $interval_new,
                        'load' => $load_new,
                    ];

                    dfehc_set_transient_noautoload($snap_key, $snapshot_new, $stale_ttl);

                    if (function_exists('wp_cache_delete') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
                        wp_cache_delete($lock_key, DFEHC_CACHE_GROUP);
                    } else {
                        delete_transient($lock_key);
                    }

                    wp_send_json_success([
                        'interval' => (float) $interval_new,
                        'load' => $load_new,
                        'fresh' => 1,
                        'next_poll_ms' => (int) apply_filters('dfehc_ajax_next_poll_ms_fresh', 45000),
                    ]);
                }
            }

            wp_send_json_success([
                'interval' => $interval > 0 ? $interval : (float) DFEHC_MIN_INTERVAL,
                'load' => $load,
                'fresh' => 0,
                'next_poll_ms' => (int) apply_filters('dfehc_ajax_next_poll_ms_stale', 90000),
            ]);
        }
    }

    $interval = dfehc_get_recommended_heartbeat_interval_async();
    if (!is_numeric($interval) || (float) $interval <= 0.0) {
        $interval = (float) DFEHC_MIN_INTERVAL;
    }

    $load = function_exists('dfehc_get_server_load') ? dfehc_get_server_load() : null;
    $load = (is_numeric($load) && (float) $load >= 0.0) ? (float) $load : null;

    dfehc_set_transient_noautoload($snap_key, [
        't' => $now,
        'interval' => (float) $interval,
        'load' => $load,
    ], $stale_ttl);

    wp_send_json_success([
        'interval' => (float) $interval,
        'load' => $load,
        'fresh' => 1,
        'next_poll_ms' => (int) apply_filters('dfehc_ajax_next_poll_ms_fresh', 45000),
    ]);
}

add_action('wp_ajax_dfehc_update_heartbeat_interval', 'dfehc_get_recommended_intervals');
add_action('wp_ajax_nopriv_dfehc_update_heartbeat_interval', 'dfehc_get_recommended_intervals');

function dfehc_override_heartbeat_interval(array $settings): array
{
    if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
        return $settings;
    }

    if (get_option('dfehc_disable_heartbeat')) {
        $settings['interval'] = (int) DFEHC_MAX_INTERVAL;
        return $settings;
    }

    $enabled = (string) get_option('dfehc_heartbeat_control_enabled', '1');
    if ($enabled !== '1') {
        return $settings;
    }

    $interval = 0.0;

    $snap_key = dfehc_scoped_tkey('dfehc_ri_snapshot');
    $snapshot = get_transient($snap_key);
    if (is_array($snapshot) && isset($snapshot['interval']) && is_numeric($snapshot['interval'])) {
        $interval = (float) $snapshot['interval'];
    }

    if ($interval <= 0.0) {
        $interval = function_exists('dfehc_get_recommended_heartbeat_interval_async')
            ? (float) dfehc_get_recommended_heartbeat_interval_async()
            : (float) dfehc_calculate_recommended_interval_user_activity();
    }

    $interval = (int) round($interval);
    $interval = min(max($interval, (int) DFEHC_MIN_INTERVAL), (int) DFEHC_MAX_INTERVAL);

    $settings['interval'] = $interval;
    return $settings;
}
add_filter('heartbeat_settings', 'dfehc_override_heartbeat_interval', 99);


function dfehc_get_server_health_status(float $load): string
{
    if (get_option('dfehc_disable_heartbeat')) {
        return 'Stopped';
    }
    if ($load < 14.95) {
        return 'Resting';
    }
    if ($load <= 33.0) {
        return 'Pacing';
    }
    if ($load <= 67.65) {
        return 'Under Load';
    }
    return 'Under Strain';
}

function dfehc_invalidate_heartbeat_cache(): void
{
    $visitors = function_exists('dfehc_get_website_visitors') ? (int) dfehc_get_website_visitors() : 0;
    if ($visitors > 100) {
        delete_transient(dfehc_scoped_tkey('dfehc_recommended_interval'));
    }
}
add_action('wp_logout', 'dfehc_invalidate_heartbeat_cache');