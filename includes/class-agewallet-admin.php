<?php
/**
 * AgeWallet Admin Class
 *
 * Handles the admin settings page for the AgeWallet OIDC Client plugin.
 * Uses the WordPress Settings API with a multi-page "Wizard" layout.
 * Implements the Singleton pattern.
 *
 * @package AgeWalletOIDCClient
 * @since   0.1.0
 */

// Prevent direct script access.
defined( 'ABSPATH' ) || exit;

class AgeWallet_Admin {

	/**
	 * The single instance of the class.
	 * @var AgeWallet_Admin|null
	 */
	private static $instance = null;

	/**
	 * Option Groups for granular saving.
	 */
	private $group_credentials = 'agewallet_options_credentials';
	private $group_guarding    = 'agewallet_options_guarding';
	private $group_appearance  = 'agewallet_options_appearance';
	private $group_scripts     = 'agewallet_options_scripts';
	private $group_debug       = 'agewallet_options_debug';

	/**
	 * Base Slug.
	 */
	private $base_slug = 'agewallet-welcome';

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_agewallet_purge_cache', array( $this, 'handle_purge_cache' ) );
		add_action( 'wp_ajax_agewallet_term_search', array( $this, 'handle_term_search' ) );
	}

	/**
	 * Register the new multi-page menu structure.
	 */
	public function add_admin_menu() {
		$cap = apply_filters( 'agewallet_admin_menu_capability', 'manage_options' );

		// 1. Main Menu Item -> Welcome Page
		add_menu_page(
			__( 'AgeWallet', 'agewallet-oidc-client' ),
			__( 'AgeWallet', 'agewallet-oidc-client' ),
			$cap,
			$this->base_slug,
			array( $this, 'render_welcome_page' ),
			'dashicons-shield-alt'
		);

		// 2. Welcome Submenu
		add_submenu_page(
			$this->base_slug,
			__( 'Welcome', 'agewallet-oidc-client' ),
			__( 'Welcome', 'agewallet-oidc-client' ),
			$cap,
			'agewallet-welcome',
			array( $this, 'render_welcome_page' )
		);

		// 3. Step 1: API Credentials
		add_submenu_page(
			$this->base_slug,
			__( 'API Credentials', 'agewallet-oidc-client' ),
			__( 'API Credentials', 'agewallet-oidc-client' ),
			$cap,
			'agewallet-credentials',
			array( $this, 'render_credentials_page' )
		);

		// 4. Step 2: Content Guarding
		add_submenu_page(
			$this->base_slug,
			__( 'Content Guarding', 'agewallet-oidc-client' ),
			__( 'Content Guarding', 'agewallet-oidc-client' ),
			$cap,
			'agewallet-guarding',
			array( $this, 'render_guarding_page' )
		);

		// 5. Step 3: Appearance
		add_submenu_page(
			$this->base_slug,
			__( 'Gate Appearance', 'agewallet-oidc-client' ),
			__( 'Gate Appearance', 'agewallet-oidc-client' ),
			$cap,
			'agewallet-appearance',
			array( $this, 'render_appearance_page' )
		);

		// 6. Step 4: Strict Mode Settings
		add_submenu_page(
			$this->base_slug,
			__( 'Strict Mode Settings', 'agewallet-oidc-client' ),
			__( 'Strict Mode Settings', 'agewallet-oidc-client' ),
			$cap,
			'agewallet-strict-mode',
			array( $this, 'render_scripts_page' )
		);

		// 7. Cache Control
		add_submenu_page(
			$this->base_slug,
			__( 'Cache Control', 'agewallet-oidc-client' ),
			__( 'Cache Control', 'agewallet-oidc-client' ),
			$cap,
			'agewallet-cache-control',
			array( $this, 'render_debug_page' )
		);

		// 8. Documentation Pages
		add_submenu_page( $this->base_slug, __( 'Usage Guide', 'agewallet-oidc-client' ), __( 'Usage Guide', 'agewallet-oidc-client' ), $cap, 'agewallet-usage', array( $this, 'render_usage_page' ) );
		add_submenu_page( $this->base_slug, __( 'CSS Guide', 'agewallet-oidc-client' ), __( 'CSS Guide', 'agewallet-oidc-client' ), $cap, 'agewallet-css-guide', array( $this, 'render_style_guide_page' ) );
		add_submenu_page( $this->base_slug, __( 'Developer Hooks', 'agewallet-oidc-client' ), __( 'Developer Hooks', 'agewallet-oidc-client' ), $cap, 'agewallet-hooks', array( $this, 'render_hooks_page' ) );
	}

	/**
	 * Registers settings sections and fields using the Settings API.
	 */
	public function register_settings() {

		// --- Group 1: Credentials ---
		register_setting( $this->group_credentials, AgeWalletOIDCClientPro::OPT_CLIENT_ID, array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
		register_setting( $this->group_credentials, AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
		register_setting( $this->group_credentials, AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT, array( 'sanitize_callback' => array( $this, 'sanitize_metadata' ), 'default' => '' ) );
		register_setting( $this->group_credentials, 'agewallet_metadata_mode', array( 'sanitize_callback' => array( $this, 'sanitize_metadata_mode' ), 'default' => AgeWallet_Metadata_Builder::MODE_STATIC ) );
		register_setting( $this->group_credentials, 'agewallet_auto_metadata_fields', array( 'sanitize_callback' => array( $this, 'sanitize_auto_metadata_fields' ), 'default' => array() ) );

		add_settings_section( 'agewallet_sec_creds', __( 'API Configuration', 'agewallet-oidc-client' ), '__return_false', 'agewallet-credentials' );
		add_settings_field( AgeWalletOIDCClientPro::OPT_CLIENT_ID, __( 'Client ID', 'agewallet-oidc-client' ), array( $this, 'render_text_input' ), 'agewallet-credentials', 'agewallet_sec_creds', array( 'label_for' => AgeWalletOIDCClientPro::OPT_CLIENT_ID, 'class' => 'regular-text' ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, __( 'Client Secret', 'agewallet-oidc-client' ), array( $this, 'render_text_input' ), 'agewallet-credentials', 'agewallet_sec_creds', array( 'label_for' => AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, 'class' => 'regular-text', 'type' => 'password' ) );
		add_settings_field( 'oidc_redirect_uri', __( 'Redirect URI', 'agewallet-oidc-client' ), array( $this, 'render_redirect_uri' ), 'agewallet-credentials', 'agewallet_sec_creds' );

		add_settings_section( 'agewallet_sec_metadata', __( 'Verification Metadata', 'agewallet-oidc-client' ), array( $this, 'render_metadata_section_description' ), 'agewallet-credentials' );
		add_settings_field( 'agewallet_metadata_mode', __( 'Metadata source', 'agewallet-oidc-client' ), array( $this, 'render_metadata_source_ui' ), 'agewallet-credentials', 'agewallet_sec_metadata' );

		// --- Group 2: Guarding ---
		register_setting( $this->group_guarding, 'agewallet_protection_mode', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'standard' ) );
		register_setting( $this->group_guarding, AgeWalletOIDCClientPro::OPT_BLOCK_MODE, array( 'sanitize_callback' => array( $this, 'sanitize_block_mode' ), 'default' => 'none' ) );
		register_setting( $this->group_guarding, AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, array( 'sanitize_callback' => array( $this, 'sanitize_paths_textarea' ), 'default' => '' ) );
		register_setting( $this->group_guarding, 'agewallet_excluded_paths', array( 'sanitize_callback' => array( $this, 'sanitize_paths_textarea' ), 'default' => '' ) );
		// NEW: Taxonomy Rules
		register_setting( $this->group_guarding, 'agewallet_taxonomy_rules', array( 'sanitize_callback' => array( $this, 'sanitize_taxonomy_rules' ), 'default' => array() ) );

		add_settings_section( 'agewallet_sec_guard', __( 'Protection Rules', 'agewallet-oidc-client' ), array( $this, 'render_guarding_section_description' ), 'agewallet-guarding' );
		add_settings_field( 'agewallet_protection_mode', __( 'Security Mode', 'agewallet-oidc-client' ), array( $this, 'render_protection_mode_radio' ), 'agewallet-guarding', 'agewallet_sec_guard' );
		add_settings_field( AgeWalletOIDCClientPro::OPT_BLOCK_MODE, __( 'Scope of Protection', 'agewallet-oidc-client' ), array( $this, 'render_radio_buttons' ), 'agewallet-guarding', 'agewallet_sec_guard', array( 'option_name' => AgeWalletOIDCClientPro::OPT_BLOCK_MODE, 'options' => array( 'none' => __( 'No automatic protection.', 'agewallet-oidc-client' ), 'all_but_home' => __( 'Protect entire site, except homepage.', 'agewallet-oidc-client' ), 'all' => __( 'Protect entire site, including homepage.', 'agewallet-oidc-client' ), 'specific' => __( 'Protect only specific URL paths.', 'agewallet-oidc-client' ) ) ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, __( 'Paths to Protect', 'agewallet-oidc-client' ), array( $this, 'render_textarea' ), 'agewallet-guarding', 'agewallet_sec_guard', array( 'label_for' => AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, 'class' => 'large-text', 'rows' => 5, 'placeholder' => '/shop/, /articles/premium-content/', 'desc' => __( 'Only used if Scope is "specific". Enter comma-separated paths.', 'agewallet-oidc-client' ) ) );
		add_settings_field( 'agewallet_excluded_paths', __( 'Paths to Exclude', 'agewallet-oidc-client' ), array( $this, 'render_simple_textarea' ), 'agewallet-guarding', 'agewallet_sec_guard', array( 'label_for' => 'agewallet_excluded_paths', 'class' => 'large-text', 'rows' => 3, 'placeholder' => '/privacy-policy/, /contact/', 'desc' => __( 'Exceptions to Global Protection. Any URL containing these paths will be visible.', 'agewallet-oidc-client' ) ) );
		// Taxonomy Rules UI
		add_settings_section( 'agewallet_sec_tax_rules', __( 'Taxonomy Rules', 'agewallet-oidc-client' ), '__return_false', 'agewallet-guarding' );
		add_settings_field( 'agewallet_taxonomy_rules', __( 'Configure Taxonomies', 'agewallet-oidc-client' ), array( $this, 'render_taxonomy_rules_ui' ), 'agewallet-guarding', 'agewallet_sec_tax_rules' );

		// WooCommerce Rules — only shown when WC is active.
		if ( class_exists( 'WooCommerce' ) ) {
			register_setting( $this->group_guarding, AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT, array( 'sanitize_callback' => array( $this, 'sanitize_wc_gate_mode' ), 'default' => AgeWalletOIDCClientPro::WC_GATE_MODE_OFF ) );
			register_setting( $this->group_guarding, AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS, array( 'sanitize_callback' => array( $this, 'sanitize_wc_metadata_fields' ), 'default' => array( 'cart_hash', 'cart_total', 'currency' ) ) );

			add_settings_section( 'agewallet_sec_wc', __( 'WooCommerce', 'agewallet-oidc-client' ), array( $this, 'render_wc_section_description' ), 'agewallet-guarding' );
			add_settings_field( AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT, __( 'Checkout gating', 'agewallet-oidc-client' ), array( $this, 'render_wc_gate_mode_radio' ), 'agewallet-guarding', 'agewallet_sec_wc', array( 'label_for' => AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT ) );
			add_settings_field( AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS, __( 'Checkout metadata fields', 'agewallet-oidc-client' ), array( $this, 'render_wc_metadata_fields_ui' ), 'agewallet-guarding', 'agewallet_sec_wc' );
		}

		// --- Group 3: Appearance ---
		register_setting( $this->group_appearance, AgeWalletOIDCClientPro::OPT_LOGO_ID, array( 'sanitize_callback' => array( $this, 'sanitize_positive_int' ), 'default' => 0 ) );
		register_setting( $this->group_appearance, AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, array( 'sanitize_callback' => array( $this, 'sanitize_positive_int' ), 'default' => 0 ) );
		register_setting( $this->group_appearance, AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG, array( 'sanitize_callback' => array( $this, 'sanitize_wysiwyg' ), 'default' => $this->get_default_copy() ) );
		register_setting( $this->group_appearance, AgeWalletOIDCClientPro::OPT_HIDE_HEADING, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => 0 ) );

		// Structured colour + radius options. Each maps to a CSS custom property
		// rendered into <style id="agewallet-vars"> on the gate; defaults match
		// the values in assets/css/gate.css :root.
		foreach ( self::appearance_color_defaults() as $opt => $default ) {
			register_setting( $this->group_appearance, $opt, array( 'sanitize_callback' => 'sanitize_hex_color', 'default' => $default ) );
		}
		register_setting( $this->group_appearance, 'agewallet_radius_card', array( 'sanitize_callback' => array( $this, 'sanitize_radius' ), 'default' => 16 ) );
		register_setting( $this->group_appearance, 'agewallet_radius_btn', array( 'sanitize_callback' => array( $this, 'sanitize_radius' ), 'default' => 12 ) );

		add_settings_section( 'agewallet_sec_app', __( 'Gate Styling', 'agewallet-oidc-client' ), '__return_false', 'agewallet-appearance' );
		add_settings_field( AgeWalletOIDCClientPro::OPT_LOGO_ID, __( 'Gate Logo', 'agewallet-oidc-client' ), array( $this, 'render_media_uploader' ), 'agewallet-appearance', 'agewallet_sec_app', array( 'option_name' => AgeWalletOIDCClientPro::OPT_LOGO_ID ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, __( 'Logo Width (px)', 'agewallet-oidc-client' ), array( $this, 'render_number_input' ), 'agewallet-appearance', 'agewallet_sec_app', array( 'label_for' => AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, 'class' => 'small-text', 'min' => 0, 'step' => 1, 'desc' => __( 'Leave 0 for natural width.', 'agewallet-oidc-client' ) ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG, __( 'Gate Copy', 'agewallet-oidc-client' ), array( $this, 'render_wysiwyg_editor' ), 'agewallet-appearance', 'agewallet_sec_app', array( 'option_name' => AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_HIDE_HEADING, __( 'Hide Default Heading', 'agewallet-oidc-client' ), array( $this, 'render_checkbox' ), 'agewallet-appearance', 'agewallet_sec_app', array( 'label_for' => AgeWalletOIDCClientPro::OPT_HIDE_HEADING, 'label' => __( 'Hide the "You Must Verify Your Age" heading.', 'agewallet-oidc-client' ) ) );

		add_settings_section( 'agewallet_sec_colors', __( 'Colours', 'agewallet-oidc-client' ), array( $this, 'render_colors_intro' ), 'agewallet-appearance' );
		foreach ( self::appearance_color_fields() as $opt => $label ) {
			add_settings_field( $opt, $label, array( $this, 'render_color_picker' ), 'agewallet-appearance', 'agewallet_sec_colors', array( 'option_name' => $opt ) );
		}
		add_settings_field( 'agewallet_radius_card', __( 'Card border radius (px)', 'agewallet-oidc-client' ), array( $this, 'render_number_input' ), 'agewallet-appearance', 'agewallet_sec_colors', array( 'label_for' => 'agewallet_radius_card', 'class' => 'small-text', 'min' => 0, 'max' => 32, 'step' => 1 ) );
		add_settings_field( 'agewallet_radius_btn', __( 'Button border radius (px)', 'agewallet-oidc-client' ), array( $this, 'render_number_input' ), 'agewallet-appearance', 'agewallet_sec_colors', array( 'label_for' => 'agewallet_radius_btn', 'class' => 'small-text', 'min' => 0, 'max' => 32, 'step' => 1 ) );

		// --- Group 4: Strict-mode analytics ---
		register_setting( $this->group_scripts, 'agewallet_ga4_id', array( 'sanitize_callback' => array( $this, 'sanitize_ga4_id' ), 'default' => '' ) );
		register_setting( $this->group_scripts, 'agewallet_gtm_id', array( 'sanitize_callback' => array( $this, 'sanitize_gtm_id' ), 'default' => '' ) );
		register_setting( $this->group_scripts, 'agewallet_fb_pixel_id', array( 'sanitize_callback' => array( $this, 'sanitize_fb_pixel_id' ), 'default' => '' ) );

		add_settings_section( 'agewallet_sec_scripts', __( 'Strict Mode Analytics', 'agewallet-oidc-client' ), array( $this, 'render_scripts_intro' ), 'agewallet-strict-mode' );
		add_settings_field( 'agewallet_ga4_id', __( 'Google Analytics 4 — Measurement ID', 'agewallet-oidc-client' ), array( $this, 'render_text_input' ), 'agewallet-strict-mode', 'agewallet_sec_scripts', array( 'label_for' => 'agewallet_ga4_id', 'placeholder' => 'G-XXXXXXXXXX', 'desc' => __( 'Renders the official gtag.js snippet on the strict-mode loading screen when set.', 'agewallet-oidc-client' ) ) );
		add_settings_field( 'agewallet_gtm_id', __( 'Google Tag Manager — Container ID', 'agewallet-oidc-client' ), array( $this, 'render_text_input' ), 'agewallet-strict-mode', 'agewallet_sec_scripts', array( 'label_for' => 'agewallet_gtm_id', 'placeholder' => 'GTM-XXXXXXX', 'desc' => __( 'Renders the official GTM snippet when set.', 'agewallet-oidc-client' ) ) );
		add_settings_field( 'agewallet_fb_pixel_id', __( 'Facebook Pixel — ID', 'agewallet-oidc-client' ), array( $this, 'render_text_input' ), 'agewallet-strict-mode', 'agewallet_sec_scripts', array( 'label_for' => 'agewallet_fb_pixel_id', 'placeholder' => '123456789012345', 'desc' => __( 'Numeric pixel ID. Renders the official fbevents.js snippet when set.', 'agewallet-oidc-client' ) ) );

		// --- Group 5: Cache Control ---
		register_setting( $this->group_debug, AgeWalletOIDCClientPro::OPT_DEBUG_MODE, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => 0 ) );
		// NEW: Cache TTL setting
		register_setting( $this->group_debug, 'agewallet_cache_ttl', array( 'sanitize_callback' => array( $this, 'sanitize_positive_int' ), 'default' => 14400 ) ); // Default 4 hours (14400s)

		// Section 1: Cache Management
		add_settings_section( 'agewallet_sec_cache', __( 'Cache Management', 'agewallet-oidc-client' ), array( $this, 'render_cache_section_description' ), 'agewallet-cache-control' );
		add_settings_field( 'agewallet_cache_ttl', __( 'Cache Auto-Clear Schedule', 'agewallet-oidc-client' ), array( $this, 'render_cache_ttl_dropdown' ), 'agewallet-cache-control', 'agewallet_sec_cache' );

		// Section 2: Developer Logging
		add_settings_section( 'agewallet_sec_logging', __( 'Developer Tools', 'agewallet-oidc-client' ), array( $this, 'render_logging_section_description' ), 'agewallet-cache-control' );
		add_settings_field(
			AgeWalletOIDCClientPro::OPT_DEBUG_MODE,
			__( 'Enable Logging', 'agewallet-oidc-client' ),
			array( $this, 'render_checkbox' ),
			'agewallet-cache-control',
			'agewallet_sec_logging',
			array(
				'label_for' => AgeWalletOIDCClientPro::OPT_DEBUG_MODE,
				'label'     => __( 'Enable plugin debug logging', 'agewallet-oidc-client' ),
				/* translators: %s: path to the WordPress debug log file. */
				'desc'      => sprintf( wp_kses( __( 'Writes to <code>%s</code>.', 'agewallet-oidc-client' ), array( 'code' => array() ) ), 'wp-content/debug.log' ),
			)
		);
	}

	// --- Page Renderer Wrapper (Unified Layout) ---

	/**
	 * Renders a prominent "Register for an AgeWallet account" notice when the
	 * Client ID or Client Secret hasn't been saved yet. Hidden once both are
	 * populated. Called from render_page_wrapper() so it appears at the top of
	 * every AgeWallet admin screen until credentials are configured.
	 *
	 * @since 1.5.6
	 */
	private function render_register_prompt_if_missing() {
		$client_id     = trim( (string) get_option( AgeWalletOIDCClientPro::OPT_CLIENT_ID, '' ) );
		$client_secret = trim( (string) get_option( AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, '' ) );
		if ( '' !== $client_id && '' !== $client_secret ) {
			return;
		}
		?>
		<div class="notice notice-warning" style="padding: 15px 20px; margin: 0 0 20px 0; border-left-width: 6px;">
			<h2 style="margin: 0 0 6px 0;"><?php esc_html_e( 'You need an AgeWallet account to use this plugin', 'agewallet-oidc-client' ); ?></h2>
			<p style="font-size: 14px; margin: 0 0 8px 0;">
				<?php esc_html_e( 'Register for a free AgeWallet account to get the Client ID and Client Secret you\'ll need to enter on the API Credentials screen.', 'agewallet-oidc-client' ); ?>
			</p>
			<p style="font-size: 14px; margin: 0 0 12px 0;">
				<strong><?php esc_html_e( 'New accounts get $5 of free verification credit to get you started.', 'agewallet-oidc-client' ); ?></strong>
			</p>
			<p style="margin: 0;">
				<a href="https://app.agewallet.io/register" target="_blank" rel="noopener noreferrer" class="button button-primary button-hero">
					<?php esc_html_e( 'Register at agewallet.io', 'agewallet-oidc-client' ); ?>
					<span class="dashicons dashicons-external" style="line-height: 1.5;"></span>
				</a>
			</p>
		</div>
		<?php
	}

	private function render_page_wrapper( $title, $callback, $option_group = null, $next_step = null ) {
		?>
		<div class="wrap">
			<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
				<h1 style="margin:0;"><?php echo esc_html( $title ); ?></h1>
				<a href="mailto:support@agewallet.com" class="button"><?php esc_html_e( 'Get Support', 'agewallet-oidc-client' ); ?></a>
			</div>
			<hr style="margin: 0 0 20px 0;">

			<?php $this->render_register_prompt_if_missing(); ?>

			<div style="display:flex; gap:20px; flex-wrap:wrap;">
				<div style="flex: 1; min-width: 300px;">
					<?php if ( $option_group ) : ?>
						<form method="post" action="options.php" id="agewallet-settings-form">
							<?php
							settings_fields( $option_group );
							// Admin page identifier from the WP-rendered menu link; not user-submitted form data, so no nonce applies.
							// phpcs:ignore WordPress.Security.NonceVerification.Recommended
							$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
							do_settings_sections( $current_page );
							submit_button( __( 'Save Changes', 'agewallet-oidc-client' ) );
							?>
						</form>

						<?php if ( $next_step ) : ?>
							<div style="margin-top: 20px; text-align: left;">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $next_step['slug'] ) ); ?>" class="button button-primary button-large aw-next-step-btn" style="display: inline-flex; align-items: center; gap: 6px;">
									<?php echo esc_html( $next_step['label'] ); ?>
                                    <span class="dashicons dashicons-arrow-right-alt2" style="line-height: 1;"></span>
                                </a>
                            </div>
						<?php endif; ?>

					<?php else : ?>
						<?php call_user_func( $callback ); ?>
					<?php endif; ?>
				</div>

				<div style="width: 280px; flex-shrink: 0;">
					<div class="postbox">
						<h2 class="hndle ui-sortable-handle" style="padding:10px 15px; margin:0;"><span><?php esc_html_e( 'Documentation', 'agewallet-oidc-client' ); ?></span></h2>
						<div class="inside">
							<ul style="margin:0; padding-left:15px; list-style:square;">
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-usage' ) ); ?>"><?php esc_html_e( 'Usage Guide', 'agewallet-oidc-client' ); ?></a></li>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-css-guide' ) ); ?>"><?php esc_html_e( 'CSS Guide', 'agewallet-oidc-client' ); ?></a></li>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-hooks' ) ); ?>"><?php esc_html_e( 'Developer Hooks', 'agewallet-oidc-client' ); ?></a></li>
							</ul>
							<hr>
							<?php if ( 'strict' === get_option( 'agewallet_protection_mode', 'standard' ) ) : ?>
								<p><strong><?php esc_html_e( 'Quick Action:', 'agewallet-oidc-client' ); ?></strong></p>
								<button type="button" class="button button-small agewallet-purge-btn" style="width:100%; margin-bottom:5px;">
									<?php esc_html_e( 'Purge Cache', 'agewallet-oidc-client' ); ?>
								</button>
								<span class="spinner agewallet-purge-spinner" style="float:none; margin:0;"></span>
								<span class="agewallet-purge-message" style="display:block; font-size:11px; line-height:1.2; margin-top:5px;"></span>
							<?php else : ?>
								<p style="font-size:12px; color:#666;"><?php esc_html_e( 'Cache purging is available when Strict Mode is enabled.', 'agewallet-oidc-client' ); ?></p>
							<?php endif; ?>
							<hr>
							<p style="font-size:12px; margin-top:10px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-cache-control' ) ); ?>"><?php esc_html_e( 'Go to Cache Control →', 'agewallet-oidc-client' ); ?></a></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// --- Specific Page Renderers ---

	public function render_welcome_page() {
		$this->render_page_wrapper( __( 'Welcome to AgeWallet', 'agewallet-oidc-client' ), function() {
            // Welcome - wizard start
			?>
			<div class="card" style="max-width:100%; padding:20px; margin-top:20px;">
				<h2><?php esc_html_e( "Let's get you set up!", 'agewallet-oidc-client' ); ?></h2>
				<ol style="font-size:1.1em; line-height:2; margin-left:20px;">
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-credentials' ) ); ?>"><strong><?php esc_html_e( 'Step 1: Set up your API Credentials', 'agewallet-oidc-client' ); ?></strong></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-guarding' ) ); ?>"><strong><?php esc_html_e( 'Step 2: Set up Content Guarding Rules', 'agewallet-oidc-client' ); ?></strong></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-appearance' ) ); ?>"><?php esc_html_e( 'Step 3: Customize Gate Appearance', 'agewallet-oidc-client' ); ?></a> <span class="description">(<?php esc_html_e( 'Optional', 'agewallet-oidc-client' ); ?>)</span></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-strict-mode' ) ); ?>"><?php esc_html_e( 'Step 4: Strict Mode Settings', 'agewallet-oidc-client' ); ?></a> <span class="description">(<?php esc_html_e( 'Strict Mode Only', 'agewallet-oidc-client' ); ?>)</span></li>
				</ol>
			</div>
			<?php
		});
	}

	public function render_credentials_page() {
		$next = array(
			'slug'  => 'agewallet-guarding',
			'label' => __( 'Next: Content Guarding', 'agewallet-oidc-client' ),
		);
		$this->render_page_wrapper( __( 'Step 1: API Credentials', 'agewallet-oidc-client' ), null, $this->group_credentials, $next );
	}

	public function render_guarding_page() {
		$next = array(
			'slug'  => 'agewallet-appearance',
			'label' => __( 'Next: Gate Appearance', 'agewallet-oidc-client' ),
		);
		$this->render_page_wrapper( __( 'Step 2: Content Guarding', 'agewallet-oidc-client' ), null, $this->group_guarding, $next );
	}

	public function render_appearance_page() {
		$next = array(
			'slug'  => 'agewallet-strict-mode',
			'label' => __( 'Next: Strict Mode Settings', 'agewallet-oidc-client' ),
		);
		$this->render_page_wrapper( __( 'Step 3: Gate Appearance', 'agewallet-oidc-client' ), null, $this->group_appearance, $next );
	}

	public function render_scripts_page() {
		$next = array(
			'slug'  => 'agewallet-welcome',
			'label' => __( 'Go to Dashboard', 'agewallet-oidc-client' ),
		);
		$this->render_page_wrapper( __( 'Step 4: Strict Mode Settings', 'agewallet-oidc-client' ), null, $this->group_scripts, $next );
	}

	public function render_debug_page() {
		$this->render_page_wrapper( __( 'Cache Control', 'agewallet-oidc-client' ), null, $this->group_debug );
	}

	// --- Documentation Renderers (Reusing Wrapper for Consistency) ---

	public function render_usage_page() {
		$this->render_page_wrapper( __( 'Usage Guide', 'agewallet-oidc-client' ), function() {
			$content = $this->get_doc_content( 'usage-guide.md' );
			if ( ! empty( $content ) ) {
				echo wp_kses_post( $this->parse_readme_markdown( $content ) );
			} else {
				$this->render_doc_error( 'usage-guide.md' );
			}
		});
	}

	public function render_hooks_page() {
		$this->render_page_wrapper( __( 'Developer Hooks Guide', 'agewallet-oidc-client' ), function() {
			$content = $this->get_doc_content( 'developer-hooks.md' );
			if ( ! empty( $content ) ) {
				echo wp_kses_post( $this->parse_readme_markdown( $content ) );
			} else {
				$this->render_doc_error( 'developer-hooks.md' );
			}
		});
	}

	public function render_style_guide_page() {
		$this->render_page_wrapper( __( 'CSS Customization Guide', 'agewallet-oidc-client' ), function() {
			$content = $this->get_doc_content( 'css-customization-guide.md' );
			if ( ! empty( $content ) ) {
				echo wp_kses_post( $this->parse_readme_markdown( $content ) );
			} else {
				$this->render_doc_error( 'css-customization-guide.md' );
			}
		});
	}

	private function render_doc_error( $filename ) {
		echo '<div class="notice notice-error"><p>';
		/* translators: %s: Name of the documentation file that could not be read (e.g., "developer-hooks.md"). */
		printf( esc_html__( 'Error: Could not read the documentation file "docs/%s".', 'agewallet-oidc-client' ), esc_html( $filename ) );
		echo '</p></div>';
	}

	// --- Field Rendering Callbacks ---

	public function render_guarding_section_description() {
		echo '<p>' . esc_html__( 'Choose how site content should be protected. Administrators and editors bypass the gate.', 'agewallet-oidc-client' ) . '</p>';
	}

	public function render_metadata_section_description() {
		echo '<p>' . esc_html__( 'Attach an opaque value to every AgeWallet verification triggered from this site. The value rides along with the OIDC flow and is stored alongside the verification record for later audit / reporting.', 'agewallet-oidc-client' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Developers: the `agewallet_metadata` filter has the final word on the encoded string. The `agewallet_auto_metadata` filter lets you add/remove keys from the auto-JSON bundle before encoding.', 'agewallet-oidc-client' ) . '</p>';
	}

	public function render_metadata_source_ui() {
		$mode = get_option( 'agewallet_metadata_mode', AgeWallet_Metadata_Builder::MODE_STATIC );
		$static_value = get_option( AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT, '' );
		$selected_fields = get_option( 'agewallet_auto_metadata_fields', array() );
		if ( ! is_array( $selected_fields ) ) {
			$selected_fields = array();
		}

		$field_groups = array(
			__( 'Post context (singular pages)', 'agewallet-oidc-client' ) => array(
				'fields' => array(
					'post_id'   => __( 'Post ID', 'agewallet-oidc-client' ),
					'post_slug' => __( 'Post slug', 'agewallet-oidc-client' ),
					'post_type' => __( 'Post type', 'agewallet-oidc-client' ),
				),
			),
			__( 'User context', 'agewallet-oidc-client' ) => array(
				'description' => __( 'These fields only populate when the visitor is signed in to a WordPress user account at click time. For anonymous visitors they are omitted from the JSON.', 'agewallet-oidc-client' ),
				'fields'      => array(
					'user_id'   => __( 'WordPress user ID', 'agewallet-oidc-client' ),
					'user_role' => __( 'Primary role', 'agewallet-oidc-client' ),
				),
			),
			__( 'Request context', 'agewallet-oidc-client' ) => array(
				'fields' => array(
					'request_path' => __( 'Request path', 'agewallet-oidc-client' ),
				),
			),
			__( 'Archive / search context', 'agewallet-oidc-client' ) => array(
				'fields' => array(
					'page_type'         => __( 'page_type (singular/category/tag/search/home/...)', 'agewallet-oidc-client' ),
					'term_id'           => __( 'term_id (category/tag/taxonomy archives)', 'agewallet-oidc-client' ),
					'term_slug'         => __( 'term_slug', 'agewallet-oidc-client' ),
					'term_taxonomy'     => __( 'term_taxonomy', 'agewallet-oidc-client' ),
					'search_query'      => __( 'search_query (on-site ?s= search)', 'agewallet-oidc-client' ),
					'archive_post_type' => __( 'archive_post_type (post-type archives)', 'agewallet-oidc-client' ),
				),
			),
		);

		echo '<fieldset class="aw-metadata-source">';

		echo '<div class="notice notice-info inline" style="margin:0 0 12px 0; padding:8px 12px;">';
		echo '<p style="margin:0;">' . esc_html__(
			'Changing any setting below will automatically purge AgeWallet\'s own cache so the new value takes effect on the next visitor.',
			'agewallet-oidc-client'
		) . '</p>';
		echo '<p style="margin:6px 0 0;">' . esc_html__(
			'If your site uses an external page cache (WP Engine, Cloudflare, W3 Total Cache, WP Rocket, LiteSpeed, etc.), you\'ll also need to purge that separately — the auto-purge above only clears AgeWallet\'s own cache.',
			'agewallet-oidc-client'
		) . '</p>';
		echo '</div>';

		// Mode radios
		$modes = array(
			AgeWallet_Metadata_Builder::MODE_OFF    => __( 'Off — no metadata attached', 'agewallet-oidc-client' ),
			AgeWallet_Metadata_Builder::MODE_STATIC => __( 'Static text', 'agewallet-oidc-client' ),
			AgeWallet_Metadata_Builder::MODE_AUTO   => __( 'Auto JSON of selected fields', 'agewallet-oidc-client' ),
		);
		foreach ( $modes as $value => $label ) {
			printf(
				'<label style="display:block; margin-bottom:6px;"><input type="radio" name="agewallet_metadata_mode" value="%1$s" %2$s class="aw-md-mode" /> %3$s</label>',
				esc_attr( $value ),
				checked( $value, $mode, false ),
				esc_html( $label )
			);
		}

		// Static-text sub-block
		echo '<div class="aw-md-block aw-md-block-static" style="margin-left:24px; margin-top:8px;' . ( AgeWallet_Metadata_Builder::MODE_STATIC === $mode ? '' : ' display:none;' ) . '">';
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
			esc_attr( AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT ),
			esc_attr( $static_value )
		);
		echo '<p class="description">' . esc_html__( 'Sent verbatim with every verification. Max 4096 bytes.', 'agewallet-oidc-client' ) . '</p>';
		echo '</div>';

		// Auto-JSON sub-block
		echo '<div class="aw-md-block aw-md-block-auto" style="margin-left:24px; margin-top:8px;' . ( AgeWallet_Metadata_Builder::MODE_AUTO === $mode ? '' : ' display:none;' ) . '">';
		foreach ( $field_groups as $group_label => $group_data ) {
			echo '<p style="margin:8px 0 4px; font-weight:600;">' . esc_html( $group_label ) . '</p>';
			if ( ! empty( $group_data['description'] ) ) {
				echo '<p class="description" style="margin:0 0 6px 0;">' . esc_html( $group_data['description'] ) . '</p>';
			}
			foreach ( $group_data['fields'] as $key => $label ) {
				$is_checked = in_array( $key, $selected_fields, true );
				printf(
					'<label style="display:block; margin-left:8px;"><input type="checkbox" name="agewallet_auto_metadata_fields[]" value="%1$s" %2$s/> %3$s</label>',
					esc_attr( $key ),
					checked( true, $is_checked, false ),
					esc_html( $label )
				);
			}
		}
		echo '<p class="description">' . esc_html__( 'Selected fields are JSON-encoded. Keys with no value on a given request (e.g., Post ID on a category page) are omitted automatically.', 'agewallet-oidc-client' ) . '</p>';
		echo '</div>';

		echo '</fieldset>';

		// The metadata-source toggle JS is enqueued via wp_add_inline_script on the
		// 'agewallet-admin-settings' handle (see enqueue_admin_scripts) — no inline <script> here.
	}

	public function render_wc_section_description() {
		echo '<p>' . esc_html__( 'Choose which products shoppers must verify their age to buy. Until they verify, those products can\'t be added to the cart, and their add-to-cart area, express payment buttons included, is replaced by a Verify my age button. Per-checkout context (cart hash, total, etc.) is attached as metadata to verifications that start from the checkout page.', 'agewallet-oidc-client' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Use the `agewallet_wc_checkout_metadata` filter to fully customise the metadata payload from code.', 'agewallet-oidc-client' ) . '</p>';
	}

	public function render_wc_metadata_fields_ui() {
		$option_name = AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS;
		$selected    = get_option( $option_name, array( 'cart_hash', 'cart_total', 'currency' ) );
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}
		$choices = array(
			'cart_hash'       => __( 'Cart hash (identifies the exact cart state)', 'agewallet-oidc-client' ),
			'cart_total'      => __( 'Cart total', 'agewallet-oidc-client' ),
			'currency'        => __( 'Currency code', 'agewallet-oidc-client' ),
			'customer_id'     => __( 'WordPress user ID', 'agewallet-oidc-client' ),
			'billing_country' => __( 'Billing country (if available)', 'agewallet-oidc-client' ),
			'line_item_count' => __( 'Number of line items', 'agewallet-oidc-client' ),
		);
		echo '<fieldset>';
		foreach ( $choices as $key => $label ) {
			$is_checked = in_array( $key, $selected, true );
			printf(
				'<label style="display:block; margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $option_name ),
				esc_attr( $key ),
				checked( true, $is_checked, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Fields included in the JSON metadata sent with each checkout verification.', 'agewallet-oidc-client' ) . '</p>';
	}

    // Cache Section Description (Contains the Purge Button)
    public function render_cache_section_description() {
        $style = 'border: 1px solid #c3c4c7; background: #f6f7f7; padding: 10px 15px; border-left-width: 4px; border-left-color: #00a32a; margin-bottom: 20px;';
        echo '<div style="' . esc_attr( $style ) . '">';
        echo '<h4 style="margin-top:0;">' . esc_html__( 'Manual Actions', 'agewallet-oidc-client' ) . '</h4>';
        echo '<p>' . esc_html__( 'Manually clear the HTML cache if you see stale content after updates.', 'agewallet-oidc-client' ) . '</p>';
		echo '<button type="button" class="button button-secondary agewallet-purge-btn">';
		echo '<span class="dashicons dashicons-trash" style="vertical-align:text-top; margin-right:4px;"></span>';
		echo esc_html__( 'Purge HTML Cache (Strict Mode)', 'agewallet-oidc-client' );
		echo '</button>';
		echo '<span class="spinner agewallet-purge-spinner" style="float:none; margin-left: 5px;"></span>';
		echo '<span class="agewallet-purge-message" style="margin-left: 10px; font-weight: 600;"></span>';
        echo '</div>';
    }

    // NEW: Logging Section Description (Contains the Warning)
	public function render_logging_section_description() {
		$style = 'border: 1px solid #c3c4c7; background: #fff8e5; padding: 10px 15px; border-left-width: 4px; border-left-color: #d63638; margin-top: 20px;';
		echo '<div style="' . esc_attr( $style ) . '">';
		echo '<h4 style="margin-top:0;"><span class="dashicons dashicons-warning" style="color:#d63638; vertical-align: middle; margin-right: 5px;"></span>' . esc_html__( 'Developer Options', 'agewallet-oidc-client' ) . '</h4>';
		/* translators: %s: path to the WordPress debug log file (e.g., wp-content/debug.log). */
		echo '<p style="margin-bottom:0;">' . sprintf( wp_kses( __( '<strong>Warning:</strong> For debugging only. This will write detailed plugin activity to <code>%s</code>.', 'agewallet-oidc-client' ), array( 'code' => array(), 'strong' => array() ) ), 'wp-content/debug.log' ) . '</p>';
		echo '</div>';
	}

	public function render_text_input( $args ) {
		$option_name = $args['label_for'];
		$value       = get_option( $option_name, '' );
		$type        = isset( $args['type'] ) ? $args['type'] : 'text';
		$class       = isset( $args['class'] ) ? $args['class'] : 'regular-text';
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		printf( '<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="%4$s" placeholder="%5$s" />', esc_attr( $type ), esc_attr( $option_name ), esc_attr( $value ), esc_attr( $class ), esc_attr( $placeholder ) );
		if ( isset( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	public function render_redirect_uri() {
		$redirect_uri = AgeWallet_Helpers::instance()->get_oidc_redirect_uri();
		printf( '<code>%s</code>', esc_html( $redirect_uri ) );
		echo '<p class="description">' . wp_kses( __( 'Add this exact Redirect URI to your AgeWallet application configuration. It must match <strong>exactly</strong>.', 'agewallet-oidc-client' ), array( 'strong' => array() ) ) . '</p>';
	}

	public function render_media_uploader( $args ) {
		$option_name = $args['option_name'];
		$logo_id     = get_option( $option_name, 0 );
		$logo_src    = $logo_id ? wp_get_attachment_image_url( (int) $logo_id, 'medium' ) : '';
		?>
		<div style="margin-bottom: 8px;">
			<img id="aw-logo-preview" src="<?php echo esc_url( $logo_src ?: '' ); ?>" alt="<?php esc_attr_e( 'Logo Preview', 'agewallet-oidc-client' ); ?>" style="max-height: 60px; height: auto; <?php echo $logo_src ? '' : 'display:none;'; ?> border: 1px solid #ddd; padding: 2px;">
		</div>
		<input type="hidden" id="aw-logo-id-input" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( $logo_id ); ?>">
		<button type="button" class="button" id="aw-logo-select-button"><?php esc_html_e( 'Select Image', 'agewallet-oidc-client' ); ?></button>
		<button type="button" class="button" id="aw-logo-remove-button" <?php echo $logo_id ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Remove Image', 'agewallet-oidc-client' ); ?></button>
		<p class="description"><?php esc_html_e( 'Optional. Choose a logo from the media library to display above the age verification gate.', 'agewallet-oidc-client' ); ?></p>
		<?php
	}

	public function render_number_input( $args ) {
		$option_name = $args['label_for'];
		$value       = get_option( $option_name, 0 );
		$class       = isset( $args['class'] ) ? $args['class'] : 'small-text';
		$min         = isset( $args['min'] ) ? $args['min'] : 0;
		$step        = isset( $args['step'] ) ? $args['step'] : 1;
		printf( '<input type="number" id="%1$s" name="%1$s" value="%2$s" class="%3$s" min="%4$s" step="%5$s" /> px', esc_attr( $option_name ), esc_attr( $value ), esc_attr( $class ), esc_attr( $min ), esc_attr( $step ) );
		if ( isset( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	public function render_wysiwyg_editor( $args ) {
		$option_name = $args['option_name'];
		$content     = get_option( $option_name, $this->get_default_copy() );
		$editor_id   = 'agewallet_wysiwyg_copy_editor';
		wp_editor(
			$content,
			$editor_id,
			array(
				'textarea_name' => $option_name,
				'textarea_rows' => 6,
				'media_buttons' => false,
				'tinymce'       => true,
				'quicktags'     => true,
			)
		);
		/* translators: %s: Plain-text default gate copy (HTML stripped) shown as the field's placeholder hint. */
		echo '<p class="description">' . sprintf( wp_kses( __( 'Customize the text shown on the age verification gate. HTML is allowed. Default: "%s"', 'agewallet-oidc-client' ), array() ), esc_html( $this->get_default_copy( false ) ) ) . '</p>';
	}

	public function render_checkbox( $args ) {
		$option_name = $args['label_for'];
		$checked     = get_option( $option_name, 0 );
		$label_text  = isset( $args['label'] ) ? $args['label'] : '';
		echo '<label for="' . esc_attr( $option_name ) . '">';
		printf( '<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s />', esc_attr( $option_name ), checked( 1, $checked, false ) );
		if ( $label_text ) {
			echo ' ' . esc_html( $label_text );
		}
		echo '</label>';
		if ( isset( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses( $args['desc'], array( 'code' => array() ) ) );
		}
	}

	public function render_wc_gate_mode_radio( $args ) {
		$option_name = $args['label_for'];
		$mode        = AgeWallet_WooCommerce::checkout_gating_mode();

		$modes = array(
			AgeWalletOIDCClientPro::WC_GATE_MODE_OFF              => array(
				'label' => __( 'Off — no purchase rule', 'agewallet-oidc-client' ),
				'desc'  => __( 'Products can be bought without verifying. Your page protection rules (scope, paths, taxonomies) still apply as normal.', 'agewallet-oidc-client' ),
			),
			AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS     => array(
				'label' => __( 'Force always — verify before buying any product', 'agewallet-oidc-client' ),
				'desc'  => __( 'Shoppers must verify their age before they can buy any product, and every visit to the checkout page requires verification.', 'agewallet-oidc-client' ),
			),
			AgeWalletOIDCClientPro::WC_GATE_MODE_CONDITIONAL_CART => array(
				'label' => __( 'Conditional on cart — verify before buying regulated products', 'agewallet-oidc-client' ),
				'desc'  => __( 'Flag products, categories, or tags as regulated (see Products → individual product or Products → Categories/Tags). Shoppers must verify their age before they can buy a regulated product: until they do, its add-to-cart area, express payment buttons included, is replaced by a Verify my age button.', 'agewallet-oidc-client' ),
			),
		);

		echo '<fieldset class="aw-wc-gate-mode">';
		foreach ( $modes as $value => $entry ) {
			printf(
				'<label style="display:block; margin-bottom:8px;"><input type="radio" name="%1$s" value="%2$s" %3$s /> <strong>%4$s</strong><br><span class="description" style="margin-left:24px;">%5$s</span></label>',
				esc_attr( $option_name ),
				esc_attr( $value ),
				checked( $value, $mode, false ),
				esc_html( $entry['label'] ),
				esc_html( $entry['desc'] )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Map of structured colour options → default hex.
	 * Defaults mirror the :root values in assets/css/gate.css so saving without
	 * changes produces no visual diff.
	 *
	 * @return array<string, string>
	 */
	public static function appearance_color_defaults() {
		return array(
			'agewallet_color_overlay_bg'    => '#0c0a14',
			'agewallet_color_card_bg'       => '#191029',
			'agewallet_color_card_border'   => '#221634',
			'agewallet_color_text'          => '#ffffff',
			'agewallet_color_muted'         => '#b8b8b8',
			'agewallet_color_btn_yes_bg'    => '#9d70c9',
			'agewallet_color_btn_yes_hover' => '#8b46cf',
			'agewallet_color_btn_no_bg'     => '#221634',
			'agewallet_color_btn_no_text'   => '#b8b8b8',
		);
	}

	/**
	 * Map of structured colour options → human label.
	 *
	 * @return array<string, string>
	 */
	public static function appearance_color_fields() {
		return array(
			'agewallet_color_overlay_bg'    => __( 'Overlay backdrop', 'agewallet-oidc-client' ),
			'agewallet_color_card_bg'       => __( 'Card background', 'agewallet-oidc-client' ),
			'agewallet_color_card_border'   => __( 'Card border', 'agewallet-oidc-client' ),
			'agewallet_color_text'          => __( 'Card text', 'agewallet-oidc-client' ),
			'agewallet_color_muted'         => __( 'Disclaimer text', 'agewallet-oidc-client' ),
			'agewallet_color_btn_yes_bg'    => __( 'Agree button background', 'agewallet-oidc-client' ),
			'agewallet_color_btn_yes_hover' => __( 'Agree button hover', 'agewallet-oidc-client' ),
			'agewallet_color_btn_no_bg'     => __( 'Disagree button background', 'agewallet-oidc-client' ),
			'agewallet_color_btn_no_text'   => __( 'Disagree button text', 'agewallet-oidc-client' ),
		);
	}

	public function render_colors_intro() {
		echo '<p class="description">' . wp_kses(
			__( 'For anything beyond these structured controls (custom fonts, site-wide rules, advanced selectors), use <strong>Appearance → Customize → Additional CSS</strong>. It applies to the gate on both Standard and Strict modes.', 'agewallet-oidc-client' ),
			array( 'strong' => array() )
		) . '</p>';
	}

	public function render_scripts_intro() {
		echo '<p class="description">' . esc_html__( 'In Strict mode the theme does not load, so the theme\'s analytics tags do not fire on the loading screen. Enter the IDs of the services below and AgeWallet renders their canonical snippets directly. Leave any field blank to skip it.', 'agewallet-oidc-client' ) . '</p>';
	}

	public function render_color_picker( $args ) {
		$option_name = $args['option_name'];
		$defaults    = self::appearance_color_defaults();
		$default     = isset( $defaults[ $option_name ] ) ? $defaults[ $option_name ] : '#ffffff';
		$value       = get_option( $option_name, $default );
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="agewallet-color-picker" data-default-color="%3$s" />',
			esc_attr( $option_name ),
			esc_attr( $value ),
			esc_attr( $default )
		);
	}

	public function render_radio_buttons( $args ) {
		$option_name = $args['option_name'];
		$options     = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		$current_val = get_option( $option_name, 'none' );
		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Protection Mode Options', 'agewallet-oidc-client' ) . '</span></legend>';
		foreach ( $options as $value => $label ) {
			$input_id = $option_name . '_' . $value;
			echo '<div style="margin-bottom: 5px;">';
			printf( '<input type="radio" id="%1$s" name="%2$s" value="%3$s" %4$s />', esc_attr( $input_id ), esc_attr( $option_name ), esc_attr( $value ), checked( $value, $current_val, false ) );
			echo ' <label for="' . esc_attr( $input_id ) . '">' . wp_kses_post( $label ) . '</label>';
			echo '</div>';
		}
		echo '</fieldset>';
		if ( isset( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	public function render_protection_mode_radio() {
		$option_name = 'agewallet_protection_mode';
		$current     = get_option( $option_name, 'standard' );
		?>
		<fieldset>
			<div style="margin-bottom: 8px;">
				<label>
					<input type="radio" name="<?php echo esc_attr( $option_name ); ?>" value="standard" <?php checked( $current, 'standard' ); ?>>
					<strong><?php esc_html_e( 'Standard (Overlay)', 'agewallet-oidc-client' ); ?></strong> -
					<span class="description"><?php esc_html_e( 'Uses CSS to hide content. Better for SEO/Bots, but less secure.', 'agewallet-oidc-client' ); ?></span>
				</label>
			</div>
			<div>
				<label>
					<input type="radio" name="<?php echo esc_attr( $option_name ); ?>" value="strict" <?php checked( $current, 'strict' ); ?>>
					<strong><?php esc_html_e( 'High Security (Strict Mode)', 'agewallet-oidc-client' ); ?></strong> -
					<span class="description"><?php esc_html_e( 'Prevents the content from loading until verification is complete. Compatible with "Cache Everything" (Cloudflare/Varnish).', 'agewallet-oidc-client' ); ?></span>
				</label>
				<br><small style="margin-left: 25px; color: #666;">
					<strong><?php esc_html_e( 'Warning:', 'agewallet-oidc-client' ); ?></strong>
					<?php esc_html_e( 'This mode uses JavaScript hydration. It is generally incompatible with Lazy Loading plugins.', 'agewallet-oidc-client' ); ?>
				</small>
			</div>
		</fieldset>
		<?php
	}

	public function render_textarea( $args ) {
		$option_name  = $args['label_for'];
		$value        = get_option( $option_name, '' );
		$class        = isset( $args['class'] ) ? $args['class'] : 'large-text';
		$rows         = isset( $args['rows'] ) ? absint( $args['rows'] ) : 5;
		$placeholder  = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		echo '<div id="agewallet-blocked-paths-wrapper">';
		printf( '<textarea id="%1$s" name="%1$s" class="%2$s" rows="%3$d" placeholder="%4$s">%5$s</textarea>', esc_attr( $option_name ), esc_attr( $class ), absint( $rows ), esc_attr( $placeholder ), esc_textarea( $value ) );
		if ( isset( $args['desc'] ) ) {
			echo '<p class="description">' . wp_kses( $args['desc'], array( 'code' => array() ) ) . '</p>';
		}
		echo '</div>';
	}

	public function render_simple_textarea( $args ) {
		$option_name = $args['label_for'];
		$value       = get_option( $option_name, '' );
		$class       = isset( $args['class'] ) ? $args['class'] : 'large-text';
		$rows        = isset( $args['rows'] ) ? absint( $args['rows'] ) : 3;
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';

		printf( '<textarea id="%1$s" name="%1$s" class="%2$s" rows="%3$d" placeholder="%4$s">%5$s</textarea>', esc_attr( $option_name ), esc_attr( $class ), absint( $rows ), esc_attr( $placeholder ), esc_textarea( $value ) );
		if ( isset( $args['desc'] ) ) {
			echo '<p class="description">' . wp_kses( $args['desc'], array( 'code' => array() ) ) . '</p>';
		}
	}

	// --- New Renderer: Taxonomy Rules UI (Multi-Input) ---
	public function render_taxonomy_rules_ui() {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$saved_rules = get_option( 'agewallet_taxonomy_rules', array() );

		// Badge / per-taxonomy input styles live in assets/css/admin.css, enqueued via
		// AgeWallet_Admin::enqueue_admin_assets() on AgeWallet settings pages.

		if ( empty( $taxonomies ) ) {
			echo '<p>' . esc_html__( 'No public taxonomies found.', 'agewallet-oidc-client' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Configure blocking rules for Categories, Tags, and Custom Taxonomies.', 'agewallet-oidc-client' ) . '</p>';
		echo '<table class="widefat striped" style="margin-top:10px; max-width: 100%;">';
		echo '<thead><tr>';
		echo '<th style="width: 25%;">' . esc_html__( 'Taxonomy', 'agewallet-oidc-client' ) . '</th>';
		echo '<th style="width: 20%;">' . esc_html__( 'Mode', 'agewallet-oidc-client' ) . '</th>';
		echo '<th>' . esc_html__( 'Specific Terms Configuration', 'agewallet-oidc-client' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $taxonomies as $tax_slug => $tax_obj ) {
			// Exclude post_format
			if ( 'post_format' === $tax_slug ) {
				continue;
			}

			$mode = isset( $saved_rules[ $tax_slug ]['mode'] ) ? $saved_rules[ $tax_slug ]['mode'] : 'ignore';

			// Saved IDs (Strings)
			$gate_ids_str = isset( $saved_rules[ $tax_slug ]['terms_gate'] ) ? $saved_rules[ $tax_slug ]['terms_gate'] : '';
			$exclude_ids_str = isset( $saved_rules[ $tax_slug ]['terms_exclude'] ) ? $saved_rules[ $tax_slug ]['terms_exclude'] : '';

			echo '<tr class="aw-tax-row" data-taxonomy="' . esc_attr( $tax_slug ) . '">';
			// Label
			echo '<td><strong>' . esc_html( $tax_obj->label ) . '</strong><br><small><code>' . esc_html( $tax_slug ) . '</code></small></td>';

			// Mode Dropdown
			echo '<td style="vertical-align: top; padding-top: 15px;">';
			echo '<select name="agewallet_taxonomy_rules[' . esc_attr( $tax_slug ) . '][mode]" class="aw-tax-mode">';
			echo '<option value="ignore" ' . selected( $mode, 'ignore', false ) . '>' . esc_html__( 'Ignore (Default)', 'agewallet-oidc-client' ) . '</option>';
			echo '<option value="gate_all" ' . selected( $mode, 'gate_all', false ) . '>' . esc_html__( 'Gate All Terms', 'agewallet-oidc-client' ) . '</option>';
			echo '<option value="exclude_all" ' . selected( $mode, 'exclude_all', false ) . '>' . esc_html__( 'Exclude All Terms', 'agewallet-oidc-client' ) . '</option>';
			echo '<option value="specific" ' . selected( $mode, 'specific', false ) . '>' . esc_html__( 'Specific Rules', 'agewallet-oidc-client' ) . '</option>';
			echo '</select>';
			echo '</td>';

			// Specific Inputs
			$display_style = ( 'specific' === $mode ) ? '' : 'display:none;';
			echo '<td>';
			echo '<div class="aw-tax-terms-wrap" style="' . esc_attr( $display_style ) . '">';

			// --- 1. Gate Terms Input ---
			echo '<div class="aw-input-group">';
			echo '<label>' . esc_html__( 'Gate these Terms:', 'agewallet-oidc-client' ) . '</label>';
			// Badges
			echo '<div class="aw-badges-container badges-gate">';
			if ( ! empty( $gate_ids_str ) ) {
				$term_ids = explode( ',', $gate_ids_str );
				foreach ( $term_ids as $term_id ) {
					$term = get_term( (int) $term_id, $tax_slug );
					if ( $term && ! is_wp_error( $term ) ) {
						echo '<span class="aw-term-badge type-gate" data-id="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '<span class="aw-remove-term dashicons dashicons-no-alt"></span></span>';
					}
				}
			}
			echo '</div>';
			// Hidden Storage
			echo '<input type="hidden" name="agewallet_taxonomy_rules[' . esc_attr( $tax_slug ) . '][terms_gate]" value="' . esc_attr( $gate_ids_str ) . '" class="aw-hidden-gate" />';
			// Search Input
			echo '<input type="text" class="large-text aw-term-search aw-search-gate" placeholder="' . esc_attr__( 'Search to gate...', 'agewallet-oidc-client' ) . '" />';
			echo '</div>'; // End Group

			// --- 2. Exclude Terms Input ---
			echo '<div class="aw-input-group">';
			echo '<label style="color: #005a1a;">' . esc_html__( 'Exclude these Terms (Overrides Gating):', 'agewallet-oidc-client' ) . '</label>';
			// Badges
			echo '<div class="aw-badges-container badges-exclude">';
			if ( ! empty( $exclude_ids_str ) ) {
				$term_ids = explode( ',', $exclude_ids_str );
				foreach ( $term_ids as $term_id ) {
					$term = get_term( (int) $term_id, $tax_slug );
					if ( $term && ! is_wp_error( $term ) ) {
						echo '<span class="aw-term-badge type-exclude" data-id="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '<span class="aw-remove-term dashicons dashicons-no-alt"></span></span>';
					}
				}
			}
			echo '</div>';
			// Hidden Storage
			echo '<input type="hidden" name="agewallet_taxonomy_rules[' . esc_attr( $tax_slug ) . '][terms_exclude]" value="' . esc_attr( $exclude_ids_str ) . '" class="aw-hidden-exclude" />';
			// Search Input
			echo '<input type="text" class="large-text aw-term-search aw-search-exclude" placeholder="' . esc_attr__( 'Search to exclude...', 'agewallet-oidc-client' ) . '" />';
			echo '</div>'; // End Group

			echo '</div>'; // End Wrapper
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// --- Renderer: Cache TTL Dropdown ---
	public function render_cache_ttl_dropdown() {
		$current = (int) get_option( 'agewallet_cache_ttl', 14400 );
		$options = array(
			7200   => __( 'Every 2 Hours', 'agewallet-oidc-client' ),
			14400  => __( 'Every 4 Hours', 'agewallet-oidc-client' ),
			21600  => __( 'Every 6 Hours', 'agewallet-oidc-client' ),
			28800  => __( 'Every 8 Hours', 'agewallet-oidc-client' ),
			36000  => __( 'Every 10 Hours', 'agewallet-oidc-client' ),
			43200  => __( 'Every 12 Hours', 'agewallet-oidc-client' ),
			86400  => __( 'Every 24 Hours', 'agewallet-oidc-client' ),
			172800 => __( 'Every 48 Hours', 'agewallet-oidc-client' ),
			259200 => __( 'Every 72 Hours', 'agewallet-oidc-client' ),
		);
		echo '<select name="agewallet_cache_ttl">';
		foreach ( $options as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Automatically clear the strict mode HTML cache on this schedule. Defaults to 4 hours.', 'agewallet-oidc-client' ) . '</p>';
	}

	// --- Sanitization Callbacks ---

	public function sanitize_positive_int( $input ) {
		return absint( $input );
	}

	public function sanitize_metadata( $input ) {
		$value = is_scalar( $input ) ? (string) $input : '';
		$value = wp_strip_all_tags( $value );
		if ( strlen( $value ) > AgeWalletOIDCClientPro::METADATA_MAX_BYTES ) {
			$value = substr( $value, 0, AgeWalletOIDCClientPro::METADATA_MAX_BYTES );
		}
		return $value;
	}

	public function sanitize_wc_metadata_fields( $input ) {
		$allowed = array( 'cart_hash', 'cart_total', 'currency', 'customer_id', 'billing_country', 'line_item_count' );
		if ( ! is_array( $input ) ) {
			return array();
		}
		return array_values( array_intersect( $allowed, array_map( 'sanitize_key', $input ) ) );
	}

	public function sanitize_metadata_mode( $input ) {
		$allowed = AgeWallet_Metadata_Builder::allowed_modes();
		return in_array( $input, $allowed, true ) ? $input : AgeWallet_Metadata_Builder::MODE_STATIC;
	}

	public function sanitize_auto_metadata_fields( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$allowed = AgeWallet_Metadata_Builder::allowed_auto_fields();
		return array_values( array_intersect( $allowed, array_map( 'sanitize_key', $input ) ) );
	}

	public function sanitize_wysiwyg( $input ) {
		return wp_kses_post( $input );
	}

	public function sanitize_radius( $input ) {
		// A blank field means "use the default radius" (let gate.css :root apply), NOT 0. Storing
		// '' keeps "unset" distinct from an explicit 0 (squared corners). A real value is clamped.
		if ( '' === trim( (string) $input ) ) {
			return '';
		}
		return min( 32, max( 0, absint( $input ) ) );
	}

	public function sanitize_ga4_id( $input ) {
		$value = strtoupper( trim( (string) $input ) );
		return preg_match( '/^G-[A-Z0-9]{4,}$/', $value ) ? $value : '';
	}

	public function sanitize_gtm_id( $input ) {
		$value = strtoupper( trim( (string) $input ) );
		return preg_match( '/^GTM-[A-Z0-9]{4,}$/', $value ) ? $value : '';
	}

	public function sanitize_fb_pixel_id( $input ) {
		$value = trim( (string) $input );
		return preg_match( '/^\d{6,}$/', $value ) ? $value : '';
	}

	public function sanitize_checkbox( $input ) {
		return ( isset( $input ) && '1' === $input ) ? 1 : 0;
	}

	public function sanitize_wc_gate_mode( $input ) {
		// Legacy normalization: integer/string 1 = old checkbox checked → force-always; 0/'' = unchecked → off.
		if ( 1 === $input || '1' === $input || true === $input ) {
			return AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS;
		}
		if ( 0 === $input || '0' === $input || '' === $input || null === $input ) {
			return AgeWalletOIDCClientPro::WC_GATE_MODE_OFF;
		}
		$allowed = array(
			AgeWalletOIDCClientPro::WC_GATE_MODE_OFF,
			AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS,
			AgeWalletOIDCClientPro::WC_GATE_MODE_CONDITIONAL_CART,
		);
		return in_array( $input, $allowed, true ) ? $input : AgeWalletOIDCClientPro::WC_GATE_MODE_OFF;
	}

	public function sanitize_block_mode( $input ) {
		$allowed_modes = array( 'none', 'all_but_home', 'all', 'specific' );
		if ( in_array( $input, $allowed_modes, true ) ) {
			return $input;
		}
		return 'none';
	}

	public function sanitize_paths_textarea( $input ) {
		if ( empty( trim( $input ) ) ) {
			return '';
		}
		$paths       = explode( ',', $input );
		$clean_paths = array();
		foreach ( $paths as $path ) {
			$path = trim( $path );
			if ( empty( $path ) ) {
				continue;
			}
			if ( strpos( $path, '/' ) !== 0 ) {
				$path = '/' . $path;
			}
			$sanitized_path = sanitize_text_field( $path );
			if ( ! empty( $sanitized_path ) && ( strlen( $sanitized_path ) > 1 || '/' === $sanitized_path ) ) {
				$clean_paths[] = $sanitized_path;
			}
		}
		$unique_paths = array_unique( $clean_paths );
		return implode( ',', $unique_paths );
	}

	// Sanitize Taxonomy Rules
	public function sanitize_taxonomy_rules( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$clean = array();
		$allowed_modes = array( 'ignore', 'gate_all', 'exclude_all', 'specific' );

		foreach ( $input as $tax => $data ) {
			$sanitized_tax = sanitize_key( $tax );
			$mode = isset( $data['mode'] ) && in_array( $data['mode'], $allowed_modes, true ) ? $data['mode'] : 'ignore';

			// Helper to clean ID lists
			$clean_ids = function( $str ) {
				if ( empty( $str ) ) return '';
				$ids = explode( ',', $str );
				$out = array();
				foreach ( $ids as $id ) {
					$out[] = absint( trim( $id ) );
				}
				return implode( ',', array_filter( $out ) );
			};

			$terms_gate = isset( $data['terms_gate'] ) ? $clean_ids( $data['terms_gate'] ) : '';
			$terms_exclude = isset( $data['terms_exclude'] ) ? $clean_ids( $data['terms_exclude'] ) : '';

			$clean[ $sanitized_tax ] = array(
				'mode'          => $mode,
				'terms_gate'    => $terms_gate,
				'terms_exclude' => $terms_exclude,
			);
		}
		return $clean;
	}

	// --- Documentation Parsing ---

	/**
	 * Read a bundled guide from docs/.
	 *
	 * wordpress.org folds every unrecognised readme section into "Other Notes",
	 * appends that to the Description, then trims the result to 2500 words — so
	 * large reference guides kept in readme.txt silently truncated the listing
	 * (and each other). They use readme markup, so parse_readme_markdown() renders
	 * them unchanged.
	 */
	private function get_doc_content( $filename ) {
		$doc_path = AGEWALLET_PLUGIN_DIR . 'docs/' . basename( $filename );
		if ( ! file_exists( $doc_path ) ) {
			return '';
		}
		$doc_content = file_get_contents( $doc_path );
		$doc_content = str_replace( array( "\r\n", "\r" ), "\n", $doc_content );
		if ( empty( $doc_content ) ) {
			return '';
		}
		return trim( $doc_content );
	}

	private function parse_readme_markdown( $content ) {
		$content = preg_replace( '/^=\s*(.*?)\s*=/m', '<h3>$1</h3>', $content );
		$content = preg_replace( '/^>(.*)/m', '<blockquote>$1</blockquote>', $content );
		$content = str_replace( "</blockquote>\n<blockquote>", "\n", $content );
		$content = preg_replace_callback( '/(^\*\s*.*(?:\n^\*\s*.*)*)/m', function( $matches ) {
			$items = preg_replace( '/^\*\s*(.*)/m', '<li>$1</li>', $matches[0] );
			return "<ul>\n" . $items . "\n</ul>\n";
		}, $content );
		$content = preg_replace_callback( '/(^(\d+\.)\s*.*(?:\n^\d+\.\s*.*)*)/m', function( $matches ) {
			$items = preg_replace( '/^(\d+\.)\s*(.*)/m', '<li>$2</li>', $matches[0] );
			return "<ol>\n" . $items . "\n</ol>\n";
		}, $content );
		$content = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $content );
		$content = preg_replace( '/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $content );
		$content = preg_replace( '/\*([^\*]+)\*/s', '<em>$1</em>', $content );
		$content = preg_replace( '/`(.*?)`/', '<code>$1</code>', $content );
		$content = preg_replace( '/`\n(.*?)\n`/s', '<pre><code>$1</code></pre>', $content );
		$blocks      = explode( "\n\n", $content );
		$html_blocks = array();
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( empty( $block ) ) {
				continue;
			}
			if ( ! preg_match( '/^<(h3|ul|ol|blockquote|pre)/', $block ) ) {
				$html_blocks[] = '<p>' . nl2br( $block ) . '</p>';
			} else {
				$html_blocks[] = $block;
			}
		}
		$content = implode( "\n\n", $html_blocks );
		return $content;
	}

	private function get_default_copy( $include_html = true ) {
		$partner_name = 'AgeWallet™';
		$partner_link = '<a href="https://www.agewallet.com" target="_blank" rel="noopener">' . $partner_name . '</a>';
		/* translators: %s: Verification partner name, either plain text "AgeWallet™" or an anchor link to agewallet.com (depending on caller). */
		$text_format  = __( 'You must be 18+ to view this content (or meet the minimum age required by your local jurisdiction). By selecting “I Agree,” you confirm that you meet the minimum age requirement and consent to verification by our partner, %s. If you do not meet the minimum age requirement or do not agree, please select “I Disagree.”', 'agewallet-oidc-client' );
		return sprintf( $text_format, $include_html ? $partner_link : $partner_name );
	}

	// --- AJAX Handlers ---

	public function handle_purge_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission Denied', 'agewallet-oidc-client' ) );
		}
		if ( class_exists( 'AgeWallet_API' ) ) {
			$count = AgeWallet_API::instance()->clear_all_cache();
			wp_send_json_success(
				/* translators: %d: Number of cached HTML files that were just deleted. */
				sprintf( __( 'Cache purged successfully! %d files deleted.', 'agewallet-oidc-client' ), (int) $count )
			);
		} else {
			wp_send_json_error( __( 'API Class not loaded.', 'agewallet-oidc-client' ) );
		}
	}

	// AJAX Handler for Term Search
	public function handle_term_search() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		// AJAX endpoint hit by the admin's term-search jQuery; capability check above
		// is the real gate. Query params here are search filters, not a form submission.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$taxonomy    = isset( $_GET['taxonomy'] ) ? sanitize_text_field( wp_unslash( $_GET['taxonomy'] ) ) : '';
		$term_search = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( empty( $taxonomy ) || empty( $term_search ) ) {
			wp_send_json_error( 'Missing parameters.' );
		}

		$terms = get_terms( array(
			'taxonomy' => $taxonomy,
			'name__like' => $term_search,
			'hide_empty' => false,
			'number' => 20
		) );

		if ( is_wp_error( $terms ) ) {
			wp_send_json_error( $terms->get_error_message() );
		}

		$results = array();
		foreach ( $terms as $term ) {
			$results[] = array(
				'id' => $term->term_id,
				'label' => $term->name . ' (ID: ' . $term->term_id . ')',
				'value' => $term->term_id
			);
		}

		wp_send_json_success( $results );
	}

	// --- Enqueue ---

	public function enqueue_admin_scripts( $hook_suffix ) {
		// Match any page that starts with agewallet-
		if ( strpos( $hook_suffix, 'agewallet-' ) === false ) {
			return;
		}

		// We enable the media uploader on the appearance page specifically, but general JS everywhere for the Purge button.
		wp_enqueue_media();
		// Enqueue jQuery UI Autocomplete for Taxonomy Rules
		wp_enqueue_script( 'jquery-ui-autocomplete' );

		// Plugin's own admin CSS (badges, next-step buttons).
		wp_enqueue_style( 'agewallet-admin', AGEWALLET_PLUGIN_URL . 'assets/css/admin.css', array(), AGEWALLET_VERSION );

		// Core colour picker for the Gate Appearance form.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function($){ $(".agewallet-color-picker").wpColorPicker(); });'
		);

		wp_enqueue_script(
			'agewallet-admin-settings',
			AGEWALLET_PLUGIN_URL . 'assets/js/admin-settings.js',
			array( 'jquery', 'wp-i18n', 'jquery-ui-autocomplete' ),
			AGEWALLET_VERSION,
			true
		);

		wp_localize_script(
			'agewallet-admin-settings',
			'agewalletAdminData',
			array(
				'mediaFrameTitle'       => __( 'Select or Upload Gate Logo', 'agewallet-oidc-client' ),
				'mediaFrameButton'      => __( 'Use this image', 'agewallet-oidc-client' ),
				'logoInputId'           => 'aw-logo-id-input',
				'logoPreviewId'         => 'aw-logo-preview',
				'selectButtonId'        => 'aw-logo-select-button',
				'removeButtonId'        => 'aw-logo-remove-button',
				'blockModeOptionName'   => AgeWalletOIDCClientPro::OPT_BLOCK_MODE,
				'blockedPathsWrapperId' => 'agewallet-blocked-paths-wrapper',
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
			)
		);

		// Metadata-source radio toggle (previously an inline <script> on the settings page;
		// moved here so admin screens carry no inline JS). Single-quoted JS inside a
		// double-quoted PHP string — no interpolation, no escaping needed.
		wp_add_inline_script(
			'agewallet-admin-settings',
			"(function(){var radios=document.querySelectorAll('input.aw-md-mode');function awMdToggle(){var v=document.querySelector('input.aw-md-mode:checked');v=v?v.value:'';document.querySelectorAll('.aw-md-block').forEach(function(el){el.style.display='none';});var t=document.querySelector('.aw-md-block-'+v);if(t){t.style.display='';}}radios.forEach(function(r){r.addEventListener('change',awMdToggle);});awMdToggle();})();"
		);
	}

	/** Cloning forbidden. @since 0.1.0 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'agewallet-oidc-client' ), '0.1.0' );
	}
	/** Unserializing forbidden. @since 0.1.0 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing forbidden.', 'agewallet-oidc-client' ), '0.1.0' );
	}

} // End class AgeWallet_Admin