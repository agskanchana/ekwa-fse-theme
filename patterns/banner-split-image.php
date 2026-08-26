<?php
/**
 * Title: Banner — Split (Text + Featured Image)
 * Slug: ekwa/banner-split-image
 * Categories: ekwa-banners
 * Description: Title and breadcrumb on the left, the featured image as a real Featured Image block on the right — no background layer, so the photo keeps its aspect ratio instead of being cropped.
 *
 * @package ekwa
 */

?>
<!-- wp:ekwa/page-banner {"align":"full","bgSource":"none","backgroundColor":"primary","textColor":"white","contentWidth":"full","className":"is-split is-style-left","style":{"spacing":{"padding":{"top":"0","bottom":"0","left":"0","right":"0"}}},"scopedCss":".ekwa-page-banner.is-split .ekwa-page-banner__content{display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:0}\n.ekwa-page-banner.is-split .is-split__text{padding:clamp(24px, 5vw, 64px)}\n.ekwa-page-banner.is-split .wp-block-post-featured-image{margin:0;height:100%}\n.ekwa-page-banner.is-split .wp-block-post-featured-image img{width:100%;height:100%;object-fit:cover;display:block}\n@media (max-width:781px){.ekwa-page-banner.is-split .ekwa-page-banner__content{grid-template-columns:1fr}.ekwa-page-banner.is-split .wp-block-post-featured-image{order:-1;max-height:220px}}"} -->
<!-- wp:ekwa/div {"tagName":"div","className":"is-split__text"} -->
<!-- wp:ekwa/banner-title {"style":{"typography":{"fontSize":"clamp(1.5rem, 3.4vw, 2.5rem)","fontWeight":"700","lineHeight":"1.2"}}} /-->
<!-- wp:ekwa/breadcrumb {"separator":"›","style":{"spacing":{"margin":{"top":"10px"}}}} /-->
<!-- /wp:ekwa/div -->

<!-- wp:post-featured-image {"isLink":false} /-->
<!-- /wp:ekwa/page-banner -->
