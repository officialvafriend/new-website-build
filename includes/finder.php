<?php
/**
 * 도구 → 코드 찾기 (관리자 · 읽기 전용).
 *
 * 화면에 뜨는 문구가 어느 코드에서 나오는지 찾는다. 이 가게의 동작은 테마
 * functions.php · 테마 템플릿 · 사장님 Code Snippets 에 흩어져 있고 그 파일은
 * 이 저장소에 없다. 2026-09-11 결제 화면의 「주문 금액이 올바르게 계산되지
 * 않았습니다」가 그랬다 — 우리 플러그인에는 그 글자가 없다.
 *
 * 하는 일: 테마 폴더(부모 · 자식)의 PHP · JS 파일과 Code Snippets 표
 * (`{prefix}snippets`)에서 낱말을 찾아 앞뒤 몇 줄을 보여 준다.
 * **아무것도 고치지 않는다.** 결과는 화면에만 나온다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Finder;

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-finder';

/**
 * 메뉴.
 *
 * @return void
 */
function menu(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	add_management_page( '코드 찾기', '코드 찾기', 'manage_options', SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 파일에서 찾는다.
 *
 * @param string $needle 낱말.
 * @param int    $ctx    앞뒤 줄 수.
 * @return array<int,array{where:string,line:int,text:string}>
 */
function in_files( string $needle, int $ctx = 12 ): array {
	$out  = array();
	$dirs = array_unique( array_filter( array(
		function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() : '',
		function_exists( 'get_template_directory' ) ? get_template_directory() : '',
	) ) );
	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ! preg_match( '/\.(php|js)$/', $f->getFilename() ) || $f->getSize() > 3_000_000 ) {
				continue;
			}
			$lines = @file( $f->getPathname(), FILE_IGNORE_NEW_LINES );
			if ( ! is_array( $lines ) ) {
				continue;
			}
			foreach ( $lines as $i => $l ) {
				if ( false === mb_stripos( $l, $needle ) ) {
					continue;
				}
				$a     = max( 0, $i - $ctx );
				$b     = min( count( $lines ) - 1, $i + $ctx );
				$out[] = array(
					'where' => str_replace( dirname( $dir ), '', $f->getPathname() ),
					'line'  => $i + 1,
					'text'  => implode( "\n", array_map( fn( $n ) => sprintf( '%5d  %s', $n + 1, $lines[ $n ] ), range( $a, $b ) ) ),
				);
				if ( count( $out ) > 40 ) {
					return $out;
				}
			}
		}
	}
	return $out;
}

/**
 * Code Snippets 표에서 찾는다.
 *
 * @param string $needle 낱말.
 * @param int    $ctx    앞뒤 줄 수.
 * @return array<int,array{where:string,line:int,text:string}>
 */
function in_snippets( string $needle, int $ctx = 12 ): array {
	global $wpdb;
	$out = array();
	if ( ! isset( $wpdb ) ) {
		return $out;
	}
	$table = $wpdb->prefix . 'snippets';
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return $out;
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, code, active, scope FROM {$table} WHERE code LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%' ), ARRAY_A ); // phpcs:ignore
	foreach ( (array) $rows as $r ) {
		$lines = explode( "\n", (string) $r['code'] );
		foreach ( $lines as $i => $l ) {
			if ( false === mb_stripos( $l, $needle ) ) {
				continue;
			}
			$a     = max( 0, $i - $ctx );
			$b     = min( count( $lines ) - 1, $i + $ctx );
			$out[] = array(
				'where' => sprintf( '스니펫 #%d 「%s」 (%s · %s)', (int) $r['id'], (string) $r['name'], (string) $r['scope'], ! empty( $r['active'] ) ? '켜짐' : '꺼짐' ),
				'line'  => $i + 1,
				'text'  => implode( "\n", array_map( fn( $n ) => sprintf( '%5d  %s', $n + 1, $lines[ $n ] ), range( $a, $b ) ) ),
			);
		}
	}
	return $out;
}

/**
 * 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : ''; // phpcs:ignore
	echo '<div class="wrap"><h1>코드 찾기</h1>';
	echo '<p>화면에 뜨는 문구를 그대로 넣으면 테마 파일과 Code Snippets 에서 그 글자가 있는 자리를 보여 줍니다. 읽기만 합니다.</p>';
	echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( SLUG ) . '">';
	echo '<input type="text" name="q" value="' . esc_attr( $q ) . '" class="regular-text" placeholder="예) 올바르게 계산되지"> <button class="button button-primary">찾기</button></form>';
	if ( '' === $q || mb_strlen( $q ) < 3 ) {
		echo '</div>';
		return;
	}
	$hits = array_merge( in_snippets( $q ), in_files( $q ) );
	echo '<h2>' . count( $hits ) . '곳</h2>';
	if ( ! $hits ) {
		echo '<p>테마 · 스니펫에 없습니다. 그러면 플러그인 쪽입니다 — 그 문구를 저에게 알려 주세요.</p>';
	}
	foreach ( $hits as $h ) {
		echo '<h3 style="margin-bottom:.3em">' . esc_html( $h['where'] ) . ' <small>' . (int) $h['line'] . '행</small></h3>';
		echo '<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;font-size:12px;line-height:1.45;max-height:420px">' . esc_html( $h['text'] ) . '</pre>';
	}
	echo '</div>';
}
