<?php
/**
 * Order History Section with Return Management
 * Display completed and cancelled orders with return workflow
 */

if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// ============================================================================
// FUNCTION: Generate unique ID
// ============================================================================
function generateReturnId($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(ReturnID, 2) AS UNSIGNED)) as max_id FROM Returns_Order");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'R' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

function generateImageId($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(ImageID, 2) AS UNSIGNED)) as max_id FROM Return_Images");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'I' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

// ============================================================================
// FUNCTION: Check return window (7 days)
// ============================================================================
function isReturnWindowOpen($date_received) {
    if (empty($date_received)) return false;
    
    $received = new DateTime($date_received);
    $now = new DateTime();
    $interval = $now->diff($received);
    
    // Nếu ngày hiện tại lớn hơn ngày nhận (khoảng cách dương) và <= 7 ngày
    return $interval->days <= 7 && $interval->invert == 1; 
}

// ============================================================================
// POST HANDLER: Request Return
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_return') {
    $order_id = trim($_POST['order_id'] ?? '');
    // Lấy mảng item, nếu không phải mảng (chỉ 1 item) thì ép kiểu
    $selected_items = isset($_POST['return_items']) ? $_POST['return_items'] : [];
    if (!is_array($selected_items)) $selected_items = [$selected_items];

    $quantities = $_POST['return_quantities'] ?? [];
    $reasons = $_POST['return_reasons'] ?? [];

    if (empty($order_id) || empty($selected_items)) {
        $message = 'Vui lòng chọn ít nhất một sản phẩm để trả hàng.';
        $message_type = 'danger';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Tạo Returns_Order
            $return_id = generateReturnId($pdo);
            $stmt = $pdo->prepare("
                INSERT INTO Returns_Order (ReturnID, OrderID, Status, TotalRefund, CreatedDate)
                VALUES (?, ?, 'Chờ xác nhận', 0, NOW())
            ");
            $stmt->execute([$return_id, $order_id]);

            $total_refund = 0;

            // 2. Xử lý từng item
            foreach ($selected_items as $order_item_id) {
                $qty = isset($quantities[$order_item_id]) ? (int)$quantities[$order_item_id] : 0;
                $reason = isset($reasons[$order_item_id]) ? trim($reasons[$order_item_id]) : '';

                // Validate bắt buộc
                if ($qty <= 0) throw new Exception('Số lượng trả phải lớn hơn 0.');
                if (empty($reason)) throw new Exception('Vui lòng chọn lý do trả hàng.');

                // Lấy thông tin gốc để tính tiền và check số lượng
                $stmt = $pdo->prepare("SELECT UnitPrice, Quantity FROM Order_Items WHERE OrderItemID = ?");
                $stmt->execute([$order_item_id]);
                $item = $stmt->fetch();

                if (!$item || $qty > $item['Quantity']) {
                    throw new Exception('Dữ liệu sản phẩm không hợp lệ.');
                }

                $refund_amount = $qty * $item['UnitPrice'];
                $total_refund += $refund_amount;

                // Tạo Return_Items
                $stmt = $pdo->prepare("
                    INSERT INTO Return_Items (ReturnID, OrderItemID, Quantity, RefundAmount, Reason)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$return_id, $order_item_id, $qty, $refund_amount, $reason]);
                
                $return_item_id = $pdo->lastInsertId();

                // 3. Upload ảnh (Bắt buộc)
                if (!isset($_FILES['return_images']['name'][$order_item_id]) || 
                    empty($_FILES['return_images']['name'][$order_item_id][0])) {
                    throw new Exception('Vui lòng tải lên hình ảnh minh chứng cho sản phẩm.');
                }

                $files = $_FILES['return_images'];
                $file_count = count($files['name'][$order_item_id]);

                for ($i = 0; $i < $file_count; $i++) {
                    if ($files['error'][$order_item_id][$i] === UPLOAD_ERR_OK) {
                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        if (!in_array($files['type'][$order_item_id][$i], $allowed_types)) {
                            throw new Exception('Chỉ hỗ trợ file ảnh (JPG, PNG, GIF, WEBP).');
                        }

                        $upload_dir = 'uploads/returns/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                        $extension = pathinfo($files['name'][$order_item_id][$i], PATHINFO_EXTENSION);
                        $filename = $return_id . '_' . uniqid() . '.' . $extension;
                        $filepath = $upload_dir . $filename;

                        if (move_uploaded_file($files['tmp_name'][$order_item_id][$i], $filepath)) {
                            $image_id = generateImageId($pdo);
                            $stmt = $pdo->prepare("INSERT INTO Return_Images (ImageID, ReturnItemID, ImageURL) VALUES (?, ?, ?)");
                            $stmt->execute([$image_id, $return_item_id, $filepath]);
                        }
                    }
                }
            }

            // 4. Cập nhật tổng tiền hoàn
            $stmt = $pdo->prepare("UPDATE Returns_Order SET TotalRefund = ? WHERE ReturnID = ?");
            $stmt->execute([$total_refund, $return_id]);

            // [MỚI] 5. Cập nhật trạng thái Order gốc thành 'Trả hàng'
            $stmt = $pdo->prepare("UPDATE `Order` SET Status = 'Trả hàng' WHERE OrderID = ?");
            $stmt->execute([$order_id]);

            $pdo->commit();
            $message = 'Yêu cầu trả hàng đã được gửi thành công!';
            $message_type = 'success';
            
            // Refresh lại trang để cập nhật trạng thái nút
            echo "<script>window.location.href = window.location.href;</script>"; 
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'Lỗi: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// ============================================================================
// POST HANDLER: Cancel Return (Hoàn tác)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_return') {
    $return_id = trim($_POST['return_id'] ?? '');

    try {
        $pdo->beginTransaction();
        
        // Kiểm tra trạng thái và LẤY OrderID để restore
        $stmt = $pdo->prepare("SELECT Status, OrderID FROM Returns_Order WHERE ReturnID = ?");
        $stmt->execute([$return_id]);
        $curr = $stmt->fetch();

        if (!$curr || $curr['Status'] !== 'Chờ xác nhận') {
            throw new Exception('Chỉ có thể hủy yêu cầu khi đang chờ xác nhận.');
        }

        $order_id_to_restore = $curr['OrderID'];

        // Xóa ảnh vật lý và DB
        $stmt = $pdo->prepare("
            SELECT ri.ImageURL, ri.ImageID 
            FROM Return_Images ri
            JOIN Return_Items rit ON ri.ReturnItemID = rit.ReturnItemID
            WHERE rit.ReturnID = ?
        ");
        $stmt->execute([$return_id]);
        $images = $stmt->fetchAll();

        foreach ($images as $img) {
            if (file_exists($img['ImageURL'])) unlink($img['ImageURL']);
        }

        // Xóa dữ liệu theo thứ tự khóa ngoại
        // 1. Return_Images
        $stmt = $pdo->prepare("DELETE FROM Return_Images WHERE ReturnItemID IN (SELECT ReturnItemID FROM Return_Items WHERE ReturnID = ?)");
        $stmt->execute([$return_id]);

        // 2. Return_Items
        $stmt = $pdo->prepare("DELETE FROM Return_Items WHERE ReturnID = ?");
        $stmt->execute([$return_id]);

        // 3. Returns_Order
        $stmt = $pdo->prepare("DELETE FROM Returns_Order WHERE ReturnID = ?");
        $stmt->execute([$return_id]);

        // [MỚI] 4. Khôi phục trạng thái Order gốc về 'Đã nhận'
        $stmt = $pdo->prepare("UPDATE `Order` SET Status = 'Đã nhận' WHERE OrderID = ?");
        $stmt->execute([$order_id_to_restore]);

        $pdo->commit();
        $message = 'Đã hủy yêu cầu trả hàng.';
        $message_type = 'success';

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Lỗi hủy: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// ============================================================================
// GET ORDERS DATA
// ============================================================================
$filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$sql = "
    SELECT
        o.OrderID,
        o.TotalAmount,
        o.Status,
        o.CreatedDate,
        o.DateReceived,
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
    WHERE o.UserID = ?
";

$params = [$user_id];

switch ($filter) {
    case 'received':
        $sql .= " AND o.Status = 'Đã nhận'";
        break;
    case 'returned':
        $sql .= " AND (o.Status = 'Trả hàng' OR o.OrderID IN (SELECT OrderID FROM Returns_Order))";
        break;
    case 'cancelled':
        $sql .= " AND o.Status = 'Bị hủy'";
        break;
    default:
        $sql .= " AND o.Status IN ('Đã nhận', 'Bị hủy', 'Trả hàng')";
        break;
}

$sql .= " ORDER BY o.CreatedDate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Group orders by OrderID
$grouped_orders = [];
foreach ($orders as $order) {
    $order_id = $order['OrderID'];
    if (!isset($grouped_orders[$order_id])) {
        $grouped_orders[$order_id] = [
            'OrderID' => $order['OrderID'],
            'TotalAmount' => $order['TotalAmount'],
            'Status' => $order['Status'],
            'CreatedDate' => $order['CreatedDate'],
            'DateReceived' => $order['DateReceived'],
            'Items' => []
        ];
    }
    if (!empty($order['ProductName'])) {
        $grouped_orders[$order_id]['Items'][] = [
            'OrderItemID' => $order['OrderItemID'],
            'ProductName' => $order['ProductName'],
            'Image' => $order['Image'],
            'Format' => $order['Format'],
            'ISBN' => $order['ISBN'],
            'Quantity' => $order['Quantity'],
            'UnitPrice' => $order['UnitPrice']
        ];
    }
}

// Get Return Statuses
$return_map = [];
if (!empty($grouped_orders)) {
    $order_ids = array_keys($grouped_orders);
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $stmt = $pdo->prepare("SELECT ReturnID, OrderID, Status FROM Returns_Order WHERE OrderID IN ($placeholders)");
    $stmt->execute($order_ids);
    $returns = $stmt->fetchAll();

    foreach ($returns as $return) {
        $return_map[$return['OrderID']] = $return;
    }
}
?>

<div class="account-section">
    <div class="account-section-header">
        <h2 class="account-section-title">Lịch sử đặt hàng</h2>
        <div class="account-filters">
            <form method="GET" action="">
                <?php
                foreach ($_GET as $key => $value) {
                    if ($key !== 'status') {
                        echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
                    }
                }
                ?>
                <select name="status" class="account-filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>Tất cả đơn hàng</option>
                    <option value="received" <?php echo $filter === 'received' ? 'selected' : ''; ?>>Đã nhận</option>
                    <option value="returned" <?php echo $filter === 'returned' ? 'selected' : ''; ?>>Trả hàng</option>
                    <option value="cancelled" <?php echo $filter === 'cancelled' ? 'selected' : ''; ?>>Bị hủy</option>
                </select>
            </form>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> account-alert" role="alert">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

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
                                Ngày đặt hàng: <?php echo date('d/m/Y H:i', strtotime($order['CreatedDate'])); ?>
                                <?php if (!empty($order['DateReceived'])): ?>
                                    - Ngày nhận hàng: <?php echo date('d/m/Y H:i', strtotime($order['DateReceived'])); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php
                        $status_class = 'status-cancelled';
                        $status_text = mb_strtolower($order['Status'], 'UTF-8');

                        if ($status_text === 'đã nhận') {
                            $status_class = 'status-success';
                        } elseif ($status_text === 'trả hàng') {
                            $status_class = 'status-returned';
                        }
                        ?>
                        <span class="account-order-status <?php echo $status_class; ?>">
                            <?php echo htmlspecialchars($order['Status']); ?>
                        </span>
                    </div>

                    <div class="account-order-items">
                        <?php foreach ($order['Items'] as $item): ?>
                            <div class="account-order-item" 
                                 data-order-item-id="<?php echo $item['OrderItemID']; ?>"
                                 data-max-qty="<?php echo $item['Quantity']; ?>">

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

                             <?php
                            // Logic hiển thị nút Trả hàng
                            $has_return = isset($return_map[$order['OrderID']]);
                            
                            // Nếu đã có đơn trả hàng -> Xem chi tiết
                            if ($has_return) {
                                $r_id = $return_map[$order['OrderID']]['ReturnID'];
                                echo '<button class="btn account-btn-secondary" data-bs-toggle="modal" data-bs-target="#viewReturnModal" onclick="setViewReturnData(\''.$r_id.'\')">
                                        <i class="fas fa-eye"></i> Xem trả hàng
                                      </button>';
                            } 
                            // Nếu chưa trả, Status là Đã nhận VÀ trong vòng 7 ngày -> Nút Trả hàng
                            elseif ($order['Status'] === 'Đã nhận' && isReturnWindowOpen($order['DateReceived'])) {
                                echo '<button class="btn account-btn-secondary account-btn-return" data-bs-toggle="modal" data-bs-target="#returnModal" onclick="setReturnOrderData(\''.$order['OrderID'].'\', this.closest(\'.account-order-card\'))">
                                        <i class="fas fa-undo"></i> Trả hàng
                                      </button>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header account-modal-header">
                <h5 class="modal-title">Yêu cầu trả hàng</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="returnForm" onsubmit="return validateReturnForm()">
                <div class="modal-body">
                    <input type="hidden" name="action" value="request_return">
                    <input type="hidden" name="order_id" id="return_order_id">
                    
                    <div class="alert alert-info" style="font-size: 13px;">
                        <i class="fas fa-info-circle"></i> Vui lòng chọn sản phẩm, lý do và tải ảnh minh chứng để được hỗ trợ nhanh nhất.
                    </div>

                    <div id="return_items_container"></div>
                </div>
                <div class="modal-footer account-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn account-btn-save">Gửi yêu cầu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewReturnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header account-modal-header">
                <h5 class="modal-title">Theo dõi trả hàng</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="account-tracking-timeline" id="return_timeline_container"></div>

                <div id="return_success_message" style="display:none;" class="account-return-success">
                    <i class="fas fa-check-circle"></i>
                    <h4>Đã hoàn tất trả hàng</h4>
                    <p id="return_refund_text"></p>
                </div>

                <div id="return_items_info_container" style="margin-top: 20px;"></div>
            </div>
            <div class="modal-footer account-modal-footer">
                <form method="POST" id="cancelReturnForm">
                    <input type="hidden" name="action" value="cancel_return">
                    <input type="hidden" name="return_id" id="cancel_return_id_input">
                    <button type="submit" id="cancel_return_btn" class="btn btn-danger" style="display:none;" onclick="return confirm('Bạn chắc chắn muốn hủy yêu cầu này? Mọi dữ liệu trả hàng sẽ bị xóa.');">Hoàn tác</button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================================
// JS: SETUP FORM TRẢ HÀNG
// ============================================================================
function setReturnOrderData(orderId, orderCard) {
    document.getElementById('return_order_id').value = orderId;
    const container = document.getElementById('return_items_container');
    container.innerHTML = '';

    const items = orderCard.querySelectorAll('.account-order-item');
    
    let html = '<div class="account-return-items-form">';
    
    items.forEach(el => {
        // Lấy ID từ data attribute mà ta đã thêm ở PHP
        const itemId = el.dataset.orderItemId; 
        const maxQty = el.dataset.maxQty;
        const name = el.querySelector('.account-order-item-name').innerText;
        
        // Đoạn này lấy text SKU để hiện trong form trả hàng cho đẹp
        let skuText = '';
        const skuEl = el.querySelector('.account-order-item-sku');
        if (skuEl) skuText = skuEl.innerText;

        html += `
        <div class="account-return-item-section" style="margin-bottom: 20px;">
            <div class="account-return-item-header">
                <div class="form-check">
                    <input class="form-check-input return-item-check" type="checkbox" 
                           name="return_items[]" value="${itemId}" 
                           onchange="toggleItemReturn(this, '${itemId}')">
                    <label class="form-check-label" style="font-weight:600;">
                        ${name}
                    </label>
                    <div style="font-size:12px; color:#666;">${skuText}</div>
                </div>
            </div>

            <div id="return_detail_${itemId}" class="account-return-item-details" style="display:none; margin-top:10px; padding-left: 1.5rem;">
                
                <div class="form-group mb-2">
                    <label class="form-label">Số lượng trả (Max: ${maxQty}) <span class="text-danger">*</span></label>
                    <input type="number" name="return_quantities[${itemId}]" class="form-control" 
                           min="1" max="${maxQty}" value="1">
                </div>

                <div class="form-group mb-2">
                    <label class="form-label">Lý do trả hàng <span class="text-danger">*</span></label>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="return_reasons[${itemId}]" value="Sản phẩm bị lỗi/hư hỏng">
                            <label class="form-check-label">Sản phẩm lỗi</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="return_reasons[${itemId}]" value="Giao sai hàng">
                            <label class="form-check-label">Giao sai hàng</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="return_reasons[${itemId}]" value="Không còn nhu cầu">
                            <label class="form-check-label">Không nhu cầu</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="return_reasons[${itemId}]" value="Hàng giả/nhái">
                            <label class="form-check-label">Hàng giả/nhái</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Hình ảnh minh chứng <span class="text-danger">*</span></label>
                    <input type="file" name="return_images[${itemId}][]" class="form-control" multiple accept="image/*">
                    <small class="text-muted">Bắt buộc tải lên ít nhất 1 ảnh.</small>
                </div>
            </div>
        </div>`;
    });

    html += '</div>';
    container.innerHTML = html;
}

// Hàm ẩn/hiện form chi tiết khi tick chọn sản phẩm
function toggleItemReturn(checkbox, itemId) {
    const detailDiv = document.getElementById('return_detail_' + itemId);
    const inputs = detailDiv.querySelectorAll('input');
    
    if (checkbox.checked) {
        detailDiv.style.display = 'block';
        inputs.forEach(input => input.required = true); // Bắt buộc nhập nếu đã chọn
    } else {
        detailDiv.style.display = 'none';
        inputs.forEach(input => input.required = false);
    }
}

// Validate trước khi submit (JS Side)
function validateReturnForm() {
    const checked = document.querySelectorAll('.return-item-check:checked');
    if (checked.length === 0) {
        alert('Vui lòng chọn ít nhất 1 sản phẩm để trả.');
        return false;
    }
    return true;
}

// ============================================================================
// JS: XEM TIẾN ĐỘ TRẢ HÀNG
// ============================================================================
function setViewReturnData(returnId) {
    // Gọi API lấy dữ liệu
    fetch('get_return_details.php?return_id=' + encodeURIComponent(returnId))
        .then(res => res.json())
        .then(data => {
            if(!data.success) {
                alert(data.message); return;
            }
            
            const ret = data.return;
            const timelineDiv = document.getElementById('return_timeline_container');
            const successDiv = document.getElementById('return_success_message');
            const itemsDiv = document.getElementById('return_items_info_container');
            const cancelBtn = document.getElementById('cancel_return_btn');
            document.getElementById('cancel_return_id_input').value = ret.returnId;

            // Các bước Status
            const steps = [
                'Chờ xác nhận',
                'Đã xác nhận', 
                'Đang tới lấy',
                'Đang trả về',
                'Kiểm hàng',
                'Chấp thuận'
            ];

            // Nếu đã chấp thuận -> Hiện màn hình thành công
            if (ret.status === 'Chấp thuận') {
                timelineDiv.style.display = 'none';
                successDiv.style.display = 'block';
                document.getElementById('return_refund_text').innerHTML = 
                    `Đã hoàn lại <strong>${new Intl.NumberFormat('vi-VN').format(ret.totalRefund)} đ</strong> vào ví của bạn.`;
                cancelBtn.style.display = 'none';
            } else {
                // Hiện Timeline
                successDiv.style.display = 'none';
                timelineDiv.style.display = 'flex'; 
                
                let html = '';
                const currentIdx = steps.indexOf(ret.status);
                
                steps.forEach((step, idx) => {
                    // Logic: Bước này active nếu index <= index hiện tại
                    const isActiveStep = idx <= currentIdx;
                    
                    // Logic: Đường kẻ (nối sang bước sau) active nếu index < index hiện tại
                    const isActiveLine = idx < currentIdx;

                    // 1. Tạo HTML cho Step (Dùng class của Tracking)
                    html += `
                    <div class="account-tracking-step ${isActiveStep ? 'account-tracking-step-active' : ''}">
                        <div class="account-tracking-step-marker"></div>
                        <div class="account-tracking-step-label">${step}</div>
                    </div>`;

                    // 2. Tạo HTML cho Line (nếu không phải bước cuối cùng)
                    if(idx < steps.length - 1) {
                        html += `<div class="account-tracking-line ${isActiveLine ? 'account-tracking-line-active' : ''}"></div>`;
                    }
                });
                
                timelineDiv.innerHTML = html;

                // Nút hoàn tác chỉ hiện khi chưa xử lý
                cancelBtn.style.display = (ret.status === 'Chờ xác nhận') ? 'inline-block' : 'none';
            }

            // Render Items info
            let itemsHtml = '<h5>Sản phẩm trả lại</h5>';
            data.items.forEach(item => {
                itemsHtml += `
                <div class="p-2 mb-2 bg-light border rounded">
                    <strong>${item.productName}</strong><br>
                    <small>SL: ${item.quantity} | Lý do: ${item.reason}</small>
                </div>`;
            });
            itemsDiv.innerHTML = itemsHtml;
        })
        .catch(err => console.error(err));
}
</script>