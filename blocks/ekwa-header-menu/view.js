/**
 * Ekwa Header Menu — keyboard / click-outside accessibility helper.
 *
 * Hover behaviour is handled in CSS. This script adds:
 *   - Keyboard support: ArrowDown / Enter opens a submenu; Escape closes it.
 *   - Tap support on touch devices that emulate hover poorly.
 *   - Click-outside dismissal.
 */
( function () {
	'use strict';

	function init( nav ) {
		var openClass = 'is-open';
		var topItems  = nav.querySelectorAll( '.ekwa-header-menu > .menu-item-has-children' );

		function closeAll( exceptItem ) {
			topItems.forEach( function ( item ) {
				if ( item !== exceptItem ) {
					item.classList.remove( openClass );
					var trigger = item.querySelector( ':scope > a' );
					if ( trigger ) {
						trigger.setAttribute( 'aria-expanded', 'false' );
					}
				}
			} );
		}

		topItems.forEach( function ( item ) {
			var trigger = item.querySelector( ':scope > a' );
			if ( ! trigger ) { return; }

			// Toggle on Enter/Space when the link is just '#'.
			trigger.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'ArrowDown' ) {
					e.preventDefault();
					item.classList.add( openClass );
					trigger.setAttribute( 'aria-expanded', 'true' );
					closeAll( item );
					var firstSubLink = item.querySelector( '.sub-menu a, .ekwa-megamenu a' );
					if ( firstSubLink ) { firstSubLink.focus(); }
				} else if ( e.key === 'Escape' ) {
					item.classList.remove( openClass );
					trigger.setAttribute( 'aria-expanded', 'false' );
					trigger.focus();
				}
			} );

			// Tap-to-toggle on touch when href is '#' — preserves real navigation otherwise.
			trigger.addEventListener( 'click', function ( e ) {
				var href = trigger.getAttribute( 'href' );
				if ( ! href || href === '#' ) {
					e.preventDefault();
					var willOpen = ! item.classList.contains( openClass );
					closeAll();
					item.classList.toggle( openClass, willOpen );
					trigger.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
				}
			} );
		} );

		// Escape key on any nested element closes the open dropdown.
		nav.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				closeAll();
			}
		} );

		// Click outside closes everything.
		document.addEventListener( 'click', function ( e ) {
			if ( ! nav.contains( e.target ) ) {
				closeAll();
			}
		} );
	}

	function bootstrap() {
		document.querySelectorAll( '.ekwa-header-nav' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bootstrap );
	} else {
		bootstrap();
	}
} )();
