<?php
/**
 * Stock Handler Class
 * 
 * @package Chinemerem_Foods_Inventory
 */

if (!defined('ABSPATH')) {
    exit;
}

class CFI_Stock {
    
    /**
     * Initialize stock record for a product
     */
    public static function initialize_product($product_id, $date = null) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        if (!$date) {
            $date = current_time('Y-m-d');
        }
        
        // Check if already exists
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE product_id = %d AND record_date = %s",
                $product_id,
                $date
            )
        );
        
        if ($existing) {
            return $existing;
        }
        
        // Get the most recent closing value from any previous date (handles skipped days like weekends)
        $opening = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT closing FROM $table WHERE product_id = %d AND record_date < %s ORDER BY record_date DESC LIMIT 1",
                $product_id,
                $date
            )
        );
        
        $wpdb->insert(
            $table,
            array(
                'product_id' => $product_id,
                'record_date' => $date,
                'opening' => $opening ?: 0,
                'import_qty' => 0,
                'cash_supply' => 0,
                'credit_supply' => 0,
                'not_supplied' => 0,
                'supplied_today' => 0,
                'to_packing_store' => 0,
                'from_packing_store' => 0,
                'closing' => $opening ?: 0,
            ),
            array('%d', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get stock records by date
     */
    public static function get_by_date($date) {
        global $wpdb;
        $table_stock = CFI_Database::get_table('stock');
        $table_packing = CFI_Database::get_table('packing_store');
        $table_products = CFI_Database::get_table('products');
        
        // Ensure all products have stock records for this date
        $products = CFI_Products::get_all();
        foreach ($products as $product) {
            self::initialize_product($product->id, $date);
        }
        
        // Verify and fix opening values: ensure each product's opening matches the previous day's actual closing
        // This prevents stale or incorrect opening values from propagating
        foreach ($products as $product) {
            $stock_record = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table_stock WHERE product_id = %d AND record_date = %s",
                    $product->id,
                    $date
                )
            );
            
            if (!$stock_record) {
                continue;
            }
            
            // Get the most recent closing value from any previous date
            $prev_closing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT closing FROM $table_stock WHERE product_id = %d AND record_date < %s ORDER BY record_date DESC LIMIT 1",
                    $product->id,
                    $date
                )
            );
            
            // Default to 0 if no previous record exists
            $expected_opening = $prev_closing !== null ? floatval($prev_closing) : 0;
            
            // Fix opening if it doesn't match the previous closing
            if (abs(floatval($stock_record->opening) - $expected_opening) > 0.001) {
                $new_closing = $expected_opening + 
                              floatval($stock_record->import_qty) - 
                              floatval($stock_record->cash_supply) - 
                              floatval($stock_record->credit_supply) + 
                              floatval($stock_record->not_supplied) - 
                              floatval($stock_record->supplied_today) - 
                              floatval($stock_record->to_packing_store) + 
                              floatval($stock_record->from_packing_store);
                
                $wpdb->update(
                    $table_stock,
                    array(
                        'opening' => $expected_opening,
                        'closing' => $new_closing
                    ),
                    array('id' => $stock_record->id),
                    array('%f', '%f'),
                    array('%d')
                );
            }
        }
        
        // Sync from_packing_store from packing store's to_sales for ALL products
        // This ensures the stock form always shows the correct from_packing value
        foreach ($products as $product) {
            // Get packing store's to_sales for this product and date
            $packing_to_sales = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT to_sales FROM $table_packing WHERE product_id = %d AND record_date = %s",
                    $product->id,
                    $date
                )
            );
            
            $from_packing_value = $packing_to_sales !== null ? floatval($packing_to_sales) : 0;
            
            // Get current stock record (re-read after opening fix)
            $stock_record = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table_stock WHERE product_id = %d AND record_date = %s",
                    $product->id,
                    $date
                )
            );
            
            if ($stock_record && floatval($stock_record->from_packing_store) != $from_packing_value) {
                // Recalculate closing with the correct from_packing_store value
                // Formula: closing = opening + import - cash - credit + not_supplied - supplied_today - to_packing + from_packing
                $new_closing = floatval($stock_record->opening) + 
                              floatval($stock_record->import_qty) - 
                              floatval($stock_record->cash_supply) - 
                              floatval($stock_record->credit_supply) + 
                              floatval($stock_record->not_supplied) - 
                              floatval($stock_record->supplied_today) - 
                              floatval($stock_record->to_packing_store) + 
                              $from_packing_value;
                
                $wpdb->update(
                    $table_stock,
                    array(
                        'from_packing_store' => $from_packing_value,
                        'closing' => $new_closing
                    ),
                    array('id' => $stock_record->id),
                    array('%f', '%f'),
                    array('%d')
                );
                
                // Cascade the closing change to next day's opening if it exists
                self::cascade_closing_to_next_day($product->id, $date);
            }
        }
        
        $query = $wpdb->prepare(
            "SELECT s.*, p.name as product_name, p.price 
            FROM $table_stock s 
            JOIN $table_products p ON s.product_id = p.id 
            WHERE s.record_date = %s AND p.status = 'active'
            ORDER BY p.name",
            $date
        );
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Update stock record
     */
    public static function update($data) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        $table_history = CFI_Database::get_table('stock_history');
        
        $staff_id = get_current_user_id();
        $date = current_time('Y-m-d');
        
        foreach ($data as $item) {
            $product_id = intval($item['product_id']);
            $to_packing = floatval($item['to_packing_store'] ?? 0);
            
            // Get current record
            $current = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE product_id = %d AND record_date = %s",
                    $product_id,
                    $date
                )
            );
            
            if (!$current) {
                self::initialize_product($product_id, $date);
                continue;
            }
            
            // Calculate new closing
            $closing = $current->opening + $current->import_qty - $current->cash_supply - 
                       $current->credit_supply + $current->not_supplied - $current->supplied_today - 
                       $to_packing + $current->from_packing_store;
            
            // Record history if value changed
            if ($to_packing != $current->to_packing_store) {
                $wpdb->insert(
                    $table_history,
                    array(
                        'stock_id' => $current->id,
                        'product_id' => $product_id,
                        'record_date' => $date,
                        'field_name' => 'to_packing_store',
                        'old_value' => $current->to_packing_store,
                        'new_value' => $to_packing,
                        'staff_id' => $staff_id,
                    ),
                    array('%d', '%d', '%s', '%s', '%f', '%f', '%d')
                );
            }
            
            // Update stock
            $wpdb->update(
                $table,
                array(
                    'to_packing_store' => $to_packing,
                    'closing' => $closing,
                    'staff_id' => $staff_id,
                ),
                array('id' => $current->id),
                array('%f', '%f', '%d'),
                array('%d')
            );
            
            // Cascade closing change to next day's opening if it exists
            self::cascade_closing_to_next_day($product_id, $date);
            
            // Update packing store from_sales
            CFI_Packing::update_from_sales($product_id, $to_packing, $date);
        }
        
        return true;
    }
    
    /**
     * Update cash supply from order (atomic to prevent race conditions)
     */
    public static function update_cash_supply($product_id, $quantity, $date) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        self::initialize_product($product_id, $date);
        
        // Atomic update: add to cash_supply and recalculate closing in a single query
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET 
                    cash_supply = cash_supply + %f,
                    closing = opening + import_qty - (cash_supply + %f) - credit_supply + not_supplied - supplied_today - to_packing_store + from_packing_store
                WHERE product_id = %d AND record_date = %s",
                $quantity,
                $quantity,
                $product_id,
                $date
            )
        );
        
        // Cascade closing change to next day's opening if it exists
        self::cascade_closing_to_next_day($product_id, $date);
    }
    
    /**
     * Update credit supply from debtor order (atomic to prevent race conditions)
     */
    public static function update_credit_supply($product_id, $quantity, $date) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        self::initialize_product($product_id, $date);
        
        // Atomic update: add to credit_supply and recalculate closing in a single query
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET 
                    credit_supply = credit_supply + %f,
                    closing = opening + import_qty - cash_supply - (credit_supply + %f) + not_supplied - supplied_today - to_packing_store + from_packing_store
                WHERE product_id = %d AND record_date = %s",
                $quantity,
                $quantity,
                $product_id,
                $date
            )
        );
        
        // Cascade closing change to next day's opening if it exists
        self::cascade_closing_to_next_day($product_id, $date);
    }
    
    /**
     * Update import quantity (atomic to prevent race conditions)
     */
    public static function update_import($product_id, $quantity, $date) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        self::initialize_product($product_id, $date);
        
        // Atomic update: add to import_qty and recalculate closing in a single query
        // This prevents race conditions when multiple operations happen concurrently
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET 
                    import_qty = import_qty + %f,
                    closing = opening + (import_qty + %f) - cash_supply - credit_supply + not_supplied - supplied_today - to_packing_store + from_packing_store
                WHERE product_id = %d AND record_date = %s",
                $quantity,
                $quantity,
                $product_id,
                $date
            )
        );
        
        // Cascade closing change to next day's opening if it exists
        self::cascade_closing_to_next_day($product_id, $date);
    }
    
    /**
     * Update from packing store (atomic to prevent race conditions)
     */
    public static function update_from_packing($product_id, $quantity, $date) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        self::initialize_product($product_id, $date);
        
        // Atomic update: add to from_packing_store and recalculate closing in a single query
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET 
                    from_packing_store = from_packing_store + %f,
                    closing = opening + import_qty - cash_supply - credit_supply + not_supplied - supplied_today - to_packing_store + (from_packing_store + %f)
                WHERE product_id = %d AND record_date = %s",
                $quantity,
                $quantity,
                $product_id,
                $date
            )
        );
        
        // Cascade closing change to next day's opening if it exists
        self::cascade_closing_to_next_day($product_id, $date);
    }
    
    /**
     * Update not supplied quantity (atomic to prevent race conditions)
     */
    public static function update_not_supplied_qty($product_id, $quantity, $date) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        self::initialize_product($product_id, $date);
        
        // Atomic update: add to not_supplied and recalculate closing in a single query
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET 
                    not_supplied = not_supplied + %f,
                    closing = opening + import_qty - cash_supply - credit_supply + (not_supplied + %f) - supplied_today - to_packing_store + from_packing_store
                WHERE product_id = %d AND record_date = %s",
                $quantity,
                $quantity,
                $product_id,
                $date
            )
        );
        
        // Cascade closing change to next day's opening if it exists
        self::cascade_closing_to_next_day($product_id, $date);
    }
    
    /**
     * Update supplied today quantity (atomic to prevent race conditions)
     */
    public static function update_supplied_today_qty($product_id, $quantity, $date) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        self::initialize_product($product_id, $date);
        
        // Atomic update: add to supplied_today and recalculate closing in a single query
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET 
                    supplied_today = supplied_today + %f,
                    closing = opening + import_qty - cash_supply - credit_supply + not_supplied - (supplied_today + %f) - to_packing_store + from_packing_store
                WHERE product_id = %d AND record_date = %s",
                $quantity,
                $quantity,
                $product_id,
                $date
            )
        );
        
        // Cascade closing change to next day's opening if it exists
        self::cascade_closing_to_next_day($product_id, $date);
    }
    
    /**
     * Get stock history
     */
    public static function get_history($start_date = '', $end_date = '', $product_id = 0) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        $table_products = CFI_Database::get_table('products');
        
        $where = array('1=1');
        $params = array();
        
        if ($start_date) {
            $where[] = 's.record_date >= %s';
            $params[] = $start_date;
        }
        
        if ($end_date) {
            $where[] = 's.record_date <= %s';
            $params[] = $end_date;
        }
        
        if ($product_id) {
            $where[] = 's.product_id = %d';
            $params[] = $product_id;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = "SELECT s.*, p.name as product_name, u.display_name as staff_name 
                  FROM $table s 
                  JOIN $table_products p ON s.product_id = p.id 
                  LEFT JOIN {$wpdb->users} u ON s.staff_id = u.ID 
                  WHERE $where_clause 
                  ORDER BY s.record_date DESC, p.name";
        
        return $wpdb->get_results($params ? $wpdb->prepare($query, $params) : $query);
    }
    
    /**
     * Add not supplied record
     */
    public static function add_not_supplied($records) {
        global $wpdb;
        $table = CFI_Database::get_table('not_supplied');
        
        $staff_id = get_current_user_id();
        $date = current_time('Y-m-d');
        $time = current_time('H:i:s');
        
        foreach ($records as $record) {
            $product_id = intval($record['product_id']);
            $quantity = floatval($record['quantity']);
            $customer_name = sanitize_text_field($record['customer_name'] ?? '');
            $remark = sanitize_textarea_field($record['remark'] ?? '');
            
            $wpdb->insert(
                $table,
                array(
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'customer_name' => $customer_name,
                    'remark' => $remark,
                    'is_supplied' => 0,
                    'record_date' => $date,
                    'record_time' => $time,
                    'staff_id' => $staff_id,
                ),
                array('%d', '%f', '%s', '%s', '%d', '%s', '%s', '%d')
            );
            
            // Update stock not_supplied column
            self::update_not_supplied_qty($product_id, $quantity, $date);
        }
        
        return true;
    }
    
    /**
     * Get not supplied records
     */
    public static function get_not_supplied($date) {
        global $wpdb;
        $table = CFI_Database::get_table('not_supplied');
        $table_products = CFI_Database::get_table('products');
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ns.*, p.name as product_name, u.display_name as staff_name 
                FROM $table ns 
                JOIN $table_products p ON ns.product_id = p.id 
                LEFT JOIN {$wpdb->users} u ON ns.staff_id = u.ID 
                WHERE ns.record_date = %s 
                ORDER BY ns.record_time DESC",
                $date
            )
        );
    }
    
    /**
     * Mark as supplied
     */
    public static function mark_as_supplied($id) {
        global $wpdb;
        $table = CFI_Database::get_table('not_supplied');
        
        return $wpdb->update(
            $table,
            array(
                'is_supplied' => 1,
                'supplied_date' => current_time('Y-m-d'),
                'supplied_by' => get_current_user_id(),
            ),
            array('id' => $id),
            array('%d', '%s', '%d'),
            array('%d')
        );
    }
    
    /**
     * Get not supplied history
     */
    public static function get_not_supplied_history($start_date = '', $end_date = '') {
        global $wpdb;
        $table = CFI_Database::get_table('not_supplied');
        $table_products = CFI_Database::get_table('products');
        
        $where = array('1=1');
        $params = array();
        
        if ($start_date) {
            $where[] = 'ns.record_date >= %s';
            $params[] = $start_date;
        }
        
        if ($end_date) {
            $where[] = 'ns.record_date <= %s';
            $params[] = $end_date;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = "SELECT ns.*, p.name as product_name, u.display_name as staff_name 
                  FROM $table ns 
                  JOIN $table_products p ON ns.product_id = p.id 
                  LEFT JOIN {$wpdb->users} u ON ns.staff_id = u.ID 
                  WHERE $where_clause 
                  ORDER BY ns.record_date DESC, ns.record_time DESC";
        
        return $wpdb->get_results($params ? $wpdb->prepare($query, $params) : $query);
    }
    
    /**
     * Add supplied today record
     */
    public static function add_supplied_today($records) {
        global $wpdb;
        $table = CFI_Database::get_table('supplied_today');
        
        $staff_id = get_current_user_id();
        $date = current_time('Y-m-d');
        $time = current_time('H:i:s');
        
        foreach ($records as $record) {
            $product_id = intval($record['product_id']);
            $quantity = floatval($record['quantity']);
            $customer_name = sanitize_text_field($record['customer_name'] ?? '');
            $remark = sanitize_textarea_field($record['remark'] ?? '');
            
            $wpdb->insert(
                $table,
                array(
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'customer_name' => $customer_name,
                    'remark' => $remark,
                    'record_date' => $date,
                    'record_time' => $time,
                    'staff_id' => $staff_id,
                ),
                array('%d', '%f', '%s', '%s', '%s', '%s', '%d')
            );
            
            // Update stock supplied_today column
            self::update_supplied_today_qty($product_id, $quantity, $date);
        }
        
        return true;
    }
    
    /**
     * Get supplied today records
     */
    public static function get_supplied_today($date) {
        global $wpdb;
        $table = CFI_Database::get_table('supplied_today');
        $table_products = CFI_Database::get_table('products');
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.*, p.name as product_name, u.display_name as staff_name 
                FROM $table st 
                JOIN $table_products p ON st.product_id = p.id 
                LEFT JOIN {$wpdb->users} u ON st.staff_id = u.ID 
                WHERE st.record_date = %s 
                ORDER BY st.record_time DESC",
                $date
            )
        );
    }
    
    /**
     * Get supplied today history
     */
    public static function get_supplied_today_history($start_date = '', $end_date = '') {
        global $wpdb;
        $table = CFI_Database::get_table('supplied_today');
        $table_products = CFI_Database::get_table('products');
        
        $where = array('1=1');
        $params = array();
        
        if ($start_date) {
            $where[] = 'st.record_date >= %s';
            $params[] = $start_date;
        }
        
        if ($end_date) {
            $where[] = 'st.record_date <= %s';
            $params[] = $end_date;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = "SELECT st.*, p.name as product_name, u.display_name as staff_name 
                  FROM $table st 
                  JOIN $table_products p ON st.product_id = p.id 
                  LEFT JOIN {$wpdb->users} u ON st.staff_id = u.ID 
                  WHERE $where_clause 
                  ORDER BY st.record_date DESC, st.record_time DESC";
        
        return $wpdb->get_results($params ? $wpdb->prepare($query, $params) : $query);
    }
    
    /**
     * Daily reset - Carry forward closing to next day's opening
     * Resilient against late WordPress cron execution: uses the most recent
     * record date per product (not current_time) to determine what to carry forward.
     */
    public static function daily_reset() {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        $today = current_time('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        
        // Get all products
        $products = CFI_Products::get_all();
        
        foreach ($products as $product) {
            // Get the most recent closing value for this product (from any date up to and including today)
            // This handles late cron execution: even if the cron fires the next morning,
            // we use the actual most recent closing, not just "today's" closing.
            $latest = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT closing, record_date FROM $table WHERE product_id = %d AND record_date <= %s ORDER BY record_date DESC LIMIT 1",
                    $product->id,
                    $today
                )
            );
            
            if (!$latest) {
                continue;
            }
            
            $closing_value = floatval($latest->closing);
            
            // Initialize tomorrow's record
            self::initialize_product($product->id, $tomorrow);
            
            // Always update tomorrow's opening and closing to match the latest closing
            // Use a conditional update that only changes if no activity has happened yet
            // (i.e., all activity columns are still 0)
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET 
                        opening = %f,
                        closing = %f + import_qty - cash_supply - credit_supply + not_supplied - supplied_today - to_packing_store + from_packing_store
                    WHERE product_id = %d AND record_date = %s",
                    $closing_value,
                    $closing_value,
                    $product->id,
                    $tomorrow
                )
            );
        }
    }
    
    /**
     * Cascade closing value change to the next day's opening.
     * When a day's closing changes, the following day's opening must be updated
     * to match, and its closing must be recalculated accordingly.
     * This prevents stale opening values from persisting after closing changes.
     */
    private static function cascade_closing_to_next_day($product_id, $date) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        // Get the current closing value for the given date
        $closing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT closing FROM $table WHERE product_id = %d AND record_date = %s",
                $product_id,
                $date
            )
        );
        
        if ($closing === null) {
            return;
        }
        
        $closing_value = floatval($closing);
        
        // Find the next record (any future date) for this product
        $next_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, record_date, opening FROM $table WHERE product_id = %d AND record_date > %s ORDER BY record_date ASC LIMIT 1",
                $product_id,
                $date
            )
        );
        
        if (!$next_record) {
            return;
        }
        
        // Only update if the opening is actually different (avoid unnecessary writes and infinite loops)
        if (abs(floatval($next_record->opening) - $closing_value) > 0.001) {
            // Atomically update the opening and recalculate closing
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET 
                        opening = %f,
                        closing = %f + import_qty - cash_supply - credit_supply + not_supplied - supplied_today - to_packing_store + from_packing_store
                    WHERE id = %d",
                    $closing_value,
                    $closing_value,
                    $next_record->id
                )
            );
            
            // Recursively cascade to subsequent days
            // Recursion is bounded: always moves to later dates and stops when openings match
            self::cascade_closing_to_next_day($product_id, $next_record->record_date);
        }
    }
    
    /**
     * Update opening stock value for a product (Super Admin only)
     * Used to set initial opening values when starting the system
     */
    public static function update_opening($product_id, $opening_value, $date = null) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        $table_history = CFI_Database::get_table('stock_history');
        
        if (!$date) {
            $date = current_time('Y-m-d');
        }
        
        // Initialize the product record if it doesn't exist
        self::initialize_product($product_id, $date);
        
        // Get current record
        $current = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE product_id = %d AND record_date = %s",
                $product_id,
                $date
            )
        );
        
        if (!$current) {
            return false;
        }
        
        // Record history if value changed
        if ($opening_value != $current->opening) {
            $wpdb->insert(
                $table_history,
                array(
                    'stock_id' => $current->id,
                    'product_id' => $product_id,
                    'record_date' => $date,
                    'field_name' => 'opening',
                    'old_value' => $current->opening,
                    'new_value' => $opening_value,
                    'staff_id' => get_current_user_id(),
                ),
                array('%d', '%d', '%s', '%s', '%f', '%f', '%d')
            );
        }
        
        // Recalculate closing with new opening
        $closing = $opening_value + $current->import_qty - $current->cash_supply - 
                   $current->credit_supply + $current->not_supplied - $current->supplied_today - 
                   $current->to_packing_store + $current->from_packing_store;
        
        // Update the record
        $result = $wpdb->update(
            $table,
            array(
                'opening' => $opening_value,
                'closing' => $closing,
                'staff_id' => get_current_user_id(),
            ),
            array('id' => $current->id),
            array('%f', '%f', '%d'),
            array('%d')
        );
        
        // Cascade closing change to next day's opening if it exists
        if ($result !== false) {
            self::cascade_closing_to_next_day($product_id, $date);
        }
        
        return $result !== false;
    }
    
    /**
     * Edit a specific stock record by ID (Super Admin only)
     * Allows editing any column and recalculates closing
     */
    public static function edit_record($stock_id, $data) {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        $table_history = CFI_Database::get_table('stock_history');
        
        // Get current record
        $current = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $stock_id)
        );
        
        if (!$current) {
            return false;
        }
        
        // Build update data - only update provided values
        $update_data = array();
        $format = array();
        
        $opening = isset($data['opening']) && $data['opening'] !== null ? floatval($data['opening']) : floatval($current->opening);
        $import_qty = isset($data['import_qty']) && $data['import_qty'] !== null ? floatval($data['import_qty']) : floatval($current->import_qty);
        $cash_supply = isset($data['cash_supply']) && $data['cash_supply'] !== null ? floatval($data['cash_supply']) : floatval($current->cash_supply);
        $credit_supply = isset($data['credit_supply']) && $data['credit_supply'] !== null ? floatval($data['credit_supply']) : floatval($current->credit_supply);
        $not_supplied = isset($data['not_supplied']) && $data['not_supplied'] !== null ? floatval($data['not_supplied']) : floatval($current->not_supplied);
        $supplied_today = isset($data['supplied_today']) && $data['supplied_today'] !== null ? floatval($data['supplied_today']) : floatval($current->supplied_today);
        $to_packing_store = isset($data['to_packing_store']) && $data['to_packing_store'] !== null ? floatval($data['to_packing_store']) : floatval($current->to_packing_store);
        $from_packing_store = isset($data['from_packing_store']) && $data['from_packing_store'] !== null ? floatval($data['from_packing_store']) : floatval($current->from_packing_store);
        
        // Recalculate closing
        // Formula: closing = opening + import - cash - credit + not_supplied - supplied_today - to_packing + from_packing
        $closing = $opening + $import_qty - $cash_supply - $credit_supply + $not_supplied - $supplied_today - $to_packing_store + $from_packing_store;
        
        // Record history for changed fields
        $staff_id = get_current_user_id();
        $fields_to_check = array(
            'opening' => $opening,
            'import_qty' => $import_qty,
            'cash_supply' => $cash_supply,
            'credit_supply' => $credit_supply,
            'not_supplied' => $not_supplied,
            'supplied_today' => $supplied_today,
            'to_packing_store' => $to_packing_store,
            'from_packing_store' => $from_packing_store,
        );
        
        foreach ($fields_to_check as $field => $new_value) {
            $old_value = floatval($current->$field);
            if ($new_value != $old_value) {
                $wpdb->insert(
                    $table_history,
                    array(
                        'stock_id' => $stock_id,
                        'product_id' => $current->product_id,
                        'record_date' => $current->record_date,
                        'field_name' => $field,
                        'old_value' => $old_value,
                        'new_value' => $new_value,
                        'staff_id' => $staff_id,
                    ),
                    array('%d', '%d', '%s', '%s', '%f', '%f', '%d')
                );
            }
        }
        
        // Update the record
        $result = $wpdb->update(
            $table,
            array(
                'opening' => $opening,
                'import_qty' => $import_qty,
                'cash_supply' => $cash_supply,
                'credit_supply' => $credit_supply,
                'not_supplied' => $not_supplied,
                'supplied_today' => $supplied_today,
                'to_packing_store' => $to_packing_store,
                'from_packing_store' => $from_packing_store,
                'closing' => $closing,
                'staff_id' => $staff_id,
            ),
            array('id' => $stock_id),
            array('%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%d'),
            array('%d')
        );
        
        // Cascade closing change to next day's opening if it exists
        if ($result !== false) {
            self::cascade_closing_to_next_day($current->product_id, $current->record_date);
        }
        
        return $result !== false;
    }
    
    /**
     * Migrate existing records with 0 opening to use the last closing value
     * This fixes records that were created before the skipped-day fix
     */
    public static function migrate_opening_values() {
        global $wpdb;
        $table = CFI_Database::get_table('stock');
        
        // Get all records with opening = 0 that might need migration
        $records_to_fix = $wpdb->get_results(
            "SELECT s.id, s.product_id, s.record_date, s.opening, s.closing,
                    s.import_qty, s.cash_supply, s.credit_supply, s.not_supplied, 
                    s.supplied_today, s.to_packing_store, s.from_packing_store
             FROM $table s
             WHERE s.opening = 0
             ORDER BY s.record_date ASC"
        );
        
        $fixed_count = 0;
        
        foreach ($records_to_fix as $record) {
            // Find the most recent closing value before this date for this product
            $prev_closing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT closing FROM $table 
                     WHERE product_id = %d AND record_date < %s 
                     ORDER BY record_date DESC LIMIT 1",
                    $record->product_id,
                    $record->record_date
                )
            );
            
            // If there's a previous closing value and it's not 0, update this record
            if ($prev_closing && floatval($prev_closing) > 0) {
                $new_opening = floatval($prev_closing);
                
                // Recalculate closing with the new opening
                $new_closing = $new_opening + floatval($record->import_qty) - floatval($record->cash_supply) 
                             - floatval($record->credit_supply) + floatval($record->not_supplied) 
                             - floatval($record->supplied_today) - floatval($record->to_packing_store) 
                             + floatval($record->from_packing_store);
                
                $wpdb->update(
                    $table,
                    array(
                        'opening' => $new_opening,
                        'closing' => $new_closing,
                    ),
                    array('id' => $record->id),
                    array('%f', '%f'),
                    array('%d')
                );
                
                $fixed_count++;
            }
        }
        
        return $fixed_count;
    }
}