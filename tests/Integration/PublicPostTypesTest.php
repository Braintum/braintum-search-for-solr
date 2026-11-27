<?php
/**
 * Public search surfaces answer only from post types a visitor may see.
 *
 * Here rather than in the unit suite because "a visitor may see" is a
 * WordPress question: is_post_type_viewable() over registered post types.
 *
 * @package BraintumSearch
 */

declare( strict_types=1 );

namespace BraintumSearch\Tests\Integration;

use BraintumSearch\Backend\Memory\MemoryBackend;
use BraintumSearch\Contracts\SearchQuery;
use BraintumSearch\Documents\IndexedTypes;
use BraintumSearch\Integration\WordPress\QueryIntegration;
use BraintumSearch\Rest\RateLimiter;
use BraintumSearch\Rest\SearchController;
use BraintumSearch\Search\FacetRegistry;
use BraintumSearch\Search\FilterRegistry;
use BraintumSearch\Search\SearchService;
use BraintumSearch\Support\Config;
use BraintumSearch\Support\NullLogger;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * An empty post-type list must never reach the backend as "no restriction".
 *
 * @covers \BraintumSearch\Documents\IndexedTypes
 * @covers \BraintumSearch\Rest\SearchController
 * @covers \BraintumSearch\Integration\WordPress\QueryIntegration
 */
final class PublicPostTypesTest extends WP_UnitTestCase {

	/**
	 * A post type that is indexed but not public.
	 */
	private const HIDDEN = 'bts_hidden_note';

	/**
	 * Queries the search service was asked to run.
	 *
	 * @var SearchQuery[]
	 */
	private $seen = array();

	public function set_up(): void {
		parent::set_up();

		register_post_type( self::HIDDEN, array( 'public' => false ) );

		$this->seen = array();

		add_filter(
			'braintum_search_query',
			function ( SearchQuery $query ): SearchQuery {
				$this->seen[] = $query;

				return $query;
			}
		);

		add_filter( 'braintum_search_rate_limit_request', '__return_false' );
	}

	public function tear_down(): void {
		remove_all_filters( 'braintum_search_query' );
		remove_all_filters( 'braintum_search_rate_limit_request' );
		unregister_post_type( self::HIDDEN );

		parent::tear_down();
	}

	public function test_an_indexed_type_that_is_not_public_is_not_searchable(): void {
		$this->assertContains( self::HIDDEN, IndexedTypes::all( $this->config() ) );
		$this->assertPublicOnly( IndexedTypes::searchable( $this->config() ) );
	}

	/**
	 * @dataProvider routes
	 */
	public function test_a_request_naming_only_a_hidden_type_is_refused( string $handler ): void {
		$response = $this->controller()->{$handler}( $this->request( array( 'post_type' => array( self::HIDDEN ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'braintum_search_invalid_post_type', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertPublicOnly( $response->get_error_data()['available'] );
		$this->assertSame( array(), $this->seen, 'The backend was asked anyway.' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function routes(): array {
		return array(
			'/search'  => array( 'handle' ),
			'/facets'  => array( 'handle_facets' ),
			'/suggest' => array( 'handle_suggest' ),
		);
	}

	public function test_a_request_naming_no_type_is_restricted_to_public_ones(): void {
		$this->controller()->handle( $this->request() );

		$this->assertCount( 1, $this->seen );
		$this->assertPublicOnly( $this->seen[0]->post_types() );
	}

	public function test_a_hidden_type_beside_a_public_one_is_dropped(): void {
		$this->controller()->handle( $this->request( array( 'post_type' => array( 'post', self::HIDDEN ) ) ) );

		$this->assertCount( 1, $this->seen );
		$this->assertSame( array( 'post' ), $this->seen[0]->post_types() );
	}

	public function test_the_results_page_leaves_a_hidden_type_to_wordpress(): void {
		$integration = new QueryIntegration( $this->service(), $this->config(), 1 );

		$query = new WP_Query();
		$query->set( 's', 'note' );
		$query->set( 'post_type', self::HIDDEN );
		$query->set( 'braintum_search_handled', true );

		$this->assertNull( $integration->maybe_substitute( null, $query ) );
		$this->assertSame( array(), $this->seen );
	}

	public function test_the_results_page_searches_public_types_only(): void {
		$integration = new QueryIntegration( $this->service(), $this->config(), 1 );

		$query = new WP_Query();
		$query->set( 's', 'note' );
		$query->set( 'post_type', 'any' );
		$query->set( 'braintum_search_handled', true );

		$integration->maybe_substitute( null, $query );

		$this->assertCount( 1, $this->seen );
		$this->assertPublicOnly( $this->seen[0]->post_types() );
	}

	/**
	 * Assert a type list holds the public types and not the hidden one.
	 *
	 * Not an exact list: with WooCommerce loaded, products are indexed too.
	 *
	 * @param string[] $types Post types.
	 */
	private function assertPublicOnly( array $types ): void {
		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
		$this->assertNotContains( self::HIDDEN, $types );
	}

	/**
	 * A request carrying every parameter the route schema would default.
	 *
	 * @param array<string,mixed> $params Overrides.
	 * @return WP_REST_Request
	 */
	private function request( array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/braintum-search/v1/search' );

		$defaults = array(
			'q'            => 'note',
			'page'         => 1,
			'per_page'     => 10,
			'limit'        => 8,
			'sort'         => 'relevance',
			'post_type'    => array(),
			'filters'      => array(),
			'highlight'    => false,
			'facets'       => array(),
			'facet_parent' => array(),
		);

		foreach ( $params + $defaults as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * The REST controller over the in-memory backend.
	 *
	 * @return SearchController
	 */
	private function controller(): SearchController {
		$service = $this->service();

		return new SearchController(
			static function () use ( $service ): SearchService {
				return $service;
			},
			FilterRegistry::defaults(),
			FacetRegistry::defaults(),
			$this->config(),
			new RateLimiter(),
			1
		);
	}

	/**
	 * A search service over the in-memory backend.
	 *
	 * @return SearchService
	 */
	private function service(): SearchService {
		return new SearchService( new MemoryBackend( FilterRegistry::defaults(), FacetRegistry::defaults() ), new NullLogger(), 0 );
	}

	/**
	 * Configuration indexing two public types and a hidden one.
	 *
	 * @return Config
	 */
	private function config(): Config {
		return new Config(
			array(
				'search.enabled'   => true,
				'index.post_types' => array( 'post', 'page', self::HIDDEN ),
			)
		);
	}
}
