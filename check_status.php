<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// 1. DATABASE CONFIGURATION (Matches your exact Login.php credentials)
$db_host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$db_port = getenv('MYSQLPORT') ?: '3306';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: 'uGMtUbozFJJSnBszScvdokEShYJWoMDn';
$db_name = getenv('MYSQLDATABASE') ?: 'railway';

// 2. CAPTURE THE INTERNAL TRANSACTION ID FROM THE JAVASCRIPT FETCH REQUEST
$transaction_id = isset($_GET['id']) ? trim($_GET['id']) : '';

if (empty($transaction_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing transaction ID']);
    exit;
}

try {
    // 3. ESTABLISH SECURE DATABASE CONNECTION
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 4. QUERY THE VOUCHERS TABLE
    // We check if the voucher transaction_id matches, and see if the status changed to 'used'
    $stmt = $pdo->prepare("SELECT status, voucher_code FROM vouchers WHERE transaction_id = :tx_id LIMIT 1");
    $stmt->execute(['tx_id' => $transaction_id]);
    $voucher = $stmt->fetch();

    if ($voucher) {
        // 5. SEND THE RESPONSE BACK TO JAVASCRIPT
        // If status is 'used', JavaScript reveals the success screen and voucherCode text block
        echo json_encode([
            'status' => $voucher['status'], // Will return 'assigned' or 'used'
            'voucherCode' => $voucher['voucher_code']
        ]);
    } else {
        echo json_encode(['status' => 'pending', 'message' => 'Transaction tracking record not found yet']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>

