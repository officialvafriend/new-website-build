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
function in_files( string $needle, int $ctx = 12, bool $all_plugins = false ): array {
	$out  = array();
	$dirs = array_unique( array_filter( array_merge(
		array(
			function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() : '',
			function_exists( 'get_template_directory' ) ? get_template_directory() : '',
		),
		plugin_dirs( $all_plugins )
	) ) );
	// 화면이 멈추는 것보다 「여기까지」가 낫다 — 매출 화면과 같은 생각이다.
	$deadline = microtime( true ) + (float) apply_filters( 'duckhoo_finder_budget', 12 );
	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) || microtime( true ) > $deadline ) {
			continue;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
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
 * 뒤질 플러그인 폴더.
 *
 * **기본은 키플 · 우리 것만** 본다. 플러그인이 53개라 전부 뒤지면 워드커머스만으로도
 * 수천 개 파일이라 화면이 한참 멈춘다. 사장님이 「플러그인 전부」를 켰을 때만 넓힌다.
 * 적립금 · 쿠폰처럼 **플러그인 PHP 안에 있는 문구**를 찾느라 여러 번 막혔다 (2026-09-14).
 *
 * @param bool $all 전부 볼 것인가.
 * @return string[]
 */
function plugin_dirs( bool $all = false ): array {
	if ( ! defined( 'WP_PLUGIN_DIR' ) || ! is_dir( WP_PLUGIN_DIR ) ) {
		return array();
	}
	$globs = (array) apply_filters( 'duckhoo_finder_plugins', array( 'keyple-*', 'duckhoo-*' ) );
	if ( $all ) {
		$globs = array( '*' );
	}
	$out = array();
	foreach ( $globs as $g ) {
		foreach ( (array) glob( WP_PLUGIN_DIR . '/' . $g, GLOB_ONLYDIR ) as $d ) {
			$out[] = $d;
		}
	}
	return array_values( array_unique( $out ) );
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
	$q   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : ''; // phpcs:ignore
	$all = ! empty( $_GET['all'] ); // phpcs:ignore
	echo '<div class="wrap"><h1>코드 찾기</h1>';
	echo '<p>화면에 뜨는 문구를 그대로 넣으면 그 글자가 있는 자리를 보여 줍니다 — <strong>테마 · Code Snippets · 키플 플러그인</strong>. 읽기만 합니다.</p>';
	echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( SLUG ) . '">';
	echo '<input type="text" name="q" value="' . esc_attr( $q ) . '" class="regular-text" placeholder="예) 쿠폰함에서 확인"> ';
	echo '<label style="margin-left:.6em"><input type="checkbox" name="all" value="1"' . checked( $all, true, false ) . '> 플러그인 전부 (느립니다)</label> ';
	echo '<button class="button button-primary">찾기</button></form>';
	if ( '' === $q || mb_strlen( $q ) < 3 ) {
		echo '</div>';
		return;
	}
	$t0   = microtime( true );
	$hits = array_merge( in_snippets( $q ), in_files( $q, 12, $all ) );
	echo '<h2>' . count( $hits ) . '곳 <small style="font-weight:400">(' . number_format( microtime( true ) - $t0, 1 ) . '초)</small></h2>';
	if ( ! $hits ) {
		echo '<p>' . ( $all
			? '어디에도 없습니다. 글자가 조금 다를 수 있으니 <strong>짧은 조각</strong>으로 다시 찾아 보세요 (예: 「쿠폰함」).'
			: '테마 · 스니펫 · 키플 플러그인에 없습니다. <strong>플러그인 전부</strong>를 켜고 다시 찾아 보세요.' ) . '</p>';
	}
	foreach ( $hits as $h ) {
		echo '<h3 style="margin-bottom:.3em">' . esc_html( $h['where'] ) . ' <small>' . (int) $h['line'] . '행</small></h3>';
		echo '<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;font-size:12px;line-height:1.45;max-height:420px">' . esc_html( $h['text'] ) . '</pre>';
	}
	echo '</div>';
}
