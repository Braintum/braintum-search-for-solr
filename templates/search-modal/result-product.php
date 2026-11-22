<?php
/**
 * A product card in the search popup.
 *
 * Override by copying this file to
 * `yourtheme/braintum-search/search-modal/result-product.php`. The attributes are
 * the ones documented in result.php.
 *
 * The price is formatted in the browser from the indexed base price, in the
 * store's currency format. It can differ from the product page for a customer
 * in another tax jurisdiction, under a role-based or multi-currency price, or
 * while a scheduled sale starts or ends before the product is reindexed. A
 * store where that matters removes the price element from its copy.
 *
 * @package BraintumSearch
 * @since   0.11.0
 *
 * @var array $args {
 *     @type string $post_type "product".
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<li class="braintum-search-result braintum-search-result--product">
	<a class="braintum-search-result__link" data-braintum-search-href="url">
		<span class="braintum-search-result__media" data-braintum-search-if="thumbnail">
			<img class="braintum-search-result__image" alt="" loading="lazy" decoding="async" data-braintum-search-src="thumbnail" />
		</span>
		<span class="braintum-search-result__body">
			<span class="braintum-search-result__type" data-braintum-search-field="type"></span>
			<span class="braintum-search-result__title" data-braintum-search-field="title"></span>
			<span class="braintum-search-result__price" data-braintum-search-if="price" data-braintum-search-field="price"></span>
		</span>
		<svg class="braintum-search-result__go" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M6.5 13.5 13.5 6.5M7.5 6.5h6v6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
	</a>
</li>
