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

// 기본값은 **낱병 제한 없음**(묶음만). 아래 항목들은 낱병까지 세는 경우도 함께 재려고
// 켜 두고, 기본값은 마지막에 따로 확인한다.
$singlesOn = function(){ $GLOBALS['__filters']['duckhoo_novo_event'] = [fn($c) => ['singles' => true] + $c]; };
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
$threw = false; try { ($N.'guard_order')(); } catch (\Throwable $e) { $threw = str_contains($e->getMessage(), '하루 한도'); }
$ok($threw, '한도를 넘은 장바구니는 주문이 만들어지지 않는다');
$GLOBALS['__cart']->items = ['abc' => ['data' => $plain, 'quantity' => 10]];
$threw = false; try { ($N.'guard_order')(); } catch (\Throwable $e) { $threw = true; }
$ok(!$threw, '한도 안이면 주문이 그대로 만들어진다');
$GLOBALS['__cart']->items = [];

// 41-c. 금액대별 자동 할인 규칙은 그대로 읽는다 (노보 제외는 쿠폰 플러그인 쪽 일이다)
$F = 'Duckhoo\\Redesign\\Front\\';
$ok(($F.'discount_for')(99999.0) === 0 && ($F.'discount_for')(100000.0) === 10000, '10만원부터 1만원 할인');
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
$GLOBALS['__filters']['duckhoo_novo_event'] = [];
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
$GLOBALS['__filters']['duckhoo_novo_event'] = [];
$GLOBALS['__cart']->items = [];

echo $fail ? "\n❌ ".count($fail)."건\n".implode("\n",$fail)."\n" : "\n✅ 모두 통과\n";
exit($fail?1:0);
