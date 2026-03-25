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
    <div class="login-card">
        <div class="login-brand">
            <img src="<?php echo htmlspecialchars($store['logo']); ?>" alt="شعار <?php echo htmlspecialchars($store['name']); ?>" class="store-logo">
            <div>
                <h2><?php echo htmlspecialchars($store['name']); ?></h2>
                <p class="login-brand-subtitle"><?php echo htmlspecialchars($store['subtitle']); ?></p>
            </div>
        </div>
        <p class="login-description">✨ تسجيل الدخول إلى لوحة التحكم</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>اسم المستخدم</label>
                <input type="text" name="username" placeholder="ادخل اسم المستخدم">
            </div>

            <div class="form-group">
                <label>كلمة السر</label>
                <input type="password" name="password" placeholder="ادخل كلمة السر">
            </div>

            <div class="form-group" style="display:flex;justify-content:space-between;align-items:center;">
                <span>الوضع الداكن</span>
                <label class="switch" style="width:56px;">
                    <input type="checkbox" id="themeToggle" aria-label="تبديل الوضع الداكن">
                    <span class="slider"></span>
                </label>
            </div>

            <button type="submit">🚀 دخول</button>
        </form>
    </div>

    <script src="assets/js/theme.js"></script>
</body>
</html>
