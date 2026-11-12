const { test, expect, wp, wpEval, logInAs } = require( './utils' );

// The theme the suite runs with offers "page-no-title", a block template that hides the page title.
const NO_TITLE = 'page-no-title';
const GONE = 'templates/aptu-retired.php';
const REPORT = '/wp-admin/themes.php?page=axle-page-template-usage';
const MISSING = `${ REPORT }&tab=missing`;
const ROLE = 'aptu_pages_only_2';
const USER = 'aptu-pages-only-2';
// Test-only password for a user on the throwaway Docker site.
const PASS = 'aptu-pages-only-e2e-2';

const metaOf = ( id ) => wpEval( `echo get_post_meta( ${ id }, '_wp_page_template', true );` );
const history = ( ...args ) => JSON.parse( wp( 'axle-page-template-usage', 'history', '--format=json', ...args ) );
const createPage = ( title, template ) => {
	const id = Number( wp( 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${ title }`, '--post_content=Body text', '--porcelain' ) );
	if ( template ) {
		wp( 'post', 'meta', 'update', String( id ), '_wp_page_template', template );
	}
	return id;
};
const quietly = ( ...args ) => {
	try {
		return wp( ...args );
	} catch ( error ) {
		return String( error.stdout || '' );
	}
};
// `wp ... missing` exits with 1 when anything is missing.
const missingCli = ( ...args ) => {
	try {
		return { code: 0, out: wp( 'axle-page-template-usage', 'missing', ...args ) };
	} catch ( error ) {
		return { code: error.status, out: String( error.stdout || '' ) };
	}
};

test.describe( 'Missing templates', () => {
	test.describe.configure( { mode: 'serial' } );

	const ids = {};

	test.beforeAll( () => {
		wp( 'option', 'delete', 'axle_page_template_usage_missing_state' );
		wp( 'option', 'delete', 'axle_page_template_usage_missing_alert' );
		ids.a = createPage( 'MT Alpha', GONE );
		ids.b = createPage( 'MT Beta', GONE );
		// Setting up the pages was recorded too. Start the history from here.
		wpEval( "global $wpdb; $wpdb->query( 'TRUNCATE TABLE ' . AryanX\\Axle\\PageTemplateUsage\\History::table() );" );
	} );

	test.afterAll( () => {
		wp( 'post', 'delete', ...Object.values( ids ).map( String ), '--force' );
		wp( 'option', 'delete', 'axle_page_template_usage_missing_state' );
		wp( 'option', 'delete', 'axle_page_template_usage_missing_alert' );
		quietly( 'user', 'meta', 'delete', '1', 'axle_page_template_usage_missing_dismissed' );
	} );

	test( 'WP-CLI lists the missing template and exits with 1, and a clean site exits with 0', () => {
		const found = missingCli( '--format=json' );
		expect( found.code ).toBe( 1 );
		const rows = JSON.parse( found.out );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( { post_type: 'page', slug: GONE, count: 2, suggestion: 'default', reason: 'default' } );

		// Nothing was changed by looking.
		expect( metaOf( ids.a ) ).toBe( GONE );
		expect( history() ).toHaveLength( 0 );
	} );

	test( 'the Missing tab lists it with a suggestion, previews the move and moves it with an Undo', async ( { page } ) => {
		await page.goto( MISSING );
		await expect( page.locator( '.nav-tab-wrapper a' ).nth( 1 ) ).toHaveText( 'Missing 2' );

		const row = page.locator( `tr[data-template="${ GONE }"]` );
		await expect( row ).toContainText( 'No close match, so the default template' );
		await expect( row.locator( 'time.axle-time' ) ).toHaveCount( 1 );

		await row.locator( 'select[name="mv_to"]' ).selectOption( NO_TITLE );
		await row.getByRole( 'button', { name: 'Preview the move' } ).click();

		const preview = page.locator( '.aptu-move-preview' );
		await expect( preview.locator( '.axle-diff' ) ).toContainText( GONE );
		await expect( preview.locator( '.axle-diff' ) ).toContainText( 'Page No Title' );
		await expect( preview.locator( '.aptu-move-sample li' ) ).toHaveCount( 2 );
		await expect( preview.locator( '.axle-confirm' ) ).toContainText( 'This changes the template of 2 pages to "Page No Title".' );
		// The preview changed nothing.
		expect( metaOf( ids.a ) ).toBe( GONE );

		await preview.getByRole( 'button', { name: 'Move 2 pages' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Moved 2 items to "Page No Title".' );
		await expect( page.locator( '.aptu-missing-empty' ) ).toBeVisible();
		expect( metaOf( ids.a ) ).toBe( NO_TITLE );
		expect( metaOf( ids.b ) ).toBe( NO_TITLE );
		expect( history() ).toHaveLength( 2 );

		// The move is in the history with an Undo.
		await page.locator( '.notice-success' ).getByRole( 'button', { name: 'Undo this batch' } ).click();
		await page.getByRole( 'button', { name: 'Undo the changes' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undone: 2 pages' );
		expect( metaOf( ids.a ) ).toBe( GONE );
		expect( metaOf( ids.b ) ).toBe( GONE );
	} );

	test( 'a template that moved folders is suggested by its file name', async ( { page } ) => {
		const moved = 'old-folder/page-no-title';
		wp( 'post', 'meta', 'update', String( ids.b ), '_wp_page_template', moved );
		quietly( 'axle-page-template-usage', 'missing' );

		await page.goto( MISSING );
		const row = page.locator( `tr[data-template="${ moved }"]` );
		await expect( row ).toContainText( 'Same file name in another folder' );
		await expect( row.locator( 'select[name="mv_to"]' ) ).toHaveValue( NO_TITLE );

		wp( 'post', 'meta', 'update', String( ids.b ), '_wp_page_template', GONE );
	} );

	test( 'the dashboard notice can be dismissed, and Site Health reports the problem', async ( { page } ) => {
		quietly( 'axle-page-template-usage', 'missing' );
		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '.aptu-missing-alert' );
		await expect( notice ).toContainText( '2 items use a template the theme no longer has' );
		await expect( notice.getByRole( 'link', { name: 'Review the missing templates' } ) ).toHaveAttribute( 'href', /tab=missing/ );

		const health = JSON.parse( wpEval( "wp_set_current_user( 1 ); echo wp_json_encode( ( new AryanX\\Axle\\PageTemplateUsage\\Health( AryanX\\Axle\\PageTemplateUsage\\Plugin::instance()->missing() ) )->run() );" ) );
		expect( health.status ).toBe( 'recommended' );
		expect( health.description ).toContain( GONE );

		await notice.getByRole( 'link', { name: /Dismiss/ } ).click();
		await expect( page.locator( '.aptu-missing-alert' ) ).toHaveCount( 0 );
		await page.goto( '/wp-admin/themes.php' );
		await expect( page.locator( '.aptu-missing-alert' ) ).toHaveCount( 0 );
	} );

	test( 'the email is off until it is turned on, then a new missing template sends one', async ( { page } ) => {
		await page.goto( MISSING );
		await expect( page.getByLabel( 'Send the email' ) ).not.toBeChecked();
		await page.getByLabel( 'Send the email' ).check();
		await page.getByLabel( 'Send it to' ).fill( 'someone@localhost' );
		await page.getByRole( 'button', { name: 'Save the email settings' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'not valid' );
		expect( quietly( 'option', 'get', 'axle_page_template_usage_missing_alert', '--format=json' ) ).not.toContain( 'enabled' );

		await page.getByLabel( 'Send the email' ).check();
		await page.getByLabel( 'Send it to' ).fill( 'ops@example.test' );
		await page.getByRole( 'button', { name: 'Save the email settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'You get an email' );
		expect( JSON.parse( wp( 'option', 'get', 'axle_page_template_usage_missing_alert', '--format=json' ) ) ).toEqual( { enabled: true, to: 'ops@example.test' } );

		// A page on a second lost template: the scan announces it and hands the mail to wp_mail().
		const extra = createPage( 'MT Gamma', 'templates/aptu-second.php' );
		ids.c = extra;
		const sent = wpEval(
			"$GLOBALS['aptu_mail'] = array(); add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['aptu_mail'][] = $atts; return true; }, 10, 2 );" +
				"AryanX\\Axle\\PageTemplateUsage\\Plugin::instance()->missing()->scan( true, 'manual' ); echo wp_json_encode( $GLOBALS['aptu_mail'] );"
		);
		const mails = JSON.parse( sent );
		expect( mails ).toHaveLength( 1 );
		expect( mails[ 0 ].to ).toEqual( [ 'ops@example.test' ] );
		expect( mails[ 0 ].message ).toContain( 'templates/aptu-second.php (Pages: 1)' );
		expect( mails[ 0 ].message ).not.toContain( GONE );

		wp( 'axle-page-template-usage', 'missing-alert', 'off' );
		expect( JSON.parse( wp( 'option', 'get', 'axle_page_template_usage_missing_alert', '--format=json' ) ).enabled ).toBe( false );
	} );

	test( 'the daily scan and a theme update are hooked in', () => {
		expect( wp( 'cron', 'event', 'list', '--format=csv' ) ).toContain( 'axle_page_template_usage_scan' );
		wp( 'cron', 'event', 'run', 'axle_page_template_usage_scan' );
	} );
} );

test.describe( 'Preview with another template', () => {
	test.describe.configure( { mode: 'serial' } );

	const ids = {};

	test.beforeAll( () => {
		wpEval( "global $wpdb; $wpdb->query( 'TRUNCATE TABLE ' . AryanX\\Axle\\PageTemplateUsage\\History::table() );" );
		quietly( 'user', 'delete', USER, '--yes' );
		quietly( 'role', 'delete', ROLE );
		wp( 'role', 'create', ROLE, 'Pages only two' );
		wp( 'cap', 'add', ROLE, 'read', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages' );
		wp( 'user', 'create', USER, `${ USER }@example.com`, `--role=${ ROLE }`, `--user_pass=${ PASS }` );
		ids.page = createPage( 'PV Page' );
		ids.post = Number( wp( 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=PV Secret post', '--post_content=Secret body', '--porcelain' ) );
	} );

	test.afterAll( () => {
		wp( 'post', 'delete', String( ids.page ), String( ids.post ), '--force' );
		quietly( 'user', 'delete', USER, '--yes' );
		quietly( 'role', 'delete', ROLE );
	} );

	test( 'a page row has "Preview with...", and the preview shows the template without saving it', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=page' );
		const row = page.locator( `#post-${ ids.page }` );
		await row.hover();
		await row.getByRole( 'link', { name: /Preview .* with another template/ } ).click();
		await expect( page.locator( '.aptu-preview-table' ) ).toContainText( 'Page No Title' );
		await expect( page.locator( '.aptu-preview-table tr[data-template="default"] .axle-badge' ) ).toHaveText( 'Current' );

		// The normal page shows its title.
		await page.goto( `/?page_id=${ ids.page }` );
		await expect( page.locator( '.wp-block-post-title' ) ).toHaveCount( 1 );

		await page.goto( `${ REPORT }&tab=preview&post=${ ids.page }` );
		const response = page.waitForResponse( ( r ) => r.url().includes( 'axle_ptu_preview' ) && r.request().resourceType() === 'document' );
		await page.locator( `tr[data-template="${ NO_TITLE }"]` ).getByRole( 'link', { name: /Open the preview/ } ).click();
		const served = await response;

		expect( served.headers()[ 'x-robots-tag' ] ).toContain( 'noindex' );
		expect( served.headers()[ 'cache-control' ] ).toContain( 'no-cache' );
		await expect( page.locator( '.wp-block-post-title' ) ).toHaveCount( 0 );
		await expect( page.locator( 'meta[name="robots"]' ) ).toHaveAttribute( 'content', /noindex/ );
		const bar = page.locator( '.aptu-preview-bar' );
		await expect( bar ).toContainText( 'Previewing "PV Page" with the template "Page No Title". Nothing is saved.' );

		// Saved nothing, recorded nothing.
		expect( metaOf( ids.page ) ).toBe( '' );
		expect( history() ).toHaveLength( 0 );

		// Without the login, the same address is the normal page.
		const previewUrl = page.url();
		const anon = await ( await page.context().browser().newContext( { storageState: { cookies: [], origins: [] } } ) );
		const anonPage = await anon.newPage();
		await anonPage.goto( previewUrl );
		await expect( anonPage.locator( '.aptu-preview-bar' ) ).toHaveCount( 0 );
		await expect( anonPage.locator( '.wp-block-post-title' ) ).toHaveCount( 1 );
		await anon.close();

		// A link with a changed template does not work: the nonce was made for another one.
		await page.goto( previewUrl.replace( `axle_ptu_preview=${ NO_TITLE }`, 'axle_ptu_preview=default' ) );
		await expect( page.locator( '.aptu-preview-bar' ) ).toHaveCount( 0 );

		// "Use this template" saves it through the history, with a notice and an Undo.
		await page.goto( previewUrl );
		await page.locator( '.aptu-preview-bar' ).getByRole( 'button', { name: 'Use this template' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Moved 1 item to "Page No Title".' );
		expect( metaOf( ids.page ) ).toBe( NO_TITLE );
		expect( history( `--post=${ ids.page }` )[ 0 ] ).toMatchObject( { from: 'default', to: NO_TITLE, source: 'preview', undoable: 'yes' } );
		await page.locator( '.notice-success' ).getByRole( 'button', { name: 'Undo this batch' } ).click();
		await page.getByRole( 'button', { name: 'Undo the changes' } ).click();
		expect( metaOf( ids.page ) ).toBe( 'default' );
	} );

	test( 'the Close button goes back to the editor', async ( { page } ) => {
		const url = wp( '--user=1', 'axle-page-template-usage', 'preview-url', String( ids.page ), NO_TITLE );
		await page.goto( url );
		await page.locator( '.aptu-preview-bar' ).getByRole( 'link', { name: 'Close' } ).click();
		await expect( page ).toHaveURL( new RegExp( `post=${ ids.page }` ) );
	} );

	test( 'someone who can edit pages but not posts cannot preview a post, and sees no post template', async ( { browser } ) => {
		// The CLI and the ability make links for the user given.
		expect( wp( `--user=${ USER }`, 'axle-page-template-usage', 'preview-url', String( ids.page ), NO_TITLE ) ).toContain( 'axle_ptu_preview' );

		// A link made for the admin does nothing for this user: it is signed for the admin.
		const adminLink = wp( '--user=1', 'axle-page-template-usage', 'preview-url', String( ids.page ), NO_TITLE );
		const { context, page } = await logInAs( browser, USER, PASS );
		await page.goto( adminLink );
		await expect( page.locator( '.aptu-preview-bar' ) ).toHaveCount( 0 );
		await expect( page.locator( '.wp-block-post-title' ) ).toHaveCount( 1 );

		// Asking for the post in the picker shows nothing: the user may not edit posts.
		await page.goto( `${ REPORT }&tab=preview&post=${ ids.post }` );
		await expect( page.locator( '.aptu-preview-table' ) ).toHaveCount( 0 );
		await expect( page.locator( '.aptu-preview-empty' ) ).toBeVisible();

		// No row action on the posts list either, if the user could open it.
		await page.goto( `${ REPORT }&tab=preview&post=${ ids.page }` );
		await expect( page.locator( '.aptu-preview-table' ) ).toBeVisible();

		// The post's lost template stays out of the Missing tab and the Missing ability.
		wp( 'post', 'meta', 'update', String( ids.post ), '_wp_page_template', 'templates/secret-gone.php' );
		await page.goto( MISSING );
		await expect( page.locator( '.aptu-report' ) ).not.toContainText( 'templates/secret-gone.php' );
		await context.close();
	} );
} );
