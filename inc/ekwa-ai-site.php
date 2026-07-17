<?php
/**
 * AI Site Generator — orchestrated whole-site generation (header, footer,
 * home page, one inner page) built from ekwa/* blocks.
 *
 * Three REST endpoints drive a multi-step pipeline (the settings-page JS calls
 * them sequentially so no single request outlives one Gemini call):
 *
 *   POST /ekwa/v1/ai-site-plan   — one planning call: business info (Ekwa
 *        Settings), design tokens, project memory, and the MEDIA MANIFEST go
 *        in; a JSON plan comes out (per-part briefs + which real media file
 *        goes where). The plan is normalized into a flat list of steps.
 *
 *   POST /ekwa/v1/ai-site-part   — generate ONE part/section from its brief by
 *        delegating to the proven AI Block Builder pipeline
 *        (ekwa_ai_generate_blocks_handle_request): block-spec prompt, JSON
 *        repair, self-correct, scoped CSS embedding, server preview.
 *
 *   POST /ekwa/v1/ai-site-apply  — persist an accepted result: header/footer
 *        as wp_template_part posts (they override parts/header.html and
 *        parts/footer.html; deleting the part in the Site Editor reverts),
 *        home as the static front page, inner as a new page.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/ekwa-ai-shared.php';

add_action( 'rest_api_init', 'ekwa_ai_site_register_routes' );

/**
 * Register the site-generator REST routes.
 */
function ekwa_ai_site_register_routes() {
	register_rest_route( 'ekwa/v1', '/ai-site-plan', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_site_plan_handler',
		'permission_callback' => 'ekwa_ai_site_permission',
		'args'                => array(
			'description' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'images'      => array( 'required' => false, 'type' => 'array',  'default' => array() ),
			'parts'       => array( 'required' => false, 'type' => 'array',  'default' => array( 'header', 'footer', 'home', 'inner' ) ),
			'inner_topic' => array( 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'model'       => array( 'required' => false, 'type' => 'string', 'default' => 'gemini-2.5-flash' ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/ai-site-part', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_site_part_handler',
		'permission_callback' => 'ekwa_ai_site_permission',
		'args'                => array(
			'context'      => array( 'required' => true,  'type' => 'string', 'enum' => array( 'header', 'footer', 'section' ) ),
			'brief'        => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'site_summary' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'media_ids'    => array( 'required' => false, 'type' => 'array',  'default' => array() ),
			'images'       => array( 'required' => false, 'type' => 'array',  'default' => array() ),
			'model'        => array( 'required' => false, 'type' => 'string', 'default' => 'gemini-2.5-flash' ),
			'temperature'  => array( 'required' => false, 'type' => 'number', 'default' => 0.3 ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/ai-site-apply', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_site_apply_handler',
		'permission_callback' => function () {
			// Applying rewrites site structure (front page, header/footer) —
			// admins only, regardless of the general AI minimum role.
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'target'    => array( 'required' => true, 'type' => 'string', 'enum' => array( 'header', 'footer', 'home', 'inner' ) ),
			'markup'    => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'title'     => array( 'required' => false, 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'slug'      => array( 'required' => false, 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_title' ),
			'overwrite' => array( 'required' => false, 'type' => 'boolean', 'default' => false ),
		),
	) );
}

/**
 * Permission for plan/part: the shared AI gate (role + daily cap + payload).
 *
 * @return true|WP_Error
 */
function ekwa_ai_site_permission() {
	return ekwa_ai_rest_permission();
}

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 1 — PLAN
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Business facts from Ekwa Settings, formatted as a prompt section. The
 * planner uses these for copy direction only — dynamic blocks (ekwa/phone,
 * ekwa/address, ekwa/hours…) pull the real values at render time.
 *
 * @return string
 */
function ekwa_ai_site_business_context() {
	$lines = array();

	$practice = trim( (string) get_option( 'ekwa_practice_name', '' ) );
	if ( '' !== $practice ) {
		$lines[] = 'Business name: ' . $practice;
	}
	$org = trim( (string) get_option( 'ekwa_organization_type', '' ) );
	if ( '' !== $org ) {
		$lines[] = 'Organization type: ' . $org;
	}
	$country = trim( (string) get_option( 'ekwa_country', '' ) );
	if ( '' !== $country ) {
		$lines[] = 'Country: ' . $country;
	}

	$locations = get_option( 'ekwa_locations', array() );
	if ( is_array( $locations ) && ! empty( $locations ) ) {
		$locs = array();
		foreach ( $locations as $loc ) {
			if ( ! is_array( $loc ) ) {
				continue;
			}
			$place = trim( implode( ', ', array_filter( array(
				(string) ( $loc['city'] ?? '' ),
				(string) ( $loc['state'] ?? '' ),
			) ) ) );
			if ( '' !== $place ) {
				$locs[] = $place;
			}
		}
		if ( $locs ) {
			$lines[] = 'Locations: ' . implode( ' | ', array_unique( $locs ) );
		}
	}

	$social = get_option( 'ekwa_social', array() );
	if ( is_array( $social ) && ! empty( $social ) ) {
		$nets = array();
		foreach ( $social as $item ) {
			if ( is_array( $item ) && '' !== trim( (string) ( $item['name'] ?? '' ) ) ) {
				$nets[] = trim( $item['name'] );
			}
		}
		if ( $nets ) {
			$lines[] = 'Social networks configured: ' . implode( ', ', array_unique( $nets ) );
		}
	}

	if ( empty( $lines ) ) {
		return '';
	}
	return "\n\nBUSINESS FACTS (from Theme Settings — use for tone/copy direction; NEVER hardcode phone numbers, addresses, or hours because the dynamic blocks render the real values):\n- "
		. implode( "\n- ", $lines ) . "\n";
}

/**
 * Handle POST /ekwa/v1/ai-site-plan.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_site_plan_handler( $request ) {
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'site-plan' );
	}
	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key not configured. Add it in Appearance → Ekwa Settings → AI.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$description = trim( (string) $request->get_param( 'description' ) );
	if ( '' === $description ) {
		return new WP_Error( 'empty_description', __( 'Describe the site first.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$parts = array_values( array_intersect(
		array( 'header', 'footer', 'home', 'inner' ),
		array_map( 'strval', (array) $request->get_param( 'parts' ) )
	) );
	if ( empty( $parts ) ) {
		return new WP_Error( 'no_parts', __( 'Select at least one thing to generate.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$inner_topic = trim( (string) $request->get_param( 'inner_topic' ) );
	$images      = (array) $request->get_param( 'images' );
	$model       = (string) $request->get_param( 'model' );
	$allowed     = ekwa_ai_generate_allowed_models();
	if ( ! isset( $allowed[ $model ] ) ) {
		$model = 'gemini-2.5-flash';
	}
	if ( count( $images ) > 6 ) {
		return new WP_Error( 'too_many_images', __( 'Up to 6 screenshots per request.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$system = ekwa_ai_site_plan_system_prompt( $parts, $inner_topic, ! empty( $images ) );

	// Reuses the Block Builder's multimodal contents builder (text + validated
	// inline screenshot parts).
	$contents = ekwa_ai_generate_build_contents(
		"SITE DESCRIPTION FROM THE OWNER:\n" . $description,
		$images
	);
	if ( is_wp_error( $contents ) ) {
		return $contents;
	}

	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.4, $api_key, $model );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'ai_error', $result->get_error_message(), array( 'status' => 502 ) );
	}

	$plan = ekwa_ai_site_extract_json( $result['content'] );
	if ( null === $plan ) {
		return new WP_Error( 'bad_plan', __( 'The AI returned an unreadable plan. Try again (or a different model).', 'ekwa' ), array( 'status' => 502 ) );
	}

	$normalized = ekwa_ai_site_normalize_plan( $plan, $parts );
	if ( empty( $normalized['steps'] ) ) {
		return new WP_Error( 'empty_plan', __( 'The AI plan contained no usable sections. Try again with a fuller description.', 'ekwa' ), array( 'status' => 502 ) );
	}

	return rest_ensure_response( $normalized );
}

/**
 * System prompt for the planning call.
 *
 * @param array  $parts       Requested parts: subset of header|footer|home|inner.
 * @param string $inner_topic Optional inner-page topic from the user.
 * @param bool   $has_images  Whether reference screenshots are attached.
 * @return string
 */
function ekwa_ai_site_plan_system_prompt( $parts, $inner_topic, $has_images = false ) {
	$want = array_fill_keys( $parts, true );

	$schema_lines = array();
	$schema_lines[] = '"site_summary": "2–3 sentences: what the business is, the tone of voice, and the visual direction (colors/typography feel) for every generator that follows"';
	if ( isset( $want['header'] ) ) {
		$schema_lines[] = '"header": { "brief": "detailed build brief for the DESKTOP header bar — layout, which elements (logo, menu, phone, button, …), styling direction", "media_ids": [] }';
	}
	if ( isset( $want['footer'] ) ) {
		$schema_lines[] = '"footer": { "brief": "detailed build brief for the footer — columns, address/hours/social/map/nav/copyright, styling direction", "media_ids": [] }';
	}
	if ( isset( $want['home'] ) ) {
		$schema_lines[] = '"home_sections": [ { "title": "short section name shown to the user (e.g. Hero, Services, Why Choose Us)", "brief": "detailed build brief for this ONE section: layout, copy direction, styling", "media_ids": [ids of manifest media to feature] }, … ]';
	}
	if ( isset( $want['inner'] ) ) {
		$topic_cue = '' !== $inner_topic
			? 'The owner wants the inner page to be about: "' . $inner_topic . '".'
			: 'Pick the single most valuable inner page for this business (About, Services, a key treatment/product…).';
		$schema_lines[] = '"inner_page": { "title": "page title", "slug": "url-slug", "sections": [ { "title": "…", "brief": "…", "media_ids": [] }, … ] }  — ' . $topic_cue;
	}

	$prompt = "You are the site architect for a WordPress block theme (Ekwa). Turn the owner's description into a build plan that section-generator AIs will execute one section at a time.\n\n"
		. "Return ONLY a JSON object (no prose, no Markdown fences) with EXACTLY these keys:\n{\n  " . implode( ",\n  ", $schema_lines ) . "\n}\n\n"
		. "PLANNING RULES:\n"
		. "- Home page: 4–7 sections, starting with a strong hero. Inner page: 2–4 sections (the theme already renders the page title and banner above the content — do not plan a title/banner section).\n"
		. "- Each \"brief\" must be a self-contained instruction (3–6 sentences) a generator can execute without seeing the other sections: what the section contains, the layout, the copy angle, and how it should feel. Do not reference other sections.\n"
		. "- Assign media from the MEDIA LIBRARY MANIFEST below by numeric id. Use each file at most once across the whole plan. A large landscape image (or a video) belongs in the hero. Leave media_ids empty when no listed file fits — the generator will use tasteful placeholders.\n"
		. "- Dynamic data (phone numbers, addresses, hours, menus, social links) comes from theme blocks at render time — briefs should mention WHICH dynamic elements appear, never their literal values.\n"
		. "- Respect the PROJECT MEMORY and the design tokens if provided.";

	if ( $has_images ) {
		$prompt .= "\n\nREFERENCE SCREENSHOTS: the owner attached screenshot(s) — a mockup, an existing site, or design inspiration. "
			. "Read the layout, color feel, typography, and section structure from them. Base site_summary's visual direction on what they show, "
			. "and write each brief so its section matches the corresponding part of the screenshots (describe the layout you see — e.g. "
			. "\"split hero, text left, image right, oversized serif headline\"). The same screenshots are also shown to the generator that "
			. "builds each section, so the briefs and the images will be interpreted together.";
	}

	if ( function_exists( 'ekwa_ai_project_memory_block' ) ) {
		$prompt .= ekwa_ai_project_memory_block();
	}
	$prompt .= ekwa_ai_site_business_context();
	if ( function_exists( 'ekwa_tokens_ai_context' ) ) {
		$prompt .= ekwa_tokens_ai_context();
	}
	if ( function_exists( 'ekwa_media_manifest_ai_context' ) ) {
		$prompt .= ekwa_media_manifest_ai_context();
	}

	return $prompt;
}

/**
 * Pull the first JSON object out of a model response (fences/prose tolerated).
 *
 * @param string $content Raw model output.
 * @return array|null Decoded object, or null.
 */
function ekwa_ai_site_extract_json( $content ) {
	$content = trim( (string) $content );
	if ( function_exists( 'ekwa_ai_generate_strip_fences' ) ) {
		$content = ekwa_ai_generate_strip_fences( $content );
	}
	$start = strpos( $content, '{' );
	$end   = strrpos( $content, '}' );
	if ( false === $start || false === $end || $end <= $start ) {
		return null;
	}
	$decoded = json_decode( substr( $content, $start, $end - $start + 1 ), true );
	return is_array( $decoded ) ? $decoded : null;
}

/**
 * Normalize the raw plan into a flat, validated list of generation steps the
 * JS can execute in order, dropping unknown media ids along the way.
 *
 * Step shape: { key, group (header|footer|home|inner), context (header|footer|
 * section), label, brief, media_ids[] }.
 *
 * @param array $plan  Decoded plan JSON.
 * @param array $parts Requested parts.
 * @return array{ site_summary:string, steps:array, inner_page:array, media:array }
 */
function ekwa_ai_site_normalize_plan( $plan, $parts ) {
	$manifest = function_exists( 'ekwa_media_manifest_get' ) ? ekwa_media_manifest_get() : array();
	$want     = array_fill_keys( $parts, true );
	$steps    = array();
	$used_ids = array();

	$clean_ids = function ( $ids ) use ( $manifest, &$used_ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && isset( $manifest[ $id ] ) && ! in_array( $id, $out, true ) ) {
				$out[]      = $id;
				$used_ids[] = $id;
			}
		}
		return $out;
	};

	$site_summary = trim( (string) ( $plan['site_summary'] ?? '' ) );

	if ( isset( $want['header'] ) && ! empty( $plan['header']['brief'] ) ) {
		$steps[] = array(
			'key'       => 'header',
			'group'     => 'header',
			'context'   => 'header',
			'label'     => __( 'Header', 'ekwa' ),
			'brief'     => trim( (string) $plan['header']['brief'] ),
			'media_ids' => $clean_ids( $plan['header']['media_ids'] ?? array() ),
		);
	}
	if ( isset( $want['footer'] ) && ! empty( $plan['footer']['brief'] ) ) {
		$steps[] = array(
			'key'       => 'footer',
			'group'     => 'footer',
			'context'   => 'footer',
			'label'     => __( 'Footer', 'ekwa' ),
			'brief'     => trim( (string) $plan['footer']['brief'] ),
			'media_ids' => $clean_ids( $plan['footer']['media_ids'] ?? array() ),
		);
	}
	if ( isset( $want['home'] ) && ! empty( $plan['home_sections'] ) && is_array( $plan['home_sections'] ) ) {
		$i = 0;
		foreach ( $plan['home_sections'] as $section ) {
			if ( ! is_array( $section ) || '' === trim( (string) ( $section['brief'] ?? '' ) ) ) {
				continue;
			}
			$title   = trim( (string) ( $section['title'] ?? '' ) );
			$steps[] = array(
				'key'       => 'home-' . $i,
				'group'     => 'home',
				'context'   => 'section',
				/* translators: %s: section name from the AI plan. */
				'label'     => sprintf( __( 'Home — %s', 'ekwa' ), '' !== $title ? $title : ( $i + 1 ) ),
				'brief'     => trim( (string) $section['brief'] ),
				'media_ids' => $clean_ids( $section['media_ids'] ?? array() ),
			);
			$i++;
		}
	}

	$inner_page = array();
	if ( isset( $want['inner'] ) && ! empty( $plan['inner_page']['sections'] ) && is_array( $plan['inner_page']['sections'] ) ) {
		$inner_title = trim( (string) ( $plan['inner_page']['title'] ?? '' ) );
		if ( '' === $inner_title ) {
			$inner_title = __( 'About Us', 'ekwa' );
		}
		$inner_slug = sanitize_title( (string) ( $plan['inner_page']['slug'] ?? $inner_title ) );
		$inner_page = array( 'title' => $inner_title, 'slug' => $inner_slug );

		$i = 0;
		foreach ( $plan['inner_page']['sections'] as $section ) {
			if ( ! is_array( $section ) || '' === trim( (string) ( $section['brief'] ?? '' ) ) ) {
				continue;
			}
			$title   = trim( (string) ( $section['title'] ?? '' ) );
			$steps[] = array(
				'key'       => 'inner-' . $i,
				'group'     => 'inner',
				'context'   => 'section',
				/* translators: 1: inner page title, 2: section name. */
				'label'     => sprintf( __( '%1$s — %2$s', 'ekwa' ), $inner_title, '' !== $title ? $title : ( $i + 1 ) ),
				'brief'     => trim( (string) $section['brief'] ),
				'media_ids' => $clean_ids( $section['media_ids'] ?? array() ),
			);
			$i++;
		}
	}

	// Media details for the UI (thumbnails/labels next to each step).
	$media = array();
	foreach ( array_unique( $used_ids ) as $id ) {
		$e           = $manifest[ $id ];
		$media[ $id ] = array(
			'id'   => $id,
			'type' => $e['type'],
			'url'  => $e['url'],
			'alt'  => (string) ( $e['alt'] ?? '' ),
		);
	}

	return array(
		'site_summary' => $site_summary,
		'steps'        => $steps,
		'inner_page'   => $inner_page,
		'media'        => $media,
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 2 — GENERATE ONE PART
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Handle POST /ekwa/v1/ai-site-part — delegate to the AI Block Builder with a
 * prompt assembled from the plan step (site summary + brief + resolved media).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_site_part_handler( $request ) {
	$context      = (string) $request->get_param( 'context' );
	$brief        = trim( (string) $request->get_param( 'brief' ) );
	$site_summary = trim( (string) $request->get_param( 'site_summary' ) );
	$media_ids    = (array) $request->get_param( 'media_ids' );
	$images       = (array) $request->get_param( 'images' );
	$model        = (string) $request->get_param( 'model' );
	$temperature  = (float) $request->get_param( 'temperature' );

	if ( '' === $brief ) {
		return new WP_Error( 'empty_brief', __( 'The section brief is empty.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$prompt = '';
	if ( '' !== $site_summary ) {
		$prompt .= "SITE OVERVIEW (shared direction for every section of this site):\n" . $site_summary . "\n\n";
	}
	$prompt .= "BUILD THIS:\n" . $brief;
	if ( ! empty( $images ) ) {
		$prompt .= "\n\nThe attached screenshot(s) show the design the owner wants — match this section's layout, colors, and typography to the corresponding part of them.";
	}
	if ( function_exists( 'ekwa_media_manifest_prompt_for_ids' ) ) {
		$prompt .= ekwa_media_manifest_prompt_for_ids( $media_ids );
	}

	// Delegate to the AI Block Builder handler with a hand-built request. Its
	// registered arg defaults do NOT apply here, so every param is set explicitly.
	// The delegate re-validates the screenshots (count + mime).
	$inner = new WP_REST_Request( 'POST', '/ekwa/v1/ai-generate-blocks' );
	$inner->set_param( 'prompt', $prompt );
	$inner->set_param( 'images', $images );
	$inner->set_param( 'history', array() );
	$inner->set_param( 'use_child_css', true );
	$inner->set_param( 'temperature', $temperature > 0 ? $temperature : 0.3 );
	$inner->set_param( 'model', $model );
	$inner->set_param( 'context', $context );
	$inner->set_param( 'mode', 'create' );
	$inner->set_param( 'base_markup', '' );
	$inner->set_param( 'base_css', '' );

	// Usage is logged inside the delegate under its own 'blocks' feature label.
	return ekwa_ai_generate_blocks_handle_request( $inner );
}

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 3 — APPLY
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Handle POST /ekwa/v1/ai-site-apply.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_site_apply_handler( $request ) {
	$target    = (string) $request->get_param( 'target' );
	$markup    = trim( (string) $request->get_param( 'markup' ) );
	$title     = (string) $request->get_param( 'title' );
	$slug      = (string) $request->get_param( 'slug' );
	$overwrite = (bool) $request->get_param( 'overwrite' );

	if ( '' === $markup ) {
		return new WP_Error( 'empty_markup', __( 'Nothing to apply — the markup is empty.', 'ekwa' ), array( 'status' => 400 ) );
	}

	switch ( $target ) {
		case 'header':
		case 'footer':
			return ekwa_ai_site_apply_template_part( $target, $markup, $overwrite );
		case 'home':
			return ekwa_ai_site_apply_home( $markup, $title, $overwrite );
		case 'inner':
			return ekwa_ai_site_apply_inner( $markup, $title, $slug );
	}

	return new WP_Error( 'bad_target', __( 'Unknown apply target.', 'ekwa' ), array( 'status' => 400 ) );
}

/**
 * Save generated markup as the active theme's header/footer template part.
 *
 * A wp_template_part post with the matching slug + wp_theme term overrides the
 * theme's parts/{slug}.html file. Deleting the part in the Site Editor reverts
 * to the file version — that is the undo path.
 *
 * @param string $slug      'header' or 'footer'.
 * @param string $markup    Block markup.
 * @param bool   $overwrite Replace an existing customized part.
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_site_apply_template_part( $slug, $markup, $overwrite ) {
	$theme    = get_stylesheet();
	$existing = get_posts( array(
		'post_type'      => 'wp_template_part',
		'post_status'    => array( 'publish', 'draft' ),
		'name'           => $slug,
		'posts_per_page' => 1,
		'tax_query'      => array(
			array(
				'taxonomy' => 'wp_theme',
				'field'    => 'name',
				'terms'    => $theme,
			),
		),
	) );

	if ( ! empty( $existing ) && ! $overwrite ) {
		return rest_ensure_response( array(
			'needs_confirmation' => true,
			/* translators: %s: template part slug. */
			'message'            => sprintf( __( 'A customized "%s" template part already exists (it may contain manual edits). Overwrite it?', 'ekwa' ), $slug ),
		) );
	}

	if ( ! empty( $existing ) ) {
		$post_id = wp_update_post( array(
			'ID'           => $existing[0]->ID,
			'post_content' => $markup,
			'post_status'  => 'publish',
		), true );
	} else {
		$post_id = wp_insert_post( array(
			'post_type'    => 'wp_template_part',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => ucfirst( $slug ),
			'post_content' => $markup,
		), true );
	}

	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'apply_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
	}

	wp_set_object_terms( $post_id, $theme, 'wp_theme' );
	wp_set_object_terms( $post_id, $slug, 'wp_template_part_area' );
	update_post_meta( $post_id, '_ekwa_ai_generated', current_time( 'mysql' ) );

	return rest_ensure_response( array(
		'applied'  => true,
		'post_id'  => $post_id,
		/* translators: %s: template part slug. */
		'message'  => sprintf( __( 'Saved as the site %s. To revert, delete this part in the Site Editor (the theme file version returns).', 'ekwa' ), $slug ),
		'edit_url' => admin_url( 'site-editor.php?postType=wp_template_part&postId=' . rawurlencode( $theme . '//' . $slug ) . '&canvas=edit' ),
	) );
}

/**
 * Save generated markup as the static front page.
 *
 * @param string $markup    Block markup (all home sections concatenated).
 * @param string $title     Page title (defaults to "Home").
 * @param bool   $overwrite Replace the current front page's content.
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_site_apply_home( $markup, $title, $overwrite ) {
	$front_id = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;
	$front    = $front_id ? get_post( $front_id ) : null;

	if ( $front && ! $overwrite ) {
		return rest_ensure_response( array(
			'needs_confirmation' => true,
			/* translators: %s: current front page title. */
			'message'            => sprintf( __( 'Your site already has a front page ("%s"). Replace its content?', 'ekwa' ), $front->post_title ),
		) );
	}

	if ( $front ) {
		$page_id = wp_update_post( array(
			'ID'           => $front->ID,
			'post_content' => $markup,
			'post_status'  => 'publish',
		), true );
	} else {
		$page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => '' !== trim( $title ) ? $title : __( 'Home', 'ekwa' ),
			'post_content' => $markup,
		), true );
	}

	if ( is_wp_error( $page_id ) ) {
		return new WP_Error( 'apply_failed', $page_id->get_error_message(), array( 'status' => 500 ) );
	}

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $page_id );
	update_post_meta( $page_id, '_ekwa_ai_generated', current_time( 'mysql' ) );

	return rest_ensure_response( array(
		'applied'  => true,
		'post_id'  => (int) $page_id,
		'message'  => __( 'Front page saved and set as the site homepage.', 'ekwa' ),
		'view_url' => get_permalink( $page_id ),
		'edit_url' => get_edit_post_link( $page_id, 'raw' ),
	) );
}

/**
 * Save generated markup as a new inner page (never overwrites — WordPress
 * uniquifies the slug if it already exists).
 *
 * @param string $markup Block markup (all inner sections concatenated).
 * @param string $title  Page title.
 * @param string $slug   Desired slug.
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_site_apply_inner( $markup, $title, $slug ) {
	$page_id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => '' !== trim( $title ) ? $title : __( 'New Page', 'ekwa' ),
		'post_name'    => $slug,
		'post_content' => $markup,
	), true );

	if ( is_wp_error( $page_id ) ) {
		return new WP_Error( 'apply_failed', $page_id->get_error_message(), array( 'status' => 500 ) );
	}

	update_post_meta( $page_id, '_ekwa_ai_generated', current_time( 'mysql' ) );

	return rest_ensure_response( array(
		'applied'  => true,
		'post_id'  => (int) $page_id,
		'message'  => __( 'Inner page created and published.', 'ekwa' ),
		'view_url' => get_permalink( $page_id ),
		'edit_url' => get_edit_post_link( $page_id, 'raw' ),
	) );
}
