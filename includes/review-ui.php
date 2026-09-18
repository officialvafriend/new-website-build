<?php
/**
 * 후기 화면 — 이름 가리기 · 아바타 · 날짜.
 *
 * 사장님(2026-09-18): **본명이 그대로 나오면 안 되고 `김**` 으로 나와야 한다.**
 * 그리고 왼쪽의 둥그런 아이콘(그라바타 identicon)이 무엇인지, 화면이 왜 어수선한지.
 *
 * 세 가지를 서버에서 바꾼다. 화면(CSS)은 `assets/shell.css` 가 맡는다.
 *
 *   1. **이름** — 첫 글자만 남기고 가린다 (`김시원` → `김**`).
 *      **관리자 화면에서는 그대로 둔다** — 사장님은 누가 썼는지 봐야 승인 · 적립을 판단한다
 *   2. **아바타** — 그라바타는 이메일의 해시를 gravatar.com 에 보내 받아오는 그림이다.
 *      등록한 적이 없으면 무늬(identicon)를 만들어 준다 — 손님이 고른 것이 아니라
 *      **이메일에서 자동으로 나온 무늬**다. 후기에서는 아무 뜻이 없고, 바깥 요청이
 *      한 번 더 나가므로 뺀다
 *   3. **날짜** — `9월 18, 2026` 은 영어 차례를 한글로 옮긴 꼴이다. `2026.09.18` 로
 *
 * **후기 내용 · 별점 · 구매자 확인은 건드리지 않는다** — 워드커머스가 쥔 그대로다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\ReviewUi;

defined( 'ABSPATH' ) || exit;

/**
 * 이 후기 화면에 손을 댈 것인가.
 *
 * @return bool
 */
function on(): bool {
	return (bool) apply_filters( 'duckhoo_review_ui', true );
}

/**
 * 이름을 가린다 — 첫 글자만 남기고 나머지를 별표로.
 *
 * `김시원` → `김**` · `김민` → `김*` · `남궁민수` → `남***`.
 * 아이디처럼 긴 이름은 별표를 다섯 개까지만 찍는다 (`kkuromi1004` → `k*****`) —
 * 길이를 그대로 보여 주면 그것도 단서가 된다.
 *
 * @param string $name 원래 이름.
 * @return string
 */
function mask( string $name ): string {
	$name = trim( $name );
	if ( '' === $name ) {
		return '';
	}
	$len = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );
	if ( $len <= 1 ) {
		return $name . '*';
	}
	$head = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1, 'UTF-8' ) : substr( $name, 0, 1 );

	return $head . str_repeat( '*', (int) min( $len - 1, 5 ) );
}

/**
 * 후기 작성자 이름.
 *
 * **상품 후기일 때만** 가린다 — 블로그 댓글 · kboard 는 그대로다.
 * 관리자 화면(상품평 · 댓글)에서는 원래 이름이 보여야 한다.
 *
 * @param string          $author  이름.
 * @param int|string      $cid     후기 ID.
 * @param \WP_Comment|null $comment 후기.
 * @return string
 */
function author( $author, $cid = 0, $comment = null ): string {
	$author = (string) $author;
	if ( ! on() || is_admin() ) {
		return $author;
	}
	if ( ! $comment instanceof \WP_Comment ) {
		$comment = get_comment( (int) $cid );
	}
	if ( ! $comment instanceof \WP_Comment || 'review' !== (string) $comment->comment_type ) {
		return $author;
	}

	return mask( $author );
}
add_filter( 'get_comment_author', __NAMESPACE__ . '\\author', 20, 3 );

/**
 * 후기에는 아바타를 그리지 않는다.
 *
 * `pre_get_avatar` 에서 빈 문자열을 돌려주면 워드프레스가 `<img>` 를 아예 안 만든다 —
 * gravatar.com 으로 나가는 요청도 같이 없어진다.
 *
 * @param string|null $avatar 여태 값 (null 이면 워드프레스가 만든다).
 * @param mixed       $id     회원 · 이메일 · 댓글.
 * @return string|null
 */
function no_avatar( $avatar, $id = null ) {
	if ( ! on() || is_admin() ) {
		return $avatar;
	}
	if ( $id instanceof \WP_Comment && 'review' === (string) $id->comment_type ) {
		return '';
	}

	return $avatar;
}
add_filter( 'pre_get_avatar', __NAMESPACE__ . '\\no_avatar', 20, 2 );

/**
 * 후기 날짜 — `2026.09.18`.
 *
 * @param string          $date    여태 글자.
 * @param string          $format  요청한 꼴.
 * @param \WP_Comment|null $comment 후기.
 * @return string
 */
function date_text( $date, $format = '', $comment = null ): string {
	if ( ! on() || is_admin() || ! $comment instanceof \WP_Comment ) {
		return (string) $date;
	}
	if ( 'review' !== (string) $comment->comment_type ) {
		return (string) $date;
	}

	/* **`get_comment_date()` 를 여기서 다시 부르면 안 된다** — 그 함수가 이 필터를
	   또 쏘아 끝없이 돈다. 날짜 글자를 만드는 쪽(`mysql2date`)을 바로 쓴다. */
	$fmt = (string) apply_filters( 'duckhoo_review_date_format', 'Y.m.d' );

	return (string) mysql2date( $fmt, (string) $comment->comment_date );
}
add_filter( 'get_comment_date', __NAMESPACE__ . '\\date_text', 20, 3 );

/**
 * 「(인증된 구매자)」 알약을 뺀다 — 사장님(2026-09-18): 이상해 보인다.
 *
 * 이 가게는 **산 사람만** 후기를 쓴다 (`Product\verified_only()`). 그러니 후기마다
 * 「인증된 구매자」라고 적는 것은 모두에게 같은 말을 되풀이하는 것이고, 손님 눈에는
 * 낯선 낱말 하나가 이름 옆에 붙어 있는 것뿐이다. CSS 로 감추는 대신 워드커머스가
 * 그 글자를 만드는 설정(`woocommerce_review_rating_verification_label`)을 꺼진 것으로
 * 돌려준다 — 마크업 자체가 안 나온다.
 *
 * 되살리려면 `add_filter( 'duckhoo_review_verified_label', '__return_true' );`
 *
 * @param mixed $value 저장된 값.
 * @return mixed
 */
function no_verified_label( $value ) {
	if ( ! on() || apply_filters( 'duckhoo_review_verified_label', false ) ) {
		return $value;
	}

	return 'no';
}
add_filter( 'option_woocommerce_review_rating_verification_label', __NAMESPACE__ . '\\no_verified_label' );
