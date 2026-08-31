<?php
/**
 * Plugin Name:       액상덕후 리디자인
 * Plugin URI:        https://github.com/officialvafriend/new-website-build
 * Description:       액상덕후 사이트 리디자인용 디자인 토큰과 프론트엔드 스타일. 현재 v0 — 토큰 변수만 정의하며 화면을 바꾸지 않습니다.
 * Version:           0.1.0
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

const VERSION = '0.1.0';

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
