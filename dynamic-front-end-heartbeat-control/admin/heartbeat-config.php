<?php

namespace DynamicHeartbeat\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class HeartbeatController {
    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        add_action( 'init', [ $this, 'maybe_disable' ], 0 );
        add_filter( 'heartbeat_settings', [ $this, 'hb' ], 20 );
        add_filter( 'dfehc_contextual_load_value', [ $this, 'zoom' ], 10, 2 );
    }

    public function maybe_disable() {
        if ( get_option( 'dfehc_disable_heartbeat' ) ) {
            add_action( 'init', function () {
                wp_deregister_script( 'heartbeat' );
            }, 100 );
        }
    }

    public function hb( $s ) {
        $id = '';
        if ( isset( $_POST['screen_id'] ) ) {
            $id = sanitize_text_field( wp_unslash( $_POST['screen_id'] ) );
        }

        if ( $id !== '' && strpos( $id, 'post' ) !== false && get_option( 'dfhcsl_editor_heartbeat_control' ) ) {
            $i = (string) get_option( 'dfhcsl_editor_heartbeat_interval', '60' );
            if ( $i !== 'disable' ) {
                $ival = (int) $i;
                if ( $ival > 0 ) {
                    $s['interval'] = $ival;
                }
            }
        } elseif ( get_option( 'dfhcsl_backend_heartbeat_control' ) ) {
            $i = (string) get_option( 'dfhcsl_backend_heartbeat_interval', '60' );
            if ( $i !== 'disable' ) {
                $ival = (int) $i;
                if ( $ival > 0 ) {
                    $s['interval'] = $ival;
                }
            }
        }

        return $s;
    }

    public function zoom( $load, $src ) {
        if ( $src !== 'cpu_load' ) {
            return $load;
        }

        $z = get_option( 'dfehc_heartbeat_zoom', 10 );
        $z = is_numeric( $z ) ? (float) $z : 10.0;

        if ( ! is_finite( $z ) || $z <= 0.0 ) {
            $z = 10.0;
        }

        return $load * $z;
    }
}