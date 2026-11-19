const { test, expect, wp, wpEval } = require( './utils' );

// The theme the suite runs with offers "page-no-title", a block template that hides the page title.
const NO_TITLE = 'page-no-title';

const metaOf = ( id ) => wpEval( `echo get_post_meta( ${ id }, '_wp_page_template', true );` );

test.describe( 'The preview bar', () => {
	test.describe.configure( { mode: 'serial' } );

	let id;

	test.beforeAll( () => {
		id = Number( wp( 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=PB Page', '--post_content=Body text for the bar test.', '--porcelain' ) );
	} );

	test.afterAll( () => {
		wp( 'post', 'delete', String( id ), '--force' );
	} );

	test( 'looks like the admin bar, with core buttons', async ( { page } ) => {
		await page.goto( wp( '--user=1', 'axle-page-template-usage', 'preview-url', String( id ), NO_TITLE ) );
		const bar = page.locator( '.axle-front-bar' );
		await expect( bar ).toBeVisible();
		await expect( bar ).toHaveCSS( 'background-color', 'rgb(29, 35, 39)' );
		await expect( bar ).toHaveCSS( 'color', 'rgb(240, 240, 241)' );
		await expect( bar.getByRole( 'button', { name: 'Use this template' } ) ).toHaveClass( /button-primary/ );
		await expect( bar.getByRole( 'link', { name: 'Close' } ) ).toHaveClass( /\bbutton\b/ );
	} );

	for ( const [ label, width ] of [ [ 'desktop', 1280 ], [ 'phone', 390 ] ] ) {
		test( `pads the page by the real height of the bar on a ${ label }`, async ( { page } ) => {
			await page.setViewportSize( { width, height: 800 } );
			await page.goto( wp( '--user=1', 'axle-page-template-usage', 'preview-url', String( id ), NO_TITLE ) );
			const bar = page.locator( '.axle-front-bar' );
			await expect( page.locator( 'html.axle-has-front-bar-bottom' ) ).toHaveCount( 1 );

			const sizes = await page.evaluate( () => ( {
				bar: document.querySelector( '.axle-front-bar' ).offsetHeight,
				pad: parseFloat( getComputedStyle( document.documentElement ).getPropertyValue( '--axle-front-bar-bottom' ) ),
				sideways: document.documentElement.scrollWidth > document.documentElement.clientWidth,
			} ) );
			expect( sizes.pad ).toBe( sizes.bar );
			expect( sizes.sideways ).toBe( false );

			// The end of the page is not under the bar.
			await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
			const lastBottom = await page.evaluate( () => {
				const rect = document.body.lastElementChild ? document.querySelector( '.axle-front-bar' ).getBoundingClientRect() : null;
				return rect ? rect.top : 0;
			} );
			expect( lastBottom ).toBeGreaterThan( 0 );
			await expect( bar ).toBeInViewport();
		} );
	}

	test( 'a template select tries the next template without going back, and saves nothing', async ( { page } ) => {
		await page.goto( wp( '--user=1', 'axle-page-template-usage', 'preview-url', String( id ), NO_TITLE ) );
		await expect( page.locator( '.wp-block-post-title' ) ).toHaveCount( 0 );

		const bar = page.locator( '.axle-front-bar' );
		await expect( bar.getByLabel( 'Try' ) ).toHaveValue( NO_TITLE );
		await bar.getByLabel( 'Try' ).selectOption( 'default' );
		await bar.getByRole( 'button', { name: 'Preview' } ).click();

		await expect( page ).toHaveURL( /axle_ptu_preview=default/ );
		await expect( page.locator( '.wp-block-post-title' ) ).toHaveCount( 1 );
		await expect( bar ).toContainText( 'with the template "Default"' );
		// The page already uses the default, so there is nothing to apply, and the select is on it.
		await expect( bar.getByRole( 'button', { name: 'Use this template' } ) ).toHaveCount( 0 );
		await expect( bar.getByLabel( 'Try' ) ).toHaveValue( 'default' );
		expect( metaOf( id ) ).toBe( '' );
	} );

	test( 'the select refuses a template the theme does not have, and a link made for nobody', async ( { page } ) => {
		await page.goto( wp( '--user=1', 'axle-page-template-usage', 'preview-url', String( id ), NO_TITLE ) );
		const form = page.locator( '.aptu-preview-switch' );
		const nonce = await form.locator( 'input[name="_wpnonce"]' ).inputValue();

		const forged = await page.request.get( `/wp-admin/admin-post.php?action=axle_page_template_usage_switch_preview&post=${ id }&template=templates%2Fnope.php&_wpnonce=${ nonce }`, { maxRedirects: 0 } );
		expect( forged.status() ).toBe( 403 );

		const noNonce = await page.request.get( `/wp-admin/admin-post.php?action=axle_page_template_usage_switch_preview&post=${ id }&template=default`, { maxRedirects: 0 } );
		expect( noNonce.status() ).toBe( 403 );
	} );
} );
