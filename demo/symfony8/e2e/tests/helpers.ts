import { type Page } from '@playwright/test';

/** The section form of the settings scaffold. */
export function sectionForm(page: Page) {
  return page.locator('form[name="settings_section"]');
}

/** README screenshots never show the Symfony Web Debug Toolbar. */
export async function hideWebProfiler(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar, .sf-minitoolbar, [id^="sfwdt"] { display: none !important; }' });
}
