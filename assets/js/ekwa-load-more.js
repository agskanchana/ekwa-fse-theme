/**
 * Ekwa Load More — AJAX pagination for query blocks.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.ekwa-load-more' ).forEach( initLoadMore );
	} );

	function initLoadMore( wrapper ) {
		var btn = wrapper.querySelector( '.ekwa-load-more__btn' );
		if ( ! btn ) return;

		// Find the parent wp-block-query.
		var queryBlock = wrapper.closest( '.wp-block-query' );
		if ( ! queryBlock ) return;

		var queryJson = queryBlock.dataset.ekwaQuery;
		var maxPages  = parseInt( queryBlock.dataset.ekwaMaxPages ) || 1;
		var nonce     = queryBlock.dataset.ekwaNonce || '';
		var ajaxUrl   = ( window.ekwaLoadMore && window.ekwaLoadMore.ajaxUrl ) || '/wp-admin/admin-ajax.php';

		if ( ! queryJson ) return;

		// Find the post grid container.
		var grid = queryBlock.querySelector( '.wp-block-post-template' );

		var page        = 1;
		var loading     = false;
		var btnText     = btn.textContent;
		var loadingText = wrapper.dataset.loadingText || 'Loading...';
		var noMoreText  = wrapper.dataset.noMoreText || 'No more posts';

		// Hide if only one page.
		if ( maxPages <= 1 ) {
			wrapper.style.display = 'none';
			return;
		}

		btn.addEventListener( 'click', function () {
			if ( loading ) return;
			loading = true;
			page++;

			btn.classList.add( 'is-loading' );
			btn.textContent = loadingText;

			var formData = new FormData();
			formData.append( 'action', 'ekwa_load_more' );
			formData.append( 'nonce', nonce );
			formData.append( 'page', page );
			formData.append( 'query', queryJson );

			fetch( ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				if ( data.success && data.data.html && grid ) {
					// Append new cards.
					var temp = document.createElement( 'div' );
					temp.innerHTML = data.data.html;
					while ( temp.firstChild ) {
						grid.appendChild( temp.firstChild );
					}

					if ( ! data.data.hasMore ) {
						btn.classList.add( 'is-done' );
						btn.textContent = noMoreText;
						btn.disabled = true;
					} else {
						btn.textContent = btnText;
					}
				}

				btn.classList.remove( 'is-loading' );
				loading = false;
			} )
			.catch( function () {
				btn.classList.remove( 'is-loading' );
				btn.textContent = btnText;
				loading = false;
			} );
		} );
	}
} )();
