/**
 * Ekwa Custom Fonts — admin UI.
 *
 * Powers the Fonts tab on the Ekwa Settings page:
 *   - Google Font picker (autocomplete, weight selection, server-side download)
 *   - Custom font file upload (one weight per upload, appends to a variable)
 *   - Per-row rename of CSS variable
 *   - Per-row remove (registry only; files on disk are left alone)
 */
( function ( $, ekwaFonts ) {
	'use strict';

	if ( ! ekwaFonts ) { return; }

	var $list             = $( '#ekwa-fonts-list' );
	var $addGoogleBtn     = $( '#ekwa-fonts-add-google' );
	var $addUploadBtn     = $( '#ekwa-fonts-add-upload' );
	var $detectAiBtn      = $( '#ekwa-fonts-detect-ai' );
	var $aiResults        = $( '#ekwa-fonts-ai-results' );
	var catalogPromise    = null;

	function loadCatalog() {
		if ( catalogPromise ) { return catalogPromise; }
		catalogPromise = $.post( ekwaFonts.ajaxUrl, {
			action : 'ekwa_fonts_catalog',
			nonce  : ekwaFonts.nonce,
		} ).then( function ( res ) {
			return ( res && res.success && res.data && res.data.families ) ? res.data.families : [];
		}, function () { return []; } );
		return catalogPromise;
	}

	function renderTemplate( id ) {
		var tpl = document.getElementById( id );
		if ( ! tpl ) { return null; }
		var $wrap = $( '<div></div>' ).html( tpl.innerHTML );
		return $wrap.children().first();
	}

	function showMsg( $row, text, isError ) {
		$row.find( '.ekwa-fonts-msg' )
			.css( 'color', isError ? '#b32d2e' : '#2271b1' )
			.text( text || '' );
	}

	function spinnerOn( $row )  { $row.find( '.spinner' ).addClass( 'is-active' ).css( 'visibility', 'visible' ); }
	function spinnerOff( $row ) { $row.find( '.spinner' ).removeClass( 'is-active' ).css( 'visibility', 'hidden' ); }

	/**
	 * Turn a font-family display name into a valid CSS variable slug.
	 *   "Playfair Display" -> "playfair-display"
	 */
	function slugify( name ) {
		return ( name || '' )
			.toString()
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' );
	}

	/**
	 * Keep the var-name input in sync with the family input — until the user
	 * manually edits the var name, at which point it's "touched" and we leave
	 * it alone.
	 */
	function wireVarNameAutosync( $family, $var ) {
		// User-typed input marks the field as touched. Sync changes triggered
		// via .val() + .trigger('input', { silent: true }) do not.
		$var.on( 'input', function ( e, opts ) {
			if ( opts && opts.silent ) { return; }
			$var.data( 'touched', true );
		} );

		function sync() {
			if ( $var.data( 'touched' ) ) { return; }
			$var.val( slugify( $family.val() ) ).trigger( 'input', { silent: true } );
		}

		$family.on( 'input change', sync );
		// Expose so the autocomplete pick handler can call it after .val().
		$family.data( 'syncVarName', sync );
	}

	/**
	 * Inject a single <link rel=stylesheet> to Google Fonts that covers all
	 * the family names we haven't already requested. Subsequent calls only
	 * fetch the deltas.
	 */
	var googleFontsLoaded = {};
	function loadGoogleFontPreviews( names ) {
		var toLoad = [];
		names.forEach( function ( n ) {
			if ( ! googleFontsLoaded[ n ] ) {
				googleFontsLoaded[ n ] = true;
				toLoad.push( n );
			}
		} );
		if ( ! toLoad.length ) { return; }
		var params = toLoad.map( function ( n ) {
			return 'family=' + encodeURIComponent( n ).replace( /%20/g, '+' );
		} ).join( '&' );
		var link = document.createElement( 'link' );
		link.rel  = 'stylesheet';
		link.href = 'https://fonts.googleapis.com/css2?' + params + '&display=swap';
		document.head.appendChild( link );
	}

	function attachAutocomplete( $input, $suggest, $preview ) {
		var families = [];
		loadCatalog().then( function ( list ) { families = list; } );

		function close() {
			$suggest.empty().hide();
		}

		function updatePreview( name ) {
			if ( ! $preview || ! $preview.length ) { return; }
			if ( ! name ) { $preview.hide(); return; }
			loadGoogleFontPreviews( [ name ] );
			$preview.show().find( '.ekwa-fonts-preview-sample' )
				.css( 'font-family', "'" + name.replace( /'/g, '' ) + "', system-ui, sans-serif" );
		}

		$input.on( 'input', function () {
			var q = $( this ).val().toLowerCase().trim();
			if ( q.length < 2 ) { close(); return; }
			var matches = families.filter( function ( f ) {
				return f.toLowerCase().indexOf( q ) !== -1;
			} ).slice( 0, 20 );
			if ( ! matches.length ) {
				$suggest.html( '<li style="color:#646970;">' + ekwaFonts.i18n.noResults + '</li>' ).show();
				return;
			}
			$suggest.empty();
			matches.forEach( function ( name ) {
				var $li = $( '<li></li>' )
					.text( name )
					.css( 'font-family', "'" + name.replace( /'/g, '' ) + "', system-ui, sans-serif" )
					.append( $( '<span class="ekwa-fonts-suggest-fallback"></span>' ).text( '(' + name + ')' ) )
					.data( 'name', name );
				$suggest.append( $li );
			} );
			loadGoogleFontPreviews( matches );
			$suggest.show();
		} );

		$suggest.on( 'click', 'li', function () {
			var name = $( this ).data( 'name' );
			if ( name ) {
				$input.val( name );
				var sync = $input.data( 'syncVarName' );
				if ( typeof sync === 'function' ) { sync(); }
				updatePreview( name );
				close();
			}
		} );

		// Also refresh preview when the user types a full family name directly.
		$input.on( 'change blur', function () {
			var val = $input.val().trim();
			if ( val && families.indexOf( val ) > -1 ) {
				updatePreview( val );
			}
		} );

		$input.on( 'blur', function () { setTimeout( close, 200 ); } );
	}

	/**
	 * Bring a freshly added row into view (used after AI "Embed" clicks).
	 */
	function scrollToRow( $row ) {
		if ( $row && $row[0] && $row[0].scrollIntoView ) {
			$row[0].scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	/**
	 * Create a "New Google Font" row, fully wired (autocomplete + var sync).
	 * Returns the jQuery row so callers can pre-fill it.
	 */
	function createGoogleRow() {
		var $row = renderTemplate( 'tmpl-ekwa-fonts-new-google' );
		if ( ! $row ) { return null; }
		$list.append( $row );
		var $family = $row.find( '.ekwa-fonts-family-search' );
		var $var    = $row.find( '.ekwa-fonts-varname' );
		wireVarNameAutosync( $family, $var );
		attachAutocomplete(
			$family,
			$row.find( '.ekwa-fonts-suggest' ),
			$row.find( '.ekwa-fonts-preview' )
		);
		return $row;
	}

	/**
	 * Create an "Upload Font File" row, wired for var-name auto-sync.
	 */
	function createUploadRow() {
		var $row = renderTemplate( 'tmpl-ekwa-fonts-new-upload' );
		if ( ! $row ) { return null; }
		$list.append( $row );
		wireVarNameAutosync(
			$row.find( '.ekwa-fonts-family' ),
			$row.find( '.ekwa-fonts-varname' )
		);
		return $row;
	}

	/* -- Add Google Font ------------------------------------------------- */
	$addGoogleBtn.on( 'click', function () { createGoogleRow(); } );

	/* -- Add Upload Font ------------------------------------------------- */
	$addUploadBtn.on( 'click', function () { createUploadRow(); } );

	/* -- Cancel new row -------------------------------------------------- */
	$list.on( 'click', '.ekwa-fonts-cancel', function () {
		$( this ).closest( '.ekwa-fonts-new' ).remove();
	} );

	/* -- Download Google Font ------------------------------------------- */
	$list.on( 'click', '.ekwa-fonts-download', function () {
		var $row     = $( this ).closest( '.ekwa-fonts-new' );
		var family   = $row.find( '.ekwa-fonts-family-search' ).val().trim();
		var var_name = $row.find( '.ekwa-fonts-varname' ).val().trim();
		var fallback = $row.find( '.ekwa-fonts-fallback' ).val();
		var weights  = $row.find( '.ekwa-fonts-weights input:checked' ).map( function () {
			return this.value;
		} ).get();

		if ( ! family ) { showMsg( $row, ekwaFonts.i18n.missingFamily, true ); return; }
		if ( ! weights.length ) { showMsg( $row, ekwaFonts.i18n.missingWeights, true ); return; }

		showMsg( $row, ekwaFonts.i18n.downloading, false );
		spinnerOn( $row );

		$.post( ekwaFonts.ajaxUrl, {
			action   : 'ekwa_fonts_download',
			nonce    : ekwaFonts.nonce,
			family   : family,
			weights  : weights,
			var_name : var_name,
			fallback : fallback,
		} ).done( function ( res ) {
			spinnerOff( $row );
			if ( res && res.success ) {
				$row.replaceWith( res.data.rowHtml );
			} else {
				showMsg( $row, ( res && res.data && res.data.message ) || 'Error', true );
			}
		} ).fail( function () {
			spinnerOff( $row );
			showMsg( $row, 'Network error', true );
		} );
	} );

	/* -- Upload custom font file ---------------------------------------- */
	$list.on( 'click', '.ekwa-fonts-upload', function () {
		var $row     = $( this ).closest( '.ekwa-fonts-new' );
		var $file    = $row.find( '.ekwa-fonts-file' );
		var family   = $row.find( '.ekwa-fonts-family' ).val().trim();
		var weight   = $row.find( '.ekwa-fonts-weight' ).val();
		var var_name = $row.find( '.ekwa-fonts-varname' ).val().trim();
		var fallback = $row.find( '.ekwa-fonts-fallback' ).val();

		if ( ! family ) { showMsg( $row, 'Please enter a font family name.', true ); return; }
		if ( ! $file[0] || ! $file[0].files || ! $file[0].files.length ) {
			showMsg( $row, ekwaFonts.i18n.missingFile, true );
			return;
		}
		var file = $file[0].files[0];
		var ext  = ( file.name.split( '.' ).pop() || '' ).toLowerCase();
		if ( [ 'woff2', 'woff', 'ttf', 'otf' ].indexOf( ext ) === -1 ) {
			showMsg( $row, ekwaFonts.i18n.invalidExt, true );
			return;
		}

		showMsg( $row, ekwaFonts.i18n.uploading, false );
		spinnerOn( $row );

		var fd = new FormData();
		fd.append( 'action',   'ekwa_fonts_upload' );
		fd.append( 'nonce',    ekwaFonts.nonce );
		fd.append( 'family',   family );
		fd.append( 'weight',   weight );
		fd.append( 'var_name', var_name );
		fd.append( 'fallback', fallback );
		fd.append( 'file',     file );

		$.ajax( {
			url         : ekwaFonts.ajaxUrl,
			method      : 'POST',
			data        : fd,
			processData : false,
			contentType : false,
		} ).done( function ( res ) {
			spinnerOff( $row );
			if ( res && res.success ) {
				// Replace any existing row for this entry id, or append a new one.
				var existing = $list.find( '.ekwa-fonts-row[data-id="' + res.data.id + '"]' );
				if ( existing.length ) {
					existing.replaceWith( res.data.rowHtml );
				} else {
					$row.replaceWith( res.data.rowHtml );
					return;
				}
				$row.remove();
			} else {
				showMsg( $row, ( res && res.data && res.data.message ) || 'Error', true );
			}
		} ).fail( function () {
			spinnerOff( $row );
			showMsg( $row, 'Network error', true );
		} );
	} );

	/* -- Remove entry --------------------------------------------------- */
	$list.on( 'click', '.ekwa-fonts-remove', function () {
		if ( ! window.confirm( ekwaFonts.i18n.confirmRemove ) ) { return; }
		var $row = $( this ).closest( '.ekwa-fonts-row' );
		var id   = $row.data( 'id' );
		$.post( ekwaFonts.ajaxUrl, {
			action : 'ekwa_fonts_remove',
			nonce  : ekwaFonts.nonce,
			id     : id,
		} ).always( function () { $row.remove(); } );
	} );

	/* -- Rename variable ------------------------------------------------ */
	$list.on( 'click', '.ekwa-fonts-rename', function () {
		var $row = $( this ).closest( '.ekwa-fonts-row' );
		var id   = $row.data( 'id' );
		var name = $row.find( '.ekwa-fonts-rename-var' ).val();
		$.post( ekwaFonts.ajaxUrl, {
			action   : 'ekwa_fonts_rename',
			nonce    : ekwaFonts.nonce,
			id       : id,
			var_name : name,
		} ).done( function ( res ) {
			if ( res && res.success ) {
				$row.find( '.ekwa-fonts-var' ).text( '--' + res.data.var_name );
				$row.find( '.ekwa-fonts-rename-var' ).val( res.data.var_name );
			}
		} );
	} );

	/* ===================================================================
	 * AI: detect fonts used in the child theme stylesheet
	 * =================================================================== */

	var i18n = ekwaFonts.i18n || {};

	function sprintf1( tpl, val ) {
		return String( tpl || '' ).replace( '%s', val );
	}

	/**
	 * Open a Google-font row pre-filled with a family + weights, ready for the
	 * user to review and hit "Download & save".
	 */
	function embedGoogleFont( family, weights ) {
		var $row = createGoogleRow();
		if ( ! $row ) { return; }
		var $family = $row.find( '.ekwa-fonts-family-search' );
		$family.val( family );
		var sync = $family.data( 'syncVarName' );
		if ( typeof sync === 'function' ) { sync(); }
		$family.trigger( 'change' ); // refresh preview if the catalog is loaded

		var wanted = {};
		( weights || [] ).forEach( function ( w ) { wanted[ String( w ) ] = true; } );
		$row.find( '.ekwa-fonts-weights input[type=checkbox]' ).each( function () {
			this.checked = !! wanted[ this.value ];
		} );

		scrollToRow( $row );
	}

	/**
	 * Open an upload row pre-filled with a custom family name.
	 */
	function embedCustomFont( family ) {
		var $row = createUploadRow();
		if ( ! $row ) { return; }
		$row.find( '.ekwa-fonts-family' ).val( family ).trigger( 'input' );
		scrollToRow( $row );
	}

	function renderAiResults( fonts ) {
		$aiResults.empty();

		if ( ! fonts || ! fonts.length ) {
			$aiResults.html(
				'<div class="notice notice-warning inline"><p></p></div>'
			).find( 'p' ).text( i18n.aiNoFonts || 'No fonts detected.' );
			return;
		}

		var $panel = $( '<div class="ekwa-fonts-ai-panel"></div>' );

		var $head = $( '<div class="ekwa-fonts-ai-head"></div>' );
		$( '<h3></h3>' ).text( i18n.aiHeading || 'Detected fonts' ).appendTo( $head );
		$( '<button type="button" class="button-link ekwa-fonts-ai-dismiss"></button>' )
			.text( i18n.dismiss || 'Dismiss' ).appendTo( $head );
		$panel.append( $head );

		$( '<p class="description"></p>' ).text( i18n.aiIntro || '' ).appendTo( $panel );

		var $cards = $( '<div class="ekwa-fonts-ai-cards"></div>' );

		fonts.forEach( function ( f ) {
			var isGoogle = !! f.is_google_font;
			var weights  = f.weights || [];
			var styles   = f.styles || [];
			var hasItalic = styles.indexOf( 'italic' ) !== -1;

			var $card = $( '<div class="ekwa-fonts-ai-card"></div>' );

			$( '<span class="ekwa-fonts-ai-card-name"></span>' ).text( f.family ).appendTo( $card );
			$( '<span class="ekwa-fonts-ai-card-src"></span>' )
				.addClass( isGoogle ? '' : 'is-custom' )
				.text( isGoogle ? ( i18n.srcGoogle || 'Google Font' ) : ( i18n.srcCustom || 'Custom' ) )
				.appendTo( $card );

			var $weights = $( '<div class="ekwa-fonts-ai-card-weights"></div>' );
			weights.forEach( function ( w ) {
				$( '<span class="ekwa-fonts-weight-chip"></span>' ).text( w ).appendTo( $weights );
			} );
			$card.append( $weights );

			var metaBits = [];
			if ( f.usage_count ) { metaBits.push( sprintf1( i18n.aiUsage || 'used in %s rules', f.usage_count ) ); }
			if ( f.notes ) { metaBits.push( f.notes ); }
			if ( hasItalic ) { metaBits.push( i18n.aiItalic || 'italic used' ); }
			if ( metaBits.length ) {
				$( '<div class="ekwa-fonts-ai-card-meta"></div>' ).text( metaBits.join( ' · ' ) ).appendTo( $card );
			}

			$( '<button type="button" class="button button-primary ekwa-fonts-ai-embed"></button>' )
				.text( isGoogle ? ( i18n.embed || 'Embed' ) : ( i18n.embedUpload || 'Upload to embed' ) )
				.data( 'family', f.family )
				.data( 'weights', weights )
				.data( 'google', isGoogle ? 1 : 0 )
				.appendTo( $card );

			$cards.append( $card );
		} );

		$panel.append( $cards );
		$aiResults.append( $panel );
	}

	$detectAiBtn.on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		$aiResults.show().html(
			'<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span></p>'
		);
		$aiResults.find( 'p' ).append(
			document.createTextNode( i18n.detecting || 'Scanning…' )
		);

		$.post( ekwaFonts.ajaxUrl, {
			action : 'ekwa_fonts_ai_detect',
			nonce  : ekwaFonts.nonce,
		} ).done( function ( res ) {
			if ( res && res.success && res.data && res.data.fonts ) {
				renderAiResults( res.data.fonts );
			} else {
				var msg = ( res && res.data && res.data.message ) || i18n.aiError || 'Error';
				$aiResults.html( '<div class="notice notice-error inline"><p></p></div>' ).find( 'p' ).text( msg );
			}
		} ).fail( function () {
			$aiResults.html( '<div class="notice notice-error inline"><p></p></div>' )
				.find( 'p' ).text( i18n.aiError || 'Network error' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	/* -- AI: embed a detected font -------------------------------------- */
	$aiResults.on( 'click', '.ekwa-fonts-ai-embed', function () {
		var $b = $( this );
		if ( $b.data( 'google' ) ) {
			embedGoogleFont( $b.data( 'family' ), $b.data( 'weights' ) );
		} else {
			embedCustomFont( $b.data( 'family' ) );
		}
	} );

	/* -- AI: dismiss the results panel ---------------------------------- */
	$aiResults.on( 'click', '.ekwa-fonts-ai-dismiss', function () {
		$aiResults.hide().empty();
	} );

} )( jQuery, window.ekwaFonts );
