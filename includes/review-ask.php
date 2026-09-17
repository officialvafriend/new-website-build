<?php
/**
 * 후기를 **부른다**.
 *
 * 사장님(2026-09-17): 「후기를 안 적어주니깐… 고객들이」.
 *
 * 그런데 배포 일주일이 지나도 후기가 0인 이유는 손님이 게으른 것이 아니다.
 * **한 번도 부탁한 적이 없다.** 지금 후기를 쓸 수 있는 길은 상품 상세 맨 아래
 * 한 곳뿐이고, 물건을 받은 손님은 그 페이지에 다시 들어올 일이 없다. 사진 후기에
 * 1,000원을 준다는 것도 그 폼 안에만 적혀 있어서, 폼까지 간 사람만 알 수 있다.
 * **혜택이 있는 줄 모르는 사람에게는 혜택이 없는 것과 같다.**
 *
 * 그래서 물건을 받은 손님이 **이미 서 있는 자리**에서 부른다:
 *
 *   1. 주문 상세 — 그 주문에 든 상품마다 `후기 쓰기` (이미 쓴 것은 고맙다고 적는다)
 *   2. 주문 목록 — 배송완료 카드에 `후기 쓰고 적립금` 버튼
 *   3. 마이페이지 첫 화면 — 아직 한 번도 안 쓴 손님에게 한 줄
 *
 * **읽기만 한다.** 주문 · 후기 · 적립금에 한 글자도 쓰지 않는다 — 돈이 오가는 쪽은
 * `includes/review-photos.php` 가 그대로 쥐고 있고, 여기는 길만 놓는다.
 *
 * 끄기: `add_filter( 'duckhoo_review_ask_on', '__return_false' );`
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\ReviewAsk;

defined( 'ABSPATH' ) || exit;

/**
 * 부를 것인가.
 *
 * 후기 자체가 꺼져 있으면 부르지 않는다 — 눌러도 쓸 자리가 없다.
 *
 * @return bool
 */
function on(): bool {
	if ( ! function_exists( 'Duckhoo\\Redesign\\Product\\reviews_on' ) || ! \Duckhoo\Redesign\Product\reviews_on() ) {
		return false;
	}
	return (bool) apply_filters( 'duckhoo_review_ask_on', true );
}

/**
 * 사진 후기에 주는 적립금. 0 이면 돈 이야기를 하지 않는다.
 *
 * @return int
 */
function points(): int {
	if ( ! function_exists( 'Duckhoo\\Redesign\\ReviewPhotos\\on' ) || ! \Duckhoo\Redesign\ReviewPhotos\on() ) {
		return 0;
	}
	return (int) \Duckhoo\Redesign\ReviewPhotos\reward();
}

/**
 * 「받은」 주문 상태 — 후기 판정과 **같은 목록을 쓴다.**
 *
 * 다른 목록을 쓰면 「후기 쓰기」를 눌렀는데 워드커머스가 구매자로 안 봐서 폼이
 * 안 나오는 일이 생긴다. 한 곳에서 정해야 한다.
 *
 * @return string[]
 */
function statuses(): array {
	if ( function_exists( 'Duckhoo\\Redesign\\Product\\bought_statuses' ) ) {
		return (array) \Duckhoo\Redesign\Product\bought_statuses();
	}
	return array( 'delivered', 'completed', 'processing' );
}

/**
 * 이 주문이 「받은」 주문인가.
 *
 * @param \WC_Order|mixed $order 주문.
 * @return bool
 */
function received( $order ): bool {
	return is_object( $order ) && method_exists( $order, 'has_status' ) && $order->has_status( statuses() );
}

/**
 * 이 회원이 후기를 쓴 상품 번호들. **질의는 한 번뿐이다** (한 화면에 여러 주문이 있다).
 *
 * @param int $uid 회원 번호.
 * @return array<int,bool> 상품 번호 => true
 */
function reviewed( int $uid ): array {
	static $memo = array();
	if ( isset( $memo[ $uid ] ) ) {
		return $memo[ $uid ];
	}
	$out = array();
	if ( $uid > 0 && function_exists( 'get_comments' ) ) {
		$rows = (array) get_comments( array(
			'user_id' => $uid,
			'type'    => 'review',
			'status'  => 'all',
			'number'  => 200,
		) );
		foreach ( $rows as $c ) {
			$pid = (int) ( is_object( $c ) && isset( $c->comment_post_ID ) ? $c->comment_post_ID : 0 );
			if ( $pid > 0 ) {
				$out[ $pid ] = true;
			}
		}
	}
	$memo[ $uid ] = $out;

	return $out;
}

/**
 * 주문에 든 상품들 — 번호 => 이름. 같은 상품이 여러 줄이어도 한 번만.
 *
 * 주문 항목에 적힌 이름이 아니라 **지금 상품의 이름**을 쓴다. 후기를 쓰러 갈 화면의
 * 제목과 같아야 손님이 같은 상품인지 안다 (옵션 이름 바꾸기에서 옛 주문은 그때 이름을
 * 그대로 둔다 — 그건 주문 기록이라 맞고, 여기는 가는 길이라 지금 이름이 맞다).
 *
 * @param \WC_Order|mixed $order 주문.
 * @return array<int,string>
 */
function products( $order ): array {
	$out = array();
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
		return $out;
	}
	foreach ( (array) $order->get_items() as $item ) {
		$pid = 0;
		if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
			$pid = (int) $item->get_product_id();
		}
		if ( $pid <= 0 || isset( $out[ $pid ] ) ) {
			continue;
		}
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! is_object( $p ) || ! method_exists( $p, 'get_name' ) ) {
			continue;
		}
		$out[ $pid ] = (string) $p->get_name();
	}

	return $out;
}

/**
 * 후기 폼으로 곧장 가는 주소.
 *
 * `#respond` 는 워드프레스 댓글 폼의 자리다 — 상품 페이지 맨 아래라 눌러 들어가면
 * 바로 쓸 자리가 보인다. (카드의 `구매하기` 를 상세 중간으로 열었다가 사진도 가격도
 * 안 보여서 뺀 적이 있는데, 여기는 **그 자리로 가는 것이 목적**이라 반대다.)
 *
 * @param int $pid 상품 번호.
 * @return string
 */
function write_url( int $pid ): string {
	$url = (string) get_permalink( $pid );
	return '' === $url ? '' : $url . '#respond';
}

/**
 * 「사진 후기 1,000원」 한 줄. 적립금이 0 이면 돈 이야기를 빼고 말한다.
 *
 * @return string
 */
function offer(): string {
	$p = points();
	if ( $p <= 0 ) {
		return '받아 보신 상품, 한 줄 남겨 주시면 다음 손님에게 큰 도움이 됩니다.';
	}
	return '사진 한 장과 함께 남겨 주시면 확인 후 ' . number_format_i18n( $p ) . '원 적립해 드립니다.';
}

/**
 * 주문 상세 아래 — 그 주문에 든 상품마다 한 줄.
 *
 * @param \WC_Order|mixed $order 주문.
 * @return void
 */
function details( $order = null ): void {
	static $done = array();

	if ( is_numeric( $order ) ) {
		$order = wc_get_order( (int) $order );
	}
	if ( ! on() || ! received( $order ) || ! is_user_logged_in() ) {
		return;
	}
	$id = (int) $order->get_id();
	if ( isset( $done[ $id ] ) ) {
		return;
	}

	$items = products( $order );
	if ( ! $items ) {
		return;
	}
	$done[ $id ] = true;

	$mine = reviewed( (int) get_current_user_id() );
	$left = 0;
	foreach ( array_keys( $items ) as $pid ) {
		$left += isset( $mine[ $pid ] ) ? 0 : 1;
	}

	echo '<section class="dhr-ask" aria-labelledby="dhr-ask-t">';
	echo '<h2 id="dhr-ask-t" class="dhr-ask__t">' . ( $left > 0 ? '후기 남기기' : '후기 고맙습니다' ) . '</h2>';
	if ( $left > 0 ) {
		echo '<p class="dhr-ask__d">' . esc_html( offer() ) . '</p>';
	}
	echo '<ul class="dhr-ask__l">';
	foreach ( $items as $pid => $name ) {
		$url = write_url( (int) $pid );
		echo '<li class="dhr-ask__i"><span class="dhr-ask__n">' . esc_html( $name ) . '</span>';
		if ( isset( $mine[ $pid ] ) ) {
			echo '<span class="dhr-ask__done">작성함</span>';
		} elseif ( '' !== $url ) {
			echo '<a class="dhr-ask__go" href="' . esc_url( $url ) . '">후기 쓰기</a>';
		}
		echo '</li>';
	}
	echo '</ul></section>';
}
add_action( 'woocommerce_order_details_after_order_table', __NAMESPACE__ . '\\details', 20 );

/**
 * 주문 목록의 버튼.
 *
 * **여기서는 상품 줄을 열지 않는다** — 한 화면에 열 건이 있어 주문마다 질의가
 * 한 번씩 더 나간다. 배송완료 주문이면 버튼을 세우고, 무엇을 아직 안 썼는지는
 * 주문 상세가 말한다.
 *
 * @param array           $actions 동작 목록.
 * @param \WC_Order|mixed $order   주문.
 * @return array
 */
function action( $actions, $order = null ): array {
	$actions = (array) $actions;
	if ( ! on() || ! received( $order ) || ! method_exists( $order, 'get_view_order_url' ) ) {
		return $actions;
	}

	$actions['duckhoo-review'] = array(
		'url'  => (string) $order->get_view_order_url(),
		'name' => points() > 0 ? '후기 쓰고 적립금' : '후기 쓰기',
	);

	return $actions;
}
add_filter( 'woocommerce_my_account_my_orders_actions', __NAMESPACE__ . '\\action', 40, 2 );

/**
 * 마이페이지 첫 화면 — **아직 한 번도 안 쓴 손님에게만** 한 줄.
 *
 * 이미 쓴 사람에게 또 부르면 잔소리가 된다.
 *
 * @return void
 */
function dashboard(): void {
	if ( ! on() || ! is_user_logged_in() || ! function_exists( 'wc_get_orders' ) ) {
		return;
	}
	$uid = (int) get_current_user_id();
	if ( ! empty( reviewed( $uid ) ) ) {
		return;
	}
	$orders = wc_get_orders( array(
		'customer_id' => $uid,
		'status'      => statuses(),
		'limit'       => 1,
		'return'      => 'ids',
		'type'        => 'shop_order',
	) );
	if ( ! is_array( $orders ) || ! $orders ) {
		return;
	}

	$url = function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'orders' ) : '';

	echo '<section class="dhr-ask dhr-ask--note">';
	echo '<h2 class="dhr-ask__t">받으신 상품, 어떠셨어요?</h2>';
	echo '<p class="dhr-ask__d">' . esc_html( offer() ) . '</p>';
	if ( '' !== $url ) {
		echo '<a class="dhr-ask__go dhr-ask__go--wide" href="' . esc_url( $url ) . '">주문내역에서 후기 쓰기</a>';
	}
	echo '</section>';
}
add_action( 'woocommerce_account_dashboard', __NAMESPACE__ . '\\dashboard', 5 );
