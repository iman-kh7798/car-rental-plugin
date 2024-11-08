<?php

add_action('rest_api_init', function () {
    register_rest_route('car-rental/v1', '/cars', array(
        'methods' => 'GET',
        'callback' => 'get_rental_cars',
        'permission_callback' => '__return_true'
    ));

    register_rest_route('car-rental/v1', '/book', array(
        'methods' => 'POST',
        'callback' => 'book_rental_car',
        'permission_callback' => 'custom_permission_callback'
    ));

    register_rest_route('car-rental/v1', '/booked-dates/(?P<car_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'get_booked_dates_api',
        'permission_callback' => '__return_true', // Adjust permissions as needed
    ));

    register_rest_route('car-rental/v1', '/provinces', array(
        'methods' => 'GET',
        'callback' => 'get_provices',
        'permission_callback' => '__return_true', // Adjust permissions as needed
    ));
});