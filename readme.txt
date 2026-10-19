=== Primiko - Quote Requests by Trade ===
Contributors: nolidev
Tags: quote, request a quote, multi-step form, contractor, tradesman
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Quote request forms made for each trade: the right questions in the right order, with photos and the site address.

== Description ==

A contact form tells a tradesperson very little: a name, a phone number and "I need a quote". Then comes a phone call to ask what the job is, where it is, how big it is, and whether there are photos.

Primiko gives each trade a ready-made quote request: the questions a roofer, a tiler or a plumber would ask, in the right order, with the address of the work and photos taken from the phone. Choose your trade, put the form on a page, and the requests arrive complete.

This is not a form builder, and it never shows a price. It collects and qualifies the request. The quote stays yours.

= What visitors get =

* One question at a time, in short steps with a progress bar, made for phones first.
* Questions that follow the answers: a leak brings questions about the leak, a roof window brings questions about the window.
* Photos straight from the phone camera or library, resized on the phone before sending.
* A review of every answer before sending, and a copy of the request by email.
* Keyboard and screen reader friendly: errors are announced and linked to their field.

= What you get =

* Questions you can fit to your business: hide a step, a question or an answer you do not offer, rename what you say differently, add your own questions, change their order, rewrite the messages sent to visitors. Questions that depend on a hidden answer disappear with it.
* Every request saved in WordPress, under Quote requests, with its answers and photos.
* An email for each new request, to one or more addresses, with the photos attached. Reply to it and your answer goes to the visitor.
* A list of requests with the trade, town, photos and status, New and Handled views, and the number of new requests next to the menu.
* Photos kept in a private folder, outside the media library, without their location data.
* Requests deleted automatically after the period you choose (three years by default), with their photos.
* Plugged into the personal data tools of WordPress: a visitor's requests are part of the export and erase requests made with their email address. A paragraph is suggested for your privacy policy.
* Protection against spam without any outside service: a hidden field, a minimum filling time, a single use token and a limit per connection.
* A block for the block editor, and a shortcode for page builders and classic themes.
* WP-CLI commands to list, export and delete requests: `wp primiko list`, `export`, `delete`, `purge` and `templates`.
* The look of your theme, adjustable with two CSS variables.

= Trades =

Twenty templates, all free, found in a searchable list when you create a form:

* Building trades: roofer, tiler, plumber and heating engineer, electrician, painter and decorator, mason, joiner and window fitter.
* Outdoor: septic systems and earthworks, landscaper and gardener.
* Home services: pest control, cleaning, removals.
* Professional services: accountant, lawyer, notary, architect, property diagnostics, land surveyor.
* Two general templates, for renovation works and for any service on quote, when your trade has no template yet.

More trades are coming.

= About =

Primiko is developed and maintained by [Nolidev](https://nolidev.fr/), a WordPress studio in Vannes, France.

== Installation ==

1. Install and activate the plugin.
2. Open Quote requests, Forms, and add a form. Choose your trade and the email addresses that receive the requests.
3. Add the Quote request form block to a page, or paste the shortcode shown on the form screen.

== External services ==

The plugin works without any outside service. Two optional anti-spam services can be turned on under Quotes, Settings, for sites that already use them. Nothing is loaded or sent until a service is chosen and its keys are saved.

= Cloudflare Turnstile =

When chosen, the Turnstile script is loaded from challenges.cloudflare.com on the pages that show a quote form, and the browser of the visitor exchanges technical data with Cloudflare to tell people from robots. When a request is sent, the site sends the Turnstile answer and your secret key to Cloudflare to check it. [Terms of service](https://www.cloudflare.com/website-terms/), [privacy policy](https://www.cloudflare.com/privacypolicy/).

= Google reCAPTCHA v3 =

When chosen, the reCAPTCHA script is loaded from www.google.com on the pages that show a quote form, and the browser of the visitor exchanges technical data with Google to score the visit. When a request is sent, the site sends the reCAPTCHA answer and your secret key to Google to check it. [Terms of service](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy).

== Frequently Asked Questions ==

= Can I change the questions? =

Yes. On the screen of a form, the Questions and messages box lists every step, question and answer of the trade template. Hide what you do not offer, rename what you say differently, add your own questions, change their order, and rewrite the messages sent to visitors. Only your changes are saved, so improvements to the template in plugin updates still reach your form.

= Does it show a price or an estimate? =

No, and it will not. Prices depend on things only you can see on site. The form gathers what you need to prepare your quote.

= Where are the photos stored? =

In `wp-content/uploads/primiko/`, in one folder per request with random names, protected from web access on Apache and LiteSpeed servers. They are not in the media library. Deleting a request deletes its photos.

= How long are requests kept? =

Three years by default, the period the French data protection authority accepts for prospects. Change it under Quotes, Settings, from six months to five years, or keep requests until you delete them. Older requests are deleted every night with their photos.

= Does it work with page caching? =

Yes. The form asks for its security token when it loads, so a cached page still sends requests.

= Can I change the look? =

You should not need to. The form reads your theme: its fonts, main color, buttons, the corners and background of its fields. To change it anyway, the block has a main color and a corner radius, or set `--primiko-accent`, `--primiko-radius` and `--primiko-small` (size of the help texts, 1rem at least by default) on `.primiko` in your theme CSS.

= Which heading level do the step titles use? =

Heading 3, since the form usually sits under the title of its section. In the block settings, choose Heading 2 when no title comes before it, or Heading 4. With the shortcode, add `heading="2"`.

= Can a button or a menu link go straight to the form? =

Yes. Give the block an HTML anchor in its Advanced settings, such as `quote`, and link to `#quote`. With the shortcode, add `anchor="quote"`. The form stops below a fixed site header.

= Can the form use the whole width of its column? =

Yes. In the block settings, set Width to Whole column. With the shortcode, add `width="full"`. By default the form keeps a width that is easy to read.

= Can visitors leave out their email address? =

Yes. Tick Visitor email, Optional, on the form screen. Visitors who leave it empty get no copy of their request, and the phone number stays required.

= Does it send data to an outside service? =

No, unless you turn on one of the optional anti-spam services (see External services). Requests are saved on your site and sent with the email function of WordPress, so an SMTP plugin applies.

= My site is behind Cloudflare or another proxy. =

The limit per connection (five requests an hour) relies on the address the server sees. Behind a proxy, all visitors may share the proxy address. Forwarded headers can be forged, so the plugin does not read them: if your proxy is trusted, return the real address of the visitor with the `primiko_client_ip` filter, or raise the limit with `primiko_hourly_limit`.

= Do I need a captcha? =

Usually not. The form stops most spam on its own: a hidden field robots fill in, a minimum filling time, a single use signed token and a limit per connection. If spam still gets through, turn on Cloudflare Turnstile or Google reCAPTCHA v3 under Quotes, Settings.

== Screenshots ==

1. The visitor form: one question at a time, with a progress bar, in the colours and fonts of the theme.
2. Questions that follow the answers: a leak brings questions about the leak.
3. The review step: every answer, with a link back to each step, before sending.
4. Creating a form: search a trade among twenty templates.
5. Fitting the questions to your business: hide, rename, reorder, add your own.
6. The list of requests, with the trade, town, photos and status.
7. A request with all its answers and a reply button.

== Changelog ==

= 1.0.3 =
* The message shown when no copy is sent can be rewritten too.
* No extra space above the form when the theme already keeps anchors below its header.
* The text shown without JavaScript no longer speaks for the business.

= 1.0.2 =
* No step count any more: the progress bar shows the way, without "Step 1 of 7".
* Step titles use Heading 3 by default.
* HTML anchor and width setting for the block, `anchor` and `width` for the shortcode.
* Emails carry a plain text part.
* The stylesheet no longer holds the page, and the visitor script is minified.
* The copy sent to the visitor no longer links to their own details.
* Clearer message for a photo the browser cannot read, such as a HEIC photo on a computer.

= 1.0.1 =
* A step made only of single choices moves on by itself after a click or a tap.
* Heading level of the step titles in the block, and an optional visitor email.
* Step introductions can be rewritten, and the template introductions no longer speak for the business.
* Answer cards take the background of the theme fields, and the corners follow the theme everywhere.
* Small texts never go below 1rem.
* Phone, email and address are links in the request and the email, and attached photos are named after the reference.
* Fixed: quotes around the names in Reply-To, plural of the photo counts, the warning left at the top of a fixed step, "Floor" in the tiler template.

= 1.0.0 =
* First version: multi-step visitor form, photos, saved requests and emails.
* Questions and messages editor on the form screen.
* Twenty trade and professional templates, with a searchable trade picker.
* Retention period, personal data export and erase, request list with status views.
* Optional Cloudflare Turnstile or Google reCAPTCHA v3.
* WP-CLI commands.
* The form takes the colors, buttons and fields of the theme.
