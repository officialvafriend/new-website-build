<?php
/**
 * 적립금 진단 화면 (관리자 전용, 읽기만 함).
 *
 * 왜 필요한가: 적립금을 쓴 주문을 취소해도 적립금이 돌아오지 않는다. 되돌리는 코드를
 * 우리가 짜려면 **적립금을 더하는 쪽이 어디인지**를 알아야 하는데, 테마와 keyple 의
 * PHP 는 이 저장소에 없다. 이 화면은 사이트 안에서 그것을 찾아 보여 준다.
 *
 * 아무것도 바꾸지 않는다. 읽기만 하고, 결과를 그대로 복사할 수 있게 보여 준다.
 * 다 쓰고 나면 이 파일과 `duckhoo-redesign.php` 의 require 한 줄을 지우면 된다.
 *
 * 도구 → 적립금 진단.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Points\Doctor;

use function Duckhoo\Redesign\Points\used;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CAP  = 'manage_woocommerce';
const SLUG = 'duckhoo-points-doctor';

/**
 * 관리자가 이 화면을 볼 수 있는가.
 *
 * @return bool
 */
function allowed(): bool {
	return current_user_can( CAP ) || current_user_can( 'manage_options' );
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
		__( '적립금 진단', 'duckhoo-redesign' ),
		__( '적립금 진단', 'duckhoo-redesign' ),
		current_user_can( CAP ) ? CAP : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 이름이 적립금을 가리키는가.
 *
 * @param string $name 이름.
 * @return bool
 */
function looks_like_points( string $name ): bool {
	return (bool) preg_match( '/(point|mileage|적립)/i', $name );
}

/**
 * 훅에 걸린 콜백 이름들.
 *
 * @param string $hook 훅 이름.
 * @return string[]
 */
function callbacks_on( string $hook ): array {
	global $wp_filter;
	$out = array();

	if ( empty( $wp_filter[ $hook ] ) ) {
		return $out;
	}

	// `$wp_filter[$hook]` 는 WP_Hook **객체**다. 배열로 캐스팅하면 콜백이 아니라
	// 그 객체의 속성(callbacks · priorities · nesting_level …)이 나온다. 한 번 그렇게 헛돌았다.
	$hooked = $wp_filter[ $hook ];
	$groups = ( $hooked instanceof \WP_Hook || isset( $hooked->callbacks ) ) ? (array) $hooked->callbacks : (array) $hooked;

	foreach ( $groups as $priority => $entries ) {
		foreach ( (array) $entries as $entry ) {
			$fn = $entry['function'] ?? null;
			if ( is_string( $fn ) ) {
				$name = $fn;
			} elseif ( is_array( $fn ) && 2 === count( $fn ) ) {
				$name = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . (string) $fn[1];
			} elseif ( $fn instanceof \Closure ) {
				$name = '{익명 함수}';
				try {
					$r    = new \ReflectionFunction( $fn );
					$name = '{익명 함수} ' . basename( (string) $r->getFileName() ) . ':' . $r->getStartLine();
				} catch ( \Throwable $e ) { // phpcs:ignore
					$name = '{익명 함수}';
				}
			} else {
				$name = '{알 수 없음}';
			}
			$out[] = $priority . '  ' . $name;
		}
	}

	return $out;
}

/**
 * 적립금을 다룰 법한 함수 · 메서드 이름.
 *
 * @return string[]
 */
function point_functions(): array {
	$out = array();

	$defined = get_defined_functions();
	foreach ( (array) ( $defined['user'] ?? array() ) as $fn ) {
		if ( looks_like_points( (string) $fn ) ) {
			$out[] = (string) $fn;
		}
	}

	foreach ( get_declared_classes() as $class ) {
		if ( ! preg_match( '/(keyple|^WD|wd_|woodmart|duckhoo)/i', $class ) && ! looks_like_points( $class ) ) {
			continue;
		}
		try {
			$r = new \ReflectionClass( $class );
			if ( ! $r->getFileName() ) {
				continue; // 내장 클래스.
			}
			foreach ( $r->getMethods() as $m ) {
				if ( looks_like_points( $m->getName() ) ) {
					$out[] = $class . '::' . $m->getName();
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore
			continue;
		}
	}

	sort( $out );

	return array_values( array_unique( $out ) );
}

/**
 * 이름에 적립금이 든 표.
 *
 * @return string[]
 */
function point_tables(): array {
	global $wpdb;
	$out = array();

	if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
		return $out;
	}

	$tables = (array) $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB
	foreach ( $tables as $t ) {
		if ( looks_like_points( (string) $t ) ) {
			$out[] = (string) $t;
		}
	}

	return $out;
}

/**
 * 최근 주문 중 적립금을 쓴 것.
 *
 * @param int $limit 몇 건까지 볼지.
 * @return array<int,array<string,mixed>>
 */
function point_orders( int $limit = 40 ): array {
	$out = array();

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return $out;
	}

	$orders = wc_get_orders( array(
		'limit'   => $limit,
		'orderby' => 'date',
		'order'   => 'DESC',
	) );

	foreach ( (array) $orders as $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			continue;
		}
		$amount = used( $order );
		if ( $amount <= 0 ) {
			continue;
		}
		$meta = array();
		if ( method_exists( $order, 'get_meta_data' ) ) {
			foreach ( (array) $order->get_meta_data() as $m ) {
				$d = method_exists( $m, 'get_data' ) ? (array) $m->get_data() : array();
				$k = (string) ( $d['key'] ?? '' );
				if ( '' !== $k && looks_like_points( $k ) ) {
					$v      = $d['value'] ?? '';
					$meta[] = $k . ' = ' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
				}
			}
		}
		$fees = array();
		if ( method_exists( $order, 'get_items' ) ) {
			foreach ( (array) $order->get_items( 'fee' ) as $fee ) {
				$fees[] = $fee->get_name() . ' = ' . $fee->get_total();
			}
		}
		$out[] = array(
			'id'     => $order->get_id(),
			'status' => method_exists( $order, 'get_status' ) ? $order->get_status() : '',
			'user'   => method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0,
			'used'   => $amount,
			'meta'   => $meta,
			'fees'   => $fees,
		);
	}

	return $out;
}

/**
 * 한 회원의 적립금스러운 메타.
 *
 * @param int $user_id 회원 번호.
 * @return string[]
 */
function user_point_meta( int $user_id ): array {
	$out = array();

	if ( $user_id <= 0 ) {
		return $out;
	}

	foreach ( (array) get_user_meta( $user_id ) as $key => $vals ) {
		if ( ! looks_like_points( (string) $key ) ) {
			continue;
		}
		$v     = is_array( $vals ) ? reset( $vals ) : $vals;
		$v     = maybe_unserialize( $v );
		$out[] = $key . ' = ' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
	}

	sort( $out );

	return $out;
}


/**
 * 있으면 부르고, 터지면 삼킨다. 남의 함수를 진단하려고 부르는 자리라 그렇다.
 *
 * @param string $fn   함수 이름.
 * @param mixed  ...$a 인자.
 * @return string 결과를 사람이 읽을 수 있게.
 */
function safe_call( string $fn, ...$a ): string {
	if ( ! function_exists( $fn ) ) {
		return '(없음)';
	}
	try {
		return (string) wp_json_encode( $fn( ...$a ), JSON_UNESCAPED_UNICODE );
	} catch ( \Throwable $e ) { // phpcs:ignore
		return '(호출 실패: ' . $e->getMessage() . ')';
	}
}

/**
 * 들여다볼 함수들. 2026-09-07 진단으로 이름이 확인된 것만 적는다 —
 * 아무 함수나 찍어 내지 않기 위해서다.
 *
 * @return string[]
 */
function target_functions(): array {
	return (array) apply_filters( 'duckhoo_points_doctor_functions', array(
		'wd_log_keyple_points_change',
		'wd_get_keyple_points_log_table',
		'wd_get_keyple_points_log_columns',
		'wd_apply_point_discount_fee',
		'wd_get_myaccount_user_points',
		'wd_award_purchase_points_on_delivery',
		'wd_get_user_point_history',
	) );
}

/**
 * 함수 하나의 원본 코드. 없으면 빈 문자열.
 *
 * 사이트에 이미 돌고 있는 코드를 읽기만 한다. 이름은 위 목록에 있는 것만 받는다.
 *
 * @param string $fn    함수 이름.
 * @param int    $limit 몇 줄까지.
 * @return string
 */
function function_source( string $fn, int $limit = 160 ): string {
	if ( ! in_array( $fn, target_functions(), true ) || ! function_exists( $fn ) ) {
		return '';
	}
	try {
		$r    = new \ReflectionFunction( $fn );
		$file = (string) $r->getFileName();
		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}
		$lines = file( $file );
		if ( ! is_array( $lines ) ) {
			return '';
		}
		$start = max( 0, $r->getStartLine() - 1 );
		$end   = min( count( $lines ), $r->getEndLine() );
		$body  = array_slice( $lines, $start, min( $limit, $end - $start ) );

		return '--- ' . $fn . '()  ' . basename( $file ) . ':' . $r->getStartLine() . '-' . $r->getEndLine() . "\n"
			. rtrim( implode( '', $body ) );
	} catch ( \Throwable $e ) { // phpcs:ignore
		return '';
	}
}

/**
 * 적립금 함수가 사는 파일에서 잔액 · 취소를 건드리는 줄만 뽑습니다.
 *
 * 파일 전체를 옮기지 않는다 — 어디서 잔액을 읽고 쓰는지, 취소에 무엇이 걸려 있는지만 본다.
 *
 * @param int $limit 몇 줄까지.
 * @return string[]
 */
function balance_lines( int $limit = 120 ): array {
	$out   = array();
	$files = array();

	foreach ( target_functions() as $fn ) {
		if ( ! function_exists( $fn ) ) {
			continue;
		}
		try {
			$f = ( new \ReflectionFunction( $fn ) )->getFileName();
			if ( $f && is_readable( $f ) ) {
				$files[ $f ] = true;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore
			continue;
		}
	}

	foreach ( array_keys( $files ) as $file ) {
		$lines = file( $file );
		if ( ! is_array( $lines ) ) {
			continue;
		}
		$out[] = '--- ' . basename( $file ) . ' (' . count( $lines ) . '줄)';
		foreach ( $lines as $i => $line ) {
			if ( ! preg_match( "/_keyple_points|keyple_points_log|point_discount|order_status_(cancel|refund|changed)|add_action\\s*\\(\\s*['\"]woocommerce_order/i", $line ) ) {
				continue;
			}
			$out[] = sprintf( '%5d  %s', $i + 1, rtrim( $line ) );
			if ( count( $out ) > $limit ) {
				$out[] = '  … (여기까지)';
				return $out;
			}
		}
	}

	return $out;
}

/**
 * 적립금 원장(wp_keyple_points_log)의 칸 이름과 최근 줄.
 *
 * @param int[] $user_ids 이 회원들 것만. 비우면 최근 것 전부.
 * @param int   $limit    몇 줄까지.
 * @return string[]
 */
function ledger( array $user_ids = array(), int $limit = 30 ): array {
	global $wpdb;
	$out = array();

	$table = '';
	try {
		$table = function_exists( 'wd_get_keyple_points_log_table' ) ? (string) wd_get_keyple_points_log_table() : '';
	} catch ( \Throwable $e ) { // phpcs:ignore
		$table = '';
	}
	if ( '' === $table ) {
		$table = $wpdb->prefix . 'keyple_points_log';
	}
	$out[] = '표 이름: ' . $table;

	if ( function_exists( 'wd_get_keyple_points_log_columns' ) ) {
		$out[] = '칸 매핑: ' . safe_call( 'wd_get_keyple_points_log_columns' );
	}

	$cols = (array) $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB
	if ( ! $cols ) {
		$out[] = '(표를 읽지 못했다)';
		return $out;
	}
	$names = array();
	foreach ( $cols as $c ) {
		$names[] = $c->Field . ' ' . $c->Type; // phpcs:ignore WordPress.NamingConventions
	}
	$out[] = '칸: ' . implode( ' | ', $names );

	$rows = (array) $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $table ) . '` ORDER BY 1 DESC LIMIT ' . absint( $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	foreach ( $rows as $row ) {
		$bits = array();
		foreach ( (array) $row as $k => $v ) {
			$bits[] = $k . '=' . ( is_scalar( $v ) ? (string) $v : '' );
		}
		$out[] = '  ' . implode( ' · ', $bits );
	}

	return $out;
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

	$orders = point_orders();
	$user   = isset( $_GET['dhr_user'] ) ? absint( wp_unslash( $_GET['dhr_user'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $user && $orders ) {
		$user = (int) $orders[0]['user'];
	}

	$report   = array();
	$report[] = '### 취소·환불 훅에 걸린 것';
	foreach ( array( 'woocommerce_order_status_cancelled', 'woocommerce_order_status_refunded', 'woocommerce_order_status_changed', 'woocommerce_cancelled_order' ) as $hook ) {
		$cbs      = callbacks_on( $hook );
		$report[] = '';
		$report[] = $hook . ' (' . count( $cbs ) . ')';
		foreach ( $cbs as $c ) {
			$report[] = '  ' . $c;
		}
	}

	$report[] = '';
	$report[] = '### 적립금을 다룰 법한 함수 · 메서드';
	foreach ( point_functions() as $fn ) {
		$report[] = '  ' . $fn;
	}

	$report[] = '';
	$report[] = '### 적립금스러운 표';
	foreach ( point_tables() as $t ) {
		$report[] = '  ' . $t;
	}

	$report[] = '';
	$report[] = '### 최근 주문 중 적립금을 쓴 것 (' . count( $orders ) . '건)';
	foreach ( $orders as $o ) {
		$report[] = '  #' . $o['id'] . ' [' . $o['status'] . '] 회원 ' . $o['user'] . ' · 사용 ' . $o['used'];
		foreach ( $o['meta'] as $m ) {
			$report[] = '      메타 ' . $m;
		}
		foreach ( $o['fees'] as $f ) {
			$report[] = '      수수료 ' . $f;
		}
	}

	$report[] = '';
	$report[] = '### 적립금을 쓴 주문의 회원 잔액';
	$seen = array();
	foreach ( $orders as $o ) {
		$uid = (int) $o['user'];
		if ( $uid <= 0 || isset( $seen[ $uid ] ) ) {
			continue;
		}
		$seen[ $uid ] = true;
		$report[]     = '  회원 ' . $uid . ' (주문 #' . $o['id'] . ' [' . $o['status'] . '] 사용 ' . $o['used'] . ')';
		foreach ( user_point_meta( $uid ) as $m ) {
			$report[] = '      ' . $m;
		}
		if ( function_exists( 'wd_get_myaccount_user_points' ) ) {
			$report[] = '      wd_get_myaccount_user_points() = ' . safe_call( 'wd_get_myaccount_user_points', $uid );
		}
	}

	$report[] = '';
	$report[] = '### 적립금 원장';
	foreach ( ledger( array_keys( $seen ) ) as $l ) {
		$report[] = $l;
	}

	$report[] = '';
	$report[] = '### 적립금을 더하고 빼는 코드';
	foreach ( target_functions() as $fn ) {
		$src = function_source( $fn );
		if ( '' !== $src ) {
			$report[] = '';
			$report[] = $src;
		}
	}

	$report[] = '';
	$report[] = '### 잔액 · 취소를 건드리는 줄';
	foreach ( balance_lines() as $l ) {
		$report[] = $l;
	}

	$text = implode( "\n", $report );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '적립금 진단', 'duckhoo-redesign' ); ?></h1>
		<p>
			<?php esc_html_e( '이 화면은 아무것도 바꾸지 않습니다. 적립금을 어디에 어떻게 저장하는지, 주문이 취소될 때 무엇이 도는지를 읽어서 보여 줄 뿐입니다. 아래 상자의 내용을 통째로 복사해 주세요.', 'duckhoo-redesign' ); ?>
		</p>
		<p>
			<label><?php esc_html_e( '회원 번호로 보기', 'duckhoo-redesign' ); ?>
				<input type="number" id="dhr-user" value="<?php echo esc_attr( (string) $user ); ?>" style="width:8em">
			</label>
			<button type="button" class="button" onclick="location.search='?page=<?php echo esc_js( SLUG ); ?>&amp;dhr_user='+document.getElementById('dhr-user').value"><?php esc_html_e( '보기', 'duckhoo-redesign' ); ?></button>
		</p>
		<textarea readonly style="width:100%;height:60vh;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px" onclick="this.select()"><?php echo esc_textarea( $text ); ?></textarea>
	</div>
	<?php
}
