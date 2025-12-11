<?php
/**
 * API Endpoint to fetch return details
 * Called via AJAX from account-orders.php modal
 */

session_start();
require_once 'db_connect.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$return_id = isset($_GET['return_id']) ? trim($_GET['return_id']) : '';

if (empty($return_id)) {
    echo json_encode(['success' => false, 'message' => 'Return ID required']);
    exit;
}

try {
    // Fetch return order
    $stmt = $pdo->prepare("
        SELECT ro.ReturnID, ro.OrderID, ro.Status, ro.TotalRefund, ro.CreatedDate
        FROM Returns_Order ro
        WHERE ro.ReturnID = ?
    ");
    $stmt->execute([$return_id]);
    $return = $stmt->fetch();

    if (!$return) {
        echo json_encode(['success' => false, 'message' => 'Return not found']);
        exit;
    }

    // Verify the return belongs to the logged-in user
    $stmt = $pdo->prepare("SELECT UserID FROM `Order` WHERE OrderID = ?");
    $stmt->execute([$return['OrderID']]);
    $order = $stmt->fetch();

    if (!$order || $order['UserID'] !== $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // Fetch return items with product names
    $stmt = $pdo->prepare("
        SELECT
            ri.ReturnItemID,
            ri.Quantity,
            ri.RefundAmount,
            ri.Reason,
            p.ProductName
        FROM Return_Items ri
        LEFT JOIN Order_Items oi ON ri.OrderItemID = oi.OrderItemID
        LEFT JOIN Product p ON oi.SKU_ID = (
            SELECT SKUID FROM SKU WHERE ProductID = p.ProductID LIMIT 1
        )
        WHERE ri.ReturnID = ?
    ");
    $stmt->execute([$return_id]);
    $items = $stmt->fetchAll();

    // Build response
    $response = [
        'success' => true,
        'return' => [
            'returnId' => $return['ReturnID'],
            'orderId' => $return['OrderID'],
            'status' => $return['Status'],
            'totalRefund' => (float)$return['TotalRefund'],
            'createdDate' => $return['CreatedDate']
        ],
        'items' => []
    ];

    foreach ($items as $item) {
        $response['items'][] = [
            'returnItemId' => $item['ReturnItemID'],
            'productName' => $item['ProductName'] ?? 'N/A',
            'quantity' => (int)$item['Quantity'],
            'refundAmount' => (float)$item['RefundAmount'],
            'reason' => $item['Reason'] ?? ''
        ];
    }

    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
