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

		// Flyouts open on :hover OR :focus-within (see style.css). After a click,
		// the focused link keeps its flyout open via :focus-within, so hovering a
		// sibling would open a second flyout while the first lingers — they
		// overlap. When the pointer moves onto a different menu link, drop the
		// stale focus so hover stays authoritative. Keyboard users never fire
		// mouseover, so focus navigation is unaffected.
		nav.addEventListener( 'mouseover', function ( e ) {
			var link = e.target && e.target.closest ? e.target.closest( 'a' ) : null;
			if ( ! link || ! nav.contains( link ) ) { return; }
			var active = document.activeElement;
			if ( active && active.tagName === 'A' && active !== link && nav.contains( active ) ) {
				active.blur();
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
