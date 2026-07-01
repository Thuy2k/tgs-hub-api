<?php
/**
 * Client List View
 * Giao diện danh sách cửa hàng
 */

if (!defined('ABSPATH')) {
    exit;
}

// Ensure $clients is defined
if (!isset($clients)) {
    $clients = array();
}
?>

<div class="wrap">
    <h1><?php _e('Quản lý Cửa hàng (POS Clients)', 'tgs-hub-api'); ?></h1>

    <div class="tgs-hub-stats" style="display: flex; gap: 20px; margin: 20px 0;">
        <div class="stat-box" style="background: #fff; padding: 20px; border-left: 4px solid #2271b1;">
            <h3 style="margin: 0 0 10px;"><?php echo count($clients); ?></h3>
            <p style="margin: 0; color: #646970;">Tổng số cửa hàng</p>
        </div>
        <div class="stat-box" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a;">
            <h3 style="margin: 0 0 10px;">
                <?php echo count(array_filter($clients, function($c) { return $c['is_registered'] && $c['is_active']; })); ?>
            </h3>
            <p style="margin: 0; color: #646970;">Đã đăng ký & Hoạt động</p>
        </div>
        <div class="stat-box" style="background: #fff; padding: 20px; border-left: 4px solid #dba617;">
            <h3 style="margin: 0 0 10px;">
                <?php echo count(array_filter($clients, function($c) { return !$c['is_registered']; })); ?>
            </h3>
            <p style="margin: 0; color: #646970;">Chưa đăng ký</p>
        </div>
        <div class="stat-box" style="background: #fff; padding: 20px; border-left: 4px solid #d63638;">
            <h3 style="margin: 0 0 10px;">
                <?php echo count(array_filter($clients, function($c) { return $c['is_registered'] && !$c['is_active']; })); ?>
            </h3>
            <p style="margin: 0; color: #646970;">Đã tắt</p>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Blog ID', 'tgs-hub-api'); ?></th>
                <th><?php _e('Store ID', 'tgs-hub-api'); ?></th>
                <th><?php _e('Chi nhánh', 'tgs-hub-api'); ?></th>
                <th><?php _e('Trạng thái', 'tgs-hub-api'); ?></th>
                <th><?php _e('Lần sync cuối', 'tgs-hub-api'); ?></th>
                <th><?php _e('Hành động', 'tgs-hub-api'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($clients)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px;">
                        <?php _e('Chưa có cửa hàng nào đăng ký.', 'tgs-hub-api'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($clients as $client): ?>
                    <?php $stats = TGS_Hub_Client_Manager::get_sync_stats($client['blog_id']); ?>
                    <tr>
                        <td><strong><?php echo esc_html($client['blog_id']); ?></strong></td>
                        <td><?php echo esc_html($client['store_id']); ?></td>
                        <td><?php echo esc_html($client['branch_name']); ?></td>
                        <td>
                            <?php if ($client['is_active']): ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                <?php _e('Hoạt động', 'tgs-hub-api'); ?>
                            <?php else: ?>
                                <span class="dashicons dashicons-dismiss" style="color: #d63638;"></span>
                                <?php _e('Tắt', 'tgs-hub-api'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            if ($stats['last_sync_at']) {
                                echo esc_html(human_time_diff(strtotime($stats['last_sync_at']), current_time('timestamp'))) . ' ' . __('trước', 'tgs-hub-api');
                            } else {
                                echo '<em>' . __('Chưa sync', 'tgs-hub-api') . '</em>';
                            }
                            ?>
                            <br>
                            <small style="color: #646970;">
                                <?php printf(__('%d events, %d errors', 'tgs-hub-api'), $stats['total_events'], $stats['errors']); ?>
                            </small>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=tgs-hub-sync&blog_id=' . $client['blog_id'])); ?>"
                               class="button button-small">
                                <?php _e('Xem logs', 'tgs-hub-api'); ?>
                            </a>
                            <button class="button button-small tgs-generate-qr"
                                    data-blog-id="<?php echo esc_attr($client['blog_id']); ?>"
                                    data-store-id="<?php echo esc_attr($client['store_id']); ?>"
                                    data-branch-name="<?php echo esc_attr($client['branch_name']); ?>">
                                <?php _e('Tạo QR mới', 'tgs-hub-api'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- QR Code Modal -->
<div id="tgs-qr-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 999999; align-items: center; justify-content: center;">
    <div style="background: #fff; padding: 40px; border-radius: 8px; max-width: 500px; text-align: center;">
        <h2><?php _e('QR Code Đăng ký', 'tgs-hub-api'); ?></h2>
        <div id="tgs-qr-content"></div>
        <p><small><?php _e('Quét mã này trên máy Local POS để kết nối.', 'tgs-hub-api'); ?></small></p>
        <button class="button button-primary" onclick="document.getElementById('tgs-qr-modal').style.display='none';">
            <?php _e('Đóng', 'tgs-hub-api'); ?>
        </button>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var ajaxurl = typeof tgsHub !== 'undefined' ? tgsHub.ajaxurl : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
    var nonce = typeof tgsHub !== 'undefined' ? tgsHub.nonce : '<?php echo wp_create_nonce('tgs_hub_generate_qr'); ?>';

    $('.tgs-generate-qr').on('click', function() {
        var btn = $(this);
        var blogId = btn.data('blog-id');
        var storeId = btn.data('store-id');
        var branchName = btn.data('branch-name');

        btn.prop('disabled', true).text('Đang tạo...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'tgs_hub_generate_qr',
                nonce: nonce,
                blog_id: blogId,
                store_id: storeId,
                branch_name: branchName
            },
            success: function(response) {
                console.log('Response:', response);
                if (response.success) {
                    var qrData = JSON.parse(response.data.qr_data); // Parse qr_data thay vì token
                    var setupToken = response.data.token;

                    $('#tgs-qr-content').html(
                        '<img src="' + response.data.qr_code_url + '" style="max-width: 300px; margin: 20px auto; display: block;" onerror="this.style.display=\'none\'">' +
                        '<div style="text-align: left; background: #f6f7f7; padding: 15px; border-radius: 4px; margin: 20px 0;">' +
                        '<p><strong>JSON để đăng ký:</strong></p>' +
                        '<textarea readonly style="width: 100%; height: 150px; font-family: monospace; font-size: 11px; padding: 10px;">' +
                        JSON.stringify({
                            hub_url: qrData.hub_url.replace('/wp-json/tgs-hub/v1', ''),
                            setup_token: qrData.setup_token,
                            blog_id: qrData.blog_id,
                            store_id: qrData.store_id
                        }, null, 2) +
                        '</textarea>' +
                        '</div>' +
                        '<p><strong>Setup Token:</strong><br><code style="word-break: break-all; font-size: 11px;">' + setupToken + '</code></p>' +
                        '<p><small>Hết hạn: ' + response.data.expires_at + '</small></p>'
                    );
                    $('#tgs-qr-modal').css('display', 'flex');
                } else {
                    alert('Lỗi: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', xhr, status, error);
                alert('AJAX Error: ' + error + '\nStatus: ' + xhr.status + '\nResponse: ' + xhr.responseText);
            },
            complete: function() {
                btn.prop('disabled', false).text('Tạo QR mới');
            }
        });
    });
});
</script>
