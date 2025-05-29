<?php
session_start();
require_once 'InventoryManager.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$inventory = new InventoryManager();
$message = '';
$scan_result = null;
$error_class = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'scan_out') {
    $qr_code = trim($_POST['qr_code']);
    $notes = trim($_POST['notes'] ?? '');
    $quotation_id = !empty($_POST['quotation_id']) ? (int)$_POST['quotation_id'] : null;
    $reference_number = trim($_POST['reference_number'] ?? '');
    
    // Validate barcode input
    if (empty($qr_code)) {
        $message = "Please enter or scan a barcode.";
        $error_class = 'error';
    } else {
        $result = $inventory->removeStock(
            $qr_code, 
            $_SESSION['user_id'], 
            'sale', 
            $quotation_id, 
            $notes,
            $reference_number
        );
        
        if ($result['success']) {
            $scan_result = $result;
            $message = $result['message'];
            $error_class = 'success';
            
            // Clear form data after successful scan
            $_POST = array();
        } else {
            $message = $result['error'];
            $error_class = 'error';
        }
    }
}

// Handle AJAX requests for real-time stock checking
if (isset($_GET['action']) && $_GET['action'] === 'check_stock' && isset($_GET['qr_code'])) {
    header('Content-Type: application/json');
    
    $qr_code = trim($_GET['qr_code']);
    
    try {
        $db = new Database();
        $conn = $db->connect();
        
        $stmt = $conn->prepare("
            SELECT p.id, p.name, p.sku, p.description, 
                   COALESCE(i.quantity_in_stock, 0) as current_stock,
                   COALESCE(i.minimum_stock_level, 0) as min_level
            FROM products p 
            LEFT JOIN inventory_stock i ON p.id = i.product_id 
            WHERE p.qr_code = ?
        ");
        $stmt->execute([$qr_code]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            echo json_encode([
                'success' => true,
                'product' => $product,
                'can_scan' => $product['current_stock'] > 0
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Product not found for code: ' . $qr_code
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Out - Barcode Scanner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
            line-height: 1.6;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 1.1em;
        }
        
        .content {
            padding: 30px;
        }
        
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-weight: bold;
            animation: fadeIn 0.5s ease-in;
        }
        
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .scan-result {
            background: linear-gradient(135deg, #f0f8ff 0%, #e6f3ff 100%);
            padding: 25px;
            border-radius: 10px;
            border: 2px solid #007bff;
            margin: 20px 0;
            animation: slideIn 0.5s ease-out;
        }
        
        .scan-result h3 {
            color: #007bff;
            font-size: 1.5em;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .scan-result h3::before {
            content: "✓";
            background: #28a745;
            color: white;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-weight: bold;
        }
        
        .scan-result .product-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .scan-result .detail-item {
            background: white;
            padding: 10px;
            border-radius: 5px;
            border-left: 4px solid #007bff;
        }
        
        .scan-result .detail-item strong {
            display: block;
            color: #666;
            font-size: 0.9em;
            margin-bottom: 5px;
        }
        
        .scan-result .detail-item span {
            font-size: 1.1em;
            color: #333;
        }
        
        .stock-indicator {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.9em;
            font-weight: bold;
        }
        
        .stock-indicator.high {
            background: #d4edda;
            color: #155724;
        }
        
        .stock-indicator.medium {
            background: #fff3cd;
            color: #856404;
        }
        
        .stock-indicator.low {
            background: #f8d7da;
            color: #721c24;
        }
        
        .form-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .form-section h3 {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.3em;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: #333;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 10px rgba(0,123,255,0.1);
        }
        
        .qr-input-group {
            position: relative;
        }
        
        .qr-input-group input {
            padding-right: 50px;
        }
        
        .scan-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-size: 1.2em;
        }
        
        .btn {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,123,255,0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            margin-top: 10px;
        }
        
        .navigation {
            background: #f8f9fa;
            padding: 15px 30px;
            border-top: 1px solid #dee2e6;
        }
        
        .navigation a {
            color: #007bff;
            text-decoration: none;
            margin-right: 20px;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .navigation a:hover {
            color: #0056b3;
            text-decoration: underline;
        }
        
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .stat-card h4 {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 5px;
        }
        
        .stat-card .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            color: #333;
        }
        
        .product-preview {
            background: #fff;
            border: 2px dashed #007bff;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
            display: none;
            animation: fadeIn 0.3s ease-in;
        }
        
        .product-preview.show {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translateY(-20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header h1 {
                font-size: 2em;
            }
            
            .content {
                padding: 20px;
            }
            
            .scan-result .product-details {
                grid-template-columns: 1fr;
            }
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
          /* Add these styles to your existing CSS */
        .qr-scanner-container {
            position: relative;
            margin: 10px 0;
        }
        
        .camera-button {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .camera-button:hover {
            background: #0056b3;
        }
        
        .camera-button:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        .scanner-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .scanner-content {
            background: white;
            padding: 20px;
            border-radius: 10px;
            max-width: 90%;
            max-height: 90%;
            position: relative;
        }
        
        .scanner-video {
            width: 100%;
            max-width: 400px;
            height: 300px;
            border: 2px solid #007bff;
            border-radius: 5px;
            background: #000;
        }
        
        .scanner-controls {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: center;
        }
        
        .close-scanner {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .scanner-status {
            text-align: center;
            margin: 10px 0;
            font-weight: bold;
        }
        
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .info { color: #007bff; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📦 Stock Out Scanner</h1>
            <p>Scan barcodes to remove items from inventory</p>
        </div>
        
        <div class="content">
            <?php if ($message): ?>
                <div class="message <?php echo $error_class; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($scan_result): ?>
                <div class="scan-result">
                    <h3>Item Successfully Scanned Out</h3>
                    <div class="product-details">
                        <div class="detail-item">
                            <strong>Product Name</strong>
                            <span><?php echo htmlspecialchars($scan_result['product']['name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <strong>SKU</strong>
                            <span><?php echo htmlspecialchars($scan_result['product']['sku']); ?></span>
                        </div>
                        <div class="detail-item">
                            <strong>Remaining Stock</strong>
                            <span class="stock-indicator <?php 
                                $stock = $scan_result['new_stock'];
                                if ($stock > 10) echo 'high';
                                elseif ($stock > 3) echo 'medium';
                                else echo 'low';
                            ?>">
                                <?php echo $stock; ?> units
                            </span>
                        </div>
                        <div class="detail-item">
                            <strong>Scanned By</strong>
                            <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?></span>
                        </div>
                        <div class="detail-item">
                            <strong>Scan Time</strong>
                            <span><?php echo date('M j, Y - g:i A'); ?></span>
                        </div>
                        <?php if (!empty($scan_result['quotation_id'])): ?>
                        <div class="detail-item">
                            <strong>Quotation ID</strong>
                            <span>#<?php echo $scan_result['quotation_id']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="form-section">
                <h3>🔍 Scan Item Out</h3>
                
                <div class="loading" id="loading">
                    <div class="spinner"></div>
                    <p>Checking product information...</p>
                </div>
                
                <div class="product-preview" id="productPreview">
                    <h4>Product Information</h4>
                    <div id="productInfo"></div>
                </div>
                
                <form method="POST" id="scanForm">
                    <input type="hidden" name="action" value="scan_out">
                    
                  <div class="form-group">
        <label for="qr_code">Barcode *</label>
        <div class="qr-input-group">
            <input 
                type="text" 
                id="qr_code"
                name="qr_code" 
                placeholder="Scan barcode or enter manually"
                required 
                autofocus
                autocomplete="off"
                value="<?php echo htmlspecialchars($_POST['qr_code'] ?? ''); ?>"
            >
            <div class="qr-scanner-container">
                <button type="button" id="startScanBtn" class="camera-button">
                    📷 Scan Barcode
                </button>
            </div>
        </div>
        <small style="color: #666; font-size: 0.9em;">
            Click "Scan Barcode" to use camera, or type the code manually
        </small>
    </div>
                    
                    <div class="form-group">
                        <label for="quotation_id">Quotation ID (Optional)</label>
                        <input 
                            type="number" 
                            id="quotation_id"
                            name="quotation_id" 
                            placeholder="Enter quotation ID to link this transaction"
                            value="<?php echo htmlspecialchars($_POST['quotation_id'] ?? ''); ?>"
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="reference_number">Reference Number (Optional)</label>
                        <input 
                            type="text" 
                            id="reference_number"
                            name="reference_number" 
                            placeholder="Invoice, receipt, or document number"
                            value="<?php echo htmlspecialchars($_POST['reference_number'] ?? ''); ?>"
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes (Optional)</label>
                        <textarea 
                            id="notes"
                            name="notes" 
                            rows="3" 
                            placeholder="Additional notes about this transaction..."
                        ><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn" id="submitBtn">
                        📤 Scan Out Item
                    </button>
                    
                    <button type="button" class="btn btn-secondary" onclick="clearForm()">
                        🔄 Clear Form
                    </button>

                </form>
                  <div id="scannerModal" class="scanner-modal">
        <div class="scanner-content">
            <h3 style="text-align: center; margin-bottom: 15px;">📷 Barcode Scanner</h3>
            <div class="scanner-status" id="scannerStatus">
                <span class="info">Position barcode in front of camera</span>
            </div>
            <video id="scannerVideo" class="scanner-video" muted playsinline></video>
            <div class="scanner-controls">
                <button id="closeScannerBtn" class="close-scanner">❌ Close Scanner</button>
            </div>
        </div>
    </div>
            </div>
            
            <div class="quick-stats">
                <div class="stat-card">
                    <h4>Session Scans</h4>
                    <div class="stat-value" id="sessionScans">
                        <?php echo isset($_SESSION['scan_count']) ? $_SESSION['scan_count'] : 0; ?>
                    </div>
                </div>
                <div class="stat-card">
                    <h4>Current User</h4>
                    <div class="stat-value" style="font-size: 1em;">
                        <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?>
                    </div>
                </div>
                <div class="stat-card">
                    <h4>Last Scan</h4>
                    <div class="stat-value" style="font-size: 0.9em;">
                        <?php echo $scan_result ? date('g:i A') : 'None'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="navigation">
            <a href="stock_in.php">📥 Stock In</a>
            <a href="stock_report.php">📊 Stock Report</a>
            <a href="transaction_history.php">📋 Transaction History</a>
            <a href="index.php">🏠 Dashboard</a>
        </div>
    </div>

    <script>
        // Barcode scanner variables
        let barcodeDetector = null;
        let videoStream = null;
        let isScanning = false;
        
        // Track session scans
        <?php if ($scan_result): ?>
            <?php 
            if (!isset($_SESSION['scan_count'])) {
                $_SESSION['scan_count'] = 0;
            }
            $_SESSION['scan_count']++;
            ?>
            document.getElementById('sessionScans').textContent = '<?php echo $_SESSION['scan_count']; ?>';
        <?php endif; ?>
        
        // Initialize Barcode Scanner
        function initBarcodeScanner() {
            const video = document.getElementById('scannerVideo');
            const statusElement = document.getElementById('scannerStatus');

            barcodeDetector = new BarcodeDetector({ formats: ['code_128', 'ean_13', 'ean_8', 'code_39'] });

            const scan = () => {
                if (!isScanning) return;
                barcodeDetector.detect(video).then(barcodes => {
                    if (barcodes.length > 0) {
                        document.getElementById('qr_code').value = barcodes[0].rawValue;
                        statusElement.innerHTML = '<span class="success">✅ Barcode detected!</span>';
                        setTimeout(() => {
                            closeScanner();
                            checkProductInfo();
                        }, 1000);
                    } else {
                        requestAnimationFrame(scan);
                    }
                }).catch(err => {
                    console.error('Detection error:', err);
                    requestAnimationFrame(scan);
                });
            };

            requestAnimationFrame(scan);
        }
        
        // Start barcode scanner
        function startScanner() {
            if (isScanning) return;

            const modal = document.getElementById('scannerModal');
            const statusElement = document.getElementById('scannerStatus');
            const startBtn = document.getElementById('startScanBtn');

            // Check for camera support
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert('Camera access is not supported in this browser. Please enter the code manually.');
                return;
            }

            if (!('BarcodeDetector' in window)) {
                alert('Barcode Detector API not supported in this browser.');
                return;
            }

            statusElement.innerHTML = '<span class="info">📷 Starting camera...</span>';
            modal.style.display = 'flex';
            startBtn.disabled = true;

            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(stream => {
                videoStream = stream;
                const video = document.getElementById('scannerVideo');
                video.srcObject = stream;
                video.play();
                isScanning = true;
                statusElement.innerHTML = '<span class="info">🔍 Position barcode in front of camera</span>';
                startBtn.textContent = '📷 Scanner Active';

                initBarcodeScanner();
            }).catch(err => {
                console.error('Scanner start error:', err);
                statusElement.innerHTML = '<span class="error">❌ Camera access denied or not available</span>';
                
                // Show user-friendly error message
                let errorMsg = 'Unable to access camera. ';
                if (err.name === 'NotAllowedError') {
                    errorMsg += 'Please allow camera access and try again.';
                } else if (err.name === 'NotFoundError') {
                    errorMsg += 'No camera found on this device.';
                } else {
                    errorMsg += 'Please enter the code manually.';
                }

                alert(errorMsg);
                closeScanner();
            });
        }

        // Close barcode scanner
        function closeScanner() {
            const modal = document.getElementById('scannerModal');
            const startBtn = document.getElementById('startScanBtn');

            modal.style.display = 'none';
            startBtn.disabled = false;
            startBtn.textContent = '📷 Scan Barcode';

            if (videoStream) {
                videoStream.getTracks().forEach(t => t.stop());
                videoStream = null;
            }
            isScanning = false;
        }
        
        // Event Listeners
        document.getElementById('startScanBtn').addEventListener('click', startScanner);
        document.getElementById('closeScannerBtn').addEventListener('click', closeScanner);
        
        // Close scanner when clicking outside the content
        document.getElementById('scannerModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeScanner();
            }
        });
        
        // Close scanner with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isScanning) {
                closeScanner();
            }
        });
        
        // Auto-submit form when barcode is scanned (assuming scanner adds newline)
        document.getElementById('qr_code').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                checkProductInfo();
            }
        });
        
        // Real-time product checking
        let checkTimeout;
        document.getElementById('qr_code').addEventListener('input', function() {
            clearTimeout(checkTimeout);
            const qrCode = this.value.trim();
            
            if (qrCode.length > 3) {
                checkTimeout = setTimeout(() => {
                    checkProductInfo();
                }, 500);
            } else {
                hideProductPreview();
            }
        });
        
        function checkProductInfo() {
            const qrCode = document.getElementById('qr_code').value.trim();
            
            if (!qrCode) {
                hideProductPreview();
                return;
            }
            
            showLoading();
            
            fetch(`?action=check_stock&qr_code=${encodeURIComponent(qrCode)}`)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    
                    if (data.success) {
                        showProductPreview(data.product, data.can_scan);
                    } else {
                        showProductPreview(null, false, data.error);
                    }
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showProductPreview(null, false, 'Network error occurred');
                });
        }
        
        function showProductPreview(product, canScan, error = null) {
            const preview = document.getElementById('productPreview');
            const info = document.getElementById('productInfo');
            const submitBtn = document.getElementById('submitBtn');
            
            if (error) {
                info.innerHTML = `<div style="color: #721c24; background: #f8d7da; padding: 10px; border-radius: 5px;">${error}</div>`;
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
            } else if (product) {
                const stockClass = product.current_stock > 10 ? 'high' : (product.current_stock > 3 ? 'medium' : 'low');
                
                info.innerHTML = `
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                        <div><strong>Name:</strong><br>${product.name}</div>
                        <div><strong>SKU:</strong><br>${product.sku}</div>
                        <div><strong>Stock:</strong><br><span class="stock-indicator ${stockClass}">${product.current_stock} units</span></div>
                    </div>
                    ${!canScan ? '<div style="color: #721c24; margin-top: 10px; font-weight: bold;">⚠️ Out of stock - Cannot scan out</div>' : ''}
                `;
                
                submitBtn.disabled = !canScan;
                submitBtn.style.opacity = canScan ? '1' : '0.5';
            }
            
            preview.classList.add('show');
        }
        
        function hideProductPreview() {
            const preview = document.getElementById('productPreview');
            const submitBtn = document.getElementById('submitBtn');
            
            preview.classList.remove('show');
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        }
        
        function showLoading() {
            document.getElementById('loading').style.display = 'block';
        }
        
        function hideLoading() {
            document.getElementById('loading').style.display = 'none';
        }
        
        function clearForm() {
            document.getElementById('scanForm').reset();
            hideProductPreview();
            document.getElementById('qr_code').focus();
        }
        
        // Focus on barcode input when page loads
        window.addEventListener('load', function() {
            document.getElementById('qr_code').focus();
        });
        
        // Prevent form submission if product can't be scanned
        document.getElementById('scanForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn.disabled) {
                e.preventDefault();
                alert('Cannot scan out this item. Please check the product information.');
            }
        });
        
        // Auto-clear success messages after 5 seconds
        <?php if ($scan_result): ?>
            setTimeout(() => {
                const message = document.querySelector('.message.success');
                if (message) {
                    message.style.transition = 'opacity 0.5s ease-out';
                    message.style.opacity = '0';
                    setTimeout(() => message.remove(), 500);
                }
            }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>