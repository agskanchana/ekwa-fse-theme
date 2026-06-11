/**
 * Ekwa Load More — AJAX pagination for query blocks.
 * Supports "load-more" button mode and "numbered" pagination mode.
 * Numbered mode uses real page URLs (/blog/page/2/) with pushState,
 * so pages stay linkable and the back/forward buttons work.
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

		var currentPage = parseInt( queryBlock.dataset.ekwaCurrentPage ) || 1;
		var urlPattern  = queryBlock.dataset.ekwaUrlPattern || '';
		var page1Url    = queryBlock.dataset.ekwaPage1Url || '';
		var loading     = false;
		var initialPage = currentPage;
		var initialHtml = grid ? grid.innerHTML : '';
		var prevLabel   = wrapper.dataset.prevText || 'Prev';
		var nextLabel   = wrapper.dataset.nextText || 'Next';

		function pageUrl( p ) {
			if ( p <= 1 && page1Url ) return page1Url;
			if ( urlPattern ) return urlPattern.replace( '%d', p );
			return '#';
		}

		function makeItem( label, page, classes, disabled ) {
			var el;
			if ( disabled ) {
				el = document.createElement( 'span' );
				el.className = classes + ' is-disabled';
				el.setAttribute( 'aria-disabled', 'true' );
			} else {
				el = document.createElement( 'a' );
				el.className = classes;
				el.href = pageUrl( page );
				el.addEventListener( 'click', function ( e ) {
					// Let modified clicks (new tab, etc.) behave like normal links.
					if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) return;
					e.preventDefault();
					goToPage( page, true );
				} );
			}
			el.textContent = label;
			return el;
		}

		function buildNav() {
			nav.innerHTML = '';

			nav.appendChild( makeItem( prevLabel, currentPage - 1, 'ekwa-pagination__btn ekwa-pagination__prev', currentPage === 1 ) );

			getPageRange( currentPage, maxPages ).forEach( function ( p ) {
				if ( p === '...' ) {
					var ellipsis = document.createElement( 'span' );
					ellipsis.className   = 'ekwa-pagination__ellipsis';
					ellipsis.textContent = '…';
					nav.appendChild( ellipsis );
					return;
				}
				if ( p === currentPage ) {
					var active = document.createElement( 'span' );
					active.className   = 'ekwa-pagination__btn ekwa-pagination__page is-active';
					active.textContent = p;
					active.setAttribute( 'aria-current', 'page' );
					nav.appendChild( active );
					return;
				}
				var link = makeItem( p, p, 'ekwa-pagination__btn ekwa-pagination__page', false );
				link.setAttribute( 'aria-label', 'Page ' + p );
				nav.appendChild( link );
			} );

			nav.appendChild( makeItem( nextLabel, currentPage + 1, 'ekwa-pagination__btn ekwa-pagination__next', currentPage === maxPages ) );
		}

		function goToPage( p, push ) {
			if ( loading || p === currentPage || p < 1 || p > maxPages ) return;
			loading = true;
			nav.classList.add( 'is-loading' );

			function onDone() {
				currentPage = p;
				buildNav();
				nav.classList.remove( 'is-loading' );
				loading = false;
				if ( push && window.history && history.pushState ) {
					history.pushState( { ekwaPage: p }, '', pageUrl( p ) );
				}
				queryBlock.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}

			// Restore the server-rendered initial page without an AJAX call.
			if ( p === initialPage && initialHtml ) {
				if ( grid ) grid.innerHTML = initialHtml;
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

		function parsePageFromUrl() {
			var m = window.location.pathname.match( /\/page\/(\d+)/ );
			if ( m ) return parseInt( m[1] );
			m = window.location.search.match( /[?&]paged=(\d+)/ );
			if ( m ) return parseInt( m[1] );
			return 1;
		}

		if ( window.history && history.replaceState ) {
			history.replaceState( { ekwaPage: currentPage }, '' );
		}
		window.addEventListener( 'popstate', function ( e ) {
			var p = ( e.state && e.state.ekwaPage ) || parsePageFromUrl();
			if ( p && p !== currentPage ) {
				goToPage( p, false );
			}
		} );

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
