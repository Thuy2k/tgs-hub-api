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

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($txn_events as $event) {
                $event_id = $event['event_id'] ?? '';
                $table_name = $event['table_name'] ?? '';
                $record_id = $event['record_id'] ?? 0;
                $action = $event['action'] ?? '';
                $payload = $event['payload'] ?? null;
                $data_hash = $event['data_hash'] ?? '';

                // Validate
                if (empty($event_id) || empty($table_name) || empty($action) || empty($payload)) {
                    throw new Exception("Invalid event: {$event_id}");
                }

                // Check idempotency (đã xử lý event này chưa?)
                if (TGS_Hub_Idempotency::is_duplicate($blog_id, $event_id)) {
                    $duplicates[] = $event_id;
                    continue;
                }

                // Apply change vào database
                $result = self::apply_change($table_name, $record_id, $action, $payload);

                if (is_wp_error($result)) {
                    // Nếu là conflict → log nhưng vẫn rollback cả transaction
                    if ($result->get_error_code() === 'conflict') {
                        self::log_conflict($blog_id, $event_id, $table_name, $record_id, $action, $payload, $result->get_error_data());
                    }

                    throw new Exception($result->get_error_message());
                }

                // Log success
                self::log_sync($blog_id, $event_id, $table_name, $record_id, $action, $payload, $data_hash, 'applied', null);
                $accepted[] = $event_id;
            }

            // Commit nếu tất cả thành công
            $wpdb->query('COMMIT');

        } catch (Exception $e) {
            // Rollback nếu có lỗi
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

        // Lấy whitelist từ config (các bảng LOCAL cho phép PUSH)
        $config = TGS_Hub_Schema_Config::get_config();
        $allowed_tables_config = $config['local_push'] ?? array();

        // Convert từ method name sang table name
        $allowed_tables = array();
        foreach ($allowed_tables_config as $method_name) {
            // sql_local_ledger → wp_local_ledger
            $table = 'wp_' . str_replace('sql_', '', $method_name);
            $allowed_tables[] = $table;
        }

        if (!in_array($table_name, $allowed_tables)) {
            return new WP_Error('forbidden_table', "Bảng {$table_name} không được phép sync (chưa được bật trong Hub Schema Config)");
        }

        $table = $wpdb->prefix . str_replace('wp_', '', $table_name);

        // Filter payload để chỉ giữ các cột tồn tại trong bảng
        $clean_payload = self::filter_columns($payload, $table);

        if (empty($clean_payload)) {
            return new WP_Error('empty_payload', 'Payload không có cột hợp lệ');
        }

        switch ($action) {
            case 'insert':
                // Check conflict: nếu record_id đã tồn tại → skip (idempotent)
                $pk = self::get_primary_key($table_name);
                if ($record_id && isset($clean_payload[$pk])) {
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE {$pk} = %s",
                        $clean_payload[$pk]
                    ));

                    if ($exists) {
                        // Record đã tồn tại → skip, không lỗi (idempotent)
                        return true;
                    }
                }

                $result = $wpdb->insert($table, $clean_payload);
                break;

            case 'update':
                // Check conflict: so sánh updated_at
                $pk = self::get_primary_key($table_name);

                // Lấy updated_at hiện tại ở Hub
                $hub_updated_at = $wpdb->get_var($wpdb->prepare(
                    "SELECT updated_at FROM {$table} WHERE {$pk} = %s",
                    $record_id
                ));

                // Nếu payload có updated_at và Hub có data mới hơn → conflict
                if ($hub_updated_at && isset($clean_payload['updated_at'])) {
                    if (strtotime($clean_payload['updated_at']) < strtotime($hub_updated_at)) {
                        return new WP_Error('conflict', 'Hub data is newer than Local. Please pull first.', array(
                            'hub_updated_at' => $hub_updated_at,
                            'local_updated_at' => $clean_payload['updated_at'],
                        ));
                    }
                }

                $result = $wpdb->update($table, $clean_payload, array($pk => $record_id));
                break;

            case 'delete':
                $pk = self::get_primary_key($table_name);
                $result = $wpdb->update($table, array('is_deleted' => 1, 'deleted_at' => current_time('mysql')), array($pk => $record_id));
                break;

            default:
                return new WP_Error('invalid_action', 'Action không hợp lệ');
        }

        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error);
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

    /**
     * Get primary key của bảng
     */
    private static function get_primary_key($table_name) {
        $map = array(
            'wp_local_ledger' => 'local_ledger_id',
            'wp_local_ledger_item' => 'local_ledger_item_id',
            'wp_local_ledger_person' => 'local_ledger_person_id',
            'wp_local_ledger_meta' => 'local_ledger_meta_id',
            'wp_local_viettel_invoice' => 'invoice_id',
            'wp_local_viettel_invoice_log' => 'log_id',
            'wp_local_zns_log' => 'id',
            'wp_local_person_loyalty_logs' => 'log_id',
            'wp_local_htsoft_import_log' => 'log_id',
        );

        return $map[$table_name] ?? 'id';
    }

    /**
     * Filter payload để chỉ giữ các cột tồn tại trong bảng Hub
     * Tránh lỗi "Unknown column" khi Local có cột mới Hub chưa có
     */
    private static function filter_columns($data, $table_name) {
        global $wpdb;

        // Lấy danh sách cột của bảng Hub
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        if (empty($columns)) {
            return array();
        }

        // Chỉ giữ các key có trong bảng
        $filtered = array();
        foreach ($data as $key => $value) {
            if (in_array($key, $columns)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Log conflict vào bảng wp_tgs_sync_conflicts
     */
    private static function log_conflict($blog_id, $event_id, $table_name, $record_id, $action, $local_data, $error_data) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CONFLICTS;

        // Xác định conflict type
        $conflict_type = 'update_outdated'; // Default
        if ($action === 'insert') {
            $conflict_type = 'insert_duplicate';
        } elseif ($action === 'delete') {
            $conflict_type = 'delete_missing';
        }

        // Lấy data hiện tại ở Hub (nếu có)
        $hub_data = null;
        $hub_updated_at = null;
        if ($record_id && $action !== 'insert') {
            $pk = self::get_primary_key($table_name);
            $target_table = $wpdb->prefix . str_replace('wp_', '', $table_name);

            $hub_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$target_table} WHERE {$pk} = %s",
                $record_id
            ), ARRAY_A);

            if ($hub_record) {
                $hub_data = $hub_record;
                $hub_updated_at = $hub_record['updated_at'] ?? null;
            }
        }

        $wpdb->insert(
            $table,
            array(
                'blog_id' => $blog_id,
                'event_id' => $event_id,
                'table_name' => $table_name,
                'record_id' => $record_id,
                'conflict_type' => $conflict_type,
                'local_data' => json_encode($local_data, JSON_UNESCAPED_UNICODE),
                'hub_data' => $hub_data ? json_encode($hub_data, JSON_UNESCAPED_UNICODE) : null,
                'local_updated_at' => $local_data['updated_at'] ?? null,
                'hub_updated_at' => $hub_updated_at ?? ($error_data['hub_updated_at'] ?? null),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
}
