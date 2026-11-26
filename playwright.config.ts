import { env } from 'node:process';
import { defineConfig, devices } from '@playwright/test';

const inCi = Boolean(env.CI);

/**
 * End-to-end tests (08). Locally they run against the app in Docker, at
 * http://localhost:8001. In CI, Playwright starts `php artisan serve`,
 * which with --no-reload passes the step's environment on, so it uses the
 * testing database. Each test starts its own sandbox, so no database needs
 * resetting.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: inCi,
    workers: inCi ? 2 : undefined,
    reporter: inCi ? [['github'], ['html', { open: 'never' }]] : 'list',
    use: {
        // php artisan serve listens on IPv4 only, and localhost can resolve
        // to IPv6 first.
        baseURL:
            env.PLAYWRIGHT_BASE_URL ??
            (inCi ? 'http://127.0.0.1:8001' : 'http://localhost:8001'),
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: 'php artisan serve --no-reload --host=127.0.0.1 --port=8001',
        url: 'http://127.0.0.1:8001/up',
        reuseExistingServer: !inCi,
    },
});
