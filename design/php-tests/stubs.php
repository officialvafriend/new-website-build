<?php
/* 워드프레스 없이 로직만 돌려 보기 위한 최소 스텁 */
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
if(!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

$GLOBALS['__filters'] = [];
$GLOBALS['__actions'] = [];
$GLOBALS['__usermeta'] = [];
$GLOBALS['__orders'] = [];
$GLOBALS['__logged_in'] = 1;
$GLOBALS['__transients'] = [];

function add_filter($h,$cb,$p=10,$a=1){ $GLOBALS['__filters'][$h][]=$cb; }
function remove_filter($h,$cb,$p=10){ }
function add_action($h,$cb,$p=10,$a=1){ $GLOBALS['__actions'][$h][]=$cb; }
if ( ! function_exists( 'register_activation_hook' ) ) { function register_activation_hook($f,$cb){ $GLOBALS['__activation'][]=$cb; } }
function do_action($h,...$a){ }
function add_shortcode($t,$cb){ $GLOBALS['__shortcodes'][$t]=$cb; }
function apply_filters($h,$v,...$rest){ foreach($GLOBALS['__filters'][$h]??[] as $cb){ $v=$cb($v,...$rest);} return $v; }
function __return_false(){ return false; }
function __($s,$d=null){ return $s; }
function esc_html__($s,$d=null){ return $s; }
function esc_html_e($s,$d=null){ echo $s; }
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return $s; }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function wp_strip_all_tags($s){ return strip_tags((string)$s); }
function sanitize_text_field($s){ return trim(strip_tags((string)$s)); }
function wp_unslash($s){ return is_string($s)?stripslashes($s):$s; }
function number_format_i18n($n){ return number_format((float)$n); }
$GLOBALS['__now'] = mktime(9, 30, 0, 9, 2, 2026);
function current_time($t){ $n=(int)$GLOBALS['__now'];
  if($t==='timestamp'||$t==='U') return $n;
  if($t==='mysql') return '2026-09-02 00:00:00';
  return date($t, $n); }
function home_url($p=''){ return 'https://duck-hoo.com'.$p; }
function get_permalink($p=null){ return 'https://duck-hoo.com/membership-cancel/'; }
function wp_login_url($r=''){ return 'https://duck-hoo.com/login/'; }
function is_user_logged_in(){ return (bool)$GLOBALS['__logged_in']; }
function get_current_user_id(){ return (int)$GLOBALS['__logged_in']; }
function get_user_meta($id,$key='',$single=false){
  $m = $GLOBALS['__usermeta'][$id] ?? [];
  if ($key==='') { $out=[]; foreach($m as $k=>$v){ $out[$k]=[$v]; } return $out; }
  return $single ? ($m[$key] ?? '') : (isset($m[$key])?[$m[$key]]:[]);
}
function update_user_meta($id,$k,$v){ $GLOBALS['__usermeta'][$id][$k]=$v; }
function delete_user_meta($id,$k){ unset($GLOBALS['__usermeta'][$id][$k]); }
function maybe_unserialize($v){ return $v; }
function get_userdata($id){ $u=new stdClass; $u->ID=$id; $u->user_pass='hash'; return $u; }
function wp_update_user($a){ $GLOBALS['__updated']=$a; return $a['ID']; }
function wp_set_password($p,$id){ $GLOBALS['__pwset']=true; }
function wp_generate_password($l=12,$s=true,$x=false){ return str_repeat('x',$l); }
function wp_check_password($p,$h,$id=''){ return $p==='correct'; }
function wp_logout(){ $GLOBALS['__logged_in']=0; }
function wp_safe_redirect($u){ $GLOBALS['__redirect']=$u; throw new \RuntimeException('redirect'); }
function add_query_arg($k,$v,$u){ return $u.'?'.$k.'='.$v; }
function wp_verify_nonce($n,$a){ return $n==='good'; }
function wp_nonce_field($a){ echo '<input type="hidden" name="_wpnonce" value="good">'; }
function set_transient($k,$v,$t){ $GLOBALS['__transients'][$k]=$v; }
function get_transient($k){ return $GLOBALS['__transients'][$k] ?? false; }
function delete_transient($k){ unset($GLOBALS['__transients'][$k]); }
function is_page(){ return true; }
function in_the_loop(){ return true; }
function is_main_query(){ return true; }
function get_post(){ $p=new stdClass; $p->post_name='membership-cancel'; $p->post_content=$GLOBALS['__page_content'] ?? '/'; return $p; }
function has_shortcode($c,$t){ return str_contains((string)$c,'['.$t); }
function wc_get_account_endpoint_url($e){ return 'https://duck-hoo.com/my-account/'.$e.'/'; }
function wc_get_order_statuses(){
  return [
    'wc-pending'=>'결제 대기','wc-processing'=>'처리 중','wc-on-hold'=>'보류',
    'wc-completed'=>'완료','wc-cancelled'=>'취소됨','wc-refunded'=>'환불됨','wc-failed'=>'실패',
    'wc-keyple-before'=>'입금전','wc-keyple-check'=>'확인필요','wc-keyple-paid'=>'입금확인',
    'wc-keyple-ready'=>'배송준비중','wc-keyple-shipping'=>'배송중','wc-keyple-done'=>'배송완료',
  ];
}
function wc_get_orders($args){
  $out = $GLOBALS['__orders'];
  // 상태 거르기 — 취소·환불된 주문이 한도에서 빠지는지 실제로 재려면 이게 있어야 한다.
  if (!empty($args['status']) && is_array($args['status'])) {
    $want = array_map(fn($s) => preg_replace('/^wc-/', '', (string)$s), $args['status']);
    $out = array_values(array_filter($out, fn($o) => !is_object($o) || !property_exists($o,'status') || in_array($o->status, $want, true)));
  }
  return $out;
}

if(!function_exists('plugin_dir_path')) { function plugin_dir_path($f){ return dirname($f).'/'; } }
if(!function_exists('plugin_dir_url')) { function plugin_dir_url($f){ return 'https://example.test/wp-content/plugins/new-website-build/'; } }
if(!function_exists('wp_enqueue_style')) { function wp_enqueue_style(...$a){} } if(!function_exists('wp_enqueue_script')) { function wp_enqueue_script(...$a){} } if(!function_exists('wp_add_inline_script')) { function wp_add_inline_script(...$a){} } if(!function_exists('wp_dequeue_style')) { function wp_dequeue_style(...$a){} } if(!function_exists('plugins_url')) { function plugins_url(...$a){ return ''; } }
if(!function_exists('is_admin')) { function is_admin(){ return false; } } if(!function_exists('is_front_page')) { function is_front_page(){ return false; } } if(!function_exists('is_page')) { function is_page($s=''){ return false; } }
if(!function_exists('get_terms')) { function get_terms($a){ return []; } } if(!function_exists('is_wp_error')) { function is_wp_error($x){ return false; } } if(!function_exists('wp_timezone')) { function wp_timezone(){ return new DateTimeZone('Asia/Seoul'); } }
if(!function_exists('get_theme_mod')) { function get_theme_mod($k){ return 0; } } if(!function_exists('wp_get_attachment_image')) { function wp_get_attachment_image(...$a){ return ''; } } if(!function_exists('get_bloginfo')) { function get_bloginfo($k){ return '액상덕후'; } }
if(!function_exists('get_search_query')) { function get_search_query(){ return ''; } } if(!function_exists('language_attributes')) { function language_attributes(){} } if(!function_exists('body_class')) { function body_class(){} } if(!function_exists('wp_head')) { function wp_head(){} } if(!function_exists('wp_footer')) { function wp_footer(){} } if(!function_exists('wp_body_open')) { function wp_body_open(){} }
if(!function_exists('get_privacy_policy_url')) { function get_privacy_policy_url(){ return ''; } } if(!function_exists('wp_date')) { function wp_date($f,$ts=null){ return date($f, null===$ts ? time() : (int)$ts); } } if(!function_exists('has_term')) { function has_term(...$a){ return false; } } if(!function_exists('get_permalink_stub')) { function get_permalink_stub(){ } }

/* ── 적립금 주문 스텁 ────────────────────────────────────────────────────
   points.php 는 WC_Order 의 몇 가지 메서드만 쓴다. 그만큼만 흉내 낸다. */
class DhrFakeItem {
  public function __construct(public string $n='', public float $t=0.0, public string $c='', public float $d=0.0){}
  public function get_name(){ return $this->n; }
  public function get_total(){ return $this->t; }
  public function get_code(){ return $this->c; }
  public function get_discount(){ return $this->d; }
}
class DhrFakeMeta {
  public function __construct(public string $k, public $v){}
  public function get_data(){ return ['key'=>$this->k, 'value'=>$this->v]; }
}
class DhrFakeOrder {
  public array $notes = [];
  public int $uid = 0;
  public function __construct(public int $id=1, public array $meta=[], public array $fees=[], public array $coupons=[], public string $status='on-hold'){}
  public function get_id(){ return $this->id; }
  public function get_order_number(){ return (string)$this->id; }
  public function get_customer_id(){ return $this->uid; }
  public function get_meta($k, $single=true){ return $this->meta[$k] ?? ''; }
  public function update_meta_data($k,$v){ $this->meta[$k]=$v; }
  public function save(){ }
  public function get_meta_data(){ $o=[]; foreach($this->meta as $k=>$v) $o[]=new DhrFakeMeta($k,$v); return $o; }
  public array $lines = [];
  public function get_items($type='line_item'){ return $type==='fee' ? $this->fees : ($type==='coupon' ? $this->coupons : $this->lines); }
  public function get_date_created(){ return null; }
  public function has_status($s){ return in_array($this->status, (array)$s, true); }
  public function add_order_note($t){ $this->notes[]=$t; }
}
if(!function_exists('wc_get_order')) { function wc_get_order($id){ return $GLOBALS['__order_by_id'][$id] ?? null; } }

/* 테마(키플_액상덕후 functions.php)의 적립금 함수 — 있는 것처럼 흉내만 낸다 */
$GLOBALS['__keyple_on'] = true;
$GLOBALS['__ledger'] = [];
function wd_is_keyple_crm_active(){ return (bool)$GLOBALS['__keyple_on']; }
function wd_log_keyple_points_change($uid,$delta,$desc=''){ $GLOBALS['__ledger'][] = [$uid,$delta,$desc]; }
function wd_signup_point_amount(){ return 8800; }

// 메인 플러그인 파일을 그대로 읽으면 includes/ 도 따라 읽힌다. front.php 는 wc_get_products 없이도 정의만 된다.
$src = file_get_contents(dirname(__DIR__, 2).'/duckhoo-redesign.php');
$src = str_replace("require_once plugin_dir_path( __FILE__ ) . 'includes/membership-cancel.php';", '', $src); // 이미 읽었다
$src = preg_replace('/^<\?php\s*/', '', $src, 1);
file_put_contents(dirname(__DIR__, 2).'/.dhr-main-test.php', "<?php\n".$src);


/* ── 노보 이벤트 스텁 ────────────────────────────────────────────────────
   novo.php 는 WC_Product 의 몇 가지 메서드와 장바구니만 쓴다. 그만큼만 흉내 낸다. */
class WC_Product {
  public function __construct(
    public int $id = 1, public string $name = '', public float $price = 0.0,
    public bool $in_stock = true, public bool $manage = false, public ?int $stock = null,
    public float $regular = 0.0, public string $sale = ''
  ){ if ($this->regular === 0.0) { $this->regular = $this->price; } }
  public function get_id(){ return $this->id; }
  public function get_name(){ return $this->name; }
  public function get_price(){ return $this->price; }
  public function get_regular_price(){ return (string)$this->regular; }
  public function get_sale_price(){ return $this->sale; }
  public function set_regular_price($v){ $this->regular = (float)$v; $this->price = (float)$v; }
  public function set_sale_price($v){ $this->sale = (string)$v; }
  public function is_on_sale(){ return $this->sale !== ''; }
  public function is_in_stock(){ return $this->in_stock; }
  public function managing_stock(){ return $this->manage; }
  public function get_stock_quantity(){ return $this->stock; }
  public function get_average_rating(){ return 0.0; }
  public function get_meta($k, $single = true){ return $GLOBALS['__pmeta'][$this->id][$k] ?? ''; }
  public function update_meta_data($k,$v){ $GLOBALS['__pmeta'][$this->id][$k] = $v; }
  public function delete_meta_data($k){ unset($GLOBALS['__pmeta'][$this->id][$k]); }
  public function save(){ }
}
class DhrFakeLine {
  public function __construct(public ?WC_Product $p = null, public int $q = 1, public array $meta = []){}
  public function get_product(){ return $this->p; }
  public function get_quantity(){ return $this->q; }
  public function get_meta_data(){ $o=[]; foreach($this->meta as $k=>$v) $o[]=new DhrFakeMeta($k,$v); return $o; }
}
class DhrFakeFee {
  public function __construct(public string $name='', public float $amount=0.0, public bool $taxable=false, public string $tax_class=''){}
}
class DhrFakeFeesApi {
  public function __construct(private DhrFakeCart $c){}
  public function get_fees(){ return $this->c->fees; }
  public function remove_all_fees(){ $this->c->fees = []; }
  public function add_fee($a){ $this->c->fees[] = new DhrFakeFee((string)$a['name'], (float)$a['amount'], !empty($a['taxable']), (string)($a['tax_class'] ?? '')); }
}
class DhrFakeCart {
  public array $items = [];
  public array $fees = [];
  public function get_cart(){ return $this->items; }
  public function fees_api(){ return new DhrFakeFeesApi($this); }
}
$GLOBALS['__pmeta'] = [];
$GLOBALS['__products'] = [];
$GLOBALS['__notices'] = [];
$GLOBALS['__cart'] = new DhrFakeCart();
if(!function_exists('wc_get_product')) { function wc_get_product($id){ return $GLOBALS['__products'][(int)$id] ?? null; } }
if(!function_exists('wc_add_notice')) { function wc_add_notice($m,$t='success'){ $GLOBALS['__notices'][] = [$t,$m]; } }
if(!function_exists('WC')) { function WC(){ return (object)['cart' => $GLOBALS['__cart']]; } }


/* ── 회원 휴대폰번호 조회 스텁 ────────────────────────────────────────────
   signup.php 는 usermeta 를 한 번 훑어 같은 번호를 쓰는 계정을 찾는다.
   $GLOBALS['__phone_users'] 가 그 결과를 대신한다 (번호 → 회원 ID 목록). */
class DhrFakeWpdb {
  public string $usermeta = 'wp_usermeta';
  public string $options = 'wp_options';
  public string $users = 'wp_users';
  public string $prefix = 'wp_';
  public array $lastArgs = [];
  public function get_results($sql, $mode=null){
    if (str_contains($sql, 'woocommerce_order_items')) return $GLOBALS['__fee_rows'] ?? [];
    if (str_contains($sql, 'user_registered')) return $GLOBALS['__signup_rows'] ?? [];
    if (str_contains($sql, 'dhr_funnel')) return $GLOBALS['__funnel_rows'] ?? [];
    return [];
  }
  public function prepare($sql, ...$a){ $this->lastArgs = (isset($a[0]) && is_array($a[0])) ? $a[0] : $a; return $sql; }
  public function get_col($sql){
    $n = end($this->lastArgs);
    return $GLOBALS['__phone_users'][$n] ?? [];
  }
  public function query($sql){ $GLOBALS['__sql'][] = $sql; return 1; }
  public function get_var($sql){ return $GLOBALS['__funnel_since'] ?? null; }
  public function get_charset_collate(){ return ''; }
  public string $posts = 'wp_posts';
  public function esc_like($t){ return addcslashes((string)$t, '_%\\'); }
}
$GLOBALS['wpdb'] = new DhrFakeWpdb();
$GLOBALS['__phone_users'] = [];
if(!function_exists('wp_lostpassword_url')) { function wp_lostpassword_url($r=''){ return 'https://example.test/lost/'; } }
if(!function_exists('wc_get_page_permalink')) { function wc_get_page_permalink($p){ return 'https://example.test/my-account/'; } }
if(!function_exists('wp_get_referer')) { function wp_get_referer(){ return ''; } }
if(!function_exists('wp_safe_redirect')) { function wp_safe_redirect($u){ $GLOBALS['__redirect'] = $u; } }
if(!function_exists('add_query_arg')) { function add_query_arg($k,$v,$u){ return $u.'?'.$k.'='.$v; } }
if(!function_exists('remove_query_arg')) { function remove_query_arg($k,$u){ return $u; } }
if(!function_exists('sanitize_text_field')) { function sanitize_text_field($v){ return trim((string)$v); } }

if(!function_exists('is_checkout')) { function is_checkout(){ return (bool)($GLOBALS['__is_checkout'] ?? false); } }


/* ── 테마 결제 템플릿 스텁 ──────────────────────────────────────────────
   discount.php 는 그 파일을 읽어 「스스로 다시 계산하는가」를 보고,
   그렇다면 숫자만 0 으로 바꾼 사본을 만들어 wc_get_template 으로 건넨다.
   여기서는 진짜 파일을 임시 폴더에 써서 그 길을 그대로 돌려 본다. */
if(!function_exists('get_theme_root')) { function get_theme_root(){ return sys_get_temp_dir().'/dhr-themes'; } }
if(!function_exists('get_template')) { function get_template(){ return 'fake'; } }
if(!function_exists('get_current_screen')) { function get_current_screen(){ return null; } }
if(!function_exists('current_user_can')) { function current_user_can($c,...$a){ return (bool)($GLOBALS['__can'] ?? false); } }
if(!function_exists('remove_action')) { function remove_action($h,$cb,$p=10){ } }
if(!function_exists('wp_mkdir_p')) { function wp_mkdir_p($d){ return is_dir($d) || @mkdir($d, 0777, true); } }
if(!function_exists('wp_upload_dir')) {
  function wp_upload_dir(){ $d = sys_get_temp_dir().'/dhr-uploads'; @mkdir($d, 0777, true); return ['basedir'=>$d,'error'=>false]; }
}
if(!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
(function(){
  $dir = sys_get_temp_dir().'/dhr-themes/fake/woocommerce/checkout';
  @mkdir($dir, 0777, true);
  $GLOBALS['__tier_file'] = $dir.'/form-checkout.php';
})();

/** 테마의 「표시 안정화」 블록이 있는 결제 템플릿 (진짜 모양에 가깝게). */
function dhr_tier_template(): string {
  return "<?php\ndefined( 'ABSPATH' ) || exit;\n"
    . "// 표시 안정화: fee 목록 대신 직접 구간 계산 (부과 로직과 동일 기준)\n"
    . "\$wd_tier_base = 0;\n"
    . "foreach ( WC()->cart->get_cart() as \$wd_ti ) { \$wd_tier_base += 1; }\n"
    . "if ( \$wd_tier_base >= 100000 ) { \$wd_auto_fee_discount = 10000; }\n"
    . "elseif ( \$wd_tier_base >= 80000 ) { \$wd_auto_fee_discount = 5000; }\n"
    . "elseif ( \$wd_tier_base >= 50000 ) { \$wd_auto_fee_discount = 3000; }\n";
}
function dhr_set_tier_recalc(bool $on): void {
  file_put_contents($GLOBALS['__tier_file'], $on ? dhr_tier_template() : "<?php // fee 합산만 한다\n");
  /* patched()/read() 는 mtime 으로 캐시 키를 만들고 static 으로 기억한다.
     테스트는 같은 초 안에 파일을 두 번 쓰므로 mtime 을 손으로 벌려 줘야
     키가 달라져 그 기억을 지나친다 (실제 사이트에서는 저절로 달라진다). */
  static $n = 0;
  touch($GLOBALS['__tier_file'], time() + ( ++$n * 60 ));
  clearstatcache(true, $GLOBALS['__tier_file']);
  $GLOBALS['__transients'] = [];
}

/* ── 상품 후기 스텁 ─────────────────────────────────────────────────────
   product.php 의 reviews_on() · open_reviews() 가 쓴다. */
if(!function_exists('get_option')) { function get_option($k,$d=false){ return $GLOBALS['__options'][$k] ?? $d; } }
if(!function_exists('get_post_type')) { function get_post_type($id=0){ return $GLOBALS['__posttype'][$id] ?? 'post'; } }
$GLOBALS['__options'] = [];
$GLOBALS['__posttype'] = [];

if(!function_exists('wc_customer_bought_product')) {
  function wc_customer_bought_product($email,$uid,$pid){ return !empty($GLOBALS['__bought'][$uid][$pid]); }
}
if(!function_exists('wp_die')) { function wp_die($m='',$t='',$a=[]){ throw new \RuntimeException('wp_die: '.$m); } }
$GLOBALS['__bought'] = [];

/* ── 사진 후기 스텁 ─────────────────────────────────────────────────────── */
if(!class_exists('WP_Comment')) {
  class WP_Comment {
    public $comment_ID=0, $comment_post_ID=0, $comment_type='review', $comment_approved='1', $user_id=0;
    function __construct($a=[]){ foreach($a as $k=>$v) $this->$k=$v; }
  }
}
$GLOBALS['__comments'] = [];      // id => WP_Comment
$GLOBALS['__cmeta'] = [];         // id => [key => value]
$GLOBALS['__titles'] = [];        // post id => 제목
if(!function_exists('get_comment')) { function get_comment($id=0){ return $GLOBALS['__comments'][(int)$id] ?? null; } }
if(!function_exists('get_comment_meta')) { function get_comment_meta($id,$k='',$single=false){ $v=$GLOBALS['__cmeta'][(int)$id][$k] ?? ''; return $single?$v:($v===''?[]:[$v]); } }
if(!function_exists('update_comment_meta')) { function update_comment_meta($id,$k,$v){ $GLOBALS['__cmeta'][(int)$id][$k]=$v; return true; } }
if(!function_exists('add_comment_meta')) { function add_comment_meta($id,$k,$v,$u=false){ $GLOBALS['__cmeta'][(int)$id][$k]=$v; return true; } }
if(!function_exists('delete_comment_meta')) { function delete_comment_meta($id,$k){ unset($GLOBALS['__cmeta'][(int)$id][$k]); return true; } }
if(!function_exists('get_comments')) {
  function get_comments($args=[]){
    $out=[];
    foreach($GLOBALS['__comments'] as $id=>$c){
      if((int)$c->comment_post_ID !== (int)($args['post_id']??0)) continue;
      if((int)$c->user_id !== (int)($args['user_id']??0)) continue;
      $out[]=$id;
    }
    return $out;
  }
}
if(!function_exists('get_the_title')) { function get_the_title($id=0){ return $GLOBALS['__titles'][(int)$id] ?? '상품'; } }
if(!function_exists('get_comments_number')) { function get_comments_number($id=0){ return 0; } }
if(!function_exists('comments_open')) { function comments_open($id=0){ return true; } }
if(!function_exists('comments_template')) { function comments_template(){ echo ''; } }
if(!function_exists('wp_get_attachment_image_url')) { function wp_get_attachment_image_url($id,$s=''){ return 'https://duck-hoo.com/i/'.$id.'.jpg'; } }
if(!function_exists('wp_get_attachment_image')) { function wp_get_attachment_image($id,$s='',$icon=false,$attr=[]){ return '<img src="https://duck-hoo.com/i/'.$id.'.jpg">'; } }
if(!function_exists('is_wp_error')) { function is_wp_error($t){ return false; } }

/* ── 매출 대시보드 스텁 ────────────────────────────────────────────────── */
$GLOBALS['__fee_rows'] = [];
$GLOBALS['__signup_rows'] = [];
if(!function_exists('absint')) { function absint($v){ return abs((int)$v); } }
if(!function_exists('register_shutdown_function_stub')) { }
if(!function_exists('wp_list_pluck')) { function wp_list_pluck($list, $field){ return array_map(fn($r) => is_array($r) ? ($r[$field] ?? null) : ($r->$field ?? null), (array)$list); } }
if(!function_exists('add_menu_page')) { function add_menu_page(...$a){ return ''; } }
if(!function_exists('admin_url')) { function admin_url($p=''){ return 'https://duck-hoo.com/wp-admin/'.$p; } }
if(!function_exists('wp_nonce_url')) { function wp_nonce_url($u,$a=''){ return $u.'&_wpnonce=good'; } }
if(!function_exists('check_admin_referer')) { function check_admin_referer($a=''){ return true; } }
if(!function_exists('wc_get_order_status_name')) { function wc_get_order_status_name($s){ return $GLOBALS['__status_names'][$s] ?? $s; } }
$GLOBALS['__status_names'] = ['on-hold'=>'결제 확인 중','delivered'=>'배송완료','ready-to-ship'=>'배송준비중'];

/** 매출 대시보드가 읽는 만큼만 흉내 낸 주문. */
class DhrSalesDate {
  public function __construct(public int $ts){}
  public function date($f){ return date($f, $this->ts); }
  public function getTimestamp(){ return $this->ts; }
}
class DhrSalesOrder {
  public function __construct(
    public int $id, public string $day, public string $status, public float $total,
    public int $uid = 0, public array $meta = [], public float $coupon = 0.0, public float $ship = 0.0
  ){}
  public function get_id(){ return $this->id; }
  public function get_date_created(){ return '' === $this->day ? null : new DhrSalesDate(strtotime($this->day.' 12:00:00')); }
  public function get_status(){ return $this->status; }
  public function get_total(){ return $this->total; }
  public function get_customer_id(){ return $this->uid; }
  public function get_meta($k, $single=true){ return $this->meta[$k] ?? ''; }
  public function get_discount_total(){ return $this->coupon; }
  public function get_shipping_total(){ return $this->ship; }
}

/* ── 깔때기 · 되돌림 스텁 ─────────────────────────────────────────────── */
$GLOBALS['__cookies_set'] = []; $GLOBALS['__did'] = []; $GLOBALS['__sql'] = []; $GLOBALS['__funnel_rows'] = [];
if(!function_exists('setcookie')) { }
function dhr_setcookie_stub($n,$v,$o=[]){ $GLOBALS['__cookies_set'][] = [$n,$v,$o]; return true; }
if(!function_exists('headers_sent')) { }
if(!function_exists('is_ssl')) { function is_ssl(){ return true; } }
if(!function_exists('untrailingslashit')) { function untrailingslashit($s){ return rtrim((string)$s,'/'); } }
if(!function_exists('wp_validate_redirect')) { function wp_validate_redirect($u,$d=''){ return str_starts_with((string)$u,'https://duck-hoo.com/') ? $u : $d; } }
if(!function_exists('did_action')) { function did_action($h){ return (int)($GLOBALS['__did'][$h] ?? 0); } }
if(!function_exists('register_rest_route')) { function register_rest_route(...$a){ return true; } }
if(!function_exists('rest_url')) { function rest_url($p=''){ return 'https://duck-hoo.com/wp-json/'.$p; } }
if(!function_exists('rest_ensure_response')) { function rest_ensure_response($r){ return $r; } }
if(!function_exists('update_option')) { function update_option($k,$v,$a=null){ $GLOBALS['__options'][$k]=$v; return true; } }
if(!function_exists('is_shop')) { function is_shop(){ return (bool)($GLOBALS['__is_shop'] ?? false); } }
if(!function_exists('is_product')) { function is_product(){ return (bool)($GLOBALS['__is_product'] ?? false); } }
if(!function_exists('is_product_taxonomy')) { function is_product_taxonomy(){ return false; } }
if(!function_exists('is_cart')) { function is_cart(){ return false; } }
if(!function_exists('is_search')) { function is_search(){ return false; } }
class DhrReq { public function __construct(public array $p){} public function get_param($k){ return $this->p[$k] ?? null; } }

// ── 검색 노출 (includes/seo.php) 스텁 ──
if(!function_exists('get_query_var')) { function get_query_var($k,$d=''){ return $GLOBALS['__qv'][$k] ?? $d; } }
if(!function_exists('get_the_terms')) { function get_the_terms($id,$tax){ return $GLOBALS['__pterms'][(int)$id] ?? []; } }
if(!function_exists('get_post_meta')) { function get_post_meta($id,$k,$s=false){ return $GLOBALS['__postmeta'][(int)$id][$k] ?? ''; } }
if(!function_exists('update_post_meta')) { function update_post_meta($id,$k,$v){ $GLOBALS['__postmeta'][(int)$id][$k]=$v; return true; } }
if(!function_exists('delete_post_meta')) { function delete_post_meta($id,$k){ unset($GLOBALS['__postmeta'][(int)$id][$k]); return true; } }
if(!function_exists('get_bloginfo')) { function get_bloginfo($k=''){ return '액상덕후'; } }
if(!function_exists('get_queried_object')) { function get_queried_object(){ return $GLOBALS['__qobj'] ?? null; } }
if(!function_exists('get_queried_object_id')) { function get_queried_object_id(){ return (int)($GLOBALS['__qid'] ?? 0); } }
if(!function_exists('esc_textarea')) { function esc_textarea($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if(!function_exists('add_meta_box')) { function add_meta_box(...$a){ $GLOBALS['__metaboxes'][] = $a[0]; } }
if(!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s){ return trim(strip_tags((string)$s)); } }
if(!function_exists('add_rewrite_tag')) { function add_rewrite_tag($t,$r){ $GLOBALS['__rw_tags'][] = $t; } }
if(!function_exists('add_rewrite_rule')) { function add_rewrite_rule($re,$q,$pos='bottom'){ $GLOBALS['__rw_rules'][$re] = $q; } }
if(!function_exists('flush_rewrite_rules')) { function flush_rewrite_rules($h=true){ $GLOBALS['__rw_flushed'] = ($GLOBALS['__rw_flushed'] ?? 0) + 1; } }
if(!function_exists('add_management_page')) { function add_management_page(...$a){ return ''; } }
class DhrFakeQuery {
  public array $v = []; public bool $is_home = true; public bool $is_archive = false; public bool $is_404 = false; public bool $main = true;
  public function __construct(array $v = [], bool $main = true){ $this->v = $v; $this->main = $main; }
  public function get($k,$d=''){ return $this->v[$k] ?? $d; }
  public function set($k,$val){ $this->v[$k] = $val; }
  public function is_main_query(){ return $this->main; }
}

// 실제 Front\products() 가 도는 길 — 상품은 $GLOBALS['__products'], 이름 검색은 mb_strpos.
if(!function_exists('wc_get_products')) { function wc_get_products($a){ return array_values($GLOBALS['__products'] ?? []); } }
if(!class_exists('WP_Query')) { class WP_Query { public array $posts = []; public function __construct(array $a = []){ foreach($GLOBALS['__products'] ?? [] as $p){ if(!isset($a['s']) || false !== mb_strpos($p->get_name(), (string)$a['s'])) $this->posts[] = $p->get_id(); } } } }
if(!function_exists('wp_json_encode')) { function wp_json_encode($v,$f=0){ return json_encode($v, $f | JSON_UNESCAPED_UNICODE); } }
