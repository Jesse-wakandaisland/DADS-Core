<?php
/*
Plugin Name: API Exposer - Webhook Companion
Description: Exposes all APIs from other plugins and integrates with Webhook Manager for headless WordPress (beta)
Version: 1.1
Author: ConvoBuilder.com
Requires at least: 5.0
Requires PHP: 7.2
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('API_EXPOSER_VERSION', '1.1.0');
define('API_EXPOSER_PATH', plugin_dir_path(__FILE__));
define('API_EXPOSER_URL', plugin_dir_url(__FILE__));

class API_Exposer {
    // Static instance for singleton pattern
    private static $instance = null;
    
    // Store discovered plugin APIs
    private $discovered_apis = array();
    
    // Store exposed endpoints
    private $exposed_endpoints = array();
    
    // Path to store cache
    private $cache_path;
    
    // Cache duration (in seconds)
    private $cache_duration = 86400; // 24 hours
    
    /**
     * Constructor
     */
    private function __construct() {
        // Set cache path
        $this->cache_path = API_EXPOSER_PATH . 'cache/';
        
        // Create cache directory if it doesn't exist
        if (!file_exists($this->cache_path)) {
            wp_mkdir_p($this->cache_path);
        }
        
        // Initialize the plugin
        add_action('init', array($this, 'init'), 5);
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
        // Load discovered APIs from database
        $this->load_discovered_apis();
        
        // Load exposed endpoints
        $this->load_exposed_endpoints();
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Add AJAX handlers
        add_action('wp_ajax_api_exposer_scan_plugin', array($this, 'ajax_scan_plugin'));
        add_action('wp_ajax_api_exposer_create_route', array($this, 'ajax_create_route'));
        add_action('wp_ajax_api_exposer_get_plugin_data', array($this, 'ajax_get_plugin_data'));
        
        // Check if webhook manager exists and add integration options
        if ($this->webhook_manager_exists()) {
            add_filter('webhook_manager_callback_functions', array($this, 'add_callback_functions'));
        }
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Include dynamic handlers
        $this->include_handlers();
    }
    
    /**
     * Include dynamic handlers
     */
    private function include_handlers() {
        $handlers_dir = API_EXPOSER_PATH . 'handlers/';
        
        if (is_dir($handlers_dir)) {
            $files = glob($handlers_dir . '*.php');
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    include_once $file;
                }
            }
        }
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_menu_page(
            'API Exposer',
            'API Exposer',
            'manage_options',
            'api-exposer',
            array($this, 'render_main_page'),
            'dashicons-rest-api',
            31
        );
        
        add_submenu_page(
            'api-exposer',
            'Discover APIs',
            'Discover APIs',
            'manage_options',
            'api-exposer',
            array($this, 'render_main_page')
        );
        
        add_submenu_page(
            'api-exposer',
            'Exposed Endpoints',
            'Exposed Endpoints',
            'manage_options',
            'api-exposer-endpoints',
            array($this, 'render_endpoints_page')
        );
        
        add_submenu_page(
            'api-exposer',
            'Settings',
            'Settings',
            'manage_options',
            'api-exposer-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'api-exposer') !== false) {
            // Create assets if they don't exist
            $this->create_assets();
            
            // Enqueue the main script
            wp_enqueue_script(
                'api-exposer-admin',
                API_EXPOSER_URL . 'assets/js/admin.js',
                array('jquery'),
                API_EXPOSER_VERSION,
                true
            );
            
            // Localize the script with data
            wp_localize_script(
                'api-exposer-admin',
                'apiExposerData',
                array(
                    'ajax_url' => admin_url('ajax.php'),
                    'nonce' => wp_create_nonce('api_exposer_nonce'),
                    'rest_url' => rest_url(),
                    'webhook_manager_exists' => $this->webhook_manager_exists()
                )
            );
            
            // Enqueue the styles
            wp_enqueue_style(
                'api-exposer-admin',
                API_EXPOSER_URL . 'assets/css/admin.css',
                array(),
                API_EXPOSER_VERSION
            );
        }
    }
    
    /**
     * Check if Webhook Manager exists
     */
    public function webhook_manager_exists() {
        // Check if the webhook manager plugin is active
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        // Look for any plugin that might be webhook manager
        $webhook_plugins = array(
            'webhook-manager/webhook-manager.php',
            'enhanced-webhook-manager/webhook-manager.php'
        );
        
        foreach ($webhook_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }
        
        // Also check for specific functions
        return function_exists('webhook_manager_activate');
    }
    
    /**
     * Add callback functions to webhook manager
     */
    public function add_callback_functions($callbacks) {
        // Add our dynamic handler callbacks
        $callbacks[] = 'api_exposer_handle_request';
        
        // Add all exposed endpoint callbacks
        foreach ($this->exposed_endpoints as $endpoint) {
            if (isset($endpoint['callback_function'])) {
                $callbacks[] = $endpoint['callback_function'];
            }
        }
        
        return $callbacks;
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Register route for getting all exposed APIs
        register_rest_route('api-exposer/v1', '/apis', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_all_apis'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
        
        // Register route for getting plugin data
        register_rest_route('api-exposer/v1', '/plugin/(?P<plugin_slug>[a-zA-Z0-9-_]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_plugin_data'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
        
        // Register dynamic endpoints based on exposed endpoints
        foreach ($this->exposed_endpoints as $endpoint) {
            if (isset($endpoint['route']) && isset($endpoint['callback_function'])) {
                register_rest_route('api-exposer/v1', '/' . $endpoint['route'], array(
                    'methods' => $endpoint['http_method'] ?? 'GET',
                    'callback' => $endpoint['callback_function'],
                    'permission_callback' => '__return_true'
                ));
            }
        }
    }
    
    /**
     * Load discovered APIs from database
     */
    private function load_discovered_apis() {
        $apis = get_option('api_exposer_discovered_apis', array());
        $this->discovered_apis = is_array($apis) ? $apis : array();
    }
    
    /**
     * Save discovered APIs to database
     */
    private function save_discovered_apis() {
        update_option('api_exposer_discovered_apis', $this->discovered_apis);
    }
    
    /**
     * Load exposed endpoints
     */
    private function load_exposed_endpoints() {
        $endpoints = get_option('api_exposer_exposed_endpoints', array());
        $this->exposed_endpoints = is_array($endpoints) ? $endpoints : array();
    }
    
    /**
     * Save exposed endpoints
     */
    private function save_exposed_endpoints() {
        update_option('api_exposer_exposed_endpoints', $this->exposed_endpoints);
    }
    
    /**
     * AJAX handler for scanning a plugin
     */
    public function ajax_scan_plugin() {
        // Verify nonce
        check_ajax_referer('api_exposer_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Get plugin slug
        $plugin_slug = isset($_POST['plugin_slug']) ? sanitize_text_field($_POST['plugin_slug']) : '';
        
        if (empty($plugin_slug)) {
            wp_send_json_error('No plugin specified');
        }
        
        // Scan the plugin
        $apis = $this->scan_plugin_for_apis($plugin_slug);
        
        // Save to discovered APIs
        $this->discovered_apis[$plugin_slug] = array(
            'name' => $this->get_plugin_name($plugin_slug),
            'slug' => $plugin_slug,
            'apis' => $apis,
            'last_scanned' => current_time('mysql')
        );
        
        // Save to database
        $this->save_discovered_apis();
        
        // Return success
        wp_send_json_success(array(
            'plugin' => $plugin_slug,
            'apis' => $apis
        ));
    }
    
    /**
     * AJAX handler for creating a route
     */
    public function ajax_create_route() {
        // Verify nonce
        check_ajax_referer('api_exposer_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Get data
        $plugin_slug = isset($_POST['plugin_slug']) ? sanitize_text_field($_POST['plugin_slug']) : '';
        $api_id = isset($_POST['api_id']) ? sanitize_text_field($_POST['api_id']) : '';
        $route = isset($_POST['route']) ? sanitize_text_field($_POST['route']) : '';
        $method = isset($_POST['method']) ? sanitize_text_field($_POST['method']) : 'GET';
        
        if (empty($plugin_slug) || empty($api_id) || empty($route)) {
            wp_send_json_error('Missing required fields');
        }
        
        // Check if API exists
        if (!isset($this->discovered_apis[$plugin_slug]['apis'][$api_id])) {
            wp_send_json_error('API not found');
        }
        
        // Create unique callback function name
        $callback_function = 'api_exposer_' . $plugin_slug . '_' . $api_id . '_handler';
        
        // Create endpoint
        $endpoint = array(
            'plugin_slug' => $plugin_slug,
            'api_id' => $api_id,
            'route' => $route,
            'http_method' => $method,
            'callback_function' => $callback_function,
            'created_at' => current_time('mysql')
        );
        
        // Add to exposed endpoints
        $endpoint_id = uniqid();
        $this->exposed_endpoints[$endpoint_id] = $endpoint;
        
        // Save to database
        $this->save_exposed_endpoints();
        
        // Generate handler file
        $api_type = $this->discovered_apis[$plugin_slug]['apis'][$api_id]['type'];
        $this->create_dynamic_handler($plugin_slug, $api_id, $api_type);
        
        // If webhook manager exists, create webhook route
        if ($this->webhook_manager_exists()) {
            $this->create_webhook_route($endpoint);
        }
        
        // Return success
        wp_send_json_success(array(
            'endpoint_id' => $endpoint_id,
            'endpoint' => $endpoint
        ));
    }
    
    /**
     * AJAX handler for getting plugin data
     */
    public function ajax_get_plugin_data() {
        // Verify nonce
        check_ajax_referer('api_exposer_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Get plugin slug
        $plugin_slug = isset($_POST['plugin_slug']) ? sanitize_text_field($_POST['plugin_slug']) : '';
        
        if (empty($plugin_slug)) {
            wp_send_json_error('No plugin specified');
        }
        
        // Get plugin data
        $data = $this->get_plugin_data_by_slug($plugin_slug);
        
        if (!$data) {
            wp_send_json_error('Plugin not found');
        }
        
        // Check if we have discovered APIs for this plugin
        $has_discovered_apis = isset($this->discovered_apis[$plugin_slug]);
        
        // Return success
        wp_send_json_success(array(
            'plugin' => $data,
            'has_discovered_apis' => $has_discovered_apis,
            'discovered_apis' => $has_discovered_apis ? $this->discovered_apis[$plugin_slug] : null
        ));
    }
    
    /**
     * REST API callback for getting all APIs
     */
    public function get_all_apis($request) {
        return rest_ensure_response($this->discovered_apis);
    }
    
    /**
     * REST API callback for getting plugin data
     */
    public function get_plugin_data($request) {
        $plugin_slug = $request->get_param('plugin_slug');
        
        if (empty($plugin_slug)) {
            return new WP_Error('missing_plugin', 'No plugin specified', array('status' => 400));
        }
        
        if (!isset($this->discovered_apis[$plugin_slug])) {
            return new WP_Error('plugin_not_found', 'Plugin APIs not found', array('status' => 404));
        }
        
        return rest_ensure_response($this->discovered_apis[$plugin_slug]);
    }
    
    /**
     * Get all installed plugins
     */
    private function get_all_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());
        
        $plugins = array();
        
        foreach ($all_plugins as $plugin_path => $plugin_data) {
            // Get the plugin slug from the path
            $path_parts = explode('/', $plugin_path);
            $slug = $path_parts[0];
            
            $plugins[$slug] = array(
                'name' => $plugin_data['Name'],
                'description' => $plugin_data['Description'],
                'version' => $plugin_data['Version'],
                'author' => $plugin_data['Author'],
                'path' => $plugin_path,
                'is_active' => in_array($plugin_path, $active_plugins)
            );
        }
        
        return $plugins;
    }
    
    /**
     * Get plugin name by slug
     */
    private function get_plugin_name($plugin_slug) {
        $plugins = $this->get_all_plugins();
        
        if (isset($plugins[$plugin_slug])) {
            return $plugins[$plugin_slug]['name'];
        }
        
        return $plugin_slug;
    }
    
    /**
     * Get plugin data by slug
     */
    private function get_plugin_data_by_slug($plugin_slug) {
        $plugins = $this->get_all_plugins();
        
        if (isset($plugins[$plugin_slug])) {
            return $plugins[$plugin_slug];
        }
        
        return null;
    }
    
    /**
     * Scan plugin for APIs
     */
    private function scan_plugin_for_apis($plugin_slug) {
        $apis = array();
        $plugins = $this->get_all_plugins();
        
        if (!isset($plugins[$plugin_slug])) {
            return $apis;
        }
        
        $plugin_data = $plugins[$plugin_slug];
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_data['path']);
        
        // Check cache first
        $cache_file = $this->cache_path . $plugin_slug . '.json';
        if (file_exists($cache_file) && (time() - filemtime($cache_file) < $this->cache_duration)) {
            $cached_data = json_decode(file_get_contents($cache_file), true);
            if ($cached_data) {
                return $cached_data;
            }
        }
        
        // Scan for REST API registrations
        $rest_apis = $this->scan_for_rest_apis($plugin_dir);
        foreach ($rest_apis as $api) {
            $api_id = 'rest_' . sanitize_title($api['route']);
            $apis[$api_id] = array(
                'type' => 'rest',
                'name' => 'REST: ' . $api['route'],
                'route' => $api['route'],
                'methods' => $api['methods'],
                'file' => $api['file'],
                'line' => $api['line']
            );
        }
        
        // Scan for AJAX handlers
        $ajax_handlers = $this->scan_for_ajax_handlers($plugin_dir);
        foreach ($ajax_handlers as $handler) {
            $api_id = 'ajax_' . sanitize_title($handler['action']);
            $apis[$api_id] = array(
                'type' => 'ajax',
                'name' => 'AJAX: ' . $handler['action'],
                'action' => $handler['action'],
                'callback' => $handler['callback'],
                'file' => $handler['file'],
                'line' => $handler['line']
            );
        }
        
        // Scan for custom post types
        $post_types = $this->scan_for_post_types($plugin_dir);
        foreach ($post_types as $post_type) {
            $api_id = 'cpt_' . sanitize_title($post_type['name']);
            $apis[$api_id] = array(
                'type' => 'post_type',
                'name' => 'Post Type: ' . $post_type['name'],
                'post_type' => $post_type['name'],
                'supports' => $post_type['supports'],
                'file' => $post_type['file'],
                'line' => $post_type['line']
            );
        }
        
        // Scan for custom taxonomies
        $taxonomies = $this->scan_for_taxonomies($plugin_dir);
        foreach ($taxonomies as $taxonomy) {
            $api_id = 'tax_' . sanitize_title($taxonomy['name']);
            $apis[$api_id] = array(
                'type' => 'taxonomy',
                'name' => 'Taxonomy: ' . $taxonomy['name'],
                'taxonomy' => $taxonomy['name'],
                'object_type' => $taxonomy['object_type'],
                'file' => $taxonomy['file'],
                'line' => $taxonomy['line']
            );
        }
        
        // Scan for shortcodes
        $shortcodes = $this->scan_for_shortcodes($plugin_dir);
        foreach ($shortcodes as $shortcode) {
            $api_id = 'shortcode_' . sanitize_title($shortcode['tag']);
            $apis[$api_id] = array(
                'type' => 'shortcode',
                'name' => 'Shortcode: ' . $shortcode['tag'],
                'tag' => $shortcode['tag'],
                'callback' => $shortcode['callback'],
                'file' => $shortcode['file'],
                'line' => $shortcode['line']
            );
        }
        
        // Scan for options
        $options = $this->scan_for_options($plugin_dir);
        foreach ($options as $option) {
            $api_id = 'option_' . sanitize_title($option['name']);
            $apis[$api_id] = array(
                'type' => 'option',
                'name' => 'Option: ' . $option['name'],
                'option_name' => $option['name'],
                'autoload' => $option['autoload'],
                'file' => $option['file'],
                'line' => $option['line']
            );
        }
        
        // Scan for meta fields
        $meta_fields = $this->scan_for_meta_fields($plugin_dir);
        foreach ($meta_fields as $meta) {
            $api_id = 'meta_' . sanitize_title($meta['key']);
            $apis[$api_id] = array(
                'type' => 'meta',
                'name' => 'Meta: ' . $meta['key'],
                'meta_key' => $meta['key'],
                'object_type' => $meta['object_type'],
                'file' => $meta['file'],
                'line' => $meta['line']
            );
        }
        
        // Cache the results
        if (!empty($apis)) {
            file_put_contents($cache_file, json_encode($apis));
        }
        
        return $apis;
    }
    
    /**
     * Scan for REST API registrations
     */
    private function scan_for_rest_apis($plugin_dir) {
        $apis = array();
        $pattern = '/(register_rest_route|register_rest_field)\s*\(\s*[\'"]([^\'"]+)[\'"]?\s*,\s*[\'"]([^\'"]+)[\'"]?/';
        
        $files = $this->get_php_files($plugin_dir);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $namespace = isset($matches[2][$i][0]) ? $matches[2][$i][0] : '';
                    $route = isset($matches[3][$i][0]) ? $matches[3][$i][0] : '';
                    $full_route = $namespace . '/' . $route;
                    
                    // Get the line number
                    $line = substr_count(substr($content, 0, $matches[0][$i][1]), "\n") + 1;
                    
                    // Try to determine HTTP methods
                    $methods = array('GET');
                    if (preg_match('/[\'"]methods[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $content, $method_matches)) {
                        $methods = explode(',', $method_matches[1]);
                        foreach ($methods as &$method) {
                            $method = trim($method);
                        }
                    }
                    
                    $apis[] = array(
                        'route' => $full_route,
                        'methods' => $methods,
                        'file' => str_replace(WP_PLUGIN_DIR, '', $file),
                        'line' => $line
                    );
                }
            }
        }
        
        return $apis;
    }
    
    /**
     * Scan for AJAX handlers
     */
    private function scan_for_ajax_handlers($plugin_dir) {
        $handlers = array();
        $pattern = '/add_action\s*\(\s*[\'"]wp_ajax_([^\'"]+)[\'"]?\s*,\s*[\'"]?([^\'"]+)[\'"]?/';
        
        $files = $this->get_php_files($plugin_dir);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $action = isset($matches[1][$i][0]) ? $matches[1][$i][0] : '';
                    $callback = isset($matches[2][$i][0]) ? $matches[2][$i][0] : '';
                    
                    // Get the line number
                    $line = substr_count(substr($content, 0, $matches[0][$i][1]), "\n") + 1;
                    
                    $handlers[] = array(
                        'action' => $action,
                        'callback' => $callback,
                        'file' => str_replace(WP_PLUGIN_DIR, '', $file),
                        'line' => $line
                    );
                }
            }
        }
        
        return $handlers;
    }
    
    /**
     * Scan for custom post types
     */
    private function scan_for_post_types($plugin_dir) {
        $post_types = array();
        $register_pattern = '/register_post_type\s*\(\s*[\'"]([^\'"]+)[\'"]?/';
        
        $files = $this->get_php_files($plugin_dir);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            if (preg_match_all($register_pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $type = isset($matches[1][$i][0]) ? $matches[1][$i][0] : '';
                    
                    // Get the line number
                    $line = substr_count(substr($content, 0, $matches[0][$i][1]), "\n") + 1;
                    
                    // Try to determine supports
                    $supports = array('title', 'editor');
                    if (preg_match('/[\'"]supports[\'"]\s*=>\s*array\s*\(([^\)]+)\)/', $content, $support_matches)) {
                        $supports_string = $support_matches[1];
                        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $supports_string, $support_items);
                        if (!empty($support_items[1])) {
                            $supports = $support_items[1];
                        }
                    }
                    
                    $post_types[] = array(
                        'name' => $type,
                        'supports' => $supports,
                        'file' => str_replace(WP_PLUGIN_DIR, '', $file),
                        'line' => $line
                    );
                }
            }
        }
        
        return $post_types;
    }
    
    /**
     * Scan for custom taxonomies
     */
    private function scan_for_taxonomies($plugin_dir) {
        $taxonomies = array();
        $register_pattern = '/register_taxonomy\s*\(\s*[\'"]([^\'"]+)[\'"]?\s*,\s*[\'"]([^\'"]+)[\'"]?/';
        
        $files = $this->get_php_files($plugin_dir);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            if (preg_match_all($register_pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $taxonomy = isset($matches[1][$i][0]) ? $matches[1][$i][0] : '';
                    $object_type = isset($matches[2][$i][0]) ? $matches[2][$i][0] : '';
                    
                    // Get the line number
                    $line = substr_count(substr($content, 0, $matches[0][$i][1]), "\n") + 1;
                    
                    $taxonomies[] = array(
                        'name' => $taxonomy,
                        'object_type' => $object_type,
                        'file' => str_replace(WP_PLUGIN_DIR, '', $file),
                        'line' => $line
                    );
                }
            }
        }
        
        return $taxonomies;
    }
    
    /**
     * Scan for shortcodes
     */
    private function scan_for_shortcodes($plugin_dir) {
        $shortcodes = array();
        $pattern = '/add_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]?\s*,\s*[\'"]?([^\'"]+)[\'"]?/';
        
        $files = $this->get_php_files($plugin_dir);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $tag = isset($matches[1][$i][0]) ? $matches[1][$i][0] : '';
                    $callback = isset($matches[2][$i][0]) ? $matches[2][$i][0] : '';
                    
                    // Get the line number
                    $line = substr_count(substr($content, 0, $matches[0][$i][1]), "\n") + 1;
                    
                    $shortcodes[] = array(
                        'tag' => $tag,
                        'callback' => $callback,
                        'file' => str_replace(WP_PLUGIN_DIR, '', $file),
                        'line' => $line
                    );
                }
            }
        }
        
        return $shortcodes;
    }
    
    /**
     * Scan for options
     */
    private function scan_for_options($plugin_dir) {
        $options = array();
        $pattern = '/(add_option|update_option|get_option)\s*\(\s*[\'"]([^\'"]+)[\'"]?/';
        
        $files = $this->get_php_files($plugin_dir);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $option_name = isset($matches[2][$i][0]) ? $matches[2][$i][0] : '';
                    
                    // Get the line number
                    $line = substr_count(substr($content, 0, $matches[0][$i][1]), "\n") + 1;
                    
                    // Check if already exists
                    $exists = false;
                    foreach ($options as $option) {
                        if ($option['name'] === $option_name) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $options[] = array(
                            'name' => $option_name,
                            'autoload' => 'yes',
                            'file' => str_replace(WP_PLUGIN_DIR, '', $file),
                            'line' => $line
                        );
                    }
                }
            }
        }
        
        return $options;
    }
    
    /**
     * Scan for meta fields
     */
    private function scan_for_meta_fields($plugin_dir) {
        $meta_fields = array();
        $pattern = '/(add_post_meta|update_post_meta|get_post_meta|add_user_meta|update_user_meta|get_user_meta|add_term_meta|update_term_meta|get_term_meta)\s*\(\s*.*?,\s*[\'"]([^\'"]+)[\'"]?/';
        
        $files = $this->get_php_files($plugin_dir);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $function = isset($matches[1][$i][0]) ? $matches[1][$i][0] : '';
                    $meta_key = isset($matches[2][$i][0]) ? $matches[2][$i][0] : '';
                    
                    // Determine object type based on function
                    $object_type = 'post';
                    if (strpos($function, 'user_meta') !== false) {
                        $object_type = 'user';
                    } else if (strpos($function, 'term_meta') !== false) {
                        $object_type = 'term';
                    }
                    
                    // Get the line number
                    $line = substr_count(substr($content, 0, $matches[0][$i][1]), "\n") + 1;
                    
                    // Check if already exists
                    $exists = false;
                    foreach ($meta_fields as $meta) {
                        if ($meta['key'] === $meta_key && $meta['object_type'] === $object_type) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $meta_fields[] = array(
                            'key' => $meta_key,
                            'object_type' => $object_type,
                            'file' => str_replace(WP_PLUGIN_DIR, '', $file),
                            'line' => $line
                        );
                    }
                }
            }
        }
        
        return $meta_fields;
    }
    
    /**
     * Get all PHP files in a directory recursively
     */
    private function get_php_files($dir) {
        $files = array();
        
        if (!is_dir($dir)) {
            return $files;
        }
        
        try {
            $dir_iterator = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($dir_iterator);
            
            foreach ($iterator as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        } catch (Exception $e) {
            // Log error but continue
            error_log('API Exposer - Error scanning directory: ' . $e->getMessage());
        }
        
        return $files;
    }
    
    /**
     * Create webhook route in webhook manager
     */
    private function create_webhook_route($endpoint) {
        global $wpdb;
        
        // Check if webhook manager table exists
        $table_name = $wpdb->prefix . 'webhook_routes';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return false;
        }
        
        // Create route
        $result = $wpdb->insert(
            $table_name,
            array(
                'route_slug' => $endpoint['route'],
                'http_method' => $endpoint['http_method'],
                'callback_function' => $endpoint['callback_function'],
                'description' => 'Auto-created by API Exposer for ' . $endpoint['plugin_slug'] . ' - ' . $endpoint['api_id'],
                'is_active' => 1,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );
        
        return ($result !== false);
    }
    
    /**
     * Create dynamic callback handler
     */
    public function create_dynamic_handler($plugin_slug, $api_id, $api_type) {
        // Check if API exists
        if (!isset($this->discovered_apis[$plugin_slug]['apis'][$api_id])) {
            return false;
        }
        
        $api = $this->discovered_apis[$plugin_slug]['apis'][$api_id];
        
        // Generate function code based on API type
        $function_code = '';
        
        switch ($api['type']) {
            case 'rest':
                $function_code = $this->create_rest_api_handler($api);
                break;
                
            case 'ajax':
                $function_code = $this->create_ajax_handler($api);
                break;
                
            case 'post_type':
                $function_code = $this->create_post_type_handler($api);
                break;
                
            case 'taxonomy':
                $function_code = $this->create_taxonomy_handler($api);
                break;
                
            case 'shortcode':
                $function_code = $this->create_shortcode_handler($api);
                break;
                
            case 'option':
                $function_code = $this->create_option_handler($api);
                break;
                
            case 'meta':
                $function_code = $this->create_meta_handler($api);
                break;
                
            default:
                return false;
        }
        
        if (empty($function_code)) {
            return false;
        }
        
        // Create handler file
        $handlers_dir = API_EXPOSER_PATH . 'handlers/';
        
        if (!file_exists($handlers_dir)) {
            wp_mkdir_p($handlers_dir);
        }
        
        $handler_file = $handlers_dir . $plugin_slug . '_' . $api_id . '.php';
        file_put_contents($handler_file, $function_code);
        
        return true;
    }
    
    /**
     * Create REST API handler
     */
    private function create_rest_api_handler($api) {
        // Create a function that proxies to the original REST API endpoint
        $function_name = 'api_exposer_' . sanitize_title($api['route']) . '_handler';
        
        $code = "<?php\n";
        $code .= "/**\n";
        $code .= " * REST API Handler for {$api['route']}\n";
        $code .= " * Generated by API Exposer\n";
        $code .= " */\n";
        $code .= "function {$function_name}(\$request) {\n";
        $code .= "    // Create a request to the original REST API endpoint\n";
        $code .= "    \$rest_url = rest_url('{$api['route']}');\n";
        $code .= "    \$method = \$_SERVER['REQUEST_METHOD'];\n";
        $code .= "    \$params = \$request->get_params();\n";
        $code .= "    \$headers = array(\n";
        $code .= "        'Content-Type' => 'application/json',\n";
        $code .= "    );\n";
        $code .= "    \$args = array(\n";
        $code .= "        'method' => \$method,\n";
        $code .= "        'headers' => \$headers,\n";
        $code .= "        'timeout' => 30,\n";
        $code .= "    );\n";
        $code .= "    if (\$method !== 'GET') {\n";
        $code .= "        \$args['body'] = json_encode(\$params);\n";
        $code .= "    } else {\n";
        $code .= "        \$rest_url = add_query_arg(\$params, \$rest_url);\n";
        $code .= "    }\n";
        $code .= "    \$response = wp_remote_request(\$rest_url, \$args);\n";
        $code .= "    if (is_wp_error(\$response)) {\n";
        $code .= "        return new WP_Error('api_error', \$response->get_error_message(), array('status' => 500));\n";
        $code .= "    }\n";
        $code .= "    \$body = json_decode(wp_remote_retrieve_body(\$response), true);\n";
        $code .= "    \$status = wp_remote_retrieve_response_code(\$response);\n";
        $code .= "    return new WP_REST_Response(\$body, \$status);\n";
        $code .= "}\n";
        
        return $code;
    }
    
    /**
     * Create AJAX handler
     */
    private function create_ajax_handler($api) {
        // Create a function that proxies to the original AJAX action
        $function_name = 'api_exposer_' . sanitize_title($api['action']) . '_handler';
        
        $code = "<?php\n";
        $code .= "/**\n";
        $code .= " * AJAX Handler for {$api['action']}\n";
        $code .= " * Generated by API Exposer\n";
        $code .= " */\n";
        $code .= "function {$function_name}(\$request) {\n";
        $code .= "    // Prepare for AJAX action\n";
        $code .= "    \$params = \$request->get_params();\n";
        $code .= "    \$_REQUEST = \$params;\n";
        $code .= "    \$_POST = \$params;\n";
        $code .= "    \$_GET = \$params;\n";
        $code .= "    // Call the original AJAX handler\n";
        $code .= "    ob_start();\n";
        $code .= "    do_action('wp_ajax_{$api['action']}');\n";
        $code .= "    \$output = ob_get_clean();\n";
        $code .= "    // Try to parse JSON response\n";
        $code .= "    \$json_response = json_decode(\$output, true);\n";
        $code .= "    if (\$json_response !== null) {\n";
        $code .= "        return new WP_REST_Response(\$json_response, 200);\n";
        $code .= "    }\n";
        $code .= "    // Return raw output if not JSON\n";
        $code .= "    return new WP_REST_Response(array('data' => \$output), 200);\n";
        $code .= "}\n";
        
        return $code;
    }
    
    /**
     * Create post type handler
     */
    private function create_post_type_handler($api) {
        // Create a function that returns post type data
        $function_name = 'api_exposer_' . sanitize_title($api['post_type']) . '_handler';
        
        $code = "<?php\n";
        $code .= "/**\n";
        $code .= " * Post Type Handler for {$api['post_type']}\n";
        $code .= " * Generated by API Exposer\n";
        $code .= " */\n";
        $code .= "function {$function_name}(\$request) {\n";
        $code .= "    \$params = \$request->get_params();\n";
        $code .= "    \$post_type = '{$api['post_type']}';\n";
        $code .= "    // Get post type data\n";
        $code .= "    \$post_type_obj = get_post_type_object(\$post_type);\n";
        $code .= "    if (!\$post_type_obj) {\n";
        $code .= "        return new WP_Error('invalid_post_type', 'Invalid post type', array('status' => 404));\n";
        $code .= "    }\n";
        $code .= "    // Parse query params\n";
        $code .= "    \$args = array(\n";
        $code .= "        'post_type' => \$post_type,\n";
        $code .= "        'posts_per_page' => isset(\$params['per_page']) ? intval(\$params['per_page']) : 10,\n";
        $code .= "        'paged' => isset(\$params['page']) ? intval(\$params['page']) : 1,\n";
        $code .= "        'orderby' => isset(\$params['orderby']) ? sanitize_text_field(\$params['orderby']) : 'date',\n";
        $code .= "        'order' => isset(\$params['order']) ? sanitize_text_field(\$params['order']) : 'DESC',\n";
        $code .= "    );\n";
        $code .= "    // Add search if specified\n";
        $code .= "    if (isset(\$params['search']) && !empty(\$params['search'])) {\n";
        $code .= "        \$args['s'] = sanitize_text_field(\$params['search']);\n";
        $code .= "    }\n";
        $code .= "    // If ID is specified, get a single post\n";
        $code .= "    if (isset(\$params['id']) && !empty(\$params['id'])) {\n";
        $code .= "        \$args['p'] = intval(\$params['id']);\n";
        $code .= "        \$args['posts_per_page'] = 1;\n";
        $code .= "    }\n";
        $code .= "    // Query posts\n";
        $code .= "    \$query = new WP_Query(\$args);\n";
        $code .= "    \$posts = array();\n";
        $code .= "    // Process results\n";
        $code .= "    foreach (\$query->posts as \$post) {\n";
        $code .= "        \$posts[] = array(\n";
        $code .= "            'id' => \$post->ID,\n";
        $code .= "            'title' => \$post->post_title,\n";
        $code .= "            'content' => \$post->post_content,\n";
        $code .= "            'excerpt' => \$post->post_excerpt,\n";
        $code .= "            'slug' => \$post->post_name,\n";
        $code .= "            'status' => \$post->post_status,\n";
        $code .= "            'date' => \$post->post_date,\n";
        $code .= "            'modified' => \$post->post_modified,\n";
        $code .= "            'author' => \$post->post_author,\n";
        $code .= "            'url' => get_permalink(\$post->ID),\n";
        $code .= "            'featured_image' => get_the_post_thumbnail_url(\$post->ID, 'full'),\n";
        $code .= "            'meta' => get_post_meta(\$post->ID),\n";
        $code .= "        );\n";
        $code .= "    }\n";
        $code .= "    // Return results\n";
        $code .= "    \$result = array(\n";
        $code .= "        'posts' => \$posts,\n";
        $code .= "        'total' => \$query->found_posts,\n";
        $code .= "        'total_pages' => \$query->max_num_pages,\n";
        $code .= "        'current_page' => \$args['paged'],\n";
        $code .= "    );\n";
        $code .= "    return new WP_REST_Response(\$result, 200);\n";
        $code .= "}\n";
        
        return $code;
    }
    
    /**
     * Create taxonomy handler
     */
    private function create_taxonomy_handler($api) {
        // Create a function that returns taxonomy data
        $function_name = 'api_exposer_' . sanitize_title($api['taxonomy']) . '_handler';
        
        $code = "<?php\n";
        $code .= "/**\n";
        $code .= " * Taxonomy Handler for {$api['taxonomy']}\n";
        $code .= " * Generated by API Exposer\n";
        $code .= " */\n";
        $code .= "function {$function_name}(\$request) {\n";
        $code .= "    \$params = \$request->get_params();\n";
        $code .= "    \$taxonomy = '{$api['taxonomy']}';\n";
        $code .= "    // Get taxonomy data\n";
        $code .= "    \$taxonomy_obj = get_taxonomy(\$taxonomy);\n";
        $code .= "    if (!\$taxonomy_obj) {\n";
        $code .= "        return new WP_Error('invalid_taxonomy', 'Invalid taxonomy', array('status' => 404));\n";
        $code .= "    }\n";
        $code .= "    // Parse query params\n";
        $code .= "    \$args = array(\n";
        $code .= "        'taxonomy' => \$taxonomy,\n";
        $code .= "        'hide_empty' => isset(\$params['hide_empty']) ? filter_var(\$params['hide_empty'], FILTER_VALIDATE_BOOLEAN) : false,\n";
        $code .= "        'number' => isset(\$params['per_page']) ? intval(\$params['per_page']) : 0,\n";
        $code .= "        'offset' => isset(\$params['page']) && isset(\$params['per_page']) ? (intval(\$params['page']) - 1) * intval(\$params['per_page']) : 0,\n";
        $code .= "        'orderby' => isset(\$params['orderby']) ? sanitize_text_field(\$params['orderby']) : 'name',\n";
        $code .= "        'order' => isset(\$params['order']) ? sanitize_text_field(\$params['order']) : 'ASC',\n";
        $code .= "    );\n";
        $code .= "    // Search by name if specified\n";
        $code .= "    if (isset(\$params['search']) && !empty(\$params['search'])) {\n";
        $code .= "        \$args['name__like'] = sanitize_text_field(\$params['search']);\n";
        $code .= "    }\n";
        $code .= "    // If ID is specified, get a single term\n";
        $code .= "    if (isset(\$params['id']) && !empty(\$params['id'])) {\n";
        $code .= "        \$args['include'] = array(intval(\$params['id']));\n";
        $code .= "    }\n";
        $code .= "    // Query terms\n";
        $code .= "    \$terms = get_terms(\$args);\n";
        $code .= "    if (is_wp_error(\$terms)) {\n";
        $code .= "        return new WP_Error('term_error', \$terms->get_error_message(), array('status' => 500));\n";
        $code .= "    }\n";
        $code .= "    \$result = array();\n";
        $code .= "    // Process results\n";
        $code .= "    foreach (\$terms as \$term) {\n";
        $code .= "        \$result[] = array(\n";
        $code .= "            'id' => \$term->term_id,\n";
        $code .= "            'name' => \$term->name,\n";
        $code .= "            'slug' => \$term->slug,\n";
        $code .= "            'description' => \$term->description,\n";
        $code .= "            'count' => \$term->count,\n";
        $code .= "            'parent' => \$term->parent,\n";
        $code .= "            'url' => get_term_link(\$term),\n";
        $code .= "            'meta' => get_term_meta(\$term->term_id),\n";
        $code .= "        );\n";
        $code .= "    }\n";
        $code .= "    // Return results\n";
        $code .= "    return new WP_REST_Response(\$result, 200);\n";
        $code .= "}\n";
        
        return $code;
    }
    
    /**
     * Create shortcode handler
     */
    private function create_shortcode_handler($api) {
        // Create a function that returns shortcode output
        $function_name = 'api_exposer_' . sanitize_title($api['tag']) . '_handler';
        
        $code = "<?php\n";
        $code .= "/**\n";
        $code .= " * Shortcode Handler for {$api['tag']}\n";
        $code .= " * Generated by API Exposer\n";
        $code .= " */\n";
        $code .= "function {$function_name}(\$request) {\n";
        $code .= "    \$params = \$request->get_params();\n";
        $code .= "    \$tag = '{$api['tag']}';\n";
        $code .= "    // Get shortcode attributes from params\n";
        $code .= "    \$atts = array();\n";
        $code .= "    foreach (\$params as \$key => \$value) {\n";
        $code .= "        if (\$key !== 'content') {\n";
        $code .= "            \$atts[\$key] = sanitize_text_field(\$value);\n";
        $code .= "        }\n";
        $code .= "    }\n";
        $code .= "    // Get content param if exists\n";
        $code .= "    \$content = isset(\$params['content']) ? \$params['content'] : null;\n";
        $code .= "    // Generate shortcode syntax\n";
        $code .= "    \$shortcode = '[$tag';\n";
        $code .= "    foreach (\$atts as \$key => \$value) {\n";
        $code .= "        \$shortcode .= ' ' . \$key . '=\"' . \$value . '\"';\n";
        $code .= "    }\n";
        $code .= "    \$shortcode .= ']';\n";
        $code .= "    if (\$content !== null) {\n";
        $code .= "        \$shortcode .= \$content . '[/$tag]';\n";
        $code .= "    }\n";
        $code .= "    // Execute shortcode\n";
        $code .= "    \$output = do_shortcode(\$shortcode);\n";
        $code .= "    // Return results\n";
        $code .= "    return new WP_REST_Response(array(\n";
        $code .= "        'output' => \$output,\n";
        $code .= "        'shortcode' => \$shortcode,\n";
        $code .= "    ), 200);\n";
        $code .= "}\n";
        
        return $code;
    }
    
    /**
     * Create option handler
     */
    private function create_option_handler($api) {
        // Create a function that returns option data
        $function_name = 'api_exposer_' . sanitize_title($api['option_name']) . '_handler';
        
        $code = "<?php\n";
        $code .= "/**\n";
        $code .= " * Option Handler for {$api['option_name']}\n";
        $code .= " * Generated by API Exposer\n";
        $code .= " */\n";
        $code .= "function {$function_name}(\$request) {\n";
        $code .= "    \$params = \$request->get_params();\n";
        $code .= "    \$option_name = '{$api['option_name']}';\n";
        $code .= "    // Handle GET request (retrieve option)\n";
        $code .= "    if (\$request->get_method() === 'GET') {\n";
        $code .= "        \$option_value = get_option(\$option_name);\n";
        $code .= "        if (\$option_value === false) {\n";
        $code .= "            return new WP_Error('option_not_found', 'Option not found', array('status' => 404));\n";
        $code .= "        }\n";
        $code .= "        return new WP_REST_Response(array(\n";
        $code .= "            'option_name' => \$option_name,\n";
        $code .= "            'value' => \$option_value,\n";
        $code .= "        ), 200);\n";
        $code .= "    }\n";
        $code .= "    // Handle POST request (update option)\n";
        $code .= "    if (\$request->get_method() === 'POST') {\n";
        $code .= "        // Require authentication for updates\n";
        $code .= "        if (!current_user_can('manage_options')) {\n";
        $code .= "            return new WP_Error('rest_forbidden', 'You do not have permission to update options', array('status' => 403));\n";
        $code .= "        }\n";
        $code .= "        if (!isset(\$params['value'])) {\n";
        $code .= "            return new WP_Error('missing_value', 'Value parameter is required', array('status' => 400));\n";
        $code .= "        }\n";
        $code .= "        \$value = \$params['value'];\n";
        $code .= "        \$result = update_option(\$option_name, \$value);\n";
        $code .= "        return new WP_REST_Response(array(\n";
        $code .= "            'success' => \$result,\n";
        $code .= "            'option_name' => \$option_name,\n";
        $code .= "            'value' => \$value,\n";
        $code .= "        ), 200);\n";
        $code .= "    }\n";
        $code .= "    // Handle DELETE request (delete option)\n";
        $code .= "    if (\$request->get_method() === 'DELETE') {\n";
        $code .= "        // Require authentication for deletion\n";
        $code .= "        if (!current_user_can('manage_options')) {\n";
        $code .= "            return new WP_Error('rest_forbidden', 'You do not have permission to delete options', array('status' => 403));\n";
        $code .= "        }\n";
        $code .= "        \$result = delete_option(\$option_name);\n";
        $code .= "        return new WP_REST_Response(array(\n";
        $code .= "            'success' => \$result,\n";
        $code .= "            'option_name' => \$option_name,\n";
        $code .= "        ), 200);\n";
        $code .= "    }\n";
        $code .= "    return new WP_Error('invalid_method', 'Method not allowed', array('status' => 405));\n";
        $code .= "}\n";
        
        return $code;
    }
    
    /**
     * Create meta handler
     */
    private function create_meta_handler($api) {
        // Create a function that returns and manipulates meta data
        $function_name = 'api_exposer_' . sanitize_title($api['meta_key']) . '_handler';
        
        $code = "<?php\n";
        $code .= "/**\n";
        $code .= " * Meta Handler for {$api['meta_key']} ({$api['object_type']})\n";
        $code .= " * Generated by API Exposer\n";
        $code .= " */\n";
        $code .= "function {$function_name}(\$request) {\n";
        $code .= "    \$params = \$request->get_params();\n";
        $code .= "    \$meta_key = '{$api['meta_key']}';\n


$object_type = '{$api['object_type']}';\n";
        $code .= "    // Require object ID\n";
        $code .= "    if (!isset(\$params['id']) || empty(\$params['id'])) {\n";
        $code .= "        return new WP_Error('missing_id', 'Object ID is required', array('status' => 400));\n";
        $code .= "    }\n";
        $code .= "    \$object_id = intval(\$params['id']);\n";
        $code .= "    // Handle different object types\n";
        $code .= "    switch (\$object_type) {\n";
        $code .= "        case 'post':\n";
        $code .= "            \$get_function = 'get_post_meta';\n";
        $code .= "            \$update_function = 'update_post_meta';\n";
        $code .= "            \$delete_function = 'delete_post_meta';\n";
        $code .= "            \$exists_check = 'get_post';\n";
        $code .= "            break;\n";
        $code .= "        case 'user':\n";
        $code .= "            \$get_function = 'get_user_meta';\n";
        $code .= "            \$update_function = 'update_user_meta';\n";
        $code .= "            \$delete_function = 'delete_user_meta';\n";
        $code .= "            \$exists_check = 'get_userdata';\n";
        $code .= "            break;\n";
        $code .= "        case 'term':\n";
        $code .= "            \$get_function = 'get_term_meta';\n";
        $code .= "            \$update_function = 'update_term_meta';\n";
        $code .= "            \$delete_function = 'delete_term_meta';\n";
        $code .= "            \$exists_check = 'get_term';\n";
        $code .= "            break;\n";
        $code .= "        default:\n";
        $code .= "            return new WP_Error('invalid_object_type', 'Invalid object type', array('status' => 400));\n";
        $code .= "    }\n";
        $code .= "    // Check if object exists\n";
        $code .= "    if (!function_exists(\$exists_check) || !\$exists_check(\$object_id)) {\n";
        $code .= "        return new WP_Error('object_not_found', ucfirst(\$object_type) . ' not found', array('status' => 404));\n";
        $code .= "    }\n";
        $code .= "    // Handle GET request (retrieve meta)\n";
        $code .= "    if (\$request->get_method() === 'GET') {\n";
        $code .= "        \$meta_value = \$get_function(\$object_id, \$meta_key, true);\n";
        $code .= "        return new WP_REST_Response(array(\n";
        $code .= "            'meta_key' => \$meta_key,\n";
        $code .= "            'object_type' => \$object_type,\n";
        $code .= "            'object_id' => \$object_id,\n";
        $code .= "            'value' => \$meta_value,\n";
        $code .= "        ), 200);\n";
        $code .= "    }\n";
        $code .= "    // Handle POST request (update meta)\n";
        $code .= "    if (\$request->get_method() === 'POST') {\n";
        $code .= "        // Require authentication for updates\n";
        $code .= "        if (!current_user_can('edit_' . \$object_type . 's')) {\n";
        $code .= "            return new WP_Error('rest_forbidden', 'You do not have permission to update ' . \$object_type . ' meta', array('status' => 403));\n";
        $code .= "        }\n";
        $code .= "        if (!isset(\$params['value'])) {\n";
        $code .= "            return new WP_Error('missing_value', 'Value parameter is required', array('status' => 400));\n";
        $code .= "        }\n";
        $code .= "        \$value = \$params['value'];\n";
        $code .= "        \$result = \$update_function(\$object_id, \$meta_key, \$value);\n";
        $code .= "        return new WP_REST_Response(array(\n";
        $code .= "            'success' => \$result !== false,\n";
        $code .= "            'meta_key' => \$meta_key,\n";
        $code .= "            'object_type' => \$object_type,\n";
        $code .= "            'object_id' => \$object_id,\n";
        $code .= "            'value' => \$value,\n";
        $code .= "        ), 200);\n";
        $code .= "    }\n";
        $code .= "    // Handle DELETE request (delete meta)\n";
        $code .= "    if (\$request->get_method() === 'DELETE') {\n";
        $code .= "        // Require authentication for deletion\n";
        $code .= "        if (!current_user_can('edit_' . \$object_type . 's')) {\n";
        $code .= "            return new WP_Error('rest_forbidden', 'You do not have permission to delete ' . \$object_type . ' meta', array('status' => 403));\n";
        $code .= "        }\n";
        $code .= "        \$old_value = isset(\$params['value']) ? \$params['value'] : '';\n";
        $code .= "        \$result = \$delete_function(\$object_id, \$meta_key, \$old_value);\n";
        $code .= "        return new WP_REST_Response(array(\n";
        $code .= "            'success' => \$result,\n";
        $code .= "            'meta_key' => \$meta_key,\n";
        $code .= "            'object_type' => \$object_type,\n";
        $code .= "            'object_id' => \$object_id,\n";
        $code .= "        ), 200);\n";
        $code .= "    }\n";
        $code .= "    return new WP_Error('invalid_method', 'Method not allowed', array('status' => 405));\n";
        $code .= "}\n";
        
        return $code;
    }
    
    /**
     * Render main plugin page
     */
    public function render_main_page() {
        ?>
        <div class="wrap">
            <h1>API Exposer - Discover APIs</h1>
            
            <div class="notice notice-info">
                <p>This tool scans installed plugins to discover APIs and allows you to expose them via REST endpoints that can be consumed by external applications.</p>
                <?php if ($this->webhook_manager_exists()): ?>
                    <p><strong>Webhook Manager Integration:</strong> Discovered endpoints can be automatically added to the Webhook Manager.</p>
                <?php else: ?>
                    <p><strong>Note:</strong> Install the Webhook Manager plugin for additional functionality.</p>
                <?php endif; ?>
            </div>
            
            <div class="api-exposer-container">
                <div class="api-exposer-plugins">
                    <h2>Installed Plugins</h2>
                    <p>Select a plugin to scan for APIs:</p>
                    
                    <div class="api-exposer-plugins-list">
                        <?php 
                        $plugins = $this->get_all_plugins();
                        foreach ($plugins as $slug => $plugin): 
                            if (!$plugin['is_active']) continue;
                        ?>
                            <div class="api-exposer-plugin-item" data-slug="<?php echo esc_attr($slug); ?>">
                                <h3><?php echo esc_html($plugin['name']); ?></h3>
                                <p><?php echo esc_html(substr($plugin['description'], 0, 100) . (strlen($plugin['description']) > 100 ? '...' : '')); ?></p>
                                <div class="api-exposer-plugin-actions">
                                    <button class="button button-primary scan-plugin" data-slug="<?php echo esc_attr($slug); ?>">Scan for APIs</button>
                                    <?php if (isset($this->discovered_apis[$slug])): ?>
                                        <span class="dashicons dashicons-yes-alt"></span> 
                                        <span class="api-count"><?php echo count($this->discovered_apis[$slug]['apis']); ?> APIs discovered</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="api-exposer-results" style="display: none;">
                    <h2>Discovered APIs <span id="plugin-name"></span></h2>
                    <button class="button back-to-plugins">← Back to Plugins</button>
                    
                    <div class="api-exposer-results-list"></div>
                </div>
            </div>
        </div>
        
        <!-- Create Endpoint Modal -->
        <div id="create-endpoint-modal" class="api-exposer-modal" style="display: none;">
            <div class="api-exposer-modal-content">
                <span class="api-exposer-close">&times;</span>
                <h2>Create API Endpoint</h2>
                
                <form id="create-endpoint-form">
                    <input type="hidden" id="api-plugin-slug" name="plugin_slug" value="">
                    <input type="hidden" id="api-id" name="api_id" value="">
                    
                    <div class="form-row">
                        <label for="endpoint-route">Route:</label>
                        <input type="text" id="endpoint-route" name="route" required>
                        <p class="description">This will be used as the endpoint path: <code>/api-exposer/v1/[route]</code></p>
                    </div>
                    
                    <div class="form-row">
                        <label for="endpoint-method">HTTP Method:</label>
                        <select id="endpoint-method" name="method">
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="DELETE">DELETE</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <label for="endpoint-description">Description:</label>
                        <textarea id="endpoint-description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="button button-primary">Create Endpoint</button>
                        <button type="button" class="button api-exposer-close">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Scan plugin for APIs
            $('.scan-plugin').on('click', function() {
                var pluginSlug = $(this).data('slug');
                var $button = $(this);
                
                $button.text('Scanning...').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'api_exposer_scan_plugin',
                        plugin_slug: pluginSlug,
                        nonce: apiExposerData.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            showResults(pluginSlug, response.data);
                        } else {
                            alert('Error: ' + response.data);
                            $button.text('Scan for APIs').prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('An error occurred while scanning the plugin.');
                        $button.text('Scan for APIs').prop('disabled', false);
                    }
                });
            });
            
            // Show results
            function showResults(pluginSlug, data) {
                $('.api-exposer-plugins').hide();
                $('.api-exposer-results').show();
                
                $('#plugin-name').text(': ' + data.plugin);
                
                var $resultsList = $('.api-exposer-results-list');
                $resultsList.empty();
                
                if (data.apis && Object.keys(data.apis).length > 0) {
                    var $table = $('<table class="wp-list-table widefat fixed striped"></table>');
                    var $thead = $('<thead><tr><th>API Name</th><th>Type</th><th>Details</th><th>Actions</th></tr></thead>');
                    var $tbody = $('<tbody></tbody>');
                    
                    $table.append($thead).append($tbody);
                    
                    $.each(data.apis, function(id, api) {
                        var $row = $('<tr></tr>');
                        
                        // Name column
                        $row.append('<td>' + api.name + '</td>');
                        
                        // Type column
                        $row.append('<td>' + api.type.toUpperCase() + '</td>');
                        
                        // Details column
                        var details = '';
                        switch (api.type) {
                            case 'rest':
                                details = 'Route: ' + api.route + '<br>Methods: ' + api.methods.join(', ');
                                break;
                            case 'ajax':
                                details = 'Action: ' + api.action + '<br>Callback: ' + api.callback;
                                break;
                            case 'post_type':
                                details = 'Post Type: ' + api.post_type + '<br>Supports: ' + api.supports.join(', ');
                                break;
                            case 'taxonomy':
                                details = 'Taxonomy: ' + api.taxonomy + '<br>Object Type: ' + api.object_type;
                                break;
                            case 'shortcode':
                                details = 'Tag: ' + api.tag + '<br>Callback: ' + api.callback;
                                break;
                            case 'option':
                                details = 'Option Name: ' + api.option_name;
                                break;
                            case 'meta':
                                details = 'Meta Key: ' + api.meta_key + '<br>Object Type: ' + api.object_type;
                                break;
                        }
                        $row.append('<td>' + details + '</td>');
                        
                        // Actions column
                        var actions = '<button class="button create-endpoint" data-plugin="' + pluginSlug + '" data-api="' + id + '">Create Endpoint</button>';
                        $row.append('<td>' + actions + '</td>');
                        
                        $tbody.append($row);
                    });
                    
                    $resultsList.append($table);
                } else {
                    $resultsList.append('<p>No APIs found in this plugin.</p>');
                }
            }
            
            // Back to plugins list
            $('.back-to-plugins').on('click', function() {
                $('.api-exposer-results').hide();
                $('.api-exposer-plugins').show();
                $('.scan-plugin').text('Scan for APIs').prop('disabled', false);
            });
            
            // Show create endpoint modal
            $(document).on('click', '.create-endpoint', function() {
                var pluginSlug = $(this).data('plugin');
                var apiId = $(this).data('api');
                
                $('#api-plugin-slug').val(pluginSlug);
                $('#api-id').val(apiId);
                
                // Suggest a route name based on API ID
                $('#endpoint-route').val(apiId);
                
                $('#create-endpoint-modal').show();
            });
            
            // Close modal
            $('.api-exposer-close').on('click', function() {
                $('#create-endpoint-modal').hide();
            });
            
            // Close modal when clicking outside
            $(window).on('click', function(event) {
                if ($(event.target).hasClass('api-exposer-modal')) {
                    $('#create-endpoint-modal').hide();
                }
            });
            
            // Create endpoint form submit
            $('#create-endpoint-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = $(this).serialize();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'api_exposer_create_route',
                        nonce: apiExposerData.nonce,
                        plugin_slug: $('#api-plugin-slug').val(),
                        api_id: $('#api-id').val(),
                        route: $('#endpoint-route').val(),
                        method: $('#endpoint-method').val(),
                        description: $('#endpoint-description').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Endpoint created successfully! You can now access it at: ' + apiExposerData.rest_url + 'api-exposer/v1/' + response.data.endpoint.route);
                            $('#create-endpoint-modal').hide();
                            
                            // Disable the create button for this API
                            $('.create-endpoint[data-plugin="' + response.data.endpoint.plugin_slug + '"][data-api="' + response.data.endpoint.api_id + '"]')
                                .text('Endpoint Created')
                                .prop('disabled', true)
                                .removeClass('button-primary');
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred while creating the endpoint.');
                    }
                });
            });
        });
        </script>
        
        <style>
        .api-exposer-container {
            margin-top: 20px;
        }
        
        .api-exposer-plugins-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .api-exposer-plugin-item {
            background: #fff;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .api-exposer-plugin-item h3 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .api-exposer-plugin-actions {
            margin-top: 15px;
            display: flex;
            align-items: center;
        }
        
        .api-exposer-plugin-actions .dashicons {
            color: #46b450;
            margin-left: 10px;
        }
        
        .api-exposer-plugin-actions .api-count {
            margin-left: 5px;
            color: #555;
        }
        
        .api-exposer-results {
            margin-top: 20px;
        }
        
        .api-exposer-results h2 {
            display: inline-block;
            margin-right: 15px;
        }
        
        .api-exposer-results-list {
            margin-top: 20px;
        }
        
        /* Modal styles */
        .api-exposer-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        
        .api-exposer-modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 60%;
            max-width: 600px;
            border-radius: 5px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .api-exposer-close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .api-exposer-close:hover,
        .api-exposer-close:focus {
            color: black;
            text-decoration: none;
        }
        
        .form-row {
            margin-bottom: 15px;
        }
        
        .form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .form-row input[type="text"],
        .form-row select,
        .form-row textarea {
            width: 100%;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        
        .form-actions {
            margin-top: 20px;
            text-align: right;
        }
        
        .form-actions button {
            margin-left: 10px;
        }
        </style>
        <?php
    }
    
    /**
     * Render endpoints page
     */
    public function render_endpoints_page() {
        // Handle delete endpoint
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['endpoint_id'])) {
            $endpoint_id = sanitize_text_field($_GET['endpoint_id']);
            
            if (isset($this->exposed_endpoints[$endpoint_id])) {
                unset($this->exposed_endpoints[$endpoint_id]);
                $this->save_exposed_endpoints();
                
                // Show success message
                echo '<div class="notice notice-success is-dismissible"><p>Endpoint deleted successfully.</p></div>';
            }
        }
        
        ?>
        <div class="wrap">
            <h1>API Exposer - Exposed Endpoints</h1>
            
            <div class="notice notice-info">
                <p>Below are all the API endpoints that have been exposed by this plugin. These endpoints can be consumed by external applications.</p>
            </div>
            
            <?php if (empty($this->exposed_endpoints)): ?>
                <div class="notice notice-warning">
                    <p>No endpoints have been exposed yet. Go to the <a href="<?php echo admin_url('admin.php?page=api-exposer'); ?>">Discover APIs</a> page to create endpoints.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Route</th>
                            <th>Full URL</th>
                            <th>Method</th>
                            <th>Plugin</th>
                            <th>API</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->exposed_endpoints as $endpoint_id => $endpoint): ?>
                            <tr>
                                <td><?php echo esc_html($endpoint['route']); ?></td>
                                <td><code><?php echo esc_html(rest_url('api-exposer/v1/' . $endpoint['route'])); ?></code></td>
                                <td><?php echo esc_html($endpoint['http_method']); ?></td>
                                <td><?php echo esc_html($endpoint['plugin_slug']); ?></td>
                                <td><?php echo esc_html($endpoint['api_id']); ?></td>
                                <td><?php echo esc_html($endpoint['created_at']); ?></td>
                                <td>
                                    <a href="<?php echo rest_url('api-exposer/v1/' . $endpoint['route']); ?>" class="button button-small" target="_blank">Test</a>
                                    <a href="<?php echo admin_url('admin.php?page=api-exposer-endpoints&action=delete&endpoint_id=' . $endpoint_id); ?>" class="button button-small" onclick="return confirm('Are you sure you want to delete this endpoint?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Handle form submission
        if (isset($_POST['api_exposer_save_settings']) && check_admin_referer('api_exposer_settings')) {
            // Get settings from form
            $cache_duration = isset($_POST['cache_duration']) ? intval($_POST['cache_duration']) : 86400;
            
            // Save settings
            update_option('api_exposer_cache_duration', $cache_duration);
            
            // Clear cache if requested
            if (isset($_POST['clear_cache'])) {
                $this->clear_cache();
            }
            
            // Show success message
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
            
            // Update cache duration
            $this->cache_duration = $cache_duration;
        }
        
        // Get current settings
        $cache_duration = get_option('api_exposer_cache_duration', 86400);
        
        ?>
        <div class="wrap">
            <h1>API Exposer - Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('api_exposer_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="cache_duration">Cache Duration (seconds)</label></th>
                        <td>
                            <input type="number" name="cache_duration" id="cache_duration" value="<?php echo $cache_duration; ?>" min="0">
                            <p class="description">How long to cache plugin scanning results. Set to 0 to disable caching.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Cache Management</th>
                        <td>
                            <label for="clear_cache">
                                <input type="checkbox" name="clear_cache" id="clear_cache">
                                Clear cache now
                            </label>
                            <p class="description">This will delete all cached API scan results.</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="api_exposer_save_settings" class="button button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Clear the cache
     */
    private function clear_cache() {
        $cache_dir = $this->cache_path;
        
        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '*.json');
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    
    /**
     * Create necessary assets
     */
    private function create_assets() {
        // Create JS directory and file
        $js_dir = API_EXPOSER_PATH . 'assets/js';
        if (!file_exists($js_dir)) {
            wp_mkdir_p($js_dir);
        }
        
        $js_file = $js_dir . '/admin.js';
        if (!file_exists($js_file)) {
            file_put_contents($js_file, "// API Exposer admin JavaScript file\njQuery(document).ready(function($) {\n    // JS functionality is directly embedded in the page templates\n});");
        }
        
        // Create CSS directory and file
        $css_dir = API_EXPOSER_PATH . 'assets/css';
        if (!file_exists($css_dir)) {
            wp_mkdir_p($css_dir);
        }
        
        $css_file = $css_dir . '/admin.css';
        if (!file_exists($css_file)) {
            file_put_contents($css_file, "/* API Exposer admin CSS file */\n/* CSS styles are directly embedded in the page templates */");
        }
    }
}

/**
 * Handler for exposed endpoints
 */
function api_exposer_handle_request($request) {
    // Get the endpoint from exposed endpoints
    $endpoints = get_option('api_exposer_exposed_endpoints', array());
    $path = $request->get_route();
    
    // Find matching endpoint
    foreach ($endpoints as $endpoint) {
        if (isset($endpoint['route']) && '/api-exposer/v1/' . $endpoint['route'] === $path) {
            // Get API details
            $plugin_slug = $endpoint['plugin_slug'];
            $api_id = $endpoint['api_id'];
            
            // Get discovered APIs
            $discovered_apis = get_option('api_exposer_discovered_apis', array());
            
            if (isset($discovered_apis[$plugin_slug]['apis'][$api_id])) {
                $api = $discovered_apis[$plugin_slug]['apis'][$api_id];
                
                // Handle based on API type
                switch ($api['type']) {
                    case 'rest':
                        return api_exposer_handle_rest_api($api, $request);
                    case 'ajax':
                        return api_exposer_handle_ajax($api, $request);
                    case 'post_type':
                        return api_exposer_handle_post_type($api, $request);
                    case 'taxonomy':
                        return api_exposer_handle_taxonomy($api, $request);
                    case 'shortcode':
                        return api_exposer_handle_shortcode($api, $request);
                    case 'option':
                        return api_exposer_handle_option($api, $request);
                    case 'meta':
                        return api_exposer_handle_meta($api, $request);
                }
            }
        }
    }
    
    // If no matching endpoint found
    return new WP_Error('endpoint_not_found', 'Endpoint not found or not properly configured', array('status' => 404));
}

/**
 * REST API handler
 */
function api_exposer_handle_rest_api($api, $request) {
    // Create a request to the original REST API endpoint
    $rest_url = rest_url($api['route']);
    $method = $request->get_method();
    $params = $request->get_params();
    
    $headers = array(
        'Content-Type' => 'application/json',
    );
    
    $args = array(
        'method' => $method,
        'headers' => $headers,
        'timeout' => 30,
    );
    
    if ($method !== 'GET') {
        $args['body'] = json_encode($params);
    } else {
        $rest_url = add_query_arg($params, $rest_url);
    }
    
    $response = wp_remote_request($rest_url, $args);
    
    if (is_wp_error($response)) {
        return new WP_Error('api_error', $response->get_error_message(), array('status' => 500));
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $status = wp_remote_retrieve_response_code($response);
    
    return new WP_REST_Response($body, $status);
}

/**
 * AJAX handler
 */
function api_exposer_handle_ajax($api, $request) {
    // Prepare for AJAX action
    $params = $request->get_params();
    $_REQUEST = $params;
    $_POST = $params;
    $_GET = $params;
    
    // Call the original AJAX handler
    ob_start();
    do_action('wp_ajax_' . $api['action']);
    $output = ob_get_clean();
    
    // Try to parse JSON response
    $json_response = json_decode($output, true);
    if ($json_response !== null) {
        return new WP_REST_Response($json_response, 200);
    }
    
    // Return raw output if not JSON
    return new WP_REST_Response(array('data' => $output), 200);
}

/**
 * Post type handler
 */
function api_exposer_handle_post_type($api, $request) {
    $params = $request->get_params();
    $post_type = $api['post_type'];
    
    // Get post type data
    $post_type_obj = get_post_type_object($post_type);
    if (!$post_type_obj) {
        return new WP_Error('invalid_post_type', 'Invalid post type', array('status' => 404));
    }
    
    // Parse query params
    $args = array(
        'post_type' => $post_type,
        'posts_per_page' => isset($params['per_page']) ? intval($params['per_page']) : 10,
        'paged' => isset($params['page']) ? intval($params['page']) : 1,
        'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date',
        'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
    );
    
    // Add search if specified
    if (isset($params['search']) && !empty($params['search'])) {
        $args['s'] = sanitize_text_field($params['search']);
    }
    
    // If ID is specified, get a single post
    if (isset($params['id']) && !empty($params['id'])) {
        $args['p'] = intval($params['id']);
        $args['posts_per_page'] = 1;
    }
    
    // Query posts
    $query = new WP_Query($args);
    $posts = array();
    
    // Process results
    foreach ($query->posts as $post) {
        $posts[] = array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'author' => $post->post_author,
            'url' => get_permalink($post->ID),
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
            'meta' => get_post_meta($post->ID),
        );
    }
    
    // Return results
    $result = array(
        'posts' => $posts,
        'total' => $query->found_posts,
        'total_pages' => $query->max_num_pages,
        'current_page' => $args['paged'],
    );
    
    return new WP_REST_Response($result, 200);
}

/**
 * Taxonomy handler
 */
function api_exposer_handle_taxonomy($api, $request) {
    $params = $request->get_params();
    $taxonomy = $api['taxonomy'];
    
    // Get taxonomy data
    $taxonomy_obj = get_taxonomy($taxonomy);
    if (!$taxonomy_obj) {
        return new WP_Error('invalid_taxonomy', 'Invalid taxonomy', array('status' => 404));
    }
    
    // Parse query params
    $args = array(
        'taxonomy' => $taxonomy,
        'hide_empty' => isset($params['hide_empty']) ? filter_var($params['hide_empty'], FILTER_VALIDATE_BOOLEAN) : false,
        'number' => isset($params['per_page']) ? intval($params['per_page']) : 0,
        'offset' => isset($params['page']) && isset($params['per_page']) ? (intval($params['page']) - 1) * intval($params['per_page']) : 0,
        'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'name',
        'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'ASC',
    );
    
    // Search by name if specified
    if (isset($params['search']) && !empty($params['search'])) {
        $args['name__like'] = sanitize_text_field($params['search']);
    }
    
    // If ID is specified, get a single term
    if (isset($params['id']) && !empty($params['id'])) {
        $args['include'] = array(intval($params['id']));
    }
    
    // Query terms
    $terms = get_terms($args);
    if (is_wp_error($terms)) {
        return new WP_Error('term_error', $terms->get_error_message(), array('status' => 500));
    }
    
    $result = array();
    
    // Process results
    foreach ($terms as $term) {
        $result[] = array(
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'count' => $term->count,
            'parent' => $term->parent,
            'url' => get_term_link($term),
            'meta' => get_term_meta($term->term_id),
        );
    }
    
    // Return results
    return new WP_REST_Response($result, 200);
}

/**
 * Shortcode handler
 */
function api_exposer_handle_shortcode($api, $request) {
    $params = $request->get_params();
    $tag = $api['tag'];
    
    // Get shortcode attributes from params
    $atts = array();
    foreach ($params as $key => $value) {
        if ($key !== 'content') {
            $atts[$key] = sanitize_text_field($value);
        }
    }
    
    // Get content param if exists
    $content = isset($params['content']) ? $params['content'] : null;
    
    // Generate shortcode syntax
    $shortcode = '[' . $tag;
    foreach ($atts as $key => $value) {
        $shortcode .= ' ' . $key . '="' . $value . '"';
    }
    $shortcode .= ']';
    
    if ($content !== null) {
        $shortcode .= $content . '[/' . $tag . ']';
    }
    
    // Execute shortcode
    $output = do_shortcode($shortcode);
    
    // Return results
    return new WP_REST_Response(array(
        'output' => $output,
        'shortcode' => $shortcode,
    ), 200);
}

/**
 * Option handler
 */
function api_exposer_handle_option($api, $request) {
    $params = $request->get_params();
    $option_name = $api['option_name'];
    
    // Handle GET request (retrieve option)
    if ($request->get_method() === 'GET') {
        $option_value = get_option($option_name);
        
        if ($option_value === false) {
            return new WP_Error('option_not_found', 'Option not found', array('status' => 404));
        }
        
        return new WP_REST_Response(array(
            'option_name' => $option_name,
            'value' => $option_value,
        ), 200);
    }
    
    // Handle POST request (update option)
    if ($request->get_method() === 'POST') {
        // Require authentication for updates
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', 'You do not have permission to update options', array('status' => 403));
        }
        
        if (!isset($params['value'])) {
            return new WP_Error('missing_value', 'Value parameter is required', array('status' => 400));
        }
        
        $value = $params['value'];
        $result = update_option($option_name, $value);
        
        return new WP_REST_Response(array(
            'success' => $result,
            'option_name' => $option_name,
            'value' => $value,
        ), 200);
    }
    
    // Handle DELETE request (delete option)
    if ($request->get_method() === 'DELETE') {
        // Require authentication for deletion
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', 'You do not have permission to delete options', array('status' => 403));
        }
        
        $result = delete_option($option_name);
        
        return new WP_REST_Response(array(
            'success' => $result,
            'option_name' => $option_name,
        ), 200);
    }
    
    return new WP_Error('invalid_method', 'Method not allowed', array('status' => 405));
}

/**
 * Meta handler
 */
function api_exposer_handle_meta($api, $request) {
    $params = $request->get_params();
    $meta_key = $api['meta_key'];
    $object_type = $api['object_type'];
    
    // Require object ID
    if (!isset($params['id']) || empty($params['id'])) {
        return new WP_Error('missing_id', 'Object ID is required', array('status' => 400));
    }
    
    $object_id = intval($params['id']);
    
    // Handle different object types
    switch ($object_type) {
        case 'post':
            $get_function = 'get_post_meta';
            $update_function = 'update_post_meta';
            $delete_function = 'delete_post_meta';
            $exists_check = 'get_post';
            break;
        case 'user':
            $get_function = 'get_user_meta';
            $update_function = 'update_user_meta';
            $delete_function = 'delete_user_meta';
            $exists_check = 'get_userdata';
            break;
        case 'term':
            $get_function = 'get_term_meta';
            $update_function = 'update_term_meta';
            $delete_function = 'delete_term_meta';
            $exists_check = 'get_term';
            break;
        default:
            return new WP_Error('invalid_object_type', 'Invalid object type', array('status' => 400));
    }
    
    // Check if object exists
    if (!function_exists($exists_check) || !$exists_check($object_id)) {
        return new WP_Error('object_not_found', ucfirst($object_type) . ' not found', array('status' => 404));
    }
    
    // Handle GET request (retrieve meta)
    if ($request->get_method() === 'GET') {
        $meta_value = $get_function($object_id, $meta_key, true);
        
        return new WP_REST_Response(array(
            'meta_key' => $meta_key,
            'object_type' => $object_type,
            'object_id' => $object_id,
            'value' => $meta_value,
        ), 200);
    }
    
    // Handle POST request (update meta)
    if ($request->get_method() === 'POST') {
        // Require authentication for updates
        if (!current_user_can('edit_' . $object_type . 's')) {
            return new WP_Error('rest_forbidden', 'You do not have permission to update ' . $object_type . ' meta', array('status' => 403));
        }
        
        if (!isset($params['value'])) {
            return new WP_Error('missing_value', 'Value parameter is required', array('status' => 400));
        }
        
        $value = $params['value'];
        $result = $update_function($object_id, $meta_key, $value);
        
        return new WP_REST_Response(array(
            'success' => $result !== false,
            'meta_key' => $meta_key,
            'object_type' => $object_type,
            'object_id' => $object_id,
            'value' => $value,
        ), 200);
    }
    
    // Handle DELETE request (delete meta)
    if ($request->get_method() === 'DELETE') {
        // Require authentication for deletion
        if (!current_user_can('edit_' . $object_type . 's')) {
            return new WP_Error('rest_forbidden', 'You do not have permission to delete ' . $object_type . ' meta', array('status' => 403));
        }
        
        $old_value = isset($params['value']) ? $params['value'] : '';
        $result = $delete_function($object_id, $meta_key, $old_value);
        
        return new WP_REST_Response(array(
            'success' => $result,
            'meta_key' => $meta_key,
            'object_type' => $object_type,
            'object_id' => $object_id,
        ), 200);
    }
    
    return new WP_Error('invalid_method', 'Method not allowed', array('status' => 405));
}

/**
 * Plugin activation hook
 */
function api_exposer_activate() {
    // Create cache directory
    $cache_dir = plugin_dir_path(__FILE__) . 'cache';
    if (!file_exists($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }
    
    // Create handlers directory
    $handlers_dir = plugin_dir_path(__FILE__) . 'handlers';
    if (!file_exists($handlers_dir)) {
        wp_mkdir_p($handlers_dir);
    }
    
    // Create assets directories
    $assets_dir = plugin_dir_path(__FILE__) . 'assets';
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
        
        // Create JS directory
        $js_dir = $assets_dir . '/js';
        if (!file_exists($js_dir)) {
            wp_mkdir_p($js_dir);
        }
        
        // Create CSS directory
        $css_dir = $assets_dir . '/css';
        if (!file_exists($css_dir)) {
            wp_mkdir_p($css_dir);
        }
        
        // Create JS file
        $js_file = $js_dir . '/admin.js';
        if (!file_exists($js_file)) {
            file_put_contents($js_file, "// API Exposer admin JavaScript file\njQuery(document).ready(function($) {\n    // JS functionality is directly embedded in the page templates\n});");
        }
        
        // Create CSS file
        $css_file = $css_dir . '/admin.css';
        if (!file_exists($css_file)) {
            file_put_contents($css_file, "/* API Exposer admin CSS file */\n/* CSS styles are directly embedded in the page templates */");
        }
    }
    
    // Set default settings
    if (!get_option('api_exposer_cache_duration')) {
        update_option('api_exposer_cache_duration', 86400); // 24 hours
    }
    
    // Clear any existing cache
    api_exposer_clear_cache();
    
    // Add a notice for first-time activation
    set_transient('api_exposer_activation_notice', true, 5);
}

/**
 * Plugin deactivation hook
 */
function api_exposer_deactivate() {
    // Nothing to do on deactivation
}

/**
 * Plugin uninstall hook
 */
function api_exposer_uninstall() {
    // Remove all plugin options
    delete_option('api_exposer_discovered_apis');
    delete_option('api_exposer_exposed_endpoints');
    delete_option('api_exposer_cache_duration');
    
    // Remove cache directory
    $cache_dir = plugin_dir_path(__FILE__) . 'cache';
    if (is_dir($cache_dir)) {
        $files = glob($cache_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($cache_dir);
    }
    
    // Remove handlers directory
    $handlers_dir = plugin_dir_path(__FILE__) . 'handlers';
    if (is_dir($handlers_dir)) {
        $files = glob($handlers_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($handlers_dir);
    }
}

/**
 * Clear cache function
 */
function api_exposer_clear_cache() {
    $cache_dir = plugin_dir_path(__FILE__) . 'cache';
    
    if (is_dir($cache_dir)) {
        $files = glob($cache_dir . '/*.json');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

// Display activation notice
function api_exposer_admin_notice() {
    if (get_transient('api_exposer_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>API Exposer has been activated!</strong> Go to <a href="<?php echo admin_url('admin.php?page=api-exposer'); ?>">API Exposer</a> to start discovering and exposing APIs.</p>
        </div>
        <?php
        delete_transient('api_exposer_activation_notice');
    }
}
add_action('admin_notices', 'api_exposer_admin_notice');

// Register activation and uninstall hooks
register_activation_hook(__FILE__, 'api_exposer_activate');
register_deactivation_hook(__FILE__, 'api_exposer_deactivate');
register_uninstall_hook(__FILE__, 'api_exposer_uninstall');

// Initialize the plugin
function api_exposer_init() {
    API_Exposer::get_instance();
}
add_action('plugins_loaded', 'api_exposer_init');
