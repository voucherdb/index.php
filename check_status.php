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

<?php
header('Content-Type: application/json');

// ... [Keep your database connection variables above here the same] ...

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $stmt = $pdo->prepare("SELECT status, voucher_code FROM vouchers WHERE transaction_id = :tx_id LIMIT 1");
    $stmt->execute(['tx_id' => $_GET['id']]);
    $voucher = $stmt->fetch();

    if ($voucher) {
        // FORCE the response to say "used" if the database table shows "used"
        echo json_encode([
            'status' => $voucher['status'], // This will return "used"
            'voucherCode' => $voucher['voucher_code']
        ]);
    } else {
        echo json_encode(['status' => 'pending', 'voucherCode' => '']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'voucherCode' => '']);
}
?>


