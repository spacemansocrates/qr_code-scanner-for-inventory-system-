<?php
require_once 'database.php';
require_once 'BarcodeGenerator.php';

class InventoryManager {
    private $conn;
    private $db;

    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->connect();
    }

    /**
     * Generate barcode for a product (if not exists)
     */
    public function generateProductBarcode($product_id) {
        // Check if product already has a code stored
        $stmt = $this->conn->prepare("SELECT qr_code FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['qr_code'])) {
            return $result['qr_code'];
        }

        // Generate unique barcode content
        $barcode_content = "PROD_" . str_pad($product_id, 6, "0", STR_PAD_LEFT);

        // Update product with barcode
        $stmt = $this->conn->prepare("UPDATE products SET qr_code = ? WHERE id = ?");
        $stmt->execute([$barcode_content, $product_id]);

        return $barcode_content;
    }

    /**
     * Add stock to inventory
     */
    public function addStock($product_id, $quantity, $user_id, $reference_type = 'receipt', $reference_id = null, $notes = '') {
        try {
            $this->conn->beginTransaction();
            
            // Get current stock
            $current_stock = $this->getCurrentStock($product_id);
            $new_stock = $current_stock + $quantity;
            
            // Update or insert inventory_stock
            // Note: The original SQL had an issue where it would always insert 0 for total_sold on new product.
            // Corrected to ensure total_sold is maintained or initialized correctly.
            $stmt = $this->conn->prepare("
                INSERT INTO inventory_stock (product_id, quantity_in_stock, total_received, total_sold, minimum_stock_level) 
                VALUES (?, ?, ?, 0, 0) 
                ON DUPLICATE KEY UPDATE 
                quantity_in_stock = quantity_in_stock + VALUES(quantity_in_stock), 
                total_received = total_received + VALUES(total_received)
            ");
             // The VALUES(quantity_in_stock) and VALUES(total_received) in ON DUPLICATE KEY UPDATE
             // refer to the values that would have been inserted.
             // So, for quantity_in_stock, it should be just $quantity (the amount being added).
             // For total_received, it should also be $quantity.
             // The quantity_in_stock in the INSERT part should be the new_stock.

            $stmt_insert_update = $this->conn->prepare("
                INSERT INTO inventory_stock (product_id, quantity_in_stock, total_received, total_sold, minimum_stock_level)
                VALUES (:product_id, :quantity_in_stock_insert, :total_received_insert, 0, 0)
                ON DUPLICATE KEY UPDATE
                quantity_in_stock = quantity_in_stock + :quantity_update,
                total_received = total_received + :total_received_update
            ");
            $stmt_insert_update->execute([
                ':product_id' => $product_id,
                ':quantity_in_stock_insert' => $new_stock, // Initial stock level if new
                ':total_received_insert' => $quantity,     // Initial received quantity if new
                ':quantity_update' => $quantity,           // Quantity to add if duplicate
                ':total_received_update' => $quantity      // Quantity to add to total_received if duplicate
            ]);
            
            // Record transaction
            $this->recordTransaction($product_id, 'stock_in', $quantity, $new_stock, $reference_type, $reference_id, $user_id, $notes);
            
            $this->conn->commit();
            return ['success' => true, 'new_stock' => $new_stock];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Remove stock from inventory (when scanned out)
     * Assumes quantity to remove is 1 per scan, as per original logic
     */
    public function removeStock($qr_code, $user_id, $reference_type = 'sale', $reference_id = null, $notes = '') {
        try {
            $this->conn->beginTransaction();
            
            // Get product ID from barcode
            $stmt = $this->conn->prepare("SELECT id, name, sku FROM products WHERE qr_code = ?");
            $stmt->execute([$qr_code]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception("Product not found for code: " . htmlspecialchars($qr_code));
            }
            
            $product_id = $product['id'];
            $current_stock = $this->getCurrentStock($product_id);
            
            if ($current_stock <= 0) {
                throw new Exception("No stock available for product: " . htmlspecialchars($product['name']));
            }
            
            $quantity_to_remove = 1; // As per original logic, one scan removes one item
            $new_stock = $current_stock - $quantity_to_remove;
            
            // Update inventory_stock
            $stmt = $this->conn->prepare("
                UPDATE inventory_stock 
                SET quantity_in_stock = quantity_in_stock - ?, 
                    total_sold = total_sold + ? 
                WHERE product_id = ?
            ");
            $stmt->execute([$quantity_to_remove, $quantity_to_remove, $product_id]);
            
            // Record transaction
            $this->recordTransaction($product_id, 'stock_out', $quantity_to_remove, $new_stock, $reference_type, $reference_id, $user_id, $notes);
            
            $this->conn->commit();
            return [
                'success' => true, 
                'product' => $product, 
                'new_stock' => $new_stock,
                'message' => "Item scanned out successfully. Remaining stock: " . $new_stock
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get current stock for a product
     */
    public function getCurrentStock($product_id) {
        $stmt = $this->conn->prepare("SELECT quantity_in_stock FROM inventory_stock WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['quantity_in_stock'] : 0;
    }

    /**
     * Record stock transaction
     */
    private function recordTransaction($product_id, $transaction_type, $quantity, $running_balance, $reference_type, $reference_id, $user_id, $notes) {
        $stmt = $this->conn->prepare("
            INSERT INTO stock_transactions 
            (product_id, transaction_type, quantity, running_balance, reference_type, reference_id, scanned_by_user_id, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        // Ensure reference_id is null if not provided or empty, to match database schema (allows NULL)
        $reference_id = !empty($reference_id) ? (int)$reference_id : null;
        $stmt->execute([$product_id, $transaction_type, $quantity, $running_balance, $reference_type, $reference_id, $user_id, $notes]);
    }

    /**
     * Generate printable barcodes for stock addition
     */
    public function generatePrintableBarcodes($product_id, $quantity_to_print, $user_id) {
        // Generate barcode for product if it doesn't exist, or get existing
        $barcode_content = $this->generateProductBarcode($product_id);
        
        // Get product details
        $stmt_product = $this->conn->prepare("SELECT name, sku, description FROM products WHERE id = ?");
        $stmt_product->execute([$product_id]);
        $product = $stmt_product->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
             return ['success' => false, 'error' => 'Product not found.'];
        }
        
        // Create batch record
        $batch_reference = 'BATCH_' . date('YmdHis') . '_' . $product_id;
        $stmt_batch = $this->conn->prepare("
            INSERT INTO qr_print_batches (product_id, batch_reference, quantity_printed, printed_by_user_id) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt_batch->execute([$product_id, $batch_reference, $quantity_to_print, $user_id]);
        
        // Generate barcode image using built-in generator
        try {
            $barcode_image_base64 = BarcodeGenerator::generate($barcode_content);
        } catch (Exception $e) {
            error_log('Barcode generation error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to generate barcode image. ' . $e->getMessage()];
        }
        
        return [
            'success' => true,
            'barcode_content' => $barcode_content,
            'barcode_image_base64' => $barcode_image_base64,
            'product' => $product,
            'quantity' => $quantity_to_print, // Use the parameter name for clarity
            'batch_reference' => $batch_reference
        ];
    }

    /**
     * Get stock report
     */
  public function getStockReport($filter_status = 'all', $search_term = '', $sort_by = 'name', $sort_order = 'ASC') {
        // 1. Initialize WHERE clauses and parameters
        $where_clauses = [];
        $params = [];

        // 2. Add filter by status
        if ($filter_status !== 'all') {
            // Use the CASE statement's logic for filtering
            $where_clauses[] = "CASE
                                    WHEN COALESCE(i.quantity_in_stock, 0) = 0 THEN 'OUT_OF_STOCK'
                                    WHEN COALESCE(i.quantity_in_stock, 0) <= COALESCE(i.minimum_stock_level, 0) THEN 'LOW_STOCK'
                                    ELSE 'IN_STOCK'
                                END = ?";
            $params[] = $filter_status;
        }

        // 3. Add search term
        if (!empty($search_term)) {
            $search_term_like = '%' . $search_term . '%';
            $where_clauses[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.qr_code LIKE ?)";
            $params[] = $search_term_like;
            $params[] = $search_term_like;
            $params[] = $search_term_like;
        }

        // 4. Construct the WHERE part of the query
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = ' WHERE ' . implode(' AND ', $where_clauses);
        }

        // 5. Validate and set sorting parameters
        // IMPORTANT: Only allow predefined columns for sorting to prevent SQL Injection
        $allowed_sort_columns = ['name', 'sku', 'current_stock', 'total_received', 'total_sold', 'minimum_stock_level', 'stock_status'];
        $allowed_sort_order = ['ASC', 'DESC'];

        if (!in_array($sort_by, $allowed_sort_columns)) {
            $sort_by = 'name'; // Default to 'name' if invalid
        }
        if (!in_array(strtoupper($sort_order), $allowed_sort_order)) {
            $sort_order = 'ASC'; // Default to 'ASC' if invalid
        }

        // If sorting by 'stock_status', we need to use the CASE statement again
        $order_by_column = $sort_by;
        if ($sort_by === 'stock_status') {
            $order_by_column = "CASE
                                    WHEN COALESCE(i.quantity_in_stock, 0) = 0 THEN 'OUT_OF_STOCK'
                                    WHEN COALESCE(i.quantity_in_stock, 0) <= COALESCE(i.minimum_stock_level, 0) THEN 'LOW_STOCK'
                                    ELSE 'IN_STOCK'
                                END";
        }


        $stmt = $this->conn->prepare("
            SELECT
                p.id,
                p.name,
                p.sku,
                p.qr_code,
                p.description, -- Added description as it's used in stock_report.php
                COALESCE(i.quantity_in_stock, 0) as current_stock,
                COALESCE(i.total_received, 0) as total_received,
                COALESCE(i.total_sold, 0) as total_sold,
                COALESCE(i.minimum_stock_level, 0) as minimum_stock_level,
                CASE
                    WHEN COALESCE(i.quantity_in_stock, 0) = 0 THEN 'OUT_OF_STOCK'
                    WHEN COALESCE(i.quantity_in_stock, 0) <= COALESCE(i.minimum_stock_level, 0) THEN 'LOW_STOCK'
                    ELSE 'IN_STOCK'
                END as stock_status
            FROM products p
            LEFT JOIN inventory_stock i ON p.id = i.product_id
            " . $where_sql . "
            ORDER BY " . $order_by_column . " " . $sort_order
        );

        // 6. Execute the statement with parameters
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get transaction history for a product
     */
    public function getTransactionHistory($product_id, $limit = 50) {
        // Validate limit to prevent excessively large queries
        $limit = max(1, min((int)$limit, 500)); // Ensure limit is between 1 and 500

        $stmt = $this->conn->prepare("
            SELECT 
                st.*,
                u.username, /* Assuming 'users' table has 'username' */
                u.id as user_id_from_users_table, /* Assuming 'users' table has 'full_name', adjust if not */
                p.name as product_name,
                p.sku
            FROM stock_transactions st
            JOIN users u ON st.scanned_by_user_id = u.id /* Make sure 'users' table and 'id' column exist */
            JOIN products p ON st.product_id = p.id
            WHERE st.product_id = ?
            ORDER BY st.created_at DESC
            LIMIT ?
        ");
        // Bind parameters explicitly for type safety, especially for LIMIT
        $stmt->bindParam(1, $product_id, PDO::PARAM_INT);
        $stmt->bindParam(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>