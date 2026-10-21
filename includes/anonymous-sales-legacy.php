<?php
/**
 * Compatibility with the versions released before the rename to "Anonymous Sales".
 *
 * Older installs used the prefix "mfpro_anonymoussalesplugin_" for tables and options. This file moves that
 * data to the new names once and keeps the public entry points of the old versions working (webhook address
 * registered at NOWPayments, shortcodes already placed in pages, the wp-config.php constant).
 * Nothing here is needed on a fresh install.
 */

defined('ABSPATH') || exit;

// wp-config.php constant of the old versions.
if (!defined('ANONYMOUS_SALES_ENCRYPTION_KEY') && defined('MFPRO_ANON_SALES_ENCRYPTION_KEY')) {
    define('ANONYMOUS_SALES_ENCRYPTION_KEY', MFPRO_ANON_SALES_ENCRYPTION_KEY);
}

/**
 * Moves tables, options, the cron event and per-user flags from the old names to the new ones.
 * Safe to run repeatedly: a step is skipped when the old data is gone or the new one already exists.
 */
function anonymous_sales_migrate_legacy_data() {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-off rename of the plugin's own tables; every name is built from fixed strings and the table prefix.
    $old_prefix = $wpdb->prefix . 'mfpro_anonymoussalesplugin_';
    $new_prefix = $wpdb->prefix . 'anonymous_sales_';
    $tables     = ['offers', 'orders', 'promocodes', 'resources'];

    $to_move = [];
    foreach ($tables as $table) {
        $old_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old_prefix . $table)));
        $new_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($new_prefix . $table)));
        if ($old_exists && !$new_exists) {
            $to_move[] = $table;
        }
    }

    if ($to_move) {
        // Constraint names are unique per schema and carry the old prefix: drop them, install() adds them back under the new names.
        $constraints = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS constraint_name FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = %s AND CONSTRAINT_NAME LIKE %s',
                'FOREIGN KEY',
                $wpdb->esc_like('fk_mfpro_anonymoussalesplugin_') . '%'
            ),
            ARRAY_A
        );
        foreach ((array) $constraints as $constraint) {
            $wpdb->query('ALTER TABLE `' . esc_sql($constraint['table_name']) . '` DROP FOREIGN KEY `' . esc_sql($constraint['constraint_name']) . '`');
        }

        foreach ($to_move as $table) {
            $wpdb->query('RENAME TABLE `' . $old_prefix . $table . '` TO `' . $new_prefix . $table . '`');
        }
    }
    // phpcs:enable

    $options = [
        'nowpayments_apiKey',
        'nowpayments_webhookKey',
        'nowpayments_webhookToken',
        'router_auth_key',
        'nowpayments_send_callback_each_request',
        'nowpayments_send_callback_each_request_user_set',
        'delete_data_on_deactivation',
        'debug_logging',
        'encryption_key',
    ];
    $missing = '__anonymous_sales_missing__';
    foreach ($options as $option) {
        $old_value = get_option('mfpro_anonymoussalesplugin_' . $option, $missing);
        if ($old_value === $missing) {
            continue;
        }
        if (get_option('anonymous_sales_' . $option, $missing) === $missing) {
            add_option('anonymous_sales_' . $option, $old_value, '', false);
        }
        delete_option('mfpro_anonymoussalesplugin_' . $option);
    }
    delete_option('mfpro_anonymoussalesplugin_plugin_version');

    wp_clear_scheduled_hook('mfpro_anonymoussalesplugin_poll_pending_orders');

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time rename of this plugin's own user meta key during the migration from the old name.
    $wpdb->update($wpdb->usermeta, ['meta_key' => 'anonymous_sales_blogmcp_card_dismissed'], ['meta_key' => 'mfpro_anonymoussalesplugin_blogmcp_card_dismissed']);
}

/**
 * The webhook address given to NOWPayments before the rename (/wp-json/mfpro-anonymoussalesplugin/v1/router/<key>)
 * keeps working: same handler, same key.
 */
function anonymous_sales_register_legacy_rest_routes() {
    register_rest_route(
        'mfpro-anonymoussalesplugin/v1',
        '/router/(?P<router_key>[A-Za-z0-9_-]+)',
        [
            'methods'             => WP_REST_Server::ALLMETHODS,
            'callback'            => 'anonymous_sales_handle_router_request',
            'permission_callback' => '__return_true',
        ]
    );
}
add_action('rest_api_init', 'anonymous_sales_register_legacy_rest_routes');
