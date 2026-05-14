=== NPC Maintenance Inspector ===
Contributors: npc01
Tags: maintenance, healthcheck, diagnostics, security, monitoring
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress maintenance health-check tool with 9-point diagnostics, history tracking, and optional AI-powered reports.

== Description ==

NPC Maintenance Inspector performs 9-point health diagnostics on your WordPress site directly from the admin dashboard:

1. **WordPress core updates** — Detect missing core updates.
2. **Plugin updates** — List plugins with available updates.
3. **Site Health issues** — Extract critical issues from WP standard Site Health API.
4. **PHP version** — Warn on end-of-life PHP versions.
5. **Error log analysis** — Summarize debug.log entries and save a one-click backup to uploads/ for safe review.
6. **File integrity** — Detect suspicious code patterns in core files (eval / base64_decode / etc.) by comparing checksums against the WordPress.org Checksum API.
7. **Suspicious files** — Find unexpected PHP files in wp-content/uploads/, with built-in whitelist for known legitimate plugin files (Ajax Load More templates, WP STAGING index.php, AIOS firewall rules, etc.).
8. **SSL certificate** — Check days until expiration (warn under 30 days, critical at 0).
9. **File permissions** — Check critical files like wp-config.php, .htaccess, wp-content/.

Operational features:

* **History** — Keep the last 10 diagnoses as a custom post type.
* **Scheduled auto-check** — Run daily, weekly, or monthly via WP Cron.
* **Email notifications** — Send alerts only on critical issues.
* **One-click debug.log clear** — Truncate the log while preserving file permissions.
* **Optional AI report** — Generate maintenance suggestions via Anthropic Claude API. Disabled by default; opt-in via a wp-config.php constant.

The plugin is locked to the user who activated it and to the original site URL, so it stays inert after backup restores to a different domain.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it via **Plugins → Add New** in the admin.
2. Activate **NPC Maintenance Inspector** through the **Plugins** menu.
3. Open **NPC Inspector** in the admin sidebar.
4. Click **Run Diagnosis** to start a manual check.
5. (Optional) Open **NPC Inspector → Settings** to enable scheduled auto-checks and email notifications.
6. (Optional) Define `NPCMI_API_KEY` in `wp-config.php` to enable the AI report feature (see FAQ).

== Frequently Asked Questions ==

= Is the AI report feature required? =

No. AI report is optional and **disabled by default**. To enable it, add this line above `/* That's all, stop editing! */` in your `wp-config.php`:

`define( 'NPCMI_API_KEY', 'sk-ant-xxxx' );`

Without that constant the plugin still runs all 9 diagnostics, scheduled checks, email notifications, and history.

= Does the plugin send any data externally? =

By default, **no**. Only the AI report feature sends data, and only to Anthropic Claude API, and only when explicitly triggered (manual "Generate AI Report" button, or automatic generation when a scheduled check detects a critical issue). See the **External services** section below for details.

= Who can use the plugin after activation? =

Only the user who activated it. The user ID/email and the site URL are recorded on first activation, and the plugin refuses to operate for other users or on a different domain. This is a deliberate constraint to keep the AI cost surface and the admin actions tied to a specific operator.

= Why are AI reports in Japanese? =

This plugin was initially built for Japanese-speaking WordPress maintainers. The AI prompt instructs Claude to reply in Japanese. We will localize the prompt for additional output languages in a future release.

= My site shows "suspicious files" in wp-content/uploads/. Should I delete them? =

Not automatically. The detector tries to whitelist known legitimate plugin files (Ajax Load More templates, WP STAGING index.php, AIOS firewall rules, etc.). Anything that still appears in the warning should be opened and inspected first — many backup/staging/security plugins legitimately drop PHP files there.

== Screenshots ==

1. Dashboard with the latest diagnosis result and overall grade.
2. 9-point health check cards (WP Core / Plugins / Site Health / PHP / Error Log / File Integrity / Suspicious Files / SSL / Permissions).
3. Diagnosis history accordion with per-entry download.
4. Settings — security and bound site URL.
5. Settings — automatic diagnosis and email notification.
6. Settings — AI report feature configuration.
7. AI report rendered with severity badges.
8. Suspicious files card with safe-by-default whitelist behavior.

== External services ==

This plugin can optionally connect to the **Anthropic Claude API** (`https://api.anthropic.com/v1/messages`) to generate AI-powered maintenance reports. This is the only external service the plugin ever contacts.

**When is data sent?**

* When you manually click the "Generate AI Report" button in the admin.
* When an automatic scheduled diagnosis detects a critical issue **and** notifications are enabled. In that case the AI report is generated once per critical run and attached to the notification email.

**What data is sent?**

* The diagnosis result text only:
  * Site URL, site name, WordPress version, theme name
  * Counts of plugin updates / Site Health issues / error-log entries
  * PHP version, memory limit, max upload size
  * SSL expiration date and days remaining
  * File-permission status of critical files
  * Suspicious code patterns detected (function names like `eval`, `base64_decode`)
* No login data, no post content, no personal data of site visitors.

**Is the data sent unconditionally?**

No. The AI feature is **completely disabled** unless you define `NPCMI_API_KEY` in `wp-config.php`. Without that constant, no external connection to Anthropic is ever made.

**Anthropic's terms and privacy policy:**

* Consumer Terms of Service: https://www.anthropic.com/legal/consumer-terms
* Privacy Policy: https://www.anthropic.com/legal/privacy
* Commercial Terms of Service (if you use a paid API plan): https://www.anthropic.com/legal/commercial-terms

By enabling the AI report feature (i.e. by defining `NPCMI_API_KEY`), you agree to Anthropic's applicable terms.

== Changelog ==

= 1.0.0 (2026-05-11) =
* Initial public release on WordPress.org (forked from the internal `npc-wp-healthcheck` 0.7.8 series).
* 9-point health diagnostics.
* Diagnosis history (last 10 entries) stored as a custom post type.
* WP Cron-based auto-check (daily / weekly / monthly).
* Email notifications on critical issues only.
* Optional AI-powered maintenance reports via Anthropic Claude API (opt-in via `NPCMI_API_KEY`).
* Full internationalization (English source strings, Japanese translation included).

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
