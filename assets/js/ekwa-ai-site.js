/**
 * AI Site Generator — settings-page driver.
 *
 * Orchestrates the three REST endpoints sequentially so no single request
 * outlives one Gemini call:
 *   1. /ai-site-plan  → normalized step list
 *   2. /ai-site-part  → one call per step (header, footer, each section)
 *   3. /ai-site-apply → persist accepted parts (header/footer template parts,
 *      home front page, inner page)
 *
 * Localized config: window.ekwaAiSite { planUrl, partUrl, applyUrl,
 * manifestRebuildUrl, nonce, hasKey, siteCss, manifest:{images,videos}, i18n }.
 */
( function () {
	'use strict';

	var cfg = window.ekwaAiSite;
	var root = document.getElementById( 'ekwa-ai-site-generator' );
	if ( ! cfg || ! root ) {
		return;
	}

	var $ = function ( id ) { return document.getElementById( id ); };

	var els = {
		desc: $( 'ekwa-ai-site-desc' ),
		innerTopic: $( 'ekwa-ai-site-inner-topic' ),
		model: $( 'ekwa-ai-site-model' ),
		generate: $( 'ekwa-ai-site-generate' ),
		status: $( 'ekwa-ai-site-status' ),
		progress: $( 'ekwa-ai-site-progress' ),
		results: $( 'ekwa-ai-site-results' ),
		manifestStatus: $( 'ekwa-ai-site-manifest-status' ),
		manifestRebuild: $( 'ekwa-ai-site-manifest-rebuild' ),
		addImages: $( 'ekwa-ai-site-add-images' ),
		imagesInput: $( 'ekwa-ai-site-images-input' ),
		imagesWrap: $( 'ekwa-ai-site-images' )
	};

	// state.steps[i] = step from the plan + { status, result }
	var state = { summary: '', steps: [], innerPage: {}, images: [], running: false };

	// Limits matching the server side (and the editor's Build with AI modal).
	var MAX_IMAGES = 6;
	var MAX_IMAGE_BYTES = 4 * 1024 * 1024;

	function post( url, body ) {
		return fetch( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( body || {} )
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				if ( ! r.ok ) {
					throw new Error( ( data && data.message ) || ( 'HTTP ' + r.status ) );
				}
				return data;
			} );
		} );
	}

	function setStatus( text, isError ) {
		els.status.textContent = text || '';
		els.status.style.color = isError ? '#d63638' : '#646970';
	}

	function checkedParts() {
		var parts = [];
		root.querySelectorAll( '.ekwa-ai-site-part-cb:checked' ).forEach( function ( cb ) {
			parts.push( cb.value );
		} );
		return parts;
	}

	// ─── Reference screenshots ───────────────────────────────────────────

	function imagesPayload() {
		return state.images.map( function ( im ) {
			return { mime: im.mime, data_base64: im.data_base64 };
		} );
	}

	function renderThumbs() {
		if ( ! els.imagesWrap ) {
			return;
		}
		els.imagesWrap.innerHTML = '';
		state.images.forEach( function ( im, i ) {
			var cell = document.createElement( 'div' );
			cell.style.cssText = 'position:relative;width:96px;height:64px;border:1px solid #c3c4c7;border-radius:3px;overflow:hidden;background:#f0f0f1;';

			var img = document.createElement( 'img' );
			img.src = im.previewUrl;
			img.alt = im.name;
			img.title = im.name;
			img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
			cell.appendChild( img );

			var x = document.createElement( 'button' );
			x.type = 'button';
			x.textContent = '×';
			x.title = cfg.i18n.removeImage;
			x.style.cssText = 'position:absolute;top:2px;right:2px;width:20px;height:20px;line-height:16px;padding:0;border:0;border-radius:50%;background:rgba(0,0,0,.65);color:#fff;cursor:pointer;';
			x.addEventListener( 'click', function () {
				URL.revokeObjectURL( im.previewUrl );
				state.images.splice( i, 1 );
				renderThumbs();
			} );
			cell.appendChild( x );

			els.imagesWrap.appendChild( cell );
		} );
	}

	function readFileAsBase64( file ) {
		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload = function ( e ) {
				var result = e.target.result || '';
				var comma = result.indexOf( ',' );
				resolve( comma >= 0 ? result.slice( comma + 1 ) : result );
			};
			reader.onerror = function () { reject( new Error( 'Could not read file: ' + file.name ) ); };
			reader.readAsDataURL( file );
		} );
	}

	function addImageFiles( fileList ) {
		if ( ! fileList || ! fileList.length ) {
			return;
		}
		var room = MAX_IMAGES - state.images.length;
		if ( room <= 0 ) {
			setStatus( cfg.i18n.maxImages, true );
			return;
		}
		var picked = Array.prototype.slice.call( fileList, 0, room );

		Promise.all( picked.map( function ( file ) {
			if ( ! file || ! file.type || 0 !== file.type.indexOf( 'image/' ) ) {
				setStatus( cfg.i18n.notImage.replace( '%s', file && file.name ? file.name : '?' ), true );
				return null;
			}
			if ( file.size > MAX_IMAGE_BYTES ) {
				setStatus( cfg.i18n.imageTooLarge.replace( '%s', file.name ), true );
				return null;
			}
			return readFileAsBase64( file ).then( function ( b64 ) {
				return {
					name: file.name || ( 'pasted-' + Date.now() + '.png' ),
					mime: file.type,
					data_base64: b64,
					previewUrl: URL.createObjectURL( file )
				};
			} );
		} ) ).then( function ( results ) {
			var added = results.filter( Boolean );
			if ( added.length ) {
				state.images = state.images.concat( added );
				setStatus( '' );
				renderThumbs();
			}
		} );
	}

	if ( els.addImages && els.imagesInput ) {
		els.addImages.addEventListener( 'click', function () {
			els.imagesInput.click();
		} );
		els.imagesInput.addEventListener( 'change', function () {
			addImageFiles( els.imagesInput.files );
			els.imagesInput.value = '';
		} );
	}

	// Pasting a screenshot into the description box attaches it.
	if ( els.desc ) {
		els.desc.addEventListener( 'paste', function ( e ) {
			var items = e.clipboardData && e.clipboardData.items;
			if ( ! items ) {
				return;
			}
			var files = [];
			for ( var i = 0; i < items.length; i++ ) {
				if ( 'file' === items[ i ].kind && 0 === items[ i ].type.indexOf( 'image/' ) ) {
					var f = items[ i ].getAsFile();
					if ( f ) { files.push( f ); }
				}
			}
			if ( files.length ) {
				e.preventDefault();
				addImageFiles( files );
			}
		} );
	}

	// ─── Manifest rebuild ────────────────────────────────────────────────

	if ( els.manifestRebuild ) {
		els.manifestRebuild.addEventListener( 'click', function () {
			els.manifestRebuild.disabled = true;
			els.manifestStatus.textContent = cfg.i18n.working;
			post( cfg.manifestRebuildUrl ).then( function ( res ) {
				els.manifestRebuild.disabled = false;
				els.manifestStatus.textContent = cfg.i18n.manifestCounts
					.replace( '%1$s', res.images ).replace( '%2$s', res.videos );
			} ).catch( function ( err ) {
				els.manifestRebuild.disabled = false;
				els.manifestStatus.textContent = err.message;
			} );
		} );
	}

	// ─── Progress list ───────────────────────────────────────────────────

	function renderProgress() {
		els.progress.hidden = false;
		els.progress.innerHTML = '';

		var list = document.createElement( 'ol' );
		list.className = 'ekwa-ai-site-steps';
		state.steps.forEach( function ( step, i ) {
			var li = document.createElement( 'li' );
			li.dataset.step = i;

			var icon = { pending: '○', running: '⏳', done: '✓', error: '✗' }[ step.status ] || '○';
			var label = document.createElement( 'span' );
			label.textContent = icon + ' ' + step.label;
			if ( 'error' === step.status ) { label.style.color = '#d63638'; }
			if ( 'done' === step.status ) { label.style.color = '#008a20'; }
			li.appendChild( label );

			if ( 'error' === step.status ) {
				var msg = document.createElement( 'em' );
				msg.textContent = ' — ' + ( step.error || cfg.i18n.error );
				msg.style.color = '#d63638';
				li.appendChild( msg );

				var retry = document.createElement( 'button' );
				retry.type = 'button';
				retry.className = 'button button-small';
				retry.style.marginLeft = '8px';
				retry.textContent = cfg.i18n.retry;
				retry.addEventListener( 'click', function () {
					runStep( i ).then( renderResults );
				} );
				li.appendChild( retry );
			}
			list.appendChild( li );
		} );
		els.progress.appendChild( list );
	}

	// ─── Step execution ──────────────────────────────────────────────────

	function runStep( i ) {
		var step = state.steps[ i ];
		step.status = 'running';
		step.error = '';
		renderProgress();

		return post( cfg.partUrl, {
			context: step.context,
			brief: step.brief,
			site_summary: state.summary,
			media_ids: step.media_ids || [],
			images: imagesPayload(),
			model: els.model ? els.model.value : 'gemini-2.5-flash'
		} ).then( function ( res ) {
			step.result = res;
			step.status = 'done';
			renderProgress();
		} ).catch( function ( err ) {
			step.status = 'error';
			step.error = err.message;
			renderProgress();
		} );
	}

	function runAllSteps( i ) {
		if ( i >= state.steps.length ) {
			return Promise.resolve();
		}
		setStatus( cfg.i18n.generatingStep
			.replace( '%1$s', i + 1 )
			.replace( '%2$s', state.steps.length )
			.replace( '%3$s', state.steps[ i ].label ) );
		return runStep( i ).then( function () {
			return runAllSteps( i + 1 );
		} );
	}

	// ─── Results / previews / apply ──────────────────────────────────────

	function groupMarkup( group ) {
		var chunks = [];
		state.steps.forEach( function ( step ) {
			if ( step.group === group && step.result && step.result.block_markup ) {
				chunks.push( step.result.block_markup );
			}
		} );
		return chunks.join( '\n\n' );
	}

	function groupPreviewHtml( group ) {
		var html = '';
		state.steps.forEach( function ( step ) {
			if ( step.group === group && step.result ) {
				if ( step.result.rendered_html ) {
					html += step.result.rendered_html;
				}
			}
		} );
		return '<style>' + ( cfg.siteCss || '' ) + '</style>' + html;
	}

	function groupWarnings( group ) {
		var out = [];
		state.steps.forEach( function ( step ) {
			if ( step.group === group && step.result && step.result.warnings ) {
				out = out.concat( step.result.warnings );
			}
		} );
		return out;
	}

	function applyGroup( group, box, overwrite ) {
		var body = { target: group, markup: groupMarkup( group ), overwrite: !! overwrite };
		if ( 'home' === group ) {
			body.title = 'Home';
		}
		if ( 'inner' === group ) {
			body.title = ( state.innerPage && state.innerPage.title ) || '';
			body.slug = ( state.innerPage && state.innerPage.slug ) || '';
		}

		var note = box.querySelector( '.ekwa-ai-site-apply-note' );
		note.textContent = cfg.i18n.working;
		note.style.color = '#646970';

		post( cfg.applyUrl, body ).then( function ( res ) {
			if ( res.needs_confirmation ) {
				if ( window.confirm( res.message ) ) {
					applyGroup( group, box, true );
				} else {
					note.textContent = '';
				}
				return;
			}
			note.style.color = '#008a20';
			note.textContent = res.message || cfg.i18n.applied;
			var links = [];
			if ( res.view_url ) { links.push( '<a href="' + res.view_url + '" target="_blank" rel="noopener">' + cfg.i18n.view + '</a>' ); }
			if ( res.edit_url ) { links.push( '<a href="' + res.edit_url + '" target="_blank" rel="noopener">' + cfg.i18n.edit + '</a>' ); }
			if ( links.length ) {
				var span = document.createElement( 'span' );
				span.innerHTML = ' ' + links.join( ' · ' );
				note.appendChild( span );
			}
		} ).catch( function ( err ) {
			note.style.color = '#d63638';
			note.textContent = err.message;
		} );
	}

	function renderResults() {
		var groups = [];
		[ 'header', 'footer', 'home', 'inner' ].forEach( function ( g ) {
			if ( state.steps.some( function ( s ) { return s.group === g && s.result; } ) ) {
				groups.push( g );
			}
		} );

		els.results.hidden = 0 === groups.length;
		els.results.innerHTML = '';
		if ( ! groups.length ) {
			return;
		}

		var labels = {
			header: cfg.i18n.groupHeader,
			footer: cfg.i18n.groupFooter,
			home: cfg.i18n.groupHome,
			inner: ( state.innerPage && state.innerPage.title )
				? cfg.i18n.groupInner.replace( '%s', state.innerPage.title )
				: cfg.i18n.groupInner.replace( '%s', '' )
		};

		groups.forEach( function ( group ) {
			var box = document.createElement( 'div' );
			box.className = 'ekwa-ai-site-result';
			box.style.cssText = 'border:1px solid #c3c4c7;border-radius:4px;margin:12px 0;padding:12px;background:#fff;';

			var h = document.createElement( 'h3' );
			h.style.margin = '0 0 8px';
			h.textContent = labels[ group ];
			box.appendChild( h );

			var warnings = groupWarnings( group );
			if ( warnings.length ) {
				var warn = document.createElement( 'p' );
				warn.style.cssText = 'color:#996800;margin:4px 0;';
				warn.textContent = '⚠ ' + warnings.join( ' ' );
				box.appendChild( warn );
			}

			// Render at desktop width (1400px) scaled down to fit — generated
			// headers hide themselves below the desktop breakpoint, so a
			// narrow iframe would show them blank.
			var frameWrap = document.createElement( 'div' );
			frameWrap.style.cssText = 'overflow:hidden;height:360px;border:1px solid #dcdcde;border-radius:2px;background:#fff;';
			var iframe = document.createElement( 'iframe' );
			iframe.style.cssText = 'border:0;width:1400px;height:360px;transform-origin:top left;';
			iframe.setAttribute( 'sandbox', 'allow-same-origin' );
			iframe.srcdoc = groupPreviewHtml( group );
			frameWrap.appendChild( iframe );
			box.appendChild( frameWrap );

			var row = document.createElement( 'p' );
			row.style.margin = '10px 0 0';

			var apply = document.createElement( 'button' );
			apply.type = 'button';
			apply.className = 'button button-primary';
			apply.textContent = cfg.i18n.applyLabels[ group ];
			apply.addEventListener( 'click', function () {
				applyGroup( group, box, false );
			} );
			row.appendChild( apply );

			var copy = document.createElement( 'button' );
			copy.type = 'button';
			copy.className = 'button';
			copy.style.marginLeft = '8px';
			copy.textContent = cfg.i18n.copyMarkup;
			copy.addEventListener( 'click', function () {
				navigator.clipboard.writeText( groupMarkup( group ) ).then( function () {
					copy.textContent = cfg.i18n.copied;
					setTimeout( function () { copy.textContent = cfg.i18n.copyMarkup; }, 1500 );
				} );
			} );
			row.appendChild( copy );

			var note = document.createElement( 'span' );
			note.className = 'ekwa-ai-site-apply-note';
			note.style.marginLeft = '10px';
			row.appendChild( note );

			box.appendChild( row );
			els.results.appendChild( box );

			// Scale now that the box is in the DOM and has a measurable width.
			var avail = frameWrap.clientWidth || 800;
			var scale = Math.min( 1, avail / 1400 );
			iframe.style.transform = 'scale(' + scale + ')';
			iframe.style.height = Math.round( 360 / scale ) + 'px';
		} );
	}

	// ─── Generate button ─────────────────────────────────────────────────

	els.generate.addEventListener( 'click', function () {
		if ( state.running ) {
			return;
		}
		if ( ! cfg.hasKey ) {
			setStatus( cfg.i18n.noKey, true );
			return;
		}
		var desc = ( els.desc.value || '' ).trim();
		if ( ! desc ) {
			setStatus( cfg.i18n.noDescription, true );
			els.desc.focus();
			return;
		}
		var parts = checkedParts();
		if ( ! parts.length ) {
			setStatus( cfg.i18n.noParts, true );
			return;
		}

		state.running = true;
		els.generate.disabled = true;
		els.results.hidden = true;
		els.results.innerHTML = '';
		els.progress.hidden = true;
		setStatus( cfg.i18n.planning );

		post( cfg.planUrl, {
			description: desc,
			parts: parts,
			inner_topic: els.innerTopic ? els.innerTopic.value : '',
			images: imagesPayload(),
			model: els.model ? els.model.value : 'gemini-2.5-flash'
		} ).then( function ( plan ) {
			state.summary = plan.site_summary || '';
			state.innerPage = plan.inner_page || {};
			state.steps = ( plan.steps || [] ).map( function ( step ) {
				step.status = 'pending';
				step.result = null;
				return step;
			} );
			renderProgress();
			return runAllSteps( 0 );
		} ).then( function () {
			var failed = state.steps.filter( function ( s ) { return 'error' === s.status; } ).length;
			setStatus( failed
				? cfg.i18n.doneWithErrors.replace( '%s', failed )
				: cfg.i18n.done );
			renderResults();
		} ).catch( function ( err ) {
			setStatus( err.message, true );
		} ).then( function () {
			state.running = false;
			els.generate.disabled = false;
		} );
	} );
} )();
