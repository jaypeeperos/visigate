/* Rule warnings in the shared builder: node tests/rule-warnings.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const el = (type, props, ...children) => ({type, props: {...props, children}});
const filters = {};
const tokens = {options: 'x'};
const window = {
	VisigateData: {
		siteNow: '2026-11-12T10:00',
		groups: {},
		options: {devices: {desktop: 'Desktop', mobile: 'Mobile', tablet: 'Tablet'}},
		conditions: {
			logged_in: {label: 'Login status', input: 'none', ops: {is: '', is_not: ''}},
			user_role: {label: 'User role', input: 'tokens', ops: {is_any: '', is_none: ''}},
			device: {label: 'Device type', input: 'tokens', options: 'devices', ops: {is_any: '', is_none: ''}},
			woo_cart_total: {label: 'Cart subtotal', input: 'number', ops: {gte: '', lte: '', eq: ''}},
			time_of_day: {label: 'Time of day', input: 'time_range', ops: {between: ''}},
			date_range: {label: 'Date range', input: 'date_range', ops: {within: '', outside: ''}},
			url_param: {label: 'URL parameter', input: 'kv', no_value: ['exists'], ops: {equals: '', exists: ''}},
			specific_post: {label: 'Specific Page or Post', input: 'async', source: 'posts', ops: {is_any: ''}},
		},
	},
	wp: {
		element: {createElement: el, Fragment: 'Fragment', useState: v => [v, () => {}], useRef: () => ({}), useEffect: () => {}},
		components: {ToggleControl: 'Toggle', SelectControl: 'Select', Button: 'Button'},
		i18n: {__: s => s, sprintf: (f, ...a) => { let i = 0; return f.replace(/%(\d\$)?[sd]/g, (m, n) => String(n ? a[parseInt(n, 10) - 1] : a[i++])); }},
		hooks: {applyFilters: (name, value, ...args) => (filters[name] || []).reduce((v, f) => f(v, ...args), value)},
	},
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/builder.js', 'utf8'), {window, setTimeout, clearTimeout});
const warnings = r => JSON.parse(JSON.stringify(window.VisigateBuilderUtils.warnings(r)));
const rule = (...conds) => ({action: 'show', groups: [{conditions: conds}]});
const levels = r => warnings(r).map(w => w.level);
const texts = r => warnings(r).map(w => w.text).join('\n');

assert.deepEqual(warnings(rule({type: 'logged_in', op: 'is'})), [], 'A simple rule has no warnings');
assert.ok(levels(rule({type: 'logged_in', op: 'is'}, {type: 'logged_in', op: 'is_not'})).includes('error'), 'Logged in AND logged out');
assert.match(texts(rule({type: 'logged_in', op: 'is_not'}, {type: 'user_role', op: 'is_any', value: ['editor']})), /no account/, 'Logged out AND a role');
assert.deepEqual(warnings(rule({type: 'logged_in', op: 'is_not'}, {type: 'user_role', op: 'is_none', value: ['editor']})), [], 'Logged out AND "not a role" is fine');
assert.match(texts(rule({type: 'device', op: 'is_any', value: ['mobile']}, {type: 'device', op: 'is_any', value: ['desktop']})), /exclude each other/, 'Single-value lists that do not overlap');
assert.deepEqual(warnings(rule({type: 'device', op: 'is_any', value: ['mobile', 'tablet']}, {type: 'device', op: 'is_any', value: ['tablet']})), [], 'Overlapping lists are fine');
assert.deepEqual(warnings(rule({type: 'user_role', op: 'is_any', value: ['editor']}, {type: 'user_role', op: 'is_any', value: ['shop_manager']})), [], 'Users can have several roles');
assert.match(texts(rule({type: 'user_role', op: 'is_any', value: ['editor']}, {type: 'user_role', op: 'is_none', value: ['editor', 'author']})), /exclude each other/, 'is any of A AND is none of A');
assert.match(texts(rule({type: 'woo_cart_total', op: 'gte', value: '100'}, {type: 'woo_cart_total', op: 'lte', value: '50'})), /cannot both be met/, 'Impossible number range');
assert.deepEqual(warnings(rule({type: 'woo_cart_total', op: 'gte', value: '50'}, {type: 'woo_cart_total', op: 'lte', value: '100'})), [], 'Valid number range');
assert.match(texts(rule({type: 'time_of_day', op: 'between', value: {from: '09:00', to: '09:00'}})), /same minute/, 'Empty time range');
assert.match(texts(rule({type: 'date_range', op: 'within', value: {from: '2026-02-01', to: '2026-01-01'}})), /ends before it starts/, 'Reversed date range');
assert.match(texts(rule({type: 'date_range', op: 'within', value: {from: '', to: '2026-11-11'}})), /ended on 2026-11-11 23:59/, 'Ended date range');
assert.deepEqual(warnings(rule({type: 'date_range', op: 'within', value: {from: '', to: '2026-11-12'}})), [], 'A range ending today (whole day) has not ended');
assert.match(texts(rule({type: 'date_range', op: 'outside', value: {from: '', to: '2026-01-01T08:00'}})), /always true/, 'Ended "outside" range');
assert.match(texts(rule({type: 'url_param', op: 'equals', value: {key: 'a', value: ''}})), /incomplete/, 'Incomplete condition');
assert.deepEqual(warnings(rule({type: 'url_param', op: 'exists', value: {key: 'a', value: ''}})), [], 'Existence needs only a key');
const empty = warnings({action: 'show', groups: [{conditions: [{type: 'device', op: 'is_any', value: []}]}]});
assert.ok(empty.some(w => /skipped/.test(w.text)) && empty.some(w => /always visible/.test(w.text)), 'All groups incomplete');
assert.match(texts({action: 'hide', groups: []}), /never hidden/, 'Hide with no conditions');
assert.match(texts(rule({type: 'country', op: 'is_any', value: ['PH']})), /add-on that is not active/, 'Unknown condition type');
assert.deepEqual(levels({always_hide: true, groups: [{conditions: [{type: 'logged_in', op: 'is'}, {type: 'logged_in', op: 'is_not'}]}]}), ['info'], 'Always hide: only the info note');
const two = warnings({action: 'show', groups: [{conditions: [{type: 'logged_in', op: 'is'}]}, {conditions: [{type: 'logged_in', op: 'is'}, {type: 'logged_in', op: 'is_not'}]}]});
assert.equal(two.length, 1);
assert.match(two[0].text, /^Group 2 can never match/, 'Groups are numbered when there are several');
filters['visigate.builder.warnings'] = [list => list.concat([{level: 'info', text: 'add-on'}])];
assert.ok(texts(rule({type: 'logged_in', op: 'is'})).includes('add-on'), 'Add-ons can extend warnings');
const tree = window.VisigateBuilder({value: rule({type: 'logged_in', op: 'is'}), onChange: () => {}});
assert.equal(tree.props.children[tree.props.children.length - 1].type.name, 'Warnings', 'Warnings render below the summary');
// Pro: Query Loop scope control and its warning (when the add-on sits next to this plugin).
const proFile = __dirname + '/../../visigate-pro/assets/js/pro-builder.js';
if (fs.existsSync(proFile)) {
	window.wp.hooks.addFilter = (name, ns, fn) => { (filters[name] = filters[name] || []).push(fn); };
	window.VisigateData.conditions.specific_post.item = true;
	window.VisigateData.isPro = true;
	window.VisigateData.pro = {tiers: {}, presets: []};
	vm.runInNewContext(fs.readFileSync(proFile, 'utf8'), {window});
	const extras = r => { let out; filters['visigate.builder.extras'].reduce((v, f) => f(v, {rules: r, onChange: x => { out = x; }, clone: o => JSON.parse(JSON.stringify(o))}), []).forEach(item => { if (item.props.key === 'vgp-scope') { const node = item.type(item.props); if (node) { node.props.children[0].props.onChange('item'); } } }); return out; };
	assert.equal(extras(rule({type: 'logged_in', op: 'is'})), undefined, 'No scope control without post/product conditions');
	assert.equal(extras(rule({type: 'specific_post', op: 'is_any', value: [5]})).scope, 'item', 'Scope control sets scope');
	assert.match(texts({action: 'show', scope: 'item', groups: [{conditions: [{type: 'logged_in', op: 'is'}]}]}), /has no effect/, 'Scope without item conditions is flagged');
}
console.log('PASS: contradictions, incomplete and skipped groups, impossible ranges, ended dates, add-on conditions and extensions.');
