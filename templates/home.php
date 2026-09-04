<?php
/**
 * 홈 — 데모(design/demo.html)의 구조를 실제 상품으로 그린다.
 *
 * 레퍼런스 순서: 검색 → 히어로 2장 → 오늘의 특가(카운트다운) → 지금 고르세요(필터+그리드)
 * → 브랜드로 둘러보기 → 배너 → 푸터.
 *
 * @package DuckhooRedesign
 */

use function Duckhoo\Redesign\Front\{products, cat_by_name, card, split_name, per_bottle, brands, featured_brands, icon, header_html, tabbar_html, footer_html, short_cat, carousel, section_head};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sale_cat  = cat_by_name( '특가' );
$nonic_cat = cat_by_name( '무니코틴' );
$rank_cat  = cat_by_name( '랭킹' );
$shop_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );

$deals  = $sale_cat ? products( array( 'category' => array( $sale_cat->slug ), 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) ) : products( array( 'include' => wc_get_product_ids_on_sale(), 'limit' => 10 ) );
$newest = products( array( 'limit' => 8, 'orderby' => 'date', 'order' => 'DESC', 'stock_status' => 'instock' ) );
// 병당 1만 원 아래 — 묶음 중에서 병당 가격이 가장 낮은 것. 이 가게에서 고객이 실제로 비교하는 숫자다.
$cheap = array_filter( products( array( 'limit' => 40, 'orderby' => 'popularity', 'stock_status' => 'instock' ) ), function ( $p ) {
	$b = per_bottle( $p );
	return $b['qty'] > 1 && $b['per'] <= 10000;
} );
usort( $cheap, fn( $a, $b ) => per_bottle( $a )['per'] <=> per_bottle( $b )['per'] );
$cheap = array_slice( array_values( $cheap ), 0, 10 );
$brand_names = featured_brands( 12 );
// 히어로는 묶음 상품을 넘겨 본다. 묶음이 이 가게의 주력이고, 병당 가격이 내려가는 게
// 첫 화면에서 보여야 할 이야기다. 사진이 있고 재고가 있는 것만, 최대 5장.
$heroes    = array();
$seen_ids  = array();
$hero_pick = function ( array $list ) use ( &$heroes, &$seen_ids ) {
	foreach ( $list as $p ) {
		if ( count( $heroes ) >= 5 ) {
			return;
		}
		$id = $p->get_id();
		if ( isset( $seen_ids[ $id ] ) || ! $p->get_image_id() || ! $p->is_in_stock() ) {
			continue;
		}
		// 결제용·부속 상품은 히어로가 아니다
		if ( preg_match( '/결제|드립팁|첨가제|코일|팟\b/u', $p->get_name() ) ) {
			continue;
		}
		$seen_ids[ $id ] = true;
		$heroes[]        = $p;
	}
};
// 1순위: 특가 분류의 묶음, 2순위: 이름이 묶음인 것, 3순위: 최신
if ( $sale_cat ) {
	$hero_pick( products( array( 'category' => array( $sale_cat->slug ), 'limit' => 12, 'orderby' => 'popularity' ) ) );
}
$hero_pick( array_filter( products( array( 'limit' => 24, 'orderby' => 'popularity' ) ), fn( $p ) => (bool) preg_match( '/묶음|세트|\d+\s*병|\d\s*\+\s*\d/u', $p->get_name() ) ) );
$hero_pick( $newest );
$hero = $heroes[0] ?? null;

$grid   = $rank_cat ? products( array( 'category' => array( $rank_cat->slug ), 'limit' => 12 ) ) : array();
if ( count( $grid ) < 12 ) {
	$grid = array_merge( $grid, products( array( 'limit' => 12 - count( $grid ), 'orderby' => 'popularity', 'exclude' => array_map( fn( $p ) => $p->get_id(), $grid ) ) ) );
}
// 주력 브랜드 — 노보 · 디오리퀴드 · 화이트아웃 · 펠릭스 · 액상덕후 (필터 duckhoo_featured_brands).
// 브랜드 분류(taxonomy)가 없어서 이름 앞 [브랜드] 로 찾는다.
$featured   = featured_brands( 5 );
$brand_list = array_slice( $featured, 0, 3 );
$picks      = array();
$seen_pick  = array();
foreach ( $featured as $fb ) {
	foreach ( products( array( 's' => '[' . $fb . ']', 'limit' => 3, 'orderby' => 'popularity', 'stock_status' => 'instock' ) ) as $fp ) {
		if ( ! isset( $seen_pick[ $fp->get_id() ] ) ) {
			$seen_pick[ $fp->get_id() ] = true;
			$picks[]                    = $fp;
		}
	}
}

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
		<?php if ( $heroes ) : ?>
		<div class="hslide" data-hero aria-roledescription="캐러셀" aria-label="추천 묶음 상품">
			<div class="hviewport"><div class="hslide-track">
			<?php foreach ( $heroes as $i => $hp_item ) :
				$hn = split_name( $hp_item );
				$hp = per_bottle( $hp_item );
				$hr = (float) $hp_item->get_regular_price();
				$hs = (float) $hp_item->get_price(); ?>
				<a class="hcard hcard-a hslide-item" href="<?php echo esc_url( get_permalink( $hp_item->get_id() ) ); ?>"
					role="group" aria-roledescription="슬라이드" aria-label="<?php echo esc_attr( ( $i + 1 ) . ' / ' . count( $heroes ) ); ?>"
					<?php echo $i ? 'aria-hidden="true" tabindex="-1"' : ''; ?>>
					<div class="htxt"><span class="eb2"><?php echo esc_html( $hp_item->is_on_sale() ? '묶음 특가' : '추천 묶음' ); ?></span>
						<h1><?php echo esc_html( $hn['title'] ); ?></h1>
						<p class="hprice"><?php if ( $hr > $hs ) : ?><s><?php echo esc_html( number_format_i18n( $hr ) ); ?>원</s><?php endif; ?>
							<b><?php echo esc_html( number_format_i18n( $hs ) ); ?>원</b></p>
						<p><?php echo esc_html( $hn['brand'] ? $hn['brand'] . ' · ' : '' ); ?><?php echo $hp['qty'] > 1 ? esc_html( $hp['qty'] . '병 · 병당 ' . number_format_i18n( $hp['per'] ) . '원' ) : '단품'; ?></p>
						<span class="btn btn-d">상품 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></span></div>
					<div class="hart"><span class="box"><?php echo $hp_item->get_image( 'woocommerce_single' ); // phpcs:ignore ?></span></div>
				</a>
			<?php endforeach; ?>
			</div></div>
			<?php if ( count( $heroes ) > 1 ) : ?>
			<div class="hctl">
				<button type="button" class="hnav hprev" aria-label="이전 상품"><?php echo icon( 'chev' ); // phpcs:ignore ?></button>
				<div class="hdots" role="tablist" aria-label="상품 선택">
					<?php foreach ( $heroes as $i => $unused ) : ?>
					<button type="button" role="tab" class="hdot<?php echo $i ? '' : ' on'; ?>" aria-label="<?php echo esc_attr( ( $i + 1 ) . '번째 상품' ); ?>" aria-selected="<?php echo $i ? 'false' : 'true'; ?>"></button>
					<?php endforeach; ?>
				</div>
				<button type="button" class="hnav hnext" aria-label="다음 상품"><?php echo icon( 'chev' ); // phpcs:ignore ?></button>
				<button type="button" class="hplay" aria-label="자동 넘김 멈춤" data-playing="1"></button>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<a class="hcard hcard-b" href="<?php echo esc_url( $sale_cat ? get_term_link( $sale_cat ) : $shop_url ); ?>">
			<div class="htxt"><h2><?php echo (int) $month; ?>월 특가<br><?php echo $best_off ? '최대 <em>' . (int) $best_off . '%</em>' : '<em>묶음 할인</em>'; ?></h2>
				<p><?php echo (int) $month; ?>월 말까지. 묶음으로 담을수록 병당 가격이 내려갑니다.</p>
				<span class="btn btn-w2">특가 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></span></div>
		</a>
	</section>

	<?php
	// 둘러보기 — 분류로 바로 가는 둥근 타일. 분류 사진이 있으면 쓰고 없으면 첫 글자.
	$qc = array_values( array_filter( array( $sale_cat, cat_by_name( '입호흡' ), cat_by_name( '폐호흡' ), $nonic_cat, cat_by_name( '기기' ), $rank_cat ) ) );
	if ( $qc ) : ?>
	<nav class="qcats" aria-label="분류 바로 가기">
		<?php foreach ( $qc as $c ) :
			$tid = (int) get_term_meta( $c->term_id, 'thumbnail_id', true );
			$lbl = short_cat( $c->name );
			$ini = mb_substr( preg_replace( '/^이달\s*/u', '', $lbl ), 0, 1 ); ?>
		<a href="<?php echo esc_url( get_term_link( $c ) ); ?>">
			<span class="qcats__ic"><?php echo $tid ? wp_get_attachment_image( $tid, 'woocommerce_gallery_thumbnail' ) : '<b>' . esc_html( $ini ) . '</b>'; // phpcs:ignore ?></span>
			<span><?php echo esc_html( $lbl ); ?></span>
		</a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<?php if ( $deals ) : ?>
	<section class="deals"><div class="deals-h"><div><p class="sh-eb"><i></i><?php echo (int) $month; ?>월 특가</p><h2>오늘의 특가</h2></div>
		<span class="ends">마감까지 <b id="dhr-left" class="n">—</b></span></div>
		<?php carousel( $deals, '오늘의 특가' ); ?></section>
	<?php endif; ?>

	<section class="sec">
		<?php section_head( '많이 찾는 순', '지금 고르세요', '이번 주 가장 많이 담긴 상품부터', $shop_url ); ?>
		<div class="grid grid4"><?php foreach ( $grid as $p ) { echo card( $p ); } // phpcs:ignore ?></div>
		<div class="center" style="margin-top:1.4rem"><a class="btn btn-d" href="<?php echo esc_url( $shop_url ); ?>">전체 상품 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></a></div></section>

	<?php if ( $brand_names ) : ?>
	<div class="tick" aria-hidden="true"><div class="tick-in"><?php for ( $r = 0; $r < 2; $r++ ) { foreach ( $brand_names as $b ) { echo '<span>' . esc_html( $b ) . '</span><i></i>'; } } ?></div></div>
	<?php endif; ?>

	<?php if ( $cheap ) : ?>
	<section class="sec">
		<?php section_head( '병당 가격으로 고르기', '병당 1만 원 아래', '묶음으로 담을수록 한 병 값이 내려갑니다', $sale_cat ? get_term_link( $sale_cat ) : $shop_url ); ?>
		<?php carousel( $cheap, '병당 1만 원 아래' ); ?></section>
	<?php endif; ?>

	<?php if ( $newest ) : ?>
	<section class="sec">
		<?php section_head( '새로 들어온', '이번 주 신상', '방금 들어온 맛부터 먼저', add_query_arg( 'orderby', 'date', $shop_url ) ); ?>
		<?php carousel( $newest, '이번 주 신상' ); ?></section>
	<?php endif; ?>

	<?php if ( $picks ) : ?>
	<section class="sec">
		<?php section_head( '주력 브랜드', implode( ' · ', array_slice( $featured, 0, 3 ) ), '이 가게가 가장 오래 팔아 온 라인', $shop_url ); ?>
		<?php carousel( $picks, '주력 브랜드' ); ?></section>
	<?php endif; ?>

	<?php if ( $brand_list ) : ?>
	<section class="sec">
		<?php section_head( '많이 찾는 라인', '브랜드로 둘러보기', '', '' ); ?>
		<div class="bgrid"><?php foreach ( $brand_list as $b ) :
			$bp = products( array( 's' => '[' . $b . ']', 'limit' => 4, 'orderby' => 'popularity' ) );
			if ( ! $bp ) { continue; }
			$bcount = brands()[ $b ] ?? count( $bp );
			$burl = add_query_arg( array( 's' => '[' . $b . ']', 'post_type' => 'product' ), home_url( '/' ) ); ?>
			<article class="bcard"><div class="bhead"><span class="blogo"><?php echo esc_html( mb_substr( $b, 0, 1 ) ); ?></span>
				<div><b><?php echo esc_html( $b ); ?></b>
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
