<?php
/**
 * Plugin Name:       액상덕후 리디자인
 * Plugin URI:        https://github.com/officialvafriend/new-website-build
 * Description:       액상덕후 리디자인 — 홈 화면(실제 상품 연동), 입금 전 주문취소, 회원탈퇴. 테마와 keyple 플러그인은 건드리지 않습니다.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            officialvafriend
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       duckhoo-redesign
 *
 * @package DuckhooRedesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign;

// 워드프레스를 거치지 않은 직접 접근을 막습니다.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.4.0';

// 회원탈퇴 — 원래 페이지가 비어 있어서 여기서 채웁니다.
require_once plugin_dir_path( __FILE__ ) . 'includes/membership-cancel.php';
// 홈 — 첫 화면을 플러그인이 그립니다. 테마 파일은 건드리지 않습니다.
require_once plugin_dir_path( __FILE__ ) . 'includes/front.php';

/**
 * 프론트엔드에 디자인 토큰을 불러옵니다.
 *
 * 지금 불러오는 CSS 는 :root 커스텀 속성만 정의하므로 화면은 바뀌지 않습니다.
 * 배포 경로가 실제로 동작하는지 확인하는 것이 목적입니다. 실제 스타일은
 * 디자인 방향이 확정된 뒤 별도 핸들로 추가하고 여기에 의존성으로 겁니다.
 */
function enqueue_tokens(): void {
	$relative = 'assets/tokens.css';
	$path     = plugin_dir_path( __FILE__ ) . $relative;

	if ( ! file_exists( $path ) ) {
		return;
	}

	// 파일 수정 시각을 버전으로 써서 배포 직후 캐시가 남지 않게 합니다.
	wp_enqueue_style(
		'duckhoo-tokens',
		plugin_dir_url( __FILE__ ) . $relative,
		array(),
		(string) filemtime( $path )
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_tokens' );

/**
 * 블록 에디터에서도 같은 토큰을 쓸 수 있게 합니다.
 *
 * 에디터와 프론트엔드가 다른 값을 쓰면 편집 화면에서 본 것과 실제 화면이
 * 어긋나므로 같은 파일을 불러옵니다. enqueue_block_assets 는 프론트엔드에서도
 * 발동해 같은 파일을 두 번 불러오므로 에디터 전용 훅을 씁니다.
 */
function enqueue_editor_tokens(): void {
	$relative = 'assets/tokens.css';
	$path     = plugin_dir_path( __FILE__ ) . $relative;

	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_enqueue_style(
		'duckhoo-tokens-editor',
		plugin_dir_url( __FILE__ ) . $relative,
		array(),
		(string) filemtime( $path )
	);
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_tokens' );

/**
 * 회원탈퇴 화면 스타일을 불러옵니다.
 *
 * 그 화면에서만 씁니다. 상품 목록처럼 카드가 수십 개 깔리는 곳에 쓸데없는
 * CSS 를 얹지 않기 위해서입니다.
 */
function enqueue_membership_cancel_style(): void {
	$post = get_post();

	if ( ! $post || 'membership-cancel' !== $post->post_name ) {
		return;
	}

	$relative = 'assets/membership-cancel.css';
	$path     = plugin_dir_path( __FILE__ ) . $relative;

	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_enqueue_style(
		'duckhoo-membership-cancel',
		plugin_dir_url( __FILE__ ) . $relative,
		array( 'duckhoo-tokens' ),
		(string) filemtime( $path )
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_membership_cancel_style', 20 );

/**
 * 고객이 직접 취소할 수 있는 주문 상태를 넓힙니다.
 *
 * WooCommerce 는 기본적으로 pending·failed 주문에만 "취소" 링크를 내보냅니다.
 * 이 사이트의 주문은 keyple-order-status 가 붙인 한국형 상태로 들어가므로
 * 그 목록에 걸리지 않고, 그래서 마이페이지에 취소 버튼 자체가 그려지지 않습니다.
 * 고객이 취소를 못 하는 원인이 여기입니다.
 *
 * 여는 것은 입금전 하나뿐입니다. 확인필요는 입금 문자가 이미 들어온 뒤의
 * 상태라 고객이 스스로 취소하면 환불 처리가 남습니다. 그건 사람이 봐야 합니다.
 */
function customer_cancellable_statuses(): array {
	static $slugs = null;

	if ( null !== $slugs ) {
		return $slugs;
	}

	$slugs = array();

	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		return $slugs;
	}

	// 슬러그는 keyple 이 정하는 값이라 하드코딩하지 않고 이름표로 찾습니다.
	// 슬러그가 바뀌어도 이름표가 그대로면 계속 동작합니다.
	foreach ( wc_get_order_statuses() as $slug => $label ) {
		if ( '입금전' === trim( wp_strip_all_tags( (string) $label ) ) ) {
			$slugs[] = (string) preg_replace( '/^wc-/', '', (string) $slug );
		}
	}

	return $slugs;
}

/**
 * 취소 가능 상태 목록에 입금전을 더합니다.
 *
 * 기존 목록은 지우지 않고 더하기만 합니다. WooCommerce 나 다른 플러그인이
 * 넣어 둔 상태를 뺏지 않기 위해서입니다.
 *
 * @param array $statuses 취소 가능 상태 슬러그 목록.
 * @return array
 */
function allow_cancel_before_deposit( $statuses ): array {
	return array_values( array_unique( array_merge( (array) $statuses, customer_cancellable_statuses() ) ) );
}
add_filter( 'woocommerce_valid_order_statuses_for_cancel', __NAMESPACE__ . '\\allow_cancel_before_deposit' );
