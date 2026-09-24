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
$GLOBALS['__slugs'][901] = rawurlencode('펠릭스-더블라임-9-8mg-30ml');
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
$GLOBALS['__slugs'][901] = rawurlencode('펠릭스-더블라임-9-8mg-30ml');
// 2026-09-21 — 코덱스가 노보 13개에 규격대로 찍은 꼬리도 템플릿이다 (맛이 없고 다섯 개는 남의 맛).
$codex = '노보 블랙 데저트 액상 30ml, 니코틴 9.8mg 입호흡(MTL) 전용. 액상덕후에서 3만원 이상 무료배송, 신규 가입 시 적립금 8,800원 증정.';
$codex2 = '노보 타박멘솔 액상 30ml, 니코틴 9.8mg 입호흡(MTL) 전용. 액상덕후 노보 액상 판매량 1위 제품. 3만원 이상 무료배송, 가입 시 적립금 8,800원.';
$codex3 = '노보 액상 30ml 10병을 병당 7,000원(총 70,000원)에. 맛 조합 선택 가능한 입호흡 전자담배 액상 묶음 상품. 무료배송, 가입 시 적립금 8,800원.';
$felix = '[펠릭스] 더블라임 20,000원 - 입호흡 전용 더블라임 향 액상(9.8mg/30ml). 3만원 이상 무료배송, 신규가입 적립금 8,800원 증정. 액상덕후.';
$ok(($S.'templated')($codex) && ($S.'templated')($codex2) && ($S.'templated')($codex3), '코덱스 규격 꼬리 세 가지를 템플릿으로 본다');
$ok(!($S.'templated')($felix) && !($S.'templated')('라임 두 겹. 액상덕후 — 가입 즉시 8,800원 적립.'), '펠릭스 손글(「신규가입」 빈칸 없음) · 우리 꼬리는 안 걸린다');
$GLOBALS['__slugs'][901] = rawurlencode('노보-블랙-타박멘솔-9-8mg-30ml');
$d = ($S.'description')($codex);
$ok(str_starts_with($d, '담배 잎의 구수함') && !str_contains($d, '데저트'), '블랙 타박멘솔에 붙어 있던 「데저트」 설명이 우리 글로 바뀐다');
$GLOBALS['__slugs'][901] = rawurlencode('노보-엠에스블랜드-9-8mg-30ml');
$ok(str_starts_with(($S.'description')($codex2), '구수한 연초') && !str_contains(($S.'description')($codex2), '판매량 1위'), '엠에스블랜드에 붙어 있던 「타박멘솔 판매량 1위」가 우리 글로 바뀐다');
$GLOBALS['__slugs'][901] = rawurlencode('펠릭스-더블라임-9-8mg-30ml');
$ok(($S.'description')($felix) === $felix, '펠릭스 손글은 그대로');
// 노보 상품 제목 — 값 · 병 수는 상품에서 읽는다
$GLOBALS['__products'][905] = new WC_Product(905, '[노보] 타박멘솔 (9.8mg / 30ml)', 13000);
$GLOBALS['__products'][906] = new WC_Product(906, '[노보 블랙 리퀴드] 10+1 | 금액 130,000원', 130000);
$GLOBALS['__products'][907] = new WC_Product(907, '[노보 블랙] 엠에스블랜드 (9.8mg / 30ml)', 13500);
$ok(($S.'product_title')($GLOBALS['__products'][905]) === '노보 타박멘솔 입호흡 액상 9.8mg 30ml 13,000원 | 액상덕후', '노보 낱병 제목: 브랜드 · 맛 · 입호흡 액상 · 규격 · 값');
$ok(($S.'product_title')($GLOBALS['__products'][906]) === '노보 블랙 액상 10+1 묶음 11병 130,000원 입호흡 | 액상덕후', '노보 10+1 제목: 「리퀴드」를 떼고 병 수 · 값');
$ok(($S.'product_title')($GLOBALS['__products'][907]) === '노보 블랙 엠에스블랜드 입호흡 액상 9.8mg 30ml 13,500원 | 액상덕후', '노보 블랙 낱병 제목');
$ok(($S.'product_title')($GLOBALS['__products'][901]) === '', '펠릭스는 제목을 안 정한다 (AIOSEO 값 그대로)');
$GLOBALS['__qid'] = 905;
$ok(($S.'title')('[노보] 타박멘솔 (9.8mg / 30ml) - 액상덕후') === '노보 타박멘솔 입호흡 액상 9.8mg 30ml 13,000원 | 액상덕후', 'title 필터가 노보 상품에서 우리 제목을 준다');
$tags = ($S.'social_title')(['og:title' => '[노보] 타박멘솔 (9.8mg / 30ml) - 액상덕후', 'og:type' => 'product']);
$ok($tags['og:title'] === '노보 타박멘솔 입호흡 액상 9.8mg 30ml 13,000원 | 액상덕후' && $tags['og:type'] === 'product' && !isset($tags['twitter:title']), 'og:title 만 바꾸고 없는 칸은 만들지 않는다');
$GLOBALS['__qid'] = 901;
$ok(($S.'title')('[펠릭스] 더블라임 20,000원 입호흡 액상 | 액상덕후') === '[펠릭스] 더블라임 20,000원 입호흡 액상 | 액상덕후', '펠릭스 제목은 AIOSEO 값 그대로');
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
$ok(($S.'title')('AIOSEO 제목') === '노보 액상 2종 전 라인 재고 보유 | 액상덕후', '브랜드 페이지 제목은 우리가 정한다 — 노보는 「재고 보유」까지 (2026-09-21)');
$ok(str_contains(($S.'brand_intro')('노보'), '재고를 보유'), '노보 소개 첫 문장이 재고를 말한다 — 검색 결과에 그대로 찍힌다');
add_filter('duckhoo_brand_notes', fn($v = null) => []);
$ok(($S.'title')('x') === '노보 액상 2종 | 액상덕후' && !str_contains(($S.'brand_intro')('노보'), '재고를 보유'), '품절이 풀리면 필터 하나로 제목 · 소개가 원래대로');
$GLOBALS['__filters']['duckhoo_brand_notes'] = [];
$bi = ($S.'brand_intro')('노보');
$ok(str_contains($bi, '노보 액상 2종') && str_contains($bi, '입호흡 액상') && !str_contains($bi, '특가'), '소개 한 줄은 개수와 실제 분류를 말한다');
$ok(($S.'description')('') === $bi && ($S.'canonical')('x') === 'https://duck-hoo.com/brand/novo/', '메타 설명 · canonical 도 브랜드 것');
$ok(str_contains(($S.'brand_intro_html')(), 'class="dhr-brandintro"'), '화면에 글자로 그린다');
ob_start(); ($S.'head')(); $h = ob_get_clean();
$ok(substr_count($h, 'name="description"') === 1 && str_contains($h, 'og:title" content="노보 액상 2종 전 라인 재고 보유 | 액상덕후"') && str_contains($h, 'og:url" content="https://duck-hoo.com/brand/novo/"'), '브랜드 페이지는 설명 · og 를 우리가 찍는다 (AIOSEO 가 이 화면을 모른다)');
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
$ok(str_starts_with($xml, '<?xml') && str_contains($xml, '<loc>https://duck-hoo.com/brand/novo/</loc>') && str_contains($xml, '/brand/felix/') && str_contains($xml, '/brand/' . rawurlencode('조바') . '/'), '브랜드 사이트맵에 상품이 있는 브랜드가 전부 실린다');
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
$GLOBALS['__slugs'][901] = rawurlencode('펠릭스-더블라임-9-8mg-30ml');
$ok(str_starts_with(($S.'hand_text')($GLOBALS['__products'][901]), '라임을 두 겹'), '상자가 비면 승인된 글을 쓴다 (slug 는 퍼센트 인코딩돼 있어도)');
ob_start(); ($S.'render_text')($GLOBALS['__products'][901]); $h = ob_get_clean();
$ok(str_contains($h, 'dhp-about') && str_contains($h, '입호흡(MTL)'), '화면에도 그린다');
$GLOBALS['__pmeta'][901]['_dhr_text'] = '사장님이 상자에 쓴 글';
$ok(($S.'hand_text')($GLOBALS['__products'][901]) === '사장님이 상자에 쓴 글', '상자의 글이 먼저다');
$GLOBALS['__pmeta'][901] = [];
$GLOBALS['__slugs'][902] = '노보-10-1';
$GLOBALS['__slugs'][903] = rawurlencode('노보-블랙-블랙멘솔-9-8mg-30ml');
$ok(str_contains(($S.'hand_text')($GLOBALS['__products'][903]), '노보보다') && count(('Duckhoo\\Redesign\\Seo\\Texts\\texts')()) === 63, '상품 글이 실렸다 (주소 63개 — 이름이 다른 상품은 옛 주소 · 새 주소 둘 다)');
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
$ok(($S2.'title')('노보 액상 가격 8종 | 10병 특가 병당7,000원') === '노보 액상 3종 전 라인 재고 보유 | 액상덕후', '노보 분류 제목은 우리가 쓴다 — 종수는 재고 있는 상품만 센다 (품절 1개 제외)');
$d = ($S2.'description')('노보(NOVO) 액상 가격 안내: 10병 묶음 특가 병당 7,000원');
$ok(str_contains($d, '재고를 보유') && str_contains($d, '낱병 13,000원부터') && str_contains($d, '10+1 묶음(11병) 120,000원') && str_contains($d, '병당 약 10,900원'), '설명의 값은 상품에서 읽는다 — 낱병 최저가 · 묶음 · 병당');
$ok(!str_contains($d, '7,000'), '손으로 쓴 옛 값(7,000원)은 검색 결과로 나가지 않는다');
$ok(!preg_match('/건강|금연|순하|해롭지/u', $d), '광고 제한 낱말이 없다');
ob_start(); ($S2.'head')(); $hh = ob_get_clean();
$ok(str_contains($hh, 'og:title" content="노보 액상 3종 전 라인 재고 보유 | 액상덕후"') && str_contains($hh, 'og:description" content="액상덕후는 노보'), '노보 분류에는 og 를 우리가 찍는다 — 카카오톡 미리보기용 (AIOSEO 가 분류에는 안 찍는다)');
$GLOBALS['__qobj'] = (object) ['slug' => 'other-cat', 'name' => '입호흡 액상', 'description' => ''];
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
$ok(($GLOBALS['__postmeta'][11]['_dhr_pages_hash'] ?? '') === md5(trim($defs['shipping']['content'])) && $GLOBALS['__options']['duckhoo_pages_version'] === 2 && !$GLOBALS['__inserted'], '바꾼 글의 해시를 남기고 버전을 올린다 · 새로 만들지는 않는다');
$GLOBALS['__options']['duckhoo_pages_version'] = 2; $GLOBALS['__updated'] = [];
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

echo $fail ? "\n❌ ".count($fail)."건\n".implode("\n",$fail)."\n" : "\n✅ 모두 통과\n";
exit($fail?1:0);
