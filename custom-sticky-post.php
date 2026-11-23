<?php
/**
 * Plugin Name: Custom Sticky Post
 * Description: Закрепление одного поста (только для админов). Совместим с PHP 8.4+ и WordPress 7.0+.
 * Version:     3.5.4
 * Author:      Gnostis
 * Author URI:  https://github.com/Gnoztis
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: custom-sticky-post
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Update URI:  https://github.com/Gnoztis/custom-sticky-post
 *
 * @package CustomStickyPost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Plugin constants. The version is declared once here and repeated only in the
 * plugin header above; both must match the release tag.
 */
define( 'CUSTOM_STICKY_VERSION', '3.5.4' );
define( 'CUSTOM_STICKY_PLUGIN_FILE', __FILE__ );
define( 'CUSTOM_STICKY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CUSTOM_STICKY_PLUGIN_DIR . 'includes/class-csp-github-updater.php';

/**
 * Requirements check.
 *
 * The activation hook is the wrong place for this: when it runs the plugin is
 * not in the active_plugins option yet, so deactivate_plugins() does nothing,
 * and wp_die() leaves a half-activated plugin behind. WordPress validates the
 * "Requires PHP" / "Requires at least" headers itself on activation, so this
 * is only a safety net for an environment where that check was bypassed: on
 * admin_init the plugin deactivates itself and shows a notice instead of
 * interrupting every request with wp_die().
 */
add_action( 'admin_init', 'custom_sticky_check_requirements' );
function custom_sticky_check_requirements() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( custom_sticky_requirements_met() ) {
		return;
	}

	deactivate_plugins( plugin_basename( __FILE__ ) );
	add_action( 'admin_notices', 'custom_sticky_requirements_notice' );
}

/**
 * Whether the site runs a supported PHP and WordPress version.
 *
 * @return bool
 */
function custom_sticky_requirements_met() {
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		return false;
	}

	return version_compare( (string) get_bloginfo( 'version' ), '7.0', '>=' );
}

/**
 * Notice shown after the plugin deactivated itself.
 */
function custom_sticky_requirements_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Плагин Custom Sticky Post требует PHP 8.0 или выше и WordPress 7.0 или выше.', 'custom-sticky-post' )
	);
}

/**
 * 1. Metabox, administrators only.
 */
add_action( 'add_meta_boxes', 'custom_sticky_add_meta_box' );
function custom_sticky_add_meta_box() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	add_meta_box(
		'custom_sticky_meta',
		esc_html__( 'Закреплённый пост', 'custom-sticky-post' ),
		'custom_sticky_meta_box_callback',
		'post',
		'side',
		'high'
	);
}

/**
 * Renders the metabox.
 *
 * The capability is checked again inside the callback: add_meta_box() already
 * hides the box from non-admins, but the callback is a public function that a
 * screen could reach through another registration. The checked attribute is
 * produced by checked(), so WordPress escapes it instead of it being
 * concatenated into the markup.
 *
 * @param WP_Post $post Post being edited.
 */
function custom_sticky_meta_box_callback( $post ) {
	if ( ! ( $post instanceof WP_Post ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$sticky_id = (int) get_option( 'custom_sticky_post_id', 0 );

	wp_nonce_field( 'custom_sticky_nonce', 'custom_sticky_nonce' );
	?>
	<label>
		<input type="checkbox" name="custom_sticky_checkbox" value="1" <?php checked( $sticky_id, (int) $post->ID ); ?> />
		<?php echo esc_html__( 'Закрепить этот пост', 'custom-sticky-post' ); ?>
	</label>
	<?php
}

/**
 * 2. Saves the choice (administrators only).
 *
 * Order matters: cheap context guards first, then the capability, then the
 * nonce. A request without the nonce (REST, Quick Edit, autosave, revisions)
 * never touches the option — the checkbox is not part of those forms. The
 * nonce is verified before any write, so a forged request cannot move the
 * sticky post either.
 *
 * @param int $post_id Post being saved.
 */
add_action( 'save_post', 'custom_sticky_save_meta_box' );
function custom_sticky_save_meta_box( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$post_id = absint( $post_id );
	if ( $post_id <= 0 || 'post' !== get_post_type( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( ! isset( $_POST['custom_sticky_nonce'] ) ) {
		return;
	}
	$nonce = sanitize_text_field( wp_unslash( $_POST['custom_sticky_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'custom_sticky_nonce' ) ) {
		return;
	}

	$is_sticky = isset( $_POST['custom_sticky_checkbox'] )
		&& '1' === sanitize_text_field( wp_unslash( $_POST['custom_sticky_checkbox'] ) );

	$current_sticky = (int) get_option( 'custom_sticky_post_id', 0 );

	if ( $is_sticky && $current_sticky !== $post_id ) {
		update_option( 'custom_sticky_post_id', $post_id );
		custom_sticky_update_post_modified( $post_id );
		if ( $current_sticky > 0 ) {
			custom_sticky_update_post_modified( $current_sticky );
		}
	} elseif ( ! $is_sticky && $current_sticky === $post_id ) {
		delete_option( 'custom_sticky_post_id' );
		custom_sticky_update_post_modified( $post_id );
	}
}

/**
 * Bumps post_modified and post_modified_gmt of a post.
 *
 * Written straight to $wpdb->posts on purpose: wp_update_post() would fire
 * save_post again, re-enter the handler above and run revisions and content
 * filters for what is only a modification date. The ID is cast, both values
 * are formatted here and bound with explicit types, and the post cache is
 * invalidated afterwards so the change is visible to the rest of the request.
 *
 * @param int $post_id Post ID.
 */
function custom_sticky_update_post_modified( $post_id ) {
	$post_id = absint( $post_id );
	if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! ( get_post( $post_id ) instanceof WP_Post ) ) {
		return;
	}

	global $wpdb;

	$now = current_datetime();
	$utc = $now->setTimezone( new DateTimeZone( 'UTC' ) );

	$wpdb->update(
		$wpdb->posts,
		array(
			'post_modified'     => $now->format( 'Y-m-d H:i:s' ),
			'post_modified_gmt' => $utc->format( 'Y-m-d H:i:s' ),
		),
		array( 'ID' => $post_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	clean_post_cache( $post_id );
}

/**
 * 3. Bumps the modification date on publish and drops the option when the
 * sticky post stops being published.
 *
 * Without the cleanup on unpublish the option keeps pointing at a draft or a
 * trashed post until some front-end request notices it, so the home page can
 * briefly render a stale reference.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Previous status.
 * @param WP_Post $post       Post object.
 */
add_action( 'transition_post_status', 'custom_sticky_transition_post_status', 10, 3 );
function custom_sticky_transition_post_status( $new_status, $old_status, $post ) {
	if ( ! ( $post instanceof WP_Post ) || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
		return;
	}

	$sticky_id = (int) get_option( 'custom_sticky_post_id', 0 );

	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		custom_sticky_update_post_modified( $post->ID );
	}

	if ( 'publish' !== $new_status && $sticky_id > 0 && $sticky_id === (int) $post->ID ) {
		delete_option( 'custom_sticky_post_id' );
	}
}

/**
 * 4. Drops the option when the sticky post is deleted for good.
 *
 * @param int $post_id Deleted post ID.
 */
add_action( 'delete_post', 'custom_sticky_delete_post' );
function custom_sticky_delete_post( $post_id ) {
	$sticky_id = (int) get_option( 'custom_sticky_post_id', 0 );

	if ( $sticky_id > 0 && $sticky_id === (int) $post_id ) {
		delete_option( 'custom_sticky_post_id' );
	}
}

/**
 * 5. Hides the thumbnail of the sticky post on the blog home page only.
 *
 * @param string $html             Thumbnail markup.
 * @param int    $post_id          Post ID.
 * @param int    $post_thumbnail_id Attachment ID.
 * @return string
 */
add_filter( 'post_thumbnail_html', 'custom_sticky_hide_thumbnail_on_home', 10, 3 );
function custom_sticky_hide_thumbnail_on_home( $html, $post_id, $post_thumbnail_id ) {
	if ( ! is_home() ) {
		return $html;
	}

	$sticky_id = (int) get_option( 'custom_sticky_post_id', 0 );

	if ( $sticky_id > 0 && $sticky_id === (int) $post_id ) {
		return '';
	}

	return $html;
}

/**
 * 6. Keeps the sticky post out of the blog home query.
 *
 * The post is rendered separately at the top of the first page. Without this
 * exclusion the query also returns it in its natural position: a post old
 * enough to belong to page 2 then appears twice — once prepended on page 1 and
 * once where it belongs. Excluding it on every page (not only after the first)
 * keeps the query result identical on every request, so found_posts and
 * max_num_pages stay consistent and pagination never links to an empty page.
 *
 * Main query only: archives, search, REST and secondary queries keep the sticky
 * post where WordPress put it.
 *
 * @param WP_Query $query Query being prepared.
 */
add_action( 'pre_get_posts', 'custom_sticky_exclude_from_home_query' );
function custom_sticky_exclude_from_home_query( $query ) {
	if ( ! ( $query instanceof WP_Query ) || is_admin() || ! $query->is_home() || ! $query->is_main_query() ) {
		return;
	}

	$sticky_id = (int) get_option( 'custom_sticky_post_id', 0 );
	if ( $sticky_id <= 0 ) {
		return;
	}

	// Keep any post__not_in another filter has already set.
	$excluded   = $query->get( 'post__not_in' );
	$excluded   = is_array( $excluded ) ? $excluded : array();
	$excluded[] = $sticky_id;

	$query->set( 'post__not_in', array_values( array_unique( array_map( 'absint', $excluded ) ) ) );
}

/**
 * 7. Prepends the sticky post to the first page of the blog home results.
 *
 * Runs on the main query results, after the exclusion above. Unlike
 * loop_start, this filter also runs when the query matched nothing — the case
 * of a site whose only published post is the sticky one — so the loop starts
 * and the home page renders that post instead of "nothing found".
 *
 * found_posts and max_num_pages are left as the query computed them; only the
 * single-post case raises them to one, so the pagination metadata matches what
 * is rendered.
 *
 * @param WP_Post[] $posts Query results.
 * @param WP_Query  $query Query object.
 * @return WP_Post[]
 */
add_filter( 'the_posts', 'custom_sticky_prepend_sticky_post', 10, 2 );
function custom_sticky_prepend_sticky_post( $posts, $query ) {
	if ( ! is_array( $posts ) || ! ( $query instanceof WP_Query ) || is_admin()
		|| ! $query->is_home() || ! $query->is_main_query() || $query->is_paged() ) {
		return $posts;
	}

	$sticky_id = (int) get_option( 'custom_sticky_post_id', 0 );
	if ( $sticky_id <= 0 ) {
		return $posts;
	}

	$sticky_post = get_post( $sticky_id );
	if ( ! ( $sticky_post instanceof WP_Post ) || 'publish' !== $sticky_post->post_status ) {
		delete_option( 'custom_sticky_post_id' );
		return $posts;
	}

	// The query already excludes the post; filtering again keeps the callback
	// idempotent if a later filter reset post__not_in.
	$remaining = array_values(
		array_filter(
			$posts,
			static function ( $post ) use ( $sticky_id ) {
				return $post instanceof WP_Post && (int) $post->ID !== $sticky_id;
			}
		)
	);

	$prepended         = array_merge( array( $sticky_post ), $remaining );
	$query->posts      = $prepended;
	$query->post_count = count( $prepended );

	if ( (int) $query->found_posts < 1 ) {
		// Only the sticky post is published: the query matched nothing because
		// the post was excluded, while the home page renders it.
		$query->found_posts   = 1;
		$query->max_num_pages = 1;
	}

	return $prepended;
}

/**
 * 8. Adds the custom-sticky-post class to the sticky post.
 *
 * @param string[] $classes Post classes.
 * @param string[] $class   Additional classes.
 * @param int      $post_id Post ID.
 * @return string[]
 */
add_filter( 'post_class', 'custom_sticky_post_class', 999, 3 );
function custom_sticky_post_class( $classes, $class, $post_id ) {
	$sticky_id = (int) get_option( 'custom_sticky_post_id', 0 );

	if ( $sticky_id > 0 && $sticky_id === (int) $post_id && is_array( $classes )
		&& ! in_array( 'custom-sticky-post', $classes, true ) ) {
		$classes[] = 'custom-sticky-post';
	}

	return $classes;
}

/**
 * 9. Prints the sticky post styles on the home page.
 *
 * The stylesheet is static: no option, post field or request value is
 * interpolated, so there is nothing to escape here. The marker classes are
 * unchanged from the released version.
 */
add_action( 'wp_head', 'custom_sticky_styles', 9999 );
function custom_sticky_styles() {
	if ( ! is_home() ) {
		return;
	}

	$sticky_id = (int) get_option( 'custom_sticky_post_id', 0 );
	if ( $sticky_id <= 0 ) {
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

/*
 * Updates arrive through the native WordPress mechanism: the updater only
 * feeds the update_plugins transient from GitHub Releases and tags.
 */
( new CSP_GitHub_Updater() )->register_hooks();
