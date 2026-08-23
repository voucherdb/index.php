<?php
/**
 * Test Utility: fake_trigger.php
 * Use this to simulate a customer successfully typing their PIN on their phone
 * via AzamPay mobile push SMS.
 */

// 1. SETTINGS: Change this to match your Railway app domain or local setup
$railway_webhook_url = "https://railway.app"; 
$test_transaction_id = "TXN_998231414"; // Must match the transaction ID your frontend is polling

// 2. MOCK AZAMPAY PAYLOAD (The exact structure AzamPay sends when a PIN is entered)
$mock_payload = [
    "transactionId" => $test_transaction_id,
    "status"        => "COMPLETED",
    "message"       => "Payment successful via Mobile Push STK",
    "operator"      => "M-Pesa", // Can be TigoPesa, AirtelMoney, etc.
    "amount"        => "1000",
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
