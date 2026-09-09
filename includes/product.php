<?php
/**
 * 상품 상세 — 구매 상자를 우리가 그린다.
 *
 * 여태 한 건 키플 화면 위에 색만 얹은 것이었다. 조회수 · 제목 · 가격 · 오늘출발 ·
 * 무료배송 게이지 · 옵션 · 총액 · 버튼이 전부 같은 크기로 쌓여 있어서 무엇을 먼저
 * 봐야 하는지가 없었다.
 *
 * 여기서는 워드커머스 기본 제목 · 가격 훅을 우리 것으로 갈아끼우고, 살 때 필요한
 * 이야기(무통장입금 · 입금자명 · 출고 · 19세)를 버튼 아래에 붙인다.
 *
 * **구매 폼은 건드리지 않는다.** form.cart 는 워드커머스 · PPOM · 키플 옵션 UI 가
 * 그대로 그린다. 우리가 바꾸는 건 그 위아래뿐이다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Product;

use function Duckhoo\Redesign\Front\{split_name, per_bottle, eyebrow, icon};

defined( 'ABSPATH' ) || exit;

/**
 * 우리 껍데기를 쓰는 상품 화면인가.
 *
 * @return bool
 */
function on(): bool {
	return ! is_admin() && function_exists( 'is_product' ) && is_product()
		&& (bool) apply_filters( 'duckhoo_take_product_summary', true );
}

/**
 * 제목 줄 — 브랜드 · 상태 배지 · 상품명.
 *
 * @return void
 */
function head(): void {
	global $product;
	if ( ! $product instanceof \WC_Product ) {
		return;
	}
	$n  = split_name( $product );
	$eb = eyebrow( $product );

	if ( $eb ) {
		echo '<div class="dhp-eb"><span class="dhp-badge dhp-badge--' . esc_attr( $eb[1] ) . '">' . esc_html( $eb[0] ) . '</span></div>';
	}

	// **브랜드는 제목 안에 둔다.** 작은 회색 윗줄로 빼 놨더니 `노보 데저트` 와
	// `노보 블랙 데저트` 가 화면에서 둘 다 그냥 `데저트` 로 보였다. 이 가게에는
	// 그렇게 겹치는 상품이 4종 8개 있다 (데저트 · 코코넛커피 · 블랙멘솔 · 알로에베라).
	// 포장할 때 헷갈리고, 검색엔진에도 같은 제목이 두 개로 보인다.
	echo '<h1 class="product_title entry-title dhp-title">';
	if ( '' !== $n['brand'] ) {
		echo '<span class="dhp-title__b">' . esc_html( $n['brand'] ) . '</span> ';
	}
	echo esc_html( $n['title'] ) . '</h1>';
}

/**
 * 가격 줄 — 파는 값이 주인공, 병당은 그 아래.
 *
 * @return void
 */
function price(): void {
	global $product;
	if ( ! $product instanceof \WC_Product ) {
		return;
	}
	$now = (float) $product->get_price();
	$was = (float) $product->get_regular_price();
	$pb  = per_bottle( $product );
	$off = ( $was > $now && $was > 0 ) ? (int) round( ( 1 - $now / $was ) * 100 ) : 0;

	echo '<div class="dhp-price">';
	if ( $off > 0 ) {
		echo '<span class="dhp-off">' . (int) $off . '%</span>';
		echo '<s class="dhp-was">' . esc_html( number_format_i18n( $was ) ) . '원</s>';
	}
	echo '<b class="dhp-now">' . esc_html( number_format_i18n( $now ) ) . '<span>원</span></b>';
	echo '</div>';

	if ( $pb['qty'] > 1 ) {
		echo '<div class="dhp-unit"><b>병당 ' . esc_html( number_format_i18n( $pb['per'] ) ) . '원</b>'
			. '<span>' . (int) $pb['qty'] . '병 묶음</span></div>';
	}
}

/**
 * 가격 바로 아래 — 살까 말까를 정하는 사실 세 줄. 길게는 버튼 아래 trust() 가 말한다.
 *
 * @return void
 */
function benefits(): void {
	global $product;
	$rows = array( '30,000원 이상 무료배송 · 우체국택배', '평일 16시 이전 입금 확인 시 당일 출고', '미개봉 7일 이내 교환 · 환불' );
	if ( ! is_user_logged_in() ) {
		array_unshift( $rows, '첫 가입 시 ' . number_format_i18n( \Duckhoo\Redesign\Front\signup_points() ) . '원 적립 · 본인확인 1분' );
	}
	echo '<ul class="dhp-ben">';
	foreach ( apply_filters( 'duckhoo_product_benefits', $rows, $product ) as $r ) {
		echo '<li>' . esc_html( $r ) . '</li>';
	}
	echo '</ul>';
}

/**
 * 버튼 아래 — 이 가게에서 사는 방법. 결제 단계에서 처음 보면 늦는 이야기다.
 *
 * @return void
 */
function trust(): void {
	$rows = apply_filters(
		'duckhoo_product_trust',
		array(
			array( 'bank', '무통장입금 전용', '<b>입금자명을 주문자명과 똑같이</b> 넣어 주세요. 같으면 자동으로 확인됩니다.' ),
			array( 'truck', '평일 16시 이전 입금 확인 시 당일 출고', '30,000원 이상 무료배송 · 우체국택배' ),
			array( 'shield', '19세 미만 판매 금지', '구매 시 휴대폰 본인확인이 필요합니다 · 니코틴은 중독성이 있는 물질입니다' ),
		)
	);
	echo '<ul class="dhp-trust">';
	foreach ( $rows as $r ) {
		echo '<li><span class="dhp-trust__ic">' . icon( $r[0] ) . '</span>' // phpcs:ignore
			. '<span class="dhp-trust__tx"><b>' . esc_html( $r[1] ) . '</b>'
			. '<span>' . wp_kses( $r[2], array( 'b' => array() ) ) . '</span></span></li>';
	}
	echo '</ul>';
	// 카카오톡은 로그인 없이 바로 물어볼 수 있는 유일한 창구다 — 게시판보다 앞에 둔다.
	$kakao = \Duckhoo\Redesign\Front\kakao_url();
	echo '<p class="dhp-links"><a href="' . esc_url( home_url( '/shipping/' ) ) . '">배송 · 교환 · 환불 안내</a>'
		. '<a href="' . esc_url( $kakao ) . '"' . ( \Duckhoo\Redesign\Front\is_external( $kakao ) ? ' target="_blank" rel="noopener"' : '' ) . '>카카오톡 문의</a>'
		. '<a href="' . esc_url( \Duckhoo\Redesign\Front\inquiry_url() ) . '">1:1 문의</a></p>';
}

/**
 * 훅 갈아끼우기. 워드커머스 기본 제목 · 가격만 빼고 나머지는 그대로 둔다.
 *
 * @return void
 */
function swap(): void {
	if ( ! on() ) {
		return;
	}
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
	add_action( 'woocommerce_single_product_summary', __NAMESPACE__ . '\\head', 5 );
	add_action( 'woocommerce_single_product_summary', __NAMESPACE__ . '\\price', 10 );
	add_action( 'woocommerce_single_product_summary', __NAMESPACE__ . '\\trust', 45 );
}
add_action( 'wp', __NAMESPACE__ . '\\swap' );

/**
 * 상품 구조화 데이터(Product JSON-LD)를 붙입니다.
 *
 * **왜 필요한가.** 워드커머스는 `woocommerce_single_product_summary` 훅이 돌 때
 * 상품의 가격 · 재고 · 브랜드를 검색엔진용 데이터로 만든다. 우리 상세 템플릿은
 * 화면을 직접 그리느라 그 훅을 쏘지 않는다 — 그래서 상품 페이지에 `Product` 가
 * 통째로 빠져 있었다 (2026-09-07 확인: `BreadcrumbList` · `ItemPage` ·
 * `Organization` · `WebSite` 뿐). 검색엔진이 가격을 읽지 못하니 상품 페이지가
 * 검색 경쟁에 아예 못 들어갔다.
 *
 * 훅을 그대로 쏘면 테마의 제목 · 가격 · 구매 버튼이 한 벌 더 그려지므로,
 * 데이터를 만드는 쪽만 직접 부른다. **화면은 하나도 바뀌지 않는다.**
 * 출력은 워드커머스가 `wp_footer` 에서 알아서 한다.
 *
 * 목록 화면에는 붙이지 않는다 — 카드가 수십 장인 분류 페이지에 Product 를 수십 개
 * 싣는 것은 얻는 것에 비해 무겁다. 필요해지면 그때는 ItemList 로 따로 짠다.
 *
 * @param \WC_Product|null $product 상품. 없으면 전역.
 * @return void
 */
function schema( $product = null ): void {
	if ( ! apply_filters( 'duckhoo_product_schema', true, $product ) || ! function_exists( 'WC' ) ) {
		return;
	}

	$wc = WC();
	if ( ! isset( $wc->structured_data ) || ! is_object( $wc->structured_data )
		|| ! method_exists( $wc->structured_data, 'generate_product_data' ) ) {
		return;
	}

	if ( ! $product instanceof \WC_Product ) {
		$product = $GLOBALS['product'] ?? null;
	}
	if ( ! $product instanceof \WC_Product ) {
		return;
	}

	$wc->structured_data->generate_product_data( $product );
}

/**
 * 구조화 데이터에 브랜드를 채웁니다.
 *
 * 이 사이트에는 브랜드 분류(taxonomy)가 없다. 브랜드는 상품 이름 앞 `[노보]` 로만
 * 구분되고, 화면의 카드 · 상세 제목이 이미 그것을 브랜드로 그린다. 그래서
 * 검색엔진에게도 같은 값을 준다 — 화면에 보이는 것과 데이터가 어긋나지 않는다.
 *
 * 노보 · 디오리퀴드 같은 브랜드 이름이 이 가게가 실제로 순위를 먹고 있는 축이라
 * 비워 두면 아까운 자리다.
 *
 * @param array            $data    워드커머스가 만든 상품 데이터.
 * @param \WC_Product|null $product 상품.
 * @return array
 */
function schema_brand( $data, $product = null ): array {
	$data = (array) $data;

	if ( ! $product instanceof \WC_Product || ! empty( $data['brand'] ) ) {
		return $data;
	}

	$brand = \Duckhoo\Redesign\Front\split_name( $product )['brand'];
	if ( '' === $brand ) {
		return $data;
	}

	$data['brand'] = array(
		'@type' => 'Brand',
		'name'  => $brand,
	);

	return $data;
}
add_filter( 'woocommerce_structured_data_product', __NAMESPACE__ . '\\schema_brand', 10, 2 );

/* ── 상품 후기 ────────────────────────────────────────────────────────────
   상세를 우리 템플릿으로 바꾸면서 **리뷰 영역이 통째로 빠져 있었다.** 관리자에
   `상품 → 상품평` 메뉴가 있으니 워드커머스 리뷰는 켜져 있는데, 화면에 쓸 자리가
   없어서 손님이 후기를 남길 방법이 없었다 (2026-09-09 사장님 확인). */

/**
 * 이 사이트에서 리뷰를 쓰는가 — 워드커머스의 전체 설정.
 *
 * @return bool
 */
function reviews_on(): bool {
	return 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' );
}

/**
 * 상품의 댓글이 닫혀 있어도 리뷰를 열어 준다.
 *
 * **왜 필요한가.** 리뷰가 꺼진 채로 만들어지거나 가져오기(import)로 들어온 상품은
 * `comment_status` 가 `closed` 로 남는다. 그러면 전체 설정을 켜도 그 상품에는
 * 리뷰 영역이 안 나온다 — 173개를 하나씩 열어 고칠 수는 없다.
 *
 * 상품에만, 전체 설정이 켜져 있을 때만 연다. 끄려면
 * `add_filter( 'duckhoo_force_product_reviews', '__return_false' );`
 *
 * @param bool $open 여태 판정.
 * @param int  $pid  글 ID.
 * @return bool
 */
function open_reviews( $open, $pid = 0 ): bool {
	if ( $open || ! reviews_on() ) {
		return (bool) $open;
	}
	if ( 'product' !== get_post_type( (int) $pid ) ) {
		return (bool) $open;
	}
	return (bool) apply_filters( 'duckhoo_force_product_reviews', true );
}
add_filter( 'comments_open', __NAMESPACE__ . '\\open_reviews', 10, 2 );

/**
 * 상세의 후기 영역. **워드커머스가 그린다** — 폼 · 필드 이름 · 논스 · 구매자 확인이
 * 전부 그쪽 것이라야 관리자의 `상품평` 화면과 별점 집계가 그대로 맞는다.
 * 우리는 자리와 제목만 준다.
 *
 * @return void
 */
function reviews(): void {
	if ( ! reviews_on() || ! comments_open() ) {
		return;
	}
	$n = (int) get_comments_number();
	?>
	<section class="dhp-sec dhp-rev" id="dhp-rev">
		<div class="sec-h">
			<h2>상품 후기<?php echo $n ? ' <span class="dhp-rev__n n">' . esc_html( number_format_i18n( $n ) ) . '</span>' : ''; // phpcs:ignore ?></h2>
		</div>
		<?php comments_template(); ?>
	</section>
	<?php
}
