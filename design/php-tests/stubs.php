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
function wc_get_orders($args){ return $GLOBALS['__orders']; }
