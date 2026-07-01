<?php
/**
 * Sync Detail View
 * Giao diện chi tiết sync logs của 1 cửa hàng
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>
        <a href="<?php echo esc_url(admin_url('admin.php?page=tgs-hub-sync')); ?>" class="dashicons dashicons-arrow-left-alt2" style="text-decoration: none;"></a>
        <?php printf(__('Push Logs - %s', 'tgs-hub-api'), esc_html($client['store_id'])); ?>
    </h1>

    <div style="background: #fff; padding: 20px; margin: 20px 0; border-left: 4px solid #2271b1;">
        <h3 style="margin-top: 0;"><?php _e('Thông tin cửa hàng', 'tgs-hub-api'); ?></h3>
        <table class="form-table">
            <tr>
                <th><?php _e('Blog ID:', 'tgs-hub-api'); ?></th>
                <td><strong><?php echo esc_html($client['blog_id']); ?></strong></td>
            </tr>
            <tr>
                <th><?php _e('Store ID:', 'tgs-hub-api'); ?></th>
                <td><?php echo esc_html($client['store_id']); ?></td>
            </tr>
            <tr>
                <th><?php _e('Chi nhánh:', 'tgs-hub-api'); ?></th>
                <td><?php echo esc_html($client['branch_name']); ?></td>
            </tr>
            <tr>
                <th><?php _e('Trạng thái:', 'tgs-hub-api'); ?></th>
                <td>
                    <?php if ($client['is_active']): ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php _e('Đang hoạt động', 'tgs-hub-api'); ?>
                    <?php else: ?>
                        <span class="dashicons dashicons-dismiss" style="color: #d63638;"></span>
                        <?php _e('Không hoạt động', 'tgs-hub-api'); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php _e('Đăng ký lúc:', 'tgs-hub-api'); ?></th>
                <td><?php echo esc_html($client['created_at']); ?></td>
            </tr>
        </table>

        <h4><?php _e('Thống kê Sync', 'tgs-hub-api'); ?></h4>
        <div style="display: flex; gap: 20px; margin-top: 10px;">
            <div style="flex: 1; padding: 15px; background: #f6f7f7; border-radius: 4px;">
                <div style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo number_format($stats['total_events']); ?></div>
                <div style="color: #646970; font-size: 13px;"><?php _e('Tổng events', 'tgs-hub-api'); ?></div>
            </div>
            <div style="flex: 1; padding: 15px; background: #f6f7f7; border-radius: 4px;">
                <div style="font-size: 24px; font-weight: bold; color: #00a32a;"><?php echo number_format($stats['applied']); ?></div>
                <div style="color: #646970; font-size: 13px;"><?php _e('Thành công', 'tgs-hub-api'); ?></div>
            </div>
            <div style="flex: 1; padding: 15px; background: #f6f7f7; border-radius: 4px;">
                <div style="font-size: 24px; font-weight: bold; color: #d63638;"><?php echo number_format($stats['errors']); ?></div>
                <div style="color: #646970; font-size: 13px;"><?php _e('Lỗi', 'tgs-hub-api'); ?></div>
            </div>
            <div style="flex: 1; padding: 15px; background: #f6f7f7; border-radius: 4px;">
                <div style="font-size: 14px; font-weight: bold; color: #646970;">
                    <?php
                    if ($stats['last_sync_at']) {
                        echo esc_html(human_time_diff(strtotime($stats['last_sync_at']), current_time('timestamp'))) . ' ' . __('trước', 'tgs-hub-api');
                    } else {
                        echo __('Chưa sync', 'tgs-hub-api');
                    }
                    ?>
                </div>
                <div style="color: #646970; font-size: 13px;"><?php _e('Lần sync cuối', 'tgs-hub-api'); ?></div>
            </div>
        </div>
    </div>

    <h2><?php _e('Sync Logs (50 gần nhất)', 'tgs-hub-api'); ?></h2>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 180px;"><?php _e('Thời gian', 'tgs-hub-api'); ?></th>
                <th style="width: 120px;"><?php _e('Direction', 'tgs-hub-api'); ?></th>
                <th style="width: 100px;"><?php _e('Status', 'tgs-hub-api'); ?></th>
                <th style="width: 200px;"><?php _e('Event ID', 'tgs-hub-api'); ?></th>
                <th><?php _e('Payload', 'tgs-hub-api'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 40px;">
                        <?php _e('Chưa có log nào.', 'tgs-hub-api'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log['created_at']); ?></td>
                        <td>
                            <?php if ($log['sync_direction'] === 'push'): ?>
                                <span class="dashicons dashicons-upload" style="color: #2271b1;"></span> Push
                            <?php else: ?>
                                <span class="dashicons dashicons-download" style="color: #00a32a;"></span> Pull
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $status_colors = array(
                                'applied' => '#00a32a',
                                'pending' => '#dba617',
                                'error' => '#d63638',
                            );
                            $color = $status_colors[$log['sync_status']] ?? '#646970';
                            ?>
                            <span style="display: inline-block; padding: 3px 8px; background: <?php echo $color; ?>15; color: <?php echo $color; ?>; border-radius: 3px; font-size: 12px; font-weight: 500;">
                                <?php echo esc_html(strtoupper($log['sync_status'])); ?>
                            </span>
                        </td>
                        <td>
                            <code style="font-size: 11px;"><?php echo esc_html($log['event_id']); ?></code>
                        </td>
                        <td>
                            <details>
                                <summary style="cursor: pointer; color: #2271b1;"><?php _e('Xem payload', 'tgs-hub-api'); ?></summary>
                                <pre style="margin-top: 10px; padding: 10px; background: #f6f7f7; overflow-x: auto; font-size: 11px;"><?php echo esc_html(json_encode(json_decode($log['payload']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
