<?php
/**
 * Plugin Name: User Safe Mode
 * Plugin URI:  https://github.com/allmoinahmed/user-safe-mode
 * Description: Debug like a pro — disable plugins just for yourself. Other users and visitors see the site untouched.
 * Version:     1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Moin Uddin Ahmed
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: user-safe-mode
 *
 * @license GPL-2.0-or-later
 *
 * HOW IT WORKS:
 * - On activation, this plugin writes a tiny MU plugin to wp-content/mu-plugins/usm-safe-mode.php
 * - The MU plugin loads BEFORE regular plugins and hooks option_active_plugins
 * - The MU plugin loads BEFORE pluggable.php, so it verifies the logged-in cookie
 *   with its own HMAC and session-token checks instead of using pluggable functions
 * - The main plugin file handles admin UI, admin bar, and lifecycle hooks
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'USM_VERSION', '1.2.0' );
define( 'USM_META_KEY', '_usm_disabled_plugins' );
define( 'USM_MENU_SLUG', 'user-safe-mode' );
define( 'USM_MU_PLUGIN_PATH', WP_CONTENT_DIR . '/mu-plugins/usm-safe-mode.php' );

// ============================================================================
//  MULTISITE GUARD
//
//  Multisite is not supported. If detected, clean up any existing MU plugin,
//  show an error notice, and bail so none of the plugin's hooks register.
// ============================================================================
if ( is_multisite() ) {
	add_action( 'admin_init', function () {
		usm_remove_mu_plugin();
	} );
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'User Safe Mode does not support multisite networks. All Safe Mode features are disabled. Please deactivate this plugin.', 'user-safe-mode' ); ?></p>
		</div>
		<?php
	} );
	return;
}

// ============================================================================
//  CAPABILITY HELPERS
// ============================================================================
/**
 * Get the capability required to use Safe Mode.
 *
 * Default: manage_options. Filter with 'usm_required_capability' to
 * customize (e.g. 'install_plugins', 'editor', etc.).
 *
 * @return string
 */
function usm_required_capability() {
	return apply_filters( 'usm_required_capability', 'manage_options' );
}

// ============================================================================
//  PLUGIN API HELPER
// ============================================================================
/**
 * Ensure wp-admin/includes/plugin.php is loaded so get_plugins() is available.
 *
 * get_plugins() lives in wp-admin/includes/plugin.php. It is normally loaded
 * on admin requests, but it is not loaded on admin-post.php handlers or on
 * cron / REST routes that touch our handlers. Loading it ourselves makes
 * the helper safe to call from any context within this plugin.
 */
function usm_ensure_plugin_api() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
}

// ============================================================================
//  MU PLUGIN FILE HELPERS
// ============================================================================
/**
 * Write the MU plugin file to disk.
 *
 * Returns true on success, false on failure. On failure a transient notice
 * is set so the admin sees the error.
 *
 * @return bool
 */
function usm_write_mu_plugin() {
	$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
	if ( ! is_dir( $mu_dir ) && ! wp_mkdir_p( $mu_dir ) ) {
		set_transient( 'usm_mu_plugin_error', __( 'Could not create wp-content/mu-plugins/ directory.', 'user-safe-mode' ), 60 );
		return false;
	}

	$dest = USM_MU_PLUGIN_PATH;
	$code = usm_build_mu_code();

	if ( file_exists( $dest ) ) {
		$existing = file_get_contents( $dest );

		if ( false === $existing ) {
			set_transient(
				'usm_mu_plugin_error',
				sprintf(
					/* translators: %s: path to the MU plugin file */
					__( 'Could not read the existing MU plugin at %s to verify it.', 'user-safe-mode' ),
					$dest
				),
				60
			);
			return false;
		}

		if ( false === strpos( $existing, 'User Safe Mode — MU plugin' ) ) {
			set_transient(
				'usm_mu_plugin_error',
				sprintf(
					/* translators: %s: path to the MU plugin file */
					__( 'A different file already exists at %s. Please remove it manually before activating User Safe Mode.', 'user-safe-mode' ),
					$dest
				),
				60
			);
			return false;
		}

		// Content is current — nothing to do.
		if ( $existing === $code ) {
			return true;
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$written = file_put_contents( $dest, $code );
	if ( false === $written || $written <= 0 ) {
		set_transient(
			'usm_mu_plugin_error',
			sprintf(
				/* translators: %s: path to the MU plugin file */
				__( 'Failed to write the MU plugin at %s. Check file permissions.', 'user-safe-mode' ),
				$dest
			),
			60
		);
		return false;
	}

	// Verify the file was written correctly.
	if ( ! file_exists( $dest ) || filesize( $dest ) <= 0 ) {
		set_transient( 'usm_mu_plugin_error', __( 'MU plugin file was written but could not be verified. Check file permissions.', 'user-safe-mode' ), 60 );
		return false;
	}

	return true;
}

/**
 * Remove the MU plugin file from disk.
 *
 * Returns true on success, false if the file could not be removed.
 *
 * @return bool
 */
function usm_remove_mu_plugin() {
	$mu_file = USM_MU_PLUGIN_PATH;
	if ( ! file_exists( $mu_file ) ) {
		return true;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
	if ( ! unlink( $mu_file ) ) {
		set_transient(
			'usm_mu_plugin_error',
			sprintf(
				/* translators: %s: path to the MU plugin file */
				__( 'Failed to remove the MU plugin at %s. Check file permissions.', 'user-safe-mode' ),
				$mu_file
			),
			60
		);
		return false;
	}

	return true;
}

/**
 * Show admin notice if MU plugin file operations failed.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( usm_required_capability() ) ) { return; }
	$error = get_transient( 'usm_mu_plugin_error' );
	if ( $error ) {
		delete_transient( 'usm_mu_plugin_error' );
		?>
		<div class="notice notice-error is-dismissible">
			<p><strong><?php esc_html_e( 'User Safe Mode:', 'user-safe-mode' ); ?></strong> <?php echo esc_html( $error ); ?></p>
		</div>
		<?php
	}
} );

// ============================================================================
//  ACTIVATION
// ============================================================================
function usm_on_activation() {
	usm_write_mu_plugin();
}
register_activation_hook( __FILE__, 'usm_on_activation' );

/**
 * Refresh the generated MU plugin after an update. Activation hooks do not run
 * when an existing plugin is updated, so this keeps older generated code from
 * remaining active indefinitely.
 */
add_action( 'plugins_loaded', 'usm_sync_mu_plugin', 1 );
function usm_sync_mu_plugin() {
	usm_write_mu_plugin();
}

// ============================================================================
//  DEACTIVATION: Remove the MU plugin but PRESERVE user settings.
//  User meta (disabled plugins per user) survives deactivate/reactivate.
// ============================================================================
function usm_on_deactivation() {
	usm_remove_mu_plugin();
}
register_deactivation_hook( __FILE__, 'usm_on_deactivation' );

// ============================================================================
//  UNINSTALL: Remove the MU plugin AND wipe all user meta.
//  This is the nuclear option — no user data is left behind.
// ============================================================================
function usm_uninstall() {
	usm_remove_mu_plugin();

	global $wpdb;
	$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => USM_META_KEY ], [ '%s' ] );
}
register_uninstall_hook( __FILE__, 'usm_uninstall' );

// ============================================================================
//  BUILD the MU plugin code (string template)
//
//  MU plugins run before pluggable.php. The generated plugin therefore performs
//  the same logged-in cookie HMAC and session-token checks without calling
//  pluggable functions. Invalid or unverifiable identity fails closed.
// ============================================================================
function usm_build_mu_code() {
	$meta_key = USM_META_KEY;
	return <<<PHP
<?php
/**
 * User Safe Mode — MU plugin.
 * Auto-generated by User Safe Mode v1.2.0. Do not edit directly.
 *
 * @license GPL-2.0-or-later
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function usm_get_user_id_from_cookie() {
	global \$wpdb;
	if ( empty( \$_COOKIE ) || ! is_array( \$_COOKIE ) || ! isset( \$wpdb ) ) {
		return 0;
	}
	if ( ! defined( 'LOGGED_IN_KEY' ) || ! defined( 'LOGGED_IN_SALT' ) ) {
		return 0;
	}

	// Use only WordPress's current logged-in cookie. If the constant is not
	// available, fail closed rather than guessing from arbitrary cookie names.
	if ( ! defined( 'LOGGED_IN_COOKIE' ) || ! isset( \$_COOKIE[ LOGGED_IN_COOKIE ] ) || ! is_string( \$_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
		return 0;
	}

	\$parts = explode( '|', stripslashes( \$_COOKIE[ LOGGED_IN_COOKIE ] ) );
	if ( 4 !== count( \$parts ) ) { return 0; }
	list( \$username, \$expiration, \$token, \$hmac ) = \$parts;
	if ( '' === \$username || ! ctype_digit( \$expiration ) || '' === \$token || '' === \$hmac || (int) \$expiration < time() ) {
		return 0;
	}

	\$user = \$wpdb->get_row( \$wpdb->prepare( "SELECT ID, user_login, user_pass FROM {\$wpdb->users} WHERE user_login = %s LIMIT 1", \$username ) );
	if ( ! \$user ) { return 0; }

	// Derive pass_frag the same way WordPress core does in wp_validate_auth_cookie().
	\$pass_frag = substr( \$user->user_pass, 8, 4 );

	// wp_hash( data, 'logged_in' ) === hash_hmac( 'md5', data, LOGGED_IN_KEY . LOGGED_IN_SALT ).
	\$key = hash_hmac( 'md5', \$username . '|' . \$pass_frag . '|' . \$expiration . '|' . \$token, LOGGED_IN_KEY . LOGGED_IN_SALT );
	\$expected = hash_hmac( 'sha256', \$username . '|' . \$expiration . '|' . \$token, \$key );
	if ( ! function_exists( 'hash_equals' ) || ! hash_equals( \$expected, \$hmac ) ) { return 0; }

	// Session token validity (WP_Session_Tokens stores sessions in usermeta keyed by sha256(token)).
	\$raw = \$wpdb->get_var( \$wpdb->prepare( "SELECT meta_value FROM {\$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'session_tokens' LIMIT 1", (int) \$user->ID ) );
	\$sessions = is_string( \$raw ) ? @unserialize( \$raw ) : false;
	if ( ! is_array( \$sessions ) ) { return 0; }
	\$token_hash = hash( 'sha256', \$token );
	if ( ! isset( \$sessions[ \$token_hash ] ) || ! is_array( \$sessions[ \$token_hash ] ) || empty( \$sessions[ \$token_hash ]['expiration'] ) || (int) \$sessions[ \$token_hash ]['expiration'] < time() ) {
		return 0;
	}
	return (int) \$user->ID;
	}

/**
 * Skip filtering on the Safe Mode admin page so the plugin list sees the
 * real active_plugins state and checkboxes remain visible.
 */
function usm_should_filter_plugins() {
	if ( function_exists( 'is_admin' ) && is_admin() ) {
		if ( isset( \$_GET['page'] ) ) {
			\$page = sanitize_key( wp_unslash( \$_GET['page'] ) );
			if ( 'user-safe-mode' === \$page ) {
				return false;
			}
		}
	}

	\$uri = \$_SERVER['REQUEST_URI'] ?? '';
	if ( false !== strpos( \$uri, 'page=user-safe-mode' ) ) {
		return false;
	}

	return true;
}

add_filter( 'option_active_plugins', function ( \$plugins ) {
	if ( ! is_array( \$plugins ) ) { return \$plugins; }
	if ( ! usm_should_filter_plugins() ) { return \$plugins; }

	\$user_id = usm_get_user_id_from_cookie();
	if ( ! \$user_id ) { return \$plugins; }

	\$disabled = get_user_meta( \$user_id, '{$meta_key}', true );
	if ( empty( \$disabled ) || ! is_array( \$disabled ) ) {
		return \$plugins;
	}

	return array_values( array_diff( \$plugins, \$disabled ) );
} );

PHP;
}

// ============================================================================
//  MU PLUGIN FILE STATUS: proactive check for foreign files at usm-safe-mode.php
// ============================================================================
add_action( 'admin_init', 'usm_check_foreign_mu_plugin' );
function usm_check_foreign_mu_plugin() {
	if ( ! current_user_can( usm_required_capability() ) ) { return; }
	if ( ! file_exists( USM_MU_PLUGIN_PATH ) ) { return; }

	// Direct read is acceptable here: this is a small, local file check in the
	// wp-admin context, and the contents are not written back out or executed.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	$contents = file_get_contents( USM_MU_PLUGIN_PATH );
	if ( ! is_string( $contents ) ) {
		set_transient(
			'usm_mu_plugin_error',
			sprintf(
				/* translators: %s: path to the MU plugin file */
				__( 'Could not read the MU plugin at %s to verify ownership.', 'user-safe-mode' ),
				USM_MU_PLUGIN_PATH
			),
			HOUR_IN_SECONDS
		);
		return;
	}

	if ( false === strpos( $contents, 'User Safe Mode — MU plugin' ) ) {
		$message = sprintf(
			/* translators: %s: path to the MU plugin file */
			__( 'A file already exists at %s that was not generated by User Safe Mode. Safe Mode will not activate until you remove or rename this file.', 'user-safe-mode' ),
			USM_MU_PLUGIN_PATH
		);

		// Avoid spamming the same transient if it already carries the same message.
		$current = get_transient( 'usm_mu_plugin_error' );
		if ( $current !== $message ) {
			set_transient( 'usm_mu_plugin_error', $message, HOUR_IN_SECONDS );
		}
	}
}

// ============================================================================
//  PERSISTENT ADMIN NOTICE: shown on all admin screens when Safe Mode is active
// ============================================================================
add_action( 'admin_notices', 'usm_active_admin_notice' );
function usm_active_admin_notice() {
	if ( ! current_user_can( usm_required_capability() ) ) { return; }

	$user_id   = get_current_user_id();
	if ( ! $user_id ) { return; }

	$disabled  = get_user_meta( $user_id, USM_META_KEY, true );
	if ( ! is_array( $disabled ) || empty( $disabled ) ) { return; }

	$count     = count( $disabled );
	$clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=usm_clear' ), 'usm_clear' );
	?>
	<div class="notice notice-warning">
		<p>
			<strong>🛡️ <?php esc_html_e( 'Safe Mode is ON.', 'user-safe-mode' ); ?></strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of disabled plugins */
					_n( '%d plugin disabled for you.', '%d plugins disabled for you.', $count, 'user-safe-mode' ),
					$count
				)
			);
			?>
			<a href="<?php echo esc_url( $clear_url ); ?>" class="button button-small" style="margin-left:8px;vertical-align:baseline;">
				<?php esc_html_e( 'Restore all plugins', 'user-safe-mode' ); ?>
			</a>
		</p>
	</div>
	<?php
}

// ============================================================================
//  ADMIN PAGE: Top-level Safe Mode menu
// ============================================================================
add_action( 'admin_menu', 'usm_register_menu' );
function usm_register_menu() {
	add_menu_page(
		__( 'Safe Mode', 'user-safe-mode' ),
		__( 'Safe Mode', 'user-safe-mode' ),
		usm_required_capability(),
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
	if ( ! current_user_can( usm_required_capability() ) ) {
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

	usm_ensure_plugin_api();
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

	// Drop stale entries — only keep plugins that are currently installed.
	$disabled = array_values( array_intersect( $disabled, $all_plugins ) );
	update_user_meta( $user_id, USM_META_KEY, $disabled );

	wp_safe_redirect( admin_url( 'admin.php?page=' . USM_MENU_SLUG ) );
	exit;
}

add_action( 'admin_post_usm_bulk', 'usm_handle_bulk' );
function usm_handle_bulk() {
	if ( ! current_user_can( usm_required_capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'user-safe-mode' ) );
	}
	check_admin_referer( 'usm_bulk' );

	$user_id     = get_current_user_id();
	$plugins     = isset( $_POST['usm_plugins'] ) ? (array) $_POST['usm_plugins'] : [];
	$action      = sanitize_text_field( wp_unslash( $_POST['usm_bulk_action'] ?? '' ) );

	usm_ensure_plugin_api();
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

	// Drop stale entries — only keep plugins that are currently installed.
	$disabled = array_values( array_unique( array_intersect( $disabled, $all_plugins ) ) );
	update_user_meta( $user_id, USM_META_KEY, $disabled );

	wp_safe_redirect( admin_url( 'admin.php?page=' . USM_MENU_SLUG ) );
	exit;
}

add_action( 'admin_post_usm_clear', 'usm_handle_clear' );
function usm_handle_clear() {
	if ( ! current_user_can( usm_required_capability() ) ) {
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
	if ( ! current_user_can( usm_required_capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'user-safe-mode' ) );
	}

	$user_id       = get_current_user_id();
	$user_disabled = get_user_meta( $user_id, USM_META_KEY, true );
	if ( ! is_array( $user_disabled ) ) { $user_disabled = []; }

	usm_ensure_plugin_api();
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
	if ( ! current_user_can( usm_required_capability() ) ) { return; }

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
	if ( ! current_user_can( usm_required_capability() ) ) { return; }
	?>
	<style>
	#wpadminbar .usm-node.usm-active > a{background-color:#d63638!important;color:#fff!important;font-weight:600}
	#wpadminbar .usm-node.usm-active > a:hover{background-color:#ca312f!important}
	#wpadminbar .usm-node.usm-active > a:before{content:"\f113";font-family:dashicons;display:inline-block;-moz-osx-font-smoothing:grayscale;-webkit-font-smoothing:antialiased;font-size:16px;line-height:1;margin-right:4px;vertical-align:middle}
	</style>
	<?php
}
