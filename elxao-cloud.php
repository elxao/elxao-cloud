<?php
/*
Plugin Name: ELXAO Cloud Automation
Description: Auto-creates Project posts on paid orders (per line item) and provisions Nextcloud folders. Secure REST gateway (list/download/upload/mkdir/rename/move/delete). Minimal embedded **Simple Viewer** (no toolbar). No public links, no Nextcloud UI, no emails, no chat messages.
Version: 1.24.0
Author: ELXAO
*/

if (!defined('ABSPATH')) exit;

/* ===========================================================
   CONFIG (uses your existing wp-config.php constants)
   Required (already in your wp-config):
     ELXAO_NC_USER, ELXAO_NC_PASS, ELXAO_NC_BASE, ELXAO_NC_OCS_BASE, ELXAO_NC_TIMEOUT
   Optional (add only if you want):
     ELXAO_CLOUD_DEBUG (bool), ELXAO_CLOUD_HMAC_SECRET (string),
     ELXAO_CLOUD_MAX_UPLOAD_MB (int), ELXAO_CLOUD_ALLOWED_MIME (csv string),
     ELXAO_CLOUD_BLOCK_EXT (csv string), ELXAO_CLOUD_RATE_WINDOW_SEC (int),
     ELXAO_CLOUD_RATE_MAX_REQ (int), ELXAO_CLOUD_STREAM_CHUNK (int),
     ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER (string)
   =========================================================== */

if (!defined('ELXAO_CLOUD_DEBUG')) define('ELXAO_CLOUD_DEBUG', false);
if (!defined('ELXAO_CLOUD_STREAM_CHUNK')) define('ELXAO_CLOUD_STREAM_CHUNK', 8192);
if (!defined('ELXAO_CLOUD_MAX_UPLOAD_MB')) define('ELXAO_CLOUD_MAX_UPLOAD_MB', 128);
if (!defined('ELXAO_CLOUD_ALLOWED_MIME')) define('ELXAO_CLOUD_ALLOWED_MIME', 'application/pdf,image/png,image/jpeg,image/webp,application/zip,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/msword,text/plain');
if (!defined('ELXAO_CLOUD_BLOCK_EXT')) define('ELXAO_CLOUD_BLOCK_EXT', 'php,php3,php4,php5,phtml,js,exe,sh,bat,cmd,com');
if (!defined('ELXAO_CLOUD_RATE_WINDOW_SEC')) define('ELXAO_CLOUD_RATE_WINDOW_SEC', 60);
if (!defined('ELXAO_CLOUD_RATE_MAX_REQ')) define('ELXAO_CLOUD_RATE_MAX_REQ', 60);
if (!defined('ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER')) define('ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER', 'Uploads');

if (!function_exists('elxao_log')){
  function elxao_log($msg){ if(!ELXAO_CLOUD_DEBUG) return; error_log('[ELXAO Cloud] '.(is_scalar($msg)?$msg:wp_json_encode($msg))); }
}

/* ===========================================================
   ACF helpers
   =========================================================== */
function elxao_get_acf($name,$post_id){ return function_exists('get_field') ? get_field($name,$post_id) : get_post_meta($post_id,$name,true); }
function elxao_update_acf($name,$val,$post_id){
  if (function_exists('update_field')){
    $key = '';
    if (function_exists('get_field_object')){
      $fo = get_field_object($name,$post_id,false,false);
      if (is_array($fo) && !empty($fo['key'])) $key = $fo['key'];
    }
    update_field($key?:$name,$val,$post_id);
  } else {
    update_post_meta($post_id,$name,$val);
  }
}

/* ===========================================================
   Utils / roles
   =========================================================== */
function elxao_slug($s){
  $s = remove_accents((string)$s);
  $s = preg_replace('~[^\pL\d]+~u','-',$s);
  $s = trim($s,'-');
  $s = preg_replace('~[^-\w]+~','',$s);
  $s = strtolower($s);
  return $s ?: 'n-a';
}
function elxao_is_admin(){ return current_user_can('manage_options'); }
function elxao_current_user_id(){ return get_current_user_id() ?: 0; }
function elxao_project_participants($project_id){
  return [
    'client'=> (int) ( elxao_get_acf('client_user',$project_id) ?: 0 ),
    'pm'    => (int) ( elxao_get_acf('pm_user',$project_id) ?: 0 ),
  ];
}
function elxao_user_role_for_project($project_id, $user_id){
  if (!$user_id) return 'guest';
  if (user_can($user_id,'manage_options')) return 'admin';
  $p = elxao_project_participants($project_id);
  if ($user_id === (int)$p['pm']) return 'pm';
  if ($user_id === (int)$p['client']) return 'client';
  return 'none';
}

/* ===========================================================
   Nextcloud DAV helpers
   ELXAO_NC_BASE should be like: https://cloud.elxao.com/remote.php/dav/files/itselxao/
   =========================================================== */
function elxao_nc_url($relative){ return rtrim(ELXAO_NC_BASE,'/').'/'.ltrim($relative,'/'); }

function elxao_nc_request($method, $relative, $headers=[], $body=null, $extra=[]){
  $url = elxao_nc_url($relative);
  $ch  = curl_init($url);
  curl_setopt($ch, CURLOPT_USERPWD, ELXAO_NC_USER.':'.ELXAO_NC_PASS);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, (int)ELXAO_NC_TIMEOUT);
  $http_headers = array_merge(['Depth: 1'], $headers);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $http_headers);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  if (!empty($extra['read_cb'])){
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_READFUNCTION, $extra['read_cb']);
    if (isset($extra['infilesize'])) curl_setopt($ch, CURLOPT_INFILESIZE, (int)$extra['infilesize']);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
  }
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $hdrs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $raw_headers = substr($resp,0,$hdrs);
  $body_raw    = substr($resp,$hdrs);
  curl_close($ch);
  elxao_log(['nc_req'=>[$method,$relative],'code'=>$code]);
  return [$code,$raw_headers,$body_raw];
}
function elxao_nc_mkcol($relative){ [$code] = elxao_nc_request('MKCOL',$relative); return in_array($code,[201,405],true); }
function elxao_nc_propfind($relative){
  [$code,, $body] = elxao_nc_request('PROPFIND',$relative,['Content-Type: application/xml']);
  if ($code>=400) return new WP_Error('propfind_failed','Nextcloud PROPFIND failed',['status'=>$code]);
  return $body;
}
function elxao_nc_delete($relative){ [$code] = elxao_nc_request('DELETE',$relative); return $code>=200 && $code<300; }
function elxao_nc_move($from,$to){
  $dest = elxao_nc_url($to);
  [$code] = elxao_nc_request('MOVE',$from,['Destination: '.$dest,'Overwrite: T']);
  return $code>=200 && $code<300;
}

/* ===========================================================
   PROJECT CREATION (kept from your 1.14.0, chat removed)
   =========================================================== */

function elxao_subscription_cpts(){ return ['shop_subscription','subscription','fs_subscription','wpdesk_subscription','wc_subscription']; }
function elxao_relation_keys(){ return ['_order_id','_parent_order_id','_initial_order_id','_origin_order_id','order_id','_order_key']; }

function elxao_build_relation_meta_query( int $order_id, string $order_key ) : array {
  $keys = elxao_relation_keys(); $rel = [ 'relation' => 'OR' ];
  foreach ($keys as $k){
    if ($k === '_order_key'){ if ($order_key!=='') $rel[] = ['key'=>$k,'value'=>$order_key,'compare'=>'=']; continue; }
    $rel[] = ['key'=>$k,'value'=>$order_id,'compare'=>'='];
    $rel[] = ['key'=>$k,'value'=>'"'.$order_id.'"','compare'=>'LIKE'];
    $rel[] = ['key'=>$k,'value'=>'i:'.$order_id.';','compare'=>'LIKE'];
  }
  return $rel;
}
function elxao_is_subscription_item( WC_Order_Item_Product $item ) : bool {
  $product = $item->get_product(); if(!$product) return false;
  $product_id = $product->get_id();
  $parent_id  = method_exists($product,'get_parent_id') ? (int)$product->get_parent_id() : 0;
  $slug='sla-gmaas';
  $in_cat = static function($pid) use($slug){ return $pid ? has_term($slug,'product_cat',$pid) : false; };
  return $in_cat($product_id) || ($parent_id && $in_cat($parent_id));
}
function elxao_find_subscription_post_id_for_order( WC_Order $order ) : string {
  $order_id=(int)$order->get_id(); $customer=(int)$order->get_user_id(); $order_key=(string)$order->get_order_key();
  $cpts=elxao_subscription_cpts(); $rel=elxao_build_relation_meta_query($order_id,$order_key);
  $q1=new WP_Query(['post_type'=>$cpts,'post_status'=>'any','posts_per_page'=>1,'no_found_rows'=>true,'fields'=>'ids','meta_query'=>['relation'=>'AND',['key'=>'_customer_user','value'=>$customer,'compare'=>'='],$rel]]); if(!empty($q1->posts)) return (string)$q1->posts[0];
  $q2=new WP_Query(['post_type'=>$cpts,'post_status'=>'any','posts_per_page'=>1,'no_found_rows'=>true,'fields'=>'ids','meta_query'=>$rel]); if(!empty($q2->posts)) return (string)$q2->posts[0];
  $q3=new WP_Query(['post_type'=>$cpts,'post_status'=>'any','posts_per_page'=>1,'no_found_rows'=>true,'fields'=>'ids','post_parent'=>$order_id]); if(!empty($q3->posts)) return (string)$q3->posts[0];
  if(function_exists('wcs_get_subscriptions_for_order')){
    $subs=wcs_get_subscriptions_for_order($order,['order_type'=>'any']); if(!empty($subs)){ $first=reset($subs); if($first && is_object($first)){ if(method_exists($first,'get_id')) return (string)$first->get_id(); if(isset($first->id)) return (string)(int)$first->id; } }
  }
  foreach($order->get_items() as $item){ if(!($item instanceof WC_Order_Item_Product)) continue; foreach(['_subscription_id','subscription_id','_fs_subscription_id','fs_subscription_id'] as $k){ $v=$item->get_meta($k,true); if($v) return (string)$v; } }
  return '';
}

/* Create projects from order (processing/completed) */
add_action('woocommerce_order_status_processing','elxao_create_projects_from_order',10,1);
add_action('woocommerce_order_status_completed','elxao_create_projects_from_order',10,1);
add_action('woocommerce_thankyou','elxao_backfill_on_thankyou',10,1);
add_action('woocommerce_checkout_subscription_created','elxao_on_checkout_subscription_created',20,2);

function elxao_create_projects_from_order($order_id){
  if (!function_exists('wc_get_order')) return;
  if (get_post_meta($order_id,'_elxao_projects_created',true)) return;

  $order = wc_get_order($order_id);
  if (!$order || (method_exists($order,'is_paid') && !$order->is_paid())) return;

  $client_id = (int)$order->get_user_id();
  $items = $order->get_items(); if (empty($items)) return;

  $prefill_sub_id = elxao_find_subscription_post_id_for_order($order); // may be ''

  foreach ($items as $item_id=>$item){
    if (!($item instanceof WC_Order_Item_Product)) continue;

    $product = $item->get_product();
    $product_name = $item->get_name();
    $qty = (int)$item->get_quantity();

    $is_sub_item = elxao_is_subscription_item($item);
    $project_type = $is_sub_item ? 'subscription' : 'one_shot';
    $subscription_id = $is_sub_item ? (string)$prefill_sub_id : '';

    $project_post_id = wp_insert_post([
      'post_title'=> sanitize_text_field($product_name),
      'post_type' => 'project',
      'post_status'=>'publish',
      'post_content'=>'',
    ]);
    if (is_wp_error($project_post_id)) continue;

    $now_mysql = current_time('mysql');
    $now_date  = current_time('Y-m-d');
    elxao_update_acf('order_id',          $order_id,        $project_post_id);
    elxao_update_acf('project_type',      $project_type,    $project_post_id);
    elxao_update_acf('subscription_id',   $subscription_id, $project_post_id);
    elxao_update_acf('parent_project',    '',               $project_post_id);
    elxao_update_acf('project_id',        $project_post_id, $project_post_id);
    elxao_update_acf('creation_date',     $now_date,        $project_post_id);
    elxao_update_acf('project_name',      $product_name,    $project_post_id);
    elxao_update_acf('units_purchased',   $qty,             $project_post_id);
    elxao_update_acf('client_user',       $client_id,       $project_post_id);
    elxao_update_acf('pm_user',           '',               $project_post_id);
    elxao_update_acf('status',            'new',            $project_post_id);
    elxao_update_acf('summary',           '',               $project_post_id);
    elxao_update_acf('progress',          0,                $project_post_id);
    elxao_update_acf('deadline',          '',               $project_post_id);
    elxao_update_acf('cloud_folder_id',   '',               $project_post_id); // set after provisioning
    elxao_update_acf('action_required',   0,                $project_post_id);
    elxao_update_acf('action_type',       '',               $project_post_id);
    elxao_update_acf('action_message',    '',               $project_post_id);
    elxao_update_acf('latest_message_at', $now_mysql,       $project_post_id);

    // Provision Nextcloud folder tree (hook below will do it and save INTERNAL path)
    do_action('elxao_drive_create_folder',$project_post_id,$client_id);
  }
  update_post_meta($order_id,'_elxao_projects_created',1);
}

/* Backfill after thankyou */
function elxao_backfill_on_thankyou($order_id){
  $order = wc_get_order($order_id); if(!$order) return;
  $sub_id = elxao_find_subscription_post_id_for_order($order);
  if ($sub_id) elxao_assign_subscription_to_order_projects((int)$order_id,(int)$sub_id);
}

/* On subscription creation during checkout */
function elxao_on_checkout_subscription_created($subscription, $order){
  if (!$order instanceof WC_Order){
    $order_id = is_object($order) && method_exists($order,'get_id') ? (int)$order->get_id() : (int)$order;
    $order = $order_id ? wc_get_order($order_id) : null;
  }
  if (!$order instanceof WC_Order) return;

  $subscription_id = 0;
  if (is_object($subscription)){
    if (method_exists($subscription,'get_id')) $subscription_id = (int)$subscription->get_id();
    elseif (isset($subscription->id)) $subscription_id = (int)$subscription->id;
    if (!$subscription_id && method_exists($subscription,'get_parent_id')){
      $maybe=(int)$subscription->get_parent_id(); if (!$order->get_id() && $maybe) $order = wc_get_order($maybe);
    }
  } else $subscription_id = (int)$subscription;
  if (!$subscription_id) return;

  elxao_assign_subscription_to_order_projects((int)$order->get_id(), $subscription_id);
}

/* Deterministic backfill targets */
function elxao_assign_subscription_to_order_projects($origin_order_id, $subscription_post_id){
  $q = new WP_Query([
    'post_type'=>'project','post_status'=>'any','posts_per_page'=>200,'no_found_rows'=>true,'fields'=>'ids',
    'meta_query'=>[['key'=>'order_id','value'=>$origin_order_id,'compare'=>'=']],
    'update_post_meta_cache'=>false,'update_post_term_cache'=>false,'ignore_sticky_posts'=>true,
  ]);
  if (empty($q->posts)) return;
  foreach ($q->posts as $pid){
    $is_sub = (int)get_post_meta($pid,'_elxao_is_subscription_item',true) === 1;
    if (!$is_sub) continue;
    $current = (string)( function_exists('get_field') ? get_field('subscription_id',$pid) : get_post_meta($pid,'subscription_id',true) );
    if ($current==='') elxao_update_acf('subscription_id', (string)$subscription_post_id, $pid);
    $ptype = (string)( function_exists('get_field') ? get_field('project_type',$pid) : get_post_meta($pid,'project_type',true) );
    if ($ptype!=='subscription') elxao_update_acf('project_type','subscription',$pid);
  }
}

/* Admin: add sortable ID column in Projects */
add_filter('manage_project_posts_columns', function($cols){
  $new=[]; foreach($cols as $k=>$v){ $new[$k]=$v; if($k==='title') $new['elxao_id']='ID'; } return $new;
});
add_action('manage_project_posts_custom_column', function($col,$post_id){ if($col==='elxao_id') echo (int)$post_id; },10,2);
add_filter('manage_edit-project_sortable_columns', function($cols){ $cols['elxao_id']='elxao_id'; return $cols; });
add_action('pre_get_posts', function($q){ if(!is_admin()||!$q->is_main_query())return; if($q->get('post_type')!=='project')return; if($q->get('orderby')==='elxao_id') $q->set('orderby','ID'); });

/* ===========================================================
   NEXTCLOUD PROVISIONING (creates tree + saves INTERNAL path)
   =========================================================== */

add_action('elxao_drive_create_folder', function($project_id, $client_user_id){
  $client_slug = 'client-unknown';
  if ($client_user_id){
    $u=get_userdata((int)$client_user_id);
    if($u){ $disp = $u->display_name ?: $u->user_nicename ?: $u->user_login; $client_slug = elxao_slug($disp); }
  }
  $ref   = (string)( elxao_get_acf('project_id',$project_id) ?: $project_id );
  $pname = (string)( elxao_get_acf('project_name',$project_id) ?: get_the_title($project_id) );
  $pslug = elxao_slug($pname ?: ('project-'.$project_id));
  $base  = '/ELXAO/'.$client_slug.'/'.$ref.'_'.$pslug;

  // Create path progressively
  $segments = explode('/', trim($base,'/'));
  $acc=''; foreach($segments as $seg){ $acc.=($acc?'/':'').$seg; if(!elxao_nc_mkcol($acc)){ elxao_log('MKCOL fail '.$acc); return; } }
  foreach(['Uploads','Planning','Deliverables','Reports'] as $sub){ elxao_nc_mkcol(trim($base,'/').'/'.$sub); }

  // Save INTERNAL Nextcloud path (NOT a public link)
  elxao_update_acf('cloud_folder_id', $base, $project_id);
  elxao_log(['provisioned'=>$project_id,'path'=>$base]);
}, 10, 2);

/* ===========================================================
   REST SECURITY (rate limit + optional HMAC)
   =========================================================== */
function elxao_cloud_check_rate(){
  $uid = elxao_current_user_id(); if(!$uid) return;
  $win=(int)ELXAO_CLOUD_RATE_WINDOW_SEC; $max=(int)ELXAO_CLOUD_RATE_MAX_REQ;
  $key='elxao_cloud_rate_'.$uid; $data=get_transient($key); $now=time();
  if(!$data) $data=['t'=>$now,'c'=>0]; if(($now-$data['t'])>$win) $data=['t'=>$now,'c'=>0];
  $data['c']++; set_transient($key,$data,$win); if($data['c']>$max) wp_send_json_error(['message'=>'Rate limit exceeded'],429);
}
function elxao_cloud_verify_hmac(){
  if (!defined('ELXAO_CLOUD_HMAC_SECRET') || !ELXAO_CLOUD_HMAC_SECRET) return; // optional
  $ts  = isset($_GET['ts']) ? (int)$_GET['ts'] : 0;
  $sig = isset($_GET['sig']) ? (string)$_GET['sig'] : '';
  if(!$ts||!$sig) wp_send_json_error(['message'=>'Missing signature'],403);
  if(abs(time()-$ts)>120) wp_send_json_error(['message'=>'Signature expired'],403);
  $user = elxao_current_user_id();
  $uri  = $_SERVER['REQUEST_URI'];
  $host = parse_url(home_url('/'), PHP_URL_HOST);
  $calc = hash_hmac('sha256', $user.'|'.$ts+'|'.$uri+'|'.$host, ELXAO_CLOUD_HMAC_SECRET);
  if(!hash_equals($calc,$sig)) wp_send_json_error(['message'=>'Invalid signature'],403);
}

/* ===========================================================
   REST: endpoints (list/download/upload/mkdir/rename/move/delete)
   =========================================================== */
add_action('rest_api_init', function(){
  register_rest_route('elxao/v1','/cloud/list',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>'elxao_api_cloud_list']);
  register_rest_route('elxao/v1','/cloud/download',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>'elxao_api_cloud_download']);
  register_rest_route('elxao/v1','/cloud/upload',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>'elxao_api_cloud_upload']);
  register_rest_route('elxao/v1','/cloud/mkdir',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>'elxao_api_cloud_mkdir']);
  register_rest_route('elxao/v1','/cloud/rename',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>'elxao_api_cloud_rename']);
  register_rest_route('elxao/v1','/cloud/move',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>'elxao_api_cloud_move']);
  register_rest_route('elxao/v1','/cloud/delete',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>'elxao_api_cloud_delete']);
  // Admin maintenance
  register_rest_route('elxao/v1','/cloud-delete',['methods'=>'POST','permission_callback'=>function(){return current_user_can('manage_options');},'callback'=>'elxao_api_project_delete']);
  register_rest_route('elxao/v1','/cloud-rename',['methods'=>'POST','permission_callback'=>function(){return current_user_can('manage_options');},'callback'=>'elxao_api_project_rename']);
  register_rest_route('elxao/v1','/cloud-rebuild',['methods'=>'POST','permission_callback'=>function(){return current_user_can('manage_options');},'callback'=>'elxao_api_project_rebuild']);
});

function elxao_project_basepath($project_id){
  $stored = (string) elxao_get_acf('cloud_folder_id',$project_id);
  if ($stored && str_starts_with($stored,'/ELXAO/')) return $stored;
  // Fallback (shouldn’t happen once provisioned)
  $client_id = (int) elxao_get_acf('client_user',$project_id);
  $client_slug = 'client-unknown';
  if ($client_id){ $u=get_userdata($client_id); if($u){ $disp=$u->display_name?:$u->user_nicename?:$u->user_login; $client_slug=elxao_slug($disp);} }
  $ref   = (string)( elxao_get_acf('project_id',$project_id) ?: $project_id );
  $pname = (string)( elxao_get_acf('project_name',$project_id) ?: get_the_title($project_id) );
  $pslug = elxao_slug($pname ?: ('project-'.$project_id));
  return '/ELXAO/'.$client_slug.'/'.$ref.'_'.$pslug;
}
function elxao_sanitize_relpath($p){ $p=ltrim((string)$p,'/'); if(str_contains($p,'..')) return ''; return $p; }
function elxao_guard_and_paths($request,$need_write=false,$must_be_upload_for_client=false){
  elxao_cloud_check_rate(); elxao_cloud_verify_hmac();
  if(!is_user_logged_in()) wp_send_json_error(['message'=>'Auth required'],401);
  $project_id=(int)$request->get_param('project_id'); if(!$project_id) wp_send_json_error(['message'=>'Missing project_id'],400);
  $role = elxao_user_role_for_project($project_id, elxao_current_user_id());
  if($role==='none'||$role==='guest') wp_send_json_error(['message'=>'Forbidden'],403);
  if($need_write && $role==='client' && !$must_be_upload_for_client) wp_send_json_error(['message'=>'Clients cannot write here'],403);
  $base = elxao_project_basepath($project_id);
  $sub  = (string)$request->get_param('path'); $sub = $sub ? elxao_sanitize_relpath($sub) : '';
  $full = trim($base,'/').($sub?'/'.$sub:'');
  return [$role,$base,$sub,$full,$project_id];
}

/* LIST */
function elxao_api_cloud_list($request){
  [$role,$base,$sub,$full] = elxao_guard_and_paths($request,false,false);
  $resp = elxao_nc_propfind($full);
  if (is_wp_error($resp)) wp_send_json_error(['message'=>$resp->get_error_message()],500);
  $xml = @simplexml_load_string($resp); $out=[];
  if($xml && isset($xml->response)){
    foreach($xml->response as $node){
      $href=(string)$node->href; $is_dir=false; $size=0; $mtime='';
      if(isset($node->propstat->prop->resourcetype->collection)) $is_dir=true;
      if(isset($node->propstat->prop->getcontentlength)) $size=(int)$node->propstat->prop->getcontentlength;
      if(isset($node->propstat->prop->getlastmodified)) $mtime=(string)$node->propstat->prop->getlastmodified;
      $decoded=rawurldecode($href);
      $rel=preg_replace('#^.*/remote\.php/dav/files/[^/]+/#','',$decoded);
      if(rtrim($rel,'/')===rtrim($full,'/')) continue;
      $name=basename(rtrim($rel,'/'));
      $out[]=['name'=>$name,'type'=>$is_dir?'dir':'file','size'=>$size,'mtime'=>$mtime];
    }
  }
  return new WP_REST_Response(['ok'=>true,'base'=>$base,'path'=>$sub,'items'=>$out,'role'=>$role],200);
}

/* DOWNLOAD */
function elxao_api_cloud_download($request){
  [$role,$base,$sub,$full] = elxao_guard_and_paths($request,false,false);
  if(!$sub) wp_die('Missing file path','',400);
  $url = elxao_nc_url($full);
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_USERPWD, ELXAO_NC_USER.':'.ELXAO_NC_PASS);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST,'GET');
  curl_setopt($ch, CURLOPT_TIMEOUT,(int)ELXAO_NC_TIMEOUT);
  curl_setopt($ch, CURLOPT_HEADER,true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
  $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $hdr=curl_getinfo($ch,CURLINFO_HEADER_SIZE);
  $body=substr($resp,$hdr); curl_close($ch);
  if($code>=400) wp_die('File not found','',['response'=>$code]);
  $filename=basename($sub);
  header_remove(); nocache_headers();
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="'.rawurlencode($filename).'"');
  header('Content-Length: '.strlen($body));
  echo $body; exit;
}

/* UPLOAD (zero-retention stream) */
function elxao_api_cloud_upload($request){
  [$role,$base,$sub,$full,$project_id] = elxao_guard_and_paths($request,true,true);
  $uploads_root = trim($base,'/').'/'.ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER;
  if($role==='client' && !str_starts_with($full,$uploads_root)) wp_send_json_error(['message'=>'Client uploads limited to /Uploads'],403);
  $filename = sanitize_file_name((string)($_SERVER['HTTP_X_FILE_NAME'] ?? ''));
  if(!$filename) wp_send_json_error(['message'=>'Missing X-File-Name header'],400);
  if(elxao_is_blocked_ext($filename)) wp_send_json_error(['message'=>'File type not allowed'],415);
  $content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
  $max_bytes = (int)ELXAO_CLOUD_MAX_UPLOAD_MB*1024*1024;
  if($content_length && $content_length>$max_bytes) wp_send_json_error(['message'=>'File too large'],413);
  $target_rel = $full.'/'.$filename;
  $in = fopen('php://input','rb'); if(!$in) wp_send_json_error(['message'=>'Upload stream error'],500);
  $read_cb = function($ch,$fd,$len) use($in){ return fread($in,$len); };
  [$code] = elxao_nc_request('PUT',$target_rel,[],null,['read_cb'=>$read_cb,'infilesize'=>$content_length?:null]);
  fclose($in);
  if($code<200||$code>=300) wp_send_json_error(['message'=>'Upload failed','status'=>$code],500);
  return new WP_REST_Response(['ok'=>true,'path'=>$target_rel],200);
}
function elxao_ext($n){ $p=strrpos($n,'.'); return $p===false?'':strtolower(substr($n,$p+1)); }
function elxao_is_blocked_ext($n){ $blocked=array_map('trim',explode(',',ELXAO_CLOUD_BLOCK_EXT)); $e=elxao_ext($n); return $e && in_array($e,$blocked,true); }
function elxao_is_allowed_mime($m){ $allowed=array_map('trim',explode(',',ELXAO_CLOUD_ALLOWED_MIME)); return in_array($m,$allowed,true); }

/* MKDIR */
function elxao_api_cloud_mkdir($request){
  [$role,$base,$sub,$full] = elxao_guard_and_paths($request,true,false);
  if($role==='client') wp_send_json_error(['message'=>'Clients cannot create folders'],403);
  $name = sanitize_file_name((string)$request->get_param('name')); if(!$name) wp_send_json_error(['message'=>'Missing name'],400);
  $rel = rtrim($full,'/').'/'.$name; $ok = elxao_nc_mkcol($rel);
  return $ok ? new WP_REST_Response(['ok'=>true],200) : wp_send_json_error(['message'=>'MKCOL failed'],500);
}

/* RENAME */
function elxao_api_cloud_rename($request){
  [$role,$base,$sub,$full] = elxao_guard_and_paths($request,true,false);
  if($role==='client') wp_send_json_error(['message'=>'Clients cannot rename'],403);
  $new = sanitize_file_name((string)$request->get_param('new_name'));
  if(!$new || !$sub) wp_send_json_error(['message'=>'Missing current path or new_name'],400);
  $to = dirname($full).'/'.$new; $ok = elxao_nc_move($full,$to);
  return $ok ? new WP_REST_Response(['ok'=>true,'to'=>$to],200) : wp_send_json_error(['message'=>'Rename failed'],500);
}

/* MOVE */
function elxao_api_cloud_move($request){
  [$role,$base,$sub,$full] = elxao_guard_and_paths($request,true,false);
  if($role==='client') wp_send_json_error(['message'=>'Clients cannot move'],403);
  $to_rel = elxao_sanitize_relpath((string)$request->get_param('to')); if(!$to_rel) wp_send_json_error(['message'=>'Invalid destination'],400);
  $ok = elxao_nc_move($full,$to_rel);
  return $ok ? new WP_REST_Response(['ok'=>true,'to'=>$to_rel],200) : wp_send_json_error(['message'=>'Move failed'],500);
}

/* DELETE */
function elxao_api_cloud_delete($request){
  [$role,$base,$sub,$full] = elxao_guard_and_paths($request,true,false);
  if($role==='client') wp_send_json_error(['message'=>'Clients cannot delete'],403);
  if(!$sub) wp_send_json_error(['message'=>'Nothing to delete'],400);
  $ok = elxao_nc_delete($full);
  return $ok ? new WP_REST_Response(['ok'=>true],200) : wp_send_json_error(['message'=>'Delete failed'],500);
}

/* ===========================================================
   Admin maintenance: delete/rename/rebuild whole project tree
   =========================================================== */
function elxao_project_segments($project_id){ return explode('/',trim(elxao_project_basepath($project_id),'/')); }
function elxao_mkcol_recursive_segments($segments){ $acc=''; foreach($segments as $s){ $acc.=($acc?'/':'').$s; if(!elxao_nc_mkcol($acc)) return false; } return true; }

function elxao_api_project_rebuild($request){
  if(!is_user_logged_in()||!elxao_is_admin()) wp_send_json_error(['message'=>'Forbidden'],403);
  $project_id=(int)$request->get_param('project_id'); if(!$project_id) wp_send_json_error(['message'=>'project_id required'],400);
  $segs=elxao_project_segments($project_id);
  if(!elxao_mkcol_recursive_segments($segs)) wp_send_json_error(['message'=>'Recreate base failed'],500);
  foreach(['Uploads','Planning','Deliverables','Reports'] as $sub){ elxao_nc_mkcol(implode('/',array_merge($segs,[$sub]))); }
  $path='/'.implode('/',$segs); elxao_update_acf('cloud_folder_id',$path,$project_id);
  return new WP_REST_Response(['ok'=>true,'path'=>$path],200);
}
function elxao_api_project_delete($request){
  if(!is_user_logged_in()||!elxao_is_admin()) wp_send_json_error(['message'=>'Forbidden'],403);
  $project_id=(int)$request->get_param('project_id'); if(!$project_id) wp_send_json_error(['message'=>'project_id required'],400);
  $path=trim(elxao_project_basepath($project_id),'/'); $ok=elxao_nc_delete($path);
  return $ok ? new WP_REST_Response(['ok'=>true],200) : wp_send_json_error(['message'=>'Delete failed'],500);
}
function elxao_api_project_rename($request){
  if(!is_user_logged_in()||!elxao_is_admin()) wp_send_json_error(['message'=>'Forbidden'],403);
  $project_id=(int)$request->get_param('project_id'); $new_slug=elxao_slug((string)$request->get_param('new_slug'));
  if(!$project_id||!$new_slug) wp_send_json_error(['message'=>'project_id and new_slug required'],400);
  $base=elxao_project_basepath($project_id); $segs=explode('/',trim($base,'/')); array_pop($segs);
  $ref=(string)( elxao_get_acf('project_id',$project_id) ?: $project_id ); $new_last=$ref.'_'.$new_slug;
  $to=implode('/',array_merge($segs,[$new_last]));
  $ok=elxao_nc_move(trim($base,'/'),$to);
  if(!$ok) wp_send_json_error(['message'=>'Rename failed'],500);
  elxao_update_acf('project_name',$new_slug,$project_id);
  elxao_update_acf('cloud_folder_id','/'.$to,$project_id);
  return new WP_REST_Response(['ok'=>true,'path'=>'/'.$to],200);
}

/* ===========================================================
   Embedded UI (Simple Viewer): [elxao_cloud project_id="auto"]
   - Lands inside project root and shows ONLY the 4 subfolders
   - Inside "Uploads" shows an inline "Upload here" button (clients only)
   - No toolbar, no Up button; a tiny breadcrumb is clickable
   =========================================================== */
add_shortcode('elxao_cloud', function($atts){
  $a = shortcode_atts(['project_id'=>'auto'], $atts, 'elxao_cloud');
  $pid = $a['project_id']==='auto' ? (int)get_the_ID() : (int)$a['project_id'];
  if(!$pid) return '<div class="elxao-cloud-error">Missing project_id</div>';
  if(!is_user_logged_in()) return '<div class="elxao-cloud-error">Login required</div>';
  $uid=elxao_current_user_id(); $role=elxao_user_role_for_project($pid,$uid);
  if($role==='none'||$role==='guest') return '<div class="elxao-cloud-error">Access denied</div>';

  wp_enqueue_script('elxao-cloud-simple-js', plugins_url('elxao-cloud-simple.js', __FILE__), [], '1.24.0', true);
  wp_enqueue_style('elxao-cloud-simple-css', plugins_url('elxao-cloud-simple.css', __FILE__), [], '1.24.0');

  $ts=time(); $sig='';
  if(defined('ELXAO_CLOUD_HMAC_SECRET') && ELXAO_CLOUD_HMAC_SECRET){
    $uri='/wp-json/elxao/v1'; $host=parse_url(home_url('/'),PHP_URL_HOST);
    $sig=hash_hmac('sha256', $uid.'|'.$ts+'|'.$uri+'|'.$host, ELXAO_CLOUD_HMAC_SECRET);
  }
  wp_localize_script('elxao-cloud-simple-js','ELXAO_CLOUD',[
    'projectId'=>$pid,'role'=>$role,
    'restBase'=>esc_url_raw(get_rest_url(null,'elxao/v1')),
    'nonce'=>wp_create_nonce('wp_rest'),'ts'=>$ts,'sig'=>$sig,
    'uploadsSub'=>ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER,'chunk'=>(int)ELXAO_CLOUD_STREAM_CHUNK
  ]);

  ob_start(); ?>
  <div class="elxao-sv" data-role="<?php echo esc_attr($role); ?>">
    <div class="sv-breadcrumb" aria-label="breadcrumb"></div>
    <div class="sv-grid" aria-live="polite"></div>
    <div class="sv-upload" style="display:none;">
      <input type="file" class="sv-file"/>
      <button class="sv-upload-btn" type="button">Upload here</button>
      <div class="sv-hint">Clients can upload only inside “<?php echo esc_html(ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER); ?>”.</div>
    </div>
  </div>
  <?php return ob_get_clean();
});

/* Inline JS/CSS (Simple Viewer) so it works immediately */
add_action('wp_enqueue_scripts', function(){
  if (wp_script_is('elxao-cloud-simple-js','registered')) return;
  wp_register_script('elxao-cloud-simple-js','',[], '1.24.0', true);
  wp_add_inline_script('elxao-cloud-simple-js', <<<JS
(function(){
  function api(base,path,params){
    const u=new URL(base+'/'+path.replace(/^\\/+/,''));
    if(params){Object.entries(params).forEach(([k,v])=>u.searchParams.set(k,v));}
    if(window.ELXAO_CLOUD && ELXAO_CLOUD.sig){u.searchParams.set('ts',ELXAO_CLOUD.ts);u.searchParams.set('sig',ELXAO_CLOUD.sig);}
    return u.toString();
  }
  function restHeaders(){return {'X-WP-Nonce':(window.ELXAO_CLOUD?ELXAO_CLOUD.nonce:'')}}
  function segs(p){return (p?p.split('/') : []).filter(Boolean)}
  let currentPath='';

  function renderCrumbs(root, path){
    const el=root.querySelector('.sv-breadcrumb'); if(!el) return;
    const parts=segs(path); let acc=''; const out=['<span class="sv-crumb" data-p="">Project</span>'];
    parts.forEach((p,i)=>{acc = i===0?p:acc+'/'+p; out.push('<span class="sv-sep">›</span><span class="sv-crumb'+(i===parts.length-1?' active':'')+'" data-p="'+acc+'">'+p+'</span>');});
    el.innerHTML=out.join('');
    el.querySelectorAll('.sv-crumb').forEach(c=>{c.onclick=()=>{currentPath=c.getAttribute('data-p')||''; load(root);};});
  }

  function renderGrid(root, items){
    const grid=root.querySelector('.sv-grid'); grid.innerHTML='';
    items.sort((a,b)=>{ if(a.type!==b.type) return a.type==='dir'?-1:1; return a.name.localeCompare(b.name); });
    items.forEach(it=>{
      const card=document.createElement('button'); card.type='button'; card.className='sv-card '+(it.type==='dir'?'dir':'file');
      card.innerHTML='<div class="sv-ico"></div><div class="sv-name">'+it.name+'</div>';
      if(it.type==='dir'){
        card.onclick=()=>{ currentPath = (currentPath?currentPath+'/':'') + it.name; load(root); };
      }else{
        card.onclick=()=>{ const url=api(ELXAO_CLOUD.restBase,'cloud/download',{project_id:ELXAO_CLOUD.projectId,path:(currentPath?currentPath+'/':'')+it.name}); window.open(url,'_blank'); };
      }
      grid.appendChild(card);
    });
  }

  function toggleUpload(root, path){
    const wrap=root.querySelector('.sv-upload'); if(!wrap) return;
    if(ELXAO_CLOUD.role!=='client'){ wrap.style.display = path? 'block':'none'; return; }
    const sub=ELXAO_CLOUD.uploadsSub; const allowed = (path===sub) || (path && path.startsWith(sub+'/'));
    wrap.style.display = allowed ? 'block' : 'none';
  }

  function attachUpload(root){
    const btn=root.querySelector('.sv-upload-btn'); const inp=root.querySelector('.sv-file');
    if(!btn||!inp) return; let busy=false;
    btn.onclick=()=>{ if(busy) return; inp.click(); };
    inp.onchange=()=>{ const f=inp.files[0]; if(!f) return; busy=true; btn.disabled=true; btn.textContent='Uploading...';
      const url=api(ELXAO_CLOUD.restBase,'cloud/upload',{project_id:ELXAO_CLOUD.projectId,path:currentPath});
      fetch(url,{method:'POST',headers:Object.assign({'Content-Type':'application/octet-stream','X-File-Name':f.name},restHeaders()),body:f})
        .then(r=>r.json()).then(j=>{ if(!j||!j.ok) throw new Error(j&&j.message||'Upload failed'); })
        .catch(err=>alert(err.message||'Upload error'))
        .finally(()=>{ busy=false; btn.disabled=false; btn.textContent='Upload here'; inp.value=''; load(root); });
    };
  }

  function load(root){
    const url=api(ELXAO_CLOUD.restBase,'cloud/list',{project_id:ELXAO_CLOUD.projectId,path:currentPath});
    fetch(url,{headers:restHeaders()}).then(r=>r.json()).then(j=>{
      if(!j||!j.ok) throw new Error(j&&j.message||'Load failed');
      renderCrumbs(root, j.path||'');
      renderGrid(root, j.items||[]);
      toggleUpload(root, j.path||'');
    }).catch(err=>{ alert(err.message||'Load failed'); });
  }

  function init(){ document.querySelectorAll('.elxao-sv').forEach(root=>{ attachUpload(root); load(root); }); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
JS);
  wp_register_style('elxao-cloud-simple-css', false, [], '1.24.0');
  wp_add_inline_style('elxao-cloud-simple-css', <<<CSS
.elxao-sv{border:1px solid #eee;border-radius:14px;padding:14px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif}
.sv-breadcrumb{font-size:12px;color:#666;margin-bottom:10px}
.sv-crumb{cursor:pointer;user-select:none}
.sv-crumb.active{font-weight:600;color:#111}
.sv-sep{padding:0 6px;color:#aaa}
.sv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px}
.sv-card{display:flex;flex-direction:column;align-items:center;gap:8px;padding:16px;border:1px solid #eee;border-radius:12px;background:#fff;cursor:pointer}
.sv-card:hover{box-shadow:0 2px 10px rgba(0,0,0,.06)}
.sv-ico{width:34px;height:34px}
.sv-card.dir .sv-ico{background:currentColor;mask:url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M10 4l2 2h8a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2h6z\"/></svg>') no-repeat 50% 50%/contain;color:#111}
.sv-card.file .sv-ico{background:currentColor;mask:url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M6 2h7l5 5v13a2 2 0 01-2 2H6a2 2 0 01-2-2V4a2 2 0 012-2z\"/></svg>') no-repeat 50% 50%/contain;color:#555}
.sv-name{font-size:13px;color:#111;text-align:center;word-break:break-word}
.sv-upload{margin-top:14px;border-top:1px dashed #eee;padding-top:12px}
.sv-upload-btn{padding:8px 12px;border:1px solid #ddd;border-radius:10px;background:#fafafa;cursor:pointer}
.sv-hint{font-size:12px;color:#777;margin-top:6px}
CSS);
});
