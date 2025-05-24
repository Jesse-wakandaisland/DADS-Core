<?php
/*
Plugin Name: Enhanced Webhook Manager
Description: Handles incoming webhooks with dashboard management capabilities
Version: 2.0
Author: ConvoBuilder.com
*/

// Include the file containing callback functions
include(plugin_dir_path(__FILE__) . 'callback-functions.php');

// Activation hook - set up database tables and default routes
register_activation_hook(__FILE__, 'webhook_manager_activate');
function webhook_manager_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'webhook_routes';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        route_slug varchar(255) NOT NULL,
        http_method varchar(10) NOT NULL DEFAULT 'POST',
        callback_function varchar(255) NOT NULL,
        description text,
        is_active tinyint(1) NOT NULL DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY route_slug (route_slug)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Check for database errors
    if (!empty($wpdb->last_error)) {
        error_log('Webhook Manager - Database creation error: ' . $wpdb->last_error);
    }
    
    // Add default routes if table is empty
    $existing_routes = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
    
    if (empty($existing_routes) || $existing_routes === false) {
        // Check if there was an error in getting routes
        if (!empty($wpdb->last_error)) {
            error_log('Webhook Manager - Error fetching routes: ' . $wpdb->last_error);
        }
        
        // Default route 1
        $result1 = $wpdb->insert(
            $table_name, 
            array(
                'route_slug' => 'rfdfhodrfuhtophierueroperihguer',
                'http_method' => 'POST',
                'callback_function' => 'handle_webhook',
                'description' => 'Default webhook endpoint',
                'is_active' => 1,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );
        
        if ($result1 === false) {
            error_log('Webhook Manager - Error adding default route 1: ' . $wpdb->last_error);
        }
        
        // Default route 2
        $result2 = $wpdb->insert(
            $table_name, 
            array(
                'route_slug' => 'webhook-starter',
                'http_method' => 'GET',
                'callback_function' => 'get_stored_content',
                'description' => 'Endpoint to retrieve stored webhook content',
                'is_active' => 1,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );
        
        if ($result2 === false) {
            error_log('Webhook Manager - Error adding default route 2: ' . $wpdb->last_error);
        }
    }
    
    // Enable logging by default for debugging
    update_option('webhook_enable_logging', true);
}

// Handle incoming webhook payload
function handle_webhook() {
    // Check if it's a POST request and has a JSON payload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
        // Get the JSON payload
        $payload = json_decode(file_get_contents('php://input'), true);
        
        // Store the content from the payload
        if (isset($payload['content'])) {
            $content = $payload['content'];
            // Store the content in WordPress options
            update_option('last_webhook_content', $content);
            
            // Log the webhook request
            webhook_log('Received webhook content: ' . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''));
        }
        
        // Respond with success
        http_response_code(200);
        echo 'Webhook received successfully.';
        exit();
    }
    
    // Respond with an error for other requests
    http_response_code(400);
    echo 'Bad Request';
    exit();
}

// Callback function to retrieve stored content
function get_stored_content(WP_REST_Request $request) {
    $content = get_option('last_webhook_content');
    
    if ($content === false) {
        return new WP_Error('no_content', 'No content found.', array('status' => 404));
    }
    
    webhook_log('Content retrieved from webhook storage');
    return new WP_REST_Response(array('content' => $content), 200);
}

// Function to log webhook activities
function webhook_log($message) {
    if (get_option('webhook_enable_logging', false)) {
        $log_file = plugin_dir_path(__FILE__) . 'webhook_log.txt';
        $timestamp = current_time('mysql');
        $log_message = "[{$timestamp}] {$message}\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
}

// Register dynamic endpoints from database
add_action('rest_api_init', 'register_dynamic_webhook_routes');
function register_dynamic_webhook_routes() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'webhook_routes';
    
    // Get all active routes from the database
    $routes = $wpdb->get_results("SELECT * FROM $table_name WHERE is_active = 1", ARRAY_A);
    
    // Log what routes are being registered (if logging is enabled)
    if (get_option('webhook_enable_logging', false)) {
        webhook_log('Registering ' . count($routes) . ' webhook routes from database');
        foreach ($routes as $route) {
            webhook_log('Registering route: ' . $route['route_slug'] . ' (' . $route['http_method'] . ') with callback: ' . $route['callback_function']);
        }
    }
    
    if (!empty($routes)) {
        foreach ($routes as $route) {
            if (function_exists($route['callback_function'])) {
                register_rest_route('convoengine/v1', '/' . $route['route_slug'] . '/', array(
                    'methods' => $route['http_method'],
                    'callback' => $route['callback_function'],
                    'permission_callback' => '__return_true'
                ));
            } else {
                if (get_option('webhook_enable_logging', false)) {
                    webhook_log('WARNING: Callback function "' . $route['callback_function'] . '" for route "' . $route['route_slug'] . '" does not exist');
                }
            }
        }
    }
}

// Add admin menu
add_action('admin_menu', 'webhook_manager_menu');
function webhook_manager_menu() {
    add_menu_page(
        'Webhook Manager',
        'Webhook Manager',
        'manage_options',
        'webhook-manager',
        'webhook_manager_page',
        'dashicons-rest-api',
        30
    );
    
    add_submenu_page(
        'webhook-manager',
        'Add New Route',
        'Add New Route',
        'manage_options',
        'webhook-manager-add',
        'webhook_manager_add_page'
    );
    
    add_submenu_page(
        'webhook-manager',
        'Settings',
        'Settings',
        'manage_options',
        'webhook-manager-settings',
        'webhook_manager_settings_page'
    );
    
    add_submenu_page(
        'webhook-manager',
        'Logs',
        'Logs',
        'manage_options',
        'webhook-manager-logs',
        'webhook_manager_logs_page'
    );
}

// Main admin page
function webhook_manager_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'webhook_routes';
    
    // Check if table exists and create it if not
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        webhook_manager_activate();
        echo '<div class="notice notice-info is-dismissible"><p>Database table initialized. Default routes created.</p></div>';
    }
    
    // Add an explicit refresh option
    if (isset($_GET['action']) && $_GET['action'] == 'refresh') {
        echo '<div class="notice notice-info is-dismissible"><p>Page refreshed. Displaying current routes from database.</p></div>';
    }
    
    // Handle route deletion
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['route_id']) && is_numeric($_GET['route_id'])) {
        $route_id = intval($_GET['route_id']);
        $result = $wpdb->delete($table_name, array('id' => $route_id), array('%d'));
        
        if ($result !== false) {
            echo '<div class="notice notice-success is-dismissible"><p>Route deleted successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Error deleting route: ' . $wpdb->last_error . '</p></div>';
        }
    }
    
    // Handle route activation/deactivation
    if (isset($_GET['action']) && ($_GET['action'] == 'activate' || $_GET['action'] == 'deactivate') && isset($_GET['route_id']) && is_numeric($_GET['route_id'])) {
        $route_id = intval($_GET['route_id']);
        $is_active = ($_GET['action'] == 'activate') ? 1 : 0;
        $result = $wpdb->update($table_name, array('is_active' => $is_active), array('id' => $route_id), array('%d'), array('%d'));
        
        if ($result !== false) {
            $status = $is_active ? 'activated' : 'deactivated';
            echo '<div class="notice notice-success is-dismissible"><p>Route ' . $status . ' successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Error updating route status: ' . $wpdb->last_error . '</p></div>';
        }
    }
    
    // Direct SQL query to ensure we're getting all routes
    $routes = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}webhook_routes ORDER BY created_at DESC", ARRAY_A);
    
    // Debug information 
    echo '<div class="notice notice-info is-dismissible"><p>Found ' . count($routes) . ' route(s) in the database.</p></div>';
    
    // Show database debug info if enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        // Include database error if present
        if (!empty($wpdb->last_error)) {
            echo '<div class="notice notice-error is-dismissible"><p>Database error: ' . esc_html($wpdb->last_error) . '</p></div>';
        }
        
        // Count check
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}webhook_routes");
        echo '<div class="notice notice-info is-dismissible"><p>Database count check: ' . $count . ' routes</p></div>';
    }
    
    // Display the admin interface
    ?>
    <div class="wrap">
        <h1>Webhook Manager</h1>
        <p>Manage your webhook routes below. These endpoints will be available at: <code><?php echo rest_url('convoengine/v1/'); ?>[route-slug]</code></p>
        
        <a href="<?php echo admin_url('admin.php?page=webhook-manager-add'); ?>" class="button button-primary">Add New Route</a>
        <a href="<?php echo admin_url('admin.php?page=webhook-manager&action=refresh'); ?>" class="button">Refresh List</a>
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <a href="<?php echo admin_url('admin.php?page=webhook-manager-settings'); ?>" class="button">Settings</a>
                <a href="<?php echo admin_url('admin.php?page=webhook-manager-logs'); ?>" class="button">View Logs</a>
            </div>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Route Slug</th>
                    <th>Full URL</th>
                    <th>Method</th>
                    <th>Callback Function</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($routes)): ?>
                    <?php foreach ($routes as $route): ?>
                        <tr>
                            <td><?php echo esc_html($route['id']); ?></td>
                            <td><?php echo esc_html($route['route_slug']); ?></td>
                            <td><code><?php echo esc_html(rest_url('convoengine/v1/' . $route['route_slug'])); ?></code></td>
                            <td><?php echo esc_html($route['http_method']); ?></td>
                            <td><?php echo esc_html($route['callback_function']); ?></td>
                            <td><?php echo esc_html($route['description']); ?></td>
                            <td><?php echo $route['is_active'] ? '<span style="color:green;">Active</span>' : '<span style="color:red;">Inactive</span>'; ?></td>
                            <td><?php echo esc_html($route['created_at']); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=webhook-manager-add&action=edit&route_id=' . $route['id']); ?>" class="button button-small">Edit</a>
                                <?php if ($route['is_active']): ?>
                                    <a href="<?php echo admin_url('admin.php?page=webhook-manager&action=deactivate&route_id=' . $route['id']); ?>" class="button button-small">Deactivate</a>
                                <?php else: ?>
                                    <a href="<?php echo admin_url('admin.php?page=webhook-manager&action=activate&route_id=' . $route['id']); ?>" class="button button-small">Activate</a>
                                <?php endif; ?>
                                <a href="<?php echo admin_url('admin.php?page=webhook-manager&action=delete&route_id=' . $route['id']); ?>" class="button button-small" onclick="return confirm('Are you sure you want to delete this route? This action cannot be undone.')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9">No webhook routes found. <a href="<?php echo admin_url('admin.php?page=webhook-manager-add'); ?>">Add one now</a>.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Helper function to get available callback functions
function get_webhook_callback_functions() {
    // Default callbacks
    $callbacks = array('handle_webhook', 'get_stored_content');
    
    // Look for custom callbacks in callback-functions.php
    $callback_file = plugin_dir_path(__FILE__) . 'callback-functions.php';
    if (file_exists($callback_file)) {
        $file_content = file_get_contents($callback_file);
        preg_match_all('/function\s+(\w+)\s*\(/i', $file_content, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $function_name) {
                if (!in_array($function_name, $callbacks)) {
                    $callbacks[] = $function_name;
                }
            }
        }
    }
    
    return $callbacks;
}

// Add JSON content testing tool
add_action('admin_footer', 'webhook_testing_script');
function webhook_testing_script() {
    $screen = get_current_screen();
    
    if ($screen->id !== 'toplevel_page_webhook-manager') {
        return;
    }
    
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Add a link to open the testing modal
        $('.wrap h1').after('<a href="#" id="open-webhook-tester" class="button">Test Webhook</a><div id="webhook-tester-modal" style="display:none; position:fixed; top:50px; left:50%; transform:translateX(-50%); width:80%; max-width:800px; background:#fff; padding:20px; border:1px solid #ccc; box-shadow:0 0 10px rgba(0,0,0,0.2); z-index:9999;"><h2>Webhook Tester</h2><div class="test-form"></div><div class="close-button" style="position:absolute; top:10px; right:10px; cursor:pointer;">×</div></div>');
        
        // Get all routes for the form
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_webhook_routes'
            },
            success: function(response) {
                var routes = JSON.parse(response);
                var form = '<form id="test-webhook-form">';
                
                // Route selection
                form += '<p><label for="test-route">Select Route:</label><br>';
                form += '<select id="test-route" name="test-route">';
                
                $.each(routes, function(i, route) {
                    form += '<option value="' + route.route_slug + '" data-method="' + route.http_method + '">' + route.route_slug + ' (' + route.http_method + ')</option>';
                });
                
                form += '</select></p>';
                
                // JSON payload for POST routes
                form += '<div id="json-payload-container"><p><label for="test-payload">JSON Payload:</label><br>';
                form += '<textarea id="test-payload" name="test-payload" rows="10" style="width:100%;">{\n  "content": "Test webhook content"\n}</textarea></p></div>';
                
                // Submit button
                form += '<p><button type="submit" class="button button-primary">Send Test Request</button></p>';
                
                // Results area
                form += '<div id="test-results" style="display:none;"><h3>Results:</h3><pre style="background:#f0f0f0; padding:10px; overflow:auto;"></pre></div>';
                
                form += '</form>';
                
                $('#webhook-tester-modal .test-form').html(form);
                
                // Show/hide JSON payload based on selected method
                $('#test-route').on('change', function() {
                    var method = $(this).find(':selected').data('method');
                    if (method === 'GET' || method === 'DELETE') {
                        $('#json-payload-container').hide();
                    } else {
                        $('#json-payload-container').show();
                    }
                }).trigger('change');
                
                // Handle form submission
                $('#test-webhook-form').on('submit', function(e) {
                    e.preventDefault();
                    
                    var route = $('#test-route').val();
                    var method = $('#test-route').find(':selected').data('method');
                    var payload = $('#test-payload').val();
                    var $results = $('#test-results');
                    
                    $results.show().find('pre').html('Sending request...');
                    
                    var ajaxSettings = {
                        url: '<?php echo rest_url('convoengine/v1/'); ?>' + route,
                        type: method,
                        dataType: 'json',
                        complete: function(xhr, status) {
                            var responseText = xhr.responseText;
                            try {
                                var responseJson = JSON.parse(responseText);
                                responseText = JSON.stringify(responseJson, null, 2);
                            } catch(e) {
                                // Not JSON, just use the text
                            }
                            
                            $results.find('pre').html('Status: ' + xhr.status + ' ' + xhr.statusText + '\n\nResponse:\n' + responseText);
                        }
                    };
                    
                    if (method === 'POST' || method === 'PUT') {
                        ajaxSettings.contentType = 'application/json';
                        ajaxSettings.data = payload;
                    }
                    
                    $.ajax(ajaxSettings);
                });
            }
        });
        
        // Modal handling
        $('#open-webhook-tester').on('click', function(e) {
            e.preventDefault();
            $('#webhook-tester-modal').show();
        });
        
        $('#webhook-tester-modal .close-button').on('click', function() {
            $('#webhook-tester-modal').hide();
        });
        
        $(document).keyup(function(e) {
            if (e.key === "Escape") {
                $('#webhook-tester-modal').hide();
            }
        });
    });
    </script>
    <?php
}

// AJAX handler for getting routes
add_action('wp_ajax_get_webhook_routes', 'ajax_get_webhook_routes');
function ajax_get_webhook_routes() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'webhook_routes';
    
    $routes = $wpdb->get_results("SELECT route_slug, http_method FROM $table_name WHERE is_active = 1", ARRAY_A);
    echo json_encode($routes);
    wp_die();
}

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'webhook_plugin_settings_link');
function webhook_plugin_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=webhook-manager') . '">Manage Routes</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Logs page
function webhook_manager_logs_page() {
    $log_file = plugin_dir_path(__FILE__) . 'webhook_log.txt';
    
    // Handle log clearing
    if (isset($_POST['clear_logs']) && current_user_can('manage_options')) {
        file_put_contents($log_file, '');
        echo '<div class="notice notice-success is-dismissible"><p>Logs cleared successfully!</p></div>';
    }
    
    // Display the logs
    ?>
    <div class="wrap">
        <h1>Webhook Logs</h1>
        
        <?php if (file_exists($log_file) && filesize($log_file) > 0): ?>
            <form method="post" action="">
                <p>
                    <input type="submit" name="clear_logs" class="button" value="Clear Logs" onclick="return confirm('Are you sure you want to clear all logs? This action cannot be undone.')">
                </p>
            </form>
            
            <div style="background-color: #fff; padding: 10px; border: 1px solid #ccc; height: 400px; overflow-y: scroll; font-family: monospace;">
                <?php echo nl2br(esc_html(file_get_contents($log_file))); ?>
            </div>
        <?php else: ?>
            <p>No logs available. Make sure logging is enabled in the <a href="<?php echo admin_url('admin.php?page=webhook-manager-settings'); ?>">settings</a>.</p>
        <?php endif; ?>
    </div>
    <?php
}

// Add/Edit route page
function webhook_manager_add_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'webhook_routes';
    
    // Initialize variables
    $route = array(
        'id' => 0,
        'route_slug' => '',
        'http_method' => 'POST',
        'callback_function' => '',
        'description' => '',
        'is_active' => 1
    );
    
    $is_edit_mode = false;
    
    // Check if we're in edit mode
    if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['route_id']) && is_numeric($_GET['route_id'])) {
        $route_id = intval($_GET['route_id']);
        $db_route = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $route_id), ARRAY_A);
        
        if ($db_route) {
            $route = $db_route;
            $is_edit_mode = true;
        }
    }
    
    // Handle form submission
    if (isset($_POST['submit_webhook_route'])) {
        // Validate and sanitize input
        $route_slug = sanitize_text_field($_POST['route_slug']);
        $http_method = sanitize_text_field($_POST['http_method']);
        $callback_function = sanitize_text_field($_POST['callback_function']);
        $description = sanitize_textarea_field($_POST['description']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validate route slug
        if (empty($route_slug)) {
            echo '<div class="notice notice-error is-dismissible"><p>Route slug cannot be empty!</p></div>';
        } else {
            // Prepare data for database
            $data = array(
                'route_slug' => $route_slug,
                'http_method' => $http_method,
                'callback_function' => $callback_function,
                'description' => $description,
                'is_active' => $is_active
            );
            
            // Insert or update
            if ($is_edit_mode) {
                $result = $wpdb->update($table_name, $data, array('id' => $route['id']), array('%s', '%s', '%s', '%s', '%d'), array('%d'));
                if ($result !== false) {
                    echo '<div class="notice notice-success is-dismissible"><p>Route updated successfully! <a href="' . admin_url('admin.php?page=webhook-manager') . '">Return to route list</a></p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>Error updating route: ' . $wpdb->last_error . '</p></div>';
                }
            } else {
                // Check if route already exists
                $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE route_slug = %s", $route_slug));
                
                if ($existing) {
                    echo '<div class="notice notice-error is-dismissible"><p>A route with this slug already exists!</p></div>';
                } else {
                    $result = $wpdb->insert($table_name, $data, array('%s', '%s', '%s', '%s', '%d'));
                    
                    if ($result !== false) {
                        echo '<div class="notice notice-success is-dismissible"><p>Route added successfully! <a href="' . admin_url('admin.php?page=webhook-manager') . '">View all routes</a></p></div>';
                        
                        // Reset form after successful addition
                        $route = array(
                            'id' => 0,
                            'route_slug' => '',
                            'http_method' => 'POST',
                            'callback_function' => '',
                            'description' => '',
                            'is_active' => 1
                        );
                    } else {
                        echo '<div class="notice notice-error is-dismissible"><p>Error adding route: ' . $wpdb->last_error . '</p></div>';
                    }
                }
            }
        }
    }
    
    // Get available callback functions
    $available_callbacks = get_webhook_callback_functions();
    
    // Display the form
    ?>
    <div class="wrap">
        <h1><?php echo $is_edit_mode ? 'Edit Route' : 'Add New Route'; ?></h1>
        
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="route_slug">Route Slug</label></th>
                    <td>
                        <input type="text" name="route_slug" id="route_slug" class="regular-text" value="<?php echo esc_attr($route['route_slug']); ?>" required>
                        <p class="description">This will be the endpoint for your webhook: <code><?php echo rest_url('convoengine/v1/'); ?>[route-slug]</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="http_method">HTTP Method</label></th>
                    <td>
                        <select name="http_method" id="http_method">
                            <option value="GET" <?php selected($route['http_method'], 'GET'); ?>>GET</option>
                            <option value="POST" <?php selected($route['http_method'], 'POST'); ?>>POST</option>
                            <option value="PUT" <?php selected($route['http_method'], 'PUT'); ?>>PUT</option>
                            <option value="DELETE" <?php selected($route['http_method'], 'DELETE'); ?>>DELETE</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="callback_function">Callback Function</label></th>
                    <td>
                        <select name="callback_function" id="callback_function">
                            <?php foreach ($available_callbacks as $callback): ?>
                                <option value="<?php echo esc_attr($callback); ?>" <?php selected($route['callback_function'], $callback); ?>><?php echo esc_html($callback); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Function that will handle this webhook request.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="description">Description</label></th>
                    <td>
                        <textarea name="description" id="description" class="large-text" rows="3"><?php echo esc_textarea($route['description']); ?></textarea>
                        <p class="description">Optional description for this webhook route.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Status</th>
                    <td>
                        <label for="is_active">
                            <input type="checkbox" name="is_active" id="is_active" <?php checked($route['is_active'], 1); ?>>
                            Active
                        </label>
                        <p class="description">Inactive routes will not be registered with the REST API.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_webhook_route" class="button button-primary" value="<?php echo $is_edit_mode ? 'Update Route' : 'Add Route'; ?>">
                <a href="<?php echo admin_url('admin.php?page=webhook-manager'); ?>" class="button">Cancel</a>
            </p>
        </form>
    </div>
    <?php
}

// Settings page
function webhook_manager_settings_page() {
    // Handle form submission
    if (isset($_POST['submit_webhook_settings'])) {
        $enable_logging = isset($_POST['enable_logging']) ? true : false;
        update_option('webhook_enable_logging', $enable_logging);
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
    }
    
    // Get current settings
    $enable_logging = get_option('webhook_enable_logging', false);
    
    // Display the form
    ?>
    <div class="wrap">
        <h1>Webhook Manager Settings</h1>
        
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row">Logging</th>
                    <td>
                        <label for="enable_logging">
                            <input type="checkbox" name="enable_logging" id="enable_logging" <?php checked($enable_logging, true); ?>>
                            Enable webhook activity logging
                        </label>
                        <p class="description">Logs will be saved to <code><?php echo plugin_dir_path(__FILE__) . 'webhook_log.txt'; ?></code></p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_webhook_settings" class="button button-primary" value="Save Settings">
            </p>
        </form>
    </div>
    <?php
}
