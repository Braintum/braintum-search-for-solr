<?php
/**
 * The search popup.
 *
 * Override by copying this file to `yourtheme/braintum-search/search-modal.php`.
 *
 * The script finds its parts by their `data-braintum-search-*` attributes, never by
 * class, so classes and structure are yours to change. Keep each attribute on
 * some element, or the part it names stops working:
 *
 * - `data-braintum-search-modal`    the <dialog> itself
 * - `data-braintum-search-form`     a form; submitting it opens the full results page
 * - `data-braintum-search-input`    the search field
 * - `data-braintum-search-hint`     how to reach the results by keyboard; the
 *                              field is described by it
 * - `data-braintum-search-tab-input` hidden field carrying the selected tab on submit
 * - `data-braintum-search-clear`    clears the field
 * - `data-braintum-search-close`    closes the popup
 * - `data-braintum-search-tabs`     where the tab buttons are placed
 * - `data-braintum-search-summary`  the "5 results for …" line
 * - `data-braintum-search-results`  where the result sections are placed
 * - `data-braintum-search-more`     a button that loads the next page of a tab; it
 *                              is also pressed for the visitor when it scrolls
 *                              into view
 * - `data-braintum-search-message`  empty, error and hint text
 * - `data-braintum-search-view-all` link to the full results page
 * - <template data-braintum-search-template="tab"> one tab; fills `label` and `count`
 * - <template data-braintum-search-template="section"> one group of results: on the
 *   All tab, one per content type; on a type tab, the one grid that scrolls.
 *   Inside it:
 *   - `data-braintum-search-section-header` the heading row, removed on a type tab
 *   - `data-braintum-search-field="label"` the type's plural name
 *   - `data-braintum-search-field="count"` its number of matches
 *   - `data-braintum-search-see-all`       opens that type's tab; fills `see_all`
 *   - `data-braintum-search-section-results` the list the cards are placed in
 *
 * Result cards are separate templates: see search-modal/result.php.
 *
 * @package BraintumSearch
 * @since   0.11.0
 *
 * @var array $args {
 *     @type string $heading     Text at the top left. Defaults to the site name.
 *     @type string $tagline     Text at the top right. Defaults to the site tagline.
 *     @type string $placeholder Placeholder in the search field.
 *     @type string $search_url  The site's search results address.
 *     @type bool   $show_credit Whether to show the "Powered by" line. Off unless a site opts in.
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<dialog class="braintum-search-modal" aria-label="<?php esc_attr_e( 'Search', 'braintum-search-for-solr' ); ?>" data-braintum-search-modal>
	<div class="braintum-search-modal__panel">
		<div class="braintum-search-modal__header">
			<p class="braintum-search-modal__heading" id="braintum-search-modal-heading">
				<svg class="braintum-search-modal__mark" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M3 6V4.5A1.5 1.5 0 0 1 4.5 3H6M14 3h1.5A1.5 1.5 0 0 1 17 4.5V6M17 14v1.5a1.5 1.5 0 0 1-1.5 1.5H14M6 17H4.5A1.5 1.5 0 0 1 3 15.5V14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="9.5" cy="9.5" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m11.5 11.5 1.8 1.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
				<span><?php echo esc_html( $args['heading'] ); ?></span>
			</p>
			<?php if ( '' !== $args['tagline'] ) : ?>
				<p class="braintum-search-modal__tagline"><?php echo esc_html( $args['tagline'] ); ?></p>
			<?php endif; ?>
			<button type="button" class="braintum-search-modal__close" data-braintum-search-close>
				<span class="screen-reader-text"><?php esc_html_e( 'Close search', 'braintum-search-for-solr' ); ?></span>
				<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
			</button>
		</div>

		<form class="braintum-search-modal__search" role="search" method="get" action="<?php echo esc_url( $args['search_url'] ); ?>" data-braintum-search-form>
			<svg class="braintum-search-modal__search-icon" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="9" cy="9" r="6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m13.5 13.5 3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
			<label class="screen-reader-text" for="braintum-search-modal-input"><?php esc_html_e( 'Search for:', 'braintum-search-for-solr' ); ?></label>
			<input
				class="braintum-search-modal__input"
				id="braintum-search-modal-input"
				type="search"
				name="s"
				placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
				autocomplete="off"
				autocapitalize="off"
				spellcheck="false"
				enterkeyhint="search"
				aria-describedby="braintum-search-modal-hint"
				data-braintum-search-input
			/>
			<span class="screen-reader-text" id="braintum-search-modal-hint" data-braintum-search-hint><?php esc_html_e( 'Results appear below as you type. Press the down arrow to move to them.', 'braintum-search-for-solr' ); ?></span>
			<input type="hidden" name="post_type" value="" disabled data-braintum-search-tab-input />
			<span class="braintum-search-modal__spinner" aria-hidden="true"></span>
			<button type="button" class="braintum-search-modal__clear" hidden data-braintum-search-clear>
				<span class="screen-reader-text"><?php esc_html_e( 'Clear search', 'braintum-search-for-solr' ); ?></span>
				<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="m5.5 5.5 9 9m0-9-9 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
			</button>
		</form>

		<div class="braintum-search-modal__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Filter results by type', 'braintum-search-for-solr' ); ?>" hidden data-braintum-search-tabs></div>

		<div class="braintum-search-modal__body">
			<div class="braintum-search-modal__summary" hidden data-braintum-search-summary-row>
				<p class="braintum-search-modal__count" data-braintum-search-summary></p>
				<p class="braintum-search-modal__order"><?php esc_html_e( 'Most relevant first', 'braintum-search-for-solr' ); ?></p>
			</div>
			<div class="braintum-search-modal__results" data-braintum-search-results></div>
			<button type="button" class="braintum-search-modal__more" hidden data-braintum-search-more>
				<span class="braintum-search-modal__more-label"><?php esc_html_e( 'Load more results', 'braintum-search-for-solr' ); ?></span>
			</button>
			<p class="braintum-search-modal__message" data-braintum-search-message></p>
			<p class="screen-reader-text" aria-live="polite" data-braintum-search-status></p>
		</div>

		<div class="braintum-search-modal__footer">
			<?php if ( $args['show_credit'] ) : ?>
				<p class="braintum-search-modal__credit"><?php esc_html_e( 'Powered by Braintum Search', 'braintum-search-for-solr' ); ?></p>
			<?php endif; ?>
			<a class="braintum-search-modal__all" href="<?php echo esc_url( $args['search_url'] ); ?>" data-braintum-search-view-all>
				<?php esc_html_e( 'View all results', 'braintum-search-for-solr' ); ?>
				<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M4 10h11m-4.5-4.5L15 10l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</a>
		</div>
	</div>

	<template data-braintum-search-template="section">
		<section class="braintum-search-section">
			<div class="braintum-search-section__header" data-braintum-search-section-header>
				<h2 class="braintum-search-section__title">
					<span data-braintum-search-field="label"></span>
					<span class="braintum-search-section__count" data-braintum-search-field="count"></span>
				</h2>
				<button type="button" class="braintum-search-section__all" data-braintum-search-see-all>
					<span data-braintum-search-field="see_all"></span>
					<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M4 10h11m-4.5-4.5L15 10l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</button>
			</div>
			<ul class="braintum-search-section__results" role="list" data-braintum-search-section-results></ul>
		</section>
	</template>

	<template data-braintum-search-template="tab">
		<button type="button" class="braintum-search-tab" role="tab" aria-selected="false" tabindex="-1">
			<span class="braintum-search-tab__label" data-braintum-search-field="label"></span>
			<span class="braintum-search-tab__count" data-braintum-search-field="count"></span>
		</button>
	</template>
</dialog>
