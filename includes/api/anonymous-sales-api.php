<?php

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin owns these tables; table names come from its own schema (anonymous_sales_get_schema_names), every value goes through $wpdb->prepare() or the format arguments of insert()/update().

function anonymous_sales_register_rest_routes() {
    register_rest_route(
        'anonymous-sales/v1',
        '/router/(?P<router_key>[A-Za-z0-9_-]+)',
        [
            'methods' => WP_REST_Server::ALLMETHODS,
            'callback' => 'anonymous_sales_handle_router_request',
            'permission_callback' => '__return_true',
        ]
    );
}

function anonymous_sales_send_router_cors_headers($served, $result, $request, $server) {
    if (!($request instanceof WP_REST_Request)) {
        return $served;
    }

    $route = (string) $request->get_route();
    if (strpos($route, '/anonymous-sales/v1/router/') !== 0 && strpos($route, '/mfpro-anonymoussalesplugin/v1/router/') !== 0) {
        return $served;
    }

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 600');

    return $served;
}

function anonymous_sales_handle_router_request(WP_REST_Request $request) {
    if (strtoupper((string) $request->get_method()) === 'OPTIONS') {
        return new WP_REST_Response(['success' => true], 200);
    }

    $action_param = sanitize_key((string) $request->get_param('action'));
    $signature_header = (string) $request->get_header('x-nowpayments-sig');

    anonymous_sales_log_info('Router request received.', [
        'route' => (string) $request->get_route(),
        'action' => (string) $action_param,
        'method' => strtoupper((string) $request->get_method()),
        'ip' => anonymous_sales_get_client_ip(),
        'signature_present' => $signature_header !== '',
        'signature_length' => strlen($signature_header),
    ]);

    $provided_key = sanitize_text_field((string) $request->get_param('router_key'));
    $expected_key = anonymous_sales_get_router_auth_key();

    if ($provided_key === '' || !hash_equals($expected_key, $provided_key)) {
        anonymous_sales_log_warning('Router key validation failed.', [
            'route' => (string) $request->get_route(),
            'action' => (string) $action_param,
            'method' => (string) $request->get_method(),
            'ip' => anonymous_sales_get_client_ip(),
            'signature_present' => $signature_header !== '',
            'provided_key_length' => strlen($provided_key),
            'expected_key_length' => strlen($expected_key),
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid router key.',
        ], 403);
    }

    $action = $action_param;

    do_action('anonymous_sales_router_request_received', $action, $request);

    $pre_dispatch = apply_filters('anonymous_sales_router_pre_dispatch', null, $action, $request);
    if ($pre_dispatch instanceof WP_REST_Response) {
        return $pre_dispatch;
    }

    if (anonymous_sales_is_webhook_request($request, $action)) {
        return anonymous_sales_handle_nowpayments_webhook($request);
    }

    switch ($action) {
        case 'create_order':
            return anonymous_sales_handle_create_order($request);

        case 'check_approved_crypto':
            return anonymous_sales_handle_check_approved_crypto($request);

        case 'check_status':
            return anonymous_sales_handle_check_status($request);

        case 'download_pdf':
            return anonymous_sales_handle_download_pdf($request);

        case 'access_resource':
            return anonymous_sales_handle_resource_access($request);

        default:
            anonymous_sales_log_warning('Unsupported router action received.', [
                'action' => $action,
                'method' => (string) $request->get_method(),
            ]);

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unknown action.',
                'supported_actions' => [
                    'create_order',
                    'check_approved_crypto',
                    'check_status',
                    'download_pdf',
                    'access_resource',
                    'webhook',
                ],
            ], 400);
    }
}

function anonymous_sales_is_webhook_request(WP_REST_Request $request, $action) {
    if ($action === 'webhook') {
        return true;
    }

    $signature = $request->get_header('x-nowpayments-sig');

    return $signature !== '' && $signature !== null;
}

function anonymous_sales_get_router_url() {
    $router_key = anonymous_sales_get_router_auth_key();
    return rest_url('anonymous-sales/v1/router/' . rawurlencode($router_key));
}

function anonymous_sales_get_offer_record($offer_id) {
    global $wpdb;

    $schema = anonymous_sales_get_schema_names();

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$schema['offers']} WHERE offer_id = %d",
            $offer_id
        ),
        ARRAY_A
    );
}

function anonymous_sales_get_offer_resources($offer_id) {
    global $wpdb;

    $schema = anonymous_sales_get_schema_names();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$schema['resources']} WHERE resource_offer = %d ORDER BY resource_id ASC",
            $offer_id
        ),
        ARRAY_A
    );

    foreach ($rows as &$row) {
        if (isset($row['resource_password'])) {
            $row['resource_password'] = anonymous_sales_decrypt_secret((string) $row['resource_password']);
        }
    }
    unset($row);

    return $rows;
}

function anonymous_sales_get_order_by_identifiers($order_number, $claim_token = '') {
    global $wpdb;

    $schema = anonymous_sales_get_schema_names();

    if ($claim_token !== '') {
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$schema['orders']} WHERE order_stamp = %s AND order_claimToken = %s LIMIT 1",
                $order_number,
                $claim_token
            ),
            ARRAY_A
        );
    }

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$schema['orders']} WHERE order_stamp = %s LIMIT 1",
            $order_number
        ),
        ARRAY_A
    );
}

function anonymous_sales_get_order_by_payment_id($payment_id) {
    global $wpdb;

    $schema = anonymous_sales_get_schema_names();

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$schema['orders']} WHERE order_paymentId = %s LIMIT 1",
            $payment_id
        ),
        ARRAY_A
    );
}

function anonymous_sales_get_order_by_invoice_id($invoice_id) {
    global $wpdb;

    $invoice_id = trim((string) $invoice_id);
    if ($invoice_id === '') {
        return null;
    }

    $schema = anonymous_sales_get_schema_names();

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$schema['orders']} WHERE order_invoiceId = %s LIMIT 1",
            $invoice_id
        ),
        ARRAY_A
    );
}

function anonymous_sales_normalize_resource_snapshot(array $resources) {
    $snapshot = [];

    foreach ($resources as $resource) {
        $snapshot[] = [
            'id' => (int) $resource['resource_id'],
            'name' => (string) $resource['resource_name'],
            'description' => (string) $resource['resource_description'],
            'size' => $resource['resource_size'] !== null ? (string) $resource['resource_size'] : '',
            // The snapshot is stored for every order, paid or not, so the password stays
            // encrypted here and is decrypted only when the PDF is rendered.
            'password' => '',
            'password_enc' => anonymous_sales_encrypt_secret((string) $resource['resource_password']),
            'address' => (string) $resource['resource_address'],
            'type' => (string) $resource['resource_type'],
            'hash' => (string) ($resource['resource_stamp'] ?? ''),
            'version' => '',
        ];
    }

    return $snapshot;
}

function anonymous_sales_create_order_snapshot(array $offer, array $resources, $currency) {
    $price_usd = anonymous_sales_offer_price_to_usd($offer['offer_price']);

    return wp_json_encode([
        'offer' => [
            'id' => (int) $offer['offer_id'],
            'name' => (string) $offer['offer_name'],
            'description' => (string) $offer['offer_description'],
            'price' => $price_usd,
            'currency' => 'usd',
            'infoFromSeller' => (string) $offer['offer_infoFromSeller'],
            'infoFromSellerPdf' => (string) ($offer['offer_infoFromSeller_pdf'] ?? ''),
            'userLogo' => (string) $offer['offer_userLogo'],
            'offerPage' => (string) ($offer['offer_page'] ?? ''),
            'showContentHeaderOnNewPages' => (int) $offer['offer_showContentHeaderOnNewPages'],
        ],
        'resources' => anonymous_sales_normalize_resource_snapshot($resources),
    ]);
}

function anonymous_sales_create_order_offer_snapshot(array $offer) {
    $price_usd = anonymous_sales_offer_price_to_usd($offer['offer_price']);

    return [
        'id' => (int) $offer['offer_id'],
        'name' => (string) $offer['offer_name'],
        'description' => (string) $offer['offer_description'],
        'price' => $price_usd,
        'currency' => 'usd',
        'infoFromSeller' => (string) $offer['offer_infoFromSeller'],
        'infoFromSellerPdf' => (string) ($offer['offer_infoFromSeller_pdf'] ?? ''),
        'userLogo' => (string) $offer['offer_userLogo'],
        'offerPage' => (string) ($offer['offer_page'] ?? ''),
        'showContentHeaderOnNewPages' => (int) $offer['offer_showContentHeaderOnNewPages'],
        'createdAt' => (string) ($offer['offer_createdAt'] ?? ''),
        'stamp' => (string) ($offer['offer_stamp'] ?? ''),
    ];
}

function anonymous_sales_get_nowpayments_api_key() {
    $schema = anonymous_sales_get_schema_names();
    $stored = (string) get_option($schema['nowpayments_apiKey'], '');

    return anonymous_sales_decrypt_secret($stored);
}

function anonymous_sales_get_nowpayments_webhook_key() {
    $schema = anonymous_sales_get_schema_names();
    $stored = (string) get_option($schema['nowpayments_webhookKey'], '');

    return anonymous_sales_decrypt_secret($stored);
}

function anonymous_sales_get_nowpayments_pay_currency(array $offer, array $order, $price_currency) {
    $default_pay_currency = 'btc';

    $pay_currency = (string) apply_filters(
        'anonymous_sales_nowpayments_pay_currency',
        $default_pay_currency,
        $offer,
        $order,
        $price_currency
    );

    $pay_currency = strtolower(trim($pay_currency));
    $pay_currency = preg_replace('/[^a-z0-9_]/', '', $pay_currency);

    if (!is_string($pay_currency) || $pay_currency === '') {
        return $default_pay_currency;
    }

    return $pay_currency;
}

function anonymous_sales_is_http_url($url) {
    if (!is_string($url) || $url === '') {
        return false;
    }

    return (bool) preg_match('#^https?://#i', $url);
}

function anonymous_sales_build_crypto_payment_uri($pay_currency, $pay_address, array $payment = []) {
    $pay_currency = strtolower(trim((string) $pay_currency));
    $pay_address = trim((string) $pay_address);

    if ($pay_address === '') {
        return '';
    }

    $scheme_map = [
        'btc' => 'bitcoin',
        'ltc' => 'litecoin',
        'doge' => 'dogecoin',
        'bch' => 'bitcoincash',
        'dash' => 'dash',
        'zec' => 'zcash',
        'eth' => 'ethereum',
    ];

    if (!isset($scheme_map[$pay_currency])) {
        return '';
    }

    $uri = $scheme_map[$pay_currency] . ':' . $pay_address;
    $amount = isset($payment['pay_amount']) ? trim((string) $payment['pay_amount']) : '';
    if ($amount !== '' && is_numeric($amount)) {
        $uri .= '?amount=' . rawurlencode($amount);
    }

    return $uri;
}

function anonymous_sales_resolve_payment_url(array $payment) {
    $invoice_url = (string) ($payment['invoice_url'] ?? '');
    if (anonymous_sales_is_http_url($invoice_url)) {
        return $invoice_url;
    }

    $payment_url = (string) ($payment['payment_url'] ?? '');
    if (anonymous_sales_is_http_url($payment_url)) {
        return $payment_url;
    }

    $pay_currency = (string) ($payment['pay_currency'] ?? '');
    $pay_address = (string) ($payment['pay_address'] ?? '');
    return anonymous_sales_build_crypto_payment_uri($pay_currency, $pay_address, $payment);
}

function anonymous_sales_get_nowpayments_checkout_mode(array $offer, array $order, $price_currency) {
    $mode = strtolower(trim((string) apply_filters(
        'anonymous_sales_nowpayments_checkout_mode',
        'invoice',
        $offer,
        $order,
        $price_currency
    )));

    if (!in_array($mode, ['invoice', 'payment'], true)) {
        return 'invoice';
    }

    return $mode;
}

function anonymous_sales_extract_payment_id(array $payment) {
    foreach (['payment_id', 'invoice_id', 'id'] as $key) {
        if (!empty($payment[$key])) {
            return (string) $payment[$key];
        }
    }

    return '';
}
function anonymous_sales_mask_router_key_in_url($url) {
    $url = (string) $url;
    if ($url === '') {
        return '';
    }

    return preg_replace('#(/router/)[A-Za-z0-9_-]+#', '$1***', $url);
}

function anonymous_sales_extract_payment_status(array $payment) {
    foreach (['payment_status', 'invoice_status', 'status'] as $key) {
        if (!empty($payment[$key])) {
            return (string) $payment[$key];
        }
    }

    return 'pending';
}

function anonymous_sales_extract_pay_currency(array $payment, $fallback_currency = '') {
    if (!empty($payment['pay_currency'])) {
        return (string) $payment['pay_currency'];
    }

    if (!empty($payment['price_currency'])) {
        return (string) $payment['price_currency'];
    }

    return (string) $fallback_currency;
}

function anonymous_sales_extract_pay_amount(array $payment, $fallback_amount = '') {
    if (!empty($payment['pay_amount'])) {
        return (string) $payment['pay_amount'];
    }

    if (!empty($payment['price_amount'])) {
        return (string) $payment['price_amount'];
    }

    return (string) $fallback_amount;
}

function anonymous_sales_create_nowpayments_payment(array $offer, array $order, $currency) {
    $api_key = anonymous_sales_get_nowpayments_api_key();
    $checkout_mode = anonymous_sales_get_nowpayments_checkout_mode($offer, $order, $currency);
    $pay_currency = '';

    if ($api_key === '') {
        anonymous_sales_log_error('NOWPayments API key is not configured for payment initialization.', [
            'order_number' => (string) ($order['order_stamp'] ?? ''),
        ]);

        return new WP_Error(
            'anonymous_sales_nowpayments_not_configured',
            'NOWPayments API key is not configured.',
            ['status' => 500]
        );
    }

    $payload = [
        'price_amount' => anonymous_sales_offer_price_to_usd($offer['offer_price']),
        'price_currency' => strtolower((string) $currency),
        'order_id' => (string) $order['order_stamp'],
        'order_description' => sprintf('Anonymous Sales order %s', $order['order_stamp']),
        // Back to the page the order came from, with only the order number: the claim
        // token must not reach NOWPayments (their logs and dashboard would hold it).
        'success_url' => add_query_arg(
            'anonymous_sales_order',
            (string) $order['order_stamp'],
            (string) ($order['order_offerPage'] ?: home_url('/'))
        ),
        'is_fixed_rate' => true,
        'is_fee_paid_by_user' => false,
    ];

    $callback_sync_enabled = anonymous_sales_should_send_callback_each_request();
    if ($callback_sync_enabled) {
        $payload['ipn_callback_url'] = add_query_arg('action', 'webhook', anonymous_sales_get_router_url());
    }

    if ($checkout_mode === 'payment') {
        $pay_currency = anonymous_sales_get_nowpayments_pay_currency($offer, $order, $currency);
        $payload['pay_currency'] = $pay_currency;
        $payload = apply_filters('anonymous_sales_nowpayments_payment_payload', $payload, $offer, $order, $currency);
        $endpoint = apply_filters('anonymous_sales_nowpayments_payment_endpoint', 'https://api.nowpayments.io/v1/payment', $offer, $order, $currency);
    } else {
        $payload = apply_filters('anonymous_sales_nowpayments_invoice_payload', $payload, $offer, $order, $currency);
        $payload = apply_filters('anonymous_sales_nowpayments_payment_payload', $payload, $offer, $order, $currency);
        $endpoint = apply_filters('anonymous_sales_nowpayments_invoice_endpoint', 'https://api.nowpayments.io/v1/invoice', $offer, $order, $currency);
    }

    $request_args = [
        'timeout' => 20,
        'headers' => [
            'x-api-key' => $api_key,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ];
    $request_args = apply_filters('anonymous_sales_nowpayments_request_args', $request_args, $offer, $order, $currency, $payload);

    anonymous_sales_log_info('Creating NOWPayments payment.', [
        'order_number' => (string) ($order['order_stamp'] ?? ''),
        'endpoint' => (string) $endpoint,
        'checkout_mode' => (string) $checkout_mode,
        'currency' => (string) $currency,
        'pay_currency' => (string) $pay_currency,
        'callback_sync_enabled' => (bool) $callback_sync_enabled,
        'callback_url_sent' => isset($payload['ipn_callback_url']),
        'callback_url_masked' => isset($payload['ipn_callback_url'])
            ? anonymous_sales_mask_router_key_in_url((string) $payload['ipn_callback_url'])
            : '',
    ]);

    $response = wp_remote_post($endpoint, $request_args);

    if (is_wp_error($response)) {
        anonymous_sales_log_error('NOWPayments request returned WP_Error.', [
            'order_number' => (string) ($order['order_stamp'] ?? ''),
            'error_code' => (string) $response->get_error_code(),
            'error_message' => (string) $response->get_error_message(),
        ]);

        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code < 200 || $status_code >= 300 || !is_array($body)) {
        anonymous_sales_log_error('NOWPayments payment creation failed with non-success response.', [
            'order_number' => (string) ($order['order_stamp'] ?? ''),
            'checkout_mode' => (string) $checkout_mode,
            'status_code' => (int) $status_code,
            'response_body' => wp_remote_retrieve_body($response),
        ]);

        return new WP_Error(
            'anonymous_sales_nowpayments_request_failed',
            'NOWPayments payment creation failed.',
            [
                'status' => 502,
                'response_code' => $status_code,
                'response_body' => wp_remote_retrieve_body($response),
            ]
        );
    }

    $body['_anonymous_sales_checkout_mode'] = $checkout_mode;

    return $body;
}

function anonymous_sales_extract_nowpayments_coins($decoded_body) {
    if (!is_array($decoded_body)) {
        return [];
    }

    $candidates = [];
    $priority_keys = [
        'selectedCurrencies',
        'currencies',
        'coins',
        'data',
    ];

    foreach ($priority_keys as $key) {
        if (isset($decoded_body[$key]) && is_array($decoded_body[$key])) {
            $candidates = $decoded_body[$key];
            break;
        }
    }

    if ($candidates === []) {
        $has_string_item = false;
        foreach ($decoded_body as $item) {
            if (is_string($item)) {
                $has_string_item = true;
                break;
            }
        }
        if ($has_string_item) {
            $candidates = $decoded_body;
        }
    }

    $coins = [];
    foreach ($candidates as $item) {
        $coin = '';

        if (is_string($item)) {
            $coin = $item;
        } elseif (is_array($item)) {
            if (isset($item['code']) && is_string($item['code'])) {
                $coin = $item['code'];
            } elseif (isset($item['currency']) && is_string($item['currency'])) {
                $coin = $item['currency'];
            } elseif (isset($item['ticker']) && is_string($item['ticker'])) {
                $coin = $item['ticker'];
            }
        }

        $coin = strtolower(trim((string) $coin));
        $coin = preg_replace('/[^a-z0-9_]/', '', $coin);
        if ($coin !== '') {
            $coins[$coin] = true;
        }
    }

    return array_keys($coins);
}

function anonymous_sales_check_approved_crypto() {
    $api_key = anonymous_sales_get_nowpayments_api_key();
    if ($api_key === '') {
        return new WP_Error(
            'anonymous_sales_nowpayments_not_configured',
            'NOWPayments API key is not configured.',
            ['status' => 500]
        );
    }

    $endpoint = apply_filters(
        'anonymous_sales_nowpayments_merchant_coins_endpoint',
        'https://api.nowpayments.io/v1/merchant/coins'
    );

    $request_args = [
        'timeout' => 20,
        'headers' => [
            'x-api-key' => $api_key,
            'Accept' => 'application/json',
        ],
    ];

    $request_args = apply_filters('anonymous_sales_nowpayments_merchant_coins_request_args', $request_args, $endpoint);

    anonymous_sales_log_info('Checking approved NOWPayments crypto list.', [
        'endpoint' => (string) $endpoint,
    ]);

    $response = wp_remote_get($endpoint, $request_args);
    if (is_wp_error($response)) {
        anonymous_sales_log_error('Approved crypto request returned WP_Error.', [
            'error_code' => (string) $response->get_error_code(),
            'error_message' => (string) $response->get_error_message(),
        ]);

        return new WP_Error(
            'anonymous_sales_nowpayments_merchant_coins_failed',
            'Could not fetch approved cryptocurrencies from NOWPayments.',
            ['status' => 502]
        );
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $decoded_body = json_decode($raw_body, true);

    if ($status_code < 200 || $status_code >= 300 || !is_array($decoded_body)) {
        anonymous_sales_log_error('Approved crypto request failed with non-success response.', [
            'status_code' => $status_code,
            'response_body' => $raw_body,
        ]);

        return new WP_Error(
            'anonymous_sales_nowpayments_merchant_coins_failed',
            'NOWPayments merchant coins request failed.',
            [
                'status' => 502,
                'response_code' => $status_code,
            ]
        );
    }

    $coins = anonymous_sales_extract_nowpayments_coins($decoded_body);

    $result = [
        'approved_coins' => $coins,
        'count' => count($coins),
        'raw' => $decoded_body,
    ];

    return apply_filters('anonymous_sales_approved_crypto_result', $result, $decoded_body, $response);
}

function anonymous_sales_is_truthy($value) {
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function anonymous_sales_get_minimum_payment_amount($currency_from, $currency_to, $fiat_equivalent = 'usd', $is_fixed_rate = false) {
    $api_key = anonymous_sales_get_nowpayments_api_key();
    if ($api_key === '') {
        return new WP_Error(
            'anonymous_sales_nowpayments_not_configured',
            'NOWPayments API key is not configured.',
            ['status' => 500]
        );
    }

    $currency_from = strtolower(trim((string) $currency_from));
    $currency_to = strtolower(trim((string) $currency_to));
    $fiat_equivalent = strtolower(trim((string) $fiat_equivalent));

    if ($currency_from === '' || $currency_to === '') {
        return new WP_Error(
            'anonymous_sales_nowpayments_min_amount_invalid_input',
            'currency_from and currency_to are required for minimum amount check.',
            ['status' => 400]
        );
    }

    $endpoint = apply_filters(
        'anonymous_sales_nowpayments_min_amount_endpoint',
        'https://api.nowpayments.io/v1/min-amount'
    );

    $query_args = [
        'currency_from' => $currency_from,
        'currency_to' => $currency_to,
        'is_fixed_rate' => $is_fixed_rate ? 'true' : 'false',
    ];
    if ($fiat_equivalent !== '') {
        $query_args['fiat_equivalent'] = $fiat_equivalent;
    }

    $request_url = add_query_arg($query_args, $endpoint);
    $request_args = [
        'timeout' => 20,
        'headers' => [
            'x-api-key' => $api_key,
            'Accept' => 'application/json',
        ],
    ];

    $request_args = apply_filters('anonymous_sales_nowpayments_min_amount_request_args', $request_args, $request_url, $query_args);

    $response = wp_remote_get($request_url, $request_args);
    if (is_wp_error($response)) {
        return new WP_Error(
            'anonymous_sales_nowpayments_min_amount_failed',
            'Could not fetch minimum payment amount from NOWPayments.',
            ['status' => 502]
        );
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $decoded_body = json_decode($raw_body, true);

    if ($status_code < 200 || $status_code >= 300 || !is_array($decoded_body)) {
        return new WP_Error(
            'anonymous_sales_nowpayments_min_amount_failed',
            'NOWPayments minimum amount request failed.',
            [
                'status' => 502,
                'response_code' => $status_code,
            ]
        );
    }

    $result = [
        'currency_from' => $currency_from,
        'currency_to' => $currency_to,
        'fiat_equivalent_currency' => $fiat_equivalent,
        'is_fixed_rate' => (bool) $is_fixed_rate,
        'min_amount' => isset($decoded_body['min_amount']) ? (float) $decoded_body['min_amount'] : null,
        'fiat_equivalent' => isset($decoded_body['fiat_equivalent']) ? (float) $decoded_body['fiat_equivalent'] : null,
        'raw' => $decoded_body,
    ];

    return apply_filters('anonymous_sales_nowpayments_min_amount_result', $result, $decoded_body, $response, $query_args);
}

function anonymous_sales_analyze_approved_crypto_minimums(array $approved_coins, $price_amount, $price_currency, $is_fixed_rate = false) {
    $price_amount = (float) $price_amount;
    $price_currency = strtolower(trim((string) $price_currency));
    if ($price_currency === '') {
        $price_currency = 'usd';
    }

    $max_coins = (int) apply_filters('anonymous_sales_min_amount_max_coins_per_check', 20);
    if ($max_coins <= 0) {
        $max_coins = 20;
    }

    $analysis = [];
    $eligible = [];
    $ineligible = [];
    $coins_to_check = array_slice(array_values($approved_coins), 0, $max_coins);

    foreach ($coins_to_check as $coin) {
        $coin = strtolower(trim((string) $coin));
        if ($coin === '') {
            continue;
        }

        $minimum = anonymous_sales_get_minimum_payment_amount($coin, $coin, $price_currency, $is_fixed_rate);

        if (is_wp_error($minimum)) {
            $analysis[$coin] = [
                'ok' => false,
                'error' => $minimum->get_error_message(),
            ];
            continue;
        }

        $min_fiat = isset($minimum['fiat_equivalent']) && is_numeric($minimum['fiat_equivalent'])
            ? (float) $minimum['fiat_equivalent']
            : null;

        $is_eligible = $min_fiat === null ? true : ($price_amount >= $min_fiat);

        if ($is_eligible) {
            $eligible[] = $coin;
        } else {
            $ineligible[] = $coin;
        }

        $analysis[$coin] = [
            'ok' => true,
            'is_eligible' => $is_eligible,
            'min_amount' => $minimum['min_amount'],
            'fiat_equivalent' => $min_fiat,
            'fiat_currency' => $price_currency,
        ];
    }

    return [
        'price_amount' => $price_amount,
        'price_currency' => $price_currency,
        'is_fixed_rate' => (bool) $is_fixed_rate,
        'checked_count' => count($analysis),
        'truncated' => count($approved_coins) > count($coins_to_check),
        'eligible_coins' => $eligible,
        'ineligible_coins' => $ineligible,
        'minimums' => $analysis,
    ];
}

function anonymous_sales_handle_check_approved_crypto(WP_REST_Request $request) {
    $result = anonymous_sales_check_approved_crypto();
    if (is_wp_error($result)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => $result->get_error_message(),
            'approved_coins' => [],
        ], (int) ($result->get_error_data()['status'] ?? 500));
    }

    $response_data = [
        'success' => true,
        'approved_coins' => array_values((array) ($result['approved_coins'] ?? [])),
        'count' => (int) ($result['count'] ?? 0),
        'checked_at' => gmdate('c'),
    ];

    $include_minimums = anonymous_sales_is_truthy($request->get_param('include_minimums'));
    if ($include_minimums) {
        $price_currency = sanitize_key((string) $request->get_param('price_currency'));
        if ($price_currency === '') {
            $price_currency = 'usd';
        }

        $price_amount = (float) $request->get_param('price_amount');
        $offer_id = absint($request->get_param('offer_id'));
        if ($offer_id > 0) {
            $offer = anonymous_sales_get_offer_record($offer_id);
            if (is_array($offer) && isset($offer['offer_price'])) {
                $price_amount = (float) anonymous_sales_offer_price_to_usd($offer['offer_price']);
                $price_currency = 'usd';
            }
        }

        if ($price_amount > 0) {
            $is_fixed_rate = anonymous_sales_is_truthy($request->get_param('is_fixed_rate'));
            $minimums = anonymous_sales_analyze_approved_crypto_minimums(
                (array) ($result['approved_coins'] ?? []),
                $price_amount,
                $price_currency,
                $is_fixed_rate
            );

            $response_data['minimum_analysis'] = $minimums;
            $response_data['eligible_coins'] = $minimums['eligible_coins'];
            $response_data['ineligible_coins'] = $minimums['ineligible_coins'];
        } else {
            $response_data['minimum_analysis'] = [
                'warning' => 'Minimum check requested but no valid offer amount was provided.',
            ];
        }
    }

    $response_data = apply_filters('anonymous_sales_check_approved_crypto_response_data', $response_data, $result, $request);

    return new WP_REST_Response($response_data, 200);
}

function anonymous_sales_handle_create_order(WP_REST_Request $request) {
    global $wpdb;

    $offer_id = absint($request->get_param('offer_id'));
    $currency = 'usd';

    anonymous_sales_log_info('Create order request received.', [
        'offer_id' => (int) $offer_id,
    ]);

    if ($offer_id <= 0) {
        anonymous_sales_log_warning('Create order rejected due to invalid offer ID.');

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing or invalid offer ID.',
        ], 400);
    }

    // Every order costs a database row and a NOWPayments invoice, and the router key is
    // public, so creation is limited per IP: a short burst limit and an hourly one.
    $burst_limit = max(1, (int) apply_filters('anonymous_sales_create_order_burst_limit', 5));
    $hourly_limit = max(1, (int) apply_filters('anonymous_sales_create_order_hourly_limit', 20));
    $rate_limit_error = anonymous_sales_enforce_rate_limit('create', '', '', $burst_limit, MINUTE_IN_SECONDS)
        ?: anonymous_sales_enforce_rate_limit('create_hour', '', '', $hourly_limit, HOUR_IN_SECONDS);
    if ($rate_limit_error instanceof WP_REST_Response) {
        return $rate_limit_error;
    }

    $offer = anonymous_sales_get_offer_record($offer_id);
    if (!$offer) {
        anonymous_sales_log_warning('Create order failed because offer was not found.', [
            'offer_id' => (int) $offer_id,
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Offer not found.',
        ], 404);
    }

    $resources = anonymous_sales_get_offer_resources($offer_id);
    $price_usd = anonymous_sales_offer_price_to_usd($offer['offer_price']);
    $order_number = strtoupper(anonymous_sales_generate_random_string(20));
    $claim_token = anonymous_sales_generate_random_string(48);
    $offer_page = esc_url_raw((string) $request->get_param('offer_page'));
    if ($offer_page === '') {
        $offer_page = home_url('/');
    }

    $offer_page = (string) apply_filters('anonymous_sales_create_order_offer_page', $offer_page, $offer, $request);

    $offer['offer_page'] = $offer_page;
    $snapshot = anonymous_sales_create_order_snapshot($offer, $resources, $currency);
    $offer_snapshot_json = wp_json_encode(anonymous_sales_create_order_offer_snapshot($offer));
    $item_list = wp_json_encode(anonymous_sales_normalize_resource_snapshot($resources));
    $schema = anonymous_sales_get_schema_names();

    $order_insert_data = [
        'order_relatedOffer' => (int) $offer['offer_id'],
        'order_currentStatus' => 'created',
        'order_stamp' => $order_number,
        'order_claimToken' => $claim_token,
        'order_offerPage' => $offer_page,
        'order_basicPrice' => $price_usd,
        'order_priceCurrency' => $currency,
        'order_fileList' => $snapshot,
        'order_itemList' => $item_list,
        'order_offer_snapshot' => $offer_snapshot_json,
        'order_finalPrice' => $price_usd,
    ];

    $order_insert_data = apply_filters('anonymous_sales_create_order_insert_data', $order_insert_data, $offer, $resources, $request);

    $inserted = $wpdb->insert(
        $schema['orders'],
        $order_insert_data,
        ['%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%f']
    );

    if (!$inserted) {
        anonymous_sales_error_log('Create order insert failed: ' . $wpdb->last_error);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Could not create order.',
        ], 500);
    }

    $order = anonymous_sales_get_order_by_identifiers($order_number, $claim_token);
    anonymous_sales_log_info('Order row created.', [
        'order_number' => (string) $order_number,
        'offer_id' => (int) $offer['offer_id'],
    ]);

    do_action('anonymous_sales_order_created', $order, $offer, $resources, $request);

    $payment = anonymous_sales_create_nowpayments_payment($offer, $order, $currency);

    if (is_wp_error($payment)) {
        anonymous_sales_log_error('Payment initialization failed after order creation.', [
            'order_number' => (string) $order_number,
            'error_message' => (string) $payment->get_error_message(),
        ]);

        do_action('anonymous_sales_order_payment_failed', $order, $payment, $offer, $request);

        $wpdb->update(
            $schema['orders'],
            ['order_currentStatus' => 'payment_configuration_error'],
            ['order_id' => (int) $order['order_id']],
            ['%s'],
            ['%d']
        );

        return new WP_REST_Response([
            'success' => false,
            'message' => $payment->get_error_message(),
            'order_number' => $order_number,
            'claim_token' => $claim_token,
        ], (int) ($payment->get_error_data()['status'] ?? 500));
    }

    do_action('anonymous_sales_order_payment_initialized', $order, $payment, $offer, $request);

    $resolved_payment_url = anonymous_sales_resolve_payment_url($payment);
    $payment_address = (string) ($payment['pay_address'] ?? '');
    $checkout_mode = (string) ($payment['_anonymous_sales_checkout_mode'] ?? '');
    // In invoice mode NOWPayments returns the invoice id, not a payment id. A payment id
    // only exists once the buyer opens the invoice and picks a coin; the webhook or the
    // payment list lookup fills it in later.
    $invoice_id = $checkout_mode === 'invoice' ? anonymous_sales_extract_payment_id($payment) : (string) ($payment['invoice_id'] ?? '');
    $payment_id = $checkout_mode === 'invoice' ? '' : anonymous_sales_extract_payment_id($payment);
    $payment_status = anonymous_sales_extract_payment_status($payment);
    $payment_currency = anonymous_sales_extract_pay_currency($payment, $currency);
    $payment_amount = anonymous_sales_extract_pay_amount($payment, $price_usd);

    anonymous_sales_log_info('Payment initialized successfully.', [
        'order_number' => (string) $order_number,
        'payment_id' => (string) $payment_id,
        'invoice_id' => (string) $invoice_id,
        'payment_status' => (string) $payment_status,
        'checkout_mode' => $checkout_mode,
        'payment_url_type' => anonymous_sales_is_http_url($resolved_payment_url) ? 'http' : ($resolved_payment_url !== '' ? 'wallet_uri' : 'none'),
    ]);

    if ($resolved_payment_url === '' && $payment_address !== '') {
        anonymous_sales_log_warning('No navigable payment URL returned by NOWPayments. Returning address-only payment details.', [
            'order_number' => (string) $order_number,
            'payment_id' => (string) ($payment['payment_id'] ?? ''),
            'pay_currency' => (string) ($payment['pay_currency'] ?? ''),
        ]);
    }

    $wpdb->update(
        $schema['orders'],
        [
            'order_currentStatus' => sanitize_text_field((string) $payment_status),
            'order_paymentId' => sanitize_text_field((string) $payment_id),
            'order_invoiceId' => sanitize_text_field((string) $invoice_id),
            'order_paymentUrl' => esc_url_raw((string) $resolved_payment_url),
        ],
        ['order_id' => (int) $order['order_id']],
        ['%s', '%s', '%s', '%s'],
        ['%d']
    );

    $response_data = [
        'success' => true,
        'message' => 'Order created.',
        'order_number' => $order_number,
        'claim_token' => $claim_token,
        'status' => (string) $payment_status,
        'payment_url' => (string) $resolved_payment_url,
        'payment_address' => (string) $payment_address,
        'payment_currency' => (string) $payment_currency,
        'payment_amount' => (string) $payment_amount,
        'payment_id' => (string) $payment_id,
        'invoice_id' => (string) $invoice_id,
        'checkout_mode' => $checkout_mode,
        'offer' => [
            'id' => (int) $offer['offer_id'],
            'name' => (string) $offer['offer_name'],
            'price' => $price_usd,
            'currency' => $currency,
        ],
    ];

    $response_data = apply_filters('anonymous_sales_create_order_response_data', $response_data, $order, $offer, $resources, $payment, $request);
    do_action('anonymous_sales_create_order_response_ready', $response_data, $order, $offer, $resources, $payment, $request);

    return new WP_REST_Response($response_data, 201);
}

function anonymous_sales_handle_check_status(WP_REST_Request $request) {
    $order_number = sanitize_text_field((string) $request->get_param('order_number'));
    $claim_token = sanitize_text_field((string) $request->get_param('claim_token'));

    if ($order_number === '' || $claim_token === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order number and claim token are required.',
        ], 400);
    }

    $rate_limit_error = anonymous_sales_enforce_rate_limit('status', $order_number, $claim_token, 30, MINUTE_IN_SECONDS);
    if ($rate_limit_error instanceof WP_REST_Response) {
        return $rate_limit_error;
    }

    $order = anonymous_sales_get_order_by_identifiers($order_number, $claim_token);
    if (!$order) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order not found.',
        ], 404);
    }

    // ─── C: Fallback — poll NOWPayments API when status is non-terminal ──────
    $non_terminal_statuses = ['created', 'pending', 'partially_paid', 'processing', 'payment_configuration_error'];
    $has_reference = (string) $order['order_paymentId'] !== '' || (string) ($order['order_invoiceId'] ?? '') !== '';

    if (in_array((string) $order['order_currentStatus'], $non_terminal_statuses, true) && $has_reference) {
        $fetched = anonymous_sales_fetch_payment_for_order($order);
        if (!is_wp_error($fetched)) {
            anonymous_sales_apply_fetched_payment_status($order, $fetched);
            // Reload order after potential update
            $order = anonymous_sales_get_order_by_identifiers($order_number, $claim_token) ?: $order;
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    $response_data = [
        'success' => true,
        'order_number' => (string) $order['order_stamp'],
        'status' => (string) $order['order_currentStatus'],
        'payment_url' => (string) $order['order_paymentUrl'],
        'is_paid' => in_array((string) $order['order_currentStatus'], ['finished', 'confirmed', 'paid'], true),
        'download_ready' => in_array((string) $order['order_currentStatus'], ['finished', 'confirmed', 'paid'], true),
    ];

    $response_data = apply_filters('anonymous_sales_check_status_response_data', $response_data, $order, $request);
    do_action('anonymous_sales_check_status_response_ready', $response_data, $order, $request);

    return new WP_REST_Response($response_data, 200);
}

function anonymous_sales_decode_order_snapshot(array $order) {
    $snapshot = json_decode((string) $order['order_fileList'], true);

    return is_array($snapshot) ? $snapshot : ['offer' => [], 'resources' => []];
}

/**
 * Client IP for rate limiting. X-Forwarded-For is not trusted: anyone can send it and
 * would get a fresh limit per request. Sites behind a proxy or Cloudflare can return
 * the real address through the filter (e.g. from CF-Connecting-IP).
 */
function anonymous_sales_get_client_ip() {
    $raw = !empty($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']))) : '';
    $raw = (string) apply_filters('anonymous_sales_client_ip', $raw);

    return sanitize_text_field($raw);
}

function anonymous_sales_enforce_rate_limit($scope, $order_number, $claim_token, $max_requests, $window_seconds) {
    $scope = sanitize_key((string) $scope);
    $order_number = sanitize_text_field((string) $order_number);
    $claim_token = sanitize_text_field((string) $claim_token);
    $max_requests = max(1, (int) $max_requests);
    $window_seconds = max(10, (int) $window_seconds);

    $subject = implode('|', [
        $scope,
        $order_number,
        $claim_token,
        anonymous_sales_get_client_ip(),
    ]);
    $cache_key = 'anonymous_sales_rl_' . md5($subject);

    $payload = get_transient($cache_key);
    if (!is_array($payload) || !isset($payload['count'], $payload['started_at'])) {
        set_transient($cache_key, [
            'count' => 1,
            'started_at' => time(),
        ], $window_seconds);
        return null;
    }

    $count = (int) $payload['count'];
    $started_at = (int) $payload['started_at'];
    $elapsed = time() - $started_at;

    if ($elapsed >= $window_seconds) {
        set_transient($cache_key, [
            'count' => 1,
            'started_at' => time(),
        ], $window_seconds);
        return null;
    }

    if ($count >= $max_requests) {
        $retry_after = max(1, $window_seconds - $elapsed);

        anonymous_sales_log_warning('Rate limit exceeded.', [
            'scope' => (string) $scope,
            'order_number' => (string) $order_number,
            'retry_after' => (int) $retry_after,
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $retry_after,
        ], 429);
    }

    $payload['count'] = $count + 1;
    set_transient($cache_key, $payload, max(1, $window_seconds - $elapsed));

    return null;
}

function anonymous_sales_get_resource_signature_secret() {
    $router_key = anonymous_sales_get_router_auth_key();
    $salt = function_exists('wp_salt') ? wp_salt('nonce') : ABSPATH;

    return hash('sha256', $router_key . '|' . $salt);
}

function anonymous_sales_build_resource_signature($order_number, $claim_token, $resource_id, $expires) {
    $payload = implode('|', [
        sanitize_text_field((string) $order_number),
        sanitize_text_field((string) $claim_token),
        (int) $resource_id,
        (int) $expires,
    ]);

    return hash_hmac('sha256', $payload, anonymous_sales_get_resource_signature_secret());
}

function anonymous_sales_get_signed_resource_url($order_number, $claim_token, $resource_id, $expires) {
    $signature = anonymous_sales_build_resource_signature($order_number, $claim_token, $resource_id, $expires);

    return add_query_arg([
        'action' => 'access_resource',
        'order_number' => (string) $order_number,
        'claim_token' => (string) $claim_token,
        'resource_id' => (int) $resource_id,
        'expires' => (int) $expires,
        'sig' => $signature,
    ], anonymous_sales_get_router_url());
}

function anonymous_sales_get_resource_snapshot_by_id(array $order, $resource_id) {
    $resource_id = (int) $resource_id;
    if ($resource_id <= 0) {
        return null;
    }

    $snapshot = anonymous_sales_decode_order_snapshot($order);
    $resources = isset($snapshot['resources']) && is_array($snapshot['resources']) ? $snapshot['resources'] : [];

    foreach ($resources as $resource) {
        if (!is_array($resource)) {
            continue;
        }

        if ((int) ($resource['id'] ?? 0) === $resource_id) {
            return $resource;
        }
    }

    return null;
}

function anonymous_sales_build_pdf_payload(array $order) {
    $snapshot = anonymous_sales_decode_order_snapshot($order);
    $offer = isset($snapshot['offer']) && is_array($snapshot['offer']) ? $snapshot['offer'] : [];
    $resources = isset($snapshot['resources']) && is_array($snapshot['resources']) ? $snapshot['resources'] : [];
    $resource_link_ttl = (int) apply_filters('anonymous_sales_resource_link_ttl', DAY_IN_SECONDS);
    if ($resource_link_ttl <= 0) {
        $resource_link_ttl = DAY_IN_SECONDS;
    }
    $resource_link_expires_at = time() + $resource_link_ttl;

    $resources_for_pdf = [];
    foreach ($resources as $resource) {
        if (!is_array($resource)) {
            continue;
        }

        $resource_id = (int) ($resource['id'] ?? 0);
        $resource_address = (string) ($resource['address'] ?? '');
        if ($resource_id > 0 && filter_var($resource_address, FILTER_VALIDATE_URL)) {
            $resource['address'] = anonymous_sales_get_signed_resource_url(
                (string) $order['order_stamp'],
                (string) $order['order_claimToken'],
                $resource_id,
                $resource_link_expires_at
            );
        }

        // Snapshots written before 1.3 carry the password in plain text.
        if (!empty($resource['password_enc'])) {
            $resource['password'] = anonymous_sales_decrypt_secret((string) $resource['password_enc']);
        }
        unset($resource['password_enc']);

        $resource['description'] = anonymous_sales_markdown_to_text((string) ($resource['description'] ?? ''));
        $resources_for_pdf[] = $resource;
    }

    $payload = [
        'websiteTitle' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
        'websiteAddress' => home_url('/'),
        'offerTitle' => (string) ($offer['name'] ?? 'Offer'),
        'offerAddress' => (string) ($order['order_offerPage'] ?? ($offer['offerPage'] ?? home_url('/'))),
        'offerPrice' => sprintf('%s %s', (string) ($offer['price'] ?? $order['order_finalPrice']), strtoupper((string) ($offer['currency'] ?? $order['order_priceCurrency']))),
        'orderNumber' => (string) $order['order_stamp'],
        'claimToken' => (string) $order['order_claimToken'],
        'orderDate' => (string) $order['order_createdAt'],
        'userLogo' => (string) ($offer['userLogo'] ?? ''),
        'showContentHeaderOnNewPages' => !empty($offer['showContentHeaderOnNewPages']),
        'assetList' => $resources_for_pdf,
        'infoFromSeller' => [
            [
                'infoFromSellerTitle' => 'Seller information',
                'infoFromSellerHeader' => 'Additional information',
                'infoFromSellerValue' => anonymous_sales_markdown_to_text((string) ($offer['infoFromSellerPdf'] ?? $offer['infoFromSeller'] ?? '')),
                'infoFromSellerPic' => '',
            ],
        ],
    ];

    return apply_filters('anonymous_sales_pdf_payload', $payload, $order, $snapshot);
}

function anonymous_sales_handle_download_pdf(WP_REST_Request $request) {
    $order_number = sanitize_text_field((string) $request->get_param('order_number'));
    $claim_token = sanitize_text_field((string) $request->get_param('claim_token'));
    $force_download = in_array(strtolower((string) $request->get_param('download')), ['1', 'true', 'yes', 'on'], true);

    if ($order_number === '' || $claim_token === '') {
        anonymous_sales_log_warning('Download PDF rejected due to missing credentials.');

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order number and claim token are required.',
        ], 400);
    }

    $rate_limit_error = anonymous_sales_enforce_rate_limit('download', $order_number, $claim_token, 8, MINUTE_IN_SECONDS);
    if ($rate_limit_error instanceof WP_REST_Response) {
        return $rate_limit_error;
    }

    $order = anonymous_sales_get_order_by_identifiers($order_number, $claim_token);
    if (!$order) {
        anonymous_sales_log_warning('Download PDF failed because order was not found.', [
            'order_number' => (string) $order_number,
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order not found.',
        ], 404);
    }

    if (!in_array((string) $order['order_currentStatus'], ['finished', 'confirmed', 'paid'], true)) {
        anonymous_sales_log_warning('Download PDF blocked because order is not paid.', [
            'order_number' => (string) $order_number,
            'status' => (string) $order['order_currentStatus'],
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order is not paid yet.',
        ], 409);
    }

    $pdf = anonymous_sales_renderPasswordSheet(anonymous_sales_build_pdf_payload($order));
    if (!is_array($pdf) || empty($pdf['filename']) || empty($pdf['content'])) {
        anonymous_sales_log_error('PDF rendering failed before stream.', [
            'order_number' => (string) $order_number,
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'PDF generation failed.',
        ], 500);
    }

    nocache_headers();
    header('Content-Type: application/pdf');
    $content_disposition = $force_download ? 'attachment' : 'inline';
    $content_disposition = apply_filters('anonymous_sales_download_pdf_disposition', $content_disposition, $order, $request);
    if (!in_array($content_disposition, ['attachment', 'inline'], true)) {
        $content_disposition = 'inline';
    }

    $filename = (string) apply_filters('anonymous_sales_download_pdf_filename', basename((string) $pdf['filename']), $order, $request, $force_download);
    do_action('anonymous_sales_before_pdf_stream', $order, $filename, $request, $pdf);

    header('Content-Disposition: ' . $content_disposition . '; filename="' . basename($filename) . '"');
    header('Content-Length: ' . (string) strlen((string) $pdf['content']));
    echo (string) $pdf['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF document, escaping would corrupt it.
    exit;
}

function anonymous_sales_handle_resource_access(WP_REST_Request $request) {
    $order_number = sanitize_text_field((string) $request->get_param('order_number'));
    $claim_token = sanitize_text_field((string) $request->get_param('claim_token'));
    $resource_id = (int) $request->get_param('resource_id');
    $expires = (int) $request->get_param('expires');
    $signature = sanitize_text_field((string) $request->get_param('sig'));

    if ($order_number === '' || $claim_token === '' || $resource_id <= 0 || $expires <= 0 || $signature === '') {
        anonymous_sales_log_warning('Resource access rejected due to invalid signed URL params.');

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing or invalid signed URL parameters.',
        ], 400);
    }

    $rate_limit_error = anonymous_sales_enforce_rate_limit('resource', $order_number, $claim_token, 25, MINUTE_IN_SECONDS);
    if ($rate_limit_error instanceof WP_REST_Response) {
        return $rate_limit_error;
    }

    if (time() > $expires) {
        anonymous_sales_log_warning('Resource link expired.', [
            'order_number' => (string) $order_number,
            'resource_id' => (int) $resource_id,
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Resource link expired.',
        ], 410);
    }

    $expected_signature = anonymous_sales_build_resource_signature($order_number, $claim_token, $resource_id, $expires);
    if (!hash_equals($expected_signature, $signature)) {
        anonymous_sales_log_warning('Resource signature validation failed.', [
            'order_number' => (string) $order_number,
            'resource_id' => (int) $resource_id,
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid resource signature.',
        ], 403);
    }

    $order = anonymous_sales_get_order_by_identifiers($order_number, $claim_token);
    if (!$order) {
        anonymous_sales_log_warning('Resource access failed because order was not found.', [
            'order_number' => (string) $order_number,
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order not found.',
        ], 404);
    }

    if (!in_array((string) $order['order_currentStatus'], ['finished', 'confirmed', 'paid'], true)) {
        anonymous_sales_log_warning('Resource access blocked because order is not paid.', [
            'order_number' => (string) $order_number,
            'status' => (string) $order['order_currentStatus'],
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order is not paid yet.',
        ], 409);
    }

    $resource = anonymous_sales_get_resource_snapshot_by_id($order, $resource_id);
    $target_url = is_array($resource) ? (string) ($resource['address'] ?? '') : '';
    $target_url = (string) apply_filters('anonymous_sales_resource_redirect_url', $target_url, $resource, $order, $request);

    if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
        anonymous_sales_log_warning('Resource redirect URL is missing or invalid.', [
            'order_number' => (string) $order_number,
            'resource_id' => (int) $resource_id,
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Resource URL not available.',
        ], 404);
    }

    if (!in_array(wp_parse_url((string) $target_url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Resource URL not available.',
        ], 404);
    }

    do_action('anonymous_sales_before_resource_redirect', $target_url, $resource, $order, $request);

    // The target is the file or page the seller configured for the paid resource: an external host by design.
    wp_redirect($target_url, 302, 'Anonymous Sales'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
    exit;
}

function anonymous_sales_ksort_recursive(array $data) {
    ksort($data);

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = anonymous_sales_ksort_recursive($value);
        }
    }

    return $data;
}

/**
 * NOWPayments signs the JSON re-encoded with keys sorted alphabetically (ksort) and
 * unescaped slashes, not the raw request body. The raw body is still accepted in case
 * it already arrives in that form.
 */
function anonymous_sales_validate_nowpayments_signature($payload, $signature) {
    $secret = trim((string) anonymous_sales_get_nowpayments_webhook_key());
    $signature = strtolower(trim((string) $signature));

    if ($secret === '' || $signature === '') {
        return false;
    }

    $candidates = [(string) $payload];

    $decoded = json_decode((string) $payload, true);
    if (is_array($decoded)) {
        $sorted = anonymous_sales_ksort_recursive($decoded);
        $candidates[] = json_encode($sorted, JSON_UNESCAPED_SLASHES);
        $candidates[] = json_encode($sorted);
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        if (hash_equals(hash_hmac('sha512', $candidate, $secret), $signature)) {
            return true;
        }
    }

    return false;
}

/**
 * A paid order must never drop back to an unpaid status: NOWPayments can deliver
 * IPNs out of order, and a late "waiting" after "finished" would lock the download.
 */
function anonymous_sales_is_status_regression($current_status, $incoming_status) {
    $paid_statuses = ['confirmed', 'finished', 'paid'];
    $unpaid_statuses = ['created', 'pending', 'partially_paid', 'processing', 'sending', 'waiting', 'confirming'];

    return in_array((string) $current_status, $paid_statuses, true)
        && in_array((string) $incoming_status, $unpaid_statuses, true);
}

function anonymous_sales_map_payment_status($status) {
    $status = strtolower((string) $status);

    $map = [
        'waiting' => 'pending',
        'confirming' => 'pending',
        'confirmed' => 'confirmed',
        'finished' => 'finished',
        'failed' => 'failed',
        'expired' => 'expired',
        'refunded' => 'refunded',
        'partially_paid' => 'partially_paid',
        'sending' => 'processing',
    ];

    return $map[$status] ?? $status;
}

function anonymous_sales_extract_webhook_payment_id(array $payload) {
    foreach (['payment_id', 'invoice_id', 'id'] as $key) {
        if (!empty($payload[$key])) {
            return sanitize_text_field((string) $payload[$key]);
        }
    }

    return '';
}

function anonymous_sales_extract_webhook_order_id(array $payload) {
    foreach (['order_id', 'merchant_order_id'] as $key) {
        if (!empty($payload[$key])) {
            return sanitize_text_field((string) $payload[$key]);
        }
    }

    return '';
}

function anonymous_sales_extract_webhook_status(array $payload) {
    foreach (['payment_status', 'invoice_status', 'status'] as $key) {
        if (!empty($payload[$key])) {
            return anonymous_sales_map_payment_status($payload[$key]);
        }
    }

    return '';
}

function anonymous_sales_limit_log_string($value, $max_length = 2048) {
    $value = (string) $value;
    $max_length = max(128, (int) $max_length);

    if (strlen($value) <= $max_length) {
        return $value;
    }

    return substr($value, 0, $max_length) . '...[truncated]';
}

function anonymous_sales_get_webhook_request_log_context(WP_REST_Request $request, $raw_payload, $signature) {
    $preview_payload = json_decode((string) $raw_payload, true);

    $context = [
        'route' => (string) $request->get_route(),
        'method' => strtoupper((string) $request->get_method()),
        'action' => sanitize_key((string) $request->get_param('action')),
        'ip' => anonymous_sales_get_client_ip(),
        'user_agent' => sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))),
        'content_type' => sanitize_text_field(wp_unslash((string) ($_SERVER['CONTENT_TYPE'] ?? ''))),
        'signature_present' => $signature !== '',
        'signature_length' => strlen((string) $signature),
        'payload_length' => strlen((string) $raw_payload),
        'payload_excerpt' => anonymous_sales_limit_log_string((string) $raw_payload, 3000),
    ];

    if (is_array($preview_payload)) {
        $context['parsed_payload'] = true;
        $context['payment_id_hint'] = (string) anonymous_sales_extract_webhook_payment_id($preview_payload);
        $context['order_number_hint'] = (string) anonymous_sales_extract_webhook_order_id($preview_payload);
        $context['status_hint'] = (string) anonymous_sales_extract_webhook_status($preview_payload);
        $context['payload_keys'] = array_keys($preview_payload);
    } else {
        $context['parsed_payload'] = false;
    }

    return $context;
}

function anonymous_sales_handle_nowpayments_webhook(WP_REST_Request $request) {
    global $wpdb;

    $raw_payload = $request->get_body();
    $signature = (string) $request->get_header('x-nowpayments-sig');

    $request_context = anonymous_sales_get_webhook_request_log_context($request, $raw_payload, $signature);
    anonymous_sales_log_info('Webhook request received.', $request_context);

    $request_method = strtoupper((string) $request->get_method());
    if ($request_method !== 'POST') {
        $is_benign_probe = $request_method === 'GET' && $signature === '' && trim((string) $raw_payload) === '';

        if ($is_benign_probe) {
            anonymous_sales_log_info('Webhook endpoint probe detected (GET without signature/payload).', $request_context);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Webhook endpoint is reachable. Use POST with signature for processing.',
            ], 200);
        }

        anonymous_sales_log_warning('Webhook request uses unsupported HTTP method.', $request_context);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Webhook accepts POST only.',
        ], 405);
    }

    if (!anonymous_sales_validate_nowpayments_signature($raw_payload, $signature)) {
        anonymous_sales_log_warning('Webhook rejected due to invalid signature.', $request_context);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid webhook signature.',
        ], 403);
    }

    $payload = json_decode($raw_payload, true);
    if (!is_array($payload)) {
        anonymous_sales_log_warning('Webhook payload is invalid JSON.', $request_context);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid webhook payload.',
        ], 400);
    }

    $payment_id = anonymous_sales_extract_webhook_payment_id($payload);
    $order_number = anonymous_sales_extract_webhook_order_id($payload);
    $payment_status = anonymous_sales_extract_webhook_status($payload);

    if ($payment_status === '') {
        anonymous_sales_log_warning('Webhook payload missing payment status fields.', [
            'payment_id' => (string) $payment_id,
            'order_number' => (string) $order_number,
            'payload_keys' => array_keys($payload),
        ]);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Webhook payload does not contain payment status.',
        ], 400);
    }

    do_action('anonymous_sales_nowpayments_webhook_received', $payload, $request);

    $order = null;
    if ($payment_id !== '') {
        $order = anonymous_sales_get_order_by_payment_id($payment_id);
    }
    if (!$order && !empty($payload['invoice_id'])) {
        $order = anonymous_sales_get_order_by_invoice_id(sanitize_text_field((string) $payload['invoice_id']));
    }
    if (!$order && $order_number !== '') {
        $order = anonymous_sales_get_order_by_identifiers($order_number);
    }

    if (!$order) {
        anonymous_sales_log_warning('Webhook order lookup failed.', [
            'payment_id' => (string) $payment_id,
            'order_number' => (string) $order_number,
        ]);

        do_action('anonymous_sales_nowpayments_webhook_order_missing', $payload, $payment_id, $order_number, $request);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Related order was not found.',
        ], 404);
    }

    $schema = anonymous_sales_get_schema_names();
    $current_status = (string) $order['order_currentStatus'];

    if (anonymous_sales_is_status_regression($current_status, $payment_status)) {
        anonymous_sales_log_warning('Webhook ignored: it would move a paid order back to an unpaid status (late or out-of-order delivery).', [
            'order_number' => (string) $order['order_stamp'],
            'payment_id' => (string) $payment_id,
            'current_status' => $current_status,
            'incoming_status' => (string) $payment_status,
        ]);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Webhook ignored: order already paid.',
            'ignored' => true,
        ], 200);
    }

    if ($current_status === $payment_status && (string) $order['order_paymentId'] === $payment_id) {
        anonymous_sales_log_info('Webhook idempotent delivery detected.', [
            'order_number' => (string) $order['order_stamp'],
            'payment_id' => (string) $payment_id,
            'status' => (string) $payment_status,
        ]);

        do_action('anonymous_sales_nowpayments_webhook_idempotent', $order, $payload, $request);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Webhook already processed.',
            'idempotent' => true,
        ], 200);
    }

    $update_data = [
        'order_currentStatus' => $payment_status,
        'order_paymentId' => $payment_id !== '' ? $payment_id : (string) $order['order_paymentId'],
        'order_paymentUrl' => esc_url_raw((string) (anonymous_sales_resolve_payment_url($payload) ?: $order['order_paymentUrl'])),
        'order_webhookPayload' => $raw_payload,
    ];

    $update_format = ['%s', '%s', '%s', '%s'];

    if (in_array($payment_status, ['confirmed', 'finished', 'paid'], true)) {
        $update_data['order_paidAt'] = current_time('mysql', true);
        $update_format[] = '%s';
    }

    $update_data = apply_filters('anonymous_sales_nowpayments_webhook_update_data', $update_data, $order, $payload, $payment_status, $request);

    $wpdb->update(
        $schema['orders'],
        $update_data,
        ['order_id' => (int) $order['order_id']],
        $update_format,
        ['%d']
    );

    if ($wpdb->last_error !== '') {
        anonymous_sales_log_warning('Webhook order update failed due to database error.', [
            'order_number' => (string) $order['order_stamp'],
            'payment_id' => (string) $payment_id,
            'status' => (string) $payment_status,
            'db_error' => (string) $wpdb->last_error,
        ] + $request_context);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Webhook order update failed.',
        ], 500);
    }

    do_action('anonymous_sales_nowpayments_webhook_processed', $order, $payload, $payment_status, $request);

    anonymous_sales_log_info('Webhook processed and order updated.', [
        'order_number' => (string) $order['order_stamp'],
        'payment_id' => (string) $payment_id,
        'status' => (string) $payment_status,
    ]);

    if (in_array($payment_status, ['confirmed', 'finished', 'paid'], true)) {
        do_action('anonymous_sales_order_paid', $order, $payload, $request);
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Webhook processed.',
        'order_number' => (string) $order['order_stamp'],
        'status' => $payment_status,
    ], 200);
}

// ─── A: Fetch payment status from NOWPayments API ───────────────────────────

function anonymous_sales_fetch_payment_status_from_nowpayments($payment_id) {
    $payment_id = trim((string) $payment_id);
    if ($payment_id === '') {
        return new WP_Error('anonymous_sales_nowpayments_no_payment_id', 'No payment ID to fetch.');
    }

    $api_key = anonymous_sales_get_nowpayments_api_key();
    if ($api_key === '') {
        return new WP_Error('anonymous_sales_nowpayments_not_configured', 'NOWPayments API key is not configured.');
    }

    $request_args = [
        'headers' => ['x-api-key' => $api_key],
        'timeout' => 10,
    ];

    $endpoint = apply_filters(
        'anonymous_sales_nowpayments_payment_status_endpoint',
        'https://api.nowpayments.io/v1/payment/' . rawurlencode($payment_id)
    );

    $request_args = apply_filters('anonymous_sales_nowpayments_payment_status_request_args', $request_args, $payment_id);

    anonymous_sales_log_info('Fetching payment status from NOWPayments.', [
        'payment_id' => (string) $payment_id,
    ]);

    $response = wp_remote_get($endpoint, $request_args);

    if (is_wp_error($response)) {
        anonymous_sales_log_warning('NOWPayments payment status request failed.', [
            'payment_id' => (string) $payment_id,
            'error' => $response->get_error_message(),
        ]);
        return new WP_Error('anonymous_sales_nowpayments_request_failed', $response->get_error_message());
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $body = json_decode($raw_body, true);

    if ($status_code !== 200 || !is_array($body)) {
        anonymous_sales_log_warning('NOWPayments payment status returned unexpected response.', [
            'payment_id' => (string) $payment_id,
            'status_code' => $status_code,
        ]);
        return new WP_Error(
            'anonymous_sales_nowpayments_status_failed',
            sprintf('NOWPayments returned HTTP %d.', $status_code),
            ['status' => $status_code]
        );
    }

    anonymous_sales_log_info('NOWPayments payment status fetched.', [
        'payment_id' => (string) $payment_id,
        'status' => (string) anonymous_sales_extract_payment_status($body),
    ]);

    return $body;
}

/**
 * An invoice has no status endpoint. The payment NOWPayments creates when the buyer
 * opens the invoice carries our order number as order_id, so it is found on the
 * payment list and its id is stored on the order for direct lookups from then on.
 */
function anonymous_sales_find_payment_for_invoice(array $order) {
    global $wpdb;

    $order_number = (string) ($order['order_stamp'] ?? '');
    $invoice_id = trim((string) ($order['order_invoiceId'] ?? ''));
    if ($order_number === '' || $invoice_id === '') {
        return new WP_Error('anonymous_sales_nowpayments_no_invoice_id', 'No invoice ID to look up.');
    }

    $api_key = anonymous_sales_get_nowpayments_api_key();
    if ($api_key === '') {
        return new WP_Error('anonymous_sales_nowpayments_not_configured', 'NOWPayments API key is not configured.');
    }

    $created_at = strtotime((string) ($order['order_createdAt'] ?? ''));
    $query = [
        'limit' => 100,
        'page' => 0,
        'sortBy' => 'created_at',
        'orderBy' => 'desc',
    ];
    if ($created_at) {
        $query['dateFrom'] = gmdate('Y-m-d', $created_at - DAY_IN_SECONDS);
    }

    $endpoint = apply_filters(
        'anonymous_sales_nowpayments_payment_list_endpoint',
        add_query_arg($query, 'https://api.nowpayments.io/v1/payment/'),
        $order
    );

    $request_args = apply_filters('anonymous_sales_nowpayments_payment_list_request_args', [
        'headers' => ['x-api-key' => $api_key],
        'timeout' => 10,
    ], $order);

    $response = wp_remote_get($endpoint, $request_args);
    if (is_wp_error($response)) {
        anonymous_sales_log_warning('NOWPayments payment list request failed.', [
            'order_number' => $order_number,
            'error' => $response->get_error_message(),
        ]);
        return $response;
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    $items = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

    if ($status_code !== 200 || !is_array($body)) {
        anonymous_sales_log_warning('NOWPayments payment list returned unexpected response.', [
            'order_number' => $order_number,
            'status_code' => $status_code,
            'hint' => in_array($status_code, [401, 403], true) ? 'The payment list may require a JWT token on this account; see the anonymous_sales_nowpayments_payment_list_request_args filter.' : '',
        ]);
        return new WP_Error('anonymous_sales_nowpayments_list_failed', sprintf('NOWPayments returned HTTP %d.', $status_code), ['status' => $status_code]);
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $matches_order = (string) ($item['order_id'] ?? '') === $order_number;
        $matches_invoice = isset($item['invoice_id']) && (string) $item['invoice_id'] === $invoice_id;
        if (!$matches_order && !$matches_invoice) {
            continue;
        }

        $payment_id = sanitize_text_field((string) ($item['payment_id'] ?? ''));
        if ($payment_id === '') {
            continue;
        }

        $schema = anonymous_sales_get_schema_names();
        $wpdb->update(
            $schema['orders'],
            ['order_paymentId' => $payment_id],
            ['order_id' => (int) $order['order_id']],
            ['%s'],
            ['%d']
        );

        anonymous_sales_log_info('Payment found for invoice on the payment list.', [
            'order_number' => $order_number,
            'invoice_id' => $invoice_id,
            'payment_id' => $payment_id,
        ]);

        return $item;
    }

    // Nothing yet: the buyer has not opened the invoice or picked a coin.
    return new WP_Error('anonymous_sales_nowpayments_payment_not_found', 'No payment exists for this invoice yet.');
}

/**
 * Current NOWPayments data for an order: by payment id when known, otherwise by
 * finding the invoice's payment first. Returns the raw payment array or WP_Error.
 */
function anonymous_sales_fetch_payment_for_order(array $order) {
    $payment_id = trim((string) ($order['order_paymentId'] ?? ''));
    if ($payment_id !== '') {
        return anonymous_sales_fetch_payment_status_from_nowpayments($payment_id);
    }

    return anonymous_sales_find_payment_for_invoice($order);
}

// ─── B: Apply fetched payment status to order (idempotent) ──────────────────

function anonymous_sales_apply_fetched_payment_status(array $order, array $raw_payment) {
    global $wpdb;

    $schema = anonymous_sales_get_schema_names();

    $fetched_status = anonymous_sales_map_payment_status(
        anonymous_sales_extract_payment_status($raw_payment)
    );

    if ($fetched_status === '') {
        return false;
    }

    $current_status = (string) $order['order_currentStatus'];

    // Already at terminal state — do not regress
    $terminal_statuses = ['finished', 'confirmed', 'paid', 'failed', 'expired', 'refunded'];
    if (in_array($current_status, $terminal_statuses, true) && $current_status === $fetched_status) {
        return false;
    }

    // No change
    if ($current_status === $fetched_status) {
        return false;
    }

    if (anonymous_sales_is_status_regression($current_status, $fetched_status)) {
        return false;
    }

    anonymous_sales_log_info('Applying fetched payment status to order.', [
        'order_number' => (string) $order['order_stamp'],
        'old_status' => $current_status,
        'new_status' => $fetched_status,
    ]);

    $update_data = [
        'order_currentStatus' => $fetched_status,
    ];
    $update_format = ['%s'];

    if (in_array($fetched_status, ['confirmed', 'finished', 'paid'], true) && empty($order['order_paidAt'])) {
        $update_data['order_paidAt'] = current_time('mysql', true);
        $update_format[] = '%s';
    }

    $update_data = apply_filters(
        'anonymous_sales_apply_fetched_status_update_data',
        $update_data,
        $order,
        $fetched_status,
        $raw_payment
    );

    $wpdb->update(
        $schema['orders'],
        $update_data,
        ['order_id' => (int) $order['order_id']],
        $update_format,
        ['%d']
    );

    if ($wpdb->last_error !== '') {
        anonymous_sales_log_warning('Failed to apply fetched payment status.', [
            'order_number' => (string) $order['order_stamp'],
            'db_error' => (string) $wpdb->last_error,
        ]);
        return false;
    }

    do_action('anonymous_sales_payment_status_applied', $order, $fetched_status, $raw_payment);

    if (in_array($fetched_status, ['confirmed', 'finished', 'paid'], true)) {
        do_action('anonymous_sales_order_paid', $order, $raw_payment, null);
    }

    return true;
}

add_action('rest_api_init', 'anonymous_sales_register_rest_routes');
add_filter('rest_pre_serve_request', 'anonymous_sales_send_router_cors_headers', 10, 4);