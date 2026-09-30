<?php
/**
 * Main plugin class.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use RuntimeException;
use SmartBook\Admin\AdminServiceProvider;
use SmartBook\Assets\AssetServiceProvider;
use SmartBook\Core\Container\Container;
use SmartBook\Core\Contracts\ContainerInterface;
use SmartBook\Core\Contracts\ServiceProviderInterface;
use SmartBook\Frontend\FrontendServiceProvider;
use SmartBook\MetaBoxes\MetaBoxServiceProvider;
use SmartBook\Notifications\NotificationsServiceProvider;
use SmartBook\PostTypes\PostTypeServiceProvider;
use SmartBook\Services\BarcodeServiceProvider;
use SmartBook\Services\Import\ImportExportServiceProvider;
use SmartBook\Services\LoggerServiceProvider;
use SmartBook\Services\QrCodeServiceProvider;
use SmartBook\Settings\SettingsServiceProvider;
use SmartBook\Taxonomies\TaxonomyServiceProvider;

/**
 * Owns the service container and orchestrates every module's service
 * provider. A Singleton is appropriate here: WordPress boots exactly one
 * instance of this plugin per request, and hook callbacks registered as
 * `array( Plugin::instance(), ... )` need a stable, globally reachable
 * handle rather than a passed-around reference.
 */
final class Plugin {

	/**
	 * The single instance of this class.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Service providers that make up the plugin, in registration order.
	 *
	 * Each entry is booted only after every provider has finished
	 * register(), so a provider's boot() can safely depend on bindings
	 * made by providers listed after it.
	 *
	 * @var class-string<ServiceProviderInterface>[]
	 */
	private const PROVIDERS = array(
		LoggerServiceProvider::class,
		SettingsServiceProvider::class,
		AssetServiceProvider::class,
		PostTypeServiceProvider::class,
		TaxonomyServiceProvider::class,
		QrCodeServiceProvider::class,
		BarcodeServiceProvider::class,
		ImportExportServiceProvider::class,
		MetaBoxServiceProvider::class,
		FrontendServiceProvider::class,
		AdminServiceProvider::class,
		NotificationsServiceProvider::class,
	);

	/**
	 * Application service container.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Instantiated service providers, in boot order.
	 *
	 * @var ServiceProviderInterface[]
	 */
	private array $providers = array();

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Build the container and register the plugin itself into it.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->container->instance( self::class, $this );
		$this->container->instance( ContainerInterface::class, $this->container );
	}

	/**
	 * Retrieve the single plugin instance, creating it on first call.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin: load translations, then register and boot every
	 * service provider. Safe to call more than once; only the first call
	 * has any effect.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->load_textdomain();
		$this->register_providers();
		$this->boot_providers();
	}

	/**
	 * The application service container.
	 */
	public function container(): ContainerInterface {
		return $this->container;
	}

	/**
	 * Load the plugin's translation files.
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain( 'smartbook', false, dirname( SB_BASENAME ) . '/languages' );
	}

	/**
	 * Instantiate every provider and let it register its bindings.
	 */
	private function register_providers(): void {
		foreach ( self::PROVIDERS as $provider_class ) {
			/**
			 * Concrete provider instance.
			 *
			 * @var ServiceProviderInterface $provider
			 */
			$provider = new $provider_class();
			$provider->register( $this->container );

			$this->providers[] = $provider;
		}
	}

	/**
	 * Let every already-registered provider attach its WordPress hooks.
	 */
	private function boot_providers(): void {
		foreach ( $this->providers as $provider ) {
			$provider->boot( $this->container );
		}
	}

	/**
	 * Singletons must not be cloneable.
	 */
	private function __clone() {
	}

	/**
	 * Singletons must not be unserializable.
	 *
	 * @throws RuntimeException Always, to prevent unserializing a singleton.
	 */
	public function __wakeup(): void {
		throw new RuntimeException( 'Cannot unserialize a singleton.' );
	}
}
