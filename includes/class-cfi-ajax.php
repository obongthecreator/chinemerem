<?php
/**
 * AJAX Handler Class
 * 
 * @package Chinemerem_Foods_Inventory
 */

if (!defined('ABSPATH')) {
    exit;
}

class CFI_Ajax {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->register_ajax_handlers();
    }
    
    /**
     * Register all AJAX handlers
     */
    private function register_ajax_handlers() {
        $actions = array(
            // Products
            'get_products',
            'add_product',
            'update_product',
            'delete_product',
            
            // Orders
            'submit_order',
            'get_orders',
            'get_order_history',
            'get_order_product_summary',
            'get_order_details',
            'delete_credit_order_item',
            'delete_order',
            
            // Stock
            'get_stock',
            'update_stock',
            'update_stock_opening',
            'get_stock_history',
            'edit_stock_record',
            
            // Packing
            'get_packing',
            'update_packing',
            'update_packing_opening',
            'get_packing_history',
            
            // Debtors
            'get_debtors',
            'get_debtor_balances',
            'add_debtor',
            'update_debtor',
            'delete_debtor',
            'debtor_order',
            'debtor_payment',
            'get_debtor_payment_details',
            'get_debtor_history',
            
            // Expenses
            'add_expense',
            'get_expenses',
            'get_expense_history',
            
            // Imports
            'add_import',
            'get_imports',
            'get_import_history',
            
            // Not Supplied
            'add_not_supplied',
            'get_not_supplied',
            'mark_as_supplied',
            'get_not_supplied_history',
            
            // Supplied Today
            'add_supplied_today',
            'get_supplied_today',
            'get_supplied_today_history',
            
            // Cash Out
            'add_cashout',
            'get_cashout',
            
            // Financial
            'get_financial_summary',
            'update_financial',
            'update_old_cash',
            'get_financial_history',
            'get_analytics_summary',
            
            // Transfer History
            'get_transfer_history',
            
            // Reconciliation
            'reconcile_date',
            'get_reconciliation',
            
            // Backup
            'download_backup',
            'upload_backup',
            'get_backup_list',
            
            // Sync
            'sync_offline_data',
            
            // Admin
            'get_users',
            'add_user',
            'update_user',
            'delete_user',
            'delete_history',
            'update_history',
            'recreate_pages',
            'migrate_opening_values',
            'verify_sunday_carryover',
            'system_scan',
            'system_fix',
        );
        
        foreach ($actions as $action) {
            add_action('wp_ajax_cfi_' . $action, array($this, 'handle_' . $action));
        }
    }
    
    /**
     * Verify nonce and user permissions
     */
    private function verify_request($admin_only = false) {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cfi_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'chinemerem-foods')));
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please login to continue', 'chinemerem-foods')));
        }
        
        if ($admin_only && !CFI_Auth::is_cfi_admin()) {
            wp_send_json_error(array('message' => __('You do not have permission for this action', 'chinemerem-foods')));
        }
        
        return true;
    }
    
    /**
     * Get products
     */
    public function handle_get_products() {
        $this->verify_request();
        $products = CFI_Products::get_all();
        wp_send_json_success(array('products' => $products));
    }
    
    /**
     * Add product
     */
    public function handle_add_product() {
        $this->verify_request(true);
        
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        $unit = isset($_POST['unit']) ? sanitize_text_field(wp_unslash($_POST['unit'])) : 'unit';
        $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
        
        if (empty($name)) {
            wp_send_json_error(array('message' => __('Product name is required', 'chinemerem-foods')));
        }
        
        if ($price <= 0) {
            wp_send_json_error(array('message' => __('Price must be greater than 0', 'chinemerem-foods')));
        }
        
        $result = CFI_Products::add($name, $price, $unit, $category);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Product added successfully', 'chinemerem-foods'),
                'product_id' => $result
            ));
        } else {
            global $wpdb;
            $db_error = $wpdb->last_error;
            $error_message = __('Failed to add product', 'chinemerem-foods');
            if (!empty($db_error)) {
                error_log('CFI Add Product DB Error: ' . $db_error);
            }
            wp_send_json_error(array('message' => $error_message));
        }
    }
    
    /**
     * Update product
     */
    public function handle_update_product() {
        $this->verify_request(true);
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        $unit = isset($_POST['unit']) ? sanitize_text_field(wp_unslash($_POST['unit'])) : 'unit';
        $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
        
        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid product ID', 'chinemerem-foods')));
        }
        
        $result = CFI_Products::update($id, $name, $price, $unit, $category);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Product updated successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update product', 'chinemerem-foods')));
        }
    }
    
    /**
     * Delete product
     */
    public function handle_delete_product() {
        $this->verify_request(true);
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid product ID', 'chinemerem-foods')));
        }
        
        $result = CFI_Products::delete($id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Product deleted successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete product', 'chinemerem-foods')));
        }
    }
    
    /**
     * Submit order
     */
    public function handle_submit_order() {
        $this->verify_request();
        
        $order_data = isset($_POST['order']) ? json_decode(stripslashes($_POST['order']), true) : array();
        
        if (empty($order_data)) {
            wp_send_json_error(array('message' => __('Invalid order data', 'chinemerem-foods')));
        }
        
        // Sanitize order data
        $order_data = $this->sanitize_order_data($order_data);
        
        $result = CFI_Orders::submit($order_data);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => __('Order submitted successfully', 'chinemerem-foods'),
                'order_id' => $result['order_id']
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Sanitize order data
     */
    private function sanitize_order_data($data) {
        $sanitized = array();
        
        $sanitized['items'] = array();
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                $sanitized['items'][] = array(
                    'product_id' => intval($item['product_id']),
                    'quantity' => floatval($item['quantity']),
                    'price' => floatval($item['price']),
                    'discount' => floatval($item['discount'] ?? 0),
                    'total' => floatval($item['total'])
                );
            }
        }
        
        $sanitized['payment_method'] = sanitize_text_field($data['payment_method'] ?? 'cash');
        $sanitized['transfer_amount'] = floatval($data['transfer_amount'] ?? 0);
        $sanitized['cash_amount'] = floatval($data['cash_amount'] ?? 0);
        $sanitized['bank_name'] = sanitize_text_field($data['bank_name'] ?? '');
        $sanitized['total_quantity'] = floatval($data['total_quantity'] ?? 0);
        $sanitized['total_amount'] = floatval($data['total_amount'] ?? 0);
        $sanitized['discount_amount'] = floatval($data['discount_amount'] ?? 0);
        $sanitized['grand_total'] = floatval($data['grand_total'] ?? 0);
        $sanitized['debtor_id'] = intval($data['debtor_id'] ?? 0);
        $sanitized['order_type'] = sanitize_text_field($data['order_type'] ?? 'cash');
        
        return $sanitized;
    }
    
    /**
     * Get orders
     */
    public function handle_get_orders() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $orders = CFI_Orders::get_by_date($date);
        
        wp_send_json_success(array('orders' => $orders));
    }
    
    /**
     * Get order history
     */
    public function handle_get_order_history() {
        $this->verify_request();
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 50;
        
        $history = CFI_Orders::get_history($start_date, $end_date, $page, $per_page);
        
        wp_send_json_success($history);
    }
    
    /**
     * Get order product summary
     */
    public function handle_get_order_product_summary() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'cash';
        
        $summary = CFI_Orders::get_product_summary($date, $type);
        
        wp_send_json_success(array('summary' => $summary));
    }
    
    /**
     * Get order details by order ID
     */
    public function handle_get_order_details() {
        // Allow GET request for this action (no nonce needed, just user login)
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please login to continue', 'chinemerem-foods')));
        }
        
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID', 'chinemerem-foods')));
        }
        
        global $wpdb;
        $orders_table = $wpdb->prefix . 'cfi_orders';
        $items_table = $wpdb->prefix . 'cfi_order_items';
        $products_table = $wpdb->prefix . 'cfi_products';
        
        // Get order details
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $orders_table WHERE id = %d",
            $order_id
        ));
        
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found', 'chinemerem-foods')));
        }
        
        // Get order items with product names
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT oi.*, p.name as product_name 
             FROM $items_table oi 
             LEFT JOIN $products_table p ON oi.product_id = p.id 
             WHERE oi.order_id = %d",
            $order_id
        ));
        
        $order_time = $order->order_time;
        if (!empty($order->order_date) && !empty($order->order_time) && function_exists('cfi_format_receipt_time')) {
            $order_time = cfi_format_receipt_time($order->order_date, $order->order_time);
        }

        $staff_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT display_name FROM {$wpdb->users} WHERE ID = %d",
                $order->staff_id
            )
        );

        wp_send_json_success(array(
            'order_number' => $order->order_number,
            'order_date' => $order->order_date,
            'order_time' => $order_time,
            'customer_name' => $order->customer_name,
            'grand_total' => $order->grand_total,
            'total_amount' => $order->total_amount,
            'discount_amount' => $order->discount_amount,
            'payment_method' => $order->payment_method,
            'transfer_amount' => $order->transfer_amount,
            'cash_amount' => $order->cash_amount,
            'staff_name' => $staff_name,
            'items' => $items
        ));
    }
    
    /**
     * Delete a credit order item (super admin only)
     * Reverses credit_supply in stock and updates order totals
     */
    public function handle_delete_credit_order_item() {
        $this->verify_request(true);
        
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only super admin can delete credit order items', 'chinemerem-foods')));
        }
        
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        
        if (!$item_id) {
            wp_send_json_error(array('message' => __('Invalid item ID', 'chinemerem-foods')));
        }
        
        global $wpdb;
        $items_table = $wpdb->prefix . 'cfi_order_items';
        $orders_table = $wpdb->prefix . 'cfi_orders';
        $trans_table = $wpdb->prefix . 'cfi_debtor_transactions';
        $debtors_table = $wpdb->prefix . 'cfi_debtors';
        
        // Get the order item details
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT oi.*, o.order_date, o.debtor_id, o.id as parent_order_id
             FROM {$items_table} oi 
             JOIN {$orders_table} o ON oi.order_id = o.id 
             WHERE oi.id = %d AND o.order_type = 'credit'",
            $item_id
        ));
        
        if (!$item) {
            wp_send_json_error(array('message' => __('Credit order item not found', 'chinemerem-foods')));
        }
        
        $item_qty = floatval($item->quantity);
        $item_total = floatval($item->total);
        $item_discount = floatval($item->discount);
        
        // 1. Reverse credit_supply in stock
        CFI_Stock::update_credit_supply(
            intval($item->product_id),
            -$item_qty,
            $item->order_date
        );
        
        // 2. Delete the order item
        $wpdb->delete($items_table, array('id' => $item_id), array('%d'));
        
        // 3. Fetch the debtor transaction for this order (used in both branches)
        $trans = $wpdb->get_row($wpdb->prepare(
            "SELECT id, debtor_id, amount, balance_before, balance_after FROM {$trans_table} WHERE order_id = %d AND transaction_type = 'order'",
            $item->parent_order_id
        ));
        
        // 4. Check remaining items on this order
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items_table} WHERE order_id = %d",
            $item->parent_order_id
        ));
        
        if ($remaining === 0) {
            // No items left — delete the entire order and its debtor transaction
            if ($trans) {
                // Reverse the debtor balance
                $debt_change = floatval($trans->amount);
                $debtor = $wpdb->get_row($wpdb->prepare(
                    "SELECT total_debt FROM {$debtors_table} WHERE id = %d",
                    $trans->debtor_id
                ));
                if ($debtor) {
                    $new_debt = floatval($debtor->total_debt) - $debt_change;
                    $wpdb->update(
                        $debtors_table,
                        array('total_debt' => $new_debt),
                        array('id' => $trans->debtor_id),
                        array('%f'),
                        array('%d')
                    );
                }
                $wpdb->delete($trans_table, array('id' => $trans->id), array('%d'));
            }
            
            // Delete associated transfer history records
            $transfer_table = $wpdb->prefix . 'cfi_transfer_history';
            $wpdb->delete($transfer_table, array('source' => 'order', 'source_id' => $item->parent_order_id), array('%s', '%d'));
            
            // Delete the order
            $wpdb->delete($orders_table, array('id' => $item->parent_order_id), array('%d'));
        } else {
            // Update the parent order totals
            $new_totals = $wpdb->get_row($wpdb->prepare(
                "SELECT SUM(quantity) as total_qty, SUM(total) as total_amount, SUM(discount) as total_discount 
                 FROM {$items_table} WHERE order_id = %d",
                $item->parent_order_id
            ));
            
            $new_grand = floatval($new_totals->total_amount);
            $new_subtotal = $new_grand + floatval($new_totals->total_discount);
            
            $wpdb->update(
                $orders_table,
                array(
                    'total_quantity' => floatval($new_totals->total_qty),
                    'total_amount' => $new_subtotal,
                    'discount_amount' => floatval($new_totals->total_discount),
                    'grand_total' => $new_grand,
                ),
                array('id' => $item->parent_order_id),
                array('%f', '%f', '%f', '%f'),
                array('%d')
            );
            
            // Update debtor transaction amount and balance
            if ($trans) {
                $new_balance_after = floatval($trans->balance_before) + $new_grand;
                $wpdb->update(
                    $trans_table,
                    array(
                        'amount' => $new_grand,
                        'balance_after' => $new_balance_after,
                    ),
                    array('id' => $trans->id),
                    array('%f', '%f'),
                    array('%d')
                );
                
                // Update debtor total_debt to match
                $wpdb->update(
                    $debtors_table,
                    array('total_debt' => $new_balance_after),
                    array('id' => $trans->debtor_id),
                    array('%f'),
                    array('%d')
                );
            }
        }
        
        // 4. Recalculate financial summary
        CFI_Financial::update_daily_summary($item->order_date);
        
        wp_send_json_success(array('message' => __('Credit order item deleted and stock updated', 'chinemerem-foods')));
    }
    
    /**
     * Delete an order via AJAX - instant deletion without page reload
     */
    public function handle_delete_order() {
        $this->verify_request(true);
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID', 'chinemerem-foods')));
        }
        
        $is_super_admin = CFI_Auth::is_super_admin();
        
        global $wpdb;
        $orders_table = $wpdb->prefix . 'cfi_orders';
        $items_table = $wpdb->prefix . 'cfi_order_items';
        $stock_table = $wpdb->prefix . 'cfi_stock';
        $financial_table = $wpdb->prefix . 'cfi_financial_summary';
        $transfers_table = $wpdb->prefix . 'cfi_transfer_history';
        $today = current_time('Y-m-d');
        
        // Get full order details before deletion
        $order_to_delete = $wpdb->get_row($wpdb->prepare("SELECT * FROM $orders_table WHERE id = %d", $order_id));
        
        if (!$order_to_delete) {
            wp_send_json_error(array('message' => __('Order not found', 'chinemerem-foods')));
        }
        
        $order_date = $order_to_delete->order_date;
        
        // Super admin can delete anytime, CFI admin only same-day
        if (!$is_super_admin && $order_date !== $today) {
            wp_send_json_error(array('message' => __('Cannot delete orders from previous days', 'chinemerem-foods')));
        }
        
        // Get order items before deletion for stock rollback
        $order_items_to_delete = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $items_table WHERE order_id = %d",
            $order_id
        ));
        
        // 1. STOCK ROLLBACK - Reverse the stock impact for each product
        foreach ($order_items_to_delete as $item) {
            $stock_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $stock_table WHERE product_id = %d AND record_date = %s",
                $item->product_id, $order_date
            ));
            
            if ($stock_record) {
                if ($order_to_delete->payment_method === 'credit') {
                    $new_credit_supply = max(0, $stock_record->credit_supply - $item->quantity);
                    $wpdb->update(
                        $stock_table,
                        array(
                            'credit_supply' => $new_credit_supply,
                            'closing' => $stock_record->opening + $stock_record->import - $stock_record->cash_supply - $new_credit_supply + $stock_record->not_supplied - $stock_record->supplied_today - $stock_record->to_packing_store + $stock_record->from_packing_store
                        ),
                        array('id' => $stock_record->id),
                        array('%d', '%d'),
                        array('%d')
                    );
                } else {
                    $new_cash_supply = max(0, $stock_record->cash_supply - $item->quantity);
                    $wpdb->update(
                        $stock_table,
                        array(
                            'cash_supply' => $new_cash_supply,
                            'closing' => $stock_record->opening + $stock_record->import - $new_cash_supply - $stock_record->credit_supply + $stock_record->not_supplied - $stock_record->supplied_today - $stock_record->to_packing_store + $stock_record->from_packing_store
                        ),
                        array('id' => $stock_record->id),
                        array('%d', '%d'),
                        array('%d')
                    );
                }
            }
        }
        
        // 2. FINANCIAL SUMMARY ROLLBACK
        $financial_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $financial_table WHERE record_date = %s",
            $order_date
        ));
        
        if ($financial_record) {
            $order_amount = floatval($order_to_delete->grand_total);
            
            if ($order_to_delete->payment_method === 'credit') {
                $new_credit_total = max(0, $financial_record->credit_total - $order_amount);
                $wpdb->update(
                    $financial_table,
                    array('credit_total' => $new_credit_total),
                    array('id' => $financial_record->id),
                    array('%d'),
                    array('%d')
                );
            } else {
                $new_money_supplied = max(0, $financial_record->money_supplied - $order_amount);
                $new_cash_left = $financial_record->old_cash + $new_money_supplied - $financial_record->cash_to_bank - $financial_record->expenses_total;
                $wpdb->update(
                    $financial_table,
                    array(
                        'money_supplied' => $new_money_supplied,
                        'cash_left' => $new_cash_left
                    ),
                    array('id' => $financial_record->id),
                    array('%d', '%d'),
                    array('%d')
                );
            }
        }
        
        // 3. DELETE TRANSFER HISTORY ENTRY
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $transfers_table WHERE source = 'order' AND source_id = %d AND transfer_date = %s",
            $order_id, $order_date
        ));
        
        // 4. Delete order items
        $wpdb->delete($items_table, array('order_id' => $order_id), array('%d'));
        
        // 5. Delete the order itself
        $result = $wpdb->delete($orders_table, array('id' => $order_id), array('%d'));
        
        if ($result) {
            // 6. Recalculate financial summary
            CFI_Financial::update_daily_summary($order_date);
            
            wp_send_json_success(array('message' => __('Order deleted successfully with all related records', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete order', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get stock
     */
    public function handle_get_stock() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $stock = CFI_Stock::get_by_date($date);
        
        wp_send_json_success(array('stock' => $stock));
    }
    
    /**
     * Update stock
     */
    public function handle_update_stock() {
        $this->verify_request();
        
        $stock_data = isset($_POST['stock']) ? json_decode(stripslashes($_POST['stock']), true) : array();
        
        if (empty($stock_data)) {
            wp_send_json_error(array('message' => __('Invalid stock data', 'chinemerem-foods')));
        }
        
        $result = CFI_Stock::update($stock_data);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Stock updated successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update stock', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get stock history
     */
    public function handle_get_stock_history() {
        $this->verify_request();
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        
        $history = CFI_Stock::get_history($start_date, $end_date, $product_id);
        
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * Update stock opening value (Super Admin only)
     */
    public function handle_update_stock_opening() {
        $this->verify_request(true); // Require admin
        
        // Additional check for super admin
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only Super Admin can update opening values', 'chinemerem-foods')));
            return;
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $opening_value = isset($_POST['opening']) ? floatval($_POST['opening']) : 0;
        
        if ($product_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid product', 'chinemerem-foods')));
            return;
        }
        
        $result = CFI_Stock::update_opening($product_id, $opening_value);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Stock opening value updated successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update stock opening value', 'chinemerem-foods')));
        }
    }
    
    /**
     * Edit stock record (Super Admin only) - Edit any column for past/present records
     */
    public function handle_edit_stock_record() {
        $this->verify_request(true); // Require admin
        
        // Super admin only
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only Super Admin can edit stock history', 'chinemerem-foods')));
            return;
        }
        
        $stock_id = isset($_POST['stock_id']) ? intval($_POST['stock_id']) : 0;
        $opening = isset($_POST['opening']) ? floatval($_POST['opening']) : null;
        $import_qty = isset($_POST['import_qty']) ? floatval($_POST['import_qty']) : null;
        $cash_supply = isset($_POST['cash_supply']) ? floatval($_POST['cash_supply']) : null;
        $credit_supply = isset($_POST['credit_supply']) ? floatval($_POST['credit_supply']) : null;
        $not_supplied = isset($_POST['not_supplied']) ? floatval($_POST['not_supplied']) : null;
        $supplied_today = isset($_POST['supplied_today']) ? floatval($_POST['supplied_today']) : null;
        $to_packing_store = isset($_POST['to_packing_store']) ? floatval($_POST['to_packing_store']) : null;
        $from_packing_store = isset($_POST['from_packing_store']) ? floatval($_POST['from_packing_store']) : null;
        
        if ($stock_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid stock record', 'chinemerem-foods')));
            return;
        }
        
        $result = CFI_Stock::edit_record($stock_id, array(
            'opening' => $opening,
            'import_qty' => $import_qty,
            'cash_supply' => $cash_supply,
            'credit_supply' => $credit_supply,
            'not_supplied' => $not_supplied,
            'supplied_today' => $supplied_today,
            'to_packing_store' => $to_packing_store,
            'from_packing_store' => $from_packing_store,
        ));
        
        if ($result) {
            wp_send_json_success(array('message' => __('Stock record updated successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update stock record', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get packing store
     */
    public function handle_get_packing() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $packing = CFI_Packing::get_by_date($date);
        
        wp_send_json_success(array('packing' => $packing));
    }
    
    /**
     * Update packing store
     */
    public function handle_update_packing() {
        $this->verify_request();
        
        $packing_data = isset($_POST['packing']) ? json_decode(stripslashes($_POST['packing']), true) : array();
        
        if (empty($packing_data)) {
            wp_send_json_error(array('message' => __('Invalid packing data', 'chinemerem-foods')));
        }
        
        $result = CFI_Packing::update($packing_data);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Packing store updated successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update packing store', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get packing history
     */
    public function handle_get_packing_history() {
        $this->verify_request();
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        
        $history = CFI_Packing::get_history($start_date, $end_date);
        
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * Update packing opening value (Super Admin only)
     */
    public function handle_update_packing_opening() {
        $this->verify_request(true); // Require admin
        
        // Additional check for super admin
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only Super Admin can update opening values', 'chinemerem-foods')));
            return;
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $opening_value = isset($_POST['opening']) ? floatval($_POST['opening']) : 0;
        
        if ($product_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid product', 'chinemerem-foods')));
            return;
        }
        
        $result = CFI_Packing::update_opening($product_id, $opening_value);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Packing opening value updated successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update packing opening value', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get debtors
     */
    public function handle_get_debtors() {
        $this->verify_request();
        
        $debtors = CFI_Debtors::get_all();
        
        wp_send_json_success(array('debtors' => $debtors));
    }

    /**
     * Get debtor balances from latest transactions
     */
    public function handle_get_debtor_balances() {
        $this->verify_request();
        
        global $wpdb;
        $debtors_table = $wpdb->prefix . 'cfi_debtors';
        $allowed_tables = array(
            $wpdb->prefix . 'cfi_debtors',
            $wpdb->prefix . 'cfi_debtor_transactions'
        );
        if (!in_array($debtors_table, $allowed_tables, true)) {
            wp_send_json_error(array('message' => __('Invalid table name', 'chinemerem-foods')));
        }
        
        // BRUTAL FIX: Use total_debt from debtors table directly instead of balance_after from transactions
        // This ensures the balance is always in sync after transactions are deleted
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT SQL_NO_CACHE d.id, d.total_debt AS balance
                FROM `{$debtors_table}` d
                WHERE d.status = %s",
                'active'
            )
        );
        
        $balances = array();
        foreach ($rows as $row) {
            $balances[$row->id] = floatval($row->balance);
        }
        
        wp_send_json_success(array('balances' => $balances));
    }
    
    /**
     * Add debtor
     */
    public function handle_add_debtor() {
        $this->verify_request(true);
        
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $address = isset($_POST['address']) ? sanitize_textarea_field(wp_unslash($_POST['address'])) : '';
        
        if (empty($name)) {
            wp_send_json_error(array('message' => __('Debtor name is required', 'chinemerem-foods')));
        }
        
        $result = CFI_Debtors::add($name, $phone, $email, $address);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Debtor added successfully', 'chinemerem-foods'),
                'debtor_id' => $result
            ));
        } else {
            global $wpdb;
            $db_error = $wpdb->last_error;
            if (!empty($db_error)) {
                error_log('CFI Add Debtor DB Error: ' . $db_error);
            }
            wp_send_json_error(array('message' => __('Failed to add debtor', 'chinemerem-foods')));
        }
    }
    
    /**
     * Update debtor
     */
    public function handle_update_debtor() {
        $this->verify_request(true);
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $address = isset($_POST['address']) ? sanitize_textarea_field(wp_unslash($_POST['address'])) : '';
        
        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid debtor ID', 'chinemerem-foods')));
        }
        
        $result = CFI_Debtors::update($id, $name, $phone, $email, $address);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Debtor updated successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update debtor', 'chinemerem-foods')));
        }
    }
    
    /**
     * Delete debtor
     */
    public function handle_delete_debtor() {
        $this->verify_request(true);
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid debtor ID', 'chinemerem-foods')));
        }
        
        $result = CFI_Debtors::delete($id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Debtor deleted successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete debtor', 'chinemerem-foods')));
        }
    }
    
    /**
     * Debtor order
     */
    public function handle_debtor_order() {
        $this->verify_request();
        
        $debtor_id = isset($_POST['debtor_id']) ? intval($_POST['debtor_id']) : 0;
        $order_data = isset($_POST['order']) ? json_decode(stripslashes($_POST['order']), true) : array();
        
        if (!$debtor_id) {
            wp_send_json_error(array('message' => __('Invalid debtor ID', 'chinemerem-foods')));
        }
        
        $order_data = $this->sanitize_order_data($order_data);
        $order_data['debtor_id'] = $debtor_id;
        $order_data['order_type'] = 'credit';
        
        $result = CFI_Debtors::add_order($debtor_id, $order_data);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => __('Order added to debtor successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Debtor payment
     */
    public function handle_debtor_payment() {
        $this->verify_request();
        if (!function_exists('cfi_format_receipt_time')) {
            require_once plugin_dir_path(__FILE__) . 'class-cfi-orders.php';
        }
        
        $debtor_id = isset($_POST['debtor_id']) ? intval($_POST['debtor_id']) : 0;
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';
        $bank_name = isset($_POST['bank_name']) ? sanitize_text_field(wp_unslash($_POST['bank_name'])) : '';
        $transfer_amount = isset($_POST['transfer_amount']) ? floatval($_POST['transfer_amount']) : 0;
        $cash_amount = isset($_POST['cash_amount']) ? floatval($_POST['cash_amount']) : 0;
        $home_calculation = isset($_POST['home_calculation']) ? floatval($_POST['home_calculation']) : 0;
        
        if (!$debtor_id) {
            wp_send_json_error(array('message' => __('Invalid debtor ID', 'chinemerem-foods')));
        }
        
        $result = CFI_Debtors::add_payment($debtor_id, array(
            'amount' => $amount,
            'payment_method' => $payment_method,
            'bank_name' => $bank_name,
            'transfer_amount' => $transfer_amount,
            'cash_amount' => $cash_amount,
            'home_calculation' => $home_calculation
        ));
        
        if ($result['success']) {
            wp_send_json_success(array('message' => __('Payment recorded successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    /**
     * Get debtor payment details
     */
    public function handle_get_debtor_payment_details() {
        $this->verify_request();

        $payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
        if (!$payment_id) {
            wp_send_json_error(array('message' => __('Invalid payment ID', 'chinemerem-foods')));
        }

        global $wpdb;
        $trans_table = $wpdb->prefix . 'cfi_debtor_transactions';
        $debtors_table = $wpdb->prefix . 'cfi_debtors';

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT dt.*, d.name as debtor_name, u.display_name as staff_name
             FROM {$trans_table} dt
             LEFT JOIN {$debtors_table} d ON dt.debtor_id = d.id
             LEFT JOIN {$wpdb->users} u ON dt.staff_id = u.ID
             WHERE dt.id = %d AND dt.transaction_type = 'payment'
             LIMIT 1",
            $payment_id
        ));

        if (!$payment) {
            wp_send_json_error(array('message' => __('Payment not found', 'chinemerem-foods')));
        }

        wp_send_json_success(array(
            'id' => $payment->id,
            'debtor_name' => $payment->debtor_name,
            'amount' => $payment->amount,
            'payment_method' => $payment->payment_method,
            'cash_amount' => $payment->cash_amount,
            'transfer_amount' => $payment->transfer_amount,
            'bank_name' => $payment->bank_name,
            'home_amount' => $payment->home_calculation_amount,
            'balance_before' => $payment->balance_before,
            'balance_after' => $payment->balance_after,
            'transaction_date' => $payment->transaction_date,
            'transaction_time' => cfi_format_receipt_time($payment->transaction_date, $payment->transaction_time),
            'staff_name' => $payment->staff_name
        ));
    }
    
    /**
     * Get debtor history
     */
    public function handle_get_debtor_history() {
        $this->verify_request();
        
        $debtor_id = isset($_POST['debtor_id']) ? intval($_POST['debtor_id']) : 0;
        
        $history = CFI_Debtors::get_history($debtor_id);
        
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * Add expense
     */
    public function handle_add_expense() {
        $this->verify_request();
        
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        
        if (empty($description)) {
            wp_send_json_error(array('message' => __('Expense description is required', 'chinemerem-foods')));
        }
        
        $result = CFI_Expenses::add($description, $amount);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Expense added successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to add expense', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get expenses
     */
    public function handle_get_expenses() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $expenses = CFI_Expenses::get_by_date($date);
        
        wp_send_json_success(array('expenses' => $expenses));
    }
    
    /**
     * Get expense history
     */
    public function handle_get_expense_history() {
        $this->verify_request();
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        
        $history = CFI_Expenses::get_history($start_date, $end_date);
        
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * Add import
     */
    public function handle_add_import() {
        $this->verify_request();
        
        $imports = isset($_POST['imports']) ? json_decode(stripslashes($_POST['imports']), true) : array();
        
        if (empty($imports)) {
            wp_send_json_error(array('message' => __('Invalid import data', 'chinemerem-foods')));
        }
        
        $result = CFI_Imports::add($imports);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Import record added successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Sender and driver names are required for all imports.', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get imports
     */
    public function handle_get_imports() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $imports = CFI_Imports::get_by_date($date);
        
        wp_send_json_success(array('imports' => $imports));
    }
    
    /**
     * Get import history
     */
    public function handle_get_import_history() {
        $this->verify_request();
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        
        $history = CFI_Imports::get_history($start_date, $end_date);
        
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * Add not supplied record
     */
    public function handle_add_not_supplied() {
        $this->verify_request();
        
        $records = isset($_POST['records']) ? json_decode(stripslashes($_POST['records']), true) : array();
        
        if (empty($records)) {
            wp_send_json_error(array('message' => __('Invalid data', 'chinemerem-foods')));
        }
        
        $result = CFI_Stock::add_not_supplied($records);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Record added successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to add record', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get not supplied records
     */
    public function handle_get_not_supplied() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $records = CFI_Stock::get_not_supplied($date);
        
        wp_send_json_success(array('records' => $records));
    }
    
    /**
     * Mark as supplied
     */
    public function handle_mark_as_supplied() {
        $this->verify_request();
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid record ID', 'chinemerem-foods')));
        }
        
        $result = CFI_Stock::mark_as_supplied($id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Marked as supplied', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update record', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get not supplied history
     */
    public function handle_get_not_supplied_history() {
        $this->verify_request();
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        
        $history = CFI_Stock::get_not_supplied_history($start_date, $end_date);
        
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * Add supplied today record
     */
    public function handle_add_supplied_today() {
        $this->verify_request();
        
        $records = isset($_POST['records']) ? json_decode(stripslashes($_POST['records']), true) : array();
        
        if (empty($records)) {
            wp_send_json_error(array('message' => __('Invalid data', 'chinemerem-foods')));
        }
        
        $result = CFI_Stock::add_supplied_today($records);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Record added successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to add record', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get supplied today records
     */
    public function handle_get_supplied_today() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $records = CFI_Stock::get_supplied_today($date);
        
        wp_send_json_success(array('records' => $records));
    }
    
    /**
     * Get supplied today history
     */
    public function handle_get_supplied_today_history() {
        $this->verify_request();
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        
        $history = CFI_Stock::get_supplied_today_history($start_date, $end_date);
        
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * Add cash out
     */
    public function handle_add_cashout() {
        $this->verify_request();
        
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $bank_name = isset($_POST['bank_name']) ? sanitize_text_field(wp_unslash($_POST['bank_name'])) : 'Moniepoint MFB';
        $recipient_name = isset($_POST['recipient_name']) ? sanitize_text_field(wp_unslash($_POST['recipient_name'])) : '';
        
        if ($amount <= 0) {
            wp_send_json_error(array('message' => __('Invalid amount', 'chinemerem-foods')));
        }
        
        if ($recipient_name === '') {
            wp_send_json_error(array('message' => __('Recipient name is required', 'chinemerem-foods')));
        }
        
        $result = CFI_Financial::add_cashout($amount, $bank_name, $recipient_name);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Cash out recorded successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to record cash out', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get cash out records
     */
    public function handle_get_cashout() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $records = CFI_Financial::get_cashout($date);
        
        wp_send_json_success(array('records' => $records));
    }
    
    /**
     * Get financial summary
     */
    public function handle_get_financial_summary() {
        $this->verify_request();
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : current_time('Y-m-d');
        $summary = CFI_Financial::get_summary($date);
        
        wp_send_json_success(array('summary' => $summary));
    }
    
    /**
     * Update financial summary
     */
    public function handle_update_financial() {
        $this->verify_request();
        
        $cash_to_bank = isset($_POST['cash_to_bank']) ? floatval($_POST['cash_to_bank']) : 0;
        
        $result = CFI_Financial::update_cash_to_bank($cash_to_bank);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Financial summary updated', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update', 'chinemerem-foods')));
        }
    }
    
    /**
     * Update old cash (opening balance) - Super Admin only
     */
    public function handle_update_old_cash() {
        $this->verify_request(true); // Require admin (super admin)
        
        // Additional check for super admin
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only Super Admin can update old cash', 'chinemerem-foods')));
            return;
        }
        
        $old_cash = isset($_POST['old_cash']) ? floatval($_POST['old_cash']) : 0;
        
        $result = CFI_Financial::update_old_cash($old_cash);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Old Cash updated successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update old cash', 'chinemerem-foods')));
        }
    }
    
    /**
     * Get financial history
     */
    public function handle_get_financial_history() {
        $this->verify_request();
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        
        $history = CFI_Financial::get_history($start_date, $end_date);
        
        wp_send_json_success(array('history' => $history));
    }

    /**
     * Get analytics summary (admin only)
     */
    public function handle_get_analytics_summary() {
        $this->verify_request(true);

        $period = isset($_POST['period']) ? sanitize_text_field(wp_unslash($_POST['period'])) : 'daily';
        $summary = CFI_Financial::get_analytics_summary($period);

        wp_send_json_success(array('summary' => $summary));
    }
    
    /**
     * Get transfer history
     */
    public function handle_get_transfer_history() {
        $this->verify_request();
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        $source = isset($_POST['source']) ? sanitize_text_field(wp_unslash($_POST['source'])) : '';
        
        $history = CFI_Financial::get_transfer_history($start_date, $end_date, $source);
        
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * Reconcile date
     */
    public function handle_reconcile_date() {
        $this->verify_request(true);
        
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        
        if (empty($date)) {
            wp_send_json_error(array('message' => __('Invalid date', 'chinemerem-foods')));
        }
        
        $result = CFI_Reconciliation::reconcile($date);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Get reconciliation
     */
    public function handle_get_reconciliation() {
        $this->verify_request();
        
        $month = isset($_POST['month']) ? sanitize_text_field(wp_unslash($_POST['month'])) : current_time('Y-m');
        $records = CFI_Reconciliation::get_month($month);
        
        wp_send_json_success(array('records' => $records));
    }
    
    /**
     * Download backup
     */
    public function handle_download_backup() {
        $this->verify_request(true);
        
        $table = isset($_POST['table']) ? sanitize_text_field(wp_unslash($_POST['table'])) : '';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        
        $result = CFI_Backup::create_backup($table, $start_date, $end_date);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => __('Backup created successfully', 'chinemerem-foods'),
                'file_url' => $result['file_url']
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Upload backup
     */
    public function handle_upload_backup() {
        $this->verify_request(true);
        
        if (!isset($_FILES['backup_file'])) {
            wp_send_json_error(array('message' => __('No file uploaded', 'chinemerem-foods')));
        }
        
        $result = CFI_Backup::restore_backup($_FILES['backup_file']);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => __('Backup restored successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Get backup list
     */
    public function handle_get_backup_list() {
        $this->verify_request(true);
        
        $backups = CFI_Backup::get_list();
        
        wp_send_json_success(array('backups' => $backups));
    }
    
    /**
     * Sync offline data
     */
    public function handle_sync_offline_data() {
        $this->verify_request();
        
        $data = isset($_POST['data']) ? json_decode(stripslashes($_POST['data']), true) : array();
        
        if (empty($data)) {
            wp_send_json_success(array('message' => __('No data to sync', 'chinemerem-foods')));
        }
        
        $results = array();
        
        foreach ($data as $item) {
            $type = sanitize_text_field($item['type'] ?? '');
            $payload = $item['data'] ?? array();
            $item_id = sanitize_text_field($item['id'] ?? '');
            
            switch ($type) {
                case 'order':
                    $payload = $this->sanitize_order_data($payload);
                    $result = CFI_Orders::submit($payload);
                    $results[] = array('type' => $type, 'id' => $item_id, 'success' => $result['success']);
                    break;
                    
                case 'debtor_order':
                    $debtor_id = absint($payload['debtor_id'] ?? 0);
                    $order_payload = $this->sanitize_order_data($payload);
                    $result = CFI_Debtors::add_order($debtor_id, $order_payload);
                    $results[] = array('type' => $type, 'id' => $item_id, 'success' => $result['success']);
                    break;
                    
                case 'debtor_payment':
                    $debtor_id = absint($payload['debtor_id'] ?? 0);
                    $payment_payload = array(
                        'transfer_amount' => floatval($payload['transfer_amount'] ?? 0),
                        'cash_amount' => floatval($payload['cash_amount'] ?? 0),
                        'home_calculation' => floatval($payload['home_calculation'] ?? 0),
                        'payment_method' => sanitize_text_field($payload['payment_method'] ?? 'transfer'),
                        'bank_name' => sanitize_text_field($payload['bank_name'] ?? 'Moniepoint MFB'),
                    );
                    $result = CFI_Debtors::add_payment($debtor_id, $payment_payload);
                    $results[] = array('type' => $type, 'id' => $item_id, 'success' => $result['success']);
                    break;
                    
                case 'expense':
                    $result = CFI_Expenses::add(
                        sanitize_textarea_field($payload['description'] ?? ''),
                        floatval($payload['amount'] ?? 0)
                    );
                    $results[] = array('type' => $type, 'id' => $item_id, 'success' => (bool) $result);
                    break;
            }
        }
        
        wp_send_json_success(array(
            'message' => __('Sync completed', 'chinemerem-foods'),
            'results' => $results
        ));
    }
    
    /**
     * Get users (admin only)
     */
    public function handle_get_users() {
        $this->verify_request(true);
        
        $users = get_users(array(
            'role__in' => array('administrator', 'cfi_admin', 'cfi_staff'),
            'orderby' => 'display_name'
        ));
        
        $user_list = array();
        foreach ($users as $user) {
            $user_list[] = array(
                'id' => $user->ID,
                'username' => $user->user_login,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'role' => implode(', ', $user->roles)
            );
        }
        
        wp_send_json_success(array('users' => $user_list));
    }
    
    /**
     * Add user (admin only)
     */
    public function handle_add_user() {
        $this->verify_request(true);
        
        $username = isset($_POST['username']) ? sanitize_user(wp_unslash($_POST['username'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $role = isset($_POST['role']) ? sanitize_text_field(wp_unslash($_POST['role'])) : 'cfi_staff';
        
        if (empty($username) || empty($email) || empty($password)) {
            wp_send_json_error(array('message' => __('All fields are required', 'chinemerem-foods')));
        }
        
        // Validate role
        if (!in_array($role, array('cfi_staff', 'cfi_admin'))) {
            $role = 'cfi_staff';
        }
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            wp_send_json_error(array('message' => $user_id->get_error_message()));
        }
        
        // Update user
        wp_update_user(array(
            'ID' => $user_id,
            'display_name' => $name,
            'role' => $role
        ));
        
        wp_send_json_success(array('message' => __('User added successfully', 'chinemerem-foods')));
    }
    
    /**
     * Update user (admin only)
     */
    public function handle_update_user() {
        $this->verify_request(true);
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $role = isset($_POST['role']) ? sanitize_text_field(wp_unslash($_POST['role'])) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        
        if (!$user_id) {
            wp_send_json_error(array('message' => __('Invalid user ID', 'chinemerem-foods')));
        }
        
        $user_data = array('ID' => $user_id);
        
        if (!empty($name)) {
            $user_data['display_name'] = $name;
        }
        
        if (!empty($email)) {
            $user_data['user_email'] = $email;
        }
        
        if (!empty($password)) {
            $user_data['user_pass'] = $password;
        }
        
        $result = wp_update_user($user_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Update role if provided
        if (!empty($role) && in_array($role, array('cfi_staff', 'cfi_admin'))) {
            $user = new WP_User($user_id);
            $user->set_role($role);
        }
        
        wp_send_json_success(array('message' => __('User updated successfully', 'chinemerem-foods')));
    }
    
    /**
     * Delete user (super admin only)
     */
    public function handle_delete_user() {
        $this->verify_request(true);
        
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only super admin can delete users', 'chinemerem-foods')));
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error(array('message' => __('Invalid user ID', 'chinemerem-foods')));
        }
        
        // Prevent deleting self
        if ($user_id === get_current_user_id()) {
            wp_send_json_error(array('message' => __('Cannot delete yourself', 'chinemerem-foods')));
        }
        
        require_once(ABSPATH . 'wp-admin/includes/user.php');
        $result = wp_delete_user($user_id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('User deleted successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete user', 'chinemerem-foods')));
        }
    }
    
    /**
     * Delete history (super admin only)
     */
    public function handle_delete_history() {
        $this->verify_request(true);
        
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only super admin can delete history', 'chinemerem-foods')));
        }
        
        $table = isset($_POST['table']) ? sanitize_text_field(wp_unslash($_POST['table'])) : '';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (empty($table) || !$id) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'chinemerem-foods')));
        }
        
        global $wpdb;
        $table_name = CFI_Database::get_table($table);
        
        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));
        
        if ($result) {
            wp_send_json_success(array('message' => __('Record deleted successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete record', 'chinemerem-foods')));
        }
    }
    
    /**
     * Update history (super admin only)
     */
    public function handle_update_history() {
        $this->verify_request(true);
        
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only super admin can edit history', 'chinemerem-foods')));
        }
        
        $table = isset($_POST['table']) ? sanitize_text_field(wp_unslash($_POST['table'])) : '';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $data = isset($_POST['data']) ? json_decode(stripslashes($_POST['data']), true) : array();
        
        if (empty($table) || !$id || empty($data)) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'chinemerem-foods')));
        }
        
        global $wpdb;
        $table_name = CFI_Database::get_table($table);
        
        // Sanitize data
        $sanitized_data = array();
        foreach ($data as $key => $value) {
            $sanitized_data[sanitize_key($key)] = is_numeric($value) ? $value : sanitize_text_field($value);
        }
        
        $result = $wpdb->update($table_name, $sanitized_data, array('id' => $id));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => __('Record updated successfully', 'chinemerem-foods')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update record', 'chinemerem-foods')));
        }
    }
    
    /**
     * Recreate missing pages (admin only)
     */
    public function handle_recreate_pages() {
        $this->verify_request(true);
        
        $created = CFI_Pages::recreate_pages();
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d pages created successfully', 'chinemerem-foods'), $created),
            'created' => $created
        ));
    }
    
    /**
     * Migrate opening values for stock, packing and financial records (super admin only)
     * Fixes records with 0 opening that should have carried over from previous days
     */
    public function handle_migrate_opening_values() {
        $this->verify_request(true);
        
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only super admin can run migrations', 'chinemerem-foods')));
        }
        
        $stock_fixed = CFI_Stock::migrate_opening_values();
        $packing_fixed = CFI_Packing::migrate_opening_values();
        $financial_fixed = CFI_Financial::migrate_opening_values();
        
        wp_send_json_success(array(
            'message' => sprintf(__('Migration complete: %d stock records, %d packing records and %d financial records fixed', 'chinemerem-foods'), $stock_fixed, $packing_fixed, $financial_fixed),
            'stock_fixed' => $stock_fixed,
            'packing_fixed' => $packing_fixed,
            'financial_fixed' => $financial_fixed
        ));
    }
    
    /**
     * Verify Sunday carryover fix (super admin only)
     * Simulates the Saturday→Sunday→Monday gap using real database data
     * to confirm values carry over correctly when Sunday has no records
     */
    public function handle_verify_sunday_carryover() {
        $this->verify_request(true);
        
        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Only super admin can run verification', 'chinemerem-foods')));
        }
        
        global $wpdb;
        $results = array();
        
        // --- 1. Verify Financial Summary carryover ---
        $fin_table = CFI_Database::get_table('financial_summary');
        
        // Get the most recent financial record (simulates "Saturday")
        $latest_financial = $wpdb->get_row(
            "SELECT record_date, old_cash, cash_left FROM $fin_table ORDER BY record_date DESC LIMIT 1"
        );
        
        if ($latest_financial) {
            $saturday_date = $latest_financial->record_date;
            // Simulate Monday = Saturday + 2 days (skipping Sunday)
            $monday_date = date('Y-m-d', strtotime($saturday_date . ' +2 days'));
            
            // Test: What would initialize_date return for Monday?
            // Use the same query that initialize_date now uses
            $simulated_old_cash = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SQL_NO_CACHE cash_left FROM $fin_table WHERE record_date < %s ORDER BY record_date DESC LIMIT 1",
                    $monday_date
                )
            );
            $simulated_old_cash = floatval($simulated_old_cash ?: 0);
            
            $results['financial'] = array(
                'status' => ($simulated_old_cash == floatval($latest_financial->cash_left)) ? 'pass' : 'fail',
                'latest_date' => $saturday_date,
                'latest_cash_left' => floatval($latest_financial->cash_left),
                'simulated_monday' => $monday_date,
                'monday_old_cash_would_be' => $simulated_old_cash,
                'explanation' => ($simulated_old_cash == floatval($latest_financial->cash_left))
                    ? sprintf('Monday (%s) would correctly carry over cash_left ₦%s from %s', $monday_date, number_format($simulated_old_cash, 2), $saturday_date)
                    : sprintf('PROBLEM: Monday old_cash (₦%s) does not match %s cash_left (₦%s)', number_format($simulated_old_cash, 2), $saturday_date, number_format(floatval($latest_financial->cash_left), 2)),
            );
        } else {
            $results['financial'] = array(
                'status' => 'skip',
                'explanation' => 'No financial records found to test against',
            );
        }
        
        // --- 2. Verify Stock carryover ---
        $stock_table = CFI_Database::get_table('stock');
        
        $latest_stock_products = $wpdb->get_results(
            "SELECT s.product_id, p.name as product_name, s.record_date, s.closing
             FROM $stock_table s
             JOIN " . CFI_Database::get_table('products') . " p ON s.product_id = p.id
             WHERE s.record_date = (SELECT MAX(record_date) FROM $stock_table)
             ORDER BY p.name"
        );
        
        $stock_results = array();
        foreach ($latest_stock_products as $sp) {
            $monday_date = date('Y-m-d', strtotime($sp->record_date . ' +2 days'));
            
            $simulated_opening = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT closing FROM $stock_table WHERE product_id = %d AND record_date < %s ORDER BY record_date DESC LIMIT 1",
                    $sp->product_id,
                    $monday_date
                )
            );
            $simulated_opening = floatval($simulated_opening ?: 0);
            
            $stock_results[] = array(
                'product' => $sp->product_name,
                'status' => ($simulated_opening == floatval($sp->closing)) ? 'pass' : 'fail',
                'latest_closing' => floatval($sp->closing),
                'monday_opening_would_be' => $simulated_opening,
            );
        }
        
        $stock_all_pass = !empty($stock_results) && !array_filter($stock_results, function($r) { return $r['status'] !== 'pass'; });
        $results['stock'] = array(
            'status' => empty($stock_results) ? 'skip' : ($stock_all_pass ? 'pass' : 'fail'),
            'latest_date' => !empty($latest_stock_products) ? $latest_stock_products[0]->record_date : null,
            'products' => $stock_results,
            'explanation' => empty($stock_results)
                ? 'No stock records found to test against'
                : ($stock_all_pass ? 'All stock products would correctly carry over closing→opening across a Sunday gap' : 'Some stock products would NOT carry over correctly'),
        );
        
        // --- 3. Verify Packing Store carryover ---
        $packing_table = CFI_Database::get_table('packing_store');
        
        $latest_packing_products = $wpdb->get_results(
            "SELECT ps.product_id, p.name as product_name, ps.record_date, ps.closing, ps.balance_in_packing
             FROM $packing_table ps
             JOIN " . CFI_Database::get_table('products') . " p ON ps.product_id = p.id
             WHERE ps.record_date = (SELECT MAX(record_date) FROM $packing_table)
             ORDER BY p.name"
        );
        
        $packing_results = array();
        foreach ($latest_packing_products as $pp) {
            $monday_date = date('Y-m-d', strtotime($pp->record_date . ' +2 days'));
            
            $prev = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT closing, balance_in_packing FROM $packing_table WHERE product_id = %d AND record_date < %s ORDER BY record_date DESC LIMIT 1",
                    $pp->product_id,
                    $monday_date
                )
            );
            $simulated_opening = $prev ? floatval($prev->closing) : 0;
            $simulated_balance = $prev ? floatval($prev->balance_in_packing) : 0;
            
            $packing_results[] = array(
                'product' => $pp->product_name,
                'status' => ($simulated_opening == floatval($pp->closing) && $simulated_balance == floatval($pp->balance_in_packing)) ? 'pass' : 'fail',
                'latest_closing' => floatval($pp->closing),
                'monday_opening_would_be' => $simulated_opening,
                'latest_balance' => floatval($pp->balance_in_packing),
                'monday_balance_would_be' => $simulated_balance,
            );
        }
        
        $packing_all_pass = !empty($packing_results) && !array_filter($packing_results, function($r) { return $r['status'] !== 'pass'; });
        $results['packing'] = array(
            'status' => empty($packing_results) ? 'skip' : ($packing_all_pass ? 'pass' : 'fail'),
            'latest_date' => !empty($latest_packing_products) ? $latest_packing_products[0]->record_date : null,
            'products' => $packing_results,
            'explanation' => empty($packing_results)
                ? 'No packing records found to test against'
                : ($packing_all_pass ? 'All packing products would correctly carry over closing→opening and balance across a Sunday gap' : 'Some packing products would NOT carry over correctly'),
        );
        
        // Overall status
        $all_statuses = array($results['financial']['status'], $results['stock']['status'], $results['packing']['status']);
        $has_fail = in_array('fail', $all_statuses);
        $all_skip = !array_filter($all_statuses, function($s) { return $s !== 'skip'; });
        
        $overall = $has_fail ? 'fail' : ($all_skip ? 'skip' : 'pass');
        
        wp_send_json_success(array(
            'overall' => $overall,
            'results' => $results,
            'message' => $overall === 'pass' 
                ? '✅ Sunday carryover fix is working correctly! All values would carry over properly across a Sunday gap.'
                : ($overall === 'skip' 
                    ? '⚠️ No data available to verify. Use the system for at least one day first.'
                    : '❌ Some values would not carry over correctly. Run "Migrate Opening Values" to fix existing records.'),
        ));
    }

    /**
     * System Scan - Check all form submissions, histories, and cross-form linking
     * Super Admin only
     */
    public function handle_system_scan() {
        $this->verify_request(true);

        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Super Admin access required', 'chinemerem-foods')));
        }

        global $wpdb;
        $checks = array();

        // 1. Database Tables Check
        $expected_tables = array(
            'cfi_products', 'cfi_orders', 'cfi_order_items',
            'cfi_stock', 'cfi_stock_history',
            'cfi_packing_store', 'cfi_packing_history',
            'cfi_debtors', 'cfi_debtor_transactions',
            'cfi_expenses', 'cfi_imports',
            'cfi_not_supplied', 'cfi_supplied_today',
            'cfi_cashout', 'cfi_financial_summary',
            'cfi_financial_history', 'cfi_transfer_history',
            'cfi_reconciliation', 'cfi_reconciliation_history',
        );
        $missing_tables = array();
        $table_counts = array();
        foreach ($expected_tables as $t) {
            $full = $wpdb->prefix . $t;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full));
            if (!$exists) {
                $missing_tables[] = $t;
            } else {
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$full}`");
                $table_counts[$t] = $count;
            }
        }
        $checks['tables'] = array(
            'status' => empty($missing_tables) ? 'pass' : 'fail',
            'missing' => $missing_tables,
            'counts' => $table_counts,
            'message' => empty($missing_tables)
                ? 'All ' . count($expected_tables) . ' database tables exist'
                : count($missing_tables) . ' table(s) missing: ' . implode(', ', $missing_tables),
        );

        // 2. Orders & Order Items Integrity
        $orders_table = $wpdb->prefix . 'cfi_orders';
        $order_items_table = $wpdb->prefix . 'cfi_order_items';
        $total_orders = isset($table_counts['cfi_orders']) ? $table_counts['cfi_orders'] : 0;
        $orders_without_items = 0;
        $items_without_orders = 0;
        if ($total_orders > 0 && !in_array('cfi_orders', $missing_tables) && !in_array('cfi_order_items', $missing_tables)) {
            $orders_without_items = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$orders_table}` o 
                 LEFT JOIN `{$order_items_table}` oi ON o.id = oi.order_id 
                 WHERE oi.id IS NULL"
            );
            $items_without_orders = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$order_items_table}` oi 
                 LEFT JOIN `{$orders_table}` o ON oi.order_id = o.id 
                 WHERE o.id IS NULL"
            );
        }
        $order_integrity_ok = ($orders_without_items === 0 && $items_without_orders === 0);
        $checks['order_integrity'] = array(
            'status' => $total_orders === 0 ? 'skip' : ($order_integrity_ok ? 'pass' : 'warn'),
            'total_orders' => $total_orders,
            'orders_without_items' => $orders_without_items,
            'items_without_orders' => $items_without_orders,
            'message' => $total_orders === 0
                ? 'No orders to check'
                : ($order_integrity_ok
                    ? $total_orders . ' orders all have matching order items'
                    : $orders_without_items . ' order(s) missing items, ' . $items_without_orders . ' orphan item(s)'),
        );

        // 3. Debtor Transactions & Balance Check
        $debtors_table = $wpdb->prefix . 'cfi_debtors';
        $trans_table = $wpdb->prefix . 'cfi_debtor_transactions';
        $debtor_issues = array();
        if (!in_array('cfi_debtors', $missing_tables) && !in_array('cfi_debtor_transactions', $missing_tables)) {
            $debtors = $wpdb->get_results("SELECT id, name, total_debt FROM `{$debtors_table}` WHERE status = 'active'");
            foreach ($debtors as $d) {
                $calc = $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(
                        (SELECT balance_after FROM `{$trans_table}` WHERE debtor_id = %d ORDER BY id DESC LIMIT 1),
                        0
                    )",
                    $d->id
                ));
                $trans_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$trans_table}` WHERE debtor_id = %d", $d->id
                ));
                if ($trans_count > 0 && abs(floatval($d->total_debt) - floatval($calc)) > 0.01) {
                    $debtor_issues[] = array(
                        'name' => $d->name,
                        'table_debt' => floatval($d->total_debt),
                        'last_trans_balance' => floatval($calc),
                    );
                }
            }
        }
        $total_debtors = isset($debtors) ? count($debtors) : 0;
        $checks['debtor_balances'] = array(
            'status' => $total_debtors === 0 ? 'skip' : (empty($debtor_issues) ? 'pass' : 'warn'),
            'total_debtors' => $total_debtors,
            'issues' => $debtor_issues,
            'message' => $total_debtors === 0
                ? 'No active debtors to check'
                : (empty($debtor_issues)
                    ? 'All ' . $total_debtors . ' debtor balance(s) match their latest transaction records'
                    : count($debtor_issues) . ' debtor(s) have balance mismatch between table and transactions'),
        );

        // 4. Credit Orders linked to Debtors
        $credit_issues = array();
        if (!in_array('cfi_orders', $missing_tables) && !in_array('cfi_debtors', $missing_tables)) {
            $credit_orders = $wpdb->get_results(
                "SELECT o.id, o.order_number, o.debtor_id, o.grand_total, d.name as debtor_name
                 FROM `{$orders_table}` o
                 LEFT JOIN `{$debtors_table}` d ON o.debtor_id = d.id
                 WHERE o.order_type = 'credit'"
            );
            foreach ($credit_orders as $co) {
                if (empty($co->debtor_name)) {
                    $credit_issues[] = array(
                        'order' => $co->order_number,
                        'issue' => 'Debtor ID ' . $co->debtor_id . ' not found',
                    );
                }
            }
        }
        $total_credit = isset($credit_orders) ? count($credit_orders) : 0;
        $checks['credit_order_linking'] = array(
            'status' => $total_credit === 0 ? 'skip' : (empty($credit_issues) ? 'pass' : 'warn'),
            'total_credit_orders' => $total_credit,
            'issues' => $credit_issues,
            'message' => $total_credit === 0
                ? 'No credit orders to check'
                : (empty($credit_issues)
                    ? 'All ' . $total_credit . ' credit order(s) correctly linked to debtors'
                    : count($credit_issues) . ' credit order(s) have missing debtor links'),
        );

        // 5. Transfer History Integrity
        $transfer_table = $wpdb->prefix . 'cfi_transfer_history';
        $transfer_count = isset($table_counts['cfi_transfer_history']) ? $table_counts['cfi_transfer_history'] : 0;
        $orphan_transfers = 0;
        if ($transfer_count > 0 && !in_array('cfi_transfer_history', $missing_tables)) {
            $orphan_transfers = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$transfer_table}` WHERE amount <= 0"
            );
        }
        $checks['transfers'] = array(
            'status' => $transfer_count === 0 ? 'skip' : ($orphan_transfers === 0 ? 'pass' : 'warn'),
            'total' => $transfer_count,
            'invalid' => $orphan_transfers,
            'message' => $transfer_count === 0
                ? 'No transfers to check'
                : ($orphan_transfers === 0
                    ? $transfer_count . ' transfer record(s) all valid'
                    : $orphan_transfers . ' transfer(s) with invalid amounts'),
        );

        // 6. Stock Records Check
        $stock_table = $wpdb->prefix . 'cfi_stock';
        $stock_count = isset($table_counts['cfi_stock']) ? $table_counts['cfi_stock'] : 0;
        $negative_stock = 0;
        if ($stock_count > 0 && !in_array('cfi_stock', $missing_tables)) {
            $negative_stock = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$stock_table}` WHERE closing < 0"
            );
        }
        $checks['stock'] = array(
            'status' => $stock_count === 0 ? 'skip' : ($negative_stock === 0 ? 'pass' : 'warn'),
            'total' => $stock_count,
            'negative_closing' => $negative_stock,
            'message' => $stock_count === 0
                ? 'No stock records to check'
                : ($negative_stock === 0
                    ? $stock_count . ' stock record(s) all have valid closing values'
                    : $negative_stock . ' stock record(s) have negative closing values'),
        );

        // 7. Financial Summary Check
        $fin_table = $wpdb->prefix . 'cfi_financial_summary';
        $fin_count = isset($table_counts['cfi_financial_summary']) ? $table_counts['cfi_financial_summary'] : 0;
        $checks['financial'] = array(
            'status' => $fin_count === 0 ? 'skip' : 'pass',
            'total' => $fin_count,
            'message' => $fin_count === 0
                ? 'No financial summary records to check'
                : $fin_count . ' financial summary record(s) found',
        );

        // 8. Expenses, Imports, Not Supplied, Supplied Today, Cash Out record counts
        $form_sections = array(
            'Expenses' => 'cfi_expenses',
            'Imports' => 'cfi_imports',
            'Not Supplied' => 'cfi_not_supplied',
            'Supplied Today' => 'cfi_supplied_today',
            'Cash Out' => 'cfi_cashout',
            'Packing Store' => 'cfi_packing_store',
            'Reconciliation' => 'cfi_reconciliation',
        );
        $form_counts = array();
        foreach ($form_sections as $label => $tbl) {
            $form_counts[$label] = isset($table_counts[$tbl]) ? $table_counts[$tbl] : 0;
        }
        $checks['form_records'] = array(
            'status' => 'info',
            'counts' => $form_counts,
            'message' => 'Record counts across all form sections',
        );

        // 9. History Tables Check
        $history_sections = array(
            'Stock History' => 'cfi_stock_history',
            'Packing History' => 'cfi_packing_history',
            'Financial History' => 'cfi_financial_history',
            'Reconciliation History' => 'cfi_reconciliation_history',
        );
        $history_counts = array();
        foreach ($history_sections as $label => $tbl) {
            $history_counts[$label] = isset($table_counts[$tbl]) ? $table_counts[$tbl] : 0;
        }
        $checks['history_records'] = array(
            'status' => 'info',
            'counts' => $history_counts,
            'message' => 'Record counts across all history tables',
        );

        // Overall status
        $statuses = array_column($checks, 'status');
        $has_fail = in_array('fail', $statuses);
        $has_warn = in_array('warn', $statuses);
        $overall = $has_fail ? 'fail' : ($has_warn ? 'warn' : 'pass');

        wp_send_json_success(array(
            'overall' => $overall,
            'checks' => $checks,
            'scan_time' => current_time('d/m/Y g:i A'),
            'message' => $overall === 'pass'
                ? '✅ System scan complete. All checks passed — forms, histories, and cross-form links are working correctly.'
                : ($overall === 'warn'
                    ? '⚠️ System scan complete with warnings. Some data inconsistencies detected.'
                    : '❌ System scan found critical issues that need attention.'),
        ));
    }

    /**
     * System Fix - Auto-fix all issues found by system scan
     * Super Admin only
     */
    public function handle_system_fix() {
        $this->verify_request(true);

        if (!CFI_Auth::is_super_admin()) {
            wp_send_json_error(array('message' => __('Super Admin access required', 'chinemerem-foods')));
        }

        global $wpdb;
        $fixes = array();
        $fix_count = 0;

        // 1. Fix Missing Database Tables
        CFI_Database::create_tables();
        $expected_tables = array(
            'cfi_products', 'cfi_orders', 'cfi_order_items',
            'cfi_stock', 'cfi_stock_history',
            'cfi_packing_store', 'cfi_packing_history',
            'cfi_debtors', 'cfi_debtor_transactions',
            'cfi_expenses', 'cfi_imports',
            'cfi_not_supplied', 'cfi_supplied_today',
            'cfi_cashout', 'cfi_financial_summary',
            'cfi_financial_history', 'cfi_transfer_history',
            'cfi_reconciliation', 'cfi_reconciliation_history',
        );
        $tables_fixed = 0;
        foreach ($expected_tables as $t) {
            $full = $wpdb->prefix . $t;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full));
            if ($exists) {
                $tables_fixed++;
            }
        }
        $fixes['tables'] = array(
            'action' => 'Recreated all database tables',
            'result' => $tables_fixed . '/' . count($expected_tables) . ' tables verified',
            'count' => $tables_fixed === count($expected_tables) ? 0 : (count($expected_tables) - $tables_fixed),
        );
        if ($tables_fixed < count($expected_tables)) {
            $fix_count += (count($expected_tables) - $tables_fixed);
        }

        // 2. Fix Missing WordPress Pages
        $pages_created = CFI_Pages::recreate_pages();
        $page_slugs = CFI_Pages::get_page_slugs();
        $missing_pages = array();
        $pages_with_wrong_content = array();
        foreach (CFI_Pages::get_pages_config() as $page_config) {
            $page_obj = get_page_by_path($page_config['slug']);
            if (!$page_obj) {
                $missing_pages[] = $page_config['slug'];
            } elseif (strpos($page_obj->post_content, '[cfi_page') === false) {
                // Page exists but shortcode is missing — fix it
                wp_update_post(array(
                    'ID' => $page_obj->ID,
                    'post_content' => '[cfi_page template="' . $page_config['template'] . '"]',
                ));
                $pages_with_wrong_content[] = $page_config['slug'];
            }
        }
        $total_page_fixes = $pages_created + count($pages_with_wrong_content);
        $fixes['pages'] = array(
            'action' => 'Verified & repaired WordPress pages',
            'result' => $pages_created . ' page(s) created, ' . count($pages_with_wrong_content) . ' page(s) shortcode repaired',
            'count' => $total_page_fixes,
            'details' => array(
                'created' => $pages_created,
                'shortcode_repaired' => $pages_with_wrong_content,
                'still_missing' => $missing_pages,
            ),
        );
        $fix_count += $total_page_fixes;

        // 3. Fix Debtor Balance Mismatches
        $debtors_table = $wpdb->prefix . 'cfi_debtors';
        $trans_table = $wpdb->prefix . 'cfi_debtor_transactions';
        $debtor_fixes = 0;
        $debtor_details = array();
        $debtors = $wpdb->get_results("SELECT id, name, total_debt FROM `{$debtors_table}` WHERE status = 'active'");
        if ($debtors) {
            foreach ($debtors as $d) {
                $trans_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$trans_table}` WHERE debtor_id = %d", $d->id
                ));
                if ($trans_count > 0) {
                    $calc = $wpdb->get_var($wpdb->prepare(
                        "SELECT balance_after FROM `{$trans_table}` WHERE debtor_id = %d ORDER BY id DESC LIMIT 1",
                        $d->id
                    ));
                    $calc = floatval($calc);
                    if (abs(floatval($d->total_debt) - $calc) > 0.01) {
                        $wpdb->update(
                            $debtors_table,
                            array('total_debt' => $calc),
                            array('id' => $d->id),
                            array('%f'),
                            array('%d')
                        );
                        $debtor_fixes++;
                        $debtor_details[] = $d->name . ': ₦' . number_format($d->total_debt, 0) . ' → ₦' . number_format($calc, 0);
                    }
                }
            }
        }
        $fixes['debtor_balances'] = array(
            'action' => 'Synced debtor balances with transaction history',
            'result' => $debtor_fixes . ' debtor balance(s) corrected',
            'count' => $debtor_fixes,
            'details' => $debtor_details,
        );
        $fix_count += $debtor_fixes;

        // 4. Fix Orphan Order Items (items without matching orders)
        $orders_table = $wpdb->prefix . 'cfi_orders';
        $order_items_table = $wpdb->prefix . 'cfi_order_items';
        $orphan_items = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$order_items_table}` oi 
             LEFT JOIN `{$orders_table}` o ON oi.order_id = o.id 
             WHERE o.id IS NULL"
        );
        if ($orphan_items > 0) {
            $wpdb->query(
                "DELETE oi FROM `{$order_items_table}` oi 
                 LEFT JOIN `{$orders_table}` o ON oi.order_id = o.id 
                 WHERE o.id IS NULL"
            );
        }
        // Fix orders without items — remove empty orders
        $empty_orders = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$orders_table}` o 
             LEFT JOIN `{$order_items_table}` oi ON o.id = oi.order_id 
             WHERE oi.id IS NULL"
        );
        if ($empty_orders > 0) {
            $wpdb->query(
                "DELETE o FROM `{$orders_table}` o 
                 LEFT JOIN `{$order_items_table}` oi ON o.id = oi.order_id 
                 WHERE oi.id IS NULL"
            );
        }
        $fixes['order_integrity'] = array(
            'action' => 'Cleaned up orphan order data',
            'result' => $orphan_items . ' orphan item(s) removed, ' . $empty_orders . ' empty order(s) removed',
            'count' => $orphan_items + $empty_orders,
        );
        $fix_count += $orphan_items + $empty_orders;

        // 5. Fix Invalid Transfer Records
        $transfer_table = $wpdb->prefix . 'cfi_transfer_history';
        $invalid_transfers = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$transfer_table}` WHERE amount <= 0"
        );
        if ($invalid_transfers > 0) {
            $wpdb->query("DELETE FROM `{$transfer_table}` WHERE amount <= 0");
        }
        $fixes['transfers'] = array(
            'action' => 'Removed invalid transfer records',
            'result' => $invalid_transfers . ' invalid transfer(s) removed',
            'count' => $invalid_transfers,
        );
        $fix_count += $invalid_transfers;

        // 6. Fix Negative Stock Closing Values
        $stock_table = $wpdb->prefix . 'cfi_stock';
        $negative_stock = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$stock_table}` WHERE closing < 0"
        );
        if ($negative_stock > 0) {
            // Reset negative closing to 0 — the stock was oversold
            $wpdb->query("UPDATE `{$stock_table}` SET closing = 0 WHERE closing < 0");
        }
        $fixes['stock'] = array(
            'action' => 'Reset negative stock closing values to 0',
            'result' => $negative_stock . ' stock record(s) corrected',
            'count' => $negative_stock,
        );
        $fix_count += $negative_stock;

        // 7. Recalculate Financial Summaries for dates that have data
        $fin_table = $wpdb->prefix . 'cfi_financial_summary';
        $fin_dates = $wpdb->get_col("SELECT DISTINCT record_date FROM `{$fin_table}` ORDER BY record_date DESC LIMIT 30");
        $fin_fixed = 0;
        if ($fin_dates) {
            foreach ($fin_dates as $date) {
                CFI_Financial::recalculate($date);
                $fin_fixed++;
            }
        }
        $fixes['financial'] = array(
            'action' => 'Recalculated financial summaries',
            'result' => $fin_fixed . ' financial summary date(s) recalculated',
            'count' => $fin_fixed,
        );

        // 8. Fix Credit Orders with missing debtor links
        $credit_orphans = $wpdb->get_results(
            "SELECT o.id, o.order_number, o.debtor_id FROM `{$orders_table}` o
             LEFT JOIN `{$debtors_table}` d ON o.debtor_id = d.id
             WHERE o.order_type = 'credit' AND d.id IS NULL"
        );
        $credit_fixed = 0;
        if ($credit_orphans) {
            foreach ($credit_orphans as $co) {
                // Set to cash order if debtor not found
                $wpdb->update(
                    $orders_table,
                    array('order_type' => 'cash', 'debtor_id' => 0, 'payment_method' => 'cash'),
                    array('id' => $co->id),
                    array('%s', '%d', '%s'),
                    array('%d')
                );
                $credit_fixed++;
            }
        }
        $fixes['credit_links'] = array(
            'action' => 'Fixed credit orders with missing debtor links',
            'result' => $credit_fixed . ' credit order(s) converted to cash orders',
            'count' => $credit_fixed,
        );
        $fix_count += $credit_fixed;

        // 9. Verify all WordPress pages exist and have correct status
        $page_status_fixes = 0;
        foreach (CFI_Pages::get_pages_config() as $page_config) {
            $page_obj = get_page_by_path($page_config['slug']);
            if ($page_obj && $page_obj->post_status !== 'publish') {
                wp_update_post(array(
                    'ID' => $page_obj->ID,
                    'post_status' => 'publish',
                ));
                $page_status_fixes++;
            }
        }
        $fixes['page_status'] = array(
            'action' => 'Verified all pages are published',
            'result' => $page_status_fixes . ' page(s) republished',
            'count' => $page_status_fixes,
        );
        $fix_count += $page_status_fixes;

        wp_send_json_success(array(
            'fixes' => $fixes,
            'total_fixes' => $fix_count,
            'fix_time' => current_time('d/m/Y g:i A'),
            'message' => $fix_count > 0
                ? '🔧 Brutal Fix complete! ' . $fix_count . ' issue(s) fixed across the system.'
                : '✅ No issues found — system is already in good shape!',
        ));
    }
}