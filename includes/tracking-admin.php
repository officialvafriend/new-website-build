<?php
/**
 * 도구 → 송장 진단 (관리자).
 *
 * 송장번호가 **어느 주문 메타에 들어 있는지** 이 저장소에서는 알 수 없다 —
 * 송장을 넣는 플러그인(`keyple-order-excel-tracking` · `woocommerce-epost-shipping`)
 * 소스가 여기 없고, 관리자 계정도 없다. 그래서 `Tracking\find()` 는 이름을 훑어
 * 찾아내는데, **정말 그것이 맞는지는 이 화면 한 장으로 가른다.**
 *
 * 최근 주문 몇 건을 열어 (1) 지금 손님에게 무엇이 보이는지, (2) 그 주문의 메타 중
 * 송장처럼 생긴 칸이 무엇 무엇인지를 나란히 놓는다. 캡처 한 장이면 답이 나오고,
 * 맞는 칸을 골라 `못 박기` 를 누르면 그 다음부터는 훑지 않고 그 칸만 읽는다.
 *
 * **읽기만 한다** — 주문에 한 글자도 쓰지 않는다. 쓰는 것은 옵션 두 개
 * (`duckhoo_tracking_meta_key` · `duckhoo_tracking_courier`)뿐이다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Tracking\Admin;

use function Duckhoo\Redesign\Tracking\{find, clean_no, tracking_key, courier_key, couriers, courier_of, pretty, default_courier};
use const Duckhoo\Redesign\Tracking\{PINNED, PINNED_CO};

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-track';

/**
 * 이 화면을 볼 수 있는가.
 *
 * @return bool
 */
function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * 메뉴.
 *
 * @return void
 */
function menu(): void {
	if ( ! may() ) {
		return;
	}
	add_management_page(
		'송장 진단',
		'송장 진단',
		current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 볼 주문 몇 건.
 *
 * 배송이 걸린 상태를 먼저 본다 — 송장이 실제로 붙어 있을 주문이다.
 *
 * @param int $n 건수.
 * @return \WC_Order[]
 */
function orders( int $n = 12 ): array {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$statuses = (array) apply_filters( 'duckhoo_tracking_scan_statuses', array(
		'ready-to-ship', 'shipping', 'delivered', 'completed', 'processing',
	) );

	$found = wc_get_orders( array(
		'limit'   => $n,
		'status'  => $statuses,
		'orderby' => 'date',
		'order'   => 'DESC',
		'type'    => 'shop_order',
	) );
	$found = is_array( $found ) ? $found : array();

	if ( count( $found ) < 3 ) {
		/* 그 상태가 없거나 이름이 다를 수 있다 — 그때는 최근 주문을 그냥 본다. */
		$any = wc_get_orders( array( 'limit' => $n, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
		if ( is_array( $any ) ) {
			$seen = array();
			foreach ( $found as $o ) {
				$seen[ (int) $o->get_id() ] = true;
			}
			foreach ( $any as $o ) {
				if ( ! isset( $seen[ (int) $o->get_id() ] ) ) {
					$found[] = $o;
				}
			}
			$found = array_slice( $found, 0, $n );
		}
	}

	return $found;
}

/**
 * 한 주문의 메타 중 송장 후보.
 *
 * 값이 9~14자리 숫자이거나 이름이 송장 · 택배사를 가리키는 칸을 모두 모은다 —
 * `find()` 가 놓친 칸이 있으면 여기서 보인다.
 *
 * @param \WC_Order|mixed $order 주문.
 * @return array<int, array{key: string, value: string, why: string}>
 */
function candidates( $order ): array {
	$out = array();
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta_data' ) ) {
		return $out;
	}

	foreach ( (array) $order->get_meta_data() as $meta ) {
		$data = method_exists( $meta, 'get_data' ) ? (array) $meta->get_data() : (array) $meta;
		$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
		$val  = $data['value'] ?? null;
		if ( '' === $key || is_array( $val ) || is_object( $val ) ) {
			continue;
		}

		$why = '';
		if ( tracking_key( $key ) ) {
			$why = '이름이 송장';
		} elseif ( courier_key( $key ) ) {
			$why = '이름이 택배사';
		} elseif ( '' !== clean_no( $val ) && preg_match( '/^[0-9\- ]+$/', (string) $val ) ) {
			$why = '값이 숫자 ' . strlen( (string) preg_replace( '/[^0-9]/', '', (string) $val ) ) . '자리';
		}
		if ( '' === $why ) {
			continue;
		}

		$out[] = array(
			'key'   => $key,
			'value' => (string) $val,
			'why'   => $why,
		);
	}

	return $out;
}

/**
 * 못 박기 · 풀기.
 *
 * @return string 화면 위에 적을 한 줄.
 */
function save(): string {
	if ( empty( $_POST['dhr_track_do'] ) || ! may() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return '';
	}
	check_admin_referer( 'dhr-track' );

	$key = isset( $_POST['dhr_track_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['dhr_track_key'] ) ) : '';
	$co  = isset( $_POST['dhr_track_co'] ) ? sanitize_key( wp_unslash( (string) $_POST['dhr_track_co'] ) ) : '';

	if ( '' !== $co && ! isset( couriers()[ $co ] ) ) {
		$co = '';
	}

	update_option( PINNED, $key );
	update_option( PINNED_CO, $co );

	if ( '' === $key && '' === $co ) {
		return '못 박은 것을 풀었습니다. 다시 훑어서 찾습니다.';
	}

	return '저장했습니다. ' . ( '' !== $key ? '이제 `' . $key . '` 칸만 읽습니다. ' : '' ) . ( '' !== $co ? '택배사는 ' . ( couriers()[ $co ]['name'] ?? $co ) . ' 입니다.' : '' );
}

/**
 * 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}

	$said = save();
	$pin  = (string) get_option( PINNED, '' );
	$pco  = (string) get_option( PINNED_CO, '' );
	$list = orders();
	$keys = array();

	echo '<div class="wrap"><h1>송장 진단</h1>';
	echo '<p style="max-width:52em;line-height:1.7">관리자 주문 목록에는 송장번호가 보이는데 손님 화면에 안 나오던 문제를 가르는 화면입니다. 아래 표의 <b>손님에게 보이는 것</b> 칸에 번호가 떠 있으면 손님 주문 상세에도 같은 번호가 보입니다. 비어 있으면 <b>후보</b> 칸에서 맞는 칸을 골라 못 박아 주세요.</p>';

	if ( '' !== $said ) {
		echo '<div class="notice notice-success"><p>' . esc_html( $said ) . '</p></div>';
	}

	if ( ! $list ) {
		echo '<p><b>읽을 주문이 없습니다.</b></p></div>';
		return;
	}

	echo '<table class="widefat striped" style="margin-top:1rem"><thead><tr>';
	echo '<th style="width:9rem">주문</th><th style="width:22rem">손님에게 보이는 것</th><th>후보 (그 주문의 메타)</th>';
	echo '</tr></thead><tbody>';

	foreach ( $list as $order ) {
		$t   = find( $order );
		$cds = candidates( $order );

		echo '<tr><td><a href="' . esc_url( (string) $order->get_edit_order_url() ) . '">#' . esc_html( (string) $order->get_order_number() ) . '</a><br><span style="color:#666">' . esc_html( wc_get_order_status_name( (string) $order->get_status() ) ) . '</span></td>';

		echo '<td>';
		if ( '' !== $t['no'] ) {
			echo '<b style="font-size:15px">' . esc_html( pretty( (string) $t['no'] ) ) . '</b><br>';
			echo esc_html( (string) $t['name'] ) . ' · <code>' . esc_html( '' !== $t['key'] ? (string) $t['key'] : '(훑어서 찾음)' ) . '</code><br>';
			echo '<a href="' . esc_url( (string) $t['url'] ) . '" target="_blank" rel="noopener">조회 주소 열기</a>';
		} else {
			echo '<b style="color:#B32D2E">안 보입니다</b><br><span style="color:#666">이 주문에서 송장으로 볼 만한 칸을 못 찾았습니다.</span>';
		}
		echo '</td>';

		echo '<td>';
		if ( ! $cds ) {
			echo '<span style="color:#666">없음 — 이 주문에는 아직 송장이 안 붙은 것 같습니다.</span>';
		} else {
			echo '<table style="border-collapse:collapse"><tbody>';
			foreach ( $cds as $c ) {
				$keys[ $c['key'] ] = ( $keys[ $c['key'] ] ?? 0 ) + 1;
				echo '<tr><td style="padding:.1rem .6rem .1rem 0"><code>' . esc_html( $c['key'] ) . '</code></td>';
				echo '<td style="padding:.1rem .6rem"><b>' . esc_html( mb_substr( $c['value'], 0, 40 ) ) . '</b></td>';
				echo '<td style="padding:.1rem 0;color:#666">' . esc_html( $c['why'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</td></tr>';
	}

	echo '</tbody></table>';

	/* 못 박기 */
	ksort( $keys );
	echo '<h2 style="margin-top:2rem">못 박기</h2>';
	echo '<p style="max-width:52em;line-height:1.7">맞는 칸을 고르면 그 다음부터는 훑지 않고 그 칸만 읽습니다. 빈칸으로 두면 지금처럼 이름을 보고 찾습니다. <b>주문 데이터는 어느 쪽이든 건드리지 않습니다.</b></p>';
	echo '<form method="post"><input type="hidden" name="dhr_track_do" value="1">';
	wp_nonce_field( 'dhr-track' );

	echo '<p><label>송장번호가 든 칸<br><select name="dhr_track_key" style="min-width:24rem">';
	echo '<option value="">— 훑어서 찾기 (지금) —</option>';
	foreach ( array_keys( $keys ) as $k ) {
		echo '<option value="' . esc_attr( (string) $k ) . '"' . selected( $pin, (string) $k, false ) . '>' . esc_html( (string) $k ) . ' (' . esc_html( (string) $keys[ $k ] ) . '건)</option>';
	}
	if ( '' !== $pin && ! isset( $keys[ $pin ] ) ) {
		echo '<option value="' . esc_attr( $pin ) . '" selected>' . esc_html( $pin ) . ' (지금 못 박힌 값)</option>';
	}
	echo '</select></label></p>';

	echo '<p><label>택배사<br><select name="dhr_track_co" style="min-width:24rem">';
	echo '<option value="">— 주문에서 찾고, 못 찾으면 ' . esc_html( (string) ( couriers()[ default_courier() ]['name'] ?? '우체국택배' ) ) . ' —</option>';
	foreach ( couriers() as $slug => $co ) {
		echo '<option value="' . esc_attr( (string) $slug ) . '"' . selected( $pco, (string) $slug, false ) . '>' . esc_html( (string) $co['name'] ) . '</option>';
	}
	echo '</select></label></p>';

	submit_button( '저장' );
	echo '</form></div>';
}
