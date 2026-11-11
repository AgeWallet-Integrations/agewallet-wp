This plugin includes a number of action and filter hooks to allow for advanced customization and integration.

= Main Plugin File =
* `agewallet_dependency_files` (filter) - Modify the array of core class files to be loaded.
* `agewallet_initialized` (action) - Fires after all plugin classes have been instantiated.
* `agewallet_activated` (action) - Fires when the plugin is activated.
* `agewallet_deactivated` (action) - Fires when the plugin is deactivated.

= Admin Settings (class-agewallet-admin.php) =
* `agewallet_admin_menu_capability` (filter) - Change the user capability required to access the settings page.

= Helpers (class-agewallet-helpers.php) =
* `agewallet_hmac_secret` (filter) - Override the HMAC secret retrieved from the database.
* `agewallet_redirect_uri` (filter) - Modify the `/agewallet/callback/` URL.
* `agewallet_success_url` (filter) - Modify the `/agewallet/success/` URL.
* `agewallet_launch_url` (filter) - Modify the `/agewallet/launch/` URL.
* `agewallet_cookie_path` (filter) - Modify the path used for the verification cookie.
* `agewallet_current_url` (filter) - Override the auto-detected current URL.
* `agewallet_cookie_payload` (filter) - Modify the data array stored inside the signed cookie before it is signed.
* `agewallet_cookie_validation_error` (action) - Fires when a cookie fails validation: a bad HMAC signature (error_type `signature_mismatch`) or an expired payload (error_type `expired`) (args: error_type, cookie_value).

= Metadata Builder (class-agewallet-metadata-builder.php) =
* `agewallet_auto_metadata` (filter) - Modify the resolved auto-metadata fields array (post_id, post_slug, request_path, user_id, etc.) before it is JSON-encoded into the metadata string.

= OIDC Handler (class-agewallet-oidc-handler.php) =
* `agewallet_state_transient_expiration` (filter) - Change the expiration time for the OIDC session transient.
* `agewallet_auth_request_params` (filter) - Modify the parameters sent in the authorization request to AgeWallet.
* `agewallet_metadata` (filter) - Modify the metadata string just before it is signed and attached to the verification request. Receives the value produced by the configured metadata source (Off, Static text, or Auto JSON of selected fields).
* `agewallet_oidc_error` (action) - Fires when an error is returned from the AgeWallet callback.
* `agewallet_token_request_args` (filter) - Modify the arguments for the server-to-server token exchange request.
* `agewallet_verification_success` (action) - Fires immediately after a user's age is successfully verified.
* `agewallet_verified_cookie_attributes` (filter) - Modify the attributes (path, domain, expires, etc.) of the verification cookie.
* `agewallet_final_redirect_url` (filter) - Modify the final URL the user is redirected to after successful verification.
* `agewallet_before_render_success_page` (action) - Fires before the success page HTML is rendered, allowing for a complete override.

= Gating Manager (class-agewallet-gating-manager.php) =
* `agewallet_should_gate_request` (filter) - Override the final boolean decision on whether to gate the current page.
* `agewallet_gate_script_data` (filter) - Modify the data array passed to the front-end `gate.js` script.
* `agewallet_gate_template_args` (filter) - Modify the array of data used to build the age gate HTML.
* `agewallet_after_gate_buttons` (action) - Add custom HTML content after the Agree/Disagree buttons on the gate.
* `agewallet_meta_box_save` (action) - Fires when the age restriction setting is saved for a post or page.
* `agewallet_meta_box_post_types` (filter) - Control which post types show the Age Restriction meta box.
* `agewallet_template_include_priority` (filter) - Adjust the priority of the strict mode template interception (default: 99).
* `agewallet_taxonomy_rules` (filter) - Modify the loaded array of taxonomy blocking rules before they are evaluated.

= Strict Mode & Caching (class-agewallet-api.php & gatekeeper.php) =
* `agewallet_skeleton_template` (filter) - Replace the `gatekeeper.php` template file entirely.
* `agewallet_skeleton_head` (action) - Output custom tags in the &lt;head&gt; of the skeleton screen.
* `agewallet_skeleton_content_after` (action) - Output content below the spinner on the loading screen.
* `agewallet_skeleton_body_classes` (filter) - Add custom classes to the skeleton &lt;body&gt;.
* `agewallet_skeleton_footer` (action) - Output custom tags before the closing &lt;/body&gt; tag.
* `agewallet_cache_directory` (filter) - Change the physical path where HTML caches are stored.
* `agewallet_loopback_url` (filter) - Modify the URL used by the cache builder (useful for specific proxy setups). Runs inside the `agewallet/v1/content` REST route, described under `agewallet_api_content_response`.
* `agewallet_loopback_request_args` (filter) - Modify HTTP args (timeout, headers) for the cache build request.
* `agewallet_api_content_response` (filter) - Modify the HTML content string before it is returned by the API to the frontend. The API is the `agewallet/v1/content` REST route (GET and POST), which the Strict-mode skeleton's gate script calls with the page's address and post id; it answers 403 `unverified`, 400 `missing_url` or `invalid_url` (an address not on the site's own host), and 500 `build_failed` when the loopback fetch fails.
* `agewallet_is_user_verified` (filter) - Strict mode only. Runs when a visitor who has an `agewallet_verified` cookie asks for a page's protected content. Return true to send the content without checking the cookie's signature and expiry. It isn't run for visitors with no cookie, and Standard mode and WooCommerce don't use it. No arguments; default false.
* `agewallet_cache_invalidation_events` (filter) - Modify the list of WP actions that trigger a global cache purge.
* `agewallet_before_cache_purge` (action) - Fires immediately before cache files are deleted (args: type, count/id).
* `agewallet_after_cache_purge` (action) - Fires immediately after cache files are deleted.

= WooCommerce (class-agewallet-woocommerce.php & class-agewallet-product-flags.php) =
* `agewallet_wc_checkout_metadata` (filter) - Modify the per-checkout JSON metadata blob (cart hash, total, currency, line item count, billing country, etc.) before it is attached to the verification.
* `agewallet_cart_has_regulated_items` (filter) - Override the boolean decision on whether the current WC cart contains regulated items, for the cart and checkout pages' message and the order refusals under "Only the products you flag". It does not affect the order-save backstop, which reads each order line's product (see `AgeWallet_WooCommerce::product_requires_verification()`), except the first save of an empty order, which asks the cart. Useful for custom rules such as "regulated if cart total exceeds X" or "regulated if shipping to a specific country".
* `agewallet_visitor_is_verified` (filter, applied in `class-agewallet-helpers.php`) - Decide on the server whether the current visitor counts as age-verified: receives whether the request carries a valid (signed, unexpired) verification cookie, returns the decision. Used by the cart and checkout pages' message, the order refusals, the verification record and the Strict mode content route; not by the browser-side reveal of gated pages and masked listing cards. Useful for accepting a site's own proof of age (for example, a membership system).
* `agewallet_wc_verified_order` (action) - Fires when an order placed by a verified shopper has been given its verification record (args: `WC_Order $order`, `string $verified_at` in UTC `Y-m-d H:i:s`). Fires after the classic checkout creates the order, after the Store API places it (the Checkout block and express buttons), and after a payment plugin that builds orders itself first saves one in a verified shopper's request.
* `agewallet_regulated_cart_triggers` (filter) - Modify the array of product IDs, category term IDs, and tag term IDs that triggered the regulated-cart check. Used both for the gating decision and for the cart_triggers audit field stored in metadata.

= WooCommerce: order refusals =

An order placed by a shopper who hasn't verified, when the cart or the order holds a product that needs verification (`AgeWallet_WooCommerce::product_requires_verification()`), is refused on the server in three places. The shopper's text is `AgeWallet_WooCommerce::refusal_message()`.

* Classic checkout: a checkout error with the code `agewallet_age_verification_required`, added on `woocommerce_after_checkout_validation`, so no order is created.
* Store API: the same text as a cart error on `POST /wc/store/v1/checkout` (also `/wc/store/checkout`, in any letter case), which WooCommerce returns as a 409 before the order is placed. Cart reads and the Checkout block's updates are not touched.
* Backstop for payment plugins that build orders themselves: a new order, or a new product line, saved in an unverified shopper's request ends the request on `woocommerce_before_order_object_save` or `woocommerce_before_order_item_object_save`: a JSON 403 whose `code` is `agewallet_age_verification_required` for AJAX, JSON and REST requests, a 403 page otherwise. The Checkout block's draft order is exempt.

The backstop never runs for wp-admin screens, cron, WP-CLI, Action Scheduler jobs or users who can edit shop orders; the classic checkout and Store API refusals look only at the cart and the visitor's verification.

= Public Helper Functions =
* `agewallet_get_metadata()` - Returns the metadata value attached to the currently verified user's session as a string, or null if not available. Reads from the signed verification cookie payload.

= Public PHP Methods =
* `AgeWallet_WooCommerce::checkout_gating_mode()` - Returns the configured WC Checkout gating mode as a string: `'off'`, `'force-always'`, or `'conditional-on-cart'`.
* `AgeWallet_WooCommerce::checkout_gating_enabled()` - Returns true when Checkout gating is on in any mode (force-always or conditional-on-cart).
* `AgeWallet_WooCommerce::cart_notice_enabled()` - Returns true when the cart page shows the age verification message (the Cart message setting, on until saved off).
* `AgeWallet_WooCommerce::checkout_notice_position()` - Returns where the checkout shows the message: `'above'`, `'below'` or `'both'` (the setting Where the checkout shows the message; above for any other stored value).
* `AgeWallet_WooCommerce::product_requires_verification( $product )` - Returns true when buying the product needs age verification: Checkout gating is "Only the products you flag" and the product is regulated, or "All products except those you exempt" and the product is not set to Never require verification.
* `AgeWallet_WooCommerce::product_regulated_status( $product )` - Returns the product's own regulated status, `'not_regulated'`, `'regulated'` or `'override_not_regulated'` (a product id or a `WC_Product`; variations read their parent's).
* `AgeWallet_WooCommerce::cart_requires_verification()` - Returns true when the current cart must be age-verified before it can be ordered and the visitor is not yet verified: Checkout gating is on and the cart holds a product that needs verification under the rule.
* `AgeWallet_WooCommerce::refuses( $product )` - Returns true when this visitor can't have this product in an order yet: the product needs verification, the request is a shopper's (not wp-admin, cron, WP-CLI, a scheduled action or a user who can edit shop orders), and the visitor hasn't verified.
* `AgeWallet_WooCommerce::refusal_message()` - Returns the text shoppers see when an order is refused.
* `AgeWallet_WooCommerce::record_verification( $order )` - Writes the verification record on an order when the current request's visitor is verified, once, and fires `agewallet_wc_verified_order`.
* `AgeWallet_WooCommerce::visitor_is_verified()` - Returns true when the current request's visitor counts as verified: a valid (signed, unexpired) verification cookie, passed through the `agewallet_visitor_is_verified` filter.
* `AgeWallet_WooCommerce::cart_contains_regulated_items()` - Returns true when the current WC cart contains at least one item flagged as regulated.
* `AgeWallet_WooCommerce::get_regulated_triggers()` - Returns an associative array of product IDs, category term IDs, and tag term IDs that triggered the regulated-cart check.
* `AgeWallet_WooCommerce::default_metadata_fields()` - Returns the default set of cart-context fields for the checkout metadata, `cart_hash`, `cart_total` and `currency` (the Checkout metadata fields setting's default).
* `AgeWallet_WooCommerce::allowed_metadata_fields()` - Returns the six field keys the Checkout metadata fields setting accepts: `cart_hash`, `cart_total`, `currency`, `customer_id`, `billing_country` and `line_item_count`.
* `AgeWallet_WooCommerce::instance()->build_checkout_metadata_array()` - Returns the selected cart-context fields resolved from the current cart as an associative array: `cart_hash`, `cart_total` (WooCommerce's unformatted total), `currency`, `customer_id` (the current user id), `billing_country` (from the customer, when there is one) and `line_item_count`. Empty when there is no cart. The cart_triggers field and the `agewallet_wc_checkout_metadata` filter are added by the metadata filter callback, not here.
* `AgeWallet_WooCommerce::product_is_regulated( $product )` - Returns true when the given WC_Product (or its parent for variations) is flagged as regulated, either directly via the per-product status or inherited from its categories or tags.
* `AgeWallet_WooCommerce::is_checkout_request()` - Returns true when the current request is the WooCommerce-configured checkout page.
* `AgeWallet_WooCommerce::is_dynamic_wc_page()` - Returns true for WooCommerce pages, which always use Standard mode even when Strict mode is on (shop, products, product categories and tags, cart, checkout, account, and pages with WooCommerce product blocks or shortcodes).

= Post & Term Meta Keys =
* `_agewallet_regulated_status` (post meta on `product`) - Per-product regulated status. Accepts `'not_regulated'` (default), `'regulated'`, or `'override_not_regulated'` (forces unregulated even when the product's category or tag is flagged). The product's Age verification choice writes it together with `_agewallet_force_restrict` and `_agewallet_force_exclude`: Follow store rules = `not_regulated` and neither flag; Require verification to buy only = `regulated` and neither flag; Require verification to view and buy = `regulated` and `_agewallet_force_restrict`; Never require verification = `override_not_regulated` and `_agewallet_force_exclude`. Any other combination shows as Custom on the product screen.
* `agewallet_regulated` (term meta on `product_cat` and `product_tag`) - When set to `'1'`, every product in this category or tag is treated as regulated: under "Only the products you flag", shoppers must verify their age before they can buy it.
* `_agewallet_verified_at` (order meta) - The verification record: the UTC time (`Y-m-d H:i:s`) an order was placed by a verified shopper, shown on the order's edit screen under the order details. Absent on orders placed without verifying, and on orders created in the admin.
* `_agewallet_no_record_notified` (order meta) - Set to `'1'` once a paid order without a verification record has been given its order note, log line and email, so it is reported once.
* `_agewallet_created_by_scheduled_task` (order meta) - Set to `'1'` when the order was created by WP-Cron or inside an Action Scheduler job (a subscription renewal, for example). The check of paid orders without a verification record skips these orders and orders created in the admin; orders created before 1.6.1 carry no mark.
* `_agewallet_force_restrict` (post meta) - When set to `'1'`, forces the age gate onto this single post, page or product regardless of global rules. Controls viewing only; it doesn't make a product regulated for buying. On a product it is set by the Require verification to view and buy choice.
* `_agewallet_force_exclude` (post meta) - When set to `'1'`, forces the age gate to be skipped on this single post regardless of global rules. Takes priority over `_agewallet_force_restrict`.
