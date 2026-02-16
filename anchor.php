<?php
/**
 * Plugin Name: Anchor
 * Plugin URI: https://stronganchortech.com
 * Description: Custom tools for managing Strong Anchor Tech's WordPress sites
 * Author: Strong Anchor Tech
 * Version: 1.1.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include the plugin update checker
require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use Duplicator\Utils\Email\EmailSummary;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/anchor-plugin', // GitHub repository URL
    __FILE__,                                         // Full path to the main plugin file
    'anchor-plugin'                                   // Plugin slug
);

// If the site has defined ANCHOR_GITHUB_TOKEN, use it to authenticate:
if ( defined( 'ANCHOR_GITHUB_TOKEN' ) && ANCHOR_GITHUB_TOKEN ) {
    $myUpdateChecker->setAuthentication( ANCHOR_GITHUB_TOKEN );
}

// Set the branch to "main"
$myUpdateChecker->setBranch( 'main' );

// -----------------------------------------------------------------------------
// ** Wordfence Scan Cron Staggering (with dedupe) **
// -----------------------------------------------------------------------------
// Goal: prevent many sites on the same server from running Wordfence scans at the same time.
// Works by detecting likely Wordfence scan cron hooks and rescheduling them to a deterministic,
// per-site time window (stable across requests) to spread load.
//
// Notes:
// - Wordfence free uses WP-Cron to schedule scans; hook names can vary by version.
// - We do best-effort hook detection (no hard dependency on one name).
// - We dedupe: after staggering, we ensure only ONE future event remains per scan hook.
// - If we can't detect a hook, we do nothing.

function anchor_wf_is_enabled() {
    // Default ON once deployed, but safe if Wordfence isn't installed (no-op).
    return get_option( 'anchor_wf_stagger_enabled', '1' ) === '1';
}

/**
 * Find likely Wordfence scan-related cron events.
 *
 * Returns array of:
 * [
 *   [
 *     'hook' => string,
 *     'timestamp' => int,
 *     'schedule' => string|null,
 *     'args' => array,
 *   ],
 *   ...
 * ]
 */
function anchor_wf_find_scan_cron_events() {
    $cron = _get_cron_array();
    if ( empty( $cron ) || ! is_array( $cron ) ) {
        return array();
    }

    $events = array();

    foreach ( $cron as $timestamp => $hooks ) {
        if ( ! is_array( $hooks ) ) {
            continue;
        }

        foreach ( $hooks as $hook => $instances ) {
            if ( ! is_array( $instances ) ) {
                continue;
            }

            $hook_lc = strtolower( (string) $hook );

            // Heuristic detection:
            // - Must mention wordfence or wf
            // - Prefer hooks that mention scan
            // This avoids catching unrelated WF cron tasks.
            $looks_like_wordfence = ( strpos( $hook_lc, 'wordfence' ) !== false || preg_match( '/\bwf\b/', $hook_lc ) );
            $looks_like_scan      = ( strpos( $hook_lc, 'scan' ) !== false );

            if ( ! $looks_like_wordfence || ! $looks_like_scan ) {
                continue;
            }

            foreach ( $instances as $sig => $data ) {
                $args     = isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array();
                $schedule = isset( $data['schedule'] ) && is_string( $data['schedule'] ) && $data['schedule'] !== '' ? $data['schedule'] : null;

                $events[] = array(
                    'hook'      => (string) $hook,
                    'timestamp' => (int) $timestamp,
                    'schedule'  => $schedule,
                    'args'      => $args,
                );
            }
        }
    }

    // Sort by next run time ascending
    usort( $events, function( $a, $b ) {
        return (int) $a['timestamp'] <=> (int) $b['timestamp'];
    } );

    return $events;
}

/**
 * Compute a deterministic target timestamp (server/site time) for "tomorrow at base + offset".
 * We spread sites across a 6-hour window to reduce herd effects.
 */
function anchor_wf_compute_target_timestamp() {
    // Base local time for scans: 03:00 tomorrow (server/PHP timezone).
    // Offset: 0..359 minutes (6 hours).
    $home = home_url();
    $hash = sprintf( '%u', crc32( $home ) ); // unsigned
    $offset_minutes = (int) ( $hash % 360 );

    $base = strtotime( 'tomorrow 03:00' );
    if ( ! $base ) {
        $base = time() + DAY_IN_SECONDS;
    }

    return (int) ( $base + ( $offset_minutes * 60 ) );
}

/**
 * Reschedule detected Wordfence scan cron events to the per-site stagger time.
 * Dedupe: after this runs, there should be only ONE future event per detected scan hook.
 */
function anchor_wf_stagger_scan_cron( $force = false ) {
    if ( ! anchor_wf_is_enabled() ) {
        return;
    }

    $events = anchor_wf_find_scan_cron_events();
    if ( empty( $events ) ) {
        return;
    }

    // Avoid rescheduling on every request. Do it at most once every ~20 hours unless forced.
    $last = (int) get_option( 'anchor_wf_stagger_last_reschedule', 0 );
    if ( ! $force && $last > 0 && ( time() - $last ) < ( 20 * HOUR_IN_SECONDS ) ) {
        return;
    }

    $target = anchor_wf_compute_target_timestamp();

    // If target already passed today due to clock oddities, push to next day.
    if ( $target <= time() + 60 ) {
        $target = (int) ( $target + DAY_IN_SECONDS );
    }

    // Group by hook. Keep args from the earliest event for that hook.
    $by_hook = array();
    foreach ( $events as $e ) {
        $hook = $e['hook'];
        if ( ! isset( $by_hook[ $hook ] ) ) {
            $by_hook[ $hook ] = array(
                'keep_args'     => $e['args'],
                'keep_schedule' => $e['schedule'], // usually null (single)
            );
        }
    }

    foreach ( $by_hook as $hook => $data ) {
        // Remove ALL future instances of this hook (any args).
        while ( $t = wp_next_scheduled( $hook ) ) {
            wp_unschedule_event( $t, $hook ); // removes one instance; loop clears all
        }

        // Schedule exactly one scan-start event at the target time.
        $args = isset( $data['keep_args'] ) && is_array( $data['keep_args'] ) ? $data['keep_args'] : array();

        // Most Wordfence scan-start hooks are single events. Keep it single to match observed behavior.
        wp_schedule_single_event( $target, $hook, $args );
    }

    update_option( 'anchor_wf_stagger_last_reschedule', time(), false );
}

/**
 * Provide admin-readable info: detected WF scan cron events (if any) + target.
 */
function anchor_wf_get_next_scan_event_info() {
    $events = anchor_wf_find_scan_cron_events();

    return array(
        'found'  => ! empty( $events ),
        'events' => $events,
        'target' => anchor_wf_compute_target_timestamp(),
    );
}

// Attempt a daily best-effort reschedule (lightweight). Runs on init.
add_action( 'init', function() {
    anchor_wf_stagger_scan_cron( false );
}, 1 );

// -----------------------------------------------------------------------------
// ** Admin Menu & Page **
// -----------------------------------------------------------------------------

// Add the "Anchor" top-level menu
function anchor_add_admin_page() {
    add_menu_page(
        'Anchor',                 // Page title
        'Anchor',                 // Menu title
        'manage_options',         // Capability
        'anchor-plugin',          // Menu slug
        'anchor_admin_page',      // Callback function
        'dashicons-admin-site-alt3' // Icon (closest available to a sea anchor)
    );
}
add_action( 'admin_menu', 'anchor_add_admin_page' );

// Admin page content with buttons for various tools, including disabling pingbacks/trackbacks
function anchor_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>Anchor Admin Tools</h1>';

    // Ensure nonces for form security
    $nonce_action = 'anchor_admin_actions';
    $nonce_name   = 'anchor_admin_nonce';

    // Style buttons to sit inline with spacing
    echo '<style>
        .anchor-button-wrapper form {
            display: inline-block;
            margin-right: 20px;
            margin-bottom: 12px;
        }
        .anchor-section {
            margin-top: 24px;
            padding: 16px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        .anchor-section h2 {
            margin-top: 0;
        }
        .anchor-kv {
            margin: 8px 0;
        }
        .anchor-kv code {
            font-size: 12px;
        }
    </style>';

    echo '<div class="anchor-button-wrapper">';

    // 1) Permalink flush button
    echo '<form method="post" action="">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="flush_permalinks">';
    echo '<input type="submit" class="button button-primary" value="Flush Permalinks Now">';
    echo '</form>';

    // 2) Error reporting toggle button
    $error_reporting_enabled = get_option( 'anchor_error_reporting_enabled' ) === '1';
    $error_label            = $error_reporting_enabled
        ? 'Disable Error Reporting for Admins'
        : 'Enable Error Reporting for Admins';
    echo '<form method="post" action="">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="toggle_error_reporting">';
    echo '<input type="submit" class="button button-primary" value="' . esc_attr( $error_label ) . '">';
    echo '</form>';

    // 3) Disable Pingbacks & Trackbacks button
    $pings_disabled = get_option( 'anchor_disable_pings_enabled' ) === '1';
    $pings_label    = $pings_disabled
        ? 'Pingbacks/Trackbacks Already Disabled'
        : 'Disable Pingbacks & Trackbacks';
    // If already disabled, disable the button
    $button_attr = $pings_disabled ? 'disabled' : '';
    echo '<form method="post" action="">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="disable_pings">';
    echo '<input type="submit" class="button button-primary" value="' . esc_attr( $pings_label ) . '" ' . $button_attr . '>';
    echo '</form>';

    echo '</div>'; // End button wrapper

    // Handle submitted actions
    if ( isset( $_POST['anchor_action'] ) && check_admin_referer( $nonce_action, $nonce_name ) ) {
        switch ( sanitize_text_field( $_POST['anchor_action'] ) ) {

            case 'flush_permalinks':
                anchor_flush_permalinks();
                break;

            case 'toggle_error_reporting':
                $new_status = $error_reporting_enabled ? '0' : '1';
                update_option( 'anchor_error_reporting_enabled', $new_status );
                echo '<div class="notice notice-success"><p>Error reporting has been '
                     . ( $new_status === '1' ? 'enabled' : 'disabled' )
                     . ' for admins.</p></div>';
                break;

            case 'disable_pings':
                if ( ! $pings_disabled ) {
                    update_option( 'anchor_disable_pings_enabled', '1' );
                    echo '<div class="notice notice-success"><p>Pingbacks and trackbacks have been disabled site-wide.</p></div>';
                    // Optionally, also disable for existing posts by updating options
                    update_option( 'default_ping_status', 'closed' );
                    update_option( 'default_pingback_flag', '0' );
                }
                break;

            case 'toggle_wf_stagger':
                $enabled = anchor_wf_is_enabled();
                update_option( 'anchor_wf_stagger_enabled', $enabled ? '0' : '1', false );
                echo '<div class="notice notice-success"><p>Wordfence scan staggering has been '
                     . ( $enabled ? 'disabled' : 'enabled' )
                     . '.</p></div>';
                break;

            case 'wf_reschedule_now':
                anchor_wf_stagger_scan_cron( true );
                echo '<div class="notice notice-success"><p>Wordfence scan staggering was applied now (best-effort).</p></div>';
                break;
        }
    }

    // Wordfence Stagger Info Section
    $wf_enabled = anchor_wf_is_enabled();
    $info       = anchor_wf_get_next_scan_event_info();
    $target_ts  = (int) $info['target'];

    echo '<div class="anchor-section">';
    echo '<h2>Wordfence Scan Cron Staggering</h2>';

    echo '<div class="anchor-kv"><strong>Status:</strong> ' . ( $wf_enabled ? '<span style="color:#1e8e3e;">Enabled</span>' : '<span style="color:#b32d2e;">Disabled</span>' ) . '</div>';

    echo '<form method="post" action="" style="display:inline-block; margin-right: 12px;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="toggle_wf_stagger">';
    echo '<input type="submit" class="button button-secondary" value="' . esc_attr( $wf_enabled ? 'Disable Staggering' : 'Enable Staggering' ) . '">';
    echo '</form>';

    echo '<form method="post" action="" style="display:inline-block;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="wf_reschedule_now">';
    echo '<input type="submit" class="button button-secondary" value="Apply Staggering Now">';
    echo '</form>';

    // Display target time
    $target_str = function_exists( 'wp_date' )
        ? wp_date( 'Y-m-d H:i:s T', $target_ts )
        : date_i18n( 'Y-m-d H:i:s T', $target_ts );

    echo '<div class="anchor-kv"><strong>Target scan time (this site):</strong> ' . esc_html( $target_str ) . '</div>';

    // Display detected WF scan cron hook(s)
    if ( empty( $info['events'] ) ) {
        echo '<p><em>No Wordfence scan cron hooks detected on this site (or Wordfence not installed / hook name not matched).</em></p>';
    } else {
        echo '<p><strong>Detected scan-related cron events:</strong></p>';
        echo '<table class="widefat striped" style="max-width: 1100px;">';
        echo '<thead><tr><th>Hook</th><th>Next Run</th><th>Schedule</th><th>Args</th></tr></thead>';
        echo '<tbody>';

        // Show up to 10 rows
        $shown = 0;
        foreach ( $info['events'] as $e ) {
            if ( $shown >= 10 ) {
                break;
            }
            $shown++;

            $next_str = function_exists( 'wp_date' )
                ? wp_date( 'Y-m-d H:i:s T', (int) $e['timestamp'] )
                : date_i18n( 'Y-m-d H:i:s T', (int) $e['timestamp'] );

            echo '<tr>';
            echo '<td><code>' . esc_html( $e['hook'] ) . '</code></td>';
            echo '<td>' . esc_html( $next_str ) . '</td>';
            echo '<td>' . esc_html( $e['schedule'] ? $e['schedule'] : 'single' ) . '</td>';
            echo '<td><code>' . esc_html( wp_json_encode( $e['args'] ) ) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        if ( count( $info['events'] ) > 10 ) {
            echo '<p><em>Showing first 10 detected events.</em></p>';
        }

        echo '<p style="max-width: 1100px;"><em>After staggering runs, there should be only one scheduled scan-start event per hook. If you still see duplicates, click “Apply Staggering Now” once.</em></p>';
    }

    $last = (int) get_option( 'anchor_wf_stagger_last_reschedule', 0 );
    if ( $last > 0 ) {
        $last_str = function_exists( 'wp_date' )
            ? wp_date( 'Y-m-d H:i:s T', $last )
            : date_i18n( 'Y-m-d H:i:s T', $last );
        echo '<div class="anchor-kv"><strong>Last reschedule attempt:</strong> ' . esc_html( $last_str ) . '</div>';
    }

    echo '<p style="max-width: 1100px;"><em>Notes:</em> This is a best-effort rescheduler. Wordfence hook names vary by version; if no hooks are detected here, this feature will not change anything on this site.</p>';
    echo '</div>'; // anchor-section

    echo '</div>';
}

// Function to flush permalinks and show a success notice
function anchor_flush_permalinks() {
    flush_rewrite_rules( true );
    echo '<div class="notice notice-success"><p>Permalinks have been flushed successfully.</p></div>';
}

// -----------------------------------------------------------------------------
// ** Error Reporting Control **
// -----------------------------------------------------------------------------

// Dynamically control PHP error reporting based on the admin setting
function anchor_set_error_reporting() {
    if ( get_option( 'anchor_error_reporting_enabled' ) === '1' ) {
        if ( current_user_can( 'administrator' ) && is_user_logged_in() ) {
            // Show all PHP errors to admins
            error_reporting( E_ALL );
            @ini_set( 'display_errors', 1 );
        } else {
            // Hide errors for non-admins or guests
            error_reporting( 0 );
            @ini_set( 'display_errors', 0 );
        }
    } else {
        // If the feature is “off,” hide errors universally
        error_reporting( 0 );
        @ini_set( 'display_errors', 0 );
    }
}
add_action( 'init', 'anchor_set_error_reporting' );

// -----------------------------------------------------------------------------
// ** Pingbacks & Trackbacks Disabling **
// -----------------------------------------------------------------------------

// On every page load, if the “disable pings” option is set, apply filters to block them.
function anchor_disable_pings_apply() {
    if ( get_option( 'anchor_disable_pings_enabled' ) === '1' ) {

        // 1) Force new posts to have pingbacks/trackbacks off
        add_filter( 'pre_option_default_pingback_flag', '__return_zero' );
        add_filter( 'pre_option_default_ping_status', '__return_zero' );

        // 2) Disable XML-RPC pingback.ping method so remote sites cannot send pingbacks
        add_filter( 'xmlrpc_methods', function( $methods ) {
            if ( isset( $methods['pingback.ping'] ) ) {
                unset( $methods['pingback.ping'] );
            }
            return $methods;
        });

        // 3) Prevent self-pings (WP sending a trackback to itself)
        add_action( 'pre_ping', function( &$links ) {
            $home_url = get_option( 'home' );
            foreach ( $links as $l => $link ) {
                if ( 0 === strpos( $link, $home_url ) ) {
                    unset( $links[ $l ] );
                }
            }
        });

        // 4) Remove the “X-Pingback” HTTP header so bots can’t easily find the XML-RPC endpoint
        add_filter( 'wp_headers', function( $headers ) {
            if ( isset( $headers['X-Pingback'] ) ) {
                unset( $headers['X-Pingback'] );
            }
            return $headers;
        });

        // 5) Disable XML-RPC entirely to prevent any pingback calls
        add_filter( 'xmlrpc_enabled', '__return_false' );
    }
}
add_action( 'init', 'anchor_disable_pings_apply' );

/**
 * Force‐disable Duplicator Pro email summaries:
 *  - Overrides any submitted frequency to "never"
 *  - Clears the scheduled cron
 *  - Persists "never" via the Global Entity (SnapIO)
 */
function anchor_force_disable_duplicator_summaries() {
    // Bail if Duplicator Pro isn’t loaded or the classes aren’t available
    if (
        ! defined( 'DUPLICATOR_PRO_VERSION' )
        || ! class_exists( EmailSummary::class )
        || ! class_exists( 'DUP_PRO_Global_Entity' )
    ) {
        return;
    }

    // 1) If the Settings page is submitting a frequency, force it to 'never'
    if ( isset( $_REQUEST['_email_summary_frequency'] ) ) {
        $_REQUEST['_email_summary_frequency'] = EmailSummary::SEND_FREQ_NEVER;
        $_POST   ['_email_summary_frequency'] = EmailSummary::SEND_FREQ_NEVER;
    }

    // 2) Unschedule any pending summary email
    wp_clear_scheduled_hook( 'duplicator_weekly_summary' );

    // 3) Programmatically set + save "never" in Duplicator Pro’s Global Entity
    /** @var \DUP_PRO_Global_Entity $global */
    $global = \DUP_PRO_Global_Entity::getInstance();
    $global->setEmailSummaryFrequency( EmailSummary::SEND_FREQ_NEVER );
    $global->save();
}
add_action( 'admin_init', 'anchor_force_disable_duplicator_summaries', 1 );

// -----------------------------------------------------------------------------
// ** Activation & Deactivation Hooks **
// -----------------------------------------------------------------------------

/**
 * On plugin activation: clear cron and force “never” for Duplicator Pro summaries.
 */
function anchor_activate() {
    // Flush permalinks
    flush_rewrite_rules( true );

    // Disable any Duplicator Pro weekly summary immediately
    anchor_force_disable_duplicator_summaries();

    // Default our own features off
    update_option( 'anchor_error_reporting_enabled', '0' );
    update_option( 'anchor_disable_pings_enabled',   '0' );

    // Default Wordfence staggering ON (safe no-op if no Wordfence scan hooks found)
    if ( get_option( 'anchor_wf_stagger_enabled', null ) === null ) {
        update_option( 'anchor_wf_stagger_enabled', '1', false );
    }

    // Attempt immediate best-effort stagger (and dedupe)
    anchor_wf_stagger_scan_cron( true );
}
register_activation_hook( __FILE__, 'anchor_activate' );

/**
 * On Anchor plugin upgrade: rerun the disable routine so you don’t have to visit wp-admin.
 */
add_action( 'upgrader_process_complete', function( $upgrader, $hook_data ) {
    if (
        isset( $hook_data['action'], $hook_data['type'], $hook_data['plugins'] )
        && $hook_data['action']  === 'update'
        && $hook_data['type']    === 'plugin'
        && in_array( plugin_basename( __FILE__ ), (array) $hook_data['plugins'], true )
    ) {
        anchor_force_disable_duplicator_summaries();

        // Best-effort apply Wordfence staggering after plugin updates as well.
        anchor_wf_stagger_scan_cron( true );
    }
}, 10, 2 );

function anchor_deactivate() {
    // Flush permalinks again when deactivating
    flush_rewrite_rules( true );

    // Clean up our options
    delete_option( 'anchor_error_reporting_enabled' );
    delete_option( 'anchor_disable_pings_enabled' );

    // Intentionally keep WF staggering settings so reactivation preserves behavior.
}
register_deactivation_hook( __FILE__, 'anchor_deactivate' );
