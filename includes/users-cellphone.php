<?php

function add_cellphone_field($user)
{
?>
    <h3><?php _e("اطلاعات کاربر", "my_domain"); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="cellphone"><?php _e("شماره موبایل"); ?></label></th>
            <td>
                <input type="text" name="cellphone" id="cellphone" value="<?php echo esc_attr(get_the_author_meta('cellphone', $user->ID)); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><label for="national_code"><?php _e("کد ملی"); ?></label></th>
            <td>
                <input type="text" name="national_code" id="national_code" value="<?php echo esc_attr(get_the_author_meta('national_code', $user->ID)); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><label for="reside_outside_iran"><?php _e("مقیم خارج از ایران هستم"); ?></label></th>
            <td>
                <label><input type="checkbox" name="reside_outside_iran" id="reside_outside_iran" value="1" <?php checked(get_the_author_meta('reside_outside_iran', $user->ID), 1); ?> />
                    بله</label>
            </td>
        </tr>
    </table>
<?php
}

function save_cellphone_field($user_id)
{
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    update_user_meta($user_id, 'cellphone', sanitize_text_field($_POST['cellphone']));
    update_user_meta($user_id, 'national_code', sanitize_text_field($_POST['national_code']));
    update_user_meta($user_id, 'reside_outside_iran', isset($_POST['reside_outside_iran']) ? 1 : 0);
}

add_action('personal_options_update', 'save_cellphone_field');
add_action('edit_user_profile_update', 'save_cellphone_field');
add_action('show_user_profile', 'add_cellphone_field');
add_action('edit_user_profile', 'add_cellphone_field');
