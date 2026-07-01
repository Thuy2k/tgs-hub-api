<?php
/**
 * Pull Handler
 * Serve pull requests from Local clients
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Pull_Handler {

    /**
     * Handle pull request
     *
     * Query params:
     * ?last_pull_id=12345&tables=wp_global_product_name,wp_global_product_cat&limit=100
     */
    public static function handle($request) {
        global $wpdb;

        $client = $request->get_param('_tgs_client');
        $last_pull_id = (int) $request->get_param('last_pull_id') ?: 0;
        $tables = $request->get_param('tables');
        $limit = min((int) $request->get_param('limit') ?: 100, 200); // Max 200

        $blog_id = $client['blog_id'];

        // Parse tables
        $allowed_tables = array(
            'wp_global_product_name',
            'wp_global_product_cat',
            'wp_global_selling_policy',
        );

        $tables_to_pull = !empty($tables) ? explode(',', $tables) : $allowed_tables;
        $tables_to_pull = array_filter($tables_to_pull, function($t) use ($allowed_tables) {
            return in_array($t, $allowed_tables);
        });

        if (empty($tables_to_pull)) {
            return new WP_Error('invalid_tables', 'No valid tables specified', array('status' => 400));
        }

        // Query changes từ global tables
        // Ở đây đơn giản hóa: pull toàn bộ records mới hơn last_pull_id
        // Thực tế: cần có cơ chế track changes cho global tables

        $changes = array();

        foreach ($tables_to_pull as $table_name) {
            $records = self::get_changed_records($table_name, $last_pull_id, $limit);
            $pk = self::get_primary_key($table_name);

            foreach ($records as $record) {
                $changes[] = array(
                    'change_id' => 'chg_' . uniqid(),
                    'table_name' => $table_name,
                    'record_id' => $record[$pk] ?? 0,
                    'action' => 'update', // Hoặc 'insert' tùy logic
                    'hub_updated_at' => $record['updated_at'] ?? current_time('mysql'),
                    'payload' => $record,
                );
            }
        }

        $next_pull_id = $last_pull_id + count($changes);

        return new WP_REST_Response(array(
            'success' => true,
            'changes' => $changes,
            'next_pull_id' => $next_pull_id,
            'has_more' => count($changes) >= $limit,
        ), 200);
    }

    /**
     * Get changed records từ global table
     * (Simplified version - thực tế cần có change tracking)
     */
    private static function get_changed_records($table_name, $last_pull_id, $limit) {
        global $wpdb;

        // Map table name to actual table
        $table_map = array(
            'wp_global_product_name' => $wpdb->base_prefix . 'global_product_name',
            'wp_global_product_cat' => $wpdb->base_prefix . 'global_product_cat',
            'wp_global_selling_policy' => $wpdb->base_prefix . 'global_selling_policy',
        );

        $table = $table_map[$table_name] ?? null;

        if (!$table) {
            return array();
        }

        // Query (simplified - chỉ lấy records có updated_at mới)
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE is_deleted = 0 ORDER BY updated_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        return $results ?: array();
    }

    /**
     * Get primary key của bảng
     */
    private static function get_primary_key($table_name) {
        $map = array(
            'wp_global_product_name' => 'product_id',
            'wp_global_product_cat' => 'cat_id',
            'wp_global_selling_policy' => 'policy_id',
            'wp_local_ledger' => 'local_ledger_id',
            'wp_local_ledger_item' => 'local_ledger_item_id',
            'wp_local_ledger_meta' => 'local_ledger_meta_id',
        );
        return $map[$table_name] ?? 'id';
    }

    /**
     * Handle pull local tables request (ledger, items, meta)
     */
    public static function handle_pull_local($request) {
        global $wpdb;

        $client = $request->get_param('_tgs_client');
        $last_pull_at = $request->get_param('last_pull_at');
        $tables = $request->get_param('tables');

        $blog_id = $client['blog_id'];

        if ($tables) {
            $tables = explode(',', $tables);
        } else {
            $tables = array('wp_local_ledger', 'wp_local_ledger_item', 'wp_local_ledger_meta');
        }

        switch_to_blog($blog_id);

        $changes = array();

        foreach ($tables as $table_name) {
            $table = $wpdb->prefix . str_replace('wp_', '', $table_name);
            $pk = self::get_primary_key($table_name);

            $where = '1=1';
            if ($last_pull_at) {
                // Use >= instead of > to include records with exact timestamp
                $where = $wpdb->prepare('(updated_at >= %s OR deleted_at >= %s)', $last_pull_at, $last_pull_at);
            }

            $results = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at ASC LIMIT 100", ARRAY_A);

            foreach ($results as $row) {
                $action = 'insert';
                if (!empty($row['is_deleted'])) {
                    $action = 'delete';
                } elseif ($last_pull_at) {
                    $action = 'update';
                }

                $changes[] = array(
                    'table_name' => $table_name,
                    'record_id' => $row[$pk] ?? 0,
                    'action' => $action,
                    'payload' => $row,
                    'updated_at' => $row['updated_at'] ?? null,
                );
            }
        }

        restore_current_blog();

        return new WP_REST_Response(array(
            'success' => true,
            'changes' => $changes,
            'server_timestamp' => current_time('mysql'),
        ), 200);
    }
}
