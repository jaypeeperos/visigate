/* Shared builder: node tests/always-hide.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const el = (type, props, ...children) => ({type, props: {...props, children}});
const window = {wp: {
 element: {createElement: el, Fragment: 'Fragment'},
 components: {ToggleControl: 'Toggle', SelectControl: 'Select', Button: 'Button'},
 i18n: {__: s => s},
}};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/builder.js', 'utf8'), {window});
let saved;
const original = {action: 'hide', groups: [{conditions: [{type: 'logged_in', op: 'is'}]}]};
const before = JSON.stringify(original);
let tree = window.VisigateBuilder({value: original, onChange: v => {saved = v;}});
const toggle = tree.props.children[0];
toggle.props.onChange(true);
assert.equal(saved.always_hide, true);
assert.equal(JSON.stringify(saved.groups), JSON.stringify(original.groups), 'Hide preserves existing conditions');
assert.equal(JSON.stringify(original), before, 'No mutation of editor attributes');
tree = window.VisigateBuilder({value: saved, onChange: v => {saved = v;}});
assert.equal(tree.props.children[1].props.disabled, true, 'Conditional controls disabled while Always hide is on');
tree.props.children[0].props.onChange(false);
assert.equal(JSON.stringify(saved), before, 'Off restores exactly the previous rule');
tree = window.VisigateBuilder({value: undefined, onChange: v => {saved = v;}});
tree.props.children[0].props.onChange(true);
assert.equal(saved.always_hide, true);
assert.equal(saved.groups.length, 0, 'No dummy condition required');
console.log('PASS: Always hide toggle, preserved conditions, disabled controls and empty rules.');
