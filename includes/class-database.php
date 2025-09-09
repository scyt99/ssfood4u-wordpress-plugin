<?php
/**
 * Database Management Class with Enhanced OCR Support
 * Handles all database operations for SSFood4U
 */

if (!defined('ABSPATH')) exit;

class SSFood4U_Database {
    
    /**
     * Create payment verification table with enhanced OCR fields
     */
    public static function create_payment_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(50) NOT NULL,
            customer_email varchar(100),
            payment_method varchar(50),
            transaction_id varchar(100),
            amount decimal(10,2) NOT NULL,
            receipt_url varchar(500),
            verification_status varchar(20) DEFAULT 'pending',
            admin_notes text,
            ocr_validation varchar(50) DEFAULT NULL,
            ocr_confidence int(3) DEFAULT 0,
            ocr_message text DEFAULT NULL,
            ocr_amounts_found text DEFAULT NULL,
            ocr_metadata text DEFAULT NULL,
            auto_approved_by_ocr tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            verified_at datetime,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id),
            KEY verification_status (verification_status),
            KEY ocr_confidence (ocr_confidence),
            KEY auto_approved_by_ocr (auto_approved_by_ocr)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        update_option('ssfood4u_db_version', '2.0');
    }
    
    /**
     * Update existing table to add OCR fields if they don't exist
     */
    public static function update_table_for_ocr() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        // Check if OCR columns exist
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        $existing_columns = array();
        foreach ($columns as $column) {
            $existing_columns[] = $column->Field;
        }
        
        $ocr_columns = array(
            'ocr_validation' => 'ALTER TABLE ' . $table_name . ' ADD COLUMN ocr_validation varchar(50) DEFAULT NULL',
            'ocr_confidence' => 'ALTER TABLE ' . $table_name . ' ADD COLUMN ocr_confidence int(3) DEFAULT 0',
            'ocr_message' => 'ALTER TABLE ' . $table_name . ' ADD COLUMN ocr_message text DEFAULT NULL',
            'ocr_amounts_found' => 'ALTER TABLE ' . $table_name . ' ADD COLUMN ocr_amounts_found text DEFAULT NULL',
            'ocr_metadata' => 'ALTER TABLE ' . $table_name . ' ADD COLUMN ocr_metadata text DEFAULT NULL',
            'auto_approved_by_ocr' => 'ALTER TABLE ' . $table_name . ' ADD COLUMN auto_approved_by_ocr tinyint(1) DEFAULT 0'
        );
        
        foreach ($ocr_columns as $column_name => $sql) {
            if (!in_array($column_name, $existing_columns)) {
                $wpdb->query($sql);
            }
        }
        
        // Add indexes if they don't exist
        $wpdb->query("ALTER TABLE $table_name ADD INDEX IF NOT EXISTS idx_ocr_confidence (ocr_confidence)");
        $wpdb->query("ALTER TABLE $table_name ADD INDEX IF NOT EXISTS idx_auto_approved (auto_approved_by_ocr)");
        
        update_option('ssfood4u_db_version', '2.0');
    }
    
    /**
     * Save payment verification data with OCR support
     */
    public static function save_payment_verification($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        // Prepare base data
        $insert_data = array(
            'order_id' => sanitize_text_field($data['order_id']),
            'customer_email' => sanitize_email($data['customer_email'] ?? ''),
            'payment_method' => sanitize_text_field($data['payment_method'] ?? 'upload'),
            'transaction_id' => sanitize_text_field($data['transaction_id'] ?? ''),
            'amount' => floatval($data['amount']),
            'receipt_url' => esc_url_raw($data['receipt_url'] ?? ''),
            'verification_status' => sanitize_text_field($data['verification_status'] ?? 'pending'),
            'created_at' => current_time('mysql')
        );
        
        // Add OCR data if available
        if (isset($data['ocr_validation'])) {
            $insert_data['ocr_validation'] = sanitize_text_field($data['ocr_validation']);
        }
        if (isset($data['ocr_confidence'])) {
            $insert_data['ocr_confidence'] = intval($data['ocr_confidence']);
        }
        if (isset($data['ocr_message'])) {
            $insert_data['ocr_message'] = sanitize_textarea_field($data['ocr_message']);
        }
        if (isset($data['ocr_amounts_found'])) {
            $insert_data['ocr_amounts_found'] = sanitize_textarea_field($data['ocr_amounts_found']);
        }
        if (isset($data['ocr_metadata'])) {
            $insert_data['ocr_metadata'] = sanitize_textarea_field($data['ocr_metadata']);
        }
        if (isset($data['auto_approved_by_ocr'])) {
            $insert_data['auto_approved_by_ocr'] = $data['auto_approved_by_ocr'] ? 1 : 0;
        }
        
        // Set verified_at if auto-approved
        if (isset($data['verification_status']) && $data['verification_status'] === 'verified') {
            $insert_data['verified_at'] = current_time('mysql');
        }
        
        $result = $wpdb->replace($table_name, $insert_data);
        
        if ($result === false) {
            error_log('Database error saving payment: ' . $wpdb->last_error);
            return false;
        }
        
        return $result !== false;
    }
    
    /**
     * Get payment by order ID
     */
    public static function get_payment_by_order_id($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %s",
            $order_id
        ));
    }
    
    /**
     * Update payment status
     */
    public static function update_payment_status($payment_id, $status, $admin_notes = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        $update_data = array(
            'verification_status' => sanitize_text_field($status),
            'admin_notes' => sanitize_textarea_field($admin_notes)
        );
        
        if ($status === 'verified') {
            $update_data['verified_at'] = current_time('mysql');
        }
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => intval($payment_id))
        );
        
        if ($result === false) {
            error_log('Database error updating payment status: ' . $wpdb->last_error);
        }
        
        return $result !== false;
    }
    
    /**
     * Get all payments with pagination and enhanced OCR data
     */
    public static function get_payments($limit = 50, $offset = 0, $status = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        $sql = "SELECT * FROM $table_name";
        
        if ($status) {
            $sql .= $wpdb->prepare(" WHERE verification_status = %s", $status);
        }
        
        $sql .= " ORDER BY created_at DESC";
        $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        
        $results = $wpdb->get_results($sql);
        
        if ($wpdb->last_error) {
            error_log('Database error getting payments: ' . $wpdb->last_error);
            return array();
        }
        
        return $results;
    }
    
    /**
     * Get comprehensive payment statistics including OCR data
     */
    public static function get_payment_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        $stats = array();
        
        // Basic payment statistics
        $stats['total'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name"));
        $stats['pending'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE verification_status = 'pending'"));
        $stats['verified'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE verification_status = 'verified'"));
        $stats['rejected'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE verification_status = 'rejected'"));
        
        // Total verified amount
        $total_amount = $wpdb->get_var("SELECT SUM(amount) FROM $table_name WHERE verification_status = 'verified'");
        $stats['total_amount'] = $total_amount ? floatval($total_amount) : 0;
        
        // Enhanced OCR statistics
        $stats['total_ocr_processed'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ocr_validation IS NOT NULL AND ocr_validation != ''"));
        $stats['auto_approved_count'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE auto_approved_by_ocr = 1"));
        
        // Average OCR confidence
        $avg_confidence = $wpdb->get_var("SELECT AVG(ocr_confidence) FROM $table_name WHERE ocr_confidence > 0");
        $stats['avg_confidence'] = $avg_confidence ? floatval($avg_confidence) : 0;
        
        // OCR match rate (exact and close matches)
        $match_rate = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ocr_validation IN ('match', 'close_match')");
        $stats['match_rate'] = $match_rate ? intval($match_rate) : 0;
        
        // OCR validation breakdown
        $stats['ocr_matches'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ocr_validation = 'match'"));
        $stats['ocr_close_matches'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ocr_validation = 'close_match'"));
        $stats['ocr_no_matches'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ocr_validation = 'no_match'"));
        $stats['ocr_failed'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ocr_validation = 'failed'"));
        
        if ($wpdb->last_error) {
            error_log('Database error getting payment stats: ' . $wpdb->last_error);
        }
        
        return $stats;
    }
    
    /**
     * Get OCR performance analytics
     */
    public static function get_ocr_analytics($days = 30) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $analytics = array();
        
        // OCR accuracy over time
        $analytics['daily_confidence'] = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, AVG(ocr_confidence) as avg_confidence, COUNT(*) as count 
             FROM $table_name 
             WHERE ocr_confidence > 0 AND created_at >= %s 
             GROUP BY DATE(created_at) 
             ORDER BY date DESC",
            $date_limit
        ));
        
        // Validation status distribution
        $analytics['validation_breakdown'] = $wpdb->get_results($wpdb->prepare(
            "SELECT ocr_validation, COUNT(*) as count 
             FROM $table_name 
             WHERE ocr_validation IS NOT NULL AND created_at >= %s 
             GROUP BY ocr_validation",
            $date_limit
        ));
        
        // Auto-approval rate
        $total_ocr = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE ocr_validation IS NOT NULL AND created_at >= %s",
            $date_limit
        ));
        $auto_approved = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE auto_approved_by_ocr = 1 AND created_at >= %s",
            $date_limit
        ));
        
        $analytics['auto_approval_rate'] = $total_ocr > 0 ? ($auto_approved / $total_ocr) * 100 : 0;
        
        return $analytics;
    }
    
    /**
     * Initialize database tables and check for updates
     */
    public static function init_database() {
        $current_version = get_option('ssfood4u_db_version', '0');
        
        if ($current_version === '0') {
            // First install
            self::create_payment_table();
        } elseif (version_compare($current_version, '2.0', '<')) {
            // Update existing table for OCR support
            self::update_table_for_ocr();
        }
    }
    
    /**
     * Fallback to options table if database table doesn't exist
     */
    public static function save_payment_fallback($data) {
        $payments = get_option('ssfood4u_payment_verifications', array());
        
        // Add timestamp and ID if not present
        if (!isset($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }
        if (!isset($data['id'])) {
            $data['id'] = count($payments) + 1;
        }
        
        $payments[] = $data;
        update_option('ssfood4u_payment_verifications', $payments);
        
        return true;
    }
    
    /**
     * Get payments from options table fallback
     */
    public static function get_payments_fallback($limit = 50, $offset = 0, $status = null) {
        $payments = get_option('ssfood4u_payment_verifications', array());
        
        if ($status) {
            $payments = array_filter($payments, function($payment) use ($status) {
                return isset($payment['verification_status']) && $payment['verification_status'] === $status;
            });
        }
        
        // Sort by created_at descending
        usort($payments, function($a, $b) {
            $date_a = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
            $date_b = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
            return $date_b - $date_a;
        });
        
        // Convert arrays to objects and apply pagination
        return array_map(function($payment) {
            return (object) $payment;
        }, array_slice($payments, $offset, $limit));
    }
    
    /**
     * Smart method that tries database first, falls back to options
     */
    public static function save_payment_smart($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            error_log('Payment table does not exist, using options fallback');
            return self::save_payment_fallback($data);
        }
        
        return self::save_payment_verification($data);
    }
    
    /**
     * Smart getter that tries database first, falls back to options
     */
    public static function get_payments_smart($limit = 50, $offset = 0, $status = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssfood4u_payments';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            error_log('Payment table does not exist, using options fallback');
            return self::get_payments_fallback($limit, $offset, $status);
        }
        
        return self::get_payments($limit, $offset, $status);
    }
}

// Initialize database on plugin activation
register_activation_hook(SSFOOD4U_PLUGIN_DIR . 'ssfood4u-main.php', array('SSFood4U_Database', 'init_database'));

// Check for database updates on admin init
add_action('admin_init', array('SSFood4U_Database', 'init_database'));
?>