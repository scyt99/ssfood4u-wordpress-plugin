<?php
/**
 * SSFood4U Debug Logger - Complete Version
 * File: class-ssfood4u-debug-logger.php
 */

if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

class SSFood4U_Debug_Logger {
    
    private static $instance = null;
    private $debug_enabled = false;
    private $log_file = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->debug_enabled = get_option('ssfood4u_debug_enabled', false);
        $this->log_file = WP_CONTENT_DIR . '/ssfood4u-debug.log';
        
        if ($this->debug_enabled) {
            $this->init_hooks();
        }
        
        add_action('admin_menu', array($this, 'add_debug_menu'));
    }
    
    private function init_hooks() {
        add_action('woocommerce_shipping_init', array($this, 'init_shipping_debug'));
        add_filter('woocommerce_package_rates', array($this, 'debug_shipping_rates'), 10, 2);
        add_action('woocommerce_checkout_update_order_review', array($this, 'debug_shipping_calculation'));
    }
    
    public function add_debug_menu() {
        add_submenu_page('ssfood4u-main', 'Debug Controls', 'Debug', 'manage_options', 'ssfood4u-debug', array($this, 'render_debug_page'));
    }
    
    public function render_debug_page() {
        if (isset($_POST['toggle_debug'])) {
            $this->debug_enabled = !$this->debug_enabled;
            update_option('ssfood4u_debug_enabled', $this->debug_enabled);
            if ($this->debug_enabled) {
                $this->init_hooks();
            }
            echo '<div class="notice notice-success"><p>Debug ' . ($this->debug_enabled ? 'enabled' : 'disabled') . '</p></div>';
        }
        
        if (isset($_POST['clear_log'])) {
            $this->clear_log();
            echo '<div class="notice notice-success"><p>Log cleared</p></div>';
        }
        
        $stats = $this->get_stats();
        ?>
        <div class="wrap">
            <h1>SSFood4U Debug</h1>
            <div class="card">
                <h2>Status</h2>
                <p><strong>Debug:</strong> 
                    <span style="color: <?php echo $this->debug_enabled ? 'green' : 'red'; ?>;">
                        <?php echo $this->debug_enabled ? 'ON' : 'OFF'; ?>
                    </span>
                </p>
                <p>Entries: <?php echo $stats['entries']; ?> | Size: <?php echo $stats['size']; ?></p>
                
                <form method="post">
                    <input type="submit" name="toggle_debug" class="button button-primary" 
                           value="<?php echo $this->debug_enabled ? 'Turn OFF' : 'Turn ON'; ?>" />
                    <input type="submit" name="clear_log" class="button" value="Clear Log" />
                </form>
            </div>
            
            <div class="card">
                <h2>Recent Log</h2>
                <textarea readonly style="width:100%;height:300px;font-family:monospace;font-size:11px;"><?php echo $this->get_recent_log(); ?></textarea>
            </div>
        </div>
        <?php
    }
    
    private function get_stats() {
        if (!file_exists($this->log_file)) {
            return array('entries' => 0, 'size' => '0 B');
        }
        $content = file_get_contents($this->log_file);
        $lines = substr_count($content, "\n");
        $size = filesize($this->log_file);
        $units = array('B', 'KB', 'MB');
        for ($i = 0; $size > 1024 && $i < 2; $i++) $size /= 1024;
        return array('entries' => $lines, 'size' => round($size, 1) . ' ' . $units[$i]);
    }
    
    private function get_recent_log() {
        if (!file_exists($this->log_file)) {
            return 'No log file found.';
        }
        $content = file_get_contents($this->log_file);
        $lines = explode("\n", $content);
        $recent = array_slice($lines, -50);
        return implode("\n", $recent);
    }
    
    private function clear_log() {
        file_put_contents($this->log_file, '');
    }
    
    public function log($message, $category = 'GENERAL') {
        if (!$this->debug_enabled) return;
        
        $timestamp = date('Y-m-d H:i:s');
        $log_line = "[{$timestamp}] [{$category}] {$message}\n";
        
        error_log('[SSFood4U] ' . $message);
        file_put_contents($this->log_file, $log_line, FILE_APPEND | LOCK_EX);
    }
    
    public function log_delivery_validation($address, $result) {
        if (!$this->debug_enabled) return;
        $status = $result['valid'] ? 'VALID' : 'INVALID';
        $this->log("Delivery: {$address} -> {$status} ({$result['message']})", 'DELIVERY');
    }
    
    public function log_rm1180_detection($address, $cost, $method) {
        if (!$this->debug_enabled) return;
        $this->log("RM11.80 FOUND: {$address} | Cost: RM{$cost} | Via: {$method}", 'RM11.80');
    }
    
    public function log_hotel_translation($chinese, $english, $found) {
        if (!$this->debug_enabled) return;
        $status = $found ? 'FOUND' : 'NOT_FOUND';
        $this->log("Translation: {$chinese} -> {$english} ({$status})", 'TRANSLATION');
    }
    
    public function log_payment_verification($order_id, $status, $details = '') {
        if (!$this->debug_enabled) return;
        $msg = "Payment: Order {$order_id} -> {$status}";
        if ($details) $msg .= " ({$details})";
        $this->log($msg, 'PAYMENT');
    }
    
    public function log_system_event($event, $details = '') {
        if (!$this->debug_enabled) return;
        $msg = "System: {$event}";
        if ($details) $msg .= " - {$details}";
        $this->log($msg, 'SYSTEM');
    }
    
    public function init_shipping_debug() {
        $this->log('Shipping system initialized', 'SHIPPING');
    }
    
    public function debug_shipping_calculation($post_data) {
        if (!$this->debug_enabled) return;
        
        parse_str($post_data, $data);
        $address = isset($data['billing_address_1']) ? $data['billing_address_1'] : '';
        if ($address) {
            $this->log("Calculating rates for: {$address}", 'SHIPPING');
        }
    }
    
    public function debug_shipping_rates($rates, $package) {
        if (!$this->debug_enabled) return $rates;
        
        $address = isset($package['destination']['address']) ? $package['destination']['address'] : 'Unknown';
        
        foreach ($rates as $rate) {
            $this->log("Rate: {$rate->label} = RM{$rate->cost} for {$address}", 'SHIPPING');
            
            if (abs(floatval($rate->cost) - 11.80) < 0.01) {
                $this->log_rm1180_detection($address, $rate->cost, 'Shipping Calculator');
            }
        }
        
        return $rates;
    }
    
    public function set_debug_enabled($enabled) {
        $this->debug_enabled = $enabled;
        update_option('ssfood4u_debug_enabled', $enabled);
        if ($enabled) {
            $this->init_hooks();
        }
    }
    
    public function is_debug_enabled() {
        return $this->debug_enabled;
    }
}

// Initialize
SSFood4U_Debug_Logger::get_instance();
?>