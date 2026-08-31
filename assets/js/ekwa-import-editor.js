/**
 * Ekwa — "Create page (with imported content)".
 *
 * The Bulk Page Creator parks a page's original HTML on the page rather than
 * publishing it. This adds the step that turns it into blocks: convert, look at
 * a server-rendered preview, read what needs a human, insert — and redo it as
 * often as you like, because the source HTML is never consumed.
 *
 * It sits in the editor's ⋮ menu beside "Build with AI (Blocks)", and only
 * appears on pages that actually have imported content — everywhere else the
 * menu is untouched.
 *
 * Deliberately a separate plugin rather than a branch inside the AI Block
 * Builder modal: nothing here needs the model picker, prompt box, image uploads
 * or session history, and keeping it apart means the AI tool's behaviour is
 * unchanged for every existing site.
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element ) { return; }

	var el             = wp.element.createElement;
	var Fragment       = wp.element.Fragment;
	var useState       = wp.element.useState;
	var useEffect      = wp.element.useEffect;
	var registerPlugin = wp.plugins.registerPlugin;
	var Modal          = wp.components.Modal;
	var Button         = wp.components.Button;
	var Notice         = wp.components.Notice;
	var Spinner        = wp.components.Spinner;
	var ToggleControl  = wp.components.ToggleControl;
	var SelectControl  = wp.components.SelectControl;
	var apiFetch       = wp.apiFetch;
	var __             = wp.i18n.__;
	var sprintf        = wp.i18n.sprintf;

	var PluginMoreMenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem )
		? wp.editor.PluginMoreMenuItem
		: ( wp.editPost && wp.editPost.PluginMoreMenuItem
			? wp.editPost.PluginMoreMenuItem
			: null );

	var cfg = window.ekwaImportContent || {};

	// The site's design tokens + stylesheet, so the preview resolves the same
	// var() tokens the real page will. Raw CSS — the caller wraps it.
	var previewCss = cfg.previewCss || '';

	// Model catalog, sorted most-capable-first by ekwa_ai_model_catalog().
	// DEFAULT_MODEL is the theme-wide "pro" pick (ekwa_ai_default_model()) —
	// laying out a whole page is the heaviest job here, so it is not somewhere
	// to economise. An empty value means "let the server choose", which lands on
	// the same model; the option is listed so the choice is visible.
	var MODELS = ( cfg.models && cfg.models.length )
		? cfg.models
		: [ { value: '', label: __( 'Theme default', 'ekwa' ) } ];
	var DEFAULT_MODEL = cfg.defaultModel || '';

	/** Current post id, or 0 outside a post context. */
	function currentPostId() {
		var sel = wp.data && wp.data.select( 'core/editor' );
		return ( sel && sel.getCurrentPostId ) ? ( sel.getCurrentPostId() || 0 ) : 0;
	}

	// How each warning category is introduced to the author. The categories
	// come from two places (this import's own checks and the converter's loss
	// report) and are deliberately shown as one list — from the author's side
	// it is all just "what needs a look".
	var CATEGORY_LABELS = {
		phone:     __( 'Phone numbers', 'ekwa' ),
		link:      __( 'Links', 'ekwa' ),
		media:     __( 'Images and video', 'ekwa' ),
		converted: __( 'Converted', 'ekwa' ),
		dynamic:   __( 'Turned into theme blocks', 'ekwa' ),
		'raw-html': __( 'Kept as raw HTML', 'ekwa' ),
		dropped:   __( 'Removed', 'ekwa' ),
		general:   __( 'Notes', 'ekwa' )
	};

	// Categories that mean "you may want to do something", shown expanded.
	var NEEDS_ATTENTION = { phone: 1, link: 1, media: 1, dropped: 1 };

	function StatLine( stats ) {
		var bits = [];
		function add( n, one, many ) {
			if ( n > 0 ) { bits.push( sprintf( 1 === n ? one : many, n ) ); }
		}
		/* translators: %d: count */
		add( stats.faq_items,        __( '%d FAQ question', 'ekwa' ),  __( '%d FAQ questions', 'ekwa' ) );
		add( stats.videos,           __( '%d video', 'ekwa' ),          __( '%d videos', 'ekwa' ) );
		add( stats.images_imported,  __( '%d image copied in', 'ekwa' ), __( '%d images copied in', 'ekwa' ) );
		add( stats.phones_converted, __( '%d phone number', 'ekwa' ),   __( '%d phone numbers', 'ekwa' ) );
		add( stats.links_remapped,   __( '%d link re-pointed', 'ekwa' ), __( '%d links re-pointed', 'ekwa' ) );
		return bits.join( ' · ' );
	}

	function ImportModal( props ) {
		var postId = props.postId;
		var status = props.status || {};

		var s1 = useState( false ); var busy     = s1[0]; var setBusy     = s1[1];
		var s2 = useState( '' );    var markup   = s2[0]; var setMarkup   = s2[1];
		var s3 = useState( '' );    var preview  = s3[0]; var setPreview  = s3[1];
		var s4 = useState( [] );    var warnings = s4[0]; var setWarnings = s4[1];
		var s5 = useState( null );  var stats    = s5[0]; var setStats    = s5[1];
		var s6 = useState( '' );    var error    = s6[0]; var setError    = s6[1];
		var s7 = useState( true );  var sideload = s7[0]; var setSideload = s7[1];
		var s8 = useState( false ); var inserted = s8[0]; var setInserted = s8[1];
		var s9  = useState( false );         var designed = s9[0];  var setDesigned = s9[1];
		var s10 = useState( DEFAULT_MODEL ); var model    = s10[0]; var setModel    = s10[1];

		// Building needs a key, nothing else. Section designs already on the site
		// are used as reference when they exist, but are not required.
		var canDesign = !! status.has_api_key;

		function build() {
			setBusy( true );
			setError( '' );
			setInserted( false );

			apiFetch( {
				path:   '/ekwa/v1/import-convert',
				method: 'POST',
				data:   {
					post_id:  postId,
					sideload: sideload,
					model:    model
				}
			} ).then( function ( res ) {
				setMarkup( res.markup || '' );
				setPreview( res.preview || '' );
				setWarnings( res.warnings || [] );
				setStats( res.stats || null );
				setDesigned( !! res.designed );
				setBusy( false );
			} ).catch( function ( e ) {
				setError( ( e && e.message ) || __( 'Could not build the page.', 'ekwa' ) );
				setBusy( false );
			} );
		}

		// Deliberately NOT run on open. Building costs a model call and takes
		// about a minute, so it happens when the author asks for it — the same
		// bargain as the AI Block Builder, which waits for you to press Generate.

		function insert() {
			if ( ! markup ) { return; }
			try {
				var blocks = wp.blocks.parse( markup );
				if ( ! blocks || ! blocks.length ) {
					setError( __( 'Nothing to insert — the conversion produced no blocks.', 'ekwa' ) );
					return;
				}
				wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks );
				setInserted( true );
				// Best effort: the marker only drives a hint in the UI, so a
				// failure here must not look like the insert failed.
				apiFetch( {
					path:   '/ekwa/v1/import-applied',
					method: 'POST',
					data:   { post_id: postId }
				} ).catch( function () {} );
			} catch ( e ) {
				setError( ( e && e.message ) || __( 'Could not insert the blocks.', 'ekwa' ) );
			}
		}

		// Group warnings by category so one long flat list does not bury the
		// two or three lines that actually need a decision.
		var grouped = {};
		( warnings || [] ).forEach( function ( w ) {
			var c = w.category || 'general';
			if ( ! grouped[ c ] ) { grouped[ c ] = []; }
			grouped[ c ].push( w.message );
		} );

		var attention = [];
		var informational = [];
		Object.keys( grouped ).forEach( function ( c ) {
			( NEEDS_ATTENTION[ c ] ? attention : informational ).push( c );
		} );

		function renderGroup( c, open ) {
			return el( 'details', { key: c, open: open, style: { marginBottom: '8px' } },
				el( 'summary', { style: { cursor: 'pointer', fontWeight: 600 } },
					( CATEGORY_LABELS[ c ] || c ) + ' (' + grouped[ c ].length + ')'
				),
				el( 'ul', { style: { margin: '6px 0 0 18px', listStyle: 'disc' } },
					grouped[ c ].map( function ( m, i ) {
						return el( 'li', { key: i, style: { marginBottom: '4px' } }, m );
					} )
				)
			);
		}

		return el( Modal, {
			title: __( 'Create page (with imported content)', 'ekwa' ),
			onRequestClose: props.onClose,
			className: 'ekwa-import-modal',
			style: { maxWidth: '1100px', width: '92vw' }
		},
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,

			inserted
				? el( Notice, { status: 'success', isDismissible: false },
					__( 'Blocks inserted. The imported HTML is still stored on this page, so you can convert it again at any time.', 'ekwa' ) )
				: null,

			el( 'p', { style: { marginTop: 0 } },
				__( 'Builds a page from this page\'s imported content, the same way “Build with AI (Blocks)” builds a section — and on the way it copies the images into your Media Library, swaps phone numbers for [ekwa_phone], and re-points the old site\'s internal links at your pages. Nothing is saved until you insert it, and the imported content is kept, so you can build again as often as you like.', 'ekwa' )
			),

			el( ToggleControl, {
				label: __( 'Copy images into the Media Library', 'ekwa' ),
				help:  __( 'Off means the page keeps loading images from the old site.', 'ekwa' ),
				checked: sideload,
				onChange: setSideload,
				__nextHasNoMarginBottom: true
			} ),

			canDesign
				? el( 'div', { style: { display: 'flex', gap: '10px', alignItems: 'flex-end', flexWrap: 'wrap', margin: '14px 0' } },
					el( 'div', { style: { minWidth: '280px' } },
						el( SelectControl, {
							label: __( 'Model', 'ekwa' ),
							value: model,
							options: MODELS,
							onChange: setModel,
							disabled: busy,
							help: ( model && DEFAULT_MODEL && model !== DEFAULT_MODEL )
								? __( 'Not the default. Building a full page is demanding — a smaller model usually shows up as thinner layout.', 'ekwa' )
								: __( 'The most capable model available. Building a whole page is the heaviest job here.', 'ekwa' ),
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true
						} )
					),

					el( Button, {
						variant: 'primary',
						onClick: build,
						disabled: busy,
						style: { marginBottom: '8px' }
					}, busy
						? __( 'Building…', 'ekwa' )
						: ( markup ? __( 'Build again', 'ekwa' ) : __( 'Build page with AI', 'ekwa' ) ) ),

					el( Button, {
						variant: 'secondary',
						onClick: insert,
						disabled: busy || ! markup,
						style: { marginBottom: '8px' }
					}, __( 'Insert into page', 'ekwa' ) ),

					busy ? el( Spinner, null ) : null,

					stats && ! busy
						? el( 'span', { style: { color: '#757575', fontSize: '12px', paddingBottom: '14px' } }, StatLine( stats ) )
						: null
				)
				: el( Notice, { status: 'warning', isDismissible: false },
					__( 'Add a Gemini API key in Ekwa Settings → AI to build pages from imported content.', 'ekwa' )
				),

			busy
				? el( 'p', { style: { fontSize: '12px', color: '#757575', margin: '0 0 12px' } },
					__( 'This usually takes about a minute — it is laying out the whole page.', 'ekwa' ) )
				: null,

			attention.length
				? el( 'div', { style: { marginBottom: '12px' } },
					el( 'h3', { style: { margin: '0 0 6px', fontSize: '13px' } },
						__( 'Needs a look', 'ekwa' ) ),
					attention.map( function ( c ) { return renderGroup( c, true ); } )
				)
				: null,

			informational.length
				? el( 'div', { style: { marginBottom: '12px' } },
					el( 'h3', { style: { margin: '0 0 6px', fontSize: '13px' } },
						__( 'What was converted', 'ekwa' ) ),
					informational.map( function ( c ) { return renderGroup( c, false ); } )
				)
				: null,

			el( 'h3', { style: { margin: '0 0 6px', fontSize: '13px' } }, __( 'Preview', 'ekwa' ) ),
			el( 'iframe', {
				title: __( 'Imported content preview', 'ekwa' ),
				// previewCss is RAW css, not a <style> element — it has to be
				// wrapped. Injected bare it is text, not markup: the parser
				// moves it out of <head> and the stylesheet renders as visible
				// text on top of the preview. Matches how the AI Block Builder
				// builds its own iframe (assets/js/ekwa-ai-blocks-editor.js).
				srcDoc: '<!doctype html><html><head><meta charset="utf-8">'
					+ ( previewCss ? '<style>' + previewCss + '</style>' : '' )
					+ '<style>body{margin:0;padding:16px}img{max-width:100%;height:auto}</style>'
					+ '</head><body>' + ( preview || '' ) + '</body></html>',
				style: {
					width: '100%',
					height: '48vh',
					border: '1px solid #ddd',
					borderRadius: '4px',
					background: '#fff'
				}
			} )
		);
	}

	function ImportPlugin() {
		var o = useState( false ); var isOpen = o[0]; var setOpen = o[1];
		var h = useState( null );  var status = h[0]; var setStatus = h[1];

		var postId = currentPostId();

		// Ask once per page load whether there is anything to offer. Until the
		// answer is yes the menu item is not rendered at all, so the editor's ⋮
		// menu looks exactly as it always has on pages with no import.
		useEffect( function () {
			if ( ! postId ) { return; }
			apiFetch( { path: '/ekwa/v1/import-status?post_id=' + postId } )
				.then( setStatus )
				.catch( function () { setStatus( null ); } );
		}, [ postId ] );

		if ( ! status || ! status.has_content ) { return null; }

		var label = status.applied_at
			? __( 'Create page (with imported content) — done once', 'ekwa' )
			: __( 'Create page (with imported content)', 'ekwa' );

		var trigger = PluginMoreMenuItem
			? el( PluginMoreMenuItem, { icon: 'download', onClick: function () { setOpen( true ); } }, label )
			: el( Button, {
				icon: 'download',
				onClick: function () { setOpen( true ); },
				className: 'ekwa-import-fab'
			}, label );

		return el( Fragment, null,
			trigger,
			isOpen
				? el( ImportModal, {
					postId: postId,
					status: status,
					onClose: function () { setOpen( false ); }
				} )
				: null
		);
	}

	registerPlugin( 'ekwa-import-content', {
		render: ImportPlugin,
		icon: 'download'
	} );

} )( window.wp );
