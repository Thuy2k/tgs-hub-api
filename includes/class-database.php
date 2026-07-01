<?php
/**
 * Database Schema for TGS Hub API
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Database {

    const DB_VERSION = '1.0.0';
    const OPTION_DB_VERSION = 'tgs_hub_api_db_version';

    /**
     * Create all tables
     */
    public static function create_tables() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        // 1. Bảng quản lý clients (70/650 cửa hàng)
        dbDelta(self::sql_hub_clients($charset_collate));

        // 2. Bảng sync log (Hub side)
        dbDelta(self::sql_sync_log($charset_collate));

        // Update DB version
        update_site_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Bảng wp_tgs_hub_clients - Danh sách cửa hàng kết nối
     */
    private static function sql_hub_clients($charset_collate) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            blog_id INT UNSIGNED NOT NULL UNIQUE COMMENT 'ID site trên Multisite',
            client_token VARCHAR(64) NOT NULL UNIQUE COMMENT 'Token xác thực',
            client_name VARCHAR(255) NOT NULL COMMENT 'Tên cửa hàng',
            store_id VARCHAR(64) DEFAULT NULL COMMENT 'Mã cửa hàng (VD: store_pt_001)',
            branch_name VARCHAR(255) DEFAULT NULL COMMENT 'Chi nhánh (VD: Phú Thọ)',

            setup_token VARCHAR(64) DEFAULT NULL COMMENT 'Token QR Code (dùng 1 lần)',
            setup_token_expires_at DATETIME DEFAULT NULL,

            connected_at DATETIME NOT NULL,
            last_seen_at DATETIME DEFAULT NULL COMMENT 'Lần ping cuối',
            last_sync_at DATETIME DEFAULT NULL COMMENT 'Lần sync cuối',

            is_active TINYINT(1) NOT NULL DEFAULT 1,
            metadata JSON DEFAULT NULL COMMENT 'IP, version, docker info',

            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,

            INDEX idx_active (is_active),
            INDEX idx_branch (branch_name),
            INDEX idx_store_id (store_id),
            INDEX idx_last_seen (last_seen_at)
        ) {$charset_collate};";
    }

    /**
     * Bảng wp_tgs_sync_log - Log đồng bộ (Hub side)
     * Ghi lại mọi thay đổi từ Local push lên
     */
    private static function sql_sync_log($charset_collate) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_SYNC_LOG;

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            blog_id INT UNSIGNED NOT NULL COMMENT 'Blog ID của client',
            event_id VARCHAR(64) NOT NULL COMMENT 'Event ID từ Local (idempotency key)',

            table_name VARCHAR(64) NOT NULL COMMENT 'Tên bảng bị thay đổi',
            record_id BIGINT UNSIGNED NOT NULL COMMENT 'ID bản ghi',
            action ENUM('insert', 'update', 'delete') NOT NULL,

            data_hash VARCHAR(64) DEFAULT NULL COMMENT 'MD5 của payload',
            payload JSON NOT NULL COMMENT 'Dữ liệu đầy đủ',

            direction ENUM('push', 'pull') NOT NULL DEFAULT 'push',
            sync_status ENUM('pending', 'applied', 'error') NOT NULL DEFAULT 'pending',

            applied_at DATETIME DEFAULT NULL,
            error_message TEXT DEFAULT NULL,

            created_at DATETIME NOT NULL,

            UNIQUE KEY uk_blog_event (blog_id, event_id),
            INDEX idx_blog_table (blog_id, table_name),
            INDEX idx_status (sync_status),
            INDEX idx_created (created_at),
            INDEX idx_direction (direction, sync_status)
        ) {$charset_collate};";
    }

    /**
     * Get table name with prefix
     */
    public static function table($name) {
        global $wpdb;
        return $wpdb->base_prefix . $name;
    }
}
