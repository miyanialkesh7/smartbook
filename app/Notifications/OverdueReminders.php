<?php
/**
 * Daily overdue/due-soon reminder emails.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Notifications;

use SmartBook\Core\Contracts\Hookable;
use SmartBook\Services\BookStats;
use SmartBook\Services\LoggerInterface;
use WP_User;

use function sb_format_date;
use function sb_option;

/**
 * Once a day, emails every borrower whose loan is overdue or whose
 * "sb_reminder" date has arrived -- the same set BookStats::borrow_alerts()
 * already computes for the dashboard's "Needs Attention" list, just
 * turned into emails instead of an admin-only view. A "sb_lost" copy is
 * skipped: there's no active borrower to remind. Only a loan whose
 * "sb_borrowed_to" is a resolvable WP user (i.e. it started from an
 * approved front-end request, see BookFields::user_options()) can be
 * emailed; a free-typed walk-in name from the book scan page's "Borrow"
 * quick action has no account to send to and is silently skipped.
 *
 * Schedules its own cron event the same way on every request
 * (wp_next_scheduled() is a cheap option check, and only actually
 * reschedules when the event is missing) rather than only on plugin
 * activation, so the daily run self-heals if the schedule is ever lost
 * -- e.g. a "wp cron event delete" or a migrated database that dropped
 * the cron option. Uses the "sb_cron_event" hook name Core\Deactivator
 * and Core\Uninstaller already clear on deactivate/uninstall.
 */
final class OverdueReminders implements Hookable {

	/**
	 * Cron hook name, matching what Core\Deactivator/Core\Uninstaller
	 * already clear.
	 */
	private const CRON_HOOK = 'sb_cron_event';

	/**
	 * @param BookStats       $stats  Book catalog statistics, for borrow_alerts().
	 * @param LoggerInterface $logger Logger, used when wp_mail() fails.
	 */
	public function __construct(
		private readonly BookStats $stats,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}

		add_action( self::CRON_HOOK, array( $this, 'send_reminders' ) );
	}

	/**
	 * Cron callback: email every borrower with an overdue or due-soon loan.
	 */
	public function send_reminders(): void {
		if ( ! sb_option( 'enable_email_notifications', true ) ) {
			return;
		}

		foreach ( $this->stats->borrow_alerts() as $alert ) {
			if ( 'lost' === $alert['status'] ) {
				continue;
			}

			$this->send_alert_email( $alert );
		}
	}

	/**
	 * Email one borrow_alerts() row's borrower, if they're a resolvable
	 * WP user with an email address.
	 *
	 * @param array{post_id: int, title: string, borrowed_to: string, date: string, status: string} $alert One borrow_alerts() row.
	 */
	private function send_alert_email( array $alert ): void {
		$borrowed_to = (string) get_post_meta( $alert['post_id'], 'sb_borrowed_to', true );

		if ( '' === $borrowed_to || ! ctype_digit( $borrowed_to ) ) {
			return;
		}

		$user = get_userdata( (int) $borrowed_to );

		if ( ! $user instanceof WP_User || '' === $user->user_email ) {
			return;
		}

		$date = '' !== $alert['date'] ? sb_format_date( $alert['date'] ) : '';

		if ( 'overdue' === $alert['status'] ) {
			$subject = sprintf(
				/* translators: %s: book title. */
				__( '"%s" is overdue', 'smartbook' ),
				$alert['title']
			);
			$message = sprintf(
				/* translators: 1: borrower display name, 2: book title, 3: due date. */
				__( "Hi %1\$s,\n\n\"%2\$s\" was due back on %3\$s and is now overdue. Please return it as soon as you can.", 'smartbook' ),
				$user->display_name,
				$alert['title'],
				$date
			);
		} else {
			$subject = sprintf(
				/* translators: %s: book title. */
				__( 'Reminder: "%s" is due soon', 'smartbook' ),
				$alert['title']
			);
			$message = sprintf(
				/* translators: 1: borrower display name, 2: book title, 3: due date. */
				__( "Hi %1\$s,\n\nJust a reminder that \"%2\$s\" is due back on %3\$s.", 'smartbook' ),
				$user->display_name,
				$alert['title'],
				$date
			);
		}

		$sent = wp_mail( $user->user_email, $subject, $message );

		if ( ! $sent ) {
			$this->logger->error(
				'Failed to send SmartBook overdue/reminder email.',
				array(
					'post_id' => $alert['post_id'],
					'user_id' => $user->ID,
				)
			);
		}
	}
}
