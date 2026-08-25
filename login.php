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
        $error_message = "Samahani, mtambo umeshindwa kuchakata vocha za TZS " . number_format($amount) . " kwa sasa. Tafadhali jaribu kifurushi kingine.";
    } else {
        // 1. Safely extract voucher values from array
        $voucher_id = $voucher['id']; 
        $voucher_code = $voucher['voucher_code'];
                $voucher_id = $voucher['id']; 
        $voucher_code = $voucher['voucher_code'];
        
        // Dynamic variable assignments for your success message
        $purchased_price = number_format($amount); // Formats 1000 to 1,000
        $purchased_duration = "Saa 1 (Hour 1)";    // Default fallback
        if ($amount == 500) {
            $purchased_duration = "Masaa 6";
        } elseif ($amount == 1000) {
            $purchased_duration = "Siku 1";
        } elseif ($amount == 2000) {
            $purchased_duration = "Siku 2";
        } elseif ($amount == 4000) {
            $purchased_duration = "Siku 5";
        } elseif ($amount == 5000) {
            $purchased_duration = "Siku 7";
        } elseif ($amount == 7000) {
            $purchased_duration = "Siku 10";
        } elseif ($amount == 9000) {
            $purchased_duration = "Siku 13";
         } elseif ($amount == 10000) {
            $purchased_duration = "Siku 15";
        } elseif ($amount == 20000) {
            $purchased_duration = "Siku 30";
        }

        // 2. Update status and log the customer phone number seamlessly
        $updateStmt = $pdo->prepare("UPDATE vouchers SET status = 'assigned', assigned_at = NOW(), transaction_id = :tx_id, customer_phone = :phone WHERE id = :id");
        $updateStmt->execute([
            'tx_id' => $internal_tx_id,
            'phone' => $phone,
            'id'    => $voucher_id
        ]);
        
        $pdo->commit();

        // ==========================================
        // STEP 2b: AZAMPAY PRODUCTION SETUP
        // ==========================================
        $clientId = '678beae1-7761-47fb-8111-858fb60d7ad3';
        $secretKey = 'VsZ0sQJpaxcWpkm5WtfmQNfjqwq0WqeQ/4qiFI044jmdSvq5ksVo3GWtT6yjQYVr4uqgn4X9hUdnrBaf3opZI/HdK2PzbxzBLlBf5xBhTY8WeyjPgnTWbEBkkIA+8Z3MBCItvm83FBLdv/hOBAwtRbnOSNfPSKxs3TgtTGo1xMBc/NqGWAsMRKgEH5m5v0mO9jxgRQzRezzSE4ibKDrRg1bswh7GWN6u7SfKvzyZN1ZnSJPC6iTcgDz4gzeoygb9nyOprJCfwe0fEJd9ohfVMhOG/FGyXsEcG2UKjoeH12p1+/LqjzCOUyR1aYWv4R8GdizIzghOTtZCmnOb35XuyRbQkwdEq6lbC5naP322gvE+pQ/MAhS1q5ZeS3FzIYmaZ1yrcT10mIUNasaCsa+1oMmF8E/zrRnNnVPymU9S5pzjzCK44uRQHqoSnn3E44agwMq9y1A6JnCVeRAYsoI64xzjThf9DFgafop8ToYcisKqIaxYclEgJMtYX/hrIaWKGBNV+WUX0kRFh/KTLYtpOvLUpui1KMIQNEYwQDBG8gcV+uieN1VxwA780QRj1zdZI8K9HWeqzPwxgmYyi2CGeYzuLdAzC4X84NanxCMOoHCO/IFwuYhPTMqSnjMEaRoPKcymxHk0KwHN9rnzC6UKaXleNuTOG/szi2qYAr2XImY=';
        $appName = 'Tanconnect';
        $apiKey  = "63bdee95-eba0-4eec-a5f0-0a8a12a715df";
        
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
            // Revert voucher if handshake fails
            $revertStmt = $pdo->prepare("UPDATE vouchers SET status = 'available', assigned_at = NULL, transaction_id = NULL, customer_phone = NULL WHERE id = :id");
            $revertStmt->execute(['id' => $voucher_id]);
            
            if ($httpStatusCode === 0) {
                // Determine the clean provider name string directly within PHP variables
                if ($provider === 'Mpesa') {
                    $provider_name = 'M-Pesa';
                } elseif ($provider === 'Tigo') {
                    $provider_name = 'Tigopesa';
                } elseif ($provider === 'Airtel') {
                    $provider_name = 'Airtel Money';
                } elseif ($provider === 'Halopesa') {
                    $provider_name = 'Halopesa';
                } else {
                    $provider_name = 'simu yako';
                }

                // Build the clean string with standard HTML tag concatenation operators
                $error_message = "Tumeshindwa kuwasiliana na" . $provider_name . "kuanzisha malipo. Tafadhali jaribu tena.";
            } else {
                $error_message = "Muamala umeshindikana au umekataliwa na mfumo. (HTTP Status Code: " . $httpStatusCode . ")";
            }
            $voucher_code = null;
        }
    } // Closes the outer 'else' block from the voucher check
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) { 
        $pdo->rollBack(); 
    }
    $error_message = "HITILAFU YA KIUFUNDI (Line " . $e->getLine() . "): " . $e->getMessage() . " katika faili " . basename($e->getFile());
    $voucher_code = null;
}
?>

<?php if ($error_message): ?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TANConnect - Uhaba wa Vifurushi</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f6f9; text-align: center; padding: 50px 20px; color: #2c3e50; margin: 0; display: flex; justify-content: center; align-items: center; min-height: 90vh; }
        .receipt-card { background: white; max-width: 450px; width: 100%; margin: 0 auto; padding: 40px 30px 30px 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); box-sizing: border-box; position: relative; }
        .btn-done { background: #3498db; color: white; border: none; padding: 14px; font-size: 14px; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; margin-top: 2px; width: 100%; box-sizing: border-box; font-weight: bold; text-transform: uppercase; transition: background 0.2s; }
        .btn-done:hover { filter: brightness(0.9); }
        .footer { font-family: 'Segoe UI', Arial, sans-serif; text-align: center; font-size: 11px; font-weight: bold; color: #1e3c72;}
    </style>
</head>
<body>
<div class="receipt-card">
    <span class="close-btn" onclick="closeThisWindow()" style="position: absolute; top: 12px; right: 18px; font-size: 26px; cursor: pointer; color: #7f8c8d; font-weight: bold; z-index: 110;">&times;</span>
    <img src="logo.png" alt="Water Point Logo" style="max-width: 250px; height: auto; object-fit: contain; margin-bottom: 1px;">
    <h3 style="color: #e74c3c; margin-top: 15px;">❌ Hitilafu ya Mtandao Imejitokeza! </h3>
    <p style="color: #57606f; line-height: 1.5; margin-bottom: 25px;"><?php echo htmlspecialchars($error_message); ?></p>
    <a href="index.php" class="btn-done" style="background: #e74c3c;">Jaribu</a>
    <br><br><div class="footer">"We bring the world at your finger tips" </div>
</div>
</body>
</html>
<?php elseif ($payment_triggered): ?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TANConnect - Malipo</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f6f9; text-align: center; padding: 50px 20px; color: #2c3e50; margin: 0; display: flex; justify-content: center; align-items: center; min-height: 90vh; }
        .receipt-card { background: white; max-width: 450px; width: 100%; margin: 0 auto; padding: 40px 30px 30px 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); box-sizing: border-box; position: relative; }
        .voucher-box { font-size: 28px; font-weight: bold; background: #eef2f7; padding: 15px; border-radius: 6px; letter-spacing: 2px; color: #0033a0; margin: 20px 0; border: 2px dashed #3498db; font-family: monospace; }
        .btn { display: inline-block; background: #3498db; color: white; padding: 12px 30px; font-size: 16px; font-weight: bold; border-radius: 6px; text-decoration: none; cursor: pointer; transition: background 0.2s; border: none; width: 100%; box-sizing: border-box; }
        .btn:hover { background: #2980b9; }
        .success-title { color: #2ecc71; margin-top: 0; }
        .footer { font-family: 'Segoe UI', Arial, sans-serif; text-align: center; font-size: 11px; font-weight: bold; color: #1e3c72;}
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #f39c12; border-radius: 50%; width: 45px; height: 45px; animation: spin 1s linear infinite; margin: 25px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="receipt-card">
    <span class="close-btn" onclick="closeThisWindow()" style="position: absolute; top: 12px; right: 18px; font-size: 26px; cursor: pointer; color: #7f8c8d; font-weight: bold; z-index: 110;">&times;</span>
    <img src="logo.png" alt="Water Point Logo" style="max-width: 250px; height: auto; object-fit: contain; margin-bottom: 1px;">

    <div id="payment-pending-view">
        <h2 style="color: blue; margin-top: 0;">Ombi La Malipo Umetumiwa</h2>
        <div class="spinner"></div>
        <div id="status-loading-container" style="background: #e8f4fd; border: 2px dashed #3498db; border-radius: 8px; padding: 14px; min-height: 55px; display: flex; align-items: center; justify-content: center; box-sizing: border-box;">
            <marquee hspace="-45" behavior="scroll" direction="left" style="font-size: 14px; font-weight: bold; color: #3498db;">
                Malipo yanafanyika kupitia mtandao wa AzamPay. &nbsp;&nbsp;&nbsp;||&nbsp;&nbsp;&nbsp; Voucher yako itajitokeza hapa utapoweka PIN kwenye simu yako. &nbsp;&nbsp;&nbsp;||&nbsp;&nbsp;&nbsp; Vilevile utapokea SMS yenye Voucher yako kutoka 0753 476 850.
            </marquee>
        </div>
    </div>
    <div id="payment-success-view" style="display: none;">
        <h2 class="success-title" style="color: #2ecc71; margin-top: 0;">✔ Malipo Yamekamilika!</h2> 
        
        <!-- UPDATED TEXT LAYER WITH DYNAMIC PHP VALUES -->
        <p style="color: black; font-weight: 500; margin-bottom: 20px; font-size: 11px; line-height: 1.6; text-align: justify; padding: 0 5px;">
          Umefanikiwa kununua kifurushi cha Tsh <b><?php echo $purchased_price; ?></b>, kitatumika kwa <b><?php echo $purchased_duration; ?></b>. Bonyeza NAKILI kuhifadhi voucher yako kisha fuata maelekezo.
        </p>
        
        <!-- FLEXBOX ROW CONTAINER -->
        <div style="display: flex; align-items: center; gap: 12px; margin: 20px 0; width: 100%; box-sizing: border-box;">
            
            <!-- VOUCHER BOX (Left Side) -->
            <div class="voucher-box" id="voucherCode" style="flex: 2; margin: 0; padding: 12px; font-size: 24px; display: flex; align-items: center; justify-content: center; height: 55px; box-sizing: border-box;">
                --------
            </div>
            
            <!-- NAKILI BUTTON (Right Side) -->
            <button class="btn" style="flex: 1; margin: 0; background: #2ecc71; height: 55px; font-size: 15px; text-transform: uppercase; white-space: nowrap; padding: 0 15px; display: flex; align-items: center; justify-content: center;" onclick="copyVoucher()">
                NAKILI
            </button>
            
        </div>
    </div>


<br><div class="footer">"We bring the world at your finger tips" </div>

</div> 
</body>
</html>
<?php endif; ?>

<script>
function closeThisWindow() {
    window.close();
    var hiddenExitLink = document.createElement('a');
    hiddenExitLink.href = "about:blank"; 
    hiddenExitLink.target = "_self";
    document.body.appendChild(hiddenExitLink);
    hiddenExitLink.click();
    if (!window.closed) {
        window.open('', '_self', '');
        window.close();
    }
}

// 1. Capture the exact dynamic ID token from your PHP file header engine
const transactionId = "<?php echo $internal_tx_id; ?>";
const paymentTriggered = <?php echo $payment_triggered ? 'true' : 'false'; ?>;
let pollInterval;

if (paymentTriggered) {
    window.onload = function() {
        // Run our background verification script every 3 seconds
        pollInterval = setInterval(checkLiveStatus, 3000); 
    };
}

function checkLiveStatus() {
    // 2. Fetch data via standard relative routing to avoid secure domain policy restrictions
    fetch(`check_status.php?id=${transactionId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP Error Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log("Tracking matrix sync check values:", data);

            // 3. Match the lowercase status keyword row update
            if (data.status === "used") {
                clearInterval(pollInterval); // Stop looping server connection checks immediately
                
                // 4. Inject the raw text code pin down into your card display container
                var codeDisplayBox = document.getElementById("voucherCode");
                if (codeDisplayBox) {
                    codeDisplayBox.innerText = data.voucherCode || data.vouchercode || "VOCHA_OK";
                }

                // 5. Hide the orange loader card view layout smoothly
                var pendingCardView = document.getElementById("payment-pending-view");
                if (pendingCardView) {
                    pendingCardView.style.display = "none";
                } else {
                    // Fallback: If your page elements have different names, hide the whole container
                    document.body.innerHTML = `<div style='background:white; padding:40px; border-radius:12px; text-align:center; max-width:400px; margin:50px auto; box-shadow:0 4px 12px rgba(0,0,0,0.1); font-family:sans-serif;'>
                        <h3 style='color:#2ecc71;'>✔ Malipo Yamekamilika!</h3>
                        <p>Voucher Code yako ni:</p>
                        <div style='font-size:26px; font-weight:bold; color:blue; padding:15px; background:#f0f4f8; border:2px dashed #3498db; margin:20px 0;'>${data.voucherCode || data.vouchercode}</div>
                        <button style='background:#2ecc71; color:white; border:none; padding:12px; width:100%; border-radius:6px; font-weight:bold; cursor:pointer;' onclick='window.location.href="https://5wifi.net"'>HODI</button>
                    </div>`;
                    return;
                }

                // 6. Reveal the green success panel container layout view
                var successCardView = document.getElementById("payment-success-view");
                if (successCardView) {
                    successCardView.style.display = "block";
                }
            }
        })
        .catch(err => console.error("Database connection error inside loop track engine:", err));
}

function copyVoucher() {
    var voucherText = document.getElementById("voucherCode").innerText;
    navigator.clipboard.writeText(voucherText).then(function() {
        alert("Voucher yako imenakiliwa! Bonyeza HODI kwenye ukurasa unaofuata, kisha ingiza voucher kuingia mtandaoni.");
        window.location.href = "https://www.5wifi.net";
    }, function() {
        window.location.href = "https://www.5wifi.net";
    });
}
</script>
