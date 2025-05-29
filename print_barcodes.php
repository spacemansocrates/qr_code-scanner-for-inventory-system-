<?php
session_start();

// Check for user authentication - updated to match login.php session variables
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    header("Location: ../login.php");
    exit();
}

// Check if print data exists
if (!isset($_SESSION['print_data'])) {
    header("Location: ../stock_in.php");
    exit();
}

$data = $_SESSION['print_data'];
// Keep print data in session until explicitly cleared by user action
// This allows for reprinting if needed

// Get current date and time for the batch
$print_date = date('Y-m-d H:i:s');
$print_user = $_SESSION['username'] ?? 'Unknown User';
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Barcodes - <?php echo htmlspecialchars($data['product']['name']); ?></title>
    
    <style>
        /* Screen styles */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        .screen-only {
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .screen-only h1 {
            margin: 0 0 15px 0;
            color: #333;
        }
        
        .print-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .btn {
            padding: 10px 20px;
            background: #007cba;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn:hover {
            background: #005a8b;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #545b62;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .batch-info {
            background: #e7f3ff;
            padding: 15px;
            border-left: 4px solid #007cba;
            margin-bottom: 20px;
            border-radius: 0 4px 4px 0;
        }
        
        .batch-info h3 {
            margin: 0 0 10px 0;
            color: #007cba;
        }
        
        .batch-info p {
            margin: 5px 0;
            color: #333;
        }
        
        /* Print styles */
        @media print {
            .screen-only { 
                display: none !important; 
            }
            
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            
            .qr-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 15px;
                padding: 20px;
                page-break-inside: avoid;
            }
            
            .qr-item {
                text-align: center;
                border: 2px solid #333;
                padding: 15px;
                page-break-inside: avoid;
                background: white;
                border-radius: 8px;
            }
            
            .qr-item img {
                width: 120px;
                height: 120px;
                display: block;
                margin: 0 auto 10px auto;
            }
            
            .product-info {
                font-size: 11px;
                line-height: 1.3;
                color: #000;
            }
            
            .product-name {
                font-weight: bold;
                font-size: 12px;
                margin-bottom: 5px;
            }
            
            .product-details {
                font-size: 10px;
                color: #333;
            }
            
            .print-header {
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #333;
            }
            
            .print-footer {
                position: fixed;
                bottom: 20px;
                left: 20px;
                right: 20px;
                text-align: center;
                font-size: 10px;
                color: #666;
                border-top: 1px solid #ccc;
                padding-top: 10px;
            }
        }
        
        /* Screen grid styles */
        @media screen {
            .qr-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                padding: 20px;
                background: white;
                border-radius: 5px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            
            .qr-item {
                text-align: center;
                border: 2px solid #ddd;
                padding: 20px;
                border-radius: 8px;
                background: #fafafa;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            
            .qr-item:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }
            
            .qr-item img {
                width: 150px;
                height: 150px;
                display: block;
                margin: 0 auto 15px auto;
                border: 1px solid #ccc;
            }
            
            .product-info {
                font-size: 13px;
                line-height: 1.4;
                color: #333;
            }
            
            .product-name {
                font-weight: bold;
                font-size: 14px;
                margin-bottom: 8px;
                color: #007cba;
            }
            
            .product-details {
                font-size: 12px;
                color: #666;
            }
        }
        
        .quantity-info {
            background: #fff3cd;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 10px;
            border: 1px solid #ffeaa7;
        }
        
        .preview-note {
            background: #d1ecf1;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
    </style>
</head>
<body>
    <!-- Screen-only controls -->
    <div class="screen-only">
        <h1>Barcode Print Preview</h1>
        
        <div class="print-controls">
            <button class="btn" onclick="window.print()">
                🖨️ Print Barcodes
            </button>
            <a href="stock_in.php" class="btn btn-secondary">
                ← Back to Stock In
            </a>
            <button class="btn btn-danger" onclick="clearPrintData()">
                🗑️ Clear & Return
            </button>
        </div>
        
        <div class="preview-note">
            <strong>Print Preview:</strong> This page is optimized for printing. The barcodes will be arranged in a 3-column grid when printed.
        </div>
    </div>
    
    <!-- Batch Information -->
    <div class="batch-info screen-only">
        <h3>Batch Information</h3>
        <p><strong>Product:</strong> <?php echo htmlspecialchars($data['product']['name']); ?></p>
        <p><strong>SKU:</strong> <?php echo htmlspecialchars($data['product']['sku'] ?? 'N/A'); ?></p>
        <p><strong>Description:</strong> <?php echo htmlspecialchars($data['product']['description'] ?? 'N/A'); ?></p>
        <p><strong>Quantity:</strong> <?php echo (int)$data['quantity']; ?> barcodes</p>
        <p><strong>Batch Reference:</strong> <?php echo htmlspecialchars($data['batch_reference']); ?></p>
        <p><strong>Generated by:</strong> <?php echo htmlspecialchars($print_user); ?></p>
        <p><strong>Generated on:</strong> <?php echo $print_date; ?></p>
    </div>
    
    <div class="quantity-info screen-only">
        <strong>Note:</strong> Printing <?php echo (int)$data['quantity']; ?> identical barcodes for product:
        <em><?php echo htmlspecialchars($data['product']['name']); ?></em>
    </div>
    
    <!-- Print Header (only visible when printing) -->
    <div class="print-header" style="display: none;">
        <h2><?php echo htmlspecialchars($data['product']['name']); ?></h2>
        <p>Batch: <?php echo htmlspecialchars($data['batch_reference']); ?> | Generated: <?php echo $print_date; ?></p>
    </div>
    
    <!-- Barcode Grid -->
    <div class="qr-grid">
        <?php for ($i = 1; $i <= (int)$data['quantity']; $i++): ?>
            <div class="qr-item">
                <img src="data:image/png;base64,<?php echo $data['barcode_image_base64']; ?>"
                     alt="Barcode for <?php echo htmlspecialchars($data['product']['name']); ?>">
                
                <div class="product-info">
                    <div class="product-name">
                        <?php echo htmlspecialchars($data['product']['name']); ?>
                    </div>
                    
                    <div class="product-details">
                        <strong>SKU:</strong> <?php echo htmlspecialchars($data['product']['sku'] ?? 'N/A'); ?><br>
                        <strong>Code:</strong> <?php echo htmlspecialchars($data['barcode_content']); ?><br>
                        <strong>Item:</strong> <?php echo $i; ?> of <?php echo (int)$data['quantity']; ?><br>
                        <strong>Batch:</strong> <?php echo htmlspecialchars(substr($data['batch_reference'], -8)); ?>
                    </div>
                </div>
            </div>
        <?php endfor; ?>
    </div>
    
    <!-- Print Footer (only visible when printing) -->
    <div class="print-footer">
        Generated by Supplies Direct System | Batch: <?php echo htmlspecialchars($data['batch_reference']); ?> | 
        <?php echo $print_date; ?> | User: <?php echo htmlspecialchars($print_user); ?>
    </div>
    
    <script>
        // Auto-focus print dialog after page loads
        window.addEventListener('load', function() {
            // Small delay to ensure page is fully rendered
            setTimeout(function() {
                // Optionally auto-open print dialog
                // window.print();
            }, 500);
        });
        
        // Function to clear print data and return to stock in
        function clearPrintData() {
            if (confirm('Are you sure you want to clear the print data and return to Stock In?')) {
                // Make AJAX call to clear session data
                fetch('clear_print_data.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({action: 'clear_print_data'})
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = 'stock_in.php';
                    } else {
                        alert('Error clearing print data. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    // Fallback: redirect anyway
                    window.location.href = 'stock_in.php';
                });
            }
        }
        
        // Handle print events
        window.addEventListener('beforeprint', function() {
            // Show print-only elements
            document.querySelectorAll('.print-header').forEach(el => {
                el.style.display = 'block';
            });
        });
        
        window.addEventListener('afterprint', function() {
            // Hide print-only elements
            document.querySelectorAll('.print-header').forEach(el => {
                el.style.display = 'none';
            });
            
            // Optionally ask if user wants to clear data after printing
            setTimeout(function() {
                if (confirm('Barcodes printed successfully. Would you like to return to Stock In page?')) {
                    clearPrintData();
                }
            }, 1000);
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P or Cmd+P for print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Escape key to go back
            if (e.key === 'Escape') {
                window.location.href = 'stock_in.php';
            }
        });
        
        // Prevent accidental page refresh
        window.addEventListener('beforeunload', function(e) {
            // Only show warning if print data exists and user hasn't explicitly cleared it
            const printData = <?php echo json_encode(isset($_SESSION['print_data'])); ?>;
            if (printData) {
                e.preventDefault();
                e.returnValue = 'You have unsaved print data. Are you sure you want to leave?';
                return e.returnValue;
            }
        });
    </script>
</body>
</html>