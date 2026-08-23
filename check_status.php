<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); 
header('Content-Type: application/json');

$db_host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$db_port = getenv('MYSQLPORT') ?: '3306';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: 'uGMtUbozFJJSnBszScvdokEShYJWoMDn';
$db_name = getenv('MYSQLDATABASE') ?: 'railway';

$transaction_id = isset($_GET['id']) ? trim($_GET['id']) : '';

if (empty($transaction_id)) {
    echo json_encode(['status' => 'error', 'voucherCode' => '']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $stmt = $pdo->prepare("SELECT status, voucher_code FROM vouchers WHERE transaction_id = :tx_id LIMIT 1");
    $stmt->execute(['tx_id' => $transaction_id]);
    $voucher = $stmt->fetch();

    if ($voucher) {
        // FORCE the outputs to match every possible spelling scenario the frontend might expect
        echo json_encode([
            'status'       => strtolower(trim($voucher['status'])), // returns "used"
            'voucherCode'  => $voucher['voucher_code'],            // matches JavaScript camelCase
            'vouchercode'  => $voucher['voucher_code']             // matches lowercase fallback
        ]);
    } else {
        echo json_encode(['status' => 'pending', 'voucherCode' => '']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'voucherCode' => '']);
}
?>
