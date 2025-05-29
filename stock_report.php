<?php
session_start();
require_once 'InventoryManager.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$inventory = new InventoryManager();

// Handle filter parameters
$filter_status = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'name';
$sort_order = $_GET['order'] ?? 'ASC';

// Get stock report with filters
$stock_report = $inventory->getStockReport($filter_status, $search_term, $sort_by, $sort_order);

// Calculate summary statistics
$total_products = count($stock_report);
$out_of_stock = array_filter($stock_report, fn($item) => $item['stock_status'] === 'OUT_OF_STOCK');
$low_stock = array_filter($stock_report, fn($item) => $item['stock_status'] === 'LOW_STOCK');
$in_stock = array_filter($stock_report, fn($item) => $item['stock_status'] === 'IN_STOCK');

$total_value = array_sum(array_column($stock_report, 'stock_value'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Report - Supplies Direct System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1em;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid;
        }

        .stat-card.total { border-left-color: #3498db; }
        .stat-card.in-stock { border-left-color: #2ecc71; }
        .stat-card.low-stock { border-left-color: #f39c12; }
        .stat-card.out-of-stock { border-left-color: #e74c3c; }

        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .controls {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .controls-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }

        .form-group input,
        .form-group select {
            padding: 10px;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th {
            background: #f8f9fa;
            padding: 15px 10px;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e9ecef;
            position: sticky;
            top: 0;
            cursor: pointer;
            user-select: none;
        }

        th:hover {
            background: #e9ecef;
        }

        th .sort-indicator {
            margin-left: 5px;
            opacity: 0.5;
        }

        th.active .sort-indicator {
            opacity: 1;
        }

        td {
            padding: 12px 10px;
            border-bottom: 1px solid #e9ecef;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-in-stock {
            background: #d4edda;
            color: #155724;
        }

        .status-low-stock {
            background: #fff3cd;
            color: #856404;
        }

        .status-out-of-stock {
            background: #f8d7da;
            color: #721c24;
        }

        .qr-code {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }

        .actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .actions .btn {
            padding: 5px 10px;
            font-size: 12px;
            min-width: auto;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-data i {
            font-size: 3em;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: flex-end;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .controls-row {
                grid-template-columns: 1fr;
            }

            .export-buttons {
                justify-content: center;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px 5px;
            }
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .alert-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 20px;
        }

        .page-info {
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📊 Stock Report</h1>
            <p>Real-time inventory status and analytics</p>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-number"><?php echo $total_products; ?></div>
                <div class="stat-label">Total Products</div>
            </div>
            <div class="stat-card in-stock">
                <div class="stat-number"><?php echo count($in_stock); ?></div>
                <div class="stat-label">In Stock</div>
            </div>
            <div class="stat-card low-stock">
                <div class="stat-number"><?php echo count($low_stock); ?></div>
                <div class="stat-label">Low Stock</div>
            </div>
            <div class="stat-card out-of-stock">
                <div class="stat-number"><?php echo count($out_of_stock); ?></div>
                <div class="stat-label">Out of Stock</div>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls">
            <form method="GET" id="filterForm">
                <div class="controls-row">
                    <div class="form-group">
                        <label for="search">Search Products</label>
                        <input type="text" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search_term); ?>" 
                               placeholder="Search by name, SKU, or QR code...">
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Filter by Status</label>
                        <select id="status" name="status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="IN_STOCK" <?php echo $filter_status === 'IN_STOCK' ? 'selected' : ''; ?>>In Stock</option>
                            <option value="LOW_STOCK" <?php echo $filter_status === 'LOW_STOCK' ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="OUT_OF_STOCK" <?php echo $filter_status === 'OUT_OF_STOCK' ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="sort">Sort By</label>
                        <select id="sort" name="sort">
                            <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Product Name</option>
                            <option value="sku" <?php echo $sort_by === 'sku' ? 'selected' : ''; ?>>SKU</option>
                            <option value="current_stock" <?php echo $sort_by === 'current_stock' ? 'selected' : ''; ?>>Current Stock</option>
                            <option value="total_sold" <?php echo $sort_by === 'total_sold' ? 'selected' : ''; ?>>Total Sold</option>
                            <option value="stock_status" <?php echo $sort_by === 'stock_status' ? 'selected' : ''; ?>>Status</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="order">Order</label>
                        <select id="order" name="order">
                            <option value="ASC" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                            <option value="DESC" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Export Buttons -->
        <div class="export-buttons">
            <button onclick="exportToCSV()" class="btn btn-success">📄 Export to CSV</button>
            <button onclick="printReport()" class="btn btn-secondary">🖨️ Print Report</button>
            <a href="stock_in.php" class="btn btn-primary">➕ Add Stock</a>
            <a href="stock_out.php" class="btn btn-warning">📤 Scan Out</a>
        </div>

        <!-- Alert for Critical Stock Levels -->
        <?php if (count($out_of_stock) > 0 || count($low_stock) > 0): ?>
        <div class="alert alert-info">
            <strong>⚠️ Attention Required:</strong> 
            <?php if (count($out_of_stock) > 0): ?>
                <?php echo count($out_of_stock); ?> product(s) are out of stock.
            <?php endif; ?>
            <?php if (count($low_stock) > 0): ?>
                <?php echo count($low_stock); ?> product(s) have low stock levels.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Stock Report Table -->
        <div class="table-container">
            <?php if (empty($stock_report)): ?>
                <div class="no-data">
                    <div style="font-size: 3em; margin-bottom: 15px; opacity: 0.3;">📦</div>
                    <h3>No Products Found</h3>
                    <p>No products match your current filter criteria.</p>
                    <a href="?" class="btn btn-primary" style="margin-top: 15px;">Clear Filters</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="stockTable">
                        <thead>
                            <tr>
                                <th onclick="sortTable('name')">
                                    Product Name 
                                    <span class="sort-indicator <?php echo $sort_by === 'name' ? 'active' : ''; ?>">
                                        <?php echo $sort_by === 'name' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕'; ?>
                                    </span>
                                </th>
                                <th onclick="sortTable('sku')">
                                    SKU 
                                    <span class="sort-indicator <?php echo $sort_by === 'sku' ? 'active' : ''; ?>">
                                        <?php echo $sort_by === 'sku' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕'; ?>
                                    </span>
                                </th>
                                <th>QR Code</th>
                                <th onclick="sortTable('current_stock')">
                                    Current Stock 
                                    <span class="sort-indicator <?php echo $sort_by === 'current_stock' ? 'active' : ''; ?>">
                                        <?php echo $sort_by === 'current_stock' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕'; ?>
                                    </span>
                                </th>
                                <th onclick="sortTable('total_received')">
                                    Total Received 
                                    <span class="sort-indicator <?php echo $sort_by === 'total_received' ? 'active' : ''; ?>">
                                        <?php echo $sort_by === 'total_received' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕'; ?>
                                    </span>
                                </th>
                                <th onclick="sortTable('total_sold')">
                                    Total Sold 
                                    <span class="sort-indicator <?php echo $sort_by === 'total_sold' ? 'active' : ''; ?>">
                                        <?php echo $sort_by === 'total_sold' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕'; ?>
                                    </span>
                                </th>
                                <th>Min Level</th>
                                <th onclick="sortTable('stock_status')">
                                    Status 
                                    <span class="sort-indicator <?php echo $sort_by === 'stock_status' ? 'active' : ''; ?>">
                                        <?php echo $sort_by === 'stock_status' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕'; ?>
                                    </span>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stock_report as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                        <?php if (!empty($item['description'])): ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars(substr($item['description'], 0, 50)); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['sku']); ?></td>
                                    <td>
                                        <?php if ($item['qr_code']): ?>
                                            <span class="qr-code"><?php echo htmlspecialchars($item['qr_code']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #999; font-style: italic;">Not Generated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong style="font-size: 1.1em;"><?php echo $item['current_stock']; ?></strong>
                                    </td>
                                    <td><?php echo $item['total_received']; ?></td>
                                    <td><?php echo $item['total_sold']; ?></td>
                                    <td><?php echo $item['minimum_stock_level']; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower(str_replace('_', '-', $item['stock_status'])); ?>">
                                            <?php echo str_replace('_', ' ', $item['stock_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a href="transaction_history.php?product_id=<?php echo $item['id']; ?>" 
                                               class="btn btn-secondary" title="View Transaction History">📊</a>
                                            
                                            <?php if ($item['current_stock'] <= $item['minimum_stock_level']): ?>
                                                <a href="stock_in.php?product_id=<?php echo $item['id']; ?>" 
                                                   class="btn btn-success" title="Add Stock">➕</a>
                                            <?php endif; ?>
                                            
                                            <?php if (!$item['qr_code']): ?>
                                                <a href="generate_qr.php?product_id=<?php echo $item['id']; ?>" 
                                                   class="btn btn-primary" title="Generate QR Code">🏷️</a>
                                            <?php endif; ?>
                                            
                                            <a href="product_details.php?id=<?php echo $item['id']; ?>" 
                                               class="btn btn-secondary" title="View Details">👁️</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Page Info -->
        <div class="pagination">
            <div class="page-info">
                Showing <?php echo count($stock_report); ?> of <?php echo $total_products; ?> products
                <?php if ($search_term || $filter_status !== 'all'): ?>
                    | <a href="?" style="color: #667eea;">Clear all filters</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-submit form on filter changes
        document.getElementById('status').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        document.getElementById('sort').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        document.getElementById('order').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        // Search with debounce
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });

        // Sort table function
        function sortTable(column) {
            const currentSort = new URLSearchParams(window.location.search).get('sort');
            const currentOrder = new URLSearchParams(window.location.search).get('order') || 'ASC';
            
            const newOrder = (currentSort === column && currentOrder === 'ASC') ? 'DESC' : 'ASC';
            
            const url = new URL(window.location);
            url.searchParams.set('sort', column);
            url.searchParams.set('order', newOrder);
            
            window.location.href = url.toString();
        }

        // Export to CSV function
        function exportToCSV() {
            const table = document.getElementById('stockTable');
            const rows = Array.from(table.querySelectorAll('tr'));
            
            const csvContent = rows.map(row => {
                const cells = Array.from(row.querySelectorAll('th, td'));
                return cells.map(cell => {
                    let text = cell.textContent.trim();
                    // Remove action buttons and sort indicators
                    text = text.replace(/[📊➕🏷️👁️↑↓↕]/g, '').trim();
                    // Escape quotes and wrap in quotes if contains comma
                    if (text.includes(',') || text.includes('"')) {
                        text = '"' + text.replace(/"/g, '""') + '"';
                    }
                    return text;
                }).join(',');
            }).join('\n');
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'stock_report_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Print report function
        function printReport() {
            window.print();
        }

        // Highlight critical stock levels
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const statusBadge = row.querySelector('.status-badge');
                if (statusBadge) {
                    if (statusBadge.textContent.includes('OUT OF STOCK')) {
                        row.style.backgroundColor = '#fff5f5';
                    } else if (statusBadge.textContent.includes('LOW STOCK')) {
                        row.style.backgroundColor = '#fffdf0';
                    }
                }
            });
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'f':
                        e.preventDefault();
                        document.getElementById('search').focus();
                        break;
                    case 'p':
                        e.preventDefault();
                        printReport();
                        break;
                    case 's':
                        e.preventDefault();
                        exportToCSV();
                        break;
                }
            }
        });
    </script>
</body>
</html>