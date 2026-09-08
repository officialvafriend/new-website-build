<?php
/* 워드프레스 없이 로직만 돌려 보기 위한 최소 스텁 */
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);

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
function current_time($t){ return '2026-09-02 00:00:00'; }
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
    $want = array_map(fn($s) => ltrim((string)$s, 'wc-'), $args['status']);
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
if(!function_exists('get_privacy_policy_url')) { function get_privacy_policy_url(){ return ''; } } if(!function_exists('wp_date')) { function wp_date($f){ return date($f); } } if(!function_exists('has_term')) { function has_term(...$a){ return false; } } if(!function_exists('get_permalink_stub')) { function get_permalink_stub(){ } }

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
  public function __construct(public ?WC_Product $p = null, public int $q = 1){}
  public function get_product(){ return $this->p; }
  public function get_quantity(){ return $this->q; }
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
