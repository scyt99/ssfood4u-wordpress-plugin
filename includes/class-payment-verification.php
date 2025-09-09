<?php
/**
 * Enhanced Payment Verification System - Cleaned Version
 * Removed debug statements and simplified event handling
 */

if (!defined('ABSPATH')) exit;

class SSFood4U_Payment_Verification {
    
    private $debug_logger = null;
    
    public function __construct() {
        // Initialize debug logger if available
        if (class_exists('SSFood4U_Debug_Logger')) {
            $this->debug_logger = SSFood4U_Debug_Logger::get_instance();
        }
        
        $this->init_hooks();
    }
    
    private function debug($message, $category = 'PAYMENT') {
        if ($this->debug_logger) {
            $this->debug_logger->log($message, $category);
        }
    }
    
    private function init_hooks() {
        add_action('wp_footer', array($this, 'add_payment_verification_ui'), 999);
        add_action('init', array($this, 'handle_form_submission'));
        add_action('wp_loaded', array($this, 'handle_receipt_upload'));
        add_action('wp_footer', array($this, 'restore_form_data'), 998);
        add_action('wp_footer', array($this, 'add_dual_language_fixes'), 997);
    }
    
    public function handle_form_submission() {
        if (isset($_POST['ssfood4u_upload_receipt']) && isset($_POST['ssfood4u_nonce'])) {
            if (wp_verify_nonce($_POST['ssfood4u_nonce'], 'ssfood4u_payment_nonce')) {
                $this->process_receipt_upload();
            }
        }
    }
    
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
            
            if (!isset($_FILES['payment_receipt']) || $_FILES['payment_receipt']['error'] !== UPLOAD_ERR_OK) {
                $this->set_upload_message('error', 'No valid file uploaded');
                return;
            }
            
            $this->debug("Processing receipt upload for order: {$order_id}, amount: RM{$amount}");
            
            // OCR validation if available
            $ocr_validated = false;
            $ocr_data = array();
            $extracted_transaction_id = null;
            
            if (class_exists('SSFood4U_Enhanced_OCR_Validator')) {
                $ocr_validator = new SSFood4U_Enhanced_OCR_Validator();
                $temp_file_path = $_FILES['payment_receipt']['tmp_name'];
                
                if (!file_exists($temp_file_path)) {
                    $this->set_upload_message('error', 'Temporary file not accessible for validation');
                    return;
                }
                
                $ocr_result = $ocr_validator->validate_receipt_amount($temp_file_path, $amount, $transaction_id);
                
                $this->debug("OCR Result: " . json_encode($ocr_result));
                
                if (!$ocr_result['success']) {
                    $this->set_upload_message('error', 'OCR validation failed: ' . $ocr_result['message']);
                    return;
                }
                
                $extracted_transaction_id = $ocr_result['extracted_transaction_id'] ?? null;
                
                if (empty($transaction_id) && !empty($extracted_transaction_id)) {
                    $transaction_id = $extracted_transaction_id;
                }
                
                $auto_approve_threshold = get_option('ssfood4u_ocr_auto_approve', 85);
                $is_amount_valid = ($ocr_result['validation'] === 'match' || $ocr_result['validation'] === 'close_match');
                $is_confidence_high = ($ocr_result['confidence'] >= $auto_approve_threshold);
                
                if (!$is_amount_valid) {
                    $this->set_upload_message('error', 'Payment amount validation failed: ' . $ocr_result['message']);
                    return;
                }
                
                if (!$is_confidence_high) {
                    $this->set_upload_message('error', 'Payment verification confidence too low (' . $ocr_result['confidence'] . '%). Please ensure your receipt is clear and readable.');
                    return;
                }
                
                $ocr_validated = true;
                $ocr_data = array(
                    'validation' => $ocr_result['validation'],
                    'confidence' => $ocr_result['confidence'],
                    'matched_amount' => $ocr_result['matched_amount'] ?? $amount,
                    'ocr_message' => $ocr_result['message'],
                    'extracted_transaction_id' => $extracted_transaction_id
                );
                
                $success_message = 'Payment verified successfully! OCR confirmed RM' . number_format($ocr_result['matched_amount'], 2) . ' matches your order total with ' . $ocr_result['confidence'] . '% confidence.';
                if (!empty($extracted_transaction_id)) {
                    $success_message .= ' Transaction ID automatically detected: ' . $extracted_transaction_id;
                }
                
            } else {
                $ocr_validated = false;
                $ocr_data = array();
                $success_message = 'Receipt uploaded but OCR validation unavailable. Payment pending manual review.';
                
                if (empty($transaction_id)) {
                    $this->set_upload_message('error', 'Transaction ID is required');
                    return;
                }
            }
            
            if (empty($transaction_id)) {
                $require_transaction = (get_option('ssfood4u_ocr_require_transaction', 'no') === 'yes');
                if ($require_transaction) {
                    $this->set_upload_message('error', 'Transaction ID is required but could not be extracted from receipt');
                    return;
                }
            }
            
            // Upload file after validation
            $upload_result = $this->upload_receipt_file($_FILES['payment_receipt'], $order_id);
            if (!$upload_result['success']) {
                $this->set_upload_message('error', $upload_result['message']);
                return;
            }
            
            // Save payment data
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
            
            if ($ocr_validated) {
                $payment_data['ocr_validation'] = $ocr_data['validation'];
                $payment_data['ocr_confidence'] = $ocr_data['confidence'];
                $payment_data['ocr_message'] = $ocr_data['ocr_message'];
                $payment_data['auto_approved_by_ocr'] = true;
            }
            
            $this->save_payment_data($payment_data);
            $this->notify_admin($order_id, $amount, $customer_email, $upload_result['url'], $payment_data);
            
            // Store verification status
            if (!session_id()) {
                session_start();
            }
            $_SESSION['ssfood4u_payment_verified'] = $order_id;
            $_SESSION['ssfood4u_ocr_validated'] = $ocr_validated;
            $_SESSION['ssfood4u_extracted_transaction_id'] = $extracted_transaction_id;
            
            // Preserve form data
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
            
            $this->set_upload_message($ocr_validated ? 'success' : 'warning', $success_message);
            
            // Redirect with success parameters
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
            $this->debug('Payment upload error: ' . $e->getMessage());
            $this->set_upload_message('error', 'An error occurred. Please try again.');
        }
    }
    
    public function add_payment_verification_ui() {
        if (!is_checkout()) return;
        
        $plugin_url = SSFOOD4U_PLUGIN_URL;
        $bank_name = get_option('ssfood4u_bank_name', 'Maybank');
        $account_number = get_option('ssfood4u_account_number', '1234567890');
        $account_holder = get_option('ssfood4u_account_holder', 'SSFood4U Sdn Bhd');
        $auto_extract_enabled = (get_option('ssfood4u_ocr_auto_extract_transaction', 'yes') === 'yes');
        
        $upload_message = get_transient('ssfood4u_upload_message');
        $upload_status = get_transient('ssfood4u_upload_status');
        
        if ($upload_message) {
            delete_transient('ssfood4u_upload_message');
            delete_transient('ssfood4u_upload_status');
        }
        
        ?>
        <script>
        (function() {
            'use strict';
            
            var initPaymentVerification = function() {
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
                    
                    init: function() {
                        if (this.isInitialized) return;
                        
                        this.disablePlaceOrder();
                        this.restoreFormData();
                        this.addPaymentSection();
                        this.bindEvents();
                        this.handleUploadResult();
                        this.isInitialized = true;
                    },
                    
                    restoreFormData: function() {
                        if (window.location.href.includes('payment_uploaded=1')) {
                            // Form data restoration handled by separate function
                        }
                    },
                    
                    addPaymentSection: function() {
                        $('#ssfood4u-payment-verification').remove();
                        
                        var cartTotal = $('.order-total .woocommerce-Price-amount').text();
                        var amount = cartTotal ? cartTotal.replace('RM', '').replace(',', '').trim() : '0.00';
                        var orderId = 'ORD-' + Date.now();
                        var uploadFormAction = window.location.href;
                        
                        var transactionIdSection = this.autoExtractEnabled ? 
                            '<input type="hidden" name="transaction_id" id="hidden-transaction-id" value="">' :
                            '<div style="margin: 10px 0;"><label><strong>Transaction ID / Reference Number:</strong></label><br><input type="text" name="transaction_id" required placeholder="Enter transaction ID from your receipt" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></div>';
                        
                        var paymentHtml = `
                            <div id="ssfood4u-payment-verification" style="margin: 20px 0; padding: 20px; border: 2px solid #e74c3c; border-radius: 8px; background: #fdf2f2;">
                                <h3 style="color: #e74c3c; margin-top: 0;">Payment Required</h3>
                                <p><strong>Please complete payment before placing your order.</strong></p>
                                
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
                                        <p><strong>Amount to Pay: RM ${amount}</strong></p>
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
                                
                                <form id="payment-upload-form" method="post" enctype="multipart/form-data" action="${uploadFormAction}" style="margin: 20px 0;">
                                    <h4>Upload Your Payment Receipt</h4>
                                    <div style="background: #e3f2fd; border: 1px solid #2196f3; padding: 10px; margin: 10px 0; border-radius: 4px; font-size: 13px;">
                                        <strong>AI Verification:</strong> Your receipt will be automatically validated using OCR technology.${this.autoExtractEnabled ? ' Transaction ID will be detected automatically.' : ''}
                                    </div>
                                    
                                    <div style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #fff;">
                                        <div style="margin: 10px 0;">
                                            <label><strong>Upload Payment Receipt/Screenshot:</strong></label><br>
                                            
                                            <input type="file" name="payment_receipt" id="receipt-file-input" 
                                                   accept="image/*,.pdf" required 
                                                   style="display: none;">
                                            
                                            <div style="margin: 5px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                                <button type="button" id="custom-file-btn" 
                                                        style="background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">
                                                    Choose File
                                                </button>
                                                <span id="file-name-display" style="color: #666; font-size: 14px;">No file selected</span>
                                            </div>
                                            
                                            <small>Accepts JPG, PNG, PDF files (max 10MB) - Please ensure receipt is clear and readable</small>
                                        </div>
                                        
                                        ${transactionIdSection}
                                        
                                        <input type="hidden" name="order_id" value="${orderId}">
                                        <input type="hidden" name="amount" value="${amount}">
                                        <input type="hidden" name="customer_email" value="">
                                        <input type="hidden" name="ssfood4u_upload_receipt" value="1">
                                        <?php wp_nonce_field('ssfood4u_payment_nonce', 'ssfood4u_nonce'); ?>
                                        
                                        <button type="submit" id="upload-receipt-btn" disabled style="background: #ccc; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: not-allowed; font-weight: bold; width: 100%;">
                                            Select Receipt File First
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
                        
                        // Remove existing event handlers
                        $(document).off('click.ssfood4u');
                        $(document).off('change.ssfood4u');
                        $(document).off('submit.ssfood4u');
                        
                        // Custom file button
                        $(document).on('click.ssfood4u', '#custom-file-btn', function(e) {
                            e.preventDefault();
                            $('#receipt-file-input').click();
                        });
                        
                        // File selection
                        $(document).on('change.ssfood4u', '#receipt-file-input', function(e) {
                            var fileName = e.target.files[0] ? e.target.files[0].name : '';
                            
                            if (fileName) {
                                $('#file-name-display').text(fileName);
                                $('#custom-file-btn').css('background', '#28a745');
                                
                                if (e.target.files.length > 0) {
                                    var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/bmp', 'application/pdf'];
                                    var file = e.target.files[0];
                                    
                                    if (allowedTypes.includes(file.type)) {
                                        self.enableUploadButton();
                                    } else {
                                        self.resetUploadButton();
                                        self.showStatus('error', 'Invalid file type. Please use JPG, PNG, or PDF files.');
                                        $('#receipt-file-input').val('');
                                        $('#file-name-display').text('No file selected');
                                        $('#custom-file-btn').css('background', '#007cba');
                                    }
                                }
                            } else {
                                $('#file-name-display').text('No file selected');
                                $('#custom-file-btn').css('background', '#007cba');
                                self.resetUploadButton();
                            }
                        });
                        
                        // Form submission
                        $(document).on('submit.ssfood4u', '#payment-upload-form', function(e) {
                            var fileInput = $('#receipt-file-input')[0];
                            if (!fileInput.files || fileInput.files.length === 0) {
                                e.preventDefault();
                                self.showStatus('error', 'Please select a file first');
                                return false;
                            }
                            
                            var email = $('#billing_email').val() || 'not-provided';
                            $('input[name="customer_email"]').val(email);
                            
                            // Capture form data
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
                            
                            var form = this;
                            Object.keys(currentFormData).forEach(function(key) {
                                if (currentFormData[key]) {
                                    $(form).find('input[name="' + key + '"]').remove();
                                    $(form).append('<input type="hidden" name="' + key + '" value="' + currentFormData[key] + '">');
                                }
                            });
                            
                            $(this).find('button[type="submit"]').text('Validating Payment...').prop('disabled', true);
                        });
                        
                        // Place order button protection
                        $(document).on('click.ssfood4u', '#place_order', function(e) {
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
                            .text('Upload & Verify Receipt')
                            .css({
                                'background': '#27ae60',
                                'cursor': 'pointer'
                            });
                    },
                    
                    resetUploadButton: function() {
                        $('#upload-receipt-btn').prop('disabled', true)
                            .text('Select Receipt File First')
                            .css({
                                'background': '#ccc',
                                'cursor': 'not-allowed'
                            });
                    },
                    
                    handleUploadResult: function() {
                        if (this.uploadMessage) {
                            this.showStatus(this.uploadStatus, this.uploadMessage);
                            
                            if (this.uploadStatus === 'success') {
                                var ocrValidated = window.location.href.includes('ocr_validated=1');
                                
                                if (ocrValidated) {
                                    this.enablePlaceOrder();
                                    $('#payment-upload-form button').text('Payment Verified by AI').css('background', '#27ae60').prop('disabled', true);
                                    $('#payment-upload-form').fadeOut();
                                    
                                    var completionMessage = `
                                        <div style="background: #d5f4e6; border: 1px solid #27ae60; padding: 15px; margin: 15px 0; border-radius: 4px; text-align: center;">
                                            <h4 style="color: #27ae60; margin: 0 0 10px 0;">Payment Automatically Verified!</h4>
                                            <p style="margin: 0; color: #155724;">
                                                Our AI system has successfully verified your payment amount.
                                                <br><strong>You can now place your order!</strong>
                                            </p>
                                        </div>
                                    `;
                                    
                                    $('#ssfood4u-payment-verification').append(completionMessage);
                                    
                                    setTimeout(function() {
                                        $('html, body').animate({
                                            scrollTop: $('#place_order').offset().top - 100
                                        }, 1000);
                                    }, 1000);
                                    
                                } else {
                                    this.disablePlaceOrder();
                                }
                                
                            } else if (this.uploadStatus === 'error') {
                                this.disablePlaceOrder();
                                $('#payment-upload-form button').text('Try Again').css('background', '#e74c3c').prop('disabled', false);
                            } else if (this.uploadStatus === 'warning') {
                                this.disablePlaceOrder();
                            }
                        }
                    },
                    
                    disablePlaceOrder: function() {
                        $('#place_order').prop('disabled', true).text('Payment Verification Required').css({
                            'background-color': '#e74c3c !important',
                            'cursor': 'not-allowed'
                        });
                    },
                    
                    enablePlaceOrder: function() {
                        window.SSFood4U_PaymentVerified = true;
                        
                        $('#place_order').prop('disabled', false)
                            .text('Place Order')
                            .css({
                                'background-color': '#27ae60 !important',
                                'cursor': 'pointer',
                                'opacity': '1'
                            })
                            .removeClass('disabled');
                            
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
            function enableEnglishInput() {
                var englishSelectors = [
                    '#working-english-output',
                    'input[name="english_name"]', 
                    '.english input[type="text"]',
                    '.input-group:last-child input[type="text"]',
                    '.working-input-field'
                ];
                
                englishSelectors.forEach(function(selector) {
                    $(selector).each(function() {
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
            
            function changeAddressLabel() {
                $('label[for="billing_address_1"]').each(function() {
                    var currentText = $(this).text();
                    if (currentText.includes('Street address') || currentText.includes('Address')) {
                        $(this).text('Hotel/Homestay/BnB or Address *');
                    }
                });
            }
            
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
     * Upload file with enhanced validation
     */
    private function upload_receipt_file($file, $order_id) {
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
        
        $max_size = ($mime_type === 'application/pdf') ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            $max_mb = ($mime_type === 'application/pdf') ? '10MB' : '5MB';
            return array('success' => false, 'message' => 'File size must be less than ' . $max_mb);
        }
        
        $upload_dir = wp_upload_dir();
        $payment_dir = $upload_dir['basedir'] . '/payment-receipts/';
        
        if (!file_exists($payment_dir)) {
            wp_mkdir_p($payment_dir);
            file_put_contents($payment_dir . '.htaccess', 'Options -Indexes');
            file_put_contents($payment_dir . 'index.php', '<?php // Silence is golden');
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (empty($extension)) {
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
     * Save payment data
     */
    private function save_payment_data($payment_data) {
        $saved = false;
        
        if (class_exists('SSFood4U_Database')) {
            try {
                $saved = SSFood4U_Database::save_payment_verification($payment_data);
            } catch (Exception $e) {
                $this->debug('Database save failed: ' . $e->getMessage());
            }
        }
        
        if (!$saved) {
            $existing = get_option('ssfood4u_payment_verifications', array());
            $existing[] = $payment_data;
            update_option('ssfood4u_payment_verifications', $existing);
        }
    }
    
    /**
     * Enhanced admin notification
     */
    private function notify_admin($order_id, $amount, $customer_email, $receipt_url, $payment_data = array()) {
        $admin_email = get_option('admin_email');
        $subject = 'Payment Receipt Processed - ' . get_bloginfo('name');
        $message = "Payment receipt processed with AI verification:\n\n";
        $message .= "Order: {$order_id}\n";
        $message .= "Amount: RM {$amount}\n";
        $message .= "Customer: {$customer_email}\n";
        $message .= "Receipt: {$receipt_url}\n";
        
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
        
        if (strpos($receipt_url, '.pdf') !== false) {
            $message .= "Receipt Type: PDF\n";
        } else {
            $message .= "Receipt Type: Image\n";
        }
        
        $message .= "\n";
        
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
     * Restore form data after successful payment upload
     */
    public function restore_form_data() {
        if (!is_checkout()) return;
        
        if (!session_id()) {
            session_start();
        }
        
        if (isset($_GET['payment_uploaded']) && isset($_SESSION['ssfood4u_preserved_data'])) {
            $preserved_data = $_SESSION['ssfood4u_preserved_data'];
            $extracted_transaction_id = $_SESSION['ssfood4u_extracted_transaction_id'] ?? '';
            
            unset($_SESSION['ssfood4u_preserved_data']);
            unset($_SESSION['ssfood4u_extracted_transaction_id']);
            
            ?>
            <script>
            jQuery(document).ready(function($) {
                setTimeout(function() {
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
                        
                        setTimeout(function() {
                            $('[style*="AI Auto-Detection"]').parent().fadeOut();
                        }, 8000);
                    }, 2000);
                    <?php endif; ?>
                    
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
?>