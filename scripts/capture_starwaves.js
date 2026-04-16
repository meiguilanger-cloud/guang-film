const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 2200 } });
  await page.goto('http://127.0.0.1:8765/starwaves/', { waitUntil: 'networkidle', timeout: 120000 });
  await page.screenshot({ path: 'starwaves-home.png', fullPage: true });
  await browser.close();
})();
