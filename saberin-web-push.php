<?php
/**
 * Plugin Name: Saberin Web Push
 * Description: Web Push subscription management and sending for Saberin PWA.
 * Version: 1.4.0
 * Author: Saberin
 */

if (!defined('ABSPATH')) exit;

define('SABERIN_WEB_PUSH_VERSION', '1.4.0');
define('SABERIN_WEB_PUSH_DB_VERSION', '1.4.0');
define('SABERIN_WEB_PUSH_TABLE', $GLOBALS['wpdb']->prefix . 'saberin_push_subscriptions');
define('SABERIN_WEB_PUSH_STATUS_TABLE', $GLOBALS['wpdb']->prefix . 'saberin_push_status');

/* ------------------------------------------------------------------ */
/*  Database                                                            */
/* ------------------------------------------------------------------ */

function saberin_web_push_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $table = SABERIN_WEB_PUSH_TABLE;
    $sql1 = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        user_agent VARCHAR(500) DEFAULT '',
        device_label VARCHAR(120) DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id)
    ) {$charset};";

    $status_table = SABERIN_WEB_PUSH_STATUS_TABLE;
    $sql2 = "CREATE TABLE {$status_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY user_id (user_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);

    // Manual column add for older installs where dbDelta may miss ALTER
    $cols = $wpdb->get_col("DESCRIBE {$table}", 0);
    if ($cols && !in_array('user_agent', $cols, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN user_agent VARCHAR(500) DEFAULT '' AFTER auth");
    }
    if ($cols && !in_array('device_label', $cols, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN device_label VARCHAR(120) DEFAULT '' AFTER user_agent");
    }

    if (!get_option('saberin_web_push_vapid_public') || !get_option('saberin_web_push_vapid_private')) {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($key) {
            $details = openssl_pkey_get_details($key);
            if (!empty($details['ec']['x']) && !empty($details['ec']['y'])) {
                $public_raw  = "\x04" . $details['ec']['x'] . $details['ec']['y'];
                $private_raw = $details['ec']['d'];
                update_option('saberin_web_push_vapid_public',  saberin_web_push_b64url($public_raw));
                update_option('saberin_web_push_vapid_private', saberin_web_push_b64url($private_raw));
            }
        }
    }

    update_option('saberin_web_push_db_version', SABERIN_WEB_PUSH_DB_VERSION);
}
register_activation_hook(__FILE__, 'saberin_web_push_create_tables');

add_action('plugins_loaded', function () {
    if (get_option('saberin_web_push_db_version') !== SABERIN_WEB_PUSH_DB_VERSION) {
        saberin_web_push_create_tables();
    }
});

/* ------------------------------------------------------------------ */
/*  Helpers                                                             */
/* ------------------------------------------------------------------ */

function saberin_web_push_b64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function saberin_web_push_b64url_decode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function saberin_web_push_rest_permission() {
    return is_user_logged_in();
}

/**
 * Parse User-Agent into a short Persian-friendly device label.
 */
function saberin_web_push_device_label_from_ua($ua) {
    $ua = (string) $ua;
    if ($ua === '') {
        return 'دستگاه ناشناس';
    }

    $os = 'سیستم‌عامل نامشخص';
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = preg_match('/iPad/i', $ua) ? 'آیپد' : 'آیفون';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'اندروید';
        if (preg_match('/Mobile/i', $ua)) {
            $os = 'اندروید (موبایل)';
        } else {
            $os = 'اندروید (تبلت)';
        }
    } elseif (preg_match('/Windows NT/i', $ua)) {
        $os = 'ویندوز';
    } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
        $os = 'مک';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'لینوکس';
    } elseif (preg_match('/CrOS/i', $ua)) {
        $os = 'کروم‌بوک';
    }

    $browser = 'مرورگر';
    if (preg_match('/Edg\//i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/OPR\/|Opera/i', $ua)) {
        $browser = 'Opera';
    } elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Edg\//i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome\//i', $ua)) {
        $browser = 'Safari';
    } elseif (preg_match('/Firefox\//i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/SamsungBrowser/i', $ua)) {
        $browser = 'Samsung Internet';
    }

    // Detect if likely installed as PWA (rough)
    $pwa = '';
    if (preg_match('/wv\)|WebView/i', $ua)) {
        $pwa = ' WebView';
    }

    return trim($os . ' · ' . $browser . $pwa);
}

function saberin_web_push_endpoint_provider($endpoint) {
    if (strpos($endpoint, 'fcm.googleapis.com') !== false || strpos($endpoint, 'android.googleapis.com') !== false) {
        return 'FCM (گوگل)';
    }
    if (strpos($endpoint, 'wns.windows.com') !== false) {
        return 'WNS (مایکروسافت)';
    }
    if (strpos($endpoint, 'updates.push.services.mozilla.com') !== false || strpos($endpoint, 'push.services.mozilla.com') !== false) {
        return 'Mozilla';
    }
    if (strpos($endpoint, 'web.push.apple.com') !== false) {
        return 'Apple';
    }
    return 'سایر';
}

/* ------------------------------------------------------------------ */
/*  REST API                                                            */
/* ------------------------------------------------------------------ */

add_action('rest_api_init', function () {
    register_rest_route('saberin/v1', '/push/subscribe', [
        'methods'             => 'POST',
        'callback'            => 'saberin_web_push_subscribe',
        'permission_callback' => 'saberin_web_push_rest_permission',
    ]);

    register_rest_route('saberin/v1', '/push/unsubscribe', [
        'methods'             => 'POST',
        'callback'            => 'saberin_web_push_unsubscribe',
        'permission_callback' => 'saberin_web_push_rest_permission',
    ]);

    register_rest_route('saberin/v1', '/push/status', [
        'methods'             => 'GET',
        'callback'            => 'saberin_web_push_status',
        'permission_callback' => 'saberin_web_push_rest_permission',
    ]);

    register_rest_route('saberin/v1', '/push/log-status', [
        'methods'             => 'POST',
        'callback'            => 'saberin_web_push_log_status',
        'permission_callback' => 'saberin_web_push_rest_permission',
    ]);
});

function saberin_web_push_log_status(WP_REST_Request $request) {
    global $wpdb;
    $status_table = SABERIN_WEB_PUSH_STATUS_TABLE;
    $data         = $request->get_json_params();

    $allowed = ['denied', 'dismissed'];
    $status  = isset($data['status']) ? sanitize_key($data['status']) : '';

    if (!in_array($status, $allowed, true)) {
        return new WP_REST_Response(['success' => false, 'message' => 'وضعیت نامعتبر است.'], 400);
    }

    $user_id = get_current_user_id();
    $now     = current_time('mysql');

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$status_table} WHERE user_id = %d LIMIT 1",
        $user_id
    ));

    if ($existing) {
        $wpdb->update(
            $status_table,
            ['status' => $status, 'updated_at' => $now],
            ['id' => (int) $existing],
            ['%s', '%s'],
            ['%d']
        );
    } else {
        $wpdb->insert(
            $status_table,
            ['user_id' => $user_id, 'status' => $status, 'updated_at' => $now],
            ['%d', '%s', '%s']
        );
    }

    return new WP_REST_Response(['success' => true], 200);
}

function saberin_web_push_subscribe(WP_REST_Request $request) {
    global $wpdb;
    $table = SABERIN_WEB_PUSH_TABLE;
    $data  = $request->get_json_params();

    $endpoint = isset($data['endpoint']) ? esc_url_raw($data['endpoint']) : '';
    $keys     = isset($data['keys']) && is_array($data['keys']) ? $data['keys'] : [];
    $p256dh   = isset($keys['p256dh']) ? sanitize_text_field($keys['p256dh']) : '';
    $auth     = isset($keys['auth']) ? sanitize_text_field($keys['auth']) : '';

    // Optional client-provided UA / label
    $ua_from_client = isset($data['userAgent']) ? sanitize_text_field(substr($data['userAgent'], 0, 500)) : '';
    $ua = $ua_from_client !== '' ? $ua_from_client : (isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(substr(wp_unslash($_SERVER['HTTP_USER_AGENT']), 0, 500)) : '');
    $device_label = saberin_web_push_device_label_from_ua($ua);

    if (!$endpoint || !$p256dh || !$auth) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'اطلاعات اشتراک اعلان ناقص است.',
        ], 400);
    }

    $user_id = get_current_user_id();
    $now     = current_time('mysql');

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE endpoint = %s LIMIT 1",
        $endpoint
    ));

    if ($existing) {
        $wpdb->update(
            $table,
            [
                'user_id'      => $user_id,
                'p256dh'       => $p256dh,
                'auth'         => $auth,
                'user_agent'   => $ua,
                'device_label' => $device_label,
                'updated_at'   => $now,
            ],
            ['id' => (int) $existing],
            ['%d', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    } else {
        $wpdb->insert(
            $table,
            [
                'user_id'      => $user_id,
                'endpoint'     => $endpoint,
                'p256dh'       => $p256dh,
                'auth'         => $auth,
                'user_agent'   => $ua,
                'device_label' => $device_label,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    // Mark status as subscribed
    $status_table = SABERIN_WEB_PUSH_STATUS_TABLE;
    $existing_st  = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$status_table} WHERE user_id = %d LIMIT 1",
        $user_id
    ));
    if ($existing_st) {
        $wpdb->update($status_table, ['status' => 'subscribed', 'updated_at' => $now], ['id' => (int) $existing_st], ['%s', '%s'], ['%d']);
    } else {
        $wpdb->insert($status_table, ['user_id' => $user_id, 'status' => 'subscribed', 'updated_at' => $now], ['%d', '%s', '%s']);
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'اعلان‌های صابرین با موفقیت فعال شد.',
        'device'  => $device_label,
    ], 200);
}

function saberin_web_push_unsubscribe(WP_REST_Request $request) {
    global $wpdb;
    $table    = SABERIN_WEB_PUSH_TABLE;
    $data     = $request->get_json_params();
    $endpoint = isset($data['endpoint']) ? esc_url_raw($data['endpoint']) : '';

    if (!$endpoint) {
        return new WP_REST_Response(['success' => false], 400);
    }

    $wpdb->delete($table, [
        'endpoint' => $endpoint,
        'user_id'  => get_current_user_id(),
    ], ['%s', '%d']);

    return new WP_REST_Response([
        'success' => true,
        'message' => 'اشتراک اعلان حذف شد.',
    ], 200);
}

function saberin_web_push_status() {
    global $wpdb;
    $table   = SABERIN_WEB_PUSH_TABLE;
    $user_id = get_current_user_id();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, device_label, user_agent, created_at, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC",
        $user_id
    ));

    $devices = [];
    foreach ($rows as $r) {
        $devices[] = [
            'id'           => (int) $r->id,
            'device_label' => $r->device_label ?: saberin_web_push_device_label_from_ua($r->user_agent),
            'updated_at'   => $r->updated_at,
        ];
    }

    return new WP_REST_Response([
        'success'       => true,
        'subscribed'    => count($devices) > 0,
        'subscriptions' => count($devices),
        'devices'       => $devices,
    ], 200);
}

/* ------------------------------------------------------------------ */
/*  Pure-PHP Web Push Sender (VAPID + aes128gcm)                       */
/* ------------------------------------------------------------------ */

function saberin_web_push_create_vapid_jwt($audience, $subject, $public_key_b64, $private_key_b64) {
    $header = [
        'typ' => 'JWT',
        'alg' => 'ES256',
    ];
    $payload = [
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => $subject,
    ];

    $header_b64  = saberin_web_push_b64url(json_encode($header, JSON_UNESCAPED_SLASHES));
    $payload_b64 = saberin_web_push_b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $unsigned    = $header_b64 . '.' . $payload_b64;

    $private_raw = saberin_web_push_b64url_decode($private_key_b64);
    $pem = saberin_web_push_private_key_to_pem($private_raw);
    if (!$pem) {
        return new WP_Error('vapid_pem', 'ساخت کلید خصوصی VAPID ناموفق بود.');
    }

    $signature = '';
    $ok = openssl_sign($unsigned, $signature, $pem, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        return new WP_Error('vapid_sign', 'امضای JWT ناموفق بود: ' . openssl_error_string());
    }

    $raw_sig = saberin_web_push_der_to_raw_sig($signature);
    if (!$raw_sig) {
        return new WP_Error('vapid_sig_fmt', 'فرمت امضا نامعتبر است.');
    }

    return $unsigned . '.' . saberin_web_push_b64url($raw_sig);
}

function saberin_web_push_private_key_to_pem($private_raw) {
    $oid_prime256v1 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $priv_octet = "\x04" . chr(strlen($private_raw)) . $private_raw;
    $version = "\x02\x01\x01";
    $params  = "\xa0" . chr(strlen($oid_prime256v1)) . $oid_prime256v1;
    $seq_content = $version . $priv_octet . $params;
    $ec_priv     = "\x30" . chr(strlen($seq_content)) . $seq_content;

    $alg_oid   = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
    $alg_id    = "\x30" . chr(strlen($alg_oid . $oid_prime256v1)) . $alg_oid . $oid_prime256v1;
    $pk_octet  = "\x04" . chr(strlen($ec_priv)) . $ec_priv;
    $pkcs8_ver = "\x02\x01\x00";
    $pkcs8     = "\x30" . chr(strlen($pkcs8_ver . $alg_id . $pk_octet)) . $pkcs8_ver . $alg_id . $pk_octet;

    $pem = "-----BEGIN PRIVATE KEY-----\n" .
           chunk_split(base64_encode($pkcs8), 64, "\n") .
           "-----END PRIVATE KEY-----\n";

    $res = @openssl_pkey_get_private($pem);
    if (!$res) {
        return false;
    }
    return $pem;
}

function saberin_web_push_der_to_raw_sig($der) {
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) return false;
    $len = ord($der[$offset++]);
    if ($len & 0x80) {
        $n = $len & 0x7f;
        $len = 0;
        for ($i = 0; $i < $n; $i++) $len = ($len << 8) | ord($der[$offset++]);
    }
    if (ord($der[$offset++]) !== 0x02) return false;
    $rlen = ord($der[$offset++]);
    $r = substr($der, $offset, $rlen);
    $offset += $rlen;
    if (ord($der[$offset++]) !== 0x02) return false;
    $slen = ord($der[$offset++]);
    $s = substr($der, $offset, $slen);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    if (strlen($r) !== 32 || strlen($s) !== 32) return false;
    return $r . $s;
}

function saberin_web_push_encrypt_payload($payload, $user_public_b64, $user_auth_b64) {
    $user_public = saberin_web_push_b64url_decode($user_public_b64);
    $user_auth   = saberin_web_push_b64url_decode($user_auth_b64);

    if (strlen($user_public) !== 65 || $user_public[0] !== "\x04") {
        return new WP_Error('bad_p256dh', 'کلید عمومی کاربر نامعتبر است.');
    }
    if (strlen($user_auth) !== 16) {
        return new WP_Error('bad_auth', 'کلید auth کاربر نامعتبر است.');
    }

    if (!function_exists('openssl_pkey_derive')) {
        return new WP_Error('php_version', 'برای ارسال پوش به PHP 7.3 یا بالاتر نیاز است (openssl_pkey_derive).');
    }

    $local_key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);
    if (!$local_key) {
        return new WP_Error('ecdh_key', 'ساخت کلید موقت ناموفق بود.');
    }

    $local_details = openssl_pkey_get_details($local_key);
    $local_public  = "\x04" . $local_details['ec']['x'] . $local_details['ec']['y'];

    $peer_pem = saberin_web_push_public_key_to_pem($user_public);
    if (!$peer_pem) {
        return new WP_Error('peer_pem', 'ساخت کلید عمومی همتا ناموفق بود.');
    }

    $shared = openssl_pkey_derive($peer_pem, $local_key, 32);
    if ($shared === false) {
        return new WP_Error('ecdh', 'محاسبه ECDH ناموفق بود.');
    }

    $key_info = "WebPush: info\x00" . $user_public . $local_public;
    $prk      = hash_hmac('sha256', $shared, $user_auth, true);
    $ikm      = hash_hmac('sha256', $key_info . "\x01", $prk, true);

    $salt  = random_bytes(16);
    $prk2  = hash_hmac('sha256', $ikm, $salt, true);
    $cek   = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk2, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk2, true), 0, 12);

    $padded = $payload . "\x02";
    $tag = '';
    $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        return new WP_Error('encrypt', 'رمزنگاری محتوا ناموفق بود.');
    }

    $record_size = 4096;
    $body = $salt . pack('N', $record_size) . chr(strlen($local_public)) . $local_public . $ciphertext . $tag;

    return [
        'body'    => $body,
        'headers' => [
            'Content-Encoding' => 'aes128gcm',
            'Content-Type'     => 'application/octet-stream',
            'TTL'              => '86400',
            'Urgency'          => 'normal',
        ],
    ];
}

function saberin_web_push_public_key_to_pem($public_raw) {
    $oid_ec   = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
    $oid_p256 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $alg = "\x30" . chr(strlen($oid_ec . $oid_p256)) . $oid_ec . $oid_p256;
    $bitstring = "\x03" . chr(strlen($public_raw) + 1) . "\x00" . $public_raw;
    $spki = "\x30" . chr(strlen($alg . $bitstring)) . $alg . $bitstring;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $res = @openssl_pkey_get_public($pem);
    if (!$res) return false;
    return $pem;
}

function saberin_web_push_send_one($subscription, $payload_json) {
    $endpoint = $subscription->endpoint;
    $p256dh   = $subscription->p256dh;
    $auth     = $subscription->auth;

    $public_key  = get_option('saberin_web_push_vapid_public');
    $private_key = get_option('saberin_web_push_vapid_private');

    if (!$public_key || !$private_key) {
        return new WP_Error('no_vapid', 'کلیدهای VAPID موجود نیستند.');
    }

    $parts = wp_parse_url($endpoint);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return new WP_Error('bad_endpoint', 'آدرس endpoint نامعتبر است.');
    }
    $audience = $parts['scheme'] . '://' . $parts['host'];
    $subject  = home_url('/');

    $jwt = saberin_web_push_create_vapid_jwt($audience, $subject, $public_key, $private_key);
    if (is_wp_error($jwt)) {
        return $jwt;
    }

    $encrypted = saberin_web_push_encrypt_payload($payload_json, $p256dh, $auth);
    if (is_wp_error($encrypted)) {
        return $encrypted;
    }

    $headers = array_merge($encrypted['headers'], [
        'Authorization'  => 'vapid t=' . $jwt . ', k=' . $public_key,
        'Content-Length' => strlen($encrypted['body']),
    ]);

    $response = wp_remote_post($endpoint, [
        'timeout'  => 15,
        'headers'  => $headers,
        'body'     => $encrypted['body'],
        'blocking' => true,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        return true;
    }
    if ($code === 404 || $code === 410) {
        return new WP_Error('gone', 'اشتراک منقضی شده است.', ['status' => $code]);
    }
    $body = wp_remote_retrieve_body($response);
    return new WP_Error('push_failed', "ارسال ناموفق بود (HTTP {$code}): {$body}", ['status' => $code]);
}

/**
 * Send notifications.
 *
 * @param string   $title
 * @param string   $body
 * @param string   $url
 * @param int[]|null $user_ids   Filter by user IDs
 * @param int[]|null $sub_ids    Filter by subscription row IDs
 */
function saberin_web_push_send($title, $body, $url = '', $user_ids = null, $sub_ids = null) {
    global $wpdb;
    $table = SABERIN_WEB_PUSH_TABLE;

    $payload = wp_json_encode([
        'title' => $title,
        'body'  => $body,
        'url'   => $url ?: home_url('/'),
        'tag'   => 'saberin-' . time(),
    ], JSON_UNESCAPED_UNICODE);

    $where  = [];
    $params = [];

    if ($sub_ids && is_array($sub_ids) && count($sub_ids) > 0) {
        $sub_ids = array_map('intval', $sub_ids);
        $ph = implode(',', array_fill(0, count($sub_ids), '%d'));
        $where[] = "id IN ({$ph})";
        $params = array_merge($params, $sub_ids);
    }

    if ($user_ids && is_array($user_ids) && count($user_ids) > 0) {
        $user_ids = array_map('intval', $user_ids);
        $ph = implode(',', array_fill(0, count($user_ids), '%d'));
        $where[] = "user_id IN ({$ph})";
        $params = array_merge($params, $user_ids);
    }

    if ($where) {
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where);
        $subs = $wpdb->get_results($wpdb->prepare($sql, ...$params));
    } else {
        $subs = $wpdb->get_results("SELECT * FROM {$table}");
    }

    $ok = 0;
    $fail = 0;
    $removed = 0;
    $errors = [];

    foreach ($subs as $sub) {
        $result = saberin_web_push_send_one($sub, $payload);
        if ($result === true) {
            $ok++;
        } else {
            $fail++;
            if (is_wp_error($result)) {
                $code = 0;
                $data = $result->get_error_data();
                if (is_array($data) && isset($data['status'])) {
                    $code = (int) $data['status'];
                }
                if ($code === 404 || $code === 410 || $result->get_error_code() === 'gone') {
                    $wpdb->delete($table, ['id' => $sub->id], ['%d']);
                    $removed++;
                }
                $label = $sub->device_label ?: ('#' . $sub->id);
                $errors[] = $label . ': ' . $result->get_error_message();
            }
        }
    }

    return [
        'total'   => count($subs),
        'success' => $ok,
        'failed'  => $fail,
        'removed' => $removed,
        'errors'  => array_slice($errors, 0, 8),
    ];
}

/* ------------------------------------------------------------------ */
/*  Admin                                                               */
/* ------------------------------------------------------------------ */

add_action('admin_menu', function () {
    add_menu_page(
        'اعلان‌های صابرین',
        'اعلان‌های صابرین',
        'manage_options',
        'saberin-web-push',
        'saberin_web_push_admin_page',
        'dashicons-megaphone',
        58
    );
});

function saberin_web_push_admin_page() {
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $table        = SABERIN_WEB_PUSH_TABLE;
    $status_table = SABERIN_WEB_PUSH_STATUS_TABLE;

    // Handle delete device
    if (isset($_GET['saberin_delete_sub']) && check_admin_referer('saberin_delete_sub_' . (int) $_GET['saberin_delete_sub'])) {
        $wpdb->delete($table, ['id' => (int) $_GET['saberin_delete_sub']], ['%d']);
        echo '<div class="notice notice-success is-dismissible"><p>دستگاه حذف شد.</p></div>';
    }

    // Handle send form
    $send_result = null;
    if (isset($_POST['saberin_push_send']) && check_admin_referer('saberin_push_send_action')) {
        $title  = sanitize_text_field(wp_unslash($_POST['push_title'] ?? ''));
        $body   = sanitize_textarea_field(wp_unslash($_POST['push_body'] ?? ''));
        $url    = esc_url_raw(wp_unslash($_POST['push_url'] ?? ''));
        $mode   = sanitize_key($_POST['push_mode'] ?? 'all');

        $user_ids = null;
        $sub_ids  = null;

        if ($mode === 'selected_devices') {
            $sub_ids = isset($_POST['sub_ids']) && is_array($_POST['sub_ids'])
                ? array_map('intval', $_POST['sub_ids'])
                : [];
            if (!$sub_ids) {
                $send_result = ['error' => 'هیچ دستگاهی انتخاب نشده است.'];
            }
        } elseif ($mode === 'selected_users') {
            $user_ids = isset($_POST['user_ids']) && is_array($_POST['user_ids'])
                ? array_map('intval', $_POST['user_ids'])
                : [];
            if (!$user_ids) {
                $send_result = ['error' => 'هیچ کاربری انتخاب نشده است.'];
            }
        }

        if ($send_result === null) {
            if ($title === '' || $body === '') {
                $send_result = ['error' => 'عنوان و متن اعلان الزامی است.'];
            } else {
                $send_result = saberin_web_push_send($title, $body, $url, $user_ids, $sub_ids);
            }
        }
    }

    // All active subscriptions with user info
    $subs = $wpdb->get_results(
        "SELECT s.*, u.display_name, u.user_login, u.user_email
         FROM {$table} s
         LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
         ORDER BY s.updated_at DESC"
    );

    // User status overview
    $users = $wpdb->get_results(
        "SELECT u.ID as user_id, u.display_name, u.user_login, u.user_email,
                sub.sub_count, sub.sub_updated,
                st.status as logged_status, st.updated_at as status_updated
         FROM {$wpdb->users} u
         LEFT JOIN (
             SELECT user_id, COUNT(*) as sub_count, MAX(updated_at) as sub_updated
             FROM {$table}
             GROUP BY user_id
         ) sub ON sub.user_id = u.ID
         LEFT JOIN {$status_table} st ON st.user_id = u.ID
         ORDER BY u.display_name"
    );

    $computed = [];
    $counts = ['subscribed' => 0, 'denied' => 0, 'dismissed' => 0, 'unknown' => 0];
    foreach ($users as $row) {
        if (!empty($row->sub_count) && (int) $row->sub_count > 0) {
            $status = 'subscribed';
            $when   = $row->sub_updated;
        } elseif (!empty($row->logged_status)) {
            $status = $row->logged_status;
            $when   = $row->status_updated;
        } else {
            $status = 'unknown';
            $when   = '';
        }
        $counts[$status]++;
        $computed[] = (object) [
            'user_id'      => $row->user_id,
            'display_name' => $row->display_name,
            'user_login'   => $row->user_login,
            'user_email'   => $row->user_email,
            'status'       => $status,
            'when'         => $when,
            'sub_count'    => (int) ($row->sub_count ?: 0),
        ];
    }

    $labels = [
        'subscribed' => ['🔔 فعال', '#0a7d34', '#e6f7ec'],
        'denied'     => ['🚫 رد شده', '#b42318', '#fdeceb'],
        'dismissed'  => ['⏳ بعداً گفته', '#946200', '#fff6e0'],
        'unknown'    => ['❔ هنوز اقدامی نکرده', '#555', '#f1f1f1'],
    ];

    $filter = isset($_GET['saberin_status']) ? sanitize_key($_GET['saberin_status']) : 'all';
    if (!in_array($filter, ['all', 'subscribed', 'denied', 'dismissed', 'unknown'], true)) {
        $filter = 'all';
    }

    $public_key = get_option('saberin_web_push_vapid_public', '');
    $base_url   = esc_url(admin_url('admin.php?page=saberin-web-push'));
    $sub_count  = count($subs);
    $php_ok     = function_exists('openssl_pkey_derive');
    $php_ver    = PHP_VERSION;

    ?>
    <div class="wrap" dir="rtl" style="font-family:inherit;">
        <h1>اعلان‌های صابرین <small style="font-size:14px;color:#666;">نسخه <?php echo esc_html(SABERIN_WEB_PUSH_VERSION); ?></small></h1>

        <?php if (!$php_ok): ?>
            <div class="notice notice-error"><p>
                نسخه PHP سرور شما: <strong><?php echo esc_html($php_ver); ?></strong>.
                برای ارسال پوش به <strong>PHP 7.3 یا بالاتر</strong> نیاز است (تابع <code>openssl_pkey_derive</code>).
                لطفاً نسخه PHP هاست را ارتقا دهید.
            </p></div>
        <?php endif; ?>

        <?php if ($send_result): ?>
            <?php if (!empty($send_result['error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html($send_result['error']); ?></p></div>
            <?php else: ?>
                <div class="notice notice-success"><p>
                    ارسال انجام شد:
                    موفق <strong><?php echo (int) $send_result['success']; ?></strong> /
                    ناموفق <strong><?php echo (int) $send_result['failed']; ?></strong>
                    (از مجموع <?php echo (int) $send_result['total']; ?> دستگاه)
                    <?php if (!empty($send_result['removed'])): ?>
                        — <?php echo (int) $send_result['removed']; ?> اشتراک منقضی حذف شد.
                    <?php endif; ?>
                </p>
                <?php if (!empty($send_result['errors'])): ?>
                    <ul style="margin:8px 0 0 20px;color:#666;font-size:13px;">
                        <?php foreach ($send_result['errors'] as $err): ?>
                            <li><?php echo esc_html($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Send form -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:10px;padding:18px;margin:18px 0;max-width:1100px;">
            <h2 style="margin-top:0;">ارسال اعلان</h2>
            <p>تعداد دستگاه‌های فعال: <strong><?php echo (int) $sub_count; ?></strong>
                <?php if ($sub_count > 0): ?>
                    (از <?php echo (int) $counts['subscribed']; ?> کاربر)
                <?php endif; ?>
            </p>

            <?php if ($sub_count === 0): ?>
                <p style="color:#946200;">هنوز هیچ دستگاهی ثبت نشده. کاربران باید لاگین کنند و اجازه اعلان بدهند.</p>
            <?php endif; ?>

            <form method="post" id="saberin-send-form">
                <?php wp_nonce_field('saberin_push_send_action'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="push_title">عنوان</label></th>
                        <td><input name="push_title" id="push_title" type="text" class="regular-text" value="صابرین" required></td>
                    </tr>
                    <tr>
                        <th><label for="push_body">متن اعلان</label></th>
                        <td><textarea name="push_body" id="push_body" rows="3" class="large-text" required placeholder="متن اعلان را بنویسید..."></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="push_url">لینک (اختیاری)</label></th>
                        <td>
                            <input name="push_url" id="push_url" type="url" class="regular-text" placeholder="<?php echo esc_attr(home_url('/')); ?>">
                            <p class="description">با کلیک روی اعلان، کاربر به این آدرس می‌رود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>گیرندگان</th>
                        <td>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="radio" name="push_mode" value="all" checked onchange="saberinToggleMode()">
                                همه دستگاه‌های فعال
                            </label>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="radio" name="push_mode" value="selected_devices" onchange="saberinToggleMode()">
                                دستگاه‌های انتخاب‌شده (از جدول زیر)
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="push_mode" value="selected_users" onchange="saberinToggleMode()">
                                کاربران انتخاب‌شده (همه دستگاه‌های آن‌ها)
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="saberin_push_send" class="button button-primary button-hero" <?php echo ($sub_count === 0 || !$php_ok) ? 'disabled' : ''; ?>>
                        🔔 ارسال اعلان
                    </button>
                </p>
            </form>
        </div>

        <!-- Devices table -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:10px;padding:18px;margin:18px 0;max-width:1100px;">
            <h2 style="margin-top:0;">دستگاه‌های فعال</h2>
            <p class="description">هر ردیف = یک دستگاه/مرورگر که اعلان را فعال کرده. یک کاربر می‌تواند چند دستگاه داشته باشد (دسکتاپ + موبایل + ...).</p>

            <?php if (!$subs): ?>
                <p>هنوز دستگاهی ثبت نشده است.</p>
            <?php else: ?>
            <table class="widefat striped" id="saberin-devices-table">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="saberin-check-all-devices" title="انتخاب همه"></th>
                        <th>کاربر</th>
                        <th>دستگاه</th>
                        <th>سرویس پوش</th>
                        <th>آخرین فعالیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subs as $s):
                    $label = $s->device_label ?: saberin_web_push_device_label_from_ua($s->user_agent);
                    $provider = saberin_web_push_endpoint_provider($s->endpoint);
                    $uname = $s->display_name ?: $s->user_login ?: ('User #' . $s->user_id);
                    $del_url = wp_nonce_url(
                        add_query_arg('saberin_delete_sub', (int) $s->id, $base_url),
                        'saberin_delete_sub_' . (int) $s->id
                    );
                ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="saberin-sub-check" form="saberin-send-form" name="sub_ids[]" value="<?php echo (int) $s->id; ?>">
                        </td>
                        <td>
                            <?php echo esc_html($uname); ?>
                            <br><small><?php echo esc_html($s->user_email); ?> · ID: <?php echo (int) $s->user_id; ?></small>
                        </td>
                        <td><?php echo esc_html($label); ?></td>
                        <td><small><?php echo esc_html($provider); ?></small></td>
                        <td><?php echo esc_html($s->updated_at); ?></td>
                        <td>
                            <a href="<?php echo esc_url($del_url); ?>" class="button button-small" onclick="return confirm('این دستگاه حذف شود؟');">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Users status overview -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:10px;padding:18px;margin:18px 0;max-width:1100px;">
            <h2 style="margin-top:0;">وضعیت اعلان کاربران</h2>
            <p style="margin:0 0 12px;">
                <span style="margin-left:16px;">🔔 فعال: <strong><?php echo (int) $counts['subscribed']; ?></strong></span>
                <span style="margin-left:16px;">🚫 رد شده: <strong><?php echo (int) $counts['denied']; ?></strong></span>
                <span style="margin-left:16px;">⏳ بعداً: <strong><?php echo (int) $counts['dismissed']; ?></strong></span>
                <span>❔ بدون اقدام: <strong><?php echo (int) $counts['unknown']; ?></strong></span>
            </p>
            <p style="margin-top:-6px;">
                <a href="<?php echo esc_url($base_url); ?>"<?php echo $filter === 'all' ? ' style="font-weight:700;"' : ''; ?>>همه</a> |
                <?php foreach ($labels as $key => $meta): ?>
                    <a href="<?php echo esc_url(add_query_arg('saberin_status', $key, $base_url)); ?>"<?php echo $filter === $key ? ' style="font-weight:700;"' : ''; ?>><?php echo esc_html($meta[0]); ?></a>
                    <?php echo $key !== 'unknown' ? '|' : ''; ?>
                <?php endforeach; ?>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:36px;"></th>
                        <th>کاربر</th>
                        <th>ایمیل</th>
                        <th>وضعیت</th>
                        <th>تعداد دستگاه</th>
                        <th>آخرین تغییر</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $shown = 0;
                foreach ($computed as $row):
                    if ($filter !== 'all' && $row->status !== $filter) continue;
                    $shown++;
                    $meta = $labels[$row->status];
                ?>
                    <tr>
                        <td>
                            <?php if ($row->sub_count > 0): ?>
                                <input type="checkbox" class="saberin-user-check" form="saberin-send-form" name="user_ids[]" value="<?php echo (int) $row->user_id; ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html($row->display_name ?: $row->user_login ?: ('User #' . $row->user_id)); ?>
                            <br><small>ID: <?php echo (int) $row->user_id; ?></small>
                        </td>
                        <td><?php echo esc_html($row->user_email); ?></td>
                        <td>
                            <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?php echo esc_attr($meta[2]); ?>;color:<?php echo esc_attr($meta[1]); ?>;font-size:13px;">
                                <?php echo esc_html($meta[0]); ?>
                            </span>
                        </td>
                        <td><?php echo (int) $row->sub_count; ?></td>
                        <td><?php echo $row->when ? esc_html($row->when) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($shown === 0): ?>
                    <tr><td colspan="6">کاربری با این وضعیت پیدا نشد.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="background:#fff;border:1px solid #ddd;border-radius:10px;padding:18px;max-width:1100px;">
            <h2 style="margin-top:0;">نکات موبایل و آیفون</h2>
            <ul style="line-height:1.9;">
                <li><strong>اندروید (Chrome):</strong> معمولاً بدون نصب کار می‌کند؛ کافی است اجازه اعلان داده شود.</li>
                <li><strong>آیفون / آیپد (Safari):</strong> از iOS 16.4 به بعد پشتیبانی می‌شود و سایت باید به <strong>صفحه اصلی (Add to Home Screen)</strong> اضافه شده باشد. اعلان فقط از طریق آیکون PWA نصب‌شده کار می‌کند، نه از تب معمولی سافاری.</li>
                <li>یک حساب کاربری می‌تواند هم‌زمان چند دستگاه (لپ‌تاپ + گوشی + ...) داشته باشد؛ هر کدام جداگانه در جدول دستگاه‌ها دیده می‌شود.</li>
            </ul>
            <h3>کلید عمومی VAPID</h3>
            <p style="word-break:break-all;font-family:monospace;font-size:12px;background:#f6f7f7;padding:10px;border-radius:6px;"><?php echo esc_html($public_key ?: 'هنوز ساخته نشده'); ?></p>
            <p class="description">PHP: <?php echo esc_html($php_ver); ?> · <?php echo $php_ok ? '✓ آماده ارسال' : '✗ نیاز به ارتقای PHP'; ?></p>
        </div>
    </div>
    <script>
    (function(){
        var all = document.getElementById('saberin-check-all-devices');
        if (all) {
            all.addEventListener('change', function(){
                document.querySelectorAll('.saberin-sub-check').forEach(function(cb){ cb.checked = all.checked; });
            });
        }
        window.saberinToggleMode = function(){};
    })();
    </script>
    <?php
}

/* ------------------------------------------------------------------ */
/*  Front-end prompt                                                    */
/* ------------------------------------------------------------------ */

add_action('wp_head', function () {
    if (!is_user_logged_in()) return;

    $public_key = get_option('saberin_web_push_vapid_public', '');
    if (!$public_key) return;
    ?>
    <style>
        #saberin-push-modal{position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:999999;display:none;align-items:center;justify-content:center;padding:20px}
        #saberin-push-box{background:#fff;width:min(420px,100%);border-radius:20px;padding:28px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.2);font-family:inherit}
        #saberin-push-box h3{margin:0 0 12px;font-size:22px}
        #saberin-push-box p{line-height:1.9;color:#555;margin:0 0 20px}
        #saberin-push-enable{width:100%;border:0;border-radius:12px;padding:13px 16px;background:#174ea6;color:#fff;font-size:16px;cursor:pointer}
        #saberin-push-later{margin-top:10px;border:0;background:transparent;color:#777;cursor:pointer;padding:8px}
        #saberin-push-status{margin-top:14px;font-size:14px}
    </style>
    <div id="saberin-push-modal" aria-hidden="true">
        <div id="saberin-push-box">
            <h3>🔔 اعلان‌های صابرین را فعال کنید</h3>
            <p>با فعال کردن اعلان‌ها، اطلاعیه‌های مهم و پیام‌های جدید صابرین را به‌موقع دریافت می‌کنید.</p>
            <button id="saberin-push-enable">🔔 فعال کردن اعلان‌ها</button>
            <button id="saberin-push-later">فعلاً نه</button>
            <div id="saberin-push-status"></div>
        </div>
    </div>
    <script>
    (() => {
        const publicKey = <?php echo wp_json_encode($public_key); ?>;
        const restNonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
        const restBase  = <?php echo wp_json_encode(esc_url_raw(rest_url('saberin/v1/push/'))); ?>;
        const dismissedKey = 'saberin_push_prompt_dismissed_v1';

        function b64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = atob(base64);
            const arr = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
            return arr;
        }

        async function getSubscription() {
            const registration = await navigator.serviceWorker.ready;
            return registration.pushManager.getSubscription();
        }

        async function saveSubscription(subscription) {
            const json = subscription.toJSON();
            json.userAgent = navigator.userAgent || '';
            const res = await fetch(restBase + 'subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': restNonce
                },
                body: JSON.stringify(json)
            });
            return res.json();
        }

        function logStatus(status) {
            fetch(restBase + 'log-status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': restNonce
                },
                body: JSON.stringify({ status })
            }).catch(() => {});
        }

        async function init() {
            try {
                if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                    return;
                }

                if (Notification.permission === 'denied') {
                    if (sessionStorage.getItem('saberin_push_denied_logged') !== '1') {
                        logStatus('denied');
                        sessionStorage.setItem('saberin_push_denied_logged', '1');
                    }
                    return;
                }

                if (Notification.permission === 'granted') {
                    const sub = await getSubscription();
                    if (sub) {
                        await saveSubscription(sub);
                        return;
                    }
                }

                if (localStorage.getItem(dismissedKey) === '1') {
                    return;
                }

                setTimeout(() => {
                    const modal = document.getElementById('saberin-push-modal');
                    if (modal) modal.style.display = 'flex';
                }, 1800);
            } catch (e) {
                console.error('Saberin Web Push init error:', e);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const modal  = document.getElementById('saberin-push-modal');
            const enable = document.getElementById('saberin-push-enable');
            const later  = document.getElementById('saberin-push-later');
            const status = document.getElementById('saberin-push-status');

            if (!enable || !later || !modal) return;

            later.addEventListener('click', () => {
                localStorage.setItem(dismissedKey, '1');
                logStatus('dismissed');
                modal.style.display = 'none';
            });

            enable.addEventListener('click', async () => {
                enable.disabled = true;
                status.textContent = 'در حال فعال‌سازی...';

                try {
                    const permission = await Notification.requestPermission();
                    if (permission !== 'granted') {
                        status.textContent = '⚠️ اجازه اعلان‌ها داده نشد.';
                        logStatus('denied');
                        enable.disabled = false;
                        return;
                    }

                    const registration = await navigator.serviceWorker.ready;
                    let subscription = await registration.pushManager.getSubscription();

                    if (!subscription) {
                        subscription = await registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: b64ToUint8Array(publicKey)
                        });
                    }

                    const result = await saveSubscription(subscription);

                    if (!result || !result.success) {
                        throw new Error('Subscription save failed');
                    }

                    status.textContent = '✅ اعلان‌های صابرین فعال شد' + (result.device ? ' (' + result.device + ')' : '') + '.';
                    setTimeout(() => { modal.style.display = 'none'; }, 1200);
                } catch (e) {
                    console.error('Saberin Web Push subscribe error:', e);
                    status.textContent = '❌ فعال‌سازی انجام نشد. Console مرورگر را بررسی کنید.';
                    enable.disabled = false;
                }
            });

            init();
        });
    })();
    </script>
    <?php
});