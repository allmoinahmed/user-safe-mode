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

If you deactivate User Safe Mode itself, its per-user filtering is stopped before WordPress saves the active plugin list. Version 1.1.6 also guards the option write directly, so plugins previously disabled only for you cannot be accidentally saved as site-wide deactivations.

### Admin bar indicator
When Safe Mode is active, the admin bar shows a **red "Safe Mode: N disabled"** badge. Click it to jump to the Safe Mode page, or use the **Clear** sub-link.

---

## ⚙️ How It Works

WordPress loads plugins in this order:

```
wp-settings.php loads:
  1. wp-includes/load.php          → basic functions (is_admin(), etc.)
  2. mu-plugins/*.php              → must-use plugins
  3. active plugins (from DB)      → regular plugins
```

On **activation**, User Safe Mode writes a tiny **MU plugin** to `wp-content/mu-plugins/usm-safe-mode.php`.

On **every request**, that MU plugin:

1. **Finds the current user** by reading the WordPress logged-in cookie (`wordpress_logged_in_<hash>`) and looking that username up in `wp_users`. If no logged-in cookie is present (a visitor), no filtering occurs.
2. **Looks up that user's disabled list** from `wp_usermeta` (meta key: `_usm_disabled_plugins`).
3. **Filters `option_active_plugins`** (and `option_active_sitewide_plugins` for multisite) — removes that user's disabled plugins from the active list before any regular plugin code runs.

Because only the current request's own cookie is used, each user's disabled list applies only to that user.

Whenever Safe Mode is active for the current user, the MU plugin also sends **no-store anti-cache headers** (`Cache-Control: no-cache, no-store, must-revalidate`, `Vary: Cookie`, and defines `DONOTCACHEPAGE`). This guarantees a Safe Mode response is never cached by a page cache, object cache, or CDN and served to another user or visitor.

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

- **Identity comes from your own login cookie.** On each request the MU plugin reads the `wordpress_logged_in_*` cookie and resolves that username to a user ID. No cookie, no filtering — visitors and logged-out users always get the full plugin list.
- **Safe Mode responses are never cached.** When your Safe Mode is active, the page is marked `no-store` so no cache (page cache, object cache, CDN) can store and leak it to other users or visitors.
- **Your disabled list persists across logouts.** Stay in Safe Mode until you explicitly clear it.
- **The User Safe Mode plugin itself** is hidden from the disable list — you can't accidentally lock yourself out.
- **The MU plugin** (`wp-content/mu-plugins/usm-safe-mode.php`) is auto-managed. Don't edit it directly.
- **Two users can have different disabled lists** — each user's settings are stored in their own `wp_usermeta`.
- **Deactivation clears your settings.** Deactivating the plugin removes the MU plugin and wipes all users' disabled-plugin lists. Deleting (uninstalling) the plugin does the same.
- **Capability required.** Only users with `manage_options` capability can use Safe Mode.

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
