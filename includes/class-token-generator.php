<?php
/**
 * Token Generator
 * Tạo QR Code token và Client token
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Token_Generator {

    /**
     * Generate setup token (dùng trong QR Code)
     *
     * @param int $blog_id Blog ID của cửa hàng
     * @param string $store_id Mã cửa hàng (VD: store_pt_001)
     * @param string $branch_name Chi nhánh (VD: Phú Thọ)
     * @return array {token, expires_at}
     */
    public static function generate_setup_token($blog_id, $store_id, $branch_name) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;

        // Generate random token
        $setup_token = self::generate_random_token(32);
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // Kiểm tra blog_id đã tồn tại chưa
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE blog_id = %d",
            $blog_id
        ));

        if ($exists) {
            // Update existing
            $wpdb->update(
                $table,
                array(
                    'setup_token' => $setup_token,
                    'setup_token_expires_at' => $expires_at,
                    'store_id' => $store_id,
                    'branch_name' => $branch_name,
                    'updated_at' => current_time('mysql'),
                ),
                array('blog_id' => $blog_id),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            // Insert new
            $wpdb->insert(
                $table,
                array(
                    'blog_id' => $blog_id,
                    'setup_token' => $setup_token,
                    'setup_token_expires_at' => $expires_at,
                    'client_name' => 'Pending', // Sẽ update khi client register
                    'store_id' => $store_id,
                    'branch_name' => $branch_name,
                    'client_token' => null,
                    'connected_at' => current_time('mysql'),
                    'is_active' => 0,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );
        }

        return array(
            'token' => $setup_token,
            'expires_at' => $expires_at,
            'qr_data' => json_encode(array(
                'setup_token' => $setup_token,
                'hub_url' => rest_url('tgs-hub/v1'),
                'blog_id' => $blog_id,
                'store_id' => $store_id,
                'branch_name' => $branch_name,
            )),
        );
    }

    /**
     * Generate client token (sau khi register thành công)
     */
    public static function generate_client_token() {
        return self::generate_random_token(64);
    }

    /**
     * Generate random token
     */
    private static function generate_random_token($length = 64) {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Generate QR Code image URL
     *
     * @param string $qr_data JSON data để encode
     * @return string URL của QR Code
     */
    public static function generate_qr_code_url($qr_data) {
        // Sử dụng Google Charts API (hoặc library PHP như endroid/qr-code)
        $encoded_data = urlencode($qr_data);
        return "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl={$encoded_data}";
    }
}
