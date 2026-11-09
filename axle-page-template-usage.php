<?php
/**
 * Plugin Name:       Axle Page Template Usage
 * Plugin URI:        https://wordpress.org/plugins/axle-page-template-usage/
 * Description:       Shows which template each page uses, lets you filter and sort by template, and finds pages whose template no longer exists. Works with classic and block themes.
 * Version:           2.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Aryan Jasala
 * Author URI:        https://profiles.wordpress.org/aryanjasala/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axle-page-template-usage
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

defined( 'ABSPATH' ) || exit;

const VERSION     = '2.0.0';
const PLUGIN_FILE = __FILE__;

// PSR-4: AryanX\Axle\PageTemplateUsage\Foo\Bar lives in src/Foo/Bar.php.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
