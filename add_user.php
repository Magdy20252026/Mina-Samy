<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'مدير') {
    die('غير مصرح لك');
}

$error = '';
$success = '';
$username = '';
$role = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? '');

    if ($username === '' || $password === '' || $role === '') {
        $error = 'جميع الحقول مطلوبة';
    } elseif (!in_array($role, ['مدير', 'مشرف'])) {
        $error = 'الصلاحية غير صحيحة';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);

        if ($check->fetch()) {
            $error = 'اسم المستخدم موجود بالفعل';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            $stmt->execute([$username, $hashedPassword, $role]);
            $success = 'تمت إضافة المستخدم بنجاح';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة مستخدم - <?php echo e($store['name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-layout">
    <aside class="sidebar">
        <div class="brand-block">
            <img src="<?php echo e($store['logo']); ?>" alt="شعار <?php echo e($store['name']); ?>" class="store-logo">
            <div>
                <h2 class="brand"><?php echo e($store['name']); ?></h2>
                <p class="brand-subtitle"><?php echo e($store['subtitle']); ?></p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php"><span class="nav-icon">🏠</span><span class="nav-label">لوحة التحكم</span></a>
            <a href="users.php" class="active"><span class="nav-icon">👥</span><span class="nav-label">المستخدمين</span></a>
            <?php if (($_SESSION['role'] ?? '') === 'مدير'): ?>
                <a href="settings.php"><span class="nav-icon">⚙️</span><span class="nav-label">إعدادات المتجر</span></a>
            <?php endif; ?>
            <div class="sidebar-section-group">
                <span class="sidebar-section-title">أقسام النظام</span>
                <?php foreach (getSidebarSections() as $section): ?>
                    <button type="button" class="sidebar-action-link" aria-label="<?php echo e($section['aria_label']); ?>" disabled>
                        <span class="nav-icon" aria-hidden="true"><?php echo e($section['icon']); ?></span>
                        <span class="nav-label"><?php echo e($section['label']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <a href="logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-label">تسجيل الخروج</span></a>
        </nav>

        <div class="theme-toggle-box">
            <span>🌗 الوضع</span>
            <label class="switch">
                <input type="checkbox" id="themeToggle" aria-label="تبديل الوضع الداكن">
                <span class="slider"></span>
            </label>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h1>إضافة مستخدم جديد</h1>
        </header>

        <div class="form-card">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>اسم المستخدم</label>
                    <input type="text" name="username" value="<?php echo e($username); ?>" required>
                </div>

                <div class="form-group">
                    <label>كلمة السر</label>
                    <input type="password" name="password" required>
                </div>

                <div class="form-group select-group">
                    <label>الصلاحية</label>
                    <select name="role" required>
                        <option value="">اختر الصلاحية</option>
                        <option value="مدير" <?php echo $role === 'مدير' ? 'selected' : ''; ?>>مدير</option>
                        <option value="مشرف" <?php echo $role === 'مشرف' ? 'selected' : ''; ?>>مشرف</option>
                    </select>
                </div>

                <button type="submit">💾 حفظ</button>
            </form>
        </div>
    </main>
</div>
<script src="assets/js/theme.js"></script>
</body>
</html>
