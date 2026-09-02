<?php
/**
 * 회원탈퇴 — 우리 껍데기 + includes/membership-cancel.php 의 폼.
 *
 * @package DuckhooRedesign
 */

use function Duckhoo\Redesign\Front\{header_html, tabbar_html, footer_html};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.min.css">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'dhr' ); ?>>
<?php wp_body_open(); ?>
<?php header_html(); ?>
<main id="content" class="dhr-main">
<div class="wrap" style="padding-top:1rem">
<?php echo \Duckhoo\Redesign\MembershipCancel\render(); // phpcs:ignore ?>
</div>
</main>
<?php footer_html(); ?>
<?php tabbar_html(); ?>
<?php wp_footer(); ?>
</body>
</html>
