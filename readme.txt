=== SendSMS Subscribers & 2FA ===
Contributors: sendsms, neamtua
Tags: sms, sendsms, subscribers, 2fa, marketing
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.3
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage SMS subscribers, run campaigns, and protect wp-admin with SMS 2FA — all through the sendsms.ro gateway.

== Description ==
**SendSMS Subscribers & 2FA** connects your WordPress site to the [sendsms.ro](https://www.sendsms.ro/en/) SMS gateway and gives you two independent capabilities: a full subscriber management and SMS marketing system, and an SMS-based two-factor authentication layer on the wp-admin login form.

**Subscriber management and campaigns:**

* Subscribe and unsubscribe widgets you can drop into any widgetised area on the frontend.
* Optional phone-verification step on subscribe/unsubscribe (sends a one-time code; IP rate-limiting prevents abuse).
* Subscriber admin page with a searchable list table — add, edit, delete, or sync contacts to your sendsms.ro address book with one click.
* Mass-send SMS to all subscribers, or to WordPress users filtered by role.
* Complete SMS history log of every message the plugin sends.
* "Send a test SMS" page for verifying your sender label and message content against any phone number.

**SMS two-factor authentication:**

* Enable 2FA per user role — only the roles you pick require a second factor.
* On first login, users who don't yet have a phone number stored are prompted to enrol.
* A one-time code is sent to the user's phone; the wp-admin session is not opened until the code is validated.
* Codes are time-limited and bound to a signed cookie so they cannot be replayed.

**Compatibility:** PHP 7.4 through 8.3, WordPress 6.0 through 7.0. Verified on PHP 7.4 and PHP 8.3 against WordPress 7.0.

This plugin requires a [sendsms.ro](https://www.sendsms.ro/en/) account. Sign-up is free; SMS pricing is per message and depends on the destination country.

== Installation ==
1. Upload the `sendsms-subscribers-2fa` folder to `/wp-content/plugins/`, or install the plugin from the WordPress.org directory.
2. Activate the plugin under **Plugins → Installed Plugins**.
3. Go to **SendSMS Dashboard → Settings** and enter your sendsms.ro username, password, and sender label.

== Frequently Asked Questions ==

= Do I need a sendsms.ro account? =
Yes. Sign up for free at https://www.sendsms.ro/en/ and top up your balance. SMS are charged per message; pricing depends on the destination country.

= Does the 2FA work with custom login plugins? =
No. The 2FA feature is designed for the default wp-admin login form — the one WordPress ships at `/wp-login.php`. Custom login plugins or themes that replace the login form are not supported and may behave unpredictably. Always test in a development environment before enabling 2FA on a live site.

= What PHP and WordPress versions are supported? =
PHP 7.4 through PHP 8.3, WordPress 6.0 through 7.0. The plugin is verified on PHP 7.4 and PHP 8.3 against WordPress 7.0.

= A user is locked out because they lost their phone. How do I rescue them? =
You have three options, depending on your situation:

1. **Edit the user** — go to **Users → All Users**, open the locked-out user's profile, and clear the phone field. The next login will skip 2FA and prompt for a new number.
2. **Remove the role from 2FA** — in **SendSMS Dashboard → Settings → User**, deselect the user's role from the `2fa_roles` list and save. 2FA will no longer apply to that role.
3. **Sole-admin recovery** — if the locked-out user is the only admin, use WP-CLI (`wp option get sendsms_dashboard_plugin_settings`) or direct database access to clear the phone number stored in the plugin settings option, or to remove the role from `2fa_roles`.

= Will my version 1.x settings and data carry over? =
Yes. The subscriber table, the SMS history table, and the settings option all keep their v1.x names. No data migration is required; upgrading from 1.x to 2.0 is transparent.

= How do I add the subscribe / unsubscribe form to a page? =
Three ways, all produce the same HTML and behaviour:

* **Classic widget** — Appearance → Widgets, drag *SendSMS Subscription* or *SendSMS Unsubscribe* into a sidebar. Requires a theme that registers widget areas (or the *Classic Widgets* plugin).
* **Shortcode** — drop `[sendsms_subscribe]` or `[sendsms_unsubscribe]` into any post, page, or block. See attributes below.
* **Gutenberg block** — search for *SendSMS Subscribe* / *SendSMS Unsubscribe* in the block inserter (Widgets category). Title and GDPR URL are in the right-hand inspector.

= What attributes do the shortcodes accept? =

**`[sendsms_subscribe]`** — the subscribe form.

* `title` (string, optional) — heading rendered above the form.
* `gdpr_link` (URL, optional) — privacy-policy URL. When set, the GDPR consent label includes a "privacy policy" link pointing to it.

Example:
`[sendsms_subscribe title="Get SMS updates" gdpr_link="https://www.sendsms.ro/en/gdpr/"]`

**`[sendsms_unsubscribe]`** — the unsubscribe form.

* `title` (string, optional) — heading rendered above the form.

Example:
`[sendsms_unsubscribe title="Leave the SMS list"]`

Both shortcodes are safe to use multiple times on the same page; each form is independent.

= Which CSS classes do the subscribe / unsubscribe forms use? =
The same class names are emitted whether the form is rendered via the widget, the shortcode, or the Gutenberg block — so a single stylesheet covers all three. Use these to style the output from your theme or a custom CSS plugin.

* `.sendsms-dashboard-shortcode` — outermost wrapper when rendered via shortcode or block. Adds `-subscribe` or `-unsubscribe` as a modifier (`.sendsms-dashboard-shortcode-subscribe`, `.sendsms-dashboard-shortcode-unsubscribe`). The classic widget uses the theme's `before_widget` instead.
* `form.sendsms-dashboard-subscribe`, `form.sendsms-dashboard-unsubscribe` — the form element itself.
* `.sendsms-dashboard-field` — wraps every input row (`<p>` element). Modifier `.sendsms-dashboard-gdpr` on the GDPR consent row of the subscribe form.
* `.sendsms-dashboard-verify` — the hidden block containing the verification code input and "Verify" button. Revealed by JavaScript after the first AJAX response when phone verification is enabled. Use the `[hidden]` attribute selector if you want to override its initial state.
* `.sendsms-dashboard-feedback` — the `[role="status"]` paragraph used for status messages. Carries `data-state="ok"` or `data-state="error"` while a request is in flight.

The bundled `assets/css/public.css` styles every one of these classes; override or replace it from your theme's stylesheet as needed.

== External services ==

This plugin connects to the **sendsms.ro** SMS gateway — a third-party service operated by SC sendSMS Solutions SRL — to deliver text messages and manage your contact list. Using the plugin requires an active sendsms.ro account.

What the service is used for:

* Sending the subscribe/unsubscribe confirmation and one-time verification-code SMS to the phone numbers visitors enter in the frontend forms.
* Sending the two-factor-authentication code SMS to wp-admin users when SMS 2FA is enabled for their role.
* Sending the test SMS triggered from the **SendSMS Dashboard → Send a test SMS** page.
* Sending bulk SMS triggered from the **SendSMS Dashboard → SMS sending** page (to all subscribers, or to WordPress users filtered by role).
* Reading your account balance to display it on the **Settings** page.
* Creating and updating contacts (and the contact group) in your sendsms.ro address book when you sync a subscriber from the **Subscribers** page.

What data is sent, and when:

* On every outbound SMS: your sendsms.ro **username** and **API key/password**, the configured **sender label**, the **recipient phone number** (a visitor/subscriber number, an admin-supplied number for tests, or a user's stored number for 2FA), and the **message body**. Bulk sends POST the recipient list and message together as a batch.
* On a contact sync: your **username**, **API key/password**, and the subscriber's **phone number, first name, and last name**.
* On a balance check: your **username** and **API key/password**.
* No data is sent until you have entered credentials and either a visitor submits a form, a protected user logs in, or you press a send/sync button — or you open the Settings page, which checks the balance once per page load and caches it for 5 minutes.

Service endpoint used: `https://api.sendsms.ro/json` (HTTPS).

Third-party terms of service and privacy:

* Terms and conditions: https://www.sendsms.ro/en/terms-and-conditions/
* GDPR / privacy: https://www.sendsms.ro/en/gdpr/
* ISO 27001 certification: https://www.sendsms.ro/en/iso-27001-certified/

== Screenshots ==
1. Settings → General tab: API credentials (username, password, sender label) and country code selector.
2. Settings → User tab: enable 2FA, select which roles require it, and customise the verification message.
3. Settings → Subscription tab: toggle phone verification on subscribe/unsubscribe, set the IP rate limit, and manage blocked IPs.
4. Subscribers admin page: searchable list table with add, edit, delete, and sendsms.ro contact-sync actions.
5. History page: every SMS the plugin sent, with timestamp, recipient, message text, and delivery status.
6. Send a test SMS page: send a one-off message to any number to verify your sender label and content.

== Changelog ==
= 2.0.3 =
Documentation corrections for WordPress.org. The supported-versions statement in the description now reads WordPress 6.0 through 7.0 consistently with the header and FAQ, the installation step references the correct `sendsms-subscribers-2fa` folder, and the example URL in the shortcode documentation was replaced with a valid one. No functional or data changes.

= 2.0.2 =
Renamed the plugin to **SendSMS Subscribers & 2FA** (text domain `sendsms-subscribers-2fa`) so it can be published in the WordPress.org directory. No functional or data changes — your settings, subscribers, SMS history, shortcodes, blocks, widgets, and CSS classes are all unchanged.

= 2.0.1 =
Naming/compliance pass for WordPress.org. All internal identifiers now carry a distinct, collision-safe `rosendsms_dash_` prefix so the plugin coexists cleanly with other plugins (including *SendSMS for WooCommerce*). Your settings, SMS history, and subscriber list are migrated automatically on update.

* Prefixed every plugin-defined name: constants (`ROSENDSMS_DASH_*`), the PSR-4 namespace (`Rosendsms\Dashboard\…`), option/transient keys, custom table names, AJAX actions, script handles, the localized JS objects, and nonces.
* Added a one-shot, idempotent migration that renames the pre-2.0.1 `sendsms_dashboard_*` options and custom tables to the new `rosendsms_dash_*` names on activation/upgrade — no data loss.
* Front-end shortcodes (`[sendsms_subscribe]`, `[sendsms_unsubscribe]`), block names, widget IDs, and the documented CSS class names are unchanged, so existing pages, widgets, and custom styling keep working.
* Added an **External services** section to the readme disclosing the sendsms.ro gateway, the data sent, and links to its terms / privacy / ISO 27001 certification.
* Replaced the admin-menu dashicon with the sendSMS brand icon.

= 2.0.0 =
Full architectural rewrite. The plugin now follows modern WordPress conventions while preserving every existing setting, the SMS history database table, and the subscriber list — upgrading from 1.x is transparent.

* Code reorganised into a PSR-4 namespace tree (`SendSMS\Dashboard\…`) with one class per responsibility (API client, settings reader, subscribe / unsubscribe / 2FA flows, admin pages, AJAX handlers).
* 2FA rebuilt on the `authenticate` filter — no fake login + session destruction, no fork of `wp-login.php`. Cleaner, fewer surprises with other plugins.
* Settings page redesigned with three tabs: **General**, **User**, **Subscription**. Each tab merges into the existing option instead of overwriting it (fixes a v1.x bug where saving one tab wiped the others).
* Admin scripts moved to `assets/js/`. jBox dropped; native WordPress admin patterns used instead.
* Mass-send campaigns now build the CSV in memory (no `batches/` filesystem write, matching the security baseline of the WooCommerce sibling plugin).
* Minimum PHP raised to 7.4; verified on PHP 7.4 and PHP 8.3 against WordPress 7.0.

= 1.0.3 =
* WordPress 7.0 compatibility.
* PHP 8.3 compatibility: removed deprecated FILTER_SANITIZE_STRING usage in the 2FA login handlers.
* Synced version numbers across the plugin header, version constant, and stable tag.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==
= 2.0.3 =
Documentation-only corrections (supported versions, install instructions, example URL). No functional or data changes.

= 2.0.2 =
Plugin renamed to "SendSMS Subscribers & 2FA" for the WordPress.org directory. No functional or data changes.

= 2.0.1 =
Internal names are now prefixed `rosendsms_dash_`. Settings, SMS history, and subscribers migrate automatically; shortcodes, blocks, widgets, and CSS classes are unchanged.

= 2.0.0 =
Full rewrite under the SendSMS\Dashboard namespace. Settings, SMS history, and subscriber data carry over automatically.
