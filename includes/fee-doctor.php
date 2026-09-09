<?php
/**
 * 도구 → 자동 할인 진단 (관리자 · 읽기 전용).
 *
 * `🎁 금액 자동 할인` 을 **누가 붙이는지** 찾는다. 사장님 Code Snippets 활성 16개를
 * 다 열어 봤는데 없었고, 쿠폰 목록 · 쿠폰 노출 설정에도 없었다. 남은 곳은 테마이거나
 * 52개 플러그인 중 하나다 — 밖에서는 알 수 없으니 사이트 안에서 물어본다.
 *
 * **아무것도 바꾸지 않는다.** 훅에 걸린 콜백의 이름과 파일 · 줄 번호를 읽고,
 * wp-content 안에서 그 문구가 든 파일을 찾아 보여 줄 뿐이다.
 *
 * 찾고 나면 이 파일과 `duckhoo-redesign.php` 의 require 한 줄을 지운다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\FeeDoctor;

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-fee-doctor';

/**
 * 메뉴.
 *
 * @return void
 */
function menu(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	add_management_page( '자동 할인 진단', '자동 할인 진단', 'manage_options', SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 콜백이 어느 파일 몇 번째 줄에 있는지.
 *
 * @param mixed $cb 콜백.
 * @return string
 */
function where( $cb ): string {
	try {
		if ( is_string( $cb ) && function_exists( $cb ) ) {
			$r = new \ReflectionFunction( $cb );
		} elseif ( $cb instanceof \Closure ) {
			$r = new \ReflectionFunction( $cb );
		} elseif ( is_array( $cb ) && count( $cb ) === 2 ) {
			$r = new \ReflectionMethod( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0], (string) $cb[1] );
		} else {
			return '?';
		}
		$f = (string) $r->getFileName();
		$f = str_replace( WP_CONTENT_DIR, 'wp-content', $f );
		return $f . ':' . $r->getStartLine();
	} catch ( \Throwable $e ) {
		return '읽을 수 없음';
	}
}

/**
 * 콜백의 이름.
 *
 * @param mixed $cb 콜백.
 * @return string
 */
function name( $cb ): string {
	if ( is_string( $cb ) ) {
		return $cb;
	}
	if ( $cb instanceof \Closure ) {
		return '(익명 함수)';
	}
	if ( is_array( $cb ) && count( $cb ) === 2 ) {
		return ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) . '::' . (string) $cb[1];
	}
	return '(알 수 없음)';
}

/**
 * wp-content 안에서 이 글자가 든 PHP 파일을 찾는다.
 *
 * @param string[] $needles 찾을 글자들.
 * @param int      $limit   최대 몇 개까지.
 * @return array<int,array{file:string,line:int,text:string,needle:string}>
 */
function grep( array $needles, int $limit = 40 ): array {
	$hits  = array();
	$start = microtime( true );
	$dirs  = array( get_theme_root(), WP_PLUGIN_DIR );
	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $it as $file ) {
			if ( count( $hits ) >= $limit || microtime( true ) - $start > 20 ) {
				return $hits;
			}
			if ( ! $file->isFile() || 'php' !== strtolower( (string) $file->getExtension() ) ) {
				continue;
			}
			if ( $file->getSize() > 3000000 ) {
				continue;
			}
			$path = (string) $file->getPathname();
			// 우리 플러그인은 건너뛴다 — 우리가 아닌 것을 찾는 중이다.
			if ( false !== strpos( $path, 'new-website-build' ) ) {
				continue;
			}
			$body = (string) @file_get_contents( $path ); // phpcs:ignore
			foreach ( $needles as $needle ) {
				if ( false === strpos( $body, $needle ) ) {
					continue;
				}
				foreach ( explode( "\n", $body ) as $i => $ln ) {
					if ( false !== strpos( $ln, $needle ) ) {
						$hits[] = array(
							'file'   => str_replace( WP_CONTENT_DIR, 'wp-content', $path ),
							'line'   => $i + 1,
							'text'   => trim( mb_substr( $ln, 0, 180 ) ),
							'needle' => $needle,
						);
						break;
					}
				}
			}
		}
	}
	return $hits;
}

/**
 * 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '권한이 없습니다.' );
	}
	global $wp_filter;

	echo '<div class="wrap"><h1>자동 할인 진단</h1>';
	echo '<p>「🎁 금액 자동 할인」을 <b>누가 붙이는지</b> 찾습니다. 아무것도 바꾸지 않습니다. '
		. '아래 두 상자를 통째로 복사해 Claude 에게 보내 주세요.</p>';

	echo '<h2>1. 장바구니 수수료 훅에 걸린 것</h2>';
	echo '<textarea readonly style="width:100%;height:260px;font-family:monospace;font-size:12px">';
	foreach ( array( 'woocommerce_cart_calculate_fees', 'woocommerce_before_calculate_totals' ) as $hook ) {
		echo esc_textarea( "== {$hook} ==\n" );
		$h = $wp_filter[ $hook ] ?? null;
		if ( ! $h || ! isset( $h->callbacks ) ) {
			echo esc_textarea( "  (없음)\n\n" );
			continue;
		}
		foreach ( (array) $h->callbacks as $prio => $list ) {
			foreach ( (array) $list as $one ) {
				$cb = $one['function'] ?? null;
				echo esc_textarea( sprintf( "  [%s] %s\n        %s\n", (string) $prio, name( $cb ), where( $cb ) ) );
			}
		}
		echo esc_textarea( "\n" );
	}
	echo '</textarea>';

	echo '<h2>2. 그 문구가 든 파일</h2>';
	$hits = grep( array( '금액 자동 할인', '자동 할인', 'auto_discount' ) );
	echo '<textarea readonly style="width:100%;height:260px;font-family:monospace;font-size:12px">';
	if ( ! $hits ) {
		echo esc_textarea( "찾지 못했습니다.\n" );
	}
	foreach ( $hits as $h ) {
		echo esc_textarea( sprintf( "%s:%d  [%s]\n    %s\n", $h['file'], $h['line'], $h['needle'], $h['text'] ) );
	}
	echo '</textarea>';

	echo '<h2 style="background:#FFE02E;padding:.4rem .6rem;display:inline-block;border-radius:6px">3. 그 코드 원문 — 이 상자를 복사해 주세요</h2>';
	echo '<p>찾은 자리는 <code>테마/functions.php:10669~10700</code> 다. 규칙을 그대로 읽어야 '
		. '<b>같은 규칙에 노보만 빼서</b> 다시 지을 수 있다.</p>';
	echo '<textarea readonly style="width:100%;height:520px;font-family:monospace;font-size:12px">';
	foreach ( array(
		array( get_theme_root() . '/' . get_template() . '/functions.php', 10655, 10710 ),
		array( get_theme_root() . '/' . get_template() . '/functions.php', 1935, 1960 ),
		array( get_theme_root() . '/' . get_template() . '/woocommerce/checkout/form-checkout.php', 120, 165 ),
		array( get_theme_root() . '/' . get_template() . '/page-cart.php', 40, 75 ),
		// duckhoo-front 가 우선순위 100 에 뭔가를 건다. 저장소 사본(183줄)보다 실제
		// 파일이 길다 — 누군가 업데이트했다. 그 부분을 봐야 한다.
		array( WP_PLUGIN_DIR . '/duckhoo-front/duckhoo-front.php', 180, 260 ),
	) as $one ) {
		list( $path, $from, $to ) = $one;
		echo esc_textarea( '== ' . str_replace( WP_CONTENT_DIR, 'wp-content', $path ) . " {$from}~{$to} ==\n" );
		if ( ! is_readable( $path ) ) {
			echo esc_textarea( "  (읽을 수 없음)\n\n" );
			continue;
		}
		$lines = file( $path );
		for ( $i = $from - 1; $i < min( $to, count( $lines ) ); $i++ ) {
			echo esc_textarea( sprintf( '%5d  %s', $i + 1, (string) $lines[ $i ] ) );
		}
		echo esc_textarea( "\n" );
	}
	echo '</textarea>';

	echo '<p style="margin-top:1em;color:#616870">찾고 나면 이 화면은 지웁니다.</p></div>';
}
