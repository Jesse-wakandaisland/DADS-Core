<?php
/*
Plugin Name: Cubbit DS3 Folder Management
Description: Manages folder creation and file deletion in Cubbit DS3.
Version: 1.0
Author: WPWakanda LLC
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Add the endpoint for creating a folder
add_action('rest_api_init', function () {
    register_rest_route('cubbit-folder-management/v1', '/create-folder', array(
        'methods' => 'POST',
        'callback' => 'create_folder',
        'permission_callback' => '__return_true'
    ));
});

// Add the endpoint for deleting a file
add_action('rest_api_init', function () {
    register_rest_route('cubbit-folder-management/v1', '/delete-file', array(
        'methods' => 'POST',
        'callback' => 'delete_file',
        'permission_callback' => '__return_true'
    ));
});

function create_folder($request) {
    // Cubbit DS3 configuration
    $endpoint = 'https://s3.cubbit.eu';
    $accessKey = 'your-access-key';
    $secretKey = 'your-secret-key';
    $bucketName = 'your-bucket-name';

    // Get the folder name from the request
    $body = $request->get_json_params();
    $folderName = isset($body['folderName']) ? $body['folderName'] : null;

    if (!$folderName) {
        return new WP_Error('no_folder_name', 'No folder name was provided', array('status' => 400));
    }

    // Ensure the folder name ends with a slash
    if (substr($folderName, -1) !== '/') {
        $folderName .= '/';
    }

    // Generate the URL for folder creation
    $url = "{$endpoint}/{$bucketName}/{$folderName}";

    // Create the signature
    $date = gmdate('D, d M Y H:i:s T');
    $stringToSign = "PUT\n\n\n{$date}\n/{$bucketName}/{$folderName}";
    $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));

    // Set up cURL for folder creation
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: s3.cubbit.eu",
        "Date: {$date}",
        "Authorization: AWS {$accessKey}:{$signature}"
    ]);

    // Send the request
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Folder created successfully'
        ], 200);
    } else {
        return new WP_Error('folder_creation_failed', 'Error creating folder. HTTP Code: ' . $httpCode, array('status' => 500));
    }
}

function delete_file($request) {
    // Cubbit DS3 configuration
    $endpoint = 'https://s3.cubbit.eu';
    $accessKey = 'your-access-key';
    $secretKey = 'your-secret-key';
    $bucketName = 'your-bucket-name';

    // Get the file name from the request
    $body = $request->get_json_params();
    $fileName = isset($body['fileName']) ? $body['fileName'] : null;

    if (!$fileName) {
        return new WP_Error('no_file_name', 'No file name was provided', array('status' => 400));
    }

    // Generate the URL for file deletion
    $url = "{$endpoint}/{$bucketName}/{$fileName}";

    // Create the signature
    $date = gmdate('D, d M Y H:i:s T');
    $stringToSign = "DELETE\n\n\n{$date}\n/{$bucketName}/{$fileName}";
    $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));

    // Set up cURL for file deletion
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: s3.cubbit.eu",
        "Date: {$date}",
        "Authorization: AWS {$accessKey}:{$signature}"
    ]);

    // Send the request
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 204) {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'File deleted successfully'
        ], 200);
    } else {
        return new WP_Error('file_deletion_failed', 'Error deleting file. HTTP Code: ' . $httpCode, array('status' => 500));
    }
}
