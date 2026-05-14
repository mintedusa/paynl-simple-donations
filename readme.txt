=== PAY.nl Simple Donations ===
Contributors: cristian
Tags: donations, pay.nl, payment, nonprofit
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Lightweight one-step donation form integrated with PAY.nl Order API (v1).
Preset amounts + custom amount, single-click checkout. No bloat.

== Description ==

Custom donation plugin built directly against the PAY.nl REST API v1
(`https://connect.pay.nl/v1/orders`). No third-party gateway plugin and
no recurring license fees.

Features:
* 4 preset amount buttons + optional custom amount
* Optional email (for receipt) and name fields
* One-click redirect to PAY.nl hosted checkout (Bancontact, iDEAL, credit card, etc.)
* Webhook handler for transaction status updates
* Test mode toggle
* Override settings per shortcode placement

== Installation ==

1. Upload the `paynl-simple-donations` folder to `/wp-content/plugins/` (or upload the ZIP via Plugins → Add New → Upload).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings → PAY.nl Donations** and fill in:
   - Token code (AT-... from Merchant → Company information)
   - API token (40-char hash from same screen)
   - Service ID (SL-... from Settings → Sales locations)
4. Copy the displayed Webhook URL and paste it as the **exchange URL** in your PAY.nl service location settings.
5. Place `[paynl_donate]` on any page.

== Usage ==

Basic:
`[paynl_donate]`

Override amounts:
`[paynl_donate amounts="5,15,30,100"]`

Custom heading:
`[paynl_donate title="Steun ons werk"]`

Custom statement description (max 30 chars, shown on donor's bank statement):
`[paynl_donate description="Maandgift mei"]`

== Hooks ==

Action `paynl_donations_status_update` fires when the webhook receives a status update from PAY.nl:

`do_action( 'paynl_donations_status_update', $order_id, $state, $full_response );`

Use it to send custom thank-you emails, log to a CRM, etc.

== Changelog ==

= 1.0.0 =
* Initial release.
