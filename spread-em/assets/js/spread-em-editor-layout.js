/**
 * Spread Em editor layout helper module.
 *
 * Owns column-width persistence and overlap-safe layout sizing.
 *
 * @package SpreadEm
 */
/* global jQuery */

( function ( $ ) {
	'use strict';

	const COL_WIDTHS_KEY = 'spreadEmColWidths';

	window.SpreadEmEditorLayout = {
		loadColumnWidths: loadColumnWidths,
		saveColumnWidths: saveColumnWidths,
		initColumnLayout: initColumnLayout,
		syncColumnWidthsForContent: syncColumnWidthsForContent,
	};

	function loadColumnWidths() {
		try {
			return JSON.parse( localStorage.getItem( COL_WIDTHS_KEY ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function saveColumnWidths() {
		const widths = {};
		$( '#spread-em-colgroup col' ).each( function () {
			const key = $( this ).attr( 'data-key' );
			const width = parseInt( $( this ).css( 'width' ), 10 );
			if ( key && width > 0 ) {
				widths[ key ] = width;
			}
		} );

		try {
			localStorage.setItem( COL_WIDTHS_KEY, JSON.stringify( widths ) );
		} catch ( e ) {}
	}

	function initColumnLayout() {
		$( '#spread-em-table' ).css( 'table-layout', 'fixed' );

		if ( Object.keys( loadColumnWidths() ).length ) {
			return;
		}

		const measured = {};
		$( '#spread-em-thead tr:first-child th' ).each( function () {
			const key = $( this ).attr( 'data-key' );
			if ( key ) {
				measured[ key ] = $( this ).outerWidth();
			}
		} );

		$( '#spread-em-colgroup col' ).each( function () {
			const key = $( this ).attr( 'data-key' );
			if ( measured[ key ] ) {
				$( this ).css( 'width', measured[ key ] + 'px' );
			}
		} );

		try {
			localStorage.setItem( COL_WIDTHS_KEY, JSON.stringify( measured ) );
		} catch ( e ) {}
	}

	function getColumnFloorWidth( key, index, hasVisibleVariations ) {
		let min = 96;

		if ( 'name' === key ) {
			min = 180;
		} else if ( 'attributes' === key || 0 === String( key ).indexOf( 'meta::' ) ) {
			min = 200;
		} else if ( 'short_description' === key || 'description' === key ) {
			min = 160;
		} else if ( 'categories' === key || 'tags' === key ) {
			min = 170;
		}

		if ( 0 === index && hasVisibleVariations ) {
			min += 28;
		}

		return min;
	}

	function syncColumnWidthsForContent() {
		const $cols = $( '#spread-em-colgroup col' );
		if ( ! $cols.length ) {
			return;
		}

		const hasVisibleVariations = $( '#spread-em-tbody tr.spread-em-variation-row:visible' ).length > 0;
		let changed = false;

		$cols.each( function ( index ) {
			const key = String( $( this ).attr( 'data-key' ) || '' );
			if ( ! key ) {
				return;
			}

			const current = parseInt( $( this ).css( 'width' ), 10 ) || 0;
			const floor = getColumnFloorWidth( key, index, hasVisibleVariations );
			const next = Math.max( current, floor );

			if ( next !== current ) {
				$( this ).css( 'width', next + 'px' );
				changed = true;
			}
		} );

		if ( changed ) {
			saveColumnWidths();
		}
	}
}( jQuery ) );
