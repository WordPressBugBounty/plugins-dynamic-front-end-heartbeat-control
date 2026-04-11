<?php
namespace DynamicHeartbeat;

defined('ABSPATH') or die();

require_once plugin_dir_path(__FILE__) . 'unclogger-db.php';
require_once plugin_dir_path(__FILE__) . 'db-health.php';

class DfehcUnclogger
{
    protected $db;
    protected $config;

    protected $default_settings = [
        'auto_cleanup'        => false,
        'post_revision_limit' => 20,
    ];

    protected array $allowed_tools = [
        'delete_trashed_posts',
        'delete_revisions',
        'delete_auto_drafts',
        'delete_orphaned_postmeta',
        'delete_expired_transients',
        'count_expired_transients',
        'convert_to_innodb',
        'optimize_tables',
        'optimize_all',
        'clear_woocommerce_cache',
    ];

    public function __construct()
    {
        if (defined('WP_CLI') && WP_CLI) {
            $base = rtrim(self::get_plugin_path(), '/\\');
            $cli  = $base . '/cli-helper.php';
            if (!is_file($cli)) {
                $cli = $base . '/defibrillator/cli-helper.php';
            }
            if (is_file($cli)) {
                require_once $cli;
            }
        }

        if (class_exists(__NAMESPACE__ . '\\DfehcUncloggerDb')) {
            $this->db = new DfehcUncloggerDb();
        } elseif (class_exists('\\DynamicHeartbeat\\DfehcUncloggerDb')) {
            $this->db = new \DynamicHeartbeat\DfehcUncloggerDb();
        } else {
            $this->db = null;
        }

        $this->set_default_settings();

        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public static function get_plugin_path()
    {
        return plugin_dir_path(__FILE__);
    }

    protected function client_ip_for_key(): string
    {
        $raw = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $raw = trim((string) $raw);
        return filter_var($raw, FILTER_VALIDATE_IP) ? $raw : '0.0.0.0';
    }

    public function set_default_settings()
    {
        if (!get_option('dfehc_unclogger_settings')) {
            update_option('dfehc_unclogger_settings', $this->default_settings, true);
        }
    }

    public function get_settings()
    {
        $settings = get_option('dfehc_unclogger_settings', $this->default_settings);
        return [
            'success' => true,
            'data' => $settings,
        ];
    }

    public function set_setting($req)
    {
        $data = $req->get_json_params();
        if (!is_array($data)) {
            return new \WP_Error('invalid_body', 'Invalid JSON payload.');
        }

        $setting = sanitize_text_field($data['setting'] ?? '');
        $value   = $data['value'] ?? null;

        $known = ['auto_cleanup', 'post_revision_limit'];
        if (!in_array($setting, $known, true)) {
            return new \WP_Error('invalid_setting', 'Unknown setting key.');
        }

        if ($setting === 'auto_cleanup') {
            $value = (bool) $value;
        } elseif ($setting === 'post_revision_limit') {
            $value = max(0, (int) $value);
        }

        $settings = get_option('dfehc_unclogger_settings', $this->default_settings);
        $settings[$setting] = $value;
        update_option('dfehc_unclogger_settings', $settings, true);

        return [
            'success' => true,
            'data' => $settings,
        ];
    }

    protected function get_max_load(string $context = 'interactive'): float
    {
        $interactive = (float) apply_filters('dfehc_optimize_max_load', 45.0);
        $background  = (float) apply_filters('dfehc_optimize_max_load_background', 5.0);
        return $context === 'background' ? $background : $interactive;
    }

    public function optimize_db($req)
    {
        if (!$this->db) {
            return new \WP_Error('db_unavailable', 'Database tools are not available in this environment.');
        }

        $tool = sanitize_key($req->get_param('tool'));

        $uid = (int) get_current_user_id();
        $ip = $this->client_ip_for_key();

        $rl_key   = 'dfehc_optimize_rl_' . ($uid ? 'u' . $uid : 'ip' . md5($ip));
        $rl_limit = (int) apply_filters('dfehc_optimize_rl_limit', 10);
        $rl_win   = (int) apply_filters('dfehc_optimize_rl_window', 60);
        $rl_cnt   = (int) get_transient($rl_key);

        if ($rl_cnt >= $rl_limit) {
            return new \WP_Error('rate_limited', 'Too many requests. Please try again shortly.', ['status' => 429]);
        }
        set_transient($rl_key, $rl_cnt + 1, $rl_win);

        if (get_transient('dfehc_optimizing')) {
            return new \WP_Error('already_running', 'Optimization is already in progress.');
        }

        if (!$tool || !in_array($tool, $this->allowed_tools, true) || !method_exists($this->db, $tool)) {
            return new \WP_Error('invalid_tool', 'No valid optimization tool specified.');
        }

        $load = function_exists('dfehc_get_server_load') ? (float) dfehc_get_server_load() : 0.0;
        if ($load > $this->get_max_load('interactive')) {
            return new \WP_Error('server_busy', 'Server load is too high to run optimization safely.');
        }

        set_transient('dfehc_optimizing', 1, 300);

        if ($tool === 'optimize_all') {
            wp_schedule_single_event(time() + 10, 'dfehc_async_optimize_all');
            delete_transient('dfehc_optimizing');
            return [
                'success' => true,
                'data' => ['scheduled' => true, 'tool' => $tool],
            ];
        }

        try {
            $result = call_user_func([$this, 'guarded_run_tool'], $tool);
        } catch (\Throwable $e) {
            delete_transient('dfehc_optimizing');
            return new \WP_Error('optimize_failed', 'Optimization failed: ' . $e->getMessage());
        }

        delete_transient('dfehc_optimizing');

        return [
            'success' => true,
            'data' => [
                'result'   => $result,
                'tool'     => $tool,
                'settings' => get_option('dfehc_unclogger_settings', $this->default_settings),
            ],
        ];
    }

    protected function guarded_run_tool(string $tool)
    {
        if (!$this->db || !method_exists($this->db, $tool)) {
            throw new \RuntimeException('Unknown tool: ' . $tool);
        }
        if (in_array($tool, ['convert_to_innodb', 'optimize_tables', 'optimize_all'], true)) {
            $load = function_exists('dfehc_get_server_load') ? (float) dfehc_get_server_load() : 0.0;
            if ($load > $this->get_max_load('interactive')) {
                throw new \RuntimeException('Server load spiked during operation.');
            }
        }
        return call_user_func([$this->db, $tool]);
    }

    public function register_rest_routes()
    {
        register_rest_route('dfehc-unclogger/v1', '/get/', [
            'methods'             => \WP_REST_Server::READABLE,
            'permission_callback' => __NAMESPACE__ . '\\dfehc_permission_check',
            'callback'            => [$this, 'get_settings'],
        ]);

        register_rest_route('dfehc-unclogger/v1', '/optimize-db/(?P<tool>[^/]+)', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'permission_callback' => __NAMESPACE__ . '\\dfehc_permission_check',
            'callback'            => [$this, 'optimize_db'],
            'args'                => [
                'tool' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        register_rest_route('dfehc-unclogger/v1', '/set/', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'permission_callback' => __NAMESPACE__ . '\\dfehc_permission_check',
            'callback'            => [$this, 'set_setting'],
        ]);
    }

    public function __call($method, $args)
    {
        if ($this->db && method_exists($this->db, $method)) {
            return call_user_func_array([$this->db, $method], $args);
        }
        throw new \BadMethodCallException("Method {$method} does not exist.");
    }
}

function dfehc_permission_check()
{
    return (bool) apply_filters('dfehc_unclogger_permission_check', current_user_can('manage_options'));
}

function dfehc_async_optimize_all()
{
    if (get_transient('dfehc_optimizing')) {
        return;
    }

    $load = function_exists('dfehc_get_server_load') ? (float) dfehc_get_server_load() : 0.0;
    $max  = (float) apply_filters('dfehc_optimize_max_load_background', 5.0);
    if ($load > $max) {
        return;
    }

    set_transient('dfehc_optimizing', 1, 300);

    $prev = ignore_user_abort(true);

    try {
        $db = class_exists(__NAMESPACE__ . '\\DfehcUncloggerDb') ? new DfehcUncloggerDb() : (class_exists('\\DynamicHeartbeat\\DfehcUncloggerDb') ? new \DynamicHeartbeat\DfehcUncloggerDb() : null);
        if ($db && method_exists($db, 'optimize_all')) {
            $db->optimize_all();
        }
    } finally {
        ignore_user_abort($prev);
        delete_transient('dfehc_optimizing');
    }
}

add_action('dfehc_async_optimize_all', __NAMESPACE__ . '\\dfehc_async_optimize_all');

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('dfehc optimize', function () {
        if (get_transient('dfehc_optimizing')) {
            \WP_CLI::error('Optimization already in progress.');
        }
        $load = function_exists('dfehc_get_server_load') ? (float) dfehc_get_server_load() : 0.0;
        $max  = (float) apply_filters('dfehc_optimize_max_load_background', 5.0);
        if ($load > $max) {
            \WP_CLI::error('Server load too high to proceed.');
        }
        set_transient('dfehc_optimizing', 1, 300);
        $prev = ignore_user_abort(true);
        try {
            $db = class_exists(__NAMESPACE__ . '\\DfehcUncloggerDb') ? new DfehcUncloggerDb() : (class_exists('\\DynamicHeartbeat\\DfehcUncloggerDb') ? new \DynamicHeartbeat\DfehcUncloggerDb() : null);
            if (!$db || !method_exists($db, 'optimize_all')) {
                \WP_CLI::error('Database tools are not available in this environment.');
            }
            $db->optimize_all();
            \WP_CLI::success('Database optimized successfully.');
        } finally {
            ignore_user_abort($prev);
            delete_transient('dfehc_optimizing');
        }
    });
}

$dfehc_unclogger = new \DynamicHeartbeat\DfehcUnclogger();