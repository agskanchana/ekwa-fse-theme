/**
 * Shared video schema validation and post saving guard.
 */
( function ( wp ) {
	'use strict';

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var knownFields = [ 'videoTitle', 'videoDescription', 'thumbnailUrl', 'uploadDate', 'videoDuration' ];
	var config = window.ekwaVideoSchemaConfig;
	var required = config && Array.isArray( config.required )
		? config.required : [ 'videoTitle', 'thumbnailUrl', 'uploadDate', 'videoDuration' ];
	var extractors = Object.create( null );
	var loading = Object.create( null );
	var locked = false;
	var subscribed = false;
	var noticeSignature = '';
	var lockId = 'ekwa-video-schema';
	var noticeId = 'ekwa-video-schema-missing';
	var labels = {
		videoTitle: __( 'Video title', 'ekwa' ),
		videoDescription: __( 'Video description', 'ekwa' ),
		thumbnailUrl: __( 'Thumbnail', 'ekwa' ),
		uploadDate: __( 'Upload date', 'ekwa' ),
		videoDuration: __( 'Duration', 'ekwa' ),
	};

	required = required.filter( function ( key ) {
		return knownFields.indexOf( key ) !== -1;
	} );

	function trimmed( value ) {
		return 'string' === typeof value ? value.trim() : '';
	}

	function isRenderable( blockName, attrs ) {
		attrs = attrs || {};
		var url = trimmed( attrs.videoUrl );
		if ( ! url ) { return false; }
		if ( trimmed( attrs.videoId ) ) { return true; }
		var extractId = extractors[ blockName ];
		return 'function' === typeof extractId && !! trimmed( extractId( url ) );
	}

	function getMissing( blockName, attrs ) {
		if ( ! isRenderable( blockName, attrs ) ) { return []; }
		attrs = attrs || {};
		return required.filter( function ( key ) {
			var value = 'string' === typeof attrs[ key ] ? attrs[ key ] : '';
			if ( 'thumbnailUrl' === key ) {
				var custom = attrs.customThumbnail;
				return ! value.trim() && ! ( custom && 'object' === typeof custom && custom.url );
			}
			if ( 'uploadDate' === key ) {
				return ! /^\d{4}-\d{2}-\d{2}/.test( value );
			}
			if ( 'videoDuration' === key ) {
				var duration = value.match( /^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/ );
				return ! duration || ! ( Number( duration[ 1 ] ) > 0 || Number( duration[ 2 ] ) > 0 || Number( duration[ 3 ] ) > 0 );
			}
			return ! value.trim();
		} );
	}

	function formatMissing( keys ) {
		return keys.map( function ( key ) { return labels[ key ]; } ).join( ', ' );
	}

	// These stores are not available in every editor, or at script load time.
	function selectStore( name ) {
		try {
			return wp.data && 'function' === typeof wp.data.select ? wp.data.select( name ) : null;
		} catch ( err ) {
			return null;
		}
	}

	function dispatchStore( name ) {
		try {
			return wp.data && 'function' === typeof wp.data.dispatch ? wp.data.dispatch( name ) : null;
		} catch ( err ) {
			return null;
		}
	}

	function collectIncomplete() {
		var blocks = selectStore( 'core/block-editor' );
		var incomplete = [];
		if ( ! blocks || 'function' !== typeof blocks.getBlockAttributes ) { return incomplete; }
		Object.keys( extractors ).forEach( function ( name ) {
			var ids = [];
			if ( 'function' === typeof blocks.getBlocksByName ) {
				ids = blocks.getBlocksByName( name ) || [];
			} else if ( 'function' === typeof blocks.getClientIdsWithDescendants && 'function' === typeof blocks.getBlockName ) {
				ids = ( blocks.getClientIdsWithDescendants() || [] ).filter( function ( id ) {
					return blocks.getBlockName( id ) === name;
				} );
			}
			ids.forEach( function ( id ) {
				var missing = getMissing( name, blocks.getBlockAttributes( id ) );
				if ( missing.length ) {
					incomplete.push( { clientId: id, missing: missing } );
				}
			} );
		} );
		return incomplete;
	}

	function missingMessage( incomplete ) {
		if ( 1 === incomplete.length ) {
			return sprintf(
				__( 'A video on this page is missing information its schema markup needs: %s. Fill it in on the video block — saving is disabled until then.', 'ekwa' ),
				formatMissing( incomplete[ 0 ].missing )
			);
		}
		return sprintf(
			_n(
				'%d video on this page is missing information its schema markup needs. Fill it in on the video block — saving is disabled until then.',
				'%d videos on this page are missing information their schema markup needs. Fill it in on each video block — saving is disabled until then.',
				incomplete.length,
				'ekwa'
			),
			incomplete.length
		);
	}

	function subscribeToBlocks() {
		if ( subscribed || ! wp.data || 'function' !== typeof wp.data.subscribe || ! selectStore( 'core/block-editor' ) ) { return; }
		try {
			wp.data.subscribe( evaluate, 'core/block-editor' );
			subscribed = true;
		} catch ( err ) {
			// Registration or a later loading update can retry once the store exists.
		}
	}

	function evaluate() {
		subscribeToBlocks();
		var editor = dispatchStore( 'core/editor' );
		if ( ! editor || 'function' !== typeof editor.lockPostSaving || 'function' !== typeof editor.unlockPostSaving ) { return []; }
		var incomplete = collectIncomplete();
		var shouldLock = incomplete.length > 0;
		if ( locked !== shouldLock ) {
			locked = shouldLock;
			if ( locked ) {
				editor.lockPostSaving( lockId );
			} else {
				editor.unlockPostSaving( lockId );
			}
		}

		var visible = incomplete.filter( function ( block ) { return ! loading[ block.clientId ]; } );
		var notices = dispatchStore( 'core/notices' );
		if ( ! notices ) { return incomplete; }
		if ( visible.length && 'function' === typeof notices.createErrorNotice ) {
			var message = missingMessage( visible );
			var target = visible[ 0 ].clientId;
			var signature = JSON.stringify( [ message, target ] );
			if ( signature !== noticeSignature ) {
				noticeSignature = signature;
				notices.createErrorNotice( message, {
					id: noticeId,
					isDismissible: false,
					actions: [ {
						label: __( 'Go to video', 'ekwa' ),
						onClick: function () {
							var blocks = dispatchStore( 'core/block-editor' );
							if ( blocks && 'function' === typeof blocks.selectBlock ) { blocks.selectBlock( target ); }
						},
					} ],
				} );
			}
		} else if ( ! visible.length && noticeSignature && 'function' === typeof notices.removeNotice ) {
			noticeSignature = '';
			notices.removeNotice( noticeId );
		}
		return incomplete;
	}

	window.ekwaVideoSchema = {
		registerBlock: function ( blockName, extractId ) {
			extractors[ blockName ] = extractId;
			evaluate();
		},
		// False when a child theme emptied the required list via the
		// ekwa_video_schema_required_fields filter: blocks then fall back to
		// their old soft warning.
		isEnabled: required.length > 0,
		isRenderable: isRenderable,
		getMissing: getMissing,
		labels: labels,
		formatMissing: formatMissing,
		setLoading: function ( clientId, isLoading ) {
			if ( isLoading ) {
				loading[ clientId ] = true;
			} else {
				delete loading[ clientId ];
			}
			evaluate();
		},
	};

	if ( wp.hooks && 'function' === typeof wp.hooks.addFilter ) {
		wp.hooks.addFilter( 'editor.preSavePost', 'ekwa/video-schema-guard', function ( edits, options ) {
			if ( options && ( options.isAutosave || options.isPreview ) ) { return edits; }
			var incomplete = evaluate();
			if ( incomplete.length ) { throw new Error( missingMessage( incomplete ) ); }
			return edits;
		} );
	}

	evaluate();
} )( window.wp );
