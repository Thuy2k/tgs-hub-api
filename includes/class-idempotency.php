<?php
/**
 * Idempotency Handler
 * Kiểm tra duplicate events (chống tạo trùng khi retry)
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Idempotency {

    /**
     * Check if event_id đã được xử lý chưa
     */
    public static function is_duplicate($blog_id, $event_id) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_SYNC_LOG;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND event_id = %s",
            $blog_id,
            $event_id
        ));

        return (bool) $exists;
    }
}
