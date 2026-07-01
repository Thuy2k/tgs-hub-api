<?php
/**
 * Authentication Handler
 * Xử lý đăng ký client mới (QR scan) và verify token
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Auth_Handler {

    /**
     * Register new client (khi Local quét QR Code)
     *
     * Request body:
     * {
     *   "setup_token": "QR_TOKEN_FROM_HUB",
     *   "store_name": "Cửa hàng Phú Thọ 01",
     *   "device_info": {...}
     * }
     */
    public static function register($request) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;

        $setup_token = $request->get_param('setup_token');
        $store_name = $request->get_param('store_name') ?: 'POS Client'; // Default name
        $device_info = $request->get_param('device_info');

        if (empty($setup_token)) {
            return new WP_Error(
                'invalid_request',
                'Missing setup_token',
                array('status' => 400)
            );
        }

        // Tìm client pending với setup_token
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE setup_token = %s AND setup_token_expires_at > NOW()",
            $setup_token
        ), ARRAY_A);

        if (!$client) {
            return new WP_Error(
                'invalid_token',
                'Setup token không hợp lệ hoặc đã hết hạn',
                array('status' => 401)
            );
        }

        // Generate client token
        $client_token = TGS_Hub_Token_Generator::generate_client_token();

        // Update client
        $wpdb->update(
            $table,
            array(
                'client_token' => $client_token,
                'client_name' => $store_name,
                'connected_at' => current_time('mysql'),
                'last_seen_at' => current_time('mysql'),
                'is_active' => 1,
                'metadata' => json_encode($device_info),
                'setup_token' => null, // Xóa setup_token đã dùng
                'setup_token_expires_at' => null,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $client['id']),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        // Response
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'client_token' => $client_token,
                'blog_id' => (int) $client['blog_id'],
                'store_id' => $client['store_id'],
                'store_name' => $store_name,
                'branch_name' => $client['branch_name'],
                'hub_url' => rest_url('tgs-hub/v1'),
                'schema_version' => '1.0.0',
                'sync_interval' => 300, // 5 phút
            ),
        ), 200);
    }

    /**
     * Verify client token
     */
    public static function verify_token($token, $blog_id) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;

        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE client_token = %s AND blog_id = %d AND is_active = 1",
            $token,
            $blog_id
        ), ARRAY_A);

        if (!$client) {
            return new WP_Error(
                'invalid_token',
                'Token không hợp lệ hoặc client đã bị vô hiệu hóa',
                array('status' => 401)
            );
        }

        return $client;
    }
}
