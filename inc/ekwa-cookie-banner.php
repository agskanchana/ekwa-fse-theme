<?php
/**
 * Cookie consent banner.
 *
 * Renders a small dismissible card at the bottom of every page until the
 * visitor clicks "Got it" — sets a 360-day `cookie_banner=true` cookie that
 * suppresses the banner on subsequent visits. The markup is always emitted
 * (cache-friendly); JS checks the cookie client-side and reveals the card
 * only when consent has not been given.
 *
 * Filters:
 *   - ekwa_cookie_banner_enabled       (bool)   – disable entirely
 *   - ekwa_cookie_banner_message       (string) – HTML allowed via wp_kses_post
 *   - ekwa_cookie_banner_button_label  (string) – plain text
 *   - ekwa_cookie_banner_policy_url    (string) – defaults to /cookie-policy/
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', 'ekwa_cookie_banner_output', 50 );

function ekwa_cookie_banner_output() {
	if ( ! apply_filters( 'ekwa_cookie_banner_enabled', true ) ) {
		return;
	}

	$policy_url = apply_filters( 'ekwa_cookie_banner_policy_url', home_url( '/cookie-policy/' ) );
	$default_msg = sprintf(
		'We use cookies to enhance your experience. By continuing to use our website, you consent to our use of <a href="%s">cookies</a>.',
		esc_url( $policy_url )
	);
	$message = apply_filters( 'ekwa_cookie_banner_message', $default_msg );
	$button  = apply_filters( 'ekwa_cookie_banner_button_label', 'Got it' );
	?>
<div id="ekwa-cookie-banner" class="ekwa-cookie-banner" role="dialog" aria-live="polite" aria-label="Cookie consent" hidden>
	<div class="ekwa-cookie-banner__inner">
		<svg class="ekwa-cookie-banner__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-4-4 4 4 0 0 1-4-4 4 4 0 0 1-2-2zm-3 7a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 9 9zm6 2a1 1 0 1 1-1 1 1 1 0 0 1 1-1zm-7.5 4A1.5 1.5 0 1 1 6 16.5 1.5 1.5 0 0 1 7.5 15zm6.5 1a1 1 0 1 1-1 1 1 1 0 0 1 1-1z"/></svg>
		<p class="ekwa-cookie-banner__msg"><?php echo wp_kses_post( $message ); ?></p>
		<button type="button" class="ekwa-cookie-banner__btn" id="ekwa-cookie-accept"><?php echo esc_html( $button ); ?></button>
	</div>
</div>
<style id="ekwa-cookie-banner-style">
.ekwa-cookie-banner{position:fixed;left:24px;bottom:24px;max-width:440px;width:calc(100% - 48px);background:#fff;color:#1f2937;border:1px solid rgba(15,23,42,.06);border-radius:16px;box-shadow:0 22px 60px -12px rgba(15,23,42,.22),0 4px 12px rgba(15,23,42,.06);padding:18px 20px;z-index:100000;font:14px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;transform:translateX(-24px);opacity:0;transition:transform .4s cubic-bezier(.2,.8,.2,1),opacity .35s ease;}
.ekwa-cookie-banner.is-visible{transform:translateX(0);opacity:1;}
.ekwa-cookie-banner__inner{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.ekwa-cookie-banner__icon{flex:0 0 auto;width:28px;height:28px;color:#b8741a;}
.ekwa-cookie-banner__msg{margin:0;flex:1 1 200px;color:#374151;font-size:14px;}
.ekwa-cookie-banner__msg a{color:#111827;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px;font-weight:600;}
.ekwa-cookie-banner__msg a:hover{text-decoration-thickness:2px;}
.ekwa-cookie-banner__btn{flex:0 0 auto;padding:9px 22px;background:#111827;color:#fff;border:0;border-radius:999px;font:inherit;font-weight:600;font-size:13px;letter-spacing:.01em;cursor:pointer;transition:background .2s ease,transform .15s ease,box-shadow .2s ease;}
.ekwa-cookie-banner__btn:hover{background:#1f2937;box-shadow:0 6px 16px -4px rgba(15,23,42,.35);transform:translateY(-1px);}
.ekwa-cookie-banner__btn:focus-visible{outline:2px solid #4c8df6;outline-offset:2px;}
.ekwa-cookie-banner__btn:active{transform:translateY(0) scale(.97);}
@media (max-width:991px){
	.ekwa-cookie-banner{left:12px;bottom:90px;width:230px;max-width:230px;padding:12px 14px;border-radius:14px;gap:8px;}
	.ekwa-cookie-banner__inner{gap:10px;}
	.ekwa-cookie-banner__icon{width:22px;height:22px;}
	.ekwa-cookie-banner__msg{font-size:12px;line-height:1.45;flex:1 1 100%;}
	.ekwa-cookie-banner__btn{padding:7px 16px;font-size:12px;flex:0 0 auto;align-self:flex-start;}
}
@media (prefers-reduced-motion:reduce){
	.ekwa-cookie-banner{transition:opacity .15s ease;transform:none;}
}
</style>
<script id="ekwa-cookie-banner-script">
(function(){
	var KEY='cookie_banner';
	function getCookie(n){
		var m=document.cookie.match('(?:^|;\\s*)'+n+'=([^;]+)');
		return m?decodeURIComponent(m[1]):'';
	}
	function setCookie(n,v,days){
		var d=new Date();
		d.setTime(d.getTime()+days*24*60*60*1000);
		document.cookie=n+'='+encodeURIComponent(v)+'; expires='+d.toUTCString()+'; path=/; SameSite=Lax';
	}
	if(getCookie(KEY)==='true')return;
	var el=document.getElementById('ekwa-cookie-banner');
	if(!el)return;
	el.hidden=false;
	// Force reflow so the transition fires from the initial transformed state.
	void el.offsetWidth;
	el.classList.add('is-visible');
	var btn=document.getElementById('ekwa-cookie-accept');
	if(btn){
		btn.addEventListener('click',function(){
			setCookie(KEY,'true',360);
			el.classList.remove('is-visible');
			setTimeout(function(){el.hidden=true;},400);
		});
	}
})();
</script>
	<?php
}
