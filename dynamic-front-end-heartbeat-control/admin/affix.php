<?php

namespace DynamicHeartbeat\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Settings {
    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        add_action( 'admin_init', [$this,'reg'] );
    }

    private function get_priority_weights( int $slider_value ): array {
        $userActivityWeight = 0.4;
        $serverLoadWeight = 0.3;
        $responseTimeWeight = 0.3;

        $userActivityWeight += (0.1 * $slider_value);
        $serverLoadWeight   -= (0.1 * $slider_value / 2);
        $responseTimeWeight -= (0.1 * $slider_value / 2);

        return [
            'user'     => $userActivityWeight,
            'server'   => $serverLoadWeight,
            'response' => $responseTimeWeight,
        ];
    }

    private function s_bool( $v ) { return empty($v) ? 0 : 1; }
    private function s_int( $v ) { return is_numeric($v) ? (int) $v : 0; }
    private function s_float( $v ) { return is_numeric($v) ? (float) $v : 0.0; }

    private function s_text( $v ) {
        return sanitize_text_field( is_string($v) ? $v : '' );
    }

    private function s_cap( $v ) {
        $v = sanitize_key( is_string($v) ? $v : '' );
        return $v !== '' ? $v : 'read';
    }

    private function s_lines( $v ) {
        $raw = is_string($v) ? $v : '';
        $raw = sanitize_textarea_field( $raw );
        $lines = preg_split("/\r\n|\r|\n/", $raw);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') $out[] = $line;
        }
        return implode("\n", $out);
    }

    private function s_json( $v ) {
        $raw = is_string($v) ? $v : '';
        $raw = trim( wp_unslash( $raw ) );
        if ($raw === '') return '';
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) return '';
        return wp_json_encode($decoded);
    }

    private function s_opt_freq( $v ) {
        $v = sanitize_key( is_string($v) ? $v : '' );
        $allowed = ['','daily','weekly','biweekly','monthly'];
        return in_array($v, $allowed, true) ? $v : '';
    }

    private function f_text( string $key, string $placeholder = '', $default = '' ): void {
        $val = get_option($key, $default);
        echo '<input type="text" class="regular-text" name="'.esc_attr($key).'" value="'.esc_attr((string)$val).'" placeholder="'.esc_attr($placeholder).'" />';
        echo '<p class="description"><code>'.esc_html($key).'</code></p>';
    }

    private function f_number( string $key, string $step = '1', string $min = '', string $max = '', $default = '' ): void {
        $val = get_option($key, $default);
        echo '<input type="number" name="'.esc_attr($key).'" value="'.esc_attr((string)$val).'" step="'.esc_attr($step).'"'.($min!==''?' min="'.esc_attr($min).'"':'').($max!==''?' max="'.esc_attr($max).'"':'').' />';
        echo '<p class="description"><code>'.esc_html($key).'</code></p>';
    }

    private function f_checkbox( string $key, $default = 0 ): void {
        $val = (int) get_option($key, $default);
        echo '<input type="hidden" name="'.esc_attr($key).'" value="0" />';
        echo '<label><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked(1,$val,false).' /> ' . esc_html__('Enabled', 'dfehc') . '</label>';
        echo '<p class="description"><code>'.esc_html($key).'</code></p>';
    }

    private function f_textarea( string $key, string $placeholder = '', $default = '' ): void {
        $val = get_option($key, $default);
        echo '<textarea name="'.esc_attr($key).'" rows="5" class="large-text code" placeholder="'.esc_attr($placeholder).'">'.esc_textarea((string)$val).'</textarea>';
        echo '<p class="description"><code>'.esc_html($key).'</code></p>';
    }

    private function f_json( string $key, string $placeholder = '', $default = '' ): void {
        $val = get_option($key, $default);
        echo '<textarea name="'.esc_attr($key).'" rows="6" class="large-text code" placeholder="'.esc_attr($placeholder).'">'.esc_textarea((string)$val).'</textarea>';
        echo '<p class="description">'.esc_html__('JSON format. Leave empty to use defaults.', 'dfehc').' <code>'.esc_html($key).'</code></p>';
    }

    private function render_section( string $page, string $section_id, bool $show_title = true ): void {
        global $wp_settings_sections;

        $title = '';
        $callback = null;

        if ( isset( $wp_settings_sections[ $page ][ $section_id ] ) ) {
            $s = $wp_settings_sections[ $page ][ $section_id ];
            $title = isset($s['title']) ? (string) $s['title'] : '';
            $callback = $s['callback'] ?? null;
        }

        if ( $show_title && $title !== '' ) {
            echo '<h2>' . esc_html( $title ) . '</h2>';
        }

        if ( is_callable( $callback ) ) {
            call_user_func( $callback );
        }

        echo '<table class="form-table" role="presentation">';
        do_settings_fields( $page, $section_id );
        echo '</table>';
    }

    public function reg() {
        $keep = function(string $key, callable $san) {
            return function($v) use ($key, $san) {
                if ($v === null) return get_option($key);
                if (is_string($v) && trim($v) === '') return get_option($key);
                return call_user_func($san, $v);
            };
        };

        register_setting( $this->plugin->og, 'dfehc_heartbeat_zoom', 'floatval' );
        register_setting( $this->plugin->og, 'dfehc_priority_slider', 'intval' );
        register_setting( $this->plugin->og, 'dfehc_optimization_frequency', [$this,'s_opt_freq'] );
        register_setting( $this->plugin->og, 'dfhcsl_backend_heartbeat_control', 'absint' );
        register_setting( $this->plugin->og, 'dfhcsl_editor_heartbeat_control', 'absint' );
        register_setting( $this->plugin->og, 'dfhcsl_backend_heartbeat_interval', [$this,'vi'] );
        register_setting( $this->plugin->og, 'dfhcsl_editor_heartbeat_interval', [$this,'vi'] );
        register_setting( $this->plugin->og, 'dfehc_redis_server', 'sanitize_text_field' );
        register_setting( $this->plugin->og, 'dfehc_redis_port', 'intval' );
        register_setting( $this->plugin->og, 'dfehc_memcached_server', 'sanitize_text_field' );
        register_setting( $this->plugin->og, 'dfehc_memcached_port', 'intval' );
        register_setting( $this->plugin->og, 'dfehc_redis_socket', 'sanitize_text_field' );
        register_setting( $this->plugin->og, 'dfehc_disable_heartbeat', 'absint' );
        register_setting( $this->plugin->og, 'add_to_menu', 'absint' );

        register_setting( $this->plugin->og, 'dfehc_client_ip_public_only', $keep('dfehc_client_ip_public_only', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_trusted_proxies', $keep('dfehc_trusted_proxies', [$this,'s_lines']) );
        register_setting( $this->plugin->og, 'dfehc_proxy_ip_headers', $keep('dfehc_proxy_ip_headers', [$this,'s_lines']) );
        register_setting( $this->plugin->og, 'dfehc_client_ip', $keep('dfehc_client_ip', [$this,'s_text']) );

        register_setting( $this->plugin->og, 'dfehc_default_response_time', $keep('dfehc_default_response_time', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_spike_threshold_factor', $keep('dfehc_spike_threshold_factor', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_spike_increment_floor', $keep('dfehc_spike_increment_floor', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_spike_increment_cap', $keep('dfehc_spike_increment_cap', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_spike_decay', $keep('dfehc_spike_decay', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_recalibrate_threshold', $keep('dfehc_recalibrate_threshold', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_trim_extremes', $keep('dfehc_trim_extremes', [$this,'s_bool']) );

        register_setting( $this->plugin->og, 'dfehc_high_traffic_cache_expiration', $keep('dfehc_high_traffic_cache_expiration', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_max_baseline_age', $keep('dfehc_max_baseline_age', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_baseline_min_samples', $keep('dfehc_baseline_min_samples', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_baseline_expiration', $keep('dfehc_baseline_expiration', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_cache_expiration', $keep('dfehc_cache_expiration', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_head_negative_ttl', $keep('dfehc_head_negative_ttl', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_head_positive_ttl', $keep('dfehc_head_positive_ttl', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_probe_fail_ttl', $keep('dfehc_probe_fail_ttl', [$this,'s_int']) );

        register_setting( $this->plugin->og, 'dfehc_disable_loopback', $keep('dfehc_disable_loopback', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_probe_headers', $keep('dfehc_probe_headers', [$this,'s_lines']) );
        register_setting( $this->plugin->og, 'dfehc_total_timeout', $keep('dfehc_total_timeout', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_request_timeout', $keep('dfehc_request_timeout', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_num_requests', $keep('dfehc_num_requests', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_request_pause_us', $keep('dfehc_request_pause_us', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_ssl_verify', $keep('dfehc_ssl_verify', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_use_get_fallback', $keep('dfehc_use_get_fallback', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_use_head_method', $keep('dfehc_use_head_method', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_redirection', $keep('dfehc_redirection', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_limit_response_size', $keep('dfehc_limit_response_size', [$this,'s_int']) );

        register_setting( $this->plugin->og, 'dfehc_ping_rl_ttl', $keep('dfehc_ping_rl_ttl', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_ping_rl_limit', $keep('dfehc_ping_rl_limit', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_enable_public_ping', $keep('dfehc_enable_public_ping', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_high_traffic_threshold', $keep('dfehc_high_traffic_threshold', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_website_visitors', $keep('dfehc_website_visitors', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_current_server_load', $keep('dfehc_current_server_load', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_high_traffic_load_threshold', $keep('dfehc_high_traffic_load_threshold', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_allow_public_server_load', $keep('dfehc_allow_public_server_load', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_allow_public_async', $keep('dfehc_allow_public_async', [$this,'s_bool']) );

        register_setting( $this->plugin->og, 'dfehc_min_interval', $keep('dfehc_min_interval', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_max_interval', $keep('dfehc_max_interval', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_fallback_interval', $keep('dfehc_fallback_interval', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_unknown_load', $keep('dfehc_unknown_load', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_max_server_load', $keep('dfehc_max_server_load', [$this,'s_float']) );

        register_setting( $this->plugin->og, 'dfehc_interval_factors', $keep('dfehc_interval_factors', [$this,'s_json']) );
        register_setting( $this->plugin->og, 'dfehc_interval_weights', $keep('dfehc_interval_weights', [$this,'s_json']) );
        register_setting( $this->plugin->og, 'dfehc_load_weights', $keep('dfehc_load_weights', [$this,'s_json']) );
        register_setting( $this->plugin->og, 'dfehc_normalize_load', $keep('dfehc_normalize_load', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_assumed_cores_for_normalization', $keep('dfehc_assumed_cores_for_normalization', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_max_increase_rate', $keep('dfehc_max_increase_rate', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_interval_snap', $keep('dfehc_interval_snap', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_response_time_is_ms', $keep('dfehc_response_time_is_ms', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_contextual_load_value', $keep('dfehc_contextual_load_value', [$this,'s_float']) );
        register_setting( $this->plugin->og, 'dfehc_divide_cpu_load', $keep('dfehc_divide_cpu_load', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_cpu_cores', $keep('dfehc_cpu_cores', [$this,'s_int']) );

        register_setting( $this->plugin->og, 'dfehc_server_load_ttl', $keep('dfehc_server_load_ttl', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_transient_ttl', $keep('dfehc_transient_ttl', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_ema_ttl', $keep('dfehc_ema_ttl', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_prev_interval_ttl', $keep('dfehc_prev_interval_ttl', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_cache_retry_after', $keep('dfehc_cache_retry_after', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_redis_auth', $keep('dfehc_redis_auth', [$this,'s_text']) );
        register_setting( $this->plugin->og, 'dfehc_redis_user', $keep('dfehc_redis_user', [$this,'s_text']) );

        register_setting( $this->plugin->og, 'dfehc_required_capability', $keep('dfehc_required_capability', [$this,'s_cap']) );
        register_setting( $this->plugin->og, 'dfehc_public_rate_limit', $keep('dfehc_public_rate_limit', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_public_rate_window', $keep('dfehc_public_rate_window', [$this,'s_int']) );

        register_setting( $this->plugin->og, 'dfehc_enable_load_logging', $keep('dfehc_enable_load_logging', [$this,'s_bool']) );
        register_setting( $this->plugin->og, 'dfehc_log_retention_seconds', $keep('dfehc_log_retention_seconds', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_log_retention_max', $keep('dfehc_log_retention_max', [$this,'s_int']) );
        register_setting( $this->plugin->og, 'dfehc_prune_chunk_size', $keep('dfehc_prune_chunk_size', [$this,'s_int']) );

        add_settings_section( 'dfhcsl_heartbeat_settings_section', __('Heartbeat Control Settings','dfehc'), [$this,'shb'], $this->plugin->slug );
        add_settings_section( 'dfehc_redis_settings_section', __('Redis Settings','dfehc'), [$this,'srd'], $this->plugin->slug );
        add_settings_section( 'dfehc_memcached_settings_section', __('Memcached Settings','dfehc'), [$this,'smc'], $this->plugin->slug );
        add_settings_section( 'dfehc_optimization_schedule_section', __('Database Optimization Area','dfehc'), [$this,'sop'], $this->plugin->slug );
        add_settings_section( 'dfehc_load_display_settings_section', __('Heartbeat Zoom Settings','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_priority_settings_section', __('Priority Settings','dfehc'), [$this,'spr'], $this->plugin->slug );

        add_settings_section( 'dfehc_adv_ip_proxy', __('IP & Proxy Handling','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_adv_response_spike', __('Response Time & Spike Detection','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_adv_caching_baselines', __('Caching & Baselines','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_adv_loopback_http', __('Loopback & HTTP Requests','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_adv_ping_traffic_load', __('Ping, Traffic & Load','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_adv_core_thresholds', __('Core Configuration & Thresholds','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_adv_algorithm_logic', __('Algorithm & Logic','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_adv_caching_persistence', __('Caching & Persistence','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_adv_access_security', __('Access & Security','dfehc'), '__return_false', $this->plugin->slug );
        add_settings_section( 'dfehc_adv_logging_maintenance', __('Logging & Maintenance','dfehc'), '__return_false', $this->plugin->slug );

        add_settings_field( 'dfehc_disable_heartbeat', __('Disable Heartbeat','dfehc'), [$this,'fdis'], $this->plugin->slug, 'dfhcsl_heartbeat_settings_section' );
        add_settings_field( 'dfhcsl_backend_heartbeat_control', __('Backend Heartbeat Control','dfehc'), [$this,'fbhc'], $this->plugin->slug, 'dfhcsl_heartbeat_settings_section' );
        add_settings_field( 'dfhcsl_backend_heartbeat_interval', __('Backend Heartbeat Interval','dfehc'), [$this,'fbhi'], $this->plugin->slug, 'dfhcsl_heartbeat_settings_section' );
        add_settings_field( 'dfhcsl_editor_heartbeat_control', __('Editor Heartbeat Control','dfehc'), [$this,'fehc'], $this->plugin->slug, 'dfhcsl_heartbeat_settings_section' );
        add_settings_field( 'dfhcsl_editor_heartbeat_interval', __('Editor Heartbeat Interval','dfehc'), [$this,'fehi'], $this->plugin->slug, 'dfhcsl_heartbeat_settings_section' );
        add_settings_field( 'dfehc_priority_slider', __('Adjust Priority','dfehc'), [$this,'fps'], $this->plugin->slug, 'dfehc_priority_settings_section' );

        add_settings_field( 'dfehc_redis_server', __('Redis Server','dfehc'), [$this,'frs'], $this->plugin->slug, 'dfehc_redis_settings_section' );
        add_settings_field( 'dfehc_redis_port', __('Redis Port','dfehc'), [$this,'frp'], $this->plugin->slug, 'dfehc_redis_settings_section' );
        add_settings_field( 'dfehc_redis_socket', __('Redis Unix Socket','dfehc'), [$this,'frso'], $this->plugin->slug, 'dfehc_redis_settings_section' );

        add_settings_field( 'dfehc_memcached_server', __('Memcached Server','dfehc'), [$this,'fms'], $this->plugin->slug, 'dfehc_memcached_settings_section' );
        add_settings_field( 'dfehc_memcached_port', __('Memcached Port','dfehc'), [$this,'fmp'], $this->plugin->slug, 'dfehc_memcached_settings_section' );

        add_settings_field( 'dfehc_optimization_frequency', __('DB Optimization Frequency','dfehc'), [$this,'ffq'], $this->plugin->slug, 'dfehc_optimization_schedule_section' );
        add_settings_field( 'dfehc_heartbeat_zoom', __('Heartbeat Zoom Multiplier','dfehc'), [$this,'fzm'], $this->plugin->slug, 'dfehc_load_display_settings_section' );

        add_settings_field( 'dfehc_client_ip_public_only', __('Public IP only','dfehc'), function(){ $this->f_checkbox('dfehc_client_ip_public_only', 1); }, $this->plugin->slug, 'dfehc_adv_ip_proxy' );
        add_settings_field( 'dfehc_trusted_proxies', __('Trusted proxies (one per line)','dfehc'), function(){ $this->f_textarea('dfehc_trusted_proxies',"127.0.0.1\n10.0.0.0/8", ''); }, $this->plugin->slug, 'dfehc_adv_ip_proxy' );
        add_settings_field( 'dfehc_proxy_ip_headers', __('Proxy IP headers (one per line)','dfehc'), function(){ $this->f_textarea('dfehc_proxy_ip_headers',"X-Forwarded-For\nCF-Connecting-IP", "HTTP_CF_CONNECTING_IP\nHTTP_TRUE_CLIENT_IP\nHTTP_X_REAL_IP\nHTTP_X_FORWARDED_FOR"); }, $this->plugin->slug, 'dfehc_adv_ip_proxy' );
        add_settings_field( 'dfehc_client_ip', __('Client IP override','dfehc'), function(){ $this->f_text('dfehc_client_ip','', ''); }, $this->plugin->slug, 'dfehc_adv_ip_proxy' );

        add_settings_field( 'dfehc_default_response_time', __('Default response time','dfehc'), function(){ $this->f_number('dfehc_default_response_time','0.01','','', 0.0); }, $this->plugin->slug, 'dfehc_adv_response_spike' );
        add_settings_field( 'dfehc_spike_threshold_factor', __('Spike threshold factor','dfehc'), function(){ $this->f_number('dfehc_spike_threshold_factor','0.01','','', 0.0); }, $this->plugin->slug, 'dfehc_adv_response_spike' );
        add_settings_field( 'dfehc_spike_increment_floor', __('Spike increment floor','dfehc'), function(){ $this->f_number('dfehc_spike_increment_floor','0.01','','', 0.0); }, $this->plugin->slug, 'dfehc_adv_response_spike' );
        add_settings_field( 'dfehc_spike_increment_cap', __('Spike increment cap','dfehc'), function(){ $this->f_number('dfehc_spike_increment_cap','0.01','','', 0.0); }, $this->plugin->slug, 'dfehc_adv_response_spike' );
        add_settings_field( 'dfehc_spike_decay', __('Spike decay','dfehc'), function(){ $this->f_number('dfehc_spike_decay','0.01','','', 0.0); }, $this->plugin->slug, 'dfehc_adv_response_spike' );
        add_settings_field( 'dfehc_recalibrate_threshold', __('Recalibrate threshold','dfehc'), function(){ $this->f_number('dfehc_recalibrate_threshold','0.01','','', 0.0); }, $this->plugin->slug, 'dfehc_adv_response_spike' );
        add_settings_field( 'dfehc_trim_extremes', __('Trim extremes','dfehc'), function(){ $this->f_checkbox('dfehc_trim_extremes', 0); }, $this->plugin->slug, 'dfehc_adv_response_spike' );

        add_settings_field( 'dfehc_high_traffic_cache_expiration', __('High traffic cache expiration (s)','dfehc'), function(){ $this->f_number('dfehc_high_traffic_cache_expiration','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_baselines' );
        add_settings_field( 'dfehc_max_baseline_age', __('Max baseline age (s)','dfehc'), function(){ $this->f_number('dfehc_max_baseline_age','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_baselines' );
        add_settings_field( 'dfehc_baseline_min_samples', __('Baseline min samples','dfehc'), function(){ $this->f_number('dfehc_baseline_min_samples','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_baselines' );
        add_settings_field( 'dfehc_baseline_expiration', __('Baseline expiration (s)','dfehc'), function(){ $this->f_number('dfehc_baseline_expiration','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_baselines' );
        add_settings_field( 'dfehc_cache_expiration', __('Cache expiration (s)','dfehc'), function(){ $this->f_number('dfehc_cache_expiration','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_baselines' );
        add_settings_field( 'dfehc_head_negative_ttl', __('HEAD negative TTL (s)','dfehc'), function(){ $this->f_number('dfehc_head_negative_ttl','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_baselines' );
        add_settings_field( 'dfehc_head_positive_ttl', __('HEAD positive TTL (s)','dfehc'), function(){ $this->f_number('dfehc_head_positive_ttl','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_baselines' );
        add_settings_field( 'dfehc_probe_fail_ttl', __('Probe fail TTL (s)','dfehc'), function(){ $this->f_number('dfehc_probe_fail_ttl','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_baselines' );

        add_settings_field( 'dfehc_disable_loopback', __('Disable loopback','dfehc'), function(){ $this->f_checkbox('dfehc_disable_loopback', 0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_probe_headers', __('Probe headers (one per line: Key: Value)','dfehc'), function(){ $this->f_textarea('dfehc_probe_headers',"User-Agent: DFEHC\nAccept: */*", ''); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_total_timeout', __('Total timeout (s)','dfehc'), function(){ $this->f_number('dfehc_total_timeout','0.1','0','', 0.0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_request_timeout', __('Request timeout (s)','dfehc'), function(){ $this->f_number('dfehc_request_timeout','0.1','0','', 0.0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_num_requests', __('Number of requests','dfehc'), function(){ $this->f_number('dfehc_num_requests','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_request_pause_us', __('Pause between requests (µs)','dfehc'), function(){ $this->f_number('dfehc_request_pause_us','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_ssl_verify', __('SSL verify','dfehc'), function(){ $this->f_checkbox('dfehc_ssl_verify', 0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_use_get_fallback', __('Use GET fallback','dfehc'), function(){ $this->f_checkbox('dfehc_use_get_fallback', 0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_use_head_method', __('Use HEAD method','dfehc'), function(){ $this->f_checkbox('dfehc_use_head_method', 0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_redirection', __('Max redirections','dfehc'), function(){ $this->f_number('dfehc_redirection','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );
        add_settings_field( 'dfehc_limit_response_size', __('Limit response size (bytes)','dfehc'), function(){ $this->f_number('dfehc_limit_response_size','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_loopback_http' );

        add_settings_field( 'dfehc_ping_rl_ttl', __('Ping rate-limit TTL (s)','dfehc'), function(){ $this->f_number('dfehc_ping_rl_ttl','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_ping_traffic_load' );
        add_settings_field( 'dfehc_ping_rl_limit', __('Ping rate-limit limit','dfehc'), function(){ $this->f_number('dfehc_ping_rl_limit','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_ping_traffic_load' );
        add_settings_field( 'dfehc_enable_public_ping', __('Enable public ping','dfehc'), function(){ $this->f_checkbox('dfehc_enable_public_ping', 0); }, $this->plugin->slug, 'dfehc_adv_ping_traffic_load' );
        add_settings_field( 'dfehc_high_traffic_threshold', __('High traffic threshold','dfehc'), function(){ $this->f_number('dfehc_high_traffic_threshold','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_ping_traffic_load' );
        add_settings_field( 'dfehc_website_visitors', __('Website visitors override','dfehc'), function(){ $this->f_number('dfehc_website_visitors','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_ping_traffic_load' );
        add_settings_field( 'dfehc_current_server_load', __('Current server load override','dfehc'), function(){ $this->f_number('dfehc_current_server_load','0.01','','', 0.0); }, $this->plugin->slug, 'dfehc_adv_ping_traffic_load' );
        add_settings_field( 'dfehc_high_traffic_load_threshold', __('High traffic load threshold','dfehc'), function(){ $this->f_number('dfehc_high_traffic_load_threshold','0.01','','', 0.0); }, $this->plugin->slug, 'dfehc_adv_ping_traffic_load' );
        add_settings_field( 'dfehc_allow_public_server_load', __('Allow public server load','dfehc'), function(){ $this->f_checkbox('dfehc_allow_public_server_load', 0); }, $this->plugin->slug, 'dfehc_adv_ping_traffic_load' );
        add_settings_field( 'dfehc_allow_public_async', __('Allow public async','dfehc'), function(){ $this->f_checkbox('dfehc_allow_public_async', 0); }, $this->plugin->slug, 'dfehc_adv_ping_traffic_load' );

        add_settings_field( 'dfehc_min_interval', __('Min interval (s)','dfehc'), function(){ $this->f_number('dfehc_min_interval','1','0','', 15); }, $this->plugin->slug, 'dfehc_adv_core_thresholds' );
        add_settings_field( 'dfehc_max_interval', __('Max interval (s)','dfehc'), function(){ $this->f_number('dfehc_max_interval','1','0','', 300); }, $this->plugin->slug, 'dfehc_adv_core_thresholds' );
        add_settings_field( 'dfehc_fallback_interval', __('Fallback interval (s)','dfehc'), function(){ $this->f_number('dfehc_fallback_interval','1','0','', 60); }, $this->plugin->slug, 'dfehc_adv_core_thresholds' );
        add_settings_field( 'dfehc_unknown_load', __('Unknown load sentinel','dfehc'), function(){ $this->f_number('dfehc_unknown_load','0.001','','', 0.404); }, $this->plugin->slug, 'dfehc_adv_core_thresholds' );
        add_settings_field( 'dfehc_max_server_load', __('Max server load','dfehc'), function(){ $this->f_number('dfehc_max_server_load','0.01','','', 85); }, $this->plugin->slug, 'dfehc_adv_core_thresholds' );

        add_settings_field( 'dfehc_interval_factors', __('Interval factors (JSON)','dfehc'), function(){ $this->f_json('dfehc_interval_factors','', ''); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_interval_weights', __('Interval weights (JSON)','dfehc'), function(){ $this->f_json('dfehc_interval_weights','', ''); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_load_weights', __('Load weights (JSON)','dfehc'), function(){ $this->f_json('dfehc_load_weights','', ''); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_normalize_load', __('Normalize load','dfehc'), function(){ $this->f_checkbox('dfehc_normalize_load', 0); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_assumed_cores_for_normalization', __('Assumed cores for normalization','dfehc'), function(){ $this->f_number('dfehc_assumed_cores_for_normalization','0.1','0','', 8.0); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_max_increase_rate', __('Max increase rate','dfehc'), function(){ $this->f_number('dfehc_max_increase_rate','0.01','','', 0.5); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_interval_snap', __('Interval snap','dfehc'), function(){ $this->f_number('dfehc_interval_snap','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_response_time_is_ms', __('Response time is ms','dfehc'), function(){ $this->f_checkbox('dfehc_response_time_is_ms', 0); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_contextual_load_value', __('Contextual load value','dfehc'), function(){ $this->f_number('dfehc_contextual_load_value','0.01','','', 0.0); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_divide_cpu_load', __('Divide CPU load','dfehc'), function(){ $this->f_checkbox('dfehc_divide_cpu_load', 1); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );
        add_settings_field( 'dfehc_cpu_cores', __('CPU cores override','dfehc'), function(){ $this->f_number('dfehc_cpu_cores','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_algorithm_logic' );

        add_settings_field( 'dfehc_server_load_ttl', __('Server load TTL (s)','dfehc'), function(){ $this->f_number('dfehc_server_load_ttl','1','0','', 180); }, $this->plugin->slug, 'dfehc_adv_caching_persistence' );
        add_settings_field( 'dfehc_transient_ttl', __('Transient TTL (s)','dfehc'), function(){ $this->f_number('dfehc_transient_ttl','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_persistence' );
        add_settings_field( 'dfehc_ema_ttl', __('EMA TTL (s)','dfehc'), function(){ $this->f_number('dfehc_ema_ttl','1','0','', 0); }, $this->plugin->slug, 'dfehc_adv_caching_persistence' );
        add_settings_field( 'dfehc_prev_interval_ttl', __('Previous interval TTL (s)','dfehc'), function(){ $this->f_number('dfehc_prev_interval_ttl','1','0','', 1800); }, $this->plugin->slug, 'dfehc_adv_caching_persistence' );
        add_settings_field( 'dfehc_cache_retry_after', __('Cache retry-after (s)','dfehc'), function(){ $this->f_number('dfehc_cache_retry_after','1','0','', 60); }, $this->plugin->slug, 'dfehc_adv_caching_persistence' );
        add_settings_field( 'dfehc_redis_user', __('Redis user','dfehc'), function(){ $this->f_text('dfehc_redis_user','', (string) (getenv('REDIS_USERNAME') ?: '')); }, $this->plugin->slug, 'dfehc_adv_caching_persistence' );
        add_settings_field( 'dfehc_redis_auth', __('Redis auth','dfehc'), function(){ $this->f_text('dfehc_redis_auth','', (string) (getenv('REDIS_PASSWORD') ?: '')); }, $this->plugin->slug, 'dfehc_adv_caching_persistence' );

        add_settings_field( 'dfehc_required_capability', __('Required capability','dfehc'), function(){ $this->f_text('dfehc_required_capability','read', 'read'); }, $this->plugin->slug, 'dfehc_adv_access_security' );
        add_settings_field( 'dfehc_public_rate_limit', __('Public rate limit','dfehc'), function(){ $this->f_number('dfehc_public_rate_limit','1','0','', 60); }, $this->plugin->slug, 'dfehc_adv_access_security' );
        add_settings_field( 'dfehc_public_rate_window', __('Public rate window (s)','dfehc'), function(){ $this->f_number('dfehc_public_rate_window','1','0','', 60); }, $this->plugin->slug, 'dfehc_adv_access_security' );

        add_settings_field( 'dfehc_enable_load_logging', __('Enable load logging','dfehc'), function(){ $this->f_checkbox('dfehc_enable_load_logging', 1); }, $this->plugin->slug, 'dfehc_adv_logging_maintenance' );
        add_settings_field( 'dfehc_log_retention_seconds', __('Log retention seconds','dfehc'), function(){ $this->f_number('dfehc_log_retention_seconds','1','0','', 86400); }, $this->plugin->slug, 'dfehc_adv_logging_maintenance' );
        add_settings_field( 'dfehc_log_retention_max', __('Log retention max','dfehc'), function(){ $this->f_number('dfehc_log_retention_max','1','0','', 2000); }, $this->plugin->slug, 'dfehc_adv_logging_maintenance' );
        add_settings_field( 'dfehc_prune_chunk_size', __('Prune chunk size','dfehc'), function(){ $this->f_number('dfehc_prune_chunk_size','1','1','', 50); }, $this->plugin->slug, 'dfehc_adv_logging_maintenance' );
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $tabs = [
            'dfehc_tab_heartbeat'     => __('Heartbeat  Control','dfehc'),
            'dfehc_tab_object_cache'  => __('Object Cache','dfehc'),
            'dfehc_tab_db'            => __('Database Optimization','dfehc'),
            'dfehc_tab_advanced'      => __('Advanced Settings','dfehc'),
        ];

        echo '<div class="wrap">';

        echo '<div class="dfehc-page-head">';
        echo '<h1 class="dfehc-page-title"><span class="dfehc-page-title-mark">DFEHC</span> <span class="dfehc-page-title-sub">'.esc_html__('Settings','dfehc').'</span></h1>';
        echo '<p class="dfehc-page-subtitle">'.esc_html__("The plugin automatically identifies your environment configuration and optimizes frequency settings accordingly. You can further customize these settings using the options below.",'dfehc').'</p>';
        echo '</div>';

        echo '<h2 class="nav-tab-wrapper dfehc-tabs">';
        $first = true;
        foreach ( $tabs as $id => $label ) {
            $cls = 'nav-tab' . ( $first ? ' nav-tab-active' : '' );
            echo '<a href="#" class="' . esc_attr( $cls ) . '" data-tab="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>';
            $first = false;
        }
        echo '</h2>';

        echo '<form id="dfehc-settings-form" method="post" action="options.php">';
        settings_fields( $this->plugin->og );

        echo '<div id="dfehc_tab_heartbeat" class="dfehc-tab-panel is-active">';
        $this->render_section( $this->plugin->slug, 'dfhcsl_heartbeat_settings_section' );
        $this->render_section( $this->plugin->slug, 'dfehc_priority_settings_section' );
        submit_button( null, 'primary', 'submit', true );
        echo '</div>';

        echo '<div id="dfehc_tab_object_cache" class="dfehc-tab-panel">';
        echo '<h2>' . esc_html__( 'Object Cache', 'dfehc' ) . '</h2>';
        echo '<p>' . esc_html__( 'Configure Redis or Memcached settings.', 'dfehc' ) . '</p>';
        $this->render_section( $this->plugin->slug, 'dfehc_redis_settings_section' );
        $this->render_section( $this->plugin->slug, 'dfehc_memcached_settings_section' );
        submit_button( null, 'primary', 'submit', true );
        echo '</div>';

        echo '<div id="dfehc_tab_db" class="dfehc-tab-panel">';
        $this->render_section( $this->plugin->slug, 'dfehc_optimization_schedule_section' );
        submit_button( null, 'primary', 'submit', true );
        echo '</div>';

        echo '<div id="dfehc_tab_advanced" class="dfehc-tab-panel">';
        echo '<h2>' . esc_html__( 'Advanced Settings', 'dfehc' ) . '</h2>';

        echo '<details open class="dfehc-adv-group"><summary>'.esc_html__('IP & Proxy Handling','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_ip_proxy', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Response Time & Spike Detection','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_response_spike', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Caching & Baselines','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_caching_baselines', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Loopback & HTTP Requests','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_loopback_http', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Ping, Traffic & Load','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_ping_traffic_load', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Core Configuration & Thresholds','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_core_thresholds', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Algorithm & Logic','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_algorithm_logic', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Caching & Persistence','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_caching_persistence', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Access & Security','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_access_security', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Logging & Maintenance','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_adv_logging_maintenance', false );
        echo '</details>';

        echo '<details class="dfehc-adv-group"><summary>'.esc_html__('Heartbeat Zoom Settings','dfehc').'</summary>';
        $this->render_section( $this->plugin->slug, 'dfehc_load_display_settings_section', false );
        echo '</details>';

        submit_button( null, 'primary', 'submit', true );
        echo '</div>';

        echo '<script>
(function(){
    var form = document.getElementById("dfehc-settings-form");
    if (!form) return;

    var submitting = false;
    var dirty = false;
    var lastActiveId = null;
    var mo = null;

    function getActiveTabId(){
        var p = form.querySelector(".dfehc-tab-panel.is-active");
        return p ? p.id : "dfehc_tab_heartbeat";
    }

    function setPanelEnabled(panel, enabled){
        var els = panel.querySelectorAll("input,select,textarea,button");
        els.forEach(function(el){
            var isNamed = !!el.name || el.tagName === "BUTTON";
            if (!isNamed) return;

            if (!enabled) {
                if (el.dataset.dfehcPrevDisabled === undefined) {
                    el.dataset.dfehcPrevDisabled = el.disabled ? "1" : "0";
                }
                el.disabled = true;
                return;
            }

            if (el.dataset.dfehcPrevDisabled !== undefined) {
                el.disabled = (el.dataset.dfehcPrevDisabled === "1");
                delete el.dataset.dfehcPrevDisabled;
            }
        });
    }

    function syncEnabled(){
        var activeId = getActiveTabId();
        var panels = form.querySelectorAll(".dfehc-tab-panel");
        panels.forEach(function(p){
            setPanelEnabled(p, p.id === activeId);
        });
        lastActiveId = activeId;
    }

    function snapshotPanel(panel){
        var data = {};
        panel.querySelectorAll("input,select,textarea").forEach(function(el){
            if (!el.name) return;
            if (el.type === "checkbox" || el.type === "radio") {
                data[el.name + "::" + (el.value || "")] = el.checked ? "1" : "0";
            } else {
                data[el.name] = (el.value === undefined ? "" : String(el.value));
            }
        });
        return JSON.stringify(data);
    }

    function getPanelById(id){
        return document.getElementById(id);
    }

    var baselineByTab = {};

    function ensureBaselineFor(tabId){
        var panel = getPanelById(tabId);
        if (!panel) return;
        baselineByTab[tabId] = snapshotPanel(panel);
        dirty = false;
    }

    function isActivePanelDirty(){
        var id = getActiveTabId();
        var panel = getPanelById(id);
        if (!panel) return false;
        if (baselineByTab[id] === undefined) ensureBaselineFor(id);
        return snapshotPanel(panel) !== baselineByTab[id];
    }

    function markDirtyCheck(){
        dirty = isActivePanelDirty();
    }

    function attachObserver(){
        if (mo) return;
        mo = new MutationObserver(function(){
            if (submitting) return;
            syncEnabled();
        });
        mo.observe(form, { attributes: true, subtree: true, attributeFilter: ["class"] });
    }

    function detachObserver(){
        if (!mo) return;
        mo.disconnect();
        mo = null;
    }

    function activateTab(tabId){
        syncEnabled();
        ensureBaselineFor(tabId);
    }

    syncEnabled();
    attachObserver();
    ensureBaselineFor(getActiveTabId());

    window.addEventListener("load", function(){
        syncEnabled();
        ensureBaselineFor(getActiveTabId());
    });

    document.addEventListener("visibilitychange", function(){
        if (document.hidden) return;
        syncEnabled();
        ensureBaselineFor(getActiveTabId());
    });

    form.addEventListener("input", function(e){
        var panel = e.target && e.target.closest ? e.target.closest(".dfehc-tab-panel") : null;
        if (!panel) return;
        if (!panel.classList.contains("is-active")) return;
        markDirtyCheck();
    });

    form.addEventListener("change", function(e){
        var panel = e.target && e.target.closest ? e.target.closest(".dfehc-tab-panel") : null;
        if (!panel) return;
        if (!panel.classList.contains("is-active")) return;
        markDirtyCheck();
    });

    document.querySelectorAll(".dfehc-tabs a[data-tab]").forEach(function(a){
        a.addEventListener("click", function(ev){
            var targetId = a.getAttribute("data-tab");
            if (!targetId) return;

            var currentId = getActiveTabId();
            if (targetId === currentId) {
                syncEnabled();
                return;
            }

            if (isActivePanelDirty()) {
                var ok = window.confirm("You have unsaved changes in this tab. Save before leaving, or discard changes?");
                if (!ok) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    return;
                }
            }

            dirty = false;
            activateTab(targetId);
            setTimeout(function(){
                syncEnabled();
                ensureBaselineFor(getActiveTabId());
            }, 0);
        }, true);
    });

    window.addEventListener("beforeunload", function(e){
        if (submitting) return;
        if (isActivePanelDirty()) {
            e.preventDefault();
            e.returnValue = "";
            return "";
        }
    });

    form.addEventListener("submit", function(){
        submitting = true;
        detachObserver();
        syncEnabled();
        setTimeout(function(){ submitting = false; attachObserver(); }, 3000);
    });
})();
</script>';


        echo '</form>';
        echo '</div>';
    }

    public function shb() { echo '<br><p>'.esc_html__('Control the WordPress heartbeat settings for the backend and editor. Disabling or setting a long interval can impact real-time features.','dfehc').'</p>'; }
    public function srd() { echo '<p>'.esc_html__('Configure Redis settings for the plugin.','dfehc').'</p>'; }
    public function smc() { echo '<p>'.esc_html__('Configure Memcached settings for the plugin.','dfehc').'</p>'; }
    public function spr() { echo '<br><p>'.esc_html__('Adjust the priority between server performance and user activity.','dfehc').'</p>'; }

    public function sop() {
        $m = get_option( 'add_to_menu', 0 );
        echo '<br><p><strong>'.esc_html__('Use this section with care.','dfehc').'</strong> '.esc_html__('An optimized database helps your website run faster. Backup first.','dfehc').'</p>';

        $printed_health = false;

        if ( function_exists('DynamicHeartbeat\\dfehc_get_database_health_status') ) {
            $h = \DynamicHeartbeat\dfehc_get_database_health_status();
            $c = isset($h['status_color']) ? (string) $h['status_color'] : '#999';
            echo '<p>' . esc_html__('Database health status: ', 'dfehc') . ' <span class="database-health-status" style="--c:' . esc_attr($c) . ';"></span></p>';
            $printed_health = true;

            $db_ms = null;
            if ( isset($h['db_response_ms']) && is_numeric($h['db_response_ms']) ) $db_ms = (float) $h['db_response_ms'];
            elseif ( isset($h['db_response_time_ms']) && is_numeric($h['db_response_time_ms']) ) $db_ms = (float) $h['db_response_time_ms'];

            if ( $db_ms !== null ) {
                echo '<p>' . esc_html__('Database response time: ', 'dfehc') . esc_html( number_format((float)$db_ms, 2) ) . ' ms</p>';
            }
        }

        if ( function_exists('dfehc_get_server_response_time') ) {
            $rt = dfehc_get_server_response_time();
            $db_ms2 = null;

            if ( is_array($rt) && isset($rt['db_response_ms']) && is_numeric($rt['db_response_ms']) ) {
                $db_ms2 = (float) $rt['db_response_ms'];
            }

            if ( $printed_health && $db_ms2 !== null ) {
                echo '<p>' . esc_html__('Database response time: ', 'dfehc') . '<strong>' . esc_html( number_format((float)$db_ms2, 2) ) . ' ms</strong></p>';
            }
        }

        if ( $m ) echo '<p><a href="'.esc_url(admin_url('admin.php?page=dfehc-unclogger')).'">'.esc_html__('Manually choose database optimizations','dfehc').'</a></p>';
        echo '<div>';
        echo '<p><br>'.esc_html__('Add manual database optimizations page to admin menu:','dfehc').'</p><label><input type="radio" name="add_to_menu" value="1" '.checked(1,$m,false).'> '.esc_html__('Enable','dfehc').'</label> <label><input type="radio" name="add_to_menu" value="0" '.checked(0,$m,false).'> '.esc_html__('Disable','dfehc').'</label></div>';
    }

    public function fzm() { echo '<input type="number" name="dfehc_heartbeat_zoom" value="'.esc_attr(get_option('dfehc_heartbeat_zoom',10)).'" step="0.1" /><br><p> Default "10" or "1". Applies to CPU based calculations only.'; }

    public function fps() {
        $slider_value = (int) get_option('dfehc_priority_slider', 0);
        $weights = $this->get_priority_weights($slider_value);

        $userActivityWeight = $weights['user'];
        $serverLoadWeight = $weights['server'];
        $responseTimeWeight = $weights['response'];

        $slider_html = '<div style="display:flex;align-items:center;max-width:500px;">
            <span style="padding-right:10px">'.esc_html__('Server','dfehc').'</span>
            <input type="range" id="dfehc_priority_slider" name="dfehc_priority_slider" min="-3" max="3" step="1" value="'.esc_attr($slider_value).'" style="flex-grow:1" />
            <span style="padding-left:10px">'.esc_html__('User','dfehc').'</span>
        </div>';

        $display_html = '<div id="dfehc-priority-display" style="max-width:500px; margin-top:10px; opacity:0.7;">
            <p style="font-size: 10px; margin: 2px 0;">User Activity Priority: <span id="user_activity_weight_display">'.number_format($userActivityWeight, 2).'</span></p>
            <p style="font-size: 10px; margin: 2px 0;">Server Load Priority: <span id="server_load_weight_display">'.number_format($serverLoadWeight, 2).'</span></p>
            <p style="font-size: 10px; margin: 2px 0;">Response Time Priority: <span id="response_time_weight_display">'.number_format($responseTimeWeight, 2).'</span></p>
        </div>';

        echo '<div>' . $slider_html . $display_html . '</div>';
    }

    public function fdis() { echo '<input type="checkbox" name="dfehc_disable_heartbeat" value="1" '.checked(1,get_option('dfehc_disable_heartbeat'),false).' '.((get_option('dfhcsl_backend_heartbeat_control')||get_option('dfhcsl_editor_heartbeat_control'))?'disabled':'').' />'; }

    public function fbhc() {
        echo '<div style="display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" name="dfhcsl_backend_heartbeat_control" value="1" '.checked(1, get_option('dfhcsl_backend_heartbeat_control'), false).' />
            <span class="dfehc-tooltip">?
                <span class="dfehc-tooltip-text">' . esc_html__('Enable manual heartbeat control for the backend', 'dfehc') . '</span>
            </span>
        </div>';
    }

    public function fbhi() { echo '<input type="number" name="dfhcsl_backend_heartbeat_interval" min="15" max="300" value="'.esc_attr(get_option('dfhcsl_backend_heartbeat_interval','30')).'" />'; }

    public function fehc() {
        echo '<div style="display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" name="dfhcsl_editor_heartbeat_control" value="1" '.checked(1, get_option('dfhcsl_editor_heartbeat_control'), false).' />
            <span class="dfehc-tooltip">?
                <span class="dfehc-tooltip-text">' . esc_html__('Enable manual heartbeat control for the editor', 'dfehc') . '</span>
            </span>
        </div>';
    }

    public function fehi() { echo '<input type="number" name="dfhcsl_editor_heartbeat_interval" min="15" max="300" value="'.esc_attr(get_option('dfhcsl_editor_heartbeat_interval','30')).'" />'; }
    public function frs() { echo '<input type="text" name="dfehc_redis_server" value="'.esc_attr(get_option('dfehc_redis_server','127.0.0.1')).'" />'; }
    public function frp() { echo '<input type="number" name="dfehc_redis_port" value="'.esc_attr(get_option('dfehc_redis_port',6379)).'" />'; }
    public function frso() { echo '<input type="text" name="dfehc_redis_socket" value="'.esc_attr(get_option('dfehc_redis_socket','')).'" placeholder="/path/to/redis.sock" />'; }
    public function fms() { echo '<input type="text" name="dfehc_memcached_server" value="'.esc_attr(get_option('dfehc_memcached_server','127.0.0.1')).'" />'; }
    public function fmp() { echo '<input type="number" name="dfehc_memcached_port" value="'.esc_attr(get_option('dfehc_memcached_port',11211)).'" />'; }

    public function ffq() {
        $f = get_option('dfehc_optimization_frequency','');
        $o = [''=>__('Disabled','dfehc'),'daily'=>__('Daily','dfehc'),'weekly'=>__('Every week','dfehc'),'biweekly'=>__('Every two weeks','dfehc'),'monthly'=>__('Every month','dfehc')];
        echo '<select name="dfehc_optimization_frequency">';
        foreach ($o as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($f,$v,false).'>'.esc_html($l).'</option>';
        echo '</select>';
    }

    public function vi($i){ if($i==='disable') return $i; $v=(int)$i; return ($v>=15&&$v<=300)?$v:60; }
}