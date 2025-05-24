<?php
add_action('wp_ajax_nara_get_data', 'nara_get_data');
add_action('wp_ajax_nopriv_nara_get_data', 'nara_get_data');

function nara_get_data() {
    $options = get_option('nara_settings');
    $endpoints = isset($options['nara_api_endpoints']) ? $options['nara_api_endpoints'] : [];

    if (empty($endpoints)) {
        wp_send_json_error('No endpoints configured');
    }

    $responses = [];

    foreach ($endpoints as $endpoint) {
        $api_url = esc_url($endpoint['url']);
        $response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            $responses[] = ['url' => $api_url, 'error' => $response->get_error_message()];
        } else {
            $responses[] = ['url' => $api_url, 'data' => wp_remote_retrieve_body($response)];
        }
    }

    wp_send_json_success($responses);
}
