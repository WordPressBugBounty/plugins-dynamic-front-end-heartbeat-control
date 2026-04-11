<?php

namespace DynamicHeartbeat\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AjaxHandler {
    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        add_action( 'wp_ajax_dfehc_optimize', [$this,'ajax'] );
    }

    public function ajax() {
        if ( ! current_user_can('manage_options') ) wp_send_json_error( 'auth', 403 );
        check_ajax_referer('dfehc_optimize_action', '_ajax_nonce');
        $fn = sanitize_text_field($_POST['optimize_function'] ?? '');
        if ( ! in_array($fn, $this->plugin->ops, true) ) wp_send_json_error( 'invalid', 400 );
        if ( ! class_exists('DynamicHeartbeat\\DfehcUncloggerDb') ) wp_send_json_error( 'missing', 500 );
        $u = new \DynamicHeartbeat\DfehcUncloggerDb();
        if ( ! method_exists($u, $fn) ) wp_send_json_error( 'method', 500 );
        $u->$fn();
        set_transient( 'dfehc_optimization_flash', 1, 60 );
        wp_send_json_success();
    }
}