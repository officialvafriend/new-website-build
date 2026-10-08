<?php
/**
 * 고객 세그먼트 — 주차 플랜 · 명단별 보상 라벨 · 「한 사람에 한 보상」.
 *
 * 사장님 CRM 플랜(2026-10-08): 1 · 3주차 = 담고 나간 손님 적립금(7일) + 노보·디오 구매 손님 쿠폰(7일),
 * 2주차 = RFM 챔피언 · 충성 · 신규 적립금(2주), 10/26 할로윈 쿠폰(10일). 명단마다 보상이 다른데 같은 사람이
 * 두 명단에 들면 **어느 보상을 받는지**가 문자 사이트 양식에 적혀야 한다 — 그룹명이 곧 보낼 문구를 가른다.
 *
 * 규칙: 한 사람에 한 통 · **보상은 주 명단(우선순위가 높은 명단) 것 하나**. 우선순위는 `priority()` 그대로
 * (미입금 → 담고 나간 → 노보·디오 → RFM). 담고 나간 손님이 노보도 산 사람이면 적립금(담고 나간) 하나만 —
 * 장바구니가 결제에 더 가까운 손님이라서. 바꾸려면 `duckhoo_crm_priority`.
 *
 * **읽기 전용** — 적립금 · 쿠폰을 여기서 주지 않는다. 쿠폰은 마케팅 → 쿠폰 한 번에 만들기, 적립금은 사장님이 관리자에서.
 * 쓰는 것은 보상 라벨 옵션 하나(`duckhoo_crm_rewards`).
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Crm;

defined( 'ABSPATH' ) || exit;

const REWARD_OPT = 'duckhoo_crm_rewards';

/**
 * 주차 플랜 — 사장님 표를 조각(parts) 묶음으로. 필터 `duckhoo_crm_weeks`.
 *
 * @return array<string,array{label:string,parts:string[],hint:string}>
 */
function weeks(): array {
	return (array) apply_filters(
		'duckhoo_crm_weeks',
		array(
			'w13' => array(
				'label' => '1 · 3주차',
				'parts' => array( 'abandon', 'brand' ),
				'hint'  => '담고 나간 손님 → 적립금(7일) · 노보 · 디오리퀴드 구매 손님 → 쿠폰(7일). 겹치면 적립금 하나만',
			),
			'w2'  => array(
				'label' => '2주차',
				'parts' => array( 'rfm:챔피언', 'rfm:충성', 'rfm:신규' ),
				'hint'  => 'RFM 챔피언 · 충성 · 신규 → 적립금(2주). 쿠폰 사용이 저조해 적립금으로 시험',
			),
			'w3r' => array(
				'label' => '3주차 리마인드',
				'parts' => array( 'brand', 'abandon' ),
				'hint'  => '「쿠폰 기간이 7일 남았습니다」 · 장바구니 리마인드. 1주차와 같은 사람들 — 1주차 파일을 그대로 쓰는 것이 정확하다 (명단은 그 사이 바뀐다)',
			),
			'hw'  => array(
				'label' => '10/26 할로윈',
				'parts' => array( 'rfm:챔피언', 'rfm:충성', 'rfm:잠재 충성', 'rfm:신규', 'rfm:관심 필요', 'rfm:이탈 위험', 'brand', 'abandon' ),
				'hint'  => '할로윈 쿠폰(10일) — 산 적 있는 회원 전부 + 담고 나간 손님. 동의한 사람만 나간다',
			),
		)
	);
}

/** 조각 열쇠 — `abandon` · `brand` · `unpaid` · `rfm:갈래`. 순수. */
function reward_key( string $seg, string $only = '' ): string {
	return 'rfm' === $seg ? ( '' !== $only ? 'rfm:' . $only : 'rfm' ) : $seg;
}

/** 보상 라벨 옵션 — part => 글(「적립금 3,000원 · 7일」). 필터 `duckhoo_crm_rewards`. */
function rewards(): array {
	$v = get_option( REWARD_OPT, array() );
	$v = is_array( $v ) ? array_filter( array_map( 'strval', $v ), fn( $s ) => '' !== trim( $s ) ) : array();
	return (array) apply_filters( 'duckhoo_crm_rewards', $v );
}

/**
 * 줄마다 보상을 붙인다 — `reward` 칸, 그리고 그룹명 뒤에 「 · 보상」. 라벨이 없는 조각은 그대로.
 * `part` 가 없는 줄은 `seg` 로 본다 (RFM 은 갈래를 모르면 `rfm` 전체 라벨). 순수.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<string,string>           $rewards part => 라벨.
 */
function with_reward( array $rows, array $rewards ): array {
	foreach ( $rows as &$r ) {
		$part = (string) ( $r['part'] ?? $r['seg'] ?? '' );
		$lab  = trim( (string) ( $rewards[ $part ] ?? ( str_starts_with( $part, 'rfm:' ) ? ( $rewards['rfm'] ?? '' ) : '' ) ) );
		$r['reward'] = $lab;
		if ( '' !== $lab && isset( $r['group'] ) && '' !== (string) $r['group'] && ! str_ends_with( (string) $r['group'], ' · ' . $lab ) ) {
			$r['group'] .= ' · ' . $lab;
		}
	}
	unset( $r );
	return $rows;
}

/** 조각별 사람 수 · 보상 — 합치기 탭 요약용. 순수. @return array<string,array{n:int,reward:string}> */
function reward_summary( array $rows ): array {
	$out = array();
	foreach ( $rows as $r ) {
		$k = (string) ( $r['part'] ?? $r['seg'] ?? '' );
		if ( ! isset( $out[ $k ] ) ) {
			$out[ $k ] = array( 'n' => 0, 'reward' => (string) ( $r['reward'] ?? '' ) );
		}
		++$out[ $k ]['n'];
	}
	return $out;
}

/* ── 저장 (admin-post) ──────────────────────────────────────────────── */

function save_rewards(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	check_admin_referer( 'dhr-crm-rewards' );
	$in  = isset( $_POST['reward'] ) && is_array( $_POST['reward'] ) ? wp_unslash( $_POST['reward'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$old = get_option( REWARD_OPT, array() );
	$old = is_array( $old ) ? $old : array();
	$ok  = array_keys( parts() );
	$ok[] = 'rfm';
	foreach ( $in as $k => $v ) {
		$k = sanitize_text_field( (string) $k );
		if ( ! in_array( $k, $ok, true ) ) {
			continue;
		}
		$v = trim( sanitize_text_field( (string) $v ) );
		if ( '' === $v ) {
			unset( $old[ $k ] );
		} else {
			$old[ $k ] = mb_substr( $v, 0, 40 );
		}
	}
	update_option( REWARD_OPT, $old, false );
	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( (string) $_POST['back'] ) ) : admin_url( 'admin.php?page=' . SLUG );
	wp_safe_redirect( add_query_arg( 'dhr_sms_saved', 'reward', $back ) );
	exit;
}
add_action( 'admin_post_dhr_crm_rewards', __NAMESPACE__ . '\\save_rewards' );

/* ── 화면 ─────────────────────────────────────────────────────────────── */

/** 주차 플랜 링크 줄 + 보상 라벨 칸. 합치기 탭 맨 위에. */
function plan_box( string $back, array $inc ): void {
	$rw   = rewards();
	$base = admin_url( 'admin.php?page=' . SLUG . '&seg=all' );
	echo '<div class="dhr-crm-adv" style="margin:0 0 14px"><b>주차 플랜</b> — 누르면 그 주에 보낼 조각이 골라집니다: ';
	foreach ( weeks() as $k => $w ) {
		$url = $base . '&' . implode( '&', array_map( fn( $p ) => 'inc%5B%5D=' . rawurlencode( $p ), $w['parts'] ) );
		$on  = array_values( $inc ) === array_values( array_intersect( $w['parts'], $inc ) ) && count( $inc ) === count( $w['parts'] );
		echo '<a href="' . esc_url( $url ) . '" title="' . esc_attr( $w['hint'] ) . '" style="display:inline-block;margin:0 6px 4px 0;padding:3px 10px;border-radius:999px;border:1px solid #c9cfd4' . ( $on ? ';background:#161616;color:#fff' : '' ) . '">' . esc_html( $w['label'] ) . '</a>';
	}
	echo '<p style="margin:8px 0 0">규칙: <b>한 사람에 한 통 · 보상은 주 명단 것 하나</b>. 겹친 사람은 급한 명단(미입금 → 담고 나간 → 노보 · 디오 → RFM)의 보상을 받고, 다른 명단은 메모에만 적힙니다. 그룹명 뒤에 보상이 붙어 문자 사이트에서 그룹마다 다른 문구를 고를 수 있습니다.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:10px 0 0;display:flex;flex-wrap:wrap;gap:8px 14px;align-items:end">';
	echo '<input type="hidden" name="action" value="dhr_crm_rewards"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'dhr-crm-rewards' ) ) . '"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
	$p = parts();
	foreach ( $inc as $k ) {
		if ( ! isset( $p[ $k ] ) ) {
			continue;
		}
		echo '<label style="display:flex;flex-direction:column;gap:2px;font-size:12px">' . esc_html( $p[ $k ] ) . ' 보상<input type="text" name="reward[' . esc_attr( $k ) . ']" value="' . esc_attr( $rw[ $k ] ?? '' ) . '" placeholder="예: 적립금 3,000원 · 7일" style="width:200px"></label>';
	}
	echo '<button class="button">보상 라벨 저장</button><span class="dhr-sl-note" style="margin:0">라벨은 그룹명 · 메모에만 쓰입니다 — 적립금 · 쿠폰을 실제로 주는 것은 따로(쿠폰 한 번에 만들기 · 관리자 적립금)</span></form></div>';
}
