<?php
/*
Plugin Name: Cubbit Authenticated Bulk Downloader
Plugin URI: https://algorithmpress.com
Description: Download multiple files (including private files) from Cubbit S3 storage
Version: 1.0
Author: WPWakanda, LLC
Author URI: https://yourwebsite.com/
*/

if (!defined('ABSPATH')) {
    exit;
}

class CubbitAuthenticatedDownloader {
    private $endpoint = 'https://s3.cubbit.eu';
    private $region = 'eu-central-1';
    private $log_file;
    private $temp_dir;

    public function __construct() {
        // Make sure to load this plugin after the main plugin
        add_action('plugins_loaded', [$this, 'init'], 20);
        
        // Create a log file in the WordPress uploads directory
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/cubbit-auth-downloader.log';
        $this->temp_dir = $upload_dir['basedir'] . '/cubbit-temp';
        
        // Create temp directory if it doesn't exist
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
            
            // Create .htaccess to prevent direct access
            file_put_contents($this->temp_dir . '/.htaccess', 'deny from all');
        }
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
        
        // Register AJAX handlers
        add_action('wp_ajax_cubbit_auth_download', [$this, 'ajax_auth_download']);
        add_action('wp_ajax_cubbit_get_download_file', [$this, 'ajax_get_download_file']);
        add_action('wp_ajax_cubbit_check_zip_status', [$this, 'ajax_check_zip_status']);
        
        // Add scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets'], 20);
        
        // Add cleanup for temporary files
        add_action('wp_scheduled_delete', [$this, 'cleanup_temp_files']);
    }

    /**
     * Add admin notice if main plugin is not active
     */
    public function plugin_dependency_notice() {
        ?>
        <div class="notice notice-error">
            <p>Cubbit Authenticated Downloader requires the Cubbit Directory Manager plugin to be active. Please activate it first.</p>
        </div>
        <?php
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'cubbit-directory') === false) {
            return;
        }

        wp_enqueue_style(
            'cubbit-auth-downloader-styles', 
            plugin_dir_url(__FILE__) . 'css/auth-downloader.css', 
            [], 
            '1.0'
        );
        
        wp_enqueue_script(
            'cubbit-auth-downloader-script', 
            plugin_dir_url(__FILE__) . 'js/auth-downloader.js', 
            ['jquery', 'cubbit-directory-script'], 
            '1.0', 
            true
        );
        
        // Pass data to JS
        wp_localize_script('cubbit-auth-downloader-script', 'cubbitAuthData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cubbit-auth-download-nonce')
        ]);
    }
    
    /**
     * AJAX handler for authenticated download
     */
    public function ajax_auth_download() {
        check_ajax_referer('cubbit-auth-download-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        $items = isset($_POST['items']) ? array_map('sanitize_text_field', $_POST['items']) : [];
        
        if (empty($items)) {
            wp_send_json_error('No items selected for download');
            return;
        }
        
        // Create a unique download ID
        $download_id = uniqid('cubbit_auth_');
        
        // Create a subfolder for this download
        $download_path = $this->temp_dir . '/' . $download_id;
        wp_mkdir_p($download_path);
        
        // Store info about the download job
        $download_job = [
            'id' => $download_id,
            'items' => $items,
            'path' => $download_path,
            'started' => time(),
            'status' => 'initializing',
            'progress' => 0,
            'total_items' => count($items),
            'processed_items' => 0,
            'successful_items' => 0,
            'failed_items' => 0,
            'errors' => [],
            'files' => []
        ];
        
        // Store job in a transient
        set_transient('cubbit_download_job_' . $download_id, $download_job, 12 * HOUR_IN_SECONDS);
        
        // Return the download ID immediately
        wp_send_json_success([
            'download_id' => $download_id,
            'total_items' => count($items)
        ]);
        
        // Then process the download in the background
        $this->process_download_job($download_id);
        exit;
    }
    
    /**
     * AJAX handler for checking ZIP status
     */
    public function ajax_check_zip_status() {
        check_ajax_referer('cubbit-auth-download-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        $download_id = isset($_POST['download_id']) ? sanitize_text_field($_POST['download_id']) : '';
        
        if (empty($download_id)) {
            wp_send_json_error('Invalid download ID');
            return;
        }
        
        // Get the download job
        $download_job = get_transient('cubbit_download_job_' . $download_id);
        
        if (empty($download_job)) {
            wp_send_json_error('Download job not found or expired');
            return;
        }
        
        // Check if the job is complete
        if ($download_job['status'] === 'completed') {
            $zip_file = $download_job['zip_file'] ?? '';
            $zip_url = $this->get_download_url($download_id);
            
            wp_send_json_success([
                'status' => 'completed',
                'download_url' => $zip_url,
                'filename' => basename($zip_file),
                'successful_items' => $download_job['successful_items'],
                'failed_items' => $download_job['failed_items'],
                'errors' => $download_job['errors']
            ]);
        } elseif ($download_job['status'] === 'failed') {
            wp_send_json_error([
                'status' => 'failed',
                'message' => 'Download job failed',
                'errors' => $download_job['errors']
            ]);
        } else {
            // Job is still in progress
            wp_send_json_success([
                'status' => $download_job['status'],
                'progress' => $download_job['progress'],
                'processed_items' => $download_job['processed_items'],
                'total_items' => $download_job['total_items']
            ]);
        }
    }
    
    /**
     * AJAX handler for getting download file
     */
    public function ajax_get_download_file() {
        // Check for download token
        $download_id = isset($_GET['download_id']) ? sanitize_text_field($_GET['download_id']) : '';
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        
        if (empty($download_id) || empty($token)) {
            wp_die('Invalid download request');
        }
        
        // Verify token
        $expected_token = get_transient('cubbit_download_token_' . $download_id);
        
        if ($token !== $expected_token) {
            wp_die('Invalid download token');
        }
        
        // Get the download job
        $download_job = get_transient('cubbit_download_job_' . $download_id);
        
        if (empty($download_job) || $download_job['status'] !== 'completed') {
            wp_die('Download not ready or expired');
        }
        
        $zip_file = $download_job['zip_file'] ?? '';
        
        if (empty($zip_file) || !file_exists($zip_file)) {
            wp_die('Download file not found');
        }
        
        // Set headers for download
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zip_file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($zip_file));
        
        // Clear output buffer
        ob_clean();
        flush();
        
        // Output file
        readfile($zip_file);
        
        // Delete the token
        delete_transient('cubbit_download_token_' . $download_id);
        
        exit;
    }
    
    /**
     * Process a download job
     */
    private function process_download_job($download_id) {
        // Get the download job
        $download_job = get_transient('cubbit_download_job_' . $download_id);
        
        if (empty($download_job)) {
            $this->log_error("Download job not found: {$download_id}");
            return;
        }
        
        $items = $download_job['items'];
        $download_path = $download_job['path'];
        
        // Get bucket info
        $bucket_name = get_option('cubbit_bucket_name');
        
        if (empty($bucket_name)) {
            $download_job['status'] = 'failed';
            $download_job['errors'][] = 'Cubbit configuration is missing';
            set_transient('cubbit_download_job_' . $download_id, $download_job, 12 * HOUR_IN_SECONDS);
            $this->log_error("Bucket name missing for job: {$download_id}");
            return;
        }
        
        // Update job status
        $download_job['status'] = 'downloading';
        set_transient('cubbit_download_job_' . $download_id, $download_job, 12 * HOUR_IN_SECONDS);
        
        $total_items = count($items);
        $processed_items = 0;
        $successful_items = 0;
        $failed_items = 0;
        $errors = [];
        $files = [];
        
        // Process each item
        foreach ($items as $item) {
            // Check if it's a directory
            if (substr($item, -1) === '/') {
                // List all objects in this directory
                $objects = $this->list_objects_recursive($bucket_name, $item);
                
                if (empty($objects)) {
                    $processed_items++;
                    $failed_items++;
                    $errors[] = "Failed to list contents of directory: {$item}";
                    continue;
                }
                
                // Download each object in the directory
                foreach ($objects as $object) {
                    // Skip directory markers
                    if (substr($object, -1) === '/') {
                        continue;
                    }
                    
                    $result = $this->download_file($bucket_name, $object, $download_path, $item);
                    
                    if ($result['success']) {
                        $successful_items++;
                        $files[] = $result['path'];
                    } else {
                        $failed_items++;
                        $errors[] = $result['message'];
                    }
                    
                    $processed_items++;
                    
                    // Update progress
                    $progress = ($processed_items / $total_items) * 100;
                    $download_job['progress'] = min(95, $progress); // Cap at 95% until zip is done
                    $download_job['processed_items'] = $processed_items;
                    $download_job['successful_items'] = $successful_items;
                    $download_job['failed_items'] = $failed_items;
                    $download_job['errors'] = $errors;
                    $download_job['files'] = $files;
                    
                    set_transient('cubbit_download_job_' . $download_id, $download_job, 12 * HOUR_IN_SECONDS);
                }
            } else {
                // Download individual file
                $result = $this->download_file($bucket_name, $item, $download_path);
                
                if ($result['success']) {
                    $successful_items++;
                    $files[] = $result['path'];
                } else {
                    $failed_items++;
                    $errors[] = $result['message'];
                }
                
                $processed_items++;
                
                // Update progress
                $progress = ($processed_items / $total_items) * 100;
                $download_job['progress'] = min(95, $progress); // Cap at 95% until zip is done
                $download_job['processed_items'] = $processed_items;
                $download_job['successful_items'] = $successful_items;
                $download_job['failed_items'] = $failed_items;
                $download_job['errors'] = $errors;
                $download_job['files'] = $files;
                
                set_transient('cubbit_download_job_' . $download_id, $download_job, 12 * HOUR_IN_SECONDS);
            }
        }
        
        // All files downloaded, create zip
        $download_job['status'] = 'creating_zip';
        set_transient('cubbit_download_job_' . $download_id, $download_job, 12 * HOUR_IN_SECONDS);
        
        if ($successful_items > 0) {
            $zip_result = $this->create_zip_archive($download_path, $download_id);
            
            if ($zip_result['success']) {
                // Create download token
                $token = wp_generate_password(32, false);
                set_transient('cubbit_download_token_' . $download_id, $token, 12 * HOUR_IN_SECONDS);
                
                // Update job as completed
                $download_job['status'] = 'completed';
                $download_job['progress'] = 100;
                $download_job['zip_file'] = $zip_result['path'];
                $download_job['download_token'] = $token;
                $download_job['completed'] = time();
                
                set_transient('cubbit_download_job_' . $download_id, $download_job, 12 * HOUR_IN_SECONDS);
                
                // Schedule cleanup
                wp_schedule_single_event(time() + 12 * HOUR_IN_SECONDS, 'wp_scheduled_delete', ['cubbit_cleanup_' . $download_id]);
                
                $this->log_error("Download job completed: {$download_id}");
            } else {
                $download_job['status'] = 'failed';
                $download_job['errors'][] = 'Failed to create ZIP archive: ' . $zip_result['message'];
                
                set_transient('cubbit_download_job_' . $download_id, $download_job, 12 * HOUR_IN_SECONDS);
                
                $this->log_error("Failed to create ZIP for job: {$download_id}");
            }
        } else {
            $download_job['status'] = 'failed';
            $download_job['errors'][] = 'No files were successfully downloaded';
            
            set_transient('cubbit_download_job_' . $download_id, $download_job, 12 * HOUR_IN_SECONDS);
            
            $this->log_error("No files downloaded for job: {$download_id}");
        }
    }
    
    /**
     * Download a file from Cubbit S3 with authentication
     */
    private function download_file($bucket, $key, $download_path, $base_folder = '') {
        $this->log_error("Downloading file: {$key}");
        
        // Get Cubbit credentials
        $access_key = get_option('cubbit_access_key');
        $secret_key = get_option('cubbit_secret_key');
        
        if (empty($access_key) || empty($secret_key)) {
            return [
                'success' => false,
                'message' => "Missing Cubbit credentials for {$key}"
            ];
        }
        
        // Determine the local file path
        $local_path = $key;
        
        // If this is part of a folder download, maintain subfolder structure
        if (!empty($base_folder)) {
            // Remove the base folder prefix to get the relative path
            if (strpos($key, $base_folder) === 0) {
                $local_path = substr($key, strlen($base_folder));
            }
        }
        
        // Ensure the path doesn't start with a slash
        $local_path = ltrim($local_path, '/');
        
        // Create local path with proper directory structure
        $local_file = $download_path . '/' . $local_path;
        $local_dir = dirname($local_file);
        
        // Create directory structure if it doesn't exist
        if (!file_exists($local_dir)) {
            wp_mkdir_p($local_dir);
        }
        
        // Format date for AWS requirement
        $amz_date = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        // Properly URL encode the path components while preserving slashes
        $path_parts = explode('/', $key);
        $encoded_parts = array_map('rawurlencode', $path_parts);
        $encoded_key = implode('/', $encoded_parts);
        
        // Create headers
        $headers = [
            'Host' => 's3.cubbit.eu',
            'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'x-amz-date' => $amz_date
        ];
        
        // Format canonical headers
        ksort($headers);
        $canonical_headers = '';
        $signed_headers = '';
        
        foreach ($headers as $header_key => $value) {
            $canonical_headers .= strtolower($header_key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($header_key) . ';';
        }
        
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $canonical_request = "GET\n" .
                           "/{$bucket}/{$encoded_key}\n" .
                           "\n" . // Empty query string
                           $canonical_headers . "\n" .
                           $signed_headers . "\n" .
                           "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        
        // Create string to sign
        $algorithm = "AWS4-HMAC-SHA256";
        $credential_scope = "{$datestamp}/{$this->region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$amz_date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $signing_key = $this->derive_signature_key($secret_key, $datestamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        
        // Create authorization header
        $authorization_header = "{$algorithm} " .
                              "Credential={$access_key}/{$credential_scope}, " .
                              "SignedHeaders={$signed_headers}, " .
                              "Signature={$signature}";
        
        // Set up cURL request
        $url = "{$this->endpoint}/{$bucket}/{$encoded_key}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Prepare headers for cURL
        $curl_headers = [];
        foreach ($headers as $header_key => $value) {
            $curl_headers[] = "{$header_key}: {$value}";
        }
        $curl_headers[] = "Authorization: {$authorization_header}";
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
        
        // Execute request
        $file_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        
        // Check for errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log_error("cURL Error downloading {$key}: {$error}");
            curl_close($ch);
            return [
                'success' => false,
                'message' => "Error downloading {$key}: {$error}"
            ];
        }
        
        curl_close($ch);
        
        if ($http_code !== 200) {
            $this->log_error("Failed to download {$key}. HTTP Code: {$http_code}");
            return [
                'success' => false,
                'message' => "Failed to download {$key}. HTTP Code: {$http_code}"
            ];
        }
        
        // Check if the response is XML (error)
        if (strpos($content_type, 'application/xml') !== false && strpos($file_content, '<?xml') === 0) {
            // This is likely an error response
            $this->log_error("Error response for {$key}: {$file_content}");
            return [
                'success' => false,
                'message' => "Error downloading {$key}: " . $this->extract_error_message($file_content)
            ];
        }
        
        // Save file content
        $result = file_put_contents($local_file, $file_content);
        
        if ($result === false) {
            $this->log_error("Failed to save {$key} to {$local_file}");
            return [
                'success' => false,
                'message' => "Failed to save {$key} to local file"
            ];
        }
        
        $this->log_error("Successfully downloaded {$key} to {$local_file}");
        
        // Return success with file details
        return [
            'success' => true,
            'message' => "Successfully downloaded {$key}",
            'path' => $local_file,
            'key' => $key
        ];
    }
    
    /**
     * Create a ZIP archive of downloaded files
     */
    private function create_zip_archive($download_path, $download_id) {
        $this->log_error("Creating ZIP archive for {$download_id}");
        
        // Create ZIP filename
        $zip_filename = 'cubbit-files-' . date('Y-m-d-His') . '.zip';
        $zip_path = $this->temp_dir . '/' . $zip_filename;
        
        // Create new ZIP archive
        $zip = new ZipArchive();
        
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->log_error("Failed to create ZIP archive at {$zip_path}");
            return [
                'success' => false,
                'message' => "Failed to create ZIP archive"
            ];
        }
        
        // Get all files in the download directory
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($download_path),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $file_count = 0;
        
        foreach ($files as $name => $file) {
            // Skip directories and special files
            if (!$file->isDir() && !in_array($file->getBasename(), ['.', '..', '.htaccess'])) {
                // Get real and relative path for current file
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($download_path) + 1);
                
                // Add current file to archive
                if ($zip->addFile($file_path, $relative_path)) {
                    $file_count++;
                } else {
                    $this->log_error("Failed to add {$file_path} to ZIP");
                }
            }
        }
        
        // Close the ZIP file
        if (!$zip->close()) {
            $this->log_error("Failed to finalize ZIP archive");
            return [
                'success' => false,
                'message' => "Failed to finalize ZIP archive"
            ];
        }
        
        if ($file_count === 0) {
            $this->log_error("No files were added to the ZIP archive");
            return [
                'success' => false,
                'message' => "No files were added to the ZIP archive"
            ];
        }
        
        $this->log_error("Successfully created ZIP archive with {$file_count} files");
        
        return [
            'success' => true,
            'message' => "Successfully created ZIP archive with {$file_count} files",
            'path' => $zip_path,
            'filename' => $zip_filename,
            'file_count' => $file_count
        ];
    }
    
    /**
     * Get download URL for a completed job
     */
    private function get_download_url($download_id) {
        $download_job = get_transient('cubbit_download_job_' . $download_id);
        
        if (empty($download_job) || $download_job['status'] !== 'completed') {
            return '';
        }
        
        $token = $download_job['download_token'] ?? '';
        
        if (empty($token)) {
            return '';
        }
        
        return admin_url('admin-ajax.php?action=cubbit_get_download_file&download_id=' . $download_id . '&token=' . $token);
    }
    
    /**
     * Extract error message from XML response
     */
    private function extract_error_message($xml_content) {
        try {
            $xml = simplexml_load_string($xml_content);
            if ($xml && isset($xml->Message)) {
                return (string)$xml->Message;
            }
        } catch (Exception $e) {
            // Ignore parsing errors
        }
        
        return 'Unknown error occurred';
    }
    
    /**
     * Recursively list all objects in a path
     */
    private function list_objects_recursive($bucket, $prefix) {
        $all_objects = [];
        $access_key = get_option('cubbit_access_key');
        $secret_key = get_option('cubbit_secret_key');
        
        if (empty($access_key) || empty($secret_key)) {
            $this->log_error("Missing Cubbit credentials for recursive listing");
            return $all_objects;
        }
        
        try {
            // Normalize prefix to ensure it ends with a slash if it's a folder
            if (!empty($prefix) && substr($prefix, -1) !== '/') {
                $prefix .= '/';
            }
            
            // Prepare S3 request
            $amz_date = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            
            $query_params = [
                'list-type' => '2',
                'prefix' => $prefix
            ];
            ksort($query_params);
            $canonical_query_string = http_build_query($query_params);
            
            // Create canonical headers
            $canonical_headers = implode("\n", [
                "host:s3.cubbit.eu",
                "x-amz-content-sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
                "x-amz-date:{$amz_date}"
            ]);
            
            $signed_headers = "host;x-amz-content-sha256;x-amz-date";
            
            // Create canonical request
            $canonical_request = implode("\n", [
                "GET",
                "/{$bucket}/",
                $canonical_query_string,
                $canonical_headers . "\n",
                $signed_headers,
                "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
            ]);
            
            // Create string to sign
            $algorithm = "AWS4-HMAC-SHA256";
            $credential_scope = "{$datestamp}/{$this->region}/s3/aws4_request";
            $string_to_sign = implode("\n", [
                $algorithm,
                $amz_date,
                $credential_scope,
                hash('sha256', $canonical_request)
            ]);
            
            // Calculate signature
            $signing_key = $this->derive_signature_key($secret_key, $datestamp, $this->region, 's3');
            $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
            
            // Create authorization header
            $authorization_header = implode(", ", [
                "{$algorithm} Credential={$access_key}/{$credential_scope}",
                "SignedHeaders={$signed_headers}",
                "Signature={$signature}"
            ]);
            
            // Prepare cURL request
            $url = "{$this->endpoint}/{$bucket}/?" . $canonical_query_string;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Host: s3.cubbit.eu",
                "x-amz-content-sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
                "x-amz-date: {$amz_date}",
                "Authorization: {$authorization_header}"
            ]);
            
            // Execute request
            $result = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Handle response
            if ($http_code !== 200) {
                $this->log_error("Failed to list objects recursively. HTTP Code: {$http_code}. Response: {$result}");
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
        $access_key = get_option('cubbit_access_key');
        $secret_key = get_option('cubbit_secret_key');
        
        try {
            // Prepare S3 request
            $amz_date = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            
            $query_params = [
                'list-type' => '2',
                'prefix' => $prefix,
                'continuation-token' => $continuation_token
            ];
            ksort($query_params);
            $canonical_query_string = http_build_query($query_params);
            
            // Create canonical headers
            $canonical_headers = implode("\n", [
                "host:s3.cubbit.eu",
                "x-amz-content-sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
                "x-amz-date:{$amz_date}"
            ]);
            
            $signed_headers = "host;x-amz-content-sha256;x-amz-date";
            
            // Create canonical request
            $canonical_request = implode("\n", [
                "GET",
                "/{$bucket}/",
                $canonical_query_string,
                $canonical_headers . "\n",
                $signed_headers,
                "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
            ]);
            
            // Create string to sign
            $algorithm = "AWS4-HMAC-SHA256";
            $credential_scope = "{$datestamp}/{$this->region}/s3/aws4_request";
            $string_to_sign = implode("\n", [
                $algorithm,
                $amz_date,
                $credential_scope,
                hash('sha256', $canonical_request)
            ]);
            
            // Calculate signature
            $signing_key = $this->derive_signature_key($secret_key, $datestamp, $this->region, 's3');
            $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
            
            // Create authorization header
            $authorization_header = implode(", ", [
                "{$algorithm} Credential={$access_key}/{$credential_scope}",
                "SignedHeaders={$signed_headers}",
                "Signature={$signature}"
            ]);
            
            // Prepare cURL request
            $url = "{$this->endpoint}/{$bucket}/?" . $canonical_query_string;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Host: s3.cubbit.eu",
                "x-amz-content-sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
                "x-amz-date: {$amz_date}",
                "Authorization: {$authorization_header}"
            ]);
            
            // Execute request
            $result = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Handle response
            if ($http_code !== 200) {
                $this->log_error("Failed to list continuation objects. HTTP Code: {$http_code}");
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
     * Cleanup temporary files
     */
    public function cleanup_temp_files($download_id = null) {
        // If download_id starts with 'cubbit_cleanup_', extract the actual ID
        if (is_string($download_id) && strpos($download_id, 'cubbit_cleanup_') === 0) {
            $download_id = substr($download_id, strlen('cubbit_cleanup_'));
        }
        
        if (!empty($download_id)) {
            // Clean up specific download folder
            $download_job = get_transient('cubbit_download_job_' . $download_id);
            
            if (!empty($download_job)) {
                $download_path = $download_job['path'];
                $this->delete_directory($download_path);
                
                // Delete ZIP file
                if (isset($download_job['zip_file']) && file_exists($download_job['zip_file'])) {
                    unlink($download_job['zip_file']);
                }
                
                // Delete transients
                delete_transient('cubbit_download_job_' . $download_id);
                delete_transient('cubbit_download_token_' . $download_id);
                
                $this->log_error("Cleaned up download job: {$download_id}");
            }
        } else {
            // Clean up all downloads older than 1 day
            $folders = glob($this->temp_dir . '/*', GLOB_ONLYDIR);
            $yesterday = time() - (24 * 60 * 60);
            
            foreach ($folders as $folder) {
                if (is_dir($folder)) {
                    $modified_time = filemtime($folder);
                    if ($modified_time < $yesterday) {
                        $this->delete_directory($folder);
                        $this->log_error("Cleaned up old download folder: " . basename($folder));
                    }
                }
            }
            
            // Clean up old ZIP files
            $zip_files = glob($this->temp_dir . '/*.zip');
            foreach ($zip_files as $zip_file) {
                $modified_time = filemtime($zip_file);
                if ($modified_time < $yesterday) {
                    unlink($zip_file);
                    $this->log_error("Cleaned up old ZIP file: " . basename($zip_file));
                }
            }
        }
    }
    
    /**
     * Delete a directory and all its contents
     */
    private function delete_directory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            if (!$this->delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        
        return rmdir($dir);
    }
    
    /**
     * Generate signing key for AWS Signature V4
     */
    private function derive_signature_key($key, $date, $region, $service) {
        $k_date = hash_hmac('sha256', $date, "AWS4" . $key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        return $k_signing;
    }
    
    /**
     * Log error messages
     */
    private function log_error($message) {
        error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", 3, $this->log_file);
    }
}

// Initialize plugin
new CubbitAuthenticatedDownloader();

// Register activation hook
register_activation_hook(__FILE__, 'cubbit_auth_downloader_activate');

// Activation function
function cubbit_auth_downloader_activate() {
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
    if (!file_exists($css_dir . '/auth-downloader.css')) {
        $css_content = '/* Cubbit Authenticated Downloader Styles */
.auth-download-button {
    margin-left: 10px !important;
}

.auth-download-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    display: flex;
    justify-content: center;
    align-items: center;
}

.auth-download-modal-content {
    background-color: #fff;
    padding: 20px;
    border-radius: 5px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.auth-download-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
    margin-bottom: 15px;
}

.auth-download-modal-close {
    cursor: pointer;
    font-size: 20px;
    font-weight: bold;
}

.auth-download-progress {
    margin: 15px 0;
}

.auth-download-progress-bar {
    height: 20px;
    background-color: #f3f3f3;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 5px;
}

.auth-download-progress-inner {
    height: 100%;
    background-color: #0073aa;
    width: 0;
    transition: width 0.3s ease;
}

.auth-download-progress-text {
    margin-top: 5px;
    text-align: center;
}

.auth-download-link {
    display: block;
    margin: 15px 0;
    padding: 10px;
    background-color: #f9f9f9;
    border-radius: 3px;
    text-align: center;
}

.auth-download-errors {
    margin-top: 15px;
    padding: 10px;
    background-color: #ffebee;
    border-radius: 3px;
    border-left: 4px solid #f44336;
}

.auth-download-errors h4 {
    margin-top: 0;
    color: #c62828;
}

.auth-download-errors ul {
    margin-bottom: 0;
}

.auth-download-note {
    background-color: #e8f5e9;
    border-left: 4px solid #43a047;
    padding: 10px;
    margin-top: 15px;
    border-radius: 3px;
}';
        file_put_contents($css_dir . '/auth-downloader.css', $css_content);
    }
    
    // Create JS file if it doesn't exist
    if (!file_exists($js_dir . '/auth-downloader.js')) {
        $js_content = '/**
 * Cubbit Authenticated Downloader
 * JavaScript for handling authenticated file downloads
 */
jQuery(document).ready(function($) {
    // Check if the original plugin\'s JS is loaded
    if (typeof cubbitData === \'undefined\') {
        console.error(\'Cubbit Directory Manager script not loaded\');
        return;
    }
    
    // Add download button to toolbar
    $(\'.cubbit-toolbar\').append(
        \'<button id="auth_download_btn" class="button auth-download-button" disabled>\' +
        \'<span class="dashicons dashicons-download"></span> Secure Download</button>\'
    );
    
    // Create download modal
    $(\'body\').append(
        \'<div id="auth_download_modal" class="auth-download-modal" style="display: none;">\' +
        \'  <div class="auth-download-modal-content">\' +
        \'    <div class="auth-download-modal-header">\' +
        \'      <h3>Authenticated Download</h3>\' +
        \'      <span class="auth-download-modal-close">&times;</span>\' +
        \'    </div>\' +
        \'    <div id="auth_download_content">\' +
        \'      <p>Processing <span id="file_count">0</span> item(s) for download...</p>\' +
        \'      <div class="auth-download-note">\' +
        \'        <p><strong>Note:</strong> This process downloads files using your stored Cubbit credentials, allowing access to private files.</p>\' +
        \'      </div>\' +
        \'      <div class="auth-download-progress">\' +
        \'        <div class="auth-download-progress-bar">\' +
        \'          <div class="auth-download-progress-inner"></div>\' +
        \'        </div>\' +
        \'        <div class="auth-download-progress-text">Initializing...</div>\' +
        \'      </div>\' +
        \'      <div id="auth_download_link_container"></div>\' +
        \'      <div id="auth_download_errors_container"></div>\' +
        \'    </div>\' +
        \'  </div>\' +
        \'</div>\'
    );
    
    // Listen for checkbox changes to update download button state
    $(document).on(\'change\', \'.item-checkbox\', function() {
        updateDownloadButton();
    });
    
    // Function to update download button state
    function updateDownloadButton() {
        if (typeof selectedItems !== \'undefined\' && selectedItems.length > 0) {
            $(\'#auth_download_btn\').prop(\'disabled\', false);
        } else {
            $(\'#auth_download_btn\').prop(\'disabled\', true);
        }
    }
    
    // Handle download button click
    $(\'#auth_download_btn\').on(\'click\', function() {
        if (typeof selectedItems === \'undefined\' || selectedItems.length === 0) {
            alert(\'Please select at least one item to download\');
            return;
        }
        
        // Show modal
        $(\'#auth_download_modal\').show();
        $(\'#file_count\').text(selectedItems.length);
        $(\'#auth_download_link_container\').empty();
        $(\'#auth_download_errors_container\').empty();
        $(\'.auth-download-progress-inner\').css(\'width\', \'5%\');
        $(\'.auth-download-progress-text\').text(\'Starting download process...\');
        
        // Start download process
        startAuthDownload();
    });
    
    // Close modal when clicking X or outside the modal
    $(\'.auth-download-modal-close\').on(\'click\', function() {
        $(\'#auth_download_modal\').hide();
    });
    
    $(\'#auth_download_modal\').on(\'click\', function(e) {
        if ($(e.target).hasClass(\'auth-download-modal\')) {
            $(\'#auth_download_modal\').hide();
        }
    });
    
    // Function to start authenticated download
    function startAuthDownload() {
        $.ajax({
            url: cubbitAuthData.ajaxUrl,
            method: \'POST\',
            data: {
                action: \'cubbit_auth_download\',
                nonce: cubbitAuthData.nonce,
                items: selectedItems
            },
            dataType: \'json\',
            success: function(response) {
                if (response.success) {
                    var downloadId = response.data.download_id;
                    var totalItems = response.data.total_items;
                    
                    // Start polling for progress
                    $(\'.auth-download-progress-text\').text(\'Starting download process...\');
                    pollDownloadProgress(downloadId);
                } else {
                    $(\'.auth-download-progress-inner\').css(\'width\', \'100%\');
                    $(\'.auth-download-progress-text\').text(\'Download failed\');
                    
                    // Show error message
                    $(\'#auth_download_link_container\').html(
                        \'<div class="notice notice-error"><p>Failed to start download: \' + 
                        (response.data ? response.data : \'Unknown error\') + \'</p></div>\'
                    );
                }
            },
            error: function(xhr, status, error) {
                $(\'.auth-download-progress-inner\').css(\'width\', \'100%\');
                $(\'.auth-download-progress-text\').text(\'Download failed\');
                
                // Show error message
                $(\'#auth_download_link_container\').html(
                    \'<div class="notice notice-error"><p>An error occurred while starting the download.</p></div>\'
                );
                
                console.error(\'AJAX Error:\', xhr.responseText);
            }
        });
    }
    
    // Function to poll download progress
    function pollDownloadProgress(downloadId) {
        $.ajax({
            url: cubbitAuthData.ajaxUrl,
            method: \'POST\',
            data: {
                action: \'cubbit_check_zip_status\',
                nonce: cubbitAuthData.nonce,
                download_id: downloadId
            },
            dataType: \'json\',
            success: function(response) {
                if (response.success) {
                    var status = response.data.status;
                    var progress = response.data.progress || 0;
                    
                    // Update progress bar
                    $(\'.auth-download-progress-inner\').css(\'width\', progress + \'%\');
                    
                    // Update progress text based on status
                    switch (status) {
                        case \'initializing\':
                            $(\'.auth-download-progress-text\').text(\'Initializing download...\');
                            break;
                        case \'downloading\':
                            var processed = response.data.processed_items || 0;
                            var total = response.data.total_items || 1;
                            $(\'.auth-download-progress-text\').text(
                                \'Downloading files: \' + processed + \' of \' + total + \' (\' + Math.round(progress) + \'%)\'
                            );
                            break;
                        case \'creating_zip\':
                            $(\'.auth-download-progress-text\').text(\'Creating ZIP archive...\');
                            break;
                        case \'completed\':
                            $(\'.auth-download-progress-inner\').css(\'width\', \'100%\');
                            $(\'.auth-download-progress-text\').text(\'Download ready!\');
                            
                            // Show download link
                            $(\'#auth_download_link_container\').html(
                                \'<a href="\' + response.data.download_url + \'" class="button button-primary auth-download-link">Download ZIP</a>\'
                            );
                            
                            // Show any errors
                            if (response.data.errors && response.data.errors.length > 0) {
                                showDownloadErrors(response.data.errors);
                            }
                            
                            // Add success message
                            $(\'#auth_download_link_container\').append(
                                \'<div class="notice notice-success"><p>Successfully downloaded \' + 
                                response.data.successful_items + \' files. \' +
                                (response.data.failed_items > 0 ? response.data.failed_items + \' files failed.\' : \'\') +
                                \'</p></div>\'
                            );
                            
                            // Auto-download after a short delay
                            setTimeout(function() {
                                window.location.href = response.data.download_url;
                            }, 1000);
                            
                            return; // Stop polling
                        case \'failed\':
                            $(\'.auth-download-progress-inner\').css(\'width\', \'100%\');
                            $(\'.auth-download-progress-text\').text(\'Download failed\');
                            
                            // Show error message
                            $(\'#auth_download_link_container\').html(
                                \'<div class="notice notice-error"><p>Download failed: \' + 
                                (response.data.message || \'Unknown error\') + \'</p></div>\'
                            );
                            
                            // Show detailed errors
                            if (response.data.errors && response.data.errors.length > 0) {
                                showDownloadErrors(response.data.errors);
                            }
                            
                            return; // Stop polling
                    }
                    
                    // Continue polling if not completed or failed
                    setTimeout(function() {
                        pollDownloadProgress(downloadId);
                    }, 2000); // Poll every 2 seconds
                } else {
                    $(\'.auth-download-progress-inner\').css(\'width\', \'100%\');
                    $(\'.auth-download-progress-text\').text(\'Download failed\');
                    
                    // Show error message
                    $(\'#auth_download_link_container\').html(
                        \'<div class="notice notice-error"><p>Failed to check download status: \' + 
                        (response.data ? response.data : \'Unknown error\') + \'</p></div>\'
                    );
                }
            },
            error: function(xhr, status, error) {
                $(\'.auth-download-progress-inner\').css(\'width\', \'100%\');
                $(\'.auth-download-progress-text\').text(\'Download failed\');
                
                // Show error message
                $(\'#auth_download_link_container\').html(
                    \'<div class="notice notice-error"><p>An error occurred while checking download status.</p></div>\'
                );
                
                console.error(\'AJAX Error:\', xhr.responseText);
            }
        });
    }
    
    // Function to display download errors
    function showDownloadErrors(errors) {
        if (!errors || errors.length === 0) {
            return;
        }
        
        let errorHtml = \'<div class="auth-download-errors">\' +
                      \'<h4>Some files could not be downloaded:</h4>\' +
                      \'<ul>\';
        
        errors.forEach(function(error) {
            errorHtml += \'<li>\' + error + \'</li>\';
        });
        
        errorHtml += \'</ul></div>\';
        
        $(\'#auth_download_errors_container\').html(errorHtml);
    }
    
    // Initialize button state
    updateDownloadButton();
});';
        file_put_contents($js_dir . '/auth-downloader.js', $js_content);
    }
    
    // Create temp directory for downloads
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/cubbit-temp';
    
    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
        
        // Create .htaccess to prevent direct access
        file_put_contents($temp_dir . '/.htaccess', 'deny from all');
    }
    
// Add this function to your plugin's PHP file
function enqueue_direct_download_script() {
    // Only add to the Cubbit Directory Manager pages
    $screen = get_current_screen();
    if (strpos($screen->id, 'cubbit-directory') !== false) {
        wp_enqueue_script(
            'cubbit-direct-download', 
            plugin_dir_url(__FILE__) . 'js/direct-download.js', 
            array('jquery'), 
            '1.0', 
            true
        );
    }
}
add_action('admin_enqueue_scripts', 'enqueue_direct_download_script');
}
