<?php
/**
 * Enhanced Payment Verification System with Dual Language Support
 * Updated from existing version to include dual language display
 * Fixed double file selection issue and English input enabling
 */

if (!defined('ABSPATH')) exit;

class SSFood4U_Payment_Verification {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Add payment verification UI
        add_action('wp_footer', array($this, 'add_payment_verification_ui'), 999);
        
        // Handle form submission (traditional POST)
        add_action('init', array($this, 'handle_form_submission'));
        
        // Handle file upload via traditional form
        add_action('wp_loaded', array($this, 'handle_receipt_upload'));
        
        // Restore form data after successful upload
        add_action('wp_footer', array($this, 'restore_form_data'), 998);
        
        // Add dual language fixes for English input
        add_action('wp_footer', array($this, 'add_dual_language_fixes'), 997);
    }
    
    /**
     * AJAX handler for transaction ID extraction preview (optional feature)
     */
    public function ajax_extract_transaction_preview() {
        check_ajax_referer('ssfood4u_payment_nonce', 'nonce');
        
        if (!isset($_FILES['receipt_preview']) || $_FILES['receipt_preview']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('No valid file uploaded for preview');
            return;
        }
        
        // Initialize OCR validator
        if (!class_exists('SSFood4U_Enhanced_OCR_Validator')) {
            wp_send_json_error('OCR validator not available');
            return;
        }
        
        $ocr_validator = new SSFood4U_Enhanced_OCR_Validator();
        
        // Extract transaction ID from temporary file
        $temp_file_path = $_FILES['receipt_preview']['tmp_name'];
        $validation_result = $ocr_validator->validate_receipt_amount($temp_file_path, 0); // Amount doesn't matter for extraction
        
        if ($validation_result['success'] && isset($validation_result['extracted_transaction_id'])) {
            wp_send_json_success(array(
                'transaction_id' => $validation_result['extracted_transaction_id'],
                'confidence' => $validation_result['confidence'],
                'amounts_found' => $validation_result['amounts_found'] ?? array()
            ));
        } else {
            wp_send_json_error('Could not extract transaction ID from receipt');
        }
    }
    
    /**
     * Handle traditional form submission
     */
    public function handle_form_submission() {
        if (isset($_POST['ssfood4u_upload_receipt']) && isset($_POST['ssfood4u_nonce'])) {
            if (wp_verify_nonce($_POST['ssfood4u_nonce'], 'ssfood4u_payment_nonce')) {
                $this->process_receipt_upload();
            }
        }
    }
    
    /**
     * Process receipt upload with enhanced OCR validation and auto transaction ID extraction
     */
    private function process_receipt_upload() {
        try {
            $order_id = sanitize_text_field($_POST['order_id'] ?? '');
            $amount = floatval($_POST['amount'] ?? 0);
            $customer_email = sanitize_email($_POST['customer_email'] ?? '');
            $transaction_id = sanitize_text_field($_POST['transaction_id'] ?? '');
            
            if (empty($order_id)) {
                $this->set_upload_message('error', 'Missing order ID');
                return;
            }
            
            // Handle file upload
            if (!isset($_FILES['payment_receipt']) || $_FILES['payment_receipt']['error'] !== UPLOAD_ERR_OK) {
                $this->set_upload_message('error', 'No valid file uploaded');
                return;
            }
            
            // ENHANCED: Perform OCR validation and transaction ID extraction BEFORE moving the file
            $ocr_validated = false;
            $ocr_data = array();
            $extracted_transaction_id = null;
            
            if (class_exists('SSFood4U_Enhanced_OCR_Validator')) {
                $ocr_validator = new SSFood4U_Enhanced_OCR_Validator();
                
                // Use the temporary file path BEFORE it's moved
                $temp_file_path = $_FILES['payment_receipt']['tmp_name'];
                
                // Validate the temp file exists before OCR processing
                if (!file_exists($temp_file_path)) {
                    $this->set_upload_message('error', 'Temporary file not accessible for validation');
                    return;
                }
                
                $ocr_result = $ocr_validator->validate_receipt_amount($temp_file_path, $amount, $transaction_id);
                
                error_log('OCR Validation Result: ' . json_encode($ocr_result));
                
                if (!$ocr_result['success']) {
                    $this->set_upload_message('error', 'OCR validation failed: ' . $ocr_result['message']);
                    return;
                }
                
                // Extract transaction ID if available
                $extracted_transaction_id = $ocr_result['extracted_transaction_id'] ?? null;
                
                // Use extracted transaction ID if user didn't provide one
                if (empty($transaction_id) && !empty($extracted_transaction_id)) {
                    $transaction_id = $extracted_transaction_id;
                }
                
                // Check if OCR validation is successful
                $auto_approve_threshold = get_option('ssfood4u_ocr_auto_approve', 85);
                $is_amount_valid = ($ocr_result['validation'] === 'match' || $ocr_result['validation'] === 'close_match');
                $is_confidence_high = ($ocr_result['confidence'] >= $auto_approve_threshold);
                
                if (!$is_amount_valid) {
                    $this->set_upload_message('error', 'Payment amount validation failed / 付款金额验证失败: ' . $ocr_result['message']);
                    return;
                }
                
                if (!$is_confidence_high) {
                    $this->set_upload_message('error', 'Payment verification confidence too low (' . $ocr_result['confidence'] . '%). Please ensure your receipt is clear and readable.');
                    return;
                }
                
                // Store OCR success data
                $ocr_validated = true;
                $ocr_data = array(
                    'validation' => $ocr_result['validation'],
                    'confidence' => $ocr_result['confidence'],
                    'matched_amount' => $ocr_result['matched_amount'] ?? $amount,
                    'ocr_message' => $ocr_result['message'],
                    'extracted_transaction_id' => $extracted_transaction_id
                );
                
                // Enhanced success message
                $success_message = 'Payment verified successfully! OCR confirmed RM' . number_format($ocr_result['matched_amount'], 2) . ' matches your order total with ' . $ocr_result['confidence'] . '% confidence.';
                if (!empty($extracted_transaction_id)) {
                    $success_message .= ' Transaction ID automatically detected: ' . $extracted_transaction_id;
                }
                
            } else {
                // Fallback: OCR not available
                $ocr_validated = false;
                $ocr_data = array();
                $success_message = 'Receipt uploaded but OCR validation unavailable. Payment pending manual review.';
                
                // Still require transaction ID if OCR is not available
                if (empty($transaction_id)) {
                    $this->set_upload_message('error', 'Transaction ID is required');
                    return;
                }
            }
            
            // Validate that we have a transaction ID (manual or extracted)
            if (empty($transaction_id)) {
                $require_transaction = (get_option('ssfood4u_ocr_require_transaction', 'no') === 'yes');
                if ($require_transaction) {
                    $this->set_upload_message('error', 'Transaction ID is required but could not be extracted from receipt');
                    return;
                }
            }
            
            // NOW upload the file after OCR validation passes
            $upload_result = $this->upload_receipt_file($_FILES['payment_receipt'], $order_id);
            if (!$upload_result['success']) {
                $this->set_upload_message('error', $upload_result['message']);
                return;
            }
            
            // Save payment info with enhanced OCR data
            $payment_data = array(
                'order_id' => $order_id,
                'customer_email' => $customer_email,
                'transaction_id' => $transaction_id,
                'extracted_transaction_id' => $extracted_transaction_id,
                'amount' => $amount,
                'receipt_url' => $upload_result['url'],
                'upload_time' => current_time('mysql'),
                'payment_method' => 'upload',
                'verification_status' => $ocr_validated ? 'verified' : 'pending',
                'auto_extracted' => !empty($extracted_transaction_id)
            );
            
            // Add OCR data if available
            if ($ocr_validated) {
                $payment_data['ocr_validation'] = $ocr_data['validation'];
                $payment_data['ocr_confidence'] = $ocr_data['confidence'];
                $payment_data['ocr_message'] = $ocr_data['ocr_message'];
                $payment_data['auto_approved_by_ocr'] = true;
            }
            
            // Save to database or options
            $this->save_payment_data($payment_data);
            
            // Enhanced admin notification
            $this->notify_admin($order_id, $amount, $customer_email, $upload_result['url'], $payment_data);
            
            // Store verification status and preserve form data
            if (!session_id()) {
                session_start();
            }
            $_SESSION['ssfood4u_payment_verified'] = $order_id;
            $_SESSION['ssfood4u_ocr_validated'] = $ocr_validated;
            $_SESSION['ssfood4u_extracted_transaction_id'] = $extracted_transaction_id;
            
            // Preserve the customer's form data
            $_SESSION['ssfood4u_preserved_data'] = array(
                'billing_address_1' => sanitize_text_field($_POST['billing_address_1'] ?? ''),
                'billing_first_name' => sanitize_text_field($_POST['billing_first_name'] ?? ''),
                'billing_last_name' => sanitize_text_field($_POST['billing_last_name'] ?? ''),
                'billing_email' => sanitize_email($_POST['billing_email'] ?? $customer_email),
                'billing_phone' => sanitize_text_field($_POST['billing_phone'] ?? ''),
                'billing_city' => sanitize_text_field($_POST['billing_city'] ?? ''),
                'billing_postcode' => sanitize_text_field($_POST['billing_postcode'] ?? ''),
                'order_comments' => sanitize_textarea_field($_POST['order_comments'] ?? '')
            );
            
            // Set success message
            $this->set_upload_message($ocr_validated ? 'success' : 'warning', $success_message);
            
            // Redirect to checkout with success parameter and preserve form data
            $redirect_params = array(
                'payment_uploaded' => '1',
                'ocr_validated' => ($ocr_validated ? '1' : '0'),
                'auto_transaction_id' => (!empty($extracted_transaction_id) ? '1' : '0'),
                'billing_first_name' => urlencode($_POST['billing_first_name'] ?? ''),
                'billing_last_name' => urlencode($_POST['billing_last_name'] ?? ''),
                'billing_address_1' => urlencode($_POST['billing_address_1'] ?? ''),
                'billing_room_no' => urlencode($_POST['billing_room_no'] ?? ''),
                'billing_email' => urlencode($_POST['billing_email'] ?? $customer_email),
                'billing_phone' => urlencode($_POST['billing_phone'] ?? ''),
                'order_comments' => urlencode($_POST['order_comments'] ?? '')
            );
            
            $redirect_url = add_query_arg($redirect_params, wc_get_checkout_url());
            
            wp_redirect($redirect_url);
            exit;
            
        } catch (Exception $e) {
            error_log('Payment upload error: ' . $e->getMessage());
            $this->set_upload_message('error', 'An error occurred. Please try again.');
        }
    }
    
    /**
     * Add enhanced payment verification UI with dual language support and fixed file handling
     */
    public function add_payment_verification_ui() {
        if (!is_checkout()) return;
        
        $plugin_url = SSFOOD4U_PLUGIN_URL;
        $bank_name = get_option('ssfood4u_bank_name', 'Maybank');
        $account_number = get_option('ssfood4u_account_number', '1234567890');
        $account_holder = get_option('ssfood4u_account_holder', 'SSFood4U Sdn Bhd');
        $auto_extract_enabled = (get_option('ssfood4u_ocr_auto_extract_transaction', 'yes') === 'yes');
        
        // Get any upload messages
        $upload_message = get_transient('ssfood4u_upload_message');
        $upload_status = get_transient('ssfood4u_upload_status');
        
        // Clear the message after displaying
        if ($upload_message) {
            delete_transient('ssfood4u_upload_message');
            delete_transient('ssfood4u_upload_status');
        }
        
        ?>
        <script>
        // Enhanced Payment Verification with Dual Language Support and Fixed File Upload
        (function() {
            'use strict';
            
            console.log('Payment Verification with Dual Language Support - Fixed Version');
            
            var initPaymentVerification = function() {
                try {
                    if (typeof jQuery === 'undefined') {
                        setTimeout(initPaymentVerification, 2000);
                        return;
                    }
                    
                    var $ = jQuery;
                    
                    if ($('.woocommerce-checkout-review-order').length === 0 && $('#order_review').length === 0) {
                        setTimeout(initPaymentVerification, 1000);
                        return;
                    }
                    
                    var paymentVerification = {
                        pluginUrl: '<?php echo esc_js($plugin_url); ?>',
                        bankName: '<?php echo esc_js($bank_name); ?>',
                        accountNumber: '<?php echo esc_js($account_number); ?>',
                        accountHolder: '<?php echo esc_js($account_holder); ?>',
                        uploadMessage: '<?php echo esc_js($upload_message); ?>',
                        uploadStatus: '<?php echo esc_js($upload_status); ?>',
                        autoExtractEnabled: <?php echo $auto_extract_enabled ? 'true' : 'false'; ?>,
                        isInitialized: false,
                        fileChangeTimeout: null,
                        isSubmitting: false,
                        
                        init: function() {
                            if (this.isInitialized) return;
                            
                            // ALWAYS disable Place Order button first
                            this.disablePlaceOrder();
                            
                            this.restoreFormData();
                            this.addPaymentSection();
                            this.bindEvents();
                            this.handleUploadResult();
                            this.isInitialized = true;
                            console.log('Enhanced payment verification initialized - Place Order disabled until validation');
                        },
                        
                        restoreFormData: function() {
                            if (window.location.href.includes('payment_uploaded=1')) {
                                console.log('Restoring form data after payment upload...');
                            }
                        },
                        
                        addPaymentSection: function() {
                            $('#ssfood4u-payment-verification').remove();
                            
                            var cartTotal = $('.order-total .woocommerce-Price-amount').text();
                            var amount = cartTotal ? cartTotal.replace('RM', '').replace(',', '').trim() : '0.00';
                            var orderId = 'ORD-' + Date.now();
                            
                            var uploadFormAction = window.location.href;
                            
                            // Auto-extraction status display (no manual input needed)
                            var transactionIdSection = this.autoExtractEnabled ? 
                                `<div id="transaction-extract-status" style="margin: 10px 0; padding: 10px; background: #e8f4fd; border: 1px solid #bee5eb; border-radius: 4px; display: none;">
                                    <div id="extract-progress" style="display: none;">
                                        <span style="color: #0c5460;">Analyzing receipt and extracting transaction ID...</span>
                                    </div>
                                    <div id="extract-success" style="display: none; color: #155724;">
                                        Transaction ID detected: <strong><span id="detected-transaction-id"></span></strong>
                                    </div>
                                    <div id="extract-error" style="display: none; color: #721c24;">
                                        Could not auto-extract transaction ID. Receipt may be unclear.
                                    </div>
                                </div>
                                <input type="hidden" name="transaction_id" id="hidden-transaction-id" value="">` :
                                `<div style="margin: 10px 0;">
                                    <label><strong>Transaction ID / Reference Number:</strong></label><br>
                                    <input type="text" name="transaction_id" required 
                                           placeholder="Enter transaction ID from your receipt" 
                                           style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>`;
                            
                            var paymentHtml = `
                                <div id="ssfood4u-payment-verification" style="margin: 20px 0; padding: 20px; border: 2px solid #e74c3c; border-radius: 8px; background: #fdf2f2;">
                                    <h3 style="color: #e74c3c; margin-top: 0;">Payment Required</h3>
                                    <p><strong>Please complete payment before placing your order.</strong></p>
                                    
                                    <!-- Payment Instructions -->
                                    <div style="text-align: center; padding: 15px; border: 2px dashed #3498db; margin: 15px 0; background: #f8f9fa; border-radius: 8px;">
                                        <h4>Pay by QR Code or Bank Transfer</h4>
                                        
                                        <div style="background: #fff; padding: 20px; display: inline-block; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                            <img src="${this.pluginUrl}assets/ssfood4u-payment-qr.png" 
                                                 alt="Payment QR Code" 
                                                 style="width: 200px; height: 200px; border: 1px solid #eee;"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div style="width: 200px; height: 200px; background: #f0f0f0; border: 1px solid #ddd; display: none; align-items: center; justify-content: center; font-size: 12px; text-align: center; flex-direction: column;">
                                                <div>QR CODE</div>
                                                <div><strong>RM ${amount}</strong></div>
                                                <div>Order: ${orderId}</div>
                                                <small style="margin-top: 10px; color: #666;">Upload QR code to:<br>/assets/ssfood4u-payment-qr.png</small>
                                            </div>
                                        </div>
                                        
                                        <div style="margin-top: 15px;">
                                            <p><strong>Amount to Pay / 应付金额：RM ${amount}</strong></p>
                                            <p>Order Reference: <strong>${orderId}</strong></p>
                                            
                                            <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 15px 0; border-radius: 4px;">
                                                <strong>Bank Transfer Details:</strong><br>
                                                <div style="text-align: left; margin-top: 10px; font-size: 14px;">
                                                    <strong>Bank:</strong> ${this.bankName}<br>
                                                    <strong>Account:</strong> ${this.accountNumber}<br>
                                                    <strong>Name:</strong> ${this.accountHolder}<br>
                                                    <strong>Reference:</strong> ${orderId}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Enhanced Upload Form with Fixed File Button -->
                                    <form id="payment-upload-form" method="post" enctype="multipart/form-data" action="${uploadFormAction}" style="margin: 20px 0;">
                                        <h4>Upload Your Payment Receipt</h4>
                                        <div style="background: #e3f2fd; border: 1px solid #2196f3; padding: 10px; margin: 10px 0; border-radius: 4px; font-size: 13px;">
                                            <strong>AI Verification:</strong> Your receipt will be automatically validated using OCR technology.${this.autoExtractEnabled ? ' Transaction ID will be detected automatically.' : ''}
                                        </div>
                                        
                                        <div style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #fff;">
                                            <div style="margin: 10px 0;">
                                                <label><strong>Upload Payment Receipt/Screenshot / 上传付款收据/截图:</strong></label><br>
                                                
                                                <!-- Hidden file input -->
                                                <input type="file" name="payment_receipt" id="receipt-file-input" 
                                                       accept="image/*,.pdf" required 
                                                       style="display: none;">
                                                
                                                <!-- Custom styled button -->
                                                <div style="margin: 5px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                                    <button type="button" id="custom-file-btn" 
                                                            style="background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">
                                                        Choose File / 选择文件
                                                    </button>
                                                    <span id="file-name-display" style="color: #666; font-size: 14px;">No file selected / 未选择文件</span>
                                                </div>
                                                
                                                <small><strong>NEW:</strong> Accepts JPG, PNG, PDF files (max 10MB) - Please ensure receipt is clear and readable</small>
                                            </div>
                                            
                                            ${transactionIdSection}
                                            
                                            <!-- Hidden Fields -->
                                            <input type="hidden" name="order_id" value="${orderId}">
                                            <input type="hidden" name="amount" value="${amount}">
                                            <input type="hidden" name="customer_email" value="">
                                            <input type="hidden" name="ssfood4u_upload_receipt" value="1">
                                            <?php wp_nonce_field('ssfood4u_payment_nonce', 'ssfood4u_nonce'); ?>
                                            
                                            <button type="submit" id="upload-receipt-btn" disabled style="background: #ccc; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: not-allowed; font-weight: bold; width: 100%;">
                                                Select Receipt File First / 请先选择收据文件
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <div id="verification-status" style="margin: 15px 0;"></div>
                                </div>
                            `;
                            
                            if ($('.woocommerce-checkout-review-order').length > 0) {
                                $('.woocommerce-checkout-review-order').after(paymentHtml);
                            } else if ($('#order_review').length > 0) {
                                $('#order_review').after(paymentHtml);
                            } else {
                                $('body').append(paymentHtml);
                            }
                        },
                        
                        bindEvents: function() {
                            var self = this;
                            
                            // Remove any existing event handlers first to prevent duplicates
                            $(document).off('click.ssfood4u', '#custom-file-btn');
                            $(document).off('change.ssfood4u', '#receipt-file-input');
                            $(document).off('submit.ssfood4u', '#payment-upload-form');
                            $(document).off('click.ssfood4u_order', '#place_order');
                            
                            // Custom file button click handler with namespace
                            $(document).on('click.ssfood4u', '#custom-file-btn', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                console.log('Custom file button clicked');
                                $('#receipt-file-input').trigger('click');
                            });
                            
                            // File selection handling with proper event management and debouncing
                            $(document).on('change.ssfood4u', '#receipt-file-input', function(e) {
                                e.stopPropagation();
                                
                                console.log('File input changed, files:', this.files.length);
                                
                                // Prevent multiple rapid fire events
                                if (self.fileChangeTimeout) {
                                    clearTimeout(self.fileChangeTimeout);
                                }
                                
                                self.fileChangeTimeout = setTimeout(function() {
                                    const fileName = e.target.files[0] ? e.target.files[0].name : '';
                                    console.log('Processing file:', fileName);
                                    
                                    if (fileName) {
                                        $('#file-name-display').text(fileName);
                                        $('#custom-file-btn').css('background', '#28a745'); // Green when file selected
                                        
                                        // Validate file type
                                        if (e.target.files.length > 0) {
                                            var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/bmp', 'application/pdf'];
                                            var file = e.target.files[0];
                                            
                                            console.log('File type:', file.type, 'Size:', file.size);
                                            
                                            if (allowedTypes.includes(file.type)) {
                                                self.enableUploadButton();
                                                if (self.autoExtractEnabled) {
                                                    self.showTransactionStatus('info', 'Receipt selected. Transaction ID will be extracted during upload.');
                                                }
                                            } else {
                                                console.log('Invalid file type:', file.type);
                                                self.resetUploadButton();
                                                self.showStatus('error', 'Invalid file type. Please use JPG, PNG, or PDF files.');
                                                // Clear the file input
                                                $('#receipt-file-input').val('');
                                                $('#file-name-display').text('No file selected / 未选择文件');
                                                $('#custom-file-btn').css('background', '#007cba');
                                            }
                                        }
                                    } else {
                                        $('#file-name-display').text('No file selected / 未选择文件');
                                        $('#custom-file-btn').css('background', '#007cba'); // Back to original color
                                        self.resetUploadButton();
                                    }
                                }, 150); // Increased delay to prevent rapid fire
                            });
                            
                            // Form submission handler with namespace and double-submission prevention
                            $(document).on('submit.ssfood4u', '#payment-upload-form', function(e) {
                                console.log('Form submission started');
                                
                                // Prevent double submission
                                if (self.isSubmitting) {
                                    console.log('Form already submitting, preventing duplicate');
                                    e.preventDefault();
                                    return false;
                                }
                                
                                // Validate file is selected
                                var fileInput = $('#receipt-file-input')[0];
                                if (!fileInput.files || fileInput.files.length === 0) {
                                    e.preventDefault();
                                    self.showStatus('error', 'Please select a file first');
                                    return false;
                                }
                                
                                self.isSubmitting = true;
                                
                                var email = $('#billing_email').val() || 'not-provided';
                                $('input[name="customer_email"]').val(email);
                                
                                // Capture all current form data
                                var currentFormData = {
                                    billing_address_1: $('#billing_address_1').val(),
                                    billing_first_name: $('#billing_first_name').val(),
                                    billing_last_name: $('#billing_last_name').val(),
                                    billing_email: $('#billing_email').val(),
                                    billing_phone: $('#billing_phone').val(),
                                    billing_city: $('#billing_city').val(),
                                    billing_postcode: $('#billing_postcode').val(),
                                    order_comments: $('#order_comments').val()
                                };
                                
                                // Add form data as hidden fields
                                var form = this;
                                Object.keys(currentFormData).forEach(function(key) {
                                    if (currentFormData[key]) {
                                        // Remove existing hidden field if present
                                        $(form).find('input[name="' + key + '"]').remove();
                                        // Add new hidden field
                                        $(form).append('<input type="hidden" name="' + key + '" value="' + currentFormData[key] + '">');
                                    }
                                });
                                
                                // Show loading state
                                $(this).find('button[type="submit"]').text('Validating Payment...').prop('disabled', true);
                                
                                // Allow form to submit normally
                                console.log('Form submitting with file:', fileInput.files[0].name);
                            });
                            
                            // Handle place order button with namespace
                            $(document).on('click.ssfood4u_order', '#place_order', function(e) {
                                if ($(this).prop('disabled') && $(this).text().indexOf('Verification') !== -1) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    self.showStatus('error', 'Please upload and verify your payment receipt first!');
                                    $('html, body').animate({
                                        scrollTop: $('#ssfood4u-payment-verification').offset().top - 100
                                    }, 500);
                                    return false;
                                }
                            });
                        },
                        
                        enableUploadButton: function() {
                            $('#upload-receipt-btn').prop('disabled', false)
                                .text('Upload & Verify Receipt / 上传并验证收据')
                                .css({
                                    'background': '#27ae60',
                                    'cursor': 'pointer'
                                });
                        },
                        
                        resetUploadButton: function() {
                            $('#upload-receipt-btn').prop('disabled', true)
                                .text('Select Receipt File First / 请先选择收据文件')
                                .css({
                                    'background': '#ccc',
                                    'cursor': 'not-allowed'
                                });
                        },
                        
                        showTransactionStatus: function(type, message) {
                            var statusDiv = $('#transaction-extract-status');
                            if (statusDiv.length === 0) return;
                            
                            statusDiv.show();
                            statusDiv.find('div').hide();
                            
                            if (type === 'info') {
                                statusDiv.find('#extract-progress').show().find('span').text(message);
                            } else if (type === 'success') {
                                statusDiv.find('#extract-success').show();
                            } else if (type === 'error') {
                                statusDiv.find('#extract-error').show();
                            }
                        },
                        
                        handleUploadResult: function() {
                            if (this.uploadMessage) {
                                this.showStatus(this.uploadStatus, this.uploadMessage);
                                
                                if (this.uploadStatus === 'success') {
                                    // Check if OCR validation was actually successful
                                    var ocrValidated = window.location.href.includes('ocr_validated=1');
                                    
                                    if (ocrValidated) {
                                        // Enable place order button only if OCR validated successfully
                                        this.enablePlaceOrder();
                                        
                                        // Update upload button
                                        $('#payment-upload-form button').text('Payment Verified by AI').css('background', '#27ae60').prop('disabled', true);
                                        
                                        // Hide the upload form
                                        $('#payment-upload-form').fadeOut();
                                        
                                        // Show completion message with auto transaction ID info
                                        var completionMessage = `
                                            <div style="background: #d5f4e6; border: 1px solid #27ae60; padding: 15px; margin: 15px 0; border-radius: 4px; text-align: center;">
                                                <h4 style="color: #27ae60; margin: 0 0 10px 0;">Payment Automatically Verified!</h4>
                                                <p style="margin: 0; color: #155724;">
                                                    Our AI system has successfully verified your payment amount.`;
                                        
                                        var autoTransactionId = window.location.href.includes('auto_transaction_id=1');
                                        if (autoTransactionId) {
                                            completionMessage += `<br><strong>Transaction ID automatically detected and verified!</strong>`;
                                        }
                                        
                                        completionMessage += `<br><strong>You can now place your order!</strong>
                                                </p>
                                            </div>
                                        `;
                                        
                                        $('#ssfood4u-payment-verification').append(completionMessage);
                                        
                                        // Scroll to place order button
                                        setTimeout(function() {
                                            $('html, body').animate({
                                                scrollTop: $('#place_order').offset().top - 100
                                            }, 1000);
                                        }, 1000);
                                        
                                    } else {
                                        // Receipt uploaded but OCR validation failed - do NOT enable Place Order
                                        this.showValidationFailed();
                                        this.disablePlaceOrder(); // Explicitly keep disabled
                                    }
                                    
                                } else if (this.uploadStatus === 'error') {
                                    // Show error and keep Place Order disabled
                                    this.disablePlaceOrder();
                                    $('#payment-upload-form button').text('Try Again / 再试一次').css('background', '#e74c3c').prop('disabled', false);
                                } else if (this.uploadStatus === 'warning') {
                                    // OCR not available, manual review needed - keep Place Order disabled
                                    this.showManualReview();
                                    this.disablePlaceOrder();
                                }
                            }
                        },
                        
                        showValidationFailed: function() {
                            $('#payment-upload-form button').text('Validation Failed').css('background', '#e74c3c').prop('disabled', true);
                            
                            $('#ssfood4u-payment-verification').append(`
                                <div style="background: #fdf2f2; border: 1px solid #e74c3c; padding: 15px; margin: 15px 0; border-radius: 4px; text-align: center;">
                                    <h4 style="color: #e74c3c; margin: 0 0 10px 0;">Payment Verification Failed</h4>
                                    <p style="margin: 0; color: #721c24;">
                                        The payment amount on your receipt could not be verified automatically.<br>
                                        <strong>Please upload a clearer receipt or contact support.</strong>
                                    </p>
                                    <div style="margin-top: 15px;">
                                        <button onclick="location.reload()" style="background: #007cba; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;">
                                            Upload Different Receipt
                                        </button>
                                    </div>
                                </div>
                            `);
                            
                            // Ensure Place Order stays disabled
                            this.disablePlaceOrder();
                        },
                        
                        showManualReview: function() {
                            $('#payment-upload-form button').text('Manual Review Required').css('background', '#ff9800').prop('disabled', true);
                            
                            $('#ssfood4u-payment-verification').append(`
                                <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 15px 0; border-radius: 4px; text-align: center;">
                                    <h4 style="color: #856404; margin: 0 0 10px 0;">Manual Review Required</h4>
                                    <p style="margin: 0; color: #856404;">
                                        Your receipt has been uploaded but requires manual verification.<br>
                                        <strong>Please wait for admin approval before placing your order.</strong>
                                    </p>
                                </div>
                            `);
                            
                            this.disablePlaceOrder();
                        },
                        
                        disablePlaceOrder: function() {
                            $('#place_order').prop('disabled', true).text('Payment Verification Required / 需要付款验证').css({
                                'background-color': '#e74c3c !important',
                                'cursor': 'not-allowed'
                            });
                        },
                        
                        enablePlaceOrder: function() {
                            console.log('Enabling Place Order button after OCR validation');
                            
                            // Set global flag for landmark system
                            window.SSFood4U_PaymentVerified = true;
                            
                            $('#place_order').prop('disabled', false)
                                .text('Place Order')
                                .css({
                                    'background-color': '#27ae60 !important',
                                    'cursor': 'pointer',
                                    'opacity': '1'
                                })
                                .removeClass('disabled');
                                
                            // Force enable by removing any disabled classes
                            setTimeout(function() {
                                $('#place_order').prop('disabled', false).removeClass('disabled');
                            }, 100);
                        },
                        
                        showStatus: function(type, message) {
                            var colors = {
                                'success': '#27ae60',
                                'error': '#e74c3c',
                                'warning': '#ff9800',
                                'info': '#3498db'
                            };
                            
                            $('#verification-status').html(message).css({
                                'color': colors[type] || '#333',
                                'background': type === 'success' ? '#d5f4e6' : (type === 'error' ? '#fdf2f2' : (type === 'warning' ? '#fff3cd' : '#e8f4fd')),
                                'border': '1px solid ' + (colors[type] || '#ddd'),
                                'padding': '12px',
                                'border-radius': '4px',
                                'display': 'block'
                            });
                        }
                    };
                    
                    paymentVerification.init();
                    window.SSFood4U_PaymentVerification = paymentVerification;
                    
                } catch (error) {
                    console.error('Error in payment verification:', error);
                    setTimeout(initPaymentVerification, 5000);
                }
            };
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPaymentVerification);
            } else {
                initPaymentVerification();
            }
            
            setTimeout(initPaymentVerification, 2000);
            
        })();
        </script>
        <?php
    }
    
    /**
     * Add dual language fixes for English input field
     */
    public function add_dual_language_fixes() {
        if (!is_checkout()) return;
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            console.log('Dual language form fixes loading...');
            
            // Simple function to enable English input field only
            function enableEnglishInput() {
                var englishSelectors = [
                    '#working-english-output',        // Your actual field ID
                    'input[name="english_name"]', 
                    '.english input[type="text"]',
                    '.input-group:last-child input[type="text"]',
                    '.working-input-field'            // Your actual field class
                ];
                
                englishSelectors.forEach(function(selector) {
                    $(selector).each(function() {
                        console.log('Found English field with selector:', selector);
                        $(this).prop('disabled', false)
                               .prop('readonly', false)
                               .removeAttr('disabled')
                               .removeAttr('readonly')
                               .css({
                                   'background-color': 'white',
                                   'color': '#333',
                                   'cursor': 'text'
                               });
                    });
                });
            }
            
            // Function to change address label
            function changeAddressLabel() {
                $('label[for="billing_address_1"]').each(function() {
                    var currentText = $(this).text();
                    if (currentText.includes('Street address') || currentText.includes('Address')) {
                        $(this).text('Hotel/Homestay/BnB or Address *');
                    }
                });
            }
            
            // Run immediately and after small delays
            enableEnglishInput();
            changeAddressLabel();

            setTimeout(function() {
                enableEnglishInput();
                changeAddressLabel();
            }, 500);
            
            setTimeout(function() {
                enableEnglishInput();
                changeAddressLabel();
            }, 2000);
        });
        </script>
        <?php
    }
    
    /**
     * Upload file with enhanced validation including PDF support
     */
    private function upload_receipt_file($file, $order_id) {
        // Enhanced file type validation including PDF
        $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/bmp', 'application/pdf');
        
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        } else {
            $mime_type = $file['type'];
        }
        
        if (!in_array($mime_type, $allowed_types)) {
            return array('success' => false, 'message' => 'Only JPG, PNG, and PDF files are allowed');
        }
        
        // Enhanced file size validation (10MB for PDFs, 5MB for images)
        $max_size = ($mime_type === 'application/pdf') ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            $max_mb = ($mime_type === 'application/pdf') ? '10MB' : '5MB';
            return array('success' => false, 'message' => 'File size must be less than ' . $max_mb);
        }
        
        // Create upload directory
        $upload_dir = wp_upload_dir();
        $payment_dir = $upload_dir['basedir'] . '/payment-receipts/';
        
        if (!file_exists($payment_dir)) {
            wp_mkdir_p($payment_dir);
            file_put_contents($payment_dir . '.htaccess', 'Options -Indexes');
            file_put_contents($payment_dir . 'index.php', '<?php // Silence is golden');
        }
        
        // Generate filename with proper extension handling
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (empty($extension)) {
            // Determine extension from MIME type for files without extension
            $mime_to_ext = array(
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg', 
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/bmp' => 'bmp',
                'application/pdf' => 'pdf'
            );
            $extension = $mime_to_ext[$mime_type] ?? 'jpg';
        }
        
        $filename = sanitize_file_name($order_id) . '_' . time() . '.' . $extension;
        $filepath = $payment_dir . $filename;
        
        // Move file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            chmod($filepath, 0644);
            return array(
                'success' => true,
                'url' => $upload_dir['baseurl'] . '/payment-receipts/' . $filename,
                'file_type' => $mime_type
            );
        }
        
        return array('success' => false, 'message' => 'Failed to save file');
    }
    
    /**
     * Save payment data with enhanced transaction ID info
     */
    private function save_payment_data($payment_data) {
        $saved = false;
        
        // Try database first
        if (class_exists('SSFood4U_Database')) {
            try {
                $saved = SSFood4U_Database::save_payment_verification($payment_data);
            } catch (Exception $e) {
                error_log('Database save failed: ' . $e->getMessage());
            }
        }
        
        // Fallback to options
        if (!$saved) {
            $existing = get_option('ssfood4u_payment_verifications', array());
            $existing[] = $payment_data;
            update_option('ssfood4u_payment_verifications', $existing);
        }
    }
    
    /**
     * Enhanced admin notification with OCR and transaction ID details
     */
    private function notify_admin($order_id, $amount, $customer_email, $receipt_url, $payment_data = array()) {
        $admin_email = get_option('admin_email');
        $subject = 'Payment Receipt Processed - ' . get_bloginfo('name');
        $message = "Payment receipt processed with AI verification:\n\n";
        $message .= "Order: {$order_id}\n";
        $message .= "Amount: RM {$amount}\n";
        $message .= "Customer: {$customer_email}\n";
        $message .= "Receipt: {$receipt_url}\n";
        
        // Add transaction ID details
        if (!empty($payment_data['transaction_id'])) {
            $message .= "Transaction ID: " . $payment_data['transaction_id'] . "\n";
        }
        
        if (!empty($payment_data['extracted_transaction_id']) && 
            $payment_data['extracted_transaction_id'] !== $payment_data['transaction_id']) {
            $message .= "Auto-Extracted ID: " . $payment_data['extracted_transaction_id'] . "\n";
        }
        
        if (isset($payment_data['auto_extracted']) && $payment_data['auto_extracted']) {
            $message .= "ID Source: AUTO-EXTRACTED by AI\n";
        }
        
        // Add file type info
        if (strpos($receipt_url, '.pdf') !== false) {
            $message .= "Receipt Type: PDF\n";
        } else {
            $message .= "Receipt Type: Image\n";
        }
        
        $message .= "\n";
        
        // Add OCR validation details if available
        if (isset($payment_data['ocr_validation'])) {
            $message .= "=== AI OCR Validation Results ===\n";
            $message .= "Status: " . ucfirst(str_replace('_', ' ', $payment_data['ocr_validation'])) . "\n";
            $message .= "Confidence: " . $payment_data['ocr_confidence'] . "%\n";
            $message .= "Verification: " . ($payment_data['verification_status'] === 'verified' ? 'AUTO-APPROVED' : 'PENDING REVIEW') . "\n";
            
            if (isset($payment_data['auto_approved_by_ocr']) && $payment_data['auto_approved_by_ocr']) {
                $message .= "Auto-Approval: YES - Payment automatically approved by AI OCR system\n";
            } else {
                $message .= "Auto-Approval: NO - Manual review required\n";
            }
            
            $message .= "OCR Message: " . $payment_data['ocr_message'] . "\n\n";
        }
        
        $message .= "Review in admin panel: " . admin_url('admin.php?page=ssfood4u-payments');
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Set upload message for next page load
     */
    private function set_upload_message($status, $message) {
        set_transient('ssfood4u_upload_message', $message, 60);
        set_transient('ssfood4u_upload_status', $status, 60);
    }
    
    /**
     * Restore form data after successful payment upload with transaction ID info
     */
    public function restore_form_data() {
        if (!is_checkout()) return;
        
        // Check if we have preserved data and payment was uploaded
        if (!session_id()) {
            session_start();
        }
        
        if (isset($_GET['payment_uploaded']) && isset($_SESSION['ssfood4u_preserved_data'])) {
            $preserved_data = $_SESSION['ssfood4u_preserved_data'];
            $extracted_transaction_id = $_SESSION['ssfood4u_extracted_transaction_id'] ?? '';
            
            // Clear the preserved data so it's only used once
            unset($_SESSION['ssfood4u_preserved_data']);
            unset($_SESSION['ssfood4u_extracted_transaction_id']);
            
            ?>
            <script>
            jQuery(document).ready(function($) {
                console.log('Restoring form data after payment upload');
                
                setTimeout(function() {
                    // Restore billing fields
                    if ('<?php echo esc_js($preserved_data['billing_address_1']); ?>') {
                        $('#billing_address_1').val('<?php echo esc_js($preserved_data['billing_address_1']); ?>').trigger('change');
                    }
                    if ('<?php echo esc_js($preserved_data['billing_first_name']); ?>') {
                        $('#billing_first_name').val('<?php echo esc_js($preserved_data['billing_first_name']); ?>');
                    }
                    if ('<?php echo esc_js($preserved_data['billing_last_name']); ?>') {
                        $('#billing_last_name').val('<?php echo esc_js($preserved_data['billing_last_name']); ?>');
                    }
                    if ('<?php echo esc_js($preserved_data['billing_email']); ?>') {
                        $('#billing_email').val('<?php echo esc_js($preserved_data['billing_email']); ?>');
                    }
                    if ('<?php echo esc_js($preserved_data['billing_phone']); ?>') {
                        $('#billing_phone').val('<?php echo esc_js($preserved_data['billing_phone']); ?>');
                    }
                    if ('<?php echo esc_js($preserved_data['billing_city']); ?>') {
                        $('#billing_city').val('<?php echo esc_js($preserved_data['billing_city']); ?>');
                    }
                    if ('<?php echo esc_js($preserved_data['billing_postcode']); ?>') {
                        $('#billing_postcode').val('<?php echo esc_js($preserved_data['billing_postcode']); ?>');
                    }
                    if ('<?php echo esc_js($preserved_data['order_comments']); ?>') {
                        $('#order_comments').val('<?php echo esc_js($preserved_data['order_comments']); ?>');
                    }
                    
                    console.log('Form data restored successfully');
                    
                    // Show extracted transaction ID notification if available
                    <?php if (!empty($extracted_transaction_id)): ?>
                    setTimeout(function() {
                        $('body').prepend(`
                            <div style="position: fixed; top: 20px; right: 20px; background: #d5f4e6; border: 1px solid #27ae60; padding: 15px; border-radius: 8px; z-index: 9999; max-width: 300px;">
                                <h5 style="margin: 0 0 10px 0; color: #27ae60;">AI Auto-Detection</h5>
                                <p style="margin: 0; font-size: 13px; color: #155724;">
                                    Transaction ID automatically detected:<br>
                                    <strong><?php echo esc_js($extracted_transaction_id); ?></strong>
                                </p>
                                <button onclick="this.parentElement.remove()" style="background: none; border: none; float: right; cursor: pointer; margin-top: 5px; font-size: 18px;">×</button>
                            </div>
                        `);
                        
                        // Auto-remove notification after 8 seconds
                        setTimeout(function() {
                            $('[style*="AI Auto-Detection"]').parent().fadeOut();
                        }, 8000);
                    }, 2000);
                    <?php endif; ?>
                    
                    // Trigger address validation after restoration
                    if ($('#billing_address_1').val()) {
                        $('#billing_address_1').trigger('blur');
                    }
                }, 1000);
            });
            </script>
            <?php
        }
    }
    
    /**
     * Handle receipt upload via traditional form
     */
    public function handle_receipt_upload() {
        // This is handled in handle_form_submission()
    }
}

/**
 * Helper function to display payment verification status in admin
 */
function ssfood4u_display_payment_verification_status($payment_data) {
    if (empty($payment_data)) return '';
    
    $html = '<div style="background: #f9f9f9; border-radius: 8px; padding: 15px; margin: 10px 0;">';
    $html .= '<h4>Payment Verification Details</h4>';
    
    // Basic payment info
    $html .= '<p><strong>Amount:</strong> RM ' . number_format($payment_data['amount'], 2) . '</p>';
    $html .= '<p><strong>Status:</strong> ' . ucfirst($payment_data['verification_status'] ?? 'pending') . '</p>';
    
    // Transaction ID info
    if (!empty($payment_data['transaction_id'])) {
        $html .= '<p><strong>Transaction ID:</strong> ' . esc_html($payment_data['transaction_id']);
        if (isset($payment_data['auto_extracted']) && $payment_data['auto_extracted']) {
            $html .= ' <span style="background: #e8f4fd; padding: 2px 6px; border-radius: 3px; font-size: 11px;">AUTO-EXTRACTED</span>';
        }
        $html .= '</p>';
    }
    
    // OCR validation details
    if (isset($payment_data['ocr_validation'])) {
        $confidence_color = $payment_data['ocr_confidence'] >= 85 ? '#27ae60' : 
                           ($payment_data['ocr_confidence'] >= 70 ? '#f39c12' : '#e74c3c');
        
        $html .= '<div style="border-top: 1px solid #ddd; margin-top: 15px; padding-top: 15px;">';
        $html .= '<h5>AI OCR Validation Results</h5>';
        $html .= '<p><strong>Validation:</strong> ' . ucfirst(str_replace('_', ' ', $payment_data['ocr_validation'])) . '</p>';
        $html .= '<p><strong>Confidence:</strong> <span style="color: ' . $confidence_color . '; font-weight: bold;">' . intval($payment_data['ocr_confidence']) . '%</span></p>';
        $html .= '<p><strong>Message:</strong> ' . esc_html($payment_data['ocr_message'] ?? '') . '</p>';
        
        if (isset($payment_data['auto_approved_by_ocr']) && $payment_data['auto_approved_by_ocr']) {
            $html .= '<p style="color: #27ae60;"><strong>Auto-Approved:</strong> Yes - Payment automatically verified by AI</p>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

?>