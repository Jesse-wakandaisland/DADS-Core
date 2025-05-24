<?php
add_action('wp_footer', 'nara_display_api_data');

function nara_display_api_data() {
    $options = get_option('nara_settings');
    $endpoints = isset($options['nara_api_endpoints']) ? $options['nara_api_endpoints'] : [];

    if (!empty($endpoints)) {
        echo "<script type='text/javascript'>
            var naraSettings = " . json_encode($endpoints) . ";
        </script>";
    }
}
