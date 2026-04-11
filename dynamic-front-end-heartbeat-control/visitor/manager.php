<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

if (!function_exists('dfehc_blog_id')) {
    function dfehc_blog_id(): int
    {
        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
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
            $url = (string) home_url('/');
        }

        $host = '';
        if ($url !== '' && function_exists('wp_parse_url')) {
            $p = wp_parse_url($url);
            if (is_array($p) && isset($p['host']) && is_string($p['host'])) {
                $host = $p['host'];
            }
        }

        if ($host === '') {
            $host = @php_uname('n') ?: 'unknown';
        }

        $salt = defined('DB_NAME') ? (string) DB_NAME : '';
        $t = substr(md5($host . $salt), 0, 10);
        return $t;
    }
}

if (!function_exists('dfehc_scoped_key')) {
    function dfehc_scoped_key(string $base): string
    {
        return $base . '_' . dfehc_blog_id() . '_' . dfehc_host_token();
    }
}

if (!function_exists('dfehc_rand_int')) {
    function dfehc_rand_int(int $min, int $max): int
    {
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
            } catch (\Throwable $e) {
            }
        }
        return function_exists('wp_rand') ? (int) wp_rand($min, $max) : $min;
    }
}

if (!defined('DFEHC_SENTINEL_NO_LOAD')) {
    define('DFEHC_SENTINEL_NO_LOAD', 0.404);
}

if (!function_exists('dfehc_set_transient_noautoload')) {
    function dfehc_set_transient_noautoload(string $key, $value, int $expiration): void
    {
        $expiration = (int) $expiration;
        if ($expiration < 1) {
            $expiration = 1;
        }
        if (!function_exists('set_transient')) {
            return;
        }
        set_transient($key, $value, $expiration);
    }
}

if (!function_exists('dfehc_acquire_lock')) {
    function dfehc_acquire_lock(string $key, int $ttl = 60)
    {
        $ttl = max(5, (int) $ttl);
        $group = (string) apply_filters('dfehc_cache_group', defined('DFEHC_CACHE_GROUP') ? (string) DFEHC_CACHE_GROUP : 'dfehc');
        $scoped = dfehc_scoped_key($key);

        if (class_exists('WP_Lock')) {
            $lock = new WP_Lock($scoped, $ttl);
            return $lock->acquire() ? $lock : null;
        }

        if (function_exists('wp_cache_add') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            if (wp_cache_add($scoped, 1, $group, $ttl)) {
                return (object) ['cache_key' => $scoped, 'cache_group' => $group];
            }
            return null;
        }

        if (function_exists('get_transient') && false !== get_transient($scoped)) {
            return null;
        }

        if (function_exists('set_transient') && set_transient($scoped, 1, $ttl)) {
            return (object) ['transient_key' => $scoped];
        }

        return null;
    }
}

if (!function_exists('dfehc_release_lock')) {
    function dfehc_release_lock($lock): void
    {
        if ($lock instanceof WP_Lock) {
            $lock->release();
            return;
        }

        if (is_object($lock) && isset($lock->cache_key)) {
            $group = isset($lock->cache_group)
                ? (string) $lock->cache_group
                : (string) apply_filters('dfehc_cache_group', defined('DFEHC_CACHE_GROUP') ? (string) DFEHC_CACHE_GROUP : 'dfehc');

            if (function_exists('wp_cache_delete')) {
                wp_cache_delete((string) $lock->cache_key, $group);
            }
            return;
        }

        if (is_object($lock) && isset($lock->transient_key) && function_exists('delete_transient')) {
            delete_transient((string) $lock->transient_key);
        }
    }
}

if (!function_exists('dfehc_set_default_last_activity_time')) {
    function dfehc_set_default_last_activity_time(int $user_id): void
    {
        if (!function_exists('update_user_meta')) {
            return;
        }
        $meta_key = (string) apply_filters('dfehc_last_activity_meta_key', 'last_activity_time');
        update_user_meta($user_id, $meta_key, time());
    }
}
add_action('user_register', 'dfehc_set_default_last_activity_time');

if (!function_exists('dfehc_add_intervals')) {
    function dfehc_add_intervals(array $s): array
    {
        if (!isset($s['dfehc_5_minutes'])) {
            $s['dfehc_5_minutes'] = [
                'interval' => 300,
                'display'  => __('Every 5 minutes (DFEHC)', 'dfehc'),
            ];
        }
        if (!isset($s['dfehc_daily'])) {
            $s['dfehc_daily'] = [
                'interval' => defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400,
                'display'  => __('Daily (DFEHC)', 'dfehc'),
            ];
        }
        return $s;
    }
}
add_filter('cron_schedules', 'dfehc_add_intervals');

if (!function_exists('dfehc_activity_scheduled_option_key')) {
    function dfehc_activity_scheduled_option_key(): string
    {
        return dfehc_scoped_key('dfehc_activity_cron_scheduled');
    }
}

if (!function_exists('dfehc_schedule_user_activity_processing')) {
    function dfehc_schedule_user_activity_processing(): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;

        $fast_key = dfehc_scoped_key('dfehc_sched_fast_guard');
        if (function_exists('get_transient') && get_transient($fast_key) !== false) {
            return;
        }
        dfehc_set_transient_noautoload($fast_key, 1, (int) apply_filters('dfehc_schedule_fast_guard_ttl', 300));

        $lock = dfehc_acquire_lock('dfehc_cron_sched_lock', 15);
        if (!$lock) {
            return;
        }

        $aligned = time() - (time() % 300) + 300;

        try {
            $schedOpt = dfehc_activity_scheduled_option_key();

            $needs_primary = true;
            if (function_exists('get_option')) {
                $needs_primary = !get_option($schedOpt);
            }

            if ($needs_primary && function_exists('wp_next_scheduled') && !wp_next_scheduled('dfehc_process_user_activity')) {
                $ok = function_exists('wp_schedule_event') ? wp_schedule_event($aligned, 'dfehc_5_minutes', 'dfehc_process_user_activity') : false;
                if ($ok && !is_wp_error($ok)) {
                    if (function_exists('update_option')) {
                        update_option($schedOpt, 1, false);
                    }
                } else {
                    if (function_exists('wp_schedule_single_event')) {
                        wp_schedule_single_event($aligned, 'dfehc_process_user_activity');
                    }
                }
            }

            $cleanupArgs = [0, (int) apply_filters('dfehc_cleanup_batch_size', 75)];
            if (function_exists('wp_next_scheduled') && !wp_next_scheduled('dfehc_cleanup_user_activity', $cleanupArgs)) {
                $ok2 = function_exists('wp_schedule_event') ? wp_schedule_event($aligned + 300, 'dfehc_daily', 'dfehc_cleanup_user_activity', $cleanupArgs) : false;
                if (!$ok2 || is_wp_error($ok2)) {
                    if (function_exists('wp_schedule_single_event')) {
                        wp_schedule_single_event($aligned + 300, 'dfehc_cleanup_user_activity', $cleanupArgs);
                    }
                }
            }
        } finally {
            dfehc_release_lock($lock);
        }
    }
}
add_action('init', 'dfehc_schedule_user_activity_processing', 10);

if (!function_exists('dfehc_get_users_in_batches')) {
    function dfehc_get_users_in_batches(int $batch_size = 75, int $offset = 0): array
    {
        $batch_size = max(1, min(1000, $batch_size));
        $offset = max(0, $offset);

        if (!class_exists('WP_User_Query')) {
            return [];
        }

        $q = new WP_User_Query([
            'number' => $batch_size,
            'offset' => $offset,
            'fields' => 'ID',
        ]);

        $res = $q->get_results();
        return is_array($res) ? $res : [];
    }
}

if (!function_exists('dfehc_throttled_user_activity_handler')) {
    function dfehc_throttled_user_activity_handler(): void
    {
        $lock = dfehc_acquire_lock('dfehc_recent_user_processing', 300);
        if (!$lock) {
            return;
        }

        $prev = function_exists('ignore_user_abort') ? (bool) ignore_user_abort(true) : false;
        try {
            dfehc_process_user_activity();
        } finally {
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort((bool) $prev);
            }
            dfehc_release_lock($lock);
        }
    }
}
add_action('dfehc_process_user_activity', 'dfehc_throttled_user_activity_handler');

if (!function_exists('dfehc_process_user_activity')) {
    function dfehc_process_user_activity(): void
    {
        static $memo_done = null;
        static $memo_flag_opt = null;
        static $memo_last_id_opt = null;

        $flag_opt = $memo_flag_opt ?? ($memo_flag_opt = dfehc_scoped_key('dfehc_activity_backfill_done'));
        if (function_exists('get_option')) {
            if ($memo_done === true) {
                return;
            }
            $done = (bool) get_option($flag_opt);
            if ($done) {
                $memo_done = true;
                return;
            }
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->users)) {
            return;
        }

        $meta_key = (string) apply_filters('dfehc_last_activity_meta_key', 'last_activity_time');
        $batch = (int) apply_filters('dfehc_activity_processing_batch_size', 75);
        $batch = max(1, min(500, $batch));

        $last_id_opt = $memo_last_id_opt ?? ($memo_last_id_opt = dfehc_scoped_key('dfehc_activity_last_id'));
        $last_id = function_exists('get_option') ? (int) get_option($last_id_opt, 0) : 0;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
            $last_id,
            $batch
        ));

        if (!$ids) {
            if (function_exists('update_option')) {
                update_option($flag_opt, 1, false);
                $memo_done = true;
                delete_option($last_id_opt);
                update_option(dfehc_scoped_key('dfehc_last_activity_cron'), time(), false);
            }
            return;
        }

        $now = time();
        $written = 0;
        $max_writes = (int) apply_filters('dfehc_activity_max_writes_per_run', 500);
        $max_writes = max(1, min(2000, $max_writes));

        foreach ($ids as $id) {
            $id = (int) $id;
            $has = function_exists('get_user_meta') ? get_user_meta($id, $meta_key, true) : null;
            if (!$has) {
                if (function_exists('update_user_meta')) {
                    update_user_meta($id, $meta_key, $now);
                    $written++;
                    if ($written >= $max_writes) {
                        break;
                    }
                }
            }
        }

        $last = end($ids);
        if (function_exists('update_option')) {
            update_option($last_id_opt, $last ? (int) $last : (int) $last_id, false);
            update_option(dfehc_scoped_key('dfehc_last_activity_cron'), time(), false);
        }
    }
}

if (!function_exists('dfehc_record_user_activity')) {
    function dfehc_record_user_activity(): void
    {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return;
        }

        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($uid <= 0) {
            return;
        }

        static $cache = [];
        $meta_key = (string) apply_filters('dfehc_last_activity_meta_key', 'last_activity_time');

        $now = time();
        $interval = (int) apply_filters('dfehc_activity_update_interval', 900);
        $interval = max(60, $interval);

        $last = isset($cache[$uid]) ? (int) $cache[$uid] : (int) (function_exists('get_user_meta') ? get_user_meta($uid, $meta_key, true) : 0);

        if ($last <= 0) {
            if (function_exists('update_user_meta')) {
                update_user_meta($uid, $meta_key, $now);
            }
            $cache[$uid] = $now;
            return;
        }

        $delta = $now - $last;
        if ($delta < 0) {
            $delta = 0;
        }

        if ($delta >= $interval) {
            if (function_exists('update_user_meta')) {
                update_user_meta($uid, $meta_key, $now);
            }
            $cache[$uid] = $now;

            $k = 'dfehc_user_activity';
            $activity = function_exists('get_user_meta') ? get_user_meta($uid, $k, true) : null;
            if (!is_array($activity)) {
                $activity = [];
            }
            $dur = isset($activity['durations']) && is_array($activity['durations']) ? $activity['durations'] : [];

            $delta_f = (float) $delta;
            if (is_finite($delta_f) && $delta_f >= 0.0) {
                $dur[] = $delta_f;
            }

            $max_points = (int) apply_filters('dfehc_activity_max_points_per_user', 40);
            $max_points = max(5, min(150, $max_points));
            if (count($dur) > $max_points) {
                $dur = array_slice($dur, -$max_points);
            }

            $activity['durations'] = $dur;
            $activity['t'] = $now;

            if (function_exists('update_user_meta')) {
                update_user_meta($uid, $k, $activity);
            }
        }
    }
}
add_action('wp', 'dfehc_record_user_activity', 10);

if (!function_exists('dfehc_cleanup_user_activity')) {
    function dfehc_cleanup_user_activity(int $last_id = 0, int $batch_size = 75): void
    {
        $lock = dfehc_acquire_lock('dfehc_cleanup_lock', 600);
        if (!$lock) {
            return;
        }

        $prev = function_exists('ignore_user_abort') ? (bool) ignore_user_abort(true) : false;
        try {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->users)) {
                return;
            }

            $meta_key = (string) apply_filters('dfehc_last_activity_meta_key', 'last_activity_time');
            $batch_size = (int) apply_filters('dfehc_cleanup_batch_size', $batch_size);
            $batch_size = max(1, min(500, $batch_size));

            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
                (int) $last_id,
                (int) $batch_size
            ));

            if (!$ids) {
                if (function_exists('update_option')) {
                    update_option(dfehc_scoped_key('dfehc_last_cleanup_cron'), time(), false);
                }
                return;
            }

            $cutoff = time() - (int) apply_filters('dfehc_activity_expiration', defined('WEEK_IN_SECONDS') ? (int) WEEK_IN_SECONDS : 604800);

            foreach ($ids as $id) {
                $id = (int) $id;
                $ts = function_exists('get_user_meta') ? (int) get_user_meta($id, $meta_key, true) : 0;
                if ($ts && $ts < $cutoff) {
                    if (function_exists('delete_user_meta')) {
                        delete_user_meta($id, $meta_key);
                    }
                }
            }

            if (count($ids) === $batch_size && function_exists('wp_schedule_single_event')) {
                $delay = 15 + dfehc_rand_int(0, 5);
                wp_schedule_single_event(time() + $delay, 'dfehc_cleanup_user_activity', [(int) end($ids), (int) $batch_size]);
            }

            if (function_exists('update_option')) {
                update_option(dfehc_scoped_key('dfehc_last_cleanup_cron'), time(), false);
            }
        } finally {
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort((bool) $prev);
            }
            dfehc_release_lock($lock);
        }
    }
}
add_action('dfehc_cleanup_user_activity', 'dfehc_cleanup_user_activity', 10, 2);

if (!function_exists('dfehc_total_visitors_storage_key')) {
    function dfehc_total_visitors_storage_key(): string
    {
        return dfehc_scoped_key('dfehc_total_visitors');
    }
}

if (!function_exists('dfehc_increment_total_visitors')) {
    function dfehc_increment_total_visitors(): void
    {
        $key = dfehc_total_visitors_storage_key();
        $grp = (string) apply_filters('dfehc_cache_group', defined('DFEHC_CACHE_GROUP') ? (string) DFEHC_CACHE_GROUP : 'dfehc');
        $ttl = (int) apply_filters('dfehc_total_visitors_ttl', defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600);
        $ttl = max(60, $ttl + dfehc_rand_int(0, 5));

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_incr')) {
            if (function_exists('wp_cache_add')) {
                wp_cache_add($key, 0, $grp, $ttl);
            }
            $val = wp_cache_incr($key, 1, $grp);
            if ($val === false && function_exists('wp_cache_set')) {
                wp_cache_set($key, 1, $grp, $ttl);
            }
            return;
        }

        $cur = function_exists('get_transient') ? get_transient($key) : false;
        $cur = is_numeric($cur) ? (int) $cur : 0;
        $cur++;
        dfehc_set_transient_noautoload($key, $cur, $ttl);
    }
}

if (!function_exists('dfehc_safe_cache_get_raw')) {
    function dfehc_safe_cache_get_raw(string $rawKey): int
    {
        $grp = (string) apply_filters('dfehc_cache_group', defined('DFEHC_CACHE_GROUP') ? (string) DFEHC_CACHE_GROUP : 'dfehc');
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_get')) {
            $v = wp_cache_get($rawKey, $grp);
            return is_numeric($v) ? (int) $v : 0;
        }
        $v = function_exists('get_transient') ? get_transient($rawKey) : false;
        return is_numeric($v) ? (int) $v : 0;
    }
}

if (!function_exists('dfehc_safe_cache_delete_raw')) {
    function dfehc_safe_cache_delete_raw(string $rawKey): void
    {
        $grp = (string) apply_filters('dfehc_cache_group', defined('DFEHC_CACHE_GROUP') ? (string) DFEHC_CACHE_GROUP : 'dfehc');
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_delete')) {
            wp_cache_delete($rawKey, $grp);
        }
        if (function_exists('delete_transient')) {
            delete_transient($rawKey);
        }
    }
}

if (!function_exists('dfehc_get_website_visitors')) {
    function dfehc_get_website_visitors(): int
    {
        static $request_memo = null;
        if ($request_memo !== null) {
            return (int) $request_memo;
        }

        $cache_key = dfehc_scoped_key('dfehc_total_visitors_cache');
        $regen_key = dfehc_scoped_key('dfehc_regenerating_cache');
        $stale_opt = dfehc_scoped_key('dfehc_stale_total_visitors');

        $cache = function_exists('get_transient') ? get_transient($cache_key) : false;
        if ($cache !== false && is_numeric($cache)) {
            $request_memo = (int) $cache;
            return (int) $request_memo;
        }

        if (function_exists('get_transient') && get_transient($regen_key)) {
            $stale = function_exists('get_option') ? get_option($stale_opt, 0) : 0;
            $request_memo = is_numeric($stale) ? (int) $stale : 0;
            return (int) $request_memo;
        }

        $regen_ttl = (int) (defined('MINUTE_IN_SECONDS') ? (int) MINUTE_IN_SECONDS : 60) + dfehc_rand_int(0, 5);
        dfehc_set_transient_noautoload($regen_key, 1, $regen_ttl);

        $rawKey = dfehc_total_visitors_storage_key();
        $total = dfehc_safe_cache_get_raw($rawKey);

        $ttl = (int) apply_filters('dfehc_visitors_cache_ttl', 10 * (defined('MINUTE_IN_SECONDS') ? (int) MINUTE_IN_SECONDS : 60));
        $ttl = max(30, $ttl + dfehc_rand_int(0, 5));

        dfehc_set_transient_noautoload($cache_key, (int) $total, $ttl);
        if (function_exists('update_option')) {
            update_option($stale_opt, (int) $total, false);
        }
        if (function_exists('delete_transient')) {
            delete_transient($regen_key);
        }

        $request_memo = (int) apply_filters('dfehc_get_website_visitors_result', (int) $total);
        return (int) $request_memo;
    }
}

if (!function_exists('dfehc_reset_total_visitors')) {
    function dfehc_reset_total_visitors(): void
    {
        $start = microtime(true);
        $lock = dfehc_acquire_lock('dfehc_resetting_visitors', 60);
        if (!$lock) {
            return;
        }

        try {
            $threshold = (float) apply_filters('dfehc_reset_load_threshold', 15.0);
            $load = function_exists('dfehc_get_server_load') ? dfehc_get_server_load() : (defined('DFEHC_SENTINEL_NO_LOAD') ? (float) DFEHC_SENTINEL_NO_LOAD : 0.404);
            if (!is_numeric($load)) {
                return;
            }

            $load = (float) $load;
            if ($load === (defined('DFEHC_SENTINEL_NO_LOAD') ? (float) DFEHC_SENTINEL_NO_LOAD : 0.404) || $load >= $threshold) {
                return;
            }

            $rawKey = dfehc_total_visitors_storage_key();
            dfehc_safe_cache_delete_raw($rawKey);

            if (function_exists('delete_transient')) {
                delete_transient(dfehc_scoped_key('dfehc_total_visitors_cache'));
                delete_transient(dfehc_scoped_key('dfehc_regenerating_cache'));
            }
            if (function_exists('delete_option')) {
                delete_option(dfehc_scoped_key('dfehc_stale_total_visitors'));
            }

            if ((microtime(true) - $start) > 5.0) {
                return;
            }
        } finally {
            dfehc_release_lock($lock);
        }
    }
}
add_action('dfehc_reset_total_visitors_event', 'dfehc_reset_total_visitors');

if (!function_exists('dfehc_on_activate')) {
    function dfehc_on_activate(): void
    {
        $aligned = time() - (time() % 300) + 300;

        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event') && !wp_next_scheduled('dfehc_reset_total_visitors_event')) {
            $ts = $aligned + (defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600);
            $ok = wp_schedule_event($ts, 'hourly', 'dfehc_reset_total_visitors_event');
            if (!$ok || is_wp_error($ok)) {
                if (function_exists('wp_schedule_single_event')) {
                    wp_schedule_single_event($ts, 'dfehc_reset_total_visitors_event');
                }
            }
        }

        dfehc_process_user_activity();
        dfehc_schedule_user_activity_processing();
    }
}

if (!function_exists('dfehc_ensure_reset_visitors_schedule')) {
    function dfehc_ensure_reset_visitors_schedule(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (wp_next_scheduled('dfehc_reset_total_visitors_event')) {
            return;
        }

        $aligned = time() - (time() % 300) + 300;
        $ts = $aligned + (defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600);

        $ok = wp_schedule_event($ts, 'hourly', 'dfehc_reset_total_visitors_event');
        if (!$ok || is_wp_error($ok)) {
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event($ts, 'dfehc_reset_total_visitors_event');
            }
        }
    }
}
add_action('init', 'dfehc_ensure_reset_visitors_schedule', 10);

if (!function_exists('dfehc_unschedule_all')) {
    function dfehc_unschedule_all(string $hook, array $args = []): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
            return;
        }
        while ($ts = wp_next_scheduled($hook, $args)) {
            wp_unschedule_event($ts, $hook, $args);
        }
    }
}

if (!function_exists('dfehc_on_deactivate')) {
    function dfehc_on_deactivate(): void
    {
        dfehc_unschedule_all('dfehc_process_user_activity');
        dfehc_unschedule_all('dfehc_reset_total_visitors_event');
        $cleanupArgs = [0, (int) apply_filters('dfehc_cleanup_batch_size', 75)];
        dfehc_unschedule_all('dfehc_cleanup_user_activity', $cleanupArgs);

        if (function_exists('delete_option')) {
            delete_option(dfehc_activity_scheduled_option_key());
            delete_option(dfehc_scoped_key('dfehc_activity_backfill_done'));
            delete_option(dfehc_scoped_key('dfehc_activity_last_id'));
        }
    }
}

if (function_exists('register_activation_hook')) {
    $plugin_file = defined('DFEHC_PLUGIN_FILE') ? (string) DFEHC_PLUGIN_FILE : __FILE__;
    register_activation_hook($plugin_file, 'dfehc_on_activate');
}

if (function_exists('register_deactivation_hook')) {
    $plugin_file = defined('DFEHC_PLUGIN_FILE') ? (string) DFEHC_PLUGIN_FILE : __FILE__;
    register_deactivation_hook($plugin_file, 'dfehc_on_deactivate');
}

if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
    \WP_CLI::add_command('dfehc:reset_visitors', static function () {
        dfehc_reset_total_visitors();
        \WP_CLI::success('Visitor count reset triggered.');
    });
}