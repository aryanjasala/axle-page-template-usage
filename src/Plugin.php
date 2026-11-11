<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

use AryanX\Axle\PageTemplateUsage\Admin\HistoryTab;
use AryanX\Axle\PageTemplateUsage\Admin\ListColumn;
use AryanX\Axle\PageTemplateUsage\Admin\ReportPage;
use AryanX\Axle\PageTemplateUsage\Admin\ThemeCheckTab;
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
	 * Template history.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Theme check.
	 *
	 * @var ThemeCheck
	 */
	private ThemeCheck $theme;

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
	 * Template history.
	 *
	 * @return History
	 */
	public function history(): History {
		return $this->history;
	}

	/**
	 * Theme check.
	 *
	 * @return ThemeCheck
	 */
	public function theme_check(): ThemeCheck {
		return $this->theme;
	}

	/**
	 * Builds the services.
	 */
	private function __construct() {
		$this->history   = new History();
		$this->templates = new Templates( $this->history );
		$this->theme     = new ThemeCheck( $this->templates, $this->history );
		$theme_tab       = new ThemeCheckTab( $this->templates, $this->theme );
		$history_tab     = new HistoryTab( $this->templates, $this->history );
		$this->services  = array(
			$this->history,
			$this->templates,
			$this->theme,
			new Privacy( $this->history ),
			new ListColumn( $this->templates ),
			new ReportPage( $this->templates, $theme_tab, $history_tab ),
			$theme_tab,
			$history_tab,
			new Abilities( $this->templates, $this->theme, $this->history ),
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
			\WP_CLI::add_command( 'axle-page-template-usage', new Command( $this->templates, $this->theme, $this->history ) );
		}
	}
}
