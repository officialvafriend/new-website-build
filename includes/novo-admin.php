<?php
/**
 * 도구 → 노보 이벤트 (관리자).
 *
 * 노보 상품의 **실제 판매가 · 재고**와 **이벤트 기준 가격**을 나란히 놓고 본다.
 * 어긋난 줄만 눈에 띄게 하고, 원하면 한 번에 맞춘다.
 *
 * 가격은 워드커머스 API(`set_regular_price()` · `save()`)로 쓴다 — 관리자 화면에서
 * 손으로 고치는 것과 같은 길이다. 메타를 직접 쓰지 않는다. 바꾸기 전 값은
 * `_duckhoo_novo_price_before` 에 적어 두므로 한 번에 되돌릴 수 있다.
 *
 * **주문 · 회원 · 적립금은 건드리지 않는다.**
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Novo\Admin;

use function Duckhoo\Redesign\Novo\{config, line, bottles, paid, stock_left, limit, on};

defined( 'ABSPATH' ) || exit;

const SLUG   = 'duckhoo-novo';
const BEFORE = '_duckhoo_novo_price_before';

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
		'노보 이벤트',
		'노보 이벤트',
		current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 노보 상품 전부.
 *
 * @return \WC_Product[]
 */
function products(): array {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}
	$out  = array();
	$seen = array();

	$by_cat = wc_get_products(
		array(
			'status'   => 'publish',
			'limit'    => 200,
			'category' => array( (string) config()['cat'] ),
		)
	);
	foreach ( (array) $by_cat as $p ) {
		$out[]                 = $p;
		$seen[ $p->get_id() ] = true;
	}

	// 분류가 빠진 노보 상품도 있을 수 있다. 이름으로 한 번 더 훑는다.
	$by_name = wc_get_products( array( 'status' => 'publish', 'limit' => 400, 's' => '노보' ) );
	foreach ( (array) $by_name as $p ) {
		if ( ! isset( $seen[ $p->get_id() ] ) && '' !== line( $p ) ) {
			$out[]                = $p;
			$seen[ $p->get_id() ] = true;
		}
	}
	return $out;
}

/**
 * 이 상품에 맞는 이벤트 기준 가격. 대상이 아니면 0.
 *
 * 단품(1병)과 10+1(설정의 set 병)만 대상이다. 그 밖의 묶음은 이벤트가 정한 값이 없다.
 *
 * @param \WC_Product $p 상품.
 * @return int
 */
function target_price( \WC_Product $p ): int {
	$c   = config();
	$key = line( $p );
	if ( '' === $key || ! isset( $c['lines'][ $key ] ) ) {
		return 0;
	}
	$n = bottles( $p );
	if ( 1 === $n ) {
		return (int) $c['lines'][ $key ]['single'];
	}
	if ( (int) $c['set'] === $n ) {
		return (int) $c['lines'][ $key ]['bundle'];
	}
	return 0;
}

/**
 * 가격 적용 · 되돌리기.
 *
 * @param string $mode 'apply' 또는 'revert'.
 * @return array{done:int,skip:int,notes:string[]}
 */
function run( string $mode ): array {
	$done  = 0;
	$skip  = 0;
	$notes = array();

	foreach ( products() as $p ) {
		$id  = $p->get_id();
		$now = (float) $p->get_regular_price();

		if ( 'revert' === $mode ) {
			$was = $p->get_meta( BEFORE );
			if ( '' === (string) $was ) {
				++$skip;
				continue;
			}
			$p->set_regular_price( (string) $was );
			$p->set_sale_price( '' );
			$p->delete_meta_data( BEFORE );
			$p->save();
			++$done;
			$notes[] = sprintf( '#%d %s → %s원 (되돌림)', $id, $p->get_name(), number_format( (float) $was ) );
			continue;
		}

		$want = target_price( $p );
		if ( $want <= 0 ) {
			++$skip;
			continue;
		}
		if ( (int) $now === $want && '' === (string) $p->get_sale_price() ) {
			++$skip;
			continue;
		}
		if ( '' === (string) $p->get_meta( BEFORE ) ) {
			$p->update_meta_data( BEFORE, (string) $now );
		}
		$p->set_regular_price( (string) $want );
		// 이벤트 가격이 곧 판매가다. 할인가가 남아 있으면 그쪽이 이긴다.
		$p->set_sale_price( '' );
		$p->save();
		++$done;
		$notes[] = sprintf( '#%d %s : %s원 → %s원', $id, $p->get_name(), number_format( $now ), number_format( (float) $want ) );
	}

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
	// 우리 상품 캐시도 비운다.
	if ( function_exists( 'Duckhoo\\Redesign\\Front\\flush_cache' ) ) {
		\Duckhoo\Redesign\Front\flush_cache();
	}
	return array( 'done' => $done, 'skip' => $skip, 'notes' => $notes );
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
	// 배너 이미지 고르기 — 워드프레스 미디어 창을 쓴다.
	wp_enqueue_media();

	$result = null;
	$saved  = false;
	$mode   = isset( $_POST['dhr_novo_mode'] ) ? sanitize_key( wp_unslash( $_POST['dhr_novo_mode'] ) ) : '';
	if ( in_array( $mode, array( 'apply', 'revert' ), true ) ) {
		check_admin_referer( 'dhr-novo' );
		$result = run( $mode );
	} elseif ( 'banner' === $mode ) {
		check_admin_referer( 'dhr-novo' );
		update_option( 'duckhoo_novo_banner_img', esc_url_raw( wp_unslash( $_POST['dhr_novo_img'] ?? '' ) ) );
		update_option( 'duckhoo_novo_banner_img_m', esc_url_raw( wp_unslash( $_POST['dhr_novo_img_m'] ?? '' ) ) );
		$saved = true;
	}

	$c    = config();
	$list = products();

	echo '<div class="wrap"><h1>노보 이벤트</h1>';
	echo '<p>노보 물량 이벤트의 <b>기준 가격</b>과 실제 상품의 <b>판매가 · 재고</b>를 나란히 봅니다. '
		. '판매가는 언제나 워드커머스가 쥔 값입니다 — 이 화면은 그 값을 대조하고, 눌렀을 때만 바꿉니다.</p>';

	echo '<table class="widefat" style="max-width:760px;margin:1em 0"><tbody>';
	printf(
		'<tr><th style="width:220px">하루 구매 한도</th><td>낱병 <b>%d병</b>까지 · 10+1 묶음 <b>한 세트</b>까지 (사은품 1병은 세지 않습니다) · %s</td></tr>',
		(int) limit(),
		'all' === (string) $c['scope'] ? '노보 전체 합산' : '라인별 따로'
	);
	foreach ( (array) $c['lines'] as $meta ) {
		printf(
			'<tr><th>%s 기준 가격</th><td>1병 <b>%s원</b> · 10+1 <b>%s원</b></td></tr>',
			esc_html( (string) $meta['label'] ),
			esc_html( number_format( (int) $meta['single'] ) ),
			esc_html( number_format( (int) $meta['bundle'] ) )
		);
	}
	printf( '<tr><th>이벤트</th><td>%s</td></tr>', on() ? '켜짐' : '<b style="color:#B42318">꺼짐</b> — 한도가 걸리지 않습니다' );
	echo '</tbody></table>';

	if ( $result ) {
		printf(
			'<div class="notice notice-success"><p><b>%d개</b> 바꿨습니다. %d개는 그대로 두었습니다.</p>%s</div>',
			(int) $result['done'],
			(int) $result['skip'],
			$result['notes'] ? '<pre style="margin:0 0 8px;white-space:pre-wrap">' . esc_html( implode( "\n", $result['notes'] ) ) . '</pre>' : ''
		);
	}

	echo '<table class="widefat striped"><thead><tr>'
		. '<th>상품</th><th>라인</th><th>병</th><th>지금 판매가</th><th>이벤트 기준</th><th>재고 관리</th><th>남은 수량</th><th>상태</th>'
		. '</tr></thead><tbody>';

	$diff = 0;
	foreach ( $list as $p ) {
		$want  = target_price( $p );
		$now   = (int) round( (float) $p->get_regular_price() );
		$sale  = (string) $p->get_sale_price();
		$stock = stock_left( $p );
		$key   = line( $p );
		$label = '' !== $key && isset( $c['lines'][ $key ] ) ? (string) $c['lines'][ $key ]['label'] : '—';

		if ( $want <= 0 ) {
			$state = '<span style="color:#616870">이벤트 대상 아님</span>';
		} elseif ( $now === $want && '' === $sale ) {
			$state = '<b style="color:#1F5F46">맞음</b>';
		} else {
			++$diff;
			$state = '<b style="color:#B42318">다름</b>' . ( '' !== $sale ? ' (할인가 ' . esc_html( number_format( (float) $sale ) ) . '원)' : '' );
		}

		printf(
			'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s원</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_url( (string) get_edit_post_link( $p->get_id() ) ),
			esc_html( $p->get_name() ),
			esc_html( $label ),
			esc_html( bottles( $p ) === paid( $p ) ? (string) bottles( $p ) : bottles( $p ) . ' (' . paid( $p ) . ')' ),
			esc_html( number_format( $now ) ),
			$want > 0 ? esc_html( number_format( $want ) ) . '원' : '—',
			$p->managing_stock() ? '켜짐' : '<span style="color:#B42318">꺼짐</span>',
			null === $stock ? '—' : (int) $stock . '개',
			$state // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}
	echo '</tbody></table>';

	echo '<form method="post" style="margin:1.2em 0">';
	wp_nonce_field( 'dhr-novo' );
	printf(
		'<button class="button button-primary" name="dhr_novo_mode" value="apply" onclick="return confirm(\'노보 상품 %d개의 판매가를 이벤트 기준 가격으로 바꿉니다. 계속할까요?\')">이벤트 가격 적용</button> ',
		(int) $diff
	);
	echo '<button class="button" name="dhr_novo_mode" value="revert" onclick="return confirm(\'바꾸기 전 가격으로 되돌립니다. 계속할까요?\')">이전 가격으로 되돌리기</button>';
	echo '</form>';

	$dhr_img  = (string) get_option( 'duckhoo_novo_banner_img', '' );
	$dhr_imgm = (string) get_option( 'duckhoo_novo_banner_img_m', '' );

	echo '<h2>노보 분류 배너</h2>';
	if ( $saved ) {
		echo '<div class="notice notice-success"><p>배너를 저장했습니다.</p></div>';
	}
	echo '<p>노보 분류 목록 맨 위에 그립니다. 이미지를 고르면 그 이미지를, 비워 두면 플러그인이 그린 글자판을 씁니다. '
		. '<a href="' . esc_url( (string) get_term_link( (string) $c['cat'], 'product_cat' ) ) . '" target="_blank" rel="noopener">화면 보기</a></p>';
	echo '<form method="post" style="margin:0 0 2em">';
	wp_nonce_field( 'dhr-novo' );
	echo '<table class="form-table" role="presentation"><tbody>';
	printf(
		'<tr><th scope="row"><label for="dhr_novo_img">배너 이미지</label></th><td>'
		. '<input type="url" class="regular-text code" id="dhr_novo_img" name="dhr_novo_img" value="%s" placeholder="https://…">'
		. ' <button type="button" class="button dhr-pick" data-target="dhr_novo_img">미디어에서 고르기</button>'
		. '<p class="description">가로로 긴 이미지를 권합니다 (예: 2000×740). 폭에 맞춰 줄어듭니다.</p>'
		. '%s</td></tr>',
		esc_attr( $dhr_img ),
		$dhr_img ? '<p><img src="' . esc_url( $dhr_img ) . '" alt="" style="max-width:520px;height:auto;border-radius:10px"></p>' : ''
	);
	printf(
		'<tr><th scope="row"><label for="dhr_novo_img_m">폰용 이미지 (선택)</label></th><td>'
		. '<input type="url" class="regular-text code" id="dhr_novo_img_m" name="dhr_novo_img_m" value="%s" placeholder="비워 두면 위 이미지를 씁니다">'
		. ' <button type="button" class="button dhr-pick" data-target="dhr_novo_img_m">미디어에서 고르기</button>'
		. '<p class="description">가로로 긴 이미지는 폰에서 글자가 작아집니다. 세로로 조금 긴 판을 따로 두면 읽기 좋습니다.</p>'
		. '%s</td></tr>',
		esc_attr( $dhr_imgm ),
		$dhr_imgm ? '<p><img src="' . esc_url( $dhr_imgm ) . '" alt="" style="max-width:320px;height:auto;border-radius:10px"></p>' : ''
	);
	echo '</tbody></table>';
	echo '<button class="button button-primary" name="dhr_novo_mode" value="banner">배너 저장</button>';
	echo '</form>';
	echo '<script>
jQuery(function($){ $(".dhr-pick").on("click", function(e){ e.preventDefault();
  var id = $(this).data("target");
  var f = wp.media({ title: "배너 이미지 고르기", library: { type: "image" }, button: { text: "이 이미지 쓰기" }, multiple: false })
    .on("select", function(){ var a = f.state().get("selection").first().toJSON(); $("#" + id).val(a.url); })
    .open();
}); });
</script>';

	echo '<h2>남은 수량이 안 보인다면</h2>'
		. '<p>남은 수량은 워드커머스 <b>재고 관리</b>가 켜진 상품에만 나옵니다. 위 표의 <b>재고 관리</b>가 <b>꺼짐</b>인 상품은 '
		. '상품 편집 → 재고 → <b>재고 관리</b>를 켜고 수량을 넣어 주세요. 숫자는 워드커머스가 주문마다 알아서 줄입니다.</p>'
		. '<h2>10+1 상품</h2>'
		. '<p>이벤트 기준 가격의 <b>10+1</b> 은 이름에 <code>10+1</code> 이 들어간 상품(11병)에 맞춰집니다. '
		. '지금 있는 <code>노보 10병</code> 묶음은 병 수가 달라 대상이 아닙니다 — 10+1 로 바꾸시려면 상품 이름과 옵션 수를 11병으로 고쳐 주세요.</p>';

	echo '</div>';
}
