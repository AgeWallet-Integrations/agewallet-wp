<?php
/**
 * Stand-ins for the WordPress and WooCommerce classes the plugin's code type-checks or calls:
 * the few methods the plugin uses, backed by plain properties. Loaded by tests/bootstrap.php.
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<string, string[]> */
		public $errors = array();

		public function __construct( $code = '', $message = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message );
			}
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
		}

		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		public function get_error_messages( $code = '' ) {
			if ( '' !== $code ) {
				return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
			}
			$all = array();
			foreach ( $this->errors as $messages ) {
				$all = array_merge( $all, $messages );
			}
			return $all;
		}

		public function has_errors() {
			return ! empty( $this->errors );
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID           = 0;
		public $post_content = '';
		public $post_type    = 'post';
		public $post_name    = '';
		public $post_title   = '';
		public $post_status  = 'publish';

		public function __construct( array $fields = array() ) {
			foreach ( $fields as $name => $value ) {
				$this->$name = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id;
		public $taxonomy;
		public $slug;
		public $name;

		public function __construct( $term_id, $taxonomy, $slug = '' ) {
			$this->term_id  = (int) $term_id;
			$this->taxonomy = $taxonomy;
			$this->slug     = '' === $slug ? 'term-' . $term_id : $slug;
			$this->name     = ucfirst( $this->slug );
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID    = 0;
		public $roles = array();

		public function __construct( $id = 0, array $roles = array() ) {
			$this->ID    = (int) $id;
			$this->roles = $roles;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $method;
		private $route;

		public function __construct( $method = 'GET', $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function get_method() {
			return $this->method;
		}

		public function get_route() {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WooCommerce' ) ) {
	/** Marks WooCommerce as active: the plugin only tests class_exists( 'WooCommerce' ). */
	class WooCommerce {}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		protected $id;
		protected $parent_id;

		public function __construct( $id, $parent_id = 0 ) {
			$this->id        = (int) $id;
			$this->parent_id = (int) $parent_id;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_parent_id() {
			return $this->parent_id;
		}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	class WC_Product_Variation extends WC_Product {}
}

if ( ! class_exists( 'WC_Customer' ) ) {
	class WC_Customer {
		private $billing_country;

		public function __construct( $billing_country = '' ) {
			$this->billing_country = $billing_country;
		}

		public function get_billing_country() {
			return $this->billing_country;
		}
	}
}

if ( ! class_exists( 'WC_Cart' ) ) {
	class WC_Cart {
		/** @var array<string, array> cart item key => item */
		private $items = array();
		public $total  = '0';

		/**
		 * @param WC_Product[] $products
		 */
		public function __construct( array $products = array() ) {
			foreach ( $products as $i => $product ) {
				$this->items[ 'item-' . $i ] = array(
					'key'        => 'item-' . $i,
					'product_id' => $product->get_id(),
					'quantity'   => 1,
					'data'       => $product,
				);
			}
		}

		public function is_empty() {
			return empty( $this->items );
		}

		public function get_cart() {
			return $this->items;
		}

		public function get_cart_hash() {
			return md5( implode( ',', array_keys( $this->items ) ) );
		}

		public function get_total( $context = 'view' ) {
			return $this->total;
		}

		public function get_cart_contents_count() {
			$count = 0;
			foreach ( $this->items as $item ) {
				$count += $item['quantity'];
			}
			return $count;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	class WC_Order_Item_Product {
		private $id;
		private $product;
		private $order_id;

		public function __construct( $product, $id = 0, $order_id = 0 ) {
			$this->product  = $product;
			$this->id       = (int) $id;
			$this->order_id = (int) $order_id;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_product() {
			return $this->product;
		}

		public function get_order_id() {
			return $this->order_id;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public $id                   = 0;
		public $status               = 'pending';
		public $created_via          = 'checkout';
		public $payment_method       = '';
		public $payment_method_title = '';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var WC_Order_Item_Product[] */
		public $items = array();
		/** @var string[] */
		public $notes = array();
		/** @var int How many times save_meta_data() ran. */
		public $meta_saves = 0;

		public function __construct( array $fields = array() ) {
			foreach ( $fields as $name => $value ) {
				$this->$name = $value;
			}
		}

		public function get_id() {
			return $this->id;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_created_via() {
			return $this->created_via;
		}

		public function get_payment_method() {
			return $this->payment_method;
		}

		public function get_payment_method_title() {
			return $this->payment_method_title;
		}

		public function get_order_number() {
			return (string) $this->id;
		}

		public function get_edit_order_url() {
			return \AgeWallet\Tests\Support\WP::$home_url . '/wp-admin/post.php?post=' . $this->id . '&action=edit';
		}

		public function get_meta( $key, $single = true ) {
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		public function save_meta_data() {
			$this->meta_saves++;
		}

		public function get_items() {
			return $this->items;
		}

		public function add_order_note( $note ) {
			$this->notes[] = $note;
			return count( $this->notes );
		}
	}
}

if ( ! class_exists( 'WC_DateTime' ) ) {
	class WC_DateTime extends DateTime {}
}
