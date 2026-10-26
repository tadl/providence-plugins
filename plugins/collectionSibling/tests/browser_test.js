'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

const fixture = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'plugin_test.php'), '--fixtures'], {encoding: 'utf8'});
assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
const fixtures = JSON.parse(fixture.stdout);
const reference = process.env.PROVIDENCE_REFERENCE_ROOT || path.resolve(__dirname, '../../../../providence');
const jquery = fs.readFileSync(path.join(reference, 'assets/jquery/jquery-3.7.1.js'));
const prefix = 'P17ObjectEditorForm';
const row = '#' + prefix + 'HierarchyBrowseAdd';
const mode = '#' + prefix + 'addType';
const type = '#' + prefix + 'typeList';
const destination = '/index.php/editor/objects/ObjectEditor/Edit/object_id/0';

const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/jquery.js') {
        response.setHeader('Content-Type', 'text/javascript'); response.end(jquery); return;
    }
    const name = url.pathname.slice(1);
    if (Object.hasOwn(fixtures, name)) {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end('<!doctype html><html lang="en"><meta charset="utf-8"><title>Synthetic collection sibling test</title>'
            + '<script src="/jquery.js"></script><main><h1>Synthetic object</h1><form>' + fixtures[name] + '</form></main></html>');
        return;
    }
    if (url.pathname.startsWith(destination)) {
        response.end('Synthetic new-object form destination'); return;
    }
    response.statusCode = 404; response.end('Synthetic resource not found');
});

let browser;
(async function () {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;
    browser = await chromium.launch({headless: true});
    const context = await browser.newContext();
    // Never allow a fixture to contact external systems.
    await context.route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.abort());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => { errors.push('Unexpected dialog: ' + dialog.message()); return dialog.dismiss(); });
    async function open(name='normal') {
        await page.goto(origin + '/' + name);
        await page.evaluate(() => new Promise(resolve => jQuery(resolve)));
    }
    async function navigate(action, suffix, keyboard=false) {
        await page.selectOption(mode, action);
        const link = page.locator(row + ' a');
        await Promise.all([
            page.waitForURL(origin + destination + suffix),
            keyboard ? link.press('Enter') : link.locator('span').click()
        ]);
    }

    await open();
    assert.deepEqual(await page.locator(mode + ' option').evaluateAll(options => options.map(o => o.value)), ['under', 'above', 'collection_sibling']);
    assert.equal(await page.locator(mode).inputValue(), 'under', 'Adding an option must not change the native selection');
    assert.equal(await page.locator(row + ' a').getAttribute('aria-label'), 'Create a new object');
    await navigate('collection_sibling', '/type_id/11/collection_id/501');
    assert(!page.url().includes('parent_id') && !page.url().includes('after_id'), 'Collection siblings must not alter object ancestry or global rank');

    await open();
    await page.selectOption(type, '12');
    await navigate('collection_sibling', '/type_id/12/collection_id/501', true);
    console.log('PASS: sibling destination, selected type, icon click and keyboard activation');

    await open(); await navigate('under', '/type_id/11/parent_id/101');
    await open(); await navigate('above', '/type_id/11/above_id/101');
    await open('nativeParent');
    assert.equal(await page.locator(mode + ' option[value="collection_sibling"]').count(), 0);
    await navigate('next_to', '/type_id/11/parent_id/91/after_id/101');
    console.log('PASS: native child, parent and object-sibling navigation stays intact');

    await open('twoPlacements');
    assert.equal(await page.locator('option[value="collection_sibling"]').count(), 2, 'Each hierarchy placement gets exactly one option');
    await page.selectOption('#P18ObjectEditorFormaddType', 'collection_sibling');
    await page.selectOption('#P18ObjectEditorFormtypeList', '12');
    await Promise.all([
        page.waitForURL(origin + destination + '/type_id/12/collection_id/501'),
        page.locator('#P18ObjectEditorFormHierarchyBrowseAdd a').click()
    ]);

    await open();
    const script = await page.locator('script[data-collection-sibling]').textContent();
    await page.addScriptTag({content: script});
    await page.evaluate(() => new Promise(resolve => jQuery(resolve)));
    assert.equal(await page.locator(mode + ' option[value="collection_sibling"]').count(), 1, 'Repeated initialization must not duplicate controls');
    await page.selectOption(mode, 'collection_sibling');
    await page.locator(type).evaluate(node => { node.add(new Option('Invalid type', '../invalid')); node.value = '../invalid'; });
    await page.locator(row + ' a').click();
    assert.equal(page.url(), origin + '/normal', 'Invalid type cannot navigate or reach the native invalid-action alert');

    await open('readonly');
    assert.equal(await page.locator(mode).count(), 0, 'Read-only placement remains without Add controls');
    await open();
    await page.locator(row).evaluate(node => { delete node.dataset.collectionSibling; node.querySelector('select[name="add_type"]').disabled = true; });
    // Remove the already added option before replaying initialization in a disabled control.
    await page.locator(mode + ' option[value="collection_sibling"]').evaluate(node => node.remove());
    await page.addScriptTag({content: script});
    await page.evaluate(() => new Promise(resolve => jQuery(resolve)));
    assert.equal(await page.locator(mode + ' option[value="collection_sibling"]').count(), 0);
    console.log('PASS: independent placements, idempotence, invalid type and read-only/disabled control guards');

    // Native unsaved-change listeners must still get a normal beforeunload event.
    await open();
    page.removeAllListeners('dialog');
    let warning = false;
    page.on('dialog', async dialog => {
        assert.equal(dialog.type(), 'beforeunload'); warning = true; await dialog.accept();
    });
    await page.evaluate(() => window.addEventListener('beforeunload', event => {
        event.preventDefault(); event.returnValue = '';
    }));
    await navigate('collection_sibling', '/type_id/11/collection_id/501');
    assert(warning, 'Sibling navigation must preserve unsaved-change warnings');
    assert.deepEqual(errors, [], 'No script errors or native invalid-action alerts');
    console.log('PASS: existing unsaved-change warning and clean browser execution');
    await context.close();
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
    try { if (browser) { await browser.close(); } }
    finally { await new Promise(resolve => server.close(resolve)); }
});
