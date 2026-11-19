// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const fixtures = require( './update-fixtures' );

test.describe( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 760 }, deviceScaleFactor: 2 } );
	const ids = [];
	const extra = [];
	let original = 'twentytwentyfive';

	test.beforeAll( () => {
		prepareSite( 'Example Shop' );
		for ( const [ title, template ] of [
			[ 'Home', '' ],
			[ 'About us', 'page-no-title' ],
			[ 'Contact', '' ],
			[ 'Pricing', 'page-no-title' ],
			[ 'Spring sale landing', 'templates/landing.php' ],
			[ 'Webinar signup', 'templates/landing.php' ],
		] ) {
			const id = wp( 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${ title }`, '--porcelain' );
			if ( template ) {
				wp( 'post', 'meta', 'update', id, '_wp_page_template', template );
			}
			ids.push( id );
		}
	} );

	test.afterAll( () => {
		wp( 'post', 'delete', ...ids, '--force' );
		wp( 'post', 'delete', ...extra, '--force' );
		fixtures.teardown( original );
	} );

	test( '1: the Template column and filter on the Pages screen', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=page&orderby=title&order=asc' );
		await expect( page.locator( '.aptu-missing-notice' ) ).toBeVisible();
		await shot( page, 1 );
	} );

	test( '2: pages whose template no longer exists', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=page&axle_page_template_usage=__missing' );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );
		await shot( page, 2 );
	} );

	test( '3: Appearance > Axle Page Template Usage', async ( { page } ) => {
		await page.goto( '/wp-admin/themes.php?page=axle-page-template-usage' );
		await expect( page.locator( '.aptu-table' ).first() ).toBeVisible();
		await shot( page, 3 );
	} );

	test( '4: the Missing tab, with the closest template for each', async ( { page } ) => {
		await page.goto( '/wp-admin/themes.php?page=axle-page-template-usage&tab=missing' );
		await expect( page.locator( '.aptu-missing-table' ).first() ).toBeVisible();
		await shot( page, 4 );
	} );

	test( '5: the History tab', async ( { page } ) => {
		await page.goto( '/wp-admin/themes.php?page=axle-page-template-usage&tab=history' );
		await expect( page.locator( '.aptu-history-table' ) ).toBeVisible();
		await shot( page, 5 );
	} );

	test( '6: the preview bar on the front end', async ( { page } ) => {
		const url = wp( '--user=admin', 'axle-page-template-usage', 'preview-url', ids[ 1 ], 'default' );
		await page.goto( url );
		await expect( page.locator( '.axle-front-bar' ) ).toBeVisible();
		await shot( page, 6 );
	} );

	test( '7: which pages lose their template under a theme update', async ( { page } ) => {
		original = wp( 'theme', 'list', '--status=active', '--field=name' );
		fixtures.setup();
		for ( const [ title, template ] of [ [ 'Pricing comparison', 'aptu-gone' ], [ 'Press kit', 'aptu-gone' ], [ 'Team', 'page-templates/aptu-wide.php' ] ] ) {
			extra.push( wp( 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${ title }`, `--meta_input={"_wp_page_template":"${ template }"}`, '--porcelain' ) );
		}
		await page.goto( '/wp-admin/themes.php' );
		await page.locator( '.aptu-update-check' ).getByRole( 'button', { name: 'Check the update' } ).click();
		await expect( page.locator( '.aptu-update-check .aptu-table' ).first() ).toBeVisible();
		await page.locator( '.aptu-update-check' ).scrollIntoViewIfNeeded();
		await shot( page, 7 );
	} );
} );
