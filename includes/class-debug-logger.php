<?php
/**
 * SSFood4U Debug Logger
 * File: class-ssfood4u-debug-logger.php
 * Centralized debugging for delivery validation and shipping calculations
 */

if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

class SSFood4U_Debug_Logger {
    
    private static $instance = null;
    private $debug_enabled = true;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->debug_enabled = defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        $this->init_hooks();
    }
    
    private function init_hooks() {
        if (!$this->debug_enabled) {
            return;
        }
        
        // Hook into shipping calculations
        add_action('woocommerce_shipping_init', array($this, 'init_shipping_debug'));
        add_filter('woocommerce_package_rates', array($this, 'debug_shipping_rates'), 10, 2);
        add_action('woocommerce_checkout_update_order_review', array($this, 'debug_shipping_calculation'));
    }
    
    /**
     * Log delivery validation debug info
     */
    public function log_delivery_validation($address, $result) {
        if (!$this->debug_enabled) return;
        
        $this->log('=== DELIVERY VALIDATION DEBUG ===');
        $this->log('Original Address: ' . $address);
        $this->log('Processed Address: ' . strtolower(trim($address)));
        $this->log('Valid: ' . ($result['valid'] ? 'YES' : 'NO'));
        $this->log('Message: ' . $result['message']);
        $this->log('Area Detected: ' . $result['area_detected']);
        $this->log('=== END DELIVERY VALIDATION DEBUG ===');
    }
    
    /**
     * Initialize shipping debugging
     */
    public function init_shipping_debug() {
        $this->log('=== SHIPPING DEBUG INIT ===');
        $this->log('WooCommerce shipping system initialized');
        $this->log('========================');
    }
    
    /**
     * Debug shipping calculation process
     */
    public function debug_shipping_calculation($post_data) {
        if (!$this->debug_enabled) return;
        
        $this->log('=== SHIPPING CALCULATION DEBUG ===');
        
        // Parse the post data to get address
        parse_str($post_data, $data);
        $address = isset($data['billing_address_1']) ? $data['billing_address_1'] : '';
        
        if ($address) {
            $this->log('Address being calculated: ' . $address);
            
            // Get coordinates
            $this->debug_google_coordinates($address);
            
            // Debug shipping packages
            $packages = WC()->shipping()->get_packages();
            foreach ($packages as $i => $package) {
                $this->log('Package ' . $i . ' destination: ' . print_r($package['destination'], true));
            }
        }
        
        $this->log('=== END SHIPPING CALCULATION DEBUG ===');
    }
    
    /**
     * Debug shipping rates
     */
    public function debug_shipping_rates($rates, $package) {
        if (!$this->debug_enabled) return $rates;
        
        $this->log('=== SHIPPING RATES DEBUG ===');
        $this->log('Package destination: ' . print_r($package['destination'], true));
        
        // Log both addresses separately
        $address_1 = isset($package['destination']['address']) ? $package['destination']['address'] : 'Unknown';
        $address_2 = isset($package['destination']['address_2']) ? $package['destination']['address_2'] : 'None';
        
        $this->log('*** ADDRESS 1 (Primary): ' . $address_1 . ' ***');
        $this->log('*** ADDRESS 2 (Secondary): ' . $address_2 . ' ***');
        
        foreach ($rates as $rate_id => $rate) {
            $this->log('Rate ID: ' . $rate_id);
            $this->log('Rate Label: ' . $rate->label);
            $this->log('Rate Cost: RM' . $rate->cost);
            $this->log('Rate Method ID: ' . $rate->method_id);
            
            // Check if this is the distance rate plugin
            if (strpos($rate->method_id, 'distance_rate') !== false) {
                $this->log('*** DISTANCE RATE SHIPPING DETECTED ***');
                
                // Enhanced debugging for specific rates
                $cost = floatval($rate->cost);
                
                if (abs($cost - 11.80) < 0.01) {
                    $this->log('*** ALERT: RM11.80 DETECTED - DEFAULT LOCATION USED ***');
                    $this->log('*** This indicates Google returned Semporna center coordinates ***');
                } elseif (abs($cost - 5.00) < 0.01) {
                    $this->log('*** ALERT: RM5.00 DETECTED - POSSIBLE ADDRESS 2 FALLBACK ***');
                    $this->log('*** This may indicate distance calculated to: ' . $address_2 . ' ***');
                } elseif (abs($cost - 16.00) < 0.01) {
                    $this->log('*** RM16.00 DETECTED - NORMAL CALCULATION ***');
                    $this->log('*** This indicates proper coordinates were found ***');
                }
                
                $this->log('*** INVESTIGATE: Which address was actually used for calculation? ***');
                $this->log('*** Expected: ' . $address_1 . ' ***');
                $this->log('*** Possible fallback: ' . $address_2 . ' ***');
                
                // Check for RM11.80 specifically
                if (abs(floatval($rate->cost) - 11.80) < 0.01) {
                    $this->log('*** ALERT: RM11.80 DETECTED - LIKELY GOOGLE DEFAULT LOCATION ***');
                    $this->log('Address: ' . (isset($package['destination']['address_1']) ? $package['destination']['address_1'] : 'Unknown'));
                    
                    // Log full rate details for RM11.80 cases
                    $this->log('Full rate object: ' . print_r($rate, true));
                }
            }
        }
        
        $this->log('=== END SHIPPING RATES DEBUG ===');
        
        return $rates;
    }
    
    /**
     * Debug Google coordinates lookup
     */
    private function debug_google_coordinates($address) {
        if (!$this->debug_enabled) return;
        
        $this->log('=== GOOGLE COORDINATES DEBUG ===');
        $this->log('Looking up coordinates for: ' . $address);
        
        // Try to access Google Maps API key from ALL possible WordPress options
        $api_keys = array(
            get_option('google_maps_api_key'),
            get_option('wc_distance_rate_google_api_key'),
            get_option('woocommerce_distance_rate_google_api_key'),
            get_option('distance_rate_google_api_key'),
            get_option('wc_settings_distance_rate_google_api_key'),
            get_option('woocommerce_shipping_distance_rate_settings'),
            defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : null
        );
        
        // Also check if settings are stored as arrays
        $shipping_settings = get_option('woocommerce_distance_rate_settings', array());
        if (is_array($shipping_settings) && isset($shipping_settings['google_api_key'])) {
            $api_keys[] = $shipping_settings['google_api_key'];
        }
        
        // Check for any option that might contain 'google' and 'api'
        global $wpdb;
        $google_options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
             WHERE option_name LIKE '%google%' AND option_name LIKE '%api%'"
        );
        
        foreach ($google_options as $option) {
            $this->log('Found Google API option: ' . $option->option_name);
            if (strlen($option->option_value) > 20) { // API keys are long
                $api_keys[] = $option->option_value;
            }
        }
        
        // Check for distance rate shipping options specifically
        $distance_options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
             WHERE option_name LIKE '%distance%rate%'"
        );
        
        foreach ($distance_options as $option) {
            $this->log('Found distance rate option: ' . $option->option_name);
            $value = maybe_unserialize($option->option_value);
            if (is_array($value)) {
                foreach ($value as $key => $val) {
                    if (stripos($key, 'google') !== false || stripos($key, 'api') !== false) {
                        $this->log('Found API key in array: ' . $key);
                        if (strlen($val) > 20) {
                            $api_keys[] = $val;
                        }
                    }
                }
            }
        }
        
        $api_key = null;
        foreach ($api_keys as $key) {
            if (!empty($key) && strlen($key) > 20) {
                $api_key = $key;
                $this->log('Using API key: ' . substr($key, 0, 10) . '...');
                break;
            }
        }
        
        if (!$api_key) {
            $this->log('NO GOOGLE API KEY FOUND! Checked options:');
            foreach ($api_keys as $i => $key) {
                $this->log('Option ' . $i . ': ' . (empty($key) ? 'empty' : substr($key, 0, 10) . '...'));
            }
            $this->log('=== END GOOGLE COORDINATES DEBUG ===');
            return;
        }
        
        $this->log('Google API key found, attempting coordinate lookup...');
        
        // Test both address variations
        $test_addresses = array(
            'primary_only' => $address . ', Semporna, Sabah, Malaysia',
            'with_seafest' => $address . ', Seafest Hotel, Semporna, Sabah, Malaysia',
            'seafest_only' => 'Seafest Hotel, Semporna, Sabah, Malaysia'
        );
        
        foreach ($test_addresses as $test_type => $test_address) {
            $this->log('=== TESTING: ' . strtoupper($test_type) . ' ===');
            $this->log('Test Address: ' . $test_address);
            
            $address_encoded = urlencode($test_address);
            $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address_encoded}&key={$api_key}";
            
            $response = wp_remote_get($url, array('timeout' => 10));
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                $this->log('Google API Response Status: ' . $data['status']);
                
                if ($data['status'] == 'OK' && !empty($data['results'])) {
                    $result = $data['results'][0];
                    $lat = $result['geometry']['location']['lat'];
                    $lng = $result['geometry']['location']['lng'];
                    
                    $this->log('*** COORDINATES FOUND FOR ' . strtoupper($test_type) . ' ***');
                    $this->log('*** Latitude: ' . $lat . ' ***');
                    $this->log('*** Longitude: ' . $lng . ' ***');
                    $this->log('Formatted Address: ' . $result['formatted_address']);
                    $this->log('Place ID: ' . (isset($result['place_id']) ? $result['place_id'] : 'N/A'));
                    
                    // Check if this looks like a default/generic location
                    $location_types = isset($result['types']) ? $result['types'] : array();
                    $this->log('Location Types: ' . implode(', ', $location_types));
                    
                    // Flag suspicious coordinates (generic locations)
                    if (in_array('political', $location_types) || in_array('locality', $location_types)) {
                        $this->log('*** WARNING: Coordinates may be generic/default location ***');
                    }
                    
                    // Check against known Semporna center coordinates
                    $semporna_lat = 4.479391;
                    $semporna_lng = 118.611545;
                    $distance_from_semporna = $this->calculate_distance($lat, $lng, $semporna_lat, $semporna_lng);
                    $this->log('Distance from Semporna center: ' . round($distance_from_semporna, 2) . ' km');
                    
                    // Check distance to Seafest Hotel coordinates
                    $seafest_lat = 4.4690029;  // From your Mr Bean log
                    $seafest_lng = 118.5746759;
                    $distance_from_seafest = $this->calculate_distance($lat, $lng, $seafest_lat, $seafest_lng);
                    $this->log('Distance from Seafest Hotel: ' . round($distance_from_seafest, 2) . ' km');
                    
                    if ($distance_from_semporna < 1) {
                        $this->log('*** ALERT: Very close to Semporna center - likely default location ***');
                    }
                    
                    if ($distance_from_seafest < 1) {
                        $this->log('*** ALERT: Very close to Seafest Hotel - may be using Address 2 ***');
                    }
                    
                } else {
                    $this->log('Google API returned no results or error for ' . $test_type);
                    $this->log('Status: ' . $data['status']);
                    if (isset($data['error_message'])) {
                        $this->log('Error: ' . $data['error_message']);
                    }
                }
            } else {
                $this->log('WordPress HTTP error for ' . $test_type . ': ' . $response->get_error_message());
            }
            
            $this->log('=== END TESTING: ' . strtoupper($test_type) . ' ===');
        }
        
        $this->log('=== END GOOGLE COORDINATES DEBUG ===');
    }
    
    /**
     * Helper function to calculate distance between two coordinates
     */
    private function calculate_distance($lat1, $lng1, $lat2, $lng2) {
        $earth_radius = 6371; // Earth's radius in kilometers
        
        $lat_diff = deg2rad($lat2 - $lat1);
        $lng_diff = deg2rad($lng2 - $lng1);
        
        $a = sin($lat_diff/2) * sin($lat_diff/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lng_diff/2) * sin($lng_diff/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = $earth_radius * $c;
        
        return $distance;
    }
    
    /**
     * Central logging function
     */
    private function log($message) {
        if ($this->debug_enabled) {
            error_log('[SSFood4U Debug] ' . $message);
        }
    }
    
    /**
     * Enable/disable debugging
     */
    public function set_debug_enabled($enabled) {
        $this->debug_enabled = $enabled;
    }
    
    /**
     * Check if debugging is enabled
     */
    public function is_debug_enabled() {
        return $this->debug_enabled;
    }
}

// Initialize the debug logger
SSFood4U_Debug_Logger::get_instance();