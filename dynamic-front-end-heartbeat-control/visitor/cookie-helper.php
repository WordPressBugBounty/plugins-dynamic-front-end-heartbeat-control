<?php
declare(strict_types=1);

if (!function_exists('dfehc_get_bot_pattern')) {
    function dfehc_get_bot_pattern(): string
    {
        static $pattern = null;
        if (is_string($pattern) && $pattern !== '') {
            return $pattern;
        }

        $sigs = (array) apply_filters('dfehc_bot_signatures', [
            'bot','crawl','crawler','slurp','spider','mediapartners','bingpreview',
            'yandex','duckduckbot','baiduspider','sogou','exabot',
            'facebot','facebookexternalhit','ia_archiver',
            'GPTBot','ChatGPT-User','OAI-SearchBot',
            'ClaudeBot','Claude-Web','anthropic-ai',
            'PerplexityBot','Perplexity-User',
            'Meta-ExternalAgent','DuckAssistBot',
            'Bytespider','Google-Extended','GoogleOther',
            'Applebot-Extended','Amazonbot','CCBot',
            'cohere-ai','ImagesiftBot','Diffbot','YouBot','GeminiBot',
            'MistralAI-User','xAI-GrokBot','AI2Bot',
        ]);

        $clean = [];
        foreach ($sigs as $s) {
            if (!is_string($s)) {
                continue;
            }
            $s = trim($s);
            if ($s === '') {
                continue;
            }
            $clean[$s] = true;
        }

        if (!$clean) {
            $pattern = '/$^/';
            return $pattern;
        }

        $tokens = [];
        foreach (array_keys($clean) as $s) {
            $tokens[] = preg_quote($s, '/');
        }

        $pattern = '/(' . implode('|', $tokens) . ')/i';
        return $pattern;
    }
}

if (!function_exists('dfehc_blog_id')) {
    function dfehc_blog_id(): int
    {
        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    }
}

if (!function_exists('dfehc_host_token')) {
    function dfehc_host_token(): string
    {
        static $t = '';
        if ($t !== '') {
            return $t;
        }

        $url = '';
        if (defined('WP_HOME') && is_string(WP_HOME) && WP_HOME !== '') {
            $url = WP_HOME;
        } elseif (function_exists('home_url')) {
            $url = (string) home_url();
        }

        $host = '';
        if ($url !== '' && function_exists('wp_parse_url')) {
            $parts = wp_parse_url($url);
            if (is_array($parts) && isset($parts['host']) && is_string($parts['host'])) {
                $host = $parts['host'];
            }
        }

        if ($host === '') {
            $host = @php_uname('n') ?: 'unknown';
        }

        $salt = defined('DB_NAME') ? (string) DB_NAME : '';
        $t = substr(md5($host . $salt), 0, 10);
        return $t;
    }
}

if (!function_exists('dfehc_scoped_key')) {
    function dfehc_scoped_key(string $base): string
    {
        return $base . '_' . dfehc_blog_id() . '_' . dfehc_host_token();
    }
}

if (!function_exists('dfehc_ip_in_cidr')) {
    function dfehc_ip_in_cidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP) && $ip === $cidr;
        }

        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $subnet = (string) $parts[0];
        $mask = (int) $parts[1];

        $ip_bin = @inet_pton($ip);
        $sub_bin = @inet_pton($subnet);
        if ($ip_bin === false || $sub_bin === false) {
            return false;
        }
        if (strlen($ip_bin) !== strlen($sub_bin)) {
            return false;
        }

        $len = strlen($ip_bin);
        $max_bits = $len * 8;
        if ($mask < 0 || $mask > $max_bits) {
            return false;
        }

        $bytes = intdiv($mask, 8);
        $bits = $mask % 8;

        if ($bytes && substr($ip_bin, 0, $bytes) !== substr($sub_bin, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $ip_byte = ord($ip_bin[$bytes]);
        $sub_byte = ord($sub_bin[$bytes]);
        $mask_byte = (0xFF << (8 - $bits)) & 0xFF;

        return ($ip_byte & $mask_byte) === ($sub_byte & $mask_byte);
    }
}

if (!function_exists('dfehc_select_client_ip_from_xff')) {
    function dfehc_select_client_ip_from_xff(string $xff, array $trustedCidrs): ?string
    {
        $parts = explode(',', $xff);
        $candidates = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $candidates[] = $p;
            }
        }

        $ipNonTrusted = null;

        foreach ($candidates as $ip) {
            $ip = (string) preg_replace('/%[0-9A-Za-z.\-]+$/', '', $ip);
            $ip = trim($ip);

            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $isTrustedHop = false;
            foreach ($trustedCidrs as $cidr) {
                if (is_string($cidr) && $cidr !== '' && dfehc_ip_in_cidr($ip, $cidr)) {
                    $isTrustedHop = true;
                    break;
                }
            }
            if ($isTrustedHop) {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }

            if ($ipNonTrusted === null) {
                $ipNonTrusted = $ip;
            }
        }

        if ($ipNonTrusted !== null) {
            return $ipNonTrusted;
        }

        if ($candidates) {
            $last = trim((string) end($candidates));
            if ($last !== '' && filter_var($last, FILTER_VALIDATE_IP)) {
                return $last;
            }
        }

        return null;
    }
}

if (!function_exists('dfehc_client_ip')) {
    function dfehc_client_ip(): string
    {
        static $memo = null;
        if (is_string($memo) && $memo !== '') {
            return $memo;
        }

        $remote_raw = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        $remote_raw = trim($remote_raw);
        $remote = ($remote_raw !== '' && filter_var($remote_raw, FILTER_VALIDATE_IP)) ? $remote_raw : '';

        $trustedCidrs = (array) apply_filters('dfehc_trusted_proxies', []);
        $trusted = [];
        foreach ($trustedCidrs as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v !== '') {
                $trusted[] = $v;
            }
        }

        $isTrustedRemote = false;
        if ($remote !== '' && $trusted) {
            foreach ($trusted as $cidr) {
                if (dfehc_ip_in_cidr($remote, $cidr)) {
                    $isTrustedRemote = true;
                    break;
                }
            }
        }

        $cand = null;

        if ($isTrustedRemote) {
            $headers = (array) apply_filters('dfehc_proxy_ip_headers', [
                'HTTP_CF_CONNECTING_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
            ]);

            $hdrs = [];
            foreach ($headers as $h) {
                if (!is_string($h)) {
                    continue;
                }
                $h = trim($h);
                if ($h !== '') {
                    $hdrs[] = $h;
                }
            }

            foreach ($hdrs as $h) {
                if (empty($_SERVER[$h])) {
                    continue;
                }

                $raw = trim((string) wp_unslash($_SERVER[$h]));
                if ($raw === '') {
                    continue;
                }

                if ($h === 'HTTP_X_FORWARDED_FOR') {
                    $picked = dfehc_select_client_ip_from_xff($raw, $trusted);
                    if ($picked !== null) {
                        $cand = $picked;
                        break;
                    }
                    continue;
                }

                $raw = (string) preg_replace('/%[0-9A-Za-z.\-]+$/', '', $raw);
                $raw = trim($raw);

                if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_IP)) {
                    $cand = $raw;
                    break;
                }
            }
        }

        if ($cand === null) {
            $cand = ($remote !== '' ? $remote : '0.0.0.0');
        }

        $memo = (string) apply_filters('dfehc_client_ip', $cand);
        if ($memo === '') {
            $memo = '0.0.0.0';
        }
        return $memo;
    }
}

if (!function_exists('dfehc_is_request_bot')) {
    function dfehc_is_request_bot(): bool
    {
        static $cached = null;
        static $server_ctx = null;

        if ($server_ctx === null) {
            $server_ctx = [
                'REMOTE_ADDR'     => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                'HTTP_USER_AGENT' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
                'HTTP_ACCEPT'     => isset($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_ACCEPT'])) : '',
                'HTTP_SEC_CH_UA'  => isset($_SERVER['HTTP_SEC_CH_UA']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_SEC_CH_UA'])) : '',
                'REQUEST_URI'     => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string) wp_unslash($_SERVER['REQUEST_URI'])) : '',
                'REQUEST_METHOD'  => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field((string) wp_unslash($_SERVER['REQUEST_METHOD'])) : '',
            ];
        }

        if ($cached !== null) {
            return (bool) apply_filters('dfehc_is_request_bot', (bool) $cached, $server_ctx);
        }

        $ua = (string) ($server_ctx['HTTP_USER_AGENT'] ?? '');

        $treatEmptyUaAsBot = (bool) apply_filters('dfehc_empty_ua_is_bot', true, $server_ctx);
        if ($ua === '') {
            $cached = $treatEmptyUaAsBot;
            return (bool) apply_filters('dfehc_is_request_bot', (bool) $cached, $server_ctx);
        }

        if (preg_match(dfehc_get_bot_pattern(), $ua)) {
            $cached = true;
            return (bool) apply_filters('dfehc_is_request_bot', true, $server_ctx);
        }

        $accept = (string) ($server_ctx['HTTP_ACCEPT'] ?? '');
        $sec_ch = (string) ($server_ctx['HTTP_SEC_CH_UA'] ?? '');

        $strictHeuristic = (bool) apply_filters('dfehc_bot_accept_heuristic_enabled', true, $server_ctx);
        if ($strictHeuristic && ($accept === '' || stripos($accept, 'text/html') === false) && $sec_ch === '') {
            $cached = true;
            return (bool) apply_filters('dfehc_is_request_bot', true, $server_ctx);
        }

        $ip = dfehc_client_ip();
        $group = (string) apply_filters('dfehc_cache_group', defined('DFEHC_CACHE_GROUP') ? DFEHC_CACHE_GROUP : 'dfehc');

        $badBase = (string) apply_filters('dfehc_bad_ip_key_base', 'dfehc_bad_ip_');
        $badKey = dfehc_scoped_key($badBase) . hash('sha256', $ip ?: 'none');
        $badKeyLocal = $badKey . '_t';

        if ($ip && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_get')) {
            if (wp_cache_get($badKey, $group)) {
                $cached = true;
                return (bool) apply_filters('dfehc_is_request_bot', true, $server_ctx);
            }
        }

        if ($ip && get_transient($badKeyLocal)) {
            $cached = true;
            return (bool) apply_filters('dfehc_is_request_bot', true, $server_ctx);
        }

        $cached = false;
        return (bool) apply_filters('dfehc_is_request_bot', false, $server_ctx);
    }
}

if (!function_exists('dfehc_should_set_cookie')) {
    function dfehc_should_set_cookie(): bool
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return false;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }
        if (function_exists('is_admin') && is_admin()) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return false;
        }

        if (function_exists('rest_get_url_prefix')) {
            $p = rest_get_url_prefix();
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            $uri = (string) preg_replace('#\?.*$#', '', $uri);
            $uri = ltrim($uri, '/');

            $p = is_string($p) ? trim($p, " \t\n\r\0\x0B/") : '';
            if ($p && strpos($uri, $p . '/') === 0) {
                return false;
            }
        }

        if (function_exists('is_feed') && is_feed()) {
            return false;
        }
        if (function_exists('is_robots') && is_robots()) {
            return false;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) wp_unslash($_SERVER['REQUEST_METHOD']) : '';
        $method = strtoupper(trim($method));
        if ($method !== '' && $method !== 'GET') {
            return false;
        }

        return true;
    }
}

if (!function_exists('dfehc_cache_increment')) {
    function dfehc_cache_increment(string $key, string $group, int $ttl): int
    {
        static $memo = [];

        $ttl = max(10, (int) $ttl);

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_incr')) {
            if (function_exists('wp_cache_add')) {
                wp_cache_add($key, 0, $group, $ttl);
            }
            $value = wp_cache_incr($key, 1, $group);
            if ($value === false) {
                if (function_exists('wp_cache_set')) {
                    wp_cache_set($key, 1, $group, $ttl);
                }
                return 1;
            }
            return (int) $value;
        }

        if (isset($memo[$key]) && is_numeric($memo[$key])) {
            $memo[$key] = (int) $memo[$key] + 1;
            if (function_exists('dfehc_set_transient_noautoload')) {
                dfehc_set_transient_noautoload($key, (int) $memo[$key], $ttl);
            } else {
                set_transient($key, (int) $memo[$key], $ttl);
            }
            return (int) $memo[$key];
        }

        $cur = get_transient($key);
        $cur = is_numeric($cur) ? (int) $cur : 0;
        $cur++;
        $memo[$key] = $cur;

        if (function_exists('dfehc_set_transient_noautoload')) {
            dfehc_set_transient_noautoload($key, $cur, $ttl);
        } else {
            set_transient($key, $cur, $ttl);
        }

        return (int) $cur;
    }
}

if (!function_exists('dfehc_should_count_visitor_now')) {
    function dfehc_should_count_visitor_now(bool $shouldRefresh, bool $hadCookie, array $ctx): bool
    {
        $mode = (string) apply_filters('dfehc_visitor_count_mode', 'refresh_only', $ctx);
        $mode = strtolower(trim($mode));

        if ($mode === 'always') {
            return true;
        }

        if ($mode === 'refresh_only') {
            return $shouldRefresh || !$hadCookie;
        }

        if ($mode === 'sample') {
            $rate = (float) apply_filters('dfehc_visitor_count_sample_rate', 0.05, $ctx);
            if (!is_finite($rate) || $rate <= 0.0) {
                return false;
            }
            if ($rate >= 1.0) {
                return true;
            }
            $roll = function_exists('wp_rand') ? (int) wp_rand(0, 1000000) : (int) (time() % 1000001);
            return ((float) $roll / 1000000.0) < $rate;
        }

        return $shouldRefresh || !$hadCookie;
    }
}

if (!function_exists('dfehc_set_user_cookie')) {
    function dfehc_set_user_cookie(): void
    {
        if (!dfehc_should_set_cookie()) {
            return;
        }
        if (dfehc_is_request_bot()) {
            return;
        }

        $ip = dfehc_client_ip();
        $group = (string) apply_filters('dfehc_cache_group', defined('DFEHC_CACHE_GROUP') ? DFEHC_CACHE_GROUP : 'dfehc');

        $maxRPM = (int) apply_filters('dfehc_max_rpm', 300);
        $maxRPM = max(1, $maxRPM);

        $badIpTtl = (int) apply_filters('dfehc_bad_ip_ttl', defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);
        $badIpTtl = max(60, $badIpTtl);

        $name = (string) apply_filters('dfehc_cookie_name', 'dfehc_user');
        $lifetime = (int) apply_filters('dfehc_cookie_lifetime', 400);
        $lifetime = max(60, min(86400, $lifetime));

        $path = (string) apply_filters('dfehc_cookie_path', defined('COOKIEPATH') ? COOKIEPATH : '/');

        $home = function_exists('home_url') ? (string) home_url('/') : '';
        $host = '';
        if ($home !== '' && function_exists('wp_parse_url')) {
            $parsed = (array) wp_parse_url($home);
            $host = !empty($parsed['host']) ? (string) $parsed['host'] : '';
        }

        $isIpHost = $host && (filter_var($host, FILTER_VALIDATE_IP) !== false);
        $domainDefault = $isIpHost ? '' : ($host ?: (defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : ''));
        $domain = (string) apply_filters('dfehc_cookie_domain', $domainDefault);

        $sameSite = (string) apply_filters('dfehc_cookie_samesite', 'Lax');
        $map = ['lax' => 'Lax', 'strict' => 'Strict', 'none' => 'None'];
        $sameSiteUpper = $map[strtolower($sameSite)] ?? 'Lax';

        $secure = (function_exists('is_ssl') && is_ssl()) || $sameSiteUpper === 'None';
        if ($sameSiteUpper === 'None' && !$secure) {
            $sameSiteUpper = 'Lax';
        }

        $httpOnly = true;

        $nowTs = isset($_SERVER['REQUEST_TIME']) ? (int) wp_unslash($_SERVER['REQUEST_TIME']) : time();

        $existing = isset($_COOKIE[$name]) ? (string) wp_unslash($_COOKIE[$name]) : '';
        $existing = sanitize_text_field($existing);

        $val = $existing;
        if (!preg_match('/^[A-Fa-f0-9]{32}$/', (string) $val)) {
            $val = '';
            if (function_exists('random_bytes')) {
                try {
                    $val = bin2hex(random_bytes(16));
                } catch (\Throwable $e) {
                    $val = '';
                }
            }
            if ($val === '' || !preg_match('/^[A-Fa-f0-9]{32}$/', $val)) {
                $seed = (string) (function_exists('wp_rand') ? wp_rand() : mt_rand()) . '|' . $nowTs . '|' . dfehc_host_token() . '|' . uniqid('', true);
                $val = md5($seed);
            }
        }

        $refreshPct = (int) apply_filters('dfehc_cookie_refresh_percent', 5);
        $refreshPct = max(0, min(99, $refreshPct));

        $roll = function_exists('wp_rand') ? (int) wp_rand(0, 99) : (int) (time() % 100);
        $hadCookie = isset($_COOKIE[$name]);
        $shouldRefresh = !$hadCookie || ($roll < $refreshPct);

        $ctx = [
            'ip' => $ip,
            'cookie_name' => $name,
            'had_cookie' => $hadCookie ? 1 : 0,
            'should_refresh' => $shouldRefresh ? 1 : 0,
            'lifetime' => $lifetime,
        ];

        if ($ip) {
            $rpmGateMode = (string) apply_filters('dfehc_rpm_mode', 'refresh_only', $ctx);
            $rpmGateMode = strtolower(trim($rpmGateMode));
            $doRpm = ($rpmGateMode === 'always') ? true : ($shouldRefresh || !$hadCookie);

            if ($doRpm) {
                $rpmKeyBase = (string) apply_filters('dfehc_ip_rpm_key_base', 'dfehc_iprpm_', $ctx);
                $rpmKey = dfehc_scoped_key($rpmKeyBase) . hash('sha256', $ip);
                $rpmTtl = 60 + (function_exists('wp_rand') ? (int) wp_rand(0, 29) : (int) (time() % 30));
                $rpm = dfehc_cache_increment($rpmKey, $group, $rpmTtl);

                if ($rpm > $maxRPM) {
                    $badBase = (string) apply_filters('dfehc_bad_ip_key_base', 'dfehc_bad_ip_', $ctx);
                    $badKey = dfehc_scoped_key($badBase) . hash('sha256', $ip);
                    if (function_exists('wp_cache_set')) {
                        wp_cache_set($badKey, 1, $group, $badIpTtl);
                    }
                    if (function_exists('dfehc_set_transient_noautoload')) {
                        dfehc_set_transient_noautoload($badKey . '_t', 1, $badIpTtl);
                    } else {
                        set_transient($badKey . '_t', 1, $badIpTtl);
                    }
                    if (function_exists('wp_cache_delete')) {
                        wp_cache_delete($rpmKey, $group);
                    }
                    return;
                }
            }
        }

        if ($shouldRefresh && !headers_sent()) {
            $expires = $nowTs + $lifetime;

            if (PHP_VERSION_ID >= 70300) {
                setcookie($name, $val, [
                    'expires'  => $expires,
                    'path'     => $path,
                    'domain'   => $domain ?: null,
                    'secure'   => $secure,
                    'httponly' => $httpOnly,
                    'samesite' => $sameSiteUpper,
                ]);
            } else {
                header(sprintf(
                    'Set-Cookie: %s=%s; Expires=%s; Path=%s%s%s; HttpOnly; SameSite=%s',
                    rawurlencode($name),
                    rawurlencode($val),
                    gmdate('D, d-M-Y H:i:s T', $expires),
                    $path,
                    $domain ? '; Domain=' . $domain : '',
                    $secure ? '; Secure' : '',
                    $sameSiteUpper
                ), false);
            }
        }

        $jitterMax = (int) apply_filters('dfehc_visitor_ttl_jitter', 30);
        $jitterMax = max(0, min(300, $jitterMax));
        $jitter = $jitterMax > 0 ? (function_exists('wp_rand') ? (int) wp_rand(0, $jitterMax) : (int) (time() % ($jitterMax + 1))) : 0;

        $visitorKeyBase = (string) apply_filters('dfehc_total_visitors_key_base', 'dfehc_total_visitors', $ctx);
        $visitorKey = dfehc_scoped_key($visitorKeyBase);

        $shouldCount = dfehc_should_count_visitor_now($shouldRefresh, $hadCookie, $ctx);
        $shouldCount = (bool) apply_filters('dfehc_should_count_visitor', $shouldCount, $ctx);

        if ($shouldCount) {
            dfehc_cache_increment($visitorKey, $group, $lifetime + $jitter);
        }
    }
}

add_action('send_headers', 'dfehc_set_user_cookie', 1);
