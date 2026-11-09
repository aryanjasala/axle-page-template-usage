// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );

test.describe( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 760 }, deviceScaleFactor: 2 } );
	const ids = [];

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
} );
