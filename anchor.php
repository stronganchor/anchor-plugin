<?php
/**
 * Plugin Name: Anchor
 * Plugin URI: https://stronganchortech.com
 * Description: Custom tools for managing Strong Anchor Tech's WordPress sites
 * Author: Strong Anchor Tech
 * Version: 1.0.4
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
    $debug_status = defined('WP_DEBUG') && WP_DEBUG ? 'checked' : '';
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
        anchor_toggle_debug(isset($_POST['anchor_toggle_debug']));
    }

    echo '</div>';
}

// Function to toggle WP_DEBUG in wp-config.php
function anchor_toggle_debug($enable_debug) {
    $wp_config_path = ABSPATH . 'wp-config.php';

    if (is_writable($wp_config_path)) {
        $config_file = file_get_contents($wp_config_path);

        // Ensure WP_DEBUG is defined and modify its value
        if (strpos($config_file, "define('WP_DEBUG'") !== false) {
            // Update existing WP_DEBUG definition
            $config_file = preg_replace(
                "/define\('WP_DEBUG', (true|false)\);/i",
                "define('WP_DEBUG', " . ($enable_debug ? 'true' : 'false') . ");",
                $config_file
            );
        } else {
            // Add WP_DEBUG definition if not found
            $config_file = str_replace("/* That's all, stop editing!", "define('WP_DEBUG', " . ($enable_debug ? 'true' : 'false') . ");\n/* That's all, stop editing!", $config_file);
        }

        // Write the modified config file back
        file_put_contents($wp_config_path, $config_file);

        echo '<div class="notice notice-success"><p>WP_DEBUG has been ' . ($enable_debug ? 'enabled' : 'disabled') . '.</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Error: Unable to write to wp-config.php.</p></div>';
    }
}

// ** Show errors only to logged-in admins **
function anchor_show_errors_to_admins() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (current_user_can('administrator') && is_user_logged_in()) {
            @ini_set('display_errors', 1);
            define('WP_DEBUG_DISPLAY', true);
        } else {
            @ini_set('display_errors', 0);
            define('WP_DEBUG_DISPLAY', false);
        }
    }
}
add_action('init', 'anchor_show_errors_to_admins');

// ** Flush permalinks on plugin activation and deactivation **
function anchor_activate() {
    // Flush permalinks on activation
    flush_rewrite_rules(true);
}
register_activation_hook(__FILE__, 'anchor_activate');

function anchor_deactivate() {
    // Flush permalinks on deactivation
    flush_rewrite_rules(true);
}
register_deactivation_hook(__FILE__, 'anchor_deactivate');
