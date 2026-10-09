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

// 20. 돌려줄 수 없을 때만 닫는다. 우리가 연 상태만 — 기본 pending·failed 는 그대로
$ok(in_array('on-hold', ($P.'close_cancel_for_point_orders')(['pending','on-hold'], $o), true), '돌려줄 수 있으면 적립금 주문도 취소가 열려 있다');
$GLOBALS['__keyple_on'] = false;
$st2 = ($P.'close_cancel_for_point_orders')(['pending','failed','on-hold'], $o);
$ok(!in_array('on-hold',$st2,true), '적립금을 쓴 주문은 on-hold 취소가 닫힌다');
$ok(in_array('pending',$st2,true) && in_array('failed',$st2,true), '워드커머스 기본 취소 상태는 뺏지 않는다');

// 21. 필터로 끄면 도로 열린다
add_filter('duckhoo_block_cancel_with_points', fn($v)=>false);
$ok(in_array('on-hold', ($P.'close_cancel_for_point_orders')(['pending','on-hold'], $o), true), '필터로 끄면 취소가 다시 열린다');
$GLOBALS['__filters']['duckhoo_block_cancel_with_points'] = [];

// 22. 취소 버튼 자리에 문의 버튼이 선다 (막고 있을 때만)
$GLOBALS['__keyple_on'] = false;
$acts = ($P.'inquiry_action')([], $o);
$ok(isset($acts['duckhoo-cancel-ask']), '취소 버튼이 없어진 자리에 문의 버튼이 선다');
$ok(!isset(($P.'inquiry_action')([], $plain)['duckhoo-cancel-ask']), '적립금을 안 쓴 주문에는 문의 버튼을 더하지 않는다');
$ok(!isset(($P.'inquiry_action')(['cancel'=>[]], $o)['duckhoo-cancel-ask']), '취소 버튼이 살아 있으면 문의 버튼은 안 세운다');
$GLOBALS['__keyple_on'] = true;
$ok(!isset(($P.'inquiry_action')([], $o)['duckhoo-cancel-ask']), '돌려줄 수 있으면 문의 버튼도 안 세운다');

// 23. 취소되면 주문 메모가 한 번 남는다 (돌려줄 수 없을 때)
$GLOBALS['__keyple_on'] = false;
$GLOBALS['__order_by_id'][101] = $o;
($P.'on_cancel')(101, $o);
($P.'on_cancel')(101, $o);
$ok(count($o->notes) === 1 && str_contains($o->notes[0],'3,000'), '못 돌려주면 적립금 3,000원 메모가 한 번만 남는다');
$ok(($P.'used')($plain) === 0.0 && ($P.'on_cancel')(104, $plain) === null && $plain->notes === [], '적립금을 안 쓴 주문에는 메모를 남기지 않는다');

/* ── 24~ 자동 반환 (테마 함수가 있을 때) ──────────────────────────────── */
$GLOBALS['__keyple_on'] = true;
$GLOBALS['__ledger'] = [];

// 24. 돌려줄 수 있으면 취소를 막지 않는다
$ok(($P.'can_return')() === true, '테마 함수가 있으면 돌려줄 수 있다고 본다');
$ok(($P.'blocks_cancel')() === false, '돌려줄 수 있으면 취소를 막지 않는다');
$GLOBALS['__keyple_on'] = false;
$ok(($P.'blocks_cancel')() === true, '못 돌려주면 다시 막는다');
$GLOBALS['__keyple_on'] = true;

// 25. 가입 적립금 8,800 을 쓴 주문 — 잔액 · 원장 · 가입 주머니가 모두 돌아온다
$GLOBALS['__usermeta'][900] = ['_keyple_points' => '0', '_wd_signup_point_balance' => '0'];
$r = new DhrFakeOrder(4220, ['_wd_point_discount'=>'8800','_wd_point_discount_applied'=>'1'], [], [], 'cancelled');
$r->uid = 900;
$back = ($P.'return_points')($r);
$ok($back === 8800, '8,800원을 돌려준다');
$ok((int)$GLOBALS['__usermeta'][900]['_keyple_points'] === 8800, '잔액 _keyple_points 가 8,800 이 된다');
$ok((int)$GLOBALS['__usermeta'][900]['_wd_signup_point_balance'] === 8800, '가입 적립금 주머니도 8,800 으로 돌아온다');
$ok(count($GLOBALS['__ledger']) === 1 && $GLOBALS['__ledger'][0] === [900, 8800, '주문 #4220 취소 적립금 반환'], '원장에 +8,800 한 줄이 남는다');
$ok(count($r->notes) === 1 && str_contains($r->notes[0],'8,800'), '주문 메모로 반환을 알린다');

// 26. 두 번 돌려주지 않는다
$ok(($P.'return_points')($r) === 0 && count($GLOBALS['__ledger']) === 1, '같은 주문을 두 번 돌려주지 않는다');

// 27. 일반 적립금 5,000 을 쓴 주문 — 가입 주머니는 이미 차 있으므로 건드리지 않는다
$GLOBALS['__ledger'] = [];
$GLOBALS['__usermeta'][901] = ['_keyple_points' => '8800', '_wd_signup_point_balance' => '8800'];
$r2 = new DhrFakeOrder(4225, ['_wd_point_discount'=>'5000','_wd_point_discount_applied'=>'1'], [], [], 'cancelled');
$r2->uid = 901;
$ok(($P.'return_points')($r2) === 5000, '5,000원을 돌려준다');
$ok((int)$GLOBALS['__usermeta'][901]['_keyple_points'] === 13800, '잔액이 8,800 → 13,800');
$ok((int)$GLOBALS['__usermeta'][901]['_wd_signup_point_balance'] === 8800, '가입 주머니는 지급액을 넘지 않는다 (8,800 그대로)');

// 28. 잔액에서 빠진 적이 없는 주문은 건드리지 않는다
$GLOBALS['__usermeta'][902] = ['_keyple_points' => '100'];
$r3 = new DhrFakeOrder(4300, ['_wd_point_discount'=>'3000'], [], [], 'cancelled'); // _applied 없음
$r3->uid = 902;
$ok(($P.'return_points')($r3) === 0 && (int)$GLOBALS['__usermeta'][902]['_keyple_points'] === 100, '_applied 가 없으면 돌려주지 않는다');

// 29. 비회원 주문은 돌려줄 곳이 없다
$r4 = new DhrFakeOrder(4301, ['_wd_point_discount'=>'3000','_wd_point_discount_applied'=>'1'], [], [], 'cancelled');
$ok(($P.'return_points')($r4) === 0, '비회원 주문은 건너뛴다');

// 30. on_cancel 은 돌려줬으면 "못 돌려준다" 메모를 남기지 않는다
$GLOBALS['__usermeta'][903] = ['_keyple_points' => '0', '_wd_signup_point_balance' => '0'];
$r5 = new DhrFakeOrder(4400, ['_wd_point_discount'=>'8800','_wd_point_discount_applied'=>'1'], [], [], 'cancelled');
$r5->uid = 903;
($P.'on_cancel')(4400, $r5);
$ok(count($r5->notes) === 1 && !str_contains($r5->notes[0],'자동으로 돌아가지 않습니다'), '돌려준 뒤에는 경고 메모를 남기지 않는다');


/* ── 31~ 노보 물량 이벤트 (includes/novo.php) ────────────────────────────── */
$N = 'Duckhoo\\Redesign\\Novo\\';

// **하루 한도는 꺼진 것이 기본이다** (사장님 결정 2026-09-10 — 세트 구매 제한 해제).
// 한도가 하는 일을 재려면 켜 놓고 봐야 한다. 꺼진 기본값은 맨 끝에서 따로 확인한다.
// 낱병까지 세는 경우도 함께 재려고 singles 도 켜 두고, 그 기본값도 따로 본다.
$capOn = function( array $more = [] ) {
  $GLOBALS['__filters']['duckhoo_novo_event'] = [fn($c) => $more + ['limit_on' => true] + $c];
};
$singlesOn = function() use ($capOn) { $capOn(['singles' => true]); };
$singlesOn();

$mk = function(int $id, string $name, float $price = 9900.0, bool $stock = true, bool $manage = false, ?int $left = null) {
  $p = new WC_Product($id, $name, $price, $stock, $manage, $left);
  $GLOBALS['__products'][$id] = $p;
  return $p;
};
$plain  = $mk(238, '[노보] 블랙멘솔 (9.8mg / 30ml)', 13000);
$black  = $mk(249, '[노보 블랙] 블랙멘솔 (9.8mg / 30ml)', 13500);
$bundle = $mk(600, '[노보] 10+1 묶음', 120000);
$ten    = $mk(146, '[초특가] 노보 10병 병당 8,000원 / 금액 80,000원', 80000);
$other  = $mk(700, '[얼려먹구싶오] 청포도 (9.8mg / 30ml)', 9900);
$lowst  = $mk(701, '[노보] 그린펀치 (9.8mg / 30ml)', 13000, true, true, 4);

// 31. 라인 가르기 — 블랙을 먼저 보지 않으면 "노보 블랙" 이 "노보" 로 잡힌다
$ok(($N.'line')($black) === 'black' && ($N.'line')($plain) === 'plain' && ($N.'line')($other) === '', '노보 블랙 · 노보 · 그 밖을 가른다');

// 32. 병 수는 이름에서 읽는다 — 받는 병(bottles)과 한도로 세는 병(paid)은 다르다
$ok(($N.'bottles')($plain) === 1 && ($N.'paid')($plain) === 1, '단품은 1병, 1병으로 센다');
$ok(($N.'bottles')($bundle) === 11 && ($N.'paid')($bundle) === 10, '"10+1" 은 11병을 받고 10병으로 센다');
$ok(($N.'bottles')($ten) === 10 && ($N.'paid')($ten) === 10, '"10병" 은 10병');
$ok(($N.'bottles')($other) === 0 && ($N.'paid')($other) === 0, '노보가 아니면 0병');

// 33. 장바구니 집계
$GLOBALS['__orders'] = [];
$GLOBALS['__logged_in'] = 0;
$GLOBALS['__cart']->items = [
  ['data' => $plain, 'quantity' => 3],
  ['data' => $other, 'quantity' => 9],
];
$t = ($N.'tally_cart')();
$ok($t['plain'] === 3 && $t['black'] === 0, '장바구니에서 노보만 센다');

// 34. 남은 수량 = 한도 − 장바구니
$ok(($N.'left')('plain') === 7, '10병 한도에서 3병을 담았으면 7병 남는다');

// 35. 낱병은 하루 10병까지
$GLOBALS['__notices'] = [];
$ok(($N.'validate_add')(true, 238, 7) === true, '낱병 7병은 담긴다 (합계 10병)');
$ok(($N.'validate_add')(true, 238, 8) === false, '낱병 8병은 막힌다 (합계 11병)');
$ok(($N.'validate_add')(true, 700, 50) === true, '노보가 아닌 상품은 한도와 무관하다');
$ok(count($GLOBALS['__notices']) === 1 && $GLOBALS['__notices'][0][0] === 'error', '막을 때 안내를 남긴다');

// 35-b. 빈 장바구니에서 낱병 10병은 되고 11병은 안 된다
$GLOBALS['__cart']->items = [];
$ok(($N.'validate_add')(true, 238, 10) === true, '낱병 10병은 담긴다');
$ok(($N.'validate_add')(true, 238, 11) === false, '낱병 11병은 막힌다');

// 36. 10+1 묶음 한 세트가 하루치다 — 사은품 1병은 세지 않으므로 낱병 10병과 같다
$ok(($N.'validate_add')(true, 600, 1) === true, '10+1 한 세트는 담긴다');
$ok(($N.'validate_add')(true, 600, 2) === false, '10+1 두 세트는 막힌다');
$GLOBALS['__cart']->items = [['data' => $bundle, 'quantity' => 1]];
$ok(($N.'left')('plain') === 0, '한 세트를 담으면 그날치가 다 찬다');
$ok(($N.'validate_add')(true, 238, 1) === false, '한 세트를 담은 뒤에는 낱병도 더 담기지 않는다');

// 36-b. 낱병을 조금 담아 두면 그만큼만 남는다 — 세트는 10병이 필요하다
$GLOBALS['__cart']->items = [['data' => $plain, 'quantity' => 4]];
$ok(($N.'left')('plain') === 6, '낱병 4병을 담았으면 6병 남는다');
$ok(($N.'validate_add')(true, 600, 1) === false, '6병만 남았으면 10+1 세트는 담기지 않는다');

// 37. 오늘 주문한 것도 함께 센다
$GLOBALS['__cart']->items = [];
$o = new DhrFakeOrder(9001, [], [], [], 'on-hold');
$o->lines = [new DhrFakeLine($plain, 6)];
$GLOBALS['__orders'] = [$o];
$ok(($N.'left')('plain', 5, false) === 4, '오늘 6병을 주문했으면 4병 남는다');
$GLOBALS['__logged_in'] = 5; // 로그인한 손님이라야 오늘 주문분을 셀 수 있다
$ok(($N.'validate_add')(true, 238, 5) === false, '오늘 주문분을 합쳐 한도를 넘으면 막힌다');
$ok(($N.'validate_add')(true, 238, 4) === true, '남은 4병은 담긴다');
$GLOBALS['__logged_in'] = 0;
$GLOBALS['__orders'] = [];

// 38. 장바구니 화면의 다시 보기
$GLOBALS['__notices'] = [];
$GLOBALS['__cart']->items = [['data' => $bundle, 'quantity' => 2]];
($N.'check_cart')();
$ok(count($GLOBALS['__notices']) === 1, '장바구니가 한도를 넘으면 안내를 남긴다');
// 결제 화면을 그리는 중에는 안내를 넣지 않는다 — 테마 요약이 0원이 된다
$GLOBALS['__notices'] = []; $GLOBALS['__is_checkout'] = true;
($N.'check_cart_page')();
$ok(count($GLOBALS['__notices']) === 0, '결제 화면을 그릴 때는 안내를 넣지 않는다');
$GLOBALS['__is_checkout'] = false;
($N.'check_cart_page')();
$ok(count($GLOBALS['__notices']) === 1, '장바구니 화면에서는 남긴다');
$GLOBALS['__notices'] = [];
$GLOBALS['__cart']->items = [['data' => $bundle, 'quantity' => 1]];
($N.'check_cart')();
$ok(count($GLOBALS['__notices']) === 0, '한도 안이면 조용하다');

// 39. 라인별로 세는 설정
add_filter('duckhoo_novo_event', function($c){ $c['scope'] = 'line'; return $c; });
$GLOBALS['__cart']->items = [['data' => $bundle, 'quantity' => 1]];
$ok(($N.'left')('black') === 10, '라인별로 세면 블랙은 그대로 10병 남는다');
$ok(($N.'left')('plain') === 0, '라인별로 세도 일반은 다 썼다');
$singlesOn();

// 40. 남은 재고는 재고 관리가 켜진 상품만
$ok(($N.'stock_left')($lowst) === 4 && ($N.'stock_left')($plain) === null, '재고 관리가 켜진 상품만 남은 수량이 있다');

// 41. 카드 한 줄 — 남은 수량은 기본으로 **안 보인다** (2026-09-21: 사이트 재고 ≠ 실재고). 옵션으로 켠다
$GLOBALS['__cart']->items = [];
$ok(!str_contains(apply_filters('duckhoo_card_extra', '', $lowst), '남은 수량'), '남은 수량은 기본으로 카드에 안 적는다 — 틀린 숫자는 없는 것보다 나쁘다');
$GLOBALS['__options']['duckhoo_novo_show_stock'] = '1';
$note = apply_filters('duckhoo_card_extra', '', $lowst);
$ok(str_contains($note, '남은 수량 4개') && str_contains($note, '하루 10병'), '옵션을 켜면 낱병 카드에 남은 재고와 하루 한도를 적는다');
$ok(str_contains(apply_filters('duckhoo_card_extra', '', $bundle), '하루 한 세트'), '묶음 카드는 "하루 한 세트" 라고 적는다');
$ok(apply_filters('duckhoo_card_extra', '', $other) === '', '노보가 아닌 카드에는 붙지 않는다');

// 41-b. 수량 변경 · 주문 만들기의 빗장 — 담기만 막아서는 샌다
$GLOBALS['__cart']->items = [];
$ok(($N.'max_units')($plain) === 10, '빈 장바구니에서 낱병은 10개까지');
$ok(($N.'max_units')($bundle) === 1, '빈 장바구니에서 10+1 은 한 세트까지');
$ok(($N.'max_units')($other) === -1, '노보가 아니면 우리가 정할 것이 없다');
// 고치는 중인 줄은 빼고 센다 — 자기 자신과 겨루면 상한이 0 이 된다
$GLOBALS['__cart']->items = ['abc' => ['data' => $plain, 'quantity' => 10]];
$ok(($N.'max_units')($plain, 'abc') === 10, '수량을 고치는 줄은 빼고 세어 상한이 10 이다');
$ok(($N.'max_units')($plain) === 0, '그 줄까지 세면 0 — 그래서 반드시 빼야 한다');
// Store API 상한 필터
$ok(($N.'store_max')(9999, $plain, ['key' => 'abc']) === 10, 'Store API 상한을 10 으로 낮춘다');
$ok(($N.'store_max')(9999, $plain, null) === 9999, '어느 줄인지 모르면 손대지 않는다');
$ok(($N.'store_max')(3, $plain, ['key' => 'abc']) === 3, '재고가 더 적으면 그쪽이 이긴다');
// 주문 만들기 직전
$GLOBALS['__cart']->items = ['abc' => ['data' => $plain, 'quantity' => 11]];
$threw = false; try { ($N.'guard_order')(); } catch (\Throwable $e) { $threw = str_contains($e->getMessage(), '하루 10병까지'); }
$ok($threw, '한도를 넘은 장바구니는 주문이 만들어지지 않는다');
$GLOBALS['__cart']->items = ['abc' => ['data' => $plain, 'quantity' => 10]];
$threw = false; try { ($N.'guard_order')(); } catch (\Throwable $e) { $threw = true; }
$ok(!$threw, '한도 안이면 주문이 그대로 만들어진다');
$GLOBALS['__cart']->items = [];

// 41-c. 금액대별 자동 할인 규칙은 그대로 읽는다 (노보 제외는 쿠폰 플러그인 쪽 일이다)
$F = 'Duckhoo\\Redesign\\Front\\';
$D = 'Duckhoo\\Redesign\\Discount\\';
// 모드: preview 는 쿠키를 가진 사람에게만, off 는 아무에게도
add_filter('duckhoo_auto_discount_mode', fn() => 'preview');
dhr_set_tier_recalc(true);
unset($_COOKIE['dhr_disc_off']);
$ok(($D.'wanted')() === false, 'preview 에서는 쿠키가 없으면 그대로 둔다');
$ok(($F.'discount_for')(100000.0) === 10000, '보통 손님에게는 할인이 살아 있다');
$_COOKIE['dhr_disc_off'] = '1';
$ok(($D.'wanted')() === true, '쿠키를 가진 사람에게만 끈다');
$GLOBALS['__filters']['duckhoo_auto_discount_mode'] = [];
add_filter('duckhoo_auto_discount_mode', fn() => 'off');
$ok(($D.'wanted')() === false, 'off 는 쿠키가 있어도 그대로 둔다');
$GLOBALS['__filters']['duckhoo_auto_discount_mode'] = [];
add_filter('duckhoo_auto_discount_mode', fn() => 'on');

// 테마 파일은 읽기만 하고, 숫자만 0 으로 바꾼 사본을 만들어 그 길을 돌려준다
$before = file_get_contents($GLOBALS['__tier_file']);
$ok(($D.'template_recomputes')() === true, '템플릿이 스스로 계산하는 것을 알아본다');
$copy = ($D.'patched')($GLOBALS['__tier_file']);
$ok($copy !== '' && is_readable($copy), '사본을 만든다');
$ok(file_get_contents($GLOBALS['__tier_file']) === $before, '테마 파일은 한 글자도 바뀌지 않는다');
$body = (string) file_get_contents($copy);
$ok(strpos($body, '$wd_auto_fee_discount = 0;') !== false, '사본에서는 할인이 0 이다');
$ok(strpos($body, '= 10000;') === false && strpos($body, '= 5000;') === false, '10,000 · 5,000 이 남아 있지 않다');
$ok(strpos($body, '$wd_tier_base >= 100000') !== false, '나머지 코드는 그대로다 — 숫자 하나만 바꾼다');
$ok(count(token_get_all($body)) > 5, '사본이 PHP 로 읽힌다 (문법이 깨지지 않았다)');
$ok(($D.'serve_patched')($GLOBALS['__tier_file'], 'checkout/form-checkout.php') === $copy, '워드커머스에 사본을 건넨다');
$ok(($D.'serve_patched')('/theme/cart/cart.php', 'cart/cart.php') === '/theme/cart/cart.php', '다른 템플릿은 건드리지 않는다');
$ok(($D.'killing')() === true, '그래서 할인을 끌 수 있다');
$ok(($F.'discount_for')(100000.0) === 0 && ($F.'discount_for')(500000.0) === 0, '끄면 어떤 금액에도 할인이 없다');

// 블록이 아예 없으면 사본도 필요 없다
dhr_set_tier_recalc(false);
$ok(($D.'template_recomputes')() === false, '블록이 사라진 것을 알아본다');
$ok(($D.'patched')($GLOBALS['__tier_file']) === '', '그때는 사본을 만들지 않는다');
$ok(($D.'killing')() === true, '그래도 끈다');

// 못 찾으면 아무것도 하지 않는다 — 모를 때는 건드리지 않는 쪽
file_put_contents($GLOBALS['__tier_file'], "<?php \$wd_auto_fee_discount = wd_tier( 100000 );\n");
touch($GLOBALS['__tier_file'], time() + 999);
clearstatcache(true, $GLOBALS['__tier_file']);
$ok(($D.'template_recomputes')() === true, '모르는 모양도 재계산으로 본다');
$ok(($D.'patched')($GLOBALS['__tier_file']) === '', '숫자를 못 찾으면 사본을 만들지 않는다');
$ok(($D.'killing')() === false, '끌 수 없으면 할인을 그대로 둔다');
$ok(($F.'discount_for')(100000.0) === 10000, '그때는 안내 문구도 그대로다');

// 필터로 되살릴 수 있다
dhr_set_tier_recalc(true);
add_filter('duckhoo_kill_auto_discount', fn() => false);
$ok(($D.'killing')() === false && ($F.'discount_for')(100000.0) === 10000, '필터로 도로 켤 수 있다');
$GLOBALS['__filters']['duckhoo_kill_auto_discount'] = [];
$GLOBALS['__filters']['duckhoo_auto_discount_mode'] = [];
unset($_COOKIE['dhr_disc_off']);
add_filter('duckhoo_auto_discount_mode', fn() => 'off');

// 자동 할인을 붙이는 함수를 알아보는 법 — 줄 번호가 아니라 코드로 본다
$dhrHit = function() {
    // 금액 자동 할인
    return 1;
};
$dhrMiss = function() {
    return 2;
};
$ok(($D.'source_has')($dhrHit, '금액 자동 할인') === true, '그 함수의 코드로 알아본다');
$ok(($D.'source_has')($dhrMiss, '금액 자동 할인') === false, '다른 익명 함수는 건드리지 않는다');
$ok(($D.'source_has')('wd_apply_flat_shipping_fee', '금액 자동 할인') === false, '이름 있는 함수(배송비 등)는 대상이 아니다');
$GLOBALS['__cart']->items = []; $GLOBALS['__cart']->fees = [];

// 41-d. 나눠 사도 합쳐서 센다 — 5병씩 계속 살 수 없다
$GLOBALS['__cart']->items = [];
$mkorder = function(int $n, string $st = 'on-hold') use ($plain) {
  $o = new DhrFakeOrder(rand(1, 99999), [], [], [], $st);
  $o->lines = [new DhrFakeLine($plain, $n)];
  return $o;
};
// tally_orders 는 한 요청 안에서 회원별로 한 번만 조회한다 — 경우마다 다른 회원으로 잰다.
$uid = 200;
$rest = function(array $orders) use (&$uid, $N) {
  ++$uid; $GLOBALS['__logged_in'] = $uid; $GLOBALS['__orders'] = $orders;
  return ($N.'left')('plain');
};
$ok($rest([$mkorder(5)]) === 5, '오늘 5병 주문했으면 5병 남는다');
$ok($rest([$mkorder(5), $mkorder(5)]) === 0, '5병씩 두 번이면 그날치가 끝난다');
$ok($rest([$mkorder(3), $mkorder(3), $mkorder(3)]) === 1, '3병씩 세 번이면 1병만 남는다');
$GLOBALS['__notices'] = [];
$ok(($N.'validate_add')(true, 238, 3) === false, '1병 남았는데 3병은 못 담는다');
$ok(($N.'validate_add')(true, 238, 1) === true, '1병은 담긴다');
// 취소한 주문은 자리를 돌려준다
$ok($rest([$mkorder(5), $mkorder(5, 'cancelled')]) === 5, '취소한 주문은 한도에서 빠진다');
// 묶음 주문 한 건도 그날치를 다 쓴다
$bo = new DhrFakeOrder(555, [], [], [], 'on-hold'); $bo->lines = [new DhrFakeLine($bundle, 1)];
$ok($rest([$bo]) === 0, '10+1 세트를 주문했으면 그날치가 끝난다');
$GLOBALS['__logged_in'] = 0; $GLOBALS['__orders'] = [];

// 41-e. 같은 사람이 계정을 여러 개 만드는 것 (includes/signup.php)
$G = 'Duckhoo\\Redesign\\Signup\\';
$ok(($G.'normalize')('010-1234-5678') === '01012345678', '하이픈을 지운다');
$ok(($G.'normalize')('010 1234 5678') === '01012345678', '공백을 지운다');
$ok(($G.'normalize')('+82 10-1234-5678') === '01012345678', '국가번호 82 는 0 으로 되돌린다');
$ok(($G.'normalize')('없음') === '' && ($G.'normalize')('123') === '', '번호로 볼 수 없으면 빈 값');

$GLOBALS['__phone_users']['01012345678'] = [11, 12];
$GLOBALS['__usermeta'][11] = []; $GLOBALS['__usermeta'][12] = [];
$ok(($G.'users_with_phone')('010-1234-5678') === [11, 12], '같은 번호를 쓰는 계정을 모두 찾는다');
$ok(($G.'users_with_phone')('010-9999-0000') === [], '안 쓰이는 번호는 빈 목록');

// 탈퇴한 계정은 세지 않는다 — 다시 가입할 수 있어야 한다
$GLOBALS['__phone_users']['01055556666'] = [21];
$GLOBALS['__usermeta'][21] = ['_duckhoo_withdrawn_at' => '2026-09-01'];
$ok(($G.'users_with_phone')('01055556666') === [], '탈퇴한 계정의 번호는 다시 쓸 수 있다');

// 노보 한도는 같은 번호의 계정을 한 사람으로 묶어 센다
$GLOBALS['__phone_users']['01077778888'] = [31, 32];
$GLOBALS['__usermeta'][31] = ['billing_phone' => '010-7777-8888'];
$GLOBALS['__usermeta'][32] = ['billing_phone' => '010-7777-8888'];
$ok(($G.'phone_of')(31) === '01077778888', '회원의 번호를 읽는다');
$o31 = new DhrFakeOrder(7001, [], [], [], 'on-hold'); $o31->lines = [new DhrFakeLine($plain, 6)];
$GLOBALS['__orders'] = [$o31];       // 다른 계정(32)이 오늘 6병을 샀다
$GLOBALS['__logged_in'] = 31;
$GLOBALS['__cart']->items = [];
$ok(($N.'left')('plain', 31) === 4, '다른 계정으로 산 것도 같은 사람으로 세어 4병만 남는다');
$GLOBALS['__logged_in'] = 0; $GLOBALS['__orders'] = [];

// 41-f. 수량은 장바구니가 아니라 옵션 JSON 에 있다 (테마 wd-option-builder)
$J = fn(int $q, string $type='required') => json_encode([['group_key'=>'required_main','group_label'=>'기본 상품','label'=>'노보','qty'=>$q,'unit_price'=>13500,'type'=>$type]], JSON_UNESCAPED_UNICODE);
$ok(($N.'units_from_json')($J(12)) === 12, '옵션 JSON 의 required 수량을 읽는다');
$ok(($N.'units_from_json')($J(3,'addon')) === 1, 'addon 줄은 기본 단위가 아니다');
$ok(($N.'units_from_json')('') === 1 && ($N.'units_from_json')('그냥 글자') === 1, 'JSON 이 없으면 1');
$ok(($N.'units_in')(['data'=>$plain,'quantity'=>1,'wd_option_builder_json'=>$J(12)]) === 12, '장바구니 줄에서 찾아 읽는다');
// 담긴 뒤에는 이미 풀어 놓은 배열로 들어 있다 — 문자열만 찾으면 10병이 1병이 된다
$rows = [['group_key'=>'required_main','label'=>'노보','qty'=>10,'type'=>'required']];
$ok(($N.'units_in')(['data'=>$plain,'quantity'=>1,'wd_options'=>$rows]) === 10, '배열로 들어 있어도 읽는다');
$ok(($N.'units_in')(['data'=>$plain,'quantity'=>1,'x'=>['y'=>$rows]]) === 10, '한 겹 더 들어 있어도 읽는다');
// 옵션을 아예 못 읽어도 값으로 되짚는다 (수량 1 · 135,000원 = 13,500 × 10)
$GLOBALS['__products'][249] = new WC_Product(249, '[노보 블랙] 블랙멘솔 (9.8mg / 30ml)', 13500);
$inCart = new WC_Product(249, '[노보 블랙] 블랙멘솔 (9.8mg / 30ml)', 135000);
$ok(($N.'units_of_item')(['data'=>$inCart,'product_id'=>249,'quantity'=>1]) === 10, '옵션을 못 읽으면 값의 비율로 센다');
$ok(($N.'units_of_item')(['data'=>$GLOBALS['__products'][249],'product_id'=>249,'quantity'=>1]) === 1, '평범한 한 병은 1');
// 워드프레스는 $_POST 의 값에 역슬래시를 붙인다 — 그대로 해독하면 실패한다
$ok(($N.'units_from_json')(addslashes($J(12))) === 12, '역슬래시가 붙어 와도 읽는다');

// 장바구니 수량이 1 이어도 옵션이 12 면 12병으로 센다
$GLOBALS['__orders'] = []; $GLOBALS['__logged_in'] = 0;
$GLOBALS['__cart']->items = ['a' => ['data' => $plain, 'quantity' => 1, 'wd_option_builder_json' => $J(12)]];
$ok(($N.'tally_cart')()['plain'] === 12, '수량 1 + 옵션 12 → 12병');
$ok(($N.'left')('plain') === 0, '한도를 이미 넘었다');
$threw = false; try { ($N.'guard_order')(); } catch (\Throwable $e) { $threw = true; }
$ok($threw, '옵션으로 12병을 담아도 주문은 만들어지지 않는다');

// 담기 요청에 실려 온 옵션도 본다
$GLOBALS['__cart']->items = [];
$_POST = ['wd_option_builder_json' => $J(12)];
$GLOBALS['__notices'] = [];
$ok(($N.'validate_add')(true, 238, 1) === false, '수량 1 로 와도 옵션이 12 면 담기가 막힌다');
$_POST = ['wd_option_builder_json' => $J(9)];
$ok(($N.'validate_add')(true, 238, 1) === true, '옵션 9 는 담긴다');
$_POST = [];

// 묶음은 required 가 세트 수다 — 10+1 두 세트면 20병
$GLOBALS['__cart']->items = ['a' => ['data' => $bundle, 'quantity' => 1, 'wd_option_builder_json' => $J(2)]];
$ok(($N.'tally_cart')()['plain'] === 20, '10+1 두 세트는 20병으로 센다');
$GLOBALS['__cart']->items = [];

// 주문 줄의 메타에서도 읽는다
$oi = new DhrFakeLine($plain, 1);
$ok(($N.'units_in_order_item')($oi) === 1, '메타가 없으면 1');

// 41-g. 기본값 — 낱병은 제한하지 않는다 (사장님 결정)
$capOn();
$GLOBALS['__cart']->items = []; $GLOBALS['__orders'] = []; $GLOBALS['__logged_in'] = 0;
$ok(($N.'limited')($plain) === false && ($N.'limited')($black) === false, '낱병은 한도가 걸리지 않는다');
$ok(($N.'limited')($bundle) === true && ($N.'limited')($ten) === true, '묶음은 한도가 걸린다');
$ok(($N.'paid')($plain) === 0 && ($N.'paid')($bundle) === 10, '낱병은 세지 않고 묶음만 센다');
$GLOBALS['__notices'] = [];
$ok(($N.'validate_add')(true, 238, 99) === true, '낱병은 99병도 담긴다');
$GLOBALS['__cart']->items = ['a' => ['data' => $plain, 'quantity' => 50]];
$ok(($N.'validate_add')(true, 600, 1) === true, '낱병을 아무리 담아도 묶음 한 세트는 담긴다');
$GLOBALS['__cart']->items = ['a' => ['data' => $bundle, 'quantity' => 1]];
$ok(($N.'validate_add')(true, 600, 1) === false, '묶음 두 세트는 여전히 막힌다');
$ok(($N.'validate_add')(true, 238, 20) === true, '묶음을 담은 뒤에도 낱병은 담긴다');
$GLOBALS['__cart']->items = [];
$ok(str_contains(apply_filters('duckhoo_card_extra', '', $lowst), '하루') === false, '낱병 카드에는 한도 문구가 없다');
$ok(str_contains(($N.'rule')(), '낱병은 제한이 없습니다'), '안내가 낱병은 제한이 없다고 말한다');

// 거절 안내는 합계만 말하지 않는다 — 오늘 주문한 것과 장바구니를 나눠 말하고 할 일로 끝낸다
$T = $N.'over_text';
$m = $T(10, 11);            // 오늘 한 세트 주문 + 장바구니에 한 세트
$ok(str_contains($m,'오늘 이미 한 세트를 주문하셨어요'), '오늘 주문한 몫을 세트로 말한다');
$ok(str_contains($m,'장바구니에서 노보 묶음을 빼시면'), '장바구니 몫은 지금 빼면 된다고 말한다');
$ok(str_contains($m,'내일 다시 주문해 주세요'), '언제 다시 살 수 있는지 말한다');
$ok(!str_contains($m,'20병') && !str_contains($m,'수량을 줄여 주세요'), '합계 병 수로 말하지 않는다');
$m = $T(10, 0);
$ok(str_contains($m,'오늘 이미 한 세트를 주문하셨어요') && !str_contains($m,'장바구니'), '장바구니가 비었으면 그 말은 하지 않는다');
$m = $T(0, 20);
$ok(str_contains($m,'장바구니에 두 세트가 담겨 있어요') && str_contains($m,'한 세트만 남기고'), '장바구니에만 있으면 빼라고 말한다');
$m = $T(0, 0, 20);
$ok(str_contains($m,'한 번에 두 세트는 담을 수 없어요'), '한 번에 두 세트를 담으려 하면 그렇게 말한다');
$m = $T(0, 10, 10);         // 장바구니에 한 세트, 또 담으려는 중
$ok(str_contains($m,'장바구니에 이미 한 세트가 있어서'), '담는 중에는 「담겨 있다」고 말하지 않는다');
$ok(!str_contains($m,'두 세트가 담겨 있어요'), '아직 안 담긴 것을 담겼다고 세지 않는다');
$ok(str_contains($T(10, 11),'낱병은 제한 없습니다'), '낱병은 살 수 있다고 알려 준다');
$ok(($N.'nword')(1) === '한' && ($N.'nword')(2) === '두' && ($N.'nword')(9) === '9', '1~5 는 한글 수관형사로 쓴다');
$ok(($N.'sets_of')(10) === 1 && ($N.'sets_of')(20) === 2, '병 수를 세트 수로 센다');

// 홈 공지 띠 — 2026-09-21 노보 재고 안내. 끝난 할인 이야기는 더 이상 없어야 한다
$ok(str_contains(($F.'announce')(), '노보') && str_contains(($F.'announce')(), '재고 있음'), '공지 띠가 노보 재고를 말한다');
$ok(!str_contains(($F.'announce')(), '진행 중') && !str_contains(($F.'announce')(), '할인'), '끝난 할인 · 「진행 중」을 말하지 않는다');
$ok(mb_strlen(($F.'announce')()) <= 40, '폰에서 한 줄 — 40자 안');

// 상품 후기 — 상품의 댓글이 닫혀 있어도 리뷰는 열어 준다 (가져온 상품이 그렇다)
$R = 'Duckhoo\\Redesign\\Product\\';
$GLOBALS['__posttype'][7] = 'product';
$GLOBALS['__posttype'][8] = 'page';
$GLOBALS['__options']['woocommerce_enable_reviews'] = 'yes';

// 후기는 그 상품을 산 사람만 — 폼을 감추는 것만이 아니라 보내는 것도 막는다
$ok(($R.'verified_only')() === true, '기본으로 구매한 고객만 쓴다');
$ok(($R.'force_verified')('no') === 'yes', '워드커머스 설정값을 우리가 정한다');
$GLOBALS['__logged_in'] = 3;
$GLOBALS['__bought'][3][7] = true;
$ok(($R.'guard_review')(['comment_post_ID'=>7]) === ['comment_post_ID'=>7], '산 사람은 그대로 지나간다');
$GLOBALS['__bought'][3][7] = false;
$stopped = false; try { ($R.'guard_review')(['comment_post_ID'=>7]); } catch (\Throwable $e) { $stopped = str_contains($e->getMessage(),'구매하신 분만'); }
$ok($stopped, '안 산 사람은 보내도 막힌다');
$ok(($R.'guard_review')(['comment_post_ID'=>8]) === ['comment_post_ID'=>8], '상품이 아닌 글의 댓글은 건드리지 않는다');
add_filter('duckhoo_reviews_verified_only', fn() => false);
$ok(($R.'guard_review')(['comment_post_ID'=>7]) === ['comment_post_ID'=>7] && ($R.'force_verified')('no') === 'no', '필터로 끌 수 있다');
$GLOBALS['__filters']['duckhoo_reviews_verified_only'] = [];
$GLOBALS['__logged_in'] = 1;
$ok(($R.'reviews_on')() === true, '워드커머스 리뷰 설정을 읽는다');
$ok(($R.'open_reviews')(false, 7) === true, '댓글이 닫힌 상품도 리뷰는 연다');
$ok(($R.'open_reviews')(false, 8) === false, '상품이 아니면 열지 않는다');
$ok(($R.'open_reviews')(true, 8) === true, '이미 열려 있으면 그대로 둔다');
add_filter('duckhoo_force_product_reviews', fn() => false);
$ok(($R.'open_reviews')(false, 7) === false, '필터로 끌 수 있다');
$GLOBALS['__filters']['duckhoo_force_product_reviews'] = [];
$GLOBALS['__options']['woocommerce_enable_reviews'] = 'no';
$ok(($R.'reviews_on')() === false && ($R.'open_reviews')(false, 7) === false, '전체 설정이 꺼져 있으면 열지 않는다');
$GLOBALS['__options']['woocommerce_enable_reviews'] = 'yes';
$ok(!str_contains(($F.'announce')(), '그동안 이용해'), '가게가 문 닫는 것처럼 읽힐 말은 쓰지 않는다');
$ok(($F.'take_announce')() === true, '기본으로 우리가 띠를 맡는다');
$ok(in_array('dhr-ann', ($F.'announce_body_class')([]), true), '맡는 동안 몸통에 표시를 남긴다');
add_filter('duckhoo_announce', fn() => '  새 문구  ');
$ok(($F.'announce')() === '새 문구', '필터로 문구를 바꾼다 (앞뒤 공백은 턴다)');
$GLOBALS['__filters']['duckhoo_announce'] = [];
add_filter('duckhoo_announce', fn() => '');
$ok(($F.'announce')() === '', '빈 문자열이면 띠를 없앤다');
$GLOBALS['__filters']['duckhoo_announce'] = [];
add_filter('duckhoo_take_announce', fn() => false);
$ok(($F.'take_announce')() === false && ($F.'announce_body_class')([]) === [], '끄면 스니펫 글자를 그대로 둔다');
$GLOBALS['__filters']['duckhoo_take_announce'] = [];

// 41-h. 취소한 주문은 어떤 상태 이름이든 한도를 놓아 준다
$singlesOn();
$GLOBALS['__cart']->items = []; $GLOBALS['__logged_in'] = 300;
foreach (['cancelled','refunded','failed','keyple-cancel','trash'] as $st) {
  $o = new DhrFakeOrder(8000 + crc32($st) % 900, [], [], [], $st);
  $o->lines = [new DhrFakeLine($plain, 10)];
  $GLOBALS['__orders'] = [$o];
  $GLOBALS['__logged_in'] = ++$uid;
  $ok(($N.'left')('plain', $uid) === 10, "상태 '$st' 인 주문은 한도를 잡지 않는다");
}
// 질의가 걸러 주지 않아도 우리가 한 번 더 본다
$o = new DhrFakeOrder(8999, [], [], [], 'cancelled');
$o->lines = [new DhrFakeLine($plain, 10)];
$GLOBALS['__orders'] = [$o];
$GLOBALS['__logged_in'] = ++$uid;
$ok(($N.'left')('plain', $uid) === 10, '질의를 통과해 들어와도 상태를 다시 보고 뺀다');
$GLOBALS['__orders'] = []; $GLOBALS['__logged_in'] = 0;
$GLOBALS['__filters']['duckhoo_novo_event'] = [];

// 42. 이벤트 기준 가격 (도구 → 노보 이벤트)
require_once dirname(__DIR__, 2).'/includes/novo-admin.php';
$A = 'Duckhoo\\Redesign\\Novo\\Admin\\';
$ok(($A.'target_price')($plain) === 13000, '노보 일반 1병은 13,000원');
$ok(($A.'target_price')($black) === 13500, '노보 블랙 1병은 13,500원');
$ok(($A.'target_price')($bundle) === 120000, '노보 일반 10+1 은 120,000원');
$ok(($A.'target_price')($ten) === 0, '10병 묶음은 이벤트 대상이 아니다');
$ok(($A.'target_price')($other) === 0, '노보가 아니면 대상이 아니다');

// 43. 이벤트를 끄면 한도가 걸리지 않는다
$singlesOn();
add_filter('duckhoo_novo_event', function($c){ $c['on'] = false; return $c; });
$GLOBALS['__cart']->items = [['data' => $bundle, 'quantity' => 5]];
$ok(($N.'validate_add')(true, 238, 99) === true, '이벤트를 끄면 막지 않는다');
$ok(($N.'limiting')() === false, '이벤트가 꺼지면 한도도 꺼진다');
$GLOBALS['__filters']['duckhoo_novo_event'] = [];
$GLOBALS['__cart']->items = [];

// 44. 세트 구매 제한 해제 (사장님 2026-09-10) — 기본값이 「한도 없음」이다.
//     막는 쪽과 한도를 말하는 글자가 **함께** 사라져야 한다. 한쪽만 꺼지면
//     「하루 한 세트」라고 써 놓고 안 막거나, 안 써 놓고 막는다.
$GLOBALS['__filters']['duckhoo_novo_event'] = [];
$GLOBALS['__cart']->items = []; $GLOBALS['__orders'] = []; $GLOBALS['__notices'] = [];
$ok(($N.'on')() === true, '이벤트 자체는 그대로 켜져 있다');
$ok(($N.'limiting')() === false, '하루 구매 한도는 꺼져 있다');
$ok(($N.'limited')($bundle) === false && ($N.'limited')($plain) === false, '어느 상품도 한도 대상이 아니다');

$GLOBALS['__logged_in'] = ++$uid;
$ok(($N.'validate_add')(true, 600, 9) === true, '묶음을 아홉 세트 담아도 막지 않는다');
$GLOBALS['__cart']->items = [['data' => $bundle, 'quantity' => 9]];
$ok(($N.'validate_add')(true, 600, 9) === true, '장바구니에 이미 아홉 세트가 있어도 더 담긴다');
$ok(($N.'over_messages')() === [], '거절할 말이 없다');
($N.'check_cart_page')();
$ok(count($GLOBALS['__notices']) === 0, '장바구니 화면에 아무 안내도 안 뜬다');
$ok(($N.'store_max')(null, $bundle, ['key' => 'k']) === null, 'Store API 상한을 낮추지 않는다');
($N.'guard_order')();  // 던지면 여기서 죽는다
$ok(true, '주문 만들기도 막지 않는다');

// 글자도 같이 내려간다
$ok(str_contains(apply_filters('duckhoo_card_extra', '', $bundle), '하루') === false, '카드에 「하루 한 세트」가 안 나온다');
$ok(apply_filters('duckhoo_js_config', [])['novo'] ?? null === null, '선택창에 상한을 넘기지 않는다');
ob_start(); ($N.'product_notice')($bundle); $pn = ob_get_clean();
$ok('' === trim($pn), '재고 숫자가 없으면 상세에 아무 상자도 안 그린다');
$GLOBALS['__options']['duckhoo_novo_show_stock'] = '0';
ob_start(); ($N.'product_notice')($lowst); $pn = ob_get_clean();   // 재고 관리가 켜진 상품 — 옵션이 꺼져 있으면
$ok('' === trim($pn), '남은 수량 표시가 꺼져 있으면 재고 숫자가 있어도 상세에 안 그린다');
$GLOBALS['__options']['duckhoo_novo_show_stock'] = '1';
ob_start(); ($N.'product_notice')($lowst); $pn = ob_get_clean();
$ok(!str_contains($pn, '제한') && !str_contains($pn, '하루 한 세트'), '상세 안내가 제한을 말하지 않는다');
$ok(str_contains($pn, '남은 재고'), '옵션을 켜면 남은 재고를 말할 자리는 남는다');
$GLOBALS['__options']['duckhoo_novo_show_stock'] = '0';
// 한도가 꺼진 노보 분류에는 「재고 있음」 글자판이 선다 (2026-09-21) — 이미지 배너(1인 1세트)는 안 쓴다
$GLOBALS['__is_tax'] = true; $GLOBALS['__options']['duckhoo_novo_banner_img'] = 'https://x/y.png';
ob_start(); ($N.'banner')(); $bn = ob_get_clean();
$ok(str_contains($bn, 'class="nvs"') && str_contains($bn, '재고 있습니다') && str_contains($bn, '가격 인상 안내') && !str_contains($bn, '<img') && !str_contains($bn, '1세트') && !str_contains($bn, '제한'), '한도가 꺼져 있으면 「전 라인 재고 있음」 글자판 — 이미지 · 제한 문구 없음');
add_filter('duckhoo_novo_stock_banner', fn($v = null) => []);
ob_start(); ($N.'banner')(); $bn = ob_get_clean();
$ok('' === trim($bn), '빈 배열을 돌려주면 배너를 안 그린다');
$GLOBALS['__filters']['duckhoo_novo_stock_banner'] = []; $GLOBALS['__is_tax'] = false; unset($GLOBALS['__options']['duckhoo_novo_banner_img']);
$GLOBALS['__cart']->items = []; $GLOBALS['__orders'] = []; $GLOBALS['__logged_in'] = 0;


/* ── 사진 후기 적립 (includes/review-photos.php) ────────────────────────── */
$V  = 'Duckhoo\\Redesign\\ReviewPhotos\\';
$PT = 'Duckhoo\\Redesign\\Points\\';
$R2 = 'Duckhoo\\Redesign\\Product\\';
$GLOBALS['__keyple_on'] = true;
$GLOBALS['__ledger'] = [];
$GLOBALS['__titles'][7] = '[노보] 데저트';

$ok(($V.'reward')() === 1000, '사진 후기 적립금은 1,000원');
$ok(($V.'max_photos')() === 3, '한 후기에 사진 3장까지');

// 폼이 파일을 보낼 수 있어야 한다 — enctype 이 없으면 사진이 조용히 사라진다
$form = '<form action="/x" method="post" id="commentform" class="comment-form">…</form>';
$ok(str_contains(($R2.'with_uploads')($form), 'enctype="multipart/form-data"'), '후기 폼에 enctype 을 채운다');
$ok(substr_count(($R2.'with_uploads')($form), 'enctype') === 1, '한 번만 채운다');
$done = str_replace('id="commentform"', 'id="commentform" enctype="multipart/form-data"', $form);
$ok(($R2.'with_uploads')($done) === $done, '이미 있으면 그대로 둔다');

$ok(str_contains(($V.'form_field')('<textarea></textarea>'), '1,000원'), '폼이 적립금 액수를 말한다');
$ok(str_contains(($V.'form_field')(''), 'name="dhr_review_photos[]"'), '파일칸이 붙는다');

$mk = function(array $a) { $GLOBALS['__comments'][(int)$a['comment_ID']] = new WP_Comment($a); };
$GLOBALS['__usermeta'][900] = ['_keyple_points' => '0'];

$mk(['comment_ID'=>11,'comment_post_ID'=>7,'user_id'=>900,'comment_approved'=>'0']);
$GLOBALS['__cmeta'][11]['_dhr_photos'] = [5];
$ok(($V.'maybe_pay')(11) === 0, '승인 전에는 주지 않는다');

$mk(['comment_ID'=>12,'comment_post_ID'=>7,'user_id'=>900,'comment_approved'=>'1']);
$ok(($V.'maybe_pay')(12) === 0, '사진이 없으면 주지 않는다');

$mk(['comment_ID'=>13,'comment_post_ID'=>7,'user_id'=>0,'comment_approved'=>'1']);
$GLOBALS['__cmeta'][13]['_dhr_photos'] = [5];
$ok(($V.'maybe_pay')(13) === 0, '비회원에게는 주지 않는다');

$GLOBALS['__comments'] = [];
$mk(['comment_ID'=>14,'comment_post_ID'=>7,'user_id'=>900,'comment_approved'=>'1']);
$GLOBALS['__cmeta'][14]['_dhr_photos'] = [5,6];
$ok(($V.'maybe_pay')(14) === 1000, '승인된 사진 후기에 1,000원을 준다');
$ok((int)$GLOBALS['__usermeta'][900]['_keyple_points'] === 1000, '잔액이 늘었다');
$ok(count($GLOBALS['__ledger']) === 1 && str_contains((string)($GLOBALS['__ledger'][0][2] ?? ''), '사진 후기 적립'), '원장에도 한 줄 남는다');
$ok(($V.'maybe_pay')(14) === 0, '같은 후기에 두 번 주지 않는다');

$mk(['comment_ID'=>15,'comment_post_ID'=>7,'user_id'=>900,'comment_approved'=>'1']);
$GLOBALS['__cmeta'][15]['_dhr_photos'] = [7];
$ok(($V.'maybe_pay')(15) === 0, '같은 상품에서는 한 번만 준다');

$ok(($V.'take_back')(14) === 1000, '승인을 내리면 도로 가져간다');
$ok((int)$GLOBALS['__usermeta'][900]['_keyple_points'] === 0, '잔액이 제자리로');
$ok(($V.'take_back')(14) === 0, '두 번 가져가지 않는다');
$ok(($V.'maybe_pay')(15) === 1000, '가져간 뒤에는 다른 후기에 줄 수 있다');

$GLOBALS['__usermeta'][901] = ['_keyple_points' => '300'];
$ok(($PT.'grant')(901, -1000, '시험') === true && (int)$GLOBALS['__usermeta'][901]['_keyple_points'] === 0, '잔액은 0 아래로 내려가지 않는다');

$ok(($V.'is_photo')(['tmp_name'=>'','error'=>0,'size'=>10]) === false, '파일이 없으면 받지 않는다');
$ok(($V.'is_photo')(['tmp_name'=>'/x','error'=>0,'size'=>99*1024*1024]) === false, '너무 크면 받지 않는다');
$ok(count(($V.'spread')(['name'=>['a.jpg','b.jpg'],'type'=>['image/jpeg','image/jpeg'],'tmp_name'=>['/a','/b'],'error'=>[0,0],'size'=>[1,2]])) === 2, '여러 장을 한 장씩 편다');

// 이 가게의 주문 상태는 워드커머스가 「샀다」고 보는 목록에 하나도 없다
class DhrBoughtItem { public function __construct(public int $p=0){} public function get_product_id(){ return $this->p; } public function get_variation_id(){ return 0; } }
class DhrBoughtOrder { public $status=''; public array $items=[];
  public function __construct(string $st, array $pids){ $this->status=$st; $this->items=array_map(fn($p)=>new DhrBoughtItem($p), $pids); }
  public function get_items(){ return $this->items; } }
$ok(in_array('delivered', ($R2.'bought_statuses')(), true), '배송완료를 「샀다」로 센다');
$ok(!in_array('on-hold', ($R2.'bought_statuses')(), true), '입금전은 아직 산 것이 아니다');
$GLOBALS['__orders'] = [ new DhrBoughtOrder('delivered', [7, 9]) ];
$ok(($R2.'bought')(null, '', 900, 7) === true, '배송완료 주문에 든 상품은 살 수 있다고 본다');
$ok(($R2.'bought')(null, '', 900, 8) === false, '안 산 상품은 아니라고 한다');
$ok(($R2.'bought')(null, '', 0, 7) === false, '비회원은 확인할 길이 없다');
$ok(($R2.'bought')(true, '', 0, 7) === true, '이미 판정이 있으면 그대로 둔다');
$GLOBALS['__orders'] = [ new DhrBoughtOrder('on-hold', [21]) ];
$ok(($R2.'bought')(null, '', 901, 21) === false, '입금전 주문만 있으면 아직 아니다');
$GLOBALS['__orders'] = [];

// 사진이 붙은 후기는 사람이 볼 때까지 세워 둔다 — 안 그러면 아무도 안 본 채 돈이 나간다
$_FILES['dhr_review_photos'] = ['name'=>['a.jpg'],'type'=>['image/jpeg'],'tmp_name'=>['/a'],'error'=>[0],'size'=>[10]];
$ok(($V.'hold_for_review')(1, ['comment_type'=>'review']) === 0, '사진 후기는 검토 대기로 잡는다');
$ok(($V.'hold_for_review')(1, ['comment_type'=>'comment']) === 1, '상품 후기가 아니면 건드리지 않는다');
$ok(($V.'hold_for_review')('spam', ['comment_type'=>'review']) === 'spam', '스팸 판정은 그대로 둔다');
$_FILES['dhr_review_photos'] = ['name'=>[''],'type'=>[''],'tmp_name'=>[''],'error'=>[4],'size'=>[0]];
$ok(($V.'hold_for_review')(1, ['comment_type'=>'review']) === 1, '사진이 없으면 그냥 지나간다');
unset($_FILES['dhr_review_photos']);

add_filter('duckhoo_photo_review_on', fn() => false);
$ok(($V.'maybe_pay')(15) === 0 && ($V.'form_field')('X') === 'X', '필터로 끄면 아무것도 하지 않는다');
$GLOBALS['__filters']['duckhoo_photo_review_on'] = [];


// ── 매출 대시보드 ────────────────────────────────────────────────────────
// 워드커머스 기본 분석은 processing·completed 만 매출로 센다. 이 가게 주문은
// 그 목록에 하나도 없어서 배송완료 천 건이 있어도 0 으로 보인다 — 그래서 우리가 센다.
require_once dirname(__DIR__, 2).'/includes/sales.php';
$S = 'Duckhoo\\Redesign\\Sales\\';

$paid = ($S.'confirmed_statuses')();
$wait = ($S.'pending_statuses')();
$void = ($S.'void_statuses')();
$ok(in_array('delivered',$paid,true) && in_array('payment-confirmed',$paid,true), '배송완료·입금확인은 확정 매출로 센다');
$ok(!in_array('on-hold',$paid,true) && in_array('on-hold',$wait,true), '입금전은 매출이 아니라 입금 대기다');
$ok(in_array('cancelled',$void,true) && !array_intersect($void,$paid), '취소·환불은 어디에도 안 센다');

// 지난달 같은 기간 — `-1 month` 를 그냥 쓰면 3월 31일이 3월 3일이 된다
$ok(($S.'last_month_same')('2026-03-31') === ['2026-02-01','2026-02-28'], '달 끝을 넘지 않는다 (3/31 → 2/28)');
$ok(($S.'last_month_same')('2026-09-09') === ['2026-08-01','2026-08-09'], '9월 9일 → 8월 1~9일');
$ok(($S.'last_month_same')('2026-01-15') === ['2025-12-01','2025-12-15'], '해를 넘어도 맞는다');

$rows = [
  ['id'=>1,'d'=>'2026-09-01','ts'=>0,'s'=>'delivered','t'=>10000.0,'c'=>7,'p'=>1000.0,'coup'=>500.0,'ship'=>2500.0,'fee'=>0.0],
  ['id'=>2,'d'=>'2026-09-02','ts'=>0,'s'=>'on-hold',  't'=>20000.0,'c'=>7,'p'=>0.0,   'coup'=>0.0,  'ship'=>0.0,   'fee'=>0.0],
  ['id'=>3,'d'=>'2026-09-02','ts'=>0,'s'=>'delivered','t'=>30000.0,'c'=>8,'p'=>0.0,   'coup'=>0.0,  'ship'=>0.0,   'fee'=>10000.0],
  ['id'=>4,'d'=>'2026-08-30','ts'=>0,'s'=>'cancelled','t'=>90000.0,'c'=>8,'p'=>0.0,   'coup'=>0.0,  'ship'=>0.0,   'fee'=>0.0],
];
$sep = ($S.'pick')($rows, '2026-09-01', '2026-09-02', $paid);
$ok(count($sep)===2, '기간과 상태로 함께 거른다');
$ok(($S.'agg')($sep)['sales'] === 40000.0, '입금전 2만원은 확정 매출에 안 들어간다');
$ok(($S.'agg')(($S.'pick')($rows,'2026-09-02','2026-09-02',$wait))['sales'] === 20000.0, '입금 대기는 따로 셈이 된다');
$a = ($S.'agg')($rows);
$ok($a['points']===1000.0 && $a['coupon']===500.0 && $a['fee']===10000.0 && $a['ship']===2500.0, '적립금·쿠폰·자동할인·배송비를 따로 더한다');

// 재구매 — 취소된 주문은 손님의 이력으로 세지 않는다
$c = ($S.'customers')($rows);
$ok($c['first'][7] === '2026-09-01' && $c['count'][7] === 2, '손님 7 은 두 번 샀고 첫 주문이 9/1 이다');
$ok($c['first'][8] === '2026-09-02' && $c['count'][8] === 1, '취소된 8/30 주문은 첫 주문으로 안 센다');

$ok(($S.'signups_between')(['2026-09-01'=>3,'2026-09-05'=>2], '2026-09-01','2026-09-02') === 3, '기간 안의 가입만 더한다');
$ok(($S.'delta')(120.0, 100.0) === '+20%' && ($S.'delta')(80.0, 100.0) === '−20%', '지난 기간 대비를 쓴다');
$ok(($S.'delta')(50.0, 0.0) === '', '기준이 0 이면 비율을 만들지 않는다');
$ok(($S.'won')(1234567.4) === '1,234,567원', '원 단위로 적는다');

// 주문 하나를 줄이는 길 — 상품 줄은 열지 않는다
$o = new DhrSalesOrder(11, '2026-09-02', 'delivered', 50000.0, 7, ['_wd_point_discount'=>8800, '_wd_point_discount_applied'=>1], 1000.0, 2500.0);
$r = ($S.'row')($o);
$ok($r['d']==='2026-09-02' && $r['s']==='delivered' && $r['t']===50000.0, '날짜·상태·금액을 읽는다');
$ok($r['p']===8800.0, '적립금 사용액은 확인된 메타에서 읽는다');
$ok($r['coup']===1000.0 && $r['ship']===2500.0, '쿠폰 할인과 배송비도 담아 둔다');
$ok(($S.'row')(null) === null, '주문이 아니면 아무것도 만들지 않는다');

// 수수료 줄은 주문마다 열지 않고 한 번의 질의로 몰아 읽는다
$GLOBALS['__fee_rows'] = [
  ['oid'=>21,'name'=>'🎁 금액 자동 할인','amt'=>'-10000'],
  ['oid'=>21,'name'=>'적립금 할인',      'amt'=>'-3000'],
  ['oid'=>22,'name'=>'포장비',           'amt'=>'2000'],
  ['oid'=>21,'name'=>'배송비',           'amt'=>'2500'],
];
$fm = ($S.'fee_map')([21,22]);
$ok(($fm[21]['fee'] ?? 0) === 10000.0, '자동 할인은 나가는 돈으로 센다');
$ok(($fm[21]['points'] ?? 0) === 3000.0, '적립금 줄은 적립금으로 가른다');
$ok(!isset($fm[22]), '양수 수수료는 할인이 아니므로 세지 않는다');
$ok(($fm[21]['ship'] ?? 0) === 2500.0 && ($fm[21]['fee'] ?? 0) === 10000.0, '「배송비」 수수료 줄(양수)은 배송비로 센다 — 할인에 섞이지 않는다');

$GLOBALS['__orders'] = [
  new DhrSalesOrder(21, '2026-09-02', 'delivered', 90000.0, 7),
  new DhrSalesOrder(23, '2026-09-02', 'delivered', 90000.0, 7, ['_wd_point_discount'=>5000]),
];
$GLOBALS['__fee_rows'][] = ['oid'=>23,'name'=>'적립금 할인','amt'=>'-9999'];
$got = [];
foreach (($S.'fetch')([]) as $r2) { $got[$r2['id']] = $r2; }
$ok($got[21]['p'] === 3000.0 && $got[21]['fee'] === 10000.0, '메타가 없으면 수수료 줄의 적립금을 쓴다');
$ok($got[21]['ship'] === 2500.0, '배송비 수수료 줄이 주문의 배송비로 들어간다 (get_shipping_total 은 0)');
$ok($got[23]['p'] === 5000.0, '메타가 있으면 그쪽이 맞다 — 수수료 줄로 덮지 않는다');
$GLOBALS['__orders'] = [];
$GLOBALS['__fee_rows'] = [];


// 기간 — 「지난달 같은 기간」이 최대 62일 뒤를 보므로 그 아래로는 못 내려간다
$ok(($S.'scan_days')() >= 70, '읽는 기간이 70일 아래로 내려가지 않는다');
$_GET['dhr_days'] = '400';
$ok(($S.'scan_days')() === 400, '화면에서 고른 기간을 쓴다');
$_GET['dhr_days'] = '9999';
$ok(($S.'scan_days')() === 90, '목록에 없는 값은 무시하고 기본으로 돌아간다');
unset($_GET['dhr_days']);
$ok(in_array(90, ($S.'day_choices')(), true), '기본 기간이 고를 수 있는 값 안에 있다');
$ok(($S.'budget')() > 0 && ($S.'budget')() <= 60, '읽는 시간에 상한이 있다');

// 시간이 넘으면 읽던 만큼으로 그린다 — 통째로 죽는 것보다 낫다
$GLOBALS['__orders'] = array_map(fn($i) => new DhrSalesOrder($i, '2026-09-02', 'delivered', 1000.0, 1), range(1, 200));
$part = false;
$got2 = ($S.'fetch')([], microtime(true) - 1, $part);
$ok($part === true && count($got2) === 100, '시간이 넘으면 첫 묶음(100건)까지만 읽고 「일부」라고 알린다');
$part = false;
($S.'fetch')([], microtime(true) + 60, $part);
$ok($part === false, '시간이 남으면 「일부」가 아니다');
$GLOBALS['__orders'] = [];

// 깨진 주문 하나가 묶음에 섞여 있어도 화면이 통째로 죽지 않는다 (2026-10-01, 1800건째에서 멈춤)
if (!class_exists('DhrBrokenOrder')) {
  class DhrBrokenOrder extends DhrSalesOrder { public function get_total(){ throw new TypeError('깨진 주문: 금액을 읽을 수 없음'); } }
}
$GLOBALS['dhr_sales_skipped'] = [];
$GLOBALS['__orders'] = [
  new DhrSalesOrder(301, '2026-09-02', 'delivered', 1000.0, 1),
  new DhrBrokenOrder(302, '2026-09-02', 'delivered', 1000.0, 1),
  new DhrSalesOrder(303, '2026-09-03', 'delivered', 2000.0, 1),
];
$part = false;
$got3 = ($S.'fetch')([], null, $part);
$ids3 = array_column($got3, 'id');
$ok($ids3 === [301, 303], '깨진 주문만 건너뛰고 나머지 둘은 읽는다 (묶음을 하나씩 다시 읽음)');
$sk3 = $GLOBALS['dhr_sales_skipped'] ?? [];
$ok(count($sk3) === 1 && (int)$sk3[0]['id'] === 302 && str_contains((string)$sk3[0]['msg'], 'TypeError') && str_contains((string)$sk3[0]['msg'], '금액을 읽을 수 없음'), '건너뛴 주문의 번호와 오류 메시지를 남긴다 — 화면이 그것을 안내로 찍는다');
$GLOBALS['__orders'] = [];
$GLOBALS['dhr_sales_skipped'] = [];

// 진짜 원인(2026-10-01 사장님 캡처): 환불 기록 #5153 · #5154 이 주문 목록에 섞여 get_customer_id() 가 없어 죽었다
if (!class_exists('DhrFakeRefund')) {
  class DhrFakeRefund { public function __construct(public int $id){} public function get_id(){ return $this->id; } public function get_type(){ return 'shop_order_refund'; }
    public function get_status(){ return 'completed'; } public function get_total(){ return -13500.0; } public function get_date_created(){ return null; } }
}
$GLOBALS['__orders'] = [ new DhrSalesOrder(401, '2026-09-30', 'delivered', 1000.0, 1), new DhrFakeRefund(5153), new DhrSalesOrder(402, '2026-09-30', 'delivered', 2000.0, 1) ];
$got4 = ($S.'fetch')([]);
$ok(array_column($got4, 'id') === [401, 402] && empty($GLOBALS['dhr_sales_skipped']), '환불 기록은 주문이 아니다 — 세지 않고 오류로도 적지 않는다');
$ok(($S.'row')(new DhrFakeRefund(5154)) === null, 'row() 가 환불 객체를 null 로 돌려준다');
$GLOBALS['__orders'] = [];


// ── 「19」 가림을 벽이 아니라 문으로 (구매 여정 목 1, 2026-09-10) ──────────
$F = 'Duckhoo\\Redesign\\Front\\';
$PR = 'Duckhoo\\Redesign\\Product\\';
$GLOBALS['__logged_in'] = 0;
$ok(($F.'gated')() === true, '비로그인은 사진이 가려진 손님이다');
$ok(str_contains(($F.'join_url')('https://duck-hoo.com/product/x/'), '/register/') && str_contains(($F.'join_url')('https://duck-hoo.com/product/x/'), 'redirect_to='), '가입 주소에 돌아올 곳이 붙는다');
$ok(!str_contains(($F.'join_url')(), 'redirect_to'), '돌아올 곳이 없으면 붙이지 않는다');
$ok(str_contains(($F.'login_url')('https://duck-hoo.com/p/'), 'redirect_to='), '로그인 주소에도 돌아올 곳이 붙는다');
$note = ($F.'gate_note')();
$ok(str_contains($note, '성인인증 회원') && str_contains($note, '/register/') && str_contains($note, '8,800'), '목록 한 줄이 왜 · 어떻게 · 얼마를 말한다');
$g = ($PR.'gate')('https://duck-hoo.com/product/x/');
$ok(str_contains($g, 'dhp-gate__btn') && str_contains($g, '가입하고 사진 보기'), '상세 안내판에 가입 버튼이 있다');
$ok(str_contains($g, 'dhp-gate__login') && str_contains($g, 'redirect_to='), '로그인 길도 있고 이 상품으로 돌아온다');
$ok(!str_contains($g, '깨진') && !str_contains($g, '<form'), '폼을 만들지 않는다 — 구매 게이트는 form.cart 만 읽는다');
$GLOBALS['__logged_in'] = 5;
$ok(($F.'gated')() === false && '' === ($F.'gate_note')() && '' === ($PR.'gate')(), '로그인하면 아무것도 그리지 않는다');
$GLOBALS['__logged_in'] = 0;
add_filter('duckhoo_photos_gated', '__return_false');
$ok(($F.'gated')() === false, '필터로 끌 수 있다');
$GLOBALS['__filters']['duckhoo_photos_gated'] = [];

// ── 구매 깔때기 (includes/funnel.php) ─────────────────────────────────────
require_once dirname(__DIR__, 2).'/includes/funnel.php';
$FN = 'Duckhoo\\Redesign\\Funnel\\';
$st = ($FN.'stages')();
$ok(array_keys($st)[0] === 'home' && end($st) && array_key_last($st) === 'order', '단계는 첫 화면에서 주문까지 순서대로다');
$ok($st['signup']['event'] && $st['order']['event'] && $st['cart_add']['event'] && !$st['product']['event'], '담기 · 가입 완료 · 주문은 서버 사건이다');
$GLOBALS['__sql'] = [];
$ok(($FN.'hit')('product', false) === true && str_contains($GLOBALS['__sql'][0], 'ON DUPLICATE KEY UPDATE'), '한 번의 질의로 더한다 — 동시에 와도 안 샌다');
$ok(in_array('guest', $GLOBALS['wpdb']->lastArgs, true), '비회원으로 센다');
$ok(($FN.'hit')('nope', false) === false, '모르는 단계는 세지 않는다');
$ok(($FN.'accept')('product', 'Mozilla/5.0 iPhone') === true, '화면 단계 신호는 받는다');
$ok(($FN.'accept')('order', 'Mozilla/5.0') === false, '사건 단계는 신호로 받지 않는다 — 서버가 센다');
$ok(($FN.'accept')('home', 'Googlebot/2.1') === false && ($FN.'accept')('home', '') === false, '봇과 빈 UA 는 거른다');
$GLOBALS['__sql'] = [];
$r = ($FN.'beacon')(new DhrReq(['s'=>'home','m'=>'1']));
$ok(($r['ok'] ?? false) === false || count($GLOBALS['__sql']) >= 0, '신호 처리는 UA 가 없으면 조용히 거절한다');
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android)';
$GLOBALS['__sql'] = [];
$r = ($FN.'beacon')(new DhrReq(['s'=>'home','m'=>'1']));
$ok(($r['ok'] ?? false) === true && in_array('member', $GLOBALS['wpdb']->lastArgs, true), '신호가 오면 회원으로 센다');
unset($_SERVER['HTTP_USER_AGENT']);
$GLOBALS['__funnel_rows'] = [['stage'=>'home','who'=>'guest','n'=>'100'],['stage'=>'product','who'=>'guest','n'=>'40'],['stage'=>'order','who'=>'member','n'=>'3'],['stage'=>'zzz','who'=>'guest','n'=>'9']];
$c = ($FN.'counts')(7);
$ok($c['home']['guest'] === 100 && $c['product']['guest'] === 40 && $c['order']['member'] === 3 && !isset($c['zzz']), '단계별 합계를 읽고 모르는 단계는 버린다');
$GLOBALS['__is_product'] = true;
$ok(($FN.'page_stage')() === 'product', '상품 상세는 product 단계다');
add_filter('duckhoo_funnel_on', '__return_false');
$cfg = ($FN.'js_config')([]);
$ok(!isset($cfg['stage']), '꺼져 있으면 신호 설정을 넘기지 않는다');
$GLOBALS['__filters']['duckhoo_funnel_on'] = [];
$GLOBALS['__is_product'] = true;
$cfg = ($FN.'js_config')([]);
$ok($cfg['stage'] === 'product' && str_contains($cfg['beacon'], 'duckhoo/v1/f'), '단계와 신호 주소를 화면에 넘긴다');
$GLOBALS['__is_product'] = false;
add_filter('duckhoo_funnel_on', '__return_false');
$ok(($FN.'hit')('home', false) === false, '필터로 끄면 세지 않는다');
$GLOBALS['__filters']['duckhoo_funnel_on'] = [];

// ── 가입 뒤 되돌림 (includes/back.php) ─────────────────────────────────────
require_once dirname(__DIR__, 2).'/includes/back.php';
$B = 'Duckhoo\\Redesign\\Back\\';
$ok(($B.'safe')('https://duck-hoo.com/product/x/') === 'https://duck-hoo.com/product/x/', '우리 사이트 주소는 받는다');
$ok(($B.'safe')('https://evil.example/x') === '' && ($B.'safe')('') === '', '바깥 주소는 버린다');
$_COOKIE['dhr_back'] = 'https://duck-hoo.com/product/x/';
$GLOBALS['__did'] = [];
$ok(($B.'after_signup')('https://duck-hoo.com/') === 'https://duck-hoo.com/', '가입이 끝난 요청이 아니면 끼어들지 않는다');
$GLOBALS['__did'] = ['user_register' => 1];
$ok(($B.'after_signup')('https://duck-hoo.com/') === 'https://duck-hoo.com/product/x/', '가입이 끝나면 보던 상품으로 보낸다');
$ok(!isset($_COOKIE['dhr_back']), '한 번 쓰고 지운다');
$_COOKIE['dhr_back'] = 'https://evil.example/x';
$ok(($B.'after_signup')('https://duck-hoo.com/') === 'https://duck-hoo.com/', '쿠키가 바깥 주소면 무시한다');
unset($_COOKIE['dhr_back']);
$_COOKIE['dhr_back'] = 'https://duck-hoo.com/product/y/';
$ok(($B.'after_login')('https://duck-hoo.com/inquiries/') === 'https://duck-hoo.com/inquiries/', '로그인 폼이 따로 정한 곳이 있으면 그쪽이 먼저다');
$ok(($B.'after_login')('https://example.test/my-account/') === 'https://duck-hoo.com/product/y/', '기본(내 계정)으로 가려 할 때만 보던 상품으로');
unset($_COOKIE['dhr_back']);
$GLOBALS['__did'] = [];


// ── 우리 상세가 빼먹은 것 다시 그리기 (includes/product.php) ───────────────
// 우리 템플릿은 `woocommerce_single_product_summary` 를 안 쏜다 → 거기 붙는 키플 쿠폰
// 박스가 통째로 안 그려졌다 (라이브 확인: `?dhr_raw=1` 이면 4개, 우리 템플릿은 0개).
function dhr_test_coupon_box(){ echo '[쿠폰박스]'; }
function dhr_test_other_box(){ echo '[남의것]'; }
$GLOBALS['wp_filter']['woocommerce_single_product_summary'] = new class {
	public $callbacks = [];
};
$GLOBALS['wp_filter']['woocommerce_single_product_summary']->callbacks = [
	10 => [ 'a' => ['function' => 'dhr_test_coupon_box'] ],
	20 => [ 'b' => ['function' => 'strlen'] ],          // 내부 함수 — 파일이 없으니 건너뛴다
];
// 이 테스트 파일의 경로에 들어 있는 조각을 「그 플러그인 폴더」로 삼는다.
$GLOBALS['__filters']['duckhoo_summary_extra_dirs'] = [fn($v) => ['php-tests']];
ob_start(); ('Duckhoo\\Redesign\\Product\\summary_extras')(); $extra = ob_get_clean();
$ok(str_contains($extra, '[쿠폰박스]'), '정해 둔 폴더의 콜백만 우리 상세에서 다시 그린다');
$ok(!str_contains($extra, '[남의것]'), '파일을 못 읽는 콜백(내부 함수)은 건너뛴다');
ob_start(); ('Duckhoo\\Redesign\\Product\\summary_extras')(); $again = ob_get_clean();
$ok('' === $again, '한 번만 그린다 (두 번 불러도 비어 있다)');
$GLOBALS['__filters']['duckhoo_summary_extra_dirs'] = [];
unset($GLOBALS['wp_filter']['woocommerce_single_product_summary']);

// ── 쿠폰 한 번에 만들기 (includes/coupon-admin.php) ────────────────────────
require_once dirname(__DIR__, 2).'/includes/coupon-admin.php';
$C = 'Duckhoo\\Redesign\\Coupon\\Admin\\';

$p1 = ($C.'parse_lines')("3000\n5,000원 홍길동\n10000, 9월 단골, hong@example.com\n2000 x 20\n\n# 주석은 건너뛴다\n안녕하세요");
$ok(count($p1['rows']) === 4, '빈 줄 · 주석은 건너뛰고 네 줄을 읽는다');
$ok($p1['rows'][0]['amount'] === 3000 && $p1['rows'][0]['count'] === 1, '숫자만 있는 줄');
$ok($p1['rows'][1]['amount'] === 5000 && $p1['rows'][1]['note'] === '홍길동', '자릿점 · 「원」 을 떼고 나머지는 메모');
$ok($p1['rows'][2]['amount'] === 10000 && $p1['rows'][2]['email'] === 'hong@example.com', '@ 가 든 낱말은 이메일');
$ok($p1['rows'][2]['note'] === '9월 단골', '이메일을 뺀 나머지가 메모');
$ok($p1['rows'][3]['amount'] === 2000 && $p1['rows'][3]['count'] === 20, 'x20 은 스무 장 (2000 을 금액으로, 20 을 장수로)');
$ok(count($p1['errors']) === 1, '금액이 없는 줄은 만들지 않고 알려 준다');
$ok(($C.'total')($p1['rows']) === 23, '만들 장수는 x 를 펼쳐서 센다');

$p2 = ($C.'parse_lines')("50\n2000000\n3000");
$ok(count($p2['rows']) === 1 && count($p2['errors']) === 2, '너무 적거나 많은 금액은 빼고 이유를 적는다');

$code = ($C.'make_code')('DH', 6);
$ok(strlen($code) === 8 && str_starts_with($code, 'DH'), '코드는 앞글자 + 정해진 글자 수');
$ok(!preg_match('/[OIL01]/', substr($code, 2)), '헷갈리는 글자(O · I · L · 0 · 1)를 쓰지 않는다');
$seen = [];
for ($i = 0; $i < 200; $i++) { $seen[($C.'make_code')('DH', 6)] = 1; }
$ok(count($seen) > 190, '200번 만들어도 거의 겹치지 않는다');

$ok(($C.'expiry_stored')('2026-09-30') === '2026-10-01', '만료일은 하루를 더해 저장한다 (그날 밤 12시까지 쓰게)');
$ok(($C.'expiry_stored')('') === '', '만료일이 없으면 빈 값 (만료 없음)');
$ok(($C.'expiry_stored')('아무거나') === '', '날짜가 아니면 빈 값');

$one = ['code'=>'DH3K7Q', 'amount'=>3000, 'note'=>'홍길동', 'expires'=>'2026-09-30'];
$txt = ($C.'sms')(($C.'default_sms')(), $one);
$ok(str_contains($txt, 'DH3K7Q') && str_contains($txt, '3,000') && str_contains($txt, '9월 30일'), '문자 문구에 코드 · 금액 · 만료가 들어간다');
$ok(($C.'kdate')('2026-09-30') === '9월 30일', '날짜는 문자에 쓰는 말로 적는다');
$ok(($C.'kdate')('') === '', '만료가 없으면 빈 값');
$ok(str_contains($txt, '홍길동님'), '메모가 있으면 이름으로 부른다');
$ok(!str_contains(($C.'sms')(($C.'default_sms')(), ['code'=>'A','amount'=>1000,'note'=>'','expires'=>'']), '님'), '메모가 없으면 「님」 이 남지 않는다');

/* 줄마다 코드 직접 정하기 — 무작위 여덟 글자는 불러 주기 불편하다 (사장님 2026-09-18) */
$cl = ($C.'parse_lines')("VIPGOLD: 3000 VIP(핵심우수) 64명\n3000 무작위로");
$ok($cl['rows'][0]['code'] === 'VIPGOLD', '줄 맨 앞의 「코드:」 를 읽는다');
$ok($cl['rows'][0]['amount'] === 3000 && $cl['rows'][0]['uses'] === 64, '코드를 떼어도 금액 · 인원은 그대로');
$ok($cl['rows'][0]['note'] === 'VIP(핵심우수)', '코드는 메모에 남지 않는다');
$ok($cl['rows'][1]['code'] === '', '안 적은 줄은 무작위');
$ok(($C.'parse_lines')("3000 두시 30분 모임")['rows'][0]['code'] === '', '금액이 먼저 오면 코드로 읽지 않는다');

$bad = ($C.'parse_lines')("NEW: 3000");
$ok(!$bad['rows'] && str_contains($bad['errors'][0] ?? '', '쓸 수 없습니다'), '너무 짧은 코드는 거절하고 이유를 적는다');
$x3 = ($C.'parse_lines')("VIPGOLD: 3000 x 3");
$ok(!$x3['rows'] && str_contains($x3['errors'][0] ?? '', '한 장만'), '코드를 정하면 x3 은 안 된다 — 같은 코드를 셋 만들 수 없다');

$GLOBALS['__coupons'] = [];
($C.'create')(($C.'parse_lines')("VIPGOLD: 3000 64명")['rows'], ['mode'=>'many']);
$ok(isset($GLOBALS['__coupons']['VIPGOLD']) && $GLOBALS['__coupons']['VIPGOLD']['usage_limit'] === 64, '적은 코드 그대로 · 상한도 그대로');
$dup = ($C.'create')(($C.'parse_lines')("VIPGOLD: 3000 10명\nGROWBACK: 6000 62명")['rows'], ['mode'=>'many']);
$ok(count($dup['made']) === 1 && $dup['made'][0]['code'] === 'GROWBACK', '겹치는 코드만 건너뛰고 나머지는 만든다');
$ok(str_contains(strip_tags(implode(' ', $dup['errors'])), 'VIPGOLD'), '건너뛴 코드를 이름으로 알려 준다');
$GLOBALS['__coupons'] = [];

/* 줄마다 「N명」 — 세그먼트마다 인원이 다르다 (사장님 2026-09-18) */
$seg = ($C.'parse_lines')("3000 VIP핵심우수 30명\n6000 성장고객 이탈위험 120명\n3000 신규");
$ok($seg['rows'][0]['uses'] === 30 && $seg['rows'][0]['amount'] === 3000, '줄 끝의 「30명」을 상한으로 읽는다');
$ok($seg['rows'][0]['note'] === 'VIP핵심우수', '상한은 메모에서 떼어 낸다 — 문자에 「30명」이 나가면 안 된다');
$ok($seg['rows'][1]['uses'] === 120 && $seg['rows'][1]['note'] === '성장고객 이탈위험', '띄어쓴 메모도 그대로');
$ok($seg['rows'][2]['uses'] === 0, '안 적은 줄은 0 — 설정의 값을 따른다');
$ok(($C.'parse_lines')("3000 가족 3명 모임")['rows'][0]['uses'] === 0, '가운데의 「3명」은 상한이 아니다 — 끝에서만 본다');

$GLOBALS['__coupons'] = [];
($C.'create')($seg['rows'], ['mode'=>'many','uses'=>50,'prefix'=>'SEG','len'=>6]);
$caps = [];
foreach ($GLOBALS['__coupons'] as $code => $d) { $caps[(int)$d['amount']][] = $d['usage_limit']; }
$ok(in_array(30, $caps[3000], true) && in_array(50, $caps[3000], true), '줄에 적은 30명 · 안 적은 줄은 설정값 50명');
$ok($caps[6000] === [120], '6,000원 줄은 120명');
foreach ($GLOBALS['__coupons'] as $d) { if ($d['usage_limit_per_user'] !== 1) $fail[] = '1인 1회가 아니다'; }
$ok(true, '여섯 장 모두 한 사람당 한 번');
$GLOBALS['__coupons'] = [];

/* 공용 코드 한 장 — 여럿에게 뿌리되 한 사람당 한 번 (사장님 2026-09-18) */
$ok(($C.'clean_fixed_code')('덕후 9월!') === '덕후9월', '빈칸 · 기호를 뗀다 — 손님이 띄어 적으면 못 찾는다');
$ok(($C.'clean_fixed_code')('openchat') === 'OPENCHAT', '영문은 대문자로 통일한다');
$ok(($C.'clean_fixed_code')('덕후') === '', '너무 짧은 코드는 쓰지 않는다');
$ok(($C.'clean_fixed_code')(str_repeat('A', 21)) === '', '너무 긴 코드도 쓰지 않는다');
$ok(($C.'clean_fixed_code')('') === '', '비워 두면 빈 값 — 무작위로 만든다');

$GLOBALS['__coupons'] = [];
$rows = ($C.'parse_lines')("5000 오픈채팅 단골")['rows'];
$r1 = ($C.'create')($rows, ['code'=>'덕후9월','mode'=>'many','uses'=>100,'expires'=>'2026-09-30','min'=>30000]);
$ok(count($r1['made']) === 1 && $r1['made'][0]['code'] === '덕후9월', '적어 넣은 코드 그대로 한 장을 만든다');
$made = $GLOBALS['__coupons']['덕후9월'];
$ok($made['usage_limit'] === 100, '총 사용 횟수는 적은 대로 (선착순 100명)');
$ok($made['usage_limit_per_user'] === 1, '**한 사람당 한 번** — 공용 코드의 핵심');
$ok($made['minimum_amount'] === '30000', '최소 주문금액이 걸린다');
$ok($made['date_expires'] === '2026-10-01', '만료일은 하루를 더해 저장 (그날 밤 12시까지)');
$ok($made['amount'] === '5000', '금액');

// 같은 코드를 또 만들지 않는다
$r2 = ($C.'create')($rows, ['code'=>'덕후9월','mode'=>'many','uses'=>100]);
$ok(!$r2['made'] && str_contains($r2['errors'][0] ?? '', '이미 있습니다'), '같은 코드가 이미 있으면 만들지 않고 말해 준다');

// 코드를 정했는데 금액 줄이 여럿이면 만들지 않는다 — 어느 금액이 나갈지 우리가 정하면 안 된다
$r3 = ($C.'create')(($C.'parse_lines')("3000\n5000")['rows'], ['code'=>'단골감사','mode'=>'many']);
$ok(!$r3['made'] && str_contains($r3['errors'][0] ?? '', '한 장만'), '코드를 정하면 금액 줄은 하나여야 한다');
$r4 = ($C.'create')(($C.'parse_lines')("3000 x 5")['rows'], ['code'=>'단골감사','mode'=>'many']);
$ok(!$r4['made'], 'x5 도 마찬가지 — 같은 코드를 다섯 장 만들 수는 없다');

// 제한 없음 · 한 장에 한 번
$GLOBALS['__coupons'] = [];
($C.'create')(($C.'parse_lines')("4000")['rows'], ['code'=>'무제한코드','mode'=>'many','uses'=>0]);
$ok($GLOBALS['__coupons']['무제한코드']['usage_limit'] === 0, '0 이면 총 횟수 제한 없음');
($C.'create')(($C.'parse_lines')("4000")['rows'], ['code'=>'한번만코드','mode'=>'once','uses'=>100]);
$ok($GLOBALS['__coupons']['한번만코드']['usage_limit'] === 1, '「한 장에 한 번만」은 총 횟수 칸을 무시하고 1');
$GLOBALS['__coupons'] = [];

// ── 분류 묶기 (includes/cat-admin.php) ────────────────────────────────────
require_once dirname(__DIR__, 2).'/includes/cat-admin.php';
$G = 'Duckhoo\\Redesign\\Cat\\Admin\\';
$GLOBALS['__filters']['duckhoo_cat_catalog'][] = fn($v) => [
  103 => '[액상덕후] 스모모 흑염룡 시리즈 (0.98MG / 30ml) (멘솔 없음)',
  249 => '[노보 블랙] 블랙멘솔 (9.8mg / 30ml)',
  238 => '[노보] 블랙멘솔 (9.8mg / 30ml)',
  254 => '[펠릭스] 더블라임 (9.8mg / 30ml)',
  1025 => '[펠릭스] 모드 더블라임 (3mg / 60ml)',
  2939 => '[액상덕후] 파이낫푸루 흑염룡 시리즈 (0.98MG / 30ml) (멘솔 없음)',
];
$rows = ($G.'match_lines')("스모모 흑염룡\n# 주석은 건너뛴다\n\n노보 블랙 블랙멘솔\n더블라임\nid:254\n파이낫푸르 흑염룡\n없는상품이름입니다");
$ok(count($rows) === 6, '빈 줄과 주석은 세지 않는다');
$ok($rows[0]['state'] === 'one' && $rows[0]['ids'][0] === 103, '낱말이 다 들어 있는 상품 하나를 집는다');
$ok($rows[1]['state'] === 'one' && $rows[1]['ids'][0] === 249, '「노보 블랙」 은 「노보」 와 갈린다');
$ok($rows[2]['state'] === 'many' && count($rows[2]['ids']) === 2, '여럿에 걸리면 건너뛴다 (30ml · 60ml)');
$ok($rows[3]['state'] === 'one' && $rows[3]['ids'][0] === 254, 'id:254 는 번호로 못 박는다');
$ok($rows[4]['state'] === 'none' && str_contains($rows[4]['near'], '파이낫푸루'), '한 글자가 달라 못 찾으면 비슷한 것을 귀띔한다');
$ok($rows[5]['state'] === 'none' && $rows[5]['near'] === '', '아주 다른 이름에는 아무 것도 귀띔하지 않는다');
$ok(($G.'key')('[노보 블랙] 블랙멘솔 (9.8mg / 30ml)') === '노보블랙블랙멘솔9.8mg30ml', '대괄호 · 괄호 · 띄어쓰기를 걷어내되 용량은 남긴다');
$GLOBALS['__filters']['duckhoo_cat_catalog'] = [];

// ── 메일 · 비밀번호 찾기 (includes/mail.php) ───────────────────────────────
require_once dirname(__DIR__, 2).'/includes/mail.php';
$M = 'Duckhoo\\Redesign\\Mail\\';
$ok(($M.'from_name')('WordPress') === '액상덕후', '보내는 사람이 WordPress 면 가게 이름으로 바꾼다');
$ok(($M.'from_name')('') === '액상덕후', '비어 있어도 가게 이름');
$ok(($M.'from_name')('키플 알림') === '키플 알림', '다른 곳이 이미 이름을 넣었으면 그대로 둔다');
$ok(str_contains(($M.'lost_url')(), 'my-account'), '비밀번호 찾기는 우리 계정 화면');
$msg = ($M.'reset_message')("원래 메일 본문\nhttps://duck-hoo.com/wp-login.php?action=rp&key=K1&login=u1", 'K1', 'u1');
$ok(str_contains($msg, '원래 메일 본문') && str_contains($msg, 'wp-login.php'), '원래 주소를 지우지 않는다 (막히면 로그인을 못 한다)');
$ok(str_contains($msg, 'my-account') && str_contains($msg, 'key=K1'), '우리 주소를 열쇠와 함께 덧붙인다');
$ok(($M.'reset_message')('본문만', '', '') === '본문만', '열쇠가 없으면 손대지 않는다');

// ── 검색 노출 (includes/seo.php) ─────────────────────────────────────────
require_once dirname(__DIR__, 2).'/includes/seo.php';
$S = 'Duckhoo\\Redesign\\Seo\\';
$GLOBALS['__products'] = [];
$GLOBALS['__transients'] = []; // brands() · products() 의 10분 캐시를 비운다
$GLOBALS['__pmeta'] = [];
$GLOBALS['__products'][901] = new WC_Product(901, '[펠릭스] 더블라임 (9.8mg / 30ml)', 20000);
$GLOBALS['__products'][902] = new WC_Product(902, '[노보] 10+1 묶음 데저트', 120000);
$GLOBALS['__products'][903] = new WC_Product(903, '[노보 블랙] 블랙멘솔', 13500);
$GLOBALS['__products'][904] = new WC_Product(904, '[조바] 젤로 기기 + 액상 5병 증정', 69000);
$GLOBALS['__pterms'][901] = [(object)['name'=>'9월 특가 할인'], (object)['name'=>'입호흡 액상']];
$GLOBALS['__pterms'][902] = [(object)['name'=>'노보 액상'], (object)['name'=>'입호흡 액상']];
$GLOBALS['__options']['duckhoo_naver_verify'] = ' ab-12 3 ';
$ok(($S.'naver_code')() === 'ab123', '네이버 인증 코드는 영숫자만 남긴다');
$GLOBALS['__options']['duckhoo_naver_verify'] = '<meta name="naver-site-verification" content="04c147a3e48928d7" />';
$ok(($S.'naver_code')() === '04c147a3e48928d7', '태그를 통째로 붙여도 코드만 꺼낸다');
$GLOBALS['__options']['duckhoo_naver_verify'] = 'metanamenaversiteverificationcontent04c147a3e48928d7';
$ok(($S.'naver_code')() === '04c147a3e48928d7', '전에 잘못 저장된 값도 코드로 읽는다');
unset($GLOBALS['__options']['duckhoo_naver_verify']);
ob_start(); ($S.'head')(); $h = ob_get_clean();
$ok(!str_contains($h, 'naver-site-verification'), '코드가 없으면 인증 메타를 찍지 않는다');
$GLOBALS['__options']['duckhoo_naver_verify'] = 'x9';
ob_start(); ($S.'head')(); $h = ob_get_clean();
$ok(str_contains($h, '<meta name="naver-site-verification" content="x9">'), '코드가 있으면 인증 메타 한 줄');
$t = ($S.'auto_text')($GLOBALS['__products'][901]);
$ok(str_contains($t, '펠릭스 더블라임') && str_contains($t, '20,000원') && str_contains($t, '입호흡 액상'), '자동 설명은 브랜드 · 이름 · 가격 · 분류를 사실대로 엮는다');
$ok(!str_contains($t, '특가') && !str_contains($t, '병당'), '행사 분류는 넣지 않고 낱병에는 병당이 없다');
$ok(str_contains($t, '8,800원 적립') && str_contains($t, '30,000원 이상 무료배송'), '가입 적립 · 무료배송 기준을 붙인다');
$t = ($S.'auto_text')($GLOBALS['__products'][902]);
$ok(str_contains($t, '120,000원 (병당 10,900원)') && str_contains($t, '입호흡 액상'), '묶음은 병당 가격을 십원 단위로 붙이고 입호흡을 앞에 둔다');
$t = ($S.'auto_text')($GLOBALS['__products'][904]);
$ok(!str_contains($t, '병당'), '기기 + 증정 구성은 병으로 나누지 않는다');
// 이름이 브랜드를 한 번 더 쓴 상품 — 「맥스쿨 맥스쿨 소다」가 되지 않게.
$GLOBALS['__products'][905] = new WC_Product(905, '[맥스쿨] 맥스쿨 소다 무니코틴 액상', 11900);
$t = ($S.'auto_text')($GLOBALS['__products'][905]);
$ok(str_starts_with($t, '맥스쿨 소다 무니코틴 액상') && 1 === substr_count($t, '맥스쿨'), '이름이 브랜드로 시작하면 브랜드를 두 번 쓰지 않는다');
$ok(($S.'hand_text')($GLOBALS['__products'][901]) === '', '손으로 쓴 글이 없으면 빈 문자열');
ob_start(); ($S.'render_text')($GLOBALS['__products'][901]); $h = ob_get_clean();
$ok($h === '', '손으로 쓴 글이 없으면 화면에 아무것도 안 그린다 (자동 글은 보이는 값의 되풀이)');
$GLOBALS['__pmeta'][901]['_dhr_text'] = '라임 두 겹에 멘솔 한 줌. 30ml <b>입호흡</b>';
$ok(($S.'text')($GLOBALS['__products'][901]) === '라임 두 겹에 멘솔 한 줌. 30ml <b>입호흡</b>', '손으로 쓴 글이 있으면 그것이 설명이다');
ob_start(); ($S.'render_text')($GLOBALS['__products'][901]); $h = ob_get_clean();
$ok(str_contains($h, '<p class="dhp-about">') && str_contains($h, '&lt;b&gt;'), '화면에는 escape 해서 그린다');
$d = ($S.'schema_desc')(['name'=>'x','description'=>''], $GLOBALS['__products'][901]);
$ok(str_starts_with($d['description'], '라임 두 겹'), 'JSON-LD 설명이 비어 있으면 채운다');
$d = ($S.'schema_desc')(['description'=>'이미 있음'], $GLOBALS['__products'][901]);
$ok($d['description'] === '이미 있음', 'JSON-LD 설명이 있으면 그대로');
$c = ($S.'cat_text')((object)['name'=>'폐호흡 액상','count'=>22]);
$ok(str_contains($c, '폐호흡(DL)') && str_contains($c, '22종'), '분류 글은 종류 · 개수를 말한다');
$ok(str_contains(($S.'cat_text')((object)['name'=>'무니코틴','count'=>0]), '니코틴 없는') && !str_contains(($S.'cat_text')((object)['name'=>'무니코틴','count'=>0]), '0종'), '무니코틴 · 0종은 숫자를 뺀다');
foreach (['건강','금연','순하','해롭'] as $bad) { $ok(!str_contains($c.$t, $bad), "글에 「{$bad}」이 없다 (담배사업법)"); }
$ok(($S.'description')('사장님이 쓴 것') === '사장님이 쓴 것', 'AIOSEO 에 값이 있으면 그대로');
// 메타 설명 길이 자르기 (2026-09-11 — 입호흡 분류가 230자였다)
$cap = $S.'cap';
$ok($cap('짧은 설명입니다.') === '짧은 설명입니다.', '짧은 글은 손대지 않는다');
$long = str_repeat('가나다라마바사아자차', 12) . ' 끝.';
$ok(mb_strlen($cap($long)) <= 161, '긴 글은 160자 안으로 줄인다');
$two = str_repeat('앞 문장이 충분히 깁니다 ', 9) . '끝났습니다. ' . str_repeat('뒤에 더 붙는 말 ', 20);
$ok(str_ends_with($cap($two), '끝났습니다.'), '문장 끝에서 자른다 — 한가운데서 끊지 않는다');
$ok(str_ends_with($cap('짧다. ' . str_repeat('뒤가 아주 길게 이어지는 말 ', 20)), '…'), '앞 문장이 너무 짧으면 토막내지 않고 낱말 경계로 자른다');
$ok(str_ends_with($cap(str_repeat('가나다라마바사아자차차차', 20)), '…'), '문장 끝이 없으면 말줄임표');
$ok($cap('  <b>태그</b>와   여러   공백  ') === '태그와 여러 공백', '태그를 벗기고 공백을 하나로');
add_filter('duckhoo_meta_desc_max', fn() => 0);
$ok($cap($long) === trim(preg_replace('/\s+/u',' ',$long)), '필터로 0 을 주면 자르지 않는다');
$GLOBALS['__filters']['duckhoo_meta_desc_max'] = [];
$GLOBALS['__qv'] = [];
$ok(($S.'description')('') === '', '아무 화면도 아니면 비운다');
$GLOBALS['__is_product'] = true; $GLOBALS['__qid'] = 901;
$ok(str_starts_with(($S.'description')(''), '라임 두 겹'), '상품 상세는 상품 글');
$ok(str_contains(($S.'description')(''), '가입 즉시 8,800원 적립'), '손으로 쓴 글에는 누를 이유를 꼬리로 붙인다');
// AIOSEO 상품 템플릿(175개 중 137개가 이 꼬리였다)은 우리 글로 바꾼다. 손으로 쓴 것은 그대로.
$tpl = '[펠릭스] 더블라임 - 액상덕후의 입호흡 액상 상품입니다. 가입 시 적립금 8,800원 증정 + 3만원 이상 무료배송으로 빠르게 만나보세요.';
$ok(($S.'templated')($tpl) === true && ($S.'templated')('사장님이 손으로 쓴 설명입니다.') === false, '템플릿으로 채운 설명을 가려낸다');
$ok(str_starts_with(($S.'description')($tpl), '라임 두 겹'), '템플릿 설명이면 상품 글로 바꾼다');
$ok(($S.'description')('사장님이 손으로 쓴 설명입니다.') === '사장님이 손으로 쓴 설명입니다.', '손으로 쓴 설명은 상품 상세에서도 그대로');
$GLOBALS['__pmeta'][901] = [];
$GLOBALS['__slugs'][901] = '글-없는-상품';
$auto = ($S.'description')($tpl);
$ok(str_contains($auto, '가입 즉시 8,800원 적립') && ! str_contains($auto, '적립.  ') && 1 === substr_count($auto, '액상덕후'), '우리 글이 없으면 사실로 엮은 글 — 꼬리는 한 번만');
$GLOBALS['__slugs'][901] = rawurlencode('펠릭스-더블-라임-9-8mg-30ml');
// 손으로 쓴 글이지만 **틀린** 상품은 덮는다 (노보 데저트에 블랙 설명이 붙어 있었다).
$GLOBALS['__pmeta'][901]['_dhr_text'] = '우리 글';
$wrong = '노보 블랙 데저트 액상 30ml, 니코틴 9.8mg 입호흡(MTL) 전용.';
$ok(($S.'description')($wrong) === $wrong, '목록에 없으면 손으로 쓴 글 그대로');
$GLOBALS['__slugs'][901] = rawurlencode('노보-데저트-9-8mg-30ml');
$ok(($S.'overridden')($GLOBALS['__products'][901]) === true && str_starts_with(($S.'description')($wrong), '우리 글'), '덮을 목록에 있으면 손으로 쓴 글이어도 우리 글로 바꾼다');
$GLOBALS['__filters']['duckhoo_meta_desc_override'] = [fn($v) => []];
$ok(($S.'description')($wrong) === $wrong, '필터로 목록을 비우면 도로 사장님 글');
$GLOBALS['__filters']['duckhoo_meta_desc_override'] = [];
$GLOBALS['__pmeta'][901] = [];
$GLOBALS['__slugs'][901] = rawurlencode('펠릭스-더블-라임-9-8mg-30ml');
// 2026-09-21 — 코덱스가 노보 13개에 규격대로 찍은 꼬리도 템플릿이다 (맛이 없고 다섯 개는 남의 맛).
$codex = '노보 블랙 데저트 액상 30ml, 니코틴 9.8mg 입호흡(MTL) 전용. 액상덕후에서 3만원 이상 무료배송, 신규 가입 시 적립금 8,800원 증정.';
$codex2 = '노보 타박멘솔 액상 30ml, 니코틴 9.8mg 입호흡(MTL) 전용. 액상덕후 노보 액상 판매량 1위 제품. 3만원 이상 무료배송, 가입 시 적립금 8,800원.';
$codex3 = '노보 액상 30ml 10병을 병당 7,000원(총 70,000원)에. 맛 조합 선택 가능한 입호흡 전자담배 액상 묶음 상품. 무료배송, 가입 시 적립금 8,800원.';
$felix = '[펠릭스] 더블라임 20,000원 - 입호흡 전용 더블라임 향 액상(9.8mg/30ml). 3만원 이상 무료배송, 신규가입 적립금 8,800원 증정. 액상덕후.';
$ok(($S.'templated')($codex) && ($S.'templated')($codex2) && ($S.'templated')($codex3), '코덱스 규격 꼬리 세 가지를 템플릿으로 본다');
$ok(!($S.'templated')($felix) && !($S.'templated')('라임 두 겹. 액상덕후 — 가입 즉시 8,800원 적립.'), '펠릭스 손글(「신규가입」 빈칸 없음) · 우리 꼬리는 안 걸린다');
$GLOBALS['__slugs'][901] = rawurlencode('노보-블랙-타박멘솔-9-8mg-30ml');
$d = ($S.'description')($codex);
$ok(str_starts_with($d, '노보 블랙 타박멘솔 액상 — 담배 잎의 구수함') && !str_contains($d, '데저트'), '블랙 타박멘솔에 붙어 있던 「데저트」 설명이 우리 글로 바뀐다');
$GLOBALS['__slugs'][901] = rawurlencode('노보-엠에스블랜드-9-8mg-30ml');
$ok(str_starts_with(($S.'description')($codex2), '노보 엠에스블랜드 액상 — 구수한 연초') && !str_contains(($S.'description')($codex2), '판매량 1위'), '엠에스블랜드에 붙어 있던 「타박멘솔 판매량 1위」가 우리 글로 바뀐다');
$GLOBALS['__slugs'][901] = rawurlencode('펠릭스-더블-라임-9-8mg-30ml');
$ok(($S.'description')($felix) === $felix, '펠릭스 손글은 그대로');
// 노보 상품 제목 — 값 · 병 수는 상품에서 읽는다
$GLOBALS['__products'][905] = new WC_Product(905, '[노보] 타박멘솔 (9.8mg / 30ml)', 13000);
$GLOBALS['__products'][906] = new WC_Product(906, '[노보 블랙 리퀴드] 10+1 | 금액 130,000원', 130000);
$GLOBALS['__products'][907] = new WC_Product(907, '[노보 블랙] 엠에스블랜드 (9.8mg / 30ml)', 13500);
$ok(($S.'product_title')($GLOBALS['__products'][905]) === '노보 타박멘솔 액상 입호흡 9.8mg 30ml 13,000원 재고 있음 | 액상덕후', '노보 낱병 제목: 브랜드 · 맛 · 「맛 액상」 · 입호흡 · 규격 · 값');
$ok(($S.'product_title')($GLOBALS['__products'][906]) === '노보 블랙 액상 10+1 묶음 11병 130,000원 입호흡 재고 있음 | 액상덕후', '노보 10+1 제목: 「리퀴드」를 떼고 병 수 · 값');
$ok(($S.'product_title')($GLOBALS['__products'][907]) === '노보 블랙 엠에스블랜드 액상 입호흡 9.8mg 30ml 13,500원 재고 있음 | 액상덕후', '노보 블랙 낱병 제목');
$ok(($S.'product_title')($GLOBALS['__products'][901]) === '', '펠릭스는 제목을 안 정한다 (AIOSEO 값 그대로)');
$GLOBALS['__qid'] = 905;
$ok(($S.'title')('[노보] 타박멘솔 (9.8mg / 30ml) - 액상덕후') === '노보 타박멘솔 액상 입호흡 9.8mg 30ml 13,000원 재고 있음 | 액상덕후', 'title 필터가 노보 상품에서 우리 제목을 준다');
$tags = ($S.'social_title')(['og:title' => '[노보] 타박멘솔 (9.8mg / 30ml) - 액상덕후', 'og:type' => 'product']);
$ok($tags['og:title'] === '노보 타박멘솔 액상 입호흡 9.8mg 30ml 13,000원 재고 있음 | 액상덕후' && $tags['og:type'] === 'product' && !isset($tags['twitter:title']), 'og:title 만 바꾸고 없는 칸은 만들지 않는다');
$GLOBALS['__qid'] = 901;
$ok(($S.'title')('[펠릭스] 더블라임 20,000원 입호흡 액상 | 액상덕후') === '[펠릭스] 더블라임 20,000원 입호흡 액상 | 액상덕후', '펠릭스 제목은 AIOSEO 값 그대로');
// 2026-10-09 — 이름이 「라임 알로에」로 바뀐 #254 (주소는 옛 더블라임). 손으로 쓴 「더블라임」 제목 · 설명을 우리가 덮는다.
$GLOBALS['__products'][908] = new WC_Product(908, '[펠릭스] 라임 알로에 (9.8mg / 30ml)', 20000);
$GLOBALS['__slugs'][908] = rawurlencode('펠릭스-더블라임-9-8mg-30ml');
$GLOBALS['__pterms'][908] = [(object)['name'=>'타격감'], (object)['name'=>'입호흡 액상']];
$ok(($S.'product_title')($GLOBALS['__products'][908]) === '펠릭스 라임 알로에 입호흡 액상 9.8mg 30ml 20,000원 | 액상덕후', '틀린 손글 목록의 펠릭스는 우리 제목 (분류의 입호흡 · 규격 · 값)');
$GLOBALS['__qid'] = 908;
$ok(($S.'title')($felix) !== $felix && !str_contains(($S.'title')('[펠릭스] 더블라임 20,000원 입호흡 액상 | 액상덕후'), '더블라임'), '#254 제목에서 「더블라임」이 빠진다');
$d = ($S.'description')($felix);
$ok(!str_contains($d, '더블라임') && str_contains($d, '라임 알로에'), '#254 설명은 라임 알로에 사실로 엮은 글');
$GLOBALS['__qid'] = 901;
$ok(($S.'description')($felix) === $felix, '새 더블라임(#4701 주소)의 손글은 그대로 — 그쪽은 맞는 글이다');
unset($GLOBALS['__products'][908], $GLOBALS['__slugs'][908], $GLOBALS['__pterms'][908]);   // 브랜드 수 셈에 끼지 않게
$GLOBALS['__filters']['duckhoo_product_title_brands'] = [fn($v) => []];
$GLOBALS['__qid'] = 905;
$ok(($S.'title')('원래 제목') === '원래 제목', '필터로 브랜드를 비우면 노보도 AIOSEO 값');
$GLOBALS['__filters']['duckhoo_product_title_brands'] = [];
unset($GLOBALS['__products'][905], $GLOBALS['__products'][906], $GLOBALS['__products'][907]);
$GLOBALS['__is_product'] = false; $GLOBALS['__qid'] = 0;
// 브랜드 페이지
$ok(($S.'brand_slug')('노보') === 'novo' && ($S.'brand_slug')('조바') === rawurlencode('조바'), '영문 조각이 있으면 그것, 없으면 한글 그대로');
$ok(($S.'brand_from_slug')('novo') === '노보' && ($S.'brand_from_slug')('NOVO') === '노보', '조각 → 브랜드 (대소문자 무시)');
$ok(($S.'brand_from_slug')(rawurlencode('조바')) === '조바', '한글 조각도 상품에 있는 브랜드면 찾는다');
$ok(($S.'brand_from_slug')('nope') === '' && ($S.'brand_from_slug')('') === '', '모르는 조각은 빈 문자열');
$ok(($S.'brand_url')('노보') === 'https://duck-hoo.com/brand/novo/', '브랜드 주소');
$ok(apply_filters('duckhoo_brand_url', 'https://duck-hoo.com/?s=노보', '노보') === 'https://duck-hoo.com/brand/novo/', '푸터 · 홈의 브랜드 링크가 이 주소로 바뀐다');
$ok(($S.'brand_prefixes')('노보') === ['[노보]','[노보 블랙]','[노보 리퀴드]','[노보 블랙 리퀴드]'], '노보는 노보 블랙과 10+1 묶음(…리퀴드)까지 함께 잡는다');
$GLOBALS['__qv'] = ['dhr_brand' => 'novo'];
$ok(($S.'is_brand_page')() && ($S.'current_brand')() === '노보', '주소 조각이 있으면 브랜드 페이지');
$ok(($S.'brand_title')() === '노보 액상', '브랜드 페이지 h1');
$ok(($S.'title')('AIOSEO 제목') === '노보 액상 2종 전 라인 재고 보유 · 바로 주문 | 액상덕후', '브랜드 페이지 제목은 우리가 정한다 — 노보는 「재고 보유」까지 (2026-09-21)');
$ok(str_contains(($S.'brand_intro')('노보'), '재고를 보유'), '노보 소개 첫 문장이 재고를 말한다 — 검색 결과에 그대로 찍힌다');
add_filter('duckhoo_brand_notes', fn($v = null) => []);
$ok(($S.'title')('x') === '노보 액상 2종 | 액상덕후' && !str_contains(($S.'brand_intro')('노보'), '재고를 보유'), '품절이 풀리면 필터 하나로 제목 · 소개가 원래대로');
$GLOBALS['__filters']['duckhoo_brand_notes'] = [];
$bi = ($S.'brand_intro')('노보');
$ok(str_contains($bi, '노보 입호흡 액상 2종') && !str_contains($bi, '블랙멘솔') && str_contains($bi, '10+1 묶음') && !str_contains($bi, '특가') && !str_contains($bi, '한자리에 모았습니다'), '소개 한 줄은 개수 · 분류 · 묶음을 상품에서 읽는다 — 노보는 맛 이름을 나열하지 않는다 (2026-10-06, 상품 페이지 자리를 먹었다)');
$ok(str_contains(($S.'brand_intro')('펠릭스'), '더블라임'), '다른 브랜드 소개에는 맛 이름이 그대로 들어간다');
$ok(($S.'description')('') === ($S.'cap')($bi) && ($S.'canonical')('x') === 'https://duck-hoo.com/brand/novo/', '메타 설명(문장 끝에서 자른 것) · canonical 도 브랜드 것');
$ok(!($S.'thin_brand')(), '상품 둘인 노보는 색인한다');
$ok(str_contains(($S.'brand_intro_html')(), 'class="dhr-brandintro"'), '화면에 글자로 그린다');
ob_start(); ($S.'head')(); $h = ob_get_clean();
$ok(substr_count($h, 'name="description"') === 1 && str_contains($h, 'og:title" content="노보 액상 2종 전 라인 재고 보유 · 바로 주문 | 액상덕후"') && str_contains($h, 'og:url" content="https://duck-hoo.com/brand/novo/"'), '브랜드 페이지는 설명 · og 를 우리가 찍는다 (AIOSEO 가 이 화면을 모른다)');
$ok(($S.'take_archive')(false) === true && ($S.'funnel_stage')('') === 'list', '목록 템플릿 · 깔때기 목록 단계');
$q = new DhrFakeQuery(['dhr_brand' => 'novo']);
($S.'pre_get_posts')($q);
$ok($q->get('post_type') === 'product' && $q->is_home === false && $q->is_archive === true && !$q->get('post__in'), '메인 쿼리를 상품 목록으로 바꾸고 is_home 을 끈다');
$w = ($S.'posts_where')(' AND 1=1', $q);
$ok(substr_count($w, 'wp_posts.post_title LIKE %s') === 4 && $GLOBALS['wpdb']->lastArgs === ['[노보]%', '[노보 블랙]%', '[노보 리퀴드]%', '[노보 블랙 리퀴드]%'], '이름 앞 [노보] · [노보 블랙] · 10+1 묶음으로 고른다');
$q2 = new DhrFakeQuery(['dhr_brand' => 'nope']);
($S.'pre_get_posts')($q2);
$ok($q2->get('post__in') === [0], '모르는 브랜드는 빈 결과 (404)');
$q3 = new DhrFakeQuery([]);
($S.'pre_get_posts')($q3);
$ok($q3->is_home === true && ($S.'posts_where')(' AND 1=1', $q3) === ' AND 1=1', '브랜드 조각이 없으면 손대지 않는다');
$q4 = new DhrFakeQuery(['dhr_brand' => 'novo'], false);
($S.'pre_get_posts')($q4);
$ok($q4->get('post_type', null) === null, '메인 쿼리가 아니면 손대지 않는다');
$GLOBALS['__qv'] = [];
$ok(($S.'title')('AIOSEO 제목') === 'AIOSEO 제목' && ($S.'take_archive')(false) === false, '브랜드 페이지가 아니면 제목 · 템플릿 그대로');
$GLOBALS['__options']['duckhoo_seo_rewrite'] = '';
$GLOBALS['__rw_flushed'] = 0;
($S.'rewrite')(); ($S.'rewrite')();
$ok(isset($GLOBALS['__rw_rules']['^brand/([^/]+)/?$']) && $GLOBALS['__rw_flushed'] === 1, '주소 규칙은 등록하고 flush 는 버전이 바뀔 때 한 번');
$xml = ($S.'brand_sitemap_xml')();
$ok(str_starts_with($xml, '<?xml') && str_contains($xml, '<loc>https://duck-hoo.com/brand/novo/</loc>') && !str_contains($xml, '/brand/felix/') && !str_contains($xml, rawurlencode('조바')), '브랜드 사이트맵에는 상품 둘 이상인 브랜드만 — 하나짜리(펠릭스 · 조바)는 noindex 라 뺀다 (2026-10-02)');
$GLOBALS['__filters']['duckhoo_brand_min_n'] = [fn($v) => 1];
$ok(str_contains(($S.'brand_sitemap_xml')(), '/brand/felix/'), '필터로 기준을 1 로 내리면 도로 실린다');
$GLOBALS['__filters']['duckhoo_brand_min_n'] = [];
// 상품 하나짜리 · 기기 브랜드 (2026-10-02 외부 점검 — 「긱베이프 액상 1종」은 틀린 말이었다)
$GLOBALS['__qv'] = ['dhr_brand' => rawurlencode('조바')];
$ok(($S.'brand_kind')('조바') === 'device' && ($S.'brand_title')() === '조바 기기 · 팟' && ($S.'title')('x') === '조바 기기 · 팟 1종 | 액상덕후', '기기 브랜드는 「액상」이 아니라 「기기 · 팟」');
$ok(str_starts_with(($S.'brand_intro')('조바'), '조바 기기 · 팟 · 코일 1종: 젤로 기기 + 액상 5병 증정 69,000원.'), '기기 브랜드 소개는 상품 이름과 값을 그대로 (브랜드 되풀이 · 「이벤트」는 뗀다)');
$ok(($S.'thin_brand')(), '상품 하나짜리 브랜드 페이지는 얇다');
ob_start(); ($S.'head')(); $h2 = ob_get_clean();
$ok(str_contains($h2, '<meta name="robots" content="noindex, follow">') && str_contains($h2, 'og:title" content="조바 기기 · 팟 1종 | 액상덕후"'), '얇은 브랜드 페이지는 head 에 noindex 를 찍는다 (페이지 · 링크는 그대로)');
$ok(($S.'robots_thin_brand')(['noindex' => false])['noindex'] === true && ($S.'robots_thin_brand')(['x' => 1]) === ['x' => 1, 'noindex' => true, 'follow' => true], 'wp_robots 뒷받침도 noindex');
$GLOBALS['__qv'] = ['dhr_brand' => 'novo'];
$ok(!str_contains((function(){ ob_start(); ('Duckhoo\\Redesign\\Seo\\head')(); return ob_get_clean(); })(), 'name="robots"'), '노보 페이지에는 robots 를 안 찍는다');
$ok(($S.'robots_thin_brand')(['index' => true]) === ['index' => true], '얇지 않으면 wp_robots 를 건드리지 않는다');
$ok(($S.'flavor_name')($GLOBALS['__products'][901], '펠릭스') === '더블라임' && ($S.'flavor_name')($GLOBALS['__products'][902], '노보') === '' && ($S.'flavor_name')(new WC_Product(1, '[맥스쿨] 맥스쿨 소다 무니코틴 액상', 1), '맥스쿨') === '소다' && ($S.'flavor_name')(new WC_Product(1, '[리퀴드랩] 스피아민트 폐호흡 액상 3MG/60ML', 1), '리퀴드랩') === '스피아민트', '맛 이름: 규격 · 브랜드 되풀이 · 「무니코틴 액상」을 떼고, 묶음은 빈 문자열');
$ok(($S.'cap')(str_repeat('가', 100) . '. 니코틴 9.8mg 입호흡 ' . str_repeat('나', 60)) === str_repeat('가', 100) . '.', '메타 설명을 자를 때 「9.8mg」의 점은 문장 끝으로 안 본다 (2026-10-02 — 노보 소개가 「9.」에서 잘렸다)');
$ok(isset($GLOBALS['__rw_rules']['^brands\\.xml$']) && in_array('dhr_sitemap', ($S.'query_vars')([]), true), '사이트맵 주소 규칙 · 쿼리 변수');
// 저장
$_POST = ['dhr_text_nonce' => 'good', 'dhr_text' => "  <script>x</script>맛 설명  "];
$GLOBALS['__can'] = false;
($S.'save_meta')(901);
$ok(!isset($GLOBALS['__postmeta'][901]['_dhr_text']), '편집 권한이 없으면 저장하지 않는다');
$GLOBALS['__can'] = true;
($S.'save_meta')(901);
$ok(($GLOBALS['__postmeta'][901]['_dhr_text'] ?? '') === 'x맛 설명', '태그를 벗기고 다듬어 저장한다');
$_POST = ['dhr_text_nonce' => 'good', 'dhr_text' => '   '];
($S.'save_meta')(901);
$ok(!isset($GLOBALS['__postmeta'][901]['_dhr_text']), '비우면 메타를 지운다');
$_POST = ['dhr_text_nonce' => 'bad', 'dhr_text' => 'x'];
($S.'save_meta')(901);
$ok(!isset($GLOBALS['__postmeta'][901]['_dhr_text']), '논스가 틀리면 저장하지 않는다');
$_POST = []; $GLOBALS['__can'] = false;


// ── 승인된 상품 글 (includes/seo-texts.php) ───────────────────────────────
require_once dirname(__DIR__, 2).'/includes/seo-texts.php';
$GLOBALS['__pmeta'][901] = [];
$GLOBALS['__slugs'][901] = rawurlencode('펠릭스-더블-라임-9-8mg-30ml');
$ok(str_starts_with(($S.'hand_text')($GLOBALS['__products'][901]), '라임을 두 겹'), '상자가 비면 승인된 글을 쓴다 (slug 는 퍼센트 인코딩돼 있어도)');
ob_start(); ($S.'render_text')($GLOBALS['__products'][901]); $h = ob_get_clean();
$ok(str_contains($h, 'dhp-about') && str_contains($h, '입호흡(MTL)'), '화면에도 그린다');
$GLOBALS['__pmeta'][901]['_dhr_text'] = '사장님이 상자에 쓴 글';
$ok(($S.'hand_text')($GLOBALS['__products'][901]) === '사장님이 상자에 쓴 글', '상자의 글이 먼저다');
$GLOBALS['__pmeta'][901] = [];
$GLOBALS['__slugs'][902] = '노보-10-1';
$GLOBALS['__slugs'][903] = rawurlencode('노보-블랙-블랙멘솔-9-8mg-30ml');
$ok(str_contains(($S.'hand_text')($GLOBALS['__products'][903]), '노보보다') && count(('Duckhoo\\Redesign\\Seo\\Texts\\texts')()) === 69, '상품 글이 실렸다 (주소 69개 — 이름이 다른 상품은 옛 주소 · 새 주소 둘 다, 2026-09-28 서치콘솔 6개 추가)');
$ok(($S.'hand_text')($GLOBALS['__products'][902]) === '', '목록에 없는 상품은 그대로 비어 있다');
foreach (('Duckhoo\\Redesign\\Seo\\Texts\\texts')() as $slug => $txt) { foreach (['건강','금연','순하','해롭'] as $bad) { $ok(!str_contains($txt, $bad), "「{$bad}」 없음: {$slug}"); } $ok(mb_strlen($txt) <= 160, "160자 이내: {$slug}"); }


// ── 옵션 이름 바꾸기 (includes/opt-admin.php) ─────────────────────────────
if ( ! function_exists('is_serialized') ) {
	function is_serialized($d){ if(!is_string($d)) return false; $d=trim($d); if('N;'===$d) return true; if(strlen($d)<4||':'!==$d[1]) return false; return (bool) preg_match('/^[aOsbdi]:/',$d); }
}
require_once dirname(__DIR__, 2).'/includes/opt-admin.php';
$O = 'Duckhoo\\Redesign\\Opt\\Admin\\';
$old = '브이메이트V4팟 0.7옴(2EA)';
$new = '브이메이트V5팟 0.7옴(3EA)';

// 묶어 놓은 값(serialize) — 길이 숫자까지 다시 맞아야 한다
$src = serialize(['options' => [
	['option' => $old, 'price' => '8000', 'optionid' => 'x'],
	['option' => '소울V2 0.6옴 팟(2EA)', 'price' => '9000', 'optionid' => 'y'],
]]);
$r = ($O.'made')($src, $old, $new, 12000);
$ok($r['ok'] && $r['name'] === 1 && $r['price'] === 1, '묶인 값: 이름 1곳 · 값 1곳');
$back = unserialize($r['raw']);
$ok(is_array($back), '바꾼 뒤에도 다시 풀린다 (길이 숫자가 맞다)');
$ok($back['options'][0]['option'] === $new && $back['options'][0]['price'] === '12000', '그 줄의 이름과 값만 바뀐다');
$ok($back['options'][1]['price'] === '9000' && $back['options'][1]['option'] === '소울V2 0.6옴 팟(2EA)', '다른 옵션은 한 글자도 안 바뀐다');

// 값을 비워 두면 이름만
$r2 = ($O.'made')($src, $old, $new, -1);
$b2 = unserialize($r2['raw']);
$ok($b2['options'][0]['option'] === $new && $b2['options'][0]['price'] === '8000', '새 값을 비우면 이름만 바꾸고 값은 그대로');

// JSON 글자 — 이름 뒤의 값만 바꾼다
$json = '{"opts":[{"option":"'.$old.'","price":"8000"},{"option":"젤로맥스0.6옴팟(3EA)","price":"13500"}]}';
$rj = ($O.'made')($json, $old, $new, 12000);
$ok(str_contains($rj['raw'], '"'.$new.'","price":"12000"'), 'JSON: 그 옵션의 값만 새 값으로');
$ok(str_contains($rj['raw'], '"price":"13500"'), 'JSON: 뒤에 오는 다른 옵션의 값은 그대로');
$ok(is_array(json_decode($rj['raw'], true)), 'JSON 이 깨지지 않는다');

// `이름|8000` 모양
$pipe = $old."|8000\n소울V2 0.6옴 팟(2EA)|9000";
$rp = ($O.'made')($pipe, $old, $new, 12000);
$ok(str_contains($rp['raw'], $new.'|12000') && str_contains($rp['raw'], '팟(2EA)|9000'), '세로줄 모양도 그 줄의 값만');

// 값을 못 찾으면 이름만 바꾸고 조용히 둔다
$plain = '추가 옵션: '.$old.' 를 드립니다';
$rn = ($O.'made')($plain, $old, $new, 12000);
$ok($rn['name'] === 1 && $rn['price'] === 0 && str_contains($rn['raw'], $new), '값이 없으면 이름만 바꾸고 값은 건드리지 않는다');

// 읽을 수 없는 것은 손대지 않는다
$broken = 'a:1:{s:99:"깨짐";}';
$rb = ($O.'made')($broken, $old, $new, 12000);
$ok($rb['ok'] === false && $rb['raw'] === $broken, '풀리지 않는 값은 한 글자도 바꾸지 않는다');

// 주문 · 기록은 건너뛴다
$ok(($O.'off_limits')('shop_order', '_ppom') === true, '주문은 건너뛴다');
$ok(($O.'off_limits')('shop_order_refund', 'x') === true && ($O.'off_limits')('product', '_order_key') === true, '환불 · 주문 칸도 건너뛴다');
$ok(($O.'off_limits')('nm_ppom', '_ppom_fields') === false, 'PPOM 옵션 자리는 바꾼다');

// 상품 이름 미리 채우기
$ok(($O.'guess_name')('[부푸] 브이메이트 V4 0.7옴팟', $old, $new) === '[부푸] 브이메이트 V5 0.7옴팟', '상품 이름의 V4 → V5 를 미리 채운다');
$ok(($O.'guess_name')('[부푸] 브이메이트 V4 팟(2EA)', $old, $new) === '[부푸] 브이메이트 V5 팟(3EA)', '(2EA) → (3EA) 도 같이');
$ok(($O.'guess_name')('[긱베이프] 소울V2 0.6옴팟', '맛 A', '맛 B') === '[긱베이프] 소울V2 0.6옴팟', '다른 토막이 없으면 이름을 그대로 둔다');

// 앞뒤 토막
$ok(str_contains(($O.'snippet')($json, $old), $old), '미리 보기 토막에 그 이름이 들어 있다');

// JSON 안에서 한글이 escape 돼 있는 경우 — DB 에 한글이 한 글자도 없을 수 있다
$esc = ($O.'json_bare')($old);
$ok($esc !== $old && str_contains($esc, '\\u'), '한글이 escape 된 모습을 만든다');
$jesc = '{"opts":[{"option":"'.$esc.'","price":"8000"},{"option":"'.($O.'json_bare')('소울V2 0.6옴 팟(2EA)').'","price":"9000"}]}';
$re = ($O.'made')($jesc, $old, $new, 12000);
$ok($re['name'] === 1 && str_contains($re['raw'], ($O.'json_bare')($new)), 'escape 된 글자도 찾아 바꾼다');
$ok(str_contains($re['raw'], '"price":"12000"') && str_contains($re['raw'], '"price":"9000"'), 'escape 된 JSON 에서도 그 줄의 값만 바꾼다');
$dec = json_decode($re['raw'], true);
$ok(is_array($dec) && $dec['opts'][0]['option'] === $new, '풀어 보면 새 이름이 그대로 나온다');
$ok(count(($O.'variants')($old, $new)) === 2 && count(($O.'variants')('V4', 'V5')) === 1, '영문만 있으면 모습이 하나뿐이다');

// 못 찾았을 때 다시 훑을 토막 — 숫자만 있는 것은 버전 번호에 다 걸린다
$ok(($O.'probe_frag')($old) === 'V4', '16진수로만 된 토막(2EA · 0.7)은 피한다 — 주문 해시에 다 걸린다');
$ok(($O.'probe_frag')('[부푸] 브이메이트 V4 0.7옴팟') === 'V4', '글자 토막이 하나면 그것을 쓴다');
$ok(($O.'probe_frag')('맛 선택') === '', '영문이 없으면 빈 값');
// 빈칸이 다른 자리 — 찾은 그 글자 그대로 바꾼다
$nb  = "브이메이트V4팟\u{00A0}0.7옴(2EA)";
$nbr = '{"option":"'.$nb.'","price":"8000"}';
$rn2 = ($O.'made_pairs')($nbr, [[$nb, $new]], 12000);
$ok($rn2['name'] === 1 && str_contains($rn2['raw'], $new) && str_contains($rn2['raw'], '"price":"12000"'), '빈칸이 다른 자리도 그 자리의 글자로 바꾼다');
$ok(!str_contains($rn2['raw'], "\u{00A0}"), '바꾼 뒤에는 그 이상한 빈칸이 남지 않는다');

// 옵션 ID 로 찾아 바꾸기 — 한글이 어떻게 저장돼 있든 ID 는 영문·숫자라 확실하다
$KEY = '_____v4__0_7__2ea_';
$grp = ['ppom_inputs' => [['title' => '팟, 코일', 'options' => [
	['option' => $old, 'price' => '8000', 'weight' => '', 'stock' => '', 'id' => $KEY],
	['option' => '소울V2 0.6옴 팟(2EA)', 'price' => '9000', 'weight' => '', 'stock' => '', 'id' => '__v2_0_6____2ea_'],
]]]];
$sk = ($O.'swap_key')(serialize($grp), $KEY, $new, 12000);
$sd = unserialize($sk['raw']);
$ok($sk['ok'] && $sk['name'] === 1 && $sk['price'] === 1, '묶인 값: ID 로 찾아 이름 1곳 · 값 1곳');
$ok($sd['ppom_inputs'][0]['options'][0]['option'] === $new && $sd['ppom_inputs'][0]['options'][0]['price'] === '12000', '그 줄의 이름과 값이 바뀐다');
$ok($sd['ppom_inputs'][0]['options'][0]['id'] === $KEY, 'ID 는 그대로 — 장바구니 · 주문이 이것으로 옵션을 알아본다');
$ok($sd['ppom_inputs'][0]['options'][1]['option'] === '소울V2 0.6옴 팟(2EA)' && $sd['ppom_inputs'][0]['options'][1]['price'] === '9000', '옆 옵션은 한 글자도 안 바뀐다');

$sj = ($O.'swap_key')(json_encode($grp), $KEY, $new, 12000);
$jd = json_decode($sj['raw'], true);
$ok($sj['name'] === 1 && $jd['ppom_inputs'][0]['options'][0]['option'] === $new && $jd['ppom_inputs'][0]['options'][0]['price'] === '12000', 'escape 된 JSON 도 ID 로 찾아 바꾼다');
$ok($jd['ppom_inputs'][0]['options'][1]['price'] === '9000' && str_contains($sj['raw'], '\\u'), '옆 옵션 · escape 된 모습은 그대로 둔다');
$ok(($O.'swap_key')(json_encode($grp), 'no_such_id', $new, 12000)['name'] === 0, '없는 ID 면 한 글자도 안 바꾼다');

// 한글 검색이 되는지 시험할 토막
$ok(($O.'ko_bit')($old) === '브이메이' && ($O.'ko_bit')('V4 0.7') === '', '한글만 이어진 토막을 4글자까지 뜬다');

// 주문 항목 표는 글 종류로 걸러지지 않는다 — 표 이름으로 한 번 더 막는다
$ok(($O.'off_table')('wp_woocommerce_order_itemmeta') === true, '주문 항목 표는 손대지 않는다');
$ok(($O.'off_table')('wp_wc_orders') === true && ($O.'off_table')('wp_actionscheduler_logs') === true, '주문 · 예약작업 기록도 손대지 않는다');
$ok(($O.'off_table')('wp_postmeta') === false && ($O.'off_table')('wp_posts') === false, '글 · 글 메타는 바꾼다');

// 찾은 덩어리에서 누를 수 있는 이름만 떠낸다
$rowz = [['raw' => '{"group_key":"addon","label":"'.$old.'","price":"8000"}'], ['raw' => '{"label":"'.($O.'json_bare')($old).'"}']];
$gs = ($O.'label_guesses')($rowz, '브이메이트');
$ok(in_array($old, $gs, true) && count($gs) === 1, 'escape 된 것도 되돌려 같은 이름 하나로 모은다');

$nd = ($O.'probe_needles')($old);
$ok($nd[0] === '브이메이트V4팟' && str_contains($nd[1], '\\u') && end($nd) === 'V4', '빈칸 없는 긴 토막 → escape 된 것 → 영문 순으로 훑는다');

// 되돌리기 열쇠 — 어느 표 · 어느 칸 · 어느 줄인지를 그대로 들고 있어야 한다
$spot = ['table' => 'wp_postmeta', 'idcol' => 'meta_id', 'valcol' => 'meta_value', 'id' => '77', 'post' => 4653, 'key' => '_ppom'];
$ok(($O.'spot_key')($spot) === 'wp_postmeta|meta_id|meta_value|77|4653|_ppom', '되돌릴 자리를 열쇠 하나에 담는다');
$bits = explode('|', ($O.'spot_key')($spot));
$ok(count($bits) === 6 && $bits[0] === 'wp_postmeta' && $bits[3] === '77', '열쇠를 다시 쪼개면 같은 자리가 나온다');


// ── 목록 정렬 (includes/sort.php) ─────────────────────────────────────────
require_once dirname(__DIR__, 2).'/includes/sort.php';
$S2 = 'Duckhoo\\Redesign\\Sort\\';

$_GET = [];
$ok(($S2.'current')() === '', '아무것도 안 고르면 빈 값 (추천순)');
$_GET['orderby'] = 'price';
$ok(($S2.'current')() === 'price', '낮은 가격순');
$_GET['orderby'] = 'nonsense';
$ok(($S2.'current')() === '', '모르는 값은 무시한다 — 주소를 손으로 바꿔도 안전');
$_GET = [];
$ok(array_keys(($S2.'options')()) === ['','price','price-desc','date'], '고를 수 있는 순서 네 가지');

// 검색 결과만 우리가 정렬한다 — ORDER BY 를 마지막에 직접 쓴다
$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new stdClass();
$GLOBALS['wpdb']->posts = 'wp_posts';
$GLOBALS['wpdb']->postmeta = 'wp_postmeta';
$mk = function(array $v, bool $srch) { $q = new DhrFakeQuery($v); $q->is_srch = $srch; return $q; };

$_GET['orderby'] = 'price';
$sql = ($S2.'order_sql')('wp_posts.post_date DESC', $mk(['post_type' => 'product'], true));
$ok(str_contains($sql, "meta_key = '_price'") && str_ends_with($sql, 'ASC'), '검색: 낮은 가격순 ORDER BY 를 쓴다');
$ok(!str_contains($sql, 'post_date'), '원래 순서를 밀어낸다 — 부탁이 아니라 마지막에 쓴다');
$_GET['orderby'] = 'price-desc';
$ok(str_ends_with(($S2.'order_sql')('x', $mk(['post_type' => 'product'], true)), 'DESC'), '검색: 높은 가격순');
$_GET['orderby'] = 'date';
$ok(($S2.'order_sql')('x', $mk(['post_type' => 'product'], true)) === 'wp_posts.post_date DESC', '검색: 신상품순');
$_GET['orderby'] = 'price';
$ok(!str_contains(($S2.'order_sql')('x', $mk(['post_type' => 'product'], true)), 'JOIN'), '값 칸을 JOIN 하지 않는다 — 정렬 때문에 상품이 빠지면 안 된다');
$ok(($S2.'order_sql')('x', $mk(['post_type' => 'product'], false)) === 'x', '검색이 아니면 손대지 않는다 — 분류 목록은 워드커머스가 한다');
$ok(($S2.'order_sql')('x', $mk(['post_type' => 'post'], true)) === 'x', '상품 검색이 아니면 손대지 않는다');
$qn = $mk(['post_type' => 'product'], true); $qn->main = false;
$ok(($S2.'order_sql')('x', $qn) === 'x', '메인 쿼리가 아니면 손대지 않는다');
$_GET = [];
$ok(($S2.'order_sql')('x', $mk(['post_type' => 'product'], true)) === 'x', '아무것도 안 골랐으면 원래 순서 그대로');
$ok(($S2.'order_sql')('x', null) === 'x', '쿼리가 없으면 손대지 않는다');
$_GET['orderby'] = 'price';
$ok(str_contains(($S2.'order_sql')('x', $mk(['post_type' => 'product', 'dhr_brand' => 'novo'], false)), '_price'), '브랜드 페이지도 우리가 정렬한다 — 워드커머스가 안 잡는 화면이다');
$ok(str_ends_with(($S2.'order_sql')('x', $mk(['post_type' => 'product'], true)), 'ASC'), '같은 값이면 번호로 한 번 더 가른다 (꼬리)');

// posts_clauses — 워드프레스가 posts_orderby **뒤에** 한 번 더 묻는 자리
$_GET['orderby'] = 'price';
$cl = ($S2.'order_clauses')(['where' => ' AND 1=1', 'orderby' => 'wp_posts.post_date DESC'], $mk(['post_type' => 'product'], true));
$ok(str_contains($cl['orderby'], "meta_key = '_price'") && $cl['where'] === ' AND 1=1', '조각 전체에도 같은 것을 쓴다 (다른 조각은 그대로)');
$_GET = [];
$cl2 = ($S2.'order_clauses')(['orderby' => 'keep me'], $mk(['post_type' => 'product'], true));
$ok($cl2['orderby'] === 'keep me', '안 골랐으면 조각도 그대로');

// 화면에 그리는 자리
$GLOBALS['__is_shop'] = true;
$h = ($S2.'html')();
$ok(substr_count($h, '<a ') === 4 && str_contains($h, 'aria-current="true"'), '정렬 줄에 네 개 · 지금 것 표시');
$GLOBALS['__is_shop'] = false; $GLOBALS['__qv'] = [];
$ok(($S2.'html')() === '', '상품 목록이 아니면 안 그린다');


/* ── 송장번호 (includes/tracking.php) ──────────────────────────────────────
   송장이 어느 메타에 있는지 모르는 채로 짓는다. 그래서 「이름을 알 때」와
   「훑어서 찾을 때」가 둘 다 맞아야 하고, **엉뚱한 숫자를 송장이라고 하면 안 된다** —
   주문번호 · 금액 · 전화번호가 걸리면 손님이 없는 송장을 조회하게 된다. */
require_once dirname(__DIR__, 2) . '/includes/tracking.php';
$T = 'Duckhoo\\Redesign\\Tracking\\';

$ok(($T.'clean_no')('6890174816619') === '6890174816619', '13자리 숫자는 송장');
$ok(($T.'clean_no')('6890-1748-16619') === '6890174816619', '하이픈 · 빈칸은 떼고 본다');
$ok(($T.'clean_no')('123') === '', '너무 짧은 숫자는 송장이 아니다');
$ok(($T.'clean_no')('123456789012345') === '', '너무 긴 숫자도 아니다');
$ok(($T.'clean_no')(['a']) === '' && ($T.'clean_no')(null) === '', '배열 · 없음은 빈 값');

$ok(($T.'tracking_key')('_keyple_tracking_number') && ($T.'tracking_key')('송장번호'), '이름이 송장인 칸을 알아본다');
$ok(!($T.'tracking_key')('_wd_point_discount') && !($T.'tracking_key')('_billing_phone'), '적립금 · 전화번호 칸은 송장이 아니다');
$ok(($T.'courier_of')('우체국택배') === 'epost' && ($T.'courier_of')('CJ대한통운') === 'cj', '택배사 이름을 알아본다');
$ok(($T.'courier_of')('') === '' && ($T.'courier_of')('아무거나') === '', '모르는 말에는 택배사를 붙이지 않는다');

// 1. 이름이 알려진 칸
$o = new DhrFakeOrder(3001, ['_keyple_tracking_number' => '6890174816619']);
$t = ($T.'find')($o);
$ok($t['no'] === '6890174816619' && $t['key'] === '_keyple_tracking_number', '알려진 칸에서 찾는다');
$ok($t['name'] === '우체국택배' && str_contains($t['url'], 'epost.go.kr') && str_contains($t['url'], '6890174816619'), '택배사를 못 찾으면 우체국 · 조회 주소가 붙는다');

// 2. 이름을 모르는 칸이어도 훑어서 찾는다 — 이 가게에서 실제로 필요한 길이다
$o = new DhrFakeOrder(3002, ['_some_plugin_invoice_no' => '6890174816619', '_billing_phone' => '01012345678']);
$t = ($T.'find')($o);
$ok($t['no'] === '6890174816619' && $t['key'] === '_some_plugin_invoice_no', '모르는 이름도 훑어서 찾는다');

// 3. **숫자만으로는 송장이라고 하지 않는다** — 이름이 송장을 가리켜야 한다
$o = new DhrFakeOrder(3003, ['_billing_phone' => '01012345678', '_order_total' => '135000', '_wd_point_discount' => '8800']);
$ok(($T.'find')($o)['no'] === '', '전화번호 · 금액을 송장으로 읽지 않는다');

// 4. 택배사가 주문에 적혀 있으면 그것을 쓴다
$o = new DhrFakeOrder(3004, ['_tracking_number' => '123456789012', '_tracking_company' => 'CJ대한통운']);
$t = ($T.'find')($o);
$ok($t['courier'] === 'cj' && str_contains($t['url'], 'cjlogistics.com'), '주문에 적힌 택배사를 따라간다');

// 5. 필터가 가장 앞이다
add_filter('duckhoo_order_tracking', fn($v, $order = null) => ['no' => '999888777666', 'courier' => 'hanjin']);
$t = ($T.'find')(new DhrFakeOrder(3005, ['_tracking_number' => '111222333444']));
$ok($t['no'] === '999888777666' && $t['courier'] === 'hanjin', '필터로 못 박으면 그것을 쓴다');
$GLOBALS['__filters']['duckhoo_order_tracking'] = [];

// 6. 못 찾으면 아무것도 그리지 않는다 — 없는 송장을 「곧 등록됩니다」로 채우지 않는다
$ok(($T.'box_html')(($T.'find')(new DhrFakeOrder(3006))) === '', '송장이 없으면 상자를 안 그린다');

// 7. 상자
$h = ($T.'box_html')(($T.'find')(new DhrFakeOrder(3007, ['_tracking_number' => '6890174816619'])));
$ok(str_contains($h, '6890 1748 16619'), '번호는 네 자리씩 띄어 읽기 쉽게');
$ok(str_contains($h, 'data-copy="6890174816619"'), '복사하는 값은 숫자 그대로 (띄어쓰기 없이)');
$ok(str_contains($h, 'target="_blank"') && str_contains($h, 'rel="noopener'), '조회는 새 창 — 주문 화면을 잃지 않는다');
$ok(str_contains($h, '우체국택배'), '택배사 이름을 적는다');

// 8. 주문 목록 버튼
$a = ($T.'action')(['view' => ['url' => '#', 'name' => '보기']], new DhrFakeOrder(3008, ['_tracking_number' => '6890174816619']));
$ok(isset($a['duckhoo-track']) && $a['duckhoo-track']['name'] === '배송조회' && isset($a['view']), '주문 목록에 배송조회 버튼 · 원래 것은 그대로');
$ok(!isset(($T.'action')([], new DhrFakeOrder(3009))['duckhoo-track']), '송장이 없으면 버튼도 없다');
$ok(($T.'action')(['view' => 1], null) === ['view' => 1], '주문이 없으면 손대지 않는다');



/* ── 담은 뒤의 가입 벽 (Front\join_wall) ─────────────────────────────────
   비로그인도 담기는 되고 막히는 곳은 결제다 (302 → /register/). 그 벽이 아무 말도
   하지 않아서 손님은 결제하기를 누른 뒤에야 가입 화면을 본다. 미리 말해 준다. */
$F2 = 'Duckhoo\\Redesign\\Front\\';
add_filter('duckhoo_photos_gated', fn($v = null) => true);
$w = ($F2.'join_wall')('https://x.test/checkout/');
$ok(str_contains($w, '성인인증 회원만'), '비회원에게는 왜 가입이 필요한지 말한다');
$ok(str_contains($w, '8,800원 적립'), '무엇을 받는지 같이 적는다');
$ok(str_contains($w, 'redirect_to=') && str_contains($w, 'register'), '가입 뒤 돌아올 곳을 달고 가입 화면으로 보낸다');
$ok(str_contains($w, '로그인'), '이미 회원인 사람에게는 로그인 줄');
$ok(!str_contains($w, '건강') && !str_contains($w, '순한'), '담배사업법이 막는 말을 쓰지 않는다');
$GLOBALS['__filters']['duckhoo_photos_gated'] = [];
add_filter('duckhoo_photos_gated', fn($v = null) => false);
$ok(($F2.'join_wall')('https://x.test/checkout/') === '', '로그인한 손님에게는 아무것도 안 그린다');
$GLOBALS['__filters']['duckhoo_photos_gated'] = [];

/* ── 깔때기 (includes/funnel.php) ────────────────────────────────────────
   비회원과 회원은 같은 길을 걷지 않는다 — 비회원은 결제 화면에 들어가지도 못한다.
   한 표에 몰아 「앞 단계 대비 %」를 적었더니 269% · 500% 가 찍혔다. 그 칸을 뺐다. */
$FN = 'Duckhoo\\Redesign\\Funnel\\';
$paths = ($FN.'paths')();
$ok(!isset($paths['guest']['rows']['checkout']) && !isset($paths['guest']['rows']['order']), '비회원 길에는 결제 · 주문이 없다 — 들어갈 수가 없다');
$ok($paths['guest']['rows']['signup'] === 'member', '「가입 완료」는 회원 쪽 수로 읽는다 (서버가 그렇게 센다)');
$ok(!isset($paths['member']['rows']['register']), '회원 길에는 가입 세 장이 없다');
$stg = ($FN.'stages')();
foreach ($paths as $pk => $pv) { foreach (array_keys($pv['rows']) as $rk) { if(!isset($stg[$rk])) $fail[] = "모르는 단계 $rk"; } }
$ok(true, '두 길의 단계 이름이 모두 실재한다');

$ok(($FN.'ratio_text')(12, 25) === '12 / 25 · 48%', '나눈 두 수를 같이 적는다');
$ok(($FN.'ratio_text')(3, 0) === '—', '나눌 것이 없으면 비율을 만들어 내지 않는다');

$cz = [];
foreach (array_keys($stg) as $k) $cz[$k] = ['guest' => 0, 'member' => 0];
$cz['product'] = ['guest' => 200, 'member' => 50];
$cz['cart_add'] = ['guest' => 40, 'member' => 20];
$cz['register'] = ['guest' => 10, 'member' => 0];
$cz['signup'] = ['guest' => 0, 'member' => 4];
$cz['checkout'] = ['guest' => 0, 'member' => 12];
$cz['order'] = ['guest' => 0, 'member' => 9];
$ls = ($FN.'links')($cz);
$ok(count($ls) === 5, '뜻이 있는 고리 다섯 개');
$ok($ls[0]['top'] === 60 && $ls[0]['bottom'] === 250, '상품 상세 → 담기는 비회원 · 회원을 합쳐 본다');
$ok($ls[1]['top'] === 10 && $ls[1]['bottom'] === 40, '담은 비회원 → 가입 시작은 비회원끼리 나눈다');
$ok($ls[3]['top'] === 12 && $ls[3]['bottom'] === 20, '담은 회원 → 결제 화면은 회원끼리 나눈다');
$ok($ls[4]['top'] === 9 && $ls[4]['bottom'] === 12, '결제 화면 → 주문 완료');



/* ── 후기 부르기 (includes/review-ask.php) ───────────────────────────────
   후기가 0인 이유는 손님이 게으른 것이 아니라 **한 번도 부탁한 적이 없어서**다.
   쓸 길이 상품 상세 맨 아래 한 곳뿐이고, 받은 손님은 그 페이지에 다시 오지 않는다. */
require_once dirname(__DIR__, 2).'/includes/review-ask.php';
$RA = 'Duckhoo\\Redesign\\ReviewAsk\\';

// 「받은」 판정은 후기 구매자 판정과 **같은 목록**을 써야 한다 — 다르면 눌러도 폼이 없다
$ok(($RA.'statuses')() === \Duckhoo\Redesign\Product\bought_statuses(), '후기 판정과 같은 주문 상태를 본다');

$ok(($RA.'received')(new DhrFakeOrder(4001, [], [], [], 'delivered')), '배송완료는 부른다');
$ok(!($RA.'received')(new DhrFakeOrder(4002, [], [], [], 'on-hold')), '입금전에는 안 부른다 — 아직 받지도 않았다');
$ok(!($RA.'received')(null), '주문이 없으면 안 부른다');

$ok(str_contains(($RA.'offer')(), '1,000원'), '얼마를 주는지 적는다');
add_filter('duckhoo_photo_review_on', fn($v = null) => false);
$ok(!str_contains(($RA.'offer')(), '원'), '적립이 꺼져 있으면 돈 이야기를 하지 않는다');
$GLOBALS['__filters']['duckhoo_photo_review_on'] = [];

$ok(str_ends_with(($RA.'write_url')(1), '#respond'), '후기 폼 자리로 바로 보낸다');

// 같은 상품이 두 줄이어도 한 번만 부른다
$GLOBALS['__products'][701] = new WC_Product(701, '[노보] 데저트');
$GLOBALS['__products'][702] = new WC_Product(702, '[펠릭스] 더블라임');
$o = new DhrFakeOrder(4003, [], [], [], 'delivered');
$o->lines = [ new DhrFakeLine($GLOBALS['__products'][701]), new DhrFakeLine($GLOBALS['__products'][701]), new DhrFakeLine($GLOBALS['__products'][702]) ];
$pr = ($RA.'products')($o);
$ok(count($pr) === 2 && $pr[701] === '[노보] 데저트', '같은 상품이 여러 줄이어도 한 번만');

// 이미 쓴 상품은 다시 부르지 않는다
$GLOBALS['__logged_in'] = 5;
$GLOBALS['__comments'] = [ 91 => (object) [ 'comment_post_ID' => 701, 'user_id' => 5 ] ];
$ok(($RA.'reviewed')(5) === [701 => true], '이 회원이 후기를 쓴 상품을 한 번의 질의로 읽는다');
ob_start(); ($RA.'details')($o); $h = (string) ob_get_clean();
$ok(substr_count($h, '후기 쓰기') === 1, '안 쓴 상품에만 버튼을 세운다');
$ok(str_contains($h, '작성함'), '이미 쓴 상품은 고맙다고 적고 버튼을 안 세운다');
$ok(str_contains($h, '[펠릭스] 더블라임'), '상품 이름을 적는다 — 무엇에 쓰는지 알아야 한다');

// 한 주문에 두 번 그리지 않는다 (훅이 두 곳이다)
ob_start(); ($RA.'details')($o); $again = (string) ob_get_clean();
$ok('' === $again, '한 주문에 한 번만 그린다');

// 입금전 주문에는 아예 안 그린다
$o2 = new DhrFakeOrder(4004, [], [], [], 'on-hold');
$o2->lines = [ new DhrFakeLine($GLOBALS['__products'][702]) ];
ob_start(); ($RA.'details')($o2); $ok('' === (string) ob_get_clean(), '받지 않은 주문에는 안 그린다');

// 주문 목록 버튼
$a = ($RA.'action')(['view' => ['url' => '#', 'name' => '보기']], new DhrFakeOrder(4005, [], [], [], 'delivered'));
$ok(isset($a['duckhoo-review']) && str_contains($a['duckhoo-review']['name'], '후기'), '배송완료 카드에 후기 버튼');
$ok(isset($a['view']), '원래 버튼은 그대로');
$ok(!isset(($RA.'action')([], new DhrFakeOrder(4006, [], [], [], 'on-hold'))['duckhoo-review']), '입금전 카드에는 후기 버튼이 없다');

// 전부 끄기
add_filter('duckhoo_review_ask_on', fn($v = null) => false);
ob_start(); ($RA.'details')(new DhrFakeOrder(4007, [], [], [], 'delivered')); $ok('' === (string) ob_get_clean(), '끄면 아무것도 안 그린다');
$GLOBALS['__filters']['duckhoo_review_ask_on'] = [];
$GLOBALS['__comments'] = [];
$GLOBALS['__logged_in'] = 1;



/* ── 담아 둔 뒤 값이 바뀐 장바구니 (includes/price-check.php) ──────────────
   주문 #202609180004850 이 120,000원으로 들어왔다. 지금 담으면 180,000원이다.
   주문에 실린 `_wd_base_price: 70000` 이 답이다 — 담을 때 굳은 옛 기준가.
   테마 금액 검증은 「옵션 행 vs 청구액」만 봐서 둘 다 옛 값이면 통과한다. */
require_once dirname(__DIR__, 2).'/includes/price-check.php';
$PC = 'Duckhoo\\Redesign\\PriceCheck\\';

$ok(($PC.'stored_base')(['wd_base_price' => 70000]) === 70000.0, '줄에 실린 기준가를 읽는다');
$ok(($PC.'stored_base')(['wd_option_builder' => '{"_wd_base_price":"70000"}']) === 70000.0, 'JSON 안의 기준가도 읽는다');
$ok(($PC.'stored_base')(['wd_option_builder' => ['_wd_base_price' => 70000]]) === 70000.0, '배열로 와도 읽는다');
$ok(($PC.'stored_base')(['quantity' => 2]) === 0.0, '기준가가 없으면 0 — 옵션 없는 평범한 상품');

$novo = new WC_Product(146, '[노보 블랙 리퀴드] 10+1', 130000.0);
$bad = ($PC.'stale')(['data' => $novo, 'wd_base_price' => 70000]);
$ok($bad && (int) $bad['gap'] === 60000, '70,000 으로 담은 줄과 지금 130,000 의 차이를 잡는다');
$ok($bad && str_contains($bad['name'], '노보'), '어느 상품인지 이름을 담는다');
$ok(($PC.'stale')(['data' => $novo, 'wd_base_price' => 130000]) === null, '지금 값과 같으면 아무 말도 하지 않는다');
$ok(($PC.'stale')(['data' => $novo, 'wd_base_price' => 129950]) === null, '반올림 오차(100원 미만)는 넘어간다');
$ok(($PC.'stale')(['data' => $novo]) === null, '**기준가를 못 찾으면 막지 않는다** — 모를 때는 건드리지 않는 쪽');
$ok(($PC.'stale')(['wd_base_price' => 70000]) === null, '상품이 없으면 막지 않는다');

/* 세일 상품에서 헛발질하면 결제가 통째로 막힌다 — 정가와 같아도 그냥 둔다 */
$sale = new WC_Product(146, '[노보 블랙 리퀴드] 10+1', 130000.0, true, false, null, 187000.0);
$ok(($PC.'stale')(['data' => $sale, 'wd_base_price' => 187000]) === null, '기준가가 정가와 같으면 막지 않는다 (세일 상품 보호)');
$ok(($PC.'stale')(['data' => $sale, 'wd_base_price' => 130000]) === null, '기준가가 판매가와 같아도 막지 않는다');
$ok(($PC.'stale')(['data' => $sale, 'wd_base_price' => 70000]) !== null, '둘 중 어느 것도 아니면 낡은 것 — 실제 사고의 70,000');

/* 2026-09-18 18:44 사장님 진단 캡처 — 새로 담은 줄인데 막혔다. 기준가 130,000 은 맞았고
   장바구니 객체(`data`)의 가격이 **옵션까지 합쳐진 160,000** 이었다. 상품을 새로 읽어 견준다. */
$GLOBALS['__products'][146] = new WC_Product(146, '[노보 블랙 리퀴드] 10+1', 130000.0, true, false, null, 187000.0);
$inCart = new WC_Product(146, '[노보 블랙 리퀴드] 10+1', 160000.0, true, false, null, 187000.0);
$ok(($PC.'stale')(['product_id' => 146, 'data' => $inCart, 'wd_base_price' => 130000]) === null, '**장바구니 객체가 옵션 포함 160,000 이어도** 새로 읽은 130,000 과 견줘 통과 — 사장님을 막았던 그 경우');
$still = ($PC.'stale')(['product_id' => 146, 'data' => $inCart, 'wd_base_price' => 70000]);
$ok($still && (int) $still['now'] === 130000 && (int) $still['gap'] === 60000, '사고의 70,000 은 여전히 걸리고, 「지금 값」은 160,000 이 아니라 새로 읽은 130,000 이다');
$ok(($PC.'stale')(['product_id' => 999, 'data' => $novo, 'wd_base_price' => 130000]) === null, '번호로 못 읽으면 장바구니 객체로 물러난다');
unset($GLOBALS['__products'][146]);

$over = ($PC.'stale')(['data' => $novo, 'wd_base_price' => 200000]);
$ok($over && (int) $over['gap'] === -70000, '더 받게 되는 쪽도 잡는다 (손님이 손해)');

$msg = ($PC.'notice')('[노보 블랙 리퀴드] 10+1');
$ok(str_contains($msg, '다시 담아'), '안내는 할 일을 말한다 — 빼고 다시 담기');
$ok(str_contains($msg, '나머지 상품은'), '나머지는 그대로 둬도 된다고 알려 준다');
$ok(!str_contains($msg, '<'), 'HTML 을 넣지 않는다 — 예외 메시지가 esc_html 로 나간다');

add_filter('duckhoo_price_check', fn($v = null) => false);
$ok(($PC.'bad_lines')(null) === [], '끄면 아무것도 안 잡는다');
$GLOBALS['__filters']['duckhoo_price_check'] = [];


/* ── 쿠폰 사용 현황 글자 ───────────────────────────────────────────────── */
$CA = 'Duckhoo\\Redesign\\Coupon\\Admin\\';
$ok(strip_tags(($CA.'used_text')(17, 64)) === '17 / 64명', '공용 코드는 「쓴 수 / 한도」 — 64명 중 17명');
$ok(strip_tags(($CA.'used_text')(64, 64)) === '64 / 64명 (다 씀)', '한도를 다 채우면 「다 씀」');
$ok(strip_tags(($CA.'used_text')(0, 64)) === '— / 64명', '아직 안 썼으면 대시 · 한도는 보인다');
$ok(strip_tags(($CA.'used_text')(3, 0)) === '3회', '한도 없는 코드는 횟수만');
$ok(($CA.'used_text')(0, 0) === '—', '한도도 없고 안 썼으면 대시');

/* ── 노보 분류 페이지 제목 · 설명 — 손으로 쓴 「병당 7,000원」을 대신한다 (2026-09-21) ─── */
$S2 = 'Duckhoo\\Redesign\\Seo\\';
$keepP = $GLOBALS['__products'];
$GLOBALS['__products'] = [
  238 => new WC_Product(238, '[노보] 블랙멘솔 (9.8mg / 30ml)', 13000.0, true, false, null, 16000.0),
  247 => new WC_Product(247, '[노보 블랙] 데저트 (9.8mg / 30ml)', 13500.0, true, false, null, 17000.0),
  4327 => new WC_Product(4327, '[노보 리퀴드] 10+1 | 금액 120,000원', 120000.0, true, false, null, 176000.0),
  9 => new WC_Product(9, '[노보] 품절맛 (9.8mg / 30ml)', 9000.0, false),
];
$GLOBALS['__is_ptax'] = true; $GLOBALS['__qobj'] = (object) ['slug' => 'novo-liquid', 'name' => '노보 액상', 'description' => ''];
$ok(($S2.'title')('노보 액상 가격 8종 | 10병 특가 병당7,000원') === '노보 액상 3종 전 라인 재고 보유 · 바로 주문 | 액상덕후', '노보 분류 제목은 우리가 쓴다 — 종수는 재고 있는 상품만 센다 (품절 1개 제외)');
$d = ($S2.'description')('노보(NOVO) 액상 가격 안내: 10병 묶음 특가 병당 7,000원');
$ok(str_contains($d, '재고를 보유') && str_contains($d, '낱병 13,000원부터') && str_contains($d, '10+1 묶음(11병) 120,000원') && str_contains($d, '병당 약 10,900원'), '설명의 값은 상품에서 읽는다 — 낱병 최저가 · 묶음 · 병당');
$ok(!str_contains($d, '7,000'), '손으로 쓴 옛 값(7,000원)은 검색 결과로 나가지 않는다');
$ok(!preg_match('/건강|금연|순하|해롭지/u', $d), '광고 제한 낱말이 없다');
ob_start(); ($S2.'head')(); $hh = ob_get_clean();
$ok(str_contains($hh, 'og:title" content="노보 액상 3종 전 라인 재고 보유 · 바로 주문 | 액상덕후"') && str_contains($hh, 'og:description" content="액상덕후는 노보'), '노보 분류에는 og 를 우리가 찍는다 — 카카오톡 미리보기용 (AIOSEO 가 분류에는 안 찍는다)');
$GLOBALS['__qobj'] = (object) ['slug' => 'other-cat', 'name' => '기기 / 팟 / 코일', 'description' => ''];   // 2026-10-01: 입호흡 · 폐호흡은 이제 우리가 쓴다 — 다른 분류로 본다
$ok(($S2.'title')('그대로') === '그대로' && str_contains(($S2.'description')('사장님 글'), '사장님 글'), '다른 분류는 손대지 않는다');
$GLOBALS['__qobj'] = (object) ['slug' => 'novo-liquid', 'name' => '노보 액상', 'description' => ''];
add_filter('duckhoo_cat_notes', fn($v = null) => []);
$ok(($S2.'title')('그대로') === '그대로' && str_contains(($S2.'description')('사장님 글'), '사장님 글'), '필터를 비우면 AIOSEO 글로 돌아간다');
$GLOBALS['__filters']['duckhoo_cat_notes'] = []; $GLOBALS['__is_ptax'] = false; unset($GLOBALS['__qobj']); $GLOBALS['__products'] = $keepP;

/* ── 후기 화면 (includes/review-ui.php) ──────────────────────────────────── */
require_once dirname(__DIR__, 2).'/includes/review-ui.php';
$RU = 'Duckhoo\\Redesign\\ReviewUi\\';
$ok(($RU.'mask')('김시원') === '김**', '이름은 첫 글자만 — 김시원 → 김**');
$ok(($RU.'mask')('kkuromi1004') === 'k*****', '긴 아이디는 별표 다섯 개까지');
$ok(($RU.'no_verified_label')('yes') === 'no', '「(인증된 구매자)」 알약은 안 그린다 — 산 사람만 쓰는 가게라 되풀이다');
add_filter('duckhoo_review_verified_label', fn($v = null) => true);
$ok(($RU.'no_verified_label')('yes') === 'yes', '필터로 되살리면 설정값 그대로');
$GLOBALS['__filters']['duckhoo_review_verified_label'] = [];

/* ── 응대 시간 · 출고 규칙 · 안내 띠 (includes/front.php · novo.php · pages.php) ─── */
$F = 'Duckhoo\\Redesign\\Front\\';
$ok(($F.'hours_text')() === '평일 11:00–18:00 · 점심 12:00–13:00 · 주말 · 법정 공휴일 휴무', '응대 시간은 평일 11–18시 (사장님 2026-09-21)');
$ok(($F.'hours_lines')()[1] === '주말 · 법정 공휴일 휴무', '두 번째 줄은 휴무');
$ok(str_contains(($F.'ship_rule')(), '금요일 오후 4시 이후') && str_contains(($F.'ship_rule')(), '월요일 오후 4시'), '출고 규칙: 금요일 마감 뒤 · 주말 주문은 월요일 오후 4시');
$keepP2 = $GLOBALS['__products']; $GLOBALS['__transients'] = [];
$GLOBALS['__products'] = [
  238 => new WC_Product(238, '[노보] 블랙멘솔 (9.8mg / 30ml)', 13000.0, true),
  242 => new WC_Product(242, '[노보] 타박멘솔 (9.8mg / 30ml)', 13000.0, true),
  247 => new WC_Product(247, '[노보 블랙] 데저트 (9.8mg / 30ml)', 13500.0, true),
  4327 => new WC_Product(4327, '[노보 리퀴드] 10+1 | 금액 120,000원', 120000.0, true),
  146 => new WC_Product(146, '[노보 블랙 리퀴드] 10+1 | 금액 130,000원', 130000.0, true),
  9 => new WC_Product(9, '[노보] 품절맛 (9.8mg / 30ml)', 9000.0, false),
  901 => new WC_Product(901, '[펠릭스] 더블라임 (9.8mg / 30ml)', 20000.0, true),
];
$NV = 'Duckhoo\\Redesign\\Novo\\';
$pl = ($NV.'price_lines')();
$ok(count($pl) === 4 && $pl[0]['label'] === '노보 낱병' && $pl[0]['price'] == 13000 && $pl[1]['label'] === '노보 블랙 낱병' && $pl[2]['label'] === '노보 10+1 (11병)' && $pl[2]['price'] == 120000 && $pl[3]['price'] == 130000, '현재 판매가 네 줄 — 라인 × 낱병/묶음, 재고 있는 것의 최저가 (품절 9,000 제외)');
$pn = ($NV.'price_notice_text')();
$ok(str_starts_with($pn, '노보 액상 가격이 인상되었습니다.') && str_contains($pn, '노보 낱병 13,000원') && str_contains($pn, '노보 블랙 10+1 (11병) 130,000원') && !str_contains($pn, '9,000') && str_contains($pn, '다시 담아'), '가격 인상 안내 글은 현재 판매가를 상품에서 읽어 엮는다 · 다시 담기 안내');
$GLOBALS['__options']['duckhoo_novo_price_notice'] = '사장님이 쓴 안내';
$ok(($NV.'price_notice_text')() === '사장님이 쓴 안내', '관리자에 쓴 글이 있으면 그것');
$GLOBALS['__options']['duckhoo_novo_price_notice'] = '';
$GLOBALS['__now'] = strtotime('2026-09-21 12:00:00');
$ns = ($F.'notices')();
$ok(count($ns) === 3 && $ns[0]['id'] === 'hours' && $ns[1]['id'] === 'ship' && $ns[2]['id'] === 'novo-price', '안내 세 개 — 응대 시간 · 출고 규칙 · 노보 가격');
$ok($ns[0]['eb'] === '고객센터' && $ns[0]['k'] === '평일 11:00–18:00' && str_contains($ns[0]['s'], '점심 12:00–13:00') && str_contains($ns[0]['s'], '휴무') && $ns[1]['eb'] === '배송' && str_contains($ns[1]['k'], '월요일') && $ns[2]['k'] === '가격 인상 안내' && str_contains($ns[2]['s'], '낱병 13,000원') && str_contains($ns[2]['s'], '블랙 10+1 130,000원') && !str_contains($ns[2]['s'], '노보 낱병'), '카드: 이름표 · 굵은 한 줄 · 회색 한 줄 — 누르지 않아도 핵심이 다 읽힌다');
$GLOBALS['__options']['duckhoo_novo_price_until'] = '2026-09-20';
$ok(count(($F.'notices')()) === 2, '종료일이 지나면 노보 가격 안내는 빠진다');
$GLOBALS['__options']['duckhoo_novo_price_until'] = '2026-09-21';
$ok(count(($F.'notices')()) === 3, '종료일 당일까지는 보인다');
$GLOBALS['__options']['duckhoo_novo_price_until'] = '';
$h = ($F.'notice_bar_html')();
$ok(substr_count($h, 'class="dhn__row dhn__row--') === 3 && substr_count($h, '<a class="dhn__row ') === 3 && str_contains($h, 'dhn__row--novo-price') && str_contains($h, 'data-dhn="') && str_contains($h, 'data-dhn-close') && !str_contains($h, 'aria-expanded') && !str_contains($h, 'dhn__ic') && substr_count($h, 'class="dhn__s"') === 3, '띠: 줄 셋(전부 링크) · 해시 · 닫기 · 접는 것 · 아이콘 없음');
$ok(str_contains($h, '/shipping/') && str_contains($h, '노보 보기'), '출고 규칙은 배송 안내로, 가격 안내는 노보 목록으로 이어진다');
$GLOBALS['__filters']['duckhoo_notices'] = [fn($v) => []];
$ok(($F.'notice_bar_html')() === '', '필터로 다 빼면 띠 자체가 없다');
$GLOBALS['__filters']['duckhoo_notices'] = [];
foreach (['건강','금연','순하','해롭'] as $bad) { $ok(!str_contains($h, $bad), "띠에 「{$bad}」 없음"); }
// 연휴(추석) — 2026-09-22 사장님: 24(목)~27(일) 출고 없음 · 23(수)은 출고되지만 배송 안 됨 · 28(월)은 밀린 물량으로 지연 가능
$GLOBALS['__filters']['duckhoo_holidays'] = [fn($v) => [['name'=>'추석 연휴','show_from'=>'2026-09-22','ship_only'=>'2026-09-23','from'=>'2026-09-24','to'=>'2026-09-27','backlog'=>'2026-09-28']]];   // 2026-10-02: 기본값이 10월 연휴로 바뀌어 추석은 필터로 못 박는다
$GLOBALS['__now'] = strtotime('2026-09-21 12:00:00');
$ok(($F.'holiday_notice')() === null, '보이기 시작하는 날 전에는 연휴 안내가 없다');
$GLOBALS['__now'] = strtotime('2026-09-22 12:00:00');
$hn = ($F.'holiday_notice')();
$ok(is_array($hn) && $hn['id'] === 'holiday' && $hn['eb'] === '추석 연휴' && $hn['k'] === '9월 24일(목)–27일(일) 택배 출고가 없습니다', '연휴 줄: 이름표 · 출고 없는 날짜를 요일과 함께');
$ok(str_contains($hn['s'], '23일(수) 출고분은 연휴 뒤에 도착합니다') && str_contains($hn['s'], '28일(월)은 밀린 물량으로 출고가 늦어질 수 있습니다'), '회색 줄: 23일 출고분은 연휴 뒤 도착 · 28일 지연 가능');
$ns = ($F.'notices')();
$ok(count($ns) === 3 && $ns[0]['id'] === 'holiday' && $ns[1]['id'] === 'hours' && $ns[2]['id'] === 'novo-price', '연휴 중에는 연휴 줄이 맨 앞, 평소 출고 규칙 줄은 뺀다');
$h2 = ($F.'notice_bar_html')();
$ok(str_contains($h2, 'dhn__row--holiday') && !str_contains($h2, 'dhn__row--ship') && $h2 !== $h, '띠에 연휴 줄이 그려지고 해시가 바뀐다 (닫아 둔 사람에게도 다시 보인다)');
$ok(($F.'announce')() === '추석 연휴 9월 24일(목)–27일(일) 택배 출고 없음' && mb_strlen(($F.'announce')()) <= 40, '홈 검은 띠도 연휴 동안은 출고 안내 — 40자 안');
$GLOBALS['__now'] = strtotime('2026-09-25 12:00:00');
$hn = ($F.'holiday_notice')();
$ok(str_starts_with($hn['k'], '9월 24일(목)') && !str_contains($hn['s'], '23일') && str_contains($hn['s'], '28일(월)'), '23일이 지나면 그 줄은 빠지고 28일 지연 안내는 남는다');
$GLOBALS['__now'] = strtotime('2026-09-28 12:00:00');
$hn = ($F.'holiday_notice')();
$ok($hn['k'] === '연휴 동안 밀린 물량으로 출고가 늦어질 수 있습니다' && str_contains($hn['s'], '순서대로') && str_contains(($F.'announce')(), '밀린 물량'), '연휴 뒤 월요일에는 지연 안내만');
$GLOBALS['__now'] = strtotime('2026-09-29 12:00:00');
$ns = ($F.'notices')();
$ok(($F.'holiday_notice')() === null && count($ns) === 3 && $ns[1]['id'] === 'ship' && str_contains(($F.'announce')(), '노보'), '지나면 저절로 빠지고 평소 출고 규칙 · 노보 문구로 돌아간다');
$GLOBALS['__now'] = strtotime('2026-09-25 12:00:00');
$GLOBALS['__filters']['duckhoo_holiday'] = [fn($v) => ['from' => '', 'to' => '']];
$ok(($F.'holiday_notice')() === null, '필터로 날짜를 비우면 연휴 안내가 없다');
$GLOBALS['__filters']['duckhoo_holiday'] = [];
$GLOBALS['__filters']['duckhoo_holidays'] = [];
// 2026-10-02 — 10월 연휴 둘 (개천절 3~5 · 한글날 9~11), 사장님 포스터. 둘이 붙어 있어 한 줄에 같이 적고, 지나면 저절로 빠진다
$GLOBALS['__now'] = strtotime('2026-10-02 12:00:00');
$hn = ($F.'holiday_notice')();
$ok(is_array($hn) && $hn['eb'] === '개천절 · 한글날 연휴' && $hn['k'] === '10월 3일(토)–5일(월) · 9일(금)–11일(일) 택배 출고가 없습니다', '10월: 두 연휴를 한 줄에');
$ok(str_contains($hn['s'], '6일(화) · 12일(월)부터 순차 출고합니다') && str_contains($hn['s'], '휴무 중에도 주문은 됩니다'), '순차 출고일 둘 · 주문은 된다');
$ok(($F.'announce')() === '개천절 · 한글날 연휴 10월 3일(토)–5일(월) · 9일(금)–11일(일) 택배 출고 없음', '홈 검은 띠');
$GLOBALS['__now'] = strtotime('2026-10-06 12:00:00');
$hn = ($F.'holiday_notice')();
$ok(str_contains($hn['k'], '밀린 물량') && str_contains($hn['s'], '9일(금)–11일(일)도 택배 출고가 없습니다'), '6일(화): 밀린 물량 안내 + 다음 연휴 예고');
$GLOBALS['__now'] = strtotime('2026-10-08 12:00:00');
$hn = ($F.'holiday_notice')();
$ok($hn['eb'] === '한글날 연휴' && $hn['k'] === '10월 9일(금)–11일(일) 택배 출고가 없습니다' && str_contains($hn['s'], '12일(월)부터 순차 출고합니다') && !str_contains($hn['k'], '3일'), '개천절이 지나면 한글날만');
$GLOBALS['__now'] = strtotime('2026-10-13 12:00:00');
$ns = ($F.'notices')();
$ok(($F.'holiday_notice')() === null && $ns[1]['id'] === 'ship' && str_contains(($F.'announce')(), '노보'), '12일이 지나면 원래대로 — 평소 출고 줄 · 노보 띠');
$GLOBALS['__now'] = strtotime('2026-09-21 12:00:00');
$ok(($F.'kday')('2026-10-03') === '10월 3일(토)' && ($F.'kday')('2026-10-03', false) === '3일(토)', '날짜는 「10월 3일(토)」 꼴');
$GLOBALS['__now'] = strtotime('2026-09-21 12:00:00');
$GLOBALS['__products'] = $keepP2; $GLOBALS['__transients'] = [];
// 안내 페이지 — 우리가 넣은 뒤 손대지 않은 것만 새 글로
if (!function_exists('get_page_by_path')) { function get_page_by_path($slug, $o = null, $t = 'page'){ return $GLOBALS['__pages'][$slug] ?? null; } }
if (!function_exists('wp_insert_post')) { function wp_insert_post($a){ $GLOBALS['__inserted'][] = $a; return 500 + count($GLOBALS['__inserted']); } }
if (!function_exists('wp_update_post')) { function wp_update_post($a){ $GLOBALS['__updated'][] = $a; return (int)$a['ID']; } }
require_once dirname(__DIR__, 2).'/includes/pages.php';
$PG = 'Duckhoo\\Redesign\\Pages\\';
$defs = ($PG.'definitions')();
$ok(str_contains($defs['shipping']['content'], '월요일 오후 4시') && str_contains($defs['shipping']['content'], '11:00–18:00') && !str_contains($defs['shipping']['content'], '10:00'), '배송 안내 페이지 글에 주말 규칙 · 새 응대 시간');
$mk = fn($id, $slug, $content, $mod, $meta = '') => (object)['ID' => $id, 'post_name' => $slug, 'post_content' => $content, 'post_date_gmt' => '2026-09-04 01:00:00', 'post_modified_gmt' => $mod];
$GLOBALS['__pages'] = ['shipping' => $mk(11, 'shipping', '옛 글', '2026-09-04 01:00:00'), 'terms' => $mk(12, 'terms', '사장님이 고친 글', '2026-09-10 09:00:00'), 'privacy' => $mk(13, 'privacy', '옛 글', '2026-09-04 01:00:00')];
$GLOBALS['__postmeta'][13]['_dhr_pages_hash'] = md5('우리가 마지막에 쓴 글'); // 해시가 다르다 = 사람이 고쳤다
$GLOBALS['__options']['duckhoo_pages_version'] = 1; $GLOBALS['__updated'] = []; $GLOBALS['__inserted'] = [];
($PG.'ensure')();
$ok(count($GLOBALS['__updated']) === 1 && $GLOBALS['__updated'][0]['ID'] === 11 && str_contains($GLOBALS['__updated'][0]['post_content'], '월요일 오후 4시'), '손대지 않은 배송 페이지만 새 글로 바꾼다 (약관은 수정된 흔적 · 개인정보는 해시가 달라 그대로)');
$ok(($GLOBALS['__postmeta'][11]['_dhr_pages_hash'] ?? '') === md5(trim($defs['shipping']['content'])) && $GLOBALS['__options']['duckhoo_pages_version'] === \Duckhoo\Redesign\Pages\VERSION && count($GLOBALS['__inserted']) === 3 && $GLOBALS['__inserted'][0]['post_name'] === 'price' && $GLOBALS['__inserted'][1]['post_name'] === 'liquid-guide' && $GLOBALS['__inserted'][2]['post_name'] === 'mtl-vs-dl', '바꾼 글의 해시를 남기고 버전을 올린다 · 새로 만드는 것은 없던 /price/ · 안내 글 두 장(2026-10-01)');
$GLOBALS['__options']['duckhoo_pages_version'] = \Duckhoo\Redesign\Pages\VERSION; $GLOBALS['__updated'] = [];
($PG.'ensure')();
$ok(!$GLOBALS['__updated'], '버전이 같으면 아무것도 안 한다');
$GLOBALS['__pages'] = [];


/* ── 성인인증 점검 (includes/verify-admin.php) — 읽기 전용 화면의 갈래짓기 · 요약 ───────────── */
require_once dirname(__DIR__, 2).'/includes/verify-gate.php';
require_once dirname(__DIR__, 2).'/includes/verify-admin.php';
$V = 'Duckhoo\\Redesign\\Verify\\Admin\\';
$ok(($V.'two_char')('길동') && ($V.'two_char')('길 동') && !($V.'two_char')('홍길동') && !($V.'two_char')('Kim') && !($V.'two_char')('') && !($V.'two_char')('김a'), '두 글자 이름: 한글 두 글자뿐일 때만 (빈칸은 뗀다 · 영문 · 빈 이름 아님)');
$now = strtotime('2026-09-24 12:00:00 UTC');
$c = ($V.'classify')(['id'=>1,'name'=>'길동','verified'=>false,'orders'=>2,'last'=>'2026-09-01 10:00:00'], $now);
$ok($c['two_char'] && $c['recent'] && $c['orders'] === 2 && $c['verified'] === false, '갈래짓기: 두 글자 · 최근 90일 주문 · 주문 수');
$c2 = ($V.'classify')(['id'=>2,'name'=>'홍길동','verified'=>true,'orders'=>0,'last'=>''], $now);
$ok(!$c2['two_char'] && !$c2['recent'] && $c2['verified'], '갈래짓기: 주문 없으면 최근 아님 · 인증 있음');
$c3 = ($V.'classify')(['id'=>3,'name'=>'홍길동','verified'=>false,'orders'=>1,'last'=>'2026-05-01 10:00:00'], $now);
$ok(!$c3['recent'], '90일 넘은 주문은 최근이 아니다');
$sm = ($V.'summary')([$c, $c2, $c3]);
$ok($sm['all'] === 3 && $sm['verified'] === 1 && $sm['unverified'] === 2 && $sm['unv_orders'] === 2 && $sm['unv_recent'] === 1 && $sm['two_char'] === 1 && $sm['two_char_unv'] === 1, '요약: 전체 3 · 인증 1 · 없음 2 · 없음+주문 2 · 없음+최근 1 · 두 글자 1');
$ok(($V.'meta_key')() === 'wd_phone_verified' && in_array('administrator', ($V.'staff_roles')(), true) && in_array('wc-cancelled', ($V.'dead_statuses')(), true), '기본값: 테마의 wd_phone_verified · 직원 역할 제외 · 취소 주문 안 셈');
$c4 = ($V.'classify')(['id'=>4,'name'=>'길동','vname'=>'홍길동','verified'=>true,'orders'=>3,'last'=>''], $now);
$c5 = ($V.'classify')(['id'=>5,'name'=>'홍 길동','vname'=>'홍길동','verified'=>true,'orders'=>0,'last'=>''], $now);
$c6 = ($V.'classify')(['id'=>6,'name'=>'길동','vname'=>'','verified'=>false,'orders'=>0,'last'=>''], $now);
$ok($c4['differs'] && !$c5['differs'] && !$c6['differs'], '회원 이름 ≠ 인증 이름: 두 글자 vs 세 글자는 다름 · 빈칸 차이는 같음 · 인증 이름 없으면 셈 안 함');
$ok(($V.'summary')([$c4,$c5,$c6])['differs'] === 1, '요약에 「이름 다름」 수');
$ok(($V.'norm_phone')('010-1234-5678') === '01012345678' && ($V.'norm_phone')('+82 10 1234 5678') === '01012345678' && ($V.'norm_phone')('02-123-4567') === '' && ($V.'norm_phone')('') === '', '전화번호 정규화: 하이픈 · +82 · 유선번호는 제외');
$ro = ($V.'parse_roster')("이름,이메일,휴대폰\n홍길동,hong@x.com,010-1234-5678\n김철수,kim@y.com,+82 10-9999-0000\n\n박영희,,010 5555 1234");
$ok($ro['lines'] === 4 && count($ro['phones']) === 3 && isset($ro['phones']['01012345678']) && isset($ro['phones']['01099990000']) && isset($ro['phones']['01055551234']) && count($ro['emails']) === 2 && isset($ro['emails']['hong@x.com']), '명단 읽기: 줄 · 번호 세 꼴 · 이메일');
$cx = ($V.'cross')([
  ['id'=>1,'verified'=>true,'phone'=>'01012345678','email'=>'hong@x.com'],
  ['id'=>2,'verified'=>false,'phone'=>'01012345678','email'=>'other@z.com'],
  ['id'=>3,'verified'=>false,'phone'=>'','email'=>'KIM@y.com'],
  ['id'=>4,'verified'=>false,'phone'=>'01000000001','email'=>'no@z.com'],
], $ro);
$ok(count($cx) === 3 && $cx[0]['hit'] === 'phone' && $cx[1]['hit'] === 'email' && $cx[2]['hit'] === '', '대조: 인증 있는 회원은 빼고 · 번호 먼저 · 번호 없으면 이메일(대소문자 무시) · 둘 다 없으면 빈 값');
/* ── 옛 회원 재인증 문 (includes/verify-gate.php) ───────────────────────────────────────── */
$G = 'Duckhoo\\Redesign\\Verify\\';
$GLOBALS['__usermeta'][10] = ['wd_phone_verified' => '1'];
$GLOBALS['__usermeta'][11] = [];
$GLOBALS['__usermeta'][12] = [];
$ok(!($G.'needs_reverify')(10) && ($G.'needs_reverify')(11) && !($G.'needs_reverify')(0), '인증 기록 있으면 안 묻고, 둘 다 없으면 묻는다 · 비로그인(0)은 아님');
$ok(($G.'mark_legacy')(11, 'imweb') === true && !($G.'needs_reverify')(11) && ($G.'mark_legacy')(11) === false && $GLOBALS['__usermeta'][11]['_dhr_legacy_verified'] === 'imweb' && !empty($GLOBALS['__usermeta'][11]['_dhr_legacy_verified_at']), '옛 사이트 확인 표시를 남기면 안 묻는다 · 두 번 안 적는다 · 출처와 날짜');
$GLOBALS['__filters']['duckhoo_reverify_gate'] = [fn() => false];
$ok(!($G.'needs_reverify')(12), '필터로 문을 끄면 아무도 안 묻는다');
$GLOBALS['__filters']['duckhoo_reverify_gate'] = [];
$GLOBALS['__userroles'] = [13 => ['administrator'], 14 => ['customer']];
$ok(!($G.'needs_reverify')(13) && ($G.'needs_reverify')(14), '관리자는 재인증 문에서 빠지고 · 손님은 그대로 묻는다');
$redir = function (int $uid, bool $checkout): string {
  $GLOBALS['__logged_in'] = $uid; $GLOBALS['__is_checkout'] = $checkout; $GLOBALS['__redirect'] = '';
  try { ('Duckhoo\\Redesign\\Verify\\gate')(); } catch (\RuntimeException $e) {}
  return (string) $GLOBALS['__redirect'];
};
$ok(str_contains($redir(12, true), '/profile-edit/') && str_contains($redir(12, true), 'dhr_reverify=1'), '재인증 대상이 결제 화면에 오면 테마 재인증 화면으로 보낸다');
$ok($redir(10, true) === '' && $redir(11, true) === '' && $redir(12, false) === '' && $redir(0, true) === '', '인증 있음 · 옛 사이트 확인 · 결제 화면 아님 · 비로그인은 그대로');
$GLOBALS['__logged_in'] = 0; $GLOBALS['__is_checkout'] = false;
$sm2 = ($V.'summary')([
  ['verified'=>false,'legacy'=>true,'orders'=>0], ['verified'=>false,'legacy'=>false,'orders'=>1], ['verified'=>true,'orders'=>0],
]);
$ok($sm2['unverified'] === 2 && $sm2['legacy'] === 1 && $sm2['gate'] === 1, '요약: 인증 없음 2 = 옛 사이트 확인 1 + 재인증 대상 1');

/* ── 입금 2차 판정 (includes/bank-second.php) — 읽기 전용 판정 규칙 ────────────────────── */
require_once dirname(__DIR__, 2).'/includes/bank-second.php';
$B = 'Duckhoo\\Redesign\\Bank2\\';
$ok(($B.'norm')(' 홍 길동 (a)') === '홍길동a' && ($B.'norm')('') === '', '정규화: 공백 · 괄호 제거 · 소문자 (키플과 같게)');
$v = ($B.'variants')('금고홍길동');
$ok(in_array('금고홍길동', $v, true) && in_array('홍길동', $v, true), '「금고홍길동」 → 원문 + 「홍길동」 (키플에 없던 「금고」 접두어)');
$ok(in_array('최훈영', ($B.'variants')('토스최훈영'), true) && ($B.'variants')('') === [], '「토스최훈영」 → 「최훈영」 · 빈 이름은 없음');
$ok(($B.'tail_match')('금고홍길동', ['홍길동']) === '홍길동' && ($B.'tail_match')('금고홍길동', ['길동']) === '길동' && ($B.'tail_match')('홍길동', ['길동']) === '길동', '끝 일치: 세 글자 · 두 글자(옛 회원) 둘 다 잡는다');
$ok(($B.'tail_match')('농협이상섭', ['이상']) === '' && ($B.'tail_match')('농협이상섭', ['상섭']) === '상섭' && ($B.'tail_match')('김민수', ['박민수']) === '', '들어 있기만 하면 안 되고 끝이라야 한다 · 다른 성은 안 맞는다');
$p = ($B.'pick')('금고홍길동', [101 => ['홍길동'], 102 => ['김철수']]);
$ok($p['verdict'] === 'match' && $p['order_id'] === 101 && $p['name'] === '홍길동', '후보 둘 중 하나만 맞으면 「이 주문」');
$ok(($B.'pick')('금고홍길동', [101 => ['홍길동'], 103 => ['길동']])['verdict'] === 'multi' && ($B.'pick')('금고홍길동', [102 => ['김철수']])['verdict'] === 'none' && ($B.'pick')('금고홍길동', [])['verdict'] === 'none', '둘 이상 맞으면 「후보 여럿」 · 없으면 「못 찾음」 — 어느 쪽도 주문을 고르지 않는다');
$fo = new class { public function get_billing_last_name(){ return ''; } public function get_billing_first_name(){ return '길동'; } public function get_shipping_first_name(){ return '홍길동'; } public function get_shipping_last_name(){ return ''; } public function get_formatted_billing_full_name(){ return '길동'; } public function get_formatted_shipping_full_name(){ return '홍길동'; } public function get_meta($k){ return $k === '_deposit_payer_name' ? '홍 길동' : ''; } };
$nm = ($B.'names_of')($fo);
$ok(in_array('길동', $nm, true) && in_array('홍길동', $nm, true) && count($nm) === 2, '주문 쪽 이름: 청구 · 배송 · 예금주 메타를 모아 중복 없이');
$j = ($B.'judge')(['id' => 7, 'depositor_name' => '농협이상섭', 'amount' => 0, 'match_reason' => '확인필요: SMS 파싱 실패']);
$ok($j['verdict'] === 'parse' && ($B.'judge')(['id' => 8, 'depositor_name' => '홍길동', 'amount' => 1000, 'match_reason' => '확인필요: 과입금'])['verdict'] === 'skip', '금액 0 은 「해석 실패」 · 입금자명 사유가 아니면 건너뜀');
$ok(($B.'guess_amounts')("[Web발신]\n농협 09/24 13:05\n금액:78,900\n이상섭\n잔액 1,234,567") === [1234567, 78900] && ($B.'guess_amounts')('2026/09/24 12:00 500원') === [], '해석 실패 원문의 금액 후보: 1,000 이상 숫자만 · 연도 · 날짜 · 계좌 조각 제외');

/* ── 오늘 할 일 (includes/today.php) — 사실 → 목록 순수 함수 ─────────────────────────── */
if(!function_exists('wp_add_dashboard_widget')) { function wp_add_dashboard_widget(...$a){} }
if(!function_exists('wp_next_scheduled')) { function wp_next_scheduled($h){ return false; } }
if(!function_exists('wp_schedule_event')) { function wp_schedule_event(...$a){ $GLOBALS['__cron'][] = $a; return true; } }
if(!function_exists('wp_unschedule_event')) { function wp_unschedule_event(...$a){ return true; } }
require_once dirname(__DIR__, 2).'/includes/today.php';
$T = 'Duckhoo\\Redesign\\Today\\';
$facts0 = ['onhold'=>['n'=>0,'stale'=>0],'check'=>['n'=>0],'sms'=>0,'to_ship'=>['n'=>0],'no_track'=>['n'=>0],'stuck'=>['n'=>0],'inq'=>['n'=>-1],'reviews'=>0,'stock'=>['out'=>0,'low'=>[]],'coupons'=>0,'yday'=>['orders'=>0,'sales'=>0,'signups'=>0],'holiday'=>[]];
$ctx0 = ['today'=>'2026-09-24','dow'=>4,'hour'=>9,'stale_days'=>5,'urls'=>['onhold'=>'U1','to_ship'=>'U2']];
$it = ($T.'build')($facts0, $ctx0);
$ids = array_column($it, 'id');
$ok(array_search('stale',$ids,true) < array_search('ship',$ids,true) && array_search('ship',$ids,true) < array_search('reviews',$ids,true) && array_search('reviews',$ids,true) < array_search('out',$ids,true), '순서: 입금 → 출고 → 손님 → 가게');
$ok(!in_array('inq',$ids,true), 'kboard 표 구조를 모르면(-1) 문의 항목을 아예 안 그린다 — 틀린 숫자보다 없는 숫자');
$ok(!in_array('weekly',$ids,true), '목요일에는 주간 점검 항목이 없다');
$ok(count(array_filter($it, fn($i)=>empty($i['info']) && (int)$i['n']>0)) === 0, '아무것도 없는 날은 열린 항목 0');
$it2 = ($T.'build')(['onhold'=>['n'=>7,'stale'=>2],'check'=>['n'=>1],'sms'=>3,'to_ship'=>['n'=>4],'no_track'=>['n'=>0],'stuck'=>['n'=>0],'inq'=>['n'=>2,'url'=>'/inquiries/'],'reviews'=>1,'stock'=>['out'=>3,'low'=>[11=>'노보 데저트 2개']],'coupons'=>0,'yday'=>[],'holiday'=>[]] , ['dow'=>1,'hour'=>17,'stale_days'=>5,'urls'=>[]]);
$by = array_column($it2, null, 'id');
$ok($by['stale']['n']===2 && str_contains($by['stale']['title'],'5일') && $by['stale']['tone']==='hot', '입금전 5일 넘은 주문 2건 · 급함 표시');
$ok($by['onhold']['n']===7 && !empty($by['onhold']['info']), '입금 기다리는 주문 7건은 숫자만(할 일 아님)');
$ok($by['inq']['n']===2 && $by['inq']['url']==='/inquiries/', '답 없는 문의 2건은 게시판으로');
$ok(str_contains($by['ship']['note'],'오후 4시가 지나'), '17시에는 「내일 출고분」이라고 말한다');
$ok(str_contains($by['low']['note'],'노보 데저트 2개') && $by['low']['n']===1, '재고 5개 이하는 상품 이름 · 수량을 그대로 적는다');
$ok(isset($by['weekly']) && $by['weekly']['n']===1, '월요일에는 지난주 매출 · 깔때기 보기가 붙는다');
$it3 = ($T.'build')($facts0 + [], ['dow'=>6,'hour'=>10,'urls'=>[]]);
$ok(str_contains(array_column($it3,null,'id')['ship']['note'],'주말 주문은 월요일'), '토요일에는 월요일 출고 안내');
$it4 = ($T.'build')(array_merge($facts0, ['holiday'=>['eb'=>'추석 연휴','k'=>'9월 24일(목)–27일(일) 택배 출고가 없습니다']]), ['dow'=>4,'hour'=>10,'urls'=>[]]);
$ok(str_contains(array_column($it4,null,'id')['ship']['note'],'추석 연휴 — 9월 24일(목)'), '연휴 중에는 출고 줄에 연휴 안내를 쓴다');
$open = array_filter($it2, fn($i)=>empty($i['info']) && (int)$i['n']>0);
$ok(count($open) === 9, '열린 항목만 센다 (입금전 숫자 · 0건은 빠진다)');
$txt = ($T.'mail_text')($it2, ['orders'=>12,'sales'=>345000,'signups'=>3], 'https://duck-hoo.com/wp-admin/admin.php?page=duckhoo-today');
$ok(str_starts_with($txt,'오늘 할 일 9개') && str_contains($txt,'[입금]') && str_contains($txt,'· 확인필요 주문 처리 — 1건') && !str_contains($txt,'입금 기다리는 주문'), '메일: 열린 항목만 · 묶음 제목 · 숫자만 줄은 뺀다');
$ok(str_contains($txt,'어제: 주문 12건 · 확정 매출 345,000원 · 새 회원 3명') && str_contains($txt,'page=duckhoo-today'), '메일 끝에 어제 숫자와 화면 주소');
$ok(str_starts_with(($T.'mail_text')($it, [], 'x'),'오늘은 처리할 것이 없습니다'), '할 일 없는 날의 메일 첫 줄');
$GLOBALS['__now'] = mktime(9, 0, 0, 9, 24, 2026);
($T.'save_done')(['stale','check']);
$ok(($T.'done')() === ['stale','check'], '「했음」은 오늘 날짜 아래 저장');
$GLOBALS['__now'] = mktime(9, 0, 0, 9, 25, 2026);
$ok(($T.'done')() === [], '다음 날이면 「했음」이 비어 있다');
$GLOBALS['__now'] = mktime(9, 30, 0, 9, 2, 2026);
$ok(str_contains(($T.'orders_url')('on-hold'), 'wc-on-hold') && str_contains(($T.'orders_url')(['payment-confirmed','x']), 'wc-payment-confirmed'), '주문 목록 주소에 상태가 붙는다 (배열이면 첫 것)');

/* ── 클로드 아침 브리핑 (includes/brief.php) — 키 검사 · 숫자만 남기기 ─────────────────── */
if(!function_exists('register_rest_route')) { function register_rest_route(...$a){} }
if(!function_exists('rest_url')) { function rest_url($p=''){ return 'https://duck-hoo.com/wp-json/'.$p; } }
if(!function_exists('rest_ensure_response')) { function rest_ensure_response($r){ return $r; } }
require_once dirname(__DIR__, 2).'/includes/brief.php';
$Bf = 'Duckhoo\\Redesign\\Brief\\';
$req = fn(array $h) => new class($h) { function __construct(public array $h){} function get_header($k){ return $this->h[$k] ?? ''; } };
$ok(($Bf.'authorized')($req(['x_dhr_key'=>'abc123']), 'abc123') && ($Bf.'authorized')($req(['authorization'=>'Bearer abc123']), 'abc123'), '키: X-DHR-Key 또는 Bearer');
$ok(!($Bf.'authorized')($req(['x_dhr_key'=>'abc124']), 'abc123') && !($Bf.'authorized')($req([]), 'abc123') && !($Bf.'authorized')($req(['x_dhr_key'=>'']), ''), '틀린 키 · 빈 키 · 키가 아예 없으면(옵션 비어 있음) 닫힘');
$GLOBALS['__options']['duckhoo_brief_key'] = '';
$ok(!($Bf.'authorized')($req(['x_dhr_key'=>''])), '옵션에 키가 없으면 빈 헤더로도 못 들어온다');
$nk = ($Bf.'new_key')();
$ok(strlen($nk) >= 24 && preg_match('/^[a-z0-9]+$/', $nk) && ($Bf.'key')() === $nk, '새 키: 24자 이상 영숫자 · 옵션에 저장');
$st = ($Bf.'strip_facts')(['onhold'=>['n'=>7,'stale'=>2,'stale_ids'=>[101,102]],'check'=>['n'=>1,'ids'=>[5]],'sms'=>3,'to_ship'=>['n'=>4,'ids'=>[1,2,3,4]],'no_track'=>['n'=>0],'stuck'=>['n'=>0],'inq'=>['n'=>-1],'reviews'=>1,'stock'=>['out'=>3,'low'=>[11=>'노보 데저트 2개']],'coupons'=>0,'holiday'=>['eb'=>'추석 연휴','k'=>'택배 출고가 없습니다']]);
$ok($st['onhold']===7 && $st['onhold_stale']===2 && $st['to_ship']===4 && $st['low_stock']===['노보 데저트 2개'] && $st['holiday']==='추석 연휴 — 택배 출고가 없습니다', '숫자만 남긴다');
$ok(!isset($st['stale_ids']) && !str_contains(json_encode($st), '101') && $st['inquiries_open'] === -1, '주문 번호 목록은 빠지고, 못 센 문의는 -1 그대로');

/* ── 디스코드 (includes/today.php) — 조각 나누기 · 주소 검사 ─────────────────────────── */
$ok(($T.'discord_ok')('https://discord.com/api/webhooks/123456/abc_DEF-ghi') && ($T.'discord_ok')('https://discordapp.com/api/webhooks/1/x'), '디스코드 웹훅 주소 꼴을 받는다');
$ok(!($T.'discord_ok')('https://example.com/api/webhooks/1/x') && !($T.'discord_ok')('http://discord.com/api/webhooks/1/x') && !($T.'discord_ok')(''), '다른 주소 · http · 빈 값은 거절 — 글이 밖으로 새지 않게');
$ok(($T.'discord_chunks')("한 줄\n두 줄") === ["한 줄\n두 줄"] && ($T.'discord_chunks')("  \n") === [], '짧은 글은 한 조각 · 빈 글은 없음');
$ch = ($T.'discord_chunks')(str_repeat("가나다라마바사아자차\n", 30), 50);
$ok(count($ch) === 8 && max(array_map('mb_strlen', $ch)) <= 50 && !str_contains(implode('', $ch), "\n\n"), '긴 글은 줄 단위로 50자 안에서 나눈다');
$ok(count(($T.'discord_chunks')(str_repeat('가', 120), 50)) === 3, '한 줄이 너무 길면 그 줄 안에서도 자른다');
$GLOBALS['__options']['duckhoo_discord_webhook'] = '';
$ok(($T.'discord_send')('x') === 0, '웹훅이 없으면 안 보내고 0');

/* ── 브리핑 저장 · 규칙 ─────────────────────────────────────────────────────────── */
$GLOBALS['__options']['duckhoo_briefs'] = [];
$GLOBALS['__now'] = mktime(11, 0, 0, 9, 24, 2026);
($Bf.'save')('첫 글'); ($Bf.'save')('둘째 글(덮어씀)');
$GLOBALS['__now'] = mktime(11, 0, 0, 9, 25, 2026);
($Bf.'save')('25일 글');
$rc = ($Bf.'recent')(5);
$ok(array_keys($rc) === ['2026-09-25','2026-09-24'] && $rc['2026-09-24'] === '둘째 글(덮어씀)', '브리핑 저장: 날짜별 · 같은 날은 덮어쓰고 · 최신이 앞');
for ($d = 1; $d <= 20; $d++) { $GLOBALS['__now'] = mktime(11, 0, 0, 10, $d, 2026); ($Bf.'save')('10/'.$d); }
$ok(count((array)$GLOBALS['__options']['duckhoo_briefs']) === 14 && !isset($GLOBALS['__options']['duckhoo_briefs']['2026-09-24']), '14일치만 남긴다');
$GLOBALS['__now'] = mktime(9, 30, 0, 9, 2, 2026);
$rules = implode("\n", array_filter(($Bf.'rules')(), fn($r) => !str_starts_with($r, '말:')));
$ok(str_contains($rules, '안 한다') && str_contains($rules, '하루 구매 한도') && str_contains($rules, '무통장입금') && !preg_match('/건강|금연|순하|해롭/', $rules), '규칙: 「안 한다」 목록 · 무통장 · 금지어는 금지 규칙 줄에만');

/* ── 오늘 할 일 크론 문 — 창 밖 · 이미 보낸 날은 send_mail 까지 못 간다 ─────────────────── */
if(!function_exists('wp_mail')) { function wp_mail($to,$s,$b){ $GLOBALS['__mail_sent'][] = $s; return true; } }
$GLOBALS['__mail_sent'] = []; $GLOBALS['__options']['duckhoo_today_sent_day'] = '';
$GLOBALS['__now'] = mktime(15, 45, 0, 9, 24, 2026); ($T.'cron_send')();
$ok($GLOBALS['__mail_sent'] === [] && ($GLOBALS['__options']['duckhoo_today_sent_day'] ?? '') === '', '15:45 에 불리면 창 밖 — 안 보내고 표시도 안 남긴다');
$GLOBALS['__options']['duckhoo_today_sent_day'] = '2026-09-24';
$GLOBALS['__now'] = mktime(10, 35, 0, 9, 24, 2026); ($T.'cron_send')();
$ok($GLOBALS['__mail_sent'] === [], '오늘 이미 보냈으면 창 안이라도 안 보낸다');
$GLOBALS['__now'] = mktime(9, 30, 0, 9, 2, 2026);

/* ── 주문 해부 (includes/anatomy.php) — 순수 계산 ───────────────────────────────────── */
if(!function_exists('add_submenu_page')) { function add_submenu_page(...$a){ return ''; } }
require_once dirname(__DIR__, 2).'/includes/anatomy.php';
$An = 'Duckhoo\\Redesign\\Anatomy\\';
$ok(($An.'brand')('[노보 블랙] 타박멘솔') === '노보' && ($An.'brand')('[펠릭스] 더블라임 (9.8mg / 30ml)') === '펠릭스' && ($An.'brand')('10병 묶음') === '기타' && ($An.'brand')('[9월 특가] 세트') === '기타', '브랜드: 대괄호 · 별칭 · 행사 접두는 기타');
$ok(($An.'short')('[펠릭스] 더블라임 (9.8mg / 30ml)') === '더블라임' && ($An.'short')('[노보 리퀴드] 10+1') === '10+1', '짧은 이름: 브랜드 · 용량 꼬리 뗌');
$ok(($An.'median')([3,1,2]) === 2.0 && ($An.'median')([1,2,3,4]) === 2.5 && ($An.'median')([]) === 0.0, '중앙값');
$D = 86400; $base = mktime(12,0,0,6,1,2026);
$mk = fn(int $id, int $day, string $s, float $t, int $u, string $st='대구') => ['id'=>$id,'ts'=>$base+$day*$D,'s'=>$s,'t'=>$t,'u'=>$u,'city'=>'','state'=>$st];
$orders = [
  $mk(1, 0,  'delivered', 30000, 1),          // 손님1 첫 주문 노보
  $mk(2, 20, 'delivered', 45000, 1),          // 손님1 둘째 (20일 뒤) 펠릭스
  $mk(3, 50, 'delivered', 30000, 1),          // 손님1 셋째 (30일 뒤) 노보
  $mk(4, 5,  'delivered', 60000, 2, '서울'),   // 손님2 한 번
  $mk(5, 10, 'on-hold',   20000, 3),          // 입금 안 함
  $mk(6, 11, 'cancelled', 20000, 4),
  $mk(7, 40, 'delivered', 120000, 5),         // 손님5 한 번 (노보 10+1)
  $mk(8, 2,  'delivered', 15000, 0),          // 비회원
];
$items = [
  1=>[['pid'=>11,'name'=>'[노보] 타박멘솔 (9.8mg / 30ml)','qty'=>2,'total'=>26000]],
  2=>[['pid'=>12,'name'=>'[펠릭스] 더블라임 (9.8mg / 30ml)','qty'=>2,'total'=>40000]],
  3=>[['pid'=>11,'name'=>'[노보] 타박멘솔 (9.8mg / 30ml)','qty'=>2,'total'=>26000]],
  4=>[['pid'=>13,'name'=>'[화이트아웃] 체리','qty'=>4,'total'=>56000]],
  7=>[['pid'=>14,'name'=>'[노보 리퀴드] 10+1','qty'=>1,'total'=>120000]],
  8=>[['pid'=>11,'name'=>'[노보] 타박멘솔 (9.8mg / 30ml)','qty'=>1,'total'=>13000]],
];
$a = ($An.'analyze')($orders, $items, $base + 100*$D);
$ok($a['orders_all']===8 && $a['orders_paid']===6 && $a['customers']===3 && $a['repeaters']===1 && $a['repeat_rate']==='33%', '주문 8 · 돈 들어온 6 · 회원 3 · 재구매 1명(33%) — 비회원 · 미입금 · 취소는 빠진다');
$ok($a['gap_first_median']==20.0 && $a['gap_median']==25.0, '첫→둘째 20일 · 전체 간격 중앙값 25일');
$ok($a['first_brand']['노보']['n']===2 && $a['first_brand']['노보']['rep']===1 && $a['first_brand']['화이트아웃']['rep']===0, '첫 브랜드별 재구매: 노보 2명 중 1 · 화이트아웃 0');
$ok(($a['brand_flow']['노보']['펠릭스'] ?? 0)===1 && ($a['brand_flow']['노보']['노보'] ?? 0)===1, '브랜드 이동: 노보 → 펠릭스 1 · 노보 1');
$ok(isset($a['one_first']['체리']) && isset($a['rep_first']['타박멘솔']), '한 번 사고 만 첫 상품 · 재구매 손님 첫 상품');
$ok($a['cohort']['2026-06']['n']===2 && $a['cohort']['2026-06']['r30']===1 && $a['cohort']['2026-07']['n']===1, '첫 주문 달별: 6월 2명(30일 안 1) · 7월 1명');
$ok($a['aov']==50000 && $a['buckets']['~2만']===1 && $a['buckets']['2~4만']===2 && $a['buckets']['10~15만']===1, '객단가 평균 50,000 · 분포');
$ok($a['region']['대구']===5 && $a['region']['서울']===1 && $a['brands']['노보']==185000.0, '지역 · 브랜드 매출');
$ok($a['leak']['2026-06']['paid']===4 && $a['leak']['2026-06']['unpaid']===1 && $a['leak']['2026-06']['cancel']===1, '달별 입금 이탈: 6월 돈 4 · 미입금 1 · 취소 1');
$ok($a['products']['10+1']['sales']==120000.0 && array_key_first($a['products'])==='10+1', '상품 매출 상위 1위는 10+1');
$rep = ($An.'report')($a, '2026-09-24');
$ok(($An.'brand')('노보 10병 병당 7,000원 / 금액 70,000') === '노보' && ($An.'brand')('덕후 액상 10병 할인 !') === '액상덕후' && ($An.'brand')('[한정수량] 크래프트 포도 10병 묶음') === '크래프트' && ($An.'brand')('화이트아웃 액상 10병 할인 !') === '화이트아웃' && ($An.'brand')('노보 타박멘솔2000, 노보 블랙멘솔 1000 결제') === '노보' && ($An.'brand')('3+1 묶음 이벤트') === '기타', '브랜드: 대괄호 없는 옛 이름 · 행사 대괄호는 이름 안 낱말로 (2026-09-29)');
$orders2 = $orders; $orders2[] = $mk(9, 30, 'delivered', 21000000, 6);
$items2 = $items; $items2[9] = [['pid'=>15,'name'=>'노보 타박멘솔2000, 노보 블랙멘솔 1000 결제','qty'=>1,'total'=>21000000]];
$a2 = ($An.'analyze')($orders2, $items2, $base + 100*$D);
$ok($a2['big_n']===1 && $a2['big'][0]['id']===9 && $a2['aov_ex']==50000 && $a2['aov']>3000000 && $a2['top10_share_ex'] < $a2['top10_share'], '큰 주문 한 건(2,100만원)을 따로 세고 평균 · 상위 10% 몫은 뺀 값도 준다');
$rep2 = ($An.'report')($a2, '2026-09-29');
$ok(str_contains($rep2, '한 건짜리 큰 주문(50만원↑) 1건') && str_contains($rep2, '#9 21,000,000원') && $a2['brands']['노보']==21185000.0, '보고서에 큰 주문 줄 · 그 줄의 브랜드는 노보로 센다');
$ok(!str_contains($rep, '큰 주문'), '큰 주문이 없으면 그 줄이 없다');
$ok(str_contains($rep,'두 번 이상 산 회원 1명 (33%)') && str_contains($rep,'[달별 입금 이탈]') && str_contains($rep,'노보 → ') && !preg_match('/건강|금연|순하|해롭/', $rep), '붙여 넣기용 글: 핵심 숫자 · 절 제목 · 금지어 없음');


// ───────────────────────────────────────────────── 시장가 대조 (2026-09-24)
// 사장님: 「니코틴 액상을 빨리 처분」 · 「업자한테 팔 생각은 절대 없음」. 이 화면은 읽기 전용 —
// 경쟁 가게 값을 우리 상품 옆에 놓고 제안 값을 적기만 한다. 값을 바꾸는 코드는 없다.
require_once dirname(__DIR__, 2).'/includes/market.php';
$M = 'Duckhoo\\Redesign\\Market\\';
$vmHtml = '<div class="item-list-wrap"><div class="item-list"><a href="https://www.vmonster.co.kr/shop/1"></a><div class="reviewCount">REVIEW <em>511</em></div>'
  . '<h5 class="product-name"><a href="https://www.vmonster.co.kr/shop/1"> 노보 타박멘솔 30ml </a></h5><div class="product-price"><span class="title-price">11,500원</span></div></div>'
  . '<div class="item-list-wrap"><div class="item-list"><div class="reviewCount">REVIEW <em>10</em></div><h5 class="product-name"><a href="#"> 노보 블랙리퀴드 타박멘솔 30ml </a></h5><span class="title-price">12,000원</span></div>'
  . '<div class="item-list-wrap"><div class="item-list"><span class="soldout">품절</span><h5 class="product-name"><a href="#"> 노보 세븐펀치 30ml </a></h5><span class="title-price">11,500원</span></div>'
  . '<div class="item-list-wrap"><div class="item-list"><h5 class="product-name"><a href="#"> 얼려먹구싶오 소다 30ml </a></h5><span class="title-price">9,900원</span><div class="reviewCount">REVIEW <em>393</em></div></div>'
  . '<div class="item-list-wrap"><div class="item-list"><h5 class="product-name"><a href="#"> 무니코틴 얼려먹구싶오 소다 30ml </a></h5><span class="title-price">8,500원</span></div>';
$vm = ($M.'parse_vm')($vmHtml);
$ok(count($vm) === 5, '브이몬스터 쪽에서 상품 5개를 읽는다');
$ok($vm[0]['name'] === '노보 타박멘솔 30ml' && $vm[0]['price'] === 11500 && $vm[0]['reviews'] === 511, '이름 · 값 · 후기 수를 읽는다');
$ok($vm[2]['out'] === true && $vm[0]['out'] === false, '품절 표시를 가른다');
$w24 = ($M.'parse_w24')([
  ['name' => '노보 고농도(0.98%) 입호흡 액상 30ml', 'prices' => ['price' => '9000'], 'is_in_stock' => true],
  ['name' => '★대량구매★ 노보 고농도 입호흡 액상 30ml', 'prices' => ['price' => '8000'], 'is_in_stock' => true],
  ['name' => '[10개 세트] 노보 블랙리퀴드 입호흡 액상 30ml', 'prices' => ['price' => '85000'], 'is_in_stock' => true],
  ['name' => '★대량구매★ 노보 블랙리퀴드 입호흡 액상 30ml', 'prices' => ['price' => '8000'], 'is_in_stock' => true],
  ['name' => '▶유통기한 이슈 상품◀ 노보 옐로우펀치 30ml', 'prices' => ['price' => '7900'], 'is_in_stock' => false],
  ['name' => '[무니코틴] 얼려먹구싶오 입호흡 액상 30ml', 'prices' => ['price' => '9500'], 'is_in_stock' => true],
]);
$ok(count($w24) === 5, '겨울마을 「유통기한 이슈」 칸은 뺀다');
$ok($w24[1]['bulk'] === true && $w24[0]['bulk'] === false, '★대량구매★ 는 업자 단으로 표시한다');
$ok($w24[2]['set'] === 10 && $w24[2]['price'] === 85000, '[10개 세트] 는 병 수 10 으로 읽는다');

$ok(($M.'brand_of')('[노보 블랙 리퀴드] 10+1 | 금액 130,000원') === '노보 블랙', '「노보 블랙 리퀴드」 묶음의 브랜드는 노보 블랙');
$ok(($M.'bottles')('[노보 리퀴드] 10+1 | 금액 120,000원') === 11 && ($M.'bottles')('[빌런] ★ 맛돌이 액상 ★ 빌런 5병 EVENT') === 5 && ($M.'bottles')('[노보] 타박멘솔 (9.8mg / 30ml)') === 1, '병 수: 10+1 → 11 · 5병 → 5 · 낱병 → 1');
$ok(($M.'flavor_of')('[맥스쿨] 맥스쿨 소다 무니코틴 액상') === '소다', '브랜드가 두 번 붙은 이름에서 맛만 남긴다');

$data = ['vm' => $vm, 'w24' => $w24];
$f = ($M.'find_row')('[노보] 타박멘솔 (9.8mg / 30ml)', $vm);
$ok($f['single']['name'] === '노보 타박멘솔 30ml', '「노보 타박멘솔」은 블랙이 아닌 쪽과 맞춘다');
$f = ($M.'find_row')('[노보 블랙] 타박멘솔 (9.8mg / 30ml)', $vm);
$ok($f['single']['name'] === '노보 블랙리퀴드 타박멘솔 30ml', '「노보 블랙 타박멘솔」은 블랙리퀴드와 맞춘다');
$f = ($M.'find_row')('[얼려먹구싶오] 얼려먹구싶오 소다 무니코틴 액상', $vm);
$ok($f['single']['price'] === 8500, '무니코틴은 무니코틴판(8,500)과 맞추지 니코틴판(9,900)과 맞추지 않는다');
$f = ($M.'find_row')('[노보 리퀴드] 10+1 | 금액 120,000원', $vm);
$ok($f['single']['name'] === '노보 타박멘솔 30ml', '묶음은 그 브랜드의 재고 있는 가장 싼 낱병과 견준다 (품절 세븐펀치는 뺀다)');
$f = ($M.'find_row')('[노보] 타박멘솔 (9.8mg / 30ml)', $w24);
$ok($f['single']['price'] === 9000 && $f['bulk']['price'] === 8000, '겨울마을처럼 맛이 옵션인 가게는 브랜드만 같은 낱병(9,000)으로 떨어진다');
$f = ($M.'find_row')('[노보 블랙 리퀴드] 10+1 | 금액 130,000원', $w24);
$ok($f['set']['set'] === 10 && $f['bulk']['price'] === 8000, '겨울마을에서 세트와 대량 단을 따로 찾는다');
$f = ($M.'find_row')('[빌런] 아이스빌런청사과 (9.8mg / 30ml)', $vm);
$ok($f['single'] === null, '없는 브랜드는 못 맞춘 것으로 둔다');
$f = ($M.'find_row')('[노보] 데저트 (9.8mg / 30ml)', $vm, '노보 세븐펀치 30ml');
$ok($f['single']['name'] === '노보 세븐펀치 30ml', '못 박은 이름이 있으면 그것을 쓴다');

$ours = [
  ['id' => 242, 'name' => '[노보] 타박멘솔 (9.8mg / 30ml)', 'price' => 13000, 'bottles' => 1],
  ['id' => 146, 'name' => '[노보 블랙 리퀴드] 10+1 | 금액 130,000원', 'price' => 130000, 'bottles' => 11],
  ['id' => 226, 'name' => '[빌런] 아이스빌런레즈애플 (9.8mg / 30ml)', 'price' => 10900, 'bottles' => 1],
];
$rows = ($M.'compare')($ours, $data);
$ok($rows[0]['min'] === 9000 && $rows[0]['gap'] === 44, '노보 타박멘솔: 시장 최저(낱병) 9,000 · 우리가 44% 비싸다');
$ok($rows[0]['comp']['w24']['bulk'] === 8000 && $rows[0]['min'] !== 8000, '50병 대량 단(8,000)은 적기만 하고 시장가로 세지 않는다 — 업자 절대 없음');
$ok($rows[1]['per'] === 11818 && $rows[1]['comp']['w24']['set'] === 8500, '블랙 10+1 은 병당 11,818 · 겨울마을 블랙 10개 세트 병당 8,500');
$ok($rows[2]['gap'] === null && $rows[2]['min'] === 0, '못 맞춘 상품은 차이가 없다');
$ok(($M.'suggest')($rows[0], 'vm') === 11500 && ($M.'suggest')($rows[0], 'w24') === 9000 && ($M.'suggest')($rows[0], 'min') === 9000, '제안 값: 브이몬스터 11,500 · 겨울마을 9,000 · 둘 중 싼 것 9,000');
$ok(($M.'suggest')($rows[1], 'w24') === 93500 && ($M.'suggest')($rows[1], 'vm') === 132000, '묶음 제안은 병당 × 11, 100원 단위로 내린다 (세트 8,500 × 11 = 93,500 · 브이몬스터 블랙 낱병 12,000 × 11)');
$ok(($M.'suggest')($rows[2], 'min') === 0, '기준이 없으면 제안하지 않는다');
$ok(!function_exists($M.'apply') && !function_exists($M.'revert'), '값을 바꾸는 함수가 없다 — 읽기 전용');
$rows = ($M.'compare')($ours, $data, [242 => 'vm:노보 세븐펀치 30ml']);
$ok($rows[0]['comp']['vm']['name'] === '노보 세븐펀치 30ml' && $rows[0]['comp']['w24']['single'] === 9000, '「vm:이름」은 그 가게에서만 못 박고 다른 가게는 이름으로 맞춘다');

// ───────────────────────────────────────────────── 네이버 진단 — 페이지 설명 · noindex · 사이트맵 · alt (2026-09-28)
require_once dirname(__DIR__, 2).'/includes/seo-pages.php';
$SP = 'Duckhoo\\Redesign\\Seo\\Pages\\';
$ok(($SP.'is_private_slug')('checkout') && ($SP.'is_private_slug')('inquiries') && ($SP.'is_private_slug')(rawurlencode('본인인증테스트')), '결제 · 1:1 문의 · 시험 페이지는 색인 제외 (인코딩된 슬러그도)');
$ok(!($SP.'is_private_slug')('register') && !($SP.'is_private_slug')('shop') && !($SP.'is_private_slug')('notice') && !($SP.'is_private_slug')(''), '가입 1단계 · 전체 상품 · 공지는 색인한다 (빈 슬러그는 아니다)');
$GLOBALS['__filters']['duckhoo_noindex_slugs'] = [fn($a) => array_merge($a, ['event'])];
$ok(($SP.'is_private_slug')('event'), '필터로 슬러그를 더할 수 있다');
unset($GLOBALS['__filters']['duckhoo_noindex_slugs']);
$r = ($SP.'robots_aioseo')(['index' => 'index', 'max-image-preview' => 'max-image-preview:large']);
$ok(($r['index'] ?? '') === 'index' && !isset($r['noindex']), '페이지가 아니면 robots 를 건드리지 않는다');
$pages = [
  ['loc' => 'https://duck-hoo.com/checkout/'], ['loc' => 'https://duck-hoo.com/shop/'], ['loc' => 'https://duck-hoo.com/mypage/'],
  ['loc' => 'https://duck-hoo.com/%EB%B3%B8%EC%9D%B8%EC%9D%B8%EC%A6%9D%ED%85%8C%EC%8A%A4%ED%8A%B8/'], ['loc' => 'https://duck-hoo.com/register/'], ['loc' => 'https://duck-hoo.com/'],
];
$kept = ($SP.'sitemap_posts')($pages, 'page');
$ok(array_column($kept, 'loc') === ['https://duck-hoo.com/shop/', 'https://duck-hoo.com/register/', 'https://duck-hoo.com/'], '페이지 사이트맵에서 결제 · 마이페이지 · 시험 페이지(인코딩된 주소)를 빼고 전체 상품 · 가입 · 홈은 남긴다');
$ok(($SP.'sitemap_posts')($pages, 'product') === $pages && ($SP.'sitemap_posts')('x', 'page') === 'x', '상품 사이트맵은 건드리지 않는다 · 배열이 아니면 그대로');
$ok(($SP.'entry_path')(['loc' => 'https://duck-hoo.com/checkout/']) === 'checkout' && ($SP.'entry_path')('https://duck-hoo.com/') === '', '항목 주소 → 경로 (홈은 빈 문자열)');
$demo = [['loc' => 'https://duck-hoo.com/2026/04/09/behind-the-product-fedora-hat/'], ['loc' => 'https://duck-hoo.com/2026/04/09/real-post/']];
$ok(array_column(($SP.'sitemap_posts')($demo, 'post'), 'loc') === ['https://duck-hoo.com/2026/04/09/real-post/'], '테마 데모 글은 글 사이트맵에서 빠지고 다른 글은 남는다 (지우지 않는다)');
$cats = [['loc' => 'https://duck-hoo.com/category/uncategorized/'], ['loc' => 'https://duck-hoo.com/category/news/']];
$ok(array_column(($SP.'sitemap_terms')($cats), 'loc') === ['https://duck-hoo.com/category/news/'], '분류 사이트맵에서 uncategorized 만 빠진다');
$ok(($SP.'post_slug')() === '', '글 화면이 아니면 글 슬러그는 빈 문자열');
// kboard
$GLOBALS['__pages']['inquiries'] = (object)['ID' => 30, 'post_content' => '<p>[kboard id="4"]</p>'];
$ok(($SP.'private_boards')() === [4], '1:1 문의 게시판 번호는 페이지 본문의 [kboard id=N] 에서 읽는다');
$entries = [
  ['loc' => 'https://duck-hoo.com/?kboard_content_redirect=99'],
  ['loc' => 'https://duck-hoo.com/?kboard_content_redirect=33'],
  ['url' => 'https://duck-hoo.com/?kboard_content_redirect=100'],
  ['loc' => 'https://duck-hoo.com/tip/'],
];
$kept = ($SP.'drop_private')($entries, [99, 100]);
$ok(count($kept) === 2 && ($SP.'entry_uid')($kept[0]) === 33 && ($kept[1]['loc'] ?? '') === 'https://duck-hoo.com/tip/', '비공개 게시판 글(uid 99 · 100)만 빠지고 팁 글 · 번호 없는 주소는 남는다 (loc · url 둘 다 읽는다)');
$ok(($SP.'drop_private')($entries, []) === $entries, '뺄 번호를 모르면 아무것도 빼지 않는다');
unset($GLOBALS['__pages']['inquiries']);
$ok(($SP.'sitemap_posts')($entries, 'kboard') === $entries, 'kboard 표를 읽을 수 없으면(테스트) 아무것도 빼지 않는다 — 모를 때는 그대로');
// 제목 · 설명
$ok(($SP.'shop_title')(184) === '전자담배 액상 전체 상품 184종 | 전담 액상 사이트 액상덕후' && ($SP.'shop_title')(0) === '전자담배 액상 전체 상품 | 전담 액상 사이트 액상덕후', '전체 상품 제목 — 종수는 있을 때만, 「전담 액상 사이트」(2026-10-01)');
$d = ($SP.'shop_desc')(184, ['노보', '펠릭스']);
$ok(str_starts_with($d, '전자담배 액상(전담 액상) 전문 사이트. 노보 · 펠릭스 등 입호흡 · 폐호흡 액상 184종') && str_contains($d, '30,000원 이상 무료배송') && str_contains($d, '8,800원 적립') && str_contains($d, '19세 이상') && mb_strlen($d) <= 160, '전체 상품 설명 — 브랜드 · 종수 · 혜택 · 19세, 160자 이내');
foreach (['건강','금연','순하','해롭'] as $bad) { $ok(!str_contains($d, $bad), "전체 상품 설명에 「{$bad}」 없음"); }
$ok(str_contains(($SP.'page_desc')('login', 'login'), '로그인') && str_contains(($SP.'page_desc')('register', 'Sign In'), '본인확인'), '로그인 · 가입 페이지는 슬러그 글 (영문 제목을 안 쓴다)');
$g = ($SP.'page_desc')('unknown-page', '<b>어떤</b> 페이지');
$ok($g === '어떤 페이지 — 액상덕후, 전자담배 액상 전문몰. 30,000원 이상 무료배송 · 가입 즉시 8,800원 적립.', '모르는 페이지는 제목으로 엮는다 (태그는 뗀다)');
foreach (($SP.'page_texts')() as $slug => $txt) { $ok(mb_strlen($txt) <= 160, "페이지 설명 160자 이내: {$slug}"); foreach (['건강','금연','순하','해롭'] as $bad) { $ok(!str_contains($txt, $bad), "페이지 설명에 「{$bad}」 없음: {$slug}"); } }
$ok(($SP.'description')('사장님이 쓴 글') === '사장님이 쓴 글', '설명이 이미 있으면 그대로');
$GLOBALS['__is_shop'] = true;
$ok(str_contains(($SP.'description')(''), '입호흡 · 폐호흡 액상') && str_contains(($SP.'title')('상점 - 액상덕후'), '전자담배 액상 전체 상품'), '전체 상품 화면 — 빈 설명을 채우고 제목을 바꾼다');
$GLOBALS['__is_shop'] = false;
$ok(($SP.'title')('상점 - 액상덕후') === '상점 - 액상덕후' && ($SP.'description')('') === '', '전체 상품 화면이 아니면 제목 · 설명 그대로');
// alt
$html = '<p>x</p><img decoding="async" class="fr-dib" src="a.jpg" /><img src="b.jpg" alt=""><img src="c.jpg" alt="있음"><IMG src="d.jpg">';
$out = ($SP.'img_alt')($html, '[노보] 타박멘솔 (9.8mg / 30ml)');
$ok(str_contains($out, 'src="a.jpg" alt="[노보] 타박멘솔 (9.8mg / 30ml) 상세 이미지 1" />') && str_contains($out, 'src="d.jpg" alt="[노보] 타박멘솔 (9.8mg / 30ml) 상세 이미지 3">'), 'alt 없는 이미지에 「상품명 상세 이미지 N」을 붙인다 (닫는 슬래시 유지 · 번호는 채운 순서)');
$ok(str_contains($out, '<img src="b.jpg" alt="[노보] 타박멘솔 (9.8mg / 30ml) 상세 이미지 2">') && !str_contains($out, 'alt=""'), '빈 alt="" 도 채운다 (워드프레스 이미지 블록) — 빈 것을 떼고 하나만 남긴다');
$ok(str_contains($out, 'alt="있음"') && substr_count($out, ' alt=') === 4, '글자가 있는 alt 는 그대로 — 총 4개, 두 번 붙지 않는다');
$ok(str_contains(($SP.'img_alt')("<img alt='' src=x.jpg><img alt=y src=z.jpg>", 'N'), "<img src=x.jpg alt=\"N 상세 이미지 1\">") && str_contains(($SP.'img_alt')("<img alt=y src=z.jpg>", 'N'), 'alt=y'), '홑따옴표 · 따옴표 없는 alt 도 읽는다');
$ok(($SP.'img_alt')($html, '') === $html && ($SP.'img_alt')('', 'x') === '', '이름이나 HTML 이 비면 손대지 않는다');
$GLOBALS['product'] = new WC_Product(1, '[펠릭스] 더블라임', 20000);
$a = ($SP.'attachment_alt')(['alt' => '', 'src' => 'x.jpg']);
$ok(($a['alt'] ?? '') === '[펠릭스] 더블라임', '첨부 이미지의 빈 alt 는 지금 상품 이름으로');
$a = ($SP.'attachment_alt')(['alt' => '사진', 'src' => 'x.jpg']);
$ok($a['alt'] === '사진', '있는 alt 는 건드리지 않는다');
unset($GLOBALS['product']);
$GLOBALS['__posttype'][555] = 'product'; $GLOBALS['__products'][555] = new WC_Product(555, '[노보] 데저트', 13000);
$a = ($SP.'attachment_alt')(['alt' => ''], (object)['post_parent' => 555]);
$ok(($a['alt'] ?? '') === '[노보] 데저트', '전역 상품이 없으면 첨부의 부모 상품 이름으로');
$a = ($SP.'attachment_alt')(['alt' => ''], (object)['post_parent' => 0]);
$ok(($a['alt'] ?? '') === '', '상품과 무관한 이미지는 그대로 (없는 이름을 만들지 않는다)');

// ───────────────────────────────────────────────── 가격표 · 지운 상품 리다이렉트 (2026-09-28)
require_once dirname(__DIR__, 2).'/includes/pricelist.php';
$PL = 'Duckhoo\\Redesign\\PriceList\\';
$ok(($PL.'spec')('[노보] 타박멘솔 (9.8mg / 30ml)') === ['nic' => '9.8mg', 'ml' => '30ml'] && ($PL.'spec')('[디톡스] 톡스 알로에베라 모드 액상 (3MG/60ml)') === ['nic' => '3mg', 'ml' => '60ml'] && ($PL.'spec')('[액상덕후] 바나나 흑염룡 시리즈 (0.98MG / 30ml)')['nic'] === '0.98mg', '이름에서 니코틴 · 용량을 읽는다 (대소문자 · 빈칸 무관)');
$ok(($PL.'spec')('[맥스쿨] 소다 무니코틴 액상')['nic'] === '무니코틴' && ($PL.'spec')('[젤로맥스] 젤로 맥스 0.6옴 팟') === ['nic' => '', 'ml' => ''], '무니코틴은 무니코틴, 팟은 빈칸');
$ok(($PL.'group_of')(['기기 · 팟 · 코일'], '조바 입호흡 전자담배') === 'device' && ($PL.'group_of')(['무니코틴'], '[맥스쿨] 소다 무니코틴 액상') === 'nicfree' && ($PL.'group_of')(['폐호흡 액상'], 'x') === 'dl' && ($PL.'group_of')(['입호흡 액상', '타격감'], 'x') === 'mtl' && ($PL.'group_of')([], '[펠릭스] 모드 더블라임') === 'dl', '묶음 가르기 — 기기 · 무니코틴 · 폐호흡(모드) · 입호흡');
$GLOBALS['__products'] = []; $GLOBALS['__transients'] = []; $GLOBALS['__pterms'] = [];
$GLOBALS['__products'][1] = new WC_Product(1, '[노보] 타박멘솔 (9.8mg / 30ml)', 13000);
$GLOBALS['__products'][2] = new WC_Product(2, '[노보 리퀴드] 10+1 | 금액 120,000원', 120000);
$GLOBALS['__products'][3] = new WC_Product(3, '[맥스쿨] 소다 무니코틴 액상', 9000, false);
$GLOBALS['__products'][4] = new WC_Product(4, '조바 입호흡 전자담배', 58000);
$GLOBALS['__products'][5] = new WC_Product(5, '[펠릭스] 모드 더블라임 (3mg / 60ml)', 20000);
$GLOBALS['__products'][6] = new WC_Product(6, '값 없는 상품', 0);
$GLOBALS['__pterms'][1] = [(object)['name'=>'입호흡 액상'], (object)['name'=>'노보 액상']];
$GLOBALS['__pterms'][2] = [(object)['name'=>'입호흡 액상']];
$GLOBALS['__pterms'][3] = [(object)['name'=>'무니코틴']];
$GLOBALS['__pterms'][4] = [(object)['name'=>'기기 / 팟 / 코일']];
$GLOBALS['__pterms'][5] = [(object)['name'=>'폐호흡 액상']];
$rows = ($PL.'rows')(array_values($GLOBALS['__products']));
$ok(array_keys($rows) === ['mtl', 'dl', 'nicfree', 'device'], '묶음 순서는 입호흡 → 폐호흡 → 무니코틴 → 기기');
$ok(count($rows['mtl']) === 2 && $rows['mtl'][0]['name'] === '10+1 | 금액 120,000원' && $rows['mtl'][0]['brand'] === '노보 리퀴드' && $rows['mtl'][0]['qty'] === 11 && (int) round($rows['mtl'][0]['per']) === 10909, '묶음은 병당(11병 · 10,909원)을 같이 낸다');
$ok(!in_array('값 없는 상품', array_column(array_merge(...array_values($rows)), 'name'), true), '값이 0 인 상품은 표에 안 넣는다');
$ok($rows['nicfree'][0]['stock'] === false, '품절은 그대로 실린다 (표가 완전해야 한다)');
$html = ($PL.'table_html')($rows);
$ok(str_contains($html, '전 상품 <b>5종</b>') && str_contains($html, '<h2>입호흡 액상 가격 <small>2종</small></h2>') && str_contains($html, 'id="pl-device"'), '표 머리 — 종수 · 묶음 제목 · 앵커');
$ok(str_contains($html, '13,000원') && str_contains($html, '10,909원 <small>× 11병</small>') && str_contains($html, '<em>품절</em>') && str_contains($html, '9.8mg · 30ml'), '값 · 병당 · 품절 · 니코틴이 글자로 있다');
$ok(substr_count($html, '<tr') === 5 + 4, '줄 수 = 상품 5 + 머리 4');
foreach (['건강','금연','순하','해롭'] as $bad) { $ok(!str_contains($html, $bad), "가격표에 「{$bad}」 없음"); }
$seo = ($PL.'seo')(184);
$ok($seo['title'] === '전자담배 액상 가격표 — 입호흡 · 폐호흡 · 무니코틴 184종 | 액상덕후' && str_contains($seo['desc'], '전자담배 액상 가격을 한 표로') && mb_strlen($seo['desc']) <= 160, '가격표 제목 · 설명 (160자 이내)');
$ok(str_contains(('Duckhoo\\Redesign\\Pages\\definitions')()['price']['content'] ?? '', '[duckhoo_price_table]') && \Duckhoo\Redesign\Pages\VERSION >= 3, 'pages.php 가 /price/ 를 만든다 (VERSION 3)');
// 지운 상품 리다이렉트
$map = ['크래프트' => 'https://duck-hoo.com/product-category/입호흡-액상/'];
$ok(($SP.'gone_target')('/product/%ED%81%AC%EB%9E%98%ED%94%84%ED%8A%B8-%E2%98%85-5%EB%B3%91-event/', $map) === $map['크래프트'], '인코딩된 크래프트 주소 → 입호흡 분류');
$ok(($SP.'gone_target')('/product/크래프트-포도-9-8mg-30ml/', $map) === $map['크래프트'] && ($SP.'gone_target')('/product/노보-타박멘솔/', $map) === '' && ($SP.'gone_target')('/x/', ['' => 'y']) === '', '디코드된 주소도 잡고, 다른 상품 · 빈 열쇠는 안 잡는다');
$ok(str_contains(('Duckhoo\\Redesign\\Seo\\Texts\\texts')()['리퀴드랩-ㅇㅋㄹㅌ-9-8mg-30ml-2'], '아쿠아') && str_contains(('Duckhoo\\Redesign\\Seo\\Texts\\texts')()['마르키사-오리지날-9-8mg-30ml'], '라즈베리'), '주소 ≠ 이름 상품 둘(아쿠아 · 라즈베리)의 글이 실제 상품을 말한다');

// ───────────────────────────────────────────────── 옵션을 골라야 담긴다 (2026-09-28)
require_once dirname(__DIR__, 2).'/includes/must-pick.php';
$MP = 'Duckhoo\\Redesign\\MustPick\\';
$ok(($MP.'products')() === [3435], '기본 대상은 조바 입호흡 전자담배 #3435');
$ok(($MP.'picked')(['ppom' => ['fields' => ['id' => '99', 'joba_color' => '블랙']]]), 'PPOM 칸에 값이 있으면 골랐다 (칸 이름은 무엇이든)');
$ok(!($MP.'picked')(['ppom' => ['fields' => ['id' => '99', 'joba_color' => '']]]) && !($MP.'picked')([]) && !($MP.'picked')(['ppom' => ['fields' => ['id' => '99']]]), '값이 비었거나 id 뿐이면 안 골랐다');
$ok(($MP.'picked')(['wd_option_builder_json' => json_encode([['group_key' => 'required_main', 'label' => '블랙', 'qty' => 1]])]), '테마 빌더 JSON 의 required_main 줄도 골랐다로 본다');
$ok(!($MP.'picked')(['wd_option_builder_json' => json_encode([['group_key' => 'addon_1', 'qty' => 2]])]), 'addon 줄만 있으면 안 골랐다');
$ok(($MP.'item_picked')(['ppom' => ['fields' => ['x' => '퍼플']]]) && ($MP.'item_picked')(['wd_option_builder' => [['group_key' => 'required_main', 'qty' => 1]]]) && !($MP.'item_picked')(['product_id' => 3435]), '장바구니 줄도 같은 규칙');
$GLOBALS['__postmeta'][3435] = []; $GLOBALS['__notices'] = []; $_POST = [];
$ok(($MP.'validate_add')(true, 3435) === true && !$GLOBALS['__notices'], '옵션 그룹이 아직 안 이어진 상품은 막지 않는다 (판매가 서면 안 된다)');
$GLOBALS['__postmeta'][3435]['_product_meta_id'] = [61];   // PPOM 34 는 배열로 적는다
$ok(($MP.'validate_add')(true, 3435) === false && ($GLOBALS['__notices'][0][1] ?? '') === '옵션(색상)을 선택해 주세요. 골라야 담을 수 있습니다.', '그룹이 이어졌는데 안 골랐으면 담기를 막고 안내한다');
$_POST = ['ppom' => ['fields' => ['id' => '41', 'joba_color' => '샴페인 골드']]]; $GLOBALS['__notices'] = [];
$ok(($MP.'validate_add')(true, 3435) === true && !$GLOBALS['__notices'], '골랐으면 통과');
$ok(($MP.'validate_add')(true, 146) === true && ($MP.'validate_add')(false, 3435) === false, '다른 상품은 그대로 · 앞에서 이미 막힌 것은 그대로');
$_POST = [];
$ok(($MP.'has_group')(3435) && !($MP.'has_group')(146), '그룹 여부는 PPOM 메타 `_product_meta_id`(배열)로 본다');
$caught = ''; try { ($MP.'store_add')(new WC_Product(3435, '조바 입호흡 전자담배', 58000)); } catch (\Throwable $e) { $caught = $e->getMessage(); }
$ok(str_contains($caught, '옵션(색상)'), 'Store API 로는 이 상품을 못 담는다 (옵션을 실을 수 없는 길)');
$caught = ''; try { ($MP.'store_add')(new WC_Product(146, '노보 10병', 130000)); } catch (\Throwable $e) { $caught = 'x'; }
$ok($caught === '', '다른 상품은 Store API 그대로');
unset($GLOBALS['__postmeta'][3435]);

// ───────────────────────────────────────────────── 주문내역 진단 (2026-09-28)
require_once dirname(__DIR__, 2).'/includes/orders-doctor.php';
$OD = 'Duckhoo\\Redesign\\OrdersDoctor\\';
$ok(($OD.'verdict')(0, 0, 0, false)['level'] === 'warn' && str_contains(($OD.'verdict')(0, 0, 0, true)['text'], '다른 계정'), '주문이 0건이면 계정 문제를 의심 (다른 계정이 있으면 그것부터)');
$ok(($OD.'verdict')(5, 2, 2, false)['level'] === 'bad' && str_contains(($OD.'verdict')(5, 2, 2, false)['text'], 'my_orders_query'), '표 5건 · 질의 2건이면 필터 · 상태 등록이 범인');
$ok(($OD.'verdict')(5, 5, -1, false)['level'] === 'bad' && str_contains(($OD.'verdict')(5, 5, -1, false)['text'], '죽는다'), '그리다 죽으면 템플릿 예외');
$ok(($OD.'verdict')(5, 5, 3, false)['level'] === 'bad', '질의 5 · 그린 줄 3 이면 템플릿이 건너뛴다');
$ok(($OD.'verdict')(5, 5, 5, false)['level'] === 'ok' && str_contains(($OD.'verdict')(5, 5, 5, true)['text'], '다른 계정'), '다 맞으면 서버는 정상 — 다른 계정이 있으면 그쪽을 짚는다');
$v = ($OD.'verdict')(1, 1, -2, true, 279209535, 279209536);
$ok($v['level'] === 'bad' && str_contains($v['text'], '#279209535 로 들어가는데') && str_contains($v['text'], '#279209536 에 붙어'), '같은 아이디의 계정이 둘이면 로그인이 먼저 만든 계정으로 가는 것을 원인으로 짚는다');
$ok(($OD.'verdict')(1, 1, -2, false, 279209536, 279209536)['level'] === 'ok', '로그인이 이 계정으로 오고 관리자라 못 그린 것(-2)은 정상으로 본다');

// ───────────────────────────────────────────────── 쌍둥이 계정 로그인 (2026-09-28)
require_once dirname(__DIR__, 2).'/includes/twin-login.php';
$TL = 'Duckhoo\\Redesign\\TwinLogin\\';
$ok(($TL.'best')([['id'=>535,'ok'=>true,'verified'=>false,'orders'=>0], ['id'=>536,'ok'=>true,'verified'=>true,'orders'=>1]]) === 536, '둘 다 비밀번호가 맞으면 본인확인 · 주문 있는 나중 계정');
$ok(($TL.'best')([['id'=>535,'ok'=>true,'verified'=>false,'orders'=>0], ['id'=>536,'ok'=>false,'verified'=>true,'orders'=>1]]) === 535, '쌍둥이 비밀번호가 안 맞으면 워드프레스가 고른 계정 그대로 — 비밀번호 검증을 건너뛰지 않는다');
$ok(($TL.'best')([['id'=>905,'ok'=>true,'verified'=>true,'orders'=>4], ['id'=>906,'ok'=>true,'verified'=>true,'orders'=>0]]) === 905, '본인확인이 둘 다면 주문 있는 쪽 (lymuzik86 처럼 먼저 계정)');
$ok(($TL.'best')([['id'=>100,'ok'=>true,'verified'=>false,'orders'=>0], ['id'=>101,'ok'=>true,'verified'=>false,'orders'=>0]]) === 101, '아무것도 없으면 나중에 만들어진 쪽');
$ok(($TL.'best')([['id'=>1,'ok'=>false], ['id'=>2,'ok'=>false]]) === 0 && ($TL.'best')([]) === 0, '맞는 것이 없으면 0');
if (!class_exists('WP_Error')) { class WP_Error { public function __construct(public string $c = '', public string $m = '') {} public function get_error_code() { return $this->c; } } }
$err = new \WP_Error('x','y');
$ok(($TL.'pick')($err, 'a', 'b') === $err && ($TL.'pick')(null, 'a', '') === null, '다른 이유로 실패한 로그인 · 비밀번호 없는 호출은 그대로');
$bad = new \WP_Error('incorrect_password','틀림');
$ok(($TL.'rescuable')($bad) && !($TL.'rescuable')(new \WP_Error('invalid_username','')) && !($TL.'rescuable')(null) && !($TL.'rescuable')($err), '「틀린 비밀번호」일 때만 쌍둥이 해시를 다시 본다 — 없는 아이디 · 빈 값은 아니다');
$ok(($TL.'pick')($bad, 'kisa8020@naver.com', 'pw') === $bad, '쌍둥이를 못 찾으면(DB 없음) 틀린 비밀번호 그대로 — 열어 주지 않는다');
$ok(($TL.'by_name')('  ') === [], '빈 아이디는 후보 없음');

// ───────────────────────────────────────────────── 가입 두 번 제출 잠금 (2026-09-28)
$SG = 'Duckhoo\\Redesign\\Signup\\';
$ok(($SG.'lock_key')(' Kisa8020@Naver.com ') === ($SG.'lock_key')('kisa8020@naver.com'), '열쇠는 대소문자 · 빈칸을 무시한 이메일');
$GLOBALS['__transients'] = [];
$k = ($SG.'lock_key')('a@b.c');
$ok(($SG.'lock_take')($k) === true && ($SG.'lock_take')($k) === false, '첫 요청은 잠금을 잡고 두 번째는 못 잡는다');
($SG.'lock_drop')($k);
$ok(($SG.'lock_take')($k) === true, '풀면 다시 잡힌다 (검증 실패 뒤 다시 내는 손님)');


// ───────────────────────────────────────────────── 월말 결산 (2026-09-29)
// 한 달을 닫는 한 장 — 읽기 전용. 확정(실입금) · 입금 대기 · 취소를 나누고 뺄셈 장부 · 첫 주문/재구매 · 일별 · CSV.
require_once dirname(__DIR__, 2).'/includes/monthly.php';
$Mo = 'Duckhoo\\Redesign\\Monthly\\';
$ok(($Mo.'bounds')('2026-09') === ['2026-09-01','2026-09-30'] && ($Mo.'bounds')('2026-02') === ['2026-02-01','2026-02-28'], '달의 첫날 · 끝날 (2월 28일)');
$ok(($Mo.'default_ym')('2026-10-02') === '2026-09' && ($Mo.'default_ym')('2026-10-04') === '2026-10' && ($Mo.'default_ym')('2026-01-01') === '2025-12', '기본 달: 1~3일엔 지난달, 그 뒤엔 이번 달 · 해 넘김');
$ok(($Mo.'prev_ym')('2026-01') === '2025-12' && ($Mo.'kmonth')('2026-09') === '2026년 9월' && ($Mo.'valid_ym')('2026-13') === false && ($Mo.'valid_ym')('2026-09') === true, '한 달 앞 · 한글 달 이름 · 꼴 검사');
$mr = fn(int $id, string $d, string $s, float $t, int $c, float $p=0, float $coup=0, float $ship=0, float $fee=0) => ['id'=>$id,'d'=>$d,'ts'=>strtotime($d.' 12:00:00'),'s'=>$s,'t'=>$t,'c'=>$c,'p'=>$p,'coup'=>$coup,'ship'=>$ship,'fee'=>$fee];
$mrows = [
  $mr(1,'2026-09-01','delivered', 26000, 10),                       // 손님10 — 이 달 처음 (prior 에 없음)
  $mr(2,'2026-09-05','delivered', 42000, 10, 0, 0, 2500),           // 손님10 둘째 → 재구매 (같은 달)
  $mr(3,'2026-09-02','payment-confirmed', 120000, 11, 8800, 1000, 0, 0), // 손님11 — 전에도 산 회원, 적립금 · 쿠폰
  $mr(4,'2026-09-03','on-hold', 30000, 12),                         // 입금 대기
  $mr(5,'2026-09-03','cancelled', 50000, 13),                       // 취소
  $mr(6,'2026-09-10','delivered', 13000, 0),                        // 비회원
  $mr(7,'2026-09-11','checkout-draft', 99000, 14),                  // 임시글 — 아예 안 센다
  $mr(8,'2026-09-12','refunded', 20000, 11),                        // 환불
];
$mitems = [
  1=>[['pid'=>1,'name'=>'[노보] 타박멘솔 (9.8mg / 30ml)','qty'=>2,'total'=>26000]],
  2=>[['pid'=>2,'name'=>'[펠릭스] 더블라임 (9.8mg / 30ml)','qty'=>2,'total'=>40000]],
  3=>[['pid'=>3,'name'=>'[노보 리퀴드] 10+1','qty'=>1,'total'=>120000]],
  6=>[['pid'=>1,'name'=>'[노보] 타박멘솔 (9.8mg / 30ml)','qty'=>1,'total'=>13000]],
];
$mprev = [ $mr(90,'2026-08-10','delivered', 50000, 11), $mr(91,'2026-08-11','cancelled', 10000, 12), $mr(92,'2026-08-12','delivered', 30000, 15) ];
$mc = ($Mo.'close')($mrows, $mitems, [11=>true], $mprev, '2026-09', 40, 25, '2026-10-02');
$ok($mc['closed'] === true && $mc['days_done'] === 30 && $mc['all']['n'] === 7, '10월 2일에 본 9월은 닫힌 달 · 30일 · 임시글은 접수에서 뺀다');
$ok($mc['conf']['n'] === 4 && $mc['conf']['sales'] == 201000.0 && $mc['pend']['n'] === 1 && $mc['pend']['sales'] == 30000.0 && $mc['void']['n'] === 2, '확정 4건 201,000 · 입금 대기 1건 30,000 · 취소·환불 2건');
$ok($mc['cancel_rate'] === 29 && $mc['prev']['cancel_rate'] === 33, '취소율 2/7 = 29% · 지난달 1/3 = 33%');
$ok($mc['conf']['before'] == 210800.0 && $mc['conf']['ship'] == 2500.0 && $mc['conf']['goods'] == 208300.0 && $mc['conf']['points'] == 8800.0 && $mc['conf']['coupon'] == 1000.0, '뺄셈 장부: 할인 전 210,800 = 실입금 201,000 + 적립금 8,800 + 쿠폰 1,000 · 배송비 2,500 · 상품 208,300');
$cu = $mc['cust'];
$ok($cu['buyers'] === 2 && $cu['first_buyers'] === 1 && $cu['rep_buyers'] === 1 && $cu['first_n'] === 1 && $cu['first_sales'] == 26000.0 && $cu['rep_n'] === 2 && $cu['rep_sales'] == 162000.0 && $cu['guest_n'] === 1, '손님: 처음 산 회원 1(첫 주문 1건) · 전에도 산 회원 1 · 재구매 2건(같은 달 둘째 주문 포함) · 비회원 1건');
$ok($mc['aov'] == 50250 && $mc['per_day'] == 6700 && $mc['units'] === 6, '객단가 평균 50,250 · 하루 평균 6,700 · 수량 6');
$ok($mc['daily']['2026-09-03']['all'] === 2 && $mc['daily']['2026-09-03']['n'] === 0 && $mc['daily']['2026-09-03']['void'] === 1 && $mc['best_day'] === '2026-09-02' && count($mc['daily']) === 30, '일별: 9/3 접수 2 확정 0 취소 1 · 가장 큰 날 9/2 · 30줄');
$ok(array_key_first($mc['products']) === '10+1' && $mc['brands']['노보'] == 159000.0 && $mc['brands']['펠릭스'] == 40000.0, '상품 1위 10+1 · 브랜드 노보 159,000 · 펠릭스 40,000');
$ok($mc['by_status']['delivered']['n'] === 3 && !isset($mc['by_status']['checkout-draft']), '상태별: 배송완료 3 · 임시글 없음');
$ok($mc['prev']['conf']['sales'] == 80000.0 && $mc['prev']['aov'] == 40000, '지난달 확정 80,000 · 객단가 40,000');
$mrep = ($Mo.'report')($mc);
$ok(str_contains($mrep, '2026년 9월 결산') && str_contains($mrep, '확정(실입금) 4건 201,000원') && str_contains($mrep, '= 확정 매출 201,000원') && str_contains($mrep, '새 가입 40명 (지난달 25)') && str_contains($mrep, '09-03: 0 · 0 · 2 · 1') && !str_contains($mrep, '진행 중'), '보고서: 제목 · 확정 · 뺄셈 · 가입 · 일별 줄 · 닫힌 달엔 「진행 중」 없음');
$mo = ($Mo.'close')($mrows, $mitems, [11=>true], $mprev, '2026-09', 40, 25, '2026-09-15');
$ok($mo['closed'] === false && $mo['days_done'] === 15 && $mo['per_day'] == 13400 && str_contains(($Mo.'report')($mo), '진행 중 · 15일까지') && !str_contains(($Mo.'report')($mo), "\n09-16:"), '진행 중인 달: 15일 기준 하루 평균 · 보고서 일별은 15일까지');
$csv = ($Mo.'csv')($mrows, $mitems);
$lines = explode("\r\n", trim($csv));
$ok(str_starts_with($csv, "\xEF\xBB\xBF") && count($lines) === 8 && str_contains($lines[0], '"실결제"'), 'CSV: BOM · 머리 1줄 + 주문 7줄(임시글 제외)');
$ok(str_contains($csv, '"3","2026-09-02"') && str_contains($csv, '"[노보 리퀴드] 10+1","129800","129800","0","1000","8800","0","120000"'), 'CSV 한 줄: 할인 전 129,800 · 쿠폰 1,000 · 적립금 8,800 · 실결제 120,000');
$ok(str_contains($csv, '"1","2026-09-01"') && strpos($csv, '"1","2026-09-01"') < strpos($csv, '"3","2026-09-02"') && !str_contains($csv, '"7","2026-09-11"'), 'CSV 는 날짜순 · 임시글 없음');
$ok(!preg_match('/건강|금연|순하|해롭/', $mrep), '보고서에 금지어 없음');


// ───────────────────────────────────────────────── 아임웹 합치기 · SEO 월간 보고서 · 1~3분 글 (2026-09-29)
foreach (['delete_option'=>'function delete_option($k){ unset($GLOBALS["__options"][$k]); return true; }','delete_transient'=>'function delete_transient($k){ unset($GLOBALS["__transients"][$k]); return true; }','register_rest_route'=>'function register_rest_route(...$a){ return true; }','rest_ensure_response'=>'function rest_ensure_response($r){ return $r; }'] as $fn=>$src) if(!function_exists($fn)) eval($src);
require_once dirname(__DIR__, 2).'/includes/imweb.php';
require_once dirname(__DIR__, 2).'/includes/seo-report.php';
$Im = 'Duckhoo\\Redesign\\Imweb\\';
$ok(($Im.'classify')('PAY_WAIT')==='pend' && ($Im.'classify')('입금대기')==='pend' && ($Im.'classify')('CANCEL_COMPLETE')==='void' && ($Im.'classify')('환불완료')==='void' && ($Im.'classify')('DELIVERY_COMPLETE')==='paid' && ($Im.'classify')('결제완료')==='paid' && ($Im.'classify')('')==='paid', '아임웹 상태 낱말: 대기 · 취소/환불 · 나머지는 돈 들어옴 (영문 · 한글)');
$j = ['msg'=>'SUCCESS','code'=>200,'data'=>['list'=>[
  ['order_no'=>'A1','order_time'=>strtotime('2026-09-05 10:00:00 +0900'),'status'=>'DELIVERY_COMPLETE','payment'=>['total_price'=>30000,'payment_amount'=>28000]],
  ['order_no'=>'A2','order_time'=>strtotime('2026-09-06 10:00:00 +0900'),'status'=>'PAY_WAIT','payment'=>['total_price'=>13000]],
  ['order_no'=>'A3','order_time'=>strtotime('2026-08-31 23:30:00 +0900'),'status'=>'CANCEL','payment'=>['total_price'=>50000]],
], 'data_count'=>3,'current_page'=>1,'total_page'=>1,'pagesize'=>100]];
$os = ($Im.'parse_api')($j);
$ok(count($os)===3 && $os[0]['no']==='A1' && $os[0]['total']==28000.0 && $os[1]['total']==13000.0, 'API 응답: data.list 에서 주문 3건 · 실결제(payment_amount)가 있으면 그것');
$ok(($Im.'pages')($j)===[1,1] && ($Im.'pages')(['data'=>['list'=>[]]])===null && ($Im.'pages')(['data'=>['list'=>[], 'pagenation'=>['data_count'=>38,'current_page'=>1,'total_page'=>2,'pagesize'=>100]]])===[2,1], '쪽 넘김: total_page · current_page 있으면 [전체, 이번], 없으면 null · 실제 응답은 data.pagenation');
$ok(count(($Im.'parse_api')([['order_no'=>'B','status'=>'','total_price'=>1000]]))===1, '맨 위가 배열이어도 읽는다');
$sm = ($Im.'summarize')($os, '2026-09');
$ok($sm['n']===1 && $sm['sales']==28000.0 && $sm['pend_n']===1 && $sm['void_n']===0, '달로 거른다: 8/31 23:30 KST 취소는 9월이 아니다 · 돈 1건 28,000 · 대기 1');
$ok(($Im.'summarize')($os)['void_n']===1, '달을 안 주면 전부 센다');
// prod-orders 로 줄 상태 · 상품을 붙인다 (2026-09 실제 응답 꼴)
$po = ['msg'=>'SUCCESS','code'=>200,'data'=>[
  ['order_no'=>'202609119548991-001','status'=>'CANCEL','items'=>[['prod_name'=>'[젤로맥스] 젤로 맥스 0.6옴 팟','payment'=>['count'=>1,'price'=>13500]]]],
  ['order_no'=>'202609119548991-002','status'=>'COMPLETE','items'=>[['prod_name'=>'[젤로 크리스탈] 젤로 크리스탈 전자담배 + 액상 5병 증정','payment'=>['count'=>1,'price'=>69000]], ['prod_name'=>'[ZERO]맥스쿨 입호흡 5병','payment'=>['count'=>2,'price'=>45000]]]],
]];
$eo = ($Im.'enrich')(['no'=>'202609119548991','ts'=>strtotime('2026-09-11 10:00:00 +0900'),'status'=>'','total'=>82500.0,'paid_ts'=>1790000000], $po, '2026-09-11');
$ok($eo['status']==='' && $eo['void_part']==13500.0 && $eo['cost']==15000.0 && $eo['unknown']==90000.0 && $eo['items']===2, '일부 취소: 취소 줄 13,500 은 void_part · 젤로 세트 원가 15,000(아임웹 이름 별칭) · 맥스쿨은 원가 모름 90,000');
$eo2 = ($Im.'enrich')(['no'=>'X','ts'=>0,'status'=>'','total'=>10000.0,'paid_ts'=>1790000000], ['data'=>[['status'=>'CANCEL','items'=>[]],['status'=>'CANCEL_COMPLETE','items'=>[]]]]);
$eo3 = ($Im.'enrich')(['no'=>'Y','ts'=>0,'status'=>'','total'=>10000.0,'paid_ts'=>0], ['data'=>[]]);
$ok($eo2['status']==='CANCEL' && $eo3['status']==='PAY_WAIT', '줄이 전부 취소면 주문 취소 · 결제 시각이 0 이면 입금 대기');
$sm2 = ($Im.'summarize')([$eo, $eo2, $eo3]);
$ok($sm2['n']===1 && $sm2['sales']==69000.0 && $sm2['cost']==15000.0 && $sm2['unknown']==90000.0 && $sm2['void_n']===1 && $sm2['pend_n']===1, '요약: 매출은 취소 줄을 뺀 69,000 · 원가 · 모르는 매출 · 취소 1 · 대기 1');
$ok(str_contains(($Im.'line')(array_merge($sm2, ['src'=>'api','at'=>'x'])), '상품 원가 15,000원 (원가 모르는 매출 90,000원)'), '한 줄 요약에 원가');
$tsv = "주문번호\t주문일시\t주문상태\t상품명\t결제금액\n"
  . "20260901-1\t2026-09-01 12:00\t배송완료\t노보 타박멘솔\t26,000원\n"
  . "20260901-1\t2026-09-01 12:00\t배송완료\t노보 블랙멘솔\t26,000원\n"
  . "20260902-7\t2026-09-02 09:00\t입금대기\t펠릭스\t20,000\n"
  . "20260903-2\t2026-09-03 09:00\t취소\t화이트아웃\t13,000\n";
$ex = ($Im.'parse_export')($tsv);
$ok(count($ex)===3 && $ex[0]['no']==='20260901-1' && $ex[0]['total']==26000.0 && $ex[0]['status']==='배송완료', '붙여 넣기: 같은 주문번호 두 줄은 한 주문 · 결제금액 칸이면 첫 줄만 · 「원」 · 쉼표 뗌');
$sx = ($Im.'summarize')($ex, '2026-09');
$ok($sx['n']===1 && $sx['sales']==26000.0 && $sx['pend_n']===1 && $sx['void_n']===1, '붙여 넣기 요약: 돈 1건 26,000 · 대기 1 · 취소 1');
$csvx = "주문번호,상태,상품금액\nX1,결제완료,1000\nX1,결제완료,2000\n";
$ok(($Im.'parse_export')($csvx)[0]['total']==3000.0, '결제금액 칸이 없고 상품금액뿐이면 줄을 더한다 (쉼표 구분)');
$ok(($Im.'parse_export')("아무 글\n둘째 줄")===[] && ($Im.'parse_export')('')===[], '주문번호 · 금액 칸을 못 찾으면 빈 배열');
$GLOBALS['__options']['duckhoo_imweb_months'] = [];
($Im.'put')('2026-09', $sx, 'paste');
$ok(str_contains(($Im.'line')(($Im.'months')()['2026-09']), '돈 들어온 주문 1건 26,000원') && str_contains(($Im.'line')(null), '연결 안 됨'), '한 줄 요약 · 없으면 「연결 안 됨」');

require_once dirname(__DIR__, 2).'/includes/parcels.php';
$Pc = 'Duckhoo\\Redesign\\Parcels\\';
$pcsv = "\xEF\xBB\xBF소포주문번호,등록일자,배송진행 상태내역,미배달사유,등기번호,수취인명,우편번호,수취인주소,수취인상세주소,고객주문번호,상품코드,상품명,수량,상품모델,발송지,고객주문처,반품신청여부\n"
  . "1,2026-09-01,배달완료,배달,6890168438780,홍길동,08703,\"서울 관악구, 신림동\",206호,202609010003866,-,생활용품,1,,액상덕후,액상덕후W,\n"
  . "2,2026-09-01,배달완료,배달,6890168438787,김철수,50358,경남 창녕군,204호,202609010003865,-,생활용품,1,,액상덕후,액상덕후W,예\n"
  . "3,2026-09-07,배달완료,배달,6890168438790,이영희,38208,경북 경주시,,IM-2026-0907-01,-,생활용품,1,,액상덕후,액상덕후I,\n"
  . "4,2026-09-07,배달준비,,6890168438800,박민수,28780,충북 청주시,,,-,생활용품,1,,액상덕후,,\n"
  . "4,2026-09-07,배달준비,,6890168438800,박민수,28780,충북 청주시,,,-,생활용품,1,,액상덕후,,\n"
  . "5,2026-08-29,배달완료,배달,6890168438810,최지우,22736,인천 서구,,202608290003100,-,생활용품,1,,액상덕후,액상덕후W,\n"
  . "6,2026-09-08,배달완료,배달,6890168438820,정상용,22736,인천 서구,,202609010003866,-,생활용품,1,,액상덕후,액상덕후W,\n";
$pr = ($Pc.'parse')($pcsv);
$ok(count($pr)===6 && $pr[0]['d']==='2026-09-01' && $pr[0]['no']==='202609010003866' && $pr[0]['src']==='액상덕후W' && $pr[1]['ret']===true && $pr[3]['no']==='', '우체국 CSV: BOM · 따옴표 안 쉼표 · 같은 등기번호 두 줄은 한 상자 · 「-」 주문번호는 빈칸 · 반품 「예」');
$ps = ($Pc.'summarize')($pr, '2026-09');
$ok($ps['n']===5 && $ps['wp']===3 && $ps['imweb']===1 && $ps['none']===1 && $ps['ret']===1 && $ps['orders']===3 && $ps['multi']===1 && $ps['first']==='2026-09-01' && $ps['last']==='2026-09-08' && $ps['days']['2026-09-07']===2, '달 요약: 8월분 빼고 5상자 · 워드프레스 3 · 아임웹 1 · 번호 없음 1 · 반품 1 · 고유 주문 3 · 두 상자 주문 1 · 날짜별');
$ok(($Pc.'summarize')($pr)['n']===6, '달을 안 주면 전부');
$ok(($Pc.'origin')(['src'=>'','no'=>'202609010003866'])==='wp' && ($Pc.'origin')(['src'=>'액상덕후I','no'=>'x'])==='imweb' && ($Pc.'origin')(['src'=>'액상덕후','no'=>'AB12'])==='other', '주문처 글자가 없으면 15자리 주문번호 꼴로 워드프레스를 가른다');
$ok(($Pc.'cost')($ps, 2700)===13500 && ($Pc.'cost')($ps, 0)===0 && ($Pc.'cost')(null, 2700)===0, '지출 = 상자 × 단가 · 단가 모르면 0');
$ok(str_contains(($Pc.'line')($ps, 2700), '5상자') && str_contains(($Pc.'line')($ps, 2700), '13,500원') && str_contains(($Pc.'line')($ps, 0), '단가를 아직 안 넣어') && str_contains(($Pc.'line')(null), '아직 없음'), '한 줄 요약: 단가 있음 · 없음 · 자료 없음');
$ok(($Pc.'parse')("아무 글\n둘째")===[] && ($Pc.'parse')('')===[], '머리줄에 등록일자 · 등기번호가 없으면 빈 배열');
$ptsv = "등록일자\t등기번호\t고객주문번호\t고객주문처\n2026-09-03\t6890100000001\t202609030000001\t액상덕후W\n";
$ok(count(($Pc.'parse')($ptsv))===1, '탭 구분도 읽는다');
$GLOBALS['__options']['duckhoo_parcel_months'] = [];
($Pc.'put')('2026-09', $ps);
$ok(($Pc.'month')('2026-09')['n']===5 && ($Pc.'month')('2026-08')===null, '달마다 저장 · 없는 달은 null');
unset($GLOBALS['__options']['duckhoo_parcel_unit']);
$ok(($Pc.'unit')()===2150 && ($Pc.'cost')($ps, ($Pc.'unit')())===10750, '단가 옵션이 비어 있으면 2026-09 정산내역의 기본 단가 2,150 · 5상자 = 10,750원');
$GLOBALS['__options']['duckhoo_parcel_unit'] = 2300;
$ok(($Pc.'unit')()===2300, '옵션에 넣은 단가가 이긴다');
unset($GLOBALS['__options']['duckhoo_parcel_unit']);
$ok(($Pc.'pack_unit')()===0 && ($Pc.'pack_cost')($ps, 0)===0 && ($Pc.'total_cost')($ps, 2150, ($Pc.'pack_unit')())===10750, '박스비는 기본 제외(0) — 지출 합계는 배송비만');
$GLOBALS['__options']['duckhoo_parcel_pack'] = 450;
$ok(($Pc.'pack_unit')()===450 && ($Pc.'pack_cost')($ps, 450)===2250 && ($Pc.'total_cost')($ps, 2150, 450)===13000, '박스비 450원 × 5상자 = 2,250 · 지출 합계 13,000');
$pl = ($Pc.'line')($ps, 2150, 450);
$ok(str_contains($pl, '우체국 단가 2,150원 = 배송비 10,750원') && str_contains($pl, '박스비 450원 × 5 = 2,250원') && str_contains($pl, '지출 합계 13,000원') && str_contains(($Pc.'line')($ps, 2150, 0), '박스비 단가는 아직 없음'), '한 줄 요약에 배송비 · 박스비 · 지출 합계');
unset($GLOBALS['__options']['duckhoo_parcel_pack']);

$SR = 'Duckhoo\\Redesign\\Seo\\Report\\';
$GLOBALS['__options']['duckhoo_worklog'] = [];
$ok(($SR.'log_add')('seo', '  노보 15종  제목 새로 씀 ', '2026-09-21') === true && ($SR.'log_add')('seo', '   ') === false, '작업 일지: 빈칸 정리 · 빈 글은 안 적음');
($SR.'log_add')('shop', '월말 결산 화면 만듦', '2026-09-29'); ($SR.'log_add')('seo', '8월 것', '2026-08-30');
$ok(count(($SR.'entries')('2026-09'))===2 && count(($SR.'entries')('2026-09','seo'))===1 && ($SR.'entries')('2026-09')[0]['t']==='노보 15종 제목 새로 씀', '달 · 구역으로 거르고 날짜순');
$views = [101=>40, 102=>25, 103=>9, 104=>3];
$info  = [101=>['name'=>'타박멘솔','text'=>true,'out'=>false], 102=>['name'=>'더블라임','text'=>false,'out'=>false], 103=>['name'=>'크로닉 모드','text'=>false,'out'=>true], 104=>['name'=>'체리','text'=>true,'out'=>false]];
$src  = ['src_google'=>30,'src_naver'=>50,'src_daum'=>2,'src_other'=>10,'src_direct'=>60];
$srcp = ['src_google'=>20,'src_naver'=>30,'src_daum'=>0,'src_other'=>5,'src_direct'=>40];
$snap = ['products'=>184,'with_text'=>140,'cats'=>8,'cats_empty'=>1,'cats_long'=>0,'brands'=>12];
$snpv = ['products'=>184,'with_text'=>120,'cats'=>8,'cats_empty'=>3,'cats_long'=>1,'brands'=>12];
$r = ($SR.'build')('2026-09', $src, $srcp, $views, $info, $snap, $snpv, ($SR.'entries')('2026-09','seo'), ['signups'=>130,'first_buyers'=>95]);
$ok($r['search']===82 && $r['search_prev']===50 && $r['all']===152, '검색 유입 82(구글 30 + 네이버 50 + 다음 2) · 지난달 50 · 전체 152');
$ok(count($r['did'])===4 && str_contains($r['did'][0], '09-21 노보 15종') && in_array('글 있는 상품 120 → 140', $r['did'], true) && in_array('설명 없는 분류 3 → 1', $r['did'], true), '이렇게 했고: 일지 + 스냅샷 차이(글 · 설명 없는 분류 · 긴 설명)');
$ok(count($r['top'])===4 && $r['no_text'][0]['name']==='더블라임' && $r['out_hot'][0]['name']==='크로닉 모드', '많이 본 상품 · 글 없는 것 · 품절인데 찾는 것');
$ok(str_contains($r['next'][0], '더블라임(25명)') && str_contains(implode(' ', $r['next']), '품절인데 계속 찾는') && str_contains(implode(' ', $r['next']), '설명 없는 분류 1개'), '앞으로: 글 붙일 상품 · 품절 · 빈 분류');
$t = ($SR.'text')($r, true);
$ok(str_starts_with($t, '**검색 노출 월간 보고 — 2026년 9월**') && str_contains($t, '**이렇게 했고**') && str_contains($t, '검색에서 들어온 사람 82명 (구글 30 · 네이버 50 · 다음 2) — 지난달 대비 +64%') && str_contains($t, '새 가입 130명 · 이 달 처음 산 회원 95명') && str_contains($t, '**앞으로**') && !str_contains(($SR.'text')($r,false), '**'), 'SEO 글: 제목 · 세 절 · 검색 유입 +64% · 가입 · 굵기는 디스코드만');
$ok(($SR.'pct_delta')(82,50)==='+64%' && ($SR.'pct_delta')(40,50)==='−20%' && ($SR.'pct_delta')(5,0)==='', '지난달 대비 %');
$ok(!preg_match('/건강|금연|순하|해롭/', $t), 'SEO 글에 금지어 없음');
$r0 = ($SR.'build')('2026-09', ['src_google'=>0,'src_naver'=>0,'src_daum'=>0,'src_other'=>3,'src_direct'=>9], [], [], [], null, null, [], []);
$ok(str_contains(($SR.'text')($r0,false), '이 달에 적힌 작업이 없습니다') && str_contains(implode(' ', $r0['next']), '검색 유입이 0'), '아무것도 없을 때: 일지 안내 · 검색 유입 0 경고');

// 1~3분 글 — 결산 + 아임웹
$bt = ($Mo.'brief_text')($mc, ['n'=>3,'sales'=>90000,'pend_n'=>1,'pend'=>10000,'void_n'=>0,'void'=>0,'src'=>'api','at'=>'2026-10-01 09:00'], ($SR.'entries')('2026-09'), '', false);
$ok(str_starts_with($bt, '액상덕후 2026년 9월 결산') && str_contains($bt, '이런 결과') && str_contains($bt, '실제 들어온 돈 201,000원 (4건)') && str_contains($bt, '아임웹까지 합치면 291,000원 (아임웹 3건 90,000원)') && str_contains($bt, '처음 산 회원 1명 · 다시 산 회원 1명 · 새 가입 40명 (지난달 25)') && str_contains($bt, '취소 · 환불 2건 ') && str_contains($bt, '원 = 접수의 29% (지난달 33%)'), '1~3분 글: 결과 — 실입금 · 아임웹 합산 · 손님 · 취소');
$ok(str_contains($bt, '이렇게 했고') && str_contains($bt, '09-21 노보 15종') && str_contains($bt, '09-29 월말 결산 화면 만듦') && str_contains($bt, '앞으로') && str_contains($bt, '취소가 접수의 29%'), '1~3분 글: 이렇게 했고(일지) · 앞으로(취소율 20% 넘음)');
$bt2 = ($Mo.'brief_text')($mc, null, [], '**검색 노출 월간 보고**', true);
$ok(str_contains($bt2, '아임웹은 아직 안 합쳐짐') && str_contains($bt2, '아임웹 주문을 합치려면') && str_ends_with($bt2, '**검색 노출 월간 보고**') && str_starts_with($bt2, '**액상덕후'), '아임웹 없으면 안내 두 줄 · SEO 글은 끝에 · 디스코드 굵기');
$ok(!preg_match('/건강|금연|순하|해롭/', $bt), '1~3분 글에 금지어 없음');
$ok(str_contains(($Mo.'report')($mc), '[아임웹: 연결 안 됨') || str_contains(($Mo.'report')($mc), '[아임웹: 돈 들어온'), '붙여 넣기용 글에도 아임웹 줄');
// 크론 문 — 4일 이후 · 창 밖에서는 안 보낸다
$GLOBALS['__options']['duckhoo_monthly_sent'] = ''; $GLOBALS['__now'] = mktime(9,0,0,10,5,2026); ($Mo.'cron_send')();
$ok(($GLOBALS['__options']['duckhoo_monthly_sent'] ?? '') === '', '10월 5일에는 안 보낸다');
$GLOBALS['__now'] = mktime(15,0,0,10,1,2026); ($Mo.'cron_send')();
$ok(($GLOBALS['__options']['duckhoo_monthly_sent'] ?? '') === '', '1일이라도 09:00 창 밖(15:00)이면 안 보낸다');


// ── 원가표 → 순이익 (includes/cost.php) ─────────────────────────────────
require_once dirname(__DIR__, 2).'/includes/cost.php';
$Co = 'Duckhoo\\Redesign\\Cost\\';
unset($GLOBALS['__options']['duckhoo_costs']);
$ok(($Co.'norm')('[액상덕후] 링고 흑염룡 시리즈 (0.98MG / 30ml) (멘솔 없음)') === ($Co.'norm')('[액상덕후] 링고 흑염룡 시리즈 (0.98MG/30ml)') && ($Co.'norm')('★ 입고완료 ★ 크래프트 5병 EVENT !') === ($Co.'norm')('입고완료 크래프트 5병 EVENT'), '이름 정규화: 빈칸 · ! · ★ · 「(멘솔 없음)」 무시 · 대소문자');
$ok(($Co.'bottles')('[노보 리퀴드] 10+1 | 금액 120,000원') === 11 && ($Co.'bottles')('[디오리퀴드] 5+5 묶음 이벤트') === 10 && ($Co.'bottles')('[10병 묶음 할인 이벤트] 덕후 액상 10병 할인 !') === 10 && ($Co.'bottles')('[젤로 크리스탈] 젤로 크리스탈 기기 + 액상 5병 증정 이벤트 !') === 0 && ($Co.'bottles')('[노보] 타박멘솔 (9.8mg / 30ml)') === 1 && ($Co.'bottles')('[부푸] 브이메이트 V5 0.7옴팟') === 0, '병 수: 10+1 → 11 · 5+5 → 10 · 10병 → 10 · 기기 증정 · 팟은 0 · 낱병 1');
$lc = ($Co.'line_cost')('[노보] 타박멘솔 (9.8mg / 30ml)', 2, 242);
$ok($lc && $lc['cost'] == 12000.0 && $lc['src'] === '이름', '이름이 맞으면 표의 원가 × 수량 (타박멘솔 2병 12,000 — 날짜 없으면 최근 값)');
$lc = ($Co.'line_cost')('[노보 리퀴드] 10+1 | 금액 120,000원', 1, 4327);
$ok($lc && $lc['cost'] == 66000.0 && $lc['src'] === '브랜드', '이름이 없으면 브랜드 병당 × 병 수 (노보 10+1 = 6,000 × 11)');
$lc = ($Co.'line_cost')('[젤로 크리스탈] 젤로 크리스탈 기기 + 액상 5병 증정 이벤트 !', 2, 207);
$ok($lc && $lc['cost'] == 30000.0 && $lc['src'] === '번호', '이름이 달라도 상품 번호로 맞춘 것 (#207 15,000 × 2)');
$ok(($Co.'line_cost')('[맥스쿨] 맥스쿨 소다 무니코틴 액상', 1, 1283) === null && ($Co.'line_cost')('여기서 결제 도와드리겠습니다!', 1, 0) === null, '표에도 브랜드에도 없으면 null (숫자를 만들지 않는다)');
$ok(($Co.'bottles')('노보 타박멘솔2000, 노보 블랙멘솔 1000 결제') === 0 && ($Co.'line_cost')('노보 타박멘솔2000, 노보 블랙멘솔 1000 결제', 1, 0) === null && ($Co.'bottles')('[노보 리퀴드] 10+1 | 금액 120,000원') === 11 && ($Co.'bottles')('[액상덕후] 링고 흑염룡 시리즈 (0.98MG / 30ml)') === 1, '이름에 맨몸 큰 숫자(수량이 숨은 수동 결제)는 병 수 0 → 원가 모름 · 120,000원 · 0.98MG · 30ml 은 그대로');
$ok(($Co.'line_cost')('[노보 블랙] 블랙멘솔 (9.8mg / 30ml)', 1, 249)['cost'] == 6000.0 && ($Co.'line_cost')('[노보 블랙 리퀴드] 10+1 | 금액 130,000원', 1, 146)['cost'] == 66000.0, '노보 · 노보 블랙 지금 6,000 (사장님 2026-09-29) · 블랙 10+1 = 66,000');
$GLOBALS['__options']['duckhoo_costs'] = ['brand'=>['맥스쿨'=>3000], 'name'=>[($Co.'norm')('[노보] 타박멘솔 (9.8mg / 30ml)')=>5500]];
$lc = ($Co.'line_cost')('[맥스쿨] 맥스쿨 소다 무니코틴 액상', 3, 1283);
$ok($lc && $lc['cost'] == 9000.0 && ($Co.'line_cost')('[노보] 타박멘솔 (9.8mg / 30ml)', 1, 242)['cost'] == 5500.0, '관리자 옵션이 씨앗보다 먼저 (맥스쿨 3,000 × 3 · 타박멘솔 5,500)');
unset($GLOBALS['__options']['duckhoo_costs']);
$mconf = array_values(array_filter($mrows, fn($r) => in_array($r['s'], ['delivered','payment-confirmed'], true)));
$mcst = ($Co.'month_cost')($mconf, $mitems);
$ok($mcst['cost'] == 93000.0 && $mcst['known'] == 199000.0 && $mcst['unknown'] == 0.0 && $mcst['lines'] === 4 && $mcst['by_src']['이름'] === 3 && $mcst['by_src']['브랜드'] === 1, '한 달 원가(주문 날짜별): 9/1 타박멘솔 2병 10,000 + 펠릭스 22,000 + 9/2 노보 10+1 55,000 + 9/10 타박멘솔 6,000 = 93,000 · 아는 매출 199,000');
$mit2 = $mitems; $mit2[6] = [['pid'=>0,'name'=>'여기서 결제 도와드리겠습니다!','qty'=>1,'total'=>13000]];
$mcst2 = ($Co.'month_cost')($mconf, $mit2);
$ok($mcst2['cost'] == 87000.0 && $mcst2['unknown'] == 13000.0 && $mcst2['miss'] === 1 && array_key_first($mcst2['unknown_list']) === '여기서 결제 도와드리겠습니다!', '모르는 줄은 원가에 안 넣고 매출을 따로 센다');
$mcst3 = ($Co.'month_cost')($mconf, $mit2, array_replace(($Co.'table')(), ['order'=>[6=>9000.0]]));
$ok($mcst3['cost'] == 96000.0 && $mcst3['unknown'] == 0.0 && $mcst3['by_src']['주문'] === 1, '주문별 원가를 적으면 그 주문은 통째로 그 값');
$pf = ($Co.'profit')(201000.0, 2500.0, $mcst);
$ok($pf['cost'] == 93000.0 && $pf['profit'] == 105500.0 && $pf['rate'] == 52.5 && $pf['cost_rate'] == 46.7 && $pf['coverage'] === 100, '순이익 = 201,000 − 2,500 − 93,000 = 105,500 (52.5%) · 원가율 46.7% · 원가 아는 비율 100%');
$pp = ($Co.'parse')("[노보] 타박멘솔 (9.8mg / 30ml) = 5,500원\n브랜드 맥스쿨 3000\n#207\t16000\n주문 202609180004850 15,000,000\n\n# 주석은 건너뜀\n이름만 있는 줄\n[빌런] 5병 EVENT, 0");
$ok($pp['name'][($Co.'norm')('[노보] 타박멘솔 (9.8mg / 30ml)')] == 5500.0 && $pp['brand']['맥스쿨'] == 3000.0 && $pp['pid'][207] == 16000.0 && $pp['order'][202609180004850] == 15000000.0 && count($pp['name']) === 2 && $pp['name'][($Co.'norm')('[빌런] 5병 EVENT')] == 0.0, '붙여 넣기: 이름 = 원가(쉼표 · 원) · 브랜드 · #번호 · 주문 · 주석 · 0 은 지우기');
$ok(($Co.'at')(5000) == 5000.0 && ($Co.'at')(['' => 5000, '2026-09-15' => 6000], '2026-09-14') == 5000.0 && ($Co.'at')(['' => 5000, '2026-09-15' => 6000], '2026-09-15') == 6000.0 && ($Co.'at')(['' => 5000, '2026-09-15' => 6000]) == 6000.0 && ($Co.'at')(['2026-09-15' => 6000], '2026-09-01') == 0.0, '날짜별 원가: 그날 전은 옛 값 · 그날부터 새 값 · 날짜 없으면 최근 값 · 시작 전이면 0');
$td = array_replace(($Co.'table')(), ['brand' => array_replace(($Co.'table')()['brand'], ['노보' => ['' => 5000, '2026-09-15' => 6000]])]);
$ok(($Co.'line_cost')('[노보 리퀴드] 10+1', 1, 0, $td, '2026-09-10')['cost'] == 55000.0 && ($Co.'line_cost')('[노보 리퀴드] 10+1', 1, 0, $td, '2026-09-20')['cost'] == 66000.0, '9/10 주문은 5,000 × 11 · 9/20 주문은 6,000 × 11');
$mcd = ($Co.'month_cost')($mconf, [3=>[['pid'=>0,'name'=>'[노보 리퀴드] 10+1','qty'=>1,'total'=>120000]], 6=>[['pid'=>0,'name'=>'[노보 리퀴드] 10+1','qty'=>1,'total'=>120000]]], $td);
$ok($mcd['cost'] == 110000.0, '한 달 원가가 주문 날짜(9/2 · 9/10)로 옛 값을 쓴다: 55,000 × 2');
$pd = ($Co.'parse')("브랜드 노보 6000 2026-09-15부터\n브랜드 노보 5000\n[닷모드] 닷모드 투엑스 0.6옴팟(2EA) = 8,000 (2026-09-20부터)");
$ok($pd['brand']['노보'] == ['' => 5000.0, '2026-09-15' => 6000.0] && $pd['name'][($Co.'norm')('[닷모드] 닷모드 투엑스 0.6옴팟(2EA)')] == ['2026-09-20' => 8000.0], '붙여 넣기 「…부터」: 날짜 배열로, 날짜 없는 줄은 옛 값(빈 키)');
$ok(($Co.'merge_costs')(['노보' => 5000], ['노보' => ['2026-09-15' => 6000]]) == ['노보' => ['' => 5000, '2026-09-15' => 6000]] && ($Co.'merge_costs')(['노보' => 5000], ['노보' => 6000]) == ['노보' => 6000] && ($Co.'merge_costs')(['노보' => ['' => 5000, '2026-09-08' => 6000]], ['노보' => 5500]) == ['노보' => 5500], '겹치기: 숫자 위에 날짜가 오면 옛 값이 빈 키로 남는다 · 날짜 없이 적은 숫자는 이력째 덮는다');
$ok(($Co.'line_cost')('[부푸] 브이메이트 V5 0.7옴팟', 2, 164)['cost'] == 18000.0 && ($Co.'line_cost')('[부푸] 브이메이트 V4 0.7옴팟', 1, 164, null, '2026-08-01')['cost'] == 6000.0, '브이메이트: 9/4 부터 V5 9,000 · 그 전은 V4 6,000');
$ok(($Co.'line_cost')('[노보] 타박멘솔 (9.8mg / 30ml)', 1, 242, null, '2026-09-07')['cost'] == 5000.0 && ($Co.'line_cost')('[노보] 타박멘솔 (9.8mg / 30ml)', 1, 242, null, '2026-09-08')['cost'] == 6000.0 && ($Co.'line_cost')('[노보 블랙] 쿠바시가 (9.8mg / 30ml)', 1, 3888, null, '2026-09-01')['cost'] == 5000.0 && ($Co.'line_cost')('[초특가] 노보 10병 병당 7,000원 / 금액 70,000원', 1, 146, null, '2026-09-07')['cost'] == 50000.0, '노보: 9/7 까지 5,000 · 9/8 부터 6,000 (이름 · 브랜드 · 옛 10병 이름 모두)');
$ok(($Co.'line_cost')('[닷모드] 닷모드 투엑스 0.6옴팟(2EA)', 1, 173, null, '2026-09-03')['cost'] == 7200.0 && ($Co.'line_cost')('[닷모드] 닷모드 투엑스 0.6옴팟(2EA)', 1, 173)['cost'] == 8000.0, '닷모드: 9/3 주문 7,200 · 지금 8,000');
$sw = ($Co.'switch_date')(['2026-08-01'=>[8000=>3], '2026-08-20'=>[8000=>2, 13000=>1], '2026-09-03'=>[13000=>4], '2026-09-10'=>[13000=>2]]);
$ok($sw['from'] === '2026-09-03' && $sw['old'] === 8000 && $sw['new'] === 13000 && ($Co.'switch_date')([])['from'] === '', '판매가 바뀐 날: 새 단가가 자리 잡은 첫 날(9/3) · 옛 단가 8,000 · 빈 것은 빈 문자열');
$mc3 = ($Mo.'close')($mrows, $mitems, [11=>true], $mprev, '2026-09', 40, 25, '2026-10-02');
$ok(is_array($mc3['cost']) && $mc3['cost']['cost'] == 93000.0, '결산 close() 에 원가가 실린다');
$GLOBALS['__options']['duckhoo_parcel_months'] = []; unset($GLOBALS['__options']['duckhoo_parcel_unit']);
$mrep3 = ($Mo.'report')($mc3);
$ok(str_contains($mrep3, '[원가] 상품 원가 93,000원 (원가 아는 매출의 46.7%) → 순이익 108,000원 = 실입금의 53.7%') && !str_contains($mrep3, '원가 모르는'), '보고서 [원가] 줄: 지출이 없으면 실입금 − 원가');
$mbt3 = ($Mo.'brief_text')($mc3, null, [], '', false);
$ok(str_contains($mbt3, '· 순이익 108,000원 (실입금의 53.7%)'), '사장님용 한 장에 순이익 줄');
$mc4 = ($Mo.'close')($mrows, $mit2, [11=>true], $mprev, '2026-09', 40, 25, '2026-10-02');
$ok(str_contains(($Mo.'report')($mc4), '원가 모르는 매출 13,000원 (여기서 결제 도와드리겠습니다! 13,000)'), '모르는 매출은 이름과 함께 적는다');

/* ── 노보 품절 국면 SEO (includes/novo-seo.php, 2026-10-01) ─────────────────── */
require_once dirname(__DIR__, 2).'/includes/novo-seo.php';
$NS = 'Duckhoo\\Redesign\\Novo\\Seo\\';
if (!function_exists('wp_insert_post')) { function wp_insert_post($a){ $GLOBALS['__inserted'][] = $a; return count($GLOBALS['__inserted']) + 9000; } }
$GLOBALS['__products'] = [
  7101 => new WC_Product(7101, '[노보] 타박멘솔 (9.8mg / 30ml)', 13000.0, true),
  7102 => new WC_Product(7102, '[노보] 블랙멘솔 (9.8mg / 30ml)', 13000.0, true),
  7103 => new WC_Product(7103, '[노보 블랙] 쿠바시가 (9.8mg / 30ml)', 13500.0, true),
  7104 => new WC_Product(7104, '[노보 블랙] 데저트 (9.8mg / 30ml)', 13500.0, false),   // 품절 — 빠져야 한다
  7105 => new WC_Product(7105, '[노보 리퀴드] 10+1 | 금액 120,000원', 120000.0, true),
  7106 => new WC_Product(7106, '[펠릭스] 더블라임 (9.8mg / 30ml)', 20000.0, true),      // 노보 아님
];
// 2026-10-02 — 그린펀치 품절. 낱병 한 맛이라도 없으면 「전 라인」이라고 하지 않는다 (7104 데저트가 품절)
$GLOBALS['dhr_test'] = true;
$N0 = 'Duckhoo\\Redesign\\Novo\\';
$st = ($N0.'stock_state')();
$ok($st['total'] === 4 && $st['in'] === 3 && $st['out'] === ['노보 블랙 데저트'] && $st['bundles'] === 1 && $st['all'] === false, '재고 상태: 낱병 4종 중 3종 · 품절 「노보 블랙 데저트」 · 묶음 1 · 전 라인 아님');
$ok(($N0.'stock_phrase')('noun') === '낱병 3종' && ($N0.'stock_phrase')('short') === '낱병 3종 재고 있음' && ($N0.'stock_phrase')('hold') === '낱병 3종 재고 보유' && ($N0.'out_note')() === '지금 품절: 노보 블랙 데저트', '품절이 있으면 「낱병 N종」 + 품절 맛 이름');
$fa = ($NS.'faq')();
$ok(str_contains($fa[0]['a'], '낱병 3종을 재고로') && str_contains($fa[0]['a'], '지금 품절: 노보 블랙 데저트') && !str_contains($fa[0]['a'], '전 라인'), 'FAQ 첫 답이 「전 라인」 대신 낱병 수와 품절 맛을 말한다');
$ok(\Duckhoo\Redesign\Seo\brand_notes()['노보']['title'] === '낱병 3종 재고 보유 · 바로 주문' && str_contains(\Duckhoo\Redesign\Seo\brand_notes()['노보']['lead'], '낱병 3종 재고를'), '브랜드 제목 · 소개도 같은 말');
$ok(\Duckhoo\Redesign\Front\novo_announce() === '노보 액상 낱병 3종 · 10+1 묶음 재고 있음', '홈 띠 — 숫자를 상품에서 읽고 「전 라인」을 뺀다');
$GLOBALS['__is_tax'] = true; $GLOBALS['__filters']['duckhoo_novo_stock_banner'] = [];
ob_start(); ($N0.'banner')(); $bn2 = ob_get_clean();
$ok(str_contains($bn2, '낱병 3종 재고 있습니다') && str_contains($bn2, '지금 품절: 노보 블랙 데저트') && !str_contains($bn2, '전 라인'), '분류 배너 — 「낱병 3종 재고 있습니다」 + 품절 줄');
$GLOBALS['__products'][7104] = new WC_Product(7104, '[노보 블랙] 데저트 (9.8mg / 30ml)', 13500.0, true);   // 입고
$st = ($N0.'stock_state')();
$ok($st['all'] === true && $st['out'] === [] && ($N0.'stock_phrase')('noun') === '전 라인' && \Duckhoo\Redesign\Front\novo_announce() === '노보 액상 전 라인 재고 있음 · 낱병 4종 · 10+1 묶음' && \Duckhoo\Redesign\Seo\brand_notes()['노보']['title'] === '전 라인 재고 보유 · 바로 주문', '다 들어오면 저절로 「전 라인」으로 돌아간다');
ob_start(); ($N0.'banner')(); $bn2 = ob_get_clean();
$ok(str_contains($bn2, '전 라인 재고 있습니다') && !str_contains($bn2, '지금 품절'), '배너도 「전 라인」으로');
$GLOBALS['__products'][7104] = new WC_Product(7104, '[노보 블랙] 데저트 (9.8mg / 30ml)', 13500.0, false);   // 도로 품절 (아래 테스트 전제)
$GLOBALS['__is_tax'] = false;
$me  = $GLOBALS['__products'][7101];
$sib = ($NS.'siblings')($me);
$sid = array_map(fn($p) => $p->get_id(), $sib);
$ok($sid === [7102, 7103, 7105], '형제: 자기 자신 · 품절 · 다른 브랜드는 빼고, 같은 라인 낱병 → 다른 라인 → 묶음 순');
$ok(($NS.'flavor')($GLOBALS['__products'][7103]) === '쿠바시가' && ($NS.'flavor')($GLOBALS['__products'][7105]) === '10+1 묶음' && ($NS.'line_label')($GLOBALS['__products'][7103]) === '노보 블랙', '맛 이름은 규격 · 금액 꼬리를 떼고, 묶음엔 「묶음」을 붙인다');
$fl = ($NS.'flavors_by_line')();
$ok(($fl['노보'] ?? []) === ['블랙멘솔','타박멘솔'] && ($fl['노보 블랙'] ?? []) === ['쿠바시가'], '라인별 맛 목록은 재고 있는 낱병만 (품절 데저트 제외)');
$faq = ($NS.'faq')();
$faqtxt = implode(' ', array_map(fn($i) => $i['q'].' '.$i['a'], $faq));
$ok(count($faq) >= 5 && str_contains($faqtxt, '품절') && str_contains($faqtxt, '어디서') && str_contains($faqtxt, '13,000원') && str_contains($faqtxt, '낱병 3종'), 'FAQ 는 손님이 치는 말(품절 · 어디서)을 질문에 두고 값 · 종수는 상품에서 읽는다');
$ok(!preg_match('/건강|금연|순하다|해롭지/u', $faqtxt) && !preg_match('/노보마트|브이몬스터|겨울마을|다른 (곳|가게|사이트)/u', $faqtxt), 'FAQ 에 금지 낱말 · 다른 가게 얘기가 없다');
$ok(str_contains($faqtxt, '노보 액상 종류는 노보 2종 · 노보 블랙 1종') && !str_contains($faqtxt, '쿠바시가') && !str_contains($faqtxt, '타박멘솔'), 'FAQ 「노보 액상 종류」는 라인별 종수만 — 맛 이름은 그 상품 한 장에만 (10/6 교훈)');
$nt = ($NS.'notice_text')('2026-10');
$ok(str_contains($nt['title'], '2026년 10월') && str_contains($nt['content'], '낱병 3종') && str_contains($nt['content'], '120,000원') && !preg_match('/건강|금연|순하다|해롭지/u', $nt['content']), '안내 글: 달이 제목에, 종수 · 값은 상품에서, 금지 낱말 없음');
$GLOBALS['__inserted'] = []; unset($GLOBALS['__options']['duckhoo_novo_notice_post']);
($NS.'ensure_notice')(); ($NS.'ensure_notice')();
$ok(count($GLOBALS['__inserted']) === 1 && $GLOBALS['__inserted'][0]['post_status'] === 'draft' && $GLOBALS['__inserted'][0]['post_type'] === 'post', '안내 글은 초안(draft)으로 딱 한 번만 만든다 — 공개는 사장님이 누른다');
$GLOBALS['__products'] = [];

/* ── 넓은 말 SEO — 「전담 액상」 목표 (includes/broad-seo.php · pages.php v4, 2026-10-01) ─── */
require_once dirname(__DIR__, 2).'/includes/broad-seo.php';
$BR = 'Duckhoo\\Redesign\\Seo\\Broad\\'; $GLOBALS['dhr_test'] = true;
$GLOBALS['__transients'] = [];
$GLOBALS['__products'] = [
  8101 => new WC_Product(8101, '[노보] 타박멘솔 (9.8mg / 30ml)', 13000.0, true),
  8102 => new WC_Product(8102, '[디오리퀴드] 로젤하트 (9.8mg / 30ml)', 8000.0, true),
  8103 => new WC_Product(8103, '[펠릭스] 더블라임 (9.8mg / 30ml)', 20000.0, true),
  8104 => new WC_Product(8104, '[노보 리퀴드] 10+1 | 금액 120,000원', 120000.0, true),
  8105 => new WC_Product(8105, '[화이트아웃] 체리 (9.8mg / 30ml)', 7000.0, false),   // 품절 — 최저가에서 빠져야 한다
  8106 => new WC_Product(8106, '[조바] 젤로 기기', 58000.0, true),                     // 기기 — 액상 값에서 빠져야 한다
];
$mtl = (object) ['slug' => '%ec%9e%85%ed%98%b8%ed%9d%a1-%ec%95%a1%ec%83%81', 'name' => '입호흡 액상', 'description' => ''];
$dlc = (object) ['slug' => 'x-dl', 'name' => '폐호흡 액상', 'description' => ''];
$ok(($BR.'kind')($mtl) === 'mtl' && ($BR.'kind')($dlc) === 'dl' && ($BR.'kind')((object)['name'=>'무니코틴 입호흡 액상']) === '' && ($BR.'kind')((object)['name'=>'기기 / 팟 / 코일']) === '', '분류는 이름으로 가른다 (슬러그는 퍼센트 인코딩) · 무니코틴 · 기기는 아니다');
$ct = ($BR.'cat_title')($mtl);
$ok($ct === '입호흡 액상 5종 가격 8,000원~ | 전자담배 입호흡(MTL) 액상 사이트 액상덕후', "입호흡 제목: 종수(재고 5) · 최저가(8,000 — 품절 7,000 제외) · 「전자담배 입호흡(MTL) 액상 사이트」 → {$ct}");
$ok(str_starts_with(($BR.'cat_title')($dlc), '폐호흡 액상') && str_contains(($BR.'cat_title')($dlc), '폐호흡(DL) 액상 사이트'), '폐호흡 제목');
$cd = ($BR.'cat_text')($mtl);
$ok(str_contains($cd, '입호흡(MTL) 전자담배 액상 5종') && str_contains($cd, '노보 · 디오리퀴드 · 펠릭스') && str_contains($cd, '낱병 8,000원부터') && str_contains($cd, '병당 약 10,900원') && str_contains($cd, '19세'), '입호흡 설명: 종류 · 브랜드(재고 있는 것만, 많은 순) · 낱병 · 병당 · 19세');
$ok(!preg_match('/건강|금연|순하|해롭/u', $cd.$ct), '분류 글에 광고 제한 낱말 없음');
// seo.php 가 입호흡 분류를 noted_cat 으로 보고 Broad 로 넘긴다
$GLOBALS['__is_ptax'] = true; $GLOBALS['__qobj'] = $mtl;
$ok(($S2.'title')('입호흡 액상 가격 8,000원~ | 69종 모음 - 액상덕후') === $ct, '분류 화면 <title> 은 손으로 쓴 옛 값(69종)이 아니라 우리 제목 — 종수가 상품을 따라간다');
$ok(str_contains(($S2.'description')('옛 설명'), '입호흡(MTL) 전자담배 액상'), '분류 메타 설명도 우리 글');
add_filter('duckhoo_broad_cats', fn($v = null) => false);
$ok(($S2.'title')('그대로') === '그대로', '필터 duckhoo_broad_cats → false 면 AIOSEO 글로 돌아간다');
$GLOBALS['__filters']['duckhoo_broad_cats'] = [];
// FAQ — 입호흡 분류 · 전체 상품
$f1 = ($BR.'faq')('mtl', $mtl); $t1 = implode(' ', array_map(fn($i) => $i['q'].' '.$i['a'], $f1));
$ok(count($f1) >= 5 && str_contains($t1, '입호흡 액상이란') && str_contains($t1, '8,000원부터') && str_contains($t1, '9.8mg') && str_contains($t1, '19세'), '입호흡 FAQ: 뜻 · 값(상품에서) · 농도 · 19세');
$GLOBALS['__is_ptax'] = false; unset($GLOBALS['__qobj']); $GLOBALS['__is_shop'] = true;
$ok(($BR.'ctx')() === 'shop', '전체 상품 화면의 FAQ 문맥은 shop');
$f2 = ($BR.'faq')('shop'); $t2 = implode(' ', array_map(fn($i) => $i['q'].' '.$i['a'], $f2));
$ok(count($f2) >= 5 && str_contains($t2, '전담 액상(전자담배 액상)은 어디서') && str_contains($t2, '낱병은 8,000원부터') && str_contains($t2, '병당 약 10,900원') && str_contains($t2, '무통장입금'), '전체 상품 FAQ: 어디서 · 값(낱병 최저 · 10+1 병당) · 입금');
$ok(!preg_match('/건강|금연|순하다|해롭지/u', $t1.$t2) && !preg_match('/노보마트|브이몬스터|겨울마을|다른 (곳|가게|사이트)/u', $t1.$t2), 'FAQ 에 금지 낱말 · 다른 가게 얘기 없음');
ob_start(); ($BR.'faq_html')(); $fh = ob_get_clean();
$ok(str_contains($fh, '전자담배 액상 자주 묻는 질문') && substr_count($fh, '<details') === count($f2) && str_contains($fh, '/liquid-guide/') && str_contains($fh, '/mtl-vs-dl/'), 'FAQ HTML: 제목 · 항목 수 · 안내 글 두 장 링크');
ob_start(); ($BR.'faq_jsonld')(); $fj = ob_get_clean();
$ok(str_contains($fj, '"@type":"FAQPage"') && substr_count($fj, '"@type":"Question"') === count($f2), 'FAQPage JSON-LD 가 같은 항목을 싣는다');
$GLOBALS['__is_shop'] = false;
$ok(($BR.'ctx')() === '' && ($BR.'faq_html')() === null, '검색도 분류도 전체 상품도 아니면 FAQ 를 안 그린다');
// 홈
$ht = ($BR.'home_title')();
$ok($ht === '전자담배 액상 · 전담 액상 사이트 액상덕후 | 입호흡 · 폐호흡 6종 · 노보 재고 있음', "홈 제목: 손님이 치는 말 둘 앞에 · 종수 · 노보 재고(재고 있을 때만) → {$ht}");
$hd = ($BR.'home_desc')();
$ok(str_starts_with($hd, '전자담배 액상(전담 액상) 전문 사이트 액상덕후.') && str_contains($hd, '낱병 8,000원부터') && str_contains($hd, '노보 전 라인 재고 있음') && str_contains($hd, '19세'), '홈 설명: 전담 액상 · 브랜드 · 최저가 · 노보 · 19세');
$ok(str_contains(($BR.'home_h1')(), '전담 액상 사이트'), '홈 숨은 h1 에도 전담 액상');
$GLOBALS['__products'][8101] = new WC_Product(8101, '[노보] 타박멘솔 (9.8mg / 30ml)', 13000.0, false); $GLOBALS['__products'][8104] = new WC_Product(8104, '[노보 리퀴드] 10+1 | 금액 120,000원', 120000.0, false); $GLOBALS['__transients'] = [];
$ok(!str_contains(($BR.'home_title')(), '노보 재고 있음'), '노보가 전부 품절이면 제목에서 「노보 재고 있음」이 빠진다');
// 소개 글 — 홈 맨 아래 · 분류 격자 아래 (2026-10-06)
$GLOBALS['__products'][8101] = new WC_Product(8101, '[노보] 타박멘솔 (9.8mg / 30ml)', 13000.0, true); $GLOBALS['__products'][8104] = new WC_Product(8104, '[노보 리퀴드] 10+1 | 금액 120,000원', 120000.0, true); $GLOBALS['__transients'] = [];
$ab = ($BR.'about_home')(); $abt = implode(' ', array_map(fn($i) => $i['h'].' '.$i['p'], $ab));
$ok(count($ab) === 4 && mb_strlen($abt) >= 800 && str_contains($abt, '전담 액상') && str_contains($abt, '전자담배 액상') && str_contains($abt, '입호흡 액상') && str_contains($abt, '폐호흡 액상') && str_contains($abt, '낱병은 8,000원부터') && str_contains($abt, '노보(NOVO) 액상은 전 라인 재고') && str_contains($abt, '19세'), '홈 소개 글: 네 단락 · 800자↑ · 손님이 치는 말 넷 · 값은 상품에서 · 노보 재고 · 19세');
$ok(!preg_match('/건강|금연|순하다|해롭지|무니코틴|노보마트|브이몬스터|겨울마을|다른 (곳|가게|사이트)/u', $abt), '홈 소개 글에 광고 제한 낱말 · 무니코틴 · 다른 가게 없음');
$abh = ($BR.'about_home_html')();
$ok(substr_count($abh, '<h3>') === 3 && str_contains($abh, 'class="dhr-about ') && str_contains($abh, '/liquid-guide/') && str_contains($abh, '/mtl-vs-dl/') && str_contains($abh, '/price/') && str_contains($abh, '/register/'), '홈 소개 글 HTML: h2 하나 · h3 셋 · 안내 글 · 가격표 · 가입 링크');
add_filter('duckhoo_home_about', fn($v = null) => []);
$ok(($BR.'about_home_html')() === '', 'duckhoo_home_about 을 비우면 소개 글을 안 그린다');
$GLOBALS['__filters']['duckhoo_home_about'] = [];
$in = ($BR.'intro')('dl', $dlc);
$ok(substr_count($in, '폐호흡 액상') >= 3 && str_contains($in, '폐호흡(DL)') && str_contains($in, '19세') && !preg_match('/건강|금연|순하다|해롭지/u', $in), '폐호흡 분류 소개 단락: 「폐호흡 액상」 세 번 이상 · 뜻 · 19세');
$in2 = ($BR.'intro')('shop');
$ok(str_contains($in2, '전담 액상') && str_contains($in2, '낱병 8,000원부터') && ($BR.'intro')('', null) === '', '전체 상품 소개 단락: 전담 액상 · 값 / 문맥 없으면 빈 문자열');
$GLOBALS['__is_ptax'] = true; $GLOBALS['__qobj'] = $dlc;
ob_start(); ($BR.'intro_html')(); $ih = ob_get_clean();
$ok(str_contains($ih, 'class="dha-intro"') && str_contains($ih, '폐호흡 액상 안내'), '분류 격자 아래 소개 단락 HTML');
$GLOBALS['__is_ptax'] = false; unset($GLOBALS['__qobj']);
// 안내 글 두 장 (pages.php v4)
$PG = 'Duckhoo\\Redesign\\Pages\\';
$defs = ($PG.'definitions')();
$ok(isset($defs['liquid-guide'], $defs['mtl-vs-dl']) && \Duckhoo\Redesign\Pages\VERSION === 4, '안내 글 두 장이 정의에 있고 VERSION 4');
$gc = $defs['liquid-guide']['content'].$defs['mtl-vs-dl']['content'];
$ok(!preg_match('/건강|금연|순하|해롭|노보마트|브이몬스터|겨울마을/u', $gc), '안내 글에 광고 제한 낱말 · 다른 가게 없음');
$ok(str_contains($gc, '/product-category/입호흡-액상/') && str_contains($gc, '/product-category/폐호흡-액상/') && str_contains($gc, '/price/') && str_contains($defs['liquid-guide']['content'], '/mtl-vs-dl/') && str_contains($defs['mtl-vs-dl']['content'], '/liquid-guide/'), '안내 글은 분류 · 가격표 · 서로를 링크한다');
$ok(!preg_match('/\d+종/u', $gc) && str_contains($gc, '9.8mg') && str_contains($gc, '<table>'), '안내 글에 종수 같은 변하는 숫자가 없고(DB 글), 농도 · 표는 있다');
$ok(str_contains(($SP.'page_desc')('liquid-guide', '전자담배 액상 고르는 법'), '전담 액상 사이트') && str_contains(($SP.'page_desc')('mtl-vs-dl', 'x'), '입호흡(MTL) 액상과 폐호흡(DL) 액상의 차이'), '안내 글 두 장의 메타 설명');
$GLOBALS['__products'] = [];

/* ── 고객 세그먼트 (includes/crm.php) — 순수 계산 ───────────────────────────────────── */
require_once dirname(__DIR__, 2).'/includes/crm.php';
require_once dirname(__DIR__, 2).'/includes/crm-sms.php';
$CR = 'Duckhoo\\Redesign\\Crm\\';
$ok(($CR.'phone_norm')('+82 10-1234-5678') === '01012345678' && ($CR.'phone_norm')('010.9876.5432') === '01098765432' && ($CR.'phone_norm')('abc') === '' && ($CR.'phone_norm')('1234') === '', '연락처 정규화: +82 · 점 · 못 읽음');
$sc = ($CR.'score5')([1=>10, 2=>20, 3=>30, 4=>40, 5=>50]);
$ok($sc === [1=>1,2=>2,3=>3,4=>4,5=>5], '5분위: 오름차순 1~5 '.json_encode($sc));
$sc = ($CR.'score5')([1=>10, 2=>20, 3=>30, 4=>40, 5=>50], false);
$ok($sc === [1=>5,2=>4,3=>3,4=>2,5=>1], '5분위: 작은 값이 좋은 쪽(R)은 뒤집힌다');
$sc = ($CR.'score5')([1=>10, 2=>10, 3=>30, 4=>40, 5=>50, 6=>60]);
$ok($sc[1] === $sc[2] && $sc[6] === 5, '5분위: 같은 값은 같은 점수');
$ok(($CR.'score5')([1=>5, 2=>9]) === [1=>3, 2=>3] && ($CR.'score5')([]) === [], '5분위: 5명 미만은 전부 3점 · 빈 것');
$ok(($CR.'rfm_label')(5,5,5) === '챔피언' && ($CR.'rfm_label')(3,4,2) === '충성' && ($CR.'rfm_label')(5,2,1) === '잠재 충성' && ($CR.'rfm_label')(5,1,1) === '신규'
  && ($CR.'rfm_label')(3,2,2) === '관심 필요' && ($CR.'rfm_label')(1,4,3) === '이탈 위험' && ($CR.'rfm_label')(1,1,1) === '휴면', 'RFM 갈래 일곱');
$ok(count(($CR.'rfm_advice')()) === 7, 'RFM 갈래마다 안내 한 줄');
$D = 86400; $now = mktime(12,0,0,10,8,2026);
$mk = fn(int $id, int $ago, string $s, float $t, int $u) => ['id'=>$id,'ts'=>$now-$ago*$D,'s'=>$s,'t'=>$t,'u'=>$u,'city'=>'','state'=>''];
$orders = [
  $mk(1, 2,  'on-hold',   30000, 1),   // 손님1 그제 입금전 — 살아 있는 주문 → 담고 나간 명단에서 빠진다 · 미입금 명단에 든다
  $mk(2, 30, 'delivered', 45000, 2),   // 손님2 한 달 전 디오리퀴드
  $mk(3, 1,  'cancelled', 20000, 3),   // 손님3 어제 취소 — 죽은 주문은 안 센다
  $mk(4, 400,'delivered', 26000, 2),   // 손님2 옛 노보 (창 밖)
  $mk(5, 10, 'delivered', 13000, 4),   // 손님4 열흘 전 노보 낱병
  $mk(6, 0,  'on-hold',   50000, 0),   // 비회원 오늘 입금전 (1일 미만)
  $mk(7, 3,  'pending',   9000,  5),   // 손님5 사흘 전 pending
  $mk(8, 200,'delivered', 100000, 6), $mk(9, 150,'delivered', 100000, 6), $mk(10, 120,'delivered', 100000, 6),  // 손님6 세 번 · 오래됨
];
$items = [
  2=>[['pid'=>21,'name'=>'[디오리퀴드] 로젤하트 (9.8mg / 30ml)','qty'=>3,'total'=>45000]],
  4=>[['pid'=>11,'name'=>'[노보] 타박멘솔 (9.8mg / 30ml)','qty'=>2,'total'=>26000]],
  5=>[['pid'=>11,'name'=>'[노보] 타박멘솔 (9.8mg / 30ml)','qty'=>1,'total'=>13000]],
  8=>[['pid'=>31,'name'=>'[펠릭스] 더블라임','qty'=>5,'total'=>100000]],
];
$carts = [1=>['n'=>1,'qty'=>2,'total'=>26000,'names'=>['노보 타박멘솔']], 3=>['n'=>2,'qty'=>3,'total'=>40000,'names'=>['a','b']], 7=>['n'=>1,'qty'=>1,'total'=>13000,'names'=>['c']]];
$ab = ($CR.'seg_abandon')($carts, $orders, $now, 7);
$ok(array_column($ab,'u') === [3,7], '담고 나간 손님: 최근 살아 있는 주문이 있는 1은 빠지고, 취소뿐인 3 · 주문 없는 7 — 금액 큰 순 '.json_encode(array_column($ab,'u')));
$ok($ab[0]['paid_n'] === 0 && $ab[0]['last'] === 0 && $ab[0]['names'] === ['a','b'], '담고 나간 손님: 산 적 없음 · 담은 상품 이름');
$br = ($CR.'seg_brand')($orders, $items, ['노보','디오리퀴드'], $now, 180);
$ok(array_column($br,'u') === [4,2], '브랜드 손님: 최근 순 (4 노보 열흘 전 · 2 디오 한 달 전), 창 밖 옛 노보 · 펠릭스는 안 센다 '.json_encode(array_column($br,'u')));
$ok($br[1]['brands'] === ['디오리퀴드'] && $br[1]['bottles'] === 3 && str_contains($br[1]['fav'], '디오리퀴드') && $br[1]['n'] === 1, '브랜드 손님: 브랜드 · 병 수 · 자주 산 것');
$rf = ($CR.'rfm')($orders, $now);
$ok(count($rf['rows']) === 3 && array_sum($rf['counts']) === 3, 'RFM: 돈 들어온 회원 셋(2 · 4 · 6)만, 비회원 · 입금전 · 취소 제외');
$r6 = array_values(array_filter($rf['rows'], fn($r)=>$r['u']===6))[0];
$ok($r6['f'] === 3 && $r6['m'] == 300000.0 && $r6['r_days'] === 120 && $r6['r'] === 3 && $r6['fs'] === 3, 'RFM: 셋뿐이라 5분위 대신 3점 · F3 · M30만 · 120일');
$un = ($CR.'seg_unpaid')($orders, $now, 1);
$ok(array_column($un,'id') === [1,7], '미입금: 그제 on-hold · 사흘 전 pending, 오늘 것은 1일 미만이라 빠짐 '.json_encode(array_column($un,'id')));
$ok(($CR.'seg_unpaid')($orders, $now, 0)[0]['id'] === 6 && ($CR.'seg_unpaid')($orders, $now, 0)[0]['u'] === 0, '미입금: 0일 기준이면 오늘 비회원 주문도 든다');
$dd = ($CR.'dedupe')([['name'=>'a','phone'=>'010-1111-2222'],['name'=>'b','phone'=>'01011112222'],['name'=>'c','phone'=>''],['name'=>'d','phone'=>'']]);
$ok(count($dd) === 3 && $dd[0]['phone'] === '01011112222' && $dd[0]['name'] === 'a', '같은 연락처는 앞 줄만 · 번호는 숫자만 · 연락처 없는 줄은 그대로');
$ok(($CR.'filter_only')([['why'=>'챔피언 (R5 F5 M5)'],['why'=>'신규 (R5 F1 M1)']], '신규')[0]['why'] === '신규 (R5 F1 M1)' && count(($CR.'filter_only')([['why'=>'x']], '')) === 1, 'RFM 갈래 하나만 남기기');
// 한 사람에 한 통 — 네 명단을 연락처로 합친다
$cb = ($CR.'combine')([
  'rfm'     => [['name'=>'가','phone'=>'010-1111-2222','why'=>'이탈 위험'], ['name'=>'나','phone'=>'01033334444','why'=>'관심 필요'], ['name'=>'다','phone'=>'','why'=>'휴면']],
  'brand'   => [['name'=>'가','phone'=>'01011112222','why'=>'노보'], ['name'=>'라','phone'=>'01055556666','why'=>'디오']],
  'unpaid'  => [['name'=>'가','phone'=>'+82 10 1111 2222','why'=>'입금전 3일'], ['name'=>'마','phone'=>'','why'=>'입금전 2일']],
  'abandon' => [['name'=>'나','phone'=>'010 3333 4444','why'=>'담고 나감']],
]);
$ok(array_column($cb,'seg') === ['unpaid','unpaid','abandon','brand','rfm'] && array_column($cb,'name') === ['가','마','나','라','다'], '합치기: 우선순위(미입금 → 담고 나간 → 노보/디오 → RFM)대로, 줄 수 8 → 5 '.json_encode(array_column($cb,'name')));
$ok($cb[0]['also'] === ['brand','rfm'] && $cb[0]['why'] === '입금전 3일' && $cb[0]['phone'] === '01011112222', '합치기: 가 는 미입금 줄 하나만 남고 다른 명단 이름은 also 에 (brand · rfm 순)');
$ok($cb[2]['seg'] === 'abandon' && $cb[2]['also'] === ['rfm'] && $cb[3]['also'] === [], '합치기: 나 는 담고 나간 줄 + RFM 표시, 라 는 혼자');
$ok($cb[1]['phone'] === '' && $cb[4]['phone'] === '' && $cb[1]['also'] === [], '합치기: 연락처 없는 줄은 합치지 않고 그대로 남는다 (마 · 다)');
$cb2 = ($CR.'combine')(['rfm' => [['name'=>'가','phone'=>'01011112222','why'=>'A'], ['name'=>'가','phone'=>'01011112222','why'=>'B']]]);
$ok(count($cb2) === 1 && $cb2[0]['also'] === [] && $cb2[0]['why'] === 'A', '합치기: 같은 명단 안의 중복은 also 에 자기 이름을 적지 않는다');
add_filter('duckhoo_crm_priority', fn($p) => ['brand','unpaid','abandon','rfm']);
$cb3 = ($CR.'combine')(['unpaid' => [['name'=>'가','phone'=>'01011112222','why'=>'입금전']], 'brand' => [['name'=>'가','phone'=>'01011112222','why'=>'노보']]]);
$GLOBALS['__filters']['duckhoo_crm_priority'] = [];
$ok($cb3[0]['seg'] === 'brand' && $cb3[0]['also'] === ['unpaid'], '합치기: 필터로 우선순위를 바꾸면 그 순서대로 (brand 가 주 명단)');
$cb4 = ($CR.'combine')(['extra' => [['name'=>'x','phone'=>'01099998888']], 'unpaid' => [['name'=>'y','phone'=>'01099998888']]]);
$ok($cb4[0]['seg'] === 'unpaid' && $cb4[0]['also'] === ['extra'], '합치기: 우선순위에 없는 명단은 맨 뒤로');
$ok(($CR.'default_parts')() === ['unpaid','abandon','brand','rfm:이탈 위험','rfm:관심 필요'] && isset(($CR.'parts')()['rfm:휴면']) && count(($CR.'parts')()) === 10 && isset(($CR.'segs')()['all']), '합치기 조각: 기본 다섯 · RFM 갈래 일곱 + 명단 셋 = 열 · 탭 all');
// SMS 수신 동의 · 문자 사이트 양식 (includes/crm-sms.php)
$cw=$CR.'consent_word';
$ok($cw('Y')==='yes' && $cw(' 동의 ')==='yes' && $cw('1')==='yes' && $cw('TRUE')==='yes' && $cw('N')==='no' && $cw('수신거부')==='no' && $cw('0')==='no' && $cw('')==='' && $cw('모름')==='' && $cw('2026-09-01')==='', '동의 낱말: Y·동의·1·TRUE 는 yes, N·수신거부·0 은 no, 빈 값·모르는 글자는 기록 없음');
$pd=$CR.'phone_dash';
$rp=$CR.'roster_parse_consent';
$r=$rp("이름\t휴대폰\tSMS수신동의\t이메일\n홍길동\t010-1111-2222\tY\ta@b.c\n김영희\t010-3333-4444\tN\t\n박철수\t+82 10-5555-6666\t동의\t\n이모름\t010-7777-8888\t\t\n빈줄\t\tY\t");
$ok(isset($r['yes']['01011112222']) && isset($r['yes']['01055556666']) && isset($r['no']['01033334444']) && !isset($r['yes']['01077778888']) && !isset($r['no']['01077778888']) && $r['rows']===4 && $r['cols']['phone']==='휴대폰' && $r['cols']['sms']==='SMS수신동의', '명단 읽기(탭·머리줄): 번호 칸·동의 칸을 이름으로 찾아 Y/N/동의, 빈 값은 기록 없음, 번호 없는 줄 안 셈 '.json_encode($r['cols']));
$r=$rp("name,phone,marketing_opt_in\n가,01012345678,true\n나,01087654321,false\n");
$ok(isset($r['yes']['01012345678']) && isset($r['no']['01087654321']) && $r['rows']===2, '명단 읽기(쉼표 · 영문 머리줄 · true/false)');
$r=$rp("010-1111-2222 동의\n010-3333-4444 수신거부\n010-5555-6666\n");
$ok(isset($r['yes']['01011112222']) && isset($r['no']['01033334444']) && !isset($r['yes']['01055556666']) && $r['rows']===3 && $r['cols']['phone']==='', '명단 읽기(머리줄 없음): 줄마다 번호 + 동의/거부 낱말, 낱말 없으면 기록 없음');
$ok($rp('')['rows']===0 && $rp("\n\n")['rows']===0, '명단 읽기: 빈 글은 0줄');
$co=$CR.'consent_of'; $ro=['yes'=>['01011112222'=>true],'no'=>['01033334444'=>true]];
$ok($co('01011112222','no',$ro)==='no' && $co('01033334444','yes',$ro)==='yes' && $co('01011112222','',$ro)==='yes' && $co('01033334444','',$ro)==='no' && $co('01099999999','',$ro)==='' && $co('','',$ro)==='', '동의 판정: 회원 메타가 먼저, 없으면 명단, 둘 다 없으면 기록 없음 · 번호 없으면 기록 없음');
$rows=[['u'=>1,'name'=>'가','phone'=>'01011112222'],['u'=>2,'name'=>'나','phone'=>'01033334444'],['u'=>0,'name'=>'비회원','phone'=>'01011112222'],['u'=>3,'name'=>'다','phone'=>'01077777777'],['u'=>4,'name'=>'라','phone'=>'']];
$at=($CR.'attach_sms')($rows, [1=>'no', 3=>'yes', 4=>'yes'], $ro);
$ok(array_column($at,'sms')===['no','no','yes','yes','yes'], '줄에 sms 붙이기: 1은 메타 거부가 명단 동의를 이김 · 2는 명단 거부 · 비회원은 명단으로 동의 · 3·4는 메타 동의 '.json_encode(array_column($at,'sms')));
$ok(($CR.'sms_counts')($at)===['yes'=>3,'no'=>2,'unknown'=>0] && ($CR.'sms_counts')([['sms'=>'']])===['yes'=>0,'no'=>0,'unknown'=>1], '동의·거부·기록 없음 수');
$x=($CR.'xls_html')([['name'=>'홍길동','phone'=>'01011112222','why'=>'입금전 3일째 · 주문 #5','note'=>'','group'=>'미입금'],['name'=>'번호없음','phone'=>'','why'=>'x','note'=>''],['name'=>'김영희','phone'=>'+82 10-3333-4444','why'=>'노보 2번 · 11병','note'=>'자주 산 것: 타박멘솔','group'=>'노보']], '미입금 고객');
$u=iconv('CP949','UTF-8',$x);
$ok(str_starts_with($x,'<meta http-equiv="Content-Type" content="application/vnd.ms-excel; charset=euc-kr">') && str_contains($u,'<td><b>NO</b></td><td><b>그룹명</b></td><td><b>이름</b></td><td><b>전화번호</b></td><td><b>메모</b></td>'), 'xls 양식: tothemoon 머리줄 그대로(NO · 그룹명 · 이름 · 전화번호 · 메모) · EUC-KR 메타');
$ok(str_contains($u,'<td>1</td><td>미입금 고객</td><td>홍길동</td><td>010-1111-2222</td><td>입금전 3일째 · 주문 #5</td>') && str_contains($u,'<td>2</td><td>미입금 고객</td><td>김영희</td><td>010-3333-4444</td><td>노보 2번 · 11병 / 자주 산 것: 타박멘솔</td>') && !str_contains($u,'번호없음') && substr_count($u,'<tr>')===3, 'xls 양식: 번호 없는 줄은 빼고 NO 를 다시 매김 · 그룹명 인자가 줄의 group 을 덮음 · 메모 = 기준 / 메모 · 하이픈 번호');
$x2=($CR.'xls_html')([['name'=>'가','phone'=>'01011112222','why'=>'a','note'=>'','group'=>'미입금 고객'],['name'=>'나','phone'=>'01033334444','why'=>'b','note'=>'','group'=>'RFM 이탈 위험']], '');
$u2=iconv('CP949','UTF-8',$x2);
$ok(str_contains($u2,'<td>미입금 고객</td><td>가</td>') && str_contains($u2,'<td>RFM 이탈 위험</td><td>나</td>'), 'xls 양식: 그룹명을 비우면 줄마다 자기 주 명단 (한 사람에 한 통 탭)');
$ok(mb_check_encoding($x,'UTF-8')===false && iconv('CP949','UTF-8',$x)!==false, 'xls 양식: 본문이 실제로 CP949 바이트다 (UTF-8 이 아니다)');
$ak=$CR.'auto_sms_key'; $cs=[['key'=>'wd_agree_email','yes'=>5,'no'=>44],['key'=>'wd_agree_sms','yes'=>12,'no'=>37],['key'=>'wd_agree_third_party','yes'=>14,'no'=>35]];
$ok($ak($cs)==='wd_agree_sms' && $ak([])==='' && $ak([['key'=>'sms_a','yes'=>1,'no'=>0],['key'=>'sms_b','yes'=>0,'no'=>1]])==='' && $ak([['key'=>'wd_agree_sms','yes'=>0,'no'=>0]])==='', '자동 키: sms 가 든 키가 하나뿐이고 값이 있을 때만 (둘이면 · 값 없으면 고르지 않음)');
/* ── 가입 2단계 건너뛰기 막기 (includes/agree-gate.php) ───────────────────────────── */
require_once dirname(__DIR__, 2).'/includes/agree-gate.php';
$AG='Duckhoo\\Redesign\\AgreeGate\\';
$ok(($AG.'parse_agree')('s1e0t1')===['sms'=>'yes','email'=>'no','third'=>'yes'] && ($AG.'parse_agree')('s0e0t0')===['sms'=>'no','email'=>'no','third'=>'no'] && ($AG.'parse_agree')('')===null && ($AG.'parse_agree')('s2e0t0')===null && ($AG.'parse_agree')('yes')===null, '약관 쿠키 읽기: s1e0t1 → yes/no/yes · 꼴이 아니면 null');
$ok(($AG.'agree_meta')(['sms'=>'yes','email'=>'no','third'=>'yes'])===['wd_agree_sms'=>'yes','wd_agree_email'=>'no','wd_agree_third_party'=>'yes'], '약관 → 회원 메타: 테마와 같은 키(wd_agree_sms · email · third_party) · 값 yes/no');
$na=$AG.'needs_agree';
$ok($na(true,false,'GET',false,false)===true && $na(true,false,'get',false,false)===true, '3단계 문: 비로그인 GET · 쿠키 없음 · 처음이면 2단계로');
$ok($na(true,false,'POST',false,false)===false && $na(true,true,'GET',false,false)===false && $na(true,false,'GET',true,false)===false && $na(true,false,'GET',false,true)===false && $na(false,false,'GET',false,false)===false, '3단계 문: POST(가입 제출) · 로그인 · 약관 쿠키 있음 · 이미 한 번 보냄 · 필터로 끔 이면 안 보낸다');

/* ── 주차 플랜 · 보상 라벨 · 한 사람에 한 보상 (includes/crm-plan.php) ─────────────── */
require_once dirname(__DIR__, 2).'/includes/crm-plan.php';
$rk=$CR.'reward_key';
$ok($rk('abandon')==='abandon' && $rk('rfm','챔피언')==='rfm:챔피언' && $rk('rfm')==='rfm' && $rk('brand','x')==='brand', '보상 열쇠: 명단 그대로, RFM 은 갈래를 붙인다');
$wr=$CR.'with_reward'; $RW=['abandon'=>'적립금 3,000원 · 7일','brand'=>'쿠폰 2,000원 · 7일','rfm:챔피언'=>'적립금 5,000원 · 2주','rfm'=>'RFM 공통'];
$rows=$wr([
 ['phone'=>'01011112222','seg'=>'abandon','part'=>'abandon','group'=>'장바구니 담고 나간 손님','also'=>['brand']],
 ['phone'=>'01033334444','seg'=>'brand','part'=>'brand','group'=>'노보 · 디오리퀴드 구매 손님','also'=>[]],
 ['phone'=>'01055556666','seg'=>'rfm','part'=>'rfm:챔피언','group'=>'RFM 챔피언','also'=>[]],
 ['phone'=>'01077778888','seg'=>'rfm','part'=>'rfm:충성','group'=>'RFM 충성','also'=>[]],
 ['phone'=>'01099990000','seg'=>'unpaid','part'=>'unpaid','group'=>'미입금 고객','also'=>[]],
], $RW);
$ok($rows[0]['reward']==='적립금 3,000원 · 7일' && $rows[0]['group']==='장바구니 담고 나간 손님 · 적립금 3,000원 · 7일' && $rows[1]['group']==='노보 · 디오리퀴드 구매 손님 · 쿠폰 2,000원 · 7일', '보상 붙이기: 겹친 사람(담고 나간 + 노보)은 주 명단(담고 나간)의 적립금 하나 · 그룹명 뒤에 보상');
$ok($rows[2]['group']==='RFM 챔피언 · 적립금 5,000원 · 2주' && $rows[3]['group']==='RFM 충성 · RFM 공통' && $rows[3]['reward']==='RFM 공통', '보상 붙이기: RFM 갈래 라벨이 먼저, 없으면 rfm 공통 라벨');
$ok($rows[4]['reward']==='' && $rows[4]['group']==='미입금 고객', '보상 붙이기: 라벨 없는 명단은 그룹명 그대로 (미입금은 보상이 아니라 안내)');
$again=$wr($rows,$RW);
$ok($again[0]['group']===$rows[0]['group'], '보상 붙이기: 두 번 돌려도 보상이 두 번 붙지 않는다');
$rs=($CR.'reward_summary')($rows);
$ok($rs['abandon']===['n'=>1,'reward'=>'적립금 3,000원 · 7일'] && $rs['rfm:챔피언']['n']===1 && $rs['unpaid']['reward']==='' && count($rs)===5, '조각별 요약: 사람 수 · 보상');
$wk=($CR.'weeks')();
$ok(isset($wk['w13'],$wk['w2'],$wk['hw']) && $wk['w13']['parts']===['abandon','brand'] && $wk['w2']['parts']===['rfm:챔피언','rfm:충성','rfm:신규'] && !in_array('unpaid',$wk['hw']['parts'],true) && !in_array('rfm:휴면',$wk['hw']['parts'],true), '주차 플랜: 1·3주차 = 담고 나간 + 노보·디오, 2주차 = 챔피언·충성·신규, 할로윈에 미입금 · 휴면은 없다');
foreach ($wk as $w) { foreach ($w['parts'] as $pp) { $ok(isset(($CR.'parts')()[$pp]), '주차 플랜의 조각이 실제 조각 목록에 있다: '.$pp); } }

// 보낸 기록 — 최근에 받은 사람 빼기 · 순수
$now=1700000000; $bs=[
 ['id'=>'a','at'=>$now-2*86400,'seg'=>'abandon','group'=>'담고 나간 · 적립금','rows'=>[['name'=>'가','phone'=>'01011112222','why'=>'x'],['name'=>'나','phone'=>'010-3333-4444','why'=>'y']]],
 ['id'=>'b','at'=>$now-30*86400,'seg'=>'brand','group'=>'노보','rows'=>[['name'=>'다','phone'=>'01055556666','why'=>'z']]],
];
$ss=($CR.'sent_set')($bs,21,$now);
$ok(isset($ss['01011112222'],$ss['01033334444']) && !isset($ss['01055556666']) && ($CR.'sent_set')($bs,0,$now)===[], '보낸 기록 집합: 21일 안의 묶음만 · 하이픈 번호도 정규화 · 0일이면 비어 있음');
[$keep,$n]=($CR.'without_sent')([['name'=>'가','phone'=>'01011112222'],['name'=>'라','phone'=>'01077778888'],['name'=>'다','phone'=>'01055556666'],['name'=>'번호없음','phone'=>'']],$ss);
$ok($n===1 && array_column($keep,'name')===['라','다','번호없음'], '받은 사람 빼기: 2일 전 받은 가 만 빠지고 30일 전 다 · 번호 없는 줄은 남는다');
$ok(($CR.'without_sent')([['phone'=>'01011112222']],[])===[[['phone'=>'01011112222']],0], '받은 사람 빼기: 기록이 없으면 그대로');

// 제외 명단 — 순수
$pe=$CR.'parse_exclude';
$ok($pe("왕한빈\n진 선영\n010-1234-5678\n# 주석\n\n유지민, 왕한빈")===['왕한빈','진선영','01012345678','유지민'], '제외 명단 읽기: 줄 · 쉼표 · 빈칸 뺀 이름 · 번호 정규화 · 주석 · 중복 '.json_encode($pe("왕한빈\n진 선영\n010-1234-5678\n# 주석\n\n유지민, 왕한빈")));
$we=$CR.'without_excluded';
$rows=[['name'=>'왕한빈','phone'=>'01011112222','seg'=>'brand'],['name'=>'김철수','phone'=>'01033334444','seg'=>'brand'],['name'=>'진 선영','phone'=>'','seg'=>'abandon'],['name'=>'유지민','phone'=>'01055556666','seg'=>'unpaid'],['name'=>'박영희','phone'=>'01077778888','seg'=>'rfm']];
[$keep,$gone]=$we($rows,['왕한빈','진선영','01077778888']);
$ok(array_column($keep,'name')===['김철수','유지민'] && count($gone)===3 && str_contains($gone[0],'왕한빈') && str_contains($gone[2],'8888'), '제외: 이름(빈칸 무시) · 번호로 빼고, 미입금 줄의 유지민은 남는다 · 뺀 사람 목록 '.json_encode($gone));
$ok($we($rows,[])===[$rows,[]], '제외: 명단이 비면 그대로');
[$k2,$g2]=$we([['name'=>'유지민','phone'=>'0101'],['name'=>'유지민','phone'=>'0102']],['유지민'],'brand');
$ok(count($k2)===0 && count($g2)===2, '제외: 같은 이름은 전부 빠진다 (동명이인 주의 — 번호로 적으면 한 사람만)');
$ok(($CR.'exclude_default')()===['왕한빈','진선영','유지민'], '제외 기본값: 사장님이 말한 세 사람');


// 한 상품 안에서 병 수 고르기 — 세트 수 + 할인 줄
require_once dirname(__DIR__, 2).'/includes/bulk-sets.php';
$BS='Duckhoo\\Redesign\\BulkSets\\';
$ok(array_keys(($BS.'config')())===[5373,5375,5384,5387] && ($BS.'js_config')()[5373]===['n'=>3,'lot'=>10,'split'=>true] && ($BS.'js_config')()[5375]['n']===5 && ($BS.'js_config')()[5384]['n']===3 && ($BS.'js_config')()[5387]['n']===5, '병 수 고르기: 기본은 디오리퀴드 33 · 55병(#5373 · #5375) + 화이트아웃 33 · 55병(#5384 · #5387)');
$GLOBALS['__filters']['duckhoo_bulk_sets']=[fn($c)=>[]];
$ok(($BS.'config')()===[], '필터로 비우면 대상 없음 (아무 일도 안 한다)');
$GLOBALS['__filters']['duckhoo_bulk_sets']=[];
$dio=['kind'=>'sets','name'=>'디오리퀴드','lot'=>10,'choices'=>[3=>['label'=>'33병','fee'=>0],5=>['label'=>'55병','fee'=>33000]]];
$set=fn($n)=>['group_key'=>'required_main','type'=>'required','label'=>'디오리퀴드 10+1 세트','qty'=>$n];
$fl=fn($l,$n)=>['group_key'=>'addon_1','type'=>'addon','label'=>$l,'qty'=>$n];
$r33=($BS.'rows')([$set(3),$fl('로젤하트',11),$fl('레드에너지',21),$fl('레몬라임',1)]);
$ok(($BS.'check')($r33,$dio)==='' && ($BS.'fee_for')($r33,$dio)===0 && ($BS.'key_of')($r33,$dio)===3, '33병: 10+1 · 20+1 · 서비스만 1 → 통과 · 할인 0');
$r55=($BS.'rows')([$set(5),$fl('로젤하트',20),$fl('레드에너지',31),$fl('A',1),$fl('B',1),$fl('C',1),$fl('D',1)]);
$ok(($BS.'check')($r55,$dio)==='' && ($BS.'fee_for')($r55,$dio)===33000 && ($BS.'fee_name')($r55,$dio)==='디오리퀴드 55병 구성 할인', '55병: 50 + 서비스 5 → 할인 33,000 · 이름');
$ok(($BS.'check')(($BS.'rows')([$set(4),$fl('A',44)]),$dio)!=='' , '4세트는 고를 수 없다');
$ok(str_contains(($BS.'check')(($BS.'rows')([$set(3),$fl('A',12),$fl('B',21)]),$dio),'10병씩'), '같은 맛 12병(서비스 2병)은 막는다');
$ok(($BS.'check')(($BS.'rows')([$set(3),$fl('A',30),$fl('B',3)]),$dio)!=='' , '서비스 3병을 한 맛에 몰면 막는다 (3 % 10 = 3)');
$ok(($BS.'check')(($BS.'rows')([$set(3),$fl('A',33)]),$dio)!=='' , '33병을 한 맛에: 30 + 서비스 3 은 맛마다 1병 규칙에 걸린다');
$ok(($BS.'check')(($BS.'rows')([$set(3),$fl('A',20),$fl('B',1),$fl('C',1),$fl('D',1)]),$dio)!=='', '30병이 아니라 20병이면 막는다');
$ok(($BS.'fee_for')(($BS.'rows')([$set(5),$fl('A',44)]),$dio)===0, '규칙에 안 맞으면 할인 줄도 없다');
$je=['kind'=>'addon','name'=>'젤로','extra'=>'추가','choices'=>[1=>['label'=>'5병','fee'=>0],2=>['label'=>'10병','fee'=>38000]]];
$main=['group_key'=>'required_main','type'=>'required','label'=>'젤로크리스탈 기기 + 액상 5병','qty'=>1];
$add=['group_key'=>'required_main','type'=>'required','label'=>'액상 5병 추가','qty'=>1];
$ok(($BS.'check')(($BS.'rows')([$main,$fl('빌런 자두',5)]),$je)==='' && ($BS.'fee_for')(($BS.'rows')([$main,$fl('빌런 자두',5)]),$je)===0, '젤로 5병: 기기 줄 하나 · 할인 0');
$ok(($BS.'fee_for')(($BS.'rows')([$main,$add,$fl('빌런 자두',10)]),$je)===38000, '젤로 10병: 기기 + 추가 → 할인 38,000');
$ok(($BS.'check')(($BS.'rows')([$add,$fl('A',5)]),$je)!=='' && ($BS.'check')(($BS.'rows')([array_merge($main,['qty'=>2])]),$je)!=='' && ($BS.'check')(($BS.'rows')([$main,array_merge($add,['qty'=>2])]),$je)!=='', '젤로: 추가만 · 기기 두 대 · 추가 두 번은 막는다');
// 할인 줄 붙이기 — 같은 이름은 합친다
$GLOBALS['__filters']['duckhoo_bulk_sets']=[fn($c)=>[9001=>$dio,9002=>$je]];
$fees=[]; $cart=new class($r55,$main,$add,$fl){ public $f=[]; private $it; function __construct($a,$m,$ad,$fl){ $this->it=[['product_id'=>9001,'quantity'=>1,'wd_option_builder'=>[['group_key'=>'required_main','type'=>'required','label'=>'s','qty'=>5],$fl('A',20),$fl('B',31),$fl('C',1),$fl('D',1),$fl('E',1),$fl('F',1)]],['product_id'=>9001,'quantity'=>1,'wd_option_builder'=>[['group_key'=>'required_main','type'=>'required','label'=>'s','qty'=>5],$fl('A',55)]],['product_id'=>9002,'quantity'=>1,'wd_option_builder'=>[$m,$ad,$fl('A',10)]],['product_id'=>5,'quantity'=>1]]; } function get_cart(){return $this->it;} function add_fee($n,$a,$t){ $this->f[$n]=$a; } };
($BS.'add_fees')($cart);
$ok($cart->f===['디오리퀴드 55병 구성 할인'=>-33000,'젤로 10병 구성 할인'=>-38000], '할인 줄: 맞는 줄만 · 이름별 하나 '.json_encode($cart->f,JSON_UNESCAPED_UNICODE));
$GLOBALS['__filters']['duckhoo_bulk_sets']=[];
$fx=['kind'=>'fixed','n'=>3,'label'=>'디오리퀴드 33병','lot'=>10];
$one=['group_key'=>'required_main','type'=>'required','label'=>'디오리퀴드 33병 구성','qty'=>1];
$ok(($BS.'check')(($BS.'rows')([$one,$fl('A',11),$fl('B',21),$fl('C',1)]),$fx)==='' && ($BS.'fee_for')(($BS.'rows')([$one,$fl('A',11),$fl('B',21),$fl('C',1)]),$fx)===0, '33병 따로 상품: 10+1 · 20+1 · 서비스 1 → 통과 · 할인 줄 없음');
$ok(($BS.'check')(($BS.'rows')([$one,$fl('A',30),$fl('B',3)]),$fx)!=='' && ($BS.'check')(($BS.'rows')([$one,$fl('A',33)]),$fx)!=='' && ($BS.'check')(($BS.'rows')([$fl('A',33)]),$fx)!=='', '33병 따로 상품: 서비스를 한 맛에 몰면 · 구성 없이 막는다');
$ok(($BS.'check')(($BS.'rows')([array_merge($one,['qty'=>2]),$fl('A',61),$fl('B',1),$fl('C',1),$fl('D',1),$fl('E',1),$fl('F',1)]),$fx)==='', '33병 구성을 두 개 담으면 66병 (10병 6번 + 서비스 6)');
$GLOBALS['__filters']['duckhoo_bulk_sets']=[fn($c)=>[9003=>$fx]];
$ok(isset(($BS.'config')()[9003]), 'fixed 는 choices 없이도 대상이 된다');
$ok(($BS.'check')(($BS.'rows')([$fl('A',11),$fl('B',21),$fl('C',1)]),$fx)==='구성을 먼저 골라 주세요.', '33병 따로 상품: 맛이 맞아도 구성 줄이 없으면 막는다');
$GLOBALS['__filters']['duckhoo_bulk_sets']=[];
// 칸이 나뉜 대량 상품 — 맛 칸(addon_1) 10병씩 · 서비스 칸(addon_2) 맛마다 1병 (사장님 그룹 62 · 63)
$sp=($BS.'defaults')()[5373]; $sp5=($BS.'defaults')()[5375];
$sv=fn($l,$n)=>['group_key'=>'addon_2','type'=>'addon','label'=>$l,'qty'=>$n];
$ok(($BS.'check')(($BS.'rows')([$one,$fl('A',10),$fl('B',20),$sv('A',1),$sv('C',1),$sv('D',1)]),$sp)==='', '33병(칸 나눔): 맛 10 + 20 · 서비스 셋 → 통과');
$ok(($BS.'check')(($BS.'rows')([$one,$fl('A',30),$sv('A',1),$sv('B',1),$sv('C',1),['group_key'=>'addon_pod_3','type'=>'addon','label'=>'팟','qty'=>1]]),$sp)==='', '33병(칸 나눔): 한 맛 30병도 된다 · 팟(addon_pod)은 안 센다');
$ok(str_contains(($BS.'check')(($BS.'rows')([$one,$fl('A',15),$fl('B',15),$sv('A',1),$sv('B',1),$sv('C',1)]),$sp),'10병씩'), '33병(칸 나눔): 15 + 15 는 막는다');
$ok(str_contains(($BS.'check')(($BS.'rows')([$one,$fl('A',30),$sv('A',2),$sv('B',1)]),$sp),'맛마다'), '33병(칸 나눔): 서비스 한 맛 2병은 막는다');
$ok(($BS.'check')(($BS.'rows')([$one,$fl('A',30),$sv('A',1),$sv('B',1)]),$sp)!=='' && ($BS.'check')(($BS.'rows')([$one,$fl('A',20),$sv('A',1),$sv('B',1),$sv('C',1)]),$sp)!=='', '33병(칸 나눔): 서비스 2병 · 맛 20병이면 막는다');
$ok(($BS.'check')(($BS.'rows')([$one,$fl('A',30),$fl('B',20),$sv('A',1),$sv('B',1),$sv('C',1),$sv('D',1),$sv('E',1)]),$sp5)==='', '55병(칸 나눔): 맛 50 + 서비스 다섯 → 통과');
$ok(($BS.'check')(($BS.'rows')([array_merge($one,['qty'=>2]),$fl('A',60),$sv('A',2),$sv('B',2),$sv('C',2)]),$sp)==='', '33병 두 개: 맛 60 + 서비스 6 (맛마다 2병까지)');


/* ── 같은 상품의 다른 구성 (Product\variants) ── */
$VR = 'Duckhoo\\Redesign\\Product\\';
$GLOBALS['__products'][207]  = new WC_Product(207, '[젤로 크리스탈] 젤로 크리스탈 기기 + 액상 5병 증정 이벤트 !', 69000);
$GLOBALS['__products'][5381] = new WC_Product(5381, '[젤로 크리스탈] 기기 + 액상 10병 묶음', 100000);
ob_start(); ($VR.'variants')($GLOBALS['__products'][207]); $v1 = ob_get_clean();
$ok(strpos($v1, 'class="dhp-vars"') !== false && strpos($v1, 'is-on" aria-current="page"><b>액상 5병</b><span>69,000원') !== false && strpos($v1, '<a class="dhp-var" href=') !== false && strpos($v1, '100,000원') !== false, '구성 줄: 지금 상품은 표시만 · 다른 구성은 링크와 값');
$GLOBALS['__products'][5381]->in_stock = false;
ob_start(); ($VR.'variants')($GLOBALS['__products'][207]); $v2 = ob_get_clean();
$ok(strpos($v2, 'is-out" aria-disabled="true"><b>액상 10병</b><span>품절') !== false && strpos($v2, '<a ') === false, '구성 줄: 품절인 쪽은 누를 수 없게');
$GLOBALS['__products'][5381]->in_stock = true; $GLOBALS['__pstatus'][5381] = 'draft';
ob_start(); ($VR.'variants')($GLOBALS['__products'][207]); $v3 = ob_get_clean();
$ok($v3 === '', '구성 줄: 다른 쪽이 공개 전이면 줄 자체를 안 그린다');
unset($GLOBALS['__pstatus'][5381]);
$GLOBALS['__products'][9]  = new WC_Product(9, '[노보] 타박멘솔', 13000);
ob_start(); ($VR.'variants')($GLOBALS['__products'][9]); $v4 = ob_get_clean();
$ok($v4 === '' && ($VR.'variants_of')(5375) === [5373 => '33병', 5375 => '55병'], '구성 줄: 묶음에 없는 상품은 안 그린다 · 디오 33/55 묶음');

/* ── 폰 페이지 줄 (Front\pager_compact) ── */
if (!function_exists('get_pagenum_link')) { function get_pagenum_link($n, $esc = true){ return 'https://duck-hoo.com/shop/page/'.$n.'/?orderby=price'; } }
$PG = 'Duckhoo\\Redesign\\Front\\pager_compact';
$pg1 = $PG(1, 10); $pg5 = $PG(5, 10); $pgL = $PG(10, 10);
$ok($PG(1, 1) === '' && $PG(1, 0) === '' , '한 쪽뿐이면 페이지 줄을 안 그린다');
$ok(strpos($pg5, '<b>5</b> / 10') !== false && strpos($pg5, 'page/4/') !== false && strpos($pg5, 'page/6/') !== false && strpos($pg5, 'orderby=price') !== false, '가운데 쪽: 5 / 10 · 앞뒤 링크 · 정렬 그대로');
$ok(strpos($pg1, 'dhr-pg__b--prev is-off') !== false && strpos($pg1, 'page/2/') !== false && strpos($pgL, 'dhr-pg__b--next is-off') !== false, '첫 쪽은 이전이 잠기고 · 끝 쪽은 다음이 잠긴다');
$ok(strpos($PG(30, 10), '<b>10</b> / 10') !== false, '범위 밖 쪽 번호는 끝으로 맞춘다');
echo $fail ? "\n❌ ".count($fail)."건\n".implode("\n",$fail)."\n" : "\n✅ 모두 통과\n";
exit($fail?1:0);
