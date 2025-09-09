<?php
/**
 * SSFood4U Enhanced Working Version - With Real-time Delivery Validation
 * File: class-delivery-validator-enhanced.php
 * Version: Enhanced with validation triggers
 */

if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

class SSFood4U_Working_Delivery_Validator {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_footer', array($this, 'add_chinese_input_system'), 9999);
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_delivery'));
        add_action('wp_ajax_validate_delivery_address', array($this, 'ajax_validate_delivery'));
        add_action('wp_ajax_nopriv_validate_delivery_address', array($this, 'ajax_validate_delivery'));
        add_filter('woocommerce_checkout_fields', array($this, 'reorder_checkout_fields'));
        
        // Add debugging hooks for shipping calculations
        add_action('woocommerce_shipping_init', array($this, 'init_shipping_debug'));
        add_filter('woocommerce_package_rates', array($this, 'debug_shipping_rates'), 10, 2);
        add_action('woocommerce_checkout_update_order_review', array($this, 'debug_shipping_calculation'));
    }
    
    /**
     * Initialize shipping debugging
     */
    public function init_shipping_debug() {
        error_log('=== SHIPPING DEBUG INIT ===');
        error_log('WooCommerce shipping system initialized');
        error_log('========================');
    }
    
    /**
     * Debug shipping calculation process
     */
    public function debug_shipping_calculation($post_data) {
        error_log('=== SHIPPING CALCULATION DEBUG ===');
        
        // Parse the post data to get address
        parse_str($post_data, $data);
        $address = isset($data['billing_address_1']) ? $data['billing_address_1'] : '';
        
        if ($address) {
            error_log('Address being calculated: ' . $address);
            
            // Try to get coordinates using Google Maps API if available
            $this->debug_google_coordinates($address);
            
            // Try to get shipping packages
            $packages = WC()->shipping()->get_packages();
            foreach ($packages as $i => $package) {
                error_log('Package ' . $i . ' destination: ' . print_r($package['destination'], true));
                
                // Get available shipping methods
                $shipping_methods = WC()->shipping()->calculate_shipping($packages);
                foreach ($packages as $package_key => $package) {
                    if (isset($package['rates'])) {
                        foreach ($package['rates'] as $rate_id => $rate) {
                            error_log('Shipping Rate ID: ' . $rate_id);
                            error_log('Shipping Rate Label: ' . $rate->label);
                            error_log('Shipping Rate Cost: RM' . $rate->cost);
                            
                            // Check for the problematic RM11.80
                            if (abs(floatval($rate->cost) - 11.80) < 0.01) {
                                error_log('*** DETECTED RM11.80 - POTENTIAL GOOGLE DEFAULT LOCATION ***');
                                error_log('Rate details: ' . print_r($rate, true));
                            }
                        }
                    }
                }
            }
        }
        
        error_log('=== END SHIPPING CALCULATION DEBUG ===');
    }
    
    /**
     * Debug Google coordinates lookup
     */
    private function debug_google_coordinates($address) {
        error_log('=== GOOGLE COORDINATES DEBUG ===');
        error_log('Looking up coordinates for: ' . $address);
        
        // Try to access Google Maps API key from common WordPress options
        $api_keys = array(
            get_option('google_maps_api_key'),
            get_option('wc_distance_rate_google_api_key'),
            get_option('woocommerce_distance_rate_google_api_key'),
            get_option('distance_rate_google_api_key'),
            defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : null
        );
        
        $api_key = null;
        foreach ($api_keys as $key) {
            if (!empty($key)) {
                $api_key = $key;
                break;
            }
        }
        
        if ($api_key) {
            error_log('Google API key found, attempting coordinate lookup...');
            
            $address_encoded = urlencode($address . ', Semporna, Sabah, Malaysia');
            $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address_encoded}&key={$api_key}";
            
            $response = wp_remote_get($url);
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                error_log('Google API Response Status: ' . $data['status']);
                
                if ($data['status'] == 'OK' && !empty($data['results'])) {
                    $result = $data['results'][0];
                    $lat = $result['geometry']['location']['lat'];
                    $lng = $result['geometry']['location']['lng'];
                    
                    error_log('*** COORDINATES FOUND ***');
                    error_log('Latitude: ' . $lat);
                    error_log('Longitude: ' . $lng);
                    error_log('Formatted Address: ' . $result['formatted_address']);
                    error_log('Place ID: ' . (isset($result['place_id']) ? $result['place_id'] : 'N/A'));
                    
                    // Check if this looks like a default/generic location
                    $location_types = isset($result['types']) ? $result['types'] : array();
                    error_log('Location Types: ' . implode(', ', $location_types));
                    
                    // Flag suspicious coordinates (generic locations)
                    if (in_array('political', $location_types) || in_array('locality', $location_types)) {
                        error_log('*** WARNING: Coordinates may be generic/default location ***');
                    }
                    
                } else {
                    error_log('Google API returned no results or error');
                    error_log('Status: ' . $data['status']);
                    if (isset($data['error_message'])) {
                        error_log('Error: ' . $data['error_message']);
                    }
                }
            } else {
                error_log('WordPress HTTP error: ' . $response->get_error_message());
            }
            
        } else {
            error_log('No Google API key found in common WordPress options');
        }
        
        error_log('=== END GOOGLE COORDINATES DEBUG ===');
    }
    
    /**
     * Debug shipping rates
     */
    public function debug_shipping_rates($rates, $package) {
        error_log('=== SHIPPING RATES DEBUG ===');
        error_log('Package destination: ' . print_r($package['destination'], true));
        
        foreach ($rates as $rate_id => $rate) {
            error_log('Rate ID: ' . $rate_id);
            error_log('Rate Label: ' . $rate->label);
            error_log('Rate Cost: RM' . $rate->cost);
            error_log('Rate Method ID: ' . $rate->method_id);
            
            // Check if this is the distance rate plugin
            if (strpos($rate->method_id, 'distance_rate') !== false) {
                error_log('*** DISTANCE RATE SHIPPING DETECTED ***');
                error_log('Full rate object: ' . print_r($rate, true));
                
                // Check for RM11.80 specifically
                if (abs(floatval($rate->cost) - 11.80) < 0.01) {
                    error_log('*** ALERT: RM11.80 DETECTED - LIKELY GOOGLE DEFAULT LOCATION ***');
                    error_log('Address: ' . (isset($package['destination']['address_1']) ? $package['destination']['address_1'] : 'Unknown'));
                    
                    // Try to get coordinates if available in the rate meta
                    if (isset($rate->meta_data)) {
                        error_log('Rate meta data: ' . print_r($rate->meta_data, true));
                    }
                }
            }
        }
        
        error_log('=== END SHIPPING RATES DEBUG ===');
        
        return $rates;
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
            console.log('Working validator with delivery validation loading...');
            
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
                    
                    if (inserted) {
                        console.log('Working interface created with validation integration');
                    }
                },
                
                bindEvents: function() {
                    var self = this;
                    console.log('=== BINDING EVENTS ===');
                    
                    // Test if jQuery events work at all
                    $(document).on('click', '#working-chinese-input', function() {
                        console.log('Chinese field clicked - events are working');
                    });
                    
                    // Chinese input handling
                    $(document).on('input', '#working-chinese-input', function() {
                        var chineseText = $(this).val().trim();
                        console.log('Chinese input event fired:', chineseText);
                        
                        if (chineseText) {
                            self.translateChinese(chineseText);
                        } else {
                            $('#working-english-output').val('');
                            $('#billing_address_1').val('');
                            $('#working-status').html('');
                        }
                    });
                    
                    // English input handling with extensive debugging
                    $(document).on('click', '#working-english-output', function() {
                        console.log('English field clicked - events are working');
                    });
                    
                    $(document).on('input', '#working-english-output', function() {
                        console.log('=== ENGLISH INPUT EVENT FIRED ===');
                        var englishText = $(this).val().trim();
                        console.log('English text value:', englishText);
                        
                        if (englishText) {
                            console.log('Processing English input:', englishText);
                            
                            // Update address field
                            $('#billing_address_1').val(englishText);
                            console.log('Updated address field to:', englishText);
                            
                            // Update status
                            $('#working-status').html('<span style="color: #007cba;">English input: ' + englishText + '</span>');
                            console.log('Updated status display');
                            
                            // Trigger delivery validation
                            console.log('About to trigger delivery validation...');
                            setTimeout(function() {
                                console.log('Calling triggerDeliveryValidation for:', englishText);
                                self.triggerDeliveryValidation(englishText, false);
                            }, 300);
                            
                            // Trigger other events
                            $('#billing_address_1').trigger('change');
                            $('body').trigger('update_checkout');
                            console.log('Triggered other events');
                        } else {
                            console.log('English field cleared');
                            $('#billing_address_1').val('');
                            $('#working-status').html('');
                        }
                        console.log('=== END ENGLISH INPUT EVENT ===');
                    });
                    
                    // Also try keyup as backup
                    $(document).on('keyup', '#working-english-output', function() {
                        console.log('English field keyup event fired');
                    });
                    
                    console.log('Events bound successfully');
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
                        
                        // Trigger delivery validation after translation
                        setTimeout(function() {
                            self.triggerDeliveryValidation(englishResult);
                        }, 300);
                    } else {
                        $('#working-english-output').val('Hotel not found in database');
                        $('#billing_address_1').val(chinese);
                        $('#working-status').html('<span style="color: #ffc107;">Hotel not found - using original text</span>');
                        
                        // Still validate the original Chinese text
                        setTimeout(function() {
                            self.triggerDeliveryValidation(chinese);
                        }, 300);
                    }
                    
                    $('#billing_address_1').trigger('change');
                    $('body').trigger('update_checkout');
                },
                
                initDeliveryValidation: function() {
                    var self = this;
                    
                    // Hook into direct address field changes
                    $(document).off('change.deliveryValidation', '#billing_address_1')
                               .on('change.deliveryValidation', '#billing_address_1', function() {
                        var addressValue = $(this).val();
                        if (addressValue && addressValue.trim() !== '') {
                            self.triggerDeliveryValidation(addressValue);
                        }
                    });
                    
                    console.log('Delivery validation hooks initialized');
                },
                
                triggerDeliveryValidation: function(address) {
                    var self = this;
                    console.log('Triggering delivery validation for:', address);
                    
                    if (!address || address.trim() === '') {
                        return;
                    }
                    
                    // Call the AJAX validation function
                    $.ajax({
                        url: wc_checkout_params.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'validate_delivery_address',
                            address: address,
                            nonce: validationNonce
                        },
                        success: function(response) {
                            console.log('Delivery validation response:', response);
                            
                            if (response.success) {
                                var result = response.data;
                                
                                // Update status display
                                var statusColor = result.valid ? '#28a745' : '#dc3545';
                                var statusMessage = result.message;
                                
                                // Update the working status div
                                var currentStatus = $('#working-status').html();
                                var validationStatus = '<br><span style="color: ' + statusColor + ';">Delivery: ' + statusMessage + '</span>';
                                
                                if (currentStatus.indexOf('Translation successful') !== -1 || currentStatus.indexOf('Hotel not found') !== -1) {
                                    $('#working-status').html(currentStatus + validationStatus);
                                } else {
                                    $('#working-status').html('<span style="color: ' + statusColor + ';">' + statusMessage + '</span>');
                                }
                                
                                // If invalid, show error notice
                                if (!result.valid) {
                                    $('.woocommerce-error, .woocommerce-message').remove();
                                    $('.woocommerce-notices-wrapper').first().html(
                                        '<div class="woocommerce-error" role="alert">' + statusMessage + '</div>'
                                    );
                                } else {
                                    $('.woocommerce-error').remove();
                                }
                            } else {
                                console.error('Validation failed:', response.data);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX error:', error);
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
            
            // Make validator globally available for testing
            window.workingValidator = workingValidator;
            window.manualTriggerDeliveryValidation = function(address) {
                var addr = address || $('#billing_address_1').val();
                if (addr) {
                    workingValidator.triggerDeliveryValidation(addr);
                } else {
                    console.log('No address provided for validation');
                }
            };
            
            // Hide apartment field
            $('#billing_address_2_field').hide();
            $('#billing_address_2').val('');
            
            console.log('Enhanced validator with delivery validation loaded. Use window.manualTriggerDeliveryValidation() for testing.');
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
            // Add debug logger call before return
            if (class_exists('SSFood4U_Debug_Logger')) {
                SSFood4U_Debug_Logger::get_instance()->log_delivery_validation($address, $result);
            }
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
                
                // Add debug logger call before return
                if (class_exists('SSFood4U_Debug_Logger')) {
                    SSFood4U_Debug_Logger::get_instance()->log_delivery_validation($address, $result);
                }
                return $result;
            }
        }
        
        foreach ($valid_areas as $area) {
            if (strpos($address_lower, $area) !== false) {
                $result['message'] = "Delivery available to " . $area;
                
                // Add debug logger call before return
                if (class_exists('SSFood4U_Debug_Logger')) {
                    SSFood4U_Debug_Logger::get_instance()->log_delivery_validation($address, $result);
                }
                return $result;
            }
        }
        
        // Add debug logger call before final return
        if (class_exists('SSFood4U_Debug_Logger')) {
            SSFood4U_Debug_Logger::get_instance()->log_delivery_validation($address, $result);
        }
        
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