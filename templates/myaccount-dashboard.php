<?php
/**
 * 마이페이지 첫 화면.
 *
 * 워드커머스 기본 화면은 "안녕하세요 …님" 한 문단이 전부다. 주문내역도 배송지도
 * 옆 메뉴를 눌러야 나온다. 자주 가는 곳을 타일로 꺼내 둔다.
 *
 * 데이터는 읽기만 한다. 엔드포인트 주소는 워드커머스가 알려주는 것을 그대로 쓴다.
 *
 * @package Duckhoo\Redesign
 */

defined( 'ABSPATH' ) || exit;

$dhr_user    = wp_get_current_user();
$dhr_name    = $dhr_user->display_name ? $dhr_user->display_name : $dhr_user->user_login;
$dhr_account = wc_get_page_permalink( 'myaccount' );
$dhr_orders  = wc_get_customer_order_count( $dhr_user->ID );

$dhr_tiles = array(
	array( 'orders', '주문내역', $dhr_orders ? $dhr_orders . '건' : '아직 없습니다', 'receipt' ),
	array( 'edit-address', '배송지', '주소를 저장해 두면 주문이 빨라집니다', 'home' ),
	array( 'edit-account', '계정 정보', '이름 · 연락처 · 비밀번호', 'user' ),
);
$dhr_extra = array();
foreach ( wc_get_account_menu_items() as $dhr_ep => $dhr_label ) {
	if ( in_array( $dhr_ep, array( 'dashboard', 'orders', 'edit-address', 'edit-account', 'customer-logout' ), true ) ) {
		continue;
	}
	$dhr_extra[ $dhr_ep ] = $dhr_label;
}
?>
<div class="dhr-acc">
	<?php // 인사말은 머리판(includes/account.php)이 그린다 — 여기서 또 그리면 이름이 두 번 나온다 ?>
	<p class="dhr-acc__note">무통장입금 전용입니다. 입금자명이 주문자명과 같으면 자동으로 확인됩니다.</p>

	<div class="dhr-acc__grid">
		<?php foreach ( $dhr_tiles as $dhr_t ) : ?>
		<a class="dhr-acc__tile" href="<?php echo esc_url( wc_get_endpoint_url( $dhr_t[0], '', $dhr_account ) ); ?>">
			<span class="dhr-acc__ic"><?php echo \Duckhoo\Redesign\Front\icon( $dhr_t[3] ); // phpcs:ignore ?></span>
			<b><?php echo esc_html( $dhr_t[1] ); ?></b>
			<span class="dhr-acc__sub"><?php echo esc_html( $dhr_t[2] ); ?></span>
		</a>
		<?php endforeach; ?>
	</div>

	<?php if ( $dhr_extra ) : ?>
	<div class="dhr-acc__more">
		<?php foreach ( $dhr_extra as $dhr_ep => $dhr_label ) : ?>
		<a href="<?php echo esc_url( wc_get_endpoint_url( $dhr_ep, '', $dhr_account ) ); ?>"><?php echo esc_html( $dhr_label ); ?></a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="dhr-acc__foot">
		<a class="dhr-acc__out" href="<?php echo esc_url( wc_logout_url( $dhr_account ) ); ?>">로그아웃</a>
		<a class="dhr-acc__leave" href="<?php echo esc_url( home_url( '/membership-cancel/' ) ); ?>">회원탈퇴</a>
	</div>
</div>
