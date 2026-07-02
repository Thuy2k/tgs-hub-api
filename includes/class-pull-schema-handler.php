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
     * GET /tgs-hub/v1/sync/pull-schema?since=2026-07-01 06:00:00&cursor_cat=123&selected_tables=categories,policy_items
     */
    public static function handle($request) {
        $client = $request->get_param('_tgs_client');

        // Lấy timestamp cho incremental sync
        $since = $request->get_param('since');

        // Lấy cursors cho từng bảng (pagination)
        $cursors = array(
            'categories' => $request->get_param('cursor_cat') ?? PHP_INT_MAX,
            'products' => $request->get_param('cursor_product') ?? PHP_INT_MAX,
            'policies' => $request->get_param('cursor_policy') ?? PHP_INT_MAX,
            'lots' => $request->get_param('cursor_lot') ?? PHP_INT_MAX,
        );

        // Parse thêm dynamic cursors (cursor_product_name, cursor_selling_policy_items, etc.)
        foreach ($request->get_params() as $key => $value) {
            if (strpos($key, 'cursor_') === 0 && !isset($cursors[$key])) {
                // cursor_product_name -> product_name
                $table_key = str_replace('cursor_', '', $key);
                $cursors[$table_key] = $value;
            }
        }

        error_log('[Hub] Received cursors: ' . print_r($cursors, true));

        // Lấy danh sách bảng được chọn (optional - nếu không có thì fetch all)
        $selected_tables = $request->get_param('selected_tables');
        if ($selected_tables && is_string($selected_tables)) {
            $selected_tables = explode(',', $selected_tables);
        } else {
            $selected_tables = null; // null = fetch all
        }

        // Lấy SQL statements từ DB thực tế (GLOBAL từ Hub, LOCAL từ blog)
        $sql_statements = self::extract_sql_from_database_class($client['blog_id']);

        // Lấy dữ liệu GLOBAL cần pull về (chỉ fetch bảng được chọn)
        $global_data = self::get_global_data($request, $client['blog_id'], $since, $cursors, $selected_tables);

        // Lấy dữ liệu LOCAL từ blog multisite đã kết nối
        $local_data = self::get_local_data($client['blog_id'], $since);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'schema_version' => '1.0.0',
                'sql_statements' => $sql_statements,
                'global_data' => $global_data,
                'local_data' => $local_data,
                'server_time' => current_time('mysql', true), // Để Local update watermark
                'instructions' => 'Execute SQL statements to create tables, then upsert global and local data',
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
        foreach ($config['local_pull'] ?? array() as $method_name) {
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
     * Hỗ trợ incremental sync với timestamp + cursor-based pagination
     *
     * @param WP_REST_Request $request Request object
     * @param int $blog_id Blog ID (unused, reserved for future filtering)
     * @param string|null $since Timestamp cho incremental sync
     * @param array $cursors Array of cursors: ['table_name' => cursor_value, ...]
     * @param array|null $selected_tables Danh sách key bảng được chọn (null = fetch all)
     * @return array Data với pagination info
     */
    private static function get_global_data($request, $blog_id, $since = null, $cursors = array(), $selected_tables = null) {
        global $wpdb;

        $limit = 500;
        $data = array();

        // Lấy danh sách tables từ Registry
        $tables = TGS_Hub_Table_Registry::get_global_tables();

        // Filter theo bảng được chọn (nếu có)
        if ($selected_tables !== null && is_array($selected_tables)) {
            $filtered_tables = array();
            foreach ($tables as $table_name => $pk) {
                // Convert table name -> key (wp_global_product_cat -> product_cat)
                $key = str_replace('wp_global_', '', $table_name);

                // Check nếu key này được chọn
                if (in_array($key, $selected_tables)) {
                    $filtered_tables[$table_name] = $pk;
                }
            }
            $tables = $filtered_tables;
        }

        // Parse cursors trực tiếp từ query params - dùng table name làm key
        $normalized_cursors = array();
        foreach ($request->get_params() as $param_key => $value) {
            if (strpos($param_key, 'cursor_') === 0) {
                // cursor_product_name -> wp_global_product_name
                $key = str_replace('cursor_', '', $param_key);
                $table_name = 'wp_global_' . $key;

                // Nếu table này trong danh sách fetch, lưu cursor
                if (isset($tables[$table_name])) {
                    $normalized_cursors[$table_name] = intval($value);
                    error_log("[Hub] Parsed cursor: {$param_key} = {$value} -> {$table_name} = " . intval($value));
                }
            }
        }
        error_log('[Hub] Final normalized cursors: ' . print_r($normalized_cursors, true));

        // Init cursors nếu chưa có
        foreach ($tables as $table_name => $pk) {
            if (!isset($normalized_cursors[$table_name])) {
                $normalized_cursors[$table_name] = PHP_INT_MAX;
            }
        }

        // Fetch từng bảng
        $has_more_any = false;
        foreach ($tables as $table_name => $pk) {
            $result = self::fetch_table_batch(
                $table_name,
                $pk,
                $since,
                $normalized_cursors[$table_name],
                $limit
            );

            // Store data với key = table name (bỏ prefix wp_)
            $key = str_replace('wp_global_', '', $table_name);

            // APPEND data thay vì ghi đè (cho trường hợp multi-batch)
            if (!isset($data[$key])) {
                $data[$key] = array();
            }
            $data[$key] = array_merge($data[$key], $result['data']);

            $data["cursor_{$key}_next"] = $result['next_cursor'];
            $data["has_more_{$key}"] = $result['has_more'];

            error_log("[Hub] Table {$table_name}: fetched " . count($result['data']) . " records, has_more={$result['has_more']}, next_cursor={$result['next_cursor']}");

            if ($result['has_more']) {
                $has_more_any = true;
            }
        }

        // Summary
        $data['summary'] = array(
            'since' => $since,
            'is_incremental' => !empty($since),
            'has_more' => $has_more_any,
        );

        return $data;
    }

    /**
     * Lấy dữ liệu LOCAL từ multisite blog
     */
    private static function get_local_data($blog_id, $since = null) {
        global $wpdb;

        $data = array();
        $limit = 500;

        // Lấy config bảng LOCAL nào được phép PULL
        $config = TGS_Hub_Schema_Config::get_config();
        $allowed_tables = $config['local_pull'] ?? array();

        if (empty($allowed_tables)) {
            return array(
                'summary' => array(
                    'total_records' => 0,
                    'tables_pulled' => 0,
                    'message' => 'No LOCAL tables configured for PULL',
                ),
            );
        }

        $total_records = 0;

        // Lặp qua từng bảng LOCAL được phép pull
        foreach ($allowed_tables as $method_name) {
            // Extract tên bảng: sql_local_ledger -> local_ledger
            $base_table = str_replace('sql_', '', $method_name);

            // Tên bảng thực tế trong blog: wp_5_local_ledger
            $blog_table = $wpdb->get_blog_prefix($blog_id) . $base_table;

            // Check bảng tồn tại
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $blog_table));
            if (!$exists) {
                continue;
            }

            // Lấy primary key
            $pk = self::get_real_primary_key($blog_table, null);
            if (!$pk) {
                continue;
            }

            // Build WHERE clause cho incremental sync
            $where_clause = '';
            $prepare_args = array($limit);

            if ($since) {
                $where_clause = 'WHERE (updated_at > %s OR deleted_at > %s)';
                $prepare_args = array($since, $since, $limit);
            }

            // Query data
            $query = $wpdb->prepare(
                "SELECT * FROM {$blog_table}
                 {$where_clause}
                 ORDER BY {$pk} DESC
                 LIMIT %d",
                ...$prepare_args
            );

            $results = $wpdb->get_results($query, ARRAY_A);

            if (!empty($results)) {
                $data[$base_table] = $results;
                $total_records += count($results);
            }
        }

        $data['summary'] = array(
            'total_records' => $total_records,
            'tables_pulled' => count($data) - 1, // -1 vì có 'summary'
            'since' => $since,
            'is_incremental' => !empty($since),
            'blog_id' => $blog_id,
        );

        return $data;
    }

    /**
     * Fetch một batch dữ liệu từ bảng với cursor-based pagination
     * ORDER BY id DESC (mới nhất trước)
     *
     * @param string $table_name Tên bảng
     * @param string $pk_column Tên cột primary key (optional, auto-detect nếu null)
     * @param string|null $since Timestamp cho incremental sync
     * @param int $cursor Cursor hiện tại (id lớn nhất đã lấy)
     * @param int $limit Số records tối đa mỗi batch
     * @return array ['data' => [], 'next_cursor' => int, 'has_more' => bool]
     */
    private static function fetch_table_batch($table_name, $pk_column, $since, $cursor, $limit) {
        global $wpdb;

        // Auto-detect primary key nếu cột không tồn tại
        $real_pk = self::get_real_primary_key($table_name, $pk_column);

        if (!$real_pk) {
            // Không tìm thấy primary key → trả về empty
            return array(
                'data' => array(),
                'next_cursor' => null,
                'has_more' => false,
            );
        }

        // Build WHERE clause
        $where_parts = array();
        $prepare_args = array();

        // Incremental sync: chỉ lấy records thay đổi sau $since (không dùng cursor)
        if ($since) {
            $where_parts[] = "(updated_at > %s OR deleted_at > %s)";
            $prepare_args[] = $since;
            $prepare_args[] = $since;
        } else {
            // Full sync: dùng cursor để phân trang
            $where_parts[] = "{$real_pk} < %d";
            $prepare_args[] = $cursor;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where_parts);

        // 3. Thêm LIMIT
        $prepare_args[] = $limit;

        // Query
        $query = $wpdb->prepare(
            "SELECT * FROM {$table_name}
             {$where_clause}
             ORDER BY {$real_pk} DESC
             LIMIT %d",
            ...$prepare_args
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        // Tính next_cursor và has_more
        $next_cursor = null;
        $has_more = false;

        if (!empty($results)) {
            $last_record = end($results);
            $next_cursor = $last_record[$real_pk];
            $has_more = (count($results) == $limit); // Nếu đủ limit thì còn data
        }

        return array(
            'data' => $results ?: array(),
            'next_cursor' => $next_cursor,
            'has_more' => $has_more,
        );
    }

    /**
     * Detect primary key thực tế từ bảng
     *
     * @param string $table_name Tên bảng
     * @param string $suggested_pk Tên PK gợi ý (có thể sai)
     * @return string|null Tên cột PK thực tế, hoặc null nếu không tìm thấy
     */
    private static function get_real_primary_key($table_name, $suggested_pk) {
        global $wpdb;

        // Check suggested PK có tồn tại không
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        if (in_array($suggested_pk, $columns)) {
            return $suggested_pk;
        }

        // Không tồn tại → tìm PK thực tế từ SHOW KEYS
        $keys = $wpdb->get_results("SHOW KEYS FROM {$table_name} WHERE Key_name = 'PRIMARY'", ARRAY_A);

        if (!empty($keys)) {
            return $keys[0]['Column_name'];
        }

        // Fallback: tìm cột có AUTO_INCREMENT
        $auto_inc = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} WHERE Extra LIKE '%auto_increment%'", ARRAY_A);

        if (!empty($auto_inc)) {
            return $auto_inc[0]['Field'];
        }

        // Không tìm thấy → return null
        return null;
    }
}
