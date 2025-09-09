<?php
/*
Plugin Name: ssfood4u main
Description: Adds QR code and Payment Verification with Enhanced OCR
Version: 1.3
Author: Steven
*/

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('SSFOOD4U_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSFOOD4U_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SSFOOD4U_PLUGIN_VERSION', '1.3');

// Load all PHP modules from /includes
foreach (glob(plugin_dir_path(__FILE__) . 'includes/*.php') as $file) {
    require_once $file;
}

// === LANDMARK DEBUG FUNCTIONALITY ===
class SSFood4U_Landmark_Debug {
    
    public function __construct() {
        add_action('wp_footer', array($this, 'debug_geocoding_results'));
    }
    
    // Debug geocoding results
    public function debug_geocoding_results() {
        if (is_checkout()) {
            ?>
            <script>
            jQuery(document).ready(function($) {
                console.log('🚀 SSFood4U Landmark Debug Active - OVERRIDE MODE');
                
                // Our validation state
                let ourValidationActive = false;
                let userIsTyping = false;
                let validationInProgress = false;
                
                // Function to block delivery
                function blockDelivery(reason) {
                    console.log('🚫 BLOCKING DELIVERY:', reason);
                    $('#place_order').prop('disabled', true);
                    $('#place_order').text('Delivery Not Available');
                    $('#delivery-warning').remove();
                    $('.woocommerce-checkout-review-order').prepend(
                        '<div id="delivery-warning" style="background: #ffebee; border: 1px solid #f44336; color: #d32f2f; padding: 15px; margin: 15px 0; border-radius: 4px; font-weight: bold;">' +
                        'Delivery not available to this location' +
                        '</div>'
                    );
                }
                
                // Function to allow delivery
                function allowDelivery(reason) {
                    console.log('✅ ALLOWING DELIVERY:', reason);
                    
                    // Only enable if payment verification is complete
                    if (window.SSFood4U_PaymentVerified) {
                        $('#place_order').prop('disabled', false);
                        $('#place_order').text('Place Order');
                    } else {
                        $('#place_order').prop('disabled', true);
                        $('#place_order').text('Upload Payment Receipt First');
                    }
                    
                    $('#delivery-warning').remove();
                }
                
                // OVERRIDE: Block all external geocoding attempts
                function blockExternalGeocoding() {
                    // Override AJAX success globally but only for geocoding
                    $(document).off('ajaxSuccess.externalGeocode').on('ajaxSuccess.externalGeocode', function(event, xhr, settings) {
                        if (userIsTyping || validationInProgress) {
                            if (settings.url && (settings.url.includes('googleapis.com') ||
                                settings.url.includes('geocod') ||
                                settings.url.includes('maps') ||
                                settings.url.includes('kikote'))) {
                                console.log('🚫 BLOCKED EXTERNAL GEOCODING while user typing');
                                event.stopImmediatePropagation();
                                return false;
                            }
                        }
                    });
                }
                
                // Our isolated validation
                function performOurValidation(address) {
                    if (userIsTyping || validationInProgress) {
                        console.log('🚫 VALIDATION CANCELLED - User typing or validation in progress');
                        return;
                    }
                    
                    validationInProgress = true;
                    console.log('🔍 STARTING OUR VALIDATION for:', address);
                    
                    var enhancedAddress = address + ', Semporna, Sabah, Malaysia';
                    
                    if (typeof google !== 'undefined' && google.maps && google.maps.Geocoder) {
                        var ourGeocoder = new google.maps.Geocoder();
                        
                        ourGeocoder.geocode({'address': enhancedAddress}, function(results, status) {
                            if (userIsTyping) {
                                console.log('🚫 VALIDATION CANCELLED - User started typing during geocoding');
                                validationInProgress = false;
                                return;
                            }
                            
                            if (status === 'OK' && results[0]) {
                                var lat = results[0].geometry.location.lat();
                                var lng = results[0].geometry.location.lng();
                                console.log('🔍 OUR COORDINATES:', lat, lng);
                                
                                if (Math.abs(lat - 4.479391) < 0.001 && Math.abs(lng - 118.611545) < 0.001) {
                                    console.log('🚫 FALLBACK COORDINATES DETECTED');
                                    blockDelivery('Fallback coordinates detected');
                                    validationInProgress = false;
                                    return;
                                }
                            }
                            
                            // Check shipping cost
                            checkShippingCost(address);
                        });
                    } else {
                        checkShippingCost(address);
                    }
                }
                
                function checkShippingCost(address) {
                    if (userIsTyping) {
                        console.log('🚫 SHIPPING CHECK CANCELLED - User typing');
                        validationInProgress = false;
                        return;
                    }
                    
                    setTimeout(function() {
                        if (userIsTyping) {
                            console.log('🚫 SHIPPING CHECK CANCELLED - User typing during wait');
                            validationInProgress = false;
                            return;
                        }
                        
                        var shippingText = $('.shipping .woocommerce-Price-amount').text();
                        if (!shippingText) {
                            console.log('⚠️ No shipping cost found');
                            validationInProgress = false;
                            return;
                        }
                        
                        var shippingCost = parseFloat(shippingText.replace('RM', '').replace(',', ''));
                        console.log('💰 SHIPPING COST:', shippingCost);
                        
                        if (shippingCost === 11.8 || shippingCost === 11.80) {
                            blockDelivery('Fallback shipping rate RM11.80');
                        } else if (shippingCost > 0 && shippingCost < 11.8) {
                            allowDelivery('Valid shipping rate: RM' + shippingCost);
                        } else if (shippingCost > 11.8) {
                            allowDelivery('High shipping rate: RM' + shippingCost);
                        }
                        
                        validationInProgress = false;
                    }, 3000);
                }
                
                // COMPLETELY override the address field behavior
                function setupOverrideHandlers() {
                    var addressField = $('#billing_address_1');
                    
                    // Remove ALL existing event handlers from other plugins
                    addressField.off();
                    
                    // Add our controlled handlers
                    addressField.on('focus.ourplugin', function() {
                        userIsTyping = true;
                        ourValidationActive = false;
                        validationInProgress = false;
                        console.log('✏️ USER EDITING - All validation disabled');
                        
                        // Clear warnings
                        $('#delivery-warning').remove();
                        
                        // Only enable if payment is verified
                        if (window.SSFood4U_PaymentVerified) {
                            $('#place_order').prop('disabled', false);
                            $('#place_order').text('Place Order');
                        }
                    });
                    
                    addressField.on('input.ourplugin keydown.ourplugin keyup.ourplugin paste.ourplugin', function(e) {
                        userIsTyping = true;
                        ourValidationActive = false;
                        validationInProgress = false;
                        console.log('⌨️ USER TYPING (' + e.type + ') - Validation blocked');
                    });
                    
                    addressField.on('blur.ourplugin', function() {
                        var address = $(this).val().trim();
                        console.log('👆 USER CLICKED AWAY');
                        
                        if (address.length < 3) {
                            console.log('⚠️ Address too short');
                            userIsTyping = false;
                            return;
                        }
                        
                        // Wait to ensure user is really done
                        setTimeout(function() {
                            if (!userIsTyping && !validationInProgress) {
                                userIsTyping = false; // Officially not typing anymore
                                ourValidationActive = true;
                                console.log('✅ STARTING VALIDATION after blur delay');
                                performOurValidation(address);
                            } else {
                                console.log('🚫 VALIDATION CANCELLED - Still typing or in progress');
                            }
                        }, 1500);
                    });
                }
                
                // Initialize our override system
                function initializeOverride() {
                    blockExternalGeocoding();
                    setupOverrideHandlers();
                    
                    // Re-apply our overrides every few seconds to counter other plugins
                    setInterval(function() {
                        if (!userIsTyping && !validationInProgress) {
                            setupOverrideHandlers();
                        }
                    }, 5000);
                }
                
                // Wait for page to fully load, then initialize
                setTimeout(function() {
                    initializeOverride();
                    console.log('🛡️ OVERRIDE SYSTEM ACTIVATED');
                }, 2000);
                
                // Global flag for payment verification status
                window.SSFood4U_PaymentVerified = false;
                
                // Check if payment was uploaded (from URL parameter or session)
                if (window.location.href.includes('payment_uploaded=1')) {
                    window.SSFood4U_PaymentVerified = true;
                    console.log('💳 Payment verification detected from URL');
                }
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
        error_log('Enhanced OCR Validator class not found');
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
        $payment_data['verification_status'] = 'verified'; // Auto-approved
        $payment_data['auto_approved_by_ocr'] = true;
        
        // Log auto-approval
        error_log("OCR Auto-approved payment: Order {$payment_data['order_id']} with {$ocr_result['confidence']}% confidence");
        
        // Notify admin of auto-approval
        $admin_email = get_option('admin_email');
        $subject = 'Payment Auto-Approved by OCR - ' . get_bloginfo('name');
        $message = "Payment automatically approved by enhanced OCR system:\n\n";
        $message .= "Order ID: {$payment_data['order_id']}\n";
        $message .= "Amount: RM {$expected_amount}\n";
        $message .= "Confidence: {$ocr_result['confidence']}%\n";
        $message .= "Validation: {$ocr_result['validation']}\n";
        $message .= "Customer: {$payment_data['customer_email']}\n";
        $message .= "Time: " . date('Y-m-d H:i:s') . "\n\n";
        
        if (isset($ocr_result['matched_amount'])) {
            $message .= "Matched Amount: RM {$ocr_result['matched_amount']}\n";
        }
        
        if (isset($ocr_result['receipt_metadata']['receipt_type'])) {
            $message .= "Receipt Type: {$ocr_result['receipt_metadata']['receipt_type']}\n";
        }
        
        $message .= "\nReview in admin panel: " . admin_url('admin.php?page=ssfood4u-payments');
        
        wp_mail($admin_email, $subject, $message);
    }
    
    // Detailed logging for analysis
    error_log('Enhanced OCR Processing: ' . json_encode(array(
        'order_id' => $payment_data['order_id'],
        'validation_status' => $ocr_result['validation'],
        'confidence_score' => $ocr_result['confidence'],
        'auto_approved' => $payment_data['auto_approved_by_ocr'] ?? false,
        'amounts_detected' => $ocr_result['amounts_found'] ?? array(),
        'expected_amount' => $expected_amount,
        'transaction_match' => $ocr_result['transaction_match'] ?? null,
        'receipt_type' => $ocr_result['receipt_metadata']['receipt_type'] ?? 'unknown'
    )));
    
    return $payment_data;
}

// Initialize enhanced OCR system
add_action('init', 'ssfood4u_integrate_enhanced_ocr_validation');

// Initialize the debug functionality
new SSFood4U_Landmark_Debug();

// Initialize debug logger for delivery validation and shipping
if (class_exists('SSFood4U_Debug_Logger')) {
    SSFood4U_Debug_Logger::get_instance();
    error_log('SSFood4U: Debug logger initialized for coordinate and shipping tracking');
} else {
    error_log('SSFood4U_Debug_Logger class not found - coordinate debugging unavailable');
}

// Initialize payment verification system
if (class_exists('SSFood4U_Payment_Verification')) {
    new SSFood4U_Payment_Verification();
}

// Initialize delivery validator
if (class_exists('SSFood4U_Simple_Delivery_Validator')) {
    new SSFood4U_Simple_Delivery_Validator();
}

// Initialize working delivery validator (updated name)
if (class_exists('SSFood4U_Working_Delivery_Validator')) {
    new SSFood4U_Working_Delivery_Validator();
}

// Initialize enhanced OCR validator
if (class_exists('SSFood4U_Enhanced_OCR_Validator')) {
    new SSFood4U_Enhanced_OCR_Validator();
}

// === ADMIN MENU SETUP ===
add_action('admin_menu', function() {
    // Create main menu
    add_menu_page(
        'SSFood4U Settings',
        'SSFood4U',
        'manage_options',
        'ssfood4u-main',
        'ssfood4u_main_admin_page',
        'dashicons-store',
        30
    );
    
    // Add hotel database submenu
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
    
    // Add payment verification submenu (if exists)
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
    
    // Add general settings submenu
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
        
        <div class="card" style="max-width: none;">
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
                    <th scope="row">Delivery Validation</th>
                    <td><span style="color: green;">Active</span> - Mandarin translation enabled</td>
                </tr>
                <tr>
                    <th scope="row">Payment Verification</th>
                    <td><span style="color: green;">Active</span> - OCR validation enabled</td>
                </tr>
                <tr>
                    <th scope="row">Debug Logging</th>
                    <td>
                        <?php if (class_exists('SSFood4U_Debug_Logger')): ?>
                            <span style="color: green;">Active</span> - Coordinate and shipping tracking enabled
                        <?php else: ?>
                            <span style="color: orange;">Inactive</span> - Debug logger not loaded
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card" style="max-width: none;">
            <h2>Quick Actions</h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-hotels'); ?>" class="button button-primary">Manage Hotels</a>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-payments'); ?>" class="button">View Payments</a>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-settings'); ?>" class="button">Settings</a>
            </p>
        </div>
        
        <div class="card" style="max-width: none;">
            <h2>Recent Activity</h2>
            <p>Check your WordPress debug log for translation and validation activity.</p>
            <p><strong>Debug Log Location:</strong> <code>/wp-content/debug.log</code></p>
            <?php if (class_exists('SSFood4U_Debug_Logger')): ?>
                <p><strong>Coordinate Debug:</strong> Address validation and Google Maps coordinate lookups are being logged.</p>
                <p><strong>Shipping Debug:</strong> WooCommerce shipping rate calculations are being tracked for RM11.80 detection.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Settings admin page
function ssfood4u_settings_admin_page() {
    if (isset($_POST['submit'])) {
        // Handle settings save
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
            
            <div class="card" style="max-width: none;">
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
            
            <div class="card" style="max-width: none;">
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

// Add admin notice for setup completion
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'ssfood4u') !== false) {
        $ocr_key = get_option('ssfood4u_ocr_api_key', '');
        if (empty($ocr_key)) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>Enhanced OCR System Ready:</strong> ';
            echo '<a href="' . admin_url('admin.php?page=ssfood4u-settings') . '">Configure your OCR.space API key</a> to enable automatic receipt validation.';
            echo '</p></div>';
        }
    }
});

// Add OCR performance logging function
function ssfood4u_log_ocr_performance($order_id, $expected, $found, $confidence, $status) {
    $log_data = array(
        'timestamp' => current_time('mysql'),
        'order_id' => $order_id,
        'expected_amount' => $expected,
        'amounts_found' => $found,
        'confidence' => $confidence,
        'status' => $status,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    );
    
    // Log to WordPress debug log
    error_log('OCR Performance: ' . json_encode($log_data));
    
    // Store performance data for future analysis
    $performance_logs = get_option('ssfood4u_ocr_performance', array());
    $performance_logs[] = $log_data;
    
    // Keep only last 100 entries
    if (count($performance_logs) > 100) {
        $performance_logs = array_slice($performance_logs, -100);
    }
    
    update_option('ssfood4u_ocr_performance', $performance_logs);
}

// Initialize admin panel if class exists
if (class_exists('SSFood4U_Admin_Panel')) {
    error_log('SSFood4U: Creating real admin panel instance...');
    $admin_panel = new SSFood4U_Admin_Panel();
    error_log('SSFood4U: Real admin panel created successfully');
} else {
    error_log('SSFood4U_Admin_Panel class not found');
}
?>