<?php
/**
 * Debtors Record Page Template - COMPLETE REBUILD v3
 * Brutal fix: Zero caching, direct wpdb queries, proper balance tracking
 */

if (!defined('ABSPATH')) {
    exit;
}

// Force fresh page - aggressive no-cache headers
if (!headers_sent()) {
    header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0, s-maxage=0, post-check=0, pre-check=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    header('Vary: *');
}

// Clear any WordPress object cache for debtors
wp_cache_flush();

// Ensure database tables exist
CFI_Database::maybe_create_tables();

global $wpdb;
$is_admin = CFI_Auth::is_cfi_admin();
$message = '';
$message_type = '';

// Table names
$debtors_table = $wpdb->prefix . 'cfi_debtors';
$orders_table = $wpdb->prefix . 'cfi_orders';
$order_items_table = $wpdb->prefix . 'cfi_order_items';
$trans_table = $wpdb->prefix . 'cfi_debtor_transactions';
$debtor_record_url = home_url('/cfi-debtors-record/');
$debtor_record_done_url = add_query_arg('t', time(), $debtor_record_url);

if (!function_exists('cfi_get_latest_debtor_balance')) {
    // BRUTAL FIX: Always use the total_debt from debtors table as source of truth
    // This ensures consistency after transactions are deleted
    function cfi_get_latest_debtor_balance($wpdb, $trans_table, $debtor_id, $fallback) {
        // The fallback IS the total_debt from debtors table, which is now our source of truth
        return floatval($fallback);
    }
}

// Process Take Order Form - BRUTAL FIX: No redirect, inline success
if (isset($_POST['cfi_debtor_order_submit']) && isset($_POST['cfi_debtor_order_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cfi_debtor_order_nonce'])), 'cfi_debtor_order')) {
    $debtor_id = intval($_POST['debtor_id']);
    $items = isset($_POST['order_items']) ? $_POST['order_items'] : array();
    
    // Filter items to only those with quantity > 0
    $valid_items = array();
    if (is_array($items)) {
        foreach ($items as $item) {
            if (isset($item['quantity']) && floatval($item['quantity']) > 0 && isset($item['product_id']) && intval($item['product_id']) > 0) {
                $valid_items[] = $item;
            }
        }
    }
    
    if (empty($valid_items)) {
        $message = 'Please add at least one item to the order (quantity must be greater than 0)';
        $message_type = 'error';
    } else {
        // Get current debtor data with fresh query
        $debtor = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$debtors_table} WHERE id = %d LIMIT 1",
            $debtor_id
        ));
        
        if (!$debtor) {
            $message = 'Debtor not found. Please go back and try again.';
            $message_type = 'error';
        } else {
            $total_amount = 0;
            $order_items = array();
            
            foreach ($valid_items as $item) {
                $product_id = intval($item['product_id']);
                $quantity = floatval($item['quantity']);
                $discount = isset($item['discount']) ? floatval($item['discount']) : 0;
                
                $product = CFI_Products::get($product_id);
                if ($product) {
                    $item_total = ($product->price * $quantity) - $discount;
                    if ($item_total < 0) $item_total = 0;
                    $total_amount += $item_total;
                    $order_items[] = array(
                        'product_id' => $product_id,
                        'product_name' => $product->name,
                        'price' => $product->price,
                        'quantity' => $quantity,
                        'discount' => $discount,
                        'total' => $item_total
                    );
                }
            }
            
            if ($total_amount <= 0) {
                $message = 'Order total must be greater than zero.';
                $message_type = 'error';
            } else {
                $order_number = 'ORD-' . date('Ymd') . '-' . substr(uniqid(), -6);
                $total_qty = 0;
                $total_discount = 0;
                foreach ($order_items as $oi) {
                    $total_qty += $oi['quantity'];
                    $total_discount += $oi['discount'];
                }
                
                // Insert order
                $wpdb->insert(
                    $orders_table,
                    array(
                        'order_number' => $order_number,
                        'order_type' => 'credit',
                        'customer_name' => $debtor->name,
                        'debtor_id' => $debtor_id,
                        'total_quantity' => $total_qty,
                        'total_amount' => $total_amount + $total_discount,
                        'discount_amount' => $total_discount,
                        'grand_total' => $total_amount,
                        'payment_method' => 'credit',
                        'transfer_amount' => 0,
                        'cash_amount' => 0,
                        'bank_name' => '',
                        'staff_id' => get_current_user_id(),
                        'order_date' => current_time('Y-m-d'),
                        'order_time' => current_time('H:i:s'),
                        'status' => 'completed'
                    ),
                    array('%s', '%s', '%s', '%d', '%f', '%f', '%f', '%f', '%s', '%f', '%f', '%s', '%d', '%s', '%s', '%s')
                );
                $order_id = $wpdb->insert_id;
                
                if (!$order_id) {
                    $message = 'Failed to create order. Database error.';
                    $message_type = 'error';
                } else {
                    // Insert order items
                    foreach ($order_items as $oi) {
                        $wpdb->insert(
                            $order_items_table,
                            array(
                                'order_id' => $order_id,
                                'product_id' => $oi['product_id'],
                                'quantity' => $oi['quantity'],
                                'price' => $oi['price'],
                                'discount' => $oi['discount'],
                                'total' => $oi['total']
                            ),
                            array('%d', '%d', '%f', '%f', '%f', '%f')
                        );
                        CFI_Stock::update_credit_supply($oi['product_id'], $oi['quantity'], current_time('Y-m-d'));
                    }
                    
                    $balance_before = floatval($debtor->total_debt);
                    $new_balance = $balance_before + $total_amount;
                    
                    // CRITICAL: Direct SQL update for debtor balance
                    $wpdb->query($wpdb->prepare(
                        "UPDATE `{$debtors_table}` SET `total_debt` = %f WHERE `id` = %d",
                        $new_balance,
                        $debtor_id
                    ));
                    
                    // Record transaction in debtor_transactions history
                    $wpdb->insert(
                        $trans_table,
                        array(
                            'debtor_id' => $debtor_id,
                            'transaction_type' => 'order',
                            'order_id' => $order_id,
                            'amount' => $total_amount,
                            'balance_before' => $balance_before,
                            'balance_after' => $new_balance,
                            'description' => 'New order: ' . $order_number,
                            'staff_id' => get_current_user_id(),
                            'transaction_date' => current_time('Y-m-d'),
                            'transaction_time' => current_time('H:i:s')
                        ),
                        array('%d', '%s', '%d', '%f', '%f', '%f', '%s', '%d', '%s', '%s')
                    );
                    
                    CFI_Financial::update_daily_summary(current_time('Y-m-d'));
                    
                    // BRUTAL FIX: Store receipt directly in variable instead of transient+redirect
                    // This avoids wp_redirect() failing when headers are already sent by WordPress theme
                    $order_receipt = array(
                        'order_number' => $order_number,
                        'date' => current_time('d/m/Y'),
                        'time' => current_time('g:i A'),
                        'debtor_name' => $debtor->name,
                        'items' => $order_items,
                        'total' => $total_amount,
                        'new_balance' => $new_balance,
                        'staff' => wp_get_current_user()->display_name
                    );
                    $show_order_success = true;
                    
                    // Clear the selected debtor/action so form doesn't show again
                    $selected_debtor_id = 0;
                    $action = '';
                    $selected_debtor = null;
                }
            }
        }
    }
}

// Process Clear Debt Form - BRUTAL FIX: No redirect, inline success  
if (isset($_POST['cfi_clear_debt_submit']) && isset($_POST['cfi_clear_debt_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cfi_clear_debt_nonce'])), 'cfi_clear_debt')) {
    $debtor_id = intval($_POST['debtor_id']);
    $use_transfer = isset($_POST['use_transfer']);
    $use_cash = isset($_POST['use_cash']);
    $use_home = isset($_POST['use_home']);
    
    $methods = array();
    if ($use_transfer) $methods[] = 'transfer';
    if ($use_cash) $methods[] = 'cash';
    if ($use_home) $methods[] = 'home';
    $payment_method = !empty($methods) ? implode('_', $methods) : 'cash';
    
    $transfer_raw = isset($_POST['transfer_amount']) ? sanitize_text_field($_POST['transfer_amount']) : '';
    $cash_raw = isset($_POST['cash_amount']) ? sanitize_text_field($_POST['cash_amount']) : '';
    $home_raw = isset($_POST['home_amount']) ? sanitize_text_field($_POST['home_amount']) : '';
    $transfer_amount = $use_transfer ? floatval(str_replace(',', '', $transfer_raw)) : 0;
    $cash_amount = $use_cash ? floatval(str_replace(',', '', $cash_raw)) : 0;
    $home_amount = $use_home ? floatval(str_replace(',', '', $home_raw)) : 0;
    $bank_name = sanitize_text_field($_POST['bank_name']);
    $home_remarks = isset($_POST['home_remarks']) ? sanitize_textarea_field($_POST['home_remarks']) : '';
    $total_payment = $transfer_amount + $cash_amount + $home_amount;
    
    if ($total_payment > 0) {
        $debtor = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$debtors_table} WHERE id = %d LIMIT 1",
            $debtor_id
        ));
        
        if ($debtor) {
            $balance_before = floatval($debtor->total_debt);
            $new_balance = $balance_before - $total_payment;
            
            // CRITICAL: Direct SQL update for debtor balance
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$debtors_table}` SET `total_debt` = %f WHERE `id` = %d",
                $new_balance,
                $debtor_id
            ));
            
            // Record transaction in debtor_transactions history
            $wpdb->insert(
                $trans_table,
                array(
                    'debtor_id' => $debtor_id,
                    'transaction_type' => 'payment',
                    'amount' => $total_payment,
                    'payment_method' => $payment_method,
                    'bank_name' => $bank_name,
                    'transfer_amount' => $transfer_amount,
                    'cash_amount' => $cash_amount,
                    'home_calculation_amount' => $home_amount,
                    'home_remarks' => $home_remarks,
                    'balance_before' => $balance_before,
                    'balance_after' => $new_balance,
                    'description' => 'Debt payment received',
                    'staff_id' => get_current_user_id(),
                    'transaction_date' => current_time('Y-m-d'),
                    'transaction_time' => current_time('H:i:s')
                ),
                array('%d', '%s', '%f', '%s', '%s', '%f', '%f', '%f', '%s', '%f', '%f', '%s', '%d', '%s', '%s')
            );
            
            // Record transfer if applicable
            if ($transfer_amount > 0) {
                $transfer_table = $wpdb->prefix . 'cfi_transfer_history';
                $wpdb->insert(
                    $transfer_table,
                    array(
                        'source' => 'debtor',
                        'source_id' => $debtor_id,
                        'customer_name' => $debtor->name,
                        'amount' => $transfer_amount,
                        'bank_name' => $bank_name,
                        'staff_id' => get_current_user_id(),
                        'transfer_date' => current_time('Y-m-d'),
                        'transfer_time' => current_time('H:i:s')
                    ),
                    array('%s', '%d', '%s', '%f', '%s', '%d', '%s', '%s')
                );
            }
            
            CFI_Financial::update_daily_summary(current_time('Y-m-d'));
            
            // BRUTAL FIX: Store receipt directly in variable instead of transient+redirect
            $payment_receipt = array(
                'receipt_number' => 'PAY-' . date('Ymd') . '-' . substr(uniqid(), -6),
                'date' => current_time('d/m/Y'),
                'time' => current_time('g:i A'),
                'debtor_name' => $debtor->name,
                'payment_amount' => $total_payment,
                'transfer_amount' => $transfer_amount,
                'cash_amount' => $cash_amount,
                'home_amount' => $home_amount,
                'bank_name' => $bank_name,
                'balance_before' => $balance_before,
                'new_balance' => $new_balance,
                'overpayment' => max(0, -$new_balance),
                'staff' => wp_get_current_user()->display_name
            );
            $show_payment_success = true;
            
            // Clear the selected debtor/action so form doesn't show again
            $selected_debtor_id = 0;
            $action = '';
            $selected_debtor = null;
        }
    }
}

// Load receipt data from transients after redirect (backward compat - also supports inline POST success above)
if (!isset($order_receipt)) {
    $order_receipt = null;
}
if (!isset($payment_receipt)) {
    $payment_receipt = null;
}
if (!isset($show_order_success)) {
    $show_order_success = false;
}
if (!isset($show_payment_success)) {
    $show_payment_success = false;
}

// Also check GET params for backward compat with old redirect-based flow
if (isset($_GET['order_done']) && isset($_GET['rk'])) {
    $rk_key = sanitize_text_field($_GET['rk']);
    // Validate transient key matches expected format: cfi_order_{user_id}_{timestamp}
    if (preg_match('/^cfi_order_\d+_\d+$/', $rk_key)) {
        $rk_receipt = get_transient($rk_key);
        if ($rk_receipt) {
            $order_receipt = $rk_receipt;
            delete_transient($rk_key);
        }
    }
    $show_order_success = true;
}

if (isset($_GET['pay_done']) && isset($_GET['pk'])) {
    $pk_key = sanitize_text_field($_GET['pk']);
    // Validate transient key matches expected format: cfi_pay_{user_id}_{timestamp}
    if (preg_match('/^cfi_pay_\d+_\d+$/', $pk_key)) {
        $pk_receipt = get_transient($pk_key);
        if ($pk_receipt) {
            $payment_receipt = $pk_receipt;
            delete_transient($pk_key);
        }
    }
    $show_payment_success = true;
}

if (!function_exists('cfi_format_receipt_value')) {
    function cfi_format_receipt_value($value) {
        return number_format((float) $value, 0);
    }
}

// Get fresh data - use SQL_NO_CACHE and bypass WordPress object cache
$wpdb->flush();  // Clear any cached query results
$safe_debtors_table = esc_sql($debtors_table);
$safe_trans_table = esc_sql($trans_table);

// BRUTAL FIX: Use total_debt from debtors table directly instead of balance_after from transactions
// This ensures the card balance matches the database value which is updated when transactions are deleted
$debtors = $wpdb->get_results(
    "SELECT SQL_NO_CACHE d.*, d.total_debt AS display_debt
    FROM `{$safe_debtors_table}` d
    WHERE d.status = 'active'
    ORDER BY d.name ASC"
);
$products = CFI_Products::get_all();

// Only set from GET params if not already set by POST processing above
if (!isset($selected_debtor_id) || $selected_debtor_id === 0) {
    $selected_debtor_id = isset($_GET['debtor']) ? intval($_GET['debtor']) : 0;
}
if (!isset($action) || $action === '') {
    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
}
if (!isset($selected_debtor)) {
    $selected_debtor = null;
}
if ($selected_debtor_id && !$selected_debtor) {
    $debtors_by_id = array();
    foreach ($debtors as $debtor) {
        $debtors_by_id[(int) $debtor->id] = $debtor;
    }
    $selected_debtor = $debtors_by_id[$selected_debtor_id] ?? null;
}

// Generate unique page ID to break caching
$page_uid = substr(md5(microtime(true)), 0, 8);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<meta name="page-uid" content="<?php echo $page_uid; ?>">
<title>Debtors Record</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;padding:0;background:#f8fafc}
.container{max-width:1200px;margin:0 auto;padding:1rem}
.header{background:linear-gradient(135deg,#001943,#003366);color:#fff;padding:0.75rem 1rem;border-radius:12px;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem}
.header h1{margin:0;font-size:0.85rem;display:flex;align-items:center;gap:0.5rem}
.btn{display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 0.75rem;border:none;border-radius:8px;font-weight:600;cursor:pointer;text-decoration:none;font-size:0.7rem;transition:all 0.2s}
.btn-primary{background:#001943;color:#fff}
.btn-success{background:#16a34a;color:#fff}
.btn-outline{background:#fff;border:2px solid #001943;color:#001943}
.btn-white{background:#fff;color:#001943}
.card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem}
.card{background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 4px 20px rgba(0,25,67,0.1);border:2px solid rgba(0,25,67,0.1)}
.cfi-debtor-loading .card-balance,
.cfi-debtor-loading .cfi-debtor-balance{opacity:0}
.card-name{font-size:1.2rem;color:#001943;margin:0 0 0.5rem}
.card-phone{color:#64748b;font-size:0.85rem;margin:0 0 1rem}
.card-balance{font-size:1.5rem;font-weight:700;color:#dc2626;margin-bottom:1rem}
.card-balance.zero{color:#16a34a}
.card-actions{display:flex;gap:0.5rem;flex-wrap:wrap}
.card-actions .btn{flex:1;justify-content:center}
.glass{background:rgba(255,255,255,0.95);border-radius:12px;padding:1.5rem;box-shadow:0 4px 20px rgba(0,25,67,0.1);border:2px solid rgba(0,25,67,0.1);margin-bottom:1.5rem}
.form-group{margin-bottom:1rem}
.form-group label{display:block;margin-bottom:0.5rem;font-weight:600;color:#001943;font-size:0.85rem}
.input{width:100%;padding:0.75rem;border:2px solid #e2e8f0;border-radius:8px;font-size:1rem}
.input:focus{outline:none;border-color:#001943}
table{width:100%;border-collapse:collapse;font-size:0.85rem}
th{background:#001943;color:#fff;padding:0.6rem 0.4rem;text-align:left}
td{padding:0.5rem 0.4rem;border-bottom:1px solid #e2e8f0}
table input{width:70px;padding:0.4rem;border:1px solid #e2e8f0;border-radius:4px;text-align:center}
.order-total{background:#001943;color:#fff;padding:1rem;border-radius:8px;margin-top:1rem;display:flex;justify-content:space-between;align-items:center}
.total-value{font-size:1.5rem;font-weight:700}
.grand-display{margin-top:0.5rem;padding:1rem;background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;border-radius:8px;text-align:center;display:none}
.grand-display span{display:block;font-size:0.9rem}
.grand-display strong{font-size:2rem;font-weight:700}
.payment-methods{display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem}
.payment-method{flex:1;min-width:100px;padding:0.75rem;border:2px solid #e2e8f0;border-radius:8px;text-align:center;cursor:pointer;transition:all 0.2s;position:relative}
.payment-method.selected{border-color:#001943;background:rgba(0,25,67,0.15);box-shadow:0 0 0 3px rgba(0,25,67,0.1)}
.payment-method i{display:block;font-size:1.5rem;color:#001943;margin-bottom:0.5rem}
.bank-options{margin-bottom:1rem}
.bank-option{display:flex;align-items:center;gap:0.5rem;padding:0.5rem;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:0.5rem;cursor:pointer}
.bank-option input{width:auto}
.back-link{color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:0.4rem;font-size:0.85rem}
.back-link:hover{text-decoration:underline}
.section-title{font-size:1rem;color:#fff;margin:0 0 1rem;padding:0.75rem 1rem;background:linear-gradient(135deg,#001943,#003366);border-radius:10px;display:flex;align-items:center;gap:0.5rem}
.empty{text-align:center;padding:3rem;color:#64748b}
.empty i{font-size:3rem;margin-bottom:1rem;display:block}
.modal{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:1rem}
.modal-content{background:#fff;max-width:400px;width:100%;max-height:90vh;overflow-y:auto;border-radius:12px;box-shadow:0 25px 50px rgba(0,0,0,0.3)}
.modal-header{padding:1rem;display:flex;justify-content:space-between;align-items:center}
.modal-header.order{background:#001943;color:#fff}
.modal-header.payment{background:#16a34a;color:#fff}
.modal-header h3{margin:0}
.modal-close{background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer}
.modal-body{padding:1.5rem}
.modal-footer{display:flex;gap:0.5rem;padding:1rem;background:#f1f5f9}
.modal-footer .btn{flex:1;justify-content:center}
.receipt-company{text-align:center;margin-bottom:0.5rem}
.receipt-company h2{color:#001943;margin:0 0 0.25rem 0;font-weight:800;letter-spacing:0.5px;text-transform:uppercase}
.receipt-company p{color:#64748b;font-size:0.8rem;margin:0}
.receipt-divider{border-top:1px solid #001943;margin:0.5rem 0}
.receipt-info{margin-bottom:0.5rem;font-size:0.85rem}
.receipt-info p{margin:0.25rem 0;display:flex;justify-content:space-between}
.receipt-items{margin:0.5rem 0}
.receipt-row{display:grid;grid-template-columns:1.6fr 0.8fr 0.5fr 0.9fr;gap:6px;align-items:baseline}
.receipt-row .item-price,.receipt-row .item-qty,.receipt-row .item-total{text-align:right}
.receipt-item-header{font-size:0.7rem;font-weight:700;text-transform:uppercase;color:#0f172a}
.receipt-item{padding:0.35rem 0;border-bottom:1px dashed #e2e8f0}
.receipt-item:last-child{border-bottom:none}
.receipt-item-discount{display:flex;justify-content:space-between;font-size:0.7rem;margin-top:0.2rem}
.receipt-item-discount .receipt-amount{color:#dc2626}
.receipt-amount{font-weight:800}
.receipt-totals{margin-top:0.5rem;font-size:0.85rem}
.receipt-totals p{display:flex;justify-content:space-between;margin:0.25rem 0}
.receipt-totals .grand{font-size:1rem;font-weight:700;color:#001943}
.receipt-footer{text-align:center;margin-top:0.5rem;font-size:0.75rem;color:#64748b}
.receipt-footer p{margin:0.25rem 0}
@media(max-width:768px){
.header{flex-direction:column;text-align:center}
.card-actions{flex-direction:column}
table input{width:50px}
}
</style>
</head>
<body class="cfi-debtor-loading">
<script>
// Prevent form resubmission dialog on page refresh - run immediately
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>
<main class="container">
<div class="header">
<?php if ($selected_debtor && ($action === 'order' || $action === 'pay')) : ?>
<a href="<?php echo esc_url(remove_query_arg(array('debtor','action'))); ?>" class="back-link"><i class="fas fa-arrow-left"></i> Back</a>
<h1><?php echo $action === 'order' ? '<i class="fas fa-cart-plus"></i> Take Order - ' : '<i class="fas fa-money-check"></i> Clear Debt - '; ?><?php echo esc_html($selected_debtor->name); ?></h1>
<?php else : ?>
<h1><i class="fas fa-user-clock"></i> Debtors Record</h1>
<a href="<?php echo esc_url(home_url('/cfi-debtors-history/')); ?>" class="btn btn-white"><i class="fas fa-history"></i> View History</a>
<?php endif; ?>
</div>

<?php if (!empty($message)) : ?>
<div style="background:<?php echo $message_type === 'error' ? '#fee2e2' : '#dcfce7'; ?>;color:<?php echo $message_type === 'error' ? '#991b1b' : '#166534'; ?>;padding:1rem;border-radius:8px;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
<i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-circle' : 'check-circle'; ?>" style="font-size:1.5rem;"></i>
<div><strong><?php echo esc_html($message); ?></strong></div>
</div>
<?php endif; ?>

<?php if ($show_order_success) : ?>
<div style="background:#dcfce7;color:#166534;padding:1rem;border-radius:8px;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
<i class="fas fa-check-circle" style="font-size:1.5rem;"></i>
<div>
<strong>Order Recorded Successfully!</strong>
<p style="margin:0.25rem 0 0 0;font-size:0.875rem;">The credit order has been added to the debtor's account.<?php if ($order_receipt) : ?> Order #<?php echo esc_html($order_receipt['order_number']); ?> — New Balance: ₦<?php echo number_format($order_receipt['new_balance'], 0); ?><?php endif; ?></p>
</div>
</div>
<?php endif; ?>

<?php if ($show_payment_success) : ?>
<div style="background:#dcfce7;color:#166534;padding:1rem;border-radius:8px;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
<i class="fas fa-check-circle" style="font-size:1.5rem;"></i>
<div>
<strong>Payment Recorded Successfully!</strong>
<p style="margin:0.25rem 0 0 0;font-size:0.875rem;">The debt payment has been recorded.<?php if ($payment_receipt) : ?> Receipt #<?php echo esc_html($payment_receipt['receipt_number']); ?> — New Balance: ₦<?php echo number_format($payment_receipt['new_balance'], 0); ?><?php endif; ?></p>
</div>
</div>
<?php endif; ?>

<?php if ($selected_debtor && $action === 'order') : ?>
<div class="glass">
<h3 style="color:#001943;margin-top:0"><i class="fas fa-shopping-cart"></i> Order Items</h3>
<p><strong>Current Debt:</strong> <span class="cfi-debtor-balance" data-debtor-id="<?php echo esc_attr($selected_debtor->id); ?>" style="color:#dc2626">₦<?php echo number_format($selected_debtor->display_debt, 0); ?></span></p>
<form method="POST" id="order-form">
<?php wp_nonce_field('cfi_debtor_order', 'cfi_debtor_order_nonce'); ?>
<input type="hidden" name="debtor_id" value="<?php echo esc_attr($selected_debtor->id); ?>">
<input type="hidden" name="cfi_debtor_order_submit" value="1">
<div style="overflow-x:auto">
<table>
<thead><tr><th style="width:40%">Item</th><th>Price (₦)</th><th>Qty</th><th>Disc (₦)</th><th>Total (₦)</th></tr></thead>
<tbody>
<?php foreach ($products as $idx => $product) : ?>
<tr class="order-row" data-price="<?php echo esc_attr($product->price); ?>">
<td><?php echo esc_html($product->name); ?><input type="hidden" name="order_items[<?php echo $idx; ?>][product_id]" value="<?php echo esc_attr($product->id); ?>"></td>
<td style="color:#001943;font-weight:600"><?php echo number_format($product->price, 0); ?></td>
<td><input type="number" name="order_items[<?php echo $idx; ?>][quantity]" class="qty" value="0" min="0" step="0.5" oninput="calcRow(this)"></td>
<td><input type="number" name="order_items[<?php echo $idx; ?>][discount]" class="disc" value="0" min="0" step="0.01" oninput="calcRow(this)"></td>
<td class="row-total" style="font-weight:600;color:#001943">0</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="order-total"><span>Grand Total:</span><span class="total-value" id="grand-total">₦0</span></div>
<div class="grand-display" id="grand-display"><span>Amount to add to debt:</span><strong id="grand-highlight">₦0</strong></div>
<div style="margin-top:1.5rem;display:flex;gap:1rem;justify-content:flex-end">
<a href="<?php echo esc_url(remove_query_arg(array('debtor','action'))); ?>" class="btn btn-outline">Cancel</a>
<button type="submit" name="cfi_debtor_order_submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add to Debt</button>
</div>
</form>
</div>
<script>
function calcRow(el) {
    var row = el.closest('.order-row');
    var price = parseFloat(row.dataset.price) || 0;
    var qty = parseFloat(row.querySelector('.qty').value) || 0;
    var discount = parseFloat(row.querySelector('.disc').value) || 0;
    var total = (price * qty) - discount;
    if (total < 0) {
        total = 0;
    }
    row.querySelector('.row-total').textContent = total.toLocaleString('en-NG', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    calcTotal();
}

function calcTotal() {
    var totals = document.querySelectorAll('.row-total');
    var grand = 0;
    totals.forEach(function(el) {
        grand += parseFloat(String(el.textContent).replace(/,/g, '')) || 0;
    });
    var formatted = '₦' + grand.toLocaleString('en-NG', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    document.getElementById('grand-total').textContent = formatted;
    var display = document.getElementById('grand-display');
    var highlight = document.getElementById('grand-highlight');
    if (grand > 0) {
        display.style.display = 'block';
        highlight.textContent = formatted;
    } else {
        display.style.display = 'none';
    }
}
document.querySelectorAll('input[type="number"]').forEach(function(i){i.addEventListener('focus',function(){var s=this;setTimeout(function(){s.select()},10)})});

// Double submission prevention
var debtorOrderSubmitting = false;

// Online form submission - BRUTAL FIX: Do NOT disable the submit button
// Disabling the button can prevent its name/value from being sent in POST data
var cfiDebtorOrderForm = document.getElementById('order-form');
if (cfiDebtorOrderForm) {
    cfiDebtorOrderForm.addEventListener('submit', function(e) {
        // Prevent double submission
        if (debtorOrderSubmitting) {
            e.preventDefault();
            return false;
        }
        
        if (!navigator.onLine) {
            e.preventDefault();
            submitDebtorOrderOffline();
        } else {
            // Mark as submitting but do NOT disable the button
            debtorOrderSubmitting = true;
            var submitBtn = cfiDebtorOrderForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.style.opacity = '0.6';
                submitBtn.style.pointerEvents = 'none';
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }
            // Let the form submit naturally - do not call e.preventDefault()
        }
    });
}

function submitDebtorOrderOffline() {
    var items = collectDebtorOrderItems();
    if (!items || items.length === 0) {
        alert('Please add at least one item to the order.');
        return;
    }
    
    var grandTotal = 0;
    var totalQty = 0;
    var totalDiscount = 0;
    items.forEach(function(item) {
        grandTotal += item.total;
        totalQty += item.quantity;
        totalDiscount += item.discount;
    });
    
    if (grandTotal <= 0) {
        alert('Order total must be greater than zero.');
        return;
    }
    
    var now = new Date();
    var orderNumber = 'OFF-' + now.getFullYear() + 
        String(now.getMonth() + 1).padStart(2, '0') + 
        String(now.getDate()).padStart(2, '0') + '-' + 
        String(now.getHours()).padStart(2, '0') + 
        String(now.getMinutes()).padStart(2, '0') + 
        String(now.getSeconds()).padStart(2, '0');
    
    var payload = {
        debtor_id: <?php echo (int)$selected_debtor->id; ?>,
        items: items.map(function(item) {
            return {
                product_id: item.product_id,
                quantity: item.quantity,
                price: item.price,
                discount: item.discount,
                total: item.total
            };
        }),
        total_quantity: totalQty,
        total_amount: grandTotal + totalDiscount,
        discount_amount: totalDiscount,
        grand_total: grandTotal
    };
    
    var receipt = {
        order_number: orderNumber,
        debtor_name: '<?php echo esc_js($selected_debtor->name); ?>',
        date: formatDebtorOfflineDate(now),
        time: formatDebtorOfflineTime(now),
        items: items,
        total_qty: totalQty,
        subtotal: grandTotal + totalDiscount,
        discount: totalDiscount,
        grand_total: grandTotal,
        current_debt: <?php echo floatval($selected_debtor->display_debt); ?>,
        new_balance: <?php echo floatval($selected_debtor->display_debt); ?> + grandTotal,
        staff: (window.cfiData && cfiData.currentUser) ? cfiData.currentUser : ''
    };
    
    // Add to offline queue
    if (window.CFI && CFI.offline && typeof CFI.offline.addToQueue === 'function') {
        CFI.offline.addToQueue('debtor_order', payload);
    } else {
        // Fallback: save to localStorage directly
        var queue = JSON.parse(localStorage.getItem('cfi_offline_queue') || '[]');
        queue.push({
            id: 'off_' + Date.now(),
            type: 'debtor_order',
            data: payload,
            timestamp: Date.now()
        });
        localStorage.setItem('cfi_offline_queue', JSON.stringify(queue));
    }
    
    showDebtorOfflineSuccess('Credit order submitted offline. It will sync when you are back online.');
    showDebtorOfflineReceipt(receipt, 'order');
}

function collectDebtorOrderItems() {
    var rows = document.querySelectorAll('.order-row');
    var items = [];
    rows.forEach(function(row, idx) {
        var qty = parseFloat(row.querySelector('.qty').value) || 0;
        if (qty > 0) {
            var price = parseFloat(row.dataset.price) || 0;
            var disc = parseFloat(row.querySelector('.disc').value) || 0;
            var total = (price * qty) - disc;
            if (total < 0) total = 0;
            var productId = row.querySelector('input[name*="product_id"]').value;
            var productName = row.querySelector('td').textContent.trim();
            items.push({
                product_id: productId,
                product_name: productName,
                quantity: qty,
                price: price,
                discount: disc,
                total: total
            });
        }
    });
    return items;
}

function formatDebtorOfflineDate(date) {
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
}

function formatDebtorOfflineTime(date) {
    var h = date.getHours();
    var m = date.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
}

function showDebtorOfflineSuccess(message) {
    var existing = document.querySelector('.cfi-offline-success');
    if (existing) existing.remove();
    
    var div = document.createElement('div');
    div.className = 'cfi-offline-success';
    div.innerHTML = '<i class="fas fa-check-circle"></i> ' + message;
    div.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;padding:1rem 2rem;border-radius:8px;font-weight:600;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,0.3);';
    document.body.appendChild(div);
    setTimeout(function() { div.remove(); }, 5000);
}

function showDebtorOfflineReceipt(receipt, type) {
    var existing = document.getElementById('debtor-offline-receipt-modal');
    if (existing) existing.remove();
    
    var modal = document.createElement('div');
    modal.id = 'debtor-offline-receipt-modal';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99998;padding:1rem;box-sizing:border-box;';
    // Store receipt data for printing
    modal.dataset.receipt = JSON.stringify(receipt);
    modal.dataset.receiptType = type;
    
    var content = buildDebtorOfflineReceiptContent(receipt, type);
    
    modal.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:400px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,0.3);">' +
        '<div style="background:#001943;color:#fff;padding:1rem;display:flex;justify-content:space-between;align-items:center;border-radius:12px 12px 0 0;">' +
            '<h3 style="margin:0;font-size:1rem;"><i class="fas fa-receipt"></i> ' + (type === 'order' ? 'Credit Order' : 'Payment') + ' Receipt</h3>' +
            '<button type="button" onclick="closeDebtorOfflineReceipt()" style="background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;">&times;</button>' +
        '</div>' +
        '<div id="debtor-offline-receipt-print-area">' + content + '</div>' +
        '<div style="padding:1rem;display:flex;gap:0.5rem;justify-content:center;border-top:1px solid #e5e7eb;">' +
            '<button type="button" onclick="printDebtorOfflineReceipt()" class="btn" style="background:#7c3aed;color:#fff;"><i class="fas fa-print"></i> Print</button>' +
            '<button type="button" onclick="closeDebtorOfflineReceipt()" class="btn" style="background:#16a34a;color:#fff;"><i class="fas fa-check"></i> Done</button>' +
        '</div>' +
    '</div>';
    
    document.body.appendChild(modal);
}

function buildDebtorOfflineReceiptContent(receipt, type) {
    var html = '<div style="padding:1rem;font-family:Arial,sans-serif;">';
    html += '<div style="text-align:center;border-bottom:2px dashed #ccc;padding-bottom:0.75rem;margin-bottom:0.75rem;">';
    html += '<h2 style="margin:0 0 0.25rem 0;color:#001943;font-size:1.25rem;">CHINEMEREM FOODS</h2>';
    html += '<p style="margin:0;color:#666;font-size:0.8rem;">Inventory Management System</p>';
    html += '</div>';
    
    html += '<div style="margin-bottom:0.75rem;font-size:0.85rem;">';
    html += '<p style="margin:0.25rem 0;"><strong>' + (type === 'order' ? 'Order' : 'Receipt') + '#:</strong> ' + receipt.order_number + '</p>';
    html += '<p style="margin:0.25rem 0;"><strong>Date:</strong> ' + receipt.date + '</p>';
    html += '<p style="margin:0.25rem 0;"><strong>Time:</strong> ' + receipt.time + '</p>';
    html += '<p style="margin:0.25rem 0;"><strong>Debtor:</strong> <span style="color:#dc2626;">' + receipt.debtor_name + '</span></p>';
    if (receipt.staff) {
        html += '<p style="margin:0.25rem 0;"><strong>Staff:</strong> ' + receipt.staff + '</p>';
    }
    html += '</div>';
    
    if (type === 'order' && receipt.items) {
        html += '<div style="border-top:1px solid #ccc;border-bottom:1px solid #ccc;padding:0.5rem 0;margin-bottom:0.75rem;">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:0.8rem;">';
        html += '<tr style="border-bottom:1px solid #eee;"><th style="text-align:left;padding:0.25rem 0;">Item</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Total</th></tr>';
        receipt.items.forEach(function(item) {
            html += '<tr><td style="padding:0.25rem 0;">' + item.product_name + '</td>';
            html += '<td style="text-align:right;">' + item.quantity + '</td>';
            html += '<td style="text-align:right;">₦' + item.total.toFixed(2) + '</td></tr>';
            if (item.discount > 0) {
                html += '<tr><td colspan="2" style="font-size:0.75rem;color:#666;padding-left:0.5rem;">Discount</td>';
                html += '<td style="text-align:right;color:#dc2626;font-size:0.75rem;">-₦' + item.discount.toFixed(2) + '</td></tr>';
            }
        });
        html += '</table>';
        html += '</div>';
        
        html += '<div style="margin-bottom:0.75rem;font-size:0.9rem;">';
        if (receipt.discount > 0) {
            html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;"><span>Total Discount:</span><span style="color:#dc2626;">-₦' + receipt.discount.toFixed(2) + '</span></p>';
        }
        html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;font-weight:700;font-size:1rem;"><span>Order Total:</span><span>₦' + receipt.grand_total.toFixed(2) + '</span></p>';
        html += '</div>';
        
        html += '<div style="background:#fef2f2;padding:0.75rem;border-radius:8px;margin-bottom:0.75rem;">';
        html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;"><span>Previous Balance:</span><span>₦' + receipt.current_debt.toFixed(2) + '</span></p>';
        html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;font-weight:700;color:#dc2626;"><span>New Balance:</span><span>₦' + receipt.new_balance.toFixed(2) + '</span></p>';
        html += '</div>';
    } else if (type === 'payment') {
        html += '<div style="margin-bottom:0.75rem;font-size:0.9rem;">';
        html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;"><span>Balance Before:</span><span style="color:#dc2626;">₦' + receipt.balance_before.toFixed(2) + '</span></p>';
        html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;font-weight:700;font-size:1rem;"><span>Payment Amount:</span><span style="color:#16a34a;">₦' + receipt.payment_amount.toFixed(2) + '</span></p>';
        if (receipt.transfer_amount > 0) {
            html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;padding-left:1rem;font-size:0.85rem;"><span>Transfer (' + receipt.bank_name + '):</span><span>₦' + receipt.transfer_amount.toFixed(2) + '</span></p>';
        }
        if (receipt.cash_amount > 0) {
            html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;padding-left:1rem;font-size:0.85rem;"><span>Cash:</span><span>₦' + receipt.cash_amount.toFixed(2) + '</span></p>';
        }
        if (receipt.home_amount > 0) {
            html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;padding-left:1rem;font-size:0.85rem;"><span>Home Calculation:</span><span>₦' + receipt.home_amount.toFixed(2) + '</span></p>';
        }
        html += '</div>';
        
        html += '<div style="background:#f0fdf4;padding:0.75rem;border-radius:8px;margin-bottom:0.75rem;">';
        html += '<p style="margin:0;display:flex;justify-content:space-between;font-weight:700;color:' + (receipt.new_balance > 0 ? '#dc2626' : '#16a34a') + ';"><span>New Balance:</span><span>₦' + receipt.new_balance.toFixed(2) + '</span></p>';
        html += '</div>';
    }
    
    html += '<div style="text-align:center;padding-top:0.75rem;border-top:2px dashed #ccc;">';
    html += '<p style="margin:0;color:#dc2626;font-weight:600;font-size:0.8rem;"><i class="fas fa-wifi"></i> Submitted Offline</p>';
    html += '<p style="margin:0.25rem 0 0 0;color:#666;font-size:0.75rem;">Will sync when back online</p>';
    html += '<p style="margin:0.5rem 0 0 0;color:#666;font-size:0.7rem;">Powered by BendlessTech</p>';
    html += '</div>';
    html += '</div>';
    
    return html;
}

function closeDebtorOfflineReceipt() {
    var modal = document.getElementById('debtor-offline-receipt-modal');
    if (modal) modal.remove();
    // Redirect to debtors list
    window.location.href = '<?php echo esc_url(remove_query_arg(array('debtor','action'))); ?>';
}

function printDebtorOfflineReceipt() {
    var modal = document.getElementById('debtor-offline-receipt-modal');
    if (!modal || !modal.dataset.receipt) return;
    
    try {
        var receipt = JSON.parse(modal.dataset.receipt);
        var type = modal.dataset.receiptType || 'order';
        
        // Build receipt content similar to take-order format
        var itemsHtml = '';
        var totalDiscount = 0;
        if (receipt.items && receipt.items.length > 0) {
            receipt.items.forEach(function(item) {
                totalDiscount += item.discount || 0;
                itemsHtml += '<div class="receipt-item">';
                itemsHtml += '<div class="receipt-row">';
                itemsHtml += '<span>' + item.product_name + '</span>';
                itemsHtml += '<span class="item-price">₦' + item.price.toFixed(2) + '</span>';
                itemsHtml += '<span class="item-qty">' + item.quantity + '</span>';
                itemsHtml += '<span class="item-total receipt-amount">₦' + item.total.toFixed(2) + '</span>';
                itemsHtml += '</div>';
                if (item.discount > 0) {
                    itemsHtml += '<div class="receipt-item-discount"><span>Discount:</span><span>-₦' + item.discount.toFixed(2) + '</span></div>';
                }
                itemsHtml += '</div>';
            });
        }
        
        var h = '<!DOCTYPE html><html><head><title>Print Receipt</title>';
        h += '<style>';
        h += '*{margin:0;padding:0;box-sizing:border-box}';
        h += 'html,body{width:80mm!important;max-width:80mm!important;margin:0!important;padding:0!important}';
        h += 'body{font-family:"Courier New",Courier,monospace;font-size:12px;line-height:1.4;color:#000;background:#fff}';
        h += '.receipt-body{width:80mm;padding:2mm;box-sizing:border-box}';
        h += '.receipt-company{text-align:center;margin-bottom:6px}';
        h += '.receipt-company h2{font-size:16px;font-weight:800;margin:0 0 4px;letter-spacing:0.5px;text-transform:uppercase}';
        h += '.receipt-company p{font-size:11px;margin:0}';
        h += '.receipt-divider{border-top:1px solid #000;margin:6px 0}';
        h += '.receipt-info p{display:flex;justify-content:space-between;margin:4px 0;font-size:12px}';
        h += '.receipt-row{display:grid;grid-template-columns:1.6fr 0.8fr 0.5fr 0.9fr;gap:6px;align-items:baseline;font-size:11px}';
        h += '.receipt-row .item-price,.receipt-row .item-qty,.receipt-row .item-total{text-align:right}';
        h += '.receipt-item-header{font-size:10px;font-weight:700;text-transform:uppercase}';
        h += '.receipt-item{padding:4px 0;border-bottom:1px dashed #999}';
        h += '.receipt-item:last-child{border-bottom:none}';
        h += '.receipt-item-discount{display:flex;justify-content:space-between;font-size:10px;margin-top:2px}';
        h += '.receipt-amount{font-weight:800}';
        h += '.receipt-totals p{display:flex;justify-content:space-between;margin:4px 0;font-size:12px}';
        h += '.receipt-totals .grand{font-size:13px;font-weight:700}';
        h += '.receipt-footer{text-align:center;margin-top:6px;font-size:10px}';
        h += '.credit-note{text-align:center;padding:6px;margin:6px 0;font-weight:700;font-size:11px;border:1px dashed #000}';
        h += '.offline-note{text-align:center;font-size:10px;margin:4px 0;color:#666}';
        h += '@media print{body{margin:0;padding:0}}';
        h += '</style></head><body>';
        h += '<div class="receipt-body">';
        h += '<div class="receipt-company">';
        h += '<h2>Chinemerem Foods</h2>';
        h += '<p>*** CREDIT ORDER ***</p>';
        h += '</div>';
        h += '<div class="receipt-divider"></div>';
        h += '<div class="receipt-info">';
        h += '<p><span>Order #:</span> <strong>' + receipt.order_number + '</strong></p>';
        h += '<p><span>Date:</span> ' + receipt.date + '</p>';
        h += '<p><span>Time:</span> ' + receipt.time + '</p>';
        h += '<p><span>Debtor:</span> <strong>' + receipt.debtor_name + '</strong></p>';
        if (receipt.staff) {
            h += '<p><span>Staff:</span> ' + receipt.staff + '</p>';
        }
        h += '</div>';
        h += '<div class="offline-note">📱 Submitted Offline - Will sync when online</div>';
        h += '<div class="receipt-divider"></div>';
        h += '<div class="receipt-items">';
        h += '<div class="receipt-row receipt-item-header">';
        h += '<span>Item</span>';
        h += '<span class="item-price">Price</span>';
        h += '<span class="item-qty">Qty</span>';
        h += '<span class="item-total">Total</span>';
        h += '</div>';
        h += itemsHtml;
        h += '</div>';
        h += '<div class="receipt-divider"></div>';
        h += '<div class="receipt-totals">';
        if (totalDiscount > 0) {
            h += '<p><span>Total Discount:</span> <span class="receipt-amount">-₦' + totalDiscount.toFixed(2) + '</span></p>';
        }
        h += '<p class="grand"><span>Order Total:</span> <span class="receipt-amount">₦' + receipt.grand_total.toFixed(2) + '</span></p>';
        h += '<p><span>Previous Bal:</span> <span class="receipt-amount">₦' + receipt.current_debt.toFixed(2) + '</span></p>';
        h += '<p class="grand"><span>NEW BALANCE:</span> <span class="receipt-amount">₦' + receipt.new_balance.toFixed(2) + '</span></p>';
        h += '</div>';
        h += '<div class="credit-note">⚠ CREDIT - PAYMENT PENDING</div>';
        h += '<div class="receipt-divider"></div>';
        h += '<div class="receipt-footer">';
        h += '<p>Thank you for your patronage!</p>';
        h += '<p>Powered by BendlessTech</p>';
        h += '</div>';
        h += '</div>';
        h += '</body></html>';
        
        // Try opening popup first (works better on some tablets)
        var w = window.open('', 'printReceipt', 'width=400,height=700,scrollbars=yes');
        if (w && !w.closed) {
            w.document.write(h);
            w.document.close();
            w.onload = function() { setTimeout(function() { w.print(); }, 300); };
            w.focus();
        } else {
            // Popup blocked - use iframe method for tablets
            var iframe = document.createElement('iframe');
            iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;';
            document.body.appendChild(iframe);
            var doc = iframe.contentWindow.document;
            doc.open();
            doc.write(h);
            doc.close();
            iframe.contentWindow.onload = function() {
                setTimeout(function() {
                    iframe.contentWindow.print();
                    setTimeout(function() { document.body.removeChild(iframe); }, 1000);
                }, 300);
            };
        }
    } catch(e) {
        console.error('Error printing offline receipt:', e);
        alert('Unable to print receipt. Please try again.');
    }
}
</script>

<?php elseif ($selected_debtor && $action === 'pay') : ?>
<?php $history_redirect_url = add_query_arg(array('debtor' => $selected_debtor->id), home_url('/cfi-debtors-history/')); ?>
<div class="glass">
<h3 style="color:#001943;margin-top:0"><i class="fas fa-money-check"></i> Record Payment</h3>
<p><strong>Debtor:</strong> <?php echo esc_html($selected_debtor->name); ?></p>
<p><strong>Outstanding Balance:</strong> <span class="cfi-debtor-balance" data-debtor-id="<?php echo esc_attr($selected_debtor->id); ?>" style="color:#dc2626;font-size:1.5rem;font-weight:700">₦<?php echo number_format($selected_debtor->display_debt, 0); ?></span></p>
<?php if ($selected_debtor->display_debt <= 0) : ?>
<div style="background:#dcfce7;color:#166534;padding:1rem;border-radius:8px;margin:1rem 0"><i class="fas fa-check-circle"></i> No outstanding debt!</div>
<a href="<?php echo esc_url(remove_query_arg(array('debtor','action'))); ?>" class="btn btn-primary">Back to Debtors</a>
<?php else : ?>
<form method="POST" id="pay-form">
<?php wp_nonce_field('cfi_clear_debt', 'cfi_clear_debt_nonce'); ?>
<input type="hidden" name="debtor_id" value="<?php echo esc_attr($selected_debtor->id); ?>">
<input type="hidden" name="cfi_clear_debt_submit" value="1">
<h4 style="color:#001943">Select Payment Method(s)</h4>
<p style="font-size:0.75rem;color:#64748b;margin-bottom:0.75rem"><i class="fas fa-info-circle"></i> Click to select payment method(s)</p>
<div class="payment-methods">
<div class="payment-method selected" data-method="transfer" onclick="togglePay(this)"><input type="checkbox" name="use_transfer" id="use_transfer" style="display:none" checked><i class="fas fa-credit-card"></i><span>Transfer/Card</span></div>
<div class="payment-method" data-method="cash" onclick="togglePay(this)"><input type="checkbox" name="use_cash" id="use_cash" style="display:none"><i class="fas fa-money-bill-wave"></i><span>Cash</span></div>
<?php if ($is_admin) : ?>
<div class="payment-method" data-method="home" onclick="togglePay(this)"><input type="checkbox" name="use_home" id="use_home" style="display:none"><i class="fas fa-home"></i><span>Home Calc</span></div>
<?php endif; ?>
</div>
<div class="bank-options" id="bank-opts" style="display:block">
<h4 style="color:#001943">Select Bank</h4>
<label class="bank-option"><input type="radio" name="bank_name" value="Moniepoint MFB" checked><span>Moniepoint MFB</span></label>
<label class="bank-option"><input type="radio" name="bank_name" value="Access Bank PLC"><span>Access Bank PLC</span></label>
</div>
<div id="pay-amounts">
<div class="form-group" id="transfer-grp" style="display:block"><label>Transfer Amount (₦)</label><input type="text" id="transfer_amount" name="transfer_amount" class="input cfi-amount-input" value="0" inputmode="numeric" oninput="updatePayTotal()"></div>
<div class="form-group" id="cash-grp" style="display:none"><label>Cash Amount (₦)</label><input type="text" id="cash_amount" name="cash_amount" class="input cfi-amount-input" value="0" inputmode="numeric" oninput="updatePayTotal()"></div>
<?php if ($is_admin) : ?>
<div class="form-group" id="home-grp" style="display:none"><label>Home Calculation (₦)</label><input type="text" id="home_amount" name="home_amount" class="input cfi-amount-input" value="0" inputmode="numeric" oninput="updatePayTotal()"></div>
<div class="form-group" id="home-remarks-grp" style="display:none"><label>Home Reconciliation Remarks</label><textarea id="home_remarks" name="home_remarks" class="input" rows="3" placeholder="Enter remarks for home reconciliation..."></textarea></div>
<?php else : ?>
<input type="hidden" name="home_amount" value="0">
<?php endif; ?>
</div>
<div style="margin-top:1rem;padding:1rem;background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;border-radius:8px;text-align:center"><span style="font-size:0.9rem">Total Payment:</span><strong id="pay-total" style="font-size:1.5rem;display:block">₦0</strong></div>
<div id="pay-warn" style="display:none;margin-top:0.5rem;padding:0.75rem;border-radius:8px;font-size:0.85rem"></div>
<div style="margin-top:1.5rem;display:flex;gap:1rem;justify-content:flex-end">
<a href="<?php echo esc_url(remove_query_arg(array('debtor','action'))); ?>" class="btn btn-outline">Cancel</a>
<button type="submit" name="cfi_clear_debt_submit" class="btn btn-success"><i class="fas fa-check"></i> Record Payment</button>
</div>
</form>
<script>
window.cfiDebtorState = window.cfiDebtorState || {};
window.cfiDebtorState.debt = <?php echo floatval($selected_debtor->display_debt); ?>;
var debt = window.cfiDebtorState.debt;
function togglePay(el){
    el.classList.toggle('selected');
    var m=el.dataset.method;
    var c=el.querySelector('input[type="checkbox"]');
    c.checked=el.classList.contains('selected');
    if(m==='transfer'){
        document.getElementById('transfer-grp').style.display=c.checked?'block':'none';
        document.getElementById('bank-opts').style.display=c.checked?'block':'none';
        if(!c.checked)document.getElementById('transfer_amount').value=0;
    } else if(m==='cash'){
        document.getElementById('cash-grp').style.display=c.checked?'block':'none';
        if(!c.checked)document.getElementById('cash_amount').value=0;
    } else if(m==='home'){
        var hg=document.getElementById('home-grp');
        var hrg=document.getElementById('home-remarks-grp');
        if(hg){hg.style.display=c.checked?'block':'none';if(!c.checked)document.getElementById('home_amount').value=0}
        if(hrg){hrg.style.display=c.checked?'block':'none';if(!c.checked)document.getElementById('home_remarks').value=''}
    }
    updatePayTotal();
}
function updatePayTotal(){var t=CFI.utils.parseNumber(document.getElementById('transfer_amount').value),ca=CFI.utils.parseNumber(document.getElementById('cash_amount').value),h=document.getElementById('home_amount'),ha=h?CFI.utils.parseNumber(h.value):0,tot=t+ca+ha;
document.getElementById('pay-total').textContent='₦'+CFI.utils.formatNumber(tot);
var diff=debt-tot,w=document.getElementById('pay-warn');
if(Math.abs(diff)>0.01&&tot>0){w.style.display='block';if(diff>0){w.textContent='Payment is ₦'+CFI.utils.formatNumber(diff)+' less than debt';w.style.background='#fee2e2';w.style.color='#991b1b'}else{w.textContent='Payment exceeds debt by ₦'+CFI.utils.formatNumber(Math.abs(diff));w.style.background='#fef3c7';w.style.color='#92400e'}}else{w.style.display='none'}}

// Offline payment support
var cfiPayForm = document.getElementById('pay-form');
if (cfiPayForm) {
    cfiPayForm.addEventListener('submit', function(e) {
        if (!navigator.onLine) {
            e.preventDefault();
            submitDebtorPaymentOffline();
        }
    });
}

function submitDebtorPaymentOffline() {
    var transferAmount = CFI.utils.parseNumber(document.getElementById('transfer_amount').value);
    var cashAmount = CFI.utils.parseNumber(document.getElementById('cash_amount').value);
    var homeEl = document.getElementById('home_amount');
    var homeAmount = homeEl ? CFI.utils.parseNumber(homeEl.value) : 0;
    var totalPayment = transferAmount + cashAmount + homeAmount;
    
    if (totalPayment <= 0) {
        alert('Please enter a payment amount.');
        return;
    }
    
    var bankInput = document.querySelector('input[name="bank_name"]:checked');
    var bankName = bankInput ? bankInput.value : 'Moniepoint MFB';
    
    var paymentMethod = 'transfer';
    if (transferAmount > 0 && cashAmount > 0) {
        paymentMethod = 'split';
    } else if (cashAmount > 0) {
        paymentMethod = 'cash';
    }
    
    var now = new Date();
    var receiptNumber = 'PAY-' + now.getFullYear() + 
        String(now.getMonth() + 1).padStart(2, '0') + 
        String(now.getDate()).padStart(2, '0') + '-' + 
        String(now.getHours()).padStart(2, '0') + 
        String(now.getMinutes()).padStart(2, '0') + 
        String(now.getSeconds()).padStart(2, '0');
    
    var currentDebt = <?php echo floatval($selected_debtor->display_debt); ?>;
    var newBalance = currentDebt - totalPayment;
    
    var homeRemarksEl = document.getElementById('home_remarks');
    var homeRemarks = homeRemarksEl ? homeRemarksEl.value : '';
    
    var payload = {
        debtor_id: <?php echo (int)$selected_debtor->id; ?>,
        transfer_amount: transferAmount,
        cash_amount: cashAmount,
        home_calculation: homeAmount,
        payment_method: paymentMethod,
        bank_name: bankName,
        home_remarks: homeRemarks
    };
    
    var receipt = {
        order_number: receiptNumber,
        debtor_name: '<?php echo esc_js($selected_debtor->name); ?>',
        date: formatPaymentOfflineDate(now),
        time: formatPaymentOfflineTime(now),
        balance_before: currentDebt,
        payment_amount: totalPayment,
        transfer_amount: transferAmount,
        cash_amount: cashAmount,
        home_amount: homeAmount,
        bank_name: bankName,
        new_balance: newBalance,
        home_remarks: homeRemarks,
        staff: (window.cfiData && cfiData.currentUser) ? cfiData.currentUser : ''
    };
    
    // Add to offline queue
    if (window.CFI && CFI.offline && typeof CFI.offline.addToQueue === 'function') {
        CFI.offline.addToQueue('debtor_payment', payload);
    } else {
        // Fallback: save to localStorage directly
        var queue = JSON.parse(localStorage.getItem('cfi_offline_queue') || '[]');
        queue.push({
            id: 'off_' + Date.now(),
            type: 'debtor_payment',
            data: payload,
            timestamp: Date.now()
        });
        localStorage.setItem('cfi_offline_queue', JSON.stringify(queue));
    }
    
    showPaymentOfflineSuccess('Payment recorded offline. It will sync when you are back online.');
    showPaymentOfflineReceipt(receipt);
}

function formatPaymentOfflineDate(date) {
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
}

function formatPaymentOfflineTime(date) {
    var h = date.getHours();
    var m = date.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
}

document.addEventListener('input', function(e) {
    if (!e.target || !e.target.classList.contains('cfi-amount-input')) {
        return;
    }
    var raw = CFI.utils.parseNumber(e.target.value);
    e.target.value = raw ? CFI.utils.formatNumber(raw) : '';
    updatePayTotal();
});
document.addEventListener('blur', function(e) {
    if (!e.target || !e.target.classList.contains('cfi-amount-input')) {
        return;
    }
    if (e.target.value === '') {
        e.target.value = '0';
        updatePayTotal();
    }
}, true);

function showPaymentOfflineSuccess(message) {
    var existing = document.querySelector('.cfi-offline-success');
    if (existing) existing.remove();
    
    var div = document.createElement('div');
    div.className = 'cfi-offline-success';
    div.innerHTML = '<i class="fas fa-check-circle"></i> ' + message;
    div.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;padding:1rem 2rem;border-radius:8px;font-weight:600;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,0.3);';
    document.body.appendChild(div);
    setTimeout(function() { div.remove(); }, 5000);
}

function showPaymentOfflineReceipt(receipt) {
    var existing = document.getElementById('payment-offline-receipt-modal');
    if (existing) existing.remove();
    
    var modal = document.createElement('div');
    modal.id = 'payment-offline-receipt-modal';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99998;padding:1rem;box-sizing:border-box;';
    // Store receipt data for printing
    modal.dataset.receipt = JSON.stringify(receipt);
    
    var content = buildPaymentOfflineReceiptContent(receipt);
    
    modal.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:400px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,0.3);">' +
        '<div style="background:#16a34a;color:#fff;padding:1rem;display:flex;justify-content:space-between;align-items:center;border-radius:12px 12px 0 0;">' +
            '<h3 style="margin:0;font-size:1rem;"><i class="fas fa-receipt"></i> Payment Receipt</h3>' +
            '<button type="button" onclick="closePaymentOfflineReceipt()" style="background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;">&times;</button>' +
        '</div>' +
        '<div id="payment-offline-receipt-print-area">' + content + '</div>' +
        '<div style="padding:1rem;display:flex;gap:0.5rem;justify-content:center;border-top:1px solid #e5e7eb;">' +
            '<button type="button" onclick="printPaymentOfflineReceipt()" class="btn" style="background:#7c3aed;color:#fff;"><i class="fas fa-print"></i> Print</button>' +
            '<button type="button" onclick="closePaymentOfflineReceipt()" class="btn" style="background:#16a34a;color:#fff;"><i class="fas fa-check"></i> Done</button>' +
        '</div>' +
    '</div>';
    
    document.body.appendChild(modal);
}

function buildPaymentOfflineReceiptContent(receipt) {
    var html = '<div style="padding:1rem;font-family:Arial,sans-serif;">';
    html += '<div style="text-align:center;border-bottom:2px dashed #ccc;padding-bottom:0.75rem;margin-bottom:0.75rem;">';
    html += '<h2 style="margin:0 0 0.25rem 0;color:#001943;font-size:1.25rem;">CHINEMEREM FOODS</h2>';
    html += '<p style="margin:0;color:#666;font-size:0.8rem;">Debt Payment Receipt</p>';
    html += '</div>';
    
    html += '<div style="margin-bottom:0.75rem;font-size:0.85rem;">';
    html += '<p style="margin:0.25rem 0;"><strong>Receipt#:</strong> ' + receipt.order_number + '</p>';
    html += '<p style="margin:0.25rem 0;"><strong>Date:</strong> ' + receipt.date + '</p>';
    html += '<p style="margin:0.25rem 0;"><strong>Time:</strong> ' + receipt.time + '</p>';
    html += '<p style="margin:0.25rem 0;"><strong>Debtor:</strong> <span style="color:#16a34a;">' + receipt.debtor_name + '</span></p>';
    if (receipt.staff) {
        html += '<p style="margin:0.25rem 0;"><strong>Staff:</strong> ' + receipt.staff + '</p>';
    }
    html += '</div>';
    
    html += '<div style="border-top:1px solid #ccc;padding-top:0.75rem;margin-bottom:0.75rem;font-size:0.9rem;">';
    html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;"><span>Balance Before:</span><span style="color:#dc2626;">₦' + receipt.balance_before.toFixed(2) + '</span></p>';
    html += '<p style="margin:0.5rem 0;display:flex;justify-content:space-between;font-weight:700;font-size:1rem;background:#f0fdf4;padding:0.5rem;border-radius:6px;"><span>Payment Amount:</span><span style="color:#16a34a;">₦' + receipt.payment_amount.toFixed(2) + '</span></p>';
    if (receipt.transfer_amount > 0) {
        html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;padding-left:1rem;font-size:0.85rem;"><span>- Transfer (' + receipt.bank_name + '):</span><span>₦' + receipt.transfer_amount.toFixed(2) + '</span></p>';
    }
    if (receipt.cash_amount > 0) {
        html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;padding-left:1rem;font-size:0.85rem;"><span>- Cash:</span><span>₦' + receipt.cash_amount.toFixed(2) + '</span></p>';
    }
    if (receipt.home_amount > 0) {
        html += '<p style="margin:0.25rem 0;display:flex;justify-content:space-between;padding-left:1rem;font-size:0.85rem;"><span>- Home Calculation:</span><span>₦' + receipt.home_amount.toFixed(2) + '</span></p>';
    }
    html += '</div>';
    
    html += '<div style="background:' + (receipt.new_balance > 0 ? '#fef2f2' : '#f0fdf4') + ';padding:0.75rem;border-radius:8px;margin-bottom:0.75rem;">';
    html += '<p style="margin:0;display:flex;justify-content:space-between;font-weight:700;color:' + (receipt.new_balance > 0 ? '#dc2626' : '#16a34a') + ';font-size:1.1rem;"><span>New Balance:</span><span>₦' + receipt.new_balance.toFixed(2) + '</span></p>';
    html += '</div>';
    
    html += '<div style="text-align:center;padding-top:0.75rem;border-top:2px dashed #ccc;">';
    html += '<p style="margin:0;color:#16a34a;font-weight:600;font-size:0.85rem;">Payment Received with Thanks!</p>';
    html += '<p style="margin:0.5rem 0 0 0;color:#dc2626;font-weight:600;font-size:0.8rem;"><i class="fas fa-wifi"></i> Submitted Offline</p>';
    html += '<p style="margin:0.25rem 0 0 0;color:#666;font-size:0.75rem;">Will sync when back online</p>';
    html += '<p style="margin:0.5rem 0 0 0;color:#666;font-size:0.7rem;">Powered by BendlessTech</p>';
    html += '</div>';
    html += '</div>';
    
    return html;
}

function closePaymentOfflineReceipt() {
    var modal = document.getElementById('payment-offline-receipt-modal');
    if (modal) modal.remove();
    // Redirect to debtors list
    window.location.href = '<?php echo esc_url(remove_query_arg(array('debtor','action'))); ?>';
}

function printPaymentOfflineReceipt() {
    var modal = document.getElementById('payment-offline-receipt-modal');
    if (!modal || !modal.dataset.receipt) return;
    
    try {
        var receipt = JSON.parse(modal.dataset.receipt);
        
        var h = '<!DOCTYPE html><html><head><title>Print Receipt</title>';
        h += '<style>';
        h += '*{margin:0;padding:0;box-sizing:border-box}';
        h += 'html,body{width:80mm!important;max-width:80mm!important;margin:0!important;padding:0!important}';
        h += 'body{font-family:"Courier New",Courier,monospace;font-size:12px;line-height:1.4;color:#000;background:#fff}';
        h += '.receipt-body{width:80mm;padding:2mm;box-sizing:border-box}';
        h += '.receipt-company{text-align:center;margin-bottom:6px}';
        h += '.receipt-company h2{font-size:16px;font-weight:800;margin:0 0 4px;letter-spacing:0.5px;text-transform:uppercase}';
        h += '.receipt-company p{font-size:11px;margin:0}';
        h += '.receipt-divider{border-top:1px solid #000;margin:6px 0}';
        h += '.receipt-info p{display:flex;justify-content:space-between;margin:4px 0;font-size:12px}';
        h += '.receipt-amount{font-weight:800}';
        h += '.receipt-totals p{display:flex;justify-content:space-between;margin:4px 0;font-size:12px}';
        h += '.receipt-totals .grand{font-size:13px;font-weight:700}';
        h += '.receipt-footer{text-align:center;margin-top:6px;font-size:10px}';
        h += '.payment-box{padding:6px;margin:6px 0;border:1px dashed #000}';
        h += '.offline-note{text-align:center;font-size:10px;margin:4px 0;color:#666}';
        h += '@media print{body{margin:0;padding:0}}';
        h += '</style></head><body>';
        h += '<div class="receipt-body">';
        h += '<div class="receipt-company">';
        h += '<h2>Chinemerem Foods</h2>';
        h += '<p>Debt Payment Receipt</p>';
        h += '</div>';
        h += '<div class="receipt-divider"></div>';
        h += '<div class="receipt-info">';
        h += '<p><span>Receipt #:</span> <strong>' + receipt.order_number + '</strong></p>';
        h += '<p><span>Date:</span> ' + receipt.date + '</p>';
        h += '<p><span>Time:</span> ' + receipt.time + '</p>';
        h += '<p><span>Debtor:</span> <strong>' + receipt.debtor_name + '</strong></p>';
        if (receipt.staff) {
            h += '<p><span>Staff:</span> ' + receipt.staff + '</p>';
        }
        h += '</div>';
        h += '<div class="offline-note">📱 Submitted Offline - Will sync when online</div>';
        h += '<div class="receipt-divider"></div>';
        h += '<div class="receipt-totals">';
        h += '<p><span>Balance Before:</span> <span class="receipt-amount">₦' + receipt.balance_before.toFixed(2) + '</span></p>';
        h += '<div class="payment-box">';
        h += '<p class="grand"><span>PAYMENT:</span> <span class="receipt-amount">₦' + receipt.payment_amount.toFixed(2) + '</span></p>';
        if (receipt.transfer_amount > 0) {
            h += '<p><span>  - Transfer (' + receipt.bank_name + '):</span> <span>₦' + receipt.transfer_amount.toFixed(2) + '</span></p>';
        }
        if (receipt.cash_amount > 0) {
            h += '<p><span>  - Cash:</span> <span>₦' + receipt.cash_amount.toFixed(2) + '</span></p>';
        }
        if (receipt.home_amount > 0) {
            h += '<p><span>  - Home Calc:</span> <span>₦' + receipt.home_amount.toFixed(2) + '</span></p>';
        }
        h += '</div>';
        h += '<p class="grand"><span>NEW BALANCE:</span> <span class="receipt-amount">₦' + receipt.new_balance.toFixed(2) + '</span></p>';
        h += '</div>';
        h += '<div class="receipt-divider"></div>';
        h += '<div class="receipt-footer">';
        h += '<p>Payment received with thanks!</p>';
        h += '<p>Powered by BendlessTech</p>';
        h += '</div>';
        h += '</div>';
        h += '</body></html>';
        
        // Try opening popup first (works better on some tablets)
        var w = window.open('', 'printReceipt', 'width=400,height=700,scrollbars=yes');
        if (w && !w.closed) {
            w.document.write(h);
            w.document.close();
            w.onload = function() { setTimeout(function() { w.print(); }, 300); };
            w.focus();
        } else {
            // Popup blocked - use iframe method for tablets
            var iframe = document.createElement('iframe');
            iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;';
            document.body.appendChild(iframe);
            var doc = iframe.contentWindow.document;
            doc.open();
            doc.write(h);
            doc.close();
            iframe.contentWindow.onload = function() {
                setTimeout(function() {
                    iframe.contentWindow.print();
                    setTimeout(function() { document.body.removeChild(iframe); }, 1000);
                }, 300);
            };
        }
    } catch(e) {
        console.error('Error printing offline receipt:', e);
        alert('Unable to print receipt. Please try again.');
    }
}
</script>
<?php endif; ?>
</div>

<?php else : ?>
<div class="section-title"><i class="fas fa-users"></i><span>Current Debtors</span></div>
<?php if (empty($debtors)) : ?>
<div class="glass empty"><i class="fas fa-users"></i><h3>No Debtors Found</h3><p>Admin can add debtors from the Admin Panel.</p></div>
<?php else : ?>
<div class="card-grid">
<?php foreach ($debtors as $debtor) : ?>
<div class="card" data-debtor-id="<?php echo esc_attr($debtor->id); ?>">
<h3 class="card-name"><?php echo esc_html($debtor->name); ?></h3>
<?php if ($debtor->phone) : ?><p class="card-phone"><i class="fas fa-phone"></i> <?php echo esc_html($debtor->phone); ?></p><?php endif; ?>
<div class="card-balance cfi-debtor-balance <?php echo $debtor->display_debt <= 0 ? 'zero' : ''; ?>" data-debtor-id="<?php echo esc_attr($debtor->id); ?>">₦<?php echo number_format($debtor->display_debt, 0); ?></div>
<div class="card-actions">
<a href="<?php echo esc_url(add_query_arg(array('debtor'=>$debtor->id,'action'=>'order'))); ?>" class="btn btn-primary"><i class="fas fa-cart-plus"></i> Order</a>
<a href="<?php echo esc_url(add_query_arg(array('debtor'=>$debtor->id,'action'=>'pay'))); ?>" class="btn btn-success"><i class="fas fa-money-check"></i> Clear Debt</a>
</div>
<div style="margin-top:0.75rem"><a href="<?php echo esc_url(add_query_arg(array('debtor' => $debtor->id), home_url('/cfi-debtors-history/'))); ?>" class="btn btn-outline" style="width:100%;justify-content:center"><i class="fas fa-history"></i> View History</a></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</main>

<?php if ($order_receipt) : ?>
<div class="modal" id="order-modal">
<div class="modal-content">
<div class="modal-header order"><h3><i class="fas fa-receipt"></i> Credit Receipt</h3><button class="modal-close" onclick="closeOrderModal()">&times;</button></div>
<div class="modal-body" id="order-print-area">
<div class="receipt-company">
    <h2>Chinemerem Foods</h2>
    <p>Inventory Management System</p>
</div>
<div class="receipt-divider"></div>
<div class="receipt-info">
<p><span>Order #:</span><strong><?php echo esc_html($order_receipt['order_number']); ?></strong></p>
<p><span>Date:</span><?php echo esc_html($order_receipt['date']); ?></p>
<p><span>Time:</span><?php echo esc_html($order_receipt['time']); ?></p>
<p><span>Debtor:</span><strong style="color:#dc2626"><?php echo esc_html($order_receipt['debtor_name']); ?></strong></p>
<p><span>Staff:</span><?php echo esc_html($order_receipt['staff']); ?></p>
</div>
<div class="receipt-divider"></div>
<?php
$order_discount_total = 0;
foreach ($order_receipt['items'] as $item) {
    $order_discount_total += (float) $item['discount'];
}
?>
<div class="receipt-items">
    <div class="receipt-row receipt-item-header">
        <span>Item</span>
        <span class="item-price">Price</span>
        <span class="item-qty">Qty</span>
        <span class="item-total">Total</span>
    </div>
    <?php foreach ($order_receipt['items'] as $item) : ?>
    <div class="receipt-item">
        <div class="receipt-row receipt-item-row">
            <span><?php echo esc_html($item['product_name']); ?></span>
            <span class="item-price receipt-amount">₦<?php echo cfi_format_receipt_value($item['price']); ?></span>
            <span class="item-qty receipt-amount"><?php echo cfi_format_receipt_value($item['quantity']); ?></span>
            <span class="item-total receipt-amount">₦<?php echo cfi_format_receipt_value($item['total']); ?></span>
        </div>
        <div class="receipt-item-discount">
            <span>Discount:</span>
            <span class="receipt-amount"><?php echo $item['discount'] > 0 ? '-₦' . cfi_format_receipt_value($item['discount']) : '-'; ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="receipt-divider"></div>
<div class="receipt-totals">
    <p><span>Subtotal:</span> <span class="receipt-amount">₦<?php echo cfi_format_receipt_value($order_receipt['total'] + $order_discount_total); ?></span></p>
    <p><span>Total Discount:</span> <span class="receipt-amount">-₦<?php echo cfi_format_receipt_value($order_discount_total); ?></span></p>
    <p class="grand"><span>Order Total:</span> <span class="receipt-amount">₦<?php echo cfi_format_receipt_value($order_receipt['total']); ?></span></p>
</div>
<div class="receipt-divider"></div>
<div class="receipt-info">
    <p><span>New Balance:</span><span class="receipt-amount">₦<?php echo cfi_format_receipt_value($order_receipt['new_balance']); ?></span></p>
</div>
<div class="receipt-footer">
    <p>This is a credit order</p>
    <p>Payment pending</p>
    <p>Powered by BendlessTech</p>
</div>
</div>
<div class="modal-footer">
<button type="button" onclick="sendOrderReceipt()" class="btn" style="background:#001943;color:#fff"><i class="fab fa-whatsapp"></i> Send to WhatsApp</button>
<button onclick="printOrderReceipt()" class="btn" style="background:#7c3aed;color:#fff"><i class="fas fa-print"></i> Print</button>
<button onclick="closeOrderModal()" class="btn btn-success"><i class="fas fa-check"></i> Done</button>
</div>
</div>
</div>
<script>
async function printOrderReceipt(){
// Try Bluetooth printing first
if ('bluetooth' in navigator) {
    var text = '';
    var line = '================================';
    text += '       CHINEMEREM FOODS\n';
    text += '      Credit Order Receipt\n';
    text += line + '\n';
    text += 'Order: <?php echo esc_js($order_receipt['order_number']); ?>\n';
    text += 'Date: <?php echo esc_js($order_receipt['date']); ?>\n';
    text += 'Time: <?php echo esc_js($order_receipt['time']); ?>\n';
    text += 'Debtor: <?php echo esc_js($order_receipt['debtor_name']); ?>\n';
    text += 'Staff: <?php echo esc_js($order_receipt['staff']); ?>\n';
    text += line + '\n';
    text += 'ITEM        PRICE QTY TOTAL\n';
    text += '--------------------------------\n';
    <?php foreach ($order_receipt['items'] as $item) : ?>
    text += '<?php echo str_pad(substr(esc_js($item['product_name']), 0, 10), 10); ?> <?php echo str_pad(cfi_format_receipt_value($item['price']), 6, ' ', STR_PAD_LEFT); ?> <?php echo str_pad(cfi_format_receipt_value($item['quantity']), 3, ' ', STR_PAD_LEFT); ?> <?php echo str_pad(cfi_format_receipt_value($item['total']), 8, ' ', STR_PAD_LEFT); ?>\n';
    text += '  Discount:<?php echo str_pad($item['discount'] > 0 ? '-N' . cfi_format_receipt_value($item['discount']) : '-', 21, ' ', STR_PAD_LEFT); ?>\n';
    <?php endforeach; ?>
    text += line + '\n';
    text += 'Total Discount:   -N<?php echo str_pad(cfi_format_receipt_value($order_discount_total), 9, ' ', STR_PAD_LEFT); ?>\n';
    text += 'ORDER TOTAL:       N<?php echo str_pad(cfi_format_receipt_value($order_receipt['total']), 9, ' ', STR_PAD_LEFT); ?>\n';
    text += 'NEW BALANCE:       N<?php echo str_pad(cfi_format_receipt_value($order_receipt['new_balance']), 9, ' ', STR_PAD_LEFT); ?>\n';
    text += line + '\n';
    text += '  This is a credit order\n';
    text += '      Payment pending\n';
    text += '   Powered by BendlessTech\n';
    text += '\n\n\n';
    
    var printed = await printToBluetoothPrinter(text);
    if (printed) {
        return;
    }
}

// Fallback to browser print - 80mm thermal format matching take-order
var printWindow = window.open('', '_blank', 'width=400,height=700');
if (!printWindow) {
    alert('Please allow popups to print receipts');
    return;
}

printWindow.document.write('<!DOCTYPE html><html><head><title>Print Receipt</title>');
printWindow.document.write('<style>');
printWindow.document.write('@page{size:80mm auto;margin:0}');
printWindow.document.write('*{margin:0;padding:0;box-sizing:border-box}');
printWindow.document.write('html,body{width:80mm!important;max-width:80mm!important;margin:0!important;padding:0!important}');
printWindow.document.write('body{font-family:"Courier New",Courier,monospace;font-size:12px;line-height:1.4;color:#000;background:#fff}');
printWindow.document.write('.receipt{width:80mm;padding:2mm;box-sizing:border-box}');
printWindow.document.write('.header{text-align:center;margin-bottom:6px}');
printWindow.document.write('.header h2{font-size:16px;font-weight:800;margin:0 0 4px;letter-spacing:0.5px;text-transform:uppercase}');
printWindow.document.write('.header p{font-size:11px;margin:0}');
printWindow.document.write('.divider{border-top:1px solid #000;margin:6px 0}');
printWindow.document.write('.info-row{display:flex;justify-content:space-between;margin:4px 0;font-size:12px}');
printWindow.document.write('.info-row .value{font-weight:700}');
printWindow.document.write('.items{margin:6px 0}');
printWindow.document.write('.item-row{display:grid;grid-template-columns:1.6fr 0.8fr 0.5fr 0.9fr;gap:6px;align-items:baseline;font-size:11px}');
printWindow.document.write('.item-row .item-price,.item-row .item-qty,.item-row .item-total{text-align:right}');
printWindow.document.write('.item-header{font-size:10px;font-weight:700;text-transform:uppercase}');
printWindow.document.write('.item{padding:4px 0;border-bottom:1px dashed #999}');
printWindow.document.write('.item:last-child{border-bottom:none}');
printWindow.document.write('.item-discount{display:flex;justify-content:space-between;font-size:10px;margin-top:2px}');
printWindow.document.write('.receipt-amount{font-weight:800}');
printWindow.document.write('.totals p{display:flex;justify-content:space-between;margin:4px 0;font-size:12px}');
printWindow.document.write('.totals .grand{font-size:13px;font-weight:700}');
printWindow.document.write('.credit-note{text-align:center;padding:6px;margin:6px 0;font-weight:700;font-size:10px;border:1px dashed #000}');
printWindow.document.write('.footer{text-align:center;margin-top:6px;font-size:10px}');
printWindow.document.write('.footer .thanks{font-weight:700;font-size:11px}');
printWindow.document.write('@media print{html,body{width:100%!important}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}');
printWindow.document.write('</style></head><body>');

var h = '<div class="receipt">';
h += '<div class="header"><h2>CHINEMEREM FOODS</h2><p>CREDIT ORDER RECEIPT</p></div>';
h += '<div class="divider"></div>';
h += '<div class="info">';
h += '<div class="info-row"><span class="label">Order No:</span><span class="value"><?php echo esc_js($order_receipt['order_number']); ?></span></div>';
h += '<div class="info-row"><span class="label">Date:</span><span class="value"><?php echo esc_js($order_receipt['date']); ?></span></div>';
h += '<div class="info-row"><span class="label">Time:</span><span class="value"><?php echo esc_js($order_receipt['time']); ?></span></div>';
h += '<div class="info-row"><span class="label">Debtor:</span><span class="value"><?php echo esc_js($order_receipt['debtor_name']); ?></span></div>';
h += '<div class="info-row"><span class="label">Staff:</span><span class="value"><?php echo esc_js($order_receipt['staff']); ?></span></div>';
h += '</div>';
h += '<div class="divider"></div>';
h += '<div class="items">';
h += '<div class="item-row item-header"><span>Item</span><span class="item-price">Price</span><span class="item-qty">Qty</span><span class="item-total">Total</span></div>';
<?php foreach ($order_receipt['items'] as $item) : ?>
h += '<div class="item">';
h += '<div class="item-row"><span><?php echo esc_js($item['product_name']); ?></span><span class="item-price receipt-amount">₦<?php echo cfi_format_receipt_value($item['price']); ?></span><span class="item-qty receipt-amount"><?php echo cfi_format_receipt_value($item['quantity']); ?></span><span class="item-total receipt-amount">₦<?php echo cfi_format_receipt_value($item['total']); ?></span></div>';
h += '<div class="item-discount"><span>Discount:</span><span class="receipt-amount"><?php echo isset($item['discount']) && $item['discount'] > 0 ? '-₦' . cfi_format_receipt_value($item['discount']) : '-'; ?></span></div>';
h += '</div>';
<?php endforeach; ?>
h += '</div>';
h += '<div class="divider"></div>';
h += '<div class="totals">';
h += '<p><span>Subtotal:</span><span class="receipt-amount">₦<?php echo cfi_format_receipt_value($order_receipt['total'] + $order_discount_total); ?></span></p>';
h += '<p><span>Total Discount:</span><span class="receipt-amount">-₦<?php echo cfi_format_receipt_value($order_discount_total); ?></span></p>';
h += '<p class="grand"><span>Order Total:</span><span class="receipt-amount">₦<?php echo cfi_format_receipt_value($order_receipt['total']); ?></span></p>';
h += '<p class="grand"><span>New Balance:</span><span class="receipt-amount">₦<?php echo cfi_format_receipt_value($order_receipt['new_balance']); ?></span></p>';
h += '</div>';
h += '<div class="credit-note">⚠ CREDIT ORDER - PAYMENT PENDING</div>';
h += '<div class="divider"></div>';
h += '<div class="footer"><p class="thanks">Thank you for your patronage!</p><p>Powered by BendlessTech</p></div>';
h += '</div>';

printWindow.document.write(h);
printWindow.document.write('</body></html>');
printWindow.document.close();
printWindow.onload = function() {
    setTimeout(function() { printWindow.print(); }, 300);
};
}
function closeOrderModal(){document.getElementById('order-modal').style.display='none';window.location.href='<?php echo esc_url($debtor_record_done_url); ?>'}

function sendOrderReceipt(){
    var receiptText = buildOrderReceiptText();
    if (!receiptText) {
        alert('Unable to build receipt message');
        return;
    }
    shareOrderReceiptImage('order-print-area', receiptText);
}

function buildOrderReceiptText(){
    var lines = [];
    lines.push('CHINEMEREM FOODS');
    lines.push('Credit Order Receipt');
    lines.push('Order: <?php echo esc_js($order_receipt['order_number']); ?>');
    lines.push('Date: <?php echo esc_js($order_receipt['date']); ?>');
    lines.push('Time: <?php echo esc_js($order_receipt['time']); ?>');
    lines.push('Debtor: <?php echo esc_js($order_receipt['debtor_name']); ?>');
    lines.push('Amount: ₦<?php echo esc_js(number_format($order_receipt['total'], 0)); ?>');
    lines.push('New Balance: ₦<?php echo esc_js(number_format($order_receipt['new_balance'], 0)); ?>');
    lines.push('Powered by BendlessTech');
    return lines.join('\n');
}

function shareOrderReceiptImage(elementId, receiptText){
    var receiptNode = document.getElementById(elementId);
    if (!receiptNode) {
        alert('Receipt image not available.');
        return;
    }
    html2canvas(receiptNode, { backgroundColor: '#ffffff', scale: 1.5, logging: false }).then(function(canvas) {
        canvas.toBlob(function(blob) {
            if (!blob) {
                alert('Receipt image could not be created.');
                return;
            }
            var file = new File([blob], 'chinemerem-foods-receipt.png', { type: 'image/png' });
            if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                navigator.share({
                    title: 'Chinemerem Foods Receipt',
                    text: receiptText,
                    files: [file]
                }).catch(function(error){
                    console.warn('Share cancelled or failed', error);
                    // Fallback: download the image
                    downloadReceiptImage(canvas, 'chinemerem-foods-order-receipt.png');
                });
            } else {
                // Fallback for browsers without Web Share API: download the image
                downloadReceiptImage(canvas, 'chinemerem-foods-order-receipt.png');
            }
        }, 'image/png');
    }).catch(function(error){
        console.error('Failed to capture receipt:', error);
        alert('Failed to capture receipt image. Please try again.');
    });
}

function downloadReceiptImage(canvas, filename) {
    var link = document.createElement('a');
    link.download = filename;
    link.href = canvas.toDataURL('image/png');
    link.click();
}

// Ensure order modal is visible on page load
document.addEventListener('DOMContentLoaded', function() {
    var orderModal = document.getElementById('order-modal');
    if (orderModal) {
        orderModal.style.display = 'flex';
    }
});
</script>
<?php endif; ?>

<?php if ($payment_receipt) : ?>
<div class="modal" id="pay-modal">
<div class="modal-content">
<div class="modal-header payment"><h3><i class="fas fa-receipt"></i> Payment Receipt</h3><button class="modal-close" onclick="closePayModal()">&times;</button></div>
<div class="modal-body" id="pay-print-area">
<div class="receipt-company">
    <h2>Chinemerem Foods</h2>
    <p>Inventory Management System</p>
</div>
<div class="receipt-divider"></div>
<div class="receipt-info">
<p><span>Receipt #:</span><strong><?php echo esc_html($payment_receipt['receipt_number']); ?></strong></p>
<p><span>Date:</span><?php echo esc_html($payment_receipt['date']); ?></p>
<p><span>Time:</span><?php echo esc_html($payment_receipt['time']); ?></p>
<p><span>Debtor:</span><strong style="color:#16a34a"><?php echo esc_html($payment_receipt['debtor_name']); ?></strong></p>
<p><span>Staff:</span><?php echo esc_html($payment_receipt['staff']); ?></p>
</div>
<div class="receipt-divider"></div>
<div class="receipt-totals">
    <p><span>Balance Before:</span><span class="receipt-amount" style="color:#dc2626">₦<?php echo number_format($payment_receipt['balance_before'], 0); ?></span></p>
    <p class="grand"><span>Payment Amount:</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['payment_amount'], 0); ?></span></p>
    <?php if ($payment_receipt['transfer_amount'] > 0) : ?>
    <p><span>Transfer:</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['transfer_amount'], 0); ?></span></p>
    <?php endif; ?>
    <?php if ($payment_receipt['cash_amount'] > 0) : ?>
    <p><span>Cash:</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['cash_amount'], 0); ?></span></p>
    <?php endif; ?>
    <?php if ($payment_receipt['home_amount'] > 0) : ?>
    <p><span>Home Calculation:</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['home_amount'], 0); ?></span></p>
    <?php endif; ?>
    <?php if (!empty($payment_receipt['bank_name']) && $payment_receipt['transfer_amount'] > 0) : ?>
    <p><span>Bank:</span><span><?php echo esc_html($payment_receipt['bank_name']); ?></span></p>
    <?php endif; ?>
</div>
<div class="receipt-divider"></div>
<div class="receipt-info">
    <p><span>New Balance:</span><span class="receipt-amount" style="color:<?php echo $payment_receipt['new_balance'] > 0 ? '#dc2626' : '#16a34a'; ?>">₦<?php echo number_format($payment_receipt['new_balance'], 0); ?></span></p>
    <?php if (!empty($payment_receipt['overpayment'])) : ?>
    <p><span>Overpayment Credit:</span><span class="receipt-amount" style="color:#16a34a">₦<?php echo number_format($payment_receipt['overpayment'], 0); ?></span></p>
    <?php endif; ?>
</div>
<div class="receipt-footer">
    <p>Payment received with thanks</p>
    <p>Powered by BendlessTech</p>
</div>
</div>
<div class="modal-footer">
<button type="button" onclick="sendPayReceipt()" class="btn" style="background:#001943;color:#fff"><i class="fab fa-whatsapp"></i> Send to WhatsApp</button>
<button onclick="printPayReceipt()" class="btn" style="background:#7c3aed;color:#fff"><i class="fas fa-print"></i> Print</button>
<button onclick="closePayModal()" class="btn btn-success"><i class="fas fa-check"></i> Done</button>
</div>
</div>
</div>
<script>
async function printPayReceipt(){
// Try Bluetooth printing first
if ('bluetooth' in navigator) {
    var text = '';
    var line = '--------------------------------';
    text += '       CHINEMEREM FOODS\n';
    text += '     Debt Payment Receipt\n';
    text += line + '\n';
    text += 'Receipt: <?php echo esc_js($payment_receipt['receipt_number']); ?>\n';
    text += 'Date: <?php echo esc_js($payment_receipt['date']); ?>\n';
    text += 'Time: <?php echo esc_js($payment_receipt['time']); ?>\n';
    text += 'Debtor: <?php echo esc_js($payment_receipt['debtor_name']); ?>\n';
    text += 'Staff: <?php echo esc_js($payment_receipt['staff']); ?>\n';
    text += line + '\n';
    text += 'Balance Before:    N<?php echo str_pad(number_format($payment_receipt['balance_before'], 0), 9, ' ', STR_PAD_LEFT); ?>\n';
    text += line + '\n';
    text += 'PAYMENT AMOUNT:    N<?php echo str_pad(number_format($payment_receipt['payment_amount'], 0), 9, ' ', STR_PAD_LEFT); ?>\n';
    <?php if ($payment_receipt['transfer_amount'] > 0) : ?>text += '  - Transfer:      N<?php echo str_pad(number_format($payment_receipt['transfer_amount'], 0), 9, ' ', STR_PAD_LEFT); ?>\n';<?php endif; ?>
    <?php if ($payment_receipt['cash_amount'] > 0) : ?>text += '  - Cash:          N<?php echo str_pad(number_format($payment_receipt['cash_amount'], 0), 9, ' ', STR_PAD_LEFT); ?>\n';<?php endif; ?>
    <?php if ($payment_receipt['home_amount'] > 0) : ?>text += '  - Home Calc:     N<?php echo str_pad(number_format($payment_receipt['home_amount'], 0), 9, ' ', STR_PAD_LEFT); ?>\n';<?php endif; ?>
    text += line + '\n';
    text += 'NEW BALANCE:       N<?php echo str_pad(number_format($payment_receipt['new_balance'], 0), 9, ' ', STR_PAD_LEFT); ?>\n';
    text += line + '\n';
    text += '  Payment received with thanks!\n';
    text += '     Powered by BendlessTech\n';
    text += '\n\n\n';
    
    var printed = await printToBluetoothPrinter(text);
    if (printed) {
        return;
    }
}

// Fallback to browser print - 80mm thermal format matching take-order
var printWindow = window.open('', '_blank', 'width=400,height=700');
if (!printWindow) {
    alert('Please allow popups to print receipts');
    return;
}

printWindow.document.write('<!DOCTYPE html><html><head><title>Print Receipt</title>');
printWindow.document.write('<style>');
printWindow.document.write('@page{size:80mm auto;margin:0}');
printWindow.document.write('*{margin:0;padding:0;box-sizing:border-box}');
printWindow.document.write('html,body{width:80mm!important;max-width:80mm!important;margin:0!important;padding:0!important}');
printWindow.document.write('body{font-family:"Courier New",Courier,monospace;font-size:12px;line-height:1.4;color:#000;background:#fff}');
printWindow.document.write('.receipt{width:80mm;padding:2mm;box-sizing:border-box}');
printWindow.document.write('.header{text-align:center;margin-bottom:6px}');
printWindow.document.write('.header h2{font-size:16px;font-weight:800;margin:0 0 4px;letter-spacing:0.5px;text-transform:uppercase}');
printWindow.document.write('.header p{font-size:11px;margin:0}');
printWindow.document.write('.divider{border-top:1px solid #000;margin:6px 0}');
printWindow.document.write('.info-row{display:flex;justify-content:space-between;margin:4px 0;font-size:12px}');
printWindow.document.write('.info-row .value{font-weight:700}');
printWindow.document.write('.receipt-amount{font-weight:800}');
printWindow.document.write('.totals p{display:flex;justify-content:space-between;margin:4px 0;font-size:12px}');
printWindow.document.write('.totals .grand{font-size:13px;font-weight:700}');
printWindow.document.write('.footer{text-align:center;margin-top:6px;font-size:10px}');
printWindow.document.write('.footer .thanks{font-weight:700;font-size:11px}');
printWindow.document.write('@media print{html,body{width:100%!important}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}');
printWindow.document.write('</style></head><body>');

var h = '<div class="receipt">';
h += '<div class="header"><h2>CHINEMEREM FOODS</h2><p>DEBT PAYMENT RECEIPT</p></div>';
h += '<div class="divider"></div>';
h += '<div class="info">';
h += '<div class="info-row"><span class="label">Receipt #:</span><span class="value"><?php echo esc_js($payment_receipt['receipt_number']); ?></span></div>';
h += '<div class="info-row"><span class="label">Date:</span><span class="value"><?php echo esc_js($payment_receipt['date']); ?></span></div>';
h += '<div class="info-row"><span class="label">Time:</span><span class="value"><?php echo esc_js($payment_receipt['time']); ?></span></div>';
h += '<div class="info-row"><span class="label">Debtor:</span><span class="value"><?php echo esc_js($payment_receipt['debtor_name']); ?></span></div>';
h += '<div class="info-row"><span class="label">Staff:</span><span class="value"><?php echo esc_js($payment_receipt['staff']); ?></span></div>';
h += '</div>';
h += '<div class="divider"></div>';
h += '<div class="totals">';
h += '<p><span>Balance Before:</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['balance_before'], 0); ?></span></p>';
h += '<p class="grand"><span>Payment Amount:</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['payment_amount'], 0); ?></span></p>';
<?php if ($payment_receipt['transfer_amount'] > 0) : ?>
h += '<p><span>  - Transfer (<?php echo esc_js($payment_receipt['bank_name']); ?>):</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['transfer_amount'], 0); ?></span></p>';
<?php endif; ?>
<?php if ($payment_receipt['cash_amount'] > 0) : ?>
h += '<p><span>  - Cash:</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['cash_amount'], 0); ?></span></p>';
<?php endif; ?>
<?php if ($payment_receipt['home_amount'] > 0) : ?>
h += '<p><span>  - Home Calc:</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['home_amount'], 0); ?></span></p>';
<?php endif; ?>
h += '<p class="grand"><span>New Balance:</span><span class="receipt-amount">₦<?php echo number_format($payment_receipt['new_balance'], 0); ?></span></p>';
h += '</div>';
h += '<div class="divider"></div>';
h += '<div class="footer"><p class="thanks">Payment received with thanks!</p><p>Powered by BendlessTech</p></div>';
h += '</div>';

printWindow.document.write(h);
printWindow.document.write('</body></html>');
printWindow.document.close();
printWindow.onload = function() {
    setTimeout(function() { printWindow.print(); }, 300);
};
}

function sendPayReceipt(){
    var receiptText = buildPaymentReceiptText();
    if (!receiptText) {
        alert('Unable to build receipt message');
        return;
    }
    sharePaymentReceiptImage('pay-print-area', receiptText);
}

function sharePaymentReceiptImage(elementId, receiptText){
    var receiptNode = document.getElementById(elementId);
    if (!receiptNode) {
        alert('Receipt image not available.');
        return;
    }
    html2canvas(receiptNode, { backgroundColor: '#ffffff', scale: 1.5, logging: false }).then(function(canvas) {
        canvas.toBlob(function(blob) {
            if (!blob) {
                alert('Receipt image could not be created.');
                return;
            }
            var file = new File([blob], 'chinemerem-foods-receipt.png', { type: 'image/png' });
            if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                navigator.share({
                    title: 'Chinemerem Foods Receipt',
                    text: receiptText,
                    files: [file]
                }).catch(function(error){
                    console.warn('Share cancelled or failed', error);
                    // Fallback: download the image
                    downloadPaymentReceiptImage(canvas, 'chinemerem-foods-payment-receipt.png');
                });
            } else {
                // Fallback for browsers without Web Share API: download the image
                downloadPaymentReceiptImage(canvas, 'chinemerem-foods-payment-receipt.png');
            }
        }, 'image/png');
    }).catch(function(error){
        console.error('Failed to capture receipt:', error);
        alert('Failed to capture receipt image. Please try again.');
    });
}

function downloadPaymentReceiptImage(canvas, filename) {
    var link = document.createElement('a');
    link.download = filename;
    link.href = canvas.toDataURL('image/png');
    link.click();
}

function buildPaymentReceiptText(){
    var lines = [];
    lines.push('CHINEMEREM FOODS');
    lines.push('Debt Payment Receipt');
    lines.push('Receipt: <?php echo esc_js($payment_receipt['receipt_number']); ?>');
    lines.push('Date: <?php echo esc_js($payment_receipt['date']); ?>');
    lines.push('Time: <?php echo esc_js($payment_receipt['time']); ?>');
    lines.push('Debtor: <?php echo esc_js($payment_receipt['debtor_name']); ?>');
    lines.push('Amount Paid: ₦<?php echo esc_js(number_format($payment_receipt['payment_amount'], 0)); ?>');
    <?php if ($payment_receipt['transfer_amount'] > 0) : ?>
    lines.push('Transfer: ₦<?php echo esc_js(number_format($payment_receipt['transfer_amount'], 0)); ?>');
    lines.push('Bank: <?php echo esc_js($payment_receipt['bank_name']); ?>');
    <?php endif; ?>
    <?php if ($payment_receipt['cash_amount'] > 0) : ?>
    lines.push('Cash: ₦<?php echo esc_js(number_format($payment_receipt['cash_amount'], 0)); ?>');
    <?php endif; ?>
    <?php if ($payment_receipt['home_amount'] > 0) : ?>
    lines.push('Home Calc: ₦<?php echo esc_js(number_format($payment_receipt['home_amount'], 0)); ?>');
    <?php endif; ?>
    lines.push('New Balance: ₦<?php echo esc_js(number_format($payment_receipt['new_balance'], 0)); ?>');
    <?php if (!empty($payment_receipt['overpayment'])) : ?>
    lines.push('Overpayment Credit: ₦<?php echo esc_js(number_format($payment_receipt['overpayment'], 0)); ?>');
    <?php endif; ?>
    lines.push('Powered by BendlessTech');
    return lines.join('\\n');
}
function closePayModal(){document.getElementById('pay-modal').style.display='none';window.location.href='<?php echo esc_url($debtor_record_done_url); ?>'}

// Ensure payment modal is visible on page load
document.addEventListener('DOMContentLoaded', function() {
    var payModal = document.getElementById('pay-modal');
    if (payModal) {
        payModal.style.display = 'flex';
    }
});
</script>
<?php endif; ?>

<script>
var cfiDebtorAjaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
var cfiDebtorNonce = '<?php echo wp_create_nonce('cfi_nonce'); ?>';
var cfiSelectedDebtorId = <?php echo $selected_debtor ? (int) $selected_debtor->id : 'null'; ?>;
var cfiDebtorHasPhone = <?php echo isset($debtor) && !empty($debtor->phone) ? 'true' : 'false'; ?>;
var cfiCurrencySymbol = '₦';
var cfiLocale = (typeof Intl !== 'undefined' && Intl.NumberFormat && Intl.NumberFormat.supportedLocalesOf(['en-NG']).length)
    ? 'en-NG'
    : 'en-US';
var cfiDebtorCardBalances = null;
var cfiDebtorBalanceFields = null;
var cfiDebtorLoading = true;

function cfiSetDebtorLoading(isLoading) {
    if (!document.body) {
        return;
    }
    if (isLoading) {
        document.body.classList.add('cfi-debtor-loading');
    } else {
        document.body.classList.remove('cfi-debtor-loading');
    }
}

function cfiFormatDebt(value) {
    var amount = parseFloat(value) || 0;
    if (amount.toLocaleString && typeof Intl !== 'undefined' && Intl.NumberFormat) {
        return cfiCurrencySymbol + amount.toLocaleString(cfiLocale, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    return cfiCurrencySymbol + amount.toLocaleString('en-NG', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function cfiUpdateSelectedDebt(balanceValue) {
    if (!window.cfiDebtorState) {
        return;
    }
    window.cfiDebtorState.debt = balanceValue;
    if (typeof debt !== 'undefined') {
        debt = balanceValue;
    }
    if (typeof updatePayTotal === 'function') {
        updatePayTotal();
    }
}

function cfiApplyDebtorBalances(balances) {
    if (!cfiDebtorCardBalances) {
        cfiDebtorCardBalances = document.querySelectorAll('.card[data-debtor-id] .card-balance');
    }
    cfiDebtorCardBalances.forEach(function(balanceEl) {
        var card = balanceEl.closest('.card[data-debtor-id]');
        if (!card) {
            return;
        }
        var debtorId = card.getAttribute('data-debtor-id');
        if (!debtorId || balances[debtorId] === undefined) {
            return;
        }
        var balanceValue = parseFloat(balances[debtorId]) || 0;
        balanceEl.textContent = cfiFormatDebt(balanceValue);
        if (balanceValue <= 0) {
            balanceEl.classList.add('zero');
        } else {
            balanceEl.classList.remove('zero');
        }
    });
    if (!cfiDebtorBalanceFields) {
        cfiDebtorBalanceFields = document.querySelectorAll('.cfi-debtor-balance[data-debtor-id]');
    }
    cfiDebtorBalanceFields.forEach(function(el) {
        var debtorId = el.getAttribute('data-debtor-id');
        if (!debtorId || balances[debtorId] === undefined) {
            return;
        }
        var balanceValue = parseFloat(balances[debtorId]) || 0;
        el.textContent = cfiFormatDebt(balanceValue);
        if (cfiSelectedDebtorId && Number(debtorId) === Number(cfiSelectedDebtorId)) {
            cfiUpdateSelectedDebt(balanceValue);
        }
    });
}

function cfiRefreshDebtorBalances() {
    if (cfiDebtorLoading) {
        cfiSetDebtorLoading(true);
    }
    var formData = new FormData();
    formData.append('action', 'cfi_get_debtor_balances');
    formData.append('nonce', cfiDebtorNonce);
    fetch(cfiDebtorAjaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        cache: 'no-cache'
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data && data.success && data.data && data.data.balances) {
            cfiApplyDebtorBalances(data.data.balances);
        }
    })
    .catch(function(error) {
        if (window.console && console.warn) {
            console.warn('CFI debtor balance refresh failed', error);
        }
        return null;
    })
    .finally(function() {
        cfiDebtorLoading = false;
        cfiSetDebtorLoading(false);
    });
}

cfiSetDebtorLoading(true);
cfiRefreshDebtorBalances();
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        cfiRefreshDebtorBalances();
    }
});

// Bluetooth thermal printer support
var bluetoothDevice = null;
var printerCharacteristic = null;

async function connectBluetoothPrinter() {
    try {
        bluetoothDevice = await navigator.bluetooth.requestDevice({
            acceptAllDevices: true,
            optionalServices: ['000018f0-0000-1000-8000-00805f9b34fb', '49535343-fe7d-4ae5-8fa9-9fafd205e455', 'e7810a71-73ae-499d-8c15-faa9aef0c3f2']
        });
        const server = await bluetoothDevice.gatt.connect();
        const serviceUUIDs = ['000018f0-0000-1000-8000-00805f9b34fb', '49535343-fe7d-4ae5-8fa9-9fafd205e455', 'e7810a71-73ae-499d-8c15-faa9aef0c3f2'];
        for (let uuid of serviceUUIDs) {
            try {
                const service = await server.getPrimaryService(uuid);
                const characteristics = await service.getCharacteristics();
                for (let char of characteristics) {
                    if (char.properties.write || char.properties.writeWithoutResponse) {
                        printerCharacteristic = char;
                        return true;
                    }
                }
            } catch (e) { continue; }
        }
        const services = await server.getPrimaryServices();
        for (let service of services) {
            const chars = await service.getCharacteristics();
            for (let char of chars) {
                if (char.properties.write || char.properties.writeWithoutResponse) {
                    printerCharacteristic = char;
                    return true;
                }
            }
        }
        throw new Error('No writable characteristic found');
    } catch (error) {
        console.error('Bluetooth connection failed:', error);
        return false;
    }
}

async function printToBluetoothPrinter(text) {
    if (!printerCharacteristic) {
        const connected = await connectBluetoothPrinter();
        if (!connected) return false;
    }
    try {
        const encoder = new TextEncoder();
        await printerCharacteristic.writeValue(new Uint8Array([0x1B, 0x40]));
        const textData = encoder.encode(text);
        for (let i = 0; i < textData.length; i += 100) {
            await printerCharacteristic.writeValue(textData.slice(i, i + 100));
            await new Promise(r => setTimeout(r, 50));
        }
        await printerCharacteristic.writeValue(new Uint8Array([0x0A, 0x0A, 0x0A, 0x1D, 0x56, 0x00]));
        return true;
    } catch (error) {
        console.error('Print failed:', error);
        printerCharacteristic = null;
        return false;
    }
}

// Prevent form resubmission on back button - but do NOT auto-reload
if(window.history.replaceState)window.history.replaceState(null,null,window.location.href);

window.addEventListener('pageshow', function(event) {
    var navEntry = null;
    if (window.performance && typeof window.performance.getEntriesByType === 'function') {
        navEntry = window.performance.getEntriesByType('navigation')[0];
    }
    // Some browsers report back_forward when restoring from bfcache.
    var isBackForward = navEntry && navEntry.type === 'back_forward';
    if (event.persisted || isBackForward) {
        cfiDebtorLoading = true;
        cfiSetDebtorLoading(true);
        cfiRefreshDebtorBalances();
    }
});
</script>
</body>
</html>