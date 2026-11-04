/**
 * Ekwa — PageSpeed & Accessibility dashboard (Site Audit → PageSpeed).
 *
 * Lists every published page and post (separately) with PASS/FAIL per device
 * against the team's sitemap-tool criteria, the four Lighthouse scores and
 * the lab metrics, plus charts of where the site stands. Tests run from here
 * in the browser, a few at a time, through POST /ekwa/v1/page-audit/run —
 * each result is saved on the server the moment it lands.
 *
 * PASS/FAIL is judged on Performance, Accessibility and SEO together (the
 * stored status — the sitemap tool's rules) unless this browser picked fewer
 * ("Judge PASS/FAIL on"). Blog, thank-you and policy pages are left out of the
 * charts, totals and "test all" scopes unless someone includes them.
 *
 * Plain wp.element (no build step), like the theme's other admin scripts.
 */
( function ( wp ) {
	'use strict';

	var root = document.getElementById( 'ekwa-pad-app' );
	if ( ! root || ! wp || ! wp.element || ! wp.apiFetch ) { return; }

	var el        = wp.element.createElement;
	var Fragment  = wp.element.Fragment;
	var useState  = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef    = wp.element.useRef;
	var useMemo   = wp.element.useMemo;
	var __        = wp.i18n.__;
	var sprintf   = wp.i18n.sprintf;
	var apiFetch  = wp.apiFetch;
	var Button    = wp.components.Button;
	var Spinner   = wp.components.Spinner;
	var Notice    = wp.components.Notice;

	var CFG = window.ekwaPaDash || {};

	var DEVICES = [ [ 'mobile', __( 'Mobile', 'ekwa' ) ], [ 'desktop', __( 'Desktop', 'ekwa' ) ] ];
	var SCORE_CATS = [
		[ 'performance', __( 'Performance', 'ekwa' ) ],
		[ 'accessibility', __( 'Accessibility', 'ekwa' ) ],
		[ 'seo', __( 'SEO', 'ekwa' ) ],
	];
	var CRITERIA = [
		[ 'performance', __( 'Performance', 'ekwa' ) ],
		[ 'accessibility', __( 'Accessibility', 'ekwa' ) ],
		[ 'seo', __( 'SEO', 'ekwa' ) ],
		[ 'best-practices', __( 'Best Practices', 'ekwa' ) ],
		[ 'fcp', 'FCP' ],
		[ 'lcp', 'LCP' ],
		[ 'cls', 'CLS' ],
		[ 'tbt', 'TBT' ],
	];

	// What PASS/FAIL is judged on. All three (the default) = the stored status,
	// i.e. the team's sitemap tool. A narrower choice ("Performance only", or
	// "without SEO" on the hidden test server) re-judges in this browser from
	// the failing criteria each summary keeps — nothing stored changes.
	var JUDGE_GROUPS = [
		[ 'performance', __( 'Performance', 'ekwa' ), [ 'performance', 'fcp', 'lcp', 'cls', 'tbt' ] ],
		[ 'accessibility', __( 'Accessibility', 'ekwa' ), [ 'accessibility' ] ],
		[ 'seo', __( 'SEO', 'ekwa' ), [ 'seo' ] ],
	];
	var JUDGE_ALL = JUDGE_GROUPS.map( function ( g ) { return g[ 0 ]; } );

	// ─── Helpers ────────────────────────────────────────────────────────────

	function store( key, value ) {
		try {
			if ( undefined === value ) { return window.localStorage.getItem( 'ekwaPad:' + key ); }
			window.localStorage.setItem( 'ekwaPad:' + key, String( value ) );
		} catch ( e ) {}
		return null;
	}

	function readJudge() {
		var raw  = store( 'judge' );
		var list = raw ? String( raw ).split( ',' ).filter( function ( k ) { return -1 !== JUDGE_ALL.indexOf( k ); } ) : [];
		return list.length ? list : JUDGE_ALL.slice();
	}
	var JUDGE = readJudge();

	function judgeAll() { return JUDGE.length === JUDGE_ALL.length; }

	// Does a failing criterion key count under the current choice?
	function keyCounts( key ) {
		if ( judgeAll() ) { return true; }
		return JUDGE_GROUPS.some( function ( g ) { return -1 !== JUDGE.indexOf( g[ 0 ] ) && -1 !== g[ 2 ].indexOf( key ); } );
	}

	// Left out of the dashboard: blog, thank-you and policy pages (automatic),
	// or any page someone excluded; a manual "include" brings one back.
	function isExcluded( item ) {
		return 'exclude' === item.exclude || ( !! item.kind && 'include' !== item.exclude );
	}

	function errText( err ) {
		return err && err.message ? String( err.message ) : __( 'Something went wrong. Try again.', 'ekwa' );
	}

	function ago( ts ) {
		if ( ! ts ) { return ''; }
		var diff = Math.round( ( Date.now() / 1000 ) - ts );
		var fmt  = window.Intl && window.Intl.RelativeTimeFormat ? new window.Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } ) : null;
		var unit = [ [ 60, 'second' ], [ 3600, 'minute' ], [ 86400, 'hour' ], [ 2592000, 'day' ], [ 31536000, 'month' ], [ Infinity, 'year' ] ];
		var div  = [ 1, 60, 3600, 86400, 2592000, 31536000 ];
		for ( var i = 0; i < unit.length; i++ ) {
			if ( diff < unit[ i ][ 0 ] ) {
				var n = Math.max( 0, Math.round( diff / div[ i ] ) );
				return fmt ? fmt.format( -n, unit[ i ][ 1 ] ) : n + ' ' + unit[ i ][ 1 ] + 's ago';
			}
		}
		return '';
	}

	// A stored summary's PASS/FAIL under the current choice.
	function summaryStatus( s ) {
		if ( ! s ) { return 'none'; }
		var st = s.status || 'none';
		if ( judgeAll() || 'none' === st ) { return st; }
		return ( s.keys || [] ).some( keyCounts ) ? 'fail' : 'pass';
	}

	function deviceStatus( item, device ) {
		return summaryStatus( item[ device ] );
	}

	// Overall: FAIL if any tested device fails, PASS if every tested device passes.
	function overall( item ) {
		var tested = DEVICES.filter( function ( d ) { return !! item[ d[ 0 ] ]; } );
		if ( ! tested.length ) { return 'none'; }
		return tested.some( function ( d ) { return 'fail' === deviceStatus( item, d[ 0 ] ); } ) ? 'fail' : 'pass';
	}

	function band( score ) {
		if ( null == score ) { return 'none'; }
		return score >= 90 ? 'good' : ( score >= 50 ? 'average' : 'poor' );
	}

	function psiLink( url, device ) {
		return 'https://pagespeed.web.dev/analysis?url=' + encodeURIComponent( url ) + '&form_factor=' + device;
	}

	function avg( list ) {
		var vals = list.filter( function ( v ) { return null != v; } );
		if ( ! vals.length ) { return null; }
		return Math.round( vals.reduce( function ( a, b ) { return a + b; }, 0 ) / vals.length );
	}

	function criteriaText( t ) {
		t = t || {};
		var out = [];
		if ( null != t.performance && keyCounts( 'performance' ) ) { out.push( sprintf( __( 'Performance ≥ %s', 'ekwa' ), t.performance ) ); }
		if ( null != t.accessibility && keyCounts( 'accessibility' ) ) { out.push( sprintf( __( 'Accessibility %s', 'ekwa' ), t.accessibility ) ); }
		if ( null != t.seo && keyCounts( 'seo' ) ) { out.push( sprintf( __( 'SEO %s', 'ekwa' ), t.seo ) ); }
		if ( null != t[ 'best-practices' ] && keyCounts( 'best-practices' ) ) { out.push( sprintf( __( 'Best Practices ≥ %s', 'ekwa' ), t[ 'best-practices' ] ) ); }
		if ( null != t.fcp && keyCounts( 'fcp' ) ) { out.push( 'FCP ≤ ' + t.fcp + ' s' ); }
		if ( null != t.lcp && keyCounts( 'lcp' ) ) { out.push( 'LCP ≤ ' + t.lcp + ' s' ); }
		if ( null != t.cls && keyCounts( 'cls' ) ) { out.push( 'CLS ≤ ' + t.cls ); }
		if ( null != t.tbt && keyCounts( 'tbt' ) ) { out.push( 'TBT ≤ ' + t.tbt + ' s' ); }
		return out.join( ' · ' );
	}

	// ─── Tooltip (one per page) ─────────────────────────────────────────────

	var tipListeners = [];
	function showTip( evt, lines ) {
		var x, y;
		if ( evt && 'number' === typeof evt.clientX && evt.clientX ) {
			x = evt.clientX;
			y = evt.clientY;
		} else if ( evt && evt.currentTarget && evt.currentTarget.getBoundingClientRect ) {
			var r = evt.currentTarget.getBoundingClientRect();
			x = r.left + r.width / 2;
			y = r.top;
		}
		tipListeners.forEach( function ( fn ) { fn( { x: x, y: y, lines: lines } ); } );
	}
	function hideTip() {
		tipListeners.forEach( function ( fn ) { fn( null ); } );
	}
	function Tooltip() {
		var pair = useState( null );
		useEffect( function () {
			tipListeners.push( pair[ 1 ] );
			return function () { tipListeners.splice( tipListeners.indexOf( pair[ 1 ] ), 1 ); };
		}, [] );
		var tip = pair[ 0 ];
		if ( ! tip ) { return null; }
		var left = Math.min( tip.x + 14, window.innerWidth - 280 );
		return el( 'div', { className: 'ekwa-pad-tip', role: 'tooltip', style: { left: Math.max( 8, left ), top: Math.max( 8, tip.y - 12 ) } },
			tip.lines.map( function ( l, i ) { return el( 'div', { key: i, className: 0 === i ? 'ekwa-pad-tip__head' : '' }, l ); } )
		);
	}

	function tipProps( lines, onPick ) {
		return {
			tabIndex: 0,
			onMouseMove: function ( e ) { showTip( e, lines ); },
			onMouseLeave: hideTip,
			onFocus: function ( e ) { showTip( e, lines ); },
			onBlur: hideTip,
			onClick: onPick || undefined,
			onKeyDown: onPick ? function ( e ) { if ( 'Enter' === e.key || ' ' === e.key ) { e.preventDefault(); onPick(); } } : undefined,
			role: onPick ? 'button' : 'img',
			'aria-label': lines.join( ' — ' ),
		};
	}

	// ─── Small presentational pieces ────────────────────────────────────────

	function Status( props ) {
		var st = props.status || 'none';
		var lb = { pass: __( 'PASS', 'ekwa' ), fail: __( 'FAIL', 'ekwa' ), none: __( 'Not tested', 'ekwa' ), error: __( 'Error', 'ekwa' ) }[ st ];
		return el( 'span', { className: 'ekwa-pad-status is-' + st },
			el( 'span', { 'aria-hidden': 'true' }, { pass: '✓', fail: '✕', none: '–', error: '!' }[ st ] ), ' ', lb );
	}

	function Score( props ) {
		var s = props.value;
		return el( 'span', { className: 'ekwa-pad-score is-' + band( s ), title: props.label },
			el( 'span', { className: 'ekwa-pad-score__k' }, props.short ), null == s ? '–' : String( s ) );
	}

	function Tile( props ) {
		return el( 'div', { className: 'ekwa-pad-tile' },
			el( 'div', { className: 'ekwa-pad-tile__label' }, props.label ),
			el( 'div', { className: 'ekwa-pad-tile__value' }, props.value ),
			props.sub ? el( 'div', { className: 'ekwa-pad-tile__sub' }, props.sub ) : null
		);
	}

	function Seg( props ) {
		var opts = props.options;
		return el( 'div', { className: 'ekwa-pad-seg-control', role: 'group', 'aria-label': props.label },
			opts.map( function ( o ) {
				return el( 'button', {
					key: o[ 0 ], type: 'button', 'aria-pressed': props.value === o[ 0 ],
					onClick: function () { props.onChange( o[ 0 ] ); },
				}, o[ 1 ] );
			} )
		);
	}

	// ─── Charts ─────────────────────────────────────────────────────────────

	// One whole split into parts (status share, score bands).
	function SegmentedBar( props ) {
		var parts = props.parts;
		var total = parts.reduce( function ( a, p ) { return a + p.value; }, 0 );
		return el( 'div', { className: 'ekwa-pad-sbar' },
			el( 'div', { className: 'ekwa-pad-sbar__name' }, props.name, el( 'span', { className: 'ekwa-pad-sbar__total' }, sprintf( __( '%d URLs', 'ekwa' ), total ) ) ),
			total ? el( 'div', { className: 'ekwa-pad-sbar__bar' },
				parts.filter( function ( p ) { return p.value > 0; } ).map( function ( p ) {
					var pct   = Math.round( ( p.value / total ) * 100 );
					var lines = [ props.name + ': ' + p.label, sprintf( __( '%1$d of %2$d (%3$d%%)', 'ekwa' ), p.value, total, pct ) ];
					return el( 'div', assign( {
						key: p.key,
						className: 'ekwa-pad-sbar__part is-' + p.key,
						style: { flexGrow: p.value },
					}, tipProps( lines, props.onPick ? function () { props.onPick( p.key ); } : null ) ),
						// The icon is the identity channel (status colours are not
						// colour-blind safe on their own); the share joins it when it fits.
						pct >= 5 ? el( 'span', { className: 'ekwa-pad-sbar__lbl', 'aria-hidden': 'true' }, ( p.icon || '' ) + ( pct >= 14 ? ' ' + pct + '%' : '' ) ) : null
					);
				} )
			) : el( 'div', { className: 'ekwa-pad-sbar__empty' }, __( 'Nothing here yet', 'ekwa' ) )
		);
	}

	function Legend( props ) {
		return el( 'ul', { className: 'ekwa-pad-legend' }, props.items.map( function ( i ) {
			return el( 'li', { key: i.key }, el( 'span', { className: 'ekwa-pad-legend__key is-' + i.key, 'aria-hidden': 'true' }, i.icon || '' ), i.label );
		} ) );
	}

	// Horizontal bars, one series — value at the tip.
	function HBars( props ) {
		var rows = props.rows;
		var max  = Math.max.apply( null, [ 1 ].concat( rows.map( function ( r ) { return r.value; } ) ) );
		if ( ! rows.length ) {
			return el( 'p', { className: 'ekwa-pad-muted' }, props.empty );
		}
		return el( 'div', { className: 'ekwa-pad-hbars' }, rows.map( function ( r, i ) {
			var lines = [ r.label, sprintf( props.unit, r.value ) ].concat( r.extra || [] );
			return el( 'div', assign( { key: i, className: 'ekwa-pad-hbar' }, tipProps( lines, r.onPick ) ),
				el( 'div', { className: 'ekwa-pad-hbar__label' }, r.tag ? el( 'span', { className: 'ekwa-pad-tag' }, r.tag ) : null, r.label ),
				el( 'div', { className: 'ekwa-pad-hbar__track' },
					el( 'div', { className: 'ekwa-pad-hbar__bar', style: { width: Math.max( 1, ( r.value / max ) * 100 ) + '%' } } ),
					el( 'span', { className: 'ekwa-pad-hbar__val' }, String( r.value ) )
				)
			);
		} ) );
	}

	function DataTable( props ) {
		return el( 'details', { className: 'ekwa-pad-data' },
			el( 'summary', null, __( 'Show the numbers', 'ekwa' ) ),
			el( 'table', { className: 'widefat striped' },
				el( 'thead', null, el( 'tr', null, props.head.map( function ( h, i ) { return el( 'th', { key: i, scope: 'col' }, h ); } ) ) ),
				el( 'tbody', null, props.rows.map( function ( r, i ) {
					return el( 'tr', { key: i }, r.map( function ( c, j ) { return el( 0 === j ? 'th' : 'td', { key: j, scope: 0 === j ? 'row' : undefined }, c ); } ) );
				} ) )
			)
		);
	}

	function assign( target ) {
		for ( var i = 1; i < arguments.length; i++ ) {
			var src = arguments[ i ];
			if ( ! src ) { continue; }
			for ( var k in src ) {
				if ( Object.prototype.hasOwnProperty.call( src, k ) ) { target[ k ] = src[ k ]; }
			}
		}
		return target;
	}

	// ─── Overview (KPIs + charts) ───────────────────────────────────────────

	function Overview( props ) {
		var items  = props.items;
		var device = props.device;
		var pages  = items.filter( function ( i ) { return 'page' === i.type; } );
		var posts  = items.filter( function ( i ) { return 'post' === i.type; } );

		function counts( list ) {
			var c = { pass: 0, fail: 0, none: 0 };
			list.forEach( function ( i ) { c[ deviceStatus( i, device ) ]++; } );
			return c;
		}
		var cp = counts( pages );
		var cq = counts( posts );
		var tested = items.filter( function ( i ) { return !! i[ device ]; } );

		function rate( c ) {
			var t = c.pass + c.fail;
			return t ? Math.round( ( c.pass / t ) * 100 ) + '%' : '–';
		}

		// Why URLs fail: how many fail each criterion.
		var why = {};
		tested.forEach( function ( i ) {
			( i[ device ].keys || [] ).forEach( function ( k ) { why[ k ] = ( why[ k ] || 0 ) + 1; } );
		} );
		var whyRows = CRITERIA.filter( function ( c ) { return why[ c[ 0 ] ] && keyCounts( c[ 0 ] ); } ).map( function ( c ) {
			return { key: c[ 0 ], label: c[ 1 ], value: why[ c[ 0 ] ], onPick: function () { props.onFilter( { status: 'fail', criterion: c[ 0 ] } ); } };
		} ).sort( function ( a, b ) { return b.value - a.value; } );

		// Most common failing accessibility / SEO checks.
		var checks = {};
		tested.forEach( function ( i ) {
			var f = i[ device ].failing || {};
			[ [ 'accessibility', __( 'A11y', 'ekwa' ) ], [ 'seo', __( 'SEO', 'ekwa' ) ] ].forEach( function ( cat ) {
				if ( ! keyCounts( cat[ 0 ] ) ) { return; }
				( f[ cat[ 0 ] ] || [] ).forEach( function ( a ) {
					var k = cat[ 0 ] + ':' + ( a.id || a.title );
					if ( ! checks[ k ] ) { checks[ k ] = { label: a.title, tag: cat[ 1 ], value: 0, id: a.id }; }
					checks[ k ].value++;
				} );
			} );
		} );
		var checkRows = Object.keys( checks ).map( function ( k ) { return checks[ k ]; } )
			.sort( function ( a, b ) { return b.value - a.value; } ).slice( 0, 8 )
			.map( function ( r ) {
				r.onPick = function () { props.onFilter( { status: 'fail', audit: r.id } ); };
				return r;
			} );

		// Score bands per category.
		function bands( cat ) {
			var b = { poor: 0, average: 0, good: 0 };
			tested.forEach( function ( i ) {
				var s = i[ device ].scores ? i[ device ].scores[ cat ] : null;
				if ( null != s ) { b[ band( s ) ]++; }
			} );
			return b;
		}

		var avgOf = function ( cat ) { return avg( tested.map( function ( i ) { return i[ device ].scores ? i[ device ].scores[ cat ] : null; } ) ); };

		return el( 'div', { className: 'ekwa-pad-overview' },
			el( 'div', { className: 'ekwa-pad-tiles' },
				el( Tile, { label: __( 'Pages passing', 'ekwa' ), value: rate( cp ), sub: sprintf( __( '%1$d of %2$d tested · %3$d not tested', 'ekwa' ), cp.pass, cp.pass + cp.fail, cp.none ) } ),
				el( Tile, { label: __( 'Posts passing', 'ekwa' ), value: rate( cq ), sub: sprintf( __( '%1$d of %2$d tested · %3$d not tested', 'ekwa' ), cq.pass, cq.pass + cq.fail, cq.none ) } ),
				SCORE_CATS.map( function ( c ) {
					var a = avgOf( c[ 0 ] );
					return el( Tile, { key: c[ 0 ], label: sprintf( __( 'Average %s', 'ekwa' ), c[ 1 ] ), value: null == a ? '–' : String( a ), sub: sprintf( __( 'over %d tested URLs', 'ekwa' ), tested.length ) } );
				} )
			),
			el( 'div', { className: 'ekwa-pad-charts' },
				el( 'section', { className: 'ekwa-pad-card' },
					el( 'h2', null, __( 'Pass / fail', 'ekwa' ) ),
					el( 'p', { className: 'ekwa-pad-muted' }, __( 'Click a part to list those URLs.', 'ekwa' ) ),
					el( SegmentedBar, { name: __( 'Pages', 'ekwa' ), parts: statusParts( cp ), onPick: function ( k ) { props.onFilter( { tab: 'page', status: k } ); } } ),
					el( SegmentedBar, { name: __( 'Posts', 'ekwa' ), parts: statusParts( cq ), onPick: function ( k ) { props.onFilter( { tab: 'post', status: k } ); } } ),
					el( Legend, { items: [
						{ key: 'pass', label: __( 'Pass', 'ekwa' ), icon: '✓' },
						{ key: 'fail', label: __( 'Fail', 'ekwa' ), icon: '✕' },
						{ key: 'none', label: __( 'Not tested', 'ekwa' ) },
					] } ),
					el( DataTable, { head: [ '', __( 'Pass', 'ekwa' ), __( 'Fail', 'ekwa' ), __( 'Not tested', 'ekwa' ) ], rows: [
						[ __( 'Pages', 'ekwa' ), cp.pass, cp.fail, cp.none ],
						[ __( 'Posts', 'ekwa' ), cq.pass, cq.fail, cq.none ],
					] } )
				),
				el( 'section', { className: 'ekwa-pad-card' },
					el( 'h2', null, __( 'Score bands', 'ekwa' ) ),
					el( 'p', { className: 'ekwa-pad-muted' }, __( 'Tested URLs by Lighthouse band: 0–49 poor, 50–89 needs work, 90–100 good.', 'ekwa' ) ),
					SCORE_CATS.map( function ( c ) {
						var b = bands( c[ 0 ] );
						return el( SegmentedBar, { key: c[ 0 ], name: c[ 1 ], parts: [
							{ key: 'poor', label: __( 'Poor (0–49)', 'ekwa' ), value: b.poor, icon: '▲' },
							{ key: 'average', label: __( 'Needs work (50–89)', 'ekwa' ), value: b.average, icon: '■' },
							{ key: 'good', label: __( 'Good (90–100)', 'ekwa' ), value: b.good, icon: '●' },
						] } );
					} ),
					el( Legend, { items: [
						{ key: 'poor', label: __( 'Poor', 'ekwa' ), icon: '▲' },
						{ key: 'average', label: __( 'Needs work', 'ekwa' ), icon: '■' },
						{ key: 'good', label: __( 'Good', 'ekwa' ), icon: '●' },
					] } ),
					el( DataTable, { head: [ '', __( 'Poor', 'ekwa' ), __( 'Needs work', 'ekwa' ), __( 'Good', 'ekwa' ) ], rows: SCORE_CATS.map( function ( c ) {
						var b = bands( c[ 0 ] );
						return [ c[ 1 ], b.poor, b.average, b.good ];
					} ) } )
				),
				el( 'section', { className: 'ekwa-pad-card' },
					el( 'h2', null, __( 'Why URLs fail', 'ekwa' ) ),
					el( 'p', { className: 'ekwa-pad-muted' }, __( 'How many tested URLs miss each criterion. Click one to list them.', 'ekwa' ) ),
					el( HBars, { rows: whyRows, unit: __( '%d URLs miss this', 'ekwa' ), empty: __( 'No failures among the tested URLs.', 'ekwa' ) } ),
					whyRows.length ? el( DataTable, { head: [ __( 'Criterion', 'ekwa' ), __( 'URLs failing', 'ekwa' ) ], rows: whyRows.map( function ( r ) { return [ r.label, r.value ]; } ) } ) : null
				),
				el( 'section', { className: 'ekwa-pad-card' },
					el( 'h2', null, __( 'Most common failing checks', 'ekwa' ) ),
					el( 'p', { className: 'ekwa-pad-muted' }, __( 'Accessibility and SEO checks failing on the most URLs — usually one site-wide fix (stylesheet, header or footer) clears many at once.', 'ekwa' ) ),
					el( HBars, { rows: checkRows, unit: __( 'fails on %d URLs', 'ekwa' ), empty: __( 'No failing accessibility or SEO checks among the tested URLs.', 'ekwa' ) } ),
					checkRows.length ? el( DataTable, { head: [ __( 'Check', 'ekwa' ), __( 'URLs', 'ekwa' ) ], rows: checkRows.map( function ( r ) { return [ r.tag + ' · ' + r.label, r.value ]; } ) } ) : null
				)
			)
		);
	}

	function statusParts( c ) {
		return [
			{ key: 'pass', label: __( 'Pass', 'ekwa' ), value: c.pass, icon: '✓' },
			{ key: 'fail', label: __( 'Fail', 'ekwa' ), value: c.fail, icon: '✕' },
			{ key: 'none', label: __( 'Not tested', 'ekwa' ), value: c.none, icon: '–' },
		];
	}

	// ─── Table ──────────────────────────────────────────────────────────────

	function DeviceCell( props ) {
		var s       = props.summary;
		var running = props.running;
		var error   = props.error;
		if ( running ) {
			return el( 'div', { className: 'ekwa-pad-cell' }, el( Spinner, null ), el( 'span', { className: 'ekwa-pad-muted' }, __( 'Testing…', 'ekwa' ) ) );
		}
		if ( ! s ) {
			return el( 'div', { className: 'ekwa-pad-cell' }, el( Status, { status: error ? 'error' : 'none' } ), error ? el( 'div', { className: 'ekwa-pad-err' }, error ) : null );
		}
		var sc = s.scores || {};
		return el( 'div', { className: 'ekwa-pad-cell' },
			el( 'div', { className: 'ekwa-pad-cell__top' },
				el( Status, { status: summaryStatus( s ) } ),
				el( 'span', { className: 'ekwa-pad-muted' }, ago( s.t ) )
			),
			el( 'div', { className: 'ekwa-pad-scores' },
				el( Score, { short: 'P', label: __( 'Performance', 'ekwa' ), value: sc.performance } ),
				el( Score, { short: 'A', label: __( 'Accessibility', 'ekwa' ), value: sc.accessibility } ),
				el( Score, { short: 'SEO', label: __( 'SEO', 'ekwa' ), value: sc.seo } ),
				el( Score, { short: 'BP', label: __( 'Best Practices', 'ekwa' ), value: sc[ 'best-practices' ] } )
			),
			error ? el( 'div', { className: 'ekwa-pad-err' }, error ) : null
		);
	}

	function Details( props ) {
		var item = props.item;
		return el( 'div', { className: 'ekwa-pad-details' }, DEVICES.map( function ( d ) {
			var s = item[ d[ 0 ] ];
			if ( ! s ) {
				return el( 'div', { key: d[ 0 ], className: 'ekwa-pad-details__col' }, el( 'h4', null, d[ 1 ] ), el( 'p', { className: 'ekwa-pad-muted' }, __( 'Not tested yet.', 'ekwa' ) ) );
			}
			var m = s.metrics || {};
			var f = s.failing || {};
			var keys    = s.keys || [];
			var counted = ( s.issues || [] ).filter( function ( t, i ) { return keyCounts( keys[ i ] ); } );
			var ignored = ( s.issues || [] ).filter( function ( t, i ) { return ! keyCounts( keys[ i ] ); } );
			return el( 'div', { key: d[ 0 ], className: 'ekwa-pad-details__col' },
				el( 'h4', null, d[ 1 ], ' ', el( Status, { status: deviceStatus( item, d[ 0 ] ) } ) ),
				el( 'p', { className: 'ekwa-pad-metrics' },
					'FCP ' + ( null == m.fcp ? '–' : m.fcp + ' s' ) + ' · LCP ' + ( null == m.lcp ? '–' : m.lcp + ' s' ) +
					' · CLS ' + ( null == m.cls ? '–' : m.cls ) + ' · TBT ' + ( null == m.tbt ? '–' : Math.round( m.tbt * 1000 ) + ' ms' ) ),
				counted.length ? el( 'ul', { className: 'ekwa-pad-issues' }, counted.map( function ( t, i ) { return el( 'li', { key: i }, t ); } ) ) : el( 'p', { className: 'ekwa-pad-muted' }, judgeAll() ? __( 'Meets every criterion.', 'ekwa' ) : __( 'Meets every criterion it is judged on.', 'ekwa' ) ),
				ignored.length ? el( 'p', { className: 'ekwa-pad-muted' }, __( 'Not counted here: ', 'ekwa' ) + ignored.join( ' · ' ) ) : null,
				( f.accessibility || [] ).length ? el( Fragment, null, el( 'h5', null, __( 'Failing accessibility checks', 'ekwa' ) ), el( 'ul', null, f.accessibility.map( function ( a, i ) { return el( 'li', { key: i }, a.title ); } ) ) ) : null,
				( f.seo || [] ).length ? el( Fragment, null, el( 'h5', null, __( 'Failing SEO checks', 'ekwa' ) ), el( 'ul', null, f.seo.map( function ( a, i ) { return el( 'li', { key: i }, a.title ); } ) ) ) : null,
				( f.performance || [] ).length ? el( Fragment, null, el( 'h5', null, __( 'Top performance opportunities', 'ekwa' ) ), el( 'ul', null, f.performance.map( function ( a, i ) { return el( 'li', { key: i }, a.title ); } ) ) ) : null,
				el( 'p', null, el( 'a', { href: psiLink( item.url, d[ 0 ] ), target: '_blank', rel: 'noopener noreferrer' }, __( 'Open in PageSpeed Insights ↗', 'ekwa' ) ) )
			);
		} ) );
	}

	function Row( props ) {
		var item   = props.item;
		var open   = props.open;
		var status = overall( item );
		var detail = 'ekwa-pad-row-' + item.id;
		var left   = isExcluded( item );
		var kinds  = props.kinds || {};
		return el( Fragment, null,
			el( 'tr', { className: 'ekwa-pad-row is-' + status + ( open ? ' is-open' : '' ) + ( left ? ' is-excluded' : '' ) },
				el( 'td', { className: 'ekwa-pad-col-title' },
					el( 'button', { type: 'button', className: 'ekwa-pad-expand', 'aria-expanded': open, 'aria-controls': detail, onClick: props.onToggle },
						el( 'span', { 'aria-hidden': 'true' }, open ? '▾' : '▸' ),
						el( 'span', { className: 'screen-reader-text' }, __( 'Details', 'ekwa' ) )
					),
					el( 'div', { className: 'ekwa-pad-title' },
						el( 'a', { href: item.url, target: '_blank', rel: 'noopener noreferrer', className: 'ekwa-pad-title__link' }, item.title ),
						item.front ? el( 'span', { className: 'ekwa-pad-tag' }, __( 'Front page', 'ekwa' ) ) : null,
						item.blog && 'blog' !== item.kind ? el( 'span', { className: 'ekwa-pad-tag' }, __( 'Blog', 'ekwa' ) ) : null,
						item.kind ? el( 'span', { className: 'ekwa-pad-tag' }, kinds[ item.kind ] || item.kind ) : null,
						item.soon ? el( 'span', { className: 'ekwa-pad-tag' }, __( 'Coming soon', 'ekwa' ) ) : ( item.empty && 'blog' !== item.kind ? el( 'span', { className: 'ekwa-pad-tag' }, __( 'No content', 'ekwa' ) ) : null ),
						left ? el( 'span', { className: 'ekwa-pad-tag ekwa-pad-tag--left' }, __( 'Left out', 'ekwa' ) ) : null,
						el( 'div', { className: 'ekwa-pad-row-links' },
							item.edit ? el( 'a', { href: item.edit }, __( 'Open audit', 'ekwa' ) ) : null,
							el( 'span', { className: 'ekwa-pad-url' }, item.url.replace( /^https?:\/\//, '' ) )
						)
					)
				),
				DEVICES.map( function ( d ) {
					return el( 'td', { key: d[ 0 ], className: 'ekwa-pad-col-dev' }, el( DeviceCell, {
						summary: item[ d[ 0 ] ],
						running: props.running[ item.id + ':' + d[ 0 ] ],
						error: props.errors[ item.id + ':' + d[ 0 ] ],
					} ) );
				} ),
				el( 'td', { className: 'ekwa-pad-col-act' },
					el( Button, {
						variant: 'secondary', size: 'small',
						disabled: !! ( props.running[ item.id + ':mobile' ] || props.running[ item.id + ':desktop' ] ),
						onClick: props.onTest,
					}, 'none' === status ? __( 'Test', 'ekwa' ) : __( 'Retest', 'ekwa' ) ),
					props.onExclude ? el( 'div', null, el( Button, {
						variant: 'link', className: 'ekwa-pad-exclude', disabled: !! props.excluding, onClick: props.onExclude,
						title: left ? __( 'Count this page in the dashboard again', 'ekwa' ) : __( 'Leave this page out of the dashboard (charts, totals and “test all”)', 'ekwa' ),
					}, left ? __( 'Include', 'ekwa' ) : __( 'Leave out', 'ekwa' ) ) ) : null
				)
			),
			open ? el( 'tr', { className: 'ekwa-pad-detail-row', id: detail }, el( 'td', { colSpan: 4 }, el( Details, { item: item } ) ) ) : null
		);
	}

	// ─── App ────────────────────────────────────────────────────────────────

	function App() {
		var _items   = useState( null );
		var items    = _items[ 0 ], setItems = _items[ 1 ];
		var _error   = useState( '' );
		var _tab     = useState( store( 'tab' ) || 'page' );
		var tab      = _tab[ 0 ];
		var _device  = useState( store( 'device' ) || 'mobile' );
		var device   = _device[ 0 ];
		var _filter  = useState( { status: 'all', criterion: '', audit: '' } );
		var filter   = _filter[ 0 ];
		var _search  = useState( '' );
		var _open    = useState( {} );
		var _running = useState( {} );
		var _errors  = useState( {} );
		var _prog    = useState( null );
		var _notice  = useState( null );
		var _scope   = useState( 'untested' );
		var _devs    = useState( 'both' );
		var _conc    = useState( parseInt( store( 'conc' ), 10 ) || 2 );
		var _confirm = useState( null );
		var _judge   = useState( JUDGE );
		var _showEx  = useState( '1' === store( 'showExcluded' ) );
		var _kinds   = useState( {} );
		var _excl    = useState( {} );
		var thresholds = useRef( CFG.thresholds || {} );
		JUDGE = _judge[ 0 ];

		var queue  = useRef( [] );
		var active = useRef( 0 );
		var halted = useRef( false );
		var conc   = useRef( _conc[ 0 ] );
		conc.current = _conc[ 0 ];

		useEffect( function () {
			apiFetch( { path: '/ekwa/v1/page-audit/overview' } )
				.then( function ( res ) {
					thresholds.current = res.thresholds || thresholds.current;
					_kinds[ 1 ]( res.kinds || {} );
					setItems( res.items || [] );
				} )
				.catch( function ( err ) { _error[ 1 ]( errText( err ) ); } );
		}, [] );

		// Leaving while tests run stops them — say so.
		useEffect( function () {
			var running = _prog[ 0 ] && ! _prog[ 0 ].finished;
			if ( ! running ) { return undefined; }
			var fn = function ( e ) { e.preventDefault(); e.returnValue = ''; return ''; };
			window.addEventListener( 'beforeunload', fn );
			return function () { window.removeEventListener( 'beforeunload', fn ); };
		}, [ _prog[ 0 ] && _prog[ 0 ].finished ] );

		function patchItem( id, dev, summary ) {
			setItems( function ( list ) {
				return ( list || [] ).map( function ( i ) {
					if ( i.id !== id ) { return i; }
					var n = assign( {}, i );
					n[ dev ] = summary;
					return n;
				} );
			} );
		}
		function setJudge( list ) {
			list = JUDGE_ALL.filter( function ( k ) { return -1 !== list.indexOf( k ); } );
			if ( ! list.length ) { return; } // At least one category.
			JUDGE = list;
			store( 'judge', list.join( ',' ) );
			_judge[ 1 ]( list );
		}

		function toggleExclude( item ) {
			var mode = isExcluded( item ) ? 'include' : 'exclude';
			setFlag( _excl[ 1 ], item.id, true );
			apiFetch( { path: '/ekwa/v1/page-audit/exclude', method: 'POST', data: { post_id: item.id, mode: mode } } )
				.then( function ( res ) {
					setItems( function ( list ) {
						return ( list || [] ).map( function ( i ) { return i.id === item.id ? assign( {}, i, { exclude: res.exclude || '', kind: res.kind || '' } ) : i; } );
					} );
				} )
				.catch( function ( err ) { _notice[ 1 ]( { type: 'error', text: errText( err ) } ); } )
				.then( function () { setFlag( _excl[ 1 ], item.id, undefined ); } );
		}

		function setFlag( setter, key, value ) {
			setter( function ( m ) {
				var n = assign( {}, m );
				if ( undefined === value ) { delete n[ key ]; } else { n[ key ] = value; }
				return n;
			} );
		}

		function pump() {
			while ( ! halted.current && active.current < conc.current && queue.current.length ) {
				( function ( task ) {
					var key = task.id + ':' + task.device;
					active.current++;
					setFlag( _running[ 1 ], key, true );
					setFlag( _errors[ 1 ], key, undefined );
					apiFetch( { path: '/ekwa/v1/page-audit/run', method: 'POST', data: { post_id: task.id, strategy: task.device } } )
						.then( function ( res ) {
							if ( res && res.summary ) { patchItem( task.id, task.device, res.summary ); }
							_prog[ 1 ]( function ( p ) { return p ? assign( {}, p, { done: p.done + 1 } ) : p; } );
						} )
						.catch( function ( err ) {
							setFlag( _errors[ 1 ], key, errText( err ) );
							_prog[ 1 ]( function ( p ) { return p ? assign( {}, p, { done: p.done + 1, failed: p.failed + 1 } ) : p; } );
							if ( err && ( 'ekwa_page_audit_quota' === err.code || 'ekwa_page_audit_key' === err.code ) ) {
								halt( errText( err ) );
							}
						} )
						.then( function () {
							active.current--;
							setFlag( _running[ 1 ], key, undefined );
							if ( ! queue.current.length && 0 === active.current ) {
								_prog[ 1 ]( function ( p ) { return p ? assign( {}, p, { finished: true } ) : p; } );
							}
							pump();
						} );
				}( queue.current.shift() ) );
			}
		}

		function start( tasks ) {
			if ( ! tasks.length ) {
				_notice[ 1 ]( { type: 'info', text: __( 'Nothing to test for that selection.', 'ekwa' ) } );
				return;
			}
			halted.current = false;
			queue.current  = queue.current.concat( tasks );
			_notice[ 1 ]( null );
			_prog[ 1 ]( function ( p ) {
				var base = p && ! p.finished ? p : { total: 0, done: 0, failed: 0 };
				return assign( {}, base, { total: base.total + tasks.length, finished: false } );
			} );
			pump();
		}

		function halt( reason ) {
			halted.current = true;
			queue.current  = [];
			if ( reason ) { _notice[ 1 ]( { type: 'error', text: reason } ); }
			_prog[ 1 ]( function ( p ) { return p ? assign( {}, p, { stopped: true, finished: 0 === active.current } ) : p; } );
		}

		var everything = items || [];
		var all        = everything.filter( function ( i ) { return ! isExcluded( i ); } );
		var leftOut    = everything.length - all.length;
		var listed     = _showEx[ 0 ] ? everything : all;

		function devicesFor( choice ) {
			return 'both' === choice ? [ 'mobile', 'desktop' ] : [ choice ];
		}

		function tasksFor( scope, devChoice, visible ) {
			var devs  = devicesFor( devChoice );
			var tasks = [];
			var pool  = {
				visible:    visible,
				tab:        all.filter( function ( i ) { return i.type === tab; } ),
				everything: all,
				failing:    all,
				untested:   all,
			}[ scope ] || [];
			pool.forEach( function ( i ) {
				devs.forEach( function ( d ) {
					if ( 'failing' === scope && 'fail' !== deviceStatus( i, d ) ) { return; }
					if ( 'untested' === scope && i[ d ] ) { return; }
					tasks.push( { id: i.id, device: d } );
				} );
			} );
			return tasks;
		}

		// Filtering for the table.
		var q = _search[ 0 ].trim().toLowerCase();
		var visible = listed.filter( function ( i ) {
			if ( i.type !== tab ) { return false; }
			if ( q && -1 === ( i.title + ' ' + i.url ).toLowerCase().indexOf( q ) ) { return false; }
			var st = overall( i );
			if ( 'all' !== filter.status && st !== filter.status ) { return false; }
			if ( filter.criterion || filter.audit ) {
				var s = i[ device ];
				if ( ! s ) { return false; }
				if ( filter.criterion && -1 === ( s.keys || [] ).indexOf( filter.criterion ) ) { return false; }
				if ( filter.audit ) {
					var f = s.failing || {};
					var hit = [ 'accessibility', 'seo' ].some( function ( c ) { return ( f[ c ] || [] ).some( function ( a ) { return a.id === filter.audit; } ); } );
					if ( ! hit ) { return false; }
				}
			}
			return true;
		} );

		var countBy = function ( type ) { return listed.filter( function ( i ) { return i.type === type; } ).length; };

		// SEO fails everywhere on a site hidden from search engines (the test
		// server): Lighthouse's "Page is blocked from indexing". Offer to judge
		// without SEO.
		var testedN = 0;
		var blocked = 0;
		all.forEach( function ( i ) {
			var tested = false;
			var hit    = false;
			DEVICES.forEach( function ( d ) {
				var s = i[ d[ 0 ] ];
				if ( ! s ) { return; }
				tested = true;
				if ( ( ( s.failing || {} ).seo || [] ).some( function ( a ) { return 'is-crawlable' === a.id; } ) ) { hit = true; }
			} );
			if ( tested ) { testedN++; }
			if ( hit ) { blocked++; }
		} );
		var seoHint = -1 !== JUDGE.indexOf( 'seo' ) && JUDGE.length > 1 && ( blocked >= Math.max( 2, Math.ceil( testedN / 2 ) ) || ( false === CFG.blogPublic && testedN > 0 ) );
		var prog    = _prog[ 0 ];

		function applyFilter( f ) {
			if ( f.tab ) { _tab[ 1 ]( f.tab ); store( 'tab', f.tab ); }
			_filter[ 1 ]( { status: f.status || 'all', criterion: f.criterion || '', audit: f.audit || '' } );
			var t = document.getElementById( 'ekwa-pad-table' );
			if ( t && t.scrollIntoView ) { t.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }
		}

		if ( _error[ 0 ] ) {
			return el( Notice, { status: 'error', isDismissible: false }, _error[ 0 ] );
		}
		if ( ! items ) {
			return el( 'p', null, el( Spinner, null ), ' ', __( 'Loading pages and posts…', 'ekwa' ) );
		}

		var scopeOptions = [
			[ 'untested', __( 'Not tested yet', 'ekwa' ) ],
			[ 'failing', __( 'Failing only', 'ekwa' ) ],
			[ 'visible', __( 'Rows shown below', 'ekwa' ) ],
			[ 'tab', 'page' === tab ? __( 'All pages', 'ekwa' ) : __( 'All posts', 'ekwa' ) ],
			[ 'everything', leftOut ? __( 'Every page and post (not the left-out ones)', 'ekwa' ) : __( 'Every page and post', 'ekwa' ) ],
		];
		var pending = tasksFor( _scope[ 0 ], _devs[ 0 ], visible );
		var minutes = Math.max( 1, Math.round( ( pending.length * 35 ) / 60 / Math.max( 1, _conc[ 0 ] ) ) );

		return el( 'div', null,
			el( Tooltip, null ),
			! CFG.psiKey ? el( Notice, { status: 'warning', isDismissible: false, className: 'ekwa-pad-notice' },
				__( 'No PageSpeed Insights API key is set, so Google allows only a few tests. ', 'ekwa' ),
				CFG.canManage ? el( 'a', { href: CFG.settingsUrl }, __( 'Add one in Ekwa Settings → Performance', 'ekwa' ) ) : null
			) : null,
			seoHint ? el( Notice, { status: 'info', isDismissible: false, className: 'ekwa-pad-notice' },
				blocked
					? sprintf( __( 'SEO fails on %d pages because they are blocked from indexing (“Page is blocked from indexing”) — expected while the site is hidden from search engines, e.g. on the test server.', 'ekwa' ), blocked )
					: __( 'This site asks search engines not to index it (Settings → Reading), so Lighthouse’s SEO score fails on every page — expected on the test server.', 'ekwa' ),
				' ',
				el( Button, { variant: 'link', onClick: function () { setJudge( JUDGE.filter( function ( k ) { return 'seo' !== k; } ) ); } }, __( 'Judge PASS/FAIL without SEO', 'ekwa' ) )
			) : null,
			el( 'div', { className: 'ekwa-pad-toolbar ekwa-pad-judge' },
				el( 'span', { className: 'ekwa-pad-toolbar__label', id: 'ekwa-pad-judge-label' }, __( 'Judge PASS/FAIL on', 'ekwa' ) ),
				el( 'div', { className: 'ekwa-pad-seg-control', role: 'group', 'aria-labelledby': 'ekwa-pad-judge-label' },
					JUDGE_GROUPS.map( function ( g ) {
						var on = -1 !== JUDGE.indexOf( g[ 0 ] );
						return el( 'button', {
							key: g[ 0 ], type: 'button', 'aria-pressed': on,
							disabled: on && 1 === JUDGE.length,
							title: on && 1 === JUDGE.length ? __( 'At least one category is needed.', 'ekwa' ) : undefined,
							onClick: function () { setJudge( on ? JUDGE.filter( function ( k ) { return k !== g[ 0 ]; } ) : JUDGE.concat( [ g[ 0 ] ] ) ); },
						}, ( on ? '✓ ' : '' ) + g[ 1 ] );
					} )
				),
				! judgeAll() ? el( Button, { variant: 'link', onClick: function () { setJudge( JUDGE_ALL ); } }, __( 'All three (sitemap tool)', 'ekwa' ) ) : null
			),
			el( 'p', { className: 'ekwa-pad-criteria' },
				el( 'strong', null, __( 'Pass criteria', 'ekwa' ) + ': ' ), criteriaText( thresholds.current ),
				el( 'span', { className: 'ekwa-pad-muted' }, ' — ' + ( judgeAll()
					? __( 'same rules as the team’s PageSpeed sitemap tool. Best Practices and TBT are recorded, not enforced.', 'ekwa' )
					: __( 'your choice, in this browser only (the page editor’s sidebar follows it). The stored results and the team’s sitemap tool still judge Performance, Accessibility and SEO together.', 'ekwa' ) ) )
			),

			el( 'div', { className: 'ekwa-pad-toolbar' },
				el( 'span', { className: 'ekwa-pad-toolbar__label' }, __( 'Charts for', 'ekwa' ) ),
				el( Seg, { label: __( 'Device', 'ekwa' ), value: device, options: DEVICES, onChange: function ( v ) { _device[ 1 ]( v ); store( 'device', v ); } } )
			),
			el( Overview, { items: all, device: device, onFilter: applyFilter } ),

			// ── Run tests ──
			el( 'section', { className: 'ekwa-pad-card ekwa-pad-run' },
				el( 'h2', null, __( 'Run tests', 'ekwa' ) ),
				el( 'div', { className: 'ekwa-pad-run__row' },
					el( 'label', null, __( 'Test', 'ekwa' ) + ' ',
						el( 'select', { value: _scope[ 0 ], onChange: function ( e ) { _scope[ 1 ]( e.target.value ); _confirm[ 1 ]( null ); } },
							scopeOptions.map( function ( o ) { return el( 'option', { key: o[ 0 ], value: o[ 0 ] }, o[ 1 ] ); } ) ) ),
					el( 'label', null, __( 'on', 'ekwa' ) + ' ',
						el( 'select', { value: _devs[ 0 ], onChange: function ( e ) { _devs[ 1 ]( e.target.value ); _confirm[ 1 ]( null ); } },
							el( 'option', { value: 'both' }, __( 'Mobile and desktop', 'ekwa' ) ),
							el( 'option', { value: 'mobile' }, __( 'Mobile', 'ekwa' ) ),
							el( 'option', { value: 'desktop' }, __( 'Desktop', 'ekwa' ) ) ) ),
					el( 'label', null, __( 'At once', 'ekwa' ) + ' ',
						el( 'select', { value: String( _conc[ 0 ] ), onChange: function ( e ) { var v = parseInt( e.target.value, 10 ); _conc[ 1 ]( v ); store( 'conc', v ); } },
							[ 1, 2, 3, 4 ].map( function ( n ) { return el( 'option', { key: n, value: String( n ) }, String( n ) ); } ) ) ),
					el( Button, {
						variant: 'primary',
						disabled: ! pending.length,
						onClick: function () {
							if ( pending.length > 6 ) { _confirm[ 1 ]( pending ); } else { start( pending ); }
						},
					}, sprintf( __( 'Run %d tests', 'ekwa' ), pending.length ) ),
					prog && ! prog.finished ? el( Button, { variant: 'secondary', isDestructive: true, onClick: function () { halt( '' ); } }, __( 'Stop', 'ekwa' ) ) : null
				),
				_confirm[ 0 ] ? el( 'div', { className: 'ekwa-pad-confirm', role: 'alert' },
					el( 'span', null, sprintf( __( '%1$d PageSpeed tests — about %2$d minutes. Keep this tab open; finished results are saved as they arrive.', 'ekwa' ), _confirm[ 0 ].length, minutes ) ),
					el( Button, { variant: 'primary', onClick: function () { var t = _confirm[ 0 ]; _confirm[ 1 ]( null ); start( t ); } }, __( 'Start', 'ekwa' ) ),
					el( Button, { variant: 'tertiary', onClick: function () { _confirm[ 1 ]( null ); } }, __( 'Cancel', 'ekwa' ) )
				) : null,
				prog ? el( 'div', { className: 'ekwa-pad-progress', 'aria-live': 'polite' },
					el( 'div', { className: 'ekwa-pad-progress__bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': prog.total, 'aria-valuenow': prog.done },
						el( 'div', { style: { width: ( prog.total ? ( prog.done / prog.total ) * 100 : 0 ) + '%' } } ) ),
					el( 'span', null, prog.finished
						? sprintf( __( 'Done: %1$d of %2$d tests (%3$d could not run).', 'ekwa' ), prog.done - prog.failed, prog.total, prog.failed )
						: sprintf( __( '%1$d of %2$d tests done — %3$d running…', 'ekwa' ), prog.done, prog.total, active.current ) )
				) : null,
				_notice[ 0 ] ? el( Notice, { status: _notice[ 0 ].type, isDismissible: true, onRemove: function () { _notice[ 1 ]( null ); }, className: 'ekwa-pad-notice' }, _notice[ 0 ].text ) : null
			),

			// ── Table ──
			el( 'section', { className: 'ekwa-pad-card ekwa-pad-list', id: 'ekwa-pad-table' },
				el( 'div', { className: 'ekwa-pad-tabs', role: 'tablist' },
					[ [ 'page', __( 'Pages', 'ekwa' ) ], [ 'post', __( 'Posts', 'ekwa' ) ] ].map( function ( t ) {
						return el( 'button', {
							key: t[ 0 ], type: 'button', role: 'tab', 'aria-selected': tab === t[ 0 ],
							className: 'ekwa-pad-tab' + ( tab === t[ 0 ] ? ' is-active' : '' ),
							onClick: function () { _tab[ 1 ]( t[ 0 ] ); store( 'tab', t[ 0 ] ); },
						}, t[ 1 ] + ' (' + countBy( t[ 0 ] ) + ')' );
					} )
				),
				el( 'div', { className: 'ekwa-pad-filters' },
					el( Seg, { label: __( 'Status', 'ekwa' ), value: filter.status, onChange: function ( v ) { _filter[ 1 ]( { status: v, criterion: '', audit: '' } ); }, options: [
						[ 'all', __( 'All', 'ekwa' ) ], [ 'fail', __( 'Failing', 'ekwa' ) ], [ 'pass', __( 'Passing', 'ekwa' ) ], [ 'none', __( 'Not tested', 'ekwa' ) ],
					] } ),
					el( 'input', { type: 'search', className: 'ekwa-pad-search', placeholder: __( 'Search title or URL…', 'ekwa' ), 'aria-label': __( 'Search title or URL', 'ekwa' ), value: _search[ 0 ], onChange: function ( e ) { _search[ 1 ]( e.target.value ); } } ),
					filter.criterion || filter.audit ? el( Button, { variant: 'tertiary', onClick: function () { _filter[ 1 ]( { status: filter.status, criterion: '', audit: '' } ); } },
						__( 'Clear chart filter ✕', 'ekwa' ) ) : null,
					el( 'span', { className: 'ekwa-pad-muted' }, sprintf( __( '%d shown', 'ekwa' ), visible.length ) ),
					leftOut ? el( Button, {
						variant: 'tertiary', className: 'ekwa-pad-leftout',
						onClick: function () { var v = ! _showEx[ 0 ]; _showEx[ 1 ]( v ); store( 'showExcluded', v ? '1' : '0' ); },
					}, _showEx[ 0 ]
						? sprintf( __( 'Hide the %d left-out pages', 'ekwa' ), leftOut )
						: sprintf( __( '%d left out (blog, thank-you and policy pages…) — show', 'ekwa' ), leftOut ) ) : null
				),
				el( 'table', { className: 'widefat ekwa-pad-table' },
					el( 'thead', null, el( 'tr', null,
						el( 'th', { scope: 'col' }, 'page' === tab ? __( 'Page', 'ekwa' ) : __( 'Post', 'ekwa' ) ),
						el( 'th', { scope: 'col' }, __( 'Mobile', 'ekwa' ) ),
						el( 'th', { scope: 'col' }, __( 'Desktop', 'ekwa' ) ),
						el( 'th', { scope: 'col' }, el( 'span', { className: 'screen-reader-text' }, __( 'Actions', 'ekwa' ) ) )
					) ),
					el( 'tbody', null, visible.length ? visible.map( function ( item ) {
						return el( Row, {
							key: item.id,
							item: item,
							open: !! _open[ 0 ][ item.id ],
							running: _running[ 0 ],
							errors: _errors[ 0 ],
							kinds: _kinds[ 0 ],
							excluding: !! _excl[ 0 ][ item.id ],
							onToggle: function () { setFlag( _open[ 1 ], item.id, _open[ 0 ][ item.id ] ? undefined : true ); },
							onTest: function () { start( [ { id: item.id, device: 'mobile' }, { id: item.id, device: 'desktop' } ] ); },
							onExclude: function () { toggleExclude( item ); },
						} );
					} ) : el( 'tr', null, el( 'td', { colSpan: 4, className: 'ekwa-pad-muted' }, __( 'Nothing matches.', 'ekwa' ) ) ) )
				)
			)
		);
	}

	var render = wp.element.createRoot
		? function ( node, target ) { wp.element.createRoot( target ).render( node ); }
		: function ( node, target ) { wp.element.render( node, target ); };
	render( el( App, null ), root );
}( window.wp ) );
