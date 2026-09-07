<?php
/**
 * 성과 분석 화면 (관리자 전용, 읽기만 함).
 *
 * 리디자인이 매출과 운영에 무엇을 했는지 쓰려면 숫자가 있어야 하는데, 그 숫자는
 * 사이트 데이터베이스에만 있다. Jetpack 통계는 꺼져 있고 GA 는 밖에서 볼 수 없다.
 * 그래서 주문 · 회원 · 적립금을 **읽어서** 주차별로 정리해 보여 준다.
 *
 * 아무것도 바꾸지 않는다. 다 쓰고 나면 이 파일과 require 한 줄을 지운다.
 *
 * 도구 → 성과 분석.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Impact;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SLUG = 'duckhoo-impact';

/** 리디자인이 프로덕션에서 켜진 날. 필터로 바꿀 수 있다. */
function live_date(): string {
	return (string) apply_filters( 'duckhoo_impact_live_date', '2026-09-04' );
}

/**
 * 볼 수 있는 관리자인가.
 *
 * @return bool
 */
function allowed(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * 도구 메뉴에 붙입니다.
 *
 * @return void
 */
function menu(): void {
	if ( ! allowed() ) {
		return;
	}
	add_management_page(
		__( '성과 분석', 'duckhoo-redesign' ),
		__( '성과 분석', 'duckhoo-redesign' ),
		current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 매출로 세지 않는 상태 — 취소 · 환불 · 실패 · 임시글.
 *
 * @return string[]
 */
function void_statuses(): array {
	return (array) apply_filters( 'duckhoo_impact_void_statuses',
		array( 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash' ) );
}

/**
 * 기간 안의 주문을 한 번만 읽어 옵니다.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d (포함).
 * @return array<int,array<string,mixed>>
 */
function orders( string $from, string $to ): array {
	static $cache = array();
	$key = $from . '~' . $to;
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$out = array();
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return $out;
	}

	$novo = novo_product_ids();
	$page = 1;
	do {
		$batch = wc_get_orders( array(
			'limit'        => 200,
			'page'         => $page,
			'status'       => 'any',
			'date_created' => $from . '...' . $to,
			'orderby'      => 'date',
			'order'        => 'ASC',
		) );
		foreach ( (array) $batch as $o ) {
			if ( ! is_object( $o ) || ! method_exists( $o, 'get_id' ) ) {
				continue;
			}
			$status  = (string) $o->get_status();
			$has_novo = false;
			$novo_sum = 0.0;
			foreach ( (array) $o->get_items() as $item ) {
				$pid = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
				if ( $pid && isset( $novo[ $pid ] ) ) {
					$has_novo  = true;
					$novo_sum += (float) $item->get_total();
				}
			}
			$out[] = array(
				'date'   => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d' ) : '',
				'status' => $status,
				'total'  => (float) $o->get_total(),
				'void'   => in_array( $status, void_statuses(), true ),
				'points' => (float) \Duckhoo\Redesign\Points\used( $o ),
				'novo'   => $has_novo,
				'novo_v' => $novo_sum,
				'guest'  => ! (int) $o->get_customer_id(),
			);
		}
		$page++;
	} while ( count( (array) $batch ) === 200 && $page < 30 );

	$cache[ $key ] = $out;

	return $out;
}

/**
 * 노보 상품 번호 목록 (분류 + 이름 앞 [노보]).
 *
 * @return array<int,bool>
 */
function novo_product_ids(): array {
	$ids = array();

	if ( ! function_exists( 'wc_get_products' ) ) {
		return $ids;
	}

	foreach ( array( 'novo-liquid' ) as $slug ) {
		$found = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'category' => array( $slug ), 'return' => 'ids' ) );
		foreach ( (array) $found as $id ) {
			$ids[ (int) $id ] = true;
		}
	}

	foreach ( (array) wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) ) as $p ) {
		if ( is_object( $p ) && false !== mb_strpos( (string) $p->get_name(), '노보' ) ) {
			$ids[ (int) $p->get_id() ] = true;
		}
	}

	return $ids;
}

/**
 * 기간의 합계.
 *
 * @param array $rows 주문 줄.
 * @return array<string,float|int>
 */
function totals( array $rows ): array {
	$t = array( 'n' => 0, 'sales' => 0.0, 'paid' => 0, 'void' => 0, 'cancelled' => 0,
		'points_n' => 0, 'points_sum' => 0.0, 'novo_n' => 0, 'novo_sales' => 0.0, 'guest' => 0 );

	foreach ( $rows as $r ) {
		$t['n']++;
		if ( $r['void'] ) {
			$t['void']++;
			if ( 'cancelled' === $r['status'] ) {
				$t['cancelled']++;
			}
		} else {
			$t['paid']++;
			$t['sales'] += $r['total'];
			if ( $r['novo'] ) {
				$t['novo_n']++;
				$t['novo_sales'] += $r['novo_v'];
			}
		}
		if ( $r['points'] > 0 ) {
			$t['points_n']++;
			$t['points_sum'] += $r['points'];
		}
		if ( $r['guest'] ) {
			$t['guest']++;
		}
	}

	return $t;
}

/**
 * 주차별 신규 가입자 수.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d.
 * @return array<string,int> 날짜 => 수
 */
function signups( string $from, string $to ): array {
	global $wpdb;
	$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
		"SELECT DATE(user_registered) d, COUNT(*) c FROM {$wpdb->users}
		 WHERE user_registered >= %s AND user_registered < %s GROUP BY d ORDER BY d",
		$from . ' 00:00:00',
		gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00'
	), ARRAY_A );

	$out = array();
	foreach ( $rows as $r ) {
		$out[ (string) $r['d'] ] = (int) $r['c'];
	}

	return $out;
}

/**
 * 하루 평균으로 두 기간을 견줍니다.
 *
 * @param array $a   앞 기간 합계.
 * @param array $b   뒤 기간 합계.
 * @param int   $da  앞 기간 일수.
 * @param int   $db  뒤 기간 일수.
 * @param string $key 항목.
 * @return string
 */
function per_day( array $a, array $b, int $da, int $db, string $key ): string {
	$x = $da > 0 ? $a[ $key ] / $da : 0;
	$y = $db > 0 ? $b[ $key ] / $db : 0;
	$d = ( $x > 0 ) ? ( ( $y - $x ) / $x * 100 ) : 0;

	return sprintf( '%12s → %12s  (%+.1f%%)', number_format( $x, 1 ), number_format( $y, 1 ), $d );
}

/**
 * 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! allowed() ) {
		wp_die( esc_html__( '권한이 없습니다.', 'duckhoo-redesign' ) );
	}

	$live  = live_date();
	$weeks = isset( $_GET['dhr_weeks'] ) ? max( 4, min( 26, absint( wp_unslash( $_GET['dhr_weeks'] ) ) ) ) : 10; // phpcs:ignore WordPress.Security.NonceVerification
	$today = current_time( 'Y-m-d' );
	$start = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $weeks * 7 ) . ' days' ) );

	$rows = orders( $start, $today );
	$regs = signups( $start, $today );

	$r   = array();
	$r[] = '### 기간: ' . $start . ' ~ ' . $today . ' (' . $weeks . '주) · 리디자인 프로덕션 적용일 ' . $live;
	$r[] = '주문 ' . count( $rows ) . '건을 읽었습니다.';
	$r[] = '';

	// 주차별.
	$r[] = '### 주차별';
	$r[] = sprintf( '%-12s %6s %6s %14s %12s %7s %7s %8s %8s',
		'주 시작', '전체', '유효', '매출', '평균', '취소', '가입', '적립사용', '노보건' );
	$bucket = array();
	foreach ( $rows as $row ) {
		if ( '' === $row['date'] ) {
			continue;
		}
		$mon = gmdate( 'Y-m-d', strtotime( 'monday this week', strtotime( $row['date'] ) ) );
		$bucket[ $mon ][] = $row;
	}
	ksort( $bucket );
	foreach ( $bucket as $mon => $list ) {
		$t   = totals( $list );
		$reg = 0;
		foreach ( $regs as $d => $c ) {
			if ( $d >= $mon && $d < gmdate( 'Y-m-d', strtotime( $mon . ' +7 days' ) ) ) {
				$reg += $c;
			}
		}
		$r[] = sprintf( '%-12s %6d %6d %14s %12s %7d %7d %8d %8d',
			$mon, $t['n'], $t['paid'], number_format( $t['sales'] ),
			$t['paid'] ? number_format( $t['sales'] / $t['paid'] ) : '0',
			$t['cancelled'], $reg, $t['points_n'], $t['novo_n'] );
	}

	// 전후.
	$before = array();
	$after  = array();
	foreach ( $rows as $row ) {
		if ( '' === $row['date'] ) {
			continue;
		}
		if ( $row['date'] < $live ) {
			$before[] = $row;
		} else {
			$after[] = $row;
		}
	}
	$da = max( 1, (int) round( ( strtotime( $live ) - strtotime( $start ) ) / 86400 ) );
	$db = max( 1, (int) round( ( strtotime( $today ) - strtotime( $live ) ) / 86400 ) + 1 );
	$ta = totals( $before );
	$tb = totals( $after );

	$r[] = '';
	$r[] = '### 리디자인 전 · 후 (하루 평균)';
	$r[] = sprintf( '전: %s ~ %s (%d일, 주문 %d건) / 후: %s ~ %s (%d일, 주문 %d건)',
		$start, gmdate( 'Y-m-d', strtotime( $live . ' -1 day' ) ), $da, $ta['n'], $live, $today, $db, $tb['n'] );
	$r[] = '  주문 건수   ' . per_day( $ta, $tb, $da, $db, 'n' );
	$r[] = '  유효 주문   ' . per_day( $ta, $tb, $da, $db, 'paid' );
	$r[] = '  매출        ' . per_day( $ta, $tb, $da, $db, 'sales' );
	$r[] = '  취소 건수   ' . per_day( $ta, $tb, $da, $db, 'cancelled' );
	$r[] = '  적립금 사용 ' . per_day( $ta, $tb, $da, $db, 'points_n' );
	$r[] = '  노보 주문   ' . per_day( $ta, $tb, $da, $db, 'novo_n' );
	$r[] = sprintf( '  평균 주문액 %12s → %12s',
		$ta['paid'] ? number_format( $ta['sales'] / $ta['paid'] ) : '0',
		$tb['paid'] ? number_format( $tb['sales'] / $tb['paid'] ) : '0' );
	$r[] = sprintf( '  취소율      %11.1f%% → %11.1f%%',
		$ta['n'] ? $ta['cancelled'] / $ta['n'] * 100 : 0,
		$tb['n'] ? $tb['cancelled'] / $tb['n'] * 100 : 0 );
	$r[] = sprintf( '  비회원 주문 %12d → %12d', $ta['guest'], $tb['guest'] );

	$ra = 0;
	$rb = 0;
	foreach ( $regs as $d => $c ) {
		if ( $d < $live ) {
			$ra += $c;
		} else {
			$rb += $c;
		}
	}
	$r[] = sprintf( '  신규 가입   %12s → %12s  (%+.1f%%)',
		number_format( $ra / $da, 1 ), number_format( $rb / $db, 1 ),
		$ra ? ( ( $rb / $db ) - ( $ra / $da ) ) / ( $ra / $da ) * 100 : 0 );

	// 노보 기여.
	$r[] = '';
	$r[] = '### 노보 기여';
	$r[] = sprintf( '  전: 노보 주문 %d건 · 노보 매출 %s (전체 매출의 %.1f%%)',
		$ta['novo_n'], number_format( $ta['novo_sales'] ), $ta['sales'] ? $ta['novo_sales'] / $ta['sales'] * 100 : 0 );
	$r[] = sprintf( '  후: 노보 주문 %d건 · 노보 매출 %s (전체 매출의 %.1f%%)',
		$tb['novo_n'], number_format( $tb['novo_sales'] ), $tb['sales'] ? $tb['novo_sales'] / $tb['sales'] * 100 : 0 );
	$r[] = '  (노보 상품 ' . count( novo_product_ids() ) . '종 기준 — 분류 novo-liquid + 이름에 "노보")';

	// 상태 분포.
	$r[] = '';
	$r[] = '### 상태 분포 (전체 기간)';
	$byst = array();
	foreach ( $rows as $row ) {
		$byst[ $row['status'] ] = ( $byst[ $row['status'] ] ?? 0 ) + 1;
	}
	arsort( $byst );
	foreach ( $byst as $st => $n ) {
		$r[] = sprintf( '  %-22s %5d', $st, $n );
	}

	$r[] = '';
	$r[] = '### 적립금';
	$r[] = sprintf( '  전: %d건 · %s원 사용', $ta['points_n'], number_format( $ta['points_sum'] ) );
	$r[] = sprintf( '  후: %d건 · %s원 사용', $tb['points_n'], number_format( $tb['points_sum'] ) );

	$text = implode( "\n", $r );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '성과 분석', 'duckhoo-redesign' ); ?></h1>
		<p><?php esc_html_e( '이 화면은 아무것도 바꾸지 않습니다. 주문·회원·적립금을 읽어서 주차별로 세어 보여 줄 뿐입니다. 아래 상자를 클릭해 전체 복사해 주세요.', 'duckhoo-redesign' ); ?></p>
		<p>
			<label><?php esc_html_e( '몇 주치', 'duckhoo-redesign' ); ?>
				<input type="number" id="dhr-weeks" min="4" max="26" value="<?php echo esc_attr( (string) $weeks ); ?>" style="width:6em">
			</label>
			<button type="button" class="button" onclick="location.search='?page=<?php echo esc_js( SLUG ); ?>&amp;dhr_weeks='+document.getElementById('dhr-weeks').value"><?php esc_html_e( '다시 세기', 'duckhoo-redesign' ); ?></button>
		</p>
		<textarea readonly style="width:100%;height:64vh;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;white-space:pre" onclick="this.select()"><?php echo esc_textarea( $text ); ?></textarea>
	</div>
	<?php
}
