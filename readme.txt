=== User Safe Mode ===
Contributors: yourusername
Tags: debug, development, troubleshooting, plugin, mu-plugin
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.6
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

= 1.1.6 =
* Prevents the filtered plugin list from being saved during User Safe Mode deactivation.

= 1.1.5 =
* Disabling User Safe Mode now restores all plugins to their normal active state.

= 1.1.4 =
* Safe Mode responses are never cached, so a disabled plugin can never leak to other users or visitors.

= 1.1.3 =
* Identity is resolved from the user's own WordPress logged-in cookie only.

= 1.0.0 =
* First release.
