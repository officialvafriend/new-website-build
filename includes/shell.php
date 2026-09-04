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
	\Duckhoo\Redesign\Front\libs();
	wp_enqueue_script( 'duckhoo-front', plugins_url( 'assets/front.js', $base ), array( 'duckhoo-swiper', 'duckhoo-gsap-st' ), (string) filemtime( $dir . 'assets/front.js' ), true );
	wp_add_inline_script( 'duckhoo-front', \Duckhoo\Redesign\Front\js_config(), 'before' );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\assets', 110 );

/**
 * 옛 프론트(duckhoo-front)가 얹어 둔 껍데기 CSS 를 뺀다.
 *
 * `dh-shell` 은 body 에 파스텔 그라데이션을, `#page` 에 1240px 둥근 흰 카드와 216px 사이드
 * 내비를 그린다. 우리 헤더·푸터와 겹쳐 어색하다. `dh-shop-polish` · `dh-front` 도 같은 층이다.
 * 상품 옵션 UI(`dh-option-ui`)는 남긴다 — 그것이 PPOM select 에 값을 넣는 쪽이다.
 * 우리가 하는 건 등록된 스타일 핸들을 빼는 것뿐이다. 플러그인 파일은 건드리지 않는다.
 *
 * 사장님 Code Snippets 가 직접 찍는 `<style id="dh-cta-fix">` 는 핸들이 없어 뺄 수 없다.
 * 그건 shell.css 가 특이도로 덮는다.
 *
 * @return void
 */
function drop_legacy_shell(): void {
	if ( ! wraps() ) {
		return;
	}
	$handles = apply_filters( 'duckhoo_drop_legacy_styles', array( 'dh-shell', 'dh-shop-polish', 'dh-front' ) );
	foreach ( (array) $handles as $handle ) {
		wp_dequeue_style( (string) $handle );
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\drop_legacy_shell', 200 );

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

/**
 * 마이페이지 첫 화면을 우리 것으로. 워드커머스 기본은 안내 문단 하나뿐이다.
 *
 * @param string $template 원래 파일.
 * @param string $name     템플릿 이름.
 * @return string
 */
function account_dashboard( string $template, string $name ): string {
	if ( 'myaccount/dashboard.php' === $name && wraps() ) {
		return dirname( __DIR__ ) . '/templates/myaccount-dashboard.php';
	}
	return $template;
}
add_filter( 'wc_get_template', __NAMESPACE__ . '\\account_dashboard', 20, 2 );

/**
 * 목록 제목. 테마는 카테고리 이름을 h1 으로 찍되 1px 로 숨긴다 (접근성용). 그래서 목록만 있고
 * 어느 분류를 보는지 알 수 없다. 루프 앞에 보이는 제목 · 건수 · 분류 설명을 넣는다. 테마 h1 은 그대로 둔다.
 */
function archive_title(): void {
	if ( ! wraps() || ! function_exists( 'woocommerce_page_title' ) ) {
		return;
	}
	if ( ! ( is_shop() || is_product_taxonomy() || is_search() ) ) {
		return;
	}
	if ( apply_filters( 'duckhoo_take_archive', true ) ) {
		return; // templates/archive-product.php 가 제목을 그린다
	}
	$title = is_search() ? '“' . get_search_query() . '” 검색 결과' : (string) woocommerce_page_title( false );
	$total = function_exists( 'wc_get_loop_prop' ) ? (int) wc_get_loop_prop( 'total' ) : 0;
	$desc  = is_product_taxonomy() ? (string) term_description() : '';
	echo '<header class="dhr-arch"><h2 class="dhr-arch__t">' . esc_html( $title ) . '</h2>';
	if ( $total > 0 ) {
		echo '<span class="dhr-arch__n">' . esc_html( number_format_i18n( $total ) ) . '종</span>';
	}
	if ( '' !== trim( wp_strip_all_tags( $desc ) ) ) {
		echo '<div class="dhr-arch__d">' . wp_kses_post( $desc ) . '</div>';
	}
	echo '</header>';
}
add_action( 'woocommerce_before_shop_loop', __NAMESPACE__ . '\\archive_title', 5 );
