<?php
/**
 * Deprecated functions.
 *
 * @deprecated BuddyBoss [BBVERSION]
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Current user online status.
 *
 * @since BuddyPress 1.7.0
 *
 * @param int $user_id User id.
 *
 * @return void
 */
function bb_current_user_status( $user_id ) {

	_deprecated_function( __FUNCTION__, '2.2.0', 'bb_get_user_status' );

	if ( bb_is_online_user( $user_id ) ) {
		echo wp_kses_post( apply_filters( 'bb_user_online_html', '<span class="member-status online"></span>', $user_id ) );

	}
}
