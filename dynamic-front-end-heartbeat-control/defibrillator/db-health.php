<?php
declare(strict_types=1);

namespace DynamicHeartbeat;

if (!\function_exists(__NAMESPACE__ . '\\dfehc_blog_id')) {
    function dfehc_blog_id(): int {
        return \function_exists('\get_current_blog_id') ? (int) \get_current_blog_id() : 0;
    }
}

if (!\function_exists(__NAMESPACE__ . '\\dfehc_host_token')) {
    function dfehc_host_token(): string {
        static $t = '';
        if ($t !== '') {
            return $t;
        }
        $host = @\php_uname('n')
            ?: (\defined('WP_HOME')
                ? WP_HOME
                : (\function_exists('\home_url') ? \home_url() : 'unknown'));
        $salt = \defined('DB_NAME') ? (string) DB_NAME : '';
        return $t = \substr(\md5((string) $host . $salt), 0, 10);
    }
}

if (!\function_exists(__NAMESPACE__ . '\\dfehc_scoped_key')) {
    function dfehc_scoped_key(string $base): string {
        return $base . '_' . dfehc_blog_id() . '_' . dfehc_host_token();
    }
}

if (!\function_exists(__NAMESPACE__ . '\\dfehc_cache_group')) {
    function dfehc_cache_group(): string {
        return \defined('DFEHC_CACHE_GROUP') ? (string) \DFEHC_CACHE_GROUP : 'dfehc';
    }
}

if (!\function_exists(__NAMESPACE__ . '\\dfehc_cache_get')) {
    function dfehc_cache_get(string $key) {
        if (\function_exists('\wp_using_ext_object_cache') && \wp_using_ext_object_cache()) {
            return \wp_cache_get($key, dfehc_cache_group());
        }
        return \get_site_transient($key);
    }
}

if (!\function_exists(__NAMESPACE__ . '\\dfehc_cache_set')) {
    function dfehc_cache_set(string $key, $value, int $ttl): void {
        $jitter = 0;
        if (\function_exists('\random_int')) {
            try { $jitter = \random_int(0, 5); } catch (\Throwable $e) { $jitter = 0; }
        }
        $ttl = \max(1, $ttl + $jitter);

        if (\function_exists('\wp_using_ext_object_cache') && \wp_using_ext_object_cache()) {
            \wp_cache_set($key, $value, dfehc_cache_group(), $ttl);
            return;
        }
        \set_site_transient($key, $value, $ttl);
    }
}

function dfehc_gather_database_metrics(bool $force = false): array {
    static $cached = null;
    $persist_ttl = (int) \apply_filters('dfehc_db_metrics_ttl', 600);
    $metrics_key = dfehc_scoped_key('dfehc_db_metrics');

    if (!$force) {
        $persisted = dfehc_cache_get($metrics_key);
        if (\is_array($persisted)) {
            $cached = $persisted;
            return $persisted;
        }
    } elseif ($cached !== null) {
        return $cached;
    }

    $toggles = \wp_parse_args(\apply_filters('dfehc_db_metrics_toggles', []), [
        'revisions'          => true,
        'trashed_posts'      => true,
        'expired_transients' => true,
        'orders'             => true,
        'products'           => true,
        'pages'              => true,
        'customers'          => true,
        'users'              => true,
        'db_size'            => true,
        'disk'               => true,
    ]);

    $budget_ms = (int) \apply_filters('dfehc_db_metrics_budget_ms', 250);
    $budget_ms = $budget_ms > 0 ? $budget_ms : 250;
    $t0 = \microtime(true);
    $within_budget = static function () use ($t0, $budget_ms): bool {
        return ((\microtime(true) - $t0) * 1000) < $budget_ms;
    };

    $sources = [];
    $metrics = [
        'revision_count'           => 0,
        'trashed_posts_count'      => 0,
        'expired_transients_count' => 0,
        'order_count'              => 0,
        'product_count'            => 0,
        'page_count'               => 0,
        'customer_count'           => 0,
        'user_count'               => 0,
        'db_size_mb'               => 0.0,
        'disk_free_space_mb'       => 0.0,
        'collected_at'             => \current_time('mysql'),
        'cache_backend'            => (\function_exists('\wp_using_ext_object_cache') && \wp_using_ext_object_cache()) ? 'object' : 'transient',
    ];

    $unclogger = null;
    if (\class_exists(__NAMESPACE__ . '\\DfehcUncloggerDb')) {
        $unclogger = new DfehcUncloggerDb();
    } elseif (\class_exists('\\DfehcUncloggerDb')) {
        $unclogger = new \DfehcUncloggerDb();
    }

    if ($toggles['revisions'] && $within_budget()) {
        $metrics['revision_count'] = $unclogger ? (int) $unclogger->count_revisions() : 0;
        $sources['revision_count'] = $unclogger ? 'unclogger' : 'none';
    } else {
        $sources['revision_count'] = 'skipped';
    }

    if ($toggles['trashed_posts'] && $within_budget()) {
        $metrics['trashed_posts_count'] = $unclogger ? (int) $unclogger->count_trashed_posts() : 0;
        $sources['trashed_posts_count'] = $unclogger ? 'unclogger' : 'none';
    } else {
        $sources['trashed_posts_count'] = 'skipped';
    }

    if ($toggles['expired_transients'] && $within_budget()) {
        $metrics['expired_transients_count'] = $unclogger ? (int) $unclogger->count_expired_transients() : 0;
        $sources['expired_transients_count'] = $unclogger ? 'unclogger' : 'none';
    } else {
        $sources['expired_transients_count'] = 'skipped';
    }

    if ($toggles['orders'] && $within_budget() && \function_exists('\post_type_exists') && \post_type_exists('shop_order')) {
        $o = \wp_count_posts('shop_order');
        $metrics['order_count'] = (int) ((\is_object($o) ? (array) $o : [])['publish'] ?? 0);
        $sources['order_count'] = 'wp_count_posts';
    } else {
        $sources['order_count'] = $toggles['orders'] ? 'skipped' : 'disabled';
    }

    if ($toggles['products'] && $within_budget() && \function_exists('\post_type_exists') && \post_type_exists('product')) {
        $p = \wp_count_posts('product');
        $metrics['product_count'] = (int) ((\is_object($p) ? (array) $p : [])['publish'] ?? 0);
        $sources['product_count'] = 'wp_count_posts';
    } else {
        $sources['product_count'] = $toggles['products'] ? 'skipped' : 'disabled';
    }

    if ($toggles['pages'] && $within_budget() && \function_exists('\post_type_exists') && \post_type_exists('page')) {
        $pg = \wp_count_posts('page');
        $metrics['page_count'] = (int) ((\is_object($pg) ? (array) $pg : [])['publish'] ?? 0);
        $sources['page_count'] = 'wp_count_posts';
    } else {
        $sources['page_count'] = $toggles['pages'] ? 'skipped' : 'disabled';
    }

    if ($toggles['users'] && $within_budget()) {
        $cu = \function_exists('\count_users') ? \count_users() : null;
        $metrics['user_count'] = \is_array($cu) && isset($cu['total_users']) ? (int) $cu['total_users'] : 0;
        $sources['user_count'] = $cu ? 'count_users' : 'none';
    } else {
        $sources['user_count'] = $toggles['users'] ? 'skipped' : 'disabled';
    }

    if ($toggles['customers'] && $within_budget()) {
        $cu = \function_exists('\count_users') ? \count_users() : null;
        $avail = \is_array($cu) && isset($cu['avail_roles']) ? (array) $cu['avail_roles'] : [];
        $metrics['customer_count'] = (int) ($avail['customer'] ?? 0);
        $sources['customer_count'] = $cu ? 'count_users' : 'none';
    } else {
        $sources['customer_count'] = $toggles['customers'] ? 'skipped' : 'disabled';
    }

    $db_size_mb = null;
    if ($toggles['db_size'] && $within_budget() && $unclogger && \method_exists($unclogger, 'get_database_size')) {
        $db_size_key = dfehc_scoped_key('dfehc_db_size_mb');
        $cached_db = dfehc_cache_get($db_size_key);

        if (\is_numeric($cached_db) && (float) $cached_db > 0) {
            $db_size_mb = (float) $cached_db;
            $sources['db_size_mb'] = 'cache';
        } else {
            $size = $unclogger->get_database_size();
            if (\is_numeric($size)) {
                $size = (float) $size;
                if ($size > 0 && $size <= (float) \apply_filters('dfehc_db_size_upper_bound_mb', 10 * 1024 * 1024)) {
                    $db_size_mb = $size;
                    dfehc_cache_set($db_size_key, (float) $db_size_mb, 6 * HOUR_IN_SECONDS);
                    $sources['db_size_mb'] = 'unclogger';
                }
            }
        }
    } else {
        $sources['db_size_mb'] = $toggles['db_size'] ? ($unclogger ? 'skipped' : 'unavailable') : 'disabled';
    }

    if ($db_size_mb === null) {
        $multipliers = \apply_filters('dfehc_db_size_multipliers', [
            'user'      => 0.03,
            'order'     => 0.05,
            'revision'  => 0.05,
            'trash'     => 0.05,
            'page'      => 0.10,
            'transient' => 0.01,
            'default'   => 500.0,
        ]);
        $estimate = 0.0;
        $estimate += $metrics['user_count']               * (float) ($multipliers['user'] ?? 0.03);
        $estimate += $metrics['order_count']              * (float) ($multipliers['order'] ?? 0.05);
        $estimate += $metrics['revision_count']           * (float) ($multipliers['revision'] ?? 0.05);
        $estimate += $metrics['trashed_posts_count']      * (float) ($multipliers['trash'] ?? 0.05);
        $estimate += $metrics['page_count']               * (float) ($multipliers['page'] ?? 0.10);
        $estimate += $metrics['expired_transients_count'] * (float) ($multipliers['transient'] ?? 0.01);
        if ($estimate <= 0) {
            $estimate = (float) ($multipliers['default'] ?? 500.0);
        }
        $db_size_mb = \round($estimate, 2);
        $sources['db_size_mb'] = 'estimated';
    }

    $metrics['db_size_mb'] = (float) $db_size_mb;

    if ($toggles['disk'] && $within_budget()) {
        $disk_paths = \apply_filters('dfehc_disk_paths', (function (): array {
            $paths = [];
            if (\defined('WP_CONTENT_DIR')) { $paths[] = WP_CONTENT_DIR; }
            if (\function_exists('\wp_get_upload_dir')) {
                $u = \wp_get_upload_dir();
                if (\is_array($u) && !empty($u['basedir'])) {
                    $paths[] = (string) $u['basedir'];
                }
            }
            if (\defined('ABSPATH')) { $paths[] = ABSPATH; }
            return $paths;
        })());

        $disk_free_space_mb = null;
        foreach ($disk_paths as $p) {
            if ($p && \is_dir($p) && \is_readable($p)) {
                $space = @\disk_free_space($p);
                if ($space !== false) {
                    $disk_free_space_mb = \round($space / 1024 / 1024, 2);
                    $sources['disk_free_space_mb'] = 'disk_free_space';
                    break;
                }
            }
        }
        if ($disk_free_space_mb === null) {
            $fallback = (float) \apply_filters(
                'dfehc_disk_free_default_mb',
                \round((50 * 1024 * 1024 * 1024) / 1024 / 1024, 2)
            );
            $disk_free_space_mb = $fallback;
            $sources['disk_free_space_mb'] = 'default';
        }
        $metrics['disk_free_space_mb'] = (float) $disk_free_space_mb;
    } else {
        $metrics['disk_free_space_mb'] = (float) \apply_filters(
            'dfehc_disk_free_default_mb',
            \round((50 * 1024 * 1024 * 1024) / 1024 / 1024, 2)
        );
        $sources['disk_free_space_mb'] = $toggles['disk'] ? 'skipped' : 'disabled';
    }

    $metrics['sources'] = $sources;

    $cached = $metrics;
    dfehc_cache_set($metrics_key, $metrics, $persist_ttl);

    return $metrics;
}

function dfehc_evaluate_database_health(array $metrics): array {
    $severity = 'ok';
    $conditions_met = 0;

    $thresholds = [
        'revision'     => 1000,
        'trash'        => 1000,
        'transients'   => 5000,
        'db_size_mb'   => 500,
        'disk_free_mb' => 10240,
    ];

    if (((int) ($metrics['order_count'] ?? 0)) > 10000) {
        $thresholds = \array_merge($thresholds, [
            'revision'   => 5000,
            'trash'      => 5000,
            'transients' => 20000,
            'db_size_mb' => 3000,
        ]);
    }

    if (((int) ($metrics['product_count'] ?? 0)) > 3000) {
        $thresholds = \array_merge($thresholds, [
            'revision'   => 6000,
            'trash'      => 6000,
            'transients' => 30000,
            'db_size_mb' => 3000,
        ]);
    }

    if (((int) ($metrics['user_count'] ?? 0)) > 10000) {
        $thresholds = \array_merge($thresholds, [
            'revision'   => 3000,
            'trash'      => 3000,
            'transients' => 10000,
            'db_size_mb' => 3000,
        ]);
    }

    $thresholds = \apply_filters('dfehc_database_health_thresholds', $thresholds, $metrics);

    if (((int) ($metrics['revision_count'] ?? 0)) > (int) ($thresholds['revision'] ?? 0))   $conditions_met++;
    if (((int) ($metrics['trashed_posts_count'] ?? 0)) > (int) ($thresholds['trash'] ?? 0)) $conditions_met++;
    if (((int) ($metrics['expired_transients_count'] ?? 0)) > (int) ($thresholds['transients'] ?? 0)) $conditions_met++;
    if (((float) ($metrics['db_size_mb'] ?? 0)) > (float) ($thresholds['db_size_mb'] ?? 0)) $conditions_met++;
    if (((float) ($metrics['disk_free_space_mb'] ?? \INF)) < (float) ($thresholds['disk_free_mb'] ?? 0)) $conditions_met++;

    if ($conditions_met >= 3) {
        $severity = 'critical';
    } elseif ($conditions_met === 2) {
        $severity = 'warn';
    }

    $color_map = \apply_filters('dfehc_database_health_colors', [
        'ok'       => '#1a7f37',
        'warn'     => '#9a6700',
        'critical' => '#d1242f',
    ]);

    return [
        'severity'        => $severity,
        'status_color'    => $color_map[$severity] ?? '#1a7f37',
        'conditions_met'  => $conditions_met,
        'thresholds'      => $thresholds,
        'metrics'         => $metrics,
    ];
}

function dfehc_get_database_health_status(): array {
    $metrics = dfehc_gather_database_metrics();
    return dfehc_evaluate_database_health($metrics);
}

function check_database_health_on_admin_page(): void {
    if (!\is_admin()) return;
    if (!\current_user_can('manage_options')) return;
    $page = isset($_GET['page']) ? \sanitize_text_field(\wp_unslash((string) $_GET['page'])) : '';
    if ($page !== 'dfehc_plugin') return;
    dfehc_get_database_health_status();
}
\add_action('admin_init', __NAMESPACE__ . '\\check_database_health_on_admin_page');