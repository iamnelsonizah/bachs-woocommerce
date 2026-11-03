<?php
/**
 * Safe, structured exception for Bachs API failures.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Api_Exception extends RuntimeException {
	/** @var int */
	private $http_status;

	/** @var string */
	private $error_code;

	/**
	 * @param string $message Safe message for merchant-facing notices.
	 * @param int    $http_status HTTP response status, if known.
	 * @param string $error_code Provider error code, if known.
	 */
	public function __construct( $message, $http_status = 0, $error_code = '' ) {
		parent::__construct( $message );
		$this->http_status = (int) $http_status;
		$this->error_code  = sanitize_key( $error_code );
	}

	/**
	 * @return int
	 */
	public function get_http_status() {
		return $this->http_status;
	}

	/**
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}
}
