<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. DATABASE CONFIGURATION (Pulls from your Railway Environment Variables)
$db_host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$db_name = getenv('MYSQLDATABASE') ?: 'railway';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: 'uGMtUbozFJJSnBszScvdokEShYJWoMDn';
$db_port = getenv('MYSQLPORT') ?: '3306';

// AzamPay Credentials (Set these up in your Railway Variables Dashboard tab)
$azampay_client_id = getenv('AZAMPAY_CLIENT_ID') ?: '678beae1-7761-47fb-8111-858fb60d7ad3';
$azampay_secret_key = getenv('AZAMPAY_SECRET_KEY') ?: 'VsZ0sQJpaxcWpkm5WtfmQNfjqwq0WqeQ/4qiFI044jmdSvq5ksVo3GWtT6yjQYVr4uqgn4X9hUdnrBaf3opZI/HdK2PzbxzBLlBf5xBhTY8WeyjPgnTWbEBkkIA+8Z3MBCItvm83FBLdv/hOBAwtRbnOSNfPSKxs3TgtTGo1xMBc/NqGWAsMRKgEH5m5v0mO9jxgRQzRezzSE4ibKDrRg1bswh7GWN6u7SfKvzyZN1ZnSJPC6iTcgDz4gzeoygb9nyOprJCfwe0fEJd9ohfVMhOG/FGyXsEcG2UKjoeH12p1+/LqjzCOUyR1aYWv4R8GdizIzghOTtZCmnOb35XuyRbQkwdEq6lbC5naP322gvE+pQ/MAhS1q5ZeS3FzIYmaZ1yrcT10mIUNasaCsa+1oMmF8E/zrRnNnVPymU9S5pzjzCK44uRQHqoSnn3E44agwMq9y1A6JnCVeRAYsoI64xzjThf9DFgafop8ToYcisKqIaxYclEgJMtYX/hrIaWKGBNV+WUX0kRFh/KTLYtpOvLUpui1KMIQNEYwQDBG8gcV+uieN1VxwA780QRj1zdZI8K9HWeqzPwxgmYyi2CGeYzuLdAzC4X84NanxCMOoHCO/IFwuYhPTMqSnjMEaRoPKcymxHk0KwHN9rnzC6UKaXleNuTOG/szi2qYAr2XImY=';
$azampay_app_name = getenv('AZAMPAY_APP_NAME') ?: 'Tanconnect';

// 2. CAPTURE DATA SENT FROM INDEX.PHP
$phone = isset($_POST['customer_phone']) ? trim($_POST['customer_phone']) : '';
$amount = isset($_POST['amount']) ? intval($_POST['amount']) : 0;

$cleanDigits = preg_replace('/[^0-9]/', '', $phone);
if (strpos($cleanDigits, '0') === 0) {
    $cleanDigits = '255' . substr($cleanDigits, 1);
}

$error_message = null;
$voucher_code = null;
$internal_tx_id = "TAN-" . time() . "-" . rand(1000, 9999);

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // ==========================================
    // STEPS 1 & 2: DATABASE CHECK & RESERVATION
    // ==========================================
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("SELECT id, voucher_code FROM vouchers WHERE price_tier = :amount AND status = 'available' LIMIT 1 FOR UPDATE");
    $stmt->execute(['amount' => $amount]);
    $voucher = $stmt->fetch();
    
    if (!$voucher) {
        $pdo->rollBack();
        $error_message = "Samahani, vocha za kiasi cha TZS " . number_format($amount) . " zimeisha kwa sasa. Tafadhali chagua kifurushi kingine.";
    } else {
        $voucher_id = $voucher['id'];
        $voucher_code = $voucher['voucher_code'];
        
        $updateStmt = $pdo->prepare("UPDATE vouchers SET status = 'assigned', assigned_at = NOW(), transaction_id = :tx_id WHERE id = :id");
        $updateStmt->execute(['tx_id' => $internal_tx_id, 'id' => $voucher_id]);
        $pdo->commit();
        
              // ==========================================
        // STEP 2b: GENERATE AZAMPAY OAUTH BEARER TOKEN (SANDBOX AUTO-CASING)
        // ==========================================
       $authUrl = "https://authenticator-sandbox.azampay.co.tz/AppRegistration/GenerateToken";
$authPayload = json_encode([
    'appname'      => $appName,
    'clientid'     => $clientId,
    'clientsecret' => $secretKey
]);

        
        $ch_auth = curl_init($auth_url);
        curl_setopt($ch_auth, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_auth, CURLOPT_POST, true);
        curl_setopt($ch_auth, CURLOPT_POSTFIELDS, json_encode($auth_payload));
        curl_setopt($ch_auth, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        
        $auth_response = curl_exec($ch_auth);
        $auth_data = json_decode($auth_response, true);
        curl_close($ch_auth);
        
        $access_token = null;
        if (isset($auth_data['data']['accessToken'])) { $access_token = $auth_data['data']['accessToken']; }
        elseif (isset($auth_data['token'])) { $access_token = $auth_data['token']; }
        elseif (isset($auth_data['accessToken'])) { $access_token = $auth_data['accessToken']; }
        
        if (!$access_token) {
            // Safety rollback: reset voucher if handshake fails
            $revertStmt = $pdo->prepare("UPDATE vouchers SET status = 'available', assigned_at = NULL, transaction_id = NULL, customer_phone = NULL WHERE id = :id");
            $revertStmt->execute(['id' => $voucher_id]);
            
            // Print the raw sandbox text to expose exactly why it is failing
            throw new Exception("AzamPay Authentication Failed. Raw Sandbox Error: " . ($auth_response ?: 'No Server Response'));
        }

        
        // ==========================================
        // STEP 3: SEND PUSH TO AZAMPAY (SANDBOX)
        // ==========================================
        $checkout_url = "https://sandbox.azampay.co.tz/azampay/mno/checkout";
        $provider = "AzamPay";
        $prefix_3 = substr($cleanDigits, 3, 2);
        if (in_array($prefix_3, ['74', '75', '76', '14'])) { $provider = "Mpesa"; }
        elseif (in_array($prefix_3, ['71', '77', '65', '07', '67', '72', '70'])) { $provider = "Tigopesa"; }
        elseif (in_array($prefix_3, ['78', '79', '68', '69'])) { $provider = "Airtel"; }
        elseif (in_array($prefix_3, ['62', '61'])) { $provider = "Halopesa"; }
        
        // Crucial Sandbox note: Ensure your amount key parses string properties cleanly
        $payload = [
            "accountNumber" => $cleanDigits,
            "amount" => (string)$amount,
            "currency" => "TZS",
            "externalId" => $internal_tx_id,
            "provider" => $provider,
            "additionalProperties" => new stdClass()
        ];
        
        $ch = curl_init($checkout_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $access_token,
            "X-Client-Id: " . $azampay_client_id
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $resData = json_decode($response, true);
        
        // ==========================================
        // STEP 4: EVALUATE RESULT
        // ==========================================
        if ($http_code == 200 && isset($resData['success']) && ($resData['success'] == true || $resData['success'] === "true")) {
            $finalStmt = $pdo->prepare("UPDATE vouchers SET status = 'used' WHERE id = :id");
            $finalStmt->execute(['id' => $voucher_id]);
        } else {
            $revertStmt = $pdo->prepare("UPDATE vouchers SET status = 'available', assigned_at = NULL, transaction_id = NULL WHERE id = :id");
            $revertStmt->execute(['id' => $voucher_id]);
            $error_message = "Muamala umeshindwa kufanyika. Tafadhali jaribu tena.";
            $voucher_code = null;
        }
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) { 
        $pdo->rollBack(); 
    }
    // TEMPORARY DIAGNOSTIC MODE: Prints the exact line and error cause directly to the screen
    $error_message = "HITILAFU YA KIUFUNDI (Line " . $e->getLine() . "): " . $e->getMessage() . " katika faili " . basename($e->getFile());
    $voucher_code = null;
}

?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TANConnect - Malipo</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f1f2f6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .result-card { background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.15); max-width: 450px; width: 100%; text-align: center; }
        .voucher-box { font-size: 28px; font-weight: bold; background: #eef2f7; padding: 15px; border-radius: 6px; letter-spacing: 2px; color: #0033a0; margin: 20px 0; border: 2px dashed #3498db; font-family: monospace; }
        .btn { display: inline-block; background: #3498db; color: white; padding: 12px 30px; font-size: 16px; font-weight: bold; border-radius: 6px; text-decoration: none; cursor: pointer; transition: background 0.2s; border: none; width: 100%; box-sizing: border-box; }
        .btn:hover { background: #2980b9; }
        .error-title { color: #e74c3c; margin-top: 0; }
        .success-title { color: #2ecc71; margin-top: 0; }
    </style>
</head>
<body>

<div class="result-card">
    <?php if ($error_message): ?>
        <!-- ERROR DISPLAY -->
        <h3 class="error-title">Hitilafu ya Mtandao Imejitokeza! ❌</h3>
        <p style="color: #57606f; line-height: 1.5; margin-bottom: 25px;"><?php echo htmlspecialchars($error_message); ?></p>
        <button class="btn" onclick="window.history.back();">Rudi Nyuma</button>

    <?php else: ?>
        <!-- SUCCESS DISPLAY -->
        <h3 class="success-title">Malipo Yamefanikiwa! ✔</h3>
        <p style="color: #57606f; line-height: 1.5; font-size: 15px;">
            Muamala wako umekamilika. Tafadhali <b>nakili (copy)</b> namba hii ya vocha hapa chini, kisha utabonyeza (HODI) ili kuunganishwa na mtandao wa TANConnect.
        </p>
        
        <div class="voucher-box" id="voucherCode"><?php echo htmlspecialchars($voucher_code); ?></div>
        
        <button class="btn" style="margin-bottom: 10px; background: #2ecc71;" onclick="copyVoucher()">Nakili Vocha (Copy)</button>
        <button class="btn" style="background: #747d8c;" onclick="window.history.back();">Funga Ukurasa</button>
    <?php endif; ?>
</div>

<script>
function copyVoucher() {
    var voucherText = document.getElementById("voucherCode").innerText;
    navigator.clipboard.writeText(voucherText).then(function() {
        alert("Vocha imenakiliwa kikamilifu!");
    }, function() {
        alert("Imeshindwa kunakili kiotomatiki. Tafadhali nakili kwa mkono.");
    });
}
</script>

</body>
</html>
