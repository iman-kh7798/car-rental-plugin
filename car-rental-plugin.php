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
require_once __DIR__ . '/vendor/autoload.php';

use \Firebase\JWT\JWT;


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
    $tracking_id = create_car_booking($car_id, $start_date, $end_date, $province, $current_user);

    if ($tracking_id) {
        return new WP_REST_Response(array('message' => 'Booking successful', 'tracking_id' => $tracking_id), 200);
    } else {
        return new WP_REST_Response(array('error' => 'Booking failed. Please try again later.'), 500);
    }
}

function get_user_booking_details(WP_REST_Request $request)
{
    $current_user_id = get_current_user_id();

    if ($current_user_id === 0) {
        return new WP_Error('not_logged_in', 'User  is not logged in', array('status' => 401));
    }


    $args = array(
        'post_type' => 'booking', // Your custom post type
        'post_status' => 'publish', // Only get published bookings
        'meta_query' => array(
            array(
                'key' => 'user_id', // The meta key where user ID is stored
                'value' => $current_user_id,
                'compare' => '='
            )
        )
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $data = array();

        // Loop through the bookings and format the data
        foreach ($query->posts as $post) {
            $car_id = get_post_meta($post->ID, 'car_id', true);
            $product = wc_get_product($car_id);
            $attributes = $product->get_attributes(); // Get all attributes
            $attributes_list = array();

            foreach ($attributes as $attribute) {
                $name = $attribute->get_name(); // Attribute name
                $options = $attribute->get_options(); // Attribute values
                // Process or display the attribute as needed
                // Prepare an array to hold the option names
                $option_names = array();

                foreach ($options as $option) {
                    // Get the term object for the option
                    $term = get_term_by('id', $option, $attribute->get_name());

                    if ($term) {
                        $option_names[] = $term->name; // Get the term name
                    }
                }
                $attributes_list[] = array($name => $option_names);
            }
            // Check if the product exists
            if ($product) {
                $booking_data = array(
                    'id' => $post->ID,
                    'reserve_price' => get_post_meta($post->ID, 'reserve_price', true),
                    'start_date' => get_post_meta($post->ID, 'start_date', true),
                    'end_date' => get_post_meta($post->ID, 'end_date', true),
                    'province' => get_post_meta($post->ID, 'province', true),
                    'status' => get_post_meta($post->ID, 'status', true), // Assuming you have a 'status' meta key
                    'tracking_id' => get_post_meta($post->ID, 'tracking_id', true), // Assuming you have a 'tracking_id' meta key
                    'car_details' => array(
                        'id' => $product->get_id(),
                        'name' => $product->get_name(),
                        'price' => $product->get_price(),
                        'image' => wp_get_attachment_image_url($product->get_image_id(), 'full'), // Get the main image URL
                        'sku' => $product->get_sku(),
                        'attributes' => $attributes_list
                        // Add any other relevant product data you want to return
                    ),
                );
                $data[] = $booking_data;
            } else {
                // Handle the case where the product does not exist
                $data[] = array(
                    'id' => $post->ID,
                    'error' => 'Car not found for this booking.',
                );
            }
        }

        return array(
            'data' => $data,
            'message' => 'successful',
        );
    } else {
        return new WP_Error('no_booking_found', 'No bookings found for this user', array('status' => 404));
    }
}
function get_tracking_status(WP_REST_Request $request)
{
    $tracking_id = $request->get_param('tracking_id');

    if (empty($tracking_id)) {
        return new WP_Error('no_tracking_id', 'Tracking ID is required', array('status' => 400));
    }

    $args = array(
        'post_type' => 'booking', // Your custom post type
        'meta_query' => array(
            array(
                'key' => 'tracking_id', // The meta key where tracking ID is stored
                'value' => $tracking_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1 // Limit to one result
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $booking = $query->posts[0]; // Get the first booking found
        $status = get_post_meta($booking->ID, 'status', true); // Assuming you have a 'status' meta key

        return array(
            'tracking_id' => $tracking_id,
            'status' => $status,
            'message' => 'Booking found.',
        );
    } else {
        return new WP_Error('no_booking_found', 'No booking found with that Tracking ID', array('status' => 404));
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

function create_car_booking($car_id, $start_date, $end_date, $province, $current_user)
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

    $tracking_id = generate_tracking_id();
    // Save custom fields for the booking
    update_post_meta($booking_id, 'car_id', $car_id);
    update_post_meta($booking_id, 'car_name', $product->get_name());
    update_post_meta($booking_id, 'reserve_price', calculate_price_per_day($start_date, $end_date, $product->get_price()));
    update_post_meta($booking_id, 'start_date', $start_date);
    update_post_meta($booking_id, 'end_date', $end_date);
    update_post_meta($booking_id, 'province', $province_details);
    update_post_meta($booking_id, 'status', 'pending');
    update_post_meta($booking_id, 'tracking_id', $tracking_id);
    update_post_meta($booking_id, 'user_id', $current_user->ID);

    return $tracking_id; // Booking created successfully
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

function custom_user_registration(WP_REST_Request $request)
{
    $username = sanitize_user($request->get_param('username'));
    $password = sanitize_text_field($request->get_param('password'));
    $first_name  = sanitize_text_field($request->get_param('first_name'));
    $last_name  = sanitize_text_field($request->get_param('last_name'));
    $cellphone  = sanitize_text_field($request->get_param('cellphone'));
    $national_code = sanitize_text_field($request->get_param('national_code'));
    $email = sanitize_email($request->get_param('email'));
    $reside_outside_iran = filter_var($request->get_param('reside_outside_iran'), FILTER_VALIDATE_BOOLEAN);
    // Validate email format
    if (!filter_var($request->get_param('email'), FILTER_VALIDATE_EMAIL)) {
        return new WP_Error('invalid_email', 'Email format is invalid. Please provide a valid email address.', array('status' => 400));
    }

    if (empty($username) || empty($password) || empty($email)) {
        return new WP_Error('missing_fields', 'Please provide all required fields.', array('status' => 400));
    }

    if (username_exists($username) || email_exists($email)) {
        return new WP_Error('user_exists', 'Username or email already exists.', array('status' => 400));
    }

    $user_id = wp_create_user($username, $password, $email);

    if (is_wp_error($user_id)) {
        return new WP_Error('registration_failed', 'User  registration failed.', array('status' => 500));
    }

    // Optionally, you can set the user role
    $user = new WP_User($user_id);
    $user->set_role('subscriber');
    if ($first_name) {
        update_user_meta($user_id, 'first_name', $first_name);
    }

    if ($last_name) {
        update_user_meta($user_id, 'last_name', $last_name);
    }

    if ($national_code) {
        update_user_meta($user_id, 'national_code', $national_code);
    }

    if ($cellphone) {
        update_user_meta($user_id, 'cellphone', $cellphone);
    }
    update_user_meta($user_id, 'reside_outside_iran', $reside_outside_iran ? 1 : 0);

    wp_update_user([
        'ID' => $user_id,
        'display_name' => trim($first_name . ' ' . $last_name),
    ]);

    // Generate JWT token
    $token = generate_jwt_token($user_id);

    return array(
        'token' => $token,
        'user_id' => $user_id,
    );
}

function generate_jwt_token($user_id)
{
    $secret_key = "dasf322fewrf2q3fsdfw23r"; // Replace with your secret key
    $issuer = get_bloginfo('url'); // Issuer
    $audience = get_bloginfo('url'); // Audience
    $issued_at = time(); // Issued at
    $expiration_time = $issued_at + (DAY_IN_SECONDS * 7); // jwt valid for 1 week
    $user = get_userdata($user_id);
    $payload = array(
        'iat' => $issued_at,
        'exp' => $expiration_time,
        'iss' => $issuer,
        'aud' => $audience,
        'data' => array(
            'user' => array(
                'id' => $user_id,
                'email' => $user->user_email,
                'first_name' => get_user_meta($user_id, 'first_name', true),
                'last_name' => get_user_meta($user_id, 'last_name', true),
                'cellphone' => get_user_meta($user_id, 'cellphone', true),
                'national_code' => get_user_meta($user_id, 'national_code', true),
                'reside_outside_iran' => (bool) get_user_meta($user_id, 'reside_outside_iran', true),
            )
        ),
    );

    $jwt = JWT::encode($payload, $secret_key, 'HS256');
    return $jwt;
}

function get_current_user_from_token(WP_REST_Request $request)
{
    $auth = $request->get_header('authorization');

    if (!$auth || !preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
        return new WP_Error('no_token', 'توکن ارسال نشده یا فرمت آن صحیح نیست.', ['status' => 403]);
    }

    $token = $matches[1];

    try {
        $decoded = JWT::decode($token, new Key("dasf322fewrf2q3fsdfw23r", 'HS256'));
    } catch (Exception $e) {
        return new WP_Error('invalid_token', 'توکن نامعتبر است: ' . $e->getMessage(), ['status' => 403]);
    }

    $user_id = $decoded->data->user->id ?? null;

    if (!$user_id || !get_userdata($user_id)) {
        return new WP_Error('invalid_user', 'کاربر یافت نشد.', ['status' => 404]);
    }

    return [
        'id' => $user_id,
        'username' => $decoded->data->user->username ?? '',
        'email' => $decoded->data->user->email ?? '',
        'first_name' => $decoded->data->user->first_name ?? '',
        'last_name' => $decoded->data->user->last_name ?? '',
        'cellphone' => $decoded->data->user->cellphone ?? '',
        'national_code' => $decoded->data->user->national_code ?? '',
        'reside_outside_iran' => $decoded->data->user->reside_outside_iran ?? false,
    ];
}


// require_once plugin_dir_path(__FILE__) . 'includes/admin-functions.php';
require_once plugin_dir_path(__FILE__) . 'routes.php';
