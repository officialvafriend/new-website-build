<?php
/**
 * 마케팅 → 쿠폰 한 번에 만들기 (관리자).
 *
 * 손님마다 다른 금액의 쿠폰을 문자로 뿌리려면 금액 수만큼 쿠폰을 만들어야 한다.
 * 관리자 화면에서 하나씩 만들면 열 장만 넘어도 일이 된다. 이 화면은 금액 목록을
 * 붙여 넣으면 **코드를 한 번에 만들고 문자에 붙여 넣을 목록까지** 뽑는다.
 *
 * 쿠폰은 **워드커머스 쿠폰**이다 (`WC_Coupon`) — 키플 쿠폰함이 아니라 결제 · 장바구니가
 * 실제로 계산에 쓰는 그것이다. 만드는 길도 관리자에서 손으로 만드는 것과 같다.
 *
 * **주문 · 회원 · 적립금은 건드리지 않는다.** 만드는 것은 눌렀을 때뿐이고,
 * 되돌리기는 「이 묶음 전부 만료 처리」다 (지우지 않는다 — 이미 쓴 쿠폰의 기록이 남아야 한다).
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Coupon\Admin;

defined( 'ABSPATH' ) || exit;

const SLUG  = 'duckhoo-coupons';
const BATCH = '_dhr_batch';
const NOTE  = '_dhr_note';

/** 헷갈리는 글자를 뺀 코드용 글자 (0/O · 1/I/L 없음). */
const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

/**
 * 이 화면을 볼 수 있는가.
 *
 * @return bool
 */
function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * 한 번에 만들 수 있는 최대 장수.
 *
 * @return int
 */
function max_batch(): int {
	return max( 1, (int) apply_filters( 'duckhoo_coupon_batch_max', 300 ) );
}

/**
 * 문자 문구의 기본값.
 *
 * @return string
 */
function default_sms(): string {
	return (string) apply_filters(
		'duckhoo_coupon_sms',
		"[액상덕후] {메모}쿠폰 {금액}원을 보내드립니다.\n쿠폰코드 {코드}\n{만료}까지 쓰실 수 있어요. 장바구니 쿠폰칸에 코드를 넣으면 바로 할인됩니다.\n{주소}"
	);
}

/**
 * 메뉴 — 마케팅 → 쿠폰 옆. 마케팅 메뉴가 없으면 도구로 간다.
 *
 * @return void
 */
function menu(): void {
	if ( ! may() ) {
		return;
	}
	$cap    = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	$parent = isset( $GLOBALS['admin_page_hooks']['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'tools.php';

	add_submenu_page( $parent, '쿠폰 한 번에 만들기', '쿠폰 한 번에 만들기', $cap, SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 99 );

/**
 * 붙여 넣은 금액 목록을 읽는다.
 *
 * 한 줄에 하나. 줄에서 **처음 나오는 숫자**가 금액이고 나머지가 메모다.
 * `@` 가 든 낱말은 이메일로 보아 그 사람만 쓸 수 있게 한다.
 * 끝에 `x5` · `×5` · `*5` 가 붙으면 같은 금액을 그만큼 만든다.
 *
 *   3000
 *   3,000원  홍길동
 *   5000, 9월 단골, hong@example.com
 *   2000 x 20
 *
 * @param string $raw 붙여 넣은 글.
 * @return array{rows:array<int,array{amount:int,note:string,email:string,count:int}>,errors:array<int,string>}
 */
function parse_lines( string $raw ): array {
	$rows   = array();
	$errors = array();
	$min    = (int) apply_filters( 'duckhoo_coupon_min_amount', 100 );
	$max    = (int) apply_filters( 'duckhoo_coupon_max_amount', 1000000 );

	// `\R` 로 나누면 안 된다 — UTF 모드가 아니면 바이트 0x85(NEL)도 줄바꿈으로 보는데
	// 한글 안에 그 바이트가 들어 있다 (「안녕하세요」가 글자 가운데서 쪼개졌다).
	foreach ( preg_split( "/\r\n|\r|\n/", $raw ) ?: array() as $i => $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}

		// 몇 장 만들지 (x5 · ×5 · *5) — 먼저 떼어 낸다. 그래야 금액으로 잘못 읽지 않는다.
		$count = 1;
		if ( preg_match( '/[x×*]\s*(\d{1,4})\s*(장|개)?\s*$/iu', $line, $m ) ) {
			$count = (int) $m[1];
			$line  = trim( (string) preg_replace( '/[x×*]\s*\d{1,4}\s*(장|개)?\s*$/iu', '', $line ) );
		}

		// 이메일 — 있으면 그 사람만 쓸 수 있게.
		$email = '';
		if ( preg_match( '/[^\s,]+@[^\s,]+\.[^\s,]+/', $line, $m ) ) {
			$email = trim( $m[0], " \t,;" );
			$line  = trim( str_replace( $m[0], '', $line ) );
		}

		// 금액 — 처음 나오는 숫자 (자릿점은 허용).
		if ( ! preg_match( '/\d[\d,]*/', $line, $m ) ) {
			$errors[] = sprintf( '%d번째 줄에 금액이 없습니다: %s', $i + 1, $line );
			continue;
		}
		$amount = (int) str_replace( ',', '', $m[0] );
		// 앞뒤의 구두점 · 「원」 을 뗀다. trim() 의 글자 목록은 **바이트 단위**라
		// 한글이 섞이면 글자를 반으로 자른다 — 정규식으로 뗀다.
		$note   = (string) preg_replace( '/^[\s,;·\-]*원?[\s,;·\-]*|[\s,;·\-]+$/u', '', str_replace( $m[0], '', $line ) );

		if ( $amount < $min || $amount > $max ) {
			$errors[] = sprintf( '%d번째 줄의 금액 %s원은 만들지 않았습니다 (%s~%s원만).', $i + 1, number_format( $amount ), number_format( $min ), number_format( $max ) );
			continue;
		}
		if ( $count < 1 || $count > max_batch() ) {
			$errors[] = sprintf( '%d번째 줄의 장수 %d 는 만들지 않았습니다.', $i + 1, $count );
			continue;
		}

		$rows[] = array(
			'amount' => $amount,
			'note'   => $note,
			'email'  => $email,
			'count'  => $count,
		);
	}

	return array( 'rows' => $rows, 'errors' => $errors );
}

/**
 * 만들 장수 전부 (x5 를 펼친 수).
 *
 * @param array<int,array{count:int}> $rows 줄.
 * @return int
 */
function total( array $rows ): int {
	$n = 0;
	foreach ( $rows as $r ) {
		$n += max( 1, (int) ( $r['count'] ?? 1 ) );
	}
	return $n;
}

/**
 * 코드 한 개를 만든다. 겹치는지는 부르는 쪽이 본다.
 *
 * @param string $prefix 앞에 붙일 글자.
 * @param int    $len    뒤에 붙일 글자 수.
 * @return string
 */
function make_code( string $prefix, int $len ): string {
	$len  = max( 4, min( 12, $len ) );
	$out  = '';
	$last = strlen( ALPHABET ) - 1;
	for ( $i = 0; $i < $len; $i++ ) {
		$out .= ALPHABET[ random_int( 0, $last ) ];
	}
	return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '' ) . $out;
}

/**
 * 이미 있는 코드인가.
 *
 * @param string $code 코드.
 * @return bool
 */
function code_taken( string $code ): bool {
	return function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) > 0;
}

/**
 * 만료일은 **그날 밤 12시**까지 쓰게 하려고 하루를 더해 저장한다.
 *
 * 워드커머스는 만료일 00:00 에 끊는다 — 9월 30일을 그대로 넣으면 9월 29일까지만 쓴다.
 *
 * @param string $pick 사장님이 고른 날짜 (Y-m-d).
 * @return string 저장할 날짜 (Y-m-d). 빈 값이면 만료 없음.
 */
function expiry_stored( string $pick ): string {
	$pick = trim( $pick );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pick ) ) {
		return '';
	}
	$ts = strtotime( $pick . ' +1 day' );
	return false === $ts ? '' : gmdate( 'Y-m-d', $ts );
}

/**
 * 날짜를 문자에 쓰는 말로 — `2026-09-30` → `9월 30일`.
 *
 * @param string $ymd 날짜.
 * @return string
 */
function kdate( string $ymd ): string {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $ymd ), $m ) ) {
		return trim( $ymd );
	}
	return sprintf( '%d월 %d일', (int) $m[2], (int) $m[3] );
}

/**
 * 문자 문구 한 줄.
 *
 * @param string                                             $tpl 문구 틀.
 * @param array{code:string,amount:int,note:string,expires:string} $row 쿠폰.
 * @return string
 */
function sms( string $tpl, array $row ): string {
	$note = trim( (string) ( $row['note'] ?? '' ) );
	return strtr(
		$tpl,
		array(
			'{코드}'   => (string) ( $row['code'] ?? '' ),
			'{금액}'   => number_format( (int) ( $row['amount'] ?? 0 ) ),
			'{메모}'   => '' === $note ? '' : $note . '님 ',
			'{만료}'   => kdate( (string) ( $row['expires'] ?? '' ) ),
			'{주소}'   => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
		)
	);
}

/**
 * 쿠폰을 실제로 만든다.
 *
 * @param array<int,array{amount:int,note:string,email:string,count:int}> $rows  줄.
 * @param array<string,mixed>                                            $opts  설정.
 * @return array{made:array<int,array<string,mixed>>,errors:array<int,string>,batch:string}
 */
function create( array $rows, array $opts ): array {
	$made   = array();
	$errors = array();
	$batch  = (string) ( $opts['batch'] ?? '' );
	$batch  = '' === $batch ? gmdate( 'ymd-His' ) : $batch;

	if ( ! class_exists( '\\WC_Coupon' ) ) {
		return array( 'made' => array(), 'errors' => array( '워드커머스를 찾을 수 없습니다.' ), 'batch' => $batch );
	}

	$prefix  = (string) ( $opts['prefix'] ?? 'DH' );
	$len     = (int) ( $opts['len'] ?? 6 );
	$expires = expiry_stored( (string) ( $opts['expires'] ?? '' ) );
	$cap     = max_batch();
	$n       = 0;

	foreach ( $rows as $r ) {
		for ( $k = 0; $k < max( 1, (int) $r['count'] ); $k++ ) {
			if ( $n >= $cap ) {
				$errors[] = sprintf( '한 번에 %d장까지만 만듭니다. 나머지는 만들지 않았습니다.', $cap );
				break 2;
			}

			// 겹치지 않는 코드를 찾는다.
			$code = '';
			for ( $try = 0; $try < 50; $try++ ) {
				$try_code = make_code( $prefix, $len );
				if ( ! code_taken( $try_code ) ) {
					$code = $try_code;
					break;
				}
			}
			if ( '' === $code ) {
				$errors[] = '코드를 만들지 못했습니다 (겹침). 글자 수를 늘려 주세요.';
				break 2;
			}

			$c = new \WC_Coupon();
			$c->set_code( $code );
			$c->set_discount_type( 'fixed_cart' );
			$c->set_amount( (string) $r['amount'] );
			$c->set_individual_use( ! empty( $opts['individual'] ) );
			/* **쓰는 방식 두 가지.**
			   `many` — 코드 하나를 여럿에게 뿌리고 **한 사람당 한 번**. 손님 수만큼 코드를
			            만들지 않아도 된다 (사장님 2026-09-15: 사람마다 만들면 너무 오래 걸린다).
			   `once` — 한 장에 딱 한 번. 손님마다 다른 코드를 줄 때.
			   워드커머스는 `usage_limit` 0 을 「제한 없음」으로 본다. 어느 쪽이든
			   `usage_limit_per_user` 는 1 이라 한 사람이 두 번 쓰지는 못한다.
			   **한 사람인지는 로그인 계정으로 본다** — 이 가게는 비로그인 결제가 안 되므로 샐 구멍이 없다. */
			$c->set_usage_limit( 'once' === (string) ( $opts['mode'] ?? 'many' ) ? 1 : 0 );
			$c->set_usage_limit_per_user( 1 );
			$c->set_exclude_sale_items( ! empty( $opts['exclude_sale'] ) );
			$c->set_free_shipping( false );

			if ( '' !== $expires ) {
				$c->set_date_expires( $expires );
			}
			if ( (int) ( $opts['min'] ?? 0 ) > 0 ) {
				$c->set_minimum_amount( (string) (int) $opts['min'] );
			}
			if ( '' !== (string) $r['email'] && is_email( (string) $r['email'] ) ) {
				$c->set_email_restrictions( array( (string) $r['email'] ) );
			}

			$desc = trim( sprintf( '%s %s', (string) ( $opts['label'] ?? '' ), (string) $r['note'] ) );
			$c->set_description( '' === $desc ? '쿠폰 한 번에 만들기' : $desc );

			$c->update_meta_data( BATCH, $batch );
			$c->update_meta_data( NOTE, (string) $r['note'] );
			$c->save();

			$made[] = array(
				'code'    => $code,
				'amount'  => (int) $r['amount'],
				'note'    => (string) $r['note'],
				'email'   => (string) $r['email'],
				'expires' => (string) ( $opts['expires'] ?? '' ),
			);
			++$n;
		}
	}

	return array( 'made' => $made, 'errors' => $errors, 'batch' => $batch );
}

/**
 * 만든 묶음들 — 몇 장 만들어 몇 장이 쓰였는지.
 *
 * @return array<int,object>
 */
function batches(): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return array();
	}
	return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT pm.meta_value AS batch, COUNT(*) AS n,
			        SUM( CASE WHEN CAST( COALESCE( uc.meta_value, '0' ) AS UNSIGNED ) > 0 THEN 1 ELSE 0 END ) AS used,
			        MIN( p.post_date ) AS made
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_coupon' AND p.post_status = 'publish'
			 LEFT JOIN {$wpdb->postmeta} uc ON uc.post_id = p.ID AND uc.meta_key = 'usage_count'
			 WHERE pm.meta_key = %s
			 GROUP BY pm.meta_value ORDER BY made DESC LIMIT 40",
			BATCH
		)
	);
}

/**
 * 한 묶음의 쿠폰들.
 *
 * @param string $batch 묶음 이름.
 * @return array<int,\WC_Coupon>
 */
function batch_coupons( string $batch ): array {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! class_exists( '\\WC_Coupon' ) ) {
		return array();
	}
	$ids = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s
			 WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish'
			 ORDER BY p.ID ASC LIMIT %d",
			BATCH,
			$batch,
			max_batch()
		)
	);
	$out = array();
	foreach ( $ids as $id ) {
		$out[] = new \WC_Coupon( (int) $id );
	}
	return $out;
}

/**
 * 묶음을 끝낸다 — **지우지 않고** 만료일을 어제로 돌린다.
 *
 * 이미 쓴 쿠폰의 기록이 주문에 남아 있어야 한다.
 *
 * @param string $batch 묶음 이름.
 * @return int 손댄 장수.
 */
function expire_batch( string $batch ): int {
	$n   = 0;
	$day = gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) - DAY_IN_SECONDS );
	foreach ( batch_coupons( $batch ) as $c ) {
		if ( (int) $c->get_usage_count() > 0 ) {
			continue; // 이미 쓴 것은 그대로 둔다.
		}
		$c->set_date_expires( $day );
		$c->save();
		++$n;
	}
	return $n;
}

/**
 * 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}

	$raw     = (string) wp_unslash( $_POST['dhr_list'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$tpl     = (string) wp_unslash( $_POST['dhr_sms'] ?? '' );  // phpcs:ignore WordPress.Security.NonceVerification
	$tpl     = '' === trim( $tpl ) ? default_sms() : $tpl;
	$opts    = array(
		'prefix'       => (string) wp_unslash( $_POST['dhr_prefix'] ?? 'DH' ),       // phpcs:ignore WordPress.Security.NonceVerification
		'len'          => (int) ( $_POST['dhr_len'] ?? 6 ),                          // phpcs:ignore WordPress.Security.NonceVerification
		'expires'      => (string) wp_unslash( $_POST['dhr_exp'] ?? '' ),            // phpcs:ignore WordPress.Security.NonceVerification
		'min'          => (int) str_replace( ',', '', (string) wp_unslash( $_POST['dhr_min'] ?? '0' ) ), // phpcs:ignore WordPress.Security.NonceVerification
		'label'        => (string) wp_unslash( $_POST['dhr_label'] ?? '' ),          // phpcs:ignore WordPress.Security.NonceVerification
		'mode'         => 'once' === sanitize_key( (string) wp_unslash( $_POST['dhr_mode'] ?? '' ) ) ? 'once' : 'many', // phpcs:ignore WordPress.Security.NonceVerification
		'individual'   => isset( $_POST['dhr_individual'] ),                          // phpcs:ignore WordPress.Security.NonceVerification
		'exclude_sale' => isset( $_POST['dhr_exclude_sale'] ),                        // phpcs:ignore WordPress.Security.NonceVerification
	);
	if ( '' === trim( (string) $opts['expires'] ) ) {
		$opts['expires'] = gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) + 30 * DAY_IN_SECONDS );
	}
	$first = '' === trim( $raw ) && ! isset( $_POST['dhr_do'] ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( $first ) {
		$opts['individual'] = true;
	}

	$said   = '';
	$do     = isset( $_POST['dhr_do'] ) ? sanitize_key( wp_unslash( $_POST['dhr_do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$parsed = parse_lines( $raw );
	$result = null;

	if ( in_array( $do, array( 'preview', 'make', 'show', 'expire' ), true ) ) {
		check_admin_referer( 'dhr-coupons' );
	}

	if ( 'make' === $do && $parsed['rows'] ) {
		$result = create( $parsed['rows'], $opts );
	} elseif ( 'show' === $do || 'expire' === $do ) {
		$batch = sanitize_text_field( (string) wp_unslash( $_POST['dhr_batch'] ?? '' ) );
		if ( 'expire' === $do && '' !== $batch ) {
			$n     = expire_batch( $batch );
			$said  = sprintf( '<b>%s</b> 묶음에서 아직 안 쓴 <b>%d장</b>을 만료 처리했습니다. 이미 쓴 쿠폰은 그대로 뒀습니다.', esc_html( $batch ), (int) $n );
		}
		$made = array();
		foreach ( batch_coupons( $batch ) as $c ) {
			$exp    = $c->get_date_expires();
			$made[] = array(
				'code'    => strtoupper( (string) $c->get_code() ),
				'amount'  => (int) $c->get_amount(),
				'note'    => (string) $c->get_meta( NOTE ),
				'email'   => implode( ' ', (array) $c->get_email_restrictions() ),
				'expires' => $exp ? gmdate( 'Y-m-d', $exp->getTimestamp() - DAY_IN_SECONDS ) : '',
				'used'    => (int) $c->get_usage_count(),
			);
		}
		$result = array( 'made' => $made, 'errors' => array(), 'batch' => $batch );
	}

	echo '<div class="wrap"><h1>쿠폰 한 번에 만들기</h1>';
	echo '<p>금액 목록을 붙여 넣으면 <b>워드커머스 쿠폰</b>을 그만큼 만들고, 문자에 붙여 넣을 목록까지 뽑습니다. '
		. '손님은 <b>장바구니 쿠폰칸</b>에 코드를 넣으면 바로 할인됩니다. 코드는 대소문자를 가리지 않습니다.</p>';

	if ( '' !== $said ) {
		printf( '<div class="notice notice-success"><p>%s</p></div>', wp_kses_post( $said ) );
	}

	if ( 'yes' !== get_option( 'woocommerce_enable_coupons', 'yes' ) ) {
		echo '<div class="notice notice-error"><p><b>쿠폰 사용이 꺼져 있습니다.</b> '
			. '상점설정 → 설정 → 일반 → <b>쿠폰 사용 활성화</b>를 켜야 손님이 코드를 넣을 수 있습니다.</p></div>';
	}

	// ── 결과 ────────────────────────────────────────────────────────────
	if ( $result && ! empty( $result['made'] ) ) {
		$lines = array();
		$rec   = array();
		foreach ( $result['made'] as $m ) {
			$lines[] = sms( $tpl, $m );
			$rec[]   = sprintf( '%s,%d,%s,%s', $m['code'], (int) $m['amount'], (string) $m['note'], (string) $m['email'] );
		}

		printf(
			'<div class="notice notice-success"><p><b>%d장</b> — 묶음 <code>%s</code> · %s</p></div>',
			count( $result['made'] ),
			esc_html( (string) $result['batch'] ),
			'once' === (string) $opts['mode'] ? '한 장에 한 번만' : '여러 사람이 같은 코드 · 한 사람당 한 번'
		);

		echo '<table class="widefat striped" style="max-width:900px;margin:1em 0"><thead><tr>'
			. '<th>코드</th><th>금액</th><th>메모</th><th>이 사람만</th><th>만료</th><th>쓴 횟수</th></tr></thead><tbody>';
		foreach ( $result['made'] as $m ) {
			printf(
				'<tr><td><code style="font-size:14px;font-weight:700">%s</code></td><td>%s원</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $m['code'] ),
				esc_html( number_format( (int) $m['amount'] ) ),
				esc_html( (string) $m['note'] ),
				esc_html( (string) ( $m['email'] ?? '' ) ),
				esc_html( (string) $m['expires'] ),
				isset( $m['used'] ) ? ( (int) $m['used'] > 0 ? '<b style="color:#B42318">사용됨</b>' : '—' ) : '—'
			);
		}
		echo '</tbody></table>';

		printf(
			'<h2>문자에 붙여 넣을 목록</h2><p class="description">한 사람에 한 칸입니다. 빈 줄로 나뉩니다.</p>'
			. '<textarea readonly rows="10" style="width:100%%;max-width:900px;font-family:monospace" onclick="this.select()">%s</textarea>',
			esc_textarea( implode( "\n\n", $lines ) )
		);
		printf(
			'<h2>기록용 (코드,금액,메모,이메일)</h2>'
			. '<textarea readonly rows="6" style="width:100%%;max-width:900px;font-family:monospace" onclick="this.select()">%s</textarea>',
			esc_textarea( implode( "\n", $rec ) )
		);
	}

	if ( $result && ! empty( $result['errors'] ) ) {
		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( implode( ' / ', $result['errors'] ) ) );
	}

	// ── 폼 ──────────────────────────────────────────────────────────────
	echo '<hr><form method="post">';
	wp_nonce_field( 'dhr-coupons' );

	echo '<h2>1. 금액 목록</h2>';
	echo '<p class="description">한 줄에 한 장. 줄에서 <b>처음 나오는 숫자가 금액</b>이고 나머지는 메모입니다. '
		. '<code>@</code> 가 든 낱말은 이메일로 보아 <b>그 계정만</b> 쓸 수 있게 합니다. '
		. '끝에 <code>x5</code> 를 붙이면 같은 금액을 다섯 장 만듭니다.</p>';
	printf(
		'<textarea name="dhr_list" rows="10" style="width:100%%;max-width:900px;font-family:monospace" placeholder="%s">%s</textarea>',
		esc_attr( "3000\n5,000원 홍길동\n10000, 9월 단골, hong@example.com\n2000 x 20" ),
		esc_textarea( $raw )
	);

	echo '<h2>2. 설정</h2><table class="form-table" style="max-width:900px"><tbody>';
	printf(
		'<tr><th scope="row"><label for="dhr_prefix">코드 앞글자</label></th><td>'
		. '<input type="text" id="dhr_prefix" name="dhr_prefix" value="%s" size="6" maxlength="6"> + 무작위 '
		. '<input type="number" id="dhr_len" name="dhr_len" value="%d" min="4" max="12" style="width:70px"> 글자'
		. '<p class="description">헷갈리는 글자(0 · O · 1 · I · L)는 쓰지 않습니다.</p></td></tr>',
		esc_attr( (string) $opts['prefix'] ),
		(int) $opts['len']
	);
	printf(
		'<tr><th scope="row"><label for="dhr_exp">만료일</label></th><td>'
		. '<input type="date" id="dhr_exp" name="dhr_exp" value="%s"> <span class="description">그날 밤 12시까지 쓸 수 있습니다.</span></td></tr>',
		esc_attr( (string) $opts['expires'] )
	);
	printf(
		'<tr><th scope="row"><label for="dhr_min">최소 주문금액</label></th><td>'
		. '<input type="number" id="dhr_min" name="dhr_min" value="%d" min="0" step="1000" style="width:140px"> 원'
		. '<p class="description">0 이면 제한 없음.</p></td></tr>',
		(int) $opts['min']
	);
	printf(
		'<tr><th scope="row"><label for="dhr_label">묶음 메모</label></th><td>'
		. '<input type="text" id="dhr_label" name="dhr_label" value="%s" class="regular-text" placeholder="9월 단골 감사">'
		. '<p class="description">쿠폰 설명에 들어갑니다. 손님에게는 안 보입니다.</p></td></tr>',
		esc_attr( (string) $opts['label'] )
	);
	printf(
		'<tr><th scope="row">쓰는 방식</th><td>'
		. '<label style="display:block;margin-bottom:6px"><input type="radio" name="dhr_mode" value="many" %s> '
		. '<b>여러 사람이 같은 코드</b> · 한 사람당 한 번 <span class="description">— 코드 하나를 문자로 뿌립니다. 손님 수만큼 만들 필요가 없습니다.</span></label>'
		. '<label style="display:block"><input type="radio" name="dhr_mode" value="once" %s> '
		. '<b>한 장에 한 번만</b> <span class="description">— 손님마다 다른 코드를 줄 때.</span></label>'
		. '<p class="description">어느 쪽이든 <b>한 사람이 두 번은 못 씁니다</b> (로그인 계정으로 봅니다).</p></td></tr>',
		checked( 'once' !== (string) $opts['mode'], true, false ),
		checked( 'once' === (string) $opts['mode'], true, false )
	);
	printf(
		'<tr><th scope="row">그 밖에</th><td>'
		. '<label><input type="checkbox" name="dhr_individual" %s> 다른 쿠폰과 같이 못 쓰게 (한 번에 하나)</label><br>'
		. '<label><input type="checkbox" name="dhr_exclude_sale" %s> 할인 중인 상품에는 안 되게</label></td></tr>',
		checked( (bool) $opts['individual'], true, false ),
		checked( (bool) $opts['exclude_sale'], true, false )
	);
	echo '</tbody></table>';

	echo '<h2>3. 문자 문구</h2>';
	echo '<p class="description">쓸 수 있는 자리: <code>{코드}</code> <code>{금액}</code> <code>{메모}</code> <code>{만료}</code> <code>{주소}</code></p>';
	printf(
		'<textarea name="dhr_sms" rows="5" style="width:100%%;max-width:900px">%s</textarea>',
		esc_textarea( $tpl )
	);

	$n = total( $parsed['rows'] );
	// 숨은 칸이 **버튼보다 먼저** 와야 한다. 같은 이름이 두 번 오면 나중 값이 이긴다 —
	// 뒤에 두면 「만들기」를 눌러도 preview 로 읽힌다.
	echo '<p style="margin-top:1.2em"><input type="hidden" name="dhr_do" value="preview">';
	submit_button( '미리 보기', 'secondary', 'dhr_do_preview', false );
	echo ' ';
	printf(
		'<button type="submit" name="dhr_do" value="make" class="button button-primary"%s>%s</button>',
		$n > 0 ? '' : ' disabled',
		$n > 0 ? esc_html( sprintf( '쿠폰 %d장 만들기', $n ) ) : '쿠폰 만들기'
	);
	echo '</p>';

	if ( $parsed['errors'] ) {
		printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( implode( ' · ', $parsed['errors'] ) ) );
	}
	if ( $parsed['rows'] && 'make' !== $do ) {
		printf( '<p><b>%d장</b>을 만듭니다.</p><table class="widefat striped" style="max-width:700px"><thead><tr><th>금액</th><th>메모</th><th>이 사람만</th><th>장수</th></tr></thead><tbody>', (int) $n );
		foreach ( $parsed['rows'] as $r ) {
			printf(
				'<tr><td>%s원</td><td>%s</td><td>%s</td><td>%d</td></tr>',
				esc_html( number_format( (int) $r['amount'] ) ),
				esc_html( (string) $r['note'] ),
				esc_html( (string) $r['email'] ),
				(int) $r['count']
			);
		}
		echo '</tbody></table>';
	}
	echo '</form>';

	// ── 만든 묶음 ────────────────────────────────────────────────────────
	$list = batches();
	if ( $list ) {
		echo '<hr><h2>만든 묶음</h2><table class="widefat striped" style="max-width:900px"><thead><tr>'
			. '<th>묶음</th><th>만든 날</th><th>장수</th><th>쓴 장수</th><th></th></tr></thead><tbody>';
		foreach ( $list as $b ) {
			echo '<tr>';
			printf( '<td><code>%s</code></td>', esc_html( (string) $b->batch ) );
			printf( '<td>%s</td>', esc_html( substr( (string) $b->made, 0, 16 ) ) );
			printf( '<td>%d</td>', (int) $b->n );
			printf( '<td>%d</td>', (int) $b->used );
			echo '<td><form method="post" style="display:inline">';
			wp_nonce_field( 'dhr-coupons' );
			printf( '<input type="hidden" name="dhr_batch" value="%s">', esc_attr( (string) $b->batch ) );
			echo '<button type="submit" name="dhr_do" value="show" class="button button-small">목록 다시 보기</button> ';
			echo '<button type="submit" name="dhr_do" value="expire" class="button button-small" '
				. 'onclick="return confirm(\'아직 안 쓴 쿠폰을 모두 만료 처리합니다. 이미 쓴 쿠폰은 그대로 둡니다.\')">전부 만료</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}

	echo '</div>';
}
