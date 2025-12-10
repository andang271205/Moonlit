<?php
/**
 * Order History Section
 * Display completed and cancelled orders
 */

if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch completed and cancelled orders
$stmt = $pdo->prepare("
    SELECT
        o.OrderID,
        o.TotalAmount,
        o.Status,
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
    WHERE o.UserID = ? AND (o.Status = 'Đã nhận' OR o.Status = 'Bị hủy')
    ORDER BY o.CreatedDate DESC
");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

// Group orders by OrderID for display
$grouped_orders = [];
foreach ($orders as $order) {
    $order_id = $order['OrderID'];
    if (!isset($grouped_orders[$order_id])) {
        $grouped_orders[$order_id] = [
            'OrderID' => $order['OrderID'],
            'TotalAmount' => $order['TotalAmount'],
            'Status' => $order['Status'],
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
?>

<div class="account-section">
    <h2 class="account-section-title">Lịch sử đặt hàng</h2>

    <?php if (empty($grouped_orders)): ?>
        <div class="account-empty-state">
            <i class="fas fa-box-open"></i>
            <p class="account-empty-text">Bạn chưa có đơn hàng nào.</p>
        </div>
    <?php else: ?>
        <div class="account-orders-list">
            <?php foreach ($grouped_orders as $order): ?>
                <div class="account-order-card">
                    <div class="account-order-header">
                        <div class="account-order-info">
                            <span class="account-order-id">Đơn hàng: <?php echo htmlspecialchars($order['OrderID']); ?></span>
                            <span class="account-order-date">
                                <?php echo date('d/m/Y H:i', strtotime($order['CreatedDate'])); ?>
                            </span>
                        </div>
                        <span class="account-order-status <?php echo strtolower($order['Status']) === 'Đã nhận' ? 'status-success' : 'status-cancelled'; ?>">
                            <?php echo htmlspecialchars($order['Status']); ?>
                        </span>
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
                                        Số lượng: <?php echo htmlspecialchars($item['Quantity']); ?> x <?php echo number_format($item['UnitPrice'], 0, ',', '.'); ?> đ
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="account-order-footer">
                        <div class="account-order-total">
                            Tổng tiền: <strong><?php echo number_format($order['TotalAmount'], 0, ',', '.'); ?> đ</strong>
                        </div>
                        <div class="account-order-actions">
                            <button class="btn account-btn-secondary account-btn-rate">
                                <i class="fas fa-star"></i> Đánh giá
                            </button>
                            <button class="btn account-btn-secondary account-btn-rebuy">
                                <i class="fas fa-redo"></i> Mua lại
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
