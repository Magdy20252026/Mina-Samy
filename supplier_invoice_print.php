<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$selectedSupplierId = (int) ($_GET['supplier_id'] ?? 0);
$selectedInvoiceId = (int) ($_GET['invoice_id'] ?? 0);
$printDateTime = formatDateTimeForDisplay(getEgyptDateTimeValue());
$selectedInvoice = null;
$selectedInvoiceItems = [];
$selectedInvoicePayments = [];
$error = '';

if ($selectedSupplierId <= 0 || $selectedInvoiceId <= 0) {
    $error = 'بيانات الفاتورة غير مكتملة';
} else {
    $invoiceStmt = $pdo->prepare("
        SELECT
            si.id,
            si.supplier_id,
            si.payment_status,
            si.total_amount,
            si.amount_paid,
            si.amount_due,
            si.created_at,
            s.name AS supplier_name,
            s.phone AS supplier_phone
        FROM supplier_invoices si
        INNER JOIN suppliers s ON s.id = si.supplier_id
        WHERE si.id = ? AND si.supplier_id = ?
        LIMIT 1
    ");
    $invoiceStmt->execute([$selectedInvoiceId, $selectedSupplierId]);
    $selectedInvoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedInvoice) {
        $itemsStmt = $pdo->prepare("
            SELECT item_name, quantity, unit_price, line_total
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
    } else {
        $error = 'الفاتورة المطلوبة غير موجودة';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة فاتورة المورد - <?php echo e($store['name']); ?></title>
    <style>
        :root {
            color-scheme: light;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Tahoma, Arial, sans-serif;
            background: #f3f4f6;
            color: #111827;
        }

        .print-page {
            max-width: 980px;
            margin: 24px auto;
            padding: 32px;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        }

        .print-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .button-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border: 0;
            border-radius: 10px;
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }

        .button-link.secondary {
            background: #e5e7eb;
            color: #111827;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            padding-bottom: 20px;
            margin-bottom: 24px;
            border-bottom: 2px solid #e5e7eb;
        }

        .store-block,
        .invoice-title-block {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .store-block {
            flex-direction: column;
            text-align: center;
            min-width: 180px;
        }

        .store-logo {
            width: 72px;
            height: 72px;
            object-fit: contain;
        }

        h1,
        h2,
        h3,
        p {
            margin: 0;
        }

        .muted {
            color: #6b7280;
        }

        .meta-grid,
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .meta-box,
        .summary-box {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            background: #f9fafb;
        }

        .meta-box strong,
        .summary-box strong {
            display: block;
            margin-top: 8px;
            font-size: 18px;
        }

        .section-title {
            margin: 28px 0 14px;
            font-size: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 12px 10px;
            text-align: right;
            vertical-align: top;
        }

        thead {
            background: #eff6ff;
        }

        .empty-box {
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            padding: 18px;
            background: #f8fafc;
        }

        @media print {
            @page {
                size: auto;
                margin: 12mm;
            }

            body {
                background: #ffffff;
            }

            .print-page {
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            .print-actions {
                display: none;
            }
        }
    </style>
</head>
<body<?php echo $error === '' ? ' onload="window.print()"' : ''; ?>>
    <div class="print-page">
        <div class="print-actions">
            <a class="button-link secondary" href="supplier_invoice_details.php?supplier_id=<?php echo (int) $selectedSupplierId; ?>&invoice_id=<?php echo (int) $selectedInvoiceId; ?>">⬅ الرجوع للتفاصيل</a>
            <?php if ($error === ''): ?>
                <button type="button" class="button-link" onclick="window.print()">🖨️ طباعة الفاتورة</button>
            <?php endif; ?>
        </div>

        <?php if ($error !== ''): ?>
            <div class="empty-box"><?php echo e($error); ?></div>
        <?php else: ?>
            <div class="invoice-header">
                <div class="store-block">
                    <img src="<?php echo e($store['logo']); ?>" alt="شعار <?php echo e($store['name']); ?>" class="store-logo">
                    <div>
                        <h1><?php echo e($store['name']); ?></h1>
                        <p class="muted">فاتورة مورد قابلة للطباعة</p>
                    </div>
                </div>

                <div class="invoice-title-block">
                    <div>
                        <h2>فاتورة المورد رقم <?php echo (int) $selectedInvoice['id']; ?></h2>
                        <p class="muted">تاريخ ووقت الطباعة بتوقيت مصر: <?php echo e($printDateTime); ?></p>
                    </div>
                </div>
            </div>

            <div class="meta-grid">
                <div class="meta-box">
                    <p>اسم المورد</p>
                    <strong><?php echo e($selectedInvoice['supplier_name']); ?></strong>
                </div>
                <div class="meta-box">
                    <p>رقم تليفون المورد</p>
                    <strong><?php echo e($selectedInvoice['supplier_phone']); ?></strong>
                </div>
                <div class="meta-box">
                    <p>تاريخ حفظ الفاتورة</p>
                    <strong><?php echo e(formatDateTimeForDisplay($selectedInvoice['created_at'])); ?></strong>
                </div>
                <div class="meta-box">
                    <p>حالة سداد الفاتورة</p>
                    <strong><?php echo e($selectedInvoice['payment_status']); ?></strong>
                </div>
            </div>

            <h3 class="section-title">تفاصيل الأصناف</h3>
            <table>
                <thead>
                    <tr>
                        <th>اسم الصنف</th>
                        <th>العدد</th>
                        <th>سعر الوحدة</th>
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

            <div class="summary-grid">
                <div class="summary-box">
                    <p>إجمالي الفاتورة</p>
                    <strong><?php echo e(formatMoney($selectedInvoice['total_amount'])); ?> ج.م</strong>
                </div>
                <div class="summary-box">
                    <p>إجمالي المسدد</p>
                    <strong><?php echo e(formatMoney($selectedInvoice['amount_paid'])); ?> ج.م</strong>
                </div>
                <div class="summary-box">
                    <p>المتبقي على الفاتورة</p>
                    <strong><?php echo e(formatMoney($selectedInvoice['amount_due'])); ?> ج.م</strong>
                </div>
            </div>

            <h3 class="section-title">سجل التسديدات</h3>
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
                <div class="empty-box">لم يتم تسجيل أي تسديدات على هذه الفاتورة حتى الآن.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
