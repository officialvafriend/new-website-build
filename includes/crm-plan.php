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

/* ── 보낸 기록 — 1주차에 받은 사람은 2주차(RFM)에서 빠진다 ───────────────────────────
 * 양식(.xls)을 내려받은 순간 그 파일에 든 사람(이름 · 번호 · 기준 · 그룹)을 묶음으로 적는다. 그 뒤 모든 탭은
 * 최근 `skip_days()`(21일) 안에 받은 사람을 기본으로 뺀다 (`?skip=0` 이면 안 뺀다). 3주차 「쿠폰 7일 남았습니다」는
 * 그 묶음을 **그대로 다시 내려받는다** — 새로 뽑으면 명단이 바뀌어 받은 적 없는 사람에게 리마인드가 간다.
 * 쓰는 옵션 하나(`duckhoo_crm_sent`) · 주문 · 회원에는 안 쓴다. 사장님 2026-10-08 「여기서 받은 사람은 RFM 에서 제외」. */

const SENT_OPT = 'duckhoo_crm_sent';

/** 최근 며칠 안에 받은 사람을 빼나. 필터 `duckhoo_crm_skip_days`. */
function skip_days(): int {
	return max( 0, (int) apply_filters( 'duckhoo_crm_skip_days', 21 ) );
}

/** `?skip=0` 이면 안 뺀다. */
function skip_on(): bool {
	return ! ( isset( $_GET['skip'] ) && '0' === (string) $_GET['skip'] ); // phpcs:ignore WordPress.Security.NonceVerification
}

/** @return array<int,array{id:string,at:int,seg:string,group:string,rows:array<int,array{name:string,phone:string,why:string}>}> 최신 먼저. */
function sent_batches(): array {
	$v = get_option( SENT_OPT, array() );
	$v = is_array( $v ) ? array_values( array_filter( $v, 'is_array' ) ) : array();
	usort( $v, fn( $a, $b ) => (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ) );
	return $v;
}

/** 최근 `$days` 일 안에 받은 번호 집합. 순수. @return array<string,int> phone => 받은 시각 */
function sent_set( array $batches, int $days, int $now ): array {
	$out = array();
	if ( $days <= 0 ) {
		return $out;
	}
	foreach ( $batches as $b ) {
		$at = (int) ( $b['at'] ?? 0 );
		if ( $at < $now - $days * DAY_IN_SECONDS ) {
			continue;
		}
		foreach ( (array) ( $b['rows'] ?? array() ) as $r ) {
			$p = phone_norm( (string) ( $r['phone'] ?? '' ) );
			if ( '' !== $p ) {
				$out[ $p ] = max( $out[ $p ] ?? 0, $at );
			}
		}
	}
	return $out;
}

/** 받은 사람을 뺀다. 순수. @return array{0:array<int,array<string,mixed>>,1:int} 남은 줄 · 뺀 수 */
function without_sent( array $rows, array $set ): array {
	if ( ! $set ) {
		return array( $rows, 0 );
	}
	$keep = array();
	$n    = 0;
	foreach ( $rows as $r ) {
		$p = phone_norm( (string) ( $r['phone'] ?? '' ) );
		if ( '' !== $p && isset( $set[ $p ] ) ) {
			++$n;
			continue;
		}
		$keep[] = $r;
	}
	return array( $keep, $n );
}

/** 화면 · CSV · xls 가 같은 줄을 보도록 — skip 이 켜져 있으면 최근 받은 사람을 뺀다. @return array{0:array,1:int} */
function apply_skip( array $rows ): array {
	if ( ! skip_on() ) {
		return array( $rows, 0 );
	}
	return without_sent( $rows, sent_set( sent_batches(), skip_days(), time() ) );
}

/** 내려받은 파일의 사람들을 묶음으로 적는다 (번호 있는 줄만 — 파일에 든 사람과 같다). */
function record_sent( array $rows, string $seg, string $group ): string {
	$keep = array();
	foreach ( $rows as $r ) {
		$p = phone_norm( (string) ( $r['phone'] ?? '' ) );
		if ( '' === $p ) {
			continue;
		}
		$keep[] = array( 'name' => (string) ( $r['name'] ?? '' ), 'phone' => $p, 'why' => mb_substr( (string) ( $r['why'] ?? '' ), 0, 80 ), 'group' => (string) ( $r['group'] ?? $group ) );
	}
	if ( ! $keep ) {
		return '';
	}
	$id = wp_date( 'ymd-His' ) . '-' . substr( wp_hash( (string) microtime( true ) ), 0, 4 );
	$v  = get_option( SENT_OPT, array() );
	$v  = is_array( $v ) ? $v : array();
	$v[] = array( 'id' => $id, 'at' => time(), 'seg' => $seg, 'group' => '' !== $group ? $group : ( $keep[0]['group'] ?? '' ), 'rows' => $keep );
	usort( $v, fn( $a, $b ) => (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ) );
	update_option( SENT_OPT, array_slice( $v, 0, 40 ), false ); // 40묶음까지 (한 해치 넘게)
	return $id;
}

function sent_delete(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	check_admin_referer( 'dhr-crm-sent' );
	$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : '';
	$v  = array_values( array_filter( sent_batches(), fn( $b ) => (string) ( $b['id'] ?? '' ) !== $id ) );
	update_option( SENT_OPT, $v, false );
	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( (string) $_POST['back'] ) ) : admin_url( 'admin.php?page=' . SLUG );
	wp_safe_redirect( add_query_arg( 'dhr_sms_saved', 'sentdel', $back ) );
	exit;
}
add_action( 'admin_post_dhr_crm_sent_del', __NAMESPACE__ . '\\sent_delete' );

/** 묶음을 그대로 다시 내려받는다 — 리마인드. 그룹명은 `grp`(비우면 「원래 그룹 · 리마인드」). 기록은 새로 적지 않는다 (같은 사람). */
function sent_resend(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	check_admin_referer( 'dhr-crm-sent' );
	$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['id'] ) ) : '';
	$b  = null;
	foreach ( sent_batches() as $x ) {
		if ( (string) ( $x['id'] ?? '' ) === $id ) {
			$b = $x;
			break;
		}
	}
	if ( ! $b ) {
		wp_die( '그 묶음이 없습니다.' );
	}
	$grp  = isset( $_GET['grp'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['grp'] ) ) : '';
	$rows = array_map( fn( $r ) => $r + array( 'note' => '' ), (array) $b['rows'] );
	if ( '' === $grp ) {
		foreach ( $rows as &$r ) {
			$r['group'] = (string) ( $r['group'] ?? $b['group'] ) . ' · 리마인드';
		}
		unset( $r );
	}
	$body = xls_html( $rows, $grp );
	nocache_headers();
	header( 'Content-Type: application/vnd.ms-excel; charset=euc-kr' );
	header( 'Content-Disposition: attachment; filename="tothemoon_remind_' . $id . '_' . wp_date( 'Ymd' ) . '.xls"' );
	header( 'Content-Length: ' . strlen( $body ) );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- 바이너리(EUC-KR) 파일 본문
	exit;
}
add_action( 'admin_post_dhr_crm_sent_resend', __NAMESPACE__ . '\\sent_resend' );

/** 보낸 기록 상자 — 묶음마다 날짜 · 그룹 · 수, 「리마인드로 다시 내려받기」 · 「기록 지우기」. */
function sent_box( string $back, int $skipped ): void {
	$bs = sent_batches();
	echo '<details class="dhr-crm-adv" style="margin:0 0 14px"><summary style="cursor:pointer;font-weight:700">보낸 기록 ' . esc_html( number_format_i18n( count( $bs ) ) ) . '묶음 — 최근 ' . (int) skip_days() . '일 안에 받은 사람은 명단에서 뺍니다'
		. ( skip_on() ? ' (지금 ' . esc_html( number_format_i18n( $skipped ) ) . '명 뺌 · <a href="' . esc_url( add_query_arg( 'skip', '0', $back ) ) . '">안 빼고 보기</a>)' : ' (<b>지금은 안 빼고</b> 보는 중 · <a href="' . esc_url( remove_query_arg( 'skip', $back ) ) . '">빼고 보기</a>)' ) . '</summary>';
	echo '<p style="margin:10px 0 6px">양식(.xls)을 내려받으면 그 파일에 든 사람이 여기 적힙니다. 1주차에 받은 사람은 2주차 RFM 명단에서 저절로 빠지고, 3주차 「쿠폰 7일 남았습니다」는 아래 <b>리마인드로 다시 내려받기</b>로 1주차 그 사람들에게 그대로 보냅니다 (새로 뽑으면 명단이 바뀝니다).</p>';
	if ( ! $bs ) {
		echo '<p class="dhr-sl-note">아직 내려받은 파일이 없습니다.</p></details>';
		return;
	}
	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>내려받은 때</th><th>그룹명</th><th>사람</th><th></th></tr></thead><tbody>';
	foreach ( $bs as $b ) {
		$n   = count( (array) ( $b['rows'] ?? array() ) );
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=dhr_crm_sent_resend&id=' . rawurlencode( (string) $b['id'] ) ), 'dhr-crm-sent' );
		echo '<tr><td>' . esc_html( wp_date( 'm.d H:i', (int) $b['at'] ) ) . '</td><td>' . esc_html( (string) $b['group'] ) . '</td><td>' . esc_html( number_format_i18n( $n ) ) . '명</td><td style="white-space:nowrap"><a class="button button-small" href="' . esc_url( $url ) . '">리마인드로 다시 내려받기</a> '
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'이 묶음의 기록을 지웁니다. 그 사람들이 다음 명단에 다시 들어옵니다.\')"><input type="hidden" name="action" value="dhr_crm_sent_del"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'dhr-crm-sent' ) ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $b['id'] ) . '"><input type="hidden" name="back" value="' . esc_attr( $back ) . '"><button class="button button-small button-link-delete">기록 지우기</button></form></td></tr>';
	}
	echo '</tbody></table></details>';
}
