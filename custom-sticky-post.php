<?php
/**
 * Plugin Name: Custom Sticky Post
 * Description: Закрепление одного поста (только для админов). Совместим с PHP 8.4+ и WordPress 7.0+.
 * Version: 3.4
 * Requires PHP: 8.4
 * Requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Проверка минимальных версий при активации
 */
register_activation_hook( __FILE__, 'custom_sticky_check_requirements' );
function custom_sticky_check_requirements() {
    if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'Плагин требует PHP 8.4 или выше.' );
    }
    if ( version_compare( get_bloginfo( 'version' ), '7.0', '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'Плагин требует WordPress 7.0 или выше.' );
    }
}

/**
 * 1. Метабокс только для администраторов
 */
add_action( 'add_meta_boxes', 'custom_sticky_add_meta_box' );
function custom_sticky_add_meta_box() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    add_meta_box(
        'custom_sticky_meta',
        'Закреплённый пост',
        'custom_sticky_meta_box_callback',
        'post',
        'side',
        'high'
    );
}

function custom_sticky_meta_box_callback( $post ) {
    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    $checked   = ( $sticky_id == $post->ID ) ? 'checked' : '';
    wp_nonce_field( 'custom_sticky_nonce', 'custom_sticky_nonce' );
    echo '<label>';
    echo '<input type="checkbox" name="custom_sticky_checkbox" value="1" ' . $checked . ' /> ';
    echo 'Закрепить этот пост'; // Убран текст "(только один)"
    echo '</label>';
}

/**
 * 2. Сохранение (только админ) с санитизацией входных данных
 */
add_action( 'save_post', 'custom_sticky_save_meta_box' );
function custom_sticky_save_meta_box( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Санитизация nonce
    if ( ! isset( $_POST['custom_sticky_nonce'] ) ) {
        return;
    }
    $nonce = sanitize_text_field( wp_unslash( $_POST['custom_sticky_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'custom_sticky_nonce' ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Санитизация checkbox
    $is_sticky = isset( $_POST['custom_sticky_checkbox'] )
        ? sanitize_text_field( wp_unslash( $_POST['custom_sticky_checkbox'] ) ) === '1'
        : false;

    $current_sticky = get_option( 'custom_sticky_post_id', 0 );

    if ( $is_sticky && $current_sticky != $post_id ) {
        update_option( 'custom_sticky_post_id', $post_id );
        custom_sticky_update_post_modified( $post_id );
        if ( $current_sticky ) {
            custom_sticky_update_post_modified( $current_sticky );
        }
    } elseif ( ! $is_sticky && $current_sticky == $post_id ) {
        delete_option( 'custom_sticky_post_id' );
        custom_sticky_update_post_modified( $post_id );
    }
}

/**
 * Вспомогательная функция: обновляет post_modified и post_modified_gmt
 * Использует current_datetime() — современный API WordPress
 */
function custom_sticky_update_post_modified( $post_id ) {
    global $wpdb;
    $now = current_datetime();
    $wpdb->update(
        $wpdb->posts,
        array(
            'post_modified'     => $now->format( 'Y-m-d H:i:s' ),
            'post_modified_gmt' => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        ),
        array( 'ID' => $post_id ),
        array( '%s', '%s' ),
        array( '%d' )
    );
    clean_post_cache( $post_id );
}

/**
 * 3. Обновляем post_modified при публикации любого поста
 */
add_action( 'transition_post_status', 'custom_sticky_transition_post_status', 10, 3 );
function custom_sticky_transition_post_status( $new_status, $old_status, $post ) {
    if ( 'publish' === $new_status && 'publish' !== $old_status ) {
        custom_sticky_update_post_modified( $post->ID );
    }
}

/**
 * 4. Очистка опции при удалении поста
 */
add_action( 'delete_post', 'custom_sticky_delete_post' );
function custom_sticky_delete_post( $post_id ) {
    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( $sticky_id == $post_id ) {
        delete_option( 'custom_sticky_post_id' );
    }
}

/**
 * 5. Скрываем миниатюру только на главной
 */
add_filter( 'post_thumbnail_html', 'custom_sticky_hide_thumbnail_on_home', 10, 3 );
function custom_sticky_hide_thumbnail_on_home( $html, $post_id, $post_thumbnail_id ) {
    if ( is_home() ) {
        $sticky_id = get_option( 'custom_sticky_post_id', 0 );
        if ( $sticky_id == $post_id ) {
            return '';
        }
    }
    return $html;
}

/**
 * 6. Вывод закреплённого поста отдельным запросом
 */
add_action( 'loop_start', 'custom_sticky_override_loop_start' );
function custom_sticky_override_loop_start( $query ) {
    if ( ! is_home() || ! $query->is_main_query() ) {
        return;
    }

    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( ! $sticky_id ) {
        return;
    }

    $sticky_post = get_post( $sticky_id );
    if ( ! $sticky_post || $sticky_post->post_status != 'publish' ) {
        delete_option( 'custom_sticky_post_id' );
        return;
    }

    $original_posts = $query->posts;
    $filtered_posts = array_filter( $original_posts, function( $post ) use ( $sticky_id ) {
        return $post->ID != $sticky_id;
    } );

    $new_posts = array_merge( array( $sticky_post ), $filtered_posts );
    $query->posts = $new_posts;
    $query->post_count = count( $new_posts );
}

/**
 * 7. Добавляем класс custom-sticky-post
 */
add_filter( 'post_class', 'custom_sticky_post_class', 999, 3 );
function custom_sticky_post_class( $classes, $class, $post_id ) {
    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( $sticky_id == $post_id ) {
        if ( ! in_array( 'custom-sticky-post', $classes, true ) ) {
            $classes[] = 'custom-sticky-post';
        }
    }
    return $classes;
}

/**
 * 8. Стили с максимальным приоритетом
 */
add_action( 'wp_head', 'custom_sticky_styles', 9999 );
function custom_sticky_styles() {
    if ( ! is_home() ) {
        return;
    }
    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( ! $sticky_id ) {
        return;
    }
    ?>
    <style>
        body.home #primary .post-cards .custom-sticky-post,
        body.home .post-cards .custom-sticky-post {
            background-color: #1a1a1a !important;
            border-left: 6px solid #d9534f !important;
            padding: 15px 20px !important;
            margin-bottom: 25px !important;
            border-radius: 4px !important;
            color: #eee !important;
            overflow: hidden !important;
            word-wrap: break-word !important;
            box-sizing: border-box !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.4) !important;
        }
        body.home .custom-sticky-post .post-card__title a,
        body.home .custom-sticky-post .post-card__title {
            color: inherit !important;
        }
        body.home .custom-sticky-post .post-card__content {
            color: #ccc !important;
        }
        body.home .custom-sticky-post .post-meta {
            color: #999 !important;
        }
    </style>
    <?php
}