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
            <p>Chọn bảng nào cho phép shop kéo về khi pull schema</p>

            <form method="post" action="">
                <?php wp_nonce_field('tgs_schema_config'); ?>

                <h2>Bảng GLOBAL (dùng chung toàn hệ thống)</h2>
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
                            echo '<tr>';
                            echo '<td><input type="checkbox" name="global_tables[]" value="' . esc_attr($table) . '" ' . $checked . ' /></td>';
                            echo '<td><code>' . esc_html($table) . '</code></td>';
                            echo '<td>' . esc_html($info['description']) . '</td>';
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
                                <input type="checkbox" id="check-all-local" />
                            </th>
                            <th>Tên bảng</th>
                            <th>Mô tả</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $local_tables = self::get_available_local_tables();
                        foreach ($local_tables as $table => $info) {
                            $checked = in_array($table, $config['local']) ? 'checked' : '';
                            echo '<tr>';
                            echo '<td><input type="checkbox" name="local_tables[]" value="' . esc_attr($table) . '" ' . $checked . ' /></td>';
                            echo '<td><code>' . esc_html($table) . '</code></td>';
                            echo '<td>' . esc_html($info['description']) . '</td>';
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
            $('#check-all-local').on('change', function() {
                $('input[name="local_tables[]"]').prop('checked', this.checked);
            });
        });
        </script>
        <?php
    }

    /**
     * Get available GLOBAL tables
     */
    private static function get_available_global_tables() {
        return array(
            'sql_global_product_name' => array(
                'description' => 'Catalog sản phẩm toàn hệ thống',
            ),
            'sql_global_product_cat' => array(
                'description' => 'Danh mục sản phẩm',
            ),
            'sql_global_product_lots' => array(
                'description' => 'Lô hàng và HSD',
            ),
            'sql_global_selling_policy' => array(
                'description' => 'Chính sách bán hàng',
            ),
            'sql_global_selling_policy_items' => array(
                'description' => 'Chi tiết chính sách bán hàng',
            ),
            'sql_global_purchase_policy' => array(
                'description' => 'Chính sách mua hàng',
            ),
            'sql_global_purchase_policy_item' => array(
                'description' => 'Chi tiết chính sách mua hàng',
            ),
            'sql_global_supplier' => array(
                'description' => 'Danh sách nhà cung cấp',
            ),
        );
    }

    /**
     * Get available LOCAL tables
     */
    private static function get_available_local_tables() {
        return array(
            'sql_local_ledger_person' => array(
                'description' => 'Khách hàng và nhà cung cấp',
            ),
            'sql_local_ledger' => array(
                'description' => 'Phiếu chứng từ (đơn hàng, phiếu nhập, xuất)',
            ),
            'sql_local_ledger_item' => array(
                'description' => 'Chi tiết items trong phiếu',
            ),
            'sql_local_ledger_meta' => array(
                'description' => 'Metadata cho ledger',
            ),
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
        );

        update_option(self::OPTION_SCHEMA_CONFIG, $config);
        return $config;
    }
}
