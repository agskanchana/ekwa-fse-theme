/**
 * Ekwa FAQ — frontend toggle behaviour.
 *
 * The block renders <details>/<summary>, which already toggles natively.
 * This script adds:
 *   - Accordion mode (data-accordion="1"): closes other items when one opens.
 *   - First-open (data-first-open="1"): opens the first item on page load
 *     unless any item already has `open` from `defaultOpen`.
 */
( function () {
	'use strict';

	function init() {
		var groups = document.querySelectorAll( '.ekwa-faq' );
		groups.forEach( function ( group ) {
			var items     = group.querySelectorAll( ':scope > .ekwa-faq__item' );
			var accordion = group.dataset.accordion === '1';
			var firstOpen = group.dataset.firstOpen === '1';

			if ( firstOpen && items.length ) {
				var anyOpen = Array.prototype.some.call( items, function ( it ) { return it.open; } );
				if ( ! anyOpen ) {
					items[0].open = true;
				}
			}

			if ( accordion ) {
				items.forEach( function ( item ) {
					item.addEventListener( 'toggle', function () {
						if ( ! item.open ) { return; }
						items.forEach( function ( other ) {
							if ( other !== item && other.open ) {
								other.open = false;
							}
						} );
					} );
				} );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
