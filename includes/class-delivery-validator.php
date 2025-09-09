<?php
/**
 * SSFood4U Cleaned Delivery Validator
 * File: class-delivery-validator-enhanced.php
 * Version: Cleaned - All debug moved to central logger
 */

if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

class SSFood4U_Working_Delivery_Validator {
    
    private $debug_logger = null;
    
    public function __construct() {
        // Initialize debug logger if available
        if (class_exists('SSFood4U_Debug_Logger')) {
            $this->debug_logger = SSFood4U_Debug_Logger::get_instance();
        }
        
        $this->init_hooks();
    }
    
    private function debug($message, $category = 'DELIVERY') {
        if ($this->debug_logger) {
            $this->debug_logger->log($message, $category);
        }
    }
    
    private function init_hooks() {
        add_action('wp_footer', array($this, 'add_chinese_input_system'), 9999);
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_delivery'));
        add_action('wp_ajax_validate_delivery_address', array($this, 'ajax_validate_delivery'));
        add_action('wp_ajax_nopriv_validate_delivery_address', array($this, 'ajax_validate_delivery'));
        add_filter('woocommerce_checkout_fields', array($this, 'reorder_checkout_fields'));
    }
    
    public function reorder_checkout_fields($fields) {
        if (isset($fields['billing']['billing_first_name'])) {
            $fields['billing']['billing_first_name']['priority'] = 60;
            $fields['billing']['billing_first_name']['class'] = array('form-row-first');
            $fields['billing']['billing_first_name']['label'] = 'First name';
        }
        
        if (isset($fields['billing']['billing_last_name'])) {
            $fields['billing']['billing_last_name']['priority'] = 70;
            $fields['billing']['billing_last_name']['class'] = array('form-row-last');
            $fields['billing']['billing_last_name']['label'] = 'Last name';
        }
        
        if (isset($fields['billing']['billing_address_1'])) {
            $fields['billing']['billing_address_1']['priority'] = 30;
            $fields['billing']['billing_address_1']['class'] = array('form-row-wide');
            $fields['billing']['billing_address_1']['label'] = 'Hotel/Homestay/BnB or Street address';
        }
        
        if (isset($fields['billing']['billing_address_2'])) {
            $fields['billing']['billing_address_2']['required'] = false;
            $fields['billing']['billing_address_2']['class'] = array('form-row-wide', 'hidden-field');
            $fields['billing']['billing_address_2']['priority'] = 999;
        }
        
        if (!isset($fields['billing']['billing_room_no'])) {
            $fields['billing']['billing_room_no'] = array(
                'label' => 'Room Number',
                'placeholder' => 'Enter room number',
                'required' => true,
                'class' => array('form-row-wide'),
                'priority' => 40,
                'type' => 'text'
            );
        } else {
            $fields['billing']['billing_room_no']['priority'] = 40;
            $fields['billing']['billing_room_no']['class'] = array('form-row-wide');
            $fields['billing']['billing_room_no']['label'] = 'Room Number';
        }
        
        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['priority'] = 80;
            $fields['billing']['billing_email']['class'] = array('form-row-wide');
        }
        
        if (isset($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone']['priority'] = 90;
            $fields['billing']['billing_phone']['class'] = array('form-row-wide');
        }
        
        return $fields;
    }
    
    public function ajax_validate_delivery() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'delivery_validation_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        $address = sanitize_text_field($_POST['address'] ?? '');
        $result = $this->validate_delivery_address($address);
        
        wp_send_json_success(array(
            'valid' => $result['valid'],
            'message' => $result['message'],
            'area_detected' => $result['area_detected']
        ));
    }
    
    public function add_chinese_input_system() {
        if (!is_checkout()) return;
        
        $nonce = wp_create_nonce('delivery_validation_nonce');
        
        $hotel_translations = array();
        if (class_exists('SSFood4U_Hotel_Translation_DB')) {
            global $ssfood4u_hotel_db;
            $hotels = $ssfood4u_hotel_db->get_all_hotels();
            foreach ($hotels as $hotel) {
                $hotel_translations[$hotel->chinese_name] = strtolower($hotel->english_name);
            }
        }
        ?>
        
        <style>
        #working-chinese-interface {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            margin-bottom: 20px !important;
            padding: 20px !important;
            background: #f8f9fa !important;
            border: 1px solid #e9ecef !important;
            border-radius: 8px !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
            clear: both !important;
        }
        
        .working-flag-header {
            margin-bottom: 15px !important;
        }
        
        .working-chinese-badge {
            background: #dc3545 !important;
            color: white !important;
            padding: 4px 8px !important;
            border-radius: 4px !important;
            font-size: 14px !important;
            font-weight: bold !important;
            margin-right: 10px !important;
        }
        
        .working-english-badge {
            background: #007cba !important;
            color: white !important;
            padding: 4px 8px !important;
            border-radius: 4px !important;
            font-size: 14px !important;
            font-weight: bold !important;
        }
        
        .working-instructions {
            margin-bottom: 15px !important;
            color: #666 !important;
            font-size: 14px !important;
        }
        
        .working-input-row {
            display: flex !important;
            gap: 15px !important;
            margin-bottom: 10px !important;
            flex-wrap: wrap !important;
        }
        
        .working-input-col {
            flex: 1 !important;
            min-width: 250px !important;
        }
        
        .working-input-label {
            font-weight: bold !important;
            margin-bottom: 5px !important;
            display: block !important;
            color: #495057 !important;
        }
        
        .working-input-field {
            width: 100% !important;
            padding: 10px !important;
            border: 1px solid #ccc !important;
            border-radius: 4px !important;
            font-size: 14px !important;
            box-sizing: border-box !important;
        }
        
        .working-status {
            margin-top: 10px !important;
            font-size: 13px !important;
        }
        
        .hidden-field {
            display: none !important;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var hotelTranslations = <?php echo json_encode($hotel_translations); ?>;
            var validationNonce = '<?php echo $nonce; ?>';
            
            var workingValidator = {
                init: function() {
                    this.createInterface();
                    this.bindEvents();
                    this.initDeliveryValidation();
                },
                
                createInterface: function() {
                    $('#working-chinese-interface').remove();
                    
                    var interfaceHTML = '<div id="working-chinese-interface">' +
                        '<div class="working-flag-header">' +
                        '<span class="working-chinese-badge">中文输入</span>' +
                        '<span class="working-english-badge">English</span>' +
                        '</div>' +
                        
                        '<div class="working-instructions">' +
                        '<div>仅输入中文或英文的酒店/民宿/BnB名称</div>' +
                        '<div>Input only either the Hotel/Homestay/BnB in Chinese or English</div>' +
                        '</div>' +
                        
                        '<div class="working-input-row">' +
                        '<div class="working-input-col">' +
                        '<label class="working-input-label">Chinese</label>' +
                        '<input type="text" id="working-chinese-input" placeholder="酒店名称" class="working-input-field">' +
                        '</div>' +
                        '<div class="working-input-col">' +
                        '<label class="working-input-label">English</label>' +
                        '<input type="text" id="working-english-output" placeholder="English name will appear here" readonly class="working-input-field" style="background: #f8f9fa; color: #666;">' +
                        '</div>' +
                        '</div>' +
                        
                        '<div id="working-status" class="working-status"></div>' +
                        '</div>';
                    
                    var inserted = false;
                    var methods = [
                        function() { return $('#billing_first_name_field').before(interfaceHTML), $('#billing_first_name_field').length > 0; },
                        function() { return $('.form-row:first').before(interfaceHTML), $('.form-row:first').length > 0; }
                    ];
                    
                    for (var i = 0; i < methods.length && !inserted; i++) {
                        if (methods[i]()) {
                            inserted = true;
                            break;
                        }
                    }
                },
                
                bindEvents: function() {
                    var self = this;
                    
                    // Chinese input handling
                    $(document).on('input', '#working-chinese-input', function() {
                        var chineseText = $(this).val().trim();
                        
                        if (chineseText) {
                            self.translateChinese(chineseText);
                        } else {
                            $('#working-english-output').val('');
                            $('#billing_address_1').val('');
                            $('#working-status').html('');
                        }
                    });
                    
                    // English input handling
                    $(document).on('input', '#working-english-output', function() {
                        var englishText = $(this).val().trim();
                        
                        if (englishText) {
                            $('#billing_address_1').val(englishText);
                            $('#working-status').html('<span style="color: #007cba;">English input: ' + englishText + '</span>');
                            
                            setTimeout(function() {
                                self.triggerDeliveryValidation(englishText);
                            }, 300);
                            
                            $('#billing_address_1').trigger('change');
                            $('body').trigger('update_checkout');
                        } else {
                            $('#billing_address_1').val('');
                            $('#working-status').html('');
                        }
                    });
                },
                
                translateChinese: function(chinese) {
                    var self = this;
                    var found = false;
                    var englishResult = '';
                    
                    for (var key in hotelTranslations) {
                        if (chinese.indexOf(key) !== -1) {
                            englishResult = this.toTitleCase(hotelTranslations[key]);
                            found = true;
                            break;
                        }
                    }
                    
                    if (found) {
                        $('#working-english-output').val(englishResult);
                        $('#billing_address_1').val(englishResult);
                        $('#working-status').html('<span style="color: #28a745;">Translation successful: ' + englishResult + '</span>');
                        
                        setTimeout(function() {
                            self.triggerDeliveryValidation(englishResult);
                        }, 300);
                    } else {
                        $('#working-english-output').val('Hotel not found in database');
                        $('#billing_address_1').val(chinese);
                        $('#working-status').html('<span style="color: #ffc107;">Hotel not found - using original text</span>');
                        
                        setTimeout(function() {
                            self.triggerDeliveryValidation(chinese);
                        }, 300);
                    }
                    
                    $('#billing_address_1').trigger('change');
                    $('body').trigger('update_checkout');
                },
                
                initDeliveryValidation: function() {
                    var self = this;
                    
                    $(document).off('change.deliveryValidation', '#billing_address_1')
                               .on('change.deliveryValidation', '#billing_address_1', function() {
                        var addressValue = $(this).val();
                        if (addressValue && addressValue.trim() !== '') {
                            self.triggerDeliveryValidation(addressValue);
                        }
                    });
                },
                
                triggerDeliveryValidation: function(address) {
                    var self = this;
                    
                    if (!address || address.trim() === '') {
                        return;
                    }
                    
                    $.ajax({
                        url: wc_checkout_params.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'validate_delivery_address',
                            address: address,
                            nonce: validationNonce
                        },
                        success: function(response) {
                            if (response.success) {
                                var result = response.data;
                                
                                var statusColor = result.valid ? '#28a745' : '#dc3545';
                                var statusMessage = result.message;
                                
                                var currentStatus = $('#working-status').html();
                                var validationStatus = '<br><span style="color: ' + statusColor + ';">Delivery: ' + statusMessage + '</span>';
                                
                                if (currentStatus.indexOf('Translation successful') !== -1 || currentStatus.indexOf('Hotel not found') !== -1) {
                                    $('#working-status').html(currentStatus + validationStatus);
                                } else {
                                    $('#working-status').html('<span style="color: ' + statusColor + ';">' + statusMessage + '</span>');
                                }
                                
                                if (!result.valid) {
                                    $('.woocommerce-error, .woocommerce-message').remove();
                                    $('.woocommerce-notices-wrapper').first().html(
                                        '<div class="woocommerce-error" role="alert">' + statusMessage + '</div>'
                                    );
                                } else {
                                    $('.woocommerce-error').remove();
                                }
                            }
                        },
                        error: function(xhr, status, error) {
                            // Silent error handling - no console spam
                        }
                    });
                },
                
                toTitleCase: function(str) {
                    return str.replace(/\w\S*/g, function(txt) {
                        return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
                    });
                }
            };
            
            workingValidator.init();
            
            // Hide apartment field
            $('#billing_address_2_field').hide();
            $('#billing_address_2').val('');
        });
        </script>
        <?php
    }
    
    public function validate_delivery_address($address) {
        $address_lower = strtolower(trim($address));
        
        $result = array(
            'valid' => true,
            'message' => 'Address accepted',
            'area_detected' => 'general'
        );
        
        if (empty($address_lower) || strlen($address_lower) < 3) {
            $this->debug('Validation skipped - address too short: ' . $address);
            return $result;
        }
        
        $valid_areas = array(
            'semporna', 'sabah', 'uptown hotel', 'dragon inn', 
            'seafest hotel', 'grace hotel', 'green world hotel'
        );
        
        $invalid_areas = array(
            'kota kinabalu', 'kk', 'sandakan', 'tawau', 
            'kuala lumpur', 'kl', 'johor', 'penang'
        );
        
        foreach ($invalid_areas as $area) {
            if (strpos($address_lower, $area) !== false) {
                $result['valid'] = false;
                $result['message'] = "Delivery not available to " . $area;
                
                $this->debug('Invalid area detected: ' . $area . ' in address: ' . $address);
                return $result;
            }
        }
        
        foreach ($valid_areas as $area) {
            if (strpos($address_lower, $area) !== false) {
                $result['message'] = "Delivery available to " . $area;
                
                $this->debug('Valid area detected: ' . $area . ' in address: ' . $address);
                return $result;
            }
        }
        
        $this->debug('Address validated as general delivery: ' . $address);
        return $result;
    }
    
    public function validate_checkout_delivery() {
        if (current_user_can('administrator')) {
            return;
        }
        
        $address = isset($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : '';
        
        if (!empty($address)) {
            $validation_result = $this->validate_delivery_address($address);
            
            if (!$validation_result['valid']) {
                wc_add_notice($validation_result['message'], 'error');
            }
        }
    }
}

new SSFood4U_Working_Delivery_Validator();
?>