import { expect, test } from '@playwright/test';
import { expectNoAccessibilityViolations } from './accessibility';

for (const colorScheme of ['light', 'dark'] as const) {
    test.describe(`in ${colorScheme} mode`, () => {
        test.use({ colorScheme });

        test('a visitor starts a playground and sees its diary', async ({
            page,
        }) => {
            await page.goto('/playground');

            await expect(page).toHaveURL(/\/playground\/start$/);
            await expect(page.locator('html')).toHaveClass(
                colorScheme === 'dark' ? /\bdark\b/ : /^(?!.*\bdark\b)/,
            );
            await expect(
                page.getByRole('heading', {
                    level: 1,
                    name: 'Scheduling playground',
                }),
            ).toBeVisible();
            await expectNoAccessibilityViolations(page);

            await page.getByRole('button', { name: 'Start' }).click();

            await expect(page).toHaveURL(/\/playground$/);
            await expect(
                page.getByRole('heading', {
                    level: 1,
                    name: 'Alex Morgan Physio & Sports Massage',
                }),
            ).toBeVisible();
            await expect(
                page
                    .getByRole('region', { name: 'Services' })
                    .getByRole('listitem'),
            ).toHaveText([
                /Physio follow-up\s*30 minutes/,
                /Physio assessment\s*60 minutes/,
                /Sports massage, 90 minutes\s*90 minutes/,
            ]);
            await expectNoAccessibilityViolations(page);
        });
    });
}
