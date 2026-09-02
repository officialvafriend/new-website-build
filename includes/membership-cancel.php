<?php
/**
 * 회원탈퇴.
 *
 * 원래 사이트에는 /membership-cancel/ 페이지가 있지만 내용이 "/" 한 글자뿐인
 * 빈 페이지였다. 메뉴만 있고 뒤가 비어 있었던 것이다. 여기서 그 뒤를 채운다.
 *
 * 계정을 지우지 않는다. 로그인을 막고 개인정보만 지운다.
 * 주문은 그대로 남긴다 — 전자상거래법상 거래기록은 5년 보존이다.
 *
 * @package DuckhooRedesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\MembershipCancel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WITHDRAWN_META = '_duckhoo_withdrawn_at';
const NONCE_ACTION   = 'duckhoo_membership_cancel';
const DONE_QUERY_ARG = 'duckhoo_withdrawn';

/**
 * 아직 끝나지 않은 주문의 상태 슬러그.
 *
 * 슬러그는 keyple 이 정하는 값이라 하드코딩하지 않는다. 등록된 상태 전부에서
 * 끝난 것들을 빼는 방식이라, keyple 이 상태를 더해도 자동으로 따라간다.
 *
 * @return string[]
 */
function open_order_statuses(): array {
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		return array();
	}

	$finished = array( 'completed', 'cancelled', 'refunded', 'failed', 'draft', 'checkout-draft' );

	$all = array();
	foreach ( wc_get_order_statuses() as $slug => $label ) {
		$slug  = (string) preg_replace( '/^wc-/', '', (string) $slug );
		$all[] = $slug;

		if ( '배송완료' === trim( wp_strip_all_tags( (string) $label ) ) ) {
			$finished[] = $slug;
		}
	}

	return array_values( array_diff( $all, $finished ) );
}

/**
 * 진행 중인 주문 수.
 *
 * @param int $user_id 회원 ID.
 * @return int
 */
function open_order_count( int $user_id ): int {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}

	$statuses = open_order_statuses();

	if ( ! $statuses ) {
		return 0;
	}

	$orders = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'status'      => $statuses,
			'limit'       => 20,
			'return'      => 'ids',
		)
	);

	return is_array( $orders ) ? count( $orders ) : 0;
}

/**
 * 남은 적립금.
 *
 * 적립금은 keyple-customer 가 관리하고 저장 키가 공개돼 있지 않다. 그래서
 * 필터를 먼저 보고, 없으면 회원 메타에서 이름으로 찾는다. 둘 다 실패하면
 * null 을 돌려준다 — 모르는 값으로 탈퇴를 막지는 않는다.
 *
 * 정확한 키를 알게 되면 이 한 줄이면 된다:
 *   add_filter( 'duckhoo_member_points', fn( $v, $id ) => (float) get_user_meta( $id, '<키>', true ), 10, 2 );
 *
 * @param int $user_id 회원 ID.
 * @return float|null 모르면 null.
 */
function point_balance( int $user_id ): ?float {
	$given = apply_filters( 'duckhoo_member_points', null, $user_id );

	if ( null !== $given ) {
		return (float) $given;
	}

	foreach ( get_user_meta( $user_id ) as $key => $values ) {
		if ( ! preg_match( '/(point|mileage|reward|적립)/i', (string) $key ) ) {
			continue;
		}

		$raw = maybe_unserialize( $values[0] ?? '' );

		if ( is_numeric( $raw ) ) {
			return (float) $raw;
		}
	}

	return null;
}

/**
 * 탈퇴를 막는 이유들. 비어 있으면 탈퇴할 수 있다.
 *
 * @param int $user_id 회원 ID.
 * @return string[]
 */
function blockers( int $user_id ): array {
	$reasons = array();

	$open = open_order_count( $user_id );
	if ( $open > 0 ) {
		$reasons[] = sprintf(
			/* translators: %d: 진행 중인 주문 수 */
			__( '아직 끝나지 않은 주문이 %d건 있습니다. 배송이 끝난 뒤에 탈퇴해 주세요.', 'duckhoo-redesign' ),
			$open
		);
	}

	$points = point_balance( $user_id );
	if ( null !== $points && $points > 0 ) {
		$reasons[] = sprintf(
			/* translators: %s: 남은 적립금 */
			__( '적립금이 %s원 남아 있습니다. 다 쓰신 뒤에 탈퇴해 주세요. 탈퇴하면 사라지고 되돌릴 수 없습니다.', 'duckhoo-redesign' ),
			number_format_i18n( $points )
		);
	}

	return apply_filters( 'duckhoo_membership_cancel_blockers', $reasons, $user_id );
}

/**
 * 개인정보를 지우고 로그인을 막습니다. 계정과 주문은 남습니다.
 *
 * @param int $user_id 회원 ID.
 * @return void
 */
function withdraw( int $user_id ): void {
	$user = get_userdata( $user_id );

	if ( ! $user ) {
		return;
	}

	update_user_meta( $user_id, WITHDRAWN_META, current_time( 'mysql' ) );

	// 이름·연락처·주소를 지웁니다. 주문에 박힌 배송정보는 거래기록이라 건드리지 않습니다.
	$personal = array(
		'first_name',
		'last_name',
		'nickname',
		'description',
		'billing_first_name',
		'billing_last_name',
		'billing_company',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_phone',
		'billing_email',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_company',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_phone',
	);

	foreach ( $personal as $key ) {
		delete_user_meta( $user_id, $key );
	}

	// 이메일이 바뀌었다는 안내 메일은 보내지 않습니다. 탈퇴한 사람에게 갈 메일이 아닙니다.
	add_filter( 'send_email_change_email', '__return_false', 99 );
	add_filter( 'send_password_change_email', '__return_false', 99 );

	wp_update_user(
		array(
			'ID'           => $user_id,
			'display_name' => __( '탈퇴회원', 'duckhoo-redesign' ),
			'nickname'     => __( '탈퇴회원', 'duckhoo-redesign' ),
			'user_url'     => '',
			'user_email'   => sprintf( 'withdrawn-%d@duck-hoo.invalid', $user_id ),
		)
	);

	// 아무도 모르는 비밀번호로 바꿔 로그인을 끊습니다. 세션도 함께 정리됩니다.
	wp_set_password( wp_generate_password( 64, true, true ), $user_id );

	remove_filter( 'send_email_change_email', '__return_false', 99 );
	remove_filter( 'send_password_change_email', '__return_false', 99 );

	do_action( 'duckhoo_member_withdrawn', $user_id );
}

/**
 * 탈퇴한 계정은 로그인시키지 않습니다.
 *
 * 비밀번호를 이미 바꿔 놨지만, 비밀번호 재설정 메일 같은 다른 경로가 남아 있으므로
 * 인증 단계에서 한 번 더 막습니다.
 *
 * @param \WP_User|\WP_Error|null $user 인증 결과.
 * @return \WP_User|\WP_Error|null
 */
function block_withdrawn_login( $user ) {
	if ( $user instanceof \WP_User && get_user_meta( $user->ID, WITHDRAWN_META, true ) ) {
		return new \WP_Error(
			'duckhoo_withdrawn',
			__( '탈퇴한 계정입니다. 다시 이용하시려면 새로 가입해 주세요.', 'duckhoo-redesign' )
		);
	}

	return $user;
}
add_filter( 'authenticate', __NAMESPACE__ . '\\block_withdrawn_login', 30 );

/**
 * 탈퇴 요청을 처리합니다.
 *
 * @return void
 */
function handle_submit(): void {
	if ( empty( $_POST['duckhoo_membership_cancel'] ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, NONCE_ACTION ) ) {
		return;
	}

	// 동의 체크는 브라우저에서도 막지만 서버에서 한 번 더 봅니다.
	if ( empty( $_POST['duckhoo_agree'] ) ) {
		return;
	}

	$user_id = get_current_user_id();

	if ( blockers( $user_id ) ) {
		return;
	}

	// 비밀번호를 한 번 더 받습니다. 남의 자리에서 눌러 지워지는 일이 없도록.
	$password = isset( $_POST['duckhoo_password'] ) ? (string) wp_unslash( $_POST['duckhoo_password'] ) : '';
	$user     = get_userdata( $user_id );

	if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
		set_transient( 'duckhoo_cancel_error_' . $user_id, __( '비밀번호가 맞지 않습니다.', 'duckhoo-redesign' ), MINUTE_IN_SECONDS * 5 );

		return;
	}

	withdraw( $user_id );
	wp_logout();

	wp_safe_redirect( add_query_arg( DONE_QUERY_ARG, '1', get_permalink() ) );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\handle_submit' );

/**
 * 탈퇴 화면을 그립니다.
 *
 * @return string
 */
function render(): string {
	if ( isset( $_GET[ DONE_QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '<div class="dh-leave dh-leave--done">'
			. '<h2>' . esc_html__( '탈퇴가 완료됐습니다', 'duckhoo-redesign' ) . '</h2>'
			. '<p>' . esc_html__( '그동안 이용해 주셔서 고맙습니다. 이름과 연락처는 지웠고, 주문 기록은 법에 따라 5년간 보관한 뒤 폐기합니다.', 'duckhoo-redesign' ) . '</p>'
			. '<p><a class="dh-leave__link" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( '홈으로', 'duckhoo-redesign' ) . '</a></p>'
			. '</div>';
	}

	if ( ! is_user_logged_in() ) {
		return '<div class="dh-leave">'
			. '<h2>' . esc_html__( '회원탈퇴', 'duckhoo-redesign' ) . '</h2>'
			. '<p>' . esc_html__( '로그인한 뒤에 진행할 수 있습니다.', 'duckhoo-redesign' ) . '</p>'
			. '<p><a class="dh-leave__link" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( '로그인하기', 'duckhoo-redesign' ) . '</a></p>'
			. '</div>';
	}

	$user_id = get_current_user_id();
	$stop    = blockers( $user_id );

	ob_start();
	?>
	<div class="dh-leave">
		<h2><?php esc_html_e( '회원탈퇴', 'duckhoo-redesign' ); ?></h2>

		<?php if ( $stop ) : ?>
			<div class="dh-leave__stop">
				<p class="dh-leave__stop-title"><?php esc_html_e( '지금은 탈퇴할 수 없습니다.', 'duckhoo-redesign' ); ?></p>
				<ul>
					<?php foreach ( $stop as $reason ) : ?>
						<li><?php echo esc_html( $reason ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php if ( function_exists( 'wc_get_account_endpoint_url' ) ) : ?>
				<p><a class="dh-leave__link" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>"><?php esc_html_e( '주문내역 보기', 'duckhoo-redesign' ); ?></a></p>
			<?php endif; ?>
		<?php else : ?>
			<div class="dh-leave__warn">
				<p><?php esc_html_e( '탈퇴하면 로그인할 수 없게 되고, 이름·연락처·주소는 지워집니다. 되돌릴 수 없습니다.', 'duckhoo-redesign' ); ?></p>
				<p><?php esc_html_e( '주문 기록은 남습니다. 전자상거래법상 거래기록은 5년간 보관해야 합니다.', 'duckhoo-redesign' ); ?></p>
				<p><?php esc_html_e( '같은 번호로 다시 가입할 수 있지만, 예전 주문과 적립금은 이어지지 않습니다.', 'duckhoo-redesign' ); ?></p>
			</div>

			<?php
			$error = get_transient( 'duckhoo_cancel_error_' . $user_id );
			if ( $error ) {
				delete_transient( 'duckhoo_cancel_error_' . $user_id );
				echo '<p class="dh-leave__error" role="alert">' . esc_html( (string) $error ) . '</p>';
			}
			?>

			<form method="post" class="dh-leave__form">
				<?php wp_nonce_field( NONCE_ACTION ); ?>
				<input type="hidden" name="duckhoo_membership_cancel" value="1">

				<label class="dh-leave__field" for="duckhoo_password">
					<span><?php esc_html_e( '비밀번호', 'duckhoo-redesign' ); ?></span>
					<input type="password" id="duckhoo_password" name="duckhoo_password" autocomplete="current-password" required>
				</label>

				<label class="dh-leave__agree">
					<input type="checkbox" name="duckhoo_agree" value="1" required>
					<span><?php esc_html_e( '위 내용을 읽었고, 되돌릴 수 없다는 것을 이해했습니다.', 'duckhoo-redesign' ); ?></span>
				</label>

				<button type="submit" class="dh-leave__submit"><?php esc_html_e( '탈퇴하기', 'duckhoo-redesign' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}
add_shortcode( 'duckhoo_membership_cancel', __NAMESPACE__ . '\\render' );

/**
 * 비어 있는 회원탈퇴 페이지를 채웁니다.
 *
 * 페이지에 숏코드를 직접 넣어 두면 그쪽이 우선입니다. 여기서는 슬러그가
 * membership-cancel 이면서 내용이 사실상 비어 있을 때만 대신 그립니다.
 *
 * @param string $content 원래 내용.
 * @return string
 */
function fill_empty_page( string $content ): string {
	if ( ! is_page() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$page = get_post();

	if ( ! $page || 'membership-cancel' !== $page->post_name ) {
		return $content;
	}

	if ( has_shortcode( (string) $page->post_content, 'duckhoo_membership_cancel' ) ) {
		return $content;
	}

	// "/" 한 글자짜리 빈 페이지일 때만 대신 그립니다.
	if ( mb_strlen( trim( wp_strip_all_tags( $content ) ) ) > 40 ) {
		return $content;
	}

	if ( ! apply_filters( 'duckhoo_fill_membership_cancel_page', true ) ) {
		return $content;
	}

	return render();
}
add_filter( 'the_content', __NAMESPACE__ . '\\fill_empty_page' );
