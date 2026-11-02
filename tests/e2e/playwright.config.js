/**
 * Browser tests for the admin screen, the block editor and the frontend (Chromium).
 *
 *   npm run test:e2e                      # local Docker site at http://localhost:8080
 *   WP_BASE_URL=… WP_CLI="wp --path=…" npm run test:e2e
 *
 * Global setup switches the site to Recorded mode with a temporary administrator and restores
 * everything afterwards (see global-setup.js / global-teardown.js).
 */
const { defineConfig, devices } = require( '@playwright/test' );
const { STORAGE_STATE } = require( './config' );

process.env.WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8080';
process.env.STORAGE_STATE_PATH = STORAGE_STATE;

module.exports = defineConfig( {
	testDir: './specs',
	outputDir: '../../test-results',
	globalSetup: require.resolve( './global-setup.js' ),
	globalTeardown: require.resolve( './global-teardown.js' ),
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 90_000,
	expect: { timeout: 15_000 },
	reporter: [
		[ 'list' ],
		[ 'html', { open: 'never', outputFolder: '../../playwright-report' } ],
	],
	use: {
		baseURL: process.env.WP_BASE_URL,
		storageState: STORAGE_STATE,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );
