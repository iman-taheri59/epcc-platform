#!/usr/bin/env python3
"""Offline Chromium checks of actual synthetic Laravel-rendered time forms.
Run the focused PHP fixture with EPCC_TIME_UI_EVIDENCE=1 first. No server or DB writes.
"""
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
EVIDENCE = ROOT / 'docs/evidence/2026-10-20-inspection-notification-time-ui'
checks = 0
cases = []

def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)

def equal(actual, expected, message):
    check(actual == expected, f'{message}: {actual!r} != {expected!r}')

def widget(page, part):
    return page.locator(f'[data-notification-time][data-boundary="{part}"]')

def exact(page, part):
    return widget(page, part).locator('[data-time-exact]')

def fill(page, part, date, clock, offset):
    w = widget(page, part)
    w.locator('[data-time-date]').fill(date)
    w.locator('[data-time-clock]').fill(clock)
    w.locator('[data-time-offset]').fill(offset)

def load(page, name):
    page.set_content((EVIDENCE / name).read_text(), wait_until='load')

def form_data(page, form):
    return page.locator(form).evaluate('(form) => [...new FormData(form).entries()]')

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    all_errors, requests = [], []
    for lang in ['en', 'fa']:
        for tz in ['Asia/Tehran', 'America/New_York']:
            context = browser.new_context(timezone_id=tz, viewport={'width': 1100, 'height': 1000})
            page = context.new_page()
            page.on('pageerror', lambda e: all_errors.append(str(e)))
            page.route('**/*', lambda route: (requests.append(route.request.url), route.abort()))
            load(page, f'register-{lang}.html')
            equal(page.locator('html').get_attribute('dir'), 'rtl' if lang == 'fa' else 'ltr', 'language direction')
            for part in ['start', 'end']:
                w = widget(page, part)
                equal(exact(page, part).input_value(), '', 'fresh exact input remains blank')
                equal(w.locator('[data-time-offset]').input_value(), '', 'offset never inferred/defaulted')
                check(w.locator('[data-time-helper]').is_visible(), 'helper is enhanced')
                check(exact(page, part).evaluate('(e)=>e.readOnly'), 'helper mode exact value is readonly')
            form = '#inspection-notification-draft'
            page.locator(form+' [name="notification[work_reference]"]').fill('Synthetic UI test activity')
            page.locator(form+' [name="notification[location]"]').fill('Synthetic UI location')
            select = page.locator(form+' [name="notification[recipient_company_ids][]"]')
            select.select_option(select.locator('option').first.get_attribute('value'))
            fill(page, 'start', '2026-10-25', '09:30:45', '+03:30')
            fill(page, 'end', '2026-10-26', '00:05:15', '+00:00')
            equal(exact(page,'start').input_value(), '2026-10-25T09:30:45+03:30', 'start serialization with seconds')
            equal(exact(page,'end').input_value(), '2026-10-26T00:05:15+00:00', 'end serialization with independent offset')
            check(page.locator(form).evaluate('(f)=>f.checkValidity()'), 'complete form native validity')
            data = form_data(page, form)
            values = dict(data)
            equal(values['notification[activity_start_at]'], '2026-10-25T09:30:45+03:30', 'submitted canonical start')
            equal(values['notification[activity_end_at]'], '2026-10-26T00:05:15+00:00', 'submitted canonical end')
            check(all(not any(x in key for x in ['time-date','time-clock','time-offset','time-manual']) for key,_ in data), 'UI-only controls are never payload fields')
            check('notification[scheduled_at]' not in values, 'no legacy appointment payload')
            cases.append(f'{lang}/{tz}: fresh/helpers/serialization/payload')

            widget(page,'end').locator('[data-time-offset]').fill('+03:')
            equal(exact(page,'end').input_value(), '', 'partial offset cannot retain stale end')
            check(not page.locator(form).evaluate('(f)=>f.checkValidity()'), 'partial offset blocks native submission')
            widget(page,'end').locator('[data-time-offset]').fill('+00:00')
            widget(page,'start').locator('[data-time-clock]').fill('')
            equal(exact(page,'start').input_value(), '', 'cleared time cannot retain stale start')
            check(not page.locator(form).evaluate('(f)=>f.checkValidity()'), 'cleared helper blocks native submission')
            widget(page,'start').locator('[data-time-clock]').fill('09:30')
            equal(exact(page,'start').input_value(), '2026-10-25T09:30:00+03:30', 'missing displayed seconds explicitly serialize to zero')
            cases.append(f'{lang}/{tz}: partial/clear/seconds')

            manual = widget(page,'start').locator('[data-time-manual]')
            manual.check()
            check(not exact(page,'start').evaluate('(e)=>e.readOnly'), 'direct input editable')
            check(widget(page,'start').locator('[data-time-date]').is_disabled(), 'unused helpers disabled in direct mode')
            exact(page,'start').fill('2026-10-25T07:12:34+00:00')
            check(exact(page,'start').evaluate('(e)=>e.checkValidity()'), 'manual valid input')
            check('UTC+00:00' in widget(page,'start').locator('[data-time-preview]').inner_text(), 'human readable preview explicitly identifies offset')
            manual.uncheck()
            equal(widget(page,'start').locator('[data-time-clock]').input_value(), '07:12:34', 'manual seconds round trip to helper')
            equal(widget(page,'start').locator('[data-time-offset]').input_value(), '+00:00', 'manual offset round trip without timezone conversion')
            manual.check()
            exact(page,'start').fill('2026-02-30T09:30:00+03:30')
            check(not exact(page,'start').evaluate('(e)=>e.checkValidity()'), 'manual impossible date rejected by helper validation')
            equal(exact(page,'start').input_value(), '2026-02-30T09:30:00+03:30', 'bad direct input is not normalized/discarded')
            exact(page,'start').fill('2026-10-25T09:30:00-00:00')
            check(not exact(page,'start').evaluate('(e)=>e.checkValidity()'), 'unknown negative-zero offset not silently converted to UTC')
            cases.append(f'{lang}/{tz}: direct entry/roundtrip/invalid')

            load(page, f'edit-{lang}.html')
            equal(exact(page,'start').input_value(), '2026-10-25T09:30:00+03:30', 'existing original value not changed on page load')
            equal(widget(page,'start').locator('[data-time-date]').input_value(), '2026-10-25', 'edit helper date from original not host timezone')
            equal(widget(page,'start').locator('[data-time-offset]').input_value(), '+03:30', 'edit helper original offset')
            equal(exact(page,'end').input_value(), '2026-10-25T11:30:00+03:30', 'existing end untouched')
            if lang == 'fa' and tz == 'Asia/Tehran':
                page.set_viewport_size({'width':390,'height':844})
                check(page.evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth'), 'mobile page has no horizontal overflow')
                widget(page,'start').screenshot(path=str(EVIDENCE/'time-controls-fa-mobile.png'))
            cases.append(f'{lang}/{tz}: original edit prefill')
            context.close()

    context = browser.new_context(java_script_enabled=False)
    page = context.new_page()
    load(page,'edit-en.html')
    for part in ['start','end']:
        check(not widget(page,part).locator('[data-time-helper]').is_visible(), 'no-JS helper hidden')
        check(not exact(page,part).evaluate('(e)=>e.readOnly'), 'no-JS exact input remains editable')
    exact(page,'start').fill('2026-10-25T09:00:00+03:30')
    data = dict(form_data(page,'#inspection-notification-edit-form'))
    equal(data['notification[activity_start_at]'],'2026-10-25T09:00:00+03:30','no-JS exact submission')
    check(page.locator('#inspection-notification-edit-form').evaluate('(f)=>f.checkValidity()'),'no-JS valid form does not depend on hidden helper required fields')
    cases.append('no-JS: editable canonical fallback/payload')
    context.close()

    context = browser.new_context(timezone_id='Pacific/Auckland')
    page = context.new_page()
    page.on('pageerror', lambda e: all_errors.append(str(e)))
    load(page,'invalid-restored-fa.html')
    check(widget(page,'start').locator('[data-time-manual]').is_checked(), 'bad restored exact input opens direct mode')
    equal(exact(page,'start').input_value(),'2026-02-30T09:30:00+03:30','bad restored text preserved verbatim')
    equal(widget(page,'start').locator('[data-time-date]').input_value(),'','bad restored date is not converted into a valid date')
    check(not exact(page,'start').evaluate('(e)=>e.checkValidity()'),'bad restored date remains invalid')
    cases.append('restoration: malformed value preserved for correction')
    context.close()
    browser.close()
    equal(all_errors, [], 'no browser JavaScript errors')
    equal(requests, [], 'no external network requests')

summary = {'cases':len(cases),'assertions':checks,'status':'PASS','browser':'real Chromium via Playwright','timezones':['Asia/Tehran','America/New_York','Pacific/Auckland'],'scope':cases,'not_end_to_end_http_submission':True,'fixtures':'actual Laravel-rendered synthetic pages; CSRF tokens scrubbed; offline DOM/FormData, no server mutations'}
(EVIDENCE/'browser-summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
print(f'PASS: {len(cases)} browser scenarios / {checks} assertions; no external requests or JS errors.')
