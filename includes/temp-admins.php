<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_MARKER_META' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_MARKER_META', 'anchor_temp_admin_marker' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_EXPIRES_META' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_EXPIRES_META', 'anchor_temp_admin_expires_at' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_CREATED_AT_META' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_CREATED_AT_META', 'anchor_temp_admin_created_at' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_CREATED_BY_META' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_CREATED_BY_META', 'anchor_temp_admin_created_by' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_LABEL_META' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_LABEL_META', 'anchor_temp_admin_label' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_WORDFENCE_ALLOWLIST_IP_META' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_WORDFENCE_ALLOWLIST_IP_META', 'anchor_temp_admin_wordfence_allowlist_ip' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_WORDFENCE_FIREWALL_ADDED_META' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_WORDFENCE_FIREWALL_ADDED_META', 'anchor_temp_admin_wordfence_firewall_added' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_WORDFENCE_LOGIN_ADDED_META' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_WORDFENCE_LOGIN_ADDED_META', 'anchor_temp_admin_wordfence_login_added' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_LAST_LOGIN_META' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_LAST_LOGIN_META', 'anchor_temp_admin_last_login_at' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_LOG_OPTION' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_LOG_OPTION', 'anchor_temp_admin_audit_log' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_LAST_CLEANUP_OPTION' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_LAST_CLEANUP_OPTION', 'anchor_temp_admin_last_cleanup_at' );
}

if ( ! defined( 'ANCHOR_TEMP_ADMIN_CLEANUP_HOOK' ) ) {
    define( 'ANCHOR_TEMP_ADMIN_CLEANUP_HOOK', 'anchor_cleanup_temp_admins' );
}

function anchor_temp_admin_default_hours() {
    return 24;
}

function anchor_temp_admin_max_hours() {
    return 168;
}

function anchor_temp_admin_log_limit() {
    return 200;
}

function anchor_temp_admin_notice_limit() {
    return 50;
}

function anchor_format_datetime( $timestamp ) {
    $timestamp = (int) $timestamp;

    if ( $timestamp <= 0 ) {
        return 'Never';
    }

    return function_exists( 'wp_date' )
        ? wp_date( 'Y-m-d H:i:s T', $timestamp )
        : date_i18n( 'Y-m-d H:i:s T', $timestamp );
}

function anchor_get_temp_admin_user( $user = null ) {
    if ( $user instanceof WP_User ) {
        return $user;
    }

    if ( is_numeric( $user ) && (int) $user > 0 ) {
        return get_userdata( (int) $user );
    }

    if ( null === $user && is_user_logged_in() ) {
        return wp_get_current_user();
    }

    return null;
}

function anchor_is_temp_admin_user( $user = null ) {
    $user = anchor_get_temp_admin_user( $user );

    if ( ! $user instanceof WP_User || empty( $user->ID ) ) {
        return false;
    }

    return get_user_meta( $user->ID, ANCHOR_TEMP_ADMIN_MARKER_META, true ) === '1';
}

function anchor_get_temp_admin_expiration( $user_id ) {
    return (int) get_user_meta( (int) $user_id, ANCHOR_TEMP_ADMIN_EXPIRES_META, true );
}

function anchor_get_temp_admin_created_at( $user_id ) {
    return (int) get_user_meta( (int) $user_id, ANCHOR_TEMP_ADMIN_CREATED_AT_META, true );
}

function anchor_get_temp_admin_last_login( $user_id ) {
    return (int) get_user_meta( (int) $user_id, ANCHOR_TEMP_ADMIN_LAST_LOGIN_META, true );
}

function anchor_get_temp_admin_label( $user_id ) {
    return (string) get_user_meta( (int) $user_id, ANCHOR_TEMP_ADMIN_LABEL_META, true );
}

function anchor_temp_admin_is_expired( $user ) {
    $user = anchor_get_temp_admin_user( $user );

    if ( ! anchor_is_temp_admin_user( $user ) ) {
        return false;
    }

    $expires_at = anchor_get_temp_admin_expiration( $user->ID );

    return $expires_at > 0 && $expires_at <= time();
}

function anchor_temp_admin_request_ip() {
    if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
        return '';
    }

    return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
}

function anchor_temp_admin_wordfence_available() {
    return class_exists( 'wordfence' ) && method_exists( 'wordfence', 'whitelistIP' );
}

function anchor_temp_admin_wordfence_login_settings_class() {
    $class = 'WordfenceLS\\Controller_Settings';

    return class_exists( $class ) ? $class : '';
}

function anchor_temp_admin_wordfence_login_whitelist_class() {
    $class = 'WordfenceLS\\Controller_Whitelist';

    return class_exists( $class ) ? $class : '';
}

function anchor_temp_admin_wordfence_login_option_name() {
    $settings_class = anchor_temp_admin_wordfence_login_settings_class();
    $constant       = $settings_class ? $settings_class . '::OPTION_2FA_WHITELISTED' : '';

    return $constant && defined( $constant ) ? constant( $constant ) : 'whitelisted';
}

function anchor_temp_admin_wordfence_detect_current_ip() {
    $ip = '';

    if ( class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'getIP' ) ) {
        try {
            $ip = wfUtils::getIP();
        } catch ( Exception $e ) {
            $ip = '';
        }
    }

    if ( ! is_string( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        $ip = anchor_temp_admin_request_ip();
    }

    return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

function anchor_temp_admin_wordfence_normalize_ip_entry( $entry ) {
    $entry = sanitize_text_field( (string) $entry );
    $entry = preg_replace( '/[,\r\n\t]+/', '', $entry );
    $entry = trim( (string) $entry );

    if ( class_exists( 'wfUserIPRange' ) ) {
        try {
            $range = new wfUserIPRange( $entry );
            if ( method_exists( $range, 'getIPString' ) ) {
                $normalized = $range->getIPString();

                return is_string( $normalized ) ? $normalized : $entry;
            }
        } catch ( Exception $e ) {
            return $entry;
        }
    }

    return strtolower( preg_replace( '/\s+/', '', $entry ) );
}

function anchor_temp_admin_wordfence_validate_ip_entry( $entry ) {
    $entry = sanitize_text_field( (string) $entry );
    $entry = trim( $entry );

    if ( '' === $entry ) {
        return new WP_Error( 'anchor_wordfence_ip_empty', 'No IP address was provided for Wordfence allowlisting.' );
    }

    if ( preg_match( '/[,\r\n]/', $entry ) ) {
        return new WP_Error( 'anchor_wordfence_ip_multiple', 'Enter one IP address or IP range for Wordfence allowlisting.' );
    }

    if ( class_exists( 'wfUserIPRange' ) ) {
        try {
            $range = new wfUserIPRange( $entry );
            if ( method_exists( $range, 'isValidRange' ) && $range->isValidRange() ) {
                return anchor_temp_admin_wordfence_normalize_ip_entry( $entry );
            }
        } catch ( Exception $e ) {
            return new WP_Error( 'anchor_wordfence_ip_invalid', $e->getMessage() );
        }
    }

    $normalized = anchor_temp_admin_wordfence_normalize_ip_entry( $entry );
    if ( filter_var( $normalized, FILTER_VALIDATE_IP ) ) {
        return $normalized;
    }

    return new WP_Error( 'anchor_wordfence_ip_invalid', 'The Wordfence allowlist value must be a valid IP address or IP range.' );
}

function anchor_temp_admin_wordfence_firewall_entries() {
    if ( ! class_exists( 'wfConfig' ) || ! method_exists( 'wfConfig', 'get' ) ) {
        return null;
    }

    try {
        $raw = wfConfig::get( 'whitelisted', '' );
    } catch ( Exception $e ) {
        return null;
    }

    if ( is_array( $raw ) ) {
        $entries = $raw;
    } else {
        $entries = preg_split( '/[\r\n,]+/', (string) $raw );
    }

    return array_values(
        array_filter(
            array_map(
                'trim',
                $entries
            ),
            'strlen'
        )
    );
}

function anchor_temp_admin_wordfence_login_entries() {
    $settings_class = anchor_temp_admin_wordfence_login_settings_class();

    if ( ! $settings_class || ! method_exists( $settings_class, 'shared' ) ) {
        return null;
    }

    try {
        $settings = $settings_class::shared();

        if ( ! is_object( $settings ) || ! method_exists( $settings, 'whitelisted_ips' ) ) {
            return null;
        }

        $entries = $settings->whitelisted_ips();
    } catch ( Exception $e ) {
        return null;
    }

    return is_array( $entries ) ? array_values( array_filter( array_map( 'trim', $entries ), 'strlen' ) ) : array();
}

function anchor_temp_admin_wordfence_entry_contains_ip( $entry, $ip ) {
    $entry = trim( (string) $entry );
    $ip    = trim( (string) $ip );

    if ( '' === $entry || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        return false;
    }

    if ( class_exists( 'wfUserIPRange' ) ) {
        try {
            $range = new wfUserIPRange( $entry );
            if ( method_exists( $range, 'isValidRange' ) && $range->isValidRange() && method_exists( $range, 'isIPInRange' ) ) {
                return (bool) $range->isIPInRange( $ip );
            }
        } catch ( Exception $e ) {
            return false;
        }
    }

    $whitelist_class = anchor_temp_admin_wordfence_login_whitelist_class();
    if ( $whitelist_class && method_exists( $whitelist_class, 'shared' ) ) {
        try {
            $whitelist = $whitelist_class::shared();
            if ( is_object( $whitelist ) && method_exists( $whitelist, 'ip_in_range' ) ) {
                return (bool) $whitelist->ip_in_range( $ip, $entry );
            }
        } catch ( Exception $e ) {
            return false;
        }
    }

    return anchor_temp_admin_wordfence_normalize_ip_entry( $entry ) === anchor_temp_admin_wordfence_normalize_ip_entry( $ip );
}

function anchor_temp_admin_wordfence_entries_contain_ip( $entries, $ip ) {
    if ( ! is_array( $entries ) ) {
        return null;
    }

    foreach ( $entries as $entry ) {
        if ( anchor_temp_admin_wordfence_entry_contains_ip( $entry, $ip ) ) {
            return true;
        }
    }

    return false;
}

function anchor_temp_admin_wordfence_allowlist_status( $ip = '' ) {
    $ip = $ip ? sanitize_text_field( (string) $ip ) : anchor_temp_admin_wordfence_detect_current_ip();

    $firewall_entries     = anchor_temp_admin_wordfence_firewall_entries();
    $login_entries        = anchor_temp_admin_wordfence_login_entries();
    $firewall_readable    = is_array( $firewall_entries );
    $login_module_present = (bool) anchor_temp_admin_wordfence_login_settings_class();
    $login_readable       = is_array( $login_entries );
    $firewall_allowlisted = $firewall_readable ? anchor_temp_admin_wordfence_entries_contain_ip( $firewall_entries, $ip ) : null;
    $login_allowlisted    = $login_readable ? anchor_temp_admin_wordfence_entries_contain_ip( $login_entries, $ip ) : null;
    $already_allowlisted  = false;

    if ( $firewall_readable && true === $firewall_allowlisted ) {
        $already_allowlisted = ! $login_module_present || ( $login_readable && true === $login_allowlisted );
    }

    return array(
        'available'             => anchor_temp_admin_wordfence_available(),
        'ip'                    => $ip,
        'firewall_readable'     => $firewall_readable,
        'firewall_allowlisted'  => $firewall_allowlisted,
        'login_module_present'  => $login_module_present,
        'login_readable'        => $login_readable,
        'login_allowlisted'     => $login_allowlisted,
        'already_allowlisted'   => $already_allowlisted,
        'needs_allowlist_entry' => ! $already_allowlisted,
    );
}

function anchor_temp_admin_wordfence_sync_firewall_allowlist() {
    if ( ! class_exists( 'wfConfig' ) || ! method_exists( 'wfConfig', 'get' ) ) {
        return false;
    }

    try {
        $allowlist = (string) wfConfig::get( 'whitelisted', '' );

        if ( class_exists( 'wfWAF' ) && method_exists( 'wfWAF', 'getInstance' ) ) {
            $waf = wfWAF::getInstance();
            if ( is_object( $waf ) && method_exists( $waf, 'getStorageEngine' ) ) {
                $storage = $waf->getStorageEngine();

                if ( is_object( $storage ) && method_exists( $storage, 'setConfig' ) ) {
                    $storage->setConfig( 'whitelistedIPs', $allowlist, 'synced' );
                }

                if (
                    is_object( $storage )
                    && method_exists( $storage, 'purgeIPBlocks' )
                    && ( class_exists( 'wfWAFStorageInterface' ) || interface_exists( 'wfWAFStorageInterface' ) )
                    && defined( 'wfWAFStorageInterface::IP_BLOCKS_BLACKLIST' )
                ) {
                    $storage->purgeIPBlocks( wfWAFStorageInterface::IP_BLOCKS_BLACKLIST );
                }
            }
        }

        if ( class_exists( 'wfWAFIPBlocksController' ) && method_exists( 'wfWAFIPBlocksController', 'setNeedsSynchronizeConfigSettings' ) ) {
            wfWAFIPBlocksController::setNeedsSynchronizeConfigSettings();
        }
    } catch ( Exception $e ) {
        return false;
    }

    return true;
}

function anchor_temp_admin_wordfence_unblock_ip( $entry ) {
    if ( ! filter_var( $entry, FILTER_VALIDATE_IP ) ) {
        return;
    }

    try {
        if ( class_exists( 'wfBlock' ) && method_exists( 'wfBlock', 'unblockIP' ) ) {
            wfBlock::unblockIP( $entry, false );
        }

        if ( class_exists( 'wordfence' ) && method_exists( 'wordfence', 'clearLockoutCounters' ) ) {
            wordfence::clearLockoutCounters( $entry );
        }
    } catch ( Exception $e ) {
        return;
    }
}

function anchor_temp_admin_wordfence_allowlist_login_security( $entry ) {
    $settings_class = anchor_temp_admin_wordfence_login_settings_class();

    if ( ! $settings_class || ! method_exists( $settings_class, 'shared' ) ) {
        return null;
    }

    try {
        $settings = $settings_class::shared();
        if ( ! is_object( $settings ) || ! method_exists( $settings, 'set' ) || ! method_exists( $settings, 'whitelisted_ips' ) ) {
            return null;
        }

        $entries = $settings->whitelisted_ips();
        if ( ! is_array( $entries ) ) {
            $entries = array();
        }

        if ( filter_var( $entry, FILTER_VALIDATE_IP ) && true === anchor_temp_admin_wordfence_entries_contain_ip( $entries, $entry ) ) {
            return false;
        }

        $normalized_entry = anchor_temp_admin_wordfence_normalize_ip_entry( $entry );
        foreach ( $entries as $existing_entry ) {
            if ( anchor_temp_admin_wordfence_normalize_ip_entry( $existing_entry ) === $normalized_entry ) {
                return false;
            }
        }

        $entries[] = $normalized_entry;

        return (bool) $settings->set( anchor_temp_admin_wordfence_login_option_name(), implode( "\n", array_unique( $entries ) ) );
    } catch ( Exception $e ) {
        return null;
    }
}

function anchor_temp_admin_wordfence_allowlist_ip( $entry ) {
    if ( ! anchor_temp_admin_wordfence_available() ) {
        return new WP_Error( 'anchor_wordfence_unavailable', 'Wordfence is not active or its allowlist API is unavailable.' );
    }

    $entry = anchor_temp_admin_wordfence_validate_ip_entry( $entry );
    if ( is_wp_error( $entry ) ) {
        return $entry;
    }

    try {
        $firewall_added = (bool) wordfence::whitelistIP( $entry );
    } catch ( Exception $e ) {
        return new WP_Error( 'anchor_wordfence_allowlist_failed', $e->getMessage() );
    }

    anchor_temp_admin_wordfence_sync_firewall_allowlist();
    anchor_temp_admin_wordfence_unblock_ip( $entry );

    $login_added = anchor_temp_admin_wordfence_allowlist_login_security( $entry );

    return array(
        'ip'             => $entry,
        'firewall_added' => $firewall_added,
        'login_added'    => $login_added,
    );
}

function anchor_temp_admin_wordfence_remove_firewall_entry( $entry ) {
    if ( ! class_exists( 'wfConfig' ) || ! method_exists( 'wfConfig', 'set' ) ) {
        return false;
    }

    $entries = anchor_temp_admin_wordfence_firewall_entries();
    if ( ! is_array( $entries ) ) {
        return false;
    }

    $normalized_entry = anchor_temp_admin_wordfence_normalize_ip_entry( $entry );
    $new_entries      = array();
    $removed          = false;

    foreach ( $entries as $existing_entry ) {
        if ( anchor_temp_admin_wordfence_normalize_ip_entry( $existing_entry ) === $normalized_entry ) {
            $removed = true;
            continue;
        }

        $new_entries[] = $existing_entry;
    }

    if ( $removed ) {
        wfConfig::set( 'whitelisted', implode( ',', $new_entries ) );
        anchor_temp_admin_wordfence_sync_firewall_allowlist();
    }

    return $removed;
}

function anchor_temp_admin_wordfence_remove_login_entry( $entry ) {
    $settings_class = anchor_temp_admin_wordfence_login_settings_class();

    if ( ! $settings_class || ! method_exists( $settings_class, 'shared' ) ) {
        return false;
    }

    try {
        $settings = $settings_class::shared();
        if ( ! is_object( $settings ) || ! method_exists( $settings, 'set' ) || ! method_exists( $settings, 'whitelisted_ips' ) ) {
            return false;
        }

        $entries          = $settings->whitelisted_ips();
        $normalized_entry = anchor_temp_admin_wordfence_normalize_ip_entry( $entry );
        $new_entries      = array();
        $removed          = false;

        foreach ( (array) $entries as $existing_entry ) {
            if ( anchor_temp_admin_wordfence_normalize_ip_entry( $existing_entry ) === $normalized_entry ) {
                $removed = true;
                continue;
            }

            $new_entries[] = $existing_entry;
        }

        if ( $removed ) {
            $settings->set( anchor_temp_admin_wordfence_login_option_name(), implode( "\n", $new_entries ) );
        }

        return $removed;
    } catch ( Exception $e ) {
        return false;
    }
}

function anchor_temp_admin_wordfence_other_temp_admin_uses_ip( $ip, $added_meta_key, $exclude_user_id ) {
    foreach ( anchor_temp_admin_get_users() as $user ) {
        if ( (int) $user->ID === (int) $exclude_user_id ) {
            continue;
        }

        if (
            get_user_meta( $user->ID, ANCHOR_TEMP_ADMIN_WORDFENCE_ALLOWLIST_IP_META, true ) === $ip
            && get_user_meta( $user->ID, $added_meta_key, true ) === '1'
        ) {
            return true;
        }
    }

    return false;
}

function anchor_temp_admin_wordfence_cleanup_for_user( $user ) {
    $user = anchor_get_temp_admin_user( $user );
    if ( ! $user instanceof WP_User ) {
        return;
    }

    $ip = get_user_meta( $user->ID, ANCHOR_TEMP_ADMIN_WORDFENCE_ALLOWLIST_IP_META, true );
    if ( '' === $ip ) {
        return;
    }

    $removed = array();

    if (
        get_user_meta( $user->ID, ANCHOR_TEMP_ADMIN_WORDFENCE_FIREWALL_ADDED_META, true ) === '1'
        && ! anchor_temp_admin_wordfence_other_temp_admin_uses_ip( $ip, ANCHOR_TEMP_ADMIN_WORDFENCE_FIREWALL_ADDED_META, $user->ID )
        && anchor_temp_admin_wordfence_remove_firewall_entry( $ip )
    ) {
        $removed[] = 'firewall';
    }

    if (
        get_user_meta( $user->ID, ANCHOR_TEMP_ADMIN_WORDFENCE_LOGIN_ADDED_META, true ) === '1'
        && ! anchor_temp_admin_wordfence_other_temp_admin_uses_ip( $ip, ANCHOR_TEMP_ADMIN_WORDFENCE_LOGIN_ADDED_META, $user->ID )
        && anchor_temp_admin_wordfence_remove_login_entry( $ip )
    ) {
        $removed[] = 'login security';
    }

    if ( ! empty( $removed ) ) {
        anchor_log_temp_admin_event(
            'wordfence_ip_allowlist_removed',
            sprintf(
                'Removed Wordfence %1$s allowlist entry %2$s for temp admin %3$s.',
                implode( ' and ', $removed ),
                $ip,
                $user->user_login
            ),
            array(
                'subject_user_id'    => $user->ID,
                'subject_user_login' => $user->user_login,
                'ip'                 => $ip,
            )
        );
    }
}

function anchor_temp_admin_sanitized_request_uri() {
    if ( empty( $_SERVER['REQUEST_URI'] ) ) {
        return '';
    }

    $raw_url = wp_unslash( $_SERVER['REQUEST_URI'] );
    $parts   = wp_parse_url( $raw_url );

    if ( empty( $parts['path'] ) ) {
        return '';
    }

    $path = sanitize_text_field( $parts['path'] );
    $uri  = $path;

    if ( ! empty( $parts['query'] ) ) {
        parse_str( $parts['query'], $query_args );

        $sensitive_keys = array(
            '_wpnonce',
            '_ajax_nonce',
            'nonce',
            'password',
            'pass1',
            'pass2',
            'new_pass',
            'redirect_to',
            '_wp_http_referer',
        );

        foreach ( $sensitive_keys as $sensitive_key ) {
            unset( $query_args[ $sensitive_key ] );
        }

        foreach ( $query_args as $key => $value ) {
            if ( is_array( $value ) ) {
                $query_args[ $key ] = '[array]';
                continue;
            }

            $query_args[ $key ] = sanitize_text_field( (string) $value );
        }

        if ( ! empty( $query_args ) ) {
            $uri .= '?' . http_build_query( $query_args, '', '&' );
        }
    }

    return substr( $uri, 0, 300 );
}

function anchor_temp_admin_summary_text( $summary ) {
    $summary = sanitize_text_field( (string) $summary );

    return substr( $summary, 0, 500 );
}

function anchor_log_temp_admin_event( $event, $summary, $args = array() ) {
    $event   = sanitize_key( $event );
    $summary = anchor_temp_admin_summary_text( $summary );

    if ( '' === $event || '' === $summary ) {
        return;
    }

    $actor_user = null;
    if ( isset( $args['actor_user'] ) ) {
        $actor_user = anchor_get_temp_admin_user( $args['actor_user'] );
    } elseif ( is_user_logged_in() ) {
        $actor_user = wp_get_current_user();
    }

    $subject_user = null;
    if ( isset( $args['subject_user'] ) ) {
        $subject_user = anchor_get_temp_admin_user( $args['subject_user'] );
    }

    $entries = get_option( ANCHOR_TEMP_ADMIN_LOG_OPTION, array() );
    if ( ! is_array( $entries ) ) {
        $entries = array();
    }

    $entries[] = array(
        'time'               => time(),
        'event'              => $event,
        'summary'            => $summary,
        'ip'                 => isset( $args['ip'] ) ? sanitize_text_field( (string) $args['ip'] ) : anchor_temp_admin_request_ip(),
        'actor_user_id'      => isset( $args['actor_user_id'] ) ? (int) $args['actor_user_id'] : ( $actor_user instanceof WP_User ? (int) $actor_user->ID : 0 ),
        'actor_user_login'   => isset( $args['actor_user_login'] ) ? sanitize_user( (string) $args['actor_user_login'], true ) : ( $actor_user instanceof WP_User ? $actor_user->user_login : '' ),
        'subject_user_id'    => isset( $args['subject_user_id'] ) ? (int) $args['subject_user_id'] : ( $subject_user instanceof WP_User ? (int) $subject_user->ID : 0 ),
        'subject_user_login' => isset( $args['subject_user_login'] ) ? sanitize_user( (string) $args['subject_user_login'], true ) : ( $subject_user instanceof WP_User ? $subject_user->user_login : '' ),
    );

    if ( count( $entries ) > anchor_temp_admin_log_limit() ) {
        $entries = array_slice( $entries, -1 * anchor_temp_admin_log_limit() );
    }

    update_option( ANCHOR_TEMP_ADMIN_LOG_OPTION, $entries, false );
}

function anchor_temp_admin_recent_log_entries( $limit = null ) {
    $entries = get_option( ANCHOR_TEMP_ADMIN_LOG_OPTION, array() );
    if ( ! is_array( $entries ) ) {
        return array();
    }

    $limit = null === $limit ? anchor_temp_admin_notice_limit() : max( 1, (int) $limit );

    return array_reverse( array_slice( $entries, -1 * $limit ) );
}

function anchor_temp_admin_generate_username() {
    $attempts = 10;

    while ( $attempts-- > 0 ) {
        $suffix   = strtolower( wp_generate_password( 8, false, false ) );
        $username = 'anchor-temp-' . $suffix;

        if ( ! username_exists( $username ) ) {
            return $username;
        }
    }

    return 'anchor-temp-' . strtolower( wp_generate_password( 12, false, false ) );
}

function anchor_temp_admin_add_login_url_candidate( &$candidates, $url ) {
    $url = esc_url_raw( (string) $url );

    if ( '' === $url ) {
        return;
    }

    $candidates[ $url ] = $url;
}

function anchor_temp_admin_detect_login_url() {
    $default_login_url = site_url( 'wp-login.php', 'login' );
    $candidates        = array();

    anchor_temp_admin_add_login_url_candidate( $candidates, wp_login_url() );
    anchor_temp_admin_add_login_url_candidate( $candidates, $default_login_url );

    $wsp_hide_login_slug = get_option( 'whl_page' );
    if ( is_string( $wsp_hide_login_slug ) && '' !== trim( $wsp_hide_login_slug ) ) {
        anchor_temp_admin_add_login_url_candidate( $candidates, home_url( '/' . trim( $wsp_hide_login_slug, '/' ) . '/' ) );
    }

    $rename_wp_login_slug = get_option( 'rename_wp_login_slug' );
    if ( is_string( $rename_wp_login_slug ) && '' !== trim( $rename_wp_login_slug ) ) {
        anchor_temp_admin_add_login_url_candidate( $candidates, home_url( '/' . trim( $rename_wp_login_slug, '/' ) . '/' ) );
    }

    $aio_wp_security_configs = get_option( 'aio_wp_security_configs' );
    if (
        is_array( $aio_wp_security_configs )
        && ! empty( $aio_wp_security_configs['aiowps_login_page_slug'] )
        && is_string( $aio_wp_security_configs['aiowps_login_page_slug'] )
    ) {
        anchor_temp_admin_add_login_url_candidate( $candidates, home_url( '/' . trim( $aio_wp_security_configs['aiowps_login_page_slug'], '/' ) . '/' ) );
    }

    $itsec_hide_backend = get_option( 'itsec_hide_backend' );
    if (
        is_array( $itsec_hide_backend )
        && ! empty( $itsec_hide_backend['enabled'] )
        && ! empty( $itsec_hide_backend['slug'] )
        && is_string( $itsec_hide_backend['slug'] )
    ) {
        anchor_temp_admin_add_login_url_candidate( $candidates, home_url( '/' . trim( $itsec_hide_backend['slug'], '/' ) . '/' ) );
    }

    $extra_candidates = apply_filters( 'anchor_temp_admin_login_url_candidates', array_values( $candidates ) );
    if ( is_array( $extra_candidates ) ) {
        foreach ( $extra_candidates as $extra_candidate ) {
            anchor_temp_admin_add_login_url_candidate( $candidates, $extra_candidate );
        }
    }

    foreach ( $candidates as $candidate ) {
        if ( $candidate !== $default_login_url ) {
            return $candidate;
        }
    }

    return ! empty( $candidates ) ? reset( $candidates ) : $default_login_url;
}

function anchor_temp_admin_credentials_text( $created_account ) {
    if ( ! is_array( $created_account ) ) {
        return '';
    }

    $lines = array(
        'Temporary admin credentials',
        'Site: ' . home_url( '/' ),
        'Login URL: ' . anchor_temp_admin_detect_login_url(),
        'Username: ' . ( isset( $created_account['username'] ) ? $created_account['username'] : '' ),
        'Password: ' . ( isset( $created_account['password'] ) ? $created_account['password'] : '' ),
        'Expires: ' . anchor_format_datetime( isset( $created_account['expires_at'] ) ? $created_account['expires_at'] : 0 ),
    );

    if ( ! empty( $created_account['label'] ) ) {
        $lines[] = 'Label: ' . sanitize_text_field( $created_account['label'] );
    }

    if ( ! empty( $created_account['wordfence_allowlist']['ip'] ) ) {
        $lines[] = 'Wordfence allowlisted IP: ' . sanitize_text_field( $created_account['wordfence_allowlist']['ip'] );
    }

    return implode( "\n", array_map( 'sanitize_text_field', $lines ) );
}

function anchor_temp_admin_create_account( $hours, $label = '', $wordfence_allowlist_ip = '' ) {
    $hours = absint( $hours );
    if ( $hours < 1 ) {
        $hours = anchor_temp_admin_default_hours();
    }

    $hours = min( $hours, anchor_temp_admin_max_hours() );
    $label = substr( sanitize_text_field( $label ), 0, 80 );

    $username   = anchor_temp_admin_generate_username();
    $password   = wp_generate_password( 28, true, true );
    $expires_at = time() + ( $hours * HOUR_IN_SECONDS );
    $email      = $username . '@anchor-temp.invalid';
    $user_id    = wp_insert_user(
        array(
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $email,
            'display_name' => $label ? 'Temp Admin - ' . $label : 'Temp Admin ' . gmdate( 'Y-m-d H:i' ),
            'nickname'     => $username,
            'role'         => 'administrator',
        )
    );

    if ( is_wp_error( $user_id ) ) {
        return $user_id;
    }

    update_user_meta( $user_id, ANCHOR_TEMP_ADMIN_MARKER_META, '1' );
    update_user_meta( $user_id, ANCHOR_TEMP_ADMIN_CREATED_AT_META, time() );
    update_user_meta( $user_id, ANCHOR_TEMP_ADMIN_EXPIRES_META, $expires_at );
    update_user_meta( $user_id, ANCHOR_TEMP_ADMIN_CREATED_BY_META, get_current_user_id() );
    update_user_meta( $user_id, ANCHOR_TEMP_ADMIN_LABEL_META, $label );
    update_user_meta( $user_id, 'show_admin_bar_front', 'false' );

    $wordfence_allowlist = null;
    if ( '' !== $wordfence_allowlist_ip ) {
        $wordfence_allowlist = anchor_temp_admin_wordfence_allowlist_ip( $wordfence_allowlist_ip );

        if ( is_wp_error( $wordfence_allowlist ) ) {
            anchor_log_temp_admin_event(
                'wordfence_ip_allowlist_failed',
                sprintf(
                    'Failed to add Wordfence allowlist entry for temp admin %1$s: %2$s.',
                    $username,
                    $wordfence_allowlist->get_error_message()
                ),
                array(
                    'subject_user_id'    => $user_id,
                    'subject_user_login' => $username,
                )
            );
        } else {
            update_user_meta( $user_id, ANCHOR_TEMP_ADMIN_WORDFENCE_ALLOWLIST_IP_META, $wordfence_allowlist['ip'] );
            update_user_meta( $user_id, ANCHOR_TEMP_ADMIN_WORDFENCE_FIREWALL_ADDED_META, ! empty( $wordfence_allowlist['firewall_added'] ) ? '1' : '0' );
            update_user_meta( $user_id, ANCHOR_TEMP_ADMIN_WORDFENCE_LOGIN_ADDED_META, ! empty( $wordfence_allowlist['login_added'] ) ? '1' : '0' );

            anchor_log_temp_admin_event(
                'wordfence_ip_allowlisted',
                sprintf(
                    'Added Wordfence allowlist entry %1$s for temp admin %2$s.',
                    $wordfence_allowlist['ip'],
                    $username
                ),
                array(
                    'subject_user_id'    => $user_id,
                    'subject_user_login' => $username,
                    'ip'                 => $wordfence_allowlist['ip'],
                )
            );
        }
    }

    anchor_log_temp_admin_event(
        'account_created',
        sprintf(
            'Created temp admin %1$s expiring %2$s%3$s.',
            $username,
            anchor_format_datetime( $expires_at ),
            $label ? ' (' . $label . ')' : ''
        ),
        array(
            'subject_user_id'    => $user_id,
            'subject_user_login' => $username,
        )
    );

    return array(
        'user_id'    => (int) $user_id,
        'username'   => $username,
        'password'   => $password,
        'expires_at' => $expires_at,
        'label'      => $label,
        'wordfence_allowlist' => is_wp_error( $wordfence_allowlist ) ? null : $wordfence_allowlist,
        'wordfence_allowlist_error' => is_wp_error( $wordfence_allowlist ) ? $wordfence_allowlist : null,
    );
}

function anchor_temp_admin_reassign_user_id( $user_id ) {
    $created_by = (int) get_user_meta( $user_id, ANCHOR_TEMP_ADMIN_CREATED_BY_META, true );

    if ( $created_by > 0 && $created_by !== (int) $user_id && get_userdata( $created_by ) ) {
        return $created_by;
    }

    $admins = get_users(
        array(
            'role'    => 'administrator',
            'exclude' => array( (int) $user_id ),
            'number'  => 1,
            'fields'  => 'ids',
        )
    );

    return ! empty( $admins ) ? (int) $admins[0] : 0;
}

function anchor_temp_admin_delete_account( $user_id, $reason = 'expired' ) {
    $user = get_userdata( (int) $user_id );

    if ( ! $user instanceof WP_User || ! anchor_is_temp_admin_user( $user ) ) {
        return false;
    }

    $actor_user = is_user_logged_in() ? wp_get_current_user() : null;

    if ( class_exists( 'WP_Session_Tokens' ) ) {
        $sessions = WP_Session_Tokens::get_instance( $user->ID );
        $sessions->destroy_all();
    }

    if ( get_current_user_id() === (int) $user->ID ) {
        wp_logout();
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';

    anchor_temp_admin_wordfence_cleanup_for_user( $user );

    $reassign = anchor_temp_admin_reassign_user_id( $user->ID );
    $deleted  = $reassign > 0 ? wp_delete_user( $user->ID, $reassign ) : wp_delete_user( $user->ID );

    if ( $deleted ) {
        anchor_log_temp_admin_event(
            'account_deleted',
            sprintf(
                'Deleted temp admin %1$s. Reason: %2$s.',
                $user->user_login,
                sanitize_text_field( $reason )
            ),
            array(
                'actor_user_id'      => $actor_user instanceof WP_User ? (int) $actor_user->ID : 0,
                'actor_user_login'   => $actor_user instanceof WP_User ? $actor_user->user_login : '',
                'subject_user_id'    => $user->ID,
                'subject_user_login' => $user->user_login,
            )
        );
    }

    return (bool) $deleted;
}

function anchor_temp_admin_get_users() {
    $users = get_users(
        array(
            'meta_key'   => ANCHOR_TEMP_ADMIN_MARKER_META,
            'meta_value' => '1',
            'orderby'    => 'login',
            'order'      => 'ASC',
        )
    );

    usort(
        $users,
        function( $a, $b ) {
            return anchor_get_temp_admin_expiration( $a->ID ) <=> anchor_get_temp_admin_expiration( $b->ID );
        }
    );

    return $users;
}

function anchor_temp_admin_delete_all_accounts( $reason = 'plugin_deactivated' ) {
    foreach ( anchor_temp_admin_get_users() as $user ) {
        anchor_temp_admin_delete_account( $user->ID, $reason );
    }
}

function anchor_temp_admin_cleanup_expired_accounts( $force = false ) {
    $now          = time();
    $last_cleanup = (int) get_option( ANCHOR_TEMP_ADMIN_LAST_CLEANUP_OPTION, 0 );

    if ( ! $force && $last_cleanup > 0 && ( $now - $last_cleanup ) < 300 ) {
        return;
    }

    update_option( ANCHOR_TEMP_ADMIN_LAST_CLEANUP_OPTION, $now, false );

    $current_user_id = get_current_user_id();

    foreach ( anchor_temp_admin_get_users() as $user ) {
        if ( (int) $user->ID === (int) $current_user_id ) {
            continue;
        }

        if ( anchor_temp_admin_is_expired( $user ) ) {
            anchor_temp_admin_delete_account( $user->ID, 'expired' );
        }
    }
}

function anchor_temp_admin_enforce_current_user_expiry() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $user = wp_get_current_user();
    if ( ! anchor_temp_admin_is_expired( $user ) ) {
        return;
    }

    anchor_temp_admin_delete_account( $user->ID, 'expired_during_request' );

    if ( ! headers_sent() ) {
        wp_safe_redirect( anchor_temp_admin_detect_login_url() );
        exit;
    }

    wp_die( esc_html__( 'This temporary admin account has expired.', 'anchor' ) );
}

function anchor_temp_admin_block_expired_login( $user, $username ) {
    $candidate = $user instanceof WP_User ? $user : null;

    if ( ! $candidate instanceof WP_User && ! empty( $username ) ) {
        $candidate = get_user_by( 'login', sanitize_user( $username, true ) );
    }

    if ( ! $candidate instanceof WP_User || ! anchor_temp_admin_is_expired( $candidate ) ) {
        return $user;
    }

    anchor_temp_admin_delete_account( $candidate->ID, 'expired_before_login' );

    return new WP_Error(
        'anchor_temp_admin_expired',
        __( 'This temporary admin account has expired.', 'anchor' )
    );
}
add_filter( 'authenticate', 'anchor_temp_admin_block_expired_login', 100, 2 );

function anchor_temp_admin_schedule_cleanup() {
    if ( ! wp_next_scheduled( ANCHOR_TEMP_ADMIN_CLEANUP_HOOK ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', ANCHOR_TEMP_ADMIN_CLEANUP_HOOK );
    }
}

function anchor_temp_admin_unschedule_cleanup() {
    wp_clear_scheduled_hook( ANCHOR_TEMP_ADMIN_CLEANUP_HOOK );
}

add_action( ANCHOR_TEMP_ADMIN_CLEANUP_HOOK, 'anchor_temp_admin_cleanup_expired_accounts' );
add_action( 'init', 'anchor_temp_admin_schedule_cleanup', 1 );

add_action(
    'init',
    function() {
        anchor_temp_admin_cleanup_expired_accounts( false );
        anchor_temp_admin_enforce_current_user_expiry();
    },
    5
);

function anchor_temp_admin_filter_user_caps( $allcaps, $caps, $args, $user ) {
    if ( ! $user instanceof WP_User || ! anchor_is_temp_admin_user( $user ) ) {
        return $allcaps;
    }

    $restricted_caps = array(
        'create_users',
        'delete_users',
        'edit_users',
        'list_users',
        'promote_users',
        'remove_users',
    );

    foreach ( $restricted_caps as $restricted_cap ) {
        if ( isset( $allcaps[ $restricted_cap ] ) ) {
            $allcaps[ $restricted_cap ] = false;
        }
    }

    return $allcaps;
}
add_filter( 'user_has_cap', 'anchor_temp_admin_filter_user_caps', 10, 4 );

function anchor_temp_admin_disable_application_passwords( $available, $user ) {
    if ( $user instanceof WP_User && anchor_is_temp_admin_user( $user ) ) {
        return false;
    }

    return $available;
}
add_filter( 'wp_is_application_passwords_available_for_user', 'anchor_temp_admin_disable_application_passwords', 10, 2 );

function anchor_temp_admin_hide_sensitive_menus() {
    if ( ! anchor_is_temp_admin_user() ) {
        return;
    }

    remove_menu_page( 'users.php' );
    remove_menu_page( 'anchor-plugin' );
}
add_action( 'admin_menu', 'anchor_temp_admin_hide_sensitive_menus', 99 );

function anchor_temp_admin_log_login( $user_login, $user ) {
    if ( ! anchor_is_temp_admin_user( $user ) ) {
        return;
    }

    update_user_meta( $user->ID, ANCHOR_TEMP_ADMIN_LAST_LOGIN_META, time() );

    anchor_log_temp_admin_event(
        'login',
        sprintf( 'Temp admin %1$s logged in.', $user_login ),
        array(
            'subject_user_id'    => $user->ID,
            'subject_user_login' => $user_login,
        )
    );
}
add_action( 'wp_login', 'anchor_temp_admin_log_login', 10, 2 );

function anchor_temp_admin_log_logout() {
    $user = is_user_logged_in() ? wp_get_current_user() : null;

    if ( ! $user instanceof WP_User || ! anchor_is_temp_admin_user( $user ) ) {
        return;
    }

    anchor_log_temp_admin_event(
        'logout',
        sprintf( 'Temp admin %1$s logged out.', $user->user_login ),
        array(
            'subject_user_id'    => $user->ID,
            'subject_user_login' => $user->user_login,
        )
    );
}
add_action( 'wp_logout', 'anchor_temp_admin_log_logout' );

function anchor_temp_admin_log_screen_view( $screen ) {
    if ( ! anchor_is_temp_admin_user() || wp_doing_ajax() ) {
        return;
    }

    $screen_id = $screen instanceof WP_Screen ? $screen->id : 'unknown';
    $url       = anchor_temp_admin_sanitized_request_uri();

    anchor_log_temp_admin_event(
        'screen_view',
        sprintf(
            'Visited admin screen %1$s%2$s.',
            sanitize_text_field( $screen_id ),
            $url ? ' at ' . $url : ''
        ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'current_screen', 'anchor_temp_admin_log_screen_view' );

function anchor_temp_admin_log_plugin_activation( $plugin, $network_wide ) {
    if ( ! anchor_is_temp_admin_user() ) {
        return;
    }

    anchor_log_temp_admin_event(
        'plugin_activated',
        sprintf(
            'Activated plugin %1$s%2$s.',
            sanitize_text_field( $plugin ),
            $network_wide ? ' network-wide' : ''
        ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'activated_plugin', 'anchor_temp_admin_log_plugin_activation', 10, 2 );

function anchor_temp_admin_log_plugin_deactivation( $plugin, $network_wide ) {
    if ( ! anchor_is_temp_admin_user() ) {
        return;
    }

    anchor_log_temp_admin_event(
        'plugin_deactivated',
        sprintf(
            'Deactivated plugin %1$s%2$s.',
            sanitize_text_field( $plugin ),
            $network_wide ? ' network-wide' : ''
        ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'deactivated_plugin', 'anchor_temp_admin_log_plugin_deactivation', 10, 2 );

function anchor_temp_admin_log_upgrader_activity( $upgrader, $hook_data ) {
    if ( ! anchor_is_temp_admin_user() ) {
        return;
    }

    $action = isset( $hook_data['action'] ) ? sanitize_text_field( $hook_data['action'] ) : 'updated';
    $type   = isset( $hook_data['type'] ) ? sanitize_text_field( $hook_data['type'] ) : 'item';
    $items  = array();

    foreach ( array( 'plugins', 'themes', 'translations' ) as $key ) {
        if ( ! empty( $hook_data[ $key ] ) && is_array( $hook_data[ $key ] ) ) {
            $items = array_merge( $items, $hook_data[ $key ] );
        }
    }

    if ( empty( $items ) && ! empty( $hook_data['plugin'] ) ) {
        $items[] = $hook_data['plugin'];
    }

    if ( empty( $items ) && ! empty( $hook_data['theme'] ) ) {
        $items[] = $hook_data['theme'];
    }

    $items = array_slice(
        array_map(
            'sanitize_text_field',
            array_unique( $items )
        ),
        0,
        5
    );

    anchor_log_temp_admin_event(
        'upgrader_action',
        sprintf(
            'Ran %1$s on %2$s%3$s.',
            $action,
            $type,
            $items ? ': ' . implode( ', ', $items ) : ''
        ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'upgrader_process_complete', 'anchor_temp_admin_log_upgrader_activity', 10, 2 );

function anchor_temp_admin_log_theme_switch( $new_name, $new_theme, $old_theme ) {
    if ( ! anchor_is_temp_admin_user() ) {
        return;
    }

    $old_name = $old_theme instanceof WP_Theme ? $old_theme->get( 'Name' ) : '';

    anchor_log_temp_admin_event(
        'theme_switched',
        sprintf(
            'Switched theme from %1$s to %2$s.',
            $old_name ? sanitize_text_field( $old_name ) : 'unknown',
            sanitize_text_field( $new_name )
        ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'switch_theme', 'anchor_temp_admin_log_theme_switch', 10, 3 );

function anchor_temp_admin_log_plugin_editor_save( $plugin ) {
    if ( ! anchor_is_temp_admin_user() ) {
        return;
    }

    anchor_log_temp_admin_event(
        'plugin_file_edited',
        sprintf( 'Edited plugin file in %s.', sanitize_text_field( $plugin ) ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'edited_plugin', 'anchor_temp_admin_log_plugin_editor_save' );

function anchor_temp_admin_log_theme_editor_save( $stylesheet ) {
    if ( ! anchor_is_temp_admin_user() ) {
        return;
    }

    anchor_log_temp_admin_event(
        'theme_file_edited',
        sprintf( 'Edited theme file in %s.', sanitize_text_field( $stylesheet ) ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'edited_theme', 'anchor_temp_admin_log_theme_editor_save' );

function anchor_temp_admin_log_post_save( $post_id, $post, $update ) {
    if ( ! anchor_is_temp_admin_user() || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
        return;
    }

    anchor_log_temp_admin_event(
        'content_saved',
        sprintf(
            '%1$s %2$s #%3$d (%4$s).',
            $update ? 'Updated' : 'Created',
            sanitize_text_field( $post->post_type ),
            (int) $post_id,
            sanitize_text_field( get_the_title( $post_id ) ?: '(no title)' )
        ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'save_post', 'anchor_temp_admin_log_post_save', 10, 3 );

function anchor_temp_admin_log_post_trash( $post_id ) {
    if ( ! anchor_is_temp_admin_user() ) {
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }

    anchor_log_temp_admin_event(
        'content_trashed',
        sprintf(
            'Trashed %1$s #%2$d (%3$s).',
            sanitize_text_field( $post->post_type ),
            (int) $post_id,
            sanitize_text_field( get_the_title( $post_id ) ?: '(no title)' )
        ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'trashed_post', 'anchor_temp_admin_log_post_trash' );

function anchor_temp_admin_log_post_delete( $post_id ) {
    if ( ! anchor_is_temp_admin_user() ) {
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }

    anchor_log_temp_admin_event(
        'content_deleted',
        sprintf(
            'Deleted %1$s #%2$d (%3$s).',
            sanitize_text_field( $post->post_type ),
            (int) $post_id,
            sanitize_text_field( get_the_title( $post_id ) ?: '(no title)' )
        ),
        array(
            'subject_user_id'    => get_current_user_id(),
            'subject_user_login' => wp_get_current_user()->user_login,
        )
    );
}
add_action( 'before_delete_post', 'anchor_temp_admin_log_post_delete' );

function anchor_temp_admin_event_label( $event ) {
    $labels = array(
        'account_created'    => 'Created',
        'account_deleted'    => 'Deleted',
        'login'              => 'Login',
        'logout'             => 'Logout',
        'screen_view'        => 'Page View',
        'plugin_activated'   => 'Plugin Activated',
        'plugin_deactivated' => 'Plugin Deactivated',
        'upgrader_action'    => 'Updater',
        'theme_switched'     => 'Theme Switched',
        'plugin_file_edited' => 'Plugin File Edited',
        'theme_file_edited'  => 'Theme File Edited',
        'content_saved'      => 'Content Saved',
        'content_trashed'    => 'Content Trashed',
        'content_deleted'    => 'Content Deleted',
        'wordfence_ip_allowlisted' => 'Wordfence IP Allowlisted',
        'wordfence_ip_allowlist_failed' => 'Wordfence IP Allowlist Failed',
        'wordfence_ip_allowlist_removed' => 'Wordfence IP Removed',
    );

    return isset( $labels[ $event ] ) ? $labels[ $event ] : ucwords( str_replace( '_', ' ', sanitize_key( $event ) ) );
}

function anchor_handle_temp_admin_action( $action ) {
    $result = array(
        'created_account' => null,
        'notices'         => array(),
    );

    switch ( $action ) {
        case 'create_temp_admin':
            $hours = isset( $_POST['anchor_temp_admin_hours'] ) ? wp_unslash( $_POST['anchor_temp_admin_hours'] ) : anchor_temp_admin_default_hours();
            $label = isset( $_POST['anchor_temp_admin_label'] ) ? wp_unslash( $_POST['anchor_temp_admin_label'] ) : '';
            $wordfence_allowlist_ip = '';
            $hours = absint( $hours );

            if ( $hours < 1 ) {
                $hours = anchor_temp_admin_default_hours();
            }

            if ( $hours > anchor_temp_admin_max_hours() ) {
                $hours = anchor_temp_admin_max_hours();
            }

            if ( ! empty( $_POST['anchor_temp_admin_wordfence_allowlist'] ) ) {
                $posted_wordfence_ip = isset( $_POST['anchor_temp_admin_wordfence_ip'] ) ? wp_unslash( $_POST['anchor_temp_admin_wordfence_ip'] ) : '';
                $wordfence_allowlist_ip = '' !== trim( (string) $posted_wordfence_ip )
                    ? sanitize_text_field( $posted_wordfence_ip )
                    : anchor_temp_admin_wordfence_detect_current_ip();

                if ( ! anchor_temp_admin_wordfence_available() ) {
                    $result['notices'][] = array(
                        'type'    => 'error',
                        'message' => 'Wordfence is not active or its allowlist API is unavailable.',
                    );
                    break;
                }

                $validated_wordfence_ip = anchor_temp_admin_wordfence_validate_ip_entry( $wordfence_allowlist_ip );
                if ( is_wp_error( $validated_wordfence_ip ) ) {
                    $result['notices'][] = array(
                        'type'    => 'error',
                        'message' => $validated_wordfence_ip->get_error_message(),
                    );
                    break;
                }

                $wordfence_allowlist_ip = $validated_wordfence_ip;
            }

            $created = anchor_temp_admin_create_account( $hours, $label, $wordfence_allowlist_ip );

            if ( is_wp_error( $created ) ) {
                $result['notices'][] = array(
                    'type'    => 'error',
                    'message' => $created->get_error_message(),
                );
            } else {
                $result['created_account'] = $created;
                $result['notices'][]       = array(
                    'type'    => 'success',
                    'message' => 'Temporary admin account created.',
                );

                if ( ! empty( $created['wordfence_allowlist_error'] ) && is_wp_error( $created['wordfence_allowlist_error'] ) ) {
                    $result['notices'][] = array(
                        'type'    => 'error',
                        'message' => 'Temporary admin was created, but Wordfence IP allowlisting failed: ' . $created['wordfence_allowlist_error']->get_error_message(),
                    );
                } elseif ( ! empty( $created['wordfence_allowlist']['ip'] ) ) {
                    $result['notices'][] = array(
                        'type'    => 'success',
                        'message' => 'Wordfence allowlisted IP ' . $created['wordfence_allowlist']['ip'] . ' for this temporary admin.',
                    );
                }
            }
            break;

        case 'revoke_temp_admin':
            $user_id = isset( $_POST['anchor_temp_admin_user_id'] ) ? absint( wp_unslash( $_POST['anchor_temp_admin_user_id'] ) ) : 0;

            if ( $user_id < 1 || ! anchor_is_temp_admin_user( $user_id ) ) {
                $result['notices'][] = array(
                    'type'    => 'error',
                    'message' => 'The selected temporary admin account was not found.',
                );
                break;
            }

            if ( anchor_temp_admin_delete_account( $user_id, 'revoked_by_admin' ) ) {
                $result['notices'][] = array(
                    'type'    => 'success',
                    'message' => 'Temporary admin account revoked.',
                );
            } else {
                $result['notices'][] = array(
                    'type'    => 'error',
                    'message' => 'Failed to revoke the temporary admin account.',
                );
            }
            break;
    }

    return $result;
}

function anchor_render_temp_admin_section( $nonce_action, $nonce_name, $created_account = null ) {
    anchor_temp_admin_cleanup_expired_accounts( true );

    $active_users = anchor_temp_admin_get_users();
    $log_entries  = anchor_temp_admin_recent_log_entries();
    $wordfence_status = anchor_temp_admin_wordfence_allowlist_status();

    echo '<div class="anchor-section">';
    echo '<h2>Temporary Admin Access</h2>';
    echo '<p style="max-width: 1100px;">Create a short-lived administrator for hands-on work. Temp admins expire automatically, are removed on plugin deactivation, cannot manage users or generate more temp admins, and cannot use application passwords.</p>';

    if ( is_array( $created_account ) && ! empty( $created_account['username'] ) && ! empty( $created_account['password'] ) ) {
        $credentials_text = anchor_temp_admin_credentials_text( $created_account );
        $textarea_id      = 'anchor-temp-admin-credentials';

        echo '<div class="anchor-copy-card">';
        echo '<div class="anchor-copy-card-header">';
        echo '<p><strong>New temporary admin credentials</strong><br><span class="description">The password is only shown once here.</span></p>';
        echo '<p><button type="button" class="button button-secondary anchor-copy-button" data-copy-target="' . esc_attr( $textarea_id ) . '">Copy Credentials</button><span class="anchor-copy-status" aria-live="polite"></span></p>';
        echo '</div>';
        echo '<textarea readonly id="' . esc_attr( $textarea_id ) . '" class="large-text code" rows="7">' . esc_textarea( $credentials_text ) . '</textarea>';
        echo '<p class="description">Use the copy button to grab the full credential block, including the login URL and expiration time.</p>';
        echo '</div>';
        echo '<script>
        (function() {
            if (window.anchorTempAdminCopyBound) {
                return;
            }
            window.anchorTempAdminCopyBound = true;

            function setStatus(button, message) {
                var status = button.parentNode ? button.parentNode.querySelector(".anchor-copy-status") : null;
                if (!status) {
                    return;
                }
                status.textContent = message;
                window.setTimeout(function() {
                    if (status.textContent === message) {
                        status.textContent = "";
                    }
                }, 2500);
            }

            function fallbackCopy(textarea) {
                textarea.focus();
                textarea.select();
                textarea.setSelectionRange(0, textarea.value.length);
                try {
                    return document.execCommand("copy");
                } catch (error) {
                    return false;
                }
            }

            document.addEventListener("click", function(event) {
                var button = event.target.closest(".anchor-copy-button");
                var target;

                if (!button) {
                    return;
                }

                target = document.getElementById(button.getAttribute("data-copy-target"));
                if (!target) {
                    setStatus(button, "Missing text");
                    return;
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(target.value).then(function() {
                        setStatus(button, "Copied");
                    }).catch(function() {
                        if (fallbackCopy(target)) {
                            setStatus(button, "Copied");
                            return;
                        }
                        setStatus(button, "Copy failed");
                    });
                    return;
                }

                if (fallbackCopy(target)) {
                    setStatus(button, "Copied");
                    return;
                }

                setStatus(button, "Copy failed");
            });
        }());
        </script>';
    }

    echo '<form method="post" action="">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="create_temp_admin">';
    echo '<table class="form-table" role="presentation">';
    echo '<tr>';
    echo '<th scope="row"><label for="anchor_temp_admin_hours">Expires in</label></th>';
    echo '<td><input type="number" min="1" max="' . esc_attr( anchor_temp_admin_max_hours() ) . '" step="1" id="anchor_temp_admin_hours" name="anchor_temp_admin_hours" value="' . esc_attr( anchor_temp_admin_default_hours() ) . '" class="small-text"> hours';
    echo '<p class="description">Default is 24 hours. Hard limit: ' . esc_html( anchor_temp_admin_max_hours() ) . ' hours.</p></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th scope="row"><label for="anchor_temp_admin_label">Label</label></th>';
    echo '<td><input type="text" id="anchor_temp_admin_label" name="anchor_temp_admin_label" value="" class="regular-text" maxlength="80">';
    echo '<p class="description">Optional note to identify why this account was created.</p></td>';
    echo '</tr>';

    if ( ! empty( $wordfence_status['available'] ) && ! empty( $wordfence_status['ip'] ) ) {
        echo '<tr>';
        echo '<th scope="row">Wordfence allowlist</th>';
        echo '<td>';

        if ( ! empty( $wordfence_status['already_allowlisted'] ) ) {
            echo '<p class="description">Wordfence already allowlists the current detected IP: <code>' . esc_html( $wordfence_status['ip'] ) . '</code>.</p>';
        } else {
            echo '<label for="anchor_temp_admin_wordfence_allowlist">';
            echo '<input type="checkbox" id="anchor_temp_admin_wordfence_allowlist" name="anchor_temp_admin_wordfence_allowlist" value="1"> ';
            echo 'Allowlist this IP in Wordfence while the temp admin is active';
            echo '</label>';
            echo '<p style="margin: 8px 0 0;"><input type="text" id="anchor_temp_admin_wordfence_ip" name="anchor_temp_admin_wordfence_ip" value="' . esc_attr( $wordfence_status['ip'] ) . '" class="regular-text code"></p>';
            echo '<p class="description">Detected current admin IP. Edit this if Codex will log in from a different IP. Anchor removes Wordfence entries it adds when the temp admin is revoked or expires.</p>';

            if ( empty( $wordfence_status['firewall_readable'] ) ) {
                echo '<p class="description">Anchor could not read the existing Wordfence firewall allowlist, so this may duplicate an existing entry.</p>';
            }
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '<p><input type="submit" class="button button-primary" value="Create Temporary Admin"></p>';
    echo '</form>';

    echo '<h3>Active Temporary Admins</h3>';

    if ( empty( $active_users ) ) {
        echo '<p><em>No active temporary admins.</em></p>';
    } else {
        echo '<table class="widefat striped" style="max-width: 1100px;">';
        echo '<thead><tr><th>Username</th><th>Label</th><th>Created</th><th>Expires</th><th>Last Login</th><th>Created By</th><th>Action</th></tr></thead>';
        echo '<tbody>';

        foreach ( $active_users as $user ) {
            $created_by   = (int) get_user_meta( $user->ID, ANCHOR_TEMP_ADMIN_CREATED_BY_META, true );
            $created_user = $created_by > 0 ? get_userdata( $created_by ) : null;
            $label        = anchor_get_temp_admin_label( $user->ID );

            echo '<tr>';
            echo '<td><code>' . esc_html( $user->user_login ) . '</code></td>';
            echo '<td>' . esc_html( $label ? $label : '-' ) . '</td>';
            echo '<td>' . esc_html( anchor_format_datetime( anchor_get_temp_admin_created_at( $user->ID ) ) ) . '</td>';
            echo '<td>' . esc_html( anchor_format_datetime( anchor_get_temp_admin_expiration( $user->ID ) ) ) . '</td>';
            echo '<td>' . esc_html( anchor_format_datetime( anchor_get_temp_admin_last_login( $user->ID ) ) ) . '</td>';
            echo '<td>' . esc_html( $created_user instanceof WP_User ? $created_user->user_login : 'Unknown' ) . '</td>';
            echo '<td>';
            echo '<form method="post" action="" style="margin:0;">';
            wp_nonce_field( $nonce_action, $nonce_name );
            echo '<input type="hidden" name="anchor_action" value="revoke_temp_admin">';
            echo '<input type="hidden" name="anchor_temp_admin_user_id" value="' . esc_attr( $user->ID ) . '">';
            echo '<input type="submit" class="button button-secondary" value="Revoke">';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '<h3 style="margin-top: 24px;">Recent Temp Admin Activity</h3>';
    echo '<p class="description">Most recent ' . esc_html( anchor_temp_admin_notice_limit() ) . ' events. Page-view entries strip nonce and password query data before storing.</p>';

    if ( empty( $log_entries ) ) {
        echo '<p><em>No temp admin activity has been recorded yet.</em></p>';
    } else {
        echo '<table class="widefat striped" style="max-width: 1100px;">';
        echo '<thead><tr><th>Time</th><th>Actor</th><th>Event</th><th>Details</th><th>IP</th></tr></thead>';
        echo '<tbody>';

        foreach ( $log_entries as $entry ) {
            $actor = ! empty( $entry['actor_user_login'] ) ? $entry['actor_user_login'] : ( ! empty( $entry['subject_user_login'] ) ? $entry['subject_user_login'] : 'System' );

            echo '<tr>';
            echo '<td>' . esc_html( anchor_format_datetime( isset( $entry['time'] ) ? $entry['time'] : 0 ) ) . '</td>';
            echo '<td>' . esc_html( $actor ) . '</td>';
            echo '<td>' . esc_html( anchor_temp_admin_event_label( isset( $entry['event'] ) ? $entry['event'] : '' ) ) . '</td>';
            echo '<td>' . esc_html( isset( $entry['summary'] ) ? $entry['summary'] : '' ) . '</td>';
            echo '<td>' . esc_html( ! empty( $entry['ip'] ) ? $entry['ip'] : '-' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div>';
}
