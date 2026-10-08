<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin owns these tables; table names come from its own schema (anonymous_sales_get_schema_names), values are always passed through $wpdb->prepare() or the format arguments of insert()/update().

function anonymous_sales_is_post_request() {
    return isset($_SERVER['REQUEST_METHOD']) && 'POST' === sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']));
}

defined('ABSPATH') || exit;

function anonymous_sales_admin_pages() {
    return [
        'anonymous-sales',
        'anonymous-sales-offers',
        'anonymous-sales-promocodes',
        'anonymous-sales-orders',
        'anonymous-sales-settings',
        'anonymous-sales-error-log',
    ];
}

function anonymous_sales_log_admin_action($action, $context = [], $level = 'info') {
    if (!function_exists('anonymous_sales_log')) {
        return;
    }

    $base_context = [
        'source' => 'admin_ui',
        'page' => sanitize_key(wp_unslash((string) ($_GET['page'] ?? 'unknown'))), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, nothing is changed.
        'method' => sanitize_key(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? 'unknown'))),
        'user_id' => get_current_user_id(),
    ];

    anonymous_sales_log($level, '[ADMIN ACTION] ' . (string) $action, array_merge($base_context, (array) $context));
}

function anonymous_sales_log_db_outcome($operation, $table, $result, $context = []) {
    global $wpdb;

    $payload = array_merge([
        'operation' => (string) $operation,
        'table' => (string) $table,
        'result' => $result,
        'wpdb_last_error' => (string) $wpdb->last_error,
        'source' => 'admin_db',
    ], (array) $context);

    if ($result === false) {
        if (function_exists('anonymous_sales_log_error')) {
            anonymous_sales_log_error('Admin DB operation failed.', $payload);
        }
        return;
    }

    if (function_exists('anonymous_sales_log_info')) {
        anonymous_sales_log_info('Admin DB operation completed.', $payload);
    }
}

function anonymous_sales_extract_order_offer_snapshot($order) {
    if (!isset($order['order_offer_snapshot']) || empty($order['order_offer_snapshot'])) {
        return null;
    }

    $snapshot = json_decode($order['order_offer_snapshot'], true);
    if (!is_array($snapshot)) {
        return null;
    }

    return $snapshot;
}

function anonymous_sales_register_admin_menu() {
    $capability = 'manage_options';
    $parent_slug = 'anonymous-sales';

    add_menu_page(
        'Anonymous Sales',
        'Anonymous Sales',
        $capability,
        $parent_slug,
        'anonymous_sales_render_dashboard_page',
        'dashicons-lock',
        56
    );

    add_submenu_page($parent_slug, 'Dashboard', 'Dashboard', $capability, $parent_slug, 'anonymous_sales_render_dashboard_page');
    add_submenu_page($parent_slug, 'Offers', 'Offers', $capability, 'anonymous-sales-offers', 'anonymous_sales_render_offers_page');
    add_submenu_page($parent_slug, 'Orders', 'Orders', $capability, 'anonymous-sales-orders', 'anonymous_sales_render_orders_page');
    add_submenu_page($parent_slug, 'Settings', 'Settings', $capability, 'anonymous-sales-settings', 'anonymous_sales_render_settings_page');
    add_submenu_page($parent_slug, 'Error log', 'Error log', $capability, 'anonymous-sales-error-log', 'anonymous_sales_render_error_log_page');
}

function anonymous_sales_admin_enqueue_assets($hook_suffix) {
    if (strpos($hook_suffix, 'anonymous-sales') === false) {
        return;
    }

    wp_enqueue_media();

    wp_add_inline_style('common', '
        .anonymous-sales-admin-wrap { max-width: none; width: 100%; margin-right: 0; padding-right: 0; box-sizing: border-box; }
        .anonymous-sales-admin-wrap .card { max-width: none !important; }
        .anonymous-sales-admin-grid { display: block; width: 100%; }
        .anonymous-sales-admin-card { padding: 16px; margin: 0; width: 100%; box-sizing: border-box; }
        .anonymous-sales-admin-card + .anonymous-sales-admin-card { margin-top: 16px; }
        .anonymous-sales-admin-full { width: 100%; max-width: none; }
        .anonymous-sales-admin-wrap .widefat { width: 100%; table-layout: auto; }
        .anonymous-sales-admin-wrap .form-table,
        .anonymous-sales-admin-wrap .form-table tbody,
        .anonymous-sales-admin-wrap .form-table tr,
        .anonymous-sales-admin-wrap .form-table td { width: 100%; }
        .anonymous-sales-admin-wrap .form-table th { width: 220px; }
        .anonymous-sales-admin-wrap .regular-text,
        .anonymous-sales-admin-wrap .large-text,
        .anonymous-sales-admin-wrap input[type="text"],
        .anonymous-sales-admin-wrap input[type="url"],
        .anonymous-sales-admin-wrap input[type="number"],
        .anonymous-sales-admin-wrap input[type="datetime-local"],
        .anonymous-sales-admin-wrap select,
        .anonymous-sales-admin-wrap textarea { max-width: none; width: 100%; }
        .anonymous-sales-admin-wrap .button { width: auto; }
        .anonymous-sales-admin-wrap .anonymous-sales-action-btn {
            min-width: 88px;
            height: 32px;
            line-height: 30px;
            text-align: center;
            border-radius: 6px;
            box-sizing: border-box;
            margin: 0;
        }
        .anonymous-sales-admin-wrap .anonymous-sales-action-edit {
            background: #0088cc;
            border-color: #0088cc;
            color: #ffffff;
        }
        .anonymous-sales-admin-wrap .anonymous-sales-action-edit:hover,
        .anonymous-sales-admin-wrap .anonymous-sales-action-edit:focus {
            background: #0077b3;
            border-color: #0077b3;
            color: #ffffff;
        }
        .anonymous-sales-admin-wrap .anonymous-sales-action-delete {
            background: #d63638;
            border-color: #d63638;
            color: #ffffff;
        }
        .anonymous-sales-admin-wrap .anonymous-sales-action-delete:hover,
        .anonymous-sales-admin-wrap .anonymous-sales-action-delete:focus {
            background: #b32d2e;
            border-color: #b32d2e;
            color: #ffffff;
        }
        .anonymous-sales-admin-wrap .anonymous-sales-action-cell form { display: inline-block; margin: 0 0 0 6px; }
        .anonymous-sales-admin-wrap .anonymous-sales-url-picker { display: flex; gap: 8px; align-items: center; }
        .anonymous-sales-admin-wrap .anonymous-sales-url-picker input { flex: 1; }
        .anonymous-sales-admin-wrap .anonymous-sales-url-picker .button { white-space: nowrap; }
        .anonymous-sales-admin-wrap .anonymous-sales-media-preview {
            margin-top: 10px;
            width: 150px;
            height: 150px;
            object-fit: cover;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            background: #f6f7f7;
        }
    ');
}

function anonymous_sales_render_admin_page_start($title, $description = '') {
    echo '<div class="wrap anonymous-sales-admin-wrap">';
    echo '<h1>' . esc_html($title) . '</h1>';
    if ($description !== '') {
        echo '<p>' . esc_html($description) . '</p>';
    }
}

function anonymous_sales_render_admin_page_end() {
    echo '</div>';
}

function anonymous_sales_get_table_name($key) {
    $schema = anonymous_sales_get_schema_names();
    return $schema[$key];
}

function anonymous_sales_admin_notice($notice) {
    if (!is_array($notice) || !isset($notice['type'], $notice['message'])) {
        return;
    }

    $type = $notice['type'] === 'error' ? 'error' : 'success';
    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
}

function anonymous_sales_read_file_tail($file_path, $max_bytes = 131072) {
    $file_path = (string) $file_path;
    $max_bytes = max(1024, (int) $max_bytes);

    if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
        return '';
    }

    $size = filesize($file_path);
    if (!is_int($size) || $size <= 0) {
        return '';
    }

    $offset = max(0, $size - $max_bytes);
    $content = file_get_contents($file_path, false, null, $offset, $max_bytes);

    if (!is_string($content)) {
        return '';
    }

    if ($offset > 0) {
        $first_newline = strpos($content, "\n");
        if ($first_newline !== false) {
            $content = substr($content, $first_newline + 1);
        }
    }

    return trim($content);
}

function anonymous_sales_get_error_log_preview($max_bytes = 131072) {
    if (!function_exists('anonymous_sales_get_error_log_file_path')) {
        return [
            'content' => 'Error log path helper is not available.',
            'exists' => false,
        ];
    }

    $log_file = anonymous_sales_get_error_log_file_path();
    if (!file_exists($log_file)) {
        return [
            'content' => 'Log file does not exist yet. Trigger an action to create first entries.',
            'exists' => false,
        ];
    }

    $content = anonymous_sales_read_file_tail($log_file, $max_bytes);
    if ($content === '') {
        $content = 'Log file is empty.';
    }

    return [
        'content' => $content,
        'exists' => true,
    ];
}

function anonymous_sales_filter_log_by_level($content, $level) {
    $content = (string) $content;
    $level = strtoupper(trim((string) $level));

    if ($content === '' || $level === '' || $level === 'ALL') {
        return $content;
    }

    $needle = '[' . $level . ']';
    $lines = preg_split('/\r\n|\r|\n/', $content);
    if (!is_array($lines)) {
        return $content;
    }

    $filtered = [];
    foreach ($lines as $line) {
        if (strpos((string) $line, $needle) !== false) {
            $filtered[] = (string) $line;
        }
    }

    if (empty($filtered)) {
        return 'No log entries for level ' . $level . '.';
    }

    return implode("\n", $filtered);
}

function anonymous_sales_get_log_level_badge_style($level) {
    $level = strtoupper(trim((string) $level));

    switch ($level) {
        case 'ERROR':
            return 'background:#fbeaea;color:#8b0000;border:1px solid #f1b5b5;';
        case 'WARNING':
            return 'background:#fff7e6;color:#8a5800;border:1px solid #f0d195;';
        case 'INFO':
            return 'background:#eaf4ff;color:#0b4f8a;border:1px solid #b8d8f5;';
        case 'DEBUG':
            return 'background:#f2f2f4;color:#4d4d57;border:1px solid #d4d4dc;';
        default:
            return 'background:#f7f7f7;color:#555;border:1px solid #dcdcde;';
    }
}

function anonymous_sales_parse_log_entries($content, $reverse = true) {
    $content = (string) $content;
    $raw_lines = preg_split('/\r\n|\r|\n/', $content);
    $entries = [];

    foreach ((array) $raw_lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $level = 'UNKNOWN';
        if (preg_match('/\[(ERROR|WARNING|INFO|DEBUG)\]/i', $line, $matches)) {
            $level = strtoupper((string) $matches[1]);
        }

        $entries[] = [
            'level' => $level,
            'line' => $line,
        ];
    }

    if ($reverse && !empty($entries)) {
        $entries = array_reverse($entries);
    }

    return $entries;
}

function anonymous_sales_handle_refresh_error_log_ajax() {
    anonymous_sales_log_admin_action('refresh_error_log_click');

    if (!current_user_can('manage_options')) {
        anonymous_sales_log_admin_action('refresh_error_log_denied', [], 'warning');
        wp_send_json_error([
            'message' => 'Insufficient permissions.',
        ], 403);
    }

    check_ajax_referer('anonymous_sales_error_log_refresh', 'nonce');

    $error_log_preview = anonymous_sales_get_error_log_preview();
    $entries = anonymous_sales_parse_log_entries((string) ($error_log_preview['content'] ?? ''), true);

    wp_send_json_success([
        'entries' => $entries,
        'exists' => !empty($error_log_preview['exists']),
        'fetched_at' => current_time('mysql', true),
    ]);
}

function anonymous_sales_get_offers() {
    global $wpdb;
    $table = anonymous_sales_get_table_name('offers');
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY offer_id DESC", ARRAY_A);

    if ($rows === null || $wpdb->last_error !== '') {
        anonymous_sales_log_db_outcome('select', $table, false, ['query' => 'get_offers']);
    }

    return is_array($rows) ? $rows : [];
}

function anonymous_sales_get_offer($offer_id) {
    global $wpdb;
    $table = anonymous_sales_get_table_name('offers');

    if ($offer_id <= 0) {
        return null;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE offer_id = %d", $offer_id), ARRAY_A);

    if ($row === null && $wpdb->last_error !== '') {
        anonymous_sales_log_db_outcome('select', $table, false, [
            'query' => 'get_offer',
            'offer_id' => (int) $offer_id,
        ]);
    }

    return $row;
}

function anonymous_sales_get_resources() {
    global $wpdb;
    $resources_table = anonymous_sales_get_table_name('resources');
    $offers_table = anonymous_sales_get_table_name('offers');

    $rows = $wpdb->get_results(
        "SELECT r.*, o.offer_name FROM {$resources_table} r LEFT JOIN {$offers_table} o ON o.offer_id = r.resource_offer ORDER BY r.resource_id DESC",
        ARRAY_A
    );

    if ($rows === null || $wpdb->last_error !== '') {
        anonymous_sales_log_db_outcome('select', $resources_table, false, ['query' => 'get_resources']);
        return [];
    }

    foreach ($rows as &$row) {
        $row = anonymous_sales_decrypt_resource_password_row($row);
    }
    unset($row);

    return $rows;
}

function anonymous_sales_get_resources_by_offer($offer_id) {
    global $wpdb;
    $table = anonymous_sales_get_table_name('resources');

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE resource_offer = %d ORDER BY resource_id DESC",
            $offer_id
        ),
        ARRAY_A
    );

    if ($rows === null || $wpdb->last_error !== '') {
        anonymous_sales_log_db_outcome('select', $table, false, [
            'query' => 'get_resources_by_offer',
            'offer_id' => (int) $offer_id,
        ]);
        return [];
    }

    foreach ($rows as &$row) {
        $row = anonymous_sales_decrypt_resource_password_row($row);
    }
    unset($row);

    return $rows;
}

function anonymous_sales_get_resource($resource_id) {
    global $wpdb;
    $table = anonymous_sales_get_table_name('resources');

    if ($resource_id <= 0) {
        return null;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE resource_id = %d", $resource_id), ARRAY_A);

    if ($row === null && $wpdb->last_error !== '') {
        anonymous_sales_log_db_outcome('select', $table, false, [
            'query' => 'get_resource',
            'resource_id' => (int) $resource_id,
        ]);
    }

    if (!is_array($row)) {
        return null;
    }

    return anonymous_sales_decrypt_resource_password_row($row);
}

function anonymous_sales_decrypt_resource_password_row(array $row) {
    if (array_key_exists('resource_password', $row)) {
        $row['resource_password'] = anonymous_sales_decrypt_secret((string) $row['resource_password']);
    }

    return $row;
}

function anonymous_sales_get_promocodes() {
    global $wpdb;
    $promo_table = anonymous_sales_get_table_name('promocodes');
    $offers_table = anonymous_sales_get_table_name('offers');

    $rows = $wpdb->get_results(
        "SELECT p.*, o.offer_name FROM {$promo_table} p LEFT JOIN {$offers_table} o ON o.offer_id = p.promocode_offer ORDER BY p.promocode_id DESC",
        ARRAY_A
    );

    if ($rows === null || $wpdb->last_error !== '') {
        anonymous_sales_log_db_outcome('select', $promo_table, false, ['query' => 'get_promocodes']);
        return [];
    }

    return $rows;
}

function anonymous_sales_get_promocode($promocode_id) {
    global $wpdb;
    $table = anonymous_sales_get_table_name('promocodes');

    if ($promocode_id <= 0) {
        return null;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE promocode_id = %d", $promocode_id), ARRAY_A);

    if ($row === null && $wpdb->last_error !== '') {
        anonymous_sales_log_db_outcome('select', $table, false, [
            'query' => 'get_promocode',
            'promocode_id' => (int) $promocode_id,
        ]);
    }

    return $row;
}

function anonymous_sales_get_orders() {
    global $wpdb;
    $orders_table = anonymous_sales_get_table_name('orders');

    $rows = $wpdb->get_results(
        "SELECT * FROM {$orders_table} ORDER BY order_id DESC LIMIT 300",
        ARRAY_A
    );

    if ($rows === null || $wpdb->last_error !== '') {
        anonymous_sales_log_db_outcome('select', $orders_table, false, ['query' => 'get_orders']);
        return [];
    }

    // Enrich orders with snapshot data
    foreach ($rows as &$row) {
        $snapshot_data = anonymous_sales_extract_order_offer_snapshot($row);
        if ($snapshot_data && isset($snapshot_data['name'])) {
            $row['offer_name'] = $snapshot_data['name'];
            $row['offer_description'] = $snapshot_data['description'] ?? '';
        } else {
            // Fallback for old orders without snapshot
            $row['offer_name'] = '-';
            $row['offer_description'] = '';
        }
    }
    unset($row);

    return $rows;
}

function anonymous_sales_get_order_status_options() {
    return [
        'created' => 'Created',
        'pending' => 'Pending',
        'processing' => 'Processing',
        'confirming' => 'Confirming',
        'confirmed' => 'Confirmed',
        'finished' => 'Finished',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'expired' => 'Expired',
        'refunded' => 'Refunded',
        'partially_paid' => 'Partially paid',
        'payment_configuration_error' => 'Payment configuration error',
    ];
}

function anonymous_sales_handle_order_post() {
    global $wpdb;

    if (!anonymous_sales_is_post_request() || !isset($_POST['anonymous_sales_order_action'])) {
        return null;
    }

    check_admin_referer('anonymous_sales_order_action');

    $action = sanitize_key((string) $_POST['anonymous_sales_order_action']);
    anonymous_sales_log_admin_action('order_post', ['action' => $action]);

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $orders_table = anonymous_sales_get_table_name('orders');

    if ($action === 'delete' && $order_id > 0) {
        $deleted = $wpdb->delete($orders_table, ['order_id' => $order_id], ['%d']);
        anonymous_sales_log_db_outcome('delete', $orders_table, $deleted, ['order_id' => (int) $order_id]);

        if ($deleted === false) {
            if (function_exists('anonymous_sales_log_error')) {
                anonymous_sales_log_error('Order delete failed in admin.', [
                    'order_id' => (int) $order_id,
                    'wpdb_last_error' => (string) $wpdb->last_error,
                    'source' => 'admin_orders_page',
                ]);
            }
            return ['type' => 'error', 'message' => 'Order could not be deleted.'];
        }

        if (function_exists('anonymous_sales_log_warning')) {
            anonymous_sales_log_warning('Order deleted manually in admin.', [
                'order_id' => (int) $order_id,
                'source' => 'admin_orders_page',
            ]);
        }

        return ['type' => 'success', 'message' => 'Order deleted.'];
    }

    if ($action !== 'update_status') {
        return null;
    }

    $new_status = sanitize_key((string) ($_POST['order_currentStatus'] ?? ''));
    $allowed_statuses = anonymous_sales_get_order_status_options();

    if ($order_id <= 0 || !isset($allowed_statuses[$new_status])) {
        return ['type' => 'error', 'message' => 'Invalid order or status.'];
    }

    $update_data = [
        'order_currentStatus' => $new_status,
    ];
    $update_format = ['%s'];

    if (in_array($new_status, ['confirmed', 'finished', 'paid'], true)) {
        $update_data['order_paidAt'] = current_time('mysql', true);
        $update_format[] = '%s';
    }

    $updated = $wpdb->update(
        $orders_table,
        $update_data,
        ['order_id' => $order_id],
        $update_format,
        ['%d']
    );

    anonymous_sales_log_db_outcome('update', $orders_table, $updated, [
        'action' => 'update_status',
        'order_id' => (int) $order_id,
    ]);

    if ($updated === false) {
        return ['type' => 'error', 'message' => 'Could not update order status.'];
    }

    if (function_exists('anonymous_sales_log_warning')) {
        anonymous_sales_log_warning('Order status changed manually in admin.', [
            'order_id' => (int) $order_id,
            'new_status' => (string) $new_status,
            'source' => 'admin_orders_page',
        ]);
    }

    return ['type' => 'success', 'message' => 'Order status updated manually.'];
}

function anonymous_sales_handle_offer_post() {
    global $wpdb;

    if (!anonymous_sales_is_post_request() || !isset($_POST['anonymous_sales_offer_action'])) {
        return null;
    }

    check_admin_referer('anonymous_sales_offer_action');

    $table = anonymous_sales_get_table_name('offers');
    $action = sanitize_key((string) $_POST['anonymous_sales_offer_action']);
    $offer_id = isset($_POST['offer_id']) ? absint($_POST['offer_id']) : 0;
    anonymous_sales_log_admin_action('offer_post', ['action' => $action, 'offer_id' => (int) $offer_id]);

    if ($action === 'delete' && $offer_id > 0) {
        $deleted = $wpdb->delete($table, ['offer_id' => $offer_id], ['%d']);
        anonymous_sales_log_db_outcome('delete', $table, $deleted, ['offer_id' => (int) $offer_id]);

        if ($deleted === false) {
            $db_error = (string) $wpdb->last_error;
            $message = 'Offer could not be deleted.';

            if ($db_error !== '' && stripos($db_error, 'foreign key constraint fails') !== false) {
                $message = 'Offer could not be deleted because it has related orders.';
            }

            if (function_exists('anonymous_sales_log_error')) {
                anonymous_sales_log_error('Offer delete failed in admin.', [
                    'offer_id' => (int) $offer_id,
                    'wpdb_last_error' => $db_error,
                    'source' => 'admin_offers_page',
                ]);
            }

            return ['type' => 'error', 'message' => $message];
        }

        return ['type' => 'success', 'message' => 'Offer deleted.'];
    }

    $offer_name = sanitize_text_field(wp_unslash((string) ($_POST['offer_name'] ?? '')));
    $offer_price = isset($_POST['offer_price']) ? max(0, absint($_POST['offer_price'])) : 0;
    $offer_description = sanitize_textarea_field(wp_unslash((string) ($_POST['offer_description'] ?? '')));
    $offer_info = sanitize_textarea_field(wp_unslash((string) ($_POST['offer_infoFromSeller'] ?? '')));
    $offer_info_pdf = sanitize_textarea_field(wp_unslash((string) ($_POST['offer_infoFromSeller_pdf'] ?? '')));
    $offer_logo = esc_url_raw(wp_unslash((string) ($_POST['offer_userLogo'] ?? '')));
    $show_header = isset($_POST['offer_showContentHeaderOnNewPages']) ? 1 : 0;

    if ($offer_name === '') {
        return ['type' => 'error', 'message' => 'Offer name is required.'];
    }

    $data = [
        'offer_name' => $offer_name,
        'offer_price' => $offer_price,
        'offer_description' => $offer_description,
        'offer_showContentHeaderOnNewPages' => $show_header,
        'offer_infoFromSeller' => $offer_info,
        'offer_infoFromSeller_pdf' => $offer_info_pdf,
        'offer_userLogo' => $offer_logo,
    ];

    if ($action === 'update' && $offer_id > 0) {
        $data['offer_editedAt'] = current_time('mysql', true);
        $updated = $wpdb->update($table, $data, ['offer_id' => $offer_id], ['%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s'], ['%d']);
        anonymous_sales_log_db_outcome('update', $table, $updated, ['offer_id' => (int) $offer_id]);
        return $updated === false ? ['type' => 'error', 'message' => 'Offer could not be updated.'] : ['type' => 'success', 'message' => 'Offer updated.'];
    }

    $data['offer_stamp'] = strtoupper(anonymous_sales_generate_random_string(16));
    $inserted = $wpdb->insert($table, $data, ['%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s']);
    anonymous_sales_log_db_outcome('insert', $table, $inserted, ['action' => 'create']);

    return !$inserted ? ['type' => 'error', 'message' => 'Offer could not be created.'] : ['type' => 'success', 'message' => 'Offer created.'];
}

function anonymous_sales_handle_resource_post() {
    global $wpdb;

    if (!anonymous_sales_is_post_request() || !isset($_POST['anonymous_sales_resource_action'])) {
        return null;
    }

    check_admin_referer('anonymous_sales_resource_action');

    $table = anonymous_sales_get_table_name('resources');
    $action = sanitize_key((string) $_POST['anonymous_sales_resource_action']);
    $resource_id = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;
    anonymous_sales_log_admin_action('resource_post', ['action' => $action, 'resource_id' => (int) $resource_id]);

    if ($action === 'delete' && $resource_id > 0) {
        $deleted = $wpdb->delete($table, ['resource_id' => $resource_id], ['%d']);
        anonymous_sales_log_db_outcome('delete', $table, $deleted, ['resource_id' => (int) $resource_id]);
        return $deleted === false ? ['type' => 'error', 'message' => 'Resource could not be deleted.'] : ['type' => 'success', 'message' => 'Resource deleted.'];
    }

    $resource_offer_locked = isset($_POST['resource_offer_locked']) ? absint($_POST['resource_offer_locked']) : 0;
    $resource_offer = $resource_offer_locked > 0 ? $resource_offer_locked : absint($_POST['resource_offer'] ?? 0);
    if ($resource_offer <= 0) {
        return ['type' => 'error', 'message' => 'Select an offer for the resource.'];
    }

    $resource_name = sanitize_text_field(wp_unslash((string) ($_POST['resource_name'] ?? '')));
    $resource_description = sanitize_textarea_field(wp_unslash((string) ($_POST['resource_description'] ?? '')));
    $resource_size = max(0.0, (float) sanitize_text_field(wp_unslash((string) ($_POST['resource_size'] ?? 0))));
    $resource_password = anonymous_sales_encrypt_secret(sanitize_text_field(wp_unslash((string) ($_POST['resource_password'] ?? ''))));
    $resource_address = trim(sanitize_text_field(wp_unslash((string) ($_POST['resource_address'] ?? ''))));
    $resource_address = $resource_address !== '' ? esc_url_raw($resource_address, ['http', 'https']) : '';
    $resource_type = sanitize_text_field(wp_unslash((string) ($_POST['resource_type'] ?? '')));
    $resource_stamp = anonymous_sales_normalize_resource_checksum(sanitize_text_field(wp_unslash((string) ($_POST['resource_stamp'] ?? ''))));

    if ($resource_address !== '' && !wp_http_validate_url($resource_address)) {
        return ['type' => 'error', 'message' => 'Resource URL must be a valid http/https address.'];
    }

    if (!anonymous_sales_is_valid_resource_checksum($resource_stamp)) {
        return ['type' => 'error', 'message' => 'Checksum format is invalid. Use md5/sha* format, e.g. sha256:...'];
    }

    $data = [
        'resource_name' => $resource_name,
        'resource_description' => $resource_description,
        'resource_size' => $resource_size,
        'resource_password' => $resource_password,
        'resource_address' => $resource_address,
        'resource_type' => $resource_type,
        'resource_stamp' => $resource_stamp,
        'resource_offer' => $resource_offer,
    ];

    if ($data['resource_name'] === '') {
        return ['type' => 'error', 'message' => 'Resource name is required.'];
    }

    if ($action === 'update' && $resource_id > 0) {
        $data['resource_editedAt'] = current_time('mysql', true);
        $updated = $wpdb->update($table, $data, ['resource_id' => $resource_id], ['%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s'], ['%d']);
        anonymous_sales_log_db_outcome('update', $table, $updated, ['resource_id' => (int) $resource_id]);
        return $updated === false ? ['type' => 'error', 'message' => 'Resource could not be updated.'] : ['type' => 'success', 'message' => 'Resource updated.'];
    }

    $inserted = $wpdb->insert($table, $data, ['%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d']);
    anonymous_sales_log_db_outcome('insert', $table, $inserted, ['action' => 'create']);

    return !$inserted ? ['type' => 'error', 'message' => 'Resource could not be created.'] : ['type' => 'success', 'message' => 'Resource created.'];
}

function anonymous_sales_handle_promocode_post() {
    global $wpdb;

    if (!anonymous_sales_is_post_request() || !isset($_POST['anonymous_sales_promocode_action'])) {
        return null;
    }

    check_admin_referer('anonymous_sales_promocode_action');

    $table = anonymous_sales_get_table_name('promocodes');
    $action = sanitize_key((string) $_POST['anonymous_sales_promocode_action']);
    $promocode_id = isset($_POST['promocode_id']) ? absint($_POST['promocode_id']) : 0;
    anonymous_sales_log_admin_action('promocode_post', ['action' => $action, 'promocode_id' => (int) $promocode_id]);

    if ($action === 'delete' && $promocode_id > 0) {
        $deleted = $wpdb->delete($table, ['promocode_id' => $promocode_id], ['%d']);
        anonymous_sales_log_db_outcome('delete', $table, $deleted, ['promocode_id' => (int) $promocode_id]);
        return $deleted === false ? ['type' => 'error', 'message' => 'Promo code could not be deleted.'] : ['type' => 'success', 'message' => 'Promo code deleted.'];
    }

    $token = sanitize_text_field(wp_unslash((string) ($_POST['promocode_token'] ?? '')));
    if ($token === '') {
        return ['type' => 'error', 'message' => 'Promo code token is required.'];
    }

    $offer = absint($_POST['promocode_offer'] ?? 0);
    $valid_till = sanitize_text_field(wp_unslash((string) ($_POST['promocode_validTill'] ?? '')));
    if ($valid_till === '') {
        $valid_till = gmdate('Y-m-d H:i:s', strtotime('+1 year'));
    }

    $data = [
        'promocode_token' => $token,
        'promocode_type' => absint($_POST['promocode_type'] ?? 1),
        'promocode_offer' => $offer > 0 ? $offer : null,
        'promocode_multiUse' => isset($_POST['promocode_multiUse']) ? 1 : 0,
        'promocode_active' => isset($_POST['promocode_active']) ? 1 : 0,
        'promocode_usedAt' => absint($_POST['promocode_usedAt'] ?? 0),
        'promocode_validTill' => gmdate('Y-m-d H:i:s', strtotime($valid_till)),
    ];

    if ($action === 'update' && $promocode_id > 0) {
        $updated = $wpdb->update($table, $data, ['promocode_id' => $promocode_id], ['%s', '%d', '%d', '%d', '%d', '%d', '%s'], ['%d']);
        anonymous_sales_log_db_outcome('update', $table, $updated, ['promocode_id' => (int) $promocode_id]);
        return $updated === false ? ['type' => 'error', 'message' => 'Promo code could not be updated.'] : ['type' => 'success', 'message' => 'Promo code updated.'];
    }

    $inserted = $wpdb->insert($table, $data, ['%s', '%d', '%d', '%d', '%d', '%d', '%s']);
    anonymous_sales_log_db_outcome('insert', $table, $inserted, ['action' => 'create']);
    return !$inserted ? ['type' => 'error', 'message' => 'Promo code could not be created.'] : ['type' => 'success', 'message' => 'Promo code created.'];
}

function anonymous_sales_render_dashboard_page() {
    anonymous_sales_render_admin_page_start('Anonymous Sales', 'Central management panel for anonymous digital sales.');

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<h2 style="margin-top: 0;">Universal endpoint</h2>';
    if (function_exists('anonymous_sales_get_router_url')) {
        echo '<p>Use one URL for all actions and for NOWPayments webhook callback.</p>';
        echo '<input type="text" readonly class="regular-text code" value="' . esc_attr(anonymous_sales_get_router_url()) . '" style="width: 100%;" />';
    }
    echo '</div>';

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<h2 style="margin-top: 0;">Quick links</h2>';
    echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=anonymous-sales-offers')) . '">Manage offers</a></p>';
    echo '</div>';

    anonymous_sales_render_admin_page_end();
}

function anonymous_sales_render_offers_page() {
    $notice = anonymous_sales_handle_offer_post();
    $resource_notice = anonymous_sales_handle_resource_post();
    $offers = anonymous_sales_get_offers();
    $editing_id = isset($_GET['edit_offer']) ? absint($_GET['edit_offer']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, nothing is changed.
    $offer = $editing_id > 0 ? anonymous_sales_get_offer($editing_id) : null;
    if (!is_array($offer)) {
        $offer = [
            'offer_id' => 0,
            'offer_name' => '',
            'offer_price' => '',
            'offer_description' => '',
            'offer_infoFromSeller' => '',
            'offer_infoFromSeller_pdf' => '',
            'offer_userLogo' => '',
            'offer_showContentHeaderOnNewPages' => 1,
        ];
    }

    $offer_price_cents = anonymous_sales_offer_price_to_cents($offer['offer_price']);
    $router_url = function_exists('anonymous_sales_get_router_url') ? anonymous_sales_get_router_url() : '';

    anonymous_sales_render_admin_page_start('Offers', 'Create and update commercial offers.');
    anonymous_sales_admin_notice($notice);
    anonymous_sales_admin_notice($resource_notice);
    if ($editing_id > 0) {
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=anonymous-sales-offers')) . '">Back to offers list</a></p>';
    }

    echo '<div class="anonymous-sales-admin-grid">';

    echo '<div class="card anonymous-sales-admin-card">';
    $offer_form_summary = $editing_id > 0 ? 'Edit offer' : 'Add offer';
    echo '<details><summary><strong>' . esc_html($offer_form_summary) . '</strong></summary><div style="margin-top: 14px;">';
    echo '<form method="post">';
    wp_nonce_field('anonymous_sales_offer_action');
    echo '<input type="hidden" name="anonymous_sales_offer_action" value="' . esc_attr($editing_id > 0 ? 'update' : 'create') . '" />';
    echo '<input type="hidden" name="offer_id" value="' . esc_attr((string) $offer['offer_id']) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">Name</th><td><input type="text" class="regular-text" name="offer_name" value="' . esc_attr((string) $offer['offer_name']) . '" required /></td></tr>';
    echo '<tr><th scope="row">Price (cents)</th><td><input type="number" step="1" min="0" id="anonymous_sales_offer_price_cents" name="offer_price" value="' . esc_attr((string) $offer_price_cents) . '" required />';
    echo '<button type="button" class="button" id="anonymous_sales_offer_check_approved_crypto" style="margin-left:8px;">Check approved crypto for this price</button>';
    echo '<p class="description">100 cents = $1.00. Preview: <strong id="anonymous_sales_offer_price_preview">$' . esc_html(anonymous_sales_format_usd($offer_price_cents)) . '</strong></p>';
    echo '<p class="description" id="anonymous_sales_offer_check_approved_crypto_result" style="white-space:pre-wrap;">Not checked yet.</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row">Description</th><td><textarea class="large-text" rows="4" name="offer_description">' . esc_textarea((string) $offer['offer_description']) . '</textarea><p class="description">Markdown supported.</p></td></tr>';
    echo '<tr><th scope="row">Seller note</th><td><textarea class="large-text" rows="4" name="offer_infoFromSeller">' . esc_textarea((string) $offer['offer_infoFromSeller']) . '</textarea><p class="description">Markdown supported.</p></td></tr>';
    echo '<tr><th scope="row">Seller note (PDF)</th><td><textarea class="large-text" rows="4" name="offer_infoFromSeller_pdf">' . esc_textarea((string) ($offer['offer_infoFromSeller_pdf'] ?? '')) . '</textarea><p class="description">Optional dedicated PDF message. Markdown supported.</p></td></tr>';
    echo '<tr><th scope="row">Logo URL</th><td><div class="anonymous-sales-url-picker"><input type="url" class="regular-text" id="anonymous_sales_offer_logo_url" name="offer_userLogo" value="' . esc_attr((string) $offer['offer_userLogo']) . '" /><button type="button" class="button" id="anonymous_sales_pick_offer_logo">Choose from Media</button><button type="button" class="button" id="anonymous_sales_clear_offer_logo">Clear</button></div><p class="description">Paste URL manually or pick an image from WordPress Media Library. Recommended size: 150x150.</p><img id="anonymous_sales_offer_logo_preview" class="anonymous-sales-media-preview" src="" alt="Offer logo preview" hidden /></td></tr>';
    echo '<tr><th scope="row">PDF header</th><td><label><input type="checkbox" name="offer_showContentHeaderOnNewPages" value="1" ' . checked((int) $offer['offer_showContentHeaderOnNewPages'], 1, false) . ' /> Show header on each page</label></td></tr>';
    echo '</tbody></table>';
    submit_button($editing_id > 0 ? 'Update offer' : 'Create offer');
    if ($editing_id > 0) {
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=anonymous-sales-offers')) . '">Cancel</a>';
    }
    echo '</form>';
    echo '</div></details>';
    echo '</div>';

    if ($editing_id <= 0) {
        echo '<div class="card anonymous-sales-admin-card">';
        echo '<h2 style="margin-top: 0;">Existing offers</h2>';
        if (empty($offers)) {
            echo '<p>No offers yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Name</th><th>Price</th><th>Shortcode</th><th>External JS embed</th><th>Actions</th></tr></thead><tbody>';
            foreach ($offers as $row) {
                $edit_url = add_query_arg(['page' => 'anonymous-sales-offers', 'edit_offer' => (int) $row['offer_id']], admin_url('admin.php'));
                $shortcode = '[anonymous_sales_buy offer_id="' . (int) $row['offer_id'] . '"]';
                $external_script_src = add_query_arg([
                    'offer' => (int) $row['offer_id'],
                ], home_url('/externalorder.js'));
                $external_script_tag = '<script src="' . esc_url($external_script_src) . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- an example the owner copies into another site; it is escaped and printed in a text field, not output as a script.
                $external_open_example = 'window.AnonymousSalesExternalOrder && window.AnonymousSalesExternalOrder.open();';
                echo '<tr>';
                echo '<td>' . esc_html((string) $row['offer_id']) . '</td>';
                echo '<td><strong>' . esc_html((string) $row['offer_name']) . '</strong><br /><span class="description">' . esc_html(wp_trim_words((string) $row['offer_description'], 14)) . '</span></td>';
                echo '<td>$' . esc_html(anonymous_sales_format_usd($row['offer_price'])) . '</td>';
                echo '<td><code>' . esc_html($shortcode) . '</code></td>';
                echo '<td><input type="text" readonly class="regular-text code" value="' . esc_attr($external_script_tag) . '" style="width:100%;" /><p class="description" style="margin-top:6px;">Trigger example: <code>' . esc_html($external_open_example) . '</code></p></td>';
                echo '<td class="anonymous-sales-action-cell"><a class="button anonymous-sales-action-btn anonymous-sales-action-edit" href="' . esc_url($edit_url) . '">Edit</a>';
                echo '<form method="post" onsubmit="return window.confirm(\'Delete this offer?\');">';
                wp_nonce_field('anonymous_sales_offer_action');
                echo '<input type="hidden" name="anonymous_sales_offer_action" value="delete" />';
                echo '<input type="hidden" name="offer_id" value="' . esc_attr((string) $row['offer_id']) . '" />';
                submit_button('Delete', 'anonymous-sales-action-btn anonymous-sales-action-delete', '', false);
                echo '</form></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    if ($editing_id > 0) {
        echo '<div class="card anonymous-sales-admin-card">';
        echo '<h2 style="margin-top: 0;">Files in this offer</h2>';
        $resources_for_offer = anonymous_sales_get_resources_by_offer($editing_id);
        $editing_resource_id = isset($_GET['edit_resource']) ? absint($_GET['edit_resource']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, nothing is changed.
        $editing_resource = $editing_resource_id > 0 ? anonymous_sales_get_resource($editing_resource_id) : null;
        if (!is_array($editing_resource) || (int) $editing_resource['resource_offer'] !== $editing_id) {
            $editing_resource = [
                'resource_id' => 0,
                'resource_name' => '',
                'resource_description' => '',
                'resource_size' => '',
                'resource_password' => '',
                'resource_address' => '',
                'resource_type' => '',
                'resource_stamp' => '',
            ];
        }

        echo '<p>Managing resources for: <strong>' . esc_html((string) $offer['offer_name']) . '</strong></p>';
        $collapse_resource_create = !empty($resources_for_offer) && $editing_resource_id <= 0;
        if ($collapse_resource_create) {
            echo '<details><summary><strong>Add resource</strong></summary><div style="margin-top: 14px;">';
        }
        echo '<form method="post">';
        wp_nonce_field('anonymous_sales_resource_action');
        echo '<input type="hidden" name="anonymous_sales_resource_action" value="' . esc_attr($editing_resource_id > 0 ? 'update' : 'create') . '" />';
        echo '<input type="hidden" name="resource_id" value="' . esc_attr((string) $editing_resource['resource_id']) . '" />';
        echo '<input type="hidden" name="resource_offer_locked" value="' . esc_attr((string) $editing_id) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Name</th><td><input type="text" class="regular-text" name="resource_name" value="' . esc_attr((string) $editing_resource['resource_name']) . '" required /></td></tr>';
        echo '<tr><th scope="row">Description</th><td><textarea class="large-text" rows="4" name="resource_description">' . esc_textarea((string) $editing_resource['resource_description']) . '</textarea><p class="description">Markdown supported.</p></td></tr>';
        echo '<tr><th scope="row">Size</th><td><input type="number" step="0.01" min="0" name="resource_size" value="' . esc_attr((string) $editing_resource['resource_size']) . '" /></td></tr>';
        echo '<tr><th scope="row">Password</th><td><div class="anonymous-sales-url-picker"><input type="password" class="regular-text" id="anonymous_sales_resource_password" name="resource_password" value="' . esc_attr((string) $editing_resource['resource_password']) . '" autocomplete="new-password" /><button type="button" class="button" id="anonymous_sales_toggle_resource_password">Show</button></div><p class="description">Stored encrypted in database and decrypted only when needed.</p></td></tr>';
        echo '<tr><th scope="row">Checksum (optional)</th><td><input type="text" class="regular-text code" name="resource_stamp" value="' . esc_attr((string) $editing_resource['resource_stamp']) . '" pattern="(?:(?:md5|sha1|sha224|sha256|sha384|sha512):)?[A-Fa-f0-9]+" /><p class="description">Optional integrity hash. Supported formats: plain hex or prefixed e.g. <code>sha256:64hex</code>, <code>sha512:128hex</code>.</p></td></tr>';
        echo '<tr><th scope="row">Address URL</th><td><div class="anonymous-sales-url-picker"><input type="url" class="large-text" id="anonymous_sales_resource_address" name="resource_address" value="' . esc_attr((string) $editing_resource['resource_address']) . '" /><button type="button" class="button" id="anonymous_sales_pick_resource_file">Choose from Media</button><button type="button" class="button" id="anonymous_sales_clear_resource_file">Clear</button></div><p class="description">Paste URL manually, pick a file from WordPress Media Library, or clear the field.</p></td></tr>';
        echo '<tr><th scope="row">Type</th><td><input type="text" class="regular-text" name="resource_type" value="' . esc_attr((string) $editing_resource['resource_type']) . '" /></td></tr>';
        echo '</tbody></table>';
        submit_button($editing_resource_id > 0 ? 'Update resource' : 'Add resource', 'secondary', '', false);
        if ($editing_resource_id > 0) {
            $cancel_url = add_query_arg(['page' => 'anonymous-sales-offers', 'edit_offer' => $editing_id], admin_url('admin.php'));
            echo ' <a class="button" href="' . esc_url($cancel_url) . '">Cancel resource edit</a>';
        }
        echo '</form>';
        if ($collapse_resource_create) {
            echo '</div></details>';
        }

        if (empty($resources_for_offer)) {
            echo '<p style="margin-top: 16px;">No resources assigned to this offer yet.</p>';
        } else {
            echo '<table class="widefat striped" style="margin-top: 16px;"><thead><tr><th>ID</th><th>Name</th><th>Checksum</th><th>Address</th><th>Actions</th></tr></thead><tbody>';
            foreach ($resources_for_offer as $resource_row) {
                $edit_resource_url = add_query_arg([
                    'page' => 'anonymous-sales-offers',
                    'edit_offer' => $editing_id,
                    'edit_resource' => (int) $resource_row['resource_id'],
                ], admin_url('admin.php'));
                echo '<tr>';
                echo '<td>' . esc_html((string) $resource_row['resource_id']) . '</td>';
                echo '<td>' . esc_html((string) $resource_row['resource_name']) . '</td>';
                echo '<td><code>' . esc_html((string) $resource_row['resource_stamp']) . '</code></td>';
                echo '<td><a href="' . esc_url((string) $resource_row['resource_address']) . '" target="_blank" rel="noopener noreferrer">' . esc_html(wp_trim_words((string) $resource_row['resource_address'], 7)) . '</a></td>';
                echo '<td class="anonymous-sales-action-cell"><a class="button anonymous-sales-action-btn anonymous-sales-action-edit" href="' . esc_url($edit_resource_url) . '">Edit</a>';
                echo '<form method="post" onsubmit="return window.confirm(\'Delete this resource?\');">';
                wp_nonce_field('anonymous_sales_resource_action');
                echo '<input type="hidden" name="anonymous_sales_resource_action" value="delete" />';
                echo '<input type="hidden" name="resource_id" value="' . esc_attr((string) $resource_row['resource_id']) . '" />';
                echo '<input type="hidden" name="resource_offer_locked" value="' . esc_attr((string) $editing_id) . '" />';
                submit_button('Delete', 'anonymous-sales-action-btn anonymous-sales-action-delete', '', false);
                echo '</form></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    echo '</div>';

    echo '<script>';
    echo '(function(){';
    echo 'var routerUrl=' . wp_json_encode((string) $router_url) . ';';
    echo 'var input=document.getElementById("anonymous_sales_offer_price_cents");';
    echo 'var preview=document.getElementById("anonymous_sales_offer_price_preview");';
    echo 'if(input&&preview){var update=function(){var cents=parseInt(input.value||"0",10);if(isNaN(cents)||cents<0){cents=0;}preview.textContent="$"+(cents/100).toFixed(2);};input.addEventListener("input",update);update();}';
    echo 'var checkBtn=document.getElementById("anonymous_sales_offer_check_approved_crypto");';
    echo 'var checkResult=document.getElementById("anonymous_sales_offer_check_approved_crypto_result");';
    echo 'if(checkBtn&&checkResult&&input&&routerUrl){checkBtn.addEventListener("click",function(){var cents=parseInt(input.value||"0",10);if(isNaN(cents)||cents<=0){checkResult.textContent="Set Price (cents) greater than 0 first.";return;}checkBtn.disabled=true;checkResult.textContent="Checking approved crypto...";var params=new URLSearchParams();params.set("action","check_approved_crypto");params.set("include_minimums","1");params.set("price_currency","usd");params.set("price_amount",String(cents/100));fetch(routerUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:params.toString()}).then(function(r){return r.json();}).then(function(data){if(!data||!data.success){checkResult.textContent=(data&&data.message)?data.message:"Could not fetch approved crypto list.";return;}var coins=Array.isArray(data.approved_coins)?data.approved_coins:[];if(!coins.length){checkResult.textContent="No approved crypto returned by NOWPayments.";return;}var eligible=Array.isArray(data.eligible_coins)?data.eligible_coins:[];var ineligible=Array.isArray(data.ineligible_coins)?data.ineligible_coins:[];var text="Approved crypto ("+coins.length+"):\n"+coins.join(", ")+"\n\nEligible for this price ("+eligible.length+"):\n"+(eligible.length?eligible.join(", "):"none");if(ineligible.length){text+="\n\nBelow minimum ("+ineligible.length+"):\n"+ineligible.join(", ");}checkResult.textContent=text;}).catch(function(){checkResult.textContent="Network error while checking approved crypto.";}).finally(function(){checkBtn.disabled=false;});});}';
    echo 'var bindMediaPicker=function(buttonId,inputId,titleText,buttonText){var btn=document.getElementById(buttonId);var urlInput=document.getElementById(inputId);if(!btn||!urlInput){return;}btn.addEventListener("click",function(){if(typeof wp==="undefined"||!wp.media){window.alert("Media Library is not available. Please reload the page.");return;}var frame=wp.media({title:titleText,button:{text:buttonText},multiple:false});frame.on("select",function(){var selection=frame.state().get("selection").first();if(!selection){return;}var attachment=selection.toJSON();if(attachment&&attachment.url){urlInput.value=attachment.url;urlInput.dispatchEvent(new Event("input"));}});frame.open();});};';
    echo 'var bindClear=function(buttonId,inputId){var btn=document.getElementById(buttonId);var urlInput=document.getElementById(inputId);if(!btn||!urlInput){return;}btn.addEventListener("click",function(){urlInput.value="";urlInput.dispatchEvent(new Event("input"));urlInput.focus();});};';
    echo 'var bindImagePreview=function(inputId,imageId){var urlInput=document.getElementById(inputId);var img=document.getElementById(imageId);if(!urlInput||!img){return;}var refresh=function(){var url=(urlInput.value||"").trim();if(!url){img.hidden=true;img.removeAttribute("src");return;}img.src=url;};img.addEventListener("load",function(){img.hidden=false;});img.addEventListener("error",function(){img.hidden=true;});urlInput.addEventListener("input",refresh);refresh();};';
    echo 'bindMediaPicker("anonymous_sales_pick_offer_logo","anonymous_sales_offer_logo_url","Select Offer Logo","Use this image");';
    echo 'bindClear("anonymous_sales_clear_offer_logo","anonymous_sales_offer_logo_url");';
    echo 'bindImagePreview("anonymous_sales_offer_logo_url","anonymous_sales_offer_logo_preview");';
    echo 'bindMediaPicker("anonymous_sales_pick_resource_file","anonymous_sales_resource_address","Select Resource File","Use this file");';
    echo 'bindClear("anonymous_sales_clear_resource_file","anonymous_sales_resource_address");';
    echo 'var resourcePasswordInput=document.getElementById("anonymous_sales_resource_password");';
    echo 'var resourcePasswordToggle=document.getElementById("anonymous_sales_toggle_resource_password");';
    echo 'if(resourcePasswordInput&&resourcePasswordToggle){resourcePasswordToggle.addEventListener("click",function(){var isHidden=resourcePasswordInput.type==="password";resourcePasswordInput.type=isHidden?"text":"password";resourcePasswordToggle.textContent=isHidden?"Hide":"Show";});}';
    echo '})();';
    echo '</script>';

    anonymous_sales_render_admin_page_end();
}

function anonymous_sales_render_resources_page() {
    anonymous_sales_render_admin_page_start('Resources', 'Resources are managed inside offer editing now.');
    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<p>Open <strong>Offers</strong>, click <strong>Edit</strong> on the selected offer, then manage resources in the embedded section.</p>';
    echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=anonymous-sales-offers')) . '">Go to Offers</a></p>';
    echo '</div>';
    anonymous_sales_render_admin_page_end();
}

function anonymous_sales_render_promocodes_page() {
    $notice = anonymous_sales_handle_promocode_post();
    $promocodes = anonymous_sales_get_promocodes();
    $offers = anonymous_sales_get_offers();
    $editing_id = isset($_GET['edit_promocode']) ? absint($_GET['edit_promocode']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, nothing is changed.
    $promocode = $editing_id > 0 ? anonymous_sales_get_promocode($editing_id) : null;
    if (!is_array($promocode)) {
        $promocode = [
            'promocode_id' => 0,
            'promocode_token' => '',
            'promocode_type' => 1,
            'promocode_offer' => 0,
            'promocode_multiUse' => 0,
            'promocode_active' => 1,
            'promocode_usedAt' => 0,
            'promocode_validTill' => gmdate('Y-m-d H:i:s', strtotime('+1 year')),
        ];
    }

    anonymous_sales_render_admin_page_start('Promo Codes', 'Create and manage promo codes.');
    anonymous_sales_admin_notice($notice);

    echo '<div class="anonymous-sales-admin-grid">';

    echo '<div class="card anonymous-sales-admin-card">';
    echo '<h2 style="margin-top: 0;">' . esc_html($editing_id > 0 ? 'Edit promo code' : 'Add promo code') . '</h2>';
    echo '<form method="post">';
    wp_nonce_field('anonymous_sales_promocode_action');
    echo '<input type="hidden" name="anonymous_sales_promocode_action" value="' . esc_attr($editing_id > 0 ? 'update' : 'create') . '" />';
    echo '<input type="hidden" name="promocode_id" value="' . esc_attr((string) $promocode['promocode_id']) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">Token</th><td><input type="text" class="regular-text code" name="promocode_token" value="' . esc_attr((string) $promocode['promocode_token']) . '" required /></td></tr>';
    echo '<tr><th scope="row">Type</th><td><select name="promocode_type"><option value="1" ' . selected((int) $promocode['promocode_type'], 1, false) . '>Percentage</option><option value="2" ' . selected((int) $promocode['promocode_type'], 2, false) . '>Fixed amount</option></select></td></tr>';
    echo '<tr><th scope="row">Offer</th><td><select name="promocode_offer"><option value="0">All offers</option>';
    foreach ($offers as $offer) {
        echo '<option value="' . esc_attr((string) $offer['offer_id']) . '" ' . selected((int) $promocode['promocode_offer'], (int) $offer['offer_id'], false) . '>' . esc_html((string) $offer['offer_name']) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Valid till</th><td><input type="datetime-local" name="promocode_validTill" value="' . esc_attr(gmdate('Y-m-d\TH:i', strtotime((string) $promocode['promocode_validTill']))) . '" /></td></tr>';
    echo '<tr><th scope="row">Flags</th><td><label><input type="checkbox" name="promocode_multiUse" value="1" ' . checked((int) $promocode['promocode_multiUse'], 1, false) . ' /> Multi use</label><br /><label><input type="checkbox" name="promocode_active" value="1" ' . checked((int) $promocode['promocode_active'], 1, false) . ' /> Active</label></td></tr>';
    echo '<tr><th scope="row">Used count</th><td><input type="number" min="0" name="promocode_usedAt" value="' . esc_attr((string) $promocode['promocode_usedAt']) . '" /></td></tr>';
    echo '</tbody></table>';
    submit_button($editing_id > 0 ? 'Update promo code' : 'Create promo code');
    if ($editing_id > 0) {
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=anonymous-sales-promocodes')) . '">Cancel</a>';
    }
    echo '</form>';
    echo '</div>';

    echo '<div class="card anonymous-sales-admin-card">';
    echo '<h2 style="margin-top: 0;">Existing promo codes</h2>';
    if (empty($promocodes)) {
        echo '<p>No promo codes yet.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Token</th><th>Offer</th><th>Valid till</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach ($promocodes as $row) {
            $edit_url = add_query_arg(['page' => 'anonymous-sales-promocodes', 'edit_promocode' => (int) $row['promocode_id']], admin_url('admin.php'));
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['promocode_id']) . '</td>';
            echo '<td><code>' . esc_html((string) $row['promocode_token']) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['offer_name'] ?? 'All offers')) . '</td>';
            echo '<td>' . esc_html((string) $row['promocode_validTill']) . '</td>';
            echo '<td>' . ((int) $row['promocode_active'] === 1 ? 'Active' : 'Disabled') . '</td>';
            echo '<td class="anonymous-sales-action-cell"><a class="button anonymous-sales-action-btn anonymous-sales-action-edit" href="' . esc_url($edit_url) . '">Edit</a>';
            echo '<form method="post" onsubmit="return window.confirm(\'Delete this promo code?\');">';
            wp_nonce_field('anonymous_sales_promocode_action');
            echo '<input type="hidden" name="anonymous_sales_promocode_action" value="delete" />';
            echo '<input type="hidden" name="promocode_id" value="' . esc_attr((string) $row['promocode_id']) . '" />';
            submit_button('Delete', 'anonymous-sales-action-btn anonymous-sales-action-delete', '', false);
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '</div>';
    anonymous_sales_render_admin_page_end();
}

function anonymous_sales_render_orders_page() {
    $notice = anonymous_sales_handle_order_post();
    $orders = anonymous_sales_get_orders();
    $status_options = anonymous_sales_get_order_status_options();

    anonymous_sales_render_admin_page_start('Orders', 'Inspect anonymous orders, statuses, and payment references.');
    anonymous_sales_admin_notice($notice);

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    if (empty($orders)) {
        echo '<p>No orders yet.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Order number</th><th>Offer</th><th>Status</th><th>Price</th><th>Payment ID</th><th>Created</th><th>Manual status</th></tr></thead><tbody>';
        foreach ($orders as $order) {
            $dialog_id = 'anonymous-sales-manual-status-dialog-' . (int) $order['order_id'];
            $risk_ack_id = 'anonymous_sales_manual_status_ack_' . (int) $order['order_id'];
            echo '<tr>';
            echo '<td>' . esc_html((string) $order['order_id']) . '</td>';
            echo '<td><code>' . esc_html((string) $order['order_stamp']) . '</code></td>';
            echo '<td>' . esc_html((string) ($order['offer_name'] ?? '-')) . '</td>';
            echo '<td>' . esc_html((string) $order['order_currentStatus']) . '</td>';
            echo '<td>' . esc_html((string) $order['order_finalPrice']) . ' ' . esc_html(strtoupper((string) $order['order_priceCurrency'])) . '</td>';
            echo '<td>' . esc_html((string) $order['order_paymentId']) . '</td>';
            echo '<td>' . esc_html((string) $order['order_createdAt']) . '</td>';
            echo '<td>';
            echo '<button type="button" class="button button-small anonymous-sales-open-manual-status" data-target-dialog="' . esc_attr($dialog_id) . '">Change status</button>';
            if (!empty($order['order_paymentId'])) {
                $check_nonce = wp_create_nonce('anonymous_sales_check_order_status_' . (int) $order['order_id']);
                echo ' <button type="button" class="button button-small anonymous-sales-check-order-now" data-order-id="' . esc_attr((string) $order['order_id']) . '" data-nonce="' . esc_attr($check_nonce) . '">Check NOW</button>';
            }
            $delete_dialog_id = 'anonymous-sales-delete-order-dialog-' . (int) $order['order_id'];
            echo ' <button type="button" class="button button-small button-link-delete anonymous-sales-open-delete-order" data-target-dialog="' . esc_attr($delete_dialog_id) . '">Delete</button>';
            echo '<dialog id="' . esc_attr($dialog_id) . '" class="anonymous-sales-manual-status-dialog">';
            echo '<form method="post" class="anonymous-sales-manual-status-form" data-current-status="' . esc_attr((string) $order['order_currentStatus']) . '">';
            echo '<h3 style="margin-top:0;">Manual status change</h3>';
            echo '<p style="margin-top:0;">Order <code>' . esc_html((string) $order['order_stamp']) . '</code><br/>Current status: <strong>' . esc_html((string) $order['order_currentStatus']) . '</strong></p>';
            wp_nonce_field('anonymous_sales_order_action');
            echo '<input type="hidden" name="anonymous_sales_order_action" value="update_status" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order['order_id']) . '" />';
            echo '<p><label style="display:block;margin-bottom:6px;"><strong>New status</strong></label>';
            echo '<select name="order_currentStatus" style="min-width:220px;">';
            foreach ($status_options as $status_key => $status_label) {
                echo '<option value="' . esc_attr($status_key) . '" ' . selected((string) $order['order_currentStatus'], $status_key, false) . '>' . esc_html($status_label) . '</option>';
            }
            echo '</select></p>';
            echo '<label for="' . esc_attr($risk_ack_id) . '" style="display:flex;align-items:center;gap:6px;font-size:12px;color:#b00020;max-width:420px;">';
            echo '<input type="checkbox" id="' . esc_attr($risk_ack_id) . '" class="anonymous-sales-manual-status-ack" />';
            echo 'I understand the risk. Manual change is recommended only after NOWPayments link expiry or external payment verification.';
            echo '</label>';
            echo '<div style="display:flex;gap:8px;align-items:center;margin-top:14px;">';
            echo '<button type="submit" class="button button-primary button-small anonymous-sales-manual-status-save" disabled="disabled">Save</button>';
            echo '<button type="button" class="button button-small anonymous-sales-close-manual-status">Cancel</button>';
            echo '</div>';
            echo '</form>';
            echo '</dialog>';
            $delete_dialog_id = 'anonymous-sales-delete-order-dialog-' . (int) $order['order_id'];
            echo '<dialog id="' . esc_attr($delete_dialog_id) . '" class="anonymous-sales-delete-order-dialog">';
            echo '<form method="post" class="anonymous-sales-delete-order-form">';
            echo '<h3 style="margin-top:0;color:#b00020;">Delete order?</h3>';
            echo '<p style="margin-top:0;">Order <code>' . esc_html((string) $order['order_stamp']) . '</code> will be permanently deleted.</p>';
            wp_nonce_field('anonymous_sales_order_action');
            echo '<input type="hidden" name="anonymous_sales_order_action" value="delete" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order['order_id']) . '" />';
            echo '<div style="display:flex;gap:8px;align-items:center;margin-top:14px;">';
            echo '<button type="submit" class="button button-primary button-small" style="background-color:#b00020;border-color:#b00020;">Delete permanently</button>';
            echo '<button type="button" class="button button-small anonymous-sales-close-delete-order">Cancel</button>';
            echo '</div>';
            echo '</form>';
            echo '</dialog>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<script>';
        echo '(function(){';
        echo 'var openButtons=document.querySelectorAll(".anonymous-sales-open-manual-status");';
        echo 'openButtons.forEach(function(btn){';
        echo 'btn.addEventListener("click",function(){';
        echo 'var dialogId=btn.getAttribute("data-target-dialog")||"";';
        echo 'if(!dialogId){return;}';
        echo 'var dialog=document.getElementById(dialogId);';
        echo 'if(!dialog||typeof dialog.showModal!=="function"){return;}';
        echo 'dialog.showModal();';
        echo '});';
        echo '});';
        echo 'var closeButtons=document.querySelectorAll(".anonymous-sales-close-manual-status");';
        echo 'closeButtons.forEach(function(btn){';
        echo 'btn.addEventListener("click",function(){';
        echo 'var dialog=btn.closest("dialog");';
        echo 'if(dialog){dialog.close();}';
        echo '});';
        echo '});';
        echo 'var dialogs=document.querySelectorAll(".anonymous-sales-manual-status-dialog");';
        echo 'dialogs.forEach(function(dialog){';
        echo 'dialog.addEventListener("click",function(event){';
        echo 'var rect=dialog.getBoundingClientRect();';
        echo 'var inside=event.clientX>=rect.left&&event.clientX<=rect.right&&event.clientY>=rect.top&&event.clientY<=rect.bottom;';
        echo 'if(!inside){dialog.close();}';
        echo '});';
        echo '});';
        echo 'var forms=document.querySelectorAll(".anonymous-sales-manual-status-form");';
        echo 'if(!forms.length){return;}';
        echo 'forms.forEach(function(form){';
        echo 'var select=form.querySelector("select[name=\"order_currentStatus\"]");';
        echo 'var ack=form.querySelector(".anonymous-sales-manual-status-ack");';
        echo 'var saveBtn=form.querySelector(".anonymous-sales-manual-status-save");';
        echo 'var currentStatus=(form.getAttribute("data-current-status")||"").trim();';
        echo 'var syncSaveState=function(){';
        echo 'if(!select||!ack||!saveBtn){return;}';
        echo 'var targetStatus=(select.value||"").trim();';
        echo 'var changed=targetStatus!==""&&targetStatus!==currentStatus;';
        echo 'saveBtn.disabled=!(changed&&ack.checked);';
        echo '};';
        echo 'if(select){select.addEventListener("change",syncSaveState);}';
        echo 'if(ack){ack.addEventListener("change",syncSaveState);}';
        echo 'syncSaveState();';
        echo 'form.addEventListener("submit",function(event){';
        echo 'var targetStatus=(select.value||"").trim();';
        echo 'if(!select||!ack||!saveBtn){return;}';
        echo 'if(!targetStatus||targetStatus===currentStatus){return;}';
        echo 'if(!ack.checked){event.preventDefault();window.alert("Before manual status update, check the risk acknowledgment box.");return;}';
        echo 'var message="Manual status change is NOT recommended.\\n\\nDo this only if payment was received outside NOWPayments, preferably after the NOWPayments payment link has expired.\\n\\nCurrent status: "+currentStatus+"\\nNew status: "+targetStatus+"\\n\\nDo you want to continue?";';
        echo 'if(!window.confirm(message)){event.preventDefault();return;}';
        echo 'saveBtn.disabled=true;';
        echo 'var dialog=form.closest("dialog");if(dialog){dialog.close();}';
        echo '});';
        echo '});';
        echo 'var deleteOpenBtns=document.querySelectorAll(".anonymous-sales-open-delete-order");';
        echo 'deleteOpenBtns.forEach(function(btn){';
        echo 'btn.addEventListener("click",function(){';
        echo 'var dialogId=btn.getAttribute("data-target-dialog")||"";';
        echo 'if(!dialogId){return;}';
        echo 'var dialog=document.getElementById(dialogId);';
        echo 'if(!dialog||typeof dialog.showModal!=="function"){return;}';
        echo 'dialog.showModal();';
        echo '});';
        echo '});';
        echo 'var deleteCloseBtns=document.querySelectorAll(".anonymous-sales-close-delete-order");';
        echo 'deleteCloseBtns.forEach(function(btn){';
        echo 'btn.addEventListener("click",function(){';
        echo 'var dialog=btn.closest("dialog");';
        echo 'if(dialog){dialog.close();}';
        echo '});';
        echo '});';
        echo 'var deleteDialogs=document.querySelectorAll(".anonymous-sales-delete-order-dialog");';
        echo 'deleteDialogs.forEach(function(dialog){';
        echo 'dialog.addEventListener("click",function(event){';
        echo 'var rect=dialog.getBoundingClientRect();';
        echo 'var inside=event.clientX>=rect.left&&event.clientX<=rect.right&&event.clientY>=rect.top&&event.clientY<=rect.bottom;';
        echo 'if(!inside){dialog.close();}';
        echo '});';
        echo '});';
        echo '})();';
        echo '</script>';

        // ─── E: Check NOW button JS ───────────────────────────────────────────
        echo '<script>';
        echo '(function(){';
        echo 'var checkButtons=document.querySelectorAll(".anonymous-sales-check-order-now");';
        echo 'checkButtons.forEach(function(btn){';
        echo 'btn.addEventListener("click",function(){';
        echo 'var orderId=btn.getAttribute("data-order-id")||"";';
        echo 'var nonce=btn.getAttribute("data-nonce")||"";';
        echo 'if(!orderId){return;}';
        echo 'btn.disabled=true;btn.textContent="Checking...";';
        echo 'var body=new FormData();';
        echo 'body.append("action","anonymous_sales_check_order_now");';
        echo 'body.append("order_id",orderId);';
        echo 'body.append("nonce",nonce);';
        echo 'fetch(' . wp_json_encode(admin_url('admin-ajax.php')) . ',{method:"POST",body:body})';
        echo '.then(function(r){return r.json();})';
        echo '.then(function(data){';
        echo 'if(data&&data.success&&data.data&&data.data.new_status){';
        echo 'var row=btn.closest("tr");';
        echo 'if(row){var statusCell=row.querySelectorAll("td")[3];if(statusCell){statusCell.textContent=data.data.new_status;}}';
        echo 'btn.textContent="Done";';
        echo '}else{btn.textContent="No change";btn.disabled=false;}';
        echo '})';
        echo '.catch(function(){btn.textContent="Error";btn.disabled=false;});';
        echo '});';
        echo '});';
        echo '})();';
        echo '</script>';
        // ─────────────────────────────────────────────────────────────────────
    }
    echo '</div>';

    anonymous_sales_render_admin_page_end();
}

function anonymous_sales_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $schema = anonymous_sales_get_schema_names();
    $router_key_regenerated = false;

    // The "hide this" button of the info card posts to this page too; it has its own nonce.
    if (anonymous_sales_is_post_request() && !isset($_POST['anonymous_sales_dismiss_blogmcp_card'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only tells the two forms apart; the settings form is verified on the next line.
        check_admin_referer('anonymous_sales_settings_action');

        $enabled = isset($_POST['delete_data_on_deactivation']) ? '1' : '0';
        update_option($schema['debug_logging'], isset($_POST['debug_logging']) ? '1' : '0');
        $api_key = sanitize_text_field(wp_unslash((string) ($_POST['nowpayments_apiKey'] ?? '')));
        $webhook_key = sanitize_text_field(wp_unslash((string) ($_POST['nowpayments_webhookKey'] ?? '')));
        $send_callback_each_request = isset($_POST['nowpayments_send_callback_each_request']) ? '1' : '0';
        $regenerate_router_key = isset($_POST['regenerate_router_key']) && '1' === sanitize_text_field(wp_unslash($_POST['regenerate_router_key']));

        update_option($schema['delete_data_on_deactivation'], $enabled);
        update_option($schema['nowpayments_send_callback_each_request'], $send_callback_each_request);
        update_option($schema['nowpayments_send_callback_each_request_user_set'], '1');

        if ($api_key !== '') {
            update_option($schema['nowpayments_apiKey'], anonymous_sales_encrypt_secret($api_key));
        }

        if ($webhook_key !== '') {
            update_option($schema['nowpayments_webhookKey'], anonymous_sales_encrypt_secret($webhook_key));
        }

        if ($regenerate_router_key) {
            update_option($schema['router_auth_key'], anonymous_sales_generate_router_auth_key());
            $router_key_regenerated = true;
        }

        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        if ($router_key_regenerated) {
            echo '<div class="notice notice-warning is-dismissible"><p>Router key regenerated. Update the webhook URL in NOWPayments, or keep "Send endpoint URL in each payment request" enabled below.</p></div>';
        }
    }

    $callback_sync_user_set = get_option($schema['nowpayments_send_callback_each_request_user_set'], '0') === '1';
    if (!$callback_sync_user_set) {
        update_option($schema['nowpayments_send_callback_each_request'], '1');
        update_option($schema['nowpayments_send_callback_each_request_user_set'], '1');
    }

    $is_enabled = get_option($schema['delete_data_on_deactivation'], '0') === '1';
    $api_key = anonymous_sales_decrypt_secret((string) get_option($schema['nowpayments_apiKey'], ''));
    $webhook_key = anonymous_sales_decrypt_secret((string) get_option($schema['nowpayments_webhookKey'], ''));
    $api_key_masked = anonymous_sales_mask_secret($api_key);
    $webhook_key_masked = anonymous_sales_mask_secret($webhook_key);
    $router_key = anonymous_sales_get_router_auth_key();
    $send_callback_each_request = get_option($schema['nowpayments_send_callback_each_request'], '1') === '1';
    $router_url = function_exists('anonymous_sales_get_router_url') ? anonymous_sales_get_router_url() : '';

    anonymous_sales_render_admin_page_start('Settings', 'Configure keys and lifecycle behavior.');

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<details><summary><strong>Getting started</strong></summary><div style="margin-top: 14px;">';
    echo '<ol style="margin: 0; padding-left: 20px; line-height: 1.9;">';
    echo '<li>Expand <strong>NOWPayments</strong> below, paste your <strong>API key</strong> and <strong>Webhook secret</strong>, click <em>Save settings</em>.</li>';
    echo '<li>Copy the <strong>Universal endpoint URL</strong> that appears after saving and paste it as the <strong>Callback URL (IPN)</strong> in your NOWPayments account settings.</li>';
    echo '<li>Go to <strong>Offers</strong>, create your first offer, upload resources (files) inside it.</li>';
    echo '<li>Add the buy button shortcode to any page or post:<br /><code>[anonymous_sales_buy offer_id="1"]</code></li>';
    echo '<li>Add the status shortcode to a page where buyers can check their order (e.g. a dedicated &ldquo;Check order&rdquo; page):<br /><code>[anonymous_sales_status]</code></li>';
    echo '<li>Before you start selling, make one small test purchase with a low-priced offer and check that the order turns to paid and the PDF downloads. Payments go to your real NOWPayments account.</li>';
    echo '</ol>';
    echo '<p>Something does not work? <a href="https://github.com/mjfutera/anonymousSalesPlugin/issues" target="_blank" rel="noopener noreferrer">Report it on GitHub</a> with your versions and the relevant lines of the Error log (turn on debug logging in <em>Lifecycle and logs</em> first).</p>';
    echo '</div></details>';
    echo '</div>';

    echo '<form method="post" class="anonymous-sales-admin-full" style="margin-top:16px;">';
    wp_nonce_field('anonymous_sales_settings_action');

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<details><summary><strong>NOWPayments</strong></summary><div style="margin-top: 14px;">';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">API key</th><td><input type="text" class="regular-text code" name="nowpayments_apiKey" value="" placeholder="' . esc_attr($api_key_masked !== '' ? $api_key_masked : 'Not set') . '" autocomplete="off" /><p class="description">Leave empty to keep current key.</p></td></tr>';
    echo '<tr><th scope="row">Webhook secret</th><td><input type="text" class="regular-text code" name="nowpayments_webhookKey" value="" placeholder="' . esc_attr($webhook_key_masked !== '' ? $webhook_key_masked : 'Not set') . '" autocomplete="off" /><p class="description">Leave empty to keep current secret.</p></td></tr>';
    echo '<tr><th scope="row">Router key</th><td><input type="text" readonly class="regular-text code" value="' . esc_attr($router_key) . '" /><p class="description">Extra authorization key appended to router URL path.</p></td></tr>';
    if ($router_url !== '') {
        echo '<tr><th scope="row">Universal endpoint URL</th><td><input type="text" readonly class="regular-text code" value="' . esc_attr($router_url) . '" style="width: 100%;" /><p class="description">If key changes, update this URL in NOWPayments webhook settings.</p></td></tr>';
    }
    echo '<tr><th scope="row">Approved crypto check</th><td>';
    echo '<input type="number" min="1" step="1" id="anonymous_sales_check_approved_crypto_price_cents" placeholder="Price (cents) optional" style="margin-right:8px;max-width:260px;" />';
    echo '<button type="button" class="button" id="anonymous_sales_check_approved_crypto">Check approved crypto from NOWPayments</button>';
    echo '<p class="description" id="anonymous_sales_check_approved_crypto_result" style="white-space:pre-wrap;">Not checked yet. If Price (cents) is provided, minimum eligibility is calculated for that amount.</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row">Router key rotation</th><td><label><input type="checkbox" name="regenerate_router_key" value="1" id="anonymous_sales_regenerate_router_key" /> Generate a new router key on save.</label><p class="description">After regeneration you must update NOWPayments webhook URL, unless you keep the checkbox below enabled. Recommended only when there are no unfinished orders.</p></td></tr>';
    echo '<tr><th scope="row">Callback URL sync</th><td><label><input type="checkbox" name="nowpayments_send_callback_each_request" value="1" ' . checked($send_callback_each_request, true, false) . ' /> Send the endpoint URL in every request to NOWPayments when creating payment links.</label><p class="description">Recommended if you rotate router key often, or if your NOWPayments account is used across more than one project.</p></td></tr>';
    echo '</tbody></table>';
    echo '</div></details>';
    echo '</div>';

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<details><summary><strong>Shortcode: Order Status</strong></summary><div style="margin-top: 14px;">';
    echo '<p>Use this shortcode on any page where users should check order status and download PDF after payment:</p>';
    echo '<p><code>[anonymous_sales_status]</code></p>';
    echo '<p class="description">Optional attributes: <code>title</code>, <code>button_label</code>, <code>download_label</code>, <code>customCSS</code>.</p>';
    echo '</div></details>';
    echo '</div>';

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<details><summary><strong>Developer: Custom CSS mode</strong></summary><div style="margin-top: 14px;">';
    echo '<p>Add <code>customCSS="true"</code> to either shortcode to disable all built-in CSS and use fully custom class names prefixed with <code>anonymousSP-</code>.</p>';
    echo '<p><strong>Buy shortcode</strong> <code>[anonymous_sales_buy offer_id="1" customCSS="true"]</code> — classes:</p>';
    echo '<table class="widefat striped" style="margin-bottom:14px;"><thead><tr><th>Element</th><th>Class</th></tr></thead><tbody>';
    $buy_classes = [
        'Container' => 'anonymousSP-buy',
        'Open button' => 'anonymousSP-buy-open-btn',
        'Dialog overlay' => 'anonymousSP-buy-dialog',
        'Dialog — open state' => 'anonymousSP-buy-dialog anonymousSP-is-open anonymousSP-buy-is-open',
        'Backdrop' => 'anonymousSP-buy-backdrop',
        'Panel box' => 'anonymousSP-buy-panel',
        'Close button' => 'anonymousSP-buy-close',
        'Title' => 'anonymousSP-buy-title',
        'Offer summary' => 'anonymousSP-buy-offer-summary',
        'Offer name' => 'anonymousSP-buy-offer-name',
        'Offer price' => 'anonymousSP-buy-offer-price',
        'Offer description' => 'anonymousSP-buy-offer-description',
        'Accepted crypto block' => 'anonymousSP-buy-accepted-crypto',
        'Crypto content area' => 'anonymousSP-buy-accepted-crypto-content',
        'Resources title' => 'anonymousSP-buy-resources-title',
        'Resource browser' => 'anonymousSP-buy-resource-browser',
        'Resource toolbar' => 'anonymousSP-buy-resource-toolbar',
        'Resource search wrap' => 'anonymousSP-buy-resource-search-wrap',
        'Resource search input' => 'anonymousSP-buy-resource-search',
        'Per page wrap' => 'anonymousSP-buy-resource-per-page-wrap',
        'Per page select' => 'anonymousSP-buy-resource-per-page',
        'Resource summary' => 'anonymousSP-buy-resource-summary',
        'Resource list (ul)' => 'anonymousSP-buy-list',
        'Resource list item (li)' => 'anonymousSP-buy-list-item',
        'Resource item — expanded state' => 'anonymousSP-buy-list-item is-expanded',
        'Resource toggle button' => 'anonymousSP-buy-resource-toggle',
        'Resource order number' => 'anonymousSP-buy-resource-order',
        'Resource name' => 'anonymousSP-buy-resource-name',
        'Resource detail panel' => 'anonymousSP-buy-resource-panel',
        'Resource fields table' => 'anonymousSP-buy-resource-fields',
        'Resource description' => 'anonymousSP-buy-resource-description',
        'Empty message' => 'anonymousSP-buy-resource-empty',
        'Pagination wrap' => 'anonymousSP-buy-resource-pagination',
        'Pagination button' => 'anonymousSP-buy-resource-page-btn',
        'Pagination info' => 'anonymousSP-buy-resource-page-info',
        'Form' => 'anonymousSP-buy-form',
        'Actions wrap' => 'anonymousSP-buy-actions',
        'Submit button' => 'anonymousSP-buy-submit-btn',
        'Order status text' => 'anonymousSP-buy-status',
        'Order meta box (copy)' => 'anonymousSP-buy-meta',
        'Meta — new order state' => 'anonymousSP-buy-meta is-attention',
        'Meta — copied state' => 'anonymousSP-buy-meta is-copied',
        'Meta warning text' => 'anonymousSP-buy-meta-warning',
        'Order number value' => 'anonymousSP-buy-order-number',
        'Claim token value' => 'anonymousSP-buy-claim-token',
        'Copy hint text' => 'anonymousSP-buy-copy-hint',
        'Tab close hint' => 'anonymousSP-buy-tab-close-hint',
        'Payment link' => 'anonymousSP-buy-payment-link',
        'Status check wrap' => 'anonymousSP-buy-status-check',
        'Check status button' => 'anonymousSP-buy-check-status-btn',
        'Status result' => 'anonymousSP-buy-status-result',
        'Download wrap' => 'anonymousSP-buy-download-wrap',
        'Download link' => 'anonymousSP-buy-download-link',
    ];
    foreach ($buy_classes as $element => $cls) {
        echo '<tr><td>' . esc_html($element) . '</td><td><code>' . esc_html($cls) . '</code></td></tr>';
    }
    echo '</tbody></table>';
    echo '<p><strong>Status shortcode</strong> <code>[anonymous_sales_status customCSS="true"]</code> — classes:</p>';
    echo '<table class="widefat striped" style="margin-bottom:14px;"><thead><tr><th>Element</th><th>Class</th></tr></thead><tbody>';
    $status_classes = [
        'Container' => 'anonymousSP-status',
        'Title' => 'anonymousSP-status-title',
        'Form' => 'anonymousSP-status-form',
        'Row (flex)' => 'anonymousSP-status-row',
        'Grow child (flex)' => 'anonymousSP-status-grow',
        'Order field wrap' => 'anonymousSP-status-order-field',
        'Order label' => 'anonymousSP-status-order-label',
        'Order input' => 'anonymousSP-status-order-input',
        'Token field wrap' => 'anonymousSP-status-token-field',
        'Token label' => 'anonymousSP-status-token-label',
        'Token input' => 'anonymousSP-status-token-input',
        'Actions wrap' => 'anonymousSP-status-actions',
        'Submit button' => 'anonymousSP-status-submit-btn',
        'Download link' => 'anonymousSP-status-download-link',
        'Status result' => 'anonymousSP-status-status',
    ];
    foreach ($status_classes as $element => $cls) {
        echo '<tr><td>' . esc_html($element) . '</td><td><code>' . esc_html($cls) . '</code></td></tr>';
    }
    echo '</tbody></table>';
    echo '<p class="description">Tip: When <code>customCSS="true"</code>, no stylesheet is enqueued. Copy <code>anonymous-sales-frontend.css</code> from the plugin as a starting point and rename all <code>anonymous-sales</code> prefixes to your own.</p>';
    echo '</div></details>';
    echo '</div>';

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<details><summary><strong>Lifecycle and logs</strong></summary><div style="margin-top: 14px;">';
    echo '<label><input type="checkbox" name="debug_logging" value="1" ' . checked(get_option($schema['debug_logging'], '0') === '1', true, false) . ' /> Write a debug log (Anonymous Sales -> Error log).</label>';
    echo '<p class="description">Off by default. When on, the plugin records its events (order numbers, payment ids, IP addresses of throttled requests, short excerpts of payment provider answers) in the uploads folder. Secrets are masked. The file is capped at 1 MB.</p>';
    echo '<label><input type="checkbox" name="delete_data_on_deactivation" value="1" ' . checked($is_enabled, true, false) . ' /> Delete all plugin data (tables and settings) when the plugin is deleted.</label>';
    echo '<p class="description">If disabled, your offers, orders and settings are kept when the plugin is deactivated or deleted. Deactivating never deletes data.</p>';
    echo '</div></details>';
    echo '</div>';

    submit_button('Save settings');
    echo '</form>';

    echo '<script>';
    echo '(function(){';
    echo 'var keyRotationCheckbox=document.getElementById("anonymous_sales_regenerate_router_key");';
    echo 'var approvedCryptoBtn=document.getElementById("anonymous_sales_check_approved_crypto");';
    echo 'var approvedCryptoResult=document.getElementById("anonymous_sales_check_approved_crypto_result");';
    echo 'var approvedCryptoPriceCents=document.getElementById("anonymous_sales_check_approved_crypto_price_cents");';
    echo 'var routerUrl=' . wp_json_encode((string) $router_url) . ';';
    echo 'if(!keyRotationCheckbox){return;}';
    echo 'keyRotationCheckbox.addEventListener("change",function(){';
    echo 'if(!keyRotationCheckbox.checked){return;}';
    echo 'window.alert("Router key rotation is recommended only when there are no unfinished orders.");';
    echo '});';
    echo 'if(approvedCryptoBtn&&approvedCryptoResult&&routerUrl){';
    echo 'approvedCryptoBtn.addEventListener("click",function(){';
    echo 'approvedCryptoBtn.disabled=true;';
    echo 'approvedCryptoResult.textContent="Checking approved crypto...";';
    echo 'var params=new URLSearchParams();';
    echo 'params.set("action","check_approved_crypto");';
    echo 'if(approvedCryptoPriceCents&&approvedCryptoPriceCents.value){var cents=parseInt(approvedCryptoPriceCents.value,10)||0;if(cents>0){params.set("include_minimums","1");params.set("price_currency","usd");params.set("price_amount",String(cents/100));}}';
    echo 'fetch(routerUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:params.toString()})';
    echo '.then(function(r){return r.json();})';
    echo '.then(function(data){';
    echo 'if(!data||!data.success){approvedCryptoResult.textContent=(data&&data.message)?data.message:"Could not fetch approved crypto list.";return;}';
    echo 'var coins=Array.isArray(data.approved_coins)?data.approved_coins:[];';
    echo 'if(!coins.length){approvedCryptoResult.textContent="No approved crypto returned by NOWPayments.";return;}';
    echo 'var text="Approved crypto ("+coins.length+"):\n"+coins.join(", ");';
    echo 'var eligible=Array.isArray(data.eligible_coins)?data.eligible_coins:[];';
    echo 'var ineligible=Array.isArray(data.ineligible_coins)?data.ineligible_coins:[];';
    echo 'if(approvedCryptoPriceCents&&approvedCryptoPriceCents.value){text+="\n\nEligible for provided price ("+eligible.length+"):\n"+(eligible.length?eligible.join(", "):"none");if(ineligible.length){text+="\n\nBelow minimum ("+ineligible.length+"):\n"+ineligible.join(", ");}}';
    echo 'approvedCryptoResult.textContent=text;';
    echo '})';
    echo '.catch(function(){approvedCryptoResult.textContent="Network error while checking approved crypto.";})';
    echo '.finally(function(){approvedCryptoBtn.disabled=false;});';
    echo '});';
    echo '}';
    echo '})();';
    echo '</script>';

    anonymous_sales_render_info_cards();

    anonymous_sales_render_admin_page_end();
}

/**
 * Documentation links, the payment provider note and an optional hint about the author's other
 * service (MFPro Blog MCP). The hint is a plain link on this settings screen only: nothing is
 * loaded from that service, no data is sent to it, and each admin can hide it for good.
 */
function anonymous_sales_render_info_cards() {
    $meta_key = 'anonymous_sales_blogmcp_card_dismissed';

    if (anonymous_sales_is_post_request() && isset($_POST['anonymous_sales_dismiss_blogmcp_card'])) {
        check_admin_referer('anonymous_sales_dismiss_blogmcp_card');
        update_user_meta(get_current_user_id(), $meta_key, '1');
    }

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<h2 style="margin-top: 0;">More information</h2>';
    echo '<ul>';
    echo '<li><a href="https://michalfutera.pro/" target="_blank" rel="noopener noreferrer">michalfutera.pro</a>: the author\'s website</li>';
    echo '<li><a href="https://faq.michalfutera.pro/" target="_blank" rel="noopener noreferrer">faq.michalfutera.pro</a>: questions and answers</li>';
    echo '<li><a href="https://blogmcp.michalfutera.pro/" target="_blank" rel="noopener noreferrer">blogmcp.michalfutera.pro</a>: MFPro Blog MCP, manage WordPress with AI (a separate service)</li>';
    echo '<li><a href="https://scalewithisaiah.com/" target="_blank" rel="noopener noreferrer">scalewithisaiah.com</a>: the second creator of this plugin</li>';
    echo '<li><a href="https://github.com/mjfutera/anonymousSalesPlugin" target="_blank" rel="noopener noreferrer">GitHub</a>: public source code of this plugin, issues and contributions</li>';
    echo '</ul>';
    echo '<p class="description">Payments are processed by <a href="https://nowpayments.io/" target="_blank" rel="noopener noreferrer">NOWPayments</a>. You need your own NOWPayments account and API key. How to create the account and get the keys: <a href="https://faq.michalfutera.pro/nowpayments-create-account-and-get-keys" target="_blank" rel="noopener noreferrer">step-by-step guide</a> (the guide contains the author&#039;s affiliate link; the author may receive a commission from NOWPayments, you pay nothing extra).</p>';
    echo '</div>';

    if (get_user_meta(get_current_user_id(), $meta_key, true) === '1') {
        return;
    }

    $url = add_query_arg(
        [
            'utm_source' => 'anonymous-sales',
            'utm_medium' => 'wp-admin',
            'utm_campaign' => 'settings-card',
        ],
        'https://blogmcp.michalfutera.pro/'
    );

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<h2 style="margin-top: 0;">Manage your shop with AI</h2>';
    echo '<p>MFPro Blog MCP, a separate service by the author of this plugin, connects your WordPress site to AI assistants such as Claude, ChatGPT or Cursor. This plugin works fully without it.</p>';
    echo '<p><a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">Learn more</a></p>';
    echo '<form method="post" style="margin:0;">';
    wp_nonce_field('anonymous_sales_dismiss_blogmcp_card');
    echo '<input type="hidden" name="anonymous_sales_dismiss_blogmcp_card" value="1" />';
    submit_button('Hide this', 'link', '', false);
    echo '</form>';
    echo '</div>';
}

function anonymous_sales_render_error_log_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = null;

    if (anonymous_sales_is_post_request() && isset($_POST['anonymous_sales_error_log_action'])) {
        check_admin_referer('anonymous_sales_error_log_action');

        $action = sanitize_key(wp_unslash((string) $_POST['anonymous_sales_error_log_action']));
        if ($action === 'clear') {
            if (!function_exists('anonymous_sales_get_error_log_file_path')) {
                $notice = ['type' => 'error', 'message' => 'Error log path helper is unavailable.'];
            } else {
                $log_file = anonymous_sales_get_error_log_file_path();
                $ok = @file_put_contents($log_file, '');
                if ($ok === false) {
                    $notice = ['type' => 'error', 'message' => 'Could not clear error log file.'];
                } else {
                    $notice = ['type' => 'success', 'message' => 'Error log file was cleared.'];
                }
            }
        } elseif ($action === 'write_test') {
            if (!function_exists('anonymous_sales_log_info')) {
                $notice = ['type' => 'error', 'message' => 'Log helper is unavailable.'];
            } elseif (!anonymous_sales_logging_enabled()) {
                $notice = ['type' => 'error', 'message' => 'Debug logging is off. Switch it on in Settings -> Lifecycle and logs first.'];
            } else {
                anonymous_sales_log_info('Manual test log entry from admin Error log page.', [
                    'source' => 'admin_error_log_page',
                    'user_id' => get_current_user_id(),
                    'time_utc' => current_time('mysql', true),
                ]);
                $notice = ['type' => 'success', 'message' => 'Test log entry was written. Use Refresh to load new entries.'];
            }
        }
    }

    $selected_level = isset($_GET['anonymous_sales_log_level']) ? strtoupper(sanitize_key(wp_unslash((string) $_GET['anonymous_sales_log_level']))) : 'ALL'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, nothing is changed.
    $allowed_levels = ['ALL', 'ERROR', 'WARNING', 'INFO', 'DEBUG'];
    if (!in_array($selected_level, $allowed_levels, true)) {
        $selected_level = 'ALL';
    }

    $error_log_preview = anonymous_sales_get_error_log_preview();
    $entries = anonymous_sales_parse_log_entries((string) ($error_log_preview['content'] ?? ''), true);
    $refresh_nonce = wp_create_nonce('anonymous_sales_error_log_refresh');

    $log_file = function_exists('anonymous_sales_get_error_log_file_path')
        ? (string) anonymous_sales_get_error_log_file_path()
        : '';
    $log_exists = $log_file !== '' && file_exists($log_file);
    $log_readable = $log_exists && is_readable($log_file);
    $log_writable = $log_file !== '' && wp_is_writable(dirname($log_file));
    $log_size = $log_exists ? (int) @filesize($log_file) : 0;
    $log_mtime = $log_exists ? (int) @filemtime($log_file) : 0;

    anonymous_sales_render_admin_page_start('Error log', 'Review latest plugin log entries directly in admin panel.');
    anonymous_sales_admin_notice($notice);

    echo '<div class="card anonymous-sales-admin-card anonymous-sales-admin-full">';
    echo '<p class="description">This view displays the latest part of the log file (tail preview). Use filters below to quickly isolate relevant entries.</p>';
    echo '<div style="margin:10px 0 14px;padding:10px 12px;border:1px solid #dcdcde;border-radius:8px;background:#f8f9fa;">';
    echo '<strong>Log diagnostics</strong>';
    echo '<div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:6px;font-size:12px;">';
    echo '<span><strong>Path:</strong> <code>' . esc_html($log_file !== '' ? $log_file : 'unavailable') . '</code></span>';
    echo '<span><strong>Exists:</strong> ' . ($log_exists ? 'yes' : 'no') . '</span>';
    echo '<span><strong>Readable:</strong> ' . ($log_readable ? 'yes' : 'no') . '</span>';
    echo '<span><strong>Writable dir:</strong> ' . ($log_writable ? 'yes' : 'no') . '</span>';
    echo '<span><strong>Size:</strong> ' . esc_html((string) $log_size) . ' bytes</span>';
    echo '<span><strong>Updated:</strong> ' . ($log_mtime > 0 ? esc_html(gmdate('Y-m-d H:i:s', $log_mtime) . ' UTC') : '-') . '</span>';
    echo '</div>';
    echo '<form method="post" style="margin-top:8px;display:flex;gap:8px;align-items:center;">';
    wp_nonce_field('anonymous_sales_error_log_action');
    echo '<input type="hidden" name="anonymous_sales_error_log_action" value="write_test" />';
    submit_button('Write test log entry', 'secondary', '', false);
    echo '<span class="description">Use this if the table is empty to verify write/read pipeline.</span>';
    echo '</form>';
    echo '</div>';
    echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 12px;">';
    echo '<label for="anonymous_sales_log_level_filter"><strong>Level:</strong></label>';
    echo '<select id="anonymous_sales_log_level_filter">';
    foreach ($allowed_levels as $level) {
        echo '<option value="' . esc_attr($level) . '" ' . selected($selected_level, $level, false) . '>' . esc_html($level) . '</option>';
    }
    echo '</select>';
    echo '<label for="anonymous_sales_log_search"><strong>Search:</strong></label>';
    echo '<input type="text" id="anonymous_sales_log_search" class="regular-text" placeholder="status, payment_id, webhook, order..." />';
    echo '<button type="button" class="button button-small" id="anonymous_sales_log_select_visible">Select visible</button>';
    echo '<button type="button" class="button button-small" id="anonymous_sales_log_clear_selection">Clear selection</button>';
    echo '<button type="button" class="button button-small" id="anonymous_sales_log_refresh">Refresh</button>';
    echo '<button type="button" class="button button-small" id="anonymous_sales_log_copy_selected">Copy selected</button>';
    echo '<button type="button" class="button button-small" id="anonymous_sales_log_copy_all">Copy all</button>';
    echo '<span id="anonymous_sales_log_count" class="description" style="margin-left:auto;"></span>';
    echo '</div>';

    if (empty($entries)) {
        echo '<p>No log entries found in preview.</p>';
    } else {
        echo '<div style="max-height:560px;overflow:auto;border:1px solid #dcdcde;border-radius:8px;background:#fff;">';
        echo '<table class="widefat striped" style="margin:0;">';
        echo '<thead style="position:sticky;top:0;background:#f6f7f7;z-index:1;"><tr><th style="width:38px;">#</th><th style="width:86px;">Level</th><th>Entry</th></tr></thead><tbody id="anonymous_sales_log_rows">';
        $index = 1;
        foreach ($entries as $entry) {
            $level = (string) ($entry['level'] ?? 'UNKNOWN');
            $line = (string) ($entry['line'] ?? '');
            echo '<tr class="anonymous-sales-log-row" data-level="' . esc_attr($level) . '" data-line="' . esc_attr($line) . '">';
            echo '<td><label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" class="anonymous-sales-log-select" /><span>' . esc_html((string) $index) . '</span></label></td>';
            echo '<td><span style="display:inline-block;min-width:72px;text-align:center;font-weight:700;border-radius:999px;padding:2px 8px;' . esc_attr(anonymous_sales_get_log_level_badge_style($level)) . '">' . esc_html($level) . '</span></td>';
            echo '<td><code style="display:block;white-space:pre-wrap;word-break:break-word;color:#8b0000;">' . esc_html($line) . '</code></td>';
            echo '</tr>';
            $index++;
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    echo '<form method="post" style="margin-top:12px;">';
    wp_nonce_field('anonymous_sales_error_log_action');
    echo '<input type="hidden" name="anonymous_sales_error_log_action" value="clear" />';
    submit_button('Clear error log', 'delete', '', false, [
        'id' => 'anonymous_sales_clear_error_log_btn',
    ]);
    echo '<p class="description" style="color:#b00020;margin-top:8px;">Clearing logs is not recommended during active troubleshooting.</p>';
    echo '</form>';
    echo '</div>';

    echo '<script>';
    echo '(function(){';
    echo 'var ajaxUrl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';';
    echo 'var refreshNonce=' . wp_json_encode($refresh_nonce) . ';';
    echo 'var levelFilter=document.getElementById("anonymous_sales_log_level_filter");';
    echo 'var searchInput=document.getElementById("anonymous_sales_log_search");';
    echo 'var rows=Array.prototype.slice.call(document.querySelectorAll(".anonymous-sales-log-row"));';
    echo 'var rowsContainer=document.getElementById("anonymous_sales_log_rows");';
    echo 'var countLabel=document.getElementById("anonymous_sales_log_count");';
    echo 'var selectVisibleBtn=document.getElementById("anonymous_sales_log_select_visible");';
    echo 'var clearSelectionBtn=document.getElementById("anonymous_sales_log_clear_selection");';
    echo 'var refreshBtn=document.getElementById("anonymous_sales_log_refresh");';
    echo 'var copySelectedBtn=document.getElementById("anonymous_sales_log_copy_selected");';
    echo 'var copyAllBtn=document.getElementById("anonymous_sales_log_copy_all");';
    echo 'var clearBtn=document.getElementById("anonymous_sales_clear_error_log_btn");';
    echo 'var escapeHtml=function(text){';
    echo 'return String(text||"").replace(/[&<>"\']/g,function(char){return ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#039;"})[char];});';
    echo '};';
    echo 'var badgeStyleByLevel=function(level){';
    echo 'var key=String(level||"UNKNOWN").toUpperCase();';
    echo 'if(key==="ERROR"){return "background:#fbeaea;color:#8b0000;border:1px solid #f1b5b5;";}';
    echo 'if(key==="WARNING"){return "background:#fff7e6;color:#8a5800;border:1px solid #f0d195;";}';
    echo 'if(key==="INFO"){return "background:#eaf4ff;color:#0b4f8a;border:1px solid #b8d8f5;";}';
    echo 'if(key==="DEBUG"){return "background:#f2f2f4;color:#4d4d57;border:1px solid #d4d4dc;";}';
    echo 'return "background:#f7f7f7;color:#555;border:1px solid #dcdcde;";';
    echo '};';
    echo 'var refreshRowsCache=function(){rows=Array.prototype.slice.call(document.querySelectorAll(".anonymous-sales-log-row"));};';
    echo 'var renderRows=function(entries){';
    echo 'if(!rowsContainer){return;}';
    echo 'if(!entries||!entries.length){rowsContainer.innerHTML="";refreshRowsCache();applyFilters();return;}';
    echo 'var html="";';
    echo 'entries.forEach(function(entry,index){';
    echo 'var level=String((entry&&entry.level)||"UNKNOWN").toUpperCase();';
    echo 'var line=String((entry&&entry.line)||"");';
    echo 'html+="<tr class=\"anonymous-sales-log-row\" data-level=\""+escapeHtml(level)+"\" data-line=\""+escapeHtml(line)+"\">";';
    echo 'html+="<td><label style=\"display:flex;gap:6px;align-items:center;\"><input type=\"checkbox\" class=\"anonymous-sales-log-select\" /><span>"+String(index+1)+"</span></label></td>";';
    echo 'html+="<td><span style=\"display:inline-block;min-width:72px;text-align:center;font-weight:700;border-radius:999px;padding:2px 8px;"+badgeStyleByLevel(level)+"\">"+escapeHtml(level)+"</span></td>";';
    echo 'html+="<td><code style=\"display:block;white-space:pre-wrap;word-break:break-word;color:#8b0000;\">"+escapeHtml(line)+"</code></td></tr>";';
    echo '});';
    echo 'rowsContainer.innerHTML=html;';
    echo 'refreshRowsCache();';
    echo 'applyFilters();';
    echo '};';
    echo 'var copyText=function(text){';
    echo 'if(!text){window.alert("There is no log content to copy.");return;}';
    echo 'if(navigator.clipboard&&navigator.clipboard.writeText){';
    echo 'navigator.clipboard.writeText(text).then(function(){window.alert("Copied to clipboard.");}).catch(function(){fallbackCopy(text);});';
    echo 'return;';
    echo '}';
    echo 'fallbackCopy(text);';
    echo '};';
    echo 'var fallbackCopy=function(text){';
    echo 'var helper=document.createElement("textarea");';
    echo 'helper.value=text;';
    echo 'helper.setAttribute("readonly","readonly");';
    echo 'helper.style.position="fixed";helper.style.opacity="0";helper.style.pointerEvents="none";';
    echo 'document.body.appendChild(helper);';
    echo 'helper.select();';
    echo 'var ok=false;';
    echo 'try{ok=document.execCommand("copy");}catch(e){ok=false;}';
    echo 'document.body.removeChild(helper);';
    echo 'window.alert(ok?"Copied to clipboard.":"Copy failed. Please copy manually.");';
    echo '};';
    echo 'var getVisibleRows=function(){return rows.filter(function(row){return row.style.display!=="none";});};';
    echo 'var applyFilters=function(){';
    echo 'var level=(levelFilter&&levelFilter.value)?levelFilter.value:"ALL";';
    echo 'var query=((searchInput&&searchInput.value)||"").trim().toLowerCase();';
    echo 'var visible=0;';
    echo 'rows.forEach(function(row){';
    echo 'var rowLevel=(row.getAttribute("data-level")||"UNKNOWN").toUpperCase();';
    echo 'var line=(row.getAttribute("data-line")||"").toLowerCase();';
    echo 'var levelMatch=level==="ALL"||rowLevel===level;';
    echo 'var queryMatch=query===""||line.indexOf(query)!==-1;';
    echo 'var show=levelMatch&&queryMatch;';
    echo 'row.style.display=show?"":"none";';
    echo 'if(show){visible++;}';
    echo '});';
    echo 'if(countLabel){countLabel.textContent="Visible: "+visible+" / "+rows.length;}';
    echo '};';
    echo 'if(levelFilter){levelFilter.addEventListener("change",applyFilters);}';
    echo 'if(searchInput){searchInput.addEventListener("input",applyFilters);}';
    echo 'if(selectVisibleBtn){selectVisibleBtn.addEventListener("click",function(){getVisibleRows().forEach(function(row){var box=row.querySelector(".anonymous-sales-log-select");if(box){box.checked=true;}});});}';
    echo 'if(clearSelectionBtn){clearSelectionBtn.addEventListener("click",function(){rows.forEach(function(row){var box=row.querySelector(".anonymous-sales-log-select");if(box){box.checked=false;}});});}';
    echo 'if(refreshBtn){refreshBtn.addEventListener("click",function(){';
    echo 'refreshBtn.disabled=true;';
    echo 'refreshBtn.textContent="Refreshing...";';
    echo 'var body=new URLSearchParams();';
    echo 'body.append("action","anonymous_sales_refresh_error_log");';
    echo 'body.append("nonce",refreshNonce);';
    echo 'fetch(ajaxUrl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()})';
    echo '.then(function(response){return response.json();})';
    echo '.then(function(payload){';
    echo 'if(!payload||payload.success!==true){throw new Error("Refresh failed");}';
    echo 'renderRows(Array.isArray(payload.data&&payload.data.entries)?payload.data.entries:[]);';
    echo '})';
    echo '.catch(function(){window.alert("Could not refresh log preview.");})';
    echo '.finally(function(){refreshBtn.disabled=false;refreshBtn.textContent="Refresh";});';
    echo '});}';
    echo 'if(copySelectedBtn){copySelectedBtn.addEventListener("click",function(){';
    echo 'var selectedLines=[];';
    echo 'rows.forEach(function(row){var box=row.querySelector(".anonymous-sales-log-select");if(box&&box.checked){selectedLines.push(row.getAttribute("data-line")||"");}});';
    echo 'copyText(selectedLines.join("\\n"));';
    echo '});}';
    echo 'if(copyAllBtn){copyAllBtn.addEventListener("click",function(){';
    echo 'var allLines=rows.map(function(row){return row.getAttribute("data-line")||"";});';
    echo 'copyText(allLines.join("\\n"));';
    echo '});}';
    echo 'if(clearBtn){clearBtn.addEventListener("click",function(event){';
    echo 'var confirmed=window.confirm("Clearing the error log is not recommended. Are you sure you want to clear it?");';
    echo 'if(!confirmed){event.preventDefault();}';
    echo '});}';
    echo 'applyFilters();';
    echo '})();';
    echo '</script>';

    anonymous_sales_render_admin_page_end();
}

add_action('admin_menu', 'anonymous_sales_register_admin_menu');
add_action('admin_enqueue_scripts', 'anonymous_sales_admin_enqueue_assets');
add_action('wp_ajax_anonymous_sales_refresh_error_log', 'anonymous_sales_handle_refresh_error_log_ajax');

// ─── E: AJAX handler — Check NOW ─────────────────────────────────────────────

function anonymous_sales_handle_check_order_now_ajax() {
    anonymous_sales_log_admin_action('check_order_now_click');

    if (!current_user_can('manage_options')) {
        anonymous_sales_log_admin_action('check_order_now_denied', [], 'warning');
        wp_send_json_error(['message' => 'Unauthorized.'], 403);
    }

    $order_id = absint($_POST['order_id'] ?? 0);
    if ($order_id <= 0) {
        wp_send_json_error(['message' => 'Invalid order ID.'], 400);
    }

    check_ajax_referer('anonymous_sales_check_order_status_' . $order_id, 'nonce');

    if (!anonymous_sales_load_api_module()) {
        wp_send_json_error(['message' => 'API module unavailable.'], 500);
    }

    global $wpdb;
    $schema = anonymous_sales_get_schema_names();

    $order = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$schema['orders']} WHERE order_id = %d LIMIT 1", $order_id),
        ARRAY_A
    );

    if ($order === null && $wpdb->last_error !== '') {
        anonymous_sales_log_db_outcome('select', $schema['orders'], false, [
            'query' => 'check_order_now_fetch_order',
            'order_id' => (int) $order_id,
        ]);
    }

    if (!$order) {
        wp_send_json_error(['message' => 'Order not found.'], 404);
    }

    if ((string) $order['order_paymentId'] === '' && (string) ($order['order_invoiceId'] ?? '') === '') {
        wp_send_json_error(['message' => 'Order has no payment or invoice ID.'], 400);
    }

    $fetched = anonymous_sales_fetch_payment_for_order($order);
    if (is_wp_error($fetched)) {
        wp_send_json_error(['message' => $fetched->get_error_message()], 502);
    }

    $changed = anonymous_sales_apply_fetched_payment_status($order, $fetched);

    $new_status = $changed
        ? anonymous_sales_map_payment_status(anonymous_sales_extract_payment_status($fetched))
        : (string) $order['order_currentStatus'];

    wp_send_json_success([
        'changed'    => $changed,
        'new_status' => $new_status,
    ]);
}

function anonymous_sales_handle_option_update_audit($option, $old_value, $value) {
    if (strpos((string) $option, 'anonymous_sales_') !== 0) {
        return;
    }

    anonymous_sales_log_admin_action('settings_option_updated', [
        'option' => (string) $option,
        'value_changed' => $old_value !== $value,
    ]);
}

add_action('wp_ajax_anonymous_sales_check_order_now', 'anonymous_sales_handle_check_order_now_ajax');
add_action('updated_option', 'anonymous_sales_handle_option_update_audit', 10, 3);

// ─────────────────────────────────────────────────────────────────────────────
