<?php
/**
 * Plugin Name: CEN Event Attendance
 * Description: Adds a tab to the WordPress edit user page to display a list of events a user attended.
 * Version: 1.0.0
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
 * Render a concise attendance list from Event Tickets shortcode output.
 *
 * Event Tickets includes the event post ID in each list item's `event-{ID}`
 * class. Using those IDs lets this screen control the displayed fields while
 * leaving attendance lookup and ticket-provider compatibility to the plugin.
 *
 * @param string $shortcode_output Event Tickets attendance-list markup.
 */
function ceg_event_attendance_render_list( $shortcode_output ) {

	$matches = array();
	preg_match_all( '/\bevent-(\d+)\b/', $shortcode_output, $matches );

	$event_ids = isset( $matches[1] )
		? array_values( array_unique( array_map( 'absint', $matches[1] ) ) )
		: array();

	if ( empty( $event_ids ) ) {
		// Preserve Event Tickets' own empty-state or error message.
		echo wp_kses_post( $shortcode_output );
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

			if ( shortcode_exists( 'tribe-user-event-confirmations' ) ) {

				$attendance_output = do_shortcode(
					sprintf(
						'[tribe-user-event-confirmations user="%d"]',
						$user_id
					)
				);

				ceg_event_attendance_render_list( $attendance_output );

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
