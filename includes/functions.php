<?php
/**
 * Helper Functions
 * Utility functions used across the plugin
 */

if (!defined('ABSPATH')) exit;

/**
 * Log debug messages (only in development)
 */
function ssfood4u_log($message, $type = 'info') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $log_message = '[SSFood4U ' . strtoupper($type) . '] ' . $message;
        error_log($log_message);
    }
}

/**
 * Check if we're on checkout page
 */
function ssfood4u_is_checkout() {
    return function_exists('is_checkout') && is_checkout();
}

/**
 * Get plugin settings with defaults
 */
function ssfood4u_get_setting($key, $default = '') {
    $settings = array(
        'whatsapp' => get_option('ssfood4u_whatsapp', '60123456789'),
        'bank_name' => get_option('ssfood4u_bank_name', 'Update with your bank name'),
        'account_number' => get_option('ssfood4u_account_number', 'Update with your account number'),
        'account_holder' => get_option('ssfood4u_account_holder', 'Update with account holder name'),
    );
    
    return isset($settings[$key]) ? $settings[$key] : $default;
}

/**
 * Format currency amount
 */
function ssfood4u_format_currency($amount) {
    return 'RM ' . number_format(floatval($amount), 2);
}

/**
 * Generate unique order ID
 */
function ssfood4u_generate_order_id() {
    return 'ORD-' . time() . '-' . wp_generate_password(4, false, false);
}

/**
 * Validate email address
 */
function ssfood4u_validate_email($email) {
    return is_email($email) && !empty($email);
}

/**
 * Sanitize file name for uploads
 */
function ssfood4u_sanitize_filename($filename) {
    // Remove extension
    $info = pathinfo($filename);
    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
    $name = basename($filename, $ext);
    
    // Sanitize the name
    $name = sanitize_file_name($name);
    
    // Add timestamp to prevent conflicts
    $name = $name . '_' . time();
    
    return $name . $ext;
}

/**
 * Check if file is valid image
 */
function ssfood4u_is_valid_image($file) {
    if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
        return false;
    }
    
    $allowed_types = array('image/jpeg', 'image/jpg', 'image/png');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    return in_array($mime_type, $allowed_types);
}

/**
 * Get file size in human readable format
 */
function ssfood4u_human_filesize($bytes, $decimals = 2) {
    $size = array('B','KB','MB','GB','TB','PB','EB','ZB','YB');
    $factor = floor((strlen($bytes) - 1) / 3);
    
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . @$size[$factor];
}

/**
 * Send email notification
 */
function ssfood4u_send_notification($to, $subject, $message, $headers = array()) {
    if (!ssfood4u_validate_email($to)) {
        return false;
    }
    
    // Add default headers
    if (empty($headers)) {
        $headers = array('Content-Type: text/html; charset=UTF-8');
    }
    
    // Add site name to subject
    $site_name = get_bloginfo('name');
    $full_subject = "[{$site_name}] {$subject}";
    
    return wp_mail($to, $full_subject, $message, $headers);
}

/**
 * Format WhatsApp number
 */
function ssfood4u_format_whatsapp($number) {
    // Remove any non-numeric characters
    $clean_number = preg_replace('/[^0-9]/', '', $number);
    
    // Add + prefix for display
    return '+' . $clean_number;
}

/**
 * Create secure upload directory
 */
function ssfood4u_create_upload_dir($subdir = 'payment-receipts') {
    $upload_dir = wp_upload_dir();
    $target_dir = $upload_dir['basedir'] . '/' . $subdir . '/';
    
    if (!file_exists($target_dir)) {
        if (wp_mkdir_p($target_dir)) {
            // Add security files
            file_put_contents($target_dir . '.htaccess', "Options -Indexes\nDeny from all");
            file_put_contents($target_dir . 'index.php', '<?php // Silence is golden');
            
            return array(
                'success' => true,
                'path' => $target_dir,
                'url' => $upload_dir['baseurl'] . '/' . $subdir . '/'
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to create upload directory'
            );
        }
    }
    
    return array(
        'success' => true,
        'path' => $target_dir,
        'url' => $upload_dir['baseurl'] . '/' . $subdir . '/'
    );
}

/**
 * Get payment status badge HTML
 */
function ssfood4u_get_status_badge($status) {
    $badges = array(
        'pending' => '<span class="status-badge status-pending">⏳ Pending</span>',
        'verified' => '<span class="status-badge status-verified">✅ Verified</span>',
        'rejected' => '<span class="status-badge status-rejected">❌ Rejected</span>'
    );
    
    return isset($badges[$status]) ? $badges[$status] : '<span class="status-badge">Unknown</span>';
}

/**
 * Generate nonce for AJAX requests
 */
function ssfood4u_get_ajax_nonce($action = 'ssfood4u_payment_nonce') {
    return wp_create_nonce($action);
}

/**
 * Verify nonce for AJAX requests
 */
function ssfood4u_verify_ajax_nonce($nonce, $action = 'ssfood4u_payment_nonce') {
    return wp_verify_nonce($nonce, $action);
}

/**
 * Get admin URL for plugin pages
 */
function ssfood4u_admin_url($page = 'payments', $args = array()) {
    $base_url = admin_url('admin.php?page=ssfood4u-' . $page);
    
    if (!empty($args)) {
        $base_url = add_query_arg($args, $base_url);
    }
    
    return $base_url;
}

/**
 * Check if plugin is properly configured
 */
function ssfood4u_is_configured() {
    $whatsapp = get_option('ssfood4u_whatsapp', '60123456789');
    $qr_exists = file_exists(SSFOOD4U_PLUGIN_DIR . 'assets/ssfood4u-payment-qr.png');
    $bank_configured = get_option('ssfood4u_bank_name', 'Update with your bank name') !== 'Update with your bank name';
    
    return ($whatsapp !== '60123456789' && $qr_exists && $bank_configured);
}

/**
 * Get configuration status
 */
function ssfood4u_get_config_status() {
    return array(
        'whatsapp_configured' => get_option('ssfood4u_whatsapp', '60123456789') !== '60123456789',
        'qr_uploaded' => file_exists(SSFOOD4U_PLUGIN_DIR . 'assets/ssfood4u-payment-qr.png'),
        'bank_configured' => get_option('ssfood4u_bank_name', 'Update with your bank name') !== 'Update with your bank name',
        'account_configured' => get_option('ssfood4u_account_number', 'Update with your account number') !== 'Update with your account number'
    );
}

/**
 * Clean up expired files
 */
function ssfood4u_cleanup_expired_files($directory, $max_age_hours = 24) {
    if (!is_dir($directory)) return;
    
    $files = glob($directory . '*.{png,jpg,jpeg}', GLOB_BRACE);
    $now = time();
    $max_age_seconds = $max_age_hours * 60 * 60;
    $cleaned = 0;
    
    foreach ($files as $file) {
        if (filemtime($file) < ($now - $max_age_seconds)) {
            if (unlink($file)) {
                $cleaned++;
            }
        }
    }
    
    return $cleaned;
}

/**
 * Get plugin version
 */
function ssfood4u_get_version() {
    return defined('SSFOOD4U_PLUGIN_VERSION') ? SSFOOD4U_PLUGIN_VERSION : '1.3';
}

/**
 * Check if WooCommerce is active
 */
function ssfood4u_is_woocommerce_active() {
    return class_exists('WooCommerce');
}

/**
 * Display admin notice if WooCommerce is not active
 */
function ssfood4u_woocommerce_notice() {
    if (!ssfood4u_is_woocommerce_active()) {
        echo '<div class="notice notice-error"><p><strong>SSFood4U Payment Verification</strong> requires WooCommerce to be installed and activated.</p></div>';
    }
}
add_action('admin_notices', 'ssfood4u_woocommerce_notice');

/**
 * Handle plugin activation
 */
function ssfood4u_activate() {
    // Check for WooCommerce
    if (!ssfood4u_is_woocommerce_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('SSFood4U Payment Verification requires WooCommerce to be installed and activated.');
    }
    
    // Create database tables
    SSFood4U_Database::create_payment_table();
    
    // Create upload directories
    ssfood4u_create_upload_dir('payment-receipts');
    
    // Set default options
    add_option('ssfood4u_version', ssfood4u_get_version());
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Handle plugin deactivation
 */
function ssfood4u_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook('ssfood4u_cleanup_qr');
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
?>
<?php
/**
 * Set default checkout values for Semporna customers
 * Add this to your functions.php file or create a new include file
 */

if (!defined('ABSPATH')) exit;

/**
 * Set default checkout field values
 */
add_filter('woocommerce_checkout_fields', 'ssfood4u_set_default_checkout_fields');

function ssfood4u_set_default_checkout_fields($fields) {
    // Always set Semporna defaults - override any existing values
    $fields['billing']['billing_city']['default'] = 'Semporna';
    $fields['billing']['billing_postcode']['default'] = '91308';
    $fields['billing']['billing_state']['default'] = 'SBH'; // Sabah state code
    $fields['billing']['billing_country']['default'] = 'MY';
    
    return $fields;
}

/**
 * Alternative method using JavaScript (more reliable for dynamic updates)
 */
add_action('wp_footer', 'ssfood4u_set_checkout_defaults_js');

function ssfood4u_set_checkout_defaults_js() {
    if (!is_checkout()) return;
    
    ?>
    <script>
    jQuery(document).ready(function($) {
        console.log('Setting Semporna checkout defaults');
        
        function setDefaults() {
            // Check if defaults are already set to avoid triggering loops
            if ($('#billing_city').val() !== 'Semporna' || 
                $('#billing_postcode').val() !== '91308' || 
                $('#billing_state').val() !== 'SBH') {
                
                // Set defaults without triggering change events that cause shipping recalculation
                $('#billing_city').val('Semporna');
                $('#billing_postcode').val('91308');
                $('#billing_state').val('SBH');
                $('#billing_country').val('MY');
                
                // Only trigger Select2 update for visual display, not change events
                if ($('#billing_state').hasClass('select2-hidden-accessible')) {
                    $('#billing_state').select2('val', 'SBH');
                }
                
                console.log('Defaults set without triggering shipping recalculation');
            }
        }
        
        // Set defaults on page load (only once)
        var defaultsSet = false;
        
        function setDefaults() {
            if (defaultsSet) return; // Prevent multiple executions
            
            // Set defaults without triggering change events
            $('#billing_city').val('Semporna');
            $('#billing_postcode').val('91308');
            $('#billing_country').val('MY');
            
            // Handle state field with proper Select2 refresh
            var stateField = $('#billing_state');
            
            // Set the value first
            stateField.val('SBH');
            
            // If using Select2, refresh the display properly
            if (stateField.hasClass('select2-hidden-accessible')) {
                // Trigger Select2 to refresh and show the selected value
                stateField.trigger('change.select2');
            }
            
            // Hide the defaulted fields to prevent user changes
            $('#billing_city_field').hide();
            $('#billing_postcode_field').hide();
            $('#billing_state_field').hide();
            $('#billing_country_field').hide();
            
            defaultsSet = true;
            console.log('Semporna defaults set and fields hidden');
        }
        
        // Set defaults with proper timing
        setTimeout(setDefaults, 1500);
        
        // Additional check to ensure state shows properly
        setTimeout(function() {
            var stateField = $('#billing_state');
            if (stateField.val() === 'SBH') {
                // Force Select2 to display the selected text
                var selectedText = stateField.find('option[value="SBH"]').text();
                stateField.next('.select2-container').find('.select2-selection__rendered').text(selectedText);
                console.log('State field display corrected to show: ' + selectedText);
            }
        }, 2500);
    });
    </script>
    <?php
}
?>