/**
 * The editor half of the search filters block.
 *
 * Renders a description of what the block will do rather than the block itself.
 * The panel's contents depend on a live Solr query against the visitor's
 * current search, which does not exist in the editor: a server-side render here
 * would show an empty panel on most sites and a misleading one on the rest.
 * Naming the facets it will show is the useful thing the editor can say.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import './filters.css';

import metadata from '../../blocks/search-filters/block.json';

/**
 * The block as the editor shows it.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Current attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} The editor representation.
 */
function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps( { className: 'braintum-search-filters' } );
	const { heading, showCounts, facets } = attributes;
	const help = __( 'Comma separated facet keys, or empty for all.', 'braintum-search-for-solr' );

	const everything = __( 'Every facet this site offers.', 'braintum-search-for-solr' );
	const summary = facets.length
		? __( 'Showing: ', 'braintum-search-for-solr' ) + facets.join( ', ' )
		: everything;

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Filters', 'braintum-search-for-solr' ) }>
					<TextControl
						label={ __( 'Heading', 'braintum-search-for-solr' ) }
						value={ heading }
						onChange={ ( value ) =>
							setAttributes( { heading: value } )
						}
					/>
					<TextControl
						label={ __( 'Facets', 'braintum-search-for-solr' ) }
						help={ help }
						value={ facets.join( ', ' ) }
						onChange={ ( value ) =>
							setAttributes( {
								facets: value
									.split( ',' )
									.map( ( key ) => key.trim() )
									.filter( Boolean ),
							} )
						}
					/>
					<ToggleControl
						label={ __( 'Show counts', 'braintum-search-for-solr' ) }
						checked={ showCounts }
						onChange={ ( value ) =>
							setAttributes( { showCounts: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<p className="braintum-search-filters__placeholder">
				{ heading || __( 'Braintum Search search filters', 'braintum-search-for-solr' ) }
			</p>
			<p className="braintum-search-filters__placeholder-note">{ summary }</p>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,

	// Rendered in PHP, from the same code the shortcode uses.
	save: () => null,
} );
