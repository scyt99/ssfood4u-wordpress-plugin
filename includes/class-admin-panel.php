<?php
/**
 * Admin Panel Management with Enhanced OCR Settings
 * Handles admin interface, settings, and payment management
 */

if (!defined('ABSPATH')) exit;

class SSFood4U_Admin_Panel {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Admin notices
        add_action('admin_notices', array($this, 'admin_setup_notices'));
        
        // AJAX handlers for admin actions
        add_action('wp_ajax_approve_payment', array($this, 'approve_payment'));
        add_action('wp_ajax_reject_payment', array($this, 'reject_payment'));
        
        // Admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_menu_page(
            'Payment Verifications',
            'Payment Verifications',
            'manage_options',
            'ssfood4u-payments',
            array($this, 'admin_payments_page'),
            'dashicons-money-alt',
            30
        );
        
        add_submenu_page(
            'ssfood4u-payments',
            'Payment Settings',
            'Settings',
            'manage_options',
            'ssfood4u-settings',
            array($this, 'admin_settings_page')
        );
        
        add_submenu_page(
            'ssfood4u-payments',
            'Payment Reports',
            'Reports',
            'manage_options',
            'ssfood4u-reports',
            array($this, 'admin_reports_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'ssfood4u') !== false) {
            wp_enqueue_script('jquery');
            wp_localize_script('jquery', 'ssfood4u_admin_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ssfood4u_admin_nonce')
            ));
        }
    }
    
    /**
     * Main payments page with enhanced OCR display
     */
    public function admin_payments_page() {
        // Handle form actions
        if (isset($_POST['action']) && isset($_POST['payment_id'])) {
            $this->handle_admin_action();
        }
        
        // Get payments with pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : null;
        
        $payments = SSFood4U_Database::get_payments($per_page, $offset, $status_filter);
        $stats = SSFood4U_Database::get_payment_stats();
        
        ?>
        <div class="wrap">
            <h1>Payment Verifications</h1>
            
            <!-- Payment Statistics -->
            <div class="payment-stats" style="display: flex; gap: 20px; margin: 20px 0;">
                <div class="stat-box" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1;">
                    <h3 style="margin: 0; color: #666;">Total Payments</h3>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: #333;"><?php echo intval($stats['total']); ?></p>
                </div>
                <div class="stat-box" style="background: #fff3cd; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1;">
                    <h3 style="margin: 0; color: #856404;">Pending</h3>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: #856404;"><?php echo intval($stats['pending']); ?></p>
                </div>
                <div class="stat-box" style="background: #d1f2eb; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1;">
                    <h3 style="margin: 0; color: #155724;">Verified</h3>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: #155724;"><?php echo intval($stats['verified']); ?></p>
                </div>
                <div class="stat-box" style="background: #f8d7da; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1;">
                    <h3 style="margin: 0; color: #721c24;">Rejected</h3>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: #721c24;"><?php echo intval($stats['rejected']); ?></p>
                </div>
                <div class="stat-box" style="background: #e3f2fd; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1;">
                    <h3 style="margin: 0; color: #0d47a1;">Total Amount</h3>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: #0d47a1;">RM <?php echo number_format(floatval($stats['total_amount']), 2); ?></p>
                </div>
            </div>
            
            <!-- Enhanced OCR Stats -->
            <?php if (get_option('ssfood4u_ocr_api_key', '')): ?>
            <div style="background: #f0f8ff; border: 1px solid #3498db; padding: 15px; margin: 20px 0; border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: #3498db;">Enhanced OCR Statistics</h4>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                    <div>
                        <strong>OCR Processed:</strong><br>
                        <?php echo intval($stats['total_ocr_processed'] ?? 0); ?>
                    </div>
                    <div>
                        <strong>Auto-Approved:</strong><br>
                        <?php echo intval($stats['auto_approved_count'] ?? 0); ?>
                    </div>
                    <div>
                        <strong>Avg Confidence:</strong><br>
                        <?php echo number_format(floatval($stats['avg_confidence'] ?? 0), 1); ?>%
                    </div>
                    <div>
                        <strong>Match Rate:</strong><br>
                        <?php echo intval($stats['match_rate'] ?? 0); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Filter Controls -->
            <div class="filter-controls" style="margin: 20px 0;">
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-payments'); ?>" 
                   class="button <?php echo empty($status_filter) ? 'button-primary' : ''; ?>">All</a>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-payments&status=pending'); ?>" 
                   class="button <?php echo $status_filter === 'pending' ? 'button-primary' : ''; ?>">Pending</a>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-payments&status=verified'); ?>" 
                   class="button <?php echo $status_filter === 'verified' ? 'button-primary' : ''; ?>">Verified</a>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-payments&status=rejected'); ?>" 
                   class="button <?php echo $status_filter === 'rejected' ? 'button-primary' : ''; ?>">Rejected</a>
            </div>
            
            <style>
            .payment-receipt-img { max-width: 100px; max-height: 100px; cursor: pointer; }
            .payment-receipt-img:hover { opacity: 0.8; }
            .status-pending { color: #f39c12; font-weight: bold; }
            .status-verified { color: #27ae60; font-weight: bold; }
            .status-rejected { color: #e74c3c; font-weight: bold; }
            .action-buttons { white-space: nowrap; }
            .action-buttons .button { margin-right: 5px; }
            
            /* Enhanced OCR Status Styles */
            .ocr-status-match { color: #27ae60; font-weight: bold; }
            .ocr-status-close_match { color: #f39c12; font-weight: bold; }
            .ocr-status-no_match { color: #e74c3c; font-weight: bold; }
            .ocr-status-no_amounts_found { color: #999; font-style: italic; }
            .ocr-status-failed { color: #e74c3c; font-style: italic; }
            .ocr-status-skipped { color: #999; font-style: italic; }
            
            /* Modal styles */
            .image-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); }
            .modal-content { margin: auto; display: block; width: 80%; max-width: 700px; }
            .close-modal { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
            .close-modal:hover { color: #bbb; }
            </style>
            
            <?php if (empty($payments)): ?>
                <div class="notice notice-info">
                    <p>No payment verifications found. <?php if ($status_filter) echo 'Try changing the filter above.'; ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 120px;">Order ID</th>
                            <th style="width: 180px;">Customer Email</th>
                            <th style="width: 80px;">Amount</th>
                            <th style="width: 120px;">Transaction ID</th>
                            <th style="width: 100px;">Receipt</th>
                            <th style="width: 80px;">Status</th>
                            <th style="width: 90px;">OCR Status</th>
                            <th style="width: 70px;">Confidence</th>
                            <th style="width: 80px;">Auto-Approved</th>
                            <th style="width: 120px;">Date</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><strong><?php echo esc_html($payment->order_id); ?></strong></td>
                                <td><?php echo esc_html($payment->customer_email); ?></td>
                                <td><strong>RM <?php echo number_format($payment->amount, 2); ?></strong></td>
                                <td><?php echo esc_html($payment->transaction_id); ?></td>
                                <td>
                                    <?php if ($payment->receipt_url): ?>
                                        <img src="<?php echo esc_url($payment->receipt_url); ?>" 
                                             class="payment-receipt-img" 
                                             alt="Receipt"
                                             onclick="openImageModal('<?php echo esc_url($payment->receipt_url); ?>')">
                                    <?php elseif ($payment->payment_method === 'whatsapp'): ?>
                                        <em style="color: #25D366;">WhatsApp</em>
                                    <?php else: ?>
                                        <em style="color: #999;">No receipt</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-<?php echo esc_attr($payment->verification_status); ?>">
                                        <?php echo ucfirst($payment->verification_status); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (isset($payment->ocr_validation) && !empty($payment->ocr_validation)): ?>
                                        <span class="ocr-status-<?php echo esc_attr($payment->ocr_validation); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $payment->ocr_validation)); ?>
                                        </span>
                                    <?php else: ?>
                                        <em style="color: #999;">No OCR</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($payment->ocr_confidence) && $payment->ocr_confidence > 0): ?>
                                        <strong><?php echo intval($payment->ocr_confidence); ?>%</strong>
                                    <?php else: ?>
                                        <em>N/A</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($payment->auto_approved_by_ocr) && $payment->auto_approved_by_ocr): ?>
                                        <span style="color: #27ae60; font-weight: bold;">✓ Yes</span>
                                    <?php else: ?>
                                        <span style="color: #999;">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('M j, Y g:i A', strtotime($payment->created_at)); ?>
                                </td>
                                <td class="action-buttons">
                                    <?php if ($payment->verification_status === 'pending'): ?>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('ssfood4u_admin_action', 'admin_nonce'); ?>
                                            <input type="hidden" name="payment_id" value="<?php echo $payment->id; ?>">
                                            <button type="submit" name="action" value="approve" 
                                                    class="button button-primary button-small"
                                                    onclick="return confirm('Approve this payment?')">
                                                ✅ Approve
                                            </button>
                                        </form>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('ssfood4u_admin_action', 'admin_nonce'); ?>
                                            <input type="hidden" name="payment_id" value="<?php echo $payment->id; ?>">
                                            <button type="submit" name="action" value="reject" 
                                                    class="button button-secondary button-small"
                                                    onclick="return confirm('Reject this payment?')">
                                                ❌ Reject
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <em style="color: #666;">
                                            <?php if (isset($payment->auto_approved_by_ocr) && $payment->auto_approved_by_ocr): ?>
                                                Auto-approved by OCR
                                            <?php else: ?>
                                                No action needed
                                            <?php endif; ?>
                                        </em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <!-- Image Modal -->
            <div id="imageModal" class="image-modal">
                <span class="close-modal" onclick="closeImageModal()">&times;</span>
                <img class="modal-content" id="modalImage">
            </div>
            
            <script>
            function openImageModal(src) {
                document.getElementById('imageModal').style.display = 'block';
                document.getElementById('modalImage').src = src;
            }
            
            function closeImageModal() {
                document.getElementById('imageModal').style.display = 'none';
            }
            
            // Close modal when clicking outside the image
            window.onclick = function(event) {
                var modal = document.getElementById('imageModal');
                if (event.target == modal) {
                    closeImageModal();
                }
            }
            </script>
            
            <!-- Setup Information -->
            <?php $this->render_setup_info(); ?>
        </div>
        <?php
    }
    
    /**
     * Settings page with enhanced OCR settings
     */
    public function admin_settings_page() {
        // Handle form submission
        if (isset($_POST['save_settings'])) {
            if (wp_verify_nonce($_POST['ssfood4u_settings_nonce'], 'ssfood4u_save_settings')) {
                $this->save_settings();
            } else {
                echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
            }
        }
        
        // Get current values
        $whatsapp = get_option('ssfood4u_whatsapp', '60123456789');
        $bank_name = get_option('ssfood4u_bank_name', 'Update with your bank name');
        $account_number = get_option('ssfood4u_account_number', 'Update with your account number');
        $account_holder = get_option('ssfood4u_account_holder', 'Update with account holder name');
        ?>
        <div class="wrap">
            <h1>Payment Settings</h1>
            
            <form method="post">
                <?php wp_nonce_field('ssfood4u_save_settings', 'ssfood4u_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">WhatsApp Number</th>
                        <td>
                            <input type="text" name="whatsapp_number" value="<?php echo esc_attr($whatsapp); ?>" 
                                   placeholder="60123456789" style="width: 300px;" required>
                            <p class="description">
                                Enter your WhatsApp number with country code (without +)<br>
                                <strong>Examples:</strong> 60123456789 (Malaysia), 6591234567 (Singapore)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Bank Name</th>
                        <td>
                            <input type="text" name="bank_name" value="<?php echo esc_attr($bank_name); ?>" 
                                   placeholder="Maybank" style="width: 300px;" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Account Number</th>
                        <td>
                            <input type="text" name="account_number" value="<?php echo esc_attr($account_number); ?>" 
                                   placeholder="1234567890" style="width: 300px;" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Account Holder Name</th>
                        <td>
                            <input type="text" name="account_holder" value="<?php echo esc_attr($account_holder); ?>" 
                                   placeholder="John Doe" style="width: 300px;" required>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Settings', 'primary', 'save_settings'); ?>
            </form>
            
            <!-- Test Section -->
            <div style="margin-top: 20px; padding: 15px; background: #e8f4fd; border: 1px solid #3498db; border-radius: 4px;">
                <h4>Test WhatsApp Link</h4>
                <p>Test your WhatsApp number with this link:</p>
                <?php 
                $test_message = urlencode("Hi! This is a test message from SSFood4U payment system.");
                $test_url = "https://wa.me/{$whatsapp}?text={$test_message}";
                ?>
                <p>
                    <a href="<?php echo esc_url($test_url); ?>" target="_blank" 
                       style="background: #25D366; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px;">
                        Test WhatsApp (+<?php echo esc_html($whatsapp); ?>)
                    </a>
                </p>
            </div>
            
            <!-- Enhanced OCR Settings Section -->
            <hr style="margin: 40px 0; border: 1px solid #ddd;">
            <h2>Enhanced OCR Settings</h2>
            <?php $this->render_enhanced_ocr_settings(); ?>
            
            <!-- Setup Checklist -->
            <?php $this->render_setup_checklist(); ?>
        </div>
        <?php
    }
    
    /**
     * Enhanced OCR settings section
     */
    private function render_enhanced_ocr_settings() {
        if (!class_exists('SSFood4U_Enhanced_OCR_Validator')) {
            echo '<div class="notice notice-warning"><p>Enhanced OCR Validator not found. Please check your includes directory.</p></div>';
            return;
        }
        
        $ocr_validator = new SSFood4U_Enhanced_OCR_Validator();
        echo $ocr_validator->get_settings_html();
        $ocr_validator->handle_enhanced_settings_form();
    }
    
    /**
     * Reports page
     */
    public function admin_reports_page() {
        $stats = SSFood4U_Database::get_payment_stats();
        ?>
        <div class="wrap">
            <h1>Payment Reports</h1>
            
            <div class="report-section">
                <h2>Overview Statistics</h2>
                <table class="widefat">
                    <tr>
                        <td><strong>Total Payments:</strong></td>
                        <td><?php echo intval($stats['total']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Pending Verifications:</strong></td>
                        <td><?php echo intval($stats['pending']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Verified Payments:</strong></td>
                        <td><?php echo intval($stats['verified']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Rejected Payments:</strong></td>
                        <td><?php echo intval($stats['rejected']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Verified Amount:</strong></td>
                        <td>RM <?php echo number_format(floatval($stats['total_amount']), 2); ?></td>
                    </tr>
                    <?php if (get_option('ssfood4u_ocr_api_key', '')): ?>
                    <tr>
                        <td><strong>OCR Processed:</strong></td>
                        <td><?php echo intval($stats['total_ocr_processed'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Auto-Approved by OCR:</strong></td>
                        <td><?php echo intval($stats['auto_approved_count'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Average OCR Confidence:</strong></td>
                        <td><?php echo number_format(floatval($stats['avg_confidence'] ?? 0), 1); ?>%</td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <p><em>More detailed reports coming in future updates!</em></p>
        </div>
        <?php
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        $whatsapp_raw = sanitize_text_field($_POST['whatsapp_number']);
        $bank_name = sanitize_text_field($_POST['bank_name']);
        $account_number = sanitize_text_field($_POST['account_number']);
        $account_holder = sanitize_text_field($_POST['account_holder']);
        
        // Clean WhatsApp number
        $whatsapp_clean = preg_replace('/[^0-9]/', '', $whatsapp_raw);
        
        // Save to database
        update_option('ssfood4u_whatsapp', $whatsapp_clean);
        update_option('ssfood4u_bank_name', $bank_name);
        update_option('ssfood4u_account_number', $account_number);
        update_option('ssfood4u_account_holder', $account_holder);
        
        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }
    
    /**
     * Handle admin actions (approve/reject)
     */
    private function handle_admin_action() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['admin_nonce'], 'ssfood4u_admin_action')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        
        $payment_id = intval($_POST['payment_id']);
        $action = sanitize_text_field($_POST['action']);
        
        if ($action === 'approve') {
            if (SSFood4U_Database::update_payment_status($payment_id, 'verified')) {
                echo '<div class="notice notice-success"><p>Payment approved successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to approve payment.</p></div>';
            }
        } elseif ($action === 'reject') {
            if (SSFood4U_Database::update_payment_status($payment_id, 'rejected')) {
                echo '<div class="notice notice-warning"><p>Payment rejected.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to reject payment.</p></div>';
            }
        }
    }
    
    /**
     * Admin setup notices
     */
    public function admin_setup_notices() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'ssfood4u') === false) {
            return;
        }
        
        $whatsapp = get_option('ssfood4u_whatsapp', '60123456789');
        $ocr_key = get_option('ssfood4u_ocr_api_key', '');
        $qr_exists = file_exists(SSFOOD4U_PLUGIN_DIR . 'assets/ssfood4u-payment-qr.png');
        
        $issues = array();
        if ($whatsapp === '60123456789') $issues[] = '<a href="' . admin_url('admin.php?page=ssfood4u-settings') . '">Configure your WhatsApp number</a>';
        if (empty($ocr_key)) $issues[] = '<a href="' . admin_url('admin.php?page=ssfood4u-settings') . '">Configure your OCR API key</a>';
        if (!$qr_exists) $issues[] = 'Upload your QR code to /assets/ssfood4u-payment-qr.png';
        
        if (!empty($issues)) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>SSFood4U Setup Required:</strong> ' . implode(' | ', $issues);
            echo '</p></div>';
        }
    }
    
    /**
     * Render setup information
     */
    private function render_setup_info() {
        ?>
        <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-radius: 8px;">
            <h3>Setup Instructions</h3>
            <ol>
                <li><strong>QR Code:</strong> Upload your payment QR code as <code>/wp-content/plugins/ssfood4u-main/assets/ssfood4u-payment-qr.png</code></li>
                <li><strong>WhatsApp Number:</strong> Update your WhatsApp number in Settings</li>
                <li><strong>Bank Details:</strong> Update the manual transfer details in Settings</li>
                <li><strong>OCR API Key:</strong> Get your free API key from OCR.space and configure it</li>
                <li><strong>Testing:</strong> Place a test order to see how the system works</li>
                <li><strong>Email Notifications:</strong> Check your email when new payments are submitted</li>
            </ol>
            
            <div style="background: #e8f4fd; padding: 15px; border-radius: 4px; margin-top: 15px;">
                <h4>File Structure Check:</h4>
                <p>Make sure these files exist:</p>
                <ul style="font-family: monospace; font-size: 12px;">
                    <li><?php 
                        $qr_path = SSFOOD4U_PLUGIN_DIR . 'assets/ssfood4u-payment-qr.png';
                        echo file_exists($qr_path) ? '✅' : '❌'; 
                    ?> /assets/ssfood4u-payment-qr.png</li>
                    <li><?php 
                        $ocr_path = SSFOOD4U_PLUGIN_DIR . 'includes/class-enhanced-ocr-validator.php';
                        echo file_exists($ocr_path) ? '✅' : '❌'; 
                    ?> /includes/class-enhanced-ocr-validator.php</li>
                    <li><?php 
                        $assets_dir = SSFOOD4U_PLUGIN_DIR . 'assets/';
                        echo is_dir($assets_dir) ? '✅' : '❌'; 
                    ?> /assets/ directory</li>
                    <li><?php 
                        $includes_dir = SSFOOD4U_PLUGIN_DIR . 'includes/';
                        echo is_dir($includes_dir) ? '✅' : '❌'; 
                    ?> /includes/ directory</li>
                </ul>
                
                <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin-top: 10px; border-radius: 4px;">
                    <strong>Enhanced OCR Status:</strong>
                    <?php if (get_option('ssfood4u_ocr_api_key', '')): ?>
                        <span style="color: #155724;">✅ OCR API Key Configured</span>
                    <?php else: ?>
                        <span style="color: #721c24;">❌ OCR API Key Missing</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render setup checklist
     */
    private function render_setup_checklist() {
        $whatsapp = get_option('ssfood4u_whatsapp', '60123456789');
        $bank_name = get_option('ssfood4u_bank_name', 'Update with your bank name');
        $ocr_key = get_option('ssfood4u_ocr_api_key', '');
        $qr_exists = file_exists(SSFOOD4U_PLUGIN_DIR . 'assets/ssfood4u-payment-qr.png');
        ?>
        <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-radius: 8px;">
            <h3>Setup Checklist</h3>
            <ul>
                <li><strong>✅ Plugin Activated:</strong> SSFood4U Payment Verification is active</li>
                <li><strong><?php echo $qr_exists ? '✅' : '❌'; ?> QR Code:</strong> Upload your payment QR code</li>
                <li><strong><?php echo ($whatsapp !== '60123456789') ? '✅' : '❌'; ?> WhatsApp:</strong> Configure your WhatsApp number</li>
                <li><strong><?php echo ($bank_name !== 'Update with your bank name') ? '✅' : '❌'; ?> Bank Details:</strong> Update your bank information</li>
                <li><strong><?php echo !empty($ocr_key) ? '✅' : '❌'; ?> OCR API Key:</strong> Configure OCR.space API key for automatic validation</li>
            </ul>
            
            <h4>Quick Actions</h4>
            <p>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-payments'); ?>" class="button">View Payment Verifications</a>
                <a href="<?php echo site_url('/checkout'); ?>" class="button" target="_blank">Test Checkout Page</a>
                <?php if (!empty($ocr_key)): ?>
                <a href="<?php echo admin_url('admin.php?page=ssfood4u-settings'); ?>" class="button button-primary">Test OCR System</a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * AJAX: Approve payment
     */
    public function approve_payment() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ssfood4u_admin_nonce')) {
            wp_die(json_encode(array('success' => false, 'message' => 'Unauthorized')));
        }
        
        $payment_id = intval($_POST['payment_id']);
        
        if (SSFood4U_Database::update_payment_status($payment_id, 'verified')) {
            wp_die(json_encode(array('success' => true, 'message' => 'Payment approved')));
        } else {
            wp_die(json_encode(array('success' => false, 'message' => 'Failed to approve payment')));
        }
    }
    
    /**
     * AJAX: Reject payment
     */
    public function reject_payment() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ssfood4u_admin_nonce')) {
            wp_die(json_encode(array('success' => false, 'message' => 'Unauthorized')));
        }
        
        $payment_id = intval($_POST['payment_id']);
        
        if (SSFood4U_Database::update_payment_status($payment_id, 'rejected')) {
            wp_die(json_encode(array('success' => true, 'message' => 'Payment rejected')));
        } else {
            wp_die(json_encode(array('success' => false, 'message' => 'Failed to reject payment')));
        }
    }
}
?>