<?php
/**
 * Admin Panel Page Template - REBUILT FROM SCRATCH
 * Uses direct database insertion for reliability
 */

if (!defined('ABSPATH')) {
    exit;
}

// Only admins can access this page
if (!CFI_Auth::is_cfi_admin()) {
    echo '<div class="cfi-main"><div class="cfi-container"><div class="cfi-glass" style="text-align: center; padding: 3rem;"><i class="fa-solid fa-lock" style="font-size: 3rem; color: #dc2626; margin-bottom: 1rem;"></i><h2>Access Denied</h2><p>You do not have permission to access this page.</p></div></div></div>';
    return;
}

// Ensure database tables exist - run on every admin panel load
CFI_Database::maybe_create_tables();
nocache_headers();

// Process form submissions directly (no AJAX - more reliable)
$message = '';
$message_type = '';

// Amount validation helper (shared across admin forms)
$is_valid_amount = function($value) {
    return $value !== '' && (
        preg_match('/^\d+$/', $value) ||
        preg_match('/^\d{1,3}(,\d{3})*$/', $value)
    );
};

// Add Product Form Submission
if (isset($_POST['cfi_add_product_submit']) && wp_verify_nonce($_POST['cfi_product_nonce'], 'cfi_add_product')) {
    $product_name = sanitize_text_field($_POST['product_name']);
    $product_price_raw = isset($_POST['product_price']) ? sanitize_text_field($_POST['product_price']) : '';
    $product_price_clean = $is_valid_amount($product_price_raw) ? $product_price_raw : '';
    $product_price = floatval(str_replace(',', '', $product_price_clean));
    
    if (empty($product_name)) {
        $message = 'Please enter a product name';
        $message_type = 'error';
    } elseif ($product_price <= 0) {
        $message = 'Please enter a valid price greater than 0';
        $message_type = 'error';
    } else {
        global $wpdb;
        $table = $wpdb->prefix . 'cfi_products';
        
        $result = $wpdb->insert(
            $table,
            array(
                'name' => $product_name,
                'price' => $product_price,
                'unit' => 'unit',
                'category' => '',
                'status' => 'active',
            ),
            array('%s', '%f', '%s', '%s', '%s')
        );
        
        if ($result) {
            $message = 'Product "' . esc_html($product_name) . '" added successfully!';
            $message_type = 'success';
        } else {
            $message = 'Failed to add product. Database error: ' . $wpdb->last_error;
            $message_type = 'error';
        }
    }
}

// Delete Product
if (isset($_POST['cfi_delete_product']) && wp_verify_nonce($_POST['cfi_delete_nonce'], 'cfi_delete_product')) {
    $product_id = intval($_POST['product_id']);
    global $wpdb;
    $table = $wpdb->prefix . 'cfi_products';
    
    $result = $wpdb->update(
        $table,
        array('status' => 'deleted'),
        array('id' => $product_id),
        array('%s'),
        array('%d')
    );
    
    if ($result !== false) {
        $message = 'Product deleted successfully';
        $message_type = 'success';
    } else {
        $message = 'Failed to delete product';
        $message_type = 'error';
    }
}

// Edit Product
if (isset($_POST['cfi_edit_product_submit']) && wp_verify_nonce($_POST['cfi_edit_nonce'], 'cfi_edit_product')) {
    $product_id = intval($_POST['edit_product_id']);
    $product_name = sanitize_text_field($_POST['edit_product_name']);
    $product_price_raw = isset($_POST['edit_product_price']) ? sanitize_text_field($_POST['edit_product_price']) : '';
    $product_price_clean = $is_valid_amount($product_price_raw) ? $product_price_raw : '';
    $product_price = floatval(str_replace(',', '', $product_price_clean));
    
    global $wpdb;
    $table = $wpdb->prefix . 'cfi_products';
    
    $result = $wpdb->update(
        $table,
        array('name' => $product_name, 'price' => $product_price),
        array('id' => $product_id),
        array('%s', '%f'),
        array('%d')
    );
    
    if ($result !== false) {
        $message = 'Product updated successfully';
        $message_type = 'success';
    } else {
        $message = 'Failed to update product';
        $message_type = 'error';
    }
}

// Add Debtor Form Submission
if (isset($_POST['cfi_add_debtor_submit']) && wp_verify_nonce($_POST['cfi_debtor_nonce'], 'cfi_add_debtor')) {
    $debtor_name = sanitize_text_field($_POST['debtor_name']);
    $debtor_phone = sanitize_text_field($_POST['debtor_phone']);
    $initial_debt_raw = isset($_POST['debtor_initial_debt']) ? sanitize_text_field($_POST['debtor_initial_debt']) : '';
    $initial_debt_clean = $is_valid_amount($initial_debt_raw) ? $initial_debt_raw : '0';
    $initial_debt = floatval(str_replace(',', '', $initial_debt_clean));
    
    if (empty($debtor_name)) {
        $message = 'Please enter a debtor name';
        $message_type = 'error';
    } else {
        global $wpdb;
        $table = $wpdb->prefix . 'cfi_debtors';
        
        $result = $wpdb->insert(
            $table,
            array(
                'name' => $debtor_name,
                'phone' => $debtor_phone,
                'email' => '',
                'address' => '',
                'total_debt' => $initial_debt,
                'status' => 'active',
                'created_by' => get_current_user_id(),
            ),
            array('%s', '%s', '%s', '%s', '%f', '%s', '%d')
        );
        
        if ($result) {
            // Record initial debt as a transaction if debt > 0
            if ($initial_debt > 0) {
                $debtor_id = $wpdb->insert_id;
                $trans_table = $wpdb->prefix . 'cfi_debtor_transactions';
                $wpdb->insert(
                    $trans_table,
                    array(
                        'debtor_id' => $debtor_id,
                        'transaction_type' => 'initial',
                        'amount' => $initial_debt,
                        'balance_before' => 0,
                        'balance_after' => $initial_debt,
                        'description' => 'Initial debt balance',
                        'staff_id' => get_current_user_id(),
                        'transaction_date' => current_time('Y-m-d'),
                        'transaction_time' => current_time('H:i:s')
                    ),
                    array('%d', '%s', '%f', '%f', '%f', '%s', '%d', '%s', '%s')
                );
            }
            $message = 'Debtor "' . esc_html($debtor_name) . '" added with initial debt: ₦' . number_format($initial_debt, 0);
            $message_type = 'success';
        } else {
            $message = 'Failed to add debtor. Database error: ' . $wpdb->last_error;
            $message_type = 'error';
        }
    }
}

// Update Debtor Debt Amount
if (isset($_POST['cfi_update_debtor_debt']) && wp_verify_nonce($_POST['cfi_update_debt_nonce'], 'cfi_update_debtor_debt')) {
    $debtor_id = intval($_POST['debtor_id']);
    $new_debt_raw = isset($_POST['new_debt_amount']) ? sanitize_text_field($_POST['new_debt_amount']) : '';
    $new_debt_clean = $is_valid_amount($new_debt_raw) ? $new_debt_raw : '0';
    $new_debt = floatval(str_replace(',', '', $new_debt_clean));
    
    global $wpdb;
    $table = $wpdb->prefix . 'cfi_debtors';
    $debtor = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $debtor_id));
    
    if ($debtor) {
        $old_debt = $debtor->total_debt;
        $wpdb->update($table, array('total_debt' => $new_debt), array('id' => $debtor_id), array('%f'), array('%d'));
        
        // Record the adjustment
        $trans_table = $wpdb->prefix . 'cfi_debtor_transactions';
        $wpdb->insert(
            $trans_table,
            array(
                'debtor_id' => $debtor_id,
                'transaction_type' => 'adjustment',
                'amount' => abs($new_debt - $old_debt),
                'balance_before' => $old_debt,
                'balance_after' => $new_debt,
                'description' => 'Admin adjusted debt from ₦' . number_format($old_debt, 0) . ' to ₦' . number_format($new_debt, 0),
                'staff_id' => get_current_user_id(),
                'transaction_date' => current_time('Y-m-d'),
                'transaction_time' => current_time('H:i:s')
            ),
            array('%d', '%s', '%f', '%f', '%f', '%s', '%d', '%s', '%s')
        );
        
        $message = 'Debt updated for ' . esc_html($debtor->name);
        $message_type = 'success';
    } else {
        $message = 'Debtor not found';
        $message_type = 'error';
    }
}

// Delete Debtor
if (isset($_POST['cfi_delete_debtor']) && wp_verify_nonce($_POST['cfi_debtor_delete_nonce'], 'cfi_delete_debtor')) {
    $debtor_id = intval($_POST['debtor_id']);
    global $wpdb;
    $table = $wpdb->prefix . 'cfi_debtors';
    
    $result = $wpdb->update(
        $table,
        array('status' => 'deleted'),
        array('id' => $debtor_id),
        array('%s'),
        array('%d')
    );
    
    if ($result !== false) {
        $message = 'Debtor deleted successfully';
        $message_type = 'success';
    } else {
        $message = 'Failed to delete debtor';
        $message_type = 'error';
    }
}

// Clear All Test Data (Super Admin Only)
if (isset($_POST['cfi_clear_all_data']) && wp_verify_nonce($_POST['cfi_clear_data_nonce'], 'cfi_clear_all_data') && CFI_Auth::is_super_admin()) {
    global $wpdb;
    
    // Tables to clear (all records and histories)
    $tables_to_clear = array(
        'cfi_orders',
        'cfi_order_items',
        'cfi_stock',
        'cfi_stock_history',
        'cfi_packing_store',
        'cfi_packing_history',
        'cfi_debtor_transactions',
        'cfi_expenses',
        'cfi_imports',
        'cfi_not_supplied',
        'cfi_supplied_today',
        'cfi_cashout',
        'cfi_financial_summary',
        'cfi_financial_history',
        'cfi_transfer_history',
        'cfi_reconciliation',
        'cfi_reconciliation_history',
        'cfi_sync_queue',
    );
    
    $success_count = 0;
    foreach ($tables_to_clear as $table) {
        $full_table = $wpdb->prefix . $table;
        $result = $wpdb->query("TRUNCATE TABLE `$full_table`");
        if ($result !== false) {
            $success_count++;
        }
    }
    
    // Reset debtors' debt to 0 (keep debtors but clear their debts)
    $wpdb->query("UPDATE `{$wpdb->prefix}cfi_debtors` SET `total_debt` = 0 WHERE 1=1");
    
    if ($success_count > 0) {
        $message = 'All test data has been cleared! ' . $success_count . ' tables emptied. Debtor balances reset to ₦0.';
        $message_type = 'success';
    } else {
        $message = 'Failed to clear data. Please try again.';
        $message_type = 'error';
    }
}

// Fetch products and debtors
global $wpdb;
$products_table = $wpdb->prefix . 'cfi_products';
$products = $wpdb->get_results("SELECT * FROM $products_table WHERE status != 'deleted' ORDER BY name ASC");

$debtors_table = $wpdb->prefix . 'cfi_debtors';
$debtors = $wpdb->get_results("SELECT * FROM $debtors_table WHERE status = 'active' ORDER BY name ASC");

$is_super_admin = CFI_Auth::is_super_admin();

// For super admin - get stock and packing opening values
$stock_openings = array();
$packing_openings = array();
if ($is_super_admin && !empty($products)) {
    $today = current_time('Y-m-d');
    $stock_table = $wpdb->prefix . 'cfi_stock';
    $packing_table = $wpdb->prefix . 'cfi_packing_store';
    
    foreach ($products as $product) {
        // Get stock opening
        $stock = $wpdb->get_row($wpdb->prepare(
            "SELECT opening FROM $stock_table WHERE product_id = %d AND record_date = %s",
            $product->id, $today
        ));
        $stock_openings[$product->id] = $stock ? $stock->opening : 0;
        
        // Get packing opening
        $packing = $wpdb->get_row($wpdb->prepare(
            "SELECT opening FROM $packing_table WHERE product_id = %d AND record_date = %s",
            $product->id, $today
        ));
        $packing_openings[$product->id] = $packing ? $packing->opening : 0;
    }
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
<main class="cfi-main">
    <div class="cfi-container">
        <div class="cfi-page-title">
            <h1>
                <i class="fa-solid fa-gear"></i>
                Admin Panel
            </h1>
        </div>
        
        <?php if ($message) : ?>
        <div class="cfi-alert cfi-alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>" style="padding: 1rem; margin-bottom: 1.5rem; border-radius: 8px; background: <?php echo $message_type === 'success' ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#166534' : '#991b1b'; ?>; border: 1px solid <?php echo $message_type === 'success' ? '#86efac' : '#fecaca'; ?>;">
            <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo esc_html($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Products Section -->
        <div class="cfi-admin-section cfi-glass" style="margin-bottom: 1.5rem;">
            <h3><i class="fa-solid fa-box"></i> Products Management</h3>
            
            <form method="POST" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 1.5rem; padding: 1rem; background: rgba(0,25,67,0.03); border-radius: 8px;">
                <?php wp_nonce_field('cfi_add_product', 'cfi_product_nonce'); ?>
                <div class="cfi-form-group" style="flex: 2; min-width: 180px; margin: 0;">
                    <label for="product_name" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #001943;">Product Name</label>
                    <input type="text" id="product_name" name="product_name" class="cfi-input" placeholder="Enter product name" required style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem;">
                </div>
                <div class="cfi-form-group" style="flex: 1; min-width: 120px; margin: 0;">
                    <label for="product_price" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #001943;">Price (₦)</label>
                    <input type="text" id="product_price" name="product_price" class="cfi-input cfi-price-input" inputmode="numeric" pattern="[0-9,]*" placeholder="0" required style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem;">
                </div>
                <button type="submit" name="cfi_add_product_submit" class="cfi-btn cfi-btn-success" style="background: #001943; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-plus"></i>
                    Add Product
                </button>
            </form>
            
            <div class="cfi-table-wrapper">
                <table class="cfi-table cfi-table-responsive" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #001943; color: white;">
                            <th style="padding: 0.75rem; text-align: left;">Name</th>
                            <th style="padding: 0.75rem; text-align: left;">Price</th>
                            <th style="padding: 0.75rem; text-align: left;">Status</th>
                            <th style="padding: 0.75rem; text-align: left;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)) : ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 2rem;">
                                <i class="fa-solid fa-box" style="font-size: 2rem; color: #94a3b8; display: block; margin-bottom: 1rem;"></i>
                                <p>No products yet. Add your first product above.</p>
                            </td>
                        </tr>
                        <?php else : ?>
                        <?php foreach ($products as $product) : ?>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 0.75rem;"><?php echo esc_html($product->name); ?></td>
                            <td style="padding: 0.75rem;">₦<?php echo number_format((float)$product->price, 0); ?></td>
                            <td style="padding: 0.75rem;">
                                <span style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; background: <?php echo $product->status === 'active' ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $product->status === 'active' ? '#166534' : '#991b1b'; ?>;">
                                    <?php echo ucfirst($product->status); ?>
                                </span>
                            </td>
                            <td style="padding: 0.75rem;">
                                <button type="button" class="cfi-edit-product" data-id="<?php echo esc_attr($product->id); ?>" data-name="<?php echo esc_attr($product->name); ?>" data-price="<?php echo esc_attr($product->price); ?>" style="background: #e2e8f0; color: #001943; border: none; padding: 0.5rem 0.75rem; border-radius: 6px; cursor: pointer; margin-right: 0.5rem;">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <form method="POST" style="display: inline;">
                                    <?php wp_nonce_field('cfi_delete_product', 'cfi_delete_nonce'); ?>
                                    <input type="hidden" name="product_id" value="<?php echo esc_attr($product->id); ?>">
                                    <button type="submit" name="cfi_delete_product" onclick="return confirm('Are you sure you want to delete this product?');" style="background: #dc2626; color: white; border: none; padding: 0.5rem 0.75rem; border-radius: 6px; cursor: pointer;">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Debtors Section - CFI Admin and Super Admin -->
        <div class="cfi-admin-section cfi-glass" style="margin-bottom: 1.5rem;">
            <h3><i class="fa-solid fa-user-tag"></i> Debtors Management</h3>
            
            <form method="POST" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 1.5rem; padding: 1rem; background: rgba(0,25,67,0.03); border-radius: 8px;">
                <?php wp_nonce_field('cfi_add_debtor', 'cfi_debtor_nonce'); ?>
                <div class="cfi-form-group" style="flex: 1; min-width: 150px; margin: 0;">
                    <label for="debtor_name" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #001943;">Name</label>
                    <input type="text" id="debtor_name" name="debtor_name" class="cfi-input" required style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem;">
                </div>
                <div class="cfi-form-group" style="flex: 1; min-width: 120px; margin: 0;">
                    <label for="debtor_phone" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #001943;">Phone</label>
                    <input type="text" id="debtor_phone" name="debtor_phone" class="cfi-input" style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem;">
                </div>
                <div class="cfi-form-group" style="flex: 1; min-width: 120px; margin: 0;">
                    <label for="debtor_initial_debt" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #001943;">Initial Debt (₦)</label>
                    <input type="text" id="debtor_initial_debt" name="debtor_initial_debt" class="cfi-input cfi-price-input" inputmode="numeric" pattern="[0-9,]*" data-min="0" value="0" style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem;">
                </div>
                <button type="submit" name="cfi_add_debtor_submit" class="cfi-btn cfi-btn-success" style="background: #001943; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-user-plus"></i>
                    Add Debtor
                </button>
            </form>
            
            <div class="cfi-table-wrapper">
                <table class="cfi-table cfi-table-responsive" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #001943; color: white;">
                            <th style="padding: 0.75rem; text-align: left;">Name</th>
                            <th style="padding: 0.75rem; text-align: left;">Phone</th>
                            <th style="padding: 0.75rem; text-align: left;">Debt</th>
                            <th style="padding: 0.75rem; text-align: left;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($debtors)) : ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 2rem;">
                                <p>No debtors yet.</p>
                            </td>
                        </tr>
                        <?php else : ?>
                        <?php foreach ($debtors as $debtor) : ?>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 0.75rem;"><?php echo esc_html($debtor->name); ?></td>
                            <td style="padding: 0.75rem;"><?php echo esc_html($debtor->phone); ?></td>
                            <td style="padding: 0.75rem; color: <?php echo $debtor->total_debt > 0 ? '#dc2626' : '#16a34a'; ?>; font-weight: 600;">₦<?php echo number_format((float)$debtor->total_debt, 0); ?></td>
                            <td style="padding: 0.75rem;">
                                <button type="button" class="cfi-edit-debtor" data-id="<?php echo esc_attr($debtor->id); ?>" data-name="<?php echo esc_attr($debtor->name); ?>" data-debt="<?php echo esc_attr($debtor->total_debt); ?>" style="background: #f59e0b; color: white; border: none; padding: 0.5rem 0.75rem; border-radius: 6px; cursor: pointer; margin-right: 0.25rem;" title="Edit Debt Amount">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <form method="POST" style="display: inline;">
                                    <?php wp_nonce_field('cfi_delete_debtor', 'cfi_debtor_delete_nonce'); ?>
                                    <input type="hidden" name="debtor_id" value="<?php echo esc_attr($debtor->id); ?>">
                                    <button type="submit" name="cfi_delete_debtor" onclick="return confirm('Delete this debtor?');" style="background: #dc2626; color: white; border: none; padding: 0.5rem 0.75rem; border-radius: 6px; cursor: pointer;">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php if ($is_super_admin) : ?>
        <!-- Opening Values Section - Super Admin Only -->
        <div class="cfi-admin-section cfi-glass" style="margin-bottom: 1.5rem; border: 2px solid #16a34a;">
            <h3 style="color: #16a34a;"><i class="fa-solid fa-play-circle"></i> Opening Values <span style="font-size: 0.75rem; color: #f59e0b;">(Super Admin)</span></h3>
            <p style="color: #64748b; margin-bottom: 1rem;">Set initial opening values for Stock and Packing Store. These values are used when starting the system so staff can begin using the forms.</p>
            
            <?php if (!empty($products)) : ?>
            <div class="cfi-table-wrapper" style="margin-bottom: 1rem;">
                <table class="cfi-table cfi-table-responsive" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #16a34a; color: white;">
                            <th style="padding: 0.75rem; text-align: left;">Product</th>
                            <th style="padding: 0.75rem; text-align: center;">Stock Opening</th>
                            <th style="padding: 0.75rem; text-align: center;">Packing Opening</th>
                            <th style="padding: 0.75rem; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product) : ?>
                        <tr style="border-bottom: 1px solid #e2e8f0;" data-product-id="<?php echo esc_attr($product->id); ?>">
                            <td style="padding: 0.75rem; font-weight: 600;"><?php echo esc_html($product->name); ?></td>
                            <td style="padding: 0.75rem; text-align: center;">
                                <input type="number" 
                                       class="cfi-opening-stock-input" 
                                       data-product-id="<?php echo esc_attr($product->id); ?>"
                                       value="<?php echo esc_attr(isset($stock_openings[$product->id]) ? intval($stock_openings[$product->id]) : 0); ?>" 
                                       min="0" 
                                       step="1"
                                       style="width: 80px; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 6px; text-align: center;">
                            </td>
                            <td style="padding: 0.75rem; text-align: center;">
                                <input type="number" 
                                       class="cfi-opening-packing-input" 
                                       data-product-id="<?php echo esc_attr($product->id); ?>"
                                       value="<?php echo esc_attr(isset($packing_openings[$product->id]) ? intval($packing_openings[$product->id]) : 0); ?>" 
                                       min="0" 
                                       step="1"
                                       style="width: 80px; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 6px; text-align: center;">
                            </td>
                            <td style="padding: 0.75rem; text-align: center;">
                                <button type="button" 
                                        class="cfi-save-opening-btn" 
                                        data-product-id="<?php echo esc_attr($product->id); ?>"
                                        style="background: #16a34a; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                    <i class="fa-solid fa-save"></i> Save
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="font-size: 0.8rem; color: #64748b; margin-top: 0.5rem;">
                <i class="fa-solid fa-info-circle"></i> 
                Opening values are carried forward from the previous day's closing. Use this section to set initial values when first starting to use the system.
            </p>
            <?php else : ?>
            <p style="color: #64748b; text-align: center; padding: 1rem;">No products available. Please add products first.</p>
            <?php endif; ?>
        </div>
        
        <!-- Verify Sunday Carryover Section - Super Admin Only -->
        <div class="cfi-admin-section cfi-glass" style="margin-bottom: 1.5rem; border: 2px solid #2563eb;">
            <h3 style="color: #2563eb;"><i class="fa-solid fa-flask"></i> Verify Sunday Carryover Fix <span style="font-size: 0.75rem; color: #f59e0b;">(Super Admin)</span></h3>
            <p style="color: #64748b; margin-bottom: 1rem;">This tool simulates what happens when Sunday is skipped (business closed). It checks whether Monday's opening values would correctly carry over from Saturday's closing values using your real data.</p>
            
            <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem;">
                <button type="button" id="cfi-verify-carryover-btn" style="background: #2563eb; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-vial"></i> Run Verification
                </button>
                <button type="button" id="cfi-run-migrate-btn" style="background: #f59e0b; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600; display: none; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-wrench"></i> Run Migration Fix
                </button>
            </div>
            
            <div id="cfi-carryover-results" style="display: none;"></div>
        </div>
        
        <!-- System Scan Section - Super Admin Only -->
        <div class="cfi-admin-section cfi-glass" style="margin-bottom: 1.5rem; border: 2px solid #7c3aed;">
            <h3 style="color: #7c3aed;"><i class="fa-solid fa-magnifying-glass-chart"></i> System Scan &amp; Fix <span style="font-size: 0.75rem; color: #f59e0b;">(Super Admin)</span></h3>
            <p style="color: #64748b; margin-bottom: 1rem;">Run a comprehensive scan to verify that all form submissions, histories, and cross-form linking values across the entire site are working correctly. If issues are found, use <strong>Brutal Fix</strong> to auto-repair everything.</p>
            
            <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem;">
                <button type="button" id="cfi-system-scan-btn" style="background: #7c3aed; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-stethoscope"></i> Run System Scan
                </button>
                <button type="button" id="cfi-system-fix-btn" style="background: #dc2626; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-hammer"></i> Brutal Fix All Issues
                </button>
            </div>
            
            <div id="cfi-system-scan-results" style="display: none;"></div>
            <div id="cfi-system-fix-results" style="display: none;"></div>
        </div>
        
        <!-- Clear All Test Data Section - Super Admin Only -->
        <div class="cfi-admin-section cfi-glass" style="margin-bottom: 1.5rem; border: 2px solid #dc2626;">
            <h3 style="color: #dc2626;"><i class="fa-solid fa-exclamation-triangle"></i> Danger Zone <span style="font-size: 0.75rem; color: #f59e0b;">(Super Admin)</span></h3>
            <p style="color: #64748b; margin-bottom: 1rem;">This action will permanently delete all records and histories from the system. Use this to clear test data before going live. Products and debtors will be kept but debtor balances will be reset to ₦0.</p>
            
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                <h4 style="color: #dc2626; margin: 0 0 0.5rem 0;"><i class="fa-solid fa-warning"></i> Warning: This will delete:</h4>
                <ul style="color: #991b1b; margin: 0; padding-left: 1.5rem; font-size: 0.875rem;">
                    <li>All Orders & Order History</li>
                    <li>All Stock Records & History</li>
                    <li>All Packing Store Records & History</li>
                    <li>All Debtor Transactions (balances reset to ₦0)</li>
                    <li>All Expenses Records</li>
                    <li>All Import Records</li>
                    <li>All Not Supplied & Supplied Today Records</li>
                    <li>All Cash Out Records</li>
                    <li>All Financial Summary & History</li>
                    <li>All Transfer History</li>
                    <li>All Reconciliation Records & History</li>
                </ul>
            </div>
            
            <form method="POST" onsubmit="return confirmClearData();">
                <?php wp_nonce_field('cfi_clear_all_data', 'cfi_clear_data_nonce'); ?>
                <button type="submit" name="cfi_clear_all_data" style="background: #dc2626; color: white; padding: 1rem 2rem; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 0.75rem; font-size: 1rem;">
                    <i class="fa-solid fa-trash-can"></i>
                    Clear All Test Data
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- Edit Product Modal -->
<div id="cfi-edit-product-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 9999; align-items: center; justify-content: center;">
    <div class="cfi-modal-overlay" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 25, 67, 0.5);"></div>
    <div class="cfi-modal-content cfi-glass" style="position: relative; max-width: 400px; width: 90%; padding: 2rem; background: white; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,25,67,0.2);">
        <h3 style="color: #001943; margin-bottom: 1.5rem;"><i class="fa-solid fa-pen"></i> Edit Product</h3>
        <form method="POST">
            <?php wp_nonce_field('cfi_edit_product', 'cfi_edit_nonce'); ?>
            <input type="hidden" name="edit_product_id" id="edit-prod-id">
            <div style="margin-bottom: 1rem;">
                <label for="edit_product_name" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #001943;">Product Name</label>
                <input type="text" id="edit-prod-name" name="edit_product_name" class="cfi-input" required style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem;">
            </div>
            <div style="margin-bottom: 1rem;">
                <label for="edit_product_price" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #001943;">Price (₦)</label>
                <input type="text" id="edit-prod-price" name="edit_product_price" class="cfi-input cfi-price-input" inputmode="numeric" pattern="[0-9,]*" required style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem;">
            </div>
            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" class="cfi-modal-close" style="background: #e2e8f0; color: #001943; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Cancel</button>
                <button type="submit" name="cfi_edit_product_submit" style="background: #001943; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Debtor Debt Modal -->
<div id="cfi-edit-debtor-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 9999; align-items: center; justify-content: center;">
    <div class="cfi-modal-overlay-debtor" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 25, 67, 0.5);"></div>
    <div class="cfi-modal-content cfi-glass" style="position: relative; max-width: 400px; width: 90%; padding: 2rem; background: white; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,25,67,0.2);">
        <h3 style="color: #001943; margin-bottom: 1.5rem;"><i class="fa-solid fa-money-bill"></i> Edit Debt Amount</h3>
        <p id="edit-debtor-name-display" style="color: #64748b; margin-bottom: 1rem;"></p>
        <form method="POST">
            <?php wp_nonce_field('cfi_update_debtor_debt', 'cfi_update_debt_nonce'); ?>
            <input type="hidden" name="debtor_id" id="edit-debtor-id">
            <div style="margin-bottom: 1rem;">
                <label for="new_debt_amount" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #001943;">New Debt Amount (₦)</label>
                <input type="text" id="edit-debtor-debt" name="new_debt_amount" class="cfi-input cfi-price-input" inputmode="numeric" pattern="[0-9,]*" data-min="0" required style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem;">
            </div>
            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" class="cfi-modal-close-debtor" style="background: #e2e8f0; color: #001943; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Cancel</button>
                <button type="submit" name="cfi_update_debtor_debt" style="background: #f59e0b; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Update Debt</button>
            </div>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Edit Product - Open Modal
    $(document).on('click', '.cfi-edit-product', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var price = $(this).data('price');
        
        $('#edit-prod-id').val(id);
        $('#edit-prod-name').val(name);
        $('#edit-prod-price').val(price.toLocaleString('en-NG'));
        $('#cfi-edit-product-modal').css('display', 'flex');
    });
    
    // Edit Debtor - Open Modal
    $(document).on('click', '.cfi-edit-debtor', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var debt = $(this).data('debt');
        
        $('#edit-debtor-id').val(id);
        $('#edit-debtor-name-display').text('Debtor: ' + name);
        $('#edit-debtor-debt').val(debt.toLocaleString('en-NG'));
        $('#cfi-edit-debtor-modal').css('display', 'flex');
    });

    function formatPriceInput(el) {
        var raw = String(el.value || '').replace(/[^0-9]/g, '');
        el.value = raw ? parseInt(raw, 10).toLocaleString('en-NG') : '';
    }

    $(document).on('input', '.cfi-price-input', function() {
        formatPriceInput(this);
    });

    $(document).on('blur', '.cfi-price-input', function() {
        if (this.value === '') {
            this.value = '0';
        }
        var rawValue = String(this.value || '');
        var numeric = parseInt(rawValue.replace(/[^0-9]/g, ''), 10);
        if (rawValue.indexOf('-') !== -1 || Number.isNaN(numeric)) {
            this.value = '0';
        }
    });
    
    // Close Modals
    $(document).on('click', '.cfi-modal-close, .cfi-modal-overlay', function() {
        $('#cfi-edit-product-modal').hide();
    });
    $(document).on('click', '.cfi-modal-close-debtor, .cfi-modal-overlay-debtor', function() {
        $('#cfi-edit-debtor-modal').hide();
    });
    
    // Save Opening Values (Super Admin)
    $(document).on('click', '.cfi-save-opening-btn', function() {
        var btn = $(this);
        var productId = btn.data('product-id');
        var row = btn.closest('tr');
        var stockOpening = parseFloat(row.find('.cfi-opening-stock-input').val()) || 0;
        var packingOpening = parseFloat(row.find('.cfi-opening-packing-input').val()) || 0;
        
        // Validate non-negative values
        if (stockOpening < 0 || packingOpening < 0) {
            alert('Negative values are not allowed!');
            return;
        }
        
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Saving...');
        
        var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        var nonce = '<?php echo wp_create_nonce('cfi_nonce'); ?>';
        var promises = [];
        
        // Save stock opening
        var stockPromise = $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'cfi_update_stock_opening',
                nonce: nonce,
                product_id: productId,
                opening: stockOpening
            }
        });
        promises.push(stockPromise);
        
        // Save packing opening
        var packingPromise = $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'cfi_update_packing_opening',
                nonce: nonce,
                product_id: productId,
                opening: packingOpening
            }
        });
        promises.push(packingPromise);
        
        // Wait for both to complete
        $.when.apply($, promises).done(function(stockResult, packingResult) {
            btn.prop('disabled', false).html('<i class="fa-solid fa-check"></i> Saved!');
            setTimeout(function() {
                btn.html('<i class="fa-solid fa-save"></i> Save');
            }, 2000);
            
            if (typeof CFI !== 'undefined' && CFI.toast) {
                CFI.toast.success('Opening values saved successfully!');
            }
        }).fail(function() {
            btn.prop('disabled', false).html('<i class="fa-solid fa-save"></i> Save');
            alert('Failed to save opening values. Please try again.');
        });
    });
});

// Confirm Clear All Data
function confirmClearData() {
    var confirm1 = confirm('⚠️ WARNING: This will permanently delete ALL records and histories!\n\nAre you sure you want to clear all test data?');
    if (!confirm1) return false;
    
    var confirm2 = confirm('🚨 FINAL WARNING: This action CANNOT be undone!\n\nType "yes" in the next prompt to confirm deletion.');
    if (!confirm2) return false;
    
    var typeConfirm = prompt('Type "DELETE" to confirm you want to clear all test data:');
    if (typeConfirm !== 'DELETE') {
        alert('Deletion cancelled. You did not type "DELETE".');
        return false;
    }
    
    return true;
}

// Verify Sunday Carryover
jQuery(document).ready(function($) {
    var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    var nonce = '<?php echo esc_attr(wp_create_nonce('cfi_nonce')); ?>';
    
    $('#cfi-verify-carryover-btn').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Checking...');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: { action: 'cfi_verify_sunday_carryover', nonce: nonce },
            success: function(response) {
                btn.prop('disabled', false).html('<i class="fa-solid fa-vial"></i> Run Verification');
                
                if (response.success) {
                    var data = response.data;
                    var html = '';
                    
                    // Overall status banner
                    var bannerColor = data.overall === 'pass' ? '#16a34a' : (data.overall === 'skip' ? '#f59e0b' : '#dc2626');
                    var bannerBg = data.overall === 'pass' ? '#f0fdf4' : (data.overall === 'skip' ? '#fffbeb' : '#fef2f2');
                    html += '<div style="background:' + bannerBg + '; border:2px solid ' + bannerColor + '; border-radius:8px; padding:1rem; margin-bottom:1rem;">';
                    html += '<strong style="color:' + bannerColor + '; font-size:1.1rem;">' + data.message + '</strong>';
                    html += '</div>';
                    
                    var r = data.results;
                    
                    // Financial Summary
                    html += renderSection('Financial Summary (old_cash)', r.financial, function(f) {
                        if (f.status === 'skip') return '<em>' + f.explanation + '</em>';
                        var s = '<p><strong>Latest record:</strong> ' + f.latest_date + ' &mdash; cash_left = <strong>₦' + Number(f.latest_cash_left).toLocaleString() + '</strong></p>';
                        s += '<p><strong>Simulated Monday (' + f.simulated_monday + '):</strong> old_cash would be = <strong>₦' + Number(f.monday_old_cash_would_be).toLocaleString() + '</strong></p>';
                        s += '<p>' + f.explanation + '</p>';
                        return s;
                    });
                    
                    // Stock
                    html += renderSection('Stock (opening)', r.stock, function(s) {
                        if (s.status === 'skip') return '<em>' + s.explanation + '</em>';
                        var t = '<p>' + s.explanation + ' (from ' + s.latest_date + ')</p>';
                        t += '<table style="width:100%; border-collapse:collapse; font-size:0.85rem; margin-top:0.5rem;">';
                        t += '<tr style="background:#f1f5f9;"><th style="padding:0.4rem; text-align:left;">Product</th><th style="padding:0.4rem; text-align:right;">Latest Closing</th><th style="padding:0.4rem; text-align:right;">Monday Opening</th><th style="padding:0.4rem; text-align:center;">Status</th></tr>';
                        s.products.forEach(function(p) {
                            var icon = p.status === 'pass' ? '✅' : '❌';
                            t += '<tr style="border-bottom:1px solid #e2e8f0;"><td style="padding:0.4rem;">' + p.product + '</td><td style="padding:0.4rem; text-align:right;">' + p.latest_closing + '</td><td style="padding:0.4rem; text-align:right;">' + p.monday_opening_would_be + '</td><td style="padding:0.4rem; text-align:center;">' + icon + '</td></tr>';
                        });
                        t += '</table>';
                        return t;
                    });
                    
                    // Packing Store
                    html += renderSection('Packing Store (opening & balance)', r.packing, function(pk) {
                        if (pk.status === 'skip') return '<em>' + pk.explanation + '</em>';
                        var t = '<p>' + pk.explanation + ' (from ' + pk.latest_date + ')</p>';
                        t += '<table style="width:100%; border-collapse:collapse; font-size:0.85rem; margin-top:0.5rem;">';
                        t += '<tr style="background:#f1f5f9;"><th style="padding:0.4rem; text-align:left;">Product</th><th style="padding:0.4rem; text-align:right;">Latest Closing</th><th style="padding:0.4rem; text-align:right;">Monday Opening</th><th style="padding:0.4rem; text-align:right;">Latest Balance</th><th style="padding:0.4rem; text-align:right;">Monday Balance</th><th style="padding:0.4rem; text-align:center;">Status</th></tr>';
                        pk.products.forEach(function(p) {
                            var icon = p.status === 'pass' ? '✅' : '❌';
                            t += '<tr style="border-bottom:1px solid #e2e8f0;"><td style="padding:0.4rem;">' + p.product + '</td><td style="padding:0.4rem; text-align:right;">' + p.latest_closing + '</td><td style="padding:0.4rem; text-align:right;">' + p.monday_opening_would_be + '</td><td style="padding:0.4rem; text-align:right;">' + p.latest_balance + '</td><td style="padding:0.4rem; text-align:right;">' + p.monday_balance_would_be + '</td><td style="padding:0.4rem; text-align:center;">' + icon + '</td></tr>';
                        });
                        t += '</table>';
                        return t;
                    });
                    
                    $('#cfi-carryover-results').html(html).show();
                    
                    // Show migrate button if there are failures
                    if (data.overall === 'fail') {
                        $('#cfi-run-migrate-btn').css('display', 'inline-flex');
                    } else {
                        $('#cfi-run-migrate-btn').hide();
                    }
                } else {
                    $('#cfi-carryover-results').html('<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:1rem;"><strong style="color:#dc2626;">Error:</strong> ' + (response.data ? response.data.message : 'Unknown error') + '</div>').show();
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fa-solid fa-vial"></i> Run Verification');
                $('#cfi-carryover-results').html('<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:1rem;"><strong style="color:#dc2626;">Network error.</strong> Please try again.</div>').show();
            }
        });
    });
    
    // Migrate button handler
    $('#cfi-run-migrate-btn').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Migrating...');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: { action: 'cfi_migrate_opening_values', nonce: nonce },
            success: function(response) {
                btn.prop('disabled', false).html('<i class="fa-solid fa-wrench"></i> Run Migration Fix');
                if (response.success) {
                    alert(response.data.message);
                    // Re-run verification to show updated results
                    $('#cfi-verify-carryover-btn').click();
                } else {
                    alert('Migration failed: ' + (response.data ? response.data.message : 'Unknown error'));
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fa-solid fa-wrench"></i> Run Migration Fix');
                alert('Network error. Please try again.');
            }
        });
    });
    
    function renderSection(title, sectionData, contentFn) {
        var statusColor = sectionData.status === 'pass' ? '#16a34a' : (sectionData.status === 'skip' ? '#f59e0b' : '#dc2626');
        var statusIcon = sectionData.status === 'pass' ? '✅' : (sectionData.status === 'skip' ? '⚠️' : '❌');
        var html = '<div style="border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:0.75rem;">';
        html += '<h4 style="color:' + statusColor + '; margin:0 0 0.5rem 0;">' + statusIcon + ' ' + title + '</h4>';
        html += contentFn(sectionData);
        html += '</div>';
        return html;
    }
});

// System Scan
jQuery(document).ready(function($) {
    var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    var nonce = '<?php echo esc_attr(wp_create_nonce('cfi_nonce')); ?>';

    $('#cfi-system-scan-btn').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Scanning...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: { action: 'cfi_system_scan', nonce: nonce },
            success: function(response) {
                btn.prop('disabled', false).html('<i class="fa-solid fa-stethoscope"></i> Run System Scan');

                if (response.success) {
                    var data = response.data;
                    var checks = data.checks;
                    var html = '';

                    // Overall banner
                    var bannerColor = data.overall === 'pass' ? '#16a34a' : (data.overall === 'warn' ? '#f59e0b' : '#dc2626');
                    var bannerBg = data.overall === 'pass' ? '#f0fdf4' : (data.overall === 'warn' ? '#fffbeb' : '#fef2f2');
                    html += '<div style="background:' + bannerBg + '; border:2px solid ' + bannerColor + '; border-radius:8px; padding:1rem; margin-bottom:1rem;">';
                    html += '<strong style="color:' + bannerColor + '; font-size:1.1rem;">' + data.message + '</strong>';
                    html += '<p style="color:#64748b; margin:0.25rem 0 0; font-size:0.85rem;">Scanned at: ' + data.scan_time + '</p>';
                    html += '</div>';

                    // Database Tables
                    html += renderScanSection('Database Tables', checks.tables, function(c) {
                        var s = '<p>' + c.message + '</p>';
                        if (c.counts) {
                            s += '<table style="width:100%; border-collapse:collapse; font-size:0.85rem; margin-top:0.5rem;">';
                            s += '<tr style="background:#f1f5f9;"><th style="padding:0.4rem; text-align:left;">Table</th><th style="padding:0.4rem; text-align:right;">Records</th></tr>';
                            for (var t in c.counts) {
                                s += '<tr style="border-bottom:1px solid #e2e8f0;"><td style="padding:0.4rem;">' + t + '</td><td style="padding:0.4rem; text-align:right;">' + c.counts[t] + '</td></tr>';
                            }
                            s += '</table>';
                        }
                        return s;
                    });

                    // Orders Integrity
                    html += renderScanSection('Orders & Items Integrity', checks.order_integrity, function(c) {
                        var s = '<p>' + c.message + '</p>';
                        if (c.status === 'warn') {
                            s += '<p style="color:#dc2626;">⚠ ' + c.orders_without_items + ' order(s) without items, ' + c.items_without_orders + ' orphan item(s)</p>';
                        }
                        return s;
                    });

                    // Debtor Balances
                    html += renderScanSection('Debtor Balance Consistency', checks.debtor_balances, function(c) {
                        var s = '<p>' + c.message + '</p>';
                        if (c.issues && c.issues.length > 0) {
                            s += '<table style="width:100%; border-collapse:collapse; font-size:0.85rem; margin-top:0.5rem;">';
                            s += '<tr style="background:#fef2f2;"><th style="padding:0.4rem; text-align:left;">Debtor</th><th style="padding:0.4rem; text-align:right;">Table Debt</th><th style="padding:0.4rem; text-align:right;">Last Trans Balance</th></tr>';
                            c.issues.forEach(function(i) {
                                s += '<tr style="border-bottom:1px solid #e2e8f0;"><td style="padding:0.4rem;">' + i.name + '</td><td style="padding:0.4rem; text-align:right;">₦' + Number(i.table_debt).toLocaleString() + '</td><td style="padding:0.4rem; text-align:right;">₦' + Number(i.last_trans_balance).toLocaleString() + '</td></tr>';
                            });
                            s += '</table>';
                        }
                        return s;
                    });

                    // Credit Order Linking
                    html += renderScanSection('Credit Orders ↔ Debtors Linking', checks.credit_order_linking, function(c) {
                        var s = '<p>' + c.message + '</p>';
                        if (c.issues && c.issues.length > 0) {
                            c.issues.forEach(function(i) {
                                s += '<p style="color:#dc2626;">⚠ Order ' + i.order + ': ' + i.issue + '</p>';
                            });
                        }
                        return s;
                    });

                    // Transfer History
                    html += renderScanSection('Transfer History', checks.transfers, function(c) {
                        return '<p>' + c.message + '</p>';
                    });

                    // Stock Records
                    html += renderScanSection('Stock Records', checks.stock, function(c) {
                        return '<p>' + c.message + '</p>';
                    });

                    // Financial Summary
                    html += renderScanSection('Financial Summary', checks.financial, function(c) {
                        return '<p>' + c.message + '</p>';
                    });

                    // Form Records
                    html += renderScanSection('Form Submission Records', checks.form_records, function(c) {
                        var s = '<table style="width:100%; border-collapse:collapse; font-size:0.85rem; margin-top:0.5rem;">';
                        s += '<tr style="background:#f1f5f9;"><th style="padding:0.4rem; text-align:left;">Section</th><th style="padding:0.4rem; text-align:right;">Records</th></tr>';
                        for (var label in c.counts) {
                            s += '<tr style="border-bottom:1px solid #e2e8f0;"><td style="padding:0.4rem;">' + label + '</td><td style="padding:0.4rem; text-align:right;">' + c.counts[label] + '</td></tr>';
                        }
                        s += '</table>';
                        return s;
                    });

                    // History Records
                    html += renderScanSection('History Tables', checks.history_records, function(c) {
                        var s = '<table style="width:100%; border-collapse:collapse; font-size:0.85rem; margin-top:0.5rem;">';
                        s += '<tr style="background:#f1f5f9;"><th style="padding:0.4rem; text-align:left;">Section</th><th style="padding:0.4rem; text-align:right;">Records</th></tr>';
                        for (var label in c.counts) {
                            s += '<tr style="border-bottom:1px solid #e2e8f0;"><td style="padding:0.4rem;">' + label + '</td><td style="padding:0.4rem; text-align:right;">' + c.counts[label] + '</td></tr>';
                        }
                        s += '</table>';
                        return s;
                    });

                    $('#cfi-system-scan-results').html(html).show();
                } else {
                    $('#cfi-system-scan-results').html('<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:1rem;"><strong style="color:#dc2626;">Error:</strong> ' + (response.data ? response.data.message : 'Unknown error') + '</div>').show();
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fa-solid fa-stethoscope"></i> Run System Scan');
                $('#cfi-system-scan-results').html('<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:1rem;"><strong style="color:#dc2626;">Network error.</strong> Please try again.</div>').show();
            }
        });
    });

    function renderScanSection(title, sectionData, contentFn) {
        var statusMap = {
            'pass': { color: '#16a34a', icon: '✅' },
            'fail': { color: '#dc2626', icon: '❌' },
            'warn': { color: '#f59e0b', icon: '⚠️' },
            'skip': { color: '#94a3b8', icon: '⏭️' },
            'info': { color: '#7c3aed', icon: 'ℹ️' }
        };
        var s = statusMap[sectionData.status] || statusMap['info'];
        var html = '<div style="border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:0.75rem;">';
        html += '<h4 style="color:' + s.color + '; margin:0 0 0.5rem 0;">' + s.icon + ' ' + title + '</h4>';
        html += contentFn(sectionData);
        html += '</div>';
        return html;
    }

    // Brutal Fix Handler
    $('#cfi-system-fix-btn').on('click', function() {
        var confirm1 = confirm('⚠️ BRUTAL FIX\n\nThis will automatically:\n• Recreate missing database tables\n• Recreate missing WordPress pages\n• Fix broken page shortcodes\n• Sync debtor balances with transaction history\n• Clean up orphan order items & empty orders\n• Remove invalid transfer records\n• Reset negative stock values\n• Recalculate financial summaries\n• Fix credit orders with missing debtors\n• Republish unpublished pages\n\nAre you sure you want to proceed?');
        if (!confirm1) return;

        var btn = $(this);
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Fixing Everything...');
        $('#cfi-system-fix-results').html('').hide();

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: { action: 'cfi_system_fix', nonce: nonce },
            success: function(response) {
                btn.prop('disabled', false).html('<i class="fa-solid fa-hammer"></i> Brutal Fix All Issues');

                if (response.success) {
                    var data = response.data;
                    var fixes = data.fixes;
                    var html = '';

                    // Overall banner
                    var bannerColor = data.total_fixes > 0 ? '#16a34a' : '#7c3aed';
                    var bannerBg = data.total_fixes > 0 ? '#f0fdf4' : '#f5f3ff';
                    html += '<div style="background:' + bannerBg + '; border:2px solid ' + bannerColor + '; border-radius:8px; padding:1rem; margin-bottom:1rem;">';
                    html += '<strong style="color:' + bannerColor + '; font-size:1.1rem;">' + data.message + '</strong>';
                    html += '<p style="color:#64748b; margin:0.25rem 0 0; font-size:0.85rem;">Fixed at: ' + data.fix_time + '</p>';
                    html += '</div>';

                    // Render each fix section
                    for (var key in fixes) {
                        var fix = fixes[key];
                        var icon = fix.count > 0 ? '🔧' : '✅';
                        var color = fix.count > 0 ? '#f59e0b' : '#16a34a';
                        html += '<div style="border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:0.75rem;">';
                        html += '<h4 style="color:' + color + '; margin:0 0 0.5rem 0;">' + icon + ' ' + fix.action + '</h4>';
                        html += '<p style="margin:0; font-size:0.9rem;">' + fix.result + '</p>';

                        // Show details if available
                        if (fix.details) {
                            if (Array.isArray(fix.details) && fix.details.length > 0) {
                                html += '<ul style="margin:0.5rem 0 0; padding-left:1.25rem; font-size:0.85rem; color:#64748b;">';
                                fix.details.forEach(function(d) { html += '<li>' + d + '</li>'; });
                                html += '</ul>';
                            } else if (typeof fix.details === 'object') {
                                if (fix.details.shortcode_repaired && fix.details.shortcode_repaired.length > 0) {
                                    html += '<p style="margin:0.25rem 0 0; font-size:0.85rem; color:#64748b;">Shortcodes repaired: ' + fix.details.shortcode_repaired.join(', ') + '</p>';
                                }
                                if (fix.details.still_missing && fix.details.still_missing.length > 0) {
                                    html += '<p style="margin:0.25rem 0 0; font-size:0.85rem; color:#dc2626;">Still missing: ' + fix.details.still_missing.join(', ') + '</p>';
                                }
                            }
                        }
                        html += '</div>';
                    }

                    // Suggestion to re-scan
                    html += '<div style="text-align:center; margin-top:1rem; padding:1rem; background:#f8fafc; border-radius:8px;">';
                    html += '<p style="color:#64748b; margin:0 0 0.5rem;">Run a System Scan to verify all fixes were applied successfully.</p>';
                    html += '<button type="button" onclick="document.getElementById(\'cfi-system-scan-btn\').click();" style="background:#7c3aed; color:white; border:none; padding:0.5rem 1rem; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fa-solid fa-stethoscope"></i> Run Scan Now</button>';
                    html += '</div>';

                    $('#cfi-system-fix-results').html(html).show();
                } else {
                    $('#cfi-system-fix-results').html('<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:1rem;"><strong style="color:#dc2626;">Error:</strong> ' + (response.data ? response.data.message : 'Unknown error') + '</div>').show();
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fa-solid fa-hammer"></i> Brutal Fix All Issues');
                $('#cfi-system-fix-results').html('<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:1rem;"><strong style="color:#dc2626;">Network error.</strong> Please try again.</div>').show();
            }
        });
    });
});
</script>