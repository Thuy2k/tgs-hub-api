<?php
/**
 * Hub Table Registry - SINGLE SOURCE OF TRUTH
 * Tất cả config về bảng nào được pull, primary key là gì đều ở đây
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Table_Registry {

    /**
     * Danh sách GLOBAL tables có thể pull
     * Format: 'table_name' => 'primary_key_column'
     */
    public static function get_global_tables() {
        return array(
            'wp_global_product_cat' => 'global_product_cat_id',
            'wp_global_product_name' => 'global_product_name_id',
            'wp_global_product_lots' => 'global_product_lot_id',
            'wp_global_selling_policy' => 'selling_policy_id',
            'wp_global_selling_policy_items' => 'sp_item_id',
            'wp_global_purchase_policy' => 'purchase_policy_id',
            'wp_global_purchase_policy_item' => 'pp_item_id',
            'wp_global_supplier' => 'supplier_id',
        );
    }

    /**
     * Danh sách LOCAL tables có thể pull
     * Format: 'table_name' => 'primary_key_column'
     */
    public static function get_local_tables() {
        return array(
            'local_ledger' => 'local_ledger_id',
            'local_ledger_item' => 'local_ledger_item_id',
            'local_ledger_meta' => 'local_ledger_meta_id',
        );
    }

    /**
     * Get primary key cho một bảng bất kỳ
     */
    public static function get_primary_key($table_name) {
        // Remove prefix nếu có
        $clean_name = str_replace('wp_', '', $table_name);
        $clean_name = $wpdb->prefix . $clean_name;

        // Check trong GLOBAL tables
        $global = self::get_global_tables();
        if (isset($global[$table_name])) {
            return $global[$table_name];
        }

        // Check trong LOCAL tables (thêm prefix)
        $local = self::get_local_tables();
        foreach ($local as $base_name => $pk) {
            if (strpos($table_name, $base_name) !== false) {
                return $pk;
            }
        }

        // Fallback: auto-detect
        global $wpdb;
        $keys = $wpdb->get_results("SHOW KEYS FROM {$table_name} WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (!empty($keys)) {
            return $keys[0]['Column_name'];
        }

        return null;
    }

    /**
     * Check bảng có được phép pull không
     */
    public static function is_pullable($table_name) {
        $global = self::get_global_tables();
        $local = self::get_local_tables();

        // Check exact match trong global
        if (isset($global[$table_name])) {
            return true;
        }

        // Check trong local (base name)
        $clean = str_replace('wp_', '', $table_name);
        return isset($local[$clean]);
    }
}
