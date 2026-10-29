=== AgeWallet – Age Verification & Age Estimation for WordPress & WooCommerce ===
Contributors: agewallet, cookedbiscuits
Tags: age verification, age estimation, age gate, woocommerce, content gating
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Age verification and age estimation for WordPress & WooCommerce. Add a content-gating age gate with cache-safe protection modes.

== Description ==

AgeWallet adds **age verification** and **age estimation** to your WordPress website, with content gating and an age-gated checkout for WooCommerce. Verification is backed by the AgeWallet™ service over OpenID Connect (OIDC), giving your WordPress or WooCommerce store a compliant, privacy-respecting age gate that visitors complete once.

Whether you need document-based age verification, AI-assisted age estimation, or a full content-gating age gate, AgeWallet is straightforward to set up, fully customizable, and works on every WordPress host — including sites behind aggressive page caches (Cloudflare, Varnish, WP Rocket, LiteSpeed).

= Age verification and age estimation methods =

Multiple age-assurance methods are supported, so you can meet age-restriction requirements without collecting or storing sensitive identity data yourself. Every verification can carry an opaque metadata payload (≤4KB) that round-trips through the OIDC flow and surfaces on `/userinfo`, letting integrators correlate age checks with their own records.

= Two protection modes =

**Standard (Overlay)** hides gated content via a client-side overlay — SEO-friendly, works on any cached page. **High Security (Strict)** short-circuits the page entirely until the visitor passes age verification, using a cookieless "skeleton" template that pairs with "Cache Everything" CDN rules.

= Built for WooCommerce =

WooCommerce is supported out of the box: three Checkout gating modes plus per-product / per-category regulated flagging, so age-restricted items can't be bought until the shopper verifies their age, while the rest of your catalog stays open.

= Flexible age-gate rules =

Rules cover the whole site, individual URL paths, taxonomies (categories, tags, custom taxonomies), per-post overrides via the editor sidebar, and the `[agewallet_protected]` shortcode for inline content — so you gate exactly where your content needs it.

= Fully customizable gate =

Customize the age gate from the Gate Appearance tab (logo, copy, colours, button radii) and override anything else from Appearance → Customize → Additional CSS. Strict-mode analytics (Google Analytics 4, Google Tag Manager, Facebook Pixel) are configurable from the Strict Mode Analytics section of the Strict Mode Settings page.

For the full feature list see Other Notes. Setup walkthroughs, the CSS hook reference, and the developer hook list ship with the plugin — see AgeWallet → Usage Guide, CSS Guide, and Developer Hooks in your WordPress admin.

== External services ==

This plugin connects to one required third-party service and, optionally, to analytics services you choose to enable. Nothing is contacted until a visitor begins age verification (the AgeWallet service) or until you enter an analytics ID and use High Security (Strict) mode (the analytics services).

= AgeWallet age-verification service (required) =

The plugin's core purpose is to verify a visitor's age through the AgeWallet™ service using the OpenID Connect (OIDC) Authorization Code flow with PKCE. When a visitor starts verification, their browser is redirected to the AgeWallet authorization endpoint; the plugin's server then exchanges the returned authorization code for the verification outcome and requests the result from the userinfo endpoint.

* Endpoints contacted: `https://app.agewallet.io/user/authorize`, `https://app.agewallet.io/user/token`, and `https://app.agewallet.io/user/userinfo`.
* Data sent: your site's AgeWallet Client ID, a one-time authorization code, OIDC PKCE / state / nonce values, and an optional opaque metadata string that you configure (the plugin attaches no personal data of its own). The service returns only the pass / fail age-verification result.
* When: each time a visitor begins age verification on your site.
* This service is provided by AgeWallet LLC, which publishes separate Terms and Privacy policies for the two parties involved in a verification:
    * Your visitors — the end users being verified: End-User Privacy Policy (https://agewallet.com/end-user-privacy-policy/) and End-User Terms & Conditions (https://agewallet.com/end-user-terms-conditions/).
    * You — the site owner, an AgeWallet "Client": Client Privacy Policy (https://agewallet.com/privacy-policy/) and Client Terms of Service (https://agewallet.com/terms-conditions/).

= Google Analytics 4 and Google Tag Manager (optional) =

Only if you enter a GA4 Measurement ID or a Google Tag Manager Container ID in the Strict Mode Analytics section of the Strict Mode Settings page, and only while High Security (Strict) mode is active, the plugin outputs Google's official analytics snippet into the strict-mode loading screen (where your theme's own tags cannot run). The plugin sends no data itself; the snippet is Google's standard tag, executed in the visitor's browser using the ID you supply.

* Loaded from: `https://www.googletagmanager.com`.
* This service is provided by Google. Terms of Service: https://policies.google.com/terms — Privacy Policy: https://policies.google.com/privacy

= Facebook (Meta) Pixel (optional) =

Only if you enter a Facebook Pixel ID in the Strict Mode Analytics section of the Strict Mode Settings page, and only while High Security (Strict) mode is active, the plugin outputs Meta's official Pixel snippet into the strict-mode loading screen using the ID you supply. The plugin sends no data itself.

* Loaded from: `https://connect.facebook.net` (with a `https://www.facebook.com` no-script fallback image).
* This service is provided by Meta Platforms, Inc. Terms of Service: https://www.facebook.com/legal/terms — Privacy Policy: https://www.facebook.com/privacy/policy/

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/agewallet-oidc-client` directory, or install the plugin through the WordPress plugins screen directly by uploading the `.zip` file.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Crucial Step: Go to Settings > Permalinks and simply click "Save Changes". This ensures the custom AgeWallet verification URLs (e.g., `/agewallet/callback/`) are registered.
4. Look for the AgeWallet item in your main WordPress admin menu to launch the setup wizard.
5. After configuring your potection settings be sure to clear any server, plugin or CloudFlare caches.

== Frequently Asked Questions ==

= Do I need an AgeWallet account to use this plugin? =

Yes. The plugin is a client for the AgeWallet OIDC age-verification service. Register a business account at https://app.agewallet.io/register to obtain a Client ID and Client Secret, then enter them on the plugin's Credentials screen. New accounts receive $5 of free verification credit to get you started.

= Will the plugin work with my caching plugin or CDN (Cloudflare, Varnish, WP Rocket, etc.)? =

Yes. Standard (Overlay) mode is fully cache-compatible — the page HTML is the same for every visitor and the gate is enforced client-side. High Security (Strict) mode uses a split-cache system that ships a cookieless "Skeleton" page from cache and fetches the real content via an authenticated API call once the visitor is verified, so it works with "Cache Everything" rules.

= Does it work with WooCommerce? =

Yes. Mark products as regulated (per product, per category, or per tag), and shoppers must verify their age before they can buy them: the product's add-to-cart area, express payment buttons included, is replaced by a Verify my age button until they do, and WooCommerce refuses to add the product to any cart. Force Always applies this to every product. Payment plugins that place orders through WooCommerce's checkout, and those that build orders themselves, are refused for unverified shoppers.

= How do I customize the look of the gate? =

Upload your logo, customize the headline and body copy via the WYSIWYG editor, and set the colours and corner radius on the Gate Appearance settings page. For anything else, add CSS in Appearance → Customize → Additional CSS; the AgeWallet → CSS Guide page lists the gate's CSS classes.

= Does the plugin store any personal data about my visitors? =

The plugin stores a signed HMAC verification cookie on the visitor's browser indicating that they passed age verification. No personal identifying information is stored on your WordPress site — verification is handled by the AgeWallet service, and only the pass/fail outcome reaches your site. See AgeWallet's End-User Privacy Policy (https://agewallet.com/end-user-privacy-policy/) for full details.

= What happens to my data if I uninstall the plugin? =

Deleting the plugin from the Plugins screen runs `uninstall.php`, which removes all `agewallet_*` options, transients, scheduled cron events, and the strict-mode HTML cache directory. Verification cookies on visitor browsers expire on their own.

== Other Notes ==

= Features =

* Legal compliance: leverages the AgeWallet™ service, designed to comply with modern age-verification laws in major jurisdictions.
* Secure verification flow: OIDC Authorization Code Flow with PKCE.
* Cryptographic cookie integrity: HMAC-signed verification cookies, mathematically verified server-side.
* Two protection modes:
    * Standard (Overlay): a lightweight, SEO-friendly overlay that hides content via CSS and JavaScript.
    * High Security (Strict): prevents protected content from loading until verification is complete. Uses a cookieless skeleton page compatible with "Cache Everything" CDN rules.
* Smart caching architecture: Strict mode uses a split-cache system (singular vs. archives) for fast performance with immediate invalidation when content changes.
* Automated cache management: configurable garbage-collection schedule plus invalidation triggers for menu / theme / taxonomy changes.
* Fully customizable gate appearance: dedicated logo, WYSIWYG copy editor, colour pickers (overlay / card / text / buttons), card and button border-radius, plus Customizer Additional CSS for advanced overrides.
* Strict-mode analytics: structured Google Analytics 4, Google Tag Manager, and Facebook Pixel ID fields render the official snippets directly into the skeleton page (where the theme's own analytics tags can't fire).
* Flexible content protection rules:
    * Full-site protection (with or without the homepage).
    * Granular taxonomy control across Categories, Tags, and Custom Taxonomies (e.g. WooCommerce Product Categories).
    * Path-based protection on specific URL paths (e.g. `/shop/`, `/videos/premium/`).
    * Per-post overrides via the editor sidebar.
    * Inline `[agewallet_protected]` shortcode for specific page elements.
* WooCommerce integration:
    * Three Checkout gating modes: Off, Force Always (verify before buying anything), Conditional on Cart (verify before buying regulated products).
    * Age-restricted products can't be bought until the shopper verifies: their add-to-cart area (express payment buttons included) is replaced by a Verify my age button, and WooCommerce refuses to add them to any cart until then.
    * Per-product regulated controls: Not Regulated / Regulated / Override.
    * Per-category and per-tag regulated flagging on the term-edit screens (products inherit from any of their categories or tags).
    * Cart-context metadata (cart hash, total, currency, line-item count, billing country) attached to verifications that start from the checkout page. In Conditional-on-Cart mode, these verifications also carry a cart_triggers audit trail that captures which products / categories / tags fired the gate.
* Metadata pass-through: attach an opaque per-verification string (up to 4 KB) — static text or auto-composed JSON of selected request-context fields — that round-trips through the OIDC flow and surfaces on the `/userinfo` response.

== Screenshots ==

1. The age gate overlay shown to unverified visitors in Standard (Overlay) mode.
2. The plugin's setup wizard — Step 1 (API Credentials).
3. The Content Guarding screen — taxonomy, path, and global protection rules, plus the WooCommerce "gate the checkout" setting.
4. The per-product Regulated status control on the WooCommerce product edit screen.

== License ==

This plugin is licensed under the GNU General Public License v2.0 or later. A copy of the license is included in `LICENSE.txt` and is available at https://www.gnu.org/licenses/gpl-2.0.html.

== Changelog ==

= 1.5.8 =
* Security: WooCommerce age-restricted products can no longer be bought before age verification, by any route. Until the shopper verifies, a regulated product can't be added to the cart (WooCommerce's own purchasability rule, including the cart API and payment plugins' express buttons), and its add-to-cart area, express payment buttons included, is replaced by a Verify my age button. Checkout validation and a backstop for payment plugins that build orders themselves also refuse unverified regulated orders.
* Change: in Force Always mode, shoppers now verify before they can buy any product, not only at checkout.
* Change: WooCommerce pages (shop, products, product categories and tags, cart, checkout, account, and pages with WooCommerce product blocks or shortcodes) always use Standard mode, so shoppers' carts and WooCommerce messages work as normal.
* Change: When age-restricted items are removed from a shopper's cart because the shopper hasn't verified, the cart page (or the checkout page) now lists them, each with a link to its product page, where the shopper can verify and add it again.
* Fix: The "Age verification required before you can place this order." message no longer appears on pages other than checkout, such as My Account.
* Note: clear your page cache once after updating, so cached product pages pick up the new add-to-cart area and cached gated pages get the age gate.
* Security: The Strict-mode content endpoint now only fetches this site's own pages, so the internal loopback request cannot be pointed at another host.
* Security: Removed an obsolete verification-callback code path in the OIDC handler.
* Security: In Standard mode, every visitor now gets the same gated page, including `[agewallet_protected]` blocks, and the browser reveals the content once the visitor is verified. A page cache can no longer store a copy made for a verified visitor and serve it to everyone without the age gate.
* Fix: A visitor whose verification the site no longer accepts (more than 24 hours old, or made before the site's signing key changed) is now asked to verify again. In Strict mode the age gate shows instead of a "Verifying your age..." screen that never finished; on WooCommerce pages the site clears the refused cookie, so the Verify my age button shows again.

= 1.5.7 =
* Docs: Documentation improvements.

= 1.5.6 =
* Security: Improved script and style handling on the age-gate loading screen and post-verification handoff page.
* Security: Hardened content escaping in the `[agewallet_protected]` shortcode.
* UX: The AgeWallet admin screens now show a prominent "Register at agewallet.io" prompt when the Client ID or Client Secret is missing. The prompt hides automatically once both are saved.
* Docs: Updated the Usage Guide and FAQ with the new https://app.agewallet.io/register signup URL.

= 1.5.5 =
* Docs: Added an "External services" section documenting the AgeWallet verification service and optional Strict-mode analytics integrations (Google Analytics 4, Google Tag Manager, Facebook Pixel), each with the provider's Terms and Privacy links.
* Security: Improved script handling and output escaping across the admin and gate paths.
* Fix: The `[agewallet_protected]` shortcode no longer triggers the full-page overlay on otherwise-ungated pages — it renders its inline placeholder only.
* Fix: Gate Appearance colours and border-radii now apply consistently in both Standard and Strict modes; a blank radius field uses the rounded default instead of squared corners.
* Docs: Removed three readme references to action hooks the plugin does not actually fire.

= 1.5.4 =
* Structured Gate Appearance form: dedicated colour pickers (overlay backdrop, card background and border, body and disclaimer text, Agree / Disagree buttons) and card / button border-radius inputs replace the previous free-text "Custom CSS" field. Defaults match the previous look; saving without changes produces no visual diff.
* Strict Mode Analytics: dedicated Google Analytics 4 Measurement ID, Google Tag Manager Container ID, and Facebook Pixel ID fields replace the previous free-text Header / Footer Scripts inputs. AgeWallet now renders the canonical official snippets for each provider when the corresponding ID is set.
* Customizer-driven custom CSS: arbitrary CSS overrides should now live in **Appearance → Customize → Additional CSS**. AgeWallet automatically renders the Customizer's saved CSS into the Strict-mode skeleton (it already applies on Standard mode via the normal WordPress lifecycle). For exotic per-site needs (custom fonts, non-standard analytics providers), use the `agewallet_skeleton_head` / `agewallet_skeleton_footer` action hooks listed in the Developer Hooks guide.
* Text domain renamed to `agewallet-oidc-client`. Plugin folder, main file, and Text Domain header aligned.
* Security: Admin styles moved into an enqueued stylesheet; tightened debug-log input sanitisation and shortcode escape annotations.
* The previous `agewallet_custom_css`, `agewallet_head_scripts`, and `agewallet_footer_scripts` options are removed; their stored values (if any) are cleared by `uninstall.php` on plugin deletion.

= 1.5.3 =
* Security: Comprehensive input-sanitisation, output-escaping, and internationalisation cleanup across the plugin.
* Compatibility: Minimum WordPress version raised from 5.8 to 6.0.

= 1.5.2 =
* Compatibility: Added `WC requires at least` / `WC tested up to` plugin headers and declared WooCommerce High-Performance Order Storage (HPOS) compatibility.

= 1.5.1 =
* Maintenance: Housekeeping and licence declaration. Added `uninstall.php` for clean removal of options, scheduled cron events, and the strict-mode cache directory.

= 1.4.0 =
* Feature: Metadata pass-through. An opaque per-verification string (up to 4096 bytes) can now be attached to every AgeWallet verification. The Credentials page exposes a "Metadata source" picker with three modes: Off, Static text (literal string), and Auto JSON (compose from selected fields across post, user, request, and archive context groups). Metadata is computed at gate-render time and HMAC-signed for transport via the launch URL. Two filter hooks let developers extend: `agewallet_auto_metadata` (array, before encoding) and `agewallet_metadata` (final string, after encoding). Read back with `agewallet_get_metadata()`.
* Feature: WooCommerce checkout gating. New three-state Checkout Gating control on the Content Guarding page (visible only when WooCommerce is active): Off (no checkout-specific rule), Force Always (every checkout gates), or Conditional on Cart (gate fires only when the cart contains items flagged as regulated). Regulated status is set per-product via a new "AgeWallet" tab in the product data metabox (Not Regulated / Regulated / Override) or per-category and per-tag via a Regulated checkbox on the term edit screens. Per-checkout cart context (cart hash, total, currency, line item count, billing country) is composed into the verification metadata and merged with any site-level metadata. When Conditional on Cart mode fires the gate, a cart_triggers audit field captures the product, category, and tag IDs that triggered. Filters: `agewallet_wc_checkout_metadata`, `agewallet_cart_has_regulated_items`, `agewallet_regulated_cart_triggers`.
* Safety: Strict-mode cache automatically skips WooCommerce checkout, cart, and my-account pages. Caching cart-bearing pages would either render an empty cart in the cookieless loopback or leak one customer's HTML to another, so these pages always render live.
* Dev: New helper `agewallet_get_metadata()` and `AgeWallet_Helpers::get_verified_cookie_payload()`. Cart-inspection methods exposed on `AgeWallet_WooCommerce`: `checkout_gating_mode`, `cart_contains_regulated_items`, `get_regulated_triggers`, `product_is_regulated`. Programmatic flagging via post meta (`_agewallet_regulated_status` on products) and term meta (`agewallet_regulated` on `product_cat` and `product_tag`).

= 1.3.1 =
* Fix: On verification failure, redirect user back to originating page so the age gate re-triggers, rather than showing a misleading "cancelled or denied" message.

= 1.3.0 =
* Architecture: Implemented High Security (Strict) Mode with API-based content retrieval.
* Architecture: Added Split-Cache system (Singular/Archive) for intelligent invalidation.
* Compatibility: Switched verification triggers to POST requests to bypass aggressive host caching (WP Engine, etc).
* UX: Refactored Admin Settings into a multi-step Wizard.
* Feature: Added Manual Cache Purge tool.
* Security: Implemented HMAC cryptographic signing for verification cookies to prevent forgery.
* Feature: Added granular Taxonomy Gating (support for Categories, Tags, and Custom Taxonomies).
* Feature: Added "Cache Control" settings with configurable auto-purge schedule (WP-Cron).
* Feature: Extended automatic cache invalidation to include Menu, Theme, and Term updates.
* Dev: Added multiple new hooks for deep customization of cookies, caching, and taxonomy rules.

= 1.2.0 =
* Patched 2 bugs preventing shortcode from working properly in some environments.

= 1.0.1 =
* CSS and Documentation tweaks.

= 1.0.0 =
* Feature: Added an in-dashboard "Plugin Guide" page that displays the plugin's readme file.

= 0.1.7 =
* Tweak: Standardized all front-end CSS class names for better consistency and to prevent theme conflicts.
* Tweak: Converted the "I Agree" link into a &lt;button&gt; element for better styling compatibility across themes.
* Feature: Added numerous developer hooks and filters for improved extensibility.
* Security: Hardened client-side script by adding click handlers dynamically instead of using inline attributes.

== Upgrade Notice ==

= 1.5.8 =
Security release: age-restricted WooCommerce products can no longer be bought before age verification, including through block checkout and express payment buttons. Page caches no longer serve gated pages without the age gate. Clear your page cache once after updating. Recommended for all users.

= 1.5.7 =
Documentation improvements.

= 1.5.6 =
Improved security around script and style handling and shortcode content escaping. No functional changes for site owners.

= 1.5.5 =
Documentation and code-quality release (External services disclosure, script cleanup, small fixes). No functional changes.

= 1.4.0 =
Adds metadata pass-through and a full WooCommerce integration with three-mode checkout gating (Off / Force Always / Conditional on Cart), per-product and per-category/tag regulated flagging, and per-checkout cart context attached as metadata.

= 1.3.1 =
On verification failure, users are now redirected back to the originating page instead of seeing a generic error.