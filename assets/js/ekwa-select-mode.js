/**
 * Ekwa Select Mode (X-ray) — Gutenberg Editor Plugin.
 *
 * Design CSS loaded into the canvas (child theme, scoped section CSS) can
 * overlap blocks with absolute positioning, z-index stacks or overflow:hidden,
 * making them impossible to click. Select mode adds the `ekwa-select-mode`
 * class to the canvas body; assets/css/ekwa-editor.css then flattens
 * position/z-index/transform/overflow and outlines every block so everything
 * is reachable. Purely visual and editor-only — nothing is saved.
 *
 * Also exposes the existing "disable child CSS in editor" site option
 * (admins only) so it's discoverable at the moment of pain.
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	var el             = wp.element.createElement;
	var Fragment       = wp.element.Fragment;
	var useState       = wp.element.useState;
	var registerPlugin = wp.plugins.registerPlugin;
	var useSelect      = wp.data.useSelect;
	var select         = wp.data.select;
	var dispatch       = wp.data.dispatch;
	var subscribe      = wp.data.subscribe;
	var apiFetch       = wp.apiFetch;
	var __             = wp.i18n.__;

	var PluginMoreMenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem )
		? wp.editor.PluginMoreMenuItem
		: ( wp.editPost && wp.editPost.PluginMoreMenuItem
			? wp.editPost.PluginMoreMenuItem
			: null );

	var PREF_SCOPE = 'ekwa';
	var PREF_KEY   = 'selectMode';
	var config     = window.ekwaSelectMode || {};

	function isOn() {
		return !! select( 'core/preferences' ).get( PREF_SCOPE, PREF_KEY );
	}

	/**
	 * Sync the body class on both possible canvas documents: the FSE/iframed
	 * canvas (iframe[name=editor-canvas]) and the non-iframed post editor
	 * (main document). The CSS matches either body placement.
	 */
	function syncCanvasClass() {
		var on   = isOn();
		var docs = [ document ];
		var frame = document.querySelector( 'iframe[name="editor-canvas"]' );
		if ( frame && frame.contentDocument && frame.contentDocument.body ) {
			docs.push( frame.contentDocument );
		}
		docs.forEach( function ( doc ) {
			if ( doc.body ) {
				doc.body.classList.toggle( 'ekwa-select-mode', on );
			}
		} );
	}

	// The canvas iframe reloads on template/navigation changes in the site
	// editor; any store change re-syncs the class, so a plain subscribe covers
	// remounts without polling.
	subscribe( syncCanvasClass );

	function SelectModeMenu() {
		var on = useSelect( function ( sel ) {
			return !! sel( 'core/preferences' ).get( PREF_SCOPE, PREF_KEY );
		}, [] );

		var childState    = useState( !! config.childCssDisabled );
		var childDisabled = childState[0];
		var setChildDisabled = childState[1];
		var busyState = useState( false );
		var busy      = busyState[0];
		var setBusy   = busyState[1];

		if ( ! PluginMoreMenuItem ) {
			return null;
		}

		var items = [
			el( PluginMoreMenuItem, {
				key:  'ekwa-select-mode',
				icon: on ? 'yes' : 'grid-view',
				onClick: function () {
					dispatch( 'core/preferences' ).toggle( PREF_SCOPE, PREF_KEY );
					syncCanvasClass();
				},
			}, on ? __( 'Select mode (X-ray) — on', 'ekwa' ) : __( 'Select mode (X-ray)', 'ekwa' ) ),
		];

		if ( config.canToggleChildCss && config.hasChildTheme ) {
			items.push(
				el( PluginMoreMenuItem, {
					key:  'ekwa-child-css',
					icon: childDisabled ? 'yes' : 'editor-code',
					onClick: function () {
						if ( busy ) {
							return;
						}
						setBusy( true );
						var next = ! childDisabled;
						apiFetch( {
							path:   '/ekwa/v1/editor-child-css',
							method: 'POST',
							data:   { disabled: next },
						} ).then( function () {
							setChildDisabled( next );
							setBusy( false );
							// Editor styles are compiled server-side at load.
							if ( window.confirm( __( 'Saved. Reload the editor now to apply? Unsaved changes will be lost.', 'ekwa' ) ) ) {
								window.location.reload();
							}
						} ).catch( function () {
							setBusy( false );
							window.alert( __( 'Could not save the setting. Please try again.', 'ekwa' ) );
						} );
					},
				}, childDisabled
					? __( 'Child CSS in editor — disabled', 'ekwa' )
					: __( 'Disable child CSS in editor', 'ekwa' ) )
			);
		}

		return el( Fragment, null, items );
	}

	registerPlugin( 'ekwa-select-mode', {
		render: SelectModeMenu,
	} );
} )( window.wp );
