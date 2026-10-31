import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { sectionForm, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Mail Kit screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1000 } });

  test('mailer-settings — mailer section after a successful test e-mail', async ({ page }) => {
    await page.goto('/admin/settings/sections/mailer');
    await page.getByTestId('nowo_mail_kit_test').click();
    await expect(page.getByText('Test e-mail sent to ops@example.com.')).toBeVisible();
    await expect(sectionForm(page)).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'mailer-settings.png'), fullPage: true });
  });

  test('sent-mail-log — statuses, recipients, tags and transports', async ({ page }) => {
    await page.goto('/admin/mail/log?period=all');
    await expect(page.getByTestId('mail-kit-log')).toContainText('Weekly incident digest');
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'sent-mail-log.png'), fullPage: true });
  });
});
