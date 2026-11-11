This guide explains how to configure and use the plugin using the new setup wizard.

= 1. Initial Configuration (Step 1) =

Before the plugin can work, you must connect it to your AgeWallet account. If you don't have an account yet, register one at https://app.agewallet.io/register — new accounts receive $5 of free verification credit.

1. Click AgeWallet in your WordPress admin menu to see the Welcome screen.
2. Click on Step 1: API Credentials.
3. In the "API Configuration" section, you will see a field labeled Redirect URI. Copy this URL.
4. Log in to your AgeWallet business account dashboard at https://app.agewallet.io.
5. Create a new application and paste the Redirect URI from the plugin settings into the corresponding field in your AgeWallet dashboard.
6. AgeWallet will provide you with a Client ID and a Client Secret.
7. Copy these keys and paste them into the "Client ID" and "Client Secret" fields in the plugin settings.
8. Click "Save Changes", then click "Next: Content Guarding".

= 2. Setting up Protection Rules (Step 2) =

You have several ways to protect content. You can mix and match these methods on the Step 2: Content Guarding page.

= A. Security Mode =

* Standard (Overlay): The page loads normally, but a CSS overlay covers the content. Verified users see the content revealed instantly. Best for SEO.
* High Security (Strict): The server sends a generic "Skeleton" page instead of your content. Secure content is fetched via an API call only after the user is verified. This prevents bypassing the gate by disabling JavaScript.
* WooCommerce pages (shop, products, product categories and tags, cart, checkout, account, and pages with WooCommerce product blocks or shortcodes) always use Standard mode, even when High Security is on, so shoppers' carts and WooCommerce messages work as normal.

= B. Global Scope =

* No automatic protection: Only protects content you specify manually (via Paths, Per-Post settings, or Shortcodes).
* Protect entire site, except homepage: Gates every page and post except your front page.
* Protect entire site, including homepage: Gates every single page on your site.

= C. Taxonomy Rules =

You can define rules based on Categories, Tags, or Custom Taxonomies (like WooCommerce Product Categories).
* Gate All Terms: Gates every post belonging to that taxonomy.
* Exclude All Terms: Ensures posts in that taxonomy are always public.
* Specific Rules: Search for specific terms (e.g., "Premium Content" category) to Gate or Exclude individually. Note: Exclusion rules take priority over Gating rules.

= D. Path-Based Rules =

* Paths to Protect: (Only active if "Protect only specific URL paths" is selected above). Enter comma-separated paths. Any URL containing these paths will be gated. Example: `/shop/, /videos/` will protect `example.com/shop/`, `example.com/shop/product-1/`, and `example.com/videos/my-video/`.
* Paths to Exclude: Enter paths that should always be public, even if 'Protect entire site' is on. Example: `/privacy-policy/, /terms/`.

= E. WooCommerce (visible when WooCommerce is active) =

The Content Guarding page shows a "WooCommerce" section below the Taxonomy Rules. The plugin also adds an "AgeWallet" tab to the Product Data metabox and a checkbox to the term-edit screens for Categories and Tags.

Two cases, set per product: some products can't be viewed or bought until the shopper verifies; others can be viewed, but not bought until the shopper verifies.

Per-product Age verification: open any product in WooCommerce > Products > Edit Product > AgeWallet tab. One choice sets who can view the product's page and who can buy it:

* Follow store rules (default): viewing follows your Content Guarding rules; buying follows your Checkout gating rule, including this product's categories and tags.
* Require verification to view and buy: the product page is gated, and shoppers must verify their age before buying.
* Require verification to buy only: the page stays open; shoppers must verify their age before buying.
* Never require verification: the page is open and shoppers can buy without verifying, whatever your rules say.

Under the choice, a hint says what the product does now: whether its page is gated by your Content Guarding rules, and whether shoppers verify before buying it. A product whose saved settings from an earlier version match none of the four choices shows a fifth choice, Custom, with the saved settings named; it keeps behaving as before until you pick a choice and update the product. Products don't have the Age Restriction sidebar box (section 5): the choice replaces it.

Per-category and per-tag flag: on the term-edit screens (Products > Categories or Products > Tags), tick "Require age verification to buy (AgeWallet)". Shoppers must verify before buying any product with that category or tag, when Checkout gating is set to Only the products you flag. A product's own choice overrides it. Clear your page cache after changing it.

Checkout gating chooses which products need verification to buy:

* None: no product needs verification to buy, whatever its choice says. Your Content Guarding rules still decide who can view each page.
* Only the products you flag: products set to Require verification (to buy only, or to view and buy), and products in a flagged category or tag. The cart and checkout ask the shopper to verify when the cart holds one.
* All products except those you exempt: every product, except products set to Never require verification. Pick this when most of your catalog is age-restricted and carve out the few products that aren't.

Two settings under Checkout gating choose where shoppers see the message:

* Cart message, on by default. Untick "Show the age verification message on the cart page" and the cart page shows no message and no Verify my age button; shoppers verify at the checkout instead. Express checkout buttons stay hidden on the cart until the shopper verifies.
* Where the checkout shows the message: Above the checkout form (the default), Below the checkout form, where Place order appears after verifying, or Both. The choice applies to the Checkout block and the classic checkout alike.

Neither setting changes what the server refuses: an order placed without verifying is refused whatever they say.

The shopper's journey, when unverified:

* A product set to view and buy: its page shows the age gate, as any gated page does, and its card in listings (shop, category, tag and search pages, related products, product grid blocks, the Product Collection block) shows "Verify your age to view this product." with a Verify my age button that brings them back to the listing. Product images drawn outside WooCommerce's own cards, such as a theme's slider or a page-builder widget, are not covered.
* A product set to buy only, or in a flagged category or tag: its page and its listing cards are as your theme draws them, and Add to cart works everywhere.
* The cart page shows "Your order contains items that require age verification." with a Verify my age button above Proceed to checkout, unless the Cart message setting is off. The checkout page shows the message above the checkout form, below it where Place order appears after verifying, or in both places, as the setting Where the checkout shows the message says. Express checkout buttons (PayPal, Apple Pay, Google Pay and the like) are hidden on both pages, and in the classic mini-cart, until the shopper verifies, whatever the two settings say.
* Verify my age takes the shopper to AgeWallet and back to the same page with the cart intact. Place order and the express buttons then appear, and they pay.
* An order placed without verifying is refused by the server before it exists, whether it comes through the classic checkout form, the Checkout block, an express button or a payment plugin that builds orders itself. The shopper sees "Age verification is required before you can place this order. Please verify your age at checkout."

A verified shopper sees nothing of AgeWallet on these pages. Their order carries a verification record: the order's edit screen shows "Age verification: Verified with AgeWallet on …" with the date and time under the order details, and the time is stored in the order's meta `_agewallet_verified_at`. A paid order that holds a product needing verification but has no record gets an order note ("This order has no age verification record..."), a line in WooCommerce > Status > Logs (source agewallet) and one email to the site's admin address, so you can check the customer's age before shipping. Orders created in the admin are not checked.

**Express checkout.** AgeWallet hides the express checkout buttons that payment plugins place in WooCommerce's cart page checkout area, the classic mini-cart, the classic checkout (above the customer details and in the payment area) and the Cart and Checkout blocks' express payment area until the shopper has verified, and it refuses any order an unverified shopper places through WooCommerce's checkout, the classic form or the Store API. Tested with WooPayments 11.2.0, WooCommerce Stripe 11.1.1 and WooCommerce PayPal Payments 4.2.0. Express buttons on product pages are not hidden: turn that location off in your payment plugin's settings. Anything else is not supported.

Product and listing pages are the same for every visitor, so page caching keeps working; the browser reveals masked cards and the gated page once the shopper is verified. The cart, checkout and the Store API are never page-cached. Clear your page cache once after updating the plugin.

Checkout Metadata Fields: pick which cart-context values get attached to each verification that starts from the cart or checkout page, as metadata that round-trips through the OIDC flow back to your backend. Available fields: cart hash, cart total, currency code, WordPress user ID, billing country, number of line items. Defaults: cart hash, cart total, currency. Under Only the products you flag, the products, categories and tags that need verification are added as cart_triggers. Use the `agewallet_wc_checkout_metadata` filter (see Developer Hooks) for fully custom payloads.

= 3. Customizing the Gate Appearance (Step 3) =

You can customize the age gate to match your brand on the Step 3: Gate Appearance page.

* Gate Logo: Upload or select a logo from your Media Library.
* Logo Width (px): Set a specific width for your logo, or leave at 0 for natural size.
* Gate Copy: Use the text editor to change the main message your users see.
* Hide Default Heading: Check this box to remove the "You Must Verify Your Age" title (e.g., if your custom copy already includes a title).
* Colours: Pick the overlay backdrop, card background and border, body and disclaimer text colours, and the Agree / Disagree button colours from the dedicated colour pickers.
* Card / button border radius: Set how rounded the card and buttons appear.
* For anything beyond these structured controls (custom fonts, site-wide rules, advanced selectors, hover variants not in the form), use **Appearance → Customize → Additional CSS**. AgeWallet applies that CSS to the gate on both Standard and Strict modes — see the CSS Customization Guide below for the available class names.

= 4. Strict Mode Analytics (Step 4) =

These settings are in the Strict Mode Analytics section of the Step 4: Strict Mode Settings page.

Note: These settings only apply if you are using High Security (Strict) Mode.

Because Strict Mode short-circuits the theme during verification, the analytics tags your theme normally renders do NOT fire on the "Verifying..." loading screen. Enter the IDs of the services you use and AgeWallet renders their official snippets directly. Leave any field blank to skip it.

* Google Analytics 4 — Measurement ID (`G-XXXXXXXXXX`)
* Google Tag Manager — Container ID (`GTM-XXXXXXX`)
* Facebook Pixel — numeric Pixel ID

For analytics providers we don't ship a field for (Plausible, Fathom, Microsoft Clarity, Hotjar, etc.), add them via the `agewallet_skeleton_head` / `agewallet_skeleton_footer` action hooks in a small mu-plugin or your theme's `functions.php` — see the Developer Hooks section.

= 5. Per-Post / Per-Page Control =

On the Edit Post or Edit Page screen, you will see an "Age Restriction" box in the sidebar. It controls who can view that page, and it overrides your other page protection rules. (WooCommerce products have the Age verification choice in Product data > AgeWallet instead; see section E.)

* Require age verification: Check this to force the gate on this single page, even if your global setting is "No automatic protection."
* Exclude from age verification: Check this to make the page viewable by anyone, even if it's in a protected path (like `/shop/`) or a protected Category.
* If both are ticked, Exclude wins.

The Age Restriction box works on single posts and pages. It has no effect on your Posts page (Settings → Reading) or the WooCommerce Shop page; to gate or exclude those, add their address to Paths to Protect or Paths to Exclude.

On a WooCommerce product, the box is not shown: the product's Age verification choice (Product data > AgeWallet, section E) sets both who can view the page and who can buy. Require verification to view and buy gates the page like Require does here; Never require verification opens it like Exclude does.

= 6. Shortcode Protection =

To protect just one part of a post (like a single video or paragraph), wrap it in the `[agewallet_protected]` shortcode.

`[agewallet_protected]`
This content, and only this content, will be hidden until the user verifies their age.
`[/agewallet_protected]`

Note: Shortcode protection only works in Standard Mode. In Strict Mode, the shortcode will NOT work.

= 7. Caching & Maintenance =

If you use High Security Mode, the plugin generates static HTML caches of your protected pages to ensure speed.

* Automatic Management: The cache is automatically cleared when you update posts, switch themes, create/edit menus, or modify taxonomy terms.
* Scheduled Cleanup: You can configure an automatic cache purge schedule (default: every 4 hours) in the "Cache Control" settings tab.
* Manual Purge: If you change settings and don't see them update immediately, click the "Purge Cache" button in the sidebar of any AgeWallet settings page, which appears when High Security (Strict) mode is on (or use the purge button under Cache Control).
