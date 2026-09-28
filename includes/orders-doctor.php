<?php
/**
 * 도구 → 주문내역 진단 (관리자, 읽기 전용) — 2026-09-28.
 *
 * 사장님: 「주문내역 안 보임 — 직원들 개인 계정으로는 나오는데 종종 다른 고객들은 안 보인다」 (#202609280005056 의 고객).
 * 손님 계정으로 로그인할 수 없어 화면을 직접 못 본다. 그래서 **워드커머스가 마이페이지 주문 목록을 만드는 길을
 * 그 손님 번호로 그대로 밟아** 어느 단계에서 비는지 적는다.
 *
 * 보는 것 (전부 읽기만):
 *  1. 회원 — 있는지 · 역할 · 이메일 · 본인확인 표시 · 같은 전화 · 이름으로 **다른 계정**이 있는지 (다른 계정으로 로그인하면 빈 목록이다)
 *  2. 주문 — 표를 직접 세어(HPOS `wc_orders.customer_id` / `_customer_user`) 이 회원 것이 몇 건인지, 상태별로
 *  3. 마이페이지가 실제로 부르는 질의 — `wc_get_orders( customer, status = 등록된 상태 전부, paginate )` 에
 *     `woocommerce_my_account_my_orders_query` 필터까지 건 결과. 2 와 다르면 **필터나 상태 등록**이 범인이다
 *  4. 그 필터 · 주문 목록 훅에 걸린 콜백 이름 (테마 · 키플 · 우리)
 *  5. 템플릿 — `myaccount/orders.php` 가 테마 것인지 워드커머스 것인지
 *  6. 그 회원으로 잠깐 바꿔 주문 목록 템플릿을 그려 본다 — 줄 수 · 예외. 그리기가 죽으면 그 자리가 원인이다
 *
 * 회원 · 주문에 아무것도 쓰지 않는다. `wp_set_current_user` 는 그리는 동안만, 끝나면 되돌린다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\OrdersDoctor;

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-orders-doctor';

/**
 * 권한.
 *
 * @return bool
 */
function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * 메뉴.
 *
 * @return void
 */
function menu(): void {
	if ( ! may() ) {
		return;
	}
	add_management_page( '주문내역 진단', '주문내역 진단', current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options', SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * HPOS 인가.
 *
 * @return bool
 */
function hpos(): bool {
	return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * 판정 — 순수 함수. 숫자 넷으로 어디가 문제인지 말한다. 테스트가 이것을 본다.
 *
 * @param int  $raw     표에서 직접 센 이 회원의 주문 수.
 * @param int  $listed  마이페이지 질의가 돌려준 수.
 * @param int  $drawn   템플릿을 그려 나온 줄 수 (-1 = 그리다 죽음).
 * @param bool $twins   같은 사람으로 보이는 다른 계정이 있는가.
 * @return array{level:string,text:string}
 */
function verdict( int $raw, int $listed, int $drawn, bool $twins, int $login_to = 0, int $self = 0 ): array {
	if ( $login_to > 0 && $self > 0 && $login_to !== $self ) {
		return array( 'level' => 'bad', 'text' => sprintf( '**같은 아이디 · 이메일의 계정이 둘이다.** 이 아이디로 로그인하면 워드프레스는 먼저 만들어진 #%d 로 들어가는데, 주문은 #%d 에 붙어 있다. 그래서 손님은 빈 주문내역을 본다. 고치는 길: 주문 화면의 「고객」 칸을 #%d 로 바꾸거나(주문을 손님이 실제로 들어가는 계정으로 옮김), 두 계정을 하나로 합친다.', $login_to, $self, $login_to ) );
	}
	if ( $raw <= 0 ) {
		return array( 'level' => 'warn', 'text' => $twins
			? '이 계정에는 주문이 없다. 같은 사람으로 보이는 다른 계정이 있다 — 손님이 주문은 그 계정으로 하고 로그인은 이 계정으로 한 것일 수 있다.'
			: '이 계정에는 주문이 하나도 없다. 주문의 「고객」이 다른 계정에 붙어 있을 수 있다 — 주문 화면의 고객 칸을 본다.' );
	}
	if ( $listed < $raw ) {
		return array( 'level' => 'bad', 'text' => sprintf( '표에는 %d건인데 마이페이지 질의는 %d건만 돌려준다 — `woocommerce_my_account_my_orders_query` 필터나 상태 등록(테마 · 키플)이 걸러 내고 있다. 아래 콜백 목록과 상태별 수를 본다.', $raw, $listed ) );
	}
	if ( -1 === $drawn ) {   // -2 = 관리자라 안 그림(모름) · -1 = 그리다 죽음
		return array( 'level' => 'bad', 'text' => '질의는 맞는데 주문 목록 템플릿을 그리다 죽는다 — 아래 예외를 본다. 손님 화면이 비거나 잘린 이유다.' );
	}
	if ( $drawn >= 0 && $drawn < $listed ) {
		return array( 'level' => 'bad', 'text' => sprintf( '질의는 %d건인데 그린 줄은 %d개 — 템플릿이 일부를 건너뛴다 (5번에서 테마 override 인지 본다).', $listed, $drawn ) );
	}
	return array( 'level' => 'ok', 'text' => $twins
		? sprintf( '이 계정으로는 %d건이 정상으로 그려진다. 다만 같은 사람으로 보이는 다른 계정이 있다 — 손님이 **다른 계정**으로 로그인해 빈 목록을 본 것일 가능성이 크다.', $drawn )
		: sprintf( '이 계정으로는 %d건이 정상으로 그려진다. 서버 쪽은 문제가 없다 — 손님 쪽(캐시 · 다른 계정 · 화면 어디를 봤는지)을 확인한다.', $drawn ) );
}

/**
 * 회원 찾기 — 번호 · 이메일 · 로그인 이름.
 *
 * @param string $q 입력.
 * @return \WP_User|null
 */
function find_user( string $q ) {
	$q = trim( $q );
	if ( '' === $q ) {
		return null;
	}
	if ( ctype_digit( $q ) ) {
		$u = get_user_by( 'id', (int) $q );
		if ( $u ) {
			return $u;
		}
	}
	foreach ( array( 'email', 'login', 'slug' ) as $by ) {
		$u = get_user_by( $by, $q );
		if ( $u ) {
			return $u;
		}
	}
	return null;
}

/**
 * 정확히 못 찾았을 때 — 아이디 · 이메일 · 이름에 그 글자가 든 회원들 (이 가게는 아이디가 이메일이라
 * 「kisa8020」 만 치면 정확 일치가 없다). 읽기 전용.
 *
 * @param string $q 입력.
 * @return array<int,array{id:int,login:string,email:string,name:string,registered:string}>
 */
function find_like( string $q ): array {
	global $wpdb;
	$q = trim( $q );
	if ( mb_strlen( $q ) < 2 || ! isset( $wpdb ) ) {
		return array();
	}
	$like = '%' . $wpdb->esc_like( $q ) . '%';
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_login, user_email, display_name, user_registered FROM {$wpdb->users} WHERE user_login LIKE %s OR user_email LIKE %s OR display_name LIKE %s ORDER BY ID ASC LIMIT 20", $like, $like, $like ) ); // phpcs:ignore
	$out  = array();
	foreach ( $rows as $r ) {
		$out[] = array( 'id' => (int) $r->ID, 'login' => (string) $r->user_login, 'email' => (string) $r->user_email, 'name' => (string) $r->display_name, 'registered' => (string) $r->user_registered );
	}
	return $out;
}

/**
 * 같은 사람으로 보이는 다른 계정 — 전화 · 이름이 같은 회원.
 *
 * @param \WP_User $u 회원.
 * @return array<int,array{id:int,login:string,email:string,why:string}>
 */
function twins( $u ): array {
	global $wpdb;
	$out    = array();
	$phones = array();
	foreach ( array( 'billing_phone', 'wd_verified_phone', 'phone' ) as $k ) {
		$v = preg_replace( '/\D+/', '', (string) get_user_meta( $u->ID, $k, true ) );
		if ( strlen( (string) $v ) >= 9 ) {
			$phones[] = $v;
		}
	}
	$phones = array_values( array_unique( $phones ) );
	if ( $phones && isset( $wpdb ) ) {
		$like = array();
		foreach ( $phones as $p ) {
			$like[] = $wpdb->prepare( "REPLACE(REPLACE(meta_value,'-',''),' ','') LIKE %s", '%' . $wpdb->esc_like( substr( $p, -8 ) ) );
		}
		$rows = (array) $wpdb->get_results( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('billing_phone','wd_verified_phone','phone') AND (" . implode( ' OR ', $like ) . ') LIMIT 20' ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$id = (int) $r->user_id;
			if ( $id === (int) $u->ID ) {
				continue;
			}
			$o = get_userdata( $id );
			if ( $o ) {
				$out[ $id ] = array( 'id' => $id, 'login' => (string) $o->user_login, 'email' => (string) $o->user_email, 'why' => '전화 같음' );
			}
		}
	}
	$name = trim( (string) $u->display_name );
	if ( mb_strlen( $name ) >= 2 ) {
		foreach ( (array) get_users( array( 'search' => '*' . $name . '*', 'search_columns' => array( 'display_name' ), 'number' => 20, 'fields' => array( 'ID', 'user_login', 'user_email' ) ) ) as $o ) {
			$id = (int) $o->ID;
			if ( $id === (int) $u->ID || isset( $out[ $id ] ) ) {
				continue;
			}
			$out[ $id ] = array( 'id' => $id, 'login' => (string) $o->user_login, 'email' => (string) $o->user_email, 'why' => '이름 같음' );
		}
	}
	return array_values( $out );
}

/**
 * 표에서 직접 센 이 회원의 주문 (상태별). 주문 객체를 깨우지 않는다.
 *
 * @param int $uid 회원 번호.
 * @return array<string,int>
 */
function raw_counts( int $uid ): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return array();
	}
	if ( hpos() ) {
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM {$wpdb->prefix}wc_orders WHERE customer_id = %d AND type = 'shop_order' GROUP BY status", $uid ), ARRAY_A ); // phpcs:ignore
	} else {
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.post_status status, COUNT(*) n FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_customer_user' WHERE p.post_type = 'shop_order' AND m.meta_value = %s GROUP BY p.post_status", (string) $uid ), ARRAY_A ); // phpcs:ignore
	}
	$out = array();
	foreach ( $rows as $r ) {
		$out[ (string) $r['status'] ] = (int) $r['n'];
	}
	return $out;
}

/**
 * 마이페이지가 부르는 그 질의 (WC_Shortcode_My_Account::orders 와 같은 인자 + 같은 필터).
 *
 * @param int $uid 회원 번호.
 * @return array{ids:int[],total:int,args:array}
 */
function account_query( int $uid ): array {
	$args = apply_filters(
		'woocommerce_my_account_my_orders_query',
		array(
			'customer' => $uid,
			'page'     => 1,
			'paginate' => true,
			'limit'    => 50,
			'status'   => array_keys( wc_get_order_statuses() ),
			'return'   => 'ids',
		)
	);
	$q = wc_get_orders( $args );
	$ids = is_object( $q ) && isset( $q->orders ) ? (array) $q->orders : (array) $q;
	$tot = is_object( $q ) && isset( $q->total ) ? (int) $q->total : count( $ids );
	return array( 'ids' => array_map( 'intval', $ids ), 'total' => $tot, 'args' => $args );
}

/**
 * 훅에 걸린 콜백 이름들.
 *
 * @param string $hook 훅.
 * @return string[]
 */
function callbacks( string $hook ): array {
	global $wp_filter;
	$out = array();
	if ( ! isset( $wp_filter[ $hook ] ) ) {
		return $out;
	}
	foreach ( (array) $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
		foreach ( (array) $cbs as $cb ) {
			$f = $cb['function'] ?? null;
			$n = '?';
			if ( is_string( $f ) ) {
				$n = $f;
			} elseif ( is_array( $f ) ) {
				$n = ( is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0] ) . '::' . (string) $f[1];
			} elseif ( $f instanceof \Closure ) {
				try {
					$r = new \ReflectionFunction( $f );
					$n = 'closure @ ' . basename( (string) $r->getFileName() ) . ':' . $r->getStartLine();
				} catch ( \Throwable $e ) {
					$n = 'closure';
				}
			}
			$out[] = $prio . ' · ' . $n;
		}
	}
	return $out;
}

/**
 * 그 회원으로 잠깐 바꿔 주문 목록 템플릿을 그려 본다.
 *
 * @param int $uid 회원 번호.
 * @return array{rows:int,len:int,error:string,empty_note:bool}
 */
function render_as( int $uid ): array {
	$me   = get_current_user_id();
	$out  = array( 'rows' => -1, 'len' => 0, 'error' => '', 'empty_note' => false, 'html' => '', 'skipped' => false );
	$html = '';
	if ( ! has_action( 'woocommerce_account_orders_endpoint' ) ) {
		// 관리자 화면에는 워드커머스 템플릿 훅(wc-template-hooks.php)이 안 실린다 — 여기서 그리면 늘 빈 결과가 나온다.
		// 처음엔 그 0 을 「템플릿이 건너뛴다」로 읽었다 (2026-09-28). 그리지 않고 그렇다고 적는다.
		$out['skipped'] = true;
		$out['rows']    = -2;
		return $out;
	}
	try {
		wp_set_current_user( $uid );
		ob_start();
		do_action( 'woocommerce_account_orders_endpoint', 1 );
		$html = (string) ob_get_clean();
		$out['rows']       = preg_match_all( '/<tr[^>]*woocommerce-orders-table__row/', $html );
		$out['len']        = strlen( $html );
		$out['empty_note'] = false !== strpos( $html, 'woocommerce-info' ) || false !== strpos( $html, '주문이 없' ) || false !== stripos( $html, 'No order' );
		$out['html']       = $html;
	} catch ( \Throwable $e ) {
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		$out['error'] = get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( (string) $e->getFile() ) . ':' . $e->getLine();
	} finally {
		wp_set_current_user( $me );
	}
	return $out;
}

/**
 * 그 주문들의 속살 — 템플릿이 무엇을 보고 건너뛰는지 알려면 필요하다.
 *
 * @param int[] $ids 주문 번호들.
 * @return array<int,array<string,string>>
 */
function order_facts( array $ids ): array {
	$out = array();
	foreach ( array_slice( $ids, 0, 10 ) as $id ) {
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : null;
		if ( ! $o ) {
			$out[ (int) $id ] = array( 'wc_get_order' => '못 읽음 (null)' );
			continue;
		}
		$f = array(
			'class'          => get_class( $o ),
			'status'         => (string) $o->get_status(),
			'type'           => (string) $o->get_type(),
			'parent_id'      => (string) $o->get_parent_id(),
			'customer_id'    => (string) $o->get_customer_id(),
			'created_via'    => (string) $o->get_created_via(),
			'payment_method' => (string) $o->get_payment_method(),
			'date_created'   => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
			'items'          => (string) count( $o->get_items() ),
			'fees'           => implode( ' | ', array_map( fn( $x ) => $x->get_name() . '=' . $x->get_total(), (array) $o->get_items( 'fee' ) ) ),
			'total'          => (string) $o->get_total(),
			'billing_email'  => (string) $o->get_billing_email(),
			'is_editable'    => $o->is_editable() ? 'yes' : 'no',
			'meta_keys'      => implode( ', ', array_slice( array_map( fn( $m ) => (string) $m->key, (array) $o->get_meta_data() ), 0, 40 ) ),
		);
		$out[ (int) $id ] = $f;
	}
	return $out;
}

/**
 * 테마가 덮어쓴 템플릿의 원문 (읽기만). 테마 폴더 안의 파일만 연다.
 *
 * @param string $path 절대 경로.
 * @return string
 */
function theme_source( string $path ): string {
	if ( '' === $path || false === strpos( $path, '/themes/' ) || ! is_readable( $path ) ) {
		return '';
	}
	return (string) file_get_contents( $path ); // phpcs:ignore
}

/**
 * 이 아이디 · 이메일로 로그인하면 워드프레스가 어느 계정을 잡는가. (`wp_authenticate_username_password` 는 `get_user_by('login')`,
 * 이메일 로그인은 `get_user_by('email')` — 둘 다 LIMIT 1 이라 같은 값이 둘이면 **먼저 만들어진 쪽**이다.)
 *
 * @param \WP_User $u 회원.
 * @return array{by_login:int,by_email:int}
 */
function login_resolution( $u ): array {
	$a = get_user_by( 'login', (string) $u->user_login );
	$b = get_user_by( 'email', (string) $u->user_email );
	return array( 'by_login' => $a ? (int) $a->ID : 0, 'by_email' => $b ? (int) $b->ID : 0 );
}

/**
 * 가게 전체에서 아이디 · 이메일이 겹치는 계정 — 같은 일이 몇 명에게 있는지.
 *
 * @return array<int,array{val:string,n:int,ids:string,kind:string}>
 */
function duplicates(): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return array();
	}
	$out = array();
	foreach ( array( 'user_login' => '아이디', 'user_email' => '이메일' ) as $col => $kind ) {
		$rows = (array) $wpdb->get_results( "SELECT {$col} val, COUNT(*) n, GROUP_CONCAT(ID ORDER BY ID) ids FROM {$wpdb->users} WHERE {$col} <> '' GROUP BY {$col} HAVING n > 1 ORDER BY n DESC LIMIT 100" ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$out[] = array( 'val' => (string) $r->val, 'n' => (int) $r->n, 'ids' => (string) $r->ids, 'kind' => $kind );
		}
	}
	return $out;
}

/**
 * 어느 계정에 주문이 몇 건인지 (표 직접).
 *
 * @param int $uid 회원 번호.
 * @return int
 */
function order_count( int $uid ): int {
	return array_sum( raw_counts( $uid ) );
}

/**
 * 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$q = isset( $_GET['who'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['who'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$u = '' !== $q ? find_user( $q ) : null;
	$done = '';
	if ( isset( $_POST['dhr_sync_from'] ) && check_admin_referer( 'dhr_sync_pw' ) && function_exists( '\\Duckhoo\\Redesign\\TwinLogin\\sync_hash' ) ) {
		$n    = \Duckhoo\Redesign\TwinLogin\sync_hash( (int) $_POST['dhr_sync_from'] );
		$done = sprintf( '#%d 의 비밀번호를 쌍둥이 계정 %d개에 적용했습니다. 손님은 지금 쓰는 비밀번호 그대로 다시 로그인하면 됩니다.', (int) $_POST['dhr_sync_from'], $n );
	}
	?>
	<div class="wrap" style="max-width:1000px">
		<h1>주문내역 진단</h1>
		<?php if ( '' !== $done ) : ?><div class="notice notice-success"><p><?php echo esc_html( $done ); ?></p></div><?php endif; ?>
		<p>손님이 「주문내역이 안 보인다」고 할 때, 그 손님 번호(주문 화면 고객 칸의 <code>#숫자</code>) · 이메일 · 아이디로 마이페이지 주문 목록을 서버에서 그대로 만들어 봅니다. <b>읽기만 합니다.</b></p>
		<form method="get"><input type="hidden" name="page" value="<?php echo esc_attr( SLUG ); ?>">
			<input type="text" name="who" value="<?php echo esc_attr( $q ); ?>" class="regular-text" placeholder="279209536 또는 이메일"> <button class="button button-primary">진단</button></form>
		<?php
		if ( '' !== $q && ! $u ) {
			$like = find_like( $q );
			if ( 1 === count( $like ) || ( $like && 1 === count( array_unique( array_column( $like, 'login' ) ) ) ) ) {
				$u = get_user_by( 'id', $like[0]['id'] );   // 한 사람(쌍둥이 포함)만 걸리면 바로 그 사람
			}
		}
		if ( '' !== $q && ! $u ) {
			echo '<div class="notice notice-error"><p>「' . esc_html( $q ) . '」 와 정확히 같은 번호 · 이메일 · 아이디가 없습니다. 이 가게는 아이디가 이메일입니다 — <code>kisa8020@naver.com</code> 처럼 전체를 넣어 주세요.</p></div>';
			if ( ! empty( $like ) ) {
				echo '<h2>비슷한 회원</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>번호</th><th>아이디</th><th>이메일</th><th>이름</th><th>가입</th></tr></thead><tbody>';
				foreach ( $like as $r ) {
					echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page' => SLUG, 'who' => $r['id'] ), admin_url( 'tools.php' ) ) ) . '"><b>#' . (int) $r['id'] . '</b></a></td><td>' . esc_html( $r['login'] ) . '</td><td>' . esc_html( $r['email'] ) . '</td><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['registered'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</div>';
			return;
		}
		if ( ! $u ) {
			echo '</div>';
			return;
		}
		$uid    = (int) $u->ID;
		$raw    = raw_counts( $uid );
		$rawn   = array_sum( $raw );
		$aq     = account_query( $uid );
		$tw     = twins( $u );
		$dr     = render_as( $uid );
		$lr     = login_resolution( $u );
		$v      = verdict( $rawn, $aq['total'], '' !== $dr['error'] ? -1 : $dr['rows'], ! empty( $tw ), (int) ( $lr['by_login'] ?: $lr['by_email'] ), $uid );
		$names  = wc_get_order_statuses();
		$color  = array( 'ok' => '#1B5E2A', 'warn' => '#8A4B0C', 'bad' => '#B42318' )[ $v['level'] ];
		$tpl    = function_exists( 'wc_locate_template' ) ? (string) wc_locate_template( 'myaccount/orders.php' ) : '';
		$tpl2   = function_exists( 'wc_locate_template' ) ? (string) wc_locate_template( 'myaccount/my-account.php' ) : '';
		$in_theme = fn( string $p ) => false !== strpos( $p, '/themes/' ) ? '<b style="color:#B42318">테마 override</b>' : '워드커머스 기본';
		?>
		<div style="border-left:5px solid <?php echo esc_attr( $color ); ?>;background:#fff;padding:12px 16px;margin:16px 0"><b>판정</b> — <?php echo wp_kses_post( $v['text'] ); ?></div>
		<h2>1. 회원</h2>
		<table class="widefat striped" style="max-width:900px"><tbody>
			<tr><th style="width:200px">번호 · 아이디 · 이메일</th><td>#<?php echo (int) $uid; ?> · <?php echo esc_html( $u->user_login ); ?> · <?php echo esc_html( $u->user_email ); ?></td></tr>
			<tr><th>이름 · 역할</th><td><?php echo esc_html( $u->display_name ); ?> · <?php echo esc_html( implode( ', ', (array) $u->roles ) ); ?></td></tr>
			<tr><th>가입 · 본인확인</th><td><?php echo esc_html( (string) $u->user_registered ); ?> · wd_phone_verified=<code><?php echo esc_html( (string) get_user_meta( $uid, 'wd_phone_verified', true ) ); ?></code> · 옛 사이트 확인=<code><?php echo esc_html( (string) get_user_meta( $uid, '_dhr_legacy_verified', true ) ); ?></code></td></tr>
			<tr><th>전화</th><td><?php echo esc_html( (string) get_user_meta( $uid, 'billing_phone', true ) ); ?> / 인증 <?php echo esc_html( (string) get_user_meta( $uid, 'wd_verified_phone', true ) ); ?></td></tr>
			<tr><th>같은 사람으로 보이는 다른 계정</th><td><?php echo $tw ? wp_kses_post( implode( '<br>', array_map( fn( $t ) => sprintf( '#%d · %s · %s (%s) · 가입 %s · 주문 <b>%d건</b> · 본인확인 %s', $t['id'], esc_html( $t['login'] ), esc_html( $t['email'] ), esc_html( $t['why'] ), esc_html( (string) ( get_userdata( $t['id'] )->user_registered ?? '' ) ), order_count( $t['id'] ), esc_html( (string) get_user_meta( $t['id'], 'wd_phone_verified', true ) ) ), $tw ) ) ) : '없음'; ?></td></tr>
			<tr><th>이 아이디 · 이메일로 로그인하면</th><td>아이디로 → <b>#<?php echo (int) $lr['by_login']; ?></b> · 이메일로 → <b>#<?php echo (int) $lr['by_email']; ?></b> <?php echo ( $lr['by_login'] && $lr['by_login'] !== $uid ) || ( $lr['by_email'] && $lr['by_email'] !== $uid ) ? '<b style="color:#B42318">← 이 계정(#' . (int) $uid . ')이 아니다</b>' : '(이 계정)'; ?></td></tr>
		</tbody></table>
		<?php
		$same = function_exists( '\\Duckhoo\\Redesign\\TwinLogin\\by_name' ) ? array_values( array_unique( array_merge( \Duckhoo\Redesign\TwinLogin\by_name( (string) $u->user_login ), \Duckhoo\Redesign\TwinLogin\by_name( (string) $u->user_email ) ) ) ) : array();
		if ( count( $same ) > 1 ) :
			?>
			<h2>1-b. 같은 아이디 · 이메일의 계정 나란히 (로그인 · 비밀번호가 어디에 닿는지)</h2>
			<p>비밀번호 찾기 · 정보 수정은 <b>한 계정에만</b> 닿는다. 「재설정 링크 요청」 시각이 한쪽에만 있으면 그 계정의 비밀번호만 바뀐 것이다.
			플러그인은 손님이 친 비밀번호를 두 계정 해시로 다 확인해 맞는 쪽으로 넣어 준다 — 어느 쪽에도 안 맞으면 정말 틀린 비밀번호다.
			이제부터는 어느 계정의 비밀번호가 바뀌든 쌍둥이에 같은 해시가 복사된다. 이미 갈라진 쌍은 오른쪽 버튼으로 한 번 맞춘다 —
			<b>손님이 지금 쓰는 비밀번호가 걸려 있는 계정</b>(보통 주문내역이 비어 보이는 그 계정)의 버튼을 누른다.</p>
			<table class="widefat striped" style="max-width:1100px"><thead><tr><th>번호</th><th>가입</th><th>역할</th><th>본인확인</th><th>주문</th><th>적립금</th><th>비밀번호 해시</th><th>재설정 링크 요청</th><th>키플 · 테마 메타</th><th></th></tr></thead><tbody>
			<?php foreach ( $same as $sid ) : $su = get_userdata( (int) $sid ); if ( ! $su ) { continue; } ?>
				<?php
				$hash  = (string) $su->user_pass;
				$htype = str_starts_with( $hash, '$wp' ) ? 'bcrypt(wp)' : ( str_starts_with( $hash, '$P$' ) ? 'phpass(옛)' : ( str_starts_with( $hash, '$2' ) ? 'bcrypt' : ( strlen( $hash ) <= 32 ? 'md5(옛)' : '기타' ) ) );
				$akey  = (string) $su->user_activation_key;
				$when  = '';
				if ( '' !== $akey && false !== strpos( $akey, ':' ) ) {
					$when = wp_date( 'Y-m-d H:i', (int) explode( ':', $akey, 2 )[0] );
				}
				$metas = array();
				foreach ( (array) get_user_meta( (int) $sid ) as $mk => $mv ) {
					if ( preg_match( '/^(wd_|keyple|_keyple|_wd_|_dhr_|dhr_)/', (string) $mk ) && ! preg_match( '/(ci|di|pass|token|key|secret)$/i', (string) $mk ) ) {
						$val     = is_array( $mv ) ? (string) reset( $mv ) : (string) $mv;
						$metas[] = esc_html( $mk ) . '=' . esc_html( mb_strimwidth( $val, 0, 24, '…' ) );
					}
				}
				?>
				<tr<?php echo (int) $sid === (int) $uid ? ' style="background:#FFF7ED"' : ''; ?>>
					<td><b>#<?php echo (int) $sid; ?></b><?php echo (int) $sid === (int) $uid ? ' (이 계정)' : ''; ?></td>
					<td><?php echo esc_html( (string) $su->user_registered ); ?></td>
					<td><?php echo esc_html( implode( ', ', (array) $su->roles ) ); ?></td>
					<td><?php echo '' !== trim( (string) get_user_meta( (int) $sid, 'wd_phone_verified', true ) ) ? '있음' : ( '' !== trim( (string) get_user_meta( (int) $sid, '_dhr_legacy_verified', true ) ) ? '옛 사이트' : '<b style="color:#B42318">없음</b>' ); ?></td>
					<td><?php echo (int) order_count( (int) $sid ); ?>건</td>
					<td><?php echo esc_html( number_format_i18n( (float) get_user_meta( (int) $sid, '_keyple_points', true ) ) ); ?></td>
					<td><?php echo esc_html( $htype ); ?></td>
					<td><?php echo '' !== $when ? '<b>' . esc_html( $when ) . '</b>' : ( '' !== $akey ? '있음' : '—' ); ?></td>
					<td style="font-size:12px;max-width:380px"><?php echo $metas ? wp_kses_post( implode( '<br>', array_slice( $metas, 0, 14 ) ) ) : '—'; ?></td>
					<td><form method="post" onsubmit="return confirm('#<?php echo (int) $sid; ?> 의 비밀번호를 같은 아이디의 다른 계정에도 그대로 적용합니다. 손님이 지금 쓰는 비밀번호가 이 계정 것일 때 누르세요.');"><?php wp_nonce_field( 'dhr_sync_pw' ); ?><input type="hidden" name="dhr_sync_from" value="<?php echo (int) $sid; ?>"><button class="button">이 계정 비밀번호를 쌍둥이에도</button></form></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<h3>로그인에 끼어드는 것 (<code>authenticate</code> 필터, 우선순위 순)</h3>
			<p style="font-size:13px">우리 <code>TwinLogin\pick</code> 은 30 이다. <b>그보다 뒤(큰 숫자)에서 아이디로 회원을 다시 찾아 돌려주는 콜백</b>이 있으면 우리가 고른 계정이 덮인다.</p>
			<ul style="font-family:monospace;font-size:12px"><?php foreach ( callbacks( 'authenticate' ) as $cbn ) : ?><li><?php echo esc_html( $cbn ); ?></li><?php endforeach; ?></ul>
			<h3>쌍둥이 계정의 최근 로그인 (플러그인이 적은 것)</h3>
			<?php $tlog = array_reverse( (array) get_option( 'duckhoo_twin_log', array() ) ); ?>
			<?php if ( $tlog ) : ?>
				<table class="widefat striped" style="max-width:700px"><thead><tr><th>시각</th><th>아이디</th><th>들어간 계정</th><th>어떻게</th></tr></thead><tbody>
				<?php foreach ( array_slice( $tlog, 0, 15 ) as $e ) : ?><tr><td><?php echo esc_html( (string) ( $e['t'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $e['login'] ?? '' ) ); ?></td><td>#<?php echo (int) ( $e['id'] ?? 0 ); ?></td><td><?php echo esc_html( (string) ( $e['how'] ?? '' ) ); ?></td></tr><?php endforeach; ?>
				</tbody></table>
			<?php else : ?><p>아직 없음 — 이 기록은 배포 뒤의 로그인부터 쌓인다.</p><?php endif; ?>
		<?php endif; ?>
		<h2>2. 표에서 직접 센 주문 (<?php echo hpos() ? 'HPOS wc_orders.customer_id' : 'posts + _customer_user'; ?>)</h2>
		<p><b><?php echo (int) $rawn; ?>건</b>
		<?php foreach ( $raw as $st => $n ) : ?> · <?php echo esc_html( ( $names[ $st ] ?? $names[ 'wc-' . $st ] ?? $st ) . ' ' . $n ); ?><?php endforeach; ?></p>
		<h2>3. 마이페이지가 부르는 질의</h2>
		<p><b><?php echo (int) $aq['total']; ?>건</b> (첫 쪽 <?php echo count( $aq['ids'] ); ?>건: <?php echo esc_html( implode( ', ', array_slice( $aq['ids'], 0, 20 ) ) ); ?>)</p>
		<details><summary>질의 인자 (필터를 거친 뒤)</summary><pre style="background:#fff;padding:8px;font-size:12px"><?php echo esc_html( (string) wp_json_encode( $aq['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre></details>
		<p>등록된 주문 상태 <?php echo count( $names ); ?>개: <?php echo esc_html( implode( ' · ', array_keys( $names ) ) ); ?></p>
		<details open><summary>그 주문들의 속살 (템플릿이 보는 값)</summary>
		<?php foreach ( order_facts( $aq['ids'] ) as $oid => $f ) : ?>
			<p><b>#<?php echo (int) $oid; ?></b> — <?php echo esc_html( implode( ' · ', array_map( fn( $k, $v ) => $k . '=' . $v, array_keys( $f ), $f ) ) ); ?></p>
		<?php endforeach; ?>
		</details>
		<h2>4. 그 자리에 걸린 콜백</h2>
		<?php foreach ( array( 'woocommerce_my_account_my_orders_query', 'woocommerce_account_orders_endpoint', 'woocommerce_before_account_orders', 'woocommerce_my_account_my_orders_actions', 'woocommerce_account_menu_items' ) as $h ) : $c = callbacks( $h ); ?>
			<p><code><?php echo esc_html( $h ); ?></code> — <?php echo $c ? esc_html( implode( ' | ', $c ) ) : '없음'; ?></p>
		<?php endforeach; ?>
		<h2>5. 템플릿</h2>
		<p><code>myaccount/orders.php</code> → <?php echo wp_kses_post( $in_theme( $tpl ) ); ?> <small><?php echo esc_html( str_replace( ABSPATH, '', $tpl ) ); ?></small><br>
		<code>myaccount/my-account.php</code> → <?php echo wp_kses_post( $in_theme( $tpl2 ) ); ?> <small><?php echo esc_html( str_replace( ABSPATH, '', $tpl2 ) ); ?></small></p>
		<h2>6. 그 회원으로 주문 목록을 그려 봄</h2>
		<?php if ( ! empty( $dr['skipped'] ) ) : ?>
			<p>관리자 화면에는 워드커머스 주문 목록 훅이 실리지 않아 여기서는 그릴 수 없습니다 (그려도 늘 0 이 나옵니다). 3번 질의 결과가 곧 손님 화면의 줄 수입니다.</p>
		<?php elseif ( '' !== $dr['error'] ) : ?>
			<p style="color:#B42318"><b>그리다 죽음:</b> <?php echo esc_html( $dr['error'] ); ?></p>
		<?php else : ?>
			<p>줄 <b><?php echo (int) $dr['rows']; ?>개</b> · HTML <?php echo number_format( (int) $dr['len'] ); ?>자 · 「주문 없음」 안내 <?php echo $dr['empty_note'] ? '있음' : '없음'; ?></p>
			<details><summary>실제로 그려진 HTML (앞 4,000자)</summary><pre style="background:#fff;padding:8px;font-size:11px;white-space:pre-wrap;word-break:break-all"><?php echo esc_html( mb_substr( $dr['html'], 0, 4000 ) ); ?></pre></details>
		<?php endif; ?>
		<?php $src = theme_source( $tpl ); if ( '' !== $src ) : ?>
		<h2>7. 테마가 덮어쓴 <code>myaccount/orders.php</code> 원문 (읽기만)</h2>
		<p class="description">이 안에서 <code>continue</code> · <code>if</code> 로 주문을 건너뛰는 줄이 원인이다. 통째로 복사해 클로드에게 붙여 주세요.</p>
		<pre style="background:#fff;padding:8px;font-size:11px;max-height:600px;overflow:auto;white-space:pre-wrap;word-break:break-all"><?php echo esc_html( $src ); ?></pre>
		<?php endif; ?>
		<h2>8. 가게 전체에서 아이디 · 이메일이 겹치는 계정</h2>
		<?php $dups = duplicates(); ?>
		<?php if ( ! $dups ) : ?><p>없음</p><?php else : ?>
		<p>같은 아이디 · 이메일로 계정이 둘 이상인 경우 <b><?php echo count( $dups ); ?>쌍</b>. 이런 손님은 로그인할 때마다 먼저 만들어진 계정으로 들어가므로, 나중 계정에 붙은 주문은 안 보입니다.</p>
		<p class="description">계정마다: 가입 시각 · 본인확인(<code>V</code>) · 적립금 · 주문 수. <b>지워도 되는 쪽</b> = 주문 0 · 적립금 0 · 본인확인 없음. 지울 때 콘텐츠는 남는 계정에 귀속. 아이디 표와 이메일 표는 같은 쌍이 두 번 나온 것이다.</p>
		<table class="widefat striped" style="max-width:1000px"><thead><tr><th>무엇</th><th>값</th><th>계정들 (먼저 만든 순)</th><th>제안</th></tr></thead><tbody>
		<?php foreach ( $dups as $d ) : $ids = array_map( 'intval', explode( ',', $d['ids'] ) ); $cells = array(); $del = array(); $keep = 0; $first = null;
			foreach ( $ids as $i ) { $ud = get_userdata( $i ); $reg = $ud ? (string) $ud->user_registered : ''; $ver = '' !== trim( (string) get_user_meta( $i, 'wd_phone_verified', true ) ); $pts = (int) get_user_meta( $i, '_keyple_points', true ); $oc = order_count( $i );
				$first = $first ?? strtotime( $reg ); $gap = $first ? ( strtotime( $reg ) - $first ) : 0;
				$cells[] = sprintf( '#%d · %s%s · %s · 적립 %s · 주문 <b>%d</b>', $i, esc_html( $reg ), $gap > 0 ? ' (+' . $gap . '초)' : '', $ver ? '<b>V</b>' : '-', number_format( $pts ), $oc );
				if ( 0 === $oc && 0 === $pts && ! $ver ) { $del[] = $i; } else { $keep = $keep ?: $i; } }
			$keepers = array_values( array_diff( $ids, $del ) );
			if ( ! $keepers ) { $keepers = array( max( $ids ) ); $del = array_values( array_diff( $ids, $keepers ) ); }
			$sug = count( $keepers ) === 1 ? '#' . implode( '', array_map( 'strval', $keepers ) ) . ' 남기고 ' . ( $del ? '#' . implode( ' · #', $del ) . ' 지움' : '지울 것 없음' ) : '<b style="color:#B42318">둘 다 무엇인가 있음 — 주문 · 적립금을 한쪽으로 옮긴 뒤 정리</b>'; ?>
			<tr><td><?php echo esc_html( $d['kind'] ); ?></td><td><?php echo esc_html( $d['val'] ); ?></td><td><?php echo wp_kses_post( implode( '<br>', $cells ) ); ?></td><td><?php echo wp_kses_post( $sug ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<p class="description">로그인은 이제 플러그인이 <b>비밀번호가 맞는 계정 중 본인확인 · 주문이 있는 쪽</b>으로 들여보냅니다 (<code>includes/twin-login.php</code>). 그래서 지우는 정리는 급하지 않습니다 — 다만 새로 생기는 것은 가입 흐름을 고쳐야 멈춥니다.</p>
		<?php endif; ?>
		<p class="description">이 화면 결과를 그대로 복사해 클로드에게 붙여 주시면 다음 손을 정합니다. 손님께는 「어느 계정(이메일)으로 로그인했는지」와 「마이페이지 → 주문내역 화면에 무엇이 보이는지(빈 목록인지 · 오류인지)」를 물어봐 주세요.</p>
	</div>
	<?php
}
