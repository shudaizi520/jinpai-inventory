const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../php/index.php'), 'utf8');
const save = source.slice(source.indexOf('async function saveItem('), source.indexOf('// --- 拆分出库模态框控制 ---'));

for (const [cost, expected] of [['***', '1'], ['400.00', '0']]) {
    test(`save marks ${cost === '***' ? 'masked' : 'visible'} financial fields for the correct retry behavior`, async () => {
        let payload;
        class BrowserFormData extends FormData {
            constructor() {
                super();
                // Disabled cost/freight inputs are absent; blank masked collected becomes zero.
                for (const [key, value] of Object.entries({id: '3', status: 'CN_WH', quantity: '1', unit_collected: ''})) this.set(key, value);
            }
        }
        const page = vm.createContext({
            FormData: BrowserFormData,
            document: {getElementById: () => ({})}, alert() {}, closeModal() {}, loadData() {},
            submitInventoryMutation: async (_, init) => { payload = init.body; return {json: async () => ({status: 'success'})}; },
        });
        vm.runInContext(`
            const INVENTORY_API_URL = 'api_inventory.php';
            let isFinanceUnlocked = false;
            let currentData = [{id: 3, status: 'SOLD', cost_rmb: ${JSON.stringify(cost)}, freight: ${JSON.stringify(cost)}, collected_amount: ${JSON.stringify(cost)}}];
            ${save}
        `, page);
        await vm.runInContext('saveItem({preventDefault(){}, target:{querySelector(){return null;}}})', page);
        assert.equal(payload.get('preserve_finance'), expected);
    });
}
