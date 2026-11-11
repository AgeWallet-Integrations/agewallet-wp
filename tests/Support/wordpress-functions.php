<?php
/**
 * Stand-ins for the WordPress and WooCommerce functions the plugin calls, backed by the state in
 * AgeWallet\Tests\Support\WP. Loaded by tests/bootstrap.php after Patchwork, so a test can replace
 * any one of them with Brain Monkey (Functions\when(), Functions\expect()). Brain Monkey itself
 * provides the hook functions (add_action, apply_filters, did_action, ...) and a few helpers
 * (absint, trailingslashit, user_trailingslashit, wp_json_encode, is_wp_error, wp_slash), which
 * are left alone. Where WordPress would end the request or reach the network, these throw the
 * exceptions in Exceptions.php.
 */

// phpcs:ignoreFile

use AgeWallet\Tests\Support\JsonResponseException;
use AgeWallet\Tests\Support\NetworkCallException;
use AgeWallet\Tests\Support\RedirectException;
use AgeWallet\Tests\Support\WP;
use AgeWallet\Tests\Support\WPDieException;

// --- Plugin loading ---

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return rtrim( dirname( $file ), '/\\' ) . '/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return WP::$home_url . '/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {}
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( $hard = true ) {}
}
if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
		WP::$rewrite_rules[] = array( $regex, $query, $after );
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $route_namespace, $route, $args = array(), $override = false ) {
		WP::$rest_routes[] = array( $route_namespace, $route, $args );
		return true;
	}
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		WP::$shortcodes[ $tag ] = $callback;
	}
}
if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( $content, $tag ) {
		return (bool) preg_match( '/\[' . preg_quote( $tag, '/' ) . '(?=[\s\]\/])/', (string) $content );
	}
}
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( $content, $ignore_html = false ) {
		return $content;
	}
}

// --- Translation and escaping ---

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ) {
		echo $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return addcslashes( (string) $text, "'\"\\\r\n" );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}
if ( ! function_exists( 'esc_html_x' ) ) {
	function esc_html_x( $text, $context, $domain = 'default' ) {
		return esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_attr( $text );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ) {
		echo esc_attr( $text );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url, $protocols = null, $_context = 'display' ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		return str_replace( array( '&amp;', '&' ), '&#038;', $url );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		return trim( (string) $url );
	}
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $content, $allowed_html, $allowed_protocols = array() ) {
		return (string) $content;
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ) {
		return (string) $content;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( $text );
	}
}
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $text, $br = true ) {
		$text = trim( (string) $text );
		return '' === $text ? '' : '<p>' . $text . "</p>\n";
	}
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
		return html_entity_decode( (string) $text, $quote_style, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		if ( is_object( $str ) || is_array( $str ) ) {
			return '';
		}
		$filtered = (string) $str;
		if ( false !== strpos( $filtered, '<' ) ) {
			$filtered = wp_strip_all_tags( $filtered, false );
		}
		$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
		$filtered = trim( $filtered );
		while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
			$filtered = str_replace( $match[0], '', $filtered );
		}
		return $filtered;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $class, $fallback = '' ) {
		$sanitized = preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $class );
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $sanitized );
		return '' === $sanitized && $fallback ? sanitize_html_class( $fallback ) : $sanitized;
	}
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( $color ) {
		if ( '' === $color ) {
			return '';
		}
		return preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color ) ? $color : null;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

// --- URLs ---

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		$path = (string) $path;
		return WP::$home_url . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		return WP::$home_url . '/wp-admin/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '', $scheme = 'rest' ) {
		return WP::$home_url . '/wp-json/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * As WordPress: add_query_arg( $key, $value, $url ) or add_query_arg( array $args, $url ). The
	 * parameters already in the URL are decoded and encoded again; the new ones are added as given
	 * (the caller encodes them); a false value removes its key; a fragment is kept.
	 */
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$uri    = isset( $args[1] ) ? (string) $args[1] : $_SERVER['REQUEST_URI'];
		} else {
			$params = array( $args[0] => $args[1] );
			$uri    = isset( $args[2] ) ? (string) $args[2] : $_SERVER['REQUEST_URI'];
		}
		$frag = '';
		$hash = strpos( $uri, '#' );
		if ( false !== $hash ) {
			$frag = substr( $uri, $hash );
			$uri  = substr( $uri, 0, $hash );
		}
		$base  = $uri;
		$query = '';
		$mark  = strpos( $uri, '?' );
		if ( false !== $mark ) {
			$base  = substr( $uri, 0, $mark );
			$query = substr( $uri, $mark + 1 );
		}
		parse_str( $query, $qs );
		$encode = static function ( $value ) use ( &$encode ) {
			return is_array( $value ) ? array_map( $encode, $value ) : urlencode( (string) $value );
		};
		$qs = array_map( $encode, $qs );
		foreach ( $params as $key => $value ) {
			$qs[ $key ] = $value;
		}
		foreach ( $qs as $key => $value ) {
			if ( false === $value ) {
				unset( $qs[ $key ] );
			}
		}
		$pairs = array();
		$build = static function ( $prefix, $value ) use ( &$pairs, &$build ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $k => $v ) {
					$build( $prefix . '[' . $k . ']', $v );
				}
			} else {
				$pairs[] = $prefix . '=' . $value;
			}
		};
		foreach ( $qs as $key => $value ) {
			$build( $key, $value );
		}
		$ret = preg_replace( '#=(&|$)#', '$1', implode( '&', $pairs ) );
		return $base . ( '' === $ret ? '' : '?' . $ret ) . $frag;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0, $leavename = false ) {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return isset( WP::$permalinks[ $id ] ) ? WP::$permalinks[ $id ] : WP::$home_url . '/?p=' . $id;
	}
}
if ( ! function_exists( 'wp_validate_redirect' ) ) {
	function wp_validate_redirect( $location, $fallback_url = '' ) {
		$host = parse_url( (string) $location, PHP_URL_HOST );
		$home = parse_url( WP::$home_url, PHP_URL_HOST );
		return ( null === $host || $host === $home ) ? $location : $fallback_url;
	}
}
if ( ! function_exists( 'wp_sanitize_redirect' ) ) {
	function wp_sanitize_redirect( $location ) {
		return (string) $location;
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
		throw new RedirectException( $location, $status );
	}
}
if ( ! function_exists( 'wp_redirect' ) ) {
	function wp_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
		throw new RedirectException( $location, $status );
	}
}

// --- Conditional tags ---

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return WP::is( 'admin' );
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $post_types = '' ) {
		return WP::is( 'singular' );
	}
}
if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page() {
		return WP::is( 'front_page' );
	}
}
if ( ! function_exists( 'is_home' ) ) {
	function is_home() {
		return WP::is( 'home' );
	}
}
if ( ! function_exists( 'is_archive' ) ) {
	function is_archive() {
		return WP::is( 'archive' );
	}
}
if ( ! function_exists( 'is_search' ) ) {
	function is_search() {
		return WP::is( 'search' );
	}
}
if ( ! function_exists( 'is_feed' ) ) {
	function is_feed( $feeds = '' ) {
		return WP::is( 'feed' );
	}
}
if ( ! function_exists( 'is_preview' ) ) {
	function is_preview() {
		return WP::is( 'preview' );
	}
}
if ( ! function_exists( 'is_customize_preview' ) ) {
	function is_customize_preview() {
		return WP::is( 'customize_preview' );
	}
}
if ( ! function_exists( 'is_404' ) ) {
	function is_404() {
		return WP::is( '404' );
	}
}
if ( ! function_exists( 'is_author' ) ) {
	function is_author( $author = '' ) {
		return WP::is( 'author' );
	}
}
if ( ! function_exists( 'is_date' ) ) {
	function is_date() {
		return WP::is( 'date' );
	}
}
if ( ! function_exists( 'is_category' ) ) {
	function is_category( $category = '' ) {
		return WP::is( 'category' );
	}
}
if ( ! function_exists( 'is_tag' ) ) {
	function is_tag( $tag = '' ) {
		return WP::is( 'tag' );
	}
}
if ( ! function_exists( 'is_tax' ) ) {
	function is_tax( $taxonomy = '', $term = '' ) {
		return WP::is( 'tax' );
	}
}
if ( ! function_exists( 'is_post_type_archive' ) ) {
	function is_post_type_archive( $post_types = '' ) {
		return WP::is( 'post_type_archive' );
	}
}
if ( ! function_exists( 'is_page' ) ) {
	function is_page( $page = '' ) {
		if ( '' === $page ) {
			return WP::is( 'page' );
		}
		return isset( WP::$is['page'] ) && (int) WP::$is['page'] === (int) $page;
	}
}
if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() {
		return WP::is( 'ssl' );
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return WP::$user_id > 0;
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return WP::is( 'doing_ajax' );
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() {
		return WP::is( 'doing_cron' );
	}
}
if ( ! function_exists( 'wp_is_json_request' ) ) {
	function wp_is_json_request() {
		return WP::is( 'json_request' );
	}
}
if ( ! function_exists( 'is_woocommerce' ) ) {
	function is_woocommerce() {
		return WP::is( 'woocommerce' );
	}
}
if ( ! function_exists( 'is_checkout' ) ) {
	function is_checkout() {
		return WP::is( 'checkout' );
	}
}
if ( ! function_exists( 'is_cart' ) ) {
	function is_cart() {
		return WP::is( 'cart' );
	}
}
if ( ! function_exists( 'is_account_page' ) ) {
	function is_account_page() {
		return WP::is( 'account_page' );
	}
}
if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	function is_wc_endpoint_url( $endpoint = false ) {
		if ( ! isset( WP::$is['wc_endpoint'] ) ) {
			return false;
		}
		return false === $endpoint || WP::$is['wc_endpoint'] === $endpoint;
	}
}

// --- Posts, terms, queries ---

if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID() {
		return WP::$current_post_id > 0 ? WP::$current_post_id : false;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post = null, $output = 'OBJECT', $filter = 'raw' ) {
		if ( $post instanceof WP_Post ) {
			return $post;
		}
		$id = null === $post ? WP::$current_post_id : (int) $post;
		return isset( WP::$posts[ $id ] ) ? WP::$posts[ $id ] : null;
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $post = null, $context = 'display' ) {
		$post = get_post( $post );
		return ( $post && isset( $post->$field ) ) ? $post->$field : '';
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		$post = get_post( $post );
		return $post ? $post->post_type : false;
	}
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {
		return array_combine( WP::$post_types, WP::$post_types );
	}
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $post_type ) {
		if ( ! in_array( $post_type, WP::$post_types, true ) ) {
			return null;
		}
		return (object) array(
			'name' => $post_type,
			'cap'  => (object) array( 'edit_post' => 'edit_post' ),
		);
	}
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return WP::$queried_object;
	}
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $query_var, $default_value = '' ) {
		return isset( WP::$query_vars[ $query_var ] ) ? WP::$query_vars[ $query_var ] : $default_value;
	}
}
if ( ! function_exists( 'get_search_query' ) ) {
	function get_search_query( $escaped = true ) {
		return WP::$search_query;
	}
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post ) {
		return false;
	}
}
if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post, $taxonomy ) {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return isset( WP::$post_terms[ $id ][ $taxonomy ] ) ? WP::$post_terms[ $id ][ $taxonomy ] : false;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy = 'post_tag', $args = array() ) {
		$terms = isset( WP::$post_terms[ (int) $post_id ][ $taxonomy ] ) ? WP::$post_terms[ (int) $post_id ][ $taxonomy ] : array();
		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return wp_list_pluck( $terms, 'term_id' );
		}
		return $terms;
	}
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $input_list, $field, $index_key = null ) {
		$out = array();
		foreach ( $input_list as $item ) {
			$out[] = is_object( $item ) ? $item->$field : $item[ $field ];
		}
		return $out;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term, $taxonomy = '', $output = 'OBJECT', $filter = 'raw' ) {
		return $term instanceof WP_Term ? $term : new WP_Term( (int) $term, $taxonomy );
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array(), $deprecated = '' ) {
		return array();
	}
}
if ( ! function_exists( 'get_taxonomies' ) ) {
	function get_taxonomies( $args = array(), $output = 'names', $operator = 'and' ) {
		return array();
	}
}
if ( ! function_exists( 'get_comment' ) ) {
	function get_comment( $comment = null, $output = 'OBJECT' ) {
		return null;
	}
}

// --- Users ---

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return in_array( $capability, WP::$caps, true );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return WP::$user_id;
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return new WP_User( WP::$user_id, WP::$user_roles );
	}
}

// --- Options, meta, transients ---

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return array_key_exists( $option, WP::$options ) ? WP::$options[ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		WP::$options[ $option ]  = $value;
		WP::$updated_options[]   = array( $option, $value, $autoload );
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		$had = array_key_exists( $option, WP::$options );
		unset( WP::$options[ $option ] );
		return $had;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$all = isset( WP::$post_meta[ (int) $post_id ] ) ? WP::$post_meta[ (int) $post_id ] : array();
		if ( '' === $key ) {
			return $all;
		}
		if ( ! array_key_exists( $key, $all ) ) {
			return $single ? '' : array();
		}
		return $single ? $all[ $key ] : array( $all[ $key ] );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value, $prev_value = '' ) {
		WP::$post_meta[ (int) $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
		unset( WP::$post_meta[ (int) $post_id ][ $meta_key ] );
		return true;
	}
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key = '', $single = false ) {
		$all = isset( WP::$term_meta[ (int) $term_id ] ) ? WP::$term_meta[ (int) $term_id ] : array();
		if ( '' === $key ) {
			return $all;
		}
		if ( ! array_key_exists( $key, $all ) ) {
			return $single ? '' : array();
		}
		return $single ? $all[ $key ] : array( $all[ $key ] );
	}
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $meta_key, $meta_value, $prev_value = '' ) {
		WP::$term_meta[ (int) $term_id ][ $meta_key ] = $meta_value;
		return true;
	}
}
if ( ! function_exists( 'delete_term_meta' ) ) {
	function delete_term_meta( $term_id, $meta_key, $meta_value = '' ) {
		unset( WP::$term_meta[ (int) $term_id ][ $meta_key ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return array_key_exists( $transient, WP::$transients ) ? WP::$transients[ $transient ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		WP::$transients[ $transient ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		$had = array_key_exists( $transient, WP::$transients );
		unset( WP::$transients[ $transient ] );
		return $had;
	}
}

// --- Styles and scripts ---

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) {
		WP::$registered_styles[] = $handle;
		return true;
	}
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
		WP::$registered_scripts[] = $handle;
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		WP::$enqueued_styles[] = $handle;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
		WP::$enqueued_scripts[] = $handle;
	}
}
if ( ! function_exists( 'wp_add_inline_style' ) ) {
	function wp_add_inline_style( $handle, $data ) {
		WP::$inline_styles[ $handle ][] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		WP::$inline_scripts[ $handle ][] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		WP::$localized[ $handle ][ $object_name ] = $l10n;
		return true;
	}
}
if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $status = 'enqueued' ) {
		if ( 'registered' === $status ) {
			return in_array( $handle, WP::$registered_styles, true ) || in_array( $handle, WP::$enqueued_styles, true );
		}
		return in_array( $handle, WP::$enqueued_styles, true );
	}
}
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $status = 'enqueued' ) {
		if ( 'registered' === $status ) {
			return in_array( $handle, WP::$registered_scripts, true ) || in_array( $handle, WP::$enqueued_scripts, true );
		}
		if ( 'data' === $status ) {
			return isset( WP::$localized[ $handle ] );
		}
		return in_array( $handle, WP::$enqueued_scripts, true );
	}
}
if ( ! function_exists( 'wp_get_inline_script_tag' ) ) {
	function wp_get_inline_script_tag( $data, $attributes = array() ) {
		return "<script>\n" . $data . "\n</script>\n";
	}
}
if ( ! function_exists( 'wp_print_inline_script_tag' ) ) {
	function wp_print_inline_script_tag( $data, $attributes = array() ) {
		echo wp_get_inline_script_tag( $data, $attributes );
	}
}
if ( ! function_exists( 'wp_get_custom_css' ) ) {
	function wp_get_custom_css( $stylesheet = '' ) {
		return '';
	}
}
if ( ! function_exists( 'wp_print_styles' ) ) {
	function wp_print_styles( $handles = false ) {
		return array();
	}
}
if ( ! function_exists( 'wp_print_head_scripts' ) ) {
	function wp_print_head_scripts() {
		return array();
	}
}
if ( ! function_exists( 'wp_print_footer_scripts' ) ) {
	function wp_print_footer_scripts() {}
}
if ( ! function_exists( 'wp_enqueue_media' ) ) {
	function wp_enqueue_media( $args = array() ) {}
}

// --- Requests, responses, mail ---

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) {
		if ( is_int( $args ) ) {
			$args = array( 'response' => $args );
		}
		throw new WPDieException( $message, $title, $args );
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $value = null, $status_code = null, $flags = 0 ) {
		throw new JsonResponseException( false, $value, $status_code );
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $value = null, $status_code = null, $flags = 0 ) {
		throw new JsonResponseException( true, $value, $status_code );
	}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {}
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		WP::$mail[] = array( $to, $subject, $message );
		return true;
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		throw new NetworkCallException( 'wp_remote_get( ' . $url . ' ) in a unit test' );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		throw new NetworkCallException( 'wp_remote_post( ' . $url . ' ) in a unit test' );
	}
}
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = array() ) {
		throw new NetworkCallException( 'wp_safe_remote_get( ' . $url . ' ) in a unit test' );
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		throw new NetworkCallException( 'wp_remote_request( ' . $url . ' ) in a unit test' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		return is_array( $response ) && isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
	}
}

// --- Files, cron, passwords, nonces, markup ---

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
		$basedir = sys_get_temp_dir() . '/agewallet-tests/uploads';
		if ( $create_dir && ! is_dir( $basedir ) ) {
			mkdir( $basedir, 0777, true );
		}
		return array(
			'path'    => $basedir,
			'url'     => WP::$home_url . '/wp-content/uploads',
			'subdir'  => '',
			'basedir' => $basedir,
			'baseurl' => WP::$home_url . '/wp-content/uploads',
			'error'   => false,
		);
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
		WP::$scheduled[] = array( $timestamp, $recurrence, $hook, $args );
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		foreach ( WP::$scheduled as $event ) {
			if ( $event[2] === $hook ) {
				return $event[0];
			}
		}
		return false;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ) {
		$cleared = 0;
		foreach ( WP::$scheduled as $i => $event ) {
			if ( $event[2] === $hook ) {
				unset( WP::$scheduled[ $i ] );
				$cleared++;
			}
		}
		return $cleared;
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) + 1 ) ), 0, (int) $length );
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce-' . esc_attr( (string) $action ) . '" />';
		if ( $display ) {
			echo $field;
		}
		return $field;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return isset( WP::$nonces[ $nonce ] ) && WP::$nonces[ $nonce ] === $action ? 1 : false;
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $display = true ) {
		$result = (string) $checked === (string) $current ? ' checked=\'checked\'' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $display = true ) {
		$result = (string) $selected === (string) $current ? ' selected=\'selected\'' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $attachment_id, $size = 'thumbnail', $icon = false ) {
		return isset( WP::$attachments[ (int) $attachment_id ] ) ? WP::$attachments[ (int) $attachment_id ] : false;
	}
}
if ( ! function_exists( 'bloginfo' ) ) {
	function bloginfo( $show = '' ) {
		echo 'charset' === $show ? 'UTF-8' : esc_html( get_option( 'blogname', '' ) );
	}
}
if ( ! function_exists( 'language_attributes' ) ) {
	function language_attributes( $doctype = 'html' ) {
		echo 'lang="en-US"';
	}
}
if ( ! function_exists( 'wp_title' ) ) {
	function wp_title( $sep = '&raquo;', $display = true, $seplocation = '' ) {
		return '';
	}
}
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
		throw new LogicException( $function_name . ': ' . $message );
	}
}

// --- WooCommerce ---

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return WP::$wc ? WP::$wc : (object) array( 'cart' => null, 'customer' => null );
	}
}
if ( ! function_exists( 'wc_get_cart_url' ) ) {
	function wc_get_cart_url() {
		return WP::$home_url . '/cart/';
	}
}
if ( ! function_exists( 'wc_get_checkout_url' ) ) {
	function wc_get_checkout_url() {
		return WP::$home_url . '/checkout/';
	}
}
if ( ! function_exists( 'wc_get_page_id' ) ) {
	function wc_get_page_id( $page ) {
		return isset( WP::$wc_pages[ $page ] ) ? (int) WP::$wc_pages[ $page ] : -1;
	}
}
if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $the_product = false, $deprecated = array() ) {
		if ( $the_product instanceof WC_Product ) {
			return $the_product;
		}
		return isset( WP::$products[ (int) $the_product ] ) ? WP::$products[ (int) $the_product ] : false;
	}
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $the_order = false ) {
		if ( $the_order instanceof WC_Order ) {
			return $the_order;
		}
		return isset( WP::$orders[ (int) $the_order ] ) ? WP::$orders[ (int) $the_order ] : false;
	}
}
if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $notice_type = 'success', $data = array() ) {
		WP::$notices[] = array( $message, $notice_type );
	}
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new class() {
			public function log( $level, $message, $context = array() ) {
				WP::$wc_log[] = array( $level, $message, $context );
			}
			public function warning( $message, $context = array() ) {
				$this->log( 'warning', $message, $context );
			}
			public function error( $message, $context = array() ) {
				$this->log( 'error', $message, $context );
			}
			public function info( $message, $context = array() ) {
				$this->log( 'info', $message, $context );
			}
			public function debug( $message, $context = array() ) {
				$this->log( 'debug', $message, $context );
			}
		};
	}
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() {
		return WP::$currency;
	}
}
if ( ! function_exists( 'wc_timezone_string' ) ) {
	function wc_timezone_string() {
		return 'UTC';
	}
}
if ( ! function_exists( 'wc_format_datetime' ) ) {
	function wc_format_datetime( $date, $format = '' ) {
		return $date->format( '' === $format ? 'Y-m-d H:i:s' : $format );
	}
}
