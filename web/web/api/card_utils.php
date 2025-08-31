<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$number = $_GET['number'] ?? '';
$number = preg_replace('/\D+/', '', $number);

if ($action === 'luhn') {
    $ok = luhnCheck($number);
    echo json_encode(['success' => true, 'data' => ['valid' => $ok]]);
    exit;
}

if ($action === 'brand') {
    $brand = detectCardBrand($number);
    echo json_encode(['success' => true, 'data' => ['brand' => $brand]]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unsupported action']);
?>

