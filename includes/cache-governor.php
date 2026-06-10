<?php
/**
 * Anchor Cache Governor.
 *
 * Keeps known cache plugins from creating avoidable load spikes on small
 * shared PHP-FPM pools. It governs WP-Optimize rather than replacing it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const ANCHOR_CACHE_GOVERNOR_OPTION_ENABLED       = 'anchor_cache_governor_enabled';
const ANCHOR_CACHE_GOVERNOR_OPTION_LAST_ENFORCED = 'anchor_cache_governor_last_enforced';
const ANCHOR_CACHE_GOVERNOR_OPTION_PROFILE       = 'anchor_cache_governor_profile';
const ANCHOR_CACHE_GOVERNOR_OPTION_WARM_QUEUE    = 'anchor_cache_governor_warm_queue';
const ANCHOR_CACHE_GOVERNOR_OPTION_WARM_STATS    = 'anchor_cache_governor_warm_stats';
const ANCHOR_CACHE_GOVERNOR_OPTION_STALE_STATS   = 'anchor_cache_governor_stale_scan_stats';
const ANCHOR_CACHE_GOVERNOR_OPTION_EVENT_LOG     = 'anchor_cache_governor_event_log';
const ANCHOR_CACHE_GOVERNOR_WPO_PRELOAD_HOOK     = 'wpo_page_cache_preload_continue';
const ANCHOR_CACHE_GOVERNOR_WARM_HOOK            = 'anchor_cache_governor_warm_one_url';
const ANCHOR_CACHE_GOVERNOR_WARM_LOCK            = 'anchor_cache_governor_warm_lock';
const ANCHOR_CACHE_GOVERNOR_STALE_SCAN_HOOK      = 'anchor_cache_governor_scan_stale_cache';
const ANCHOR_CACHE_GOVERNOR_STALE_SCAN_LOCK      = 'anchor_cache_governor_stale_scan_lock';
const ANCHOR_CACHE_GOVERNOR_DEFAULT_TTL_DAYS     = 30;
const ANCHOR_CACHE_GOVERNOR_REST_BASE_ROUTE      = '/anchor/v1/cache-governor';

function anchor_cache_governor_get_profiles() {
    $profiles = array(
        'conservative' => array(
            'label'            => 'Conservative',
            'ttl_days'         => 30,
            'warm_pages'       => 6,
            'warm_recent_posts' => 4,
            'warm_delay'       => 5 * MINUTE_IN_SECONDS,
            'failure_delay'    => 15 * MINUTE_IN_SECONDS,
            'max_queue'        => 40,
            'load_limit'       => 4.0,
            'stale_refresh_days'    => 14,
            'stale_scan_batch'      => 4,
            'stale_scan_file_limit' => 500,
            'stale_scan_interval'   => 6 * HOUR_IN_SECONDS,
        ),
        'archive' => array(
            'label'            => 'Archive-heavy',
            'ttl_days'         => 60,
            'warm_pages'       => 10,
            'warm_recent_posts' => 3,
            'warm_delay'       => 10 * MINUTE_IN_SECONDS,
            'failure_delay'    => 30 * MINUTE_IN_SECONDS,
            'max_queue'        => 60,
            'load_limit'       => 4.0,
            'stale_refresh_days'    => 21,
            'stale_scan_batch'      => 5,
            'stale_scan_file_limit' => 800,
            'stale_scan_interval'   => 6 * HOUR_IN_SECONDS,
        ),
        'brochure' => array(
            'label'            => 'Brochure',
            'ttl_days'         => 30,
            'warm_pages'       => 12,
            'warm_recent_posts' => 0,
            'warm_delay'       => 5 * MINUTE_IN_SECONDS,
            'failure_delay'    => 15 * MINUTE_IN_SECONDS,
            'max_queue'        => 50,
            'load_limit'       => 4.0,
            'stale_refresh_days'    => 14,
            'stale_scan_batch'      => 4,
            'stale_scan_file_limit' => 500,
            'stale_scan_interval'   => 6 * HOUR_IN_SECONDS,
        ),
    );

    /**
     * Filters the available Anchor Cache Governor profiles.
     *
     * @param array $profiles Profile definitions keyed by profile slug.
     */
    $profiles = apply_filters( 'anchor_cache_governor_profiles', $profiles );

    return is_array( $profiles ) ? $profiles : array();
}

function anchor_cache_governor_get_profile_key() {
    $profiles = anchor_cache_governor_get_profiles();
    $profile  = sanitize_key( (string) get_option( ANCHOR_CACHE_GOVERNOR_OPTION_PROFILE, 'conservative' ) );

    return isset( $profiles[ $profile ] ) ? $profile : 'conservative';
}

function anchor_cache_governor_get_profile() {
    $profiles = anchor_cache_governor_get_profiles();
    $key      = anchor_cache_governor_get_profile_key();

    return isset( $profiles[ $key ] ) && is_array( $profiles[ $key ] ) ? $profiles[ $key ] : $profiles['conservative'];
}

function anchor_cache_governor_set_profile( $profile ) {
    $profile  = sanitize_key( (string) $profile );
    $profiles = anchor_cache_governor_get_profiles();

    if ( ! isset( $profiles[ $profile ] ) ) {
        return new WP_Error(
            'anchor_cache_governor_invalid_profile',
            __( 'Unknown Cache Governor profile.', 'anchor' ),
            array( 'status' => 400 )
        );
    }

    update_option( ANCHOR_CACHE_GOVERNOR_OPTION_PROFILE, $profile, false );
    anchor_cache_governor_log_event( 'profile_set', array( 'profile' => $profile ) );

    return $profile;
}

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

function anchor_cache_governor_is_plugin_active( $plugin_file ) {
    $plugin_file    = (string) $plugin_file;
    $active_plugins = (array) get_option( 'active_plugins', array() );
    if ( in_array( $plugin_file, $active_plugins, true ) ) {
        return true;
    }

    if ( is_multisite() ) {
        $network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
        if ( isset( $network_plugins[ $plugin_file ] ) ) {
            return true;
        }
    }

    return false;
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
    $profile = anchor_cache_governor_get_profile();
    $days = defined( 'ANCHOR_CACHE_GOVERNOR_WPO_TTL_DAYS' )
        ? (int) ANCHOR_CACHE_GOVERNOR_WPO_TTL_DAYS
        : (int) ( $profile['ttl_days'] ?? ANCHOR_CACHE_GOVERNOR_DEFAULT_TTL_DAYS );

    /**
     * Filters the conservative WP-Optimize cache lifespan used by Anchor.
     *
     * @param int $days Cache lifespan in days.
     */
    $days = (int) apply_filters( 'anchor_cache_governor_wpo_ttl_days', $days );

    return max( 1, min( 365, $days ) );
}

function anchor_cache_governor_get_stale_refresh_days() {
    $profile = anchor_cache_governor_get_profile();
    $days    = defined( 'ANCHOR_CACHE_GOVERNOR_STALE_REFRESH_DAYS' )
        ? (int) ANCHOR_CACHE_GOVERNOR_STALE_REFRESH_DAYS
        : (int) ( $profile['stale_refresh_days'] ?? 14 );

    /**
     * Filters the age after which cached HTML is eligible for slow refresh.
     *
     * @param int $days Cache file age in days.
     */
    $days = (int) apply_filters( 'anchor_cache_governor_stale_refresh_days', $days );

    return max( 1, min( 365, $days ) );
}

function anchor_cache_governor_get_stale_scan_batch() {
    $profile = anchor_cache_governor_get_profile();
    $batch   = (int) ( $profile['stale_scan_batch'] ?? 4 );

    /**
     * Filters how many stale cached URLs may be queued per scanner pass.
     *
     * @param int $batch URL count.
     */
    $batch = (int) apply_filters( 'anchor_cache_governor_stale_scan_batch', $batch );

    return max( 1, min( 25, $batch ) );
}

function anchor_cache_governor_get_stale_scan_file_limit() {
    $profile = anchor_cache_governor_get_profile();
    $limit   = (int) ( $profile['stale_scan_file_limit'] ?? 500 );

    /**
     * Filters how many WP-Optimize cache files Anchor may inspect per scan.
     *
     * @param int $limit File count.
     */
    $limit = (int) apply_filters( 'anchor_cache_governor_stale_scan_file_limit', $limit );

    return max( 50, min( 5000, $limit ) );
}

function anchor_cache_governor_get_stale_scan_interval() {
    $profile  = anchor_cache_governor_get_profile();
    $interval = (int) ( $profile['stale_scan_interval'] ?? ( 6 * HOUR_IN_SECONDS ) );

    /**
     * Filters the delay between conservative stale-cache scanner passes.
     *
     * @param int $interval Delay in seconds.
     */
    $interval = (int) apply_filters( 'anchor_cache_governor_stale_scan_interval', $interval );

    return max( HOUR_IN_SECONDS, min( WEEK_IN_SECONDS, $interval ) );
}

function anchor_cache_governor_normalize_list_option( $value ) {
    if ( ! is_array( $value ) ) {
        $value = array( $value );
    }

    return array_values(
        array_filter(
            array_map(
                function( $item ) {
                    return trim( (string) $item );
                },
                $value
            ),
            function( $item ) {
                return '' !== $item;
            }
        )
    );
}

function anchor_cache_governor_merge_list_option( array $existing, array $additions ) {
    $merged = array();
    foreach ( array_merge( $existing, $additions ) as $item ) {
        $item = trim( (string) $item );
        if ( '' === $item ) {
            continue;
        }

        $merged[ $item ] = $item;
    }

    return array_values( $merged );
}

function anchor_cache_governor_get_plugin_exception_rules() {
    $rules = array(
        'urls'    => array(),
        'cookies' => array(),
        'plugins' => array(),
    );

    $woocommerce_active = anchor_cache_governor_is_plugin_active( 'woocommerce/woocommerce.php' )
        || class_exists( 'WooCommerce', false )
        || function_exists( 'WC' );

    if ( $woocommerce_active ) {
        $rules['plugins'][] = 'woocommerce';
        $rules['urls'] = array_merge(
            $rules['urls'],
            array(
                '/cart/',
                '/checkout/',
                '/my-account/',
                '/account/',
                '/wc-api/',
                '/order-pay/',
                '/order-received/',
            )
        );
        $rules['cookies'] = array_merge(
            $rules['cookies'],
            array(
                'woocommerce_items_in_cart',
                'woocommerce_cart_hash',
                'wp_woocommerce_session_',
            )
        );
    }

    if ( anchor_cache_governor_is_plugin_active( 'easy-digital-downloads/easy-digital-downloads.php' ) ) {
        $rules['plugins'][] = 'easy-digital-downloads';
        $rules['urls'] = array_merge(
            $rules['urls'],
            array(
                '/checkout/',
                '/purchase-confirmation/',
                '/purchase-history/',
                '/transaction-failed/',
            )
        );
        $rules['cookies'][] = 'edd_items_in_cart';
    }

    /**
     * Filters plugin-aware WPO cache exception rules.
     *
     * @param array $rules Exception rules with urls, cookies, and plugin labels.
     */
    $rules = apply_filters( 'anchor_cache_governor_plugin_exception_rules', $rules );

    return array(
        'urls'    => anchor_cache_governor_normalize_list_option( $rules['urls'] ?? array() ),
        'cookies' => anchor_cache_governor_normalize_list_option( $rules['cookies'] ?? array() ),
        'plugins' => anchor_cache_governor_normalize_list_option( $rules['plugins'] ?? array() ),
    );
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

    $exception_rules = anchor_cache_governor_get_plugin_exception_rules();
    if ( ! empty( $exception_rules['urls'] ) ) {
        $config['cache_exception_urls'] = anchor_cache_governor_merge_list_option(
            anchor_cache_governor_normalize_list_option( $config['cache_exception_urls'] ?? array() ),
            $exception_rules['urls']
        );
    }
    if ( ! empty( $exception_rules['cookies'] ) ) {
        $config['cache_exception_cookies'] = anchor_cache_governor_merge_list_option(
            anchor_cache_governor_normalize_list_option( $config['cache_exception_cookies'] ?? array() ),
            $exception_rules['cookies']
        );
    }

    update_option( 'wpo_cache_config', $config, false );

    $changed = array();
    foreach ( $config as $key => $value ) {
        if ( ! array_key_exists( $key, $before ) || $before[ $key ] !== $value ) {
            $changed[] = $key;
        }
    }

    $cleared = anchor_cache_governor_clear_wpo_preload_jobs();

    update_option( ANCHOR_CACHE_GOVERNOR_OPTION_LAST_ENFORCED, time(), false );

    if ( anchor_cache_governor_is_enabled() ) {
        anchor_cache_governor_schedule_stale_scan( HOUR_IN_SECONDS );
    }

    anchor_cache_governor_log_event(
        'wpo_profile_applied',
        array(
            'ttl_days' => $ttl_days,
            'changed'  => $changed,
            'cleared'  => $cleared,
            'plugins'  => $exception_rules['plugins'],
        )
    );

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

function anchor_cache_governor_allow_basic_auth_route( $bases ) {
    if ( ! is_array( $bases ) ) {
        $bases = array();
    }

    $bases[] = ANCHOR_CACHE_GOVERNOR_REST_BASE_ROUTE;

    return $bases;
}
add_filter( 'anchor_temp_admin_automation_basic_auth_route_bases', 'anchor_cache_governor_allow_basic_auth_route' );

function anchor_cache_governor_get_warm_queue() {
    $queue = get_option( ANCHOR_CACHE_GOVERNOR_OPTION_WARM_QUEUE, array() );

    return is_array( $queue ) ? array_values( $queue ) : array();
}

function anchor_cache_governor_update_warm_queue( array $queue ) {
    update_option( ANCHOR_CACHE_GOVERNOR_OPTION_WARM_QUEUE, array_values( $queue ), false );
}

function anchor_cache_governor_get_warm_stats() {
    $stats = get_option( ANCHOR_CACHE_GOVERNOR_OPTION_WARM_STATS, array() );
    if ( ! is_array( $stats ) ) {
        $stats = array();
    }

    return array_merge(
        array(
            'warmed_count' => 0,
            'failed_count' => 0,
            'last_run'     => 0,
            'last_url'     => '',
            'last_status'  => 0,
            'last_error'   => '',
            'paused_until' => 0,
        ),
        $stats
    );
}

function anchor_cache_governor_update_warm_stats( array $stats ) {
    update_option(
        ANCHOR_CACHE_GOVERNOR_OPTION_WARM_STATS,
        array_merge( anchor_cache_governor_get_warm_stats(), $stats ),
        false
    );
}

function anchor_cache_governor_get_stale_scan_stats() {
    $stats = get_option( ANCHOR_CACHE_GOVERNOR_OPTION_STALE_STATS, array() );
    if ( ! is_array( $stats ) ) {
        $stats = array();
    }

    return array_merge(
        array(
            'last_run'           => 0,
            'last_error'         => '',
            'last_root'          => '',
            'last_scanned_files' => 0,
            'last_found_count'   => 0,
            'last_enqueued'      => 0,
            'last_cutoff'        => 0,
        ),
        $stats
    );
}

function anchor_cache_governor_update_stale_scan_stats( array $stats ) {
    update_option(
        ANCHOR_CACHE_GOVERNOR_OPTION_STALE_STATS,
        array_merge( anchor_cache_governor_get_stale_scan_stats(), $stats ),
        false
    );
}

function anchor_cache_governor_event_log_limit() {
    /**
     * Filters how many Cache Governor events Anchor stores.
     *
     * @param int $limit Event count.
     */
    $limit = (int) apply_filters( 'anchor_cache_governor_event_log_limit', 100 );

    return max( 10, min( 500, $limit ) );
}

function anchor_cache_governor_get_event_log( $limit = null ) {
    $events = get_option( ANCHOR_CACHE_GOVERNOR_OPTION_EVENT_LOG, array() );
    if ( ! is_array( $events ) ) {
        return array();
    }

    $events = array_values(
        array_filter(
            $events,
            function( $event ) {
                return is_array( $event ) && ! empty( $event['event'] ) && ! empty( $event['time'] );
            }
        )
    );

    usort(
        $events,
        function( $a, $b ) {
            return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
        }
    );

    if ( null !== $limit ) {
        $events = array_slice( $events, 0, max( 0, (int) $limit ) );
    }

    return $events;
}

function anchor_cache_governor_sanitize_event_context( array $context ) {
    $clean = array();
    foreach ( $context as $key => $value ) {
        $key = sanitize_key( (string) $key );
        if ( '' === $key ) {
            continue;
        }

        if ( is_bool( $value ) ) {
            $clean[ $key ] = $value;
        } elseif ( is_int( $value ) || is_float( $value ) ) {
            $clean[ $key ] = $value;
        } elseif ( is_array( $value ) ) {
            $clean[ $key ] = array_slice(
                array_map(
                    function( $item ) {
                        return is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '';
                    },
                    $value
                ),
                0,
                10
            );
        } elseif ( null !== $value ) {
            $clean[ $key ] = sanitize_text_field( (string) $value );
        }
    }

    return $clean;
}

function anchor_cache_governor_log_event( $event, array $context = array() ) {
    $event = sanitize_key( (string) $event );
    if ( '' === $event ) {
        return;
    }

    $entry = array(
        'time'    => time(),
        'event'   => $event,
        'context' => anchor_cache_governor_sanitize_event_context( $context ),
    );

    $events = anchor_cache_governor_get_event_log();
    array_unshift( $events, $entry );
    $events = array_slice( $events, 0, anchor_cache_governor_event_log_limit() );

    update_option( ANCHOR_CACHE_GOVERNOR_OPTION_EVENT_LOG, $events, false );
}

function anchor_cache_governor_clear_event_log() {
    delete_option( ANCHOR_CACHE_GOVERNOR_OPTION_EVENT_LOG );
}

function anchor_cache_governor_normalize_url( $url ) {
    $url = trim( (string) $url );
    if ( '' === $url ) {
        return '';
    }

    if ( strpos( $url, '/' ) === 0 ) {
        $url = home_url( $url );
    }

    $url = esc_url_raw( $url );
    if ( '' === $url ) {
        return '';
    }

    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $url_host  = wp_parse_url( $url, PHP_URL_HOST );
    if ( ! is_string( $home_host ) || ! is_string( $url_host ) || strtolower( $home_host ) !== strtolower( $url_host ) ) {
        return '';
    }

    return $url;
}

function anchor_cache_governor_diagnose_url( $url ) {
    $original = (string) $url;
    $url      = anchor_cache_governor_normalize_url( $url );
    $reasons  = array();

    if ( '' === $url ) {
        return array(
            'url'       => $original,
            'cacheable' => false,
            'reasons'   => array( 'URL is empty, invalid, or outside this site.' ),
        );
    }

    $path  = (string) wp_parse_url( $url, PHP_URL_PATH );
    $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
    $path_lc = strtolower( '/' . ltrim( $path, '/' ) );

    if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php)(?:/|$)#', $path_lc ) ) {
        $reasons[] = 'WordPress admin/login/API/XML-RPC paths are bypassed.';
    }

    if ( false !== strpos( $path_lc, '/wp-admin/admin-ajax.php' ) ) {
        $reasons[] = 'admin-ajax requests are bypassed.';
    }

    if ( preg_match( '#/(cart|checkout|my-account|account)(?:/|$)#', $path_lc ) ) {
        $reasons[] = 'Commerce/account paths are bypassed.';
    }

    if ( preg_match( '#/(purchase-confirmation|purchase-history|transaction-failed)(?:/|$)#', $path_lc ) ) {
        $reasons[] = 'Digital commerce paths are bypassed.';
    }

    if ( preg_match( '#/(feed|comments/feed)(?:/|$)#', $path_lc ) ) {
        $reasons[] = 'Feeds are not warmed by Anchor.';
    }

    $query_vars = array();
    if ( '' !== $query ) {
        parse_str( $query, $query_vars );
    }

    foreach ( $query_vars as $key => $value ) {
        unset( $value );
        $key_lc = strtolower( (string) $key );
        if (
            's' === $key_lc
            || 'preview' === $key_lc
            || 'customize_changeset_uuid' === $key_lc
            || false !== strpos( $key_lc, 'nonce' )
            || false !== strpos( $key_lc, 'token' )
        ) {
            $reasons[] = 'Search, preview, nonce, and token query strings are bypassed.';
            continue;
        }

        if (
            0 === strpos( $key_lc, 'utm_' )
            || in_array( $key_lc, array( 'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid' ), true )
        ) {
            continue;
        }

        $reasons[] = 'Non-marketing query strings are not warmed by Anchor.';
    }

    /**
     * Allows site-specific URL bypass rules.
     *
     * @param string[] $reasons Current bypass reasons.
     * @param string   $url     Normalized URL.
     */
    $reasons = apply_filters( 'anchor_cache_governor_url_bypass_reasons', $reasons, $url );
    $reasons = is_array( $reasons ) ? array_values( array_unique( array_filter( $reasons ) ) ) : array();

    return array(
        'url'       => $url,
        'cacheable' => empty( $reasons ),
        'reasons'   => empty( $reasons ) ? array( 'Looks safe for anonymous page-cache warming.' ) : $reasons,
    );
}

function anchor_cache_governor_collect_default_warm_urls() {
    $profile = anchor_cache_governor_get_profile();
    $urls    = array( home_url( '/' ) );

    $page_count = max( 0, (int) ( $profile['warm_pages'] ?? 0 ) );
    if ( $page_count > 0 ) {
        $pages = get_posts(
            array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => $page_count,
                'orderby'        => array(
                    'menu_order' => 'ASC',
                    'title'      => 'ASC',
                ),
                'no_found_rows'  => true,
                'fields'         => 'ids',
            )
        );

        foreach ( $pages as $post_id ) {
            $urls[] = get_permalink( (int) $post_id );
        }
    }

    $post_count = max( 0, (int) ( $profile['warm_recent_posts'] ?? 0 ) );
    if ( $post_count > 0 ) {
        $posts = get_posts(
            array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => $post_count,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'fields'         => 'ids',
            )
        );

        foreach ( $posts as $post_id ) {
            $urls[] = get_permalink( (int) $post_id );
        }
    }

    /**
     * Filters URLs added by the "enqueue default warm URLs" action.
     *
     * @param string[] $urls Candidate URLs.
     */
    $urls = apply_filters( 'anchor_cache_governor_default_warm_urls', $urls );

    return is_array( $urls ) ? $urls : array();
}

function anchor_cache_governor_enqueue_warm_urls( array $urls, $source = 'manual' ) {
    $profile   = anchor_cache_governor_get_profile();
    $max_queue = max( 1, (int) ( $profile['max_queue'] ?? 40 ) );
    $queue     = anchor_cache_governor_get_warm_queue();
    $seen      = array();

    foreach ( $queue as $item ) {
        if ( ! empty( $item['url'] ) ) {
            $seen[ (string) $item['url'] ] = true;
        }
    }

    $added = 0;
    foreach ( $urls as $url ) {
        if ( count( $queue ) >= $max_queue ) {
            break;
        }

        $diagnosis = anchor_cache_governor_diagnose_url( $url );
        if ( empty( $diagnosis['cacheable'] ) || empty( $diagnosis['url'] ) ) {
            continue;
        }

        $url = (string) $diagnosis['url'];
        if ( isset( $seen[ $url ] ) ) {
            continue;
        }

        $queue[] = array(
            'url'       => $url,
            'source'    => sanitize_key( (string) $source ),
            'queued_at' => time(),
            'attempts'  => 0,
        );
        $seen[ $url ] = true;
        $added++;
    }

    anchor_cache_governor_update_warm_queue( $queue );
    anchor_cache_governor_schedule_warm_runner( MINUTE_IN_SECONDS );

    if ( $added > 0 ) {
        anchor_cache_governor_log_event(
            'warm_urls_queued',
            array(
                'source'       => sanitize_key( (string) $source ),
                'added'        => $added,
                'queue_length' => count( $queue ),
            )
        );
    }

    return array(
        'added'        => $added,
        'queue_length' => count( $queue ),
        'max_queue'    => $max_queue,
    );
}

function anchor_cache_governor_enqueue_default_warm_urls() {
    return anchor_cache_governor_enqueue_warm_urls( anchor_cache_governor_collect_default_warm_urls(), 'default' );
}

function anchor_cache_governor_schedule_warm_runner( $delay = null ) {
    if ( null === $delay ) {
        $profile = anchor_cache_governor_get_profile();
        $delay   = max( MINUTE_IN_SECONDS, (int) ( $profile['warm_delay'] ?? ( 5 * MINUTE_IN_SECONDS ) ) );
    }

    if ( wp_next_scheduled( ANCHOR_CACHE_GOVERNOR_WARM_HOOK ) ) {
        return;
    }

    if ( empty( anchor_cache_governor_get_warm_queue() ) ) {
        return;
    }

    wp_schedule_single_event( time() + max( 30, (int) $delay ), ANCHOR_CACHE_GOVERNOR_WARM_HOOK );
}

function anchor_cache_governor_get_wpo_cache_root() {
    if ( ! defined( 'WP_CONTENT_DIR' ) ) {
        return '';
    }

    $root = trailingslashit( WP_CONTENT_DIR ) . 'cache/wpo-cache';

    /**
     * Filters the WP-Optimize page-cache root scanned by Anchor.
     *
     * @param string $root Cache root path.
     */
    $root = (string) apply_filters( 'anchor_cache_governor_wpo_cache_root', $root );
    $root = untrailingslashit( $root );

    return '' !== $root ? $root : '';
}

function anchor_cache_governor_hosts_match( $candidate_host ) {
    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( ! is_string( $home_host ) || '' === $home_host ) {
        return false;
    }

    $home_host      = strtolower( preg_replace( '/^www\./', '', $home_host ) );
    $candidate_host = strtolower( preg_replace( '/^www\./', '', (string) $candidate_host ) );

    return '' !== $candidate_host && $candidate_host === $home_host;
}

function anchor_cache_governor_cache_file_to_url( $file, $root = '' ) {
    $root = '' !== $root ? $root : anchor_cache_governor_get_wpo_cache_root();
    if ( '' === $root ) {
        return '';
    }

    $real_root = realpath( $root );
    $real_file = realpath( (string) $file );
    if ( ! is_string( $real_root ) || ! is_string( $real_file ) ) {
        return '';
    }

    $real_root = rtrim( str_replace( '\\', '/', $real_root ), '/' );
    $real_file = str_replace( '\\', '/', $real_file );

    if ( 0 !== strpos( $real_file, $real_root . '/' ) ) {
        return '';
    }

    $relative = ltrim( substr( $real_file, strlen( $real_root ) ), '/' );
    $parts    = array_values( array_filter( explode( '/', $relative ), 'strlen' ) );
    if ( count( $parts ) < 2 ) {
        return '';
    }

    $host = array_shift( $parts );
    if ( ! anchor_cache_governor_hosts_match( $host ) ) {
        return '';
    }

    $file_name = (string) end( $parts );
    if ( ! preg_match( '/\.html?$/i', $file_name ) ) {
        return '';
    }

    if ( preg_match( '/^index\.html?$/i', $file_name ) ) {
        array_pop( $parts );
    } else {
        $parts[ count( $parts ) - 1 ] = preg_replace( '/\.html?$/i', '', $file_name );
    }

    $path = implode( '/', array_map( 'rawurlencode', $parts ) );
    $url  = home_url( '' === $path ? '/' : '/' . $path . '/' );

    /**
     * Filters the URL mapped from a WP-Optimize cache file.
     *
     * @param string $url  Candidate URL.
     * @param string $file Cache file path.
     * @param string $root Cache root path.
     */
    $url = (string) apply_filters( 'anchor_cache_governor_cache_file_url', $url, $file, $root );

    return anchor_cache_governor_normalize_url( $url );
}

function anchor_cache_governor_find_stale_wpo_cache_urls( $limit = null ) {
    $root = anchor_cache_governor_get_wpo_cache_root();
    if ( '' === $root || ! is_dir( $root ) ) {
        return array(
            'ok'            => false,
            'message'       => 'WP-Optimize cache root was not found.',
            'root'          => $root,
            'scanned_files' => 0,
            'urls'          => array(),
        );
    }

    $limit      = null === $limit ? anchor_cache_governor_get_stale_scan_batch() : (int) $limit;
    $limit      = max( 1, min( 25, $limit ) );
    $file_limit = anchor_cache_governor_get_stale_scan_file_limit();
    $cutoff     = time() - ( anchor_cache_governor_get_stale_refresh_days() * DAY_IN_SECONDS );
    $candidates = array();
    $seen       = array();
    $scanned    = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file_info ) {
            if ( $scanned >= $file_limit ) {
                break;
            }

            if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
                continue;
            }

            $path = $file_info->getPathname();
            if ( ! preg_match( '/\.html?$/i', $path ) ) {
                continue;
            }

            $scanned++;
            $mtime = (int) $file_info->getMTime();
            if ( $mtime <= 0 || $mtime > $cutoff ) {
                continue;
            }

            $url = anchor_cache_governor_cache_file_to_url( $path, $root );
            if ( '' === $url || isset( $seen[ $url ] ) ) {
                continue;
            }

            $diagnosis = anchor_cache_governor_diagnose_url( $url );
            if ( empty( $diagnosis['cacheable'] ) ) {
                continue;
            }

            $seen[ $url ] = true;
            $candidates[] = array(
                'url'   => $url,
                'mtime' => $mtime,
            );
        }
    } catch ( Exception $e ) {
        return array(
            'ok'            => false,
            'message'       => $e->getMessage(),
            'root'          => $root,
            'scanned_files' => $scanned,
            'urls'          => array(),
        );
    }

    usort(
        $candidates,
        function( $a, $b ) {
            return (int) $a['mtime'] <=> (int) $b['mtime'];
        }
    );

    $urls = array();
    foreach ( array_slice( $candidates, 0, $limit ) as $candidate ) {
        $urls[] = (string) $candidate['url'];
    }

    return array(
        'ok'            => true,
        'message'       => 'Stale WP-Optimize cache scan completed.',
        'root'          => $root,
        'cutoff'        => $cutoff,
        'scanned_files' => $scanned,
        'found_count'   => count( $candidates ),
        'urls'          => $urls,
    );
}

function anchor_cache_governor_schedule_stale_scan( $delay = null ) {
    if ( ! anchor_cache_governor_is_enabled() || ! anchor_cache_governor_is_wp_optimize_active() ) {
        return false;
    }

    if ( wp_next_scheduled( ANCHOR_CACHE_GOVERNOR_STALE_SCAN_HOOK ) ) {
        return false;
    }

    if ( null === $delay ) {
        $delay = anchor_cache_governor_get_stale_scan_interval();
    }

    return (bool) wp_schedule_single_event(
        time() + max( 5 * MINUTE_IN_SECONDS, (int) $delay ),
        ANCHOR_CACHE_GOVERNOR_STALE_SCAN_HOOK
    );
}

function anchor_cache_governor_maybe_schedule_stale_scan() {
    anchor_cache_governor_schedule_stale_scan();
}
add_action( 'init', 'anchor_cache_governor_maybe_schedule_stale_scan', 25 );

function anchor_cache_governor_server_too_busy() {
    if ( ! function_exists( 'sys_getloadavg' ) ) {
        return false;
    }

    $load = sys_getloadavg();
    if ( ! is_array( $load ) || ! isset( $load[0] ) ) {
        return false;
    }

    $profile = anchor_cache_governor_get_profile();
    $limit   = (float) apply_filters( 'anchor_cache_governor_warm_load_limit', (float) ( $profile['load_limit'] ?? 4.0 ) );

    return $limit > 0 && (float) $load[0] > $limit;
}

function anchor_cache_governor_run_stale_scan( $manual = false ) {
    if ( ! anchor_cache_governor_is_enabled() || ! anchor_cache_governor_is_wp_optimize_active() ) {
        return array(
            'ok'      => false,
            'message' => 'Cache Governor must be enabled and WP-Optimize must be active before stale cache scanning.',
        );
    }

    if ( get_transient( ANCHOR_CACHE_GOVERNOR_STALE_SCAN_LOCK ) ) {
        return array(
            'ok'      => false,
            'message' => 'Stale cache scanner is already active.',
        );
    }

    if ( ! $manual && anchor_cache_governor_server_too_busy() ) {
        anchor_cache_governor_schedule_stale_scan();
        return array(
            'ok'      => false,
            'message' => 'Server load is above the stale scan threshold.',
        );
    }

    set_transient( ANCHOR_CACHE_GOVERNOR_STALE_SCAN_LOCK, 1, 5 * MINUTE_IN_SECONDS );

    $scan = anchor_cache_governor_find_stale_wpo_cache_urls();
    $queued = array(
        'added'        => 0,
        'queue_length' => count( anchor_cache_governor_get_warm_queue() ),
        'max_queue'    => (int) ( anchor_cache_governor_get_profile()['max_queue'] ?? 0 ),
    );

    if ( ! empty( $scan['ok'] ) && ! empty( $scan['urls'] ) && is_array( $scan['urls'] ) ) {
        $queued = anchor_cache_governor_enqueue_warm_urls( $scan['urls'], 'stale-scan' );
    }

    $error = empty( $scan['ok'] ) ? (string) ( $scan['message'] ?? 'Stale cache scan failed.' ) : '';
    anchor_cache_governor_update_stale_scan_stats(
        array(
            'last_run'           => time(),
            'last_error'         => $error,
            'last_root'          => (string) ( $scan['root'] ?? '' ),
            'last_scanned_files' => (int) ( $scan['scanned_files'] ?? 0 ),
            'last_found_count'   => (int) ( $scan['found_count'] ?? 0 ),
            'last_enqueued'      => (int) ( $queued['added'] ?? 0 ),
            'last_cutoff'        => (int) ( $scan['cutoff'] ?? 0 ),
        )
    );

    delete_transient( ANCHOR_CACHE_GOVERNOR_STALE_SCAN_LOCK );
    anchor_cache_governor_schedule_stale_scan();

    anchor_cache_governor_log_event(
        'stale_scan',
        array(
            'ok'            => ! empty( $scan['ok'] ),
            'scanned_files' => (int) ( $scan['scanned_files'] ?? 0 ),
            'found_count'   => (int) ( $scan['found_count'] ?? 0 ),
            'queued'        => (int) ( $queued['added'] ?? 0 ),
            'manual'        => (bool) $manual,
            'error'         => $error,
        )
    );

    return array(
        'ok'      => ! empty( $scan['ok'] ),
        'message' => (string) ( $scan['message'] ?? '' ),
        'scan'    => $scan,
        'queued'  => $queued,
    );
}

function anchor_cache_governor_stale_scan_cron_runner() {
    anchor_cache_governor_run_stale_scan( false );
}
add_action( ANCHOR_CACHE_GOVERNOR_STALE_SCAN_HOOK, 'anchor_cache_governor_stale_scan_cron_runner' );

function anchor_cache_governor_run_warm_step( $manual = false ) {
    if ( ! anchor_cache_governor_is_enabled() || ! anchor_cache_governor_is_wp_optimize_active() ) {
        return array(
            'ok'      => false,
            'message' => 'Cache Governor must be enabled and WP-Optimize must be active before warming.',
        );
    }

    if ( get_transient( ANCHOR_CACHE_GOVERNOR_WARM_LOCK ) ) {
        return array(
            'ok'      => false,
            'message' => 'Warm runner is already active.',
        );
    }

    $stats = anchor_cache_governor_get_warm_stats();
    if ( ! $manual && ! empty( $stats['paused_until'] ) && (int) $stats['paused_until'] > time() ) {
        anchor_cache_governor_schedule_warm_runner( (int) $stats['paused_until'] - time() );
        anchor_cache_governor_log_event(
            'warm_paused',
            array(
                'paused_until' => (int) $stats['paused_until'],
                'queue_length' => count( anchor_cache_governor_get_warm_queue() ),
            )
        );
        return array(
            'ok'      => false,
            'message' => 'Warm runner is paused after a recent error.',
        );
    }

    if ( ! $manual && anchor_cache_governor_server_too_busy() ) {
        anchor_cache_governor_schedule_warm_runner();
        anchor_cache_governor_log_event(
            'warm_deferred_load',
            array(
                'queue_length' => count( anchor_cache_governor_get_warm_queue() ),
            )
        );
        return array(
            'ok'      => false,
            'message' => 'Server load is above the warm queue threshold.',
        );
    }

    $queue = anchor_cache_governor_get_warm_queue();
    if ( empty( $queue ) ) {
        return array(
            'ok'      => true,
            'message' => 'Warm queue is empty.',
        );
    }

    set_transient( ANCHOR_CACHE_GOVERNOR_WARM_LOCK, 1, 2 * MINUTE_IN_SECONDS );

    $item = array_shift( $queue );
    $url  = isset( $item['url'] ) ? (string) $item['url'] : '';
    $diagnosis = anchor_cache_governor_diagnose_url( $url );

    if ( empty( $diagnosis['cacheable'] ) ) {
        anchor_cache_governor_update_warm_queue( $queue );
        delete_transient( ANCHOR_CACHE_GOVERNOR_WARM_LOCK );
        anchor_cache_governor_schedule_warm_runner();
        anchor_cache_governor_log_event(
            'warm_skipped',
            array(
                'url'          => $url,
                'reason'       => implode( ' ', (array) $diagnosis['reasons'] ),
                'queue_length' => count( $queue ),
            )
        );

        return array(
            'ok'      => false,
            'message' => 'Skipped non-cacheable URL.',
            'url'     => $url,
            'reasons' => $diagnosis['reasons'],
        );
    }

    $response = wp_remote_get(
        $url,
        array(
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => array(
                'Accept'     => 'text/html,application/xhtml+xml',
                'User-Agent' => 'Anchor-Cache-Governor/' . ( defined( 'ANCHOR_VERSION' ) ? ANCHOR_VERSION : 'unknown' ) . '; ' . home_url( '/' ),
            ),
        )
    );

    $status = 0;
    $error  = '';
    if ( is_wp_error( $response ) ) {
        $error = $response->get_error_message();
    } else {
        $status = (int) wp_remote_retrieve_response_code( $response );
    }

    $success = $status >= 200 && $status < 400 && '' === $error;
    $profile = anchor_cache_governor_get_profile();
    $next_delay = max( MINUTE_IN_SECONDS, (int) ( $profile['warm_delay'] ?? ( 5 * MINUTE_IN_SECONDS ) ) );
    $paused_until = 0;

    if ( ! $success ) {
        $next_delay = max( 5 * MINUTE_IN_SECONDS, (int) ( $profile['failure_delay'] ?? ( 15 * MINUTE_IN_SECONDS ) ) );
        $paused_until = time() + $next_delay;
    }

    anchor_cache_governor_update_warm_queue( $queue );
    anchor_cache_governor_update_warm_stats(
        array(
            'warmed_count' => (int) $stats['warmed_count'] + ( $success ? 1 : 0 ),
            'failed_count' => (int) $stats['failed_count'] + ( $success ? 0 : 1 ),
            'last_run'     => time(),
            'last_url'     => $url,
            'last_status'  => $status,
            'last_error'   => $error,
            'paused_until' => $paused_until,
        )
    );

    delete_transient( ANCHOR_CACHE_GOVERNOR_WARM_LOCK );

    if ( ! empty( $queue ) ) {
        anchor_cache_governor_schedule_warm_runner( $next_delay );
    }

    anchor_cache_governor_log_event(
        $success ? 'warm_succeeded' : 'warm_failed',
        array(
            'url'          => $url,
            'status'       => $status,
            'error'        => $error,
            'queue_length' => count( $queue ),
            'next_delay'   => ! empty( $queue ) ? $next_delay : 0,
            'manual'       => (bool) $manual,
        )
    );

    return array(
        'ok'           => $success,
        'url'          => $url,
        'status'       => $status,
        'error'        => $error,
        'queue_length' => count( $queue ),
        'next_delay'   => ! empty( $queue ) ? $next_delay : 0,
    );
}

function anchor_cache_governor_warm_cron_runner() {
    anchor_cache_governor_run_warm_step( false );
}
add_action( ANCHOR_CACHE_GOVERNOR_WARM_HOOK, 'anchor_cache_governor_warm_cron_runner' );

function anchor_cache_governor_enqueue_content_change_warm( $post_id, $post, $update ) {
    unset( $update );

    if ( ! anchor_cache_governor_is_enabled() || ! anchor_cache_governor_is_wp_optimize_active() ) {
        return;
    }

    $post_id = (int) $post_id;
    if ( $post_id <= 0 || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return;
    }

    if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
        return;
    }

    $post_type = get_post_type_object( $post->post_type );
    if ( ! $post_type || empty( $post_type->public ) ) {
        return;
    }

    $urls = array_filter(
        array(
            get_permalink( $post_id ),
            home_url( '/' ),
        )
    );

    if ( ! empty( $post_type->has_archive ) ) {
        $archive_url = get_post_type_archive_link( $post->post_type );
        if ( $archive_url ) {
            $urls[] = $archive_url;
        }
    }

    /**
     * Filters URLs queued after a public post/page is saved.
     *
     * @param string[] $urls    Candidate URLs.
     * @param int      $post_id Saved post ID.
     * @param WP_Post  $post    Saved post.
     */
    $urls = apply_filters( 'anchor_cache_governor_content_change_warm_urls', $urls, $post_id, $post );

    anchor_cache_governor_enqueue_warm_urls( is_array( $urls ) ? $urls : array(), 'content-change' );
}
add_action( 'save_post', 'anchor_cache_governor_enqueue_content_change_warm', 30, 3 );

function anchor_cache_governor_get_diagnostics() {
    $config = anchor_cache_governor_get_wpo_config();
    $profile_key = anchor_cache_governor_get_profile_key();
    $profile     = anchor_cache_governor_get_profile();
    $queue       = anchor_cache_governor_get_warm_queue();
    $warm_stats  = anchor_cache_governor_get_warm_stats();
    $stale_stats = anchor_cache_governor_get_stale_scan_stats();
    $exception_rules = anchor_cache_governor_get_plugin_exception_rules();

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
        'profile'             => $profile_key,
        'profile_label'       => (string) ( $profile['label'] ?? $profile_key ),
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
        'cache_exceptions'    => array(
            'plugin_rules' => $exception_rules,
            'urls'         => anchor_cache_governor_normalize_list_option( $config['cache_exception_urls'] ?? array() ),
            'cookies'      => anchor_cache_governor_normalize_list_option( $config['cache_exception_cookies'] ?? array() ),
        ),
        'preload_cron_count'  => anchor_cache_governor_count_cron_hook( ANCHOR_CACHE_GOVERNOR_WPO_PRELOAD_HOOK ),
        'last_enforced'       => (int) get_option( ANCHOR_CACHE_GOVERNOR_OPTION_LAST_ENFORCED, 0 ),
        'warm_queue'          => array(
            'length'        => count( $queue ),
            'next_scheduled' => (int) wp_next_scheduled( ANCHOR_CACHE_GOVERNOR_WARM_HOOK ),
            'stats'         => $warm_stats,
        ),
        'stale_scan'          => array(
            'next_scheduled'     => (int) wp_next_scheduled( ANCHOR_CACHE_GOVERNOR_STALE_SCAN_HOOK ),
            'stale_refresh_days' => anchor_cache_governor_get_stale_refresh_days(),
            'batch'              => anchor_cache_governor_get_stale_scan_batch(),
            'file_limit'         => anchor_cache_governor_get_stale_scan_file_limit(),
            'interval_seconds'   => anchor_cache_governor_get_stale_scan_interval(),
            'root'               => anchor_cache_governor_get_wpo_cache_root(),
            'root_exists'        => is_dir( anchor_cache_governor_get_wpo_cache_root() ),
            'stats'              => $stale_stats,
        ),
        'recent_events'       => anchor_cache_governor_get_event_log( 10 ),
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

    if (
        $diagnostics['ttl_seconds'] > 0
        && $diagnostics['stale_scan']['stale_refresh_days'] * DAY_IN_SECONDS >= $diagnostics['ttl_seconds']
    ) {
        $risks[] = 'Stale refresh age is not earlier than the WP-Optimize cache lifespan.';
    }

    $diagnostics['risks'] = $risks;

    return $diagnostics;
}

function anchor_cache_governor_rest_permission() {
    if ( ! is_user_logged_in() ) {
        return new WP_Error(
            'anchor_cache_governor_auth_required',
            __( 'Authenticate before using the Anchor Cache Governor endpoint.', 'anchor' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    if ( function_exists( 'anchor_is_temp_admin_user' ) && anchor_is_temp_admin_user() ) {
        if (
            function_exists( 'anchor_temp_admin_automation_get_lease' )
            && function_exists( 'anchor_temp_admin_automation_heartbeat_owned_lease' )
        ) {
            $lease = anchor_temp_admin_automation_get_lease( false );
            if ( is_array( $lease ) && (int) ( $lease['owner_user_id'] ?? 0 ) === get_current_user_id() ) {
                anchor_temp_admin_automation_heartbeat_owned_lease();
                return true;
            }
        }

        return new WP_Error(
            'anchor_cache_governor_lease_required',
            __( 'A temporary admin must hold the Anchor automation lease before using the Cache Governor endpoint.', 'anchor' ),
            array( 'status' => 423 )
        );
    }

    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }

    return new WP_Error(
        'anchor_cache_governor_forbidden',
        __( 'You are not allowed to use the Anchor Cache Governor endpoint.', 'anchor' ),
        array( 'status' => 403 )
    );
}

function anchor_cache_governor_rest_status( WP_REST_Request $request ) {
    unset( $request );

    return rest_ensure_response(
        array(
            'ok'          => true,
            'enabled'     => anchor_cache_governor_is_enabled(),
            'diagnostics' => anchor_cache_governor_get_diagnostics(),
        )
    );
}

function anchor_cache_governor_rest_enable( WP_REST_Request $request ) {
    unset( $request );

    if ( ! anchor_cache_governor_is_wp_optimize_active() ) {
        return new WP_Error(
            'anchor_cache_governor_wpo_missing',
            __( 'WP-Optimize is not active on this site.', 'anchor' ),
            array( 'status' => 409 )
        );
    }

    update_option( ANCHOR_CACHE_GOVERNOR_OPTION_ENABLED, '1', false );
    anchor_cache_governor_log_event( 'governor_enabled', array( 'source' => 'rest' ) );
    $result = anchor_cache_governor_apply_wpo_conservative_profile();

    return rest_ensure_response(
        array(
            'ok'          => ! empty( $result['ok'] ),
            'enabled'     => anchor_cache_governor_is_enabled(),
            'result'      => $result,
            'diagnostics' => anchor_cache_governor_get_diagnostics(),
        )
    );
}

function anchor_cache_governor_rest_disable( WP_REST_Request $request ) {
    unset( $request );

    update_option( ANCHOR_CACHE_GOVERNOR_OPTION_ENABLED, '0', false );
    wp_clear_scheduled_hook( ANCHOR_CACHE_GOVERNOR_STALE_SCAN_HOOK );
    anchor_cache_governor_log_event( 'governor_disabled', array( 'source' => 'rest' ) );

    return rest_ensure_response(
        array(
            'ok'          => true,
            'enabled'     => false,
            'diagnostics' => anchor_cache_governor_get_diagnostics(),
        )
    );
}

function anchor_cache_governor_rest_apply_wpo_profile( WP_REST_Request $request ) {
    unset( $request );

    $result = anchor_cache_governor_apply_wpo_conservative_profile();

    return rest_ensure_response(
        array(
            'ok'          => ! empty( $result['ok'] ),
            'result'      => $result,
            'diagnostics' => anchor_cache_governor_get_diagnostics(),
        )
    );
}

function anchor_cache_governor_rest_clear_wpo_preload( WP_REST_Request $request ) {
    unset( $request );

    $cleared = anchor_cache_governor_clear_wpo_preload_jobs();
    anchor_cache_governor_log_event(
        'wpo_preload_cleared',
        array(
            'source'  => 'rest',
            'cleared' => $cleared,
        )
    );

    return rest_ensure_response(
        array(
            'ok'          => true,
            'cleared'     => $cleared,
            'diagnostics' => anchor_cache_governor_get_diagnostics(),
        )
    );
}

function anchor_cache_governor_rest_set_profile( WP_REST_Request $request ) {
    $profile = anchor_cache_governor_set_profile( $request->get_param( 'profile' ) );
    if ( is_wp_error( $profile ) ) {
        return $profile;
    }

    $result = array(
        'ok'      => true,
        'profile' => $profile,
    );

    if ( anchor_cache_governor_is_enabled() && anchor_cache_governor_is_wp_optimize_active() ) {
        $result['wpo_profile'] = anchor_cache_governor_apply_wpo_conservative_profile();
    }

    $result['diagnostics'] = anchor_cache_governor_get_diagnostics();

    return rest_ensure_response( $result );
}

function anchor_cache_governor_rest_enqueue_default_warm( WP_REST_Request $request ) {
    unset( $request );

    if ( ! anchor_cache_governor_is_enabled() || ! anchor_cache_governor_is_wp_optimize_active() ) {
        return new WP_Error(
            'anchor_cache_governor_warm_unavailable',
            __( 'Enable Cache Governor on a WP-Optimize site before queueing warm URLs.', 'anchor' ),
            array( 'status' => 409 )
        );
    }

    $result = anchor_cache_governor_enqueue_default_warm_urls();

    return rest_ensure_response(
        array(
            'ok'          => true,
            'result'      => $result,
            'diagnostics' => anchor_cache_governor_get_diagnostics(),
        )
    );
}

function anchor_cache_governor_rest_run_warm_step( WP_REST_Request $request ) {
    unset( $request );

    $result = anchor_cache_governor_run_warm_step( true );

    return rest_ensure_response(
        array(
            'ok'          => ! empty( $result['ok'] ),
            'result'      => $result,
            'diagnostics' => anchor_cache_governor_get_diagnostics(),
        )
    );
}

function anchor_cache_governor_rest_scan_stale_cache( WP_REST_Request $request ) {
    unset( $request );

    $result = anchor_cache_governor_run_stale_scan( true );

    return rest_ensure_response(
        array(
            'ok'          => ! empty( $result['ok'] ),
            'result'      => $result,
            'diagnostics' => anchor_cache_governor_get_diagnostics(),
        )
    );
}

function anchor_cache_governor_rest_clear_history( WP_REST_Request $request ) {
    unset( $request );

    anchor_cache_governor_clear_event_log();

    return rest_ensure_response(
        array(
            'ok'          => true,
            'diagnostics' => anchor_cache_governor_get_diagnostics(),
        )
    );
}

function anchor_cache_governor_rest_diagnose_url( WP_REST_Request $request ) {
    $url = $request->get_param( 'url' );

    return rest_ensure_response(
        array(
            'ok'        => true,
            'diagnosis' => anchor_cache_governor_diagnose_url( $url ),
        )
    );
}

function anchor_cache_governor_register_rest_routes() {
    register_rest_route(
        'anchor/v1',
        '/cache-governor/status',
        array(
            'methods'             => 'GET',
            'callback'            => 'anchor_cache_governor_rest_status',
            'permission_callback' => 'anchor_cache_governor_rest_permission',
        )
    );

    register_rest_route(
        'anchor/v1',
        '/cache-governor/diagnose-url',
        array(
            'methods'             => 'GET',
            'callback'            => 'anchor_cache_governor_rest_diagnose_url',
            'permission_callback' => 'anchor_cache_governor_rest_permission',
            'args'                => array(
                'url' => array(
                    'required'          => true,
                    'sanitize_callback' => 'esc_url_raw',
                ),
            ),
        )
    );

    foreach ( array( 'enable', 'disable', 'apply-wpo-profile', 'clear-wpo-preload', 'set-profile', 'enqueue-default-warm', 'run-warm-step', 'scan-stale-cache', 'clear-history' ) as $action ) {
        register_rest_route(
            'anchor/v1',
            '/cache-governor/' . $action,
            array(
                'methods'             => 'POST',
                'callback'            => 'anchor_cache_governor_rest_' . str_replace( '-', '_', $action ),
                'permission_callback' => 'anchor_cache_governor_rest_permission',
            )
        );
    }
}
add_action( 'rest_api_init', 'anchor_cache_governor_register_rest_routes' );

function anchor_cache_governor_handle_admin_action( $posted_action ) {
    $notices = array();

    switch ( $posted_action ) {
        case 'cache_governor_save_profile':
            $profile = isset( $_POST['anchor_cache_governor_profile'] )
                ? sanitize_key( wp_unslash( $_POST['anchor_cache_governor_profile'] ) )
                : '';
            $result = anchor_cache_governor_set_profile( $profile );
            if ( is_wp_error( $result ) ) {
                $notices[] = array(
                    'type'    => 'error',
                    'message' => $result->get_error_message(),
                );
                break;
            }

            $message = 'Cache Governor profile saved.';
            if ( anchor_cache_governor_is_enabled() && anchor_cache_governor_is_wp_optimize_active() ) {
                $wpo = anchor_cache_governor_apply_wpo_conservative_profile();
                if ( ! empty( $wpo['ok'] ) ) {
                    $message .= ' WPO profile was reapplied.';
                }
            }

            $notices[] = array(
                'type'    => 'success',
                'message' => $message,
            );
            break;

        case 'cache_governor_enqueue_default_warm':
            if ( ! anchor_cache_governor_is_enabled() || ! anchor_cache_governor_is_wp_optimize_active() ) {
                $notices[] = array(
                    'type'    => 'error',
                    'message' => 'Enable Cache Governor on a WP-Optimize site before queueing warm URLs.',
                );
                break;
            }

            $result = anchor_cache_governor_enqueue_default_warm_urls();
            $notices[] = array(
                'type'    => 'success',
                'message' => sprintf(
                    'Queued %1$d URL(s) for conservative warming. Queue length: %2$d.',
                    (int) $result['added'],
                    (int) $result['queue_length']
                ),
            );
            break;

        case 'cache_governor_run_warm_step':
            $result = anchor_cache_governor_run_warm_step( true );
            $notices[] = array(
                'type'    => ! empty( $result['ok'] ) ? 'success' : 'error',
                'message' => ! empty( $result['url'] )
                    ? sprintf(
                        'Warm step for %1$s finished with status %2$d. Queue length: %3$d.',
                        $result['url'],
                        (int) ( $result['status'] ?? 0 ),
                        (int) ( $result['queue_length'] ?? 0 )
                    )
                    : (string) ( $result['message'] ?? 'Warm step finished.' ),
            );
            break;

        case 'cache_governor_scan_stale_cache':
            $result = anchor_cache_governor_run_stale_scan( true );
            $queued = is_array( $result['queued'] ?? null ) ? $result['queued'] : array();
            $scan   = is_array( $result['scan'] ?? null ) ? $result['scan'] : array();
            $notices[] = array(
                'type'    => ! empty( $result['ok'] ) ? 'success' : 'error',
                'message' => ! empty( $result['ok'] )
                    ? sprintf(
                        'Stale cache scan checked %1$d file(s), found %2$d stale URL(s), and queued %3$d.',
                        (int) ( $scan['scanned_files'] ?? 0 ),
                        (int) ( $scan['found_count'] ?? 0 ),
                        (int) ( $queued['added'] ?? 0 )
                    )
                    : (string) ( $result['message'] ?? 'Stale cache scan failed.' ),
            );
            break;

        case 'cache_governor_clear_history':
            anchor_cache_governor_clear_event_log();
            $notices[] = array(
                'type'    => 'success',
                'message' => 'Cache Governor history cleared.',
            );
            break;

        case 'cache_governor_diagnose_url':
            $url = isset( $_POST['anchor_cache_governor_diagnose_url'] )
                ? esc_url_raw( wp_unslash( $_POST['anchor_cache_governor_diagnose_url'] ) )
                : '';
            $diagnosis = anchor_cache_governor_diagnose_url( $url );
            $notices[] = array(
                'type'    => ! empty( $diagnosis['cacheable'] ) ? 'success' : 'error',
                'message' => sprintf(
                    'URL diagnosis for %1$s: %2$s %3$s',
                    $diagnosis['url'] ? $diagnosis['url'] : $url,
                    ! empty( $diagnosis['cacheable'] ) ? 'cacheable.' : 'bypassed.',
                    implode( ' ', (array) $diagnosis['reasons'] )
                ),
            );
            break;
    }

    return $notices;
}

function anchor_cache_governor_render_admin_section( $nonce_action, $nonce_name ) {
    $diagnostics = anchor_cache_governor_get_diagnostics();
    $disabled    = $diagnostics['wpo_active'] ? '' : ' disabled';
    $toggle_disabled = ( ! $diagnostics['wpo_active'] && ! $diagnostics['governor_enabled'] ) ? ' disabled' : '';
    $warm_disabled = ( $diagnostics['governor_enabled'] && $diagnostics['wpo_active'] ) ? '' : ' disabled';
    $profiles = anchor_cache_governor_get_profiles();
    $warm = $diagnostics['warm_queue'];
    $warm_stats = is_array( $warm['stats'] ?? null ) ? $warm['stats'] : array();
    $stale_scan = is_array( $diagnostics['stale_scan'] ?? null ) ? $diagnostics['stale_scan'] : array();
    $stale_stats = is_array( $stale_scan['stats'] ?? null ) ? $stale_scan['stats'] : array();
    $recent_events = is_array( $diagnostics['recent_events'] ?? null ) ? $diagnostics['recent_events'] : array();
    $cache_exceptions = is_array( $diagnostics['cache_exceptions'] ?? null ) ? $diagnostics['cache_exceptions'] : array();

    echo '<div class="anchor-section">';
    echo '<h2>Cache Governor</h2>';

    echo '<div class="anchor-kv"><strong>Status:</strong> ' . ( $diagnostics['governor_enabled'] ? '<span style="color:#1e8e3e;">Enabled</span>' : '<span style="color:#b32d2e;">Disabled</span>' ) . '</div>';
    echo '<div class="anchor-kv"><strong>Profile:</strong> ' . esc_html( $diagnostics['profile_label'] ) . '</div>';
    echo '<div class="anchor-kv"><strong>WP-Optimize detected:</strong> ' . ( $diagnostics['wpo_active'] ? 'Yes' : 'No' ) . '</div>';
    echo '<div class="anchor-kv"><strong>advanced-cache.php present:</strong> ' . ( $diagnostics['advanced_cache_file'] ? 'Yes' : 'No' ) . '</div>';

    echo '<form method="post" action="" style="margin: 12px 0;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="cache_governor_save_profile">';
    echo '<label for="anchor-cache-governor-profile"><strong>Site profile:</strong> </label>';
    echo '<select id="anchor-cache-governor-profile" name="anchor_cache_governor_profile">';
    foreach ( $profiles as $profile_key => $profile ) {
        echo '<option value="' . esc_attr( $profile_key ) . '"' . selected( $diagnostics['profile'], $profile_key, false ) . '>' . esc_html( (string) ( $profile['label'] ?? $profile_key ) ) . '</option>';
    }
    echo '</select> ';
    echo '<input type="submit" class="button button-secondary" value="Save Profile">';
    echo '</form>';

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

    echo '<form method="post" action="" style="display:inline-block; margin-left: 12px; margin-right: 12px;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="cache_governor_enqueue_default_warm">';
    echo '<input type="submit" class="button button-secondary" value="Queue Standard Warm URLs"' . $warm_disabled . '>';
    echo '</form>';

    echo '<form method="post" action="" style="display:inline-block;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="cache_governor_run_warm_step">';
    echo '<input type="submit" class="button button-secondary" value="Run One Warm Step"' . $warm_disabled . '>';
    echo '</form>';

    echo '<form method="post" action="" style="display:inline-block; margin-left: 12px;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="cache_governor_scan_stale_cache">';
    echo '<input type="submit" class="button button-secondary" value="Scan Stale Cache"' . $warm_disabled . '>';
    echo '</form>';

    echo '<form method="post" action="" style="display:inline-block; margin-left: 12px;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="cache_governor_clear_history">';
    echo '<input type="submit" class="button button-secondary" value="Clear History">';
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
        echo '<tr><th scope="row">Plugin-aware exceptions</th><td>' . esc_html( implode( ', ', (array) ( $cache_exceptions['plugin_rules']['plugins'] ?? array() ) ) ?: 'None detected' ) . '</td></tr>';
        echo '<tr><th scope="row">WPO exception URLs</th><td><code>' . esc_html( implode( ', ', array_slice( (array) ( $cache_exceptions['urls'] ?? array() ), 0, 12 ) ) ) . '</code></td></tr>';
        echo '<tr><th scope="row">WPO exception cookies</th><td><code>' . esc_html( implode( ', ', array_slice( (array) ( $cache_exceptions['cookies'] ?? array() ), 0, 12 ) ) ) . '</code></td></tr>';
        echo '<tr><th scope="row">Anchor warm queue</th><td>' . esc_html( (string) ( $warm['length'] ?? 0 ) ) . '</td></tr>';
        echo '<tr><th scope="row">Anchor warm stats</th><td>' . esc_html( sprintf( 'Warmed: %1$d; failed: %2$d', (int) ( $warm_stats['warmed_count'] ?? 0 ), (int) ( $warm_stats['failed_count'] ?? 0 ) ) ) . '</td></tr>';
        echo '<tr><th scope="row">Stale refresh threshold</th><td>' . esc_html( sprintf( '%d days', (int) ( $stale_scan['stale_refresh_days'] ?? 0 ) ) ) . '</td></tr>';
        echo '<tr><th scope="row">Stale scan limits</th><td>' . esc_html( sprintf( 'Batch: %1$d URL(s); files per scan: %2$d', (int) ( $stale_scan['batch'] ?? 0 ), (int) ( $stale_scan['file_limit'] ?? 0 ) ) ) . '</td></tr>';
        echo '<tr><th scope="row">Stale scan root</th><td><code>' . esc_html( (string) ( $stale_scan['root'] ?? '' ) ) . '</code> ' . esc_html( ! empty( $stale_scan['root_exists'] ) ? 'found' : 'not found yet' ) . '</td></tr>';
        echo '<tr><th scope="row">Last stale scan</th><td>' . esc_html( sprintf( 'Checked: %1$d; found: %2$d; queued: %3$d', (int) ( $stale_stats['last_scanned_files'] ?? 0 ), (int) ( $stale_stats['last_found_count'] ?? 0 ), (int) ( $stale_stats['last_enqueued'] ?? 0 ) ) ) . '</td></tr>';
        if ( ! empty( $warm_stats['last_url'] ) ) {
            echo '<tr><th scope="row">Last warmed URL</th><td><code>' . esc_html( (string) $warm_stats['last_url'] ) . '</code> status ' . esc_html( (string) ( $warm_stats['last_status'] ?? 0 ) ) . '</td></tr>';
        }
        if ( ! empty( $warm_stats['paused_until'] ) && (int) $warm_stats['paused_until'] > time() ) {
            $pause_str = function_exists( 'wp_date' )
                ? wp_date( 'Y-m-d H:i:s T', (int) $warm_stats['paused_until'] )
                : date_i18n( 'Y-m-d H:i:s T', (int) $warm_stats['paused_until'] );
            echo '<tr><th scope="row">Warm queue paused until</th><td>' . esc_html( $pause_str ) . '</td></tr>';
        }
        if ( ! empty( $stale_scan['next_scheduled'] ) ) {
            $next_scan_str = function_exists( 'wp_date' )
                ? wp_date( 'Y-m-d H:i:s T', (int) $stale_scan['next_scheduled'] )
                : date_i18n( 'Y-m-d H:i:s T', (int) $stale_scan['next_scheduled'] );
            echo '<tr><th scope="row">Next stale scan</th><td>' . esc_html( $next_scan_str ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    if ( ! empty( $recent_events ) ) {
        echo '<h3>Recent Cache Governor Events</h3>';
        echo '<table class="widefat striped" style="max-width: 1100px; margin-top: 8px;">';
        echo '<thead><tr><th scope="col">Time</th><th scope="col">Event</th><th scope="col">Context</th></tr></thead>';
        echo '<tbody>';
        foreach ( $recent_events as $event ) {
            $time = (int) ( $event['time'] ?? 0 );
            $time_str = $time > 0
                ? ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s T', $time ) : date_i18n( 'Y-m-d H:i:s T', $time ) )
                : '';
            $context = isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : array();
            echo '<tr>';
            echo '<td>' . esc_html( $time_str ) . '</td>';
            echo '<td><code>' . esc_html( (string) ( $event['event'] ?? '' ) ) . '</code></td>';
            echo '<td><code>' . esc_html( wp_json_encode( $context ) ) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<form method="post" action="" style="margin-top: 14px; max-width: 900px;">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="cache_governor_diagnose_url">';
    echo '<label for="anchor-cache-governor-diagnose-url"><strong>URL diagnostics:</strong> </label>';
    echo '<input id="anchor-cache-governor-diagnose-url" type="url" name="anchor_cache_governor_diagnose_url" class="regular-text" placeholder="' . esc_attr( home_url( '/' ) ) . '"> ';
    echo '<input type="submit" class="button button-secondary" value="Diagnose URL">';
    echo '</form>';

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

    echo '<p style="max-width: 1100px;"><em>Notes:</em> Enabling the governor keeps WP-Optimize on a conservative profile: no preload-after-purge, no scheduled/sitemap preload, no separate mobile cache, and a longer cache lifespan. The Anchor warm queue handles one URL per run and backs off after failures. The stale scanner only queues a small batch of older WP-Optimize HTML files for that same slow warm queue. It does not replace WP-Optimize or enable page caching on sites where page caching is off.</p>';
    echo '</div>';
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI_Command' ) ) {
    /**
     * WP-CLI controls for Anchor Cache Governor.
     */
    class Anchor_Cache_Governor_CLI_Command extends WP_CLI_Command {
        private function print_json( $value ) {
            WP_CLI::line( wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        }

        /**
         * Show Cache Governor diagnostics.
         */
        public function status() {
            $this->print_json( anchor_cache_governor_get_diagnostics() );
        }

        /**
         * Enable Cache Governor and apply the current profile.
         */
        public function enable() {
            if ( ! anchor_cache_governor_is_wp_optimize_active() ) {
                WP_CLI::error( 'WP-Optimize is not active on this site.' );
            }

            update_option( ANCHOR_CACHE_GOVERNOR_OPTION_ENABLED, '1', false );
            anchor_cache_governor_log_event( 'governor_enabled', array( 'source' => 'cli' ) );
            $this->print_json( anchor_cache_governor_apply_wpo_conservative_profile() );
        }

        /**
         * Disable Cache Governor.
         */
        public function disable() {
            update_option( ANCHOR_CACHE_GOVERNOR_OPTION_ENABLED, '0', false );
            wp_clear_scheduled_hook( ANCHOR_CACHE_GOVERNOR_STALE_SCAN_HOOK );
            anchor_cache_governor_log_event( 'governor_disabled', array( 'source' => 'cli' ) );
            WP_CLI::success( 'Cache Governor disabled.' );
        }

        /**
         * Set the Cache Governor profile.
         *
         * ## OPTIONS
         *
         * <profile>
         * : Profile key, such as conservative, archive, or brochure.
         */
        public function set_profile( $args ) {
            $profile = anchor_cache_governor_set_profile( $args[0] ?? '' );
            if ( is_wp_error( $profile ) ) {
                WP_CLI::error( $profile->get_error_message() );
            }

            WP_CLI::success( 'Profile set to ' . $profile . '.' );
        }

        /**
         * Apply the current conservative WPO profile.
         */
        public function apply_wpo_profile() {
            $this->print_json( anchor_cache_governor_apply_wpo_conservative_profile() );
        }

        /**
         * Queue standard warm URLs for the current profile.
         */
        public function enqueue_default_warm() {
            if ( ! anchor_cache_governor_is_enabled() || ! anchor_cache_governor_is_wp_optimize_active() ) {
                WP_CLI::error( 'Enable Cache Governor on a WP-Optimize site before queueing warm URLs.' );
            }

            $this->print_json( anchor_cache_governor_enqueue_default_warm_urls() );
        }

        /**
         * Run one warm queue step now.
         */
        public function run_warm_step() {
            $this->print_json( anchor_cache_governor_run_warm_step( true ) );
        }

        /**
         * Scan WP-Optimize cache files and queue stale URLs for slow warming.
         */
        public function scan_stale_cache() {
            $this->print_json( anchor_cache_governor_run_stale_scan( true ) );
        }

        /**
         * Show recent Cache Governor events.
         *
         * ## OPTIONS
         *
         * [--limit=<limit>]
         * : Number of events to print. Default: 20.
         */
        public function history( $args, $assoc_args ) {
            unset( $args );

            $limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
            $this->print_json( anchor_cache_governor_get_event_log( $limit ) );
        }

        /**
         * Clear Cache Governor event history.
         */
        public function clear_history() {
            anchor_cache_governor_clear_event_log();
            WP_CLI::success( 'Cache Governor history cleared.' );
        }

        /**
         * Diagnose whether a URL is safe for warming.
         *
         * ## OPTIONS
         *
         * <url>
         * : Absolute site URL or root-relative path.
         */
        public function diagnose_url( $args ) {
            $this->print_json( anchor_cache_governor_diagnose_url( $args[0] ?? '' ) );
        }
    }

    WP_CLI::add_command( 'anchor cache-governor', 'Anchor_Cache_Governor_CLI_Command' );
}
