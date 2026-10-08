<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Your data (offers, resources, orders, promo codes and the settings) is removed only when
 * "Delete all plugin data when the plugin is deleted" was ticked in Anonymous Sales -> Settings.
 * The log folder in the uploads directory is always removed: it only holds debug output.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$anonymous_sales_option_prefix = 'anonymous_sales_';
$anonymous_sales_delete_data   = get_option($anonymous_sales_option_prefix . 'delete_data_on_deactivation', '0') === '1';

if ($anonymous_sales_delete_data) {
    // Order matters: orders, resources and promo codes point to offers.
    $anonymous_sales_tables = ['orders', 'resources', 'promocodes', 'offers'];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- removing the plugin's own tables; the names are fixed in this file.
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($anonymous_sales_tables as $anonymous_sales_table) {
        $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . $anonymous_sales_option_prefix . $anonymous_sales_table . '`');
    }
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    // phpcs:enable

    $anonymous_sales_options = [
        'nowpayments_apiKey',
        'nowpayments_webhookKey',
        'nowpayments_webhookToken',
        'router_auth_key',
        'nowpayments_send_callback_each_request',
        'nowpayments_send_callback_each_request_user_set',
        'delete_data_on_deactivation',
        'plugin_version',
        'encryption_key',
        'debug_logging',
    ];
    foreach ($anonymous_sales_options as $anonymous_sales_option) {
        delete_option($anonymous_sales_option_prefix . $anonymous_sales_option);
        delete_site_option($anonymous_sales_option_prefix . $anonymous_sales_option);
    }
}

wp_clear_scheduled_hook('anonymous_sales_poll_pending_orders');

// The "hide this" choice of the MFPro Blog MCP hint card (one flag per admin user).
delete_metadata('user', 0, 'anonymous_sales_blogmcp_card_dismissed', '', true);

// Rate-limit counters.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- clearing this plugin's own transients.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('_transient_anonymous_sales_rl_') . '%', $wpdb->esc_like('_transient_timeout_anonymous_sales_rl_') . '%'));

// The log folder (uploads/anonymous-sales).
$anonymous_sales_uploads = wp_upload_dir(null, false);
$anonymous_sales_dir     = trailingslashit($anonymous_sales_uploads['basedir']) . 'anonymous-sales';
if (is_dir($anonymous_sales_dir)) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    if (WP_Filesystem()) {
        global $wp_filesystem;
        $wp_filesystem->delete($anonymous_sales_dir, true);
    }
}
