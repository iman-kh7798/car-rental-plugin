<?php

function get_date_interval($start_date, $end_date)
{
    return (new DateTime($start_date))->diff(new DateTime($end_date))->days;
}

function custom_permission_callback()
{
    if (!is_user_logged_in()) {
        return new WP_Error('rest_forbidden', __('You must be logged in to book a car.'), array('status' => 401));
    }
    return true; // User is logged in
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
