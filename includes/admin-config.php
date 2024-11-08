<?php


// Register Custom Post Type for Bookings
function create_booking_post_type()
{
    $labels = array(
        'name'                  => _x('رزروهای ماشین', 'Post Type General Name', 'text_domain'),
        'singular_name'         => _x('رزرو ماشین', 'Post Type Singular Name', 'text_domain'),
        'menu_name'             => __('رزرو ماشین', 'text_domain'),
        'name_admin_bar'        => __('رزرو ماشین', 'text_domain'),
        'add_new'               => __('افزودن رزرو ماشین', 'text_domain'),
        'add_new_item'          => __('افزودن رزرو ماشین', 'text_domain'),
        'new_item'              => __('رزرو ماشین جدید', 'text_domain'),
        'edit_item'             => __('ویرایش رزرو ماشین', 'text_domain'),
        'view_item'             => __('نمایش رزرو ماشین', 'text_domain'),
        'all_items'             => __('همه رزروها', 'text_domain'),
        'search_items'          => __('جستجو در رزروها', 'text_domain'),
        'not_found'             => __('هیچ رزروی پیدا نشد', 'text_domain'),
        'not_found_in_trash'    => __('هیچ رزروی در زباله دان وجود ندارد', 'text_domain'),
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
function set_custom_edit_booking_columns($columns)
{
    $columns['car_name'] = __('نام ماشین', 'text_domain');
    $columns['reserve_price'] = __('قیمت رزرو', 'text_domain');
    $columns['province'] = __('استان', 'text_domain');
    $columns['start_date'] = __('تاریخ شروع', 'text_domain');
    $columns['end_date'] = __('تاریخ پایان', 'text_domain');
    return $columns;
}

add_filter('manage_booking_posts_columns', 'set_custom_edit_booking_columns');

// Populate the custom columns with data
function custom_booking_column($column, $post_id)
{
    $car_name = get_post_meta($post_id, 'car_name', true);
    $reserve_price = get_post_meta($post_id, 'reserve_price', true);
    $province = get_post_meta($post_id, 'province', true);
    $start_date = get_post_meta($post_id, 'start_date', true);
    $end_date = get_post_meta($post_id, 'end_date', true);
    $date_interval = get_date_interval($start_date, $end_date);

    switch ($column) {
        case 'car_name':
            echo esc_html($car_name);
            break;
        case 'reserve_price':
            echo esc_html($date_interval . "/" . $reserve_price . ' روز');
            break;
        case 'province':
            echo esc_html($province['name']);
            break;
        case 'start_date':
            echo esc_html($start_date);
            break;
        case 'end_date':
            echo esc_html($end_date);
            break;
    }
}

add_action('manage_booking_posts_custom_column', 'custom_booking_column', 10, 2);
