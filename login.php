<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. DATABASE CONFIGURATION (Pulls from your Railway Environment Variables)
$db_host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$db_port = getenv('MYSQLPORT') ?: '3306';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: 'uGMtUbozFJJSnBszScvdokEShYJWoMDn';
$db_name = getenv('MYSQLDATABASE') ?: 'railway';

// 2. CAPTURE DATA SENT FROM INDEX.PHP
$phone  = isset($_POST['customer_phone']) ? trim($_POST['customer_phone']) : '';
$amount = isset($_POST['amount']) ? trim($_POST['amount']) : '1000'; 

$amount = str_replace(',', '', $amount);

if (substr($phone, 0, 1) === '0') {
    $phone = '255' . substr($phone, 1);
}

$routingPrefix = substr($phone, 3, 2); 

if (in_array($routingPrefix, ['74', '75', '76', '14'])) {
    $provider = "Mpesa";
} elseif (in_array($routingPrefix, ['70', '71', '77', '65', '07', '67', '72'])) {
    $provider = "Tigo";
} elseif (in_array($routingPrefix, ['78', '79', '68', '69'])) {
    $provider = "Airtel";
} elseif (in_array($routingPrefix, ['62', '61'])) {
    $provider = "Halopesa";
} else {
    $provider = "Mpesa"; 
}

$error_message = null;
$voucher_code = null;
$internal_tx_id = "TAN-" . time() . "-" . rand(1000, 9999);
$payment_triggered = false;

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
        $error_message = "Samahani, vocha za TZS " . number_format($amount) . " zimeisha kwa sasa. Tafadhali chagua kifurushi kingine.";
    } else {
        $voucher_id = $voucher['id'];
        $voucher_code = $voucher['voucher_code'];
        
        $updateStmt = $pdo->prepare("UPDATE vouchers SET status = 'assigned', assigned_at = NOW(), transaction_id = :tx_id WHERE id = :id");
        $updateStmt->execute(['tx_id' => $internal_tx_id, 'id' => $voucher_id]);
        $pdo->commit();

        // AzamPay Credentials
        $clientId = '678beae1-7761-47fb-8111-858fb60d7ad3';
        $secretKey = 'VsZ0sQJpaxcWpkm5WtfmQNfjqwq0WqeQ/4qiFI044jmdSvq5ksVo3GWtT6yjQYVr4uqgn4X9hUdnrBaf3opZI/HdK2PzbxzBLlBf5xBhTY8WeyjPgnTWbEBkkIA+8Z3MBCItvm83FBLdv/hOBAwtRbnOSNfPSKxs3TgtTGo1xMBc/NqGWAsMRKgEH5m5v0mO9jxgRQzRezzSE4ibKDrRg1bswh7GWN6u7SfKvzyZN1ZnSJPC6iTcgDz4gzeoygb9nyOprJCfwe0fEJd9ohfVMhOG/FGyXsEcG2UKjoeH12p1+/LqjzCOUyR1aYWv4R8GdizIzghOTtZCmnOb35XuyRbQkwdEq6lbC5naP322gvE+pQ/MAhS1q5ZeS3FzIYmaZ1yrcT10mIUNasaCsa+1oMmF8E/zrRnNnVPymU9S5pzjzCK44uRQHqoSnn3E44agwMq9y1A6JnCVeRAYsoI64xzjThf9DFgafop8ToYcisKqIaxYclEgJMtYX/hrIaWKGBNV+WUX0kRFh/KTLYtpOvLUpui1KMIQNEYwQDBG8gcV+uieN1VxwA780QRj1zdZI8K9HWeqzPwxgmYyi2CGeYzuLdAzC4X84NanxCMOoHCO/IFwuYhPTMqSnjMEaRoPKcymxHk0KwHN9rnzC6UKaXleNuTOG/szi2qYAr2XImY=';
        $appName = 'Tanconnect';
        $apiKey  = "63bdee95-eba0-4eec-a5f0-0a8a12a715df";
       
        // ==========================================
        // STEP 2b: GENERATE AZAMPAY OAUTH BEARER TOKEN
        // ==========================================
       $authUrl = "https://authenticator-sandbox.azampay.co.tz/AppRegistration/GenerateToken";
        $authPayload = json_encode([
            'appname'      => $appName,
            'clientid'     => $clientId,
            'clientsecret' => $secretKey
        ]);

        $chAuth = curl_init($authUrl);
        curl_setopt($chAuth, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chAuth, CURLOPT_POST, true);
        curl_setopt($chAuth, CURLOPT_POSTFIELDS, $authPayload);
        curl_setopt($chAuth, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Accept: application/json"]);
        curl_setopt($chAuth, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($chAuth, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($chAuth, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($chAuth, CURLOPT_TIMEOUT, 30);
        curl_setopt($chAuth, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        
        $auth_response = curl_exec($chAuth);
        $auth_data = json_decode($auth_response, true);
        curl_close($chAuth);
        
        $access_token = null;
        if (isset($auth_data['data']['accessToken'])) { $access_token = $auth_data['data']['accessToken']; }
        elseif (isset($auth_data['token'])) { $access_token = $auth_data['token']; }
        elseif (isset($auth_data['accessToken'])) { $access_token = $auth_data['accessToken']; }
        
        if (!$access_token) {
            $revertStmt = $pdo->prepare("UPDATE vouchers SET status = 'available', assigned_at = NULL, transaction_id = NULL WHERE id = :id");
            $revertStmt->execute(['id' => $voucher_id]);
            throw new Exception("AzamPay Authentication Failed. Raw Sandbox Error: " . ($auth_response ?: 'No Server Response'));
        }

        // ==========================================
        // STEP 3: SEND PUSH TO AZAMPAY (SANDBOX)
        // ==========================================
        $checkout_url = "https://sandbox.azampay.co.tz/azampay/mno/checkout";

        $payloadArray = [
            "accountNumber"        => (string)$phone,
            "amount"               => (string)$amount,
            "currency"             => "TZS",
            "externalId"           => (string)$internal_tx_id,
            "provider"             => (string)$provider,
            "additionalProperties" => new stdClass()
        ];
        
        $checkoutPayload = json_encode($payloadArray);
        
        $chCheck = curl_init($checkout_url);
        curl_setopt($chCheck, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chCheck, CURLOPT_POST, true);
        curl_setopt($chCheck, CURLOPT_POSTFIELDS, $checkoutPayload);
        curl_setopt($chCheck, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Accept: application/json",
            "X-API-KEY: " . $apiKey,
            "Authorization: Bearer " . $access_token
        ]);
        
        curl_setopt($chCheck, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($chCheck, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($chCheck, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($chCheck, CURLOPT_TIMEOUT, 30);
        curl_setopt($chCheck, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        
        $checkoutResponse = curl_exec($chCheck);
        $httpStatusCode = curl_getinfo($chCheck, CURLINFO_HTTP_CODE);
        curl_close($chCheck);
        // ==========================================
        // STEP 4: EVALUATE RESULT
        // ==========================================
        if ($httpStatusCode == 200) {
            // Push was accepted! Keep status as 'assigned' until PIN trigger arrives
            $payment_triggered = true;
        } else {
            $revertStmt = $pdo->prepare("UPDATE vouchers SET status = 'available', assigned_at = NULL, transaction_id = NULL, customer_phone = NULL WHERE id = :id");
            $revertStmt->execute(['id' => $voucher_id]);
            
            if ($httpStatusCode === 0) {
                $error_message = "Tumeshindwa kuwasiliana na mtandao wako. Tafadhali jaribu tena.";
            } else {
                $error_message = "Muamala umeshindikana au umekataliwa na mfumo. (HTTP Status Code: " . $httpStatusCode . ")";
            }
            $voucher_code = null;
        }
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) { 
        $pdo->rollBack(); 
    }
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
        
        /* Spinner CSS component */
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #f39c12; border-radius: 50%; width: 45px; height: 45px; animation: spin 1s linear infinite; margin: 25px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="result-card">
                <img src="logo.png" style="max-width: 250px; height: auto; object-fit: contain; margin-bottom: 1px;">

    <?php if ($error_message): ?>
        <!-- ERROR DISPLAY -->
        <h3 class="error-title">❌ Hitilafu ya Mtandao Imejitokeza! </h3>
        <p style="color: #57606f; line-height: 1.5; margin-bottom: 25px;"><?php echo htmlspecialchars($error_message); ?></p>
        <button class="btn" onclick="window.history.back();">Rudi Nyuma</button>

    <?php elseif ($payment_triggered): ?>
        <!-- WAITING STATE (STK PUSH SMS FIRED) -->
        <div id="payment-pending-view">
            <h3 style="color: #f39c12; margin-top: 0;">Inasubiri Malipo...</h3>
            <div class="spinner"></div>
            <p style="color: #57606f; line-height: 1.6; font-size: 15px;">
                 <marquee behavior="scroll" direction="left" scrollamount="4" style="color: black; font-weight: bold; margin-bottom: 4px;font-family: 'Segoe UI', Arial, sans-serif;">
                Tafadhali ingiza PIN kwenye simu yako kuruhusu muamala kukamilika. Malipo yanafanyika kupitia mtandao wa AzamPay. Ukikamilisha malipo voucher yako itaonekana hapa. Vilevile utapokea sms yenye namba yako ya voucher kutoka nambari 0753 476 850 
                </marquee>
        </div>

        <!-- SUCCESS DISPLAY (HIDDEN UNTIL POLL DETECTS COMPLETED STATUS) -->
        <div id="payment-success-view" style="display: none;">
            <h3 class="success-title">✔ Malipo Yamefanikiwa! </h3> 
            <marquee behavior="scroll" direction="left" scrollamount="4" style="color: black; font-weight: bold; margin-bottom: 4px;font-family: 'Segoe UI', Arial, sans-serif;">
                   Muamala wako umekamilika, Tafadhali bonyeza NAKILI kuhifadhi voucher yako, kisha bonyeza (HODI) ili kuunganishwa na mtandao wa TANConnect Wi-Fi. 
                </marquee>
            
            <div class="voucher-box" id="voucherCode">---------</div>
            
            <button class="btn" style="margin-bottom: 10px; background: #2ecc71;" onclick="copyVoucher()">NAKILI</button>
            <button class="btn" style="background: #747d8c;" onclick="window.history.back();">FUNGA</button>
        </div>
    <?php endif; ?>
</div>

<script>
const transactionId = "<?php echo $internal_tx_id; ?>";
const paymentTriggered = <?php echo $payment_triggered ? 'true' : 'false'; ?>;
let pollInterval;

if (paymentTriggered) {
    window.onload = function() {
        pollInterval = setInterval(checkLiveStatus, 3000); // Check status every 3 seconds
    };
}

function checkLiveStatus() {
    fetch(`check_status.php?id=${transactionId}`)
        .then(response => response.json())
        .then(data => {
            // Once the status changes to 'used' via your fake_trigger.php/webhook simulation
            if (data.status === "used") {
                clearInterval(pollInterval);
                
                document.getElementById("voucherCode").innerText = data.voucherCode;
                document.getElementById("payment-pending-view").style.display = "none";
                document.getElementById("payment-success-view").style.display = "block";
            }
        })
        .catch(err => console.error("Error communicating with status API:", err));
}

function copyVoucher() {
    var voucherText = document.getElementById("voucherCode").innerText;
    navigator.clipboard.writeText(voucherText).then(function() {
        alert("Voucher yako imenakiliwa! Sasa unapelekwa kwenye mtandao wetu...");
        window.location.href = "https://www.5wifi.net";
    }, function() {
        // Redirection fallback if clipboard access is blocked
        window.location.href = "https://www.5wifi.net";
    });
}
</script>

</body>
</html>
