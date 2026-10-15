<?php
/**
 * 1:1 문의 게시판에서 직원에게만 손님 번호를 보여 준다 (2026-10-15).
 *
 * 사장님: 「1대1 문의 오는 거 고객 번호도 볼 수 있게 — 동명이인이 있어서 보기 힘드네」.
 * 게시판은 kboard 라 이름(표시 이름)만 찍힌다. 같은 이름의 손님이 여럿이면 누구의 문의인지 모른다.
 *
 * - **kboard 파일 · 표는 건드리지 않는다.** 표를 읽기만 하고(`kboard_board_content` 의 uid · member_uid),
 *   화면에 꼬리표를 붙이는 것은 front 쪽 짧은 스크립트다
 * - **직원(관리자 · 상점 관리자)에게만** 그린다. 손님 화면 · 캐시된 화면에는 아무것도 안 나간다 —
 *   로그인한 사람은 페이지 캐시를 타지 않는다
 * - 꼬리표: `회원 #번호 · 010-1234-5678 · 주문 N건` — 번호는 회원 편집 화면, 주문 수는 그 손님 주문 목록으로 간다
 * - 끄기: add_filter( 'duckhoo_inquiry_who', '__return_false' );
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\InquiryWho;

defined( 'ABSPATH' ) || exit;

/** 직원인가. */
function staff(): bool {
	return function_exists( 'current_user_can' ) && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) );
}

/** 켜져 있나. */
function on(): bool {
	return (bool) apply_filters( 'duckhoo_inquiry_who', true );
}

/**
 * 1:1 문의 게시판 번호 — `/inquiries/` 본문의 `[kboard id=N]` (필터 `duckhoo_inquiry_board_id`).
 */
function board_id(): int {
	$bid  = 0;
	$page = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'inquiries' ) : null;
	if ( is_object( $page ) && isset( $page->post_content ) && preg_match( '/\[kboard[^\]]*\bid\s*=\s*"?(\d+)/', (string) $page->post_content, $m ) ) {
		$bid = (int) $m[1];
	}
	return (int) apply_filters( 'duckhoo_inquiry_board_id', $bid );
}

/** 지금 화면이 그 게시판을 그리는가. */
function on_board( int $bid ): bool {
	if ( $bid <= 0 || ! function_exists( 'get_queried_object' ) ) {
		return false;
	}
	$o = get_queried_object();
	return is_object( $o ) && isset( $o->post_content ) && preg_match( '/\[kboard[^\]]*\bid\s*=\s*"?' . $bid . '\b/', (string) $o->post_content );
}

/**
 * 번호를 보기 좋게 — `01012345678` → `010-1234-5678`. 순수 함수.
 */
function dash( string $p ): string {
	$d = preg_replace( '/\D+/', '', $p );
	if ( str_starts_with( $d, '82' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	if ( 11 === strlen( $d ) ) {
		return substr( $d, 0, 3 ) . '-' . substr( $d, 3, 4 ) . '-' . substr( $d, 7 );
	}
	if ( 10 === strlen( $d ) ) {
		return substr( $d, 0, 3 ) . '-' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 );
	}
	return $d;
}

/**
 * 꼬리표 글자 — 순수 함수.
 *
 * @param array{u:int,phone:string,orders:int} $w 손님.
 */
function label( array $w ): string {
	if ( (int) $w['u'] <= 0 ) {
		return '비회원';
	}
	$parts = array( '회원 #' . (int) $w['u'] );
	if ( '' !== (string) $w['phone'] ) {
		$parts[] = dash( (string) $w['phone'] );
	}
	$parts[] = '주문 ' . (int) $w['orders'] . '건';
	return implode( ' · ', $parts );
}

/**
 * 글 번호 => 손님. 최근 글 500개.
 *
 * @return array<int,array{u:int,phone:string,orders:int,user_url:string,orders_url:string,label:string}>
 */
function map( int $bid ): array {
	global $wpdb;
	if ( $bid <= 0 || ! isset( $wpdb ) ) {
		return array();
	}
	$ct   = $wpdb->prefix . 'kboard_board_content';
	$cols = array_map( fn( $r ) => (string) ( $r['Field'] ?? '' ), (array) $wpdb->get_results( "SHOW COLUMNS FROM {$ct}", ARRAY_A ) ); // phpcs:ignore WordPress.DB
	if ( ! in_array( 'uid', $cols, true ) || ! in_array( 'member_uid', $cols, true ) || ! in_array( 'board_id', $cols, true ) ) {
		return array();
	}
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT uid, member_uid FROM {$ct} WHERE board_id = %d ORDER BY uid DESC LIMIT %d", $bid, (int) apply_filters( 'duckhoo_inquiry_who_max', 500 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( ! $rows ) {
		return array();
	}
	$uids = array_values( array_unique( array_filter( array_map( fn( $r ) => (int) $r['member_uid'], $rows ) ) ) );
	$info = people( $uids );
	$out  = array();
	foreach ( $rows as $r ) {
		$u = (int) $r['member_uid'];
		$w = $info[ $u ] ?? array( 'phone' => '', 'orders' => 0 );
		$w = array( 'u' => $u, 'phone' => (string) $w['phone'], 'orders' => (int) $w['orders'] );
		$out[ (int) $r['uid'] ] = $w + array(
			'user_url'   => $u > 0 ? admin_url( 'user-edit.php?user_id=' . $u ) : '',
			'orders_url' => $u > 0 ? orders_url( $u ) : '',
			'label'      => label( $w ),
		);
	}
	return $out;
}

/** 그 손님의 주문 목록 (관리자). */
function orders_url( int $u ): string {
	$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	return admin_url( $hpos ? 'admin.php?page=wc-orders&_customer_user=' . $u : 'edit.php?post_type=shop_order&_customer_user=' . $u );
}

/**
 * 회원 번호 => 연락처 · 주문 수 (임시글 · 환불 기록 빼고).
 *
 * @param int[] $uids
 * @return array<int,array{phone:string,orders:int}>
 */
function people( array $uids ): array {
	global $wpdb;
	$out = array();
	if ( ! $uids ) {
		return $out;
	}
	foreach ( array_chunk( $uids, 500 ) as $chunk ) {
		$in   = implode( ',', array_map( 'intval', $chunk ) );
		$meta = (array) $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ({$in}) AND meta_key IN ('wd_verified_phone','wd_phone','billing_phone')", ARRAY_A ); // phpcs:ignore WordPress.DB
		$m    = array();
		foreach ( $meta as $r ) {
			$m[ (int) $r['user_id'] ][ (string) $r['meta_key'] ] = trim( (string) $r['meta_value'] );
		}
		foreach ( $chunk as $u ) {
			$phone = '';
			foreach ( array( 'wd_verified_phone', 'wd_phone', 'billing_phone' ) as $k ) {
				if ( '' !== preg_replace( '/\D+/', '', (string) ( $m[ $u ][ $k ] ?? '' ) ) ) {
					$phone = (string) $m[ $u ][ $k ];
					break;
				}
			}
			$out[ (int) $u ] = array( 'phone' => $phone, 'orders' => 0 );
		}
		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			$cnt = (array) $wpdb->get_results( "SELECT customer_id AS u, COUNT(*) AS n FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status NOT IN ('wc-checkout-draft','trash','auto-draft') AND customer_id IN ({$in}) GROUP BY customer_id", ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$cnt = (array) $wpdb->get_results( "SELECT pm.meta_value AS u, COUNT(*) AS n FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_customer_user' AND pm.meta_value IN ({$in}) AND p.post_type = 'shop_order' AND p.post_status NOT IN ('wc-checkout-draft','trash','auto-draft') GROUP BY pm.meta_value", ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		foreach ( $cnt as $r ) {
			if ( isset( $out[ (int) $r['u'] ] ) ) {
				$out[ (int) $r['u'] ]['orders'] = (int) $r['n'];
			}
		}
	}
	return $out;
}

/**
 * 게시판 화면 아래에 꼬리표 자료 + 붙이는 스크립트 (직원에게만).
 */
function footer(): void {
	if ( ! on() || ! staff() ) {
		return;
	}
	$bid = board_id();
	if ( ! on_board( $bid ) ) {
		return;
	}
	try {
		$map = map( $bid );
	} catch ( \Throwable $e ) {
		return;
	}
	if ( ! $map ) {
		return;
	}
	$data = array();
	foreach ( $map as $uid => $w ) {
		$data[ $uid ] = array( 'l' => $w['label'], 'p' => $w['user_url'], 'o' => $w['orders_url'] );
	}
	echo '<style>.dhr-who{display:inline-flex;flex-wrap:wrap;gap:.25rem .5rem;align-items:center;margin:.25rem 0 0;padding:.2rem .6rem;border-radius:999px;background:var(--dh-accent-soft,#EAF0FF);color:var(--dh-accent-deep,#1A5CFF);font-size:12.5px;font-weight:700;line-height:1.5;white-space:normal}'
		. '.dhr-who a{color:inherit;text-decoration:underline;text-underline-offset:2px}'
		. '.dhr-who--doc{display:flex;margin:0 0 1rem;padding:.6rem .9rem;border-radius:14px;font-size:14px}'
		. 'td .dhr-who,li .dhr-who{display:flex;width:max-content;max-width:100%}</style>';
	echo '<script>(function(){var M=' . wp_json_encode( $data ) . ';'
		. 'function uidOf(h){var m=String(h||"").match(/[?&]uid=(\\d+)/);return m?m[1]:"";}'
		. 'function tag(w,doc){var s=document.createElement("span");s.className="dhr-who"+(doc?" dhr-who--doc":"");'
		. 'var parts=w.l.split(" · ");parts.forEach(function(t,i){var el;if(i===0&&w.p){el=document.createElement("a");el.href=w.p;el.target="_blank";el.rel="noopener";}'
		. 'else if(/^주문/.test(t)&&w.o){el=document.createElement("a");el.href=w.o;el.target="_blank";el.rel="noopener";}else{el=document.createElement("span");}'
		. 'if(i>0){var d=document.createElement("span");d.setAttribute("aria-hidden","true");d.textContent="·";s.appendChild(d);}el.textContent=t;s.appendChild(el);});return s;}'
		. 'function run(){'
		// 글 보기 화면
		. 'var cur=uidOf(location.search);if(cur&&/mod=document/.test(location.search)&&M[cur]&&!document.querySelector(".dhr-who--doc")){'
		. 'var box=document.querySelector("#kboard-document,.kboard-document-wrap,.kboard-document,[class*=kboard-document],[id^=kboard-]");'
		. 'if(box){box.parentNode.insertBefore(tag(M[cur],true),box);}}'
		// 글 목록
		. 'var seen={};document.querySelectorAll("a[href*=\'uid=\']").forEach(function(a){var u=uidOf(a.getAttribute("href"));'
		. 'if(!u||!M[u]||!/mod=document/.test(a.getAttribute("href")))return;'
		. 'var row=a.closest("tr,li,.kboard-list-item,.kboard-list-row")||a.parentNode;if(!row||row.querySelector(".dhr-who"))return;'
		// 작성자 칸은 폰에서 숨는 스킨이 많아 제목 칸(링크 바로 아래)에 붙인다
		. 'var cell=a.closest("td")||a.parentNode;cell.appendChild(tag(M[u],false));});}'
		. 'if(document.readyState!=="loading")run();else document.addEventListener("DOMContentLoaded",run);window.addEventListener("load",run);'
		. '})();</script>';
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_footer', __NAMESPACE__ . '\\footer', 50 );
}
