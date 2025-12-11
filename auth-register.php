<?php
/**
 * User Registration Page
 */

session_start();
require_once 'db_connect.php';

$error_message = '';
$success_message = '';

// --- 1. LOGIC HIỂN THỊ THÔNG BÁO TỪ SESSION (MỚI) ---
// Kiểm tra xem có thông báo thành công từ lần load trước không
if (isset($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']); // Xóa ngay sau khi đã lấy để không hiện lại khi F5
}

// Logic chuyển hướng nếu đã đăng nhập
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin') {
        header('Location: admin-index.php');
    } else {
        header('Location: account-index.php');
    }
    exit;
}

function generateUserID($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(UserID, 2) AS UNSIGNED)) as max_id FROM User_Account");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'U' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validation
    if (empty($username) || empty($password) || empty($confirm_password)) {
        $error_message = 'Vui lòng điền đầy đủ thông tin.';
    } elseif (strlen($username) < 6) {
        $error_message = 'Tên đăng nhập phải ít nhất 6 ký tự.';
    } elseif (strlen($password) < 6) {
        $error_message = 'Mật khẩu phải ít nhất 6 ký tự.';
    } elseif ($password !== $confirm_password) {
        $error_message = 'Mật khẩu xác nhận không khớp.';
    } else {
        // Check exist
        $stmt = $pdo->prepare("SELECT UserID FROM User_Account WHERE Username = ?");
        $stmt->execute([$username]);

        if ($stmt->rowCount() > 0) {
            $error_message = 'Tên đăng nhập này đã tồn tại.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $user_id = generateUserID($pdo);

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO User_Account
                    (UserID, Username, Password, Role, Status, CreatedDate, Points)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ");
                
                $stmt->execute([
                    $user_id,
                    $username,
                    $hashed_password,
                    'Customer', 
                    1, 
                    0 
                ]);

                // --- 2. LOGIC PRG: CHUYỂN HƯỚNG SAU KHI THÀNH CÔNG (MỚI) ---
                
                // Lưu thông báo vào session
                $_SESSION['flash_success'] = 'Đăng ký thành công! Vui lòng đăng nhập.';
                
                // Chuyển hướng lại chính trang này (để xóa trạng thái POST)
                header('Location: auth-register.php'); 
                exit; // Bắt buộc phải có exit sau header

            } catch (Exception $e) {
                $error_message = 'Lỗi đăng ký: ' . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Ký - Moonlit Store</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-body">
    <div class="container h-100">
        <div class="row h-100 align-items-center justify-content-center">
            <div class="col-md-5 col-lg-4">
                <div class="auth-card">
                    <h2 class="auth-title">Đăng Ký</h2>

                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger auth-alert" role="alert">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success_message)): ?>
                        <div class="alert alert-success auth-alert" role="alert">
                            <?php echo htmlspecialchars($success_message); ?>
                            <br>
                            <a href="auth-login.php" class="mt-2 d-inline-block">Về trang đăng nhập →</a>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="auth-form">
                        <div class="mb-3">
                            <label for="username" class="form-label auth-label">Tên đăng nhập</label>
                            <input
                                type="text"
                                class="form-control auth-input"
                                id="username"
                                name="username"
                                value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label auth-label">Mật khẩu</label>
                            <input
                                type="password"
                                class="form-control auth-input"
                                id="password"
                                name="password"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label auth-label">Xác nhận mật khẩu</label>
                            <input
                                type="password"
                                class="form-control auth-input"
                                id="confirm_password"
                                name="confirm_password"
                                required
                            >
                        </div>

                        <button type="submit" class="btn auth-btn-submit w-100">Đăng Ký</button>
                    </form>

                    <div class="auth-footer">
                        <p>Bạn đã có tài khoản? <a href="auth-login.php" class="auth-link">Đăng nhập tại đây</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
