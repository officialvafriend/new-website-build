<?php
/**
 * 마케팅 → 적립금 한 번에 넣기 (관리자).
 *
 * CRM 문자에 「적립금을 넣어 드렸습니다」라고 보내려면 그 전에 수백 명의 잔액에
 * 적립금이 들어가 있어야 한다. 고객관리에서 한 명씩 넣으면 하루가 간다.
 * 이 화면은 엑셀(이름 · 전화번호 · 문자종류 · 적립금)을 받아 **미리 보기**를 먼저
 * 보여 주고, 사장님이 「넣기」를 눌렀을 때만 넣는다.
 *
 * - 넣는 길은 `Points\grant()` 하나 — 잔액 `_keyple_points` 와 원장
 *   `wd_log_keyple_points_change()` 를 **함께** 건드린다 (취소 반환과 같은 길)
 * - 회원은 **전화번호**로 찾는다 (`wd_verified_phone · wd_phone · billing_phone`).
 *   한 번호에 회원이 여럿이면 같은 이메일(쌍둥이 계정)일 때만 본인확인된 쪽으로,
 *   아니면 건너뛴다. 이름이 다르면 기본으로 건너뛴다 (체크하면 넣는다)
 * - CRM 제외 명단(`Crm\exclude_list()`)에 든 사람은 건너뛴다
 * - **같은 묶음은 두 번 안 들어간다** — 회원 메타 `_dhr_pts_<묶음>` 에 넣은 금액을 적는다.
 *   중간에 시간이 모자라 멈추면 다시 누르면 남은 사람만 이어서 넣는다
 * - 되돌리기는 「거두기」 — 그 묶음으로 넣은 만큼 빼고 원장에 회수로 적는다
 *   (이미 써 버린 만큼은 0 아래로 내려가지 않는다)
 *
 * 올린 파일은 저장하지 않는다. 미리 보기 결과는 넣기 전까지 1시간 transient 에만 둔다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Points\Admin;

defined( 'ABSPATH' ) || exit;

const SLUG   = 'duckhoo-points';
const OPT    = 'duckhoo_points_batches';
const META   = '_dhr_pts_';
const RUN    = '_r'; // META . 묶음 . RUN = 그 손님에게 넣은 파일(업로드) 표시.
const WAS    = '_was'; // META . 묶음 . WAS = 받았다가 거둔 금액 (받은 적 있음 기록 · 다시 넣기를 막지 않는다).
const MAX    = 3000;
const AMT_HI = 100000;
const AMT_LO = 100; // 이보다 적은 숫자는 금액이 아니라 날짜 · 병 수일 가능성이 크다 (10/16 「10월 23일까지」의 23 이 23원으로 들어갔다).

/**
 * 머리줄 없는 줄에서 금액을 고른다. 순수.
 *
 * 「5000원」 · 「8,800 원」처럼 원이 붙은 숫자가 있으면 그것(여럿이면 마지막). 없으면
 * 날짜 · 개수 단위(월 · 일 · 병 · 개 · 시 · 분 · % …)가 붙지 않은 마지막 숫자. 「6만9천원」 ·
 * 「10만원」처럼 한글 단위가 끼면 원이 붙은 숫자로 보지 않는다 (문구 속 상품 값이다).
 */
function guess_amount( string $line ): int {
	if ( preg_match_all( '/(\d[\d,]*)\s*원/u', $line, $mm ) ) {
		return (int) str_replace( ',', '', (string) end( $mm[1] ) );
	}
	if ( preg_match_all( '/(\d[\d,]*)(?!\s*(?:월|일|병|개|시|분|초|%|종|세|명|가지|ml|mg|옴|\d|[.,]\d))/iu', $line, $mm ) ) {
		$n = array_filter( $mm[1], fn( $v ) => '' !== $v );
		if ( $n ) {
			return (int) str_replace( ',', '', (string) end( $n ) );
		}
	}
	return 0;
}

/** 이 화면을 볼 수 있는가. */
function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/** 메뉴 — 마케팅 아래 (없으면 도구). */
function menu(): void {
	if ( ! may() ) {
		return;
	}
	$cap    = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	$parent = isset( $GLOBALS['admin_page_hooks']['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'tools.php';
	add_submenu_page( $parent, '적립금 한 번에 넣기', '적립금 한 번에 넣기', $cap, SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 99 );

/** 번호를 숫자만 (+82 → 0). */
function pnorm( string $p ): string {
	$d = (string) preg_replace( '/\D+/', '', $p );
	if ( str_starts_with( $d, '82' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	return preg_match( '/^01\d{8,9}$/', $d ) ? $d : '';
}

/** 이름 견주기용 — 빈칸 · 기호를 뗀다. */
function nnorm( string $n ): string {
	return (string) preg_replace( '/[\s\p{P}\p{S}]+/u', '', $n );
}

/**
 * 표(줄마다 칸 배열)를 지급 줄로 읽는다. 순수.
 *
 * 머리줄이 있으면 칸 이름(이름 · 전화 · 적립 · 종류/문자/메모)으로, 없으면 줄에서
 * 번호를 찾고 번호가 아닌 마지막 숫자를 금액으로 본다. 「지급완료」 칸에 무엇이든 적힌
 * 줄은 건너뛴다.
 *
 * @param array<int,array<int,string>> $table
 * @return array{rows:array<int,array{name:string,phone:string,kind:string,amount:int,line:int}>,bad:array<int,string>}
 */
function read_table( array $table ): array {
	$col  = array();
	$rows = array();
	$bad  = array();
	foreach ( $table as $i => $cells ) {
		$cells = array_map( fn( $c ) => trim( (string) $c ), (array) $cells );
		if ( ! $col ) {
			foreach ( $cells as $k => $c ) {
				if ( preg_match( '/이름|성명|고객명/u', $c ) && ! isset( $col['name'] ) ) {
					$col['name'] = $k;
				} elseif ( preg_match( '/전화|휴대폰|연락처|번호/u', $c ) && ! preg_match( '/^번호$/u', $c ) && ! isset( $col['phone'] ) ) {
					$col['phone'] = $k;
				} elseif ( preg_match( '/적립|금액|포인트/u', $c ) && ! isset( $col['amount'] ) ) {
					$col['amount'] = $k;
				} elseif ( preg_match( '/종류|그룹|문자|메모|사유/u', $c ) && ! isset( $col['kind'] ) ) {
					$col['kind'] = $k;
				} elseif ( preg_match( '/지급완료|완료|지급여부/u', $c ) && ! isset( $col['done'] ) ) {
					$col['done'] = $k;
				}
			}
			if ( isset( $col['phone'], $col['amount'] ) ) {
				continue; // 머리줄.
			}
			$col = array();
		}
		$line = implode( ' ', $cells );
		if ( '' === trim( $line ) ) {
			continue;
		}
		if ( $col ) {
			if ( isset( $col['done'] ) && '' !== ( $cells[ $col['done'] ] ?? '' ) ) {
				continue;
			}
			$phone  = pnorm( $cells[ $col['phone'] ] ?? '' );
			$amount = (int) preg_replace( '/\D+/', '', $cells[ $col['amount'] ] ?? '' );
			$name   = isset( $col['name'] ) ? ( $cells[ $col['name'] ] ?? '' ) : '';
			$kind   = isset( $col['kind'] ) ? ( $cells[ $col['kind'] ] ?? '' ) : '';
		} else {
			$phone = '';
			if ( preg_match( '/(?:\+?82[\s-]?)?0?1[016789][\s-]?\d{3,4}[\s-]?\d{4}/', $line, $m ) ) {
				$phone = pnorm( $m[0] );
				$line  = str_replace( $m[0], ' ', $line );
			}
			$amount = guess_amount( $line );
			$name = '';
			$kind = '';
			if ( preg_match( '/[가-힣]{2,5}/u', $line, $nm ) ) {
				$name = $nm[0];
			}
		}
		if ( '' === $phone || $amount < AMT_LO || $amount > AMT_HI ) {
			$bad[] = mb_substr( implode( ' · ', array_filter( $cells, 'strlen' ) ), 0, 80 );
			continue;
		}
		$rows[] = array(
			'name'   => $name,
			'phone'  => $phone,
			'kind'   => mb_substr( $kind, 0, 40 ),
			'amount' => $amount,
			'line'   => (int) $i + 1,
		);
		if ( count( $rows ) >= MAX ) {
			break;
		}
	}
	return array( 'rows' => $rows, 'bad' => $bad );
}

/** 붙여 넣은 글(엑셀 복사 — 탭으로 칸이 나뉜다)을 표로. */
function paste_table( string $text ): array {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $ln ) {
		if ( false !== strpos( $ln, "\t" ) ) {
			$out[] = explode( "\t", $ln );
		} elseif ( substr_count( $ln, ',' ) >= 2 ) {
			$out[] = array_map( 'trim', str_getcsv( $ln ) ); // CSV — 금액의 쉼표는 따옴표 안에 있다.
		} else {
			$out[] = array( $ln ); // 한 줄 글 — 「5,000원」의 쉼표를 칸으로 나누지 않는다.
		}
	}
	return $out;
}

/**
 * .xlsx 의 첫 시트를 표로 (ZipArchive). 못 읽으면 빈 배열.
 *
 * @return array<int,array<int,string>>
 */
function xlsx_table( string $path ): array {
	if ( ! class_exists( '\\ZipArchive' ) ) {
		return array();
	}
	$z = new \ZipArchive();
	if ( true !== $z->open( $path ) ) {
		return array();
	}
	$ss  = array();
	$raw = $z->getFromName( 'xl/sharedStrings.xml' );
	if ( is_string( $raw ) && '' !== $raw ) {
		$x = @simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NONET ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $x ) {
			foreach ( $x->si as $si ) {
				$ss[] = trim( strip_tags( (string) $si->asXML() ) );
			}
		}
	}
	$sheet = $z->getFromName( 'xl/worksheets/sheet1.xml' );
	$z->close();
	if ( ! is_string( $sheet ) ) {
		return array();
	}
	$x = @simplexml_load_string( $sheet, 'SimpleXMLElement', LIBXML_NONET ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $x || ! isset( $x->sheetData ) ) {
		return array();
	}
	$out = array();
	foreach ( $x->sheetData->row as $row ) {
		$cells = array();
		foreach ( $row->c as $c ) {
			$ref = (string) $c['r'];
			$ci  = 0;
			if ( preg_match( '/^([A-Z]+)/', $ref, $m ) ) {
				foreach ( str_split( $m[1] ) as $ch ) {
					$ci = $ci * 26 + ( ord( $ch ) - 64 );
				}
				--$ci;
			} else {
				$ci = count( $cells );
			}
			$t = (string) $c['t'];
			if ( 's' === $t ) {
				$v = $ss[ (int) $c->v ] ?? '';
			} elseif ( 'inlineStr' === $t ) {
				$v = trim( strip_tags( (string) $c->is->asXML() ) );
			} else {
				$v = (string) $c->v;
			}
			$cells[ $ci ] = $v;
		}
		if ( $cells ) {
			$max = max( array_keys( $cells ) );
			$out[] = array_map( fn( $k ) => $cells[ $k ] ?? '', range( 0, $max ) );
		}
	}
	return $out;
}

/**
 * 줄마다 회원을 정한다. 순수.
 *
 * @param array $rows      read_table() 의 rows.
 * @param array $by_phone  번호 => 회원 번호 목록.
 * @param array $users     회원 번호 => {names:string[], email:string, verified:bool}.
 * @param array $excluded  제외 명단 (이름 또는 번호).
 * @param array $given     회원 번호 => 이 묶음으로 이미 넣은 금액.
 * @return array<int,array<string,mixed>> 줄마다 + uid · status(ok|done|none|many|name|excluded|dup) · note
 */
function plan( array $rows, array $by_phone, array $users, array $excluded, array $given ): array {
	$ex_n = array();
	$ex_p = array();
	foreach ( $excluded as $e ) {
		$e = trim( (string) $e );
		if ( preg_match( '/^\d{8,}$/', $e ) ) {
			$ex_p[ $e ] = true;
		} elseif ( '' !== $e ) {
			$ex_n[ nnorm( $e ) ] = true;
		}
	}
	$seen = array();
	$out  = array();
	foreach ( $rows as $r ) {
		$r['uid']  = 0;
		$r['note'] = '';
		if ( isset( $ex_p[ $r['phone'] ] ) || ( '' !== $r['name'] && isset( $ex_n[ nnorm( $r['name'] ) ] ) ) ) {
			$r['status'] = 'excluded';
			$out[]       = $r;
			continue;
		}
		$ids = array_values( array_unique( array_map( 'intval', (array) ( $by_phone[ $r['phone'] ] ?? array() ) ) ) );
		if ( count( $ids ) > 1 ) {
			$mails = array_unique( array_map( fn( $id ) => strtolower( (string) ( $users[ $id ]['email'] ?? '' ) ), $ids ) );
			if ( 1 === count( $mails ) && '' !== reset( $mails ) ) {
				usort( $ids, fn( $a, $b ) => array( (int) ! empty( $users[ $b ]['verified'] ), $b ) <=> array( (int) ! empty( $users[ $a ]['verified'] ), $a ) );
				$r['note'] = '같은 이메일 계정 ' . count( $ids ) . '개 중 #' . $ids[0];
				$ids       = array( $ids[0] );
			}
		}
		if ( ! $ids ) {
			$r['status'] = 'none';
		} elseif ( count( $ids ) > 1 ) {
			$hit = array_values( array_filter( $ids, fn( $id ) => ! empty( $given[ $id ] ) ) );
			if ( $hit ) {
				$r['uid']    = $hit[0];
				$r['status'] = 'done';
				$r['note']   = '이 묶음으로 이미 ' . number_format( (int) $given[ $hit[0] ] ) . '원 (#' . $hit[0] . ')';
			} else {
				// 같은 사람이 이메일을 바꿔 두 번 가입한 경우가 대부분 — 사장님이 고르게 하고, 쓰는 계정을 먼저 세운다.
				$nm = nnorm( $r['name'] );
				$score = fn( $id ) => array(
					'' !== $nm && in_array( $nm, array_map( __NAMESPACE__ . '\\nnorm', (array) ( $users[ $id ]['names'] ?? array() ) ), true ) ? 1 : 0,
					(int) ( $users[ $id ]['orders'] ?? 0 ),
					! empty( $users[ $id ]['verified'] ) ? 1 : 0,
					$id,
				);
				usort( $ids, fn( $a, $b ) => $score( $b ) <=> $score( $a ) );
				$r['status'] = 'many';
				$r['cands']  = $ids;
				$r['cinfo']  = array_intersect_key( $users, array_flip( $ids ) );
				$r['note']   = '이 번호의 회원 ' . count( $ids ) . '명 (#' . implode( ' #', $ids ) . ')';
			}
		} else {
			$uid      = $ids[0];
			$r['uid'] = $uid;
			$names    = array_map( __NAMESPACE__ . '\\nnorm', (array) ( $users[ $uid ]['names'] ?? array() ) );
			if ( isset( $seen[ $uid ] ) ) {
				$r['status'] = 'dup';
				$r['note']   = $seen[ $uid ] . '번째 줄과 같은 회원';
			} elseif ( ! empty( $given[ $uid ] ) ) {
				$r['status'] = 'done';
				$r['note']   = '이 묶음으로 이미 ' . number_format( (int) $given[ $uid ] ) . '원';
			} elseif ( '' !== $r['name'] && $names && ! in_array( nnorm( $r['name'] ), $names, true ) ) {
				$r['status'] = 'name';
				$r['note']   = '사이트 이름: ' . implode( ' / ', array_slice( (array) $users[ $uid ]['names'], 0, 2 ) );
			} else {
				$r['status'] = 'ok';
			}
			$seen[ $uid ] = $seen[ $uid ] ?? $r['line'];
		}
		$out[] = $r;
	}
	return $out;
}

/** 묶음 이름 → 짧은 열쇠. */
function bkey( string $label ): string {
	return substr( md5( $label ), 0, 10 );
}

/** 번호 => 회원 번호들 (한 질의). */
function phones_map( array $phones ): array {
	global $wpdb;
	$want = array_fill_keys( $phones, true );
	$out  = array();
	$rows = (array) $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('wd_verified_phone','wd_phone','billing_phone') AND meta_value <> ''", ARRAY_A ); // phpcs:ignore WordPress.DB
	foreach ( $rows as $r ) {
		$p = pnorm( (string) $r['meta_value'] );
		if ( '' !== $p && isset( $want[ $p ] ) ) {
			$out[ $p ][ (int) $r['user_id'] ] = (int) $r['user_id'];
		}
	}
	return array_map( 'array_values', $out );
}

/** 회원 정보 (이름 후보 · 이메일 · 본인확인 · 이 묶음 지급액). */
function users_info( array $ids, string $key ): array {
	$out = array();
	foreach ( array_unique( $ids ) as $id ) {
		$u = get_userdata( (int) $id );
		if ( ! $u ) {
			continue;
		}
		$names = array_values( array_unique( array_filter( array(
			(string) get_user_meta( $id, 'wd_verified_name', true ),
			trim( get_user_meta( $id, 'billing_last_name', true ) . get_user_meta( $id, 'billing_first_name', true ) ),
			trim( $u->last_name . $u->first_name ),
			(string) $u->display_name,
		) ) ) );
		$out[ $id ] = array(
			'names'    => $names,
			'email'    => (string) $u->user_email,
			'verified' => '1' === (string) get_user_meta( $id, 'wd_phone_verified', true ) || '' !== (string) get_user_meta( $id, '_dhr_legacy_verified', true ),
			'given'    => (int) get_user_meta( $id, META . $key, true ),
			'reg'      => substr( (string) $u->user_registered, 0, 10 ),
			'points'   => (int) get_user_meta( $id, '_keyple_points', true ),
			'orders'   => 0,
		);
	}
	foreach ( order_counts( array_keys( $out ) ) as $id => $n ) {
		$out[ $id ]['orders'] = $n;
	}
	return $out;
}

/** 회원 번호 => 주문 수 (임시글 · 실패 제외, 한 질의). */
function order_counts( array $ids ): array {
	global $wpdb;
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	if ( ! $ids || ! isset( $wpdb ) ) {
		return array();
	}
	$in   = implode( ',', $ids );
	$hpos = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\hpos' ) && \Duckhoo\Redesign\Anatomy\hpos();
	$sql  = $hpos
		? "SELECT customer_id AS u, COUNT(*) AS n FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status NOT IN ('wc-checkout-draft','wc-failed','trash') AND customer_id IN ($in) GROUP BY customer_id"
		: "SELECT m.meta_value AS u, COUNT(*) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_customer_user' AND p.post_type = 'shop_order' AND p.post_status NOT IN ('wc-checkout-draft','wc-failed','trash') AND m.meta_value IN ($in) GROUP BY m.meta_value";
	$out = array();
	foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
		$out[ (int) $r['u'] ] = (int) $r['n'];
	}
	return $out;
}

/** 미리 보기를 만든다 (DB 를 읽기만). */
function build( array $rows, string $label ): array {
	$key   = bkey( $label );
	$map   = phones_map( array_column( $rows, 'phone' ) );
	$ids   = array();
	foreach ( $map as $l ) {
		$ids = array_merge( $ids, $l );
	}
	$users = users_info( $ids, $key );
	$given = array_filter( array_map( fn( $u ) => $u['given'], $users ) );
	$excl  = function_exists( '\\Duckhoo\\Redesign\\Crm\\exclude_list' ) ? \Duckhoo\Redesign\Crm\exclude_list() : array();
	return plan( $rows, $map, $users, $excl, $given );
}

/** 상태 이름. */
function status_label( string $s ): string {
	return array(
		'ok'       => '넣을 사람',
		'name'     => '이름이 다름',
		'none'     => '회원 못 찾음',
		'many'     => '번호가 여럿',
		'dup'      => '같은 사람 두 줄',
		'done'     => '이미 넣음',
		'excluded' => '제외 명단',
	)[ $s ] ?? $s;
}

/** 넣기 — 시간이 모자라면 멈추고 남은 수를 돌려준다. */
function run_grant( array $plan, string $label, bool $with_name, float $budget = 20.0, array $picks = array(), string $run = '', string $file = '' ): array {
	$key   = bkey( $label );
	$t0    = microtime( true );
	$n     = 0;
	$sum   = 0;
	$left  = 0;
	$fail  = 0;
	foreach ( $plan as $r ) {
		if ( 'many' === $r['status'] ) {
			$pick = (int) ( $picks[ $r['line'] ] ?? 0 );
			if ( $pick > 0 && in_array( $pick, (array) ( $r['cands'] ?? array() ), true ) ) {
				$r['uid']    = $pick; // 사장님이 고른 계정 — 후보 안의 것만 받는다.
				$r['status'] = 'ok';
			}
		}
		if ( ! ( 'ok' === $r['status'] || ( $with_name && 'name' === $r['status'] ) ) ) {
			continue;
		}
		if ( microtime( true ) - $t0 > $budget ) {
			++$left;
			continue;
		}
		$uid = (int) $r['uid'];
		if ( (int) get_user_meta( $uid, META . $key, true ) > 0 ) {
			continue; // 두 번 안 넣는다.
		}
		// 원장 사유는 손님의 적립금 내역에 그대로 보인다 — 「이탈 방지」 같은 내부 그룹 이름은 넣지 않는다.
		if ( \Duckhoo\Redesign\Points\grant( $uid, (int) $r['amount'], $label ) ) {
			update_user_meta( $uid, META . $key, (int) $r['amount'] );
			if ( '' !== $run ) {
				update_user_meta( $uid, META . $key . RUN, $run ); // 어느 파일로 넣었는지 — 파일마다 거둘 수 있게.
			}
			++$n;
			$sum += (int) $r['amount'];
		} else {
			++$fail;
		}
	}
	$all = (array) get_option( OPT, array() );
	$b   = $all[ $key ] ?? array( 'label' => $label, 'created' => time(), 'n' => 0, 'sum' => 0 );
	$b['n']       = (int) $b['n'] + $n;
	$b['sum']     = (int) $b['sum'] + $sum;
	$b['updated'] = time();
	unset( $b['revoked'] );
	if ( '' !== $run && $n > 0 ) {
		$rr = (array) ( $b['runs'][ $run ] ?? array( 'at' => time(), 'n' => 0, 'sum' => 0, 'file' => $file ) );
		$rr['n']   = (int) $rr['n'] + $n;
		$rr['sum'] = (int) $rr['sum'] + $sum;
		$b['runs'][ $run ] = $rr;
	}
	$all[ $key ] = $b;
	update_option( OPT, $all, false );
	return array( 'n' => $n, 'sum' => $sum, 'left' => $left, 'fail' => $fail );
}

/**
 * 거두기 — 그 묶음으로 넣은 만큼 뺀다.
 *
 * $only 를 주면 그 일부만: `array( 'amt' => 23 )` 은 23원을 받은 사람만,
 * `array( 'run' => '…' )` 은 그 파일로 넣은 사람만. 같은 사유로 여러 번 넣었을 때
 * 한 번 넣은 것만 거두는 길이다.
 */
function run_revoke( string $key, float $budget = 20.0, array $only = array() ): array {
	global $wpdb;
	$all = (array) get_option( OPT, array() );
	if ( ! isset( $all[ $key ] ) ) {
		return array( 'n' => 0, 'sum' => 0, 'left' => 0 );
	}
	$label = (string) $all[ $key ]['label'];
	$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", META . $key ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$t0    = microtime( true );
	$n     = 0;
	$sum   = 0;
	$left  = 0;
	foreach ( $rows as $r ) {
		if ( microtime( true ) - $t0 > $budget ) {
			++$left;
			continue;
		}
		$uid = (int) $r['user_id'];
		$amt = (int) $r['meta_value'];
		if ( isset( $only['amt'] ) && $amt !== (int) $only['amt'] ) {
			continue;
		}
		if ( isset( $only['run'] ) && (string) get_user_meta( $uid, META . $key . RUN, true ) !== (string) $only['run'] ) {
			continue;
		}
		$bal = (int) get_user_meta( $uid, '_keyple_points', true );
		$take = min( $amt, max( 0, $bal ) );
		if ( $take > 0 ) {
			\Duckhoo\Redesign\Points\grant( $uid, -$take, $label . ' 사용기간 종료' );
			$sum += $take;
		}
		// 받은 적 있음은 남긴다 — 참여 · CRM 기간 성과가 거둔 뒤에도 받은 사람을 센다 (10/13 묶음이 0명으로 나왔다).
		update_user_meta( $uid, META . $key . WAS, $amt );
		$run = (string) get_user_meta( $uid, META . $key . RUN, true );
		if ( '' !== $run ) {
			update_user_meta( $uid, META . $key . WAS . RUN, $run );
		}
		delete_user_meta( $uid, META . $key );
		delete_user_meta( $uid, META . $key . RUN );
		++$n;
	}
	$all[ $key ]['n']   = max( 0, (int) $all[ $key ]['n'] - $n );
	$all[ $key ]['sum'] = max( 0, (int) $all[ $key ]['sum'] - $sum );
	if ( isset( $only['run'] ) ) {
		unset( $all[ $key ]['runs'][ (string) $only['run'] ] );
	}
	if ( $only && $all[ $key ]['n'] > 0 ) {
		$all[ $key ]['part_revoked'] = time(); // 일부만 거뒀다 — 묶음은 살아 있다.
	} else {
		$all[ $key ]['revoked'] = time();
	}
	update_option( OPT, $all, false );
	return array( 'n' => $n, 'sum' => $sum, 'left' => $left );
}


/**
 * 묶음 안에서 받은 금액별 인원 — 같은 사유로 여러 번 넣었을 때 잘못 넣은 한 번을 가려 거두려고.
 *
 * @return array<int,int> 금액 => 인원 (많은 금액부터).
 */
function amount_groups( string $key ): array {
	global $wpdb;
	$out = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS a, COUNT(*) AS n FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value", META . $key ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
		$out[ (int) $r['a'] ] = (int) $r['n'];
	}
	krsort( $out );
	return $out;
}

/* ── 넣었다 거둔 묶음 — 손님 적립금 내역에서 그 줄 지우기 ───────────────── */

/** 원장 표 이름. */
function log_table(): string {
	global $wpdb;
	if ( function_exists( 'wd_get_keyple_points_log_table' ) ) {
		$t = (string) wd_get_keyple_points_log_table();
		if ( '' !== $t ) {
			return $t;
		}
	}
	return $wpdb->prefix . 'keyple_points_log';
}

/**
 * 원장 칸 이름을 짐작한다. 순수.
 *
 * @param string[] $cols 표의 칸 이름.
 * @return array{id:string,user:string,amount:string,reason:string}|null 넷 중 하나라도 없으면 null.
 */
function guess_cols( array $cols ): ?array {
	$pick = function ( array $names ) use ( $cols ) {
		foreach ( $names as $n ) {
			foreach ( $cols as $c ) {
				if ( strtolower( (string) $c ) === $n ) {
					return (string) $c;
				}
			}
		}
		return '';
	};
	$out = array(
		'id'     => $pick( array( 'id', 'log_id', 'point_id', 'idx', 'no' ) ),
		'user'   => $pick( array( 'user_id', 'uid', 'customer_id', 'member_id', 'userid' ) ),
		'amount' => $pick( array( 'points', 'point', 'amount', 'change', 'delta', 'change_amount', 'point_change', 'value' ) ),
		'reason' => $pick( array( 'reason', 'memo', 'description', 'note', 'message', 'title', 'content', 'comment', 'label', 'detail', 'details' ) ),
	);
	return in_array( '', $out, true ) ? null : $out;
}

/** 그 묶음 이름으로 찍힌 사유인가 (넣은 줄 · 거둔 줄). 순수. */
function is_batch_reason( string $reason, string $label ): ?int {
	$r = trim( $reason );
	if ( '' === $label ) {
		return null;
	}
	if ( $r === $label . ' 회수' || $r === $label . ' 사용기간 종료' ) {
		return -1;
	}
	if ( $r === $label || str_starts_with( $r, $label . ' · ' ) ) {
		return 1;
	}
	return null;
}

/**
 * 지울 줄을 고른다. 순수 — 회원마다 넣은 줄과 거둔 줄의 합이 0 일 때만 지운다.
 *
 * @param array<int,array{id:int,user:int,amount:int,reason:string}> $rows 원장 줄.
 * @return array{del:int[],users:int,rows:int,left:array<int,int>}
 */
function cleanup_pick( array $rows, string $label ): array {
	$by = array();
	foreach ( $rows as $r ) {
		$sign = is_batch_reason( (string) $r['reason'], $label );
		if ( null === $sign ) {
			continue;
		}
		$amt = (int) $r['amount'];
		$amt = $amt < 0 ? $amt : $sign * $amt; // 크기만 적는 원장이면 사유로 부호를 정한다.
		$by[ (int) $r['user'] ][] = array( (int) $r['id'], $amt );
	}
	// 같은 크기의 넣은 줄 · 거둔 줄을 짝지어 지운다 — 짝의 합은 0 이라 잔액이 안 바뀐다.
	// 그래서 23원을 넣었다 거두고 5,000원을 다시 넣은 손님도 23원 두 줄만 지워진다.
	$del  = array();
	$left = array();
	$n    = 0;
	foreach ( $by as $uid => $list ) {
		$pos  = array_values( array_filter( $list, fn( $x ) => $x[1] > 0 ) );
		$gone = array();
		$hit  = false;
		foreach ( $list as $x ) {
			if ( $x[1] >= 0 ) {
				continue;
			}
			foreach ( $pos as $j => $y ) {
				if ( ! isset( $gone[ $j ] ) && $y[1] === -$x[1] ) {
					$gone[ $j ] = true;
					$del[]      = $y[0];
					$del[]      = $x[0];
					$hit        = true;
					continue 2;
				}
			}
		}
		if ( $hit ) {
			++$n;
		}
		$rest = 0;
		foreach ( $list as $x ) {
			if ( ! in_array( $x[0], $del, true ) ) {
				$rest += $x[1];
			}
		}
		if ( ! $hit || 0 !== $rest ) {
			if ( ! $hit || $rest < 0 ) {
				$left[ $uid ] = $rest;
			}
		}
	}
	sort( $del );
	return array( 'del' => $del, 'users' => $n, 'rows' => count( $del ), 'left' => $left );
}

/** 그 묶음의 원장 줄을 읽는다. */
function cleanup_scan( string $key ): array {
	global $wpdb;
	$all   = (array) get_option( OPT, array() );
	$label = (string) ( $all[ $key ]['label'] ?? '' );
	$table = log_table();
	$out   = array( 'label' => $label, 'cols' => array(), 'map' => null, 'pick' => null, 'sample' => array() );
	if ( '' === $label || (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB
		return $out;
	}
	$out['cols'] = array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ) ); // phpcs:ignore WordPress.DB
	$map         = guess_cols( $out['cols'] );
	$out['map']  = $map;
	if ( ! $map ) {
		$out['sample'] = (array) $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $table ) . '` ORDER BY 1 DESC LIMIT 3', ARRAY_A ); // phpcs:ignore WordPress.DB
		return $out;
	}
	$q    = $wpdb->prepare(
		'SELECT `' . esc_sql( $map['id'] ) . '` AS id, `' . esc_sql( $map['user'] ) . '` AS user, `' . esc_sql( $map['amount'] ) . '` AS amount, `' . esc_sql( $map['reason'] ) . '` AS reason FROM `' . esc_sql( $table ) . '` WHERE `' . esc_sql( $map['reason'] ) . '` LIKE %s', // phpcs:ignore WordPress.DB
		$wpdb->esc_like( $label ) . '%'
	);
	$rows        = (array) $wpdb->get_results( $q, ARRAY_A ); // phpcs:ignore WordPress.DB
	$out['pick'] = cleanup_pick( $rows, $label );
	return $out;
}

/** 지운다 — 고른 번호만, 500 줄씩. */
function run_cleanup( string $key ): array {
	global $wpdb;
	$scan = cleanup_scan( $key );
	if ( ! $scan['map'] || ! $scan['pick'] || ! $scan['pick']['del'] ) {
		return array( 'rows' => 0, 'users' => 0 );
	}
	$table = log_table();
	$done  = 0;
	foreach ( array_chunk( array_map( 'intval', $scan['pick']['del'] ), 500 ) as $chunk ) {
		$done += (int) $wpdb->query( 'DELETE FROM `' . esc_sql( $table ) . '` WHERE `' . esc_sql( $scan['map']['id'] ) . '` IN (' . implode( ',', $chunk ) . ')' ); // phpcs:ignore WordPress.DB
	}
	$all = (array) get_option( OPT, array() );
	if ( isset( $all[ $key ] ) ) {
		$all[ $key ]['cleaned'] = time();
		update_option( OPT, $all, false );
	}
	return array( 'rows' => $done, 'users' => (int) $scan['pick']['users'] );
}

/** 미리 보기 상자. */
function cleanup_box( string $key ): string {
	$s = cleanup_scan( $key );
	$h = '<div style="margin:16px 0;padding:16px;border:2px solid #b42318;border-radius:8px;max-width:860px;background:#fff">';
	$h .= '<h3 style="margin-top:0">손님 적립금 내역에서 지우기 — ' . esc_html( $s['label'] ) . '</h3>';
	if ( ! $s['map'] ) {
		$h .= '<p>적립금 원장 표의 칸을 알아보지 못해 지우지 않았습니다. 이 상자를 캡처해 주세요.</p><p>칸: ' . esc_html( implode( ', ', $s['cols'] ) ) . '</p>';
		foreach ( $s['sample'] as $row ) {
			$h .= '<p style="font:12px monospace">' . esc_html( wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) ) . '</p>';
		}
		return $h . '</div>';
	}
	$p  = $s['pick'];
	$h .= '<p>이 이름으로 넣었다 거둔 줄을 지웁니다. <b>넣은 줄과 거둔 줄의 합이 0 인 손님만</b> 지우므로 잔액은 바뀌지 않습니다.</p>';
	$h .= '<p><b>' . number_format( $p['users'] ) . '명 · ' . number_format( $p['rows'] ) . '줄</b>을 지웁니다.';
	if ( $p['left'] ) {
		$h .= ' 합이 0 이 아닌 ' . number_format( count( $p['left'] ) ) . '명은 그대로 둡니다 (그 사이 적립금을 썼거나 거두기가 덜 됐습니다).';
	}
	$h .= '</p><p style="color:#646970">칸: 번호 ' . esc_html( $s['map']['id'] ) . ' · 회원 ' . esc_html( $s['map']['user'] ) . ' · 금액 ' . esc_html( $s['map']['amount'] ) . ' · 사유 ' . esc_html( $s['map']['reason'] ) . '</p>';
	if ( $p['rows'] > 0 ) {
		$h .= '<form method="post">' . wp_nonce_field( 'dhr-pts', '_wpnonce', true, false ) . '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><button class="button button-primary" name="dhr_pts" value="cleanup" onclick="return confirm(\'' . esc_js( number_format( $p['rows'] ) . '줄을 지울까요? 되돌릴 수 없습니다.' ) . '\')">' . esc_html( number_format( $p['rows'] ) ) . '줄 지우기</button></form>';
	}
	return $h . '</div>';
}


/* ── 참여 — 받은 사람이 다시 샀는가 · 적립금을 썼는가 ───────────────────── */

/**
 * 묶음 하나의 참여를 센다. 순수.
 *
 * @param array<int,array{0:int,1:string}> $recips 회원 번호 => [받은 금액, 문자종류].
 * @param array<int,array{id:int,ts:int,s:string,t:float,u:int}> $orders 주문 (ts 는 실제 시각).
 * @param array<int,int> $used 주문 번호 => 그 주문에서 쓴 적립금.
 * @param int   $at   넣은 시각.
 * @param int   $now  지금.
 * @param array $paid 돈 들어온 상태.
 * @param array $pend 입금 전 상태.
 * @return array<string,array<string,mixed>> '' => 전체, 문자종류 => 그 그룹.
 */
function participation( array $recips, array $orders, array $used, int $at, int $now, array $paid, array $pend ): array {
	$len  = max( DAY_IN_SECONDS, $now - $at );
	$by   = array();
	foreach ( $orders as $o ) {
		$u = (int) $o['u'];
		if ( $u > 0 && isset( $recips[ $u ] ) ) {
			$by[ $u ][] = $o;
		}
	}
	$blank = fn() => array( 'n' => 0, 'given' => 0, 'buyers' => 0, 'pend' => 0, 'orders' => 0, 'sales' => 0.0, 'users' => 0, 'used' => 0, 'before' => 0, 'days' => array() );
	$out   = array( '' => $blank() );
	foreach ( $recips as $uid => $rv ) {
		$kind = '' !== (string) ( $rv[1] ?? '' ) ? (string) $rv[1] : '(종류 없음)';
		$out[ $kind ] = $out[ $kind ] ?? $blank();
		// 같은 사유로 여러 파일을 넣었으면 그 사람이 받은 파일의 시각부터 센다 (셋째 칸, 없으면 묶음 시각).
		$ua    = (int) ( $rv[2] ?? 0 ) > 0 ? (int) $rv[2] : $at;
		$ulen  = max( DAY_IN_SECONDS, $now - $ua );
		$after = array_filter( (array) ( $by[ $uid ] ?? array() ), fn( $o ) => $o['ts'] >= $ua && $o['ts'] <= $now );
		$prev  = array_filter( (array) ( $by[ $uid ] ?? array() ), fn( $o ) => $o['ts'] < $ua && $o['ts'] >= $ua - $ulen && in_array( $o['s'], $paid, true ) );
		$ok    = array_filter( $after, fn( $o ) => in_array( $o['s'], $paid, true ) );
		$wait  = array_filter( $after, fn( $o ) => in_array( $o['s'], $pend, true ) );
		$spent = 0;
		foreach ( array_merge( $ok, $wait ) as $o ) {
			$spent += (int) ( $used[ (int) $o['id'] ] ?? 0 );
		}
		$first = $ok ? min( array_column( $ok, 'ts' ) ) - $ua + $at : 0;
		foreach ( array( '', $kind ) as $k ) {
			$r = &$out[ $k ];
			++$r['n'];
			$r['given'] += (int) $rv[0];
			if ( $ok ) {
				++$r['buyers'];
				$r['orders'] += count( $ok );
				$r['sales']  += array_sum( array_column( $ok, 't' ) );
				$r['days'][]  = ( $first - $at ) / DAY_IN_SECONDS;
			} elseif ( $wait ) {
				++$r['pend'];
			}
			if ( $spent > 0 ) {
				++$r['users'];
				$r['used'] += $spent;
			}
			if ( $prev ) {
				++$r['before'];
			}
			unset( $r );
		}
	}
	foreach ( $out as &$r ) {
		$d = $r['days'];
		sort( $d );
		$r['first_med'] = $d ? $d[ (int) floor( ( count( $d ) - 1 ) / 2 ) ] : null;
		$r['rate']      = $r['n'] ? $r['buyers'] / $r['n'] : 0.0;
		$r['use_rate']  = $r['n'] ? $r['users'] / $r['n'] : 0.0;
		unset( $r['days'] );
	}
	unset( $r );
	$out['']['elapsed'] = (int) floor( $len / DAY_IN_SECONDS );
	return $out;
}

/** 주문마다 쓴 적립금 (`_wd_point_discount`). 한 질의. */
function used_points( int $since ): array {
	global $wpdb;
	$gmt  = gmdate( 'Y-m-d H:i:s', $since - DAY_IN_SECONDS );
	$hpos = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\hpos' ) && \Duckhoo\Redesign\Anatomy\hpos();
	$sql  = $hpos
		? $wpdb->prepare( "SELECT m.order_id AS id, m.meta_value AS v FROM {$wpdb->prefix}wc_orders_meta m JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id WHERE m.meta_key = '_wd_point_discount' AND o.date_created_gmt >= %s", $gmt )
		: $wpdb->prepare( "SELECT m.post_id AS id, m.meta_value AS v FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wd_point_discount' AND p.post_type = 'shop_order' AND p.post_date_gmt >= %s", $gmt );
	$out = array();
	foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
		$v = (int) round( abs( (float) $r['v'] ) );
		if ( $v > 0 ) {
			$out[ (int) $r['id'] ] = $v;
		}
	}
	return $out;
}

/** 묶음의 참여 — DB 를 읽어 센다 (10분 캐시). */
function participation_for( string $key, int $until = 0 ): ?array {
	global $wpdb;
	$all = (array) get_option( OPT, array() );
	if ( ! isset( $all[ $key ] ) ) {
		return null;
	}
	$now = $until > 0 ? min( $until, time() ) : time();
	$tk  = 'dhr_pts_part_' . $key . ( $until > 0 ? '_' . gmdate( 'YmdH', $now ) : '' );
	$c   = get_transient( $tk );
	if ( is_array( $c ) ) {
		return $c;
	}
	$b      = $all[ $key ];
	$at     = (int) ( $b['created'] ?? time() );
	$kinds  = (array) ( $b['kinds'] ?? array() );
	$recips = received( $key, $b );
	$off    = (int) ( function_exists( 'wp_timezone' ) ? ( new \DateTime( 'now', wp_timezone() ) )->getOffset() : 9 * 3600 );
	$orders = array();
	foreach ( ( function_exists( '\\Duckhoo\\Redesign\\Anatomy\\orders' ) ? \Duckhoo\Redesign\Anatomy\orders() : array() ) as $o ) {
		$o['ts'] = (int) $o['ts'] - $off; // 사이트 시간으로 밀려 있다
		if ( $o['ts'] >= $at - 60 * DAY_IN_SECONDS ) {
			$orders[] = $o;
		}
	}
	$paid = function_exists( '\\Duckhoo\\Redesign\\Crm\\paid_statuses' ) ? \Duckhoo\Redesign\Crm\paid_statuses() : array( 'payment-confirmed', 'ready-to-ship', 'shipping', 'delivered', 'completed', 'processing' );
	$pend = function_exists( '\\Duckhoo\\Redesign\\Crm\\pending_statuses' ) ? \Duckhoo\Redesign\Crm\pending_statuses() : array( 'on-hold', 'pending' );
	$res  = array( 'label' => (string) $b['label'], 'at' => $at, 'kinds' => count( $kinds ), 'p' => participation( $recips, $orders, used_points( $at ), $at, $now, $paid, $pend ) );
	set_transient( $tk, $res, 10 * MINUTE_IN_SECONDS );
	return $res;
}

/**
 * 원장 줄에서 그 묶음으로 받은 사람 — 거둘 때 기록을 지우던 때(2026-10-27 전)에 거둔 묶음을 되살린다. 순수.
 *
 * @param array<int,array{user:int|string,amount:int|string,reason:string}> $rows 원장 줄.
 * @return array<int,int> 회원 => 받은 금액.
 */
function ledger_received( array $rows, string $label ): array {
	$out = array();
	foreach ( $rows as $r ) {
		$uid = (int) ( $r['user'] ?? 0 );
		$amt = abs( (int) ( $r['amount'] ?? 0 ) );
		if ( $uid > 0 && $amt > 0 && 1 === is_batch_reason( (string) ( $r['reason'] ?? '' ), $label ) ) {
			$out[ $uid ] = ( $out[ $uid ] ?? 0 ) + $amt;
		}
	}
	return $out;
}

/** 원장 표에서 그 묶음 이름으로 찍힌 줄을 읽는다. 표 · 칸을 못 알아보면 빈 배열. */
function ledger_rows_for( string $label ): array {
	global $wpdb;
	$table = log_table();
	if ( '' === $label || (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB
		return array();
	}
	$map = guess_cols( array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ) ) ); // phpcs:ignore WordPress.DB
	if ( ! $map ) {
		return array();
	}
	$q = $wpdb->prepare(
		'SELECT `' . esc_sql( $map['user'] ) . '` AS user, `' . esc_sql( $map['amount'] ) . '` AS amount, `' . esc_sql( $map['reason'] ) . '` AS reason FROM `' . esc_sql( $table ) . '` WHERE `' . esc_sql( $map['reason'] ) . '` LIKE %s', // phpcs:ignore WordPress.DB
		$wpdb->esc_like( $label ) . '%'
	);
	return (array) $wpdb->get_results( $q, ARRAY_A ); // phpcs:ignore WordPress.DB
}

/**
 * 그 묶음으로 받은 적 있는 사람 — 지금 갖고 있는 사람 + 받았다가 거둔 사람.
 * 거둔 기록이 없는 옛 묶음(거둘 때 지웠다)은 원장에서 되살린다.
 *
 * @return array<int,array{0:int,1:string,2:int}> 회원 => [금액, 문자종류, 넣은 시각(모르면 0)].
 */
function received( string $key, array $b ): array {
	global $wpdb;
	$kinds = (array) ( $b['kinds'] ?? array() );
	$runs  = (array) ( $b['runs'] ?? array() );
	$out   = array();
	foreach ( array( '', WAS ) as $suf ) {
		$urun = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", META . $key . $suf . RUN ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$urun[ (int) $r['user_id'] ] = (string) $r['meta_value'];
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", META . $key . $suf ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$uid = (int) $r['user_id'];
			if ( ! isset( $out[ $uid ] ) ) {
				$out[ $uid ] = array( (int) $r['meta_value'], (string) ( $kinds[ $uid ] ?? '' ), (int) ( $runs[ $urun[ $uid ] ?? '' ]['at'] ?? 0 ) );
			}
		}
	}
	if ( ! empty( $b['revoked'] ) || ! empty( $b['part_revoked'] ) ) {
		foreach ( ledger_received( ledger_rows_for( (string) ( $b['label'] ?? '' ) ), (string) ( $b['label'] ?? '' ) ) as $uid => $amt ) {
			if ( ! isset( $out[ $uid ] ) ) {
				$out[ $uid ] = array( $amt, (string) ( $kinds[ $uid ] ?? '' ), 0 );
			}
		}
	}
	return $out;
}

/** 참여 상자. */
function participation_box( string $key ): string {
	$r = participation_for( $key );
	if ( ! $r ) {
		return '';
	}
	$p   = $r['p'];
	$t   = $p[''];
	$pct = fn( $x ) => number_format( $x * 100, 1 ) . '%';
	$h   = '<div style="margin:16px 0;padding:16px;border:2px solid #1a5cff;border-radius:8px;max-width:960px;background:#fff">';
	$h  .= '<h3 style="margin-top:0">참여 — ' . esc_html( $r['label'] ) . ' <span style="font-weight:400;color:#646970">(' . esc_html( wp_date( 'n월 j일 H:i', $r['at'] ) ) . ' 넣음 · ' . (int) $t['elapsed'] . '일째)</span></h3>';
	$h  .= '<p style="font-size:15px">받은 <b>' . number_format( $t['n'] ) . '명</b> 중 <b>' . number_format( $t['buyers'] ) . '명 주문 (' . $pct( $t['rate'] ) . ')</b> · 적립금을 쓴 사람 <b>' . number_format( $t['users'] ) . '명 (' . $pct( $t['use_rate'] ) . ')</b> · 입금 전으로만 남은 사람 ' . number_format( $t['pend'] ) . '명</p>';
	$h  .= '<p>주문 ' . number_format( $t['orders'] ) . '건 · 실입금 <b>' . number_format( (int) $t['sales'] ) . '원</b> · 쓴 적립금 ' . number_format( $t['used'] ) . '원 / 넣은 적립금 ' . number_format( $t['given'] ) . '원'
		. ( null !== $t['first_med'] ? ' · 넣고 첫 주문까지 가운데 ' . number_format( (float) $t['first_med'], 1 ) . '일' : '' ) . '</p>';
	$h  .= '<p style="color:#646970">견줄 값: 같은 사람들이 넣기 전 ' . (int) $t['elapsed'] . '일 동안 산 사람 ' . number_format( $t['before'] ) . '명.</p>';
	$rows = array_filter( $p, fn( $k ) => '' !== $k, ARRAY_FILTER_USE_KEY );
	if ( count( $rows ) > 1 || ( 1 === count( $rows ) && ! isset( $rows['(종류 없음)'] ) ) ) {
		$h .= '<table class="widefat striped"><thead><tr><th>문자종류</th><th>받은 사람</th><th>주문한 사람</th><th>적립금 쓴 사람</th><th>주문</th><th>실입금</th><th>넣기 전 같은 기간</th></tr></thead><tbody>';
		foreach ( $rows as $k => $v ) {
			$h .= '<tr><td>' . esc_html( $k ) . '</td><td>' . number_format( $v['n'] ) . '</td><td>' . number_format( $v['buyers'] ) . ' (' . $pct( $v['rate'] ) . ')</td><td>' . number_format( $v['users'] ) . ' (' . $pct( $v['use_rate'] ) . ')</td><td>' . number_format( $v['orders'] ) . '</td><td>' . number_format( (int) $v['sales'] ) . '원</td><td>' . number_format( $v['before'] ) . '명</td></tr>';
		}
		$h .= '</tbody></table>';
	} else {
		$h .= '<p style="color:#646970">문자종류별로 나눠 보려면 같은 엑셀 · 같은 적립 사유로 <b>미리 보기</b>를 한 번 누르세요 (넣지 않아도 됩니다). 그때 종류가 기록됩니다.</p>';
	}
	$h .= '<p style="color:#646970;margin-bottom:0">「주문한 사람」은 넣은 뒤 돈이 들어온 주문이 있는 사람입니다. 입금 확인이 이어지므로 숫자는 하루 동안 계속 오릅니다 (10분마다 새로 셈). 문자를 넣은 뒤 늦게 보냈다면 그만큼 낮게 보입니다.</p>';
	return $h . '</div>';
}

/* ── 받은 사람 자세히 (사장님 2026-10-22 「낱병 엑셀을 줬는지 헷갈려서」) ── */

/**
 * 노보 줄의 구성 — 'b' 묶음 · 's' 낱병 · '' 노보 아님. 순수.
 * 병 수를 이름에서 못 세는 노보 줄(「노보 타박멘솔2000 … 결제」 같은 수동 결제)은 묶음으로 본다.
 */
function novo_part( string $name ): string {
	$brand = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\brand' ) ? \Duckhoo\Redesign\Anatomy\brand( $name ) : ( preg_match( '/노보/u', $name ) ? '노보' : '' );
	if ( '노보' !== $brand ) {
		return '';
	}
	$n = function_exists( '\\Duckhoo\\Redesign\\Cost\\bottles' ) ? \Duckhoo\Redesign\Cost\bottles( $name ) : ( preg_match( '/(\d+)\s*(?:\+|병)/u', $name ) ? 10 : 1 );
	return 1 === $n ? 's' : 'b';
}

/**
 * 한 사람의 노보 이력. 순수.
 *
 * @param array $orders 그 사람의 돈 들어온 주문 [ {id,ts,t} ].
 * @param array $items  주문 번호 => 줄들.
 * @param int   $at     넣은 시각 — 이 전의 이력이 「왜 이 명단에 들었나」다.
 * @return array{b:int,s:int,last:int,after:int,sales:float,kind:string}
 */
function novo_history( array $orders, array $items, int $at ): array {
	$h = array( 'b' => 0, 's' => 0, 'last' => 0, 'after' => 0, 'sales' => 0.0, 'kind' => 'none' );
	foreach ( $orders as $o ) {
		if ( (int) $o['ts'] >= $at ) {
			++$h['after'];
			$h['sales'] += (float) $o['t'];
			continue;
		}
		$parts = array();
		foreach ( (array) ( $items[ (int) $o['id'] ] ?? array() ) as $it ) {
			$k = novo_part( (string) $it['name'] );
			if ( '' !== $k ) {
				$parts[ $k ] = true;
			}
		}
		if ( isset( $parts['b'] ) ) {
			++$h['b'];
		}
		if ( isset( $parts['s'] ) ) {
			++$h['s'];
		}
		if ( $parts ) {
			$h['last'] = max( $h['last'], (int) $o['ts'] );
		}
	}
	$h['kind'] = $h['b'] > 0 ? 'bundle' : ( $h['s'] > 0 ? 'single' : 'none' );
	return $h;
}

/** 판정 이름. */
function novo_kind_label( string $k ): string {
	return array( 'bundle' => '노보 묶음 산 적 있음', 'single' => '노보 낱병만', 'none' => '노보 산 적 없음' )[ $k ] ?? $k;
}

/** 파일 · 붙여 넣은 글에서 휴대폰 번호만 (칸 이름 없이도). 순수. @return string[] */
function file_phones( array $table ): array {
	$out = array();
	foreach ( $table as $cells ) {
		foreach ( (array) $cells as $c ) {
			if ( preg_match_all( '/(?:\+?82[\s-]?)?0?1[016789][\s-]?\d{3,4}[\s-]?\d{4}/', (string) $c, $m ) ) {
				foreach ( $m[0] as $raw ) {
					$p = pnorm( $raw );
					if ( '' !== $p ) {
						$out[ $p ] = $p;
					}
				}
			}
		}
	}
	return array_values( $out );
}

/**
 * 받은 사람 + (견줄 파일의) 다른 회원의 이력을 한 번에 읽는다.
 *
 * @param int[] $extra 견줄 파일에서 찾은 회원.
 * @return array{at:int,label:string,recips:array,hist:array,contacts:array}|null
 */
function detail_data( string $key, array $extra = array() ): ?array {
	global $wpdb;
	$all = (array) get_option( OPT, array() );
	if ( ! isset( $all[ $key ] ) ) {
		return null;
	}
	$b      = $all[ $key ];
	$at     = (int) ( $b['created'] ?? time() );
	$recips = array_map( fn( $x ) => array( $x[0], $x[1] ), received( $key, $b ) );
	$want = array_fill_keys( array_merge( array_keys( $recips ), array_map( 'intval', $extra ) ), true );
	$off  = (int) ( function_exists( 'wp_timezone' ) ? ( new \DateTime( 'now', wp_timezone() ) )->getOffset() : 9 * 3600 );
	$paid = function_exists( '\\Duckhoo\\Redesign\\Crm\\paid_statuses' ) ? \Duckhoo\Redesign\Crm\paid_statuses() : array( 'payment-confirmed', 'ready-to-ship', 'shipping', 'delivered', 'completed', 'processing' );
	$by   = array();
	$ids  = array();
	foreach ( ( function_exists( '\\Duckhoo\\Redesign\\Anatomy\\orders' ) ? \Duckhoo\Redesign\Anatomy\orders() : array() ) as $o ) {
		$u = (int) $o['u'];
		if ( $u > 0 && isset( $want[ $u ] ) && in_array( $o['s'], $paid, true ) ) {
			$o['ts']    = (int) $o['ts'] - $off;
			$by[ $u ][] = $o;
			$ids[]      = (int) $o['id'];
		}
	}
	$items = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\items' ) ? \Duckhoo\Redesign\Anatomy\items( $ids ) : array();
	$hist  = array();
	foreach ( array_keys( $want ) as $u ) {
		$hist[ $u ] = novo_history( (array) ( $by[ $u ] ?? array() ), $items, $at );
	}
	$after = array();
	foreach ( $by as $u => $os ) {
		foreach ( $os as $o ) {
			if ( (int) $o['ts'] >= $at ) {
				$after[ $u ][] = (int) $o['id'];
			}
		}
	}
	$contacts = function_exists( '\\Duckhoo\\Redesign\\Crm\\contacts' ) ? \Duckhoo\Redesign\Crm\contacts( array_keys( $want ) ) : array();
	return array( 'at' => $at, 'label' => (string) $b['label'], 'recips' => $recips, 'hist' => $hist, 'contacts' => $contacts, 'after' => $after );
}

/** 판정별 수. 순수. */
function kind_counts( array $uids, array $hist ): array {
	$c = array( 'bundle' => 0, 'single' => 0, 'none' => 0 );
	foreach ( $uids as $u ) {
		++$c[ $hist[ $u ]['kind'] ?? 'none' ];
	}
	return $c;
}

/** 번호 가림. */
function mask( string $p ): string {
	return '' === $p ? '—' : substr( $p, 0, 3 ) . '-****-' . substr( $p, -4 );
}

/** 사람 표 한 장. */
function people_table( array $uids, array $d, bool $with_given ): string {
	$order = array( 'none' => 0, 'single' => 1, 'bundle' => 2 );
	usort( $uids, function ( $a, $b ) use ( $d, $order ) {
		$ka = $order[ $d['hist'][ $a ]['kind'] ?? 'none' ];
		$kb = $order[ $d['hist'][ $b ]['kind'] ?? 'none' ];
		return $ka <=> $kb ?: strcmp( (string) ( $d['contacts'][ $a ]['name'] ?? '' ), (string) ( $d['contacts'][ $b ]['name'] ?? '' ) );
	} );
	$h = '<table class="widefat striped"><thead><tr><th>이름</th><th>번호</th><th>회원</th>' . ( $with_given ? '<th>문자종류</th><th>받은 금액</th>' : '' ) . '<th>판정</th><th>노보 묶음 주문</th><th>노보 낱병 주문</th><th>마지막 노보 구매</th><th>넣은 뒤 주문</th></tr></thead><tbody>';
	$col = array( 'bundle' => '#1a7f37', 'single' => '#b45309', 'none' => '#b42318' );
	foreach ( array_slice( $uids, 0, 500 ) as $u ) {
		$c  = (array) ( $d['contacts'][ $u ] ?? array() );
		$hh = $d['hist'][ $u ] ?? novo_history( array(), array(), 0 );
		$h .= '<tr><td>' . esc_html( (string) ( $c['name'] ?? '' ) ) . '</td><td>' . esc_html( mask( (string) ( $c['phone'] ?? '' ) ) ) . '</td><td><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $u ) ) . '">#' . (int) $u . '</a></td>'
			. ( $with_given ? '<td>' . esc_html( (string) ( $d['recips'][ $u ][1] ?? '' ) ) . '</td><td>' . number_format( (int) ( $d['recips'][ $u ][0] ?? 0 ) ) . '원</td>' : '' )
			. '<td style="color:' . $col[ $hh['kind'] ] . ';font-weight:600">' . esc_html( novo_kind_label( $hh['kind'] ) ) . '</td><td>' . (int) $hh['b'] . '</td><td>' . (int) $hh['s'] . '</td><td>' . ( $hh['last'] ? esc_html( wp_date( 'Y.m.d', $hh['last'] ) ) : '—' ) . '</td>'
			. '<td>' . ( $hh['after'] ? (int) $hh['after'] . '건 · ' . number_format( (int) $hh['sales'] ) . '원' : '—' ) . '</td></tr>';
	}
	return $h . '</tbody></table>' . ( count( $uids ) > 500 ? '<p>500명까지만 보입니다.</p>' : '' );
}

/**
 * 받은 사람 내려받기 줄. 순수.
 *
 * $only: all(전부) · ordered(넣은 뒤 주문한 사람) · not(아직 안 산 사람 — 리마인드 대상).
 * $used: 주문 번호 => 그 주문에 쓴 적립금 (used_points()).
 * 줄: [이름, 번호(숫자만), 받은 금액, 문자종류, 넣은 뒤 주문 수, 넣은 뒤 실입금, 넣은 뒤 쓴 적립금, 넣기 전 노보 이력].
 */
function export_rows( array $d, array $used, string $only ): array {
	$out = array();
	foreach ( (array) $d['recips'] as $u => $r ) {
		$h  = $d['hist'][ $u ] ?? array( 'after' => 0, 'sales' => 0, 'kind' => 'none' );
		$on = (int) $h['after'] > 0;
		if ( ( 'ordered' === $only && ! $on ) || ( 'not' === $only && $on ) ) {
			continue;
		}
		$c   = (array) ( $d['contacts'][ $u ] ?? array() );
		$use = 0;
		foreach ( (array) ( $d['after'][ $u ] ?? array() ) as $oid ) {
			$use += (int) ( $used[ (int) $oid ] ?? 0 );
		}
		$out[] = array(
			(string) ( $c['name'] ?? '' ),
			pnorm( (string) ( $c['phone'] ?? '' ) ),
			(int) ( $r[0] ?? 0 ),
			(string) ( $r[1] ?? '' ),
			(int) $h['after'],
			(int) round( (float) $h['sales'] ),
			$use,
			novo_kind_label( (string) $h['kind'] ),
		);
	}
	usort( $out, fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
	return $out;
}

/** 내려받기 주소. */
function export_url( string $key, string $only, string $fmt ): string {
	return wp_nonce_url( admin_url( 'admin-post.php?action=dhr_pts_csv&key=' . rawurlencode( $key ) . '&only=' . $only . '&fmt=' . $fmt ), 'dhr-pts-csv' );
}

/** 받은 사람 CSV 내려받기 — 관리자만. 문자라인용(sms)은 머리줄 없이 이름 · 번호 두 칸. */
function export_csv(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	check_admin_referer( 'dhr-pts-csv' );
	$key  = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
	$only = isset( $_GET['only'] ) && in_array( $_GET['only'], array( 'all', 'ordered', 'not' ), true ) ? (string) $_GET['only'] : 'all'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$fmt  = isset( $_GET['fmt'] ) && 'sms' === $_GET['fmt'] ? 'sms' : 'full';
	$d    = '' !== $key ? detail_data( $key ) : null;
	if ( ! $d ) {
		wp_die( '그 묶음을 찾지 못했습니다.' );
	}
	$rows = export_rows( $d, used_points( (int) $d['at'] ), $only );
	$tag  = array( 'all' => '전체', 'ordered' => '주문한사람', 'not' => '안산사람' )[ $only ];
	$name = sanitize_file_name( $d['label'] . '_' . $tag . '_' . count( $rows ) . '명' . ( 'sms' === $fmt ? '_문자양식' : '' ) . '.csv' );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode( $name ) );
	$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( 'sms' === $fmt ) {
		foreach ( $rows as $r ) {
			if ( '' !== $r[1] ) {
				fputcsv( $fh, array( $r[0], $r[1] ) );
			}
		}
	} else {
		fputcsv( $fh, array( '이름', '전화번호', '받은 금액', '문자종류', '넣은 뒤 주문', '넣은 뒤 실입금', '넣은 뒤 쓴 적립금', '넣기 전 노보 이력' ) );
		foreach ( $rows as $r ) {
			fputcsv( $fh, $r );
		}
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
add_action( 'admin_post_dhr_pts_csv', __NAMESPACE__ . '\\export_csv' );

/** 내려받기 버튼 줄. */
function export_bar( string $key ): string {
	$b = fn( string $only, string $fmt, string $txt, bool $main = false ) => '<a class="button' . ( $main ? ' button-primary' : '' ) . '" style="margin:0 6px 6px 0" href="' . esc_url( export_url( $key, $only, $fmt ) ) . '">' . esc_html( $txt ) . '</a>';
	return '<p style="margin:10px 0 4px"><b>내려받기</b> — 번호가 가려지지 않은 CSV 입니다.</p><p>'
		. $b( 'not', 'sms', '아직 안 산 사람 · 문자 양식', true )
		. $b( 'ordered', 'full', '주문한 사람' )
		. $b( 'not', 'full', '아직 안 산 사람' )
		. $b( 'all', 'full', '받은 사람 전부' )
		. '</p><p style="color:#646970;margin-top:0">「문자 양식」은 머리줄 없이 이름 · 번호 두 칸이라 문자라인에 바로 올릴 수 있습니다. 「주문」은 넣은 뒤 돈이 들어온 주문이고, 입금 확인이 이어지므로 내려받는 시각에 따라 달라집니다.</p>';
}

/** 받은 사람 자세히 상자 (+ 다른 파일과 견주기). */
function detail_box( string $key, array $cmp_phones = array(), string $cmp_name = '' ): string {
	$map   = $cmp_phones ? phones_map( $cmp_phones ) : array();
	$extra = array();
	foreach ( $map as $l ) {
		$extra = array_merge( $extra, $l );
	}
	$d = detail_data( $key, $extra );
	if ( ! $d ) {
		return '';
	}
	$rec = array_keys( $d['recips'] );
	$kc  = kind_counts( $rec, $d['hist'] );
	$h   = '<div style="margin:16px 0;padding:16px;border:2px solid #1a5cff;border-radius:8px;background:#fff">';
	$h  .= '<h3 style="margin-top:0">받은 사람 자세히 — ' . esc_html( $d['label'] ) . ' <span style="font-weight:400;color:#646970">(' . esc_html( wp_date( 'n월 j일 H:i', $d['at'] ) ) . ' 넣음)</span></h3>';
	$h  .= '<p style="font-size:15px">받은 <b>' . number_format( count( $rec ) ) . '명</b>을 넣기 전 구매 이력으로 나누면: '
		. '<b style="color:#1a7f37">노보 묶음 산 적 있음 ' . number_format( $kc['bundle'] ) . '명</b> · '
		. '<b style="color:#b45309">노보 낱병만 ' . number_format( $kc['single'] ) . '명</b> · '
		. '<b style="color:#b42318">노보 산 적 없음 ' . number_format( $kc['none'] ) . '명</b></p>';
	$h  .= '<p style="color:#646970">묶음 = 이름에 「10+1 · 10병 · 5+5」처럼 병 수가 둘 이상인 노보 상품. 돈이 들어온 주문만 셉니다 (취소 · 입금 전 제외). 산 적 없음 · 낱병만이 위에 옵니다.</p>';
	$h  .= export_bar( $key );

	if ( $cmp_phones ) {
		// 번호 하나가 한 사람이다 — 같은 번호의 계정 중 하나라도 받았으면 「받음」 (이메일을 바꿔 두 번 가입한 사람의 빈 계정을 「못 받음」으로 세지 않는다).
		$in_file = array_values( array_unique( $extra ) );
		$got     = array();
		$not     = array();
		$twins   = 0;
		foreach ( $map as $ids ) {
			$hit = array_values( array_filter( (array) $ids, fn( $u ) => isset( $d['recips'][ $u ] ) ) );
			if ( $hit ) {
				$got[] = $hit[0];
				$twins += count( $ids ) > 1 ? 1 : 0;
			} else {
				$not = array_merge( $not, (array) $ids );
			}
		}
		$got      = array_values( array_unique( $got ) );
		$not      = array_values( array_unique( $not ) );
		$missing  = array_values( array_diff( $rec, $in_file ) );
		$nomember = count( $cmp_phones ) - count( $map );
		$nk       = kind_counts( $not, $d['hist'] );
		$h .= '<div style="margin:12px 0;padding:12px;background:#f6f7f7;border-radius:6px">';
		$h .= '<h4 style="margin:0 0 6px">견준 파일' . ( '' !== $cmp_name ? ' — ' . esc_html( $cmp_name ) : '' ) . '</h4>';
		$h .= '<p style="margin:0">번호 <b>' . number_format( count( $cmp_phones ) ) . '개</b> · 회원으로 찾음 ' . number_format( count( $map ) ) . '개' . ( $nomember ? ' (회원이 아닌 번호 ' . number_format( $nomember ) . '개)' : '' ) . '</p>';
		$h .= '<p style="margin:4px 0 0">이 중 <b>적립금을 받은 사람 ' . number_format( count( $got ) ) . '명</b> · <b>못 받은 사람 ' . number_format( count( $not ) ) . '명</b>'
			. ( $not ? ' (못 받은 사람의 이력: 묶음 ' . number_format( $nk['bundle'] ) . ' · 낱병만 ' . number_format( $nk['single'] ) . ' · 산 적 없음 ' . number_format( $nk['none'] ) . ')' : '' ) . '</p>';
		$h .= ( $twins ? '<p style="margin:4px 0 0;color:#646970">같은 번호로 계정이 둘 이상인 사람 ' . number_format( $twins ) . '명은 받은 계정 하나로 셉니다.</p>' : '' );
		$h .= '<p style="margin:4px 0 0">받았는데 이 파일에는 없는 사람 ' . number_format( count( $missing ) ) . '명</p></div>';
		if ( $not ) {
			$h .= '<h4>이 파일에 있는데 적립금을 못 받은 사람 (' . count( $not ) . ')</h4>' . people_table( $not, $d, false );
		}
	}

	$h .= '<form method="post" enctype="multipart/form-data" style="margin:14px 0;padding:12px;border:1px dashed #c3c4c7;border-radius:6px">';
	$h .= wp_nonce_field( 'dhr-pts', '_wpnonce', true, false );
	$h .= '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><b>다른 파일과 견주기</b> — 문자 보낸 엑셀 · CSV 를 올리면 그 사람들이 이 적립금을 받았는지, 무엇을 산 사람들인지 봅니다. 번호만 읽고 저장하지 않습니다.<br>'
		. '<input type="file" name="f" accept=".xlsx,.csv,.txt" style="margin-top:8px"> <button class="button" name="dhr_pts" value="detail">견주기</button></form>';

	$h .= '<h4>받은 사람 (' . count( $rec ) . ')</h4>' . people_table( $rec, $d, true );
	return $h . '</div>';
}

/** 미리 보기 · 넣기 때 회원마다 문자종류를 묶음에 적는다 (참여를 종류별로 나눠 보려고). */
function remember_kinds( string $label, array $plan ): void {
	$key = bkey( $label );
	$all = (array) get_option( OPT, array() );
	if ( ! isset( $all[ $key ] ) ) {
		return;
	}
	$k = (array) ( $all[ $key ]['kinds'] ?? array() );
	$n = count( $k );
	foreach ( $plan as $r ) {
		if ( in_array( $r['status'], array( 'ok', 'done' ), true ) && (int) $r['uid'] > 0 && '' !== (string) $r['kind'] && ! isset( $k[ (int) $r['uid'] ] ) ) {
			$k[ (int) $r['uid'] ] = (string) $r['kind'];
		}
	}
	if ( count( $k ) !== $n ) {
		$all[ $key ]['kinds'] = $k;
		update_option( OPT, $all, false );
		delete_transient( 'dhr_pts_part_' . $key );
	}
}

/** 화면. */
function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$me    = get_current_user_id();
	$tkey  = 'dhr_pts_prev_' . $me;
	$msg   = '';
	$plan  = null;
	$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : wp_date( 'n월' ) . ' 감사 적립금'; // phpcs:ignore WordPress.Security.NonceVerification
	$clean = '';
	$part  = '';
	$det   = '';
	$rsn   = '';
	$rmsg  = '';
	$det_ph = array();
	$det_nm = '';
	$ready = function_exists( 'Duckhoo\\Redesign\\Points\\grant' ) && \Duckhoo\Redesign\Points\can_return();

	if ( isset( $_POST['dhr_pts'] ) && check_admin_referer( 'dhr-pts' ) ) {
		$act = sanitize_key( wp_unslash( $_POST['dhr_pts'] ) );
		if ( 'preview' === $act ) {
			$table = array();
			if ( ! empty( $_FILES['f']['tmp_name'] ) && is_uploaded_file( $_FILES['f']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$nm  = strtolower( (string) ( $_FILES['f']['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$tmp = (string) $_FILES['f']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$table = str_ends_with( $nm, '.xlsx' ) ? xlsx_table( $tmp ) : paste_table( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( ! $table && str_ends_with( $nm, '.xlsx' ) ) {
					$msg = '엑셀 파일을 읽지 못했습니다. 엑셀에서 표를 전부 복사해 아래 칸에 붙여 넣어 주세요.';
				}
			}
			if ( ! $table && ! empty( $_POST['paste'] ) ) {
				$table = paste_table( (string) wp_unslash( $_POST['paste'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
			if ( '' === $label ) {
				$msg = '적립 사유를 적어 주세요 — 손님의 적립금 내역에 그대로 보입니다.';
			} elseif ( $table ) {
				$read = read_table( $table );
				$plan = build( $read['rows'], $label );
				remember_kinds( $label, $plan );
				set_transient( $tkey, array( 'label' => $label, 'rows' => $read['rows'], 'bad' => $read['bad'], 'file' => isset( $_FILES['f']['name'] ) ? sanitize_file_name( (string) wp_unslash( $_FILES['f']['name'] ) ) : '' ), HOUR_IN_SECONDS ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( $read['bad'] ) {
					$msg = '번호나 금액을 못 읽은 줄 ' . count( $read['bad'] ) . '개는 뺐습니다: ' . implode( ' / ', array_slice( $read['bad'], 0, 3 ) );
				}
			} elseif ( '' === $msg ) {
				$msg = '파일을 고르거나 표를 붙여 넣어 주세요.';
			}
		} elseif ( 'grant' === $act ) {
			$saved = get_transient( $tkey );
			if ( ! is_array( $saved ) ) {
				$msg = '미리 보기가 만료됐습니다 (1시간). 다시 올려 주세요.';
			} elseif ( ! $ready ) {
				$msg = '테마의 적립금 함수(wd_log_keyple_points_change)를 찾지 못해 넣지 않았습니다.';
			} else {
				$label = (string) $saved['label'];
				$plan  = build( $saved['rows'], $label );
				$picks = isset( $_POST['pick'] ) && is_array( $_POST['pick'] ) ? array_map( 'intval', wp_unslash( $_POST['pick'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$run   = substr( md5( wp_json_encode( $saved['rows'] ) ), 0, 10 ); // 같은 파일 = 같은 표시 (이어서 넣어도 하나로)
				$res   = run_grant( $plan, $label, ! empty( $_POST['with_name'] ), 20.0, $picks, $run, (string) ( $saved['file'] ?? '' ) );
				$msg   = number_format( $res['n'] ) . '명에게 ' . number_format( $res['sum'] ) . '원을 넣었습니다.'
					. ( $res['left'] ? ' 시간이 모자라 ' . number_format( $res['left'] ) . '명이 남았습니다 — 「넣기」를 한 번 더 누르면 이어서 넣습니다.' : '' )
					. ( $res['fail'] ? ' 넣지 못한 ' . $res['fail'] . '명이 있습니다.' : '' );
				$plan  = build( $saved['rows'], $label );
				remember_kinds( $label, $plan );
				delete_transient( 'dhr_pts_part_' . bkey( $label ) );
			}
		} elseif ( 'detail' === $act && ! empty( $_POST['key'] ) ) {
			$det = sanitize_key( wp_unslash( $_POST['key'] ) );
			if ( ! empty( $_FILES['f']['tmp_name'] ) && is_uploaded_file( $_FILES['f']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$nm      = (string) ( $_FILES['f']['name'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$tmp     = (string) $_FILES['f']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$table   = str_ends_with( strtolower( $nm ), '.xlsx' ) ? xlsx_table( $tmp ) : paste_table( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$det_ph  = file_phones( $table );
				$det_nm  = sanitize_file_name( $nm );
				if ( ! $det_ph ) {
					$msg = '그 파일에서 휴대폰 번호를 하나도 못 읽었습니다.';
				}
			}
		} elseif ( 'reason_scan' === $act ) {
			$rsn = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		} elseif ( 'reason_take' === $act ) {
			$rsn = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
			$res = reason_take( $rsn, ! empty( $_POST['with_other'] ) );
			$rmsg = number_format( $res['n'] ) . '명에게서 안 쓴 적립금 ' . number_format( $res['sum'] ) . '원을 거뒀습니다.'
				. ( $res['skip'] ? ' 다른 사유로 빠진 기록이 있는 ' . $res['skip'] . '명은 건너뛰었습니다.' : '' )
				. ( $res['left'] ? ' 시간이 모자라 ' . $res['left'] . '명이 남았습니다 — 한 번 더 누르세요.' : '' )
				. ( '' !== $res['msg'] ? ' ' . $res['msg'] : '' );
		} elseif ( 'part' === $act && ! empty( $_POST['key'] ) ) {
			$part = sanitize_key( wp_unslash( $_POST['key'] ) );
		} elseif ( 'cleanup_preview' === $act && ! empty( $_POST['key'] ) ) {
			$clean = sanitize_key( wp_unslash( $_POST['key'] ) );
		} elseif ( 'cleanup' === $act && ! empty( $_POST['key'] ) ) {
			$res = run_cleanup( sanitize_key( wp_unslash( $_POST['key'] ) ) );
			$msg = number_format( $res['users'] ) . '명의 적립금 내역에서 ' . number_format( $res['rows'] ) . '줄을 지웠습니다. 잔액은 그대로입니다.';
		} elseif ( 'revoke' === $act && ! empty( $_POST['key'] ) ) {
			$only = array();
			if ( isset( $_POST['amt'] ) && '' !== $_POST['amt'] ) {
				$only['amt'] = (int) $_POST['amt'];
			} elseif ( ! empty( $_POST['run'] ) ) {
				$only['run'] = sanitize_key( wp_unslash( $_POST['run'] ) );
			}
			$res = run_revoke( sanitize_key( wp_unslash( $_POST['key'] ) ), 20.0, $only );
			$msg = number_format( $res['n'] ) . '명에게서 ' . number_format( $res['sum'] ) . '원을 거뒀습니다.' . ( $res['left'] ? ' ' . $res['left'] . '명 남음 — 한 번 더 누르세요.' : '' );
		}
	} elseif ( isset( $_GET['again'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$saved = get_transient( $tkey );
		if ( is_array( $saved ) ) {
			$label = (string) $saved['label'];
			$plan  = build( $saved['rows'], $label );
		}
	}

	echo '<div class="wrap"><h1>적립금 한 번에 넣기</h1>';
	echo '<p style="max-width:760px">엑셀(이름 · 전화번호 · 문자종류 · 적립금)을 올리면 회원을 전화번호로 찾아 <b>미리 보기</b>를 보여 줍니다. 「넣기」를 눌러야 들어가고, 잔액과 적립금 내역에 함께 남습니다. 같은 묶음은 두 번 들어가지 않습니다.</p>';
	if ( ! $ready ) {
		echo '<div class="notice notice-error"><p>테마의 적립금 함수를 찾지 못했습니다. 이 상태로는 넣을 수 없습니다.</p></div>';
	}
	if ( '' !== $msg ) {
		echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
	}

	echo '<form method="post" enctype="multipart/form-data" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;max-width:760px">';
	wp_nonce_field( 'dhr-pts' );
	echo '<p><label><b>적립 사유</b> — <b style="color:#b42318">손님의 적립금 내역에 이 글자 그대로 보입니다.</b> 「이탈 방지」 같은 내부 그룹 이름은 쓰지 마세요<br><input type="text" name="label" value="' . esc_attr( $label ) . '" class="regular-text"></label></p>';
	echo '<p><label><b>엑셀 파일</b> (.xlsx · .csv)<br><input type="file" name="f" accept=".xlsx,.csv,.txt"></label></p>';
	echo '<p><label>또는 엑셀에서 머리줄부터 복사해 붙여 넣기<br><textarea name="paste" rows="4" style="width:100%"></textarea></label></p>';
	echo '<p><button class="button button-primary" name="dhr_pts" value="preview">미리 보기</button></p></form>';

	if ( is_array( $plan ) ) {
		$cnt = array();
		$amt = array();
		foreach ( $plan as $r ) {
			$cnt[ $r['status'] ] = ( $cnt[ $r['status'] ] ?? 0 ) + 1;
			$amt[ $r['status'] ] = ( $amt[ $r['status'] ] ?? 0 ) + $r['amount'];
		}
		echo '<h2 style="margin-top:24px">미리 보기 — ' . esc_html( $label ) . '</h2><p>';
		foreach ( array( 'ok', 'name', 'none', 'many', 'dup', 'excluded', 'done' ) as $s ) {
			if ( ! empty( $cnt[ $s ] ) ) {
				echo '<span style="display:inline-block;margin:0 8px 6px 0;padding:4px 10px;border-radius:99px;background:' . ( 'ok' === $s ? '#e7f0ff' : '#f0f0f1' ) . '"><b>' . esc_html( status_label( $s ) ) . '</b> ' . number_format( $cnt[ $s ] ) . '명 · ' . number_format( $amt[ $s ] ) . '원</span>';
			}
		}
		echo '</p>';
		$by_kind = array();
		foreach ( $plan as $r ) {
			if ( 'ok' === $r['status'] ) {
				$k = '' !== $r['kind'] ? $r['kind'] : '(종류 없음)';
				$by_kind[ $k ][ $r['amount'] ] = ( $by_kind[ $k ][ $r['amount'] ] ?? 0 ) + 1;
			}
		}
		if ( $by_kind ) {
			echo '<table class="widefat striped" style="max-width:520px"><thead><tr><th>문자종류</th><th>금액</th><th>인원</th></tr></thead><tbody>';
			foreach ( $by_kind as $k => $as ) {
				foreach ( $as as $a => $n ) {
					echo '<tr><td>' . esc_html( $k ) . '</td><td>' . number_format( $a ) . '원</td><td>' . number_format( $n ) . '명</td></tr>';
				}
			}
			echo '</tbody></table>';
		}
		if ( ! empty( $cnt['ok'] ) || ! empty( $cnt['name'] ) || ! empty( $cnt['many'] ) ) {
			echo '<form method="post" style="margin:16px 0;padding:16px;border:2px solid #1a5cff;border-radius:8px;max-width:900px;background:#fff">';
			wp_nonce_field( 'dhr-pts' );
			if ( ! empty( $cnt['many'] ) ) {
				echo '<h3 style="margin-top:0">번호가 여럿 — 넣을 계정을 고르세요 (' . number_format( $cnt['many'] ) . '명)</h3>';
				echo '<p style="margin-top:0;color:#50575e">같은 번호로 이메일이 다른 계정이 둘 있는 손님입니다. 대부분 같은 사람이 두 번 가입한 것이라 <b>주문이 있는 계정 · 엑셀 이름과 같은 계정</b>을 먼저 골라 두었습니다. 그 손님이 실제로 로그인하는 계정에 넣어야 잔액이 보입니다.</p>';
				echo '<table class="widefat striped"><thead><tr><th>엑셀 줄</th><th>이름</th><th>금액</th><th>계정 (가입일 · 이메일 · 본인확인 · 주문 · 적립금)</th></tr></thead><tbody>';
				foreach ( $plan as $r ) {
					if ( 'many' !== $r['status'] ) {
						continue;
					}
					echo '<tr><td>' . (int) $r['line'] . '</td><td>' . esc_html( $r['name'] ) . '</td><td>' . number_format( $r['amount'] ) . '</td><td>';
					foreach ( (array) $r['cands'] as $i => $id ) {
						$c  = (array) ( $r['cinfo'][ $id ] ?? array() );
						$em = (string) ( $c['email'] ?? '' );
						$at = strpos( $em, '@' );
						$em = false === $at ? $em : substr( $em, 0, min( 3, $at ) ) . '***' . substr( $em, $at );
						echo '<label style="display:block;margin:2px 0"><input type="radio" name="pick[' . (int) $r['line'] . ']" value="' . (int) $id . '"' . ( 0 === $i ? ' checked' : '' ) . '> '
							. '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $id ) ) . '" target="_blank">#' . (int) $id . '</a> · '
							. esc_html( (string) ( $c['reg'] ?? '' ) ) . ' 가입 · ' . esc_html( $em ) . ' · '
							. ( ! empty( $c['verified'] ) ? '본인확인 ✓' : '본인확인 없음' ) . ' · 주문 ' . (int) ( $c['orders'] ?? 0 ) . '건 · 적립금 ' . number_format( (int) ( $c['points'] ?? 0 ) ) . '원'
							. ( 0 === $i ? ' <b style="color:#1a5cff">추천</b>' : '' ) . '</label>';
					}
					echo '<label style="display:block;margin:2px 0;color:#646970"><input type="radio" name="pick[' . (int) $r['line'] . ']" value="0"> 넣지 않음</label></td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( ! empty( $cnt['name'] ) ) {
				echo '<p><label><input type="checkbox" name="with_name" value="1"> 이름이 다른 ' . number_format( $cnt['name'] ) . '명도 넣기 (번호는 맞음 — 아래 표에서 확인)</label></p>';
			}
			echo '<p style="margin:0 0 10px">「넣을 사람」 <b>' . number_format( $cnt['ok'] ?? 0 ) . '명 · ' . number_format( $amt['ok'] ?? 0 ) . '원</b>' . ( ! empty( $cnt['many'] ) ? ' + 위에서 고른 계정' : '' ) . '을 지금 넣습니다. 손님 잔액에 바로 보입니다.</p>';
			echo '<button class="button button-primary button-hero" name="dhr_pts" value="grant" onclick="return confirm(\'적립금을 넣을까요? 「거두기」로 되돌릴 수 있습니다.\')">넣기</button></form>';
		}
		// 이미 받은 사람(done)은 빼고 — 다시 미리 보기 하면 이 표가 곧 「아직 못 받은 사람」이다.
		$rest = array_filter( $plan, fn( $r ) => ! in_array( $r['status'], array( 'ok', 'many', 'done' ), true ) );
		if ( $rest ) {
			echo '<h3>' . ( ! empty( $cnt['done'] ) ? '아직 못 받은 사람' : '넣지 않는 줄' ) . ' (' . count( $rest ) . ')</h3><table class="widefat striped"><thead><tr><th>엑셀 줄</th><th>이름</th><th>번호</th><th>종류</th><th>금액</th><th>까닭</th><th>메모</th></tr></thead><tbody>';
			foreach ( array_slice( $rest, 0, 400 ) as $r ) {
				echo '<tr><td>' . (int) $r['line'] . '</td><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( substr( $r['phone'], 0, 3 ) . '-****-' . substr( $r['phone'], -4 ) ) . '</td><td>' . esc_html( $r['kind'] ) . '</td><td>' . number_format( $r['amount'] ) . '</td><td>' . esc_html( status_label( $r['status'] ) ) . '</td><td>' . esc_html( $r['note'] ) . ( $r['uid'] ? ' <a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $r['uid'] ) ) . '">#' . (int) $r['uid'] . '</a>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	if ( '' !== $clean ) {
		echo cleanup_box( $clean ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( '' !== $part ) {
		echo participation_box( $part ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( '' !== $det ) {
		echo detail_box( $det, $det_ph, $det_nm ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo reason_box( $rsn, $rmsg ); // phpcs:ignore WordPress.Security.EscapeOutput
	$all = (array) get_option( OPT, array() );
	if ( $all ) {
		echo '<h2 style="margin-top:28px">넣은 묶음</h2><table class="widefat striped" style="max-width:860px"><thead><tr><th>묶음</th><th>인원</th><th>금액</th><th>마지막</th><th></th></tr></thead><tbody>';
		foreach ( array_reverse( $all, true ) as $k => $b ) {
			echo '<tr><td>' . esc_html( (string) $b['label'] ) . '</td><td>' . number_format( (int) $b['n'] ) . '명</td><td>' . number_format( (int) $b['sum'] ) . '원</td><td>' . esc_html( wp_date( 'n/j H:i', (int) ( $b['revoked'] ?? $b['updated'] ?? $b['created'] ) ) ) . ( ! empty( $b['revoked'] ) ? ' 거둠' : '' ) . '</td><td>';
			if ( (int) $b['n'] > 0 ) {
				echo '<form method="post" style="display:inline">';
				wp_nonce_field( 'dhr-pts' );
				echo '<input type="hidden" name="key" value="' . esc_attr( (string) $k ) . '"><button class="button" name="dhr_pts" value="revoke" onclick="return confirm(\'이 묶음으로 넣은 적립금을 거둘까요? 이미 쓴 만큼은 빼지 못합니다.\')">거두기</button></form>';
			}
			if ( (int) $b['n'] > 0 ) {
				echo ' <form method="post" style="display:inline">';
				wp_nonce_field( 'dhr-pts' );
				echo '<input type="hidden" name="key" value="' . esc_attr( (string) $k ) . '"><button class="button button-primary" name="dhr_pts" value="part">참여 보기</button></form>';
				echo ' <form method="post" style="display:inline">';
				wp_nonce_field( 'dhr-pts' );
				echo '<input type="hidden" name="key" value="' . esc_attr( (string) $k ) . '"><button class="button" name="dhr_pts" value="detail">받은 사람 자세히</button></form>';
			}
			// 같은 사유로 여러 번 넣었으면 금액별 · 파일별로 따로 거둔다 (10/16 — 23원으로 잘못 들어간 102명만 거두려고).
			$groups = (int) $b['n'] > 0 ? amount_groups( (string) $k ) : array();
			$runs   = (array) ( $b['runs'] ?? array() );
			if ( count( $groups ) > 1 || count( $runs ) > 1 ) {
				echo '<div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">';
				foreach ( $runs as $rid => $rr ) {
					echo '<form method="post" style="display:inline">';
					wp_nonce_field( 'dhr-pts' );
					$nm = (string) ( $rr['file'] ?? '' );
					echo '<input type="hidden" name="key" value="' . esc_attr( (string) $k ) . '"><input type="hidden" name="run" value="' . esc_attr( (string) $rid ) . '"><button class="button button-small" name="dhr_pts" value="revoke" onclick="return confirm(\'이 파일로 넣은 적립금만 거둘까요?\')">'
						. esc_html( wp_date( 'n/j H:i', (int) $rr['at'] ) . ( '' !== $nm ? ' ' . $nm : '' ) . ' · ' . number_format( (int) $rr['n'] ) . '명 · ' . number_format( (int) $rr['sum'] ) . '원 거두기' ) . '</button></form>';
				}
				if ( count( $groups ) > 1 ) {
					foreach ( $groups as $amt => $cnt ) {
						echo '<form method="post" style="display:inline">';
						wp_nonce_field( 'dhr-pts' );
						echo '<input type="hidden" name="key" value="' . esc_attr( (string) $k ) . '"><input type="hidden" name="amt" value="' . (int) $amt . '"><button class="button button-small" name="dhr_pts" value="revoke" onclick="return confirm(\'' . esc_js( number_format( $amt ) . '원을 받은 ' . number_format( $cnt ) . '명에게서만 거둘까요?' ) . '\')">'
							. esc_html( number_format( $amt ) . '원 받은 ' . number_format( $cnt ) . '명만 거두기' ) . '</button></form>';
					}
				}
				echo '</div>';
			}
			if ( ( ! empty( $b['revoked'] ) || ! empty( $b['part_revoked'] ) ) && ( empty( $b['cleaned'] ) || (int) $b['cleaned'] < (int) max( (int) ( $b['revoked'] ?? 0 ), (int) ( $b['part_revoked'] ?? 0 ) ) ) ) {
				echo ' <form method="post" style="display:inline">';
				wp_nonce_field( 'dhr-pts' );
				echo '<input type="hidden" name="key" value="' . esc_attr( (string) $k ) . '"><button class="button" name="dhr_pts" value="cleanup_preview">손님 내역에서 지우기</button></form>';
			} elseif ( ! empty( $b['cleaned'] ) ) {
				echo ' 내역 지움';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

require_once __DIR__ . '/points-reason.php';
