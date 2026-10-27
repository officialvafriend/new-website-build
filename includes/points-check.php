<?php
/**
 * 도구 → 적립금 확인 (관리자, 읽기 전용) — 2026-10-27.
 *
 * 사장님: 「이준섭님 적립금 사용이 안 된다는데」. 결제 화면의 적립금 칸은 서버가 정한 최대치(max)까지만
 * 받고, 그 값이 0 이면 무엇을 넣어도 0 으로 돌아간다. 0 이 되는 이유는 셋 중 하나다 —
 * ①같은 사람의 계정이 둘이라 적립금이 다른 계정에 있다 ②적립금을 거둬 잔액이 비었다 ③잔액은 있는데
 * 테마가 쓸 수 있는 금액을 0 으로 본다. 손님 계정을 직접 볼 수 없어서, 이메일 · 전화 · 회원 번호
 * 하나로 그 셋을 가를 것을 한 화면에 모은다.
 *
 * 회원 · 주문 · 적립금에 아무것도 쓰지 않는다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\PointsCheck;

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-points-check';

/** 권한. */
function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

add_action(
	'admin_menu',
	function () {
		add_management_page( '적립금 확인', '적립금 확인', current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options', SLUG, __NAMESPACE__ . '\\screen' );
	}
);

/** 숫자만. */
function digits( string $s ): string {
	$d = preg_replace( '/\D+/', '', $s );
	if ( str_starts_with( $d, '82' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	return $d;
}

/**
 * 찾는 말 하나로 계정을 모은다 — 회원 번호 · 이메일/아이디(같은 값의 계정 전부) · 휴대폰.
 * get_user_by() 는 하나만 돌려줘서 같은 이메일의 둘째 계정을 놓친다 — 표를 직접 읽는다.
 *
 * @return array<int,string> 회원 => 어떻게 찾았나.
 */
function find( string $q ): array {
	global $wpdb;
	$q   = trim( $q );
	$out = array();
	if ( '' === $q ) {
		return $out;
	}
	if ( preg_match( '/^#?(\d{1,9})$/', $q, $m ) && get_userdata( (int) $m[1] ) ) {
		$out[ (int) $m[1] ] = '회원 번호';
	}
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email = %s OR user_login = %s", $q, $q ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $id ) {
		$out[ (int) $id ] = $out[ (int) $id ] ?? '이메일 · 아이디';
	}
	$d = digits( $q );
	if ( strlen( $d ) >= 10 && strlen( $d ) <= 11 ) {
		$rows = $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('wd_verified_phone','wd_phone','billing_phone')", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $r ) {
			if ( digits( (string) $r['meta_value'] ) === $d ) {
				$out[ (int) $r['user_id'] ] = $out[ (int) $r['user_id'] ] ?? '휴대폰';
			}
		}
	}
	// 찾은 계정과 같은 이메일 · 같은 휴대폰을 쓰는 다른 계정도 붙인다 (쌍둥이 · 다시 가입).
	foreach ( array_keys( $out ) as $uid ) {
		$u = get_userdata( $uid );
		if ( $u ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email = %s OR user_login = %s", $u->user_email, $u->user_email ) ) as $id ) { // phpcs:ignore WordPress.DB
				$out[ (int) $id ] = $out[ (int) $id ] ?? '같은 이메일';
			}
		}
		$p = digits( (string) get_user_meta( $uid, 'wd_verified_phone', true ) ?: (string) get_user_meta( $uid, 'billing_phone', true ) );
		if ( strlen( $p ) >= 10 ) {
			foreach ( $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('wd_verified_phone','wd_phone','billing_phone')", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				if ( digits( (string) $r['meta_value'] ) === $p ) {
					$out[ (int) $r['user_id'] ] = $out[ (int) $r['user_id'] ] ?? '같은 휴대폰';
				}
			}
		}
	}
	ksort( $out );
	return $out;
}

/** 원장 마지막 줄들 (칸을 못 알아보면 빈 배열). */
function ledger( int $uid, int $n = 10 ): array {
	global $wpdb;
	if ( ! function_exists( 'Duckhoo\\Redesign\\Points\\Admin\\log_table' ) ) {
		return array();
	}
	$t = \Duckhoo\Redesign\Points\Admin\log_table();
	if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { // phpcs:ignore WordPress.DB
		return array();
	}
	$map = \Duckhoo\Redesign\Points\Admin\guess_cols( array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $t ) . '`' ) ) ); // phpcs:ignore WordPress.DB
	if ( ! $map ) {
		return array();
	}
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $t ) . '` WHERE `' . esc_sql( $map['user'] ) . '` = %d ORDER BY `' . esc_sql( $map['id'] ) . '` DESC LIMIT %d', $uid, $n ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

/** 이 회원의 적립금처럼 보이는 메타 전부 (이름으로 고른다). */
function point_meta( int $uid ): array {
	$out = array();
	foreach ( (array) get_user_meta( $uid ) as $k => $v ) {
		if ( preg_match( '/point|mycred|적립|reward|mileage/i', (string) $k ) && ! str_starts_with( (string) $k, '_dhr_pts_' ) ) {
			$out[ $k ] = is_array( $v ) ? (string) reset( $v ) : (string) $v;
		}
	}
	ksort( $out );
	return $out;
}

/** 우리가 넣은 적립금 묶음 기록 (_dhr_pts_*). */
function batches( int $uid ): array {
	$out = array();
	foreach ( (array) get_user_meta( $uid ) as $k => $v ) {
		if ( str_starts_with( (string) $k, '_dhr_pts_' ) && ! str_ends_with( (string) $k, '_r' ) ) {
			$out[ $k ] = is_array( $v ) ? (string) reset( $v ) : (string) $v;
		}
	}
	return $out;
}

/** 테마가 마이페이지에 보여 주는 잔액 (함수가 있으면). */
function theme_balance( int $uid ): string {
	if ( ! function_exists( 'wd_get_myaccount_user_points' ) ) {
		return '함수 없음';
	}
	try {
		$v = wd_get_myaccount_user_points( $uid );
		return is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
	} catch ( \Throwable $e ) {
		return '오류: ' . $e->getMessage();
	}
}

/** 최근 주문. */
function orders( int $uid ): array {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	$out = array();
	foreach ( (array) wc_get_orders( array( 'customer_id' => $uid, 'type' => 'shop_order', 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC' ) ) as $o ) {
		$out[] = array(
			'id'      => $o->get_id(),
			'no'      => $o->get_order_number(),
			'date'    => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
			'status'  => wc_get_order_status_name( $o->get_status() ),
			'total'   => (float) $o->get_total(),
			'used'    => (string) $o->get_meta( '_wd_point_discount' ),
			'applied' => (string) $o->get_meta( '_wd_point_discount_applied' ),
		);
	}
	return $out;
}

/** 적립금 계산에 걸린 콜백 이름 (테마 · 키플 쪽에서 무엇이 도는지). */
function hooks(): array {
	global $wp_filter;
	$out = array();
	foreach ( array( 'woocommerce_cart_calculate_fees', 'woocommerce_checkout_update_order_review', 'woocommerce_review_order_before_payment', 'woocommerce_checkout_create_order' ) as $tag ) {
		if ( empty( $wp_filter[ $tag ] ) ) {
			continue;
		}
		foreach ( $wp_filter[ $tag ]->callbacks as $prio => $cbs ) {
			foreach ( $cbs as $cb ) {
				$f    = $cb['function'];
				$name = is_string( $f ) ? $f : ( is_array( $f ) ? ( is_object( $f[0] ) ? get_class( $f[0] ) : $f[0] ) . '::' . $f[1] : '익명 함수' );
				$file = '';
				try {
					$r    = is_array( $f ) ? new \ReflectionMethod( $f[0], $f[1] ) : new \ReflectionFunction( $f );
					$file = str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( (string) $r->getFileName() ) ) . ':' . $r->getStartLine();
				} catch ( \Throwable $e ) {
					$file = '';
				}
				if ( preg_match( '/point|적립/i', $name . $file ) || 'woocommerce_cart_calculate_fees' === $tag ) {
					$out[] = array( $tag, $prio, $name, $file );
				}
			}
		}
	}
	return $out;
}

/** 화면. */
function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap"><h1>적립금 확인</h1>';
	echo '<p>손님이 「적립금이 안 써진다」고 할 때 씁니다. 이메일 · 휴대폰 · 회원 번호 중 하나를 넣으세요. <b>읽기만 합니다.</b></p>';
	echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( SLUG ) . '"><input type="text" name="q" value="' . esc_attr( $q ) . '" style="width:320px" placeholder="이메일 · 010-… · 회원 번호"> <button class="button button-primary">찾기</button></form>';
	if ( '' === $q ) {
		echo '</div>';
		return;
	}
	$found = find( $q );
	if ( ! $found ) {
		echo '<p><b>찾은 계정이 없습니다.</b> 손님이 가입할 때 쓴 이메일 · 휴대폰이 다를 수 있습니다.</p></div>';
		return;
	}
	$login = get_user_by( 'email', $q ) ?: get_user_by( 'login', $q );
	echo '<p>계정 <b>' . count( $found ) . '개</b>';
	if ( count( $found ) > 1 ) {
		echo ' — <b style="color:#B42318">같은 사람의 계정이 여럿입니다.</b> 적립금이 로그인하는 계정과 다른 계정에 있으면 결제 화면에서 0 으로 보입니다.';
	}
	if ( $login ) {
		echo '<br>이 이메일로 로그인하면 워드프레스가 먼저 고르는 계정: <b>#' . (int) $login->ID . '</b> (쌍둥이 로그인 보정이 켜져 있으면 비밀번호가 맞는 계정 중 본인확인 · 주문 있는 쪽으로 바뀔 수 있습니다)';
	}
	echo '</p>';

	foreach ( $found as $uid => $how ) {
		$u = get_userdata( $uid );
		if ( ! $u ) {
			continue;
		}
		$kp  = get_user_meta( $uid, '_keyple_points', true );
		$sb  = get_user_meta( $uid, '_wd_signup_point_balance', true );
		$ver = get_user_meta( $uid, 'wd_phone_verified', true ) ? '있음' : ( get_user_meta( $uid, '_dhr_legacy_verified', true ) ? '옛 사이트 확인' : '없음' );
		$wd  = get_user_meta( $uid, '_duckhoo_withdrawn_at', true ) || str_starts_with( (string) $u->user_login, 'withdrawn-' );
		echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;margin:14px 0;max-width:980px">';
		printf(
			'<h2 style="margin:0 0 6px">#%d %s <small style="font-weight:400">(%s로 찾음)</small></h2>',
			(int) $uid,
			esc_html( (string) ( get_user_meta( $uid, 'wd_verified_name', true ) ?: $u->display_name ) ),
			esc_html( $how )
		);
		printf(
			'<p style="margin:0 0 8px">아이디 %s · 이메일 %s · 가입 %s · 역할 %s · 본인확인 %s%s · <a href="%s">회원 편집</a></p>',
			esc_html( $u->user_login ),
			esc_html( $u->user_email ),
			esc_html( (string) get_date_from_gmt( $u->user_registered, 'Y-m-d H:i' ) ),
			esc_html( implode( ',', (array) $u->roles ) ?: '없음' ),
			esc_html( $ver ),
			$wd ? ' · <b style="color:#B42318">탈퇴 처리됨</b>' : '',
			esc_url( admin_url( 'user-edit.php?user_id=' . $uid ) )
		);
		printf(
			'<p style="font-size:15px;margin:0 0 8px">적립금 잔액 <b>%s원</b> <small>(_keyple_points)</small> · 가입 적립분 %s <small>(_wd_signup_point_balance)</small> · 테마 마이페이지 잔액 <b>%s</b></p>',
			esc_html( '' === (string) $kp ? '(비어 있음)' : number_format( (float) $kp ) ),
			esc_html( '' === (string) $sb ? '-' : number_format( (float) $sb ) . '원' ),
			esc_html( theme_balance( $uid ) )
		);
		$pm = point_meta( $uid );
		if ( $pm ) {
			echo '<details><summary>적립금 관련 메타 전부 (' . count( $pm ) . ')</summary><table class="widefat striped" style="max-width:720px">';
			foreach ( $pm as $k => $v ) {
				echo '<tr><td><code>' . esc_html( $k ) . '</code></td><td>' . esc_html( mb_substr( $v, 0, 200 ) ) . '</td></tr>';
			}
			echo '</table></details>';
		}
		$bs = batches( $uid );
		if ( $bs ) {
			echo '<p style="margin:8px 0 0">우리 화면으로 넣은 묶음 기록: ';
			foreach ( $bs as $k => $v ) {
				echo '<code>' . esc_html( $k ) . '=' . esc_html( $v ) . '</code> ';
			}
			echo '<br><small><code>_was</code> 로 끝나는 것은 넣었다가 거둔 묶음입니다.</small></p>';
		}
		$lg = ledger( $uid );
		echo '<h3 style="margin:12px 0 4px">적립금 내역 (최근 10줄)</h3>';
		if ( $lg ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array_keys( $lg[0] ) as $c ) {
				echo '<th>' . esc_html( $c ) . '</th>';
			}
			echo '</tr></thead>';
			foreach ( $lg as $r ) {
				echo '<tr>';
				foreach ( $r as $v ) {
					echo '<td>' . esc_html( mb_substr( (string) $v, 0, 80 ) ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</table>';
		} else {
			echo '<p>내역이 없거나 내역 표를 못 읽었습니다.</p>';
		}
		$os = orders( $uid );
		echo '<h3 style="margin:12px 0 4px">최근 주문</h3>';
		if ( $os ) {
			echo '<table class="widefat striped"><thead><tr><th>주문</th><th>날짜</th><th>상태</th><th>결제</th><th>쓴 적립금</th><th>차감 표시</th></tr></thead>';
			foreach ( $os as $o ) {
				printf(
					'<tr><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td><td>%s원</td><td>%s</td><td>%s</td></tr>',
					esc_url( admin_url( 'post.php?post=' . $o['id'] . '&action=edit' ) ),
					esc_html( (string) $o['no'] ),
					esc_html( $o['date'] ),
					esc_html( $o['status'] ),
					esc_html( number_format( $o['total'] ) ),
					esc_html( '' === $o['used'] ? '-' : number_format( (float) $o['used'] ) . '원' ),
					esc_html( '' === $o['applied'] ? '-' : $o['applied'] )
				);
			}
			echo '</table>';
		} else {
			echo '<p>주문이 없습니다.</p>';
		}
		echo '</div>';
	}

	$hk = hooks();
	if ( $hk ) {
		echo '<details style="max-width:980px"><summary>결제 화면에서 적립금 계산에 걸린 코드</summary><table class="widefat striped"><thead><tr><th>자리</th><th>순서</th><th>함수</th><th>파일</th></tr></thead>';
		foreach ( $hk as $h ) {
			echo '<tr><td><code>' . esc_html( $h[0] ) . '</code></td><td>' . (int) $h[1] . '</td><td><code>' . esc_html( $h[2] ) . '</code></td><td><code>' . esc_html( $h[3] ) . '</code></td></tr>';
		}
		echo '</table></details>';
	}
	echo '<p style="max-width:980px;color:#555">읽는 법 — ①계정이 둘이고 잔액이 로그인하지 않는 쪽에 있으면: 계정 문제. ②잔액이 0 이고 내역 마지막이 「… 사용기간 종료」 · 「회수」면: 거둔 것. ③잔액이 있는데 손님 결제 화면 칸이 0 이면: 테마 계산 문제 — 이 화면을 캡처해 주세요.</p>';
	echo '</div>';
}
