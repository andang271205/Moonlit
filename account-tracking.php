<?php
/**
 * Order Tracking Section
 * Display active orders
 */

if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// --- PHẦN 1: XỬ LÝ KHI NGƯỜI DÙNG BẤM "ĐÃ NHẬN HÀNG" ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_received') {
    $order_id_confirm = $_POST['order_id'] ?? 0;
    
    // Chỉ update nếu đơn hàng thuộc về user này VÀ trạng thái đang là "Đã giao"
    $stmt_update = $pdo->prepare("
        UPDATE `Order` 
        SET Status = 'Đã nhận' 
        WHERE OrderID = ? AND UserID = ? AND Status = 'Đã giao'
    ");
    
    if ($stmt_update->execute([$order_id_confirm, $user_id])) {
        // Refresh lại trang để đơn hàng biến mất khỏi danh sách (do bộ lọc SQL bên dưới)
        header("Refresh:0"); 
        exit;
    }
}

// --- PHẦN 2: LẤY DỮ LIỆU ĐƠN HÀNG ---
// Fetch active orders 
// Logic lọc: Hiện tất cả trừ "Đã nhận" và "Bị hủy".
// Tức là "Đã giao" vẫn hiện ở đây để chờ khách bấm xác nhận.
$stmt = $pdo->prepare("
    SELECT
        o.OrderID,
        o.TotalAmount,
        o.Status,
        o.PaymentMethod,
        o.ShippingCity,
        o.ShippingDistrict,
        o.ShippingWard,
        o.ShippingStreet,
        o.ShippingNumber,
        o.CreatedDate,
        oi.OrderItemID,
        oi.Quantity,
        oi.UnitPrice,
        p.ProductName,
        p.Image,
        s.Format,
        s.ISBN
    FROM `Order` o
    LEFT JOIN Order_Items oi ON o.OrderID = oi.OrderID
    LEFT JOIN SKU s ON oi.SKU_ID = s.SKUID
    LEFT JOIN Product p ON s.ProductID = p.ProductID
    WHERE o.UserID = ? AND o.Status NOT IN ('Đã nhận', 'Bị hủy')
    ORDER BY o.CreatedDate DESC
");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

// Group orders by OrderID
$grouped_orders = [];
foreach ($orders as $order) {
    $order_id = $order['OrderID'];
    
    if (!isset($grouped_orders[$order_id])) {
        // Logic gộp địa chỉ (giữ nguyên như yêu cầu trước)
        $address_parts = array_filter([
            $order['ShippingNumber'] ?? '', 
            $order['ShippingStreet'] ?? '', 
            $order['ShippingWard'] ?? '', 
            $order['ShippingDistrict'] ?? '', 
            $order['ShippingCity'] ?? ''
        ], function($value) {
            return !empty(trim($value));
        });
        $full_shipping_address = implode(', ', $address_parts);

        $grouped_orders[$order_id] = [
            'OrderID' => $order['OrderID'],
            'TotalAmount' => $order['TotalAmount'],
            'Status' => $order['Status'],
            'PaymentMethod' => $order['PaymentMethod'],
            'ShippingAddress' => $full_shipping_address,
            'CreatedDate' => $order['CreatedDate'],
            'Items' => []
        ];
    }
    
    if (!empty($order['ProductName'])) {
        $grouped_orders[$order_id]['Items'][] = [
            'ProductName' => $order['ProductName'],
            'Image' => $order['Image'],
            'Format' => $order['Format'],
            'ISBN' => $order['ISBN'],
            'Quantity' => $order['Quantity'],
            'UnitPrice' => $order['UnitPrice']
        ];
    }
}

// Map status settings
$status_config = [
    'Đang xử lý' => ['icon' => 'fa-clock', 'color' => 'warning'],
    'Đang giao' => ['icon' => 'fa-truck', 'color' => 'info'], 
    'Đã giao' => ['icon' => 'fa-box-open', 'color' => 'primary'], // Thêm trạng thái này
    'Chờ xác nhận' => ['icon' => 'fa-hourglass-half', 'color' => 'secondary'],
];
?>

<style>
    /* CSS thêm cho nút xác nhận */
    .btn-confirm-received {
        background-color: #28a745;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        font-weight: 600;
        width: 100%;
        margin-top: 15px;
        transition: all 0.3s ease;
    }
    .btn-confirm-received:hover {
        background-color: #218838;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .status-tracking.status-delivered {
        color: #28a745; /* Màu xanh lá cho đã giao */
    }
</style>

<div class="account-section">
    <h2 class="account-section-title">Theo dõi đơn hàng</h2>

    <?php if (empty($grouped_orders)): ?>
        <div class="account-empty-state">
            <i class="fas fa-check-circle"></i>
            <p class="account-empty-text">Bạn không có đơn hàng nào đang giao.</p>
        </div>
    <?php else: ?>
        <div class="account-orders-list">
            <?php foreach ($grouped_orders as $order): ?>
                <?php 
                    $current_status = $order['Status'];
                    $status_info = $status_config[$current_status] ?? ['icon' => 'fa-circle', 'color' => 'secondary'];
                ?>
                <div class="account-order-card account-order-card-tracking">
                    <div class="account-order-header">
                        <div class="account-order-info">
                            <span class="account-order-id">Đơn hàng: #<?php echo htmlspecialchars($order['OrderID']); ?></span>
                            <span class="account-order-date">
                                <?php echo date('d/m/Y H:i', strtotime($order['CreatedDate'])); ?>
                            </span>
                        </div>
                        <span class="account-order-status text-<?php echo $status_info['color']; ?>">
                            <i class="fas <?php echo $status_info['icon']; ?>"></i> <?php echo htmlspecialchars($current_status); ?>
                        </span>
                    </div>

                    <div class="account-tracking-timeline">
                        <div class="account-tracking-step account-tracking-step-active">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Đã xác nhận</div>
                        </div>

                        <div class="account-tracking-line <?php echo in_array($current_status, ['Đang giao', 'Đã giao', 'Đã nhận']) ? 'account-tracking-line-active' : ''; ?>"></div>

                        <div class="account-tracking-step <?php echo in_array($current_status, ['Đang giao', 'Đã giao', 'Đã nhận']) ? 'account-tracking-step-active' : ''; ?>">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Đang giao</div>
                        </div>

                        <div class="account-tracking-line <?php echo in_array($current_status, ['Đã giao', 'Đã nhận']) ? 'account-tracking-line-active' : ''; ?>"></div>

                        <div class="account-tracking-step <?php echo in_array($current_status, ['Đã giao', 'Đã nhận']) ? 'account-tracking-step-active' : ''; ?>">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Đã giao</div>
                        </div>
                    </div>

                    <div class="account-order-items">
                        <?php foreach ($order['Items'] as $item): ?>
                            <div class="account-order-item">
                                <?php if (!empty($item['Image'])): ?>
                                    <div class="account-order-item-image">
                                        <img src="data:image/jpeg;base64,<?php echo base64_encode($item['Image']); ?>" alt="<?php echo htmlspecialchars($item['ProductName']); ?>">
                                    </div>
                                <?php else: ?>
                                    <div class="account-order-item-image account-order-item-image-empty">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>

                                <div class="account-order-item-details">
                                    <h4 class="account-order-item-name"><?php echo htmlspecialchars($item['ProductName']); ?></h4>
                                    <p class="account-order-item-sku">
                                        <?php if (!empty($item['Format'])): ?>
                                            Định dạng: <?php echo htmlspecialchars($item['Format']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($item['ISBN'])): ?>
                                            | ISBN: <?php echo htmlspecialchars($item['ISBN']); ?>
                                        <?php endif; ?>
                                    </p>
                                    <p class="account-order-item-qty">
                                        SL: <?php echo htmlspecialchars($item['Quantity']); ?> x <?php echo number_format($item['UnitPrice'], 0, ',', '.'); ?> đ
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="account-tracking-details">
                        <div class="account-tracking-detail-item">
                            <span class="account-tracking-detail-label">Thanh toán:</span>
                            <span class="account-tracking-detail-value"><?php echo htmlspecialchars($order['PaymentMethod'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="account-tracking-detail-item">
                            <span class="account-tracking-detail-label">Địa chỉ:</span>
                            <span class="account-tracking-detail-value"><?php echo htmlspecialchars($order['ShippingAddress'] ?? 'N/A'); ?></span>
                        </div>
                    </div>

                    <div class="account-order-footer">
                        <div class="account-order-total">
                            Tổng tiền: <strong><?php echo number_format($order['TotalAmount'], 0, ',', '.'); ?> đ</strong>
                        </div>

                        <?php if ($current_status === 'Đã giao'): ?>
                            <form method="POST" onsubmit="return confirm('Bạn xác nhận đã nhận được đầy đủ hàng? Đơn hàng sẽ được hoàn tất.');">
                                <input type="hidden" name="action" value="confirm_received">
                                <input type="hidden" name="order_id" value="<?php echo $order['OrderID']; ?>">
                                <button type="submit" class="btn-confirm-received">
                                    <i class="fas fa-check-circle"></i> Đã nhận hàng
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>