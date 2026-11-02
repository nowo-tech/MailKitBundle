import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { sectionForm } from './helpers';

/**
 * MailKit 1.3 — provider-dependent rows of the `mailer` section (InstanceSettings `visible_when`, UiKit
 * `visible-when` peer): the DSN row for `dsn`, the Resend key and sender rows for `resend`; sender, Reply-To and test
 * recipient always. Values survive a switch; a Resend key is checked before it is saved (MK-L1). The demo answers the
 * key check offline: keys starting with `re_demo_` are valid.
 */
const row = (page: Page, name: string) => sectionForm(page).locator(`[data-nowo-ui-visible-when-field]:has([name^="settings_section[${name}]"])`);

/** axe on the section form, in the state being tested. */
async function expectNoAxeViolations(page: Page): Promise<void> {
  const axe = await new AxeBuilder({ page }).include('form[name="settings_section"]').analyze();
  expect(axe.violations).toEqual([]);
}

async function save(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Save', exact: true }).click();
}

test.describe('Mailer provider-dependent fields', () => {
  test('only the selected provider rows are shown, a rejected Resend key is not saved, values survive a switch', async ({ page }) => {
    await page.goto('/admin/settings/sections/mailer');
    const form = sectionForm(page);
    await expect(form.getByLabel('Provider')).toHaveValue('dsn');
    await expect(row(page, 'dsn')).toBeVisible();
    await expect(row(page, 'resend_api_key')).toBeHidden();
    await expect(row(page, 'resend_from')).toBeHidden();
    await expect(form.getByLabel('Sender address', { exact: true })).toBeVisible();
    await expect(form.getByLabel('Test recipient', { exact: true })).toBeVisible();
    await expectNoAxeViolations(page);

    // Switch to Resend with a key Resend rejects: the save is refused before anything goes live.
    await form.getByLabel('Provider').selectOption('resend');
    await expect(row(page, 'resend_api_key')).toBeVisible();
    await expect(row(page, 'resend_from')).toBeVisible();
    await expect(row(page, 'dsn')).toBeHidden();
    await expectNoAxeViolations(page);
    await row(page, 'resend_api_key').locator('input[type="password"]').fill('re_wrong_key_123');
    await save(page);
    await expect(sectionForm(page)).toContainText('Resend rejected this API key, so nothing was saved: API key is invalid');
    await expect(sectionForm(page).getByLabel('Provider')).toHaveValue('resend');
    await expect(row(page, 'dsn')).toBeHidden();
    await page.goto('/');
    await expect(page.getByTestId('demo-status')).toContainText('smtp://mailpit:1025');

    // A key Resend accepts is saved and goes live.
    await page.goto('/admin/settings/sections/mailer');
    await sectionForm(page).getByLabel('Provider').selectOption('resend');
    await row(page, 'resend_api_key').locator('input[type="password"]').fill('re_demo_e2e_key_123');
    await sectionForm(page).getByLabel('Resend sender address', { exact: true }).fill('mail@example.com');
    await save(page);
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await expect(sectionForm(page).getByLabel('Provider')).toHaveValue('resend');
    await expect(row(page, 'dsn')).toBeHidden();
    await expectNoAxeViolations(page);
    await page.goto('/');
    await expect(page.getByTestId('demo-status')).toContainText('resend+api://');

    // Back to the DSN provider: the stored DSN is used again, the Resend values are kept (hidden).
    await page.goto('/admin/settings/sections/mailer');
    await sectionForm(page).getByLabel('Provider').selectOption('dsn');
    await expect(row(page, 'resend_api_key')).toBeHidden();
    await save(page);
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await page.goto('/');
    await expect(page.getByTestId('demo-status')).toContainText('smtp://mailpit:1025');

    await page.goto('/admin/settings/sections/mailer');
    await sectionForm(page).getByLabel('Provider').selectOption('resend');
    await expect(sectionForm(page).getByLabel('Resend sender address', { exact: true })).toHaveValue('mail@example.com');
    await sectionForm(page).getByLabel('Provider').selectOption('dsn');
  });

  test('"Check the API key" checks the stored key without echoing it', async ({ page }) => {
    await page.goto('/admin/settings/sections/mailer');
    await page.getByTestId('nowo_mail_kit_resend_check').click();
    await expect(page.getByText('The Resend API key is valid. Domains: example.com (verified)')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('re_demo_e2e_key_123');
  });
});

test.describe('Upgrade from MailKit 1.2', () => {
  test('1.2 rows with Resend on: the page pre-selects Resend and saving it keeps Resend live', async ({ page }) => {
    await page.goto('/');
    await page.getByTestId('demo-legacy-resend').click();
    await expect(page.getByText('Settings rewritten as MailKit 1.2 left them')).toBeVisible();
    await expect(page.getByTestId('demo-status')).toContainText('resend+api://');

    await page.goto('/admin/settings/sections/mailer');
    await expect(page.getByTestId('instance-settings-notice')).toContainText('still read from the old resend section');
    await expect(sectionForm(page).getByLabel('Provider')).toHaveValue('resend');
    await expect(row(page, 'resend_api_key')).toBeVisible();
    await expect(row(page, 'dsn')).toBeHidden();
    await expect(sectionForm(page).getByLabel('Resend sender address', { exact: true })).toHaveValue('legacy@example.com');
    await expect(page.locator('body')).not.toContainText('re_demo_legacy_12_key');
    await expectNoAxeViolations(page);

    await save(page);
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await expect(page.getByTestId('instance-settings-notice')).toHaveCount(0);
    await expect(sectionForm(page).getByLabel('Provider')).toHaveValue('resend');
    await page.goto('/');
    await expect(page.getByTestId('demo-status')).toContainText('resend+api://');

    // Back to the demo DSN for the other specs.
    await page.goto('/admin/settings/sections/mailer');
    await sectionForm(page).getByLabel('Provider').selectOption('dsn');
    await save(page);
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await page.goto('/');
    await expect(page.getByTestId('demo-status')).toContainText('smtp://mailpit:1025');
  });
});
