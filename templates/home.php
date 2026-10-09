<?php
/**
 * 홈 — 데모(design/demo.html)의 구조를 실제 상품으로 그린다.
 *
 * 레퍼런스 순서: 검색 → 히어로 2장 → 오늘의 특가(카운트다운) → 지금 고르세요(필터+그리드)
 * → 브랜드로 둘러보기 → 배너 → 푸터.
 *
 * @package DuckhooRedesign
 */

use function Duckhoo\Redesign\Front\{products, cat_by_name, card, split_name, per_bottle, brands, featured_brands, brand_products, brand_url, cat_icon, icon, header_html, tabbar_html, footer_html, short_cat, carousel, section_head, gate_note, chuseok_html, chuseok, chuseok_on, novo_announce, hero_video};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sale_cat  = cat_by_name( '특가' );
$nonic_cat = cat_by_name( '무니코틴' );
$rank_cat  = cat_by_name( '랭킹' );
$shop_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );

$deals  = $sale_cat ? products( array( 'category' => array( $sale_cat->slug ), 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) ) : products( array( 'include' => wc_get_product_ids_on_sale(), 'limit' => 10 ) );
// 추석 이벤트로 맨 위에 세운 상품은 아래 줄에서 뺀다 — 한 화면에 두 번 나오면
// 맨 위 한 장이 「특별한 것」으로 안 읽힌다.
$chu_id = chuseok_on() ? (int) ( \Duckhoo\Redesign\Front\chuseok_product()?->get_id() ?? 0 ) : 0;
if ( $chu_id ) {
	$deals = array_values( array_filter( $deals, fn( $p ) => (int) $p->get_id() !== $chu_id ) );
}
$newest = products( array( 'limit' => 8, 'orderby' => 'date', 'order' => 'DESC', 'stock_status' => 'instock' ) );
// 입호흡 액상 단품 — 한 병씩 고르는 사람을 위한 줄. 묶음 · 세트 · 기획은 뺀다
// (묶음은 히어로와 특가 · 주력 브랜드 줄이 이미 맡고 있다).
$mtl_cat = cat_by_name( '입호흡' );
$mtl     = $mtl_cat ? array_values( array_filter(
	products( array( 'category' => array( $mtl_cat->slug ), 'limit' => 40, 'orderby' => 'popularity', 'stock_status' => 'instock' ) ),
	fn( $p ) => ! preg_match( '/묶음|세트|이벤트|기획|\d+\s*\+\s*\d|\d+\s*병/u', $p->get_name() )
) ) : array();
$mtl = array_slice( $mtl, 0, 16 );
$brand_names = featured_brands( 12 );
// 히어로는 묶음 상품을 넘겨 본다. 묶음이 이 가게의 주력이고, 병당 가격이 내려가는 게
// 첫 화면에서 보여야 할 이야기다. 사진이 있고 재고가 있는 것만, 최대 5장.
$heroes    = array();
// 맨 위 추석 이벤트로 이미 세운 상품은 히어로에서도 뺀다.
$seen_ids  = $chu_id ? array( $chu_id => true ) : array();
// 브랜드마다 한 장 (2026-10-07 사장님 「노보만 나오게 하지 않고 디오리퀴드 10병 · 화이트아웃 10병도」).
// 행사 이름표(「10병 묶음 할인 이벤트」)가 브랜드 자리에 온 상품은 제목 첫 낱말로 가른다.
$seen_brands = array();
$hero_key    = function ( $p ) {
	$hn = split_name( $p );
	$b  = preg_match( '/이벤트|특가|할인/u', $hn['brand'] ) ? $hn['title'] : $hn['brand'];
	return (string) preg_replace( '/[\s\d+].*$/u', '', trim( $b ) ); // 「노보 블랙 리퀴드」 → 노보, 「덕후 액상 10병」 → 덕후
};
$hero_pick = function ( array $list, int $max = 5 ) use ( &$heroes, &$seen_ids, &$seen_brands, $hero_key ) {
	foreach ( $list as $p ) {
		if ( count( $heroes ) >= $max ) {
			return;
		}
		$id = $p->get_id();
		if ( isset( $seen_ids[ $id ] ) || ! $p->get_image_id() || ! $p->is_in_stock() ) {
			continue;
		}
		// 결제용·부속 상품은 히어로가 아니다. 무니코틴 묶음도 뺀다 — 첫 화면은 니코틴 재고를 파는 자리다 (사장님 2026-09-24)
		if ( preg_match( '/결제|드립팁|첨가제|코일|팟\b|무니코틴/u', $p->get_name() ) ) {
			continue;
		}
		$k = $hero_key( $p );
		if ( '' !== $k && isset( $seen_brands[ $k ] ) ) {
			continue;
		}
		$seen_brands[ $k ] = true;
		$seen_ids[ $id ]   = true;
		$heroes[]          = $p;
	}
};
// 0순위 (2026-09-21): 노보 10+1 묶음 — 다른 사이트에서 노보 품절, 첫 화면에서 노보가 먼저 보여야 한다.
$novo_cat = cat_by_name( '노보' );
if ( $novo_cat ) {
	$hero_pick( array_filter(
		products( array( 'category' => array( $novo_cat->slug ), 'limit' => 12, 'orderby' => 'popularity' ) ),
		fn( $p ) => (bool) preg_match( '/묶음|세트|\d+\s*병|\d\s*\+\s*\d/u', $p->get_name() )
	) );
}
// 1순위: 특가 분류의 묶음, 2순위: 이름이 묶음인 것, 3순위: 최신
if ( $sale_cat ) {
	$hero_pick( products( array( 'category' => array( $sale_cat->slug ), 'limit' => 12, 'orderby' => 'popularity' ) ) );
}
$hero_pick( array_filter( products( array( 'limit' => 24, 'orderby' => 'popularity' ) ), fn( $p ) => (bool) preg_match( '/묶음|세트|\d+\s*병|\d\s*\+\s*\d/u', $p->get_name() ) ) );
$hero_pick( $newest );
// 히어로 영상 (2026-10-09) — 회원에게만. 맨 위 화면 폭 띠로 따로 그리므로, 아래 묶음 슬라이드에서는
// 그 상품과 같은 브랜드(젤로 5병 등)를 뺀다 — 같은 것이 두 번 나오지 않게.
$hv  = hero_video();
$hvp = $hv ? wc_get_product( $hv['product'] ) : null;
if ( $hvp && 'publish' === $hvp->get_status() && $hvp->is_in_stock() ) {
	$hvk    = $hero_key( $hvp );
	$heroes = array_values( array_filter( $heroes, fn( $p ) => $p->get_id() !== $hvp->get_id() && $hero_key( $p ) !== $hvk ) );
} else {
	$hv  = array();
	$hvp = null;
}
$hero = $heroes[0] ?? null;

// 노보 전 라인 줄 (2026-10-01, 사장님 「노보 주문 안 되서 품절되고 있다 — 노를 무지하게 저어야 한다」).
// 재고 있는 노보 상품 전부 — 묶음(10+1)이 앞, 낱병이 뒤. 히어로 한 장은 5초마다 넘어가
// 손님이 놓치므로 따로 한 줄을 세운다. 필터 duckhoo_home_novo 로 끈다.
$novo_row = array();
$novo_sub = '';
if ( $novo_cat && apply_filters( 'duckhoo_home_novo', true ) ) {
	$novo_all = array_values( array_filter(
		products( array( 'category' => array( $novo_cat->slug ), 'limit' => -1, 'orderby' => 'popularity' ) ),
		fn( $p ) => $p->is_in_stock() && ! preg_match( '/결제|드립팁|첨가제|코일|팟\b/u', $p->get_name() )
	) );
	$is_bundle = fn( $p ) => (bool) preg_match( '/묶음|세트|\d+\s*병|\d\s*\+\s*\d/u', $p->get_name() );
	$novo_row  = array_merge( array_filter( $novo_all, $is_bundle ), array_filter( $novo_all, fn( $p ) => ! $is_bundle( $p ) ) );
	$n_single  = count( array_filter( $novo_all, fn( $p ) => ! $is_bundle( $p ) ) );
	$n_bundle  = count( $novo_all ) - $n_single;
	$novo_sub  = ( $n_single ? '낱병 ' . $n_single . '종' : '' ) . ( $n_single && $n_bundle ? ' · ' : '' ) . ( $n_bundle ? '10+1 묶음' : '' )
		. '. 평일 오후 4시 이전 입금 확인분은 당일 출고합니다';
}
// 2026-10-02 — 한 맛이 품절이면 「전 라인」이라고 하지 않는다 (그린펀치 품절)
$novo_state = function_exists( '\\Duckhoo\\Redesign\\Novo\\stock_state' ) ? \Duckhoo\Redesign\Novo\stock_state() : array( 'all' => true, 'out' => array() );
$novo_title = ! empty( $novo_state['all'] ) ? '노보 전 라인 지금 바로 주문' : '노보 액상 지금 바로 주문';
if ( empty( $novo_state['all'] ) && ! empty( $novo_state['out'] ) ) {
	$novo_sub .= ' · 품절: ' . implode( ' · ', $novo_state['out'] );
}

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
	foreach ( brand_products( $fb, 3 ) as $fp ) {
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
<?php if ( $hvp ) :
	$hvu  = get_permalink( $hvp->get_id() );
	$hvr  = (float) $hvp->get_regular_price();
	$hvs  = (float) $hvp->get_price();
	$hvo  = ( $hvr > $hvs && $hvr > 0 ) ? (int) round( ( 1 - $hvs / $hvr ) * 100 ) : 0;
	// 「[젤로 크리스탈] 기기 + 액상 10병 묶음」 → 「젤로 크리스탈 기기 + 액상 10병」 (폰에서 한 줄)
	$hvn  = trim( (string) preg_replace( array( '/^\[([^\]]*)\]\s*/u', '/\s*묶음\s*$/u' ), array( '$1 ', '' ), $hvp->get_name() ) );
	$hvt  = $hv['cta'] ? $hv['cta'] : '구매하기';
	?>
<section class="hvid<?php echo $hv['wide'] ? '' : ' hvid--sq'; ?>" aria-label="<?php echo esc_attr( $hvn ); ?>">
	<a class="hvid__media" href="<?php echo esc_url( $hvu ); ?>" tabindex="-1" aria-hidden="true">
		<video class="hvid__v" muted loop playsinline autoplay preload="metadata"<?php echo $hv['poster'] ? ' poster="' . esc_url( $hv['poster'] ) . '"' : ''; ?>>
			<?php if ( $hv['wide'] ) : ?><source media="(min-width: 880px)" src="<?php echo esc_url( $hv['wide'] ); ?>" type="video/mp4"><?php endif; ?>
			<?php if ( $hv['tall'] ) : ?><source media="(max-width: 879px)" src="<?php echo esc_url( $hv['tall'] ); ?>" type="video/mp4"><?php endif; ?>
			<source src="<?php echo esc_url( $hv['src'] ); ?>" type="video/mp4">
		</video>
	</a>
	<div class="hvid__bar">
		<div class="hvid__in">
			<p class="hvid__t"><span class="hvid__eb">JELLO CRYSTAL</span><b><?php echo esc_html( $hvn ); ?></b></p>
			<p class="hvid__p"><?php if ( $hvo > 0 ) : ?><em>-<?php echo (int) $hvo; ?>%</em><?php endif; ?><b><?php echo esc_html( number_format_i18n( $hvs ) ); ?>원</b><?php if ( $hvr > $hvs ) : ?><s><?php echo esc_html( number_format_i18n( $hvr ) ); ?>원</s><?php endif; ?></p>
			<a class="hvid__cta" href="<?php echo esc_url( $hvu ); ?>"><?php echo esc_html( $hvt ); ?> <?php echo icon( 'arrow' ); // phpcs:ignore ?></a>
		</div>
	</div>
</section>
<?php endif; ?>
<div class="wrap">

	<?php
	// 홈을 대표하는 제목. 예전에는 히어로 슬라이드 다섯 장이 각각 h1 이라 이 화면이
	// 무엇을 파는 곳인지 말하는 제목이 하나도 없었다. 화면 디자인은 그대로 두고
	// 읽어 주는 쪽(검색엔진 · 스크린리더)에만 보이게 한 줄 세운다.
	?>
	<h1 class="dhr-h1"><?php echo esc_html( (string) apply_filters( 'duckhoo_home_h1', '액상덕후 — 전자담배 액상 전문몰' ) ); ?></h1>

	<?php // 눌러서 검색창을 연다. 예전에는 빈 검색(?s=) 으로 보내 결과가 0건인 화면이 나왔다. ?>
	<a class="msearch" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" data-search-open aria-haspopup="dialog" aria-expanded="false" aria-controls="dhr-search"><?php echo icon( 'search' ); // phpcs:ignore ?><span>‘샤인머스캣’ 처럼 찾아보세요</span></a>

	<?php
	// 추석 이벤트 — 첫 화면 맨 위 (사장님 2026-09-15). 상품 데이터는 안 건드리고
	// 이벤트 이름 · 문구만 얹는다. 끄기는 `duckhoo_chuseok` 의 `on` 을 false 로.
	echo chuseok_html(); // phpcs:ignore WordPress.Security.EscapeOutput — 안에서 escape 한다
	?>

	<?php
	// 히어로 — 유리 시안 A (2026-10-06). 왼쪽 글(눈썹 · 제목 · 한 줄 · 버튼 둘), 오른쪽 무대(사진 + 할인 알약 + 유리 캡션).
	// 2026-10-07 사장님 「노보만 나오게 하지 않고 다른 액상 이벤트도」 — 묶음 다섯 장을 다시 넘겨 본다. 겹쳐 두고 크로스페이드,
	// 점으로 고르고, 마우스 · 포커스가 오면 서고, 동작 줄이기를 켠 사람에게는 자동으로 안 넘긴다 (front.js).
	$hero_slide = function ( $h0 ) {
		$hn  = split_name( $h0 );
		$hp  = per_bottle( $h0 );
		$hr  = (float) $h0->get_regular_price();
		$hs  = (float) $h0->get_price();
		$off = ( $hr > $hs && $hr > 0 ) ? (int) round( ( 1 - $hs / $hr ) * 100 ) : 0;
		$ht  = trim( (string) preg_replace( '/\s*\|\s*금액\s*[\d,]+\s*원\s*$/u', '', $hn['title'] ) );
		$ht  = trim( (string) preg_replace( array( '/★[^★]*★/u', '/\s*(묶음\s*이벤트|할인\s*!?|이벤트\s*!?|EVENT\s*!?)\s*$/iu', '/\s*\([^)]*\)\s*$/u' ), '', $ht ) );   // 끝의 괄호(「(30병 묶음 + 서비스 3병)」)도 뗀다 — 폰에서 두 줄을 굵게 먹었다
		if ( '' !== $hn['brand'] && false === mb_strpos( $ht, $hn['brand'] ) && ! preg_match( '/이벤트|특가|할인/u', $hn['brand'] ) ) {
			$ht = $hn['brand'] . ' ' . $ht;
		}
		$heb = novo_announce();
		if ( '' === $heb || false === mb_strpos( $ht, '노보' ) ) {
			$heb = $h0->is_on_sale() ? '묶음 특가 · 지금 주문하면 오늘 출고' : '추천 묶음';
		}
		$hsub = ( $hp['qty'] > 1 ? $hp['qty'] . '병에 병당 ' . number_format_i18n( $hp['per'] ) . '원. ' : '' ) . '평일 오후 4시 이전 입금 확인분은 당일 출고합니다.';
		return compact( 'hr', 'hs', 'off', 'ht', 'heb', 'hsub' );
	};
	if ( $heroes ) :
		$hmany = count( $heroes ) > 1;
	?>
	<section class="hero hhero"<?php echo $hmany ? ' data-hslides aria-roledescription="carousel" aria-label="묶음 이벤트"' : ''; ?>>
		<?php foreach ( $heroes as $hi => $h0 ) : $v = $hero_slide( $h0 ); ?>
		<div class="hslide<?php echo 0 === $hi ? ' on' : ''; ?>"<?php echo $hmany ? ' role="group" aria-roledescription="slide" aria-label="' . esc_attr( ( $hi + 1 ) . ' / ' . count( $heroes ) ) . '"' . ( 0 === $hi ? '' : ' aria-hidden="true"' ) : ''; ?>>
		<div class="hero__tx">
			<span class="eb2 hero__eb"><i></i><?php echo esc_html( $v['heb'] ); ?></span>
			<?php if ( 0 === $hi ) : ?><h2 class="hero__t"><?php echo esc_html( $v['ht'] ); ?></h2><?php else : ?><p class="hero__t"><?php echo esc_html( $v['ht'] ); ?></p><?php endif; ?>
			<p class="hero__sub"><?php echo esc_html( $v['hsub'] ); ?></p>
			<div class="hero__cta">
				<a class="btn btn-d" href="<?php echo esc_url( get_permalink( $h0->get_id() ) ); ?>">바로 구매 <?php echo icon( 'arrow' ); // phpcs:ignore ?></a>
				<a class="btn btn-o" href="<?php echo esc_url( $sale_cat ? get_term_link( $sale_cat ) : $shop_url ); ?>"><?php echo (int) $month; ?>월 특가<?php echo $best_off ? ' 최대 ' . (int) $best_off . '%' : ''; ?></a>
			</div>
		</div>
		<a class="stage" href="<?php echo esc_url( get_permalink( $h0->get_id() ) ); ?>" aria-label="<?php echo esc_attr( $v['ht'] . ' ' . number_format_i18n( $v['hs'] ) . '원' ); ?>">
			<?php if ( $v['off'] > 0 ) : ?><span class="stage__pill">-<?php echo (int) $v['off']; ?>%</span><?php endif; ?>
			<span class="stage__img"><?php echo $h0->get_image( 'woocommerce_single' ); // phpcs:ignore ?></span>
			<span class="stage__cap"><b><?php echo esc_html( $v['ht'] ); ?></b>
				<span class="n"><?php if ( $v['hr'] > $v['hs'] ) : ?><s><?php echo esc_html( number_format_i18n( $v['hr'] ) ); ?>원</s> <?php endif; ?><em data-count="<?php echo (int) $v['hs']; ?>" data-suffix="원"><?php echo esc_html( number_format_i18n( $v['hs'] ) ); ?>원</em></span></span>
		</a>
		</div>
		<?php endforeach; ?>
		<?php if ( $hmany ) : ?>
		<div class="hero__dots" role="tablist" aria-label="묶음 고르기">
			<?php foreach ( $heroes as $hi => $h0 ) : ?>
			<button type="button" class="hdot<?php echo 0 === $hi ? ' on' : ''; ?>" role="tab" aria-selected="<?php echo 0 === $hi ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( $hero_slide( $h0 )['ht'] ); ?>"><i></i></button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<?php
	// 둘러보기 — 분류로 바로 가는 둥근 타일. 분류 사진이 있으면 쓰고 없으면 첫 글자.
	// 타격감은 사장님이 직접 고른 묶음이라 특가 바로 다음에 세운다 (2026-09-15).
	// 폰은 4열 두 줄이라 여덟 개가 딱 맞는다.
	// 노보를 맨 앞에 (2026-09-21 — 다른 사이트 품절, 찾아 오는 사람이 많다). 원래 자리는 맨 뒤였다.
	$qc = array_values( array_filter( array( cat_by_name( '노보' ), $sale_cat, cat_by_name( '타격' ), cat_by_name( '입호흡' ), cat_by_name( '폐호흡' ), $nonic_cat, cat_by_name( '기기' ), cat_by_name( '적립금' ) ) ) );
	if ( $qc ) : ?>
	<nav class="qcats" aria-label="분류 바로 가기">
		<?php foreach ( $qc as $c ) : ?>
		<a href="<?php echo esc_url( get_term_link( $c ) ); ?>">
			<span class="qcats__ic"><?php echo icon( cat_icon( $c->name ) ); // phpcs:ignore ?></span>
			<span><?php echo esc_html( short_cat( $c->name ) ); ?></span>
		</a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<?php echo gate_note(); // phpcs:ignore — 비로그인: 사진이 왜 안 보이는지 ?>

	<?php
	// 숫자 셋 — 상품에서 읽는다 (적어 두지 않는다). 화면에 들어오면 굴러 올라온다 (data-count).
	$n_all = function_exists( '\\Duckhoo\\Redesign\\Seo\\Broad\\count_all' ) ? (int) \Duckhoo\Redesign\Seo\Broad\count_all() : 0;
	$n_mtl = function_exists( '\\Duckhoo\\Redesign\\Seo\\Broad\\cat_facts' ) ? (int) ( \Duckhoo\Redesign\Seo\Broad\cat_facts( '입호흡' )['n'] ?? 0 ) : 0;
	$n_dl  = function_exists( '\\Duckhoo\\Redesign\\Seo\\Broad\\cat_facts' ) ? (int) ( \Duckhoo\Redesign\Seo\Broad\cat_facts( '폐호흡' )['n'] ?? 0 ) : 0;
	if ( $n_all > 0 ) : ?>
	<div class="nums" aria-label="가게 숫자">
		<div class="num"><b data-count="<?php echo (int) $n_all; ?>" data-suffix="종"><?php echo (int) $n_all; ?>종</b><span>전체 상품</span></div>
		<?php if ( $n_mtl ) : ?><div class="num"><b data-count="<?php echo (int) $n_mtl; ?>" data-suffix="종"><?php echo (int) $n_mtl; ?>종</b><span>입호흡 액상</span></div><?php endif; ?>
		<?php if ( $n_dl ) : ?><div class="num"><b data-count="<?php echo (int) $n_dl; ?>" data-suffix="종"><?php echo (int) $n_dl; ?>종</b><span>폐호흡 액상</span></div><?php endif; ?>
		<div class="num"><b>16시</b><span>평일 출고 마감</span></div>
	</div>
	<?php endif; ?>

	<?php if ( $novo_row ) : // 2026-10-01 — 노보가 시장에서 끊기는 때. 첫 화면에 노보 전 라인을 통째로 세운다. ?>
	<section class="sec sec--novo">
		<?php section_head( '노보 액상 · 재고 있음', $novo_title, $novo_sub, $novo_cat ? get_term_link( $novo_cat ) : $shop_url ); ?>
		<?php carousel( $novo_row, '노보 액상' ); ?></section>
	<?php endif; ?>

	<?php if ( $deals ) : ?>
	<section class="deals"><div class="deals-h"><div><h2><?php echo (int) $month; ?>월 특가</h2><p class="sh-sub">묶음으로 담을수록 병당 가격이 내려갑니다</p></div>
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

	<?php if ( $mtl ) : ?>
	<section class="sec">
		<?php section_head( '한 병씩 고르기', '입호흡 액상 단품', '묶음 말고 필요한 맛만 골라 담으세요', get_term_link( $mtl_cat ) ); ?>
		<?php carousel( $mtl, '입호흡 액상 단품' ); ?>
		<div class="center" style="margin-top:.4rem"><a class="btn btn-o" href="<?php echo esc_url( get_term_link( $mtl_cat ) ); ?>">입호흡 액상 전체 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></a></div>
	</section>
	<?php endif; ?>

	<?php if ( $newest ) : // 신제품 — 카드 카루셀이 넷이나 이어져 글자 줄로 바꿨다 (썸네일 · 이름 · 규격 · 값 · 구매). 다른 꼴이 하나 들어가야 리듬이 생긴다 ?>
	<section class="sec">
		<?php section_head( '', '신제품', '방금 들어온 맛부터 먼저', add_query_arg( 'orderby', 'date', $shop_url ) ); ?>
		<div class="rows"><?php foreach ( array_slice( $newest, 0, 6 ) as $np ) :
			$nn = split_name( $np ); $nb = per_bottle( $np ); $nr = (float) $np->get_regular_price(); $ns = (float) $np->get_price(); ?>
			<a class="row" href="<?php echo esc_url( get_permalink( $np->get_id() ) ); ?>">
				<span class="row__th"><?php echo $np->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore ?></span>
				<span class="row__tx"><b class="row__nm"><?php echo esc_html( $nn['title'] ); ?></b><span class="row__sp"><?php echo esc_html( $nn['brand'] ? $nn['brand'] : '' ); ?><?php echo $nb['qty'] > 1 ? esc_html( ( $nn['brand'] ? ' · ' : '' ) . $nb['qty'] . '병' ) : ''; ?></span></span>
				<span class="row__pr n"><?php if ( $nr > $ns ) : ?><s><?php echo esc_html( number_format_i18n( $nr ) ); ?>원</s><?php endif; ?><b><?php echo esc_html( number_format_i18n( $ns ) ); ?>원</b></span>
				<span class="row__go" aria-hidden="true"><?php echo icon( 'chev' ); // phpcs:ignore ?></span>
			</a>
		<?php endforeach; ?></div></section>
	<?php endif; ?>

	<?php if ( $brand_list ) : ?>
	<section class="sec">
		<?php section_head( '많이 찾는 라인', '브랜드로 둘러보기', '', '' ); ?>
		<div class="bgrid"><?php foreach ( $brand_list as $bi => $b ) :
			$bcount = brands()[ $b ] ?? 0;
			if ( $bcount < 1 ) { continue; }
			$burl  = brand_url( $b );
			$bf    = function_exists( '\\Duckhoo\\Redesign\\Seo\\brand_facts' ) ? \Duckhoo\Redesign\Seo\brand_facts( $b ) : array();
			$bcat  = '';
			foreach ( (array) ( $bf['cats'] ?? array() ) as $c ) { if ( preg_match( '/입호흡|폐호흡|무니코틴/u', (string) $c, $m ) ) { $bcat = $m[0]; break; } }
			$beb   = trim( ( '' !== $bcat ? $bcat . ' ' : '' ) . $bcount . '종' );
			// 글 한 줄 — 노보는 맛 이름을 적지 않는다 (맛 검색어는 그 상품 페이지 한 장만, 2026-10-06). 다른 브랜드는 맛 셋 + 낱병 값
			if ( false !== mb_strpos( $b, '노보' ) && function_exists( '\\Duckhoo\\Redesign\\Novo\\stock_phrase' ) ) {
				$bp = '낱병과 10+1 묶음, ' . \Duckhoo\Redesign\Novo\stock_phrase( 'short' ) . ' 지금 바로 주문됩니다.';
			} else {
				$fl = array_slice( (array) ( $bf['flavors'] ?? array() ), 0, 3 );
				$bp = ( $fl ? implode( ' · ', $fl ) . ( count( (array) ( $bf['flavors'] ?? array() ) ) > 3 ? ' 외' : '' ) . '. ' : '' )
					. ( ! empty( $bf['single_min'] ) ? '낱병 ' . number_format_i18n( (float) $bf['single_min'] ) . '원' . ( ! empty( $bf['bundle_per'] ) ? ', 묶음은 병당 ' . number_format_i18n( (float) $bf['bundle_per'] ) . '원부터' : '' ) . '.' : '' );
			}
			$bk = 0 === $bi ? ' bcard--inv' : ( 2 === $bi ? ' bcard--acc' : '' ); ?>
			<?php // .stk — 폰에서 카드가 겹쳐 쌓이는 스택(유리 시안 A). 데스크톱은 격자 칸 ?>
			<div class="stk"><article class="bcard<?php echo esc_attr( $bk ); ?>">
				<span class="bcard__pic" aria-hidden="true"><?php echo esc_html( mb_substr( $b, 0, 1 ) ); ?></span>
				<span class="eb2 bcard__eb"><?php echo esc_html( $beb ); ?></span>
				<h3 class="bcard__t"><?php echo esc_html( $b ); ?></h3>
				<?php if ( '' !== trim( $bp ) ) : ?><p class="bcard__p"><?php echo esc_html( $bp ); ?></p><?php endif; ?>
				<div class="bcard__cta"><a class="btn <?php echo 0 === $bi ? 'btn-p' : 'btn-d'; ?>" href="<?php echo esc_url( $burl ); ?>"><?php echo esc_html( $b ); ?> 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></a></div>
			</article></div>
		<?php endforeach; ?></div></section>
	<?php endif; ?>

	<section class="banner"><div><h2><?php echo (int) $month; ?>월엔 병당 가격으로 고르세요</h2>
		<p>한 병만 사도 되고, 묶으면 병당 가격이 내려갑니다. 입금자명을 주문자명과 같게 넣으면 자동으로 입금확인됩니다.</p>
		<a class="btn btn-w2" href="<?php echo esc_url( $sale_cat ? get_term_link( $sale_cat ) : $shop_url ); ?>">특가 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></a></div></section>

	<?php
	// 2026-10-06 — 홈 맨 아래 소개 글. 「전담 액상 · 전자담배 액상」 상위 몰은 홈에 글이 1,000자 넘게 있고 우리는 카드뿐이었다.
	// 상품 위에 글을 깔지 않는다는 규칙대로 **맨 아래**. 숫자는 상품에서 읽는다 (Broad\about_home). 끄기: duckhoo_home_about → array()
	if ( function_exists( '\\Duckhoo\\Redesign\\Seo\\Broad\\about_home_html' ) ) {
		echo \Duckhoo\Redesign\Seo\Broad\about_home_html(); // phpcs:ignore WordPress.Security.EscapeOutput — 안에서 escape 한다
	}
	?>

</div>
</main>
<?php footer_html(); ?>
<?php tabbar_html(); ?>
<?php wp_footer(); ?>
</body>
</html>
