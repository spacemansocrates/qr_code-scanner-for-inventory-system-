<?php
session_start();
require_once 'InventoryManager.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$inventory = new InventoryManager();
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

if ($product_id <= 0) {
    header('Location: stock_report.php');
    exit;
}

// Get product details
$db = new Database();
$conn = $db->connect();
$stmt = $conn->prepare("SELECT id, name, sku, qr_code FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: stock_report.php');
    exit;
}

// Get current stock
$current_stock = $inventory->getCurrentStock($product_id);

// Get transaction history
$transactions = $inventory->getTransactionHistory($product_id, $limit);

// Get stock summary
$stmt = $conn->prepare("
    SELECT 
        COALESCE(quantity_in_stock, 0) as current_stock,
        COALESCE(total_received, 0) as total_received,
        COALESCE(total_sold, 0) as total_sold,
        COALESCE(minimum_stock_level, 0) as minimum_stock_level,
        last_updated
    FROM inventory_stock 
    WHERE product_id = ?
");
$stmt->execute([$product_id]);
$stock_summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Function to format transaction type for display
function formatTransactionType($type) {
    switch ($type) {
        case 'stock_in':
            return 'Stock In';
        case 'stock_out':
            return 'Stock Out';
        case 'adjustment':
            return 'Adjustment';
        case 'return':
            return 'Return';
        default:
            return ucfirst($type);
    }
}

// Function to get CSS class for transaction type
function getTransactionClass($type) {
    switch ($type) {
        case 'stock_in':
        case 'return':
            return 'positive';
        case 'stock_out':
            return 'negative';
        case 'adjustment':
            return 'adjustment';
        default:
            return '';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Transaction History - <?php echo htmlspecialchars($product['name']); ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }
        
        .product-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .product-info h2 {
            margin: 0 0 10px 0;
            color: #333;
        }
        
        .product-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }
        
        .detail-label {
            font-weight: bold;
            color: #666;
        }
        
        .stock-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .summary-card {
            background: white;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            border-left: 4px solid #007bff;
        }
        
        .summary-card.current { border-left-color: #28a745; }
        .summary-card.received { border-left-color: #17a2b8; }
        .summary-card.sold { border-left-color: #dc3545; }
        .summary-card.minimum { border-left-color: #ffc107; }
        
        .summary-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .summary-label {
            color: #666;
            font-size: 14px;
        }
        
        .filters {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .filters form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filters label {
            font-weight: bold;
        }
        
        .filters select, .filters input {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }
        
        .filters button {
            background: #007bff;
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .filters button:hover {
            background: #0056b3;
        }
        
        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 12px 8px;
            text-align: left;
        }
        
        th {
            background-color: #f8f9fa;
            font-weight: bold;
            position: sticky;
            top: 0;
        }
        
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        tr:hover {
            background-color: #f1f1f1;
        }
        
        .positive {
            color: #28a745;
            font-weight: bold;
        }
        
        .negative {
            color: #dc3545;
            font-weight: bold;
        }
        
        .adjustment {
            color: #ffc107;
            font-weight: bold;
        }
        
        .quantity-change {
            text-align: center;
            font-weight: bold;
        }
        
        .running-balance {
            text-align: center;
            background-color: #e9ecef;
            font-weight: bold;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }
        
        .back-link {
            display: inline-block;
            background: #6c757d;
            color: white;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 3px;
            margin-bottom: 10px;
        }
        
        .back-link:hover {
            background: #545b62;
        }
        
        .export-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 3px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .export-btn:hover {
            background: #218838;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                gap: 10px;
            }
            
            .product-details {
                grid-template-columns: 1fr;
            }
            
            .stock-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters form {
                flex-direction: column;
                align-items: stretch;
            }
            
            table {
                font-size: 14px;
            }
            
            th, td {
                padding: 8px 4px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Transaction History</h1>
            <div>
                <a href="stock_report.php" class="back-link">← Back to Stock Report</a>
                <a href="?product_id=<?php echo $product_id; ?>&export=csv" class="export-btn">Export CSV</a>
            </div>
        </div>
        
        <div class="product-info">
            <h2><?php echo htmlspecialchars($product['name']); ?></h2>
            <div class="product-details">
                <div class="detail-item">
                    <span class="detail-label">SKU:</span>
                    <span><?php echo htmlspecialchars($product['sku']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Product ID:</span>
                    <span><?php echo $product['id']; ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">QR Code:</span>
                    <span><?php echo htmlspecialchars($product['qr_code'] ?: 'Not Generated'); ?></span>
                </div>
                <?php if ($stock_summary && $stock_summary['last_updated']): ?>
                <div class="detail-item">
                    <span class="detail-label">Last Updated:</span>
                    <span><?php echo date('Y-m-d H:i:s', strtotime($stock_summary['last_updated'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($stock_summary): ?>
        <div class="stock-summary">
            <div class="summary-card current">
                <div class="summary-value"><?php echo $stock_summary['current_stock']; ?></div>
                <div class="summary-label">Current Stock</div>
            </div>
            <div class="summary-card received">
                <div class="summary-value"><?php echo $stock_summary['total_received']; ?></div>
                <div class="summary-label">Total Received</div>
            </div>
            <div class="summary-card sold">
                <div class="summary-value"><?php echo $stock_summary['total_sold']; ?></div>
                <div class="summary-label">Total Sold</div>
            </div>
            <div class="summary-card minimum">
                <div class="summary-value"><?php echo $stock_summary['minimum_stock_level']; ?></div>
                <div class="summary-label">Minimum Level</div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="filters">
            <form method="GET">
                <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                <label>Show Records:</label>
                <select name="limit" onchange="this.form.submit()">
                    <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                    <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
                    <option value="250" <?php echo $limit == 250 ? 'selected' : ''; ?>>250</option>
                    <option value="500" <?php echo $limit == 500 ? 'selected' : ''; ?>>500</option>
                </select>
            </form>
        </div>
        
        <?php if (empty($transactions)): ?>
            <div class="no-data">
                <h3>No transaction history found</h3>
                <p>There are no recorded transactions for this product yet.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Transaction Type</th>
                        <th>Quantity</th>
                        <th>Running Balance</th>
                        <th>Reference</th>
                        <th>User</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($transaction['transaction_date'])); ?></td>
                            <td class="<?php echo getTransactionClass($transaction['transaction_type']); ?>">
                                <?php echo formatTransactionType($transaction['transaction_type']); ?>
                            </td>
                            <td class="quantity-change <?php echo getTransactionClass($transaction['transaction_type']); ?>">
                                <?php 
                                $sign = in_array($transaction['transaction_type'], ['stock_in', 'return']) ? '+' : '-';
                                echo $sign . $transaction['quantity'];
                                ?>
                            </td>
                            <td class="running-balance"><?php echo $transaction['running_balance']; ?></td>
                            <td>
                                <?php if ($transaction['reference_type'] && $transaction['reference_id']): ?>
                                    <?php echo htmlspecialchars(ucfirst($transaction['reference_type'])); ?>
                                    #<?php echo $transaction['reference_id']; ?>
                                    <?php if ($transaction['reference_number']): ?>
                                        <br><small><?php echo htmlspecialchars($transaction['reference_number']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <em>No reference</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($transaction['full_name'] ?: $transaction['username']); ?>
                                <br><small>@<?php echo htmlspecialchars($transaction['username']); ?></small>
                            </td>
                            <td>
                                <?php if ($transaction['notes']): ?>
                                    <?php echo htmlspecialchars($transaction['notes']); ?>
                                <?php else: ?>
                                    <em>No notes</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top: 20px; text-align: center; color: #666;">
                Showing <?php echo count($transactions); ?> most recent transactions
                <?php if (count($transactions) == $limit): ?>
                    <br><small>There may be more transactions. Increase the limit to see more records.</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-refresh functionality (optional)
        function refreshPage() {
            if (confirm('Refresh the page to see the latest transactions?')) {
                window.location.reload();
            }
        }
        
        // Add refresh button functionality
        document.addEventListener('DOMContentLoaded', function() {
            // You can add a refresh button or auto-refresh timer here if needed
        });
    </script>
</body>
</html>

<?php
// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="transaction_history_' . $product['sku'] . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'Product',
        'SKU',
        'Date & Time',
        'Transaction Type',
        'Quantity',
        'Running Balance',
        'Reference Type',
        'Reference ID',
        'Reference Number',
        'User',
        'Notes'
    ]);
    
    // CSV data
    foreach ($transactions as $transaction) {
        $sign = in_array($transaction['transaction_type'], ['stock_in', 'return']) ? '+' : '-';
        fputcsv($output, [
            $product['name'],
            $product['sku'],
            date('Y-m-d H:i:s', strtotime($transaction['transaction_date'])),
            formatTransactionType($transaction['transaction_type']),
            $sign . $transaction['quantity'],
            $transaction['running_balance'],
            $transaction['reference_type'],
            $transaction['reference_id'],
            $transaction['reference_number'],
            $transaction['full_name'] ?: $transaction['username'],
            $transaction['notes']
        ]);
    }
    
    fclose($output);
    exit;
}
?>