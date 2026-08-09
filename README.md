# 🛡️ User Safe Mode

**Debug like a pro** — disable plugins just for yourself. Other users and visitors see the site untouched.

![Requires WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-blue) ![Requires PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-blue) ![License GPL v2](https://img.shields.io/badge/License-GPL%20v2-green)

---

## 📖 What It Does

User Safe Mode lets you selectively disable plugins for **your own user account only**. Think of it as a private "safe mode" for debugging.

**Use case:** A client reports an error on their live site. You suspect a plugin conflict. Instead of deactivating plugins for everyone (breaking the site for the client), you disable them just for yourself, confirm the fix, and re-enable — all without affecting anyone else.

---

## 🚀 Installation

1. Download the ZIP file from the [Releases page](https://github.com/allmoinahmed/user-safe-mode/releases).
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Activate**.
4. You'll see a new **Safe Mode** menu in your admin sidebar (shield icon).

That's it. The plugin auto-creates a small MU plugin needed for the filtering to work.

---

## 🎮 How To Use

### Per-plugin toggle (quickest)
1. Go to **Safe Mode** in the admin sidebar.
2. Find the plugin you want to test.
3. Click **Disable for you** in the Action column.
4. Reload any page — that plugin's content, CSS, and JS are gone.
5. Click **Enable for you** to turn it back on.

### Bulk actions
1. Go to **Safe Mode**.
2. Check the boxes next to plugins you want to disable or enable.
3. Select **Disable for you** or **Enable for you** from the Bulk Actions dropdown.
4. Click **Apply**.

### Clear all
Click **Clear All & Exit Safe Mode** — everything comes back for you immediately.

### Admin bar indicator
When Safe Mode is active, the admin bar shows a **red "Safe Mode: N disabled"** badge. Click it to jump to the Safe Mode page, or use the **Clear** sub-link.

---

## ⚙️ How It Works

WordPress loads plugins in this order:

```
wp-settings.php loads:
  1. wp-includes/load.php          → basic functions (is_admin(), etc.)
  2. wp-includes/pluggable.php     → is_user_logged_in(), wp_get_current_user()
  3. mu-plugins/*.php              → must-use plugins
  4. active plugins (from DB)      → regular plugins
```

On **activation**, User Safe Mode writes a tiny **MU plugin** to `wp-content/mu-plugins/usm-safe-mode.php`.

On **every request**, that MU plugin:

1. **Reads only WordPress's current logged-in cookie** (`LOGGED_IN_COOKIE`) and verifies its HMAC signature, expiration timestamp, and session token before any plugin filtering is applied. The username is **never trusted from raw cookie data**. If the cookie cannot be verified, no filtering occurs.
2. **Looks up your disabled list** from `wp_usermeta` (meta key: `_usm_disabled_plugins`).
3. **Filters `option_active_plugins`** — removes your disabled plugins from the active list before any regular plugin code runs.

### Why an MU plugin?

The `option_active_plugins` filter fires at the very start of WordPress bootstrap — before regular plugins are loaded. A regular plugin would be too late because the option is already cached. An MU plugin loads just early enough to hook in.

The MU plugin is generated automatically — you don't need to create or manage it. On deactivation, it's removed cleanly.

---

## ✅ What's Disabled For You

When you disable a plugin for yourself, that plugin's **entire** code stops running for you:

- ✅ PHP (hooks, filters, shortcodes, widgets)
- ✅ JavaScript / CSS (enqueued by the plugin)
- ✅ REST API endpoints registered by the plugin
- ✅ Admin pages registered by the plugin
- ❌ Shortcodes from that plugin appear as raw text (debugging clue)

Other users and visitors see everything working normally.

---

## ⚠️ Important Notes

- **Multisite not supported — and disabled at runtime.** User Safe Mode detects multisite installs on every load and refuses to activate. An admins-only error notice is shown, all admin pages, the admin bar item, and the MU-plugin writer are skipped, and any active MU plugin from a previous install is removed. Single-site installs only.
- **Identity is cryptographically verified.** On each request the MU plugin validates WordPress's current logged-in cookie, including its HMAC signature, expiration, and session token. It never trusts a raw username or a second/stale logged-in cookie, so a forged cookie cannot disable plugins for another user. If validation fails, the original plugin list is returned unchanged.
- **Your disabled list persists across logouts.** Stay in Safe Mode until you explicitly clear it.
- **The User Safe Mode plugin itself** is hidden from the disable list — you can't accidentally lock yourself out.
- **The MU plugin** (`wp-content/mu-plugins/usm-safe-mode.php`) is auto-managed. Don't edit it directly.
- **Two users can have different disabled lists** — each user's settings are stored in their own `wp_usermeta`.
- **Deactivation preserves your settings.** If you deactivate and reactivate the plugin, your disabled-plugin list is restored. Only deleting (uninstalling) the plugin wipes all user data.
- **Capability filter.** The default required capability is `manage_options`, but this can be customized via the `usm_required_capability` filter.

---

## 🧪 When To Use This

| Situation | Without User Safe Mode | With User Safe Mode |
|---|---|---|
| Client site has a JS error | Deactivate plugin → site breaks for client | Disable for you → see the error gone, client unaffected |
| Testing a plugin update | Deactivate on staging, guess | Test live in your own session |
| Support debugging | Ask client to reproduce | Check yourself immediately |

---

## 📦 File Structure

```
user-safe-mode/
├── user-safe-mode.php      # Main plugin (admin UI, lifecycle, ZIP distribution)
├── readme.txt              # Internal readme (not for WordPress.org)
└── README.md               # This file
```

On activation, a generated file is written to:

```
wp-content/mu-plugins/usm-safe-mode.php   # MU plugin (auto-managed)
```

---

## 📄 License

GPLv2 or later. Free to use, modify, and share.

---

## 🤝 Contributing

Found a bug or have an idea? Open an [issue](https://github.com/allmoinahmed/user-safe-mode/issues) or submit a pull request.
