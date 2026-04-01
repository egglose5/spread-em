/**
 * Spread Em editor field helper module.
 *
 * Provides select-option maps and taxonomy picker builders for the main
 * spreadsheet editor.
 *
 * @package SpreadEm
 */
/* global jQuery */

( function ( $ ) {
	'use strict';

	const taxonomyOptions = window.spreadEmData && window.spreadEmData.taxonomies
		? window.spreadEmData.taxonomies
		: { categories: [], tags: [] };

	window.SpreadEmEditorFields = {
		getSelectOptions: getSelectOptions,
		isTaxonomyColumn: isTaxonomyColumn,
		createTaxonomyPicker: createTaxonomyPicker,
	};

	function getSelectOptions( key ) {
		const maps = {
			status: [
				{ value: 'publish', label: 'Published' },
				{ value: 'draft', label: 'Draft' },
				{ value: 'pending', label: 'Pending' },
				{ value: 'private', label: 'Private' },
			],
			catalog_visibility: [
				{ value: 'visible', label: 'Visible (catalogue + search)' },
				{ value: 'catalog', label: 'Catalogue only' },
				{ value: 'search', label: 'Search only' },
				{ value: 'hidden', label: 'Hidden' },
			],
			tax_status: [
				{ value: 'taxable', label: 'Taxable' },
				{ value: 'shipping', label: 'Shipping only' },
				{ value: 'none', label: 'None' },
			],
			stock_status: [
				{ value: 'instock', label: 'In stock' },
				{ value: 'outofstock', label: 'Out of stock' },
				{ value: 'onbackorder', label: 'On backorder' },
			],
			manage_stock: [
				{ value: 'yes', label: 'Yes' },
				{ value: 'no', label: 'No' },
			],
			backorders: [
				{ value: 'no', label: 'Do not allow' },
				{ value: 'notify', label: 'Allow (notify)' },
				{ value: 'yes', label: 'Allow' },
			],
		};

		return maps[ key ] || null;
	}

	function isTaxonomyColumn( key ) {
		return 'categories' === key || 'tags' === key;
	}

	function createTaxonomyPicker( key, value, inputClass ) {
		const options = Array.isArray( taxonomyOptions[ key ] ) ? taxonomyOptions[ key ] : [];
		const listeners = [];

		const $wrap = $( '<div>' ).addClass( 'spread-em-taxonomy-picker' );
		const $input = $( '<input>' )
			.attr( { type: 'text', value: value } )
			.addClass( inputClass );
		const $toggle = $( '<button type="button">' )
			.addClass( 'spread-em-taxonomy-toggle' )
			.attr( 'title', 'Select values' )
			.text( '☑' );
		const $panel = $( '<div>' ).addClass( 'spread-em-taxonomy-panel' ).hide();

		function parseCsv( raw ) {
			return String( raw || '' )
				.split( ',' )
				.map( function ( item ) { return item.trim(); } )
				.filter( function ( item ) { return '' !== item; } );
		}

		function buildPanel( selected ) {
			$panel.empty();
			options.forEach( function ( option ) {
				const checked = selected.indexOf( option ) !== -1;
				const $label = $( '<label>' ).addClass( 'spread-em-taxonomy-option' );
				const $cb = $( '<input type="checkbox">' )
					.val( option )
					.prop( 'checked', checked )
					.on( 'change', function () {
						const next = [];
						$panel.find( 'input[type="checkbox"]:checked' ).each( function () {
							next.push( String( $( this ).val() ) );
						} );
						const csv = next.join( ', ' );
						$input.val( csv );
						listeners.forEach( function ( fn ) { fn( csv ); } );
					} );

				$label.append( $cb ).append( $( '<span>' ).text( option ) );
				$panel.append( $label );
			} );
		}

		function setValue( csv ) {
			$input.val( String( csv || '' ) );
			buildPanel( parseCsv( csv ) );
		}

		$toggle.on( 'click', function ( e ) {
			e.preventDefault();
			$panel.toggle();
		} );

		$( document ).on( 'click', function ( e ) {
			if ( ! $wrap.is( e.target ) && 0 === $wrap.has( e.target ).length ) {
				$panel.hide();
			}
		} );

		$input.on( 'input', function () {
			buildPanel( parseCsv( $input.val() ) );
		} );

		$wrap.append( $input ).append( $toggle ).append( $panel );
		setValue( value );
		$wrap.data( 'picker', { setValue: setValue } );

		return {
			$el: $wrap,
			$input: $input,
			getValue: function () {
				return String( $input.val() || '' );
			},
			setValue: setValue,
			onSelectionChange: function ( fn ) {
				listeners.push( fn );
			},
		};
	}
}( jQuery ) );
