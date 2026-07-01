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
                <table class="widefat">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="check-all-local-pull" />
                            </th>
                            <th>Tên bảng</th>
                            <th>Mô tả</th>
                            <th style="width: 100px; text-align: center;">
                                Cho phép PUSH
                                <br><input type="checkbox" id="check-all-local-push" style="margin-top: 5px;" />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $local_tables = self::get_available_local_tables();
                        foreach ($local_tables as $table => $info) {
                            $checked_pull = in_array($table, $config['local']) ? 'checked' : '';
                            $checked_push = in_array($table, $config['local_push'] ?? array()) ? 'checked' : '';
                            $disabled = !$info['has_sync_columns'] ? 'disabled' : '';
                            $row_class = !$info['has_sync_columns'] ? 'style="background-color: #ffe6e6;"' : '';

                            echo '<tr ' . $row_class . '>';
                            echo '<td><input type="checkbox" name="local_tables[]" value="' . esc_attr($table) . '" ' . $checked_pull . ' ' . $disabled . ' /></td>';
                            echo '<td><code>' . esc_html($table) . '</code></td>';
                            echo '<td>' . esc_html($info['description']);

                            if (!$info['has_sync_columns']) {
                                echo '<br><span style="color: red; font-weight: bold;">⚠️ Thiếu cột: ' . implode(', ', $info['missing_columns']) . '</span>';
                                echo '<br><em style="color: #666;">Không thể sync - cần thêm updated_at và deleted_at vào bảng</em>';
                            }

                            echo '</td>';
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
                $('input[name="local_tables[]"]').prop('checked', this.checked);
            });
            $('#check-all-local-push').on('change', function() {
                $('input[name="local_push_tables[]"]').prop('checked', this.checked);
            });
        });
        </script>
        <?php
    }

    /**
     * Get available GLOBAL tables
     */
    private static function get_available_global_tables() {
        global $wpdb;

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

        // Validate từng bảng: check có updated_at và deleted_at không
        foreach ($tables as $key => $info) {
            $validation = self::validate_table_columns($info['table']);
            $tables[$key]['has_sync_columns'] = $validation['valid'];
            $tables[$key]['missing_columns'] = $validation['missing'];
        }

        return $tables;
    }

    /**
     * Get available LOCAL tables
     */
    private static function get_available_local_tables() {
        global $wpdb;

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

        // Validate từng bảng LOCAL (dùng blog_id = 2 làm sample)
        foreach ($tables as $key => $info) {
            $blog_prefix = $wpdb->get_blog_prefix(2); // Sample blog 2
            $full_table = $blog_prefix . $info['table'];
            $validation = self::validate_table_columns($full_table);
            $tables[$key]['has_sync_columns'] = $validation['valid'];
            $tables[$key]['missing_columns'] = $validation['missing'];
        }

        return $tables;
    }

    /**
     * Validate bảng có updated_at và deleted_at không
     */
    private static function validate_table_columns($table_name) {
        global $wpdb;

        // Check bảng tồn tại
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if (!$exists) {
            return array(
                'valid' => false,
                'missing' => array('Table not exists'),
            );
        }

        // Lấy danh sách cột
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        $required = array('updated_at', 'deleted_at');
        $missing = array();

        foreach ($required as $col) {
            if (!in_array($col, $columns)) {
                $missing[] = $col;
            }
        }

        return array(
            'valid' => empty($missing),
            'missing' => $missing,
        );
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
            'local' => array(
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
            'local' => isset($post_data['local_tables']) ? array_map('sanitize_text_field', $post_data['local_tables']) : array(),
            'local_push' => isset($post_data['local_push_tables']) ? array_map('sanitize_text_field', $post_data['local_push_tables']) : array(),
        );

        update_option(self::OPTION_SCHEMA_CONFIG, $config);
        return $config;
    }
}
