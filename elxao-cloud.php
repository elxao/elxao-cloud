<?php
/*
Plugin Name: ELXAO Cloud Automation
Description: Auto-creates Project posts on paid orders and provisions Nextcloud folders. Secure REST (list/download/upload/mkdir/rename/move/delete). Embedded Explorer UI (toolbar, breadcrumbs, grid/list) with client-restricted uploads to /Uploads. No public links, no Nextcloud UI, no emails, no chat.
Version: 1.25.3
Author: ELXAO
*/

// Bail if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/*
 * ===========================================================
 * Compatibility polyfills
 *
 * The core plugin code makes use of str_contains(), str_starts_with()
 * and str_ends_with() which are only available in PHP 8+. While
 * recent WordPress versions bundle polyfills for these functions,
 * older PHP setups may not. To ensure maximum compatibility,
 * define these helpers if they are not already present.
 */
if (!function_exists('str_contains')) {
    /**
     * Polyfill for PHP 8's str_contains().
     *
     * @param string $haystack The string to search in.
     * @param string $needle   The substring to search for.
     * @return bool True if $needle is found in $haystack, false otherwise.
     */
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    /**
     * Polyfill for PHP 8's str_starts_with().
     *
     * @param string $haystack The string to search in.
     * @param string $needle   The prefix to check for.
     * @return bool True if $haystack begins with $needle, false otherwise.
     */
    function str_starts_with($haystack, $needle)
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    /**
     * Polyfill for PHP 8's str_ends_with().
     *
     * @param string $haystack The string to search in.
     * @param string $needle   The suffix to check for.
     * @return bool True if $haystack ends with $needle, false otherwise.
     */
    function str_ends_with($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }
        $length = strlen($needle);
        return substr($haystack, -$length) === $needle;
    }
}

/*
 * ===========================================================
 * CONFIG (required in wp-config.php)
 * -----------------------------------------------------------
 * ELXAO_NC_USER, ELXAO_NC_PASS, ELXAO_NC_BASE, ELXAO_NC_TIMEOUT
 * Optional:
 * ELXAO_CLOUD_DEBUG (bool)
 * ELXAO_CLOUD_HMAC_SECRET (string)
 * ELXAO_CLOUD_MAX_UPLOAD_MB (int)
 * ELXAO_CLOUD_ALLOWED_MIME (csv)
 * ELXAO_CLOUD_BLOCK_EXT (csv)
 * ELXAO_CLOUD_RATE_WINDOW_SEC (int)
 * ELXAO_CLOUD_RATE_MAX_REQ (int)
 * ELXAO_CLOUD_STREAM_CHUNK (int)
 * ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER (string)
 * =========================================================== */
if (!defined('ELXAO_CLOUD_DEBUG')) {
    define('ELXAO_CLOUD_DEBUG', false);
}
if (!defined('ELXAO_CLOUD_STREAM_CHUNK')) {
    define('ELXAO_CLOUD_STREAM_CHUNK', 8192);
}
if (!defined('ELXAO_CLOUD_MAX_UPLOAD_MB')) {
    define('ELXAO_CLOUD_MAX_UPLOAD_MB', 128);
}
if (!defined('ELXAO_CLOUD_ALLOWED_MIME')) {
    define('ELXAO_CLOUD_ALLOWED_MIME', 'application/pdf,image/png,image/jpeg,image/webp,application/zip,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/msword,text/plain');
}
if (!defined('ELXAO_CLOUD_BLOCK_EXT')) {
    define('ELXAO_CLOUD_BLOCK_EXT', 'php,php3,php4,php5,phtml,js,exe,sh,bat,cmd,com');
}
if (!defined('ELXAO_CLOUD_RATE_WINDOW_SEC')) {
    define('ELXAO_CLOUD_RATE_WINDOW_SEC', 60);
}
if (!defined('ELXAO_CLOUD_RATE_MAX_REQ')) {
    define('ELXAO_CLOUD_RATE_MAX_REQ', 60);
}
if (!defined('ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER')) {
    define('ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER', 'Uploads');
}

/**
 * Internal logger used when ELXAO_CLOUD_DEBUG is true.
 * All messages are prefaced with "[ELXAO Cloud]" and encoded as JSON when necessary.
 *
 * @param mixed $msg Message or data structure to log.
 * @return void
 */
if (!function_exists('elxao_log')) {
    function elxao_log($msg)
    {
        if (!ELXAO_CLOUD_DEBUG) {
            return;
        }
        error_log('[ELXAO Cloud] ' . (is_scalar($msg) ? $msg : wp_json_encode($msg)));
    }
}

/*
 * ===========================================================
 * ACF helpers
 * =========================================================== */
function elxao_get_acf($name, $post_id)
{
    return function_exists('get_field') ? get_field($name, $post_id) : get_post_meta($post_id, $name, true);
}

function elxao_update_acf($name, $val, $post_id)
{
    if (function_exists('update_field')) {
        $key = '';
        if (function_exists('get_field_object')) {
            $fo = get_field_object($name, $post_id, false, false);
            if (is_array($fo) && !empty($fo['key'])) {
                $key = $fo['key'];
            }
        }
        update_field($key ?: $name, $val, $post_id);
    } else {
        update_post_meta($post_id, $name, $val);
    }
}

/*
 * ===========================================================
 * Utils / roles
 * =========================================================== */
function elxao_slug($s)
{
    $s = remove_accents((string)$s);
    // Replace non letter/digit characters with hyphen
    $s = preg_replace('~[^\pL\d]+~u', '-', $s);
    // Trim off leading/trailing hyphens
    $s = trim($s, '-');
    // Remove unwanted characters
    $s = preg_replace('~[^-\w]+~', '', $s);
    $s = strtolower($s);
    return $s ?: 'n-a';
}

function elxao_is_admin()
{
    return current_user_can('manage_options');
}

function elxao_current_user_id()
{
    return get_current_user_id() ?: 0;
}

/**
 * Normalize a user reference coming from ACF/meta into a plain user ID.
 * Handles numeric IDs, strings like "user_123", WP_User objects and
 * associative/linear arrays that may wrap the user data.
 *
 * @param mixed $raw Raw value from storage/UI.
 * @return int User ID or 0 when it cannot be resolved.
 */
function elxao_normalize_user_id($raw)
{
    if (!$raw) {
        return 0;
    }
    if (is_numeric($raw)) {
        return (int)$raw;
    }
    if ($raw instanceof WP_User) {
        return (int)$raw->ID;
    }
    if (is_object($raw)) {
        if (isset($raw->ID)) {
            return (int)$raw->ID;
        }
        $raw = (array)$raw;
    }
    if (is_array($raw)) {
        if (isset($raw['ID'])) {
            return (int)$raw['ID'];
        }
        if (isset($raw['id'])) {
            return (int)$raw['id'];
        }
        if (isset($raw['user'])) {
            return elxao_normalize_user_id($raw['user']);
        }
        $first = reset($raw);
        if ($first !== false && $first !== null) {
            return elxao_normalize_user_id($first);
        }
        return 0;
    }
    if (is_string($raw) && preg_match('/(\d+)/', $raw, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function elxao_project_participants($project_id)
{
    return [
        'client' => elxao_normalize_user_id(elxao_get_acf('client_user', $project_id)),
        'pm'     => elxao_normalize_user_id(elxao_get_acf('pm_user', $project_id)),
    ];
}

function elxao_user_role_for_project($project_id, $user_id)
{
    if (!$user_id) {
        return 'guest';
    }
    if (user_can($user_id, 'manage_options')) {
        return 'admin';
    }
    $p = elxao_project_participants($project_id);
    if ($user_id === (int)$p['pm']) {
        return 'pm';
    }
    if ($user_id === (int)$p['client']) {
        return 'client';
    }
    return 'none';
}

/*
 * ===========================================================
 * Nextcloud DAV helpers
 * ELXAO_NC_BASE example: https://cloud.elxao.com/remote.php/dav/files/itselxao/
 * =========================================================== */
function elxao_nc_url($relative)
{
    $base = rtrim(ELXAO_NC_BASE, '/');
    $rel  = ltrim((string)$relative, '/');
    if ($rel === '') {
        return $base . '/';
    }
    $segments = explode('/', $rel);
    $encoded  = array_map(static function ($segment) {
        return rawurlencode((string)$segment);
    }, $segments);
    return $base . '/' . implode('/', $encoded);
}

/**
 * Generalised DAV request helper. Handles authentication, custom methods
 * and optional streaming. Returns array with HTTP status code, raw headers
 * and body.
 *
 * @param string $method    HTTP method (GET, PROPFIND, DELETE, PUT, MOVE, MKCOL)
 * @param string $relative  Relative path under ELXAO_NC_BASE
 * @param array  $headers   Extra headers to send
 * @param mixed  $body      Body content for non-streaming requests
 * @param array  $extra     Extra curl options: read_cb, infilesize
 * @return array            [int $statusCode, string $rawHeaders, string $body]
 */
function elxao_nc_request($method, $relative, $headers = [], $body = null, $extra = [])
{
    $url = elxao_nc_url($relative);
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_USERPWD, ELXAO_NC_USER . ':' . ELXAO_NC_PASS);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)ELXAO_NC_TIMEOUT);
    // Always send Depth header, but allow overrides
    $http_headers = array_merge(['Depth: 1'], $headers);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $http_headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    // When streaming, set up upload handler
    if (!empty($extra['read_cb'])) {
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_READFUNCTION, $extra['read_cb']);
        if (isset($extra['infilesize'])) {
            curl_setopt($ch, CURLOPT_INFILESIZE, (int)$extra['infilesize']);
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $raw_headers = substr($resp, 0, $hdrs);
    $body_raw    = substr($resp, $hdrs);
    curl_close($ch);
    elxao_log(['nc_req' => [$method, $relative], 'code' => $code]);
    return [$code, $raw_headers, $body_raw];
}

/**
 * Create a directory if it doesn't already exist. Returns true when
 * successful (status 201) or when the directory already exists (405).
 *
 * @param string $relative Relative path under ELXAO_NC_BASE
 * @return bool
 */
function elxao_nc_mkcol($relative)
{
    [$code] = elxao_nc_request('MKCOL', $relative);
    return in_array($code, [201, 405], true);
}

/**
 * PROPFIND wrapper. Sends XML body requesting minimal props and ensures
 * trailing slash on directory. Returns raw XML string on success or a
 * WP_Error on failure.
 *
 * @param string $relative Relative path (without leading slash) under ELXAO_NC_BASE
 * @return string|WP_Error
 */
function elxao_nc_propfind($relative)
{
    $rel  = rtrim($relative, '/') . '/';
    $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<d:propfind xmlns:d="DAV:"><d:prop>' .
            '<d:resourcetype/><d:getcontentlength/><d:getlastmodified/>' .
            '</d:prop></d:propfind>';
    [$code, , $resp] = elxao_nc_request('PROPFIND', $rel, [
        'Content-Type: text/xml; charset=UTF-8',
        'Depth: 1',
    ], $body);
    if ($code >= 400) {
        return new WP_Error('propfind_failed', 'Nextcloud PROPFIND failed', [
            'status' => $code,
            'rel'    => $rel,
        ]);
    }
    return $resp;
}

/**
 * Normalise a WebDAV href returned by Nextcloud into a relative path under
 * the configured ELXAO_NC_BASE.
 *
 * Handles absolute URLs, xml:base-relative paths and double slashes while
 * stripping the leading user namespace portion so the caller can compare the
 * value directly with project-relative paths.
 *
 * @param string $href Href value from a PROPFIND response
 * @return string Normalised, trimmed relative path
 */
function elxao_nc_normalize_href($href)
{
    $decoded = rawurldecode((string)$href);
    $path    = $decoded;

    if (str_contains($path, '://')) {
        $url_path = parse_url($path, PHP_URL_PATH);
        if (is_string($url_path)) {
            $path = $url_path;
        }
    }

    if (($qpos = strpos($path, '?')) !== false) {
        $path = substr($path, 0, $qpos);
    }
    if (($hpos = strpos($path, '#')) !== false) {
        $path = substr($path, 0, $hpos);
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/{2,}#', '/', $path);
    $path = ltrim((string)$path, '/');

    $base_path = parse_url(ELXAO_NC_BASE, PHP_URL_PATH);
    $base_path = $base_path ? trim($base_path, '/') : '';
    if ($base_path !== '') {
        if ($path === $base_path) {
            $path = '';
        } elseif (str_starts_with($path, $base_path . '/')) {
            $path = substr($path, strlen($base_path) + 1);
        }
    }

    return trim($path, '/');
}

/**
 * Delete file or folder on Nextcloud. Returns true for 2xx status.
 *
 * @param string $relative Relative path to delete
 * @return bool
 */
function elxao_nc_delete($relative)
{
    [$code] = elxao_nc_request('DELETE', $relative);
    return $code >= 200 && $code < 300;
}

/**
 * Move or rename a file/folder. Destination must be absolute relative
 * to ELXAO_NC_BASE. Returns true for 2xx status.
 *
 * @param string $from Source relative path
 * @param string $to   Destination relative path
 * @return bool
 */
function elxao_nc_move($from, $to)
{
    $dest = elxao_nc_url($to);
    [$code] = elxao_nc_request('MOVE', $from, [
        'Destination: ' . $dest,
        'Overwrite: T',
    ]);
    return $code >= 200 && $code < 300;
}

/*
 * ===========================================================
 * PROJECT CREATION
 * =========================================================== */
function elxao_subscription_cpts()
{
    return ['shop_subscription', 'subscription', 'fs_subscription', 'wpdesk_subscription', 'wc_subscription'];
}

function elxao_relation_keys()
{
    return ['_order_id', '_parent_order_id', '_initial_order_id', '_origin_order_id', 'order_id', '_order_key'];
}

function elxao_build_relation_meta_query(int $order_id, string $order_key): array
{
    $keys = elxao_relation_keys();
    $rel  = ['relation' => 'OR'];
    foreach ($keys as $k) {
        if ($k === '_order_key') {
            if ($order_key !== '') {
                $rel[] = ['key' => $k, 'value' => $order_key, 'compare' => '='];
            }
            continue;
        }
        $rel[] = ['key' => $k, 'value' => $order_id, 'compare' => '='];
        $rel[] = ['key' => $k, 'value' => '"' . $order_id . '"', 'compare' => 'LIKE'];
        $rel[] = ['key' => $k, 'value' => 'i:' . $order_id . ';', 'compare' => 'LIKE'];
    }
    return $rel;
}

function elxao_is_subscription_item(WC_Order_Item_Product $item): bool
{
    $product = $item->get_product();
    if (!$product) {
        return false;
    }
    $product_id = $product->get_id();
    $parent_id  = method_exists($product, 'get_parent_id') ? (int)$product->get_parent_id() : 0;
    $slug       = 'sla-gmaas';
    $in_cat     = static function ($pid) use ($slug) {
        return $pid ? has_term($slug, 'product_cat', $pid) : false;
    };
    return $in_cat($product_id) || ($parent_id && $in_cat($parent_id));
}

function elxao_find_subscription_post_id_for_order(WC_Order $order): string
{
    $order_id  = (int)$order->get_id();
    $customer  = (int)$order->get_user_id();
    $order_key = (string)$order->get_order_key();
    $cpts      = elxao_subscription_cpts();
    $rel       = elxao_build_relation_meta_query($order_id, $order_key);
    // 1) match by customer and relation keys
    $q1 = new WP_Query([
        'post_type'      => $cpts,
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            ['key' => '_customer_user', 'value' => $customer, 'compare' => '='],
            $rel,
        ],
    ]);
    if (!empty($q1->posts)) {
        return (string)$q1->posts[0];
    }
    // 2) match by relation keys only
    $q2 = new WP_Query([
        'post_type'      => $cpts,
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'fields'         => 'ids',
        'meta_query'     => $rel,
    ]);
    if (!empty($q2->posts)) {
        return (string)$q2->posts[0];
    }
    // 3) match by post_parent (legacy)
    $q3 = new WP_Query([
        'post_type'      => $cpts,
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'fields'         => 'ids',
        'post_parent'    => $order_id,
    ]);
    if (!empty($q3->posts)) {
        return (string)$q3->posts[0];
    }
    // 4) match via WooCommerce subscriptions API
    if (function_exists('wcs_get_subscriptions_for_order')) {
        $subs = wcs_get_subscriptions_for_order($order, ['order_type' => 'any']);
        if (!empty($subs)) {
            $first = reset($subs);
            if ($first && is_object($first)) {
                if (method_exists($first, 'get_id')) {
                    return (string)$first->get_id();
                }
                if (isset($first->id)) {
                    return (string)(int)$first->id;
                }
            }
        }
    }
    // 5) attempt to read subscription IDs from order item meta
    foreach ($order->get_items() as $item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            continue;
        }
        foreach (['_subscription_id', 'subscription_id', '_fs_subscription_id', 'fs_subscription_id'] as $k) {
            $v = $item->get_meta($k, true);
            if ($v) {
                return (string)$v;
            }
        }
    }
    return '';
}

/* Create projects from order when processing or completed */
add_action('woocommerce_order_status_processing', 'elxao_create_projects_from_order', 10, 1);
add_action('woocommerce_order_status_completed', 'elxao_create_projects_from_order', 10, 1);
add_action('woocommerce_thankyou', 'elxao_backfill_on_thankyou', 10, 1);
add_action('woocommerce_checkout_subscription_created', 'elxao_on_checkout_subscription_created', 20, 2);

/**
 * Automatically creates project posts for every product purchased in an order.
 * Populates ACF fields and triggers Nextcloud folder provisioning.
 *
 * @param int $order_id WooCommerce order ID
 * @return void
 */
function elxao_create_projects_from_order($order_id)
{
    if (!function_exists('wc_get_order')) {
        return;
    }
    if (get_post_meta($order_id, '_elxao_projects_created', true)) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order || (method_exists($order, 'is_paid') && !$order->is_paid())) {
        return;
    }
    $client_id = (int)$order->get_user_id();
    $items     = $order->get_items();
    if (empty($items)) {
        return;
    }
    $prefill_sub_id = elxao_find_subscription_post_id_for_order($order); // may be ''
    foreach ($items as $item_id => $item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            continue;
        }
        $product      = $item->get_product();
        $product_name = $item->get_name();
        $qty          = (int)$item->get_quantity();
        $is_sub_item  = elxao_is_subscription_item($item);
        $project_type = $is_sub_item ? 'subscription' : 'one_shot';
        $subscription_id = $is_sub_item ? (string)$prefill_sub_id : '';
        $project_post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($product_name),
            'post_type'    => 'project',
            'post_status'  => 'publish',
            'post_content' => '',
        ]);
        if (is_wp_error($project_post_id)) {
            continue;
        }
        $now_mysql = current_time('mysql');
        $now_date  = current_time('Y-m-d');
        // Populate ACF/meta
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
        elxao_update_acf('cloud_folder_id',   '',               $project_post_id);
        elxao_update_acf('action_required',   0,                $project_post_id);
        elxao_update_acf('action_type',       '',               $project_post_id);
        elxao_update_acf('action_message',    '',               $project_post_id);
        elxao_update_acf('latest_message_at', $now_mysql,       $project_post_id);
        update_post_meta($project_post_id, '_elxao_is_subscription_item', $is_sub_item ? 1 : 0);
        // Trigger Nextcloud provisioning
        do_action('elxao_drive_create_folder', $project_post_id, $client_id);
    }
    update_post_meta($order_id, '_elxao_projects_created', 1);
}

/**
 * Backfill subscription ID into existing projects after checkout thankyou page.
 *
 * @param int $order_id WooCommerce order ID
 * @return void
 */
function elxao_backfill_on_thankyou($order_id)
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    $sub_id = elxao_find_subscription_post_id_for_order($order);
    if ($sub_id) {
        elxao_assign_subscription_to_order_projects((int)$order_id, (int)$sub_id);
    }
}

/**
 * Hooked during checkout when a subscription is created. Ensures projects
 * associated with the order get linked to the subscription.
 *
 * @param mixed     $subscription Subscription object or ID
 * @param WC_Order  $order        WooCommerce order
 * @return void
 */
function elxao_on_checkout_subscription_created($subscription, $order)
{
    if (!$order instanceof WC_Order) {
        $order_id = is_object($order) && method_exists($order, 'get_id') ? (int)$order->get_id() : (int)$order;
        $order    = $order_id ? wc_get_order($order_id) : null;
    }
    if (!$order instanceof WC_Order) {
        return;
    }
    $subscription_id = 0;
    if (is_object($subscription)) {
        if (method_exists($subscription, 'get_id')) {
            $subscription_id = (int)$subscription->get_id();
        } elseif (isset($subscription->id)) {
            $subscription_id = (int)$subscription->id;
        }
        if (!$subscription_id && method_exists($subscription, 'get_parent_id')) {
            $maybe = (int)$subscription->get_parent_id();
            if (!$order->get_id() && $maybe) {
                $order = wc_get_order($maybe);
            }
        }
    } else {
        $subscription_id = (int)$subscription;
    }
    if (!$subscription_id) {
        return;
    }
    elxao_assign_subscription_to_order_projects((int)$order->get_id(), $subscription_id);
}

/**
 * Assign subscription ID and ensure project type is 'subscription' for any projects
 * created from a given order that are subscription items.
 *
 * @param int $origin_order_id Order ID that spawned the projects
 * @param int $subscription_post_id Associated subscription post ID
 * @return void
 */
function elxao_assign_subscription_to_order_projects($origin_order_id, $subscription_post_id)
{
    $q = new WP_Query([
        'post_type'              => 'project',
        'post_status'            => 'any',
        'posts_per_page'         => 200,
        'no_found_rows'          => true,
        'fields'                 => 'ids',
        'meta_query'             => [
            ['key' => 'order_id', 'value' => $origin_order_id, 'compare' => '='],
        ],
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'ignore_sticky_posts'    => true,
    ]);
    if (empty($q->posts)) {
        return;
    }
    foreach ($q->posts as $pid) {
        $ptype     = (string)(function_exists('get_field') ? get_field('project_type', $pid) : get_post_meta($pid, 'project_type', true));
        $is_sub_meta = (int)get_post_meta($pid, '_elxao_is_subscription_item', true) === 1;
        $is_subscription = ($ptype === 'subscription') || $is_sub_meta;
        if (!$is_subscription) {
            continue;
        }
        $current = (string)(function_exists('get_field') ? get_field('subscription_id', $pid) : get_post_meta($pid, 'subscription_id', true));
        if ($current === '') {
            elxao_update_acf('subscription_id', (string)$subscription_post_id, $pid);
        }
        if ($ptype !== 'subscription') {
            elxao_update_acf('project_type', 'subscription', $pid);
        }
    }
}

/*
 * Admin: add sortable ID column in Projects list table
 */
add_filter('manage_project_posts_columns', function ($cols) {
    $new = [];
    foreach ($cols as $k => $v) {
        $new[$k] = $v;
        if ($k === 'title') {
            $new['elxao_id'] = 'ID';
        }
    }
    return $new;
});
add_action('manage_project_posts_custom_column', function ($col, $post_id) {
    if ($col === 'elxao_id') {
        echo (int)$post_id;
    }
}, 10, 2);
add_filter('manage_edit-project_sortable_columns', function ($cols) {
    $cols['elxao_id'] = 'elxao_id';
    return $cols;
});
add_action('pre_get_posts', function ($q) {
    if (!is_admin() || !$q->is_main_query()) {
        return;
    }
    if ($q->get('post_type') !== 'project') {
        return;
    }
    if ($q->get('orderby') === 'elxao_id') {
        $q->set('orderby', 'ID');
    }
});

/*
 * ===========================================================
 * NEXTCLOUD PROVISIONING
 * =========================================================== */
add_action('elxao_drive_create_folder', function ($project_id, $client_user_id) {
    $client_slug = 'client-unknown';
    if ($client_user_id) {
        $u = get_userdata((int)$client_user_id);
        if ($u) {
            $disp        = $u->display_name ?: $u->user_nicename ?: $u->user_login;
            $client_slug = elxao_slug($disp);
        }
    }
    $ref   = (string)(elxao_get_acf('project_id', $project_id) ?: $project_id);
    $pname = (string)(elxao_get_acf('project_name', $project_id) ?: get_the_title($project_id));
    $pslug = elxao_slug($pname ?: ('project-' . $project_id));
    $base  = '/ELXAO/' . $client_slug . '/' . $ref . '_' . $pslug;
    $segments = explode('/', trim($base, '/'));
    $acc      = '';
    foreach ($segments as $seg) {
        $acc .= ($acc ? '/' : '') . $seg;
        if (!elxao_nc_mkcol($acc)) {
            elxao_log('MKCOL fail ' . $acc);
            return;
        }
    }
    foreach (['Uploads', 'Planning', 'Deliverables', 'Reports'] as $sub) {
        elxao_nc_mkcol(trim($base, '/') . '/' . $sub);
    }
    elxao_update_acf('cloud_folder_id', $base, $project_id);
    elxao_log(['provisioned' => $project_id, 'path' => $base]);
}, 10, 2);

/*
 * ===========================================================
 * REST SECURITY (rate limit + optional HMAC)
 * =========================================================== */
function elxao_rest_error($message, $status = 400, $extra = [])
{
    $payload = array_merge(['ok' => false, 'message' => (string)$message], $extra);
    wp_send_json($payload, $status);
}

/**
 * Rate limiter. Each user is allowed ELXAO_CLOUD_RATE_MAX_REQ requests per
 * ELXAO_CLOUD_RATE_WINDOW_SEC seconds. Throws error if limit exceeded.
 */
function elxao_cloud_check_rate()
{
    $uid = elxao_current_user_id();
    if (!$uid) {
        return;
    }
    $win = (int)ELXAO_CLOUD_RATE_WINDOW_SEC;
    $max = (int)ELXAO_CLOUD_RATE_MAX_REQ;
    $key = 'elxao_cloud_rate_' . $uid;
    $data = get_transient($key);
    $now  = time();
    if (!$data) {
        $data = ['t' => $now, 'c' => 0];
    }
    if (($now - $data['t']) > $win) {
        $data = ['t' => $now, 'c' => 0];
    }
    $data['c']++;
    set_transient($key, $data, $win);
    if ($data['c'] > $max) {
        elxao_rest_error('Rate limit exceeded', 429);
    }
}

/**
 * Optional HMAC verification. If ELXAO_CLOUD_HMAC_SECRET is defined, requests must
 * include timestamp (ts) and signature (sig) query parameters. Signature is
 * generated on client as hash_hmac('sha256', userId|ts|uriPath|host, secret).
 */
function elxao_cloud_verify_hmac()
{
    if (!defined('ELXAO_CLOUD_HMAC_SECRET') || !ELXAO_CLOUD_HMAC_SECRET) {
        return; // optional
    }
    $ts  = isset($_GET['ts']) ? (int)$_GET['ts'] : 0;
    $sig = isset($_GET['sig']) ? (string)$_GET['sig'] : '';
    if (!$ts || !$sig) {
        elxao_rest_error('Missing signature', 403);
    }
    if (abs(time() - $ts) > 120) {
        elxao_rest_error('Signature expired', 403);
    }
    $user         = elxao_current_user_id();
    $rest_base    = get_rest_url(null, 'elxao/v1');
    $sig_uri_path = function_exists('wp_parse_url') ? wp_parse_url($rest_base, PHP_URL_PATH) : parse_url($rest_base, PHP_URL_PATH);
    $uri          = $sig_uri_path ? rtrim($sig_uri_path, '/') : '/wp-json/elxao/v1';
    $host         = parse_url(home_url('/'), PHP_URL_HOST);
    $calc         = hash_hmac('sha256', $user . '|' . $ts . '|' . $uri . '|' . $host, ELXAO_CLOUD_HMAC_SECRET);
    if (!hash_equals($calc, $sig)) {
        elxao_rest_error('Invalid signature', 403);
    }
}

/*
 * ===========================================================
 * REST: endpoints (list/download/upload/mkdir/rename/move/delete)
 * =========================================================== */
add_action('rest_api_init', function () {
    register_rest_route('elxao/v1', '/cloud/list', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'elxao_api_cloud_list',
    ]);
    register_rest_route('elxao/v1', '/cloud/download', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'elxao_api_cloud_download',
    ]);
    register_rest_route('elxao/v1', '/cloud/upload', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'elxao_api_cloud_upload',
    ]);
    register_rest_route('elxao/v1', '/cloud/mkdir', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'elxao_api_cloud_mkdir',
    ]);
    register_rest_route('elxao/v1', '/cloud/rename', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'elxao_api_cloud_rename',
    ]);
    register_rest_route('elxao/v1', '/cloud/move', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'elxao_api_cloud_move',
    ]);
    register_rest_route('elxao/v1', '/cloud/delete', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'elxao_api_cloud_delete',
    ]);
    // Admin maintenance
    register_rest_route('elxao/v1', '/cloud-delete', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback'            => 'elxao_api_project_delete',
    ]);
    register_rest_route('elxao/v1', '/cloud-rename', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback'            => 'elxao_api_project_rename',
    ]);
    register_rest_route('elxao/v1', '/cloud-rebuild', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback'            => 'elxao_api_project_rebuild',
    ]);
});

/**
 * Helper to compute base path for a project in Nextcloud. Returns stored
 * value if present, otherwise reconstructs from project metadata.
 * Always begins with '/ELXAO/'.
 *
 * @param int $project_id Project ID
 * @return string
 */
function elxao_project_basepath($project_id)
{
    $stored = (string)elxao_get_acf('cloud_folder_id', $project_id);
    if ($stored && str_starts_with($stored, '/ELXAO/')) {
        return $stored;
    }
    $client_id   = elxao_normalize_user_id(elxao_get_acf('client_user', $project_id));
    $client_slug = 'client-unknown';
    if ($client_id) {
        $u = get_userdata($client_id);
        if ($u) {
            $disp        = $u->display_name ?: $u->user_nicename ?: $u->user_login;
            $client_slug = elxao_slug($disp);
        }
    }
    $ref   = (string)(elxao_get_acf('project_id', $project_id) ?: $project_id);
    $pname = (string)(elxao_get_acf('project_name', $project_id) ?: get_the_title($project_id));
    $pslug = elxao_slug($pname ?: ('project-' . $project_id));
    return '/ELXAO/' . $client_slug . '/' . $ref . '_' . $pslug;
}

/**
 * Sanitize a relative path by removing leading slashes and rejecting any
 * directory traversal attempts. Returns empty string on invalid input.
 *
 * @param string $p Path to sanitize
 * @return string Sanitized relative path or empty string if invalid
 */
function elxao_sanitize_relpath($p)
{
    $p = ltrim((string)$p, '/');
    if (strpos($p, '..') !== false) {
        return '';
    }
    return $p;
}

/**
 * Core request guard. Enforces authentication, role-based permissions,
 * optional rate limiting and HMAC verification. Returns details for
 * further processing: user role, base path, sub path, full path and
 * project ID. When a write operation is requested and the caller is a
 * client, restrict to /Uploads subfolder unless explicitly allowed.
 *
 * @param WP_REST_Request $request
 * @param bool            $need_write Whether this operation writes data
 * @param bool            $must_be_upload_for_client Whether clients can write only in uploads folder
 * @return array          [$role, $base, $sub, $full, $project_id]
 */
function elxao_guard_and_paths($request, $need_write = false, $must_be_upload_for_client = false)
{
    elxao_cloud_check_rate();
    elxao_cloud_verify_hmac();
    if (!is_user_logged_in()) {
        elxao_rest_error('Auth required', 401);
    }
    $project_id = (int)$request->get_param('project_id');
    if (!$project_id) {
        elxao_rest_error('Missing project_id', 400);
    }
    $role = elxao_user_role_for_project($project_id, elxao_current_user_id());
    if ($role === 'none' || $role === 'guest') {
        elxao_rest_error('Forbidden', 403);
    }
    if ($need_write && $role === 'client' && !$must_be_upload_for_client) {
        elxao_rest_error('Clients cannot write here', 403);
    }
    $base = elxao_project_basepath($project_id);
    $sub  = (string)$request->get_param('path');
    $sub  = $sub ? elxao_sanitize_relpath($sub) : '';
    $full = trim($base, '/') . ($sub ? '/' . $sub : '');
    return [$role, $base, $sub, $full, $project_id];
}

/*
 * LIST (namespace-aware; returns name, type, size, mtime)
 */
function elxao_api_cloud_list($request)
{
    [$role, $base, $sub, $full, $project_id] = elxao_guard_and_paths($request, false, false);
    $resp = elxao_nc_propfind($full);
    if (is_wp_error($resp)) {
        $status = 0;
        $data   = $resp->get_error_data();
        if (is_array($data) && isset($data['status'])) {
            $status = (int)$data['status'];
        }
        // If the base folder is missing, attempt to recreate the standard tree once.
        if (!$sub && $status === 404) {
            $segments = elxao_project_segments($project_id);
            if (elxao_mkcol_recursive_segments($segments)) {
                foreach (['Uploads', 'Planning', 'Deliverables', 'Reports'] as $child) {
                    elxao_nc_mkcol(implode('/', array_merge($segments, [$child])));
                }
                $resp = elxao_nc_propfind($full);
            }
        }
        if (is_wp_error($resp)) {
            elxao_rest_error($resp->get_error_message(), $status ?: 500);
        }
    }
    $xml = @simplexml_load_string($resp);
    $out = [];
    if ($xml) {
        // Determine DAV namespace prefix
        $ns      = $xml->getDocNamespaces(true);
        $davUri  = 'DAV:';
        $prefix  = null;
        foreach ($ns as $p => $u) {
            if ($u === 'DAV:') {
                $prefix = $p ?: 'dav';
                break;
            }
        }
        if (!$prefix) {
            $prefix = 'dav';
        }
        $xml->registerXPathNamespace($prefix, $davUri);
        $responses   = $xml->xpath('//' . $prefix . ':response') ?: [];
        $self        = trim($full, '/');
        $self_prefix = $self !== '' ? $self . '/' : '';
        $items       = [];
        foreach ($responses as $node) {
            $hrefNode = $node->xpath('./' . $prefix . ':href');
            if (!$hrefNode || !isset($hrefNode[0])) {
                continue;
            }
            $rel_full = elxao_nc_normalize_href((string)$hrefNode[0]);
            if ($rel_full === $self) {
                continue; // Skip parent directory itself
            }
            if ($self !== '') {
                if (str_starts_with($rel_full, $self_prefix)) {
                    $rel = substr($rel_full, strlen($self_prefix));
                } else {
                    // Some Nextcloud versions return href values relative to an
                    // xml:base attribute (e.g. just "file.pdf"). In that case
                    // treat the href as belonging to the current directory.
                    $rel = basename($rel_full);
                }
            } else {
                $rel = $rel_full;
            }
            $rel = trim($rel, '/');
            if ($rel === '') {
                continue;
            }
            if (str_contains($rel, '/')) {
                // Depth: 1 should not return deeper items, but guard against
                // xml:base oddities by only keeping the immediate child name.
                $parts = explode('/', $rel, 2);
                $rel   = $parts[0];
            }
            $name = $rel;
            if ($name === '') {
                continue;
            }
            // Determine if directory
            $isDir = (bool)($node->xpath('.//' . $prefix . ':collection'));
            // Fetch size and mtime if available
            $sizeNode  = $node->xpath('.//' . $prefix . ':getcontentlength');
            $mtimeNode = $node->xpath('.//' . $prefix . ':getlastmodified');
            $size      = $sizeNode && isset($sizeNode[0]) ? (int)$sizeNode[0] : 0;
            $mtime     = $mtimeNode && isset($mtimeNode[0]) ? (string)$mtimeNode[0] : '';
            $key       = strtolower($name);
            if (isset($items[$key])) {
                // Prefer directory classification if any response marks it so.
                if ($isDir && ($items[$key]['type'] ?? '') !== 'dir') {
                    $items[$key]['type'] = 'dir';
                    $items[$key]['size'] = 0;
                }
                continue;
            }
            $items[$key] = [
                'name' => $name,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? 0 : $size,
                'mtime'=> $mtime,
            ];
        }
        $out = array_values($items);
    }
    if (!$sub) {
        $existing = [];
        foreach ($out as $item) {
            if (($item['type'] ?? '') === 'dir') {
                $existing[strtolower($item['name'])] = true;
            }
        }
        $base_rel = trim($full, '/');
        foreach (['Uploads', 'Planning', 'Deliverables', 'Reports'] as $folder) {
            if (!isset($existing[strtolower($folder)])) {
                if ($base_rel) {
                    elxao_nc_mkcol($base_rel . '/' . $folder);
                }
                $out[] = ['name' => $folder, 'type' => 'dir', 'size' => 0, 'mtime' => ''];
            }
        }
        usort($out, function ($a, $b) {
            $typeA = $a['type'] ?? '';
            $typeB = $b['type'] ?? '';
            if ($typeA !== $typeB) {
                return $typeA === 'dir' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
    }
    $response = new WP_REST_Response([
        'ok'   => true,
        'base' => $base,
        'path' => $sub,
        'items'=> $out,
        'role' => $role,
    ], 200);
    $response->set_headers([
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma'        => 'no-cache',
    ]);

    return $response;
}

/*
 * DOWNLOAD (streaming proxy)
 */
function elxao_api_cloud_download($request)
{
    [$role, $base, $sub, $full] = elxao_guard_and_paths($request, false, false);
    if (!$sub) {
        wp_die('Missing file path', '', 400);
    }
    $url = elxao_nc_url($full);
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_USERPWD, ELXAO_NC_USER . ':' . ELXAO_NC_PASS);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)ELXAO_NC_TIMEOUT);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    // Remove any default headers WordPress adds
    header_remove();
    nocache_headers();
    $ctype_sent = false;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$ctype_sent) {
        $len   = strlen($header);
        $lower = strtolower($header);
        if (str_starts_with($lower, 'content-type:') && !$ctype_sent) {
            header($header);
            $ctype_sent = true;
        }
        return $len;
    });
    header('Content-Disposition: attachment; filename="' . rawurlencode(basename($sub)) . '"');
    header('X-Accel-Buffering: no');
    header('Content-Transfer-Encoding: binary');
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
        echo $data;
        flush();
        return strlen($data);
    });
    $ok   = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false || $code >= 400) {
        status_header($code ?: 404);
        exit;
    }
    exit;
}

/* Helpers for upload policy */
function elxao_ext($n)
{
    $p = strrpos($n, '.');
    return $p === false ? '' : strtolower(substr($n, $p + 1));
}

function elxao_is_blocked_ext($n)
{
    $blocked = array_map('trim', explode(',', ELXAO_CLOUD_BLOCK_EXT));
    $e       = elxao_ext($n);
    return $e && in_array($e, $blocked, true);
}

function elxao_is_allowed_mime_value($m)
{
    $allowed = array_map('trim', explode(',', ELXAO_CLOUD_ALLOWED_MIME));
    return in_array($m, $allowed, true);
}

/*
 * UPLOAD (zero-retention stream)
 */
function elxao_api_cloud_upload($request)
{
    [$role, $base, $sub, $full, $project_id] = elxao_guard_and_paths($request, true, true);
    // Restrict clients to uploads subfolder
    $uploads_root = trim($base, '/') . '/' . ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER;
    if ($role === 'client' && !str_starts_with($full, $uploads_root)) {
        elxao_rest_error('Client uploads limited to /Uploads', 403);
    }
    // Determine filename and sanitize
    $filename = sanitize_file_name((string)($_SERVER['HTTP_X_FILE_NAME'] ?? ''));
    if (!$filename) {
        elxao_rest_error('Missing X-File-Name header', 400);
    }
    if (elxao_is_blocked_ext($filename)) {
        elxao_rest_error('File type not allowed (ext)', 415);
    }
    // Determine MIME type
    $mime = (string)($_SERVER['HTTP_X_FILE_TYPE'] ?? ($_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream'));
    if ($mime && !elxao_is_allowed_mime_value($mime)) {
        elxao_rest_error('MIME not allowed: ' . $mime, 415);
    }
    // Enforce max upload size
    $content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    $max_bytes      = (int)ELXAO_CLOUD_MAX_UPLOAD_MB * 1024 * 1024;
    if ($content_length && $content_length > $max_bytes) {
        elxao_rest_error('File too large', 413);
    }
    $target_rel = $full . '/' . $filename;
    $in         = fopen('php://input', 'rb');
    if (!$in) {
        elxao_rest_error('Upload stream error', 500);
    }
    $read_cb = function ($ch, $fd, $len) use ($in) {
        return fread($in, $len);
    };
    [$code] = elxao_nc_request('PUT', $target_rel, [], null, [
        'read_cb'   => $read_cb,
        'infilesize'=> $content_length ?: null,
    ]);
    fclose($in);
    if ($code < 200 || $code >= 300) {
        elxao_rest_error('Upload failed', 500, ['status' => $code]);
    }
    return new WP_REST_Response(['ok' => true, 'path' => $target_rel], 200);
}

/*
 * MKDIR
 */
function elxao_api_cloud_mkdir($request)
{
    [$role, $base, $sub, $full] = elxao_guard_and_paths($request, true, false);
    if ($role === 'client') {
        elxao_rest_error('Clients cannot create folders', 403);
    }
    $name = sanitize_file_name((string)$request->get_param('name'));
    if (!$name) {
        elxao_rest_error('Missing name', 400);
    }
    $rel = rtrim($full, '/') . '/' . $name;
    $ok  = elxao_nc_mkcol($rel);
    if (!$ok) {
        elxao_rest_error('MKCOL failed', 500);
    }
    return new WP_REST_Response(['ok' => true], 200);
}

/*
 * RENAME
 */
function elxao_api_cloud_rename($request)
{
    [$role, $base, $sub, $full] = elxao_guard_and_paths($request, true, false);
    if ($role === 'client') {
        elxao_rest_error('Clients cannot rename', 403);
    }
    $new = sanitize_file_name((string)$request->get_param('new_name'));
    if (!$new || !$sub) {
        elxao_rest_error('Missing current path or new_name', 400);
    }
    $to = dirname($full) . '/' . $new;
    $ok = elxao_nc_move($full, $to);
    if (!$ok) {
        elxao_rest_error('Rename failed', 500);
    }
    return new WP_REST_Response(['ok' => true, 'to' => $to], 200);
}

/*
 * MOVE
 */
function elxao_api_cloud_move($request)
{
    [$role, $base, $sub, $full] = elxao_guard_and_paths($request, true, false);
    if ($role === 'client') {
        elxao_rest_error('Clients cannot move', 403);
    }
    $to_rel = elxao_sanitize_relpath((string)$request->get_param('to'));
    if (!$to_rel) {
        elxao_rest_error('Invalid destination', 400);
    }
    $ok = elxao_nc_move($full, $to_rel);
    if (!$ok) {
        elxao_rest_error('Move failed', 500);
    }
    return new WP_REST_Response(['ok' => true, 'to' => $to_rel], 200);
}

/*
 * DELETE
 */
function elxao_api_cloud_delete($request)
{
    [$role, $base, $sub, $full] = elxao_guard_and_paths($request, true, false);
    if ($role === 'client') {
        elxao_rest_error('Clients cannot delete', 403);
    }
    if (!$sub) {
        elxao_rest_error('Nothing to delete', 400);
    }
    $ok = elxao_nc_delete($full);
    if (!$ok) {
        elxao_rest_error('Delete failed', 500);
    }
    return new WP_REST_Response(['ok' => true], 200);
}

/*
 * ===========================================================
 * Admin maintenance: delete/rename/rebuild whole project tree
 * =========================================================== */
function elxao_project_segments($project_id)
{
    return explode('/', trim(elxao_project_basepath($project_id), '/'));
}

function elxao_mkcol_recursive_segments($segments)
{
    $acc = '';
    foreach ($segments as $s) {
        $acc .= ($acc ? '/' : '') . $s;
        if (!elxao_nc_mkcol($acc)) {
            return false;
        }
    }
    return true;
}

function elxao_api_project_rebuild($request)
{
    if (!is_user_logged_in() || !elxao_is_admin()) {
        elxao_rest_error('Forbidden', 403);
    }
    $project_id = (int)$request->get_param('project_id');
    if (!$project_id) {
        elxao_rest_error('project_id required', 400);
    }
    $segs = elxao_project_segments($project_id);
    if (!elxao_mkcol_recursive_segments($segs)) {
        elxao_rest_error('Recreate base failed', 500);
    }
    foreach (['Uploads', 'Planning', 'Deliverables', 'Reports'] as $sub) {
        elxao_nc_mkcol(implode('/', array_merge($segs, [$sub])));
    }
    $path = '/' . implode('/', $segs);
    elxao_update_acf('cloud_folder_id', $path, $project_id);
    return new WP_REST_Response(['ok' => true, 'path' => $path], 200);
}

function elxao_api_project_delete($request)
{
    if (!is_user_logged_in() || !elxao_is_admin()) {
        elxao_rest_error('Forbidden', 403);
    }
    $project_id = (int)$request->get_param('project_id');
    if (!$project_id) {
        elxao_rest_error('project_id required', 400);
    }
    $path = trim(elxao_project_basepath($project_id), '/');
    $ok   = elxao_nc_delete($path);
    if (!$ok) {
        elxao_rest_error('Delete failed', 500);
    }
    return new WP_REST_Response(['ok' => true], 200);
}

function elxao_api_project_rename($request)
{
    if (!is_user_logged_in() || !elxao_is_admin()) {
        elxao_rest_error('Forbidden', 403);
    }
    $project_id = (int)$request->get_param('project_id');
    $new_slug   = elxao_slug((string)$request->get_param('new_slug'));
    if (!$project_id || !$new_slug) {
        elxao_rest_error('project_id and new_slug required', 400);
    }
    $base = elxao_project_basepath($project_id);
    $segs = explode('/', trim($base, '/'));
    array_pop($segs);
    $ref      = (string)(elxao_get_acf('project_id', $project_id) ?: $project_id);
    $new_last = $ref . '_' . $new_slug;
    $to       = implode('/', array_merge($segs, [$new_last]));
    $ok       = elxao_nc_move(trim($base, '/'), $to);
    if (!$ok) {
        elxao_rest_error('Rename failed', 500);
    }
    elxao_update_acf('project_name', $new_slug, $project_id);
    elxao_update_acf('cloud_folder_id', '/' . $to, $project_id);
    return new WP_REST_Response(['ok' => true, 'path' => '/' . $to], 200);
}

/*
 * ===========================================================
 * Embedded UI — Explorer
 * Shortcodes: [elxao_cloud] / [elxao_cloud_browser]
 * =========================================================== */
function elxao_render_cloud_shortcode($atts)
{
    $a   = shortcode_atts(['project_id' => 'auto'], $atts, 'elxao_cloud');
    $pid = $a['project_id'] === 'auto' ? (int)get_the_ID() : (int)$a['project_id'];
    if (!$pid) {
        return '<div class="elxao-cloud-error">Missing project_id</div>';
    }
    if (!is_user_logged_in()) {
        return '<div class="elxao-cloud-error">Login required</div>';
    }
    $uid  = elxao_current_user_id();
    $role = elxao_user_role_for_project($pid, $uid);
    if ($role === 'none' || $role === 'guest') {
        return '<div class="elxao-cloud-error">Access denied</div>';
    }
    wp_enqueue_script('elxao-cloud-explorer-js');
    wp_enqueue_style('elxao-cloud-explorer-css');
    // Prepare REST base and optional signature
    $rest_base    = get_rest_url(null, 'elxao/v1');
    $rest_base_esc = esc_url_raw($rest_base);
    $sig_uri_path  = function_exists('wp_parse_url') ? wp_parse_url($rest_base, PHP_URL_PATH) : parse_url($rest_base, PHP_URL_PATH);
    $sig_uri       = $sig_uri_path ? rtrim($sig_uri_path, '/') : '/wp-json/elxao/v1';
    $ts            = time();
    $sig           = '';
    if (defined('ELXAO_CLOUD_HMAC_SECRET') && ELXAO_CLOUD_HMAC_SECRET) {
        $host = parse_url(home_url('/'), PHP_URL_HOST);
        $sig  = hash_hmac('sha256', $uid . '|' . $ts . '|' . $sig_uri . '|' . $host, ELXAO_CLOUD_HMAC_SECRET);
    }
    wp_localize_script('elxao-cloud-explorer-js', 'ELXAO_CLOUD', [
        'projectId'  => $pid,
        'role'       => $role,
        'restBase'   => $rest_base_esc,
        'nonce'      => wp_create_nonce('wp_rest'),
        'ts'         => $ts,
        'sig'        => $sig,
        'uploadsSub' => ELXAO_CLOUD_CLIENT_UPLOAD_SUBFOLDER,
        'chunk'      => (int)ELXAO_CLOUD_STREAM_CHUNK,
    ]);
    ob_start();
    $drawer_id = 'elxao-explorer-' . uniqid();
    ?>
    <div class="elxao-explorer-wrapper">
        <button class="ex-btn ex-launch" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr($drawer_id); ?>">
            <span class="ex-launch-icon" aria-hidden="true"></span>
            <span><?php esc_html_e('Project files', 'elxao-cloud'); ?></span>
        </button>
        <div class="elxao-explorer-overlay" aria-hidden="true"></div>
        <div id="<?php echo esc_attr($drawer_id); ?>" class="elxao-explorer-drawer" role="dialog" aria-modal="true" aria-hidden="true">
            <div class="elxao-explorer" data-role="<?php echo esc_attr($role); ?>" tabindex="0">
                <div class="ex-toolbar">
            <button class="ex-btn ex-back" type="button" title="Back">←</button>
            <button class="ex-btn ex-up" type="button" title="Up one level">↑</button>
            <div class="ex-sep"></div>
            <button class="ex-btn ex-newfolder" type="button" title="New folder">New folder</button>
            <button class="ex-btn ex-rename" type="button" title="Rename">Rename</button>
            <button class="ex-btn ex-move" type="button" title="Move">Move</button>
            <button class="ex-btn ex-delete" type="button" title="Delete">Delete</button>
            <div class="ex-sep"></div>
            <input type="file" class="ex-file" style="display:none"/>
            <button class="ex-btn ex-upload" type="button" title="Upload">Upload</button>
            <div class="ex-flex"></div>
            <button class="ex-btn ex-view ex-grid" type="button" title="Grid/List">☰</button>
                </div>
                <div class="ex-breadcrumb"></div>
                <div class="ex-content">
                    <div class="ex-gridview"></div>
                    <table class="ex-listview" style="display:none">
                        <thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Modified</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="ex-status"></div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('elxao_cloud', 'elxao_render_cloud_shortcode');
add_shortcode('elxao_cloud_browser', 'elxao_render_cloud_shortcode');

/*
 * Inline JS/CSS (Explorer) registration
 */
add_action('wp_enqueue_scripts', function () {
    if (wp_script_is('elxao-cloud-explorer-js', 'registered')) {
        return;
    }
    // Register script with empty src; inline code will follow
    wp_register_script('elxao-cloud-explorer-js', '', [], '1.25.3', true);
    wp_add_inline_script('elxao-cloud-explorer-js', <<<JS
(function(){
  const S = { path:'', history:[], grid:true, selected:null };
  // Build API URL, ensuring no duplicate slashes between base and path
  function api(base,path,params){
    const baseClean = base.replace(/\/+$/, '');
    const pathClean = path.replace(/^\/+/, '');
    const u = new URL(baseClean + '/' + pathClean);
    if(params){ Object.entries(params).forEach(([k,v]) => u.searchParams.set(k, v)); }
    if(window.ELXAO_CLOUD && ELXAO_CLOUD.sig){
      u.searchParams.set('ts', ELXAO_CLOUD.ts);
      u.searchParams.set('sig', ELXAO_CLOUD.sig);
    }
    return u.toString();
  }
  function restHeaders(){ return {'X-WP-Nonce': (window.ELXAO_CLOUD ? ELXAO_CLOUD.nonce : '')}; }
  function segs(p){ return (p ? p.split('/') : []).filter(Boolean); }
  function joinPath(a,b){ return a ? (b ? (a + '/' + b) : a) : (b || ''); }
  function upPath(p){ const ss = segs(p); ss.pop(); return ss.join('/'); }
  function fmtSize(n){ if(!n || n <= 0) return ''; const k = 1024; const u = ['B','KB','MB','GB','TB']; let i = 0, v = n; while(v >= k && i < u.length-1){ v /= k; i++; } return v.toFixed(v >= 10 ? 0 : 1) + ' ' + u[i]; }
  function setStatus(root,msg){ const s = root.querySelector('.ex-status'); if(s){ s.textContent = msg || ''; } }
  function setRoleActions(root){ const role = root.getAttribute('data-role') || 'client'; const isClient = role === 'client'; ['.ex-newfolder','.ex-rename','.ex-move','.ex-delete'].forEach(sel => { const btn = root.querySelector(sel); if(btn){ btn.disabled = isClient; } }); }
  function load(root, pushHist = true){
    const url = api(ELXAO_CLOUD.restBase, 'cloud/list', {project_id: ELXAO_CLOUD.projectId, path: S.path});
    setStatus(root,'Loading...');
    fetch(url,{headers: restHeaders(), cache:'no-store'}).then(r => r.json()).then(j => {
      if(!j || !j.ok) throw new Error(j && j.message || 'Load failed');
      if(pushHist){ S.history.push(S.path); if(S.history.length > 50) S.history.shift(); }
      renderBreadcrumb(root, S.path);
      renderItems(root, j.items || []);
      updateUploadAvailability(root, S.path);
      setStatus(root, '');
    }).catch(err => { alert(err.message || 'Load failed'); setStatus(root,'Error'); });
  }
  function renderBreadcrumb(root, path){ const el = root.querySelector('.ex-breadcrumb'); if(!el) return; const parts = segs(path); let acc = ''; const out = ['<span class="ex-crumb" data-p="">Project</span>']; parts.forEach((p,i) => { acc = i === 0 ? p : acc + '/' + p; out.push('<span class="ex-sep">›</span><span class="ex-crumb' + (i === parts.length - 1 ? ' active' : '') + '" data-p="' + acc + '">' + p + '</span>'); }); el.innerHTML = out.join(''); el.querySelectorAll('.ex-crumb').forEach(c => { c.onclick = () => { S.path = c.getAttribute('data-p') || ''; load(root); }; }); }
  function renderItems(root, items){ S.selected = null; items.sort((a,b) => { if(a.type !== b.type) return a.type === 'dir' ? -1 : 1; return a.name.localeCompare(b.name); }); const grid = root.querySelector('.ex-gridview'); const list = root.querySelector('.ex-listview'); const tbody = list.querySelector('tbody'); grid.innerHTML = ''; tbody.innerHTML = ''; items.forEach(it => {
      const card = document.createElement('button'); card.type = 'button'; card.className = 'ex-card ' + (it.type === 'dir' ? 'dir' : 'file'); card.setAttribute('data-name', it.name); card.innerHTML = '<div class="ex-ico"></div><div class="ex-name" title="' + it.name + '">' + it.name + '</div>'; card.onclick = () => itemOpen(root,it); card.oncontextmenu = (e) => { e.preventDefault(); select(root, it.name, card); }; grid.appendChild(card);
      const tr = document.createElement('tr'); tr.setAttribute('data-name', it.name); tr.innerHTML = '<td>' + it.name + '</td><td>' + it.type + '</td><td>' + fmtSize(it.size) + '</td><td>' + (it.mtime || '') + '</td>'; tr.ondblclick = () => itemOpen(root,it); tr.onclick = () => select(root, it.name, tr); tbody.appendChild(tr);
    }); if(S.grid){ grid.style.display = 'grid'; list.style.display = 'none'; } else { grid.style.display = 'none'; list.style.display = 'table'; } }
  function select(root,name,el){ root.querySelectorAll('.ex-card.selected, .ex-listview tr.selected').forEach(x => x.classList.remove('selected')); if(el){ el.classList.add('selected'); S.selected = name; } else { S.selected = null; } }
  function itemOpen(root,it){ if(it.type === 'dir'){ S.path = joinPath(S.path, it.name); load(root); } else { const url = api(ELXAO_CLOUD.restBase, 'cloud/download', {project_id: ELXAO_CLOUD.projectId, path: joinPath(S.path, it.name)}); window.open(url, '_blank'); } }
  function updateUploadAvailability(root, path){ const role = root.getAttribute('data-role') || 'client'; const btn = root.querySelector('.ex-upload'); if(!btn) return; if(role !== 'client'){ btn.disabled = false; return; } const sub = ELXAO_CLOUD.uploadsSub; const allowed = (path === sub) || (path && path.startsWith(sub + '/')); btn.disabled = !allowed; }
  function attachToolbar(root){ const back = root.querySelector('.ex-back'); const up = root.querySelector('.ex-up'); const nf = root.querySelector('.ex-newfolder'); const rn = root.querySelector('.ex-rename'); const mv = root.querySelector('.ex-move'); const del = root.querySelector('.ex-delete'); const upbtn = root.querySelector('.ex-upload'); const file = root.querySelector('.ex-file'); const toggle = root.querySelector('.ex-view'); back.onclick = () => { S.history.pop(); const prev = S.history.pop(); if(prev === undefined){ S.path = ''; } else { S.path = prev; } load(root); };
    up.onclick = () => { S.path = upPath(S.path); load(root); };
    nf.onclick = () => { const name = prompt('New folder name:'); if(!name) return; fetch(api(ELXAO_CLOUD.restBase, 'cloud/mkdir', {project_id: ELXAO_CLOUD.projectId, path: S.path}), { method:'POST', headers: Object.assign({'Content-Type':'application/json'}, restHeaders()), body: JSON.stringify({name}) }).then(r => r.json()).then(j => { if(!j.ok) throw new Error(j.message || 'MKDIR failed'); load(root,false); }).catch(e => alert(e.message || 'MKDIR error')); };
    rn.onclick = () => { if(!S.selected) return alert('Select an item'); const newname = prompt('Rename to:', S.selected); if(!newname || newname === S.selected) return; fetch(api(ELXAO_CLOUD.restBase, 'cloud/rename', {project_id: ELXAO_CLOUD.projectId, path: joinPath(S.path, S.selected)}), { method:'POST', headers: Object.assign({'Content-Type':'application/json'}, restHeaders()), body: JSON.stringify({new_name: newname}) }).then(r => r.json()).then(j => { if(!j.ok) throw new Error(j.message || 'Rename failed'); load(root,false); }).catch(e => alert(e.message || 'Rename error')); };
    mv.onclick = () => { if(!S.selected) return alert('Select an item'); const to = prompt('Move to (path relative to project root):', upPath(joinPath(S.path, S.selected))); if(to === null) return; fetch(api(ELXAO_CLOUD.restBase, 'cloud/move', {project_id: ELXAO_CLOUD.projectId, path: joinPath(S.path, S.selected)}), { method:'POST', headers: Object.assign({'Content-Type':'application/json'}, restHeaders()), body: JSON.stringify({to}) }).then(r => r.json()).then(j => { if(!j.ok) throw new Error(j.message || 'Move failed'); load(root,false); }).catch(e => alert(e.message || 'Move error')); };
    del.onclick = () => { if(!S.selected) return alert('Select an item'); if(!confirm('Delete "' + S.selected + '"?')) return; fetch(api(ELXAO_CLOUD.restBase, 'cloud/delete', {project_id: ELXAO_CLOUD.projectId, path: joinPath(S.path, S.selected)}), { method:'POST', headers: restHeaders() }).then(r => r.json()).then(j => { if(!j.ok) throw new Error(j.message || 'Delete failed'); load(root,false); }).catch(e => alert(e.message || 'Delete error')); };
    upbtn.onclick = () => file.click(); file.onchange = () => { const f = file.files[0]; if(!f) return; upbtn.disabled = true; upbtn.textContent = 'Uploading...'; const url = api(ELXAO_CLOUD.restBase, 'cloud/upload', {project_id: ELXAO_CLOUD.projectId, path: S.path}); fetch(url, { method:'POST', headers: Object.assign({'Content-Type':'application/octet-stream','X-File-Name': f.name,'X-File-Type': (f.type || '')}, restHeaders()), body: f }).then(r => r.json()).then(j => { if(!j || !j.ok) throw new Error(j && j.message || 'Upload failed'); }).catch(err => alert(err.message || 'Upload error')).finally(() => { upbtn.disabled = false; upbtn.textContent = 'Upload'; file.value = ''; load(root,false); }); };
    root.addEventListener('dragover', e => { e.preventDefault(); root.classList.add('drag'); }); root.addEventListener('dragleave', () => root.classList.remove('drag')); root.addEventListener('drop', e => { e.preventDefault(); root.classList.remove('drag'); if(!e.dataTransfer.files || !e.dataTransfer.files.length) return; const f = e.dataTransfer.files[0]; const url = api(ELXAO_CLOUD.restBase, 'cloud/upload', {project_id: ELXAO_CLOUD.projectId, path: S.path}); fetch(url, { method:'POST', headers: Object.assign({'Content-Type':'application/octet-stream','X-File-Name': f.name,'X-File-Type': (f.type || '')}, restHeaders()), body: f }).then(r => r.json()).then(j => { if(!j || !j.ok) throw new Error(j && j.message || 'Upload failed'); }).catch(err => alert(err.message || 'Upload error')).finally(() => load(root,false)); }); toggle.onclick = () => { S.grid = !S.grid; const grid = root.querySelector('.ex-gridview'); const list = root.querySelector('.ex-listview'); if(S.grid){ grid.style.display = 'grid'; list.style.display = 'none'; toggle.classList.add('ex-grid'); } else { grid.style.display = 'none'; list.style.display = 'table'; toggle.classList.remove('ex-grid'); } };
    root.addEventListener('keydown', (e) => { if(e.key === 'Backspace'){ e.preventDefault(); S.path = upPath(S.path); load(root); } if(e.key === 'Enter' && S.selected){ const el = root.querySelector('.ex-card.selected') || root.querySelector('.ex-listview tr.selected'); if(el){ const name = el.getAttribute('data-name'); if(!name) return; const itType = el.classList.contains('dir') ? 'dir' : (el.querySelector('td:nth-child(2)')?.textContent || 'file'); itemOpen(root,{name, type: itType}); } } }); }
  function initExplorer(root){
    if(root.__elxaoInit) return;
    root.__elxaoInit = true;
    S.path = '';
    S.history = [];
    S.selected = null;
    setRoleActions(root);
    attachToolbar(root);
    load(root);
  }
  function setupWrappers(){
    document.querySelectorAll('.elxao-explorer-wrapper').forEach(wrapper => {
      if(wrapper.__elxaoDrawerInit) return;
      wrapper.__elxaoDrawerInit = true;
      const toggle = wrapper.querySelector('.ex-launch');
      const drawer = wrapper.querySelector('.elxao-explorer-drawer');
      if(!toggle || !drawer) return;
      const overlay = wrapper.querySelector('.elxao-explorer-overlay');
      const explorer = drawer.querySelector('.elxao-explorer');
      let initialized = false;
      function ensureInit(){
        if(!initialized && explorer){
          initExplorer(explorer);
          initialized = true;
        }
      }
      function onKeydown(e){
        if(e.key === 'Escape' && wrapper.classList.contains('is-open')){
          e.preventDefault();
          closeDrawer();
        }
      }
      function openDrawer(){
        ensureInit();
        wrapper.classList.add('is-open');
        toggle.setAttribute('aria-expanded','true');
        drawer.setAttribute('aria-hidden','false');
        if(overlay) overlay.setAttribute('aria-hidden','false');
        document.addEventListener('keydown', onKeydown);
        const focusTarget = explorer || drawer;
        window.requestAnimationFrame(() => {
          if(focusTarget) focusTarget.focus({preventScroll:true});
        });
      }
      function closeDrawer(){
        wrapper.classList.remove('is-open');
        toggle.setAttribute('aria-expanded','false');
        drawer.setAttribute('aria-hidden','true');
        if(overlay) overlay.setAttribute('aria-hidden','true');
        document.removeEventListener('keydown', onKeydown);
        window.requestAnimationFrame(() => {
          if(toggle) toggle.focus({preventScroll:true});
        });
      }
      toggle.addEventListener('click', () => {
        if(wrapper.classList.contains('is-open')) closeDrawer(); else openDrawer();
      });
      if(overlay){
        overlay.addEventListener('click', closeDrawer);
      }
    });
  }
  function init(){
    setupWrappers();
    document.querySelectorAll('.elxao-explorer').forEach(root => {
      if(!root.closest('.elxao-explorer-drawer')){
        initExplorer(root);
      }
    });
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
JS
);
    // Register inline CSS for explorer
    wp_register_style('elxao-cloud-explorer-css', false, [], '1.25.3');
    wp_add_inline_style('elxao-cloud-explorer-css', <<<CSS
.elxao-explorer-wrapper{position:relative;display:inline-block}
.ex-launch{margin-bottom:12px;padding:12px 18px;font-size:15px;box-shadow:0 10px 24px rgba(219,39,119,.15)}
.ex-launch-icon{width:20px;height:20px;background:currentColor;mask:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M4 4a2 2 0 012-2h6l2 2h6a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V4z"/></svg>') no-repeat 50% 50%/contain}
.elxao-explorer-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);opacity:0;pointer-events:none;transition:opacity .28s ease;z-index:10000}
.elxao-explorer-drawer{position:fixed;top:0;right:0;height:100vh;width:420px;max-width:92vw;padding:32px 28px;background:transparent;display:flex;align-items:stretch;justify-content:flex-end;transform:translateX(100%);transition:transform .28s ease;z-index:10001;pointer-events:none}
.elxao-explorer-wrapper.is-open .elxao-explorer-overlay{opacity:1;pointer-events:auto}
.elxao-explorer-wrapper.is-open .elxao-explorer-drawer{transform:translateX(0);pointer-events:auto}
.elxao-explorer{border:1px solid rgba(240,240,245,.9);border-radius:18px;padding:16px;background:linear-gradient(180deg,#fff 0%,#f9f7fb 100%);font-family:"SF Pro Text",system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;outline:none;box-shadow:0 16px 40px rgba(15,23,42,.08);display:flex;flex-direction:column;max-height:100%;overflow:hidden}
.elxao-explorer-drawer .elxao-explorer{width:100%;height:100%;max-height:none}
.elxao-explorer.drag{box-shadow:0 0 0 3px rgba(236,72,153,.2)}
.ex-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.ex-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid rgba(244,114,182,.35);border-radius:999px;background:rgba(255,255,255,.85);color:#db2777;font-weight:600;cursor:pointer;box-shadow:0 4px 10px rgba(219,39,119,.08);transition:all .2s ease}
.ex-btn:hover:not(:disabled){background:linear-gradient(135deg,#fff 0%,#ffe4f2 100%);box-shadow:0 6px 16px rgba(219,39,119,.12)}
.ex-btn:focus-visible{outline:2px solid rgba(219,39,119,.4);outline-offset:2px}
.ex-btn:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
.ex-flex{flex:1 1 auto}
.ex-toolbar .ex-sep{width:1px;height:26px;background:rgba(244,114,182,.2)}
.ex-breadcrumb{font-size:13px;color:#9ca3af;margin:6px 0 12px 0;display:flex;align-items:center;gap:8px}
.ex-crumb{cursor:pointer;user-select:none;padding:4px 10px;border-radius:999px;transition:background .2s ease,color .2s ease}
.ex-crumb:hover{background:rgba(244,114,182,.12);color:#be185d}
.ex-crumb.active{font-weight:600;color:#be185d;background:rgba(244,114,182,.15)}
.ex-breadcrumb .ex-sep{padding:0;color:#e5e7eb;font-size:18px;line-height:1}
.ex-content{min-height:240px;flex:1 1 auto;overflow:auto}
.ex-gridview{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px}
.ex-card{display:flex;flex-direction:column;align-items:flex-start;gap:10px;padding:16px;border:1px solid rgba(226,232,240,.8);border-radius:16px;background:rgba(255,255,255,.9);cursor:pointer;text-align:left;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.ex-card:hover{transform:translateY(-2px);box-shadow:0 12px 24px rgba(15,23,42,.08);border-color:rgba(244,114,182,.4)}
.ex-card.selected{border-color:#db2777;box-shadow:0 0 0 2px rgba(219,39,119,.15)}
.ex-ico{width:32px;height:32px}
.ex-card.dir .ex-ico{background:currentColor;mask:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M10 4l2 2h8a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2h6z"/></svg>') no-repeat 50% 50%/contain;color:#111}
.ex-card.file .ex-ico{background:currentColor;mask:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M6 2h7l5 5v13a2 2 0 01-2 2H6a2 2 0 01-2-2V4a2 2 0 012-2z"/></svg>') no-repeat 50% 50%/contain;color:#4b5563}
.ex-name{font-size:14px;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;font-weight:500}
.ex-listview{width:100%;border-collapse:collapse;background:rgba(255,255,255,.85);border-radius:14px;overflow:hidden;box-shadow:0 10px 24px rgba(15,23,42,.05)}
.ex-listview th,.ex-listview td{padding:10px 12px;border-bottom:1px solid rgba(226,232,240,.8);text-align:left;font-size:13px;color:#374151}
.ex-listview tr:hover{background:rgba(244,114,182,.08)}
.ex-listview tr.selected{background:rgba(244,114,182,.18);color:#be185d}
.ex-status{font-size:12px;color:#6b7280;margin-top:10px}
@media (max-width:640px){
  .elxao-explorer-drawer{width:100vw;padding:20px 14px}
  .ex-launch{width:100%;justify-content:center}
}
CSS
);
});

