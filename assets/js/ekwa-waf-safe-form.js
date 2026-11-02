/**
 * WAF-safe submit for the Ekwa settings / schema forms.
 *
 * ModSecurity on shared hosting answers 403 to the whole POST when any field
 * looks like code (the settings form carries the site's CSS and ekwa-child.js).
 * On submit this packs every field into ONE base64 value, `ekwa_waf_payload`;
 * inc/ekwa-waf-safe-form.php unpacks it into $_POST before the save handlers
 * run. If anything here fails, the form submits the old way.
 */
( function () {
	'use strict';

	var FIELD = 'ekwa_waf_payload';
	var forms = ( window.ekwaWafSafeForms && window.ekwaWafSafeForms.forms ) || {};

	// What a plain urlencoded submit sends for a textarea: CRLF line breaks.
	function crlf( s ) {
		return s.replace( /\r\n|\r|\n/g, '\r\n' );
	}

	function toBase64( str ) {
		var bytes = new TextEncoder().encode( str );
		var bin   = '';
		for ( var i = 0; i < bytes.length; i += 0x8000 ) {
			bin += String.fromCharCode.apply( null, bytes.subarray( i, i + 0x8000 ) );
		}
		return btoa( bin );
	}

	// Listening on document (bubble) runs after the form's own submit listeners,
	// which sync CodeMirror into the textareas and fill the *_b64 twins.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( e.defaultPrevented || ! form || ! form.id || ! Object.prototype.hasOwnProperty.call( forms, form.id ) ) {
			return;
		}
		var keep = { _wp_http_referer: 1 };
		keep[ forms[ form.id ] ] = 1;
		keep[ FIELD ] = 1;
		if ( ! form.elements[ forms[ form.id ] ] ) {
			return;
		}

		var encoded;
		try {
			var submitter = e.submitter || null;
			var data;
			try {
				data = new FormData( form, submitter );
			} catch ( err ) {
				data = new FormData( form );
			}
			// Browsers without FormData(form, submitter) ignore the 2nd argument.
			if ( submitter && submitter.name && ! data.has( submitter.name ) ) {
				data.append( submitter.name, submitter.value );
			}
			var params = new URLSearchParams();
			data.forEach( function ( value, name ) {
				if ( keep[ name ] || 'string' !== typeof value ) {
					return; // nonce/referer stay real fields; files stay in the form
				}
				params.append( crlf( name ), crlf( value ) );
			} );
			encoded = toBase64( params.toString() );
		} catch ( err ) {
			return; // old-style submit
		}

		var hidden = form.querySelector( 'input[name="' + FIELD + '"]' );
		if ( ! hidden ) {
			hidden      = document.createElement( 'input' );
			hidden.type = 'hidden';
			hidden.name = FIELD;
			form.appendChild( hidden );
		}
		hidden.value = encoded;

		// Keep the packed fields out of the request body. The browser builds the
		// body right after this event returns, so they only need to stay disabled
		// until the next tick (which also covers a submit someone cancels).
		var packed = [];
		Array.prototype.forEach.call( form.elements, function ( el ) {
			if ( ! el.name || el.disabled || keep[ el.name ] || 'file' === el.type || 'FIELDSET' === el.tagName || 'OBJECT' === el.tagName || 'OUTPUT' === el.tagName ) {
				return;
			}
			el.disabled = true;
			packed.push( el );
		} );
		setTimeout( function () {
			packed.forEach( function ( el ) {
				el.disabled = false;
			} );
			hidden.value = '';
		}, 0 );
	} );
}() );
