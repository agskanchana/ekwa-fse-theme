/**
 * Ekwa Reconvert — per-part re-conversion to a dynamic block.
 *
 * Adds a "Reconvert" toolbar action to content blocks (text, div, link,
 * paragraph, list, image…). When the whole-section converter mis-maps one
 * element — e.g. an address flattened into plain text — select that block,
 * pick the correct dynamic block (Address, Phone, Hours…), and it is replaced
 * in place. The modal pre-fills the block's current HTML and is editable, so
 * you can paste the original mockup snippet to restore structure that was
 * flattened; the AI converter then preserves it as the block's customTemplate.
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.blockEditor || ! wp.compose || ! wp.data || ! wp.blocks ) {
		return;
	}

	var el                        = wp.element.createElement;
	var Fragment                  = wp.element.Fragment;
	var useState                  = wp.element.useState;
	var addFilter                 = wp.hooks.addFilter;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var BlockControls             = wp.blockEditor.BlockControls;
	var ToolbarGroup              = wp.components.ToolbarGroup;
	var ToolbarButton             = wp.components.ToolbarButton;
	var Modal                     = wp.components.Modal;
	var Button                    = wp.components.Button;
	var SelectControl             = wp.components.SelectControl;
	var TextareaControl           = wp.components.TextareaControl;
	var Notice                    = wp.components.Notice;
	var Spinner                   = wp.components.Spinner;
	var apiFetch                  = wp.apiFetch;
	var select                    = wp.data.select;
	var dispatch                  = wp.data.dispatch;
	var __                        = wp.i18n.__;

	var TARGETS = ( window.ekwaReconvert && window.ekwaReconvert.targets ) || [];

	// Blocks that plausibly hold a mis-mapped dynamic element.
	var ALLOWED = {
		'ekwa/text': 1, 'ekwa/div': 1, 'ekwa/link': 1, 'ekwa/icon': 1,
		'ekwa/image': 1, 'ekwa/figure': 1,
		'core/paragraph': 1, 'core/heading': 1, 'core/list': 1, 'core/html': 1,
	};

	// ─── Helpers ──────────────────────────────────────────────────────────────

	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	/**
	 * Best-effort HTML for a block, to pre-fill the reconvert field. Wrappers
	 * emit their own tag + class and recurse; known leaf blocks are rebuilt from
	 * attributes; static core blocks use their real saved HTML.
	 */
	function blockToHtml( block ) {
		if ( ! block ) { return ''; }
		var a    = block.attributes || {};
		var name = block.name;

		if ( name === 'ekwa/div' || name === 'ekwa/figure' ) {
			var tag = ( name === 'ekwa/figure' ) ? 'figure' : ( a.tagName || 'div' );
			var attrs = '';
			if ( a.className ) { attrs += ' class="' + esc( a.className ) + '"'; }
			if ( tag === 'a' && ( a.url || a.href ) ) { attrs += ' href="' + esc( a.url || a.href ) + '"'; }
			var inner = ( block.innerBlocks || [] ).map( blockToHtml ).join( '' );
			return '<' + tag + attrs + '>' + inner + '</' + tag + '>';
		}

		switch ( name ) {
			case 'ekwa/text': {
				var t  = a.tagName || 'span';
				var tc = a.className ? ' class="' + esc( a.className ) + '"' : '';
				return '<' + t + tc + '>' + esc( a.text || '' ) + '</' + t + '>';
			}
			case 'ekwa/link': {
				var lc = a.className ? ' class="' + esc( a.className ) + '"' : '';
				return '<a href="' + esc( a.url || a.href || '#' ) + '"' + lc + '>' + esc( a.text || '' ) + '</a>';
			}
			case 'ekwa/icon':
				return '<i class="' + esc( a.iconClass || '' ) + '"></i>';
			case 'ekwa/image':
				return '<img src="' + esc( a.src || '' ) + '" alt="' + esc( a.alt || '' ) + '">';
		}

		try {
			var content = wp.blocks.getBlockContent( block );
			if ( content && content.trim() ) { return content; }
		} catch ( e ) { /* fall through */ }

		if ( block.innerBlocks && block.innerBlocks.length ) {
			return block.innerBlocks.map( blockToHtml ).join( '' );
		}
		try {
			return wp.blocks.serialize( block ).replace( /<!--[\s\S]*?-->/g, '' ).trim();
		} catch ( e ) {
			return '';
		}
	}

	// ─── Modal ────────────────────────────────────────────────────────────────

	function ReconvertModal( props ) {
		var clientId = props.clientId;

		var s0 = useState( function () {
			var block = select( 'core/block-editor' ).getBlock( clientId );
			return block ? blockToHtml( block ) : '';
		} );
		var htmlValue = s0[0]; var setHtmlValue = s0[1];

		var s1 = useState( TARGETS[0] ? TARGETS[0].value : 'ekwa/address' );
		var target = s1[0]; var setTarget = s1[1];

		var s2 = useState( false ); var busy     = s2[0]; var setBusy     = s2[1];
		var s3 = useState( null );  var error    = s3[0]; var setError    = s3[1];
		var s4 = useState( '' );    var markup   = s4[0]; var setMarkup   = s4[1];
		var s5 = useState( '' );    var preview  = s5[0]; var setPreview  = s5[1];
		var s6 = useState( [] );    var warnings = s6[0]; var setWarnings = s6[1];

		function doReconvert() {
			if ( ! htmlValue.trim() ) {
				setError( __( 'Add the HTML for this part first.', 'ekwa' ) );
				return;
			}
			setBusy( true ); setError( null ); setMarkup( '' ); setPreview( '' ); setWarnings( [] );
			apiFetch( {
				path: '/ekwa/v1/reconvert-part',
				method: 'POST',
				data: { html: htmlValue, target: target },
			} ).then( function ( res ) {
				setMarkup( res.markup || '' );
				setPreview( res.rendered_html || '' );
				setWarnings( res.warnings || [] );
				setBusy( false );
				if ( ! res.markup ) {
					setError( __( 'The AI returned nothing to insert.', 'ekwa' ) );
				}
			} ).catch( function ( err ) {
				setError( ( err && err.message ) || __( 'Reconvert failed.', 'ekwa' ) );
				setBusy( false );
			} );
		}

		function doReplace() {
			if ( ! markup ) { return; }
			var blocks = wp.blocks.parse( markup );
			if ( ! blocks || ! blocks.length ) {
				setError( __( 'The converted markup could not be parsed into blocks.', 'ekwa' ) );
				return;
			}
			dispatch( 'core/block-editor' ).replaceBlocks( clientId, blocks );
			props.onClose();
		}

		var children = [];

		children.push(
			el( TextareaControl, {
				key: 'html',
				label: __( 'HTML for this part', 'ekwa' ),
				help: __( 'Pre-filled from the selected block. If the structure was flattened, paste the original mockup snippet — it becomes the block’s customTemplate so the layout and CSS are preserved.', 'ekwa' ),
				value: htmlValue,
				onChange: setHtmlValue,
				rows: 6,
			} )
		);

		children.push(
			el( SelectControl, {
				key: 'target',
				label: __( 'Convert to', 'ekwa' ),
				value: target,
				options: TARGETS.map( function ( t ) { return { label: t.label, value: t.value }; } ),
				onChange: setTarget,
			} )
		);

		if ( error ) {
			children.push(
				el( Notice, { key: 'err', status: 'error', isDismissible: true, onRemove: function () { setError( null ); } }, error )
			);
		}

		children.push(
			el( 'div', { key: 'act', style: { display: 'flex', gap: '8px', marginTop: '8px' } },
				el( Button, {
					variant: 'primary',
					isBusy: busy,
					disabled: busy || ! htmlValue.trim(),
					onClick: doReconvert,
				}, busy
					? el( Fragment, null, el( Spinner, null ), __( ' Reconverting…', 'ekwa' ) )
					: ( markup ? __( 'Reconvert again', 'ekwa' ) : __( 'Reconvert', 'ekwa' ) )
				)
			)
		);

		if ( markup ) {
			if ( warnings && warnings.length ) {
				children.push(
					el( Notice, { key: 'warn', status: 'warning', isDismissible: false },
						el( 'ul', { style: { margin: '0 0 0 1em', listStyle: 'disc' } },
							warnings.map( function ( w, i ) { return el( 'li', { key: i }, w ); } )
						)
					)
				);
			}

			children.push(
				el( 'div', { key: 'prev', style: { marginTop: '12px' } },
					el( 'strong', null, __( 'Preview', 'ekwa' ) ),
					preview
						? el( 'div', {
							style: { border: '1px solid #ddd', borderRadius: '4px', padding: '12px', marginTop: '6px', background: '#fff', overflowX: 'auto' },
							dangerouslySetInnerHTML: { __html: preview },
						} )
						: el( 'p', { style: { color: '#757575', marginTop: '6px' } },
							__( '(No server preview — insert to see it live. Page-context blocks render empty here.)', 'ekwa' )
						)
				)
			);

			children.push(
				el( TextareaControl, {
					key: 'markup',
					label: __( 'Block markup', 'ekwa' ),
					value: markup,
					readOnly: true,
					rows: 4,
				} )
			);

			children.push(
				el( 'div', { key: 'act2', style: { display: 'flex', gap: '8px', marginTop: '8px' } },
					el( Button, { variant: 'primary', onClick: doReplace }, __( 'Replace selection', 'ekwa' ) ),
					el( Button, { variant: 'tertiary', onClick: props.onClose }, __( 'Cancel', 'ekwa' ) )
				)
			);
		}

		return el( Modal, {
			title: __( 'Reconvert to dynamic block', 'ekwa' ),
			onRequestClose: props.onClose,
			className: 'ekwa-reconvert-modal',
		}, children );
	}

	// ─── Toolbar control (added to every block; renders only when applicable) ──

	function ReconvertControl( props ) {
		var st = useState( false );
		var open = st[0]; var setOpen = st[1];

		if ( ! props.isSelected || ! ALLOWED[ props.name ] || ! TARGETS.length ) {
			return null;
		}

		return el( Fragment, null,
			el( BlockControls, { group: 'other' },
				el( ToolbarGroup, null,
					el( ToolbarButton, {
						icon: 'update',
						title: __( 'Reconvert as dynamic block', 'ekwa' ),
						onClick: function () { setOpen( true ); },
					}, __( 'Reconvert', 'ekwa' ) )
				)
			),
			open ? el( ReconvertModal, { clientId: props.clientId, onClose: function () { setOpen( false ); } } ) : null
		);
	}

	var withReconvert = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			return el( Fragment, null,
				el( BlockEdit, props ),
				el( ReconvertControl, {
					clientId:   props.clientId,
					name:       props.name,
					isSelected: props.isSelected,
				} )
			);
		};
	}, 'withReconvert' );

	addFilter( 'editor.BlockEdit', 'ekwa/reconvert', withReconvert );

} )( window.wp );
