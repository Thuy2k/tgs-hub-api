# TGS Hub API Plugin

Plugin WordPress Multisite cho hệ thống POS Local-First. Đây là **Hub trung tâm** nhận đồng bộ từ 650+ cửa hàng.

## Tổng quan

- **Vai trò**: Hub API trung tâm (Server)
- **Mục tiêu**: Quản lý 650 cửa hàng (70 Phú Thọ trong Phase 1)
- **Kiến trúc**: Event-based sync, không phải raw database replication
- **Giao thức**: REST API qua HTTPS
- **Xác thực**: Token-based (setup_token → client_token)

## Cài đặt

1. Copy plugin vào `wp-content/plugins/tgs-hub-api/`
2. Kích hoạt plugin ở Network Admin
3. Plugin tự động tạo 2 bảng database:
   - `wp_tgs_hub_clients` - Danh sách 650 clients
   - `wp_tgs_sync_log` - Log tất cả sync events

## Cấu trúc Plugin

```
tgs-hub-api/
├── tgs-hub-api.php                  # Main plugin file
├── includes/
│   ├── class-database.php           # Tạo database schema
│   ├── class-rest-api.php           # Đăng ký 5 REST endpoints
│   ├── class-auth-handler.php       # Xác thực QR Code token
│   ├── class-token-generator.php    # Tạo setup_token + QR Code
│   ├── class-push-handler.php       # Xử lý Local→Hub (orders, customers)
│   ├── class-pull-handler.php       # Xử lý Hub→Local (products, policies)
│   ├── class-ack-handler.php        # Xác nhận sync thành công
│   ├── class-idempotency.php        # Chống duplicate khi retry
│   ├── class-client-manager.php     # Quản lý 650 clients
│   └── class-sync-coordinator.php   # Xử lý conflict (TODO Phase 2)
└── admin/
    ├── class-client-dashboard.php   # Dashboard quản lý cửa hàng
    ├── class-sync-monitor.php       # Monitor sync logs
    └── views/
        ├── client-list.php          # Danh sách 650 cửa hàng
        ├── sync-detail.php          # Chi tiết sync của 1 cửa hàng
        └── sync-overview.php        # Tổng quan sync tất cả cửa hàng
```

## REST API Endpoints

### 1. `POST /wp-json/tgs-hub/v1/auth/register` (Public)
**Đăng ký client mới bằng QR Code**

Request:
```json
{
  "setup_token": "abc123..."
}
```

Response:
```json
{
  "success": true,
  "client_token": "def456...",
  "blog_id": 5,
  "store_id": "PT001",
  "hub_url": "https://hub.tgsworld.vn"
}
```

### 2. `POST /wp-json/tgs-hub/v1/sync/push` (Authenticated)
**Local→Hub: Đẩy orders, customers lên Hub**

Headers:
```
Authorization: Bearer {client_token}
```

Request:
```json
{
  "events": [
    {
      "event_id": "evt_001",
      "event_type": "order_created",
      "table_name": "wp_local_ledger",
      "operation": "INSERT",
      "data": {...},
      "timestamp": "2026-07-01T10:30:00Z"
    }
  ]
}
```

Response:
```json
{
  "success": true,
  "applied": 1,
  "failed": 0
}
```

### 3. `GET /wp-json/tgs-hub/v1/sync/pull` (Authenticated)
**Hub→Local: Lấy sản phẩm, chính sách từ Hub**

Headers:
```
Authorization: Bearer {client_token}
```

Query params:
```
?since_version=123
```

Response:
```json
{
  "changes": [
    {
      "change_id": "chg_001",
      "table_name": "wp_global_product_name",
      "operation": "UPDATE",
      "data": {...},
      "version": 124
    }
  ],
  "latest_version": 124
}
```

### 4. `POST /wp-json/tgs-hub/v1/sync/ack` (Authenticated)
**ACK: Xác nhận đã sync thành công**

Headers:
```
Authorization: Bearer {client_token}
```

Request:
```json
{
  "synced_event_ids": ["evt_001"],
  "applied_change_ids": ["chg_001"]
}
```

### 5. `POST /wp-json/tgs-hub/v1/device/heartbeat` (Authenticated)
**Heartbeat: Cập nhật trạng thái online**

Headers:
```
Authorization: Bearer {client_token}
```

## Admin UI

### Network Admin → TGS Hub → Cửa hàng
- Danh sách 650 cửa hàng
- Thống kê: Tổng số / Đang hoạt động / Không hoạt động
- Tạo QR Code cho từng cửa hàng
- Xem sync logs của từng cửa hàng

### Network Admin → TGS Hub → Giám sát Sync
- Xem sync logs tất cả cửa hàng (100 gần nhất)
- Filter theo blog_id, status
- Chi tiết: blog_id, direction (push/pull), status, event_id, payload

## Workflow Đăng ký

1. **Admin tạo QR Code**
   - Vào Network Admin → TGS Hub → Cửa hàng
   - Click "Tạo QR mới" cho blog_id tương ứng
   - QR chứa: `setup_token`, `blog_id`, `store_id`, `hub_url`

2. **Local quét QR Code**
   - Local gọi `POST /auth/register` với `setup_token`
   - Hub verify token → trả về `client_token`
   - Local lưu `client_token` vào local config

3. **Local bắt đầu sync**
   - Mỗi request đính kèm `Authorization: Bearer {client_token}`
   - Hub verify token → `switch_to_blog($blog_id)` → xử lý sync

## Bảo mật

- **HTTPS bắt buộc** cho production
- **Token-based auth**: setup_token (1 lần) → client_token (dài hạn)
- **Idempotency**: Kiểm tra `event_id` đã xử lý chưa
- **Permission**: Chỉ Network Admin mới thấy menu TGS Hub
- **Nonce**: AJAX generate QR Code có nonce protection

## Phase 1 MVP - Chỉ sync 3 bảng

### Local→Hub (Push)
- `wp_local_ledger` - Đơn hàng
- `wp_local_ledger_item` - Chi tiết đơn hàng
- `wp_local_ledger_person` - Khách hàng

### Hub→Local (Pull)
- `wp_global_product_name` - Danh sách sản phẩm
- `wp_global_product_cat` - Danh mục sản phẩm
- `wp_global_selling_policy` - Chính sách bán hàng

## TODO Phase 2+
- Inventory sync (tồn kho)
- Loyalty program sync (tích điểm)
- Conflict resolution (xử lý xung đột)
- Webhook notifications
- Bulk operations
- Performance monitoring

## Yêu cầu hệ thống

- WordPress 5.8+
- PHP 7.4+
- MySQL 5.7+ hoặc MariaDB 10.2+
- WordPress Multisite (650 blogs)
- HTTPS (bắt buộc cho production)

## Liên hệ

- Website: https://tgsworld.vn
- Plugin Version: 1.0.0
