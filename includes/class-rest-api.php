<?php
/**
 * REST API Registration
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_REST_API {

    const NAMESPACE = 'tgs-hub/v1';

    /**
     * Register all REST API routes
     */
    public static function register_routes() {
        // Auth endpoint (public - cho phép external calls)
        // Support both GET and POST to bypass REST restrictions
        register_rest_route(self::NAMESPACE, '/auth/register', array(
            'methods'  => array(WP_REST_Server::CREATABLE, WP_REST_Server::READABLE),
            'callback' => array('TGS_Hub_Auth_Handler', 'register'),
            'permission_callback' => '__return_true', // Public endpoint - no auth required
            'args' => array(
                'setup_token' => array(
                    'required' => true,
                    'type' => 'string',
                ),
            ),
        ));

        // Sync endpoints (cần token)
        register_rest_route(self::NAMESPACE, '/sync/push', array(
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => array('TGS_Hub_Push_Handler', 'handle'),
            'permission_callback' => array(__CLASS__, 'check_client_permission'),
        ));

        register_rest_route(self::NAMESPACE, '/sync/pull', array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => array('TGS_Hub_Pull_Handler', 'handle'),
            'permission_callback' => array(__CLASS__, 'check_client_permission'),
        ));

        register_rest_route(self::NAMESPACE, '/sync/pull-local', array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => array('TGS_Hub_Pull_Handler', 'handle_pull_local'),
            'permission_callback' => array(__CLASS__, 'check_client_permission'),
        ));

        register_rest_route(self::NAMESPACE, '/sync/ack', array(
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => array('TGS_Hub_Ack_Handler', 'handle'),
            'permission_callback' => array(__CLASS__, 'check_client_permission'),
        ));

        // Pull Schema endpoint - Local pull schema & global data
        register_rest_route(self::NAMESPACE, '/sync/pull-schema', array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => array('TGS_Hub_Pull_Schema_Handler', 'handle'),
            'permission_callback' => array(__CLASS__, 'check_client_permission'),
        ));

        // Heartbeat endpoint
        register_rest_route(self::NAMESPACE, '/device/heartbeat', array(
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'heartbeat'),
            'permission_callback' => array(__CLASS__, 'check_client_permission'),
        ));
    }

    /**
     * Check client permission (verify token)
     */
    public static function check_client_permission($request) {
        $auth_header = $request->get_header('Authorization');
        $blog_id = $request->get_header('X-Blog-ID');
        $store_id = $request->get_header('X-Store-ID');

        error_log('[TGS Hub API] check_client_permission called');
        error_log('[TGS Hub API] Auth header: ' . ($auth_header ?: 'EMPTY'));
        error_log('[TGS Hub API] Blog ID: ' . ($blog_id ?: 'EMPTY'));
        error_log('[TGS Hub API] Store ID: ' . ($store_id ?: 'EMPTY'));

        if (empty($auth_header) || empty($blog_id)) {
            error_log('[TGS Hub API] REJECTED: Missing auth or blog_id');
            return new WP_Error(
                'missing_auth',
                'Missing Authorization header or X-Blog-ID',
                array('status' => 401)
            );
        }

        // Extract token from "Bearer {token}"
        $token = str_replace('Bearer ', '', $auth_header);

        // Verify token
        $client = TGS_Hub_Auth_Handler::verify_token($token, $blog_id);

        if (is_wp_error($client)) {
            error_log('[TGS Hub API] REJECTED: verify_token failed - ' . $client->get_error_message());
            return $client;
        }

        error_log('[TGS Hub API] PASSED: Client verified - ' . print_r($client, true));

        // Store client info in request for later use
        $request->set_param('_tgs_client', $client);

        return true;
    }

    /**
     * Heartbeat endpoint - Client ping định kỳ
     */
    public static function heartbeat($request) {
        $client = $request->get_param('_tgs_client');

        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;

        $wpdb->update(
            $table,
            array(
                'last_seen_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $client['id']),
            array('%s', '%s'),
            array('%d')
        );

        return new WP_REST_Response(array(
            'success' => true,
            'server_time' => current_time('mysql'),
            'force_pull' => false, // Có thể dùng để bắt client pull ngay
        ), 200);
    }
}
