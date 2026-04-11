<?php
function dfehc_enqueue_chart_js() {
    if (!is_admin()) {
        return;
    }

    $load_logs = get_option('dfehc_server_load_logs', []);
    if (!is_array($load_logs)) {
        $load_logs = [];
    }

    $clean_logs = [];
    foreach ($load_logs as $log) {
        if (!is_array($log)) continue;
        $ts = isset($log['timestamp']) ? (int) $log['timestamp'] : 0;
        $ld = $log['load'] ?? null;
        $ld = is_numeric($ld) ? (float) $ld : 0.0;
        if ($ts <= 0) continue;
        $clean_logs[] = ['timestamp' => $ts, 'load' => $ld];
    }

    $chart_js_url = plugins_url('js/chart.js', __FILE__);
    wp_enqueue_script('dfehc_chartjs', $chart_js_url, [], '1.0', true);

    $labels = array_map(function($entry) { return date('H:i', (int) $entry['timestamp']); }, $clean_logs);
    $data   = array_map(function($entry) { return (float) $entry['load']; }, $clean_logs);

    wp_localize_script('dfehc_chartjs', 'dfehc_chartData', [
        'labels' => array_map('sanitize_text_field', $labels),
        'data'   => array_map('floatval', $data),
    ]);
}
add_action('admin_enqueue_scripts', 'dfehc_enqueue_chart_js');

function dfehc_logs_max_entries(): int {
    $n = (int) apply_filters('dfehc_server_load_logs_max_entries', 1500);
    return max(50, min(20000, $n));
}

function dfehc_trim_load_logs(array $logs): array {
    $max = dfehc_logs_max_entries();
    $count = count($logs);
    if ($count <= $max) return $logs;
    return array_slice($logs, $count - $max);
}

function dfehc_update_load_logs_option(array $logs): void {
    $logs = array_values($logs);
    $logs = dfehc_trim_load_logs($logs);
    update_option('dfehc_server_load_logs', $logs, false);
}

function dfehc_heartbeat_health_dashboard_widget_function() {
    static $memo = null;
    if (is_array($memo)) {
        echo $memo['html'];
        return;
    }

    $now = time();

    $heartbeat_status = get_transient('dfehc_heartbeat_health_status');

    $server_load = dfehc_get_server_load();
    $server_response_time = dfehc_get_server_response_time();

    if ($heartbeat_status === false) {
        $heartbeat_status = get_option('dfehc_disable_heartbeat') ? 'Stopped' : dfehc_get_server_health_status($server_load);
        $heartbeat_status = is_string($heartbeat_status) ? $heartbeat_status : 'Stopped';
        set_transient('dfehc_heartbeat_health_status', $heartbeat_status, 20);
    }

$recommended_interval = dfehc_get_recommended_interval_for_load((float) $server_load);

    $load_logs = get_option('dfehc_server_load_logs', []);
    if (!is_array($load_logs)) $load_logs = [];

    $log_enabled = (bool) apply_filters('dfehc_widget_log_enabled', true);
    $log_sample_rate = (float) apply_filters('dfehc_widget_log_sample_rate', 1.0);
    if (!is_finite($log_sample_rate) || $log_sample_rate < 0.0) $log_sample_rate = 0.0;
    if ($log_sample_rate > 1.0) $log_sample_rate = 1.0;

    $should_log = $log_enabled;
    if ($should_log && $log_sample_rate < 1.0) {
        $r = function_exists('wp_rand') ? (int) wp_rand(0, 1000000) : (int) (mt_rand(0, 1000000));
        $should_log = ((float) $r / 1000000.0) <= $log_sample_rate;
    }

    if ($should_log) {
        $load_logs[] = ['timestamp' => $now, 'load' => $server_load];
    }

    $snap_key = 'dfehc_server_load_logs_snapshot';
    $snap = get_transient($snap_key);
    $snap_ttl = (int) apply_filters('dfehc_widget_logs_snapshot_ttl', 60);
    if ($snap_ttl < 5) $snap_ttl = 5;
    if ($snap_ttl > 600) $snap_ttl = 600;

    $save_interval = (int) apply_filters('dfehc_widget_logs_save_interval', 120);
    if ($save_interval < 10) $save_interval = 10;
    if ($save_interval > 1800) $save_interval = 1800;

    $save_lock_key = 'dfehc_server_load_logs_save_lock';
    $save_lock_ttl = (int) apply_filters('dfehc_widget_logs_save_lock_ttl', 15);
    if ($save_lock_ttl < 5) $save_lock_ttl = 5;
    if ($save_lock_ttl > 120) $save_lock_ttl = 120;

    $has_snapshot = is_array($snap) && isset($snap['t'], $snap['logs']) && is_array($snap['logs']);
    $snap_time = $has_snapshot ? (int) $snap['t'] : 0;
    $use_snapshot = $has_snapshot && ($now - $snap_time) >= 0 && ($now - $snap_time) <= $snap_ttl;

    $logs_for_chart = $use_snapshot ? $snap['logs'] : $load_logs;

    $clean_logs = [];
    $cutoff = $now - 86400;
    $upper = $now + 60;

    foreach ($logs_for_chart as $log) {
        if (!is_array($log)) continue;
        $ts = isset($log['timestamp']) ? (int) $log['timestamp'] : 0;
        if ($ts < $cutoff || $ts > $upper || $ts <= 0) continue;
        $ld = $log['load'] ?? null;
        $ld = is_numeric($ld) ? (float) $ld : 0.0;
        $clean_logs[] = ['timestamp' => $ts, 'load' => $ld];
    }

    $logs_for_chart = array_values($clean_logs);

    if (!$use_snapshot) {
        set_transient($snap_key, ['t' => $now, 'logs' => $logs_for_chart], $snap_ttl);
    }

    $should_save = false;
    $last_saved = (int) get_option('dfehc_server_load_logs_last_saved', 0);
    if ($last_saved < 0) $last_saved = 0;

    if ($should_log) {
        if ($last_saved === 0 || ($now - $last_saved) >= $save_interval) {
            $should_save = true;
        }
    }

    if ($should_save) {
        $got_lock = false;
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_add')) {
            $got_lock = wp_cache_add($save_lock_key, 1, 'dfehc', $save_lock_ttl);
        } else {
            $got_lock = (get_transient($save_lock_key) === false) && set_transient($save_lock_key, 1, $save_lock_ttl);
        }

        if ($got_lock) {
            update_option('dfehc_update_load_logs_option', $logs_for_chart, false);
            update_option('dfehc_server_load_logs_last_saved', $now, false);

            if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_delete')) {
                wp_cache_delete($save_lock_key, 'dfehc');
            } else {
                delete_transient($save_lock_key);
            }
        }
    }

    $status = is_string($heartbeat_status) ? $heartbeat_status : 'Stopped';
    $status = sanitize_text_field($status);

    $allowed_status = ['Resting', 'Pacing', 'Under Load', 'Under Strain', 'Stopped'];
    if (!in_array($status, $allowed_status, true)) $status = 'Stopped';

    $status_color = 'black';
    $animation_name = 'heartbeat-stopped';

    if ($status === 'Resting') { $status_color = 'green'; $animation_name = 'heartbeat-healthy'; }
    elseif ($status === 'Pacing') { $status_color = 'green'; $animation_name = 'heartbeat-under-load'; }
    elseif ($status === 'Under Load') { $status_color = 'yellow'; $animation_name = 'heartbeat-working-hard'; }
    elseif ($status === 'Under Strain') { $status_color = 'red'; $animation_name = 'heartbeat-critical'; }

    $min_interval = (int) get_option('dfehc_min_interval', 15);
    $max_interval = (int) get_option('dfehc_max_interval', 300);
    $max_response_time = (float) get_option('dfehc_max_response_time', 5.0);
    $ema_alpha = (float) get_option('dfehc_ema_alpha', 0.35);

    $min_interval = max(1, $min_interval);
    $max_interval = max($min_interval, $max_interval);
    $max_response_time = max(0.1, $max_response_time);
    $ema_alpha = max(0.01, min(1.0, $ema_alpha));

    $glow_rgb = '0, 0, 0';
    if ($status_color === 'green') $glow_rgb = '0, 204, 0';
    elseif ($status_color === 'yellow') $glow_rgb = '255, 204, 0';
    elseif ($status_color === 'red') $glow_rgb = '204, 0, 0';

    $server_load_text = '';
    if (is_numeric($server_load)) {
        $server_load_text = (string) round((float) $server_load, 2);
    } elseif (is_scalar($server_load) && $server_load !== null) {
        $server_load_text = (string) $server_load;
    } else {
        $server_load_text = function_exists('wp_json_encode') ? (string) wp_json_encode($server_load) : (string) json_encode($server_load);
    }
    $server_load_text = sanitize_text_field($server_load_text);

    $response_seconds = 0.0;
    if (is_numeric($server_response_time)) {
        $response_seconds = (float) $server_response_time;
    } elseif (is_array($server_response_time)) {
        if (isset($server_response_time['main_response_ms']) && is_numeric($server_response_time['main_response_ms'])) {
            $response_seconds = ((float) $server_response_time['main_response_ms']) / 1000.0;
        } elseif (isset($server_response_time['response_time']) && is_numeric($server_response_time['response_time'])) {
            $response_seconds = (float) $server_response_time['response_time'];
        } elseif (isset($server_response_time['response_ms']) && is_numeric($server_response_time['response_ms'])) {
            $response_seconds = ((float) $server_response_time['response_ms']) / 1000.0;
        }
    } elseif (is_object($server_response_time)) {
        $arr = (array) $server_response_time;
        if (isset($arr['main_response_ms']) && is_numeric($arr['main_response_ms'])) {
            $response_seconds = ((float) $arr['main_response_ms']) / 1000.0;
        } elseif (isset($arr['response_time']) && is_numeric($arr['response_time'])) {
            $response_seconds = (float) $arr['response_time'];
        } elseif (isset($arr['response_ms']) && is_numeric($arr['response_ms'])) {
            $response_seconds = ((float) $arr['response_ms']) / 1000.0;
        }
    }
    $response_seconds = max(0.0, $response_seconds);
    $response_display = (string) round($response_seconds, 3);

    $recommended_interval_text = (string) round((float) $recommended_interval, 2);

    $ajax_url = function_exists('admin_url') ? (string) admin_url('admin-ajax.php') : '';
    $ajax_nonce = function_exists('wp_create_nonce') ? (string) wp_create_nonce('dfehc_widget_stats') : '';

    ob_start();

    echo "<style>
        .heartbeat { animation: {$animation_name} 1s linear infinite; }

        @keyframes heartbeat-healthy { 0%, 100% { box-shadow: 0 0 5px {$status_color}, 0 0 10px {$status_color}; } 50% { box-shadow: 0 0 30px {$status_color}, 0 0 50px {$status_color}; } }
        @keyframes heartbeat-under-load { 0%, 50%, 100% { box-shadow: 0 0 5px {$status_color}, 0 0 10px {$status_color}; } 25%, 75% { box-shadow: 0 0 30px {$status_color}, 0 0 50px {$status_color}; } }
        @keyframes heartbeat-working-hard { 0%, 100% { box-shadow: 0 0 5px {$status_color}, 0 0 10px {$status_color}; } 50% { box-shadow: 0 0 30px {$status_color}, 0 0 50px {$status_color}; } }
        @keyframes heartbeat-critical { 0%, 50%, 100% { box-shadow: 0 0 5px {$status_color}, 0 0 10px {$status_color}; } 25%, 75% { box-shadow: 0 0 30px {$status_color}, 0 0 50px {$status_color}; } }
        @keyframes heartbeat-stopped { 0%, 100% { box-shadow: 0 0 5px {$status_color}, 0 0 10px {$status_color}; } }

        .dfehc-pulse-wrap { --size: 30px; --pulse-color: {$status_color}; --glow-rgb: {$glow_rgb}; display:flex; justify-content:center; align-items:center; margin:20px auto; width:var(--size); height:var(--size); }
        .dfehc-pulse { position:relative; width:var(--size); height:var(--size); border-radius:50%; background:var(--pulse-color); overflow:hidden; animation:dfehc-heartbeat 2s ease-in-out infinite, {$animation_name} 2s linear infinite; }
        .dfehc-pulse::before { content:''; position:absolute; top:50%; left:50%; width:190%; height:190%; transform:translate(-50%,-50%) scale(0.9); border-radius:50%; background:radial-gradient(circle, rgba(var(--glow-rgb),0.22) 0%, rgba(var(--glow-rgb),0.10) 34%, rgba(var(--glow-rgb),0) 70%); pointer-events:none; opacity:0.55; filter:blur(0.2px); animation:dfehc-glow-sync 2s ease-in-out infinite; }
        .dfehc-spark { position:absolute; top:50%; left:50%; width:4%; height:4%; border-radius:50%; background:radial-gradient(circle at center, #ffffff 0%, #eaffea 70%, #d4ffd4 100%); box-shadow:0 0 12px 2px #ffffff, 0 0 24px 6px rgba(var(--glow-rgb),0.35); transform-origin:0 0; animation:dfehc-orbit var(--duration,6s) linear forwards, dfehc-flash var(--duration,6s) ease-in-out forwards; pointer-events:none; }

        @keyframes dfehc-heartbeat { 0%{transform:scale(1);} 25%{transform:scale(1.1);} 50%{transform:scale(0.96);} 75%{transform:scale(1.05);} 100%{transform:scale(1);} }
        @keyframes dfehc-glow-sync { 0%{transform:translate(-50%,-50%) scale(0.92); opacity:0.35;} 18%{transform:translate(-50%,-50%) scale(1.18); opacity:0.90;} 34%{transform:translate(-50%,-50%) scale(1.02); opacity:0.55;} 64%{transform:translate(-50%,-50%) scale(1.12); opacity:0.78;} 100%{transform:translate(-50%,-50%) scale(0.92); opacity:0.35;} }
        @keyframes dfehc-orbit { from{transform:rotate(0deg) translate(var(--radius,0px)) scale(1);} to{transform:rotate(360deg) translate(var(--radius,0px)) scale(1);} }
        @keyframes dfehc-flash { 0%,95%,100%{opacity:0; box-shadow:0 0 8px 2px #ffffff, 0 0 16px 4px rgba(var(--glow-rgb),0.30);} 45%,55%{opacity:0.7; box-shadow:0 0 14px 3px #ffffff, 0 0 24px 6px rgba(var(--glow-rgb),0.45);} }

        .dfehc-matrix { width:100%; max-width:520px; margin:14px auto 0; border-radius:10px; border:1px solid rgba(0,0,0,0.10); background:rgba(255,255,255,0.75); box-shadow:0 8px 20px rgba(0,0,0,0.06); overflow:hidden; cursor:pointer; user-select:none; -webkit-tap-highlight-color:transparent; transition:transform 120ms ease, box-shadow 120ms ease, background 120ms ease, opacity 120ms ease; }
        .dfehc-matrix:hover { box-shadow:0 10px 24px rgba(0,0,0,0.08); transform:translateY(-1px); }
        .dfehc-matrix:active { transform:translateY(0px) scale(0.99); opacity:0.98; }
        .dfehc-matrix.is-loading { opacity:0.65; }
        .dfehc-matrix-inner { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0; }
        .dfehc-matrix-cell { padding:10px 12px; text-align:center; }
        .dfehc-matrix-cell + .dfehc-matrix-cell { border-left:1px solid rgba(0,0,0,0.08); }
        .dfehc-pulse.dfehc-ack { animation-duration:1.35s, 1.35s; }
        .dfehc-matrix-label { font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:rgba(0,0,0,0.55); line-height:1.2; margin-bottom:6px; }
        .dfehc-matrix-value { font-size:16px; font-weight:700; color:#111; line-height:1.2; font-variant-numeric:tabular-nums; word-break:break-word; }
        .dfehc-matrix-unit { font-size:11px; font-weight:600; color:rgba(0,0,0,0.55); margin-left:6px; }
        @media (max-width:520px){ .dfehc-matrix-inner{grid-template-columns:1fr;} .dfehc-matrix-cell + .dfehc-matrix-cell{border-left:none; border-top:1px solid rgba(0,0,0,0.08);} }
        @media (prefers-reduced-motion: reduce){ .dfehc-pulse, .dfehc-pulse::before, .dfehc-spark{animation:none !important;} .dfehc-matrix{transition:none !important;} }
    </style>";

    echo "<div class='dfehc-pulse-wrap'><div class='dfehc-pulse' aria-label='Heartbeat status indicator' style='margin-top: 20px;'></div></div>";
    echo "<p style='text-align: center; font-size: 24px; margin-top: 20px;'>Heartbeat: <strong>" . esc_html($status) . "</strong></p>";

    echo "<div class='dfehc-matrix' id='dfehc-matrix' role='button' tabindex='0' aria-label='Refresh current heartbeat metrics' style='margin-bottom: 20px;'>
            <div class='dfehc-matrix-inner'>
                <div class='dfehc-matrix-cell'>
                    <div class='dfehc-matrix-label'>Current Load</div>
                    <div class='dfehc-matrix-value' id='dfehc-stat-load'>" . esc_html($server_load_text) . "</div>
                </div>
                <div class='dfehc-matrix-cell'>
                    <div class='dfehc-matrix-label'>Response Time</div>
                    <div class='dfehc-matrix-value'><span id='dfehc-stat-rt'>" . esc_html((string) $response_display) . "</span><span class='dfehc-matrix-unit'>s</span></div>
                </div>
                <div class='dfehc-matrix-cell'>
                    <div class='dfehc-matrix-label'>Interval</div>
                    <div class='dfehc-matrix-value'><span id='dfehc-stat-int'>" . esc_html((string) $recommended_interval_text) . "</span><span class='dfehc-matrix-unit'>s</span></div>
                </div>
            </div>
          </div>";

    echo "<script>
(function() {
    var pulse = document.querySelector('.dfehc-pulse');
    var box = document.getElementById('dfehc-matrix');
    var elLoad = document.getElementById('dfehc-stat-load');
    var elRt = document.getElementById('dfehc-stat-rt');
    var elInt = document.getElementById('dfehc-stat-int');

    if (pulse) {
        pulse.style.cursor = 'pointer';
        pulse.setAttribute('role', 'button');
        pulse.setAttribute('tabindex', '0');
        pulse.setAttribute('aria-label', 'Refresh heartbeat animation');
    }

    window.DFEHC_METRICS = window.DFEHC_METRICS || {};
    window.DFEHC_METRICS.recommended_interval = " . wp_json_encode((float) $recommended_interval) . ";
    window.DFEHC_METRICS.server_response_time = " . wp_json_encode((float) $response_seconds) . ";
    window.DFEHC_METRICS.min_interval = " . wp_json_encode((int) $min_interval) . ";
    window.DFEHC_METRICS.max_interval = " . wp_json_encode((int) $max_interval) . ";
    window.DFEHC_METRICS.max_response_time = " . wp_json_encode((float) $max_response_time) . ";
    window.DFEHC_METRICS.ema_alpha = " . wp_json_encode((float) $ema_alpha) . ";

    function clamp(v, lo, hi) {
        v = Number(v);
        if (!Number.isFinite(v)) return lo;
        return Math.max(lo, Math.min(hi, v));
    }

    function getSeed() {
        try {
            if (window.crypto && window.crypto.getRandomValues) {
                var a = new Uint32Array(1);
                window.crypto.getRandomValues(a);
                return a[0] >>> 0;
            }
        } catch (e) {}
        return (Date.now() >>> 0) ^ ((Math.random() * 0xffffffff) >>> 0);
    }

    function mulberry32(seed) {
        var t = seed >>> 0;
        return function() {
            t += 0x6D2B79F5;
            var x = t;
            x = Math.imul(x ^ (x >>> 15), x | 1);
            x ^= x + Math.imul(x ^ (x >>> 7), x | 61);
            return ((x ^ (x >>> 14)) >>> 0) / 4294967296;
        };
    }

    var rng = mulberry32(getSeed());
    function rnd() { return rng(); }

    function jitterFactor(pct) {
        var p = clamp(pct, 0, 0.75);
        return 1 + ((rnd() * 2 - 1) * p);
    }

    var emaStress = null;
    function ema(next, alpha) {
        var a = clamp(alpha, 0.01, 1.0);
        if (emaStress === null) {
            emaStress = next;
            return emaStress;
        }
        emaStress = a * next + (1 - a) * emaStress;
        return emaStress;
    }

    function getMetrics() {
        var m = (window.DFEHC_METRICS && typeof window.DFEHC_METRICS === 'object') ? window.DFEHC_METRICS : {};
        var minInterval = clamp(m.min_interval != null ? m.min_interval : 15, 1, 3600);
        var maxInterval = clamp(m.max_interval != null ? m.max_interval : 300, minInterval, 36000);
        var recInterval = clamp(m.recommended_interval != null ? m.recommended_interval : maxInterval, minInterval, maxInterval);

        var rt = Number(m.server_response_time != null ? m.server_response_time : 0);
        if (!Number.isFinite(rt)) rt = 0;

        var maxRT = clamp(m.max_response_time != null ? m.max_response_time : 5.0, 0.1, 120);

        if (rt > (maxRT * 3) && rt <= 60000) rt = rt / 1000;
        rt = clamp(rt, 0, 600);

        return { minInterval: minInterval, maxInterval: maxInterval, recInterval: recInterval, rt: rt, maxRT: maxRT };
    }

    function computeStress() {
        var mm = getMetrics();
        var intervalNorm = (mm.recInterval - mm.minInterval) / Math.max(1e-9, (mm.maxInterval - mm.minInterval));
        intervalNorm = clamp(intervalNorm, 0, 1);
        var activity = clamp(1 - intervalNorm, 0, 1);
        var rtNorm = clamp(mm.rt / mm.maxRT, 0, 1);

        var raw = clamp((0.65 * activity) + (0.35 * rtNorm), 0, 1);
        var alpha = (window.DFEHC_METRICS && window.DFEHC_METRICS.ema_alpha != null) ? window.DFEHC_METRICS.ema_alpha : 0.35;
        var smoothed = ema(raw, alpha);

        return clamp(smoothed, 0, 1);
    }

    function pickSpawnDelayMs(stress) {
        var minMs = 900;
        var maxMs = 9500;
        var base = maxMs - (stress * (maxMs - 1800));
        return clamp(base * jitterFactor(0.18), minMs, maxMs);
    }

    function pickSparkDurationSec(stress) {
        var minS = 2.6;
        var maxS = 8.4;
        var base = maxS - (stress * (maxS - 3.2));
        return clamp(base * jitterFactor(0.12), minS, maxS);
    }

    function pickRadiusPx(stress) {
        var w = pulse ? (pulse.clientWidth || 30) : 30;
        var lo = 0.15;
        var hi = 0.35;
        var bias = lo + (hi - lo) * (0.25 + 0.75 * stress);
        var pct = clamp(bias * jitterFactor(0.10), lo, hi);
        return w * pct;
    }

    var sparkTimer = null;
    var firstTimer = null;
    var ackTimer = null;

    function clearTimers() {
        if (sparkTimer) { window.clearTimeout(sparkTimer); sparkTimer = null; }
        if (firstTimer) { window.clearTimeout(firstTimer); firstTimer = null; }
        if (ackTimer) { window.clearTimeout(ackTimer); ackTimer = null; }
    }

    function removeSparks() {
        if (!pulse) return;
        var sparks = pulse.querySelectorAll('.dfehc-spark');
        for (var i = 0; i < sparks.length; i++) {
            if (sparks[i] && sparks[i].parentNode) sparks[i].parentNode.removeChild(sparks[i]);
        }
    }

    function restartCssAnimations() {
        if (!pulse) return;
        var prev = pulse.style.animation;
        pulse.style.animation = 'none';
        pulse.offsetHeight;
        pulse.style.animation = prev || '';
    }

    function ackBeat() {
        if (!pulse) return;
        pulse.classList.add('dfehc-ack');
        if (ackTimer) window.clearTimeout(ackTimer);
        ackTimer = window.setTimeout(function() {
            if (pulse) pulse.classList.remove('dfehc-ack');
        }, 220);
    }

    function spawnSpark() {
        if (!pulse) return;

        var stress = computeStress();

        var spark = document.createElement('span');
        spark.className = 'dfehc-spark';

        var duration = pickSparkDurationSec(stress).toFixed(2) + 's';
        var radiusPx = pickRadiusPx(stress).toFixed(2) + 'px';

        spark.style.setProperty('--duration', duration);
        spark.style.setProperty('--radius', radiusPx);

        pulse.appendChild(spark);

        spark.addEventListener('animationend', function() {
            if (spark && spark.parentNode) spark.parentNode.removeChild(spark);
        });

        sparkTimer = window.setTimeout(spawnSpark, pickSpawnDelayMs(stress));
    }

    function startSparks() {
        if (!pulse) return;
        clearTimers();
        var s = computeStress();
        var firstDelay = clamp((800 + (1 - s) * 2400) * jitterFactor(0.22), 400, 4200);
        firstTimer = window.setTimeout(spawnSpark, firstDelay);
    }

    function refreshAnimation() {
        rng = mulberry32(getSeed());
        emaStress = null;
        removeSparks();
        restartCssAnimations();
        ackBeat();
        startSparks();
    }

    startSparks();

    if (pulse) {
        pulse.addEventListener('click', function() { refreshAnimation(); });
        pulse.addEventListener('keydown', function(e) {
            var k = e.key || e.keyCode;
            if (k === 'Enter' || k === ' ' || k === 13 || k === 32) {
                e.preventDefault();
                refreshAnimation();
            }
        });
    }

    var ajaxUrl = " . wp_json_encode($ajax_url) . ";
    var ajaxNonce = " . wp_json_encode($ajax_nonce) . ";
    var inFlight = false;

    function parseResponseSeconds(payload) {
        if (payload == null) return 0;
        if (typeof payload === 'number' && isFinite(payload)) return Math.max(0, payload);
        if (typeof payload === 'string') {
            var n = Number(payload);
            if (isFinite(n)) return Math.max(0, n);
            return 0;
        }
        if (typeof payload === 'object') {
            if (payload.main_response_ms != null && isFinite(Number(payload.main_response_ms))) return Math.max(0, Number(payload.main_response_ms) / 1000);
            if (payload.response_ms != null && isFinite(Number(payload.response_ms))) return Math.max(0, Number(payload.response_ms) / 1000);
            if (payload.response_time != null && isFinite(Number(payload.response_time))) return Math.max(0, Number(payload.response_time));
        }
        return 0;
    }

    function parseLoad(payload) {
        if (payload == null) return '';
        if (typeof payload === 'number' && isFinite(payload)) return String(Math.round(payload * 100) / 100);
        if (typeof payload === 'string') {
            var n = Number(payload);
            if (isFinite(n)) return String(Math.round(n * 100) / 100);
            return payload;
        }
        if (typeof payload === 'object') {
            if (payload.load != null && isFinite(Number(payload.load))) return String(Math.round(Number(payload.load) * 100) / 100);
            if (payload.server_load != null && isFinite(Number(payload.server_load))) return String(Math.round(Number(payload.server_load) * 100) / 100);
        }
        try { return JSON.stringify(payload); } catch (e) { return ''; }
    }

    function parseInterval(payload) {
        var n = Number(payload);
        if (isFinite(n)) return Math.max(0, n);
        return 0;
    }

    function updateUI(data) {
        if (elLoad && data && data.server_load != null) elLoad.textContent = parseLoad(data.server_load);
        if (elRt && data && data.server_response_time != null) {
            var s = parseResponseSeconds(data.server_response_time);
            elRt.textContent = (Math.round(s * 1000) / 1000).toFixed(3);
            window.DFEHC_METRICS.server_response_time = s;
        }
        if (elInt && data && data.recommended_interval != null) {
            var iv = parseInterval(data.recommended_interval);
            elInt.textContent = (Math.round(iv * 100) / 100).toFixed(2);
            window.DFEHC_METRICS.recommended_interval = iv;
        }
    }

    function refreshStats() {
        if (!ajaxUrl || inFlight) return;
        inFlight = true;
        if (box) box.classList.add('is-loading');

        var fd = new FormData();
        fd.append('action', 'dfehc_widget_refresh_stats');
        fd.append('_ajax_nonce', ajaxNonce);

        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(json) { if (json && json.success && json.data) updateUI(json.data); })
            .catch(function() {})
            .finally(function() {
                inFlight = false;
                if (box) box.classList.remove('is-loading');
            });
    }

    function onActivate(e) {
        if (e && e.type === 'keydown') {
            var k = e.key || e.keyCode;
            if (k !== 'Enter' && k !== ' ' && k !== 13 && k !== 32) return;
            e.preventDefault();
        }
        refreshStats();
    }

    if (box) {
        box.addEventListener('click', onActivate);
        box.addEventListener('keydown', onActivate);
    }
})();
</script>";

    $labels = [];
    $data = [];
    $timestamp = strtotime('-24 hours');
    $interval = 20 * 60;

    while ($timestamp <= time()) {
        $load_sum = 0.0;
        $count = 0;
        foreach ($load_logs as $log) {
            if (!is_array($log)) continue;
            $ts = isset($log['timestamp']) ? (int) $log['timestamp'] : 0;
            if ($ts >= $timestamp && $ts < ($timestamp + $interval)) {
                $load_sum += (isset($log['load']) && is_numeric($log['load'])) ? (float) $log['load'] : 0.0;
                $count++;
            }
        }
        $average_load = $count > 0 ? ($load_sum / $count) : 0.0;
        $labels[] = date('H:i', (int) $timestamp);
        $data[] = (float) $average_load;
        $timestamp += $interval;
    }

    echo '<canvas id="loadChart" style="width: 100%; height: 100%; display: block;"></canvas>';
    echo '
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var canvas = document.getElementById("loadChart");
        if (!canvas || !canvas.getContext || typeof Chart === "undefined") return;
        var ctx = canvas.getContext("2d");
        new Chart(ctx, {
            type: "line",
            data: {
                labels: ' . wp_json_encode(array_map('sanitize_text_field', $labels)) . ',
                datasets: [{
                    label: "Diastolic pressure",
                    data: ' . wp_json_encode(array_map('floatval', $data)) . ',
                    backgroundColor: "rgba(75,192,192,0.2)",
                    borderColor: "rgba(75,192,192,1)",
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        suggestedMax: 50,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    });
    </script>';

    $memo = ['html' => (string) ob_get_clean()];
    echo $memo['html'];
}

add_action('wp_ajax_dfehc_widget_refresh_stats', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    check_ajax_referer('dfehc_widget_stats');

    $cache_ttl = (int) apply_filters('dfehc_widget_refresh_cache_ttl', 3);
    if ($cache_ttl < 0) $cache_ttl = 0;
    if ($cache_ttl > 30) $cache_ttl = 30;

    $cache_key = 'dfehc_widget_refresh_cache';
    if ($cache_ttl > 0) {
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['server_load'], $cached['server_response_time'], $cached['recommended_interval'])) {
            wp_send_json_success($cached);
        }
    }

    $server_load = dfehc_get_server_load();
    $server_response_time = dfehc_get_server_response_time();
$recommended_interval = dfehc_get_recommended_interval_for_load((float) $server_load, (float) $response_seconds);

    $response_seconds = 0.0;
    if (is_numeric($server_response_time)) {
        $response_seconds = (float) $server_response_time;
    } elseif (is_array($server_response_time)) {
        if (isset($server_response_time['main_response_ms']) && is_numeric($server_response_time['main_response_ms'])) {
            $response_seconds = ((float) $server_response_time['main_response_ms']) / 1000.0;
        } elseif (isset($server_response_time['response_ms']) && is_numeric($server_response_time['response_ms'])) {
            $response_seconds = ((float) $server_response_time['response_ms']) / 1000.0;
        } elseif (isset($server_response_time['response_time']) && is_numeric($server_response_time['response_time'])) {
            $response_seconds = (float) $server_response_time['response_time'];
        }
    } elseif (is_object($server_response_time)) {
        $arr = (array) $server_response_time;
        if (isset($arr['main_response_ms']) && is_numeric($arr['main_response_ms'])) {
            $response_seconds = ((float) $arr['main_response_ms']) / 1000.0;
        } elseif (isset($arr['response_ms']) && is_numeric($arr['response_ms'])) {
            $response_seconds = ((float) $arr['response_ms']) / 1000.0;
        } elseif (isset($arr['response_time']) && is_numeric($arr['response_time'])) {
            $response_seconds = (float) $arr['response_time'];
        }
    }
    $response_seconds = max(0.0, $response_seconds);

    $safe_load = $server_load;
    if (!is_numeric($safe_load) && !is_string($safe_load)) $safe_load = '';

    $payload = [
        'server_load' => $safe_load,
        'server_response_time' => (float) $response_seconds,
        'recommended_interval' => (float) $recommended_interval,
    ];

    if ($cache_ttl > 0) {
        set_transient($cache_key, $payload, $cache_ttl);
    }

    wp_send_json_success($payload);
});

function dfehc_add_heartbeat_health_dashboard_widget() {
    wp_add_dashboard_widget('heartbeat_health_dashboard_widget', 'Dynamic Heartbeat Health Check', 'dfehc_heartbeat_health_dashboard_widget_function');
}
add_action('wp_dashboard_setup', 'dfehc_add_heartbeat_health_dashboard_widget');
