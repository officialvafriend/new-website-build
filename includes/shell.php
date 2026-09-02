<?php
/**
 * 껍데기 — 홈 말고 모든 화면에 우리 헤더·푸터·탭바를 씌운다.
 *
 * 테마 템플릿은 그대로 돈다 (PPOM 옵션 · 결제 폼 · 계정 · kboard · keyple 마이페이지).
 * 테마가 `wp_body_open` 과 `wp_footer` 를 부르므로 거기에 우리 헤더와 푸터를 넣고,
 * 테마 자체의 사이드 헤더 · 푸터 · 탭바 · 모바일 헤더는 CSS 로 숨긴다. 테마 파일에는
 * 손대지 않는다. 플러그인을 끄면 그대로 돌아온다.
 *
 * @package DuckhooRedesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Shell;

use function Duckhoo\Redesign\Front\header_html;
use function Duckhoo\Redesign\Front\footer_html;
use function Duckhoo\Redesign\Front\tabbar_html;
use function Duckhoo\Redesign\Front\card;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 테마 템플릿 위에 껍데기를 씌우는 화면인가. 홈과 회원탈퇴는 플러그인이 통째로 그린다.
 *
 * @return bool
 */
function wraps(): bool {
	if ( is_admin() || wp_doing_ajax() || is_embed() || is_feed() ) {
		return false;
	}
	if ( is_front_page() || is_page( 'membership-cancel' ) ) {
		return false;
	}
	return (bool) apply_filters( 'duckhoo_wrap_theme_pages', true );
}

/**
 * body 클래스. 우리 CSS 는 전부 body.dhr 아래에 있다.
 *
 * @param string[] $classes 클래스.
 * @return string[]
 */
function body_class( array $classes ): array {
	if ( wraps() ) {
		$classes[] = 'dhr';
		$classes[] = 'dhr-wrap';
	}
	return $classes;
}
add_filter( 'body_class', __NAMESPACE__ . '\\body_class' );

/**
 * 헤더 — 테마가 wp_body_open 을 부르는 자리에.
 *
 * @return void
 */
function open(): void {
	if ( ! wraps() ) {
		return;
	}
	header_html();
}
add_action( 'wp_body_open', __NAMESPACE__ . '\\open', 5 );

/**
 * 푸터 · 탭바 — 스크립트보다 먼저.
 *
 * @return void
 */
function close(): void {
	if ( ! wraps() ) {
		return;
	}
	footer_html();
	tabbar_html();
}
add_action( 'wp_footer', __NAMESPACE__ . '\\close', 5 );

/**
 * 스타일 · 스크립트.
 *
 * @return void
 */
function assets(): void {
	if ( ! wraps() ) {
		return;
	}
	$dir  = dirname( __DIR__ ) . '/';
	$base = $dir . 'duckhoo-redesign.php';
	wp_enqueue_style( 'duckhoo-front', plugins_url( 'assets/front.css', $base ), array( 'duckhoo-tokens' ), (string) filemtime( $dir . 'assets/front.css' ) );
	wp_enqueue_style( 'duckhoo-shell', plugins_url( 'assets/shell.css', $base ), array( 'duckhoo-front', 'duckhoo-theme' ), (string) filemtime( $dir . 'assets/shell.css' ) );
	wp_enqueue_script( 'duckhoo-front', plugins_url( 'assets/front.js', $base ), array(), (string) filemtime( $dir . 'assets/front.js' ), true );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\assets', 110 );

/**
 * 상품 목록의 카드를 우리 카드로. WooCommerce 는 목록에서 content-product.php 를
 * wc_get_template_part 로 부른다. 그 파일만 우리 것으로 바꾼다 — 링크 · 상품 ID 는 같다.
 *
 * @param string $template 원래 파일.
 * @param string $slug     슬러그.
 * @param string $name     이름.
 * @return string
 */
function loop_card( string $template, string $slug, string $name ): string {
	if ( 'content' === $slug && 'product' === $name && wraps() ) {
		return dirname( __DIR__ ) . '/templates/content-product.php';
	}
	return $template;
}
add_filter( 'wc_get_template_part', __NAMESPACE__ . '\\loop_card', 20, 3 );
