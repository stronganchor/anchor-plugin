<?php
/**
 * Anchor Cache Governor.
 *
 * Keeps known cache plugins from creating avoidable load spikes on small
 * shared PHP-FPM pools. Version 1 focuses on WP-Optimize safety controls.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const ANCHOR_CACHE_GOVERNOR_OPTION_ENABLED       = 'anchor_cache_governor_enabled';
const ANCHOR_CACHE_GOVERNOR_OPTION_LAST_ENFORCED = 'anchor_cache_governor_last_enforced';
const ANCHOR_CACHE_GOVERNOR_WPO_PRELOAD_HOOK     = 'wpo_page_cache_preload_continue';
const ANCHOR_CACHE_GOVERNOR_DEFAULT_TTL_DAYS     = 30;

function anchor_cache_governor_is_enabled() {
    return get_option( ANCHOR_CACHE_GOVERNOR_OPTION_ENABLED, '0' ) === '1';
}

function anchor_cache_governor_boolish( $value ) {
    if ( is_bool( $value ) ) {
        return $value;
    }

    if ( is_int( $value ) || is_float( $value ) ) {
        return (int) $value === 1;
    }

    if ( is_string( $value ) ) {
        return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
    }

    return false;
}

function anchor_cache_governor_is_wp_optimize_active() {
    $active_plugins = (array) get_option( 'active_plugins', array() );
    if ( in_array( 'wp-optimize/wp-optimize.php', $active_plugins, true ) ) {
        return true;
    }

    if ( is_multisite() ) {
        $network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
        if ( isset( $network_plugins['wp-optimize/wp-optimize.php'] ) ) {
            return true;
        }
    }

    return defined( 'WPO_VERSION' )
        || defined( 'WP_OPTIMIZE_VERSION' )
        || class_exists( 'WP_Optimize', false );
}

function anchor_cache_governor_get_wpo_config() {
    $config = get_option( 'wpo_cache_config', array() );

    return is_array( $config ) ? $config : array();
}

function anchor_cache_governor_count_cron_hook( $hook ) {
    $cron = _get_cron_array();
    if ( empty( $cron ) || ! is_array( $cron ) ) {
        return 0;
    }

    $count = 0;
    foreach ( $cron as $timestamp => $hooks ) {
        if ( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
            continue;
        }

        $count += count( $hooks[ $hook ] );
    }

    return $count;
}

function anchor_cache_governor_clear_wpo_preload_jobs() {
    $cleared = wp_clear_scheduled_hook( ANCHOR_CACHE_GOVERNOR_WPO_PRELOAD_HOOK );

    return is_int( $cleared ) ? $cleared : 0;
}

function anchor_cache_governor_get_ttl_days() {
    $days = defined( 'ANCHOR_CACHE_GOVERNOR_WPO_TTL_DAYS' )
        ? (int) ANCHOR_CACHE_GOVERNOR_WPO_TTL_DAYS
        : ANCHOR_CACHE_GOVERNOR_DEFAULT_TTL_DAYS;

    /**
     * Filters the conservative WP-Optimize cache lifespan used by Anchor.
     *
     * @param int $days Cache lifespan in days.
     */
    $days = (int) apply_filters( 'anchor_cache_governor_wpo_ttl_days', $days );

    return max( 1, min( 365, $days ) );
}

function anchor_cache_governor_apply_wpo_conservative_profile() {
    if ( ! anchor_cache_governor_is_wp_optimize_active() ) {
        return array(
            'ok'      => false,
            'message' => 'WP-Optimize is not active on this site.',
            'changed' => array(),
            'cleared' => 0,
        );
    }

    $config  = anchor_cache_governor_get_wpo_config();
    $before  = $config;
    $ttl_days = anchor_cache_governor_get_ttl_days();

    $config['auto_preload_purged_contents'] = '0';
    $config['enable_sitemap_preload']       = false;
    $config['enable_schedule_preload']      = '0';
    $config['preload_schedule_type']        = 'wpo_use_cache_lifespan';
    $config['enable_mobile_caching']        = '0';
    $config['page_cache_length_value']      = $ttl_days;
    $config['page_cache_length_unit']       = 'days';
    $config['page_cache_length']            = $ttl_days * DAY_IN_SECONDS;

    update_option( 'wpo_cache_config', $config, false );

    $changed = array();
    foreach ( $config as $key => $value ) {
        if ( ! array_key_exists( $key, $before ) || $before[ $key ] !== $value ) {
            $changed[] = $key;
        }
    }

    $cleared = anchor_cache_governor_clear_wpo_preload_jobs();

    update_option( ANCHOR_CACHE_GOVERNOR_OPTION_LAST_ENFORCED, time(), false );

    return array(
        'ok'      => true,
        'message' => sprintf(
            'Applied conservative WP-Optimize profile: preload disabled, separate mobile cache disabled, cache lifespan set to %d days.',
            $ttl_days
        ),
        'changed' => $changed,
        'cleared' => $cleared,
    );
}

function anchor_cache_governor_maybe_enforce_wpo_profile() {
    if ( ! anchor_cache_governor_is_enabled() ) {
        return;
    }

    if ( function_exists( 'wp_installing' ) && wp_installing() ) {
        return;
    }

    $last = (int) get_option( ANCHOR_CACHE_GOVERNOR_OPTION_LAST_ENFORCED, 0 );
    if ( $last > 0 && ( time() - $last ) < HOUR_IN_SECONDS ) {
        return;
    }

    anchor_cache_governor_apply_wpo_conservative_profile();
}
add_action( 'init', 'anchor_cache_governor_maybe_enforce_wpo_profile', 20 );

function anchor_cache_governor_get_diagnostics() {
    $config = anchor_cache_governor_get_wpo_config();

    $ttl_seconds = isset( $config['page_cache_length'] ) ? (int) $config['page_cache_length'] : 0;
    if ( $ttl_seconds <= 0 && isset( $config['page_cache_length_value'], $config['page_cache_length_unit'] ) ) {
        $value = max( 0, (int) $config['page_cache_length_value'] );
        $unit  = (string) $config['page_cache_length_unit'];
        if ( 'days' === $unit ) {
            $ttl_seconds = $value * DAY_IN_SECONDS;
        } elseif ( 'hours' === $unit ) {
            $ttl_seconds = $value * HOUR_IN_SECONDS;
        } elseif ( 'minutes' === $unit ) {
            $ttl_seconds = $value * MINUTE_IN_SECONDS;
        }
    }

    $diagnostics = array(
        'governor_enabled'    => anchor_cache_governor_is_enabled(),
        'wpo_active'          => anchor_cache_governor_is_wp_optimize_active(),
        'advanced_cache_file' => defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
        'page_cache_enabled'  => anchor_cache_governor_boolish( $config['enable_page_caching'] ?? false ),
        'ttl_seconds'         => $ttl_seconds,
        'ttl_label'           => isset( $config['page_cache_length_value'], $config['page_cache_length_unit'] )
            ? ( (string) $config['page_cache_length_value'] . ' ' . (string) $config['page_cache_length_unit'] )
            : ( $ttl_seconds > 0 ? human_time_diff( 0, $ttl_seconds ) : 'unknown' ),
        'preload_after_purge' => anchor_cache_governor_boolish( $config['auto_preload_purged_contents'] ?? false ),
        'sitemap_preload'     => anchor_cache_governor_boolish( $config['enable_sitemap_preload'] ?? false ),
        'scheduled_preload'   => anchor_cache_governor_boolish( $config['enable_schedule_preload'] ?? false ),
        'mobile_cache'        => anchor_cache_governor_boolish( $config['enable_mobile_caching'] ?? false ),
        'preload_cron_count'  => anchor_cache_governor_count_cron_hook( ANCHOR_CACHE_GOVERNOR_WPO_PRELOAD_HOOK ),
        'last_enforced'       => (int) get_option( ANCHOR_CACHE_GOVERNOR_OPTION_LAST_ENFORCED, 0 ),
    );

    $risks = array();

    if ( ! $diagnostics['wpo_active'] ) {
        $risks[] = 'WP-Optimize is not active; Anchor has nothing to govern.';
    } elseif ( ! $diagnostics['page_cache_enabled'] ) {
        $risks[] = 'WP-Optimize is active, but page caching does not appear to be enabled.';
    }

    if ( $diagnostics['preload_after_purge'] ) {
        $risks[] = 'Automatic preload after purge is enabled.';
    }

    if ( $diagnostics['sitemap_preload'] || $diagnostics['scheduled_preload'] ) {
        $risks[] = 'Scheduled or sitemap preloading is enabled.';
    }

    if ( $diagnostics['mobile_cache'] ) {
        $risks[] = 'Separate mobile cache is enabled, which doubles warm/preload work on many responsive sites.';
    }

    if ( $diagnostics['preload_cron_count'] > 0 ) {
        $risks[] = sprintf(
            '%d WP-Optimize preload continuation cron job(s) are queued.',
            $diagnostics['preload_cron_count']
        );
    }

    if ( $diagnostics['ttl_seconds'] > 0 && $diagnostics['ttl_seconds'] < WEEK_IN_SECONDS ) {
        $risks[] = 'Cache lifespan is shorter than one week.';
    }

    $diagnostics['risks'] = $risks;

    return $diagnostics;
}

function anchor_cache_governor_render_admin_section( $nonce_action, $nonce_name ) {
    $diagnostics = anchor_cache_governor_get_diagnostics();
    $disabled    = $diagnostics['wpo_active'] ? '' : ' disabled';
    $toggle_disabled = ( ! $diagnostics['wpo_active'] && ! $diagnostics['governor_enabled'] ) ? ' disabled' : '';

    echo '<div class="anchor-section">';
    echo '<h2>Cache Governor</h2>';

    echo '<div class="anchor-kv"><strong>Status:</strong> ' . ( $diagnostics['governor_enabled'] ? '<span style="color:#1e8e3e;">Enabled</span>' : '<span style="color:#b32d2e;">Disabled</span>' ) . '</div>';
    echo '<div class="anchor-kv"><strong>WP-Optimize detected:</strong> ' . ( $diagnostics['wpo_active'] ? 'Yes' : 'No' ) . '</div>';
    echo '<div class="anchor-kv"><strong>advanced-cache.php present:</strong> ' . ( $diagnostics['advanced_cache_file'] ? 'Yes' : 'No' ) . '</div>';

    echo '<form method="post" action="" style="display:inline-block; margin-right: 12px;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="toggle_cache_governor">';
    echo '<input type="submit" class="button button-secondary" value="' . esc_attr( $diagnostics['governor_enabled'] ? 'Disable Cache Governor' : 'Enable Cache Governor' ) . '"' . $toggle_disabled . '>';
    echo '</form>';

    echo '<form method="post" action="" style="display:inline-block; margin-right: 12px;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="cache_governor_apply_wpo_profile">';
    echo '<input type="submit" class="button button-secondary" value="Apply Conservative WPO Profile"' . $disabled . '>';
    echo '</form>';

    echo '<form method="post" action="" style="display:inline-block;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="cache_governor_clear_wpo_preload">';
    echo '<input type="submit" class="button button-secondary" value="Clear WPO Preload Jobs"' . $disabled . '>';
    echo '</form>';

    if ( $diagnostics['wpo_active'] ) {
        echo '<table class="widefat striped" style="max-width: 900px; margin-top: 14px;">';
        echo '<tbody>';
        echo '<tr><th scope="row">Page cache enabled</th><td>' . esc_html( $diagnostics['page_cache_enabled'] ? 'Yes' : 'No' ) . '</td></tr>';
        echo '<tr><th scope="row">Cache lifespan</th><td>' . esc_html( $diagnostics['ttl_label'] ) . '</td></tr>';
        echo '<tr><th scope="row">Preload after purge</th><td>' . esc_html( $diagnostics['preload_after_purge'] ? 'Enabled' : 'Disabled' ) . '</td></tr>';
        echo '<tr><th scope="row">Scheduled/sitemap preload</th><td>' . esc_html( ( $diagnostics['scheduled_preload'] || $diagnostics['sitemap_preload'] ) ? 'Enabled' : 'Disabled' ) . '</td></tr>';
        echo '<tr><th scope="row">Separate mobile cache</th><td>' . esc_html( $diagnostics['mobile_cache'] ? 'Enabled' : 'Disabled' ) . '</td></tr>';
        echo '<tr><th scope="row">Queued WPO preload jobs</th><td>' . esc_html( (string) $diagnostics['preload_cron_count'] ) . '</td></tr>';
        echo '</tbody></table>';
    }

    if ( empty( $diagnostics['risks'] ) ) {
        echo '<p><span style="color:#1e8e3e;"><strong>No high-risk WPO cache settings detected.</strong></span></p>';
    } else {
        echo '<p><strong>Detected risks / notes:</strong></p>';
        echo '<ul style="list-style: disc; padding-left: 22px;">';
        foreach ( $diagnostics['risks'] as $risk ) {
            echo '<li>' . esc_html( $risk ) . '</li>';
        }
        echo '</ul>';
    }

    if ( $diagnostics['last_enforced'] > 0 ) {
        $last_str = function_exists( 'wp_date' )
            ? wp_date( 'Y-m-d H:i:s T', $diagnostics['last_enforced'] )
            : date_i18n( 'Y-m-d H:i:s T', $diagnostics['last_enforced'] );
        echo '<div class="anchor-kv"><strong>Last governor enforcement:</strong> ' . esc_html( $last_str ) . '</div>';
    }

    echo '<p style="max-width: 1100px;"><em>Notes:</em> Enabling the governor keeps WP-Optimize on a conservative profile: no preload-after-purge, no scheduled/sitemap preload, no separate mobile cache, and a longer cache lifespan. It does not replace WP-Optimize or enable page caching on sites where page caching is off.</p>';
    echo '</div>';
}
