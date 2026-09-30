<?php
/**
 * Notifications service provider.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Notifications;

use SmartBook\Core\AbstractServiceProvider;
use SmartBook\Core\Contracts\ContainerInterface;
use SmartBook\Services\BookStats;
use SmartBook\Services\LoggerInterface;

/**
 * Binds and boots the email-notification classes: BorrowNotifications
 * (event-driven, hooked onto the borrow/return request workflow's own
 * custom actions) and OverdueReminders (a daily cron sweep). Registered
 * last in Core\Plugin::PROVIDERS -- it depends on BookStats (bound by
 * AdminServiceProvider) and LoggerInterface (bound by
 * LoggerServiceProvider), which is safe regardless of provider order
 * since boot() only ever runs after every provider's register() has
 * already completed.
 */
final class NotificationsServiceProvider extends AbstractServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register( ContainerInterface $container ): void {
		$container->singleton(
			BorrowNotifications::class,
			static fn ( ContainerInterface $container ): BorrowNotifications => new BorrowNotifications( $container->make( LoggerInterface::class ) )
		);

		$container->singleton(
			OverdueReminders::class,
			static fn ( ContainerInterface $container ): OverdueReminders => new OverdueReminders(
				$container->make( BookStats::class ),
				$container->make( LoggerInterface::class )
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( ContainerInterface $container ): void {
		$container->make( BorrowNotifications::class )->register_hooks();
		$container->make( OverdueReminders::class )->register_hooks();
	}
}
