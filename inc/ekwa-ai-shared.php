<?php
/**
 * Shared helpers for the theme's Gemini-backed AI features.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ekwa_get_ai_api_key' ) ) {
	/**
	 * Get the Gemini API key from wp-config constant or theme option.
	 *
	 * @return string|false API key string, or false when none is configured.
	 */
	function ekwa_get_ai_api_key() {
		if ( defined( 'EKWA_GEMINI_API_KEY' ) && EKWA_GEMINI_API_KEY ) {
			return EKWA_GEMINI_API_KEY;
		}
		$key = get_option( 'ekwa_gemini_api_key', '' );
		return $key ? $key : false;
	}
}
