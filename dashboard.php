<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$stmtUsers = $pdo->query("
    SELECT
        COUNT(*) AS total_users,
        COALESCE(SUM(role = 'مدير'), 0) AS total_managers,
        COALESCE(SUM(role = 'مشرف'), 0) AS total_supervisors
    FROM users
");
$stats = $stmtUsers->fetch(PDO::FETCH_ASSOC) ?: [];
$totalUsers = (int) ($stats['total_users'] ?? 0);
$totalManagers = (int) ($stats['total_managers'] ?? 0);
$totalSupervisors = (int) ($stats['total_supervisors'] ?? 0);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - <?php echo e($store['name']); ?></title>
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
            <a href="dashboard.php"><span class="nav-icon">🏠</span><span>لوحة التحكم</span></a>
            <a href="users.php"><span class="nav-icon">👥</span><span>المستخدمين</span></a>
            <a href="logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span>تسجيل الخروج</span></a>
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
            <div class="topbar-content">
                <h1>مرحبًا، <?php echo e($_SESSION['username']); ?></h1>
                <small>الصلاحية: <?php echo e($_SESSION['role']); ?></small>
            </div>
            <div class="store-chip">
                <img src="<?php echo e($store['logo']); ?>" alt="شعار <?php echo e($store['name']); ?>" class="store-logo small-logo">
                <span>✨ <?php echo e($store['name']); ?></span>
            </div>
        </header>

        <section class="page-intro">
            <h2>إحصائيات لوحة التحكم</h2>
            <p>متابعة حالة المستخدمين المسجلين فعليًا داخل نظام Mina Samy.</p>
        </section>

        <div class="cards">
            <div class="card">
                <h3>عدد المستخدمين</h3>
                <div class="stat-number"><?php echo $totalUsers; ?></div>
                <p>عدد المستخدمين الحقيقي المسجلين</p>
            </div>
            <div class="card">
                <h3>عدد المديرين</h3>
                <div class="stat-number"><?php echo $totalManagers; ?></div>
                <p>إجمالي المستخدمين بصلاحية مدير</p>
            </div>
            <div class="card">
                <h3>عدد المشرفين</h3>
                <div class="stat-number"><?php echo $totalSupervisors; ?></div>
                <p>إجمالي المستخدمين بصلاحية مشرف</p>
            </div>
        </div>
    </main>
</div>

<script src="assets/js/theme.js"></script>
</body>
</html>
