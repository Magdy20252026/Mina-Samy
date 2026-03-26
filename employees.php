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

ensureEmployeesTable($pdo);

$error = '';
$employeeName = '';
$employeeSalary = '';
$editingEmployeeId = 0;
$success = trim((string) ($_GET['success'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = trim((string) ($_POST['form_action'] ?? ''));

    if ($formAction === 'add_employee' || $formAction === 'update_employee') {
        $employeeName = trim((string) ($_POST['employee_name'] ?? ''));
        $employeeSalary = trim((string) ($_POST['salary'] ?? ''));
        $editingEmployeeId = (int) ($_POST['employee_id'] ?? 0);

        if ($employeeName === '' || $employeeSalary === '') {
            $error = 'اسم الموظف والراتب مطلوبان';
        } elseif (!is_numeric($employeeSalary)) {
            $error = 'أدخل راتبًا صحيحًا';
        } else {
            $salaryValue = (float) $employeeSalary;

            if ($salaryValue < 0) {
                $error = 'الراتب يجب أن يكون صفرًا أو أكبر';
            } elseif ($formAction === 'update_employee') {
                if ($editingEmployeeId <= 0) {
                    $error = 'تعذر تحديد الموظف المطلوب تعديله';
                } else {
                    $employeeCheck = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
                    $employeeCheck->execute([$editingEmployeeId]);

                    if (!$employeeCheck->fetchColumn()) {
                        $error = 'الموظف المطلوب تعديله غير موجود';
                        $editingEmployeeId = 0;
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE employees
                            SET employee_name = ?, salary = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$employeeName, $salaryValue, $editingEmployeeId]);

                        header('Location: employees.php?success=updated');
                        exit;
                    }
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO employees (employee_name, salary, created_at)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$employeeName, $salaryValue, getEgyptDateTimeValue()]);

                header('Location: employees.php?success=created');
                exit;
            }
        }
    } elseif ($formAction === 'delete_employee') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);

        if ($employeeId <= 0) {
            $error = 'تعذر تحديد الموظف المطلوب حذفه';
        } else {
            $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
            $stmt->execute([$employeeId]);

            if ($stmt->rowCount() > 0) {
                header('Location: employees.php?success=deleted');
                exit;
            }

            $error = 'الموظف المطلوب حذفه غير موجود';
        }
    }
}

if ($editingEmployeeId === 0) {
    $requestedEditId = (int) ($_GET['edit'] ?? 0);

    if ($requestedEditId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, employee_name, salary
            FROM employees
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$requestedEditId]);
        $employeeToEdit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($employeeToEdit) {
            $editingEmployeeId = (int) $employeeToEdit['id'];
            $employeeName = (string) $employeeToEdit['employee_name'];
            $employeeSalary = (string) $employeeToEdit['salary'];
        } else {
            $error = 'الموظف المطلوب تعديله غير موجود';
        }
    }
}

$stmt = $pdo->query("
    SELECT id, employee_name, salary, created_at
    FROM employees
    ORDER BY created_at DESC, id DESC
");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الموظفين - <?php echo e($store['name']); ?></title>
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
            <?php echo renderSidebarSections('موظفين'); ?>
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
            <h1>إدارة الموظفين</h1>
        </header>

        <div class="form-card">
            <div class="page-header">
                <div>
                    <h2><?php echo $editingEmployeeId > 0 ? 'تعديل بيانات الموظف' : 'إضافة موظف جديد'; ?></h2>
                    <p>بعد الإضافة أو التعديل أو الحذف سيتم مسح الحقول تلقائيًا.</p>
                </div>

                <?php if ($editingEmployeeId > 0): ?>
                    <div class="table-actions">
                        <a class="inline-link small-link secondary-button" href="employees.php">إلغاء التعديل</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success === 'created'): ?>
                <div class="alert alert-success">تمت إضافة الموظف بنجاح</div>
            <?php elseif ($success === 'updated'): ?>
                <div class="alert alert-success">تم تعديل بيانات الموظف بنجاح</div>
            <?php elseif ($success === 'deleted'): ?>
                <div class="alert alert-success">تم حذف الموظف بنجاح</div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="form_action" value="<?php echo $editingEmployeeId > 0 ? 'update_employee' : 'add_employee'; ?>">
                <?php if ($editingEmployeeId > 0): ?>
                    <input type="hidden" name="employee_id" value="<?php echo (int) $editingEmployeeId; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="employee_name">اسم الموظف</label>
                    <input id="employee_name" type="text" name="employee_name" value="<?php echo e($employeeName); ?>" required>
                </div>

                <div class="form-group">
                    <label for="salary">راتب الموظف</label>
                    <p id="salary_help">أدخل الراتب بالأرقام ويمكن إضافة خانتين عشريتين عند الحاجة.</p>
                    <input id="salary" type="number" name="salary" min="0" step="0.01" aria-describedby="salary_help" value="<?php echo e($employeeSalary); ?>" required>
                </div>

                <button type="submit"><?php echo $editingEmployeeId > 0 ? '💾 حفظ التعديل' : '💾 إضافة الموظف'; ?></button>
            </form>
        </div>

        <div class="table-card">
            <div class="page-header">
                <div>
                    <h2>جدول الموظفين المسجلين</h2>
                    <p>يعرض الاسم والراتب وتاريخ تسجيل كل موظف.</p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم الموظف</th>
                        <th>الراتب</th>
                        <th>تاريخ التسجيل</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($employees): ?>
                        <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td><?php echo (int) $employee['id']; ?></td>
                                <td><?php echo e($employee['employee_name']); ?></td>
                                <td><?php echo e(formatMoney($employee['salary'])); ?> ج.م</td>
                                <td><?php echo e(formatDateTimeForDisplay($employee['created_at'])); ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a class="inline-link small-link secondary-button" href="employees.php?edit=<?php echo (int) $employee['id']; ?>">تعديل</a>
                                        <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف الموظف؟');">
                                            <input type="hidden" name="form_action" value="delete_employee">
                                            <input type="hidden" name="employee_id" value="<?php echo (int) $employee['id']; ?>">
                                            <button type="submit">حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">لا يوجد موظفون مسجلون حتى الآن.</td>
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
