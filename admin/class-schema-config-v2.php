<?php
/**
 * Schema Config Admin Page - Version 2
 *
 * Check schema từ class-tgs-database.php và so sánh với DB thực tế
 *
 * @package tgs-hub-api
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Schema_Config_V2 {

    /**
     * Parse column names from CREATE TABLE SQL
     */
    private static function parse_columns_from_sql($sql) {
        $columns = array();

        // Tìm phần định nghĩa cột (giữa CREATE TABLE ... ( và PRIMARY KEY)
        if (preg_match('/CREATE TABLE[^(]+\((.*?)(?:PRIMARY KEY|UNIQUE KEY|KEY|INDEX|\);)/is', $sql, $matches)) {
            $lines = explode("\n", $matches[1]);

            foreach ($lines as $line) {
                $line = trim($line);
                // Bỏ qua dòng rỗng, comment, và constraint
                if (empty($line) ||
                    strpos($line, '//') === 0 ||
                    strpos($line, '--') === 0 ||
                    strpos($line, '\\') === 0 ||
                    stripos($line, 'PRIMARY KEY') !== false ||
                    stripos($line, 'UNIQUE KEY') !== false ||
                    stripos($line, 'KEY ') === 0 ||
                    stripos($line, 'INDEX ') === 0) {
                    continue;
                }

                // Extract column name (first word before space)
                if (preg_match('/^([a-z_][a-z0-9_]*)/i', $line, $col_match)) {
                    $columns[] = $col_match[1];
                }
            }
        }

        return $columns;
    }

    /**
     * Get schema from class-tgs-database.php
     */
    public static function get_schema_from_class() {
        $db_class_file = WP_PLUGIN_DIR . '/tgs_shop_management/database/class-tgs-database.php';
        if (!class_exists('TGS_Shop_Database') && file_exists($db_class_file)) {
            require_once($db_class_file);
        }

        if (!class_exists('TGS_Shop_Database')) {
            return array();
        }

        $reflection = new ReflectionClass('TGS_Shop_Database');
        $methods = $reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PRIVATE);

        $schema = array();
        foreach ($methods as $method) {
            $name = $method->getName();
            if (strpos($name, 'sql_') !== 0) {
                continue;
            }

            $method->setAccessible(true);
            try {
                $sql = $method->invoke(null, '');
                $columns = self::parse_columns_from_sql($sql);

                $schema[$name] = array(
                    'columns' => $columns,
                    'sql' => $sql,
                );
            } catch (Exception $e) {
                continue;
            }
        }

        return $schema;
    }

    /**
     * Check if table in DB has all required sync columns
     */
    public static function check_table_in_db($table_name, $required_columns) {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if (!$table_exists) {
            return array(
                'exists' => false,
                'has_all' => false,
                'missing' => array('Bảng chưa tồn tại trong DB'),
            );
        }

        // Get actual columns in DB
        $db_columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        // Check which sync columns are missing
        $sync_columns = array('updated_at', 'deleted_at', 'created_at', 'is_deleted', 'user_id');
        $missing = array();

        foreach ($sync_columns as $col) {
            if (in_array($col, $required_columns) && !in_array($col, $db_columns)) {
                $missing[] = $col;
            }
        }

        return array(
            'exists' => true,
            'has_all' => empty($missing),
            'missing' => $missing,
        );
    }

    /**
     * Validate all tables
     */
    public static function validate_all_tables() {
        $schema = self::get_schema_from_class();
        $results = array();

        foreach ($schema as $method_name => $info) {
            // Extract table name from SQL
            if (preg_match('/CREATE TABLE\s+([^\s(]+)/i', $info['sql'], $matches)) {
                $table_name = trim($matches[1], '{}');

                $check = self::check_table_in_db($table_name, $info['columns']);

                $results[$method_name] = array(
                    'table' => $table_name,
                    'required_columns' => $info['columns'],
                    'exists' => $check['exists'],
                    'has_all' => $check['has_all'],
                    'missing' => $check['missing'],
                );
            }
        }

        return $results;
    }
}
