<?php
/**
 * 상품 상세 — 우리 화면.
 *
 * 테마의 상품 템플릿은 조회수 · 오늘출발 타이머 · 무료배송 게이지 · 추천 상품까지
 * 구매 상자 하나에 쌓는다. 여기서는 화면을 우리가 그린다:
 * 사진 → 브랜드 · 이름 · 가격 → 구매 폼 → 사는 방법 → 상세 설명 → 함께 볼 상품.
 *
 * **구매 폼은 그대로다.** `woocommerce_template_single_add_to_cart()` 가 워드커머스 ·
 * PPOM · 키플 옵션 UI 를 전부 그린다. 우리는 그 앞뒤만 쓴다.
 * 사진은 `wc_get_gallery_image_html()` 로 그려서 비로그인 19 가림이 그대로 걸린다.
 *
 * @package Duckhoo\Redesign
 */

defined( 'ABSPATH' ) || exit;

use function Duckhoo\Redesign\Front\{card, split_name, icon};
use function Duckhoo\Redesign\Product\{head, price, trust};

global $product;
if ( ! $product instanceof \WC_Product ) {
	$product = wc_get_product( get_the_ID() );
}
$dhp_name   = split_name( $product );
// 비로그인 방문자에겐 키플이 상품 사진을 "19" 로 가린다. 그 가림은 카드가 쓰는
// get_image() 경로에 걸려 있고, wc_get_gallery_image_html() 에는 안 걸린다.
// 그래서 비로그인은 get_image() 로 한 장만 그리고 갤러리를 열지 않는다. 우회하지 않는다.
$dhp_gated  = ! is_user_logged_in();
$dhp_images = $dhp_gated ? array() : array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
$dhp_desc   = trim( (string) $product->get_description() );
$dhp_short  = trim( (string) $product->get_short_description() );
$dhp_rel    = array_filter( array_map( 'wc_get_product', wc_get_related_products( $product->get_id(), 8 ) ) );
$dhp_shop   = wc_get_page_permalink( 'shop' );
$dhp_cats   = get_the_terms( $product->get_id(), 'product_cat' );
$dhp_cat    = ( $dhp_cats && ! is_wp_error( $dhp_cats ) ) ? $dhp_cats[0] : null;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'dhr dhr-wrap dhp-page' ); ?>>
<?php wp_body_open(); ?>
<main id="content" class="dhp-main">
<div class="wrap">
	<?php do_action( 'woocommerce_before_single_product' ); ?>

	<nav class="dhp-crumb" aria-label="현재 위치">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">홈</a>
		<a href="<?php echo esc_url( $dhp_shop ); ?>">상품</a>
		<?php if ( $dhp_cat ) : ?><a href="<?php echo esc_url( get_term_link( $dhp_cat ) ); ?>"><?php echo esc_html( $dhp_cat->name ); ?></a><?php endif; ?>
	</nav>

	<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'dhp', $product ); ?>>
		<div class="dhp-top">

			<div class="dhp-gal" data-gal>
				<div class="dhp-gal__main">
					<?php if ( $product->is_on_sale() && (float) $product->get_regular_price() > (float) $product->get_price() ) : ?>
					<span class="dhp-gal__pill">이달 특가 <?php echo (int) round( ( 1 - (float) $product->get_price() / (float) $product->get_regular_price() ) * 100 ); ?>% 할인</span>
					<?php endif; ?>
					<?php if ( count( $dhp_images ) > 1 ) : ?><span class="dhp-gal__n" data-gal-n><b>1</b> / <?php echo count( $dhp_images ); ?></span><?php endif; ?>
					<?php
					foreach ( $dhp_images as $i => $dhp_id ) {
						echo '<div class="dhp-gal__slide' . ( $i ? '' : ' on' ) . '" data-slide="' . (int) $i . '">'
							. wc_get_gallery_image_html( $dhp_id, 0 === $i ) . '</div>'; // phpcs:ignore
					}
					if ( $dhp_gated ) {
						echo '<div class="dhp-gal__slide on">' . $product->get_image( 'woocommerce_single' ) . '</div>'; // phpcs:ignore
					} elseif ( ! $dhp_images ) {
						echo '<div class="dhp-gal__slide on">' . wc_placeholder_img( 'woocommerce_single' ) . '</div>'; // phpcs:ignore
					}
					?>
				</div>
				<?php if ( count( $dhp_images ) > 1 ) : ?>
				<div class="dhp-gal__thumbs" role="tablist" aria-label="상품 사진">
					<?php foreach ( $dhp_images as $i => $dhp_id ) : ?>
					<button type="button" role="tab" class="<?php echo $i ? '' : 'on'; ?>" data-thumb="<?php echo (int) $i; ?>"
						aria-selected="<?php echo $i ? 'false' : 'true'; ?>" aria-label="<?php echo esc_attr( ( $i + 1 ) . '번째 사진' ); ?>">
						<?php echo wp_get_attachment_image( $dhp_id, 'woocommerce_gallery_thumbnail' ); // phpcs:ignore ?>
					</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>

			<?php
			// 구매 영역 — 카드 세 장(고른다 · 산다 · 사는 방법)으로 나눈다. 한 상자에 다 담으면
			// 테마 스크립트가 `.summary` 에 인라인 height(사진 높이)를 박아 넣어 그보다 긴 내용이
			// 상자 밖으로 흘러넘친다. 그래서 클래스 `summary`/`entry-summary` 를 쓰지 않는다 —
			// 각 카드는 자기 내용만큼만 높이를 갖고, 무엇도 밖으로 새지 않는다.
			?>
			<div class="dhp-buy">
				<section class="dhp-card dhp-card--info">
					<?php
					head();
					price();
					\Duckhoo\Redesign\Product\benefits();
					if ( '' !== $dhp_short ) {
						echo '<div class="dhp-short">' . wp_kses_post( wpautop( $dhp_short ) ) . '</div>';
					}
					?>
				</section>
				<section class="dhp-card dhp-card--form" id="dhp-buy">
					<?php
					// 구매 폼 — 워드커머스 · PPOM · 키플 옵션 UI 가 그린다. 손대지 않는다.
					woocommerce_template_single_add_to_cart();
					?>
				</section>
				<section class="dhp-card dhp-card--trust"><?php trust(); ?></section>
			</div>
		</div>

		<?php if ( '' !== $dhp_desc ) : ?>
		<section class="dhp-sec dhp-desc" id="dhp-desc">
			<h2>상품 설명</h2>
			<div class="dhp-desc__body entry-content"><?php echo apply_filters( 'the_content', $dhp_desc ); // phpcs:ignore ?></div>
		</section>
		<?php endif; ?>

		<section class="dhp-sec dhp-how" id="dhp-how">
			<h2>배송 · 교환 · 환불</h2>
			<div class="dhp-faq">
				<details open><summary>언제 출발하나요? <?php echo icon( 'chev' ); // phpcs:ignore ?></summary>
					<p>평일 오후 4시 이전에 입금이 확인된 주문은 당일 우체국택배로 출고합니다. 그 뒤 확인분은 다음 영업일에 나갑니다. 30,000원 이상은 무료배송, 미만은 2,500원입니다.</p></details>
				<details><summary>입금했는데 확인이 안 떠요 <?php echo icon( 'chev' ); // phpcs:ignore ?></summary>
					<p>입금자명이 주문자명과 다르면 자동으로 확인되지 않습니다. 1:1 문의나 전화로 입금자명을 알려 주시면 바로 처리합니다.</p></details>
				<details><summary>교환 · 환불은요? <?php echo icon( 'chev' ); // phpcs:ignore ?></summary>
					<p>미개봉 상품은 받으신 날부터 7일 안에 교환 · 환불됩니다. 개봉한 액상은 위생상 어렵습니다. 하자나 오배송은 배송비 없이 바꿔 드립니다. <a href="<?php echo esc_url( home_url( '/shipping/' ) ); ?>">자세히 보기</a></p></details>
			</div>
		</section>

		<?php if ( $dhp_rel ) : ?>
		<section class="dhp-sec dhp-rel">
			<div class="sec-h"><h2>함께 볼 상품</h2><a class="lk" href="<?php echo esc_url( $dhp_cat ? get_term_link( $dhp_cat ) : $dhp_shop ); ?>">더 보기 <?php echo icon( 'chev' ); // phpcs:ignore ?></a></div>
			<div class="grid grid4"><?php foreach ( $dhp_rel as $dhp_p ) { echo card( $dhp_p ); } // phpcs:ignore ?></div>
		</section>
		<?php endif; ?>
	</div>

	<?php do_action( 'woocommerce_after_single_product' ); ?>
</div>
</main>
<div class="dhp-bar" data-buybar data-price="<?php echo esc_attr( (string) $product->get_price() ); ?>" hidden>
	<div class="dhp-bar__sum"><span>총 금액</span><b class="n" data-bar-total>—</b></div>
	<button type="button" class="dhp-bar__cart" data-bar-cart aria-label="장바구니에 담기"><?php echo icon( 'bag' ); // phpcs:ignore ?></button>
	<button type="button" class="dhp-bar__buy" data-bar-buy>결제하기</button>
	<?php if ( ! is_user_logged_in() ) : ?><a class="dhp-bar__tip" href="<?php echo esc_url( home_url( '/register/' ) ); ?>">가입 즉시 <?php echo esc_html( number_format_i18n( \Duckhoo\Redesign\Front\signup_points() ) ); ?>원 적립</a><?php endif; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
