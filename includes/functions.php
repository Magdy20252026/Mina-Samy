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
        ['icon' => '📦', 'label' => 'الأصناف', 'aria_label' => 'قسم الأصناف', 'href' => 'inventory.php?tab=issued#issued-items'],
        ['icon' => '🛒', 'label' => 'المبيعات', 'aria_label' => 'قسم المبيعات'],
        ['icon' => '🤝', 'label' => 'عملاء', 'aria_label' => 'قسم العملاء'],
        ['icon' => '💸', 'label' => 'مصروفات', 'aria_label' => 'قسم المصروفات'],
        ['icon' => '👔', 'label' => 'موظفين', 'aria_label' => 'قسم الموظفين'],
        ['icon' => '💵', 'label' => 'قبض موظفين', 'aria_label' => 'قسم قبض الموظفين'],
        ['icon' => '📊', 'label' => 'إحصائيات', 'aria_label' => 'قسم الإحصائيات'],
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
?>
