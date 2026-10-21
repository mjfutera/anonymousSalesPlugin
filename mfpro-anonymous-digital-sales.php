<?php

/*
 * Plugin Name:       MFPro Anonymous Digital Sales
 * Plugin URI:        https://github.com/mjfutera/mfpro-anonymous-digital-sales
 * Description:       Sell digital files for cryptocurrency without collecting any customer data. Fully anonymous orders, payments through NOWPayments.
 * Version:           1.4.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Michal Futera
 * Author URI:        https://michalfutera.pro/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mfpro-anonymous-digital-sales
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin owns these tables; table names come from its own schema (anonymous_sales_get_schema_names), never from user input, and every value goes through $wpdb->prepare() or the format arguments of insert()/update().

define('ANONYMOUS_SALES_VERSION', '1.4.1');
define('ANONYMOUS_SALES_PLUGIN_FILE', __FILE__);
define('ANONYMOUS_SALES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ANONYMOUS_SALES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ANONYMOUS_SALES_INCLUDES_DIR', ANONYMOUS_SALES_PLUGIN_DIR . 'includes');
define('ANONYMOUS_SALES_ADMIN_DIR', ANONYMOUS_SALES_INCLUDES_DIR . '/admin');
define('ANONYMOUS_SALES_API_DIR', ANONYMOUS_SALES_INCLUDES_DIR . '/api');
define('ANONYMOUS_SALES_ASSETS_DIR', ANONYMOUS_SALES_PLUGIN_DIR . 'assets');
define('ANONYMOUS_SALES_VENDOR_DIR', ANONYMOUS_SALES_PLUGIN_DIR . 'vendor');
$anonymous_sales_uploads = wp_upload_dir(null, false);
define('ANONYMOUS_SALES_VAR_DIR', trailingslashit($anonymous_sales_uploads['basedir']) . 'anonymous-sales');
unset($anonymous_sales_uploads);

// Moves data and keeps entry points of the versions released before the rename.
require_once ANONYMOUS_SALES_INCLUDES_DIR . '/anonymous-sales-legacy.php';

function anonymous_sales_load_library() {
    static $loaded = false;
    if ($loaded) {
        return true;
    }

    $library_file = ANONYMOUS_SALES_INCLUDES_DIR . '/anonymous-sales-library.php';
    if (!file_exists($library_file)) {
        return false;
    }

    require_once $library_file;
    $loaded = true;

    return true;
}

function anonymous_sales_load_admin_module() {
    static $loaded = false;

    if ($loaded) {
        return true;
    }

    $admin_file = ANONYMOUS_SALES_ADMIN_DIR . '/anonymous-sales-admin.php';
    if (!file_exists($admin_file)) {
        return false;
    }

    require_once $admin_file;
    $loaded = true;

    return true;
}

function anonymous_sales_load_api_module() {
    static $loaded = false;

    if ($loaded) {
        return true;
    }

    $api_file = ANONYMOUS_SALES_API_DIR . '/anonymous-sales-api.php';
    if (!file_exists($api_file)) {
        return false;
    }

    require_once $api_file;
    $loaded = true;

    return true;
}

function anonymous_sales_library_missing_notice() {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-error"><p>';
    echo 'MFPro Anonymous Digital Sales: missing required file <code>includes/anonymous-sales-library.php</code>.';
    echo '</p></div>';
}

function anonymous_sales_get_schema_names() {
    global $wpdb;

    return [
        'offers'     => $wpdb->prefix . 'anonymous_sales_offers',
        'orders'     => $wpdb->prefix . 'anonymous_sales_orders',
        'promocodes' => $wpdb->prefix . 'anonymous_sales_promocodes',
        'resources'  => $wpdb->prefix . 'anonymous_sales_resources',
        'nowpayments_apiKey' => 'anonymous_sales_nowpayments_apiKey',
        'nowpayments_webhookKey' => 'anonymous_sales_nowpayments_webhookKey',
        'nowpayments_webhookToken' => 'anonymous_sales_nowpayments_webhookToken',
        'router_auth_key' => 'anonymous_sales_router_auth_key',
        'nowpayments_send_callback_each_request' => 'anonymous_sales_nowpayments_send_callback_each_request',
        'nowpayments_send_callback_each_request_user_set' => 'anonymous_sales_nowpayments_send_callback_each_request_user_set',
        'delete_data_on_deactivation' => 'anonymous_sales_delete_data_on_deactivation',
        'debug_logging' => 'anonymous_sales_debug_logging',
        'plugin_version' => 'anonymous_sales_plugin_version',
        'encryption_key' => 'anonymous_sales_encryption_key',
    ];
}

/**
 * Key used before 1.3: derived from the auth salt, so rotating the salts in
 * wp-config.php silently destroyed the API key and every resource password.
 * Kept only to decrypt values stored by older versions.
 */
function anonymous_sales_get_legacy_secret_key() {
    if (function_exists('wp_salt')) {
        return hash('sha256', wp_salt('auth'), true);
    }

    return hash('sha256', ABSPATH, true);
}

/**
 * Encryption key: ANONYMOUS_SALES_ENCRYPTION_KEY from wp-config.php when defined
 * (recommended, keeps the key out of the database), otherwise a random key generated
 * once and stored as an option.
 */
function anonymous_sales_get_secret_key() {
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    if (defined('ANONYMOUS_SALES_ENCRYPTION_KEY') && (string) ANONYMOUS_SALES_ENCRYPTION_KEY !== '') {
        $key = hash('sha256', (string) ANONYMOUS_SALES_ENCRYPTION_KEY, true);
        return $key;
    }

    $schema = anonymous_sales_get_schema_names();
    $stored = (string) get_option($schema['encryption_key'], '');
    $raw = $stored !== '' ? base64_decode($stored, true) : false;

    if ($raw === false || strlen($raw) !== 32) {
        $raw = random_bytes(32);
        update_option($schema['encryption_key'], base64_encode($raw), false);
    }

    $key = $raw;
    return $key;
}

function anonymous_sales_encrypt_secret($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt') || !function_exists('random_bytes')) {
        return 'plain:' . base64_encode($value);
    }

    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($value, 'aes-256-cbc', anonymous_sales_get_secret_key(), OPENSSL_RAW_DATA, $iv);

    if ($encrypted === false) {
        return 'plain:' . base64_encode($value);
    }

    return 'enc:' . base64_encode($iv . $encrypted);
}

function anonymous_sales_decrypt_secret($stored_value) {
    $stored_value = (string) $stored_value;
    if ($stored_value === '') {
        return '';
    }

    if (strpos($stored_value, 'enc:') === 0) {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $decoded = base64_decode(substr($stored_value, 4), true);
        if ($decoded === false || strlen($decoded) <= 16) {
            return '';
        }

        $iv = substr($decoded, 0, 16);
        $cipher = substr($decoded, 16);
        $decrypted = openssl_decrypt($cipher, 'aes-256-cbc', anonymous_sales_get_secret_key(), OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            // Value written by a version before 1.3 with the salt-derived key.
            $decrypted = openssl_decrypt($cipher, 'aes-256-cbc', anonymous_sales_get_legacy_secret_key(), OPENSSL_RAW_DATA, $iv);
        }

        return $decrypted === false ? '' : trim((string) $decrypted);
    }

    if (strpos($stored_value, 'plain:') === 0) {
        $decoded = base64_decode(substr($stored_value, 6), true);
        return $decoded === false ? '' : trim((string) $decoded);
    }

    // Backward compatibility for older plain-text options.
    return trim($stored_value);
}

function anonymous_sales_mask_secret($secret) {
    $secret = trim((string) $secret);
    if ($secret === '') {
        return '';
    }

    $length = strlen($secret);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($secret, 0, 4) . str_repeat('*', max(4, $length - 8)) . substr($secret, -4);
}

function anonymous_sales_generate_router_auth_key() {
    return strtolower(anonymous_sales_generate_random_string(32));
}

function anonymous_sales_get_router_auth_key() {
    $schema = anonymous_sales_get_schema_names();
    $key = trim((string) get_option($schema['router_auth_key'], ''));

    if ($key === '') {
        $key = anonymous_sales_generate_router_auth_key();
        update_option($schema['router_auth_key'], $key);
    }

    return $key;
}

function anonymous_sales_should_send_callback_each_request() {
    $schema = anonymous_sales_get_schema_names();
    return get_option($schema['nowpayments_send_callback_each_request'], '1') === '1';
}

function anonymous_sales_markdown_to_html($markdown) {
    $text = trim((string) $markdown);
    if ($text === '') {
        return '';
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Basic markdown support for common admin/editor use cases.
    $text = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);
    $text = preg_replace('/`(.+?)`/s', '<code>$1</code>', $text);
    $text = preg_replace('/\[(.*?)\]\((https?:\/\/[^\s\)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text);

    $lines = explode("\n", $text);
    $in_list = false;
    $html_lines = [];

    foreach ($lines as $line) {
        if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $matches)) {
            if (!$in_list) {
                $html_lines[] = '<ul>';
                $in_list = true;
            }
            $html_lines[] = '<li>' . $matches[1] . '</li>';
            continue;
        }

        if ($in_list) {
            $html_lines[] = '</ul>';
            $in_list = false;
        }

        $html_lines[] = $line;
    }

    if ($in_list) {
        $html_lines[] = '</ul>';
    }

    $html = implode("\n", $html_lines);
    $html = wpautop($html);

    return wp_kses_post($html);
}

function anonymous_sales_markdown_to_text($markdown) {
    $html = anonymous_sales_markdown_to_html($markdown);
    return trim(wp_strip_all_tags($html));
}

function anonymous_sales_offer_price_to_cents($raw_price) {
    if (!is_numeric($raw_price)) {
        return 0;
    }

    $value = (float) $raw_price;

    // Backward compatibility: old records may store dollars as decimal values.
    if (abs($value - floor($value)) > 0.00001) {
        return max(0, (int) round($value * 100));
    }

    return max(0, (int) round($value));
}

function anonymous_sales_offer_price_to_usd($raw_price) {
    return round(anonymous_sales_offer_price_to_cents($raw_price) / 100, 2);
}

function anonymous_sales_format_usd($raw_price) {
    return number_format(anonymous_sales_offer_price_to_usd($raw_price), 2, '.', '');
}

function anonymous_sales_normalize_resource_checksum($raw_checksum) {
    $checksum = strtolower(trim((string) $raw_checksum));
    $checksum = preg_replace('/\s+/', '', $checksum);

    return $checksum;
}

function anonymous_sales_is_valid_resource_checksum($raw_checksum) {
    $checksum = anonymous_sales_normalize_resource_checksum($raw_checksum);
    if ($checksum === '') {
        return true;
    }

    if (!preg_match('/^(?:(md5|sha1|sha224|sha256|sha384|sha512):)?([a-f0-9]+)$/', $checksum, $matches)) {
        return false;
    }

    $algo = isset($matches[1]) ? (string) $matches[1] : '';
    $hash = (string) $matches[2];
    $length = strlen($hash);

    $expected_lengths = [
        'md5' => 32,
        'sha1' => 40,
        'sha224' => 56,
        'sha256' => 64,
        'sha384' => 96,
        'sha512' => 128,
    ];

    if ($algo !== '') {
        return isset($expected_lengths[$algo]) && $expected_lengths[$algo] === $length;
    }

    return in_array($length, [32, 40, 56, 64, 96, 128], true);
}

if (!file_exists(ANONYMOUS_SALES_INCLUDES_DIR . '/anonymous-sales-library.php')) {
    add_action('admin_notices', 'anonymous_sales_library_missing_notice');
}

if (anonymous_sales_load_library()) {
    anonymous_sales_load_api_module();
}

if (is_admin()) {
    anonymous_sales_load_admin_module();
}

function anonymous_sales_maybe_upgrade() {
    if (!anonymous_sales_load_library()) {
        return;
    }

    $schema = anonymous_sales_get_schema_names();
    $installed_version = get_option($schema['plugin_version'], '0');

    if ($installed_version === ANONYMOUS_SALES_VERSION) {
        anonymous_sales_get_router_auth_key();
        return;
    }

    anonymous_sales_install();
    update_option($schema['plugin_version'], ANONYMOUS_SALES_VERSION);
}

global $wpdb, $anonymous_sales_tables, $anonymous_sales_charset, $anonymous_sales_collation;

$anonymous_sales_tables = anonymous_sales_get_schema_names();

// Database charset and collation on install

$anonymous_sales_charset   = $wpdb->charset;
$anonymous_sales_collation = $wpdb->collate;

function anonymous_sales_install() {
    if (!anonymous_sales_load_library()) {
        return;
    }

    global $wpdb, $anonymous_sales_tables, $anonymous_sales_charset, $anonymous_sales_collation;

    anonymous_sales_protect_var_dir();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    anonymous_sales_migrate_legacy_data();

    $anonymous_sales_sql_offers = "
        CREATE TABLE {$anonymous_sales_tables['offers']} (
          offer_id INT NOT NULL AUTO_INCREMENT,
          offer_name TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation} NOT NULL,
          offer_price DOUBLE NOT NULL,
          offer_description TEXT DEFAULT NULL,
          offer_showContentHeaderOnNewPages INT NOT NULL,
          offer_infoFromSeller TEXT NOT NULL,
                    offer_infoFromSeller_pdf TEXT DEFAULT NULL,
          offer_userLogo TEXT DEFAULT NULL,
                    offer_createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          offer_editedAt TIMESTAMP NULL DEFAULT NULL,
          offer_stamp TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation} NOT NULL,
          PRIMARY KEY  (offer_id)
        ) DEFAULT CHARSET={$anonymous_sales_charset} COLLATE={$anonymous_sales_collation};
    ";

    $anonymous_sales_sql_promocodes = "
        CREATE TABLE {$anonymous_sales_tables['promocodes']} (
          promocode_id INT NOT NULL AUTO_INCREMENT,
          promocode_token TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation} NOT NULL,
          promocode_type INT NOT NULL,
                    promocode_createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          promocode_offer INT DEFAULT NULL,
          promocode_multiUse INT NOT NULL,
          promocode_active INT NOT NULL,
          promocode_usedAt INT NOT NULL,
          promocode_validTill TIMESTAMP NOT NULL,
          PRIMARY KEY  (promocode_id),
          KEY promocode_offer (promocode_offer)
        ) DEFAULT CHARSET={$anonymous_sales_charset} COLLATE={$anonymous_sales_collation};
    ";

    $anonymous_sales_sql_resources = "
        CREATE TABLE {$anonymous_sales_tables['resources']} (
          resource_id INT NOT NULL AUTO_INCREMENT,
          resource_name TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation} NOT NULL,
          resource_description TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation} NOT NULL,
          resource_size DOUBLE DEFAULT NULL,
          resource_password TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation},
          resource_address TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation},
          resource_type TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation},
          resource_offer INT NOT NULL,
                    resource_createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          resource_editedAt TIMESTAMP NULL DEFAULT NULL,
          resource_stamp TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation},
          PRIMARY KEY  (resource_id),
          KEY resource_offer (resource_offer)
        ) DEFAULT CHARSET={$anonymous_sales_charset} COLLATE={$anonymous_sales_collation};
    ";

    $anonymous_sales_sql_orders = "
        CREATE TABLE {$anonymous_sales_tables['orders']} (
          order_id INT NOT NULL AUTO_INCREMENT,
          order_relatedOffer INT DEFAULT NULL,
                    order_createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          order_currentStatus TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation} NOT NULL,
          order_stamp TEXT CHARACTER SET {$anonymous_sales_charset} COLLATE {$anonymous_sales_collation} NOT NULL,
                    order_claimToken VARCHAR(64) NOT NULL,
                    order_offerPage TEXT DEFAULT NULL,
          order_basicPrice DOUBLE NOT NULL,
                    order_priceCurrency VARCHAR(16) NOT NULL DEFAULT 'usd',
          order_usedPromocode INT DEFAULT NULL,
          order_fileList LONGTEXT DEFAULT NULL,
                    order_itemList LONGTEXT DEFAULT NULL,
                    order_offer_snapshot LONGTEXT DEFAULT NULL,
                    order_paymentId VARCHAR(191) DEFAULT NULL,
                    order_invoiceId VARCHAR(191) DEFAULT NULL,
                    order_paymentUrl TEXT DEFAULT NULL,
                    order_webhookPayload LONGTEXT DEFAULT NULL,
                    order_paidAt TIMESTAMP NULL DEFAULT NULL,
          order_finalPrice DOUBLE DEFAULT NULL,
          PRIMARY KEY  (order_id),
          KEY order_relatedOffer (order_relatedOffer),
                    KEY order_usedPromocode (order_usedPromocode),
                    KEY order_claimToken (order_claimToken),
                    KEY order_paymentId (order_paymentId),
                    KEY order_invoiceId (order_invoiceId)
        ) DEFAULT CHARSET={$anonymous_sales_charset} COLLATE={$anonymous_sales_collation};
    ";

    anonymous_sales_run_dbdelta($anonymous_sales_sql_offers, 'offers');
    anonymous_sales_run_dbdelta($anonymous_sales_sql_promocodes, 'promocodes');
    anonymous_sales_run_dbdelta($anonymous_sales_sql_resources, 'resources');
    anonymous_sales_run_dbdelta($anonymous_sales_sql_orders, 'orders');

anonymous_sales_log_info("Tables created or verified", [], false);

anonymous_sales_add_column_if_missing(
    $anonymous_sales_tables['orders'],
    'order_offer_snapshot',
    "ALTER TABLE `{$anonymous_sales_tables['orders']}`
    ADD COLUMN `order_offer_snapshot` LONGTEXT DEFAULT NULL"
);

anonymous_sales_add_column_if_missing(
    $anonymous_sales_tables['orders'],
    'order_invoiceId',
    "ALTER TABLE `{$anonymous_sales_tables['orders']}`
    ADD COLUMN `order_invoiceId` VARCHAR(191) DEFAULT NULL, ADD KEY `order_invoiceId` (`order_invoiceId`)"
);

anonymous_sales_remove_fk_if_exists(
    $anonymous_sales_tables['orders'],
    'fk_anonymous_sales_orders_offers'
);

anonymous_sales_drop_not_null_if_exists(
    $anonymous_sales_tables['orders'],
    'order_relatedOffer'
);

anonymous_sales_add_fk_if_missing(
    'fk_anonymous_sales_promocodes_offers',
    "
    ALTER TABLE `{$anonymous_sales_tables['promocodes']}`
    ADD CONSTRAINT `fk_anonymous_sales_promocodes_offers`
    FOREIGN KEY (`promocode_offer`)
    REFERENCES `{$anonymous_sales_tables['offers']}`(`offer_id`)
    ON DELETE SET NULL
    "
);

anonymous_sales_add_fk_if_missing(
    'fk_anonymous_sales_resources_offers',
    "
    ALTER TABLE `{$anonymous_sales_tables['resources']}`
    ADD CONSTRAINT `fk_anonymous_sales_resources_offers`
    FOREIGN KEY (`resource_offer`)
    REFERENCES `{$anonymous_sales_tables['offers']}`(`offer_id`)
    ON DELETE CASCADE
    "
);

// FK removed: order_offer_snapshot now stores immutable offer data at purchase time

anonymous_sales_add_fk_if_missing(
    'fk_anonymous_sales_orders_promocodes',
    "
    ALTER TABLE `{$anonymous_sales_tables['orders']}`
    ADD CONSTRAINT `fk_anonymous_sales_orders_promocodes`
    FOREIGN KEY (`order_usedPromocode`)
    REFERENCES `{$anonymous_sales_tables['promocodes']}`(`promocode_id`)
    ON DELETE SET NULL
    "
);


    add_option($anonymous_sales_tables['nowpayments_apiKey'], '' );
    add_option($anonymous_sales_tables['nowpayments_webhookKey'], '' );
    add_option($anonymous_sales_tables['nowpayments_webhookToken'], anonymous_sales_generate_random_string(32));
    add_option($anonymous_sales_tables['router_auth_key'], anonymous_sales_generate_router_auth_key());
    add_option($anonymous_sales_tables['nowpayments_send_callback_each_request'], '1');
    add_option($anonymous_sales_tables['nowpayments_send_callback_each_request_user_set'], '1');
    add_option($anonymous_sales_tables['delete_data_on_deactivation'], '0');
    add_option($anonymous_sales_tables['plugin_version'], ANONYMOUS_SALES_VERSION);
    update_option($anonymous_sales_tables['plugin_version'], ANONYMOUS_SALES_VERSION);
    anonymous_sales_log_info("Plugin installed", [], false);
}

add_action('plugins_loaded', 'anonymous_sales_maybe_upgrade');
register_activation_hook(__FILE__, 'anonymous_sales_install');

// ─── D: WP Cron — polling pending orders in background ───────────────────────

add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['anonymous_sales_5min'])) {
        $schedules['anonymous_sales_5min'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 minutes (MFPro Anonymous Digital Sales)',
        ];
    }
    return $schedules;
});

function anonymous_sales_schedule_cron() {
    if (!wp_next_scheduled('anonymous_sales_poll_pending_orders')) {
        wp_schedule_event(time(), 'anonymous_sales_5min', 'anonymous_sales_poll_pending_orders');
    }
}

function anonymous_sales_unschedule_cron() {
    $timestamp = wp_next_scheduled('anonymous_sales_poll_pending_orders');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'anonymous_sales_poll_pending_orders');
    }
}

add_action('anonymous_sales_poll_pending_orders', 'anonymous_sales_handle_poll_pending_orders');

function anonymous_sales_handle_poll_pending_orders() {
    if (!anonymous_sales_load_library() || !anonymous_sales_load_api_module()) {
        return;
    }

    if (!function_exists('anonymous_sales_fetch_payment_for_order')) {
        return;
    }

    global $wpdb;

    $schema = anonymous_sales_get_schema_names();
    $max_orders = (int) apply_filters('anonymous_sales_cron_poll_max_orders', 20);
    $max_orders = max(1, min(100, $max_orders));

    // Orders older than this are left alone: NOWPayments invoices expire long before,
    // and each invoice-only order costs a payment list request per run.
    $max_age_days = max(1, (int) apply_filters('anonymous_sales_cron_poll_max_age_days', 7));

    $orders = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$schema['orders']}
             WHERE order_currentStatus IN ('created', 'pending', 'partially_paid', 'processing')
               AND (COALESCE(order_paymentId, '') != '' OR COALESCE(order_invoiceId, '') != '')
               AND order_createdAt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
             ORDER BY order_createdAt ASC
             LIMIT %d",
            $max_age_days,
            $max_orders
        ),
        ARRAY_A
    );

    if (empty($orders)) {
        return;
    }

    anonymous_sales_log_info('Cron: polling NOWPayments status for pending orders.', [
        'count' => count($orders),
    ]);

    $updated = 0;
    foreach ($orders as $order) {
        $fetched = anonymous_sales_fetch_payment_for_order($order);
        if (is_wp_error($fetched)) {
            continue;
        }
        if (anonymous_sales_apply_fetched_payment_status($order, $fetched)) {
            $updated++;
        }
    }

    anonymous_sales_log_info('Cron: poll finished.', [
        'checked' => count($orders),
        'updated' => $updated,
    ]);
}

add_action('init', 'anonymous_sales_schedule_cron');

// ─────────────────────────────────────────────────────────────────────────────

register_deactivation_hook(__FILE__, function() {
    anonymous_sales_unschedule_cron();

    $user_id   = get_current_user_id();
    $user_info = $user_id ? get_userdata($user_id) : null;

    $user_label = $user_info
        ? " by {$user_info->user_login} (ID: {$user_id})"
        : '';
    if (anonymous_sales_load_library() && function_exists('anonymous_sales_error_log')) {
        anonymous_sales_error_log('[DEACTIVATE] Plugin disabled (data retained)' . $user_label, false);
    }
});

/**
 * Links under the plugin name on the Plugins screen (admin only, plain links, nothing is loaded from them).
 */
function anonymous_sales_plugin_row_meta($links, $file) {
    if (plugin_basename(ANONYMOUS_SALES_PLUGIN_FILE) !== $file) {
        return $links;
    }

    $links[] = '<a href="https://faq.michalfutera.pro/" target="_blank" rel="noopener noreferrer">FAQ</a>';
    $links[] = '<a href="https://github.com/mjfutera/mfpro-anonymous-digital-sales" target="_blank" rel="noopener noreferrer">Source code</a>';
    $links[] = '<a href="https://blogmcp.michalfutera.pro/" target="_blank" rel="noopener noreferrer">MFPro Blog MCP</a>';

    return $links;
}
add_filter('plugin_row_meta', 'anonymous_sales_plugin_row_meta', 10, 2);

function anonymous_sales_register_frontend_assets() {
    wp_register_style(
        'anonymous-sales-frontend',
        ANONYMOUS_SALES_PLUGIN_URL . 'assets/frontend/anonymous-sales-frontend.css',
        [],
        ANONYMOUS_SALES_VERSION
    );

    wp_register_script(
        'anonymous-sales-frontend',
        ANONYMOUS_SALES_PLUGIN_URL . 'assets/frontend/anonymous-sales-frontend.js',
        [],
        ANONYMOUS_SALES_VERSION,
        true
    );

    if (function_exists('anonymous_sales_get_router_url')) {
        $localized_data = [
            'routerUrl' => anonymous_sales_get_router_url(),
        ];
        $localized_data = apply_filters('anonymous_sales_frontend_localize_data', $localized_data);

        wp_localize_script('anonymous-sales-frontend', 'AnonymousSales', $localized_data);
    }
}

function anonymous_sales_enqueue_frontend_assets() {
    wp_enqueue_style('anonymous-sales-frontend');
    wp_enqueue_script('anonymous-sales-frontend');

    do_action('anonymous_sales_frontend_assets_enqueued');
}

function anonymous_sales_shortcode_custom_css_enabled($raw_value) {
    $value = strtolower(trim((string) $raw_value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function anonymous_sales_resource_field_label($field_key) {
    $normalized = str_replace('resource_', '', (string) $field_key);
    $normalized = str_replace('_', ' ', $normalized);
    return ucwords($normalized);
}

function anonymous_sales_get_current_page_url() {
    global $wp;

    if (isset($wp) && isset($wp->request)) {
        $relative = '/' . ltrim((string) $wp->request, '/');
        return home_url($relative);
    }

    return home_url(add_query_arg([], ''));
}

function anonymous_sales_maybe_serve_external_order_script() {
    if (is_admin()) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
    $request_path = '/' . ltrim($request_path, '/');
    if (substr($request_path, -strlen('/externalorder.js')) !== '/externalorder.js') {
        return;
    }

    if (!function_exists('anonymous_sales_get_offer_record') || !function_exists('anonymous_sales_get_offer_resources')) {
        if (function_exists('anonymous_sales_log_error')) {
            anonymous_sales_log_error('External order bootstrap script requested but API module is unavailable.');
        }

        status_header(500);
        header('Content-Type: application/javascript; charset=UTF-8');
        echo 'window.console && console.error("MFPro Anonymous Digital Sales API module is not available.");';
        exit;
    }

    $offer_id = isset($_GET['offer']) ? absint($_GET['offer']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only script for the embed, nothing is changed.
    $offer = $offer_id > 0 ? anonymous_sales_get_offer_record($offer_id) : null;
    if (!is_array($offer) && function_exists('anonymous_sales_log_warning')) {
        anonymous_sales_log_warning('External order bootstrap requested for missing offer.', [
            'offer_id' => (int) $offer_id,
        ]);
    }

    $resources = is_array($offer) ? anonymous_sales_get_offer_resources($offer_id) : [];
    $safe_resources = [];

    foreach ($resources as $resource) {
        if (!is_array($resource)) {
            continue;
        }

        $safe_resources[] = [
            'name' => (string) ($resource['resource_name'] ?? ''),
            'description' => anonymous_sales_markdown_to_text((string) ($resource['resource_description'] ?? '')),
            'size' => (string) ($resource['resource_size'] ?? ''),
            'type' => (string) ($resource['resource_type'] ?? ''),
            'hash' => (string) ($resource['resource_stamp'] ?? ''),
        ];
    }

    $bootstrap_config = [
        'offerId' => $offer_id,
        'routerUrl' => function_exists('anonymous_sales_get_router_url') ? anonymous_sales_get_router_url() : '',
        'offer' => [
            'name' => is_array($offer) ? (string) ($offer['offer_name'] ?? '') : '',
            'description' => is_array($offer) ? anonymous_sales_markdown_to_text((string) ($offer['offer_description'] ?? '')) : '',
            'priceUsd' => is_array($offer) ? anonymous_sales_format_usd($offer['offer_price'] ?? 0) : '0.00',
        ],
        'resources' => $safe_resources,
    ];

    $bootstrap_config = apply_filters('anonymous_sales_external_order_bootstrap_config', $bootstrap_config, $offer, $resources);

    if (function_exists('anonymous_sales_log_info')) {
        anonymous_sales_log_info('External order bootstrap served.', [
            'offer_id' => (int) $offer_id,
            'resource_count' => (int) count($safe_resources),
        ]);
    }

    $external_script_url = add_query_arg(
        'v',
        ANONYMOUS_SALES_VERSION,
        ANONYMOUS_SALES_PLUGIN_URL . 'assets/frontend/anonymous-sales-external.js'
    );

    do_action('anonymous_sales_external_order_script_served', $bootstrap_config, $offer, $resources);

    nocache_headers();
    header('Content-Type: application/javascript; charset=UTF-8');
    echo 'window.AnonymousSalesExternalOrderBootstrap = ' . wp_json_encode($bootstrap_config) . ';';
    echo "\n";
    echo '(function(){var d=document,s=d.createElement("script");s.src=' . wp_json_encode($external_script_url) . ';s.defer=true;(d.head||d.documentElement).appendChild(s);}());';
    exit;
}

/**
 * What a shortcode may return. WordPress prints the return value as it is, so the whole
 * result -- including whatever other code adds through the before/after/html filters --
 * goes through wp_kses with this list: the post tags plus the form controls and the
 * disclosure element of the buy dialog and the status form.
 */
function anonymous_sales_shortcode_allowed_html() {
    $allowed = wp_kses_allowed_html('post');
    $controls = [
        'form' => ['class' => true, 'id' => true, 'action' => true, 'method' => true, 'hidden' => true, 'data-*' => true],
        'input' => ['class' => true, 'id' => true, 'type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'required' => true, 'readonly' => true, 'disabled' => true, 'checked' => true, 'autocomplete' => true, 'min' => true, 'max' => true, 'step' => true, 'hidden' => true, 'data-*' => true],
        'select' => ['class' => true, 'id' => true, 'name' => true, 'required' => true, 'disabled' => true, 'data-*' => true],
        'option' => ['value' => true, 'selected' => true, 'disabled' => true],
        'textarea' => ['class' => true, 'id' => true, 'name' => true, 'placeholder' => true, 'required' => true, 'readonly' => true, 'rows' => true, 'cols' => true, 'data-*' => true],
        'label' => ['class' => true, 'for' => true],
        'button' => ['class' => true, 'id' => true, 'type' => true, 'name' => true, 'value' => true, 'disabled' => true, 'aria-expanded' => true, 'aria-label' => true, 'data-*' => true],
        'details' => ['class' => true, 'open' => true, 'data-*' => true],
        'summary' => ['class' => true],
    ];
    foreach ($controls as $tag => $attributes) {
        $existing = isset($allowed[$tag]) && is_array($allowed[$tag]) ? $allowed[$tag] : [];
        $allowed[$tag] = array_merge($existing, $attributes);
    }

    return apply_filters('anonymous_sales_shortcode_allowed_html', $allowed);
}

function anonymous_sales_shortcode_output($html) {
    return wp_kses((string) $html, anonymous_sales_shortcode_allowed_html());
}

function anonymous_sales_shortcode_buy_button($atts = []) {
    if (!function_exists('anonymous_sales_get_offer_record')) {
        return '';
    }

    $atts = shortcode_atts([
        'offer_id' => 0,
        'label' => 'Buy Now',
        'title' => 'Anonymous purchase',
        'customCSS' => 'false',
    ], $atts, 'anonymous_sales_buy');
    $atts = apply_filters('anonymous_sales_shortcode_buy_atts', $atts);

    $custom_css = anonymous_sales_shortcode_custom_css_enabled($atts['customCSS']);
    $class = static function ($name) use ($custom_css) {
        if ($custom_css) {
            return 'anonymousSP-buy' . ($name !== '' ? '-' . $name : '');
        }
        return 'anonymous-sales' . ($name !== '' ? '-' . $name : '');
    };

    $offer_id = absint($atts['offer_id']);
    if ($offer_id <= 0) {
        return anonymous_sales_shortcode_output(apply_filters('anonymous_sales_shortcode_buy_html', '<p>Invalid offer ID.</p>', $atts, null, []));
    }

    $offer = anonymous_sales_get_offer_record($offer_id);
    if (!is_array($offer)) {
        return anonymous_sales_shortcode_output(apply_filters('anonymous_sales_shortcode_buy_html', '<p>Offer not found.</p>', $atts, null, []));
    }

    $resources = anonymous_sales_get_offer_resources($offer_id);
    $resources = apply_filters('anonymous_sales_shortcode_buy_resources', $resources, $offer, $atts);

    $excluded_fields = ['resource_id', 'resource_password', 'resource_address', 'resource_createdAt', 'resource_editedAt', 'resource_offer'];
    $excluded_fields = apply_filters('anonymous_sales_shortcode_buy_excluded_fields', $excluded_fields, $offer, $atts);

    $default_per_page = 5;
    $default_per_page = (int) apply_filters('anonymous_sales_shortcode_buy_default_per_page', $default_per_page, $offer, $atts);
    if ($default_per_page <= 0) {
        $default_per_page = 5;
    }

    $dialog_id = 'anonymous-sales-anon-dialog-' . $offer_id . '-' . wp_rand(1000, 9999);
    $current_page_url = anonymous_sales_get_current_page_url();
    $current_page_url = apply_filters('anonymous_sales_shortcode_buy_offer_page_url', $current_page_url, $offer, $atts);

    anonymous_sales_enqueue_frontend_assets();

    $before_html = apply_filters('anonymous_sales_shortcode_buy_before_html', '', $atts, $offer, $resources);
    $after_html = apply_filters('anonymous_sales_shortcode_buy_after_html', '', $atts, $offer, $resources);

    ob_start();
    ?>
    <div class="<?php echo esc_attr($class('')); ?>">
        <button class="<?php echo esc_attr($class('open-btn')); ?>" data-anonymous-sales-open-dialog="<?php echo esc_attr($dialog_id); ?>"><?php echo esc_html($atts['label']); ?></button>

        <div class="<?php echo esc_attr($class('dialog')); ?>" id="<?php echo esc_attr($dialog_id); ?>" hidden>
            <div class="<?php echo esc_attr($class('backdrop')); ?>" data-anonymous-sales-close-dialog="<?php echo esc_attr($dialog_id); ?>"></div>
            <div class="<?php echo esc_attr($class('panel')); ?>" data-anonymous-sales-buy-panel>
                <button class="<?php echo esc_attr($class('close')); ?>" data-anonymous-sales-close-dialog="<?php echo esc_attr($dialog_id); ?>" aria-label="Close">x</button>
                <h2 class="<?php echo esc_attr($class('title')); ?>"><?php echo esc_html($atts['title']); ?></h2>
                <p class="<?php echo esc_attr($class('offer-summary')); ?>"><strong class="<?php echo esc_attr($class('offer-name')); ?>"><?php echo esc_html((string) $offer['offer_name']); ?></strong> | <span class="<?php echo esc_attr($class('offer-price')); ?>"><?php echo esc_html(anonymous_sales_format_usd($offer['offer_price'])); ?> USD</span></p>
                <div class="<?php echo esc_attr($class('offer-description')); ?>"><?php echo wp_kses_post(anonymous_sales_markdown_to_html((string) $offer['offer_description'])); ?></div>

                <details class="<?php echo esc_attr($class('accepted-crypto')); ?>" data-anonymous-sales-accepted-crypto-block>
                    <summary><strong>Accepted cryptocurrencies</strong></summary>
                    <div style="margin-top:8px;">
                        <p class="<?php echo esc_attr($class('accepted-crypto-content')); ?>" data-anonymous-sales-accepted-crypto-content style="margin:0;white-space:pre-wrap;">Loading accepted cryptocurrencies...</p>
                    </div>
                </details>

                <h3 class="<?php echo esc_attr($class('resources-title')); ?>">Included resources</h3>
                <div class="<?php echo esc_attr($class('resource-browser')); ?>" data-anonymous-sales-resource-browser data-default-per-page="<?php echo esc_attr((string) $default_per_page); ?>">
                    <div class="<?php echo esc_attr($class('resource-toolbar')); ?>">
                        <label class="<?php echo esc_attr($class('resource-search-wrap')); ?>">
                            <span class="<?php echo esc_attr($class('resource-search-label')); ?>">Search resources</span>
                            <input type="search" class="<?php echo esc_attr($class('resource-search')); ?>" data-anonymous-sales-resource-search placeholder="Type to search resources" />
                        </label>
                        <label class="<?php echo esc_attr($class('resource-per-page-wrap')); ?>">
                            <span class="<?php echo esc_attr($class('resource-per-page-label')); ?>">Per page</span>
                            <select class="<?php echo esc_attr($class('resource-per-page')); ?>" data-anonymous-sales-resource-per-page>
                                <option value="5" selected>5</option>
                                <option value="10">10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                            </select>
                        </label>
                    </div>

                    <p class="<?php echo esc_attr($class('resource-summary')); ?>">All resources in this offer: <strong data-anonymous-sales-resource-total><?php echo esc_html((string) count($resources)); ?></strong></p>

                    <ul class="<?php echo esc_attr($class('list')); ?>" data-anonymous-sales-resource-list>
                        <?php foreach ($resources as $index => $resource) : ?>
                            <?php
                            $search_parts = [];
                            foreach ($resource as $field_key => $field_value) {
                                if (in_array((string) $field_key, $excluded_fields, true)) {
                                    continue;
                                }
                                $search_parts[] = (string) $field_value;
                            }
                            $search_text = strtolower(implode(' ', $search_parts));
                            $resource_name = (string) ($resource['resource_name'] ?? ('Resource #' . ((int) $index + 1)));
                            ?>
                            <li class="<?php echo esc_attr($class('list-item')); ?>" data-anonymous-sales-resource-item data-anonymous-sales-resource-search-text="<?php echo esc_attr($search_text); ?>">
                                <button type="button" class="<?php echo esc_attr($class('resource-toggle')); ?>" data-anonymous-sales-resource-toggle aria-expanded="false">
                                    <span class="<?php echo esc_attr($class('resource-order')); ?>" data-anonymous-sales-resource-order>1.</span>
                                    <span class="<?php echo esc_attr($class('resource-name')); ?>"><?php echo esc_html($resource_name); ?></span>
                                    <span class="<?php echo esc_attr($class('resource-hint')); ?>" data-anonymous-sales-resource-hint>click for details</span>
                                    <span class="<?php echo esc_attr($class('resource-chevron')); ?>" aria-hidden="true">&#9662;</span>
                                </button>

                                <div class="<?php echo esc_attr($class('resource-panel')); ?>" data-anonymous-sales-resource-panel hidden>
                                    <table class="<?php echo esc_attr($class('resource-fields')); ?>">
                                        <tbody>
                                            <?php foreach ($resource as $field_key => $field_value) : ?>
                                                <?php if (in_array((string) $field_key, $excluded_fields, true)) {
                                                    continue;
                                                } ?>
                                                <tr>
                                                    <th><?php echo esc_html(anonymous_sales_resource_field_label($field_key)); ?></th>
                                                    <td>
                                                        <?php if ((string) $field_key === 'resource_description') : ?>
                                                            <div class="<?php echo esc_attr($class('resource-description')); ?>"><?php echo wp_kses_post(anonymous_sales_markdown_to_html((string) $field_value)); ?></div>
                                                        <?php else : ?>
                                                            <?php echo esc_html((string) $field_value); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="<?php echo esc_attr($class('resource-empty')); ?>" data-anonymous-sales-resource-empty hidden>No resources match your search.</p>

                    <div class="<?php echo esc_attr($class('resource-pagination')); ?>" data-anonymous-sales-resource-pagination>
                        <button type="button" class="<?php echo esc_attr($class('resource-page-btn')); ?>" data-anonymous-sales-resource-prev>Previous</button>
                        <span class="<?php echo esc_attr($class('resource-page-info')); ?>" data-anonymous-sales-resource-page>Page 1 / 1</span>
                        <button type="button" class="<?php echo esc_attr($class('resource-page-btn')); ?>" data-anonymous-sales-resource-next>Next</button>
                    </div>
                </div>

                <form class="<?php echo esc_attr($class('form')); ?>" data-anonymous-sales-create-order-form data-offer-id="<?php echo esc_attr((string) $offer_id); ?>" data-offer-page="<?php echo esc_url($current_page_url); ?>">
                    <div class="<?php echo esc_attr($class('actions')); ?>">
                        <button type="submit" class="<?php echo esc_attr($class('submit-btn')); ?>" data-anonymous-sales-create-order-btn>Create order and pay</button>
                    </div>
                    <div class="<?php echo esc_attr($class('status')); ?>" data-anonymous-sales-order-status>Order not started.</div>
                </form>

                <div class="<?php echo esc_attr($class('meta')); ?>" style="margin-top: 12px; display:none;" data-anonymous-sales-order-meta title="Click to copy order data">
                    <p class="<?php echo esc_attr($class('meta-warning')); ?>"><strong>Important:</strong> Copy and save these values on your computer or phone before closing this dialog.</p>
                    <p class="<?php echo esc_attr($class('order-row')); ?>">Order number: <span class="<?php echo esc_attr($class('order-number')); ?>" data-anonymous-sales-order-number>-</span></p>
                    <p class="<?php echo esc_attr($class('claim-row')); ?>">Claim token: <span class="<?php echo esc_attr($class('claim-token')); ?>" data-anonymous-sales-claim-token>-</span></p>
                    <p class="<?php echo esc_attr($class('copy-hint')); ?>" data-anonymous-sales-copy-hint>Click this whole box to copy order number and claim token.</p>
                </div>
                <p class="<?php echo esc_attr($class('tab-close-hint')); ?>" data-anonymous-sales-tab-close-hint hidden>If you close this tab, don't worry &mdash; return to this page and use the <strong>Check payment status</strong> button above, or find the Order Status form on this site. You'll need your order number and claim token.</p>
                <a href="#" target="_blank" rel="noopener noreferrer" class="<?php echo esc_attr($class('payment-link')); ?>" style="display:none;" data-anonymous-sales-payment-url>Open payment page</a>
                <div class="<?php echo esc_attr($class('status-check')); ?>" data-anonymous-sales-buy-status-check-wrap>
                    <button type="button" class="<?php echo esc_attr($class('check-status-btn')); ?>" data-anonymous-sales-buy-check-status-btn>Check payment status</button>
                    <div class="<?php echo esc_attr($class('status-result')); ?>" data-anonymous-sales-buy-status-result style="margin-top:8px;display:none;"></div>
                    <div class="<?php echo esc_attr($class('download-wrap')); ?>" data-anonymous-sales-buy-download-wrap style="margin-top:8px;display:none;">
                        <a href="#" class="<?php echo esc_attr($class('download-link')); ?>" data-anonymous-sales-buy-download-link target="_blank" rel="noopener noreferrer">Download PDF</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    $html = (string) $before_html . ob_get_clean() . (string) $after_html;
    $html = apply_filters('anonymous_sales_shortcode_buy_html', $html, $atts, $offer, $resources);
    do_action('anonymous_sales_shortcode_buy_rendered', $atts, $offer, $resources, $html);

    return anonymous_sales_shortcode_output($html);
}

function anonymous_sales_shortcode_status_form($atts = []) {
    if (!function_exists('anonymous_sales_get_router_url')) {
        return '';
    }

    $atts = shortcode_atts([
        'title' => 'Check order status',
        'button_label' => 'Check status',
        'download_label' => 'Download PDF',
        'customCSS' => 'false',
    ], $atts, 'anonymous_sales_status');
    $atts = apply_filters('anonymous_sales_shortcode_status_atts', $atts);

    $custom_css = anonymous_sales_shortcode_custom_css_enabled($atts['customCSS']);
    $class = static function ($name) use ($custom_css) {
        if ($custom_css) {
            return 'anonymousSP-status' . ($name !== '' ? '-' . $name : '');
        }
        return 'anonymous-sales' . ($name !== '' ? '-' . $name : '');
    };
    $grow_class = $custom_css ? 'anonymousSP-status-grow' : 'anonymous-sales-grow';

    anonymous_sales_enqueue_frontend_assets();

    $before_html = apply_filters('anonymous_sales_shortcode_status_before_html', '', $atts);
    $after_html = apply_filters('anonymous_sales_shortcode_status_after_html', '', $atts);

    ob_start();
    ?>
    <div class="<?php echo esc_attr($class('')); ?>">
        <h3 class="<?php echo esc_attr($class('title')); ?>"><?php echo esc_html($atts['title']); ?></h3>
        <form class="<?php echo esc_attr($class('form')); ?>" data-anonymous-sales-check-status-form>
            <div class="<?php echo esc_attr($class('row')); ?>">
                <div class="<?php echo esc_attr($grow_class . ' ' . $class('order-field')); ?>">
                    <label class="<?php echo esc_attr($class('order-label')); ?>">Order number</label>
                    <input class="<?php echo esc_attr($class('order-input')); ?>" type="text" name="order_number" required />
                </div>
                <div class="<?php echo esc_attr($grow_class . ' ' . $class('token-field')); ?>">
                    <label class="<?php echo esc_attr($class('token-label')); ?>">Claim token</label>
                    <input class="<?php echo esc_attr($class('token-input')); ?>" type="text" name="claim_token" required />
                </div>
            </div>
            <div class="<?php echo esc_attr($class('actions')); ?>">
                <button type="submit" class="<?php echo esc_attr($class('submit-btn')); ?>" data-anonymous-sales-check-status-btn><?php echo esc_html($atts['button_label']); ?></button>
                <a href="#" class="<?php echo esc_attr($class('download-link')); ?>" data-anonymous-sales-download-btn style="display:none;" target="_blank" rel="noopener noreferrer"><?php echo esc_html($atts['download_label']); ?></a>
            </div>
            <div class="<?php echo esc_attr($class('status')); ?>" data-anonymous-sales-check-status-result>Status unknown.</div>
        </form>
    </div>
    <?php
    $html = (string) $before_html . ob_get_clean() . (string) $after_html;
    $html = apply_filters('anonymous_sales_shortcode_status_html', $html, $atts);
    do_action('anonymous_sales_shortcode_status_rendered', $atts, $html);

    return anonymous_sales_shortcode_output($html);
}

add_action('init', 'anonymous_sales_register_frontend_assets');
add_action('init', 'anonymous_sales_maybe_serve_external_order_script', 1);
add_shortcode('anonymous_sales_buy', 'anonymous_sales_shortcode_buy_button');
add_shortcode('anonymous_sales_status', 'anonymous_sales_shortcode_status_form');

// Shortcodes already placed in pages by the versions released before the rename.
add_shortcode('mfpro_anonymoussales_buy', 'anonymous_sales_shortcode_buy_button');
add_shortcode('mfpro_anonymoussales_status', 'anonymous_sales_shortcode_status_form');


// NowPayments Webhook Handler
