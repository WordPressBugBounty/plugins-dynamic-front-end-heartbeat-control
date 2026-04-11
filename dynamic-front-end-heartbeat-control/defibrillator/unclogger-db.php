<?php
namespace DynamicHeartbeat;

defined('ABSPATH') or die();

if (!class_exists(__NAMESPACE__ . '\DfehcUncloggerDb')) {
    class DfehcUncloggerDb
    {
        private function sanitize_identifier(string $name): string
        {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', $name);
            if ($name === '') {
                return '``';
            }
            return '`' . str_replace('`', '``', $name) . '`';
        }

        private function time_budget_exceeded(float $start, float $budget): bool
        {
            return (microtime(true) - $start) >= $budget;
        }

        public function get_database_size()
        {
            global $wpdb;
            return (float) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1)
                     FROM information_schema.tables
                     WHERE table_schema = %s
                     GROUP BY table_schema",
                    $wpdb->dbname
                )
            );
        }

        public function count_trashed_posts()
        {
            global $wpdb;
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
                    'trash'
                )
            );
        }

        public function delete_trashed_posts()
        {
            global $wpdb;
            $limit  = (int) apply_filters('dfehc_unclogger_delete_limit', 1000);
            $budget = (float) apply_filters('dfehc_unclogger_time_budget', 3.0);
            $start  = microtime(true);
            $total  = 0;
            do {
                $deleted = (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->posts} WHERE post_status = %s ORDER BY ID ASC LIMIT %d",
                        'trash',
                        $limit
                    )
                );
                $total += $deleted;
                if ($deleted < $limit) break;
            } while (!$this->time_budget_exceeded($start, $budget));
            return $total;
        }

        public function count_revisions()
        {
            global $wpdb;
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                    'revision'
                )
            );
        }

        public function delete_revisions()
        {
            global $wpdb;
            $limit  = (int) apply_filters('dfehc_unclogger_delete_limit', 1000);
            $budget = (float) apply_filters('dfehc_unclogger_time_budget', 3.0);
            $start  = microtime(true);
            $total  = 0;
            do {
                $deleted = (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT %d",
                        'revision',
                        $limit
                    )
                );
                $total += $deleted;
                if ($deleted < $limit) break;
            } while (!$this->time_budget_exceeded($start, $budget));
            return $total;
        }

        public function count_auto_drafts()
        {
            global $wpdb;
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
                    'auto-draft'
                )
            );
        }

        public function delete_auto_drafts()
        {
            global $wpdb;
            $limit  = (int) apply_filters('dfehc_unclogger_delete_limit', 1000);
            $budget = (float) apply_filters('dfehc_unclogger_time_budget', 3.0);
            $start  = microtime(true);
            $total  = 0;
            do {
                $deleted = (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->posts} WHERE post_status = %s ORDER BY ID ASC LIMIT %d",
                        'auto-draft',
                        $limit
                    )
                );
                $total += $deleted;
                if ($deleted < $limit) break;
            } while (!$this->time_budget_exceeded($start, $budget));
            return $total;
        }

        public function count_orphaned_postmeta()
        {
            global $wpdb;
            return (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.ID IS NULL"
            );
        }

        public function delete_orphaned_postmeta()
        {
            global $wpdb;
            $limit  = (int) apply_filters('dfehc_unclogger_delete_limit', 2000);
            $budget = (float) apply_filters('dfehc_unclogger_time_budget', 3.0);
            $start  = microtime(true);
            $total  = 0;
            do {
                $deleted = (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE pm
                         FROM {$wpdb->postmeta} pm
                         LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE p.ID IS NULL
                         LIMIT %d",
                        $limit
                    )
                );
                $total += $deleted;
                if ($deleted < $limit) break;
            } while (!$this->time_budget_exceeded($start, $budget));
            return $total;
        }

        public function count_woocommerce_transients()
        {
            global $wpdb;
            $like1 = $wpdb->esc_like('_transient_woocommerce_') . '%';
            $like2 = $wpdb->esc_like('_transient_timeout_woocommerce_') . '%';
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT
                        (SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s) +
                        (SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s)",
                    $like1,
                    $like2
                )
            );
        }

        public function delete_woocommerce_transients()
        {
            global $wpdb;
            $limit  = (int) apply_filters('dfehc_unclogger_delete_limit', 1000);
            $budget = (float) apply_filters('dfehc_unclogger_time_budget', 3.0);
            $start  = microtime(true);
            $like1  = $wpdb->esc_like('_transient_woocommerce_') . '%';
            $like2  = $wpdb->esc_like('_transient_timeout_woocommerce_') . '%';
            $total  = 0;
            do {
                $d1 = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d", $like1, $limit));
                $d2 = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d", $like2, $limit));
                $total += ($d1 + $d2);
                if ($d1 + $d2 < (2 * $limit)) break;
            } while (!$this->time_budget_exceeded($start, $budget));
            return $total;
        }

        public function clear_woocommerce_cache()
        {
            $cleared = false;
            if (class_exists('\WC_Cache_Helper')) {
                try { \WC_Cache_Helper::get_transient_version('product', true); $cleared = true; } catch (\Throwable $e) {}
                try { \WC_Cache_Helper::get_transient_version('shipping', true); $cleared = true; } catch (\Throwable $e) {}
                try { \WC_Cache_Helper::get_transient_version('orders', true); $cleared = true; } catch (\Throwable $e) {}
            }
            if (function_exists('\wc_delete_product_transients')) {
                try { \wc_delete_product_transients(); $cleared = true; } catch (\Throwable $e) {}
            }
            if (function_exists('\wc_delete_expired_transients')) {
                try { \wc_delete_expired_transients(); $cleared = true; } catch (\Throwable $e) {}
            }
            return (bool) $cleared;
        }

        public function count_expired_transients()
        {
            global $wpdb;
            $like = $wpdb->esc_like('_transient_timeout_') . '%';
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$wpdb->options}
                     WHERE option_name LIKE %s
                       AND CAST(option_value AS UNSIGNED) < %d",
                    $like,
                    time()
                )
            );
        }

        public function delete_expired_transients()
        {
            global $wpdb;
            $batch  = (int) apply_filters('dfehc_delete_transients_batch', 1000);
            $budget = (float) apply_filters('dfehc_unclogger_time_budget', 3.0);
            $start  = microtime(true);
            $likeTO = $wpdb->esc_like('_transient_timeout_') . '%';
            $count  = 0;

            do {
                $rows = (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT REPLACE(option_name, %s, '') AS tname
                         FROM {$wpdb->options}
                         WHERE option_name LIKE %s
                           AND CAST(option_value AS UNSIGNED) < %d
                         LIMIT %d",
                        '_transient_timeout_',
                        $likeTO,
                        time(),
                        $batch
                    )
                );
                if (!$rows) break;

                foreach ($rows as $name) {
                    $name = (string) $name;
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", "_transient_{$name}"));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", "_transient_timeout_{$name}"));
                    $count++;
                }

                if (count($rows) < $batch) break;
            } while (!$this->time_budget_exceeded($start, $budget));

            return $count;
        }

        public function count_tables_with_different_prefix()
        {
            global $wpdb;
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(TABLE_NAME)
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = %s
                       AND TABLE_NAME NOT LIKE %s",
                    $wpdb->dbname,
                    $wpdb->base_prefix . '%'
                )
            );
        }

        public function list_tables_with_different_prefix()
        {
            global $wpdb;
            $results = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT TABLE_NAME
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = %s
                       AND TABLE_NAME NOT LIKE %s",
                    $wpdb->dbname,
                    $wpdb->base_prefix . '%'
                )
            );
            return implode(', ', array_map('esc_html', $results));
        }

        public function drop_tables_with_different_prefix()
        {
            global $wpdb;

            $allowed_prefixes = (array) apply_filters('dfehc_unclogger_allowed_drop_prefixes', []);
            $allowed_prefixes = array_values(array_filter(array_map('strval', $allowed_prefixes), 'strlen'));
            $allowed_prefixes = array_values(array_filter(array_map(function ($p) {
                $p = preg_replace('/[^A-Za-z0-9_]/', '', (string) $p);
                return $p;
            }, $allowed_prefixes), 'strlen'));

            if (empty($allowed_prefixes)) {
                return 0;
            }

            $clauses = [];
            $params  = [$wpdb->dbname];

            foreach ($allowed_prefixes as $p) {
                $clauses[] = "TABLE_NAME LIKE %s";
                $params[]  = $wpdb->esc_like($p) . '%';
            }

            $query = "SELECT TABLE_NAME
                      FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = %s
                        AND (" . implode(' OR ', $clauses) . ")";

            $tables = (array) $wpdb->get_col($wpdb->prepare($query, ...$params));

            $count = 0;
            foreach ($tables as $t) {
                $t = (string) $t;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                    continue;
                }
                $safe = $this->sanitize_identifier($t);
                $wpdb->query("DROP TABLE IF EXISTS {$safe}");
                $count++;
            }
            return $count;
        }

        public function count_myisam_tables()
        {
            global $wpdb;
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = %s AND ENGINE = 'MyISAM'",
                    $wpdb->dbname
                )
            );
        }

        public function list_myisam_tables()
        {
            global $wpdb;
            $results = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = %s AND ENGINE = 'MyISAM'",
                    $wpdb->dbname
                )
            );
            return implode(', ', array_map('esc_html', $results));
        }

        public function convert_to_innodb()
        {
            global $wpdb;

            $tables = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = %s AND ENGINE = 'MyISAM' AND TABLE_NAME LIKE %s",
                    $wpdb->dbname,
                    $wpdb->base_prefix . '%'
                )
            );

            $budget = (float) apply_filters('dfehc_unclogger_schema_time_budget', 5.0);
            $start  = microtime(true);
            $count  = 0;

            foreach ($tables as $t) {
                if ($this->time_budget_exceeded($start, $budget)) break;
                $t = (string) $t;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                    continue;
                }
                $safe = $this->sanitize_identifier($t);
                $wpdb->query("ALTER TABLE {$safe} ENGINE=InnoDB");
                $count++;
            }

            return $count;
        }

        public function optimize_tables()
        {
            global $wpdb;

            $tables = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
                    $wpdb->dbname,
                    $wpdb->base_prefix . '%'
                )
            );

            $budget = (float) apply_filters('dfehc_unclogger_schema_time_budget', 5.0);
            $start  = microtime(true);
            $count  = 0;

            foreach ($tables as $t) {
                if ($this->time_budget_exceeded($start, $budget)) break;
                $t = (string) $t;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                    continue;
                }
                $safe = $this->sanitize_identifier($t);
                $wpdb->query("OPTIMIZE TABLE {$safe}");
                $count++;
            }

            return $count;
        }

        public function count_tables()
        {
            global $wpdb;
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s",
                    $wpdb->dbname
                )
            );
        }

        public function optimize_all()
        {
            $this->delete_trashed_posts();
            $this->delete_revisions();
            $this->delete_auto_drafts();
            $this->delete_orphaned_postmeta();
            $this->delete_expired_transients();
            $this->convert_to_innodb();
            $this->optimize_tables();
        }

        public function set_wp_post_revisions($value)
        {
            if (!isset($this->config)) {
                return new \WP_Error('config_missing', 'Config instance not set.');
            }
            if ($value === 'default') {
                return $this->config->remove('constant', 'WP_POST_REVISIONS');
            }
            return $this->config->update('constant', 'WP_POST_REVISIONS', $value);
        }
    }
}