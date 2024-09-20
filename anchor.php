<?php
/**
 * Plugin Name: Anchor
 * Plugin URI: https://stronganchortech.com
 * Description: Custom tools for managing Strong Anchor Tech's WordPress sites
 * Author: Strong Anchor Tech
 * Version: 1.0.5
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

// Admin page content with a button to flush permalinks and toggle WP_DEBUG
function anchor_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>Anchor Plugin Admin</h1>';
    
    // Permalink flush form
    echo '<form method="post" action="">';
    echo '<input type="submit" name="anchor_flush_permalinks" class="button button-primary" value="Flush Permalinks Now">';
    echo '</form>';

    // Debugging toggle form
    $debug_status = get_option('anchor_debug_enabled') == '1' ? 'checked' : '';
    echo '<form method="post" action="">';
    echo '<label for="anchor_toggle_debug">';
    echo '<input type="checkbox" name="anchor_toggle_debug" value="1" ' . $debug_status . '>';
    echo ' Enable WP_DEBUG';
    echo '</label>';
    echo '<br><input type="submit" name="anchor_save_debug" class="button button-primary" value="Save Debug Settings">';
    echo '</form>';

    // Handle permalink flush
    if (isset($_POST['anchor_flush_permalinks'])) {
        anchor_flush_permalinks();  // Trigger permalink flush
    }

    // Handle WP_DEBUG toggle
    if (isset($_POST['anchor_save_debug'])) {
        $debug_enabled = isset($_POST['anchor_toggle_debug']) ? '1' : '0';
        update_option('anchor_debug_enabled', $debug_enabled);
        echo '<div class="notice notice-success"><p>WP_DEBUG has been ' . ($debug_enabled == '1' ? 'enabled' : 'disabled') . '.</p></div>';
    }

    echo '</div>';
}

// ** Dynamically set WP_DEBUG based on option in the database **
function anchor_set_debug_mode() {
    if (get_option('anchor_debug_enabled') == '1') {
        define('WP_DEBUG', true);
        define('WP_DEBUG_LOG', true);
        define('WP_DEBUG_DISPLAY', false);  // Set false to avoid public display of errors
        
        // Show errors to admin users only
        if (current_user_can('administrator') && is_user_logged_in()) {
            @ini_set('display_errors', 1);
            define('WP_DEBUG_DISPLAY', true);
        } else {
            @ini_set('display_errors', 0);
        }
    } else {
        define('WP_DEBUG', false);
    }
}
add_action('init', 'anchor_set_debug_mode');

// ** Flush permalinks on plugin activation and deactivation **
function anchor_activate() {
    // Flush permalinks on activation
    flush_rewrite_rules(true);
    // Set the default for WP_DEBUG to disabled
    update_option('anchor_debug_enabled', '0');
}
register_activation_hook(__FILE__, 'anchor_activate');

function anchor_deactivate() {
    // Flush permalinks on deactivation
    flush_rewrite_rules(true);
    // Optionally remove the debug option if you want
    delete_option('anchor_debug_enabled');
}
register_deactivation_hook(__FILE__, 'anchor_deactivate');
