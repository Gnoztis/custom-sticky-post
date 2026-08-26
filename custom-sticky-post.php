<?php
/**
 * Plugin Name: Custom Sticky Post
 * Description: Закрепление одного поста (только для админов). Тёмный фон, красная полоса, тонкая рамка по периметру.
 * Version: 2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
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
    echo 'Закрепить этот пост (только один)';
    echo '</label>';
}

/**
 * 2. Сохранение (только админ)
 */
add_action( 'save_post', 'custom_sticky_save_meta_box' );
function custom_sticky_save_meta_box( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! isset( $_POST['custom_sticky_nonce'] ) || ! wp_verify_nonce( $_POST['custom_sticky_nonce'], 'custom_sticky_nonce' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $is_sticky      = isset( $_POST['custom_sticky_checkbox'] ) && $_POST['custom_sticky_checkbox'] == '1';
    $current_sticky = get_option( 'custom_sticky_post_id', 0 );

    if ( $is_sticky ) {
        if ( $current_sticky != $post_id ) {
            update_option( 'custom_sticky_post_id', $post_id );
        }
    } else {
        if ( $current_sticky == $post_id ) {
            delete_option( 'custom_sticky_post_id' );
        }
    }
}

/**
 * 3. Очистка при удалении
 */
add_action( 'delete_post', 'custom_sticky_delete_post' );
function custom_sticky_delete_post( $post_id ) {
    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( $sticky_id == $post_id ) {
        delete_option( 'custom_sticky_post_id' );
    }
}

/**
 * 4. Исключаем закреплённый пост из основного запроса
 */
add_action( 'pre_get_posts', 'custom_sticky_pre_get_posts' );
function custom_sticky_pre_get_posts( $query ) {
    if ( is_admin() || ! $query->is_main_query() || ! is_home() ) {
        return;
    }
    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( $sticky_id ) {
        $query->set( 'post__not_in', array( $sticky_id ) );
    }
}

/**
 * 5. Вставляем закреплённый пост в начало (на всех страницах пагинации)
 */
add_filter( 'the_posts', 'custom_sticky_the_posts', 10, 2 );
function custom_sticky_the_posts( $posts, $query ) {
    if ( is_admin() || ! $query->is_main_query() || ! is_home() ) {
        return $posts;
    }
    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( ! $sticky_id ) {
        return $posts;
    }
    $sticky_post = get_post( $sticky_id );
    if ( ! $sticky_post || $sticky_post->post_status != 'publish' ) {
        delete_option( 'custom_sticky_post_id' );
        return $posts;
    }
    array_unshift( $posts, $sticky_post );
    $per_page = get_option( 'posts_per_page' );
    if ( count( $posts ) > $per_page ) {
        array_pop( $posts );
    }
    return $posts;
}

/**
 * 6. Добавляем класс custom-sticky-post
 */
add_filter( 'post_class', 'custom_sticky_post_class', 10, 3 );
function custom_sticky_post_class( $classes, $class, $post_id ) {
    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( $sticky_id == $post_id ) {
        $classes[] = 'custom-sticky-post';
    }
    return $classes;
}

/**
 * 7. Скрываем миниатюру только на главной (но не в single)
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
 * 8. Стили (только на главной) — добавлена рамка
 */
add_action( 'wp_head', 'custom_sticky_styles' );
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
        .custom-sticky-post {
            /* Выберите фон: #1a1a1a (почти чёрный) или #222222 (чуть светлее) */
            background-color: #1a1a1a !important;
            /* Рамка вокруг всего блока — полупрозрачная, не нарушает тёмную тему */
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            /* Красная полоса слева — поверх рамки */
            border-left: 6px solid #d9534f !important;
            padding: 15px 20px !important;
            margin-bottom: 25px !important;
            border-radius: 4px;
            color: #eee !important;
            overflow: hidden !important;
            word-wrap: break-word !important;
            box-sizing: border-box !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.5) !important; /* чуть усиленная тень */
        }
        .custom-sticky-post .post-card__title a,
        .custom-sticky-post .post-card__title {
            color: inherit !important;
        }
        .custom-sticky-post .post-card__content {
            color: #ccc !important;
        }
        .custom-sticky-post .post-meta {
            color: #999 !important;
        }
    </style>
    <?php
}