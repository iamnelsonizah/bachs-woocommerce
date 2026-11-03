<?php
/**
 * Verifies Bachs timestamped HMAC webhook signatures against the raw body.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Webhook_Verifier {
	const DEFAULT_TOLERANCE = 300;

	/**
	 * Verify current V2 signatures and the legacy V1 header during migration.
	 *
	 * @param string $raw_body Exact bytes received by WordPress.
	 * @param array  $headers Request headers.
	 * @param array  $secrets Current and optional previous secrets.
	 * @param int    $tolerance Maximum accepted timestamp drift in seconds.
	 * @return bool
	 */
	public static function verify( $raw_body, $headers, $secrets, $tolerance = self::DEFAULT_TOLERANCE ) {
		if ( ! is_string( $raw_body ) || empty( $secrets ) ) {
			return false;
		}

		$headers = array_change_key_case( $headers, CASE_LOWER );
		$v2      = isset( $headers['x-bachs-signature-v2'] ) && is_string( $headers['x-bachs-signature-v2'] ) ? trim( $headers['x-bachs-signature-v2'] ) : '';

		if ( '' !== $v2 ) {
			return self::verify_v2( $raw_body, $v2, $secrets, $tolerance );
		}

		$timestamp = isset( $headers['x-bachs-timestamp'] ) && is_string( $headers['x-bachs-timestamp'] ) ? trim( $headers['x-bachs-timestamp'] ) : '';
		$signature = isset( $headers['x-bachs-signature'] ) && is_string( $headers['x-bachs-signature'] ) ? trim( $headers['x-bachs-signature'] ) : '';
		return self::verify_legacy( $raw_body, $timestamp, $signature, $secrets, $tolerance );
	}

	/**
	 * Verify X-Bachs-Signature-V2, including every v1 value during rotation.
	 *
	 * @param string $raw_body Exact request body.
	 * @param string $header V2 signature header.
	 * @param array  $secrets Signing secrets.
	 * @param int    $tolerance Timestamp tolerance.
	 * @return bool
	 */
	private static function verify_v2( $raw_body, $header, $secrets, $tolerance ) {
		$timestamp  = null;
		$signatures = array();

		foreach ( explode( ',', $header ) as $part ) {
			$parts = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$key   = trim( $parts[0] );
			$value = trim( $parts[1] );
			if ( 't' === $key ) {
				$timestamp = $value;
			} elseif ( 'v1' === $key && preg_match( '/^[a-f0-9]{64}$/i', $value ) ) {
				$signatures[] = strtolower( $value );
			}
		}

		if ( ! self::valid_timestamp( $timestamp, $tolerance ) || empty( $signatures ) ) {
			return false;
		}

		$message = $timestamp . '.' . $raw_body;
		foreach ( $secrets as $secret ) {
			$expected = hash_hmac( 'sha256', $message, $secret );
			foreach ( $signatures as $signature ) {
				if ( hash_equals( $expected, $signature ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Verify the older pair of timestamp and signature headers.
	 *
	 * @param string $raw_body Exact request body.
	 * @param string $timestamp Unix timestamp.
	 * @param string $signature Hex digest.
	 * @param array  $secrets Signing secrets.
	 * @param int    $tolerance Timestamp tolerance.
	 * @return bool
	 */
	private static function verify_legacy( $raw_body, $timestamp, $signature, $secrets, $tolerance ) {
		if ( ! self::valid_timestamp( $timestamp, $tolerance ) || ! preg_match( '/^[a-f0-9]{64}$/i', $signature ) ) {
			return false;
		}

		$message = $timestamp . '.' . $raw_body;
		foreach ( $secrets as $secret ) {
			if ( hash_equals( hash_hmac( 'sha256', $message, $secret ), strtolower( $signature ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reject malformed and stale timestamps before doing HMAC comparisons.
	 *
	 * @param string|null $timestamp Timestamp string.
	 * @param int         $tolerance Allowed drift.
	 * @return bool
	 */
	private static function valid_timestamp( $timestamp, $tolerance ) {
		if ( ! is_string( $timestamp ) || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		return abs( time() - (int) $timestamp ) <= (int) $tolerance;
	}
}
