<?php
/**
 * Plugin Name: AgeWallet OIDC Client
 * Description: Secure AgeWallet OIDC flow for WordPress using transients and client-side gating for cache compatibility.
 * Version:     1.5.8
 * Author:      AgeWallet LLC
 * Author URI:  https://agewallet.com
 * Text Domain: agewallet-oidc-client
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 11.2
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct script access.
defined( 'ABSPATH' ) || exit;

// Declare WooCommerce HPOS (High-Performance Order Storage) compatibility.
// Plugin's WC integration only reads cart and product data; it does not touch
// the orders table directly, so HPOS is safe.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

// Define essential plugin constants.
define( 'AGEWALLET_VERSION', '1.5.8' );
define( 'AGEWALLET_PLUGIN_FILE', __FILE__ );
define( 'AGEWALLET_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGEWALLET_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Module-level debug logger used by activate_plugin / deactivate_plugin static
 * hooks and the bootstrap code outside the main class scope. Routes through
 * AgeWallet_Helpers::log() when available so output is gated by the plugin's
 * own debug-mode option (see OPT_DEBUG_MODE); silently no-ops otherwise. This
 * keeps log calls out of direct error_log() use, which the WordPress Plugin
 * Check tool flags as production debug code.
 *
 * @param string $message Already-prefixed message (caller includes its own tag).
 */
function agewallet_debug_log( $message ) {
	if ( class_exists( 'AgeWallet_Helpers' ) && method_exists( AgeWallet_Helpers::instance(), 'log' ) ) {
		AgeWallet_Helpers::instance()->log( $message );
	}
}


/**
 * The main plugin class.
 *
 * Initializes the plugin, loads dependencies, defines constants,
 * and registers hooks. Implements the Singleton pattern.
 *
 * @since 0.1.0
 */
final class AgeWalletOIDCClientPro {

	/**
	 * Plugin version.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const VERSION = AGEWALLET_VERSION;

	// --- Constants (Option Keys) ---
	// API Credentials
	const OPT_CLIENT_ID     = 'agewallet_oidc_client_id';
	const OPT_CLIENT_SECRET = 'agewallet_oidc_client_secret';
	const OPT_HMAC_SECRET   = 'agewallet_hmac_secret'; // Needed for signing internal tokens/params

	// Gate Appearance Options
	const OPT_LOGO_ID       = 'agewallet_oidc_logo_id';
	const OPT_LOGO_WIDTH_PX = 'agewallet_oidc_logo_width_px';
	const OPT_COPY_WYSIWYG  = 'agewallet_oidc_copy_wysiwyg';
	const OPT_HIDE_HEADING  = 'agewallet_oidc_hide_heading';

	// Content Guarding Options
	const OPT_BLOCK_MODE    = 'agewallet_oidc_block_mode';
	const OPT_BLOCKED_PATHS = 'agewallet_oidc_blocked_paths';

	// Metadata Options
	const OPT_METADATA_DEFAULT      = 'agewallet_metadata_default';
	const OPT_WC_GATE_CHECKOUT      = 'agewallet_wc_gate_checkout';
	const OPT_WC_METADATA_FIELDS    = 'agewallet_wc_checkout_metadata_fields';

	// WC checkout-gate mode values (stored in OPT_WC_GATE_CHECKOUT)
	const WC_GATE_MODE_OFF              = 'off';
	const WC_GATE_MODE_FORCE_ALWAYS     = 'force-always';
	const WC_GATE_MODE_CONDITIONAL_CART = 'conditional-on-cart';

	// Metadata length cap (matches server-side acceptance limit)
	const METADATA_MAX_BYTES = 4096;

	// --- Debugging Option ---
	/**
	 * Option key for enabling the plugin's internal debug logging.
	 * @since 0.1.0
	 */
	const OPT_DEBUG_MODE = 'agewallet_oidc_debug_mode';

	// Internal Query Vars (for rewrite rules) - Ensure these match rewrite setup
	const QV_LAUNCH   = 'agewallet_launch';
	const QV_CALLBACK = 'agewallet_callback';
	const QV_SUCCESS  = 'agewallet_success'; // success/cookie-setting endpoint

	// AgeWallet API Endpoints (Using .io)
	const AUTH_ENDPOINT     = 'https://app.agewallet.io/user/authorize';
	const TOKEN_ENDPOINT    = 'https://app.agewallet.io/user/token';
	const USERINFO_ENDPOINT = 'https://app.agewallet.io/user/userinfo';
	// --- End Constants ---

	/**
	 * The single instance of the class.
	 *
	 * @since 0.1.0
	 * @var   AgeWalletOIDCClientPro|null
	 */
	private static $instance = null;

	/**
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 * Follows the Singleton pattern.
	 *
	 * @since  0.1.0
	 * @static
	 * @see    AGEWALLET()
	 * @return AgeWalletOIDCClientPro - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Private to prevent direct object creation. Sets up hooks and loads dependencies.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		// --- DEBUG LOGGING ---
		$this->log_debug( 'Main class __construct started.' );
		// --- END DEBUG LOGGING ---
		$this->define_constants(); // Define constants needed by other classes
		$this->load_dependencies();
		$this->setup_hooks();
		// --- DEBUG LOGGING ---
		$this->log_debug( 'Main class __construct finished.' );
		// --- END DEBUG LOGGING ---
	}

	/**
	 * Define any necessary dynamic constants.
	 * Currently empty, but can be used later if needed.
	 *
	 * @since 0.1.0
	 * @access private
	 */
	private function define_constants() {
		// Example: define('MY_CONSTANT', 'value');
	}

	/**
	 * Load required dependency files.
	 * Includes class files from the /includes directory.
	 * Logs errors if files are missing.
	 *
	 * @since 0.1.0
	 * @access private
	 */
	private function load_dependencies() {
		// --- DEBUG LOGGING ---
		$this->log_debug( 'load_dependencies started.' );
		// --- END DEBUG LOGGING ---

		$includes_path    = AGEWALLET_PLUGIN_DIR . 'includes/';
		$files_to_include = array(
			'class-agewallet-helpers.php',
			'class-agewallet-metadata-builder.php',
			'class-agewallet-admin.php',
			'class-agewallet-oidc-handler.php',
			'class-agewallet-gating-manager.php',
			'class-agewallet-api.php',
			'class-agewallet-product-flags.php',
		);

		// HOOK: Allow developers to add/remove/change dependency files.
		$files_to_include = apply_filters( 'agewallet_dependency_files', $files_to_include );

		foreach ( $files_to_include as $filename ) {
			$file_path = $includes_path . $filename;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
				// $this->log_debug("Loaded file: includes/{$filename}");
			} else {
				// Log a critical error if a required file is missing
				$this->log_debug( "ERROR: Failed to load required file: includes/{$filename}. Plugin functionality may be broken." );
				// Trigger a user warning or admin notice for missing files
				if ( is_admin() ) {
					add_action(
						'admin_notices',
						function () use ( $filename ) {
							echo '<div class="notice notice-error"><p>';
							printf(
								/* translators: %s: Name of the missing file. */
								esc_html__( 'AgeWallet Plugin Error: Required file "%s" is missing. Please reinstall the plugin.', 'agewallet-oidc-client' ),
								esc_html( "includes/{$filename}" )
							);
							echo '</p></div>';
						}
					);
				}
			}
		}

		// --- DEBUG LOGGING ---
		$this->log_debug( 'load_dependencies finished.' );
		// --- END DEBUG LOGGING ---
	}


	/**
	 * Register all necessary WordPress hooks.
	 * Sets up activation, deactivation, and core plugin hooks.
	 *
	 * @since 0.1.0
	 * @access private
	 */
	private function setup_hooks() {
		// --- DEBUG LOGGING ---
		$this->log_debug( 'setup_hooks started.' );
		// --- END DEBUG LOGGING ---
		// Activation and deactivation hooks. Use static methods.
		register_activation_hook( AGEWALLET_PLUGIN_FILE, array( __CLASS__, 'activate_plugin' ) );
		register_deactivation_hook( AGEWALLET_PLUGIN_FILE, array( __CLASS__, 'deactivate_plugin' ) );

		// Hook to initialize plugin components once WordPress and other plugins are loaded.
		add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );

		// --- DEBUG LOGGING ---
		$this->log_debug( 'setup_hooks finished.' );
		// --- END DEBUG LOGGING ---
	}

	/**
	 * Initialize plugin components.
	 * Instantiates core plugin classes after dependencies are loaded.
	 * Hooked to 'plugins_loaded'.
	 *
	 * @since 0.1.0
	 */
	public function init_plugin() {
		// --- DEBUG LOGGING ---
		$this->log_debug( 'init_plugin started (hooked on plugins_loaded).' );
		// --- END DEBUG LOGGING ---

		// Note: load_plugin_textdomain() is no longer called manually since WP 4.6 —
		// WordPress.org auto-loads translations under the plugin slug. Removed per
		// the Plugin Check tool's recommendation.

		// Instantiate helper class (ensure it's available early)
		if ( class_exists( 'AgeWallet_Helpers' ) ) {
			AgeWallet_Helpers::instance();
			$this->log_debug( 'AgeWallet_Helpers instantiated.' );
		} else {
			$this->log_missing_class( 'AgeWallet_Helpers' );
		}

		// Instantiate Admin class (registers admin hooks). Check is_admin() first.
		if ( is_admin() && class_exists( 'AgeWallet_Admin' ) ) {
			AgeWallet_Admin::instance();
			$this->log_debug( 'AgeWallet_Admin instantiated.' );
		} elseif ( is_admin() ) { // Only log missing admin class if in admin context
			$this->log_missing_class( 'AgeWallet_Admin' );
		}

		// Instantiate OIDC Handler class (registers OIDC endpoint hooks).
		if ( class_exists( 'AgeWallet_OIDC_Handler' ) ) {
			AgeWallet_OIDC_Handler::instance();
			$this->log_debug( 'AgeWallet_OIDC_Handler instantiated.' );
		} else {
			$this->log_missing_class( 'AgeWallet_OIDC_Handler' );
		}

		// Instantiate Gating Manager class (registers shortcode, front-end scripts/styles).
		if ( class_exists( 'AgeWallet_Gating_Manager' ) ) {
			AgeWallet_Gating_Manager::instance();
			$this->log_debug( 'AgeWallet_Gating_Manager instantiated.' );
		} else {
			$this->log_missing_class( 'AgeWallet_Gating_Manager' );
		}

		// Instantiate API class (registers secure content endpoint & file cache).
		if ( class_exists( 'AgeWallet_API' ) ) {
			AgeWallet_API::instance();
			$this->log_debug( 'AgeWallet_API instantiated.' );
		} else {
			$this->log_missing_class( 'AgeWallet_API' );
		}

		// Instantiate WooCommerce integration only when WC is active.
		if ( class_exists( 'WooCommerce' ) ) {
			$wc_file = AGEWALLET_PLUGIN_DIR . 'includes/class-agewallet-woocommerce.php';
			if ( ! class_exists( 'AgeWallet_WooCommerce' ) && file_exists( $wc_file ) ) {
				require_once $wc_file;
			}
			if ( class_exists( 'AgeWallet_WooCommerce' ) ) {
				AgeWallet_WooCommerce::instance();
				$this->log_debug( 'AgeWallet_WooCommerce instantiated.' );
			}
		}

		// HOOK: Signal that all AgeWallet components are loaded and ready.
		do_action( 'agewallet_initialized' );

		// --- DEBUG LOGGING ---
		$this->log_debug( 'init_plugin finished.' );
		// --- END DEBUG LOGGING ---
	}

	/**
	 * Plugin activation hook callback (static version).
	 * Ensures dependencies are loaded if possible, generates HMAC secret, and flushes rewrite rules.
	 *
	 * @since 0.1.0
	 * @static
	 */
	public static function activate_plugin() {
		// --- DEBUG LOGGING ---
		agewallet_debug_log( '[AgeWallet Plugin] Static activate_plugin hook fired.' );
		// --- END DEBUG LOGGING ---

		// Manually include helpers if needed for activation, as instance doesn't exist yet.
		$helpers_file = AGEWALLET_PLUGIN_DIR . 'includes/class-agewallet-helpers.php';
		if ( file_exists( $helpers_file ) ) {
			require_once $helpers_file;
			if ( class_exists( 'AgeWallet_Helpers' ) ) {
				AgeWallet_Helpers::instance()->ensure_hmac_secret(); // Ensure secret is generated
				agewallet_debug_log( '[AgeWallet Plugin] HMAC secret checked/generated during activation.' );
			} else {
				agewallet_debug_log( '[AgeWallet Plugin] ERROR during activation: AgeWallet_Helpers class not found in included file.' );
			}
		} else {
			agewallet_debug_log( '[AgeWallet Plugin] ERROR during activation: Helpers file not found at ' . $helpers_file );
		}

		// Manually include OIDC Handler to ensure rules are added before flush
		$handler_file = AGEWALLET_PLUGIN_DIR . 'includes/class-agewallet-oidc-handler.php';
		if ( file_exists( $handler_file ) ) {
			// We need helpers to be loaded first
			if ( ! class_exists( 'AgeWallet_Helpers' ) && file_exists( $helpers_file ) ) {
				require_once $helpers_file;
			}
			require_once $handler_file;
			if ( class_exists( 'AgeWallet_OIDC_Handler' ) ) {
				// Instantiate briefly just to call the rule registration (can't rely on init hook during activation)
				$handler_instance = AgeWallet_OIDC_Handler::instance(); // Get instance
				$handler_instance->register_rewrite_rules(); // Register rules in memory
				agewallet_debug_log( '[AgeWallet Plugin] Rewrite rules registered in memory during activation.' );
			} else {
				agewallet_debug_log( '[AgeWallet Plugin] ERROR during activation: AgeWallet_OIDC_Handler class not found.' );
			}
		} else {
			agewallet_debug_log( '[AgeWallet Plugin] ERROR during activation: OIDC Handler file not found.' );
		}

		flush_rewrite_rules(); // Save rules to the database/htaccess
		// --- DEBUG LOGGING ---
		agewallet_debug_log( '[AgeWallet Plugin] Rewrite rules flushed during activation.' );
		// --- END DEBUG LOGGING ---

		// HOOK: Allow other plugins to perform actions on activation.
		do_action( 'agewallet_activated' );
	}

	/**
	 * Plugin deactivation hook callback (static version).
	 * Flushes rewrite rules to remove custom endpoints.
	 *
	 * @since 0.1.0
	 * @static
	 */
	public static function deactivate_plugin() {
		// --- DEBUG LOGGING ---
		agewallet_debug_log( '[AgeWallet Plugin] Static deactivate_plugin hook fired.' );
		// --- END DEBUG LOGGING ---

		// Clear scheduled cron event
		wp_clear_scheduled_hook( 'agewallet_scheduled_purge_event' );

		flush_rewrite_rules(); // Remove/Save rules
		// --- DEBUG LOGGING ---
		agewallet_debug_log( '[AgeWallet Plugin] Rewrite rules flushed during deactivation.' );
		// --- END DEBUG LOGGING ---

		// HOOK: Allow other plugins to perform actions on deactivation.
		do_action( 'agewallet_deactivated' );
	}

	// --- Helper methods for logging (can be used by instance after __construct) ---

	/**
	 * Logs a debug message if logging is enabled.
	 * Prepends '[AgeWallet Plugin]' to the message. Uses instance context.
	 *
	 * @since 0.1.0
	 * @param string $message The message to log.
	 * @param mixed  $context Optional context data (array, object, string).
	 */
	private function log_debug( $message, $context = null ) {
		// Logic will be handled centrally in AgeWallet_Helpers::log()
		if ( class_exists( 'AgeWallet_Helpers' ) && method_exists( AgeWallet_Helpers::instance(), 'log' ) ) {
			AgeWallet_Helpers::instance()->log( '[Main] ' . $message, $context ); // Add context prefix
		}
	}

	/**
	 * Logs an error message when a required class is not found. Uses instance context.
	 * Also triggers an admin notice if in the admin area.
	 *
	 * @since 0.1.0
	 * @param string $class_name The name of the missing class.
	 * @param string $stage      Optional stage description (e.g., 'Activation', 'Init').
	 */
	private function log_missing_class( $class_name, $stage = 'Init' ) {
		$log_entry = sprintf( 'ERROR [%s]: Class "%s" not found. Check file inclusion.', $stage, $class_name );
		$this->log_debug( $log_entry ); // Use log_debug which will check settings

		// Trigger an admin notice as well
		if ( is_admin() ) {
			// Use a static variable to ensure the hook is added only once, even if called multiple times
			static $notice_added = false;
			if ( ! $notice_added ) {
				add_action(
					'admin_notices',
					function () use ( $class_name ) {
						echo '<div class="notice notice-error is-dismissible"><p>';
						printf(
							/* translators: %s: Name of the missing class file (guessed). */
							esc_html__( 'AgeWallet Plugin Error: A required file is missing or unloadable (%s). Please reinstall the plugin.', 'agewallet-oidc-client' ),
							esc_html( str_replace( '_', '-', strtolower( $class_name ) ) . '.php' ) // Guess filename
						);
						echo '</p></div>';
					}
				);
				$notice_added = true;
			}
		}
	}


	// --- Singleton Pattern Boilerplate ---
	/**
	 * Cloning is forbidden. Prevents duplication of the singleton instance.
	 * @since 0.1.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'agewallet-oidc-client' ), '0.1.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden. Prevents unserializing.
	 * @since 0.1.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing instances of this class is forbidden.', 'agewallet-oidc-client' ), '0.1.0' );
	}

} // End final class AgeWalletOIDCClientPro

/**
 * Begins execution of the plugin.
 * Retrieves the singleton instance of the main plugin class.
 *
 * @since 0.1.0
 * @return AgeWalletOIDCClientPro
 */
function AGEWALLET() {
	// --- DEBUG LOGGING ---
	// This initial log will run if WP_DEBUG_LOG is on, before our setting is checked.
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		agewallet_debug_log( '[AgeWallet Plugin] === AGEWALLET() function called ===' );
	}
	// --- END DEBUG LOGGING ---
	return AgeWalletOIDCClientPro::instance();
}

// Get the plugin running.
// --- DEBUG LOGGING ---
if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
	agewallet_debug_log( '[AgeWallet Plugin] Calling AGEWALLET() to instantiate plugin.' );
}
// --- END DEBUG LOGGING ---
AGEWALLET();

// --- DEBUG LOGGING ---
if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
	agewallet_debug_log( '[AgeWallet Plugin] Main plugin file finished loading.' );
}
// --- END DEBUG LOGGING ---