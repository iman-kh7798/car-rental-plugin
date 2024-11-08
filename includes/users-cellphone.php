<?php

function add_cellphone_field($user)
{
?>
    <h3><?php _e("اطلاعات تماس کاربر", "my_domain"); ?></h3>

    <table class="form-table">
        <tr>
            <th><label for="cellphone"><?php _e("شماره موبایل"); ?></label></th>
            <td>
                <input type="text" name="cellphone" id="cellphone" value="<?php echo esc_attr(get_the_author_meta('cellphone', $user->ID)); ?>" class="regular-text" /><br />
                <!-- <span class="description"> -->
                <!-- <?php _e("لطفا شماره تلفن همراه خود را وارد کنید."); ?> -->
                <!-- </span> -->
            </td>
        </tr>
    </table>
<?php
}

function save_cellphone_field($user_id)
{
    // Check if the current user has permission to edit users
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Update the cellphone field
    update_user_meta($user_id, 'cellphone', sanitize_text_field($_POST['cellphone']));
}

add_action('personal_options_update', 'save_cellphone_field');
add_action('edit_user_profile_update', 'save_cellphone_field');
add_action('show_user_profile', 'add_cellphone_field');
add_action('edit_user_profile', 'add_cellphone_field');
