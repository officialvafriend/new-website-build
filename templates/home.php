<?php
/**
 * 홈 — 데모(design/demo.html)의 구조를 실제 상품으로 그린다.
 *
 * 레퍼런스 순서: 검색 → 히어로 2장 → 오늘의 특가(카운트다운) → 지금 고르세요(필터+그리드)
 * → 브랜드 공식 스토어 → 배너 → 푸터.
 *
 * @package DuckhooRedesign
 */

use function Duckhoo\Redesign\Front\{products, cat_by_name, card, split_name, per_bottle, brands, icon, header_html, tabbar_html, footer_html};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sale_cat  = cat_by_name( '특가' );
$nonic_cat = cat_by_name( '무니코틴' );
$rank_cat  = cat_by_name( '랭킹' );
$shop_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );

$deals  = $sale_cat ? products( array( 'category' => array( $sale_cat->slug ), 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) ) : products( array( 'include' => wc_get_product_ids_on_sale(), 'limit' => 10 ) );
$newest = products( array( 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC', 'stock_status' => 'instock' ) );
// 히어로는 "신상 입고" 다. 액상 분류에서, 묶음 이벤트가 아닌 새 단품을 고른다. 팟·기기·결제용 상품은 아니다.
$hero      = null;
$liquid    = array_values( array_filter( array_map( fn( $n ) => cat_by_name( $n ), array( '입호흡', '폐호흡', '무니코틴', '노보' ) ) ) );
$hero_args = array( 'limit' => 24, 'orderby' => 'date', 'order' => 'DESC', 'stock_status' => 'instock' );
if ( $liquid ) {
	$hero_args['category'] = array_map( fn( $t ) => $t->slug, $liquid );
}
foreach ( products( $hero_args ) as $p ) {
	if ( $p->get_image_id() && ! preg_match( '/이벤트|묶음|세트|기획|할인|증정|결제|드립팁|첨가제|\d\s*\+\s*\d/u', $p->get_name() ) ) {
		$hero = $p;
		break;
	}
}
if ( ! $hero ) {
	foreach ( $newest as $p ) {
		if ( $p->get_image_id() ) {
			$hero = $p;
			break;
		}
	}
}
$grid   = $rank_cat ? products( array( 'category' => array( $rank_cat->slug ), 'limit' => 12 ) ) : array();
if ( count( $grid ) < 12 ) {
	$grid = array_merge( $grid, products( array( 'limit' => 12 - count( $grid ), 'orderby' => 'popularity', 'exclude' => array_map( fn( $p ) => $p->get_id(), $grid ) ) ) );
}
$nonic  = $nonic_cat ? products( array( 'category' => array( $nonic_cat->slug ), 'limit' => 8, 'orderby' => 'popularity' ) ) : array();
$brand_list = array_slice( array_keys( brands() ), 0, 3 );

// 특가 중 할인율이 가장 큰 것 — 오른쪽 히어로 카드 문구에 쓴다
$best_off = 0;
foreach ( $deals as $p ) {
	$r = (float) $p->get_regular_price();
	$s = (float) $p->get_price();
	if ( $r > 0 && $s < $r ) {
		$best_off = max( $best_off, (int) round( ( 1 - $s / $r ) * 100 ) );
	}
}
$month = (int) wp_date( 'n' );
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
<div class="wrap">

	<a class="msearch" href="<?php echo esc_url( add_query_arg( array( 's' => '', 'post_type' => 'product' ), home_url( '/' ) ) ); ?>"><?php echo icon( 'search' ); // phpcs:ignore ?><span>‘샤인머스캣’ 처럼 찾아보세요</span></a>

	<section class="hero2">
		<?php if ( $hero ) : $hn = split_name( $hero ); $hp = per_bottle( $hero ); ?>
		<a class="hcard hcard-a" href="<?php echo esc_url( get_permalink( $hero->get_id() ) ); ?>">
			<div class="htxt"><span class="eb2">신상 입고</span><h1><?php echo esc_html( $hn['title'] ); ?></h1>
				<p><?php echo esc_html( $hn['brand'] ? $hn['brand'] . ' · ' : '' ); ?>병당 <?php echo esc_html( number_format_i18n( $hp['per'] ) ); ?>원<?php echo $hp['qty'] > 1 ? ' · ' . (int) $hp['qty'] . '병 구성' : ''; ?></p>
				<span class="btn btn-d">상품 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></span></div>
			<div class="hart"><span class="box"><?php echo $hero->get_image( 'woocommerce_single' ); // phpcs:ignore ?></span></div>
		</a>
		<?php endif; ?>
		<a class="hcard hcard-b" href="<?php echo esc_url( $sale_cat ? get_term_link( $sale_cat ) : $shop_url ); ?>">
			<div class="htxt"><h2><?php echo (int) $month; ?>월 특가<br><?php echo $best_off ? '최대 <em>' . (int) $best_off . '%</em>' : '<em>묶음 할인</em>'; ?></h2>
				<p><?php echo (int) $month; ?>월 말까지. 묶음으로 담을수록 병당 가격이 내려갑니다.</p>
				<span class="btn btn-w2">특가 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></span></div>
		</a>
	</section>

	<?php if ( $deals ) : ?>
	<section class="deals"><div class="deals-h"><h2>오늘의 특가</h2>
		<span class="ends">마감까지 <b id="dhr-left" class="n">—</b></span></div>
		<div class="scroller"><?php foreach ( $deals as $p ) { echo card( $p ); } // phpcs:ignore ?></div></section>
	<?php endif; ?>

	<section class="sec center"><h2 class="big2">지금 고르세요</h2>
		<p class="lead">브랜드 · 니코틴 · 맛으로 좁혀 보세요. 병당 가격으로 비교합니다.</p>
		<div class="fbar">
			<label class="fsel"><span>브랜드</span><?php echo icon( 'chev' ); // phpcs:ignore ?>
				<select data-go aria-label="브랜드"><option value="">브랜드 전체</option>
				<?php foreach ( array_slice( array_keys( brands() ), 0, 12 ) as $b ) : ?><option value="<?php echo esc_url( add_query_arg( array( 's' => '[' . $b . ']', 'post_type' => 'product' ), home_url( '/' ) ) ); ?>"><?php echo esc_html( $b ); ?></option><?php endforeach; ?>
				</select></label>
			<label class="fsel"><span>니코틴</span><?php echo icon( 'chev' ); // phpcs:ignore ?>
				<select data-go aria-label="니코틴"><option value="">니코틴 전체</option>
				<?php if ( $nonic_cat ) : ?><option value="<?php echo esc_url( get_term_link( $nonic_cat ) ); ?>">무니코틴</option><?php endif; ?>
				<option value="<?php echo esc_url( add_query_arg( array( 's' => '3mg', 'post_type' => 'product' ), home_url( '/' ) ) ); ?>">3mg</option>
				<option value="<?php echo esc_url( add_query_arg( array( 's' => '9.8mg', 'post_type' => 'product' ), home_url( '/' ) ) ); ?>">9.8mg</option>
				</select></label>
			<label class="fsel"><span>맛</span><?php echo icon( 'chev' ); // phpcs:ignore ?>
				<select data-go aria-label="맛"><option value="">맛 전체</option>
				<?php foreach ( array( '멘솔', '포도', '딸기', '망고', '복숭아', '사과', '레몬', '커피' ) as $f ) : ?><option value="<?php echo esc_url( add_query_arg( array( 's' => $f, 'post_type' => 'product' ), home_url( '/' ) ) ); ?>"><?php echo esc_html( $f ); ?></option><?php endforeach; ?>
				</select></label>
		</div>
		<div class="grid grid4"><?php foreach ( $grid as $p ) { echo card( $p ); } // phpcs:ignore ?></div>
		<div class="center" style="margin-top:1.4rem"><a class="btn btn-d" href="<?php echo esc_url( $shop_url ); ?>">전체 상품 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></a></div></section>

	<?php if ( $nonic ) : ?>
	<section class="sec"><div class="sec-h"><h2>무니코틴</h2><span class="sub">가장 많이 찾는 분류</span>
		<a class="lk" href="<?php echo esc_url( get_term_link( $nonic_cat ) ); ?>">전체 보기 <?php echo icon( 'chev' ); // phpcs:ignore ?></a></div>
		<div class="scroller"><?php foreach ( $nonic as $p ) { echo card( $p ); } // phpcs:ignore ?></div></section>
	<?php endif; ?>

	<?php if ( $brand_list ) : ?>
	<section class="sec center"><h2 class="big2">브랜드 공식 스토어</h2>
		<p class="lead">만든 곳에서 바로 받아 옵니다.</p>
		<div class="bgrid"><?php foreach ( $brand_list as $b ) :
			$bp = products( array( 's' => '[' . $b . ']', 'limit' => 4, 'orderby' => 'popularity' ) );
			if ( ! $bp ) { continue; }
			$bcount = brands()[ $b ] ?? count( $bp );
			$burl = add_query_arg( array( 's' => '[' . $b . ']', 'post_type' => 'product' ), home_url( '/' ) ); ?>
			<article class="bcard"><div class="bhead"><span class="blogo"><?php echo esc_html( mb_substr( $b, 0, 1 ) ); ?></span>
				<div><b><?php echo esc_html( $b ); ?> <span class="vf" title="공식 스토어"><?php echo icon( 'check' ); // phpcs:ignore ?></span></b>
					<span class="bsub"><?php echo (int) $bcount; ?>종</span></div>
				<a class="lk" href="<?php echo esc_url( $burl ); ?>">보기 <?php echo icon( 'chev' ); // phpcs:ignore ?></a></div>
				<div class="bthumbs"><?php foreach ( $bp as $p ) : ?><a href="<?php echo esc_url( get_permalink( $p->get_id() ) ); ?>" aria-label="<?php echo esc_attr( $p->get_name() ); ?>"><?php echo $p->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore ?></a><?php endforeach; ?></div></article>
		<?php endforeach; ?></div></section>
	<?php endif; ?>

	<section class="banner"><div><h2><?php echo (int) $month; ?>월엔 병당 가격으로 고르세요</h2>
		<p>한 병만 사도 되고, 묶으면 병당 가격이 내려갑니다. 입금자명만 주문자명과 같게 넣어주세요 — 그러면 자동으로 입금확인됩니다.</p>
		<a class="btn btn-w2" href="<?php echo esc_url( $sale_cat ? get_term_link( $sale_cat ) : $shop_url ); ?>">특가 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></a></div></section>

</div>
</main>
<?php footer_html(); ?>
<?php tabbar_html(); ?>
<?php wp_footer(); ?>
</body>
</html>
