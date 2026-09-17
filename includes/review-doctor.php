<?php
/**
 * 도구 → 후기 적립 진단 (관리자 · 읽기 전용).
 *
 * 사장님(2026-09-17): 「그 적립금이 진짜 적립이 되는지도 테스트하면 되겠네」.
 *
 * 돈이 나가는 길이라 **단위 테스트만으로는 못 믿는다.** 후기 하나를 실제로 써서
 * 승인해 보고, 그때 **잔액과 원장 두 곳이 같이 움직였는지**를 눈으로 봐야 한다.
 * 이 화면이 그 셋을 한 자리에 놓는다:
 *
 *   1. 지금 적립이 **가능한 상태인가** — 테마 원장 함수가 살아 있는지 · 설정값
 *   2. 사진이 붙은 후기 목록 — 승인 상태 · 사진 수 · 지급액 · 그 회원의 현재 잔액
 *   3. 적립금 원장의 최근 줄 — 「사진 후기 적립」이 실제로 적혔는지
 *
 * 2026-09-07 의 `적립금 진단` 화면과 같은 쓰임이다 (그것은 자리를 찾고 나서 지웠다).
 * 여기도 **한 번 확인되면 지운다** — 관리자 화면을 늘리는 것이 목적이 아니다.
 *
 * **읽기만 한다.** 후기 · 적립금 · 주문에 한 글자도 쓰지 않는다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\ReviewDoctor;

use function Duckhoo\Redesign\ReviewPhotos\{on, reward, max_photos, photos};
use function Duckhoo\Redesign\Points\can_return;
use const Duckhoo\Redesign\ReviewPhotos\PAID;   // 지급 표시 메타 — 한 곳에서 정한다

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-revdoc';

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
		'후기 적립 진단',
		'후기 적립 진단',
		current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 적립금 원장 표 이름. 테마가 알려 주면 그것, 아니면 흔한 이름.
 *
 * @return string
 */
function ledger_table(): string {
	global $wpdb;
	if ( function_exists( 'wd_get_keyple_points_log_table' ) ) {
		$t = (string) wd_get_keyple_points_log_table();
		if ( '' !== $t ) {
			return $t;
		}
	}
	return ( isset( $wpdb ) ? (string) $wpdb->prefix : 'wp_' ) . 'keyple_points_log';
}

/**
 * 원장 최근 줄. 칸 이름을 모르므로 **표가 가진 그대로** 읽어 그대로 그린다.
 *
 * @param int $n 몇 줄.
 * @return array{cols: string[], rows: array<int,array<string,mixed>>}
 */
function ledger( int $n = 12 ): array {
	global $wpdb;
	$out = array( 'cols' => array(), 'rows' => array() );
	if ( ! isset( $wpdb ) ) {
		return $out;
	}
	$table = ledger_table();
	$found = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	if ( $found !== $table ) {
		return $out;
	}
	$cols = (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB
	if ( ! $cols ) {
		return $out;
	}
	$first       = (string) $cols[0];
	$out['cols'] = array_map( 'strval', $cols );
	$out['rows'] = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
		'SELECT * FROM `' . esc_sql( $table ) . '` ORDER BY `' . esc_sql( $first ) . '` DESC LIMIT ' . max( 1, min( 50, $n ) ),
		ARRAY_A
	);

	return $out;
}

/**
 * 사진이 붙었거나 적립이 걸린 후기들.
 *
 * @param int $n 몇 개.
 * @return \WP_Comment[]
 */
function reviews( int $n = 20 ): array {
	$rows = (array) get_comments( array(
		'type'   => 'review',
		'status' => 'all',
		'number' => $n,
		'order'  => 'DESC',
	) );

	return array_values( array_filter( $rows, fn( $c ) => $c instanceof \WP_Comment ) );
}

/**
 * 회원의 지금 적립금 잔액.
 *
 * @param int $uid 회원 번호.
 * @return int
 */
function balance( int $uid ): int {
	return $uid > 0 ? (int) get_user_meta( $uid, '_keyple_points', true ) : 0;
}

/**
 * 승인 상태를 사람 말로.
 *
 * @param string $approved 워드프레스 값.
 * @return string
 */
function state( string $approved ): string {
	if ( '1' === $approved ) {
		return '승인됨';
	}
	if ( '0' === $approved ) {
		return '검토 대기';
	}
	if ( 'spam' === $approved ) {
		return '스팸';
	}
	if ( 'trash' === $approved ) {
		return '휴지통';
	}
	return $approved;
}

/**
 * 시험하는 순서.
 *
 * **후기를 쓰려면 「받은 주문」이 있어야 한다** — 이 가게는 「구매한 고객만」이 켜져
 * 있고, 세는 상태는 배송완료 · 완료 · processing 이다. 관리자 계정에 그런 주문이
 * 없으면 폼이 안 나오는 것이 **맞는 동작**이라 그것만으로는 고장인지 알 수 없다.
 * 그래서 시험용 주문 한 건을 만들고 상태만 배송완료로 바꾸는 것이 가장 빠르다 —
 * 무통장입금이라 실제로 돈을 부치지 않아도 주문은 만들어진다.
 *
 * @return void
 */
function steps(): void {
	echo '<details style="max-width:56em;margin:.8rem 0 1.2rem;border:1px solid #c3c4c7;background:#fff;border-radius:6px;padding:.9rem 1.1rem" open>';
	echo '<summary style="font-weight:700;cursor:pointer">시험하는 순서</summary>';
	echo '<ol style="line-height:1.9;margin:.8rem 0 .4rem 1.4rem">';
	echo '<li><b>받은 주문이 있는 계정이 필요합니다.</b> 없으면 시험용으로 하나 만드세요 — 아무 계정으로 상품을 담아 주문하고(무통장입금이라 입금하지 않아도 주문은 생깁니다), 관리자 주문 화면에서 그 주문을 <b>배송완료</b>로 바꿉니다. 시험이 끝나면 취소하시면 됩니다.</li>';
	echo '<li>그 계정으로 <b>마이페이지 → 주문내역 → <code>후기 쓰고 적립금</code></b> → 상품을 골라 <b>사진을 한 장 붙여</b> 후기를 씁니다.</li>';
	echo '<li>이 화면에 그 후기가 <b>검토 대기 · 사진 1장 · 지급 —</b> 으로 뜹니다. <b>아직 돈은 안 나갑니다</b> (사진을 사람이 보기 전에는 주지 않습니다).</li>';
	echo '<li><b>상품 → 상품평</b>에서 <b>승인</b>합니다. 이 화면을 새로고침하면 지급이 <b>' . esc_html( number_format_i18n( reward() ) ) . '원</b> 으로 바뀌고, 회원 잔액이 그만큼 늘고, 아래 3번 원장에 줄이 하나 생깁니다. <b>이 셋이 다 맞아야 통과입니다.</b></li>';
	echo '<li>되돌리기: 그 후기를 <b>승인 취소</b>하거나 지웁니다 — 적립금을 도로 가져가고 원장에 취소 줄이 남습니다.</li>';
	echo '</ol>';
	echo '<p style="margin:.4rem 0 0"><b>사진 없이 쓴 후기에는 돈이 나가지 않습니다</b> — 그것도 한 번 같이 해 보시면 확실합니다.</p>';
	echo '</details>';
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

	$can  = function_exists( 'Duckhoo\\Redesign\\Points\\can_return' ) && can_return();
	$list = reviews();
	$log  = ledger();

	echo '<div class="wrap"><h1>후기 적립 진단</h1>';
	echo '<p style="max-width:54em;line-height:1.7">사진 후기에 적립금이 <b>실제로</b> 나가는지 보는 화면입니다. 돈이 나가는 길이라 잔액과 원장이 <b>같이</b> 움직여야 합니다 — 하나만 움직이면 정산이 어긋납니다. 이 화면은 <b>읽기만</b> 합니다.</p>';

	/* 1. 지금 적립이 가능한 상태인가 */
	echo '<h2>1. 지금 적립이 되는 상태인가</h2><table class="widefat striped" style="max-width:54em"><tbody>';
	printf(
		'<tr><td style="width:18em">적립 기능</td><td><b style="color:%s">%s</b></td></tr>',
		on() ? '#1E7B34' : '#B32D2E',
		on() ? '켜짐' : '꺼짐 (duckhoo_photo_review_on)'
	);
	printf(
		'<tr><td>원장 함수 <code>wd_log_keyple_points_change</code></td><td><b style="color:%s">%s</b>%s</td></tr>',
		$can ? '#1E7B34' : '#B32D2E',
		$can ? '있음' : '없음',
		$can ? '' : ' — 이것이 없으면 <b>적립이 아예 안 나갑니다</b> (잔액만 늘리지 않습니다). 테마가 바뀐 것인지 확인해 주세요.'
	);
	printf( '<tr><td>사진 후기 적립금</td><td><b>%s원</b></td></tr>', esc_html( number_format_i18n( reward() ) ) );
	printf( '<tr><td>한 후기에 올릴 수 있는 사진</td><td>%d장</td></tr>', (int) max_photos() );
	printf(
		'<tr><td>사진 후기는 검토 대기로</td><td>%s</td></tr>',
		apply_filters( 'duckhoo_photo_review_hold', true ) ? '<b>예</b> — 사장님이 사진을 보고 승인해야 돈이 나갑니다' : '아니요 (자동 승인)'
	);
	printf( '<tr><td>적립금 원장 표</td><td><code>%s</code> %s</td></tr>', esc_html( ledger_table() ), $log['cols'] ? '' : '<b style="color:#B32D2E">— 못 찾음</b>' );
	echo '</tbody></table>';

	/* 2. 후기 목록 */
	echo '<h2 style="margin-top:2rem">2. 지금까지 들어온 후기</h2>';
	steps();
	if ( ! $list ) {
		echo '<p><b>아직 후기가 하나도 없습니다.</b></p>';
	} else {
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
		echo '<th>후기</th><th>상품</th><th>회원</th><th>상태</th><th>사진</th><th>지급</th><th>그 회원 잔액</th>';
		echo '</tr></thead><tbody>';
		foreach ( $list as $c ) {
			$cid  = (int) $c->comment_ID;
			$uid  = (int) $c->user_id;
			$paid = (int) get_comment_meta( $cid, PAID, true );
			$pics = count( photos( $cid ) );
			printf(
				'<tr><td><a href="%1$s">#%2$d</a><br><span style="color:#666">%3$s</span></td>'
				. '<td><a href="%4$s">%5$s</a></td><td>%6$s</td><td>%7$s</td>'
				. '<td class="dhr-num"><b>%8$d</b>장</td>'
				. '<td><b style="color:%9$s">%10$s</b></td><td>%11$s원</td></tr>',
				esc_url( (string) get_edit_comment_link( $cid ) ),
				$cid,
				esc_html( mb_substr( wp_strip_all_tags( (string) $c->comment_content ), 0, 24 ) ),
				esc_url( (string) get_permalink( (int) $c->comment_post_ID ) ),
				esc_html( (string) get_the_title( (int) $c->comment_post_ID ) ),
				esc_html( $uid > 0 ? (string) $c->comment_author . ' (#' . $uid . ')' : '비회원' ),
				esc_html( state( (string) $c->comment_approved ) ),
				$pics,
				$paid > 0 ? '#1E7B34' : '#666',
				$paid > 0 ? esc_html( number_format_i18n( $paid ) ) . '원' : '—',
				esc_html( number_format_i18n( balance( $uid ) ) )
			);
		}
		echo '</tbody></table></div>';
		echo '<p style="color:#666;max-width:54em;line-height:1.7">사진이 <b>1장 이상</b>이고 <b>승인됨</b>인데 지급이 <b>—</b> 이면 적립이 안 나간 것입니다. 같은 회원이 <b>같은 상품</b>에서 이미 받았으면 두 번은 주지 않습니다.</p>';
	}

	/* 3. 원장 */
	echo '<h2 style="margin-top:2rem">3. 적립금 원장 최근 줄</h2>';
	if ( ! $log['cols'] ) {
		echo '<p><b style="color:#B32D2E">원장 표를 못 찾았습니다.</b> 표 이름이 바뀌었을 수 있습니다.</p>';
	} elseif ( ! $log['rows'] ) {
		echo '<p>표는 있는데 줄이 없습니다.</p>';
	} else {
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
		foreach ( $log['cols'] as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $log['rows'] as $row ) {
			echo '<tr>';
			foreach ( $log['cols'] as $col ) {
				$v = $row[ $col ] ?? '';
				echo '<td>' . esc_html( mb_substr( (string) $v, 0, 60 ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p style="color:#666">사진 후기로 나간 줄은 사유에 <b>「사진 후기 적립 — 상품이름」</b> 이라고 적힙니다. 되돌린 줄은 <b>「사진 후기 적립 취소」</b> 입니다.</p>';
	}

	echo '</div>';
}
