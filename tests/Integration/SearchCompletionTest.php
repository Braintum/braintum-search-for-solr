<?php
/**
 * The results page and its filter panel complete what the visitor typed.
 *
 * @package BraintumSearch
 */

declare( strict_types=1 );

namespace BraintumSearch\Tests\Integration;

use BraintumSearch\Backend\Memory\MemoryBackend;
use BraintumSearch\Contracts\SearchQuery;
use BraintumSearch\Integration\WordPress\QueryIntegration;
use BraintumSearch\Integration\WordPress\SearchFilters;
use BraintumSearch\Search\FacetRegistry;
use BraintumSearch\Search\FilterRegistry;
use BraintumSearch\Search\SearchService;
use BraintumSearch\Support\Config;
use BraintumSearch\Support\NullLogger;
use WP_Query;
use WP_UnitTestCase;

/**
 * Both must ask for completion, or the panel's counts would describe a
 * different result set from the one beside them.
 *
 * @covers \BraintumSearch\Integration\WordPress\QueryIntegration
 * @covers \BraintumSearch\Integration\WordPress\SearchFilters
 */
final class SearchCompletionTest extends WP_UnitTestCase {

	/**
	 * Queries the search service was asked to run.
	 *
	 * @var SearchQuery[]
	 */
	private $seen = array();

	public function set_up(): void {
		parent::set_up();

		$this->seen = array();

		add_filter(
			'braintum_search_query',
			function ( SearchQuery $query ): SearchQuery {
				$this->seen[] = $query;

				return $query;
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'braintum_search_query' );
		unset( $_GET['s'] );

		parent::tear_down();
	}

	public function test_the_results_page_completes_the_last_word(): void {
		$integration = new QueryIntegration( $this->service(), $this->config(), 1 );

		$query = new WP_Query();
		$query->set( 's', 'hoo' );
		$query->set( 'braintum_search_handled', true );

		$integration->maybe_substitute( null, $query );

		$this->assertCount( 1, $this->seen );
		$this->assertSame( 'hoo', $this->seen[0]->terms() );
		$this->assertTrue( $this->seen[0]->completes_last_word() );
	}

	public function test_the_filter_panel_counts_with_completion_too(): void {
		$_GET['s'] = 'hoo';

		$filters = new SearchFilters( $this->service(), $this->config(), FacetRegistry::defaults(), FilterRegistry::defaults(), 1 );
		$filters->shortcode( array() );

		$this->assertNotEmpty( $this->seen );

		foreach ( $this->seen as $query ) {
			$this->assertSame( 'hoo', $query->terms() );
			$this->assertTrue( $query->completes_last_word() );
		}
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
	 * Configuration with search switched on.
	 *
	 * @return Config
	 */
	private function config(): Config {
		return new Config(
			array(
				'search.enabled'   => true,
				'index.post_types' => array( 'post', 'page' ),
			)
		);
	}
}
