<?php
/**
 * 도구 → 옵션 이름 바꾸기 (관리자).
 *
 * 상품이 바뀌면 **그 상품 하나만 고치면 되는 것이 아니다.** 「팟, 코일 추가」 같은
 * 선택칸은 PPOM 옵션이라 **다른 상품들의 선택 목록 안에도 같은 이름이 들어 있다.**
 * 브이메이트 V4 팟은 상품 1개 + 다른 상품 85곳의 선택칸에 적혀 있었다 (2026-09-16).
 * 관리자에서 하나씩 열면 15군데를 고쳐야 한다 — 여기서 한 번에 한다.
 *
 * 지키는 것:
 *
 * - **읽고 보여 준 뒤에만 쓴다.** 「미리 보기」가 바뀌기 전후를 **글자 그대로** 보여 주고,
 *   「바꾸기」를 눌렀을 때만 저장한다. 못 알아본 자리는 **손대지 않는다**
 * - **주문은 건드리지 않는다.** 주문에 실린 옵션 이름은 주문 항목 표에 따로 있어 여기
 *   손이 닿지 않는다 — 옛 주문은 그때 산 이름 그대로 남아야 맞다
 * - **되돌리기**는 바꾸기 전 원본을 그대로 들고 있다가 통째로 되돌린다
 * - 폼 필드 이름(`name` · `id`)은 건드리지 않는다. 바꾸는 것은 **보이는 이름과 값**뿐이다
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Opt\Admin;

defined( 'ABSPATH' ) || exit;

const SLUG   = 'duckhoo-opts';
const BACKUP = 'duckhoo_opt_rename_backup';

/** 값이 들어 있을 만한 칸 이름들. */
const PRICE_KEYS = array( 'price', 'option_price', 'optionprice', 'cost', 'amount', 'option_cost' );

/**
 * 이 화면을 볼 수 있는가.
 *
 * @return bool
 */
function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * 메뉴.
 *
 * @return void
 */
function menu(): void {
	if ( ! may() ) {
		return;
	}
	add_management_page(
		'옵션 이름 바꾸기',
		'옵션 이름 바꾸기',
		current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 손대면 안 되는 자리인가 — 주문 · 장바구니처럼 기록이 남아야 하는 것.
 *
 * @param string $type 글 종류.
 * @param string $key  칸 이름.
 * @return bool
 */
function off_limits( string $type, string $key ): bool {
	$bad = array( 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription', 'revision' );
	if ( in_array( $type, apply_filters( 'duckhoo_opt_rename_skip_types', $bad ), true ) ) {
		return true;
	}
	return (bool) preg_match( '/^_(order|refund|billing|shipping)_/', $key );
}

/**
 * 한 표에서 그 글자가 든 줄을 찾는다.
 *
 * 표마다 칸 이름이 달라 **어디서 찾았는지를 줄마다 들고 다닌다** — 되돌릴 때
 * 같은 자리에 그대로 써 넣어야 하기 때문이다.
 *
 * @param string $table  표 이름.
 * @param string $idcol  열쇠 칸.
 * @param string $valcol 글자가 든 칸.
 * @param string $old    찾을 글자.
 * @param string $extra  같이 읽을 칸 (SQL 조각, 신뢰된 값만).
 * @param string $like   찾을 본(pattern). 비우면 `$old` 를 그대로 쓴다.
 * @return array<int,array<string,mixed>>
 */
function scan_table( string $table, string $idcol, string $valcol, string $old, string $extra = '', string $like = '' ): array {
	global $wpdb;
	$like = '' !== $like ? $like : '%' . $wpdb->esc_like( $old ) . '%';
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->prepare(
			"SELECT `{$idcol}` AS dhr_id, `{$valcol}` AS dhr_val {$extra} FROM `{$table}` WHERE `{$valcol}` LIKE %s LIMIT 500",
			$like
		),
		ARRAY_A
	);
	$out = array();
	foreach ( (array) $rows as $r ) {
		$raw   = (string) $r['dhr_val'];
		$type  = (string) ( $r['post_type'] ?? '' );
		$key   = (string) ( $r['meta_key'] ?? $valcol );
		$out[] = array(
			'table'  => $table,
			'idcol'  => $idcol,
			'valcol' => $valcol,
			'id'     => (string) $r['dhr_id'],
			'post'   => (int) ( $r['post_id'] ?? 0 ),
			'key'    => $key,
			'type'   => $type,
			'title'  => (string) ( $r['post_title'] ?? '' ),
			'raw'    => $raw,
			'hits'   => substr_count( $raw, $old ),
			'skip'   => off_limits( $type, $key ),
		);
	}
	return $out;
}

/**
 * 이름에 `ppom` 이 든 표들 — 그 플러그인이 옵션을 어디에 두든 찾아내기 위해.
 *
 * **표 이름은 DB 에서 받은 것만 쓴다** (사용자 입력을 넣지 않는다).
 *
 * @return array<int,array{0:string,1:string,2:string}> 표 · 열쇠 칸 · 글자 칸
 */
function extra_tables(): array {
	global $wpdb;
	$out  = array();
	$pat  = apply_filters( 'duckhoo_opt_rename_tables', array( 'ppom', 'nm_ppom', 'product_meta' ) );
	$seen = array();
	foreach ( (array) $pat as $needle ) {
		$like  = '%' . $wpdb->esc_like( (string) $needle ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $names as $t ) {
			$t = (string) $t;
			if ( isset( $seen[ $t ] ) || 0 !== strpos( $t, $wpdb->prefix ) ) {
				continue;
			}
			$seen[ $t ] = true;
			$cols       = $wpdb->get_results( "SHOW COLUMNS FROM `{$t}`", ARRAY_A ); // phpcs:ignore WordPress.DB
			$id         = '';
			$vals       = array();
			foreach ( (array) $cols as $c ) {
				$name = (string) $c['Field'];
				if ( '' === $id && 'PRI' === (string) ( $c['Key'] ?? '' ) ) {
					$id = $name;
				}
				if ( preg_match( '/(text|blob|varchar)/i', (string) $c['Type'] ) ) {
					$vals[] = $name;
				}
			}
			foreach ( $vals as $v ) {
				if ( '' !== $id ) {
					$out[] = array( $t, $id, $v );
				}
			}
		}
	}
	return $out;
}

/**
 * JSON 안에 들어갈 때의 모습 — 한글 한 글자가 `\` + `uBE0C` 여섯 글자로 적혀 있을 수 있다.
 *
 * `wp_json_encode()` 는 기본으로 한글을 이렇게 escape 한다. 그래서 **DB 에는
 * 한글이 한 글자도 없을 수 있고**, 화면에 보이는 글자로 찾으면 0건이 나온다.
 *
 * @param string $s 글자.
 * @return string
 */
function json_bare( string $s ): string {
	/* `wp_json_encode()` 를 쓰지 않는다 — 우리가 원하는 것은 **escape 된 모습** 하나이고,
	   그 함수는 플러그인 설정에 따라 한글을 그대로 둘 수도 있다. */
	$j = json_encode( $s ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return is_string( $j ) && strlen( $j ) > 1 ? substr( $j, 1, -1 ) : $s;
}

/**
 * 같은 이름이 DB 에 적혀 있을 수 있는 모습들 — 찾을 것과 넣을 것을 짝으로.
 *
 * @param string $old 옛 이름.
 * @param string $new 새 이름.
 * @return array<int,array{0:string,1:string}>
 */
function variants( string $old, string $new ): array {
	$out = array( array( $old, $new ) );
	$jo  = json_bare( $old );
	if ( $jo !== $old ) {
		$out[] = array( $jo, json_bare( $new ) );
	}
	return $out;
}

/**
 * 찾아볼 표들 — 글 메타 · 설정 · 이름에 ppom 이 든 표.
 *
 * @return array<int,array{0:string,1:string,2:string,3:string}> 표 · 열쇠 · 글자 칸 · 같이 읽을 칸
 */
function places(): array {
	global $wpdb;
	$out = array(
		array(
			$wpdb->postmeta,
			'meta_id',
			'meta_value',
			", post_id, meta_key, (SELECT post_type FROM {$wpdb->posts} p WHERE p.ID = post_id) AS post_type,"
			. " (SELECT post_title FROM {$wpdb->posts} p2 WHERE p2.ID = post_id) AS post_title",
		),
		array( $wpdb->options, 'option_id', 'option_value', ', option_name AS meta_key' ),
		/* PPOM 은 옵션 목록을 **글 본문**에 넣는다 (`ppom[fields][id]` 가 그 글 번호다).
		   여기를 빼먹어 한 군데도 못 찾은 적이 있다 (2026-09-16). */
		array( $wpdb->posts, 'ID', 'post_content', ', ID AS post_id, post_type, post_title' ),
		array( $wpdb->posts, 'ID', 'post_excerpt', ', ID AS post_id, post_type, post_title' ),
	);
	foreach ( extra_tables() as $t ) {
		$out[] = array( $t[0], $t[1], $t[2], '' );
	}
	return $out;
}

/**
 * 그 글자가 들어 있는 자리를 모두 찾는다 (읽기만 한다).
 *
 * **글자가 적혀 있을 수 있는 모습을 모두** 본다 — 그대로, 그리고 JSON 안에서
 * 한글이 escape 된 꼴 (`json_bare()`). 혹시 한 자리가 두 모습으로 다 걸리면
 * 같은 자리는 한 번만 센다.
 *
 * @param string $old 찾을 글자.
 * @param string $new 새 이름 (모습을 짝 지으려고 받는다 — 여기서 쓰지는 않는다).
 * @return array<int,array<string,mixed>>
 */
function scan( string $old, string $new = '' ): array {
	global $wpdb;
	if ( '' === trim( $old ) ) {
		return array();
	}
	$rows = array();
	$seen = array();

	foreach ( variants( $old, '' === $new ? $old : $new ) as $pair ) {
		$bits = preg_split( '/\s+/u', $pair[0] ) ?: array();

		/* **띄어쓰기를 건너뛰고도 찾는다.** 화면에는 보통 빈칸으로 보이지만 DB 에는
		   줄바꿈이나 다른 종류의 빈칸이 들어 있을 수 있다 — 그러면 그대로 찾아서는
		   한 건도 안 나온다. 찾은 뒤 **그 자리의 진짜 글자**를 다시 떠서 그것을 바꾼다. */
		$like = count( $bits ) > 1
			? '%' . implode( '%', array_map( array( $wpdb, 'esc_like' ), $bits ) ) . '%'
			: '';
		$re   = count( $bits ) > 1
			? '/' . implode( '\s{0,4}', array_map( static fn( $b ) => preg_quote( (string) $b, '/' ), $bits ) ) . '/u'
			: '';

		foreach ( places() as $p ) {
			foreach ( scan_table( $p[0], $p[1], $p[2], $pair[0], $p[3], $like ) as $r ) {
				$k = spot_key( $r );
				if ( isset( $seen[ $k ] ) ) {
					continue;
				}
				$hit = $pair[0];
				if ( 0 === (int) $r['hits'] ) {
					/* 그대로는 없고 빈칸만 다른 경우 — 그 자리의 글자를 그대로 뜬다. */
					if ( '' === $re || ! preg_match( $re, (string) $r['raw'], $m ) ) {
						continue;
					}
					$hit        = (string) $m[0];
					$r['hits']  = substr_count( (string) $r['raw'], $hit );
				}
				$seen[ $k ] = true;
				$r['old']   = $hit;
				$r['new']   = $pair[1];
				$rows[]     = $r;
			}
		}
	}
	return $rows;
}

/**
 * 이름 안에서 다시 훑어볼 **영문 토막**을 고른다.
 *
 * **글자가 든 토막을 먼저 쓴다.** 숫자만 있는 토막(`0.7`)은 버전 번호 같은
 * 엉뚱한 곳에 다 걸린다 — 실제로 `10.7.0` 만 잔뜩 나왔다 (2026-09-16).
 *
 * @param string $old 이름.
 * @return string
 */
function probe_frag( string $old ): string {
	$best = '';
	$rank = static function ( string $s ): int {
		if ( '' === $s ) {
			return -1;
		}
		/* 16진수 글자(0-9 a-f)만 있는 토막은 주문 해시에 다 걸린다 — 실제로
		   `2EA` 로 훑었더니 `_cart_hash` 만 여덟 줄 나왔다 (2026-09-16). */
		$hexy = ! preg_match( '/[G-Zg-z]/', $s );
		$alpha = (bool) preg_match( '/[A-Za-z]/', $s );
		return ( $hexy ? 0 : 2 ) + ( $alpha ? 1 : 0 );
	};
	if ( preg_match_all( '/[A-Za-z0-9.]{2,}/', $old, $m ) ) {
		foreach ( $m[0] as $bit ) {
			$a = $rank( (string) $bit );
			$b = $rank( $best );
			if ( $a > $b || ( $a === $b && strlen( (string) $bit ) > strlen( $best ) ) ) {
				$best = (string) $bit;
			}
		}
	}
	return $best;
}

/**
 * 못 찾았을 때 훑어볼 글자들 — 순서대로 하나씩 시도한다.
 *
 * ①이름에서 **빈칸 없이 가장 긴 토막** (그대로 · escape 된 꼴) — 빈칸이 달라서
 * 못 찾는 경우를 가른다. ②마지막이 영문 토막이다.
 *
 * @param string $old 찾던 이름.
 * @return array<int,string>
 */
function probe_needles( string $old ): array {
	$out  = array();
	$bits = preg_split( '/\s+/u', trim( $old ) ) ?: array();
	/* **한글이 가장 많은 토막**을 고른다. 길이로만 고르면 `0.7옴(2EA)` 이 뽑히는데
	   숫자 · 괄호는 아무 데나 있어 걸러지지 않는다 (2026-09-16). */
	$long = '';
	$best = -1;
	foreach ( $bits as $b ) {
		$n = (int) preg_match_all( '/[가-힣]/u', (string) $b );
		if ( $n > $best || ( $n === $best && mb_strlen( (string) $b ) > mb_strlen( $long ) ) ) {
			$best = $n;
			$long = (string) $b;
		}
	}
	if ( '' !== $long && mb_strlen( $long ) >= 3 ) {
		$out[] = $long;
		$esc    = json_bare( $long );
		if ( $esc !== $long ) {
			$out[] = $esc;
		}
	}
	$frag = probe_frag( $old );
	if ( '' !== $frag ) {
		$out[] = $frag;
	}
	return $out;
}

/**
 * 못 찾았을 때 — 그 영문 토막으로 한 번 더 훑어본다.
 *
 * 영문 · 숫자는 JSON 에서도 그대로라 **어떤 모습으로 적혀 있든 걸린다.**
 * 자동으로 바꾸지는 않는다 — 어디에 사는지 화면에 적어 줄 뿐이다.
 *
 * @param string $old 찾던 이름.
 * @return array{frag:string,rows:array<int,array<string,mixed>>}
 */
function probe( string $old ): array {
	$last = '';
	foreach ( probe_needles( $old ) as $frag ) {
		$last = $frag;
		$rows = array();
		$seen = array();
		foreach ( places() as $p ) {
			foreach ( scan_table( $p[0], $p[1], $p[2], $frag, $p[3] ) as $r ) {
				/* 주문 · 기록은 진단에도 쓸모가 없다 — 해시가 우연히 걸린 것뿐이다. */
				if ( $r['skip'] ) {
					continue;
				}
				$k = spot_key( $r );
				if ( isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = true;
				$r['old']   = $frag;
				$rows[]     = $r;
				if ( count( $rows ) >= 8 ) {
					break 2;
				}
			}
		}
		if ( $rows ) {
			return array(
				'frag' => $frag,
				'rows' => $rows,
			);
		}
	}
	return array(
		'frag' => $last,
		'rows' => isset( $rows ) ? $rows : array(),
	);
}

/**
 * 값이 붙어 있는 자리를 찾아 새 값으로 바꾼다 (글자 그대로인 경우).
 *
 * `이름|8000` · `"option":"이름","price":"8000"` 두 모양을 본다.
 * **못 찾으면 그냥 둔다** — 모를 때는 건드리지 않는 쪽.
 *
 * @param string $s     글자.
 * @param string $label 새 이름.
 * @param int    $price 새 값.
 * @param int    $n     바꾼 수 (참조).
 * @return string
 */
function retag_price( string $s, string $label, int $price, int &$n ): string {
	$q = preg_quote( $label, '/' );

	/* `이름|8000` · `이름,8000` */
	$s = (string) preg_replace_callback(
		'/(' . $q . '\s*[|,]\s*)(\d+)/u',
		static function ( $m ) use ( $price, &$n ) {
			++$n;
			return $m[1] . $price;
		},
		$s
	);

	/* `"이름" … "price":"8000"` — 이름 뒤 160자 안에 있는 값만 본다. */
	$keys = implode( '|', array_map( static fn( $k ) => preg_quote( $k, '/' ), PRICE_KEYS ) );
	$s    = (string) preg_replace_callback(
		'/(' . $q . '"(?:[^"]|"(?!(?:' . $keys . ')"))*?"(?:' . $keys . ')"\s*:\s*"?)(\d+)/u',
		static function ( $m ) use ( $price, &$n ) {
			++$n;
			return $m[1] . $price;
		},
		$s
	);
	return $s;
}

/**
 * 배열 · 객체 안을 걸어 다니며 이름과 값을 바꾼다.
 *
 * 이름이 **여러 모습으로** 적혀 있을 수 있어 짝(찾을 것 → 넣을 것)을 받는다.
 *
 * @param mixed  $node  마디.
 * @param array  $pairs 짝들.
 * @param int    $price 새 값 (음수면 값은 건드리지 않는다).
 * @param array  $stat  센 것 (참조).
 * @return mixed
 */
function walk( $node, array $pairs, int $price, array &$stat ) {
	if ( is_string( $node ) ) {
		foreach ( $pairs as $p ) {
			if ( false !== strpos( $node, $p[0] ) ) {
				$stat['name'] += substr_count( $node, $p[0] );
				$node          = str_replace( $p[0], $p[1], $node );
				if ( $price >= 0 ) {
					$node = retag_price( $node, $p[1], $price, $stat['price'] );
				}
			}
		}
		return $node;
	}

	if ( is_array( $node ) || is_object( $node ) ) {
		$arr  = is_object( $node ) ? get_object_vars( $node ) : $node;
		$mine = false;
		foreach ( $arr as $v ) {
			foreach ( $pairs as $p ) {
				if ( is_string( $v ) && false !== strpos( $v, $p[0] ) ) {
					$mine = true;
				}
			}
		}
		foreach ( $arr as $k => $v ) {
			$arr[ $k ] = walk( $v, $pairs, $price, $stat );
		}
		/* 이 줄이 그 옵션이면, 같은 줄의 값 칸을 새 값으로. */
		if ( $mine && $price >= 0 ) {
			foreach ( PRICE_KEYS as $pk ) {
				if ( array_key_exists( $pk, $arr ) && is_scalar( $arr[ $pk ] ) && '' !== (string) $arr[ $pk ] ) {
					if ( (string) $arr[ $pk ] !== (string) $price ) {
						++$stat['price'];
					}
					$arr[ $pk ] = is_int( $arr[ $pk ] ) ? $price : (string) $price;
				}
			}
		}
		if ( is_object( $node ) ) {
			foreach ( $arr as $k => $v ) {
				$node->$k = $v;
			}
			return $node;
		}
		return $arr;
	}

	return $node;
}

/**
 * 한 자리의 원본을 바꾼 결과를 만든다 — **저장하지는 않는다.**
 *
 * 묶어 놓은 값(serialize)은 길이 숫자가 딸려 있어 글자만 바꾸면 깨진다 →
 * 풀어서 바꾸고 다시 묶는다. 그 밖(JSON · 맨 글자)은 글자 그대로 바꾼다.
 *
 * @param string $raw   원본.
 * @param string $old   옛 이름.
 * @param string $new   새 이름.
 * @param int    $price 새 값 (음수면 값은 그대로).
 * @return array{raw:string,name:int,price:int,ok:bool}
 */
function made( string $raw, string $old, string $new, int $price ): array {
	return made_pairs( $raw, variants( $old, $new ), $price );
}

/**
 * `made()` 의 속 — 찾을 것 → 넣을 것 짝을 직접 받는다.
 *
 * 줄마다 **그 자리에서 실제로 걸린 글자**로 바꿔야 할 때가 있다 (빈칸이 다른 경우).
 *
 * @param string $raw   원본.
 * @param array  $pairs 짝들.
 * @param int    $price 새 값 (음수면 값은 그대로).
 * @return array{raw:string,name:int,price:int,ok:bool}
 */
function made_pairs( string $raw, array $pairs, int $price ): array {
	$stat = array(
		'name'  => 0,
		'price' => 0,
	);

	if ( is_serialized( $raw ) ) {
		$data = @unserialize( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $data && 'b:0;' !== $raw ) {
			return array(
				'raw'   => $raw,
				'name'  => 0,
				'price' => 0,
				'ok'    => false,
			);
		}
		$data = walk( $data, $pairs, $price, $stat );
		$out  = serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	} else {
		$out = walk( $raw, $pairs, $price, $stat );
	}

	return array(
		'raw'   => (string) $out,
		'name'  => (int) $stat['name'],
		'price' => (int) $stat['price'],
		'ok'    => true,
	);
}

/**
 * 앞뒤를 보여 주는 토막.
 *
 * @param string $raw  글자.
 * @param string $find 찾을 것.
 * @param int    $pad  앞뒤 길이.
 * @return string
 */
function snippet( string $raw, string $find, int $pad = 90 ): string {
	$i = strpos( $raw, $find );
	if ( false === $i ) {
		return '';
	}
	$from = max( 0, $i - $pad );
	$cut  = substr( $raw, $from, strlen( $find ) + $pad * 2 );
	$cut  = (string) preg_replace( '/\s+/u', ' ', $cut );
	/* 잘린 한글 바이트를 버린다. */
	$cut = (string) ( mb_convert_encoding( $cut, 'UTF-8', 'UTF-8' ) );
	return ( $from > 0 ? '…' : '' ) . $cut . '…';
}

/**
 * 한 줄을 그 자리에 그대로 써 넣는다.
 *
 * 표마다 칸 이름이 달라 **줄이 스스로 어디서 왔는지를 들고 다닌다** —
 * 되돌릴 때 같은 자리에 그대로 써 넣어야 하기 때문이다.
 *
 * @param array  $r   줄 (표 · 칸 · 열쇠).
 * @param string $raw 넣을 글자.
 * @return bool
 */
function put( array $r, string $raw ): bool {
	global $wpdb;
	$ok = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		(string) $r['table'],
		array( (string) $r['valcol'] => $raw ),
		array( (string) $r['idcol'] => $r['id'] )
	);
	if ( false === $ok ) {
		return false;
	}
	if ( ! empty( $r['post'] ) ) {
		wp_cache_delete( (int) $r['post'], 'post_meta' );
		/* 글 본문을 고쳤으면 글 캐시도 비운다 — 안 비우면 화면이 옛 글을 계속 낸다. */
		if ( $wpdb->posts === (string) $r['table'] && function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( (int) $r['post'] );
		}
	}
	if ( $wpdb->options === (string) $r['table'] ) {
		wp_cache_delete( (string) $r['key'], 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
	return true;
}

/**
 * 되돌릴 때 같은 자리를 다시 찾기 위한 열쇠.
 *
 * @param array $r 줄.
 * @return string
 */
function spot_key( array $r ): string {
	return implode(
		'|',
		array( $r['table'], $r['idcol'], $r['valcol'], $r['id'], (int) ( $r['post'] ?? 0 ), (string) ( $r['key'] ?? '' ) )
	);
}

/**
 * 실제로 저장한다.
 *
 * @param array  $rows  scan() 결과.
 * @param string $old   옛 이름.
 * @param string $new   새 이름.
 * @param int    $price 새 값 (음수면 값은 그대로).
 * @return array{rows:int,name:int,price:int,fail:int}
 */
function save( array $rows, string $old, string $new, int $price ): array {
	$back = array();
	$did  = array(
		'rows'  => 0,
		'name'  => 0,
		'price' => 0,
		'fail'  => 0,
	);

	foreach ( $rows as $r ) {
		if ( $r['skip'] ) {
			continue;
		}
		$made = made_pairs( (string) $r['raw'], array( array( (string) $r['old'], (string) $r['new'] ) ), $price );
		if ( ! $made['ok'] || 0 === $made['name'] || $made['raw'] === $r['raw'] ) {
			++$did['fail'];
			continue;
		}
		if ( ! put( $r, $made['raw'] ) ) {
			++$did['fail'];
			continue;
		}
		$back[ spot_key( $r ) ] = (string) $r['raw'];
		++$did['rows'];
		$did['name']  += $made['name'];
		$did['price'] += $made['price'];
	}

	if ( $back ) {
		update_option(
			BACKUP,
			array(
				'when' => time(),
				'old'  => $old,
				'new'  => $new,
				'meta' => $back,
			),
			false
		);
	}

	flush_caches();
	return $did;
}

/**
 * 바꾸기 전으로 되돌린다.
 *
 * @return array{rows:int,said:string}
 */
function undo(): array {
	$bak = get_option( BACKUP );
	if ( ! is_array( $bak ) || empty( $bak['meta'] ) ) {
		return array(
			'rows' => 0,
			'said' => '되돌릴 것이 없습니다.',
		);
	}
	$n = 0;
	foreach ( (array) $bak['meta'] as $key => $raw ) {
		$bits = explode( '|', (string) $key );
		if ( count( $bits ) < 5 ) {
			continue;
		}
		$r = array(
			'table'  => $bits[0],
			'idcol'  => $bits[1],
			'valcol' => $bits[2],
			'id'     => $bits[3],
			'post'   => (int) $bits[4],
			'key'    => (string) ( $bits[5] ?? '' ),
		);
		if ( put( $r, (string) $raw ) ) {
			++$n;
		}
	}
	delete_option( BACKUP );
	flush_caches();
	return array(
		'rows' => $n,
		'said' => sprintf( '%d군데를 바꾸기 전으로 되돌렸습니다.', $n ),
	);
}

/**
 * 우리 카드 캐시 · 워드커머스 임시 저장분을 비운다.
 *
 * @return void
 */
function flush_caches(): void {
	if ( function_exists( '\\Duckhoo\\Redesign\\Front\\flush_cache' ) ) {
		\Duckhoo\Redesign\Front\flush_cache();
	}
	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
}

/**
 * 이름에 그 말이 든 상품들.
 *
 * @param string $find 찾을 말.
 * @return array<int,\WC_Product>
 */
function products( string $find ): array {
	$find = trim( $find );
	if ( '' === $find || ! function_exists( 'wc_get_products' ) ) {
		return array();
	}
	$all = wc_get_products(
		array(
			'limit'   => 500,
			'status'  => array( 'publish', 'private', 'draft' ),
			'orderby' => 'title',
			'order'   => 'ASC',
		)
	);
	$out = array();
	foreach ( (array) $all as $p ) {
		if ( $p instanceof \WC_Product && false !== mb_stripos( $p->get_name(), $find ) ) {
			$out[] = $p;
		}
	}
	return $out;
}

/**
 * 상품 이름 · 값을 바꾼다 (이전 값을 남겨 둔다).
 *
 * @param int    $id    상품 번호.
 * @param string $name  새 이름 (빈 값이면 그대로).
 * @param int    $price 새 값 (음수면 그대로).
 * @return string 한 일.
 */
function set_product( int $id, string $name, int $price ): string {
	$p = $id ? wc_get_product( $id ) : null;
	if ( ! $p instanceof \WC_Product ) {
		return '';
	}
	$was = array(
		'name'    => $p->get_name(),
		'regular' => (string) $p->get_regular_price( 'edit' ),
		'sale'    => (string) $p->get_sale_price( 'edit' ),
	);
	$did = array();

	if ( '' !== trim( $name ) && trim( $name ) !== $was['name'] ) {
		$p->set_name( trim( $name ) );
		$did[] = sprintf( '이름 「%s」 → 「%s」', $was['name'], trim( $name ) );
	}
	if ( $price >= 0 && (string) $price !== $was['regular'] ) {
		$p->set_regular_price( (string) $price );
		/* 판매가가 정가와 같았다면 같이 올린다 — 안 그러면 옛 값으로 팔린다. */
		if ( '' !== $was['sale'] && (float) $was['sale'] >= (float) $was['regular'] ) {
			$p->set_sale_price( (string) $price );
		}
		$did[] = sprintf( '값 %s원 → %s원', number_format( (float) $was['regular'] ), number_format( (float) $price ) );
	}
	if ( ! $did ) {
		return '';
	}
	$p->update_meta_data( '_dhr_optbak_product', $was );
	$p->save();
	flush_caches();
	return sprintf( '#%d — %s', $id, implode( ' · ', $did ) );
}

/**
 * 옵션 이름이 어떻게 바뀌었는지를 보고 **상품 이름도 같은 식으로** 미리 채운다.
 *
 * `V4 → V5` · `(2EA) → (3EA)` 처럼 **옛 이름과 새 이름이 실제로 다른 토막만** 옮긴다.
 * 짐작일 뿐이라 칸에 채워만 두고, 저장은 사장님이 눌렀을 때 한다.
 *
 * @param string $name 지금 상품 이름.
 * @param string $old  옵션의 옛 이름.
 * @param string $new  옵션의 새 이름.
 * @return string
 */
function guess_name( string $name, string $old, string $new ): string {
	foreach ( array( '/V(\d+)/i', '/\((\d+)EA\)/i' ) as $pat ) {
		if ( preg_match( $pat, $old, $a ) && preg_match( $pat, $new, $b ) && $a[0] !== $b[0] ) {
			$name = (string) preg_replace( $pat, str_replace( $a[1], $b[1], $a[0] ), $name );
		}
	}
	return $name;
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

	$posted = isset( $_POST['dhr_opt_do'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$do     = $posted ? sanitize_key( (string) wp_unslash( $_POST['dhr_opt_do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

	if ( in_array( $do, array( 'apply', 'undo', 'product' ), true ) ) {
		check_admin_referer( 'dhr-opts' );
	}

	// phpcs:disable WordPress.Security.NonceVerification
	$old   = $posted ? trim( (string) wp_unslash( $_POST['dhr_opt_old'] ?? '' ) ) : '브이메이트V4팟 0.7옴(2EA)';
	$new   = $posted ? trim( (string) wp_unslash( $_POST['dhr_opt_new'] ?? '' ) ) : '브이메이트V5팟 0.7옴(3EA)';
	$praw  = $posted ? trim( (string) wp_unslash( $_POST['dhr_opt_price'] ?? '' ) ) : '12000';
	$pfind = $posted ? trim( (string) wp_unslash( $_POST['dhr_opt_find'] ?? '' ) ) : '브이메이트';
	$pid   = $posted ? (int) ( $_POST['dhr_opt_pid'] ?? 0 ) : 0;
	$pn    = (array) wp_unslash( $_POST['dhr_opt_pname'] ?? array() );
	$pp    = (array) wp_unslash( $_POST['dhr_opt_pprice'] ?? array() );
	$pname = $pid && isset( $pn[ $pid ] ) ? trim( (string) $pn[ $pid ] ) : '';
	$pprc  = $pid && isset( $pp[ $pid ] ) ? trim( (string) $pp[ $pid ] ) : '';
	// phpcs:enable WordPress.Security.NonceVerification

	$price = '' === $praw ? -1 : (int) preg_replace( '/[^\d]/', '', $praw );
	$said  = '';

	$rows = scan( $old, $new );

	if ( 'apply' === $do && '' !== $old && '' !== $new ) {
		$did  = save( $rows, $old, $new, $price );
		$said = sprintf(
			'<b>%d군데</b>에서 이름 <b>%d개</b>를 바꿨습니다%s.%s 상품 화면을 새로고침해 확인해 주세요.',
			$did['rows'],
			$did['name'],
			$did['price'] ? sprintf( ' · 값 <b>%d개</b>', $did['price'] ) : '',
			$did['fail'] ? sprintf( ' <b style="color:#B54708">%d군데는 못 알아봐서 그냥 두었습니다.</b>', $did['fail'] ) : ''
		);
		$rows = scan( $old, $new );
	} elseif ( 'undo' === $do ) {
		$u    = undo();
		$said = $u['said'];
		$rows = scan( $old, $new );
	} elseif ( 'product' === $do ) {
		$done = set_product( $pid, $pname, '' === $pprc ? -1 : (int) preg_replace( '/[^\d]/', '', $pprc ) );
		$said = '' !== $done ? '상품을 바꿨습니다 — ' . esc_html( $done ) : '바뀐 것이 없습니다.';
	}

	$live = 0;
	$skip = 0;
	foreach ( $rows as $r ) {
		if ( $r['skip'] ) {
			++$skip;
		} else {
			++$live;
		}
	}
	$bak = get_option( BACKUP );

	echo '<div class="wrap"><h1>옵션 이름 바꾸기</h1>';
	echo '<p>상품 하나가 바뀌면 <b>다른 상품들의 선택칸(팟 · 코일 · 기기 추가)에 적힌 이름도 같이</b> 바뀌어야 합니다. '
		. '여기서 한 번에 바꿉니다. <b>「미리 보기」로 바뀌기 전후를 먼저 보고, 「바꾸기」를 눌렀을 때만</b> 저장합니다. '
		. '주문에 이미 실린 이름은 건드리지 않습니다 — 옛 주문은 그때 산 이름 그대로 남습니다.</p>';

	if ( '' !== $said ) {
		printf( '<div class="notice notice-success"><p>%s</p></div>', wp_kses_post( $said ) );
	}

	echo '<form method="post">';
	wp_nonce_field( 'dhr-opts' );
	echo '<input type="hidden" name="dhr_opt_do" value="preview">';
	echo '<table class="form-table" style="max-width:900px"><tbody>';
	printf(
		'<tr><th scope="row"><label for="dhr_opt_old">지금 이름</label></th><td>'
		. '<input type="text" id="dhr_opt_old" name="dhr_opt_old" value="%s" class="large-text" style="font-family:monospace">'
		. '<p class="description">선택칸에 <b>적혀 있는 그대로</b> 적습니다 (띄어쓰기 · 괄호까지).</p></td></tr>',
		esc_attr( $old )
	);
	printf(
		'<tr><th scope="row"><label for="dhr_opt_new">새 이름</label></th><td>'
		. '<input type="text" id="dhr_opt_new" name="dhr_opt_new" value="%s" class="large-text" style="font-family:monospace"></td></tr>',
		esc_attr( $new )
	);
	printf(
		'<tr><th scope="row"><label for="dhr_opt_price">새 값(원)</label></th><td>'
		. '<input type="text" id="dhr_opt_price" name="dhr_opt_price" value="%s" class="regular-text">'
		. '<p class="description">비워 두면 <b>값은 그대로</b> 두고 이름만 바꿉니다.</p></td></tr>',
		esc_attr( $praw )
	);
	echo '</tbody></table>';

	echo '<p style="margin-top:.6em">';
	submit_button( '미리 보기', 'secondary', 'dhr_opt_preview', false );
	echo ' ';
	printf(
		'<button type="submit" name="dhr_opt_do" value="apply" class="button button-primary"%s '
		. 'onclick="return confirm(\'선택칸의 이름과 값을 바꿉니다. 되돌리기 버튼이 있습니다.\')">%s</button>',
		$live ? '' : ' disabled',
		$live ? esc_html( sprintf( '선택칸 %d군데 바꾸기', $live ) ) : '선택칸 바꾸기 (찾은 것 없음)'
	);
	if ( is_array( $bak ) && ! empty( $bak['meta'] ) ) {
		printf(
			' <button type="submit" name="dhr_opt_do" value="undo" class="button" '
			. 'onclick="return confirm(\'바꾸기 전으로 되돌립니다.\')">되돌리기 (%d군데 · %s)</button>',
			count( (array) $bak['meta'] ),
			esc_html( wp_date( 'n월 j일 H:i', (int) ( $bak['when'] ?? 0 ) ) )
		);
	}
	echo '</p>';

	if ( $rows ) {
		printf(
			'<h2>미리 보기 — 찾은 자리 %d군데%s</h2>',
			count( $rows ),
			$skip ? esc_html( sprintf( ' (주문 등 %d군데는 건너뜁니다)', $skip ) ) : ''
		);
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:280px">어디</th><th>바뀌기 전 → 뒤</th></tr></thead><tbody>';
		$shown = 0;
		foreach ( $rows as $r ) {
			++$shown;
			if ( $shown > 40 ) {
				break;
			}
			$made  = $r['skip'] ? null : made_pairs( (string) $r['raw'], array( array( (string) $r['old'], (string) $r['new'] ) ), $price );
			$hit   = (string) ( $r['old'] ?? $old );
			$where = sprintf(
				'%s<br><small style="color:#666">%s · <code>%s</code> · %d곳</small>',
				esc_html( '' !== $r['title'] ? '#' . (int) $r['post'] . ' ' . $r['title'] : (string) $r['key'] ),
				esc_html( '' !== (string) $r['type'] ? (string) $r['type'] : (string) $r['table'] ),
				esc_html( (string) $r['key'] ),
				(int) $r['hits']
			);
			if ( $r['skip'] ) {
				$cell = '<b style="color:#B54708">건너뜁니다</b> — 주문 · 기록은 그때 산 이름 그대로 두어야 합니다.';
			} elseif ( ! $made['ok'] ) {
				$cell = '<b style="color:#B42318">못 읽었습니다 — 손대지 않습니다.</b>';
			} else {
				$cell = sprintf(
					'<code style="display:block;color:#8a2c0d;word-break:break-all">%s</code>'
					. '<code style="display:block;color:#1F5F46;word-break:break-all;margin-top:4px">%s</code>'
					. '<small style="color:#666">이름 %d곳%s</small>',
					esc_html( snippet( (string) $r['raw'], $hit ) ),
					esc_html( snippet( (string) $made['raw'], $hit === $old ? $new : json_bare( $new ) ) ),
					(int) $made['name'],
					$made['price'] ? esc_html( sprintf( ' · 값 %d곳', (int) $made['price'] ) ) : ' · <b style="color:#B54708">값은 못 찾음 (그대로 둡니다)</b>'
				);
			}
			printf( '<tr><td>%s</td><td>%s</td></tr>', wp_kses_post( $where ), wp_kses_post( $cell ) );
		}
		echo '</tbody></table>';
		if ( count( $rows ) > 40 ) {
			printf( '<p class="description">…그 밖 %d군데는 같은 모양이라 줄였습니다. 바꾸기는 전부에 걸립니다.</p>', count( $rows ) - 40 );
		}
	} elseif ( '' !== $old ) {
		echo '<div class="notice notice-warning inline" style="margin:1em 0"><p><b>그 글자를 가진 선택칸을 못 찾았습니다.</b> '
			. '아래에 <b>어디를 찾아봤는지</b>와 <b>비슷한 자리</b>를 적어 둘 테니 그대로 알려 주시면 됩니다.</p></div>';

		$pr = probe( $old );
		if ( $pr['rows'] ) {
			printf(
				'<p><code>%s</code> 로 다시 훑으니 <b>%d군데</b>가 나옵니다. 아래 글자에서 '
				. '<b>선택칸 이름이 실제로 어떻게 적혀 있는지</b> 보입니다 — 그대로 복사해 위 「지금 이름」에 넣어 보세요.</p>',
				esc_html( $pr['frag'] ),
				count( $pr['rows'] )
			);
			echo '<table class="widefat striped"><thead><tr><th style="width:280px">어디</th><th>그 자리의 글자</th></tr></thead><tbody>';
			foreach ( $pr['rows'] as $r ) {
				printf(
					'<tr><td>%s<br><small style="color:#666">%s · <code>%s</code></small></td>'
					. '<td><code style="display:block;word-break:break-all">%s</code></td></tr>',
					esc_html( '' !== $r['title'] ? '#' . (int) $r['post'] . ' ' . $r['title'] : (string) $r['key'] ),
					esc_html( '' !== (string) $r['type'] ? (string) $r['type'] : (string) $r['table'] ),
					esc_html( (string) $r['key'] ),
					esc_html( snippet( (string) $r['raw'], $pr['frag'], 130 ) )
				);
			}
			echo '</tbody></table>';
		} else {
			printf(
				'<p><code>%s</code> 로 훑어도 나오지 않습니다 — 옵션이 <b>아래 표들 밖</b>에 있다는 뜻입니다.</p>',
				esc_html( '' !== $pr['frag'] ? $pr['frag'] : $old )
			);
		}

		$names = array();
		foreach ( places() as $p ) {
			$names[ $p[0] ] = true;
		}
		$list = array();
		foreach ( array_keys( $names ) as $t ) {
			$list[] = '<code>' . esc_html( (string) $t ) . '</code>';
		}
		printf( '<p class="description">찾아본 곳: %s</p>', wp_kses_post( implode( ', ', $list ) ) );
	}

	/* ── 상품 자체 ── */
	echo '<hr style="margin:2em 0"><h2>상품 자체 (이름 · 값)</h2>';
	printf(
		'<p>선택칸과 별개로 <b>그 상품 자체</b>도 바꿔야 합니다. '
		. '<input type="text" name="dhr_opt_find" value="%s" class="regular-text" style="max-width:200px"> '
		. '가 이름에 든 상품을 찾습니다. <button type="submit" name="dhr_opt_do" value="preview" class="button">찾기</button></p>',
		esc_attr( $pfind )
	);
	$ps = products( $pfind );
	if ( $ps ) {
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>'
			. '<th style="width:60px">번호</th><th>지금</th><th style="width:300px">새 이름</th>'
			. '<th style="width:120px">새 값</th><th style="width:90px"></th></tr></thead><tbody>';
		foreach ( $ps as $p ) {
			printf(
				'<tr><td>#%1$d</td><td>%2$s<br><small style="color:#666">%3$s원</small></td>'
				. '<td><input type="text" name="dhr_opt_pname[%1$d]" value="%4$s" class="large-text" style="width:100%%"></td>'
				. '<td><input type="text" name="dhr_opt_pprice[%1$d]" value="%5$s" class="regular-text" style="width:110px"></td>'
				. '<td><button type="submit" name="dhr_opt_do" value="product" class="button button-primary" '
				. 'onclick="return confirm(\'이 상품 하나의 이름과 값만 바꿉니다.\')">이 상품만 바꾸기</button>'
				. '<input type="hidden" name="dhr_opt_pid" value="%1$d"></td></tr>',
				$p->get_id(),
				esc_html( $p->get_name() ),
				esc_html( number_format( (float) $p->get_price() ) ),
				esc_attr( guess_name( $p->get_name(), $old, $new ) ),
				esc_attr( $praw )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description"><b>주소(slug)는 그대로 둡니다</b> — 바꾸면 검색엔진에 올라간 주소가 끊깁니다. '
			. '이름만 바뀌어도 검색 결과 · 상세 글은 새 이름을 따라갑니다.</p>';
	} else {
		echo '<p class="description">그 말이 이름에 든 상품이 없습니다.</p>';
	}

	echo '<hr style="margin:2em 0"><h2>바꾼 뒤 한 가지</h2>';
	echo '<p>값이 바뀌면 <b>이미 담겨 있던 장바구니</b>는 옛 값으로 남아 결제 단계에서 '
		. '「주문 금액이 올바르게 계산되지 않았습니다」가 뜰 수 있습니다. '
		. '그때는 손님이 장바구니를 비우고 다시 담으면 됩니다 — 안내에도 그렇게 나옵니다.</p>';

	echo '</form></div>';
}
