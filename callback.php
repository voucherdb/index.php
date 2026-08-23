<?php
// Set headers to receive and communicate using clean JSON data strings
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Keep errors out of raw production API delivery

// 1. DATABASE CONFIGURATION (Pulls from your Railway Dashboard)
$db_host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$db_port = getenv('MYSQLPORT') ?: '3306';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: 'uGMtUbozFJJSnBszScvdokEShYJWoMDn';
$db_name = getenv('MYSQLDATABASE') ?: 'railway';

try {
    // Establish secure PDO database connection matrix
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    // 2. READ THE INCOMING PUSH DATA FROM THE TRIGGER SCRIPT
    $input_json = file_get_contents('php://input');
    $data = json_decode($input_json, true);

    // Capture transactionId and status from the received payload
    $transaction_id = isset($data['transactionId']) ? trim($data['transactionId']) : '';
    $status         = isset($data['status']) ? trim($data['status']) : '';

    // Validate that we received a real transaction ID and a COMPLETED status
    if (empty($transaction_id) || $status !== 'COMPLETED') {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing data values']);
        exit;
    }

    // 3. SECURELY UPDATE THE RECORD TO 'used' SO THE SPINNER CLEARS
    $stmt = $pdo->prepare("UPDATE vouchers SET status = 'used' WHERE transaction_id = :tx_id");
    $stmt->execute(['tx_id' => $transaction_id]);

    // Send a clean text response back to the trigger script (No HTML)
    echo json_encode([
        'success' => true, 
        'message' => 'Voucher status updated to used successfully'
    ]);

} catch (Exception $e) {
    // Return any database errors as clean JSON strings
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
