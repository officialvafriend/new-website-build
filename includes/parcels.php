<?php
/**
 * 우체국 소포 발송 목록 → 배송비 지출.
 *
 * 우체국 「소포 발송 내역」 CSV 에는 요금 칸이 없다 (보험취급수수료만 있고 비어 있다).
 * 그래서 이 파일이 주는 것은 「몇 상자를 보냈나」뿐이고, 배송비 지출 = 상자 수 × 계약 단가다.
 * 단가는 우체국 월 청구서의 값을 사람이 한 번 넣는다 (옵션). 주문 · 회원에는 아무것도 쓰지 않는다.
 *
 * 실측 (2026-09, 831상자): 고객주문처 = 액상덕후W(워드프레스) 761 · 액상덕후I(아임웹) 38 · 빈칸 27 · 액상덕후 5.
 * 고객주문번호는 워드프레스가 15자리(2026090100038xx), 아임웹은 다른 꼴. 반품신청여부 「예」 8.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Parcels;

defined( 'ABSPATH' ) || exit;

const OPT_MONTHS = 'duckhoo_parcel_months';
const OPT_UNIT   = 'duckhoo_parcel_unit';

/**
 * 계약 단가 (상자 하나에 우체국에 내는 돈). 0 이면 모름.
 */
function unit(): int {
	return (int) apply_filters( 'duckhoo_parcel_unit', (int) get_option( OPT_UNIT, 0 ) );
}

/**
 * 붙여 넣은 글 · 올린 파일 → 상자 한 줄씩. 머리줄에서 칸을 이름으로 찾는다.
 *
 * @return array<int, array{d:string,no:string,src:string,ret:bool,st:string}>
 */
function parse( string $text ): array {
	$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
	if ( '' === trim( $text ) ) {
		return array();
	}
	if ( ! preg_match( '//u', $text ) && function_exists( 'mb_convert_encoding' ) ) {
		$text = mb_convert_encoding( $text, 'UTF-8', 'CP949' );
	}
	$lines = preg_split( '/\r\n|\r|\n/', $text );
	$sep   = substr_count( $lines[0], "\t" ) > substr_count( $lines[0], ',' ) ? "\t" : ',';
	$head  = array_map( 'trim', str_getcsv( $lines[0], $sep ) );
	// 이름 우선순위대로 — 「고객주문번호」를 먼저 찾아야 첫 칸 「소포주문번호」에 안 걸린다
	$find  = function ( array $names ) use ( $head ): int {
		foreach ( $names as $n ) {
			foreach ( $head as $i => $h ) {
				$h = preg_replace( '/\s+/u', '', $h );
				if ( '' !== $h && false !== mb_strpos( $h, $n ) ) {
					return $i;
				}
			}
		}
		return -1;
	};
	$ci = array(
		'd'   => $find( array( '등록일자', '접수일', '발송일' ) ),
		'no'  => $find( array( '고객주문번호', '주문번호' ) ),
		'src' => $find( array( '고객주문처', '주문처' ) ),
		'ret' => $find( array( '반품신청' ) ),
		'st'  => $find( array( '배송진행', '진행상태', '상태' ) ),
		'reg' => $find( array( '등기번호', '송장' ) ),
	);
	if ( $ci['d'] < 0 || $ci['reg'] < 0 ) {
		return array();
	}
	$out  = array();
	$seen = array();
	foreach ( array_slice( $lines, 1 ) as $l ) {
		if ( '' === trim( $l ) ) {
			continue;
		}
		$c = str_getcsv( $l, $sep );
		$g = fn( $k ) => $ci[ $k ] >= 0 && isset( $c[ $ci[ $k ] ] ) ? trim( (string) $c[ $ci[ $k ] ] ) : '';
		$d = $g( 'd' );
		if ( ! preg_match( '/^(\d{4})[-.\/]?(\d{2})[-.\/]?(\d{2})/', $d, $m ) ) {
			continue;
		}
		$reg = $g( 'reg' );
		if ( '' !== $reg && isset( $seen[ $reg ] ) ) {
			continue; // 같은 등기번호 두 줄은 한 상자
		}
		$seen[ $reg ] = true;
		$no = $g( 'no' );
		$out[] = array(
			'd'   => "{$m[1]}-{$m[2]}-{$m[3]}",
			'no'  => '-' === $no ? '' : $no,
			'src' => $g( 'src' ),
			'ret' => in_array( $g( 'ret' ), array( '예', 'Y', 'y' ), true ),
			'st'  => $g( 'st' ),
		);
	}
	return $out;
}

/**
 * 어느 사이트 주문인가 — 주문처 글자, 없으면 주문번호 꼴로.
 */
function origin( array $r ): string {
	$s = strtoupper( (string) $r['src'] );
	if ( '' !== $s && str_ends_with( $s, 'W' ) ) {
		return 'wp';
	}
	if ( '' !== $s && str_ends_with( $s, 'I' ) ) {
		return 'imweb';
	}
	if ( preg_match( '/^20\d{13}$/', (string) $r['no'] ) ) {
		return 'wp';
	}
	return '' === (string) $r['no'] ? 'none' : 'other';
}

/**
 * 한 달 요약. `$ym` 이 있으면 등록일자가 그 달인 것만.
 *
 * @return array{n:int,wp:int,imweb:int,other:int,none:int,ret:int,orders:int,multi:int,days:array<string,int>,first:string,last:string}
 */
function summarize( array $rows, string $ym = '' ): array {
	$t = array( 'n' => 0, 'wp' => 0, 'imweb' => 0, 'other' => 0, 'none' => 0, 'ret' => 0, 'orders' => 0, 'multi' => 0, 'days' => array(), 'first' => '', 'last' => '' );
	$oc = array();
	foreach ( $rows as $r ) {
		if ( '' !== $ym && substr( (string) $r['d'], 0, 7 ) !== $ym ) {
			continue;
		}
		$t['n']++;
		$t[ origin( $r ) ]++;
		if ( $r['ret'] ) {
			$t['ret']++;
		}
		$t['days'][ $r['d'] ] = ( $t['days'][ $r['d'] ] ?? 0 ) + 1;
		if ( '' !== (string) $r['no'] ) {
			$oc[ $r['no'] ] = ( $oc[ $r['no'] ] ?? 0 ) + 1;
		}
	}
	ksort( $t['days'] );
	$t['orders'] = count( $oc );
	$t['multi']  = count( array_filter( $oc, fn( $v ) => $v > 1 ) );
	$t['first']  = $t['days'] ? (string) array_key_first( $t['days'] ) : '';
	$t['last']   = $t['days'] ? (string) array_key_last( $t['days'] ) : '';
	return $t;
}

/**
 * 배송비 지출 = 상자 수 × 단가. 단가를 모르면 0.
 */
function cost( ?array $m, int $unit ): int {
	return $m && $unit > 0 ? (int) $m['n'] * $unit : 0;
}

function months(): array {
	return (array) get_option( OPT_MONTHS, array() );
}

function put( string $ym, array $sum, string $src = 'paste' ): void {
	$all        = months();
	$all[ $ym ] = array_merge( $sum, array( 'src' => $src, 'at' => (string) current_time( 'Y-m-d H:i' ) ) );
	krsort( $all );
	update_option( OPT_MONTHS, array_slice( $all, 0, 36, true ), false );
}

function month( string $ym ): ?array {
	$all = months();
	return isset( $all[ $ym ] ) && is_array( $all[ $ym ] ) ? $all[ $ym ] : null;
}

/**
 * 한 줄 요약.
 */
function line( ?array $m, int $unit = 0 ): string {
	if ( ! $m ) {
		return '배송비 지출: 아직 없음 (월말 결산 화면에 우체국 소포 발송 내역을 올리고 계약 단가를 넣으면 장부에 들어갑니다)';
	}
	$w = fn( $n ) => number_format( (float) round( (float) $n ) );
	$s = '우체국 소포 ' . (int) $m['n'] . '상자 (워드프레스 ' . (int) $m['wp'] . ' · 아임웹 ' . (int) $m['imweb'] . ( $m['none'] > 0 ? ' · 주문번호 없음 ' . (int) $m['none'] : '' ) . ( $m['ret'] > 0 ? ' · 반품 ' . (int) $m['ret'] : '' ) . ')';
	if ( $unit > 0 ) {
		$s .= ' × 단가 ' . $w( $unit ) . '원 = 배송비 지출 ' . $w( cost( $m, $unit ) ) . '원';
	} else {
		$s .= ' · 계약 단가를 아직 안 넣어 금액은 없음';
	}
	return $s;
}

/* ── 관리자 화면 조각 (월말 결산 화면이 부른다) ─────────────────────────── */

function handle_post(): string {
	if ( ! isset( $_POST['dhr_parcel_nonce'] ) || ! wp_verify_nonce( (string) $_POST['dhr_parcel_nonce'], 'dhr_parcel' ) ) { // phpcs:ignore
		return '';
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	$ym  = sanitize_text_field( wp_unslash( (string) ( $_POST['dhr_parcel_ym'] ?? '' ) ) ); // phpcs:ignore
	$msg = '';
	if ( isset( $_POST['dhr_parcel_unit'] ) ) {
		$u = (int) preg_replace( '/\D/', '', (string) wp_unslash( (string) $_POST['dhr_parcel_unit'] ) ); // phpcs:ignore
		if ( $u !== unit() ) {
			update_option( OPT_UNIT, $u, false );
			$msg = $u > 0 ? '계약 단가 ' . number_format( $u ) . '원을 저장했습니다. ' : '계약 단가를 비웠습니다. ';
		}
	}
	$txt = '';
	if ( ! empty( $_FILES['dhr_parcel_file']['tmp_name'] ) && is_uploaded_file( (string) $_FILES['dhr_parcel_file']['tmp_name'] ) ) { // phpcs:ignore
		$txt = (string) file_get_contents( (string) $_FILES['dhr_parcel_file']['tmp_name'] ); // phpcs:ignore
	} elseif ( ! empty( $_POST['dhr_parcel_text'] ) ) {
		$txt = (string) wp_unslash( (string) $_POST['dhr_parcel_text'] ); // phpcs:ignore
	}
	if ( '' !== trim( $txt ) ) {
		$rows = parse( $txt );
		if ( ! $rows ) {
			return $msg . '올린 파일에서 등록일자 · 등기번호 칸을 못 찾았습니다. 우체국 「소포 발송 내역」 CSV 를 머리줄까지 그대로 올려 주세요.';
		}
		$sum = summarize( $rows, $ym );
		if ( 0 === $sum['n'] ) {
			return $msg . '읽은 ' . count( $rows ) . '상자 중 ' . $ym . ' 등록분이 없습니다. 달을 확인해 주세요.';
		}
		put( $ym, $sum, 'paste' );
		return $msg . $ym . ' 에 저장했습니다. ' . line( month( $ym ), unit() );
	}
	return $msg;
}

function box( string $ym, string $msg = '' ): void {
	$m = month( $ym );
	$u = unit();
	echo '<section class="dhr-sl-sec"><h2>배송비 지출 (우체국 소포)</h2>';
	if ( '' !== $msg ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<p class="dhr-sl-note">' . esc_html( line( $m, $u ) ) . '</p>';
	echo '<form method="post" enctype="multipart/form-data" style="margin:0 0 12px">';
	wp_nonce_field( 'dhr_parcel', 'dhr_parcel_nonce' );
	echo '<input type="hidden" name="dhr_parcel_ym" value="' . esc_attr( $ym ) . '">';
	echo '<p><b>① 계약 단가</b> — 상자 하나에 우체국에 내는 돈 (월 청구서의 단가). <input type="text" name="dhr_parcel_unit" value="' . esc_attr( $u > 0 ? (string) $u : '' ) . '" placeholder="예) 2700" size="8" inputmode="numeric"> 원</p>';
	echo '<p><b>② 소포 발송 내역</b> — 우체국 계약소포 → 발송 내역 조회에서 ' . esc_html( $ym ) . ' 을 골라 엑셀(CSV)로 내려받아 그대로 올립니다. 등록일자 · 등기번호 · 고객주문번호 · 고객주문처 · 반품신청여부 칸을 이름으로 찾습니다.</p>';
	echo '<p><input type="file" name="dhr_parcel_file" accept=".csv,.txt,.tsv"> &nbsp; 또는 붙여 넣기 ↓</p>';
	echo '<p><textarea name="dhr_parcel_text" rows="3" style="width:100%;max-width:720px;font-size:12px" placeholder="소포주문번호,등록일자,배송진행 상태내역,…,등기번호,…"></textarea></p>';
	echo '<p><button class="button button-primary" name="dhr_parcel_save" value="1">저장</button></p>';
	echo '</form>';
	if ( $m && $m['days'] ) {
		echo '<details class="dhr-sl-tab"><summary>이 달 날짜별 상자 수</summary><p class="dhr-sl-note">';
		echo esc_html( implode( ' · ', array_map( fn( $d, $n ) => substr( $d, 5 ) . ' ' . $n, array_keys( $m['days'] ), $m['days'] ) ) );
		echo '</p></details>';
	}
	$all = months();
	if ( $all ) {
		echo '<details class="dhr-sl-tab"><summary>저장된 달</summary><div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>달</th><th>상자</th><th>워드프레스</th><th>아임웹</th><th>반품</th><th>지출 (지금 단가)</th><th>언제</th></tr></thead><tbody>';
		foreach ( array_slice( $all, 0, 12, true ) as $k => $v ) {
			printf( '<tr><td>%s</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%s</td><td>%s</td></tr>', esc_html( (string) $k ), (int) $v['n'], (int) $v['wp'], (int) $v['imweb'], (int) $v['ret'], esc_html( $u > 0 ? number_format( cost( $v, $u ) ) . '원' : '—' ), esc_html( (string) ( $v['at'] ?? '' ) ) );
		}
		echo '</tbody></table></div></details>';
	}
	echo '</section>';
}
