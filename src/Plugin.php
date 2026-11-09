<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

use AryanX\Axle\PageTemplateUsage\Admin\ListColumn;
use AryanX\Axle\PageTemplateUsage\Admin\ReportPage;
use AryanX\Axle\PageTemplateUsage\Cli\Command;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Shared template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Services that hook into WordPress.
	 *
	 * @var Service[]
	 */
	private array $services;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance, for other plugins that want the template data.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the services.
	 */
	private function __construct() {
		$this->templates = new Templates();
		$this->services  = array(
			$this->templates,
			new ListColumn( $this->templates ),
			new ReportPage( $this->templates ),
			new Abilities( $this->templates ),
		);
	}

	/**
	 * Template data shared by every screen.
	 *
	 * @return Templates
	 */
	public function templates(): Templates {
		return $this->templates;
	}

	/**
	 * Registers every service, and the WP-CLI command when running in WP-CLI.
	 */
	private function register(): void {
		foreach ( $this->services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-page-template-usage', new Command( $this->templates ) );
		}
	}
}
