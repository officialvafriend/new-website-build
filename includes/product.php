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
		<?php echo with_uploads( render_reviews() ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</section>
	<?php
}

/**
 * 워드커머스의 후기 화면을 글자로 받는다.
 *
 * @return string
 */
function render_reviews(): string {
	ob_start();
	comments_template();
	return (string) ob_get_clean();
}

/**
 * 후기 폼이 **파일을 보낼 수 있게** 한다.
 *
 * `comment_form()` 은 `<form>` 을 직접 찍고 `enctype` 을 넣을 필터를 주지 않는다.
 * 그렇다고 JS 로 붙이면 스크립트가 늦거나 죽었을 때 사진이 조용히 사라진다 — 손님은
 * 올렸다고 믿고 적립금을 기다린다. 그래서 **우리가 그린 글자에서** 그 한 칸만 채운다.
 * 이미 있으면 그대로 둔다.
 *
 * @param string $html 후기 화면.
 * @return string
 */
function with_uploads( string $html ): string {
	if ( false !== strpos( $html, 'enctype' ) ) {
		return $html;
	}
	return (string) preg_replace(
		'/(<form\b[^>]*\bid=["\']commentform["\'])/i',
		'$1 enctype="multipart/form-data"',
		$html,
		1
	);
}

/**
 * 후기는 **그 상품을 산 사람만** 쓴다.
 *
 * 사장님 결정 2026-09-09. 워드커머스 설정
 * (`WooCommerce → 설정 → 상품 → 구매한 고객만 리뷰 작성`)과 같은 값을 플러그인이
 * 정한다 — 관리자 화면의 체크박스도 켜진 것으로 보인다.
 *
 * 끄려면 `add_filter( 'duckhoo_reviews_verified_only', '__return_false' );`
 *
 * @return bool
 */
function verified_only(): bool {
	return (bool) apply_filters( 'duckhoo_reviews_verified_only', true );
}

/**
 * 그 설정값을 우리가 돌려준다.
 *
 * @param mixed $value 저장된 값.
 * @return mixed
 */
function force_verified( $value ) {
	return verified_only() ? 'yes' : $value;
}
add_filter( 'option_woocommerce_review_rating_verification_required', __NAMESPACE__ . '\\force_verified' );

/**
 * 이 가게에서 「샀다」고 볼 주문 상태.
 *
 * **워드커머스는 `processing` · `completed` 만 산 것으로 본다**
 * (`wc_get_is_paid_statuses()`). 그런데 이 가게의 주문은 입금전(on-hold) →
 * 입금확인(payment-confirmed) → 배송준비중(ready-to-ship) → 배송완료(delivered)
 * 로 흐르고, **그 중 어느 것도 그 목록에 없다.** 그대로 두면 「구매한 고객만」을
 * 켜는 순간 **아무도 후기를 못 쓴다** (실제로 그랬다).
 *
 * 받은 사람만 센다 — 배송완료 · 완료. 사진 후기를 쓰려면 물건이 손에 있어야 하고,
 * 입금 전 주문으로 후기를 쓰는 것은 후기가 아니다.
 *
 * @return string[]
 */
function bought_statuses(): array {
	return array_values( array_unique( array_map(
		'strval',
		(array) apply_filters(
			'duckhoo_review_bought_statuses',
			array( 'delivered', 'completed', 'processing' )
		)
	) ) );
}

/**
 * 이 회원이 이 상품을 받은 적이 있는가.
 *
 * `woocommerce_order_is_paid_statuses` 를 통째로 넓히지 않는다 — 그것은 매출 ·
 * 정산 · 재고까지 따라 움직이는 값이다. 후기 판정 하나만 우리가 답한다.
 *
 * @param null|bool $pre   여태 판정 (null 이면 워드커머스가 스스로 본다).
 * @param string    $email 비회원 이메일.
 * @param int       $uid   회원 ID.
 * @param int       $pid   상품 ID.
 * @return null|bool
 */
function bought( $pre, $email = '', $uid = 0, $pid = 0 ) {
	if ( null !== $pre || ! verified_only() || ! function_exists( 'wc_get_orders' ) ) {
		return $pre;
	}
	$uid = (int) $uid;
	$pid = (int) $pid;
	if ( $uid <= 0 || $pid <= 0 ) {
		return false; // 비회원 — 이 가게는 비로그인 결제가 안 된다.
	}

	static $memo = array();
	$key = $uid . ':' . $pid;
	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}

	$orders = wc_get_orders(
		array(
			'customer_id' => $uid,
			'status'      => bought_statuses(),
			'limit'       => 50,
			'return'      => 'objects',
		)
	);
	$found = false;
	foreach ( (array) $orders as $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			continue;
		}
		foreach ( (array) $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			if ( (int) $item->get_product_id() === $pid || (int) $item->get_variation_id() === $pid ) {
				$found = true;
				break 2;
			}
		}
	}

	$memo[ $key ] = $found;
	return $found;
}
add_filter( 'woocommerce_pre_customer_bought_product', __NAMESPACE__ . '\\bought', 10, 4 );

/**
 * 폼을 감추는 것만으로는 모자란다 — **보내는 것도 막는다.**
 *
 * 워드커머스는 안 산 사람에게 폼을 안 그릴 뿐이라, 주소만 알면 그대로 보낼 수 있다.
 * 상품 후기일 때만, 산 적이 없으면 여기서 멈춘다. 관리자는 지나간다 (답글 · 정리).
 *
 * @param array<string,mixed> $data 들어온 댓글.
 * @return array<string,mixed>
 */
function guard_review( $data ) {
	$pid = (int) ( $data['comment_post_ID'] ?? 0 );
	if ( ! verified_only() || $pid <= 0 || 'product' !== get_post_type( $pid ) ) {
		return $data;
	}
	if ( ! function_exists( 'wc_customer_bought_product' ) || current_user_can( 'moderate_comments' ) ) {
		return $data;
	}
	$uid   = (int) get_current_user_id();
	$email = (string) ( $data['comment_author_email'] ?? '' );
	if ( wc_customer_bought_product( $email, $uid, $pid ) ) {
		return $data;
	}
	wp_die(
		esc_html( '이 상품을 구매하신 분만 후기를 남길 수 있습니다.' ),
		'',
		array(
			'response'  => 403,
			'back_link' => true,
		)
	);
}
add_filter( 'preprocess_comment', __NAMESPACE__ . '\\guard_review' );
