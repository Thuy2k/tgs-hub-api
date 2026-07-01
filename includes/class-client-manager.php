<?php
/**
 * Client Manager
 * Quản lý danh sách clients (70/650 cửa hàng)
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Client_Manager {

    /**
     * Get all clients
     */
    public static function get_all_clients($args = array()) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;

        $defaults = array(
            'is_active' => null,
            'branch_name' => null,
            'limit' => 100,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $prepare_values = array();

        if (!is_null($args['is_active'])) {
            $where[] = 'is_active = %d';
            $prepare_values[] = $args['is_active'];
        }

        if (!is_null($args['branch_name'])) {
            $where[] = 'branch_name = %s';
            $prepare_values[] = $args['branch_name'];
        }

        $where_sql = implode(' AND ', $where);
        $prepare_values[] = $args['limit'];
        $prepare_values[] = $args['offset'];

        $query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        $results = $wpdb->get_results(
            $wpdb->prepare($query, ...$prepare_values),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get client by blog_id
     */
    public static function get_client($blog_id) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE blog_id = %d",
            $blog_id
        ), ARRAY_A);
    }

    /**
     * Revoke client (disable)
     */
    public static function revoke_client($blog_id) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;

        return $wpdb->update(
            $table,
            array('is_active' => 0, 'updated_at' => current_time('mysql')),
            array('blog_id' => $blog_id),
            array('%d', '%s'),
            array('%d')
        );
    }

    /**
     * Get sync stats
     */
    public static function get_sync_stats($blog_id) {
        global $wpdb;
        $log_table = $wpdb->base_prefix . TGS_HUB_TABLE_SYNC_LOG;

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_events,
                SUM(CASE WHEN sync_status = 'applied' THEN 1 ELSE 0 END) as applied,
                SUM(CASE WHEN sync_status = 'error' THEN 1 ELSE 0 END) as errors,
                MAX(created_at) as last_sync_at
             FROM {$log_table}
             WHERE blog_id = %d",
            $blog_id
        ), ARRAY_A);

        return $stats;
    }
}
