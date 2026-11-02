import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { sectionForm } from './helpers';

/**
 * MailKit 1.3 — the Resend API key and sender rows follow the "Send with Resend" switch (InstanceSettings
 * `visible_when`, UiKit `visible-when` peer). Switching off and saving keeps the stored sender.
 */
const row = (page: Page, name: string) => sectionForm(page).locator(`[data-nowo-ui-visible-when-field]:has([name^="settings_section[${name}]"])`);

/** axe on the section form, in the state being tested. */
async function expectNoAxeViolations(page: Page): Promise<void> {
  const axe = await new AxeBuilder({ page }).include('form[name="settings_section"]').analyze();
  expect(axe.violations).toEqual([]);
}

test.describe('Resend provider-dependent fields', () => {
  test('key and sender rows follow the switch and keep their values', async ({ page }) => {
    await page.goto('/admin/settings/sections/resend');
    const form = sectionForm(page);
    const enabled = form.getByLabel('Send with Resend');
    await expect(enabled).not.toBeChecked();
    await expect(row(page, 'api_key')).toBeHidden();
    await expect(row(page, 'from')).toBeHidden();
    await expectNoAxeViolations(page);

    await enabled.check();
    await expect(row(page, 'from')).toBeVisible();
    await form.getByLabel('Resend sender address').fill('resend@example.com');
    await expectNoAxeViolations(page);
    // Store the sender while switched on, then switch off and save: the sender survives.
    await enabled.uncheck();
    await expect(row(page, 'from')).toBeHidden();
    await enabled.check();
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();

    await sectionForm(page).getByLabel('Send with Resend').uncheck();
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await expect(row(page, 'from')).toBeHidden();
    await sectionForm(page).getByLabel('Send with Resend').check();
    await expect(sectionForm(page).getByLabel('Resend sender address')).toHaveValue('resend@example.com');
  });
});
