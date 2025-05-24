<?php
/*
Plugin Name: NARA Data Integration
Description: A plugin to sync and manage NARA data via Custom Post Types (CPT).
Version: 1.2
Author: ConvoBuilder.com from WPWakanda, LLC
*/

// Prevent direct access
defined('ABSPATH') or die('No script kiddies please!');

// Register Custom Post Type for NARA Data
function nara_register_post_type() {
    $args = array(
        'public' => true,
        'label'  => 'NARA Data',
        'supports' => array('title', 'editor', 'custom-fields'),
        'menu_icon' => 'dashicons-admin-generic',
        'rewrite'   => array('slug' => 'nara-data'),
    );
    register_post_type('nara_data', $args);
}
add_action('init', 'nara_register_post_type');

// Save or Update Data as Custom Post Type
function nara_save_or_update_data_as_post($data) {
    $existing_posts = get_posts(array(
        'post_type'  => 'nara_data',
        'meta_key'   => 'nara_api_url',
        'meta_value' => $data['url'],
        'post_status'=> 'publish',
        'fields'     => 'ids'
    ));

    if (!empty($existing_posts)) {
        $post_id = $existing_posts[0];
        $post_data = array(
            'ID'          => $post_id,
            'post_title'  => sanitize_text_field($data['title']),
            'post_content'=> sanitize_text_field($data['content']),
        );
        wp_update_post($post_data);
        update_post_meta($post_id, 'nara_api_url', sanitize_text_field($data['url']));
        update_post_meta($post_id, 'nara_dom_selector', sanitize_text_field($data['selector']));
    } else {
        $post_data = array(
            'post_title'  => sanitize_text_field($data['title']),
            'post_content'=> sanitize_text_field($data['content']),
            'post_type'   => 'nara_data',
            'post_status' => 'publish',
        );
        $post_id = wp_insert_post($post_data);

        if ($post_id) {
            update_post_meta($post_id, 'nara_api_url', sanitize_text_field($data['url']));
            update_post_meta($post_id, 'nara_dom_selector', sanitize_text_field($data['selector']));
        }
    }
}

// Hook into the 'save_post' action to trigger synchronization
function nara_trigger_sync_on_save($post_id) {
    if (get_post_type($post_id) === 'nara_data' && !wp_is_post_revision($post_id)) {
        nara_sync_cpt_to_settings();
    }
}
add_action('save_post', 'nara_trigger_sync_on_save');

// Synchronize CPT data with NARA settings
function nara_sync_cpt_to_settings() {
    $args = array(
        'post_type' => 'nara_data',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    );

    $query = new WP_Query($args);
    $settings = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $url = get_post_meta(get_the_ID(), 'nara_api_url', true);
            $selector = get_post_meta(get_the_ID(), 'nara_dom_selector', true);

            $settings['nara_api_endpoints'][] = array(
                'url'     => $url,
                'selector'=> $selector,
            );
        }
        update_option('nara_settings', array('nara_api_endpoints' => $settings['nara_api_endpoints']));
    }

    wp_reset_postdata();
}

// Add custom cron schedule for every minute
function custom_cron_schedules($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display'  => __('Every Minute')
    );
    return $schedules;
}
add_filter('cron_schedules', 'custom_cron_schedules');

// Schedule the cron job if not already scheduled
if (!wp_next_scheduled('nara_sync_cron_event')) {
    wp_schedule_event(time(), 'every_minute', 'nara_sync_cron_event');
}

// Define the function to run for syncing from Google Sheets
function nara_sync_from_google_sheets() {
    // Update sync status to active
    update_option('nara_sync_status', 'active');
    
    $csv_url = '#';
    $response = wp_remote_get($csv_url);

    if (is_wp_error($response)) {
        error_log('Error fetching CSV: ' . $response->get_error_message());
        update_option('nara_sync_status', 'idle');
        return;
    }

    $csv_data = wp_remote_retrieve_body($response);
    if (empty($csv_data)) {
        error_log('No data received from CSV URL.');
        update_option('nara_sync_status', 'idle');
        return;
    }

    $lines = explode("\n", $csv_data);
    if (empty($lines)) {
        error_log('CSV data is empty.');
        update_option('nara_sync_status', 'idle');
        return;
    }

    foreach ($lines as $index => $line) {
        if ($index === 0) continue; // Skip header row
        $row = str_getcsv($line);

        if (count($row) >= 2) {
            $data = array(
                'title'   => sanitize_text_field($row[0]),
                'content' => 'Auto-generated content for ' . sanitize_text_field($row[0]),
                'url'     => sanitize_text_field($row[0]),
                'selector'=> sanitize_text_field(isset($row[1]) ? $row[1] : ''),
            );
            nara_save_or_update_data_as_post($data);
        }
    }

    // Update sync status to idle when done
    update_option('nara_sync_status', 'idle');
}

// Hook the sync function to the cron event
add_action('nara_sync_cron_event', 'nara_sync_from_google_sheets');

// Add sync button to admin bar
function nara_add_sync_button_to_admin_bar($wp_admin_bar) {
    $args = array(
        'id'    => 'nara_sync_status',
        'title' => '<span class="ab-icon dashicons dashicons-update"></span><span class="ab-label">NARA Sync</span>',
        'href'  => '#',
        'meta'  => array(
            'class' => 'nara-sync-idle',
            'title' => 'NARA Sync Status'
        )
    );
    $wp_admin_bar->add_node($args);
}
add_action('admin_bar_menu', 'nara_add_sync_button_to_admin_bar', 100);

// Add styles for the admin bar button
function nara_add_admin_bar_styles() {
    echo '
    <style>
        #wp-admin-bar-nara_sync_status .ab-icon {
            float: left;
            width: 20px;
            height: 30px;
            margin-right: 5px;
        }
        #wp-admin-bar-nara_sync_status.nara-sync-idle .ab-icon {
            color: #888;
        }
        #wp-admin-bar-nara_sync_status.nara-sync-active .ab-icon {
            color: #00a0d2;
            animation: nara-spin 2s linear infinite;
        }
        @keyframes nara-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>';
}
add_action('admin_head', 'nara_add_admin_bar_styles');
add_action('wp_head', 'nara_add_admin_bar_styles');

// AJAX handler to update sync status
function nara_update_sync_status() {
    $status = get_option('nara_sync_status', 'idle');
    wp_send_json_success(array('status' => $status));
}
add_action('wp_ajax_nara_update_sync_status', 'nara_update_sync_status');

// Add JavaScript to periodically check sync status
function nara_add_admin_bar_script() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        function updateSyncStatus() {
            $.ajax({
                url: ajaxurl,
                data: { action: 'nara_update_sync_status' },
                success: function(response) {
                    if (response.success) {
                        $('#wp-admin-bar-nara_sync_status')
                            .removeClass('nara-sync-idle nara-sync-active')
                            .addClass('nara-sync-' + response.data.status);
                    }
                }
            });
        }

        // Check status every 5 seconds
        setInterval(updateSyncStatus, 5000);
    });
    </script>
    <?php
}
add_action('admin_footer', 'nara_add_admin_bar_script');

// Activation hook
function nara_plugin_activate() {
    nara_register_post_type();
    nara_sync_from_google_sheets();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'nara_plugin_activate');

// Deactivation hook
function nara_clear_cron() {
    wp_clear_scheduled_hook('nara_sync_cron_event');
}
register_deactivation_hook(__FILE__, 'nara_clear_cron');
?>
