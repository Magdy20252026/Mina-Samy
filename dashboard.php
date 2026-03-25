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
<body class="dashboard-page">
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
            <a href="dashboard.php" class="active"><span class="nav-icon">🏠</span><span class="nav-label">لوحة التحكم</span></a>
            <a href="users.php"><span class="nav-icon">👥</span><span class="nav-label">المستخدمين</span></a>
            <?php if (($_SESSION['role'] ?? '') === 'مدير'): ?>
                <a href="settings.php"><span class="nav-icon">⚙️</span><span class="nav-label">إعدادات المتجر</span></a>
            <?php endif; ?>
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
        <header class="topbar dashboard-hero">
            <div class="topbar-content">
                <span class="section-badge">لوحة التحكم</span>
                <h1>مرحبًا، <?php echo e($_SESSION['username']); ?></h1>
                <p>متابعة حالة المستخدمين المسجلين فعليًا داخل نظام <?php echo e($store['name']); ?>.</p>
            </div>
            <div class="hero-meta">
                <div class="hero-meta-card">
                    <span>الصلاحية الحالية</span>
                    <strong><?php echo e($_SESSION['role']); ?></strong>
                </div>
                <div class="hero-meta-card">
                    <span>اسم النظام</span>
                    <strong><?php echo e($store['name']); ?></strong>
                </div>
                <div class="store-chip">
                    <img src="<?php echo e($store['logo']); ?>" alt="شعار <?php echo e($store['name']); ?>" class="store-logo small-logo">
                    <span>✨ <?php echo e($store['name']); ?></span>
                </div>
            </div>
        </header>

        <div class="cards dashboard-stats">
            <div class="card stat-card stat-card-users">
                <div class="stat-card-head">
                    <div>
                        <span class="stat-kicker">إجمالي السجلات</span>
                        <h3>عدد المستخدمين</h3>
                    </div>
                    <span class="stat-icon">👥</span>
                </div>
                <div class="stat-number"><?php echo $totalUsers; ?></div>
                <p>عدد المستخدمين الحقيقي المسجلين</p>
            </div>
            <div class="card stat-card stat-card-managers">
                <div class="stat-card-head">
                    <div>
                        <span class="stat-kicker">صلاحيات الإدارة</span>
                        <h3>عدد المديرين</h3>
                    </div>
                    <span class="stat-icon">🛡️</span>
                </div>
                <div class="stat-number"><?php echo $totalManagers; ?></div>
                <p>إجمالي المستخدمين بصلاحية مدير</p>
            </div>
            <div class="card stat-card stat-card-supervisors">
                <div class="stat-card-head">
                    <div>
                        <span class="stat-kicker">الإشراف والمتابعة</span>
                        <h3>عدد المشرفين</h3>
                    </div>
                    <span class="stat-icon">📈</span>
                </div>
                <div class="stat-number"><?php echo $totalSupervisors; ?></div>
                <p>إجمالي المستخدمين بصلاحية مشرف</p>
            </div>
        </div>

        <section class="dashboard-actions-section">
            <div class="page-intro">
                <h2>أقسام لوحة التحكم</h2>
                <p>أزرار جاهزة للتفعيل البرمجي لاحقًا.</p>
            </div>

            <div class="cards dashboard-actions">
                <button type="button" class="card dashboard-action-btn" aria-label="قسم الموردين"><span class="dashboard-action-icon" aria-hidden="true">🚚</span><span class="dashboard-action-label">موردين</span></button>
                <button type="button" class="card dashboard-action-btn" aria-label="قسم المخزن"><span class="dashboard-action-icon" aria-hidden="true">🏬</span><span class="dashboard-action-label">مخزن</span></button>
                <button type="button" class="card dashboard-action-btn" aria-label="قسم الأصناف"><span class="dashboard-action-icon" aria-hidden="true">📦</span><span class="dashboard-action-label">أصناف</span></button>
                <button type="button" class="card dashboard-action-btn" aria-label="قسم المبيعات"><span class="dashboard-action-icon" aria-hidden="true">🛒</span><span class="dashboard-action-label">مبيعات</span></button>
                <button type="button" class="card dashboard-action-btn" aria-label="قسم العملاء"><span class="dashboard-action-icon" aria-hidden="true">🤝</span><span class="dashboard-action-label">عملاء</span></button>
                <button type="button" class="card dashboard-action-btn" aria-label="قسم المصروفات"><span class="dashboard-action-icon" aria-hidden="true">💸</span><span class="dashboard-action-label">مصروفات</span></button>
                <button type="button" class="card dashboard-action-btn" aria-label="قسم الموظفين"><span class="dashboard-action-icon" aria-hidden="true">👔</span><span class="dashboard-action-label">موظفين</span></button>
                <button type="button" class="card dashboard-action-btn" aria-label="قسم قبض الموظفين"><span class="dashboard-action-icon" aria-hidden="true">💵</span><span class="dashboard-action-label">قبض موظفين</span></button>
                <button type="button" class="card dashboard-action-btn" aria-label="قسم الإحصائيات"><span class="dashboard-action-icon" aria-hidden="true">📊</span><span class="dashboard-action-label">إحصائيات</span></button>
            </div>
        </section>

    </main>
</div>

<script src="assets/js/theme.js"></script>
</body>
</html>
