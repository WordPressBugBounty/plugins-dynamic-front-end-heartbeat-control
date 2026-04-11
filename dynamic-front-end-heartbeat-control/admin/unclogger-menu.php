<?php

namespace DynamicHeartbeat\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Menu {
    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        add_action( 'admin_menu', [$this,'menu'] );
        add_action( 'admin_menu', [$this,'submenu'] );
        add_filter( 'admin_footer_text', [$this,'ft'] );
        add_filter( 'update_footer', [$this,'fv'], 11 );
    }

    public function menu() {
        add_options_page( 'DFEHC Settings', 'DFEHC', 'manage_options', $this->plugin->slug, [$this,'page'] );
    }

    public function submenu() {
        if ( get_option('add_to_menu',0) ) add_menu_page('Unclogger','Unclogger','manage_options','dfehc-unclogger',[$this,'unclogger'],'dashicons-heart',80);
    }

    public function page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset($this->plugin->settings) && is_object($this->plugin->settings) && method_exists($this->plugin->settings, 'render_settings_page') ) {
            $this->plugin->settings->render_settings_page();
            return;
        }

        echo '<div class="wrap"><h1>'.esc_html__('DFEHC Settings','dfehc').'</h1><p>'.esc_html__("The plugin automatically detects the object-caching method enabled on your hosting environment and selects the optimal frequency settings. Below is a list of configurable options to better suit your specific use case.",'dfehc').'</p><form action="options.php" method="post">';
        settings_fields( $this->plugin->og );
        do_settings_sections( $this->plugin->slug );
        submit_button();
        echo '</form></div>';
    }

    public function unclogger() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to access this page.', 'dfehc' ) );

        $done = get_transient( 'dfehc_optimization_flash' );
        if ( $done ) {
            delete_transient( 'dfehc_optimization_flash' );
        }

        echo '<div class="wrap"><h1>' . esc_html__( 'Database Unclogger', 'dfehc' ) . '</h1>';

        if ( $done ) {
            echo '<div id="message" class="updated notice is-dismissible"><p>' . esc_html__( 'Optimization complete.', 'dfehc' ) . '</p></div>';
            echo '<h2>' . esc_html__( 'After Optimization:', 'dfehc' ) . '</h2>';
            if ( class_exists( 'DynamicHeartbeat\\DfehcUncloggerDb' ) ) {
                $this->display_unclogger_info();
            }
            echo '<br><a href="' . esc_url( admin_url( 'admin.php?page=dfehc-unclogger' ) ) . '" class="button">' . esc_html__( 'Run Another Optimization', 'dfehc' ) . '</a>';
            echo '</div>';
            return;
        }

        echo '<p>' . esc_html__( "Below are optimization options for your website's database.", 'dfehc' ) . '</p>';
        echo '<form id="dfehc-optimizer-form">';

        $labels = [
            'delete_trashed_posts'              => __( 'Delete Trashed Posts', 'dfehc' ),
            'delete_revisions'                  => __( 'Delete Revisions', 'dfehc' ),
            'delete_auto_drafts'                => __( 'Delete Auto-drafts', 'dfehc' ),
            'delete_orphaned_postmeta'          => __( 'Delete Orphaned Post Meta', 'dfehc' ),
            'delete_expired_transients'         => __( 'Delete Expired Transients', 'dfehc' ),
            'delete_woocommerce_transients'     => __( 'Delete WooCommerce Transients', 'dfehc' ),
            'clear_woocommerce_cache'           => __( 'Clear WooCommerce Cache', 'dfehc' ),
            'drop_tables_with_different_prefix' => __( 'Drop Tables with Different Prefix', 'dfehc' ),
            'convert_to_innodb'                 => __( 'Convert MyISAM Tables to InnoDB', 'dfehc' ),
            'optimize_tables'                   => __( 'Optimize Tables', 'dfehc' ),
        ];

        foreach ( $this->plugin->ops as $fn ) {
            $cls = in_array( $fn, [ 'drop_tables_with_different_prefix', 'convert_to_innodb', 'optimize_tables' ], true ) ? 'button button-primary' : 'button button-secondary';
            echo '<button class="' . esc_attr( $cls ) . '" type="submit" value="' . esc_attr( $fn ) . '">' . esc_html( $labels[ $fn ] ) . '</button> ';
            if ( $fn === 'clear_woocommerce_cache' )
                echo '<p><br><strong>' . esc_html__( 'Please ensure that you backup your website before running the optimizations below:', 'dfehc' ) . '</strong><br><br></p>';
        }

        echo '</form><br><h2>' . esc_html__( 'Current database status:', 'dfehc' ) . '</h2><br>';

        if ( function_exists( 'DynamicHeartbeat\\dfehc_get_database_health_status' ) ) {
            $h = \DynamicHeartbeat\dfehc_get_database_health_status();
            echo '<p>' . esc_html__( 'Database health:', 'dfehc' ) . ' <span class="database-health-status" style="--c:' . esc_attr( $h['status_color'] ) . ';"></span></p>';
        }

        if ( class_exists( 'DynamicHeartbeat\\DfehcUncloggerDb' ) ) {
            $this->display_unclogger_info();
        }

        echo '</div>';
    }

    private function display_unclogger_info(){
        $u = new \DynamicHeartbeat\DfehcUncloggerDb();

        $db_size = esc_html( (string) $u->get_database_size() );
        $revs    = esc_html( (string) $u->count_revisions() );
        $trash   = esc_html( (string) $u->count_trashed_posts() );
        $exp_tr  = esc_html( (string) $u->count_expired_transients() );
        $myisam  = esc_html( (string) $u->count_myisam_tables() );

        echo '<h2>' . esc_html__( 'Current Database Size:', 'dfehc' ) . ' <span>' . $db_size . '</span></h2>';
        echo '<h2>' . esc_html__( 'Number of Revisions:', 'dfehc' ) . ' <span>' . $revs . '</span></h2>';
        echo '<h2>' . esc_html__( 'Number of Trashed Posts:', 'dfehc' ) . ' <span>' . $trash . '</span></h2>';
        echo '<h2>' . esc_html__( 'Number of Expired Transients:', 'dfehc' ) . ' <span>' . $exp_tr . '</span></h2>';
        echo '<h2>' . esc_html__( 'Number of MyISAM Tables:', 'dfehc' ) . ' <span>' . $myisam . '</span></h2>';
    }

    public function ft( $t ) {
        $s = get_current_screen();
        return ( $s && in_array($s->id,['settings_page_dfehc_plugin','toplevel_page_dfehc-unclogger'],true) )
            ? '<strong>'.esc_html__('Dynamic Front-end Heartbeat Control Settings Page','dfehc').'</strong>'
            : $t;
    }

    public function fv( $v ) {
        $s = get_current_screen();
        if ( $s && in_array($s->id,['settings_page_dfehc_plugin','toplevel_page_dfehc-unclogger'],true) )
            return esc_html__('Heartbeat:','dfehc').' <strong>'.esc_html(get_option('dfehc_disable_heartbeat')?__('Disabled','dfehc'):__('Enabled','dfehc')).'</strong>';
        return $v;
    }
}