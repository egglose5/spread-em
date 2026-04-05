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
	const fieldHelpers = window.SpreadEmEditorFields || {
		getSelectOptions: function () { return null; },
		isTaxonomyColumn: function () { return false; },
		createTaxonomyPicker: function ( key, value, inputClass ) {
			const $input = $( '<input>' )
				.attr( { type: 'text', value: value !== undefined && value !== null ? String( value ) : '' } )
				.addClass( inputClass );
			return {
				$el: $input,
				$input: $input,
				getValue: function () {
					return String( $input.val() || '' );
				},
				setValue: function ( next ) {
					$input.val( String( next || '' ) );
				},
				onSelectionChange: function () {},
			};
		},
	};
	const layoutHelpers = window.SpreadEmEditorLayout || {
		initColumnLayout: function () {},
		syncColumnWidthsForContent: function () {},
		loadColumnWidths: function () { return {}; },
		saveColumnWidths: function () {},
	};

	/** Live-session configuration and state. */
	const liveConfig = spreadEmData.live || { enabled: false };
	const currentUser = spreadEmData.current_user || { id: 0, name: '' };
	let liveVersion = 0;
	let draftToken = 0;
	let livePollTimer = null;
	let saveDraftTimer = null;
	let isApplyingRemote = false;
	let activePresence = {};
	let directMessages = [];
	let rowOwners = {};
	let liveActivity = [];
	const claimedRowsByMe = {};

	/** -----------------------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------------------- */

	$( document ).ready( function () {
		if ( ! window.SpreadEmEditorFields || ! window.SpreadEmEditorLayout ) {
			showStatus( spreadEmData.i18n.module_missing || 'Some editor modules failed to load. Limited fallback mode is active.', 'error' );
		}

		originalData = JSON.parse( JSON.stringify( spreadEmData.products ) );
		currentData  = JSON.parse( JSON.stringify( spreadEmData.products ) );

		buildTable();
		layoutHelpers.initColumnLayout();
		layoutHelpers.syncColumnWidthsForContent();

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
				initGlobalOperatorConsole();
			}
			if ( liveConfig.full_workspace && window.SpreadEmEditorIM ) {
				window.SpreadEmEditorIM.init( {
					liveConfig: liveConfig,
					currentUser: currentUser,
					ajaxUrl: spreadEmData.ajax_url,
					i18n: spreadEmData.i18n || {},
					onMessageSent: function () {
						pullLiveState( true );
					},
				} );
			}
			startLiveSessionSync();
		}

		// Hide/show parent product rows.
		$( '#spread-em-hide-parents' ).on( 'change', function () {
			$( '#spread-em-tbody tr.spread-em-parent-row' ).toggle( ! this.checked );
			layoutHelpers.syncColumnWidthsForContent();
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
		layoutHelpers.syncColumnWidthsForContent();
	}

	function buildHeader() {
		const $thead    = $( '#spread-em-thead' );
		const $colgroup = $( '#spread-em-colgroup' );
		$thead.empty();
		$colgroup.empty();

		const savedWidths = layoutHelpers.loadColumnWidths();

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
						layoutHelpers.saveColumnWidths();
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
				const selectOptions = fieldHelpers.getSelectOptions( col.key );
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
				} else if ( fieldHelpers.isTaxonomyColumn( col.key ) ) {
					const picker = fieldHelpers.createTaxonomyPicker( col.key, '', 'spread-em-override-input' );
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
							if ( '' === val && ! fieldHelpers.isTaxonomyColumn( colKey ) ) { return; }
							applyColumnOverride( colKey, val );
						};
					} )( col.key, getOverrideValue ) );

				// Also apply on Enter key (text/select inputs).
				$input.on( 'keydown', ( function ( colKey, getter ) {
					return function ( e ) {
						if ( 13 === e.which ) {
							const val = getter();
							if ( '' === val && ! fieldHelpers.isTaxonomyColumn( colKey ) ) { return; }
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

		if ( fieldHelpers.isTaxonomyColumn( col.key ) ) {
			let trackedValue = currentValue;
			const picker = fieldHelpers.createTaxonomyPicker( col.key, currentValue, 'spread-em-cell-input' );

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
		const selectOptions = fieldHelpers.getSelectOptions( col.key );

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
			pushLiveRowClaim( rowIndex );
			pushLiveCellChange( rowIndex, key, newValue );
			saveDraft( rowIndex, key, newValue );
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
		// Initial full fetch.
		pullLiveState( true );
		scheduleDraftPoll();

		// Adjust poll cadence whenever the tab visibility changes.
		document.addEventListener( 'visibilitychange', function () {
			if ( livePollTimer ) {
				clearTimeout( livePollTimer );
				livePollTimer = null;
			}
			scheduleDraftPoll();
		} );
	}

	/**
	 * Schedule the next draft poll with adaptive interval and jitter.
	 *
	 * When the document is hidden the interval grows to poll_hidden_interval
	 * (default 30 s) to avoid unnecessary server load on shared hosting.
	 * A ±10 % jitter prevents thundering-herd effects when multiple tabs
	 * or users happen to open the editor simultaneously.
	 */
	function scheduleDraftPoll() {
		const baseInterval   = parseInt( liveConfig.poll_interval, 10 ) || 10000;
		const hiddenInterval = parseInt( liveConfig.poll_hidden_interval, 10 ) || 30000;
		const interval       = document.hidden ? hiddenInterval : baseInterval;
		const jitter         = Math.floor( ( Math.random() - 0.5 ) * 0.2 * interval );
		const delay          = Math.max( 1000, interval + jitter );

		livePollTimer = window.setTimeout( function () {
			pollDraft( false );
			scheduleDraftPoll();
		}, delay );
	}

	/**
	 * Generate a RFC-4122 v4 UUID used as client_request_id for idempotency.
	 *
	 * @returns {string}
	 */
	function generateUUID() {
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			const r = Math.random() * 16 | 0;
			const v = 'x' === c ? r : ( r & 0x3 | 0x8 );
			return v.toString( 16 );
		} );
	}

	/**
	 * Debounced draft save.
	 *
	 * Each call resets a 500 ms timer so rapid keystrokes collapse into a
	 * single AJAX request.  A stable client_request_id is captured at the
	 * moment the timer fires so retries on network failure are idempotent.
	 *
	 * @param {number} rowIndex  Row index in currentData.
	 * @param {string} key       Column key.
	 * @param {string} value     New cell value.
	 */
	function saveDraft( rowIndex, key, value ) {
		const row = currentData[ rowIndex ];

		if ( ! row || ! row.id ) {
			return;
		}

		const productId = parseInt( row.id, 10 );
		if ( Number.isNaN( productId ) || productId <= 0 ) {
			return;
		}

		// Reset debounce timer on every change within the window.
		if ( saveDraftTimer ) {
			clearTimeout( saveDraftTimer );
		}

		// Capture a stable UUID for this debounced batch.
		const clientRequestId = generateUUID();

		saveDraftTimer = window.setTimeout( function () {
			saveDraftTimer = null;
			$.post(
				spreadEmData.ajax_url,
				{
					action:            'spread_em_save_draft',
					nonce:             liveConfig.nonce,
					session_id:        liveConfig.session_id,
					product_id:        productId,
					key:               key,
					value:             value,
					client_request_id: clientRequestId,
				}
			).done( function ( response ) {
				if ( response && response.success && response.data && response.data.token !== undefined ) {
					draftToken = Math.max( draftToken, parseInt( response.data.token, 10 ) || 0 );
				}
			} );
		}, parseInt( liveConfig.debounce_ms, 10 ) || 500 );
	}

	/**
	 * Poll the server for draft cell deltas since the last known token.
	 *
	 * When the server version equals the client token the response is a tiny
	 * JSON object with has_updates=false and no further processing occurs,
	 * making this cheap on shared hosting.
	 *
	 * @param {boolean} force  Pass true to request all deltas from version 0.
	 */
	function pollDraft( force ) {
		$.post(
			spreadEmData.ajax_url,
			{
				action:      'spread_em_poll_draft',
				nonce:       liveConfig.nonce,
				session_id:  liveConfig.session_id,
				since_token: force ? 0 : draftToken,
			}
		).done( function ( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				return;
			}

			const data = response.data;
			draftToken = Math.max( draftToken, parseInt( data.token, 10 ) || 0 );

			if ( data.has_updates && data.cells ) {
				applyRemoteCells( data.cells );
			}
		} );
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
				mode: 'update',
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

	function pushLiveRowClaim( rowIndex ) {
		const row = currentData[ rowIndex ];

		if ( ! row || ! row.id ) {
			return;
		}

		const productId = parseInt( row.id, 10 );
		if ( Number.isNaN( productId ) || productId <= 0 ) {
			return;
		}

		const now = Date.now();
		const lastClaimAt = claimedRowsByMe[ productId ] || 0;

		// Avoid flooding claim events while a user is actively typing on one row.
		if ( now - lastClaimAt < 12000 ) {
			return;
		}

		claimedRowsByMe[ productId ] = now;

		$.post(
			spreadEmData.ajax_url,
			{
				action: 'spread_em_live_push',
				nonce: liveConfig.nonce,
				session_id: liveConfig.session_id,
				mode: 'claim',
				product_id: productId,
			}
		);
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
			rowOwners = response.data.row_owners || {};
			liveActivity = response.data.activity || [];
			updateLivePresence( activePresence );
			if ( liveConfig.full_workspace ) {
				updateGlobalOperatorConsole( activePresence, rowOwners, liveActivity );
			}
			if ( liveConfig.full_workspace && window.SpreadEmEditorIM ) {
				window.SpreadEmEditorIM.update( activePresence, directMessages );
			}

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

	function initGlobalOperatorConsole() {
		if ( $( '#spread-em-operator-console' ).length ) {
			return;
		}

		const i18n = spreadEmData.i18n || {};
		const html = [
			'<section id="spread-em-operator-console" class="spread-em-operator-console" aria-live="polite">',
				'<div class="spread-em-operator-console__header">' + escapeHtml( i18n.operator_activity_title || 'Live Operator Console' ) + '</div>',
				'<div class="spread-em-operator-console__body">',
					'<div class="spread-em-operator-console__column">',
						'<h2 class="spread-em-operator-console__title">' + escapeHtml( i18n.operator_activity_active || 'Active editors' ) + '</h2>',
						'<div id="spread-em-operator-users" class="spread-em-operator-console__users"></div>',
					'</div>',
					'<div class="spread-em-operator-console__column">',
						'<h2 class="spread-em-operator-console__title">' + escapeHtml( i18n.operator_activity_events || 'Recent activity' ) + '</h2>',
						'<div id="spread-em-operator-events" class="spread-em-operator-console__events"></div>',
					'</div>',
				'</div>',
			'</section>',
		].join( '' );

		$( '#spread-em-toolbar' ).after( html );
	}

	function updateGlobalOperatorConsole( presenceMap, rowOwnerMap, activity ) {
		const $users = $( '#spread-em-operator-users' );
		const $events = $( '#spread-em-operator-events' );

		if ( ! $users.length || ! $events.length ) {
			return;
		}

		const ownerCounts = countOwnedRowsByUser( rowOwnerMap );
		const i18n = spreadEmData.i18n || {};

		$users.empty();
		const userIds = Object.keys( presenceMap || {} );

		if ( ! userIds.length ) {
			$users.append( '<div class="spread-em-operator-console__empty">' + escapeHtml( i18n.operator_activity_none || 'No live activity yet.' ) + '</div>' );
		} else {
			userIds.forEach( function ( userIdKey ) {
				const entry = presenceMap[ userIdKey ] || {};
				const userId = parseInt( userIdKey, 10 );
				const rowCount = ownerCounts[ userId ] || 0;
				const scopeMode = entry.scope_mode === 'global_operator'
					? ( i18n.operator_activity_scope_global || 'Global operator' )
					: ( i18n.operator_activity_scope_contributor || 'Contributor scope' );
				const rowsLabelTemplate = i18n.operator_activity_editing_rows || 'editing %d row(s)';
				const rowsLabel = rowsLabelTemplate.replace( '%d', String( rowCount ) );

				const cardHtml = [
					'<div class="spread-em-operator-user-card">',
						'<div class="spread-em-operator-user-card__name">' + escapeHtml( entry.name || ( 'User #' + userId ) ) + '</div>',
						'<div class="spread-em-operator-user-card__meta">' + escapeHtml( scopeMode ) + '</div>',
						'<div class="spread-em-operator-user-card__meta">' + escapeHtml( rowsLabel ) + '</div>',
						'<button type="button" class="button button-small spread-em-operator-user-card__im" data-user-id="' + userId + '">' + escapeHtml( i18n.im_open || 'Open IM' ) + '</button>',
					'</div>',
				].join( '' );

				$users.append( cardHtml );
			} );

			$users.find( '.spread-em-operator-user-card__im' ).on( 'click', function () {
				const toUserId = parseInt( $( this ).attr( 'data-user-id' ), 10 );
				if ( Number.isNaN( toUserId ) || toUserId <= 0 ) {
					return;
				}

				if ( window.SpreadEmEditorIM && typeof window.SpreadEmEditorIM.openThreadWithUser === 'function' ) {
					window.SpreadEmEditorIM.openThreadWithUser( toUserId );
				}
			} );
		}

		$events.empty();
		const eventsToRender = Array.isArray( activity ) ? activity.slice( -30 ).reverse() : [];

		if ( ! eventsToRender.length ) {
			$events.append( '<div class="spread-em-operator-console__empty">' + escapeHtml( i18n.operator_activity_none || 'No live activity yet.' ) + '</div>' );
			return;
		}

		eventsToRender.forEach( function ( event ) {
			$events.append( formatActivityEventItem( event ) );
		} );
	}

	function countOwnedRowsByUser( rowOwnerMap ) {
		const counts = {};

		Object.keys( rowOwnerMap || {} ).forEach( function ( productIdKey ) {
			const owners = rowOwnerMap[ productIdKey ] || {};
			Object.keys( owners ).forEach( function ( userIdKey ) {
				const userId = parseInt( userIdKey, 10 );
				if ( Number.isNaN( userId ) || userId <= 0 ) {
					return;
				}
				counts[ userId ] = ( counts[ userId ] || 0 ) + 1;
			} );
		} );

		return counts;
	}

	function formatActivityEventItem( event ) {
		const safeEvent = event || {};
		const userName = safeEvent.user_name || 'Someone';
		const productId = parseInt( safeEvent.product_id, 10 );
		const field = safeEvent.field ? String( safeEvent.field ) : '';
		const type = safeEvent.type || 'activity';
		const rows = parseInt( safeEvent.rows, 10 ) || 0;
		const toUserName = safeEvent.to_user_name || '';
		let message = userName + ' updated the workspace';

		if ( type === 'claim' ) {
			message = userName + ' focused row #' + ( Number.isNaN( productId ) ? '?' : productId );
		} else if ( type === 'edit' ) {
			message = userName + ' edited ' + ( field || 'a field' ) + ' on row #' + ( Number.isNaN( productId ) ? '?' : productId );
		} else if ( type === 'save' ) {
			message = userName + ' saved ' + rows + ' row(s)';
		} else if ( type === 'im' ) {
			message = userName + ' messaged ' + ( toUserName || 'another user' );
		}

		return [
			'<div class="spread-em-operator-event">',
				'<div class="spread-em-operator-event__body">' + escapeHtml( message ) + '</div>',
				'<div class="spread-em-operator-event__time">' + escapeHtml( formatEventTime( safeEvent.ts ) ) + '</div>',
			'</div>',
		].join( '' );
	}

	function formatEventTime( unixTs ) {
		const ts = parseInt( unixTs, 10 );
		if ( Number.isNaN( ts ) || ts <= 0 ) {
			return '';
		}

		const now = Math.floor( Date.now() / 1000 );
		const delta = Math.max( 0, now - ts );

		if ( delta < 10 ) {
			return 'just now';
		}
		if ( delta < 60 ) {
			return delta + 's ago';
		}
		if ( delta < 3600 ) {
			return Math.floor( delta / 60 ) + 'm ago';
		}

		return Math.floor( delta / 3600 ) + 'h ago';
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
				live_session_id: liveConfig.enabled ? liveConfig.session_id : '',
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
					layoutHelpers.syncColumnWidthsForContent();

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
