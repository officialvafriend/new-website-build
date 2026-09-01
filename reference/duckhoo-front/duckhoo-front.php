<?php
/**
 * Plugin Name: 액상덕후 프론트 컴포넌트
 * Description: React + Tailwind로 빌드한 프론트 컴포넌트 묶음. 테마와 독립적으로 동작하므로 테마를 교체해도 그대로 남습니다.
 * Version:     1.0.0
 * Author:      액상덕후
 * Text Domain: duckhoo-front
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DHF_DIR', plugin_dir_path( __FILE__ ) );
define( 'DHF_URL', plugin_dir_url( __FILE__ ) );

/**
 * 번들은 실제로 컴포넌트가 쓰인 화면에서만 불러온다.
 * 숏코드가 쓰이면 그때 등록해두고, 푸터에서 함께 출력한다.
 */
function dhf_register_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$js  = DHF_DIR . 'dist/dh-front.js';
	$css = DHF_DIR . 'dist/dh-front.css';
	if ( ! file_exists( $js ) ) {
		return;
	}
	wp_enqueue_style( 'dh-front', DHF_URL . 'dist/dh-front.css', array(), filemtime( $css ) );
	wp_enqueue_script( 'dh-front', DHF_URL . 'dist/dh-front.js', array(), filemtime( $js ), true );
	$done = true;
}

/**
 * 상품 목록을 커버플로우 캐러셀로.
 *
 * [dh_coverflow cat="8월-특가" limit="8"]
 * [dh_coverflow ids="146,152,163" label="이번 주 랭킹"]
 */
function dhf_coverflow_shortcode( $atts ) {

	if ( ! function_exists( 'wc_get_products' ) ) {
		return '';
	}

	$a = shortcode_atts(
		array(
			'cat'   => '',
			'ids'   => '',
			'limit' => 8,
			'width' => 'clamp(150px, 44vw, 230px)',
			'label' => '인기 묶음',
			'nav'   => 'no',
		),
		$atts,
		'dh_coverflow'
	);

	$args = array(
		'status' => 'publish',
		'limit'  => max( 1, min( 24, (int) $a['limit'] ) ),
	);
	if ( '' !== $a['ids'] ) {
		$args['include'] = array_filter( array_map( 'absint', explode( ',', $a['ids'] ) ) );
		$args['orderby'] = 'include';
	} elseif ( '' !== $a['cat'] ) {
		$args['category'] = array_map( 'sanitize_title', explode( ',', $a['cat'] ) );
	}

	$products = wc_get_products( $args );
	if ( empty( $products ) ) {
		return '';
	}

	$slides = array();
	foreach ( $products as $p ) {

		$img_id = $p->get_image_id();
		$src    = $img_id
			? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' )
			: wc_placeholder_img_src( 'woocommerce_thumbnail' );

		$name = wp_strip_all_tags( $p->get_name() );
		$meta = array();

		if ( preg_match( '/병당\s*([\d,]+)\s*원/u', $name, $m ) ) {
			$meta[] = array(
				'label' => '병당',
				'value' => $m[1] . '원',
			);
		}
		$price = $p->get_price();
		if ( '' !== $price ) {
			$meta[] = array(
				'label' => '총 금액',
				'value' => number_format( (float) $price ) . '원',
			);
		}
		if ( preg_match( '/(\d+)\s*병/u', $name, $m ) ) {
			$meta[] = array(
				'label' => '구성',
				'value' => $m[1] . '병',
			);
		}

		$title = trim(
			preg_replace(
				array( '/^\[[^\]]*\]\s*/u', '/\s*\/?\s*(병당|금액)[^\/]*$/u' ),
				'',
				$name
			)
		);

		$slides[] = array(
			'src'      => $src,
			'alt'      => $name,
			'title'    => '' !== $title ? $title : $name,
			'subtitle' => wp_strip_all_tags( $p->get_short_description() ),
			'href'     => get_permalink( $p->get_id() ),
			'meta'     => $meta,
		);
	}

	$props = array(
		'slides'         => $slides,
		'showCaption'    => true,
		'showPagination' => true,
		'showNavigation' => ( 'yes' === $a['nav'] ),
		'cardWidth'      => $a['width'],
		'label'          => $a['label'],
	);

	dhf_register_assets();

	return '<div data-dh="coverflow" data-props="' . esc_attr( wp_json_encode( $props ) ) . '"></div>';
}
add_shortcode( 'dh_coverflow', 'dhf_coverflow_shortcode' );

/**
 * 템플릿에서 직접 쓰고 싶을 때:  <?php dhf_coverflow( array( 'cat' => '8월-특가' ) ); ?>
 */
function dhf_coverflow( $args = array() ) {
	echo dhf_coverflow_shortcode( $args ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * 상품 상세페이지 옵션 UI.
 * 테마의 옵션 폼(PPOM + wd-option-builder)을 그대로 두고,
 * 그 위에 STEP 카드 UI를 얹는다. 장바구니 데이터 경로는 건드리지 않는다.
 */
function dhf_option_ui() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$css = DHF_DIR . 'assets/option-ui.css';
	$js  = DHF_DIR . 'assets/option-ui.js';
	if ( ! file_exists( $css ) || ! file_exists( $js ) ) {
		return;
	}
	wp_enqueue_style( 'dh-option-ui', DHF_URL . 'assets/option-ui.css', array(), filemtime( $css ) );
	wp_enqueue_script( 'dh-option-ui', DHF_URL . 'assets/option-ui.js', array( 'jquery' ), filemtime( $js ), true );
}
add_action( 'wp_enqueue_scripts', 'dhf_option_ui', 99 );

/**
 * 장바구니·결제 화면 스타일 정돈.
 */
function dhf_shop_polish() {
	if ( ! function_exists( 'is_cart' ) ) {
		return;
	}
	if ( ! is_cart() && ! is_checkout() ) {
		return;
	}
	$css = DHF_DIR . 'assets/shop-polish.css';
	if ( ! file_exists( $css ) ) {
		return;
	}
	wp_enqueue_style( 'dh-shop-polish', DHF_URL . 'assets/shop-polish.css', array(), filemtime( $css ) );
}
add_action( 'wp_enqueue_scripts', 'dhf_shop_polish', 99 );
