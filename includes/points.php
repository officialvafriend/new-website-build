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
 * **어디에 저장되는지는 2026-09-07 사이트 진단으로 확인했다** (도구 → 적립금 진단):
 *
 *   잔액   회원 메타 `_keyple_points` (가입 적립분 몫은 `_wd_signup_point_balance`)
 *   원장   표 `wp_keyple_points_log` — `wd_log_keyple_points_change()` 가 줄을 넣는다
 *   사용액 주문 메타 `_wd_point_discount` (+ 실제로 빠졌다는 표시 `_wd_point_discount_applied`)
 *
 * 그리고 `woocommerce_order_status_cancelled` · `_refunded` 에 걸린 것을 다 세어 봤는데
 * **적립금을 되돌리는 것이 하나도 없었다** (쿠폰 사용횟수 · 재고 · 기프트카드뿐).
 * 그래서 이 파일이 그 자리를 채운다:
 *
 *   1. 그 주문이 적립금을 얼마나 썼는지 **읽고**,
 *   2. 취소·환불되면 잔액과 원장에 **둘 다** 되돌려 놓고 (`return_points()`),
 *   3. 되돌릴 수 없는 상태(테마 함수가 없다)면 취소를 막고 **주문 메모**를 남긴다.
 *
 * 잔액만 늘리면 내역에 없는 돈이 생기고, 원장만 남기면 회원 화면의 숫자가 안 바뀐다.
 * 그래서 테마가 결제 때 빼는 방식(`functions.php:3235-3237`)을 그대로 뒤집는다.
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
	return (bool) apply_filters( 'duckhoo_block_cancel_with_points', ! can_return() );
}

/**
 * 적립금을 돌려줄 수 있는 상태인가.
 *
 * 테마가 쓰는 두 가지가 다 있어야 한다 — 잔액의 단일 소스가 `_keyple_points` 라는 것
 * (`wd_is_keyple_crm_active()`)과, 원장에 줄을 남기는 함수(`wd_log_keyple_points_change()`).
 * 하나라도 없으면 우리는 손대지 않고, 대신 취소를 막고 주문 메모만 남긴다.
 *
 * @return bool
 */
function can_return(): bool {
	$ok = function_exists( 'wd_log_keyple_points_change' )
		&& function_exists( 'wd_is_keyple_crm_active' )
		&& wd_is_keyple_crm_active();

	return (bool) apply_filters( 'duckhoo_can_return_points', $ok );
}

/**
 * 적립금을 **더하거나 뺀다** — 잔액과 원장을 같이.
 *
 * 취소 반환(`return_points()`)이 쓰던 두 줄을 밖으로 뺀 것이다. 사진 후기 적립도
 * 같은 길로 지나가야 정산이 어긋나지 않는다. 회원 메타에 숫자만 더하면 원장에 없는
 * 돈이 생긴다.
 *
 * @param int    $uid    회원 ID.
 * @param int    $amount 더할 금액 (음수면 뺀다).
 * @param string $label  원장에 남길 사유.
 * @return bool 실제로 건드렸는가.
 */
function grant( int $uid, int $amount, string $label ): bool {
	if ( $uid <= 0 || 0 === $amount || ! can_return() ) {
		return false;
	}
	$before = (int) get_user_meta( $uid, '_keyple_points', true );
	$after  = max( 0, $before + $amount );
	if ( $after === $before ) {
		return false; // 뺄 것이 없다 — 잔액을 음수로 만들지 않는다.
	}
	update_user_meta( $uid, '_keyple_points', $after );
	wd_log_keyple_points_change( $uid, $after - $before, $label );
	return true;
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
/**
 * 취소·환불된 주문이 쓴 적립금을 회원에게 돌려줍니다.
 *
 * 테마가 결제 때 빼는 방식을 **그대로 뒤집는다** (`functions.php:3235-3237`):
 *
 *     $current = (int) get_user_meta( $uid, '_keyple_points', true );
 *     update_user_meta( $uid, '_keyple_points', max( 0, $current - $used ) );
 *     wd_log_keyple_points_change( $uid, -$used, $label );
 *
 * 잔액(`_keyple_points`)과 원장(`wp_keyple_points_log`)을 **둘 다** 건드린다. 하나만
 * 고치면 정산이 어긋난다 — 잔액만 늘리면 내역에 없는 돈이 생기고, 원장만 남기면
 * 회원 화면의 숫자가 안 바뀐다.
 *
 * 가입 적립금 몫(`_wd_signup_point_balance`)은 주문에 기록이 남지 않는다. 그래서
 * **가입 적립금 주머니에서 비어 있는 만큼만** 되돌린다 — 지급액을 넘지 않으므로
 * 쓰지 않은 몫이 부풀지 않는다. 실제 두 경우로 확인했다: 8,800 을 가입 적립금으로
 * 쓴 주문은 8,800 이 그대로 돌아오고, 일반 적립금 5,000 을 쓴 주문은 0 이 돌아온다.
 *
 * 한 번만 돌려준다 (`_duckhoo_points_returned`). 실제로 빠진 적이 없는 주문
 * (`_wd_point_discount_applied` 가 없다)은 건너뛴다.
 *
 * @param \WC_Order|mixed $order 주문.
 * @return int 돌려준 금액. 0 이면 아무것도 하지 않았다.
 */
function return_points( $order ): int {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! can_return() ) {
		return 0;
	}
	if ( $order->get_meta( '_duckhoo_points_returned', true ) ) {
		return 0; // 이미 돌려줬다.
	}
	if ( ! $order->get_meta( '_wd_point_discount_applied', true ) ) {
		return 0; // 잔액에서 빠진 적이 없는 주문이다.
	}

	$amount = (int) round( used( $order ) );
	$uid    = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
	if ( $amount <= 0 || $uid <= 0 ) {
		return 0;
	}

	$number = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order->get_id();
	$label  = sprintf(
		/* translators: %s: 주문 번호 */
		__( '주문 #%s 취소 적립금 반환', 'duckhoo-redesign' ),
		$number
	);

	$before = (int) get_user_meta( $uid, '_keyple_points', true );
	update_user_meta( $uid, '_keyple_points', $before + $amount );
	wd_log_keyple_points_change( $uid, $amount, $label );

	// 가입 적립금 주머니 — 비어 있는 만큼만, 지급액을 넘지 않게.
	$grant = function_exists( 'wd_signup_point_amount' ) ? (int) wd_signup_point_amount() : 8800;
	$pot   = (int) get_user_meta( $uid, '_wd_signup_point_balance', true );
	$back  = max( 0, min( $amount, $grant - $pot ) );
	if ( $back > 0 ) {
		update_user_meta( $uid, '_wd_signup_point_balance', $pot + $back );
	}

	$order->update_meta_data( '_duckhoo_points_returned', $amount );
	$order->update_meta_data( '_duckhoo_points_returned_at', current_time( 'mysql' ) );
	$order->add_order_note( sprintf(
		/* translators: 1: 반환 금액, 2: 이전 잔액, 3: 새 잔액 */
		__( '적립금 %1$s원을 회원에게 돌려주었습니다. (잔액 %2$s원 → %3$s원)', 'duckhoo-redesign' ),
		number_format_i18n( $amount ),
		number_format_i18n( $before ),
		number_format_i18n( $before + $amount )
	) );
	$order->save();

	return $amount;
}

/**
 * 취소·환불 때 도는 자리. 돌려줄 수 있으면 돌려주고, 못 하면 메모만 남깁니다.
 *
 * @param int             $order_id 주문 번호.
 * @param \WC_Order|mixed $order    주문.
 * @return void
 */
function on_cancel( $order_id, $order = null ): void {
	if ( ( null === $order || ! is_object( $order ) ) && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! is_object( $order ) ) {
		return;
	}
	if ( return_points( $order ) > 0 ) {
		return;
	}
	note_on_cancel( $order_id, $order );
}
add_action( 'woocommerce_order_status_cancelled', __NAMESPACE__ . '\\on_cancel', 10, 2 );
add_action( 'woocommerce_order_status_refunded', __NAMESPACE__ . '\\on_cancel', 10, 2 );
