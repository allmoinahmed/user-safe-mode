=== User Safe Mode ===
Contributors: yourusername
Tags: debug, development, troubleshooting, plugin, mu-plugin
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Debug like a pro — disable plugins just for yourself. Other users and visitors see the site untouched.

== Description ==

User Safe Mode is a debugging tool. It lets you disable any plugin from the
plugin's own admin page, but only for **your logged-in user account** — visitors,
clients, and other admins see no change.

== Installation ==

1. Upload the `user-safe-mode` folder (or just `user-safe-mode.php`) to `/wp-content/plugins/`.
2. Activate the plugin through the *Plugins* menu in WordPress.
3. Visit the new *Safe Mode* menu in your admin sidebar.

== Changelog ==

= 1.2.0 =
* Security: MU plugin now uses wp_validate_auth_cookie() instead of reading raw cookie username.
* Reliability: Robust error handling for MU plugin file creation and removal.
* Multisite: Explicitly blocked with admin notice.
* UX: Persistent admin notice when Safe Mode is active with "Restore all" link.
* Cleanup: Deactivation preserves user settings; only uninstall wipes them.
* Capabilities: New filterable `usm_required_capability` hook.
* Hardening: Stale/missing plugin entries are filtered out automatically.

= 1.0.0 =
* First release.
