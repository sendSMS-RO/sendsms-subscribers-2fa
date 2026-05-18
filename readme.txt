=== SendSMS Dashboard ===
Contributors: neamtua, catalinsendsms
Tags: sms, sendsms, subscribers, 2fa, marketing
Requires at least: 4.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage SMS subscribers, run campaigns, and protect wp-admin with SMS 2FA — all through the sendsms.ro gateway.

== Description ==
**SendSMS Dashboard** connects your WordPress site to the [sendsms.ro](https://www.sendsms.ro/en/) SMS gateway and gives you two independent capabilities: a full subscriber management and SMS marketing system, and an SMS-based two-factor authentication layer on the wp-admin login form.

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

**Compatibility:** PHP 7.4 through 8.3, WordPress 4.0 through 7.0. Verified on PHP 7.4 and PHP 8.3 against WordPress 7.0.

This plugin requires a [sendsms.ro](https://www.sendsms.ro/en/) account. Sign-up is free; SMS pricing is per message and depends on the destination country.

== Installation ==
1. Upload the `sendsms-dashboard` folder to `/wp-content/plugins/`, or install the plugin from the WordPress.org directory.
2. Activate the plugin under **Plugins → Installed Plugins**.
3. Go to **SendSMS Dashboard → Settings** and enter your sendsms.ro username, password, and sender label.

== Frequently Asked Questions ==

= Do I need a sendsms.ro account? =
Yes. Sign up for free at https://www.sendsms.ro/en/ and top up your balance. SMS are charged per message; pricing depends on the destination country.

= Does the 2FA work with custom login plugins? =
No. The 2FA feature is designed for the default wp-admin login form — the one WordPress ships at `/wp-login.php`. Custom login plugins or themes that replace the login form are not supported and may behave unpredictably. Always test in a development environment before enabling 2FA on a live site.

= What PHP and WordPress versions are supported? =
PHP 7.4 through PHP 8.3, WordPress 4.0 through 7.0. The plugin is verified on PHP 7.4 and PHP 8.3 against WordPress 7.0.

= A user is locked out because they lost their phone. How do I rescue them? =
You have three options, depending on your situation:

1. **Edit the user** — go to **Users → All Users**, open the locked-out user's profile, and clear the phone field. The next login will skip 2FA and prompt for a new number.
2. **Remove the role from 2FA** — in **SendSMS Dashboard → Settings → User**, deselect the user's role from the `2fa_roles` list and save. 2FA will no longer apply to that role.
3. **Sole-admin recovery** — if the locked-out user is the only admin, use WP-CLI (`wp option get sendsms_dashboard_plugin_settings`) or direct database access to clear the phone number stored in the plugin settings option, or to remove the role from `2fa_roles`.

= Will my version 1.x settings and data carry over? =
Yes. The subscriber table, the SMS history table, and the settings option all keep their v1.x names. No data migration is required; upgrading from 1.x to 2.0 is transparent.

== Screenshots ==
1. Settings → General tab: API credentials (username, password, sender label) and country code selector.
2. Settings → User tab: enable 2FA, select which roles require it, and customise the verification message.
3. Settings → Subscription tab: toggle phone verification on subscribe/unsubscribe, set the IP rate limit, and manage blocked IPs.
4. Subscribers admin page: searchable list table with add, edit, delete, and sendsms.ro contact-sync actions.
5. History page: every SMS the plugin sent, with timestamp, recipient, message text, and delivery status.
6. Send a test SMS page: send a one-off message to any number to verify your sender label and content.

== Changelog ==
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
= 2.0.0 =
Full rewrite under the SendSMS\Dashboard namespace. Settings, SMS history, and subscriber data carry over automatically.
