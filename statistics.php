<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

function statisticsTableExists(PDO $pdo, $tableName)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $tableName)) {
        return false;
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([(string) $tableName]);

    return (bool) $stmt->fetchColumn();
}

function fetchStatisticsSum(PDO $pdo, $tableName, $valueColumn, $dateColumn, $startDate, $endDate)
{
    if (
        !statisticsTableExists($pdo, $tableName)
        || !preg_match('/^[A-Za-z0-9_]+$/', (string) $valueColumn)
        || !preg_match('/^[A-Za-z0-9_]+$/', (string) $dateColumn)
    ) {
        return 0.0;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM($valueColumn), 0) AS total_value
        FROM $tableName
        WHERE $dateColumn BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);

    return (float) ($stmt->fetchColumn() ?: 0);
}

$requestedPeriod = trim((string) ($_GET['period'] ?? 'monthly'));
$requestedDate = trim((string) ($_GET['date'] ?? ''));
$range = getStatisticsPeriodRange($requestedPeriod, $requestedDate);
$pageTitle = formatStatisticsPeriodLabel($range);

$supplierPaidTotal = fetchStatisticsSum(
    $pdo,
    'supplier_invoice_payments',
    'payment_amount',
    'created_at',
    $range['start'],
    $range['end']
);
$supplierRemainingTotal = fetchStatisticsSum(
    $pdo,
    'supplier_invoices',
    'amount_due',
    'created_at',
    $range['start'],
    $range['end']
);
$expensesTotal = fetchStatisticsSum(
    $pdo,
    'expenses',
    'amount',
    'created_at',
    $range['start'],
    $range['end']
);
$employeeSalariesTotal = fetchStatisticsSum(
    $pdo,
    'employee_salary_payments',
    'amount',
    'paid_at',
    $range['start'],
    $range['end']
);
$salesPaidTotal = fetchStatisticsSum(
    $pdo,
    'customer_invoice_payments',
    'payment_amount',
    'created_at',
    $range['start'],
    $range['end']
);
$customersDebtTotal = fetchStatisticsSum(
    $pdo,
    'customer_invoices',
    'amount_due',
    'created_at',
    $range['start'],
    $range['end']
);

$statisticsCards = [
    [
        'title' => 'إجمالي المدفوع للموردين',
        'value' => $supplierPaidTotal,
        'icon' => '🚚',
        'class' => 'stat-card-users',
        'description' => 'إجمالي المبالغ المسددة للموردين داخل الفترة المحددة.',
    ],
    [
        'title' => 'إجمالي المتبقي للموردين',
        'value' => $supplierRemainingTotal,
        'icon' => '📦',
        'class' => 'stat-card-managers',
        'description' => 'إجمالي المبالغ المتبقية على فواتير الموردين المسجلة خلال الفترة.',
    ],
    [
        'title' => 'إجمالي المصروفات',
        'value' => $expensesTotal,
        'icon' => '💸',
        'class' => 'stat-card-supervisors',
        'description' => 'إجمالي المصروفات المسجلة في نفس الفترة.',
    ],
    [
        'title' => 'إجمالي رواتب الموظفين',
        'value' => $employeeSalariesTotal,
        'icon' => '💵',
        'class' => 'stat-card-suppliers',
        'description' => 'إجمالي الرواتب المصروفة فعليًا حسب تاريخ الصرف.',
    ],
    [
        'title' => 'إجمالي المبيعات المسددة',
        'value' => $salesPaidTotal,
        'icon' => '🛒',
        'class' => 'stat-card-users',
        'description' => 'إجمالي المبالغ المحصلة من العملاء خلال الفترة.',
    ],
    [
        'title' => 'إجمالي مديونية العملاء',
        'value' => $customersDebtTotal,
        'icon' => '🤝',
        'class' => 'stat-card-managers',
        'description' => 'إجمالي المبالغ المتبقية على فواتير العملاء المسجلة خلال الفترة.',
    ],
];

$baseQuery = ['period' => $range['period']];
$previousQuery = http_build_query(array_merge($baseQuery, ['date' => $range['previous_anchor_date']]));
$nextQuery = http_build_query(array_merge($baseQuery, ['date' => $range['next_anchor_date']]));
$currentQuery = http_build_query($baseQuery);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإحصائيات - <?php echo e($store['name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .statistics-toolbar,
        .statistics-summary-table {
            margin-bottom: 24px;
        }

        .statistics-toolbar .page-header {
            align-items: flex-start;
            gap: 20px;
            flex-wrap: wrap;
        }

        .statistics-filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            width: 100%;
        }

        .statistics-filter-actions {
            display: flex;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
        }

        .statistics-filter-actions button,
        .statistics-navigation a,
        .statistics-navigation span {
            width: auto;
            min-width: 130px;
        }

        .statistics-navigation {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .statistics-navigation span {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(148, 163, 184, 0.16);
            color: var(--muted);
            border: 1px solid var(--border);
        }

        .statistics-note {
            margin: 0;
            color: var(--muted);
            line-height: 1.8;
        }

        .statistics-summary-table table td:first-child,
        .statistics-summary-table table th:first-child {
            width: 34%;
        }

        @media (max-width: 768px) {
            .statistics-filter-actions,
            .statistics-navigation {
                width: 100%;
            }

            .statistics-filter-actions button,
            .statistics-navigation a,
            .statistics-navigation span {
                width: 100%;
            }
        }
    </style>
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
            <?php echo renderSidebarSections('إحصائيات'); ?>
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
            <div>
                <h1>الإحصائيات</h1>
                <p class="statistics-note">عرض الإحصائيات اليومية أو الأسبوعية أو الشهرية مع إمكانية التنقل بين الفترات السابقة واللاحقة.</p>
            </div>
            <div class="store-chip">
                <img src="<?php echo e($store['logo']); ?>" alt="شعار <?php echo e($store['name']); ?>" class="store-logo small-logo">
                <span>📊 <?php echo e($pageTitle); ?></span>
            </div>
        </header>

        <div class="form-card statistics-toolbar">
            <div class="page-header">
                <div>
                    <h2>تحديد الفترة</h2>
                    <p class="statistics-note">يمكنك التبديل بين اليومي والأسبوعي والشهري والرجوع للفترات السابقة أو العودة للفترة الحالية.</p>
                </div>
            </div>

            <form method="GET" class="statistics-filter-form">
                <div class="form-group">
                    <label for="period">نوع الإحصائية</label>
                    <select id="period" name="period">
                        <option value="daily" <?php echo $range['period'] === 'daily' ? 'selected' : ''; ?>>يومي</option>
                        <option value="weekly" <?php echo $range['period'] === 'weekly' ? 'selected' : ''; ?>>أسبوعي</option>
                        <option value="monthly" <?php echo $range['period'] === 'monthly' ? 'selected' : ''; ?>>شهري</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="date">تاريخ الارتكاز</label>
                    <input id="date" type="date" name="date" value="<?php echo e($range['anchor_date']); ?>">
                </div>

                <div class="statistics-filter-actions">
                    <button type="submit">تطبيق الإحصائية</button>
                    <a class="inline-link" href="statistics.php?<?php echo e($currentQuery); ?>">الفترة الحالية</a>
                </div>
            </form>

            <div class="statistics-navigation">
                <a class="inline-link" href="statistics.php?<?php echo e($previousQuery); ?>">⬅ الفترة السابقة</a>
                <?php if ($range['is_current_period']): ?>
                    <span>الفترة الحالية</span>
                <?php else: ?>
                    <a class="inline-link" href="statistics.php?<?php echo e($nextQuery); ?>">الفترة التالية ➡</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="cards">
            <?php foreach ($statisticsCards as $card): ?>
                <div class="card stat-card <?php echo e($card['class']); ?>">
                    <div class="stat-card-head">
                        <div>
                            <span class="stat-kicker"><?php echo e($pageTitle); ?></span>
                            <h3><?php echo e($card['title']); ?></h3>
                        </div>
                        <span class="stat-icon"><?php echo e($card['icon']); ?></span>
                    </div>
                    <div class="stat-number"><?php echo e(formatMoney($card['value'])); ?></div>
                    <p><?php echo e($card['description']); ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="table-card statistics-summary-table">
            <div class="page-header">
                <div>
                    <h2>ملخص الإحصائيات</h2>
                    <p class="statistics-note">تم الاعتماد على تواريخ التسجيل أو السداد الفعلية داخل الفترة المحددة.</p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>البند</th>
                        <th>الإجمالي</th>
                        <th>الوصف</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statisticsCards as $card): ?>
                        <tr>
                            <td><?php echo e($card['title']); ?></td>
                            <td><?php echo e(formatMoney($card['value'])); ?> ج.م</td>
                            <td><?php echo e($card['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script src="assets/js/theme.js"></script>
</body>
</html>
