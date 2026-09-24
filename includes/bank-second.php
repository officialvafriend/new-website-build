<?php
/**
 * 도구 → 입금 2차 판정 (관리자 · **읽기 전용**, 2026-09-24).
 *
 * `keyple-bank-auto-confirm` 은 입금자명을 **공백 · 특수문자 제거 후 완전 일치**로만 본다
 * (`Keyple_Bank_Order_Matcher::name_equals`). 은행 접두어 목록에 「금고」가 없어 새마을금고에서 보낸
 * 「금고홍길동」이 영원히 안 맞고, 두 글자로 남은 옛 회원(「길동」)이 「홍길동」으로 보내도 떨어진다.
 * 그 플러그인은 필터를 하나(`keyple_bank_awaiting_statuses`)만 내주고 이름 비교에는 끼어들 자리가 없다.
 *
 * 이 화면은 **키플이 「입금자명 불일치」로 포기한 문자**를 다시 판정해 보여 준다. 규칙은 더 엄격하다:
 *   - 금액이 똑같은 입금전 주문 중에서
 *   - 주문자명(정규화)이 입금자명(정규화 · 접두어 뗀 것)의 **끝**에 붙어 있고 (「금고홍길동」 ← 「홍길동」 · 「길동」)
 *   - 그런 주문이 **딱 하나**일 때
 * 「이 주문」이라고 말한다. 「농협이상섭」에 「이상」이 들어 있어도 끝이 아니라 안 맞는다.
 *
 * **주문 · 문자 표에 아무것도 쓰지 않는다.** 입금확인은 키플의 문자 목록에서 사장님이 누른다 —
 * 돈이 걸린 상태 변경을 자동으로 하는 코드는 이 세션의 권한에서 막혔고, 사람이 한 번 보고 누르는 쪽이 맞다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Bank2;

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-bank2';

/**
 * 키플과 같은 정규화 — 공백 · 괄호 · 점 · 쉼표 · 하이픈 · 밑줄 제거, 소문자.
 *
 * @param string $s 원문.
 * @return string
 */
function norm( string $s ): string {
	$s = (string) preg_replace( '/\s+/u', '', $s );
	$s = (string) preg_replace( '/[\(\)\[\]\.,\-_]/u', '', $s );
	return mb_strtolower( trim( $s ), 'UTF-8' );
}

/**
 * 은행 접두어 — 키플 목록 + 빠져 있던 것들. 필터 `duckhoo_bank_prefixes`.
 *
 * @return string[]
 */
function prefixes(): array {
	return (array) apply_filters(
		'duckhoo_bank_prefixes',
		array(
			'카카오뱅크', '토스뱅크', '케이뱅크', '새마을금고', '우리은행', '국민은행', '신한은행', '하나은행', '농협은행', '기업은행',
			'국민', '신한', '우리', '하나', '기업', '농협', '수협', '새마을', '우체국', '카카오', '토스', '케이', '시티', '산업', '제일',
			'카뱅', '토뱅', '케뱅', '우체', '신협', '부산', '대구', '경남', '광주', '전북', '제주', '금고', '저축', '축협', '씨티', '에스씨',
			'kb', 'ibk', 'nh', 'sc', 'kbank', 'toss',
		)
	);
}

/**
 * 입금자명의 비교 후보 — 원문과 접두어 뗀 것.
 *
 * @param string $depositor 입금자명.
 * @return string[] 정규화된 후보들.
 */
function variants( string $depositor ): array {
	$base = norm( $depositor );
	if ( '' === $base ) {
		return array();
	}
	$out = array( $base => true );
	foreach ( prefixes() as $p ) {
		$p = mb_strtolower( $p, 'UTF-8' );
		if ( '' === $p || ! str_starts_with( $base, $p ) ) {
			continue;
		}
		$rest = mb_substr( $base, mb_strlen( $p, 'UTF-8' ), null, 'UTF-8' );
		if ( preg_match( '/^[가-힣]{2,6}$/u', $rest ) ) {
			$out[ $rest ] = true;
		}
	}
	return array_keys( $out );
}

/**
 * 주문 쪽 이름 후보 — 키플이 보는 것과 같은 여섯 + `_deposit_payer_name`.
 *
 * @param object $order WC_Order.
 * @return string[] 정규화 · 두 글자 이상 · 중복 제거.
 */
function names_of( object $order ): array {
	$get = fn( string $m ) => method_exists( $order, $m ) ? (string) $order->$m() : '';
	$bl  = $get( 'get_billing_last_name' );
	$bf  = $get( 'get_billing_first_name' );
	$sl  = $get( 'get_shipping_last_name' );
	$sf  = $get( 'get_shipping_first_name' );
	$raw = array( $bl . $bf, $bf . $bl, $sl . $sf, $sf . $sl, $get( 'get_formatted_billing_full_name' ), $get( 'get_formatted_shipping_full_name' ) );
	if ( method_exists( $order, 'get_meta' ) ) {
		$raw[] = (string) $order->get_meta( '_deposit_payer_name' );
	}
	$out = array();
	foreach ( $raw as $r ) {
		$n = norm( $r );
		if ( mb_strlen( $n, 'UTF-8' ) >= 2 ) {
			$out[ $n ] = true;
		}
	}
	return array_keys( $out );
}

/**
 * 주문 이름이 입금자명 **끝**에 붙어 있는가.
 *
 * @param string   $depositor 입금자명.
 * @param string[] $names     names_of().
 * @return string 맞은 이름, 없으면 ''.
 */
function tail_match( string $depositor, array $names ): string {
	foreach ( variants( $depositor ) as $v ) {
		foreach ( $names as $n ) {
			if ( '' !== $n && str_ends_with( $v, $n ) ) {
				return $n;
			}
		}
	}
	return '';
}

/**
 * 판정 — 후보 주문(id ⇒ 이름 목록) 중 끝이 맞는 것.
 *
 * @param string              $depositor 입금자명.
 * @param array<int,string[]> $cands     주문 id ⇒ names_of().
 * @return array{verdict:string,order_id:int,name:string,matched:int[]} verdict = match|none|multi.
 */
function pick( string $depositor, array $cands ): array {
	$hit = array();
	$nm  = '';
	foreach ( $cands as $oid => $names ) {
		$m = tail_match( $depositor, (array) $names );
		if ( '' !== $m ) {
			$hit[] = (int) $oid;
			$nm    = $m;
		}
	}
	if ( 1 === count( $hit ) ) {
		return array( 'verdict' => 'match', 'order_id' => $hit[0], 'name' => $nm, 'matched' => $hit );
	}
	return array( 'verdict' => $hit ? 'multi' : 'none', 'order_id' => 0, 'name' => '', 'matched' => $hit );
}

/**
 * 키플 파서가 금액을 못 읽은 원문에서 금액으로 보이는 숫자를 느슨하게 찾는다 — **표시용 추정**.
 *
 * 키플 `extract_amount()` 는 「입금 N」 · 「N원」 두 꼴만 본다. 그 밖의 꼴(「금액:78900」 · 「78,900 입금」 등)은
 * 0 으로 떨어진다. 여기서는 1,000 이상인 숫자를 전부 모아 보여 주고 판정은 사람이 한다.
 *
 * @param string $raw 원문.
 * @return int[] 큰 순서.
 */
function guess_amounts( string $raw ): array {
	$out = array();
	if ( preg_match_all( '/(?<![\d\-\*])(\d{1,3}(?:,\d{3})+|\d{4,9})(?![\d\-\*])/u', $raw, $m ) ) {
		foreach ( $m[1] as $n ) {
			$v = (int) str_replace( ',', '', $n );
			if ( $v >= 1000 && $v < 100000000 && ! preg_match( '/^(19|20)\d{2}$/', $n ) ) {
				$out[ $v ] = true;
			}
		}
	}
	$out = array_keys( $out );
	rsort( $out );
	return $out;
}

/**
 * 키플 문자 표 이름.
 *
 * @return string
 */
function table(): string {
	global $wpdb;
	if ( class_exists( 'Keyple_Bank_Installer' ) && defined( 'Keyple_Bank_Installer::TABLE_SMS' ) && method_exists( 'Keyple_Bank_Installer', 'table' ) ) {
		return (string) \Keyple_Bank_Installer::table( \Keyple_Bank_Installer::TABLE_SMS );
	}
	return isset( $wpdb ) ? $wpdb->prefix . 'keyple_bank_sms' : '';
}

/**
 * 표의 칸 이름들 — 시각 · 원문 칸은 이름을 모르니 있는 것을 고른다.
 *
 * @return array{all:string[],time:string,raw:string}
 */
function cols(): array {
	global $wpdb;
	$t = table();
	if ( '' === $t || ! isset( $wpdb ) ) {
		return array( 'all' => array(), 'time' => '', 'raw' => '' );
	}
	$c = get_transient( 'dhr_bank2_cols' );
	if ( ! is_array( $c ) ) {
		$c = array_map( fn( $r ) => (string) ( $r['Field'] ?? '' ), (array) $wpdb->get_results( "SHOW COLUMNS FROM {$t}", ARRAY_A ) ); // phpcs:ignore WordPress.DB
		set_transient( 'dhr_bank2_cols', $c, HOUR_IN_SECONDS );
	}
	$first = function ( array $want ) use ( $c ): string {
		foreach ( $want as $w ) {
			if ( in_array( $w, $c, true ) ) {
				return $w;
			}
		}
		return '';
	};
	return array(
		'all'  => $c,
		'time' => $first( array( 'received_at', 'created_at', 'sms_at', 'date_received', 'created', 'date' ) ),
		'raw'  => $first( array( 'raw_text', 'raw', 'body', 'message', 'sms_text', 'text', 'content' ) ),
	);
}

/**
 * 키플이 확인필요로 두고 주문을 못 붙인 최근 문자들.
 *
 * @param int $days  며칠.
 * @param int $limit 몇 건.
 * @return array<int,array<string,mixed>>
 */
function rows( int $days = 14, int $limit = 80 ): array {
	global $wpdb;
	$t = table();
	if ( '' === $t || ! isset( $wpdb ) ) {
		return array();
	}
	$c    = cols();
	$time = $c['time'];
	$sql  = "SELECT * FROM {$t} WHERE match_status = %s AND ( order_id IS NULL OR order_id = 0 )";
	$args = array( 'need_check' );
	if ( '' !== $time ) {
		$sql   .= " AND {$time} >= %s";
		$args[] = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}
	$sql   .= ' ORDER BY id DESC LIMIT %d';
	$args[] = max( 1, min( 500, $limit ) );
	return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

/**
 * 금액이 같은 입금전 주문들 — 키플과 같은 상태 · 기간.
 *
 * @param int $amount 금액.
 * @return array<int,object> id ⇒ 주문.
 */
function candidates( int $amount ): array {
	if ( $amount <= 0 || ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	$st = array( 'on-hold', 'pending' );
	if ( class_exists( 'Keyple_Bank_Order_Status' ) ) {
		foreach ( array( 'AWAITING', 'NEED_CHECK' ) as $k ) {
			if ( defined( 'Keyple_Bank_Order_Status::' . $k ) ) {
				$st[] = (string) constant( 'Keyple_Bank_Order_Status::' . $k );
			}
		}
	}
	$st   = (array) apply_filters( 'keyple_bank_awaiting_statuses', array_values( array_unique( $st ) ) );
	$days = (int) get_option( 'keyple_bank_match_window', 14 );
	$args = array( 'limit' => 50, 'status' => $st, 'orderby' => 'date', 'order' => 'DESC' );
	if ( $days > 0 ) {
		$args['date_created'] = '>' . ( time() - DAY_IN_SECONDS * $days );
	}
	$out = array();
	foreach ( (array) wc_get_orders( $args ) as $o ) {
		if ( ! is_object( $o ) && function_exists( 'wc_get_order' ) ) {
			$o = wc_get_order( $o );
		}
		if ( is_object( $o ) && method_exists( $o, 'get_total' ) && (int) round( (float) $o->get_total() ) === $amount ) {
			$out[ (int) $o->get_id() ] = $o;
		}
	}
	return $out;
}

/**
 * 문자 한 건 판정.
 *
 * @param array<string,mixed> $sms 문자 줄.
 * @return array<string,mixed> pick() + sms_id · depositor · amount · reason · cands(수) · cand_names.
 */
function judge( array $sms ): array {
	$dep    = (string) ( $sms['depositor_name'] ?? '' );
	$amount = (int) ( $sms['amount'] ?? 0 );
	$reason = (string) ( $sms['match_reason'] ?? '' );
	$base   = array( 'sms_id' => (int) ( $sms['id'] ?? 0 ), 'depositor' => $dep, 'amount' => $amount, 'reason' => $reason, 'cands' => 0, 'cand_names' => array() );
	if ( '' === $dep || $amount <= 0 ) {
		return $base + array( 'verdict' => 'parse', 'order_id' => 0, 'name' => '', 'matched' => array() );
	}
	if ( ! str_contains( $reason, '입금자명' ) ) {
		return $base + array( 'verdict' => 'skip', 'order_id' => 0, 'name' => '', 'matched' => array() );
	}
	$map = array();
	foreach ( candidates( $amount ) as $oid => $o ) {
		$map[ $oid ] = names_of( $o );
	}
	$base['cands']      = count( $map );
	$base['cand_names'] = $map;
	return $base + pick( $dep, $map );
}

/**
 * 볼 수 있는가.
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
	add_management_page( '입금 2차 판정', '입금 2차 판정', current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options', SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 화면 — 읽기만.
 *
 * @return void
 */
function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$t0   = microtime( true );
	$rs   = rows();
	$c    = cols();
	$list = array();
	foreach ( $rs as $r ) {
		$j        = judge( $r );
		$j['raw'] = '' !== $c['raw'] ? (string) ( $r[ $c['raw'] ] ?? '' ) : '';
		$j['at']  = '' !== $c['time'] ? (string) ( $r[ $c['time'] ] ?? '' ) : '';
		$list[]   = $j;
	}
	$n = array( 'match' => 0, 'none' => 0, 'multi' => 0, 'parse' => 0, 'skip' => 0 );
	foreach ( $list as $j ) {
		++$n[ $j['verdict'] ];
	}
	$keyple_url = admin_url( 'admin.php?page=' . ( class_exists( 'Keyple_Bank_Admin_Page' ) && defined( 'Keyple_Bank_Admin_Page::SLUG' ) ? (string) constant( 'Keyple_Bank_Admin_Page::SLUG' ) : 'keyple-bank' ) );

	echo '<div class="wrap"><h1>입금 2차 판정</h1>';
	echo '<p style="max-width:58em;line-height:1.7">키플 입금 자동확인이 <b>「입금자명 불일치」</b>로 확인필요에 둔 문자를 다시 봅니다. '
		. '키플은 이름이 글자 그대로 같아야 맞추는데, 통장에는 <b>「금고홍길동」처럼 은행 약칭이 앞에 붙고</b> 옛 회원은 이름이 <b>두 글자</b>로 남아 있어 떨어집니다. '
		. '여기서는 금액이 같은 입금전 주문 중 <b>주문자명이 입금자명의 끝에 붙어 있는 주문이 딱 하나</b>일 때만 「이 주문」이라고 말합니다. '
		. '<b>이 화면은 아무것도 바꾸지 않습니다.</b> 맞다고 보이면 키플 <a href="' . esc_url( $keyple_url ) . '">입금 문자 목록</a>에서 그 문자에 주문을 연결해 입금확인을 누르세요.</p>';

	$card = function ( string $label, int $v, string $sub = '', bool $warn = false ): void {
		echo '<div style="flex:1 1 150px;min-width:150px;background:#fff;border:1px solid #dcdcde;border-left:4px solid ' . ( $warn ? '#C2410C' : '#2271b1' ) . ';border-radius:8px;padding:12px 14px">'
			. '<div style="font-size:12px;color:#646970">' . esc_html( $label ) . '</div>'
			. '<div style="font-size:26px;font-weight:700;line-height:1.2;margin-top:2px">' . esc_html( number_format_i18n( $v ) ) . '<span style="font-size:13px;font-weight:400;color:#646970"> 건</span></div>'
			. ( '' !== $sub ? '<div style="font-size:12px;color:#646970;margin-top:4px">' . esc_html( $sub ) . '</div>' : '' ) . '</div>';
	};
	echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:14px 0 18px">';
	$card( '최근 14일 · 주문 못 붙인 문자', count( $list ) );
	$card( '이 주문이 맞아 보임', $n['match'], '주문 하나에만 끝이 맞음', $n['match'] > 0 );
	$card( '후보가 여럿', $n['multi'], '같은 금액 · 끝이 맞는 주문 2건 이상' );
	$card( '못 찾음', $n['none'], '금액 같은 주문이 없거나 이름이 안 맞음' );
	$card( '문자 해석 실패', $n['parse'], '이름 또는 금액을 못 읽음 — 원문을 봐야 함' );
	echo '</div>';

	if ( ! $list ) {
		echo '<p><b>최근 14일에 주문을 못 붙인 확인필요 문자가 없습니다.</b></p></div>';
		return;
	}
	$label = array( 'match' => '<b style="color:#1d7a3a">이 주문</b>', 'multi' => '<span style="color:#C2410C">후보 여럿</span>', 'none' => '못 찾음', 'parse' => '<span style="color:#C2410C">해석 실패</span>', 'skip' => '다른 사유' );
	echo '<table class="widefat striped"><thead><tr><th style="width:5rem">문자</th><th style="width:9rem">받은 시각</th><th>입금자명</th><th style="width:7rem;text-align:right">금액</th><th>키플 판정</th><th style="width:7rem">우리 판정</th><th>주문 (주문자명)</th></tr></thead><tbody>';
	foreach ( $list as $j ) {
		$cell = '';
		if ( 'match' === $j['verdict'] ) {
			$cell = '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $j['order_id'] . '&action=edit' ) ) . '">#' . (int) $j['order_id'] . '</a> — 주문자 「' . esc_html( (string) $j['name'] ) . '」 · 금액 일치 · 후보 ' . (int) $j['cands'] . '건 중 이것뿐';
		} elseif ( 'multi' === $j['verdict'] ) {
			$cell = '끝이 맞는 주문 ' . count( (array) $j['matched'] ) . '건: ' . implode( ', ', array_map( fn( $id ) => '#' . (int) $id, (array) $j['matched'] ) );
		} elseif ( 'none' === $j['verdict'] ) {
			$names = array();
			foreach ( (array) $j['cand_names'] as $oid => $ns ) {
				$names[] = '#' . (int) $oid . ' ' . esc_html( (string) ( $ns[0] ?? '' ) );
			}
			$cell = $j['cands'] > 0 ? '같은 금액 주문 ' . (int) $j['cands'] . '건은 있는데 이름이 안 맞음: ' . implode( ' · ', $names ) : '같은 금액의 입금전 주문 없음';
		} elseif ( 'parse' === $j['verdict'] ) {
			$g    = '' !== $j['raw'] ? guess_amounts( $j['raw'] ) : array();
			$cell = ( $g ? '원문에 보이는 금액 후보: <b>' . esc_html( implode( ' · ', array_map( fn( $v ) => number_format_i18n( $v ) . '원', $g ) ) ) . '</b><br>' : '' )
				. ( '' !== $j['raw'] ? '원문: <code style="white-space:pre-wrap">' . esc_html( mb_substr( $j['raw'], 0, 200 ) ) . '</code>' : '원문 칸을 못 찾음 (표 칸 이름을 각주에서 확인)' );
		}
		echo '<tr><td>#' . (int) $j['sms_id'] . '</td><td>' . esc_html( substr( (string) $j['at'], 0, 16 ) ) . '</td><td><b>' . esc_html( (string) $j['depositor'] ) . '</b></td>'
			. '<td style="text-align:right">' . esc_html( number_format_i18n( (int) $j['amount'] ) ) . '</td><td style="font-size:12px;color:#646970">' . esc_html( (string) $j['reason'] ) . '</td>'
			. '<td>' . $label[ $j['verdict'] ] . '</td><td>' . $cell . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p style="max-width:58em;line-height:1.7;color:#646970;font-size:13px;margin-top:16px">읽는 데 ' . esc_html( number_format( microtime( true ) - $t0, 1 ) ) . '초. 후보 주문은 키플과 같은 조건(입금전 · 확인필요 · on-hold · pending, 최근 ' . (int) get_option( 'keyple_bank_match_window', 14 ) . '일, 금액 완전 일치)으로 읽습니다. '
		. '접두어 목록은 키플 것에 「금고」 「저축」 「축협」 「씨티」 등을 더한 것입니다 (필터 <code>duckhoo_bank_prefixes</code>). 표 칸 이름: ' . esc_html( implode( ', ', $c['all'] ) ) . '</p></div>';
}
