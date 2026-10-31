import { test, expect } from '@playwright/test';
import { sectionForm } from './helpers';

/** REQ-DEMO-013 — happy path of the runtime mailer (settings section, test e-mail, send, log). Data: `make seed`. */
test.describe('Mail Kit demo', () => {
  test('home page shows the runtime transport from the settings', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('demo-status')).toContainText('smtp://mailpit:1025');
    await expect(page.getByTestId('demo-ready')).toHaveText('yes');
  });

  test('the mailer section never renders the stored DSN and refuses a private host', async ({ page }) => {
    await page.goto('/admin/settings/sections/mailer');
    await expect(sectionForm(page).locator('input[type="password"]')).toHaveValue('');
    await sectionForm(page).locator('input[type="password"]').fill('smtp://10.0.0.5:25');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).toHaveURL(/sections\/mailer/);
    await page.goto('/');
    await expect(page.getByTestId('demo-status')).toContainText('smtp://mailpit:1025');
  });

  test('send a test e-mail reports the transport answer', async ({ page }) => {
    await page.goto('/admin/settings/sections/mailer');
    await page.getByTestId('nowo_mail_kit_test').click();
    await expect(page.getByText('Test e-mail sent to ops@example.com.')).toBeVisible();
  });

  test('a message sent through MailerInterface is logged', async ({ page }) => {
    await page.goto('/');
    await page.getByLabel('Subject').fill('E2E runtime message');
    await page.getByTestId('demo-send').click();
    await expect(page.getByText('Message sent')).toBeVisible();

    await page.goto('/admin/mail/log?q=E2E+runtime');
    await expect(page.getByTestId('mail-kit-log')).toContainText('E2E runtime message');
    await page.getByRole('link', { name: 'E2E runtime message' }).click();
    await expect(page.getByTestId('mail-kit-log-show')).toContainText('noreply@example.com');
  });

  test('the log filters by status', async ({ page }) => {
    await page.goto('/admin/mail/log?status=failed&period=all');
    await expect(page.getByTestId('mail-kit-log')).toContainText('Weekly incident digest');
    await expect(page.getByTestId('mail-kit-log')).not.toContainText('Welcome to Acme Ops');
  });

  test('the log sorts on the server and keeps the sort across filters', async ({ page }) => {
    await page.goto('/admin/mail/log?period=all');
    await page.getByTestId('mail-kit-log').getByRole('link', { name: /Subject/ }).first().click();
    await expect(page).toHaveURL(/sort=subject&dir=asc/);
    await expect(page.locator('th[aria-sort="ascending"]')).toContainText('Subject');
    await page.getByRole('button', { name: 'Filter' }).click();
    await expect(page).toHaveURL(/sort=subject/);
  });

  test('the log exports the filtered rows as a formula-safe CSV', async ({ page, request }) => {
    await page.goto('/admin/mail/log?status=failed&period=all');
    const href = await page.getByTestId('mail-kit-export').getAttribute('href');
    expect(href).toContain('status=failed');
    const response = await request.get(href ?? '');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/csv');
    expect(response.headers()['cache-control']).toContain('no-store');
    const csv = (await response.text()).replace(/^\uFEFF/, '');
    expect(csv.split('\n')[0]).toBe('Date,Status,Tag,From,To,Cc,Bcc,Subject,Error,Reference');
    expect(csv).toContain('Weekly incident digest');
    expect(csv).not.toContain('Welcome to Acme Ops');
  });

  test('a forged CSRF token is refused', async ({ request }) => {
    const response = await request.post('/admin/mail/actions/test', { form: { 'csrf_only[_token]': 'forged' } });
    expect(response.status()).toBe(403);
  });
});
