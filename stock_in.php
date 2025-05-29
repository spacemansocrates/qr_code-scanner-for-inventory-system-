<?php
session_start();
require_once 'InventoryManager.php';

// Check for user authentication - updated to match login.php session variables
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    header('Location: ../login.php');
    exit;
}


$inventory = new InventoryManager();
$message = '';
$message_type = '';
$last_action_result = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_stock':
                $product_id = (int)$_POST['product_id'];
                $quantity = (int)$_POST['quantity'];
                $notes = trim($_POST['notes'] ?? '');
                $reference_type = $_POST['reference_type'] ?? 'receipt';
                $reference_number = trim($_POST['reference_number'] ?? '');
                
                if ($product_id > 0 && $quantity > 0) {
                    $result = $inventory->addStock(
                        $product_id, 
                        $quantity, 
                        $_SESSION['user_id'], 
                        $reference_type, 
                        null, 
                        $notes
                    );
                    
                    if ($result['success']) {
                        $message = "Stock added successfully! New stock level: " . $result['new_stock'] . " units";
                        $message_type = 'success';
                        $last_action_result = $result;
                        
                        // Get product details for confirmation
                        $db = new Database();
                        $conn = $db->connect();
                        $stmt = $conn->prepare("SELECT name, sku FROM products WHERE id = ?");
                        $stmt->execute([$product_id]);
                        $product_info = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($product_info) {
                            $message .= " for " . htmlspecialchars($product_info['name']);
                        }
                    } else {
                        $message = "Error adding stock: " . $result['error'];
                        $message_type = 'error';
                    }
                } else {
                    $message = "Please select a valid product and enter a quantity greater than 0.";
                    $message_type = 'error';
                }
                break;
                
            case 'generate_qr':
                $product_id = (int)$_POST['product_id'];
                $quantity = (int)$_POST['quantity'];
                
                if ($product_id > 0 && $quantity > 0) {
                    try {
                        $result = $inventory->generatePrintableQRCodes($product_id, $quantity, $_SESSION['user_id']);
                        
                        // Store result in session for printing page
                        $_SESSION['print_data'] = $result;
                        header('Location: print_qr_codes.php');
                        exit;
                    } catch (Exception $e) {
                        $message = "Error generating QR codes: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "Please select a valid product and enter a quantity greater than 0.";
                    $message_type = 'error';
                }
                break;
        }
    }
}

// Get products for dropdown
$db = new Database();
$conn = $db->connect();
$stmt = $conn->prepare("
    SELECT 
        p.id, 
        p.name, 
        p.sku, 
        p.description,
        p.category_id,
        p.default_unit_price,
        p.qr_code,
        COALESCE(i.quantity_in_stock, 0) as current_stock,
        COALESCE(i.minimum_stock_level, 0) as min_stock_level,
        CASE 
            WHEN COALESCE(i.quantity_in_stock, 0) <= COALESCE(i.minimum_stock_level, 0) THEN 'LOW_STOCK'
            WHEN COALESCE(i.quantity_in_stock, 0) = 0 THEN 'OUT_OF_STOCK'
            ELSE 'IN_STOCK'
        END as stock_status
    FROM products p 
    LEFT JOIN inventory_stock i ON p.id = i.product_id
    ORDER BY p.name
");
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent stock transactions for dashboard
$stmt = $conn->prepare("
    SELECT 
        st.*,
        p.name as product_name,
        p.sku,
        u.username,
        u.full_name
    FROM stock_transactions st
    JOIN products p ON st.product_id = p.id
    JOIN users u ON st.scanned_by_user_id = u.id
    WHERE st.transaction_type = 'stock_in'
    ORDER BY st.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stock summary
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_products,
        SUM(CASE WHEN COALESCE(i.quantity_in_stock, 0) = 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN COALESCE(i.quantity_in_stock, 0) <= COALESCE(i.minimum_stock_level, 0) AND COALESCE(i.quantity_in_stock, 0) > 0 THEN 1 ELSE 0 END) as low_stock,
        SUM(COALESCE(i.quantity_in_stock, 0)) as total_stock_value
    FROM products p 
    LEFT JOIN inventory_stock i ON p.id = i.product_id
");
$stmt->execute();
$stock_summary = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock In - Inventory Management</title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .nav-links {
            margin-top: 15px;
        }
        
        .nav-links a {
            display: inline-block;
            padding: 8px 16px;
            margin-right: 10px;
            background: #ecf0f1;
            color: #2c3e50;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .nav-links a:hover {
            background: #d5dbdb;
        }
        
        .nav-links a.active {
            background: #3498db;
            color: white;
        }
        
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card h3 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .stat-card {
            text-align: center;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-number.total { color: #3498db; }
        .stat-number.out-of-stock { color: #e74c3c; }
        .stat-number.low-stock { color: #f39c12; }
        .stat-number.total-value { color: #27ae60; }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .message {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
        }
        
        .form-section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-section h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #ecf0f1;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group select {
            cursor: pointer;
        }
        
        .form-group .help-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .btn {
            background: #3498db;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn:hover {
            background: #2980b9;
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .btn-warning {
            background: #f39c12;
        }
        
        .btn-warning:hover {
            background: #e67e22;
        }
        
        .btn-full {
            width: 100%;
        }
        
        .recent-transactions {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            grid-column: 1 / -1;
        }
        
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .transaction-table th,
        .transaction-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .transaction-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .transaction-table tr:hover {
            background: #f8f9fa;
        }
        
        .stock-indicator {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .stock-indicator.in-stock {
            background: #d4edda;
            color: #155724;
        }
        
        .stock-indicator.low-stock {
            background: #fff3cd;
            color: #856404;
        }
        
        .stock-indicator.out-of-stock {
            background: #f8d7da;
            color: #721c24;
        }
        
        .product-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-top: 10px;
            display: none;
        }
        
        .product-info.active {
            display: block;
        }
        
        .product-info h4 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .product-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
        }
        
        .product-detail {
            font-size: 14px;
        }
        
        .product-detail strong {
            color: #2c3e50;
        }
        
        .loading {
            display: none;
            color: #7f8c8d;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📦 Stock In - Inventory Management</h1>
            <p class="subtitle">Add new stock and generate QR codes for inventory tracking</p>
            
            <div class="nav-links">
                <a href="stock_in.php" class="active">📥 Stock In</a>
                <a href="stock_out.php">📤 Stock Out</a>
                <a href="stock_report.php">📊 Stock Report</a>
                <a href="dashboard.php">🏠 Dashboard</a>
            </div>
        </div>
        
        <!-- Dashboard Cards -->
        <div class="dashboard-cards">
            <div class="card stat-card">
                <div class="stat-number total"><?php echo number_format($stock_summary['total_products']); ?></div>
                <div class="stat-label">Total Products</div>
            </div>
            
            <div class="card stat-card">
                <div class="stat-number out-of-stock"><?php echo number_format($stock_summary['out_of_stock']); ?></div>
                <div class="stat-label">Out of Stock</div>
            </div>
            
            <div class="card stat-card">
                <div class="stat-number low-stock"><?php echo number_format($stock_summary['low_stock']); ?></div>
                <div class="stat-label">Low Stock</div>
            </div>
            
            <div class="card stat-card">
                <div class="stat-number total-value"><?php echo number_format($stock_summary['total_stock_value']); ?></div>
                <div class="stat-label">Total Units in Stock</div>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Add Stock Form -->
            <div class="form-section">
                <h2>📥 Add Stock</h2>
                
                <form method="POST" id="addStockForm">
                    <input type="hidden" name="action" value="add_stock">
                    
                    <div class="form-group">
                        <label for="product_id">Select Product *</label>
                        <select name="product_id" id="product_id" required onchange="showProductInfo(this.value)">
                            <option value="">-- Choose a product --</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>" 
                                        data-stock="<?php echo $product['current_stock']; ?>"
                                        data-status="<?php echo $product['stock_status']; ?>"
                                        data-sku="<?php echo htmlspecialchars($product['sku']); ?>"
                                        data-description="<?php echo htmlspecialchars($product['description']); ?>"
                                        data-price="<?php echo $product['default_unit_price']; ?>"
                                        data-qr="<?php echo htmlspecialchars($product['qr_code']); ?>">
                                    <?php echo htmlspecialchars($product['name'] . ' (' . $product['sku'] . ')'); ?>
                                    <?php if ($product['stock_status'] === 'OUT_OF_STOCK'): ?>
                                        - OUT OF STOCK
                                    <?php elseif ($product['stock_status'] === 'LOW_STOCK'): ?>
                                        - LOW STOCK (<?php echo $product['current_stock']; ?>)
                                    <?php else: ?>
                                        - Stock: <?php echo $product['current_stock']; ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Select the product you want to add stock for</div>
                    </div>
                    
                    <!-- Product Info Display -->
                    <div class="product-info" id="productInfo">
                        <h4>Product Information</h4>
                        <div class="product-details">
                            <div class="product-detail">
                                <strong>Current Stock:</strong> <span id="currentStock">-</span>
                            </div>
                            <div class="product-detail">
                                <strong>Status:</strong> <span id="stockStatus">-</span>
                            </div>
                            <div class="product-detail">
                                <strong>SKU:</strong> <span id="productSku">-</span>
                            </div>
                            <div class="product-detail">
                                <strong>Unit Price:</strong> $<span id="unitPrice">-</span>
                            </div>
                            <div class="product-detail">
                                <strong>QR Code:</strong> <span id="qrCode">-</span>
                            </div>
                            <div class="product-detail" style="grid-column: 1 / -1;">
                                <strong>Description:</strong> <span id="productDescription">-</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="quantity">Quantity to Add *</label>
                        <input type="number" name="quantity" id="quantity" min="1" required>
                        <div class="help-text">Enter the number of units to add to inventory</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="reference_type">Reference Type</label>
                        <select name="reference_type" id="reference_type">
                            <option value="receipt">Purchase Receipt</option>
                            <option value="transfer">Stock Transfer</option>
                            <option value="adjustment">Inventory Adjustment</option>
                            <option value="return">Customer Return</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="reference_number">Reference Number</label>
                        <input type="text" name="reference_number" id="reference_number" 
                               placeholder="PO#, Invoice#, etc.">
                        <div class="help-text">Optional reference number for tracking</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea name="notes" id="notes" 
                                  placeholder="Additional notes about this stock addition..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-success btn-full">
                        ✅ Add Stock to Inventory
                    </button>
                </form>
            </div>
            
            <!-- Generate QR Codes Form -->
            <div class="form-section">
                <h2>🏷️ Generate QR Codes</h2>
                
                <form method="POST" id="generateQRForm">
                    <input type="hidden" name="action" value="generate_qr">
                    
                    <div class="form-group">
                        <label for="qr_product_id">Select Product *</label>
                        <select name="product_id" id="qr_product_id" required>
                            <option value="">-- Choose a product --</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['name'] . ' (' . $product['sku'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Select product to generate QR codes for</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="qr_quantity">Number of QR Codes *</label>
                        <input type="number" name="quantity" id="qr_quantity" min="1" max="1000" required>
                        <div class="help-text">How many identical QR codes to generate (max 1000)</div>
                    </div>
                    
                    <button type="submit" class="btn btn-warning btn-full">
                        🖨️ Generate & Print QR Codes
                    </button>
                    
                    <div class="help-text" style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 4px;">
                        <strong>Note:</strong> Each product has one unique QR code. When you generate multiple codes, 
                        they will all be identical and can be attached to individual units of the same product.
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Recent Transactions -->
        <div class="recent-transactions">
            <h2>📋 Recent Stock Additions</h2>
            
            <?php if (empty($recent_transactions)): ?>
                <p style="color: #7f8c8d; font-style: italic; margin-top: 15px;">
                    No recent stock additions found.
                </p>
            <?php else: ?>
                <table class="transaction-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Quantity</th>
                            <th>New Balance</th>
                            <th>Added By</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_transactions as $transaction): ?>
                            <tr>
                                <td><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($transaction['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['sku']); ?></td>
                                <td>+<?php echo number_format($transaction['quantity']); ?></td>
                                <td><?php echo number_format($transaction['running_balance']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['full_name'] ?: $transaction['username']); ?></td>
                                <td>
                                    <?php if ($transaction['reference_type']): ?>
                                        <span style="font-size: 12px; background: #ecf0f1; padding: 2px 6px; border-radius: 10px;">
                                            <?php echo ucfirst($transaction['reference_type']); ?>
                                        </span>
                                        <?php if ($transaction['reference_number']): ?>
                                            <br><small><?php echo htmlspecialchars($transaction['reference_number']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Show product information when product is selected
        function showProductInfo(productId) {
            const productSelect = document.getElementById('product_id');
            const productInfo = document.getElementById('productInfo');
            
            if (!productId) {
                productInfo.classList.remove('active');
                return;
            }
            
            const selectedOption = productSelect.querySelector(`option[value="${productId}"]`);
            if (!selectedOption) return;
            
            // Update product info display
            document.getElementById('currentStock').textContent = selectedOption.dataset.stock || '0';
            document.getElementById('productSku').textContent = selectedOption.dataset.sku || '-';
            document.getElementById('productDescription').textContent = selectedOption.dataset.description || 'No description available';
            document.getElementById('unitPrice').textContent = selectedOption.dataset.price || '0.00';
            document.getElementById('qrCode').textContent = selectedOption.dataset.qr || 'Not generated';
            
            // Update status with styling
            const statusElement = document.getElementById('stockStatus');
            const status = selectedOption.dataset.status;
            statusElement.textContent = status.replace('_', ' ');
            statusElement.className = 'stock-indicator ' + status.toLowerCase().replace('_', '-');
            
            // Show the info panel
            productInfo.classList.add('active');
        }
        
        // Form validation and enhancement
        document.getElementById('addStockForm').addEventListener('submit', function(e) {
            const quantity = parseInt(document.getElementById('quantity').value);
            const productId = document.getElementById('product_id').value;
            
            if (!productId) {
                e.preventDefault();
                alert('Please select a product.');
                return;
            }
            
            if (quantity <= 0 || quantity > 10000) {
                e.preventDefault();
                alert('Please enter a valid quantity between 1 and 10,000.');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = '⏳ Adding Stock...';
            submitBtn.disabled = true;
            
            // Reset on page reload/back
            setTimeout(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });
        
        document.getElementById('generateQRForm').addEventListener('submit', function(e) {
            const quantity = parseInt(document.getElementById('qr_quantity').value);
            const productId = document.getElementById('qr_product_id').value;
            
            if (!productId) {
                e.preventDefault();
                alert('Please select a product.');
                return;
            }
            
            if (quantity <= 0 || quantity > 1000) {
                e.preventDefault();
                alert('Please enter a valid quantity between 1 and 1,000.');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = '⏳ Generating QR Codes...';
            submitBtn.disabled = true;
        });
        
        // Auto-focus on quantity after product selection
        document.getElementById('product_id').addEventListener('change', function() {
            if (this.value) {
                setTimeout(() => {
                    document.getElementById('quantity').focus();
                }, 100);
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + A to focus on add stock form
            if (e.altKey && e.key === 'a') {
                e.preventDefault();
                document.getElementById('product_id').focus();
            }
            
            // Alt + Q to focus on QR generation form
            if (e.altKey && e.key === 'q') {
                e.preventDefault();
                document.getElementById('qr_product_id').focus();
            }
        });
        
        // Auto-refresh stock data every 30 seconds
        setInterval(function() {
            // Only refresh if no forms are being filled
            const activeElement = document.activeElement;
            if (!activeElement || !activeElement.form) {
                location.reload();
            }
        }, 30000);
    </script>
</body>
</html>