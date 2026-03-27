<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$customerPageMode = isset($customerPageMode) && is_string($customerPageMode)
    ? trim($customerPageMode)
    : 'customers';
$allowedCustomerPageModes = ['customers', 'invoice_create', 'customer_invoices', 'invoice_details'];

if (!in_array($customerPageMode, $allowedCustomerPageModes, true)) {
    $customerPageMode = 'customers';
}

$paymentMethods = ['كاش', 'انستا باي', 'محفظة'];
$paymentOptions = ['وسيلة واحدة', 'وسيلتين دفع'];
$paymentStatuses = ['مدفوعة', 'أجل', 'نصف مدفوعة'];
$error = '';
$success = trim((string) ($_GET['success'] ?? ''));
$submittedAction = trim((string) ($_POST['form_action'] ?? ''));

function ensureCustomerTables(PDO $pdo)
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS customers (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS customer_invoices (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            customer_id INT(11) NOT NULL,
            payment_status ENUM('مدفوعة','أجل','نصف مدفوعة') NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_option ENUM('وسيلة واحدة','وسيلتين دفع') DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY customer_id (customer_id),
            CONSTRAINT customer_invoices_ibfk_1 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS customer_invoice_items (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT(11) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            KEY invoice_id (invoice_id),
            CONSTRAINT customer_invoice_items_ibfk_1 FOREIGN KEY (invoice_id) REFERENCES customer_invoices (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS customer_invoice_payments (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT(11) NOT NULL,
            payment_method ENUM('كاش','انستا باي','محفظة') NOT NULL,
            payment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            KEY invoice_id (invoice_id),
            CONSTRAINT customer_invoice_payments_ibfk_1 FOREIGN KEY (invoice_id) REFERENCES customer_invoices (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }
}

function tableExists(PDO $pdo, $tableName)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $tableName)) {
        return false;
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([(string) $tableName]);

    return (bool) $stmt->fetchColumn();
}

function tableHasColumn(PDO $pdo, $tableName, $columnName)
{
    if (!tableExists($pdo, $tableName)) {
        return false;
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $columnName)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([(string) $tableName, (string) $columnName]);

    return (bool) $stmt->fetchColumn();
}

function syncCustomerTableStructure(PDO $pdo)
{
    if (!tableHasColumn($pdo, 'customers', 'balance')) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER phone");
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

function buildCustomerPageUrl($path, array $params = [], $fragment = '')
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

function appendCustomerUrlParams($url, array $params)
{
    $parts = parse_url($url);

    if ($parts === false) {
        return customerListPageUrl($params);
    }

    $queryParams = [];

    if (isset($parts['query'])) {
        parse_str($parts['query'], $queryParams);
    }

    foreach ($params as $key => $value) {
        $queryParams[$key] = $value;
    }

    $rebuiltUrl = (string) ($parts['path'] ?? 'customers.php');

    if ($queryParams !== []) {
        $rebuiltUrl .= '?' . http_build_query($queryParams);
    }

    if (!empty($parts['fragment'])) {
        $rebuiltUrl .= '#' . $parts['fragment'];
    }

    return $rebuiltUrl;
}

function sanitizeCustomerReturnUrl($value, $defaultUrl)
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
        'customers.php',
        'customer_invoice_create.php',
        'customer_invoices.php',
        'customer_invoice_details.php',
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

function customerListPageUrl(array $params = [])
{
    return buildCustomerPageUrl('customers.php', $params);
}

function customerInvoiceCreatePageUrl($customerId = 0, array $params = [])
{
    if ((int) $customerId > 0) {
        $params = array_merge([
            'customer_id' => (int) $customerId,
        ], $params);
    }

    return buildCustomerPageUrl('customer_invoice_create.php', $params);
}

function customerInvoicesPageUrl($customerId, array $params = [])
{
    return buildCustomerPageUrl('customer_invoices.php', array_merge([
        'customer_id' => (int) $customerId,
    ], $params));
}

function customerInvoiceDetailsPageUrl($customerId, $invoiceId, array $params = [])
{
    return buildCustomerPageUrl('customer_invoice_details.php', array_merge([
        'customer_id' => (int) $customerId,
        'invoice_id' => (int) $invoiceId,
    ], $params));
}

function getCustomerById(PDO $pdo, $customerId)
{
    if ($customerId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            phone,
            balance,
            created_at
        FROM customers c
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$customerId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getCustomerByPhone(PDO $pdo, $phone)
{
    $phone = trim((string) $phone);

    if ($phone === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, name, phone, balance, created_at
        FROM customers
        WHERE phone = ?
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([$phone]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateCustomerBalance(PDO $pdo, $customerId, $amountDelta)
{
    $stmt = $pdo->prepare("
        UPDATE customers
        SET balance = GREATEST(0, ROUND(COALESCE(balance, 0) + ?, 2))
        WHERE id = ?
    ");

    $stmt->execute([normalizeAmount($amountDelta), (int) $customerId]);
}

function fetchSalesItemCatalog(PDO $pdo)
{
    $catalog = [];

    if (tableExists($pdo, 'issued_items')) {
        $issuedItemsStmt = $pdo->query("
            SELECT item_name, unit_price
            FROM issued_items
            WHERE item_name <> ''
            ORDER BY id DESC
        ");

        foreach ($issuedItemsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['item_name'] ?? ''));

            if ($name === '' || isset($catalog[$name])) {
                continue;
            }

            $catalog[$name] = [
                'item_name' => $name,
                'unit_price' => normalizeAmount($row['unit_price'] ?? 0),
            ];
        }
    }

    if (tableExists($pdo, 'inventory_items')) {
        $inventoryItemsStmt = $pdo->query("
            SELECT item_name, unit_price
            FROM inventory_items
            WHERE item_name <> ''
            ORDER BY id DESC
        ");

        foreach ($inventoryItemsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['item_name'] ?? ''));

            if ($name === '' || isset($catalog[$name])) {
                continue;
            }

            $catalog[$name] = [
                'item_name' => $name,
                'unit_price' => normalizeAmount($row['unit_price'] ?? 0),
            ];
        }
    }

    ksort($catalog, SORT_NATURAL | SORT_FLAG_CASE);

    return array_values($catalog);
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
            $error = 'العدد والسعر يجب أن يكونا أرقامًا صالحة';

            return [];
        }

        $quantity = normalizeAmount($quantityValue);
        $unitPrice = normalizeAmount($priceValue);

        if ($quantity <= 0 || $unitPrice <= 0) {
            $error = 'يجب أن يكون العدد والسعر أكبر من صفر';

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
        $error = 'مبالغ وسيلتي الدفع يجب أن تكون أرقامًا صالحة';

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

ensureCustomerTables($pdo);
syncCustomerTableStructure($pdo);
$saleItemCatalog = fetchSalesItemCatalog($pdo);

$selectedCustomerId = (int) ($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$selectedInvoiceId = (int) ($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
$legacyView = trim((string) ($_GET['view'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $customerPageMode === 'customers') {
    if ($selectedCustomerId > 0 && $selectedInvoiceId > 0) {
        header('Location: ' . customerInvoiceDetailsPageUrl($selectedCustomerId, $selectedInvoiceId, array_filter([
            'back' => customerInvoicesPageUrl($selectedCustomerId, ['back' => customerListPageUrl()]),
            'success' => $success !== '' ? $success : null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        })));
        exit;
    }

    if ($selectedCustomerId > 0 && $legacyView === 'invoice') {
        header('Location: ' . customerInvoiceCreatePageUrl($selectedCustomerId, array_filter([
            'back' => customerListPageUrl(),
            'success' => $success !== '' ? $success : null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        })));
        exit;
    }

    if ($selectedCustomerId > 0 && $legacyView === 'invoices') {
        header('Location: ' . customerInvoicesPageUrl($selectedCustomerId, array_filter([
            'back' => customerListPageUrl(),
            'success' => $success !== '' ? $success : null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        })));
        exit;
    }
}

$invoiceListBaseUrl = $selectedCustomerId > 0
    ? customerInvoicesPageUrl($selectedCustomerId)
    : customerListPageUrl();
$invoiceCreateBackUrl = sanitizeCustomerReturnUrl(
    $_GET['back'] ?? $_POST['return_to'] ?? '',
    $invoiceListBaseUrl
);
$invoiceListBackUrl = sanitizeCustomerReturnUrl($_GET['back'] ?? '', customerListPageUrl());
$invoiceDetailsBackUrl = sanitizeCustomerReturnUrl(
    $_GET['back'] ?? $_POST['return_to'] ?? '',
    $invoiceListBaseUrl
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($submittedAction === 'add_customer') {
        $customerName = trim((string) ($_POST['customer_name'] ?? ''));
        $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));

        if ($customerName === '' || $customerPhone === '') {
            $error = 'اسم العميل ورقم التليفون مطلوبان';
        } else {
            $check = $pdo->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
            $check->execute([$customerPhone]);

            if ($check->fetch(PDO::FETCH_ASSOC)) {
                $error = 'رقم هاتف العميل مسجل بالفعل';
            } else {
                $stmt = $pdo->prepare("INSERT INTO customers (name, phone, balance, created_at) VALUES (?, ?, 0.00, ?)");
                $stmt->execute([$customerName, $customerPhone, getEgyptDateTimeValue()]);

                header('Location: ' . customerListPageUrl([
                    'success' => 'تم حفظ العميل بنجاح',
                ]));
                exit;
            }
        }
    } elseif ($submittedAction === 'add_invoice') {
        $selectedCustomerId = (int) ($_POST['customer_id'] ?? 0);
        $invoiceCreateBackUrl = sanitizeCustomerReturnUrl(
            $_POST['return_to'] ?? '',
            $selectedCustomerId > 0 ? customerInvoicesPageUrl($selectedCustomerId) : customerListPageUrl()
        );
        $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
        $customerName = trim((string) ($_POST['customer_name'] ?? ''));
        $customer = $selectedCustomerId > 0 ? getCustomerById($pdo, $selectedCustomerId) : null;

        if ($customerPhone === '') {
            $error = 'رقم هاتف العميل مطلوب قبل حفظ الفاتورة';
        }

        if ($error === '' && (!$customer || trim((string) ($customer['phone'] ?? '')) !== $customerPhone)) {
            $customer = getCustomerByPhone($pdo, $customerPhone);
        }

        if ($error === '' && !$customer) {
            if ($customerName === '') {
                $error = 'اسم العميل مطلوب عند إنشاء عميل جديد من شاشة الفاتورة';
            } else {
                $now = getEgyptDateTimeValue();
                $createCustomerStmt = $pdo->prepare("
                    INSERT INTO customers (name, phone, balance, created_at)
                    VALUES (?, ?, 0.00, ?)
                ");
                $createCustomerStmt->execute([$customerName, $customerPhone, $now]);
                $selectedCustomerId = (int) $pdo->lastInsertId();
                $customer = getCustomerById($pdo, $selectedCustomerId);
            }
        }

        if ($error === '' && !$customer) {
            $error = 'تعذر تحديد العميل المطلوب حفظ الفاتورة له';
        }

        if ($error === '') {
            $selectedCustomerId = (int) ($customer['id'] ?? 0);
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
                            INSERT INTO customer_invoices (
                                customer_id,
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
                            $selectedCustomerId,
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
                            INSERT INTO customer_invoice_items (
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
                                INSERT INTO customer_invoice_payments (
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

                        if ($amountDue > 0) {
                            updateCustomerBalance($pdo, $selectedCustomerId, $amountDue);
                        }

                        $pdo->commit();

                        header('Location: ' . customerInvoiceDetailsPageUrl($selectedCustomerId, $invoiceId, [
                            'back' => customerInvoicesPageUrl($selectedCustomerId, ['back' => customerListPageUrl()]),
                            'success' => 'تم حفظ فاتورة العميل بنجاح',
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
        $selectedCustomerId = (int) ($_POST['customer_id'] ?? 0);
        $selectedInvoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $invoiceDetailsBackUrl = sanitizeCustomerReturnUrl(
            $_POST['return_to'] ?? '',
            $selectedCustomerId > 0 ? customerInvoicesPageUrl($selectedCustomerId) : customerListPageUrl()
        );

        $invoiceStmt = $pdo->prepare("
            SELECT id, customer_id, total_amount, amount_paid, amount_due
            FROM customer_invoices
            WHERE id = ? AND customer_id = ?
            LIMIT 1
        ");
        $invoiceStmt->execute([$selectedInvoiceId, $selectedCustomerId]);
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
                                INSERT INTO customer_invoice_payments (
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
                                UPDATE customer_invoices
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

                            updateCustomerBalance($pdo, $selectedCustomerId, -$paymentAmount);

                            $pdo->commit();

                            header('Location: ' . customerInvoiceDetailsPageUrl($selectedCustomerId, $selectedInvoiceId, [
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
    } elseif ($submittedAction === 'delete_invoice') {
        if (($_SESSION['role'] ?? '') !== 'مدير') {
            $error = 'حذف الفاتورة متاح لحساب المدير فقط';
        } else {
            $selectedCustomerId = (int) ($_POST['customer_id'] ?? 0);
            $selectedInvoiceId = (int) ($_POST['invoice_id'] ?? 0);
            $deleteReturnUrl = sanitizeCustomerReturnUrl(
                $_POST['return_to'] ?? '',
                $selectedCustomerId > 0 ? customerInvoicesPageUrl($selectedCustomerId, ['back' => customerListPageUrl()]) : customerListPageUrl()
            );

            $invoiceStmt = $pdo->prepare("
                SELECT id, customer_id, amount_due
                FROM customer_invoices
                WHERE id = ? AND customer_id = ?
                LIMIT 1
            ");
            $invoiceStmt->execute([$selectedInvoiceId, $selectedCustomerId]);
            $invoiceForDeletion = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$invoiceForDeletion) {
                $error = 'الفاتورة المحددة غير موجودة أو تم حذفها بالفعل';
            } else {
                try {
                    $pdo->beginTransaction();

                    if ((float) ($invoiceForDeletion['amount_due'] ?? 0) > 0) {
                        updateCustomerBalance($pdo, $selectedCustomerId, -((float) $invoiceForDeletion['amount_due']));
                    }

                    $deleteStmt = $pdo->prepare("DELETE FROM customer_invoices WHERE id = ? AND customer_id = ?");
                    $deleteStmt->execute([$selectedInvoiceId, $selectedCustomerId]);

                    $pdo->commit();

                    header('Location: ' . appendCustomerUrlParams($deleteReturnUrl, [
                        'success' => 'تم حذف الفاتورة بنجاح',
                    ]));
                    exit;
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = 'تعذر حذف الفاتورة حاليًا، برجاء المحاولة مرة أخرى';
                }
            }
        }
    }
}

$customers = [];
$totalCustomersBalance = 0.00;
$unpaidInvoicesCount = 0;

if ($customerPageMode === 'customers') {
    $customersStmt = $pdo->query("
        SELECT
            s.id,
            s.name,
            s.phone,
            s.created_at,
            COALESCE(s.balance, 0) AS balance,
            COUNT(si.id) AS total_invoices
        FROM customers s
        LEFT JOIN customer_invoices si ON si.customer_id = s.id
        GROUP BY s.id, s.name, s.phone, s.balance, s.created_at
        ORDER BY s.id DESC
    ");
    $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

    $customerStatsStmt = $pdo->query("
        SELECT
            COALESCE(SUM(amount_due), 0) AS total_balance,
            COUNT(CASE WHEN amount_due > 0 THEN 1 END) AS unpaid_invoices_count
        FROM customer_invoices
    ");
    $customerStats = $customerStatsStmt->fetch(PDO::FETCH_ASSOC);
    $totalCustomersBalance = normalizeAmount($customerStats['total_balance'] ?? 0);
    $unpaidInvoicesCount = (int) ($customerStats['unpaid_invoices_count'] ?? 0);
}

$selectedCustomer = getCustomerById($pdo, $selectedCustomerId);
$customerInvoices = [];
$customerInvoicePayments = [];
$customerInvoicePaymentsByInvoice = [];
$selectedInvoice = null;
$selectedInvoiceItems = [];
$selectedInvoicePayments = [];

if (
    !$selectedCustomer
    && $selectedCustomerId > 0
    && in_array($customerPageMode, ['invoice_create', 'customer_invoices', 'invoice_details'], true)
    && $error === ''
) {
    $error = 'العميل المحدد غير موجود';
}

if ($selectedCustomerId > 0 && $selectedCustomer) {
    $invoiceListStmt = $pdo->prepare("
        SELECT id, payment_status, total_amount, amount_paid, amount_due, created_at, updated_at
        FROM customer_invoices
        WHERE customer_id = ?
        ORDER BY created_at DESC, id DESC
    ");
    $invoiceListStmt->execute([$selectedCustomerId]);
    $customerInvoices = $invoiceListStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($customerInvoices) {
        $customerPaymentsStmt = $pdo->prepare("
            SELECT sip.invoice_id, sip.payment_method, sip.payment_amount, sip.created_at
            FROM customer_invoice_payments sip
            INNER JOIN customer_invoices si ON si.id = sip.invoice_id
            WHERE si.customer_id = ?
            ORDER BY sip.created_at DESC, sip.id DESC
        ");
        $customerPaymentsStmt->execute([$selectedCustomerId]);
        $customerInvoicePayments = $customerPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($customerInvoicePayments as $paymentRow) {
            $invoiceId = (int) $paymentRow['invoice_id'];
            $customerInvoicePaymentsByInvoice[$invoiceId][] = $paymentRow;
        }
    }
}

if ($selectedCustomerId > 0 && $selectedInvoiceId > 0 && $selectedCustomer) {
    $invoiceDetailsStmt = $pdo->prepare("
        SELECT id, customer_id, payment_status, total_amount, amount_paid, amount_due, created_at, updated_at
        FROM customer_invoices
        WHERE id = ? AND customer_id = ?
        LIMIT 1
    ");
    $invoiceDetailsStmt->execute([$selectedInvoiceId, $selectedCustomerId]);
    $selectedInvoice = $invoiceDetailsStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedInvoice) {
        $itemsStmt = $pdo->prepare("
            SELECT item_name, quantity, unit_price, line_total, created_at
            FROM customer_invoice_items
            WHERE invoice_id = ?
            ORDER BY id ASC
        ");
        $itemsStmt->execute([$selectedInvoiceId]);
        $selectedInvoiceItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $paymentsStmt = $pdo->prepare("
            SELECT payment_method, payment_amount, created_at
            FROM customer_invoice_payments
            WHERE invoice_id = ?
            ORDER BY id ASC
        ");
        $paymentsStmt->execute([$selectedInvoiceId]);
        $selectedInvoicePayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($customerPageMode === 'invoice_details' && $error === '') {
        $error = 'الفاتورة المحددة غير موجودة';
    }
}

$customerInvoicesCount = count($customerInvoices);
$settledInvoicesCount = 0;
$openInvoicesCount = 0;
$customerTotalPaid = 0.00;

if ($customerInvoices) {
    foreach ($customerInvoices as $invoiceSummary) {
        $customerTotalPaid += (float) $invoiceSummary['amount_paid'];

        if ((float) $invoiceSummary['amount_due'] <= 0) {
            $settledInvoicesCount++;
        } else {
            $openInvoicesCount++;
        }
    }
}

$customerTotalPaid = normalizeAmount($customerTotalPaid);

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
$invoiceCustomerNameValue = $submittedAction === 'add_invoice'
    ? trim((string) ($_POST['customer_name'] ?? ''))
    : trim((string) ($selectedCustomer['name'] ?? ''));
$invoiceCustomerPhoneValue = $submittedAction === 'add_invoice'
    ? trim((string) ($_POST['customer_phone'] ?? ''))
    : trim((string) ($selectedCustomer['phone'] ?? ''));

$settlementAmountValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_amount'] ?? '')) : '';
$settlementPaymentOptionValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_option'] ?? 'وسيلة واحدة')) : 'وسيلة واحدة';
$settlementPaymentSingleValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_method_single'] ?? '')) : '';
$settlementPaymentOneValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_method_one'] ?? '')) : '';
$settlementPaymentTwoValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_method_two'] ?? '')) : '';
$settlementPaymentAmountOneValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_amount_one'] ?? '')) : '';
$settlementPaymentAmountTwoValue = $submittedAction === 'add_payment' ? trim((string) ($_POST['payment_amount_two'] ?? '')) : '';

$isCustomersPage = $customerPageMode === 'customers';
$isInvoiceCreatePage = $customerPageMode === 'invoice_create';
$isInvoiceListPage = $customerPageMode === 'customer_invoices';
$isInvoiceDetailsPage = $customerPageMode === 'invoice_details';

$currentInvoiceListUrl = $selectedCustomerId > 0
    ? customerInvoicesPageUrl($selectedCustomerId, ['back' => $invoiceListBackUrl])
    : customerListPageUrl();
$pageTitle = 'العملاء';
$pageDescription = 'تسجيل العملاء ومتابعة المديونية والوصول إلى فواتير البيع وسجل السداد.';
$pageChip = '🤝 إدارة العملاء';
$pageBackUrl = customerListPageUrl();
$activeSidebarLabel = 'عملاء';

if ($isInvoiceCreatePage) {
    $pageTitle = 'إنشاء فاتورة بيع';
    $pageDescription = 'أنشئ فاتورة بيع عبر رقم هاتف العميل مع دعم الإكمال التلقائي للأصناف وطرق السداد.';
    $pageChip = '🛒 المبيعات';
    $pageBackUrl = $invoiceCreateBackUrl;
    $activeSidebarLabel = 'المبيعات';
} elseif ($isInvoiceListPage) {
    $pageTitle = 'فواتير العميل';
    $pageDescription = 'عرض فواتير العميل في صفحة منفصلة ومنظمة مع الوصول لتفاصيل كل فاتورة.';
    $pageChip = '📚 فواتير العميل';
    $pageBackUrl = $invoiceListBackUrl;
    $activeSidebarLabel = 'المبيعات';
} elseif ($isInvoiceDetailsPage) {
    $pageTitle = 'تفاصيل فاتورة العميل';
    $pageDescription = 'عرض تفاصيل الفاتورة وسجل التسديدات في صفحة مستقلة مع رجوع مباشر.';
    $pageChip = '📄 تفاصيل الفاتورة';
    $pageBackUrl = $invoiceDetailsBackUrl;
    $activeSidebarLabel = 'المبيعات';
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
            <?php echo renderSidebarSections($activeSidebarLabel); ?>
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

        <?php if ($isCustomersPage): ?>
            <div class="invoice-meta-grid" style="margin-bottom: 1.5rem;">
                <div class="summary-box">
                    <p>إجمالي مديونية العملاء</p>
                    <strong><?php echo e(formatMoney($totalCustomersBalance)); ?> ج.م</strong>
                </div>
                <div class="summary-box">
                    <p>عدد الفواتير غير المدفوعة</p>
                    <strong><?php echo e($unpaidInvoicesCount); ?></strong>
                </div>
            </div>

            <div class="customer-layout">
                <div class="form-card">
                    <div class="page-header">
                        <h2>إضافة عميل جديد</h2>
                        <span class="muted-text">يمكنك أيضًا إنشاء فاتورة بيع مباشرة عبر رقم هاتف العميل من شاشة المبيعات.</span>
                    </div>

                    <form method="POST" data-customer-lookup-form>
                        <input type="hidden" name="form_action" value="add_customer">

                        <div class="form-group">
                            <label>اسم العميل</label>
                            <input type="text" name="customer_name" value="<?php echo e($submittedAction === 'add_customer' ? ($_POST['customer_name'] ?? '') : ''); ?>" required data-customer-name-input>
                        </div>

                        <div class="form-group">
                            <label>رقم التليفون</label>
                            <input type="text" name="customer_phone" value="<?php echo e($submittedAction === 'add_customer' ? ($_POST['customer_phone'] ?? '') : ''); ?>" required data-customer-phone-input>
                            <small class="muted-text" data-customer-lookup-status data-default-text="اكتب رقم التليفون للتحقق من بيانات العميل الحالي.">اكتب رقم التليفون للتحقق من بيانات العميل الحالي.</small>
                        </div>

                        <div class="table-actions">
                            <button type="submit">💾 حفظ العميل</button>
                            <a class="inline-link small-link secondary-button" href="<?php echo e(customerInvoiceCreatePageUrl(0, ['back' => customerListPageUrl()])); ?>">🛒 إنشاء فاتورة بيع</a>
                        </div>
                    </form>
                </div>

                <div class="table-card">
                    <div class="page-header">
                        <h2>العملاء المسجلون</h2>
                        <span class="muted-text">إجمالي العملاء: <?php echo count($customers); ?></span>
                    </div>

                    <?php if ($customers): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>اسم العميل</th>
                                    <th>رقم التليفون</th>
                                    <th>رصيد العميل</th>
                                    <th>الفواتير</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><?php echo e($customer['name']); ?></td>
                                        <td><?php echo e($customer['phone']); ?></td>
                                        <td><?php echo e(formatMoney($customer['balance'])); ?> ج.م</td>
                                        <td><?php echo (int) $customer['total_invoices']; ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="inline-link small-link" href="<?php echo e(customerInvoiceCreatePageUrl((int) $customer['id'], [
                                                    'back' => customerListPageUrl(),
                                                ])); ?>">إضافة فاتورة</a>
                                                <a class="inline-link small-link" href="<?php echo e(customerInvoicesPageUrl((int) $customer['id'], [
                                                    'back' => customerListPageUrl(),
                                                ])); ?>">عرض الفواتير السابقة</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="muted-text">لا يوجد عملاء مسجلون حتى الآن.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="table-card customer-focus-card">
                <div class="customer-focus-header">
                    <div>
                        <div class="page-header">
                            <h2>
                                <?php if ($selectedCustomer): ?>
                                    العميل: <?php echo e($selectedCustomer['name']); ?>
                                <?php else: ?>
                                    فاتورة بيع جديدة
                                <?php endif; ?>
                            </h2>
                            <span class="muted-text">
                                <?php echo e($selectedCustomer ? 'يمكنك متابعة الفواتير والطباعة وسجل السداد من نفس المسار المنظم.' : 'يمكنك إنشاء فاتورة بيع مباشرة عبر رقم الهاتف أو اختيار عميل مسجل للمتابعة.'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="customer-focus-actions">
                        <a class="inline-link" href="<?php echo e($pageBackUrl); ?>">⬅ رجوع</a>
                        <?php if (!$isInvoiceCreatePage): ?>
                            <a class="inline-link" href="<?php echo e(customerInvoiceCreatePageUrl($selectedCustomer ? (int) $selectedCustomer['id'] : 0, [
                                'back' => $isInvoiceListPage ? $currentInvoiceListUrl : $pageBackUrl,
                            ])); ?>">➕ إضافة فاتورة</a>
                        <?php endif; ?>
                        <?php if ($selectedCustomer && !$isInvoiceListPage): ?>
                            <a class="inline-link" href="<?php echo e(customerInvoicesPageUrl((int) $selectedCustomer['id'], [
                                'back' => customerListPageUrl(),
                            ])); ?>">📚 فواتير العميل</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($selectedCustomer): ?>
                    <div class="invoice-meta-grid">
                        <div class="summary-box">
                            <p>رقم التليفون</p>
                            <strong><?php echo e($selectedCustomer['phone']); ?></strong>
                        </div>
                        <div class="summary-box">
                            <p>الرصيد الحالي</p>
                            <strong><?php echo e(formatMoney($selectedCustomer['balance'])); ?> ج.م</strong>
                        </div>
                        <div class="summary-box">
                            <p>عدد الفواتير</p>
                            <strong><?php echo e($customerInvoicesCount); ?></strong>
                        </div>
                        <div class="summary-box">
                            <p>إجمالي المدفوع</p>
                            <strong><?php echo e(formatMoney($customerTotalPaid)); ?> ج.م</strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isInvoiceCreatePage): ?>
            <div class="form-card" id="invoice-form">
                <div class="page-header">
                    <h2>
                        <?php echo e($selectedCustomer ? 'إضافة فاتورة للعميل: ' . $selectedCustomer['name'] : 'إنشاء فاتورة بيع جديدة'); ?>
                    </h2>
                    <div class="table-actions">
                        <span class="muted-text">أدخل رقم هاتف العميل، ثم أضف الأصناف وحدد نوع التسديد ووسائل الدفع.</span>
                        <a class="inline-link small-link secondary-button" href="<?php echo e($invoiceCreateBackUrl); ?>">إلغاء والرجوع</a>
                    </div>
                </div>

                <form method="POST" id="customerInvoiceForm" class="section-stack" data-customer-lookup-form>
                    <input type="hidden" name="form_action" value="add_invoice">
                    <input type="hidden" name="customer_id" value="<?php echo (int) ($selectedCustomer['id'] ?? 0); ?>" data-customer-id-input>
                    <input type="hidden" name="return_to" value="<?php echo e($invoiceCreateBackUrl); ?>">

                    <div class="payment-method-grid">
                        <div class="form-group">
                            <label>رقم هاتف العميل</label>
                            <input type="text" name="customer_phone" value="<?php echo e($invoiceCustomerPhoneValue); ?>" required data-customer-phone-input>
                            <small class="muted-text">إذا كان العميل مسجلًا سيتم ربط الفاتورة به تلقائيًا.</small>
                        </div>
                        <div class="form-group">
                            <label>اسم العميل</label>
                            <input type="text" name="customer_name" value="<?php echo e($invoiceCustomerNameValue); ?>" data-customer-name-input>
                            <small class="muted-text">يصبح مطلوبًا فقط إذا كان العميل جديدًا لأول مرة.</small>
                            <small class="muted-text" data-customer-lookup-status data-default-text="عند كتابة رقم هاتف عميل مسجل سيتم تعبئة الاسم وإظهار رصيد المديونية الحالي.">عند كتابة رقم هاتف عميل مسجل سيتم تعبئة الاسم وإظهار رصيد المديونية الحالي.</small>
                        </div>
                    </div>

                    <div>
                        <label>أصناف الفاتورة</label>
                        <div id="invoiceItems">
                            <?php for ($rowIndex = 0; $rowIndex < $invoiceRowCount; $rowIndex++): ?>
                                <div class="invoice-item-row">
                                    <div class="form-group">
                                        <label>اسم الصنف</label>
                                        <input type="text" name="item_name[]" list="saleItemSuggestions" value="<?php echo e($invoiceFormNames[$rowIndex] ?? ''); ?>" required autocomplete="off">
                                    </div>
                                    <div class="form-group">
                                        <label>العدد</label>
                                        <input type="number" step="0.01" min="0.01" name="quantity[]" value="<?php echo e($invoiceFormQuantities[$rowIndex] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>السعر</label>
                                        <input type="number" step="0.01" min="0.01" name="unit_price[]" value="<?php echo e($invoiceFormPrices[$rowIndex] ?? ''); ?>" required>
                                    </div>
                                    <button type="button" class="secondary-button button-inline remove-item-row">حذف</button>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <datalist id="saleItemSuggestions"></datalist>
                        <small class="muted-text">اكتب أول حرفين من اسم الصنف ليظهر لك اقتراح من الأصناف المسجلة.</small>
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

        <?php if ($isInvoiceListPage && $selectedCustomer): ?>
            <div class="table-card" id="customer-invoices">
                <div class="page-header">
                    <h2>الفواتير السابقة للعميل</h2>
                    <div class="table-actions">
                        <span class="muted-text">كل فاتورة أصبحت لها صفحة تفاصيل مستقلة وسجل تسديداتها الخاص.</span>
                        <a class="inline-link small-link secondary-button" href="<?php echo e($invoiceListBackUrl); ?>">رجوع</a>
                    </div>
                </div>

                <?php if ($customerInvoices): ?>
                    <div class="invoice-summary-strip">
                        <div class="summary-box">
                            <p>عدد الفواتير</p>
                            <strong><?php echo (int) $customerInvoicesCount; ?></strong>
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
                            <strong><?php echo e(formatMoney($customerTotalPaid)); ?> ج.م</strong>
                        </div>
                    </div>

                    <div class="invoice-records-list">
                        <?php foreach ($customerInvoices as $invoice): ?>
                            <?php
                            $invoiceId = (int) $invoice['id'];
                            $invoicePayments = $customerInvoicePaymentsByInvoice[$invoiceId] ?? [];
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
                                    <a class="inline-link small-link" href="<?php echo e(customerInvoiceDetailsPageUrl(
                                        $selectedCustomerId,
                                        $invoiceId,
                                        ['back' => $currentInvoiceListUrl]
                                    )); ?>">عرض التفاصيل والتسديد</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted-text">لا توجد فواتير مسجلة لهذا العميل حتى الآن.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isInvoiceDetailsPage && $selectedInvoice && $selectedCustomer): ?>
            <div class="customer-management-grid" id="invoice-details">
                <div class="table-card">
                    <div class="page-header">
                        <h2>تفاصيل الفاتورة رقم <?php echo (int) $selectedInvoice['id']; ?></h2>
                        <div class="table-actions">
                            <a class="inline-link small-link" href="customer_invoice_print.php?customer_id=<?php echo (int) $selectedCustomerId; ?>&invoice_id=<?php echo (int) $selectedInvoice['id']; ?>" target="_blank" rel="noopener">🖨️ طباعة الفاتورة</a>
                            <?php if (($_SESSION['role'] ?? '') === 'مدير'): ?>
                                <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذه الفاتورة؟');">
                                    <input type="hidden" name="form_action" value="delete_invoice">
                                    <input type="hidden" name="customer_id" value="<?php echo (int) $selectedCustomerId; ?>">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int) $selectedInvoice['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo e($invoiceDetailsBackUrl); ?>">
                                    <button type="submit" class="secondary-button button-inline">🗑️ حذف الفاتورة</button>
                                </form>
                            <?php endif; ?>
                            <a class="inline-link small-link secondary-button" href="<?php echo e($invoiceDetailsBackUrl); ?>">رجوع</a>
                        </div>
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

                        <form method="POST" id="customerPaymentForm" class="section-stack">
                            <input type="hidden" name="form_action" value="add_payment">
                            <input type="hidden" name="customer_id" value="<?php echo (int) $selectedCustomerId; ?>">
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
    const saleItemCatalog = <?php echo json_encode($saleItemCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const customerLookupEndpoint = 'customer_lookup.php';
    const customerLookupCache = Object.create(null);

    function parseNumber(value) {
        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function applyCustomerLookup(form, customer, errorMessage = '') {
        if (!form) {
            return;
        }

        const nameInput = form.querySelector('[data-customer-name-input]');
        const customerIdInput = form.querySelector('[data-customer-id-input]');
        const statusElement = form.querySelector('[data-customer-lookup-status]');
        const defaultStatusText = statusElement ? (statusElement.dataset.defaultText || '') : '';
        if (!nameInput) {
            return;
        }

        if (!customer && errorMessage !== '') {
            if (statusElement) {
                statusElement.textContent = errorMessage;
                statusElement.style.color = '#b54708';
            }

            return;
        }

        if (!customer) {
            if (nameInput.dataset.autoFilled === 'true') {
                nameInput.value = '';
            }

            nameInput.dataset.autoFilled = 'false';
            nameInput.readOnly = false;

            if (customerIdInput) {
                customerIdInput.value = '';
            }

            if (statusElement) {
                statusElement.textContent = defaultStatusText;
                statusElement.style.color = '';
            }

            return;
        }

        nameInput.value = customer.name || '';
        nameInput.dataset.autoFilled = 'true';
        nameInput.readOnly = true;

        if (customerIdInput) {
            customerIdInput.value = String(customer.id || '');
        }

        if (statusElement) {
            statusElement.textContent = `اسم العميل: ${customer.name || ''} — رصيد المديونية: ${customer.balance_label || ''}`;
            statusElement.style.color = parseNumber(customer.balance) > 0 ? '#b42318' : '#027a48';
        }
    }

    async function fetchCustomerByPhone(phone) {
        if (phone === '') {
            return null;
        }

        if (Object.prototype.hasOwnProperty.call(customerLookupCache, phone)) {
            return customerLookupCache[phone];
        }

        const response = await fetch(`${customerLookupEndpoint}?phone=${encodeURIComponent(phone)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error('lookup-failed');
        }

        const payload = await response.json();
        const customer = payload && payload.success ? (payload.customer || null) : null;
        customerLookupCache[phone] = customer;

        return customer;
    }

    function queueCustomerLookup(form, delay = 250) {
        if (!form) {
            return;
        }

        const phoneInput = form.querySelector('[data-customer-phone-input]');
        if (!phoneInput) {
            return;
        }

        const phone = phoneInput.value.trim();
        form.dataset.lookupRequestId = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
        const requestId = form.dataset.lookupRequestId;

        if (form.customerLookupTimer) {
            clearTimeout(form.customerLookupTimer);
        }

        if (phone === '') {
            applyCustomerLookup(form, null);
            return;
        }

        form.customerLookupTimer = setTimeout(async () => {
            const isLookupOutdated = () => form.dataset.lookupRequestId !== requestId || phoneInput.value.trim() !== phone;

            try {
                const customer = await fetchCustomerByPhone(phone);
                if (isLookupOutdated()) {
                    return;
                }

                applyCustomerLookup(form, customer);
            } catch (error) {
                if (isLookupOutdated()) {
                    return;
                }

                applyCustomerLookup(form, null, 'تعذر جلب بيانات العميل حاليًا.');
            }
        }, delay);
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
                <input type="text" name="item_name[]" list="saleItemSuggestions" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label>العدد</label>
                <input type="number" step="0.01" min="0.01" name="quantity[]" required>
            </div>
            <div class="form-group">
                <label>السعر</label>
                <input type="number" step="0.01" min="0.01" name="unit_price[]" required>
            </div>
            <button type="button" class="secondary-button button-inline remove-item-row">حذف</button>
        `;
        return row;
    }

    function refreshSaleItemSuggestions(sourceInput) {
        const datalist = document.getElementById('saleItemSuggestions');
        if (!sourceInput || !datalist) {
            return;
        }

        const value = sourceInput.value.trim().toLowerCase();
        datalist.innerHTML = '';

        if (value.length < 2) {
            return;
        }

        const seen = new Set();
        saleItemCatalog.forEach((entry) => {
            const itemName = String(entry.item_name || '').trim();
            const normalizedName = itemName.toLowerCase();

            if (!itemName || !normalizedName.startsWith(value) || seen.has(normalizedName)) {
                return;
            }

            seen.add(normalizedName);
            const option = document.createElement('option');
            option.value = itemName;
            datalist.appendChild(option);
        });
    }

    function syncSuggestedItemPrice(row, forceUpdate = false) {
        if (!row) {
            return;
        }

        const itemField = row.querySelector('input[name="item_name[]"]');
        const priceField = row.querySelector('input[name="unit_price[]"]');
        if (!itemField || !priceField) {
            return;
        }

        const itemName = itemField.value.trim();
        if (itemName === '') {
            return;
        }

        const matchedItem = saleItemCatalog.find((entry) => String(entry.item_name || '').trim() === itemName);
        if (!matchedItem) {
            return;
        }

        if (forceUpdate || priceField.value.trim() === '') {
            priceField.value = parseFloat(matchedItem.unit_price || 0).toFixed(2);
        }
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
    document.querySelectorAll('[data-customer-lookup-form]').forEach((form) => {
        const phoneInput = form.querySelector('[data-customer-phone-input]');
        if (!phoneInput) {
            return;
        }

        phoneInput.addEventListener('input', () => {
            queueCustomerLookup(form);
        });

        queueCustomerLookup(form, 0);
    });

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

        invoiceItems.addEventListener('input', (event) => {
            if (event.target.matches('input[name="item_name[]"]')) {
                refreshSaleItemSuggestions(event.target);
            }

            updateInvoiceSummary();
        });

        invoiceItems.addEventListener('change', (event) => {
            if (!event.target.matches('input[name="item_name[]"]')) {
                return;
            }

            syncSuggestedItemPrice(event.target.closest('.invoice-item-row'));
            updateInvoiceSummary();
        });

        invoiceItems.addEventListener('focusin', (event) => {
            if (!event.target.matches('input[name="item_name[]"]')) {
                return;
            }

            refreshSaleItemSuggestions(event.target);
        });

        invoiceItems.querySelectorAll('.invoice-item-row').forEach((row) => {
            syncSuggestedItemPrice(row);
        });

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
