// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );

const PAGES = [
	[ 'Home', '' ],
	[ 'About us', 'page-no-title' ],
	[ 'Contact', '' ],
	[ 'Pricing', 'page-no-title' ],
	[ 'Spring sale landing', 'templates/landing.php' ],
	[ 'Webinar signup', 'templates/landing.php' ],
];

test( 'demo: find the pages on a missing template and move them', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Example Shop' );
	const ids = PAGES.map( ( [ title, template ] ) => {
		const id = wp( 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${ title }`, '--porcelain' );
		if ( template ) {
			wp( 'post', 'meta', 'update', id, '_wp_page_template', template );
		}
		return id;
	} );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/edit.php?post_type=page&orderby=title&order=asc' );
			await demo.start();
			await demo.pause( 1200 );
			await demo.moveTo( page.locator( `#post-${ ids[ 1 ] } .column-axle_page_template_usage` ) );
			await demo.pause( 900 );
			await demo.moveTo( page.locator( `#post-${ ids[ 4 ] } .column-axle_page_template_usage` ) );
			await demo.pause( 1200 );

			// Only the pages whose template is gone.
			await demo.choose( page.locator( '#filter-by-template' ), '__missing' );
			await demo.click( page.locator( '#post-query-submit' ), 300 );
			await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );
			await demo.pause( 1600 );

			// Move them to a template the theme has, from Appearance > Axle Page Template Usage.
			await demo.moveTo( page.locator( '#menu-appearance > a' ) );
			const report = page.locator( '#menu-appearance .wp-submenu a[href$="page=axle-page-template-usage"]' );
			try {
				await expect( report ).toBeVisible( { timeout: 3000 } );
				await demo.pause( 400 );
				await demo.click( report, 300 );
			} catch ( error ) {
				// The flyout did not open on hover.
				await page.goto( '/wp-admin/themes.php?page=axle-page-template-usage' );
			}
			const section = page.locator( '.aptu-report__section', { has: page.getByRole( 'heading', { name: 'Pages' } ) } );
			const missing = section.locator( 'tr[data-template="templates/landing.php"]' );
			await expect( missing ).toBeVisible();
			await demo.pause( 1800 );
			await demo.choose( missing.locator( 'select[name="to"]' ), 'page-no-title' );
			await demo.click( missing.getByRole( 'button', { name: 'Move' } ), 300 );
			await demo.click( page.getByRole( 'button', { name: 'Move the items' } ), 300 );
			await expect( page.locator( '.notice-success' ) ).toContainText( 'Moved 2 items' );
			await demo.pause( 2000 );

			await page.goto( '/wp-admin/edit.php?post_type=page&orderby=axle_page_template_usage&order=asc' );
			await expect( page.locator( '.aptu-missing-notice' ) ).toHaveCount( 0 );
			await demo.moveTo( page.locator( `#post-${ ids[ 4 ] } .column-axle_page_template_usage` ) );
			await demo.pause( 1500 );
		} );
	} finally {
		wp( 'post', 'delete', ...ids, '--force' );
	}
} );
