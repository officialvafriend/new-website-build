<?php
/**
 * 계정 · 회원가입 화면.
 *
 * 테마는 모든 페이지 위에 `<h1 class="wd-page__title">` 를 왼쪽 위에 하나 찍는다.
 * 마이페이지에서는 그것이 "내 계정" 이라는 낱말 하나로 남아, 가운데 좁은 칸에
 * 들어앉은 폼과 아무 관계 없이 떠 있었다. 제목을 지우는 대신 **제목이 있어야 할
 * 자리를 만든다** — 어두운 머리판 하나를 모든 계정 · 가입 화면 맨 위에 두고,
 * 지금 어느 화면인지 · 무엇을 하는 중인지 · 몇 단계 중 어디인지를 그 안에 담는다.
 *
 * 회원가입은 키플이 만든 페이지 세 장으로 나뉘어 있다 (`/register/` → `/agree/`
 * → `/join-form/`). 세 장 모두 자기 헤더 · 카테고리 줄 · 푸터를 따로 그리고
 * 링크가 프로덕션(duck-hoo.com)을 가리킨다. 그것들은 CSS 로 걷어내고 우리 껍데기만
 * 남긴다. **폼 자체 · 필드 이름 · 본인확인 버튼에는 손대지 않는다.**
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Account;

use function Duckhoo\Redesign\Shell\wraps;
use function Duckhoo\Redesign\Front\signup_points;

defined( 'ABSPATH' ) || exit;

/**
 * 가입 단계. 세 장의 슬러그와 그 자리.
 *
 * @return array<string,array{int,string,string}>
 */
function steps(): array {
	return (array) apply_filters(
		'duckhoo_signup_steps',
		array(
			'register'  => array( 1, '회원가입', '' ),
			'agree'     => array( 2, '약관 동의', '필수 약관에 동의하면 다음으로 넘어갑니다.' ),
			'join-form' => array( 3, '정보 입력', '휴대폰 본인확인을 먼저 하면 이름 · 연락처 · 생년월일이 저절로 채워집니다.' ),
		)
	);
}

/**
 * 머리판. 모든 계정 · 가입 화면이 같은 것을 쓴다.
 *
 * @param string               $eyebrow 작은 윗줄.
 * @param string               $title   제목. 화면에 하나뿐인 h1 이 된다.
 * @param string               $sub     한 줄 설명.
 * @param string[]             $chips   유리 알약. 짧은 낱말만.
 * @param int                  $step    가입 단계 (0 이면 안 그린다).
 * @param string               $avatar  아바타 글자 (없으면 안 그린다).
 * @param bool                 $h1      h1 로 그릴지. 테마 h1 이 살아 있는 화면에서는 false.
 * @return void
 */
function hero( string $eyebrow, string $title, string $sub = '', array $chips = array(), int $step = 0, string $avatar = '', bool $h1 = true ): void {
	$tag = $h1 ? 'h1' : 'p';
	?>
	<header class="dhr-ah<?php echo $avatar ? ' dhr-ah--me' : ''; ?>">
		<span class="dhr-ah__bg" aria-hidden="true"></span>
		<div class="dhr-ah__in">
			<?php if ( $avatar ) : ?>
			<span class="dhr-ah__av" aria-hidden="true"><?php echo esc_html( $avatar ); ?></span>
			<?php endif; ?>
			<div class="dhr-ah__txt">
				<?php if ( $eyebrow ) : ?><p class="dhr-ah__eb"><?php echo esc_html( $eyebrow ); ?></p><?php endif; ?>
				<<?php echo esc_html( $tag ); ?> class="dhr-ah__t"><?php echo esc_html( $title ); ?></<?php echo esc_html( $tag ); ?>>
				<?php if ( $sub ) : ?><p class="dhr-ah__s"><?php echo esc_html( $sub ); ?></p><?php endif; ?>
			</div>
			<?php if ( $chips ) : ?>
			<ul class="dhr-ah__chips">
				<?php foreach ( $chips as $c ) : ?><li><?php echo esc_html( $c ); ?></li><?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>
		<?php if ( $step > 0 ) : ?>
		<ol class="dhr-ah__steps">
			<?php foreach ( steps() as $s ) : ?>
			<li class="<?php echo $s[0] === $step ? 'is-now' : ( $s[0] < $step ? 'is-done' : '' ); ?>"<?php echo $s[0] === $step ? ' aria-current="step"' : ''; ?>>
				<span class="dhr-ah__no"><?php echo $s[0] < $step ? '✓' : (int) $s[0]; ?></span><?php echo esc_html( $s[1] ); ?>
			</li>
			<?php endforeach; ?>
		</ol>
		<?php endif; ?>
	</header>
	<?php
}

/**
 * 지금 보고 있는 마이페이지 화면 이름. 없으면 "내 계정".
 *
 * @return string
 */
function endpoint_title(): string {
	if ( ! function_exists( 'wc_get_account_menu_items' ) ) {
		return '내 계정';
	}
	foreach ( wc_get_account_menu_items() as $ep => $label ) {
		if ( 'dashboard' !== $ep && is_wc_endpoint_url( $ep ) ) {
			return (string) $label;
		}
	}
	return '내 계정';
}

/**
 * 로그인 화면 머리판. 여기가 가게의 현관이다.
 *
 * @return void
 */
function login_hero(): void {
	if ( ! wraps() ) {
		return;
	}
	// 문의처럼 로그인이 필요한 화면에서 온 사람에게는 왜 여기에 왔는지 알려 준다.
	$from = isset( $_GET['redirect_to'] ) ? rawurldecode( wp_unslash( (string) $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$sub  = ( '' !== $from && false !== strpos( $from, '/inquiries' ) )
		? '문의를 남기려면 로그인이 필요합니다. 로그인하면 문의 게시판으로 돌아갑니다.'
		: '주문내역 · 적립금 · 배송지가 한곳에 있습니다.';
	hero(
		'액상덕후 회원',
		'로그인',
		$sub,
		array( '가입 즉시 ' . number_format_i18n( signup_points() ) . '원 적립', '19세 이상 본인확인' )
	);
}
add_action( 'woocommerce_before_customer_login_form', __NAMESPACE__ . '\\login_hero', 5 );

/**
 * 로그인 화면 아래 회원가입 안내.
 *
 * 여기에는 회원가입으로 가는 길이 아예 없었다. 처음 온 사람이 로그인 칸만 보고
 * 되돌아간다. 가입 단계(`/register/`)로 보내는 카드를 폼 바로 아래에 붙인다.
 *
 * @return void
 */
function login_join(): void {
	if ( ! wraps() || is_user_logged_in() ) {
		return;
	}
	?>
	<section class="dhr-join">
		<div class="dhr-join__txt">
			<b>아직 회원이 아니신가요?</b>
			<span><?php echo esc_html( number_format_i18n( signup_points() ) ); ?>원 적립금을 드립니다. 휴대폰 본인확인만 하면 이름 · 연락처가 저절로 채워집니다.</span>
		</div>
		<a class="dhr-join__go" href="<?php echo esc_url( home_url( '/register/' ) ); ?>">회원가입</a>
	</section>
	<?php
}
add_action( 'woocommerce_after_customer_login_form', __NAMESPACE__ . '\\login_join', 20 );

/**
 * 로그인 뒤 원래 가려던 곳으로 돌려보낸다.
 *
 * 문의 게시판처럼 로그인이 필요한 화면에서 우리 로그인으로 보낼 때 `?redirect_to=` 를
 * 달아 두면, 로그인이 끝나고 그 화면으로 돌아온다. 주소는 `wp_validate_redirect()` 로
 * 같은 사이트인지 확인한다 — 바깥으로 튀는 주소는 버린다.
 *
 * @return void
 */
function login_redirect_field(): void {
	if ( ! wraps() || is_user_logged_in() ) {
		return;
	}
	$raw = isset( $_GET['redirect_to'] ) ? rawurldecode( wp_unslash( (string) $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $raw ) {
		return;
	}
	$safe = wp_validate_redirect( $raw, '' );
	if ( '' === $safe ) {
		return;
	}
	echo '<input type="hidden" name="redirect" value="' . esc_attr( $safe ) . '">';
}
add_action( 'woocommerce_login_form', __NAMESPACE__ . '\\login_redirect_field' );

/**
 * 로그인한 회원의 머리판. 메뉴 · 내용보다 먼저 나온다.
 *
 * @return void
 */
function account_hero(): void {
	if ( ! wraps() || ! is_user_logged_in() ) {
		return;
	}
	$user  = wp_get_current_user();
	$name  = $user->display_name ? $user->display_name : $user->user_login;
	$chips = array();

	if ( function_exists( 'wc_get_customer_order_count' ) ) {
		$n = (int) wc_get_customer_order_count( $user->ID );
		if ( $n > 0 ) {
			$chips[] = '주문 ' . number_format_i18n( $n ) . '건';
		}
	}
	if ( function_exists( 'Duckhoo\\Redesign\\MembershipCancel\\point_balance' ) ) {
		$p = \Duckhoo\Redesign\MembershipCancel\point_balance( $user->ID );
		if ( null !== $p && $p > 0 ) {
			$chips[] = '적립금 ' . number_format_i18n( (int) round( $p ) ) . '원';
		}
	}
	$chips[] = '무통장입금 전용';

	hero( $name . ' 님', endpoint_title(), '', $chips, 0, mb_substr( $name, 0, 1 ) );
}
add_action( 'woocommerce_account_navigation', __NAMESPACE__ . '\\account_hero', 5 );

/**
 * 지금 보고 있는 가입 단계. 아니면 null.
 *
 * @return array{int,string,string}|null
 */
function current_step(): ?array {
	if ( ! wraps() || ! is_page() ) {
		return null;
	}
	foreach ( steps() as $slug => $step ) {
		if ( is_page( $slug ) ) {
			return $step;
		}
	}
	return null;
}

/**
 * 가입 세 화면의 머리판.
 *
 * 이 세 장은 페이지 본문이 아니라 템플릿이 통째로 그린다 — `the_content` 가
 * 아예 돌지 않는다. 그래서 우리 헤더 바로 뒤(`wp_body_open`)에 붙인다.
 *
 * @return void
 */
function signup_hero(): void {
	$step = current_step();
	if ( null === $step ) {
		return;
	}
	list( $no, $title, $sub ) = $step;

	$chips = 3 === $no
		? array( '만 19세 이상', '본인확인 먼저' )
		: array( '가입 즉시 ' . number_format_i18n( signup_points() ) . '원 적립', '1분 소요' );

	// 1단계는 제목이 곧 "회원가입" 이라 윗줄까지 같은 말이면 두 번 읽힌다.
	echo '<div class="dhr-authtop"><div class="wrap">';
	hero( 1 === $no ? '액상덕후 회원' : '회원가입', $title, $sub, $chips, $no );
	echo '</div></div>';
}
add_action( 'wp_body_open', __NAMESPACE__ . '\\signup_hero', 6 );

/**
 * 가입 화면 아래, 로그인으로 돌아가는 길. 세 장 어디에도 없었다.
 *
 * @return void
 */
function signup_back(): void {
	if ( null === current_step() ) {
		return;
	}
	echo '<div class="dhr-authtop dhr-authtop--end"><div class="wrap"><p class="dhr-back">이미 회원이신가요? <a href="'
		. esc_url( (string) wc_get_page_permalink( 'myaccount' ) ) . '">로그인</a></p></div></div>';
}
add_action( 'wp_footer', __NAMESPACE__ . '\\signup_back', 4 );

/**
 * 가입 화면에도 우리 본문 폭을 준다. 테마 페이지 제목은 머리판이 대신하므로 숨긴다
 * (CSS 에서 — 마크업은 그대로 둔다).
 *
 * @param string[] $classes 클래스.
 * @return string[]
 */
function body_class( array $classes ): array {
	if ( wraps() && ( is_page( array_keys( steps() ) ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) ) {
		$classes[] = 'dhr-auth';
	}
	if ( wraps() && is_page( array_keys( steps() ) ) ) {
		$classes[] = 'dhr-signup';
	}
	if ( wraps() && is_page( 'register' ) ) {
		$classes[] = 'dhr-signup-1';
	}
	return $classes;
}
add_filter( 'body_class', __NAMESPACE__ . '\\body_class', 20 );
