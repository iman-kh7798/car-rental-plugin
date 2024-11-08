<?php
/*
Plugin Name: Car Rental Plugin
Description: A custom car rental plugin for WordPress
Version: 1.0
Author: Your Name
Author URI: https://yourwebsite.com
*/

// Define constants
define('CAR_RENTAL_PLUGIN_VERSION', '1.0');
define('CAR_RENTAL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CAR_RENTAL_PLUGIN_URL', plugin_dir_url(__FILE__));

include  plugin_dir_path(__FILE__) . 'includes/constants.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-config.php';
require_once plugin_dir_path(__FILE__) . 'includes/users-cellphone.php';




function get_rental_cars()
{
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
    );

    $cars = get_posts($args);

    if (empty($cars)) {
        return new WP_REST_Response(array(
            'error' => 'No rental cars found.'
        ), 404);
    }

    $formatted_cars = array();
    foreach ($cars as $car) {
        $product = wc_get_product($car->ID);
        if (!$product) {
            continue; // Skip if product is not found
        }
        $formatted_cars[] = array(
            'id' => $car->ID,
            'title' => $car->post_title,
            'description' => $car->post_content,
            'price' => $product->get_price(),
            'image' => get_the_post_thumbnail_url($car->ID, 'full'),
            'availability' => check_car_availability($car->ID)
        );
    }

    return new WP_REST_Response($formatted_cars, 200);
}

function calculate_price_per_day($start_date, $end_date, $daily_price)
{
    $price_per_day = ($daily_price == 0) ? 1 : ceil($daily_price);
    $start_date_obj  = new DateTime($start_date);
    $end_date_obj  = new DateTime($end_date);
    $days_interval = $start_date_obj->diff($end_date_obj)->days;
    return ceil($price_per_day) * $days_interval;
}

function book_rental_car(WP_REST_Request $request)
{
    // Validate input parameters
    $car_id = $request->get_param('car_id');
    $start_date = $request->get_param('start_date');
    $end_date = $request->get_param('end_date');
    $province = $request->get_param('province');
    $current_user = wp_get_current_user();

    if (empty(get_user_meta($current_user->ID, 'cellphone', true))) {
        return new WP_REST_Response(array(
            'error' => 'cellphone-not-found'
        ), 400);
    }

    // Check if all fields are provided
    if (empty($car_id) || empty($start_date) || empty($end_date) || empty($province)) {
        return new WP_REST_Response(array(
            'error' => 'All fields are required.'
        ), 400);
    }

    // Validate date format
    if (!validate_date_format($start_date) || !validate_date_format($end_date)) {
        return new WP_REST_Response(array(
            'error' => 'Invalid date format. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.'
        ), 400);
    }

    // Create DateTime objects
    $start_date_obj = new DateTime($start_date);
    $end_date_obj = new DateTime($end_date);
    $current_date_obj = new DateTime(); // Get current date and time

    // Check if the start date is in the past
    if ($start_date_obj < $current_date_obj) {
        return new WP_REST_Response(array(
            'error' => 'The start date cannot be in the past.'
        ), 400);
    }

    // Compare start and end dates
    if ($start_date_obj >= $end_date_obj) {
        return new WP_REST_Response(array(
            'error' => 'The end date must be later than the start date.'
        ), 400);
    }

    // Check availability
    if (!check_car_availability($car_id, $start_date, $end_date)) {
        return new WP_REST_Response(array(
            'error' => 'Car is not available for the selected dates.'
        ), 400);
    }

    // Perform booking logic here
    $booking_successful = create_car_booking($car_id, $start_date, $end_date, $province);

    if ($booking_successful) {
        return new WP_REST_Response(array('message' => 'Booking successful'), 200);
    } else {
        return new WP_REST_Response(array('error' => 'Booking failed. Please try again later.'), 500);
    }
}

function check_car_availability($car_id, $start_date = null, $end_date = null)
{
    // If no dates are provided, we assume we are checking availability for the current date
    if ($start_date === null || $end_date === null) {
        return true; // No need to check availability if no dates are provided
    }

    // Convert the start and end date to DateTime objects for comparison
    $start_date_obj = new DateTime($start_date);
    $end_date_obj = new DateTime($end_date);

    // Query to get existing bookings for the car
    $args = array(
        'post_type' => 'booking', // Use the custom post type for bookings
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => 'car_id', // Assuming you have a meta field for car ID in bookings
                'value' => $car_id,
                'compare' => '='
            ),
            array(
                'relation' => 'AND',
                array(
                    'key' => 'end_date', // Existing booking ends after the new booking starts
                    'value' => $end_date_obj->format('Y-m-d H:i:s'),
                    'compare' => '<=', // Existing booking ends after the new booking starts
                ),
                array(
                    'key' => 'start_date', // Existing booking starts before the new booking ends
                    'value' =>  $start_date_obj->format('Y-m-d H:i:s'),
                    'compare' => '>=', // Existing booking starts before the new booking ends
                ),
            ),
        ),
    );

    // Execute the query
    $existing_bookings = get_posts($args);

    // If there are no existing bookings, the car is available
    if (empty($existing_bookings)) {
        return true;
    }

    // If we have bookings that overlap, the car is not available
    return false;
}

function create_car_booking($car_id, $start_date, $end_date, $province)
{
    global $iran_provinces;
    $product = wc_get_product($car_id);
    // Create a new booking post
    $booking_data = array(
        'post_title'   => 'درخواست رزرو ' . $product->get_name(),
        'post_content' => 'درخواست رزرو از تاریخ ' . $start_date . ' به ' . $end_date,
        'post_status'  => 'publish',
        'post_type'    => 'booking',
    );



    // Insert the booking post into the database
    $booking_id = wp_insert_post($booking_data);

    // Check if the booking was created successfully
    if (is_wp_error($booking_id)) {
        return false; // Booking creation failed
    }

    $province_details = getArrayItem($iran_provinces, intval($province));

    if ($province_details == null) {
        return false;
    }

    // Save custom fields for the booking
    update_post_meta($booking_id, 'car_id', $car_id);
    update_post_meta($booking_id, 'car_name', $product->get_name());
    update_post_meta($booking_id, 'reserve_price', calculate_price_per_day($start_date, $end_date, $product->get_price()));
    update_post_meta($booking_id, 'start_date', $start_date);
    update_post_meta($booking_id, 'end_date', $end_date);
    update_post_meta($booking_id, 'province', $province_details);

    return true; // Booking created successfully
}

function get_booked_dates($car_id)
{
    // Query to get existing bookings for the car
    $args = array(
        'post_type' => 'booking',
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => 'car_id',
                'value' => $car_id,
                'compare' => '='
            ),
        ),
        'posts_per_page' => -1, // Get all bookings
    );

    $bookings = get_posts($args);
    $booked_dates = array();

    // Loop through each booking and collect the dates
    foreach ($bookings as $booking) {
        $start_date = get_post_meta($booking->ID, 'start_date', true);
        $end_date = get_post_meta($booking->ID, 'end_date', true);

        // Convert dates to DateTime objects
        $start_date_obj = new DateTime($start_date);
        $end_date_obj = new DateTime($end_date);

        // Create an interval of dates from start to end
        $interval = new DateInterval('P1D'); // 1 day interval
        $period = new DatePeriod($start_date_obj, $interval, $end_date_obj->modify('+1 day')); // Include end date

        // Add each date to the booked_dates array
        foreach ($period as $date) {
            $booked_dates[] = $date->format('Y-m-d'); // Format the date as needed
        }
    }

    return array_unique($booked_dates); // Return unique booked dates
}

// Callback function to retrieve booked dates
function get_booked_dates_api($data)
{
    $car_id = $data['car_id'];
    $booked_dates = get_booked_dates($car_id); // Use the previously defined function
    return rest_ensure_response($booked_dates);
}

function get_provices()
{
    global $iran_provinces;
    return new WP_REST_Response($iran_provinces, 200);
}

// require_once plugin_dir_path(__FILE__) . 'includes/admin-functions.php';
require_once plugin_dir_path(__FILE__) . 'routes.php';
