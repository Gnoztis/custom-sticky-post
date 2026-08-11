<?php
/**
 * Plugin Name: Custom Sticky Post
 * Description: Позволяет закрепить только один пост, который будет всегда первым на всех страницах пагинации, с выделением для тёмной темы.
 * Version: 1.0
 * Author: Ваше Имя
 */

// Запрет прямого доступа
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. Добавляем метабокс в классический редактор
 */
add_action( 'add_meta_boxes', 'custom_sticky_add_meta_box' );
function custom_sticky_add_meta_box() {
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
 * 2. Сохраняем выбор (только один закреплённый)
 */
add_action( 'save_post', 'custom_sticky_save_meta_box' );
function custom_sticky_save_meta_box( $post_id ) {
    // Проверки безопасности
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! isset( $_POST['custom_sticky_nonce'] ) || ! wp_verify_nonce( $_POST['custom_sticky_nonce'], 'custom_sticky_nonce' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $is_sticky      = isset( $_POST['custom_sticky_checkbox'] ) && $_POST['custom_sticky_checkbox'] == '1';
    $current_sticky = get_option( 'custom_sticky_post_id', 0 );

    if ( $is_sticky ) {
        // Если этот пост уже закреплён – ничего не делаем
        if ( $current_sticky != $post_id ) {
            update_option( 'custom_sticky_post_id', $post_id );
        }
    } else {
        // Снимаем закрепление, если текущий пост был закреплён
        if ( $current_sticky == $post_id ) {
            delete_option( 'custom_sticky_post_id' );
        }
    }
}

/**
 * 3. Очищаем опцию при удалении закреплённого поста
 */
add_action( 'delete_post', 'custom_sticky_delete_post' );
function custom_sticky_delete_post( $post_id ) {
    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( $sticky_id == $post_id ) {
        delete_option( 'custom_sticky_post_id' );
    }
}

/**
 * 4. Изменяем главный запрос: исключаем закреплённый пост из основного списка
 */
add_action( 'pre_get_posts', 'custom_sticky_pre_get_posts' );
function custom_sticky_pre_get_posts( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( ! is_home() ) { // только главная страница блога
        return;
    }

    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( $sticky_id ) {
        $query->set( 'post__not_in', array( $sticky_id ) );
    }
}

/**
 * 5. Вставляем закреплённый пост в начало списка на каждой странице пагинации
 */
add_filter( 'the_posts', 'custom_sticky_the_posts', 10, 2 );
function custom_sticky_the_posts( $posts, $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return $posts;
    }
    if ( ! is_home() ) {
        return $posts;
    }

    $sticky_id = get_option( 'custom_sticky_post_id', 0 );
    if ( ! $sticky_id ) {
        return $posts;
    }

    // Получаем объект закреплённого поста (только опубликованный)
    $sticky_post = get_post( $sticky_id );
    if ( ! $sticky_post || $sticky_post->post_status != 'publish' ) {
        // Если пост не опубликован – чистим опцию
        delete_option( 'custom_sticky_post_id' );
        return $posts;
    }

    // Вставляем в начало массива
    array_unshift( $posts, $sticky_post );

    // Сохраняем количество постов на странице (не более posts_per_page)
    $per_page = get_option( 'posts_per_page' );
    if ( count( $posts ) > $per_page ) {
        array_pop( $posts );
    }

    return $posts;
}

/**
 * 6. Добавляем CSS-класс для закреплённого поста (для стилизации)
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
 * 7. Выводим стили для тёмной темы (только на главной странице)
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
            background-color: #2a2a2a !important;   /* тёмный фон */
            border-left: 4px solid #ffb900 !important; /* жёлтая полоса */
            padding: 15px 20px !important;
            margin-bottom: 25px !important;
            border-radius: 4px;
            color: #eee !important;
        }
        .custom-sticky-post .entry-title a,
        .custom-sticky-post .entry-title {
            color: #ffb900 !important;
        }
        .custom-sticky-post .entry-summary,
        .custom-sticky-post .entry-content {
            color: #ccc !important;
        }
        .custom-sticky-post .entry-meta {
            color: #999 !important;
        }
    </style>
    <?php
}