<?php
/*
Plugin Name: Cubbit Directory Manager
Plugin URI: https://convobuilder.com/
Description: Comprehensive Cubbit S3 file browser with integrated permissions management
Version: 2.0
Author: WPWakanda, LLC
Author URI: https://wpwakanda.com/
*/

if (!defined('ABSPATH')) {
    exit;
}

class CubbitDirectoryManager {
    private $endpoint = 'https://s3.cubbit.eu';
    private $region = 'eu-central-1';
    private $log_file;

    public function __construct() {
        // Create a log file in the WordPress uploads directory
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/cubbit-directory-manager.log';

        // Register admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_api_routes']);
        add_action('rest_api_init', [$this, 'register_test_endpoint']);
        
        // Register admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Register AJAX handlers
        add_action('wp_ajax_cubbit_list_directory', [$this, 'ajax_list_directory']);
        add_action('wp_ajax_cubbit_update_permissions', [$this, 'ajax_update_permissions']);
        add_action('wp_ajax_cubbit_create_folder', [$this, 'ajax_create_folder']);
        add_action('wp_ajax_cubbit_delete_file', [$this, 'ajax_delete_file']);
        add_action('wp_ajax_cubbit_upload_files', [$this, 'ajax_upload_files']);
    }

    /**
     * Log errors to file
     */
    private function log_error($message) {
        error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", 3, $this->log_file);
    }

    /**
     * Add admin menu and submenu pages
     */
    public function add_admin_menu() {
        add_menu_page(
            'Cubbit Directory Manager',
            'Cubbit Files',
            'manage_options',
            'cubbit-directory-manager',
            [$this, 'render_settings_page'],
            'dashicons-database',
            30
        );
        
        add_submenu_page(
            'cubbit-directory-manager',
            'Browse Files',
            'Browse Files',
            'manage_options',
            'cubbit-directory-browser',
            [$this, 'render_browser_page']
        );
    }

    /**
     * Register REST API routes
     */
    public function register_api_routes() {
        register_rest_route('cubbit-directory-manager/v1', '/list', [
            'methods' => 'GET',
            'callback' => [$this, 'list_directory_contents'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);
        
        register_rest_route('cubbit-directory-manager/v1', '/update-permissions', [
            'methods' => 'POST',
            'callback' => [$this, 'update_permissions'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);
        
        register_rest_route('cubbit-directory-manager/v1', '/create-folder', [
            'methods' => 'POST',
            'callback' => [$this, 'create_folder'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);
        
        register_rest_route('cubbit-directory-manager/v1', '/delete-file', [
            'methods' => 'POST',
            'callback' => [$this, 'delete_file'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);
    }

    /**
     * Register a test endpoint to verify Cubbit connectivity and functionality
     */
    public function register_test_endpoint() {
        register_rest_route('cubbit-directory-manager/v1', '/test-connection', [
            'methods' => 'GET',
            'callback' => [$this, 'test_cubbit_connection'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
    }

    /**
     * Verify admin permissions
     */
    public function check_admin_permissions() {
        return current_user_can('manage_options');
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'cubbit-directory') === false) {
            return;
        }

        wp_enqueue_style('cubbit-directory-styles', plugin_dir_url(__FILE__) . 'css/cubbit-manager.css', [], '2.0');
        wp_enqueue_script('cubbit-directory-script', plugin_dir_url(__FILE__) . 'js/cubbit-manager.js', ['jquery'], '2.0', true);
        
        wp_localize_script('cubbit-directory-script', 'cubbitData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'directoryNonce' => wp_create_nonce('cubbit-directory-nonce')
        ]);
    }

    /**
     * Settings page callback
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Process form submission
        if (isset($_POST['cubbit_save_settings']) && check_admin_referer('cubbit_settings_nonce')) {
            update_option('cubbit_access_key', sanitize_text_field($_POST['cubbit_access_key']));
            update_option('cubbit_secret_key', sanitize_text_field($_POST['cubbit_secret_key']));
            update_option('cubbit_bucket_name', sanitize_text_field($_POST['cubbit_bucket_name']));
            
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        
        // Get current settings
        $access_key = get_option('cubbit_access_key', '');
        $secret_key = get_option('cubbit_secret_key', '');
        $bucket_name = get_option('cubbit_bucket_name', '');
        ?>
        <div class="wrap">
            <h1>Cubbit Directory Manager Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('cubbit_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="cubbit_access_key">Access Key</label></th>
                        <td>
                            <input type="text" id="cubbit_access_key" name="cubbit_access_key" 
                                value="<?php echo esc_attr($access_key); ?>" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cubbit_secret_key">Secret Key</label></th>
                        <td>
                            <input type="password" id="cubbit_secret_key" name="cubbit_secret_key" 
                                value="<?php echo esc_attr($secret_key); ?>" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cubbit_bucket_name">Bucket Name</label></th>
                        <td>
                            <input type="text" id="cubbit_bucket_name" name="cubbit_bucket_name" 
                                value="<?php echo esc_attr($bucket_name); ?>" class="regular-text" required>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="cubbit_save_settings" class="button button-primary" value="Save Settings">
                    <a href="<?php echo esc_url(site_url('/wp-json/cubbit-directory-manager/v1/test-connection')); ?>" class="button" target="_blank">Test Connection</a>
                </p>
            </form>
            
            <div id="connection_test_result"></div>
        </div>
        <?php
    }

    /**
     * Directory browser page callback
     */
    public function render_browser_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Cubbit File Browser</h1>
            
            <div class="cubbit-browser-container">
                <!-- Toolbar -->
                <div class="cubbit-toolbar">
                    <button id="create_folder_btn" class="button"><span class="dashicons dashicons-portfolio"></span> Create Folder</button>
                    <button id="upload_file_btn" class="button"><span class="dashicons dashicons-upload"></span> Upload Files</button>
                    <button id="refresh_btn" class="button"><span class="dashicons dashicons-update"></span> Refresh</button>
                </div>

                <!-- Path Navigation -->
                <div id="cubbit_breadcrumb" class="cubbit-breadcrumb"></div>
                
                <!-- Create Folder Dialog -->
                <div id="create_folder_dialog" class="cubbit-dialog" style="display: none;">
                    <div class="cubbit-dialog-content">
                        <h3>Create New Folder</h3>
                        <div class="form-group">
                            <label for="new_folder_name">Folder Name:</label>
                            <input type="text" id="new_folder_name" required>
                        </div>
                        <div class="form-group">
                            <label for="new_folder_permission">Permissions:</label>
                            <select id="new_folder_permission">
                                <option value="private">Private</option>
                                <option value="public-read">Public Read</option>
                                <option value="authenticated-read">Authenticated Read</option>
                            </select>
                        </div>
                        <div class="dialog-buttons">
                            <button id="create_folder_submit" class="button button-primary">Create</button>
                            <button id="create_folder_cancel" class="button">Cancel</button>
                        </div>
                    </div>
                </div>
                
                <!-- Upload Files Dialog -->
                <div id="upload_files_dialog" class="cubbit-dialog" style="display: none;">
                    <div class="cubbit-dialog-content">
                        <h3>Upload Files</h3>
                        <div class="form-group">
                            <label for="file_upload">Select Files:</label>
                            <input type="file" id="file_upload" multiple>
                        </div>
                        <div class="form-group">
                            <label for="upload_permission">Permissions:</label>
                            <select id="upload_permission">
                                <option value="private">Private</option>
                                <option value="public-read">Public Read</option>
                                <option value="authenticated-read">Authenticated Read</option>
                            </select>
                        </div>
                        <div id="upload_progress"></div>
                        <div class="dialog-buttons">
                            <button id="upload_files_submit" class="button button-primary">Upload</button>
                            <button id="upload_files_cancel" class="button">Cancel</button>
                        </div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="cubbit-content-container">
                    <!-- Directory Contents -->
                    <div id="cubbit_directory_contents" class="cubbit-directory-contents">
                        <p><span class="spinner is-active"></span> Loading contents...</p>
                    </div>

                    <!-- Permissions Panel -->
                    <div class="cubbit-permissions-panel">
                        <h3>Manage Permissions</h3>
                        <p>Select files/folders to manage their permissions.</p>
                        
                        <div id="selected_items_list" class="selected-items-list">
                            <p>No items selected</p>
                        </div>
                        
                       <div class="bulk-permissions-control">
    <select id="bulk_permission_level">
        <option value="private">Private</option>
        <option value="public-read">Public Read</option>
        <option value="authenticated-read">Authenticated Read</option>
    </select>
    <div class="recursive-option" style="margin-top: 10px; display: none;">
        <label>
            <input type="checkbox" id="apply_recursive"> 
            Apply recursively to folder contents
        </label>
        <p class="description">This will apply the permission to all files and subfolders.</p>
    </div>
    <button id="apply_bulk_permissions" class="button button-primary" disabled>Apply Permissions</button>
</div>
                        
                        <div id="permission_update_status"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    

    /**
     * Test Cubbit connection and functionality
     */
    public function test_cubbit_connection() {
        $results = [
            'credentials' => false,
            'bucket_access' => false,
            'acls_enabled' => false,
            'create_test_object' => false,
            'set_acl' => false,
            'delete_test_object' => false,
            'logs' => []
        ];
        
        // Log function for capturing test progress
        $log = function($message) use (&$results) {
            $results['logs'][] = $message;
            $this->log_error("Test: {$message}");
        };
        
        // Step 1: Check credentials
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        $bucketName = get_option('cubbit_bucket_name');
        
        if (empty($accessKey) || empty($secretKey) || empty($bucketName)) {
            $log('Missing Cubbit credentials or bucket name');
            return new WP_REST_Response($results, 200);
        }
        
        $results['credentials'] = true;
        $log('Credentials found');
        
        // Step 2: Check bucket access (list objects)
        $request = new WP_REST_Request('GET', '/cubbit-directory-manager/v1/list');
        $request->set_param('prefix', '');
        
        $response = $this->list_directory_contents($request);
        
        if (is_wp_error($response)) {
            $log('Error accessing bucket: ' . $response->get_error_message());
        } else {
            $results['bucket_access'] = true;
            $log('Successfully accessed bucket');
        }
        
        // Step 3: Check if ACLs are enabled
        $acls_enabled = $this->check_acls_enabled();
        
        if (!$acls_enabled) {
            $log('ACLs are disabled for this bucket. Attempting to enable...');
            
            $enable_result = $this->enable_acls();
            
            if ($enable_result) {
                $log('Successfully enabled ACLs');
                $acls_enabled = true;
            } else {
                $log('Failed to enable ACLs. Please enable them manually in the Cubbit console.');
            }
        } else {
            $log('ACLs are enabled for this bucket');
        }
        
        $results['acls_enabled'] = $acls_enabled;
        
        // If ACLs are not enabled, we can't continue with ACL tests
        if (!$acls_enabled) {
            return new WP_REST_Response($results, 200);
        }
        
        // Step 4: Create a test object
        $test_key = 'test-object-' . uniqid() . '.txt';
        $test_content = 'This is a test object created by the Cubbit Directory Manager plugin at ' . date('Y-m-d H:i:s');
        
        $create_result = $this->create_test_object($bucketName, $test_key, $test_content);
        
        if (!$create_result) {
            $log('Failed to create test object');
            return new WP_REST_Response($results, 200);
        }
        
        $results['create_test_object'] = true;
        $log('Successfully created test object');
        
        // Step 5: Set ACL on the test object
        $acl_result = $this->set_object_acl($bucketName, $test_key, 'public-read');
        
        if (!$acl_result) {
            $log('Failed to set ACL on test object');
        } else {
            $results['set_acl'] = true;
            $log('Successfully set ACL on test object');
        }
        
        // Step 6: Delete the test object
        $delete_result = $this->delete_test_object($bucketName, $test_key);
        
        if (!$delete_result) {
            $log('Failed to delete test object');
        } else {
            $results['delete_test_object'] = true;
            $log('Successfully deleted test object');
        }
        
        return new WP_REST_Response($results, 200);
    }

    /**
     * Check if ACLs are enabled for the bucket
     * 
     * @return bool True if ACLs are enabled, false if ACLs are disabled
     */
    private function check_acls_enabled() {
        // Get Cubbit configuration
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        $bucketName = get_option('cubbit_bucket_name');
        
        if (empty($accessKey) || empty($secretKey) || empty($bucketName)) {
            $this->log_error('Cubbit configuration is incomplete');
            return false;
        }
        
        // Use GetBucketOwnershipControls API to check if ACLs are disabled
        // Format date for AWS requirement
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        // Set up the request
        $endpoint = 'https://s3.cubbit.eu';
        $method = 'GET';
        
        // Create headers
        $headers = [
            'Host' => 's3.cubbit.eu',
            'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'x-amz-date' => $amzDate
        ];
        
        // Create canonical request
        $canonicalUri = "/{$bucketName}?ownershipControls";
        
        // Format canonical headers
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        
        // Remove trailing semicolon
        $signedHeaders = rtrim($signedHeaders, ';');
        
        $canonicalRequest = $method . "\n" .
                          $canonicalUri . "\n" .
                          "" . "\n" .  // Query string (empty because it's in the URI)
                          $canonicalHeaders . "\n" .
                          $signedHeaders . "\n" .
                          "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        
        // Create string to sign
        $algorithm = "AWS4-HMAC-SHA256";
        $credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        
        // Calculate signature
        $signingKey = $this->derive_signature_key($secretKey, $datestamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        // Create authorization header
        $authorizationHeader = "{$algorithm} " .
                             "Credential={$accessKey}/{$credentialScope}, " .
                             "SignedHeaders={$signedHeaders}, " .
                             "Signature={$signature}";
        
        // Set up cURL request
        $url = "{$endpoint}/{$bucketName}?ownershipControls";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Prepare headers for cURL
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        $curlHeaders[] = "Authorization: {$authorizationHeader}";
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        
        // Execute request
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Check for errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log_error("cURL Error when checking ownership controls: {$error}");
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        $this->log_error("Ownership controls response ({$httpCode}): {$result}");
        
        // Parse the XML response
        if ($httpCode === 200) {
            try {
                $xml = simplexml_load_string($result);
                if ($xml) {
                    // Check the ownership control setting
                    if (isset($xml->Rule) && isset($xml->Rule->ObjectOwnership)) {
                        $ownershipSetting = (string)$xml->Rule->ObjectOwnership;
                        $this->log_error("Current ownership setting: {$ownershipSetting}");
                        
                        // Check if ACLs are enabled
                        if ($ownershipSetting === 'BucketOwnerEnforced') {
                            // ACLs are disabled
                            return false;
                        } else {
                            // ACLs are enabled for ObjectWriter and BucketOwnerPreferred
                            return true;
                        }
                    }
                }
            } catch (Exception $e) {
                $this->log_error("Error parsing ownership controls XML: " . $e->getMessage());
            }
        } elseif ($httpCode === 404) {
            // If the ownership controls are not set, ACLs are enabled by default (ObjectWriter)
            $this->log_error("No ownership controls set, ACLs are enabled by default");
            return true;
        }
        
        // Default to assume ACLs are enabled if we can't determine otherwise
        return true;
    }

    /**
     * Set Object Ownership to enable ACLs
     * 
     * @return bool Success status
     */
    public function enable_acls() {
        // Get Cubbit configuration
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        $bucketName = get_option('cubbit_bucket_name');
        
        if (empty($accessKey) || empty($secretKey) || empty($bucketName)) {
            $this->log_error('Cubbit configuration is incomplete');
            return false;
        }
        
        // Format date for AWS requirement
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        // Set up the request
        $endpoint = 'https://s3.cubbit.eu';
        $method = 'PUT';
        
        // Create XML body to set the ownership control to ObjectWriter
        $xmlBody = '<?xml version="1.0" encoding="UTF-8"?>
<OwnershipControls xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
   <Rule>
      <ObjectOwnership>ObjectWriter</ObjectOwnership>
   </Rule>
</OwnershipControls>';
        
        $contentLength = strlen($xmlBody);
        $bodyHash = hash('sha256', $xmlBody);
        
        // Create headers
        $headers = [
            'Host' => 's3.cubbit.eu',
            'x-amz-content-sha256' => $bodyHash,
            'x-amz-date' => $amzDate,
            'Content-Type' => 'application/xml',
            'Content-Length' => $contentLength
        ];
        
        // Create canonical request
        $canonicalUri = "/{$bucketName}?ownershipControls";
        
        // Format canonical headers
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        
        // Remove trailing semicolon
        $signedHeaders = rtrim($signedHeaders, ';');
        
        $canonicalRequest = $method . "\n" .
                          $canonicalUri . "\n" .
                          "" . "\n" .  // Query string (empty because it's in the URI)
                          $canonicalHeaders . "\n" .
                          $signedHeaders . "\n" .
                          $bodyHash;
        
        // Create string to sign
        $algorithm = "AWS4-HMAC-SHA256";
        $credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        
        // Calculate signature
        $signingKey = $this->derive_signature_key($secretKey, $datestamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        // Create authorization header
        $authorizationHeader = "{$algorithm} " .
                             "Credential={$accessKey}/{$credentialScope}, " .
                             "SignedHeaders={$signedHeaders}, " .
                             "Signature={$signature}";
        
        // Set up cURL request
        $url = "{$endpoint}/{$bucketName}?ownershipControls";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlBody);
        
        // Prepare headers for cURL
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        $curlHeaders[] = "Authorization: {$authorizationHeader}";
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        
        // Execute request
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Check for errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log_error("cURL Error when setting ownership controls: {$error}");
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        $this->log_error("Set ownership controls response ({$httpCode}): {$result}");
        
        return ($httpCode >= 200 && $httpCode < 300);
    }

    /**
     * Create a test object in Cubbit storage
     */
    
    private function create_test_object($bucket, $key, $content) {
        // Get Cubbit credentials
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        
        // Format date for AWS requirement
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        // Set up the request
        $endpoint = 'https://s3.cubbit.eu';
        $method = 'PUT';
        
        // Get file size and hash
        $fileSize = strlen($content);
        $fileHash = hash('sha256', $content);
        
        // Content type
        $contentType = 'text/plain';
        
        // Create headers
        $headers = [
            'Host' => 's3.cubbit.eu',
            'Content-Length' => $fileSize,
            'Content-Type' => $contentType,
            'x-amz-content-sha256' => $fileHash,
            'x-amz-date' => $amzDate
        ];
        
        // Create canonical request
        $canonicalUri = "/{$bucket}/{$key}";
        
        // Format canonical headers
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        
        foreach ($headers as $header_key => $value) {
            $canonicalHeaders .= strtolower($header_key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($header_key) . ';';
        }
        
        // Remove trailing semicolon
        $signedHeaders = rtrim($signedHeaders, ';');
        
        $canonicalRequest = $method . "\n" .
                          $canonicalUri . "\n" .
                          "" . "\n" .  // Query string (empty)
                          $canonicalHeaders . "\n" .
                          $signedHeaders . "\n" .
                          $fileHash;
        
        // Create string to sign
        $algorithm = "AWS4-HMAC-SHA256";
        $credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        
        // Calculate signature
        $signingKey = $this->derive_signature_key($secretKey, $datestamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        // Create authorization header
        $authorizationHeader = "{$algorithm} " .
                             "Credential={$accessKey}/{$credentialScope}, " .
                             "SignedHeaders={$signedHeaders}, " .
                             "Signature={$signature}";
        
        // Set up cURL request
        $url = "{$endpoint}/{$bucket}/{$key}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        
        // Prepare headers for cURL
        $curlHeaders = [];
        foreach ($headers as $header_key => $value) {
            $curlHeaders[] = "{$header_key}: {$value}";
        }
        $curlHeaders[] = "Authorization: {$authorizationHeader}";
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        
        // Execute request
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Check for errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log_error("cURL Error when creating test object: {$error}");
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        return ($httpCode >= 200 && $httpCode < 300);
    }

    /**
     * Delete a test object from Cubbit storage
     */
    private function delete_test_object($bucket, $key) {
        // Get Cubbit credentials
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        
        // Format date for AWS requirement
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        // Set up the request
        $endpoint = 'https://s3.cubbit.eu';
        $method = 'DELETE';
        
        // Create headers
        $headers = [
            'Host' => 's3.cubbit.eu',
            'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'x-amz-date' => $amzDate
        ];
        
        // Create canonical request
        $canonicalUri = "/{$bucket}/{$key}";
        
        // Format canonical headers
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        
        foreach ($headers as $header_name => $header_value) {
            $canonicalHeaders .= strtolower($header_name) . ':' . trim($header_value) . "\n";
            $signedHeaders .= strtolower($header_name) . ';';
        }
        
        // Remove trailing semicolon
        $signedHeaders = rtrim($signedHeaders, ';');
        
        $canonicalRequest = $method . "\n" .
                          $canonicalUri . "\n" .
                          "" . "\n" .  // Query string (empty)
                          $canonicalHeaders . "\n" .
                          $signedHeaders . "\n" .
                          "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        
        // Create string to sign
        $algorithm = "AWS4-HMAC-SHA256";
        $credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        
        // Calculate signature
        $signingKey = $this->derive_signature_key($secretKey, $datestamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        // Create authorization header
        $authorizationHeader = "{$algorithm} " .
                             "Credential={$accessKey}/{$credentialScope}, " .
                             "SignedHeaders={$signedHeaders}, " .
                             "Signature={$signature}";
        
        // Set up cURL request
        $url = "{$endpoint}/{$bucket}/{$key}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Prepare headers for cURL
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        $curlHeaders[] = "Authorization: {$authorizationHeader}";
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        
        // Execute request
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Check for errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log_error("cURL Error when deleting test object: {$error}");
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        return ($httpCode === 204 || $httpCode === 200);
    }

    /**
     * AJAX handler for directory listing
     */
    public function ajax_list_directory() {
        check_ajax_referer('cubbit-directory-nonce', '_wpnonce');
        
        $prefix = isset($_GET['prefix']) ? sanitize_text_field($_GET['prefix']) : '';
        
        $request = new WP_REST_Request('GET', '/cubbit-directory-manager/v1/list');
        $request->set_param('prefix', $prefix);
        
        $response = $this->list_directory_contents($request);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        } else {
            wp_send_json_success($response->get_data());
        }
    }

    /**
     * List directory contents API
     */
    public function list_directory_contents($request) {
        // Get configuration
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        $bucketName = get_option('cubbit_bucket_name');

        // Validate configuration
        if (empty($accessKey) || empty($secretKey) || empty($bucketName)) {
            $this->log_error('Cubbit configuration is incomplete');
            return new WP_Error('config_error', 'Cubbit configuration is missing. Please configure the plugin.', ['status' => 500]);
        }

        // Get prefix from request
        $prefix = $request->get_param('prefix') ?? '';

        try {
            // Prepare S3 request
            $amzDate = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');

            $queryParams = [
                'delimiter' => '/',
                'list-type' => '2',
                'prefix' => $prefix
            ];
            ksort($queryParams);
            $canonicalQueryString = http_build_query($queryParams);

            // Create canonical headers
            $canonicalHeaders = implode("\n", [
                "host:s3.cubbit.eu",
                "x-amz-content-sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
                "x-amz-date:{$amzDate}"
            ]);

            $signedHeaders = "host;x-amz-content-sha256;x-amz-date";

            // Create canonical request
            $canonicalRequest = implode("\n", [
                "GET",
                "/{$bucketName}/",
                $canonicalQueryString,
                $canonicalHeaders . "\n",
                $signedHeaders,
                "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
            ]);

            // Create string to sign
            $algorithm = "AWS4-HMAC-SHA256";
            $credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
            $stringToSign = implode("\n", [
                $algorithm,
                $amzDate,
                $credentialScope,
                hash('sha256', $canonicalRequest)
            ]);

            // Calculate signature
            $signingKey = $this->derive_signature_key($secretKey, $datestamp, $this->region, 's3');
            $signature = hash_hmac('sha256', $stringToSign, $signingKey);

            // Create authorization header
            $authorizationHeader = implode(", ", [
                "{$algorithm} Credential={$accessKey}/{$credentialScope}",
                "SignedHeaders={$signedHeaders}",
                "Signature={$signature}"
            ]);

            // Prepare cURL request
            $url = "{$this->endpoint}/{$bucketName}/?" . $canonicalQueryString;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Host: s3.cubbit.eu",
                "x-amz-content-sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
                "x-amz-date: {$amzDate}",
                "Authorization: {$authorizationHeader}"
            ]);

            // Execute request
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Handle response
            if ($httpCode !== 200) {
                $this->log_error("Failed to list contents. HTTP Code: {$httpCode}. Response: {$result}");
                return new WP_Error('request_failed', 'Failed to list contents. Please check your credentials.', ['status' => $httpCode]);
            }

            // Parse XML response
            $xml = simplexml_load_string($result);
            if ($xml === false) {
                $this->log_error('Failed to parse XML response');
                return new WP_Error('parsing_failed', 'Failed to parse XML response');
            }

            // Prepare contents array
            $contents = ['directories' => [], 'files' => []];

            // Process directories
            if (isset($xml->CommonPrefixes)) {
                foreach ($xml->CommonPrefixes as $prefixObj) {
                    $prefixString = (string)$prefixObj->Prefix;
                    $contents['directories'][] = [
                        'name' => rtrim($prefixString, '/'),
                        'path' => $prefixString,
                        'permissions' => $this->get_item_permissions($prefixString)
                    ];
                }
            }

            // Process files
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $object) {
                    $key = (string)$object->Key;
                    if (substr($key, -1) !== '/') {
                        $contents['files'][] = [
                            'name' => basename($key),
                            'path' => $key,
                            'size' => (int)$object->Size,
                            'lastModified' => (string)$object->LastModified,
                            'url' => "{$this->endpoint}/{$bucketName}/" . urlencode($key),
                            'permissions' => $this->get_item_permissions($key)
                        ];
                    }
                }
            }

            // Return successful response
            return new WP_REST_Response([
                'success' => true,
                'prefix' => $prefix,
                'contents' => $contents
            ], 200);

        } catch (Exception $e) {
            // Log any unexpected errors
            $this->log_error('Unexpected error in list_directory_contents: ' . $e->getMessage());
            return new WP_Error('unexpected_error', 'An unexpected error occurred', ['status' => 500]);
        }
    }

    /**
     * AJAX handler for updating permissions
     */
    public function ajax_update_permissions() {
        check_ajax_referer('cubbit-directory-nonce', '_wpnonce');
        
        $items = isset($_POST['items']) ? array_map('sanitize_text_field', $_POST['items']) : [];
        $permission_level = isset($_POST['permission_level']) ? sanitize_text_field($_POST['permission_level']) : '';
        
        $request = new WP_REST_Request('POST', '/cubbit-directory-manager/v1/update-permissions');
        $request->set_param('items', $items);
        $request->set_param('permission_level', $permission_level);
        
        $response = $this->update_permissions($request);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        } else {
            wp_send_json_success($response->get_data());
        }
    }

    /**
     * Update permissions API handler
     */
    public function update_permissions($request) {
        // Get items and permission level from request
        $items = $request->get_param('items');
        $permission_level = $request->get_param('permission_level');
        
        // Validate inputs
        if (empty($items) || !is_array($items) || empty($permission_level)) {
            return new WP_Error('invalid_input', 'Items and permission level are required', ['status' => 400]);
        }
        
        // Get Cubbit configuration
        $bucketName = get_option('cubbit_bucket_name');
        
        // Validate configuration
        if (empty($bucketName)) {
            $this->log_error('Cubbit bucket name is missing');
            return new WP_Error('config_error', 'Cubbit configuration is missing', ['status' => 500]);
        }
        
        $success_count = 0;
        $errors = [];
        
        // Update permissions for each item
        foreach ($items as $item_path) {
            try {
                // Set ACL for object in Cubbit storage
                $result = $this->set_object_acl($bucketName, $item_path, $permission_level);
                
                if ($result) {
                    $success_count++;
                } else {
                    $errors[] = "Failed to update ACL for: {$item_path}";
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                $this->log_error("Exception when updating ACL: " . $e->getMessage());
            }
        }
        
        // Return response with results
        if (empty($errors)) {
            return new WP_REST_Response([
                'success' => true,
                'message' => "Permissions updated successfully for {$success_count} items"
            ], 200);
        } else {
            return new WP_REST_Response([
                'success' => $success_count > 0,
                'message' => "Successfully updated {$success_count} items. Errors: " . implode(', ', $errors)
            ], 207); // 207 Multi-Status
        }
    }

    /**
     * Set ACL for an object in Cubbit storage
     * 
     * @param string $bucket Bucket name
     * @param string $key Object key/path
     * @param string $permission Permission level: 'private', 'public-read', or 'authenticated-read'
     * @return bool Success status
     */
    private function set_object_acl($bucket, $key, $permission) {
        // Get Cubbit credentials
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        
        if (empty($accessKey) || empty($secretKey)) {
            $this->log_error("Missing Cubbit credentials for ACL update");
            return false;
        }

        // Log the ACL update attempt
        $this->log_error("Attempting to set ACL for {$key} to {$permission}");
        
        // Convert PHP permission to S3 canned ACL
        $canned_acl = $this->get_canned_acl($permission);
        if (!$canned_acl) {
            $this->log_error("Invalid permission: {$permission}");
            return false;
        }
        
        // Ensure key is properly URL encoded but slashes remain as-is
        $encodedKey = str_replace('%2F', '/', rawurlencode($key));
        
        // Format date for AWS requirement
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        // Set up the request
        $endpoint = 'https://s3.cubbit.eu';
        $method = 'PUT';
        
        // Empty body hash - this was previously incorrect
        $emptyPayloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        
        // Create headers
        $headers = [
            'Host' => 's3.cubbit.eu',
            'x-amz-acl' => $canned_acl,
            'x-amz-content-sha256' => $emptyPayloadHash,
            'x-amz-date' => $amzDate
        ];
        
        // Create canonical request
        $canonicalUri = "/{$bucket}/{$encodedKey}?acl";
        
        // Format canonical headers
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        
        // Remove trailing semicolon
        $signedHeaders = rtrim($signedHeaders, ';');
        
        $canonicalRequest = $method . "\n" .
                           $canonicalUri . "\n" .
                           "" . "\n" .  // Query string (empty because it's in the URI)
                           $canonicalHeaders . "\n" .
                           $signedHeaders . "\n" .
                           $emptyPayloadHash;
        
        $this->log_error("Canonical Request: " . str_replace("\n", "\\n", $canonicalRequest));
        
        // Create string to sign
        $algorithm = "AWS4-HMAC-SHA256";
        $credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        
        $this->log_error("String to Sign: " . str_replace("\n", "\\n", $stringToSign));
        
        // Calculate signature
        $signingKey = $this->derive_signature_key($secretKey, $datestamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        // Create authorization header
        $authorizationHeader = "{$algorithm} " .
                               "Credential={$accessKey}/{$credentialScope}, " .
                               "SignedHeaders={$signedHeaders}, " .
                               "Signature={$signature}";
        
        // Set up cURL request
        $url = "{$endpoint}/{$bucket}/{$encodedKey}?acl";
        $this->log_error("Request URL: {$url}");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Prepare headers for cURL
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        $curlHeaders[] = "Authorization: {$authorizationHeader}";
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        
        // Add verbose debugging
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        
        // Execute request
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Get verbose debug information
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        $this->log_error("cURL verbose log: " . $verboseLog);
        
        // Check for errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log_error("cURL Error: {$error}");
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        // Log response
        $this->log_error("Response code: {$httpCode}, Response: {$result}");
        
        // If successful, store permission in WordPress database too
        if ($httpCode >= 200 && $httpCode < 300) {
            $stored_permissions = get_option('cubbit_item_permissions', []);
            $stored_permissions[$key] = $permission;
            update_option('cubbit_item_permissions', $stored_permissions);
            $this->log_error("ACL update successful for {$key}");
            return true;
        } else {
            $this->log_error("ACL update failed for {$key}");
            return false;
        }
    }

    /**
     * Convert WordPress permission level to S3 canned ACL
     */
    private function get_canned_acl($permission) {
        switch ($permission) {
            case 'public-read':
                return 'public-read';
            case 'authenticated-read':
                return 'authenticated-read';
            case 'private':
                return 'private';
            default:
                return 'private'; // Default to private
        }
    }

    /**
     * AJAX handler for creating folders
     */
    public function ajax_create_folder() {
        check_ajax_referer('cubbit-directory-nonce', '_wpnonce');
        
        $folder_name = isset($_POST['folder_name']) ? sanitize_text_field($_POST['folder_name']) : '';
        $permission = isset($_POST['permission']) ? sanitize_text_field($_POST['permission']) : 'private';
        $prefix = isset($_POST['prefix']) ? sanitize_text_field($_POST['prefix']) : '';
        
        $request = new WP_REST_Request('POST', '/cubbit-directory-manager/v1/create-folder');
        $request->set_param('folder_name', $prefix . $folder_name);
        $request->set_param('permission', $permission);
        
        $response = $this->create_folder($request);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        } else {
            wp_send_json_success($response->get_data());
        }
    }

    /**
     * Create folder in Cubbit storage
     */
    public function create_folder($request) {
        // Get folder name and permission from request
        $folder_name = $request->get_param('folder_name');
        $permission = $request->get_param('permission') ?? 'private';

        // Validate inputs
        if (empty($folder_name)) {
            return new WP_Error('invalid_input', 'Folder name is required', ['status' => 400]);
        }

        // Get Cubbit configuration
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        $bucketName = get_option('cubbit_bucket_name');

        // Validate configuration
        if (empty($accessKey) || empty($secretKey) || empty($bucketName)) {
            $this->log_error('Cubbit configuration is incomplete');
            return new WP_Error('config_error', 'Cubbit configuration is missing', ['status' => 500]);
        }

        // Ensure folder name ends with slash
        if (substr($folder_name, -1) !== '/') {
            $folder_name .= '/';
        }
        
        // Log the creation attempt
        $this->log_error("Creating folder: {$folder_name} with permission: {$permission}");

        try {
            // Prepare S3 request
            $amzDate = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            
            // URL encode the folder name properly
            $encodedFolderName = str_replace('%2F', '/', rawurlencode($folder_name));

            // Create empty file to represent the folder
            $emptyPayloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

            // Create headers
            $headers = [
                'Host' => 's3.cubbit.eu',
                'Content-Length' => '0',
                'x-amz-content-sha256' => $emptyPayloadHash,
                'x-amz-date' => $amzDate
            ];
            
            // Add ACL header if needed
            if ($permission !== 'private') {
                $headers['x-amz-acl'] = $this->get_canned_acl($permission);
            }

            // Sort headers for canonical request
            ksort($headers);
            $canonicalHeaders = '';
            $signedHeaders = '';
            
            foreach ($headers as $key => $value) {
                $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
                $signedHeaders .= strtolower($key) . ';';
            }
            
            // Remove trailing semicolon
            $signedHeaders = rtrim($signedHeaders, ';');

            // Create canonical request
            $canonicalRequest = "PUT\n" .
                              "/{$bucketName}/{$encodedFolderName}\n" .
                              "\n" . // Empty query string
                              "{$canonicalHeaders}\n" .
                              $signedHeaders . "\n" .
                              $emptyPayloadHash;
                              
            // Log canonical request
            $this->log_error("Canonical Request: " . str_replace("\n", "\\n", $canonicalRequest));

            // Create string to sign
            $algorithm = "AWS4-HMAC-SHA256";
            $credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
            $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
            
            // Log string to sign
            $this->log_error("String to Sign: " . str_replace("\n", "\\n", $stringToSign));

            // Calculate signature
            $signingKey = $this->derive_signature_key($secretKey, $datestamp, $this->region, 's3');
            $signature = hash_hmac('sha256', $stringToSign, $signingKey);

            // Create authorization header
            $authorizationHeader = "{$algorithm} " .
                                  "Credential={$accessKey}/{$credentialScope}, " .
                                  "SignedHeaders={$signedHeaders}, " .
                                  "Signature={$signature}";

            // Prepare cURL request
            $url = "{$this->endpoint}/{$bucketName}/{$encodedFolderName}";
            $this->log_error("Request URL: {$url}");
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ''); // Empty body
            
            // Prepare headers for
            
            // Prepare headers for cURL
            $curlHeaders = [];
            foreach ($headers as $key => $value) {
                $curlHeaders[] = "{$key}: {$value}";
            }
            $curlHeaders[] = "Authorization: {$authorizationHeader}";
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
            
            // Add detailed cURL debugging
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);

            // Execute request
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // Get verbose debug information
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            $this->log_error("cURL verbose log: " . $verboseLog);
            
            // Log response
            $this->log_error("HTTP Code: {$httpCode}");
            $this->log_error("Response: {$result}");
            
            // Check for cURL errors
            if (curl_errno($ch)) {
                $error = curl_error($ch);
                $this->log_error("cURL Error: {$error}");
                curl_close($ch);
                return new WP_Error('curl_error', "cURL Error: {$error}", ['status' => 500]);
            }
            
            curl_close($ch);

            // Handle response
            if ($httpCode < 200 || $httpCode >= 300) {
                $this->log_error("Failed to create folder. HTTP Code: {$httpCode}. Response: {$result}");
                return new WP_Error('folder_creation_failed', "Failed to create folder. HTTP Code: {$httpCode}", ['status' => $httpCode]);
            }

            // Set folder permissions if requested
            if ($permission !== 'private') {
                $this->set_object_acl($bucketName, $folder_name, $permission);
                
                // Store permission in WordPress
                $stored_permissions = get_option('cubbit_item_permissions', []);
                $stored_permissions[$folder_name] = $permission;
                update_option('cubbit_item_permissions', $stored_permissions);
            }

            // Return successful response
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Folder created successfully'
            ], 200);

        } catch (Exception $e) {
            // Log any unexpected errors
            $this->log_error('Unexpected error in create_folder: ' . $e->getMessage());
            return new WP_Error('unexpected_error', 'An unexpected error occurred: ' . $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * AJAX handler for deleting files
     */
    public function ajax_delete_file() {
        check_ajax_referer('cubbit-directory-nonce', '_wpnonce');
        
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        
        $request = new WP_REST_Request('POST', '/cubbit-directory-manager/v1/delete-file');
        $request->set_param('file_path', $file_path);
        
        $response = $this->delete_file($request);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        } else {
            wp_send_json_success($response->get_data());
        }
    }

    /**
     * Delete file API
     */
    public function delete_file($request) {
        // Get file path from request
        $file_path = $request->get_param('file_path');

        // Validate inputs
        if (empty($file_path)) {
            return new WP_Error('invalid_input', 'File path is required', ['status' => 400]);
        }

        // Get Cubbit configuration
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        $bucketName = get_option('cubbit_bucket_name');

        // Validate configuration
        if (empty($accessKey) || empty($secretKey) || empty($bucketName)) {
            $this->log_error('Cubbit configuration is incomplete');
            return new WP_Error('config_error', 'Cubbit configuration is missing', ['status' => 500]);
        }

        try {
            // Prepare S3 request
            $amzDate = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            
            // Empty payload hash
            $emptyPayloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

            // Create canonical headers
            $headers = [
                'Host' => 's3.cubbit.eu',
                'x-amz-content-sha256' => $emptyPayloadHash,
                'x-amz-date' => $amzDate
            ];

            // Format canonical headers
            ksort($headers);
            $canonicalHeaders = '';
            $signedHeaders = '';
            
            foreach ($headers as $key => $value) {
                $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
                $signedHeaders .= strtolower($key) . ';';
            }
            
            // Remove trailing semicolon
            $signedHeaders = rtrim($signedHeaders, ';');

            // Create canonical request
            $canonicalRequest = "DELETE\n" .
                              "/{$bucketName}/{$file_path}\n" .
                              "\n" . // Empty query string
                              $canonicalHeaders . "\n" .
                              $signedHeaders . "\n" .
                              $emptyPayloadHash;

            // Create string to sign
            $algorithm = "AWS4-HMAC-SHA256";
            $credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
            $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

            // Calculate signature
            $signingKey = $this->derive_signature_key($secretKey, $datestamp, $this->region, 's3');
            $signature = hash_hmac('sha256', $stringToSign, $signingKey);

            // Create authorization header
            $authorizationHeader = "{$algorithm} " .
                                  "Credential={$accessKey}/{$credentialScope}, " .
                                  "SignedHeaders={$signedHeaders}, " .
                                  "Signature={$signature}";

            // Prepare cURL request
            $url = "{$this->endpoint}/{$bucketName}/{$file_path}";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            // Prepare headers for cURL
            $curlHeaders = [];
            foreach ($headers as $key => $value) {
                $curlHeaders[] = "{$key}: {$value}";
            }
            $curlHeaders[] = "Authorization: {$authorizationHeader}";
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

            // Execute request
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Handle response
            if ($httpCode !== 204 && $httpCode !== 200) {
                $this->log_error("Failed to delete file. HTTP Code: {$httpCode}. Response: {$result}");
                return new WP_Error('file_deletion_failed', 'Failed to delete file', ['status' => $httpCode]);
            }

            // Remove any stored permissions
            $stored_permissions = get_option('cubbit_item_permissions', []);
            if (isset($stored_permissions[$file_path])) {
                unset($stored_permissions[$file_path]);
                update_option('cubbit_item_permissions', $stored_permissions);
            }

            // Return successful response
            return new WP_REST_Response([
                'success' => true,
                'message' => 'File deleted successfully'
            ], 200);

        } catch (Exception $e) {
            // Log any unexpected errors
            $this->log_error('Unexpected error in delete_file: ' . $e->getMessage());
            return new WP_Error('unexpected_error', 'An unexpected error occurred', ['status' => 500]);
        }
    }

    /**
     * AJAX handler for file uploads
     */
    public function ajax_upload_files() {
        check_ajax_referer('cubbit-directory-nonce', '_wpnonce');
        
        // Get Cubbit configuration
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        $bucketName = get_option('cubbit_bucket_name');
        
        // Validate configuration
        if (empty($accessKey) || empty($secretKey) || empty($bucketName)) {
            $this->log_error('Cubbit configuration is incomplete');
            wp_send_json_error('Cubbit configuration is missing. Please configure the plugin.');
            return;
        }
        
        // Get parameters
        $prefix = isset($_POST['prefix']) ? sanitize_text_field($_POST['prefix']) : '';
        $permission = isset($_POST['permission']) ? sanitize_text_field($_POST['permission']) : 'private';
        
        // Validate files
        if (empty($_FILES['files']) || !isset($_FILES['files']['name']) || !is_array($_FILES['files']['name'])) {
            wp_send_json_error('No files were uploaded.');
            return;
        }
        
        $uploaded = 0;
        $errors = [];
        
        // Process each file
        for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
            $file_name = $_FILES['files']['name'][$i];
            $file_tmp = $_FILES['files']['tmp_name'][$i];
            $file_error = $_FILES['files']['error'][$i];
            
            // Check for upload errors
            if ($file_error !== UPLOAD_ERR_OK) {
                $errors[] = "Error uploading {$file_name}: " . $this->get_upload_error_message($file_error);
                continue;
            }
            
            // Build the object key (path in Cubbit)
            $object_key = $prefix . basename($file_name);
            
            // Get file mime type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_tmp);
            finfo_close($finfo);
            
            // Upload the file
            $result = $this->upload_file($file_tmp, $object_key, $mime_type, $permission);
            
            if ($result) {
                $uploaded++;
            } else {
                $errors[] = "Failed to upload {$file_name} to Cubbit.";
            }
        }
        
        // Prepare response
        if (empty($errors)) {
            wp_send_json_success([
                'message' => "Successfully uploaded {$uploaded} file(s)."
            ]);
        } else {
            if ($uploaded > 0) {
                wp_send_json_success([
                    'message' => "Successfully uploaded {$uploaded} file(s). Errors: " . implode(', ', $errors)
                ]);
            } else {
                wp_send_json_error("Failed to upload files: " . implode(', ', $errors));
            }
        }
    }

    /**
     * Upload a file to Cubbit storage
     */
    private function upload_file($file_path, $object_key, $content_type, $permission = 'private') {
        // Get Cubbit configuration
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        $bucketName = get_option('cubbit_bucket_name');
        
        // Log the upload attempt
        $this->log_error("Uploading file: {$object_key} with content type: {$content_type}");
        
        // URL encode the object key
        $encodedKey = str_replace('%2F', '/', rawurlencode($object_key));
        
        // Prepare S3 request
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        // Get file size
        $fileSize = filesize($file_path);
        
        // Calculate file hash (for proper signing)
        $fileHash = hash_file('sha256', $file_path);
        
        // Create headers
        $headers = [
            'Host' => 's3.cubbit.eu',
            'Content-Length' => $fileSize,
            'Content-Type' => $content_type,
            'x-amz-content-sha256' => $fileHash,
            'x-amz-date' => $amzDate
        ];
        
        // Add ACL header if needed
        if ($permission !== 'private') {
            $acl = $this->get_canned_acl($permission);
            $headers['x-amz-acl'] = $acl;
        }
        
        // Sort headers for canonical request
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        
        // Remove trailing semicolon
        $signedHeaders = rtrim($signedHeaders, ';');
        
        // Create canonical request
        $canonicalRequest = "PUT\n" .
                          "/{$bucketName}/{$encodedKey}\n" .
                          "\n" . // Empty query string
                          $canonicalHeaders . "\n" .
                          $signedHeaders . "\n" .
                          $fileHash;
                          
        // Log canonical request
        $this->log_error("Canonical Request: " . str_replace("\n", "\\n", $canonicalRequest));
        
        // Create string to sign
        $algorithm = "AWS4-HMAC-SHA256";
        $credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        
        // Log string to sign
        $this->log_error("String to Sign: " . str_replace("\n", "\\n", $stringToSign));
        
        // Calculate signature
        $signingKey = $this->derive_signature_key($secretKey, $datestamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        // Create authorization header
        $authorizationHeader = "{$algorithm} " .
                              "Credential={$accessKey}/{$credentialScope}, " .
                              "SignedHeaders={$signedHeaders}, " .
                              "Signature={$signature}";
        
        // Prepare headers array
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        $curlHeaders[] = "Authorization: {$authorizationHeader}";
        
        // Prepare cURL request
        $url = "{$this->endpoint}/{$bucketName}/{$encodedKey}";
        $this->log_error("Request URL: {$url}");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        
        // Set file as request body
        $fileHandle = fopen($file_path, 'r');
        curl_setopt($ch, CURLOPT_INFILE, $fileHandle);
        curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        
        // Add detailed cURL debugging
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        
        // Execute request
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Close file handle
        fclose($fileHandle);
        
        // Get verbose debug information
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        $this->log_error("cURL verbose log: " . $verboseLog);
        
        // Log response
        $this->log_error("HTTP Code: {$httpCode}");
        $this->log_error("Response: {$result}");
        
        // Check for cURL errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log_error("cURL Error: {$error}");
        }
        
        curl_close($ch);
        
        // Store permission if upload was successful and permission isn't private
        if ($httpCode >= 200 && $httpCode < 300 && $permission !== 'private') {
            $stored_permissions = get_option('cubbit_item_permissions', []);
            $stored_permissions[$object_key] = $permission;
            update_option('cubbit_item_permissions', $stored_permissions);
        }
        
        return ($httpCode >= 200 && $httpCode < 300);
    }

    /**
     * Get upload error message
     */
    private function get_upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Get stored permissions for an item
     */
    private function get_item_permissions($item_path) {
        $stored_permissions = get_option('cubbit_item_permissions', []);
        return isset($stored_permissions[$item_path]) ? $stored_permissions[$item_path] : 'private';
    }

    /**
     * Generate signing key for AWS Signature V4
     */
    private function derive_signature_key($key, $date, $region, $service) {
        $kDate = hash_hmac('sha256', $date, "AWS4" . $key, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return $kSigning;
    }
}



// Initialize plugin
function init_cubbit_directory_manager() {
    new CubbitDirectoryManager();
}
add_action('plugins_loaded', 'init_cubbit_directory_manager');

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'cubbit_directory_manager_activate');
register_deactivation_hook(__FILE__, 'cubbit_directory_manager_deactivate');

// Activation function
function cubbit_directory_manager_activate() {
    // Create any necessary database tables or options
    add_option('cubbit_item_permissions', []);
    
    // Create CSS folder and file
    $plugin_dir = plugin_dir_path(__FILE__);
    $css_dir = $plugin_dir . 'css';
    $js_dir = $plugin_dir . 'js';
    
    // Create directories if they don't exist
    if (!is_dir($css_dir)) {
        mkdir($css_dir, 0755, true);
    }
    
    if (!is_dir($js_dir)) {
        mkdir($js_dir, 0755, true);
    }
    
    // Create default CSS file if it doesn't exist
    if (!file_exists($css_dir . '/cubbit-manager.css')) {
        $css_content = '/* Cubbit Directory Manager Styles */';
        // Add your CSS content here
        file_put_contents($css_dir . '/cubbit-manager.css', $css_content);
    }
    
    // Create default JS file if it doesn't exist
    if (!file_exists($js_dir . '/cubbit-manager.js')) {
        $js_content = '/* Cubbit Directory Manager JavaScript */';
        // Add your JS content here
        file_put_contents($js_dir . '/cubbit-manager.js', $js_content);
    }
    
    // Clear rewrite rules
    flush_rewrite_rules();
}

// Deactivation function
function cubbit_directory_manager_deactivate() {
    // Clean up if needed
    flush_rewrite_rules();
}
