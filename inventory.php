<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$error = '';
$success = trim((string) ($_GET['success'] ?? ''));
$submittedAction = trim((string) ($_POST['form_action'] ?? ''));
$issuedSearch = trim((string) ($_GET['issued_search'] ?? ''));

function ensureInventoryTables(PDO $pdo)
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS inventory_items (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            barcode VARCHAR(120) DEFAULT NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) DEFAULT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status ENUM('متاح','منتهي') NOT NULL DEFAULT 'متاح',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY unique_inventory_barcode (barcode),
            KEY inventory_item_name (item_name),
            KEY inventory_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS issued_items (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            inventory_item_id INT(11) NOT NULL,
            barcode VARCHAR(120) DEFAULT NULL,
            item_name VARCHAR(255) NOT NULL,
            issued_quantity DECIMAL(12,2) DEFAULT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            issue_mode ENUM('بعدد','بدون عدد') NOT NULL,
            remaining_status ENUM('متبقي','تم صرفه بالكامل') NOT NULL DEFAULT 'متبقي',
            created_at DATETIME NOT NULL,
            KEY issued_inventory_item_id (inventory_item_id),
            KEY issued_item_name (item_name),
            KEY issued_barcode (barcode),
            CONSTRAINT issued_items_ibfk_1 FOREIGN KEY (inventory_item_id) REFERENCES inventory_items (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }
}

function normalizeInventoryAmount($value)
{
    return round((float) $value, 2);
}

function buildInventoryPageUrl(array $params = [], $fragment = '')
{
    $url = 'inventory.php';

    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    $fragment = ltrim((string) $fragment, '#');
    if ($fragment !== '') {
        $url .= '#' . rawurlencode($fragment);
    }

    return $url;
}

function fetchInventoryItemById(PDO $pdo, $itemId)
{
    if ($itemId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            i.*,
            COUNT(ii.id) AS issued_times
        FROM inventory_items i
        LEFT JOIN issued_items ii ON ii.inventory_item_id = i.id
        WHERE i.id = ?
        GROUP BY i.id, i.barcode, i.item_name, i.quantity, i.unit_price, i.status, i.created_at, i.updated_at
        LIMIT 1
    ");
    $stmt->execute([(int) $itemId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchInventoryItems(PDO $pdo)
{
    $stmt = $pdo->query("
        SELECT
            i.*,
            COUNT(ii.id) AS issued_times
        FROM inventory_items i
        LEFT JOIN issued_items ii ON ii.inventory_item_id = i.id
        GROUP BY i.id, i.barcode, i.item_name, i.quantity, i.unit_price, i.status, i.created_at, i.updated_at
        ORDER BY CASE WHEN i.status = 'متاح' THEN 0 ELSE 1 END, i.updated_at DESC, i.id DESC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchIssuedItems(PDO $pdo, $searchTerm)
{
    $searchTerm = trim((string) $searchTerm);
    $sql = "
        SELECT
            ii.*,
            i.status AS inventory_status
        FROM issued_items ii
        INNER JOIN inventory_items i ON i.id = ii.inventory_item_id
    ";
    $params = [];

    if ($searchTerm !== '') {
        $sql .= " WHERE ii.item_name LIKE ? OR COALESCE(ii.barcode, '') LIKE ? ";
        $likeValue = '%' . $searchTerm . '%';
        $params[] = $likeValue;
        $params[] = $likeValue;
    }

    $sql .= " ORDER BY ii.created_at DESC, ii.id DESC ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchIssuedItemSuggestions(PDO $pdo)
{
    $stmt = $pdo->query("
        SELECT DISTINCT item_name, barcode
        FROM issued_items
        ORDER BY item_name ASC, barcode ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

ensureInventoryTables($pdo);

if ($submittedAction === 'add_inventory_item') {
    $barcode = trim((string) ($_POST['barcode'] ?? ''));
    $itemName = trim((string) ($_POST['item_name'] ?? ''));
    $quantityValue = trim((string) ($_POST['quantity'] ?? ''));
    $priceValue = trim((string) ($_POST['unit_price'] ?? ''));

    if ($itemName === '') {
        $error = 'يرجى إدخال اسم الصنف قبل الحفظ.';
    } elseif ($priceValue === '' || !is_numeric($priceValue) || normalizeInventoryAmount($priceValue) < 0) {
        $error = 'يرجى إدخال سعر صالح للصنف.';
    } elseif ($quantityValue !== '' && (!is_numeric($quantityValue) || normalizeInventoryAmount($quantityValue) <= 0)) {
        $error = 'إذا تم إدخال عدد فيجب أن يكون أكبر من صفر.';
    } else {
        $createdAt = getEgyptDateTimeValue();
        $quantity = $quantityValue === '' ? null : normalizeInventoryAmount($quantityValue);
        $unitPrice = normalizeInventoryAmount($priceValue);
        $barcode = $barcode === '' ? null : $barcode;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO inventory_items (barcode, item_name, quantity, unit_price, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'متاح', ?, ?)
            ");
            $stmt->execute([$barcode, $itemName, $quantity, $unitPrice, $createdAt, $createdAt]);

            header('Location: ' . buildInventoryPageUrl([
                'success' => 'تم تسجيل الصنف في المخزن بنجاح.',
            ]));
            exit;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000' && $barcode !== null) {
                $error = 'هذا الباركود مسجل بالفعل لصنف آخر.';
            } else {
                throw $exception;
            }
        }
    }
}

if ($submittedAction === 'issue_inventory_item') {
    $inventoryItemId = (int) ($_POST['item_id'] ?? 0);
    $issuePriceValue = trim((string) ($_POST['issue_unit_price'] ?? ''));
    $selectedIssueId = $inventoryItemId;

    if ($inventoryItemId <= 0) {
        $error = 'لم يتم تحديد الصنف المطلوب صرفه.';
    } elseif ($issuePriceValue === '' || !is_numeric($issuePriceValue) || normalizeInventoryAmount($issuePriceValue) < 0) {
        $error = 'يرجى إدخال سعر صرف صالح.';
    } else {
        $issuedUnitPrice = normalizeInventoryAmount($issuePriceValue);

        try {
            $pdo->beginTransaction();

            $itemStmt = $pdo->prepare("
                SELECT id, barcode, item_name, quantity, unit_price, status
                FROM inventory_items
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $itemStmt->execute([$inventoryItemId]);
            $inventoryItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventoryItem) {
                throw new RuntimeException('الصنف المطلوب غير موجود في المخزن.');
            }

            if (($inventoryItem['status'] ?? '') === 'منتهي') {
                throw new RuntimeException('هذا الصنف منتهي بالفعل ولا يمكن صرفه مرة أخرى.');
            }

            $createdAt = getEgyptDateTimeValue();
            $hasTrackedQuantity = $inventoryItem['quantity'] !== null;

            if ($hasTrackedQuantity) {
                $issuedQuantityValue = trim((string) ($_POST['issued_quantity'] ?? ''));

                if ($issuedQuantityValue === '' || !is_numeric($issuedQuantityValue) || normalizeInventoryAmount($issuedQuantityValue) <= 0) {
                    throw new RuntimeException('يرجى إدخال عدد صرف صالح أكبر من صفر.');
                }

                $availableQuantity = normalizeInventoryAmount($inventoryItem['quantity']);
                $issuedQuantity = normalizeInventoryAmount($issuedQuantityValue);

                if ($issuedQuantity > $availableQuantity) {
                    throw new RuntimeException('عدد الصرف أكبر من الكمية المتاحة في المخزن.');
                }

                $remainingQuantity = normalizeInventoryAmount($availableQuantity - $issuedQuantity);
                $newStatus = $remainingQuantity > 0 ? 'متاح' : 'منتهي';
                $remainingStatus = $remainingQuantity > 0 ? 'متبقي' : 'تم صرفه بالكامل';
                $totalPrice = normalizeInventoryAmount($issuedQuantity * $issuedUnitPrice);

                $updateStmt = $pdo->prepare("
                    UPDATE inventory_items
                    SET quantity = ?, status = ?, updated_at = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([$remainingQuantity > 0 ? $remainingQuantity : 0, $newStatus, $createdAt, $inventoryItemId]);

                $insertStmt = $pdo->prepare("
                    INSERT INTO issued_items (
                        inventory_item_id, barcode, item_name, issued_quantity, unit_price, total_price, issue_mode, remaining_status, created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, 'بعدد', ?, ?)
                ");
                $insertStmt->execute([
                    $inventoryItemId,
                    $inventoryItem['barcode'],
                    $inventoryItem['item_name'],
                    $issuedQuantity,
                    $issuedUnitPrice,
                    $totalPrice,
                    $remainingStatus,
                    $createdAt,
                ]);
            } else {
                $remainingStatus = trim((string) ($_POST['remaining_status'] ?? ''));
                $allowedRemainingStatuses = ['متبقي', 'تم صرفه بالكامل'];

                if (!in_array($remainingStatus, $allowedRemainingStatuses, true)) {
                    throw new RuntimeException('يرجى تحديد هل يوجد متبقي من الصنف أم تم صرفه بالكامل.');
                }

                $newStatus = $remainingStatus === 'تم صرفه بالكامل' ? 'منتهي' : 'متاح';

                $updateStmt = $pdo->prepare("
                    UPDATE inventory_items
                    SET status = ?, updated_at = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([$newStatus, $createdAt, $inventoryItemId]);

                $insertStmt = $pdo->prepare("
                    INSERT INTO issued_items (
                        inventory_item_id, barcode, item_name, issued_quantity, unit_price, total_price, issue_mode, remaining_status, created_at
                    )
                    VALUES (?, ?, ?, NULL, ?, ?, 'بدون عدد', ?, ?)
                ");
                $insertStmt->execute([
                    $inventoryItemId,
                    $inventoryItem['barcode'],
                    $inventoryItem['item_name'],
                    $issuedUnitPrice,
                    $issuedUnitPrice,
                    $remainingStatus,
                    $createdAt,
                ]);
            }

            $pdo->commit();

            header('Location: ' . buildInventoryPageUrl([
                'success' => 'تم صرف الصنف وتحديث المخزن بنجاح.',
                'tab' => 'issued',
            ], 'issued-items'));
            exit;
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $exception->getMessage();
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }
}

$selectedIssueId = (int) ($_GET['issue_id'] ?? ($selectedIssueId ?? 0));
$selectedInventoryItem = fetchInventoryItemById($pdo, $selectedIssueId);
$inventoryItems = fetchInventoryItems($pdo);
$issuedItems = fetchIssuedItems($pdo, $issuedSearch);
$issuedSuggestions = fetchIssuedItemSuggestions($pdo);
$inventoryStats = $pdo->query("
    SELECT
        COUNT(*) AS total_inventory_items,
        SUM(CASE WHEN status = 'متاح' THEN 1 ELSE 0 END) AS available_inventory_items,
        SUM(CASE WHEN status = 'منتهي' THEN 1 ELSE 0 END) AS finished_inventory_items
    FROM inventory_items
")->fetch(PDO::FETCH_ASSOC) ?: [];
$issuedStats = $pdo->query("
    SELECT
        COUNT(*) AS total_issued_records,
        COUNT(DISTINCT inventory_item_id) AS total_issued_items
    FROM issued_items
")->fetch(PDO::FETCH_ASSOC) ?: [];
$pageTitle = 'المخزن والأصناف';
$pageDescription = 'تسجيل الأصناف في المخزن، صرفها، ومتابعة الأصناف المصروفة مع البحث والإحصائيات.';
$pageChip = '🏬 إدارة المخزن';
$selectedIssueIsTracked = $selectedInventoryItem && $selectedInventoryItem['quantity'] !== null;
$issueUnitPriceValue = $submittedAction === 'issue_inventory_item'
    ? trim((string) ($_POST['issue_unit_price'] ?? ''))
    : ($selectedInventoryItem['unit_price'] ?? '');
$issuedQuantityValue = $submittedAction === 'issue_inventory_item' ? trim((string) ($_POST['issued_quantity'] ?? '')) : '';
$remainingStatusValue = $submittedAction === 'issue_inventory_item'
    ? trim((string) ($_POST['remaining_status'] ?? 'متبقي'))
    : 'متبقي';
$inventorySearchIndex = [];

foreach ($issuedSuggestions as $suggestion) {
    $name = trim((string) ($suggestion['item_name'] ?? ''));
    $barcode = trim((string) ($suggestion['barcode'] ?? ''));

    if ($name !== '') {
        $inventorySearchIndex[] = ['value' => $name, 'type' => 'name'];
    }

    if ($barcode !== '') {
        $inventorySearchIndex[] = ['value' => $barcode, 'type' => 'barcode'];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - <?php echo e($store['name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .inventory-section {
            margin-bottom: 24px;
        }

        .inventory-search-form {
            margin-bottom: 20px;
        }

        .inventory-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            font-weight: bold;
            white-space: nowrap;
            background: rgba(16, 185, 129, 0.12);
            color: var(--success);
        }

        .inventory-status-pill.is-finished {
            background: rgba(239, 68, 68, 0.12);
            color: var(--danger);
        }

        .inventory-note {
            margin-top: 10px;
            color: var(--muted);
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
            <?php echo renderSidebarSections('المخزن'); ?>
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
            <div class="topbar-content">
                <h1><?php echo e($pageTitle); ?></h1>
                <p><?php echo e($pageDescription); ?></p>
            </div>
            <div class="store-chip">
                <span><?php echo e($pageChip); ?></span>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <section class="inventory-section">
            <div class="invoice-meta-grid">
                <div class="summary-box">
                    <p>عدد الأصناف في المخزن</p>
                    <strong><?php echo (int) ($inventoryStats['total_inventory_items'] ?? 0); ?></strong>
                </div>
                <div class="summary-box">
                    <p>الأصناف المتاحة للصرف</p>
                    <strong><?php echo (int) ($inventoryStats['available_inventory_items'] ?? 0); ?></strong>
                </div>
                <div class="summary-box">
                    <p>الأصناف المنتهية</p>
                    <strong><?php echo (int) ($inventoryStats['finished_inventory_items'] ?? 0); ?></strong>
                </div>
                <div class="summary-box">
                    <p>عدد الأصناف المصروفة</p>
                    <strong><?php echo (int) ($issuedStats['total_issued_items'] ?? 0); ?></strong>
                </div>
            </div>
        </section>

        <?php if ($selectedInventoryItem): ?>
            <section class="inventory-section">
                <div class="form-card">
                    <div class="page-header">
                        <h2>صرف الصنف: <?php echo e($selectedInventoryItem['item_name']); ?></h2>
                        <div class="table-actions">
                            <a class="inline-link small-link secondary-button" href="<?php echo e(buildInventoryPageUrl()); ?>">إلغاء</a>
                        </div>
                    </div>

                    <div class="invoice-meta-grid" style="margin-bottom: 1rem;">
                        <div class="summary-box">
                            <p>الباركود</p>
                            <strong><?php echo e($selectedInventoryItem['barcode'] !== null && $selectedInventoryItem['barcode'] !== '' ? $selectedInventoryItem['barcode'] : 'غير مسجل'); ?></strong>
                        </div>
                        <div class="summary-box">
                            <p>الكمية الحالية</p>
                            <strong><?php echo $selectedIssueIsTracked ? e(formatMoney($selectedInventoryItem['quantity'])) : 'بدون عدد'; ?></strong>
                        </div>
                        <div class="summary-box">
                            <p>سعر الصنف</p>
                            <strong><?php echo e(formatMoney($selectedInventoryItem['unit_price'])); ?> ج.م</strong>
                        </div>
                        <div class="summary-box">
                            <p>مرات الصرف السابقة</p>
                            <strong><?php echo (int) ($selectedInventoryItem['issued_times'] ?? 0); ?></strong>
                        </div>
                    </div>

                    <form method="POST" class="section-stack">
                        <input type="hidden" name="form_action" value="issue_inventory_item">
                        <input type="hidden" name="item_id" value="<?php echo (int) $selectedInventoryItem['id']; ?>">

                        <?php if ($selectedIssueIsTracked): ?>
                            <div class="form-group">
                                <label>العدد المصروف</label>
                                <input type="number" step="0.01" min="0.01" name="issued_quantity" value="<?php echo e($issuedQuantityValue); ?>" required>
                            </div>
                        <?php else: ?>
                            <div class="form-group select-group">
                                <label>حالة الصنف بعد الصرف</label>
                                <select name="remaining_status" required>
                                    <option value="متبقي" <?php echo $remainingStatusValue === 'متبقي' ? 'selected' : ''; ?>>يوجد متبقي في المخزن</option>
                                    <option value="تم صرفه بالكامل" <?php echo $remainingStatusValue === 'تم صرفه بالكامل' ? 'selected' : ''; ?>>تم صرفه بالكامل</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>سعر الصرف</label>
                            <input type="number" step="0.01" min="0" name="issue_unit_price" value="<?php echo e($issueUnitPriceValue); ?>" required>
                        </div>

                        <button type="submit">📦 صرف الصنف</button>
                    </form>

                    <p class="inventory-note">
                        <?php if ($selectedIssueIsTracked): ?>
                            سيتم خصم العدد المصروف من رصيد المخزن، وعند الوصول إلى صفر سيظهر الصنف كمنتهي ولا يمكن صرفه مرة أخرى.
                        <?php else: ?>
                            حدّد إذا كان هناك متبقي من الصنف بعد الصرف. عند اختيار "تم صرفه بالكامل" سيتم إيقاف الصرف لهذا الصنف.
                        <?php endif; ?>
                    </p>
                </div>
            </section>
        <?php endif; ?>

        <section class="inventory-section">
            <div class="supplier-layout">
                <div class="form-card">
                    <div class="page-header">
                        <h2>تسجيل صنف جديد في المخزن</h2>
                        <span class="muted-text">يمكن الحفظ بدون باركود أو بدون عدد.</span>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="form_action" value="add_inventory_item">

                        <div class="form-group">
                            <label>باركود الصنف</label>
                            <input type="text" name="barcode" value="<?php echo e($submittedAction === 'add_inventory_item' ? ($_POST['barcode'] ?? '') : ''); ?>" placeholder="اختياري">
                        </div>

                        <div class="form-group">
                            <label>اسم الصنف</label>
                            <input type="text" name="item_name" value="<?php echo e($submittedAction === 'add_inventory_item' ? ($_POST['item_name'] ?? '') : ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>العدد</label>
                            <input type="number" step="0.01" min="0.01" name="quantity" value="<?php echo e($submittedAction === 'add_inventory_item' ? ($_POST['quantity'] ?? '') : ''); ?>" placeholder="اختياري">
                        </div>

                        <div class="form-group">
                            <label>السعر</label>
                            <input type="number" step="0.01" min="0" name="unit_price" value="<?php echo e($submittedAction === 'add_inventory_item' ? ($_POST['unit_price'] ?? '') : ''); ?>" required>
                        </div>

                        <button type="submit">💾 حفظ الصنف</button>
                    </form>
                </div>

                <div class="table-card">
                    <div class="page-header">
                        <h2>الأصناف المسجلة في المخزن</h2>
                        <span class="muted-text">عند انتهاء الصنف سيظهر كمنتهي ولن يظهر له زر الصرف.</span>
                    </div>

                    <?php if ($inventoryItems): ?>
                        <div class="payment-history-table-wrap">
                            <table class="compact-table">
                                <thead>
                                    <tr>
                                        <th>الباركود</th>
                                        <th>اسم الصنف</th>
                                        <th>العدد الحالي</th>
                                        <th>السعر</th>
                                        <th>الحالة</th>
                                        <th>الصرف السابق</th>
                                        <th>الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventoryItems as $item): ?>
                                        <?php $isFinished = ($item['status'] ?? '') === 'منتهي'; ?>
                                        <tr>
                                            <td><?php echo e($item['barcode'] !== null && $item['barcode'] !== '' ? $item['barcode'] : '-'); ?></td>
                                            <td><?php echo e($item['item_name']); ?></td>
                                            <td><?php echo $item['quantity'] !== null ? e(formatMoney($item['quantity'])) : 'بدون عدد'; ?></td>
                                            <td><?php echo e(formatMoney($item['unit_price'])); ?> ج.م</td>
                                            <td>
                                                <span class="inventory-status-pill<?php echo $isFinished ? ' is-finished' : ''; ?>">
                                                    <?php echo e($item['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo (int) ($item['issued_times'] ?? 0); ?></td>
                                            <td>
                                                <?php if ($isFinished): ?>
                                                    <span class="muted-text">تم الصرف بالكامل</span>
                                                <?php else: ?>
                                                    <a class="inline-link small-link" href="<?php echo e(buildInventoryPageUrl(['issue_id' => (int) $item['id']])); ?>">صرف من المخزن</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="muted-text">لا توجد أصناف مسجلة في المخزن حتى الآن.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="inventory-section" id="issued-items">
            <div class="table-card">
                <div class="page-header">
                    <h2>سجل الأصناف المصروفة</h2>
                    <span class="muted-text">يمكن البحث بالباركود أو باسم الصنف مع اقتراحات تبدأ من أول حرفين.</span>
                </div>

                <form method="GET" class="inventory-search-form">
                    <input type="hidden" name="tab" value="issued">
                    <div class="form-group">
                        <label for="issuedSearchInput">ابحث في الأصناف</label>
                        <input
                            type="text"
                            id="issuedSearchInput"
                            name="issued_search"
                            list="issuedSuggestions"
                            value="<?php echo e($issuedSearch); ?>"
                            placeholder="اكتب الباركود أو أول حرفين من اسم الصنف"
                            autocomplete="off"
                        >
                        <datalist id="issuedSuggestions"></datalist>
                    </div>

                    <div class="table-actions">
                        <button type="submit" class="secondary-button">🔎 بحث</button>
                        <a class="inline-link small-link secondary-button" href="<?php echo e(buildInventoryPageUrl([], 'issued-items')); ?>">إعادة تعيين</a>
                    </div>
                </form>

                <?php if ($issuedItems): ?>
                    <div class="payment-history-table-wrap">
                        <table class="compact-table">
                            <thead>
                                <tr>
                                    <th>الباركود</th>
                                    <th>اسم الصنف</th>
                                    <th>العدد المصروف</th>
                                    <th>سعر الصرف</th>
                                    <th>الإجمالي</th>
                                    <th>نوع الصرف</th>
                                    <th>حالة المتبقي</th>
                                    <th>تاريخ الصرف</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($issuedItems as $issuedItem): ?>
                                    <tr>
                                        <td><?php echo e($issuedItem['barcode'] !== null && $issuedItem['barcode'] !== '' ? $issuedItem['barcode'] : '-'); ?></td>
                                        <td><?php echo e($issuedItem['item_name']); ?></td>
                                        <td><?php echo $issuedItem['issued_quantity'] !== null ? e(formatMoney($issuedItem['issued_quantity'])) : 'بدون عدد'; ?></td>
                                        <td><?php echo e(formatMoney($issuedItem['unit_price'])); ?> ج.م</td>
                                        <td><?php echo e(formatMoney($issuedItem['total_price'])); ?> ج.م</td>
                                        <td><?php echo e($issuedItem['issue_mode']); ?></td>
                                        <td><?php echo e($issuedItem['remaining_status']); ?></td>
                                        <td><?php echo e(formatDateTimeForDisplay($issuedItem['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="muted-text">
                        <?php echo $issuedSearch !== '' ? 'لا توجد نتائج مطابقة للبحث الحالي.' : 'لم يتم صرف أي أصناف إلى قسم الأصناف حتى الآن.'; ?>
                    </p>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<script>
    const issuedSearchIndex = <?php echo json_encode($inventorySearchIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const issuedSearchInput = document.getElementById('issuedSearchInput');
    const issuedSuggestions = document.getElementById('issuedSuggestions');

    function refreshIssuedSuggestions() {
        if (!issuedSearchInput || !issuedSuggestions) {
            return;
        }

        const value = issuedSearchInput.value.trim().toLowerCase();
        issuedSuggestions.innerHTML = '';

        if (value.length < 2) {
            return;
        }

        const seen = new Set();

        issuedSearchIndex.forEach((entry) => {
            const currentValue = String(entry.value || '').trim();
            const normalizedValue = currentValue.toLowerCase();

            if (currentValue === '' || !normalizedValue.startsWith(value) || seen.has(normalizedValue)) {
                return;
            }

            seen.add(normalizedValue);

            const option = document.createElement('option');
            option.value = currentValue;
            issuedSuggestions.appendChild(option);
        });
    }

    if (issuedSearchInput) {
        issuedSearchInput.addEventListener('input', refreshIssuedSuggestions);
        refreshIssuedSuggestions();
    }
</script>
<script src="assets/js/theme.js"></script>
</body>
</html>
