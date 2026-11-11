<?php
/**
 * The exceptions the function stand-ins throw where WordPress would end the request or reach the
 * network, so a test can assert what the plugin did and the test process goes on.
 */

namespace AgeWallet\Tests\Support;

/** wp_die() was called: the message, title and arguments it was given. */
class WPDieException extends \RuntimeException {
	/** @var string */
	public $title;
	/** @var array */
	public $args;

	public function __construct( $message, $title = '', $args = array() ) {
		parent::__construct( (string) $message );
		$this->title = (string) $title;
		$this->args  = (array) $args;
	}

	/** The HTTP status the page would have had, 500 when none was given. */
	public function status() {
		return isset( $this->args['response'] ) ? (int) $this->args['response'] : 500;
	}
}

/** wp_send_json_error() or wp_send_json_success() was called with this data and status. */
class JsonResponseException extends \RuntimeException {
	/** @var bool */
	public $success;
	/** @var mixed */
	public $data;
	/** @var int|null */
	public $status;

	public function __construct( $success, $data, $status = null ) {
		parent::__construct( $success ? 'JSON success response' : 'JSON error response' );
		$this->success = (bool) $success;
		$this->data    = $data;
		$this->status  = null === $status ? null : (int) $status;
	}
}

/** A redirect was sent (wp_safe_redirect / wp_redirect). */
class RedirectException extends \RuntimeException {
	/** @var string */
	public $location;
	/** @var int */
	public $status;

	public function __construct( $location, $status = 302 ) {
		parent::__construct( 'Redirect to ' . $location );
		$this->location = (string) $location;
		$this->status   = (int) $status;
	}
}

/** The plugin tried to reach the network. No unit test may let that happen unstubbed. */
class NetworkCallException extends \RuntimeException {}
