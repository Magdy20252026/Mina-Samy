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
        ['icon' => '🚚', 'label' => 'الموردين', 'aria_label' => 'قسم الموردين'],
        ['icon' => '🏬', 'label' => 'المخزن', 'aria_label' => 'قسم المخزن'],
        ['icon' => '📦', 'label' => 'الأصناف', 'aria_label' => 'قسم الأصناف'],
        ['icon' => '🛒', 'label' => 'المبيعات', 'aria_label' => 'قسم المبيعات'],
        ['icon' => '🤝', 'label' => 'عملاء', 'aria_label' => 'قسم العملاء'],
        ['icon' => '💸', 'label' => 'مصروفات', 'aria_label' => 'قسم المصروفات'],
        ['icon' => '👔', 'label' => 'موظفين', 'aria_label' => 'قسم الموظفين'],
        ['icon' => '💵', 'label' => 'قبض موظفين', 'aria_label' => 'قسم قبض الموظفين'],
        ['icon' => '📊', 'label' => 'إحصائيات', 'aria_label' => 'قسم الإحصائيات'],
    ];
}
?>
