<?php
/**
 * User Account Dashboard
 * Main dashboard with sidebar navigation and dynamic content
 */

session_start();
require_once 'db_connect.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$current_section = $_GET['section'] ?? 'profile';

// Fetch user basic info
$stmt = $pdo->prepare("SELECT UserID, Username, FullName, Phone, Email, City, District, Ward, Street, HouseNumber FROM User_Account WHERE UserID = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tài Khoản - Moonlit Store</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="account-body">
    <!-- Header -->
    <header class="account-header">
        <div class="container-fluid">
            <div class="row align-items-center h-100">
                <div class="col-6">
                    <h1 class="account-logo">Moonlit Store</h1>
                </div>
                <div class="col-6 text-end">
                    <span class="account-username">Xin chào, <?php echo htmlspecialchars($user['Username']); ?></span>
                </div>
            </div>
        </div>
    </header>

    <div class="container-fluid account-main">
        <div class="row h-100">
            <!-- Left Sidebar -->
            <div class="col-lg-3 account-sidebar">
                <nav class="account-menu">
                    <a href="?section=profile" class="account-menu-item <?php echo $current_section === 'profile' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> Thông tin cá nhân
                    </a>
                    <a href="?section=voucher" class="account-menu-item <?php echo $current_section === 'voucher' ? 'active' : ''; ?>">
                        <i class="fas fa-coins"></i> Voucher & Đổi Điểm
                    </a>
                    <a href="?section=orders" class="account-menu-item <?php echo $current_section === 'orders' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> Lịch sử đặt hàng
                    </a>
                    <a href="?section=tracking" class="account-menu-item <?php echo $current_section === 'tracking' ? 'active' : ''; ?>">
                        <i class="fas fa-truck"></i> Theo dõi đơn hàng
                    </a>
                    <hr class="account-menu-divider">
                    <a href="logout.php" class="account-menu-item account-menu-logout">
                        <i class="fas fa-sign-out-alt"></i> Đăng xuất
                    </a>
                </nav>
            </div>

            <!-- Right Content -->
            <div class="col-lg-9 account-content">
                <?php
                // Load appropriate section based on query parameter
                if ($current_section === 'profile') {
                    include 'account-profile.php';
                } elseif ($current_section === 'voucher') {
                    include 'account-voucher.php';
                } elseif ($current_section === 'orders') {
                    include 'account-orders.php';
                } elseif ($current_section === 'tracking') {
                    include 'account-tracking.php';
                } else {
                    include 'account-profile.php';
                }
                ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
