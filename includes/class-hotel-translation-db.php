<?php
/**
 * Semporna Hotel Translation Database System
 * Manages English-Mandarin hotel name translations with admin interface
 * UPDATED: Working edit/delete functionality and better debugging
 */

if (!defined('ABSPATH')) exit;

class SSFood4U_Hotel_Translation_DB {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ssfood4u_hotel_translations';
        $this->create_table();
        $this->populate_default_hotels();
        $this->init_ajax_handlers();
    }
    
    /**
     * Initialize AJAX handlers
     */
    private function init_ajax_handlers() {
        add_action('wp_ajax_update_hotel', array($this, 'ajax_update_hotel'));
        add_action('wp_ajax_delete_hotel', array($this, 'ajax_delete_hotel'));
        add_action('wp_ajax_export_hotels', array($this, 'ajax_export_hotels'));
        add_action('wp_ajax_refresh_hotel_translations', array($this, 'ajax_refresh_translations'));
    }
    
    /**
     * Create hotel translations table
     */
    private function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            english_name varchar(255) NOT NULL,
            chinese_name varchar(255) NOT NULL,
            hotel_type enum('hotel','resort','guesthouse','inn','homestay','other') DEFAULT 'hotel',
            location varchar(100) DEFAULT 'semporna',
            status enum('active','inactive') DEFAULT 'active',
            added_date datetime DEFAULT CURRENT_TIMESTAMP,
            updated_date datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY english_name (english_name),
            KEY chinese_name (chinese_name),
            KEY hotel_type (hotel_type),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Populate table with known Semporna hotels
     */
    private function populate_default_hotels() {
        if ($this->count_hotels() > 0) {
            return; // Already populated
        }
        
        $default_hotels = array(
            // Major Hotels
            array('Seafest Hotel', '海丰酒店', 'hotel'),
            array('Sea Star Resort Semporna', '仙本那海星度假村', 'resort'),
            array('Grace Hotel Semporna', '格蕾丝酒店仙本那', 'hotel'),
            array('Grand Luxury Hotel Semporna', '仙本那豪华大酒店', 'hotel'),
            array('Green World Hotel', '绿色世界酒店', 'hotel'),
            array('Ocean Inn Semporna', '仙本那海洋酒店', 'inn'),
            array('Sunshine Boutique Hotel', '阳光精品酒店', 'hotel'),
            array('Memory Boutique Hotel', '记忆精品酒店', 'hotel'),
            
            // Inns & Guesthouses
            array('Sipadan Inn', '西巴丹客栈', 'inn'),
            array('Sipadan Inn 2', '西巴丹客栈2号', 'inn'),
            array('Sipadan Inn 3', '西巴丹客栈3号', 'inn'),
            array('Dragon Inn', '龙门客栈', 'inn'),
            array('Shoreline Guesthouse', '海岸线客栈', 'guesthouse'),
            array('Uptown Hotel', '市中心酒店', 'hotel'),
            
            // Budget & Homestays
            array('SBR Inn', 'SBR客栈', 'inn'),
            array('The Cozy Cottage Semporna', '仙本那舒适小屋', 'homestay'),
            array('Bigfin Water Bungalow', '大鳍水上屋', 'resort'),
            array('Blue Ocean Resort', '蓝海度假村', 'resort'),
            
            // Chinese-named establishments
            array('Ting Hai Resort', '听海度假庄园', 'resort'),
            array('Qiao Zhijia Homestay', '侨之家民宿', 'homestay'),
            array('Camphor Tree House', '樟树屋', 'homestay'),
            
            // Island Resorts (nearby)
            array('Sipadan Kapalai Dive Resort', '西巴丹卡帕莱潜水度假村', 'resort'),
            array('Mabul Water Bungalows', '马布岛水上屋', 'resort'),
            array('Sipadan Mabul Resort', '西巴丹马布度假村', 'resort'),
            
            // Common generic terms
            array('hotel', '酒店', 'other'),
            array('resort', '度假村', 'other'),
            array('inn', '客栈', 'other'),
            array('guesthouse', '民宿', 'other'),
            array('homestay', '家庭旅馆', 'other'),
            array('lodge', '旅舍', 'other')
        );
        
        foreach ($default_hotels as $hotel) {
            $this->add_hotel($hotel[0], $hotel[1], $hotel[2]);
        }
        
        // Clear cache after populating
        delete_option('ssfood4u_hotel_translations_cache');
    }
    
    /**
     * Add a new hotel translation
     */
    public function add_hotel($english_name, $chinese_name, $hotel_type = 'hotel', $location = 'semporna') {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'english_name' => sanitize_text_field($english_name),
                'chinese_name' => sanitize_text_field($chinese_name),
                'hotel_type' => $hotel_type,
                'location' => $location,
                'status' => 'active'
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            // Clear cache when adding new hotel
            delete_option('ssfood4u_hotel_translations_cache');
        }
        
        return $result !== false;
    }
    
    /**
     * Update existing hotel
     */
    public function update_hotel($id, $english_name, $chinese_name, $hotel_type = 'hotel') {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'english_name' => sanitize_text_field($english_name),
                'chinese_name' => sanitize_text_field($chinese_name),
                'hotel_type' => $hotel_type
            ),
            array('id' => $id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            // Clear cache when updating hotel
            delete_option('ssfood4u_hotel_translations_cache');
        }
        
        return $result !== false;
    }
    
    /**
     * Delete hotel
     */
    public function delete_hotel($id) {
        global $wpdb;
        $result = $wpdb->delete($this->table_name, array('id' => $id), array('%d'));
        
        if ($result) {
            // Clear cache when deleting hotel
            delete_option('ssfood4u_hotel_translations_cache');
        }
        
        return $result !== false;
    }
    
    /**
     * Get all active hotels
     */
    public function get_all_hotels($status = 'active') {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE status = %s ORDER BY english_name ASC",
            $status
        ));
    }
    
    /**
     * Get hotel translation array for validation system
     */
    public function get_translation_array() {
        $hotels = $this->get_all_hotels();
        $translations = array();
        
        foreach ($hotels as $hotel) {
            $translations[$hotel->chinese_name] = strtolower($hotel->english_name);
        }
        
        // Debug: Log translation count
        error_log("SSFood4U: Built translation array with " . count($translations) . " entries");
        
        return $translations;
    }
    
    /**
     * Search hotels by name (Chinese or English)
     */
    public function search_hotels($search_term) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE (english_name LIKE %s OR chinese_name LIKE %s) 
             AND status = 'active'
             ORDER BY english_name ASC",
            '%' . $search_term . '%',
            '%' . $search_term . '%'
        ));
    }
    
    /**
     * Count total hotels
     */
    public function count_hotels($status = 'active') {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s",
            $status
        ));
    }
    
    /**
     * AJAX handler for updating hotel
     */
    public function ajax_update_hotel() {
        check_ajax_referer('update_hotel', 'nonce');
        
        $hotel_id = intval($_POST['hotel_id']);
        $english_name = sanitize_text_field($_POST['english_name']);
        $chinese_name = sanitize_text_field($_POST['chinese_name']);
        $hotel_type = sanitize_text_field($_POST['hotel_type']);
        
        if ($this->update_hotel($hotel_id, $english_name, $chinese_name, $hotel_type)) {
            wp_send_json_success('Hotel updated successfully');
        } else {
            wp_send_json_error('Failed to update hotel');
        }
    }
    
    /**
     * AJAX handler for deleting hotel
     */
    public function ajax_delete_hotel() {
        check_ajax_referer('delete_hotel', 'nonce');
        
        $hotel_id = intval($_POST['hotel_id']);
        
        if ($this->delete_hotel($hotel_id)) {
            wp_send_json_success('Hotel deleted successfully');
        } else {
            wp_send_json_error('Failed to delete hotel');
        }
    }
    
    /**
     * AJAX handler for exporting hotels
     */
    public function ajax_export_hotels() {
        check_ajax_referer('export_hotels', 'nonce');
        
        $hotels = $this->get_all_hotels();
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="semporna-hotels-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, array('ID', 'English Name', 'Chinese Name', 'Type', 'Added Date'));
        
        foreach ($hotels as $hotel) {
            fputcsv($output, array(
                $hotel->id,
                $hotel->english_name,
                $hotel->chinese_name,
                $hotel->hotel_type,
                $hotel->added_date
            ));
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Admin interface HTML
     */
    public function render_admin_page() {
        // Handle form submissions
        if (isset($_POST['action'])) {
            $this->handle_admin_actions();
        }
        
        $hotels = $this->get_all_hotels();
        
        ?>
        <div class="wrap">
            <h1>Hotel Translation Database</h1>
            
            <!-- Add New Hotel Form -->
            <div class="card" style="max-width: none;">
                <h2>Add New Hotel</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('ssfood4u_hotel_action', 'ssfood4u_hotel_nonce'); ?>
                    <input type="hidden" name="action" value="add_hotel">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">English Name</th>
                            <td><input type="text" name="english_name" class="regular-text" required placeholder="e.g., Seafest Hotel"></td>
                        </tr>
                        <tr>
                            <th scope="row">Chinese Name</th>
                            <td><input type="text" name="chinese_name" class="regular-text" required placeholder="e.g., 海丰酒店"></td>
                        </tr>
                        <tr>
                            <th scope="row">Type</th>
                            <td>
                                <select name="hotel_type">
                                    <option value="hotel">Hotel (酒店)</option>
                                    <option value="resort">Resort (度假村)</option>
                                    <option value="inn">Inn (客栈)</option>
                                    <option value="guesthouse">Guesthouse (民宿)</option>
                                    <option value="homestay">Homestay (家庭旅馆)</option>
                                    <option value="other">Other</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit" class="button-primary" value="Add Hotel">
                    </p>
                </form>
            </div>
            
            <!-- Hotels List -->
            <div class="card" style="max-width: none;">
                <h2>Current Hotels (<?php echo $this->count_hotels(); ?> total)</h2>
                
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <input type="text" id="hotel-search" placeholder="Search hotels..." style="width: 250px;">
                        <button type="button" id="search-btn" class="button">Search</button>
                        <button type="button" id="clear-search" class="button">Clear</button>
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="5%">ID</th>
                            <th width="35%">English Name</th>
                            <th width="35%">Chinese Name</th>
                            <th width="15%">Type</th>
                            <th width="10%">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="hotels-tbody">
                        <?php foreach ($hotels as $hotel): ?>
                        <tr data-hotel-id="<?php echo $hotel->id; ?>">
                            <td><?php echo $hotel->id; ?></td>
                            <td><strong><?php echo esc_html($hotel->english_name); ?></strong></td>
                            <td><?php echo esc_html($hotel->chinese_name); ?></td>
                            <td>
                                <span class="hotel-type-<?php echo $hotel->hotel_type; ?>">
                                    <?php echo ucfirst($hotel->hotel_type); ?>
                                </span>
                            </td>
                            <td>
                                <a href="#" class="edit-hotel" data-id="<?php echo $hotel->id; ?>">Edit</a> |
                                <a href="#" class="delete-hotel" data-id="<?php echo $hotel->id; ?>" style="color: #a00;">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Import/Export Tools -->
            <div class="card" style="max-width: none;">
                <h2>Tools</h2>
                <p>
                    <button type="button" id="export-hotels" class="button">Export All Hotels (CSV)</button>
                    <button type="button" id="refresh-validation" class="button button-primary">Refresh Delivery Validator</button>
                    <button type="button" id="test-validation" class="button">Test Validation</button>
                </p>
                <p><small>Use "Refresh Delivery Validator" after adding new hotels to update the address validation system.</small></p>
                
                <div id="test-results" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-radius: 4px; display: none;">
                    <h4>Test Results:</h4>
                    <div id="test-output"></div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var admin_url = '<?php echo admin_url("admin-ajax.php"); ?>';
            
            // Search functionality
            $('#search-btn').click(function() {
                var searchTerm = $('#hotel-search').val();
                if (searchTerm.length > 0) {
                    $('#hotels-tbody tr').hide();
                    $('#hotels-tbody tr').each(function() {
                        var rowText = $(this).text().toLowerCase();
                        if (rowText.indexOf(searchTerm.toLowerCase()) !== -1) {
                            $(this).show();
                        }
                    });
                } else {
                    $('#hotels-tbody tr').show();
                }
            });
            
            // Clear search
            $('#clear-search').click(function() {
                $('#hotel-search').val('');
                $('#hotels-tbody tr').show();
            });
            
            // Search on Enter key
            $('#hotel-search').keypress(function(e) {
                if (e.which == 13) {
                    $('#search-btn').click();
                }
            });
            
            // Edit hotel functionality
            $('.edit-hotel').click(function(e) {
                e.preventDefault();
                var hotelId = $(this).data('id');
                var row = $(this).closest('tr');
                
                // Get current values
                var englishName = row.find('td:eq(1) strong').text();
                var chineseName = row.find('td:eq(2)').text();
                var hotelType = row.find('td:eq(3) span').text().toLowerCase();
                
                // Prompt for new values
                var newEnglish = prompt('Edit English Name:', englishName);
                if (newEnglish === null) return; // User cancelled
                
                var newChinese = prompt('Edit Chinese Name:', chineseName);
                if (newChinese === null) return; // User cancelled
                
                if (newEnglish && newChinese) {
                    $.post(admin_url, {
                        action: 'update_hotel',
                        hotel_id: hotelId,
                        english_name: newEnglish,
                        chinese_name: newChinese,
                        hotel_type: hotelType,
                        nonce: '<?php echo wp_create_nonce('update_hotel'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('Hotel updated successfully!');
                            location.reload();
                        } else {
                            alert('Error updating hotel: ' + response.data);
                        }
                    });
                }
            });
            
            // Delete hotel functionality
            $('.delete-hotel').click(function(e) {
                e.preventDefault();
                var hotelId = $(this).data('id');
                var hotelName = $(this).closest('tr').find('td:eq(1) strong').text();
                
                if (confirm('Are you sure you want to delete "' + hotelName + '"?\n\nThis action cannot be undone.')) {
                    $.post(admin_url, {
                        action: 'delete_hotel',
                        hotel_id: hotelId,
                        nonce: '<?php echo wp_create_nonce('delete_hotel'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('Hotel deleted successfully!');
                            location.reload();
                        } else {
                            alert('Error deleting hotel: ' + response.data);
                        }
                    });
                }
            });
            
            // Export functionality
            $('#export-hotels').click(function() {
                window.location.href = admin_url + '?action=export_hotels&nonce=' + '<?php echo wp_create_nonce('export_hotels'); ?>';
            });
            
            // Refresh validation system
            $('#refresh-validation').click(function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Refreshing...');
                
                $.post(admin_url, {
                    action: 'refresh_hotel_translations',
                    nonce: '<?php echo wp_create_nonce('refresh_translations'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Validation system updated with ' + response.data.count + ' hotels!');
                    } else {
                        alert('Error refreshing validation system');
                    }
                    btn.prop('disabled', false).text('Refresh Delivery Validator');
                });
            });
            
            // Test validation functionality
            $('#test-validation').click(function() {
                var testCases = ['Dragon Inn', '龙门客栈', 'Grace Hotel', '格蕾丝酒店仙本那'];
                var results = [];
                
                $('#test-results').show();
                $('#test-output').html('<p>Testing translations...</p>');
                
                // Test each case
                testCases.forEach(function(testCase, index) {
                    setTimeout(function() {
                        $.post(admin_url, {
                            action: 'test_translation',
                            test_address: testCase,
                            nonce: '<?php echo wp_create_nonce('test_translation'); ?>'
                        }, function(response) {
                            results.push('<strong>' + testCase + ':</strong> ' + (response.success ? 'Found' : 'Not Found'));
                            
                            if (results.length === testCases.length) {
                                $('#test-output').html(results.join('<br>'));
                            }
                        });
                    }, index * 500);
                });
            });
        });
        </script>
        
        <style>
        .hotel-type-hotel { color: #0073aa; }
        .hotel-type-resort { color: #00a32a; }
        .hotel-type-inn { color: #ff8c00; }
        .hotel-type-guesthouse { color: #8b4513; }
        .hotel-type-homestay { color: #9b59b6; }
        .hotel-type-other { color: #666; }
        
        #test-results {
            border: 1px solid #ddd;
        }
        </style>
        <?php
    }
    
    /**
     * Handle admin form submissions
     */
    private function handle_admin_actions() {
        if (!isset($_POST['ssfood4u_hotel_nonce']) || 
            !wp_verify_nonce($_POST['ssfood4u_hotel_nonce'], 'ssfood4u_hotel_action')) {
            return;
        }
        
        if ($_POST['action'] === 'add_hotel') {
            $result = $this->add_hotel(
                $_POST['english_name'],
                $_POST['chinese_name'],
                $_POST['hotel_type']
            );
            
            if ($result) {
                echo '<div class="notice notice-success"><p>Hotel added successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Error adding hotel. Name may already exist.</p></div>';
            }
        }
    }
    
    /**
     * AJAX handler for refreshing translations
     */
    public function ajax_refresh_translations() {
        check_ajax_referer('refresh_translations', 'nonce');
        
        $translations = $this->get_translation_array();
        update_option('ssfood4u_hotel_translations_cache', $translations);
        
        error_log("SSFood4U: Refreshed translation cache with " . count($translations) . " entries");
        
        wp_send_json_success(array('count' => count($translations)));
    }
    
    /**
     * Get cached translations for delivery validator
     */
    public function get_cached_translations() {
        $cached = get_option('ssfood4u_hotel_translations_cache', array());
        
        if (empty($cached)) {
            $cached = $this->get_translation_array();
            update_option('ssfood4u_hotel_translations_cache', $cached);
            error_log("SSFood4U: Created new translation cache with " . count($cached) . " entries");
        } else {
            error_log("SSFood4U: Using cached translations with " . count($cached) . " entries");
        }
        
        return $cached;
    }
}

// Initialize the system
$ssfood4u_hotel_db = new SSFood4U_Hotel_Translation_DB();
?>