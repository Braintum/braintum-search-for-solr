<?php
/**
 * Which post types this site indexes.
 *
 * @package BraintumSearch
 */

namespace BraintumSearch\Documents;

use BraintumSearch\Support\Config;

/**
 * One answer to "what is indexed here", for everything that needs to ask.
 *
 * Four places need it: the save listeners, a full sync, the query integration
 * and the REST endpoint. When they disagree, the failures are quiet and
 * confusing — content that reaches the index on save and disappears on the next
 * full sync, or a REST caller asking for products and being handed the whole
 * site because the post type it named was not on a list it could not see.
 *
 * Both of those happened while Phase 6 was being built, from two copies of the
 * same expression. Hence one copy.
 *
 * @since 0.7.0
 */
final class IndexedTypes {

	/**
	 * The post types this site indexes.
	 *
	 * @since 0.7.0
	 *
	 * @param Config $config Configuration.
	 * @return string[]
	 */
	public static function all( Config $config ): array {
		$types = array_map( 'strval', $config->get_array( 'index.post_types', array( 'post', 'page' ) ) );

		/**
		 * Filters which post types are eligible for indexing.
		 *
		 * Where an integration declares a post type of its own — WooCommerce
		 * adds products here — and where a site removes one.
		 *
		 * @since 0.3.0
		 *
		 * @param string[] $types Configured post types.
		 */
		$types = array_map( 'strval', (array) apply_filters( 'braintum_search_indexable_post_types', $types ) );

		return array_values( array_unique( array_filter( $types ) ) );
	}

	/**
	 * The indexed post types a visitor may search.
	 *
	 * Every public surface — the results page, the search popup, the filter
	 * panel and the REST routes — answers from this list, not from all().
	 * Indexing is the administrator's decision and can include a type a
	 * filter added with `public => false`; serving it is a different decision,
	 * and an anonymous visitor gets only what WordPress would show them at a
	 * URL of its own.
	 *
	 * @since 1.0.0
	 *
	 * @param Config $config Configuration.
	 * @return string[]
	 */
	public static function searchable( Config $config ): array {
		return array_values(
			array_filter(
				self::all( $config ),
				static function ( string $type ): bool {
					return is_post_type_viewable( $type );
				}
			)
		);
	}

	/**
	 * The searchable post types a request may be narrowed to.
	 *
	 * An empty result means the request named nothing it may search, and the
	 * caller must refuse it or answer from WordPress. It never means "no
	 * restriction": treating it that way is how a REST request naming a
	 * non-public type was once answered from every type in the index.
	 *
	 * @since 1.0.0
	 *
	 * @param Config   $config    Configuration.
	 * @param string[] $requested Requested types; empty for every searchable type.
	 * @return string[]
	 */
	public static function narrow( Config $config, array $requested ): array {
		$searchable = self::searchable( $config );
		$requested  = array_values( array_filter( array_map( 'strval', $requested ) ) );

		if ( array() === $requested ) {
			return $searchable;
		}

		return array_values( array_intersect( $requested, $searchable ) );
	}
}
