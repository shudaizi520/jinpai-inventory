const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Execute the actual page functions; only the DOM and HTTP boundary are simulated.
const source = fs.readFileSync(path.join(__dirname, '../../php/index.php'), 'utf8');
const state = source.slice(source.indexOf('let lastKnownUpdate ='), source.indexOf('function rowVersionFor'));
const manual = source.slice(source.indexOf('async function loadData()'), source.indexOf('function renderTable()'));
const polling = source.slice(source.indexOf('// === ⚡ 性能版静默刷新'), source.indexOf('setInterval(silentRefresh, 10000)'));

function deferred() {
    let resolve;
    const promise = new Promise(r => { resolve = r; });
    return { promise, resolve };
}

function setup(request, render) {
    const storage = new Map();
    const elements = new Map();
    const context = vm.createContext({
        URLSearchParams, Date,
        document: {
            getElementById(id) {
                if (!elements.has(id)) elements.set(id, { innerHTML: '', classList: { contains: () => true } });
                return elements.get(id);
            },
            querySelectorAll: () => [],
        },
        sessionStorage: { getItem: key => storage.get(key) },
        localStorage: { getItem: key => storage.get(key) },
        apiFetch: async url => {
            const params = new URL(url, 'http://test.local').searchParams;
            const payload = await request(params);
            return { json: async () => payload };
        },
        escapeHTML: value => String(value),
        renderSummaryPanels() {},
        renderTable() { if (render) render(); },
    });
    vm.runInContext(`
        let currentTab = 'US', currentSearchTerm = '', currentDateFilter = null;
        let currentPage = 1, currentData = [{id: 0}], totalFilteredItems = 0, summaryData = null;
        let isFinanceUnlocked = false, tabLastActive = Date.now();
        const ITEMS_PER_PAGE = 50, INVENTORY_API_URL = '/api_inventory.php';
        ${state}
        ${manual}
        ${polling}
    `, context);
    return {
        run: expression => vm.runInContext(expression, context),
        lock: () => storage.set('sys_global_locked', '1'),
    };
}

const revision = value => ({ status: 'success', last_update: String(value) });
const list = id => ({ status: 'success', data: [{id}], total: 1, summary: {total_count: 1} });

for (const failure of ['network', 'api']) {
    test(`polling retries the same revision after a ${failure} failure`, async () => {
        let reads = 0;
        const page = setup(async params => {
            if (params.get('action') === 'check_update') return revision(2);
            if (++reads === 1) {
                if (failure === 'network') throw new Error('temporary connection failure');
                return {status: 'error', message: 'temporary server failure'};
            }
            return list(2);
        });
        page.run("lastKnownUpdate = '1'");
        await page.run('silentRefresh()');
        await page.run('silentRefresh()');
        assert.equal(page.run('currentData[0].id'), 2);
        assert.equal(page.run('lastKnownUpdate'), '2');
    });
}

test('manual loading does not acknowledge changes newer than its data', async () => {
    let currentRevision = 1;
    let reads = 0;
    const page = setup(async params => {
        if (params.get('action') === 'check_update') return revision(currentRevision);
        if (++reads === 1) { currentRevision = 2; return list(1); }
        return list(2);
    });
    await page.run('loadData()');
    await page.run('silentRefresh()');
    assert.equal(page.run('currentData[0].id'), 2);
});

test('failed manual loading leaves the page eligible for a retry', async () => {
    const page = setup(async params => params.get('action') === 'check_update'
        ? revision(2) : {status: 'error', message: 'temporary failure'});
    page.run("lastKnownUpdate = '1'");
    await page.run('loadData()');
    assert.equal(page.run('lastKnownUpdate'), null);
});

test('a late polling response cannot replace a newly selected warehouse', async () => {
    const waiting = deferred();
    const started = deferred();
    const page = setup(async params => {
        if (params.get('action') === 'check_update') return revision(2);
        if (params.get('status') === 'US') { started.resolve(); return waiting.promise; }
        return list(9);
    });
    const pending = page.run('silentRefresh()');
    await started.promise;
    page.run("currentTab = 'CN_WH'");
    await page.run('loadData()');
    waiting.resolve(list(1));
    await pending;
    assert.equal(page.run('currentData[0].id'), 9);
});

test('overlapping timer ticks do not start multiple polling requests', async () => {
    const waiting = deferred();
    let probes = 0;
    const page = setup(async params => {
        if (params.get('action') === 'check_update') { probes++; return waiting.promise; }
        return list(2);
    });
    const first = page.run('silentRefresh()');
    const second = page.run('silentRefresh()');
    waiting.resolve(revision(2));
    await Promise.all([first, second]);
    assert.equal(probes, 1);
});

test('locking the screen while polling is in flight prevents late data from rendering', async () => {
    const waiting = deferred();
    const started = deferred();
    const page = setup(async params => {
        if (params.get('action') === 'check_update') return revision(2);
        started.resolve();
        return waiting.promise;
    });
    const pending = page.run('silentRefresh()');
    await started.promise;
    page.lock();
    waiting.resolve(list(2));
    await pending;
    assert.equal(page.run('currentData[0].id'), 0);
});

test('polling cannot overtake an unfinished manual load and then be overwritten', async () => {
    const waiting = deferred();
    const started = deferred();
    let reads = 0;
    let currentRevision = 1;
    const page = setup(async params => {
        if (params.get('action') === 'check_update') return revision(currentRevision);
        if (++reads === 1) { currentRevision = 2; started.resolve(); return waiting.promise; }
        return list(2);
    });
    const pending = page.run('loadData()');
    await started.promise;
    await page.run('silentRefresh()');
    assert.equal(page.run('currentData[0].id'), 0, 'Polling overtook an unfinished manual request');
    waiting.resolve(list(1));
    await pending;
    await page.run('silentRefresh()');
    assert.equal(page.run('currentData[0].id'), 2);
});

test('a render-triggered page correction that fails is still retried', async () => {
    let correcting;
    let rendered = false;
    let reads = 0;
    const page = setup(async params => {
        if (params.get('action') === 'check_update') return revision(2);
        if (++reads === 2) return {status: 'error', message: 'temporary page correction failure'};
        return list(reads === 1 ? 1 : 2);
    }, () => {
        if (!rendered) {
            rendered = true;
            page.run('currentPage = 1');
            correcting = page.run('loadData()');
        }
    });
    page.run('currentPage = 2');
    await page.run('silentRefresh()');
    await correcting;
    await page.run('silentRefresh()');
    assert.equal(page.run('currentData[0].id'), 2);
});
