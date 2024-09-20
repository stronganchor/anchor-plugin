<?php
/**
 * Plugin Name: Anchor
 * Plugin URI: https://stronganchortech.com
 * Description: Custom tools for managing Strong Anchor Tech's WordPress sites
 * Author: Strong Anchor Tech
 * Version: 1.0.2
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
    __FILE__,                                                  // Full path to the main plugin file
    'anchor-plugin'                                   // Plugin slug
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

// Admin page content with a button to flush permalinks
function anchor_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>Anchor Plugin Admin</h1>';
    
    // Permalink flush form
    echo '<form method="post" action="">';
    echo '<input type="submit" name="anchor_flush_permalinks" class="button button-primary" value="Flush Permalinks Now">';
    echo '</form>';
    
    if (isset($_POST['anchor_flush_permalinks'])) {
        anchor_flush_permalinks();  // Trigger permalink flush
    }
    
    echo '</div>';
}
