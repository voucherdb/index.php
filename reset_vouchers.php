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
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 2. COUNTER: Check how many vouchers are currently blocked/used before resetting
    $checkStmt = $pdo->query("SELECT COUNT(*) FROM vouchers WHERE status IN ('assigned', 'used') OR transaction_id IS NOT NULL");
    $total_blocked_before = $checkStmt->fetchColumn();

    // 3. EXECUTE FIXED ABSOLUTE RESET QUERY
    // This wipes all assigned transaction values character-for-character across the database
    $sql = "UPDATE vouchers 
            SET status = 'available', 
                assigned_at = NULL, 
                transaction_id = NULL, 
                customer_phone = NULL";
                
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    // 4. VERIFY: Query the database again to see if everything is clean
    $verifyStmt = $pdo->query("SELECT COUNT(*) FROM vouchers WHERE status = 'available'");
    $total_available_now = $verifyStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'Voucher database pool successfully restored to clean baseline!',
        'vouchers_freed_up' => (int)$total_blocked_before,
        'total_available_vouchers' => (int)$total_available_now,
        'system_status' => 'READY_FOR_NEW_PAYMENTS'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database critical reset routine halted!',
        'error' => $e->getMessage()
    ]);
}
?>
