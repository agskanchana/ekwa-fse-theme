/**
 * Ekwa icon library — every Font Awesome icon the theme bundles, for the icon
 * pickers (menu items / settings in ekwa-admin.js, the Ekwa Icon block, the
 * inline FA Icon format).
 *
 * Data comes from assets/fontawesome/icons.js (window.ekwaFaIconData), which
 * is generated from the bundled all.min.css + webfonts, so it always matches
 * what the site can actually render. Each picker keeps its own short list of
 * popular icons; search() puts those first and then the full set.
 *
 * window.ekwaIconLibrary.search( query, popular, limit )
 *   → array of { name, cls } (at most `limit`), with `.total` = all matches.
 */
( function () {
	'use strict';

	var STYLE = { s: 'fa-solid', r: 'fa-regular', b: 'fa-brands' };
	var LABEL = { s: '', r: ' (regular)', b: '' };

	var data = window.ekwaFaIconData && window.ekwaFaIconData.icons ? window.ekwaFaIconData.icons : [];
	var all  = data.map( function ( row ) {
		var cls = STYLE[ row.s ] + ' fa-' + row.n;
		return {
			name:  ( row.l || row.n ) + LABEL[ row.s ],
			cls:   cls,
			// Lowercase haystack: label, class, old names and Font Awesome's
			// own search keywords ("dentist" finds the tooth).
			terms: ( ( row.l || '' ) + ' ' + cls + ' ' + ( row.a || '' ) ).toLowerCase(),
		};
	} );

	function matches( icon, q ) {
		if ( ! q ) { return true; }
		var hay = icon.terms || ( icon.name + ' ' + icon.cls ).toLowerCase();
		// Every word must appear: "arrow right" finds arrow-right and circle-arrow-right.
		var words = q.split( /\s+/ );
		for ( var i = 0; i < words.length; i++ ) {
			if ( hay.indexOf( words[ i ] ) === -1 ) { return false; }
		}
		return true;
	}

	function search( query, popular, limit ) {
		var q    = String( query || '' ).toLowerCase().trim().replace( /^fa-/, '' );
		var seen = {};
		var hits = [];

		( popular || [] ).forEach( function ( icon ) {
			if ( ! seen[ icon.cls ] && matches( icon, q ) ) {
				seen[ icon.cls ] = true;
				hits.push( icon );
			}
		} );

		// Best matches first: the exact icon ("arrow right" → fa-arrow-right),
		// then an exact old name or keyword ("home" → House, "close" → Xmark),
		// then names starting with the query, then the rest.
		// Within a tier the list keeps its alphabetical order.
		var slug  = q.replace( /\s+/g, '-' );
		var tiers = [ [], [], [], [] ];
		all.forEach( function ( icon ) {
			if ( seen[ icon.cls ] || ! matches( icon, q ) ) { return; }
			seen[ icon.cls ] = true;
			var name = icon.cls.slice( icon.cls.lastIndexOf( ' fa-' ) + 4 );
			var tier = 3;
			if ( ! q ) {
				tier = 3;
			} else if ( name === slug ) {
				tier = 0;
			} else if ( ( ' ' + icon.terms + ' ' ).indexOf( ' ' + q + ' ' ) !== -1 ) {
				tier = 1;
			} else if ( name.indexOf( slug ) === 0 ) {
				tier = 2;
			}
			tiers[ tier ].push( icon );
		} );
		hits = hits.concat( tiers[ 0 ], tiers[ 1 ], tiers[ 2 ], tiers[ 3 ] );

		var out = limit ? hits.slice( 0, limit ) : hits;
		out.total = hits.length;
		return out;
	}

	window.ekwaIconLibrary = {
		all:    all,
		count:  all.length,
		search: search,
	};
}() );
