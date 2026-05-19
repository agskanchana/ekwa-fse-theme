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

	/* -- Add Google Font ------------------------------------------------- */
	$addGoogleBtn.on( 'click', function () {
		var $row = renderTemplate( 'tmpl-ekwa-fonts-new-google' );
		if ( ! $row ) { return; }
		$list.append( $row );
		var $family = $row.find( '.ekwa-fonts-family-search' );
		var $var    = $row.find( '.ekwa-fonts-varname' );
		wireVarNameAutosync( $family, $var );
		attachAutocomplete(
			$family,
			$row.find( '.ekwa-fonts-suggest' ),
			$row.find( '.ekwa-fonts-preview' )
		);
	} );

	/* -- Add Upload Font ------------------------------------------------- */
	$addUploadBtn.on( 'click', function () {
		var $row = renderTemplate( 'tmpl-ekwa-fonts-new-upload' );
		if ( ! $row ) { return; }
		$list.append( $row );
		wireVarNameAutosync(
			$row.find( '.ekwa-fonts-family' ),
			$row.find( '.ekwa-fonts-varname' )
		);
	} );

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

} )( jQuery, window.ekwaFonts );
