// Two tiny themes for the theme check: a block theme with one custom template, and a classic theme with PHP templates.
// Both are written into the test site, so the tests do not depend on which themes WordPress bundles.
const { wp, wpEval } = require( './utils/wp-cli' );

const BLOCK = 'aptu-block';
const CLASSIC = 'aptu-classic';

/**
 * Writes the two themes into the test site.
 */
function createThemes() {
	wpEval( `
		$root = get_theme_root();
		wp_mkdir_p( "$root/${ BLOCK }/templates" );
		file_put_contents( "$root/${ BLOCK }/style.css", "/*\\nTheme Name: APTU Block\\nVersion: 1.0.0\\n*/\\n" );
		file_put_contents( "$root/${ BLOCK }/theme.json", wp_json_encode( array(
			'version' => 2,
			'customTemplates' => array( array( 'name' => 'aptu-landing', 'title' => 'APTU Landing', 'postTypes' => array( 'page' ) ) ),
		) ) );
		file_put_contents( "$root/${ BLOCK }/templates/index.html", '<!-- wp:post-content /-->' );
		file_put_contents( "$root/${ BLOCK }/templates/aptu-landing.html", '<!-- wp:post-content /-->' );

		wp_mkdir_p( "$root/${ CLASSIC }/page-templates" );
		file_put_contents( "$root/${ CLASSIC }/style.css", "/*\\nTheme Name: APTU Classic\\nVersion: 1.0.0\\n*/\\n" );
		file_put_contents( "$root/${ CLASSIC }/index.php", '<?php // Silence.' );
		file_put_contents( "$root/${ CLASSIC }/page-templates/wide.php", "<?php\\n/*\\nTemplate Name: APTU Wide\\n*/\\n" );
		file_put_contents( "$root/${ CLASSIC }/page-templates/note.php", "<?php\\n/*\\nTemplate Name: APTU Note\\nTemplate Post Type: post\\n*/\\n" );
		wp_clean_themes_cache();
	` );
}

/**
 * Switches back to the theme the suite started with, and deletes the two themes.
 *
 * @param {string} original Folder name of the theme to keep.
 */
function removeThemes( original ) {
	wp( 'theme', 'activate', original );
	wpEval( `
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		foreach ( array( '${ BLOCK }', '${ CLASSIC }' ) as $theme ) {
			$wp_filesystem->delete( get_theme_root() . '/' . $theme, true );
		}
		wp_clean_themes_cache();
	` );
}

module.exports = { BLOCK, CLASSIC, createThemes, removeThemes };
