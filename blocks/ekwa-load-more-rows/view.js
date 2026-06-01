/**
 * Ekwa Load More Rows — reveal hidden rows in batches.
 *
 * Markup (from the server render):
 *   <div class="ekwa-lmr" data-batch="1" data-hide-when-done="1">
 *     <div class="ekwa-lmr__track">
 *       <div class="ekwa-lmr__row">…</div>
 *       <div class="ekwa-lmr__row is-hidden" hidden>…</div>
 *     </div>
 *     <div class="ekwa-lmr__more"><button class="ekwa-lmr__btn">Load More</button></div>
 *   </div>
 *
 * Rows beyond the visible count are marked hidden server-side (no flash); this
 * script reveals the next `batch` on each click and retires the button when done.
 */
( function () {
	'use strict';

	function init() {
		document.querySelectorAll( '.ekwa-lmr' ).forEach( function ( box ) {
			var track = box.querySelector( ':scope > .ekwa-lmr__track' );
			var btn   = box.querySelector( ':scope > .ekwa-lmr__more > .ekwa-lmr__btn' );
			if ( ! track || ! btn ) { return; }

			var batch = parseInt( box.getAttribute( 'data-batch' ), 10 );
			if ( ! batch || batch < 1 ) { batch = 1; }
			var hideWhenDone = box.getAttribute( 'data-hide-when-done' ) === '1';

			function hiddenRows() {
				return track.querySelectorAll( ':scope > .ekwa-lmr__row.is-hidden' );
			}

			function sync() {
				if ( hiddenRows().length === 0 ) {
					if ( hideWhenDone ) {
						btn.parentNode.style.display = 'none';
					} else {
						btn.disabled = true;
					}
				}
			}

			btn.addEventListener( 'click', function () {
				var hidden = hiddenRows();
				var n = Math.min( batch, hidden.length );
				for ( var i = 0; i < n; i++ ) {
					hidden[ i ].classList.remove( 'is-hidden' );
					hidden[ i ].removeAttribute( 'hidden' );
					hidden[ i ].classList.add( 'is-revealed' );
				}
				sync();
			} );

			sync();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
