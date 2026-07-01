<?php
/**
 * Client Dashboard
 * Trang quản lý danh sách cửa hàng
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Client_Dashboard {

    /**
     * Init hooks
     */
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }

    /**
     * Enqueue admin scripts
     */
    public static function enqueue_scripts($hook) {
        // Only on TGS Hub pages
        if (strpos($hook, 'tgs-hub') === false) {
            return;
        }

        wp_localize_script('jquery', 'tgsHub', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tgs_hub_generate_qr'),
        ));
    }

    /**
     * Render dashboard page
     */
    public static function render() {
        if (!current_user_can('manage_network')) {
            wp_die(__('Bạn không có quyền truy cập trang này.', 'tgs-hub-api'));
        }

        // Get all sites in multisite
        $sites = get_sites(array('number' => 1000));

        // Get registered clients
        $registered_clients = TGS_Hub_Client_Manager::get_all_clients(array('limit' => 1000));

        // Build map: blog_id => client data
        $clients_map = array();
        foreach ($registered_clients as $client) {
            $clients_map[$client['blog_id']] = $client;
        }

        // Merge: all sites + registration status
        $clients = array();
        foreach ($sites as $site) {
            $blog_id = (int) $site->blog_id;

            // Skip main site (blog_id = 1)
            if ($blog_id === 1) {
                continue;
            }

            $blog_details = get_blog_details($blog_id);

            $clients[] = array(
                'blog_id' => $blog_id,
                'blogname' => $blog_details->blogname,
                'siteurl' => $blog_details->siteurl,
                'store_id' => isset($clients_map[$blog_id]) ? $clients_map[$blog_id]['store_id'] : 'TGS' . $blog_id,
                'branch_name' => isset($clients_map[$blog_id]) ? $clients_map[$blog_id]['branch_name'] : 'Chưa đặt tên',
                'is_active' => isset($clients_map[$blog_id]) ? $clients_map[$blog_id]['is_active'] : 0,
                'is_registered' => isset($clients_map[$blog_id]),
                'created_at' => isset($clients_map[$blog_id]) ? $clients_map[$blog_id]['created_at'] : null,
            );
        }

        include TGS_HUB_API_PLUGIN_DIR . 'admin/views/client-list.php';
    }

    /**
     * Generate QR Code for client (AJAX handler)
     */
    public static function ajax_generate_qr() {
        check_ajax_referer('tgs_hub_generate_qr', 'nonce');

        if (!current_user_can('manage_network')) {
            wp_send_json_error('Permission denied');
        }

        $blog_id = (int) $_POST['blog_id'];
        $store_id = sanitize_text_field($_POST['store_id']);
        $branch_name = sanitize_text_field($_POST['branch_name']);

        if (!$blog_id || !$store_id || !$branch_name) {
            wp_send_json_error('Missing required fields');
        }

        $token_data = TGS_Hub_Token_Generator::generate_setup_token($blog_id, $store_id, $branch_name);

        wp_send_json_success(array(
            'token' => $token_data['token'],
            'qr_data' => $token_data['qr_data'], // Thêm qr_data riêng
            'expires_at' => $token_data['expires_at'],
            'qr_code_url' => TGS_Hub_Token_Generator::generate_qr_code_url($token_data['qr_data']),
        ));
    }
}

// Register AJAX handlers (cho cả admin thường và network admin)
add_action('wp_ajax_tgs_hub_generate_qr', array('TGS_Hub_Client_Dashboard', 'ajax_generate_qr'));
add_action('wp_ajax_nopriv_tgs_hub_generate_qr', array('TGS_Hub_Client_Dashboard', 'ajax_generate_qr'));

// Init hooks
TGS_Hub_Client_Dashboard::init();
