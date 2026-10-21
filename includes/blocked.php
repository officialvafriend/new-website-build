<?php
/**
 * 팔지 않는 맛 — 어느 길로도 못 사게 한다 (2026-10-19).
 *
 * 사장님: 「그린펀치는 일단 아무도 구매 못하게」. 낱병(#245)은 비공개로 내렸지만 **노보 10+1 묶음의 맛 옵션은 재고를
 * 따로 센다** (PPOM 옵션 재고) — 낱병에 품절을 걸어도 묶음 안의 「노보 그린펀치」는 계속 골라졌다. 이미 묶음에 담아 둔
 * 장바구니도 남아 있을 수 있다.
 *
 * 여기서는 막은 낱말을 **상품 이름 · 손님이 고른 빌더 줄(`label`) · 고른 PPOM 칸 값**에서 찾아 막는다.
 * 담기 · 장바구니 · 결제 직전 · 낱병의 구매 가능 여부 네 곳. **워드커머스가 내준 훅만 쓴다** — 폼 필드 · 담기는
 * 데이터 · 테마 · PPOM 설정은 그대로다. 화면에서는 front.js 가 그 맛 칸을 감춘다 (`window.DHR.blocked`).
 *
 * - 낱말 목록: 필터 `duckhoo_blocked_items` (기본 `그린펀치`). 빈칸은 무시하고 견준다
 * - 다시 팔려면: add_filter( 'duckhoo_blocked_items', '__return_empty_array' );
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Blocked;

defined( 'ABSPATH' ) || exit;

/**
 * 막은 낱말들 (빈칸 뺀 꼴).
 *
 * @return string[]
 */
function words(): array {
	$out = array();
	foreach ( (array) apply_filters( 'duckhoo_blocked_items', array( '그린펀치' ) ) as $w ) {
		$w = squeeze( (string) $w );
		if ( '' !== $w ) {
			$out[] = $w;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * 빈칸을 지운다 — 「노보 그린 펀치」처럼 띄어 적어도 걸리게.
 *
 * @param string $s 글.
 * @return string
 */
function squeeze( string $s ): string {
	return (string) preg_replace( '/[\s\x{00A0}\x{3000}]+/u', '', $s );
}

/**
 * 글에 막은 낱말이 들었으면 그 낱말, 아니면 ''. 순수 함수.
 *
 * @param string   $text  글.
 * @param string[] $words 낱말들 (squeeze 된 꼴).
 * @return string
 */
function hit_text( string $text, array $words ): string {
	$t = squeeze( $text );
	if ( '' === $t ) {
		return '';
	}
	foreach ( $words as $w ) {
		if ( '' !== $w && false !== mb_strpos( $t, $w ) ) {
			return $w;
		}
	}
	return '';
}

/**
 * 배열 · JSON 안의 글을 다 훑는다. 순수 함수.
 *
 * @param mixed    $v     값.
 * @param string[] $words 낱말들.
 * @param int      $depth 깊이 (끝없이 내려가지 않게).
 * @return string
 */
function hit_any( $v, array $words, int $depth = 0 ): string {
	if ( $depth > 6 || ! $words ) {
		return '';
	}
	if ( is_string( $v ) ) {
		$h = hit_text( $v, $words );
		if ( '' !== $h ) {
			return $h;
		}
		$t = ltrim( $v );
		if ( '' !== $t && ( '[' === $t[0] || '{' === $t[0] ) ) {
			$j = json_decode( function_exists( 'wp_unslash' ) ? wp_unslash( $v ) : $v, true );
			if ( is_array( $j ) ) {
				return hit_any( $j, $words, $depth + 1 );
			}
		}
		return '';
	}
	if ( is_array( $v ) ) {
		foreach ( $v as $x ) {
			$h = hit_any( $x, $words, $depth + 1 );
			if ( '' !== $h ) {
				return $h;
			}
		}
	}
	return '';
}

/**
 * 장바구니 줄 하나가 막은 것을 담고 있나 — 상품 이름 · 빌더 줄 · PPOM 칸. 순수에 가깝다.
 *
 * @param array    $item  장바구니 줄.
 * @param string[] $words 낱말들.
 * @return string 걸린 낱말 또는 ''.
 */
function hit_item( array $item, array $words ): string {
	$p = $item['data'] ?? null;
	if ( is_object( $p ) && method_exists( $p, 'get_name' ) ) {
		$h = hit_text( (string) $p->get_name(), $words );
		if ( '' !== $h ) {
			return $h;
		}
	}
	return hit_picks( $item['wd_option_builder'] ?? null, $item['ppom']['fields'] ?? null, $words );
}

/**
 * 손님이 **고른 것**만 본다 — 빌더 줄의 `label` 과 PPOM 칸(`fields`)의 값. 순수 함수.
 *
 * PPOM 의 다른 칸(`ppom_option_price` 같은 값 표)이나 옵션 목록 전체를 훑으면 그 맛을 안 고른 묶음까지 막힌다.
 *
 * @param mixed    $builder 빌더 줄 (배열 · JSON).
 * @param mixed    $fields  PPOM 칸 값.
 * @param string[] $words   낱말들.
 * @return string
 */
function hit_picks( $builder, $fields, array $words ): string {
	if ( is_string( $builder ) ) {
		$builder = json_decode( function_exists( 'wp_unslash' ) ? wp_unslash( $builder ) : $builder, true );
	}
	foreach ( (array) $builder as $r ) {
		if ( is_array( $r ) && (int) ( $r['qty'] ?? 1 ) > 0 ) {
			$h = hit_text( (string) ( $r['label'] ?? '' ), $words );
			if ( '' !== $h ) {
				return $h;
			}
		}
	}
	return is_array( $fields ) ? hit_any( $fields, $words ) : '';
}

/**
 * 안내 문구.
 *
 * @param string $word 걸린 낱말.
 * @return string
 */
function message( string $word ): string {
	$last = function_exists( 'mb_substr' ) ? mb_substr( $word, -1 ) : '';
	$code = function_exists( 'mb_ord' ) && '' !== $last ? mb_ord( $last ) : 0;
	$josa = ( $code >= 0xAC00 && $code <= 0xD7A3 && 0 === ( $code - 0xAC00 ) % 28 ) ? '는' : '은';   // 받침이 없으면 「는」
	$msg  = sprintf( '%s%s 지금 판매하지 않습니다. 다른 맛으로 바꿔 담아 주세요.', $word, $josa );
	return (string) apply_filters( 'duckhoo_blocked_message', $msg, $word );
}

/**
 * 장바구니에서 걸린 것들 — 걸린 낱말(또는 품절 낱병 이름) => 안내 문구.
 *
 * @param array $cart 장바구니 줄들.
 * @return array<string,string>
 */
function hits( array $cart ): array {
	$words = words();
	$so    = soldout();
	$out   = array();
	foreach ( $cart as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$h = $words ? hit_item( $item, $words ) : '';
		if ( '' !== $h ) {
			$out[ $h ] = message( $h );
			continue;
		}
		$p    = $item['data'] ?? null;
		$name = is_object( $p ) && method_exists( $p, 'get_name' ) ? (string) $p->get_name() : '';
		$s    = soldout_pick( $item['wd_option_builder'] ?? null, $item['ppom']['fields'] ?? null, $name, $so );
		if ( '' !== $s ) {
			$out[ $s ] = soldout_message( $s );
		}
	}
	return $out;
}

/**
 * 낱병 자체는 구매 불가로.
 *
 * @param mixed $ok 여태 판정.
 * @param mixed $p  상품.
 * @return bool
 */
function purchasable( $ok, $p = null ): bool {
	if ( ! $ok || ! is_object( $p ) || ! method_exists( $p, 'get_name' ) ) {
		return (bool) $ok;
	}
	return '' === hit_text( (string) $p->get_name(), words() );
}

/**
 * 담기 검증 — 상품 이름과 지금 보내는 빌더 줄 · PPOM 칸.
 *
 * @param mixed $passed 여태 판정.
 * @param mixed $pid    상품 번호.
 * @return bool
 */
function validate_add( $passed, $pid = 0 ): bool {
	if ( ! $passed ) {
		return false;
	}
	$words = words();
	$p     = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $pid ) : null;
	$name  = is_object( $p ) ? (string) $p->get_name() : '';
	// phpcs:ignore WordPress.Security.NonceVerification
	$json   = (string) ( $_POST['wd_option_builder_json'] ?? '' );
	// phpcs:ignore WordPress.Security.NonceVerification
	$fields = $_POST['ppom']['fields'] ?? null;
	$msg    = '';
	if ( $words ) {
		$h = '' !== $name ? hit_text( $name, $words ) : '';
		if ( '' === $h ) {
			$h = hit_picks( $json, $fields, $words );
		}
		$msg = '' !== $h ? message( $h ) : '';
	}
	if ( '' === $msg ) {
		$s   = soldout_pick( $json, $fields, $name, soldout() );
		$msg = '' !== $s ? soldout_message( $s ) : '';
	}
	if ( '' === $msg ) {
		return true;
	}
	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice( $msg, 'error' );
	}
	return false;
}

/**
 * 장바구니 · 결제 화면 — 오류로 알리면 워드커머스가 결제를 세운다.
 *
 * @return void
 */
function check_cart(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! function_exists( 'wc_add_notice' ) ) {
		return;
	}
	foreach ( hits( (array) WC()->cart->get_cart() ) as $m ) {
		wc_add_notice( $m, 'error' );
	}
}

/**
 * 마지막 빗장 — 어느 길로 왔든 주문은 여기를 지난다.
 *
 * @return void
 */
function guard_order(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$h = hits( (array) WC()->cart->get_cart() );
	if ( $h ) {
		throw new \Exception( esc_html( (string) reset( $h ) ) );
	}
}

/* ── 품절 낱병의 맛은 묶음에서도 막는다 (2026-10-21) ─────────────────────────────────────────
 *
 * 사장님: 「왜 자꾸 품절 상품이 주문이 되는 거야」. 낱병은 품절이면 워드커머스가 안 담는다. 그런데 **묶음의 맛 칸은
 * 낱병 재고와 이어져 있지 않다** — 화이트아웃 자몽 낱병이 품절인데 「화이트아웃 10병」의 맛 칸에는 「화이트 아웃 자몽」이
 * 그대로 있었다. 빌런처럼 사장님이 칸을 손으로 뺀 곳만 안 팔렸다.
 *
 * 그래서 품절 낱병의 「브랜드 + 맛」을 열쇠로 만들어, 손님이 묶음에서 고른 맛의 열쇠가 같으면 막는다.
 * - 열쇠는 `브랜드|맛`. 맛 = 글에서 그 브랜드가 **마지막으로 나온 뒤**의 글 (「아이스빌런자두」 → 자두, 「화이트 아웃 자몽」 → 자몽)
 * - 고른 글에 브랜드가 없으면 묶음 상품 이름의 브랜드로 본다 (「[액상덕후] 흑염룡 10병」의 「바나나」)
 * - 글에 든 브랜드마다 열쇠를 만든다 — 「노보 블랙멘솔」은 노보|블랙멘솔 · 노보블랙|멘솔 둘 다. 같은 맛 이름이 두 라인에
 *   있어도(노보 블랙멘솔 · 노보 블랙 블랙멘솔) 다른 라인 것은 안 막는다
 * - **값이 붙은 칸(팟 · 기기 「[+12,000원]」)은 보지 않는다** — 이름 꼴이 상품과 달라 잘못 걸린다
 * - 끄기: add_filter( 'duckhoo_soldout_auto', '__return_false' );
 */

/**
 * 견주는 꼴 — 빈칸 · 값 표시 · 규격 괄호 · 별 · BEST 를 뗀다. 순수 함수.
 *
 * @param string $s 글.
 * @return string
 */
function clean( string $s ): string {
	$s = (string) preg_replace( '/\[\s*\+[^\]]*\]/u', '', $s );              // [+12,000원]
	$s = (string) preg_replace( '/\([^)]*\d[^)]*\)/u', '', $s );              // (9.8mg / 30ml) — 「(멘솔 없음)」은 남긴다
	$s = (string) preg_replace( '/[⭐★☆✨!]|BEST/u', '', $s );
	$s = (string) preg_replace( '/[\[\]()]/u', '', squeeze( $s ) );
	$s = (string) preg_replace( '/시리즈|입호흡|폐호흡/u', '', $s );
	return (string) preg_replace( '/액상$/u', '', $s );
}

/**
 * 상품 이름 맨 앞 대괄호 브랜드 (견주는 꼴). 없으면 ''. 순수 함수.
 *
 * @param string $name 상품 이름.
 * @return string
 */
function bracket_brand( string $name ): string {
	return preg_match( '/^\s*\[([^\]]+)\]/u', $name, $m ) ? squeeze( $m[1] ) : '';
}

/**
 * 묶음 · 이벤트 상품인가 — 그런 것은 「품절 맛」 열쇠를 만들지 않는다. 순수 함수.
 *
 * @param string $name 상품 이름.
 * @return bool
 */
function is_bundle_name( string $name ): bool {
	return (bool) preg_match( '/\d+\s*병|\d+\s*\+\s*\d+|묶음|세트|EVENT|이벤트|대량|패키지|기획/iu', $name );
}

/**
 * 글에서 brand 가 마지막으로 나온 뒤의 맛. 순수 함수.
 *
 * @param string $c     clean() 된 글.
 * @param string $brand 브랜드 (견주는 꼴).
 * @return string
 */
function after_brand( string $c, string $brand ): string {
	$i = '' === $brand ? false : mb_strrpos( $c, $brand );
	return false === $i ? $c : mb_substr( $c, $i + mb_strlen( $brand ) );
}

/**
 * 브랜드 이름의 다른 꼴 — 「디오리퀴드」를 묶음 칸에서는 「디오」로 적는다.
 *
 * @param string $b 브랜드 (견주는 꼴).
 * @return string[]
 */
function brand_forms( string $b ): array {
	$forms = array( $b );
	$alias = (array) apply_filters( 'duckhoo_soldout_brand_forms', array( '디오리퀴드' => array( '디오' ), '노보리퀴드' => array( '노보' ), '노보블랙리퀴드' => array( '노보블랙' ) ) );
	foreach ( (array) ( $alias[ $b ] ?? array() ) as $a ) {
		$forms[] = squeeze( (string) $a );
	}
	$s = (string) preg_replace( '/리퀴드$/u', '', $b );
	if ( '' !== $s ) {
		$forms[] = $s;
	}
	return array_values( array_unique( array_filter( $forms ) ) );
}

/**
 * 품절 낱병 이름들 → 열쇠표 (열쇠 => 보여 줄 이름). 순수 함수.
 *
 * @param string[] $names 품절 상품 이름.
 * @return array<string,string>
 */
function soldout_keys( array $names ): array {
	$out = array();
	foreach ( $names as $n ) {
		$n = (string) $n;
		$b = bracket_brand( $n );
		if ( '' === $b || is_bundle_name( $n ) ) {
			continue;
		}
		$c    = clean( (string) preg_replace( '/^\s*\[[^\]]+\]/u', '', $n ) );
		$show = (string) preg_replace( '/^\s*\[([^\]]+)\]\s*/u', '$1 ', $n );
		$show = trim( (string) preg_replace( '/\([^)]*\d[^)]*\)/u', ' ', $show ) );
		$show = trim( (string) preg_replace( '/\s+/u', ' ', $show ) );
		foreach ( brand_forms( $b ) as $f ) {
			$flavor = after_brand( $c, $f );
			if ( mb_strlen( $flavor ) >= 2 ) {
				$out[ $f . '|' . $flavor ] = $show;
			}
		}
	}
	return $out;
}

/**
 * 고른 글 하나의 열쇠들. 순수 함수.
 *
 * @param string   $label  고른 맛 글.
 * @param string   $ctx    묶음 상품 이름 (글에 브랜드가 없을 때 쓴다).
 * @param string[] $brands 아는 브랜드 (견주는 꼴).
 * @return string[]
 */
function pick_keys( string $label, string $ctx, array $brands ): array {
	if ( preg_match( '/\[\s*\+/u', $label ) ) {
		return array();                                                       // 값이 붙은 추가 구매 칸
	}
	$c = clean( $label );
	if ( mb_strlen( $c ) < 2 ) {
		return array();
	}
	$in = array_filter( $brands, fn( $b ) => '' !== $b && false !== mb_strpos( $c, $b ) );
	if ( ! $in ) {
		$cx = clean( $ctx );
		$in = array_filter( $brands, fn( $b ) => '' !== $b && false !== mb_strpos( $cx, $b ) );
	}
	$keys = array();
	foreach ( $in as $b ) {
		$f = after_brand( $c, $b );
		if ( mb_strlen( $f ) >= 2 ) {
			$keys[] = $b . '|' . $f;
		}
	}
	return $keys;
}

/**
 * 고른 것(빌더 줄 · PPOM 칸) 중 품절 낱병의 맛이 있으면 그 이름. 순수 함수.
 *
 * @param mixed  $builder 빌더 줄.
 * @param mixed  $fields  PPOM 칸 값.
 * @param string $ctx     묶음 상품 이름.
 * @param array  $so      ['keys' => soldout_keys(), 'brands' => 브랜드들].
 * @return string
 */
function soldout_pick( $builder, $fields, string $ctx, array $so ): string {
	$keys = (array) ( $so['keys'] ?? array() );
	if ( ! $keys ) {
		return '';
	}
	$labels = array();
	if ( is_string( $builder ) ) {
		$builder = json_decode( function_exists( 'wp_unslash' ) ? wp_unslash( $builder ) : $builder, true );
	}
	foreach ( (array) $builder as $r ) {
		if ( is_array( $r ) && (int) ( $r['qty'] ?? 1 ) > 0 && 'required' !== (string) ( $r['type'] ?? '' ) ) {
			$labels[] = (string) ( $r['label'] ?? '' );
		}
	}
	if ( is_array( $fields ) ) {
		array_walk_recursive( $fields, function ( $v ) use ( &$labels ) {
			if ( is_string( $v ) && '' !== trim( $v ) && '{' !== ltrim( $v )[0] && '[' !== ltrim( $v )[0] ) {
				$labels[] = $v;
			}
		} );
	}
	foreach ( $labels as $l ) {
		foreach ( pick_keys( $l, $ctx, (array) ( $so['brands'] ?? array() ) ) as $k ) {
			if ( isset( $keys[ $k ] ) ) {
				return $keys[ $k ];
			}
		}
	}
	return '';
}

/**
 * 지금 품절인 낱병 열쇠표 + 아는 브랜드 (5분 캐시, 재고가 바뀌면 비운다).
 *
 * @return array{keys: array<string,string>, brands: string[]}
 */
function soldout(): array {
	$mem = $GLOBALS['dhr_soldout_mem'] ?? null;                               // 한 요청 안에서 한 번만 읽는다
	if ( is_array( $mem ) ) {
		return $mem;
	}
	$mem = array( 'keys' => array(), 'brands' => array() );
	$GLOBALS['dhr_soldout_mem'] = $mem;
	if ( ! apply_filters( 'duckhoo_soldout_auto', true ) || ! function_exists( 'wc_get_products' ) ) {
		return $mem;
	}
	$c = function_exists( 'get_transient' ) ? get_transient( 'dhr_soldout_v1' ) : false;
	if ( is_array( $c ) && isset( $c['keys'], $c['brands'] ) ) {
		return $GLOBALS['dhr_soldout_mem'] = $c;
	}
	$out = array();
	$all = array();
	foreach ( (array) wc_get_products( array( 'status' => array( 'publish', 'private' ), 'limit' => -1 ) ) as $p ) {
		if ( ! is_object( $p ) || ! method_exists( $p, 'get_name' ) ) {
			continue;
		}
		$n = (string) $p->get_name();
		$b = bracket_brand( $n );
		if ( '' !== $b ) {
			foreach ( brand_forms( $b ) as $f ) {
				$all[ $f ] = true;
			}
		}
		if ( method_exists( $p, 'is_in_stock' ) && ! $p->is_in_stock() ) {
			$out[] = $n;
		}
	}
	$brands = array_keys( $all );
	usort( $brands, fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
	$mem = array( 'keys' => soldout_keys( $out ), 'brands' => $brands );
	$GLOBALS['dhr_soldout_mem'] = $mem;
	if ( function_exists( 'set_transient' ) ) {
		set_transient( 'dhr_soldout_v1', $mem, 5 * ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 ) );
	}
	return $mem;
}

/**
 * 재고가 바뀌면 열쇠표를 비운다.
 *
 * @return void
 */
function soldout_flush(): void {
	unset( $GLOBALS['dhr_soldout_mem'] );
	if ( function_exists( 'delete_transient' ) ) {
		delete_transient( 'dhr_soldout_v1' );
	}
}

/**
 * 품절 맛 안내.
 *
 * @param string $name 품절 낱병 이름 (「화이트아웃 자몽」).
 * @return string
 */
function soldout_message( string $name ): string {
	return (string) apply_filters( 'duckhoo_soldout_message', sprintf( '%s 맛은 지금 품절입니다. 다른 맛으로 바꿔 담아 주세요.', $name ), $name );
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_is_purchasable', __NAMESPACE__ . '\\purchasable', 20, 2 );
	add_filter( 'woocommerce_add_to_cart_validation', __NAMESPACE__ . '\\validate_add', 26, 2 );
	add_action( 'woocommerce_check_cart_items', __NAMESPACE__ . '\\check_cart' );
	add_action( 'woocommerce_checkout_create_order', __NAMESPACE__ . '\\guard_order', 3 );
	foreach ( array( 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_product_set_stock', 'save_post_product', 'woocommerce_reduce_order_stock' ) as $hk ) {
		add_action( $hk, __NAMESPACE__ . '\\soldout_flush' );
	}
}
