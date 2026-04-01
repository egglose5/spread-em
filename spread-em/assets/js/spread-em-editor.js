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

	/** Taxonomy option lists for checkbox editors. */
	const taxonomyOptions = spreadEmData.taxonomies || { categories: [], tags: [] };

	/** Live-session configuration and state. */
	const liveConfig = spreadEmData.live || { enabled: false };
	const currentUser = spreadEmData.current_user || { id: 0, name: '' };
	let liveVersion = 0;
	let livePollTimer = null;
	let isApplyingRemote = false;
	let activePresence = {};
	let directMessages = [];
	let openImUserId = 0;

	/** -----------------------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------------------- */

	$( document ).ready( function () {
		originalData = JSON.parse( JSON.stringify( spreadEmData.products ) );
		currentData  = JSON.parse( JSON.stringify( spreadEmData.products ) );

		buildTable();
		initColumnLayout();
		syncColumnWidthsForContent();

		$( '#spread-em-loading' ).hide();
		$( '#spread-em-table-wrap' ).show();

		// Button handlers.
		$( '#spread-em-save' ).on( 'click', handleSave );
		$( '#spread-em-undo' ).on( 'click', handleUndo );

		if ( liveConfig.enabled ) {
			if ( ! $( '#spread-em-live-presence' ).length ) {
				$( '#spread-em-status' ).after( '<span id="spread-em-live-presence" class="spread-em-status spread-em-status--info"></span>' );
			}
			if ( liveConfig.full_workspace ) {
				mountImWindow();
			}
			startLiveSessionSync();
		}

		// Hide/show parent product rows.
		$( '#spread-em-hide-parents' ).on( 'change', function () {
			$( '#spread-em-tbody tr.spread-em-parent-row' ).toggle( ! this.checked );
			syncColumnWidthsForContent();
		} );

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
		syncColumnWidthsForContent();
	}

	function buildHeader() {
		const $thead    = $( '#spread-em-thead' );
		const $colgroup = $( '#spread-em-colgroup' );
		$thead.empty();
		$colgroup.empty();

		const savedWidths = loadColumnWidths();

		// Column header row.
		const $tr = $( '<tr>' );
		columns.forEach( function ( col ) {
			const $th = $( '<th>' )
				.text( col.label )
				.attr( 'data-key', col.key )
				.css( 'position', 'relative' );
			if ( col.readonly ) {
				$th.addClass( 'spread-em-readonly' );
			}

			// Drag-to-resize handle anchored to the right edge of the header cell.
			const $handle = $( '<div>' ).addClass( 'spread-em-col-resize-handle' );
			$handle.on( 'mousedown', function ( e ) {
				e.preventDefault();
				const startX = e.pageX;
				const $col   = $colgroup.find( 'col[data-key="' + col.key + '"]' );
				const startW = parseInt( $col.css( 'width' ), 10 ) || $th.outerWidth();
				$( 'body' ).addClass( 'spread-em-col-resizing' );
				$( document )
					.on( 'mousemove.colresize', function ( me ) {
						const newW = Math.max( 40, startW + me.pageX - startX );
						$col.css( 'width', newW + 'px' );
					} )
					.on( 'mouseup.colresize', function () {
						$( document ).off( '.colresize' );
						$( 'body' ).removeClass( 'spread-em-col-resizing' );
						saveColumnWidths();
					} );
			} );
			$th.append( $handle );
			$tr.append( $th );

			// Matching <col> element – carries the authoritative column width.
			const $col = $( '<col>' ).attr( 'data-key', col.key );
			if ( savedWidths[ col.key ] ) {
				$col.css( 'width', savedWidths[ col.key ] + 'px' );
			}
			$colgroup.append( $col );
		} );
		$thead.append( $tr );

		// Column override row – input + Apply button per editable column.
		const $overrideRow = $( '<tr>' ).addClass( 'spread-em-override-row' );
		columns.forEach( function ( col ) {
			const $td = $( '<td>' ).attr( 'data-key', col.key );

			if ( ! col.readonly ) {
				const selectOptions = getSelectOptions( col.key );
				let $input;
				let getOverrideValue;

				if ( selectOptions ) {
					$input = $( '<select>' ).addClass( 'spread-em-override-input' );
					$input.append( $( '<option>' ).val( '' ).text( '— override —' ) );
					selectOptions.forEach( function ( opt ) {
						$input.append( $( '<option>' ).val( opt.value ).text( opt.label ) );
					} );
					getOverrideValue = function () {
						return String( $input.val() || '' );
					};
				} else if ( isTaxonomyColumn( col.key ) ) {
					const picker = createTaxonomyPicker( col.key, '', 'spread-em-override-input' );
					$input = picker.$el;
					getOverrideValue = picker.getValue;
				} else {
					$input = $( '<input>' )
						.attr( { type: 'text', placeholder: 'Override all…' } )
						.addClass( 'spread-em-override-input' );
					getOverrideValue = function () {
						return String( $input.val() || '' );
					};
				}

				const $btn = $( '<button>' )
					.text( '↓' )
					.attr( 'title', 'Apply to all visible rows' )
					.addClass( 'spread-em-override-btn' )
					.on( 'click', ( function ( colKey, getter ) {
						return function () {
							const val = getter();
							if ( '' === val && ! isTaxonomyColumn( colKey ) ) { return; }
							applyColumnOverride( colKey, val );
						};
					} )( col.key, getOverrideValue ) );

				// Also apply on Enter key (text/select inputs).
				$input.on( 'keydown', ( function ( colKey, getter ) {
					return function ( e ) {
						if ( 13 === e.which ) {
							const val = getter();
							if ( '' === val && ! isTaxonomyColumn( colKey ) ) { return; }
							applyColumnOverride( colKey, val );
						}
					};
				} )( col.key, getOverrideValue ) );

				$td.append( $input ).append( $btn );
			}

			$overrideRow.append( $td );
		} );
		$thead.append( $overrideRow );
	}

	/**
	 * Apply a single value to every visible row for a given column key.
	 *
	 * @param {string} colKey Column key.
	 * @param {string} val    Value to apply.
	 */
	function applyColumnOverride( colKey, val ) {
		$( '#spread-em-tbody tr:visible' ).each( function () {
			const rowIndex = parseInt( $( this ).attr( 'data-row' ), 10 );
			const oldVal   = String( currentData[ rowIndex ][ colKey ] !== undefined ? currentData[ rowIndex ][ colKey ] : '' );

			if ( oldVal === String( val ) ) { return; }

			const $cell  = $( this ).find( 'td[data-key="' + colKey + '"]' );
			const picker = $cell.find( '.spread-em-taxonomy-picker' ).data( 'picker' );
			const $inp   = $cell.find( 'input.spread-em-cell-input' );
			const $sel   = $cell.find( 'select.spread-em-cell-select' );

			if ( picker && typeof picker.setValue === 'function' ) {
				picker.setValue( String( val ) );
			} else if ( $inp.length ) {
				$inp.val( val );
			} else if ( $sel.length ) {
				$sel.val( val );
			} else {
				return; // readonly cell – skip
			}

			recordChange( rowIndex, colKey, oldVal, String( val ) );
		} );
	}

	function buildBody() {
		const $tbody = $( '#spread-em-tbody' );
		$tbody.empty();

		currentData.forEach( function ( product, rowIndex ) {
			const $tr = $( '<tr>' )
				.attr( 'data-row', rowIndex )
				.addClass( product.is_variation ? 'spread-em-variation-row' : 'spread-em-parent-row' );

			columns.forEach( function ( col ) {
				const $td = $( '<td>' ).attr( 'data-key', col.key );

				// Variation fields inherited from parent taxonomy/content are readonly.
				const isInherited = product.is_variation && (
					'name' === col.key ||
					'catalog_visibility' === col.key ||
					'short_description' === col.key ||
					'categories' === col.key ||
					'tags' === col.key
				);

				if ( col.readonly || isInherited ) {
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

		if ( isTaxonomyColumn( col.key ) ) {
			let trackedValue = currentValue;
			const picker = createTaxonomyPicker( col.key, currentValue, 'spread-em-cell-input' );

			picker.onSelectionChange( function ( nextVal ) {
				if ( nextVal !== trackedValue ) {
					recordChange( rowIndex, col.key, trackedValue, nextVal );
					trackedValue = nextVal;
				}
			} );

			picker.$input.on( 'focus', function () {
				trackedValue = picker.getValue();
			} );

			picker.$input.on( 'blur', function () {
				const newVal = picker.getValue();
				if ( newVal !== trackedValue ) {
					recordChange( rowIndex, col.key, trackedValue, newVal );
					trackedValue = newVal;
				}
			} );

			return picker.$el;
		}

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

	/**
	 * Check whether a column is taxonomy-based and should use checkbox editor.
	 *
	 * @param {string} key Column key.
	 * @returns {boolean}
	 */
	function isTaxonomyColumn( key ) {
		return 'categories' === key || 'tags' === key;
	}

	/**
	 * Build a taxonomy checkbox picker with an editable text representation.
	 *
	 * @param {string} key        Column key (categories|tags).
	 * @param {string} value      Initial CSV value.
	 * @param {string} inputClass Input class name.
	 * @returns {{ $el:jQuery, $input:jQuery, getValue:function, setValue:function, onSelectionChange:function }}
	 */
	function createTaxonomyPicker( key, value, inputClass ) {
		const options = Array.isArray( taxonomyOptions[ key ] ) ? taxonomyOptions[ key ] : [];
		let listeners = [];

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

		if ( liveConfig.enabled && ! isApplyingRemote ) {
			pushLiveCellChange( rowIndex, key, newValue );
		}

		isDirty = true;
		$( '#spread-em-save' ).prop( 'disabled', false );
		$( '#spread-em-undo' ).prop( 'disabled', false );
		markRowDirty( rowIndex );
		clearStatus();
	}

	/** -----------------------------------------------------------------------
	 * Live session sync
	 * ---------------------------------------------------------------------- */

	function startLiveSessionSync() {
		pullLiveState( true );
		const interval = parseInt( liveConfig.poll_interval, 10 ) || 2500;
		livePollTimer = window.setInterval( function () {
			pullLiveState( false );
		}, interval );
	}

	function pushLiveCellChange( rowIndex, key, value ) {
		const row = currentData[ rowIndex ];

		if ( ! row || ! row.id ) {
			return;
		}

		$.post(
			spreadEmData.ajax_url,
			{
				action: 'spread_em_live_push',
				nonce: liveConfig.nonce,
				session_id: liveConfig.session_id,
				product_id: row.id,
				key: key,
				value: value,
			}
		).done( function ( response ) {
			if ( response && response.success && response.data && response.data.version ) {
				liveVersion = Math.max( liveVersion, parseInt( response.data.version, 10 ) || 0 );
			}
		} );
	}

	function pullLiveState( force ) {
		$.post(
			spreadEmData.ajax_url,
			{
				action: 'spread_em_live_pull',
				nonce: liveConfig.nonce,
				session_id: liveConfig.session_id,
				since_version: force ? 0 : liveVersion,
			}
		).done( function ( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				return;
			}

			liveVersion = Math.max( liveVersion, parseInt( response.data.version, 10 ) || 0 );
			activePresence = response.data.presence || {};
			directMessages = response.data.direct_messages || [];
			updateLivePresence( activePresence );
			renderImWindow();

			if ( response.data.has_updates && response.data.cells ) {
				applyRemoteCells( response.data.cells );
			}
		} );
	}

	function applyRemoteCells( cellMap ) {
		isApplyingRemote = true;

		Object.keys( cellMap ).forEach( function ( productIdKey ) {
			const productId = parseInt( productIdKey, 10 );
			if ( Number.isNaN( productId ) ) {
				return;
			}

			const rowIndex = findRowIndexByProductId( productId );
			if ( rowIndex < 0 ) {
				return;
			}

			const updates = cellMap[ productIdKey ] || {};

			Object.keys( updates ).forEach( function ( key ) {
				const nextVal = String( updates[ key ] );
				const prevVal = currentData[ rowIndex ][ key ] !== undefined ? String( currentData[ rowIndex ][ key ] ) : '';

				if ( nextVal === prevVal ) {
					return;
				}

				currentData[ rowIndex ][ key ] = nextVal;
				patchCellDomValue( rowIndex, key, nextVal );
				markRowDirty( rowIndex );
				isDirty = true;
			} );
		} );

		isApplyingRemote = false;
	}

	function findRowIndexByProductId( productId ) {
		for ( let i = 0; i < currentData.length; i++ ) {
			if ( parseInt( currentData[ i ].id, 10 ) === productId ) {
				return i;
			}
		}

		return -1;
	}

	function patchCellDomValue( rowIndex, key, value ) {
		const $cell = $( '#spread-em-tbody tr[data-row="' + rowIndex + '"] td[data-key="' + key + '"]' );

		if ( ! $cell.length || $cell.find( ':focus' ).length ) {
			return;
		}

		const picker = $cell.find( '.spread-em-taxonomy-picker' ).data( 'picker' );
		const $input = $cell.find( 'input.spread-em-cell-input' );
		const $select = $cell.find( 'select.spread-em-cell-select' );

		if ( picker && typeof picker.setValue === 'function' ) {
			picker.setValue( value );
			return;
		}

		if ( $input.length ) {
			$input.val( value );
			return;
		}

		if ( $select.length ) {
			$select.val( value );
			return;
		}

		$cell.text( value );
	}

	function updateLivePresence( presenceMap ) {
		const names = [];

		Object.keys( presenceMap ).forEach( function ( userIdKey ) {
			const entry = presenceMap[ userIdKey ];
			if ( ! entry || ! entry.name ) {
				return;
			}

			names.push( String( entry.name ) );
		} );

		if ( ! names.length && currentUser.name ) {
			names.push( String( currentUser.name ) );
		}

		const label = names.length
			? 'Live editors: ' + names.join( ', ' )
			: 'Live session connected';

		$( '#spread-em-live-presence' ).text( label );
	}

	function mountImWindow() {
		if ( $( '#spread-em-im-window' ).length ) {
			return;
		}

		const html = [
			'<div id="spread-em-im-window" class="spread-em-im-window">',
				'<div class="spread-em-im-header">' + escapeHtml( spreadEmData.i18n.im_title ) + '</div>',
				'<div class="spread-em-im-body">',
					'<div class="spread-em-im-users">',
						'<div class="spread-em-im-users-title">' + escapeHtml( spreadEmData.i18n.im_active_users ) + '</div>',
						'<div id="spread-em-im-users-list"></div>',
					'</div>',
					'<div class="spread-em-im-chat">',
						'<div id="spread-em-im-thread" class="spread-em-im-thread"></div>',
						'<div class="spread-em-im-compose">',
							'<input type="text" id="spread-em-im-input" class="spread-em-im-input" placeholder="' + escapeHtml( spreadEmData.i18n.im_placeholder ) + '">',
							'<button type="button" id="spread-em-im-send" class="button button-primary">' + escapeHtml( spreadEmData.i18n.im_send ) + '</button>',
						'</div>',
					'</div>',
				'</div>',
			'</div>'
		].join( '' );

		$( 'body' ).append( html );

		$( '#spread-em-im-send' ).on( 'click', sendDirectMessage );
		$( '#spread-em-im-input' ).on( 'keydown', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				sendDirectMessage();
			}
		} );
	}

	function renderImWindow() {
		if ( ! liveConfig.full_workspace || ! $( '#spread-em-im-window' ).length ) {
			return;
		}

		const $usersList = $( '#spread-em-im-users-list' );
		$usersList.empty();

		const userIds = Object.keys( activePresence ).filter( function ( userIdKey ) {
			return parseInt( userIdKey, 10 ) !== parseInt( currentUser.id, 10 );
		} );

		if ( ! userIds.length ) {
			$usersList.append( '<div class="spread-em-im-empty">' + escapeHtml( spreadEmData.i18n.im_no_active_users ) + '</div>' );
		} else {
			userIds.forEach( function ( userIdKey ) {
				const userId = parseInt( userIdKey, 10 );
				const entry = activePresence[ userIdKey ] || {};
				const isActive = openImUserId === userId;
				const $button = $( '<button type="button" class="spread-em-im-user button"></button>' )
					.text( entry.name || ( 'User #' + userId ) )
					.toggleClass( 'spread-em-im-user--active', isActive )
					.on( 'click', function () {
						openImUserId = userId;
						renderImWindow();
					} );

				$usersList.append( $button );
			} );
		}

		if ( !openImUserId && userIds.length ) {
			openImUserId = parseInt( userIds[ 0 ], 10 );
		}

		renderImThread();
	}

	function renderImThread() {
		const $thread = $( '#spread-em-im-thread' );
		if ( !$thread.length ) {
			return;
		}

		$thread.empty();

		if ( !openImUserId ) {
			$thread.append( '<div class="spread-em-im-empty">' + escapeHtml( spreadEmData.i18n.im_no_active_users ) + '</div>' );
			return;
		}

		const threadMessages = directMessages.filter( function (message) {
			const fromId = parseInt( message.from_user_id, 10 );
			const toId = parseInt( message.to_user_id, 10 );
			const me = parseInt( currentUser.id, 10 );
			return ( fromId === me && toId === openImUserId ) || ( fromId === openImUserId && toId === me );
		} );

		threadMessages.forEach( function (message) {
			const mine = parseInt( message.from_user_id, 10 ) === parseInt( currentUser.id, 10 );
			const $message = $( '<div class="spread-em-im-message"></div>' ).toggleClass( 'spread-em-im-message--mine', mine );
			$message.append( $( '<div class="spread-em-im-message-author"></div>' ).text( mine ? currentUser.name : ( message.from_name || 'User' ) ) );
			$message.append( $( '<div class="spread-em-im-message-body"></div>' ).text( message.message || '' ) );
			$thread.append( $message );
		} );

		$thread.scrollTop( $thread.prop( 'scrollHeight' ) );
	}

	function sendDirectMessage() {
		const message = String( $( '#spread-em-im-input' ).val() || '' ).trim();

		if ( !openImUserId || !message ) {
			return;
		}

		$.post(
			spreadEmData.ajax_url,
			{
				action: 'spread_em_live_im_send',
				nonce: liveConfig.nonce,
				session_id: liveConfig.session_id,
				to_user_id: openImUserId,
				message: message,
			}
		).done( function ( response ) {
			if ( response && response.success ) {
				$( '#spread-em-im-input' ).val( '' );
				pullLiveState( true );
			}
		} );
	}

	function escapeHtml( value ) {
		return String( value || '' )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
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
				context_parent_ids: JSON.stringify( getCurrentParentIds() ),
			},
		} )
			.done( function ( response ) {
				if ( response.success ) {
					if ( response.data && Array.isArray( response.data.products ) && response.data.products.length ) {
						currentData = JSON.parse( JSON.stringify( response.data.products ) );
					}

					// Accept all saved changes into the baseline.
					originalData = JSON.parse( JSON.stringify( currentData ) );
					lastChange   = null;
					isDirty      = false;

					// Rebuild table so inherited variation fields are refreshed.
					buildTable();
					if ( $( '#spread-em-hide-parents' ).is( ':checked' ) ) {
						$( '#spread-em-tbody tr.spread-em-parent-row' ).hide();
					}
					syncColumnWidthsForContent();

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
	 * Gather currently loaded parent IDs for post-save refresh context.
	 *
	 * @returns {Array<number>}
	 */
	function getCurrentParentIds() {
		const ids = [];

		currentData.forEach( function ( row ) {
			if ( ! row.is_variation && row.id ) {
				ids.push( parseInt( row.id, 10 ) );
			}
		} );

		return Array.from( new Set( ids.filter( function ( id ) {
			return ! Number.isNaN( id ) && id > 0;
		} ) ) );
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
	 * Column resize helpers
	 * ---------------------------------------------------------------------- */

	const COL_WIDTHS_KEY = 'spreadEmColWidths';

	/**
	 * Load persisted column widths from localStorage.
	 *
	 * @returns {Object} Map of column key → pixel width.
	 */
	function loadColumnWidths() {
		try {
			return JSON.parse( localStorage.getItem( COL_WIDTHS_KEY ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	/**
	 * Persist current <col> widths to localStorage.
	 */
	function saveColumnWidths() {
		const widths = {};
		$( '#spread-em-colgroup col' ).each( function () {
			const key = $( this ).attr( 'data-key' );
			const w   = parseInt( $( this ).css( 'width' ), 10 );
			if ( key && w > 0 ) {
				widths[ key ] = w;
			}
		} );
		try {
			localStorage.setItem( COL_WIDTHS_KEY, JSON.stringify( widths ) );
		} catch ( e ) {}
	}

	/**
	 * Lock the table into fixed layout using the initially rendered widths as
	 * the baseline (or previously saved widths if the user has already resized).
	 *
	 * Called once after the first buildTable() in document.ready.  Subsequent
	 * buildTable() calls (post-save rebuild) restore from localStorage via
	 * buildHeader(), so no second call is needed.
	 */
	function initColumnLayout() {
		$( '#spread-em-table' ).css( 'table-layout', 'fixed' );

		if ( ! Object.keys( loadColumnWidths() ).length ) {
			// First visit – capture rendered widths as the baseline.
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
	}

	/**
	 * Compute a safe minimum width for each column so controls do not overlap.
	 * Adds extra room to the first visible column when variation rows are shown,
	 * because those rows are visually indented.
	 *
	 * @param {string}  key                  Column key.
	 * @param {number}  index                Column index.
	 * @param {boolean} hasVisibleVariations Whether variation rows are visible.
	 * @returns {number}
	 */
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

	/**
	 * Ensure current column widths are never narrower than overlap-safe floors.
	 * This keeps dynamic sizing while preventing control collisions.
	 */
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
