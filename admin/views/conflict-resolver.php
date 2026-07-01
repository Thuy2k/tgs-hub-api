<?php
/**
 * Conflict Resolver View
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>Giải quyết Conflicts</h1>
    <p>Xử lý các xung đột dữ liệu khi Local PUSH lên Hub</p>

    <!-- Statistics -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0;">
        <div style="background: #fff; padding: 20px; border-left: 4px solid #dc3545; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="font-size: 32px; font-weight: bold; color: #dc3545;"><?php echo $stats['pending']; ?></div>
            <div style="color: #666; margin-top: 5px;">Chưa xử lý</div>
        </div>
        <div style="background: #fff; padding: 20px; border-left: 4px solid #28a745; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo $stats['resolved_local']; ?></div>
            <div style="color: #666; margin-top: 5px;">Dùng Local</div>
        </div>
        <div style="background: #fff; padding: 20px; border-left: 4px solid #007bff; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="font-size: 32px; font-weight: bold; color: #007bff;"><?php echo $stats['resolved_hub']; ?></div>
            <div style="color: #666; margin-top: 5px;">Dùng Hub</div>
        </div>
        <div style="background: #fff; padding: 20px; border-left: 4px solid #6c757d; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="font-size: 32px; font-weight: bold; color: #6c757d;"><?php echo $stats['resolved_manual']; ?></div>
            <div style="color: #666; margin-top: 5px;">Xử lý thủ công</div>
        </div>
    </div>

    <?php if (empty($conflicts)): ?>
        <div class="notice notice-success">
            <p><strong>✓ Không có conflict nào cần xử lý!</strong></p>
        </div>
    <?php else: ?>

        <!-- Conflicts List -->
        <table class="widefat" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>Cửa hàng</th>
                    <th>Bảng</th>
                    <th>Record ID</th>
                    <th>Loại conflict</th>
                    <th style="width: 180px;">Thời gian</th>
                    <th style="width: 120px;">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conflicts as $conflict): ?>
                    <tr>
                        <td><strong>#<?php echo $conflict['id']; ?></strong></td>
                        <td>
                            <strong><?php echo esc_html($conflict['client_name'] ?? 'N/A'); ?></strong>
                            <br><small style="color: #666;"><?php echo esc_html($conflict['store_id'] ?? ''); ?> - Blog <?php echo $conflict['blog_id']; ?></small>
                        </td>
                        <td><code><?php echo esc_html($conflict['table_name']); ?></code></td>
                        <td><?php echo $conflict['record_id']; ?></td>
                        <td>
                            <?php
                            $type_labels = array(
                                'insert_duplicate' => '<span style="color: #856404;">Insert trùng</span>',
                                'update_outdated' => '<span style="color: #dc3545;">Update cũ hơn Hub</span>',
                                'delete_missing' => '<span style="color: #6c757d;">Delete record không tồn tại</span>',
                            );
                            echo $type_labels[$conflict['conflict_type']] ?? $conflict['conflict_type'];
                            ?>
                        </td>
                        <td>
                            <div><strong>Local:</strong> <?php echo $conflict['local_updated_at'] ?: 'N/A'; ?></div>
                            <div><strong>Hub:</strong> <?php echo $conflict['hub_updated_at'] ?: 'N/A'; ?></div>
                        </td>
                        <td>
                            <button type="button" class="button button-small" onclick="showConflictDetail(<?php echo $conflict['id']; ?>)">
                                Xem chi tiết
                            </button>
                        </td>
                    </tr>

                    <!-- Detail Row (Hidden by default) -->
                    <tr id="conflict-detail-<?php echo $conflict['id']; ?>" style="display: none; background: #f9f9f9;">
                        <td colspan="7" style="padding: 20px;">
                            <h3>Chi tiết Conflict #<?php echo $conflict['id']; ?></h3>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                                <div>
                                    <h4>Dữ liệu từ Local (Shop)</h4>
                                    <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; max-height: 300px; overflow: auto;"><?php echo esc_html(json_encode(json_decode($conflict['local_data'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                </div>
                                <div>
                                    <h4>Dữ liệu hiện tại ở Hub</h4>
                                    <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; max-height: 300px; overflow: auto;"><?php echo $conflict['hub_data'] ? esc_html(json_encode(json_decode($conflict['hub_data'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) : '<em>Không có data</em>'; ?></pre>
                                </div>
                            </div>

                            <form method="post" action="" style="border-top: 2px solid #ddd; padding-top: 20px;">
                                <?php wp_nonce_field('tgs_resolve_conflict'); ?>
                                <input type="hidden" name="conflict_id" value="<?php echo $conflict['id']; ?>" />

                                <h4>Chọn cách giải quyết:</h4>

                                <label style="display: block; margin: 10px 0; padding: 10px; border: 2px solid #ddd; cursor: pointer;">
                                    <input type="radio" name="resolution" value="use_local" required />
                                    <strong>Dùng dữ liệu từ Local</strong> - Ghi đè data Hub bằng data từ Shop
                                </label>

                                <label style="display: block; margin: 10px 0; padding: 10px; border: 2px solid #ddd; cursor: pointer;">
                                    <input type="radio" name="resolution" value="use_hub" required />
                                    <strong>Giữ nguyên dữ liệu Hub</strong> - Bỏ qua data từ Shop, yêu cầu Shop PULL lại
                                </label>

                                <label style="display: block; margin: 10px 0; padding: 10px; border: 2px solid #ddd; cursor: pointer;">
                                    <input type="radio" name="resolution" value="manual" required />
                                    <strong>Xử lý thủ công</strong> - Đánh dấu đã xử lý, tôi sẽ sửa trực tiếp database
                                </label>

                                <div style="margin: 20px 0;">
                                    <label><strong>Ghi chú (optional):</strong></label>
                                    <textarea name="resolution_note" rows="3" style="width: 100%; margin-top: 5px;" placeholder="Lý do hoặc ghi chú về cách xử lý..."></textarea>
                                </div>

                                <button type="submit" name="resolve_conflict" class="button button-primary">
                                    Áp dụng giải pháp
                                </button>
                                <button type="button" class="button" onclick="hideConflictDetail(<?php echo $conflict['id']; ?>)">
                                    Hủy
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

    <!-- Resolved Conflicts -->
    <h2 style="margin-top: 40px;">Conflicts đã giải quyết (10 gần nhất)</h2>
    <?php
    $resolved = TGS_Hub_Conflict_Resolver::get_conflicts('resolved_use_local', 10);
    $resolved = array_merge($resolved, TGS_Hub_Conflict_Resolver::get_conflicts('resolved_use_hub', 10));
    $resolved = array_merge($resolved, TGS_Hub_Conflict_Resolver::get_conflicts('resolved_manual', 10));

    // Sort by resolved_at DESC
    usort($resolved, function($a, $b) {
        return strcmp($b['resolved_at'] ?? '', $a['resolved_at'] ?? '');
    });
    $resolved = array_slice($resolved, 0, 10);
    ?>

    <?php if (!empty($resolved)): ?>
        <table class="widefat">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Cửa hàng</th>
                    <th>Bảng</th>
                    <th>Loại</th>
                    <th>Giải pháp</th>
                    <th>Người xử lý</th>
                    <th>Thời gian</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resolved as $item): ?>
                    <tr style="opacity: 0.7;">
                        <td>#<?php echo $item['id']; ?></td>
                        <td><?php echo esc_html($item['client_name'] ?? 'N/A'); ?></td>
                        <td><code><?php echo esc_html($item['table_name']); ?></code></td>
                        <td><?php echo $item['conflict_type']; ?></td>
                        <td><strong><?php echo str_replace('resolved_', '', $item['resolution_status']); ?></strong></td>
                        <td><?php echo $item['resolved_by'] ?: 'N/A'; ?></td>
                        <td><?php echo $item['resolved_at']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
function showConflictDetail(id) {
    document.getElementById('conflict-detail-' + id).style.display = 'table-row';
}

function hideConflictDetail(id) {
    document.getElementById('conflict-detail-' + id).style.display = 'none';
}
</script>

<style>
.widefat th {
    background: #f0f0f1;
    font-weight: 600;
}
.widefat td {
    vertical-align: top;
}
label:has(input[type="radio"]:checked) {
    background: #e7f3ff;
    border-color: #007bff !important;
}
</style>
