<?php
/**
 * Plugin Name: User Safe Mode
 * Plugin URI:  https://github.com/yourusername/user-safe-mode
 * Description: Debug like a pro — disable plugins just for yourself. Other users and visitors see the site untouched.
 * Version:     1.2.0
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
 * - MU plugins load AFTER pluggable.php in WordPress 6.0+, so is_user_logged_in(),
 *   wp_validate_auth_cookie(), and all pluggable functions are available
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
	if ( file_exists( $dest ) ) {
		$existing = file_get_contents( $dest );
		if ( false !== strpos( $existing, 'User Safe Mode — MU plugin' ) ) {
			return true;
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$written = file_put_contents( $dest, usm_build_mu_code() );
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
//  IMPORTANT: MU plugins load AFTER pluggable.php in WordPress 6.0+, so
//  wp_validate_auth_cookie() IS available. We use it directly to verify the
//  authentication cookie — this validates:
//    - Cookie HMAC signature (cryptographic proof the cookie wasn't forged)
//    - Expiration timestamp
//    - Session token validity
//  Only after all checks pass do we apply per-user plugin filtering.
//  If identity cannot be verified, we return the unmodified plugin list
//  (fail closed).
// ============================================================================
function usm_build_mu_code() {
	$meta_key = USM_META_KEY;
	return <<<PHP
<?php
/**
 * User Safe Mode — MU plugin.
 * Auto-generated by User Safe Mode v1.2.0. Do not edit directly.
 *
 * Hooks option_active_plugins before regular plugins load, filtering out
 * plugins the current user has chosen to disable. Identity is verified via
 * wp_validate_auth_cookie() using the same HMAC and session-token checks
 * WordPress itself performs — no raw cookie data is trusted.
 *
 * @license GPL-2.0-or-later
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Identify the logged-in user using WordPress's own cookie validation.
 *
 * Looks for a wordpress_logged_in_* cookie and passes it through
 * wp_validate_auth_cookie() which checks:
 *   - HMAC signature against the user's password hash fragment
 *   - Cookie expiration
 *   - Session token validity
 *
 * @return int 0 if identity cannot be verified, the user ID otherwise.
 */
function usm_get_user_id_from_cookie() {
	if ( empty( \$_COOKIE ) || ! is_array( \$_COOKIE ) ) {
		return 0;
	}

	foreach ( \$_COOKIE as \$name => \$value ) {
		if ( strpos( \$name, 'wordpress_logged_in_' ) !== 0 ) {
			continue;
		}

		\$user_id = wp_validate_auth_cookie( wp_unslash( \$value ), 'logged_in' );
		if ( \$user_id && is_numeric( \$user_id ) ) {
			return (int) \$user_id;
		}
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
