<?php
/**
 * Sync Overview View
 * Tổng quan sync logs tất cả cửa hàng
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Theo dõi Sync - Tất cả cửa hàng', 'tgs-hub-api'); ?></h1>

    <p><?php _e('Hiển thị 100 sync logs gần nhất từ tất cả cửa hàng.', 'tgs-hub-api'); ?></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 80px;"><?php _e('Blog ID', 'tgs-hub-api'); ?></th>
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
                    <td colspan="6" style="text-align: center; padding: 40px;">
                        <?php _e('Chưa có log nào.', 'tgs-hub-api'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=tgs-hub-sync&blog_id=' . $log['blog_id'])); ?>">
                                <strong><?php echo esc_html($log['blog_id']); ?></strong>
                            </a>
                        </td>
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
