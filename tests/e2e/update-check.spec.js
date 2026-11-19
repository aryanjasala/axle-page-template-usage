const { test, expect, wp, wpEval, logInAs } = require( './utils' );
const fixtures = require( './update-fixtures' );

const THEME = fixtures.THEME;
const REPORT = '/wp-admin/themes.php?page=axle-page-template-usage';
const ROLE = 'aptu_upd_limited';
const USER = 'aptu-upd-limited';
// Test-only password for a user on the throwaway Docker site.
const PASS = 'aptu-upd-limited-e2e';

const metaOf = ( id ) => wpEval( `echo get_post_meta( ${ id }, '_wp_page_template', true );` );
const history = ( ...args ) => JSON.parse( wp( 'axle-page-template-usage', 'history', '--format=json', ...args ) );
const createPage = ( title, template, status = 'publish' ) => {
	const id = Number( wp( 'post', 'create', '--post_type=page', `--post_status=${ status }`, `--post_title=${ title }`, '--porcelain' ) );
	wp( 'post', 'meta', 'update', String( id ), '_wp_page_template', template );
	return id;
};
const cliCheck = ( ...args ) => {
	try {
		return { code: 0, out: wp( 'axle-page-template-usage', 'check-update', ...args ) };
	} catch ( error ) {
		return { code: error.status, out: String( error.stdout || '' ) };
	}
};

test.describe( 'Check a theme update before it runs', () => {
	test.describe.configure( { mode: 'serial' } );

	let original;
	const ids = {};

	test.beforeAll( () => {
		original = wp( 'theme', 'list', '--status=active', '--field=name' );
		if ( THEME === original ) {
			original = 'twentytwentyfive';
		}
		// Leftovers of a run that stopped halfway would change every count below.
		fixtures.teardown( original );
		wpEval( "global $wpdb; $wpdb->query( 'TRUNCATE TABLE ' . AryanX\\Axle\\PageTemplateUsage\\History::table() );" );
		fixtures.setup();

		ids.gone1 = createPage( 'UC Gone one', 'aptu-gone' );
		ids.gone2 = createPage( 'UC Gone two', 'aptu-gone' );
		ids.secret = createPage( 'UC Secret private page', 'aptu-gone', 'private' );
		ids.wide = createPage( 'UC Wide', 'page-templates/aptu-wide.php' );
		ids.keep = createPage( 'UC Landing', 'aptu-landing' );
		wp( 'role', 'create', ROLE, 'Update limited' );
		for ( const cap of [ 'read', 'edit_pages', 'switch_themes', 'update_themes' ] ) {
			wp( 'cap', 'add', ROLE, cap );
		}
		wp( 'user', 'create', USER, 'limited@example.test', `--role=${ ROLE }`, `--user_pass=${ PASS }` );
	} );

	test.afterAll( () => {
		wp( 'post', 'delete', ...Object.values( ids ).map( String ), '--force' );
		fixtures.teardown( original );
	} );

	test( 'the Themes screen offers the check, and reading the update runs none of its code and leaves nothing behind', async ( { page } ) => {
		await page.goto( '/wp-admin/themes.php' );
		const panel = page.locator( '.aptu-update-check' );
		await expect( panel ).toContainText( 'Which pages lose their template?' );
		await expect( panel ).toContainText( 'APTU Update has an update, from 1.0.0 to 2.0.0.' );

		await panel.getByRole( 'button', { name: 'Check the update' } ).click();
		await expect( page.locator( '.aptu-live' ) ).toContainText( 'nothing was installed' );
		await expect( panel.locator( '.axle-badge--warning' ) ).toHaveText( 'Some pages are affected' );
		await expect( panel ).toContainText( '4 items would lose their template' );

		// One row per template the update takes away: the block template and the classic one.
		await expect( panel.locator( 'tr[data-template="aptu-gone"]' ) ).toContainText( '3' );
		await expect( panel.locator( 'tr[data-template="page-templates/aptu-wide.php"]' ) ).toContainText( 'APTU Wide' );
		await expect( panel.locator( 'tr[data-template="aptu-landing"]' ) ).toHaveCount( 0 );

		// Still on 1.0.0: nothing was installed, and no code from the package ran.
		expect( wp( 'theme', 'get', THEME, '--field=version' ) ).toBe( '1.0.0' );
		const traces = fixtures.traces();
		expect( traces.ran ).toBe( false );
		expect( traces.functions ).toBe( false );
		expect( traces.leftovers ).toBe( '' );
	} );

	test( 'a person who cannot read private pages gets counts without them, and no private title', async ( { browser } ) => {
		const { context, page } = await logInAs( browser, USER, PASS );
		await page.goto( '/wp-admin/themes.php' );
		const panel = page.locator( '.aptu-update-check' );
		await expect( panel ).toContainText( '3 items would lose their template' );
		await expect( panel.locator( 'tr[data-template="aptu-gone"]' ) ).toContainText( '2' );
		await expect( panel ).not.toContainText( 'UC Secret private page' );

		// The same rule for the Templates tab, with no update involved.
		await page.goto( REPORT );
		const row = page.locator( 'tr[data-template="aptu-gone"]' ).first();
		await expect( row.locator( 'td.num' ) ).toHaveText( '2' );
		await context.close();
	} );

	test( 'WP-CLI lists the same, counting every item, and exits with 1', () => {
		const found = cliCheck( THEME, '--cached', '--format=json' );
		expect( found.code ).toBe( 1 );
		const rows = JSON.parse( found.out );
		expect( rows.map( ( row ) => [ row.slug, row.count ] ).sort() ).toEqual( [ [ 'aptu-gone', 3 ], [ 'page-templates/aptu-wide.php', 1 ] ] );
		expect( metaOf( ids.gone1 ) ).toBe( 'aptu-gone' );
	} );

	test( 'the ability reads the update with the same rules, and is not offered to someone without the update capability', async ( { rest, browser } ) => {
		const ran = await rest( 'POST', '/wp-abilities/v1/abilities/axle-page-template-usage/check-theme-update/run', { input: {} } );
		expect( ran.status ).toBe( 200 );
		expect( ran.body ).toMatchObject( { theme: THEME, from: '1.0.0', to: '2.0.0', total: 4 } );
		expect( ran.body.templates.map( ( row ) => [ row.slug, row.count ] ).sort() ).toEqual( [ [ 'aptu-gone', 3 ], [ 'page-templates/aptu-wide.php', 1 ] ] );
		expect( fixtures.traces().leftovers ).toBe( '' );

		// A role that may edit pages but not update themes is refused.
		wp( 'cap', 'remove', ROLE, 'update_themes' );
		const { context, page } = await logInAs( browser, USER, PASS );
		await page.goto( '/wp-admin/themes.php' );
		await expect( page.locator( '.aptu-update-check' ) ).toHaveCount( 0 );
		await context.close();
		wp( 'cap', 'add', ROLE, 'update_themes' );
	} );

	test( 'a plan is saved, moves the pages right after the update, and the move has an Undo', async ( { page } ) => {
		await page.goto( '/wp-admin/themes.php' );
		const panel = page.locator( '.aptu-update-check' );
		await panel.locator( 'tr[data-template="aptu-gone"] select' ).selectOption( 'aptu-landing' );
		await panel.getByRole( 'button', { name: 'Move them right after the update' } ).click();
		await expect( page.locator( '.aptu-live' ) ).toContainText( 'Saved.' );
		await expect( page.locator( '.aptu-update-plan' ) ).toContainText( 'A plan is saved' );

		const plan = JSON.parse( wpEval( 'echo wp_json_encode( get_option( "axle_page_template_usage_update_plans" ) );' ) );
		expect( plan[ THEME ].map ).toEqual( { page: { 'aptu-gone': 'aptu-landing', 'page-templates/aptu-wide.php': 'default' } } );
		// Saving changes nothing yet.
		expect( metaOf( ids.gone1 ) ).toBe( 'aptu-gone' );
		expect( history( '--source=theme_update' ) ).toHaveLength( 0 );

		// A plan can be removed, and saved again.
		await page.locator( '.aptu-update-plan' ).getByRole( 'button', { name: 'Remove the plan' } ).click();
		await expect( page.locator( '.aptu-live' ) ).toContainText( 'plan was removed' );
		await expect( page.locator( '.aptu-update-plan' ) ).toHaveCount( 0 );
		await page.locator( '.aptu-update-check tr[data-template="aptu-gone"] select' ).selectOption( 'aptu-landing' );
		await page.getByRole( 'button', { name: 'Move them right after the update' } ).click();
		await expect( page.locator( '.aptu-update-plan' ) ).toBeVisible();

		// Install the update, the way "Update now" does.
		wp( 'theme', 'update', THEME );
		expect( wp( 'theme', 'get', THEME, '--field=version' ) ).toBe( '2.0.0' );
		// The next admin request runs the plan.
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.aptu-switch-notice' ) ).toContainText( 'moved 4 items' );
		expect( metaOf( ids.gone1 ) ).toBe( 'aptu-landing' );
		expect( metaOf( ids.secret ) ).toBe( 'aptu-landing' );
		expect( metaOf( ids.wide ) ).toBe( 'default' );
		expect( metaOf( ids.keep ) ).toBe( 'aptu-landing' );

		const moves = history( '--source=theme_update' );
		expect( moves ).toHaveLength( 4 );
		expect( new Set( moves.map( ( entry ) => entry.batch ) ).size ).toBe( 1 );
		expect( wpEval( 'echo get_option( "axle_page_template_usage_update_plans" ) ? "left" : "gone";' ) ).toBe( 'gone' );

		await page.locator( '.aptu-switch-notice' ).getByRole( 'button', { name: /Undo/ } ).click();
		await page.getByRole( 'button', { name: 'Undo the changes' } ).click();
		await expect( page.locator( '.notice-success' ).first() ).toContainText( 'Undone: 4 pages' );
		expect( metaOf( ids.gone1 ) ).toBe( 'aptu-gone' );
		expect( metaOf( ids.wide ) ).toBe( 'page-templates/aptu-wide.php' );
	} );

	test( 'a theme that is not active has nothing to check, and leaves nothing behind', () => {
		const refused = cliCheck( 'twentytwentyfive' );
		expect( refused.code ).not.toBe( 0 );
		expect( fixtures.traces().leftovers ).toBe( '' );
	} );
} );
