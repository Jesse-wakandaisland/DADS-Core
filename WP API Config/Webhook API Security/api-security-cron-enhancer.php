<?php
/*
Plugin Name: API Security & Cron Enhancer
Description: Enhances security for API Exposer and Webhook Manager plugins while adding cron scheduling capabilities
Version: 1.0
Author: ConvoBuilder.com
Requires at least: 5.0
Requires PHP: 7.2
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('API_SECURITY_ENHANCER_VERSION', '1.0.0');
define('API_SECURITY_ENHANCER_PATH', plugin_dir_path(__FILE__));
define('API_SECURITY_ENHANCER_URL', plugin_dir_url(__FILE__));

class API_Security_Cron_Enhancer {
    // Static instance
    private static $instance = null;
    
    // Store security settings
    private $security_settings = array();
    
    // Store cron schedules
    private $cron_schedules = array();
    
    // API Exposer endpoints with cron
    private $endpoints_with_cron = array();
    
    /**
     * Constructor
     */
    private function __construct() {
        // Initialize the plugin
        add_action('init', array($this, 'init'), 10);
    }
    
    /**
     * Get the singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load security settings
        $this->load_security_settings();
        
        // Load cron schedules
        $this->load_cron_schedules();
        
        // Load endpoints with cron
        $this->load_endpoints_with_cron();
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Add AJAX handlers
        add_action('wp_ajax_api_security_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_api_security_test_cron', array($this, 'ajax_test_cron'));
        add_action('wp_ajax_api_security_add_cron', array($this, 'ajax_add_cron'));
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Add cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        // Add API Exposer integration if available
        if ($this->is_api_exposer_active()) {
            add_action('api_exposer_endpoint_created', array($this, 'handle_new_endpoint'), 10, 2);
            add_filter('api_exposer_admin_tabs', array($this, 'add_cron_tab'));
        }
        
        // Security enhancements
        $this->apply_security_enhancements();
        
        // Schedule cron events
        $this->schedule_cron_events();
    }
    
    /**
     * Load security settings
     */
    private function load_security_settings() {
        $settings = get_option('api_security_settings', array());
        
        // Set defaults if not set
        $this->security_settings = wp_parse_args($settings, array(
            'enable_ip_whitelist' => false,
            'ip_whitelist' => array(),
            'enable_api_key' => false,
            'api_key' => $this->generate_api_key(),
            'enable_rate_limiting' => false,
            'rate_limit' => 60, // requests per minute
            'enable_logging' => true,
            'log_retention' => 7, // days
            'restrict_endpoints' => false,
            'restricted_endpoints' => array(),
            'disable_non_ssl' => false,
            'require_nonce' => true
        ));
    }
    
    /**
     * Load cron schedules
     */
    private function load_cron_schedules() {
        $schedules = get_option('api_security_cron_schedules', array());
        
        // Set defaults if not set
        $this->cron_schedules = wp_parse_args($schedules, array(
            'everyminute' => array(
                'interval' => 60,
                'display' => 'Every Minute'
            ),
            'everyfiveminutes' => array(
                'interval' => 300,
                'display' => 'Every 5 Minutes'
            ),
            'everyfifteenminutes' => array(
                'interval' => 900,
                'display' => 'Every 15 Minutes'
            ),
            'everyhalfhour' => array(
                'interval' => 1800,
                'display' => 'Every 30 Minutes'
            )
        ));
    }
    
    /**
     * Load endpoints with cron
     */
    private function load_endpoints_with_cron() {
        $this->endpoints_with_cron = get_option('api_security_endpoints_with_cron', array());
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'API Security & Cron',
            'API Security',
            'manage_options',
            'api-security',
            array($this, 'render_security_page'),
            'dashicons-shield',
            32
        );
        
        add_submenu_page(
            'api-security',
            'Security Settings',
            'Security Settings',
            'manage_options',
            'api-security',
            array($this, 'render_security_page')
        );
        
        add_submenu_page(
            'api-security',
            'Cron Scheduler',
            'Cron Scheduler',
            'manage_options',
            'api-security-cron',
            array($this, 'render_cron_page')
        );
        
        add_submenu_page(
            'api-security',
            'Security Logs',
            'Security Logs',
            'manage_options',
            'api-security-logs',
            array($this, 'render_logs_page')
        );
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('api-security/v1', '/cron/(?P<endpoint_id>[a-zA-Z0-9-_]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_cron_request'),
            'permission_callback' => array($this, 'verify_cron_permission')
        ));
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'api-security') !== false) {
            // Create assets directories if they don't exist
            $this->ensure_assets_exist();
            
            // Enqueue the main script
            wp_enqueue_script(
                'api-security-admin',
                API_SECURITY_ENHANCER_URL . 'assets/js/admin.js',
                array('jquery'),
                API_SECURITY_ENHANCER_VERSION,
                true
            );
            
            // Localize the script with data
            wp_localize_script(
                'api-security-admin',
                'apiSecurityData',
                array(
                    'ajax_url' => admin_url('ajax.php'),
                    'nonce' => wp_create_nonce('api_security_nonce'),
                    'api_exposer_active' => $this->is_api_exposer_active(),
                    'webhook_manager_active' => $this->is_webhook_manager_active()
                )
            );
            
            // Enqueue the styles
            wp_enqueue_style(
                'api-security-admin',
                API_SECURITY_ENHANCER_URL . 'assets/css/admin.css',
                array(),
                API_SECURITY_ENHANCER_VERSION
            );
        }
    }
    
    /**
     * Ensure assets exist
     */
    private function ensure_assets_exist() {
        // Create assets directory
        $assets_dir = API_SECURITY_ENHANCER_PATH . 'assets';
        if (!file_exists($assets_dir)) {
            wp_mkdir_p($assets_dir);
            
            // Create js directory
            $js_dir = $assets_dir . '/js';
            if (!file_exists($js_dir)) {
                wp_mkdir_p($js_dir);
                
                // Create admin.js
                $admin_js_content = "jQuery(document).ready(function($) {
    // Handle security settings form
    $('#api-security-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        
        $.ajax({
            url: apiSecurityData.ajax_url,
            type: 'POST',
            data: {
                action: 'api_security_save_settings',
                nonce: apiSecurityData.nonce,
                form_data: formData
            },
            success: function(response) {
                if (response.success) {
                    alert('Settings saved successfully!');
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('An error occurred while saving settings.');
            }
        });
    });
    
    // Handle test cron button
    $('.test-cron').on('click', function() {
        var endpoint_id = $(this).data('endpoint');
        
        $.ajax({
            url: apiSecurityData.ajax_url,
            type: 'POST',
            data: {
                action: 'api_security_test_cron',
                nonce: apiSecurityData.nonce,
                endpoint_id: endpoint_id
            },
            success: function(response) {
                if (response.success) {
                    alert('Cron test successful! Response: ' + response.data.response);
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('An error occurred while testing the cron job.');
            }
        });
    });
    
    // Handle add cron form
    $('#api-cron-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        
        $.ajax({
            url: apiSecurityData.ajax_url,
            type: 'POST',
            data: {
                action: 'api_security_add_cron',
                nonce: apiSecurityData.nonce,
                form_data: formData
            },
            success: function(response) {
                if (response.success) {
                    alert('Cron job added successfully!');
                    window.location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('An error occurred while adding the cron job.');
            }
        });
    });
    
    // Toggle IP whitelist input
    $('#enable_ip_whitelist').on('change', function() {
        if ($(this).is(':checked')) {
            $('.ip-whitelist-container').show();
        } else {
            $('.ip-whitelist-container').hide();
        }
    }).trigger('change');
    
    // Toggle API key input
    $('#enable_api_key').on('change', function() {
        if ($(this).is(':checked')) {
            $('.api-key-container').show();
        } else {
            $('.api-key-container').hide();
        }
    }).trigger('change');
    
    // Toggle rate limiting input
    $('#enable_rate_limiting').on('change', function() {
        if ($(this).is(':checked')) {
            $('.rate-limit-container').show();
        } else {
            $('.rate-limit-container').hide();
        }
    }).trigger('change');
    
    // Generate new API key
    $('#generate-api-key').on('click', function(e) {
        e.preventDefault();
        
        var key = '';
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        
        for (var i = 0; i < 32; i++) {
            key += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        
        $('#api_key').val(key);
    });
});";
                file_put_contents($js_dir . '/admin.js', $admin_js_content);
            }
            
            // Create css directory
            $css_dir = $assets_dir . '/css';
            if (!file_exists($css_dir)) {
                wp_mkdir_p($css_dir);
                
                // Create admin.css
                $admin_css_content = "/* API Security & Cron Enhancer styles */
.api-security-container {
    margin-top: 20px;
}

.api-security-card {
    background: #fff;
    border: 1px solid #ddd;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.api-security-title {
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
    margin-bottom: 20px;
}

.form-section {
    margin-bottom: 30px;
}

.form-row {
    margin-bottom: 15px;
}

.form-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.form-controls {
    margin-top: 20px;
    text-align: right;
}

table.cron-jobs {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

table.cron-jobs th,
table.cron-jobs td {
    padding: 10px;
    text-align: left;
    border: 1px solid #ddd;
}

table.cron-jobs th {
    background-color: #f8f8f8;
    font-weight: bold;
}

.security-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-secure {
    background-color: #d4edda;
    color: #155724;
}

.status-warning {
    background-color: #fff3cd;
    color: #856404;
}

.status-danger {
    background-color: #f8d7da;
    color: #721c24;
}

/* Toggle switch */
.switch {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 34px;
}

.slider:before {
    position: absolute;
    content: \"\";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #2196F3;
}

input:focus + .slider {
    box-shadow: 0 0 1px #2196F3;
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.ip-whitelist-container,
.api-key-container,
.rate-limit-container {
    display: none;
    margin-top: 10px;
    padding-left: 20px;
}

.security-dashboard {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.dashboard-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    padding: 15px;
}

.dashboard-card h3 {
    margin-top: 0;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.security-log-entry {
    border-bottom: 1px solid #eee;
    padding: 10px 0;
}

.security-log-entry:last-child {
    border-bottom: none;
}

.security-log-time {
    color: #666;
    font-size: 0.8em;
}

.security-log-message {
    margin-top: 5px;
}

.security-log-level-info {
    color: #0c5460;
}

.security-log-level-warning {
    color: #856404;
}

.security-log-level-error {
    color: #721c24;
}";
                file_put_contents($css_dir . '/admin.css', $admin_css_content);
            }
        }
    }
    
    /**
     * Check if API Exposer is active
     */
    private function is_api_exposer_active() {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        $api_exposer_plugins = array(
            'api-exposer/api-exposer.php',
            'api-exposer-webhook-companion/api-exposer.php'
        );
        
        foreach ($api_exposer_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if Webhook Manager is active
     */
    private function is_webhook_manager_active() {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        $webhook_plugins = array(
            'webhook-manager/webhook-manager.php',
            'enhanced-webhook-manager/webhook-manager.php'
        );
        
        foreach ($webhook_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate API key
     */
    private function generate_api_key() {
        return wp_generate_password(32, false);
    }
    
    /**
     * Apply security enhancements
     */
    private function apply_security_enhancements() {
        // IP Whitelist
        if ($this->security_settings['enable_ip_whitelist']) {
            add_filter('rest_authentication_errors', array($this, 'validate_ip_whitelist'));
        }
        
        // API Key Validation
        if ($this->security_settings['enable_api_key']) {
            add_filter('rest_authentication_errors', array($this, 'validate_api_key'));
        }
        
        // Rate Limiting
        if ($this->security_settings['enable_rate_limiting']) {
            add_filter('rest_pre_dispatch', array($this, 'check_rate_limit'), 10, 3);
        }
        
        // SSL Requirement
        if ($this->security_settings['disable_non_ssl']) {
            add_filter('rest_pre_dispatch', array($this, 'require_ssl'), 10, 3);
        }
        
        // Nonce Validation
        if ($this->security_settings['require_nonce']) {
            add_filter('rest_authentication_errors', array($this, 'validate_nonce'));
        }
        
        // Endpoint Restrictions
        if ($this->security_settings['restrict_endpoints']) {
            add_filter('rest_pre_dispatch', array($this, 'restrict_endpoints'), 10, 3);
        }
        
        // Security Logging
        if ($this->security_settings['enable_logging']) {
            add_action('rest_api_init', array($this, 'log_api_requests'), 10);
            
            // Schedule log cleanup if not already scheduled
            if (!wp_next_scheduled('api_security_log_cleanup')) {
                wp_schedule_event(time(), 'daily', 'api_security_log_cleanup');
            }
        }
        
        // Hook into log cleanup
        add_action('api_security_log_cleanup', array($this, 'cleanup_security_logs'));
    }
    
    /**
     * Schedule cron events
     */
    private function schedule_cron_events() {
        // Register cron event hook
        add_action('api_security_cron_event', array($this, 'execute_cron_event'), 10, 1);
        
        // Loop through endpoints with cron and ensure they're scheduled
        foreach ($this->endpoints_with_cron as $endpoint_id => $cron_data) {
            $hook_name = 'api_security_cron_event';
            $args = array($endpoint_id);
            
            // Check if already scheduled
            if (!wp_next_scheduled($hook_name, $args)) {
                // Schedule the event
                wp_schedule_event(time(), $cron_data['schedule'], $hook_name, $args);
            }
        }
    }
    
    /**
     * Add cron schedules
     */
    public function add_cron_schedules($schedules) {
        foreach ($this->cron_schedules as $name => $schedule) {
            if (!isset($schedules[$name])) {
                $schedules[$name] = array(
                    'interval' => $schedule['interval'],
                    'display' => $schedule['display']
                );
            }
        }
        
        return $schedules;
    }
    
    /**
     * Render security page
     */
    public function render_security_page() {
        ?>
        <div class="wrap">
            <h1>API Security Settings</h1>
            
            <div class="api-security-container">
                <div class="api-security-card">
                    <h2 class="api-security-title">Security Dashboard</h2>
                    
                    <div class="security-dashboard">
                        <div class="dashboard-card">
                            <h3>Security Status</h3>
                            <?php 
                            $security_score = $this->calculate_security_score();
                            $status_class = 'status-danger';
                            $status_text = 'Vulnerable';
                            
                            if ($security_score >= 80) {
                                $status_class = 'status-secure';
                                $status_text = 'Secure';
                            } elseif ($security_score >= 50) {
                                $status_class = 'status-warning';
                                $status_text = 'Moderate';
                            }
                            ?>
                            <p>Security Score: <strong><?php echo $security_score; ?>%</strong></p>
                            <p>Status: <span class="security-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span></p>
                            <p><?php echo $this->get_security_recommendations(); ?></p>
                        </div>
                        
                        <div class="dashboard-card">
                            <h3>Recent Activity</h3>
                            <?php $this->display_recent_security_logs(5); ?>
                        </div>
                        
                        <div class="dashboard-card">
                            <h3>Protected Endpoints</h3>
                            <p>Total API Endpoints: <strong><?php echo $this->count_api_endpoints(); ?></strong></p>
                            <p>Endpoints with Cron: <strong><?php echo count($this->endpoints_with_cron); ?></strong></p>
                            <?php if ($this->security_settings['restrict_endpoints']): ?>
                                <p>Restricted Endpoints: <strong><?php echo count($this->security_settings['restricted_endpoints']); ?></strong></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="api-security-card">
                    <h2 class="api-security-title">Security Configuration</h2>
                    
                    <form id="api-security-form" method="post">
                        <div class="form-section">
                            <h3>Access Control</h3>
                            
                            <div class="form-row">
                                <label for="enable_ip_whitelist">
                                    <input type="checkbox" id="enable_ip_whitelist" name="enable_ip_whitelist" <?php checked($this->security_settings['enable_ip_whitelist']); ?>>
                                    Enable IP Whitelist
                                </label>
                                <div class="ip-whitelist-container">
                                    <p class="description">Enter IP addresses to whitelist, one per line.</p>
                                    <textarea name="ip_whitelist" rows="3" class="large-text"><?php echo implode("\n", (array)$this->security_settings['ip_whitelist']); ?></textarea>
                                    <p class="description">Your current IP: <?php echo $this->get_client_ip(); ?></p>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <label for="enable_api_key">
                                    <input type="checkbox" id="enable_api_key" name="enable_api_key" <?php checked($this->security_settings['enable_api_key']); ?>>
                                    Require API Key
                                </label>
                                <div class="api-key-container">
                                    <input type="text" id="api_key" name="api_key" class="regular-text" value="<?php echo esc_attr($this->security_settings['api_key']); ?>">
                                    <button id="generate-api-key" class="button">Generate New Key</button>
                                    <p class="description">This key must be included in the X-API-Key header for all API requests.</p>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <label for="enable_rate_limiting">
                                    <input type="checkbox" id="enable_rate_limiting" name="enable_rate_limiting" <?php checked($this->security_settings['enable_rate_limiting']); ?>>
                                    Enable Rate Limiting
                                </label>
                                <div class="rate-limit-container">
                                    <input type="number" id="rate_limit" name="rate_limit" min="1" value="<?php echo esc_attr($this->security_settings['rate_limit']); ?>"> requests per minute
                                    <p class="description">Limit the number of API requests per IP address.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h3>Security Measures</h3>
                            
                            <div class="form-row">
                                <label for="disable_non_ssl">
                                    <input type="checkbox" id="disable_non_ssl" name="disable_non_ssl" <?php checked($this->security_settings['disable_non_ssl']); ?>>
                                    Require SSL/HTTPS
                                </label>
                                <p class="description">Only allow API requests over secure HTTPS connections.</p>
                            </div>
                            
                            <div class="form-row">
                                <label for="require_nonce">
                                    <input type="checkbox" id="require_nonce" name="require_nonce" <?php checked($this->security_settings['require_nonce']); ?>>
                                    Require Nonce Validation
                                </label>
                                <p class="description">Validate nonce tokens to prevent CSRF attacks.</p>
                            </div>
                            
                            <div class="form-row">
                                <label for="restrict_endpoints">
                                    <input type="checkbox" id="restrict_endpoints" name="restrict_endpoints" <?php checked($this->security_settings['restrict_endpoints']); ?>>
                                    Restrict Specific Endpoints
                                </label>
                                <p class="description">Restrict access to specific API endpoints. Configure in the Cron Scheduler page.</p>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h3>Logging</h3>
                            
                            <div class="form-row">
                                <label for="enable_logging">
                                    <input type="checkbox" id="enable_logging" name="enable_logging" <?php checked($this->security_settings['enable_logging']); ?>>
                                    Enable Security Logging
                                </label>
                                <p class="description">Log all API requests and security events.</p>
                            </div>
                            
                            <div class="form-row">
                                <label for="log_retention">Log Retention Period (days):</label>
                                <input type="number" id="log_retention" name="log_retention" min="1" value="<?php echo esc_attr($this->security_settings['log_retention']); ?>">
                                <p class="description">How long to keep security logs before automatic cleanup.</p>
                            </div>
                        </div>
                        
                        <div class="form-controls">
                            <button type="submit" class="button button-primary">Save Security Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render cron page
     */
    public function render_cron_page() {
        // Get all API endpoints
        $api_endpoints = $this->get_all_api_endpoints();
        
        ?>
        <div class="wrap">
            <h1>API Cron Scheduler</h1>
            
            <div class="api-security-container">
                <div class="api-security-card">
                    <h2 class="api-security-title">Scheduled API Cron Jobs</h2>
                    
                    <?php if (empty($this->endpoints_with_cron)): ?>
                        <p>No API endpoints are currently scheduled for cron execution.</p>
                    <?php else: ?>
                        <table class="cron-jobs widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Endpoint</th>
                                    <th>Schedule</th>
                                    <th>Last Run</th>
                                    <th>Next Run</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->endpoints_with_cron as $endpoint_id => $cron_data): 
                                    $next_run = wp_next_scheduled('api_security_cron_event', array($endpoint_id));
                                ?>
                                    <tr>
                                        <td><?php echo esc_html($cron_data['name']); ?></td>
                                        <td><?php echo esc_html($this->cron_schedules[$cron_data['schedule']]['display']); ?></td>
                                        <td><?php echo !empty($cron_data['last_run']) ? date('Y-m-d H:i:s', $cron_data['last_run']) : 'Never'; ?></td>
                                        <td><?php echo $next_run ? date('Y-m-d H:i:s', $next_run) : 'Not scheduled'; ?></td>
                                        <td>
                                            <?php if (!empty($cron_data['last_run'])): ?>
                                                <?php if (isset($cron_data['last_status']) && $cron_data['last_status'] === 'success'): ?>
                                                    <span class="security-status status-secure">Success</span>
                                                <?php else: ?>
                                                    <span class="security-status status-danger">Failed</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="security-status status-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="button test-cron" data-endpoint="<?php echo esc_attr($endpoint_id); ?>">Run Now</button>
                                            <a href="<?php echo admin_url('admin.php?page=api-security-cron&action=delete&endpoint_id=' . $endpoint_id . '&_wpnonce=' . wp_create_nonce('delete_cron')); ?>" class="button" onclick="return confirm('Are you sure you want to delete this cron job?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <div class="api-security-card">
                    <h2 class="api-security-title">Add New Cron Job</h2>
                    
                    <?php if (empty($api_endpoints)): ?>
                        <p>No API endpoints available. Please create endpoints in the API Exposer plugin first.</p>
                    <?php else: ?>
                        <form id="api-cron-form" method="post">
                            <div class="form-row">
                                <label for="endpoint_id">Select API Endpoint:</label>
                                <select id="endpoint_id" name="endpoint_id" required>
                                    <option value="">-- Select Endpoint --</option>
                                    <?php foreach ($api_endpoints as $id => $endpoint): ?>
                                        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($endpoint['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-row">
                                <label for="schedule">Schedule:</label>
                                <select id="schedule" name="schedule" required>
                                    <?php foreach ($this->cron_schedules as $name => $schedule): ?>
                                        <option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($schedule['display']); ?> (<?php echo $this->format_interval($schedule['interval']); ?>)</option>
                                    <?php endforeach; ?>
                                    <option value="hourly">Hourly</option>
                                    <option value="twicedaily">Twice Daily</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                </select>
                            </div>
                            
                            <div class="form-row">
                                <label for="params">Parameters (JSON):</label>
                                <textarea id="params" name="params" rows="4" class="large-text">{}</textarea>
                                <p class="description">Optional parameters to pass to the API endpoint in JSON format.</p>
                            </div>
                            
                            <div class="form-controls">
                                <button type="submit" class="button button-primary">Add Cron Job</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
                
                <div class="api-security-card">
                    <h2 class="api-security-title">Custom Cron Schedules</h2>
                    
                    <p>These custom schedules will be available for all your cron jobs.</p>
                    
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Display Name</th>
                                <th>Interval</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->cron_schedules as $name => $schedule): ?>
                                <tr>
                                    <td><?php echo esc_html($name); ?></td>
                                    <td><?php echo esc_html($schedule['display']); ?></td>
                                    <td><?php echo $this->format_interval($schedule['interval']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <p class="description">System schedules: Hourly, Twice Daily, Daily, and Weekly are also available.</p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render logs page
     */
    public function render_logs_page() {
        // Get logs
        $logs = $this->get_security_logs();
        
        // Handle log deletion
        if (isset($_GET['action']) && $_GET['action'] === 'clear_logs' && check_admin_referer('clear_logs')) {
            $this->clear_security_logs();
            $logs = array();
            echo '<div class="notice notice-success is-dismissible"><p>Security logs cleared successfully.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>API Security Logs</h1>
            
            <div class="api-security-container">
                <div class="api-security-card">
                    <h2 class="api-security-title">Security Event Logs</h2>
                    
                    <div class="tablenav top">
                        <div class="alignleft actions">
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=api-security-logs&action=clear_logs'), 'clear_logs'); ?>" class="button" onclick="return confirm('Are you sure you want to clear all logs? This action cannot be undone.')">Clear All Logs</a>
                        </div>
                    </div>
                    
                    <?php if (empty($logs)): ?>
                        <p>No security logs found.</p>
                    <?php else: ?>
                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th width="15%">Time</th>
                                    <th width="10%">Level</th>
                                    <th width="15%">IP Address</th>
                                    <th>Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i:s', $log['time']); ?></td>
                                        <td>
                                            <?php
                                            $level_class = 'security-log-level-info';
                                            if ($log['level'] === 'warning') {
                                                $level_class = 'security-log-level-warning';
                                            } elseif ($log['level'] === 'error') {
                                                $level_class = 'security-log-level-error';
                                            }
                                            ?>
                                            <span class="<?php echo $level_class; ?>"><?php echo ucfirst($log['level']); ?></span>
                                        </td>
                                        <td><?php echo esc_html($log['ip']); ?></td>
                                        <td><?php echo esc_html($log['message']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <div class="api-security-card">
                    <h2 class="api-security-title">Log Settings</h2>
                    
                    <p>Current log retention period: <strong><?php echo $this->security_settings['log_retention']; ?> days</strong></p>
                    <p>Total logs stored: <strong><?php echo count($logs); ?></strong></p>
                    
                    <p>You can change log settings in the <a href="<?php echo admin_url('admin.php?page=api-security'); ?>">Security Settings</a> page.</p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for saving settings
     */
    public function ajax_save_settings() {
        // Verify nonce
        check_ajax_referer('api_security_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to change these settings.');
        }
        
        // Parse form data
        parse_str($_POST['form_data'], $form_data);
        
        // Update settings
        $settings = array(
            'enable_ip_whitelist' => isset($form_data['enable_ip_whitelist']),
            'ip_whitelist' => isset($form_data['ip_whitelist']) ? array_filter(array_map('trim', explode("\n", $form_data['ip_whitelist']))) : array(),
            'enable_api_key' => isset($form_data['enable_api_key']),
            'api_key' => isset($form_data['api_key']) ? sanitize_text_field($form_data['api_key']) : $this->security_settings['api_key'],
            'enable_rate_limiting' => isset($form_data['enable_rate_limiting']),
            'rate_limit' => isset($form_data['rate_limit']) ? intval($form_data['rate_limit']) : 60,
            'enable_logging' => isset($form_data['enable_logging']),
            'log_retention' => isset($form_data['log_retention']) ? intval($form_data['log_retention']) : 7,
            'restrict_endpoints' => isset($form_data['restrict_endpoints']),
            'restricted_endpoints' => $this->security_settings['restricted_endpoints'],
            'disable_non_ssl' => isset($form_data['disable_non_ssl']),
            'require_nonce' => isset($form_data['require_nonce'])
        );
        
        // Make sure IP whitelist includes current IP if enabled
        if ($settings['enable_ip_whitelist'] && empty($settings['ip_whitelist'])) {
            $settings['ip_whitelist'][] = $this->get_client_ip();
        }
        
        // Save settings
        update_option('api_security_settings', $settings);
        
        // Update internal settings
        $this->security_settings = $settings;
        
        // Log event
        $this->add_security_log('Security settings updated by ' . wp_get_current_user()->user_login, 'info');
        
        wp_send_json_success('Settings saved successfully.');
    }
    
    /**
     * AJAX handler for testing cron
     */
    public function ajax_test_cron() {
        // Verify nonce
        check_ajax_referer('api_security_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to run cron jobs.');
        }
        
        // Get endpoint ID
        $endpoint_id = isset($_POST['endpoint_id']) ? sanitize_text_field($_POST['endpoint_id']) : '';
        
        if (empty($endpoint_id) || !isset($this->endpoints_with_cron[$endpoint_id])) {
            wp_send_json_error('Invalid endpoint ID.');
        }
        
        // Execute cron job
        $result = $this->execute_cron_event($endpoint_id);
        
        wp_send_json_success(array(
            'response' => $result ? 'Success' : 'Failed',
            'details' => isset($this->endpoints_with_cron[$endpoint_id]['last_response']) ? $this->endpoints_with_cron[$endpoint_id]['last_response'] : ''
        ));
    }
    
    /**
     * AJAX handler for adding cron
     */
    public function ajax_add_cron() {
        // Verify nonce
        check_ajax_referer('api_security_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to add cron jobs.');
        }
        
        // Parse form data
        parse_str($_POST['form_data'], $form_data);
        
        // Validate data
        if (empty($form_data['endpoint_id'])) {
            wp_send_json_error('Please select an API endpoint.');
        }
        
        if (empty($form_data['schedule'])) {
            wp_send_json_error('Please select a schedule.');
        }
        
        // Get endpoint
        $endpoints = $this->get_all_api_endpoints();
        $endpoint_id = sanitize_text_field($form_data['endpoint_id']);
        
        if (!isset($endpoints[$endpoint_id])) {
            wp_send_json_error('Invalid endpoint selected.');
        }
        
        // Parse parameters
        $params = array();
        if (!empty($form_data['params'])) {
            $params_json = trim($form_data['params']);
            if (!empty($params_json)) {
                $params = json_decode($params_json, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    wp_send_json_error('Invalid JSON parameters. Please check your JSON syntax.');
                }
            }
        }
        
        // Save cron job
        $this->endpoints_with_cron[$endpoint_id] = array(
            'name' => $endpoints[$endpoint_id]['name'],
            'url' => $endpoints[$endpoint_id]['url'],
            'schedule' => sanitize_text_field($form_data['schedule']),
            'params' => $params,
            'created_at' => time()
        );
        
        update_option('api_security_endpoints_with_cron', $this->endpoints_with_cron);
        
        // Schedule the cron event
        $hook_name = 'api_security_cron_event';
        $args = array($endpoint_id);
        
        // Clear any existing scheduled events for this endpoint
        wp_clear_scheduled_hook($hook_name, $args);
        
        // Schedule new event
        wp_schedule_event(time(), sanitize_text_field($form_data['schedule']), $hook_name, $args);
        
        // Log event
        $this->add_security_log('New cron job created for endpoint: ' . $endpoints[$endpoint_id]['name'], 'info');
        
        wp_send_json_success('Cron job added successfully.');
    }
    
    /**
     * Execute cron event
     */
    public function execute_cron_event($endpoint_id) {
        if (!isset($this->endpoints_with_cron[$endpoint_id])) {
            return false;
        }
        
        $endpoint = $this->endpoints_with_cron[$endpoint_id];
        
        // Build the request
        $url = $endpoint['url'];
        if (!empty($endpoint['params'])) {
            $url = add_query_arg($endpoint['params'], $url);
        }
        
        // Set up security headers if needed
        $headers = array();
        
        if ($this->security_settings['enable_api_key']) {
            $headers['X-API-Key'] = $this->security_settings['api_key'];
        }
        
        // Make the request
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => $headers
        ));
        
        // Check for error
        if (is_wp_error($response)) {
            $this->endpoints_with_cron[$endpoint_id]['last_run'] = time();
            $this->endpoints_with_cron[$endpoint_id]['last_status'] = 'error';
            $this->endpoints_with_cron[$endpoint_id]['last_response'] = $response->get_error_message();
            
            // Log error
            $this->add_security_log('Cron job failed for endpoint ' . $endpoint['name'] . ': ' . $response->get_error_message(), 'error');
            
            update_option('api_security_endpoints_with_cron', $this->endpoints_with_cron);
            return false;
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        
        // Get response body
        $response_body = wp_remote_retrieve_body($response);
        
        // Update endpoint data
        $this->endpoints_with_cron[$endpoint_id]['last_run'] = time();
        $this->endpoints_with_cron[$endpoint_id]['last_status'] = ($response_code >= 200 && $response_code < 300) ? 'success' : 'error';
        $this->endpoints_with_cron[$endpoint_id]['last_response'] = $response_body;
        
        update_option('api_security_endpoints_with_cron', $this->endpoints_with_cron);
        
        // Log event
        if ($response_code >= 200 && $response_code < 300) {
            $this->add_security_log('Cron job executed successfully for endpoint: ' . $endpoint['name'], 'info');
        } else {
            $this->add_security_log('Cron job returned error code ' . $response_code . ' for endpoint: ' . $endpoint['name'], 'error');
        }
        
        return ($response_code >= 200 && $response_code < 300);
    }
    
    /**
     * Validate IP whitelist
     */
    public function validate_ip_whitelist($errors) {
        if (!is_null($errors)) {
            return $errors;
        }
        
        // Skip validation for admin
        if (current_user_can('manage_options')) {
            return null;
        }
        
        $client_ip = $this->get_client_ip();
        
        // Check if IP is in whitelist
        if (!in_array($client_ip, $this->security_settings['ip_whitelist'])) {
            $this->add_security_log('API access denied - IP not in whitelist: ' . $client_ip, 'warning');
            return new WP_Error('rest_forbidden', 'Access denied. Your IP address is not authorized.', array('status' => 403));
        }
        
        return null;
    }
    
    /**
     * Validate API key
     */
    public function validate_api_key($errors) {
        if (!is_null($errors)) {
            return $errors;
        }
        
        // Skip validation for admin
        if (current_user_can('manage_options')) {
            return null;
        }
        
        // Get headers
        $headers = getallheaders();
        
        // Check if API key header exists
        if (!isset($headers['X-API-Key']) || $headers['X-API-Key'] !== $this->security_settings['api_key']) {
            $this->add_security_log('API access denied - Invalid API key', 'warning');
            return new WP_Error('rest_forbidden', 'Access denied. Invalid API key.', array('status' => 403));
        }
        
        return null;
    }
    
    /**
     * Check rate limit
     */
    public function check_rate_limit($response, $handler, $request) {
        // Skip rate limiting for admin
        if (current_user_can('manage_options')) {
            return $response;
        }
        
        $client_ip = $this->get_client_ip();
        $rate_key = 'api_rate_limit_' . md5($client_ip);
        
        // Get rate data
        $rate_data = get_transient($rate_key);
        
        if (false === $rate_data) {
            // First request, set counter to 1
            $rate_data = array(
                'count' => 1,
                'timestamp' => time()
            );
            
            set_transient($rate_key, $rate_data, 60); // 1 minute
        } else {
            // Increment counter
            $rate_data['count']++;
            
            // Check if limit exceeded
            if ($rate_data['count'] > $this->security_settings['rate_limit']) {
                $this->add_security_log('Rate limit exceeded for IP: ' . $client_ip, 'warning');
                return new WP_Error('rest_rate_limited', 'Too many requests. Please try again later.', array('status' => 429));
            }
            
            // Update transient
            set_transient($rate_key, $rate_data, 60 - (time() - $rate_data['timestamp']));
        }
        
        return $response;
    }
    
    /**
     * Require SSL
     */
    public function require_ssl($response, $handler, $request) {
        if (!is_ssl()) {
            $this->add_security_log('Non-SSL access attempt blocked', 'warning');
            return new WP_Error('rest_forbidden_protocol', 'API requests must be made over HTTPS.', array('status' => 403));
        }
        
        return $response;
    }
    
    /**
     * Validate nonce
     */
    public function validate_nonce($errors) {
        if (!is_null($errors)) {
            return $errors;
        }
        
        // Skip for admin
        if (current_user_can('manage_options')) {
            return null;
        }
        
        // Skip for non-admin routes
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
        
        // Safe routes that don't need nonce
        $safe_routes = array(
            '/api-exposer/v1/apis',
            '/api-security/v1/cron'
        );
        
        foreach ($safe_routes as $safe_route) {
            if (strpos($route, $safe_route) === 0) {
                return null;
            }
        }
        
        // Check nonce
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? $_SERVER['HTTP_X_WP_NONCE'] : '';
        
        if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            $this->add_security_log('Invalid nonce in API request', 'warning');
            return new WP_Error('rest_cookie_invalid_nonce', 'Invalid nonce.', array('status' => 403));
        }
        
        return null;
    }
    
    /**
     * Restrict endpoints
     */
    public function restrict_endpoints($response, $handler, $request) {
        // Skip for admin
        if (current_user_can('manage_options')) {
            return $response;
        }
        
        $route = $request->get_route();
        
        // Check if endpoint is restricted
        if (in_array($route, (array)$this->security_settings['restricted_endpoints'])) {
            $this->add_security_log('Access attempt to restricted endpoint: ' . $route, 'warning');
            return new WP_Error('rest_forbidden_endpoint', 'This endpoint is restricted.', array('status' => 403));
        }
        
        return $response;
    }
    
    /**
     * Log API requests
     */
    public function log_api_requests() {
        add_action('rest_pre_serve_request', function ($served, $result, $request, $server) {
            $route = $request->get_route();
            $method = $request->get_method();
            $params = $request->get_params();
            $ip = $this->get_client_ip();
            
            // Don't log cron requests
            if (strpos($route, '/api-security/v1/cron/') === 0) {
                return $served;
            }
            
            // Create log message
            $log_message = "$method request to $route";
            
            // Log params for non-GET requests
            if ($method !== 'GET' && !empty($params)) {
                $log_message .= ' with params: ' . json_encode($params);
            }
            
            // Add log entry
            $this->add_security_log($log_message, 'info');
            
            return $served;
        }, 10, 4);
    }
    
    /**
     * Add security log
     */
    public function add_security_log($message, $level = 'info') {
        if (!$this->security_settings['enable_logging']) {
            return;
        }
        
        $logs = $this->get_security_logs();
        
        // Add new log
        $logs[] = array(
            'time' => time(),
            'message' => $message,
            'level' => $level,
            'ip' => $this->get_client_ip()
        );
        
        // Sort logs by time, newest first
        usort($logs, function($a, $b) {
            return $b['time'] - $a['time'];
        });
        
        // Limit log size
        $max_logs = 1000;
        if (count($logs) > $max_logs) {
            $logs = array_slice($logs, 0, $max_logs);
        }
        
        update_option('api_security_logs', $logs);
    }
    
    /**
     * Get security logs
     */
    private function get_security_logs() {
        $logs = get_option('api_security_logs', array());
        
        if (!is_array($logs)) {
            return array();
        }
        
        return $logs;
    }
    
    /**
     * Display recent security logs
     */
    private function display_recent_security_logs($count = 5) {
        $logs = $this->get_security_logs();
        $logs = array_slice($logs, 0, $count);
        
        if (empty($logs)) {
            echo '<p>No recent security events.</p>';
            return;
        }
        
        foreach ($logs as $log) {
            $level_class = 'security-log-level-info';
            if ($log['level'] === 'warning') {
                $level_class = 'security-log-level-warning';
            } elseif ($log['level'] === 'error') {
                $level_class = 'security-log-level-error';
            }
            
            echo '<div class="security-log-entry">';
            echo '<div class="security-log-time">' . date('Y-m-d H:i:s', $log['time']) . '</div>';
            echo '<div class="security-log-message ' . $level_class . '">' . esc_html($log['message']) . '</div>';
            echo '</div>';
        }
    }
    
    /**
     * Clear security logs
     */
    private function clear_security_logs() {
        update_option('api_security_logs', array());
    }
    
    /**
     * Cleanup security logs based on retention period
     */
    public function cleanup_security_logs() {
        if (!$this->security_settings['enable_logging']) {
            return;
        }
        
        $logs = $this->get_security_logs();
        $retention_period = $this->security_settings['log_retention'] * DAY_IN_SECONDS;
        $cutoff_time = time() - $retention_period;
        
        // Filter logs older than retention period
        $filtered_logs = array_filter($logs, function($log) use ($cutoff_time) {
            return $log['time'] >= $cutoff_time;
        });
        
        if (count($filtered_logs) !== count($logs)) {
            update_option('api_security_logs', $filtered_logs);
            
            // Log cleanup
            $this->add_security_log('Cleaned up ' . (count($logs) - count($filtered_logs)) . ' old log entries', 'info');
        }
    }
    
    /**
     * Calculate security score
     */
    private function calculate_security_score() {
        $score = 0;
        $total_points = 0;
        
        // IP Whitelist
        $total_points += 15;
        if ($this->security_settings['enable_ip_whitelist'] && !empty($this->security_settings['ip_whitelist'])) {
            $score += 15;
        }
        
        // API Key
        $total_points += 20;
        if ($this->security_settings['enable_api_key']) {
            $score += 20;
        }
        
        // Rate Limiting
        $total_points += 15;
        if ($this->security_settings['enable_rate_limiting']) {
            $score += 15;
        }
        
        // SSL
        $total_points += 20;
        if ($this->security_settings['disable_non_ssl']) {
            $score += 20;
        }
        
        // Nonce
        $total_points += 15;
        if ($this->security_settings['require_nonce']) {
            $score += 15;
        }
        
        // Endpoint Restrictions
        $total_points += 15;
        if ($this->security_settings['restrict_endpoints'] && !empty($this->security_settings['restricted_endpoints'])) {
            $score += 15;
        }
        
        // Calculate percentage
        return round(($score / $total_points) * 100);
    }
    
    /**
     * Get security recommendations
     */
    private function get_security_recommendations() {
        $recommendations = array();
        
        if (!$this->security_settings['enable_ip_whitelist']) {
            $recommendations[] = 'Enable IP whitelisting to restrict API access to trusted IPs.';
        }
        
        if (!$this->security_settings['enable_api_key']) {
            $recommendations[] = 'Enable API key authentication for better security.';
        }
        
        if (!$this->security_settings['enable_rate_limiting']) {
            $recommendations[] = 'Enable rate limiting to prevent abuse and DDoS attacks.';
        }
        
        if (!$this->security_settings['disable_non_ssl']) {
            $recommendations[] = 'Enforce SSL/HTTPS for all API requests to encrypt data in transit.';
        }
        
        if (empty($recommendations)) {
            return 'Your API security configuration is strong. No additional recommendations at this time.';
        }
        
        return 'Recommendations to improve security:<br>' . implode('<br>', $recommendations);
    }
    
    /**
     * Get all API endpoints
     */
    private function get_all_api_endpoints() {
        $endpoints = array();
        
        // Get API Exposer endpoints if available
        if ($this->is_api_exposer_active()) {
            $exposed_endpoints = get_option('api_exposer_exposed_endpoints', array());
            
            foreach ($exposed_endpoints as $id => $endpoint) {
                $endpoints[$id] = array(
                    'name' => $endpoint['route'] . ' (' . $endpoint['http_method'] . ')',
                    'url' => rest_url('api-exposer/v1/' . $endpoint['route']),
                    'method' => $endpoint['http_method'],
                    'source' => 'api_exposer'
                );
            }
        }
        
        // Get Webhook Manager routes if available
        if ($this->is_webhook_manager_active()) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'webhook_routes';
            
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                $webhook_routes = $wpdb->get_results("SELECT * FROM $table_name WHERE is_active = 1", ARRAY_A);
                
                foreach ($webhook_routes as $route) {
                    $id = 'webhook_' . $route['id'];
                    $endpoints[$id] = array(
                        'name' => $route['route_slug'] . ' (' . $route['http_method'] . ')',
                        'url' => rest_url('convoengine/v1/' . $route['route_slug']),
                        'method' => $route['http_method'],
                        'source' => 'webhook_manager'
                    );
                }
            }
        }
        
        return $endpoints;
    }
    
    /**
     * Count API endpoints
     */
    private function count_api_endpoints() {
        return count($this->get_all_api_endpoints());
    }
    
    /**
     * Handle new endpoint
     */
    public function handle_new_endpoint($endpoint_id, $endpoint_data) {
        // Add notification about new endpoint
        $this->add_security_log('New API endpoint created: ' . $endpoint_data['route'], 'info');
    }
    
    /**
     * Add cron tab to API Exposer
     */
    public function add_cron_tab($tabs) {
        $tabs['cron'] = array(
            'title' => 'Cron Scheduler',
            'url' => admin_url('admin.php?page=api-security-cron')
        );
        
        return $tabs;
    }
    
    /**
     * Format time interval
     */
    private function format_interval($seconds) {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        } elseif ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '');
        } else {
            $days = floor($seconds / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '');
        }
    }
    
    /**
     * Get client IP
     */
    private function get_client_ip() {
        $ip = '';
        
        // Check for CloudFlare
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            // Check if multiple IPs, take first one
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return $ip;
    }
    
    /**
     * Verify cron permission
     */
    public function verify_cron_permission($request) {
        // Allow internal cron requests
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }
        
        // Check for API key if enabled
        if ($this->security_settings['enable_api_key']) {
            $headers = $request->get_headers();
            if (isset($headers['x_api_key']) && $headers['x_api_key'][0] === $this->security_settings['api_key']) {
                return true;
            }
        }
        
        // Check for admin
        if (current_user_can('manage_options')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Handle cron request
     */
    public function handle_cron_request($request) {
        $endpoint_id = $request->get_param('endpoint_id');
        
        if (empty($endpoint_id) || !isset($this->endpoints_with_cron[$endpoint_id])) {
            return new WP_Error('invalid_endpoint', 'Invalid cron endpoint ID.', array('status' => 400));
        }
        
        // Execute cron job
        $result = $this->execute_cron_event($endpoint_id);
        
        if (!$result) {
            return new WP_Error('cron_failed', 'Cron job execution failed.', array('status' => 500));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Cron job executed successfully.',
            'endpoint' => $endpoint_id,
            'time' => time()
        ));
    }
}

/**
 * Plugin activation hook
 */
function api_security_activator() {
    // Create necessary directories
    $assets_dir = plugin_dir_path(__FILE__) . 'assets';
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
        
        // Create js directory
        $js_dir = $assets_dir . '/js';
        if (!file_exists($js_dir)) {
            wp_mkdir_p($js_dir);
        }
        
        // Create css directory
        $css_dir = $assets_dir . '/css';
        if (!file_exists($css_dir)) {
            wp_mkdir_p($css_dir);
        }
    }
    
    // Setup default settings if not exists
    if (!get_option('api_security_settings')) {
        $default_settings = array(
            'enable_ip_whitelist' => false,
            'ip_whitelist' => array(),
            'enable_api_key' => true,
            'api_key' => wp_generate_password(32, false),
            'enable_rate_limiting' => true,
            'rate_limit' => 60,
            'enable_logging' => true,
            'log_retention' => 7,
            'restrict_endpoints' => false,
            'restricted_endpoints' => array(),
            'disable_non_ssl' => false,
            'require_nonce' => true
        );
        
        update_option('api_security_settings', $default_settings);
    }
    
    // Set up default cron schedules
    if (!get_option('api_security_cron_schedules')) {
        $default_schedules = array(
            'everyminute' => array(
                'interval' => 60,
                'display' => 'Every Minute'
            ),
            'everyfiveminutes' => array(
                'interval' => 300,
                'display' => 'Every 5 Minutes'
            ),
            'everyfifteenminutes' => array(
                'interval' => 900,
                'display' => 'Every 15 Minutes'
            ),
            'everyhalfhour' => array(
                'interval' => 1800,
                'display' => 'Every 30 Minutes'
            )
        );
        
        update_option('api_security_cron_schedules', $default_schedules);
    }
    
    // Initialize logs if not exists
    if (!get_option('api_security_logs')) {
        update_option('api_security_logs', array());
    }
    
    // Schedule log cleanup if not already scheduled
    if (!wp_next_scheduled('api_security_log_cleanup')) {
        wp_schedule_event(time(), 'daily', 'api_security_log_cleanup');
    }
    
    // Add activation notice
    set_transient('api_security_activation_notice', true, 5);
}

/**
 * Plugin deactivation hook
 */
function api_security_deactivator() {
    // Clear scheduled events
    wp_clear_scheduled_hook('api_security_log_cleanup');
    
    // Clear all cron events
    $endpoints_with_cron = get_option('api_security_endpoints_with_cron', array());
    foreach ($endpoints_with_cron as $endpoint_id => $cron_data) {
        wp_clear_scheduled_hook('api_security_cron_event', array($endpoint_id));
    }
}

/**
 * Handle activation notice
 */
function api_security_admin_notice() {
    if (get_transient('api_security_activation_notice')) {
        // Check if needed plugins are active
        $api_exposer_active = false;
        $webhook_manager_active = false;
        
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        $api_exposer_plugins = array(
            'api-exposer/api-exposer.php',
            'api-exposer-webhook-companion/api-exposer.php'
        );
        
        foreach ($api_exposer_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                $api_exposer_active = true;
                break;
            }
        }
        
        $webhook_plugins = array(
            'webhook-manager/webhook-manager.php',
            'enhanced-webhook-manager/webhook-manager.php'
        );
        
        foreach ($webhook_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                $webhook_manager_active = true;
                break;
            }
        }
        
        // Show appropriate message
        if (!$api_exposer_active && !$webhook_manager_active) {
            $message = '<p><strong>API Security & Cron Enhancer</strong> can provide additional security and cron functionality for API Exposer and Webhook Manager plugins. Please install and activate these plugins to get the most out of API Security.</p>';
        } elseif (!$api_exposer_active) {
            $message = '<p><strong>API Security & Cron Enhancer</strong> detected the Webhook Manager plugin, but not API Exposer. Install API Exposer for enhanced API management.</p>';
        } elseif (!$webhook_manager_active) {
            $message = '<p><strong>API Security & Cron Enhancer</strong> detected the API Exposer plugin, but not Webhook Manager. Install Webhook Manager for enhanced webhook functionality.</p>';
        } else {
            $message = '<p><strong>API Security & Cron Enhancer</strong> has been activated! Both API Exposer and Webhook Manager were detected. Go to <a href="' . admin_url('admin.php?page=api-security') . '">API Security</a> to configure settings.</p>';
        }
        
        echo '<div class="notice notice-success is-dismissible">' . $message . '</div>';
        delete_transient('api_security_activation_notice');
    }
}
add_action('admin_notices', 'api_security_admin_notice');

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'api_security_activator');
register_deactivation_hook(__FILE__, 'api_security_deactivator');

// Initialize the plugin
function api_security_init() {
    API_Security_Cron_Enhancer::get_instance();
}
add_action('plugins_loaded', 'api_security_init');
