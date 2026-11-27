<?php
/**
 * Resolves a host name to every address a connection could use.
 *
 * @package BraintumSearch
 */

namespace BraintumSearch\Backend\Solr;

/**
 * The default resolver for EndpointValidator: IPv4 and IPv6 both.
 *
 * PHP's gethostbynamel() answers with A records only, while the HTTP API
 * connects through cURL, which tries AAAA records too. A host whose A record is
 * public and whose AAAA record points at a private or metadata address would
 * pass a check that only asked for IPv4, and then be reached over IPv6. So the
 * check asks for both, and the validator refuses the host if any answer is
 * unsafe.
 *
 * The two lookups are injected so the unit tests can exercise the merge
 * without touching DNS.
 *
 * @since 1.0.0
 */
final class DnsResolver {

	/**
	 * Returns the IPv4 addresses for a host, or false.
	 *
	 * @var callable
	 */
	private $ipv4;

	/**
	 * Returns the IPv6 addresses for a host, or false.
	 *
	 * @var callable
	 */
	private $ipv6;

	/**
	 * Constructor.
	 *
	 * @param callable|null $ipv4 Host to IPv4 address list. Defaults to gethostbynamel().
	 * @param callable|null $ipv6 Host to IPv6 address list. Defaults to an AAAA lookup.
	 */
	public function __construct( ?callable $ipv4 = null, ?callable $ipv6 = null ) {
		$this->ipv4 = $ipv4 ?? 'gethostbynamel';
		$this->ipv6 = $ipv6 ?? array( self::class, 'aaaa' );
	}

	/**
	 * Every address the host resolves to.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Host name.
	 * @return string[]
	 */
	public function __invoke( string $host ): array {
		$addresses = array();

		foreach ( array( ( $this->ipv4 )( $host ), ( $this->ipv6 )( $host ) ) as $found ) {
			if ( ! is_array( $found ) ) {
				continue;
			}

			foreach ( $found as $address ) {
				if ( is_string( $address ) && false !== filter_var( $address, FILTER_VALIDATE_IP ) ) {
					$addresses[] = $address;
				}
			}
		}

		return array_values( array_unique( $addresses ) );
	}

	/**
	 * The host's AAAA records.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Host name.
	 * @return string[]
	 */
	public static function aaaa( string $host ): array {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return array();
		}

		// Silenced because a host with no AAAA record, or a resolver that times
		// out, raises a warning rather than returning an empty list. Either way
		// the answer is "no IPv6 addresses", which is what the caller gets.
		$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $records ) ) {
			return array();
		}

		$out = array();

		foreach ( $records as $record ) {
			if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
				$out[] = $record['ipv6'];
			}
		}

		return $out;
	}
}
