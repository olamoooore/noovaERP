from playwright.sync_api import sync_playwright
import os

SCREENSHOT_DIR = 'evidence-screenshots-FC'
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    page = context.new_page()

    page.goto('https://example.com/login')
    page.fill('input[type="email"]', 'admin@demo.com')
    page.fill('input[type="password"]', 'Admin@123')
    page.click('button[type="submit"]')
    page.wait_for_timeout(5000)
    print('Logged in, URL:', page.url)

    page.goto('https://example.com/financial-close/my-tasks')
    page.wait_for_timeout(5000)
    page.screenshot(path=f'{SCREENSHOT_DIR}/FC-005-my-tasks.png', full_page=False)

    unpaid_task = page.get_by_text('unpaid vendor bills').first
    if unpaid_task.is_visible():
        unpaid_task.click()
        page.wait_for_timeout(3000)
        page.screenshot(path=f'{SCREENSHOT_DIR}/FC-005-unpaid-bills-detail.png', full_page=False)
        print('Task detail loaded')

        sources_tab = page.get_by_role('tab', name='Source Documents')
        if sources_tab.is_visible():
            sources_tab.click()
            page.wait_for_timeout(1000)
        page.screenshot(path=f'{SCREENSHOT_DIR}/FC-005-source-docs.png', full_page=False)

        eye_btns = page.locator('button').filter(has=page.locator('[class*="eye"]')).all()
        if not eye_btns:
            eye_btns = page.locator('.rounded-xl.border button').all()
        print(f'Found {len(eye_btns)} buttons')
        if eye_btns:
            eye_btns[0].click()
            page.wait_for_timeout(3000)
            page.screenshot(path=f'{SCREENSHOT_DIR}/FC-005-bill-navigated.png', full_page=False)
            print(f'Navigated to: {page.url}')
    else:
        print('No unpaid bills task found')
        page.screenshot(path=f'{SCREENSHOT_DIR}/FC-005-debug.png', full_page=True)

    browser.close()
    print('Done')
