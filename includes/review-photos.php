<?php
/**
 * 사진 후기 — 사진을 올린 후기에 적립금 1,000원.
 *
 * 사장님 결정 2026-09-09: **사진 후기만** 준다. 글만 쓴 후기에는 주지 않는다.
 *
 * 워드커머스 리뷰에는 사진을 올리는 자리가 없다. 그래서 여기서 만든다:
 *
 *   1. 후기 폼에 파일칸을 붙인다 (`comment_form_field_comment` 뒤)
 *   2. 올라온 사진을 미디어에 넣고 후기에 매단다 (`comment_post`)
 *   3. 후기 아래에 썸네일로 그린다 (`comment_text`)
 *   4. **승인된 뒤에** 적립금을 준다. 내리면 도로 가져간다
 *
 * **적립금은 `Points\grant()` 로만 준다** — 잔액(`_keyple_points`)과
 * 원장(`wp_keyple_points_log`)을 같이 건드려야 정산이 어긋나지 않는다.
 *
 * 주는 조건 (하나라도 어긋나면 0원):
 *
 *   - 사진이 한 장 이상 붙어 있을 것
 *   - 승인된 후기일 것 (관리자가 사진을 보고 승인한다)
 *   - 로그인 회원이 쓴 것일 것 (비회원에게는 줄 주머니가 없다)
 *   - **상품 하나당 한 번** — 같은 상품에 여러 번 써서 타먹지 못하게
 *   - 그 후기에 이미 준 적이 없을 것 (`_dhr_points`)
 *
 * 필터: `duckhoo_photo_review_points`(1000) · `duckhoo_photo_review_max`(3장) ·
 * `duckhoo_photo_review_bytes`(8MB) · `duckhoo_photo_review_on`(끄기).
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\ReviewPhotos;

use function Duckhoo\Redesign\Points\grant;

defined( 'ABSPATH' ) || exit;

const FIELD = 'dhr_review_photos';
const META  = '_dhr_photos';
const PAID  = '_dhr_points';

/**
 * 사진 후기 적립을 하는가.
 *
 * @return bool
 */
function on(): bool {
	return (bool) apply_filters( 'duckhoo_photo_review_on', true );
}

/**
 * 사진 후기에 주는 적립금.
 *
 * @return int
 */
function reward(): int {
	return max( 0, (int) apply_filters( 'duckhoo_photo_review_points', 1000 ) );
}

/**
 * 한 후기에 올릴 수 있는 사진 수.
 *
 * @return int
 */
function max_photos(): int {
	return max( 1, (int) apply_filters( 'duckhoo_photo_review_max', 3 ) );
}

/**
 * 사진 한 장의 크기 한도 (바이트).
 *
 * @return int
 */
function max_bytes(): int {
	return max( 1, (int) apply_filters( 'duckhoo_photo_review_bytes', 8 * 1024 * 1024 ) );
}

/* ── 쓰는 자리 ──────────────────────────────────────────────────────────── */

/**
 * 후기 폼의 글 상자 뒤에 파일칸을 붙인다.
 *
 * `form.cart` 가 아니라 후기 폼이라 구매 게이트가 읽는 칸 이름과는 상관이 없다.
 *
 * @param string $field 워드커머스가 만든 글 상자.
 * @return string
 */
function form_field( $field ): string {
	if ( ! on() ) {
		return (string) $field;
	}
	$n   = max_photos();
	$won = number_format_i18n( reward() );

	$html  = '<p class="comment-form-photos dhr-rev-up">';
	$html .= '<label for="' . esc_attr( FIELD ) . '">사진 (선택 · 최대 ' . esc_html( (string) $n ) . '장)</label>';
	$html .= '<input type="file" id="' . esc_attr( FIELD ) . '" name="' . esc_attr( FIELD ) . '[]"'
		. ' accept="image/jpeg,image/png,image/gif,image/webp" multiple>';
	if ( reward() > 0 ) {
		$html .= '<span class="dhr-rev-up__tip">사진을 올려 주시면 확인 후 <b>' . esc_html( $won ) . '원</b> 적립해 드립니다.'
			. ' 상품 하나당 한 번입니다.</span>';
	}
	$html .= '</p>';

	return (string) $field . $html;
}
add_filter( 'comment_form_field_comment', __NAMESPACE__ . '\\form_field', 20 );

/* ── 받는 자리 ──────────────────────────────────────────────────────────── */

/**
 * 올라온 파일이 우리가 받을 사진인가.
 *
 * 확장자만 믿지 않는다 — 실제 내용을 `getimagesize()` 로 본다.
 *
 * @param array<string,mixed> $file `$_FILES` 한 칸.
 * @return bool
 */
function is_photo( array $file ): bool {
	if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
		return false;
	}
	if ( (int) ( $file['size'] ?? 0 ) > max_bytes() ) {
		return false;
	}
	$info = @getimagesize( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $info || empty( $info['mime'] ) ) {
		return false;
	}
	return in_array(
		(string) $info['mime'],
		array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
		true
	);
}

/**
 * `$_FILES` 한 덩어리를 한 장씩으로 편다. 여러 장 올리면 배열이 칸별로 눕는다.
 *
 * @param array<string,mixed> $bag `$_FILES[FIELD]`.
 * @return array<int,array<string,mixed>>
 */
function spread( array $bag ): array {
	$names = (array) ( $bag['name'] ?? array() );
	$out   = array();
	foreach ( array_keys( $names ) as $i ) {
		$out[] = array(
			'name'     => (string) ( $bag['name'][ $i ] ?? '' ),
			'type'     => (string) ( $bag['type'][ $i ] ?? '' ),
			'tmp_name' => (string) ( $bag['tmp_name'][ $i ] ?? '' ),
			'error'    => (int) ( $bag['error'][ $i ] ?? 4 ),
			'size'     => (int) ( $bag['size'][ $i ] ?? 0 ),
		);
	}
	return $out;
}

/**
 * **사진이 붙은 후기는 사람이 볼 때까지 세워 둔다.**
 *
 * 그러지 않으면 이미 후기를 쓴 적 있는 회원의 글이 자동 승인되면서 **아무도 사진을
 * 보지 않은 채 적립금이 나간다.** 빈 화면을 찍어 올려도 1,000원이 되는 셈이다.
 * 19금 상품을 파는 가게라 어떤 사진이 올라올지도 사람이 봐야 한다.
 *
 * 사진이 없는 평범한 후기는 그대로 둔다 — 돈이 걸리지 않는다.
 *
 * @param int|string          $approved 여태 판정.
 * @param array<string,mixed> $data     들어온 댓글.
 * @return int|string
 */
function hold_for_review( $approved, $data = array() ) {
	if ( ! on() || 1 !== (int) $approved ) {
		return $approved; // 이미 대기 · 스팸이면 그대로.
	}
	if ( 'review' !== (string) ( $data['comment_type'] ?? '' ) ) {
		return $approved;
	}
	// phpcs:ignore WordPress.Security.NonceVerification -- 파일이 왔는지만 본다.
	$bag = $_FILES[ FIELD ] ?? null;
	if ( ! is_array( $bag ) || empty( array_filter( (array) ( $bag['name'] ?? array() ) ) ) ) {
		return $approved; // 사진이 없으면 돈도 안 나간다.
	}
	if ( ! apply_filters( 'duckhoo_photo_review_hold', true ) ) {
		return $approved;
	}
	return 0; // 검토 대기.
}
add_filter( 'pre_comment_approved', __NAMESPACE__ . '\\hold_for_review', 20, 2 );

/**
 * 후기가 저장된 뒤 사진을 받아 매단다.
 *
 * @param int        $comment_id 후기 ID.
 * @param int|string $approved   승인 상태.
 * @return void
 */
function save_photos( $comment_id, $approved = 0 ): void {
	$comment = get_comment( (int) $comment_id );
	if ( ! on() || ! $comment || 'review' !== (string) $comment->comment_type ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification -- 워드프레스가 이미 이 댓글을 받아들인 뒤다.
	$bag = $_FILES[ FIELD ] ?? null;
	if ( ! is_array( $bag ) || empty( $bag['name'] ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$ids  = array();
	$pid  = (int) $comment->comment_post_ID;
	$name = 0;
	foreach ( spread( $bag ) as $i => $file ) {
		if ( count( $ids ) >= max_photos() ) {
			break;
		}
		if ( ! is_photo( $file ) ) {
			continue;
		}
		// media_handle_upload 는 $_FILES 에서 이름으로 찾는다 — 한 장씩 세워 준다.
		$key           = FIELD . '_' . $name++;
		$_FILES[ $key ] = $file;
		$id             = media_handle_upload( $key, $pid );
		unset( $_FILES[ $key ] );
		if ( ! is_wp_error( $id ) ) {
			$ids[] = (int) $id;
		}
	}

	if ( $ids ) {
		add_comment_meta( (int) $comment_id, META, $ids, true );
		maybe_pay( (int) $comment_id );
	}
}
add_action( 'comment_post', __NAMESPACE__ . '\\save_photos', 20, 2 );

/**
 * 이 후기에 매달린 사진들.
 *
 * @param int $comment_id 후기 ID.
 * @return int[]
 */
function photos( int $comment_id ): array {
	$ids = get_comment_meta( $comment_id, META, true );
	return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
}

/* ── 보는 자리 ──────────────────────────────────────────────────────────── */

/**
 * 후기 글 뒤에 사진을 붙인다.
 *
 * `comment_text` 는 관리자 `상품평` 화면에서도 돌아서, 사장님이 승인 전에 사진을
 * 그대로 보고 판단할 수 있다.
 *
 * @param string      $text    후기 글.
 * @param \WP_Comment $comment 후기.
 * @return string
 */
function show_photos( $text, $comment = null ): string {
	if ( ! $comment instanceof \WP_Comment || 'review' !== (string) $comment->comment_type ) {
		return (string) $text;
	}
	$ids = photos( (int) $comment->comment_ID );
	if ( ! $ids ) {
		return (string) $text;
	}

	$html = '<div class="dhr-rev-ph">';
	foreach ( $ids as $id ) {
		$full  = wp_get_attachment_image_url( $id, 'large' );
		$thumb = wp_get_attachment_image( $id, 'medium', false, array( 'class' => 'dhr-rev-ph__img', 'loading' => 'lazy' ) );
		if ( ! $thumb ) {
			continue;
		}
		$html .= $full
			? '<a class="dhr-rev-ph__a" href="' . esc_url( $full ) . '" target="_blank" rel="noopener">' . $thumb . '</a>'
			: '<span class="dhr-rev-ph__a">' . $thumb . '</span>';
	}
	$html .= '</div>';

	return (string) $text . $html;
}
add_filter( 'comment_text', __NAMESPACE__ . '\\show_photos', 20, 2 );

/* ── 적립금 ─────────────────────────────────────────────────────────────── */

/**
 * 같은 회원이 같은 상품에서 이미 사진 후기 적립을 받았는가.
 *
 * @param \WP_Comment $comment 이번 후기.
 * @return bool
 */
function already_paid_for_product( \WP_Comment $comment ): bool {
	$uid = (int) $comment->user_id;
	if ( $uid <= 0 ) {
		return true;
	}
	$others = get_comments(
		array(
			'post_id'    => (int) $comment->comment_post_ID,
			'user_id'    => $uid,
			'type'       => 'review',
			'status'     => 'all',
			'meta_key'   => PAID, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'     => 'ids',
			'number'     => 5,
		)
	);
	foreach ( (array) $others as $id ) {
		if ( (int) $id !== (int) $comment->comment_ID && (int) get_comment_meta( (int) $id, PAID, true ) > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * 줄 수 있으면 준다. 이미 줬으면 아무것도 하지 않는다.
 *
 * @param int $comment_id 후기 ID.
 * @return int 준 금액.
 */
function maybe_pay( int $comment_id ): int {
	$comment = get_comment( $comment_id );
	if ( ! on() || ! $comment instanceof \WP_Comment || 'review' !== (string) $comment->comment_type ) {
		return 0;
	}
	if ( (int) get_comment_meta( $comment_id, PAID, true ) > 0 ) {
		return 0; // 이미 줬다.
	}
	if ( '1' !== (string) $comment->comment_approved ) {
		return 0; // 승인 전에는 주지 않는다 — 사진을 사람이 본다.
	}
	if ( ! photos( $comment_id ) ) {
		return 0; // 글만 쓴 후기에는 주지 않는다.
	}
	$uid = (int) $comment->user_id;
	if ( $uid <= 0 || already_paid_for_product( $comment ) ) {
		return 0;
	}
	$amount = reward();
	if ( $amount <= 0 ) {
		return 0;
	}

	$title = get_the_title( (int) $comment->comment_post_ID );
	$label = sprintf(
		/* translators: %s: 상품 이름 */
		__( '사진 후기 적립 — %s', 'duckhoo-redesign' ),
		wp_strip_all_tags( (string) $title )
	);
	if ( ! grant( $uid, $amount, $label ) ) {
		return 0;
	}
	update_comment_meta( $comment_id, PAID, $amount );
	return $amount;
}

/**
 * 승인을 내리거나 지우면 도로 가져간다.
 *
 * 없으면 「쓰고 받고 지우기」가 그대로 된다.
 *
 * @param int $comment_id 후기 ID.
 * @return int 가져간 금액.
 */
function take_back( int $comment_id ): int {
	$paid = (int) get_comment_meta( $comment_id, PAID, true );
	if ( $paid <= 0 ) {
		return 0;
	}
	$comment = get_comment( $comment_id );
	$uid     = $comment instanceof \WP_Comment ? (int) $comment->user_id : 0;
	if ( $uid <= 0 ) {
		return 0;
	}
	$title = $comment ? get_the_title( (int) $comment->comment_post_ID ) : '';
	$label = sprintf(
		/* translators: %s: 상품 이름 */
		__( '사진 후기 적립 취소 — %s', 'duckhoo-redesign' ),
		wp_strip_all_tags( (string) $title )
	);
	if ( ! grant( $uid, -$paid, $label ) ) {
		return 0;
	}
	delete_comment_meta( $comment_id, PAID );
	return $paid;
}

/**
 * 승인 상태가 바뀔 때.
 *
 * @param string      $new     새 상태.
 * @param string      $old     옛 상태.
 * @param \WP_Comment $comment 후기.
 * @return void
 */
function on_status( $new, $old, $comment = null ): void {
	if ( ! $comment instanceof \WP_Comment ) {
		return;
	}
	if ( 'approved' === (string) $new ) {
		maybe_pay( (int) $comment->comment_ID );
		return;
	}
	take_back( (int) $comment->comment_ID );
}
add_action( 'transition_comment_status', __NAMESPACE__ . '\\on_status', 10, 3 );

/**
 * 지울 때도 가져간다.
 *
 * @param int $comment_id 후기 ID.
 * @return void
 */
function on_delete( $comment_id ): void {
	take_back( (int) $comment_id );
}
add_action( 'delete_comment', __NAMESPACE__ . '\\on_delete' );
