/**
 * Ekwa Link Source Control — shared Inspector helper.
 *
 * Exposes window.EkwaLinkSource.Controls for the link / button / card-link
 * editor scripts. Provides a single Source select (External / Internal /
 * Media file / Appointment) plus the input that matches the chosen mode.
 *
 * Reads window.ekwaBlockData (localized in PHP) for the configured
 * appointment URL preview.
 */
( function ( wp ) {
	'use strict';

	var el               = wp.element.createElement;
	var Fragment         = wp.element.Fragment;
	var useState         = wp.element.useState;
	var SelectControl    = wp.components.SelectControl;
	var TextControl      = wp.components.TextControl;
	var ComboboxControl  = wp.components.ComboboxControl;
	var Button           = wp.components.Button;
	var Notice           = wp.components.Notice;
	var useSelect        = wp.data.useSelect;
	var MediaUpload      = wp.blockEditor && wp.blockEditor.MediaUpload;
	var MediaUploadCheck = wp.blockEditor && wp.blockEditor.MediaUploadCheck;
	var __               = wp.i18n.__;
	var decodeEntities   = ( wp.htmlEntities && wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };

	var SOURCE_OPTIONS = [
		{ label: __( 'External URL', 'ekwa' ),               value: 'external' },
		{ label: __( 'Existing Page or Post', 'ekwa' ),      value: 'internal' },
		{ label: __( 'Media File (from library)', 'ekwa' ),  value: 'media' },
		{ label: __( 'Appointment URL (from settings)', 'ekwa' ), value: 'appointment' }
	];

	var TYPE_OPTIONS = [
		{ label: __( 'Page', 'ekwa' ), value: 'page' },
		{ label: __( 'Post', 'ekwa' ), value: 'post' }
	];

	/**
	 * Page/Post search-and-select using the core data store.
	 */
	function PageSourceControl( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var pageType      = attributes.pageType || 'page';
		var pageId        = attributes.pageId   || 0;

		var searchState   = useState( '' );
		var searchValue   = searchState[0];
		var setSearchValue = searchState[1];

		var data = useSelect( function ( select ) {
			var core  = select( 'core' );
			var query = {
				per_page: 100,
				status:   'publish',
				orderby:  'title',
				order:    'asc',
				_fields:  'id,title'
			};
			if ( searchValue ) {
				query.search = searchValue;
			}
			return {
				results:  core.getEntityRecords( 'postType', pageType, query ) || [],
				selected: pageId ? core.getEntityRecord( 'postType', pageType, pageId ) : null
			};
		}, [ pageType, pageId, searchValue ] );

		function titleOf( p ) {
			var raw = ( p && p.title && p.title.rendered ) || '';
			return raw ? decodeEntities( raw ) : __( '(no title)', 'ekwa' );
		}

		var options = data.results.map( function ( p ) {
			return { value: String( p.id ), label: titleOf( p ) };
		} );

		// Ensure the currently-selected item appears even if it isn't in the
		// current search results (e.g. on first load with no search term).
		if ( data.selected ) {
			var sid = String( data.selected.id );
			var present = options.some( function ( o ) { return o.value === sid; } );
			if ( ! present ) {
				options.unshift( { value: sid, label: titleOf( data.selected ) } );
			}
		}

		return el( Fragment, null,
			el( SelectControl, {
				label:    __( 'Type', 'ekwa' ),
				value:    pageType,
				options:  TYPE_OPTIONS,
				onChange: function ( v ) { setAttributes( { pageType: v, pageId: 0 } ); },
				__next40pxDefaultSize:   true,
				__nextHasNoMarginBottom: true
			} ),
			el( ComboboxControl, {
				label:    'page' === pageType ? __( 'Page', 'ekwa' ) : __( 'Post', 'ekwa' ),
				value:    pageId ? String( pageId ) : '',
				options:  options,
				onChange: function ( v ) {
					setAttributes( { pageId: v ? parseInt( v, 10 ) || 0 : 0 } );
				},
				onFilterValueChange: function ( v ) { setSearchValue( v ); },
				help: __( 'Search by title.', 'ekwa' )
			} )
		);
	}

	/**
	 * Pick any file from the media library — image, PDF, MP4, whatever.
	 *
	 * Stores the resolved URL alongside the attachment id: the id is what lets
	 * the URL be re-resolved later, but keeping the URL means a link still works
	 * if the attachment record is ever unavailable at render time.
	 */
	function MediaSourceControl( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var mediaUrl      = attributes.mediaUrl    || '';
		var mediaFileId   = attributes.mediaFileId || 0;

		if ( ! MediaUpload || ! MediaUploadCheck ) {
			return el( TextControl, {
				label:    __( 'Media URL', 'ekwa' ),
				value:    mediaUrl,
				onChange: function ( v ) { setAttributes( { mediaUrl: v.trim(), mediaFileId: 0 } ); },
				help:     __( 'The media picker is unavailable here — paste the file URL.', 'ekwa' )
			} );
		}

		function onSelect( media ) {
			if ( ! media || ! media.url ) { return; }
			setAttributes( { mediaUrl: media.url, mediaFileId: media.id || 0 } );
		}

		return el( Fragment, null,
			mediaUrl ? el( 'p', {
				style: {
					margin: '0 0 8px',
					padding: '6px 8px',
					background: '#f0f0f1',
					borderRadius: '2px',
					fontSize: '12px',
					wordBreak: 'break-all'
				}
			}, mediaUrl ) : null,
			el( MediaUploadCheck, null,
				el( MediaUpload, {
					onSelect: onSelect,
					// Deliberately unrestricted: a lightbox may point at an image,
					// an MP4 or a PDF, and the block should not decide which.
					value: mediaFileId,
					render: function ( open ) {
						return el( Fragment, null,
							el( Button, {
								variant: 'secondary',
								onClick: open.open
							}, mediaUrl ? __( 'Replace file', 'ekwa' ) : __( 'Select file', 'ekwa' ) ),
							mediaUrl ? el( Button, {
								variant: 'link',
								isDestructive: true,
								style: { marginLeft: '8px' },
								onClick: function () { setAttributes( { mediaUrl: '', mediaFileId: 0 } ); }
							}, __( 'Clear', 'ekwa' ) ) : null
						);
					}
				} )
			),
			el( 'p', { style: { margin: '8px 0 0', fontSize: '12px', color: '#757575' } },
				__( 'Links straight to the file in the media library — the full-size image, PDF or video.', 'ekwa' )
			)
		);
	}

	/**
	 * Main controls component — drop into a PanelBody.
	 *
	 * Props:
	 *   attributes      — block attributes
	 *   setAttributes   — block setAttributes
	 */
	function LinkSourceControls( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var linkType      = attributes.linkType || 'external';
		var url           = attributes.url      || '';

		var data            = window.ekwaBlockData || {};
		var apptUrl         = data.appointmentUrl  || '';
		var apptSettingsUrl = data.apptSettingsUrl || '';

		var children = [
			el( SelectControl, {
				key:      'source',
				label:    __( 'Link Source', 'ekwa' ),
				value:    linkType,
				options:  SOURCE_OPTIONS,
				onChange: function ( v ) { setAttributes( { linkType: v } ); },
				__next40pxDefaultSize:   true,
				__nextHasNoMarginBottom: true
			} )
		];

		if ( 'external' === linkType ) {
			children.push( el( TextControl, {
				key:      'url',
				label:    __( 'URL', 'ekwa' ),
				value:    url,
				type:     'url',
				onChange: function ( v ) { setAttributes( { url: v.trim() } ); },
				__next40pxDefaultSize:   true,
				__nextHasNoMarginBottom: true
			} ) );
		} else if ( 'internal' === linkType ) {
			children.push( el( PageSourceControl, {
				key:           'picker',
				attributes:    attributes,
				setAttributes: setAttributes
			} ) );
		} else if ( 'media' === linkType ) {
			children.push( el( MediaSourceControl, {
				key:           'media',
				attributes:    attributes,
				setAttributes: setAttributes
			} ) );
		} else if ( 'appointment' === linkType ) {
			if ( apptUrl ) {
				children.push( el( Notice, {
					key:           'appt-info',
					status:        'info',
					isDismissible: false
				}, __( 'Resolves to:', 'ekwa' ), ' ', el( 'code', null, apptUrl ) ) );
			} else {
				children.push( el( Notice, {
					key:           'appt-warn',
					status:        'warning',
					isDismissible: false
				},
					__( 'No appointment URL configured. ', 'ekwa' ),
					el( 'a', { href: apptSettingsUrl, target: '_blank', rel: 'noopener noreferrer' },
						__( 'Open Ekwa Settings', 'ekwa' )
					)
				) );
			}
		}

		return el( Fragment, null, children );
	}

	window.EkwaLinkSource = { Controls: LinkSourceControls };
} )( window.wp );
