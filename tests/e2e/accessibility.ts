import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Checks the page as it is now with axe. A failure lists each rule broken
 * and the elements that break it.
 */
export async function expectNoAccessibilityViolations(
    page: Page,
): Promise<void> {
    const results = await new AxeBuilder({ page }).analyze();

    expect(
        results.violations.map((violation) => ({
            rule: violation.id,
            help: violation.help,
            elements: violation.nodes.map((node) => node.target.join(' ')),
        })),
    ).toEqual([]);
}
