<?php
/**
 * Plugin Name: TGS Hub API
 * Plugin URI: https://tgsworld.vn
 * Description: API Hub cho hệ thống POS Local-First. Nhận đồng bộ từ 650+ cửa hàng.
 * Version: 1.0.0
 * Author: TGS World
 * Author URI: https://tgsworld.vn
 * Text Domain: tgs-hub-api
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Network: true
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TGS_HUB_API_VERSION', '1.0.0');
define('TGS_HUB_API_PLUGIN_FILE', __FILE__);
define('TGS_HUB_API_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_HUB_API_PLUGIN_URL', plugin_dir_url(__FILE__));

// Table names
define('TGS_HUB_TABLE_CLIENTS', 'tgs_hub_clients');
define('TGS_HUB_TABLE_SYNC_LOG', 'tgs_sync_log');
define('TGS_HUB_TABLE_CONFLICTS', 'tgs_sync_conflicts');

/**
 * Main plugin class
 */
class TGS_Hub_API {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-database.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-hub-table-registry.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-rest-api.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-auth-handler.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-push-handler.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-pull-handler.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-pull-schema-handler.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-ack-handler.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-client-manager.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-token-generator.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-idempotency.php';
        require_once TGS_HUB_API_PLUGIN_DIR . 'includes/class-sync-coordinator.php';

        // Schema Config - cần load luôn vì REST API sẽ dùng
        require_once TGS_HUB_API_PLUGIN_DIR . 'admin/class-schema-config.php';

        // Admin classes
        if (is_admin()) {
            require_once TGS_HUB_API_PLUGIN_DIR . 'admin/class-client-dashboard.php';
            require_once TGS_HUB_API_PLUGIN_DIR . 'admin/class-sync-monitor.php';
            require_once TGS_HUB_API_PLUGIN_DIR . 'admin/class-conflict-resolver.php';

            // Test Schema V2
            require_once TGS_HUB_API_PLUGIN_DIR . 'test-schema-v2.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('rest_api_init', array('TGS_Hub_REST_API', 'register_routes'));

        // Allow REST API authentication for external calls (testing)
        add_filter('rest_authentication_errors', array($this, 'allow_rest_api'));

        // Admin menu
        if (is_admin()) {
            add_action('network_admin_menu', array($this, 'add_network_admin_menu'));
        }
    }

    /**
     * Allow REST API from external (for testing and cross-project calls)
     */
    public function allow_rest_api($result) {
        // Allow /tgs-hub/v1/auth/register endpoint from external calls
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/tgs-hub/v1/auth/register') !== false) {
            return true;
        }

        if (!empty($result)) {
            return $result;
        }
        return true;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        ob_start(); // Suppress output
        TGS_Hub_Database::create_tables();
        ob_end_clean();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'tgs-hub-api',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Add network admin menu
     */
    public function add_network_admin_menu() {
        add_menu_page(
            __('TGS Hub API', 'tgs-hub-api'),
            __('TGS Hub', 'tgs-hub-api'),
            'manage_network',
            'tgs-hub-api',
            array('TGS_Hub_Client_Dashboard', 'render'),
            'dashicons-networking',
            30
        );

        add_submenu_page(
            'tgs-hub-api',
            __('Cửa hàng', 'tgs-hub-api'),
            __('Cửa hàng', 'tgs-hub-api'),
            'manage_network',
            'tgs-hub-api',
            array('TGS_Hub_Client_Dashboard', 'render')
        );

        add_submenu_page(
            'tgs-hub-api',
            __('Push Logs', 'tgs-hub-api'),
            __('Push Logs', 'tgs-hub-api'),
            'manage_network',
            'tgs-hub-sync',
            array('TGS_Hub_Sync_Monitor', 'render')
        );

        add_submenu_page(
            'tgs-hub-api',
            __('Xử lý Conflicts', 'tgs-hub-api'),
            __('Xử lý Conflicts', 'tgs-hub-api'),
            'manage_network',
            'tgs-hub-conflicts',
            array('TGS_Hub_Conflict_Resolver', 'render')
        );

        add_submenu_page(
            'tgs-hub-api',
            __('Cấu hình Schema', 'tgs-hub-api'),
            __('Cấu hình Schema', 'tgs-hub-api'),
            'manage_network',
            'tgs-hub-schema-config',
            array('TGS_Hub_Schema_Config', 'render')
        );
    }
}

/**
 * Initialize plugin
 */
function tgs_hub_api() {
    return TGS_Hub_API::instance();
}

// Start the plugin
tgs_hub_api();
