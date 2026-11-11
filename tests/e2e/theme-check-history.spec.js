const { test, expect, wp, wpEval } = require( './utils' );
const { BLOCK, CLASSIC, createThemes, removeThemes } = require( './theme-fixtures' );

// The theme the suite runs with offers "page-no-title".
const NO_TITLE = 'page-no-title';
const GONE = 'templates/retired.php';
const LANDING = 'aptu-landing';
const REPORT = '/wp-admin/themes.php?page=axle-page-template-usage';

const metaOf = ( id ) => wpEval( `echo get_post_meta( ${ id }, '_wp_page_template', true );` );
const setMeta = ( id, value ) => wp( 'post', 'meta', 'update', String( id ), '_wp_page_template', value );
const history = ( ...args ) => JSON.parse( wp( 'axle-page-template-usage', 'history', '--format=json', ...args ) );
const createPage = ( title, status = 'publish' ) => Number( wp( 'post', 'create', '--post_type=page', `--post_status=${ status }`, `--post_title=${ title }`, '--porcelain' ) );

test.describe( 'Theme check and template history', () => {
	test.describe.configure( { mode: 'serial' } );

	let original;
	const ids = {};

	test.beforeAll( () => {
		original = wp( 'theme', 'list', '--status=active', '--field=name' );
		// The history table outlives "wp site empty", so start from none.
		wpEval( "global $wpdb; $wpdb->query( 'TRUNCATE TABLE ' . AryanX\\Axle\\PageTemplateUsage\\History::table() );" );
		createThemes();
		ids.plain = createPage( 'TCH Plain' );
		ids.a = createPage( 'TCH Alpha' );
		ids.b = createPage( 'TCH Beta' );
		ids.c = createPage( 'TCH Gamma' );
	} );

	test.afterAll( () => {
		removeThemes( original );
		wp( 'post', 'delete', ...Object.values( ids ).map( String ), '--force' );
		wp( 'option', 'delete', 'axle_page_template_usage_theme_maps' );
		wp( 'option', 'delete', 'axle_page_template_usage_switch_result' );
	} );

	test( 'the history records the editor, Quick Edit, WP-CLI and the Move button, each with its source', async ( { page, rest } ) => {
		const saved = await rest( 'POST', `/wp/v2/pages/${ ids.plain }`, { template: NO_TITLE } );
		expect( saved.status ).toBe( 200 );
		expect( history( `--post=${ ids.plain }` )[ 0 ] ).toMatchObject( { from: 'default', to: NO_TITLE, source: 'editor', user_id: 1, undoable: 'yes' } );

		setMeta( ids.plain, 'default' );
		expect( history( `--post=${ ids.plain }` )[ 0 ] ).toMatchObject( { from: NO_TITLE, to: 'default', source: 'cli', user_id: 0 } );

		await page.goto( '/wp-admin/edit.php?post_type=page' );
		const row = page.locator( `#post-${ ids.plain }` );
		await row.hover();
		await row.locator( 'button.editinline' ).click();
		await page.locator( `#edit-${ ids.plain } select[name="page_template"]` ).selectOption( NO_TITLE );
		await page.locator( `#edit-${ ids.plain } .save` ).click();
		await expect( page.locator( `#post-${ ids.plain } .column-axle_page_template_usage` ) ).toHaveText( 'Page No Title' );
		expect( history( `--post=${ ids.plain }` )[ 0 ] ).toMatchObject( { from: 'default', to: NO_TITLE, source: 'quick_edit', user_id: 1 } );

		// Saving the same template again is not a change.
		const before = history( `--post=${ ids.plain }` ).length;
		setMeta( ids.plain, NO_TITLE );
		expect( history( `--post=${ ids.plain }` ).length ).toBe( before );
		setMeta( ids.plain, 'default' );

		setMeta( ids.b, GONE );
		setMeta( ids.c, GONE );
		await page.goto( REPORT );
		const row2 = page.locator( `tr[data-template="${ GONE }"]` );
		await row2.locator( 'select[name="to"]' ).selectOption( NO_TITLE );
		await row2.getByRole( 'button', { name: 'Move' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Moved 2 items' );
		const moves = history( '--source=bulk_move' );
		expect( moves ).toHaveLength( 2 );
		expect( moves[ 0 ].batch ).toMatch( /^[a-f0-9]{32}$/ );
		expect( moves[ 0 ].batch ).toBe( moves[ 1 ].batch );
		expect( moves.every( ( entry ) => 1 === entry.user_id ) ).toBe( true );
	} );

	test( 'a whole batch is undone from the History tab, after a confirm step', async ( { page } ) => {
		const batch = history( '--source=bulk_move' )[ 0 ].batch;
		await page.goto( `/wp-admin/themes.php?page=axle-page-template-usage&tab=history&batch=${ batch }` );
		await expect( page.locator( '.aptu-batch' ) ).toContainText( 'Not undone yet' );

		let asked = '';
		page.once( 'dialog', ( dialog ) => {
			asked = dialog.message();
			dialog.dismiss();
		} );
		await page.getByRole( 'button', { name: 'Undo this batch' } ).click();
		expect( asked ).toContain( 'Undo up to 2 changes?' );
		expect( asked ).toContain( 'left alone' );
		expect( metaOf( ids.b ) ).toBe( NO_TITLE );

		// A page that changed after the batch is left alone.
		setMeta( ids.c, 'default' );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Undo this batch' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undone: 1 page is back on its earlier template.' );
		await expect( page.locator( '.notice-success' ) ).toContainText( '1 page was left alone' );
		expect( metaOf( ids.b ) ).toBe( GONE );
		expect( metaOf( ids.c ) ).toBe( 'default' );

		const rows = page.locator( '.aptu-history-table tr[data-entry]' );
		await expect( rows.filter( { hasText: 'Already undone.' } ) ).toHaveCount( 1 );
		await expect( rows.filter( { hasText: 'now, not' } ) ).toHaveCount( 1 );
		// The undo is recorded too, under its own batch, so it can be undone as well.
		expect( history( '--source=undo' ) ).toHaveLength( 1 );
	} );

	test( 'one change is undone only while the page still has the template it set, and says why not', async ( { page } ) => {
		setMeta( ids.a, NO_TITLE );
		setMeta( ids.a, GONE );

		await page.goto( '/wp-admin/edit.php?post_type=page' );
		const row = page.locator( `#post-${ ids.a }` );
		await row.hover();
		await row.getByRole( 'link', { name: /Template history/ } ).click();
		await expect( page ).toHaveURL( new RegExp( `tab=history&post=${ ids.a }` ) );
		await expect( page.locator( '.aptu-history__scope' ) ).toContainText( 'TCH Alpha' );

		const entries = page.locator( '.aptu-history-table tr[data-entry]' );
		await expect( entries ).toHaveCount( 2 );
		// Newest first: the second change can be undone, the first one was overtaken by it.
		await expect( entries.nth( 1 ) ).toContainText( 'The page uses' );
		await expect( entries.nth( 1 ) ).toContainText( 'which this change set' );
		await expect( entries.nth( 1 ).getByRole( 'button' ) ).toHaveCount( 0 );
		await entries.nth( 0 ).getByRole( 'button', { name: /Undo/ } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undone: 1 page' );
		expect( metaOf( ids.a ) ).toBe( NO_TITLE );
		await expect( page.locator( '.aptu-history-table tr[data-entry]' ).first() ).toContainText( 'Undo' );
		expect( history( `--post=${ ids.a }` )[ 0 ] ).toMatchObject( { source: 'undo', from: GONE, to: NO_TITLE } );
	} );

	test( 'the History tab filters by source, page and template, and shows an empty state', async ( { page } ) => {
		await page.goto( `${ REPORT }&tab=history` );
		await page.getByLabel( 'Source' ).selectOption( 'bulk_move' );
		await page.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( page ).toHaveURL( /source=bulk_move/ );
		const sources = await page.locator( '.aptu-history-table tr[data-entry] .axle-badge' ).allTextContents();
		expect( sources.length ).toBeGreaterThan( 0 );
		expect( new Set( sources ) ).toEqual( new Set( [ 'Move button' ] ) );

		await page.goto( `${ REPORT }&tab=history&template=${ encodeURIComponent( 'retired' ) }` );
		await expect( page.locator( '.aptu-history-table tr[data-entry]' ).first() ).toContainText( 'templates/retired.php' );

		await page.goto( `${ REPORT }&tab=history&template=zzz-no-such-template` );
		await expect( page.locator( '.axle-empty' ) ).toContainText( 'No template change matches these filters' );
		await page.getByRole( 'link', { name: 'Clear the filters' } ).click();
		await expect( page.locator( '.aptu-history-table' ) ).toBeVisible();
	} );

	test( 'check a theme: lists what would lose its template, saves a plan, and the Themes screen links to it', async ( { page } ) => {
		setMeta( ids.a, NO_TITLE );
		setMeta( ids.b, GONE );
		setMeta( ids.c, 'default' );

		await page.goto( `${ REPORT }&tab=theme` );
		await expect( page.getByRole( 'heading', { name: 'Check a theme before switching' } ) ).toBeVisible();
		await page.getByLabel( 'Theme to check' ).selectOption( BLOCK );
		await page.getByRole( 'button', { name: 'Check theme' } ).click();

		const gone = page.locator( `tr.aptu-map-row[data-template="${ GONE }"]` );
		const noTitle = page.locator( `tr.aptu-map-row[data-template="${ NO_TITLE }"]` );
		await expect( page.locator( '.aptu-theme-result .axle-stat--warning' ) ).toContainText( '2' );
		await expect( noTitle.locator( 'td.num' ) ).toHaveText( '1' );
		await expect( noTitle ).toContainText( 'TCH Alpha' );
		await expect( gone ).toContainText( 'Already missing' );
		// The default template is what is chosen unless the plan says otherwise.
		await expect( noTitle.locator( 'select' ) ).toHaveValue( 'default' );
		await expect( noTitle.locator( 'select option' ) ).toHaveText( [ 'Default', 'APTU Landing' ] );

		await noTitle.locator( 'select' ).selectOption( LANDING );
		await page.getByRole( 'button', { name: 'Save the plan' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'The plan is saved' );
		await expect( page.locator( `tr.aptu-map-row[data-template="${ NO_TITLE }"] select` ) ).toHaveValue( LANDING );
		await expect( page.locator( '.aptu-theme-result .notice-info' ) ).toContainText( 'A plan is saved for APTU Block' );

		const planned = JSON.parse( wp( 'axle-page-template-usage', 'check-theme', BLOCK, '--format=json' ) );
		expect( planned.find( ( row ) => row.slug === NO_TITLE ) ).toMatchObject( { count: 1, planned: LANDING, already_missing: 'no' } );
		expect( planned.find( ( row ) => row.slug === GONE ) ).toMatchObject( { count: 1, planned: 'default', already_missing: 'yes' } );

		await page.goto( '/wp-admin/themes.php' );
		await page.locator( `.theme[data-slug="${ BLOCK }"]` ).click();
		const link = page.locator( '.theme-overlay' ).getByRole( 'link', { name: "Check this theme's templates first" } );
		await expect( link ).toHaveAttribute( 'href', new RegExp( `tab=theme&theme=${ BLOCK }` ) );
		await link.click();
		await expect( page.locator( '#aptu-theme' ) ).toHaveValue( BLOCK );
		await expect( page.locator( '.aptu-theme-result' ) ).toContainText( 'Switching to APTU Block' );
	} );

	test( 'activating the theme moves the pages as planned, shows a notice with Undo, and Undo puts them back', async ( { page } ) => {
		wp( 'theme', 'activate', BLOCK );
		await page.goto( '/wp-admin/themes.php?activated=true' );

		const notice = page.locator( '.aptu-switch-notice' );
		await expect( notice ).toContainText( 'moved 2 items to templates of APTU Block' );
		expect( metaOf( ids.a ) ).toBe( LANDING );
		expect( metaOf( ids.b ) ).toBe( 'default' );

		const moves = history( '--source=theme_switch' );
		expect( moves ).toHaveLength( 2 );
		expect( new Set( moves.map( ( entry ) => entry.batch ) ).size ).toBe( 1 );
		expect( moves.every( ( entry ) => 1 === entry.user_id ) ).toBe( true );
		// The plan was used up.
		expect( wp( 'option', 'get', 'axle_page_template_usage_theme_maps', '--format=json' ) ).toBe( '[]' );

		let asked = '';
		page.once( 'dialog', ( dialog ) => {
			asked = dialog.message();
			dialog.accept();
		} );
		await notice.getByRole( 'button', { name: 'Undo this batch' } ).click();
		expect( asked ).toContain( 'Undo up to 2 changes?' );
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undone: 2 pages' );
		expect( metaOf( ids.a ) ).toBe( NO_TITLE );
		expect( metaOf( ids.b ) ).toBe( GONE );
		await expect( page.locator( '.aptu-switch-notice' ) ).toHaveCount( 0 );

		wp( 'theme', 'activate', original );
		await page.goto( '/wp-admin/themes.php' );
	} );

	test( 'a switch bigger than one batch continues in the background, and the notice says so', async () => {
		const extra = [ ids.a, ids.b, ids.c, createPage( 'TCH Delta' ), createPage( 'TCH Epsilon' ) ];
		ids.d = extra[ 3 ];
		ids.e = extra[ 4 ];
		extra.forEach( ( id ) => setMeta( id, GONE ) );
		wp( 'axle-page-template-usage', 'set-mapping', BLOCK, GONE, LANDING );

		wp( 'theme', 'activate', BLOCK );
		// The first command after the switch moves the first batch. Two per request here.
		wp( '--exec=WP_CLI::add_wp_hook( "plugins_loaded", function () { add_filter( "axle_page_template_usage_switch_batch_size", function () { return 2; } ); } );', 'option', 'get', 'blogname' );
		const state = () => JSON.parse( wpEval( 'echo wp_json_encode( get_option( "axle_page_template_usage_switch_result" ) );' ) );
		expect( state() ).toMatchObject( { moved: 2, remaining: 3 } );
		expect( wp( 'cron', 'event', 'list', '--format=csv' ) ).toContain( 'axle_page_template_usage_continue_switch' );

		wp( 'cron', 'event', 'run', 'axle_page_template_usage_continue_switch' );
		expect( state() ).toMatchObject( { moved: 5, remaining: 0 } );
		expect( extra.map( metaOf ) ).toEqual( Array( 5 ).fill( LANDING ) );
		expect( new Set( history( '--source=theme_switch', '--limit=100' ).slice( 0, 5 ).map( ( entry ) => entry.batch ) ).size ).toBe( 1 );

		const batch = state().batch;
		expect( wp( 'axle-page-template-usage', 'undo-batch', batch ) ).toContain( 'Undone 5 change(s)' );
		expect( extra.map( metaOf ) ).toEqual( Array( 5 ).fill( GONE ) );
		wp( 'axle-page-template-usage', 'undo-batch', batch ).includes( 'Undone 0' );

		wp( 'theme', 'activate', original );
		wp( 'option', 'delete', 'axle_page_template_usage_switch_result' );
		extra.forEach( ( id ) => setMeta( id, 'default' ) );
	} );

	test( 'WP-CLI can run a saved plan on demand, and a filter can turn off the automatic run', async () => {
		setMeta( ids.a, GONE );
		wp( 'axle-page-template-usage', 'set-mapping', BLOCK, GONE, LANDING );
		wp( 'theme', 'activate', BLOCK );
		// Core runs after_switch_theme on the next request. The filter makes this one skip the plan.
		wp( '--exec=WP_CLI::add_wp_hook( "plugins_loaded", function () { add_filter( "axle_page_template_usage_apply_on_switch", "__return_false" ); } );', 'option', 'get', 'blogname' );
		expect( metaOf( ids.a ) ).toBe( GONE );

		expect( wp( 'axle-page-template-usage', 'apply-mapping' ) ).toContain( 'Moved 1 item(s)' );
		expect( metaOf( ids.a ) ).toBe( LANDING );
		expect( history( `--post=${ ids.a }` )[ 0 ] ).toMatchObject( { source: 'theme_switch', to: LANDING } );

		expect( () => wp( 'axle-page-template-usage', 'apply-mapping' ) ).toThrow( /No plan is saved/ );
		const entry = history( `--post=${ ids.a }` )[ 0 ];
		expect( wp( 'axle-page-template-usage', 'undo', String( entry.id ) ) ).toContain( 'Undone.' );
		expect( metaOf( ids.a ) ).toBe( GONE );
		expect( () => wp( 'axle-page-template-usage', 'undo', String( entry.id ) ) ).toThrow( /Already undone/ );

		wp( 'theme', 'activate', original );
		wp( 'option', 'delete', 'axle_page_template_usage_switch_result' );
		setMeta( ids.a, 'default' );
	} );

	test( 'a plan only accepts templates the theme has, and checking the active theme is refused', () => {
		setMeta( ids.b, GONE );
		expect( () => wp( 'axle-page-template-usage', 'set-mapping', BLOCK, GONE, 'nope.php' ) ).toThrow( /does not have the template/ );
		expect( () => wp( 'axle-page-template-usage', 'set-mapping', BLOCK, 'templates/nobody-uses.php', 'default' ) ).toThrow( /nothing to plan/ );
		setMeta( ids.b, 'default' );
		expect( () => wp( 'axle-page-template-usage', 'check-theme', original ) ).toThrow( /already active/ );
		expect( () => wp( 'axle-page-template-usage', 'check-theme', 'no-such-theme' ) ).toThrow( /not installed/ );
	} );

	test( 'classic themes: PHP templates count, and so do templates saved in the Site Editor for a block theme', () => {
		setMeta( ids.a, 'page-templates/wide.php' );
		setMeta( ids.b, GONE );
		setMeta( ids.c, 'saved-in-editor' );
		wpEval( `
			$id = wp_insert_post( array(
				'post_type'   => 'wp_template',
				'post_status' => 'publish',
				'post_name'   => 'saved-in-editor',
				'post_title'  => 'Saved in editor',
			) );
			wp_set_object_terms( $id, '${ BLOCK }', 'wp_theme' );
		` );

		const classic = JSON.parse( wp( 'axle-page-template-usage', 'check-theme', CLASSIC, '--format=json' ) );
		const slugs = ( rows ) => rows.map( ( row ) => row.slug ).sort();
		// Wide is a template of the classic theme. The other two are not.
		expect( slugs( classic ) ).toEqual( [ 'saved-in-editor', GONE ].sort() );

		const block = JSON.parse( wp( 'axle-page-template-usage', 'check-theme', BLOCK, '--format=json' ) );
		// The block theme has the one saved for it in the Site Editor, and neither a PHP file nor the retired one.
		expect( slugs( block ) ).toEqual( [ 'page-templates/wide.php', GONE ].sort() );

		wpEval( `foreach ( get_posts( array( 'post_type' => 'wp_template', 'name' => 'saved-in-editor', 'post_status' => 'any' ) ) as $post ) { wp_delete_post( $post->ID, true ); }` );
		[ ids.a, ids.b, ids.c ].forEach( ( id ) => setMeta( id, 'default' ) );
	} );

	test( 'abilities: an agent can check a theme and read the history', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		setMeta( ids.a, NO_TITLE );
		const path = ( name ) => `/wp-abilities/v1/abilities/axle-page-template-usage/${ name }/run`;

		const check = await rest( 'GET', `${ path( 'check-theme' ) }?input[theme]=${ BLOCK }` );
		expect( check.status ).toBe( 200 );
		expect( check.body ).toMatchObject( { theme: BLOCK, name: 'APTU Block' } );
		expect( check.body.templates.find( ( row ) => row.slug === NO_TITLE ) ).toMatchObject( { count: 1, already_missing: false } );

		const active = await rest( 'GET', `${ path( 'check-theme' ) }?input[theme]=${ original }` );
		expect( active.body.code ).toBe( 'axle_page_template_usage_active_theme' );

		const log = await rest( 'GET', `${ path( 'get-history' ) }?input[post_id]=${ ids.a }&input[limit]=5` );
		expect( log.status ).toBe( 200 );
		expect( log.body.entries[ 0 ] ).toMatchObject( { post_id: ids.a, to: NO_TITLE, undoable: true } );

		// A move from an ability is recorded as one.
		setMeta( ids.b, GONE );
		const moved = await rest( 'POST', path( 'reassign' ), { input: { from: GONE, to: NO_TITLE } } );
		expect( moved.body ).toMatchObject( { moved: 1 } );
		const abilityLog = await rest( 'GET', `${ path( 'get-history' ) }?input[batch]=${ moved.body.batch }` );
		expect( abilityLog.body.entries ).toHaveLength( 1 );
		expect( abilityLog.body.entries[ 0 ] ).toMatchObject( { source: 'ability', post_id: ids.b } );
		[ ids.a, ids.b ].forEach( ( id ) => setMeta( id, 'default' ) );
	} );

	test( 'the history stays within its cap, and privacy export, erase and policy text cover the user ID', async ( { page } ) => {
		const result = JSON.parse(
			wpEval( `
				add_filter( 'axle_page_template_usage_history_cap', function () { return 100; } );
				$history = AryanX\\Axle\\PageTemplateUsage\\Plugin::instance()->history();
				$id = ${ ids.plain };
				wp_set_current_user( 1 );
				for ( $i = 0; $i < 400; $i++ ) {
					update_post_meta( $id, '_wp_page_template', 0 === $i % 2 ? 'cap-a-' . $i : 'cap-b-' . $i );
				}
				$history->flush();
				global $wpdb;
				$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AryanX\\Axle\\PageTemplateUsage\\History::table() );
				$newest = $wpdb->get_var( 'SELECT new_template FROM ' . AryanX\\Axle\\PageTemplateUsage\\History::table() . ' ORDER BY id DESC LIMIT 1' );
				echo wp_json_encode( array( 'count' => $count, 'newest' => $newest ) );
			` )
		);
		// Trimmed in steps of 50, so a little over the cap is fine.
		expect( result.count ).toBeGreaterThanOrEqual( 100 );
		expect( result.count ).toBeLessThan( 150 );
		expect( result.newest ).toBe( 'cap-b-399' );
		setMeta( ids.plain, 'default' );

		// Entries by the admin user exist from the earlier tests.
		const exported = JSON.parse(
			wpEval( `
				$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
				$out = call_user_func( $exporters['axle-page-template-usage']['callback'], 'admin@example.com', 1 );
				echo wp_json_encode( array( 'items' => count( $out['data'] ), 'first' => $out['data'][0]['data'][0]['name'] ?? '' ) );
			` )
		);
		expect( exported.items ).toBeGreaterThan( 0 );
		expect( exported.first ).toBe( 'Post ID' );

		const erased = JSON.parse(
			wpEval( `
				$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
				$out = call_user_func( $erasers['axle-page-template-usage']['callback'], 'admin@example.com', 1 );
				global $wpdb;
				echo wp_json_encode( array( 'removed' => $out['items_removed'], 'left' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AryanX\\Axle\\PageTemplateUsage\\History::table() . ' WHERE user_id = 1' ) ) );
			` )
		);
		expect( erased ).toEqual( { removed: true, left: 0 } );

		await page.goto( '/wp-admin/options-privacy.php?tab=policyguide' );
		const section = page.locator( '.privacy-settings-accordion-panel', { hasText: 'Page template history' } );
		await expect( section ).toHaveCount( 1 );
		await expect( section ).toContainText( 'the ID of the user who made the change' );
	} );

	test( 'uninstall.php drops the table and the saved plans', () => {
		wp( 'option', 'update', 'axle_page_template_usage_theme_maps', '{"x":{"map":{}}}', '--format=json' );
		// One process, so the plugin does not create the table again between the steps.
		const after = JSON.parse(
			wpEval( `
				define( 'WP_UNINSTALL_PLUGIN', 'axle-page-template-usage/axle-page-template-usage.php' );
				include WP_PLUGIN_DIR . '/axle-page-template-usage/uninstall.php';
				global $wpdb;
				echo wp_json_encode( array(
					'table'  => (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}axle_ptu_history'" ),
					'maps'   => get_option( 'axle_page_template_usage_theme_maps' ),
					'result' => get_option( 'axle_page_template_usage_switch_result' ),
					'cron'   => (bool) wp_next_scheduled( 'axle_page_template_usage_purge' ),
				) );
			` )
		);
		expect( after ).toEqual( { table: false, maps: false, result: false, cron: false } );
		// The table comes back on the next request, like after an update without activation.
		wp( 'option', 'get', 'blogname' );
		expect( wpEval( "global \$wpdb; echo \$wpdb->get_var( \"SHOW TABLES LIKE '{\$wpdb->prefix}axle_ptu_history'\" ) ? 'table' : 'none';" ) ).toBe( 'table' );
	} );
} );
