<?php
// 홈 템플릿을 스텁 + 프로덕션 카탈로그로 통째로 그려 본다 — 어디서 죽는지 보려고.
error_reporting(E_ALL); ini_set('display_errors','1');
require '/home/user/new-website-build/design/php-tests/stubs.php';
require_once '/home/user/new-website-build/includes/membership-cancel.php';
require_once '/home/user/new-website-build/.dhr-main-test.php';
$GLOBALS['dhr_test'] = true;
class DhrCart2 extends DhrFakeCart { public function get_cart_contents_count(){ return 0; } public function __call($n,$a){ return 0; } }
$GLOBALS['__cart'] = new DhrCart2();
class DhrP extends WC_Product {
  public function get_image_id(){ return 5; }
  public function get_image($s='',$a=[]){ return '<img src="x">'; }
  public function get_date_created(){ return null; }
  public function get_review_count(){ return 0; }
  public function get_rating_count(){ return 0; }
  public function get_total_sales(){ return 0; }
  public function get_stock_status(){ return $this->in_stock ? 'instock' : 'outofstock'; }
  public function get_short_description(){ return ''; }
  public function get_description(){ return ''; }
  public function get_sku(){ return ''; }
  public function get_type(){ return 'simple'; }
  public function __call($n,$a){ fwrite(STDERR, "[stub __call] $n\n"); return ''; }
}
$rows = [];
foreach (file(__DIR__.'/prods.json') as $l) { $l=trim($l); if($l) $rows = array_merge($rows, json_decode($l, true)); }
$GLOBALS['__products'] = []; $GLOBALS['__pterms'] = []; $GLOBALS['__transients'] = [];
foreach ($rows as $r) {
  $reg = (float)($r['prices']['regular_price'] ?? $r['prices']['price']); $pr=(float)$r['prices']['price'];
  $GLOBALS['__products'][$r['id']] = new DhrP($r['id'], $r['name'], $pr, (bool)$r['is_in_stock'], false, null, $reg, $reg>$pr ? (string)$pr : '');
  $GLOBALS['__pterms'][$r['id']] = array_map(fn($c)=>(object)['name'=>$c['name'],'slug'=>$c['slug'] ?? 'c','term_id'=>$c['id'] ?? 0], $r['categories']);
}
foreach ([
 'wc_get_page_permalink'=>fn()=>'https://duck-hoo.com/shop/',
 'get_term_link'=>fn($t)=>'https://duck-hoo.com/cat/',
 'add_query_arg'=>fn(...$a)=>'https://duck-hoo.com/shop/?x',
 'home_url'=>fn($p='')=>'https://duck-hoo.com'.$p,
 'wp_body_open'=>fn()=>null,'wp_head'=>fn()=>null,'wp_footer'=>fn()=>null,'language_attributes'=>fn()=>'lang="ko"','bloginfo'=>fn($x)=>'UTF-8','body_class'=>fn($c='')=>'class="'.$c.'"',
 'get_bloginfo'=>fn($x='')=>'액상덕후','is_user_logged_in'=>fn()=>false,'wp_login_url'=>fn()=>'/login/','wc_get_cart_url'=>fn()=>'/cart/','wc_get_checkout_url'=>fn()=>'/checkout/',
 'wp_nonce_field'=>fn()=>'','wp_create_nonce'=>fn()=>'n','wp_get_attachment_image_url'=>fn()=>'','get_term_meta'=>fn()=>0,'wp_get_attachment_image'=>fn()=>'<img>',
 'get_terms'=>fn()=>[],'get_option'=>fn($k,$d=false)=>$d,'get_the_terms'=>fn()=>false,'wp_kses_post'=>fn($s)=>$s,'wp_kses'=>fn($s)=>$s,'is_front_page'=>fn()=>true,'is_home'=>fn()=>false,
 'wc_price'=>fn($n)=>number_format((float)$n).'원','get_woocommerce_currency_symbol'=>fn()=>'원','wc_get_product_terms'=>fn()=>[], 'wc_get_product_ids_on_sale'=>fn()=>[],  'trailingslashit'=>fn(...$a)=>'', 'wp_get_current_user'=>fn()=>new class { public $display_name='x'; public $ID=0; public function exists(){ return false; } public function __call($n,$a){ return ''; } }, 'get_term_by'=>fn()=>null, 'wp_date'=>fn($f,$t=null)=>date($f), 'current_time'=>fn($f)=>date('Y-m-d H:i:s'), 'wc_get_product_category_list'=>fn()=>'', 'get_the_title'=>fn()=>'', 'wp_strip_all_tags'=>fn($s)=>strip_tags($s), 'wp_trim_words'=>fn($s)=>$s, 'wc_get_stock_html'=>fn()=>'', 'get_woocommerce_currency'=>fn()=>'KRW', 'is_product_category'=>fn()=>false, 'is_search'=>fn()=>false, 'is_shop'=>fn()=>false, 'wp_json_encode'=>fn($v)=>json_encode($v), 'get_query_var'=>fn()=>'', 'wc_get_page_id'=>fn()=>1,
] as $f=>$fn) { if(!function_exists($f)) eval("function $f(...\$a){ return (\$GLOBALS['__fn']['$f'])(...\$a); }"); $GLOBALS['__fn'][$f]=$fn; }
ob_start();
try { include $argv[1] ?? '/home/user/new-website-build/templates/home.php'; }
catch (\Throwable $e) { $out = ob_get_clean(); echo substr($out, -400), "\n\n!! ", get_class($e), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n"; exit(1); }
$out = ob_get_clean();
echo "OK ", strlen($out), " bytes\n";
foreach (['class="hero"','class="stage"','class="nums"','bcard','class="rows"','dhr-about__c','sec--novo'] as $k) printf("%-16s %d\n", $k, substr_count($out,$k));
file_put_contents(__DIR__.'/home-render.html', $out);
