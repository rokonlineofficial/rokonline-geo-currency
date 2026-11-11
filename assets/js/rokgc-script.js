/**
 * Rokonline Geo Currency - frontend script.
 *
 * 1. Manual switching via AJAX (cookie is set server-side, then the page
 *    reloads; a cache-busting ?rokgc_cur= URL is used when needed).
 * 2. Cache-safe GeoIP detection: when no cookie exists the browser asks the
 *    server (admin-ajax is never page-cached) and reloads once if needed.
 * 3. Self-healing: if the cookie and the (possibly cached) HTML disagree, the
 *    page is reloaded once with ?rokgc_cur= to get the right currency.
 */
( function () {
	'use strict';

	var cfg = window.rokgcData;

	if ( ! cfg || ! cfg.ajaxUrl ) {
		return;
	}

	var GUARD_KEY = 'rokgc_redirect_guard';

	function isValid( code ) {
		return !! code && cfg.currencies.indexOf( code ) !== -1;
	}

	function getCookie( name ) {
		var parts = document.cookie ? document.cookie.split( '; ' ) : [];
		for ( var i = 0; i < parts.length; i++ ) {
			var pair = parts[ i ].split( '=' );
			if ( pair[ 0 ] === name ) {
				return decodeURIComponent( pair.slice( 1 ).join( '=' ) );
			}
		}
		return '';
	}

	function setCookie( value ) {
		var expires = new Date();
		expires.setTime( expires.getTime() + ( cfg.days || 30 ) * 864e5 );

		var cookie = cfg.cookieName + '=' + encodeURIComponent( value ) +
			'; expires=' + expires.toUTCString() +
			'; path=' + ( cfg.cookiePath || '/' ) +
			'; SameSite=Lax';

		if ( cfg.cookieDomain ) {
			cookie += '; domain=' + cfg.cookieDomain;
		}
		if ( window.location.protocol === 'https:' ) {
			cookie += '; Secure';
		}

		document.cookie = cookie;
	}

	/**
	 * WooCommerce caches mini-cart HTML in web storage; drop it so the cart
	 * fragments are re-fetched in the new currency.
	 */
	function clearWooStorage() {
		[ window.sessionStorage, window.localStorage ].forEach( function ( store ) {
			try {
				var remove = [];
				for ( var i = 0; i < store.length; i++ ) {
					var key = store.key( i );
					if ( key && ( 0 === key.indexOf( 'wc_fragments_' ) || 0 === key.indexOf( 'wc_cart_hash_' ) ) ) {
						remove.push( key );
					}
				}
				remove.forEach( function ( k ) {
					store.removeItem( k );
				} );
			} catch ( e ) {
				// Storage unavailable: ignore.
			}
		} );
	}

	function setLoading( on ) {
		var nodes = document.querySelectorAll( '[data-rokgc-switcher]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].classList.toggle( 'rokgc-switcher--loading', on );
		}
	}

	function navigate( code, forceParam ) {
		clearWooStorage();

		if ( cfg.cacheMode || forceParam ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'rokgc_cur', code );
			window.location.assign( url.toString() );
		} else {
			window.location.reload();
		}
	}

	function redirectOnce( code ) {
		try {
			if ( window.sessionStorage.getItem( GUARD_KEY ) === code ) {
				return;
			}
			window.sessionStorage.setItem( GUARD_KEY, code );
		} catch ( e ) {
			// No storage: proceed, the server-rendered page will match next time.
		}
		navigate( code, true );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function switchCurrency( code ) {
		if ( ! isValid( code ) ) {
			return;
		}

		setLoading( true );

		post( 'rokgc_switch_currency', { currency: code, nonce: cfg.nonce } )
			.then( function ( res ) {
				setCookie( code );
				// A rejected nonce (stale cached page) falls back to ?rokgc_cur=.
				navigate( code, ! ( res && res.success ) );
			} )
			.catch( function () {
				setCookie( code );
				navigate( code, true );
			} );
	}

	function detect() {
		post( 'rokgc_detect_currency', {} )
			.then( function ( res ) {
				if ( ! res || ! res.success || ! res.data || ! isValid( res.data.currency ) ) {
					return;
				}

				setCookie( res.data.currency );

				if ( res.data.currency !== cfg.rendered ) {
					redirectOnce( res.data.currency );
				}
			} )
			.catch( function () {
				// Detection failed: keep the rendered currency.
			} );
	}

	function syncUi( code ) {
		var selects = document.querySelectorAll( '.rokgc-switcher__select' );
		for ( var i = 0; i < selects.length; i++ ) {
			selects[ i ].value = code;
		}

		var buttons = document.querySelectorAll( '[data-rokgc-currency]' );
		for ( var j = 0; j < buttons.length; j++ ) {
			var active = buttons[ j ].getAttribute( 'data-rokgc-currency' ) === code;
			buttons[ j ].classList.toggle( 'is-active', active );
			buttons[ j ].setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		}
	}

	function cleanUrl() {
		try {
			var url = new URL( window.location.href );
			if ( url.searchParams.has( 'rokgc_cur' ) ) {
				url.searchParams.delete( 'rokgc_cur' );
				window.history.replaceState( null, '', url.toString() );
			}
		} catch ( e ) {
			// Old browser: leave the URL alone.
		}
	}

	function init() {
		var cookie = getCookie( cfg.cookieName );
		var shown = cfg.rendered;

		if ( isValid( cookie ) ) {
			shown = cookie;

			if ( cookie !== cfg.rendered ) {
				// Cached HTML shows another currency than the visitor's choice.
				redirectOnce( cookie );
			} else {
				try {
					window.sessionStorage.removeItem( GUARD_KEY );
				} catch ( e ) {
					// Ignore.
				}
			}
		} else if ( cfg.geoip ) {
			detect();
		}

		if ( cookie === cfg.rendered ) {
			cleanUrl();
		}

		syncUi( shown );

		document.addEventListener( 'change', function ( event ) {
			var target = event.target;
			if ( target && target.classList && target.classList.contains( 'rokgc-switcher__select' ) ) {
				switchCurrency( target.value );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			var button = event.target && event.target.closest ? event.target.closest( '[data-rokgc-currency]' ) : null;
			if ( button ) {
				event.preventDefault();
				switchCurrency( button.getAttribute( 'data-rokgc-currency' ) );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
