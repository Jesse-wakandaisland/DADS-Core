<?php
/*
Plugin Name: New Auto Refresh API
Description: A plugin to fetch and display data via API with customizable settings directly from the WordPress post manager.
Version: 1.0
Author: WPWakanda, LLC
*/

// Prevent direct access
defined('ABSPATH') or die('No script kiddies please!');

// Include required files
include(plugin_dir_path(__FILE__) . 'includes/admin-settings.php');
include(plugin_dir_path(__FILE__) . 'includes/frontend-display.php');
include(plugin_dir_path(__FILE__) . 'includes/ajax-handling.php');

// Enqueue scripts and styles
function nara_enqueue_scripts() {
    wp_enqueue_script('nara-custom-script', plugin_dir_url(__FILE__) . 'js/custom-script.js', array('jquery'), null, true);
    wp_enqueue_style('nara-custom-style', plugin_dir_url(__FILE__) . 'css/custom-style.css');
}
add_action('wp_enqueue_scripts', 'nara_enqueue_scripts');
