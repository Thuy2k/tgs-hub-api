<?php
/**
 * Push Handler
 * Xử lý push dữ liệu từ Local → Hub
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Push_Handler {

    /**
     * Handle push request
     *
     * Request body:
     * {
     *   "events": [
     *     {
     *       "event_id": "evt_xxxxx",
     *       "transaction_id": "txn_order_123",
     *       "parent_event_id": null,
     *       "table_name": "wp_local_ledger",
     *       "record_id": 123,
     *       "action": "insert",
     *       "occurred_at": "2026-07-01T10:30:00+07:00",
     *       "data_hash": "md5_hash",
     *       "payload": {...}
     *     }
     *   ]
     * }
     */
    public static function handle($request) {
        global $wpdb;

        $client = $request->get_param('_tgs_client');
        $events = $request->get_param('events');

        if (empty($events) || !is_array($events)) {
            return new WP_Error('invalid_request', 'Missing or invalid events array', array('status' => 400));
        }

        $blog_id = $client['blog_id'];
        $accepted = array();
        $rejected = array();
        $duplicates = array();

        // Group events by transaction_id
        $grouped = self::group_by_transaction($events);

        // Switch to target blog
        switch_to_blog($blog_id);

        // Process each transaction atomically
        foreach ($grouped as $txn_id => $txn_events) {
            $txn_result = self::apply_transaction($blog_id, $txn_events);

            $accepted = array_merge($accepted, $txn_result['accepted']);
            $rejected = array_merge($rejected, $txn_result['rejected']);
            $duplicates = array_merge($duplicates, $txn_result['duplicates']);
        }

        // Restore current blog
        restore_current_blog();

        // Update last_sync_at
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;
        $wpdb->update(
            $table,
            array('last_sync_at' => current_time('mysql'), 'updated_at' => current_time('mysql')),
            array('id' => $client['id']),
            array('%s', '%s'),
            array('%d')
        );

        return new WP_REST_Response(array(
            'success' => true,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'duplicates' => $duplicates,
            'server_timestamp' => current_time('mysql'),
        ), 200);
    }

    /**
     * Group events by transaction_id
     */
    private static function group_by_transaction($events) {
        $grouped = array();

        foreach ($events as $event) {
            $txn_id = $event['transaction_id'] ?? 'single_' . ($event['event_id'] ?? uniqid());
            if (!isset($grouped[$txn_id])) {
                $grouped[$txn_id] = array();
            }
            $grouped[$txn_id][] = $event;
        }

        return $grouped;
    }

    /**
     * Apply transaction atomically (all-or-nothing)
     */
    private static function apply_transaction($blog_id, $txn_events) {
        global $wpdb;

        $accepted = array();
        $rejected = array();
        $duplicates = array();

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($txn_events as $event) {
                $event_id = $event['event_id'] ?? '';
                $table_name = $event['table_name'] ?? '';
                $record_id = $event['record_id'] ?? 0;
                $action = $event['action'] ?? '';
                $payload = $event['payload'] ?? null;
                $data_hash = $event['data_hash'] ?? '';

                if (empty($event_id) || empty($table_name) || empty($action) || empty($payload)) {
                    throw new Exception("Invalid event: {$event_id}");
                }

                if (TGS_Hub_Idempotency::is_duplicate($blog_id, $event_id)) {
                    $duplicates[] = $event_id;
                    continue;
                }

                $result = self::apply_change($table_name, $record_id, $action, $payload);

                if (is_wp_error($result)) {
                    throw new Exception($result->get_error_message());
                }

                self::log_sync($blog_id, $event_id, $table_name, $record_id, $action, $payload, $data_hash, 'applied', null);
                $accepted[] = $event_id;
            }

            $wpdb->query('COMMIT');

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            // Mark tất cả events trong transaction là rejected
            foreach ($txn_events as $event) {
                $event_id = $event['event_id'] ?? '';
                if (!in_array($event_id, $duplicates) && !in_array($event_id, $accepted)) {
                    $rejected[] = $event_id;
                    self::log_sync(
                        $blog_id,
                        $event_id,
                        $event['table_name'] ?? '',
                        $event['record_id'] ?? 0,
                        $event['action'] ?? '',
                        $event['payload'] ?? array(),
                        $event['data_hash'] ?? '',
                        'error',
                        'Transaction rolled back: ' . $e->getMessage()
                    );
                }
            }
        }

        return array(
            'accepted' => $accepted,
            'rejected' => $rejected,
            'duplicates' => $duplicates,
        );
    }

    /**
     * Apply change vào database
     */
    private static function apply_change($table_name, $record_id, $action, $payload) {
        global $wpdb;

        // Only handle local_ledger - use TGS_POS create_order()
        if ($table_name !== 'wp_local_ledger') {
            return new WP_Error('unsupported_table', 'Only wp_local_ledger is supported');
        }

        if ($action !== 'insert') {
            return new WP_Error('unsupported_action', 'Only insert action is supported');
        }

        // Parse full order payload
        $ledger_data = $payload['ledger'] ?? array();
        $items_data = $payload['items'] ?? array();
        $meta_data = $payload['meta'] ?? array();

        if (empty($ledger_data)) {
            return new WP_Error('empty_payload', 'Missing ledger data');
        }

        // Check if TGS_POS_Order_Handler exists
        if (!class_exists('TGS_POS_Order_Handler')) {
            return new WP_Error('missing_handler', 'TGS_POS_Order_Handler not found - plugin tgs_pos required');
        }

        // Reconstruct order data for create_order()
        $order_data = array(
            'sale_code' => $ledger_data['sale_code'] ?? '',
            'customer_name' => $ledger_data['customer_name'] ?? '',
            'customer_phone' => $ledger_data['customer_phone'] ?? '',
            'products_data' => array(),
            'total' => $ledger_data['total_amount'] ?? 0,
            'discount' => $ledger_data['discount_amount'] ?? 0,
            'applied_promotions' => json_decode($ledger_data['applied_promotions'] ?? '[]', true),
            'payment_plan' => json_decode($meta_data['payment_plan'] ?? '{}', true),
            'source_type' => $ledger_data['source_type'] ?? 1,
        );

        // Map items
        foreach ($items_data as $item) {
            $order_data['products_data'][] = array(
                'product_id' => $item['product_id'] ?? 0,
                'sku' => $item['sku'] ?? '',
                'quantity' => $item['quantity'] ?? 0,
                'price' => $item['price'] ?? 0,
                'unit' => $item['unit'] ?? 'Cái',
            );
        }

        // Call TGS_POS create_order()
        $result = TGS_POS_Order_Handler::create_order($order_data);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Log sync vào wp_tgs_sync_log
     */
    private static function log_sync($blog_id, $event_id, $table_name, $record_id, $action, $payload, $data_hash, $status, $error = null) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_SYNC_LOG;

        $wpdb->insert(
            $table,
            array(
                'blog_id' => $blog_id,
                'event_id' => $event_id,
                'table_name' => $table_name,
                'record_id' => $record_id,
                'action' => $action,
                'data_hash' => $data_hash,
                'payload' => json_encode($payload),
                'direction' => 'push',
                'sync_status' => $status,
                'applied_at' => ($status === 'applied') ? current_time('mysql') : null,
                'error_message' => $error,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
}

