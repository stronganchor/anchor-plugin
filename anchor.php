<?php
/**
 * Plugin Name: Anchor
 * Plugin URI: https://stronganchortech.com
 * Description: Custom tools for managing Strong Anchor Tech's WordPress sites
 * Author: Strong Anchor Tech
 * Version: 1.0.7
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Include the plugin update checker
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/anchor-plugin', // GitHub repository URL
    __FILE__,                                        // Full path to the main plugin file
    'anchor-plugin'                                  // Plugin slug
);

// Set the branch to "main"
$myUpdateChecker->setBranch('main');

// Optional: If you're using a private repository, specify the access token like this:
// $myUpdateChecker->setAuthentication('your-token-here');

// ** Permalink Flushing Functionality **

// Flush permalinks manually when requested
function anchor_flush_permalinks() {
    flush_rewrite_rules(true);
    echo '<div class="notice notice-success"><p>Permalinks have been flushed successfully.</p></div>';
}

// Add a button to flush permalinks manually from the admin area
function anchor_add_admin_page() {
    add_menu_page(
        'Anchor Plugin',   // Page title
        'Anchor Plugin',   // Menu title
        'manage_options',  // Capability
        'anchor-plugin',   // Menu slug
        'anchor_admin_page',  // Callback function
        'dashicons-admin-generic'  // Icon
    );
}
add_action('admin_menu', 'anchor_add_admin_page');

// Admin page content with a button to flush permalinks and toggle admin error reporting
function anchor_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>Anchor Plugin Admin</h1>';
    
    // Permalink flush form
    echo '<form method="post" action="">';
    echo '<input type="submit" name="anchor_flush_permalinks" class="button button-primary" value="Flush Permalinks Now">';
    echo '</form>';

    // Error reporting toggle form
    $error_reporting_status = get_option('anchor_error_reporting_enabled') == '1' ? 'checked' : '';
    echo '<form method="post" action="">';
    echo '<label for="anchor_toggle_error_reporting">';
    echo '<input type="checkbox" name="anchor_toggle_error_reporting" value="1" ' . $error_reporting_status . '>';
    echo ' Enable error reporting for administrators';
    echo '</label>';
    echo '<br><input type="submit" name="anchor_save_error_reporting" class="button button-primary" value="Save Error Reporting Settings">';
    echo '</form>';

    // Handle permalink flush
    if (isset($_POST['anchor_flush_permalinks'])) {
        anchor_flush_permalinks();  // Trigger permalink flush
    }

    // Handle error reporting toggle
    if (isset($_POST['anchor_save_error_reporting'])) {
        $error_reporting_enabled = isset($_POST['anchor_toggle_error_reporting']) ? '1' : '0';
        update_option('anchor_error_reporting_enabled', $error_reporting_enabled);
        echo '<div class="notice notice-success"><p>Error reporting has been ' . ($error_reporting_enabled == '1' ? 'enabled' : 'disabled') . ' for admins.</p></div>';
    }

    echo '</div>';
}

// ** Dynamically control error reporting for admins **
function anchor_set_error_reporting() {
    if (get_option('anchor_error_reporting_enabled') == '1') {
        if (current_user_can('administrator') && is_user_logged_in()) {
            // Enable error reporting for admins
            error_reporting(E_ALL);  // Report all PHP errors
            @ini_set('display_errors', 1);
        } else {
            // Disable error reporting for non-admins or non-logged-in users
            error_reporting(0);
            @ini_set('display_errors', 0);
        }
    } else {
        // Ensure no errors are shown if the setting is disabled
        error_reporting(0);
        @ini_set('display_errors', 0);
    }
}
add_action('init', 'anchor_set_error_reporting');

// ** Flush permalinks on plugin activation and deactivation **
function anchor_activate() {
    // Flush permalinks on activation
    flush_rewrite_rules(true);
    // Set the default for error reporting to disabled
    update_option('anchor_error_reporting_enabled', '0');
}
register_activation_hook(__FILE__, 'anchor_activate');

function anchor_deactivate() {
    // Flush permalinks on deactivation
    flush_rewrite_rules(true);
    // Optionally remove the error reporting option if you want
    delete_option('anchor_error_reporting_enabled');
}
register_deactivation_hook(__FILE__, 'anchor_deactivate');
