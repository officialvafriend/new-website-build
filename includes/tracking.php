<?php
/**
 * 송장번호를 손님에게 보여 준다.
 *
 * 사장님 신고(2026-09-17): **관리자 주문 목록에는 「우체국 + 13자리」가 보이는데
 * 손님 화면에는 「배송중」이라는 낱말 하나뿐이고 송장번호가 없다.** 물건이 어디쯤
 * 왔는지 알 길이 없으니 손님은 전화로 묻거나, 그냥 기다린다.
 *
 * 게다가 우리가 만든 `/shipping/` 안내에 이렇게 적혀 있다 —
 * 「송장번호가 등록되면 주문내역에서 조회하실 수 있습니다.」 **그 약속이 지켜지지
 * 않고 있었다.** 이 파일이 그 자리를 채운다.
 *
 * 하는 일은 **읽고 그리는 것뿐이다.**
 *   · 주문에 실린 송장번호를 찾아 (`find()`)
 *   · 주문 상세 맨 위에 택배사 · 번호 · 복사 · 배송조회 링크를 그리고
 *   · 주문 목록(마이페이지)에 `배송조회` 버튼을 세운다
 *
 * **주문 데이터에 한 글자도 쓰지 않는다.** 송장을 넣는 쪽은 그대로 키플 ·
 * 우체국 플러그인(`keyple-order-excel-tracking` · `woocommerce-epost-shipping`)이고,
 * 그 파일은 건드리지 않는다.
 *
 * ## 저장 위치를 모르는 채로 짓는다
 *
 * 송장번호가 **어느 주문 메타에 있는지 이 저장소에서는 알 수 없다** (플러그인 소스가
 * 여기 없고, 스테이징 관리자 계정도 없다). 그래서 적립금(`Points\used()`)과 옵션
 * 이름 바꾸기(`Opt\Admin\scan()`)에서 쓴 것과 같은 방법으로 **찾아낸다**:
 *
 *   1. 사장님이 못 박아 둔 값 (옵션 `duckhoo_tracking_meta_key` · 필터)
 *   2. 이름이 알려진 메타 키 (`meta_keys()`)
 *   3. 그래도 없으면 **주문 메타를 훑어** 이름이 송장처럼 생기고 값이 9~14자리
 *      숫자인 칸을 찾는다 (`scan()`)
 *
 * 3번이 있어서 키 이름을 몰라도 화면에는 번호가 뜬다. 어느 칸에서 나왔는지는
 * `도구 → 송장 진단`(`includes/tracking-admin.php`)이 적어 주므로, 확인되면
 * 1번으로 못 박아 스캔을 건너뛴다.
 *
 * **번호를 만들어 내지 않는다.** 못 찾으면 아무것도 그리지 않는다 — 없는 송장을
 * 「곧 등록됩니다」로 채우면 손님이 다시 들어와서 또 없는 것을 본다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Tracking;

defined( 'ABSPATH' ) || exit;

/** 사장님이 진단 화면에서 못 박은 메타 키. */
const PINNED = 'duckhoo_tracking_meta_key';

/** 택배사를 못 박은 옵션 (비어 있으면 주문에서 찾고, 그래도 없으면 기본값). */
const PINNED_CO = 'duckhoo_tracking_courier';

/**
 * 송장번호가 들어 있을 만한 주문 메타 키. 위에서부터 본다.
 *
 * 이 가게에 실제로 걸린 플러그인(`keyple-order-excel-tracking` ·
 * `woocommerce-epost-shipping`)과 국내 배송 플러그인들이 흔히 쓰는 이름이다.
 *
 * @return string[]
 */
function meta_keys(): array {
	return (array) apply_filters( 'duckhoo_tracking_meta_keys', array(
		'_keyple_tracking_number',
		'_keyple_invoice_no',
		'_wd_tracking_number',
		'_wd_invoice_no',
		'_epost_invoice_no',
		'_epost_regi_no',
		'_tracking_number',
		'_invoice_number',
		'_invoice_no',
		'_delivery_number',
		'_shipping_number',
		'_waybill_no',
		'tracking_number',
		'invoice_no',
		'송장번호',
		'운송장번호',
	) );
}

/**
 * 택배사를 적어 둘 만한 주문 메타 키.
 *
 * @return string[]
 */
function courier_keys(): array {
	return (array) apply_filters( 'duckhoo_tracking_courier_keys', array(
		'_keyple_tracking_company',
		'_keyple_courier',
		'_wd_tracking_company',
		'_tracking_provider',
		'_tracking_company',
		'_shipping_company',
		'_delivery_company',
		'_courier',
		'택배사',
	) );
}

/**
 * 택배사 목록 — 이름 · 조회 주소 · 알아보는 말.
 *
 * 조회 주소는 둘 다 실제로 열리는 것을 확인했다 (2026-09-17, HTTP 200).
 *
 * @return array<string, array{name: string, url: string, marks: string[]}>
 */
function couriers(): array {
	return (array) apply_filters( 'duckhoo_tracking_couriers', array(
		'epost'  => array(
			'name'  => '우체국택배',
			'url'   => 'https://service.epost.go.kr/trace.RetrieveDomRigiTraceList.comm?sid1=%s&displayHeader=N',
			'marks' => array( '우체국', '우편', 'epost', 'post' ),
		),
		'cj'     => array(
			'name'  => 'CJ대한통운',
			'url'   => 'https://trace.cjlogistics.com/next/tracking.html?wblNo=%s',
			'marks' => array( 'cj', '대한통운' ),
		),
		'hanjin' => array(
			'name'  => '한진택배',
			'url'   => 'https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?mCode=MN038&schLang=KR&wblnumText2=%s',
			'marks' => array( '한진', 'hanjin' ),
		),
		'logen'  => array(
			'name'  => '로젠택배',
			'url'   => 'https://www.ilogen.com/web/personal/trace/%s',
			'marks' => array( '로젠', 'logen' ),
		),
		'lotte'  => array(
			'name'  => '롯데택배',
			'url'   => 'https://www.lotteglogis.com/home/reservation/tracking/linkView?InvNo=%s',
			'marks' => array( '롯데', 'lotte', '현대택배' ),
		),
	) );
}

/**
 * 택배사를 못 찾았을 때 쓰는 기본값. 이 가게는 우체국택배다 (`/shipping/` 안내).
 *
 * @return string
 */
function default_courier(): string {
	$pinned = (string) get_option( PINNED_CO, '' );
	$slug   = '' !== $pinned ? $pinned : 'epost';

	return (string) apply_filters( 'duckhoo_tracking_default_courier', $slug );
}

/**
 * 글자에서 택배사 슬러그를 알아봅니다. 못 알아보면 빈 문자열.
 *
 * @param string $text 택배사 이름 · 배송방법 이름 등.
 * @return string
 */
function courier_of( string $text ): string {
	$low = strtolower( $text );
	if ( '' === trim( $low ) ) {
		return '';
	}
	foreach ( couriers() as $slug => $co ) {
		foreach ( (array) $co['marks'] as $mark ) {
			if ( false !== strpos( $low, strtolower( (string) $mark ) ) ) {
				return (string) $slug;
			}
		}
	}

	return '';
}

/**
 * 송장번호처럼 생긴 값인가 — 숫자만 9~14자리.
 *
 * 하이픈 · 빈칸은 떼고 본다 (`6890-1748-16619` 처럼 적어 두는 경우가 있다).
 * 못 믿을 값(주문번호 · 금액 · 날짜)이 섞이지 않게 **키 이름이 송장을 가리킬 때만**
 * 이 검사를 쓴다.
 *
 * @param mixed $value 값.
 * @return string 정리된 번호. 송장이 아니면 빈 문자열.
 */
function clean_no( $value ): string {
	if ( is_array( $value ) || is_object( $value ) || null === $value ) {
		return '';
	}
	$raw = preg_replace( '/[^0-9]/', '', (string) $value );
	$raw = is_string( $raw ) ? $raw : '';
	$len = strlen( $raw );

	return ( $len >= 9 && $len <= 14 ) ? $raw : '';
}

/**
 * 이름이 송장을 가리키는 키인가.
 *
 * @param string $key 메타 키.
 * @return bool
 */
function tracking_key( string $key ): bool {
	return (bool) preg_match(
		'/(track|invoice|waybill|송장|운송장|배송번호|등기번호|delivery[_\-]?(no|num)|shipping[_\-]?(no|num)|regi[_\-]?no|sid1)/iu',
		$key
	);
}

/**
 * 이름이 택배사를 가리키는 키인가.
 *
 * @param string $key 메타 키.
 * @return bool
 */
function courier_key( string $key ): bool {
	return (bool) preg_match(
		'/(courier|carrier|tracking[_\-]?(provider|company)|shipping[_\-]?company|delivery[_\-]?company|택배사|배송사)/iu',
		$key
	);
}

/**
 * 주문 메타를 훑어 송장번호를 찾습니다 — 키 이름을 모를 때의 마지막 길.
 *
 * `_duckhoo_` 로 시작하는 우리 메타는 건너뛴다 (우리가 넣은 것을 우리가 읽으면
 * 돌고 돈다). 여러 개가 걸리면 **가장 이름이 그럴듯한 것**을 쓴다 — 키에
 * `track`/`invoice`/`송장` 이 든 쪽이 `sid1` 보다 앞이다.
 *
 * @param \WC_Order|mixed $order 주문.
 * @return array{no: string, key: string, courier: string}
 */
function scan( $order ): array {
	$out = array( 'no' => '', 'key' => '', 'courier' => '' );
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta_data' ) ) {
		return $out;
	}

	$best = -1;
	foreach ( (array) $order->get_meta_data() as $meta ) {
		$data = method_exists( $meta, 'get_data' ) ? (array) $meta->get_data() : (array) $meta;
		$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
		$val  = $data['value'] ?? null;

		if ( '' === $key || 0 === strpos( $key, '_duckhoo_' ) ) {
			continue;
		}

		if ( '' === $out['courier'] && courier_key( $key ) && is_scalar( $val ) ) {
			$slug = courier_of( (string) $val );
			if ( '' !== $slug ) {
				$out['courier'] = $slug;
			}
		}

		if ( ! tracking_key( $key ) ) {
			continue;
		}
		$no = clean_no( $val );
		if ( '' === $no ) {
			continue;
		}

		/* 이름이 더 분명한 쪽을 고른다. */
		$score = preg_match( '/(송장|운송장|invoice|track)/iu', $key ) ? 2 : 1;
		if ( $score > $best ) {
			$best        = $score;
			$out['no']   = $no;
			$out['key']  = $key;
		}
	}

	return $out;
}

/**
 * 이 주문의 송장. 없으면 `no` 가 빈 문자열이다.
 *
 * @param \WC_Order|mixed $order 주문.
 * @return array{no: string, key: string, courier: string, name: string, url: string}
 */
function find( $order ): array {
	$none = array( 'no' => '', 'key' => '', 'courier' => '', 'name' => '', 'url' => '' );

	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return $none;
	}

	/* 1. 못 박아 둔 값 — 필터가 가장 앞이다. */
	$given = apply_filters( 'duckhoo_order_tracking', null, $order );
	if ( is_array( $given ) && ! empty( $given['no'] ) ) {
		return dress( array(
			'no'      => clean_no( $given['no'] ),
			'key'     => (string) ( $given['key'] ?? 'filter' ),
			'courier' => (string) ( $given['courier'] ?? '' ),
		) );
	}
	if ( is_scalar( $given ) && '' !== clean_no( $given ) ) {
		return dress( array( 'no' => clean_no( $given ), 'key' => 'filter', 'courier' => '' ) );
	}

	$found = array( 'no' => '', 'key' => '', 'courier' => '' );

	/* 2. 사장님이 진단 화면에서 고른 키 → 이름이 알려진 키. */
	$keys = array_merge( array_filter( array( (string) get_option( PINNED, '' ) ) ), meta_keys() );
	foreach ( $keys as $key ) {
		$no = clean_no( $order->get_meta( $key, true ) );
		if ( '' !== $no ) {
			$found = array( 'no' => $no, 'key' => (string) $key, 'courier' => '' );
			break;
		}
	}

	/* 3. 그래도 없으면 훑는다. */
	if ( '' === $found['no'] ) {
		$found = scan( $order );
	} else {
		$scanned           = scan( $order );
		$found['courier']  = $scanned['courier'];
	}

	if ( '' === $found['no'] ) {
		return $none;
	}

	/* 택배사를 못 찾았으면 배송방법 이름에서 한 번 더 본다. */
	if ( '' === $found['courier'] ) {
		foreach ( courier_keys() as $key ) {
			$slug = courier_of( (string) $order->get_meta( $key, true ) );
			if ( '' !== $slug ) {
				$found['courier'] = $slug;
				break;
			}
		}
	}
	if ( '' === $found['courier'] && method_exists( $order, 'get_shipping_method' ) ) {
		$found['courier'] = courier_of( (string) $order->get_shipping_method() );
	}

	return dress( $found );
}

/**
 * 찾은 것에 택배사 이름과 조회 주소를 붙입니다.
 *
 * @param array $found no · key · courier.
 * @return array{no: string, key: string, courier: string, name: string, url: string}
 */
function dress( array $found ): array {
	$no   = (string) ( $found['no'] ?? '' );
	$slug = (string) ( $found['courier'] ?? '' );
	if ( '' === $no ) {
		return array( 'no' => '', 'key' => '', 'courier' => '', 'name' => '', 'url' => '' );
	}
	if ( '' === $slug ) {
		$slug = default_courier();
	}

	$all = couriers();
	$co  = $all[ $slug ] ?? reset( $all );

	return array(
		'no'      => $no,
		'key'     => (string) ( $found['key'] ?? '' ),
		'courier' => $slug,
		'name'    => (string) $co['name'],
		'url'     => sprintf( (string) $co['url'], rawurlencode( $no ) ),
	);
}

/**
 * 번호를 네 자리씩 띄어 읽기 쉽게 만든다 (복사하는 값은 숫자 그대로다).
 *
 * 마지막 덩어리가 한두 자리로 떨어지면 앞 덩어리에 붙인다 — 열세 자리를 그대로
 * 넷씩 끊으면 `6890 1748 1661 9` 처럼 끝에 숫자 하나가 혼자 남아, 손님이 택배사
 * 화면의 번호와 눈으로 맞출 때 오히려 헷갈린다.
 *
 * @param string $no 번호.
 * @return string
 */
function pretty( string $no ): string {
	$parts = str_split( $no, 4 );
	$last  = (int) count( $parts ) - 1;
	if ( $last > 0 && strlen( $parts[ $last ] ) < 3 ) {
		$parts[ $last - 1 ] .= $parts[ $last ];
		array_pop( $parts );
	}

	return implode( ' ', $parts );
}

/**
 * 손님에게 보여 줄 상자.
 *
 * @param array $t find() 결과.
 * @return string
 */
function box_html( array $t ): string {
	if ( empty( $t['no'] ) ) {
		return '';
	}

	$icon = function_exists( 'Duckhoo\\Redesign\\Front\\icon' ) ? \Duckhoo\Redesign\Front\icon( 'truck' ) : '';

	$html  = '<section class="dhr-track" aria-label="배송 조회">';
	$html .= '<div class="dhr-track__co"><span class="dhr-track__ic" aria-hidden="true">' . $icon . '</span>' . esc_html( (string) $t['name'] ) . '</div>';
	$html .= '<div class="dhr-track__row">';
	$html .= '<b class="dhr-track__no">' . esc_html( pretty( (string) $t['no'] ) ) . '</b>';
	$html .= '<button type="button" class="dhr-copy dhr-track__copy" data-copy="' . esc_attr( (string) $t['no'] ) . '">복사</button>';
	$html .= '</div>';
	$html .= '<a class="dhr-track__go" href="' . esc_url( (string) $t['url'] ) . '" target="_blank" rel="noopener noreferrer">배송조회 <span aria-hidden="true">→</span><span class="screen-reader-text"> (새 창)</span></a>';
	$html .= '<p class="dhr-track__hint">택배사 화면에서 바로 확인할 수 있습니다. 송장이 등록된 직후에는 조회가 안 될 수 있습니다.</p>';
	$html .= '</section>';

	return $html;
}

/**
 * 주문 상세 맨 위에 그립니다.
 *
 * 워드커머스 주문 상세는 `woocommerce_view_order` 안에서 표를 그리므로 그 앞(5)에
 * 붙는다. 테마가 그 템플릿을 갈아 끼웠을 때를 대비해 표 바로 앞 훅에도 걸어 두고,
 * **한 주문에 한 번만** 그린다.
 *
 * @param \WC_Order|int|mixed $order 주문 또는 주문 번호.
 * @return void
 */
function details( $order = null ): void {
	static $done = array();

	if ( is_numeric( $order ) ) {
		$order = wc_get_order( (int) $order );
	}
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return;
	}
	$id = (int) $order->get_id();
	if ( isset( $done[ $id ] ) ) {
		return;
	}

	$t = find( $order );
	if ( '' === $t['no'] ) {
		return;
	}
	$done[ $id ] = true;

	echo box_html( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — box_html() 이 전부 escape 한다.
}
add_action( 'woocommerce_view_order', __NAMESPACE__ . '\\details', 5 );
add_action( 'woocommerce_order_details_before_order_table', __NAMESPACE__ . '\\details', 5 );

/**
 * 주문 목록(마이페이지)에 `배송조회` 버튼.
 *
 * 목록에는 번호를 적지 않는다 — 카드 한 장에 들어갈 말이 이미 많다. 누르면
 * 택배사 화면이 새 창으로 열린다 (새 창은 front.js 가 붙인다 — 워드커머스
 * 템플릿에 `target` 을 넣을 자리가 없다).
 *
 * @param array           $actions 동작 목록.
 * @param \WC_Order|mixed $order   주문.
 * @return array
 */
function action( $actions, $order = null ): array {
	$actions = (array) $actions;
	if ( null === $order ) {
		return $actions;
	}

	$t = find( $order );
	if ( '' === $t['no'] ) {
		return $actions;
	}

	$actions['duckhoo-track'] = array(
		'url'  => $t['url'],
		'name' => '배송조회',
	);

	return $actions;
}
add_filter( 'woocommerce_my_account_my_orders_actions', __NAMESPACE__ . '\\action', 30, 2 );
