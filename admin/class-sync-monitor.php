<?php
/**
 * Sync Monitor
 * Trang theo dõi sync logs của từng cửa hàng
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Sync_Monitor {

    /**
     * Render sync monitor page
     */
    public static function render() {
        if (!current_user_can('manage_network')) {
            wp_die(__('Bạn không có quyền truy cập trang này.', 'tgs-hub-api'));
        }

        $blog_id = isset($_GET['blog_id']) ? (int) $_GET['blog_id'] : 0;

        if ($blog_id) {
            self::render_single_client($blog_id);
        } else {
            self::render_all_logs();
        }
    }

    /**
     * Render logs của 1 cửa hàng cụ thể
     */
    private static function render_single_client($blog_id) {
        $client = TGS_Hub_Client_Manager::get_client($blog_id);

        if (!$client) {
            wp_die(__('Client không tồn tại.', 'tgs-hub-api'));
        }

        $logs = self::get_logs(array('blog_id' => $blog_id, 'limit' => 50));
        $stats = TGS_Hub_Client_Manager::get_sync_stats($blog_id);

        include TGS_HUB_API_PLUGIN_DIR . 'admin/views/sync-detail.php';
    }

    /**
     * Render tổng quan logs tất cả cửa hàng
     */
    private static function render_all_logs() {
        $logs = self::get_logs(array('limit' => 100));

        include TGS_HUB_API_PLUGIN_DIR . 'admin/views/sync-overview.php';
    }

    /**
     * Get sync logs
     */
    public static function get_logs($args = array()) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_SYNC_LOG;

        $defaults = array(
            'blog_id' => null,
            'sync_status' => null,
            'limit' => 50,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $prepare_values = array();

        if (!is_null($args['blog_id'])) {
            $where[] = 'blog_id = %d';
            $prepare_values[] = $args['blog_id'];
        }

        if (!is_null($args['sync_status'])) {
            $where[] = 'sync_status = %s';
            $prepare_values[] = $args['sync_status'];
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
}
