<?php
/**
 * Plugin Name: Anchor
 * Plugin URI: https://stronganchortech.com
 * Description: Custom tools for managing Strong Anchor Tech's WordPress sites
 * Author: Strong Anchor Tech
 * Version: 1.1.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Include the plugin update checker
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/anchor-plugin', 
    __FILE__,                                        
    'anchor-plugin'                                  
);

$myUpdateChecker->setBranch('main');

// Add admin menu page
add_action('admin_menu', function() {
    add_menu_page(
        'Anchor',   
        'Anchor',   
        'manage_options',  
        'anchor-plugin',   
        'anchor_admin_page',  
        'dashicons-admin-site-alt3'  
    );
});

// Admin page content with added regeneration and permalink buttons
function anchor_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>Anchor Admin Tools</h1>';
    
    // Inline CSS (preserved from original)
    echo '<style>
            .anchor-button-wrapper { display: inline-block; margin-right: 20px; }
            .anchor-page-header { margin-bottom: 20px; }
          </style>';

    echo '<div class="anchor-button-wrapper">';

    // Regenerate thumbnails button
    echo '<form method="post" action="">';
    echo '<input type="submit" name="anchor_regenerate_thumbnails" class="button button-primary" value="Regenerate Thumbnails">';
    echo '</form>';

    // Flush permalinks button
    echo '<form method="post" action="" style="margin-top: 10px;">';
    echo '<input type="submit" name="anchor_flush_permalinks" class="button button-secondary" value="Flush Permalinks">';
    echo '</form>';

    // Error reporting toggle button
    $error_reporting_enabled = get_option('anchor_error_reporting_enabled') == '1';
    $button_label = $error_reporting_enabled ? 'Disable Error Reporting for Admins' : 'Enable Error Reporting for Admins';
    echo '<form method="post" action="" style="margin-top: 10px;">';
    echo '<input type="submit" name="anchor_toggle_error_reporting" class="button button-secondary" value="' . $button_label . '">';
    echo '</form>';

    echo '</div>';

    // Handle regenerate thumbnails
    if (isset($_POST['anchor_regenerate_thumbnails'])) {
        anchor_regenerate_thumbnails();
    }

    // Handle flush permalinks
    if (isset($_POST['anchor_flush_permalinks'])) {
        flush_rewrite_rules(true);
        echo '<div class="notice notice-success is-dismissible">
                <p>Permalinks flushed successfully.</p>
              </div>';
    }

    // Handle error reporting toggle
    if (isset($_POST['anchor_toggle_error_reporting'])) {
        $new_status = $error_reporting_enabled ? '0' : '1';
        update_option('anchor_error_reporting_enabled', $new_status);
        echo '<div class="notice notice-success is-dismissible">
                <p>Error reporting has been ' . ($new_status == '1' ? 'enabled' : 'disabled') . ' for admins.</p>
              </div>';
    }

    echo '</div>';
}

// Regenerate thumbnails function
function anchor_regenerate_thumbnails() {
    if (defined('WP_CLI') && WP_CLI && current_user_can('manage_options')) {
        WP_CLI::runcommand('media regenerate --yes', ['exit_error' => false]);

        echo '<div class="notice notice-success is-dismissible">
                <p>All thumbnails regenerated successfully, including WebP if configured.</p>
              </div>';
    } else {
        echo '<div class="notice notice-error is-dismissible">
                <p>WP-CLI is not available or you don\'t have the necessary permissions.</p>
              </div>';
    }
}

// Set error reporting based on the admin toggle
add_action('init', function() {
    if (get_option('anchor_error_reporting_enabled') == '1') {
        if (current_user_can('administrator') && is_user_logged_in()) {
            error_reporting(E_ALL);
            @ini_set('display_errors', 1);
        } else {
            error_reporting(0);
            @ini_set('display_errors', 0);
        }
    } else {
        error_reporting(0);
        @ini_set('display_errors', 0);
    }
});

// Flush permalinks on activation and deactivation
register_activation_hook(__FILE__, function() {
    flush_rewrite_rules(true);
    update_option('anchor_error_reporting_enabled', '0');
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules(true);
    delete_option('anchor_error_reporting_enabled');
});
