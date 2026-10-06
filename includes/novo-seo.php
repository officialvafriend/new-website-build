<?php
/**
 * 노보 품절 국면 — 검색에서 더 먹을 자리 셋 (2026-10-01).
 *
 * 네이버 웹문서에서 노보 맛 이름 검색은 이미 1~3위다. 남은 자리는
 *  ① 구글 — 노보 상품 상세가 17~36위. 상품끼리 서로 잇는 링크(맛 이름이 앵커)가 없다
 *  ② 「노보 액상 품절 · 단종 · 어디서 · 사는곳」 류 — 블로그 · 뉴스가 1위고 우리 페이지에 그 말이 없다
 *  ③ 날짜가 있는 글 — 네이버는 「지금 재고 있다」를 날짜 있는 글에서 더 믿는다
 *
 * 전부 **우리 재고 이야기만** 한다 — 다른 가게 · 품절 소식은 적지 않는다 (사장님 2026-09-21).
 * 건강 · 금연 · 순하다 · 해롭지 않다 는 쓰지 않는다 (담배사업법). 글자는 `form.cart` 바깥에만.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Novo\Seo;

use function Duckhoo\Redesign\Novo\{is_novo, line, bottles, price_lines, stock_state, stock_phrase, out_note};
use function Duckhoo\Redesign\Front\{products, split_name, cat_by_name, signup_points, ship_rule_short};
use function Duckhoo\Redesign\Seo\free_ship;

defined( 'ABSPATH' ) || exit;

const NOTICE_OPTION = 'duckhoo_novo_notice_post';

/**
 * 라인 이름표 — 「노보」 · 「노보 블랙」.
 *
 * @param \WC_Product $p 상품.
 * @return string
 */
function line_label( \WC_Product $p ): string {
	return 'black' === line( $p ) ? '노보 블랙' : '노보';
}

/**
 * 맛 이름 — 「[노보 블랙] 쿠바시가 (9.8mg / 30ml)」 → 「쿠바시가」, 「[노보 리퀴드] 10+1 | 금액 …」 → 「10+1 묶음」.
 *
 * @param \WC_Product $p 상품.
 * @return string
 */
function flavor( \WC_Product $p ): string {
	$t = split_name( $p )['title'];
	$t = (string) preg_replace( '/\s*\([^)]*\)\s*|\s*\|\s*금액.*$/u', '', $t );
	$t = trim( $t );
	if ( bottles( $p ) > 1 && false === mb_strpos( $t, '묶음' ) ) {
		$t .= ' 묶음';
	}
	return $t;
}

/**
 * 이 상품의 형제들 — 재고 있는 노보 상품 전부에서 자기 자신을 뺀 것.
 * 같은 라인 낱병 → 다른 라인 낱병 → 묶음 순. 결제용 · 부속은 뺀다.
 *
 * @param \WC_Product $me 지금 상품.
 * @return \WC_Product[]
 */
function siblings( \WC_Product $me ): array {
	$all = array();
	foreach ( products( array( 'limit' => -1 ) ) as $p ) {
		if ( ! $p instanceof \WC_Product || (int) $p->get_id() === (int) $me->get_id() ) {
			continue;
		}
		if ( ! is_novo( $p ) || ! $p->is_in_stock() || preg_match( '/결제|드립팁|첨가제|코일|팟\b/u', (string) $p->get_name() ) ) {
			continue;
		}
		$all[] = $p;
	}
	$mine = line( $me );
	$rank = static function ( \WC_Product $p ) use ( $mine ): int {
		if ( bottles( $p ) > 1 ) {
			return 2;
		}
		return line( $p ) === $mine ? 0 : 1;
	};
	usort( $all, static fn( $a, $b ) => $rank( $a ) <=> $rank( $b ) ?: strcmp( (string) $a->get_name(), (string) $b->get_name() ) );
	return $all;
}

/**
 * 상품 상세 — 「노보 다른 맛 보기」. 가격 아래 · 혜택 줄 위, `form.cart` 바깥.
 * 앵커가 「노보 블랙 쿠바시가」처럼 맛 이름이라 검색엔진이 상품끼리 잇는 글자로 읽는다.
 *
 * @param mixed $product 상품.
 * @return void
 */
function flavor_links( $product = null ): void {
	$p = $product instanceof \WC_Product ? $product : ( $GLOBALS['product'] ?? null );
	if ( ! $p instanceof \WC_Product || ! is_novo( $p ) || ! apply_filters( 'duckhoo_novo_flavor_links', true ) ) {
		return;
	}
	// 2026-10-06 — 맛 이름 14개를 글자로 늘어놓자 타박멘솔 · 아메리카노 상품이 네이버에서 15위 밖으로 떨어졌다
	// (상품끼리 서로 잡아먹음 — 「노보 타박멘솔」에 블랙멘솔 페이지가 올라옴). 이름은 빼고 노보 전체 보기 링크 하나만 남긴다.
	// 맛 이름 목록을 도로 그리려면 duckhoo_novo_flavor_names 를 true 로.
	$names = (bool) apply_filters( 'duckhoo_novo_flavor_names', false );
	$sib   = $names ? siblings( $p ) : array();
	$cat   = function_exists( '\\Duckhoo\\Redesign\\Front\\cat_by_name' ) ? cat_by_name( '노보' ) : null;
	$more  = $cat && function_exists( 'get_term_link' ) ? get_term_link( $cat ) : '';
	if ( ! $sib && ( ! is_string( $more ) || '' === $more ) ) {
		return;
	}
	echo '<nav class="dhp-flav" aria-label="노보 다른 맛">';
	if ( $sib ) {
		echo '<p class="dhp-flav__t">노보 다른 맛 보기 <small>' . esc_html( stock_state()['all'] ? '전 라인 재고 있음' : '재고 있는 맛' ) . '</small></p><ul class="dhp-flav__list">';
		foreach ( $sib as $s ) {
			$label = line_label( $s ) . ' ' . flavor( $s );
			echo '<li><a href="' . esc_url( get_permalink( $s->get_id() ) ) . '">' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul>';
	}
	if ( is_string( $more ) && '' !== $more ) {
		echo '<a class="dhp-flav__more" href="' . esc_url( $more ) . '">노보 다른 맛 · 전체 보기 →</a>';
	}
	echo '</nav>';
}
add_action( 'duckhoo_product_after_price', __NAMESPACE__ . '\\flavor_links', 35 );

/**
 * 재고 있는 노보 낱병 맛을 라인별로 — 「노보: 블랙멘솔 · 타박멘솔 …」.
 *
 * @return array<string,string[]> 라인 이름표 => 맛 목록.
 */
function flavors_by_line(): array {
	$out = array( '노보' => array(), '노보 블랙' => array() );
	foreach ( products( array( 'limit' => -1 ) ) as $p ) {
		if ( ! $p instanceof \WC_Product || ! is_novo( $p ) || ! $p->is_in_stock() || bottles( $p ) > 1 ) {
			continue;
		}
		if ( preg_match( '/결제|드립팁|첨가제|코일|팟\b/u', (string) $p->get_name() ) ) {
			continue;
		}
		$out[ line_label( $p ) ][] = flavor( $p );
	}
	foreach ( $out as $k => $v ) {
		$v         = array_values( array_unique( $v ) );
		sort( $v );
		$out[ $k ] = $v;
	}
	return array_filter( $out );
}

/**
 * 자주 묻는 질문 — 사실만. 값 · 종수는 상품에서 그때그때 읽는다 (적어 두면 거짓이 된다).
 * 「품절 · 단종 · 어디서 · 사는 곳」은 손님이 검색창에 치는 말 그대로 질문에 둔다 — 답은 우리 재고 얘기뿐.
 *
 * @return array<int,array{q:string,a:string}>
 */
function faq(): array {
	$lines  = flavors_by_line();
	$n      = array_sum( array_map( 'count', $lines ) );
	$prices = array();
	foreach ( price_lines() as $r ) {
		$prices[] = $r['label'] . ' ' . number_format_i18n( (float) $r['price'] ) . '원';
	}
	$line_cnt = array();
	foreach ( $lines as $k => $v ) {
		$line_cnt[] = $k . ' ' . count( $v ) . '종';
	}
	$items = array(
		array(
			'q' => '노보 액상 재고 있나요? 품절 아닌가요?',
			'a' => '네, 액상덕후는 노보(NOVO) · 노보 블랙 ' . stock_phrase( 'noun' ) . '을 재고로 보유하고 있습니다'
				. ( $n ? ' — 낱병 ' . $n . '종과 10+1 묶음' : '' ) . '. ' . ( out_note() ? out_note() . '. ' : '' ) . '품절되는 맛이 생기면 이 페이지의 상품에 「품절」로 표시되고, 그 전까지는 지금 바로 주문하실 수 있습니다.',
		),
		array(
			'q' => '노보 액상 어디서 살 수 있나요? 사는 곳을 찾고 있어요.',
			'a' => '이 페이지에서 바로 주문하실 수 있습니다. 최근 노보 액상 품절 · 단종 문의가 늘어 재고 상황을 여기에 적어 둡니다 — 액상덕후 재고는 실시간으로 반영됩니다. '
				. '19세 이상 본인확인 회원만 구매할 수 있고, 가입 즉시 ' . number_format_i18n( signup_points() ) . '원이 적립됩니다.',
		),
		array(
			'q' => '노보 액상 가격은 얼마인가요?',
			'a' => ( $prices ? '지금 판매가는 ' . implode( ' · ', $prices ) . ' 입니다. ' : '판매가는 상품 페이지에 실시간으로 표시됩니다. ' )
				. '10병 이상 사실 계획이면 10+1 묶음(11병)이 병당 더 저렴하고, 맛은 묶음 안에서 골라 담으실 수 있습니다.',
		),
		array(
			// 2026-10-06 — 「노보 액상 종류」는 손님이 치는 말 그대로. 맛 이름은 여기 늘어놓지 않는다 — 각 맛은 그 상품 페이지
			// 한 장이 말하고(위 격자의 상품 이름이 곧 목록이다), 같은 낱말을 여러 페이지에 깔면 네이버에서는 나뉜다 (10/6 교훈).
			'q' => '노보 액상 종류는 몇 가지인가요? 노보와 노보 블랙은 어떻게 다른가요?',
			'a' => ( $line_cnt ? '노보 액상 종류는 ' . implode( ' · ', $line_cnt ) . ' — 낱병 ' . $n . '종과 10+1 묶음입니다. 맛별 상품은 위 목록에서 고르시면 됩니다. ' : '' )
				. '두 라인 모두 30ml · 니코틴 9.8mg · 입호흡(MTL) 기기용이며, 노보 블랙은 같은 맛 이름이라도 타격감과 풍미가 더 진한 쪽입니다.',
		),
		array(
			'q' => '언제 출고되나요?',
			'a' => '평일 오후 4시 이전에 입금이 확인된 주문은 당일 출고합니다. ' . ship_rule_short() . '. 무통장입금이라 입금자명을 주문자명과 같게 넣어 주시면 자동으로 확인됩니다. '
				. number_format_i18n( free_ship() ) . '원 이상 무료배송입니다.',
		),
	);
	return (array) apply_filters( 'duckhoo_novo_faq', $items );
}

/**
 * 노보 분류 페이지인가 (FAQ 를 그리는 자리).
 *
 * @return bool
 */
function on_novo_cat(): bool {
	if ( ! function_exists( '\\Duckhoo\\Redesign\\Seo\\noted_cat' ) ) {
		return false;
	}
	$t = \Duckhoo\Redesign\Seo\noted_cat();
	return is_object( $t ) && 'novo-liquid' === (string) ( $t->slug ?? '' );
}

/**
 * FAQ HTML — 상품 격자 **아래**에 그린다 (상품 위에 글이 길게 깔리면 안 된다 — 사장님 2026-09-04).
 *
 * @return void
 */
function faq_html(): void {
	if ( ! on_novo_cat() ) {
		return;
	}
	$items = faq();
	if ( ! $items ) {
		return;
	}
	echo '<section class="dha-faq" aria-labelledby="dha-faq-h"><h2 id="dha-faq-h">노보 액상 자주 묻는 질문</h2>';
	foreach ( $items as $i => $it ) {
		echo '<details class="dha-faq__i"' . ( 0 === $i ? ' open' : '' ) . '><summary>' . esc_html( (string) $it['q'] ) . '</summary><p>' . esc_html( (string) $it['a'] ) . '</p></details>';
	}
	echo '</section>';
}
add_action( 'duckhoo_archive_after_grid', __NAMESPACE__ . '\\faq_html' );

/**
 * FAQPage 구조화 데이터 — 구글 검색 결과의 질문 펼침용. 같은 FAQ 를 그대로 싣는다.
 *
 * @return void
 */
function faq_jsonld(): void {
	if ( ! on_novo_cat() ) {
		return;
	}
	$items = faq();
	if ( ! $items ) {
		return;
	}
	$data = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array_map(
			static fn( $it ) => array(
				'@type'          => 'Question',
				'name'           => (string) $it['q'],
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => (string) $it['a'] ),
			),
			array_values( $items )
		),
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}
add_action( 'wp_head', __NAMESPACE__ . '\\faq_jsonld', 5 );

/**
 * 날짜 있는 안내 글 — 제목 · 본문. 값 · 맛 목록은 상품에서 읽는다.
 *
 * @param string $ym Y-m (비우면 이번 달).
 * @return array{title:string,content:string}
 */
function notice_text( string $ym = '' ): array {
	$ym    = preg_match( '/^\d{4}-\d{2}$/', $ym ) ? $ym : (string) current_time( 'Y-m' );
	$month = (int) substr( $ym, 5, 2 );
	$year  = (int) substr( $ym, 0, 4 );
	$lines = flavors_by_line();
	$n     = array_sum( array_map( 'count', $lines ) );
	$cat   = function_exists( '\\Duckhoo\\Redesign\\Front\\cat_by_name' ) ? cat_by_name( '노보' ) : null;
	$url   = $cat && function_exists( 'get_term_link' ) && is_string( get_term_link( $cat ) ) ? get_term_link( $cat ) : home_url( '/product-category/novo-liquid/' );

	$title = sprintf( '노보(NOVO) 액상 재고 · 가격 · 구매 안내 (%d년 %d월)', $year, $month );
	$h     = array();
	$h[]   = '<p>최근 노보 액상 품절 · 단종 문의가 많아 액상덕후의 재고 상황을 안내드립니다.</p>';
	$h[]   = '<p><strong>액상덕후는 노보 · 노보 블랙 ' . esc_html( stock_phrase( 'noun' ) ) . '을 재고로 보유하고 있습니다' . ( $n ? ' — 낱병 ' . $n . '종과 10+1 묶음' : '' ) . '.</strong> 지금 바로 주문하실 수 있습니다.' . ( out_note() ? ' ' . esc_html( out_note() ) . '.' : '' ) . '</p>';
	if ( $lines ) {
		$h[] = '<ul>';
		foreach ( $lines as $k => $v ) {
			$h[] = '<li><strong>' . esc_html( $k ) . '</strong> (9.8mg / 30ml) — ' . esc_html( implode( ' · ', $v ) ) . '</li>';
		}
		$h[] = '</ul>';
	}
	$prices = array();
	foreach ( price_lines() as $r ) {
		$prices[] = esc_html( $r['label'] ) . ' ' . number_format_i18n( (float) $r['price'] ) . '원';
	}
	if ( $prices ) {
		$h[] = '<p><strong>지금 판매가</strong>: ' . implode( ' · ', $prices ) . '. 10병 이상 사실 계획이면 10+1 묶음(11병)이 병당 더 저렴하고, 맛은 묶음 안에서 골라 담으실 수 있습니다.</p>';
	}
	$h[] = '<p><strong>출고</strong>: 평일 오후 4시 이전에 입금이 확인된 주문은 당일 출고합니다. ' . esc_html( ship_rule_short() ) . '. 입금자명을 주문자명과 같게 넣어 주시면 자동으로 확인됩니다. ' . number_format_i18n( free_ship() ) . '원 이상 무료배송.</p>';
	$h[] = '<p><strong>구매 조건</strong>: 19세 이상 본인확인 회원만 구매할 수 있습니다. 가입 즉시 ' . number_format_i18n( signup_points() ) . '원이 적립됩니다.</p>';
	$h[] = '<p><a href="' . esc_url( $url ) . '">노보 전체 보기 →</a></p>';
	$h[] = '<p>재고는 실시간으로 바뀝니다. 품절되는 맛이 생기면 이 글 맨 위에 날짜와 함께 적겠습니다.</p>';

	return array( 'title' => $title, 'content' => implode( "\n", $h ) );
}

/**
 * 안내 글을 **초안**으로 한 번 만든다 — 사장님이 읽고 「공개」를 누른다. 글은 우리가 쓰지 않는다.
 * 옵션에 글 번호를 적어 두 번 만들지 않는다.
 *
 * @return void
 */
function ensure_notice(): void {
	if ( ! function_exists( 'wp_insert_post' ) || ! apply_filters( 'duckhoo_novo_notice_draft', true ) ) {
		return;
	}
	$have = (int) get_option( NOTICE_OPTION, 0 );
	if ( $have > 0 ) {
		return;
	}
	$t  = notice_text();
	$id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $t['title'],
			'post_content' => $t['content'],
			'post_name'    => 'novo-liquid-stock-notice-' . (string) current_time( 'Y-m' ),
		)
	);
	if ( is_int( $id ) && $id > 0 ) {
		update_option( NOTICE_OPTION, $id, false );
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\\ensure_notice' );
