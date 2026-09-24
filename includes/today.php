<?php
/**
 * 오늘 할 일 — 사장님이 아침에 여는 한 장.
 *
 * 사장님 (2026-09-24): 「매일매일 할 일을 네가 나에게 주면 그에 대한 업무를 보는 게 좋을 것 같다」.
 * 사람이 매일 적어 주는 목록은 빠지는 날이 생긴다. 사이트가 주문 · 입금 문자 · 후기 · 재고 · 문의를
 * 읽어 **그날 그날 실제 숫자로** 목록을 만든다. 아무 데도 쓰지 않는다 — 「했음」 체크 하나만 옵션에
 * 남긴다 (그날치만, 다음 날이면 비운다).
 *
 * 규칙: 급한 돈(입금) → 오늘 나갈 것(출고) → 손님(문의 · 후기) → 가게(품절 · 쿠폰) → 어제 숫자.
 * 숫자가 0 이면 「없음」으로 접어 둔다 — 할 일이 없는 날은 짧게 끝나야 한다.
 *
 * 매일 10시 30분(사이트 시간 · 사장님 11시 출근)에 같은 목록을 관리자 메일로도 보낸다 (열린 항목이 하나라도 있을 때만).
 * 끄기: 이 화면의 체크박스, 또는 `add_filter( 'duckhoo_today_mail', '__return_false' )`.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Today;

defined( 'ABSPATH' ) || exit;

const SLUG      = 'duckhoo-today';
const OPT_DONE  = 'duckhoo_today_done';
const OPT_MAIL  = 'duckhoo_today_mail';
const OPT_DISC  = 'duckhoo_discord_webhook';
const CRON      = 'duckhoo_today_mail';
const CACHE     = 'dhr_today_v1';
const MAIL_AT   = '10:30'; // 사이트 시간 — 사장님 11시 출근, 열어 보면 와 있게

/**
 * 볼 수 있는가.
 */
function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * 메뉴 — 대시보드 바로 아래.
 */
function menu(): void {
	if ( ! may() ) {
		return;
	}
	$n = open_count();
	add_menu_page(
		'오늘 할 일',
		'오늘 할 일' . ( $n > 0 ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ),
		current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen',
		'dashicons-yes-alt',
		3
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/* ── 숫자 모으기 ─────────────────────────────────────────────────────── */

/**
 * 입금전이 며칠 넘으면 손볼 때인가 (매출 화면과 같은 값).
 */
function stale_days(): int {
	return function_exists( '\\Duckhoo\\Redesign\\Sales\\stale_days' ) ? \Duckhoo\Redesign\Sales\stale_days() : 5;
}

/**
 * 이름표에 「확인필요」가 든 주문 상태 슬러그들 — 이 가게에 실제로 있는 것만.
 *
 * @return string[]
 */
function need_check_statuses(): array {
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		return array();
	}
	$out = array();
	foreach ( (array) wc_get_order_statuses() as $slug => $label ) {
		$s = preg_replace( '/^wc-/', '', (string) $slug );
		if ( str_contains( (string) $label, '확인필요' ) || str_contains( $s, 'need-check' ) ) {
			$out[] = $s;
		}
	}
	return $out;
}

/**
 * 주문 몇 건을 상태로 가볍게 읽는다 (번호 · 생성 시각 · 금액 · 이름).
 *
 * @param string[] $statuses 상태.
 * @param int      $limit    몇 건까지.
 * @return array<int,array{id:int,ts:int,total:float,name:string,o:object}>
 */
function orders( array $statuses, int $limit = 200 ): array {
	if ( ! $statuses || ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	$out = array();
	foreach ( (array) wc_get_orders( array( 'status' => $statuses, 'limit' => $limit, 'orderby' => 'date', 'order' => 'ASC', 'type' => 'shop_order' ) ) as $o ) {
		if ( ! is_object( $o ) || ! method_exists( $o, 'get_id' ) ) {
			continue;
		}
		$d    = method_exists( $o, 'get_date_created' ) ? $o->get_date_created() : null;
		$name = '';
		if ( method_exists( $o, 'get_formatted_billing_full_name' ) ) {
			$name = trim( (string) $o->get_formatted_billing_full_name() );
		}
		$out[] = array(
			'id'    => (int) $o->get_id(),
			'ts'    => $d && method_exists( $d, 'getTimestamp' ) ? (int) $d->getTimestamp() : 0,
			'total' => method_exists( $o, 'get_total' ) ? (float) $o->get_total() : 0.0,
			'name'  => $name,
			'o'     => $o,
		);
	}
	return $out;
}

/**
 * 1:1 문의 게시판(kboard)에서 답이 안 달린 글 수. 표 구조를 모르면 -1 (항목을 안 그린다).
 *
 * kboard 표는 이 저장소에 없다. `SHOW COLUMNS` 로 있는 칸을 확인하고, 없으면 셈을 포기한다 —
 * 틀린 숫자보다 없는 숫자가 낫다. 답 = 직원(관리자 · 상점 관리자)이 단 댓글.
 *
 * @return array{n:int,url:string}
 */
function inquiries(): array {
	global $wpdb;
	$none = array( 'n' => -1, 'url' => '' );
	if ( ! isset( $wpdb ) ) {
		return $none;
	}
	$page = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'inquiries' ) : null;
	$bid  = 0;
	if ( is_object( $page ) && isset( $page->post_content ) && preg_match( '/\[kboard[^\]]*\bid\s*=\s*"?(\d+)/', (string) $page->post_content, $m ) ) {
		$bid = (int) $m[1];
	}
	if ( $bid <= 0 ) {
		return $none;
	}
	$ct = $wpdb->prefix . 'kboard_board_content';
	$cm = $wpdb->prefix . 'kboard_comments';
	$cc = array_map( fn( $r ) => (string) ( $r['Field'] ?? '' ), (array) $wpdb->get_results( "SHOW COLUMNS FROM {$ct}", ARRAY_A ) ); // phpcs:ignore WordPress.DB
	$mc = array_map( fn( $r ) => (string) ( $r['Field'] ?? '' ), (array) $wpdb->get_results( "SHOW COLUMNS FROM {$cm}", ARRAY_A ) ); // phpcs:ignore WordPress.DB
	foreach ( array( 'uid', 'board_id', 'member_uid', 'status', 'date' ) as $need ) {
		if ( ! in_array( $need, $cc, true ) ) {
			return $none;
		}
	}
	foreach ( array( 'content_uid', 'member_uid' ) as $need ) {
		if ( ! in_array( $need, $mc, true ) ) {
			return $none;
		}
	}
	// 직원 = administrator · shop_manager 역할.
	$staff = array();
	foreach ( (array) get_users( array( 'role__in' => array( 'administrator', 'shop_manager' ), 'fields' => 'ID' ) ) as $id ) {
		$staff[] = (int) $id;
	}
	if ( ! $staff ) {
		return $none;
	}
	$in    = implode( ',', $staff );
	$since = gmdate( 'YmdHis', time() - 30 * DAY_IN_SECONDS );
	$n     = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
		"SELECT COUNT(*) FROM {$ct} c
		  WHERE c.board_id = %d AND ( c.status = '' OR c.status IS NULL ) AND c.date >= %s
		    AND c.member_uid NOT IN ({$in})
		    AND NOT EXISTS ( SELECT 1 FROM {$cm} m WHERE m.content_uid = c.uid AND m.member_uid IN ({$in}) )",
		$bid,
		$since
	) );
	return array( 'n' => $n, 'url' => home_url( '/inquiries/' ) );
}

/**
 * 승인 대기 후기 수 (사진이 붙은 것은 승인 순간 1,000원이 나간다).
 */
function reviews_pending(): int {
	if ( ! function_exists( 'get_comments' ) ) {
		return 0;
	}
	$n = get_comments( array( 'type' => 'review', 'status' => 'hold', 'count' => true ) );
	return is_numeric( $n ) ? (int) $n : count( (array) $n );
}

/**
 * 품절 표시 상품 수 · 재고 관리가 켜진 상품 중 5개 이하.
 *
 * @return array{out:int,low:array<int,string>}
 */
function stock(): array {
	$r = array( 'out' => 0, 'low' => array() );
	if ( ! function_exists( 'wc_get_products' ) ) {
		return $r;
	}
	$ids      = (array) wc_get_products( array( 'status' => 'publish', 'stock_status' => 'outofstock', 'limit' => -1, 'return' => 'ids' ) );
	$r['out'] = count( $ids );
	foreach ( (array) wc_get_products( array( 'status' => 'publish', 'manage_stock' => true, 'limit' => 100 ) ) as $p ) {
		if ( ! is_object( $p ) || ! method_exists( $p, 'get_stock_quantity' ) ) {
			continue;
		}
		$q = $p->get_stock_quantity();
		if ( null !== $q && (int) $q <= (int) apply_filters( 'duckhoo_today_low_stock', 5 ) && (int) $q > 0 ) {
			$r['low'][ (int) $p->get_id() ] = (string) $p->get_name() . ' ' . (int) $q . '개';
		}
	}
	return $r;
}

/**
 * 사흘 안에 만료되는데 아직 쓸 수 있는 쿠폰 수.
 */
function coupons_expiring(): int {
	if ( ! function_exists( 'get_posts' ) ) {
		return 0;
	}
	$now  = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$rows = (array) get_posts( array(
		'post_type'   => 'shop_coupon',
		'post_status' => 'publish',
		'numberposts' => 200,
		'fields'      => 'ids',
		'meta_query'  => array( array( 'key' => 'date_expires', 'value' => array( $now, $now + 3 * DAY_IN_SECONDS ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	$n = 0;
	foreach ( $rows as $id ) {
		$limit = (int) get_post_meta( (int) $id, 'usage_limit', true );
		$used  = (int) get_post_meta( (int) $id, 'usage_count', true );
		if ( $limit <= 0 || $used < $limit ) {
			++$n;
		}
	}
	return $n;
}

/**
 * 어제 숫자 — 주문 건수 · 확정 매출 · 새 회원.
 *
 * @return array{orders:int,sales:float,signups:int}
 */
function yesterday(): array {
	$today = (string) current_time( 'Y-m-d' );
	$y     = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
	$r     = array( 'orders' => 0, 'sales' => 0.0, 'signups' => 0, 'day' => $y );
	if ( function_exists( 'wc_get_orders' ) ) {
		$void = function_exists( '\\Duckhoo\\Redesign\\Sales\\void_statuses' ) ? \Duckhoo\Redesign\Sales\void_statuses() : array( 'cancelled', 'refunded', 'failed', 'checkout-draft' );
		$paid = function_exists( '\\Duckhoo\\Redesign\\Sales\\confirmed_statuses' ) ? \Duckhoo\Redesign\Sales\confirmed_statuses() : array( 'payment-confirmed', 'ready-to-ship', 'delivered', 'completed' );
		foreach ( (array) wc_get_orders( array( 'date_created' => $y . '...' . $y, 'limit' => 300, 'status' => 'any', 'type' => 'shop_order' ) ) as $o ) {
			if ( ! is_object( $o ) || ! method_exists( $o, 'get_status' ) ) {
				continue;
			}
			$s = (string) $o->get_status();
			if ( in_array( $s, $void, true ) ) {
				continue;
			}
			++$r['orders'];
			if ( in_array( $s, $paid, true ) ) {
				$r['sales'] += (float) $o->get_total();
			}
		}
	}
	global $wpdb;
	if ( isset( $wpdb ) ) {
		$r['signups'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->users . ' WHERE user_registered >= %s AND user_registered < %s', $y . ' 00:00:00', $today . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB
	}
	return $r;
}

/**
 * 오늘의 사실들 — 화면 · 메일이 같은 것을 본다. 5분 캐시.
 *
 * @param bool $fresh 캐시 무시.
 * @return array<string,mixed>
 */
function facts( bool $fresh = false ): array {
	$key = CACHE . '_' . (string) current_time( 'Y-m-d' );
	if ( ! $fresh ) {
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['built'] ) ) {
			return $hit;
		}
	}
	$now   = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$stale = stale_days();
	$f     = array(
		'built'    => $now,
		'onhold'   => array( 'n' => 0, 'stale' => 0, 'stale_ids' => array() ),
		'check'    => array( 'n' => 0, 'ids' => array() ),
		'sms'      => 0,
		'to_ship'  => array( 'n' => 0, 'ids' => array() ),
		'no_track' => array( 'n' => 0, 'ids' => array() ),
		'stuck'    => array( 'n' => 0, 'ids' => array() ),
		'inq'      => inquiries(),
		'reviews'  => reviews_pending(),
		'stock'    => stock(),
		'coupons'  => coupons_expiring(),
		'yday'     => yesterday(),
		'holiday'  => function_exists( '\\Duckhoo\\Redesign\\Front\\holiday_notice' ) ? (array) \Duckhoo\Redesign\Front\holiday_notice() : array(),
	);

	foreach ( orders( array( 'on-hold', 'pending' ) ) as $r ) {
		++$f['onhold']['n'];
		if ( $r['ts'] > 0 && $now - $r['ts'] > $stale * DAY_IN_SECONDS ) {
			++$f['onhold']['stale'];
			$f['onhold']['stale_ids'][] = $r['id'];
		}
	}
	foreach ( orders( need_check_statuses() ) as $r ) {
		++$f['check']['n'];
		$f['check']['ids'][] = $r['id'];
	}
	if ( function_exists( '\\Duckhoo\\Redesign\\Bank2\\rows' ) ) {
		// 14일치를 세면 이미 손으로 처리한 옛 문자(연결 표시가 안 남는다)까지 매일 같은 숫자로 뜬다 → 오늘 · 어제만
		$f['sms'] = count( \Duckhoo\Redesign\Bank2\rows( (int) apply_filters( 'duckhoo_today_sms_days', 2 ), 200 ) );
	}
	foreach ( orders( array( 'payment-confirmed' ) ) as $r ) {
		++$f['to_ship']['n'];
		$f['to_ship']['ids'][] = $r['id'];
	}
	foreach ( orders( array( 'ready-to-ship', 'shipping' ), 300 ) as $r ) {
		$has = function_exists( '\\Duckhoo\\Redesign\\Tracking\\find' ) ? '' !== (string) ( \Duckhoo\Redesign\Tracking\find( $r['o'] )['no'] ?? '' ) : true;
		$age = $r['ts'] > 0 ? $now - $r['ts'] : 0;
		if ( ! $has && $age <= 14 * DAY_IN_SECONDS ) {
			++$f['no_track']['n'];
			$f['no_track']['ids'][] = $r['id'];
		}
		if ( $age > 14 * DAY_IN_SECONDS ) {
			++$f['stuck']['n'];
			$f['stuck']['ids'][] = $r['id'];
		}
	}
	set_transient( $key, $f, 5 * MINUTE_IN_SECONDS );
	return $f;
}

/* ── 사실 → 할 일 (워드프레스 없이 돌아가는 순수 함수) ─────────────────── */

/**
 * 관리자 주문 목록 주소 (상태로 걸러서).
 *
 * @param string|string[] $status 상태 슬러그.
 */
function orders_url( $status = '' ): string {
	$base = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=wc-orders' ) : '/wp-admin/admin.php?page=wc-orders';
	$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( ! $hpos ) {
		$base = function_exists( 'admin_url' ) ? admin_url( 'edit.php?post_type=shop_order' ) : '/wp-admin/edit.php?post_type=shop_order';
	}
	$status = is_array( $status ) ? (string) reset( $status ) : (string) $status;
	if ( '' === $status ) {
		return $base;
	}
	return $base . ( $hpos ? '&status=wc-' : '&post_status=wc-' ) . rawurlencode( $status );
}

/**
 * 사실에서 할 일 목록을 엮는다. 순수 함수 — 테스트가 이것을 본다.
 *
 * 항목: id · group · title(할 일) · n(건수, 0 이면 없음) · note(한 줄) · url · tone('hot'|'' ) · info(숫자만, 할 일 아님).
 *
 * @param array<string,mixed> $f     facts().
 * @param array<string,mixed> $ctx   today(Y-m-d) · dow(0~6) · hour · stale_days · urls[].
 * @return array<int,array<string,mixed>>
 */
function build( array $f, array $ctx = array() ): array {
	$stale = (int) ( $ctx['stale_days'] ?? 5 );
	$hour  = (int) ( $ctx['hour'] ?? 9 );
	$dow   = (int) ( $ctx['dow'] ?? 1 );
	$u     = (array) ( $ctx['urls'] ?? array() );
	$hol   = (array) ( $f['holiday'] ?? array() );
	$items = array();

	/* 돈 */
	$oh = (array) ( $f['onhold'] ?? array() );
	$items[] = array(
		'id'    => 'stale',
		'group' => '입금',
		'title' => '입금전 ' . $stale . '일 넘은 주문 확인',
		'n'     => (int) ( $oh['stale'] ?? 0 ),
		'note'  => '입금이 안 됐거나 입금자명이 달라 자동확인이 못 걸린 주문입니다. 손님에게 한 번 묻거나 취소합니다.',
		'url'   => (string) ( $u['onhold'] ?? '' ),
		'tone'  => 'hot',
	);
	$items[] = array(
		'id'    => 'check',
		'group' => '입금',
		'title' => '확인필요 주문 처리',
		'n'     => (int) ( $f['check']['n'] ?? 0 ),
		'note'  => '입금 문자는 왔는데 이름 · 금액이 안 맞아 사람이 봐야 하는 주문입니다.',
		'url'   => (string) ( $u['check'] ?? '' ),
		'tone'  => 'hot',
	);
	$items[] = array(
		'id'    => 'sms',
		'group' => '입금',
		'title' => '주문에 못 붙인 입금 문자 (오늘 · 어제)',
		'n'     => (int) ( $f['sms'] ?? 0 ),
		'note'  => '「금고홍길동」처럼 은행 이름이 붙었거나 두 글자 이름일 수 있습니다. 키플 문자 목록에서 주문에 연결합니다.',
		'url'   => (string) ( $u['sms'] ?? '' ),
		'tone'  => '',
	);
	$items[] = array(
		'id'    => 'onhold',
		'group' => '입금',
		'title' => '입금 기다리는 주문',
		'n'     => (int) ( $oh['n'] ?? 0 ),
		'note'  => '입금 문자가 오면 자동으로 넘어갑니다. 여기서 할 일은 없고 숫자만 봅니다.',
		'url'   => (string) ( $u['onhold'] ?? '' ),
		'tone'  => '',
		'info'  => true,
	);

	/* 출고 */
	$ship_note = $hour < 16 ? '오후 4시 전 입금 확인분은 오늘 나갑니다.' : '오후 4시가 지나 내일 출고분입니다.';
	if ( $hol && ! empty( $hol['k'] ) ) {
		$ship_note = (string) $hol['eb'] . ' — ' . (string) $hol['k'] . '.';
	} elseif ( 0 === $dow || 6 === $dow ) {
		$ship_note = '주말 주문은 월요일 오후 4시에 출고합니다.';
	}
	$items[] = array(
		'id'    => 'ship',
		'group' => '출고',
		'title' => '입금확인 → 오늘 보낼 주문',
		'n'     => (int) ( $f['to_ship']['n'] ?? 0 ),
		'note'  => $ship_note . ' 포장 뒤 송장을 넣으면 배송준비중으로 넘어갑니다.',
		'url'   => (string) ( $u['to_ship'] ?? '' ),
		'tone'  => 'hot',
	);
	$items[] = array(
		'id'    => 'track',
		'group' => '출고',
		'title' => '배송준비중인데 송장이 없는 주문',
		'n'     => (int) ( $f['no_track']['n'] ?? 0 ),
		'note'  => '손님 화면에 「배송조회」가 안 뜹니다. 엑셀 송장 등록으로 번호를 넣습니다.',
		'url'   => (string) ( $u['ready'] ?? '' ),
		'tone'  => '',
	);
	$items[] = array(
		'id'    => 'stuck',
		'group' => '출고',
		'title' => '배송준비중에 2주 넘게 머문 주문',
		'n'     => (int) ( $f['stuck']['n'] ?? 0 ),
		'note'  => '이미 보낸 주문이면 배송완료로 넘겨 주세요. 배송완료가 돼야 손님이 후기를 쓰고 1% 적립이 됩니다.',
		'url'   => (string) ( $u['ready'] ?? '' ),
		'tone'  => '',
	);

	/* 손님 */
	$inq = (array) ( $f['inq'] ?? array() );
	if ( (int) ( $inq['n'] ?? -1 ) >= 0 ) {
		$items[] = array(
			'id'    => 'inq',
			'group' => '손님',
			'title' => '답이 없는 1:1 문의 (최근 30일)',
			'n'     => (int) $inq['n'],
			'note'  => '응대 시간 안에 답하면 전화가 줄어듭니다.',
			'url'   => (string) ( $inq['url'] ?? '' ),
			'tone'  => 'hot',
		);
	}
	$items[] = array(
		'id'    => 'reviews',
		'group' => '손님',
		'title' => '승인 기다리는 후기',
		'n'     => (int) ( $f['reviews'] ?? 0 ),
		'note'  => '사진이 진짜 상품 사진인지 보고 승인합니다. 승인하는 순간 사진 후기 1,000원이 자동으로 들어갑니다.',
		'url'   => (string) ( $u['reviews'] ?? '' ),
		'tone'  => '',
	);

	/* 가게 */
	$st = (array) ( $f['stock'] ?? array() );
	$items[] = array(
		'id'    => 'out',
		'group' => '가게',
		'title' => '품절 표시 상품',
		'n'     => (int) ( $st['out'] ?? 0 ),
		'note'  => '입고됐으면 재고 상태를 「재고 있음」으로 돌립니다. 품절인 채로 두면 검색에서 들어온 손님이 그냥 나갑니다.',
		'url'   => (string) ( $u['stock_out'] ?? '' ),
		'tone'  => '',
	);
	$low = (array) ( $st['low'] ?? array() );
	$items[] = array(
		'id'    => 'low',
		'group' => '가게',
		'title' => '재고 5개 이하 상품',
		'n'     => count( $low ),
		'note'  => $low ? implode( ' · ', array_slice( array_values( $low ), 0, 6 ) ) : '',
		'url'   => (string) ( $u['stock_low'] ?? '' ),
		'tone'  => '',
	);
	$items[] = array(
		'id'    => 'coupons',
		'group' => '가게',
		'title' => '사흘 안에 만료되는 쿠폰',
		'n'     => (int) ( $f['coupons'] ?? 0 ),
		'note'  => '아직 안 쓴 손님에게 한 번 더 알리거나 기한을 늘립니다.',
		'url'   => (string) ( $u['coupons'] ?? '' ),
		'tone'  => '',
	);
	if ( 1 === $dow ) {
		$items[] = array(
			'id'    => 'weekly',
			'group' => '가게',
			'title' => '월요일 — 지난주 매출 · 깔때기 한 번 보기',
			'n'     => 1,
			'note'  => '매출 화면의 지난주 · 지난달 같은 기간과 깔때기 두 길을 봅니다. 숫자가 꺾인 자리가 이번 주 할 일입니다.',
			'url'   => (string) ( $u['sales'] ?? '' ),
			'tone'  => '',
		);
	}

	return $items;
}

/**
 * 오늘 「했음」으로 표시한 항목들.
 *
 * @return string[]
 */
function done(): array {
	$v = (array) get_option( OPT_DONE, array() );
	$d = (string) current_time( 'Y-m-d' );
	return array_values( array_map( 'strval', (array) ( $v[ $d ] ?? array() ) ) );
}

/**
 * 「했음」 저장 — 오늘치만 남긴다.
 *
 * @param string[] $ids 항목 id.
 */
function save_done( array $ids ): void {
	$d = (string) current_time( 'Y-m-d' );
	update_option( OPT_DONE, array( $d => array_values( array_unique( array_map( 'sanitize_key', $ids ) ) ) ), false );
}

/**
 * 화면 · 메일이 같이 쓰는 문맥.
 *
 * @return array<string,mixed>
 */
function ctx(): array {
	$ts = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	return array(
		'today'      => gmdate( 'Y-m-d', $ts ),
		'dow'        => (int) gmdate( 'w', $ts ),
		'hour'       => (int) gmdate( 'G', $ts ),
		'stale_days' => stale_days(),
		'urls'       => array(
			'onhold'    => orders_url( 'on-hold' ),
			'check'     => orders_url( need_check_statuses() ?: 'on-hold' ),
			'sms'       => admin_url( 'admin.php?page=' . ( class_exists( 'Keyple_Bank_Admin_Page' ) && defined( 'Keyple_Bank_Admin_Page::SLUG' ) ? (string) constant( 'Keyple_Bank_Admin_Page::SLUG' ) : 'keyple-bank' ) ),
			'to_ship'   => orders_url( 'payment-confirmed' ),
			'ready'     => orders_url( 'ready-to-ship' ),
			'reviews'   => admin_url( 'edit-comments.php?comment_status=moderated&comment_type=review' ),
			'stock_out' => admin_url( 'edit.php?post_type=product&stock_status=outofstock' ),
			'stock_low' => admin_url( 'admin.php?page=wc-reports&tab=stock&report=low_in_stock' ),
			'coupons'   => admin_url( 'edit.php?post_type=shop_coupon' ),
			'sales'     => admin_url( 'admin.php?page=duckhoo-sales' ),
		),
	);
}

/**
 * 오늘 열린(0 이 아니고 안 한) 할 일 수 — 메뉴 배지.
 */
function open_count(): int {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}
	try {
		$done = done();
		$n    = 0;
		foreach ( build( facts(), ctx() ) as $it ) {
			if ( empty( $it['info'] ) && (int) $it['n'] > 0 && ! in_array( (string) $it['id'], $done, true ) ) {
				++$n;
			}
		}
		return $n;
	} catch ( \Throwable $e ) {
		// 배지 하나 때문에 관리자 전체가 죽으면 안 된다. 화면에서 원인을 보여 준다.
		return 0;
	}
}

/* ── 화면 ────────────────────────────────────────────────────────────── */

/**
 * 화면.
 */
function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['dhr_today_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dhr_today_nonce'] ) ), 'dhr_today' ) ) {
		if ( isset( $_POST['dhr_mail_set'] ) ) {
			update_option( OPT_MAIL, empty( $_POST['dhr_mail'] ) ? '0' : '1', false );
		}
		if ( isset( $_POST['dhr_disc_set'] ) ) {
			$u = esc_url_raw( trim( (string) wp_unslash( $_POST['dhr_disc'] ?? '' ) ) );
			if ( '' === $u || discord_ok( $u ) ) {
				update_option( OPT_DISC, $u, false );
			} else {
				add_action( 'admin_notices', function () {
					echo '<div class="notice notice-error"><p>디스코드 웹훅 주소가 아닙니다. <code>https://discord.com/api/webhooks/…</code> 꼴이어야 합니다.</p></div>';
				} );
			}
		}
		if ( isset( $_POST['dhr_disc_test'] ) ) {
			$ok = discord_send( '액상덕후 사이트에서 보내는 시험 메시지입니다. 이 방으로 아침 브리핑과 오늘 할 일이 옵니다.' ) > 0;
			add_action( 'admin_notices', function () use ( $ok ) {
				echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . ( $ok ? '디스코드로 보냈습니다. 방을 확인해 보세요.' : '디스코드로 못 보냈습니다. 웹훅 주소를 다시 확인해 주세요.' ) . '</p></div>';
			} );
		}
		if ( isset( $_POST['dhr_mail_now'] ) ) {
			$sent = send_mail( true );
			add_action( 'admin_notices', function () use ( $sent ) {
				echo '<div class="notice notice-' . ( $sent ? 'success' : 'warning' ) . '"><p>' . ( $sent ? '메일을 보냈습니다.' : '메일을 못 보냈습니다 (보낼 것이 없거나 발송 실패).' ) . '</p></div>';
			} );
		}
		if ( isset( $_POST['dhr_done_set'] ) ) {
			save_done( array_map( 'sanitize_key', (array) ( $_POST['dhr_done'] ?? array() ) ) );
		}
	}
	$fresh = isset( $_GET['dhr_fresh'] ) && check_admin_referer( 'dhr-today-fresh' ); // phpcs:ignore WordPress.Security.NonceVerification
	try {
		$f = facts( $fresh );
	} catch ( \Throwable $e ) {
		echo '<div class="wrap"><h1>오늘 할 일</h1><div class="notice notice-error"><p>숫자를 세다 멈췄습니다: <code>' . esc_html( $e->getMessage() ) . '</code> (' . esc_html( basename( $e->getFile() ) . ':' . $e->getLine() ) . ')</p></div></div>';
		return;
	}
	$c     = ctx();
	$items = build( $f, $c );
	$done  = done();
	$w     = array( '일', '월', '화', '수', '목', '금', '토' )[ $c['dow'] ];

	echo '<div class="wrap dhr-td">';
	styles();
	echo '<h1>오늘 할 일 <span class="dhr-td__d">' . esc_html( gmdate( 'n월 j일', strtotime( $c['today'] ) ) . ' (' . $w . ')' ) . '</span></h1>';

	$open = array_filter( $items, fn( $i ) => empty( $i['info'] ) && (int) $i['n'] > 0 );
	$left = array_filter( $open, fn( $i ) => ! in_array( (string) $i['id'], $done, true ) );
	if ( ! $open ) {
		echo '<p class="dhr-td__lead">오늘은 처리할 것이 없습니다. 입금 문자가 오면 자동으로 넘어가고, 새 주문이 들어오면 이 화면에 뜹니다.</p>';
	} elseif ( ! $left ) {
		echo '<p class="dhr-td__lead">오늘 할 일을 다 했습니다. 수고하셨습니다.</p>';
	} else {
		echo '<p class="dhr-td__lead">할 일 <b>' . count( $left ) . '개</b>가 남았습니다. 위에서부터 급한 순서입니다. 끝낸 것은 체크해 두면 오늘 하루 접힙니다.</p>';
	}

	echo '<form method="post" class="dhr-td__form"><input type="hidden" name="dhr_today_nonce" value="' . esc_attr( wp_create_nonce( 'dhr_today' ) ) . '"><input type="hidden" name="dhr_done_set" value="1">';
	$group = '';
	foreach ( $items as $it ) {
		if ( $it['group'] !== $group ) {
			if ( '' !== $group ) {
				echo '</ul>';
			}
			$group = (string) $it['group'];
			echo '<h2 class="dhr-td__g">' . esc_html( $group ) . '</h2><ul class="dhr-td__list">';
		}
		$n    = (int) $it['n'];
		$id   = (string) $it['id'];
		$info = ! empty( $it['info'] );
		$is_d = in_array( $id, $done, true );
		$cls  = 'dhr-td__it' . ( 0 === $n ? ' is-none' : '' ) . ( $is_d ? ' is-done' : '' ) . ( 'hot' === ( $it['tone'] ?? '' ) && $n > 0 && ! $is_d ? ' is-hot' : '' ) . ( $info ? ' is-info' : '' );
		echo '<li class="' . esc_attr( $cls ) . '">';
		if ( $info || 0 === $n ) {
			echo '<span class="dhr-td__box" aria-hidden="true"></span>';
		} else {
			echo '<label class="dhr-td__box"><input type="checkbox" name="dhr_done[]" value="' . esc_attr( $id ) . '"' . ( $is_d ? ' checked' : '' ) . ' onchange="this.form.submit()"><span class="screen-reader-text">했음</span></label>';
		}
		echo '<div class="dhr-td__body">';
		echo '<div class="dhr-td__t">' . esc_html( (string) $it['title'] ) . ' <b class="dhr-td__n">' . ( 0 === $n ? '없음' : number_format_i18n( $n ) . ( 'weekly' === $id ? '' : '건' ) ) . '</b></div>';
		if ( $n > 0 && '' !== (string) ( $it['note'] ?? '' ) ) {
			echo '<div class="dhr-td__s">' . esc_html( (string) $it['note'] ) . '</div>';
		}
		if ( $n > 0 && '' !== (string) ( $it['url'] ?? '' ) ) {
			echo '<a class="dhr-td__go" href="' . esc_url( (string) $it['url'] ) . '">바로 가기 →</a>';
		}
		echo '</div></li>';
	}
	if ( '' !== $group ) {
		echo '</ul>';
	}
	echo '<noscript><p><button class="button">체크한 것 저장</button></p></noscript>';
	echo '</form>';

	$y = (array) ( $f['yday'] ?? array() );
	echo '<h2 class="dhr-td__g">어제 (' . esc_html( gmdate( 'n월 j일', strtotime( (string) ( $y['day'] ?? $c['today'] ) ) ) ) . ')</h2>';
	echo '<div class="dhr-td__cards">';
	foreach ( array(
		array( '주문', number_format_i18n( (int) ( $y['orders'] ?? 0 ) ) . '건' ),
		array( '확정 매출', number_format_i18n( (int) round( (float) ( $y['sales'] ?? 0 ) ) ) . '원' ),
		array( '새 회원', number_format_i18n( (int) ( $y['signups'] ?? 0 ) ) . '명' ),
	) as $cd ) {
		echo '<div class="dhr-td__card"><div class="dhr-td__cl">' . esc_html( $cd[0] ) . '</div><div class="dhr-td__cv">' . esc_html( $cd[1] ) . '</div></div>';
	}
	echo '</div>';

	$mail_on = mail_on();
	echo '<form method="post" class="dhr-td__mail"><input type="hidden" name="dhr_today_nonce" value="' . esc_attr( wp_create_nonce( 'dhr_today' ) ) . '"><input type="hidden" name="dhr_mail_set" value="1">';
	echo '<label><input type="checkbox" name="dhr_mail" value="1"' . ( $mail_on ? ' checked' : '' ) . ' onchange="this.form.submit()"> 매일 10시 30분에 이 목록을 <b>' . esc_html( mail_to() ) . '</b> 로 보낸다 (할 일이 하나라도 있을 때만)</label> ';
	echo '<button class="button" name="dhr_mail_now" value="1">지금 보내 보기</button>';
	echo '</form>';

	$disc = discord_url();
	echo '<form method="post" class="dhr-td__mail" style="margin-top:12px"><input type="hidden" name="dhr_today_nonce" value="' . esc_attr( wp_create_nonce( 'dhr_today' ) ) . '"><input type="hidden" name="dhr_disc_set" value="1">';
	echo '<b>디스코드로 받기</b> — 디스코드 방의 <b>웹훅 주소</b>를 넣으면 아침 브리핑(11시)과 오늘 할 일(10시 30분)이 그 방으로 옵니다. ';
	echo '만드는 법: 디스코드 방 → 채널 옆 톱니바퀴(채널 편집) → 연동 → 웹훅 → 새 웹훅 → 「웹훅 URL 복사」.<br>';
	echo '<input type="url" name="dhr_disc" value="' . esc_attr( $disc ) . '" placeholder="https://discord.com/api/webhooks/…" style="width:100%;max-width:560px;margin:6px 0"> ';
	echo '<button class="button button-primary">저장</button> ';
	echo '<button class="button" name="dhr_disc_test" value="1"' . ( '' === $disc ? ' disabled' : '' ) . '>시험 메시지 보내기</button>';
	echo '</form>';

	if ( function_exists( '\\Duckhoo\\Redesign\\Brief\\key_box' ) ) {
		\Duckhoo\Redesign\Brief\key_box();
	}

	echo '<p class="dhr-td__foot">숫자는 5분마다 새로 셉니다 · <a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . SLUG . '&dhr_fresh=1' ), 'dhr-today-fresh' ) ) . '">지금 다시 세기</a>';
	echo ' · 이 화면은 읽기만 합니다. 주문 · 회원에 아무것도 쓰지 않고, 「했음」 체크만 오늘 하루 기억합니다.</p>';
	echo '</div>';
}

/**
 * 스타일 — 흰 카드 · 검정 · 번트 오렌지. 반투명 없음.
 */
function styles(): void {
	echo '<style>
.dhr-td{max-width:860px}
.dhr-td h1{font-weight:700;letter-spacing:0}
.dhr-td__d{font-size:15px;color:#6b7280;font-weight:500;margin-left:6px}
.dhr-td__lead{font-size:15px;line-height:1.6;margin:4px 0 18px}
.dhr-td__g{font-size:13px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.02em;margin:22px 0 8px}
.dhr-td__list{margin:0;padding:0;list-style:none;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
.dhr-td__it{display:flex;gap:12px;padding:12px 14px;border-top:1px solid #f0f2f4;align-items:flex-start}
.dhr-td__it:first-child{border-top:0}
.dhr-td__it.is-hot{border-left:4px solid #C2410C;padding-left:10px}
.dhr-td__it.is-none,.dhr-td__it.is-info{color:#8a919c}
.dhr-td__it.is-done .dhr-td__t{text-decoration:line-through;color:#8a919c}
.dhr-td__it.is-done .dhr-td__s,.dhr-td__it.is-done .dhr-td__go{display:none}
.dhr-td__box{width:22px;height:22px;flex:0 0 22px;margin-top:1px}
.dhr-td__box input{width:20px;height:20px;margin:0}
.dhr-td__it.is-none .dhr-td__box,.dhr-td__it.is-info .dhr-td__box{border:2px solid #e5e7eb;border-radius:5px;box-sizing:border-box}
.dhr-td__body{flex:1;min-width:0}
.dhr-td__t{font-size:15px;font-weight:600;color:#111;line-height:1.4;word-break:keep-all}
.dhr-td__it.is-none .dhr-td__t,.dhr-td__it.is-info .dhr-td__t{font-weight:500;color:#6b7280}
.dhr-td__n{font-weight:700;color:#C2410C;margin-left:4px}
.dhr-td__it.is-none .dhr-td__n{color:#8a919c;font-weight:500}
.dhr-td__it.is-info .dhr-td__n{color:#111}
.dhr-td__s{font-size:13px;color:#4b5563;line-height:1.55;margin-top:3px;word-break:keep-all}
.dhr-td__go{display:inline-block;margin-top:6px;font-size:13px;font-weight:600;color:#C2410C;text-decoration:none}
.dhr-td__go:hover{text-decoration:underline}
.dhr-td__cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.dhr-td__card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px}
.dhr-td__cl{font-size:12px;color:#6b7280;font-weight:600}
.dhr-td__cv{font-size:20px;font-weight:700;color:#111;margin-top:2px;white-space:nowrap}
.dhr-td__mail{margin:22px 0 0;padding:12px 14px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;font-size:13px;line-height:1.6}
.dhr-td__mail .button{margin-left:8px;vertical-align:middle}
.dhr-td__foot{font-size:12px;color:#8a919c;margin-top:14px;line-height:1.6}
@media (max-width:600px){.dhr-td__cards{grid-template-columns:1fr 1fr}}
</style>';
}

/* ── 대시보드 위젯 ───────────────────────────────────────────────────── */

/**
 * 워드프레스 대시보드 맨 위에 요약 한 상자.
 */
function widget(): void {
	if ( ! may() ) {
		return;
	}
	wp_add_dashboard_widget( 'dhr_today', '오늘 할 일', __NAMESPACE__ . '\\widget_html', null, null, 'normal', 'high' );
}
add_action( 'wp_dashboard_setup', __NAMESPACE__ . '\\widget' );

/**
 * 위젯 본문 — 열린 항목만.
 */
function widget_html(): void {
	try {
		$done  = done();
		$items = array_filter( build( facts(), ctx() ), fn( $i ) => empty( $i['info'] ) && (int) $i['n'] > 0 && ! in_array( (string) $i['id'], $done, true ) );
	} catch ( \Throwable $e ) {
		echo '<p>숫자를 세다 멈췄습니다: ' . esc_html( $e->getMessage() ) . '</p>';
		return;
	}
	if ( ! $items ) {
		echo '<p>오늘은 처리할 것이 없습니다.</p>';
	} else {
		echo '<ul style="margin:0">';
		foreach ( $items as $it ) {
			echo '<li style="display:flex;gap:8px;align-items:baseline;margin:0 0 6px">';
			echo '<b style="color:#C2410C;min-width:3em;text-align:right">' . number_format_i18n( (int) $it['n'] ) . '</b>';
			echo '<a href="' . esc_url( (string) ( $it['url'] ?: admin_url( 'admin.php?page=' . SLUG ) ) ) . '">' . esc_html( (string) $it['title'] ) . '</a></li>';
		}
		echo '</ul>';
	}
	echo '<p style="margin:10px 0 0"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . SLUG ) ) . '">전체 보기</a></p>';
}

/* ── 디스코드 ────────────────────────────────────────────────────────── */

/**
 * 디스코드 웹훅 주소. 비어 있으면 안 보낸다.
 */
function discord_url(): string {
	return (string) apply_filters( 'duckhoo_discord_webhook', trim( (string) get_option( OPT_DISC, '' ) ) );
}

/**
 * 웹훅 주소가 디스코드 것인가 (다른 곳으로 글이 새지 않게).
 */
function discord_ok( string $url ): bool {
	return (bool) preg_match( '#^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/\d+/[A-Za-z0-9_\-]+$#', $url );
}

/**
 * 디스코드 한 메시지는 2,000자까지 — 줄 단위로 나눈다. 순수 함수.
 *
 * @param string $text 글.
 * @param int    $max  한 조각 최대 글자 수.
 * @return string[]
 */
function discord_chunks( string $text, int $max = 1900 ): array {
	$text = trim( str_replace( "\r\n", "\n", $text ) );
	if ( '' === $text ) {
		return array();
	}
	$out = array();
	$cur = '';
	foreach ( explode( "\n", $text ) as $line ) {
		while ( mb_strlen( $line ) > $max ) {
			if ( '' !== $cur ) {
				$out[] = $cur;
				$cur   = '';
			}
			$out[] = mb_substr( $line, 0, $max );
			$line  = mb_substr( $line, $max );
		}
		$try = '' === $cur ? $line : $cur . "\n" . $line;
		if ( mb_strlen( $try ) > $max ) {
			$out[] = $cur;
			$cur   = $line;
		} else {
			$cur = $try;
		}
	}
	if ( '' !== $cur ) {
		$out[] = $cur;
	}
	return $out;
}

/**
 * 디스코드로 보낸다. 성공한 조각 수를 돌려준다 (0 = 안 보냄 · 실패).
 */
function discord_send( string $text ): int {
	$url = discord_url();
	if ( '' === $url || ! discord_ok( $url ) || ! function_exists( 'wp_remote_post' ) ) {
		return 0;
	}
	$n = 0;
	foreach ( discord_chunks( $text ) as $chunk ) {
		$r = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'content' => $chunk, 'allowed_mentions' => array( 'parse' => array() ) ) ),
		) );
		if ( is_wp_error( $r ) ) {
			break;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code < 200 || $code >= 300 ) {
			break;
		}
		++$n;
	}
	return $n;
}

/* ── 아침 메일 ───────────────────────────────────────────────────────── */

/**
 * 메일을 보낼지.
 */
function mail_on(): bool {
	return (bool) apply_filters( 'duckhoo_today_mail', '0' !== (string) get_option( OPT_MAIL, '1' ) );
}

/**
 * 받는 주소 (필터 `duckhoo_today_mail_to`).
 */
function mail_to(): string {
	return (string) apply_filters( 'duckhoo_today_mail_to', (string) get_option( 'admin_email', '' ) );
}

/**
 * 메일 본문(글자) — 열린 항목 · 어제 숫자.
 *
 * @param array<int,array<string,mixed>> $items build().
 * @param array<string,mixed>            $y     yesterday().
 * @param string                         $link  화면 주소.
 */
function mail_text( array $items, array $y, string $link ): string {
	$lines = array();
	$open  = array_filter( $items, fn( $i ) => empty( $i['info'] ) && (int) $i['n'] > 0 );
	if ( ! $open ) {
		$lines[] = '오늘은 처리할 것이 없습니다.';
	} else {
		$lines[] = '오늘 할 일 ' . count( $open ) . '개 — 위에서부터 급한 순서입니다.';
		$lines[] = '';
		$g = '';
		foreach ( $open as $it ) {
			if ( $it['group'] !== $g ) {
				$g       = (string) $it['group'];
				$lines[] = '[' . $g . ']';
			}
			$lines[] = '· ' . (string) $it['title'] . ' — ' . number_format_i18n( (int) $it['n'] ) . ( 'weekly' === (string) $it['id'] ? '' : '건' );
			if ( '' !== (string) ( $it['note'] ?? '' ) ) {
				$lines[] = '  ' . (string) $it['note'];
			}
		}
	}
	$lines[] = '';
	$lines[] = '어제: 주문 ' . number_format_i18n( (int) ( $y['orders'] ?? 0 ) ) . '건 · 확정 매출 ' . number_format_i18n( (int) round( (float) ( $y['sales'] ?? 0 ) ) ) . '원 · 새 회원 ' . number_format_i18n( (int) ( $y['signups'] ?? 0 ) ) . '명';
	$lines[] = '';
	$lines[] = '체크하며 처리하기: ' . $link;
	return implode( "\n", $lines );
}

/**
 * 보낸다. `$force` 면 할 일이 없어도 보낸다 (「지금 보내 보기」).
 */
function send_mail( bool $force = false ): bool {
	if ( ! $force && ! mail_on() ) {
		return false;
	}
	$to = mail_to();
	if ( '' === $to || ! function_exists( 'wp_mail' ) ) {
		return false;
	}
	$f     = facts( true );
	$c     = ctx();
	$items = build( $f, $c );
	$open  = array_filter( $items, fn( $i ) => empty( $i['info'] ) && (int) $i['n'] > 0 );
	if ( ! $force && ! $open ) {
		return false;
	}
	$w    = array( '일', '월', '화', '수', '목', '금', '토' )[ $c['dow'] ];
	$subj = '[액상덕후] 오늘 할 일 ' . count( $open ) . '개 — ' . gmdate( 'n월 j일', strtotime( $c['today'] ) ) . '(' . $w . ')';
	$body = mail_text( $items, (array) ( $f['yday'] ?? array() ), admin_url( 'admin.php?page=' . SLUG ) );
	$sent = (bool) wp_mail( $to, $subj, $body );
	if ( '' !== discord_url() ) {
		$sent = discord_send( "**" . $subj . "**\n" . $body ) > 0 || $sent;
	}
	return $sent;
}

/**
 * 크론 — 매일 사이트 시간 MAIL_AT. 시각을 바꾸면 이미 잡힌 것을 풀고 다시 잡는다.
 */
function schedule(): void {
	if ( ! function_exists( 'wp_next_scheduled' ) ) {
		return;
	}
	$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Asia/Seoul' );
	$ts = wp_next_scheduled( CRON );
	if ( ! mail_on() ) {
		if ( $ts ) {
			wp_unschedule_event( $ts, CRON );
		}
		return;
	}
	if ( $ts ) {
		$at = ( new \DateTime( '@' . (int) $ts ) )->setTimezone( $tz )->format( 'H:i' );
		if ( $at === MAIL_AT ) {
			return;
		}
		wp_unschedule_event( $ts, CRON ); // 시각이 달라졌다 — 다시 잡는다
	}
	$first = new \DateTime( 'today ' . MAIL_AT, $tz );
	if ( $first->getTimestamp() <= time() ) {
		$first->modify( '+1 day' );
	}
	wp_schedule_event( $first->getTimestamp(), 'daily', CRON );
}
add_action( 'admin_init', __NAMESPACE__ . '\\schedule' );
add_action( CRON, __NAMESPACE__ . '\\send_mail' );
