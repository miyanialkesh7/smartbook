<?php
/**
 * Import/export service provider.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

use SmartBook\Core\AbstractServiceProvider;
use SmartBook\Core\Contracts\ContainerInterface;
use SmartBook\Services\LoggerInterface;

/**
 * Binds the CSV/JSON/XML/Backup format registry and the chunked import
 * runner built on top of it. Registers no hooks of its own; Admin's
 * ImportExportPage and ImportExportAjaxController consume these bindings.
 */
final class ImportExportServiceProvider extends AbstractServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param ContainerInterface $container Application service container.
	 */
	public function register( ContainerInterface $container ): void {
		$container->singleton( FormatRegistry::class, static fn (): FormatRegistry => new FormatRegistry() );
		$container->singleton( DuplicateDetector::class, static fn (): DuplicateDetector => new DuplicateDetector() );
		$container->singleton( ImportSession::class, static fn (): ImportSession => new ImportSession() );
		$container->singleton( UploadedFileStore::class, static fn (): UploadedFileStore => new UploadedFileStore() );

		$container->singleton(
			ImportRunner::class,
			static fn ( ContainerInterface $container ): ImportRunner => new ImportRunner(
				$container->make( FormatRegistry::class ),
				$container->make( DuplicateDetector::class ),
				$container->make( ImportSession::class ),
				$container->make( UploadedFileStore::class ),
				$container->make( LoggerInterface::class )
			)
		);
	}
}
