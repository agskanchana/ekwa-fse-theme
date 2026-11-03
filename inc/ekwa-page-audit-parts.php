<?php
/**
 * Page audit — fixes inside template parts (header, footer, …).
 *
 * A PageSpeed report covers the whole page, so many issues sit in the header
 * or footer, which are not part of the page the editor shows. This module
 * gives the AI those parts too — the ones the page's template actually uses —
 * and lets it propose a "part" fix: change attributes of ONE block in ONE
 * part (an image's alt text, a link's label, a lazy-load switch…).
 *
 * The change is surgical: only that block's comment delimiter is rewritten
 * (<!-- wp:name {attributes} -->); every other byte of the part stays as it
 * was. The before/after delimiter is logged, so Undo puts the exact text back,
 * and refuses if the block was edited again since.
 *
 * Only attributes stored in the delimiter can be changed this way. Attributes
 * a block keeps in its HTML (core/image's alt, a paragraph's text) need the
 * block editor to re-save the markup, so those fixes stay manual. So do parts
 * that are still the theme's file (never customised): writing them would fork
 * the theme's copy into the database.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// WHICH PARTS A PAGE USES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Template parts the post's template renders, keyed by slug.
 *
 * @param WP_Post $post
 * @return array<string,array{slug:string,title:string,area:string,id:int,editable:bool,content:string}>
 */
function ekwa_page_audit_parts_for_post( $post ) {
	if ( ! function_exists( 'get_block_template' ) || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
		return array();
	}
	$theme      = get_stylesheet();
	$candidates = array();
	$custom     = get_page_template_slug( $post );
	if ( $custom ) {
		$candidates[] = $custom;
	}
	if ( 'page' === $post->post_type ) {
		if ( (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
			$candidates[] = 'front-page';
		}
		$candidates[] = 'page-' . $post->post_name;
		$candidates[] = 'page-' . $post->ID;
		$candidates[] = 'page';
	} else {
		$candidates[] = 'single-' . $post->post_type . '-' . $post->post_name;
		$candidates[] = 'single-' . $post->post_type;
		$candidates[] = 'single';
	}
	$candidates[] = 'singular';
	$candidates[] = 'index';

	$template = null;
	foreach ( array_unique( $candidates ) as $slug ) {
		$found = get_block_template( $theme . '//' . $slug, 'wp_template' );
		if ( $found && ! empty( $found->content ) ) {
			$template = $found;
			break;
		}
	}

	$slugs = array();
	if ( $template ) {
		$slugs = ekwa_page_audit_parts_referenced( $template->content );
	}
	if ( ! $slugs ) {
		$slugs = array( 'header', 'footer' );
	}

	$parts = array();
	$queue = $slugs;
	$guard = 0;
	while ( $queue && $guard++ < 12 ) {
		$slug = array_shift( $queue );
		if ( isset( $parts[ $slug ] ) ) {
			continue;
		}
		$part = get_block_template( $theme . '//' . $slug, 'wp_template_part' );
		if ( ! $part || '' === trim( (string) $part->content ) ) {
			continue;
		}
		$parts[ $slug ] = array(
			'slug'     => $slug,
			'title'    => $part->title ? (string) $part->title : $slug,
			'area'     => isset( $part->area ) ? (string) $part->area : '',
			'id'       => ! empty( $part->wp_id ) ? (int) $part->wp_id : 0,
			'editable' => 'custom' === $part->source && ! empty( $part->wp_id ),
			'content'  => (string) $part->content,
		);
		foreach ( ekwa_page_audit_parts_referenced( $part->content ) as $nested ) {
			if ( ! isset( $parts[ $nested ] ) ) {
				$queue[] = $nested;
			}
		}
	}
	return $parts;
}

/**
 * Slugs of the core/template-part blocks in some block markup.
 *
 * @param string $content
 * @return string[]
 */
function ekwa_page_audit_parts_referenced( $content ) {
	$out = array();
	foreach ( ekwa_page_audit_block_scan( (string) $content ) as $block ) {
		if ( 'core/template-part' === $block['name'] && ! empty( $block['attrs']['slug'] ) ) {
			$out[] = sanitize_title( (string) $block['attrs']['slug'] );
		}
	}
	return array_values( array_unique( $out ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// BLOCK SCANNER (offsets into the serialized markup)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Every block delimiter in serialized block markup, with a path and offsets.
 *
 * Paths count delimited blocks only ("0.2.1" = 2nd child of the 3rd child of
 * the first top-level block), the same way for the outline sent to the model
 * and for locating the block again when a fix is applied.
 *
 * @param string $content
 * @return array[] {path, name, attrs, opener_start, opener_len, void, inner_start, inner_end}
 */
function ekwa_page_audit_block_scan( $content ) {
	// The same delimiter grammar as WP_Block_Parser::next_token().
	$re = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
	if ( ! preg_match_all( $re, (string) $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return array();
	}

	$blocks   = array();
	$stack    = array();
	$counters = array();

	foreach ( $matches as $tok ) {
		$start  = $tok[0][1];
		$len    = strlen( $tok[0][0] );
		$closer = isset( $tok['closer'] ) && -1 !== $tok['closer'][1] && '' !== $tok['closer'][0];
		$void   = isset( $tok['void'] ) && -1 !== $tok['void'][1] && '' !== $tok['void'][0];
		$ns     = ( isset( $tok['namespace'] ) && -1 !== $tok['namespace'][1] && '' !== $tok['namespace'][0] ) ? $tok['namespace'][0] : 'core/';
		$name   = $ns . $tok['name'][0];

		if ( $closer ) {
			if ( $stack ) {
				$top                         = array_pop( $stack );
				$blocks[ $top ]['inner_end'] = $start;
			}
			continue;
		}

		$parent = $stack ? (int) end( $stack ) : -1;
		$idx    = isset( $counters[ $parent ] ) ? $counters[ $parent ] : 0;
		$counters[ $parent ] = $idx + 1;
		$path   = $parent >= 0 ? $blocks[ $parent ]['path'] . '.' . $idx : (string) $idx;

		$attrs = array();
		if ( isset( $tok['attrs'] ) && -1 !== $tok['attrs'][1] && '' !== trim( $tok['attrs'][0] ) ) {
			$decoded = json_decode( trim( $tok['attrs'][0] ), true );
			$attrs   = is_array( $decoded ) ? $decoded : array();
		}

		$blocks[] = array(
			'path'         => $path,
			'name'         => $name,
			'attrs'        => $attrs,
			'opener_start' => $start,
			'opener_len'   => $len,
			'void'         => $void,
			'inner_start'  => $start + $len,
			'inner_end'    => $start + $len,
		);
		if ( ! $void ) {
			$stack[] = count( $blocks ) - 1;
		}
	}
	return $blocks;
}

/**
 * A block's opening delimiter, serialized the way core does it.
 *
 * @param string $name  Full block name.
 * @param array  $attrs
 * @param bool   $void
 * @return string
 */
function ekwa_page_audit_block_opener( $name, $attrs, $void ) {
	$serialized = empty( $attrs ) ? '' : serialize_block_attributes( $attrs ) . ' ';
	return '<!-- wp:' . strip_core_block_namespace( $name ) . ' ' . $serialized . ( $void ? '/' : '' ) . '-->';
}

// ═══════════════════════════════════════════════════════════════════════════════
// CONTEXT FOR THE MODEL
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * A block's attributes, trimmed the way the editor trims the page outline.
 *
 * @param string $name
 * @param array  $attrs
 * @return array
 */
function ekwa_page_audit_parts_compact_attrs( $name, $attrs ) {
	$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
	$defs = $type && is_array( $type->attributes ) ? $type->attributes : array();
	$out  = array();
	foreach ( $attrs as $key => $value ) {
		if ( 'lock' === $key || 'metadata' === $key || null === $value || '' === $value ) {
			continue;
		}
		if ( isset( $defs[ $key ]['default'] ) && $defs[ $key ]['default'] === $value ) {
			continue;
		}
		if ( is_string( $value ) ) {
			$out[ $key ] = function_exists( 'mb_substr' ) && mb_strlen( $value ) > 220 ? mb_substr( $value, 0, 219 ) . '…' : $value;
		} elseif ( is_scalar( $value ) ) {
			$out[ $key ] = $value;
		} else {
			$json        = (string) wp_json_encode( $value );
			$out[ $key ] = strlen( $json ) <= 700 ? $value : '[' . ( wp_is_numeric_array( $value ) ? 'list' : 'object' ) . ', ' . strlen( $json ) . ' chars — not shown]';
		}
	}
	return $out;
}

/**
 * A block type's attribute schema for the model. "html": true marks an
 * attribute stored in the block's HTML — not changeable from here.
 *
 * @param string $name
 * @return array|null
 */
function ekwa_page_audit_parts_schema( $name ) {
	$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
	if ( ! $type ) {
		return null;
	}
	$attrs = array();
	foreach ( (array) $type->attributes as $key => $def ) {
		if ( 'lock' === $key || 'metadata' === $key || ! is_array( $def ) ) {
			continue;
		}
		$row = array( 'type' => isset( $def['type'] ) ? $def['type'] : ( isset( $def['source'] ) ? 'string' : 'any' ) );
		if ( isset( $def['enum'] ) ) {
			$row['enum'] = $def['enum'];
		}
		if ( array_key_exists( 'default', $def ) && strlen( (string) wp_json_encode( $def['default'] ) ) <= 80 ) {
			$row['default'] = $def['default'];
		}
		if ( isset( $def['source'] ) ) {
			$row['html'] = true;
		}
		$attrs[ $key ] = $row;
	}
	return array( 'title' => $type->title ? $type->title : $name, 'attrs' => $attrs );
}

/**
 * Template parts for the model, plus what validation needs.
 *
 * @param WP_Post $post
 * @return array{parts:array[],schemas:array,index:array,editable:array}
 */
function ekwa_page_audit_parts_context( $post ) {
	$out      = array();
	$index    = array();
	$editable = array();
	$schemas  = array();
	$css      = array();
	$budget   = 110000;

	foreach ( ekwa_page_audit_parts_for_post( $post ) as $slug => $part ) {
		$list = array();
		foreach ( ekwa_page_audit_block_scan( $part['content'] ) as $block ) {
			if ( count( $list ) >= 400 ) {
				break;
			}
			$row   = array( 'path' => $block['path'], 'name' => $block['name'] );
			$attrs = ekwa_page_audit_parts_compact_attrs( $block['name'], $block['attrs'] );
			// Section CSS goes whole into "css" (editable by css fixes), not
			// truncated into attrs.
			$scoped = isset( $block['attrs']['scopedCss'] ) && is_string( $block['attrs']['scopedCss'] ) ? $block['attrs']['scopedCss'] : '';
			if ( '' !== trim( $scoped ) && strlen( $scoped ) <= 40000 ) {
				unset( $attrs['scopedCss'] );
				$row['css']                        = $scoped;
				$css[ $slug ][ $block['path'] ] = $scoped;
			}
			if ( $attrs ) {
				$row['attrs'] = $attrs;
			}
			if ( ! $block['void'] && $block['inner_end'] > $block['inner_start'] ) {
				$inner = substr( $part['content'], $block['inner_start'], min( 4000, $block['inner_end'] - $block['inner_start'] ) );
				$text  = ekwa_page_audit_text( preg_replace( '/<!--.*?-->/s', ' ', $inner ), 100 );
				if ( '' !== $text ) {
					$row['text'] = $text;
				}
			}
			$size = strlen( (string) wp_json_encode( $row ) );
			if ( $size > $budget ) {
				break;
			}
			$budget                           -= $size;
			$list[]                            = $row;
			$index[ $slug ][ $block['path'] ] = $block['name'];
			if ( ! isset( $schemas[ $block['name'] ] ) ) {
				$schema = ekwa_page_audit_parts_schema( $block['name'] );
				if ( $schema ) {
					$schemas[ $block['name'] ] = $schema;
				}
			}
		}
		$editable[ $slug ] = $part['editable'];
		$out[]             = array(
			'part'     => $slug,
			'title'    => $part['title'],
			'area'     => $part['area'],
			'editable' => $part['editable'],
			'blocks'   => $list,
		);
	}

	return array(
		'parts'    => $out,
		'schemas'  => $schemas,
		'index'    => $index,
		'editable' => $editable,
		'css'      => $css,
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// VALIDATION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Coerce a value to an attribute definition's type, or WP_Error.
 *
 * @param array $def
 * @param mixed $value
 * @return mixed|WP_Error
 */
function ekwa_page_audit_parts_coerce( $def, $value ) {
	$type = isset( $def['type'] ) ? $def['type'] : '';
	if ( is_array( $type ) ) {
		return $value; // Union types: leave as given.
	}
	if ( 'boolean' === $type ) {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( 'true', '1', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}
	if ( 'number' === $type || 'integer' === $type ) {
		if ( ! is_numeric( $value ) ) {
			return new WP_Error( 'type', 'number expected' );
		}
		return 'integer' === $type ? (int) $value : $value + 0;
	}
	if ( 'string' === $type ) {
		if ( ! is_scalar( $value ) ) {
			return new WP_Error( 'type', 'string expected' );
		}
		$value = (string) $value;
	}
	if ( ( 'object' === $type && ( ! is_array( $value ) || wp_is_numeric_array( $value ) ) ) || ( 'array' === $type && ! is_array( $value ) ) ) {
		return new WP_Error( 'type', 'structure expected' );
	}
	if ( isset( $def['enum'] ) && is_array( $def['enum'] ) && ! in_array( $value, $def['enum'], true ) ) {
		return new WP_Error( 'enum', 'not allowed' );
	}
	return $value;
}

/**
 * Check a model-proposed part fix.
 *
 * @param array $fix
 * @param array $ctx From ekwa_page_audit_parts_context().
 * @return array {ok:true, part, path, block, attrs} or {ok:false, reason}
 */
function ekwa_page_audit_parts_validate( $fix, $ctx ) {
	$slug  = isset( $fix['part'] ) ? sanitize_title( (string) $fix['part'] ) : '';
	$path  = isset( $fix['path'] ) ? (string) $fix['path'] : '';
	$block = isset( $fix['block'] ) ? (string) $fix['block'] : '';
	$attrs = isset( $fix['attrs'] ) && is_array( $fix['attrs'] ) ? $fix['attrs'] : array();

	if ( ! isset( $ctx['index'][ $slug ][ $path ] ) || $ctx['index'][ $slug ][ $path ] !== $block ) {
		return array( 'ok' => false, 'reason' => __( 'The header/footer block this applies to could not be matched, so it was not made one-click.', 'ekwa' ) );
	}
	if ( empty( $ctx['editable'][ $slug ] ) ) {
		return array( 'ok' => false, 'reason' => __( 'This template part is still the theme’s own file, so it is changed in Appearance → Editor rather than from here.', 'ekwa' ) );
	}
	if ( ! $attrs || wp_is_numeric_array( $attrs ) || strlen( (string) wp_json_encode( $attrs ) ) > 6000 ) {
		return array( 'ok' => false, 'reason' => __( 'The suggested block change was not in a form that can be applied automatically.', 'ekwa' ) );
	}
	$type = WP_Block_Type_Registry::get_instance()->get_registered( $block );
	$defs = $type && is_array( $type->attributes ) ? $type->attributes : array();
	$out  = array();
	foreach ( $attrs as $key => $value ) {
		if ( ! isset( $defs[ $key ] ) ) {
			return array( 'ok' => false, 'reason' => sprintf( /* translators: %s: attribute */ __( 'That block has no “%s” setting.', 'ekwa' ), $key ) );
		}
		if ( isset( $defs[ $key ]['source'] ) ) {
			return array( 'ok' => false, 'reason' => __( 'That setting is stored in the block’s HTML, so it has to be changed in Appearance → Editor.', 'ekwa' ) );
		}
		$value = ekwa_page_audit_parts_coerce( $defs[ $key ], $value );
		if ( is_wp_error( $value ) ) {
			return array( 'ok' => false, 'reason' => sprintf( /* translators: %s: attribute */ __( 'The suggested value for “%s” does not fit that setting.', 'ekwa' ), $key ) );
		}
		$out[ $key ] = $value;
	}
	return array( 'ok' => true, 'part' => $slug, 'path' => $path, 'block' => $block, 'attrs' => $out );
}

// ═══════════════════════════════════════════════════════════════════════════════
// APPLY / UNDO
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Merge patch attributes into current ones (objects merged key by key).
 *
 * @param array $base
 * @param array $patch
 * @return array
 */
function ekwa_page_audit_parts_merge( $base, $patch ) {
	foreach ( $patch as $key => $value ) {
		if ( isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! wp_is_numeric_array( $base[ $key ] ) && is_array( $value ) && ! wp_is_numeric_array( $value ) ) {
			$base[ $key ] = ekwa_page_audit_parts_merge( $base[ $key ], $value );
		} else {
			$base[ $key ] = $value;
		}
	}
	return $base;
}

/**
 * The editable template part post for a slug, or WP_Error.
 *
 * @param string $slug
 * @return WP_Post|WP_Error
 */
function ekwa_page_audit_parts_post( $slug ) {
	$part = function_exists( 'get_block_template' ) ? get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' ) : null;
	if ( ! $part || 'custom' !== $part->source || empty( $part->wp_id ) ) {
		return new WP_Error( 'ekwa_page_audit_part', __( 'That template part cannot be changed from here (it is the theme’s own file, or was removed).', 'ekwa' ), array( 'status' => 409 ) );
	}
	$post = get_post( (int) $part->wp_id );
	if ( ! $post || 'wp_template_part' !== $post->post_type ) {
		return new WP_Error( 'ekwa_page_audit_part', __( 'Template part not found.', 'ekwa' ), array( 'status' => 404 ) );
	}
	return $post;
}

/**
 * Save new content for a template part. kses is off for the write: only one
 * block delimiter changed, and its attributes were serialized with core's own
 * escaping — filtering the rest would only damage markup the part already has
 * (inline SVG icons, for one) on a site without unfiltered_html.
 *
 * @param WP_Post $post
 * @param string  $content
 * @return true|WP_Error
 */
function ekwa_page_audit_parts_save( $post, $content ) {
	$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
	if ( $kses ) {
		kses_remove_filters();
	}
	$result = wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $content ) ), true );
	if ( $kses ) {
		kses_init_filters();
	}
	return is_wp_error( $result ) ? $result : true;
}

/**
 * Apply a stored part fix and log it.
 *
 * @param int   $post_id Page the audit was run on.
 * @param array $fix
 * @return array|WP_Error Public change entry.
 */
function ekwa_page_audit_parts_apply_fix( $post_id, $fix ) {
	$part_post = ekwa_page_audit_parts_post( $fix['part'] );
	if ( is_wp_error( $part_post ) ) {
		return $part_post;
	}

	$lock = function_exists( 'ekwa_ai_lock' ) ? ekwa_ai_lock( 'page-audit-part', 5 ) : 'none';
	if ( '' === $lock ) {
		return new WP_Error( 'ekwa_page_audit_busy', __( 'Busy — try again in a moment.', 'ekwa' ), array( 'status' => 503 ) );
	}
	try {
		clean_post_cache( $part_post->ID );
		$part_post = get_post( $part_post->ID );
		$content   = (string) $part_post->post_content;
		$target    = null;
		foreach ( ekwa_page_audit_block_scan( $content ) as $block ) {
			if ( $block['path'] === $fix['path'] ) {
				$target = $block;
				break;
			}
		}
		if ( ! $target || $target['name'] !== $fix['block'] ) {
			return new WP_Error( 'ekwa_page_audit_part_moved', __( 'That block is no longer at the same place in the template part. Analyze again.', 'ekwa' ), array( 'status' => 409 ) );
		}

		$after_attrs = ekwa_page_audit_parts_merge( $target['attrs'], $fix['attrs'] );
		if ( $after_attrs === $target['attrs'] ) {
			return new WP_Error( 'ekwa_page_audit_same', __( 'That change is already in place.', 'ekwa' ), array( 'status' => 409 ) );
		}
		$before_opener = substr( $content, $target['opener_start'], $target['opener_len'] );
		$after_opener  = ekwa_page_audit_block_opener( $target['name'], $after_attrs, $target['void'] );
		$new_content   = substr( $content, 0, $target['opener_start'] ) . $after_opener . substr( $content, $target['opener_start'] + $target['opener_len'] );

		$before = array();
		$after  = array();
		foreach ( array_keys( $fix['attrs'] ) as $key ) {
			$before[ $key ] = array_key_exists( $key, $target['attrs'] ) ? $target['attrs'][ $key ] : null;
			$after[ $key ]  = $after_attrs[ $key ];
		}

		$entry = array(
			'id'            => ekwa_page_audit_id(),
			'type'          => 'part',
			'time'          => time(),
			'user'          => ekwa_page_audit_user_name(),
			'post_id'       => (int) $post_id,
			'fix_id'        => isset( $fix['id'] ) ? (string) $fix['id'] : '',
			'title'         => isset( $fix['title'] ) ? (string) $fix['title'] : '',
			'category'      => isset( $fix['category'] ) ? (string) $fix['category'] : 'accessibility',
			'audit'         => isset( $fix['audit'] ) ? (string) $fix['audit'] : '',
			'part'          => $fix['part'],
			'part_id'       => (int) $part_post->ID,
			'part_title'    => get_the_title( $part_post ),
			'path'          => $fix['path'],
			'block'         => $fix['block'],
			'before'        => $before,
			'after'         => $after,
			'before_opener' => $before_opener,
			'after_opener'  => $after_opener,
			'undone'        => 0,
		);
		$logged = ekwa_page_audit_mutate_changes( static function ( $log ) use ( $entry ) {
			$log[] = $entry;
			return $log;
		} );
		if ( ! $logged ) {
			return new WP_Error( 'ekwa_page_audit_busy', __( 'Busy — try again in a moment.', 'ekwa' ), array( 'status' => 503 ) );
		}
		$saved = ekwa_page_audit_parts_save( $part_post, $new_content );
		if ( is_wp_error( $saved ) ) {
			ekwa_page_audit_mutate_changes( static function ( $log ) use ( $entry ) {
				return array_values( array_filter( $log, static function ( $e ) use ( $entry ) {
					return ! isset( $e['id'] ) || $e['id'] !== $entry['id'];
				} ) );
			} );
			return $saved;
		}
	} finally {
		if ( 'none' !== $lock && function_exists( 'ekwa_ai_unlock' ) ) {
			ekwa_ai_unlock( $lock );
		}
	}

	ekwa_page_audit_purge_caches();
	return ekwa_page_audit_public_change( $entry );
}

/**
 * Locate a logged part change's block in the part's current content.
 *
 * @param array  $entry
 * @param string $content
 * @return array|null Scanned block, or null.
 */
function ekwa_page_audit_parts_find_entry_block( $entry, $content ) {
	foreach ( ekwa_page_audit_block_scan( $content ) as $block ) {
		if ( $block['path'] === $entry['path'] && $block['name'] === $entry['block'] ) {
			return $block;
		}
	}
	// The block moved: its exact delimiter still identifies it when unique.
	if ( ! empty( $entry['after_opener'] ) && 1 === substr_count( $content, $entry['after_opener'] ) ) {
		$pos = strpos( $content, $entry['after_opener'] );
		foreach ( ekwa_page_audit_block_scan( $content ) as $block ) {
			if ( $block['opener_start'] === $pos ) {
				return $block;
			}
		}
	}
	return null;
}

/**
 * Where a logged part change stands now.
 *
 * @param array $entry
 * @return string applied|reverted|changed|missing
 */
function ekwa_page_audit_parts_entry_state( $entry ) {
	$post = get_post( isset( $entry['part_id'] ) ? (int) $entry['part_id'] : 0 );
	if ( ! $post ) {
		return 'missing';
	}
	$block = ekwa_page_audit_parts_find_entry_block( $entry, (string) $post->post_content );
	if ( ! $block ) {
		return 'missing';
	}
	$is_after  = true;
	$is_before = true;
	foreach ( (array) $entry['after'] as $key => $value ) {
		$now = array_key_exists( $key, $block['attrs'] ) ? $block['attrs'][ $key ] : null;
		if ( $now !== $value ) {
			$is_after = false;
		}
		$was = isset( $entry['before'][ $key ] ) ? $entry['before'][ $key ] : null;
		if ( $now !== $was ) {
			$is_before = false;
		}
	}
	return $is_after ? 'applied' : ( $is_before ? 'reverted' : 'changed' );
}

/**
 * Undo a logged part change.
 *
 * @param array $entry
 * @return true|WP_Error
 */
function ekwa_page_audit_parts_undo_entry( $entry ) {
	$post = get_post( isset( $entry['part_id'] ) ? (int) $entry['part_id'] : 0 );
	if ( ! $post || 'wp_template_part' !== $post->post_type ) {
		return new WP_Error( 'ekwa_page_audit_part', __( 'The template part this changed no longer exists.', 'ekwa' ), array( 'status' => 404 ) );
	}
	$content = (string) $post->post_content;
	$block   = ekwa_page_audit_parts_find_entry_block( $entry, $content );
	if ( ! $block ) {
		return new WP_Error( 'ekwa_page_audit_part_moved', __( 'That block is no longer in the template part, so it was not undone automatically.', 'ekwa' ), array( 'status' => 409 ) );
	}
	$state = ekwa_page_audit_parts_entry_state( $entry );
	if ( 'reverted' === $state ) {
		return true; // Nothing to put back.
	}
	if ( 'applied' !== $state ) {
		return new WP_Error( 'ekwa_page_audit_part_changed', __( 'The block was edited again after this fix, so it was not undone — that would overwrite the newer edit.', 'ekwa' ), array( 'status' => 409 ) );
	}

	$current = substr( $content, $block['opener_start'], $block['opener_len'] );
	if ( $current === $entry['after_opener'] ) {
		$opener = $entry['before_opener'];
	} else {
		// Same values, different delimiter text: rebuild from the attributes.
		$attrs = $block['attrs'];
		foreach ( (array) $entry['before'] as $key => $value ) {
			if ( null === $value ) {
				unset( $attrs[ $key ] );
			} else {
				$attrs[ $key ] = $value;
			}
		}
		$opener = ekwa_page_audit_block_opener( $block['name'], $attrs, $block['void'] );
	}
	$new = substr( $content, 0, $block['opener_start'] ) . $opener . substr( $content, $block['opener_start'] + $block['opener_len'] );
	return ekwa_page_audit_parts_save( $post, $new );
}
