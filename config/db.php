<?php
$host = "sql100.infinityfree.com";
$dbname = "if0_41476129_minasamystore";
$dbuser = "if0_41476129";
$dbpass = "CUueBsDrBhMBHW8";

$store = [
    'name' => 'Mina Samy',
    'subtitle' => 'نظام المبيعات الذكي',
    'logo' => 'assets/images/store-logo.svg',
];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
}
?>
