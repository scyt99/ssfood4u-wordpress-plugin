<?php
/*
Plugin Name: ssfood4u main
Description: Adds QR code and Payment Verification with Enhanced OCR
Version: 1.4
Author: Steven
*/

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('SSFOOD4U_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSFOOD4U_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SSFOOD4U_PLUGIN_VERSION', '1.4');

// Load all PHP modules from /includes
foreach (glob(plugin_dir_path(__FILE__) . 'includes/*.php') as $file) {
    require_once $file;
}

// === SINGLE UNIFIED VALIDATION SYSTEM ===
class SSFood4U_Unified_System {
    
    private static $instance = null;
    private $debug_logger = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize debug logger if available
        if (class_exists('SSFood4U_Debug_Logger')) {
            $this->debug_logger = SSFood4U_Debug_Logger::get_instance();
        }
        
        add_action('wp_footer', array($this, 'render_unified_system'));
    }
    
    private function debug($message) {
        if ($this->debug_logger) {
            $this->debug_logger->log($message);
        }
    }
    
    public function render_unified_system() {
        if (is_checkout()) {
            ?>
            <script>
            jQuery(document).ready(function($) {
                // Prevent multiple initializations
                if (window.SSFood4U_Unified_Loaded) {
                    return;
                }
                window.SSFood4U_Unified_Loaded = true;
                
                // Global state management
                let isTyping = false;
                let validationTimeout = null;
                let lastValidatedAddress = '';
                
                // Initialize payment verification flag
                window.SSFood4U_PaymentVerified = window.location.href.includes('payment_uploaded=1');
                
                // === CORE FUNCTIONS ===
                
                function showRM1180Warning() {
                    $('.ssfood4u-warning').remove();
                    
                    const warning = $('<div class="ssfood4u-warning" style="' +
                        'background: #ffebee; border: 2px solid #f44336; color: #d32f2f; ' +
                        'padding: 15px; margin: 15px 0; border-radius: 4px; font-weight: bold; ' +
                        'text-align: center; font-size: 14px;">' +
                        '⚠️ Sorry, currently we do not provide delivery to this location yet.' +
                        '</div>');
                    
                    if ($('.woocommerce-checkout-review-order').length) {
                        $('.woocommerce-checkout-review-order').prepend(warning);
                    } else if ($('#order_review').length) {
                        $('#order_review').prepend(warning);
                    }
                    
                    // Disable place order
                    $('#place_order').prop('disabled', true).css({
                        'opacity': '0.5',
                        'background-color': '#ccc'
                    });
                }
                
                function hideRM1180Warning() {
                    $('.ssfood4u-warning').remove();
                    
                    // Re-enable place order only if payment is verified
                    if (window.SSFood4U_PaymentVerified) {
                        $('#place_order').prop('disabled', false).css({
                            'opacity': '1',
                            'background-color': ''
                        });
                    }
                }
                
                function checkForRM1180() {
                    let found = false;
                    
                    // Check shipping cost elements
                    const selectors = [
                        '.shipping .woocommerce-Price-amount',
                        '.cart-subtotal + tr .woocommerce-Price-amount',
                        'tr.shipping .amount'
                    ];
                    
                    for (let selector of selectors) {
                        $(selector).each(function() {
                            const text = $(this).text().trim();
                            const cost = parseFloat(text.replace(/[^\d.]/g, ''));
                            if (cost === 11.8 || cost === 11.80) {
                                found = true;
                                return false;
                            }
                        });
                        if (found) break;
                    }
                    
                    // Content scan as backup
                    if (!found) {
                        $('.woocommerce-checkout-review-order *').each(function() {
                            if ($(this).text().includes('11.80')) {
                                found = true;
                                return false;
                            }
                        });
                    }
                    
                    return found;
                }
                
                function performValidation(address) {
                    if (!address || address.length < 3) return;
                    
                    // Prevent duplicate validations
                    if (address === lastValidatedAddress) return;
                    lastValidatedAddress = address;
                    
                    // Wait for shipping calculation, then check
                    setTimeout(function() {
                        if (checkForRM1180()) {
                            showRM1180Warning();
                        } else {
                            hideRM1180Warning();
                        }
                    }, 3000);
                }
                
                // === EVENT HANDLING ===
                
                function bindAddressEvents() {
                    // Remove any existing bindings to prevent duplicates
                    $('#billing_address_1').off('.ssfood4u');
                    
                    $('#billing_address_1').on('input.ssfood4u', function() {
                        isTyping = true;
                        hideRM1180Warning(); // Hide warning immediately when typing
                        
                        // Clear existing timeout
                        if (validationTimeout) {
                            clearTimeout(validationTimeout);
                        }
                        
                        // Set new timeout
                        validationTimeout = setTimeout(function() {
                            isTyping = false;
                            performValidation($('#billing_address_1').val().trim());
                        }, 2000); // Wait 2 seconds after typing stops
                    });
                    
                    // Also bind to checkout updates
                    $(document.body).off('updated_checkout.ssfood4u').on('updated_checkout.ssfood4u', function() {
                        if (!isTyping) {
                            const address = $('#billing_address_1').val().trim();
                            if (address.length > 3) {
                                setTimeout(function() {
                                    performValidation(address);
                                }, 1000);
                            }
                        }
                    });
                }
                
                // === INITIALIZATION ===
                
                // Wait for page to fully load
                setTimeout(function() {
                    bindAddressEvents();
                }, 1000);
                
                // Re-bind events periodically to counter other plugins
                setInterval(function() {
                    if (!isTyping) {
                        bindAddressEvents();
                    }
                }, 10000); // Every 10 seconds
            });
            </script>
            <?php
        }
    }
}

// === ENHANCED OCR INTEGRATION ===
function ssfood4u_integrate_enhanced_ocr_validation() {
    add_filter('ssfood4u_before_save_payment', 'ssfood4u_process_enhanced_ocr_validation', 10, 3);
}

function ssfood4u_process_enhanced_ocr_validation($payment_data, $receipt_file_path, $expected_amount) {
    // Skip if no receipt file
    if (empty($receipt_file_path) || !file_exists($receipt_file_path)) {
        return $payment_data;
    }
    
    // Check if enhanced OCR validator exists
    if (!class_exists('SSFood4U_Enhanced_OCR_Validator')) {
        return $payment_data;
    }
    
    $ocr_validator = new SSFood4U_Enhanced_OCR_Validator();
    $transaction_id = $payment_data['transaction_id'] ?? '';
    
    $ocr_result = $ocr_validator->validate_receipt_amount($receipt_file_path, $expected_amount, $transaction_id);
    
    // Store enhanced OCR results
    $payment_data['ocr_validation'] = $ocr_result['validation'];
    $payment_data['ocr_confidence'] = $ocr_result['confidence'];
    $payment_data['ocr_message'] = $ocr_result['message'];
    $payment_data['ocr_amounts_found'] = isset($ocr_result['amounts_found']) ? 
        implode(',', $ocr_result['amounts_found']) : '';
    $payment_data['ocr_metadata'] = isset($ocr_result['receipt_metadata']) ? 
        json_encode($ocr_result['receipt_metadata']) : '';
    
    // Auto-approve high-confidence matches
    $auto_approve_threshold = get_option('ssfood4u_ocr_auto_approve', 85);
    if ($auto_approve_threshold > 0 && $ocr_result['confidence'] >= $auto_approve_threshold) {
        $payment_data['verification_status'] = 'verified';
        $payment_data['auto_approved_by_ocr'] = true;
    }
    
    return $payment_data;
}

// Initialize enhanced OCR system
add_action('init', 'ssfood4u_integrate_enhanced_ocr_validation');

// Initialize ONLY the unified system
SSFood4U_Unified_System::get_instance();

// Initialize other systems ONLY if they exist and are needed
if (class_exists('SSFood4U_Payment_Verification')) {
    new SSFood4U_Payment_Verification();
}

if (class_exists('SSFood4U_Enhanced_OCR_Validator')) {
    new SSFood4U_Enhanced_OCR_Validator();
}

// === ADMIN MENU SETUP ===
add_action('admin_menu', function() {
    add_menu_page(
        'SSFood4U Settings',
        'SSFood4U',
        'manage_options',
        'ssfood4u-main',
        'ssfood4u_main_admin_page',
        'dashicons-store',
        30
    );
    
    if (class_exists('SSFood4U_Hotel_Translation_DB')) {
        global $ssfood4u_hotel_db;
        add_submenu_page(
            'ssfood4u-main',
            'Hotel Translations',
            'Hotel Database',
            'manage_options',
            'ssfood4u-hotels',
            array($ssfood4u_hotel_db, 'render_admin_page')
        );
    }
    
    if (class_exists('SSFood4U_Admin_Panel')) {
        add_submenu_page(
            'ssfood4u-main',
            'Payment Verification',
            'Payments',
            'manage_options',
            'ssfood4u-payments',
            array(new SSFood4U_Admin_Panel(), 'render_payments_page')
        );
    }
    
    add_submenu_page(
        'ssfood4u-main',
        'General Settings',
        'Settings',
        'manage_options',
        'ssfood4u-settings',
        'ssfood4u_settings_admin_page'
    );
});

// Main admin page
function ssfood4u_main_admin_page() {
    $hotel_count = 0;
    if (class_exists('SSFood4U_Hotel_Translation_DB')) {
        global $ssfood4u_hotel_db;
        $hotel_count = $ssfood4u_hotel_db->count_hotels();
    }
    
    ?>
    <div class="wrap">
        <h1>SSFood4U Management System</h1>
        
        <div class="card">
            <h2>System Overview</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Plugin Version</th>
                    <td><?php echo SSFOOD4U_PLUGIN_VERSION; ?></td>
                </tr>
                <tr>
                    <th scope="row">Hotel Database</th>
                    <td><?php echo $hotel_count; ?> hotels loaded</td>
                </tr>
                <tr>
                    <th scope="row">Unified Validation</th>
                    <td><span style="color: green;">Active</span> - Single system approach</td>
                </tr>
                <tr>
                    <th scope="row">Payment Verification</th>
                    <td><span style="color: green;">Active</span> - OCR validation enabled</td>
                </tr>
                <tr>
                    <th scope="row">Debug Logging</th>
                    <td>
                        <?php if (class_exists('SSFood4U_Debug_Logger')): ?>
                            <span style="color: green;">Available</span> - 
                            <a href="<?php echo admin_url('admin.php?page=ssfood4u-debug'); ?>">Debug Controls</a>
                        <?php else: ?>
                            <span style="color: orange;">Inactive</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2>Quick Actions</h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-hotels'); ?>" class="button button-primary">Manage Hotels</a>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-payments'); ?>" class="button">View Payments</a>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-settings'); ?>" class="button">Settings</a>
            </p>
        </div>
    </div>
    <?php
}

// Settings admin page
function ssfood4u_settings_admin_page() {
    if (isset($_POST['submit'])) {
        update_option('ssfood4u_bank_name', sanitize_text_field($_POST['bank_name']));
        update_option('ssfood4u_account_number', sanitize_text_field($_POST['account_number']));
        update_option('ssfood4u_account_holder', sanitize_text_field($_POST['account_holder']));
        update_option('ssfood4u_whatsapp', sanitize_text_field($_POST['whatsapp']));
        
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    $bank_name = get_option('ssfood4u_bank_name', 'Update with your bank name');
    $account_number = get_option('ssfood4u_account_number', 'Update with your account number');
    $account_holder = get_option('ssfood4u_account_holder', 'Update with account holder name');
    $whatsapp = get_option('ssfood4u_whatsapp', '60123456789');
    
    ?>
    <div class="wrap">
        <h1>SSFood4U Settings</h1>
        
        <form method="post" action="">
            <?php wp_nonce_field('ssfood4u_settings', 'ssfood4u_settings_nonce'); ?>
            
            <div class="card">
                <h2>Payment Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Bank Name</th>
                        <td><input type="text" name="bank_name" value="<?php echo esc_attr($bank_name); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Account Number</th>
                        <td><input type="text" name="account_number" value="<?php echo esc_attr($account_number); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Account Holder Name</th>
                        <td><input type="text" name="account_holder" value="<?php echo esc_attr($account_holder); ?>" class="regular-text" /></td>
                    </tr>
                </table>
            </div>
            
            <div class="card">
                <h2>Contact Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">WhatsApp Number</th>
                        <td>
                            <input type="text" name="whatsapp" value="<?php echo esc_attr($whatsapp); ?>" class="regular-text" />
                            <p class="description">Format: 60123456789 (with country code, no + or spaces)</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Save Settings" />
            </p>
        </form>
    </div>
    <?php
}

// Initialize admin panel if class exists
if (class_exists('SSFood4U_Admin_Panel')) {
    $admin_panel = new SSFood4U_Admin_Panel();
}
?>