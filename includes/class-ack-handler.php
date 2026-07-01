<?php
/**
 * ACK Handler
 * Xử lý ACK từ Local xác nhận đã sync
 *
 * @package TGS_Hub_API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Hub_Ack_Handler {

    /**
     * Handle ACK request
     *
     * Request body:
     * {
     *   "synced_event_ids": ["evt_xxx", "evt_yyy"],
     *   "applied_change_ids": ["chg_001", "chg_002"]
     * }
     */
    public static function handle($request) {
        $client = $request->get_param('_tgs_client');
        $synced_event_ids = $request->get_param('synced_event_ids') ?: array();
        $applied_change_ids = $request->get_param('applied_change_ids') ?: array();

        $acknowledged = 0;

        // Update sync log (nếu cần track ACK)
        // Hiện tại đơn giản chỉ trả success

        $acknowledged = count($synced_event_ids) + count($applied_change_ids);

        return new WP_REST_Response(array(
            'success' => true,
            'acknowledged' => $acknowledged,
        ), 200);
    }
}
