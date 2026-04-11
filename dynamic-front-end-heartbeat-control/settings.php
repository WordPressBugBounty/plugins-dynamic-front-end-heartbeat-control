<?php

namespace DynamicHeartbeat;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once plugin_dir_path( __FILE__ ) . 'admin/heartbeat-config.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/ajax-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/asset-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/unclogger-menu.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/affix.php';

final class DFEHC {
    private static $i;
    public $og = 'dfehc_options_group';
    public $slug = 'dfehc_plugin';
    public $ops = [
        'delete_trashed_posts',
        'delete_revisions',
        'delete_auto_drafts',
        'delete_orphaned_postmeta',
        'delete_expired_transients',
        'delete_woocommerce_transients',
        'clear_woocommerce_cache',
        'drop_tables_with_different_prefix',
        'convert_to_innodb',
        'optimize_tables',
    ];
    public $settings;

    public static function instance() { return self::$i ?? self::$i = new self(); }

    private function __construct() {
        new Core\HeartbeatController( $this );
        new Core\AjaxHandler( $this );
        $this->settings = new Admin\Settings( $this );
        new Admin\Menu( $this );
        new Admin\AssetManager( $this );
        new NoticeManager();
        add_filter( 'pre_update_option_dfehc_optimization_frequency', [$this,'sf'], 10, 2 );
        add_filter( 'cron_schedules', [$this,'sched'] );
        add_action( 'dfehc_periodic_optimization', [$this,'cron'] );
    }

    public function sched( $s ) {
        $s['daily'] = ['interval'=>DAY_IN_SECONDS, 'display'=>__('Daily','dfehc')];
        $s['weekly'] = ['interval'=>WEEK_IN_SECONDS, 'display'=>__('Weekly','dfehc')];
        $s['biweekly'] = ['interval'=>2*WEEK_IN_SECONDS, 'display'=>__('Biweekly','dfehc')];
        $s['monthly'] = ['interval'=>30*DAY_IN_SECONDS, 'display'=>__('Monthly','dfehc')];
        return $s;
    }

    public function sf( $n, $o ) {
        if ( $n !== $o ) {
            wp_clear_scheduled_hook('dfehc_periodic_optimization');
            if ( $n && isset( wp_get_schedules()[ $n ] ) )
                wp_schedule_event( time(), $n, 'dfehc_periodic_optimization' );
        }
        return $n;
    }

    public function cron() {
        if ( class_exists(__NAMESPACE__.'\\DfehcUncloggerDb') )
            if ( method_exists($u = new DfehcUncloggerDb(),'optimize_tables') )
                $u->optimize_tables();
    }
}

class NoticeManager {
    public function __construct() {
        add_action('admin_head', [$this, 'remove_unwanted_notices']);
    }

    public function remove_unwanted_notices(): void {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $target_pages = ['settings_page_dfehc_plugin', 'toplevel_page_dfehc-unclogger'];

        if (in_array($screen->id, $target_pages, true)) {
            global $wp_filter;

            $hooks_to_clean = ['admin_notices', 'all_admin_notices'];

            foreach ($hooks_to_clean as $hook) {
                if (isset($wp_filter[$hook])) {
                    unset($wp_filter[$hook]);
                }
            }
        }
    }
}

DFEHC::instance();
