<?php
/*
Plugin Name: Cubbit Directory Manager Extension
Plugin URI: https://convobuilder.com/
Description: Extension for Cubbit Directory Manager adding recursive permissions functionality
Version: 1.0
Author: WPWakanda LLC
Author URI: https://wpwakanda.com/
*/

if (!defined('ABSPATH')) {
    exit;
}

class CubbitDirectoryManagerExtension {
    private $endpoint = 'https://s3.cubbit.eu';
    private $region = 'eu-central-1';
    private $log_file;

    public function __construct() {
        // Make sure to load this plugin after the main plugin
        add_action('plugins_loaded', [$this, 'init'], 20);
        
        // Create a log file in the WordPress uploads directory
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/cubbit-directory-manager-extension.log';
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if main plugin is active
        if (!class_exists('CubbitDirectoryManager')) {
            add_action('admin_notices', [$this, 'plugin_dependency_notice']);
            return;
        }
        
        // Register custom REST endpoint for recursive permissions
        add_action('rest_api_init', [$this, 'register_api_routes']);
        
        // Register AJAX handler for recursive permissions
        add_action('wp_ajax_cubbit_update_permissions_recursive', [$this, 'ajax_update_permissions_recursive']);
        
        // Add scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets'], 20);
        
        // Filter the browser page to add the recursive option
        add_filter('cubbit_browser_page_content', [$this, 'modify_browser_page_content']);
    }

    /**
     * Add admin notice if main plugin is not active
     */
    public function plugin_dependency_notice() {
        ?>
        <div class="notice notice-error">
            <p>Cubbit Directory Manager Extension requires the Cubbit Directory Manager plugin to be active. Please activate it first.</p>
        </div>
        <?php
    }
    
    /**
     * Register custom REST API endpoint
     */
    public function register_api_routes() {
        register_rest_route('cubbit-directory-manager-ext/v1', '/update-permissions-recursive', [
            'methods' => 'POST',
            'callback' => [$this, 'update_permissions_recursive'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'items' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_array($param);
                    }
                ],
                'permission_level' => [
                    'required' => true
                ]
            ]
        ]);
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'cubbit-directory') === false) {
            return;
        }

        wp_enqueue_style(
            'cubbit-directory-extension-styles', 
            plugin_dir_url(__FILE__) . 'css/cubbit-extension.css', 
            [], 
            '1.0'
        );
        
        wp_enqueue_script(
            'cubbit-directory-extension-script', 
            plugin_dir_url(__FILE__) . 'js/cubbit-extension.js', 
            ['jquery', 'cubbit-directory-script'], 
            '1.0', 
            true
        );
        
        // Pass data to JS
        wp_localize_script('cubbit-directory-extension-script', 'cubbitExtData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restNonce' => wp_create_nonce('wp_rest')
        ]);
    }
    
    /**
     * Modify the browser page content to add recursive option
     */
    public function modify_browser_page_content($content) {
        // Add recursive option HTML
        $recursive_option = '
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
        </div>';
        
        // Replace the existing permissions control with our enhanced version
        $content = str_replace(
            '<div class="bulk-permissions-control">
                            <select id="bulk_permission_level">
                                <option value="private">Private</option>
                                <option value="public-read">Public Read</option>
                                <option value="authenticated-read">Authenticated Read</option>
                            </select>
                            <button id="apply_bulk_permissions" class="button button-primary" disabled>Apply Permissions</button>
                        </div>',
            $recursive_option,
            $content
        );
        
        return $content;
    }
    
    /**
     * AJAX handler for recursive permissions
     */
    public function ajax_update_permissions_recursive() {
        check_ajax_referer('cubbit-directory-nonce', '_wpnonce');
        
        $items = isset($_POST['items']) ? array_map('sanitize_text_field', $_POST['items']) : [];
        $permission_level = isset($_POST['permission_level']) ? sanitize_text_field($_POST['permission_level']) : '';
        
        $request = new WP_REST_Request('POST', '/cubbit-directory-manager-ext/v1/update-permissions-recursive');
        $request->set_param('items', $items);
        $request->set_param('permission_level', $permission_level);
        
        $response = $this->update_permissions_recursive($request);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        } else {
            wp_send_json_success($response->get_data());
        }
    }
    
    /**
     * Update permissions recursively
     */
    public function update_permissions_recursive($request) {
        // Get items and permission level from request
        $items = $request->get_param('items');
        $permission_level = $request->get_param('permission_level');
        
        // Validate inputs
        if (empty($items) || !is_array($items) || empty($permission_level)) {
            return new WP_Error('invalid_input', 'Items and permission level are required', ['status' => 400]);
        }
        
        // Get Cubbit configuration
        $bucketName = get_option('cubbit_bucket_name');
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        
        // Validate configuration
        if (empty($bucketName) || empty($accessKey) || empty($secretKey)) {
            $this->log_error('Cubbit configuration is incomplete');
            return new WP_Error('config_error', 'Cubbit configuration is missing', ['status' => 500]);
        }
        
        $success_count = 0;
        $errors = [];
        
        // Update permissions for each item
        foreach ($items as $item_path) {
            try {
                $this->log_error("Processing recursive permission update for {$item_path}");
                
                // Check if this is a directory
                $is_directory = $this->is_directory($bucketName, $item_path);
                
                if ($is_directory) {
                    // Ensure the path ends with a slash for directories
                    if (substr($item_path, -1) !== '/') {
                        $item_path .= '/';
                    }
                    
                    // Apply permissions recursively
                    $results = $this->set_permissions_recursive($bucketName, $item_path, $permission_level);
                    $success_count += $results['success'];
                    
                    if (!empty($results['errors'])) {
                        $errors = array_merge($errors, $results['errors']);
                    }
                } else {
                    // For non-directory items, use the main plugin's function
                    $request = new WP_REST_Request('POST', '/cubbit-directory-manager/v1/update-permissions');
                    $request->set_param('items', [$item_path]);
                    $request->set_param('permission_level', $permission_level);
                    
                    // Get the response from the main plugin
                    $response = rest_do_request($request);
                    $data = $response->get_data();
                    
                    if ($response->is_error()) {
                        $errors[] = "Failed to update ACL for: {$item_path}";
                    } else {
                        $success_count++;
                    }
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
                'message' => "Successfully updated {$success_count} items. Errors: " . implode('; ', $errors)
            ], 207); // 207 Multi-Status
        }
    }
    
    /**
     * Apply permissions recursively to a folder and its contents
     */
    private function set_permissions_recursive($bucket, $folder_path, $permission) {
        $results = [
            'success' => 0,
            'failure' => 0,
            'errors' => []
        ];
        
        // Get all objects in the folder
        $objects = $this->list_objects_recursive($bucket, $folder_path);
        
        // Apply permission to the folder itself
        $result = $this->set_object_acl($bucket, $folder_path, $permission);
        if ($result) {
            $results['success']++;
        } else {
            $results['failure']++;
            $results['errors'][] = "Failed to set permission on folder: {$folder_path}";
        }
        
        // Apply permission to each object
        foreach ($objects as $object) {
            // Skip the folder itself as we already set its permission
            if ($object === $folder_path) continue;
            
            $result = $this->set_object_acl($bucket, $object, $permission);
            if ($result) {
                $results['success']++;
            } else {
                $results['failure']++;
                $results['errors'][] = "Failed to set permission on: {$object}";
            }
        }
        
        return $results;
    }
    
    /**
     * Recursively list all objects in a path
     */
    private function list_objects_recursive($bucket, $prefix) {
        $all_objects = [];
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        
        if (empty($accessKey) || empty($secretKey)) {
            $this->log_error("Missing Cubbit credentials for recursive listing");
            return $all_objects;
        }
        
        try {
            // Normalize prefix to ensure it ends with a slash if it's a folder
            if (!empty($prefix) && substr($prefix, -1) !== '/') {
                $prefix .= '/';
            }
            
            // Prepare S3 request
            $amzDate = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            
            $queryParams = [
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
                "/{$bucket}/",
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
            $url = "{$this->endpoint}/{$bucket}/?" . $canonicalQueryString;
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
                $this->log_error("Failed to list objects recursively. HTTP Code: {$httpCode}. Response: {$result}");
                return $all_objects;
            }
            
            // Parse XML response
            $xml = simplexml_load_string($result);
            if ($xml === false) {
                $this->log_error('Failed to parse XML response for recursive listing');
                return $all_objects;
            }
            
            // Process files and folders
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $object) {
                    $key = (string)$object->Key;
                    $all_objects[] = $key;
                }
            }
            
            // Check if there are more objects to fetch (pagination)
            if (isset($xml->IsTruncated) && (string)$xml->IsTruncated === 'true' && isset($xml->NextContinuationToken)) {
                $token = (string)$xml->NextContinuationToken;
                $more_objects = $this->list_objects_with_continuation($bucket, $prefix, $token);
                $all_objects = array_merge($all_objects, $more_objects);
            }
            
            return $all_objects;
            
        } catch (Exception $e) {
            $this->log_error('Error in list_objects_recursive: ' . $e->getMessage());
            return $all_objects;
        }
    }
    
    /**
     * Helper function to handle pagination for list_objects_recursive
     */
    private function list_objects_with_continuation($bucket, $prefix, $continuation_token) {
        $objects = [];
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        
        try {
            // Prepare S3 request
            $amzDate = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            
            $queryParams = [
                'list-type' => '2',
                'prefix' => $prefix,
                'continuation-token' => $continuation_token
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
            
            // Rest of the signing process (same as above)
            // Create canonical request
            $canonicalRequest = implode("\n", [
                "GET",
                "/{$bucket}/",
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
            $url = "{$this->endpoint}/{$bucket}/?" . $canonicalQueryString;
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
                $this->log_error("Failed to list continuation objects. HTTP Code: {$httpCode}");
                return $objects;
            }
            
            // Parse XML response
            $xml = simplexml_load_string($result);
            if ($xml === false) {
                return $objects;
            }
            
            // Process files
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $object) {
                    $key = (string)$object->Key;
                    $objects[] = $key;
                }
            }
            
            // Check if there are more objects to fetch
            if (isset($xml->IsTruncated) && (string)$xml->IsTruncated === 'true' && isset($xml->NextContinuationToken)) {
                $token = (string)$xml->NextContinuationToken;
                $more_objects = $this->list_objects_with_continuation($bucket, $prefix, $token);
                $objects = array_merge($objects, $more_objects);
            }
            
            return $objects;
            
        } catch (Exception $e) {
            $this->log_error('Error in list_objects_with_continuation: ' . $e->getMessage());
            return $objects;
        }
    }
    
    /**
     * Check if an item is a directory in S3
     */
    private function is_directory($bucket, $key) {
        // If it has a trailing slash, it's definitely a directory
        if (substr($key, -1) === '/') {
            return true;
        }
        
        // Check if there are objects with this prefix
        $prefix = $key . '/';
        
        // Get the Cubbit configuration
        $accessKey = get_option('cubbit_access_key');
        $secretKey = get_option('cubbit_secret_key');
        
        // Format date for AWS requirement
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        // List objects with the key as prefix
        $queryParams = [
            'delimiter' => '/',
            'list-type' => '2',
            'prefix' => $prefix,
            'max-keys' => '1'
        ];
        ksort($queryParams);
        $canonicalQueryString = http_build_query($queryParams);
        
        // Create headers
        $headers = [
            'Host' => 's3.cubbit.eu',
            'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'x-amz-date' => $amzDate
        ];
        
        // Format canonical headers
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        
        foreach ($headers as $header_key => $value) {
            $canonicalHeaders .= strtolower($header_key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($header_key) . ';';
        }
        
        $signedHeaders = rtrim($signedHeaders, ';');
        
        // Create canonical request
        $canonicalRequest = "GET\n" .
                          "/{$bucket}/\n" .
                          $canonicalQueryString . "\n" .
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
        $url = "{$this->endpoint}/{$bucket}/?" . $canonicalQueryString;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
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
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return false;
        }
        
        // Parse XML response
        $xml = simplexml_load_string($result);
        if ($xml === false) {
            return false;
        }
        
        // Check if there are any contents or common prefixes
        return (isset($xml->Contents) && count($xml->Contents) > 0) || 
               (isset($xml->CommonPrefixes) && count($xml->CommonPrefixes) > 0);
    }
    
    /**
     * Set ACL for an object in Cubbit storage
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
        $this->log_error("Extension attempting to set ACL for {$key} to {$permission}");
        
        // Convert PHP permission to S3 canned ACL
        $canned_acl = $this->get_canned_acl($permission);
        if (!$canned_acl) {
            $this->log_error("Invalid permission: {$permission}");
            return false;
        }
        
        // Properly URL encode the path components while preserving slashes
        $path_parts = explode('/', $key);
        $encoded_parts = array_map('rawurlencode', $path_parts);
        $encodedKey = implode('/', $encoded_parts);
        
        // Format date for AWS requirement
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        // Set up the request
        $method = 'PUT';
        
        // Empty body hash
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
        
        foreach ($headers as $header_key => $value) {
            $canonicalHeaders .= strtolower($header_key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($header_key) . ';';
        }
        
        // Remove trailing semicolon
        $signedHeaders = rtrim($signedHeaders, ';');
        
        $canonicalRequest = $method . "\n" .
                           $canonicalUri . "\n" .
                           "" . "\n" .  // Query string (empty because it's in the URI)
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
        
        // Set up cURL request
        $url = "{$this->endpoint}/{$bucket}/{$encodedKey}?acl";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // Get headers to detect issues
        
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
            $this->log_error("cURL Error for {$key}: {$error}");
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        // If successful, store permission in WordPress database too
        if ($httpCode >= 200 && $httpCode < 300) {
            $stored_permissions = get_option('cubbit_item_permissions', []);
            $stored_permissions[$key] = $permission;
            update_option('cubbit_item_permissions', $stored_permissions);
            $this->log_error("ACL update successful for {$key}");
            return true;
        } else {
            $this->log_error("ACL update failed for {$key}: HTTP {$httpCode}");
            return false;
        }
    }
    
    /**
     * Convert permission level to S3 canned ACL
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
     * Helper method to log errors
     */
    private function log_error($message) {
        error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", 3, $this->log_file);
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
new CubbitDirectoryManagerExtension();

// Register activation hook
register_activation_hook(__FILE__, 'cubbit_directory_manager_extension_activate');

// Activation function
function cubbit_directory_manager_extension_activate() {
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
    if (!file_exists($css_dir . '/cubbit-extension.css')) {
        $css_content = '/* Cubbit Directory Manager Extension Styles */
/* Permission styles */
.permission-public {
    color: #46b450;
    font-weight: bold;
}

.permission-authenticated {
    color: #ffb900;
    font-weight: bold;
}

.permission-private {
    color: #dc3232;
    font-weight: bold;
}

/* Permissions panel */
.recursive-option {
    background: #f9f9f9;
    padding: 10px;
    border-radius: 3px;
    border-left: 4px solid #00a0d2;
    margin-bottom: 10px;
}

.recursive-option .description {
    color: #666;
    margin-top: 5px;
    margin-bottom: 0;
    margin-left: 20px;
    font-style: italic;
}';
        file_put_contents($css_dir . '/cubbit-extension.css', $css_content);
    }
    
    // Create JS file if it doesn't exist
    if (!file_exists($js_dir . '/cubbit-extension.js')) {
        $js_content = '/**
 * Cubbit Directory Manager Extension
 * JavaScript for handling recursive permissions
 */
jQuery(document).ready(function($) {
    // Check if the original plugin\'s JS is loaded
    if (typeof cubbitData === \'undefined\') {
        console.error(\'Cubbit Directory Manager script not loaded\');
        return;
    }
    
    // Add event to check for directories and show/hide recursive option
    $(document).on(\'change\', \'.item-checkbox\', function() {
        updateRecursiveOption();
    });
    
    // Override the original apply bulk permissions function
    if (typeof window.originalApplyBulkPermissions === \'undefined\') {
        window.originalApplyBulkPermissions = window.applyBulkPermissions;
    }
    
    window.applyBulkPermissions = function() {
        if (selectedItems.length === 0) {
            alert(\'Please select at least one item\');
            return;
        }
        
        const permissionLevel = $(\'#bulk_permission_level\').val();
        const applyRecursively = $(\'#apply_recursive\').is(\':checked\') ? \'true\' : \'false\';
        
        // Use the recursive endpoint if the recursive option is checked
        const action = applyRecursively === \'true\' ? 
            \'cubbit_update_permissions_recursive\' : 
            \'cubbit_update_permissions\';
        
        $.ajax({
            url: cubbitData.ajaxUrl,
            method: \'POST\',
            data: {
                action: action,
                _wpnonce: cubbitData.directoryNonce,
                items: selectedItems,
                permission_level: permissionLevel,
                recursive: applyRecursively
            },
            dataType: \'json\',
            beforeSend: function() {
                $(\'#apply_bulk_permissions\').prop(\'disabled\', true).text(\'Applying...\');
                $(\'#permission_update_status\').html(\'<p><span class="spinner is-active"></span> Updating permissions...</p>\');
            },
            success: function(response) {
                if (response.success) {
                    $(\'#permission_update_status\').html(\'<div class="notice notice-success"><p>\' + response.data.message + \'</p></div>\');
                    loadDirectory(currentPath);
                } else {
                    $(\'#permission_update_status\').html(\'<div class="notice notice-error"><p>Error: \' + response.data + \'</p></div>\');
                }
            },
            error: function(xhr, status, error) {
                $(\'#permission_update_status\').html(\'<div class="notice notice-error"><p>Failed to update permissions. Please try again.</p></div>\');
                console.error(\'AJAX Error:\', xhr.responseText);
            },
            complete: function() {
                $(\'#apply_bulk_permissions\').prop(\'disabled\', false).text(\'Apply Permissions\');
                setTimeout(function() {
                    $(\'#permission_update_status\').empty();
                }, 5000);
            }
        });
    };
    
    // Function to show/hide the recursive option based on selection
    function updateRecursiveOption() {
        let hasDirectories = false;
        
        $(\'.item-checkbox:checked\').each(function() {
            const path = $(this).data(\'path\');
            const type = $(this).data(\'type\');
            
            if (type === \'directory\' || path.endsWith(\'/\')) {
                hasDirectories = true;
                return false; // Break the loop once we find a directory
            }
        });
        
        if (hasDirectories) {
            $(\'.recursive-option\').show();
        } else {
            $(\'.recursive-option\').hide();
            $(\'#apply_recursive\').prop(\'checked\', false);
        }
    }
    
    // Add the recursive option to the permissions panel if it doesn\'t exist
    if ($(\'.recursive-option\').length === 0) {
        const recursiveOptionHtml = \'<div class="recursive-option" style="margin-top: 10px; display: none;">\' +
            \'<label>\' +
            \'<input type="checkbox" id="apply_recursive"> \' +
            \'Apply recursively to folder contents\' +
            \'</label>\' +
            \'<p class="description">This will apply the permission to all files and subfolders.</p>\' +
            \'</div>\';
            
        $(\'.bulk-permissions-control select\').after(recursiveOptionHtml);
    }
});';
        file_put_contents($js_dir . '/cubbit-extension.js', $js_content);
    }
}
