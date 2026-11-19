// A theme with an update waiting, for the theme update check.
// "aptu-upd" 1.0.0 is installed and active. The 2.0.0 package takes away two templates (a block template and a classic one)
// and brings code that must never run just by being read.
// The package is handed to WordPress by a small must-use plugin, so no network is needed.
const { wp, wpEval } = require( './utils/wp-cli' );

const THEME = 'aptu-upd';
const PACKAGE = 'https://downloads.example.test/aptu-upd-2.0.0.zip';
// The web server and WP-CLI run in two containers that share only wp-content, so every file the fixtures use sits there.
const DIR = '/var/www/html/wp-content/aptu-fixture';
const MARKER = `${ DIR }/executed`;
const ZIP = `${ DIR }/update.zip`;
const MU = 'aptu-update-fixture.php';

/**
 * Removes everything the fixtures make, also what an earlier crashed run left behind.
 *
 * @param {string} original Folder name of the theme to keep active.
 */
function teardown( original ) {
	if ( original ) {
		wp( 'theme', 'activate', original );
	}
	wpEval( `
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		$wp_filesystem->delete( get_theme_root() . '/${ THEME }', true );
		$wp_filesystem->delete( WPMU_PLUGIN_DIR . '/${ MU }' );
		$wp_filesystem->delete( '${ DIR }', true );
		wp_clean_themes_cache();
		delete_site_transient( 'update_themes' );
		delete_option( 'axle_page_template_usage_update_checks' );
		delete_option( 'axle_page_template_usage_update_plans' );
		delete_option( 'axle_page_template_usage_switch_result' );
	` );
	try {
		wp( 'user', 'delete', 'aptu-upd-limited', '--yes' );
		wp( 'role', 'delete', 'aptu_upd_limited' );
	} catch ( error ) {
		// Nothing to remove.
	}
}

/**
 * Installs 1.0.0, activates it, writes the 2.0.0 package and announces it as an update.
 */
function setup() {
	wpEval( `
		$root = get_theme_root();
		wp_mkdir_p( '${ DIR }/tmp' );
		wp_mkdir_p( "$root/${ THEME }/templates" );
		wp_mkdir_p( "$root/${ THEME }/page-templates" );
		file_put_contents( "$root/${ THEME }/style.css", "/*\\nTheme Name: APTU Update\\nVersion: 1.0.0\\n*/\\n" );
		file_put_contents( "$root/${ THEME }/theme.json", wp_json_encode( array(
			'version' => 2,
			'customTemplates' => array( array( 'name' => 'aptu-landing', 'title' => 'APTU Landing', 'postTypes' => array( 'page' ) ) ),
		) ) );
		file_put_contents( "$root/${ THEME }/templates/index.html", '<!-- wp:post-content /-->' );
		file_put_contents( "$root/${ THEME }/templates/aptu-landing.html", '<!-- wp:post-content /-->' );
		file_put_contents( "$root/${ THEME }/templates/aptu-gone.html", '<!-- wp:post-content /-->' );
		file_put_contents( "$root/${ THEME }/page-templates/aptu-wide.php", "<?php\\n/*\\nTemplate Name: APTU Wide\\n*/\\n" );

		$zip = new ZipArchive();
		$zip->open( '${ ZIP }', ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( '${ THEME }/style.css', "/*\\nTheme Name: APTU Update\\nVersion: 2.0.0\\n*/\\n" );
		$zip->addFromString( '${ THEME }/theme.json', wp_json_encode( array(
			'version' => 2,
			'customTemplates' => array( array( 'name' => 'aptu-landing', 'title' => 'APTU Landing', 'postTypes' => array( 'page' ) ) ),
		) ) );
		$zip->addFromString( '${ THEME }/templates/index.html', '<!-- wp:post-content /-->' );
		$zip->addFromString( '${ THEME }/templates/aptu-landing.html', '<!-- wp:post-content /-->' );
		// New in 2.0.0, with code that would leave a marker file if anything ran it.
		$zip->addFromString( '${ THEME }/page-templates/aptu-fresh.php', "<?php\\n/*\\nTemplate Name: APTU Fresh\\n*/\\nfile_put_contents( '${ MARKER }', 'ran' );\\n" );
		$zip->addFromString( '${ THEME }/functions.php', "<?php\\nfile_put_contents( '${ MARKER }-functions', 'ran' );\\n" );
		$zip->close();

		wp_mkdir_p( WPMU_PLUGIN_DIR );
		file_put_contents( WPMU_PLUGIN_DIR . '/${ MU }', <<<'PHP'
<?php
define( 'WP_TEMP_DIR', '${ DIR }/tmp' );
add_filter( 'upgrader_pre_download', function ( $reply, $package ) {
	if ( '${ PACKAGE }' !== $package ) {
		return $reply;
	}
	$copy = wp_tempnam( 'aptu-package' );
	copy( '${ ZIP }', $copy );
	return $copy;
}, 10, 2 );
PHP
		);
		wp_clean_themes_cache();
	` );
	wp( 'theme', 'activate', THEME );
	announce();
}

/**
 * Tells WordPress an update is waiting, the way the update check would.
 */
function announce() {
	wpEval( `
		$checked = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$checked[ $stylesheet ] = $theme->get( 'Version' );
		}
		set_site_transient( 'update_themes', (object) array(
			'last_checked' => time(),
			'checked'      => $checked,
			'response'     => array(
				'${ THEME }' => array(
					'theme'        => '${ THEME }',
					'new_version'  => '2.0.0',
					'url'          => 'https://example.test/',
					'package'      => '${ PACKAGE }',
					'requires'     => '',
					'requires_php' => '',
				),
			),
			'translations' => array(),
		) );
	` );
}

/**
 * Whether the code inside the package ran, and what is left in the temporary folder.
 *
 * @return {{ran: boolean, leftovers: string}} Marker found, and the names of leftover files.
 */
function traces() {
	return JSON.parse(
		wpEval( `
			echo wp_json_encode( array(
				'ran'       => file_exists( '${ MARKER }' ),
				'functions' => file_exists( '${ MARKER }-functions' ),
				'leftovers' => implode( ',', array_map( 'basename', (array) glob( '${ DIR }/tmp/*' ) ) ),
			) );
		` )
	);
}

/**
 * For the accessibility screens: the theme with its update, and two pages that would lose their template.
 *
 * @param {string}  original Folder name of the theme the suite runs with.
 * @param {boolean} checked  Also read the update.
 * @param {boolean} planned  Also save a plan.
 * @return {string} ID of a page that does not lose its template, for the preview bar.
 */
function seed( original, checked, planned ) {
	teardown( original );
	setup();
	wp( 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=A11y Gone', '--meta_input={"_wp_page_template":"aptu-gone"}' );
	wp( 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=A11y Wide', '--meta_input={"_wp_page_template":"page-templates/aptu-wide.php"}' );
	const keep = wp( 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=A11y Landing', '--post_content=Body text.', '--meta_input={"_wp_page_template":"aptu-landing"}', '--porcelain' );
	if ( checked ) {
		try {
			wp( 'axle-page-template-usage', 'check-update', THEME );
		} catch ( error ) {
			// Exit code 1 means pages would be affected, which is the point.
		}
	}
	if ( planned ) {
		wp( 'axle-page-template-usage', 'plan-update', THEME, 'aptu-gone', 'aptu-landing' );
	}
	return keep;
}

/**
 * Removes what seed() made, the pages too.
 *
 * @param {string} original Folder name of the theme the suite runs with.
 */
function unseed( original ) {
	for ( const title of [ 'A11y Gone', 'A11y Wide', 'A11y Landing' ] ) {
		const found = wp( 'post', 'list', '--post_type=page', `--title=${ title }`, '--format=ids' );
		if ( found ) {
			wp( 'post', 'delete', ...found.split( ' ' ), '--force' );
		}
	}
	teardown( original );
}

/**
 * A preview link for the admin, with the template the theme brings in 2.0.0 left out: the landing template.
 *
 * @param {string} id Page ID.
 * @return {string} URL.
 */
function previewUrl( id ) {
	return wp( '--user=1', 'axle-page-template-usage', 'preview-url', id, 'default' );
}

module.exports = { THEME, setup, teardown, announce, traces, seed, unseed, previewUrl };
