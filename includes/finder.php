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
function in_files( string $needle, int $ctx = 12, bool $all_plugins = false, int $max = 40 ): array {
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
				if ( count( $out ) > $max ) {
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
 * 파일 통째로 보기 — 플러그인 폴더 안의 PHP · JS 한 파일. **읽기만 한다.**
 *
 * 낱말 하나씩 찾아 캡처를 부탁하는 대신, 사장님이 파일 하나를 열어 전체를 복사해 주면 된다
 * (2026-09-24 「이거 다 검색하라고?」). `..` · 절대경로 · 플러그인 폴더 밖은 거절한다.
 *
 * @param string $rel `keyple-bank-auto-confirm/includes/class-order-matcher.php` 꼴.
 * @return array{path:string,text:string}|null
 */
function read_file( string $rel ): ?array {
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		return null;
	}
	$rel = str_replace( '\\', '/', trim( $rel ) );
	if ( '' === $rel || str_contains( $rel, '..' ) || str_starts_with( $rel, '/' ) || ! preg_match( '/\.(php|js|txt|json|css)$/i', $rel ) ) {
		return null;
	}
	$base = realpath( WP_PLUGIN_DIR );
	$full = realpath( WP_PLUGIN_DIR . '/' . $rel );
	if ( false === $base || false === $full || ! str_starts_with( $full, $base . DIRECTORY_SEPARATOR ) || ! is_file( $full ) ) {
		return null;
	}
	$text = file_get_contents( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return false === $text ? null : array( 'path' => $rel, 'text' => $text );
}

/**
 * 플러그인 폴더 하나의 PHP · JS 파일 목록 (하위 폴더 포함 · 크기순 아님, 경로순).
 *
 * @param string $dir 폴더 이름 (`keyple-bank-auto-confirm`).
 * @return array<int,array{rel:string,bytes:int,lines:int}>
 */
function list_files( string $dir ): array {
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		return array();
	}
	$dir = trim( str_replace( array( '\\', '/' ), '', $dir ) );
	if ( '' === $dir || str_contains( $dir, '..' ) ) {
		return array();
	}
	$root = realpath( WP_PLUGIN_DIR . '/' . $dir );
	$base = realpath( WP_PLUGIN_DIR );
	if ( false === $root || false === $base || ! is_dir( $root ) || ! str_starts_with( $root, $base . DIRECTORY_SEPARATOR ) ) {
		return array();
	}
	$out = array();
	$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( ! $f->isFile() || ! preg_match( '/\.(php|js)$/i', $f->getFilename() ) ) {
			continue;
		}
		$rel = $dir . '/' . str_replace( '\\', '/', substr( $f->getPathname(), strlen( $root ) + 1 ) );
		if ( str_contains( $rel, '/vendor/' ) || str_contains( $rel, '/node_modules/' ) || str_contains( $rel, '.min.' ) ) {
			continue;
		}
		$out[] = array( 'rel' => $rel, 'bytes' => (int) $f->getSize(), 'lines' => (int) substr_count( (string) file_get_contents( $f->getPathname() ), "\n" ) + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( count( $out ) >= 400 ) {
			break;
		}
	}
	usort( $out, fn( $a, $b ) => strcmp( $a['rel'], $b['rel'] ) );
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
 * 찾을 낱말들 — `|` 로 여럿을 한 번에 (`BUNDLE_RATIO | 맛 선택을 총`). 3자 미만은 버린다.
 *
 * @param string $q 입력.
 * @return string[]
 */
function terms( string $q ): array {
	$out = array();
	foreach ( explode( '|', $q ) as $t ) {
		$t = trim( $t );
		if ( mb_strlen( $t ) >= 3 && ! in_array( $t, $out, true ) ) {
			$out[] = $t;
		}
	}
	return array_slice( $out, 0, 6 );
}

/**
 * 내려받을 글 — 찾은 자리를 전부 한 파일에 (화면은 40곳에서 자르지만 파일은 400곳까지).
 *
 * @param string $q   입력.
 * @param bool   $all 플러그인 전부.
 * @return string
 */
function report_text( string $q, bool $all ): string {
	$max = (int) apply_filters( 'duckhoo_finder_download_max', 400 );
	$t   = "코드 찾기 결과\n";
	$t  .= '사이트: ' . ( function_exists( 'home_url' ) ? home_url() : '' ) . "\n";
	$t  .= '시각: ' . ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i' ) : gmdate( 'Y-m-d H:i' ) ) . "\n";
	$t  .= '찾은 곳: 테마 · Code Snippets · ' . ( $all ? '플러그인 전부' : '키플 · 우리 플러그인' ) . "\n";
	foreach ( terms( $q ) as $term ) {
		$hits = array_merge( in_snippets( $term ), in_files( $term, 12, $all, $max ) );
		$t   .= "\n" . str_repeat( '=', 72 ) . "\n";
		$t   .= '「' . $term . '」 ' . count( $hits ) . "곳\n";
		$t   .= str_repeat( '=', 72 ) . "\n";
		foreach ( $hits as $i => $h ) {
			$t .= "\n--- " . ( $i + 1 ) . '. ' . $h['where'] . ' · ' . (int) $h['line'] . "행\n";
			$t .= $h['text'] . "\n";
		}
	}
	return $t;
}

/**
 * 결과를 .txt 로 내려받는다 (`admin-post.php?action=dhr_finder_txt`). 읽기만 한다.
 *
 * @return void
 */
function download(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '권한이 없습니다.' );
	}
	check_admin_referer( 'dhr_finder_txt' );
	$q   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : ''; // phpcs:ignore
	$all = ! empty( $_GET['all'] ); // phpcs:ignore
	if ( ! terms( $q ) ) {
		wp_die( '찾을 낱말이 없습니다 (3자 이상).' );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore
	}
	$body = report_text( $q, $all );
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="code-find-' . wp_date( 'Ymd-Hi' ) . '.txt"' );
	echo "\xEF\xBB\xBF" . $body; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}
add_action( 'admin_post_dhr_finder_txt', __NAMESPACE__ . '\\download' );

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
	echo '<input type="text" name="q" value="' . esc_attr( $q ) . '" class="regular-text" placeholder="예) 쿠폰함에서 확인  ·  여럿은 | 로: BUNDLE_RATIO | 맛 선택을 총"> ';
	echo '<label style="margin-left:.6em"><input type="checkbox" name="all" value="1"' . checked( $all, true, false ) . '> 플러그인 전부 (느립니다)</label> ';
	echo '<button class="button button-primary">찾기</button></form>';
	// ── 파일 통째로 보기 ──────────────────────────────────────────────────
	$dir  = isset( $_GET['dir'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['dir'] ) ) : ''; // phpcs:ignore
	$file = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['file'] ) ) : ''; // phpcs:ignore
	echo '<h2 style="margin-top:1.6em">파일 통째로 보기</h2>';
	echo '<p style="max-width:56em;line-height:1.7">낱말을 하나씩 찾는 대신 <b>파일 하나를 열어 전체를 복사</b>해 보내 주시면 됩니다. 플러그인 폴더 이름을 넣고 목록에서 파일을 누르세요. 읽기만 합니다.</p>';
	echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( SLUG ) . '">';
	echo '<input type="text" name="dir" value="' . esc_attr( '' !== $dir ? $dir : 'keyple-bank-auto-confirm' ) . '" class="regular-text" placeholder="keyple-bank-auto-confirm"> ';
	echo '<button class="button">파일 목록</button></form>';
	if ( '' !== $dir ) {
		$files = list_files( $dir );
		if ( ! $files ) {
			echo '<p>그 이름의 플러그인 폴더가 없거나 PHP · JS 파일이 없습니다.</p>';
		} else {
			echo '<ul style="columns:2;max-width:70em;margin:.6em 0 1em">';
			foreach ( $files as $f ) {
				$u = add_query_arg( array( 'page' => SLUG, 'dir' => $dir, 'file' => $f['rel'] ), admin_url( 'tools.php' ) );
				echo '<li><a href="' . esc_url( $u ) . '">' . esc_html( substr( $f['rel'], strlen( $dir ) + 1 ) ) . '</a> <small style="color:#646970">' . (int) $f['lines'] . '줄</small></li>';
			}
			echo '</ul>';
		}
	}
	if ( '' !== $file ) {
		$rf = read_file( $file );
		if ( ! $rf ) {
			echo '<p><b>그 파일을 열 수 없습니다.</b> (플러그인 폴더 안의 PHP · JS 만 봅니다)</p>';
		} else {
			$lines = explode( "\n", $rf['text'] );
			$w     = strlen( (string) count( $lines ) );
			$numbered = '';
			foreach ( $lines as $i => $l ) {
				$numbered .= str_pad( (string) ( $i + 1 ), $w, ' ', STR_PAD_LEFT ) . '  ' . $l . "\n";
			}
			echo '<h3 style="margin:.4em 0">' . esc_html( $rf['path'] ) . ' <small style="font-weight:400;color:#646970">' . count( $lines ) . '줄 · 아래 상자를 누르고 Ctrl+A → Ctrl+C</small></h3>';
			echo '<textarea readonly onclick="this.select()" style="width:100%;max-width:70em;height:520px;font-family:monospace;font-size:12px;line-height:1.45;white-space:pre;background:#f6f7f7;border:1px solid #dcdcde;padding:10px">' . esc_textarea( $numbered ) . '</textarea>';
		}
	}

	$terms = terms( $q );
	if ( ! $terms ) {
		echo '</div>';
		return;
	}
	$dl = wp_nonce_url( add_query_arg( array( 'action' => 'dhr_finder_txt', 'q' => rawurlencode( $q ), 'all' => $all ? '1' : '' ), admin_url( 'admin-post.php' ) ), 'dhr_finder_txt' );
	echo '<p style="margin:1.2em 0 .4em"><a class="button button-primary" href="' . esc_url( $dl ) . '">결과 전부 파일로 내려받기 (.txt)</a> <small style="color:#646970">화면은 낱말마다 40곳까지, 파일은 400곳까지 담습니다. 받은 파일을 그대로 보내 주시면 됩니다.</small></p>';
	foreach ( $terms as $term ) {
		$t0   = microtime( true );
		$hits = array_merge( in_snippets( $term ), in_files( $term, 12, $all ) );
		echo '<h2>「' . esc_html( $term ) . '」 ' . count( $hits ) . '곳 <small style="font-weight:400">(' . number_format( microtime( true ) - $t0, 1 ) . '초)</small></h2>';
		hits_html( $hits, $all );
	}
	echo '</div>';
}

/**
 * 찾은 자리를 화면에.
 *
 * @param array $hits 결과.
 * @param bool  $all  플러그인 전부였나.
 * @return void
 */
function hits_html( array $hits, bool $all ): void {
	if ( ! $hits ) {
		echo '<p>' . ( $all
			? '어디에도 없습니다. 글자가 조금 다를 수 있으니 <strong>짧은 조각</strong>으로 다시 찾아 보세요 (예: 「쿠폰함」).'
			: '테마 · 스니펫 · 키플 플러그인에 없습니다. <strong>플러그인 전부</strong>를 켜고 다시 찾아 보세요.' ) . '</p>';
	}
	foreach ( $hits as $h ) {
		echo '<h3 style="margin-bottom:.3em">' . esc_html( $h['where'] ) . ' <small>' . (int) $h['line'] . '행</small></h3>';
		echo '<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;font-size:12px;line-height:1.45;max-height:420px">' . esc_html( $h['text'] ) . '</pre>';
	}
}
