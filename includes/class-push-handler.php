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
     * Apply change vào database - INSERT all 3 ledgers (SALE + EXPORT + RECEIPT)
     */
    private static function apply_change($table_name, $record_id, $action, $payload) {
        global $wpdb;

        error_log('[TGS Hub Push] apply_change called - table: ' . $table_name . ', action: ' . $action);

        // Only handle local_ledger
        if ($table_name !== 'wp_local_ledger') {
            error_log('[TGS Hub Push] Unsupported table: ' . $table_name);
            return new WP_Error('unsupported_table', 'Only wp_local_ledger is supported');
        }

        if ($action !== 'insert') {
            error_log('[TGS Hub Push] Unsupported action: ' . $action);
            return new WP_Error('unsupported_action', 'Only insert action is supported');
        }

        // Parse payload
        $sale_ledger = $payload['sale_ledger'] ?? array();
        $sale_meta = $payload['sale_meta'] ?? array();
        $export_ledger = $payload['export_ledger'] ?? array();
        $export_items = $payload['export_items'] ?? array();
        $receipt_ledgers = $payload['receipt_ledgers'] ?? array();

        if (empty($sale_ledger)) {
            error_log('[TGS Hub Push] Empty sale_ledger');
            return new WP_Error('empty_payload', 'Missing sale_ledger');
        }

        error_log('[TGS Hub Push] Inserting order - SALE + ' . count($receipt_ledgers) . ' RECEIPT(s) + EXPORT with ' . count($export_items) . ' items');

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $item_table = $wpdb->prefix . 'local_ledger_item';
        $meta_table = $wpdb->prefix . 'local_ledger_meta';

        // 1. Insert SALE_ORDER meta first (if exists)
        $sale_meta_id = null;
        if (!empty($sale_meta)) {
            $wpdb->insert($meta_table, array(
                'local_ledger_meta_value' => json_encode($sale_meta, JSON_UNESCAPED_UNICODE),
                'user_id' => $sale_ledger['user_id'] ?? 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ));
            $sale_meta_id = $wpdb->insert_id;
        }

        // 2. Insert SALE_ORDER ledger (without item_id mapping yet)
        $sale_insert = $sale_ledger;
        unset($sale_insert['local_ledger_id']); // Hub generates new ID
        unset($sale_insert['local_ledger_item_id']); // Will update after items created
        if ($sale_meta_id) {
            $sale_insert['local_ledger_meta_id'] = $sale_meta_id;
        }

        $wpdb->insert($ledger_table, $sale_insert);
        $new_sale_id = $wpdb->insert_id;

        if (!$new_sale_id) {
            error_log('[TGS Hub Push] Failed to insert SALE ledger: ' . $wpdb->last_error);
            return new WP_Error('insert_failed', 'Failed to insert SALE ledger');
        }

        error_log('[TGS Hub Push] SALE ledger inserted with ID: ' . $new_sale_id);

        // 3. Insert RECEIPT ledger(s)
        foreach ($receipt_ledgers as $receipt) {
            // Insert RECEIPT meta if exists
            $receipt_meta_id = null;
            if (!empty($receipt['meta'])) {
                $wpdb->insert($meta_table, array(
                    'local_ledger_meta_value' => json_encode($receipt['meta'], JSON_UNESCAPED_UNICODE),
                    'user_id' => $receipt['user_id'] ?? 0,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ));
                $receipt_meta_id = $wpdb->insert_id;
            }

            // Insert RECEIPT ledger
            $receipt_insert = $receipt;
            unset($receipt_insert['local_ledger_id']);
            unset($receipt_insert['meta']); // Already inserted
            unset($receipt_insert['local_ledger_item_id']); // Receipt has no items
            $receipt_insert['local_ledger_parent_id'] = $new_sale_id; // Link to new SALE
            if ($receipt_meta_id) {
                $receipt_insert['local_ledger_meta_id'] = $receipt_meta_id;
            }

            $wpdb->insert($ledger_table, $receipt_insert);
            error_log('[TGS Hub Push] RECEIPT inserted with ID: ' . $wpdb->insert_id);
        }

        // 4. Insert EXPORT ledger (without item_id mapping yet)
        $new_export_id = null;
        if (!empty($export_ledger)) {
            $export_insert = $export_ledger;
            unset($export_insert['local_ledger_id']);
            unset($export_insert['local_ledger_item_id']); // Will update after items created
            $export_insert['local_ledger_parent_id'] = $new_sale_id; // Link to new SALE

            $wpdb->insert($ledger_table, $export_insert);
            $new_export_id = $wpdb->insert_id;

            if ($new_export_id) {
                error_log('[TGS Hub Push] EXPORT ledger inserted with ID: ' . $new_export_id);
            }

            // Insert RECEIPT ledger
            $receipt_insert = $receipt;
            unset($receipt_insert['local_ledger_id']);
            unset($receipt_insert['meta']); // Already inserted
            $receipt_insert['local_ledger_parent_id'] = $new_sale_id; // Link to new SALE
            if ($receipt_meta_id) {
                $receipt_insert['local_ledger_meta_id'] = $receipt_meta_id;
            }

            $wpdb->insert($ledger_table, $receipt_insert);
            error_log('[TGS Hub Push] RECEIPT inserted with ID: ' . $wpdb->insert_id);
        }

        // 4. Insert EXPORT ledger
        if (!empty($export_ledger)) {
            $export_insert = $export_ledger;
            unset($export_insert['local_ledger_id']);
            $export_insert['local_ledger_parent_id'] = $new_sale_id; // Link to new SALE

            $wpdb->insert($ledger_table, $export_insert);
            $new_export_id = $wpdb->insert_id;

            if ($new_export_id) {
                error_log('[TGS Hub Push] EXPORT ledger inserted with ID: ' . $new_export_id);

                // 5. Insert EXPORT items and build ID mapping
                $new_item_ids = array();
                foreach ($export_items as $item) {
                    $item_insert = $item;
                    unset($item_insert['local_ledger_item_id']); // Let Hub auto-generate new ID
                    $item_insert['local_ledger_id'] = $new_export_id; // Link to new EXPORT

                    $wpdb->insert($item_table, $item_insert);
                    $new_item_ids[] = $wpdb->insert_id;
                }

                error_log('[TGS Hub Push] Inserted ' . count($export_items) . ' items');

                // 6. Update EXPORT ledger with new item IDs
                if (!empty($new_item_ids)) {
                    $items_json = json_encode($new_item_ids);
                    $wpdb->update(
                        $ledger_table,
                        array('local_ledger_item_id' => $items_json),
                        array('local_ledger_id' => $new_export_id)
                    );
                    error_log('[TGS Hub Push] Updated EXPORT ledger with item IDs: ' . $items_json);
                }

                // 7. Update SALE ledger with same item IDs
                if (!empty($new_item_ids)) {
                    $items_json = json_encode($new_item_ids);
                    $wpdb->update(
                        $ledger_table,
                        array('local_ledger_item_id' => $items_json),
                        array('local_ledger_id' => $new_sale_id)
                    );
                    error_log('[TGS Hub Push] Updated SALE ledger with item IDs: ' . $items_json);
                }
            }
        }

        error_log('[TGS Hub Push] Full order inserted successfully at Hub');

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

