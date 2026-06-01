# Qaiyo Access Manager

> Fine-grained plugin and Custom Post Type access control for WordPress — at the role **and** individual user level.

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![Version](https://img.shields.io/badge/version-1.0.0-orange.svg)](#)

Qaiyo Access Manager extends WordPress's built-in role/capability system with two missing pieces every real-world site needs:

1. **Choose which plugins each role/user can see and manage** — without writing code or modifying `wp-config.php`.
2. **Restrict any Custom Post Type** (WooCommerce Products, ACF, custom CPTs, etc.) per role or per user — admin UI, REST API and frontend together.

It's freemium: the free version (this repo) is fully functional. A separate **Pro add-on** unlocks an editable access matrix, presets, user groups, and bulk actions.

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [How access decisions work](#how-access-decisions-work)
- [Translation plugin compatibility](#translation-plugin-compatibility)
- [Translations](#translations)
- [Developer hooks](#developer-hooks-for-pro--customisation)
- [Uninstall behavior](#uninstall-behavior)
- [Pro version](#pro-version)
- [WordPress.org compliance](#wordpressorg-compliance)
- [Changelog](#changelog)
- [Credits](#credits--license)

---

## Features

### Free vs Pro at a glance

| Category | Feature | Free | Pro |
|---|---|:---:|:---:|
| **Access control** | Per-role plugin access | ✅ | ✅ |
| | Per-role CPT access (admin + REST + frontend) | ✅ | ✅ |
| | Per-user *allow* override | ✅ | ✅ |
| | Per-user *deny* override | ✅ | ✅ |
| | Native WP capability info display | ✅ | ✅ |
| | Administrator safety lock | ✅ | ✅ |
| **Tools tab** | JSON Export (backup / migration) | ✅ | ✅ |
| | JSON Import (drag & drop, validated) | ✅ | ✅ |
| | Uninstall behavior toggle (keep / delete data) | ✅ | ✅ |
| **Access Matrix** | Read-only bird's-eye matrix | ✅ | ✅ |
| | **Click-to-edit matrix cells** | — | ✅ |
| **Rule presets** | Save current rule set under a name | — | ✅ |
| | Apply preset with one click | — | ✅ |
| **User groups** | Custom groups beyond WP roles | — | ✅ |
| | Group-level allow rules (additive) | — | ✅ |
| **Bulk actions** | Multi-select plugins / CPTs | — | ✅ |
| | Apply or clear roles in bulk | — | ✅ |
| **Dashboard** | Live stats widget (3 counters) | ✅ | ✅ |
| **Admin UX** | AJAX save, no reloads | ✅ | ✅ |
| | Live search + expand/collapse | ✅ | ✅ |
| | Restricted-user notice on Plugins page | ✅ | ✅ |
| | Brand menu grouping (Qaiyo plugin family) | ✅ | ✅ |
| **i18n** | 5 built-in languages (EN/HU/DE/FR/ES) | ✅ | ✅ |
| | WPML / Polylang / TranslatePress compatibility | ✅ | ✅ |
| **WP.org** | Plugin Check compliant | ✅ | n/a |
| **Auto-updates** | Built-in via Qaiyo Licensing Server | — | ✅ |

### Counts

| | Free | Pro adds |
|---|:---:|:---:|
| Admin tabs | **4** | **+2** (Presets, Groups) |
| Distinct features | **15** | **+8** |
| Tools | **3** | **+3** (editable matrix, presets, bulk) |
| Access rule layers | **2** (role, user) | **+1** (groups) |
| Languages | **5** | **5** |
| Translation plugin integrations | **3** | **3** |

---

## Installation

### From wp-admin (recommended)
1. Plugins → Add New → Upload the ZIP from [Releases](../../releases) (or build it yourself).
2. Activate.
3. Navigate to **Access Manager** in the sidebar.

### Manual
1. Drop the unzipped folder into `wp-content/plugins/`.
2. Activate from the Plugins page.

### Requirements
| | Minimum |
|---|---|
| WordPress | 5.8 |
| PHP | 7.4 |
| Capability needed to configure | `manage_options` |

---

## Configuration

After activation a top-level **Access Manager** menu appears (under the shared "Qaiyo Plugins" label if you have other Qaiyo plugins).

The settings page has 4 tabs:

| Tab | Purpose |
|---|---|
| **Plugins** | Per-plugin role checkboxes + per-user allow/deny |
| **Content Types (CPT)** | Per-CPT role checkboxes + per-user allow/deny |
| **Access Matrix** | Read-only overview of all rules |
| **Tools** | Export / Import / Uninstall behavior |

Click **Save settings** at the bottom — saves via AJAX, no page reload.

---

## How access decisions work

Every access check (plugin visibility, CPT admin menu, edit screen, REST API, frontend, `map_meta_cap`) goes through the same priority chain:

```
1. Administrator?                      → ALLOW (always)
2. User in deny list for this item?    → DENY
3. User in allow list for this item?   → ALLOW
4. Pro: any group rule applies?        → consult Pro filter (group rules ADD access)
5. Role rule defined for this item?    → ALLOW if any of user's roles is checked
6. No rule at all?                     → ALLOW (default open)
```

This means **rules are opt-in**: an item without any rule is freely accessible. As soon as you check even one role, the item becomes restricted to the checked roles + user-level overrides.

---

## Translation plugin compatibility

Plays nicely with major translation plugins out of the box:

- **WPML**, **Polylang**, **TranslatePress** — their internal CPTs (`wp_translation`, `wpml_translation_job`, `polylang_mo`, `trp_translation`, `trp_language`) are excluded from the manageable list so you never accidentally lock yourself out of translations.
- The plugin's own `pre_get_posts` and `rest_pre_dispatch` filters do **not** interfere with `lang` / `polylang_lang` / `trp_lang` query variables.

---

## Translations

Ships with full translations for 5 locales:

| Locale | Language |
|---|---|
| `en_US` | English (source) |
| `hu_HU` | Hungarian |
| `de_DE` | German |
| `fr_FR` | French |
| `es_ES` | Spanish |

Regional variants (e.g. `de_AT`, `fr_CA`, `es_MX`) automatically fall back to the closest supported locale.

`.mo` files are compiled and committed; `.po` sources are in `languages/`. The text domain is `qaiyo-access-manager` — WordPress 4.6+ auto-loads it from `/languages` without an explicit `load_plugin_textdomain()` call (per the [latest wp.org guidelines](https://make.wordpress.org/core/2024/04/01/i18n-improvements-in-wordpress-6-5/)).

---

## Developer hooks (for Pro / customisation)

The free plugin exposes the following extension points — used by the Pro add-on, available to anyone:

### Filters

| Filter | Args | Purpose |
|---|---|---|
| `wpam_group_access_plugin` | `$result = null, $plugin_file, $user` | Allow a 3rd-party (e.g. Pro Groups) to grant/deny plugin access before the role rule runs. Return `null` to pass through, `true`/`false` to decide. |
| `wpam_group_access_cpt` | `$result = null, $post_type, $user` | Same, but for CPTs. |
| `wpam_valid_tabs` | `array $tabs` | Register additional valid tab slugs (e.g. `'presets'`, `'groups'`). |
| `wpam_hide_toolbar_tabs` | `array $tabs` | Tab slugs where the search/expand toolbar + Save bar should be hidden. |
| `wpam_render_matrix` | `bool $handled, $plugins, $cpts, $roles, $plugin_rules, $cpt_rules` | Replace the default read-only matrix. Return `true` after rendering your own. |

### Actions

| Action | Args | When it fires |
|---|---|---|
| `wpam_after_tabs` | `$active_tab` | After all built-in tab `<a>` elements — append your own. |
| `wpam_toolbar_actions` | `$active_tab` | Inside the toolbar `.wpam-actions` div — append extra buttons. |
| `wpam_render_tab_content` | `$active_tab, $plugins, $cpts, $roles, $plugin_rules, $cpt_rules` | Render content for a tab the free plugin doesn't know about. |
| `wpam_after_save_access` | — | After any rule save (AJAX or via Pro flows). |

### Brand menu integration

If you ship a Qaiyo-branded plugin, call:

```php
if ( class_exists( 'Wpam_Brand_Menu' ) ) {
    Wpam_Brand_Menu::register_plugin_slug( 'your-plugin-menu-slug' );
    $position = Wpam_Brand_Menu::plugin_position(); // use as menu_position
}
```

The first Qaiyo plugin to load defines `Wpam_Brand_Menu` (or one of `Qt_Brand_Menu`, `Qdp_Brand_Menu`, etc.); the others detect it through the shared `$GLOBALS['qaiyo_brand_menu_slugs']` registry and skip re-injecting the chip. This is the single intentional non-prefixed global in the codebase (documented with a `phpcs:ignore`).

---

## Uninstall behavior

By default, deleting the plugin from the Plugins page **preserves** all access rules in the database — so reinstalling restores everything.

To opt into full deletion: **Tools → Uninstall behavior → check the box**. The setting is stored as `wpam_delete_data_on_uninstall` and is checked by `uninstall.php`. The preference itself is always removed on uninstall (it's plugin-specific metadata).

---

## Pro version

A separate add-on plugin, **Qaiyo Access Manager Pro**, unlocks:

| Feature | What it does |
|---|---|
| **Editable Access Matrix** | Click any cell to toggle access. One-click save for all rules at once. |
| **Rule Presets** | Save the current rule set under a name ("Editor basic", "Shop manager"). Apply with one click. |
| **User Groups** | Define groups beyond WP roles. Members of a group can access checked items — even if their role couldn't. |
| **Bulk Actions** | Multi-select plugins/CPTs, apply roles to all at once, or clear rules in bulk. |

Pro is fully optional and installs as a regular WordPress plugin alongside the free version. It auto-updates via the Qaiyo Licensing Server using the shared Qaiyo SDK.

Pricing tiers (one license, no Personal/Business/Agency separation):

| Plan | Price | Sites |
|---|---|---|
| **Yearly** | $80 / year | up to 8 sites |
| **Lifetime** | $224 once | up to 24 sites |

A future **Qaiyo All-Access** bundle ($179/year, $499 lifetime) will cover every Qaiyo Pro plugin under a single license.

License management lives at `Access Manager → License` (added automatically by the SDK as a submenu).

---

## WordPress.org compliance

The plugin is built to pass the official **Plugin Check** static analyzer without warnings:

- ✅ No `load_plugin_textdomain()` (WP 4.6+ auto-loads).
- ✅ Class prefixes match the plugin folder slug (`Wpam_`).
- ✅ Every `$_POST` / `$_GET` access is explicitly `sanitize_text_field( wp_unslash( ... ) )` on its own line so the PHPCS sniffer can see it.
- ✅ Read-only `$_GET` (tab navigation) has the documented `phpcs:ignore WordPress.Security.NonceVerification.Recommended` exception.
- ✅ `readme.txt`: max 5 tags, `Tested up to: 7.0`, valid `Stable tag`.
- ✅ All globals are plugin-prefixed except for one intentional cross-plugin coordination registry (`$GLOBALS['qaiyo_brand_menu_*']`) — documented with `phpcs:ignore` and a comment explaining why prefixing would break the brand menu.

---

## Repository structure

```
wp-plugin-access-manager/
├── wp-plugin-access-manager.php   # Main plugin file (Wpam_Access_Manager class)
├── uninstall.php                  # Conditional cleanup based on user opt-in
├── readme.txt                     # wp.org plugin readme
├── README.md                      # This file
├── includes/
│   └── class-wpam-brand-menu.php  # Cross-Qaiyo-plugin brand menu grouping
├── assets/
│   ├── css/admin.css              # Settings page styles (~730 lines)
│   └── js/admin.js                # Settings page JS (~430 lines)
└── languages/
    ├── qaiyo-access-manager.pot
    ├── qaiyo-access-manager-{en_US,hu_HU,de_DE,fr_FR,es_ES}.po
    └── qaiyo-access-manager-{hu_HU,de_DE,fr_FR,es_ES}.mo
```

---

## Changelog

### 1.0.0 — Initial public release
- Plugin-level access control per role and per user.
- CPT-level access control per role and per user (admin menu, list tables, edit, REST, frontend, `map_meta_cap`).
- Native WordPress capability info display on every card.
- AJAX-saved settings with live status feedback.
- 5 languages (EN/HU/DE/FR/ES) with regional fallback.
- Translation plugin compatibility (WPML/Polylang/TranslatePress internal CPTs excluded).
- **Tools** tab: JSON Export, JSON Import (with drag-and-drop), Uninstall behavior toggle.
- **Access Matrix** tab: read-only overview of all rules across plugins/CPTs and roles.
- Dashboard widget with live stats.
- Brand menu grouping for the Qaiyo plugin family.
- Pro-extension hooks (filters and actions) for the optional Qaiyo Access Manager Pro add-on.
- Full WordPress.org Plugin Check compliance.

---

## Credits & License

**Qaiyo by PixelDesigns**
🌐 [qaiyo-plugins.com](https://qaiyo-plugins.com) ・ ✉️ [info@qaiyo-plugins.com](mailto:info@qaiyo-plugins.com)

Released under the **GPL v2 or later** — see [`LICENSE`](https://www.gnu.org/licenses/gpl-2.0.html).

If you find a bug or have a feature request, please [open an issue](../../issues). Pull requests welcome.
