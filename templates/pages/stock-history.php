<?php
/**
 * Stock History Page Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$is_super_admin = CFI_Auth::is_super_admin();
?>
<style>
/* Stock History Table - Ensure all columns display */
#cfi-stock-history .cfi-table-wrapper {
    overflow-x: auto !important;
    width: 100%;
    -webkit-overflow-scrolling: touch;
}
#cfi-history-table {
    min-width: 1100px;
    table-layout: auto;
}
#cfi-history-table th,
#cfi-history-table td {
    white-space: nowrap;
    padding: 0.5rem 0.6rem !important;
    min-width: 70px;
}
#cfi-history-table th:first-child,
#cfi-history-table td:first-child {
    min-width: 90px;
}
#cfi-history-table th:nth-child(2),
#cfi-history-table td:nth-child(2) {
    min-width: 100px;
}
/* Editable input styles */
#cfi-history-table input.editable-field {
    width: 60px;
    padding: 0.25rem 0.4rem;
    font-size: 0.75rem;
    text-align: right;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    background: #fff;
}
#cfi-history-table input.editable-field:focus {
    border-color: #3b82f6;
    outline: none;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
}
.btn-save-row {
    background: #10b981 !important;
    color: white !important;
    border: none;
    padding: 0.3rem 0.5rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.7rem;
}
.btn-save-row:hover {
    background: #059669 !important;
}
.btn-save-row:disabled {
    background: #94a3b8 !important;
    cursor: not-allowed;
}
</style>
<main class="cfi-main">
    <div class="cfi-container">
        <div class="cfi-page-title">
            <h1>
                <i class="fas fa-history"></i>
                <?php esc_html_e('Stock History', 'chinemerem-foods'); ?>
            </h1>
            <div class="cfi-page-actions">
                <?php $stock_record = get_page_by_path('cfi-stock-record'); ?>
                <?php if ($stock_record) : ?>
                <a href="<?php echo esc_url(get_permalink($stock_record->ID)); ?>" class="cfi-btn cfi-btn-primary cfi-btn-sm">
                    <i class="fas fa-boxes"></i>
                    <?php esc_html_e('Current Stock', 'chinemerem-foods'); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="cfi-filters cfi-glass">
            <div class="cfi-filter-group">
                <label for="cfi-history-start"><?php esc_html_e('From:', 'chinemerem-foods'); ?></label>
                <input type="date" id="cfi-history-start" class="cfi-input" value="<?php echo esc_attr(date('Y-m-d', strtotime('-7 days'))); ?>">
            </div>
            <div class="cfi-filter-group">
                <label for="cfi-history-end"><?php esc_html_e('To:', 'chinemerem-foods'); ?></label>
                <input type="date" id="cfi-history-end" class="cfi-input" value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
            </div>
            <div class="cfi-filter-group">
                <label for="cfi-product-filter"><?php esc_html_e('Product:', 'chinemerem-foods'); ?></label>
                <select id="cfi-product-filter" class="cfi-select">
                    <option value=""><?php esc_html_e('All Products', 'chinemerem-foods'); ?></option>
                    <?php
                    $products = CFI_Products::get_all();
                    foreach ($products as $product) :
                    ?>
                    <option value="<?php echo esc_attr($product->id); ?>"><?php echo esc_html($product->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" id="cfi-load-history" class="cfi-btn cfi-btn-primary cfi-btn-sm">
                <i class="fas fa-search"></i>
                <?php esc_html_e('Search', 'chinemerem-foods'); ?>
            </button>
        </div>
        
        <div id="cfi-stock-history" class="cfi-glass">
            <div class="cfi-table-wrapper">
                <table id="cfi-history-table" class="cfi-table cfi-table-responsive">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('Product', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('Opening', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('Import', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('Cash Supply', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('Credit Supply', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('Not Supplied', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('Supplied Today', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('To Packing', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('From Packing', 'chinemerem-foods'); ?></th>
                            <th><?php esc_html_e('Closing', 'chinemerem-foods'); ?></th>
                            <?php if ($is_super_admin) : ?>
                            <th><?php esc_html_e('Actions', 'chinemerem-foods'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
jQuery(document).ready(function($) {
    const isSuperAdmin = <?php echo $is_super_admin ? 'true' : 'false'; ?>;
    
    function loadHistory() {
        const startDate = $('#cfi-history-start').val();
        const endDate = $('#cfi-history-end').val();
        const productId = $('#cfi-product-filter').val();
        
        CFI.ajax.request('get_stock_history', {
            start_date: startDate,
            end_date: endDate,
            product_id: productId
        }).then(function(data) {
            const tbody = $('#cfi-history-table tbody');
            tbody.empty();
            
            if (!data.history || data.history.length === 0) {
                const colspan = isSuperAdmin ? '12' : '11';
                tbody.append('<tr><td colspan="' + colspan + '" style="text-align: center;"><?php esc_html_e('No records found', 'chinemerem-foods'); ?></td></tr>');
                return;
            }
            
            data.history.forEach(function(record) {
                let row;
                if (isSuperAdmin) {
                    // Editable fields for super admin
                    row = `
                        <tr data-stock-id="${record.id}">
                            <td data-label="<?php esc_attr_e('Date', 'chinemerem-foods'); ?>">${record.record_date}</td>
                            <td data-label="<?php esc_attr_e('Product', 'chinemerem-foods'); ?>">${record.product_name}</td>
                            <td data-label="<?php esc_attr_e('Opening', 'chinemerem-foods'); ?>"><input type="number" class="editable-field" name="opening" value="${parseFloat(record.opening)}" step="1"></td>
                            <td data-label="<?php esc_attr_e('Import', 'chinemerem-foods'); ?>"><input type="number" class="editable-field" name="import_qty" value="${parseFloat(record.import_qty)}" step="1"></td>
                            <td data-label="<?php esc_attr_e('Cash Supply', 'chinemerem-foods'); ?>"><input type="number" class="editable-field" name="cash_supply" value="${parseFloat(record.cash_supply)}" step="1"></td>
                            <td data-label="<?php esc_attr_e('Credit Supply', 'chinemerem-foods'); ?>"><input type="number" class="editable-field" name="credit_supply" value="${parseFloat(record.credit_supply)}" step="1"></td>
                            <td data-label="<?php esc_attr_e('Not Supplied', 'chinemerem-foods'); ?>"><input type="number" class="editable-field" name="not_supplied" value="${parseFloat(record.not_supplied)}" step="1"></td>
                            <td data-label="<?php esc_attr_e('Supplied Today', 'chinemerem-foods'); ?>"><input type="number" class="editable-field" name="supplied_today" value="${parseFloat(record.supplied_today)}" step="1"></td>
                            <td data-label="<?php esc_attr_e('To Packing', 'chinemerem-foods'); ?>"><input type="number" class="editable-field" name="to_packing_store" value="${parseFloat(record.to_packing_store)}" step="1"></td>
                            <td data-label="<?php esc_attr_e('From Packing', 'chinemerem-foods'); ?>"><input type="number" class="editable-field" name="from_packing_store" value="${parseFloat(record.from_packing_store)}" step="1"></td>
                            <td data-label="<?php esc_attr_e('Closing', 'chinemerem-foods'); ?>" class="closing-cell">${CFI.utils.formatNumber(record.closing)}</td>
                            <td><button type="button" class="btn-save-row" onclick="saveStockRow(this)"><i class="fas fa-save"></i></button></td>
                        </tr>
                    `;
                } else {
                    // Read-only for non-super admin
                    row = `
                        <tr>
                            <td data-label="<?php esc_attr_e('Date', 'chinemerem-foods'); ?>">${record.record_date}</td>
                            <td data-label="<?php esc_attr_e('Product', 'chinemerem-foods'); ?>">${record.product_name}</td>
                            <td data-label="<?php esc_attr_e('Opening', 'chinemerem-foods'); ?>">${CFI.utils.formatNumber(record.opening)}</td>
                            <td data-label="<?php esc_attr_e('Import', 'chinemerem-foods'); ?>">${CFI.utils.formatNumber(record.import_qty)}</td>
                            <td data-label="<?php esc_attr_e('Cash Supply', 'chinemerem-foods'); ?>">${CFI.utils.formatNumber(record.cash_supply)}</td>
                            <td data-label="<?php esc_attr_e('Credit Supply', 'chinemerem-foods'); ?>">${CFI.utils.formatNumber(record.credit_supply)}</td>
                            <td data-label="<?php esc_attr_e('Not Supplied', 'chinemerem-foods'); ?>">${CFI.utils.formatNumber(record.not_supplied)}</td>
                            <td data-label="<?php esc_attr_e('Supplied Today', 'chinemerem-foods'); ?>">${CFI.utils.formatNumber(record.supplied_today)}</td>
                            <td data-label="<?php esc_attr_e('To Packing', 'chinemerem-foods'); ?>">${CFI.utils.formatNumber(record.to_packing_store)}</td>
                            <td data-label="<?php esc_attr_e('From Packing', 'chinemerem-foods'); ?>">${CFI.utils.formatNumber(record.from_packing_store)}</td>
                            <td data-label="<?php esc_attr_e('Closing', 'chinemerem-foods'); ?>">${CFI.utils.formatNumber(record.closing)}</td>
                        </tr>
                    `;
                }
                tbody.append(row);
            });
            
            // Add input change handler for auto-calculating closing
            if (isSuperAdmin) {
                $('#cfi-history-table .editable-field').on('input', function() {
                    const row = $(this).closest('tr');
                    calculateRowClosing(row);
                });
            }
        }).catch(function(error) {
            CFI.toast.error(error);
        });
    }
    
    function calculateRowClosing(row) {
        const opening = parseFloat(row.find('input[name="opening"]').val()) || 0;
        const import_qty = parseFloat(row.find('input[name="import_qty"]').val()) || 0;
        const cash_supply = parseFloat(row.find('input[name="cash_supply"]').val()) || 0;
        const credit_supply = parseFloat(row.find('input[name="credit_supply"]').val()) || 0;
        const not_supplied = parseFloat(row.find('input[name="not_supplied"]').val()) || 0;
        const supplied_today = parseFloat(row.find('input[name="supplied_today"]').val()) || 0;
        const to_packing = parseFloat(row.find('input[name="to_packing_store"]').val()) || 0;
        const from_packing = parseFloat(row.find('input[name="from_packing_store"]').val()) || 0;
        
        const closing = opening + import_qty - cash_supply - credit_supply + not_supplied - supplied_today - to_packing + from_packing;
        row.find('.closing-cell').text(CFI.utils.formatNumber(closing));
    }
    
    // Global function to save stock row
    window.saveStockRow = function(btn) {
        const row = $(btn).closest('tr');
        const stockId = row.data('stock-id');
        
        $(btn).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
        
        CFI.ajax.request('edit_stock_record', {
            stock_id: stockId,
            opening: row.find('input[name="opening"]').val(),
            import_qty: row.find('input[name="import_qty"]').val(),
            cash_supply: row.find('input[name="cash_supply"]').val(),
            credit_supply: row.find('input[name="credit_supply"]').val(),
            not_supplied: row.find('input[name="not_supplied"]').val(),
            supplied_today: row.find('input[name="supplied_today"]').val(),
            to_packing_store: row.find('input[name="to_packing_store"]').val(),
            from_packing_store: row.find('input[name="from_packing_store"]').val()
        }).then(function(data) {
            CFI.toast.success('<?php esc_html_e('Stock record updated!', 'chinemerem-foods'); ?>');
            $(btn).prop('disabled', false).html('<i class="fas fa-save"></i>');
        }).catch(function(error) {
            CFI.toast.error(error);
            $(btn).prop('disabled', false).html('<i class="fas fa-save"></i>');
        });
    };
    
    $('#cfi-load-history').on('click', function() {
        loadHistory();
    });
    
    loadHistory();
});
</script>