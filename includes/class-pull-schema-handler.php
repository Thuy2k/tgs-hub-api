<?php
/**
 * Pull Schema Handler
 * Xử lý yêu cầu pull schema từ Local
 * Trả về danh sách bảng cần tạo và dữ liệu khởi tạo
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Pull_Schema_Handler {

    /**
     * Handle pull schema request
     * GET /tgs-hub/v1/sync/pull-schema
     */
    public static function handle($request) {
        $client = $request->get_param('_tgs_client');

        // Lấy SQL statements từ DB thực tế (GLOBAL từ Hub, LOCAL từ blog)
        $sql_statements = self::extract_sql_from_database_class($client['blog_id']);

        // Lấy dữ liệu GLOBAL cần pull về
        $global_data = self::get_global_data($client['blog_id']);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'schema_version' => '1.0.0',
                'sql_statements' => $sql_statements,
                'global_data' => $global_data,
                'instructions' => 'Execute SQL statements to create tables, then insert global data',
            ),
        ), 200);
    }

    /**
     * Extract SQL statements từ database thực tế
     * GLOBAL: lấy từ Hub main DB
     * LOCAL: lấy từ multisite blog đang kết nối
     */
    private static function extract_sql_from_database_class($blog_id) {
        global $wpdb;

        $sql_statements = array(
            'global' => array(),
            'local' => array(),
        );

        // Lấy config bảng nào cần pull
        $config = TGS_Hub_Schema_Config::get_config();

        // 1. GLOBAL tables - lấy CREATE TABLE từ Hub DB thực tế
        foreach ($config['global'] as $method_name) {
            // Extract tên bảng từ method name: sql_global_product_name -> wp_global_product_name
            $table_name = str_replace('sql_', '', $method_name);
            $table_name = 'wp_' . $table_name;

            // Lấy CREATE TABLE từ DB thực tế
            $create_sql = self::get_create_table_sql($table_name);
            if ($create_sql) {
                $sql_statements['global'][] = array(
                    'method' => $method_name,
                    'table' => $table_name,
                    'sql' => $create_sql,
                );
            }
        }

        // 2. LOCAL tables - lấy từ bảng multisite blog đang kết nối
        foreach ($config['local'] as $method_name) {
            // Extract tên bảng: sql_local_ledger -> local_ledger
            $base_table = str_replace('sql_', '', $method_name);

            // Tên bảng thực tế trong blog: wp_5_local_ledger
            $blog_table = $wpdb->get_blog_prefix($blog_id) . $base_table;

            // Lấy CREATE TABLE từ blog table
            $create_sql = self::get_create_table_sql($blog_table);
            if ($create_sql) {
                // Replace blog prefix bằng placeholder để Local apply đúng
                $generic_sql = str_replace($blog_table, '{{prefix}}' . $base_table, $create_sql);

                $sql_statements['local'][] = array(
                    'method' => $method_name,
                    'table' => $base_table,
                    'sql' => $generic_sql,
                );
            }
        }

        return $sql_statements;
    }

    /**
     * Lấy CREATE TABLE SQL từ database thực tế
     */
    private static function get_create_table_sql($table_name) {
        global $wpdb;

        // Check table tồn tại
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if (!$exists) {
            return null;
        }

        // Lấy CREATE TABLE statement
        $result = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);
        if (!$result || !isset($result[1])) {
            return null;
        }

        return $result[1];
    }

    /**
     * Lấy cấu hình schema (bảng nào cần tạo)
     * DEPRECATED - giờ dùng extract_sql_from_database_class()
     */
    private static function get_schema_config($blog_id) {
        // TODO: Có thể lưu config này trong wp_options hoặc database
        // Admin Hub có thể cấu hình bảng nào cần sync cho từng blog

        return array(
            'local_tables' => array(
                'local_ledger_person',
                'local_ledger',
                'local_ledger_item',
                'local_ledger_meta',
            ),
            'global_tables' => array(
                'global_product_name',
                'global_product_cat',
                'global_product_lots',
                'global_selling_policy',
                'global_selling_policy_items',
            ),
        );
    }

    /**
     * Lấy dữ liệu GLOBAL từ Hub để đẩy về Local
     */
    private static function get_global_data($blog_id) {
        global $wpdb;

        $data = array();

        // 1. Lấy danh mục sản phẩm - TẤT CẢ
        $categories = $wpdb->get_results(
            "SELECT * FROM wp_global_product_cat",
            ARRAY_A
        );
        $data['categories'] = $categories ?: array();

        // 2. Lấy sản phẩm - TẤT CẢ
        $products = $wpdb->get_results(
            "SELECT * FROM wp_global_product_name",
            ARRAY_A
        );
        $data['products'] = $products ?: array();

        // 3. Lấy chính sách bán hàng - TẤT CẢ
        $policies = $wpdb->get_results(
            "SELECT * FROM wp_global_selling_policy",
            ARRAY_A
        );
        $data['selling_policies'] = $policies ?: array();

        // 4. Lấy lô hàng - TẤT CẢ
        $lots = $wpdb->get_results(
            "SELECT * FROM wp_global_product_lots",
            ARRAY_A
        );
        $data['product_lots'] = $lots ?: array();

        // Thống kê
        $data['summary'] = array(
            'total_categories' => count($data['categories']),
            'total_products' => count($data['products']),
            'total_policies' => count($data['selling_policies']),
            'total_lots' => count($data['product_lots']),
        );

        return $data;
    }
}
