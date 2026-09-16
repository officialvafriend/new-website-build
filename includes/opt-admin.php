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
 * 그 글자가 들어 있는 자리를 모두 찾는다 (읽기만 한다).
 *
 * @param string $old 찾을 글자.
 * @return array<int,array<string,mixed>>
 */
function scan( string $old ): array {
	global $wpdb;
	if ( '' === trim( $old ) ) {
		return array();
	}
	$like = '%' . $wpdb->esc_like( $old ) . '%';
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value, p.post_type, p.post_title
			   FROM {$wpdb->postmeta} m
			   LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
			  WHERE m.meta_value LIKE %s
			  ORDER BY m.post_id ASC
			  LIMIT 500",
			$like
		),
		ARRAY_A
	);
	$out = array();
	foreach ( (array) $rows as $r ) {
		$type = (string) ( $r['post_type'] ?? '' );
		$key  = (string) $r['meta_key'];
		$raw  = (string) $r['meta_value'];
		$out[] = array(
			'meta_id' => (int) $r['meta_id'],
			'post_id' => (int) $r['post_id'],
			'key'     => $key,
			'type'    => $type,
			'title'   => (string) ( $r['post_title'] ?? '' ),
			'raw'     => $raw,
			'hits'    => substr_count( $raw, $old ),
			'skip'    => off_limits( $type, $key ),
		);
	}
	return $out;
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
 * @param mixed  $node  마디.
 * @param string $old   옛 이름.
 * @param string $new   새 이름.
 * @param int    $price 새 값 (음수면 값은 건드리지 않는다).
 * @param array  $stat  센 것 (참조).
 * @return mixed
 */
function walk( $node, string $old, string $new, int $price, array &$stat ) {
	if ( is_string( $node ) ) {
		if ( false !== strpos( $node, $old ) ) {
			$stat['name'] += substr_count( $node, $old );
			$node          = str_replace( $old, $new, $node );
			if ( $price >= 0 ) {
				$node = retag_price( $node, $new, $price, $stat['price'] );
			}
		}
		return $node;
	}

	if ( is_array( $node ) || is_object( $node ) ) {
		$arr  = is_object( $node ) ? get_object_vars( $node ) : $node;
		$mine = false;
		foreach ( $arr as $v ) {
			if ( is_string( $v ) && false !== strpos( $v, $old ) ) {
				$mine = true;
			}
		}
		foreach ( $arr as $k => $v ) {
			$arr[ $k ] = walk( $v, $old, $new, $price, $stat );
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
		$data = walk( $data, $old, $new, $price, $stat );
		$out  = serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	} else {
		$out = walk( $raw, $old, $new, $price, $stat );
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
 * 실제로 저장한다.
 *
 * @param array  $rows  scan() 결과.
 * @param string $old   옛 이름.
 * @param string $new   새 이름.
 * @param int    $price 새 값 (음수면 값은 그대로).
 * @return array{rows:int,name:int,price:int,fail:int}
 */
function save( array $rows, string $old, string $new, int $price ): array {
	global $wpdb;

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
		$made = made( (string) $r['raw'], $old, $new, $price );
		if ( ! $made['ok'] || 0 === $made['name'] || $made['raw'] === $r['raw'] ) {
			++$did['fail'];
			continue;
		}
		$ok = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->postmeta,
			array( 'meta_value' => $made['raw'] ),
			array( 'meta_id' => (int) $r['meta_id'] )
		);
		if ( false === $ok ) {
			++$did['fail'];
			continue;
		}
		$back[ (int) $r['meta_id'] ] = (string) $r['raw'];
		wp_cache_delete( (int) $r['post_id'], 'post_meta' );
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
	global $wpdb;
	$bak = get_option( BACKUP );
	if ( ! is_array( $bak ) || empty( $bak['meta'] ) ) {
		return array(
			'rows' => 0,
			'said' => '되돌릴 것이 없습니다.',
		);
	}
	$n = 0;
	foreach ( (array) $bak['meta'] as $mid => $raw ) {
		$pid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_id = %d", (int) $mid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok  = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->postmeta,
			array( 'meta_value' => (string) $raw ),
			array( 'meta_id' => (int) $mid )
		);
		if ( false !== $ok ) {
			++$n;
			if ( $pid ) {
				wp_cache_delete( $pid, 'post_meta' );
			}
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

	$rows = scan( $old );

	if ( 'apply' === $do && '' !== $old && '' !== $new ) {
		$did  = save( $rows, $old, $new, $price );
		$said = sprintf(
			'<b>%d군데</b>에서 이름 <b>%d개</b>를 바꿨습니다%s.%s 상품 화면을 새로고침해 확인해 주세요.',
			$did['rows'],
			$did['name'],
			$did['price'] ? sprintf( ' · 값 <b>%d개</b>', $did['price'] ) : '',
			$did['fail'] ? sprintf( ' <b style="color:#B54708">%d군데는 못 알아봐서 그냥 두었습니다.</b>', $did['fail'] ) : ''
		);
		$rows = scan( $old );
	} elseif ( 'undo' === $do ) {
		$u    = undo();
		$said = $u['said'];
		$rows = scan( $old );
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
		$live ? esc_html( sprintf( '%d군데 바꾸기', $live ) ) : '바꾸기'
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
			$made  = $r['skip'] ? null : made( (string) $r['raw'], $old, $new, $price );
			$where = sprintf(
				'#%d %s<br><small style="color:#666">%s · <code>%s</code> · %d곳</small>',
				(int) $r['post_id'],
				esc_html( '' !== $r['title'] ? $r['title'] : '(제목 없음)' ),
				esc_html( (string) $r['type'] ),
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
					esc_html( snippet( (string) $r['raw'], $old ) ),
					esc_html( snippet( (string) $made['raw'], $new ) ),
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
		echo '<p><b>그 글자를 가진 선택칸이 없습니다.</b> 상품 화면의 선택칸에 적힌 글자를 그대로 붙여 넣어 주세요.</p>';
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
				. '<td><input type="text" name="dhr_opt_pprice[%1$d]" value="%5$s" class="small-text"></td>'
				. '<td><button type="submit" name="dhr_opt_do" value="product" class="button button-primary" '
				. 'onclick="return confirm(\'이 상품의 이름과 값을 바꿉니다.\')">바꾸기</button>'
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
