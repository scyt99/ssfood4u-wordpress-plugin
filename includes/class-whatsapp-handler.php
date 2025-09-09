<?php
/**
 * WhatsApp Integration Handler
 * Manages WhatsApp payment verification and messaging
 */

if (!defined('ABSPATH')) exit;

class SSFood4U_WhatsApp_Handler {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Add WhatsApp-specific functionality
        add_action('wp_ajax_generate_whatsapp_link', array($this, 'generate_whatsapp_link'));
        add_action('wp_ajax_nopriv_generate_whatsapp_link', array($this, 'generate_whatsapp_link'));
    }
    
    /**
     * Generate WhatsApp link with order details
     */
    public function generate_whatsapp_link() {
        if (!wp_verify_nonce($_POST['nonce'], 'ssfood4u_payment_nonce')) {
            wp_die(json_encode(array('success' => false, 'message' => 'Security check failed')));
        }
        
        $order_id = sanitize_text_field($_POST['order_id']);
        $amount = floatval($_POST['amount']);
        $customer_email = sanitize_email($_POST['customer_email']);
        $customer_name = sanitize_text_field($_POST['customer_name']);
        
        $whatsapp_url = $this->create_whatsapp_url($order_id, $amount, $customer_email, $customer_name);
        
        wp_die(json_encode(array(
            'success' => true,
            'whatsapp_url' => $whatsapp_url
        )));
    }
    
    /**
     * Create WhatsApp URL with pre-filled message
     */
    public function create_whatsapp_url($order_id, $amount, $customer_email, $customer_name = '') {
        $whatsapp_number = get_option('ssfood4u_whatsapp', '60123456789');
        
        // Create message template
        $message = $this->create_payment_message($order_id, $amount, $customer_email, $customer_name);
        
        // Encode message for URL
        $encoded_message = urlencode($message);
        
        // Create WhatsApp URL
        $whatsapp_url = "https://wa.me/{$whatsapp_number}?text={$encoded_message}";
        
        return $whatsapp_url;
    }
    
    /**
     * Create payment verification message template
     */
    private function create_payment_message($order_id, $amount, $customer_email, $customer_name = '') {
        $site_name = get_bloginfo('name');
        
        $message = "Hi {$site_name}! 🍽️\n\n";
        $message .= "Payment confirmation for my order:\n\n";
        $message .= "📋 Order Details:\n";
        $message .= "• Order ID: {$order_id}\n";
        $message .= "• Amount: RM " . number_format($amount, 2) . "\n";
        $message .= "• Email: {$customer_email}\n";
        
        if ($customer_name) {
            $message .= "• Name: {$customer_name}\n";
        }
        
        $message .= "\n💳 Payment Status:\n";
        $message .= "✅ I have made the payment\n";
        $message .= "📸 Payment screenshot attached\n\n";
        $message .= "🔢 Transaction Details:\n";
        $message .= "• Transaction ID: [Please enter your transaction ID]\n";
        $message .= "• Payment Method: [Bank Transfer/Online Banking/E-Wallet]\n";
        $message .= "• Payment Time: [Please enter time]\n\n";
        $message .= "Thank you! Looking forward to my delicious order! 😋";
        
        return $message;
    }
    
    /**
     * Create admin notification WhatsApp message
     */
    public function create_admin_notification_message($order_id, $amount, $customer_email) {
        $message = "🔔 New Payment Verification\n\n";
        $message .= "A customer has submitted payment proof:\n\n";
        $message .= "📋 Details:\n";
        $message .= "• Order: {$order_id}\n";
        $message .= "• Amount: RM " . number_format($amount, 2) . "\n";
        $message .= "• Customer: {$customer_email}\n\n";
        $message .= "Please check admin panel to verify:\n";
        $message .= admin_url('admin.php?page=ssfood4u-payments');
        
        return $message;
    }
    
    /**
     * Send WhatsApp message (if WhatsApp Business API is configured)
     */
    public function send_whatsapp_message($phone_number, $message) {
        // This would integrate with WhatsApp Business API
        // For now, we'll just log it
        error_log("WhatsApp message to {$phone_number}: {$message}");
        
        // TODO: Implement actual WhatsApp Business API integration
        // Example structure for future implementation:
        /*
        $api_endpoint = 'https://api.whatsapp.com/send';
        $data = array(
            'phone' => $phone_number,
            'text' => $message
        );
        
        $response = wp_remote_post($api_endpoint, array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . get_option('whatsapp_api_token')
            )
        ));
        */
        
        return true;
    }
    
    /**
     * Get formatted WhatsApp number
     */
    public static function get_formatted_whatsapp_number() {
        $whatsapp = get_option('ssfood4u_whatsapp', '60123456789');
        
        // Remove any non-numeric characters
        $clean_number = preg_replace('/[^0-9]/', '', $whatsapp);
        
        // Add + prefix for display
        return '+' . $clean_number;
    }
    
    /**
     * Validate WhatsApp number format
     */
    public static function validate_whatsapp_number($number) {
        // Remove any non-numeric characters
        $clean_number = preg_replace('/[^0-9]/', '', $number);
        
        // Check if it's a valid length (usually 10-15 digits)
        if (strlen($clean_number) < 10 || strlen($clean_number) > 15) {
            return false;
        }
        
        // Check if it starts with a valid country code
        $valid_prefixes = array('60', '65', '1', '44', '91', '86'); // Malaysia, Singapore, US, UK, India, China
        
        $is_valid = false;
        foreach ($valid_prefixes as $prefix) {
            if (substr($clean_number, 0, strlen($prefix)) === $prefix) {
                $is_valid = true;
                break;
            }
        }
        
        return $is_valid;
    }
    
    /**
     * Create quick reply buttons for WhatsApp
     */
    public function create_quick_replies($order_id) {
        return array(
            array(
                'type' => 'reply',
                'reply' => array(
                    'id' => 'payment_confirmed_' . $order_id,
                    'title' => '✅ Payment Sent'
                )
            ),
            array(
                'type' => 'reply',
                'reply' => array(
                    'id' => 'need_help_' . $order_id,
                    'title' => '❓ Need Help'
                )
            ),
            array(
                'type' => 'reply',
                'reply' => array(
                    'id' => 'change_order_' . $order_id,
                    'title' => '🔄 Change Order'
                )
            )
        );
    }
    
    /**
     * Handle WhatsApp webhook (for future Business API integration)
     */
    public function handle_webhook() {
        // This would handle incoming WhatsApp messages
        // For automatic payment confirmation, status updates, etc.
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        // Log the webhook data for debugging
        error_log('WhatsApp Webhook: ' . $input);
        
        // TODO: Process incoming messages, update payment status, etc.
        
        http_response_code(200);
        echo 'OK';
    }
}
?>