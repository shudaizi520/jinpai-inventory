const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../php/index.php'), 'utf8');
const verify = source.slice(source.indexOf('function verifyLock('), source.indexOf('function closeVerifyLockModal('));
const start = source.indexOf('// Inventory mutation unlock/retry');
const mutation = start < 0 ? '' : source.slice(start, source.indexOf('// End inventory mutation unlock/retry', start));

function pageFor(code = 'FINANCIAL_LOCKED') {
    let writes = 0, locked = true;
    const page = vm.createContext({
        setTimeout() {}, alert() {},
        document: {getElementById: () => ({value: '', classList: {remove() {}, add() {}}, focus() {}})},
        inventoryScreenLocked: () => false,
        apiFetch: async () => {
            writes++;
            return writes === 1
                ? new Response(JSON.stringify({status: 'error', code, message: 'Locked'}), {status: 403})
                : new Response(JSON.stringify({status: 'success'}));
        },
        relockFinance: async () => { locked = true; return new Response(JSON.stringify({status: 'success'})); },
    });
    vm.runInContext(`
        const PERM_FINANCE = 0;
        let currentTab = 'CN_WH', currentLockCallback = null, currentLockFailCallback = null;
        let financialMutationInFlight = false, isFinanceUnlocked = false;
        ${verify}
        ${mutation}
    `, page);
    return {
        run: expression => vm.runInContext(expression, page),
        writes: () => writes,
        locked: () => locked,
        async unlock() {
            locked = false;
            await vm.runInContext('currentLockCallback()', page);
        },
    };
}

test('an inventory-only employee can authorize a locked transition without opening finance', async () => {
    const page = pageFor();
    const result = page.run("submitInventoryMutation('api_inventory.php', {method: 'POST', body: 'original-request'})");
    await new Promise(setImmediate);
    assert.equal(page.run('typeof currentLockCallback'), 'function');
    await page.unlock();
    assert.equal((await (await result).json()).status, 'success');
    assert.equal(page.writes(), 2);
    assert.equal(page.locked(), true, 'Temporary financial authorization must be revoked before handing back the result');
});

test('cancelled financial verification does not retry an inventory mutation', async () => {
    const page = pageFor();
    const result = page.run("submitInventoryMutation('api_inventory.php', {method: 'POST'})");
    await new Promise(setImmediate);
    page.run('currentLockFailCallback()');
    assert.equal((await result).status, 403);
    assert.equal(page.writes(), 1);
    assert.equal(page.run('financialMutationInFlight'), false);
});

test('unrelated permission failures never trigger an unlock or retry', async () => {
    const page = pageFor('WAREHOUSE_FORBIDDEN');
    assert.equal((await page.run("submitInventoryMutation('api_inventory.php', {method: 'POST'})")).status, 403);
    assert.equal(page.writes(), 1);
    assert.equal(page.run('currentLockCallback'), null);
});

test('temporary authorization clears a stale financial display flag after relocking', async () => {
    const page = pageFor();
    page.run('isFinanceUnlocked = true');
    const result = page.run("submitInventoryMutation('api_inventory.php', {method: 'POST'})");
    await new Promise(setImmediate);
    await page.unlock();
    await result;
    assert.equal(page.run('isFinanceUnlocked'), false);
});

test('a second pending mutation cannot replace the first password prompt', async () => {
    const page = pageFor();
    const first = page.run("submitInventoryMutation('api_inventory.php', {method: 'POST'})");
    await new Promise(setImmediate);
    const originalCallback = page.run('currentLockCallback');
    // Return another financial lock rejection, as a concurrent HTTP request would.
    page.run("apiFetch = async () => ({status: 403, clone: () => ({json: async () => ({code: 'FINANCIAL_LOCKED'})})})");
    const second = page.run("submitInventoryMutation('api_inventory.php', {method: 'POST'})");
    const timeout = new Promise((_, reject) => setTimeout(() => reject(new Error('Second mutation replaced the unresolved prompt')), 100));
    assert.equal((await Promise.race([second, timeout])).status, 403);
    assert.equal(page.run('currentLockCallback'), originalCallback);
    page.run('currentLockFailCallback()');
    await first;
});
