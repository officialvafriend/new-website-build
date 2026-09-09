<?php
/**
 * 9월 금액대별 자동 할인(10만원 이상 10,000원)을 끈다.
 *
 * 사장님 결정 2026-09-09: 이 이벤트를 없앤다. **테마 파일은 건드리지 않는다** —
 * 전에 테마를 고쳤다가 사이트가 멈춘 적이 있다.
 *
 * **할인이 테마에 두 번 적혀 있다** (`도구 → 자동 할인 진단` 으로 찾았다):
 *
 *   (가) 돈을 깎는 쪽 — `functions.php:10668` 의 익명 함수가
 *        `woocommerce_cart_calculate_fees` 20 에서 `add_fee( '🎁 금액 자동 할인', -N )`
 *   (나) 결제 화면 — `woocommerce/checkout/form-checkout.php:145` 의 「표시 안정화」
 *        블록이 **fee 를 무시하고 스스로 다시 계산**한다 (`$wd_auto_fee_discount`)
 *
 * (가)만 떼면 결제 화면은 10,000 을 계속 빼서 실제 금액과 갈라진다 — 프로덕션에서
 * 총액이 0원이 됐다 (2026-09-08~09, 세 번). 그래서 **둘을 같이** 꺼야 한다.
 *
 * **테마 파일을 고치지 않고 (나)를 끄는 법.** 워드커머스는 템플릿을 열 때마다
 * `wc_get_template` 필터로 「어느 파일을 쓸까」를 묻는다. 우리는 테마 파일을 읽어
 * `$wd_auto_fee_discount = 10000;` 의 **숫자만 0 으로 바꾼 사본**을 만들어 두고
 * 그 사본의 경로를 돌려준다. 테마 파일은 열지도 쓰지도 않는다.
 *
 * - 바꾸는 것은 정수 리터럴 하나뿐이라 **문법이 깨질 수 없다**
 * - 사본은 테마 파일의 mtime · 크기로 이름을 지어 두므로 테마가 바뀌면 다시 만든다
 * - 한 군데도 못 찾으면 사본을 만들지 않고 **원본을 그대로 돌려준다** — 그때는
 *   할인도 끄지 않는다 (모를 때는 건드리지 않는 쪽)
 * - 되돌리기: 플러그인 비활성화, 또는
 *   `add_filter( 'duckhoo_auto_discount_mode', fn() => 'off' );`
 *
 * 모드(`duckhoo_auto_discount_mode`):
 *   `off`     아무것도 안 한다 — 할인이 지금처럼 그대로
 *   `preview` 쿠키 `dhr_disc_off` 를 가진 사람에게만 끈다 (라이브에서 확인용)
 *   `on`      모두에게 끈다
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Discount;

defined( 'ABSPATH' ) || exit;

const TEMPLATE  = 'checkout/form-checkout.php';
const VAR_NAME  = 'wd_auto_fee_discount';
const PEEK_COOK = 'dhr_disc_off';

/**
 * 어떤 모드인가 — `off` · `preview` · `on`.
 *
 * @return string
 */
function mode(): string {
	$m = (string) apply_filters( 'duckhoo_auto_discount_mode', 'on' );
	return in_array( $m, array( 'off', 'preview', 'on' ), true ) ? $m : 'off';
}

/**
 * 이 요청에서 할인을 끄기를 **원하는가** (끌 수 있는지는 별개다).
 *
 * @return bool
 */
function wanted(): bool {
	$m = mode();
	if ( 'on' === $m ) {
		return true;
	}
	if ( 'preview' === $m ) {
		return isset( $_COOKIE[ PEEK_COOK ] ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	return false;
}

/**
 * 결제 템플릿의 실제 경로. 테마 override 가 있으면 그것이다.
 *
 * @return string
 */
function template_file(): string {
	if ( function_exists( 'wc_locate_template' ) ) {
		$f = (string) wc_locate_template( TEMPLATE );
		if ( '' !== $f ) {
			return $f;
		}
	}
	return get_theme_root() . '/' . get_template() . '/woocommerce/' . TEMPLATE;
}

/**
 * 파일을 한 번만 읽는다 (요청 안에서).
 *
 * @param string $file 파일.
 * @return string
 */
function read( string $file ): string {
	static $memo = array();
	$key = $file . '|' . (int) filemtime( $file );
	if ( ! isset( $memo[ $key ] ) ) {
		$memo[ $key ] = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return $memo[ $key ];
}

/**
 * 그 템플릿이 아직 **스스로 다시 계산**하는가.
 *
 * @return bool
 */
function template_recomputes(): bool {
	$file = template_file();
	if ( ! is_readable( $file ) ) {
		return true; // 모르면 「아직 있다」로 본다.
	}
	return false !== strpos( read( $file ), VAR_NAME );
}

/**
 * 사본을 둘 자리. 없으면 만든다.
 *
 * @return string 디렉터리 경로. 못 만들면 빈 문자열.
 */
function cache_dir(): string {
	$up = wp_upload_dir();
	if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) {
		return '';
	}
	$dir = rtrim( (string) $up['basedir'], '/' ) . '/duckhoo-tpl';
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return '';
	}
	// 직접 열리지 않게. (진짜 방어는 사본 안의 ABSPATH 검사다.)
	$guards = array(
		'index.html' => '',
		'.htaccess'  => "deny from all\n",
	);
	foreach ( $guards as $name => $body ) {
		$path = $dir . '/' . $name;
		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	return $dir;
}

/**
 * 재계산 블록의 숫자만 0 으로 바꾼 사본을 만든다.
 *
 * 바꾸는 것은 `$wd_auto_fee_discount = <정수>;` 의 정수뿐이다. 다른 줄은 한 글자도
 * 건드리지 않으므로 문법이 깨질 수 없다.
 *
 * @param string $file 원본 템플릿.
 * @return string 사본 경로. 못 만들면 빈 문자열.
 */
function patched( string $file ): string {
	static $memo = array();
	if ( '' === $file || ! is_readable( $file ) ) {
		return '';
	}
	$key = $file . '|' . (int) filemtime( $file ) . '|' . (int) filesize( $file );
	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}
	$memo[ $key ] = '';

	$src = read( $file );
	if ( false === strpos( $src, VAR_NAME ) ) {
		return ''; // 이미 없다 — 사본이 필요 없다.
	}
	$hits = 0;
	$out  = (string) preg_replace(
		'/(\$' . VAR_NAME . '\s*=\s*)[0-9]+(?:\.[0-9]+)?(\s*;)/',
		'${1}0${2}',
		$src,
		-1,
		$hits
	);
	if ( $hits < 1 || '' === $out ) {
		return ''; // 못 찾았다 — 아무것도 하지 않는다.
	}
	// 워드프레스 밖에서 열리지 않게 한 줄 세운다 (원본에 없을 때만).
	if ( false === strpos( $out, 'ABSPATH' ) ) {
		$out = (string) preg_replace( '/^\s*<\?php/', "<?php defined( 'ABSPATH' ) || exit;", $out, 1 );
	}

	$dir = cache_dir();
	if ( '' === $dir ) {
		return '';
	}
	$path = $dir . '/fc-' . md5( $key ) . '.php';
	if ( ! is_readable( $path ) || filesize( $path ) !== strlen( $out ) ) {
		if ( false === file_put_contents( $path, $out ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}
	}
	$memo[ $key ] = $path;
	return $path;
}

/**
 * 실제로 끌 수 있는가 — 재계산 블록이 없거나, 우리가 사본을 세워 뒀거나.
 *
 * @return bool
 */
function neutralized(): bool {
	if ( ! template_recomputes() ) {
		return true;
	}
	return '' !== patched( template_file() );
}

/**
 * 이 요청에서 자동 할인을 끄는가.
 *
 * @return bool
 */
function killing(): bool {
	if ( ! wanted() ) {
		return false;
	}
	if ( ! apply_filters( 'duckhoo_kill_auto_discount', true ) ) {
		return false;
	}
	return neutralized();
}

/**
 * 워드커머스가 결제 템플릿을 열 때 우리 사본을 건넨다.
 *
 * @param string $template      워드커머스가 고른 파일.
 * @param string $template_name 템플릿 이름.
 * @return string
 */
function serve_patched( $template, $template_name = '' ) {
	if ( TEMPLATE !== $template_name || ! killing() ) {
		return $template;
	}
	$copy = patched( (string) $template );
	return '' !== $copy ? $copy : $template;
}
add_filter( 'wc_get_template', __NAMESPACE__ . '\\serve_patched', 99, 2 );

/**
 * 이 콜백의 원본 코드에 이 글자가 있는가.
 *
 * **줄 번호로 찾지 않는다.** 테마가 조금만 바뀌어도 줄이 밀린다. 그 함수가 실제로
 * 무엇을 하는지(= `add_fee( '🎁 금액 자동 할인' … )`)로 알아본다. 못 찾으면 아무것도
 * 하지 않는다 — 할인이 되살아날 뿐, 다른 것을 잘못 떼지는 않는다.
 *
 * @param mixed  $cb     콜백.
 * @param string $needle 찾을 글자.
 * @return bool
 */
function source_has( $cb, string $needle ): bool {
	if ( ! $cb instanceof \Closure ) {
		return false;
	}
	try {
		$r    = new \ReflectionFunction( $cb );
		$file = (string) $r->getFileName();
		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}
		$from = max( 1, (int) $r->getStartLine() );
		$to   = (int) $r->getEndLine();
		$body = '';
		$fh   = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return false;
		}
		for ( $i = 1; ! feof( $fh ) && $i <= $to; $i++ ) {
			$line = fgets( $fh );
			if ( $i >= $from && false !== $line ) {
				$body .= $line;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false !== strpos( $body, $needle );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * 자동 할인을 붙이는 함수를 훅에서 뗀다.
 *
 * 이름이 붙은 다른 수수료(배송비 · 도서산간 · 적립금 할인)는 건드리지 않는다 —
 * 익명 함수이면서 그 안에 `금액 자동 할인` 을 쓰는 것 하나만 본다.
 *
 * @return void
 */
function kill(): void {
	if ( ! killing() ) {
		return;
	}
	global $wp_filter;
	$hook = $wp_filter['woocommerce_cart_calculate_fees'] ?? null;
	if ( ! $hook || ! isset( $hook->callbacks ) ) {
		return;
	}

	$needle = (string) apply_filters( 'duckhoo_auto_discount_marker', '금액 자동 할인' );
	$drop   = array();
	foreach ( (array) $hook->callbacks as $prio => $list ) {
		foreach ( (array) $list as $one ) {
			$cb = $one['function'] ?? null;
			if ( source_has( $cb, $needle ) ) {
				$drop[] = array( $cb, $prio );
			}
		}
	}
	// 돌면서 떼면 목록이 흔들린다. 다 찾은 뒤에 뗀다.
	foreach ( $drop as $one ) {
		remove_action( 'woocommerce_cart_calculate_fees', $one[0], (int) $one[1] );
	}
}
add_action( 'wp_loaded', __NAMESPACE__ . '\\kill', 99 );

/**
 * 끈 이벤트를 화면이 계속 광고하면 안 된다.
 *
 * 없는 혜택을 읽고 담은 손님은 결제 화면에서 배신당한다. 안내 문구의 규칙을 비운다 —
 * `front.js` 가 이것을 보고 장바구니 안내를 지운다.
 *
 * @param array<int,array<string,int>> $tiers 여태 규칙.
 * @return array<int,array<string,int>>
 */
function no_tiers( $tiers ): array {
	return killing() ? array() : (array) $tiers;
}
add_filter( 'duckhoo_auto_discount', __NAMESPACE__ . '\\no_tiers', 99 );

/**
 * 끄고 싶은데 못 끄고 있으면 관리자에게 왜인지 말해 준다. 조용히 안 되는 것이 제일 나쁘다.
 *
 * @return void
 */
function admin_notice(): void {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( 'off' === mode() || neutralized() ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && ! preg_match( '/woocommerce|tools|dashboard|plugins/i', (string) $screen->id ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><b>금액대별 자동 할인을 아직 끄지 못했습니다.</b> '
		. '테마 결제 화면(<code>woocommerce/checkout/form-checkout.php</code>)이 할인을 스스로 다시 '
		. '계산하는데, 그 자리를 찾지 못했습니다. 지금 끄면 결제 화면 금액이 장바구니와 갈리므로 '
		. '할인을 그대로 두었습니다.</p></div>';
}
add_action( 'admin_notices', __NAMESPACE__ . '\\admin_notice' );
