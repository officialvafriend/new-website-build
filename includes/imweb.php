<?php
/**
 * 아임웹(옛 사이트) 주문 — 월말 결산에 합친다. 읽기 전용.
 *
 * 두 길이 있다. ① 아임웹 Open API (키 · 시크릿을 관리자 화면에 넣으면 매달 1일 결산이 지난달치를
 * 스스로 읽는다) ② 아임웹 관리자에서 내려받은 주문 목록(엑셀 · CSV)을 붙여 넣기. 어느 길이든 결과는
 * 달마다 `{n, sales, pend, void, src}` 로 옵션(`duckhoo_imweb_months`)에 남고, 결산이 그것을 읽는다.
 *
 * API 는 공개 문서(old-developers.imweb.me)로만 확인했다 — `GET https://api.imweb.me/v2/auth?key&secret`
 * → `access_token`, 그 뒤 헤더 `access-token`, `GET /v2/shop/orders?order_date_from&order_date_to&order_version`.
 * 응답은 `{msg, code, data:{list:[…]}}`, 쪽 넘김 열쇠는 `data_count · current_page · total_page · pagesize`.
 * 주문 상태 낱말은 문서에 다 안 적혀 있어 **글자로 가른다**(WAIT → 대기, CANCEL·REFUND·RETURN → 취소, 나머지 → 돈 들어옴).
 * 첫 연결 뒤 「연결 시험」이 원문 응답을 보여 주므로 틀린 곳이 있으면 거기서 보인다.
 *
 * 키 · 시크릿은 옵션에만 있다. 저장소 · 대화 · 화면에 값을 찍지 않는다 (화면에는 「있음 / 없음」만).
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Imweb;

defined( 'ABSPATH' ) || exit;

const OPT_KEY    = 'duckhoo_imweb_key';
const OPT_SECRET = 'duckhoo_imweb_secret';
const OPT_MONTHS = 'duckhoo_imweb_months';
const API        = 'https://api.imweb.me/v2';

/* ── 설정 ───────────────────────────────────────────────────────────── */

function key(): string {
	return trim( (string) get_option( OPT_KEY, '' ) );
}
function secret(): string {
	return trim( (string) get_option( OPT_SECRET, '' ) );
}
function connected(): bool {
	return '' !== key() && '' !== secret();
}

/* ── 순수 함수 ──────────────────────────────────────────────────────── */

/**
 * 상태 낱말 → 'paid' | 'pend' | 'void'. 아임웹 v2 낱말(PAY_WAIT · PAY_COMPLETE · DELIVERY_* · CANCEL* · REFUND* …)과
 * 한글 내보내기(입금대기 · 결제완료 · 배송완료 · 취소 · 환불 …) 둘 다 이 규칙으로 가른다.
 */
function classify( string $status ): string {
	$s = mb_strtoupper( trim( $status ) );
	if ( '' === $s ) {
		return 'paid';
	}
	if ( preg_match( '/CANCEL|REFUND|RETURN|취소|환불|반품|FAIL|실패/u', $s ) ) {
		return 'void';
	}
	if ( preg_match( '/WAIT|PENDING|대기|미입금|입금전|입금 전/u', $s ) ) {
		return 'pend';
	}
	return 'paid';
}

/**
 * API 응답(JSON 을 푼 배열)에서 주문 줄을 뽑는다 — `data.list` · `data` · 맨 위 배열 어느 꼴이든.
 *
 * @return array<int,array{no:string,ts:int,status:string,total:float}>
 */
function parse_api( array $json ): array {
	$list = null;
	if ( isset( $json['data']['list'] ) && is_array( $json['data']['list'] ) ) {
		$list = $json['data']['list'];
	} elseif ( isset( $json['data'] ) && is_array( $json['data'] ) && array_is_list( $json['data'] ) ) {
		$list = $json['data'];
	} elseif ( isset( $json['list'] ) && is_array( $json['list'] ) ) {
		$list = $json['list'];
	} elseif ( array_is_list( $json ) ) {
		$list = $json;
	}
	$out = array();
	foreach ( (array) $list as $o ) {
		if ( ! is_array( $o ) ) {
			continue;
		}
		$pay = isset( $o['payment'] ) && is_array( $o['payment'] ) ? $o['payment'] : array();
		$amt = $pay['payment_amount'] ?? $pay['total_price'] ?? $o['payment_amount'] ?? $o['total_price'] ?? $o['price'] ?? 0;
		$st  = (string) ( $o['status'] ?? $pay['pay_status'] ?? $o['pay_status'] ?? '' );
		$ts  = (int) ( $o['order_time'] ?? $o['time'] ?? 0 );
		$out[] = array(
			'no'      => (string) ( $o['order_no'] ?? $o['order_code'] ?? $o['no'] ?? '' ),
			'ts'      => $ts,
			'status'  => $st,
			'total'   => (float) $amt,
			'paid_ts' => (int) ( $pay['payment_time'] ?? -1 ), // -1 = 칸 없음 (붙여 넣기 · 옛 꼴)
		);
	}
	return $out;
}

/**
 * 쪽 넘김 정보 — 있으면 (전체 쪽 수, 이번 쪽), 없으면 null.
 *
 * @return array{0:int,1:int}|null
 */
function pages( array $json ): ?array {
	$d = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : $json;
	$pg = isset( $d['pagenation'] ) && is_array( $d['pagenation'] ) ? $d['pagenation'] : $d; // 실제 응답(2026-09 확인)은 data.pagenation 아래
	$tp = (int) ( $pg['total_page'] ?? $json['total_page'] ?? 0 );
	$cp = (int) ( $pg['current_page'] ?? $json['current_page'] ?? 0 );
	return $tp > 0 ? array( $tp, max( 1, $cp ) ) : null;
}

/**
 * 주문 하나에 prod-orders(줄 상태 · 상품)를 붙인다 — 순수 함수. 주문 목록(v2)에는 상태가 없다 (2026-09 실제 응답으로 확인):
 * 결제 여부는 payment.payment_time, 취소는 줄마다 status(COMPLETE · DELIVERING · CANCEL …). 줄이 전부 취소면 주문이 취소,
 * 일부만 취소면 그 줄 값(void_part)을 뺀다. 상품 줄에는 원가표(Cost\line_cost)를 대서 원가 · 모르는 매출을 센다.
 *
 * @param array  $o    parse_api 가 만든 주문 행.
 * @param array  $json prod-orders 응답 (data: 줄 배열).
 * @param string $date 주문 날짜 Y-m-d (원가는 날짜별).
 */
function enrich( array $o, array $json, string $date = '' ): array {
	$lines = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : ( array_is_list( $json ) ? $json : array() );
	$o['void_part'] = 0.0;
	$o['cost']      = 0.0;
	$o['unknown']   = 0.0;
	$o['items']     = 0;
	$n_void = 0;
	$n_all  = 0;
	foreach ( $lines as $po ) {
		if ( ! is_array( $po ) ) {
			continue;
		}
		$n_all++;
		$void = 'void' === classify( (string) ( $po['status'] ?? '' ) );
		if ( $void ) {
			$n_void++;
		}
		foreach ( (array) ( $po['items'] ?? array() ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$pay   = isset( $it['payment'] ) && is_array( $it['payment'] ) ? $it['payment'] : array();
			$cnt   = max( 1, (int) ( $pay['count'] ?? 1 ) );
			$price = (float) ( $pay['price'] ?? 0 ) * $cnt;
			if ( $void ) {
				$o['void_part'] += $price;
				continue;
			}
			$o['items']++;
			$c = function_exists( '\\Duckhoo\\Redesign\\Cost\\line_cost' ) ? \Duckhoo\Redesign\Cost\line_cost( (string) ( $it['prod_name'] ?? '' ), $cnt, 0, null, $date ) : null;
			if ( $c ) {
				$o['cost'] += (float) $c['cost'];
			} else {
				$o['unknown'] += $price;
			}
		}
	}
	if ( $n_all > 0 && $n_void === $n_all ) {
		$o['status'] = 'CANCEL';
	} elseif ( '' === (string) $o['status'] && isset( $o['paid_ts'] ) && (int) $o['paid_ts'] <= 0 ) {
		$o['status'] = 'PAY_WAIT';
	}
	return $o;
}

/**
 * 붙여 넣은 내보내기(탭 · 쉼표) → 주문 줄. 머리줄에서 주문번호 · 주문일 · 상태 · 금액 칸을 이름으로 찾는다.
 * 같은 주문번호가 여러 줄(상품마다 한 줄)이면 **한 주문으로 합친다** — 금액은 「결제금액」류 칸이면 첫 줄만, 「상품금액」류면 더한다.
 *
 * @return array<int,array{no:string,ts:int,status:string,total:float}>
 */
function parse_export( string $text ): array {
	$lines = preg_split( '/\r\n|\r|\n/', trim( $text ) );
	if ( count( $lines ) < 2 ) {
		return array();
	}
	$sep  = substr_count( $lines[0], "\t" ) >= substr_count( $lines[0], ',' ) ? "\t" : ',';
	$head = array_map( fn( $h ) => trim( (string) $h, " \"'\xC2\xA0" ), str_getcsv( $lines[0], $sep ) );
	$find = function ( array $pats ) use ( $head ): int {
		foreach ( $pats as $p ) {
			foreach ( $head as $i => $h ) {
				if ( false !== mb_stripos( $h, $p ) ) {
					return (int) $i;
				}
			}
		}
		return -1;
	};
	$i_no  = $find( array( '주문번호', '주문 번호', 'order_no', 'order no' ) );
	$i_dt  = $find( array( '주문일', '주문시간', '주문 일', 'order_time', 'order date', '결제일' ) );
	$i_st  = $find( array( '주문상태', '주문 상태', '결제상태', '상태', 'status' ) );
	$i_pay = $find( array( '실결제', '결제금액', '결제 금액', '총 결제', 'payment_amount', 'total_price', '총주문금액', '총 주문금액' ) );
	$i_goods = $find( array( '상품금액', '상품 금액', '판매가', 'price' ) );
	$per_order = $i_pay >= 0;
	$i_amt = $per_order ? $i_pay : $i_goods;
	if ( $i_no < 0 || $i_amt < 0 ) {
		return array();
	}
	$by = array();
	foreach ( array_slice( $lines, 1 ) as $ln ) {
		if ( '' === trim( $ln ) ) {
			continue;
		}
		$c  = array_map( fn( $v ) => trim( (string) $v, " \"'\xC2\xA0" ), str_getcsv( $ln, $sep ) );
		$no = (string) ( $c[ $i_no ] ?? '' );
		if ( '' === $no ) {
			continue;
		}
		$amt = (float) preg_replace( '/[^\d.\-]/', '', (string) ( $c[ $i_amt ] ?? '0' ) );
		$ts  = $i_dt >= 0 ? (int) strtotime( (string) ( $c[ $i_dt ] ?? '' ) ) : 0;
		$st  = $i_st >= 0 ? (string) ( $c[ $i_st ] ?? '' ) : '';
		if ( ! isset( $by[ $no ] ) ) {
			$by[ $no ] = array( 'no' => $no, 'ts' => $ts, 'status' => $st, 'total' => $amt );
		} elseif ( ! $per_order ) {
			$by[ $no ]['total'] += $amt;
		}
	}
	return array_values( $by );
}

/**
 * 주문 줄 → 달 요약. `$ym` 을 주면 그 달 것만 (ts 가 0 인 줄은 달을 모르니 그대로 센다).
 *
 * @return array{n:int,sales:float,pend_n:int,pend:float,void_n:int,void:float}
 */
function summarize( array $orders, string $ym = '', int $tz_offset = 32400 ): array {
	$s = array( 'n' => 0, 'sales' => 0.0, 'pend_n' => 0, 'pend' => 0.0, 'void_n' => 0, 'void' => 0.0, 'cost' => 0.0, 'unknown' => 0.0, 'items' => 0 );
	foreach ( $orders as $o ) {
		if ( '' !== $ym && (int) $o['ts'] > 0 && gmdate( 'Y-m', (int) $o['ts'] + $tz_offset ) !== $ym ) {
			continue;
		}
		$k = classify( (string) $o['status'] );
		if ( 'paid' === $k ) {
			$s['n']++;
			$s['sales']   += (float) $o['total'] - (float) ( $o['void_part'] ?? 0 ); // 일부 취소된 줄은 뺀다
			$s['cost']    += (float) ( $o['cost'] ?? 0 );
			$s['unknown'] += (float) ( $o['unknown'] ?? 0 );
			$s['items']   += (int) ( $o['items'] ?? 0 );
		} elseif ( 'pend' === $k ) {
			$s['pend_n']++;
			$s['pend'] += (float) $o['total'];
		} else {
			$s['void_n']++;
			$s['void'] += (float) $o['total'];
		}
	}
	return $s;
}

/* ── API ────────────────────────────────────────────────────────────── */

/**
 * 토큰 — 30분 캐시. 실패하면 빈 문자열과 사유.
 *
 * @return array{token:string,err:string,raw:string}
 */
function token( bool $fresh = false ): array {
	if ( ! connected() ) {
		return array( 'token' => '', 'err' => '키 · 시크릿이 없습니다', 'raw' => '' );
	}
	$hit = $fresh ? false : get_transient( 'dhr_imweb_token' );
	if ( is_string( $hit ) && '' !== $hit ) {
		return array( 'token' => $hit, 'err' => '', 'raw' => '' );
	}
	$res = wp_remote_get( add_query_arg( array( 'key' => key(), 'secret' => secret() ), API . '/auth' ), array( 'timeout' => 20 ) );
	if ( is_wp_error( $res ) ) {
		return array( 'token' => '', 'err' => $res->get_error_message(), 'raw' => '' );
	}
	$body = (string) wp_remote_retrieve_body( $res );
	$j    = json_decode( $body, true );
	$tok  = is_array( $j ) ? (string) ( $j['access_token'] ?? ( $j['data']['access_token'] ?? '' ) ) : '';
	if ( '' === $tok ) {
		return array( 'token' => '', 'err' => is_array( $j ) ? (string) ( $j['msg'] ?? 'access_token 없음' ) . ' (code ' . (string) ( $j['code'] ?? '?' ) . ')' : 'JSON 아님', 'raw' => mb_substr( preg_replace( '/"(access_token|secret|key)"\s*:\s*"[^"]*"/', '"$1":"…"', $body ), 0, 600 ) );
	}
	set_transient( 'dhr_imweb_token', $tok, 30 * MINUTE_IN_SECONDS );
	return array( 'token' => $tok, 'err' => '', 'raw' => '' );
}

/**
 * 한 달치 주문을 API 로 읽는다.
 *
 * @return array{orders:array,err:string,raw:string,pages:int}
 */
function fetch_month( string $ym ): array {
	$t = token();
	if ( '' === $t['token'] ) {
		return array( 'orders' => array(), 'err' => $t['err'], 'raw' => $t['raw'], 'pages' => 0 );
	}
	$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Asia/Seoul' );
	$from  = $ym . '-01'; // Y-m-d 꼴이 실제로 통했다 (2026-09-29 확인)
	$to    = ( new \DateTime( $ym . '-01 00:00:00', $tz ) )->modify( '+1 month -1 day' )->format( 'Y-m-d' );
	$off   = (int) ( new \DateTime( 'now', $tz ) )->getOffset();
	$all   = array();
	$raw   = '';
	$pages = 0;
	foreach ( array( 'v2', 'v1' ) as $ver ) {
		$page = 1;
		$got  = array();
		do {
			$url = add_query_arg( array( 'order_date_from' => $from, 'order_date_to' => $to, 'order_version' => $ver, 'limit' => 100, 'offset' => $page ), API . '/shop/orders' );
			$res = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => array( 'access-token' => $t['token'], 'Content-Type' => 'application/json' ) ) );
			if ( is_wp_error( $res ) ) {
				return array( 'orders' => $all, 'err' => $res->get_error_message(), 'raw' => $raw, 'pages' => $pages );
			}
			$body = (string) wp_remote_retrieve_body( $res );
			$j    = json_decode( $body, true );
			if ( '' === $raw ) {
				$raw = mb_substr( $body, 0, 800 );
			}
			if ( ! is_array( $j ) ) {
				return array( 'orders' => $all, 'err' => 'JSON 아님 (' . (int) wp_remote_retrieve_response_code( $res ) . ')', 'raw' => $raw, 'pages' => $pages );
			}
			if ( isset( $j['code'] ) && (int) $j['code'] !== 200 && ! isset( $j['data'] ) ) {
				// 토큰이 죽었으면 한 번 새로 받아 본다
				if ( 1 === $page && 'v2' === $ver && preg_match( '/token|auth/i', (string) ( $j['msg'] ?? '' ) ) ) {
					delete_transient( 'dhr_imweb_token' );
					$t2 = token( true );
					if ( '' !== $t2['token'] ) {
						$t = $t2;
						continue;
					}
				}
				return array( 'orders' => $all, 'err' => (string) ( $j['msg'] ?? '' ) . ' (code ' . (string) ( $j['code'] ?? '?' ) . ')', 'raw' => $raw, 'pages' => $pages );
			}
			$rows = parse_api( $j );
			$got  = array_merge( $got, $rows );
			++$pages;
			$pg   = pages( $j );
			$more = $pg ? $pg[1] < $pg[0] : count( $rows ) >= 100;
			++$page;
		} while ( $more && $page <= 50 );
		$all = array_merge( $all, $got );
		if ( $got ) {
			break; // v2 에서 나오면 v1 은 안 본다 (같은 주문이 두 번 잡힐 수 있다)
		}
	}
	// 줄 상태 · 상품은 주문마다 prod-orders 를 따로 본다 (한 달 40건 안팎 · 300건까지)
	$n = 0;
	foreach ( $all as $i => $o ) {
		if ( '' === (string) $o['no'] || $n >= 300 ) {
			break;
		}
		$res = wp_remote_get( API . '/shop/orders/' . rawurlencode( (string) $o['no'] ) . '/prod-orders', array( 'timeout' => 30, 'headers' => array( 'access-token' => $t['token'] ) ) );
		if ( is_wp_error( $res ) ) {
			continue;
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( is_array( $j ) && isset( $j['data'] ) ) {
			$all[ $i ] = enrich( $o, $j, (int) $o['ts'] > 0 ? gmdate( 'Y-m-d', (int) $o['ts'] + $off ) : '' );
		}
		$n++;
	}
	return array( 'orders' => $all, 'err' => '', 'raw' => $raw, 'pages' => $pages );
}

/* ── 달 요약 저장 · 읽기 ─────────────────────────────────────────────── */

function months(): array {
	return (array) get_option( OPT_MONTHS, array() );
}

/**
 * 달 요약을 적는다 (같은 달은 덮어쓴다 — 나중 것이 맞다).
 */
function put( string $ym, array $sum, string $src ): void {
	$all = months();
	$all[ $ym ] = array_merge( $sum, array( 'src' => $src, 'at' => (string) current_time( 'Y-m-d H:i' ) ) );
	krsort( $all );
	update_option( OPT_MONTHS, array_slice( $all, 0, 36, true ), false );
}

/**
 * 한 달 — 저장된 것이 있으면 그것, 없고 API 가 있으면 읽어서 저장. 없으면 null.
 *
 * @return array|null
 */
function month( string $ym, bool $fresh = false ): ?array {
	$all = months();
	if ( ! $fresh && isset( $all[ $ym ] ) && is_array( $all[ $ym ] ) ) {
		return $all[ $ym ];
	}
	if ( ! connected() ) {
		return isset( $all[ $ym ] ) && is_array( $all[ $ym ] ) ? $all[ $ym ] : null;
	}
	$r = fetch_month( $ym );
	if ( '' !== $r['err'] ) {
		update_option( 'duckhoo_imweb_last_err', array( 'ym' => $ym, 'err' => $r['err'], 'raw' => $r['raw'], 'at' => (string) current_time( 'Y-m-d H:i' ) ), false );
		return isset( $all[ $ym ] ) && is_array( $all[ $ym ] ) ? $all[ $ym ] : null;
	}
	delete_option( 'duckhoo_imweb_last_err' );
	$tzoff = function_exists( 'wp_timezone' ) ? ( new \DateTime( 'now', wp_timezone() ) )->getOffset() : 32400;
	$sum   = summarize( $r['orders'], $ym, (int) $tzoff );
	put( $ym, $sum, 'api' );
	return months()[ $ym ] ?? null;
}

/**
 * 한 줄 요약 글.
 */
function line( ?array $m ): string {
	if ( ! $m ) {
		return '아임웹: 연결 안 됨 (월말 결산 화면에 API 키를 넣거나 주문 목록을 붙여 넣으면 합쳐집니다)';
	}
	$w = fn( $n ) => number_format( (float) round( (float) $n ) );
	$cost = ! empty( $m['cost'] ) ? ' · 상품 원가 ' . $w( $m['cost'] ) . '원' . ( ! empty( $m['unknown'] ) ? ' (원가 모르는 매출 ' . $w( $m['unknown'] ) . '원)' : '' ) : '';
	return '아임웹: 돈 들어온 주문 ' . (int) $m['n'] . '건 ' . $w( $m['sales'] ) . '원 · 입금 대기 ' . (int) $m['pend_n'] . '건 · 취소 ' . (int) $m['void_n'] . '건' . $cost . ' (' . ( 'api' === ( $m['src'] ?? '' ) ? 'API' : '붙여 넣기' ) . ', ' . (string) ( $m['at'] ?? '' ) . ')';
}

/* ── 관리자 화면 조각 (월말 결산 화면이 부른다) ─────────────────────────── */

function handle_post(): string {
	if ( ! isset( $_POST['dhr_imweb_nonce'] ) || ! wp_verify_nonce( (string) $_POST['dhr_imweb_nonce'], 'dhr_imweb' ) ) { // phpcs:ignore
		return '';
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	$ym = sanitize_text_field( wp_unslash( (string) ( $_POST['dhr_imweb_ym'] ?? '' ) ) ); // phpcs:ignore
	if ( isset( $_POST['dhr_imweb_save'] ) ) {
		$k = sanitize_text_field( wp_unslash( (string) ( $_POST['dhr_imweb_key'] ?? '' ) ) ); // phpcs:ignore
		$s = sanitize_text_field( wp_unslash( (string) ( $_POST['dhr_imweb_secret'] ?? '' ) ) ); // phpcs:ignore
		if ( '' !== $k ) {
			update_option( OPT_KEY, $k, false );
		}
		if ( '' !== $s ) {
			update_option( OPT_SECRET, $s, false );
		}
		delete_transient( 'dhr_imweb_token' );
		return connected() ? 'API 키를 저장했습니다.' : '키와 시크릿을 둘 다 넣어야 합니다.';
	}
	if ( isset( $_POST['dhr_imweb_clear'] ) ) {
		delete_option( OPT_KEY );
		delete_option( OPT_SECRET );
		delete_transient( 'dhr_imweb_token' );
		return 'API 키를 지웠습니다.';
	}
	if ( isset( $_POST['dhr_imweb_test'] ) ) {
		$t = token( true );
		if ( '' === $t['token'] ) {
			return '연결 실패: ' . $t['err'] . ( '' !== $t['raw'] ? ' — 응답: ' . $t['raw'] : '' );
		}
		$r = fetch_month( $ym );
		if ( '' !== $r['err'] ) {
			return '토큰은 받았는데 주문 조회 실패: ' . $r['err'] . ( '' !== $r['raw'] ? ' — 응답: ' . $r['raw'] : '' );
		}
		$tzoff = function_exists( 'wp_timezone' ) ? ( new \DateTime( 'now', wp_timezone() ) )->getOffset() : 32400;
		$sum   = summarize( $r['orders'], $ym, (int) $tzoff );
		put( $ym, $sum, 'api' );
		return '연결됐습니다. ' . $ym . ' 주문 ' . count( $r['orders'] ) . '건을 읽어 저장했습니다 (' . $r['pages'] . '쪽). ' . line( months()[ $ym ] ?? null ) . ( '' !== $r['raw'] ? ' — 첫 응답 앞부분: ' . $r['raw'] : '' );
	}
	if ( isset( $_POST['dhr_imweb_paste'] ) ) {
		$txt = (string) wp_unslash( (string) ( $_POST['dhr_imweb_text'] ?? '' ) ); // phpcs:ignore
		$os  = parse_export( $txt );
		if ( ! $os ) {
			return '붙여 넣은 글에서 주문번호 · 금액 칸을 못 찾았습니다. 아임웹 주문 목록을 엑셀로 내려받아 머리줄까지 통째로 복사해 주세요.';
		}
		$tzoff = function_exists( 'wp_timezone' ) ? ( new \DateTime( 'now', wp_timezone() ) )->getOffset() : 32400;
		$sum   = summarize( $os, $ym, (int) $tzoff );
		put( $ym, $sum, 'paste' );
		return '주문 ' . count( $os ) . '건을 읽어 ' . $ym . ' 에 저장했습니다. ' . line( months()[ $ym ] ?? null );
	}
	return '';
}

function box( string $ym, string $msg = '' ): void {
	$m   = months()[ $ym ] ?? null;
	$err = (array) get_option( 'duckhoo_imweb_last_err', array() );
	echo '<section class="dhr-sl-sec"><h2>아임웹 (옛 사이트) 주문 합치기</h2>';
	if ( '' !== $msg ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<p class="dhr-sl-note">' . esc_html( line( $m ) ) . '</p>';
	if ( $err && ( $err['ym'] ?? '' ) === $ym ) {
		echo '<p class="dhr-sl-note" style="color:#b45309">마지막 API 읽기 실패 (' . esc_html( (string) $err['at'] ) . '): ' . esc_html( (string) $err['err'] ) . ( ! empty( $err['raw'] ) ? ' — 응답: ' . esc_html( (string) $err['raw'] ) : '' ) . '</p>';
	}
	echo '<form method="post" style="margin:0 0 12px">';
	wp_nonce_field( 'dhr_imweb', 'dhr_imweb_nonce' );
	echo '<input type="hidden" name="dhr_imweb_ym" value="' . esc_attr( $ym ) . '">';
	echo '<p><b>① API 로 자동</b> — 아임웹 관리자 → 사이트 설정(환경설정) → API 에서 키 · 시크릿을 만들어 넣습니다. 값은 이 사이트 설정에만 저장되고 화면에는 다시 안 보입니다.</p>';
	echo '<p><input type="password" name="dhr_imweb_key" placeholder="API Key' . ( '' !== key() ? ' (있음)' : '' ) . '" class="regular-text" autocomplete="off"> <input type="password" name="dhr_imweb_secret" placeholder="Secret Key' . ( '' !== secret() ? ' (있음)' : '' ) . '" class="regular-text" autocomplete="off"> ';
	echo '<button class="button" name="dhr_imweb_save" value="1">저장</button> ';
	if ( connected() ) {
		echo '<button class="button button-primary" name="dhr_imweb_test" value="1">연결 시험 · ' . esc_html( $ym ) . ' 읽기</button> <button class="button" name="dhr_imweb_clear" value="1" onclick="return confirm(\'API 키를 지울까요?\')">키 지우기</button>';
	}
	echo '</p>';
	echo '<p><b>② 붙여 넣기</b> — 아임웹 관리자 → 주문 → 주문 목록에서 ' . esc_html( $ym ) . ' 을 골라 엑셀로 내려받고, 머리줄부터 통째로 복사해 붙입니다. 주문번호 · 상태 · 결제금액 칸을 이름으로 찾습니다.</p>';
	echo '<p><textarea name="dhr_imweb_text" rows="5" style="width:100%;max-width:720px;font-size:12px" placeholder="주문번호&#9;주문일&#9;주문상태&#9;결제금액 …"></textarea></p>';
	echo '<p><button class="button" name="dhr_imweb_paste" value="1">읽어서 ' . esc_html( $ym ) . ' 에 저장</button></p>';
	echo '</form>';
	$all = months();
	if ( $all ) {
		echo '<details class="dhr-sl-tab"><summary>저장된 달</summary><div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>달</th><th>돈 들어옴</th><th>금액</th><th>대기</th><th>취소</th><th>어디서</th></tr></thead><tbody>';
		foreach ( array_slice( $all, 0, 12, true ) as $k => $v ) {
			printf( '<tr><td>%s</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%s원</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%d</td><td>%s · %s</td></tr>', esc_html( (string) $k ), (int) $v['n'], esc_html( number_format( (float) $v['sales'] ) ), (int) $v['pend_n'], (int) $v['void_n'], esc_html( 'api' === ( $v['src'] ?? '' ) ? 'API' : '붙여 넣기' ), esc_html( (string) ( $v['at'] ?? '' ) ) );
		}
		echo '</tbody></table></div></details>';
	}
	echo '</section>';
}
