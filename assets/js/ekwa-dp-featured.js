/**
 * "Suggest an image" — a button beside Set featured image.
 *
 * Works out what the page is about, searches Depositphotos for it, and shows
 * the matches with a link to follow. Deliberately does NOT download anything:
 * the site owner buys and uploads the file themselves, and a button that
 * silently sideloaded a watermarked comp as the featured image would be doing
 * the one thing nobody wants done automatically.
 *
 * Mounted through the editor.PostFeaturedImage filter, which wraps the core
 * panel component — that is what puts it under the real button rather than in
 * a panel of its own somewhere further down the sidebar.
 *
 * ES5, no build step, matching the other editor scripts in this theme.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.components ) {
		return;
	}

	var el         = wp.element.createElement;
	var Fragment   = wp.element.Fragment;
	var useState   = wp.element.useState;
	var Button     = wp.components.Button;
	var Spinner    = wp.components.Spinner;
	var Notice     = wp.components.Notice;
	var apiFetch   = wp.apiFetch;
	var __         = wp.i18n.__;
	var sprintf    = wp.i18n.sprintf;

	/**
	 * The post being edited, or 0 when there is no editor store.
	 */
	function currentPostId() {
		try {
			var sel = wp.data && wp.data.select( 'core/editor' );
			return ( sel && sel.getCurrentPostId ) ? ( sel.getCurrentPostId() || 0 ) : 0;
		} catch ( e ) {
			return 0;
		}
	}

	/**
	 * One search result: thumbnail, caption, and the link to go and buy it.
	 */
	function Suggestion( props ) {
		var item = props.item;

		return el(
			'a',
			{
				className: 'ekwa-dp-suggestion',
				href:      item.page,
				target:    '_blank',
				rel:       'noopener noreferrer',
				title:     item.title,
			},
			el( 'img', {
				className: 'ekwa-dp-suggestion__thumb',
				src:       item.thumb,
				alt:       item.title,
				loading:   'lazy',
			} ),
			el( 'span', { className: 'ekwa-dp-suggestion__id' }, '#' + item.id )
		);
	}

	/**
	 * The panel that appears under the featured image button.
	 */
	function SuggestPanel() {
		var s1 = useState( false );  var busy    = s1[0]; var setBusy    = s1[1];
		var s2 = useState( null );   var data    = s2[0]; var setData    = s2[1];
		var s3 = useState( null );   var error   = s3[0]; var setError   = s3[1];

		function run() {
			var id = currentPostId();
			if ( ! id ) {
				setError( __( 'Save the page first — there is nothing to read yet.', 'ekwa' ) );
				return;
			}

			setBusy( true );
			setError( null );

			apiFetch( {
				path:   '/ekwa/v1/dp-suggest-featured',
				method: 'POST',
				data:   { post_id: id },
			} ).then( function ( res ) {
				setData( res );
				setBusy( false );
			} ).catch( function ( err ) {
				setError( ( err && err.message ) || __( 'Could not fetch suggestions.', 'ekwa' ) );
				setBusy( false );
			} );
		}

		var children = [
			el(
				Button,
				{
					variant:  'secondary',
					disabled: busy,
					onClick:  run,
					className: 'ekwa-dp-suggest-button',
					key:      'btn',
				},
				busy ? __( 'Looking…', 'ekwa' ) : __( 'Suggest an image', 'ekwa' )
			),
		];

		if ( busy ) {
			children.push( el( Spinner, { key: 'spin' } ) );
		}

		if ( error ) {
			children.push(
				el( Notice, { status: 'error', isDismissible: false, key: 'err' }, error )
			);
		}

		if ( data ) {
			// What size to crop to, learned from the featured images this site
			// already has. Only absent on a site that has none yet.
			if ( data.target && data.target.width ) {
				children.push(
					el(
						'p',
						{ className: 'ekwa-dp-target', key: 'target' },
						sprintf(
							/* translators: 1: width, 2: height, 3: how many pages matched, 4: how many were looked at. */
							__( 'Crop to %1$d × %2$d — that is what %3$d of the last %4$d featured images on this site are.', 'ekwa' ),
							data.target.width,
							data.target.height,
							data.target.matched,
							data.target.sampled
						)
					)
				);
			} else {
				children.push(
					el(
						'p',
						{ className: 'ekwa-dp-target', key: 'target' },
						__( 'This site has no featured images yet, so there is no size to match — pick one and the next suggestion will follow it.', 'ekwa' )
					)
				);
			}

			if ( ! data.groups || ! data.groups.length ) {
				children.push(
					el(
						Notice,
						{ status: 'warning', isDismissible: false, key: 'empty' },
						__( 'Nothing came back. Depositphotos may be unreachable from this site, or the page has too little text to work out what it is about.', 'ekwa' )
					)
				);
			} else {
				data.groups.forEach( function ( group, i ) {
					children.push(
						el(
							'div',
							{ className: 'ekwa-dp-group', key: 'g' + i },
							el(
								'div',
								{ className: 'ekwa-dp-group__head' },
								el( 'strong', null, group.query ),
								el(
									'a',
									{
										href:   'https://depositphotos.com/search/' +
											encodeURIComponent( group.query.toLowerCase().replace( /[^a-z0-9]+/gi, '-' ).replace( /^-|-$/g, '' ) ) + '.html',
										target: '_blank',
										rel:    'noopener noreferrer',
									},
									__( 'see all ↗', 'ekwa' )
								)
							),
							el(
								'div',
								{ className: 'ekwa-dp-grid' },
								group.results.map( function ( item ) {
									return el( Suggestion, { item: item, key: item.id } );
								} )
							)
						)
					);
				} );

				children.push(
					el(
						'p',
						{ className: 'ekwa-dp-hint', key: 'hint' },
						__( 'Click one to open it on Depositphotos, download it, then set it as the featured image the usual way.', 'ekwa' )
					)
				);
			}
		}

		return el( 'div', { className: 'ekwa-dp-featured' }, children );
	}

	/**
	 * Wrap the core featured image panel, appending the button underneath it.
	 */
	wp.hooks.addFilter(
		'editor.PostFeaturedImage',
		'ekwa/dp-featured-suggestions',
		function ( OriginalComponent ) {
			return function ( props ) {
				return el(
					Fragment,
					null,
					el( OriginalComponent, props ),
					el( SuggestPanel, null )
				);
			};
		}
	);
} )( window.wp );
