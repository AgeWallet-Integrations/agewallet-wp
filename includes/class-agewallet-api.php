<?php
/**
 * AgeWallet API & Cache Handler
 *
 * Manages the secure REST API endpoint for content retrieval and the
 * file-based caching system used in "Strict Mode".
 *
 * @package AgeWalletOIDCClient
 * @since   1.1.0
 */

// Prevent direct script access.
defined( 'ABSPATH' ) || exit;

class AgeWallet_API {

	/**
	 * The single instance of the class.
	 * @var AgeWallet_API|null
	 */
	private static $instance = null;

	/**
	 * The absolute path to the cache base directory.
	 * @var string
	 */
	private $cache_dir = '';

	/**
	 * The secret key used to authorize loopback requests for cache building.
	 * @var string
	 */
	private $bypass_secret = '';

	/**
	 * Ensures only one instance of the class is loaded.
	 * @return AgeWallet_API Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Sets up paths, secrets, and hooks.
	 */
	private function __construct() {
		// Define cache directory: /wp-content/uploads/agewallet-cache/
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'agewallet-cache/';

		/**
		 * Filter: agewallet_cache_directory
		 * Allows developers to move the HTML cache to a custom location.
		 */
		$this->cache_dir = trailingslashit( apply_filters( 'agewallet_cache_directory', $base_dir ) );

		// Retrieve the loopback bypass secret.
		$this->bypass_secret = get_option( 'agewallet_loopback_secret' );

		// Self-Healing: Check if secret is missing or invalid.
		if ( empty( $this->bypass_secret ) || preg_match( '/[^a-zA-Z0-9]/', $this->bypass_secret ) ) {
			$this->bypass_secret = wp_generate_password( 64, false );
			update_option( 'agewallet_loopback_secret', $this->bypass_secret );
		}

		// Register the API Endpoint.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Register Extended Cache Invalidation Hooks
		$this->register_extended_invalidation_hooks();

		// Loopback Request Handling.
		add_action( 'template_redirect', array( $this, 'handle_loopback_request' ), 0 );
		// Inject Cache Type Header during loopback.
		add_action( 'wp_headers', array( $this, 'add_cache_type_headers' ) );

		// Register Cron Schedule and Event
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_interval' ) );
		add_action( 'agewallet_scheduled_purge_event', array( $this, 'clear_all_cache' ) );

		// Detect Setting Change to Reschedule immediately
		add_action( 'update_option_agewallet_cache_ttl', array( $this, 'handle_ttl_change' ), 10, 2 );

		// Detect metadata-related setting changes and auto-purge the cache.
		// The signed md= URL is baked into each cached page at render time, so
		// without these hooks the old metadata keeps shipping to visitors until
		// the cron purge fires (or the admin clicks Purge Cache manually).
		$metadata_options = array(
			'agewallet_metadata_mode',                       // off/static/auto radio
			AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT,    // static text input
			'agewallet_auto_metadata_fields',                // auto-mode checkboxes
			AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS,  // WooCommerce cart-context checkboxes
		);
		foreach ( $metadata_options as $opt ) {
			add_action( "update_option_{$opt}", array( $this, 'handle_metadata_setting_change' ), 10, 2 );
		}

		$this->schedule_cache_purge();
	}

	/**
	 * Registers extended hooks for cache invalidation.
	 */
	private function register_extended_invalidation_hooks() {
		// 1. Post Content Changes (Singular & Archive)
		// 'save_post' handles post creation and updates.
		add_action( 'save_post', array( $this, 'clear_post_cache' ), 10, 3 );

		// 2. Comment Changes (Singular)
		// Comments appear on the post page, so we must clear that specific post.
		$comment_actions = array( 'comment_post', 'edit_comment', 'wp_set_comment_status' );
		foreach ( $comment_actions as $action ) {
			add_action( $action, array( $this, 'clear_post_cache_from_comment' ) );
		}

		// 3. Global Site Structure Changes (Clear EVERYTHING)
		// These changes affect navigation, footers, or archives globally.
		$global_actions = array(
			// Theme/Menu
			'switch_theme',
			'wp_create_nav_menu',
			'wp_update_nav_menu',
			'wp_delete_nav_menu',
			// Taxonomy Terms (Affects archives and potentially menus)
			'create_term',
			'edit_terms',
			'delete_term',
			// Links (Blogroll/Links Manager if active)
			'add_link',
			'edit_link',
			'delete_link',
		);

		// Allow developers to modify this list
		$global_actions = apply_filters( 'agewallet_cache_invalidation_events', $global_actions );

		foreach ( $global_actions as $action ) {
			add_action( $action, array( $this, 'clear_all_cache' ) );
		}
	}

	/**
	 * Registers the secure content retrieval endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			'agewallet/v1',
			'/content',
			array(
				// Accept POST methods to bypass aggressive GET caching (Varnish/WP Engine).
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'get_content' ),
				// We handle permission checks manually to inspect cookies.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The API Callback: Returns full page HTML for verified users.
	 *
	 * @param WP_REST_Request $request The API request object.
	 * @return WP_REST_Response The JSON response containing HTML.
	 */
	public function get_content( $request ) {
		// 1. Security Check: Verify AgeWallet Cookie.
		if ( ! $this->is_user_verified() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'unverified',
					'message' => __( 'Age verification required.', 'agewallet-oidc-client' ),
				),
				403
			);
		}

		// 2. Determine Target.
		// Support params in both Body (POST) and Query (GET).
		$target_url = $request->get_param( 'url' );
		$post_id    = (int) $request->get_param( 'id' );

		if ( empty( $target_url ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'missing_url' ), 400 );
		}

		// This endpoint only caches this site's own pages via a loopback request. Reject any
		// URL that is not same-origin so the `url` param cannot be used to make the server
		// fetch arbitrary internal or external hosts (SSRF).
		$site_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$target_host = strtolower( (string) wp_parse_url( $target_url, PHP_URL_HOST ) );
		if ( '' === $target_host || $target_host !== $site_host ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'invalid_url' ), 400 );
		}

		// Generate MD5 Hash of the URL for the filename.
		$url_hash = md5( $target_url );

		// 3. Check File Cache (Hit).
		$cached_html = $this->read_cache( $post_id, $url_hash );

		if ( $cached_html ) {
			$cached_html = apply_filters( 'agewallet_api_content_response', $cached_html, $post_id, 'cache' );
			return new WP_REST_Response(
				array(
					'success' => true,
					'html'    => $cached_html,
					'source'  => 'cache',
				),
				200
			);
		}

		// 4. Cache Miss: Build Cache via Loopback.
		$html = $this->build_cache( $target_url, $post_id, $url_hash );

		if ( is_wp_error( $html ) ) {
			if ( class_exists( 'AgeWallet_Helpers' ) ) {
				AgeWallet_Helpers::instance()->log( 'API Build Cache Failed', array( 'error' => $html->get_error_message() ) );
			}
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'build_failed',
					'message' => $html->get_error_message(),
				),
				500
			);
		}

		$html = apply_filters( 'agewallet_api_content_response', $html, $post_id, 'fresh' );

		return new WP_REST_Response(
			array(
				'success' => true,
				'html'    => $html,
				'source'  => 'fresh',
			),
			200
		);
	}

	/**
	 * Securely checks if the user is verified.
	 * With a verification cookie, the agewallet_is_user_verified filter runs first; otherwise the
	 * shared check (AgeWallet_Helpers::visitor_is_verified(): HMAC signature verification and the
	 * agewallet_visitor_is_verified filter) decides.
	 */
	private function is_user_verified() {
		$cookie_name = defined( 'AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME' )
			? AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME
			: 'agewallet_verified';

		// Check standard override hook first
		if ( isset( $_COOKIE[ $cookie_name ] ) && apply_filters( 'agewallet_is_user_verified', false ) ) {
			return true;
		}

		return class_exists( 'AgeWallet_Helpers' ) && AgeWallet_Helpers::instance()->visitor_is_verified();
	}

	/**
	 * Reads HTML content from the file system.
	 * Looks in /singular/ or /archives/ based on the ID provided by Gatekeeper.
	 */
	private function read_cache( $post_id, $url_hash ) {
		$path = $this->get_cache_path( $post_id, $url_hash );
		if ( file_exists( $path ) ) {
			return file_get_contents( $path );
		}
		return false;
	}

	/**
	 * Helper to determine file path.
	 * If ID > 0, it's singular. If 0, it's an archive.
	 */
	private function get_cache_path( $post_id, $url_hash ) {
		if ( $post_id > 0 ) {
			return $this->cache_dir . 'singular/post-' . $post_id . '-' . $url_hash . '.html';
		} else {
			return $this->cache_dir . 'archives/' . $url_hash . '.html';
		}
	}

	/**
	 * Performs a loopback HTTP request to capture HTML.
	 */
	private function build_cache( $target_url, $post_id, $url_hash ) {
		// Add the secret bypass key to the URL.
		$url = add_query_arg( 'agewallet_cache_bypass', $this->bypass_secret, $target_url );

		$url = apply_filters( 'agewallet_loopback_url', $url, $post_id );

		$args = array(
			'timeout'   => 15,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is WordPress's documented core filter for the wp_remote_get sslverify arg; renaming would break WP convention.
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'cookies'   => array(), // Request as a public, unauthenticated user
		);
		$args = apply_filters( 'agewallet_loopback_request_args', $args, $post_id );

		$response = wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error( 'http_error', 'Loopback returned status ' . $response_code );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return new WP_Error( 'empty_response', 'Empty response body.' );
		}

		// Determine cache type from Header response (set by add_cache_type_headers)
		// Default to provided ID logic if header missing.
		$type_header = wp_remote_retrieve_header( $response, 'X-AW-Cache-Type' );

		// Logic: If Loopback says "archive", force ID to 0 to save in /archives/ folder.
		if ( 'archive' === $type_header ) {
			$post_id = 0;
		}

		$this->write_cache( $post_id, $url_hash, $html );

		return $html;
	}

	/**
	 * Writes HTML content to the file system.
	 */
	private function write_cache( $post_id, $url_hash, $html ) {
		$sub_dir  = ( $post_id > 0 ) ? 'singular/' : 'archives/';
		$full_dir = $this->cache_dir . $sub_dir;

		if ( ! is_dir( $full_dir ) ) {
			if ( ! wp_mkdir_p( $full_dir ) ) {
				return false;
			}
			// Security silence file
			file_put_contents( $full_dir . 'index.php', '<?php // Silence.' );
		}

		$file_path = $this->get_cache_path( $post_id, $url_hash );
		return (bool) file_put_contents( $file_path, $html );
	}

	/**
	 * Intercepts Loopback to set constants.
	 */
	public function handle_loopback_request() {
		// Cache-bypass key is an HMAC secret compared via hash_equals below; this is the auth
		// mechanism for loopback requests originating from our own build_cache() call. No nonce
		// is involved or needed — this is a server-to-server signed request, not a form submit.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$param_secret = isset( $_GET['agewallet_cache_bypass'] ) ? sanitize_text_field( wp_unslash( $_GET['agewallet_cache_bypass'] ) ) : '';

		if ( isset( $_GET['agewallet_cache_bypass'] ) && hash_equals( $this->bypass_secret, $param_secret ) ) {
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( ! defined( 'AGEWALLET_CACHE_BUILDING' ) ) {
				define( 'AGEWALLET_CACHE_BUILDING', true );
			}
			// Disable optimization plugins. DONOTROCKETOPTIMIZE and DONOTMINIFY are the
			// canonical opt-out constants defined by WP Rocket / minification plugins; renaming
			// them would defeat the purpose.
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			if ( ! defined( 'DONOTROCKETOPTIMIZE' ) ) define( 'DONOTROCKETOPTIMIZE', true );
			if ( ! defined( 'DONOTMINIFY' ) ) define( 'DONOTMINIFY', true );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
	}

	/**
	 * Adds a header to the loopback response indicating page type.
	 * Crucial for distinguishing Archives vs Singular during cache build.
	 */
	public function add_cache_type_headers( $headers ) {
		if ( defined( 'AGEWALLET_CACHE_BUILDING' ) && AGEWALLET_CACHE_BUILDING ) {
			if ( is_archive() || is_home() || is_search() ) {
				$headers['X-AW-Cache-Type'] = 'archive';
			} elseif ( is_singular() ) {
				$headers['X-AW-Cache-Type'] = 'singular';
			}
		}
		return $headers;
	}

	/**
	 * WP-Cron: Adds the custom interval based on settings.
	 */
	public function add_custom_cron_interval( $schedules ) {
		$interval = (int) get_option( 'agewallet_cache_ttl', 14400 );
		// Ensure min 2 hours (7200) to prevent overload
		if ( $interval < 7200 ) $interval = 7200;

		$schedules['agewallet_custom_interval'] = array(
			'interval' => $interval,
			/* translators: %d: Cron interval expressed in whole seconds (configured by the admin in the AgeWallet settings). */
			'display'  => sprintf( __( 'Every %d Seconds', 'agewallet-oidc-client' ), (int) $interval ),
		);
		return $schedules;
	}

	/**
	 * WP-Cron: Schedules or Reschedules the purge event.
	 * Checks if the interval matches the setting; if not, reschedules.
	 */
	private function schedule_cache_purge() {
		// If we have a scheduled event, we assume it's handled.
		if ( wp_next_scheduled( 'agewallet_scheduled_purge_event' ) ) {
			return;
		}

		// Schedule it
		wp_schedule_event( time(), 'agewallet_custom_interval', 'agewallet_scheduled_purge_event' );
	}

	/**
	 * WP-Cron: Handle setting change (Reschedule Immediately).
	 * Hooked to update_option_agewallet_cache_ttl.
	 */
	public function handle_ttl_change( $old_value, $new_value ) {
		if ( $old_value !== $new_value ) {
			if ( class_exists( 'AgeWallet_Helpers' ) ) {
				AgeWallet_Helpers::instance()->log( 'Cache TTL Changed. Rescheduling Cron.', array( 'old' => $old_value, 'new' => $new_value ) );
			}
			// Clear existing
			wp_clear_scheduled_hook( 'agewallet_scheduled_purge_event' );

			// Reschedule immediately (starts new cycle from NOW)
			wp_schedule_event( time(), 'agewallet_custom_interval', 'agewallet_scheduled_purge_event' );
		}
	}

	/**
	 * Purge the cache when a metadata-related setting changes so the new value
	 * takes effect on the next visitor.
	 *
	 * The signed md= URL is baked into each cached page at render time
	 * (see AgeWallet_Gating_Manager + AgeWallet_Metadata_Builder::build()), so
	 * without an immediate purge the previous metadata keeps shipping until the
	 * scheduled cron purge or a manual admin Purge Cache click.
	 *
	 * Hooked to update_option_agewallet_metadata_mode, update_option_agewallet_metadata_default,
	 * update_option_agewallet_auto_metadata_fields, and update_option_agewallet_wc_metadata_fields.
	 */
	public function handle_metadata_setting_change( $old_value, $new_value ) {
		if ( $old_value === $new_value ) {
			return;
		}
		if ( class_exists( 'AgeWallet_Helpers' ) ) {
			AgeWallet_Helpers::instance()->log( 'Metadata setting changed; auto-purging cache so the new value takes effect immediately.' );
		}
		$this->clear_all_cache();
	}

	/**
	 * Invalidation Logic: Post Save
	 */
	public function clear_post_cache( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( 'revision' === $post->post_type ) return;

		do_action( 'agewallet_before_cache_purge', 'post', $post_id );

		// 1. Delete Singular Cache for this Post (all variations)
		$singular_dir = $this->cache_dir . 'singular/';
		// Pattern: post-{id}-*.html
		$files = glob( $singular_dir . 'post-' . $post_id . '-*.html' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				wp_delete_file( $file );
			}
		}

		// 2. Delete ALL Archive Caches
		// Because we don't know which archives this post appears on.
		$archive_dir = $this->cache_dir . 'archives/';
		$archives    = glob( $archive_dir . '*.html' );
		if ( is_array( $archives ) ) {
			foreach ( $archives as $file ) {
				wp_delete_file( $file );
			}
		}

		do_action( 'agewallet_after_cache_purge', 'post', $post_id );
	}

	/**
	 * Invalidation Logic: Comments
	 * Triggered by comment_post, edit_comment, etc.
	 */
	public function clear_post_cache_from_comment( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment && $comment->comment_post_ID ) {
			$post = get_post( $comment->comment_post_ID );
			// Re-use clear_post_cache logic, passing params to match signature
			$this->clear_post_cache( $comment->comment_post_ID, $post, false );
		}
	}

	/**
	 * Nuke everything (Themes/Menus changed or Manual Purge).
	 */
	public function clear_all_cache() {
		do_action( 'agewallet_before_cache_purge', 'all', 0 );

		$count = 0;
		// Clear Singular
		$files = glob( $this->cache_dir . 'singular/*.html' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				wp_delete_file( $file );
				if ( ! file_exists( $file ) ) {
					$count++;
				}
			}
		}
		// Clear Archives
		$files = glob( $this->cache_dir . 'archives/*.html' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				wp_delete_file( $file );
				if ( ! file_exists( $file ) ) {
					$count++;
				}
			}
		}

		do_action( 'agewallet_after_cache_purge', 'all', $count );
		return $count;
	}

	public function __clone() { _doing_it_wrong( __FUNCTION__, esc_html__( 'Forbidden', 'agewallet-oidc-client' ), '1.1.0' ); }
	public function __wakeup() { _doing_it_wrong( __FUNCTION__, esc_html__( 'Forbidden', 'agewallet-oidc-client' ), '1.1.0' ); }
}