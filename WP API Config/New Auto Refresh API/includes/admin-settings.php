<?php
add_action('admin_menu', 'nara_add_admin_menu');
add_action('admin_init', 'nara_settings_init');

function nara_add_admin_menu() {
    add_options_page(
        'New Auto Refresh API Settings',
        'Auto Refresh API',
        'manage_options',
        'new_auto_refresh_api',
        'nara_options_page'
    );
}

function nara_settings_init() {
    register_setting('naraPlugin', 'nara_settings');

    add_settings_section(
        'nara_naraPlugin_section',
        __('API Settings', 'wordpress'),
        'nara_settings_section_callback',
        'naraPlugin'
    );

    add_settings_field(
        'nara_api_endpoints',
        __('API Endpoints and Selectors', 'wordpress'),
        'nara_api_endpoints_render',
        'naraPlugin',
        'nara_naraPlugin_section'
    );
}

function nara_api_endpoints_render() {
    $options = get_option('nara_settings');
    $endpoints = isset($options['nara_api_endpoints']) ? $options['nara_api_endpoints'] : [];
    ?>
    <div id="nara-endpoints-container">
        <?php foreach ($endpoints as $index => $endpoint): ?>
            <div class="nara-endpoint">
                <input type="text" name="nara_settings[nara_api_endpoints][<?php echo $index; ?>][url]" value="<?php echo esc_attr($endpoint['url']); ?>" placeholder="API URL">
                <input type="text" name="nara_settings[nara_api_endpoints][<?php echo $index; ?>][selector]" value="<?php echo esc_attr($endpoint['selector']); ?>" placeholder="DOM Selector">
                <button type="button" class="remove-endpoint">Remove</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" id="add-endpoint">Add Endpoint</button>
    <script>
    jQuery(document).ready(function($) {
        $('#add-endpoint').on('click', function() {
            var index = $('#nara-endpoints-container .nara-endpoint').length;
            $('#nara-endpoints-container').append('<div class="nara-endpoint"><input type="text" name="nara_settings[nara_api_endpoints][' + index + '][url]" placeholder="API URL"><input type="text" name="nara_settings[nara_api_endpoints][' + index + '][selector]" placeholder="DOM Selector"><button type="button" class="remove-endpoint">Remove</button></div>');
        });

        $('#nara-endpoints-container').on('click', '.remove-endpoint', function() {
            $(this).closest('.nara-endpoint').remove();
        });
    });
    </script>
    <?php
}

function nara_settings_section_callback() {
    echo __('Configure multiple API URLs and DOM Selectors for auto-refresh functionality.', 'wordpress');
}

function nara_options_page() {
    ?>
    <form action='options.php' method='post'>
        <h2>New Auto Refresh API</h2>
        <?php
        settings_fields('naraPlugin');
        do_settings_sections('naraPlugin');
        submit_button();
        ?>
    </form>
    <?php
}
