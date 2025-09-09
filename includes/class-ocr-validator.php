<?php
/**
 * OCR Receipt Validation using OCR.space API
 * Add this to your payment verification system
 */

if (!defined('ABSPATH')) exit;

class SSFood4U_OCR_Validator {
    
    private $api_key;
    private $api_url = 'https://api.ocr.space/parse/image';
    
    public function __construct() {
        // Get API key from WordPress options
        $this->api_key = get_option('ssfood4u_ocr_api_key', '');
    }
    
    /**
     * Validate receipt amount using OCR
     */
    public function validate_receipt_amount($image_path, $expected_amount) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => 'OCR API key not configured',
                'validation' => 'skipped'
            );
        }
        
        // Extract text from receipt image
        $ocr_result = $this->extract_text_from_image($image_path);
        
        if (!$ocr_result['success']) {
            return array(
                'success' => false,
                'message' => 'OCR processing failed: ' . $ocr_result['message'],
                'validation' => 'failed'
            );
        }
        
        // Parse amounts from OCR text
        $amounts_found = $this->extract_amounts_from_text($ocr_result['text']);
        
        // Validate against expected amount
        $validation_result = $this->validate_amounts($amounts_found, $expected_amount);
        
        return array(
            'success' => true,
            'ocr_text' => $ocr_result['text'],
            'amounts_found' => $amounts_found,
            'expected_amount' => $expected_amount,
            'validation' => $validation_result['status'],
            'message' => $validation_result['message'],
            'confidence' => $validation_result['confidence']
        );
    }
    
    /**
     * Extract text from image using OCR.space API
     */
    private function extract_text_from_image($image_path) {
        // Prepare the request
        $post_data = array(
            'apikey' => $this->api_key,
            'language' => 'eng', // English - you can change to 'chs' for Chinese if needed
            'isOverlayRequired' => 'false',
            'detectOrientation' => 'true',
            'isTable' => 'true', // Better for receipt parsing
            'OCREngine' => '2' // Engine 2 is better for receipts
        );
        
        // Handle file upload
        if (function_exists('curl_file_create')) {
            $post_data['file'] = curl_file_create($image_path);
        } else {
            $post_data['file'] = '@' . $image_path;
        }
        
        // Make API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false || $http_code !== 200) {
            return array(
                'success' => false,
                'message' => 'API request failed'
            );
        }
        
        $result = json_decode($response, true);
        
        if (!$result || !isset($result['ParsedResults'])) {
            return array(
                'success' => false,
                'message' => 'Invalid API response'
            );
        }
        
        if (isset($result['IsErroredOnProcessing']) && $result['IsErroredOnProcessing']) {
            return array(
                'success' => false,
                'message' => 'OCR processing error: ' . ($result['ErrorMessage'] ?? 'Unknown error')
            );
        }
        
        // Extract text from all parsed results
        $extracted_text = '';
        foreach ($result['ParsedResults'] as $parsed) {
            $extracted_text .= $parsed['ParsedText'] . "\n";
        }
        
        return array(
            'success' => true,
            'text' => trim($extracted_text),
            'confidence' => $result['ParsedResults'][0]['TextOrientation'] ?? 'unknown'
        );
    }
    
    /**
     * Extract monetary amounts from OCR text
     */
    private function extract_amounts_from_text($text) {
        $amounts = array();
        
        // Common Malaysian currency patterns
        $patterns = array(
            '/RM\s*(\d+[,.]?\d*)/i',        // RM 15.50, RM15.50
            '/MYR\s*(\d+[,.]?\d*)/i',       // MYR 15.50
            '/(\d+[,.]?\d*)\s*RM/i',        // 15.50 RM
            '/\$\s*(\d+[,.]?\d*)/i',        // $ 15.50 (sometimes used)
            '/(\d{1,4}[,.]\d{2})\b/',       // General decimal amounts like 15.50, 1,234.56
        );
        
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $amount) {
                    // Clean and convert to float
                    $clean_amount = str_replace(',', '', $amount);
                    $float_amount = floatval($clean_amount);
                    
                    if ($float_amount > 0) {
                        $amounts[] = $float_amount;
                    }
                }
            }
        }
        
        // Remove duplicates and sort
        $amounts = array_unique($amounts);
        rsort($amounts); // Largest amounts first
        
        return $amounts;
    }
    
    /**
     * Validate extracted amounts against expected amount
     */
    private function validate_amounts($found_amounts, $expected_amount) {
        if (empty($found_amounts)) {
            return array(
                'status' => 'no_amounts_found',
                'message' => 'No monetary amounts detected in receipt',
                'confidence' => 'low'
            );
        }
        
        $expected = floatval($expected_amount);
        $tolerance = 0.01; // Allow 1 cent difference for rounding
        
        // Check if expected amount is found (exact or close match)
        foreach ($found_amounts as $amount) {
            if (abs($amount - $expected) <= $tolerance) {
                return array(
                    'status' => 'match',
                    'message' => "Amount validated: RM{$amount} matches expected RM{$expected}",
                    'confidence' => 'high'
                );
            }
        }
        
        // Check if expected amount is close to any found amount (within 5%)
        foreach ($found_amounts as $amount) {
            $difference_percent = abs(($amount - $expected) / $expected) * 100;
            if ($difference_percent <= 5) {
                return array(
                    'status' => 'close_match',
                    'message' => "Amount close: RM{$amount} vs expected RM{$expected} ({$difference_percent}% difference)",
                    'confidence' => 'medium'
                );
            }
        }
        
        // No match found
        $amounts_str = implode(', ', array_map(function($a) { return "RM{$a}"; }, $found_amounts));
        return array(
            'status' => 'no_match',
            'message' => "Expected RM{$expected} not found. Detected amounts: {$amounts_str}",
            'confidence' => 'low'
        );
    }
    
    /**
     * Get OCR validation settings page HTML (for admin panel)
     */
    public function get_settings_html() {
        $api_key = get_option('ssfood4u_ocr_api_key', '');
        
        ob_start();
        ?>
        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3>OCR Receipt Validation Settings</h3>
            
            <form method="post">
                <?php wp_nonce_field('ssfood4u_ocr_settings', 'ocr_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">OCR.space API Key</th>
                        <td>
                            <input type="text" name="ocr_api_key" value="<?php echo esc_attr($api_key); ?>" 
                                   style="width: 300px;" placeholder="Enter your OCR.space API key">
                            <p class="description">
                                Get your free API key from <a href="https://ocr.space/ocrapi" target="_blank">OCR.space</a><br>
                                Free tier: 25,000 requests/month
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save OCR Settings', 'primary', 'save_ocr_settings'); ?>
            </form>
            
            <?php if ($api_key): ?>
            <div style="margin-top: 20px;">
                <h4>Test OCR Functionality</h4>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('ssfood4u_test_ocr', 'test_ocr_nonce'); ?>
                    <p>
                        <label>Upload test receipt:</label><br>
                        <input type="file" name="test_receipt" accept="image/*" required>
                    </p>
                    <p>
                        <label>Expected amount (RM):</label><br>
                        <input type="number" name="test_amount" step="0.01" placeholder="15.50" required>
                    </p>
                    <?php submit_button('Test OCR', 'secondary', 'test_ocr'); ?>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle settings form submission
     */
    public function handle_settings_form() {
        if (isset($_POST['save_ocr_settings']) && wp_verify_nonce($_POST['ocr_nonce'], 'ssfood4u_ocr_settings')) {
            $api_key = sanitize_text_field($_POST['ocr_api_key']);
            update_option('ssfood4u_ocr_api_key', $api_key);
            echo '<div class="notice notice-success"><p>OCR settings saved successfully!</p></div>';
        }
        
        if (isset($_POST['test_ocr']) && wp_verify_nonce($_POST['test_ocr_nonce'], 'ssfood4u_test_ocr')) {
            $this->handle_ocr_test();
        }
    }
    
    /**
     * Handle OCR test
     */
    private function handle_ocr_test() {
        if (!isset($_FILES['test_receipt']) || $_FILES['test_receipt']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>File upload failed.</p></div>';
            return;
        }
        
        $test_amount = floatval($_POST['test_amount']);
        $test_result = $this->validate_receipt_amount($_FILES['test_receipt']['tmp_name'], $test_amount);
        
        echo '<div class="notice notice-info">';
        echo '<h4>OCR Test Results:</h4>';
        echo '<p><strong>Validation Status:</strong> ' . $test_result['validation'] . '</p>';
        echo '<p><strong>Message:</strong> ' . $test_result['message'] . '</p>';
        
        if (isset($test_result['amounts_found'])) {
            echo '<p><strong>Amounts Found:</strong> ' . implode(', ', array_map(function($a) { return "RM{$a}"; }, $test_result['amounts_found'])) . '</p>';
        }
        
        if (isset($test_result['ocr_text'])) {
            echo '<p><strong>OCR Text:</strong></p>';
            echo '<textarea readonly style="width: 100%; height: 150px;">' . esc_textarea($test_result['ocr_text']) . '</textarea>';
        }
        echo '</div>';
    }
}

// Integration with existing payment verification
function ssfood4u_integrate_ocr_validation() {
    // Add OCR validation to payment processing
    add_filter('ssfood4u_before_save_payment', 'ssfood4u_process_ocr_validation', 10, 3);
}

function ssfood4u_process_ocr_validation($payment_data, $receipt_file_path, $expected_amount) {
    $ocr_validator = new SSFood4U_OCR_Validator();
    $ocr_result = $ocr_validator->validate_receipt_amount($receipt_file_path, $expected_amount);
    
    // Add OCR results to payment data
    $payment_data['ocr_validation'] = $ocr_result['validation'];
    $payment_data['ocr_message'] = $ocr_result['message'];
    $payment_data['ocr_amounts_found'] = isset($ocr_result['amounts_found']) ? 
        implode(',', $ocr_result['amounts_found']) : '';
    
    // Log OCR results for admin review
    error_log('OCR Validation Result: ' . json_encode($ocr_result));
    
    return $payment_data;
}

// Initialize OCR integration
add_action('init', 'ssfood4u_integrate_ocr_validation');
?>