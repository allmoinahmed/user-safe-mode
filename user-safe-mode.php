<?php
/**
 * Plugin Name: User Safe Mode
 * Plugin URI:  https://github.com/yourusername/user-safe-mode
 * Description: Debug like a pro — disable plugins just for yourself. Other users and visitors see the site untouched.
 * Version:     1.1.6
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Your Name
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: user-safe-mode
 *
 * @license GPL-2.0-or-later
 *
 * HOW IT WORKS:
 * - On activation, this plugin writes a tiny MU plugin to wp-content/mu-plugins/usm-safe-mode.php
 * - The MU plugin loads BEFORE regular plugins and hooks option_active_plugins
 * - Because MU plugins load AFTER pluggable.php, is_user_logged_in() is available
 * - The main plugin file handles admin UI, admin bar, and lifecycle hooks
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'USM_VERSION', '1.1.6' );
define( 'USM_META_KEY', '_usm_disabled_plugins' );
define( 'USM_MENU_SLUG', 'user-safe-mode' );

// ============================================================================
//  ACTIVATION: Write the MU plugin
// ============================================================================
function usm_on_activation() {
    $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
    if ( ! is_dir( $mu_dir ) ) {
        wp_mkdir_p( $mu_dir );
    }

    $dest = $mu_dir . '/usm-safe-mode.php';

    // Skip if already exists, is ours, and matches the current version.
    if ( file_exists( $dest ) ) {
        $existing = file_get_contents( $dest );
        if ( false !== strpos( $existing, 'User Safe Mode — MU plugin' ) ) {
            if ( false !== strpos( $existing, 'Version: ' . USM_VERSION ) ) {
                return;
            }
        }
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    file_put_contents( $dest, usm_build_mu_code() );
}
register_activation_hook( __FILE__, 'usm_on_activation' );

/**
 * Keep the generated MU plugin in sync after a plugin update.
 * Activation does not run on update, so check on every admin request
 * and rewrite the MU plugin if its version marker is stale.
 */
add_action( 'admin_init', 'usm_maybe_refresh_mu_plugin' );
function usm_maybe_refresh_mu_plugin() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $mu_file = WP_CONTENT_DIR . '/mu-plugins/usm-safe-mode.php';
    if ( ! file_exists( $mu_file ) ) {
        usm_on_activation();
        return;
    }

    $existing = file_get_contents( $mu_file );
    if ( false !== strpos( $existing, 'User Safe Mode — MU plugin' ) ) {
        if ( false !== strpos( $existing, 'Version: ' . USM_VERSION ) ) {
            return;
        }
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    file_put_contents( $mu_file, usm_build_mu_code() );
}

// ============================================================================
//  DEACTIVATION: Stop filtering before WordPress updates active_plugins
// ============================================================================
function usm_prepare_deactivation( $plugin ) {
    if ( plugin_basename( __FILE__ ) !== $plugin ) {
        return;
    }

    if ( ! defined( 'USM_DISABLE_FILTERING' ) ) {
        define( 'USM_DISABLE_FILTERING', true );
    }

    if ( function_exists( 'usm_filter_active_plugins' ) ) {
        remove_filter( 'option_active_plugins', 'usm_filter_active_plugins', 10 );
    }
    if ( function_exists( 'usm_filter_active_sitewide_plugins' ) ) {
        remove_filter( 'option_active_sitewide_plugins', 'usm_filter_active_sitewide_plugins', 10 );
    }

    // Guard against the MU plugin having already filtered the option value.
    // WordPress will read option_active_plugins to update the DB, so we must
    // guarantee the stored option is written as the original, unfiltered list.
    if ( function_exists( 'usm_get_original_active_plugins' ) ) {
        add_filter( 'option_active_plugins', 'usm_get_original_active_plugins', 0, 1 );
    }
    add_filter( 'pre_update_option_active_plugins', 'usm_restore_active_plugins', 0, 3 );
}
add_action( 'deactivate_plugin', 'usm_prepare_deactivation', 10, 1 );

/**
 * If the MU plugin's option_active_plugins filter has already cached a filtered
 * value, return the original stored option value directly from the database.
 */
function usm_get_original_active_plugins( $value ) {
    global $wpdb;
    return maybe_unserialize( $wpdb->get_var( $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        'active_plugins'
    ) ) );
}

/**
 * Ensure the active_plugins option is saved with the full, unfiltered list.
 *
 * WordPress does not directly use `update_option` when toggling plugins; it
 * calls `update_option('active_plugins', ...)` which passes the current option
 * value through the `pre_update_option_{$option}` filter. If the MU plugin
 * filtered the read value, the filtered value would be saved. This filter
 * restores the original DB value before WordPress writes it back.
 */
function usm_restore_active_plugins( $value, $option, $old_value ) {
    if ( 'active_plugins' !== $option ) {
        return $value;
    }

    global $wpdb;
    $stored = $wpdb->get_var( $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        'active_plugins'
    ) );

    if ( null === $stored ) {
        return $value;
    }

    return maybe_unserialize( $stored );
}

// ============================================================================
//  DEACTIVATION: Remove the MU plugin + clear all user meta
// ============================================================================
function usm_on_deactivation() {
    // Make sure any generated MU plugin filtering stops on this request
    // before WordPress reads active_plugins to update the DB option.
    if ( ! defined( 'USM_DISABLE_FILTERING' ) ) {
        define( 'USM_DISABLE_FILTERING', true );
    }

    // If the MU plugin has already loaded, remove its filters directly.
    if ( function_exists( 'usm_filter_active_plugins' ) ) {
        remove_filter( 'option_active_plugins', 'usm_filter_active_plugins', 10 );
    }
    if ( function_exists( 'usm_filter_active_sitewide_plugins' ) ) {
        remove_filter( 'option_active_sitewide_plugins', 'usm_filter_active_sitewide_plugins', 10 );
    }

    add_filter( 'pre_update_option_active_plugins', 'usm_restore_active_plugins', 0, 3 );

    $mu_file = WP_CONTENT_DIR . '/mu-plugins/usm-safe-mode.php';
    if ( file_exists( $mu_file ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
        unlink( $mu_file );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => USM_META_KEY ], [ '%s' ] );
}
register_deactivation_hook( __FILE__, 'usm_on_deactivation' );

// ============================================================================
//  UNINSTALL: Same cleanup as deactivation
// ============================================================================
function usm_uninstall() {
    if ( ! defined( 'USM_DISABLE_FILTERING' ) ) {
        define( 'USM_DISABLE_FILTERING', true );
    }

    if ( function_exists( 'usm_filter_active_plugins' ) ) {
        remove_filter( 'option_active_plugins', 'usm_filter_active_plugins', 10 );
    }
    if ( function_exists( 'usm_filter_active_sitewide_plugins' ) ) {
        remove_filter( 'option_active_sitewide_plugins', 'usm_filter_active_sitewide_plugins', 10 );
    }

    $mu_file = WP_CONTENT_DIR . '/mu-plugins/usm-safe-mode.php';
    if ( file_exists( $mu_file ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
        unlink( $mu_file );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => USM_META_KEY ], [ '%s' ] );
}
register_uninstall_hook( __FILE__, 'usm_uninstall' );

// ============================================================================
//  BUILD the MU plugin code (string template)
//
//  IMPORTANT: MU plugins load BEFORE pluggable.php. We can NOT call
//  is_user_logged_in() here. Instead, we parse the WP auth cookie directly
//  (its format: username|expiration|token|hmac) and look up the user ID
//  from the database.
// ============================================================================
function usm_build_mu_code() {
    $meta_key    = USM_META_KEY;
    $usm_version = USM_VERSION;
    return <<<PHP
<?php
/**
 * User Safe Mode — MU plugin.
 * Auto-generated by User Safe Mode. Do not edit directly.
 * Version: {$usm_version}
 *
 * Loads BEFORE pluggable.php, so we cannot use is_user_logged_in().
 * We parse the WP auth cookie directly to identify the user.
 *
 * @license GPL-2.0-or-later
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Parse the WP auth cookie to get the user ID — without pluggable.php.
 *
 * WP auth cookie format: username|expiration|token|hmac
 * Cookie name:    wordpress_logged_in_<hash>  (value is the cookie string)
 * Secure variant: wordpress_sec_<hash>        (we don't need secure here)
 */
function usm_get_user_id_from_cookie() {
    if ( empty( \$_COOKIE ) || ! is_array( \$_COOKIE ) ) {
        return 0;
    }

    foreach ( \$_COOKIE as \$name => \$value ) {
        \$prefix = 'wordpress_logged_in_';
        if ( strpos( \$name, \$prefix ) !== 0 ) {
            continue;
        }

        \$value  = wp_unslash( \$value );
        \$parts  = explode( '|', \$value );
        if ( count( \$parts ) !== 4 ) {
            continue;
        }

        \$username = sanitize_user( \$parts[0] );
        if ( empty( \$username ) ) {
            continue;
        }

        global \$wpdb;
        \$user_id = \$wpdb->get_var( \$wpdb->prepare(
            "SELECT ID FROM {\$wpdb->users} WHERE user_login = %s LIMIT 1",
            \$username
        ) );

        return (int) \$user_id;
    }

    return 0;
}

/**
 * Skip filtering on the Safe Mode admin page so the plugin list sees the
 * real active_plugins state and checkboxes remain visible.
 */
function usm_should_filter_plugins() {
    if ( function_exists( 'is_admin' ) && is_admin() ) {
        if ( isset( \$_GET['page'] ) && 'user-safe-mode' === \$_GET['page'] ) {
            return false;
        }
    }

    \$uri = \$_SERVER['REQUEST_URI'] ?? '';
    if ( false !== strpos( \$uri, 'page=user-safe-mode' ) ) {
        return false;
    }

    return true;
}

/**
 * When Safe Mode is active for the current user, send anti-cache headers
 * so that the filtered response is never cached and served to other users
 * or anonymous visitors (CDN, page cache, object cache, etc.).
 */
function usm_send_nocache_headers() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    header( 'Cache-Control: no-cache, no-store, must-revalidate', true );
    header( 'Pragma: no-cache', true );
    header( 'Expires: 0', true );
    header( 'Vary: Cookie', true );

    if ( function_exists( 'nocache_headers' ) ) {
        nocache_headers();
    }
}

function usm_filter_active_plugins( \$plugins ) {
    if ( defined( 'USM_DISABLE_FILTERING' ) && USM_DISABLE_FILTERING ) {
        return \$plugins;
    }
    if ( ! is_array( \$plugins ) ) { return \$plugins; }
    if ( ! usm_should_filter_plugins() ) { return \$plugins; }

    \$user_id = usm_get_user_id_from_cookie();
    if ( ! \$user_id ) { return \$plugins; }

    \$disabled = get_user_meta( \$user_id, '{$meta_key}', true );
    if ( empty( \$disabled ) || ! is_array( \$disabled ) ) {
        return \$plugins;
    }

    // Prevent any cache from storing this filtered response.
    usm_send_nocache_headers();

    return array_values( array_diff( \$plugins, \$disabled ) );
}
add_filter( 'option_active_plugins', 'usm_filter_active_plugins' );

function usm_filter_active_sitewide_plugins( \$plugins ) {
    if ( defined( 'USM_DISABLE_FILTERING' ) && USM_DISABLE_FILTERING ) {
        return \$plugins;
    }
    if ( ! is_array( \$plugins ) ) { return \$plugins; }
    if ( ! usm_should_filter_plugins() ) { return \$plugins; }

    \$user_id = usm_get_user_id_from_cookie();
    if ( ! \$user_id ) { return \$plugins; }

    \$disabled = get_user_meta( \$user_id, '{$meta_key}', true );
    if ( empty( \$disabled ) || ! is_array( \$disabled ) ) {
        return \$plugins;
    }

    // Prevent any cache from storing this filtered response.
    usm_send_nocache_headers();

    foreach ( \$disabled as \$plugin ) {
        if ( isset( \$plugins[ \$plugin ] ) ) {
            unset( \$plugins[ \$plugin ] );
        }
    }
    return \$plugins;
}
add_filter( 'option_active_sitewide_plugins', 'usm_filter_active_sitewide_plugins' );
PHP;
}

// ============================================================================
//  ADMIN PAGE: Top-level Safe Mode menu
// ============================================================================
add_action( 'admin_menu', 'usm_register_menu' );
function usm_register_menu() {
    add_menu_page(
        __( 'Safe Mode', 'user-safe-mode' ),
        __( 'Safe Mode', 'user-safe-mode' ),
        'manage_options',
        USM_MENU_SLUG,
        'usm_render_admin_page',
        'dashicons-shield',
        80
    );
}

// ============================================================================
//  HANDLERS: Single-toggle (per-row), bulk toggle, and clear
// ============================================================================
add_action( 'admin_post_usm_toggle', 'usm_handle_toggle' );
function usm_handle_toggle() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'user-safe-mode' ) );
    }
    check_admin_referer( 'usm_toggle' );

    $user_id = get_current_user_id();
    $plugin  = sanitize_text_field( wp_unslash( $_GET['plugin'] ?? '' ) );
    $action  = sanitize_text_field( wp_unslash( $_GET['usm_action'] ?? '' ) );

    if ( empty( $plugin ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=' . USM_MENU_SLUG ) );
        exit;
    }

    $all_plugins = array_keys( get_plugins() );
    if ( ! in_array( $plugin, $all_plugins, true ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=' . USM_MENU_SLUG ) );
        exit;
    }

    $disabled = get_user_meta( $user_id, USM_META_KEY, true );
    if ( ! is_array( $disabled ) ) { $disabled = []; }

    if ( 'disable' === $action ) {
        $disabled[] = $plugin;
        $disabled   = array_values( array_unique( $disabled ) );
    } elseif ( 'enable' === $action ) {
        $disabled = array_values( array_diff( $disabled, [ $plugin ] ) );
    }

    $disabled = array_values( array_intersect( $disabled, $all_plugins ) );
    update_user_meta( $user_id, USM_META_KEY, $disabled );

    wp_safe_redirect( admin_url( 'admin.php?page=' . USM_MENU_SLUG ) );
    exit;
}

add_action( 'admin_post_usm_bulk', 'usm_handle_bulk' );
function usm_handle_bulk() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'user-safe-mode' ) );
    }
    check_admin_referer( 'usm_bulk' );

    $user_id     = get_current_user_id();
    $plugins     = isset( $_POST['usm_plugins'] ) ? (array) $_POST['usm_plugins'] : [];
    $action      = sanitize_text_field( wp_unslash( $_POST['usm_bulk_action'] ?? '' ) );
    $all_plugins = array_keys( get_plugins() );

    $disabled = get_user_meta( $user_id, USM_META_KEY, true );
    if ( ! is_array( $disabled ) ) { $disabled = []; }

    foreach ( $plugins as $plugin_file ) {
        $plugin_file = sanitize_text_field( $plugin_file );
        if ( ! in_array( $plugin_file, $all_plugins, true ) ) {
            continue;
        }
        if ( 'disable' === $action ) {
            $disabled[] = $plugin_file;
        } elseif ( 'enable' === $action ) {
            $disabled = array_diff( $disabled, [ $plugin_file ] );
        }
    }

    $disabled = array_values( array_unique( array_intersect( $disabled, $all_plugins ) ) );
    update_user_meta( $user_id, USM_META_KEY, $disabled );

    wp_safe_redirect( admin_url( 'admin.php?page=' . USM_MENU_SLUG ) );
    exit;
}

add_action( 'admin_post_usm_clear', 'usm_handle_clear' );
function usm_handle_clear() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'user-safe-mode' ) );
    }
    check_admin_referer( 'usm_clear' );

    delete_user_meta( get_current_user_id(), USM_META_KEY );

    wp_safe_redirect( admin_url( 'admin.php?page=' . USM_MENU_SLUG . '&cleared=1' ) );
    exit;
}

// ============================================================================
//  STYLES: Inline CSS loaded only on our admin page
// ============================================================================
add_action( 'admin_enqueue_scripts', 'usm_enqueue_assets' );
function usm_enqueue_assets( $hook ) {
    if ( false === strpos( $hook, USM_MENU_SLUG ) ) { return; }
    wp_register_style( 'usm-admin', false );
    wp_enqueue_style( 'usm-admin' );
    wp_add_inline_style( 'usm-admin', usm_inline_css() );
}

function usm_inline_css() {
    return <<<'CSS'
.usm-wrap .usm-status-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin:16px 0}
.usm-wrap .usm-status-active{color:#d63638;font-size:15px}
.usm-wrap .usm-status-inactive{color:#46b450;font-size:15px}
.usm-wrap .usm-description{color:#50575e;margin:8px 0 20px}
.usm-plugin-table .usm-row-disabled{background:#fcf0f1!important}
.usm-plugin-table .check-column{width:40px}
.usm-plugin-table .usm-col-status{width:60px;text-align:center}
.usm-plugin-table .usm-icon-disabled{color:#d63638}
.usm-plugin-table .usm-icon-enabled{color:#46b450}
.usm-plugin-table .usm-col-active{width:100px}
.usm-plugin-table .usm-col-action{width:130px;text-align:center}
.usm-badge{display:inline-block;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600}
.usm-badge-active{background:#dff8e5;color:#1a6d2c}
.usm-badge-inactive{background:#f0f0f1;color:#50575e}
.usm-plugin-table .row-actions .plugin-version{color:#8c8f94;font-size:12px}
.usm-clear-section{margin-top:20px;padding-top:20px;border-top:1px solid #c3c4c7}
.usm-clear-btn{color:#d63638!important;border-color:#d63638!important}
.usm-clear-btn:hover{background:#d63638!important;color:#fff!important}
CSS;
}

// ============================================================================
//  ADMIN PAGE RENDER: Plugin list with checkboxes + bulk actions
// ============================================================================
function usm_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'user-safe-mode' ) );
    }

    $user_id       = get_current_user_id();
    $user_disabled = get_user_meta( $user_id, USM_META_KEY, true );
    if ( ! is_array( $user_disabled ) ) { $user_disabled = []; }

    $all_plugins    = get_plugins();
    $active_plugins = get_option( 'active_plugins', [] );

    // Remove this plugin from the list so it cannot accidentally be disabled.
    $self_plugin = plugin_basename( __FILE__ );
    unset( $all_plugins[ $self_plugin ] );
    ?>
    <div class="wrap usm-wrap">
        <h1><?php esc_html_e( 'Safe Mode', 'user-safe-mode' ); ?></h1>

        <?php if ( ! empty( $_GET['cleared'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Your Safe Mode has been cleared. All plugins are now active for you.', 'user-safe-mode' ); ?></p>
            </div>
        <?php endif; ?>

        <div class="usm-status-card">
            <?php if ( ! empty( $user_disabled ) ) : ?>
                <div class="usm-status-active">
                    <strong>⚠️ <?php esc_html_e( 'Safe Mode is ON for you.', 'user-safe-mode' ); ?></strong>
                    <?php
                    printf(
                        esc_html( _n( 'You have disabled %d plugin.', 'You have disabled %d plugins.', count( $user_disabled ), 'user-safe-mode' ) ),
                        (int) count( $user_disabled )
                    );
                    ?>
                </div>
            <?php else : ?>
                <div class="usm-status-inactive">
                    <strong>✅ <?php esc_html_e( 'Safe Mode is OFF for you.', 'user-safe-mode' ); ?></strong>
                    <?php esc_html_e( 'All plugins are active.', 'user-safe-mode' ); ?>
                </div>
            <?php endif; ?>
        </div>

        <p class="usm-description">
            <?php esc_html_e( 'Check plugins and use bulk actions, or click Disable/Enable per plugin.', 'user-safe-mode' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="usm-bulk-form">
            <input type="hidden" name="action" value="usm_bulk">
            <?php wp_nonce_field( 'usm_bulk' ); ?>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="usm_bulk_action" id="usm-bulk-action-selector">
                        <option value=""><?php esc_html_e( 'Bulk actions', 'user-safe-mode' ); ?></option>
                        <option value="disable"><?php esc_html_e( 'Disable for you', 'user-safe-mode' ); ?></option>
                        <option value="enable"><?php esc_html_e( 'Enable for you', 'user-safe-mode' ); ?></option>
                    </select>
                    <button type="submit" class="button" id="usm-bulk-apply"><?php esc_html_e( 'Apply', 'user-safe-mode' ); ?></button>
                </div>
                <div class="alignright">
                    <?php if ( ! empty( $user_disabled ) ) : ?>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=usm_clear' ), 'usm_clear' ) ); ?>" class="button button-secondary usm-clear-btn">
                            <?php esc_html_e( 'Clear All & Exit Safe Mode', 'user-safe-mode' ); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <br class="clear">
            </div>

            <table class="wp-list-table widefat fixed striped usm-plugin-table">
                <thead>
                    <tr>
                        <th scope="col" class="check-column">
                            <input type="checkbox" id="usm-select-all">
                        </th>
                        <th scope="col" class="usm-col-status"><?php esc_html_e( 'For you', 'user-safe-mode' ); ?></th>
                        <th scope="col" class="usm-col-plugin"><?php esc_html_e( 'Plugin', 'user-safe-mode' ); ?></th>
                        <th scope="col" class="usm-col-active"><?php esc_html_e( 'Site-wide', 'user-safe-mode' ); ?></th>
                        <th scope="col" class="usm-col-action"><?php esc_html_e( 'Action', 'user-safe-mode' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $all_plugins ) ) : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No plugins found.', 'user-safe-mode' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $all_plugins as $plugin_file => $plugin_data ) : ?>
                            <?php
                            $is_active   = in_array( $plugin_file, $active_plugins, true );
                            $is_disabled = in_array( $plugin_file, $user_disabled, true );
                            $plugin_name = $plugin_data['Name'] ?? $plugin_file;
                            ?>
                            <tr class="<?php echo $is_disabled ? 'usm-row-disabled' : ''; ?>">
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="usm_plugins[]" value="<?php echo esc_attr( $plugin_file ); ?>">
                                </th>
                                <td class="usm-col-status">
                                    <?php if ( $is_disabled ) : ?>
                                        <span class="dashicons dashicons-hidden usm-icon-disabled" title="<?php esc_attr_e( 'Disabled for you', 'user-safe-mode' ); ?>"></span>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-visibility usm-icon-enabled" title="<?php esc_attr_e( 'Active for you', 'user-safe-mode' ); ?>"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="usm-col-plugin">
                                    <strong><?php echo esc_html( $plugin_name ); ?></strong>
                                    <div class="row-actions">
                                        <span class="plugin-version"><?php echo esc_html( $plugin_data['Version'] ?? '' ); ?></span>
                                    </div>
                                </td>
                                <td class="usm-col-active">
                                    <?php if ( $is_active ) : ?>
                                        <span class="usm-badge usm-badge-active"><?php esc_html_e( 'Active', 'user-safe-mode' ); ?></span>
                                    <?php else : ?>
                                        <span class="usm-badge usm-badge-inactive"><?php esc_html_e( 'Inactive', 'user-safe-mode' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="usm-col-action">
                                    <?php if ( $is_disabled ) : ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=usm_toggle&usm_action=enable&plugin=' . rawurlencode( $plugin_file ) ), 'usm_toggle' ) ); ?>" class="button button-small">
                                            <?php esc_html_e( 'Enable for you', 'user-safe-mode' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=usm_toggle&usm_action=disable&plugin=' . rawurlencode( $plugin_file ) ), 'usm_toggle' ) ); ?>" class="button button-small">
                                            <?php esc_html_e( 'Disable for you', 'user-safe-mode' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>

        <script>
        (function() {
            var selectAll = document.getElementById('usm-select-all');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    var checkboxes = document.querySelectorAll('.usm-plugin-table input[name="usm_plugins[]"]');
                    for (var i = 0; i < checkboxes.length; i++) {
                        checkboxes[i].checked = this.checked;
                    }
                });
            }

            var applyBtn = document.getElementById('usm-bulk-apply');
            if (applyBtn) {
                applyBtn.addEventListener('click', function(e) {
                    var action = document.getElementById('usm-bulk-action-selector').value;
                    if (!action) {
                        e.preventDefault();
                        alert('Please select a bulk action first.');
                        return;
                    }
                    var checked = document.querySelectorAll('.usm-plugin-table input[name="usm_plugins[]"]:checked');
                    if (checked.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one plugin.');
                        return;
                    }
                });
            }
        })();
        </script>
    </div>
    <?php
}

// ============================================================================
//  ADMIN BAR NODE: Red indicator when Safe Mode is active
// ============================================================================
add_action( 'admin_bar_menu', 'usm_admin_bar_node', 100 );
function usm_admin_bar_node( $wp_admin_bar ) {
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    $user_id   = get_current_user_id();
    $disabled  = get_user_meta( $user_id, USM_META_KEY, true );
    $is_active = is_array( $disabled ) && ! empty( $disabled );

    $title = $is_active
        ? sprintf( esc_html__( 'Safe Mode: %d disabled', 'user-safe-mode' ), count( $disabled ) )
        : esc_html__( 'Safe Mode', 'user-safe-mode' );

    $wp_admin_bar->add_node( [
        'id'    => 'usm-status',
        'title' => $title,
        'href'  => admin_url( 'admin.php?page=' . USM_MENU_SLUG ),
        'meta'  => [ 'class' => 'usm-node' . ( $is_active ? ' usm-active' : '' ) ],
    ] );

    if ( $is_active ) {
        $clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=usm_clear' ), 'usm_clear' );
        $wp_admin_bar->add_node( [
            'parent' => 'usm-status',
            'id'     => 'usm-clear',
            'title'  => esc_html__( 'Clear & Exit Safe Mode', 'user-safe-mode' ),
            'href'   => $clear_url,
        ] );
    }
}

add_action( 'admin_print_styles', 'usm_admin_bar_styles' );
add_action( 'wp_head', 'usm_admin_bar_styles' );
function usm_admin_bar_styles() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    ?>
    <style>
    #wpadminbar .usm-node.usm-active > a{background-color:#d63638!important;color:#fff!important;font-weight:600}
    #wpadminbar .usm-node.usm-active > a:hover{background-color:#ca312f!important}
    #wpadminbar .usm-node.usm-active > a:before{content:"\f113";font-family:dashicons;display:inline-block;-moz-osx-font-smoothing:grayscale;-webkit-font-smoothing:antialiased;font-size:16px;line-height:1;margin-right:4px;vertical-align:middle}
    </style>
    <?php
}
