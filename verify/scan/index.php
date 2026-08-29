<?php
// Set timezone
date_default_timezone_set('Asia/Dhaka');

// Check if data is present in GET query params or if a QR code verification was triggered
// Examples:
// 1. Direct scan with query params: /verify/scan/?card_number=...&name=...
// 2. Scan with ccv token or qr_data: /verify/scan/?data=...
// 3. Normal camera scanner view if no verified data passed
$hasVerificationResult = false;

// Authentic CitizenCard Token pattern
$VALID_QR_TOKEN = 'https://ccv.ai?1:19559110:MEQCIDNti1rN6A65AiCeH15x4UBcKHAI_YurAX6LV6SzOUcsAiBVeC5o9CiQwTwx8ISAlE5nQ7Qcn1iEXuqWaESLMcaGuA';

// Check parameters
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$dataParam = isset($_GET['data']) ? trim($_GET['data']) : '';
$cardNumber = isset($_GET['card_number']) ? trim($_GET['card_number']) : '';
$name = isset($_GET['name']) ? trim($_GET['name']) : '';
$ageText = isset($_GET['age']) ? trim($_GET['age']) : 'Current Age: 18+';
$photoUrl = isset($_GET['photo']) ? trim($_GET['photo']) : '/verify-citizencard/photo.jpg';

// Function to dynamically verify CitizenCard QR code online & extract dynamic photo and age
function verifyCitizenCardOnline($qrData) {
    try {
        $tokenUrl = 'https://verify.citizencard.com/verify/scan';
        
        // 1. Fetch CSRF token & cookies
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
                'timeout' => 5
            ]
        ]);
        
        $html1 = @file_get_contents($tokenUrl, false, $context);
        if (!$html1) return null;
        
        if (preg_match('/name="scan_verify_form\[_token\]"\s+value="([^"]+)"/', $html1, $m)) {
            $csrfToken = $m[1];
        } else {
            return null;
        }

        // Get Cookies from response header
        $cookies = [];
        if (isset($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (stripos($hdr, 'Set-Cookie:') === 0) {
                    $cookies[] = substr($hdr, 12);
                }
            }
        }
        $cookieHeader = implode('; ', $cookies);

        // 2. POST the scanned QR data
        $postData = http_build_query([
            'scan_verify_form[data]' => $qrData,
            'scan_verify_form[_token]' => $csrfToken
        ]);

        $postContext = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n" .
                            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n" .
                            (!empty($cookieHeader) ? "Cookie: $cookieHeader\r\n" : "") .
                            "Content-Length: " . strlen($postData) . "\r\n",
                'content' => $postData,
                'timeout' => 8
            ]
        ]);

        $postResultHtml = @file_get_contents($tokenUrl, false, $postContext);
        if (!$postResultHtml) return null;

        $extracted = [];

        // Extract dynamic citizen image URL (e.g. /image/01a04b4b-...)
        if (preg_match('/src="(\/image\/[a-zA-Z0-9\-]+)"/', $postResultHtml, $imgMatches)) {
            $extracted['photo'] = 'https://verify.citizencard.com' . $imgMatches[1];
        }

        // Extract dynamic age (e.g. Current Age: 18+)
        if (preg_match('/(Current Age:\s*[^<]+)/i', $postResultHtml, $ageMatches)) {
            $extracted['age'] = trim(strip_tags($ageMatches[1]));
        }

        return !empty($extracted) ? $extracted : null;
    } catch (Exception $e) {
        return null;
    }
}

// Analyze QR Payload Dynamically
$rawInput = !empty($token) ? $token : (!empty($dataParam) ? $dataParam : '');

if (!empty($rawInput)) {
    // 1. Check if it is valid CitizenCard URL or token format (e.g. ccv.ai, verify.citizencard.com, or contains signature)
    if (strpos($rawInput, 'ccv.ai') !== false || strpos($rawInput, '1:19559110') !== false || strpos($rawInput, 'citizencard.com') !== false || $rawInput === $VALID_QR_TOKEN) {
        // Attempt dynamic live verification to fetch dynamic photo & age
        $liveResult = verifyCitizenCardOnline($rawInput);
        if ($liveResult) {
            if (!empty($liveResult['photo'])) $photoUrl = $liveResult['photo'];
            if (!empty($liveResult['age'])) $ageText = $liveResult['age'];
        }
        $hasVerificationResult = true;
    } 
    // 2. Check if it is a URL with custom query params (e.g. ?name=...&age=18+&card_number=...&photo=...)
    elseif (filter_var($rawInput, FILTER_VALIDATE_URL)) {
        $parsedUrl = parse_url($rawInput);
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            if (!empty($queryParams['age'])) $ageText = 'Current Age: ' . htmlspecialchars($queryParams['age']);
            if (!empty($queryParams['name'])) $name = htmlspecialchars($queryParams['name']);
            if (!empty($queryParams['card_number'])) $cardNumber = htmlspecialchars($queryParams['card_number']);
            if (!empty($queryParams['photo'])) $photoUrl = htmlspecialchars($queryParams['photo']);
            $hasVerificationResult = true;
        }
    } 
    // 3. Check if payload is JSON encoded card object
    else {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            if (!empty($jsonData['age'])) $ageText = 'Current Age: ' . htmlspecialchars($jsonData['age']);
            if (!empty($jsonData['name'])) $name = htmlspecialchars($jsonData['name']);
            if (!empty($jsonData['card_number'])) $cardNumber = htmlspecialchars($jsonData['card_number']);
            if (!empty($jsonData['photo'])) $photoUrl = htmlspecialchars($jsonData['photo']);
            $hasVerificationResult = true;
        }
    }

    // If QR payload had data but could not be recognized as any valid citizen card format -> redirect to manual
    if (!$hasVerificationResult) {
        header('Location: /verify-citizencard/verify/manual/?qr_error=invalid');
        exit;
    }
} elseif (!empty($cardNumber) || !empty($name)) {
    $hasVerificationResult = true;
}

// Format current check date and time (e.g., 29 Aug 2026 02:32 or current time)
$checkDateTime = date('d M Y H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $hasVerificationResult ? "Verification Result - CitizenCard" : "Scan QR code - CitizenCard Verification" ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #f8fafc;
            margin: 0;
            padding: 30px 15px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .main-wrapper {
            width: 100%;
            max-width: 820px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Top Sample Warning */
        .sample-warning {
            color: #e53e3e;
            font-size: 17px;
            font-weight: 500;
            margin-bottom: 12px;
            text-align: center;
        }

        /* Expiry Box */
        .expiry-box {
            width: 100%;
            background-color: #f1f3f5;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
            color: #334155;
            margin-bottom: 15px;
        }

        /* Main Card Container */
        .card-container {
            width: 100%;
            background: #ffffff;
            border-radius: 6px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        /* Card Header */
        .card-header {
            background-color: #4a5568;
            color: #ffffff;
            padding: 13px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 600;
            text-align: center;
        }

        .card-header svg {
            width: 17px;
            height: 17px;
            fill: currentColor;
            flex-shrink: 0;
        }

        /* Result Card Body Layout */
        .result-body {
            padding: 35px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
        }

        @media (max-width: 640px) {
            .result-body {
                flex-direction: column;
                padding: 25px 20px;
            }
        }

        /* Photo Frame */
        .photo-wrapper {
            flex-shrink: 0;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 4px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }

        .photo-wrapper img {
            width: 220px;
            height: 280px;
            object-fit: cover;
            display: block;
            border-radius: 2px;
        }

        /* Info Section on Right */
        .info-section {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 24px;
        }

        /* Age Badge */
        .age-badge {
            background-color: #e2f2ea;
            border: 1px solid #b7e2cc;
            color: #276749;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            width: 100%;
            max-width: 270px;
            text-align: center;
        }

        /* Check Timestamp */
        .timestamp-info {
            color: #2d3748;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .timestamp-label {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #4a5568;
        }

        .timestamp-label svg {
            width: 15px;
            height: 15px;
            fill: currentColor;
        }

        .timestamp-val {
            font-weight: 600;
            color: #1a202c;
        }

        /* Action Buttons Area */
        .actions-wrapper {
            margin-top: 28px;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }

        .actions-title {
            font-size: 18px;
            color: #334155;
            font-weight: 500;
        }

        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            justify-content: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 22px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 5px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
        }

        .btn-primary {
            background-color: #0079c1;
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: #0064a0;
        }

        .btn-secondary {
            background-color: #55627a;
            color: #ffffff;
        }

        .btn-secondary:hover {
            background-color: #434d61;
        }

        /* SCANNER SPECIFIC STYLES */
        .alert-info-scan {
            background-color: #e2f5f6;
            color: #0b6971;
            text-align: center;
            padding: 14px 20px;
            font-size: 14px;
            font-weight: 500;
            border-bottom: 1px solid #c9ecf0;
        }

        .scanner-content-area {
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 380px;
        }

        .camera-container {
            display: none;
            width: 100%;
            max-width: 480px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            background-color: #000;
        }

        #qr-reader {
            border: none !important;
        }

        #qr-reader video {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            border-radius: 8px;
        }

        .status-loading {
            color: #64748b;
            font-size: 15px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .spinner {
            border: 3px solid rgba(0, 0, 0, 0.1);
            border-left-color: #0079c1;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error-card {
            display: none;
            width: 100%;
            max-width: 500px;
            background-color: #fdf2f2;
            border: 1px solid #fde8e8;
            border-radius: 8px;
            padding: 30px 24px;
            text-align: center;
        }

        .error-card h2 {
            color: #9b1c1c;
            font-size: 26px;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 12px;
        }

        .error-card p {
            color: #9b1c1c;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 18px;
        }

        .error-card hr {
            border: 0;
            border-top: 1px solid #f8b4b4;
            margin: 18px 0;
        }

        .error-card a {
            color: #9b1c1c;
            font-weight: 600;
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="main-wrapper">

    <?php if ($hasVerificationResult): ?>
        <!-- VERIFICATION RESULT VIEW (Matching the provided UI Design) -->
        <div class="sample-warning">
            Sample card – for testing only
        </div>

        <div class="expiry-box">
            Check expires in <span id="countdown">01:47</span>
        </div>

        <div class="card-container">
            <div class="card-header">
                <!-- User/Profile Icon -->
                <svg viewBox="0 0 24 24">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
                Match the photo and expiry date to the card to verify age and likeness only
            </div>

            <div class="result-body">
                <!-- Verified Citizen Photo -->
                <div class="photo-wrapper">
                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Verified Citizen Photo">
                </div>

                <!-- Verified Details -->
                <div class="info-section">
                    <div class="age-badge">
                        <?= htmlspecialchars($ageText) ?>
                    </div>

                    <div class="timestamp-info">
                        <div class="timestamp-label">
                            <svg viewBox="0 0 24 24">
                                <path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-4.18-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>
                            </svg>
                            Date and time of check:
                        </div>
                        <div class="timestamp-val">
                            <?= $checkDateTime ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Options -->
        <div class="actions-wrapper">
            <div class="actions-title">Verify another card</div>
            <div class="btn-group">
                <a href="/verify-citizencard/verify/manual/" class="btn btn-primary">Full check: enter card details</a>
                <a href="/verify-citizencard/verify/scan/" class="btn btn-secondary">Age &amp; likeness: scan QR code</a>
            </div>
        </div>

        <script>
            // Countdown timer script (from 1:47 downwards)
            let timeLeft = 107; // 1 min 47 sec
            const countdownEl = document.getElementById('countdown');
            const timer = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    countdownEl.textContent = "00:00 (Expired)";
                    // Redirect to root route when expired
                    window.location.href = '/verify-citizencard/';
                } else {
                    timeLeft--;
                    const minutes = String(Math.floor(timeLeft / 60)).padStart(2, '0');
                    const seconds = String(timeLeft % 60).padStart(2, '0');
                    countdownEl.textContent = `${minutes}:${seconds}`;
                }
            }, 1000);
        </script>

    <?php else: ?>
        <!-- LIVE CAMERA SCANNER VIEW -->
        <div class="card-container">
            <div class="card-header">
                <!-- QR Code Icon -->
                <svg viewBox="0 0 24 24">
                    <path d="M3 11h8V3H3v8zm2-6h4v4H5V5zm8-2v8h8V3h-8zm6 6h-4V5h4v4zM3 21h8v-8H3v8zm2-6h4v4H5v-4zm13-2h-2v2h-2v2h2v2h2v-2h2v-2h-2v-2zm-2 6h2v2h-2v-2z"/>
                </svg>
                Scan QR code to verify a CitizenCard holder's age and likeness (cards issued from 22nd March 2021)
            </div>

            <div class="alert-info-scan">
                Allow camera access when prompted, then scan the QR code on card reverse
            </div>

            <div class="scanner-content-area">
                <!-- Requesting camera status -->
                <div id="loading" class="status-loading">
                    <div class="spinner"></div>
                    Requesting camera access...
                </div>

                <!-- Live Camera Stream with Hardware Accelerated Scanner -->
                <div id="camera-container" class="camera-container">
                    <div id="qr-reader" style="width: 100%;"></div>
                </div>

                <!-- Camera Access Blocked Alert -->
                <div id="error-card" class="error-card">
                    <h2>Error</h2>
                    <p>
                        QR code reader needs access to your camera. Please refresh this page, then allow camera access when prompted. If you don't see the prompt to give camera access, it might be already blocked in your browser or app settings.
                    </p>
                    <hr>
                    <p style="margin-bottom: 0;">
                        Need help? <a href="/verify-citizencard/verify/manual/">Use manual verification</a>
                    </p>
                </div>
            </div>
        </div>

        <div class="actions-wrapper">
            <div class="btn-group">
                <a href="/verify-citizencard/verify/manual/" class="btn btn-primary">Enter card details manually</a>
            </div>
        </div>

        <!-- High-Performance Barcode & QR Code Engine (Html5-QRCode + jsQR fallback) -->
        <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
        <script>
            const loadingEl = document.getElementById('loading');
            const cameraContainerEl = document.getElementById('camera-container');
            const errorCardEl = document.getElementById('error-card');
            const VALID_QR_TOKEN = 'https://ccv.ai?1:19559110:MEQCIDNti1rN6A65AiCeH15x4UBcKHAI_YurAX6LV6SzOUcsAiBVeC5o9CiQwTwx8ISAlE5nQ7Qcn1iEXuqWaESLMcaGuA';
            let html5QrCode = null;
            let isProcessed = false;

            function processQRData(data) {
                if (isProcessed) return;
                isProcessed = true;

                if (html5QrCode) {
                    html5QrCode.stop().catch(() => {});
                }

                if (!data || data.trim().length === 0) {
                    window.location.href = '/verify-citizencard/verify/manual/?qr_error=invalid';
                    return;
                }

                const trimmed = data.trim();

                // Pass the scanned QR payload to scan endpoint for dynamic analysis and validation
                window.location.href = '/verify-citizencard/verify/scan/?data=' + encodeURIComponent(trimmed);
            }

            function showAccessError() {
                loadingEl.style.display = 'none';
                cameraContainerEl.style.display = 'none';
                errorCardEl.style.display = 'block';
            }

            window.addEventListener('DOMContentLoaded', () => {
                const scannerElementId = "qr-reader";
                
                // Initialize Html5Qrcode for instant native BarcodeDetector hardware acceleration
                html5QrCode = new Html5Qrcode(scannerElementId);

                const config = {
                    fps: 25, // 25 scans per second for instant capture
                    qrbox: { width: 250, height: 250 },
                    aspectRatio: 1.333334,
                    experimentalFeatures: {
                        useBarCodeDetectorIfSupported: true // Native hardware acceleration on mobile & modern browsers
                    }
                };

                html5QrCode.start(
                    { facingMode: "environment" },
                    config,
                    (decodedText, decodedResult) => {
                        // Instant QR detection trigger
                        console.log("Instant QR Code detected:", decodedText);
                        processQRData(decodedText);
                    },
                    (errorMessage) => {
                        // Parse in progress
                    }
                ).then(() => {
                    loadingEl.style.display = 'none';
                    cameraContainerEl.style.display = 'block';
                }).catch((err) => {
                    console.error("Camera start error:", err);
                    showAccessError();
                });
            });
        </script>
    <?php endif; ?>

</div>

</body>
</html>
