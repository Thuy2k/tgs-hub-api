<?php
/**
 * Schema Configuration Page
 * Quản lý cấu hình bảng nào cho phép shop kéo về
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Schema_Config {

    const OPTION_SCHEMA_CONFIG = 'tgs_hub_schema_config';

    /**
     * Render settings page
     */
    public static function render() {
        // Lấy cấu hình hiện tại
        $config = self::get_config();

        // Handle form submission
        if (isset($_POST['tgs_save_schema_config']) && check_admin_referer('tgs_schema_config')) {
            $config = self::save_config($_POST);
            echo '<div class="notice notice-success"><p>Đã lưu cấu hình!</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>Cấu hình Schema cho Shop</h1>
            <p>Chọn bảng nào cho phép shop đồng bộ (PULL về và PUSH lên)</p>

            <form method="post" action="">
                <?php wp_nonce_field('tgs_schema_config'); ?>

                <h2>Bảng GLOBAL (dùng chung toàn hệ thống)</h2>
                <p><em>Shop chỉ PULL về, không được PUSH (read-only)</em></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="check-all-global" />
                            </th>
                            <th>Tên bảng</th>
                            <th>Mô tả</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $global_tables = self::get_available_global_tables();
                        foreach ($global_tables as $table => $info) {
                            $checked = in_array($table, $config['global']) ? 'checked' : '';
                            $disabled = !$info['has_sync_columns'] ? 'disabled' : '';
                            $row_class = !$info['has_sync_columns'] ? 'style="background-color: #ffe6e6;"' : '';

                            echo '<tr ' . $row_class . '>';
                            echo '<td><input type="checkbox" name="global_tables[]" value="' . esc_attr($table) . '" ' . $checked . ' ' . $disabled . ' /></td>';
                            echo '<td><code>' . esc_html($table) . '</code></td>';
                            echo '<td>' . esc_html($info['description']);

                            if (!$info['has_sync_columns']) {
                                echo '<br><span style="color: red; font-weight: bold;">⚠️ Thiếu cột: ' . implode(', ', $info['missing_columns']) . '</span>';
                                echo '<br><em style="color: #666;">Không thể sync - cần thêm updated_at và deleted_at vào bảng</em>';
                            }

                            echo '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>

                <h2 style="margin-top: 30px;">Bảng LOCAL (riêng từng shop)</h2>
                <p><em>Shop có thể PULL về (từ blog multisite đã kết nối) và PUSH lên (từ shop về hub)</em></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Tên bảng</th>
                            <th>Mô tả</th>
                            <th style="width: 120px; text-align: center;">
                                Cho phép PULL
                                <br><input type="checkbox" id="check-all-local-pull" style="margin-top: 5px;" />
                            </th>
                            <th style="width: 120px; text-align: center;">
                                Cho phép PUSH
                                <br><input type="checkbox" id="check-all-local-push" style="margin-top: 5px;" />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $local_tables = self::get_available_local_tables();
                        foreach ($local_tables as $table => $info) {
                            $checked_pull = in_array($table, $config['local_pull'] ?? array()) ? 'checked' : '';
                            $checked_push = in_array($table, $config['local_push'] ?? array()) ? 'checked' : '';
                            $disabled = !$info['has_sync_columns'] ? 'disabled' : '';
                            $row_class = !$info['has_sync_columns'] ? 'style="background-color: #ffe6e6;"' : '';

                            echo '<tr ' . $row_class . '>';
                            echo '<td><code>' . esc_html($table) . '</code></td>';
                            echo '<td>' . esc_html($info['description']);

                            if (!$info['has_sync_columns']) {
                                echo '<br><span style="color: red; font-weight: bold;">⚠️ Thiếu cột: ' . implode(', ', $info['missing_columns']) . '</span>';
                                echo '<br><em style="color: #666;">Không thể sync - cần thêm updated_at và deleted_at vào bảng</em>';
                            }

                            echo '</td>';
                            echo '<td style="text-align: center;"><input type="checkbox" name="local_pull_tables[]" value="' . esc_attr($table) . '" ' . $checked_pull . ' ' . $disabled . ' /></td>';
                            echo '<td style="text-align: center;"><input type="checkbox" name="local_push_tables[]" value="' . esc_attr($table) . '" ' . $checked_push . ' ' . $disabled . ' /></td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" name="tgs_save_schema_config" class="button button-primary">
                        Lưu cấu hình
                    </button>
                </p>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#check-all-global').on('change', function() {
                $('input[name="global_tables[]"]').prop('checked', this.checked);
            });
            $('#check-all-local-pull').on('change', function() {
                $('input[name="local_pull_tables[]"]').prop('checked', this.checked);
            });
            $('#check-all-local-push').on('change', function() {
                $('input[name="local_push_tables[]"]').prop('checked', this.checked);
            });
        });
        </script>
        <?php
    }

    /**
     * Get schema definition from TGS_Shop_Database class (source of truth)
     *
     * Thay vì query database thật, lấy schema từ file class-tgs-database.php
     * để tránh lỗi "Table not exists" khi bảng chưa được tạo.
     */
    private static function get_schema_from_class() {
        // Include TGS_Shop_Database class nếu chưa có
        $db_class_file = WP_PLUGIN_DIR . '/tgs_shop_management/database/class-tgs-database.php';
        if (!class_exists('TGS_Shop_Database') && file_exists($db_class_file)) {
            require_once($db_class_file);
        }

        // Nếu không tìm thấy class, fallback về schema hard-coded
        if (!class_exists('TGS_Shop_Database')) {
            return self::get_fallback_schema();
        }

        // Parse class methods để lấy schema
        $reflection = new ReflectionClass('TGS_Shop_Database');
        $methods = $reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PRIVATE);

        $schema = array();
        foreach ($methods as $method) {
            $name = $method->getName();
            if (strpos($name, 'sql_') !== 0) {
                continue;
            }

            // Call method to get CREATE TABLE SQL
            $method->setAccessible(true);
            try {
                $sql = $method->invoke(null, '');

                // Parse SQL to check for updated_at and deleted_at
                $has_updated = (stripos($sql, 'updated_at') !== false);
                $has_deleted = (stripos($sql, 'deleted_at') !== false);

                $schema[$name] = array(
                    'updated_at' => $has_updated,
                    'deleted_at' => $has_deleted,
                );
            } catch (Exception $e) {
                // Skip methods that can't be invoked
                continue;
            }
        }

        return $schema;
    }

    /**
     * Fallback schema definition (hard-coded from class-tgs-database.php)
     * Dùng khi không tìm thấy TGS_Shop_Database class
     */
    private static function get_fallback_schema() {
        return array(
            'sql_local_ledger_meta' => array('updated_at' => true, 'deleted_at' => true),
            'sql_local_ledger_person' => array('updated_at' => true, 'deleted_at' => true),
            'sql_local_ledger' => array('updated_at' => true, 'deleted_at' => true),
            'sql_local_ledger_item' => array('updated_at' => true, 'deleted_at' => true),
            'sql_global_product_name' => array('updated_at' => true, 'deleted_at' => true),
            'sql_global_product_cat' => array('updated_at' => true, 'deleted_at' => true),
            'sql_global_product_lots' => array('updated_at' => true, 'deleted_at' => true),
            'sql_global_selling_policy' => array('updated_at' => true, 'deleted_at' => true),
            'sql_global_selling_policy_items' => array('updated_at' => true, 'deleted_at' => false),
            'sql_global_purchase_policy' => array('updated_at' => true, 'deleted_at' => true),
            'sql_global_purchase_policy_item' => array('updated_at' => true, 'deleted_at' => true),
            'sql_global_supplier' => array('updated_at' => true, 'deleted_at' => true),
            'sql_local_viettel_invoice' => array('updated_at' => true, 'deleted_at' => false),
            'sql_local_viettel_invoice_log' => array('updated_at' => false, 'deleted_at' => false),
            'sql_local_zns_log' => array('updated_at' => false, 'deleted_at' => false),
            'sql_local_person_loyalty_logs' => array('updated_at' => true, 'deleted_at' => true),
            'sql_local_htsoft_import_log' => array('updated_at' => true, 'deleted_at' => true),
            'sql_global_loyalty_log' => array('updated_at' => false, 'deleted_at' => false),
            'sql_global_loyalty_policy' => array('updated_at' => true, 'deleted_at' => false),
        );
    }

    /**
     * Get available GLOBAL tables
     */
    private static function get_available_global_tables() {
        $schema = self::get_schema_from_class();

        $tables = array(
            'sql_global_product_name' => array(
                'description' => 'Catalog sản phẩm toàn hệ thống',
                'table' => 'wp_global_product_name',
            ),
            'sql_global_product_cat' => array(
                'description' => 'Danh mục sản phẩm',
                'table' => 'wp_global_product_cat',
            ),
            'sql_global_product_lots' => array(
                'description' => 'Lô hàng và HSD',
                'table' => 'wp_global_product_lots',
            ),
            'sql_global_selling_policy' => array(
                'description' => 'Chính sách bán hàng',
                'table' => 'wp_global_selling_policy',
            ),
            'sql_global_selling_policy_items' => array(
                'description' => 'Chi tiết chính sách bán hàng',
                'table' => 'wp_global_selling_policy_items',
            ),
            'sql_global_purchase_policy' => array(
                'description' => 'Chính sách mua hàng',
                'table' => 'wp_global_purchase_policy',
            ),
            'sql_global_purchase_policy_item' => array(
                'description' => 'Chi tiết chính sách mua hàng',
                'table' => 'wp_global_purchase_policy_item',
            ),
            'sql_global_supplier' => array(
                'description' => 'Danh sách nhà cung cấp',
                'table' => 'wp_global_supplier',
            ),
        );

        // Validate từng bảng dựa trên schema từ class-tgs-database.php
        foreach ($tables as $key => $info) {
            if (isset($schema[$key])) {
                $has_updated = $schema[$key]['updated_at'];
                $has_deleted = $schema[$key]['deleted_at'];

                $missing = array();
                if (!$has_updated) $missing[] = 'updated_at';
                if (!$has_deleted) $missing[] = 'deleted_at';

                $tables[$key]['has_sync_columns'] = empty($missing);
                $tables[$key]['missing_columns'] = $missing;
            } else {
                // Bảng không tìm thấy trong class-tgs-database.php
                $tables[$key]['has_sync_columns'] = false;
                $tables[$key]['missing_columns'] = array('deleted_at (not defined in class-tgs-database.php)');
            }
        }

        return $tables;
    }

    /**
     * Get available LOCAL tables
     */
    private static function get_available_local_tables() {
        $schema = self::get_schema_from_class();

        $tables = array(
            'sql_local_ledger_person' => array(
                'description' => 'Khách hàng và nhà cung cấp',
                'table' => 'local_ledger_person',
            ),
            'sql_local_ledger' => array(
                'description' => 'Phiếu chứng từ (đơn hàng, phiếu nhập, xuất)',
                'table' => 'local_ledger',
            ),
            'sql_local_ledger_item' => array(
                'description' => 'Chi tiết items trong phiếu',
                'table' => 'local_ledger_item',
            ),
            'sql_local_ledger_meta' => array(
                'description' => 'Metadata cho ledger',
                'table' => 'local_ledger_meta',
            ),
            'sql_local_viettel_invoice' => array(
                'description' => 'Hóa đơn Viettel',
                'table' => 'local_viettel_invoice',
            ),
            'sql_local_viettel_invoice_log' => array(
                'description' => 'Log hóa đơn Viettel',
                'table' => 'local_viettel_invoice_log',
            ),
            'sql_local_zns_log' => array(
                'description' => 'Log gửi ZNS (Zalo Notification Service)',
                'table' => 'local_zns_log',
            ),
            'sql_local_person_loyalty_logs' => array(
                'description' => 'Log tích điểm khách hàng tại shop',
                'table' => 'local_person_loyalty_logs',
            ),
            'sql_local_htsoft_import_log' => array(
                'description' => 'Log import hóa đơn HTSoft',
                'table' => 'local_htsoft_import_log',
            ),
            'sql_global_loyalty_log' => array(
                'description' => 'Log tích điểm (bảng LOCAL multisite, có prefix blog)',
                'table' => 'global_loyalty_log',
            ),
            'sql_global_loyalty_policy' => array(
                'description' => 'Chính sách tích điểm (bảng LOCAL multisite, có prefix blog)',
                'table' => 'global_loyalty_policy',
            ),
        );

        // Validate từng bảng LOCAL dựa trên schema từ class-tgs-database.php
        foreach ($tables as $key => $info) {
            if (isset($schema[$key])) {
                $has_updated = $schema[$key]['updated_at'];
                $has_deleted = $schema[$key]['deleted_at'];

                $missing = array();
                if (!$has_updated) $missing[] = 'updated_at';
                if (!$has_deleted) $missing[] = 'deleted_at';

                $tables[$key]['has_sync_columns'] = empty($missing);
                $tables[$key]['missing_columns'] = $missing;
            } else {
                // Bảng không tìm thấy trong class-tgs-database.php
                $tables[$key]['has_sync_columns'] = false;
                $tables[$key]['missing_columns'] = array('deleted_at (not defined in class-tgs-database.php)');
            }
        }

        return $tables;
    }

    /**
     * Get current configuration
     */
    public static function get_config() {
        $default = array(
            'global' => array(
                'sql_global_product_name',
                'sql_global_product_cat',
                'sql_global_product_lots',
                'sql_global_selling_policy',
                'sql_global_selling_policy_items',
            ),
            'local_pull' => array(
                'sql_local_ledger_person',
                'sql_local_ledger',
                'sql_local_ledger_item',
                'sql_local_ledger_meta',
            ),
            'local_push' => array(
                'sql_local_ledger_person',
                'sql_local_ledger',
                'sql_local_ledger_item',
                'sql_local_ledger_meta',
            ),
        );

        $config = get_option(self::OPTION_SCHEMA_CONFIG, $default);
        return $config;
    }

    /**
     * Save configuration
     */
    private static function save_config($post_data) {
        $config = array(
            'global' => isset($post_data['global_tables']) ? array_map('sanitize_text_field', $post_data['global_tables']) : array(),
            'local_pull' => isset($post_data['local_pull_tables']) ? array_map('sanitize_text_field', $post_data['local_pull_tables']) : array(),
            'local_push' => isset($post_data['local_push_tables']) ? array_map('sanitize_text_field', $post_data['local_push_tables']) : array(),
        );

        update_option(self::OPTION_SCHEMA_CONFIG, $config);
        return $config;
    }
}
