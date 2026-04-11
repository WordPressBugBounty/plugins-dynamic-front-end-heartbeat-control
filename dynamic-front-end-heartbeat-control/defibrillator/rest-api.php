<?php
namespace DynamicHeartbeat;

if (!\defined('ABSPATH')) {
    exit;
}

class DfehcUncloggerRestApi
{
    public function __construct()
    {
        \add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        \register_rest_route(
            'dfehc-unclogger/v1',
            '/woocommerce-transients/count',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'count_woocommerce_transients'],
                'permission_callback' => [$this, 'permission_check'],
                'args'                => [
                    'network' => [
                        'type'        => 'boolean',
                        'required'    => false,
                        'description' => 'When true on multisite, count across sites (optionally filtered by "sites").',
                    ],
                    'sites' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'required'    => false,
                        'description' => 'Optional list of site IDs to include when network=true. Also accepts comma-separated string.',
                    ],
                ],
            ]
        );

        \register_rest_route(
            'dfehc-unclogger/v1',
            '/woocommerce-transients/delete',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'delete_woocommerce_transients'],
                'permission_callback' => [$this, 'permission_check'],
                'args'                => [
                    'network' => [
                        'type'        => 'boolean',
                        'required'    => false,
                        'description' => 'Run across all sites (multisite only, super admins only).',
                    ],
                    'sites' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'required'    => false,
                        'description' => 'Optional list of site IDs to include when network=true. Also accepts comma-separated string.',
                    ],
                    'allow_sql' => [
                        'type'        => 'boolean',
                        'required'    => false,
                        'description' => 'Opt-in to SQL fallback (still requires dfehc_unclogger_allow_sql_delete filter to allow).',
                    ],
                    'dry_run' => [
                        'type'        => 'boolean',
                        'required'    => false,
                        'description' => 'If true, estimates what would be deleted without performing deletion.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'required'    => false,
                        'description' => 'Per-chunk DELETE limit for SQL fallback.',
                    ],
                    'time_budget' => [
                        'type'        => 'number',
                        'required'    => false,
                        'description' => 'Maximum seconds to spend in SQL fallback loop.',
                    ],
                ],
            ]
        );

        \register_rest_route(
            'dfehc-unclogger/v1',
            '/woocommerce-cache/clear',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'clear_woocommerce_cache'],
                'permission_callback' => [$this, 'permission_check'],
                'args'                => [
                    'network' => [
                        'type'        => 'boolean',
                        'required'    => false,
                        'description' => 'Run across all sites (multisite only, super admins only).',
                    ],
                    'sites' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'required'    => false,
                        'description' => 'Optional list of site IDs to include when network=true. Also accepts comma-separated string.',
                    ],
                    'allow_sql' => [
                        'type'        => 'boolean',
                        'required'    => false,
                        'description' => 'Opt-in to SQL fallback (still requires dfehc_unclogger_allow_sql_delete filter to allow).',
                    ],
                    'dry_run' => [
                        'type'        => 'boolean',
                        'required'    => false,
                        'description' => 'If true, estimates what would be cleared without performing deletion.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'required'    => false,
                        'description' => 'Per-chunk DELETE limit for SQL fallback.',
                    ],
                    'time_budget' => [
                        'type'        => 'number',
                        'required'    => false,
                        'description' => 'Maximum seconds to spend in SQL fallback loop.',
                    ],
                ],
            ]
        );
    }

    public function permission_check(\WP_REST_Request $request)
    {
        $allowed = (bool) \apply_filters('dfehc_unclogger_allow_rest', true, $request);
        if (!$allowed) {
            return new \WP_Error('dfehc_rest_disabled', 'DFEHC REST endpoints are disabled.', ['status' => 403]);
        }

        $required_caps = (array) \apply_filters('dfehc_unclogger_required_capabilities', ['manage_options', 'manage_woocommerce'], $request);
        $can_manage = false;
        foreach ($required_caps as $cap) {
            if (\current_user_can($cap)) {
                $can_manage = true;
                break;
            }
        }
        if (!$can_manage) {
            return new \WP_Error('dfehc_forbidden', 'You are not allowed to access this endpoint.', ['status' => 403]);
        }

        $network = (bool) $this->get_bool_param($request, 'network');
        if ($network) {
            if (!\is_multisite() || !\is_super_admin()) {
                return new \WP_Error('dfehc_network_forbidden', 'Network-wide action requires super admin on multisite.', ['status' => 403]);
            }
        }

        if ((bool) \apply_filters('dfehc_unclogger_rest_rate_limit_enable', true, $request)) {
            $limit   = (int) \apply_filters('dfehc_unclogger_rest_rate_limit', 60, $request);
            $window  = (int) \apply_filters('dfehc_unclogger_rest_rate_window', 60, $request);
            $user_id = (int) \get_current_user_id();

            $remote_raw = isset($_SERVER['REMOTE_ADDR']) ? (string) \wp_unslash($_SERVER['REMOTE_ADDR']) : '';
            $remote_raw = \trim($remote_raw);
            $ip = ($remote_raw !== '' && \filter_var($remote_raw, \FILTER_VALIDATE_IP)) ? $remote_raw : '0.0.0.0';

            $key = $this->scoped_key('dfehc_unclogger_rl_' . ($user_id ? 'u' . $user_id : 'ip' . \md5($ip)));
            $cnt = (int) \get_transient($key);
            if ($cnt >= $limit) {
                return new \WP_Error('dfehc_rate_limited', 'Rate limited.', ['status' => 429]);
            }
            \set_transient($key, $cnt + 1, $window);
        }

        return true;
    }

    public function count_woocommerce_transients(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!$this->has_woocommerce()) {
            return \rest_ensure_response([
                'available'      => false,
                'reason'         => 'woocommerce_not_active',
                'can_estimate'   => null,
                'count'          => null,
            ]);
        }

        if (\wp_using_ext_object_cache()) {
            return \rest_ensure_response([
                'available'      => true,
                'reason'         => 'persistent_object_cache',
                'can_estimate'   => false,
                'count'          => null,
            ]);
        }

        $network = (bool) $this->get_bool_param($request, 'network');
        $sites   = $this->parse_sites_param($request);

        if ($network && \is_multisite()) {
            $ids = $sites ?: \get_sites(['fields' => 'ids']);
            $total = 0;
            foreach ($ids as $blog_id) {
                $switched = false;
                \switch_to_blog((int) $blog_id);
                $switched = true;
                try {
                    $total += $this->count_wc_transients_for_site();
                } finally {
                    if ($switched) { \restore_current_blog(); }
                }
            }
            return \rest_ensure_response([
                'available'      => true,
                'reason'         => 'ok',
                'can_estimate'   => true,
                'count'          => (int) $total,
                'network'        => true,
                'sites_counted'  => \count($ids),
            ]);
        }

        $count = $this->count_wc_transients_for_site();
        return \rest_ensure_response([
            'available'      => true,
            'reason'         => 'ok',
            'can_estimate'   => true,
            'count'          => (int) $count,
            'network'        => false,
        ]);
    }

    public function delete_woocommerce_transients(\WP_REST_Request $request): \WP_REST_Response
    {
        $network = (bool) $this->get_bool_param($request, 'network');
        $sites   = $this->parse_sites_param($request);

        $allow_sql_req = (bool) $this->get_bool_param($request, 'allow_sql');
        $allow_sql_filter = (bool) \apply_filters('dfehc_unclogger_allow_sql_delete', false, $request);
        $allow_sql = $allow_sql_req && $allow_sql_filter;

        $dry_run = (bool) $this->get_bool_param($request, 'dry_run');
        $limit_default = (int) \apply_filters('dfehc_unclogger_sql_delete_limit', 500);
        $time_default  = (float) \apply_filters('dfehc_unclogger_sql_delete_time_budget', 2.5);
        $limit = (int) ($request->get_param('limit') ?? $limit_default);
        if ($limit <= 0) { $limit = $limit_default; }
        $time_budget = (float) ($request->get_param('time_budget') ?? $time_default);
        if ($time_budget <= 0) { $time_budget = $time_default; }

        $results = $this->run_per_site($network, $sites, function (int $site_id) use ($allow_sql, $limit, $time_budget, $dry_run): array {
            return $this->delete_wc_transients_for_site($allow_sql, $limit, $time_budget, $dry_run);
        });

        return \rest_ensure_response($results + [
            'network'     => (bool) $network,
            'action'      => 'delete_transients',
            'allow_sql'   => (bool) $allow_sql,
            'dry_run'     => (bool) $dry_run,
            'limit'       => (int) $limit,
            'time_budget' => (float) $time_budget,
        ]);
    }

    public function clear_woocommerce_cache(\WP_REST_Request $request): \WP_REST_Response
    {
        $network = (bool) $this->get_bool_param($request, 'network');
        $sites   = $this->parse_sites_param($request);

        $allow_sql_req = (bool) $this->get_bool_param($request, 'allow_sql');
        $allow_sql_filter = (bool) \apply_filters('dfehc_unclogger_allow_sql_delete', false, $request);
        $allow_sql = $allow_sql_req && $allow_sql_filter;

        $dry_run = (bool) $this->get_bool_param($request, 'dry_run');
        $limit_default = (int) \apply_filters('dfehc_unclogger_sql_delete_limit', 500);
        $time_default  = (float) \apply_filters('dfehc_unclogger_sql_delete_time_budget', 2.5);
        $limit = (int) ($request->get_param('limit') ?? $limit_default);
        if ($limit <= 0) { $limit = $limit_default; }
        $time_budget = (float) ($request->get_param('time_budget') ?? $time_default);
        if ($time_budget <= 0) { $time_budget = $time_default; }

        $results = $this->run_per_site($network, $sites, function (int $site_id) use ($allow_sql, $limit, $time_budget, $dry_run): array {
            return $this->clear_wc_cache_for_site($allow_sql, $limit, $time_budget, $dry_run);
        });

        return \rest_ensure_response($results + [
            'network'     => (bool) $network,
            'action'      => 'clear_cache',
            'allow_sql'   => (bool) $allow_sql,
            'dry_run'     => (bool) $dry_run,
            'limit'       => (int) $limit,
            'time_budget' => (float) $time_budget,
        ]);
    }

    protected function has_woocommerce(): bool
    {
        return \class_exists('\WC_Cache_Helper') || \function_exists('\wc');
    }

    protected function run_per_site(bool $network, array $sites, callable $work): array
    {
        $sites_processed = 0;
        $cleared_via_all = [];
        $fallback_used = false;
        $deleted_transients_total = 0;
        $deleted_timeouts_total   = 0;
        $errors_all = [];

        if ($network && \is_multisite()) {
            $ids = $sites ?: \get_sites(['fields' => 'ids']);
            foreach ($ids as $blog_id) {
                $switched = false;
                \switch_to_blog((int) $blog_id);
                $switched = true;
                try {
                    $res = (array) $work((int) $blog_id);
                    $sites_processed++;
                    $cleared_via_all = \array_merge($cleared_via_all, (array) ($res['cleared_via'] ?? []));
                    $fallback_used = $fallback_used || !empty($res['fallback_used']);
                    $deleted_transients_total += (int) ($res['deleted_transients'] ?? 0);
                    $deleted_timeouts_total   += (int) ($res['deleted_timeouts'] ?? 0);
                    if (!empty($res['errors']) && \is_array($res['errors'])) {
                        $errors_all = \array_merge($errors_all, $res['errors']);
                    }
                } finally {
                    if ($switched) { \restore_current_blog(); }
                }
            }
        } else {
            $res = (array) $work(\get_current_blog_id() ? (int) \get_current_blog_id() : 0);
            $sites_processed = 1;
            $cleared_via_all = \array_merge($cleared_via_all, (array) ($res['cleared_via'] ?? []));
            $fallback_used = !empty($res['fallback_used']);
            $deleted_transients_total += (int) ($res['deleted_transients'] ?? 0);
            $deleted_timeouts_total   += (int) ($res['deleted_timeouts'] ?? 0);
            if (!empty($res['errors']) && \is_array($res['errors'])) {
                $errors_all = \array_merge($errors_all, $res['errors']);
            }
        }

        return [
            'sites_processed'        => (int) $sites_processed,
            'cleared_via'            => \array_values(\array_unique(\array_filter($cleared_via_all, 'strlen'))),
            'fallback_used'          => (bool) $fallback_used,
            'deleted_transients'     => (int) $deleted_transients_total,
            'deleted_timeouts'       => (int) $deleted_timeouts_total,
            'errors'                 => \array_values(\array_unique(\array_filter($errors_all, 'strlen'))),
        ];
    }

    protected function delete_wc_transients_for_site(bool $allow_sql, int $limit, float $time_budget, bool $dry_run): array
    {
        if (!$this->has_woocommerce()) {
            return ['cleared_via' => ['no_woocommerce'], 'fallback_used' => false, 'deleted_transients' => 0, 'deleted_timeouts' => 0, 'errors' => []];
        }

        $cleared_via = [];
        $errors = [];
        $deleted_transients = 0;
        $deleted_timeouts   = 0;
        $used_fallback = false;

        if (\function_exists('\wc_delete_product_transients')) {
            if ($dry_run) {
                $cleared_via[] = 'wc_delete_product_transients(dry_run)';
            } else {
                try { \wc_delete_product_transients(); $cleared_via[] = 'wc_delete_product_transients'; } catch (\Throwable $e) { $errors[] = 'wc_delete_product_transients: ' . $e->getMessage(); }
            }
        }

        if (\function_exists('\wc_delete_expired_transients')) {
            if ($dry_run) {
                $cleared_via[] = 'wc_delete_expired_transients(dry_run)';
            } else {
                try { \wc_delete_expired_transients(); $cleared_via[] = 'wc_delete_expired_transients'; } catch (\Throwable $e) { $errors[] = 'wc_delete_expired_transients: ' . $e->getMessage(); }
            }
        }

        if (\class_exists('\WC_Cache_Helper')) {
            foreach (['product','shipping','orders'] as $group) {
                if ($dry_run) {
                    $cleared_via[] = "bump:{$group}(dry_run)";
                } else {
                    try { \WC_Cache_Helper::get_transient_version($group, true); $cleared_via[] = "bump:{$group}"; } catch (\Throwable $e) { $errors[] = "bump:{$group}: " . $e->getMessage(); }
                }
            }
        }

        if (!$cleared_via || $allow_sql) {
            if ($allow_sql && !$dry_run) {
                $used_fallback = true;
                $res = $this->chunked_sql_delete($limit, $time_budget, false);
                $deleted_transients += (int) $res['deleted_transients'];
                $deleted_timeouts   += (int) $res['deleted_timeouts'];
                $cleared_via[] = 'sql_chunk_delete';
            } elseif ($allow_sql && $dry_run) {
                $used_fallback = true;
                $res = $this->chunked_sql_delete($limit, $time_budget, true);
                $deleted_transients += (int) $res['deleted_transients'];
                $deleted_timeouts   += (int) $res['deleted_timeouts'];
                $cleared_via[] = 'sql_chunk_delete(dry_run)';
            } elseif (!$cleared_via) {
                $cleared_via[] = 'noop';
            }
        }

        return [
            'cleared_via'        => \array_values(\array_unique($cleared_via)),
            'fallback_used'      => (bool) $used_fallback,
            'deleted_transients' => (int) $deleted_transients,
            'deleted_timeouts'   => (int) $deleted_timeouts,
            'errors'             => \array_values(\array_unique($errors)),
        ];
    }

    protected function clear_wc_cache_for_site(bool $allow_sql, int $limit, float $time_budget, bool $dry_run): array
    {
        if (!$this->has_woocommerce()) {
            return ['cleared_via' => ['no_woocommerce'], 'fallback_used' => false, 'deleted_transients' => 0, 'deleted_timeouts' => 0, 'errors' => []];
        }

        $cleared_via = [];
        $errors = [];
        $deleted_transients = 0;
        $deleted_timeouts   = 0;
        $used_fallback = false;

        if (\class_exists('\WC_Cache_Helper')) {
            foreach (['product','shipping','orders'] as $group) {
                if ($dry_run) {
                    $cleared_via[] = "bump:{$group}(dry_run)";
                } else {
                    try { \WC_Cache_Helper::get_transient_version($group, true); $cleared_via[] = "bump:{$group}"; } catch (\Throwable $e) { $errors[] = "bump:{$group}: " . $e->getMessage(); }
                }
            }
        }

        if (\function_exists('\wc_delete_product_transients')) {
            if ($dry_run) {
                $cleared_via[] = 'wc_delete_product_transients(dry_run)';
            } else {
                try { \wc_delete_product_transients(); $cleared_via[] = 'wc_delete_product_transients'; } catch (\Throwable $e) { $errors[] = 'wc_delete_product_transients: ' . $e->getMessage(); }
            }
        }

        if (\function_exists('\wc_delete_expired_transients')) {
            if ($dry_run) {
                $cleared_via[] = 'wc_delete_expired_transients(dry_run)';
            } else {
                try { \wc_delete_expired_transients(); $cleared_via[] = 'wc_delete_expired_transients'; } catch (\Throwable $e) { $errors[] = 'wc_delete_expired_transients: ' . $e->getMessage(); }
            }
        }

        if ($allow_sql) {
            if ($dry_run) {
                $used_fallback = true;
                $res = $this->chunked_sql_delete($limit, $time_budget, true);
                $deleted_transients += (int) $res['deleted_transients'];
                $deleted_timeouts   += (int) $res['deleted_timeouts'];
                $cleared_via[] = 'sql_chunk_delete(dry_run)';
            } else {
                $used_fallback = true;
                $res = $this->chunked_sql_delete($limit, $time_budget, false);
                $deleted_transients += (int) $res['deleted_transients'];
                $deleted_timeouts   += (int) $res['deleted_timeouts'];
                $cleared_via[] = 'sql_chunk_delete';
            }
        }

        if (!$cleared_via) {
            $cleared_via[] = 'noop';
        }

        return [
            'cleared_via'        => \array_values(\array_unique($cleared_via)),
            'fallback_used'      => (bool) $used_fallback,
            'deleted_transients' => (int) $deleted_transients,
            'deleted_timeouts'   => (int) $deleted_timeouts,
            'errors'             => \array_values(\array_unique($errors)),
        ];
    }

    protected function chunked_sql_delete(int $limit, float $time_budget, bool $dry_run): array
    {
        $deleted_transients = 0;
        $deleted_timeouts   = 0;

        if (\wp_using_ext_object_cache()) {
            return ['deleted_transients' => 0, 'deleted_timeouts' => 0];
        }

        global $wpdb;

        $limit = max(1, (int) $limit);
        $time_budget = max(0.1, (float) $time_budget);

        $like_to = $wpdb->esc_like('_transient_timeout_woocommerce_') . '%';
        $like    = $wpdb->esc_like('_transient_woocommerce_') . '%';

        if ($dry_run) {
            $timeouts = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like_to
            ));
            $values = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ));
            return ['deleted_transients' => $values, 'deleted_timeouts' => $timeouts];
        }

        $start = \microtime(true);

        do {
            $count_to = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
                    $like_to,
                    $limit
                )
            );
            $deleted_timeouts += $count_to;

            $count_val = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
                    $like,
                    $limit
                )
            );
            $deleted_transients += $count_val;

            $elapsed = \microtime(true) - $start;
            $more = ($count_to + $count_val) >= (2 * $limit);
        } while ($more && $elapsed < $time_budget);

        return ['deleted_transients' => $deleted_transients, 'deleted_timeouts' => $deleted_timeouts];
    }

    protected function count_wc_transients_for_site(): int
    {
        global $wpdb;
        $like = $wpdb->esc_like('_transient_woocommerce_') . '%';
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );
        return $count;
    }

    protected function parse_sites_param(\WP_REST_Request $request): array
    {
        $sites = $request->get_param('sites');
        if (is_array($sites)) {
            return array_values(array_filter(array_map('intval', $sites), fn($n) => $n > 0));
        }
        if (is_string($sites) && $sites !== '') {
            $parts = array_map('trim', explode(',', $sites));
            return array_values(array_filter(array_map('intval', $parts), fn($n) => $n > 0));
        }
        return [];
    }

    protected function get_bool_param(\WP_REST_Request $request, string $name): bool
    {
        $v = $request->get_param($name);
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int) $v) !== 0;
        if (is_string($v)) {
            $l = strtolower($v);
            return in_array($l, ['1','true','yes','on'], true);
        }
        return false;
    }

    protected function scoped_key(string $base): string
    {
        if (\function_exists('\dfehc_scoped_key')) {
            return \dfehc_scoped_key($base);
        }
        $bid = \function_exists('\get_current_blog_id') ? (string) \get_current_blog_id() : '0';
        $host = @\php_uname('n') ?: (\defined('WP_HOME') ? WP_HOME : (\function_exists('\home_url') ? \home_url() : 'unknown'));
        $salt = \defined('DB_NAME') ? (string) DB_NAME : '';
        $tok  = \substr(\md5((string) $host . $salt), 0, 10);
        return "{$base}_{$bid}_{$tok}";
    }
}

new DfehcUncloggerRestApi();