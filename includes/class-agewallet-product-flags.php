<?php
/**
 * Admin UI for the per-product Age verification choice and the category and tag flag that decide
 * which WC products need age verification to view or to buy.
 *
 * Adds:
 *  - An "AgeWallet" tab to the WC product data metabox with the Age verification radios
 *    (follow store rules / view and buy / buy only / never, plus Custom for saved values that
 *    match none of them) and a hint saying what the product does now.
 *  - A checkbox to the product category + product tag edit screens to mark whole taxonomy
 *    terms as needing verification to buy.
 *
 * Reads/writes go through the meta keys defined on AgeWallet_WooCommerce
 * (META_KEY_PRODUCT_REGULATED_STATUS and META_KEY_TERM_REGULATED) and AgeWallet_Gating_Manager
 * (META_KEY_FORCE_RESTRICT and META_KEY_FORCE_EXCLUDE).
 *
 * No-op on non-WC installs.
 *
 * @package AgeWalletOIDCClient
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'AgeWallet_Product_Flags' ) ) {

	final class AgeWallet_Product_Flags {

		private static $instance = null;

		/** The product edit form's field for the Age verification choice. */
		const FIELD_AGE_VERIFICATION = 'agewallet_age_verification';

		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			// Hooks register unconditionally — they're WC-specific by name and only fire when
			// WC actually invokes them. Guarding the registration on class_exists('WooCommerce')
			// would fail at file-load time (agewallet plugin loads before WC alphabetically),
			// so the constructor would bail before any hooks register.

			// Product data tab + panel + save.
			add_filter( 'woocommerce_product_data_tabs',   array( $this, 'add_product_data_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );

			// Category edit screens.
			add_action( 'product_cat_add_form_fields',  array( $this, 'render_cat_field_add' ) );
			add_action( 'product_cat_edit_form_fields', array( $this, 'render_cat_field_edit' ) );
			add_action( 'edited_product_cat',           array( $this, 'save_term_meta' ) );
			add_action( 'created_product_cat',          array( $this, 'save_term_meta' ) );

			// Tag edit screens.
			add_action( 'product_tag_add_form_fields',  array( $this, 'render_cat_field_add' ) );
			add_action( 'product_tag_edit_form_fields', array( $this, 'render_cat_field_edit' ) );
			add_action( 'edited_product_tag',           array( $this, 'save_term_meta' ) );
			add_action( 'created_product_tag',          array( $this, 'save_term_meta' ) );
		}

		/**
		 * WC product-data-tab filter: append the AgeWallet tab.
		 */
		public function add_product_data_tab( $tabs ) {
			$tabs['agewallet'] = array(
				'label'    => __( 'AgeWallet', 'agewallet-oidc-client' ),
				'target'   => 'agewallet_product_data',
				'class'    => array(),
				'priority' => 80,
			);
			return $tabs;
		}

		/**
		 * The four Age verification choices, in the order shown, each with the values it saves:
		 * the product's regulated status, and the page's force-restrict and force-exclude flags.
		 *
		 * @return array<string, array{label: string, desc: string, status: string, restrict: bool, exclude: bool}>
		 */
		public static function choices() {
			return array(
				'follow'       => array(
					'label'    => __( 'Follow store rules', 'agewallet-oidc-client' ),
					'desc'     => __( 'Viewing follows your Content Guarding rules. Buying follows your Checkout gating rule, including this product\'s categories and tags.', 'agewallet-oidc-client' ),
					'status'   => 'not_regulated',
					'restrict' => false,
					'exclude'  => false,
				),
				'view_and_buy' => array(
					'label'    => __( 'Require verification to view and buy', 'agewallet-oidc-client' ),
					'desc'     => __( 'The product page is gated, and shoppers must verify their age before buying.', 'agewallet-oidc-client' ),
					'status'   => 'regulated',
					'restrict' => true,
					'exclude'  => false,
				),
				'buy_only'     => array(
					'label'    => __( 'Require verification to buy only', 'agewallet-oidc-client' ),
					'desc'     => __( 'The page stays open. Shoppers must verify their age before buying.', 'agewallet-oidc-client' ),
					'status'   => 'regulated',
					'restrict' => false,
					'exclude'  => false,
				),
				'never'        => array(
					'label'    => __( 'Never require verification', 'agewallet-oidc-client' ),
					'desc'     => __( 'The page is open and shoppers can buy without verifying, whatever your rules say.', 'agewallet-oidc-client' ),
					'status'   => 'override_not_regulated',
					'restrict' => false,
					'exclude'  => true,
				),
			);
		}

		/**
		 * The choice a product's saved values amount to: a key of choices(), or 'custom' when the
		 * saved regulated status, force-restrict and force-exclude flags match none of them.
		 *
		 * @param int $post_id
		 * @return string
		 */
		public static function choice_for_product( $post_id ) {
			$saved = self::saved_values( $post_id );
			foreach ( self::choices() as $key => $choice ) {
				if ( $choice['status'] === $saved['status'] && $choice['restrict'] === $saved['restrict'] && $choice['exclude'] === $saved['exclude'] ) {
					return $key;
				}
			}
			return 'custom';
		}

		/**
		 * A product's saved values: its regulated status ('not_regulated' when nothing is saved)
		 * and whether the page's force-restrict and force-exclude flags are on.
		 *
		 * @param int $post_id
		 * @return array{status: string, restrict: bool, exclude: bool}
		 */
		private static function saved_values( $post_id ) {
			return array(
				'status'   => AgeWallet_WooCommerce::product_regulated_status( (int) $post_id ),
				'restrict' => '1' === get_post_meta( $post_id, AgeWallet_Gating_Manager::META_KEY_FORCE_RESTRICT, true ),
				'exclude'  => '1' === get_post_meta( $post_id, AgeWallet_Gating_Manager::META_KEY_FORCE_EXCLUDE, true ),
			);
		}

		/**
		 * The Custom choice's description: the saved values named, for a product whose values
		 * match no choice.
		 *
		 * @param int $post_id
		 * @return string
		 */
		private static function custom_description( $post_id ) {
			$saved = self::saved_values( $post_id );
			if ( $saved['restrict'] && $saved['exclude'] ) {
				$page = __( 'Require age verification and Exclude from age verification both on', 'agewallet-oidc-client' );
			} elseif ( $saved['restrict'] ) {
				$page = __( 'Require age verification on', 'agewallet-oidc-client' );
			} elseif ( $saved['exclude'] ) {
				$page = __( 'Exclude from age verification on', 'agewallet-oidc-client' );
			} else {
				$page = __( 'no page setting', 'agewallet-oidc-client' );
			}
			if ( 'regulated' === $saved['status'] ) {
				$buying = __( 'shoppers verify to buy', 'agewallet-oidc-client' );
			} elseif ( 'override_not_regulated' === $saved['status'] ) {
				$buying = __( 'shoppers never verify to buy', 'agewallet-oidc-client' );
			} else {
				$buying = __( 'buying inherits categories and tags', 'agewallet-oidc-client' );
			}
			/* translators: 1: the page setting, 2: the buying setting */
			return sprintf( __( 'Saved settings: %1$s; %2$s. Pick a choice above to replace them.', 'agewallet-oidc-client' ), $page, $buying );
		}

		/**
		 * The hint's two lines for a product: whether its page is gated by the page protection
		 * rules (AgeWallet_Gating_Manager::should_restrict_content() for the product), and whether
		 * shoppers verify before buying it (AgeWallet_WooCommerce::product_requires_verification()
		 * and the Checkout gating rule).
		 *
		 * @param int $post_id
		 * @return string[]
		 */
		private static function hint_lines( $post_id ) {
			$gated = class_exists( 'AgeWallet_Gating_Manager' ) && AgeWallet_Gating_Manager::instance()->should_restrict_content( (int) $post_id, false );
			$lines = array(
				$gated
					? __( 'This page is gated by your Content Guarding rules.', 'agewallet-oidc-client' )
					: __( 'This page is open.', 'agewallet-oidc-client' ),
			);
			if ( ! AgeWallet_WooCommerce::checkout_gating_enabled() ) {
				$lines[] = __( 'Checkout gating is off, so shoppers don\'t verify to buy.', 'agewallet-oidc-client' );
			} elseif ( function_exists( 'wc_get_product' ) && AgeWallet_WooCommerce::product_requires_verification( wc_get_product( $post_id ) ) ) {
				$lines[] = __( 'Shoppers verify before buying this product.', 'agewallet-oidc-client' );
			} else {
				$lines[] = __( 'Shoppers can buy this product without verifying.', 'agewallet-oidc-client' );
			}
			return $lines;
		}

		/**
		 * WC product-data-panel action: render the Age verification radios and the hint.
		 */
		public function render_product_data_panel() {
			global $post;
			$post_id = $post ? (int) $post->ID : 0;
			$current = $post_id ? self::choice_for_product( $post_id ) : 'follow';

			$options = array();
			foreach ( self::choices() as $key => $choice ) {
				$options[ $key ] = array( 'label' => $choice['label'], 'desc' => $choice['desc'] );
			}
			if ( 'custom' === $current ) {
				$options['custom'] = array(
					'label' => __( 'Custom', 'agewallet-oidc-client' ),
					'desc'  => self::custom_description( $post_id ),
				);
			}
			$hint = $post_id ? self::hint_lines( $post_id ) : array();
			?>
			<div id="agewallet_product_data" class="panel woocommerce_options_panel hidden">
				<div class="options_group">
					<p class="form-field">
						<label><?php esc_html_e( 'Age verification', 'agewallet-oidc-client' ); ?></label>
						<span class="description" style="display:block; margin-left:0;">
							<?php esc_html_e( 'Who can view this product\'s page, and who can buy it.', 'agewallet-oidc-client' ); ?>
						</span>
					</p>
					<?php foreach ( $options as $value => $entry ) : ?>
						<p class="form-field" style="margin-left:160px;">
							<label style="display:block;">
								<input type="radio" name="<?php echo esc_attr( self::FIELD_AGE_VERIFICATION ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $current ); ?> />
								<strong><?php echo esc_html( $entry['label'] ); ?></strong>
								<br>
								<span class="description" style="margin-left:24px;"><?php echo esc_html( $entry['desc'] ); ?></span>
							</label>
						</p>
					<?php endforeach; ?>
					<?php if ( $hint ) : ?>
						<p class="form-field agewallet-age-verification-hint" style="margin-left:160px;">
							<span class="description" style="margin-left:0;"><?php echo esc_html( implode( ' ', $hint ) ); ?></span>
						</p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * WC product save action: persist the Age verification choice as the product's regulated
		 * status and the page's force-restrict and force-exclude flags. Custom leaves the saved
		 * values as they are.
		 * WC has already verified the standard post-edit nonce + capability before this fires.
		 */
		public function save_product_meta( $post_id ) {
			// Hooks into WooCommerce's woocommerce_process_product_meta, which fires inside
			// the core product-save flow after WC has already verified the standard product-edit
			// nonce and the user's edit_product capability. No separate nonce required here.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST[ self::FIELD_AGE_VERIFICATION ] ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$input   = sanitize_key( wp_unslash( $_POST[ self::FIELD_AGE_VERIFICATION ] ) );
			$choices = self::choices();
			if ( ! isset( $choices[ $input ] ) ) {
				return;
			}
			$choice = $choices[ $input ];
			update_post_meta( $post_id, AgeWallet_WooCommerce::META_KEY_PRODUCT_REGULATED_STATUS, $choice['status'] );
			if ( $choice['restrict'] ) {
				update_post_meta( $post_id, AgeWallet_Gating_Manager::META_KEY_FORCE_RESTRICT, '1' );
			} else {
				delete_post_meta( $post_id, AgeWallet_Gating_Manager::META_KEY_FORCE_RESTRICT );
			}
			if ( $choice['exclude'] ) {
				update_post_meta( $post_id, AgeWallet_Gating_Manager::META_KEY_FORCE_EXCLUDE, '1' );
			} else {
				delete_post_meta( $post_id, AgeWallet_Gating_Manager::META_KEY_FORCE_EXCLUDE );
			}
		}

		/** The category and tag checkbox's label. */
		private static function term_label() {
			return __( 'Require age verification to buy (AgeWallet)', 'agewallet-oidc-client' );
		}

		/** The category and tag checkbox's description. */
		private static function term_description() {
			return __( 'Shoppers must verify their age before buying any product with this category or tag, when Checkout gating is set to Only the products you flag. A product\'s own Age verification choice overrides this. Clear your page cache after changing it.', 'agewallet-oidc-client' );
		}

		/**
		 * Category/tag "Add new" form field.
		 */
		public function render_cat_field_add() {
			?>
			<div class="form-field">
				<label for="agewallet_regulated">
					<input type="checkbox" name="<?php echo esc_attr( AgeWallet_WooCommerce::META_KEY_TERM_REGULATED ); ?>" id="agewallet_regulated" value="1" />
					<?php echo esc_html( self::term_label() ); ?>
				</label>
				<p><?php echo esc_html( self::term_description() ); ?></p>
			</div>
			<?php
		}

		/**
		 * Category/tag edit form field.
		 */
		public function render_cat_field_edit( $term ) {
			$checked = get_term_meta( $term->term_id, AgeWallet_WooCommerce::META_KEY_TERM_REGULATED, true );
			?>
			<tr class="form-field">
				<th scope="row" valign="top">
					<label for="agewallet_regulated">
						<?php echo esc_html( self::term_label() ); ?>
					</label>
				</th>
				<td>
					<input type="checkbox" name="<?php echo esc_attr( AgeWallet_WooCommerce::META_KEY_TERM_REGULATED ); ?>" id="agewallet_regulated" value="1" <?php checked( '1', $checked ); ?> />
					<p class="description"><?php echo esc_html( self::term_description() ); ?></p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Term save handler. WP has already verified the term-edit nonce before this fires.
		 */
		public function save_term_meta( $term_id ) {
			$key   = AgeWallet_WooCommerce::META_KEY_TERM_REGULATED;
			// Hooks into WP core's edited_{taxonomy} / create_{taxonomy} actions, which fire after
			// core has verified the taxonomy-edit nonce and capability. No separate nonce required.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$value = isset( $_POST[ $key ] ) && '1' === $_POST[ $key ] ? '1' : '';
			if ( '1' === $value ) {
				update_term_meta( $term_id, $key, '1' );
			} else {
				delete_term_meta( $term_id, $key );
			}
		}
	}

	AgeWallet_Product_Flags::instance();
}
