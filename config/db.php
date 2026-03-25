<?php
$host = "sql100.infinityfree.com";
$dbname = "if0_41476129_minasamystore";
$dbuser = "if0_41476129";
$dbpass = "CUueBsDrBhMBHW8";

$defaultStore = [
    'name' => 'Mina Samy',
    'subtitle' => 'نظام المبيعات الذكي',
    'logo' => 'assets/images/store-logo.svg',
];

$store = $defaultStore;
$storeSettingsFile = __DIR__ . '/store.json';

if (is_file($storeSettingsFile)) {
    $storedSettingsJson = file_get_contents($storeSettingsFile);

    if ($storedSettingsJson !== false) {
        $storedSettings = json_decode($storedSettingsJson, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($storedSettings)) {
            if (isset($storedSettings['name']) && is_string($storedSettings['name']) && trim($storedSettings['name']) !== '') {
                $store['name'] = trim($storedSettings['name']);
            }

            if (isset($storedSettings['subtitle']) && is_string($storedSettings['subtitle']) && trim($storedSettings['subtitle']) !== '') {
                $store['subtitle'] = trim($storedSettings['subtitle']);
            }

            if (isset($storedSettings['logo']) && is_string($storedSettings['logo'])) {
                $store['logo'] = trim($storedSettings['logo']);
            }
        }
    }
}

$defaultLogo = 'assets/images/store-logo.svg';
$storeLogo = is_string($store['logo'] ?? null) ? str_replace('\\', '/', $store['logo']) : '';
$logoParts = array_values(array_filter(explode('/', $storeLogo), 'strlen'));
$isValidLogo = count($logoParts) >= 3
    && $logoParts[0] === 'assets'
    && $logoParts[1] === 'images';

foreach ($logoParts as $part) {
    if ($part === '.' || $part === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $part)) {
        $isValidLogo = false;
        break;
    }
}

$storeLogo = implode('/', $logoParts);

if (!$isValidLogo || !is_file(__DIR__ . '/../' . $storeLogo)) {
    $storeLogo = $defaultLogo;
}

$store['logo'] = $storeLogo;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
}
?>
