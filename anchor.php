<?php
/**
 * Plugin Name: Anchor
 * Plugin URI: https://stronganchortech.com
 * Description: Custom tools for managing Strong Anchor Tech's WordPress sites
 * Author: Strong Anchor Tech
 * Version: 1.0.9
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
        'dashicons-admin-site-alt3'  // Icon (closest available to a sea anchor)
    );
}
add_action('admin_menu', 'anchor_add_admin_page');

// Admin page content with a button to flush permalinks and toggle admin error reporting
function anchor_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>Anchor Plugin Admin</h1>';
    
    // Style buttons to be on the same line with 20px spacing
    echo '<style>
        .anchor-button-wrapper form {
            display: inline-block;
            margin-right: 20px;
        }
    </style>';
    
    echo '<div class="anchor-button-wrapper">';
    
    // Permalink flush form
    echo '<form method="post" action="">';
    echo '<input type="submit" name="anchor_flush_permalinks" class="button button-primary" value="Flush Permalinks Now">';
    echo '</form>';

    // Error reporting toggle button
    $error_reporting_enabled = get_option('anchor_error_reporting_enabled') == '1';
    $button_label = $error_reporting_enabled ? 'Disable Error Reporting for Admins' : 'Enable Error Reporting for Admins';
    echo '<form method="post" action="">';
    echo '<input type="submit" name="anchor_toggle_error_reporting" class="button button-primary" value="' . $button_label . '">';
    echo '</form>';
    
    echo '</div>';  // End button wrapper

    // Handle permalink flush
    if (isset($_POST['anchor_flush_permalinks'])) {
        anchor_flush_permalinks();  // Trigger permalink flush
    }

    // Handle error reporting toggle
    if (isset($_POST['anchor_toggle_error_reporting'])) {
        $new_status = $error_reporting_enabled ? '0' : '1';
        update_option('anchor_error_reporting_enabled', $new_status);
        echo '<div class="notice notice-success"><p>Error reporting has been ' . ($new_status == '1' ? 'enabled' : 'disabled') . ' for admins.</p></div>';
    }

    echo '</div>';
}

// ** Dynamically control error reporting for admins (on both frontend and admin dashboard) **
function anchor_set_error_reporting() {
    if (get_option('anchor_error_reporting_enabled') == '1') {
        if (current_user_can('administrator') && is_user_logged_in()) {
            // Enable error reporting for admins (both frontend and dashboard)
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
