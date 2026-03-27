<?php

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function redirectIfNotLoggedIn()
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

function e($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function getSidebarSections()
{
    return [
        ['icon' => '🚚', 'label' => 'الموردين', 'aria_label' => 'قسم الموردين', 'href' => 'suppliers.php'],
        ['icon' => '🏬', 'label' => 'المخزن', 'aria_label' => 'قسم المخزن', 'href' => 'inventory.php'],
        ['icon' => '📦', 'label' => 'الأصناف', 'aria_label' => 'قسم الأصناف', 'href' => 'categories.php'],
        ['icon' => '🛒', 'label' => 'المبيعات', 'aria_label' => 'قسم المبيعات', 'href' => 'customer_invoice_create.php'],
        ['icon' => '🤝', 'label' => 'عملاء', 'aria_label' => 'قسم العملاء', 'href' => 'customers.php'],
        ['icon' => '💸', 'label' => 'مصروفات', 'aria_label' => 'قسم المصروفات', 'href' => 'expenses.php'],
        ['icon' => '👔', 'label' => 'موظفين', 'aria_label' => 'قسم الموظفين', 'href' => 'employees.php'],
        ['icon' => '💵', 'label' => 'قبض موظفين', 'aria_label' => 'قسم قبض الموظفين', 'href' => 'employee_payments.php'],
        ['icon' => '📊', 'label' => 'إحصائيات', 'aria_label' => 'قسم الإحصائيات', 'href' => 'statistics.php'],
    ];
}

function renderSidebarSections($activeLabel = '')
{
    $html = '';

    foreach (getSidebarSections() as $section) {
        $icon = e($section['icon'] ?? '');
        $label = e($section['label'] ?? '');
        $ariaLabel = e($section['aria_label'] ?? '');
        $href = trim((string) ($section['href'] ?? ''));
        $isActive = $activeLabel !== '' && ($section['label'] ?? '') === $activeLabel;

        if ($href !== '') {
            $html .= '<a href="' . e($href) . '"' . ($isActive ? ' class="active"' : '') . '>';
            $html .= '<span class="nav-icon" aria-hidden="true">' . $icon . '</span>';
            $html .= '<span class="nav-label">' . $label . '</span>';
            $html .= '</a>';
            continue;
        }

        $html .= '<button type="button" aria-label="' . $ariaLabel . '" disabled>';
        $html .= '<span class="nav-icon" aria-hidden="true">' . $icon . '</span>';
        $html .= '<span class="nav-label">' . $label . '</span>';
        $html .= '</button>';
    }

    return $html;
}

function getEgyptDateTimeValue()
{
    $date = new DateTime('now', new DateTimeZone('Africa/Cairo'));

    return $date->format('Y-m-d H:i:s');
}

function formatDateTimeForDisplay($value)
{
    if (!is_string($value) || trim($value) === '') {
        return '-';
    }

    try {
        $date = new DateTime($value, new DateTimeZone('Africa/Cairo'));

        return $date->format('Y-m-d h:i A');
    } catch (Exception $exception) {
        return $value;
    }
}

function formatMoney($value)
{
    return number_format((float) $value, 2);
}

function getStatisticsPeriodRange($period, $anchorDate = '')
{
    $allowedPeriods = ['daily', 'weekly', 'monthly'];
    $period = in_array($period, $allowedPeriods, true) ? $period : 'monthly';
    $timezone = new DateTimeZone('Africa/Cairo');

    try {
        if (is_string($anchorDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($anchorDate))) {
            $anchor = new DateTimeImmutable(trim($anchorDate) . ' 00:00:00', $timezone);
        } else {
            $anchor = new DateTimeImmutable('now', $timezone);
        }
    } catch (Exception $exception) {
        $anchor = new DateTimeImmutable('now', $timezone);
    }

    if ($period === 'daily') {
        $start = $anchor->setTime(0, 0, 0);
        $end = $anchor->setTime(23, 59, 59);
        $previousAnchor = $anchor->modify('-1 day');
        $nextAnchor = $anchor->modify('+1 day');
        $label = 'يومي';
    } elseif ($period === 'weekly') {
        $dayOfWeek = (int) $anchor->format('w');
        $daysFromSaturday = ($dayOfWeek + 1) % 7;
        $start = $anchor->modify('-' . $daysFromSaturday . ' days')->setTime(0, 0, 0);
        $end = $start->modify('+6 days')->setTime(23, 59, 59);
        $previousAnchor = $anchor->modify('-7 days');
        $nextAnchor = $anchor->modify('+7 days');
        $label = 'أسبوعي';
    } else {
        $start = $anchor->modify('first day of this month')->setTime(0, 0, 0);
        $end = $anchor->modify('last day of this month')->setTime(23, 59, 59);
        $previousAnchor = $anchor->modify('-1 month');
        $nextAnchor = $anchor->modify('+1 month');
        $label = 'شهري';
    }

    $current = new DateTimeImmutable('now', $timezone);

    return [
        'period' => $period,
        'label' => $label,
        'anchor_date' => $anchor->format('Y-m-d'),
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
        'previous_anchor_date' => $previousAnchor->format('Y-m-d'),
        'next_anchor_date' => $nextAnchor->format('Y-m-d'),
        'is_current_period' => $current >= $start && $current <= $end,
    ];
}

function formatStatisticsPeriodLabel(array $range)
{
    $startDate = trim((string) ($range['start_date'] ?? ''));
    $endDate = trim((string) ($range['end_date'] ?? ''));
    $label = trim((string) ($range['label'] ?? ''));

    if ($startDate === '' || $endDate === '') {
        return $label !== '' ? $label : 'الفترة المحددة';
    }

    if ($startDate === $endDate) {
        return ($label !== '' ? $label . ' - ' : '') . $startDate;
    }

    return ($label !== '' ? $label . ' - ' : '') . $startDate . ' إلى ' . $endDate;
}
?>
