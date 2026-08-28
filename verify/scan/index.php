<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan QR code - CitizenCard Verification</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #f4f6f8;
            margin: 0;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 960px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .header {
            background-color: #4a5468;
            color: #ffffff;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
        }

        .header svg {
            width: 18px;
            height: 18px;
            fill: currentColor;
            flex-shrink: 0;
        }

        .alert-info {
            background-color: #e2f5f6;
            color: #0b6971;
            text-align: center;
            padding: 14px 20px;
            font-size: 14px;
            font-weight: 500;
            border-bottom: 1px solid #c9ecf0;
            margin-bottom: 20px;
        }

        .content-area {
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 350px;
        }

        /* Camera Feed View */
        .camera-container {
            display: none;
            width: 100%;
            max-width: 500px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            background-color: #000;
            aspect-ratio: 4 / 3;
        }

        .camera-container video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1); /* Mirror view for selfies, optional */
        }

        /* Error Box View */
        .error-card {
            display: none;
            width: 100%;
            max-width: 500px;
            background-color: #fdf2f2;
            border: 1px solid #fde8e8;
            border-radius: 8px;
            padding: 30px 24px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .error-card h2 {
            color: #9b1c1c;
            font-size: 28px;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 15px;
        }

        .error-card p {
            color: #9b1c1c;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .error-card hr {
            border: 0;
            border-top: 1px solid #f8b4b4;
            margin: 20px 0;
        }

        .error-card a {
            color: #9b1c1c;
            font-weight: 600;
            text-decoration: underline;
        }

        /* Loading / Checking State */
        .status-loading {
            color: #718096;
            font-size: 16px;
            font-weight: 500;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <!-- QR Code Grid Icon SVG -->
        <svg viewBox="0 0 24 24">
            <path d="M3 11h8V3H3v8zm2-6h4v4H5V5zm8-2v8h8V3h-8zm6 6h-4V5h4v4zM3 21h8v-8H3v8zm2-6h4v4H5v-4zm13-2h-2v2h-2v2h2v2h2v-2h2v-2h-2v-2zm-2 6h2v2h-2v-2z"/>
        </svg>
        Scan QR code to verify a CitizenCard holder's age and likeness (cards issued from 22nd March 2021)
    </div>

    <div class="alert-info">
        Allow camera access when prompted, then scan the QR code on card reverse
    </div>

    <div class="content-area">
        <!-- Checking Status Indicator -->
        <div id="loading" class="status-loading">
            Requesting camera access...
        </div>

        <!-- Live Camera Stream -->
        <div id="camera-container" class="camera-container">
            <video id="video" autoplay playsinline></video>
        </div>

        <!-- Access Blocked Error Alert Box -->
        <div id="error-card" class="error-card">
            <h2>Error</h2>
            <p>
                QR code reader needs access to your camera. Please refresh this page, then allow camera access when prompted. If you don't see the prompt to give camera access, it might be already blocked in your browser or app settings.
            </p>
            <hr>
            <p style="margin-bottom: 0;">
                Need help? <a href="#">Contact us</a>
            </p>
        </div>
    </div>
</div>

<!-- Load jsQR library from a reliable CDN to scan QR codes -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
    const loadingEl = document.getElementById('loading');
    const cameraContainerEl = document.getElementById('camera-container');
    const errorCardEl = document.getElementById('error-card');
    const videoEl = document.getElementById('video');
    let videoStream = null;
    let canvasElement = document.createElement('canvas');
    let canvasCtx = canvasElement.getContext('2d');
    let isScanning = false;

    function showCameraStream(stream) {
        loadingEl.style.display = 'none';
        errorCardEl.style.display = 'none';
        cameraContainerEl.style.display = 'block';
        videoStream = stream;
        videoEl.srcObject = stream;
        
        videoEl.setAttribute("playsinline", true); // required to tell iOS safari we don't want fullscreen
        videoEl.play();
        
        isScanning = true;
        requestAnimationFrame(tick);
    }

    function tick() {
        if (!isScanning) return;

        if (videoEl.readyState === videoEl.HAVE_ENOUGH_DATA) {
            canvasElement.height = videoEl.videoHeight;
            canvasElement.width = videoEl.videoWidth;
            canvasCtx.drawImage(videoEl, 0, 0, canvasElement.width, canvasElement.height);
            
            var imageData = canvasCtx.getImageData(0, 0, canvasElement.width, canvasElement.height);
            var code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: "dontInvert",
            });

            if (code) {
                // QR code found! Let's stop scanning and stop camera tracks
                isScanning = false;
                if (videoStream) {
                    videoStream.getTracks().forEach(track => track.stop());
                }

                console.log("QR Code found:", code.data);
                processQRData(code.data);
                return;
            }
        }
        requestAnimationFrame(tick);
    }

    function processQRData(data) {
        // Try parsing as JSON or querying parameters
        let parsed = null;
        try {
            // Check if QR data is JSON
            parsed = JSON.parse(data);
        } catch (e) {
            // If not JSON, check if it's a URL and we can extract query parameters
            try {
                let url = new URL(data);
                let params = new URLSearchParams(url.search);
                if (params.has('card_number') || params.has('dob_day') || params.has('name')) {
                    parsed = {
                        card_number: params.get('card_number'),
                        dob_day: params.get('dob_day'),
                        dob_month: params.get('dob_month'),
                        dob_year: params.get('dob_year'),
                        name: params.get('name')
                    };
                }
            } catch (urlErr) {
                // Not a valid URL either
            }
        }

        // Validate if we have the minimum required card fields (e.g. card_number and name)
        if (parsed && (parsed.card_number || parsed.name)) {
            // Build redirect URL to manual verify page
            let redirectUrl = 'https://mycitizencard.com/manaul-card';
            let params = new URLSearchParams();
            if (parsed.card_number) params.append('card_number', parsed.card_number);
            if (parsed.dob_day) params.append('dob_day', parsed.dob_day);
            if (parsed.dob_month) params.append('dob_month', parsed.dob_month);
            if (parsed.dob_year) params.append('dob_year', parsed.dob_year);
            if (parsed.name) params.append('name', parsed.name);
            
            window.location.href = redirectUrl + params.toString();
        } else {
            // QR does not contain correct card fields, redirect to manual verification showing error
            window.location.href = 'https://mycitizencard.com/manaul-card';
        }
    }

    function showAccessError() {
        loadingEl.style.display = 'none';
        cameraContainerEl.style.display = 'none';
        errorCardEl.style.display = 'block';
    }

    // Auto prompt camera access on load
    window.addEventListener('DOMContentLoaded', () => {
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(function(stream) {
                    showCameraStream(stream);
                })
                .catch(function(error) {
                    console.error("Camera access denied or failed:", error);
                    showAccessError();
                });
        } else {
            console.error("getUserMedia not supported in this browser.");
            showAccessError();
        }
    });
</script>

</body>
</html>
