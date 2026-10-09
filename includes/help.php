<?php
/**
 * 도움말 화면 — 배송 안내 · 고객센터 · 자주 묻는 질문 · 공지사항 · 신상 소식 · 1:1 문의 (2026-10-09).
 *
 * 테마 페이지 템플릿은 그대로 돌고, 본문(`the_content`)만 이렇게 바꾼다:
 *   - 맨 위에 어두운 머리판(`.dhr-ah.dhr-hh`) 하나 — 계정 화면과 같은 판에 **지금 상태**를 한 줄로
 *     (「지금 상담 가능 · 18:00까지」 · 「오늘 16:00까지 입금 확인되면 당일 출고」). 이 가게가 받는
 *     질문의 절반이 「지금 되나요 · 언제 오나요」라서 그 답을 맨 위에 둔다
 *   - 아래에 도움말 화면끼리 오가는 알약 줄
 *   - 본문은 흰 판 하나(`.dhr-doc`)에. 관리자에서 고친 글은 그대로 나온다 — 배송 안내가 그렇다
 *   - **고객센터(/contact-us/) · 자주 묻는 질문(/faq/) 이 테마 샘플(「이 페이지는 샘플입니다」 ·
 *     Lorem ipsum)이었다.** 샘플일 때만 우리 글로 대신 그린다 — 사장님이 직접 쓰시면 그쪽이 나온다
 *
 * 게시판(kboard) · 테마 파일은 건드리지 않는다. 비로그인이 `/inquiries/` 를 열면 kboard 가
 * alert 을 띄우고 wp-login.php 로 보낸다 — 그 전에 우리 로그인 화면으로 보낸다 (돌아올 곳을 달고).
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Help;

use function Duckhoo\Redesign\Front\icon;

defined( 'ABSPATH' ) || exit;

/**
 * 켜져 있나. **작업 중이라 기본은 꺼 둔다** (2026-10-09) — CSS 가 붙으면 true 로 바꾼다.
 * 꺼져 있으면 본문 · 제목 · 다크 버튼 · 1:1 문의 길 모두 손대지 않는다.
 *
 * @return bool
 */
function on(): bool {
	return (bool) apply_filters( 'duckhoo_help_on', false );
}

/**
 * 도움말 화면 목록 — 슬러그 => [이름, 짧은 이름, 한 줄, 알약 줄에 넣을지].
 *
 * @return array<string,array{title:string,nav:string,sub:string,live:string}>
 */
function pages(): array {
	return (array) apply_filters( 'duckhoo_help_pages', array(
		'shipping'   => array( 'title' => '배송 · 교환 · 환불 안내', 'nav' => '배송 안내', 'sub' => '입금이 확인되면 평일 오후 4시까지 그날 보냅니다.', 'live' => 'ship' ),
		'faq'        => array( 'title' => '자주 묻는 질문', 'nav' => '자주 묻는 질문', 'sub' => '주문 · 입금 · 배송 · 교환에서 가장 많이 받는 질문입니다.', 'live' => 'cs' ),
		'contact-us' => array( 'title' => '고객센터', 'nav' => '고객센터', 'sub' => '로그인 없이 가장 빨리 닿는 곳은 카카오톡입니다.', 'live' => 'cs' ),
		'notice'     => array( 'title' => '공지사항', 'nav' => '공지사항', 'sub' => '출고 일정 · 입고 · 가격 변동을 여기에 올립니다.', 'live' => 'ship' ),
		'tip'        => array( 'title' => '신상 소식', 'nav' => '신상 소식', 'sub' => '새로 들어온 액상과 기기 소식입니다.', 'live' => '' ),
		'inquiries'  => array( 'title' => '1:1 문의', 'nav' => '1:1 문의', 'sub' => '주문 번호와 함께 남겨 주시면 응대 시간에 순서대로 답합니다.', 'live' => 'cs' ),
	) );
}

/**
 * 지금 화면의 도움말 슬러그. 아니면 빈 문자열.
 *
 * @return string
 */
function current(): string {
	if ( ! on() || ! function_exists( 'is_page' ) || ! is_page() ) {
		return '';
	}
	$o = get_queried_object();
	$slug = is_object( $o ) && isset( $o->post_name ) ? rawurldecode( (string) $o->post_name ) : '';
	return isset( pages()[ $slug ] ) ? $slug : '';
}

/* ── 1. 지금 상태 — 상담 · 출고 ──────────────────────────────────────────── */

/**
 * 쉬는 날 (Y-m-d) — 연휴 목록의 from~to. 상담도 출고도 쉰다.
 *
 * @return string[]
 */
function off_days(): array {
	$out = array();
	if ( function_exists( 'Duckhoo\\Redesign\\Front\\holidays' ) ) {
		foreach ( \Duckhoo\Redesign\Front\holidays() as $h ) {
			$from = strtotime( (string) ( $h['from'] ?? '' ) . ' 12:00:00' );
			$to   = strtotime( (string) ( $h['to'] ?? '' ) . ' 12:00:00' );
			if ( ! $from || ! $to ) {
				continue;
			}
			for ( $t = $from; $t <= $to && count( $out ) < 60; $t += DAY_IN_SECONDS ) {
				$out[] = gmdate( 'Y-m-d', $t );
			}
		}
	}
	return array_values( array_unique( (array) apply_filters( 'duckhoo_help_off_days', $out ) ) );
}

/**
 * 시간 설정 — 브라우저도 같은 값으로 다시 센다 (페이지 캐시가 몇 분 낡은 글을 내줘도 맞게).
 *
 * @return array{open:string,close:string,lunch:array{0:string,1:string},cut:string,off:string[]}
 */
function clock(): array {
	$h     = function_exists( 'Duckhoo\\Redesign\\Front\\hours' ) ? \Duckhoo\Redesign\Front\hours() : array( 'open' => '11:00', 'close' => '18:00', 'lunch' => '12:00–13:00' );
	$lunch = preg_split( '/\s*[–\-~]\s*/u', (string) ( $h['lunch'] ?? '' ) );
	return array(
		'open'  => (string) ( $h['open'] ?? '11:00' ),
		'close' => (string) ( $h['close'] ?? '18:00' ),
		'lunch' => array( (string) ( $lunch[0] ?? '' ), (string) ( $lunch[1] ?? '' ) ),
		'cut'   => (string) apply_filters( 'duckhoo_ship_cutoff', '16:00' ),
		'off'   => off_days(),
	);
}

/**
 * 「HH:MM」 → 분.
 */
function mins( string $hm ): int {
	return preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $hm ), $m ) ? (int) $m[1] * 60 + (int) $m[2] : -1;
}

/**
 * 그날이 일하는 날인가 (평일이고 쉬는 날이 아니다).
 *
 * @param int $ts 그날 정오의 시각(사이트 시간대로 해석한 값).
 */
function workday( int $ts, array $off ): bool {
	$w = (int) gmdate( 'w', $ts );
	return $w >= 1 && $w <= 5 && ! in_array( gmdate( 'Y-m-d', $ts ), $off, true );
}

/**
 * 다음 일하는 날 — `10월 12일(월)`. 오늘은 빼고 센다.
 */
function next_workday( int $ts, array $off ): string {
	for ( $i = 1; $i <= 21; $i++ ) {
		$d = $ts + $i * DAY_IN_SECONDS;
		if ( workday( $d, $off ) ) {
			return 1 === $i ? '내일' : kday( gmdate( 'Y-m-d', $d ) );
		}
	}
	return '다음 평일';
}

/**
 * `10월 12일(월)`.
 */
function kday( string $ymd ): string {
	return function_exists( 'Duckhoo\\Redesign\\Front\\kday' ) ? \Duckhoo\Redesign\Front\kday( $ymd ) : $ymd;
}

/**
 * 지금 상태 한 줄. 순수 함수 — `$now` 는 「사이트 시간대의 벽시계」를 UTC 처럼 담은 값
 * (`current_time('timestamp')` 와 같다). 브라우저(front.js)가 같은 셈을 한다.
 *
 * @param string $kind cs (상담) · ship (출고).
 * @param int    $now  사이트 시간 벽시계.
 * @param array  $c    clock().
 * @return array{on:bool,k:string,s:string}
 */
function state( string $kind, int $now, array $c ): array {
	$day  = (int) ( floor( $now / DAY_IN_SECONDS ) * DAY_IN_SECONDS ) + 12 * HOUR_IN_SECONDS;
	$m    = (int) floor( ( $now % DAY_IN_SECONDS ) / 60 );
	$work = workday( $day, (array) $c['off'] );
	if ( 'ship' === $kind ) {
		$cut = mins( (string) $c['cut'] );
		if ( $work && $cut > 0 && $m < $cut ) {
			$left = $cut - $m;
			$hm   = ( $left >= 60 ? intdiv( $left, 60 ) . '시간 ' : '' ) . ( $left % 60 ? ( $left % 60 ) . '분' : '' );
			return array( 'on' => true, 'k' => '오늘 ' . $c['cut'] . '까지 입금 확인되면 당일 출고', 's' => trim( $hm ) . ' 남음' );
		}
		return array( 'on' => false, 'k' => '오늘 출고는 마감됐어요', 's' => '다음 출고 ' . next_workday( $day, (array) $c['off'] ) . ' 오후 4시' );
	}
	$open  = mins( (string) $c['open'] );
	$close = mins( (string) $c['close'] );
	$l0    = mins( (string) ( $c['lunch'][0] ?? '' ) );
	$l1    = mins( (string) ( $c['lunch'][1] ?? '' ) );
	if ( $work && $m >= $open && $m < $close ) {
		if ( $l0 >= 0 && $l1 > $l0 && $m >= $l0 && $m < $l1 ) {
			return array( 'on' => false, 'k' => '점심시간이에요', 's' => $c['lunch'][1] . '부터 다시 답해요' );
		}
		return array( 'on' => true, 'k' => '지금 상담 가능', 's' => $c['close'] . '까지' );
	}
	$when = ( $work && $m < $open ) ? '오늘' : next_workday( $day, (array) $c['off'] );
	return array( 'on' => false, 'k' => '지금은 상담 시간이 아니에요', 's' => $when . ' ' . $c['open'] . '부터 답해요' );
}

/**
 * 상태 알약. 서버가 한 번 그리고, 브라우저가 같은 셈으로 1분마다 고친다.
 *
 * @param string $kind cs · ship.
 * @return string
 */
function live_html( string $kind ): string {
	if ( '' === $kind ) {
		return '';
	}
	$c  = clock();
	$st = state( $kind, (int) current_time( 'timestamp' ), $c );
	return '<p class="dhr-live' . ( $st['on'] ? ' is-on' : '' ) . '" data-live="' . esc_attr( $kind ) . '" data-clock="' . esc_attr( (string) wp_json_encode( $c ) ) . '" role="status">'
		. '<i class="dhr-live__dot" aria-hidden="true"></i><b>' . esc_html( $st['k'] ) . '</b><span>' . esc_html( $st['s'] ) . '</span></p>';
}

/* ── 2. 머리판 · 알약 줄 ─────────────────────────────────────────────────── */

/**
 * 도움말 화면끼리 오가는 알약 줄.
 *
 * @param string $here 지금 슬러그.
 * @return string
 */
function nav_html( string $here ): string {
	$items = array();
	foreach ( pages() as $slug => $p ) {
		if ( 'tip' === $slug && 'tip' !== $here ) {
			continue;   // 신상 소식은 도움말이 아니다 — 그 화면에서만 알약에 선다
		}
		$url = 'inquiries' === $slug && function_exists( 'Duckhoo\\Redesign\\Front\\inquiry_url' )
			? \Duckhoo\Redesign\Front\inquiry_url()
			: home_url( '/' . $slug . '/' );
		$items[] = '<a href="' . esc_url( $url ) . '"' . ( $slug === $here ? ' class="is-now" aria-current="page"' : '' ) . '>' . esc_html( $p['nav'] ) . '</a>';
	}
	return '<nav class="dhr-hh__nav" aria-label="고객센터 메뉴">' . implode( '', $items ) . '</nav>';
}

/**
 * 머리판.
 *
 * @param string $slug 슬러그.
 * @return string
 */
function head_html( string $slug ): string {
	$p = pages()[ $slug ] ?? null;
	if ( ! $p ) {
		return '';
	}
	return '<header class="dhr-ah dhr-hh"><span class="dhr-ah__bg" aria-hidden="true"></span>'
		. '<div class="dhr-ah__in"><div class="dhr-ah__txt">'
		. '<p class="dhr-ah__eb">고객센터</p>'
		. '<h1 class="dhr-ah__t">' . esc_html( $p['title'] ) . '</h1>'
		. '<p class="dhr-ah__s">' . esc_html( $p['sub'] ) . '</p>'
		. '</div>' . live_html( (string) $p['live'] ) . '</div>'
		. nav_html( $slug ) . '</header>';
}

/* ── 3. 본문 ─────────────────────────────────────────────────────────────── */

/**
 * 테마가 깔아 둔 샘플 글인가. 사장님이 쓴 글이면 false.
 *
 * @param string $html 본문.
 * @return bool
 */
function is_sample( string $html ): bool {
	$t = trim( wp_strip_all_tags( $html ) );
	return '' === $t || false !== mb_strpos( $t, '이 페이지는 샘플' ) || false !== stripos( $t, 'lorem ipsum' );
}

/**
 * 배송 안내 머리 — 주문부터 도착까지 (실제 순서라 번호가 뜻을 가진다) + 사실 셋 + 연휴.
 *
 * @return string
 */
function ship_top(): string {
	$free = number_format( (int) apply_filters( 'duckhoo_free_shipping_min', 30000 ) );
	$cut  = clock()['cut'];
	$out  = '';
	$hn   = function_exists( 'Duckhoo\\Redesign\\Front\\holiday_notice' ) ? \Duckhoo\Redesign\Front\holiday_notice() : null;
	if ( $hn ) {
		$out .= '<aside class="dhr-hcall"><b>' . esc_html( (string) $hn['eb'] ) . '</b><span>' . esc_html( (string) $hn['t'] ) . '</span></aside>';
	}
	$steps = array(
		array( '주문하고 입금', '무통장입금 · 입금자명은 주문자명과 같게' ),
		array( '입금 확인', '이름이 같으면 자동으로 확인돼요' ),
		array( '출고', '평일 ' . $cut . ' 전 확인분은 그날' ),
		array( '도착', '출고 다음 날부터 1~2일' ),
	);
	$out .= '<ol class="dhr-flow" aria-label="주문부터 도착까지">';
	foreach ( $steps as $i => $s ) {
		$out .= '<li><span class="dhr-flow__n">' . ( $i + 1 ) . '</span><b>' . esc_html( $s[0] ) . '</b><span>' . esc_html( $s[1] ) . '</span></li>';
	}
	$out .= '</ol>';
	$out .= '<ul class="dhr-facts">'
		. '<li><span>배송비</span><b>2,500원</b><em>' . esc_html( $free ) . '원 이상 무료</em></li>'
		. '<li><span>택배</span><b>우체국택배</b><em>송장은 주문내역에서</em></li>'
		. '<li><span>교환 · 환불</span><b>받은 날부터 7일</b><em>미개봉 상품</em></li>'
		. '</ul>';
	return $out;
}

/**
 * 고객센터 본문.
 *
 * @return string
 */
function contact_body(): string {
	$tel    = '010-5133-5852';
	$kakao  = function_exists( 'Duckhoo\\Redesign\\Front\\kakao_url' ) ? \Duckhoo\Redesign\Front\kakao_url() : '';
	$ask    = function_exists( 'Duckhoo\\Redesign\\Front\\inquiry_url' ) ? \Duckhoo\Redesign\Front\inquiry_url() : home_url( '/inquiries/' );
	$acct   = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
	$hl     = function_exists( 'Duckhoo\\Redesign\\Front\\hours_lines' ) ? \Duckhoo\Redesign\Front\hours_lines() : array( '평일 11:00–18:00 · 점심 12:00–13:00', '주말 · 법정 공휴일 휴무' );
	$banks  = function_exists( 'Duckhoo\\Redesign\\Front\\bank_accounts' ) ? \Duckhoo\Redesign\Front\bank_accounts() : array();
	$o = '<div class="dhr-cs">';
	if ( $kakao ) {
		$o .= '<a class="dhr-cs__main" href="' . esc_url( $kakao ) . '" target="_blank" rel="noopener">'
			. '<span class="dhr-cs__ic">' . icon( 'chat' ) . '</span>'
			. '<span class="dhr-cs__txt"><b>카카오톡 오픈채팅</b><span>로그인 없이 바로 물어보세요. 입금 확인 · 배송 · 교환 모두 여기서 답해요.</span></span>'
			. '<span class="dhr-cs__go">채팅 열기' . icon( 'arrow' ) . '</span></a>';
	}
	$rows = array(
		array( 'phone', '전화', $tel, $hl[0], 'tel:' . $tel, false ),
		array( 'pen', '1:1 문의', '회원 전용 게시판', '주문 번호와 함께 남기면 순서대로 답해요', $ask, false ),
		array( 'truck', '배송 조회', '우체국택배', '송장번호는 주문내역에 있어요', 'https://service.epost.go.kr/trace.RetrieveDomRigiTraceList.comm', true ),
		array( 'receipt', '주문내역', '입금 전 주문은 직접 취소', '마이페이지 → 주문내역', trailingslashit( $acct ) . 'orders/', false ),
	);
	$o .= '<div class="dhr-cs__list">';
	foreach ( $rows as $r ) {
		$o .= '<a class="dhr-cs__row" href="' . esc_url( $r[4] ) . '"' . ( $r[5] ? ' target="_blank" rel="noopener"' : '' ) . '>'
			. '<span class="dhr-cs__ic">' . icon( $r[0] ) . '</span>'
			. '<span class="dhr-cs__txt"><span class="dhr-cs__k">' . esc_html( $r[1] ) . '</span><b>' . esc_html( $r[2] ) . '</b><span>' . esc_html( $r[3] ) . '</span></span>'
			. icon( 'chev' ) . '</a>';
	}
	$o .= '</div>';
	$o .= '<section class="dhr-cs__hours"><h2>응대 시간</h2><p><b>' . esc_html( $hl[0] ) . '</b><br>' . esc_html( $hl[1] ) . '</p>'
		. '<p class="dhr-cs__note">응대 시간 밖에 남긴 문의는 다음 평일에 받은 순서대로 답합니다.</p></section>';
	foreach ( $banks as $b ) {
		$o .= '<section class="dhr-cs__bank"><h2>입금 계좌</h2>'
			. '<p class="dhr-cs__acc"><b>' . esc_html( $b['number'] ) . '</b><span>' . esc_html( trim( $b['bank'] . ( $b['name'] ? ' · 예금주 ' . $b['name'] : '' ) ) ) . '</span></p>'
			. '<p class="dhr-cs__note"><b>입금자명을 주문자명과 똑같이</b> 넣어 주세요. 이름이 같으면 자동으로 확인돼 바로 출고 준비에 들어갑니다.</p></section>';
		break;
	}
	$o .= '<p class="dhr-cs__more"><a href="' . esc_url( home_url( '/faq/' ) ) . '">자주 묻는 질문 보기</a><a href="' . esc_url( home_url( '/shipping/' ) ) . '">배송 · 교환 · 환불 안내</a></p>';
	return $o . '</div>';
}

/**
 * 자주 묻는 질문. 답은 이 가게에서 실제로 돌아가는 규칙만 적는다.
 *
 * @return array<string,array<int,array{0:string,1:string}>>
 */
function faq(): array {
	$free   = number_format( (int) apply_filters( 'duckhoo_free_shipping_min', 30000 ) );
	$points = number_format( function_exists( 'Duckhoo\\Redesign\\Front\\signup_points' ) ? \Duckhoo\Redesign\Front\signup_points() : 8800 );
	$photo  = number_format( (int) apply_filters( 'duckhoo_photo_review_points', 1000 ) );
	$cut    = clock()['cut'];
	return (array) apply_filters( 'duckhoo_help_faq', array(
		'주문 · 입금' => array(
			array( '결제는 어떻게 하나요?', '무통장입금(계좌이체)만 받습니다. 카드결제는 받지 않습니다. 주문을 마치면 입금할 계좌와 금액이 안내됩니다.' ),
			array( '입금자명은 꼭 주문자명과 같아야 하나요?', '네. 이름이 같으면 입금이 자동으로 확인되어 바로 출고 준비에 들어갑니다. 이름이 다르면 직원이 손으로 확인해야 해서 출고가 늦어질 수 있습니다. 이미 다른 이름으로 보내셨다면 카카오톡이나 1:1 문의로 알려 주세요.' ),
			array( '주문을 취소하고 싶어요.', '입금하기 전 주문은 마이페이지 → 주문내역에서 직접 취소할 수 있습니다. 적립금을 쓴 주문이면 취소할 때 적립금도 돌아옵니다. 입금한 뒤에는 카카오톡이나 1:1 문의로 말씀해 주세요.' ),
			array( '회원가입 없이 주문할 수 있나요?', '아니요. 19세 미만 판매 금지 품목이라 휴대폰 본인확인을 마친 회원만 주문할 수 있습니다. 본인확인은 가입할 때 한 번, 1분이면 끝납니다.' ),
		),
		'배송' => array(
			array( '언제 출고되나요?', '평일 오후 ' . substr( $cut, 0, 2 ) . '시 전에 입금이 확인된 주문은 그날 보냅니다. ' . ( function_exists( 'Duckhoo\\Redesign\\Front\\ship_rule' ) ? \Duckhoo\Redesign\Front\ship_rule() : '' ) ),
			array( '배송비는 얼마인가요?', '2,500원이고, ' . $free . '원 이상 사시면 무료입니다.' ),
			array( '송장번호는 어디서 보나요?', '우체국택배로 보냅니다. 송장이 등록되면 마이페이지 → 주문내역에서 번호와 배송조회 버튼이 보입니다.' ),
		),
		'교환 · 환불' => array(
			array( '교환이나 환불이 되나요?', '개봉하지 않은 상품은 받으신 날부터 7일 안에 교환 · 환불할 수 있습니다. 단순 변심이면 교환은 왕복 배송비 5,000원, 환불은 편도 배송비 2,500원이 듭니다. 상품에 문제가 있거나 다른 상품이 왔다면 배송비를 받지 않습니다.' ),
			array( '개봉한 액상도 교환되나요?', '개봉한 액상은 위생상 되팔 수 없어 교환 · 환불이 어렵습니다. 받은 상품이 처음부터 문제가 있었다면 사진과 함께 알려 주세요.' ),
		),
		'회원 · 적립금' => array(
			array( '상품 사진이 「19」로만 보여요.', '성인인증을 마친 회원에게만 상품 사진을 보여 드립니다. 로그인하면 사진이 보입니다.' ),
			array( '가입하면 혜택이 있나요?', '가입하면 바로 적립금 ' . $points . '원을 드립니다. 적립금을 쓸 수 있는 상품은 「적립금 상품」 분류에 모아 두었고, 결제 화면에서 바로 뺄 수 있습니다.' ),
			array( '후기를 쓰면 적립금을 주나요?', '사진을 붙인 후기가 승인되면 상품마다 한 번 ' . $photo . '원을 드립니다. 후기는 받은 상품 페이지 아래에서 쓸 수 있습니다.' ),
		),
	) );
}

/**
 * 자주 묻는 질문 본문 + FAQPage JSON-LD.
 *
 * @return string
 */
function faq_body(): string {
	$o  = '<div class="dhr-faq">';
	$ld = array();
	foreach ( faq() as $group => $items ) {
		$o .= '<section class="dhr-faq__g"><h2>' . esc_html( $group ) . '</h2>';
		foreach ( $items as $qa ) {
			$o   .= '<details><summary>' . esc_html( $qa[0] ) . icon( 'plus' ) . '</summary><p>' . esc_html( $qa[1] ) . '</p></details>';
			$ld[] = array( '@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $qa[1] ) );
		}
		$o .= '</section>';
	}
	$o .= '<p class="dhr-faq__more">찾는 답이 없으면 <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">고객센터</a>로 물어봐 주세요.</p></div>';
	$o .= '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $ld ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
	return $o;
}

/**
 * 본문을 바꾼다.
 *
 * @param string $html 본문.
 * @return string
 */
function content( $html ): string {
	$html = (string) $html;
	$slug = current();
	if ( '' === $slug || ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}
	static $done = false;   // 한 화면에 한 번 — 다른 곳에서 the_content 를 또 부르면 그대로
	if ( $done ) {
		return $html;
	}
	$done = true;
	$body = $html;
	$top  = '';
	if ( 'contact-us' === $slug && is_sample( $html ) ) {
		$body = contact_body();
	} elseif ( 'faq' === $slug && is_sample( $html ) ) {
		$body = faq_body();
	} elseif ( 'shipping' === $slug ) {
		$top = ship_top();
	}
	$board = in_array( $slug, array( 'notice', 'tip', 'inquiries' ), true ) ? ' dhr-doc--board' : '';
	$plain = in_array( $slug, array( 'contact-us', 'faq' ), true ) && $body !== $html ? ' dhr-doc--bare' : '';
	return head_html( $slug ) . $top . '<div class="dhr-doc' . $board . $plain . '">' . $body . '</div>';
}
add_filter( 'the_content', __NAMESPACE__ . '\\content', 99 );

/**
 * 테마 제목 · 문서 제목 — 영문 슬러그 그대로(「notice」 · 「tip」)였던 것을 한글로.
 * 화면의 테마 h1 은 머리판이 대신하므로 CSS 가 뺀다(`:has(.dhr-hh)`). 검색 결과 제목은 여기서.
 *
 * @param string $t  제목.
 * @param int    $id 글 번호.
 * @return string
 */
function title( $t, $id = 0 ): string {
	$slug = current();
	if ( '' === $slug || (int) $id !== (int) get_queried_object_id() ) {
		return (string) $t;
	}
	return pages()[ $slug ]['title'];
}
add_filter( 'the_title', __NAMESPACE__ . '\\title', 20, 2 );

/**
 * 검색 결과 제목.
 *
 * @param string $t 지금 값.
 * @return string
 */
function doc_title( $t ): string {
	$slug = current();
	return '' === $slug ? (string) $t : pages()[ $slug ]['title'] . ' | ' . get_bloginfo( 'name' );
}
add_filter( 'aioseo_title', __NAMESPACE__ . '\\doc_title', 26 );
add_filter( 'pre_get_document_title', __NAMESPACE__ . '\\doc_title', 26 );

/**
 * 다크 모드 버튼 — 도움말 화면에서도 (본문을 우리가 그리므로 색을 책임질 수 있다).
 *
 * @param bool $on 지금 값.
 * @return bool
 */
function toggle( $on ): bool {
	return (bool) $on || '' !== current();
}
add_filter( 'duckhoo_theme_toggle', __NAMESPACE__ . '\\toggle' );

/**
 * 비로그인이 1:1 문의를 열면 kboard 가 alert 뒤 wp-login.php 로 보낸다 — 그 전에 우리 로그인 화면으로.
 *
 * @return void
 */
function inquiry_door(): void {
	if ( 'inquiries' !== current() || is_user_logged_in() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		return;
	}
	if ( ! function_exists( 'Duckhoo\\Redesign\\Front\\inquiry_url' ) ) {
		return;
	}
	wp_safe_redirect( \Duckhoo\Redesign\Front\inquiry_url(), 302 );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\inquiry_door', 3 );

/**
 * 켜졌을 때 몸에 표시 — front.js 의 장바구니 전표가 이것을 보고 붙는다.
 *
 * @param string[] $c 클래스.
 * @return string[]
 */
function body_class( $c ): array {
	$c = (array) $c;
	if ( on() ) {
		$c[] = 'dhr-help-on';
	}
	return $c;
}
add_filter( 'body_class', __NAMESPACE__ . '\\body_class' );
