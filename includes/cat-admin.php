<?php
/**
 * 도구 → 분류 묶기 (관리자).
 *
 * 상품 이름을 붙여 넣으면 **새 분류를 만들고 그 상품들을 거기에 넣는다.**
 * 27개를 관리자에서 하나씩 열어 체크하는 대신 한 번에 한다 (사장님 2026-09-15 · 「타격감」).
 *
 * - **원래 분류는 그대로 둔다** — 새 분류를 *더한다* (`wp_set_object_terms( …, true )`).
 *   입호흡 · 무니코틴처럼 이미 걸려 있는 것이 빠지면 목록 · 검색이 무너진다
 * - **눌렀을 때만 쓴다.** 그 전에 「미리 보기」가 어느 줄이 어느 상품인지 보여 준다
 * - 줄이 여럿에 걸리거나 하나도 안 걸리면 **그 줄은 건너뛴다** — 엉뚱한 상품이
 *   들어가는 것보다 빠지는 편이 낫다. 정확히 집으려면 `id:254` 처럼 번호로 적는다
 * - 되돌리기는 「이 분류에서 전부 빼기」 한 번 (분류 자체는 남는다)
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Cat\Admin;

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-cats';

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
		'분류 묶기',
		'분류 묶기',
		current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 이름 맞추기용으로 다듬는다 — 대괄호 · 문장부호 · 띄어쓰기를 걷어낸다.
 *
 * 괄호 안(9.8mg / 30ml)은 **남긴다** — 같은 맛의 30ml 와 60ml 를 가르는 유일한 단서다.
 *
 * @param string $s 이름.
 * @return string
 */
function key( string $s ): string {
	$s = (string) preg_replace( '/[\[\]★!·,\-+\/()]/u', ' ', $s );
	return (string) preg_replace( '/\s+/u', '', $s );
}

/**
 * 가게의 모든 상품 (id => 이름).
 *
 * @return array<int,string>
 */
function catalog(): array {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out = array();
	if ( ! function_exists( 'wc_get_products' ) ) {
		$out = (array) apply_filters( 'duckhoo_cat_catalog', $out );
		return $out;
	}
	foreach ( (array) wc_get_products( array( 'status' => 'publish', 'limit' => 500, 'return' => 'objects' ) ) as $p ) {
		$out[ (int) $p->get_id() ] = (string) $p->get_name();
	}
	$out = (array) apply_filters( 'duckhoo_cat_catalog', $out );
	return $out;
}

/**
 * 붙여 넣은 줄을 읽어 상품에 맞춘다.
 *
 * 한 줄에 하나. `#` 로 시작하는 줄은 건너뛴다. `id:254` 는 번호로 못 박는다.
 * 그 밖에는 **줄의 낱말이 모두 들어 있는** 상품을 찾는다.
 *
 * @param string $raw 붙여 넣은 글.
 * @return array<int,array{line:string,ids:array<int,int>,state:string,near:string}>
 */
function match_lines( string $raw ): array {
	$books = catalog();
	$rows  = array();

	foreach ( preg_split( "/\r\n|\r|\n/", $raw ) ?: array() as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}
		if ( preg_match( '/^id\s*:\s*(\d+)$/i', $line, $m ) ) {
			$id    = (int) $m[1];
			$rows[] = array(
				'line'  => $line,
				'ids'   => isset( $books[ $id ] ) ? array( $id ) : array(),
				'state' => isset( $books[ $id ] ) ? 'one' : 'none',
				'near'  => '',
			);
			continue;
		}

		/* **붙여 놓은 그대로 들어 있는지를 먼저 본다.** 낱말이 다 들어 있기만 하면 되는
		   규칙으로는 `[노보] 블랙멘솔` 과 `[노보 블랙] 블랙멘솔` 이 안 갈린다 —
		   앞엣것도 「노보」 · 「블랙」 · 「블랙멘솔」 을 다 가지고 있기 때문이다.
		   통째로 이어진 자리가 있으면 그쪽이 훨씬 센 증거다 (테스트가 이걸 잡았다). */
		$whole  = key( $line );
		$strict = array();
		foreach ( $books as $id => $name ) {
			if ( '' !== $whole && false !== mb_strpos( key( $name ), $whole ) ) {
				$strict[] = (int) $id;
			}
		}

		$words = array_values( array_filter( preg_split( '/\s+/u', $line ) ?: array() ) );
		$hits  = array();
		foreach ( $books as $id => $name ) {
			$k  = key( $name );
			$ok = true;
			foreach ( $words as $w ) {
				if ( false === mb_strpos( $k, key( $w ) ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$hits[] = (int) $id;
			}
		}
		if ( $strict ) {
			$hits = $strict;   /* 순서까지 맞는 것이 있으면 그것만 본다 */
		}

		$near = '';
		if ( ! $hits ) {
			// 못 찾았을 때만 **비슷한 이름**을 하나 귀띔한다 (자동으로 넣지는 않는다 —
			// 「파이낫푸르」와 「파이낫푸루」처럼 한 글자가 다를 수 있다).
			$best = 0.0;
			foreach ( $books as $id => $name ) {
				similar_text( key( $line ), key( $name ), $pct );
				if ( $pct > $best ) {
					$best = (float) $pct;
					$near = '#' . $id . ' ' . $name;
				}
			}
			if ( $best < 45 ) {
				$near = '';
			}
		}

		$rows[] = array(
			'line'  => $line,
			'ids'   => $hits,
			'state' => 1 === count( $hits ) ? 'one' : ( $hits ? 'many' : 'none' ),
			'near'  => $near,
		);
	}
	return $rows;
}

/**
 * 분류를 찾거나 만든다.
 *
 * @param string $name 분류 이름.
 * @return int 0 이면 실패.
 */
function term_id( string $name ): int {
	$name = trim( $name );
	if ( '' === $name || ! taxonomy_exists( 'product_cat' ) ) {
		return 0;
	}
	$t = get_term_by( 'name', $name, 'product_cat' );
	if ( $t instanceof \WP_Term ) {
		return (int) $t->term_id;
	}
	$made = wp_insert_term( $name, 'product_cat' );
	if ( is_wp_error( $made ) ) {
		$exists = term_exists( $name, 'product_cat' );
		return is_array( $exists ) ? (int) $exists['term_id'] : 0;
	}
	return (int) $made['term_id'];
}

/**
 * 우리 · 워드커머스 캐시를 비운다. 안 하면 10분 동안 옛 목록이 보인다.
 *
 * @return void
 */
function flush(): void {
	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
	if ( function_exists( 'Duckhoo\\Redesign\\Front\\flush_cache' ) ) {
		\Duckhoo\Redesign\Front\flush_cache();
	}
}

/**
 * 찾은 상품을 분류에 **더한다** (원래 분류는 그대로).
 *
 * @param int                $term 분류 id.
 * @param array<int,int>     $ids  상품 id.
 * @return int 손댄 수.
 */
function attach( int $term, array $ids ): int {
	$n = 0;
	foreach ( array_unique( $ids ) as $id ) {
		$now = wp_get_object_terms( (int) $id, 'product_cat', array( 'fields' => 'ids' ) );
		$now = is_wp_error( $now ) ? array() : array_map( 'intval', $now );
		if ( in_array( $term, $now, true ) ) {
			continue;
		}
		wp_set_object_terms( (int) $id, array( $term ), 'product_cat', true );
		++$n;
	}
	flush();
	return $n;
}

/**
 * 그 분류에서 전부 뺀다 (분류 자체는 남는다).
 *
 * @param int $term 분류 id.
 * @return int 손댄 수.
 */
function detach( int $term ): int {
	$ids = get_objects_in_term( array( $term ), 'product_cat' );
	$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
	foreach ( $ids as $id ) {
		wp_remove_object_terms( $id, array( $term ), 'product_cat' );
	}
	flush();
	return count( $ids );
}

/**
 * 「타격감」 첫 목록 — 사장님이 주신 표를 가게 이름으로 맞춰 둔 것.
 *
 * 못 찾은 줄은 `#` 로 적어 두었다. 이름을 알려 주시면 그 줄의 `#` 만 지우면 된다.
 *
 * @return string
 */
function seed(): string {
	return implode(
		"\n",
		array(
			'스모모 흑염룡',
			'쟈쿠로 흑염룡',
			'아오리 흑염룡',
			'머스케또 흑염룡',
			'링고 흑염룡',
			'파이낫푸루 흑염룡',
			'바나나 흑염룡',
			'레몬 흑염룡',
			'소다 흑염룡',
			'머스케또쨩',
			'스모모쨩',
			'바나나쨩',
			'아이스빌런레즈애플',
			'아이스빌런파인애플',
			'노보 블랙 블랙멘솔',
			'노보 블랙 코코넛커피',
			'노보 블랙 아메리카노',
			'노보 블랙 데저트',
			'노보 블랙 쿠바시가',
			'노보 블랙 타박멘솔',      // 사장님이 새로 추가 (2026-09-15) — [노보] 타박멘솔 과 다른 상품이다
			'노보 블랙 엠에스블랜드',  // 사장님이 새로 추가 (2026-09-15)
			'화이트아웃 멘솔시가',     // 사장님 표의 「화이트멘솔」
			'디오리퀴드 알로에 그레이프', // 사장님 표의 「디오알포」
			'id:254',                  // [펠릭스] 더블라임 30ml — 「더블라임」 만으로는 모드 60ml 과 안 갈린다
			'',
			'# ↓ 사장님이 상품을 추가하신 뒤 `#` 만 지우면 됩니다.',
			'# 블루워터',
			'# 라임알로에',
		)
	);
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

	$posted = isset( $_POST['dhr_cat_do'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$name   = trim( (string) wp_unslash( $_POST['dhr_cat_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$raw    = (string) wp_unslash( $_POST['dhr_cat_list'] ?? '' );         // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $posted ) {
		$name = '타격감';
		$raw  = seed();
	}
	$do   = $posted ? sanitize_key( (string) wp_unslash( $_POST['dhr_cat_do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$said = '';

	if ( in_array( $do, array( 'apply', 'detach' ), true ) ) {
		check_admin_referer( 'dhr-cats' );
	}

	$rows = match_lines( $raw );
	$ok   = array();
	foreach ( $rows as $r ) {
		if ( 'one' === $r['state'] ) {
			$ok[] = (int) $r['ids'][0];
		}
	}

	if ( 'apply' === $do && $ok ) {
		$term = term_id( $name );
		if ( ! $term ) {
			$said = '<b style="color:#B42318">분류를 만들지 못했습니다.</b> 이름을 확인해 주세요.';
		} else {
			$n    = attach( $term, $ok );
			$link = get_term_link( $term, 'product_cat' );
			$said = sprintf(
				'<b>%s</b> 분류에 <b>%d개</b>를 넣었습니다 (이미 들어 있던 %d개는 그대로). %s',
				esc_html( $name ),
				(int) $n,
				count( $ok ) - (int) $n,
				is_string( $link ) ? '<a href="' . esc_url( $link ) . '" target="_blank">분류 보기</a>' : ''
			);
		}
	} elseif ( 'detach' === $do ) {
		$t = get_term_by( 'name', $name, 'product_cat' );
		if ( $t instanceof \WP_Term ) {
			$said = sprintf( '<b>%s</b> 분류에서 <b>%d개</b>를 뺐습니다. 분류 자체는 남아 있습니다.', esc_html( $name ), detach( (int) $t->term_id ) );
		} else {
			$said = '그 이름의 분류가 없습니다.';
		}
	}

	$books = catalog();

	echo '<div class="wrap"><h1>분류 묶기</h1>';
	echo '<p>상품 이름을 붙여 넣으면 <b>새 분류를 만들고 그 상품들을 넣습니다.</b> '
		. '<b>원래 분류는 그대로 둡니다</b> — 새 분류를 더할 뿐입니다. '
		. '「미리 보기」로 어느 줄이 어느 상품인지 먼저 보고, <b>「넣기」를 눌렀을 때만</b> 씁니다.</p>';

	if ( '' !== $said ) {
		printf( '<div class="notice notice-success"><p>%s</p></div>', wp_kses_post( $said ) );
	}

	echo '<form method="post">';
	wp_nonce_field( 'dhr-cats' );
	printf(
		'<table class="form-table" style="max-width:860px"><tbody><tr><th scope="row"><label for="dhr_cat_name">분류 이름</label></th>'
		. '<td><input type="text" id="dhr_cat_name" name="dhr_cat_name" value="%s" class="regular-text">'
		. '<p class="description">없으면 새로 만듭니다. 있으면 그 분류에 넣습니다.</p></td></tr></tbody></table>',
		esc_attr( $name )
	);
	echo '<h2>상품 목록</h2>';
	echo '<p class="description">한 줄에 하나. 줄의 <b>낱말이 모두 들어 있는</b> 상품을 찾습니다 '
		. '(<code>노보 블랙 데저트</code>). <code>#</code> 로 시작하는 줄은 건너뜁니다. '
		. '정확히 집으려면 <code>id:254</code> 처럼 번호로 적습니다.</p>';
	printf(
		'<textarea name="dhr_cat_list" rows="16" style="width:100%%;max-width:860px;font-family:monospace">%s</textarea>',
		esc_textarea( $raw )
	);

	echo '<p style="margin-top:1.2em"><input type="hidden" name="dhr_cat_do" value="preview">';
	submit_button( '미리 보기', 'secondary', 'dhr_cat_preview', false );
	echo ' ';
	printf(
		'<button type="submit" name="dhr_cat_do" value="apply" class="button button-primary"%s>%s</button>',
		$ok ? '' : ' disabled',
		$ok ? esc_html( sprintf( '%d개 넣기', count( $ok ) ) ) : '넣기'
	);
	echo ' <button type="submit" name="dhr_cat_do" value="detach" class="button" '
		. 'onclick="return confirm(\'이 분류에서 상품을 모두 뺍니다. 원래 분류는 그대로 남습니다.\')">이 분류에서 전부 빼기</button>';
	echo '</p>';

	if ( $rows ) {
		printf( '<h2>미리 보기 — 맞음 %d줄</h2>', count( $ok ) );
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th style="width:220px">적은 것</th><th>찾은 상품</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$cell = '';
			if ( 'one' === $r['state'] ) {
				$id   = (int) $r['ids'][0];
				$cell = sprintf( '<span style="color:#1F5F46">✔</span> #%d %s', $id, esc_html( $books[ $id ] ?? '' ) );
			} elseif ( 'many' === $r['state'] ) {
				$list = array();
				foreach ( array_slice( $r['ids'], 0, 6 ) as $id ) {
					$list[] = sprintf( '<code>id:%d</code> %s', (int) $id, esc_html( $books[ (int) $id ] ?? '' ) );
				}
				$cell = '<b style="color:#B54708">여럿(' . count( $r['ids'] ) . ') — 건너뜁니다.</b> 한 줄을 아래 중 하나로 바꿔 주세요:<br>' . implode( '<br>', $list );
			} else {
				$cell = '<b style="color:#B42318">못 찾음 — 건너뜁니다.</b>'
					. ( '' !== $r['near'] ? ' 비슷한 것: ' . esc_html( $r['near'] ) : '' );
			}
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $r['line'] ), wp_kses_post( $cell ) );
		}
		echo '</tbody></table>';
	}
	echo '</form></div>';
}
