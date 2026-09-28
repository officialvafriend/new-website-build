<?php
/**
 * 검색 노출 — 네이버 서치어드바이저 진단(2026-09-26)에 걸린 자리 (2026-09-28).
 *
 * 진단이 말한 것: 메타 설명 누락 5 · H1 2개 이상 1 · Alt 속성 누락 57 · 리다이렉션 1 · 접근 불가 3 ·
 * meta robots 색인제외 30. 사이트맵 462개 주소를 전부 받아 세어 보니 원인은 넷이다.
 *
 * | 무엇 | 어디 | 여기서 하는 일 |
 * |---|---|---|
 * | 메타 설명 없음 | `/shop/` · 로그인 · 가입 · 공지 · 팁 등 페이지 18장 | 전체 상품 화면은 상품 수를 읽어 제목 · 설명을 짓고, 페이지는 슬러그별 글 → 없으면 제목으로 엮는다 |
 * | 사이트맵의 쓰레기 | kboard 212개 중 **210개가 1:1 문의**(로그인해야 읽는 글) · 결제 · 마이페이지처럼 302 로 튕기는 페이지 | 거래 · 계정 · 비공개 게시판 · 시험 페이지는 `noindex` + 사이트맵에서 뺀다 |
 * | alt 없는 이미지 | 상품 설명의 `fr-dib`(아임웹에서 옮겨 온 에디터 이미지) 219개 · 갤러리 `alt=""` 100개 | 설명 이미지는 「상품명 상세 이미지 N」, 첨부 이미지는 상품명으로 채운다 |
 * | H1 2개 | kboard 글 화면 — 테마 `wd-page__title` + kboard 글 제목 | **안 건드린다** (테마 · 플러그인 마크업) |
 *
 * 테마 · AIOSEO · kboard · 키플 파일은 건드리지 않는다. 전부 필터 위에서 한다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Seo\Pages;

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────── 색인하지 않을 페이지 ───────────────────────────── */

/**
 * 색인하지 않을 페이지 슬러그. 거래(결제 · 장바구니) · 계정(마이페이지 · 정보수정 · 탈퇴 · 적립금 · 쿠폰함) ·
 * 가입 2 · 3단계(약관 · 정보입력 — 1단계 `/register/` 는 남긴다, 「액상덕후 회원가입」으로 찾아오는 사람이 있다) ·
 * 회원만 읽는 1:1 문의 · 시험 페이지. 검색 결과에 나와도 누를 이유가 없고 비로그인 크롤러에게는
 * 302 나 「로그인 하셔야」 안내만 보이는 화면들이다.
 *
 * @return string[]
 */
function private_slugs(): array {
	return array_values(
		array_unique(
			array_map(
				'rawurldecode',
				(array) apply_filters(
					'duckhoo_noindex_slugs',
					array(
						'checkout',
						'cart',
						'mypage',
						'my-account',
						'profile-edit',
						'membership-cancel',
						'point',
						'coupon',
						'join-form',
						'agree',
						'inquiries',
						'본인인증테스트',
						'behind-the-product-fedora-hat',   // 테마 데모 글 (지우지 않고 색인만 뺀다)
						'uncategorized',                   // 그 글 하나뿐인 분류
					)
				)
			)
		)
	);
}

/**
 * 이 슬러그가 색인 제외 대상인가.
 *
 * @param string $slug 슬러그 (인코딩돼 있어도 된다).
 * @return bool
 */
function is_private_slug( string $slug ): bool {
	return '' !== $slug && in_array( rawurldecode( $slug ), private_slugs(), true );
}

/**
 * 지금 화면의 페이지 슬러그. 페이지가 아니면 빈 문자열.
 *
 * @return string
 */
function page_slug(): string {
	if ( ! function_exists( 'is_page' ) || ! is_page() || ! function_exists( 'get_queried_object' ) ) {
		return '';
	}
	$o = get_queried_object();
	return is_object( $o ) && isset( $o->post_name ) ? (string) $o->post_name : '';
}

/**
 * 지금 화면에 noindex 를 붙여야 하는가 — 위 페이지들과 날짜 보관함(`/2026/`, 글이 하나뿐인 블로그).
 *
 * @return bool
 */
function noindex_here(): bool {
	if ( is_private_slug( page_slug() ) ) {
		return true;
	}
	if ( is_private_slug( post_slug() ) ) {
		return true;   // 테마 데모 글 — 사장님이 지우기 불안해하셔서 지우지 않고 색인만 뺀다 (2026-09-28)
	}
	if ( function_exists( 'is_category' ) && is_category() && in_array( 'uncategorized', private_slugs(), true ) ) {
		return true;   // 그 데모 글 하나뿐인 분류 보관함
	}
	return function_exists( 'is_date' ) && is_date();
}

/**
 * 지금 화면의 글(post) 슬러그. 글이 아니면 빈 문자열.
 *
 * @return string
 */
function post_slug(): string {
	if ( ! function_exists( 'is_singular' ) || ! is_singular( 'post' ) || ! function_exists( 'get_queried_object' ) ) {
		return '';
	}
	$o = get_queried_object();
	return is_object( $o ) && isset( $o->post_name ) ? (string) $o->post_name : '';
}

/**
 * AIOSEO 의 robots 메타. 값은 `['noindex' => 'noindex', …]` 꼴.
 *
 * @param mixed $r 지금 값.
 * @return array
 */
function robots_aioseo( $r ) {
	if ( ! noindex_here() ) {
		return $r;
	}
	$r            = is_array( $r ) ? $r : array();
	$r['noindex'] = 'noindex';
	unset( $r['index'] );
	return $r;
}
add_filter( 'aioseo_robots_meta', __NAMESPACE__ . '\\robots_aioseo', 20 );

/**
 * 워드프레스 기본 robots (AIOSEO 가 없을 때의 뒷받침). 값은 `['noindex' => true, …]` 꼴.
 *
 * @param mixed $r 지금 값.
 * @return array
 */
function robots_wp( $r ) {
	if ( ! noindex_here() ) {
		return $r;
	}
	$r            = is_array( $r ) ? $r : array();
	$r['noindex'] = true;
	return $r;
}
add_filter( 'wp_robots', __NAMESPACE__ . '\\robots_wp', 20 );

/**
 * 사이트맵 항목의 주소에서 경로(앞뒤 슬래시 뗀 것)를 읽는다. `https://duck-hoo.com/checkout/` → `checkout`.
 *
 * @param mixed $entry 항목 (배열 `loc`/`url` 또는 문자열).
 * @return string
 */
function entry_path( $entry ): string {
	$loc  = is_array( $entry ) ? (string) ( $entry['loc'] ?? $entry['url'] ?? '' ) : (string) $entry;
	$path = (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $loc, PHP_URL_PATH ) : parse_url( $loc, PHP_URL_PATH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	return rawurldecode( trim( $path, '/' ) );
}

/**
 * 페이지 사이트맵 항목에서 색인 제외 페이지를 뺀다 — 순수 함수.
 *
 * @param array $entries 항목들.
 * @return array
 */
function drop_private_pages( array $entries ): array {
	return array_values(
		array_filter(
			$entries,
			function ( $e ) {
				$path = entry_path( $e );
				$last = '' !== $path ? (string) substr( strrchr( '/' . $path, '/' ), 1 ) : '';   // 글은 `2026/04/09/<slug>`, 분류는 `category/<slug>` — 마지막 조각을 본다
				return ! is_private_slug( $path ) && ! is_private_slug( $last );
			}
		)
	);
}

/**
 * AIOSEO 가 글 타입 하나의 사이트맵을 다 만든 뒤 묻는 자리 (`aioseo_sitemap_posts`, `( $entries, $post_type )`).
 * 페이지는 색인 제외 슬러그를, kboard(글마다 숨은 글이 하나씩 있는 타입 — 주소가
 * `?kboard_content_redirect=<uid>`)는 비공개 게시판 글을 뺀다. 나머지 타입은 그대로.
 *
 * `aioseo_sitemap_exclude_posts` 라는 필터는 **없다** — 처음 그 이름으로 걸어 한 번 헛돌았다.
 * AIOSEO 공개 소스(`app/Common/Sitemap/Content.php`)에서 확인한 이름만 쓴다.
 *
 * @param mixed  $entries 항목들.
 * @param string $type    글 타입.
 * @return mixed
 */
function sitemap_posts( $entries, $type = '' ) {
	if ( ! is_array( $entries ) ) {
		return $entries;
	}
	if ( 'page' === (string) $type || 'post' === (string) $type ) {
		return drop_private_pages( $entries );
	}
	if ( 'kboard' === (string) $type ) {
		return drop_private( $entries, private_uids() );
	}
	return $entries;
}
add_filter( 'aioseo_sitemap_posts', __NAMESPACE__ . '\\sitemap_posts', 20, 2 );

/**
 * 분류 사이트맵(`aioseo_sitemap_terms`)에서도 같은 슬러그를 뺀다 — `/category/uncategorized/`.
 *
 * @param mixed $entries 항목들.
 * @return mixed
 */
function sitemap_terms( $entries ) {
	return is_array( $entries ) ? drop_private_pages( $entries ) : $entries;
}
add_filter( 'aioseo_sitemap_terms', __NAMESPACE__ . '\\sitemap_terms', 20 );

/* ───────────────────────────── kboard 사이트맵 ───────────────────────────── */

/**
 * 회원만 읽는 kboard 게시판 번호. 기본은 `/inquiries/` 본문의 `[kboard id=N]`.
 *
 * @return int[]
 */
function private_boards(): array {
	$ids  = array();
	$page = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'inquiries' ) : null;
	if ( is_object( $page ) && isset( $page->post_content ) && preg_match( '/\[kboard[^\]]*\bid\s*=\s*"?(\d+)/', (string) $page->post_content, $m ) ) {
		$ids[] = (int) $m[1];
	}
	return array_values( array_unique( array_map( 'intval', (array) apply_filters( 'duckhoo_kboard_private_boards', $ids ) ) ) );
}

/**
 * 그 게시판들의 글 번호(uid). 표가 없거나 읽을 수 없으면 빈 배열 — 그때는 아무것도 빼지 않는다.
 *
 * @return int[]
 */
function private_uids(): array {
	global $wpdb;
	$boards = private_boards();
	if ( ! $boards || ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
		return array();
	}
	$key = 'dhr_kb_private_uids';
	$hit = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$t = $wpdb->prefix . 'kboard_board_content';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 표 이름은 prefix, 번호는 정수로 걸렀다.
	$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$t}`" );
	if ( ! in_array( 'uid', $cols, true ) || ! in_array( 'board_id', $cols, true ) ) {
		return array();
	}
	$in = implode( ',', array_map( 'intval', $boards ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$uids = array_map( 'intval', (array) $wpdb->get_col( "SELECT uid FROM `{$t}` WHERE board_id IN ({$in})" ) );
	set_transient( $key, $uids, 10 * MINUTE_IN_SECONDS );
	return $uids;
}

/**
 * 사이트맵 항목의 주소에서 kboard 글 번호를 읽는다. 없으면 0.
 *
 * @param mixed $entry 항목 (배열 `loc`/`url` 또는 문자열).
 * @return int
 */
function entry_uid( $entry ): int {
	$loc = is_array( $entry ) ? (string) ( $entry['loc'] ?? $entry['url'] ?? '' ) : (string) $entry;
	return preg_match( '/kboard_content_redirect=(\d+)/', $loc, $m ) ? (int) $m[1] : 0;
}

/**
 * kboard 사이트맵에서 비공개 게시판의 글을 뺀다 — 순수 함수. 테스트가 이것을 본다.
 *
 * @param array $entries 항목들.
 * @param int[] $uids    뺄 글 번호.
 * @return array
 */
function drop_private( array $entries, array $uids ): array {
	if ( ! $uids ) {
		return $entries;
	}
	$set = array_fill_keys( array_map( 'intval', $uids ), true );
	return array_values(
		array_filter(
			$entries,
			function ( $e ) use ( $set ) {
				$uid = entry_uid( $e );
				return 0 === $uid || ! isset( $set[ $uid ] );
			}
		)
	);
}


/* ───────────────────────────── 제목 · 설명 ───────────────────────────── */

/**
 * 가게 이름.
 *
 * @return string
 */
function shop_name(): string {
	$n = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
	return '' !== $n ? $n : '액상덕후';
}

/**
 * 가입 적립금 · 무료배송 기준 — seo.php 의 것을 쓴다.
 *
 * @return array{points:string,ship:string}
 */
function perks(): array {
	$pts  = function_exists( 'Duckhoo\\Redesign\\Seo\\signup_points' ) ? \Duckhoo\Redesign\Seo\signup_points() : 8800;
	$ship = function_exists( 'Duckhoo\\Redesign\\Seo\\free_ship' ) ? \Duckhoo\Redesign\Seo\free_ship() : 30000;
	return array(
		'points' => number_format( (float) $pts ),
		'ship'   => number_format( (float) $ship ),
	);
}

/**
 * 공개된 상품 수.
 *
 * @return int
 */
function product_count(): int {
	if ( ! function_exists( 'wp_count_posts' ) ) {
		return 0;
	}
	$c = wp_count_posts( 'product' );
	return is_object( $c ) ? (int) ( $c->publish ?? 0 ) : 0;
}

/**
 * 주력 브랜드 이름 (front.php 의 순서).
 *
 * @return string[]
 */
function brand_names(): array {
	if ( ! function_exists( 'Duckhoo\\Redesign\\Front\\featured_brands' ) ) {
		return array( '노보', '펠릭스', '화이트아웃', '디오리퀴드' );
	}
	$out = array();
	foreach ( (array) \Duckhoo\Redesign\Front\featured_brands( 4 ) as $k => $v ) {
		$name = is_array( $v ) ? (string) ( $v['name'] ?? $v[0] ?? '' ) : ( is_string( $k ) && ! is_numeric( $k ) ? $k : (string) $v );
		if ( '' !== $name ) {
			$out[] = $name;
		}
	}
	return $out ? array_slice( $out, 0, 4 ) : array( '노보', '펠릭스', '화이트아웃', '디오리퀴드' );
}

/**
 * 전체 상품 화면(`/shop/`)의 제목. AIOSEO 기본 「상점 - 액상덕후」에는 무엇을 파는지가 없었다.
 *
 * @param int $n 상품 수.
 * @return string
 */
function shop_title( int $n ): string {
	return '전자담배 액상 전체 상품' . ( $n > 0 ? ' ' . $n . '종' : '' ) . ' | ' . shop_name();
}

/**
 * 전체 상품 화면의 메타 설명. 브랜드 · 종수 · 혜택 · 19세 — 값은 그때그때 읽는다.
 *
 * @param int      $n      상품 수.
 * @param string[] $brands 브랜드 이름.
 * @return string
 */
function shop_desc( int $n, array $brands ): string {
	$p = perks();
	$b = $brands ? implode( ' · ', $brands ) . ' 등 ' : '';
	return $b . '입호흡 · 폐호흡 액상' . ( $n > 0 ? ' ' . $n . '종' : '' ) . '을 한 화면에서. '
		. $p['ship'] . '원 이상 무료배송, 가입 즉시 ' . $p['points'] . '원 적립. 19세 이상 본인확인 회원만 구매할 수 있습니다.';
}

/**
 * 슬러그별 페이지 설명. 없는 슬러그는 제목으로 엮는다 (`page_desc`).
 *
 * @return array<string,string>
 */
function page_texts(): array {
	$p = perks();
	return (array) apply_filters(
		'duckhoo_page_desc_map',
		array(
			'login'    => '액상덕후 로그인 — 주문내역 · 적립금 · 배송지를 한곳에서 확인합니다. 회원이 아니면 가입 즉시 ' . $p['points'] . '원 적립.',
			'register' => '액상덕후 회원가입 — 휴대폰 본인확인(19세 이상) 1분, 가입 즉시 ' . $p['points'] . '원 적립. 전자담배 액상 전문몰.',
			'notice'   => '액상덕후 공지사항 — 출고 · 배송 일정, 상품 입고와 가격 변동 안내.',
			'news'     => '액상덕후 새 소식 — 신상품 입고와 진행 중인 이벤트.',
			'tip'      => '액상덕후 신상 소식 · 팁 — 매주 들어오는 새 액상과 기기, 보관 · 사용 안내.',
			'event'    => '액상덕후 이벤트 — 진행 중인 할인 · 적립 행사.',
			'reviews'  => '액상덕후 상품 후기 — 구매 고객이 남긴 액상 · 기기 후기.',
			'faq'      => '액상덕후 자주 묻는 질문 — 주문 · 입금 · 배송 · 교환 · 회원가입.',
			'shipping' => '액상덕후 배송 · 교환 · 환불 안내 — 출고 시간, 배송비, 교환 · 환불 조건.',
		)
	);
}

/**
 * 페이지 메타 설명 — 순수 함수. 슬러그 글이 있으면 그것, 없으면 제목으로 엮는다.
 *
 * @param string $slug  슬러그.
 * @param string $title 페이지 제목.
 * @return string
 */
function page_desc( string $slug, string $title ): string {
	$slug = rawurldecode( $slug );
	$map  = page_texts();
	if ( isset( $map[ $slug ] ) && '' !== trim( (string) $map[ $slug ] ) ) {
		return trim( (string) $map[ $slug ] );
	}
	$p = perks();
	$t = trim( wp_strip_all_tags( $title ) );
	return ( '' !== $t ? $t . ' — ' : '' ) . shop_name() . ', 전자담배 액상 전문몰. '
		. $p['ship'] . '원 이상 무료배송 · 가입 즉시 ' . $p['points'] . '원 적립.';
}

/**
 * 메타 설명 필터 — seo.php(20) 뒤(25)에서, **비어 있을 때만** 채운다.
 *
 * @param mixed $d 지금 값.
 * @return string
 */
function description( $d ): string {
	$d = trim( (string) $d );
	if ( '' !== $d ) {
		return $d;
	}
	if ( function_exists( 'is_shop' ) && is_shop() && ! ( function_exists( 'is_search' ) && is_search() ) ) {
		return shop_desc( product_count(), brand_names() );
	}
	$slug = page_slug();
	if ( '' !== $slug && ! is_private_slug( $slug ) ) {
		$o = get_queried_object();
		return page_desc( $slug, (string) ( $o->post_title ?? '' ) );
	}
	return $d;
}
add_filter( 'aioseo_description', __NAMESPACE__ . '\\description', 25 );
add_filter( 'aioseo_og_description', __NAMESPACE__ . '\\description', 25 );
add_filter( 'aioseo_twitter_description', __NAMESPACE__ . '\\description', 25 );

/**
 * 제목 필터 — 전체 상품 화면만. 나머지는 그대로.
 *
 * @param mixed $t 지금 값.
 * @return string
 */
function title( $t ): string {
	if ( function_exists( 'is_shop' ) && is_shop() && ! ( function_exists( 'is_search' ) && is_search() ) ) {
		return shop_title( product_count() );
	}
	return (string) $t;
}
add_filter( 'aioseo_title', __NAMESPACE__ . '\\title', 25 );
add_filter( 'pre_get_document_title', __NAMESPACE__ . '\\title', 25 );

/* ───────────────────────────── 이미지 alt ───────────────────────────── */

/**
 * `<img>` 에 alt 가 없거나 비어 있으면 「이름 상세 이미지 N」을 붙인다 — 순수 함수. 상품 설명은
 * 아임웹에서 옮겨 온 에디터 이미지(alt 없음)와 워드프레스 이미지 블록(`alt=""`)이 섞여 있는데
 * 둘 다 사장님이 올린 상품 사진이라 장식용이 아니다 — 빈 것도 채운다. 글자가 있는 alt 는 그대로.
 *
 * @param string $html 설명 HTML.
 * @param string $name 상품 이름.
 * @return string
 */
function img_alt( string $html, string $name ): string {
	$name = trim( wp_strip_all_tags( $name ) );
	if ( '' === $html || '' === $name ) {
		return $html;
	}
	$i = 0;
	return (string) preg_replace_callback(
		'/<img\b([^>]*?)(\s*\/?)>/i',
		function ( $m ) use ( $name, &$i ) {
			$attrs = $m[1];
			if ( preg_match( '/\salt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $attrs, $a ) ) {
				if ( '' !== trim( $a[1], '"\'' ) ) {
					return $m[0];   // 글자가 있는 alt 는 그대로
				}
				$attrs = preg_replace( '/\salt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attrs, 1 );   // 빈 alt 는 떼고 다시 붙인다
			}
			++$i;
			return '<img' . rtrim( (string) $attrs ) . ' alt="' . esc_attr( $name . ' 상세 이미지 ' . $i ) . '"' . $m[2] . '>';
		},
		$html
	);
}

/**
 * 첨부 이미지의 빈 alt — 상품 사진이면 상품 이름으로. 관리자 화면은 그대로.
 *
 * @param mixed $attr       속성 배열.
 * @param mixed $attachment 첨부 글.
 * @return mixed
 */
function attachment_alt( $attr, $attachment = null ) {
	if ( ! is_array( $attr ) || '' !== trim( (string) ( $attr['alt'] ?? '' ) ) ) {
		return $attr;
	}
	if ( function_exists( 'is_admin' ) && is_admin() ) {
		return $attr;
	}
	$name = '';
	$g    = $GLOBALS['product'] ?? null;
	if ( is_object( $g ) && method_exists( $g, 'get_name' ) ) {
		$name = (string) $g->get_name();
	}
	if ( '' === $name && is_object( $attachment ) && ! empty( $attachment->post_parent ) && function_exists( 'get_post_type' ) && 'product' === get_post_type( (int) $attachment->post_parent ) && function_exists( 'wc_get_product' ) ) {
		$p    = wc_get_product( (int) $attachment->post_parent );
		$name = is_object( $p ) && method_exists( $p, 'get_name' ) ? (string) $p->get_name() : '';
	}
	if ( '' !== $name ) {
		$attr['alt'] = wp_strip_all_tags( $name );
	}
	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', __NAMESPACE__ . '\\attachment_alt', 20, 2 );
