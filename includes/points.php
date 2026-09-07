<?php
/**
 * 적립금을 쓴 주문의 취소.
 *
 * 사장님이 확인한 것(2026-09-07): **적립금을 써서 주문한 뒤 취소하면 그 적립금이
 * 돌아오지 않는다.** 주문은 취소되는데 쓴 적립금은 사라진다.
 *
 * 우리 플러그인이 만든 문제는 아니다. 적립금을 빼는 쪽도 되돌리는 쪽도 테마
 * (`#wd_point_discount`) 와 keyple-customer 이고, 우리는 거기에 손대지 않는다.
 * 다만 `woocommerce_valid_order_statuses_for_cancel` 로 **입금전(on-hold) 주문에
 * 취소 버튼을 연 것이 우리**라, 손님이 스스로 눌러 적립금을 잃을 수 있는 길을
 * 만든 것도 우리다. 그 길을 막는다.
 *
 * 여기서 적립금을 **직접 돌려주지 않는다.** keyple-customer 는 잔액만이 아니라
 * 적립금 내역(원장)을 따로 쌓는다. 우리가 회원 메타에 숫자만 더하면 내역에 없는
 * 돈이 생겨 정산이 어긋난다. 저장 키도 공개돼 있지 않다. 그래서 이 파일은
 *
 *   1. 그 주문이 적립금을 얼마나 썼는지 **읽고**,
 *   2. 적립금을 쓴 주문은 손님이 혼자 취소하지 못하게 막고 문의로 보내며,
 *   3. 그래도 취소·환불로 넘어가면 **주문 메모**를 남겨 사람이 놓치지 않게 한다.
 *
 * 정확한 저장 키를 알게 되면 한 줄로 못 박는다:
 *
 *   add_filter( 'duckhoo_order_points_used', fn( $v, $o ) => (float) $o->get_meta( '<키>' ), 10, 2 );
 *
 * 자동 반환까지 켜려면 그때 keyple 쪽 적립 함수를 부르는 코드를 여기 더한다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Points;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 주문 메타에서 먼저 볼 키. 테마 결제 폼의 입력칸이 `wd_point_discount` 라
 * 주문에도 같은 이름으로 실릴 가능성이 가장 높다.
 *
 * @return string[]
 */
function meta_keys(): array {
	return (array) apply_filters( 'duckhoo_order_points_meta_keys', array(
		'_wd_point_discount',
		'wd_point_discount',
		'_wd_used_point',
		'_point_discount',
		'point_discount',
		'_used_point',
		'_keyple_point_used',
	) );
}

/**
 * 이 주문이 쓴 적립금(원). 못 찾으면 0.
 *
 * 값을 만들어 내지 않는다 — 주문에 실제로 실린 것만 읽는다. 적립금은 결제 화면에서
 * 할인처럼 붙으므로 (1) 지정 메타 → (2) 수수료 줄(음수) → (3) 쿠폰 줄 →
 * (4) 이름이 "적립금 … 사용/할인" 인 메타 순으로 본다. 적립(earn)은 세지 않는다 —
 * 쓴 것만 돌려줄 대상이다.
 *
 * @param \WC_Order|mixed $order 주문.
 * @return float
 */
function used( $order ): float {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return 0.0;
	}

	$given = apply_filters( 'duckhoo_order_points_used', null, $order );
	if ( is_numeric( $given ) ) {
		return abs( (float) $given );
	}

	if ( method_exists( $order, 'get_meta' ) ) {
		foreach ( meta_keys() as $key ) {
			$v = $order->get_meta( (string) $key, true );
			if ( is_numeric( $v ) && abs( (float) $v ) > 0 ) {
				return abs( (float) $v );
			}
		}
	}

	// 수수료 줄로 붙는 경우 — "적립금 할인 -3,000원".
	if ( method_exists( $order, 'get_items' ) ) {
		foreach ( (array) $order->get_items( 'fee' ) as $fee ) {
			if ( ! method_exists( $fee, 'get_name' ) ) {
				continue;
			}
			$name = (string) $fee->get_name();
			$amt  = method_exists( $fee, 'get_total' ) ? (float) $fee->get_total() : 0.0;
			if ( is_points_label( $name ) && $amt < 0 ) {
				return abs( $amt );
			}
		}
		foreach ( (array) $order->get_items( 'coupon' ) as $coupon ) {
			if ( ! method_exists( $coupon, 'get_code' ) ) {
				continue;
			}
			if ( ! is_points_label( (string) $coupon->get_code() ) ) {
				continue;
			}
			$amt = method_exists( $coupon, 'get_discount' ) ? (float) $coupon->get_discount() : 0.0;
			if ( abs( $amt ) > 0 ) {
				return abs( $amt );
			}
		}
	}

	// 마지막으로 이름으로 찾는다. **쓴 것**을 가리키는 낱말이 함께 있을 때만 센다 —
	// `_points_earned` 같은 적립 기록을 사용으로 잘못 읽으면 취소를 괜히 막는다.
	if ( method_exists( $order, 'get_meta_data' ) ) {
		foreach ( (array) $order->get_meta_data() as $meta ) {
			$key = '';
			if ( is_object( $meta ) && method_exists( $meta, 'get_data' ) ) {
				$d   = (array) $meta->get_data();
				$key = (string) ( $d['key'] ?? '' );
				$val = $d['value'] ?? null;
			} else {
				continue;
			}
			if ( ! is_numeric( $val ) || abs( (float) $val ) <= 0 ) {
				continue;
			}
			if ( ! preg_match( '/(point|mileage|적립)/i', $key ) ) {
				continue;
			}
			if ( ! preg_match( '/(use[ds]?|used|discount|사용|할인)/i', $key ) ) {
				continue;
			}
			// `_wd_point_discount_applied` 처럼 켜짐/꺼짐만 담은 칸은 금액이 아니다.
			// 그것을 금액으로 읽으면 사용액이 1원이 된다.
			if ( preg_match( '/(applied|flag|enabled|_at)$/i', $key ) ) {
				continue;
			}
			return abs( (float) $val );
		}
	}

	return 0.0;
}

/**
 * 이 이름표가 적립금을 가리키는가.
 *
 * @param string $label 이름.
 * @return bool
 */
function is_points_label( string $label ): bool {
	return (bool) preg_match( '/(적립금|포인트|point|mileage)/iu', $label );
}

/**
 * 적립금을 쓴 주문은 손님이 혼자 취소하지 못하게 할지. 기본은 막는다.
 *
 * 자동 반환이 붙으면 한 줄로 끈다:
 *   add_filter( 'duckhoo_block_cancel_with_points', '__return_false' );
 *
 * @return bool
 */
function blocks_cancel(): bool {
	return (bool) apply_filters( 'duckhoo_block_cancel_with_points', true );
}

/**
 * 우리가 취소 가능 목록에 더한 상태들. 본체가 없으면 입금전(on-hold) 하나로 본다.
 *
 * @return string[]
 */
function our_statuses(): array {
	return function_exists( 'Duckhoo\\Redesign\\customer_cancellable_statuses' )
		? \Duckhoo\Redesign\customer_cancellable_statuses()
		: array( 'on-hold' );
}

/**
 * 취소 가능 상태에서 우리가 더한 것을 도로 뺍니다 — 적립금을 쓴 주문에 한해서.
 *
 * WooCommerce 기본(pending·failed)은 건드리지 않는다. 우리가 연 입금전(on-hold)만
 * 닫는다. 무통장입금 주문은 전부 on-hold 로 들어오므로 사실상 그 주문의 취소
 * 버튼이 사라지고, 아래 `inquiry_action()` 이 문의 버튼을 대신 세운다.
 *
 * @param array           $statuses 취소 가능 상태 슬러그 목록.
 * @param \WC_Order|mixed $order    주문.
 * @return array
 */
function close_cancel_for_point_orders( $statuses, $order = null ): array {
	$statuses = (array) $statuses;

	if ( ! blocks_cancel() || null === $order || used( $order ) <= 0 ) {
		return $statuses;
	}

	return array_values( array_diff( $statuses, our_statuses() ) );
}
add_filter( 'woocommerce_valid_order_statuses_for_cancel', __NAMESPACE__ . '\\close_cancel_for_point_orders', 20, 2 );

/**
 * 주문내역에 문의 버튼을 세웁니다 — 취소 버튼이 없어진 자리를 비워 두지 않기 위해.
 *
 * 손님 눈에는 "취소가 안 된다" 가 아니라 "여기로 말하면 된다" 가 보여야 한다.
 *
 * @param array           $actions 주문 한 건의 동작 목록.
 * @param \WC_Order|mixed $order   주문.
 * @return array
 */
function inquiry_action( $actions, $order = null ): array {
	$actions = (array) $actions;

	if ( ! blocks_cancel() || null === $order || isset( $actions['cancel'] ) || used( $order ) <= 0 ) {
		return $actions;
	}
	if ( ! function_exists( 'Duckhoo\\Redesign\\Front\\inquiry_url' ) ) {
		return $actions;
	}
	if ( ! method_exists( $order, 'has_status' ) || ! $order->has_status( our_statuses() ) ) {
		return $actions;
	}

	$actions['duckhoo-cancel-ask'] = array(
		'url'  => \Duckhoo\Redesign\Front\inquiry_url(),
		'name' => __( '취소 문의', 'duckhoo-redesign' ),
	);

	return $actions;
}
add_filter( 'woocommerce_my_account_my_orders_actions', __NAMESPACE__ . '\\inquiry_action', 20, 2 );

/**
 * 적립금을 쓴 주문이 취소·환불로 넘어가면 주문 메모를 남깁니다.
 *
 * 적립금을 우리가 돌려주지는 않는다 — 얼마를 누구에게 돌려줘야 하는지만
 * 주문 화면에 적어 둔다. 관리자가 손으로 취소한 경우에도 남는다.
 * 한 번만 남긴다 (`_duckhoo_points_note`).
 *
 * @param int             $order_id 주문 번호.
 * @param \WC_Order|mixed $order    주문.
 * @return void
 */
function note_on_cancel( $order_id, $order = null ): void {
	if ( ( null === $order || ! is_object( $order ) ) && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! is_object( $order ) || ! method_exists( $order, 'add_order_note' ) ) {
		return;
	}
	if ( method_exists( $order, 'get_meta' ) && $order->get_meta( '_duckhoo_points_note', true ) ) {
		return;
	}

	$amount = used( $order );
	if ( $amount <= 0 ) {
		return;
	}

	$order->add_order_note( sprintf(
		/* translators: %s: 적립금 금액 */
		__( '이 주문은 적립금 %s원을 사용했습니다. 취소·환불된 적립금은 자동으로 돌아가지 않습니다 — 회원 적립금을 확인해 주세요. (액상덕후 리디자인)', 'duckhoo-redesign' ),
		function_exists( 'number_format_i18n' ) ? number_format_i18n( $amount ) : (string) $amount
	) );

	if ( method_exists( $order, 'update_meta_data' ) ) {
		$order->update_meta_data( '_duckhoo_points_note', current_time( 'mysql' ) );
		$order->save();
	}
}
add_action( 'woocommerce_order_status_cancelled', __NAMESPACE__ . '\\note_on_cancel', 10, 2 );
add_action( 'woocommerce_order_status_refunded', __NAMESPACE__ . '\\note_on_cancel', 10, 2 );
