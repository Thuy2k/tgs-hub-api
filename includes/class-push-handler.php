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

        // Switch to target blog
        switch_to_blog($blog_id);

        foreach ($events as $event) {
            $event_id = $event['event_id'] ?? '';
            $table_name = $event['table_name'] ?? '';
            $record_id = $event['record_id'] ?? 0;
            $action = $event['action'] ?? '';
            $payload = $event['payload'] ?? null;
            $data_hash = $event['data_hash'] ?? '';

            // Validate
            if (empty($event_id) || empty($table_name) || empty($action) || empty($payload)) {
                $rejected[] = $event_id;
                continue;
            }

            // Check idempotency (đã xử lý event này chưa?)
            if (TGS_Hub_Idempotency::is_duplicate($blog_id, $event_id)) {
                $duplicates[] = $event_id;
                continue;
            }

            // Apply change vào database
            $result = self::apply_change($table_name, $record_id, $action, $payload);

            if (is_wp_error($result)) {
                $rejected[] = $event_id;
                self::log_sync($blog_id, $event_id, $table_name, $record_id, $action, $payload, $data_hash, 'error', $result->get_error_message());
                continue;
            }

            // Log success
            self::log_sync($blog_id, $event_id, $table_name, $record_id, $action, $payload, $data_hash, 'applied', null);
            $accepted[] = $event_id;
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
     * Apply change vào database
     */
    private static function apply_change($table_name, $record_id, $action, $payload) {
        global $wpdb;

        // Chỉ sync các bảng được phép
        $allowed_tables = array(
            'wp_local_ledger',
            'wp_local_ledger_item',
            'wp_local_ledger_person',
        );

        if (!in_array($table_name, $allowed_tables)) {
            return new WP_Error('forbidden_table', "Bảng {$table_name} không được phép sync");
        }

        $table = $wpdb->prefix . str_replace('wp_', '', $table_name);

        switch ($action) {
            case 'insert':
                $result = $wpdb->insert($table, $payload);
                break;

            case 'update':
                // Tìm primary key
                $pk = self::get_primary_key($table_name);
                $result = $wpdb->update($table, $payload, array($pk => $record_id));
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
        );

        return $map[$table_name] ?? 'id';
    }
}
