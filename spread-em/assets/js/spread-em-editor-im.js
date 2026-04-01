/**
 * Spread Em live IM UI module.
 *
 * Handles rendering and interaction for the floating IM window.
 *
 * @package SpreadEm
 */
/* global jQuery */

( function ( $ ) {
	'use strict';

	const state = {
		initialized: false,
		liveConfig: {},
		currentUser: { id: 0, name: '' },
		ajaxUrl: '',
		i18n: {},
		onMessageSent: null,
		activePresence: {},
		directMessages: [],
		openImUserId: 0,
	};

	window.SpreadEmEditorIM = {
		init: init,
		update: update,
		openThreadWithUser: openThreadWithUser,
	};

	function init( config ) {
		if ( state.initialized ) {
			return;
		}

		state.liveConfig = config.liveConfig || {};
		state.currentUser = config.currentUser || state.currentUser;
		state.ajaxUrl = config.ajaxUrl || '';
		state.i18n = config.i18n || {};
		state.onMessageSent = typeof config.onMessageSent === 'function' ? config.onMessageSent : null;

		mountWindow();
		state.initialized = true;
	}

	function update( presenceMap, messages ) {
		if ( ! state.initialized ) {
			return;
		}

		state.activePresence = presenceMap || {};
		state.directMessages = Array.isArray( messages ) ? messages : [];

		renderWindow();
	}

	function mountWindow() {
		if ( $( '#spread-em-im-window' ).length ) {
			return;
		}

		const html = [
			'<div id="spread-em-im-window" class="spread-em-im-window">',
				'<div class="spread-em-im-header">' + escapeHtml( state.i18n.im_title ) + '</div>',
				'<div class="spread-em-im-body">',
					'<div class="spread-em-im-users">',
						'<div class="spread-em-im-users-title">' + escapeHtml( state.i18n.im_active_users ) + '</div>',
						'<div id="spread-em-im-users-list"></div>',
					'</div>',
					'<div class="spread-em-im-chat">',
						'<div id="spread-em-im-thread" class="spread-em-im-thread"></div>',
						'<div class="spread-em-im-compose">',
							'<input type="text" id="spread-em-im-input" class="spread-em-im-input" placeholder="' + escapeHtml( state.i18n.im_placeholder ) + '">',
							'<button type="button" id="spread-em-im-send" class="button button-primary">' + escapeHtml( state.i18n.im_send ) + '</button>',
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

	function renderWindow() {
		const $usersList = $( '#spread-em-im-users-list' );
		$usersList.empty();

		const userIds = Object.keys( state.activePresence ).filter( function ( userIdKey ) {
			return parseInt( userIdKey, 10 ) !== parseInt( state.currentUser.id, 10 );
		} );

		if ( ! userIds.length ) {
			$usersList.append( '<div class="spread-em-im-empty">' + escapeHtml( state.i18n.im_no_active_users ) + '</div>' );
		} else {
			userIds.forEach( function ( userIdKey ) {
				const userId = parseInt( userIdKey, 10 );
				const entry = state.activePresence[ userIdKey ] || {};
				const isActive = state.openImUserId === userId;
				const $button = $( '<button type="button" class="spread-em-im-user button"></button>' )
					.text( entry.name || ( 'User #' + userId ) )
					.toggleClass( 'spread-em-im-user--active', isActive )
					.on( 'click', function () {
						state.openImUserId = userId;
						renderWindow();
					} );

				$usersList.append( $button );
			} );
		}

		if ( ! state.openImUserId && userIds.length ) {
			state.openImUserId = parseInt( userIds[ 0 ], 10 );
		}

		renderThread();
	}

	function renderThread() {
		const $thread = $( '#spread-em-im-thread' );
		if ( ! $thread.length ) {
			return;
		}

		$thread.empty();

		if ( ! state.openImUserId ) {
			$thread.append( '<div class="spread-em-im-empty">' + escapeHtml( state.i18n.im_no_active_users ) + '</div>' );
			return;
		}

		const threadMessages = state.directMessages.filter( function ( message ) {
			const fromId = parseInt( message.from_user_id, 10 );
			const toId = parseInt( message.to_user_id, 10 );
			const me = parseInt( state.currentUser.id, 10 );
			return ( fromId === me && toId === state.openImUserId ) || ( fromId === state.openImUserId && toId === me );
		} );

		threadMessages.forEach( function ( message ) {
			const mine = parseInt( message.from_user_id, 10 ) === parseInt( state.currentUser.id, 10 );
			const $message = $( '<div class="spread-em-im-message"></div>' ).toggleClass( 'spread-em-im-message--mine', mine );
			$message.append( $( '<div class="spread-em-im-message-author"></div>' ).text( mine ? state.currentUser.name : ( message.from_name || 'User' ) ) );
			$message.append( $( '<div class="spread-em-im-message-body"></div>' ).text( message.message || '' ) );
			$thread.append( $message );
		} );

		$thread.scrollTop( $thread.prop( 'scrollHeight' ) );
	}

	function openThreadWithUser( userId ) {
		if ( ! state.initialized ) {
			return;
		}

		const nextUserId = parseInt( userId, 10 );
		if ( Number.isNaN( nextUserId ) || nextUserId <= 0 ) {
			return;
		}

		state.openImUserId = nextUserId;
		renderWindow();

		const $input = $( '#spread-em-im-input' );
		if ( $input.length ) {
			$input.trigger( 'focus' );
		}
	}

	function sendDirectMessage() {
		const message = String( $( '#spread-em-im-input' ).val() || '' ).trim();

		if ( ! state.openImUserId || ! message ) {
			return;
		}

		$.post(
			state.ajaxUrl,
			{
				action: 'spread_em_live_im_send',
				nonce: state.liveConfig.nonce,
				session_id: state.liveConfig.session_id,
				to_user_id: state.openImUserId,
				message: message,
			}
		).done( function ( response ) {
			if ( response && response.success ) {
				$( '#spread-em-im-input' ).val( '' );
				if ( state.onMessageSent ) {
					state.onMessageSent();
				}
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
}( jQuery ) );
