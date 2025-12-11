<?php
/**
 * User Login Page
 * Handles user authentication and session creation
 */

session_start();
require_once 'db_connect.php';

$error_message = '';

// --- LOGIC 1: CHUYỂN HƯỚNG NẾU ĐÃ ĐĂNG NHẬP ---
// Nếu người dùng cố vào trang login mà đã đăng nhập rồi, 
// hệ thống sẽ tự kiểm tra quyền và đưa về trang tương ứng.
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'Customer'; // Mặc định là Customer nếu không tìm thấy
    if ($role === 'Admin') {
        header('Location: admin-index.php');
    } else {
        header('Location: account-index.php');
    }
    exit;
}

// --- LOGIC 2: XỬ LÝ FORM ĐĂNG NHẬP ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error_message = 'Vui lòng nhập tên đăng nhập và mật khẩu.';
    } else {
        // Lấy thêm cột Role từ database
        $stmt = $pdo->prepare("SELECT UserID, Username, Password, Role FROM User_Account WHERE Username = ?");
        $stmt->execute([$username]);

        if ($stmt->rowCount() === 0) {
            $error_message = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
        } else {
            $user = $stmt->fetch();

            if (password_verify($password, $user['Password'])) {
                // Đăng nhập thành công -> Lưu Session
                $_SESSION['user_id'] = $user['UserID'];
                $_SESSION['username'] = $user['Username'];
                $_SESSION['role'] = $user['Role']; // Lưu quyền hạn vào session

                // --- LOGIC 3: CHUYỂN HƯỚNG THEO ROLE ---
                if ($user['Role'] === 'Admin') {
                    header('Location: admin-index.php');
                } else {
                    // Mặc định Customer hoặc các role khác
                    header('Location: account-index.php');
                }
                exit;
            } else {
                $error_message = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
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
    <title>Đăng Nhập - Moonlit Store</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-body">
    <div class="container h-100">
        <div class="row h-100 align-items-center justify-content-center">
            <div class="col-md-5 col-lg-4">
                <div class="auth-card">
                    <h2 class="auth-title">Đăng Nhập</h2>

                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger auth-alert" role="alert">
                            <?php echo htmlspecialchars($error_message); ?>
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

                        <button type="submit" class="btn auth-btn-submit w-100">Đăng Nhập</button>
                    </form>

                    <div class="auth-footer">
                        <p>Bạn chưa có tài khoản? <a href="auth-register.php" class="auth-link">Đăng ký ngay</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
