<?php
/**
 * Admin UI for the regulated-status flags that decide which WC products need age verification to buy
 * conditional-on-cart mode.
 *
 * Adds:
 *  - A "AgeWallet" tab to the WC product data metabox with a 3-state radio
 *    (not regulated / regulated / override).
 *  - A checkbox to the product category + product tag edit screens to mark
 *    whole taxonomy terms as regulated.
 *
 * Reads/writes go through the meta keys defined on AgeWallet_WooCommerce
 * (META_KEY_PRODUCT_REGULATED_STATUS and META_KEY_TERM_REGULATED).
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
		 * WC product-data-panel action: render the regulated-status radio panel.
		 */
		public function render_product_data_panel() {
			global $post;
			$status = $post ? get_post_meta( $post->ID, AgeWallet_WooCommerce::META_KEY_PRODUCT_REGULATED_STATUS, true ) : '';
			if ( '' === $status ) {
				$status = AgeWallet_WooCommerce::PRODUCT_REGULATED_STATUS_DEFAULT;
			}

			$options = array(
				'not_regulated'          => array(
					'label' => __( 'Use categories and tags', 'agewallet-oidc-client' ),
					'desc'  => __( 'Shoppers must verify their age to buy this product only when one of its categories or tags is marked Regulated.', 'agewallet-oidc-client' ),
				),
				'regulated'              => array(
					'label' => __( 'Regulated', 'agewallet-oidc-client' ),
					'desc'  => __( 'When checkout gating is set to "Conditional on cart", shoppers must verify their age before they can buy this product. Until they do, its add-to-cart area, express payment buttons included, is replaced by a Verify my age button.', 'agewallet-oidc-client' ),
				),
				'override_not_regulated' => array(
					'label' => __( 'Override — explicitly not regulated', 'agewallet-oidc-client' ),
					'desc'  => __( 'Forces this product to be treated as unregulated even if one of its categories or tags is flagged as regulated. Only useful for edge cases where an otherwise-regulated category contains a specific product that should not trigger the gate.', 'agewallet-oidc-client' ),
				),
			);
			?>
			<div id="agewallet_product_data" class="panel woocommerce_options_panel hidden">
				<div class="options_group">
					<p class="form-field">
						<label><?php esc_html_e( 'Regulated status', 'agewallet-oidc-client' ); ?></label>
						<span class="description" style="display:block; margin-left:0;">
							<?php esc_html_e( 'Controls whether shoppers must verify their age before they can buy this product, when checkout gating is set to "Conditional on cart".', 'agewallet-oidc-client' ); ?>
							<?php esc_html_e( 'This controls buying only. Who can view the product\'s page is set in the Age Restriction box in the sidebar.', 'agewallet-oidc-client' ); ?>
						</span>
					</p>
					<?php foreach ( $options as $value => $entry ) : ?>
						<p class="form-field" style="margin-left:160px;">
							<label style="display:block;">
								<input type="radio" name="<?php echo esc_attr( AgeWallet_WooCommerce::META_KEY_PRODUCT_REGULATED_STATUS ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $status ); ?> />
								<strong><?php echo esc_html( $entry['label'] ); ?></strong>
								<br>
								<span class="description" style="margin-left:24px;"><?php echo esc_html( $entry['desc'] ); ?></span>
							</label>
						</p>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * WC product save action: persist the regulated-status meta.
		 * WC has already verified the standard post-edit nonce + capability before this fires.
		 */
		public function save_product_meta( $post_id ) {
			$key = AgeWallet_WooCommerce::META_KEY_PRODUCT_REGULATED_STATUS;
			// Hooks into WooCommerce's woocommerce_admin_process_product_object, which fires inside
			// the core product-save flow after WC has already verified the standard product-edit
			// nonce and the user's edit_product capability. No separate nonce required here.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST[ $key ] ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$input   = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			$allowed = array( 'not_regulated', 'regulated', 'override_not_regulated' );
			$value   = in_array( $input, $allowed, true ) ? $input : 'not_regulated';
			update_post_meta( $post_id, $key, $value );
		}

		/**
		 * Category/tag "Add new" form field.
		 */
		public function render_cat_field_add() {
			?>
			<div class="form-field">
				<label for="agewallet_regulated">
					<input type="checkbox" name="<?php echo esc_attr( AgeWallet_WooCommerce::META_KEY_TERM_REGULATED ); ?>" id="agewallet_regulated" value="1" />
					<?php esc_html_e( 'Regulated: shoppers must verify their age to buy (AgeWallet)', 'agewallet-oidc-client' ); ?>
				</label>
				<p><?php esc_html_e( 'When AgeWallet\'s checkout gating is set to "Conditional on cart", shoppers must verify their age before they can buy any product in this term. Clear your page cache after changing this, so product pages pick it up.', 'agewallet-oidc-client' ); ?></p>
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
						<?php esc_html_e( 'Regulated: shoppers must verify their age to buy (AgeWallet)', 'agewallet-oidc-client' ); ?>
					</label>
				</th>
				<td>
					<input type="checkbox" name="<?php echo esc_attr( AgeWallet_WooCommerce::META_KEY_TERM_REGULATED ); ?>" id="agewallet_regulated" value="1" <?php checked( '1', $checked ); ?> />
					<p class="description"><?php esc_html_e( 'When AgeWallet\'s checkout gating is set to "Conditional on cart", shoppers must verify their age before they can buy any product in this term. Clear your page cache after changing this, so product pages pick it up.', 'agewallet-oidc-client' ); ?></p>
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
