<?php
/**
 * 상품 원가 → 월말 결산의 순이익.
 *
 * 원가는 어디에도 쓰지 않는다 — 상품 · 주문은 그대로 두고 옵션 하나(`duckhoo_costs`)와 씨앗 파일
 * (`cost-table.php`, 사장님 「상품 원가 DB」 2026-09-29)만 읽는다. 주문 줄(이름 · 수량 · 상품 번호)에
 * 원가를 붙이는 순서: ①관리자 화면에 적은 주문별 원가 ②상품 번호 ③정규화한 이름 ④브랜드 병당 원가 × 이름의 병 수.
 * 넷 다 없으면 「원가 모름」으로 매출을 따로 세어 보고서에 적는다 — 숫자를 만들지 않는다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Cost;

defined( 'ABSPATH' ) || exit;

const OPT  = 'duckhoo_costs';
const SLUG = 'duckhoo-cost';

/**
 * 이름 정규화 — 빈칸 · 느낌표 · 별 · 세로줄을 떼고 소문자. 「(멘솔 없음)」 꼬리는 뗀다 (원가표에는 없는 표시).
 */
function norm( string $n ): string {
	$n = mb_strtolower( html_entity_decode( $n, ENT_QUOTES, 'UTF-8' ) );
	$n = str_replace( array( '&#8211;', '–', '—' ), '-', $n );
	$n = (string) preg_replace( '/\(\s*멘솔\s*없음\s*\)/u', '', $n );
	return (string) preg_replace( '/[\s!★|]+/u', '', $n );
}

function seed(): array {
	static $s = null;
	if ( null === $s ) {
		$f = __DIR__ . '/cost-table.php';
		$s = is_file( $f ) ? (array) include $f : array();
	}
	return $s;
}

/**
 * 씨앗 + 관리자 옵션. 옵션이 이긴다.
 */
function table(): array {
	$s = seed();
	$o = (array) get_option( OPT, array() );
	$t = array();
	foreach ( array( 'name', 'brand', 'pid', 'order' ) as $k ) {
		$t[ $k ] = array_replace( (array) ( $s[ $k ] ?? array() ), (array) ( $o[ $k ] ?? array() ) );
	}
	return (array) apply_filters( 'duckhoo_cost_table', $t );
}

/**
 * 이름에서 병 수. 기기 · 팟 · 증정 구성은 병이 단위가 아니라 0 (브랜드 병당 원가로 못 센다).
 */
function bottles( string $name ): int {
	if ( preg_match( '/증정|사은품|기기|디바이스|스타터|팟|코일|드립팁|첨가제|전자담배/u', $name ) ) {
		return 0;
	}
	// 「노보 타박멘솔2000, 노보 블랙멘솔 1000 결제」처럼 병 · ml · 원 없이 큰 숫자가 맨몸으로 있으면 수량이 이름에 숨은
	// 수동 결제다. 낱병 하나로 세면 2,100만원 주문의 원가가 6,000원이 된다 → 0 (원가 모름 → 관리자가 주문별로 적는다).
	if ( preg_match( '/(?<![\d.,])\d{3,}(?![\d,]*\s*(?:원|ml|mg|ea|옴|병|\+))/iu', $name ) ) {
		return 0;
	}
	if ( preg_match( '/(\d+)\s*\+\s*(\d+)/u', $name, $m ) ) {
		return (int) $m[1] + (int) $m[2];
	}
	if ( preg_match( '/(\d+)\s*병/u', $name, $m ) ) {
		return max( 1, (int) $m[1] );
	}
	return 1;
}

function brand_of( string $name ): string {
	if ( function_exists( '\\Duckhoo\\Redesign\\Anatomy\\brand' ) ) {
		return \Duckhoo\Redesign\Anatomy\brand( $name );
	}
	return preg_match( '/^\s*\[([^\]]+)\]/u', $name, $m ) ? trim( $m[1] ) : '기타';
}

/**
 * 주문 줄 하나의 원가 (수량 곱한 값). 모르면 null.
 *
 * @return array{cost:float,src:string}|null
 */
function line_cost( string $name, int $qty, int $pid = 0, ?array $t = null ): ?array {
	$t   = $t ?? table();
	$qty = max( 1, $qty );
	if ( $pid > 0 && isset( $t['pid'][ $pid ] ) && (float) $t['pid'][ $pid ] > 0 ) {
		return array( 'cost' => (float) $t['pid'][ $pid ] * $qty, 'src' => '번호' );
	}
	$k = norm( $name );
	if ( '' !== $k && isset( $t['name'][ $k ] ) && (float) $t['name'][ $k ] > 0 ) {
		return array( 'cost' => (float) $t['name'][ $k ] * $qty, 'src' => '이름' );
	}
	$b = brand_of( $name );
	$n = bottles( $name );
	if ( $n > 0 && isset( $t['brand'][ $b ] ) && (float) $t['brand'][ $b ] > 0 ) {
		return array( 'cost' => (float) $t['brand'][ $b ] * $n * $qty, 'src' => '브랜드' );
	}
	return null;
}

/**
 * 한 달 원가. 확정 주문(매출 화면과 같은 목록)만.
 *
 * @param array $conf  Sales\fetch 행 (id · t …).
 * @param array $items Anatomy\items (주문 번호 → 줄들).
 */
function month_cost( array $conf, array $items, ?array $t = null ): array {
	$t   = $t ?? table();
	$out = array( 'cost' => 0.0, 'known' => 0.0, 'unknown' => 0.0, 'lines' => 0, 'miss' => 0, 'unknown_list' => array(), 'by_src' => array() );
	$ul  = array();
	foreach ( $conf as $r ) {
		$oid = (int) $r['id'];
		if ( isset( $t['order'][ $oid ] ) && (float) $t['order'][ $oid ] > 0 ) {
			$out['cost']  += (float) $t['order'][ $oid ];
			$out['known'] += (float) $r['t'];
			$out['by_src']['주문'] = ( $out['by_src']['주문'] ?? 0 ) + 1;
			continue;
		}
		foreach ( $items[ $oid ] ?? array() as $l ) {
			$out['lines']++;
			$c = line_cost( (string) $l['name'], (int) $l['qty'], (int) ( $l['pid'] ?? 0 ), $t );
			if ( $c ) {
				$out['cost']  += $c['cost'];
				$out['known'] += (float) $l['total'];
				$out['by_src'][ $c['src'] ] = ( $out['by_src'][ $c['src'] ] ?? 0 ) + 1;
			} else {
				$out['miss']++;
				$out['unknown'] += (float) $l['total'];
				$k = trim( (string) $l['name'] );
				$ul[ $k ] = ( $ul[ $k ] ?? 0.0 ) + (float) $l['total'];
			}
		}
	}
	arsort( $ul );
	$out['unknown_list'] = array_slice( $ul, 0, 8, true );
	return $out;
}

/**
 * 원가율 · 순이익. 원가를 아는 매출 기준으로 비율을 내고, 모르는 매출은 따로 말한다.
 */
function profit( float $sales, float $spend, array $mc ): array {
	$cost   = (float) $mc['cost'];
	$profit = $sales - $spend - $cost;
	return array(
		'cost'       => $cost,
		'profit'     => $profit,
		'rate'       => $sales > 0 ? round( $profit / $sales * 100, 1 ) : 0.0,
		'cost_rate'  => $mc['known'] > 0 ? round( $cost / (float) $mc['known'] * 100, 1 ) : 0.0,
		'unknown'    => (float) $mc['unknown'],
		'coverage'   => ( $mc['known'] + $mc['unknown'] ) > 0 ? (int) round( (float) $mc['known'] / ( (float) $mc['known'] + (float) $mc['unknown'] ) * 100 ) : 0,
	);
}

/**
 * 붙여 넣은 글 → 옵션 조각. 한 줄에 하나:
 *   상품 이름 <탭 또는 = 또는 쉼표> 원가        (파는 단위 하나의 원가)
 *   브랜드 노보 5000                              (병당)
 *   #207 15000                                    (상품 번호)
 *   주문 202609180004850 15000000                 (그 주문 통째로)
 * 원가 0 은 지우기.
 */
function parse( string $text ): array {
	$o = array( 'name' => array(), 'brand' => array(), 'pid' => array(), 'order' => array() );
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		$line = trim( (string) preg_replace( '/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $line ) );
		if ( '' === $line || str_starts_with( $line, '#' ) && ! preg_match( '/^#\d+/', $line ) ) {
			continue;
		}
		if ( ! preg_match( '/^(.*?)[\s=,\t]+([\d,]+)\s*원?\s*$/u', $line, $m ) ) {
			continue;
		}
		$key = trim( $m[1], " \t=,:" );
		$val = (float) str_replace( ',', '', $m[2] );
		if ( '' === $key ) {
			continue;
		}
		if ( preg_match( '/^브랜드\s+(.+)$/u', $key, $b ) ) {
			$o['brand'][ trim( $b[1] ) ] = $val;
		} elseif ( preg_match( '/^주문\s*#?\s*(\d+)$/u', $key, $b ) ) {
			$o['order'][ (int) $b[1] ] = $val;
		} elseif ( preg_match( '/^#(\d+)$/', $key, $b ) ) {
			$o['pid'][ (int) $b[1] ] = $val;
		} else {
			$o['name'][ norm( $key ) ] = $val;
		}
	}
	return $o;
}

/**
 * 주문 번호(손님이 보는 15자리)로 적었으면 워드커머스 주문 ID 로 바꾼다. 못 찾으면 그대로.
 */
function order_ids( array $order ): array {
	$out = array();
	foreach ( $order as $k => $v ) {
		$id = (int) $k;
		if ( $id > 100000000 && function_exists( 'wc_get_orders' ) ) {
			$hit = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'meta_key' => '_order_number', 'meta_value' => (string) $k ) ); // phpcs:ignore
			if ( is_array( $hit ) && $hit ) {
				$id = (int) $hit[0];
			}
		}
		$out[ $id ] = $v;
	}
	return $out;
}

/* ── 관리자 ─────────────────────────────────────────────────────────────── */

function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

function menu(): void {
	if ( ! may() ) {
		return;
	}
	add_submenu_page( 'duckhoo-sales', '원가표', '원가표', current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options', SLUG, __NAMESPACE__ . '\\screen' );
}

function handle_post(): string {
	if ( ! isset( $_POST['dhr_cost_nonce'] ) || ! wp_verify_nonce( (string) $_POST['dhr_cost_nonce'], 'dhr_cost' ) || ! may() ) { // phpcs:ignore
		return '';
	}
	if ( ! empty( $_POST['dhr_cost_clear'] ) ) {
		delete_option( OPT );
		return '관리자에서 적은 원가를 모두 지웠습니다. 씨앗 원가표는 그대로입니다.';
	}
	$txt = (string) wp_unslash( (string) ( $_POST['dhr_cost_text'] ?? '' ) ); // phpcs:ignore
	$p   = parse( $txt );
	$p['order'] = order_ids( $p['order'] );
	$o   = (array) get_option( OPT, array() );
	$n   = 0;
	foreach ( $p as $k => $vals ) {
		foreach ( $vals as $key => $v ) {
			if ( $v > 0 ) {
				$o[ $k ][ $key ] = $v;
			} else {
				unset( $o[ $k ][ $key ] );
			}
			$n++;
		}
	}
	update_option( OPT, $o, false );
	if ( function_exists( '\\Duckhoo\\Redesign\\Monthly\\data' ) ) {
		delete_transient( \Duckhoo\Redesign\Monthly\CACHE . '_' . (string) current_time( 'Y-m' ) . '_' . (string) current_time( 'Y-m-d' ) );
	}
	return $n ? "{$n}줄을 저장했습니다. 월말 결산의 이번 달 캐시를 비웠으니 그 화면을 다시 열면 순이익이 새 값으로 나옵니다." : '읽을 수 있는 줄이 없었습니다. 「상품 이름 = 원가」 꼴로 한 줄에 하나씩 적어 주세요.';
}

function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$msg = handle_post();
	$t   = table();
	$o   = (array) get_option( OPT, array() );
	echo '<div class="wrap dhr-sl"><h1>원가표</h1>';
	if ( '' !== $msg ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<p class="dhr-sl-note">월말 결산의 <b>순이익</b>은 이 표로 셉니다. 상품 · 주문에는 아무것도 쓰지 않습니다 — 씨앗(사장님 원가 DB 2026-09-29) 위에 여기 적은 값을 얹습니다. 이름이 표에 없는 상품은 <b>브랜드 병당 원가 × 이름의 병 수</b>로 세고, 그것도 없으면 「원가 모름」으로 매출을 따로 적습니다.</p>';

	echo '<section class="dhr-sl-sec"><h2>원가 적기</h2><form method="post">';
	wp_nonce_field( 'dhr_cost', 'dhr_cost_nonce' );
	echo '<p>한 줄에 하나. <code>상품 이름 = 원가</code>(파는 단위 하나) · <code>브랜드 노보 5000</code>(병당) · <code>#207 15000</code>(상품 번호) · <code>주문 202609180004850 15000000</code>(그 주문 통째로 — 수동 결제 같은 것). 0 이면 지우기.</p>';
	echo '<p><textarea name="dhr_cost_text" rows="6" style="width:100%;max-width:720px;font-size:12px" placeholder="[노보] 타박멘솔 (9.8mg / 30ml) = 5000&#10;브랜드 맥스쿨 3000&#10;주문 202609070004123 15000000"></textarea></p>';
	echo '<p><button class="button button-primary">저장</button> &nbsp; <button class="button" name="dhr_cost_clear" value="1" onclick="return confirm(\'관리자에서 적은 원가를 모두 지울까요? 씨앗 표는 남습니다.\')">관리자 값 모두 지우기</button></p></form>';
	if ( $o ) {
		echo '<details class="dhr-sl-tab"><summary>지금 관리자에 적힌 값</summary><pre style="font-size:12px">';
		foreach ( array( 'brand' => '브랜드 ', 'pid' => '#', 'order' => '주문 ', 'name' => '' ) as $k => $pre ) {
			foreach ( (array) ( $o[ $k ] ?? array() ) as $key => $v ) {
				echo esc_html( $pre . $key . ' = ' . number_format( (float) $v ) ) . "\n";
			}
		}
		echo '</pre></details>';
	}
	echo '</section>';

	echo '<section class="dhr-sl-sec"><h2>상품마다 어떻게 세는지</h2>';
	$prods = function_exists( 'wc_get_products' ) ? (array) wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) : array();
	$miss  = 0;
	echo '<div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>#</th><th>상품</th><th class="dhr-sl-num">판매가</th><th class="dhr-sl-num">원가 (단위 하나)</th><th>어디서</th><th class="dhr-sl-num">원가율</th></tr></thead><tbody>';
	foreach ( $prods as $p ) {
		if ( ! is_object( $p ) || ! method_exists( $p, 'get_name' ) ) {
			continue;
		}
		$c   = line_cost( (string) $p->get_name(), 1, (int) $p->get_id(), $t );
		$pr  = (float) $p->get_price();
		if ( ! $c ) {
			$miss++;
		}
		printf(
			'<tr%s><td>%d</td><td>%s</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-num">%s</td><td>%s</td><td class="dhr-sl-num">%s</td></tr>',
			$c ? '' : ' style="background:#FBF4EF"',
			(int) $p->get_id(),
			esc_html( (string) $p->get_name() ),
			esc_html( number_format( $pr ) ),
			$c ? esc_html( number_format( $c['cost'] ) ) : '<b>모름</b>',
			$c ? esc_html( $c['src'] ) : '—',
			$c && $pr > 0 ? esc_html( round( $c['cost'] / $pr * 100 ) . '%' ) : '—'
		);
	}
	echo '</tbody></table></div>';
	echo '<p class="dhr-sl-note">' . count( $prods ) . '개 중 원가를 모르는 상품 ' . (int) $miss . '개 (주황 줄). 위 칸에 <code>이름 = 원가</code> 또는 <code>브랜드 이름 병당원가</code> 로 적으면 바로 들어갑니다.</p></section>';
	echo '</div>';
}

add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 21 );
