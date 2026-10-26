<?php
// Prevent direct script access.
defined( 'ABSPATH' ) || exit;

/**
 * Manages the display and logic for age gating content.
 *
 * Handles shortcode registration ([agewallet_button], [agewallet_protected]),
 * CSS/JS enqueueing, gate HTML generation, automatic gating based on global settings,
 * and per-post meta box settings. Implements the Singleton pattern.
 *
 * @package AgeWalletOIDCClient
 * @since   0.1.0
 */
class AgeWallet_Gating_Manager {

	/**
	 * The single instance of the class.
	 * @since 0.1.0
	 * @var   AgeWallet_Gating_Manager|null
	 */
	private static $instance = null;

	/**
	 * Name of the client-side cookie used to track verification status.
	 * @since 0.1.0
	 */
	const VERIFIED_COOKIE_NAME = 'agewallet_verified';

	/**
	 * Handle for the gate stylesheet.
	 * @since 0.1.0
	 */
	const STYLE_HANDLE = 'agewallet-gate-style';

	/**
	 * Handle for the gate JavaScript.
	 * @since 0.1.0
	 */
	const SCRIPT_HANDLE = 'agewallet-gate-script';

	/**
	 * Meta key to force restriction on a specific post/page.
	 * @since 0.1.0
	 */
	const META_KEY_FORCE_RESTRICT = '_agewallet_force_restrict';

	/**
	 * Meta key to force exclusion from restriction on a specific post/page.
	 * @since 0.1.0
	 */
	const META_KEY_FORCE_EXCLUDE = '_agewallet_force_exclude';

	/**
	 * Flag to track if assets have been enqueued for the current request. Prevents double enqueueing.
	 * @since 0.1.0
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Ensures only one instance of the class is loaded.
	 * @since  0.1.0
	 * @static
	 * @return AgeWallet_Gating_Manager - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Hooks into WordPress actions and filters.
	 * Private to prevent direct object creation.
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->log_debug( '[Gating Manager] __construct started.' );

		// Front-end Hooks
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) ); // Register always
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_gate_assets' ), 20 ); // Conditionally enqueue later
		add_filter( 'body_class', array( $this, 'add_body_classes' ) ); // Add initial hiding class conditionally

		// Strict Mode Interception (New in 1.1.0)
		// Runs just before the template is included. Priority 99 ensures we override theme logic.
		// Filter added to allow priority adjustment (Dev Hook)
		$priority = apply_filters( 'agewallet_template_include_priority', 99 );
		add_filter( 'template_include', array( $this, 'intercept_template_loading' ), $priority );

		// Shortcode Hooks
		add_shortcode( 'agewallet_button', array( $this, 'button_shortcode_handler' ) );
		add_shortcode( 'agewallet_protected', array( $this, 'protected_content_shortcode_handler' ) );

		// Meta Box Hooks (Admin-side)
		add_action( 'add_meta_boxes', array( $this, 'add_restriction_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_restriction_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_post_restriction_script' ) );

		$this->log_debug( '[Gating Manager] __construct finished, hooks added.' );
	}

	/**
	 * Registers front-end CSS and JS assets. Hooked to 'wp_enqueue_scripts'.
	 * @since 0.1.0
	 */
	public function register_assets() {
		// Register Stylesheet
		$style_path = 'assets/css/gate.css';
		$style_ver  = AGEWALLET_VERSION;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$file_path = AGEWALLET_PLUGIN_DIR . $style_path;
			if ( file_exists( $file_path ) ) {
				$style_ver = filemtime( $file_path ) ?: $style_ver;
			}
		}
		wp_register_style( self::STYLE_HANDLE, AGEWALLET_PLUGIN_URL . $style_path, array(), $style_ver, 'all' );

		// Register JavaScript
		$script_path = 'assets/js/gate.js';
		$script_ver  = AGEWALLET_VERSION;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$file_path = AGEWALLET_PLUGIN_DIR . $script_path;
			if ( file_exists( $file_path ) ) {
				$script_ver = filemtime( $file_path ) ?: $script_ver;
			}
		}
		wp_register_script( self::SCRIPT_HANDLE, AGEWALLET_PLUGIN_URL . $script_path, array( 'jquery' ), $script_ver, true );

		// Skeleton-only CSS used by the strict-mode gatekeeper page (spinner, background).
		$skeleton_path = 'assets/css/gate-skeleton.css';
		$skeleton_ver  = AGEWALLET_VERSION;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$file_path = AGEWALLET_PLUGIN_DIR . $skeleton_path;
			if ( file_exists( $file_path ) ) {
				$skeleton_ver = filemtime( $file_path ) ?: $skeleton_ver;
			}
		}
		wp_register_style( 'agewallet-gate-skeleton', AGEWALLET_PLUGIN_URL . $skeleton_path, array( self::STYLE_HANDLE ), $skeleton_ver, 'all' );

		$this->log_debug( '[Gating Manager] Assets registered.' );
	}

	/**
	 * Enqueue + configure every asset needed by the strict-mode gatekeeper skeleton
	 * (templates/gatekeeper.php). Called from the template right before it emits the
	 * `<head>` and body scripts via wp_print_styles() / wp_print_scripts(). All admin-
	 * chosen values (CSS vars, Customizer CSS, analytics IDs, gate config) reach the
	 * browser via wp_add_inline_style / wp_add_inline_script / wp_localize_script so
	 * the template itself contains no raw `<script>` or `<style>` tags.
	 *
	 * @since 1.5.6
	 * @param array $script_data Per-request gate config passed to gate.js as `agewallet_gate_data`.
	 */
	public function enqueue_gatekeeper_assets( array $script_data ) {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) || ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_style( 'agewallet-gate-skeleton' );

		if ( class_exists( 'AgeWallet_Helpers' ) ) {
			$css_vars = AgeWallet_Helpers::instance()->get_gate_css_vars();
			if ( '' !== $css_vars ) {
				wp_add_inline_style( self::STYLE_HANDLE, $css_vars );
			}
		}

		// Customizer's "Additional CSS" — strict mode short-circuits wp_head(), so we
		// attach it explicitly to the gate stylesheet handle.
		$customizer_css = wp_get_custom_css();
		if ( ! empty( $customizer_css ) ) {
			wp_add_inline_style( self::STYLE_HANDLE, wp_strip_all_tags( $customizer_css ) );
		}

		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_localize_script( self::SCRIPT_HANDLE, 'agewallet_gate_data', $script_data );

		if ( class_exists( 'AgeWallet_Helpers' ) ) {
			$ids = AgeWallet_Helpers::instance()->get_analytics_ids();
			$this->register_gatekeeper_analytics( $ids );
		}
	}

	/**
	 * Register GA4 / GTM / Facebook-Pixel scripts on their own handles. GA4 has a real
	 * vendor URL (gtag/js) + a small `gtag('config', …)` inline block; GTM and FB Pixel
	 * are pure inline loaders so they register with empty src and `wp_add_inline_script`.
	 *
	 * @since 1.5.6
	 * @param array{ga4:string,gtm:string,pixel:string} $ids Regex-validated vendor IDs.
	 */
	private function register_gatekeeper_analytics( array $ids ) {
		if ( '' !== $ids['ga4'] ) {
			$ga4_url = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ids['ga4'] );
			wp_register_script( 'agewallet-ga4', $ga4_url, array(), null, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Vendor script; version must not be appended.
			wp_enqueue_script( 'agewallet-ga4' );
			$inline_ga4 = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config",' . wp_json_encode( $ids['ga4'] ) . ');';
			wp_add_inline_script( 'agewallet-ga4', $inline_ga4 );
		}

		if ( '' !== $ids['gtm'] ) {
			wp_register_script( 'agewallet-gtm', '', array(), AGEWALLET_VERSION, false );
			wp_enqueue_script( 'agewallet-gtm' );
			$gtm_id     = wp_json_encode( $ids['gtm'] );
			$inline_gtm = '(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer",' . $gtm_id . ');';
			wp_add_inline_script( 'agewallet-gtm', $inline_gtm );
		}

		if ( '' !== $ids['pixel'] ) {
			wp_register_script( 'agewallet-fb-pixel', '', array(), AGEWALLET_VERSION, false );
			wp_enqueue_script( 'agewallet-fb-pixel' );
			$pixel_id     = wp_json_encode( $ids['pixel'] );
			$inline_pixel = '!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init",' . $pixel_id . ');fbq("track","PageView");';
			wp_add_inline_script( 'agewallet-fb-pixel', $inline_pixel );
		}
	}

	/**
	 * Returns the strict-mode analytics `<noscript>` fallback markup (GTM iframe + FB
	 * Pixel tracking image). These aren't `<script>` tags so they live in the gatekeeper
	 * body markup rather than the enqueue pipeline. Escaped via esc_url().
	 *
	 * @since 1.5.6
	 * @return string Empty when no analytics IDs are configured.
	 */
	public function get_gatekeeper_analytics_noscript() {
		if ( ! class_exists( 'AgeWallet_Helpers' ) ) {
			return '';
		}
		$ids  = AgeWallet_Helpers::instance()->get_analytics_ids();
		$html = '';
		if ( '' !== $ids['gtm'] ) {
			$html .= '<noscript><iframe src="' . esc_url( 'https://www.googletagmanager.com/ns.html?id=' . $ids['gtm'] ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
		}
		if ( '' !== $ids['pixel'] ) {
			$html .= '<noscript><img height="1" width="1" style="display:none" src="' . esc_url( 'https://www.facebook.com/tr?id=' . $ids['pixel'] . '&ev=PageView&noscript=1' ) . '" alt="" /></noscript>';
		}
		return $html;
	}

	/**
	 * Enqueue the Age Restriction meta-box helper JS on post-edit screens. Handles the
	 * mutual-exclusion toggle between the "Force" and "Exclude" checkboxes.
	 *
	 * @since 1.5.6
	 * @param string $hook Current admin page slug.
	 */
	public function enqueue_post_restriction_script( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$path = 'assets/js/post-restriction.js';
		$ver  = AGEWALLET_VERSION;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$file_path = AGEWALLET_PLUGIN_DIR . $path;
			if ( file_exists( $file_path ) ) {
				$ver = filemtime( $file_path ) ?: $ver;
			}
		}
		wp_enqueue_script( 'agewallet-post-restriction', AGEWALLET_PLUGIN_URL . $path, array(), $ver, true );
	}

/**
	 * Intercepts the template loading process to serve the Gatekeeper Skeleton
	 * when Strict Mode is enabled.
	 *
	 * @since 1.1.0
	 * @param string $template The path to the template WordPress intends to load.
	 * @return string The template path (modified if gated).
	 */
	public function intercept_template_loading( $template ) {
		// 1. Bypass if this is a Loopback Request (API building cache).
		// We check BOTH the constant (set by API class) AND the query string directly as a fail-safe.
		$bypass_secret = get_option( 'agewallet_loopback_secret' );

		// HMAC bypass-secret read; auth is hash_equals below, not a nonce. Server-to-server signed
		// request from our own build_cache() loopback, not a form submit.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$param_secret  = isset( $_GET['agewallet_cache_bypass'] ) ? sanitize_text_field( wp_unslash( $_GET['agewallet_cache_bypass'] ) ) : '';

		// --- DEBUGGING INSTRUMENTATION ---
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Only log if we are potentially dealing with a bypass attempt to reduce noise
			if ( ! empty( $param_secret ) ) {
				$this->log_debug( '--- BYPASS DEBUG ---' );
				$this->log_debug( 'Stored Secret: ' . $bypass_secret );
				$this->log_debug( 'Incoming Raw: ' . ( isset( $_GET['agewallet_cache_bypass'] ) ? sanitize_text_field( wp_unslash( $_GET['agewallet_cache_bypass'] ) ) : 'NULL' ) );
				$this->log_debug( 'Incoming Stripped: ' . $param_secret );
				$this->log_debug( 'Constant Defined: ' . ( defined( 'AGEWALLET_CACHE_BUILDING' ) ? 'YES' : 'NO' ) );
			}
		}
		// --- END DEBUGGING ---
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Check if secret exists and matches.
		$is_valid_bypass = ( ! empty( $bypass_secret ) && hash_equals( $bypass_secret, $param_secret ) );

		if ( ( defined( 'AGEWALLET_CACHE_BUILDING' ) && AGEWALLET_CACHE_BUILDING ) || $is_valid_bypass ) {
			$this->log_debug( 'Template Intercept: Bypassing for Cache Build (Loopback via Query/Const).' );

			// Also ensure W3TC Lazy Load is disabled for this request if it wasn't caught earlier.
			add_filter( 'w3tc_lazyload_can_process', '__return_false' );

			return $template;
		}

		// 2. Check if Strict Mode is enabled.
		$mode = get_option( 'agewallet_protection_mode', 'standard' );
		if ( 'strict' !== $mode ) {
			return $template;
		}

		// 3. Initial Checks (Admin, Feed, API, etc).
		if ( $this->is_excluded_context() ) {
			$this->log_debug( 'Template Intercept: Context excluded (Admin/Feed/Editor).' );
			return $template;
		}

		// 4. Check Content Rules (Is this specific page protected?).
		if ( ! $this->should_restrict_content() ) {
			$this->log_debug( 'Template Intercept: Content does not require gating.' );
			return $template;
		}

		// 4a. WooCommerce pages always use Standard mode. The strict-mode cache stores the
		// cookieless-loopback HTML keyed only by URL, so it can't hold a shopper's cart, their
		// WooCommerce messages or anything else that belongs to them (and caching cart, checkout
		// or account pages would leak one customer's HTML to another). Fall back to the normal
		// template so WC renders the live page; the gate JS still draws the overlay on top.
		if ( class_exists( 'AgeWallet_WooCommerce' ) && AgeWallet_WooCommerce::is_dynamic_wc_page() ) {
			$this->log_debug( 'Template Intercept: WooCommerce page, using Standard mode (live page + overlay).' );
			return $template;
		}

		// 5. Serve the Skeleton (UNCONDITIONALLY).
		// We specifically removed the cookie check here to ensure Cloudflare always caches the Skeleton.
		// The hydration logic in gate.js will handle verified users.
		$this->log_debug( 'Template Intercept: Serving Gatekeeper Skeleton (Strict Mode Active).' );
		$skeleton_path = AGEWALLET_PLUGIN_DIR . 'templates/gatekeeper.php';
		if ( file_exists( $skeleton_path ) ) {
			// Allow developers to swap the skeleton template.
			return apply_filters( 'agewallet_skeleton_template', $skeleton_path );
		}

		$this->log_debug( 'Template Intercept ERROR: Skeleton file not found at ' . $skeleton_path );
		return $template;
	}

	/**
	 * Determines if the current page view requires gating or uses protected content,
	 * and enqueues assets if needed. Hooked to 'wp_enqueue_scripts' priority 20.
	 *
	 * @since 0.1.0
	 */
	public function maybe_enqueue_gate_assets() {
		$this->log_debug( '[Gating Manager] Checking if gate assets should be enqueued.' );

		// Use centralized check for context/rules.
		$should_restrict = $this->should_restrict_content();

		// Gated pages get the same assets for every visitor; gate.js checks the cookie in the browser.
		if ( ! $should_restrict ) {
			$this->log_debug( 'Final Decision: No assets needed (Not restricted).' );
			return;
		}

		// Enqueue Assets and Localize.
		if ( ! $this->assets_enqueued ) {
			$this->log_debug( 'Final Decision: Enqueuing assets.' );

			wp_enqueue_style( self::STYLE_HANDLE );
			wp_enqueue_script( self::SCRIPT_HANDLE );

			// Apply the admin Gate Appearance colours + radii in standard mode too. The strict
			// skeleton emits the same vars inline via render_css_vars(); attaching them to the
			// gate.css handle keeps the overlay and shortcode placeholder consistent with it.
			if ( class_exists( 'AgeWallet_Helpers' ) ) {
				$css_vars = AgeWallet_Helpers::instance()->get_gate_css_vars();
				if ( '' !== $css_vars ) {
					wp_add_inline_style( self::STYLE_HANDLE, $css_vars );
				}
			}

			$script_data = array(
				'cookieName'       => self::VERIFIED_COOKIE_NAME,
				'bodyClassPending' => 'agewallet-gated-pending',
				'gateHtml'         => '',
				'isOverlayActive'  => false,
			);

			// The page needs the gate assets, but only show the WHOLE-PAGE OVERLAY when the page is
			// auto-gated (block mode / path / taxonomy / WooCommerce). A page that qualifies ONLY
			// because it contains the [agewallet_protected] shortcode must render that shortcode's
			// INLINE placeholder, not an overlay — so re-check while ignoring shortcode presence.
			$is_auto_gated = $this->should_restrict_content( null, false );

			if ( $is_auto_gated ) {
				// Note: In Strict Mode this enqueuing might be redundant if intercept_template_loading
				// swapped the template, but it's harmless.
				$script_data['gateHtml']        = $this->get_gate_html();
				$script_data['isOverlayActive'] = true;
				$this->log_debug( 'Localizing script data including overlay HTML (auto-gated).' );

				// Signal to add the body hiding class (whole page hidden until JS runs). Only for the
				// overlay; a shortcode-only page must NOT hide the whole body. Double-underscore prefix
				// marks this as an internal, not-publicly-documented hook; plugin-check's regex misses
				// the prefix, so annotate.
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				add_filter( '__agewallet_is_gating_this_request', '__return_true' );
			} else {
				// Shortcode-only page: gate.js loads, but isOverlayActive stays false and no body
				// class is added — handleProtectedBlocks() toggles the inline placeholder in place.
				$this->log_debug( 'Localizing script data for shortcode-only page (inline placeholder, no overlay).' );
			}

			// HOOK: Allow developers to add or modify data passed to the front-end script.
			$script_data = apply_filters( 'agewallet_gate_script_data', $script_data );

			wp_localize_script( self::SCRIPT_HANDLE, 'agewallet_gate_data', $script_data );
			$this->assets_enqueued = true;

		} elseif ( $this->assets_enqueued ) {
			$this->log_debug( 'Assets already enqueued for this request.' );
		}
	}

	/**
	 * Centralized logic to determine if the current request should be gated.
	 * Checks Context, Meta, Global Settings, and Shortcodes.
	 *
	 * @since 1.1.0 Updated to accept optional Post ID for API checks.
	 * @param int|null $context_post_id   Optional. The post ID to check (for API requests).
	 * @param bool     $include_shortcode Optional. When false, the [agewallet_protected] shortcode's
	 *                                    presence does NOT count toward gating. The asset-enqueue
	 *                                    decision passes true (a shortcode needs the gate assets); the
	 *                                    whole-page-overlay decision passes false (a shortcode renders
	 *                                    its own inline placeholder, never an overlay). Default true.
	 * @return bool True if content should be restricted.
	 */
	public function should_restrict_content( $context_post_id = null, $include_shortcode = true ) {
		// 1. Context Checks.
		// If we are running an API check ($context_post_id is set), we skip the context check (admin/cli)
		// because the API itself is a REST request, which is normally excluded.
		if ( is_null( $context_post_id ) && $this->is_excluded_context() ) {
			return false;
		}

		// Determine the Post ID to check (passed or current).
		$post_id = $context_post_id;
		if ( ! $post_id && is_singular() ) {
			$post_id = get_the_ID();
		}

		// 2. Check Post Meta Settings (Highest Priority).
		if ( $post_id ) {
			$force_exclude  = get_post_meta( $post_id, self::META_KEY_FORCE_EXCLUDE, true );
			$force_restrict = get_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT, true );

			if ( '1' === $force_exclude ) {
				$this->log_debug( 'Rule Check: Post excluded via Meta.', array( 'post_id' => $post_id ) );
				return false;
			} elseif ( '1' === $force_restrict ) {
				$this->log_debug( 'Rule Check: Post restricted via Meta.', array( 'post_id' => $post_id ) );
				return true;
			}
		}

		// 3. Global Exclusion Paths (Prioritized before Global Rules).
		$excluded_paths_str = get_option( 'agewallet_excluded_paths', '' );
		if ( ! empty( $excluded_paths_str ) ) {
			// Determine path to check.
			if ( $post_id ) {
				// API Context: Get path from permalink.
				$url  = get_permalink( $post_id );
				$path = wp_parse_url( $url, PHP_URL_PATH );
			} else {
				// Current Request Context.
				$path = strtok( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', '?' );
			}
			$current_path_norm = trailingslashit( $path );
			$excluded_paths    = array_map( 'trim', explode( ',', $excluded_paths_str ) );

			foreach ( $excluded_paths as $ex_path ) {
				if ( empty( $ex_path ) || '/' !== $ex_path[0] ) {
					continue;
				}
				// Normalize paths for matching.
				$ex_path_norm = trailingslashit( $ex_path );
				if ( strpos( $current_path_norm, $ex_path_norm ) !== false || ( '/' === $ex_path && '/' === $path ) ) {
					$this->log_debug( 'Rule Check: Path excluded by Global Exception.', array( 'path' => $ex_path ) );
					return false; // Explicitly excluded.
				}
			}
		}

		// Check Taxonomy Rules
		// Priority: Term Exclusion > Term Gate
		if ( $post_id ) {
			$tax_rules = get_option( 'agewallet_taxonomy_rules', array() );
			// Dev Hook to modify rules dynamically
			$tax_rules = apply_filters( 'agewallet_taxonomy_rules', $tax_rules );

			if ( ! empty( $tax_rules ) && is_array( $tax_rules ) ) {
				$gate_signal = false; // Store gate signal, but allow loop to continue looking for exclusions

				foreach ( $tax_rules as $tax => $data ) {
					$mode = isset( $data['mode'] ) ? $data['mode'] : 'ignore';
					if ( 'ignore' === $mode ) continue;

					// Check if post has terms in this taxonomy
					$post_terms = get_the_terms( $post_id, $tax );
					if ( ! $post_terms || is_wp_error( $post_terms ) ) continue;

					// Extract IDs
					$post_term_ids = wp_list_pluck( $post_terms, 'term_id' );

					// A. Check Exclusions FIRST (Trump card inside taxonomy logic)
					if ( 'exclude_all' === $mode ) {
						$this->log_debug( 'Rule Check: Allowed by Taxonomy (Exclude All).', array( 'taxonomy' => $tax ) );
						return apply_filters( 'agewallet_should_gate_request', false, $post_id );
					}
					elseif ( 'specific' === $mode && ! empty( $data['terms_exclude'] ) ) {
						$excluded_term_ids = array_map( 'absint', explode( ',', $data['terms_exclude'] ) );
						if ( array_intersect( $post_term_ids, $excluded_term_ids ) ) {
							$this->log_debug( 'Rule Check: Allowed by Taxonomy (Specific Exclusion).', array( 'taxonomy' => $tax ) );
							return apply_filters( 'agewallet_should_gate_request', false, $post_id );
						}
					}

					// B. Check Gating (If no exclusion found yet)
					if ( 'gate_all' === $mode ) {
						$gate_signal = true;
						$this->log_debug( 'Rule Check: Matched Gate All.', array( 'taxonomy' => $tax ) );
					}
					elseif ( 'specific' === $mode && ! empty( $data['terms_gate'] ) ) {
						$gated_term_ids = array_map( 'absint', explode( ',', $data['terms_gate'] ) );
						if ( array_intersect( $post_term_ids, $gated_term_ids ) ) {
							$gate_signal = true;
							$this->log_debug( 'Rule Check: Matched Specific Gate.', array( 'taxonomy' => $tax ) );
						}
					}
				}

				// If we found a gate signal and NO exclusion signal (we would have returned false already), return true.
				if ( $gate_signal ) {
					return apply_filters( 'agewallet_should_gate_request', true, $post_id );
				}
			}
		}

		// 4a. WooCommerce checkout — additive override. Gates the checkout
		// regardless of block_mode/paths/taxonomy, in one of two modes:
		//   - force-always:        every checkout gates (current binary behavior)
		//   - conditional-on-cart: gates only when an unverified visitor's cart
		//                          contains at least one item flagged as regulated
		if (
			class_exists( 'WooCommerce' )
			&& class_exists( 'AgeWallet_WooCommerce' )
			&& is_null( $context_post_id ) // API/post-context callers skip this; this is a request-level rule.
			&& AgeWallet_WooCommerce::is_checkout_request()
		) {
			$mode = AgeWallet_WooCommerce::checkout_gating_mode();

			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS === $mode ) {
				$this->log_debug( 'Rule Check: WC checkout force-always enabled and request is checkout.' );
				return apply_filters( 'agewallet_should_gate_request', true, $post_id );
			}

			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_CONDITIONAL_CART === $mode ) {
				// Short-circuit: already-verified visitors don't need cart inspection
				// (and we don't want to pay for the per-item meta reads on every page hit).
				$is_verified = false;
				if ( isset( $_COOKIE[ self::VERIFIED_COOKIE_NAME ] ) && class_exists( 'AgeWallet_Helpers' ) ) {
					$is_verified = AgeWallet_Helpers::instance()->verify_signed_cookie(
						sanitize_text_field( wp_unslash( $_COOKIE[ self::VERIFIED_COOKIE_NAME ] ) )
					);
				}
				if ( $is_verified ) {
					$this->log_debug( 'Rule Check: WC checkout conditional-on-cart, visitor already verified — skipping cart inspection.' );
					return false;
				}
				if ( AgeWallet_WooCommerce::cart_contains_regulated_items() ) {
					$this->log_debug( 'Rule Check: WC checkout conditional-on-cart, cart contains regulated items — gating.' );
					return apply_filters( 'agewallet_should_gate_request', true, $post_id );
				}
				$this->log_debug( 'Rule Check: WC checkout conditional-on-cart, no regulated items in cart — not gating.' );
				return false;
			}

			// WC_GATE_MODE_OFF — fall through to general block_mode logic below.
		}

		// 4. Check Global Settings.
		$global_block_mode      = get_option( AgeWalletOIDCClientPro::OPT_BLOCK_MODE, 'none' );
		$global_requires_gating = false;

		switch ( $global_block_mode ) {
			case 'all':
				$global_requires_gating = true;
				break;
			case 'all_but_home':
				// If checking a specific ID (API), check if it's front page.
				if ( $post_id ) {
					$front_id = (int) get_option( 'page_on_front' );
					if ( $post_id !== $front_id ) {
						$global_requires_gating = true;
					}
				} elseif ( ! is_front_page() ) { // Fallback to conditional tag.
					$global_requires_gating = true;
				}
				break;
			case 'specific':
				$blocked_paths_str = get_option( AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, '' );
				if ( ! empty( $blocked_paths_str ) ) {
					$blocked_paths = array_map( 'trim', explode( ',', $blocked_paths_str ) );
					// Determine path to check.
					if ( $post_id ) {
						// API Context: Get path from permalink.
						$url  = get_permalink( $post_id );
						$path = wp_parse_url( $url, PHP_URL_PATH );
					} else {
						// Current Request Context.
						$path = strtok( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', '?' );
					}
					$current_path_norm = trailingslashit( $path );

					foreach ( $blocked_paths as $blocked_path ) {
						if ( empty( $blocked_path ) || '/' !== $blocked_path[0] ) {
							continue;
						}
						$blocked_path_norm = trailingslashit( $blocked_path );
						if ( strpos( $current_path_norm, $blocked_path_norm ) !== false || ( '/' === $blocked_path && '/' === $path ) ) {
							$global_requires_gating = true;
							$this->log_debug( 'Rule Check: Path match.', array( 'path' => $blocked_path ) );
							break;
						}
					}
				}
				break;
			default: // 'none'.
				break;
		}

		if ( $global_requires_gating ) {
			return apply_filters( 'agewallet_should_gate_request', true, $post_id );
		}

		// 5. Check Shortcode Presence.
		// A shortcode on the page means the gate ASSETS must load, but it must NOT trigger the
		// whole-page overlay — the shortcode renders its own inline placeholder. So this counts
		// only toward the asset-enqueue decision ( $include_shortcode = true ); the overlay
		// decision passes false. Shortcodes never gate in Strict Mode.
		if ( $include_shortcode
			&& 'strict' !== get_option( 'agewallet_protection_mode', 'standard' )
			&& $post_id ) {
			$post_obj = get_post( $post_id );
			if ( isset( $post_obj ) && has_shortcode( $post_obj->post_content, 'agewallet_protected' ) ) {
				$this->log_debug( 'Rule Check: Shortcode found (asset enqueue only).' );
				return true;
			}
		}

		return apply_filters( 'agewallet_should_gate_request', false, $post_id );
	}

	/**
	 * Helper to check if the current context is excluded from gating (Admin, Feed, etc).
	 * @return bool
	 */
	private function is_excluded_context() {
		if ( is_admin() || defined( 'WP_CLI' ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}
		if ( is_feed() || is_preview() || is_customize_preview() ) {
			return true;
		}
		if ( is_user_logged_in() && current_user_can( 'edit_others_posts' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Adds body class for pending state.
	 * @since 0.1.0
	 */
	public function add_body_classes( $classes ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- See add_filter() site; internal hook, double-underscore prefix.
		if ( apply_filters( '__agewallet_is_gating_this_request', false ) ) {
			$classes[] = 'agewallet-gated-pending';
		}
		return $classes;
	}

	// --- Shortcode Handlers ---

	/**
	 * Handles the [agewallet_button] shortcode.
	 * @since 0.1.0
	 */
	public function button_shortcode_handler( $atts = [] ) {
		$this->log_debug( '[Gating Manager] Button shortcode handler called.' );

		if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
			$this->log_debug( '[Gating Manager] Gate style enqueued via button shortcode (fallback).' );
		}

		$output_html = $this->get_gate_html();
		if ( ! empty( $output_html ) ) {
			return '<div class="agewallet-shortcode-wrapper">' . $output_html . '</div>';
		}
		return '';
	}

	/**
	 * Handles the [agewallet_protected]...[/agewallet_protected] shortcode.
	 * @since 0.1.0
	 */
	public function protected_content_shortcode_handler( $atts = [], $content = null ) {
		$this->log_debug( '[Gating Manager] Protected content shortcode handler called.' );

		// 1. Loopback Bypass (Fix for Strict Mode Cache Build).
		if ( defined( 'AGEWALLET_CACHE_BUILDING' ) && AGEWALLET_CACHE_BUILDING ) {
			$this->log_debug( '[Gating Manager] Shortcode bypassed for Cache Build.' );
			return wp_kses_post( do_shortcode( $content ) );
		}

		// 2. Strict Mode Bypass (Disable shortcode protection logic).
		if ( 'strict' === get_option( 'agewallet_protection_mode', 'standard' ) ) {
			return wp_kses_post( do_shortcode( $content ) );
		}

		// 3. Standard Checks.
		if ( is_user_logged_in() && current_user_can( 'edit_others_posts' ) ) {
			$this->log_debug( '[Gating Manager] Protected shortcode skipped: User is Admin or Editor.' );
			return wp_kses_post( do_shortcode( $content ) );
		}

		// Ensure assets are loaded if maybe_enqueue_gate_assets didn't run or detect it early enough.
		if ( ! $this->assets_enqueued ) {
			$this->log_debug( '[Gating Manager] Enqueuing assets from protected_content_shortcode_handler (fallback needed).' );

			// --- Manually register scripts/styles first, in case register_assets() hasn't run ---
			// This logic is duplicated from register_assets() to make this fallback robust.

			// Register Stylesheet.
			if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
				$style_path = 'assets/css/gate.css';
				$style_ver  = AGEWALLET_VERSION;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$file_path = AGEWALLET_PLUGIN_DIR . $style_path;
					if ( file_exists( $file_path ) ) {
						$style_ver = filemtime( $file_path ) ?: $style_ver;
					}
				}
				wp_register_style( self::STYLE_HANDLE, AGEWALLET_PLUGIN_URL . $style_path, array(), $style_ver, 'all' );
			}

			// Register JavaScript.
			if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
				$script_path = 'assets/js/gate.js';
				$script_ver  = AGEWALLET_VERSION;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$file_path = AGEWALLET_PLUGIN_DIR . $script_path;
					if ( file_exists( $file_path ) ) {
						$script_ver = filemtime( $file_path ) ?: $script_ver;
					}
				}
				wp_register_script( self::SCRIPT_HANDLE, AGEWALLET_PLUGIN_URL . $script_path, array( 'jquery' ), $script_ver, true );
			}
			// --- END REGISTRATION ---

			// Now enqueue them.
			wp_enqueue_style( self::STYLE_HANDLE );
			wp_enqueue_script( self::SCRIPT_HANDLE );

			// Apply the admin Gate Appearance colours + radii (same vars as the strict skeleton).
			if ( class_exists( 'AgeWallet_Helpers' ) ) {
				$css_vars = AgeWallet_Helpers::instance()->get_gate_css_vars();
				if ( '' !== $css_vars ) {
					wp_add_inline_style( self::STYLE_HANDLE, $css_vars );
				}
			}

			// Check if data has been added (by this function or another).
			if ( ! wp_script_is( self::SCRIPT_HANDLE, 'data' ) ) {
				$script_data = array(
					'cookieName'       => self::VERIFIED_COOKIE_NAME,
					'bodyClassPending' => 'agewallet-gated-pending',
					'gateHtml'         => '',
					'isOverlayActive'  => false,
				);

				// --- Use wp_add_inline_script instead of wp_localize_script ---
				// This correctly adds the data object before the script tag, even when called late.
				$script_data_string = 'var agewallet_gate_data = ' . wp_json_encode( $script_data ) . ';';
				wp_add_inline_script( self::SCRIPT_HANDLE, $script_data_string, 'before' );
				// --- END INLINE SCRIPT ---

				$this->log_debug( '[GGating Manager] Injected minimal script data via shortcode fallback (wp_add_inline_script).' );
			} else {
				$this->log_debug( '[Gating Manager] Script data already localized, skipping in shortcode fallback.' );
			}
			$this->assets_enqueued = true;
		}

		// wp_kses_post() applies the same allowed-HTML filter as the post-content editor,
		// which is the WordPress-approved way to escape do_shortcode() output before it's
		// concatenated into new markup. Safe for legitimate post HTML (images, headings,
		// nested shortcodes' rendered output); strips anything not on the post-content allowlist.
		$processed_content = wp_kses_post( do_shortcode( $content ) );
		$placeholder_html  = $this->get_gate_html();
		if ( empty( $placeholder_html ) ) {
			$placeholder_html = '<p style="color:red;">Error: Could not generate verification prompt.</p>';
		}

		// Output the wrapper structure, the same for every visitor. Content starts hidden, placeholder
		// starts visible; gate.js handleProtectedBlocks() swaps them for a verified visitor.
		$wrapper_class     = 'agewallet-protected-wrapper';
		$content_class     = 'agewallet-protected-content';
		$placeholder_class = 'agewallet-protected-placeholder';

		$output  = '<div class="' . esc_attr( $wrapper_class ) . '">';
		$output .= '<div class="' . esc_attr( $content_class ) . '" style="display: none;">';
		$output .= $processed_content;
		$output .= '</div>';
		$output .= '<div class="' . esc_attr( $placeholder_class ) . '" style="display: block;">';
		$output .= $placeholder_html;
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * The address every Verify / I Agree button sends the visitor to: the launch address, the page
	 * to come back to, and the signed metadata and origin marker for the current page. Built while
	 * WordPress has the page's context, so it carries the same values wherever the button is.
	 *
	 * @param string $return_url Where the visitor comes back to after verifying.
	 * @return string The launch URL, or '' if it can't be determined.
	 */
	public static function get_verify_url( $return_url ) {
		$launch_url = AgeWallet_Helpers::instance()->get_launch_url();
		if ( ! $launch_url ) {
			return '';
		}
		$url = add_query_arg( 'redirect_to', urlencode( $return_url ), $launch_url );

		// Sign the metadata so handle_launch() can trust the value despite it riding in a URL.
		if ( class_exists( 'AgeWallet_Metadata_Builder' ) ) {
			$md_value = AgeWallet_Metadata_Builder::build();
			if ( is_string( $md_value ) && '' !== $md_value ) {
				$url = add_query_arg( 'agewallet_md', urlencode( AgeWallet_Helpers::instance()->sign_metadata( $md_value ) ), $url );
			}
		}

		// Mark checkout-origin so handle_launch() knows to attach WC cart-context metadata at
		// click-time. The marker rides as a separate signed query param so it stays out of the
		// final metadata payload stored against the verification.
		if ( class_exists( 'AgeWallet_WooCommerce' ) && AgeWallet_WooCommerce::is_checkout_request() ) {
			$url = add_query_arg( 'agewallet_origin', urlencode( AgeWallet_Helpers::instance()->sign_metadata( 'checkout' ) ), $url );
		}

		return $url;
	}

	/**
	 * Generates the HTML markup for the age gate prompt.
	 * @since 0.1.0
	 * @access public
	 */
	public function get_gate_html() {
		$this->log_debug( '[Gating Manager] Generating gate HTML.' );

		$logo_id    = (int) get_option( AgeWalletOIDCClientPro::OPT_LOGO_ID, 0 );
		$logo_width = (int) get_option( AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, 0 );
		$copy_html  = get_option( AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG, '' );
		$hide_h1    = (bool) get_option( AgeWalletOIDCClientPro::OPT_HIDE_HEADING, 0 );
		$logo_src   = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		if ( ! empty( $copy_html ) ) {
			$desc_html = wpautop( trim( $copy_html ) );
		} else {
			$default_copy = __( 'You must be 18+ to view this content (or meet the minimum age required by your local jurisdiction). By selecting “I Agree,” you confirm that you meet the minimum age requirement and consent to verification by our partner, AgeWallet™. If you do not meet the minimum age requirement or do not agree, please select “I Disagree.”', 'agewallet-oidc-client' );
			$desc_html    = '<p>' . esc_html( $default_copy ) . '</p>';
		}

		$agree_href = self::get_verify_url( AgeWallet_Helpers::instance()->get_current_url() );

		if ( ! $agree_href ) {
			 $this->log_debug( '[Gating Manager] ERROR: Could not get launch URL for gate HTML.' );
			 return '<p style="color:red;">Error: Could not determine launch URL.</p>';
		}

		$args = array(
			'logo_src'         => $logo_src,
			'logo_alt'         => __( 'Logo', 'agewallet-oidc-client' ),
			'logo_width'       => $logo_width,
			'show_title'       => ! $hide_h1,
			'title'            => __( 'You Must Verify Your Age', 'agewallet-oidc-client' ),
			'description_html' => $desc_html,
			'agree_url'        => $agree_href,
			'agree_text'       => __( 'I Agree', 'agewallet-oidc-client' ),
			'disagree_text'    => __( 'I Disagree', 'agewallet-oidc-client' ),
			'error_text'       => __( 'Sorry, you do not meet the minimum requirements to view this content.', 'agewallet-oidc-client' ),
			'disclaimer_html'  => sprintf(
				/* translators: %s: URL to AgeWallet website */
				__( 'By proceeding you agree to allow %s to verify your age.', 'agewallet-oidc-client' ),
				'<a href="https://www.agewallet.com" target="_blank" rel="noopener noreferrer">AgeWallet™</a>'
			),
		);

		$args = apply_filters( 'agewallet_gate_template_args', $args );

		ob_start();
		?>
		<div class="aw-gate aw-gate__card">
			<?php if ( ! empty( $args['logo_src'] ) ) : ?>
				<div class="aw-gate__logo-wrap">
					<img class="aw-gate__logo"
						 src="<?php echo esc_url( $args['logo_src'] ); ?>"
						 alt="<?php echo esc_attr( $args['logo_alt'] ); ?>"
						<?php if ( ! empty( $args['logo_width'] ) && $args['logo_width'] > 0 ) : ?>
							 style="width:<?php echo intval( $args['logo_width'] ); ?>px; max-width:100%; height:auto;"
						<?php else : ?>
							 style="max-width:100%; height:auto;"
						<?php endif; ?>
					>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $args['show_title'] ) ) : ?>
				<h1 class="aw-gate__title"><?php echo esc_html( $args['title'] ); ?></h1>
			<?php endif; ?>

			<div class="aw-gate__desc">
				<?php echo wp_kses_post( $args['description_html'] ); ?>
			</div>

			<div class="aw-gate__buttons">
				<button class="aw-gate__btn aw-gate__btn--no" type="button">
					<?php echo esc_html( $args['disagree_text'] ); ?>
				</button>
				<button class="aw-gate__btn aw-gate__btn--yes" type="button" data-redirect-url="<?php echo esc_url( $args['agree_url'] ); ?>">
					<?php echo esc_html( $args['agree_text'] ); ?>
				</button>
			</div>

			<?php do_action( 'agewallet_after_gate_buttons' ); ?>

			<div class="aw-gate__error" style="display:none;"><?php echo esc_html( $args['error_text'] ); ?></div>

			<p class="aw-gate__disclaimer">
				<?php
				echo wp_kses(
					$args['disclaimer_html'],
					array(
						'a' => array(
							'href'   => true,
							'target' => true,
							'rel'    => true,
						),
					)
				);
				?>
			</p>
		</div>
		<?php
		$html = ob_get_clean();
		$this->log_debug( '[Gating Manager] Finished generating gate HTML.', array( 'html_length' => strlen( $html ) ) );
		return $html;
	}

	// --- Meta Box Handling ---

	/**
	 * Registers the meta box on relevant post types. Hooked to 'add_meta_boxes'.
	 * @since 0.1.0
	 */
	public function add_restriction_meta_box() {
		$this->log_debug( '[Gating Manager] Adding meta boxes.' );
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types = apply_filters( 'agewallet_meta_box_post_types', $post_types );

		if ( empty( $post_types ) ) {
			$this->log_debug( '[Gating Manager] No public post types found to add meta box to.' );
			return;
		}

		$this->log_debug( '[Gating Manager] Adding meta box to post types.', array( 'post_types' => $post_types ) );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'agewallet_restriction_meta',
				__( 'Age Restriction', 'agewallet-oidc-client' ),
				array( $this, 'render_restriction_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the content of the Age Restriction meta box.
	 * @since 0.1.0
	 * @param WP_Post $post The current post object.
	 */
	public function render_restriction_meta_box( $post ) {
		wp_nonce_field( 'agewallet_save_restriction_meta_action', 'agewallet_restriction_nonce' );
		$force_restrict = get_post_meta( $post->ID, self::META_KEY_FORCE_RESTRICT, true );
		$force_exclude  = get_post_meta( $post->ID, self::META_KEY_FORCE_EXCLUDE, true );
		?>
		<p>
			<label for="agewallet_force_restrict">
				<input type="checkbox" id="agewallet_force_restrict" name="agewallet_force_restrict" value="1"
					<?php checked( $force_restrict, '1' ); ?>
					<?php echo ( '1' === $force_exclude ) ? 'disabled="disabled"' : ''; ?> />
				<?php esc_html_e( 'Require age verification', 'agewallet-oidc-client' ); ?>
			</label><br>
			<small><?php esc_html_e( 'Visitors must verify their age to view this page, even if nothing else protects it.', 'agewallet-oidc-client' ); ?></small>
		</p>
		<hr style="margin: 10px 0;">
		<p>
			<label for="agewallet_force_exclude">
				<input type="checkbox" id="agewallet_force_exclude" name="agewallet_force_exclude" value="1"
					<?php checked( $force_exclude, '1' ); ?> />
				<?php esc_html_e( 'Exclude from age verification', 'agewallet-oidc-client' ); ?>
			</label><br>
			<small><?php esc_html_e( 'Anyone can view this page, whatever your other rules say.', 'agewallet-oidc-client' ); ?></small>
		</p>
		<p><small><?php esc_html_e( 'If both are ticked, Exclude wins.', 'agewallet-oidc-client' ); ?></small></p>
		<?php if ( 'product' === $post->post_type ) : ?>
			<p><small><?php esc_html_e( 'This box controls who can view the page. Whether the product can be bought before verifying is set in Product data → AgeWallet.', 'agewallet-oidc-client' ); ?></small></p>
		<?php endif; ?>
		<?php
		// Mutual-exclusion toggle behaviour is enqueued via enqueue_post_restriction_script()
		// (assets/js/post-restriction.js) on post.php / post-new.php.
	}

	/**
	 * Saves the custom meta box data when a post is saved. Hooked to 'save_post'.
	 * @since 0.1.0
	 * @param int $post_id The ID of the post being saved.
	 */
	public function save_restriction_meta_box( $post_id ) {
		// Check if nonce is set and valid.
		if ( ! isset( $_POST['agewallet_restriction_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['agewallet_restriction_nonce'] ), 'agewallet_save_restriction_meta_action' ) ) {
			$this->log_debug( 'Meta save aborted: Invalid nonce.', array( 'post_id' => $post_id ) );
			return;
		}
		// Check if this is an autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			$this->log_debug( 'Meta save skipped: Is autosave.', array( 'post_id' => $post_id ) );
			return;
		}
		// Check if it's a revision - prevent saving meta for revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			 $this->log_debug( 'Meta save skipped: Is revision.', array( 'post_id' => $post_id ) );
			 return;
		}
		// Check user permissions based on post type.
		$post_type         = get_post_type( $post_id );
		$public_post_types = apply_filters( 'agewallet_meta_box_post_types', get_post_types( array( 'public' => true ), 'names' ) );
		if ( ! in_array( $post_type, $public_post_types, true ) ) {
			$this->log_debug( 'Meta save skipped: Post type not applicable.', array(
				'post_id'   => $post_id,
				'post_type' => $post_type,
			) );
			return;
		}
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
			$this->log_debug( 'Meta save aborted: User lacks permission.', array(
				'post_id' => $post_id,
				'user_id' => get_current_user_id(),
			) );
			return;
		}

		$this->log_debug( '[Gating Manager] Saving restriction meta box data.', array( 'post_id' => $post_id ) );

		$force_exclude_value = filter_input( INPUT_POST, 'agewallet_force_exclude', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( true === $force_exclude_value ) {
			update_post_meta( $post_id, self::META_KEY_FORCE_EXCLUDE, '1' );
			delete_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT );
			$this->log_debug( 'Saved meta: force_exclude=1, force_restrict=deleted', array( 'post_id' => $post_id ) );
		} else {
			delete_post_meta( $post_id, self::META_KEY_FORCE_EXCLUDE );
			$force_restrict_value = filter_input( INPUT_POST, 'agewallet_force_restrict', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( true === $force_restrict_value ) {
				update_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT, '1' );
				$this->log_debug( 'Saved meta: force_exclude=deleted, force_restrict=1', array( 'post_id' => $post_id ) );
			} else {
				delete_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT );
				$this->log_debug( 'Saved meta: force_exclude=deleted, force_restrict=deleted', array( 'post_id' => $post_id ) );
			}
		}
		do_action( 'agewallet_meta_box_save', $post_id, $force_exclude_value, $force_restrict_value );
	}

	// --- Utility & Logging ---

	/** Helper method to log debug messages. @since 0.1.0 */
	private function log_debug( $message, $context = null ) {
		if ( class_exists( 'AgeWallet_Helpers' ) && method_exists( AgeWallet_Helpers::instance(), 'log' ) ) {
			 AgeWallet_Helpers::instance()->log( '[Gating Mgr] ' . $message, $context );
		} else {
			$log_entry = '[AgeWallet Plugin] [Gating Mgr] ' . $message;
			if ( ! is_null( $context ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Fallback debug formatter when central logger is unavailable; only runs under WP_DEBUG_LOG.
				$log_entry .= ' Context: ' . print_r( $context, true );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback debug output when central logger is unavailable; only runs under WP_DEBUG_LOG.
			error_log( preg_replace( '/\s+/', ' ', trim( $log_entry ) ) );
		}
	}

	// --- Singleton Pattern Boilerplate ---
	/** Cloning forbidden. @since 0.1.0 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'agewallet-oidc-client' ), '0.1.0' );
	}
	/** Unserializing forbidden. @since 0.1.0 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing forbidden.', 'agewallet-oidc-client' ), '0.1.0' );
	}

} // End class AgeWallet_Gating_Manager