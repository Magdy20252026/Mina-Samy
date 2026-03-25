<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'مدير') {
    die('غير مصرح لك');
}

$error = '';
$success = isset($_GET['saved']) ? 'تم تحديث بيانات المتجر بنجاح' : '';
$storeSettingsFile = __DIR__ . '/config/store.json';
$logoPath = $store['logo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');

    if ($name === '' || $subtitle === '') {
        $error = 'اسم المتجر والوصف مطلوبان';
    } else {
        $settings = [
            'name' => $name,
            'subtitle' => $subtitle,
            'logo' => $store['logo'],
        ];

        if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                $error = 'فشل رفع الشعار، حاول مرة أخرى';
            } else {
                $extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $mimeType = function_exists('finfo_open')
                    ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $_FILES['logo']['tmp_name'])
                    : ($_FILES['logo']['type'] ?? '');

                if ($extension !== 'png' || $mimeType !== 'image/png') {
                    $error = 'يسمح فقط برفع ملفات PNG للشعار';
                } else {
                    $targetRelativePath = 'assets/images/store-logo.png';
                    $targetAbsolutePath = __DIR__ . '/' . $targetRelativePath;

                    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $targetAbsolutePath)) {
                        $error = 'تعذر حفظ ملف الشعار داخل النظام';
                    } else {
                        $settings['logo'] = $targetRelativePath;
                        $logoPath = $targetRelativePath;
                    }
                }
            }
        }

        if ($error === '') {
            $encodedSettings = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encodedSettings === false || file_put_contents($storeSettingsFile, $encodedSettings . PHP_EOL, LOCK_EX) === false) {
                $error = 'تعذر حفظ إعدادات المتجر';
            } else {
                header('Location: settings.php?saved=1');
                exit;
            }
        }

        $store = [
            'name' => $name === '' ? $store['name'] : $name,
            'subtitle' => $subtitle === '' ? $store['subtitle'] : $subtitle,
            'logo' => $logoPath,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات المتجر - <?php echo e($store['name']); ?></title>
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
            <a href="settings.php" class="active"><span class="nav-icon">⚙️</span><span>إعدادات المتجر</span></a>
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
                <h1>إعدادات المتجر</h1>
                <small>خصص الاسم والوصف وارفع شعار PNG دائري</small>
            </div>
            <div class="store-chip">
                <img src="<?php echo e($store['logo']); ?>" alt="شعار <?php echo e($store['name']); ?>" class="store-logo small-logo">
                <span>✨ <?php echo e($store['name']); ?></span>
            </div>
        </header>

        <div class="settings-grid">
            <div class="form-card">
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>اسم المتجر</label>
                        <input type="text" name="name" value="<?php echo e($store['name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>الوصف المختصر</label>
                        <input type="text" name="subtitle" value="<?php echo e($store['subtitle']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>شعار المتجر (PNG فقط)</label>
                        <input type="file" name="logo" accept=".png,image/png">
                    </div>

                    <button type="submit">💾 حفظ الإعدادات</button>
                </form>
            </div>

            <div class="table-card settings-preview">
                <img src="<?php echo e($store['logo']); ?>" alt="معاينة شعار <?php echo e($store['name']); ?>" class="store-logo">
                <h2 class="brand"><?php echo e($store['name']); ?></h2>
                <p><?php echo e($store['subtitle']); ?></p>
            </div>
        </div>
    </main>
</div>

<script src="assets/js/theme.js"></script>
</body>
</html>
