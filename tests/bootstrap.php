<?php
/**
 * PHPUnit bootstrap: loads the plugin outside WordPress, with Brain Monkey's hook functions and the
 * stand-ins in tests/Support in place of WordPress and WooCommerce. No database, no network.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Brain Monkey loads Patchwork and the hook functions (add_action, apply_filters, ...) here. Every
// file required after this point is instrumented by Patchwork, so a test can redefine any function
// in it with Brain Monkey.
Brain\Monkey\setUp();

define( 'ABSPATH', sys_get_temp_dir() . '/agewallet-tests/wordpress/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

require __DIR__ . '/Support/Exceptions.php';
require __DIR__ . '/Support/wordpress-classes.php';
require __DIR__ . '/Support/wordpress-functions.php';

// The main file builds the AgeWalletOIDCClientPro singleton, which loads every class in includes/
// and registers the plugin's hooks. The WooCommerce class is loaded by the plugin only on
// plugins_loaded with WooCommerce active, so it is loaded here.
require dirname( __DIR__ ) . '/agewallet-oidc-client.php';
require_once dirname( __DIR__ ) . '/includes/class-agewallet-woocommerce.php';

Brain\Monkey\tearDown();
