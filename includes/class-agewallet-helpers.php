<?php
    // Prevent direct script access.
    defined('ABSPATH') || exit;

    /**
     * Provides utility/helper methods for the AgeWallet OIDC Client plugin.
    *
    * Includes functions for logging, generating secrets/tokens/PKCE, getting URLs etc.
    * Implements the Singleton pattern.
    *
    * @package AgeWalletOIDCClient
    * @since   0.1.0
    */
    class AgeWallet_Helpers {

        /**
         * The single instance of the class.
        * @since 0.1.0
        * @var   AgeWallet_Helpers|null
        */
        private static $instance = null;

        /**
         * Ensures only one instance of the helper class is loaded.
        * @since  0.1.0
        * @static
        * @return AgeWallet_Helpers - Main instance.
        */
        public static function instance() {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor. Private to prevent direct object creation.
        * @since 0.1.0
        */
        private function __construct() {
            // Initialization logic can go here if needed in the future.
        }

        /**
         * Logs a debug message if the plugin's "Enable Debug Logging" setting is enabled.
        * Prepends '[AgeWallet Plugin]' and an optional context prefix.
        * Forces logging to wp-content/debug.log regardless of WP_DEBUG_LOG.
        *
        * @since 0.1.0
        * @param string $message The message to log.
        * @param mixed  $context Optional context data (array, object, string). Will be formatted with print_r.
        */
        public function log( $message, $context = null ) {
            // --- DEBUG LOGIC ---
            // Check if our plugin's debug setting is on. If not, stop immediately.
            // We must check that the main class constant is available first.
            if ( ! class_exists('AgeWalletOIDCClientPro') ) {
                return; // Main class not loaded, cannot check setting.
            }
            $plugin_debug_on = (bool) get_option( AgeWalletOIDCClientPro::OPT_DEBUG_MODE, false );
            if ( ! $plugin_debug_on ) {
                return; // Plugin-specific debug is disabled.
            }

            $log_entry = '[AgeWallet Plugin] ' . $message;
            if ( ! is_null($context) ) {
                if ( is_array($context) || is_object($context) ) {
                    // Use print_r for arrays/objects, remove excessive whitespace.
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- This is the central logging facility; print_r is intentional here for flattening structured context.
                    $context_str = preg_replace('/\s+/', ' ', print_r($context, true));
                    $log_entry .= ' | Context: ' . $context_str;
                } else {
                    $log_entry .= ' | Context: ' . $context;
                }
            }
            // Replace multiple spaces/newlines from print_r output for cleaner single-line logs
            $log_entry = preg_replace('/\s+/', ' ', trim($log_entry));

            // --- DESTINATION LOGIC ---
            // Always log to wp-content/debug.log if plugin debug is on
            $log_file = WP_CONTENT_DIR . '/debug.log';
            // Add timestamp and newline
            $formatted_log_entry = '[' . gmdate('d-M-Y H:i:s') . ' UTC] ' . $log_entry . PHP_EOL;

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Final destination of the plugin's centralised, debug-mode-gated logger. All other log calls in the codebase route through this method.
            @error_log($formatted_log_entry, 3, $log_file); // Type 3 = append to file
            // --- END DESTINATION LOGIC ---
        }

        /**
         * Static version of the log method for use when an instance might not be available (e.g., activation).
        * This version checks WP_DEBUG_LOG, as plugin settings may not be loaded.
        *
        * @since 0.1.0
        * @param string $message The message to log.
        * @param mixed  $context Optional context data (array, object, string).
        */
        public static function static_log_debug( $message, $context = null ) {
            // This static logger is only for activation/deactivation hooks.
            // It will only log if WP_DEBUG_LOG is on, as plugin settings are not reliably available.
            if ( ! ( defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) ) {
                return; // Do nothing if main WP logging is disabled
            }

            $log_entry = '[AgeWallet Plugin] ' . $message; // No class context prefix here
            if ( ! is_null($context) ) {
                if ( is_array($context) || is_object($context) ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Central logging helper; print_r flattens context for inline log entries.
                    $context_str = preg_replace('/\s+/', ' ', print_r($context, true));
                    $log_entry .= ' | Context: ' . $context_str;
                } else {
                    $log_entry .= ' | Context: ' . $context;
                }
            }
            // Use default error_log, which will respect WP_DEBUG_LOG's destination.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Static logger for activation/deactivation hooks; only fires when WP_DEBUG_LOG is on.
            @error_log(preg_replace('/\s+/', ' ', trim($log_entry)));
        }

        /**
         * Ensures the HMAC secret option exists, generating it if needed.
        * Uses cryptographically secure random bytes.
        *
        * @since 0.1.0
        * @return string The HMAC secret. Returns empty string on generation failure.
        */
        public function ensure_hmac_secret() {
            $secret = get_option(AgeWalletOIDCClientPro::OPT_HMAC_SECRET, '');
            $regenerate = false;

            if ( empty($secret) || ! is_string($secret) || strlen($secret) < 32 ) {
                $regenerate = true;
                self::static_log_debug('[Helpers] HMAC secret is missing or invalid, attempting regeneration.'); // Use static log for activation
            }

            if ( $regenerate ) {
                try {
                    $secret = bin2hex(random_bytes(32)); // 64 hex characters
                    update_option(AgeWalletOIDCClientPro::OPT_HMAC_SECRET, $secret, false); // Do not autoload
                    self::static_log_debug('[Helpers] Generated new HMAC secret.');
                } catch ( Exception $e ) {
                    // Fallback if random_bytes fails (highly unlikely)
                    self::static_log_debug('[Helpers] ERROR: random_bytes failed during HMAC secret generation.', ['exception' => $e->getMessage()]);
                    $secret = wp_generate_password(64, true, true);
                    update_option(AgeWalletOIDCClientPro::OPT_HMAC_SECRET, $secret, false);
                    self::static_log_debug('[Helpers] Generated fallback HMAC secret using wp_generate_password.');
                }
            }

            // Final check in case generation failed completely
            if ( empty($secret) || ! is_string($secret) || strlen($secret) < 32 ) {
                self::static_log_debug('[Helpers] CRITICAL ERROR: Failed to generate or retrieve a valid HMAC secret.');
                return ''; // Return empty string on failure
            }

            return apply_filters('agewallet_hmac_secret', $secret);
        }

        /**
         * Generates a cryptographically secure random hex string.
        *
        * @since 0.1.0
        * @param int $byte_length Number of random bytes to generate (results in hex string twice as long). Default 16 (32 chars).
        * @return string Random hex string. Uses less secure fallback on error.
        */
        public function generate_random_hex( $byte_length = 16 ) {
            try {
                return bin2hex(random_bytes( (int) $byte_length ));
            } catch ( Exception $e ) {
                $this->log('ERROR: Failed to generate secure random hex, using fallback.', ['exception' => $e->getMessage()]);
                // Fallback (less secure if random_bytes truly fails)
                return wp_generate_password( $byte_length * 2, false, false);
            }
        }

        /**
         * Generates a PKCE code verifier string (approx 86 chars).
        * @link https://tools.ietf.org/html/rfc7636#section-4.1
        * @since 0.1.0
        * @param int $byte_length Number of random bytes (default 64).
        * @return string The code verifier. Uses less secure fallback on error.
        */
        public function generate_pkce_verifier( $byte_length = 64 ) {
            try {
                $random_bytes = random_bytes( (int) $byte_length );
                return rtrim(strtr(base64_encode($random_bytes), '+/', '-_'), '='); // Base64url encode
            } catch ( Exception $e ) {
                $this->log('ERROR: Failed to generate secure PKCE verifier, using fallback.', ['exception' => $e->getMessage()]);
                // Fallback
                return wp_generate_password( $byte_length * 1.3, true, true); // Approximate length, include special chars
            }
        }

        /**
         * Generates a PKCE code challenge (S256 method).
        * @link https://tools.ietf.org/html/rfc7636#section-4.2
        * @since 0.1.0
        * @param string $code_verifier The code verifier.
        * @return string The S256 code challenge. Returns empty string on error.
        */
        public function generate_pkce_challenge( $code_verifier ) {
            if ( empty($code_verifier) || ! is_string($code_verifier) ) {
                $this->log('ERROR: Invalid code_verifier provided for PKCE challenge generation.');
                return '';
            }
            try {
                $hashed_verifier = hash('sha256', $code_verifier, true); // true = raw binary output
                if ($hashed_verifier === false) {
                    $this->log('ERROR: hash("sha256") failed during PKCE challenge generation.');
                    return '';
                }
                return rtrim(strtr(base64_encode($hashed_verifier), '+/', '-_'), '='); // Base64url encode
            } catch ( Exception $e ) {
                $this->log('ERROR: Exception during PKCE challenge generation.', ['exception' => $e->getMessage()]);
                return '';
            }
        }

        // --- Cookie Security (HMAC) ---

        /**
         * Generates a signed cookie value.
         * Format: base64(json_payload) . signature
         * @param string      $salt     Optional unique salt (e.g., nonce).
         * @param string|null $metadata Optional opaque per-verification metadata to embed.
         * @return string Signed cookie string.
         */
        public function generate_signed_cookie( $salt = '', $metadata = null ) {
            $secret = $this->ensure_hmac_secret();
            if ( empty( $salt ) ) {
                $salt = $this->generate_random_hex(8);
            }

            // Payload
            $payload = array(
                'status' => 'verified',
                'salt'   => $salt,
                // 24 Hour Safety Net: Even though browser session deletes cookie on close,
                // this ensures a stolen cookie string expires in 24h.
                'exp'    => time() + ( DAY_IN_SECONDS ),
            );

            if ( is_string( $metadata ) && '' !== $metadata ) {
                $payload['metadata'] = $metadata;
            }

            // Hook to add extra data
            $payload = apply_filters( 'agewallet_cookie_payload', $payload );

            $encoded_payload = base64_encode( json_encode( $payload ) );
            $signature = hash_hmac( 'sha256', $encoded_payload, $secret );

            return $encoded_payload . '.' . $signature;
        }

        /**
         * Sign an opaque metadata string for safe round-trip through the launch URL.
         * Format: base64(value).signature
         *
         * @since 1.4.0
         * @param string $value
         * @return string
         */
        public function sign_metadata( $value ) {
            $secret  = $this->ensure_hmac_secret();
            $encoded = base64_encode( (string) $value );
            $sig     = hash_hmac( 'sha256', $encoded, $secret );
            return $encoded . '.' . $sig;
        }

        /**
         * Verify a signed metadata string. Returns the original value or null on bad signature.
         *
         * @since 1.4.0
         * @param string $signed
         * @return string|null
         */
        public function verify_signed_metadata( $signed ) {
            if ( ! is_string( $signed ) || strpos( $signed, '.' ) === false ) {
                return null;
            }
            list( $encoded, $sig ) = explode( '.', $signed, 2 );
            $secret   = $this->ensure_hmac_secret();
            $expected = hash_hmac( 'sha256', $encoded, $secret );
            if ( ! hash_equals( $expected, $sig ) ) {
                return null;
            }
            $decoded = base64_decode( $encoded, true );
            return ( false === $decoded ) ? null : $decoded;
        }

        /**
         * Returns the parsed payload of the verified cookie, or null if missing/invalid/expired.
         * Verifies the HMAC signature and expiration before returning.
         *
         * @since 1.4.0
         * @return array|null
         */
        public function get_verified_cookie_payload() {
            $cookie_name  = defined( 'AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME' )
                ? AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME
                : 'agewallet_verified';
            $cookie_value = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

            if ( empty( $cookie_value ) || strpos( $cookie_value, '.' ) === false ) {
                return null;
            }

            list( $encoded_payload, $signature ) = explode( '.', $cookie_value, 2 );

            $secret = $this->ensure_hmac_secret();
            $check_signature = hash_hmac( 'sha256', $encoded_payload, $secret );
            if ( ! hash_equals( $signature, $check_signature ) ) {
                return null;
            }

            $payload = json_decode( base64_decode( $encoded_payload ), true );
            if ( ! is_array( $payload ) || ! isset( $payload['exp'] ) || time() > $payload['exp'] ) {
                return null;
            }

            return $payload;
        }

        /**
         * Verifies a signed cookie value.
         * @param string $cookie_value The raw cookie string.
         * @return bool True if valid and not expired.
         */
        public function verify_signed_cookie( $cookie_value ) {
            // Basic format check
            if ( empty( $cookie_value ) || strpos( $cookie_value, '.' ) === false ) {
                return false;
            }

            list( $encoded_payload, $signature ) = explode( '.', $cookie_value, 2 );

            // 1. Verify Signature
            $secret = $this->ensure_hmac_secret();
            $check_signature = hash_hmac( 'sha256', $encoded_payload, $secret );

            if ( ! hash_equals( $signature, $check_signature ) ) {
                do_action( 'agewallet_cookie_validation_error', 'signature_mismatch', $cookie_value );
                return false;
            }

            // 2. Verify Expiration
            $payload = json_decode( base64_decode( $encoded_payload ), true );
            if ( ! is_array( $payload ) || ! isset( $payload['exp'] ) ) {
                return false;
            }

            if ( time() > $payload['exp'] ) {
                do_action( 'agewallet_cookie_validation_error', 'expired', $cookie_value );
                return false;
            }

            return true;
        }

        /**
         * Expires the verification cookie in the browser, with the path and domain the success page
         * sets it with (agewallet_cookie_path, agewallet_verified_cookie_attributes). Does nothing when
         * the request carries no verification cookie or headers are already sent.
         *
         * @return void
         */
        public function clear_verified_cookie() {
            $cookie_name = defined( 'AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME' )
                ? AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME
                : 'agewallet_verified';

            if ( ! isset( $_COOKIE[ $cookie_name ] ) || headers_sent() ) {
                return;
            }

            $attributes = array(
                'path'     => $this->get_site_path(),
                'SameSite' => 'Lax',
            );
            if ( is_ssl() ) {
                $attributes['Secure'] = true;
            }
            $attributes = array_change_key_case( (array) apply_filters( 'agewallet_verified_cookie_attributes', $attributes ), CASE_LOWER );

            setcookie(
                $cookie_name,
                '',
                array(
                    'expires'  => 1,
                    'path'     => isset( $attributes['path'] ) ? (string) $attributes['path'] : '/',
                    'domain'   => isset( $attributes['domain'] ) ? (string) $attributes['domain'] : '',
                    'secure'   => ! empty( $attributes['secure'] ),
                    'samesite' => isset( $attributes['samesite'] ) ? (string) $attributes['samesite'] : 'Lax',
                )
            );
        }

        // --- URL Helpers ---

        /** Gets the callback URL (/agewallet/callback/). @since 0.1.0 @return string */
        public function get_oidc_redirect_uri() {
            $url = home_url(user_trailingslashit('/agewallet/callback/'));
            return apply_filters('agewallet_redirect_uri', $url);
        }

        /** Gets the success URL (/agewallet/success/). @since 0.1.0 @return string */
        public function get_success_url() {
            $url = home_url(user_trailingslashit('/agewallet/success/'));
            return apply_filters('agewallet_success_url', $url);
        }

        /** Gets the launch URL (/agewallet/launch/). @since 0.1.0 @return string */
        public function get_launch_url() {
            $url = home_url(user_trailingslashit('/agewallet/launch/'));
            return apply_filters('agewallet_launch_url', $url);
        }

        /**
         * Gets the site's base path ('/' or '/subdirectory/'). For cookie scoping.
        * @since 0.1.0
        * @return string The site path with a trailing slash.
        */
        public function get_site_path() {
            $site_url = home_url('/'); // Get Site Address URL with trailing slash
            $path = wp_parse_url($site_url, PHP_URL_PATH);
            // Ensure path is not empty and ends with a slash, default to '/'
            $path = ! empty($path) ? trailingslashit($path) : '/';
            return apply_filters('agewallet_cookie_path', $path);
        }

        /**
         * Gets the current page's full URL by reconstructing from server variables.
        * Attempts to handle HTTPS behind proxies and non-standard ports correctly.
        * Used for the 'redirect_to' parameter.
        *
        * @since 0.1.0
        * @return string The current full URL. Returns home URL on failure.
        */
        public function get_current_url() {
            // Determine Scheme (HTTPS check, considering proxies)
            $scheme       = 'http';
            $https_raw    = isset( $_SERVER['HTTPS'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ) : '';
            $forwarded    = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) : '';
            $server_port  = isset( $_SERVER['SERVER_PORT'] ) ? (int) $_SERVER['SERVER_PORT'] : 0;
            if ( ( ! empty( $https_raw ) && 'off' !== strtolower( $https_raw ) )
                || 443 === $server_port
                || ( ! empty( $forwarded ) && 'https' === strtolower( $forwarded ) )
            ) {
                $scheme = 'https';
            }

            // Determine Host (Prefer HTTP_HOST, fallback to SERVER_NAME).
            $http_host_raw   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
            $server_name_raw = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
            $host = $http_host_raw ?: ( $server_name_raw ?: null );
            if ( ! $host ) {
                $this->log( 'Warning: Could not determine host, falling back to host from home_url().' );
                $host = wp_parse_url( home_url(), PHP_URL_HOST );
            }
            if ( ! $host ) {
                $this->log( 'ERROR: Could not determine host even from home_url(). Cannot construct URL.' );
                return home_url( '/' ); // Final fallback.
            }

            // Get Request URI (includes path and query string).
            $uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

            // Reconstruct the URL
            $current_url = $scheme . '://' . $host . $uri;

            // Basic validation
            if ( ! filter_var($current_url, FILTER_VALIDATE_URL) ) {
                $this->log('Warning: Reconstructed URL failed validation, falling back to home_url() + REQUEST_URI.', ['reconstructed_url' => $current_url]);
                // Fallback to the potentially problematic method ONLY if reconstruction fails validation
                $current_url = home_url( $uri );
                if ( empty($current_url) || ! filter_var($current_url, FILTER_VALIDATE_URL) ) {
                    $this->log('Warning: Fallback URL construction also failed, using basic home URL.');
                    return home_url('/'); // Final fallback
                }
            }

            $this->log('[Helpers] Reconstructed current URL.', ['url' => $current_url]);
            return apply_filters('agewallet_current_url', $current_url);
        }


        /**
         * Build the ":root{ --aw-...: ...; }" declaration from the structured Gate Appearance
         * options. Returns '' when there is nothing to emit. Attached to the gate stylesheet
         * handle via wp_add_inline_style() in AgeWallet_Gating_Manager for both the
         * standard-mode and strict-mode rendering paths, so the colours + radii are identical
         * across both.
         *
         * Radius handling: an empty option (never set, or saved blank) is skipped so the gate.css
         * :root default applies; a real number (including an explicit 0) is honoured.
         *
         * @since 1.5.5
         * @return string
         */
        public function get_gate_css_vars() {
            if ( ! class_exists( 'AgeWallet_Admin' ) ) {
                return '';
            }
            $radius_card = get_option( 'agewallet_radius_card', '' );
            $radius_btn  = get_option( 'agewallet_radius_btn',  '' );
            $vars = array(
                '--aw-bg'               => get_option( 'agewallet_color_overlay_bg',    '#0c0a14' ),
                '--aw-card'             => get_option( 'agewallet_color_card_bg',       '#191029' ),
                '--aw-card-border'      => get_option( 'agewallet_color_card_border',   '#221634' ),
                '--aw-text'             => get_option( 'agewallet_color_text',          '#ffffff' ),
                '--aw-muted'            => get_option( 'agewallet_color_muted',         '#b8b8b8' ),
                '--aw-purple'           => get_option( 'agewallet_color_btn_yes_bg',    '#9d70c9' ),
                '--aw-purple-700'       => get_option( 'agewallet_color_btn_yes_hover', '#8b46cf' ),
                '--aw-no-btn-dark-bg'   => get_option( 'agewallet_color_btn_no_bg',     '#221634' ),
                '--aw-no-btn-dark-text' => get_option( 'agewallet_color_btn_no_text',   '#b8b8b8' ),
                '--aw-radius'           => ( '' === trim( (string) $radius_card ) ) ? '' : ( (int) $radius_card ) . 'px',
                '--aw-btn-radius'       => ( '' === trim( (string) $radius_btn ) )  ? '' : ( (int) $radius_btn ) . 'px',
            );

            $lines = array();
            foreach ( $vars as $property => $value ) {
                $value = trim( (string) $value );
                if ( '' === $value ) {
                    continue;
                }
                $lines[] = $property . ': ' . esc_html( $value ) . ';';
            }
            if ( empty( $lines ) ) {
                return '';
            }
            return ':root{' . implode( '', $lines ) . '}';
        }

        /**
         * Returns the structured GA4 / GTM / Facebook-Pixel IDs the admin has saved (if any),
         * regex-validated. Consumed by AgeWallet_Gating_Manager::enqueue_gatekeeper_analytics()
         * to register + enqueue the vendor snippets via the standard WordPress asset pipeline
         * on the strict-mode gatekeeper page.
         *
         * @since 1.5.6
         * @return array{ga4:string,gtm:string,pixel:string}
         */
        public function get_analytics_ids() {
            $ga4   = (string) get_option( 'agewallet_ga4_id',      '' );
            $gtm   = (string) get_option( 'agewallet_gtm_id',      '' );
            $pixel = (string) get_option( 'agewallet_fb_pixel_id', '' );
            return array(
                'ga4'   => preg_match( '/^G-[A-Z0-9]{4,}$/',  $ga4 )   ? $ga4   : '',
                'gtm'   => preg_match( '/^GTM-[A-Z0-9]{4,}$/', $gtm )   ? $gtm   : '',
                'pixel' => preg_match( '/^\d{6,}$/',           $pixel ) ? $pixel : '',
            );
        }

        /**
         * @deprecated 1.5.6 Retained as a shim so external code that hooked into
         *             agewallet_skeleton_head and manually called this method doesn't fatally
         *             error. Emits nothing — the gatekeeper now enqueues analytics via
         *             AgeWallet_Gating_Manager::enqueue_gatekeeper_analytics().
         *
         * @param string $placement 'head' or 'body'. Ignored.
         */
        public function render_analytics_snippets( $placement = 'head' ) {
            unset( $placement );
        }


        // --- Singleton Pattern Boilerplate ---
        /** Cloning forbidden. @since 0.1.0 */
        public function __clone() { _doing_it_wrong(__FUNCTION__, esc_html__('Cloning forbidden.', 'agewallet-oidc-client'), '0.1.0'); }
        /** Unserializing forbidden. @since 0.1.0 */
        public function __wakeup() { _doing_it_wrong(__FUNCTION__, esc_html__('Unserializing forbidden.', 'agewallet-oidc-client'), '0.1.0'); }

    } // End class AgeWallet_Helpers

    if ( ! function_exists( 'agewallet_get_metadata' ) ) {
        /**
         * Returns the metadata string attached to the current verification, or null if none.
         * Reads the signed verified cookie (validating signature + expiry).
         *
         * @since 1.4.0
         * @return string|null
         */
        function agewallet_get_metadata() {
            $payload = AgeWallet_Helpers::instance()->get_verified_cookie_payload();
            if ( ! is_array( $payload ) || ! isset( $payload['metadata'] ) || ! is_string( $payload['metadata'] ) ) {
                return null;
            }
            return $payload['metadata'];
        }
    }