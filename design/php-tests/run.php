<?php
/* 워드프레스 없이 회원탈퇴 로직만 돌려 본다: php design/php-tests/run.php */
require __DIR__.'/stubs.php';
require dirname(__DIR__, 2) . '/includes/membership-cancel.php';
use function Duckhoo\Redesign\MembershipCancel as _;

$ns = 'Duckhoo\\Redesign\\MembershipCancel\\';
$fail = [];
$ok = function($cond,$msg) use (&$fail){ if(!$cond) $fail[]=$msg; else echo "· $msg\n"; };

// 1. 진행 중 상태에 입금전~배송중이 들어가고 배송완료·완료·취소는 빠진다
$open = ($ns.'open_order_statuses')();
$ok(in_array('keyple-before',$open,true) && in_array('keyple-shipping',$open,true), '진행 중 상태에 입금전·배송중 포함');
$ok(!in_array('keyple-done',$open,true), '배송완료는 진행 중이 아니다');
$ok(!in_array('completed',$open,true) && !in_array('cancelled',$open,true), '완료·취소는 진행 중이 아니다');

// 2. 진행 중 주문 · 남은 적립금은 이제 막지 않고 알려만 준다 (사장님 결정 2026-09-04)
$GLOBALS['__orders'] = [101,102];
$b = ($ns.'cautions')(1);
$ok(count($b)===1 && str_contains($b[0],'2건'), '진행 중 주문 2건 → 안내 문구');

// 3. 주문이 없고 적립금도 없으면 통과
$GLOBALS['__orders'] = [];
$ok(($ns.'cautions')(1) === [], '주문 없음 → 안내 없음');

// 4. 적립금이 남아 있으면 막힌다 (메타 이름으로 찾는다)
$GLOBALS['__usermeta'][1] = ['keyple_point' => '2400'];
$b = ($ns.'cautions')(1);
$ok(count($b)===1 && str_contains($b[0],'2,400'), '적립금 2,400원 → 안내 문구');

// 5. 적립금 0 이면 안 막는다
$GLOBALS['__usermeta'][1] = ['keyple_point' => '0'];
$ok(($ns.'cautions')(1) === [], '적립금 0원 → 안내 없음');

// 6. 필터로 준 값이 메타보다 우선한다
$GLOBALS['__usermeta'][1] = ['keyple_point' => '0'];
add_filter('duckhoo_member_points', fn($v,$id=null)=>500);
$ok(($ns.'point_balance')(1) === 500.0, '필터가 메타보다 우선');
$GLOBALS['__filters']['duckhoo_member_points'] = [];

// 7. 화면: 막힌 상태
$GLOBALS['__usermeta'][1] = ['keyple_point' => '2400'];
$html = ($ns.'render')();
$ok(str_contains($html,'탈퇴 전에 확인해 주세요') && str_contains($html,'name="duckhoo_password"'), '안내가 있어도 폼은 그린다');

// 8. 화면: 진행 가능
$GLOBALS['__usermeta'][1] = [];
$html = ($ns.'render')();
$ok(str_contains($html,'name="duckhoo_password"') && str_contains($html,'name="duckhoo_agree"'), '가능한 상태에서는 비밀번호·동의 폼을 그린다');
$ok(str_contains($html,'5년'), '거래기록 보존 안내가 들어 있다');

// 9. 로그아웃 상태
$GLOBALS['__logged_in'] = 0;
$html = ($ns.'render')();
$ok(str_contains($html,'로그인한 뒤에'), '비로그인 → 로그인 안내');
$GLOBALS['__logged_in'] = 1;

// 10. 제출: 논스가 틀리면 아무 일도 없다
$_POST = ['duckhoo_membership_cancel'=>'1','_wpnonce'=>'bad','duckhoo_agree'=>'1','duckhoo_password'=>'correct'];
($ns.'handle_submit')();
$ok(empty($GLOBALS['__pwset']), '논스 불일치 → 탈퇴 안 됨');

// 11. 제출: 동의 안 하면 아무 일도 없다
$_POST = ['duckhoo_membership_cancel'=>'1','_wpnonce'=>'good','duckhoo_password'=>'correct'];
($ns.'handle_submit')();
$ok(empty($GLOBALS['__pwset']), '동의 없음 → 탈퇴 안 됨');

// 12. 제출: 비밀번호가 틀리면 안내만 남는다
$_POST = ['duckhoo_membership_cancel'=>'1','_wpnonce'=>'good','duckhoo_agree'=>'1','duckhoo_password'=>'wrong'];
($ns.'handle_submit')();
$ok(empty($GLOBALS['__pwset']) && get_transient('duckhoo_cancel_error_1'), '비밀번호 불일치 → 안내 남김');

// 13. 제출: 다 맞으면 익명화되고 로그아웃된다
$GLOBALS['__usermeta'][1] = ['billing_phone'=>'01012345678','first_name'=>'홍'];
$_POST = ['duckhoo_membership_cancel'=>'1','_wpnonce'=>'good','duckhoo_agree'=>'1','duckhoo_password'=>'correct'];
try { ($ns.'handle_submit')(); } catch (\Throwable $e) {}
$ok(!empty($GLOBALS['__pwset']), '정상 제출 → 비밀번호 무효화');
$ok(($GLOBALS['__updated']['display_name'] ?? '') === '탈퇴회원', '표시 이름이 탈퇴회원으로 바뀐다');
$ok(str_contains($GLOBALS['__updated']['user_email'] ?? '', '@duck-hoo.invalid'), '이메일이 무효 주소로 바뀐다');
$ok(!isset($GLOBALS['__usermeta'][1]['billing_phone']) && !isset($GLOBALS['__usermeta'][1]['first_name']), '연락처·이름 메타가 지워진다');
$ok(!empty($GLOBALS['__usermeta'][1]['_duckhoo_withdrawn_at']), '탈퇴 표시가 남는다');

// 14. 빈 페이지는 채우고, 내용이 있는 페이지는 건드리지 않는다
$GLOBALS['__logged_in'] = 1;
$filled = ($ns.'fill_empty_page')('<p>/</p>');
$ok(str_contains($filled,'dh-leave'), '빈 페이지는 채운다');
$kept = ($ns.'fill_empty_page')('<p>여기에 원래 안내문이 길게 들어 있고 사람이 직접 쓴 내용이 있습니다. 건드리면 안 됩니다.</p>');
$ok(!str_contains($kept,'dh-leave'), '내용이 있는 페이지는 그대로 둔다');

// 15. 주문취소 필터: on-hold(결제 확인 중 = 입금 전) 가 열리고, 입금확인은 안 열린다
require_once dirname(__DIR__, 2).'/.dhr-main-test.php';
$allowed = \Duckhoo\Redesign\allow_cancel_before_deposit( ['pending','failed'] );
$ok(in_array('on-hold',$allowed,true) && in_array('pending',$allowed,true), 'on-hold 가 취소 가능 목록에 들어간다');
$ok(!in_array('payment-confirmed',$allowed,true) && !in_array('keyple-shipping',$allowed,true), '입금확인·배송중은 열리지 않는다');


/* ── 16~ 적립금을 쓴 주문의 취소 (includes/points.php) ─────────────────── */
$P = 'Duckhoo\\Redesign\\Points\\';

// 16. 주문 메타로 사용액을 읽는다
$o = new DhrFakeOrder(101, ['_wd_point_discount' => '3000']);
$ok(($P.'used')($o) === 3000.0, '주문 메타 _wd_point_discount 로 사용액을 읽는다');

// 17. 수수료 줄(음수)로도 읽는다
$o2 = new DhrFakeOrder(102, [], [new DhrFakeItem('적립금 할인', -2500.0)]);
$ok(($P.'used')($o2) === 2500.0, '수수료 줄 "적립금 할인 -2,500" 을 읽는다');

// 18. 적립(earn) 기록은 사용으로 세지 않는다 — 취소를 괜히 막으면 안 된다
$o3 = new DhrFakeOrder(103, ['_points_earned' => '880', '_wd_reward_note' => '1200']);
$ok(($P.'used')($o3) === 0.0, '적립 기록(_points_earned)은 사용으로 읽지 않는다');

// 18-b. 켜짐/꺼짐 칸(_applied)은 금액으로 읽지 않는다 — 실제 사이트에 그 칸이 있다
$o3b = new DhrFakeOrder(105, ['_wd_point_discount_applied' => '1']);
$ok(($P.'used')($o3b) === 0.0, '_wd_point_discount_applied 는 금액이 아니다');

// 19. 적립금을 안 쓴 주문은 취소 버튼이 그대로다
$plain = new DhrFakeOrder(104);
$st = ($P.'close_cancel_for_point_orders')(['pending','failed','on-hold'], $plain);
$ok(in_array('on-hold',$st,true), '적립금을 안 쓴 주문은 on-hold 취소가 열린 채다');

// 20. 적립금을 쓴 주문은 우리가 연 상태만 닫힌다 (기본 pending·failed 는 그대로)
$st2 = ($P.'close_cancel_for_point_orders')(['pending','failed','on-hold'], $o);
$ok(!in_array('on-hold',$st2,true), '적립금을 쓴 주문은 on-hold 취소가 닫힌다');
$ok(in_array('pending',$st2,true) && in_array('failed',$st2,true), '워드커머스 기본 취소 상태는 뺏지 않는다');

// 21. 필터로 끄면 도로 열린다
add_filter('duckhoo_block_cancel_with_points', fn($v)=>false);
$ok(in_array('on-hold', ($P.'close_cancel_for_point_orders')(['pending','on-hold'], $o), true), '필터로 끄면 취소가 다시 열린다');
$GLOBALS['__filters']['duckhoo_block_cancel_with_points'] = [];

// 22. 취소 버튼 자리에 문의 버튼이 선다
$acts = ($P.'inquiry_action')([], $o);
$ok(isset($acts['duckhoo-cancel-ask']), '취소 버튼이 없어진 자리에 문의 버튼이 선다');
$ok(!isset(($P.'inquiry_action')([], $plain)['duckhoo-cancel-ask']), '적립금을 안 쓴 주문에는 문의 버튼을 더하지 않는다');
$ok(!isset(($P.'inquiry_action')(['cancel'=>[]], $o)['duckhoo-cancel-ask']), '취소 버튼이 살아 있으면 문의 버튼은 안 세운다');

// 23. 취소되면 주문 메모가 한 번 남는다
$GLOBALS['__order_by_id'][101] = $o;
($P.'note_on_cancel')(101, $o);
($P.'note_on_cancel')(101, $o);
$ok(count($o->notes) === 1 && str_contains($o->notes[0],'3,000'), '취소되면 적립금 3,000원 메모가 한 번만 남는다');
$ok(($P.'used')($plain) === 0.0 && ($P.'note_on_cancel')(104, $plain) === null && $plain->notes === [], '적립금을 안 쓴 주문에는 메모를 남기지 않는다');

echo $fail ? "\n❌ ".count($fail)."건\n".implode("\n",$fail)."\n" : "\n✅ 모두 통과\n";
exit($fail?1:0);
