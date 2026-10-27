<?php
/**
 * CRM 기간 성과 — 한 기간(예: 10/13 ~ 10/30) 동안 보낸 CRM 전부를 숫자로 한 번에.
 *
 * 사장님이 문자 · 적립금을 여러 번 나눠 보내므로, 묶음마다 따로 보던 것을 한 장으로 모은다.
 * 1. 적립금 묶음 (마케팅 → 적립금 한 번에 넣기) — 받은 사람 · 산 사람 · 적립금 쓴 사람 · 실입금 (문자종류별)
 * 2. 문자 양식으로 내려받은 묶음 (고객 세그먼트의 보낸 기록) — 받은 사람 중 14일 안에 산 사람
 * 3. 그 기간 가게 전체 — 처음 산 손님 / 다시 산 손님 주문 · 실입금, 바로 앞 같은 길이와 나란히
 *
 * **읽기만 한다. 집계만 낸다** — 이름 · 연락처 · 주문 번호는 어디에도 나가지 않는다.
 * 고객 세그먼트 화면의 상자 + 브리핑 열쇠로 닫힌 `GET /wp-json/duckhoo/v1/crm-report?from=YYYY-MM-DD&to=YYYY-MM-DD`.
 *
 * @package DuckhooRedesign
 */

namespace Duckhoo\Redesign\CrmReport;

defined( 'ABSPATH' ) || exit;

/** 관리자 밖(REST)에서도 셈에 쓰는 모듈을 든다 — 월말 결산과 같은 방법. */
function load_deps(): void {
	$dir = dirname( __DIR__ ) . '/includes/';
	foreach ( array( 'sales.php', 'anatomy.php', 'crm.php', 'crm-sms.php', 'crm-plan.php', 'crm-impact.php', 'points-admin.php', 'crm-sends.php' ) as $f ) {
		if ( is_readable( $dir . $f ) ) {
			require_once $dir . $f;
		}
	}
}

/** 사이트 시간대 오프셋(초). */
function off(): int {
	return (int) ( function_exists( 'wp_timezone' ) ? ( new \DateTime( 'now', wp_timezone() ) )->getOffset() : 9 * 3600 );
}

/**
 * 기간 안의 돈 들어온 주문을 처음 산 손님 / 다시 산 손님 / 비회원으로 나눈다. 순수.
 *
 * 「처음」은 그 회원의 돈 들어온 주문 중 가장 이른 것 — 기간 전에 산 적이 있으면 다시 산 손님이다.
 *
 * @param array<int,array{id:int,ts:int,s:string,t:float,u:int}> $orders 실제 시각(ts).
 * @return array<string,mixed>
 */
function split_sales( array $orders, int $from, int $to, array $paid, int $off = 0 ): array {
	usort( $orders, fn( $a, $b ) => ( (int) $a['ts'] <=> (int) $b['ts'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
	$seen = array();
	$z    = fn() => array( 'n' => 0, 'sales' => 0.0 );
	$out  = array( 'all' => $z(), 'first' => $z(), 'repeat' => $z(), 'guest' => $z(), 'buyers' => 0, 'daily' => array() );
	$buy  = array();
	foreach ( $orders as $o ) {
		if ( ! in_array( (string) $o['s'], $paid, true ) ) {
			continue;
		}
		$u  = (int) $o['u'];
		$ts = (int) $o['ts'];
		$k  = $u <= 0 ? 'guest' : ( isset( $seen[ $u ] ) ? 'repeat' : 'first' );
		if ( $u > 0 ) {
			$seen[ $u ] = true;
		}
		if ( $ts < $from || $ts > $to ) {
			continue;
		}
		$t = (float) $o['t'];
		foreach ( array( 'all', $k ) as $g ) {
			++$out[ $g ]['n'];
			$out[ $g ]['sales'] += $t;
		}
		$d = gmdate( 'Y-m-d', $ts + $off );
		$out['daily'][ $d ] = $out['daily'][ $d ] ?? array( 'n' => 0, 'sales' => 0.0, 'first' => 0, 'repeat' => 0 );
		++$out['daily'][ $d ]['n'];
		$out['daily'][ $d ]['sales'] += $t;
		if ( 'guest' !== $k ) {
			++$out['daily'][ $d ][ $k ];
			$buy[ $u ] = true;
		}
	}
	ksort( $out['daily'] );
	$out['buyers'] = count( $buy );
	$out['aov']    = $out['all']['n'] ? $out['all']['sales'] / $out['all']['n'] : 0.0;
	return $out;
}

/** 'Y-m-d' 가 맞는 날짜인가. */
function ymd( string $s ): bool {
	return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) && false !== strtotime( $s );
}

/**
 * 기간 성과 전부. 10분 캐시.
 *
 * @return array<string,mixed>
 */
function period( string $from, string $to, bool $fresh = false ): array {
	load_deps();
	$key = 'dhr_crm_rep_' . md5( $from . '|' . $to . '|' . gmdate( 'YmdH' ) . substr( gmdate( 'i' ), 0, 1 ) );
	$hit = $fresh ? false : get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$off    = off();
	$f      = (int) strtotime( $from . ' 00:00:00 UTC' ) - $off;
	$t      = (int) strtotime( $to . ' 23:59:59 UTC' ) - $off;
	$now    = time();
	$until  = min( $now, $t );
	$paid   = function_exists( '\\Duckhoo\\Redesign\\Crm\\paid_statuses' ) ? \Duckhoo\Redesign\Crm\paid_statuses() : array( 'payment-confirmed', 'ready-to-ship', 'shipping', 'delivered', 'completed', 'processing' );

	$orders = array();
	foreach ( ( function_exists( '\\Duckhoo\\Redesign\\Anatomy\\orders' ) ? \Duckhoo\Redesign\Anatomy\orders() : array() ) as $o ) {
		$o['ts']  = (int) $o['ts'] - $off; // Anatomy\orders() 의 ts 는 사이트 시간으로 밀려 있다
		$orders[] = $o;
	}
	$len  = max( DAY_IN_SECONDS, $until - $f );
	$cur  = split_sales( $orders, $f, $until, $paid, $off );
	$base = split_sales( $orders, $f - $len, $f - 1, $paid, $off );

	// 1. 적립금 묶음 — 묶음 시각이나 파일 하나라도 기간 안이면 싣는다
	$points = array();
	$popt   = (array) get_option( 'duckhoo_points_batches', array() ); // = Points\Admin\OPT
	foreach ( $popt as $bk => $b ) {
		if ( ! is_array( $b ) ) {
			continue;
		}
		$times = array( (int) ( $b['created'] ?? 0 ) );
		$runs  = array();
		foreach ( (array) ( $b['runs'] ?? array() ) as $r ) {
			$times[] = (int) ( $r['at'] ?? 0 );
			$runs[]  = array( 'at' => wp_date( 'Y-m-d H:i', (int) ( $r['at'] ?? 0 ) ), 'n' => (int) ( $r['n'] ?? 0 ), 'sum' => (int) ( $r['sum'] ?? 0 ) );
		}
		if ( ! array_filter( $times, fn( $x ) => $x >= $f && $x <= $t ) ) {
			continue;
		}
		usort( $runs, fn( $a, $b ) => strcmp( $a['at'], $b['at'] ) );
		$p = function_exists( '\\Duckhoo\\Redesign\\Points\\Admin\\participation_for' ) ? \Duckhoo\Redesign\Points\Admin\participation_for( (string) $bk, $until ) : null;
		$points[] = array(
			'label'   => (string) ( $b['label'] ?? $bk ),
			'created' => wp_date( 'Y-m-d H:i', (int) ( $b['created'] ?? 0 ) ),
			'n'       => (int) ( $b['n'] ?? 0 ),
			'sum'     => (int) ( $b['sum'] ?? 0 ),
			'revoked' => ! empty( $b['revoked'] ),
			'runs'    => $runs,
			'p'       => $p ? (array) $p['p'] : array(),
		);
	}
	usort( $points, fn( $a, $b ) => strcmp( $a['created'], $b['created'] ) );

	// 2. 문자 양식으로 내려받은 묶음 (번호는 셈에만 쓰고 내보내지 않는다)
	$sent = array();
	if ( function_exists( '\\Duckhoo\\Redesign\\Crm\\sent_batches' ) && function_exists( '\\Duckhoo\\Redesign\\Crm\\impact_for' ) ) {
		foreach ( \Duckhoo\Redesign\Crm\sent_batches() as $b ) {
			$at = (int) ( $b['at'] ?? 0 );
			if ( $at < $f || $at > $t ) {
				continue;
			}
			$phones = array_values( array_unique( array_filter( array_map( fn( $r ) => (string) ( $r['phone'] ?? '' ), (array) ( $b['rows'] ?? array() ) ) ) ) );
			$groups = array();
			foreach ( (array) ( $b['rows'] ?? array() ) as $r ) {
				$g = (string) ( $r['group'] ?? '' );
				$groups[ $g ] = ( $groups[ $g ] ?? 0 ) + 1;
			}
			$im = \Duckhoo\Redesign\Crm\impact_for( $phones, $at );
			$sent[] = array( 'at' => wp_date( 'Y-m-d H:i', $at ), 'seg' => (string) ( $b['seg'] ?? '' ), 'group' => (string) ( $b['group'] ?? '' ), 'groups' => $groups, 'impact' => $im );
		}
		usort( $sent, fn( $a, $b ) => strcmp( $a['at'], $b['at'] ) );
	}

	// 3. 「문자 참여」에 올려 둔 발송 파일 (신상품 문자 등 — 사이트에서 내려받지 않은 것)
	$uploads = array();
	if ( function_exists( '\\Duckhoo\\Redesign\\CrmSends\\saved' ) && function_exists( '\\Duckhoo\\Redesign\\Crm\\impact_for' ) ) {
		$ups = array_values( array_filter( \Duckhoo\Redesign\CrmSends\saved(), fn( $x ) => (int) $x['at'] >= $f && (int) $x['at'] <= $t ) );
		if ( $ups ) {
			$days  = \Duckhoo\Redesign\Crm\impact_days();
			$first = min( array_map( fn( $x ) => (int) $x['at'], $ups ) );
			$d     = \Duckhoo\Redesign\Crm\impact_orders( $first - $days * DAY_IN_SECONDS );
			$items = array();
			if ( array_filter( array_column( $ups, 'kw' ) ) && function_exists( '\\Duckhoo\\Redesign\\Anatomy\\items' ) ) {
				$ids   = array_map( fn( $o ) => (int) $o['id'], array_filter( $d['orders'], fn( $o ) => (int) $o['ts'] >= $first && in_array( (string) $o['s'], $paid, true ) ) );
				$items = \Duckhoo\Redesign\Anatomy\items( array_values( $ids ) );
			}
			foreach ( $ups as $x ) {
				$uploads[] = array(
					'at'     => wp_date( 'Y-m-d H:i', (int) $x['at'] ),
					'name'   => (string) $x['name'],
					'kw'     => (array) $x['kw'],
					'impact' => \Duckhoo\Redesign\Crm\impact( (array) $x['phones'], (int) $x['at'], $d['orders'], $d['uphone'], $days, time(), $paid, \Duckhoo\Redesign\Crm\dead_statuses() ),
					'prod'   => $x['kw'] ? \Duckhoo\Redesign\CrmSends\product_stats( (array) $x['phones'], (int) $x['at'], $days, $d['orders'], $items, $d['uphone'], (array) $x['kw'], $paid ) : array(),
				);
			}
			usort( $uploads, fn( $a, $b ) => strcmp( $a['at'], $b['at'] ) );
		}
	}

	$out = array(
		'uploads' => $uploads,
		'from'   => $from,
		'to'     => $to,
		'until'  => wp_date( 'Y-m-d H:i', $until ),
		'built'  => wp_date( 'Y-m-d H:i', $now ),
		'done'   => $now >= $t,
		'days'   => (int) ceil( $len / DAY_IN_SECONDS ),
		'sales'  => $cur,
		'base'   => array( 'from' => wp_date( 'Y-m-d', $f - $len ), 'to' => wp_date( 'Y-m-d', $f - 1 ) ) + $base,
		'points' => $points,
		'sent'   => $sent,
	);
	set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/** 붙여 넣기용 글 — 숫자만. 순수. */
function text( array $r ): string {
	$w  = fn( $n ) => number_format( (int) round( (float) $n ) );
	$pc = fn( $a, $b ) => $b > 0 ? number_format( $a / $b * 100, 1 ) . '%' : '-';
	$ch = function ( $a, $b ) {
		return $b > 0 ? sprintf( '%+.0f%%', ( $a - $b ) / $b * 100 ) : '-';
	};
	$s  = $r['sales'];
	$b  = $r['base'];
	$L  = array();
	$L[] = sprintf( '[CRM 기간 성과] %s ~ %s (집계 %s%s)', $r['from'], $r['to'], $r['built'], $r['done'] ? '' : ' · 진행 중 — ' . $r['until'] . '까지' );
	$L[] = '';
	$L[] = sprintf( '가게 전체 (%d일) — 바로 앞 같은 길이 %s ~ %s 와 견줌', $r['days'], $b['from'], $b['to'] );
	$L[] = sprintf( '· 주문 %s건 · 실입금 %s원 (앞 기간 %s건 · %s원, %s)', $w( $s['all']['n'] ), $w( $s['all']['sales'] ), $w( $b['all']['n'] ), $w( $b['all']['sales'] ), $ch( $s['all']['sales'], $b['all']['sales'] ) );
	$L[] = sprintf( '· 처음 산 손님 주문 %s건 · %s원 (앞 %s건)', $w( $s['first']['n'] ), $w( $s['first']['sales'] ), $w( $b['first']['n'] ) );
	$L[] = sprintf( '· 다시 산 손님 주문 %s건 · %s원 (앞 %s건, %s)', $w( $s['repeat']['n'] ), $w( $s['repeat']['sales'] ), $w( $b['repeat']['n'] ), $ch( $s['repeat']['sales'], $b['repeat']['sales'] ) );
	$L[] = sprintf( '· 객단가 %s원 (앞 %s원)', $w( $s['aov'] ), $w( $b['aov'] ) );
	$sm = summary( $r );
	if ( $sm ) {
		$L[] = '';
		$L[] = '한눈에 (받은 · 산 사람 · 전환율 · 보내기 전 대비)';
		foreach ( $sm as $x ) {
			$L[] = sprintf( '· %s %s %s — %s명 중 %s명 (%s) · 보내기 전 %s명 → %+d명 · 실입금 %s원%s', substr( $x['date'], 5 ), $x['kind'], $x['name'], $w( $x['n'] ), $w( $x['buyers'] ), $pc( $x['buyers'], $x['n'] ), $w( $x['before'] ), $x['buyers'] - $x['before'], $w( $x['sales'] ), '' !== $x['note'] ? ' · ' . $x['note'] : '' );
		}
	}
	if ( $r['points'] ) {
		$L[] = '';
		$L[] = '적립금 묶음 (자세히)';
		foreach ( $r['points'] as $p ) {
			$t   = (array) ( $p['p'][''] ?? array() );
			$L[] = sprintf( '· %s — 넣은 날 %s · %s명 · %s원%s', $p['label'], implode( ', ', array_map( fn( $x ) => substr( $x['at'], 5, 5 ) . ' ' . $w( $x['n'] ) . '명', $p['runs'] ) ) ?: substr( $p['created'], 5, 5 ), $w( $p['revoked'] && $t ? $t['n'] : $p['n'] ), $w( $p['revoked'] && $t ? $t['given'] : $p['sum'] ), $p['revoked'] ? ' 넣었다가 거둠' : '' );
			if ( $t ) {
				$L[] = sprintf( '  받은 %s명 중 산 사람 %s명 (%s) · 적립금 쓴 사람 %s명 (%s) · 주문 %s건 · 실입금 %s원 · 쓴 적립금 %s원 · 넣기 전 같은 기간 산 사람 %s명', $w( $t['n'] ), $w( $t['buyers'] ), $pc( $t['buyers'], $t['n'] ), $w( $t['users'] ), $pc( $t['users'], $t['n'] ), $w( $t['orders'] ), $w( $t['sales'] ), $w( $t['used'] ), $w( $t['before'] ) );
				foreach ( $p['p'] as $k => $v ) {
					if ( '' === $k || '(종류 없음)' === $k ) {
						continue;
					}
					$L[] = sprintf( '    %s: %s명 → 산 %s (%s) · 실입금 %s원', $k, $w( $v['n'] ), $w( $v['buyers'] ), $pc( $v['buyers'], $v['n'] ), $w( $v['sales'] ) );
				}
			}
		}
	}
	if ( $r['sent'] ) {
		$L[] = '';
		$L[] = '문자 양식으로 내려받은 묶음 (받은 뒤 14일)';
		foreach ( $r['sent'] as $x ) {
			$m   = $x['impact'];
			$L[] = sprintf( '· %s %s — %s명 중 산 사람 %s명 (%s) · 주문 %s건 · 실입금 %s원 · 보내기 전 같은 기간 %s명', substr( $x['at'], 5, 11 ), $x['group'], $w( $m['n'] ), $w( $m['buyers'] ), $pc( $m['buyers'], $m['n'] ), $w( $m['orders'] ), $w( $m['sales'] ), $w( $m['before']['buyers'] ) );
		}
	}
	$L[] = '';
	$L[] = '일별 (주문 · 실입금 · 처음/다시)';
	foreach ( $s['daily'] as $d => $v ) {
		$L[] = sprintf( '%s  %s건 · %s원 · %s/%s', substr( $d, 5 ), $w( $v['n'] ), $w( $v['sales'] ), $w( $v['first'] ), $w( $v['repeat'] ) );
	}
	return implode( "\n", $L );
}

/**
 * 「한눈에」 — 보낸 것마다 한 줄. 받은 사람이 없는 줄은 빼고, 같은 날 같은 묶음을 여러 번 내려받은 것은 하나로. 순수.
 *
 * @return array<int,array{date:string,kind:string,name:string,n:int,buyers:int,before:int,sales:float,note:string}>
 */
function summary( array $r ): array {
	$rows = array();
	foreach ( (array) ( $r['points'] ?? array() ) as $p ) {
		$t = (array) ( $p['p'][''] ?? array() );
		if ( (int) ( $t['n'] ?? 0 ) <= 0 ) {
			continue;
		}
		$note = ! empty( $p['revoked'] ) ? '기간 끝나 거둠' : '';
		if ( isset( $t['elapsed'] ) && (int) $t['elapsed'] < 14 ) {
			$note = trim( $note . ' · 진행 ' . (int) $t['elapsed'] . '일째', ' ·' );
		}
		$rows[] = array( 'date' => substr( (string) $p['created'], 0, 10 ), 'kind' => '적립금', 'name' => (string) $p['label'], 'n' => (int) $t['n'], 'buyers' => (int) $t['buyers'], 'before' => (int) $t['before'], 'sales' => (float) $t['sales'], 'note' => $note );
	}
	$seen = array();
	foreach ( array_reverse( (array) ( $r['sent'] ?? array() ) ) as $x ) {
		$m = (array) $x['impact'];
		$k = substr( (string) $x['at'], 0, 10 ) . '|' . (string) $x['group'];
		if ( (int) ( $m['n'] ?? 0 ) <= 0 || isset( $seen[ $k ] ) ) {
			continue;
		}
		$seen[ $k ] = true;
		$rows[]     = array( 'date' => substr( (string) $x['at'], 0, 10 ), 'kind' => '문자', 'name' => (string) $x['group'], 'n' => (int) $m['n'], 'buyers' => (int) $m['buyers'], 'before' => (int) ( $m['before']['buyers'] ?? 0 ), 'sales' => (float) $m['sales'], 'note' => empty( $m['done'] ) ? '진행 ' . (int) ( $m['elapsed'] ?? 0 ) . '일째' : '' );
	}
	foreach ( (array) ( $r['uploads'] ?? array() ) as $x ) {
		$m = (array) $x['impact'];
		if ( (int) ( $m['n'] ?? 0 ) <= 0 ) {
			continue;
		}
		$note = $x['prod'] ? implode( '·', (array) $x['kw'] ) . ' 산 사람 ' . (int) $x['prod']['buyers'] . '명' : '';
		if ( empty( $m['done'] ) ) {
			$note = trim( $note . ' · 진행 ' . (int) ( $m['elapsed'] ?? 0 ) . '일째', ' ·' );
		}
		$rows[] = array( 'date' => substr( (string) $x['at'], 0, 10 ), 'kind' => $x['kw'] ? '신상품 문자' : '문자', 'name' => preg_replace( '/\.(xlsx|xls|csv|txt)$/i', '', (string) $x['name'] ), 'n' => (int) $m['n'], 'buyers' => (int) $m['buyers'], 'before' => (int) ( $m['before']['buyers'] ?? 0 ), 'sales' => (float) $m['sales'], 'note' => $note );
	}
	usort( $rows, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) ?: strcmp( $a['name'], $b['name'] ) );
	return $rows;
}

/** 「한눈에」 표 (HTML). */
function summary_html( array $r ): string {
	$rows = summary( $r );
	$w    = fn( $n ) => number_format_i18n( (int) round( (float) $n ) );
	$s    = (array) $r['sales'];
	$b    = (array) $r['base'];
	$ch   = $b['all']['sales'] > 0 ? sprintf( '%+.0f%%', ( $s['all']['sales'] - $b['all']['sales'] ) / $b['all']['sales'] * 100 ) : '-';
	$h    = '<div style="background:#fff;border:1px solid #dcdcde;padding:14px 16px;margin:10px 0;max-width:1040px">';
	$h   .= '<p style="margin:0 0 10px;font-size:15px">가게 전체 ' . esc_html( $r['from'] ) . ' ~ ' . esc_html( $r['to'] ) . ' — 주문 <b>' . esc_html( $w( $s['all']['n'] ) ) . '건</b> · 실입금 <b>' . esc_html( $w( $s['all']['sales'] ) ) . '원</b> <span style="color:#646970">(앞 같은 기간 ' . esc_html( $w( $b['all']['sales'] ) ) . '원, ' . esc_html( $ch ) . ')</span> · 다시 산 손님 주문 ' . esc_html( $w( $s['repeat']['n'] ) ) . '건 <span style="color:#646970">(앞 ' . esc_html( $w( $b['repeat']['n'] ) ) . '건)</span></p>';
	if ( ! $rows ) {
		return $h . '<p>이 기간에 보낸 것이 없습니다.</p></div>';
	}
	$h .= '<div style="overflow-x:auto"><table class="widefat striped" style="min-width:860px"><thead><tr><th>날짜</th><th>종류</th><th>무엇</th><th style="text-align:right">받은</th><th style="text-align:right">산 사람</th><th style="text-align:right">전환율</th><th style="text-align:right">보내기 전</th><th style="text-align:right">늘어난 사람</th><th style="text-align:right">실입금</th><th>비고</th></tr></thead><tbody>';
	foreach ( $rows as $x ) {
		$lift = $x['buyers'] - $x['before'];
		$col  = $lift > 0 ? '#067647' : ( $lift < 0 ? '#B42318' : '#646970' );
		$h   .= '<tr><td>' . esc_html( substr( $x['date'], 5 ) ) . '</td><td>' . esc_html( $x['kind'] ) . '</td><td>' . esc_html( $x['name'] ) . '</td>'
			. '<td style="text-align:right">' . esc_html( $w( $x['n'] ) ) . '명</td><td style="text-align:right"><b>' . esc_html( $w( $x['buyers'] ) ) . '명</b></td>'
			. '<td style="text-align:right"><b>' . esc_html( number_format( $x['n'] ? $x['buyers'] / $x['n'] * 100 : 0, 1 ) ) . '%</b></td>'
			. '<td style="text-align:right">' . esc_html( $w( $x['before'] ) ) . '명</td><td style="text-align:right;color:' . $col . '"><b>' . esc_html( sprintf( '%+d', $lift ) ) . '명</b></td>'
			. '<td style="text-align:right">' . esc_html( $w( $x['sales'] ) ) . '원</td><td style="color:#646970">' . esc_html( $x['note'] ) . '</td></tr>';
	}
	$h .= '</tbody></table></div>';
	$h .= '<p style="margin:8px 0 0;color:#646970">전환율 = 받은 사람 중 받은 뒤 14일 안에 산 사람. 「늘어난 사람」 = 산 사람 − 같은 사람들이 받기 전 같은 날수 동안 산 사람 (초록이면 효과가 있었던 것). 신상품 문자는 <b>마케팅 → 문자 참여</b>에 보낸 파일을 한 번 올리면 여기에도 계속 나옵니다.</p></div>';
	return $h;
}

/** 고객 세그먼트 화면의 상자. */
function box(): void {
	$from = isset( $_GET['rf'] ) && ymd( (string) $_GET['rf'] ) ? (string) $_GET['rf'] : wp_date( 'Y-m-01' ); // phpcs:ignore WordPress.Security
	$to   = isset( $_GET['rt'] ) && ymd( (string) $_GET['rt'] ) ? (string) $_GET['rt'] : wp_date( 'Y-m-d' ); // phpcs:ignore WordPress.Security
	echo '<details class="dhr-crm-adv" style="background:#fff;border:1px solid #dcdcde;margin:12px 0"' . ( isset( $_GET['rf'] ) ? ' open' : '' ) . '><summary style="font-weight:700;cursor:pointer">CRM 성과 한눈에 — 보낸 것마다 받은 사람 · 산 사람 · 전환율</summary>'; // phpcs:ignore WordPress.Security
	echo '<form method="get" style="margin:10px 0"><input type="hidden" name="page" value="duckhoo-crm"> 시작 <input type="date" name="rf" value="' . esc_attr( $from ) . '"> 끝 <input type="date" name="rt" value="' . esc_attr( $to ) . '"> <button class="button button-primary">보기</button></form>';
	if ( isset( $_GET['rf'] ) ) { // phpcs:ignore WordPress.Security
		try {
			$r = period( $from, $to );
			echo summary_html( $r ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<details><summary style="cursor:pointer">자세한 숫자 (붙여 넣기용)</summary><p style="color:#646970">숫자만 들어 있습니다 (이름 · 연락처 없음).</p>';
			echo '<textarea readonly rows="22" style="width:100%;font-family:monospace;font-size:12px" onclick="this.select()">' . esc_textarea( text( $r ) ) . '</textarea></details>';
		} catch ( \Throwable $e ) {
			echo '<p style="color:#b42318">셈하다 멈췄습니다: ' . esc_html( $e->getMessage() ) . '</p>';
		}
	}
	echo '</details>';
}

/** REST — 브리핑 열쇠로 닫는다. 집계만. */
function routes(): void {
	register_rest_route( 'duckhoo/v1', '/crm-report', array(
		'methods'             => 'GET',
		'permission_callback' => fn( $req ) => function_exists( '\\Duckhoo\\Redesign\\Brief\\authorized' ) && \Duckhoo\Redesign\Brief\authorized( $req ),
		'callback'            => function ( $req ) {
			$from = (string) $req->get_param( 'from' );
			$to   = (string) $req->get_param( 'to' );
			$from = ymd( $from ) ? $from : wp_date( 'Y-m-01' );
			$to   = ymd( $to ) ? $to : wp_date( 'Y-m-d' );
			try {
				$r         = period( $from, $to, '1' === (string) $req->get_param( 'fresh' ) );
				$r['text'] = text( $r );
				return rest_ensure_response( $r );
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'dhr_crm_report_failed', $e->getMessage(), array( 'status' => 500 ) );
			}
		},
	) );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\routes' );
