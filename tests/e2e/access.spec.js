const { test, expect, wp, wpEval, logInAs } = require( './utils' );

// Someone who may edit pages but not posts sees nothing about posts: not on the
// screens, and not through the abilities, however the post type is spelled.
const ROLE = 'aptu_pages_only';
const USER = 'aptu-pages-only';
// Test-only password for a user on the throwaway Docker site.
const PASS = 'aptu-pages-only-e2e';
const PAGE_GONE = 'templates/access-landing.php';
const POST_GONE = 'templates/secret-post.php';
const REPORT = '/wp-admin/themes.php?page=axle-page-template-usage';

test.describe( 'Access: only the post types you can edit', () => {
	const ids = {};

	const quietly = ( ...args ) => {
		try {
			wp( ...args );
		} catch ( error ) {
			// Not there yet.
		}
	};

	test.beforeAll( () => {
		// The test site reuses post IDs from run to run, so start from an empty history.
		wpEval( "global $wpdb; $wpdb->query( 'TRUNCATE TABLE ' . AryanX\\Axle\\PageTemplateUsage\\History::table() );" );
		quietly( 'user', 'delete', USER, '--yes' );
		quietly( 'role', 'delete', ROLE );
		wp( 'role', 'create', ROLE, 'Pages only' );
		wp( 'cap', 'add', ROLE, 'read', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages' );
		wp( 'user', 'create', USER, `${ USER }@example.com`, `--role=${ ROLE }`, `--user_pass=${ PASS }` );

		const create = ( type, title, template ) => {
			const id = Number( wp( 'post', 'create', `--post_type=${ type }`, '--post_status=publish', `--post_title=${ title }`, '--porcelain' ) );
			wp( 'post', 'meta', 'update', String( id ), '_wp_page_template', template );
			return id;
		};
		ids.page = create( 'page', 'APTU access page', PAGE_GONE );
		ids.post = create( 'post', 'APTU access secret post', POST_GONE );
	} );

	test.afterAll( () => {
		wp( 'post', 'delete', String( ids.page ), String( ids.post ), '--force' );
		quietly( 'user', 'delete', USER, '--yes' );
		quietly( 'role', 'delete', ROLE );
	} );

	test( 'the report, the theme check and the history only cover what the user can edit', async ( { browser, page } ) => {
		// The admin can edit both, so sees both.
		await page.goto( REPORT );
		await expect( page.locator( '.aptu-report__section h2' ) ).toHaveText( [ 'Posts', 'Pages' ] );
		await expect( page.locator( '.nav-tab-wrapper a' ) ).toHaveText( [ 'Templates', 'Missing 2', 'Check a theme', 'History' ] );

		const { context, page: user } = await logInAs( browser, USER, PASS );

		await user.goto( REPORT );
		await expect( user.locator( '.aptu-report__section h2' ) ).toHaveText( [ 'Pages' ] );
		await expect( user.locator( '.aptu-report' ) ).not.toContainText( POST_GONE );
		await expect( user.locator( `.aptu-report__section tr[data-template="${ PAGE_GONE }"]` ) ).toContainText( '1' );

		// The theme check lists installed themes, so it needs switch_themes as well.
		await expect( user.locator( '.nav-tab-wrapper a' ) ).toHaveText( [ 'Templates', 'Missing 1', 'History' ] );
		await user.goto( `${ REPORT }&tab=theme&theme=twentytwentyfour` );
		await expect( user.locator( '.aptu-theme-picker' ) ).toHaveCount( 0 );
		await expect( user.locator( '.aptu-report__section h2' ) ).toHaveText( [ 'Pages' ] );

		await user.goto( `${ REPORT }&tab=history` );
		await expect( user.locator( '.aptu-history-table' ) ).toContainText( 'APTU access page' );
		await expect( user.locator( '.aptu-history-table' ) ).not.toContainText( 'APTU access secret post' );
		await expect( user.locator( '.aptu-history-table' ) ).not.toContainText( POST_GONE );

		// Asking for the post's history directly finds nothing either.
		await user.goto( `${ REPORT }&tab=history&post=${ ids.post }` );
		await expect( user.locator( '.aptu-history-table' ) ).toHaveCount( 0 );
		await expect( user.locator( '.axle-empty' ) ).toContainText( 'No template change matches these filters.' );

		await context.close();
	} );

	test( 'abilities refuse a post type the user cannot edit, however it is spelled', async ( { browser, rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		const path = ( name ) => `/wp-abilities/v1/abilities/axle-page-template-usage/${ name }/run`;

		// The admin can edit posts, so the report covers them.
		const admin = await rest( 'GET', `${ path( 'get-report' ) }?input[post_type]=post` );
		expect( admin.status ).toBe( 200 );
		expect( admin.body ).toMatchObject( { post_type: 'post', missing: 1 } );

		const { context, page } = await logInAs( browser, USER, PASS );
		const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const run = async ( name, input, method = 'GET' ) => {
			const query = 'GET' === method ? Object.entries( input ).map( ( [ key, value ] ) => `&input[${ key }]=${ encodeURIComponent( value ) }` ).join( '' ) : '';
			const response = await page.request.fetch( `/index.php?rest_route=${ encodeURIComponent( path( name ) ) }${ query }`, {
				method,
				headers: { 'X-WP-Nonce': nonce },
				data: 'GET' === method ? undefined : { input },
			} );
			return { status: response.status(), body: await response.json().catch( () => null ) };
		};

		const pages = await run( 'get-report', { post_type: 'page' } );
		expect( pages.status ).toBe( 200 );
		expect( pages.body.templates.find( ( row ) => row.slug === PAGE_GONE ) ).toMatchObject( { status: 'missing', count: 1 } );

		for ( const postType of [ 'post', 'POST', ' post', 'nope' ] ) {
			const refused = await run( 'get-report', { post_type: postType } );
			expect( refused.status, `get-report for "${ postType }"` ).toBe( 403 );
			expect( JSON.stringify( refused.body ) ).not.toContain( POST_GONE );
		}

		// The missing-templates ability only covers the post types the user can edit.
		const missingAll = await run( 'get-missing', {} );
		expect( missingAll.status ).toBe( 200 );
		expect( missingAll.body.templates.map( ( row ) => row.post_type ) ).toEqual( [ 'page' ] );
		expect( JSON.stringify( missingAll.body ) ).not.toContain( POST_GONE );
		expect( ( await run( 'get-missing', { post_type: 'Post' } ) ).status ).toBe( 403 );
		// A preview link for a page needs the right to edit that page.
		expect( ( await run( 'get-preview-url', { post_id: ids.post, template: 'default' } ) ).status ).toBe( 403 );

		expect( ( await run( 'reassign', { post_type: 'Post', from: 'missing', to: 'default' }, 'POST' ) ).status ).toBe( 403 );
		expect( wp( 'post', 'meta', 'get', String( ids.post ), '_wp_page_template' ) ).toBe( POST_GONE );

		expect( ( await run( 'check-theme', { theme: 'twentytwentyfour' } ) ).status ).toBe( 403 );

		const history = await run( 'get-history', { limit: 200 } );
		expect( history.status ).toBe( 200 );
		const changed = history.body.entries.map( ( entry ) => entry.post_id );
		expect( changed ).toContain( ids.page );
		expect( changed ).not.toContain( ids.post );
		expect( ( await run( 'get-history', { post_id: ids.post } ) ).body ).toMatchObject( { total: 0, entries: [] } );

		await context.close();
	} );

	test( 'an item moved to a post type the user cannot edit keeps its title and template out of the history', async ( { browser } ) => {
		// The entry was recorded while it was a page. Now it is a post. (wp post update refuses: its template is missing.)
		wpEval( `set_post_type( ${ ids.page }, 'post' );` );

		const { context, page: user } = await logInAs( browser, USER, PASS );
		await user.goto( `${ REPORT }&tab=history&post=${ ids.page }` );

		const table = user.locator( '.aptu-history-table' );
		await expect( table ).toContainText( `A page you cannot view (ID ${ ids.page })` );
		await expect( table ).not.toContainText( 'APTU access page' );
		await expect( table.locator( '.aptu-undo-reason' ) ).toHaveText( 'You are not allowed to edit this page.' );
		await expect( user.locator( '.aptu-history__scope' ) ).not.toContainText( 'APTU access page' );

		await context.close();
	} );
} );
