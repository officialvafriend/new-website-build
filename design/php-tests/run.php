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

// 41. 카드 한 줄
$GLOBALS['__cart']->items = [];
$note = apply_filters('duckhoo_card_extra', '', $lowst);
$ok(str_contains($note, '남은 수량 4개') && str_contains($note, '하루 10병'), '낱병 카드에 남은 재고와 하루 한도를 적는다');
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

// 홈 공지 띠 — 이벤트가 끝났으면 「진행 중」이 떠 있으면 안 된다
$ok(str_contains(($F.'announce')(), '조기 종료'), '공지 띠가 이벤트가 끝났다고 말한다');
$ok(!str_contains(($F.'announce')(), '진행 중'), '「진행 중」이라고 말하지 않는다');
$ok(str_contains(($F.'announce')(), '10만원 이상'), '손님이 본 이름 그대로 쓴다');

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
$ok(str_contains(($F.'announce')(), '9월 9일'), '언제부터인지 적는다');
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
ob_start(); ($N.'product_notice')($lowst); $pn = ob_get_clean();   // 재고 관리가 켜진 상품
$ok(!str_contains($pn, '제한') && !str_contains($pn, '하루 한 세트'), '상세 안내가 제한을 말하지 않는다');
$ok(str_contains($pn, '남은 재고'), '남은 재고를 말할 자리는 남는다');
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
];
$fm = ($S.'fee_map')([21,22]);
$ok(($fm[21]['fee'] ?? 0) === 10000.0, '자동 할인은 나가는 돈으로 센다');
$ok(($fm[21]['points'] ?? 0) === 3000.0, '적립금 줄은 적립금으로 가른다');
$ok(!isset($fm[22]), '양수 수수료는 할인이 아니므로 세지 않는다');

$GLOBALS['__orders'] = [
  new DhrSalesOrder(21, '2026-09-02', 'delivered', 90000.0, 7),
  new DhrSalesOrder(23, '2026-09-02', 'delivered', 90000.0, 7, ['_wd_point_discount'=>5000]),
];
$GLOBALS['__fee_rows'][] = ['oid'=>23,'name'=>'적립금 할인','amt'=>'-9999'];
$got = [];
foreach (($S.'fetch')([]) as $r2) { $got[$r2['id']] = $r2; }
$ok($got[21]['p'] === 3000.0 && $got[21]['fee'] === 10000.0, '메타가 없으면 수수료 줄의 적립금을 쓴다');
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
$ok($part === true && count($got2) === 200, '시간이 넘으면 멈추고 「일부」라고 알린다');
$part = false;
($S.'fetch')([], microtime(true) + 60, $part);
$ok($part === false, '시간이 남으면 「일부」가 아니다');
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
$GLOBALS['__qv'] = [];
$ok(($S.'description')('') === '', '아무 화면도 아니면 비운다');
$GLOBALS['__is_product'] = true; $GLOBALS['__qid'] = 901;
$ok(str_starts_with(($S.'description')(''), '라임 두 겹'), '상품 상세는 상품 글');
$GLOBALS['__is_product'] = false; $GLOBALS['__qid'] = 0;
// 브랜드 페이지
$ok(($S.'brand_slug')('노보') === 'novo' && ($S.'brand_slug')('조바') === rawurlencode('조바'), '영문 조각이 있으면 그것, 없으면 한글 그대로');
$ok(($S.'brand_from_slug')('novo') === '노보' && ($S.'brand_from_slug')('NOVO') === '노보', '조각 → 브랜드 (대소문자 무시)');
$ok(($S.'brand_from_slug')(rawurlencode('조바')) === '조바', '한글 조각도 상품에 있는 브랜드면 찾는다');
$ok(($S.'brand_from_slug')('nope') === '' && ($S.'brand_from_slug')('') === '', '모르는 조각은 빈 문자열');
$ok(($S.'brand_url')('노보') === 'https://duck-hoo.com/brand/novo/', '브랜드 주소');
$ok(apply_filters('duckhoo_brand_url', 'https://duck-hoo.com/?s=노보', '노보') === 'https://duck-hoo.com/brand/novo/', '푸터 · 홈의 브랜드 링크가 이 주소로 바뀐다');
$ok(($S.'brand_prefixes')('노보') === ['[노보]','[노보 블랙]'], '노보는 노보 블랙까지 함께 잡는다');
$GLOBALS['__qv'] = ['dhr_brand' => 'novo'];
$ok(($S.'is_brand_page')() && ($S.'current_brand')() === '노보', '주소 조각이 있으면 브랜드 페이지');
$ok(($S.'brand_title')() === '노보 액상', '브랜드 페이지 h1');
$ok(($S.'title')('AIOSEO 제목') === '노보 액상 2종 | 액상덕후', '브랜드 페이지 제목은 우리가 정한다 (노보 블랙 포함 2종)');
$bi = ($S.'brand_intro')('노보');
$ok(str_contains($bi, '노보 액상 2종') && str_contains($bi, '입호흡 액상') && !str_contains($bi, '특가'), '소개 한 줄은 개수와 실제 분류를 말한다');
$ok(($S.'description')('') === $bi && ($S.'canonical')('x') === 'https://duck-hoo.com/brand/novo/', '메타 설명 · canonical 도 브랜드 것');
$ok(str_contains(($S.'brand_intro_html')(), 'class="dhr-brandintro"'), '화면에 글자로 그린다');
ob_start(); ($S.'head')(); $h = ob_get_clean();
$ok(substr_count($h, 'name="description"') === 1 && str_contains($h, 'og:title" content="노보 액상 2종 | 액상덕후"') && str_contains($h, 'og:url" content="https://duck-hoo.com/brand/novo/"'), '브랜드 페이지는 설명 · og 를 우리가 찍는다 (AIOSEO 가 이 화면을 모른다)');
$ok(($S.'take_archive')(false) === true && ($S.'funnel_stage')('') === 'list', '목록 템플릿 · 깔때기 목록 단계');
$q = new DhrFakeQuery(['dhr_brand' => 'novo']);
($S.'pre_get_posts')($q);
$ok($q->get('post_type') === 'product' && $q->is_home === false && $q->is_archive === true && !$q->get('post__in'), '메인 쿼리를 상품 목록으로 바꾸고 is_home 을 끈다');
$w = ($S.'posts_where')(' AND 1=1', $q);
$ok(str_contains($w, 'wp_posts.post_title LIKE %s OR wp_posts.post_title LIKE %s') && $GLOBALS['wpdb']->lastArgs === ['[노보]%', '[노보 블랙]%'], '이름 앞 [노보] · [노보 블랙] 으로 고른다');
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

echo $fail ? "\n❌ ".count($fail)."건\n".implode("\n",$fail)."\n" : "\n✅ 모두 통과\n";
exit($fail?1:0);
