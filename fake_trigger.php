<?php
/**
 * Test Utility: fake_trigger.php
 * Use this to simulate a customer successfully typing their PIN on their phone
 * via AzamPay mobile push SMS.
 */

// 1. SETTINGS: Change this to match your Railway app domain or local setup

$railway_webhook_url = "https://wi-fi-voucher-project-production.up.railway.app/callback.php";  
// 1b. DYNAMIC DATABASE LOOKUP (Finds the newest pending transaction automatically)
$db_host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$db_port = getenv('MYSQLPORT') ?: '3306';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: 'uGMtUbozFJJSnBszScvdokEShYJWoMDn';
$db_name = getenv('MYSQLDATABASE') ?: 'railway';

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Query your table for the latest transaction stuck on the "Subiri Malipo" spinner state
    $stmt = $pdo->query("SELECT transaction_id FROM vouchers WHERE status = 'assigned' ORDER BY assigned_at DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['transaction_id'])) {
        die("<b style='color:red;'>❌ Test Error:</b> No pending 'assigned' vouchers found in the database. Go to your portal and initiate a purchase transaction first!");
    }

    // Assign the matching dynamic transaction ID automatically!
    $test_transaction_id = $row['transaction_id'];

} catch (Exception $e) {
    die("<b style='color:red;'>Database Connection Error inside trigger script:</b> " . $e->getMessage());
}

// 2. MOCK AZAMPAY PAYLOAD (The exact structure AzamPay sends when a PIN is entered)
$mock_payload = [
    "transactionId" => $test_transaction_id,
    "status"        => "COMPLETED",
    "message"       => "Payment successful via Mobile Push STK",
    "operator"      => "Tigo", // Can be TigoPesa, AirtelMoney, etc.
    "amount"        => "500",
    "timestamp"     => date('Y-m-d H:i:s')
];

// 3. INITIALIZE HTTP POST REQUEST (Using PHP cURL)
$ch = curl_init($railway_webhook_url);

// Convert our PHP array into JSON format
$json_data = json_encode($mock_payload);

// Set cURL options to send a POST request with JSON headers
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($json_data)
]);

// 4. EXECUTE THE TRIGGER
echo "<h3>🚀 Simulating AzamPay PIN Input...</h3>";
echo "Sending fake completion hook to: <code>" . htmlspecialchars($railway_webhook_url) . "</code><br><br>";

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo "<b style='color:red;'>❌ Connection Error:</b> " . curl_error($ch);
} else {
    echo "<b style='color:green;'>✓ Trigger Fired Successfully!</b><br>";
    echo "Server HTTP Response Code: <b>" . $http_code . "</b><br>";
    echo "Server Response Body: <pre>" . htmlspecialchars($response) . "</pre>";
}

curl_close($ch);
?>
