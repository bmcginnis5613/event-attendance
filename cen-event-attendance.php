<?php
/**
 * Plugin Name: CEN Event Attendance
 * Description: Adds a tab to the WordPress edit user page to display a list of events a user attended.
 * Version: 1.0.2
 * Author: FirstTracks Marketing
 * Author URI: https://firsttracksmarketing.com
 */

defined( 'ABSPATH' ) || exit;


/**
 * Register a separate Event Attendance admin page.
 */
function ceg_event_attendance_register_page() {

	add_users_page(
		'Event Attendance',
		'Event Attendance',
		'edit_users',
		'ceg-event-attendance',
		'ceg_event_attendance_page'
	);

	// Don't show it as a separate item under Users.
	remove_submenu_page(
		'users.php',
		'ceg-event-attendance'
	);
}
add_action( 'admin_menu', 'ceg_event_attendance_register_page', 99 );


/**
 * Get Event Attendance page URL.
 */
function ceg_event_attendance_url( $user_id ) {

	return add_query_arg(
		array(
			'page'    => 'ceg-event-attendance',
			'user_id' => absint( $user_id ),
		),
		admin_url( 'users.php' )
	);
}


/**
 * Add Event Attendance as a third tab on the existing
 * Profile / Extended Profile navigation.
 */
function ceg_event_attendance_add_tab() {

	$screen = get_current_screen();

	if ( ! $screen ) {
		return;
	}

	/*
	 * Only run on:
	 *
	 * user-edit.php
	 * BuddyPress Extended Profile
	 */
	if (
		'user-edit' !== $screen->id
		&& false === strpos( $screen->id, 'bp-profile-edit' )
	) {
		return;
	}

	$user_id = isset( $_GET['user_id'] )
		? absint( $_GET['user_id'] )
		: 0;

	if ( ! $user_id ) {
		return;
	}

	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	$url = ceg_event_attendance_url( $user_id );

	?>
	<script>
	(function () {
		function addEventAttendanceTab() {
			const nav = document.getElementById('profile-nav');

			if (!nav) {
				return false;
			}

			// Prevent duplicate tabs.
			if (nav.querySelector('.ceg-event-attendance-tab')) {
				return true;
			}

			const tab = document.createElement('a');

			tab.href = <?php echo wp_json_encode( $url ); ?>;
			tab.className = 'nav-tab ceg-event-attendance-tab';
			tab.textContent = 'Event Attendance';

			nav.appendChild(tab);
			return true;
		}

		if (addEventAttendanceTab()) {
			return;
		}

		const observer = new MutationObserver(function () {
			if (addEventAttendanceTab()) {
				observer.disconnect();
			}
		});

		observer.observe(document.documentElement, {
			childList: true,
			subtree: true
		});
	})();
	</script>
	<?php
}
add_action( 'admin_head', 'ceg_event_attendance_add_tab' );


/**
 * Get The Events Calendar attendee-management URL for an event.
 *
 * @param int $event_id Event post ID.
 *
 * @return string
 */
function ceg_event_attendance_attendees_url( $event_id ) {

	$post_type = get_post_type( $event_id );

	if ( ! $post_type ) {
		return '';
	}

	$args = array(
		'page'     => 'tickets-attendees',
		'event_id' => absint( $event_id ),
	);

	if ( 'post' !== $post_type ) {
		$args['post_type'] = $post_type;
	}

	$url = add_query_arg( $args, admin_url( 'edit.php' ) );

	return apply_filters( 'tribe_ticket_filter_attendee_report_link', $url, absint( $event_id ) );
}


/**
 * Get every event for which Event Tickets has an attendee record for a user.
 *
 * The Event Tickets confirmation shortcode only queries upcoming events. This
 * uses the same attendee/event relationships without its end-date restriction
 * so previous events are included too. It matches both the WordPress user ID
 * and attendee email because guest and admin-created RSVPs are not necessarily
 * connected to a WordPress account.
 *
 * @param int $user_id WordPress user ID.
 *
 * @return int[] Event post IDs, newest first.
 */
function ceg_event_attendance_get_event_ids( $user_id ) {
	global $wpdb;

	if ( ! class_exists( 'Tribe__Tickets__Tickets' ) ) {
		return array();
	}

	$event_keys = array();
	$email_keys = array(
		'_tribe_rsvp_email',
		'_tribe_tickets_email',
		'_tribe_tpp_email',
	);
	$user       = get_userdata( $user_id );

	if ( ! $user ) {
		return array();
	}

	foreach ( Tribe__Tickets__Tickets::modules() as $module_class => $module_instance ) {
		$constant_name = "$module_class::ATTENDEE_EVENT_KEY";

		if ( defined( $constant_name ) ) {
			$event_keys[] = constant( $constant_name );
		} elseif ( is_callable( array( $module_class, 'get_key' ) ) ) {
			$event_keys[] = call_user_func( array( $module_class, 'get_key' ), 'ATTENDEE_EVENT_KEY' );
		}

		if ( is_object( $module_instance ) && ! empty( $module_instance->email ) ) {
			$email_keys[] = $module_instance->email;
		}
	}

	$event_keys = array_values( array_unique( array_filter( $event_keys ) ) );
	$email_keys = array_values( array_unique( array_filter( $email_keys ) ) );

	if ( empty( $event_keys ) ) {
		return array();
	}

	$key_placeholders = implode( ', ', array_fill( 0, count( $event_keys ), '%s' ) );
	$email_placeholders = implode( ', ', array_fill( 0, count( $email_keys ), '%s' ) );
	$query_args         = array_merge(
		array( absint( $user_id ) ),
		$email_keys,
		array( $user->user_email ),
		$event_keys
	);

	$query = "
		SELECT event_list.ID
		FROM {$wpdb->postmeta} AS match_identity
		INNER JOIN {$wpdb->postmeta} AS match_events
			ON match_events.post_id = match_identity.post_id
		INNER JOIN {$wpdb->posts} AS event_list
			ON event_list.ID = match_events.meta_value
		LEFT JOIN {$wpdb->postmeta} AS event_start_dates
			ON event_start_dates.post_id = event_list.ID
			AND event_start_dates.meta_key = '_EventStartDateUTC'
		WHERE (
				(
					match_identity.meta_key = '_tribe_tickets_attendee_user_id'
					AND match_identity.meta_value = %d
				)
				OR (
					match_identity.meta_key IN ( $email_placeholders )
					AND LOWER( match_identity.meta_value ) = LOWER( %s )
				)
			)
			AND match_events.meta_key IN ( $key_placeholders )
			AND event_list.post_status NOT IN ( 'trash', 'auto-draft' )
			AND NOT EXISTS (
				SELECT 1
				FROM {$wpdb->postmeta} AS ticket_status
				WHERE ticket_status.meta_key = '_wp_trash_meta_status'
					AND ticket_status.post_id = match_events.post_id
			)
			AND NOT EXISTS (
				SELECT 1
				FROM {$wpdb->postmeta} AS rsvp_status
				WHERE rsvp_status.meta_key = '_tribe_rsvp_status'
					AND rsvp_status.meta_value = 'no'
					AND rsvp_status.post_id = match_events.post_id
			)
		GROUP BY event_list.ID
		ORDER BY COALESCE( MAX( event_start_dates.meta_value ), MAX( event_list.post_date ) ) DESC
	";

	// The placeholders are constructed above from Event Tickets provider keys.
	$prepared_query = $wpdb->prepare( $query, $query_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	return array_map( 'absint', (array) $wpdb->get_col( $prepared_query ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}


/**
 * Render a concise attendance list.
 *
 * @param int[] $event_ids Event post IDs.
 */
function ceg_event_attendance_render_list( $event_ids ) {

	$event_ids = array_values( array_unique( array_map( 'absint', $event_ids ) ) );

	if ( empty( $event_ids ) ) {
		echo '<p>This user has not RSVP\'d to any events.</p>';
		return;
	}

	echo '<ol class="tribe-tickets my-attendance-list ceg-event-attendance-list">';

	foreach ( $event_ids as $event_id ) {
		$event_title = get_the_title( $event_id );
		$attendees_url = ceg_event_attendance_attendees_url( $event_id );
		$event_date  = function_exists( 'tribe_get_start_date' )
			? tribe_get_start_date( $event_id, false, 'F j, Y' )
			: get_the_date( 'F j, Y', $event_id );

		if ( ! $event_title ) {
			continue;
		}

		echo '<li class="event-' . esc_attr( $event_id ) . '">';

		if ( $attendees_url ) {
			echo '<a href="' . esc_url( $attendees_url ) . '">';
			echo esc_html( $event_title );
			echo '</a>';
		} else {
			echo esc_html( $event_title );
		}

		if ( $event_date ) {
			echo ' <span class="datetime">&mdash; ' . esc_html( $event_date ) . '</span>';
		}

		echo '</li>';
	}

	echo '</ol>';
}


/**
 * Render the separate Event Attendance screen.
 */
function ceg_event_attendance_page() {

	$user_id = isset( $_GET['user_id'] )
		? absint( $_GET['user_id'] )
		: 0;

	if ( ! $user_id ) {
		wp_die( 'No user specified.' );
	}

	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		wp_die( 'You do not have permission to view this user.' );
	}

	$user = get_userdata( $user_id );

	if ( ! $user ) {
		wp_die( 'User not found.' );
	}


	/*
	 * Tab URLs.
	 */
	$profile_url = get_edit_user_link( $user_id );

	$extended_url = add_query_arg(
		array(
			'page'    => 'bp-profile-edit',
			'user_id' => $user_id,
		),
		admin_url( 'users.php' )
	);

	$attendance_url = ceg_event_attendance_url( $user_id );

	?>

	<div class="wrap">

		<h1 class="wp-heading-inline">
			<?php
			printf(
				'Edit User %s',
				esc_html( $user->display_name )
			);
			?>
		</h1>

		<?php if ( current_user_can( 'create_users' ) ) : ?>

			<a
				href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"
				class="page-title-action"
			>
				Add User
			</a>

		<?php endif; ?>

		<hr class="wp-header-end">


		<!-- Tabs -->
		<h2 id="profile-nav" class="nav-tab-wrapper">

			<a
				href="<?php echo esc_url( $profile_url ); ?>"
				class="nav-tab"
			>
				Profile
			</a>

			<a
				href="<?php echo esc_url( $extended_url ); ?>"
				class="nav-tab"
			>
				Extended Profile
			</a>

			<a
				href="<?php echo esc_url( $attendance_url ); ?>"
				class="nav-tab nav-tab-active"
			>
				Event Attendance
			</a>

		</h2>


		<!-- Attendance content -->
		<div style="margin-top: 25px;">

			<?php

			if ( class_exists( 'Tribe__Tickets__Tickets' ) ) {

				$event_ids = ceg_event_attendance_get_event_ids( $user_id );
				ceg_event_attendance_render_list( $event_ids );

			} else {

				echo '<div class="notice notice-warning inline">';
				echo '<p>Event Tickets attendance data is unavailable.</p>';
				echo '</div>';

			}

			?>

		</div>

	</div>

	<?php
}
