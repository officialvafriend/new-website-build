<?php
/**
 * 브랜드 페이지 — 「종류 · 가격」 표 · 자주 묻는 질문 · 날짜 붙은 제목 (2026-10-20).
 *
 * 노보 분류에서 「노보 액상 종류 · 가격 (날짜 기준)」 제목 + 라인별 표 + FAQ 를 붙이자
 * 「노보 액상 가격」이 AI 브리핑 첫 출처로 올라왔다. 같은 날 브랜드 검색을 36개 재 보니
 * 「브랜드 액상」은 1~3위인데 **「브랜드 액상 종류」는 비어 있었다**(디오리퀴드 없음 · 화이트아웃 19위).
 * 그 말을 받을 페이지는 이미 있다 — 브랜드 페이지 `/brand/<slug>/`. 거기에 같은 꼴을 얹는다.
 *
 * - 값 · 종수 · 병 수는 **전부 상품에서** 그때그때 읽는다 (적어 둔 숫자는 거짓이 된다)
 * - 노보는 뺀다 — 노보 분류(`/product-category/novo-liquid/`)가 그 말을 받는 한 장이다.
 *   같은 제목 두 장이면 네이버에서 나뉜다 (2026-10-06 교훈)
 * - 얇은 브랜드(상품 하나)는 noindex 라 뺀다
 * - 표 · FAQ 에 맛 이름을 늘어놓지 않는다 — 맛 검색은 그 상품 한 장이 받는다
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Seo\Brand;

use function Duckhoo\Redesign\Seo\{is_brand_page, current_brand, brand_items, brand_facts, brand_kind, brand_noun, asof, thin_brand, free_ship};
use function Duckhoo\Redesign\Front\{per_bottle, split_name, brands};

defined( 'ABSPATH' ) || exit;

/**
 * 이 브랜드에 표 · FAQ · 제목을 얹는가.
 *
 * @param string $b 브랜드.
 * @return bool
 */
function on( string $b ): bool {
	if ( '' === $b || ! apply_filters( 'duckhoo_brand_seo', true ) ) {
		return false;
	}
	if ( in_array( $b, (array) apply_filters( 'duckhoo_brand_seo_skip', array( '노보' ) ), true ) ) {
		return false;
	}
	return ! thin_brand() && count( brand_items( $b ) ) >= 2;
}

/**
 * 지금 화면이 대상 브랜드 페이지인가.
 *
 * @return string 브랜드 (아니면 '').
 */
function here(): string {
	if ( ! is_brand_page() ) {
		return '';
	}
	$b = current_brand();
	return on( $b ) ? $b : '';
}

/**
 * 원.
 *
 * @param float $v 값.
 * @return string
 */
function won( float $v ): string {
	return number_format( round( $v ) ) . '원';
}

/**
 * 표의 줄 — 낱병 한 줄 + 재고 있는 묶음마다 한 줄. 기기 브랜드는 상품마다 한 줄.
 *
 * @param string $b 브랜드.
 * @return array<int,array{label:string,n:int,price:string,per:string,min:float}>
 */
function rows( string $b ): array {
	$single = array();
	$bund   = array();
	$dev    = 'device' === brand_kind( $b );
	foreach ( brand_items( $b ) as $p ) {
		if ( ! $p->is_in_stock() ) {
			continue;
		}
		$price = (float) $p->get_price();
		if ( $price <= 0 ) {
			continue;
		}
		$nm = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/\([^)]*\)|\[[^\]]*\]|[★!]|이벤트|EVENT/iu', ' ', split_name( $p )['title'] ) ) );
		$nm = trim( preg_replace( '/^(?:' . preg_quote( $b, '/' ) . ')\s*/u', '', $nm ), " ·-|" );
		$nm = trim( preg_replace( '/\|.*$/u', '', $nm ) );   // 「10+1 | 금액 120,000원」 → 「10+1」
		if ( $dev ) {
			$bund[] = array( 'label' => '' !== $nm ? $nm : $b, 'n' => 1, 'price' => won( $price ), 'per' => '—', 'min' => $price );
			continue;
		}
		$pb = per_bottle( $p );
		if ( $pb['qty'] > 1 ) {
			$label  = preg_match( '/(\d+)\s*\+\s*(\d+)/u', $p->get_name(), $m ) ? $m[1] . '+' . $m[2] . ' 묶음' : $pb['qty'] . '병 묶음';
			$bund[] = array(
				'label' => $label,
				'n'     => (int) $pb['qty'],
				'price' => won( $price ),
				'per'   => '약 ' . won( floor( $pb['per'] / 100 ) * 100 ),
				'min'   => $price,
			);
		} else {
			$single[] = $price;
		}
	}
	usort( $bund, fn( $x, $y ) => $x['min'] <=> $y['min'] );
	// 같은 이름 묶음이 여럿이면(맛만 다른 「10병 묶음」) 가장 싼 것 하나만
	$seen = array();
	$bund = array_values( array_filter( $bund, function ( $r ) use ( &$seen ) {
		if ( isset( $seen[ $r['label'] ] ) ) {
			return false;
		}
		return $seen[ $r['label'] ] = true;
	} ) );
	$out = array();
	if ( $single ) {
		$lo    = min( $single );
		$hi    = max( $single );
		$out[] = array(
			'label' => '낱병 ' . count( $single ) . '종',
			'n'     => 1,
			'price' => won( $lo ) . ( $hi > $lo ? '~' . won( $hi ) : '' ),
			'per'   => won( $lo ) . ( $hi > $lo ? '~' . won( $hi ) : '' ),
			'stock' => count( $single ),
			'min'   => $lo,
		);
	}
	return array_merge( $out, array_slice( $bund, 0, 8 ) );
}

/**
 * 「입호흡 · 폐호흡」 같은 분류 낱말.
 *
 * @param array $f brand_facts().
 * @return string
 */
function cat_words( array $f ): string {
	$cw = array();
	foreach ( $f['cats'] as $c ) {
		if ( preg_match( '/입호흡|폐호흡|무니코틴/u', $c, $m ) ) {
			$cw[] = $m[0];
		}
	}
	return implode( ' · ', array_unique( $cw ) );
}

/**
 * 제목 — `디오리퀴드 액상 종류 · 가격 11종 (10월 20일 기준) | 액상덕후`.
 *
 * @param string $b 브랜드.
 * @return string
 */
function title( string $b ): string {
	$n    = brands()[ $b ] ?? count( brand_items( $b ) );
	$what = 'device' === brand_kind( $b ) ? ' 기기 · 팟 가격' : ' 액상 종류 · 가격';
	$lead = lead_bundle( $b );
	if ( $lead ) {
		// 2026-10-30 — 「펠릭스」만 쳐도 묶음을 판다는 것이 검색 결과 제목에서 보이게 (사장님).
		$pb = per_bottle( $lead );
		return $b . ' 액상 ' . $pb['qty'] . '병 묶음 ' . won( (float) $lead->get_price() ) . ' · 종류 · 가격'
			. ( $n ? ' ' . $n . '종' : '' ) . ' (' . asof() . ' 기준) | ' . get_bloginfo( 'name' );
	}
	return $b . $what . ( $n ? ' ' . $n . '종' : '' ) . ' (' . asof() . ' 기준) | ' . get_bloginfo( 'name' );
}

/**
 * 묶음을 앞세울 브랜드 (2026-10-30, 사장님 「펠릭스를 검색하면 5병 묶음을 판다고 보이게」).
 *
 * @return string[]
 */
function lead_brands(): array {
	return (array) apply_filters( 'duckhoo_brand_lead_bundle', array( '펠릭스' ) );
}

/**
 * 그 브랜드의 재고 있는 묶음 중 병당이 가장 싼 것. 앞세울 브랜드가 아니거나 없으면 null.
 *
 * @param string $b 브랜드.
 * @return \WC_Product|null
 */
function lead_bundle( string $b ): ?\WC_Product {
	if ( ! in_array( $b, lead_brands(), true ) ) {
		return null;
	}
	$best = null;
	$bp   = 0.0;
	foreach ( brand_items( $b ) as $p ) {
		if ( ! $p->is_in_stock() || (float) $p->get_price() <= 0 || false !== mb_strpos( $p->get_name(), '대량' ) ) {
			continue;
		}
		$pb = per_bottle( $p );
		if ( $pb['qty'] < 2 ) {
			continue;
		}
		if ( ! $best || $pb['per'] < $bp ) {
			$best = $p;
			$bp   = $pb['per'];
		}
	}
	return $best;
}

/**
 * 묶음 이름의 맛들 — 「더블 라임+라임 알로에 5병 묶음 이벤트」 → [더블 라임, 라임 알로에].
 *
 * @param \WC_Product $p 묶음.
 * @return string[]
 */
function bundle_flavors( \WC_Product $p ): array {
	$t = (string) split_name( $p )['title'];
	$t = (string) preg_replace( '/\([^)]*\)|\s*\d+\s*병.*$/u', '', $t );
	$out = array();
	foreach ( preg_split( '/\s*[+\/,·]\s*/u', $t ) as $f ) {
		$f = trim( (string) $f );
		if ( '' !== $f ) {
			$out[] = $f;
		}
	}
	return $out;
}

/**
 * 브랜드 페이지 격자 위 — 묶음 한 장 (앞세울 브랜드만). `form.cart` 와 무관한 링크 하나다.
 *
 * @return void
 */
function lead_card(): void {
	$b = here();
	if ( '' === $b ) {
		return;
	}
	$p = lead_bundle( $b );
	if ( ! $p ) {
		return;
	}
	$pb  = per_bottle( $p );
	$fl  = bundle_flavors( $p );
	$sub = ( $fl ? implode( ' · ', $fl ) . ' 골라 담기 · ' : '' ) . '병당 약 ' . won( floor( $pb['per'] / 100 ) * 100 );
	echo '<a class="dha-lead" href="' . esc_url( get_permalink( $p->get_id() ) ) . '">'
		. '<span class="dha-lead__eb">' . (int) $pb['qty'] . '병 묶음</span>'
		. '<b class="dha-lead__t">' . esc_html( $b . ' 액상 ' . $pb['qty'] . '병 묶음' ) . '</b>'
		. '<span class="dha-lead__s">' . esc_html( $sub ) . '</span>'
		. '<span class="dha-lead__p">' . esc_html( won( (float) $p->get_price() ) ) . '</span>'
		. '<span class="dha-lead__go">묶음으로 사기</span></a>';
}
add_action( 'duckhoo_archive_before_grid', __NAMESPACE__ . '\\lead_card', 6 );

/**
 * 자주 묻는 질문. 답은 우리 가게 사실뿐 — 다른 가게 · 건강 낱말 없음.
 *
 * @param string $b 브랜드.
 * @return array<int,array{q:string,a:string}>
 */
function faq( string $b ): array {
	$f    = brand_facts( $b );
	$rows = rows( $b );
	$dev  = 'device' === brand_kind( $b );
	$noun = brand_noun( $b );
	$n    = brands()[ $b ] ?? $f['n'];
	$out  = array();
	if ( $rows ) {
		$bits = array();
		foreach ( array_slice( $rows, 0, 4 ) as $r ) {
			$bits[] = $r['label'] . ' ' . $r['price'] . ( $r['n'] > 1 ? '(' . ( str_starts_with( $r['label'], $r['n'] . '병' ) ? '' : $r['n'] . '병, ' ) . '병당 ' . $r['per'] . ')' : '' );
		}
		$out[] = array(
			'q' => $b . ' ' . ( $dev ? '기기 · 팟' : '액상' ) . ' 가격은 얼마인가요?',
			'a' => $b . ' ' . $noun . ' 가격은 ' . implode( ' · ', $bits ) . '입니다(' . asof() . ' 기준). 값은 지금 판매가 그대로이고, 품절된 상품은 빼고 셉니다.',
		);
	}
	if ( ! $dev ) {
		$cat   = cat_words( $f );
		$live  = (int) ( $rows && str_starts_with( $rows[0]['label'], '낱병' ) ? ( $rows[0]['stock'] ?? 0 ) : 0 );
		$out[] = array(
			'q' => $b . ' 액상 종류는 몇 가지인가요?',
			'a' => $b . ' 액상은 ' . ( '' !== $cat ? $cat . ' ' : '' ) . $n . '종입니다'
				. ( '' !== $f['spec'] ? ' (' . $f['spec'] . ')' : '' ) . '.'
				. ( $live > 0 && $live < $n ? ' 그중 지금 바로 주문할 수 있는 낱병은 ' . $live . '종입니다.' : '' )
				. ' 맛별 상품은 이 페이지 목록에서 고르시면 '
				. ( $f['bundles'] ? '되고, ' . implode( ' · ', $f['bundles'] ) . ' 묶음은 맛을 묶음 안에서 골라 담습니다.' : '됩니다.' ),
		);
	}
	$out[] = array(
		'q' => $b . ' ' . ( $dev ? '기기는' : '액상은' ) . ' 언제 받을 수 있나요?',
		'a' => '평일 오후 4시 이전에 입금이 확인된 주문은 그날 출고합니다. 결제는 무통장입금이고, 만 19세 이상 본인확인을 마친 회원만 주문할 수 있습니다. '
			. number_format( free_ship() ) . '원 이상 무료배송입니다.',
	);
	return (array) apply_filters( 'duckhoo_brand_faq', $out, $b );
}

/**
 * 격자 아래 표 + FAQ.
 *
 * @return void
 */
function render(): void {
	$b = here();
	if ( '' === $b ) {
		return;
	}
	$rows = rows( $b );
	$dev  = 'device' === brand_kind( $b );
	if ( $rows ) {
		$h = $b . ( $dev ? ' 기기 · 팟 가격' : ' 액상 종류와 가격' );
		echo '<section class="dha-lineup" aria-labelledby="dha-blineup-h"><h2 id="dha-blineup-h">' . esc_html( $h )
			. ' <small>' . esc_html( asof() ) . ' 기준</small></h2>';
		echo '<div class="dhr-pl__scroll"><table class="dhr-pl__t"><thead><tr><th scope="col">구성</th><th scope="col" class="num">가격</th>'
			. ( $dev ? '' : '<th scope="col" class="num">병당</th>' ) . '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$tag = $r['n'] > 1 && ! str_starts_with( $r['label'], $r['n'] . '병' ) ? ' <small>' . (int) $r['n'] . '병</small>' : '';   // 「33병 묶음 33병」 ✗
			echo '<tr><td><b>' . esc_html( $r['label'] ) . '</b>' . $tag . '</td>'
				. '<td class="num">' . esc_html( $r['price'] ) . '</td>'
				. ( $dev ? '' : '<td class="num">' . esc_html( $r['per'] ) . '</td>' ) . '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="dha-lineup__s">값은 지금 판매가 그대로입니다. 전 상품 값은 <a href="' . esc_url( home_url( '/price/' ) ) . '">전자담배 액상 가격표</a>에 있습니다.</p>';
		echo '</section>';
	}
	$items = faq( $b );
	if ( $items ) {
		echo '<section class="dha-faq" aria-labelledby="dha-bfaq-h"><h2 id="dha-bfaq-h">' . esc_html( $b ) . ' 자주 묻는 질문</h2>';
		foreach ( $items as $i => $it ) {
			echo '<details class="dha-faq__i"' . ( 0 === $i ? ' open' : '' ) . '><summary>' . esc_html( (string) $it['q'] ) . '</summary><p>' . esc_html( (string) $it['a'] ) . '</p></details>';
		}
		echo '</section>';
	}
}
add_action( 'duckhoo_archive_after_grid', __NAMESPACE__ . '\\render', 8 );

/**
 * FAQPage 구조화 데이터 — 화면의 FAQ 와 같은 글.
 *
 * @return void
 */
function jsonld(): void {
	$b = here();
	if ( '' === $b ) {
		return;
	}
	$items = faq( $b );
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
add_action( 'wp_head', __NAMESPACE__ . '\\jsonld', 6 );
