/**
 * Spread Em – Spreadsheet-style WooCommerce product editor.
 *
 * Renders the product data provided by the server (via wp_localize_script)
 * into an editable HTML table, tracks changes, provides Undo, and saves
 * only changed rows back to the server in a single AJAX call.
 *
 * @package SpreadEm
 */
/* global spreadEmData, jQuery */

( function ( $ ) {
	'use strict';

	/** -----------------------------------------------------------------------
	 * State
	 * ---------------------------------------------------------------------- */

	/** Original products as loaded from the server (deep clone). */
	let originalData = [];

	/** Current working copy – mutated as the user edits cells. */
	let currentData = [];

	/**
	 * Single-level undo stack.
	 * Each entry is { rowIndex, key, oldValue, newValue }.
	 */
	let lastChange = null;

	/** Whether there are unsaved changes pending. */
	let isDirty = false;

	/** Column definitions supplied by the server. */
	const columns = spreadEmData.columns || [];

	/** -----------------------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------------------- */

	$( document ).ready( function () {
		originalData = JSON.parse( JSON.stringify( spreadEmData.products ) );
		currentData  = JSON.parse( JSON.stringify( spreadEmData.products ) );

		buildTable();

		$( '#spread-em-loading' ).hide();
		$( '#spread-em-table-wrap' ).show();

		// Button handlers.
		$( '#spread-em-save' ).on( 'click', handleSave );
		$( '#spread-em-undo' ).on( 'click', handleUndo );

		// Warn on leaving with unsaved changes.
		$( window ).on( 'beforeunload', function ( e ) {
			if ( isDirty ) {
				e.preventDefault();
				return spreadEmData.i18n.unsaved_changes;
			}
		} );

		// Override the back-button so it also warns about unsaved changes.
		$( '#spread-em-back' ).on( 'click', function ( e ) {
			if ( isDirty ) {
				if ( ! window.confirm( spreadEmData.i18n.unsaved_changes ) ) {
					e.preventDefault();
				}
			}
		} );
	} );

	/** -----------------------------------------------------------------------
	 * Table builder
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the <thead> and <tbody> of the spreadsheet table.
	 */
	function buildTable() {
		buildHeader();
		buildBody();
	}

	function buildHeader() {
		const $thead = $( '#spread-em-thead' );
		$thead.empty();

		const $tr = $( '<tr>' );
		columns.forEach( function ( col ) {
			const $th = $( '<th>' )
				.text( col.label )
				.attr( 'data-key', col.key );
			if ( col.readonly ) {
				$th.addClass( 'spread-em-readonly' );
			}
			$tr.append( $th );
		} );
		$thead.append( $tr );
	}

	function buildBody() {
		const $tbody = $( '#spread-em-tbody' );
		$tbody.empty();

		currentData.forEach( function ( product, rowIndex ) {
			const $tr = $( '<tr>' ).attr( 'data-row', rowIndex );

			columns.forEach( function ( col ) {
				const $td = $( '<td>' ).attr( 'data-key', col.key );

				if ( col.readonly ) {
					$td.text( product[ col.key ] !== undefined ? product[ col.key ] : '' )
					   .addClass( 'spread-em-readonly' );
				} else {
					const $input = buildCellInput( col, product[ col.key ], rowIndex );
					$td.append( $input );
				}

				$tr.append( $td );
			} );

			$tbody.append( $tr );
		} );
	}

	/**
	 * Create the appropriate <input> (or <select>) element for a cell.
	 *
	 * @param {Object} col       Column definition.
	 * @param {*}      value     Current value for this cell.
	 * @param {number} rowIndex  Row index in currentData.
	 * @returns {jQuery}
	 */
	function buildCellInput( col, value, rowIndex ) {
		const currentValue = value !== undefined && value !== null ? String( value ) : '';

		// Certain columns get a <select> instead of a free-text <input>.
		const selectOptions = getSelectOptions( col.key );

		if ( selectOptions ) {
			const $select = $( '<select>' )
				.attr( 'aria-label', col.label )
				.addClass( 'spread-em-cell-select' );

			selectOptions.forEach( function ( opt ) {
				const $opt = $( '<option>' ).val( opt.value ).text( opt.label );
				if ( opt.value === currentValue ) {
					$opt.prop( 'selected', true );
				}
				$select.append( $opt );
			} );

			// Track the previous value so multiple changes to the same select
			// each store the correct oldValue for undo.
			let prevSelectValue = currentValue;
			$select.on( 'change', function () {
				const newSelectValue = $select.val();
				recordChange( rowIndex, col.key, prevSelectValue, newSelectValue );
				prevSelectValue = newSelectValue;
			} );

			return $select;
		}

		// Default: plain text input.
		const $input = $( '<input>' )
			.attr( {
				type        : 'text',
				value       : currentValue,
				'aria-label': col.label,
			} )
			.addClass( 'spread-em-cell-input' );

		// Record the change when the user leaves the cell.
		let preFocusValue = currentValue;
		$input.on( 'focus', function () {
			preFocusValue = $( this ).val();
		} );
		$input.on( 'blur', function () {
			const newVal = $( this ).val();
			if ( newVal !== preFocusValue ) {
				recordChange( rowIndex, col.key, preFocusValue, newVal );
			}
		} );

		return $input;
	}

	/** -----------------------------------------------------------------------
	 * Select option maps
	 * ---------------------------------------------------------------------- */

	/**
	 * Return an array of { value, label } pairs for fields that use a <select>,
	 * or null for free-text fields.
	 *
	 * @param {string} key Column key.
	 * @returns {Array|null}
	 */
	function getSelectOptions( key ) {
		const maps = {
			status: [
				{ value: 'publish', label: 'Published' },
				{ value: 'draft',   label: 'Draft'     },
				{ value: 'pending', label: 'Pending'   },
				{ value: 'private', label: 'Private'   },
			],
			catalog_visibility: [
				{ value: 'visible',  label: 'Visible (catalogue + search)' },
				{ value: 'catalog',  label: 'Catalogue only'               },
				{ value: 'search',   label: 'Search only'                  },
				{ value: 'hidden',   label: 'Hidden'                       },
			],
			tax_status: [
				{ value: 'taxable',  label: 'Taxable'          },
				{ value: 'shipping', label: 'Shipping only'    },
				{ value: 'none',     label: 'None'             },
			],
			stock_status: [
				{ value: 'instock',      label: 'In stock'      },
				{ value: 'outofstock',   label: 'Out of stock'  },
				{ value: 'onbackorder',  label: 'On backorder'  },
			],
			manage_stock: [
				{ value: 'yes', label: 'Yes' },
				{ value: 'no',  label: 'No'  },
			],
			backorders: [
				{ value: 'no',     label: 'Do not allow' },
				{ value: 'notify', label: 'Allow (notify)' },
				{ value: 'yes',    label: 'Allow'          },
			],
		};

		return maps[ key ] || null;
	}

	/** -----------------------------------------------------------------------
	 * Change tracking
	 * ---------------------------------------------------------------------- */

	/**
	 * Record a cell change, update currentData, mark the sheet dirty, and
	 * update the Undo button state.
	 *
	 * Only the most recent change is stored for undo (single-level undo).
	 *
	 * @param {number} rowIndex  Row index in currentData.
	 * @param {string} key       Column key.
	 * @param {string} oldValue  Value before the edit.
	 * @param {string} newValue  Value after the edit.
	 */
	function recordChange( rowIndex, key, oldValue, newValue ) {
		lastChange = { rowIndex, key, oldValue, newValue };

		currentData[ rowIndex ][ key ] = newValue;

		isDirty = true;
		$( '#spread-em-save' ).prop( 'disabled', false );
		$( '#spread-em-undo' ).prop( 'disabled', false );
		markRowDirty( rowIndex );
		clearStatus();
	}

	/**
	 * Highlight a row that has pending changes.
	 *
	 * @param {number} rowIndex
	 */
	function markRowDirty( rowIndex ) {
		$( '#spread-em-tbody tr[data-row="' + rowIndex + '"]' ).addClass( 'spread-em-dirty' );
	}

	/**
	 * Remove dirty highlight from a row (after a successful save).
	 *
	 * @param {number} rowIndex
	 */
	function markRowClean( rowIndex ) {
		$( '#spread-em-tbody tr[data-row="' + rowIndex + '"]' ).removeClass( 'spread-em-dirty' );
	}

	/** -----------------------------------------------------------------------
	 * Undo handler
	 * ---------------------------------------------------------------------- */

	function handleUndo() {
		if ( ! lastChange ) {
			showStatus( spreadEmData.i18n.nothing_to_undo, 'info' );
			return;
		}

		const { rowIndex, key, oldValue } = lastChange;

		// Revert currentData.
		currentData[ rowIndex ][ key ] = oldValue;

		// Revert the DOM cell.
		const $cell = $(
			'#spread-em-tbody tr[data-row="' + rowIndex + '"] td[data-key="' + key + '"]'
		);
		const $input = $cell.find( 'input.spread-em-cell-input' );
		const $select = $cell.find( 'select.spread-em-cell-select' );

		if ( $input.length ) {
			$input.val( oldValue );
		} else if ( $select.length ) {
			$select.val( oldValue );
		}

		// Check whether the row still has any other dirty fields.
		const rowIsStillDirty = columns.some( function ( col ) {
			return ! col.readonly &&
				String( currentData[ rowIndex ][ col.key ] ) !==
				String( originalData[ rowIndex ][ col.key ] );
		} );

		if ( ! rowIsStillDirty ) {
			markRowClean( rowIndex );
		}

		lastChange = null;
		$( '#spread-em-undo' ).prop( 'disabled', true );

		// Recalculate global dirty state.
		recalcDirty();
		clearStatus();
	}

	/**
	 * Recalculate whether there are any pending changes across the whole sheet.
	 */
	function recalcDirty() {
		isDirty = currentData.some( function ( row, i ) {
			return columns.some( function ( col ) {
				return ! col.readonly &&
					String( row[ col.key ] ) !== String( originalData[ i ][ col.key ] );
			} );
		} );

		if ( ! isDirty ) {
			$( '#spread-em-save' ).prop( 'disabled', false ); // leave enabled but no changes
		}
	}

	/** -----------------------------------------------------------------------
	 * Save handler
	 * ---------------------------------------------------------------------- */

	function handleSave() {
		// Collect only the rows that differ from the original.
		const changes = buildChangeset();

		if ( ! changes.length ) {
			showStatus( spreadEmData.i18n.saved, 'success' );
			return;
		}

		const $saveBtn = $( '#spread-em-save' );
		$saveBtn.prop( 'disabled', true ).text( spreadEmData.i18n.saving );

		$.ajax( {
			url   : spreadEmData.ajax_url,
			method: 'POST',
			data  : {
				action : 'spread_em_save',
				nonce  : spreadEmData.nonce,
				changes: JSON.stringify( changes ),
			},
		} )
			.done( function ( response ) {
				if ( response.success ) {
					// Accept all saved changes into the baseline.
					originalData = JSON.parse( JSON.stringify( currentData ) );
					lastChange   = null;
					isDirty      = false;

					// Remove dirty highlights.
					$( '#spread-em-tbody tr.spread-em-dirty' ).removeClass( 'spread-em-dirty' );
					$( '#spread-em-undo' ).prop( 'disabled', true );

					showStatus( spreadEmData.i18n.saved, 'success' );
				} else {
					const msg = ( response.data && response.data.message )
						? response.data.message
						: spreadEmData.i18n.save_error;
					showStatus( msg, 'error' );
				}
			} )
			.fail( function () {
				showStatus( spreadEmData.i18n.save_error, 'error' );
			} )
			.always( function () {
				$saveBtn.prop( 'disabled', false ).text( spreadEmData.i18n.save );
			} );
	}

	/**
	 * Build the minimal changeset – only rows (with only changed fields) that
	 * differ from the baseline.  Each entry always includes the product "id".
	 *
	 * @returns {Array<Object>}
	 */
	function buildChangeset() {
		const changes = [];

		currentData.forEach( function ( row, i ) {
			const diff = { id: row.id };
			let hasChange = false;

			columns.forEach( function ( col ) {
				if ( col.readonly ) {
					return;
				}
				if ( String( row[ col.key ] ) !== String( originalData[ i ][ col.key ] ) ) {
					diff[ col.key ] = row[ col.key ];
					hasChange       = true;
				}
			} );

			if ( hasChange ) {
				changes.push( diff );
			}
		} );

		return changes;
	}

	/** -----------------------------------------------------------------------
	 * Status helper
	 * ---------------------------------------------------------------------- */

	/**
	 * Display a status message in the toolbar area.
	 *
	 * @param {string} message
	 * @param {string} type    'success' | 'error' | 'info'
	 */
	function showStatus( message, type ) {
		$( '#spread-em-status' )
			.text( message )
			.removeClass( 'spread-em-status--success spread-em-status--error spread-em-status--info' )
			.addClass( 'spread-em-status--' + type );
	}

	function clearStatus() {
		$( '#spread-em-status' ).text( '' ).attr( 'class', 'spread-em-status' );
	}

}( jQuery ) );
