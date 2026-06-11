/**
 * Ekwa Load More — AJAX pagination for query blocks.
 * Supports "load-more" button mode and "numbered" pagination mode.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.ekwa-load-more' ).forEach( initLoadMore );
	} );

	function initLoadMore( wrapper ) {
		var queryBlock = wrapper.closest( '.wp-block-query' );
		if ( ! queryBlock ) return;

		var queryJson = queryBlock.dataset.ekwaQuery;
		var maxPages  = parseInt( queryBlock.dataset.ekwaMaxPages ) || 1;
		var nonce     = queryBlock.dataset.ekwaNonce || '';
		var ajaxUrl   = ( window.ekwaLoadMore && window.ekwaLoadMore.ajaxUrl ) || '/wp-admin/admin-ajax.php';

		if ( ! queryJson ) return;

		var grid = queryBlock.querySelector( '.wp-block-post-template' );
		var type = wrapper.dataset.paginationType || 'load-more';

		if ( type === 'numbered' ) {
			initNumberedPagination( wrapper, queryBlock, grid, queryJson, maxPages, nonce, ajaxUrl );
		} else {
			initLoadMoreButton( wrapper, grid, queryJson, maxPages, nonce, ajaxUrl );
		}
	}

	// ─── Load More button mode ────────────────────────────────────────────────

	function initLoadMoreButton( wrapper, grid, queryJson, maxPages, nonce, ajaxUrl ) {
		var btn = wrapper.querySelector( '.ekwa-load-more__btn' );
		if ( ! btn ) return;

		var page        = 1;
		var loading     = false;
		var btnText     = btn.textContent;
		var loadingText = wrapper.dataset.loadingText || 'Loading...';
		var noMoreText  = wrapper.dataset.noMoreText  || 'No more posts';

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

			fetchPage( ajaxUrl, nonce, page, queryJson,
				function ( data ) {
					if ( data.success && data.data.html && grid ) {
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
				},
				function () {
					btn.classList.remove( 'is-loading' );
					btn.textContent = btnText;
					loading = false;
				}
			);
		} );
	}

	// ─── Numbered pagination mode ─────────────────────────────────────────────

	function initNumberedPagination( wrapper, queryBlock, grid, queryJson, maxPages, nonce, ajaxUrl ) {
		if ( maxPages <= 1 ) {
			wrapper.style.display = 'none';
			return;
		}

		var nav = wrapper.querySelector( '.ekwa-load-more__pagination' );
		if ( ! nav ) return;

		var currentPage  = 1;
		var loading      = false;
		var page1Html    = grid ? grid.innerHTML : '';
		var prevLabel    = wrapper.dataset.prevText || 'Prev';
		var nextLabel    = wrapper.dataset.nextText || 'Next';

		function buildNav() {
			nav.innerHTML = '';

			var prev = document.createElement( 'button' );
			prev.className   = 'ekwa-pagination__btn ekwa-pagination__prev';
			prev.textContent = prevLabel;
			prev.disabled    = currentPage === 1;
			prev.setAttribute( 'aria-label', 'Previous page' );
			prev.addEventListener( 'click', function () {
				if ( currentPage > 1 ) goToPage( currentPage - 1 );
			} );
			nav.appendChild( prev );

			getPageRange( currentPage, maxPages ).forEach( function ( p ) {
				if ( p === '...' ) {
					var ellipsis = document.createElement( 'span' );
					ellipsis.className   = 'ekwa-pagination__ellipsis';
					ellipsis.textContent = '…';
					nav.appendChild( ellipsis );
					return;
				}
				var btn = document.createElement( 'button' );
				btn.className   = 'ekwa-pagination__btn ekwa-pagination__page';
				btn.textContent = p;
				btn.setAttribute( 'aria-label', 'Page ' + p );
				if ( p === currentPage ) {
					btn.classList.add( 'is-active' );
					btn.setAttribute( 'aria-current', 'page' );
				}
				btn.addEventListener( 'click', function () {
					if ( p !== currentPage ) goToPage( p );
				} );
				nav.appendChild( btn );
			} );

			var next = document.createElement( 'button' );
			next.className   = 'ekwa-pagination__btn ekwa-pagination__next';
			next.textContent = nextLabel;
			next.disabled    = currentPage === maxPages;
			next.setAttribute( 'aria-label', 'Next page' );
			next.addEventListener( 'click', function () {
				if ( currentPage < maxPages ) goToPage( currentPage + 1 );
			} );
			nav.appendChild( next );
		}

		function goToPage( p ) {
			if ( loading || p === currentPage ) return;
			loading = true;
			nav.classList.add( 'is-loading' );

			function onDone() {
				currentPage = p;
				buildNav();
				nav.classList.remove( 'is-loading' );
				loading = false;
				queryBlock.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}

			// Restore server-rendered page 1 without an AJAX call.
			if ( p === 1 && page1Html ) {
				if ( grid ) grid.innerHTML = page1Html;
				onDone();
				return;
			}

			fetchPage( ajaxUrl, nonce, p, queryJson,
				function ( data ) {
					if ( data.success && data.data.html && grid ) {
						grid.innerHTML = data.data.html;
					}
					onDone();
				},
				function () {
					nav.classList.remove( 'is-loading' );
					loading = false;
				}
			);
		}

		buildNav();
	}

	// ─── Shared helpers ───────────────────────────────────────────────────────

	function getPageRange( current, total ) {
		if ( total <= 7 ) {
			return Array.from( { length: total }, function ( _, i ) { return i + 1; } );
		}
		var pages = [ 1 ];
		var start = Math.max( 2, current - 2 );
		var end   = Math.min( total - 1, current + 2 );
		if ( start > 2 ) pages.push( '...' );
		for ( var i = start; i <= end; i++ ) pages.push( i );
		if ( end < total - 1 ) pages.push( '...' );
		pages.push( total );
		return pages;
	}

	function fetchPage( ajaxUrl, nonce, page, queryJson, onSuccess, onError ) {
		var formData = new FormData();
		formData.append( 'action', 'ekwa_load_more' );
		formData.append( 'nonce',  nonce );
		formData.append( 'page',   page );
		formData.append( 'query',  queryJson );

		fetch( ajaxUrl, {
			method: 'POST',
			body:   formData,
			credentials: 'same-origin',
		} )
		.then( function ( res ) { return res.json(); } )
		.then( onSuccess )
		.catch( onError );
	}
} )();
