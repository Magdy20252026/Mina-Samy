<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = "يرجى إدخال اسم المستخدم وكلمة السر";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header("Location: dashboard.php");
            exit;
        } else {
            $error = "بيانات الدخول غير صحيحة";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - <?php echo htmlspecialchars($store['name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-shell">
        <div class="login-card">
            <div class="login-window-controls">
                <span class="window-dot" aria-hidden="true"></span>
                <label class="mini-theme-switch" aria-label="تبديل الوضع الداكن">
                    <input type="checkbox" id="themeToggle" aria-label="تبديل الوضع الداكن">
                    <span class="mini-slider"></span>
                </label>
            </div>

            <div class="login-brand">
                <div class="login-logo-ring">
                    <img src="<?php echo htmlspecialchars($store['logo']); ?>" alt="شعار <?php echo htmlspecialchars($store['name']); ?>" class="store-logo">
                </div>
                <div>
                    <h2><?php echo htmlspecialchars($store['name']); ?></h2>
                    <p class="login-brand-subtitle"><?php echo htmlspecialchars($store['subtitle']); ?></p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <div class="form-group login-field">
                    <label for="username">اسم المستخدم</label>
                    <div class="input-shell">
                        <span class="input-icon" aria-hidden="true">👤</span>
                        <input id="username" type="text" name="username" placeholder="admin" autocomplete="username" required>
                    </div>
                </div>

                <div class="form-group login-field">
                    <label for="password">كلمة السر</label>
                    <div class="input-shell">
                        <span class="input-icon" aria-hidden="true">🔒</span>
                        <input id="password" type="password" name="password" placeholder="••••••" autocomplete="current-password" required>
                    </div>
                </div>

                <button type="submit" class="login-submit">📚 تسجيل الدخول إلى لوحة التحكم</button>
            </form>
        </div>
    </div>

    <script src="assets/js/theme.js"></script>
</body>
</html>
