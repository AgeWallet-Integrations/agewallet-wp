<?php
/**
 * The in-memory WordPress state the function stand-ins in wordpress-functions.php read and write:
 * options, post and term meta, transients, posts and terms, the current request's conditional tags
 * and user, and what the plugin enqueued, scheduled, logged or sent. reset() empties it before each
 * test; a test sets the state it needs on the public properties.
 */

namespace AgeWallet\Tests\Support;

final class WP {

	/** @var string */
	public static $home_url = 'https://example.test';

	/** @var array<string, mixed> */
	public static $options = array();

	/** @var array<int, array{0:string,1:mixed,2:mixed}> Every update_option() call: name, value, autoload. */
	public static $updated_options = array();

	/** @var array<int, array<string, mixed>> post id => meta key => value */
	public static $post_meta = array();

	/** @var array<int, array<string, mixed>> term id => meta key => value */
	public static $term_meta = array();

	/** @var array<string, mixed> */
	public static $transients = array();

	/** @var array<int, \WP_Post> */
	public static $posts = array();

	/** @var array<int, array<string, \WP_Term[]>> post id => taxonomy => terms */
	public static $post_terms = array();

	/** @var array<int, string> post id => permalink */
	public static $permalinks = array();

	/** @var array<int, string> attachment id => image URL */
	public static $attachments = array();

	/** @var int The post get_the_ID() returns; 0 when there is none. */
	public static $current_post_id = 0;

	/** @var mixed What get_queried_object() returns. */
	public static $queried_object = null;

	/** @var array<string, mixed> What get_query_var() reads. */
	public static $query_vars = array();

	/** @var string */
	public static $search_query = '';

	/**
	 * The conditional tags: 'admin', 'singular', 'front_page', 'home', 'archive', 'search', 'feed',
	 * 'preview', 'customize_preview', '404', 'author', 'date', 'category', 'tag', 'tax',
	 * 'post_type_archive', 'ssl', 'doing_ajax', 'doing_cron', 'json_request', 'woocommerce',
	 * 'checkout', 'cart', 'account_page'; 'page' holds the id is_page() answers true for;
	 * 'wc_endpoint' the endpoint is_wc_endpoint_url() answers true for.
	 *
	 * @var array<string, mixed>
	 */
	public static $is = array();

	/** @var int 0 = not logged in. */
	public static $user_id = 0;

	/** @var string[] */
	public static $user_roles = array();

	/** @var string[] The capabilities current_user_can() answers true for. */
	public static $caps = array();

	/** @var string[] Registered style handles. */
	public static $registered_styles = array();

	/** @var string[] Registered script handles. */
	public static $registered_scripts = array();

	/** @var string[] Enqueued style handles. */
	public static $enqueued_styles = array();

	/** @var string[] Enqueued script handles. */
	public static $enqueued_scripts = array();

	/** @var array<string, string[]> handle => inline CSS. */
	public static $inline_styles = array();

	/** @var array<string, string[]> handle => inline JS. */
	public static $inline_scripts = array();

	/** @var array<string, array<string, mixed>> handle => object name => data. */
	public static $localized = array();

	/** @var array<string, callable> */
	public static $shortcodes = array();

	/** @var array<int, array{0:string,1:string}> wc_add_notice() calls: message, type. */
	public static $notices = array();

	/** @var array<int, array{0:mixed,1:string,2:string}> wp_mail() calls: to, subject, message. */
	public static $mail = array();

	/** @var array<int, array{0:string,1:string,2:array}> WooCommerce logger calls: level, message, context. */
	public static $wc_log = array();

	/** @var array<int, array> wp_schedule_event() calls. */
	public static $scheduled = array();

	/** @var array<int, array> add_rewrite_rule() calls. */
	public static $rewrite_rules = array();

	/** @var array<int, array> register_rest_route() calls. */
	public static $rest_routes = array();

	/** @var object|null What WC() returns: an object with ->cart and ->customer. */
	public static $wc = null;

	/** @var array<string, int> WooCommerce page name => page id, for wc_get_page_id(). */
	public static $wc_pages = array();

	/** @var array<int, \WC_Product> product id => product, for wc_get_product(). */
	public static $products = array();

	/** @var array<int, \WC_Order> order id => order, for wc_get_order(). */
	public static $orders = array();

	/** @var string[] */
	public static $post_types = array( 'post', 'page', 'product' );

	/** @var string */
	public static $currency = 'USD';

	/** @var array<string, string> Nonces wp_verify_nonce() accepts: nonce => action. */
	public static $nonces = array();

	public static function reset() {
		self::$home_url           = 'https://example.test';
		self::$options            = array();
		self::$updated_options    = array();
		self::$post_meta          = array();
		self::$term_meta          = array();
		self::$transients         = array();
		self::$posts              = array();
		self::$post_terms         = array();
		self::$permalinks         = array();
		self::$attachments        = array();
		self::$current_post_id    = 0;
		self::$queried_object     = null;
		self::$query_vars         = array();
		self::$search_query       = '';
		self::$is                 = array();
		self::$user_id            = 0;
		self::$user_roles         = array();
		self::$caps               = array();
		self::$registered_styles  = array();
		self::$registered_scripts = array();
		self::$enqueued_styles    = array();
		self::$enqueued_scripts   = array();
		self::$inline_styles      = array();
		self::$inline_scripts     = array();
		self::$localized          = array();
		self::$shortcodes         = array();
		self::$notices            = array();
		self::$mail               = array();
		self::$wc_log             = array();
		self::$scheduled          = array();
		self::$rewrite_rules      = array();
		self::$rest_routes        = array();
		self::$wc                 = null;
		self::$wc_pages           = array();
		self::$products           = array();
		self::$orders             = array();
		self::$post_types         = array( 'post', 'page', 'product' );
		self::$currency           = 'USD';
		self::$nonces             = array();
	}

	/**
	 * @param string $tag
	 * @return bool
	 */
	public static function is( $tag ) {
		return ! empty( self::$is[ $tag ] );
	}

	/**
	 * Registers a post and makes it the current one.
	 *
	 * @param int   $id
	 * @param array $fields post_content, post_type, post_name, post_title.
	 * @return \WP_Post
	 */
	public static function post( $id, array $fields = array() ) {
		$post                  = new \WP_Post( array_merge( array( 'ID' => $id ), $fields ) );
		self::$posts[ $id ]    = $post;
		self::$current_post_id = $id;
		return $post;
	}

	/**
	 * Gives a post terms in a taxonomy.
	 *
	 * @param int    $post_id
	 * @param string $taxonomy
	 * @param int[]  $term_ids
	 */
	public static function terms( $post_id, $taxonomy, array $term_ids ) {
		self::$post_terms[ $post_id ][ $taxonomy ] = array_map(
			static function ( $term_id ) use ( $taxonomy ) {
				return new \WP_Term( $term_id, $taxonomy );
			},
			$term_ids
		);
	}

	/**
	 * A WooCommerce cart holding the given products, as WC()->cart.
	 *
	 * @param \WC_Product[] $products
	 * @return \WC_Cart
	 */
	public static function cart( array $products ) {
		$cart     = new \WC_Cart( $products );
		self::$wc = (object) array(
			'cart'     => $cart,
			'customer' => new \WC_Customer( 'US' ),
		);
		return $cart;
	}
}
