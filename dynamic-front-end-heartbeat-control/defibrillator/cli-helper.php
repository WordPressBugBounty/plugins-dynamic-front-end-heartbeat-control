<?php
namespace DynamicHeartbeat;

if (!\defined('WP_CLI') || !WP_CLI) {
    return;
}

if (!\function_exists(__NAMESPACE__ . '\\dfehc_blog_id')) {
    function dfehc_blog_id(): int {
        return \function_exists('\get_current_blog_id') ? (int) \get_current_blog_id() : 0;
    }
}

if (!\function_exists(__NAMESPACE__ . '\\dfehc_scope_key')) {
    function dfehc_scope_key(string $base): string {
        if (\function_exists('\dfehc_scoped_key')) {
            return \dfehc_scoped_key($base);
        }
        $hostToken = \function_exists('\dfehc_host_token')
            ? \dfehc_host_token()
            : \substr(\md5((string) ((\php_uname('n') ?: 'unknown') . (\defined('DB_NAME') ? DB_NAME : ''))), 0, 10);
        return "{$base}_" . dfehc_blog_id() . "_{$hostToken}";
    }
}

if (\class_exists(__NAMESPACE__ . '\\dfehcUnclogger') && \class_exists('WP_CLI')) {
    class DfehcUncloggerCli extends dfehcUnclogger {
        public function __construct() {
            if (!\class_exists('\WP_CLI')) return;

            if (\class_exists(__NAMESPACE__ . '\\dfehcUncloggerDb')) {
                $this->db = new dfehcUncloggerDb();
            }
            if (\class_exists(__NAMESPACE__ . '\\dfehcUncloggerTweaks')) {
                $this->tweaks = new dfehcUncloggerTweaks();
            }

            if (isset($this->db)) {
                \WP_CLI::add_command('dfehc-unclogger', [$this, 'dfehc_unclogger_command']);
            }
        }

        public function dfehc_unclogger_command(array $args, array $assoc_args) {
            if (empty($args)) {
                \WP_CLI::line('commands:');
                \WP_CLI::line('  - wp dfehc-unclogger db optimize_all');
                return 0;
            }

            if (!isset($args[1]) || $args[0] !== 'db') {
                \WP_CLI::error('Invalid command. Usage: wp dfehc-unclogger db optimize_all');
                return 1;
            }

            if ($args[1] === 'optimize_all') {
                if (!isset($this->db)) {
                    \WP_CLI::error('Database unclogger is not available.');
                    return 1;
                }
                $before = \method_exists($this->db, 'get_database_size') ? $this->db->get_database_size() : 'unknown';
                if (\method_exists($this->db, 'optimize_all')) {
                    $this->db->optimize_all();
                }
                $after = \method_exists($this->db, 'get_database_size') ? $this->db->get_database_size() : 'unknown';
                \WP_CLI::success('Before: ' . $before . ', after: ' . $after);
                return 0;
            }

            \WP_CLI::error('Unknown db subcommand.');
            return 1;
        }
    }
    new DfehcUncloggerCli();
}

class DFEHC_CLI_Command {

    private function key_candidates(string $base): array {
        $keys = [];
        $base = (string) $base;
        if ($base !== '') {
            $keys[] = $base;
        }
        $keys[] = dfehc_scope_key($base);
        if (\function_exists('\dfehc_scoped_key')) {
            $keys[] = \dfehc_scoped_key($base);
        }
        if (\function_exists('\dfehc_key')) {
            $keys[] = \dfehc_key($base);
        }
        $keys = \array_values(\array_unique(\array_filter($keys, 'strlen')));
        return $keys;
    }

    private function delete_candidate_keys(string $base): void {
        $keys = $this->key_candidates($base);
        foreach ($keys as $k) {
            \delete_transient($k);
            if (\is_multisite()) {
                \delete_site_transient($k);
            }
        }
    }

    private function set_candidate_keys(string $base, $value, int $ttl): void {
        $keys = $this->key_candidates($base);
        foreach ($keys as $k) {
            if (\function_exists('\dfehc_set_transient_noautoload')) {
                \dfehc_set_transient_noautoload($k, $value, $ttl);
            } else {
                \set_transient($k, $value, $ttl);
            }
        }
    }

    public function recalc_interval(array $args = [], array $assoc_args = []) {
        if (!\function_exists('\dfehc_get_server_load')) {
            \WP_CLI::error('dfehc_get_server_load() is unavailable.');
            return;
        }
        $load = \dfehc_get_server_load();
        if ($load === false || $load === null) {
            \WP_CLI::error('Unable to fetch server load.');
            return;
        }

        $interval = null;
        if (\function_exists('\dfehc_calculate_recommended_interval_user_activity')) {
            $interval = (int) \dfehc_calculate_recommended_interval_user_activity((float) $load);
        } elseif (\function_exists('\dfehc_calculate_recommended_interval')) {
            $interval = (int) \dfehc_calculate_recommended_interval(60.0, (float) $load, 0.0);
        } else {
            \WP_CLI::error('No interval calculator function available.');
            return;
        }

        $baseKey = \defined('DFEHC_RECOMMENDED_INTERVAL') ? \DFEHC_RECOMMENDED_INTERVAL : 'dfehc_recommended_interval';
        $ttl = 5 * MINUTE_IN_SECONDS;

        $this->set_candidate_keys((string) $baseKey, $interval, $ttl);

        \WP_CLI::success("Heartbeat interval recalculated and cached: {$interval}s");
    }

    public function process_users(array $args = [], array $assoc_args = []) {
        if (!\function_exists('\dfehc_process_user_activity')) {
            \WP_CLI::error('dfehc_process_user_activity() is unavailable.');
            return;
        }
        \dfehc_process_user_activity();
        \WP_CLI::success('User activity queue processed.');
    }

    public function clear_cache(array $args = [], array $assoc_args = []) {
        $network = !empty($assoc_args['network']) && \is_multisite();
        $flush_object_cache = !empty($assoc_args['flush-object-cache']);
        $group = !empty($assoc_args['group']) ? (string) $assoc_args['group'] : (\defined('DFEHC_CACHE_GROUP') ? \DFEHC_CACHE_GROUP : 'dfehc');

        if (empty($assoc_args['yes'])) {
            \WP_CLI::confirm('This will clear DFEHC caches. Continue?');
        }

        $bases = [
            'dfehc_db_metrics',
            'dfehc_db_size_mb',
            'dfehc_db_size_fail',
            'dfehc_server_load',
            'dfehc_server_load_payload',
            'dfehc:server_load',
            'dfehc_recommended_interval',
            'dfehc_load_averages',
            'dfehc_ema_' . dfehc_blog_id(),
            'dfehc_prev_int_' . dfehc_blog_id(),
            'dfehc_total_visitors',
            'dfehc_regenerating_cache',
            'dfehc_system_load_avg',
        ];

        if ($network && \function_exists('\get_sites')) {
            $siteIds = \array_map('intval', (array) \get_sites(['fields' => 'ids']));
        } else {
            $siteIds = [ dfehc_blog_id() ];
        }

        foreach ($siteIds as $sid) {
            $switched = false;
            if ($network) { \switch_to_blog($sid); $switched = true; }
            try {
                foreach ($bases as $base) {
                    $this->delete_candidate_keys((string) $base);
                }
                \delete_option('dfehc_stale_total_visitors');
            } finally {
                if ($switched) { \restore_current_blog(); }
            }
        }

        if ($flush_object_cache) {
            if (\function_exists('\wp_cache_flush_group')) {
                @\wp_cache_flush_group($group);
                \WP_CLI::success("Flushed object cache group: {$group}");
            } elseif (\function_exists('\wp_cache_flush')) {
                @\wp_cache_flush();
                \WP_CLI::warning('Global object cache flushed (group-specific flush not supported).');
            } else {
                \WP_CLI::warning('Object cache flush not supported by this installation.');
            }
        }

        \WP_CLI::success('DFEHC caches cleared.');
    }

    public function enable_heartbeat(array $args = [], array $assoc_args = []) {
        \update_option('dfehc_disable_heartbeat', 0, false);
        \WP_CLI::success('Heartbeat has been enabled.');
    }

    public function disable_heartbeat(array $args = [], array $assoc_args = []) {
        \update_option('dfehc_disable_heartbeat', 1, false);
        \WP_CLI::success('Heartbeat has been disabled.');
    }

    public function status(array $args = [], array $assoc_args = []) {
        $load = \function_exists('\dfehc_get_server_load') ? \dfehc_get_server_load() : null;

        $baseKey  = \defined('DFEHC_RECOMMENDED_INTERVAL') ? \DFEHC_RECOMMENDED_INTERVAL : 'dfehc_recommended_interval';
        $interval = \get_transient(dfehc_scope_key((string) $baseKey));

        if (($interval === false || $interval === null) && \function_exists('\dfehc_scoped_key')) {
            $interval = \get_transient(\dfehc_scoped_key((string) $baseKey));
        }
        if (($interval === false || $interval === null) && \function_exists('\dfehc_key')) {
            $interval = \get_transient(\dfehc_key((string) $baseKey));
        }

        $heartbeat_enabled = !\get_option('dfehc_disable_heartbeat');

        \WP_CLI::log('Server Load: ' . ($load !== null && $load !== false ? \round((float) $load, 2) : 'N/A'));
        \WP_CLI::log('Heartbeat Interval: ' . ($interval !== false && $interval !== null ? (int) $interval . 's' : 'Not set'));
        \WP_CLI::log('Heartbeat Enabled: ' . ($heartbeat_enabled ? 'Yes' : 'No'));
    }

    public function calibrate_baseline(array $args = [], array $assoc_args = []) {
        if (!\class_exists(__NAMESPACE__ . '\\Dfehc_ServerLoadEstimator')) {
            \WP_CLI::error('Server load estimator is unavailable.');
            return;
        }
        $baseline = Dfehc_ServerLoadEstimator::calibrate_baseline();
        \WP_CLI::success("Baseline calibrated: {$baseline}");
    }

    public function load(array $args = [], array $assoc_args = []) {
        if (!\class_exists(__NAMESPACE__ . '\\Dfehc_ServerLoadEstimator')) {
            \WP_CLI::error('Server load estimator is unavailable.');
            return;
        }
        $load = Dfehc_ServerLoadEstimator::get_server_load();
        if ($load === false || $load === null) {
            \WP_CLI::error('Unable to estimate server load.');
            return;
        }

        \WP_CLI::log('Current Load: ' . \round((float) $load, 2) . '%');

        $spikeShown = false;
        $candidates = [
            dfehc_scope_key('dfehc_spike_score'),
            'dfehc_spike_score',
        ];
        foreach ($candidates as $k) {
            $v = \get_transient($k);
            if (\is_numeric($v)) {
                \WP_CLI::log('Spike Score: ' . \round((float) $v, 2));
                $spikeShown = true;
                break;
            }
        }
        if (!$spikeShown) {
            \WP_CLI::log('Spike Score: N/A');
        }
    }
}

\WP_CLI::add_command('dfehc', __NAMESPACE__ . '\\DFEHC_CLI_Command', [
    'shortdesc' => 'Dynamic Front-End Heartbeat Control utilities.',
    'synopsis'  => [],
]);