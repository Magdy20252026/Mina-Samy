<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

function ensureEmployeesTable(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employees (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_name VARCHAR(255) NOT NULL,
            salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensureEmployeeSalaryPaymentsTable(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_salary_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_month CHAR(7) NOT NULL,
            paid_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            KEY employee_id (employee_id),
            KEY payment_month (payment_month),
            KEY paid_at (paid_at),
            UNIQUE KEY employee_month_unique (employee_id, payment_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function getCurrentEgyptMonthData()
{
    $date = new DateTime('now', new DateTimeZone('Africa/Cairo'));

    return [
        'key' => $date->format('Y-m'),
        'label' => $date->format('m/Y'),
    ];
}

function getEmployeeForSalaryPayment(PDO $pdo, $employeeId, $monthKey)
{
    if ($employeeId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            employees.id,
            employees.employee_name,
            employees.salary,
            salary_payments.id AS payment_id
        FROM employees
        LEFT JOIN employee_salary_payments AS salary_payments
            ON salary_payments.employee_id = employees.id
            AND salary_payments.payment_month = ?
        WHERE employees.id = ?
        LIMIT 1
    ");
    $stmt->execute([$monthKey, $employeeId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

ensureEmployeesTable($pdo);
ensureEmployeeSalaryPaymentsTable($pdo);

$currentMonth = getCurrentEgyptMonthData();
$currentMonthKey = $currentMonth['key'];
$currentMonthLabel = $currentMonth['label'];
$error = '';
$success = trim((string) ($_GET['success'] ?? ''));
$paymentAmount = '';
$selectedEmployee = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = trim((string) ($_POST['form_action'] ?? ''));

    if ($formAction === 'pay_salary') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $paymentAmount = trim((string) ($_POST['amount'] ?? ''));

        if ($employeeId <= 0) {
            $error = 'تعذر تحديد الموظف المطلوب صرف راتبه';
        } else {
            $selectedEmployee = getEmployeeForSalaryPayment($pdo, $employeeId, $currentMonthKey);
        }

        if ($error === '' && !$selectedEmployee) {
            $error = 'الموظف المطلوب غير موجود';
        } elseif ($paymentAmount === '') {
            $error = 'قيمة الراتب المصروف مطلوبة';
        } elseif (!is_numeric($paymentAmount)) {
            $error = 'أدخل قيمة صحيحة للراتب المصروف';
        } else {
            $amountValue = (float) $paymentAmount;

            if ($amountValue <= 0) {
                $error = 'قيمة الراتب المصروف يجب أن تكون أكبر من صفر';
            } else {
                if (!empty($selectedEmployee['payment_id'])) {
                    $error = 'تم صرف راتب هذا الموظف بالفعل خلال الشهر الحالي';
                } else {
                    $paidAt = getEgyptDateTimeValue();
                    $stmt = $pdo->prepare("
                        INSERT INTO employee_salary_payments (employee_id, amount, payment_month, paid_at, created_at)
                        VALUES (?, ?, ?, ?, ?)
                    ");

                    try {
                        $stmt->execute([
                            $employeeId,
                            $amountValue,
                            $currentMonthKey,
                            $paidAt,
                            $paidAt,
                        ]);

                        header('Location: employee_payments.php?success=paid');
                        exit;
                    } catch (PDOException $exception) {
                        $error = 'تعذر حفظ عملية صرف الراتب الآن، حاول مرة أخرى';
                    }
                }
            }
        }
    }
}

$requestedEmployeeId = (int) ($_GET['pay'] ?? 0);

if ($selectedEmployee === null && $requestedEmployeeId > 0) {
    $selectedEmployee = getEmployeeForSalaryPayment($pdo, $requestedEmployeeId, $currentMonthKey);

    if (!$selectedEmployee) {
        $error = 'الموظف المطلوب غير موجود';
    } elseif (!empty($selectedEmployee['payment_id'])) {
        $error = 'تم صرف راتب هذا الموظف بالفعل خلال الشهر الحالي';
        $selectedEmployee = null;
    } elseif ($paymentAmount === '') {
        $paymentAmount = (string) $selectedEmployee['salary'];
    }
} elseif ($selectedEmployee && $paymentAmount === '') {
    $paymentAmount = (string) $selectedEmployee['salary'];
}

$stmtUnpaid = $pdo->prepare("
    SELECT
        employees.id,
        employees.employee_name,
        employees.salary,
        employees.created_at
    FROM employees
    LEFT JOIN employee_salary_payments AS salary_payments
        ON salary_payments.employee_id = employees.id
        AND salary_payments.payment_month = ?
    WHERE salary_payments.id IS NULL
    ORDER BY employees.created_at DESC, employees.id DESC
");
$stmtUnpaid->execute([$currentMonthKey]);
$unpaidEmployees = $stmtUnpaid->fetchAll(PDO::FETCH_ASSOC);

$stmtPaid = $pdo->prepare("
    SELECT
        employees.id,
        employees.employee_name,
        employees.salary,
        salary_payments.amount,
        salary_payments.paid_at
    FROM employee_salary_payments AS salary_payments
    INNER JOIN employees ON employees.id = salary_payments.employee_id
    WHERE salary_payments.payment_month = ?
    ORDER BY salary_payments.paid_at DESC, salary_payments.id DESC
");
$stmtPaid->execute([$currentMonthKey]);
$paidEmployees = $stmtPaid->fetchAll(PDO::FETCH_ASSOC);

$totalPaidSalaries = 0.0;

foreach ($paidEmployees as $paidEmployee) {
    $totalPaidSalaries += (float) ($paidEmployee['amount'] ?? 0);
}

$paidEmployeesCount = count($paidEmployees);
$unpaidEmployeesCount = count($unpaidEmployees);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قبض الموظفين - <?php echo e($store['name']); ?></title>
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
            <?php echo renderSidebarSections('قبض موظفين'); ?>
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
            <h1>قبض الموظفين</h1>
        </header>

        <div class="cards">
            <div class="card stat-card stat-card-users">
                <div class="stat-card-head">
                    <div>
                        <span class="stat-kicker">الشهر الحالي</span>
                        <h3>إجمالي المرتبات المصروفة</h3>
                    </div>
                    <span class="stat-icon">💰</span>
                </div>
                <div class="stat-number"><?php echo e(formatMoney($totalPaidSalaries)); ?></div>
                <p>ج.م خلال شهر <?php echo e($currentMonthLabel); ?></p>
            </div>
            <div class="card stat-card stat-card-managers">
                <div class="stat-card-head">
                    <div>
                        <span class="stat-kicker">المقبوضون</span>
                        <h3>عدد الموظفين المقبوضين</h3>
                    </div>
                    <span class="stat-icon">✅</span>
                </div>
                <div class="stat-number"><?php echo $paidEmployeesCount; ?></div>
                <p>موظف تم صرف راتبه هذا الشهر</p>
            </div>
            <div class="card stat-card stat-card-supervisors">
                <div class="stat-card-head">
                    <div>
                        <span class="stat-kicker">المتبقي</span>
                        <h3>عدد الموظفين غير المقبوضين</h3>
                    </div>
                    <span class="stat-icon">⏳</span>
                </div>
                <div class="stat-number"><?php echo $unpaidEmployeesCount; ?></div>
                <p>موظف لم يتم صرف راتبه خلال شهر <?php echo e($currentMonthLabel); ?></p>
            </div>
        </div>

        <?php if ($selectedEmployee): ?>
            <div class="form-card" style="margin-top: 24px;">
                <div class="page-header">
                    <div>
                        <h2>صرف راتب الموظف</h2>
                        <p>سجّل المبلغ المصروف للموظف <?php echo e($selectedEmployee['employee_name']); ?> عن شهر <?php echo e($currentMonthLabel); ?>.</p>
                    </div>

                    <div class="table-actions">
                        <a class="inline-link small-link secondary-button" href="employee_payments.php">إلغاء</a>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-error"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if ($success === 'paid'): ?>
                    <div class="alert alert-success">تم صرف الراتب بنجاح</div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="form_action" value="pay_salary">
                    <input type="hidden" name="employee_id" value="<?php echo (int) $selectedEmployee['id']; ?>">

                    <div class="form-group">
                        <label>اسم الموظف</label>
                        <input type="text" value="<?php echo e($selectedEmployee['employee_name']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>الراتب الأساسي</label>
                        <input type="text" value="<?php echo e(formatMoney($selectedEmployee['salary'])); ?> ج.م" readonly>
                    </div>

                    <div class="form-group">
                        <label for="amount">الراتب المصروف</label>
                        <input id="amount" type="number" name="amount" min="0.01" step="0.01" value="<?php echo e($paymentAmount); ?>" required>
                    </div>

                    <button type="submit">صرف الراتب</button>
                </form>
            </div>
        <?php else: ?>
            <div class="form-card" style="margin-top: 24px;">
                <div class="page-header">
                    <div>
                        <h2>صرف الرواتب الشهرية</h2>
                        <p>اضغط على زر صرف المرتب أمام أي موظف من جدول غير المقبوضين لتسجيل الراتب المصروف.</p>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-error"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if ($success === 'paid'): ?>
                    <div class="alert alert-success">تم صرف الراتب بنجاح وانتقل الموظف إلى جدول المقبوضين خلال هذا الشهر.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="table-card" style="margin-top: 24px;">
            <div class="page-header">
                <div>
                    <h2>جدول الموظفين الذين لم يقبضوا خلال الشهر</h2>
                    <p>يعرض الموظفين الذين لم يتم صرف راتب لهم خلال شهر <?php echo e($currentMonthLabel); ?>.</p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم الموظف</th>
                        <th>الراتب الأساسي</th>
                        <th>تاريخ التسجيل</th>
                        <th>الإجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($unpaidEmployees): ?>
                        <?php foreach ($unpaidEmployees as $employee): ?>
                            <tr>
                                <td><?php echo (int) $employee['id']; ?></td>
                                <td><?php echo e($employee['employee_name']); ?></td>
                                <td><?php echo e(formatMoney($employee['salary'])); ?> ج.م</td>
                                <td><?php echo e(formatDateTimeForDisplay($employee['created_at'])); ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a class="inline-link small-link secondary-button" href="employee_payments.php?pay=<?php echo (int) $employee['id']; ?>">صرف المرتب</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">لا يوجد موظفون بانتظار صرف رواتب خلال هذا الشهر.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-card" style="margin-top: 24px;">
            <div class="page-header">
                <div>
                    <h2>جدول الموظفين الذين تم قبضهم خلال الشهر</h2>
                    <p>يعرض الموظفين الذين تم صرف رواتبهم خلال شهر <?php echo e($currentMonthLabel); ?>.</p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم الموظف</th>
                        <th>الراتب الأساسي</th>
                        <th>الراتب المصروف</th>
                        <th>تاريخ ووقت الصرف</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($paidEmployees): ?>
                        <?php foreach ($paidEmployees as $employee): ?>
                            <tr>
                                <td><?php echo (int) $employee['id']; ?></td>
                                <td><?php echo e($employee['employee_name']); ?></td>
                                <td><?php echo e(formatMoney($employee['salary'])); ?> ج.م</td>
                                <td><?php echo e(formatMoney($employee['amount'])); ?> ج.م</td>
                                <td><?php echo e(formatDateTimeForDisplay($employee['paid_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">لم يتم صرف أي رواتب خلال هذا الشهر حتى الآن.</td>
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
