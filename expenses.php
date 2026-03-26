<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

function ensureExpensesTable(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS expenses (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            user_id INT(11) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

ensureExpensesTable($pdo);

$error = '';
$description = '';
$amount = '';
$success = trim((string) ($_GET['success'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim((string) ($_POST['description'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $userId = $_SESSION['user_id'] ?? null;

    if ($description === '' || $amount === '') {
        $error = 'البيان والمبلغ مطلوبان';
    } elseif (!is_numeric($amount)) {
        $error = 'أدخل مبلغًا صحيحًا';
    } elseif ($userId === null) {
        $error = 'تعذر تحديد المستخدم الحالي';
    } else {
        $amountValue = (float) $amount;

        if ($amountValue <= 0) {
            $error = 'أدخل مبلغًا صحيحًا أكبر من صفر';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO expenses (description, amount, user_id, created_at)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $description,
                $amountValue,
                (int) $userId,
                getEgyptDateTimeValue(),
            ]);

            header('Location: expenses.php?success=created');
            exit;
        }
    }
}

$stmt = $pdo->query("
    SELECT
        expenses.id,
        expenses.description,
        expenses.amount,
        expenses.created_at,
        users.username
    FROM expenses
    LEFT JOIN users ON users.id = expenses.user_id
    ORDER BY expenses.created_at DESC, expenses.id DESC
");
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المصروفات - <?php echo e($store['name']); ?></title>
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
            <a href="users.php"><span class="nav-icon">👥</span><span class="nav-label">المستخدمين</span></a>
            <?php echo renderSidebarSections('مصروفات'); ?>
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
        <header class="topbar">
            <h1>إدارة المصروفات</h1>
        </header>

        <div class="form-card">
            <div class="page-header">
                <div>
                    <h2>تسجيل مصروف جديد</h2>
                    <p>يتم حفظ الوقت والتاريخ تلقائيًا بتوقيت جمهورية مصر العربية مع اسم المستخدم الحالي.</p>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success === 'created'): ?>
                <div class="alert alert-success">تم تسجيل المصروف بنجاح</div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="description">البيان</label>
                    <input id="description" type="text" name="description" value="<?php echo e($description); ?>" required>
                </div>

                <div class="form-group">
                    <label for="amount">المبلغ</label>
                    <input id="amount" type="number" name="amount" min="0.01" step="0.01" value="<?php echo e($amount); ?>" required>
                </div>

                <button type="submit">💾 حفظ المصروف</button>
            </form>
        </div>

        <div class="table-card">
            <div class="page-header">
                <div>
                    <h2>جدول المصروفات المسجلة</h2>
                    <p>جميع الأوقات المعروضة بتوقيت جمهورية مصر العربية.</p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>البيان</th>
                        <th>المبلغ</th>
                        <th>اسم المستخدم</th>
                        <th>التاريخ والوقت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($expenses): ?>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?php echo (int) $expense['id']; ?></td>
                                <td><?php echo e($expense['description']); ?></td>
                                <td><?php echo e(formatMoney($expense['amount'])); ?></td>
                                <td><?php echo e($expense['username'] ?: 'غير معروف'); ?></td>
                                <td><?php echo e(formatDateTimeForDisplay($expense['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">لا توجد مصروفات مسجلة حتى الآن.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script src="assets/js/theme.js"></script>
</body>
</html>
