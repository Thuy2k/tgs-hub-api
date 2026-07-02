<?php
/**
 * Test Schema Validation V2
 *
 * Usage: wp-admin/admin.php?page=tgs-test-schema-v2
 */

require_once(__DIR__ . '/admin/class-schema-config-v2.php');

add_action('admin_menu', function() {
    add_submenu_page(
        'tgs-pos-schema',
        'Test Schema V2',
        'Test Schema V2',
        'manage_options',
        'tgs-test-schema-v2',
        'tgs_render_test_schema_v2_page'
    );
});

function tgs_render_test_schema_v2_page() {
    ?>
    <div class="wrap">
        <h1>Test Schema Validation V2</h1>
        <p>Kiểm tra schema từ class-tgs-database.php và so sánh với DB thực tế.</p>

        <?php
        $results = TGS_Schema_Config_V2::validate_all_tables();

        echo '<h2>Kết quả kiểm tra (' . count($results) . ' bảng)</h2>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Method</th>';
        echo '<th>Tên bảng</th>';
        echo '<th>Trạng thái</th>';
        echo '<th>Cột thiếu</th>';
        echo '<th>Tổng cột trong schema</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $count_ok = 0;
        $count_missing = 0;
        $count_not_exists = 0;

        foreach ($results as $method => $info) {
            $status_class = '';
            $status_text = '';

            if (!$info['exists']) {
                $status_class = 'style="background-color: #ffcccc;"';
                $status_text = '❌ Bảng chưa tồn tại';
                $count_not_exists++;
            } elseif ($info['has_all']) {
                $status_class = 'style="background-color: #ccffcc;"';
                $status_text = '✓ Đầy đủ';
                $count_ok++;
            } else {
                $status_class = 'style="background-color: #ffffcc;"';
                $status_text = '⚠ Thiếu cột sync';
                $count_missing++;
            }

            echo '<tr ' . $status_class . '>';
            echo '<td><code>' . esc_html($method) . '</code></td>';
            echo '<td><code>' . esc_html($info['table']) . '</code></td>';
            echo '<td>' . $status_text . '</td>';
            echo '<td>' . (empty($info['missing']) ? '-' : '<strong style="color:red;">' . implode(', ', $info['missing']) . '</strong>') . '</td>';
            echo '<td>' . count($info['required_columns']) . ' cột</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '<tfoot><tr>';
        echo '<th colspan="5">';
        echo 'Tổng: <strong style="color:green;">' . $count_ok . ' OK</strong>, ';
        echo '<strong style="color:orange;">' . $count_missing . ' thiếu cột</strong>, ';
        echo '<strong style="color:red;">' . $count_not_exists . ' chưa tồn tại</strong>';
        echo '</th>';
        echo '</tr></tfoot>';
        echo '</table>';
        ?>
    </div>
    <?php
}
