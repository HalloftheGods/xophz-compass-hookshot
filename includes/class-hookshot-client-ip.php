<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Resolves the caller's IP address for webhook IP allowlists.
 *
 * REMOTE_ADDR is the only address the TCP connection itself proves. X-Forwarded-For is a header the
 * caller writes, so it is read only when the connection came from a configured trusted proxy (the
 * XOPHZ_HOOKSHOT_TRUSTED_PROXIES constant, or the `xophz_hookshot_trusted_proxies` filter; entries are
 * IPs or CIDR ranges). Behind a trusted proxy the client is the right-most forwarded address that is
 * not itself a trusted proxy, which is the hop the nearest trusted proxy actually saw.
 */
class Hookshot_Client_Ip {

	const TRUSTED_PROXIES_CONSTANT = 'XOPHZ_HOOKSHOT_TRUSTED_PROXIES';

	/**
	 * @return string The caller's IP, or an empty string when it cannot be established.
	 */
	public static function resolve( WP_REST_Request $request ) {
		$remote = self::remote_addr();
		$trusted = self::trusted_proxies();

		$isDirect = '' === $remote || empty( $trusted ) || ! self::matches_any( $remote, $trusted );
		if ( $isDirect ) {
			return $remote;
		}

		$forwarded = (string) $request->get_header( 'x_forwarded_for' );
		$chain = array_filter( array_map( 'trim', explode( ',', $forwarded ) ) );

		foreach ( array_reverse( $chain ) as $hop ) {
			$isMalformed = false === filter_var( $hop, FILTER_VALIDATE_IP );
			if ( $isMalformed ) {
				return '';
			}

			$isClient = ! self::matches_any( $hop, $trusted );
			if ( $isClient ) {
				return $hop;
			}
		}

		return $remote;
	}

	/**
	 * True when $ip equals one of $entries, where an entry is an IP or an IPv4/IPv6 CIDR range.
	 */
	public static function matches_any( $ip, array $entries ) {
		foreach ( $entries as $entry ) {
			if ( self::matches( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	public static function matches( $ip, $entry ) {
		$address = self::pack( $ip );
		$parts = explode( '/', trim( (string) $entry ), 2 );
		$network = self::pack( $parts[0] );

		$isUnparseable = false === $address || false === $network || strlen( $address ) !== strlen( $network );
		if ( $isUnparseable ) {
			return false;
		}

		$isSingleAddress = ! isset( $parts[1] );
		if ( $isSingleAddress ) {
			return $address === $network;
		}

		$isBadPrefix = ! ctype_digit( $parts[1] ) || (int) $parts[1] > strlen( $address ) * 8;
		if ( $isBadPrefix ) {
			return false;
		}

		$prefix = (int) $parts[1];
		$whole_bytes = intdiv( $prefix, 8 );
		$spare_bits = $prefix % 8;

		if ( substr( $address, 0, $whole_bytes ) !== substr( $network, 0, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $spare_bits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $spare_bits ) ) & 0xFF;

		return ( ord( $address[ $whole_bytes ] ) & $mask ) === ( ord( $network[ $whole_bytes ] ) & $mask );
	}

	/**
	 * @return string[] Trusted proxy IPs and CIDR ranges; empty unless the site configures them.
	 */
	private static function trusted_proxies() {
		$configured = defined( self::TRUSTED_PROXIES_CONSTANT ) ? constant( self::TRUSTED_PROXIES_CONSTANT ) : [];
		$entries = is_array( $configured ) ? $configured : explode( ',', (string) $configured );

		$entries = apply_filters( 'xophz_hookshot_trusted_proxies', $entries );
		$entries = is_array( $entries ) ? $entries : [];

		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $entries ) ) ) );
	}

	private static function remote_addr() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return false === filter_var( $remote, FILTER_VALIDATE_IP ) ? '' : $remote;
	}

	/**
	 * Packed binary form of an address, with IPv4-mapped IPv6 (::ffff:a.b.c.d) folded to IPv4.
	 *
	 * @return string|false
	 */
	private static function pack( $ip ) {
		$packed = @inet_pton( trim( (string) $ip ) );

		$isMappedIpv4 = false !== $packed && 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 );
		if ( $isMappedIpv4 ) {
			return substr( $packed, 12 );
		}

		return $packed;
	}
}
