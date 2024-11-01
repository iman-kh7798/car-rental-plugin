<?php
/*
Plugin Name: Car Rental Plugin
Description: A custom car rental plugin for WordPress
Version: 1.0
Author: Your Name
Author URI: https://yourwebsite.com
*/

add_action('rest_api_init', function () {
    register_rest_route('car-rental/v1', '/cars', array(
        'methods' => 'GET',
        'callback' => 'get_rental_cars',
        'permission_callback' => '__return_true'
    ));

    register_rest_route('car-rental/v1', '/book', array(
        'methods' => 'POST',
        'callback' => 'book_rental_car',
        'permission_callback' => '__return_true'
    ));
});

// Register Custom Post Type for Bookings
function create_booking_post_type() {
    $labels = array(
        'name'                  => _x('Bookings', 'Post Type General Name', 'text_domain'),
        'singular_name'         => _x('Booking', 'Post Type Singular Name', 'text_domain'),
        'menu_name'             => __('Bookings', 'text_domain'),
        'name_admin_bar'        => __('Booking', 'text_domain'),
        'add_new'               => __('Add New', 'text_domain'),
        'add_new_item'          => __('Add New Booking', 'text_domain'),
        'new_item'              => __('New Booking', 'text_domain'),
        'edit_item'             => __('Edit Booking', 'text_domain'),
        'view_item'             => __('View Booking', 'text_domain'),
        'all_items'             => __('All Bookings', 'text_domain'),
        'search_items'          => __('Search Bookings', 'text_domain'),
        'not_found'             => __('No bookings found', 'text_domain'),
        'not_found_in_trash'    => __('No bookings found in Trash', 'text_domain'),
    );

    $args = array(
        'label'                 => __('Booking', 'text_domain'),
        'description'           => __('Post Type for Car Bookings', 'text_domain'),
        'labels'                => $labels,
        'supports'              => array('title', 'editor', 'custom-fields'), // Add custom fields support
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'              => true,
        'show_in_menu'          => true,
        'menu_position'         => 5,
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => true,
        'exclude_from_search'   => false,
        'publicly_queryable'    => true,
        'capability_type'       => 'post',
    );

    register_post_type('booking', $args);
}
add_action('init', 'create_booking_post_type');

// Add custom columns to the Booking post type
function set_custom_edit_booking_columns($columns) {
    $columns['start_date'] = __('Start Date', 'text_domain');
    $columns['end_date'] = __('End Date', 'text_domain');
    return $columns;
}
add_filter('manage_booking_posts_columns', 'set_custom_edit_booking_columns');

// Populate the custom columns with data
function custom_booking_column($column, $post_id) {
    switch ($column) {
        case 'start_date':
            $start_date = get_post_meta($post_id, 'start_date', true);
            echo esc_html($start_date);
            break;
        case 'end_date':
            $end_date = get_post_meta($post_id, 'end_date', true);
            echo esc_html($end_date);
            break;
    }
}
add_action('manage_booking_posts_custom_column', 'custom_booking_column', 10, 2);

function get_rental_cars() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => 'rental-cars'
            )
        )
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

function book_rental_car(WP_REST_Request $request) {
    // Validate input parameters
    $car_id = $request->get_param('car_id');
    $start_date = $request->get_param('start_date');
    $end_date = $request->get_param('end_date');

    // Check if all fields are provided
    if (empty($car_id) || empty($start_date) || empty($end_date)) {
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
    $booking_successful = create_car_booking($car_id, $start_date, $end_date);

    if ($booking_successful) {
        return new WP_REST_Response(array('message' => 'Booking successful'), 200);
    } else {
        return new WP_REST_Response(array('error' => 'Booking failed. Please try again later.'), 500);
    }
}

// Helper function to validate date format
function validate_date_format($date) {
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $date);
    return $d && $d->format('Y-m-d H:i:s') === $date;
}

function check_car_availability($car_id, $start_date = null, $end_date = null) {
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
                'relation' => 'OR',
                array(
                    'key' => 'end_date', // Assuming you have a meta field for end date
                    'value' => $start_date_obj->format('Y-m-d H:i:s'),
                    'compare' => '<', // Existing booking ends before the new booking starts
                ),
                array(
                    'key' => 'start_date', // Assuming you have a meta field for start date
                    'value' => $end_date_obj->format('Y-m-d H:i:s'),
                    'compare' => '>', // Existing booking starts after the new booking ends
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

function create_car_booking($car_id, $start_date, $end_date) {
    // Create a new booking post
    $booking_data = array(
        'post_title'   => 'Booking for Car ID ' . $car_id,
        'post_content' => 'Booking from ' . $start_date . ' to ' . $end_date,
        'post_status'  => 'publish',
        'post_type'    => 'booking',
    );

    // Insert the booking post into the database
    $booking_id = wp_insert_post($booking_data);

    // Check if the booking was created successfully
    if (is_wp_error($booking_id)) {
        return false; // Booking creation failed
    }

    // Save custom fields for the booking
    update_post_meta($booking_id, 'car_id', $car_id);
    update_post_meta($booking_id, 'start_date', $start_date);
    update_post_meta($booking_id, 'end_date', $end_date);

    return true; // Booking created successfully
}