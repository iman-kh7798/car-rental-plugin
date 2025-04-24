<?php

use \Firebase\JWT\JWT;
use Firebase\JWT\Key;

function get_date_interval($start_date, $end_date)
{
    return (new DateTime($start_date))->diff(new DateTime($end_date))->days;
}

function get_authorization_header()
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            return trim($headers['Authorization']);
        }
    }

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Apache
        return trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) { // NGINX or FastCGI
        return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    return null;
}

function custom_permission_callback()
{
    $auth = get_authorization_header();

    if (!$auth || !preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
        return new WP_Error('no_token', 'توکن JWT ارسال نشده یا فرمت آن صحیح نیست.', ['status' => 403]);
    }

    $token = $matches[1];

    try {
        $decoded = JWT::decode($token, new Key(JWT_AUTH_SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        return new WP_Error('jwt_invalid', 'توکن نامعتبر است: ' . $e->getMessage(), ['status' => 403]);
    }

    $user_id = $decoded->data->user->id ?? null;

    if (!$user_id || !get_userdata($user_id)) {
        return new WP_Error('jwt_user_invalid', 'کاربر یافت نشد.', ['status' => 404]);
    }

    return true;
}

// Helper function to validate date format
function validate_date_format($date)
{
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $date);
    return $d && $d->format('Y-m-d H:i:s') === $date;
}


function getArrayItem($arr, $id)
{
    $filtered = array_filter($arr, function ($arr) use ($id) {
        return $arr['id'] === $id;
    });

    return !empty($filtered) ? array_shift($filtered) : null; // Return the first found province or null
}

function generate_tracking_id()
{
    return strtoupper(uniqid());
}
