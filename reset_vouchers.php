<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// 1. DATABASE CONFIGURATION (Pulls from your Railway Environment Variables)
$db_host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$db_port = getenv('MYSQLPORT') ?: '3306';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: 'uGMtUbozFJJSnBszScvdokEShYJWoMDn';
$db_name = getenv('MYSQLDATABASE') ?: 'railway';

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 2. EXECUTE THE BULK RESET QUERY
    // This updates every single voucher row back to a fresh, available testing state
    $sql = "UPDATE vouchers 
            SET status = 'available', 
                assigned_at = NULL, 
                transaction_id = NULL, 
                customer_phone = NULL";
                
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    // Get the total number of rows that were reset
    $rowCount = $stmt->rowCount();

    echo json_encode([
        'success' => true,
        'message' => 'Database reset completely successful!',
        'vouchers_restored' => $rowCount,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database reset failed!',
        'error' => $e->getMessage()
    ]);
}
?>
