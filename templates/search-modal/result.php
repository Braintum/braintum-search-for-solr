<?php
/**
 * A result card in the search popup.
 *
 * Override by copying this file to `yourtheme/braintum-search/search-modal/result.php`.
 * To change the card for one post type only, add
 * `yourtheme/braintum-search/search-modal/result-{post_type}.php` instead; the
 * product card beside this file is an example.
 *
 * Rendered once per page, into a <template>. The script copies it for each
 * result and fills it from these attributes:
 *
 * - `data-braintum-search-field="title"`   title, with the searched words in <mark>
 * - `data-braintum-search-field="type"`    the post type's singular name
 * - `data-braintum-search-field="excerpt"` a short excerpt
 * - `data-braintum-search-field="date"`    publication date, in the site's language
 * - `data-braintum-search-field="author"`  author's display name
 * - `data-braintum-search-field="price"`   a product's price
 * - `data-braintum-search-href="url"`      sets href
 * - `data-braintum-search-src="thumbnail"` sets src, or removes the element when
 *                                     there is no image
 * - `data-braintum-search-if="thumbnail"`  removes the element when that field is
 *                                     empty: here, the whole image box, so a
 *                                     card without an image is text only
 *
 * The outermost element must be the only one, and is a list item. The element
 * with `data-braintum-search-href` is the card's link: the script names it with the
 * result's title (`aria-label`) and describes it with the type and price
 * (`aria-describedby`), so put nothing in it that a screen reader must hear and
 * the title does not say. Keep it the one focusable element in the card; arrow
 * keys move between cards by it. After filling a card the script dispatches a
 * bubbling `braintum-search:result` event on it, with `{ result, element }` as its
 * detail, for anything a template cannot express.
 *
 * Text is always inserted as text. A template cannot cause a title to be
 * parsed as markup, and should not try.
 *
 * @package BraintumSearch
 * @since   0.11.0
 *
 * @var array $args {
 *     @type string $post_type The post type this card is for, or '' for the default card.
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<li class="braintum-search-result">
	<a class="braintum-search-result__link" data-braintum-search-href="url">
		<span class="braintum-search-result__media" data-braintum-search-if="thumbnail">
			<img class="braintum-search-result__image" alt="" loading="lazy" decoding="async" data-braintum-search-src="thumbnail" />
		</span>
		<span class="braintum-search-result__body">
			<span class="braintum-search-result__type" data-braintum-search-field="type"></span>
			<span class="braintum-search-result__title" data-braintum-search-field="title"></span>
			<span class="braintum-search-result__excerpt" data-braintum-search-if="excerpt" data-braintum-search-field="excerpt"></span>
		</span>
		<svg class="braintum-search-result__go" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M6.5 13.5 13.5 6.5M7.5 6.5h6v6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
	</a>
</li>
