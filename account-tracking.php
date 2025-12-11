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

// --- PHẦN 1: XỬ LÝ FORM (GIỮ NGUYÊN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_received') {
    $order_id_confirm = $_POST['order_id'] ?? 0;
    $stmt_update = $pdo->prepare("
        UPDATE `Order` 
        SET Status = 'Đã nhận' 
        WHERE OrderID = ? AND UserID = ? AND Status = 'Đã giao'
    ");
    if ($stmt_update->execute([$order_id_confirm, $user_id])) {
        header("Refresh:0"); 
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $order_id_cancel = $_POST['order_id'] ?? 0;
    $cancel_reason = $_POST['cancel_reason'] ?? 'Không có lý do cụ thể';
    $stmt_cancel = $pdo->prepare("
        UPDATE `Order` 
        SET 
            Status = 'Bị hủy',
            Note = CONCAT(IFNULL(Note, ''), '\nLý do hủy đơn: ', ?)
        WHERE OrderID = ? AND UserID = ? AND Status = 'Chờ xác nhận'
    ");
    if ($stmt_cancel->execute([$cancel_reason, $order_id_cancel, $user_id])) {
        header("Refresh:0"); 
        exit;
    }
}

// --- PHẦN 2: LẤY DỮ LIỆU ĐƠN HÀNG (CẬP NHẬT) ---
// Thêm LEFT JOIN Shipping_Order và Carrier để lấy tên nhà vận chuyển
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
        s.ISBN,
        c.CarrierName 
    FROM `Order` o
    LEFT JOIN Order_Items oi ON o.OrderID = oi.OrderID
    LEFT JOIN SKU s ON oi.SKU_ID = s.SKUID
    LEFT JOIN Product p ON s.ProductID = p.ProductID
    LEFT JOIN Shipping_Order so ON o.OrderID = so.OrderID
    LEFT JOIN Carrier c ON so.CarrierID = c.CarrierID
    WHERE o.UserID = ? AND o.Status NOT IN ('Đã nhận', 'Bị hủy', 'Trả hàng')
    ORDER BY o.CreatedDate DESC
");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

// Group orders by OrderID
$grouped_orders = [];
foreach ($orders as $order) {
    $order_id = $order['OrderID'];
    
    if (!isset($grouped_orders[$order_id])) {
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
            'CarrierName' => $order['CarrierName'], /* <--- LƯU TÊN NHÀ VẬN CHUYỂN */
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
    'Chờ xác nhận' => ['icon' => 'fa-hourglass-half', 'color' => 'secondary'],
    'Đang giao' => ['icon' => 'fa-truck', 'color' => 'info'], 
    'Đã giao' => ['icon' => 'fa-box-open', 'color' => 'primary'],
    'Đã xác nhận' => ['icon' => 'fa-check-circle', 'color' => 'success'],
];
?>

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
                        <?php 
                        // --- LOGIC XÁC ĐỊNH TRẠNG THÁI ACTIVE CHO TỪNG BƯỚC ---
                        
                        // Bước 1: Chờ xác nhận (Luôn luôn active vì là bước khởi đầu)
                        $step1_active = true;

                        // Bước 2: Đã xác nhận (Sáng khi trạng thái là Đang xử lý, Đang giao hoặc Đã giao)
                        // Lưu ý: Trong Database thường là 'Đang xử lý', ta hiển thị là 'Đã xác nhận'
                        $step2_active = in_array($current_status, ['Đã xác nhận', 'Đang giao', 'Đã giao', 'Đã nhận']);

                        // Bước 3: Đang giao (Sáng khi trạng thái là Đang giao hoặc Đã giao)
                        $step3_active = in_array($current_status, ['Đang giao', 'Đã giao', 'Đã nhận']);

                        // Bước 4: Đã giao (Sáng khi trạng thái là Đã giao)
                        $step4_active = in_array($current_status, ['Đã giao', 'Đã nhận']);
                        ?>

                        <div class="account-tracking-step account-tracking-step-active">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Chờ xác nhận</div>
                        </div>

                        <div class="account-tracking-line <?php echo $step2_active ? 'account-tracking-line-active' : ''; ?>"></div>

                        <div class="account-tracking-step <?php echo $step2_active ? 'account-tracking-step-active' : ''; ?>">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Đã xác nhận</div>
                        </div>

                        <div class="account-tracking-line <?php echo $step3_active ? 'account-tracking-line-active' : ''; ?>"></div>

                        <div class="account-tracking-step <?php echo $step3_active ? 'account-tracking-step-active' : ''; ?>">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Đang giao</div>
                        </div>

                        <div class="account-tracking-line <?php echo $step4_active ? 'account-tracking-line-active' : ''; ?>"></div>

                        <div class="account-tracking-step <?php echo $step4_active ? 'account-tracking-step-active' : ''; ?>">
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
                        <div class="account-tracking-detail-item">
                            <span class="account-tracking-detail-label">Đơn vị vận chuyển:</span>
                            <span class="account-tracking-detail-value">
                                <?php 
                                    echo !empty($order['CarrierName']) ? htmlspecialchars($order['CarrierName']) : '-'; 
                                ?>
                            </span>
                        </div>
                    </div>

                    <div class="account-order-footer">
                        <div class="account-order-total">
                            Tổng tiền: <strong style="color: #0056b3; font-size: 1.1em;"><?php echo number_format($order['TotalAmount'], 0, ',', '.'); ?> đ</strong>
                        </div>

                        <?php if ($current_status === 'Đã giao'): ?>
                            <form method="POST" onsubmit="return confirm('Bạn xác nhận đã nhận được đầy đủ hàng?');">
                                <input type="hidden" name="action" value="confirm_received">
                                <input type="hidden" name="order_id" value="<?php echo $order['OrderID']; ?>">
                                
                                <button type="submit" class="btn-tracking-action btn-green">
                                    <i class="fas fa-check-circle"></i> Đã nhận hàng
                                </button>
                            </form>

                        <?php elseif ($current_status === 'Chờ xác nhận'): ?>
                            <button type="button" class="btn-tracking-action btn-red" onclick="openCancelModal('<?php echo $order['OrderID']; ?>')">
                                <i class="fas fa-times-circle"></i> Hủy đơn hàng
                            </button>

                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="cancelOrderModal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-modal" onclick="closeCancelModal()">&times;</span>
        <div class="modal-header">
            <div class="modal-title">Lý do hủy đơn hàng</div>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="cancel_order">
            <input type="hidden" id="modal_order_id" name="order_id" value="">
            
            <ul class="reason-list">
                <li class="reason-item">
                    <input type="radio" id="r1" name="cancel_reason" value="Muốn thay đổi địa chỉ/số điện thoại nhận hàng" required>
                    <label for="r1">Muốn thay đổi địa chỉ/số điện thoại nhận hàng</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r2" name="cancel_reason" value="Muốn thay đổi sản phẩm (size, màu, số lượng...)">
                    <label for="r2">Muốn thay đổi sản phẩm (size, màu, số lượng...)</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r3" name="cancel_reason" value="Thủ tục thanh toán quá rắc rối">
                    <label for="r3">Thủ tục thanh toán quá rắc rối</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r4" name="cancel_reason" value="Tìm thấy giá rẻ hơn ở chỗ khác">
                    <label for="r4">Tìm thấy giá rẻ hơn ở chỗ khác</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r5" name="cancel_reason" value="Đổi ý, không muốn mua nữa">
                    <label for="r5">Đổi ý, không muốn mua nữa</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r6" name="cancel_reason" value="Lý do khác">
                    <label for="r6">Lý do khác</label>
                </li>
            </ul>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeCancelModal()">Đóng</button>
                <button type="submit" class="btn-danger-confirm">Xác nhận hủy</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCancelModal(orderId) {
        document.getElementById('modal_order_id').value = orderId;
        document.getElementById('cancelOrderModal').style.display = 'flex';
    }

    function closeCancelModal() {
        document.getElementById('cancelOrderModal').style.display = 'none';
    }

    // Đóng modal khi click ra ngoài
    window.onclick = function(event) {
        var modal = document.getElementById('cancelOrderModal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
</script>