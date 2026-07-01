<?php
/**
 * Conflict Resolver
 * UI để admin xử lý conflicts khi sync
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Conflict_Resolver {

    /**
     * Render conflict resolver page
     */
    public static function render() {
        if (!current_user_can('manage_network')) {
            wp_die(__('Bạn không có quyền truy cập trang này.', 'tgs-hub-api'));
        }

        // Handle form submission
        if (isset($_POST['resolve_conflict']) && check_admin_referer('tgs_resolve_conflict')) {
            self::handle_resolution($_POST);
        }

        $conflicts = self::get_conflicts();
        $stats = self::get_conflict_stats();

        include TGS_HUB_API_PLUGIN_DIR . 'admin/views/conflict-resolver.php';
    }

    /**
     * Get all pending conflicts
     */
    public static function get_conflicts($status = 'pending', $limit = 100) {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CONFLICTS;
        $clients_table = $wpdb->base_prefix . TGS_HUB_TABLE_CLIENTS;

        $query = "
            SELECT c.*, cl.client_name, cl.store_id, cl.branch_name
            FROM {$table} c
            LEFT JOIN {$clients_table} cl ON c.blog_id = cl.blog_id
            WHERE c.resolution_status = %s
            ORDER BY c.created_at DESC
            LIMIT %d
        ";

        return $wpdb->get_results(
            $wpdb->prepare($query, $status, $limit),
            ARRAY_A
        );
    }

    /**
     * Get conflict statistics
     */
    public static function get_conflict_stats() {
        global $wpdb;
        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CONFLICTS;

        $stats = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN resolution_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN resolution_status = 'resolved_use_local' THEN 1 ELSE 0 END) as resolved_local,
                SUM(CASE WHEN resolution_status = 'resolved_use_hub' THEN 1 ELSE 0 END) as resolved_hub,
                SUM(CASE WHEN resolution_status = 'resolved_manual' THEN 1 ELSE 0 END) as resolved_manual
            FROM {$table}
        ", ARRAY_A);

        return $stats;
    }

    /**
     * Handle conflict resolution
     */
    private static function handle_resolution($post_data) {
        global $wpdb;

        $conflict_id = (int) $post_data['conflict_id'];
        $resolution = sanitize_text_field($post_data['resolution']);
        $note = sanitize_textarea_field($post_data['resolution_note'] ?? '');

        if (!in_array($resolution, ['use_local', 'use_hub', 'manual'])) {
            return;
        }

        $table = $wpdb->base_prefix . TGS_HUB_TABLE_CONFLICTS;

        // Lấy thông tin conflict
        $conflict = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $conflict_id
        ), ARRAY_A);

        if (!$conflict) {
            return;
        }

        // Áp dụng resolution
        $applied = false;
        if ($resolution === 'use_local') {
            $applied = self::apply_local_data($conflict);
        } elseif ($resolution === 'use_hub') {
            // Không làm gì, giữ nguyên Hub data
            $applied = true;
        } elseif ($resolution === 'manual') {
            // Admin sẽ xử lý thủ công bên ngoài, chỉ mark resolved
            $applied = true;
        }

        if ($applied) {
            // Update resolution status
            $current_user = wp_get_current_user();
            $wpdb->update(
                $table,
                array(
                    'resolution_status' => 'resolved_' . $resolution,
                    'resolution_note' => $note,
                    'resolved_by' => $current_user->user_login,
                    'resolved_at' => current_time('mysql'),
                ),
                array('id' => $conflict_id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );

            echo '<div class="notice notice-success"><p>Đã giải quyết conflict #' . $conflict_id . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Không thể áp dụng resolution cho conflict #' . $conflict_id . '</p></div>';
        }
    }

    /**
     * Apply local data to Hub
     */
    private static function apply_local_data($conflict) {
        global $wpdb;

        $blog_id = $conflict['blog_id'];
        $table_name = $conflict['table_name'];
        $record_id = $conflict['record_id'];
        $local_data = json_decode($conflict['local_data'], true);

        if (empty($local_data)) {
            return false;
        }

        // Switch to target blog
        switch_to_blog($blog_id);

        // Tên bảng thực tế: wp_5_local_ledger
        $target_table = $wpdb->prefix . str_replace('wp_', '', $table_name);

        // Detect primary key
        $pk = self::get_primary_key($table_name);

        // Filter columns
        $clean_data = self::filter_columns($local_data, $target_table);

        // Check record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$target_table} WHERE {$pk} = %s",
            $record_id
        ));

        if ($exists) {
            // Update
            $result = $wpdb->update($target_table, $clean_data, array($pk => $record_id));
        } else {
            // Insert
            $result = $wpdb->insert($target_table, $clean_data);
        }

        restore_current_blog();

        return $result !== false;
    }

    /**
     * Get primary key của bảng
     */
    private static function get_primary_key($table_name) {
        $map = array(
            'wp_local_ledger' => 'local_ledger_id',
            'wp_local_ledger_item' => 'local_ledger_item_id',
            'wp_local_ledger_person' => 'local_ledger_person_id',
            'wp_local_ledger_meta' => 'local_ledger_meta_id',
        );

        return $map[$table_name] ?? 'id';
    }

    /**
     * Filter payload để chỉ giữ các cột tồn tại trong bảng
     */
    private static function filter_columns($data, $table_name) {
        global $wpdb;

        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        if (empty($columns)) {
            return array();
        }

        $filtered = array();
        foreach ($data as $key => $value) {
            if (in_array($key, $columns)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
