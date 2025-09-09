<?php
/**
 * QR Code Generator
 * Handles dynamic QR code generation for payments
 */

if (!defined('ABSPATH')) exit;

class SSFood4U_QR_Generator {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Handle dynamic QR generation requests
        add_action('init', array($this, 'handle_qr_generation'));
    }
    
    /**
     * Handle QR code generation requests
     */
    public function handle_qr_generation() {
        if (isset($_GET['generate_qr']) && isset($_GET['amount']) && isset($_GET['order_id'])) {
            $this->generate_dynamic_qr();
        }
    }
    
    /**
     * Generate dynamic QR code
     */
    private function generate_dynamic_qr() {
        $amount = floatval($_GET['amount']);
        $order_id = sanitize_text_field($_GET['order_id']);
        
        // Validate inputs
        if ($amount <= 0 || empty($order_id)) {
            wp_die('Invalid parameters');
        }
        
        $qr_url = $this->create_qr_in_assets($amount, $order_id);
        
        header('Content-Type: application/json');
        echo json_encode(array('qr_url' => $qr_url));
        exit;
    }
    
    /**
     * Create QR code and save to assets directory
     */
    private function create_qr_in_assets($amount, $order_id) {
        $assets_dir = SSFOOD4U_PLUGIN_DIR . 'assets/';
        
        // Ensure assets directory exists
        if (!file_exists($assets_dir)) {
            wp_mkdir_p($assets_dir);
        }
        
        // Generate QR data
        $qr_data = $this->create_malaysian_qr_data($amount, $order_id);
        
        // Use QR code service API
        $qr_image_url = "https://api.qrserver.com/v1/create-qr-code/?" . http_build_query(array(
            'size' => '300x300',
            'format' => 'png',
            'data' => $qr_data,
            'ecc' => 'M' // Error correction level
        ));
        
        // Download QR code
        $response = wp_remote_get($qr_image_url, array(
            'timeout' => 15,
            'sslverify' => false
        ));
        
        if (!is_wp_error($response)) {
            $filename = 'qr-' . sanitize_file_name($order_id) . '.png';
            $filepath = $assets_dir . $filename;
            
            if (file_put_contents($filepath, wp_remote_retrieve_body($response))) {
                return SSFOOD4U_PLUGIN_URL . 'assets/' . $filename;
            }
        }
        
        // Fallback to default QR
        return SSFOOD4U_PLUGIN_URL . 'assets/ssfood4u-payment-qr.png';
    }
    
    /**
     * Create Malaysian QR payment data
     */
    private function create_malaysian_qr_data($amount, $order_id) {
        $merchant_name = get_bloginfo('name');
        $bank_name = get_option('ssfood4u_bank_name', 'SSFood4U');
        $account_number = get_option('ssfood4u_account_number', '1234567890');
        
        // Create payment URL or data structure
        // This is a simplified version - in production, you'd use proper Malaysian QR standards
        $qr_data = array(
            'version' => '01',
            'initMethod' => '12',
            'merchantCategory' => '5812', // Restaurant category
            'merchantName' => $merchant_name,
            'merchantCity' => 'Semporna',
            'amount' => number_format($amount, 2),
            'currency' => 'MYR',
            'reference' => $order_id,
            'additionalInfo' => 'SSFood4U Order Payment',
            'bank' => $bank_name,
            'account' => $account_number
        );
        
        // For now, return JSON - in production you'd format according to Malaysian QR standards
        return json_encode($qr_data);
    }
    
    /**
     * Generate static QR code for general payments
     */
    public static function generate_static_qr() {
        $bank_name = get_option('ssfood4u_bank_name', 'Your Bank');
        $account_number = get_option('ssfood4u_account_number', '1234567890');
        $account_holder = get_option('ssfood4u_account_holder', 'SSFood4U');
        
        $qr_data = "Bank: {$bank_name}\nAccount: {$account_number}\nName: {$account_holder}\nReference: [Order ID]";
        
        $qr_url = "https://api.qrserver.com/v1/create-qr-code/?" . http_build_query(array(
            'size' => '300x300',
            'format' => 'png',
            'data' => $qr_data,
            'ecc' => 'M'
        ));
        
        return $qr_url;
    }
    
    /**
     * Clean up old QR codes
     */
    public static function cleanup_old_qr_codes() {
        $assets_dir = SSFOOD4U_PLUGIN_DIR . 'assets/';
        
        if (!is_dir($assets_dir)) return;
        
        $files = glob($assets_dir . 'qr-ORD-*.png');
        $now = time();
        
        foreach ($files as $file) {
            // Delete QR codes older than 24 hours
            if (filemtime($file) < $now - (24 * 60 * 60)) {
                unlink($file);
            }
        }
    }
}

// Schedule cleanup of old QR codes
if (!wp_next_scheduled('ssfood4u_cleanup_qr')) {
    wp_schedule_event(time(), 'daily', 'ssfood4u_cleanup_qr');
}

add_action('ssfood4u_cleanup_qr', array('SSFood4U_QR_Generator', 'cleanup_old_qr_codes'));
?>