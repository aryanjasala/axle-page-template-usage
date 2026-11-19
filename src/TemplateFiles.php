<?php
/**
 * Reads which page templates a theme folder or a theme package offers, without running any of its code.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

defined( 'ABSPATH' ) || exit;

/**
 * Looks only at file names and at the comment header of PHP files
 * (`get_file_data()` reads the first 8 KB as text), `theme.json` as JSON and
 * the names of block template files. Nothing from a theme is included,
 * required or executed, so a package nobody has installed yet is safe to read.
 */
final class TemplateFiles {

	/**
	 * Post type key for templates every public post type may use.
	 */
	public const ANY = '*';

	/**
	 * Largest theme.json that is read, in bytes.
	 */
	private const MAX_JSON = 1048576;

	/**
	 * Templates a theme folder offers.
	 *
	 * Classic templates are PHP files in the theme folder or one folder down with a
	 * `Template Name` header, for the post types in `Template Post Type` (page by
	 * default). Block templates are `templates/*.html` (or the older
	 * `block-templates/*.html`) that are not one of core's default templates: for the
	 * post types `theme.json` names in customTemplates, or for every post type.
	 *
	 * @param string $dir Theme folder, or a folder holding the pieces read from a package.
	 * @return array<string, array<string, string>> Post type (or ANY) => ( template file or slug => name ).
	 */
	public static function from_dir( string $dir ): array {
		$dir   = untrailingslashit( $dir );
		$found = array();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return $found;
		}

		$files = array_merge( (array) glob( $dir . '/*.php' ), (array) glob( $dir . '/*/*.php' ) );
		foreach ( array_slice( $files, 0, 3000 ) as $file ) {
			$headers = get_file_data(
				$file,
				array(
					'name'  => 'Template Name',
					'types' => 'Template Post Type',
				)
			);
			if ( '' === trim( $headers['name'] ) ) {
				continue;
			}

			$types = '' === trim( $headers['types'] ) ? array( 'page' ) : array_filter( array_map( 'sanitize_key', explode( ',', $headers['types'] ) ) );
			$slug  = ltrim( substr( $file, strlen( $dir ) ), '/' );
			foreach ( $types as $type ) {
				$found[ $type ][ $slug ] = trim( $headers['name'] );
			}
		}

		$custom   = self::custom_templates( $dir );
		$defaults = function_exists( 'get_default_block_template_types' ) ? array_keys( get_default_block_template_types() ) : array();
		foreach ( array( 'templates', 'block-templates' ) as $folder ) {
			foreach ( (array) glob( $dir . '/' . $folder . '/*.html' ) as $file ) {
				$slug = basename( $file, '.html' );
				if ( in_array( $slug, $defaults, true ) ) {
					continue;
				}

				$title = isset( $custom[ $slug ]['title'] ) ? $custom[ $slug ]['title'] : $slug;
				$types = isset( $custom[ $slug ] ) ? $custom[ $slug ]['postTypes'] : array( self::ANY );
				foreach ( $types as $type ) {
					$found[ $type ][ $slug ] = $title;
				}
			}
		}

		return $found;
	}

	/**
	 * Custom templates `theme.json` declares.
	 *
	 * @param string $dir Theme folder.
	 * @return array<string, array{title: string, postTypes: string[]}> Slug => details.
	 */
	private static function custom_templates( string $dir ): array {
		$file = $dir . '/theme.json';
		if ( ! is_readable( $file ) || filesize( $file ) > self::MAX_JSON ) {
			return array();
		}

		$data   = json_decode( (string) self::filesystem()->get_contents( $file ), true );
		$custom = array();
		foreach ( (array) ( is_array( $data ) ? ( $data['customTemplates'] ?? array() ) : array() ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}

			$name            = (string) $item['name'];
			$custom[ $name ] = array(
				'title'     => isset( $item['title'] ) ? (string) $item['title'] : $name,
				'postTypes' => isset( $item['postTypes'] ) ? array_values( array_map( 'sanitize_key', (array) $item['postTypes'] ) ) : array( 'page' ),
			);
		}

		return $custom;
	}

	/**
	 * Templates that one theme offers for a post type, with the ones for every post type added.
	 *
	 * @param array<string, array<string, string>> $found    What from_dir() returned, merged.
	 * @param string                               $post_type Post type name.
	 * @return array<string, string> Template file or slug => name.
	 */
	public static function for_type( array $found, string $post_type ): array {
		return array_merge( $found[ self::ANY ] ?? array(), $found[ $post_type ] ?? array() );
	}

	/**
	 * Merges what several theme folders offer, such as a child theme and its parent.
	 *
	 * @param array<int, array<string, array<string, string>>> $sets Results of from_dir().
	 * @return array<string, array<string, string>>
	 */
	public static function merge( array $sets ): array {
		$merged = array();
		foreach ( $sets as $set ) {
			foreach ( $set as $type => $templates ) {
				$merged[ $type ] = array_merge( $merged[ $type ] ?? array(), $templates );
			}
		}

		return $merged;
	}

	/**
	 * Templates a theme package (a zip) would offer, read from the zip without installing it.
	 *
	 * Only the pieces that matter are copied to a temporary folder: the first 8 KB
	 * of PHP files (the header), `theme.json`, and empty files named like the block
	 * templates. The folder is deleted before this returns.
	 *
	 * @param string $zip Path of the downloaded package.
	 * @return array<string, array<string, string>>|\WP_Error Same shape as from_dir().
	 */
	public static function from_package( string $zip ) {
		$pieces = self::list_pieces( $zip );
		if ( is_wp_error( $pieces ) ) {
			return $pieces;
		}

		$work = trailingslashit( get_temp_dir() ) . 'axle-ptu-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $work ) ) {
			return new \WP_Error( 'axle_page_template_usage_update_temp', __( 'The update could not be read: there is no folder to put it in.', 'axle-page-template-usage' ) );
		}

		$fs = self::filesystem();
		try {
			$error = self::copy_pieces( $zip, $pieces, $work, $fs );
			if ( is_wp_error( $error ) ) {
				return $error;
			}

			return self::from_dir( $work );
		} finally {
			$fs->delete( $work, true );
		}
	}

	/**
	 * The files inside a package that are worth reading, with safe relative paths.
	 *
	 * @param string $zip Path of the package.
	 * @return array<int, array{index: int, path: string, kind: string}>|\WP_Error
	 */
	private static function list_pieces( string $zip ) {
		$names = array();
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'axle_page_template_usage_update_no_zip', __( 'Reading an update needs the PHP zip extension, which this server does not have.', 'axle-page-template-usage' ) );
		}

		$archive = new \ZipArchive();
		if ( true !== $archive->open( $zip ) ) {
			return new \WP_Error( 'axle_page_template_usage_update_zip', __( 'The update package could not be opened.', 'axle-page-template-usage' ) );
		}
		/**
		 * Filters the most files a theme package may hold before it is refused as a zip bomb.
		 *
		 * @param int $limit Default 20000.
		 */
		if ( $archive->count() > max( 1, (int) apply_filters( 'axle_page_template_usage_update_max_files', 20000 ) ) ) {
			$archive->close();
			return new \WP_Error( 'axle_page_template_usage_update_big', __( 'The update package holds too many files to read safely.', 'axle-page-template-usage' ) );
		}
		for ( $i = 0, $total = $archive->count(); $i < $total; $i++ ) {
			$stat = $archive->statIndex( $i );
			if ( is_array( $stat ) ) {
				$names[ $i ] = (string) $stat['name'];
			}
		}
		$archive->close();

		if ( ! $names ) {
			return new \WP_Error( 'axle_page_template_usage_update_zip', __( 'The update package could not be opened.', 'axle-page-template-usage' ) );
		}

		// Themes sit in one folder inside the zip: folder/style.css.
		$root = '';
		foreach ( $names as $name ) {
			if ( preg_match( '#^([^/]+)/style\.css$#', $name, $match ) ) {
				$root = $match[1] . '/';
				break;
			}
		}
		if ( '' === $root ) {
			return new \WP_Error( 'axle_page_template_usage_update_not_theme', __( 'The update package does not look like a theme.', 'axle-page-template-usage' ) );
		}

		$pieces = array();
		foreach ( $names as $index => $name ) {
			if ( 0 !== strpos( $name, $root ) ) {
				continue;
			}
			$path = substr( $name, strlen( $root ) );
			// Only plain names: no dots-and-slashes tricks, nothing a path could escape with.
			if ( ! preg_match( '#^[A-Za-z0-9._ -]+(/[A-Za-z0-9._ -]+)?$#', $path ) || false !== strpos( $path, '..' ) ) {
				continue;
			}

			if ( preg_match( '#^([^/]+/)?[^/]+\.php$#', $path ) ) {
				$kind = 'php';
			} elseif ( preg_match( '#^(templates|block-templates)/[^/]+\.html$#', $path ) ) {
				$kind = 'html';
			} elseif ( 'theme.json' === $path ) {
				$kind = 'json';
			} else {
				continue;
			}

			$pieces[] = array(
				'index' => (int) $index,
				'path'  => $path,
				'kind'  => $kind,
			);
		}

		return $pieces;
	}

	/**
	 * Writes the readable pieces of a package into a folder.
	 *
	 * @param string                                                    $zip    Package.
	 * @param array<int, array{index: int, path: string, kind: string}> $pieces From list_pieces().
	 * @param string                                                    $work   Empty folder.
	 * @param \WP_Filesystem_Base                                       $fs     Direct filesystem.
	 * @return true|\WP_Error
	 */
	private static function copy_pieces( string $zip, array $pieces, string $work, $fs ) {
		$archive = new \ZipArchive();
		if ( true !== $archive->open( $zip ) ) {
			return new \WP_Error( 'axle_page_template_usage_update_zip', __( 'The update package could not be opened.', 'axle-page-template-usage' ) );
		}

		foreach ( $pieces as $piece ) {
			$target = $work . '/' . $piece['path'];
			wp_mkdir_p( dirname( $target ) );

			if ( 'html' === $piece['kind'] ) {
				$body = '';
			} else {
				$body = $archive->getFromIndex( $piece['index'], 'json' === $piece['kind'] ? self::MAX_JSON : 8192 );
				$body = is_string( $body ) ? $body : '';
			}

			$fs->put_contents( $target, $body );
		}

		$archive->close();

		return true;
	}

	/**
	 * A direct filesystem object for the temporary folder, whatever the site uses for updates.
	 *
	 * @return \WP_Filesystem_Base
	 */
	private static function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		// Core defines these when it connects a filesystem, which this does not do. The same values.
		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core's own constant.
		}
		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core's own constant.
		}

		return new \WP_Filesystem_Direct( null );
	}
}
