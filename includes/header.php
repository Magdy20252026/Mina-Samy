<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم Mina Samy</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-layout">
    <aside class="sidebar">
        <div>
            <h2 class="brand">Mina Samy</h2>
            <p class="brand-subtitle">نظام المبيعات</p>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php">لوحة التحكم</a>
            <a href="users.php">المستخدمين</a>
            <a href="logout.php" class="logout-btn">تسجيل الخروج</a>
        </nav>

        <div class="theme-toggle-box">
            <span>الوضع</span>
            <label class="switch">
                <input type="checkbox" id="themeToggle">
                <span class="slider"></span>
            </label>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <h1>مرحبًا، <?php echo e($_SESSION['username'] ?? ''); ?></h1>
                <small>الصلاحية: <?php echo e($_SESSION['role'] ?? ''); ?></small>
            </div>
        </header>