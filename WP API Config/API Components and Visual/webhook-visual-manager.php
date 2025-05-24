<?php
/*
Plugin Name: API Component Visualizer & Composer (alpha)
Description: Visualize, customize, and compose dynamic components from exposed WordPress APIs
Version: 1.0
Author: ConvoBuilder.com
Requires at least: 5.6
Requires PHP: 7.4
*/

class API_Component_Visualizer {
    private static $instance = null;
    private $components = array();
    private $categories = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('init', [$this, 'initialize_plugin']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function initialize_plugin() {
        $this->load_components();
        $this->register_shortcodes();
    }

    public function add_admin_menu() {
        add_menu_page(
            'API Component Visualizer',
            'API Components',
            'manage_options',
            'api-component-visualizer',
            [$this, 'render_admin_page'],
            'dashicons-art',
            30
        );

        add_submenu_page(
            'api-component-visualizer',
            'Component Library',
            'Component Library',
            'manage_options',
            'api-component-library',
            [$this, 'render_component_library']
        );

        add_submenu_page(
            'api-component-visualizer',
            'Composition Canvas',
            'Composition Canvas',
            'manage_options',
            'api-component-canvas',
            [$this, 'render_composition_canvas']
        );
    }

    public function register_rest_routes() {
        // Route to fetch available components
        register_rest_route('component-visualizer/v1', '/components', [
            'methods' => 'GET',
            'callback' => [$this, 'get_available_components'],
            'permission_callback' => '__return_true'
        ]);

        // Route to save composed component
        register_rest_route('component-visualizer/v1', '/save-composition', [
            'methods' => 'POST',
            'callback' => [$this, 'save_component_composition'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function get_available_components($request) {
        // Fetch components from API Exposer or other sources
        $components = apply_filters('api_component_sources', []);
        return rest_ensure_response($components);
    }

    public function save_component_composition($request) {
        $composition_data = $request->get_json_params();
        // Validate and save composition
        $saved_composition = $this->save_composition($composition_data);
        return rest_ensure_response($saved_composition);
    }

    public function render_admin_page() {
        ?>
        <div class="wrap api-component-visualizer">
            <h1>API Component Visualizer</h1>
            <div class="component-dashboard">
                <div class="component-stats">
                    <h2>Component Overview</h2>
                    <ul>
                        <li>Total Components: <span id="total-components">0</span></li>
                        <li>Available Sources: <span id="component-sources">0</span></li>
                    </ul>
                </div>
                <div class="quick-actions">
                    <a href="<?php echo admin_url('admin.php?page=api-component-library'); ?>" class="button button-primary">
                        View Component Library
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=api-component-canvas'); ?>" class="button button-secondary">
                        Open Composition Canvas
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_component_library() {
        ?>
        <div class="wrap component-library">
            <h1>Component Library</h1>
            <div id="component-library-container">
                <!-- Dynamic component list will be populated here -->
            </div>
        </div>
        <?php
    }

    public function render_composition_canvas() {
        ?>
        <div class="wrap composition-canvas">
            <h1>Composition Canvas</h1>
            <div class="canvas-grid">
                <div id="component-palette" class="sidebar">
                    <!-- Draggable components -->
                </div>
                <div id="composition-area" class="main-canvas">
                    <!-- Drag and drop composition area -->
                </div>
                <div id="composition-controls" class="controls">
                    <button id="save-composition" class="button button-primary">Save Composition</button>
                    <button id="copy-composition" class="button button-secondary">Copy Composition Code</button>
                </div>
            </div>
        </div>
        <?php
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_script('api-component-visualizer', plugin_dir_url(__FILE__) . 'assets/js/component-visualizer.js', ['jquery', 'wp-api'], '1.0', true);
        wp_enqueue_style('api-component-visualizer', plugin_dir_url(__FILE__) . 'assets/css/component-visualizer.css');
    }

    public function register_shortcodes() {
        add_shortcode('api_component_render', [$this, 'render_component_shortcode']);
    }

    public function render_component_shortcode($atts) {
        $atts = shortcode_atts([
            'id' => null,
            'type' => 'default'
        ], $atts);

        // Render specific component based on ID and type
        return $this->generate_component_html($atts['id'], $atts['type']);
    }

    private function generate_component_html($component_id, $type) {
        // Logic to generate HTML for a specific component
        return "<div class='api-generated-component' data-id='{$component_id}' data-type='{$type}'></div>";
    }

    private function load_components() {
        // Load components from database or external sources
        $this->components = get_option('api_component_library', []);
    }

    private function save_composition($composition_data) {
        // Validate and save composition
        $compositions = get_option('api_component_compositions', []);
        $composition_id = uniqid('composition_');
        
        $compositions[$composition_id] = [
            'id' => $composition_id,
            'name' => $composition_data['name'] ?? 'Unnamed Composition',
            'components' => $composition_data['components'],
            'created_at' => current_time('mysql')
        ];

        update_option('api_component_compositions', $compositions);
        return $compositions[$composition_id];
    }
}

// Initialize the plugin
function api_component_visualizer_init() {
    API_Component_Visualizer::get_instance();
}
add_action('plugins_loaded', 'api_component_visualizer_init');
