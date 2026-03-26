<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$supplierPageMode = isset($supplierPageMode) && is_string($supplierPageMode)
    ? trim($supplierPageMode)
    : 'suppliers';
$allowedSupplierPageModes = ['suppliers', 'invoice_create', 'supplier_invoices', 'invoice_details'];

if (!in_array($supplierPageMode, $allowedSupplierPageModes, true)) {
    $supplierPageMode = 'suppliers';
}

$paymentMethods = ['شيكات', 'فيزا', 'انستاباي', 'فودافون كاش', 'كاش'];
$paymentOptions = ['وسيلة واحدة', 'وسيلتين دفع'];
$paymentStatuses = ['مدفوعة', 'أجل', 'نصف مدفوعة'];
$error = '';
$success = trim((string) ($_GET['success'] ?? ''));
$submittedAction = trim((string) ($_POST['form_action'] ?? ''));

function ensureSupplierTables(PDO $pdo)
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS suppliers (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            phone VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_invoices (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT(11) NOT NULL,
            payment_status ENUM('مدفوعة','أجل','نصف مدفوعة') NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_option ENUM('وسيلة واحدة','وسيلتين دفع') DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY supplier_id (supplier_id),
            CONSTRAINT supplier_invoices_ibfk_1 FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_invoice_items (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT(11) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            KEY invoice_id (invoice_id),
            CONSTRAINT supplier_invoice_items_ibfk_1 FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_invoice_payments (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT(11) NOT NULL,
            payment_method ENUM('شيكات','فيزا','انستاباي','فودافون كاش','كاش') NOT NULL,
            payment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            KEY invoice_id (invoice_id),
            CONSTRAINT supplier_invoice_payments_ibfk_1 FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }
}

function normalizeAmount($value)
{
    return round((float) $value, 2);
}

function amountsMatch($first, $second)
{
    return abs((float) $first - (float) $second) < 0.01;
}

function buildSupplierPageUrl($path, array $params = [], $fragment = '')
{
    $url = $path;

    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    $fragment = ltrim((string) $fragment, '#');
    if ($fragment !== '') {
        $url .= '#' . rawurlencode($fragment);
    }

    return $url;
}

function appendSupplierUrlParams($url, array $params)
{
    $parts = parse_url($url);

    if ($parts === false) {
        return supplierListPageUrl($params);
    }

    $queryParams = [];

    if (isset($parts['query'])) {
        parse_str($parts['query'], $queryParams);
    }

    foreach ($params as $key => $value) {
        $queryParams[$key] = $value;
    }

    $rebuiltUrl = (string) ($parts['path'] ?? 'suppliers.php');

    if ($queryParams !== []) {
        $rebuiltUrl .= '?' . http_build_query($queryParams);
    }

    if (!empty($parts['fragment'])) {
        $rebuiltUrl .= '#' . $parts['fragment'];
    }

    return $rebuiltUrl;
}

function sanitizeSupplierReturnUrl($value, $defaultUrl)
{
    $value = trim((string) $value);

    if ($value === '' || preg_match('/[\r\n]/', $value)) {
        return $defaultUrl;
    }

    $parts = parse_url($value);

    if (
        $parts === false
        || isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['port'])
    ) {
        return $defaultUrl;
    }

    $path = trim((string) ($parts['path'] ?? ''));
    $allowedPaths = [
        'suppliers.php',
        'supplier_invoice_create.php',
        'supplier_invoices.php',
        'supplier_invoice_details.php',
    ];

    if ($path === '' || $path !== basename($path) || !in_array($path, $allowedPaths, true)) {
        return $defaultUrl;
    }

    $sanitizedUrl = $path;

    if (!empty($parts['query'])) {
        $sanitizedUrl .= '?' . $parts['query'];
    }

    if (!empty($parts['fragment'])) {
        $sanitizedUrl .= '#' . rawurlencode((string) $parts['fragment']);
    }

    return $sanitizedUrl;
}

function supplierListPageUrl(array $params = [])
{
    return buildSupplierPageUrl('suppliers.php', $params);
}

function supplierInvoiceCreatePageUrl($supplierId, array $params = [])
{
    return buildSupplierPageUrl('supplier_invoice_create.php', array_merge([
        'supplier_id' => (int) $supplierId,
    ], $params));
}

function supplierInvoicesPageUrl($supplierId, array $params = [])
{
    return buildSupplierPageUrl('supplier_invoices.php', array_merge([
        'supplier_id' => (int) $supplierId,
    ], $params));
}

function supplierInvoiceDetailsPageUrl($supplierId, $invoiceId, array $params = [])
{
    return buildSupplierPageUrl('supplier_invoice_details.php', array_merge([
        'supplier_id' => (int) $supplierId,
        'invoice_id' => (int) $invoiceId,
    ], $params));
}

function getSupplierById(PDO $pdo, $supplierId)
{
    if ($supplierId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.name,
            s.phone,
            s.created_at,
            COALESCE(SUM(si.amount_due), 0) AS balance
        FROM suppliers s
        LEFT JOIN supplier_invoices si ON si.supplier_id = s.id
        WHERE s.id = ?
        GROUP BY s.id, s.name, s.phone, s.created_at
        LIMIT 1
    ");
    $stmt->execute([$supplierId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function parseInvoiceItems(array $names, array $quantities, array $prices, &$error)
{
    $items = [];
    $maxRows = max(count($names), count($quantities), count($prices));

    for ($index = 0; $index < $maxRows; $index++) {
        $name = trim((string) ($names[$index] ?? ''));
        $quantityValue = trim((string) ($quantities[$index] ?? ''));
        $priceValue = trim((string) ($prices[$index] ?? ''));

        if ($name === '' && $quantityValue === '' && $priceValue === '') {
            continue;
        }

        if ($name === '' || $quantityValue === '' || $priceValue === '') {
            $error = 'يجب استكمال اسم الصنف والعدد والسعر في كل صف داخل الفاتورة';

            return [];
        }

        if (!is_numeric($quantityValue) || !is_numeric($priceValue)) {
            $error = 'العدد والسعر يجب أن يكونا أرقامًا صحيحة';

            return [];
        }

        $quantity = normalizeAmount($quantityValue);
        $unitPrice = normalizeAmount($priceValue);

        if ($quantity <= 0 || $unitPrice < 0) {
            $error = 'يجب أن يكون العدد أكبر من صفر والسعر لا يقل عن صفر';

            return [];
        }

        $lineTotal = normalizeAmount($quantity * $unitPrice);
        $items[] = [
            'item_name' => $name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }

    if ($items === []) {
        $error = 'يجب إضافة صنف واحد على الأقل داخل الفاتورة';
    }

    return $items;
}

function validatePaymentSubmission(array $source, $targetAmount, array $paymentMethods, array $paymentOptions, &$error)
{
    $targetAmount = normalizeAmount($targetAmount);

    if ($targetAmount <= 0) {
        return ['payment_option' => null, 'entries' => []];
    }

    $paymentOption = trim((string) ($source['payment_option'] ?? ''));

    if (!in_array($paymentOption, $paymentOptions, true)) {
        $error = 'يجب اختيار طريقة توزيع وسيلة الدفع';

        return null;
    }

    if ($paymentOption === 'وسيلة واحدة') {
        $method = trim((string) ($source['payment_method_single'] ?? ''));

        if (!in_array($method, $paymentMethods, true)) {
            $error = 'يجب اختيار وسيلة الدفع';

            return null;
        }

        return [
            'payment_option' => $paymentOption,
            'entries' => [
                ['method' => $method, 'amount' => $targetAmount],
            ],
        ];
    }

    $methodOne = trim((string) ($source['payment_method_one'] ?? ''));
    $methodTwo = trim((string) ($source['payment_method_two'] ?? ''));
    $amountOneValue = trim((string) ($source['payment_amount_one'] ?? ''));
    $amountTwoValue = trim((string) ($source['payment_amount_two'] ?? ''));

    if (!in_array($methodOne, $paymentMethods, true) || !in_array($methodTwo, $paymentMethods, true)) {
        $error = 'يجب اختيار وسيلتي الدفع';

        return null;
    }

    if (!is_numeric($amountOneValue) || !is_numeric($amountTwoValue)) {
        $error = 'مبالغ وسيلتي الدفع يجب أن تكون أرقامًا صحيحة';

        return null;
    }

    $amountOne = normalizeAmount($amountOneValue);
    $amountTwo = normalizeAmount($amountTwoValue);

    if ($amountOne <= 0 || $amountTwo <= 0) {
        $error = 'كل مبلغ من مبالغ وسائل الدفع يجب أن يكون أكبر من صفر';

        return null;
    }

    if (!amountsMatch($amountOne + $amountTwo, $targetAmount)) {
        $error = 'مجموع مبالغ وسيلتي الدفع يجب أن يساوي المبلغ المطلوب سداده';

        return null;
    }

    return [
        'payment_option' => $paymentOption,
        'entries' => [
            ['method' => $methodOne, 'amount' => $amountOne],
            ['method' => $methodTwo, 'amount' => $amountTwo],
        ],
    ];
}

ensureSupplierTables($pdo);

$selectedSupplierId = (int) ($_GET['supplier_id'] ?? $_POST['supplier_id'] ?? 0);
$selectedInvoiceId = (int) ($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
$legacyView = trim((string) ($_GET['view'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $supplierPageMode === 'suppliers') {
    if ($selectedSupplierId > 0 && $selectedInvoiceId > 0) {
        header('Location: ' . supplierInvoiceDetailsPageUrl($selectedSupplierId, $selectedInvoiceId, array_filter([
            'back' => supplierInvoicesPageUrl($selectedSupplierId, ['back' => supplierListPageUrl()]),
            'success' => $success !== '' ? $success : null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        })));
        exit;
    }

    if ($selectedSupplierId > 0 && $legacyView === 'invoice') {
        header('Location: ' . supplierInvoiceCreatePageUrl($selectedSupplierId, array_filter([
            'back' => supplierListPageUrl(),
            'success' => $success !== '' ? $success : null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        })));
        exit;
    }

    if ($selectedSupplierId > 0 && $legacyView === 'invoices') {
        header('Location: ' . supplierInvoicesPageUrl($selectedSupplierId, array_filter([
            'back' => supplierListPageUrl(),
            'success' => $success !== '' ? $success : null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        })));
        exit;
    }
}

$invoiceListBaseUrl = $selectedSupplierId > 0
    ? supplierInvoicesPageUrl($selectedSupplierId)
    : supplierListPageUrl();
$invoiceCreateBackUrl = sanitizeSupplierReturnUrl(
    $_GET['back'] ?? $_POST['return_to'] ?? '',
    $invoiceListBaseUrl
);
$invoiceListBackUrl = sanitizeSupplierReturnUrl($_GET['back'] ?? '', supplierListPageUrl());
$invoiceDetailsBackUrl = sanitizeSupplierReturnUrl(
    $_GET['back'] ?? $_POST['return_to'] ?? '',
    $invoiceListBaseUrl
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($submittedAction === 'add_supplier') {
        $supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
        $supplierPhone = trim((string) ($_POST['supplier_phone'] ?? ''));

        if ($supplierName === '' || $supplierPhone === '') {
            $error = 'اسم المورد ورقم التليفون مطلوبان';
        } else {
            $check = $pdo->prepare("SELECT id FROM suppliers WHERE name = ? LIMIT 1");
            $check->execute([$supplierName]);

            if ($check->fetch(PDO::FETCH_ASSOC)) {
                $error = 'اسم المورد مسجل بالفعل';
            } else {
                $stmt = $pdo->prepare("INSERT INTO suppliers (name, phone, created_at) VALUES (?, ?, ?)");
                $stmt->execute([$supplierName, $supplierPhone, getEgyptDateTimeValue()]);

                header('Location: ' . supplierListPageUrl([
                    'success' => 'تم حفظ المورد بنجاح',
                ]));
                exit;
            }
        }
    } elseif ($submittedAction === 'add_invoice') {
        $selectedSupplierId = (int) ($_POST['supplier_id'] ?? 0);
        $invoiceCreateBackUrl = sanitizeSupplierReturnUrl(
            $_POST['return_to'] ?? '',
            $selectedSupplierId > 0 ? supplierInvoicesPageUrl($selectedSupplierId) : supplierListPageUrl()
        );
        $supplier = getSupplierById($pdo, $selectedSupplierId);

        if (!$supplier) {
            $error = 'المورد المحدد غير موجود';
        } else {
            $items = parseInvoiceItems(
                isset($_POST['item_name']) && is_array($_POST['item_name']) ? $_POST['item_name'] : [],
                isset($_POST['quantity']) && is_array($_POST['quantity']) ? $_POST['quantity'] : [],
                isset($_POST['unit_price']) && is_array($_POST['unit_price']) ? $_POST['unit_price'] : [],
                $error
            );

            $paymentStatus = trim((string) ($_POST['payment_status'] ?? ''));

            if ($items !== [] && !in_array($paymentStatus, $paymentStatuses, true)) {
                $error = 'حالة التسديد غير صحيحة';
            }

            if ($error === '') {
                $totalAmount = 0.00;

                foreach ($items as $item) {
                    $totalAmount += (float) $item['line_total'];
                }

                $totalAmount = normalizeAmount($totalAmount);
                $amountPaid = 0.00;
                $amountDue = $totalAmount;
                $paymentData = ['payment_option' => null, 'entries' => []];

                if ($paymentStatus === 'مدفوعة') {
                    $amountPaid = $totalAmount;
                    $amountDue = 0.00;
                    $paymentData = validatePaymentSubmission($_POST, $amountPaid, $paymentMethods, $paymentOptions, $error);
                } elseif ($paymentStatus === 'نصف مدفوعة') {
                    $paidAmountInput = trim((string) ($_POST['paid_amount'] ?? ''));

                    if (!is_numeric($paidAmountInput)) {
                        $error = 'يجب إدخال المبلغ المدفوع في الفاتورة النصف مدفوعة';
                    } else {
                        $amountPaid = normalizeAmount($paidAmountInput);

                        if ($amountPaid <= 0 || $amountPaid >= $totalAmount) {
                            $error = 'المبلغ المدفوع يجب أن يكون أكبر من صفر وأقل من إجمالي الفاتورة';
                        } else {
                            $amountDue = normalizeAmount($totalAmount - $amountPaid);
                            $paymentData = validatePaymentSubmission($_POST, $amountPaid, $paymentMethods, $paymentOptions, $error);
                        }
                    }
                }

                if ($error === '' && $paymentData !== null) {
                    $now = getEgyptDateTimeValue();

                    try {
                        $pdo->beginTransaction();

                        $invoiceStmt = $pdo->prepare("
                            INSERT INTO supplier_invoices (
                                supplier_id,
                                payment_status,
                                total_amount,
                                amount_paid,
                                amount_due,
                                payment_option,
                                created_at,
                                updated_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $invoiceStmt->execute([
                            $selectedSupplierId,
                            $paymentStatus,
                            $totalAmount,
                            $amountPaid,
                            $amountDue,
                            $paymentData['payment_option'],
                            $now,
                            $now,
                        ]);

                        $invoiceId = (int) $pdo->lastInsertId();
                        $itemStmt = $pdo->prepare("
                            INSERT INTO supplier_invoice_items (
                                invoice_id,
                                item_name,
                                quantity,
                                unit_price,
                                line_total,
                                created_at
                            ) VALUES (?, ?, ?, ?, ?, ?)
                        ");

                        foreach ($items as $item) {
                            $itemStmt->execute([
                                $invoiceId,
                                $item['item_name'],
                                $item['quantity'],
                                $item['unit_price'],
                                $item['line_total'],
                                $now,
                            ]);
                        }

                        if (!empty($paymentData['entries'])) {
                            $paymentStmt = $pdo->prepare("
                                INSERT INTO supplier_invoice_payments (
                                    invoice_id,
                                    payment_method,
                                    payment_amount,
                                    created_at
                                ) VALUES (?, ?, ?, ?)
                            ");

                            foreach ($paymentData['entries'] as $entry) {
                                $paymentStmt->execute([
                                    $invoiceId,
                                    $entry['method'],
                                    $entry['amount'],
                                    $now,
                                ]);
                            }
                        }

                        $pdo->commit();

                        header('Location: ' . appendSupplierUrlParams($invoiceCreateBackUrl, [
                            'success' => 'تم حفظ فاتورة المورد بنجاح',
                        ]));
                        exit;
                    } catch (Throwable $throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error = 'تعذر حفظ الفاتورة حاليًا، برجاء المحاولة مرة أخرى';
                    }
                }
            }
        }
    } elseif ($submittedAction === 'add_payment') {
        $selectedSupplierId = (int) ($_POST['supplier_id'] ?? 0);
        $selectedInvoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $invoiceDetailsBackUrl = sanitizeSupplierReturnUrl(
            $_POST['return_to'] ?? '',
            $selectedSupplierId > 0 ? supplierInvoicesPageUrl($selectedSupplierId) : supplierListPageUrl()
        );

        $invoiceStmt = $pdo->prepare("
            SELECT id, supplier_id, total_amount, amount_paid, amount_due
            FROM supplier_invoices
            WHERE id = ? AND supplier_id = ?
            LIMIT 1
        ");
        $invoiceStmt->execute([$selectedInvoiceId, $selectedSupplierId]);
        $invoiceForPayment = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$invoiceForPayment) {
            $error = 'الفاتورة المحددة غير موجودة';
        } else {
            $paymentAmountValue = trim((string) ($_POST['payment_amount'] ?? ''));

            if (!is_numeric($paymentAmountValue)) {
                $error = 'يجب إدخال مبلغ صحيح للتسديد';
            } else {
                $paymentAmount = normalizeAmount($paymentAmountValue);
                $currentDue = normalizeAmount($invoiceForPayment['amount_due']);

                if ($paymentAmount <= 0 || $paymentAmount > $currentDue) {
                    $error = 'المبلغ المدخل يجب أن يكون أكبر من صفر ولا يتجاوز الرصيد المتبقي';
                } else {
                    $paymentData = validatePaymentSubmission($_POST, $paymentAmount, $paymentMethods, $paymentOptions, $error);

                    if ($error === '' && $paymentData !== null) {
                        $newAmountPaid = normalizeAmount($invoiceForPayment['amount_paid'] + $paymentAmount);
                        $newAmountDue = normalizeAmount($invoiceForPayment['total_amount'] - $newAmountPaid);
                        $newStatus = $newAmountDue <= 0 ? 'مدفوعة' : 'نصف مدفوعة';
                        $now = getEgyptDateTimeValue();

                        try {
                            $pdo->beginTransaction();

                            $paymentStmt = $pdo->prepare("
                                INSERT INTO supplier_invoice_payments (
                                    invoice_id,
                                    payment_method,
                                    payment_amount,
                                    created_at
                                ) VALUES (?, ?, ?, ?)
                            ");

                            foreach ($paymentData['entries'] as $entry) {
                                $paymentStmt->execute([
                                    $selectedInvoiceId,
                                    $entry['method'],
                                    $entry['amount'],
                                    $now,
                                ]);
                            }

                            $updateStmt = $pdo->prepare("
                                UPDATE supplier_invoices
                                SET payment_status = ?, amount_paid = ?, amount_due = ?, updated_at = ?
                                WHERE id = ?
                            ");
                            $updateStmt->execute([
                                $newStatus,
                                $newAmountPaid,
                                $newAmountDue,
                                $now,
                                $selectedInvoiceId,
                            ]);

                            $pdo->commit();

                            header('Location: ' . supplierInvoiceDetailsPageUrl($selectedSupplierId, $selectedInvoiceId, [
                                'back' => $invoiceDetailsBackUrl,
                                'success' => 'تم تسجيل عملية التسديد بنجاح',
                            ]));
                            exit;
                        } catch (Throwable $throwable) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $error = 'تعذر حفظ عملية التسديد حاليًا، برجاء المحاولة مرة أخرى';
                        }
                    }
                }
            }
        }
    }
}

$suppliers = [];
$totalSuppliersBalance = 0.00;
$unpaidInvoicesCount = 0;

if ($supplierPageMode === 'suppliers') {
    $suppliersStmt = $pdo->query("
        SELECT
            s.id,
            s.name,
            s.phone,
            s.created_at,
            COALESCE(SUM(si.amount_due), 0) AS balance,
            COUNT(si.id) AS total_invoices
        FROM suppliers s
        LEFT JOIN supplier_invoices si ON si.supplier_id = s.id
        GROUP BY s.id, s.name, s.phone, s.created_at
        ORDER BY s.id DESC
    ");
    $suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

    $supplierStatsStmt = $pdo->query("
        SELECT
            COALESCE(SUM(amount_due), 0) AS total_balance,
            COUNT(CASE WHEN amount_due > 0 THEN 1 END) AS unpaid_invoices_count
        FROM supplier_invoices
    ");
    $supplierStats = $supplierStatsStmt->fetch(PDO::FETCH_ASSOC);
    $totalSuppliersBalance = normalizeAmount($supplierStats['total_balance'] ?? 0);
    $unpaidInvoicesCount = (int) ($supplierStats['unpaid_invoices_count'] ?? 0);
}

$selectedSupplier = getSupplierById($pdo, $selectedSupplierId);
$supplierInvoices = [];
$supplierInvoicePayments = [];
$supplierInvoicePaymentsByInvoice = [];
$selectedInvoice = null;
$selectedInvoiceItems = [];
$selectedInvoicePayments = [];

if (
    !$selectedSupplier
    && $selectedSupplierId > 0
    && in_array($supplierPageMode, ['invoice_create', 'supplier_invoices', 'invoice_details'], true)
    && $error === ''
) {
    $error = 'المورد المحدد غير موجود';
}

if ($selectedSupplierId > 0 && $selectedSupplier) {
    $invoiceListStmt = $pdo->prepare("
        SELECT id, payment_status, total_amount, amount_paid, amount_due, created_at, updated_at
        FROM supplier_invoices
        WHERE supplier_id = ?
        ORDER BY created_at DESC, id DESC
    ");
    $invoiceListStmt->execute([$selectedSupplierId]);
    $supplierInvoices = $invoiceListStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($supplierInvoices) {
        $supplierPaymentsStmt = $pdo->prepare("
            SELECT sip.invoice_id, sip.payment_method, sip.payment_amount, sip.created_at
            FROM supplier_invoice_payments sip
            INNER JOIN supplier_invoices si ON si.id = sip.invoice_id
            WHERE si.supplier_id = ?
            ORDER BY sip.created_at DESC, sip.id DESC
        ");
        $supplierPaymentsStmt->execute([$selectedSupplierId]);
        $supplierInvoicePayments = $supplierPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($supplierInvoicePayments as $paymentRow) {
            $invoiceId = (int) $paymentRow['invoice_id'];
            $supplierInvoicePaymentsByInvoice[$invoiceId][] = $paymentRow;
        }
    }
}

if ($selectedSupplierId > 0 && $selectedInvoiceId > 0 && $selectedSupplier) {
    $invoiceDetailsStmt = $pdo->prepare("
        SELECT id, supplier_id, payment_status, total_amount, amount_paid, amount_due, created_at, updated_at
        FROM supplier_invoices
        WHERE id = ? AND supplier_id = ?
        LIMIT 1
    ");
    $invoiceDetailsStmt->execute([$selectedInvoiceId, $selectedSupplierId]);
    $selectedInvoice = $invoiceDetailsStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedInvoice) {
        $itemsStmt = $pdo->prepare("
            SELECT item_name, quantity, unit_price, line_total, created_at
            FROM supplier_invoice_items
            WHERE invoice_id = ?
            ORDER BY id ASC
        ");
        $itemsStmt->execute([$selectedInvoiceId]);
        $selectedInvoiceItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $paymentsStmt = $pdo->prepare("
            SELECT payment_method, payment_amount, created_at
            FROM supplier_invoice_payments
            WHERE invoice_id = ?
            ORDER BY id ASC
        ");
        $paymentsStmt->execute([$selectedInvoiceId]);
        $selectedInvoicePayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($supplierPageMode === 'invoice_details' && $error === '') {
        $error = 'الفاتورة المحددة غير موجودة';
    }
}

$supplierInvoicesCount = count($supplierInvoices);
$settledInvoicesCount = 0;
$openInvoicesCount = 0;
$supplierTotalPaid = 0.00;

if ($supplierInvoices) {
    foreach ($supplierInvoices as $invoiceSummary) {
        $supplierTotalPaid += (float) $invoiceSummary['amount_paid'];

        if ((float) $invoiceSummary['amount_due'] <= 0) {
            $settledInvoicesCount++;
        } else {
            $openInvoicesCount++;
        }
    }
}

$supplierTotalPaid = normalizeAmount($supplierTotalPaid);

$invoiceFormNames = isset($_POST['item_name']) && is_array($_POST['item_name']) && $submittedAction === 'add_invoice'
    ? array_values($_POST['item_name'])
    : [''];
$invoiceFormQuantities = isset($_POST['quantity']) && is_array($_POST['quantity']) && $submittedAction === 'add_invoice'
    ? array_values($_POST['quantity'])
    : [''];
$invoiceFormPrices = isset($_POST['unit_price']) && is_array($_POST['unit_price']) && $submittedAction === 'add_invoice'
    ? array_values($_POST['unit_price'])
    : [''];

if ($invoiceFormNames === []) {
    $invoiceFormNames = [''];
}

if ($invoiceFormQuantities === []) {
    $invoiceFormQuantities = [''];
}

if ($invoiceFormPrices === []) {
    $invoiceFormPrices = [''];
}

$invoiceRowCount = max(count($invoiceFormNames), count($invoiceFormQuantities), count($invoiceFormPrices));
$invoiceStatusValue = $submittedAction === 'add_invoice' ? trim((string) ($_POST['payment_status'] ?? 'مدفوعة')) : 'مدفوعة';
$invoicePaidAmountValue = $submittedAction === 'add_invoice' ? trim((string) ($_POST['paid_amount'] ?? '')) : '';
$invoicePaymentOptionValue = $submittedAction === 'add_invoice' ? trim((string) ($_POST['payment_option'] ?? 'وسيلة واحدة')) : 'وسيلة واحدة';
$invoicePaymentSingleValue = $submittedAction === 'add_invoice' ? trim((string) ($_POST['payment_method_single'] ?? '')) : '';
$invoicePaymentOneValue = $submittedAction === 'add_invoice' ? trim((string) ($_POST['payment_method_one'] ?? '')) : '';
$invoicePaymentTwoValue = $submittedAction === 'add_invoice' ? trim((string) ($_POST['payment_method_two'] ?? '')) : '';
$invoicePaymentAmountOneValue = $submittedAction === 'add_invoice' ? trim((string) ($_POST['payment_amount_one'] ?? '')) : '';
$invoicePaymentAmountTwoValue = $submittedAction === 'add_invoice' ? trim((string) ($_POST['payment_amount_two'] ?? '')) : '';

$settlementAmountValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_amount'] ?? '')) : '';
$settlementPaymentOptionValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_option'] ?? 'وسيلة واحدة')) : 'وسيلة واحدة';
$settlementPaymentSingleValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_method_single'] ?? '')) : '';
$settlementPaymentOneValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_method_one'] ?? '')) : '';
$settlementPaymentTwoValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_method_two'] ?? '')) : '';
$settlementPaymentAmountOneValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_amount_one'] ?? '')) : '';
$settlementPaymentAmountTwoValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_amount_two'] ?? '')) : '';

$isSuppliersPage = $supplierPageMode === 'suppliers';
$isInvoiceCreatePage = $supplierPageMode === 'invoice_create';
$isInvoiceListPage = $supplierPageMode === 'supplier_invoices';
$isInvoiceDetailsPage = $supplierPageMode === 'invoice_details';

$currentInvoiceListUrl = $selectedSupplierId > 0
    ? supplierInvoicesPageUrl($selectedSupplierId, ['back' => $invoiceListBackUrl])
    : supplierListPageUrl();
$pageTitle = 'الموردين';
$pageDescription = 'تسجيل الموردين وعرض جدول الموردين في صفحة مستقلة ومنظمة.';
$pageChip = '🚚 إدارة الموردين';
$pageBackUrl = supplierListPageUrl();

if ($isInvoiceCreatePage) {
    $pageTitle = 'إضافة فاتورة مورد';
    $pageDescription = 'إنشاء فاتورة مورد في صفحة مستقلة مع إمكانية الرجوع للصفحة السابقة بعد الحفظ.';
    $pageChip = '🧾 إضافة فاتورة';
    $pageBackUrl = $invoiceCreateBackUrl;
} elseif ($isInvoiceListPage) {
    $pageTitle = 'فواتير المورد';
    $pageDescription = 'عرض فواتير المورد في صفحة منفصلة ومنظمة مع الوصول لتفاصيل كل فاتورة.';
    $pageChip = '📚 فواتير المورد';
    $pageBackUrl = $invoiceListBackUrl;
} elseif ($isInvoiceDetailsPage) {
    $pageTitle = 'تفاصيل فاتورة المورد';
    $pageDescription = 'عرض تفاصيل الفاتورة وسجل التسديدات في صفحة مستقلة مع رجوع مباشر.';
    $pageChip = '📄 تفاصيل الفاتورة';
    $pageBackUrl = $invoiceDetailsBackUrl;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - <?php echo e($store['name']); ?></title>
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
            <?php echo renderSidebarSections('الموردين'); ?>
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

        <?php if ($isSuppliersPage): ?>
            <div class="invoice-meta-grid" style="margin-bottom: 1.5rem;">
                <div class="summary-box">
                    <p>إجمالي رصيد الموردين</p>
                    <strong><?php echo e(formatMoney($totalSuppliersBalance)); ?> ج.م</strong>
                </div>
                <div class="summary-box">
                    <p>عدد الفواتير غير المدفوعة</p>
                    <strong><?php echo e($unpaidInvoicesCount); ?></strong>
                </div>
            </div>

            <div class="supplier-layout">
                <div class="form-card">
                    <div class="page-header">
                        <h2>إضافة مورد جديد</h2>
                        <span class="muted-text">سجّل المورد أولًا، ثم انتقل إلى صفحة الفواتير أو إنشاء فاتورة من الجدول.</span>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="form_action" value="add_supplier">

                        <div class="form-group">
                            <label>اسم المورد</label>
                            <input type="text" name="supplier_name" value="<?php echo e($submittedAction === 'add_supplier' ? ($_POST['supplier_name'] ?? '') : ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>رقم التليفون</label>
                            <input type="text" name="supplier_phone" value="<?php echo e($submittedAction === 'add_supplier' ? ($_POST['supplier_phone'] ?? '') : ''); ?>" required>
                        </div>

                        <button type="submit">💾 حفظ المورد</button>
                    </form>
                </div>

                <div class="table-card">
                    <div class="page-header">
                        <h2>الموردون المسجلون</h2>
                        <span class="muted-text">إجمالي الموردين: <?php echo count($suppliers); ?></span>
                    </div>

                    <?php if ($suppliers): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>اسم المورد</th>
                                    <th>رقم التليفون</th>
                                    <th>رصيد المورد</th>
                                    <th>الفواتير</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <tr>
                                        <td><?php echo e($supplier['name']); ?></td>
                                        <td><?php echo e($supplier['phone']); ?></td>
                                        <td><?php echo e(formatMoney($supplier['balance'])); ?> ج.م</td>
                                        <td><?php echo (int) $supplier['total_invoices']; ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="inline-link small-link" href="<?php echo e(supplierInvoiceCreatePageUrl((int) $supplier['id'], [
                                                    'back' => supplierListPageUrl(),
                                                ])); ?>">إضافة فاتورة</a>
                                                <a class="inline-link small-link" href="<?php echo e(supplierInvoicesPageUrl((int) $supplier['id'], [
                                                    'back' => supplierListPageUrl(),
                                                ])); ?>">عرض الفواتير السابقة</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="muted-text">لا يوجد موردون مسجلون حتى الآن.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="table-card supplier-focus-card">
                <div class="supplier-focus-header">
                    <div>
                        <div class="page-header">
                            <h2>
                                <?php if ($selectedSupplier): ?>
                                    المورد: <?php echo e($selectedSupplier['name']); ?>
                                <?php else: ?>
                                    المورد غير متاح
                                <?php endif; ?>
                            </h2>
                            <span class="muted-text">
                                <?php echo e($selectedSupplier ? 'كل خطوة أصبحت في صفحة مستقلة مع روابط رجوع واضحة.' : 'اختر موردًا صالحًا للمتابعة بين صفحات الموردين والفواتير.'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="supplier-focus-actions">
                        <a class="inline-link" href="<?php echo e($pageBackUrl); ?>">⬅ رجوع</a>
                        <?php if ($selectedSupplier && !$isInvoiceCreatePage): ?>
                            <a class="inline-link" href="<?php echo e(supplierInvoiceCreatePageUrl((int) $selectedSupplier['id'], [
                                'back' => $isInvoiceListPage ? $currentInvoiceListUrl : $pageBackUrl,
                            ])); ?>">➕ إضافة فاتورة</a>
                        <?php endif; ?>
                        <?php if ($selectedSupplier && !$isInvoiceListPage): ?>
                            <a class="inline-link" href="<?php echo e(supplierInvoicesPageUrl((int) $selectedSupplier['id'], [
                                'back' => supplierListPageUrl(),
                            ])); ?>">📚 فواتير المورد</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($selectedSupplier): ?>
                    <div class="invoice-meta-grid">
                        <div class="summary-box">
                            <p>رقم التليفون</p>
                            <strong><?php echo e($selectedSupplier['phone']); ?></strong>
                        </div>
                        <div class="summary-box">
                            <p>الرصيد الحالي</p>
                            <strong><?php echo e(formatMoney($selectedSupplier['balance'])); ?> ج.م</strong>
                        </div>
                        <div class="summary-box">
                            <p>عدد الفواتير</p>
                            <strong><?php echo e($supplierInvoicesCount); ?></strong>
                        </div>
                        <div class="summary-box">
                            <p>إجمالي المدفوع</p>
                            <strong><?php echo e(formatMoney($supplierTotalPaid)); ?> ج.م</strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isInvoiceCreatePage && $selectedSupplier): ?>
            <div class="form-card" id="invoice-form">
                <div class="page-header">
                    <h2>إضافة فاتورة للمورد: <?php echo e($selectedSupplier['name']); ?></h2>
                    <div class="table-actions">
                        <span class="muted-text">أدخل الأصناف ثم اختر حالة التسديد المناسبة.</span>
                        <a class="inline-link small-link secondary-button" href="<?php echo e($invoiceCreateBackUrl); ?>">إلغاء والرجوع</a>
                    </div>
                </div>

                <form method="POST" id="supplierInvoiceForm" class="section-stack">
                    <input type="hidden" name="form_action" value="add_invoice">
                    <input type="hidden" name="supplier_id" value="<?php echo (int) $selectedSupplier['id']; ?>">
                    <input type="hidden" name="return_to" value="<?php echo e($invoiceCreateBackUrl); ?>">

                    <div>
                        <label>أصناف الفاتورة</label>
                        <div id="invoiceItems">
                            <?php for ($rowIndex = 0; $rowIndex < $invoiceRowCount; $rowIndex++): ?>
                                <div class="invoice-item-row">
                                    <div class="form-group">
                                        <label>اسم الصنف</label>
                                        <input type="text" name="item_name[]" value="<?php echo e($invoiceFormNames[$rowIndex] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>العدد</label>
                                        <input type="number" step="0.01" min="0.01" name="quantity[]" value="<?php echo e($invoiceFormQuantities[$rowIndex] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>السعر</label>
                                        <input type="number" step="0.01" min="0" name="unit_price[]" value="<?php echo e($invoiceFormPrices[$rowIndex] ?? ''); ?>" required>
                                    </div>
                                    <button type="button" class="secondary-button button-inline remove-item-row">حذف</button>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <button type="button" class="secondary-button button-inline" id="addItemRow">➕ إضافة صنف آخر</button>
                    </div>

                    <div class="summary-box">
                        <p>إجمالي الفاتورة الحالي</p>
                        <strong><span id="invoiceTotalValue">0.00</span> ج.م</strong>
                    </div>

                    <div class="form-group select-group">
                        <label>نوع التسديد</label>
                        <select name="payment_status" id="invoicePaymentStatus" required>
                            <?php foreach ($paymentStatuses as $paymentStatus): ?>
                                <option value="<?php echo e($paymentStatus); ?>" <?php echo $invoiceStatusValue === $paymentStatus ? 'selected' : ''; ?>><?php echo e($paymentStatus); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="invoicePartialAmountGroup">
                        <label>المبلغ المدفوع الآن</label>
                        <input type="number" step="0.01" min="0.01" name="paid_amount" id="invoicePaidAmount" value="<?php echo e($invoicePaidAmountValue); ?>">
                        <small class="muted-text">يُطلب هذا الحقل فقط عند اختيار حالة نصف مدفوعة.</small>
                    </div>

                    <div id="invoicePaymentDetails" class="section-stack">
                        <div class="form-group select-group">
                            <label>عدد وسائل الدفع</label>
                            <select name="payment_option" id="invoicePaymentOption">
                                <?php foreach ($paymentOptions as $paymentOption): ?>
                                    <option value="<?php echo e($paymentOption); ?>" <?php echo $invoicePaymentOptionValue === $paymentOption ? 'selected' : ''; ?>><?php echo e($paymentOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="invoiceSinglePaymentBox">
                            <div class="form-group select-group">
                                <label>نوع وسيلة الدفع</label>
                                <select name="payment_method_single">
                                    <option value="">اختر وسيلة الدفع</option>
                                    <?php foreach ($paymentMethods as $paymentMethod): ?>
                                        <option value="<?php echo e($paymentMethod); ?>" <?php echo $invoicePaymentSingleValue === $paymentMethod ? 'selected' : ''; ?>><?php echo e($paymentMethod); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div id="invoiceDoublePaymentBox" class="payment-method-grid">
                            <div class="form-group select-group">
                                <label>وسيلة الدفع الأولى</label>
                                <select name="payment_method_one">
                                    <option value="">اختر الوسيلة الأولى</option>
                                    <?php foreach ($paymentMethods as $paymentMethod): ?>
                                        <option value="<?php echo e($paymentMethod); ?>" <?php echo $invoicePaymentOneValue === $paymentMethod ? 'selected' : ''; ?>><?php echo e($paymentMethod); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>مبلغ الوسيلة الأولى</label>
                                <input type="number" step="0.01" min="0.01" name="payment_amount_one" value="<?php echo e($invoicePaymentAmountOneValue); ?>">
                            </div>
                            <div class="form-group select-group">
                                <label>وسيلة الدفع الثانية</label>
                                <select name="payment_method_two">
                                    <option value="">اختر الوسيلة الثانية</option>
                                    <?php foreach ($paymentMethods as $paymentMethod): ?>
                                        <option value="<?php echo e($paymentMethod); ?>" <?php echo $invoicePaymentTwoValue === $paymentMethod ? 'selected' : ''; ?>><?php echo e($paymentMethod); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>مبلغ الوسيلة الثانية</label>
                                <input type="number" step="0.01" min="0.01" name="payment_amount_two" value="<?php echo e($invoicePaymentAmountTwoValue); ?>">
                            </div>
                        </div>
                    </div>

                    <button type="submit">💾 حفظ الفاتورة</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($isInvoiceListPage && $selectedSupplier): ?>
            <div class="table-card" id="supplier-invoices">
                <div class="page-header">
                    <h2>الفواتير السابقة للمورد</h2>
                    <div class="table-actions">
                        <span class="muted-text">كل فاتورة أصبحت لها صفحة تفاصيل مستقلة وسجل تسديداتها الخاص.</span>
                        <a class="inline-link small-link secondary-button" href="<?php echo e($invoiceListBackUrl); ?>">رجوع</a>
                    </div>
                </div>

                <?php if ($supplierInvoices): ?>
                    <div class="invoice-summary-strip">
                        <div class="summary-box">
                            <p>عدد الفواتير</p>
                            <strong><?php echo (int) $supplierInvoicesCount; ?></strong>
                        </div>
                        <div class="summary-box">
                            <p>فواتير مسددة</p>
                            <strong><?php echo (int) $settledInvoicesCount; ?></strong>
                        </div>
                        <div class="summary-box">
                            <p>فواتير مفتوحة</p>
                            <strong><?php echo (int) $openInvoicesCount; ?></strong>
                        </div>
                        <div class="summary-box">
                            <p>إجمالي ما تم سداده</p>
                            <strong><?php echo e(formatMoney($supplierTotalPaid)); ?> ج.م</strong>
                        </div>
                    </div>

                    <div class="invoice-records-list">
                        <?php foreach ($supplierInvoices as $invoice): ?>
                            <?php
                            $invoiceId = (int) $invoice['id'];
                            $invoicePayments = $supplierInvoicePaymentsByInvoice[$invoiceId] ?? [];
                            ?>
                            <article class="invoice-record-card">
                                <div class="invoice-record-header">
                                    <div>
                                        <div class="invoice-record-label">فاتورة رقم #<?php echo $invoiceId; ?></div>
                                        <h3>تاريخ الحفظ: <?php echo e(formatDateTimeForDisplay($invoice['created_at'])); ?></h3>
                                    </div>
                                    <span class="invoice-status-pill"><?php echo e($invoice['payment_status']); ?></span>
                                </div>

                                <div class="invoice-meta-grid">
                                    <div class="summary-box">
                                        <p>إجمالي الفاتورة</p>
                                        <strong><?php echo e(formatMoney($invoice['total_amount'])); ?> ج.م</strong>
                                    </div>
                                    <div class="summary-box">
                                        <p>إجمالي المدفوع</p>
                                        <strong><?php echo e(formatMoney($invoice['amount_paid'])); ?> ج.م</strong>
                                    </div>
                                    <div class="summary-box">
                                        <p>المبلغ المتبقي</p>
                                        <strong><?php echo e(formatMoney($invoice['amount_due'])); ?> ج.م</strong>
                                    </div>
                                </div>

                                <div class="invoice-payment-history">
                                    <div class="page-header">
                                        <h3>سجل التسديدات الخاص بالفاتورة</h3>
                                        <span class="muted-text">عدد الحركات: <?php echo count($invoicePayments); ?></span>
                                    </div>

                                    <?php if ($invoicePayments): ?>
                                        <div class="payment-history-table-wrap">
                                            <table class="compact-table payment-history-table">
                                                <thead>
                                                    <tr>
                                                        <th>التاريخ والوقت</th>
                                                        <th>وسيلة الدفع</th>
                                                        <th>المبلغ</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($invoicePayments as $payment): ?>
                                                        <tr>
                                                            <td><?php echo e(formatDateTimeForDisplay($payment['created_at'])); ?></td>
                                                            <td><?php echo e($payment['payment_method']); ?></td>
                                                            <td><?php echo e(formatMoney($payment['payment_amount'])); ?> ج.م</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="muted-text">لا توجد عمليات تسديد مسجلة لهذه الفاتورة حتى الآن.</p>
                                    <?php endif; ?>
                                </div>

                                <div class="invoice-record-actions">
                                    <a class="inline-link small-link" href="<?php echo e(supplierInvoiceDetailsPageUrl(
                                        $selectedSupplierId,
                                        $invoiceId,
                                        ['back' => $currentInvoiceListUrl]
                                    )); ?>">عرض التفاصيل والتسديد</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted-text">لا توجد فواتير مسجلة لهذا المورد حتى الآن.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isInvoiceDetailsPage && $selectedInvoice && $selectedSupplier): ?>
            <div class="supplier-management-grid" id="invoice-details">
                <div class="table-card">
                    <div class="page-header">
                        <h2>تفاصيل الفاتورة رقم <?php echo (int) $selectedInvoice['id']; ?></h2>
                        <a class="inline-link small-link secondary-button" href="<?php echo e($invoiceDetailsBackUrl); ?>">رجوع</a>
                    </div>

                    <div class="invoice-meta-grid">
                        <div class="invoice-detail-box">
                            <p>تاريخ الحفظ بتوقيت مصر</p>
                            <strong><?php echo e(formatDateTimeForDisplay($selectedInvoice['created_at'])); ?></strong>
                        </div>
                        <div class="invoice-detail-box">
                            <p>حالة التسديد</p>
                            <strong><?php echo e($selectedInvoice['payment_status']); ?></strong>
                        </div>
                        <div class="invoice-detail-box">
                            <p>المتبقي على الفاتورة</p>
                            <strong><?php echo e(formatMoney($selectedInvoice['amount_due'])); ?> ج.م</strong>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>اسم الصنف</th>
                                <th>العدد</th>
                                <th>السعر</th>
                                <th>الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selectedInvoiceItems as $invoiceItem): ?>
                                <tr>
                                    <td><?php echo e($invoiceItem['item_name']); ?></td>
                                    <td><?php echo e(formatMoney($invoiceItem['quantity'])); ?></td>
                                    <td><?php echo e(formatMoney($invoiceItem['unit_price'])); ?> ج.م</td>
                                    <td><?php echo e(formatMoney($invoiceItem['line_total'])); ?> ج.م</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="invoice-meta-grid">
                        <div class="summary-box">
                            <p>إجمالي الفاتورة</p>
                            <strong><?php echo e(formatMoney($selectedInvoice['total_amount'])); ?> ج.م</strong>
                        </div>
                        <div class="summary-box">
                            <p>إجمالي المدفوع</p>
                            <strong><?php echo e(formatMoney($selectedInvoice['amount_paid'])); ?> ج.م</strong>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="page-header">
                        <h2>سجل التسديدات</h2>
                    </div>

                    <?php if ($selectedInvoicePayments): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>التاريخ والوقت</th>
                                    <th>وسيلة الدفع</th>
                                    <th>المبلغ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($selectedInvoicePayments as $payment): ?>
                                    <tr>
                                        <td><?php echo e(formatDateTimeForDisplay($payment['created_at'])); ?></td>
                                        <td><?php echo e($payment['payment_method']); ?></td>
                                        <td><?php echo e(formatMoney($payment['payment_amount'])); ?> ج.م</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="muted-text">لم يتم تسجيل أي تسديدات على هذه الفاتورة حتى الآن.</p>
                    <?php endif; ?>

                    <?php if ((float) $selectedInvoice['amount_due'] > 0): ?>
                        <hr>
                        <div class="page-header">
                            <h2>تسديد جزئي أو كلي</h2>
                        </div>

                        <form method="POST" id="supplierPaymentForm" class="section-stack">
                            <input type="hidden" name="form_action" value="add_payment">
                            <input type="hidden" name="supplier_id" value="<?php echo (int) $selectedSupplierId; ?>">
                            <input type="hidden" name="invoice_id" value="<?php echo (int) $selectedInvoice['id']; ?>">
                            <input type="hidden" name="return_to" value="<?php echo e($invoiceDetailsBackUrl); ?>">

                            <div class="summary-box">
                                <p>الرصيد المتبقي على الفاتورة</p>
                                <strong><?php echo e(formatMoney($selectedInvoice['amount_due'])); ?> ج.م</strong>
                            </div>

                            <div class="form-group">
                                <label>المبلغ المراد تسديده الآن</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    max="<?php echo e(number_format((float) $selectedInvoice['amount_due'], 2, '.', '')); ?>"
                                    name="payment_amount"
                                    id="settlementPaymentAmount"
                                    value="<?php echo e($settlementAmountValue); ?>"
                                    required
                                >
                            </div>

                            <div id="settlementPaymentDetails" class="section-stack">
                                <div class="form-group select-group">
                                    <label>عدد وسائل الدفع</label>
                                    <select name="payment_option" id="settlementPaymentOption">
                                        <?php foreach ($paymentOptions as $paymentOption): ?>
                                            <option value="<?php echo e($paymentOption); ?>" <?php echo $settlementPaymentOptionValue === $paymentOption ? 'selected' : ''; ?>><?php echo e($paymentOption); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div id="settlementSinglePaymentBox">
                                    <div class="form-group select-group">
                                        <label>نوع وسيلة الدفع</label>
                                        <select name="payment_method_single">
                                            <option value="">اختر وسيلة الدفع</option>
                                            <?php foreach ($paymentMethods as $paymentMethod): ?>
                                                <option value="<?php echo e($paymentMethod); ?>" <?php echo $settlementPaymentSingleValue === $paymentMethod ? 'selected' : ''; ?>><?php echo e($paymentMethod); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div id="settlementDoublePaymentBox" class="payment-method-grid">
                                    <div class="form-group select-group">
                                        <label>وسيلة الدفع الأولى</label>
                                        <select name="payment_method_one">
                                            <option value="">اختر الوسيلة الأولى</option>
                                            <?php foreach ($paymentMethods as $paymentMethod): ?>
                                                <option value="<?php echo e($paymentMethod); ?>" <?php echo $settlementPaymentOneValue === $paymentMethod ? 'selected' : ''; ?>><?php echo e($paymentMethod); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>مبلغ الوسيلة الأولى</label>
                                        <input type="number" step="0.01" min="0.01" name="payment_amount_one" value="<?php echo e($settlementPaymentAmountOneValue); ?>">
                                    </div>
                                    <div class="form-group select-group">
                                        <label>وسيلة الدفع الثانية</label>
                                        <select name="payment_method_two">
                                            <option value="">اختر الوسيلة الثانية</option>
                                            <?php foreach ($paymentMethods as $paymentMethod): ?>
                                                <option value="<?php echo e($paymentMethod); ?>" <?php echo $settlementPaymentTwoValue === $paymentMethod ? 'selected' : ''; ?>><?php echo e($paymentMethod); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>مبلغ الوسيلة الثانية</label>
                                        <input type="number" step="0.01" min="0.01" name="payment_amount_two" value="<?php echo e($settlementPaymentAmountTwoValue); ?>">
                                    </div>
                                </div>
                            </div>

                            <button type="submit">💰 حفظ التسديد</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<script src="assets/js/theme.js"></script>
<script>
    function parseNumber(value) {
        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function toggleFieldGroup(group, isVisible, requiredSelectors = []) {
        if (!group) {
            return;
        }

        group.classList.toggle('hidden', !isVisible);
        group.querySelectorAll('input, select').forEach((field) => {
            field.disabled = !isVisible;
            field.required = false;
        });

        if (!isVisible) {
            return;
        }

        requiredSelectors.forEach((selector) => {
            const field = group.querySelector(selector);
            if (field) {
                field.required = true;
            }
        });
    }

    function createInvoiceItemRow() {
        const row = document.createElement('div');
        row.className = 'invoice-item-row';
        row.innerHTML = `
            <div class="form-group">
                <label>اسم الصنف</label>
                <input type="text" name="item_name[]" required>
            </div>
            <div class="form-group">
                <label>العدد</label>
                <input type="number" step="0.01" min="0.01" name="quantity[]" required>
            </div>
            <div class="form-group">
                <label>السعر</label>
                <input type="number" step="0.01" min="0" name="unit_price[]" required>
            </div>
            <button type="button" class="secondary-button button-inline remove-item-row">حذف</button>
        `;
        return row;
    }

    function updateInvoiceSummary() {
        const itemsContainer = document.getElementById('invoiceItems');
        const totalElement = document.getElementById('invoiceTotalValue');
        if (!itemsContainer || !totalElement) {
            return 0;
        }

        let total = 0;
        itemsContainer.querySelectorAll('.invoice-item-row').forEach((row) => {
            const quantity = parseNumber(row.querySelector('input[name="quantity[]"]').value);
            const unitPrice = parseNumber(row.querySelector('input[name="unit_price[]"]').value);
            total += quantity * unitPrice;
        });

        totalElement.textContent = total.toFixed(2);
        return total;
    }

    function toggleInvoicePaymentDetails() {
        const statusField = document.getElementById('invoicePaymentStatus');
        const partialGroup = document.getElementById('invoicePartialAmountGroup');
        const partialInput = document.getElementById('invoicePaidAmount');
        const paymentDetails = document.getElementById('invoicePaymentDetails');
        const paymentOption = document.getElementById('invoicePaymentOption');
        const singleBox = document.getElementById('invoiceSinglePaymentBox');
        const doubleBox = document.getElementById('invoiceDoublePaymentBox');
        if (!statusField || !partialGroup || !partialInput || !paymentDetails || !paymentOption || !singleBox || !doubleBox) {
            return;
        }

        const status = statusField.value;
        const shouldShowPartialAmount = status === 'نصف مدفوعة';
        const shouldShowPaymentDetails = status !== 'أجل';
        const shouldShowSingleMethod = shouldShowPaymentDetails && paymentOption.value === 'وسيلة واحدة';
        const shouldShowDoubleMethod = shouldShowPaymentDetails && paymentOption.value === 'وسيلتين دفع';

        partialGroup.classList.toggle('hidden', !shouldShowPartialAmount);
        partialInput.disabled = !shouldShowPartialAmount;
        partialInput.required = shouldShowPartialAmount;

        paymentDetails.classList.toggle('hidden', !shouldShowPaymentDetails);
        paymentOption.disabled = !shouldShowPaymentDetails;

        toggleFieldGroup(singleBox, shouldShowSingleMethod, ['select[name="payment_method_single"]']);
        toggleFieldGroup(doubleBox, shouldShowDoubleMethod, [
            'select[name="payment_method_one"]',
            'input[name="payment_amount_one"]',
            'select[name="payment_method_two"]',
            'input[name="payment_amount_two"]',
        ]);
    }

    function toggleSettlementPaymentDetails() {
        const paymentOption = document.getElementById('settlementPaymentOption');
        const paymentDetails = document.getElementById('settlementPaymentDetails');
        const singleBox = document.getElementById('settlementSinglePaymentBox');
        const doubleBox = document.getElementById('settlementDoublePaymentBox');
        if (!paymentOption || !paymentDetails || !singleBox || !doubleBox) {
            return;
        }

        paymentDetails.classList.remove('hidden');
        toggleFieldGroup(singleBox, paymentOption.value === 'وسيلة واحدة', ['select[name="payment_method_single"]']);
        toggleFieldGroup(doubleBox, paymentOption.value === 'وسيلتين دفع', [
            'select[name="payment_method_one"]',
            'input[name="payment_amount_one"]',
            'select[name="payment_method_two"]',
            'input[name="payment_amount_two"]',
        ]);
    }

    const addItemRowButton = document.getElementById('addItemRow');
    const invoiceItems = document.getElementById('invoiceItems');

    if (addItemRowButton && invoiceItems) {
        addItemRowButton.addEventListener('click', () => {
            invoiceItems.appendChild(createInvoiceItemRow());
        });

        invoiceItems.addEventListener('click', (event) => {
            if (!event.target.classList.contains('remove-item-row')) {
                return;
            }

            const rows = invoiceItems.querySelectorAll('.invoice-item-row');
            const currentRow = event.target.closest('.invoice-item-row');
            if (!currentRow) {
                return;
            }

            if (rows.length === 1) {
                currentRow.querySelectorAll('input').forEach((input) => {
                    input.value = '';
                });
            } else {
                currentRow.remove();
            }

            updateInvoiceSummary();
        });

        invoiceItems.addEventListener('input', updateInvoiceSummary);
        updateInvoiceSummary();
    }

    const invoiceStatusField = document.getElementById('invoicePaymentStatus');
    const invoicePaymentOptionField = document.getElementById('invoicePaymentOption');
    if (invoiceStatusField) {
        invoiceStatusField.addEventListener('change', toggleInvoicePaymentDetails);
        toggleInvoicePaymentDetails();
    }
    if (invoicePaymentOptionField) {
        invoicePaymentOptionField.addEventListener('change', toggleInvoicePaymentDetails);
        toggleInvoicePaymentDetails();
    }

    const settlementPaymentOptionField = document.getElementById('settlementPaymentOption');
    if (settlementPaymentOptionField) {
        settlementPaymentOptionField.addEventListener('change', toggleSettlementPaymentDetails);
        toggleSettlementPaymentDetails();
    }
</script>
</body>
</html>
