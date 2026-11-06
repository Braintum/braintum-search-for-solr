<?php
/**
 * Connection diagnostic.
 *
 * @package BraintumSearch
 */

namespace BraintumSearch\Diagnostics;

use BraintumSearch\Backend\Solr\Endpoint;
use BraintumSearch\Backend\Solr\EndpointValidator;
use BraintumSearch\Backend\Solr\HttpTransport;
use BraintumSearch\Backend\Solr\SolrBackend;
use BraintumSearch\Contracts\Logger;
use BraintumSearch\Schema\ContractRegistry;
use BraintumSearch\Schema\SchemaExporter;
use BraintumSearch\Schema\SchemaPlanner;
use BraintumSearch\Support\Config;
use BraintumSearch\Support\Credentials;

/**
 * Answers "is this working?" with a list of independent facts.
 *
 * One boolean would be useless here. "Cannot connect" covers a firewall, a
 * wrong port, a wrong password, a missing collection and a schema without the
 * Schema API — five problems with five different remedies. So the diagnostic
 * reports each check separately and keeps going where it usefully can, which
 * is what the plan's section 10.5 asks for.
 *
 * Checks stop early only where continuing is meaningless: there is nothing to
 * learn about a collection on a host that does not resolve.
 *
 * Named "Diagnostic" rather than "ConnectionTest" because the latter matches
 * the universal PHPUnit *Test.php convention, and production code that looks
 * like a test file misleads readers, packaging globs and coverage configuration.
 *
 * @since 0.1.0
 */
final class ConnectionDiagnostic {

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Endpoint validator.
	 *
	 * @var EndpointValidator
	 */
	private $validator;

	/**
	 * Contract registry.
	 *
	 * @var ContractRegistry
	 */
	private $contracts;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Config            $config      Configuration.
	 * @param Credentials       $credentials Credential store.
	 * @param EndpointValidator $validator   Endpoint validator.
	 * @param ContractRegistry  $contracts   Contract registry.
	 * @param Logger            $logger      Logger.
	 */
	public function __construct(
		Config $config,
		Credentials $credentials,
		EndpointValidator $validator,
		ContractRegistry $contracts,
		Logger $logger
	) {
		$this->config      = $config;
		$this->credentials = $credentials;
		$this->validator   = $validator;
		$this->contracts   = $contracts;
		$this->logger      = $logger;
	}

	/**
	 * Run the diagnostic against the stored configuration.
	 *
	 * @since 0.1.0
	 *
	 * @return DiagnosticReport
	 */
	public function run(): DiagnosticReport {
		return $this->run_for(
			$this->config->get_string( 'connection.endpoint' ),
			$this->config->get_string( 'connection.collection' ),
			$this->config->get_bool( 'connection.allow_private_ip' )
		);
	}

	/**
	 * Run the diagnostic against a specific address.
	 *
	 * Used by the settings screen so an administrator can test before saving,
	 * rather than having to save a broken address to find out it is broken.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url           Address to test.
	 * @param string $collection    Collection name.
	 * @param bool   $allow_private Whether private addresses are acknowledged.
	 * @return DiagnosticReport
	 */
	public function run_for( string $url, string $collection, bool $allow_private ): DiagnosticReport {
		$report = new DiagnosticReport();

		$endpoint = $this->validator->validate( $url, $collection, $allow_private );

		if ( $endpoint->is_failure() ) {
			$report->fail(
				'address',
				__( 'Address', 'braintum-search-for-solr' ),
				$endpoint->message(),
				$endpoint->code(),
				$endpoint->context()
			);

			return $report;
		}

		$target = $endpoint->value();

		$report->pass(
			'address',
			__( 'Address', 'braintum-search-for-solr' ),
			sprintf(
				/* translators: %s: the normalised Solr base URL. */
				__( 'Valid. Braintum Search will use %s', 'braintum-search-for-solr' ),
				$target->describe()
			)
		);

		if ( ! $target->is_secure() ) {
			$report->warn(
				'transport',
				__( 'Transport security', 'braintum-search-for-solr' ),
				__( 'This connection is not encrypted. That is acceptable on the same machine, but credentials and content would travel in plain text over any network.', 'braintum-search-for-solr' )
			);
		}

		$backend = $this->backend_for( $target );

		$reachable = $backend->capabilities();

		if ( '' === $reachable->version() ) {
			$report->fail(
				'reachable',
				__( 'Server', 'braintum-search-for-solr' ),
				__( 'No Solr server answered at that address. Check that it is running, and that a firewall is not in the way.', 'braintum-search-for-solr' ),
				'unreachable'
			);

			return $report;
		}

		$report->pass(
			'reachable',
			__( 'Server', 'braintum-search-for-solr' ),
			sprintf(
				/* translators: 1: Solr version, 2: deployment mode. */
				__( 'Solr %1$s, %2$s mode', 'braintum-search-for-solr' ),
				$reachable->version(),
				$reachable->mode()
			)
		);

		$this->check_version( $report, $reachable->version() );

		$report->pass(
			'authentication',
			__( 'Authentication', 'braintum-search-for-solr' ),
			$this->credentials->has_password()
				? sprintf(
					/* translators: %s: where the credentials come from. */
					__( 'Accepted. Credentials: %s', 'braintum-search-for-solr' ),
					$this->credentials->describe()
				)
				: __( 'The server did not ask for credentials.', 'braintum-search-for-solr' )
		);

		if ( '' === $target->collection() ) {
			$report->fail(
				'collection',
				__( 'Collection', 'braintum-search-for-solr' ),
				__( 'No collection or core name is configured.', 'braintum-search-for-solr' ),
				'no_collection'
			);

			return $report;
		}

		$ping = $backend->ping();

		if ( $ping->is_failure() ) {
			$report->fail(
				'collection',
				__( 'Collection', 'braintum-search-for-solr' ),
				'not_found' === $ping->code()
					? sprintf(
						/* translators: %s: collection name. */
						__( 'The collection "%s" does not exist on this server. Create it, or correct the name.', 'braintum-search-for-solr' ),
						$target->collection()
					)
					: $ping->message(),
				$ping->code()
			);

			return $report;
		}

		$report->pass(
			'collection',
			__( 'Collection', 'braintum-search-for-solr' ),
			sprintf(
				/* translators: %s: collection name. */
				__( '"%s" exists and is responding.', 'braintum-search-for-solr' ),
				$target->collection()
			)
		);

		$this->check_schema( $report, $backend, $reachable->supports( 'schema_api' ) );

		$report->pass(
			'facets',
			__( 'Faceted search', 'braintum-search-for-solr' ),
			$reachable->supports( 'json_facets' )
				? __( 'The JSON Facet API is available.', 'braintum-search-for-solr' )
				: __( 'Not detected. Faceted search will be unavailable.', 'braintum-search-for-solr' )
		);

		return $report;
	}

	/**
	 * Check the Solr version against the support policy.
	 *
	 * @param DiagnosticReport $report  Report to add to.
	 * @param string           $version Reported version.
	 * @return void
	 */
	private function check_version( DiagnosticReport $report, string $version ): void {
		// ADR-011: 10.x recommended, 9.10+ supported, older warned but never
		// blocked. Everything below 9.10 is End of Life upstream.
		if ( version_compare( $version, '10.0', '>=' ) ) {
			return;
		}

		if ( version_compare( $version, '9.10', '>=' ) ) {
			$report->warn(
				'version',
				__( 'Solr version', 'braintum-search-for-solr' ),
				__( 'Supported. Solr 10 is recommended, and is what Braintum Search is primarily tested against.', 'braintum-search-for-solr' )
			);

			return;
		}

		$report->warn(
			'version',
			__( 'Solr version', 'braintum-search-for-solr' ),
			sprintf(
				/* translators: %s: the detected Solr version. */
				__( 'Solr %s is past end of life upstream and receives no security fixes. Braintum Search will still try to work with it, but please upgrade to 9.10 or 10.', 'braintum-search-for-solr' ),
				$version
			)
		);
	}

	/**
	 * Check the schema against the current contract.
	 *
	 * @param DiagnosticReport $report      Report to add to.
	 * @param SolrBackend      $backend     Backend to ask.
	 * @param bool             $api_present Whether the Schema API is usable.
	 * @return void
	 */
	private function check_schema( DiagnosticReport $report, SolrBackend $backend, bool $api_present ): void {
		$plan = $backend->plan( $this->contracts->current() );

		if ( $plan->is_failure() ) {
			$report->fail(
				'schema',
				__( 'Schema', 'braintum-search-for-solr' ),
				__( 'The schema could not be read.', 'braintum-search-for-solr' ),
				$plan->code()
			);

			return;
		}

		$schema_plan = $plan->value();

		if ( $schema_plan->is_empty() && array() === $schema_plan->blockers() ) {
			$report->pass(
				'schema',
				__( 'Schema', 'braintum-search-for-solr' ),
				sprintf(
					/* translators: %d: schema contract version. */
					__( 'Ready. Contract version %d is satisfied.', 'braintum-search-for-solr' ),
					$schema_plan->target_version()
				)
			);

			return;
		}

		if ( array() !== $schema_plan->blockers() ) {
			$report->fail(
				'schema',
				__( 'Schema', 'braintum-search-for-solr' ),
				implode( ' ', $schema_plan->blockers() ),
				'schema_blocked',
				array( 'can_export' => true )
			);

			return;
		}

		$report->warn(
			'schema',
			__( 'Schema', 'braintum-search-for-solr' ),
			sprintf(
				/* translators: %d: number of pending schema changes. */
				_n(
					'%d change is needed before indexing can start.',
					'%d changes are needed before indexing can start.',
					count( $schema_plan->operations() ),
					'braintum-search-for-solr'
				),
				count( $schema_plan->operations() )
			),
			array(
				'can_apply'  => $api_present,
				'can_export' => true,
				'operations' => $schema_plan->describe(),
			)
		);
	}

	/**
	 * Build a backend for a given endpoint.
	 *
	 * @param Endpoint $endpoint Endpoint to talk to.
	 * @return SolrBackend
	 */
	private function backend_for( Endpoint $endpoint ): SolrBackend {
		$transport = new HttpTransport(
			$endpoint,
			$this->credentials,
			$this->logger,
			$this->config->get_int( 'connection.read_timeout', 10 ),
			$this->config->get_bool( 'connection.verify_tls', true )
		);

		return new SolrBackend( $transport, new SchemaPlanner(), new SchemaExporter() );
	}
}
