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
const MAX    = 3000;
const AMT_HI = 100000;

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
			$amount = 0;
			if ( preg_match_all( '/\d[\d,]*/', $line, $mm ) ) {
				$amount = (int) str_replace( ',', '', (string) end( $mm[0] ) );
			}
			$name = '';
			$kind = '';
			if ( preg_match( '/[가-힣]{2,5}/u', $line, $nm ) ) {
				$name = $nm[0];
			}
		}
		if ( '' === $phone || $amount <= 0 || $amount > AMT_HI ) {
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
function run_grant( array $plan, string $label, bool $with_name, float $budget = 20.0, array $picks = array() ): array {
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
	$all[ $key ] = $b;
	update_option( OPT, $all, false );
	return array( 'n' => $n, 'sum' => $sum, 'left' => $left, 'fail' => $fail );
}

/** 거두기 — 그 묶음으로 넣은 만큼 뺀다. */
function run_revoke( string $key, float $budget = 20.0 ): array {
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
		$bal = (int) get_user_meta( $uid, '_keyple_points', true );
		$take = min( $amt, max( 0, $bal ) );
		if ( $take > 0 ) {
			\Duckhoo\Redesign\Points\grant( $uid, -$take, $label . ' 사용기간 종료' );
			$sum += $take;
		}
		delete_user_meta( $uid, META . $key );
		++$n;
	}
	$all[ $key ]['n']       = max( 0, (int) $all[ $key ]['n'] - $n );
	$all[ $key ]['sum']     = max( 0, (int) $all[ $key ]['sum'] - $sum );
	$all[ $key ]['revoked'] = time();
	update_option( OPT, $all, false );
	return array( 'n' => $n, 'sum' => $sum, 'left' => $left );
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
	$del  = array();
	$left = array();
	$n    = 0;
	foreach ( $by as $uid => $list ) {
		$sum = array_sum( array_column( $list, 1 ) );
		$pos = count( array_filter( $list, fn( $x ) => $x[1] > 0 ) );
		$neg = count( array_filter( $list, fn( $x ) => $x[1] < 0 ) );
		if ( 0 === $sum && $pos > 0 && $neg > 0 ) {
			$del = array_merge( $del, array_column( $list, 0 ) );
			++$n;
		} else {
			$left[ $uid ] = $sum;
		}
	}
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
				set_transient( $tkey, array( 'label' => $label, 'rows' => $read['rows'], 'bad' => $read['bad'] ), HOUR_IN_SECONDS );
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
				$res   = run_grant( $plan, $label, ! empty( $_POST['with_name'] ), 20.0, $picks );
				$msg   = number_format( $res['n'] ) . '명에게 ' . number_format( $res['sum'] ) . '원을 넣었습니다.'
					. ( $res['left'] ? ' 시간이 모자라 ' . number_format( $res['left'] ) . '명이 남았습니다 — 「넣기」를 한 번 더 누르면 이어서 넣습니다.' : '' )
					. ( $res['fail'] ? ' 넣지 못한 ' . $res['fail'] . '명이 있습니다.' : '' );
				$plan  = build( $saved['rows'], $label );
			}
		} elseif ( 'cleanup_preview' === $act && ! empty( $_POST['key'] ) ) {
			$clean = sanitize_key( wp_unslash( $_POST['key'] ) );
		} elseif ( 'cleanup' === $act && ! empty( $_POST['key'] ) ) {
			$res = run_cleanup( sanitize_key( wp_unslash( $_POST['key'] ) ) );
			$msg = number_format( $res['users'] ) . '명의 적립금 내역에서 ' . number_format( $res['rows'] ) . '줄을 지웠습니다. 잔액은 그대로입니다.';
		} elseif ( 'revoke' === $act && ! empty( $_POST['key'] ) ) {
			$res = run_revoke( sanitize_key( wp_unslash( $_POST['key'] ) ) );
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
		$rest = array_filter( $plan, fn( $r ) => ! in_array( $r['status'], array( 'ok', 'many' ), true ) );
		if ( $rest ) {
			echo '<h3>넣지 않는 줄 (' . count( $rest ) . ')</h3><table class="widefat striped"><thead><tr><th>엑셀 줄</th><th>이름</th><th>번호</th><th>종류</th><th>금액</th><th>까닭</th><th>메모</th></tr></thead><tbody>';
			foreach ( array_slice( $rest, 0, 400 ) as $r ) {
				echo '<tr><td>' . (int) $r['line'] . '</td><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( substr( $r['phone'], 0, 3 ) . '-****-' . substr( $r['phone'], -4 ) ) . '</td><td>' . esc_html( $r['kind'] ) . '</td><td>' . number_format( $r['amount'] ) . '</td><td>' . esc_html( status_label( $r['status'] ) ) . '</td><td>' . esc_html( $r['note'] ) . ( $r['uid'] ? ' <a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $r['uid'] ) ) . '">#' . (int) $r['uid'] . '</a>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	if ( '' !== $clean ) {
		echo cleanup_box( $clean ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
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
			if ( ! empty( $b['revoked'] ) && empty( $b['cleaned'] ) ) {
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
