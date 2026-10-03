#!/usr/bin/env node
// Exercise the real browser search script against the staged public index.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = __dirname;
const indexPath = process.argv[2] || path.join(root,
  'PortalSite/public/assets/docs/js/search-index.js');
const indexSource = fs.readFileSync(indexPath, 'utf8');
const docsSource = fs.readFileSync(path.join(root, 'PortalAssets/docs.js'), 'utf8');

function node(tagName = 'div') {
  return {
    tagName: tagName.toUpperCase(), children: [], listeners: {},
    value: '', textContent: '', hidden: false,
    classList: {contains: () => false, add() {}, remove() {}, toggle() {}},
    setAttribute() {}, removeAttribute() {}, focus() {}, scrollIntoView() {},
    addEventListener(type, handler) { this.listeners[type] = handler; },
    append(...children) { this.children.push(...children); },
    replaceChildren(...children) { this.children = children; },
  };
}

const input = node('input');
const results = node();
const overlay = node();
overlay.hidden = true;
const open = node('button');
const body = node('body');
body.dataset = {siteRoot: '/', version: 'v2.x'};
const document = {
  body,
  documentElement: {dataset: {}},
  activeElement: null,
  querySelector(selector) {
    return {'.search-overlay': overlay, '#search-input': input,
      '#search-results': results}[selector] || null;
  },
  querySelectorAll(selector) {
    return selector === '[data-search-open]' ? [open] : [];
  },
  createElement: node,
  createTextNode: value => ({textContent: value}),
  addEventListener() {},
};
const context = {
  document, window: {addEventListener() {}},
  localStorage: {getItem: () => null, setItem() {}},
  location: {href: 'http://localhost/docs/v2.x'},
  URL, innerWidth: 1200, setTimeout,
};
vm.runInNewContext(indexSource, context);
vm.runInNewContext(docsSource, context);
assert.equal(typeof open.listeners.click, 'function');
open.listeners.click();

function search(query) {
  input.value = query;
  input.listeners.input();
  return results.children.filter(item => item.tagName === 'A')
    .map(item => new URL(item.href).pathname);
}

function visibleText(element) {
  return [element.textContent || '', ...(element.children || []).map(visibleText)]
    .join(' ');
}

function resultFor(pathname) {
  return results.children.find(item => item.tagName === 'A'
    && new URL(item.href).pathname === pathname);
}

for (const query of ['agent:mcp', 'agent:status']) {
  assert.ok(search(query).includes('/docs/v2.x/agent-mcp-setup'),
    `${query} should find the local client setup guide`);
}
assert.ok(search('ChangePlan').includes('/docs/v2.x/agent-mcp-tools'),
  'ChangePlan should find the capability and tool reference');

const defaultResults = search('');
assert.ok(defaultResults.includes('/docs/v2.x/installation'));
assert.match(results.children.map(visibleText).join(' '), /v2\.x/,
  'documentation search should retain version labels');
const docsInstallation = resultFor('/docs/v2.x/installation');
assert.match(visibleText(docsInstallation.children[1]), /\bv2\.0\.0\b/,
  'the source title must exercise landing version removal');
assert.match(visibleText(docsInstallation.children[2]), /\bv2\.0\.0\b/,
  'the source summary must exercise landing version removal');

// Mount the same browser script as the public landing, where visible search
// result copy omits version tokens while result URLs remain versioned.
body.classList.contains = name => name === 'portal-page';
vm.runInNewContext(docsSource, context);
open.listeners.click();
assert.deepEqual(search(''), defaultResults);
const landingCopy = results.children.map(visibleText).join(' ');
assert.match(landingCopy, /Documentation ·/,
  'landing search should use version-neutral categories');
assert.doesNotMatch(landingCopy, /\bv2(?:\.(?:x|\d+(?:\.\d+)*))?\b/i,
  'landing search should remove visible v2 labels from titles and summaries');
const landingInstallation = resultFor('/docs/v2.x/installation');
for (const item of landingInstallation.children) {
  assert.doesNotMatch(visibleText(item), /\bv2(?:\.(?:x|\d+(?:\.\d+)*))?\b/i);
}

console.log('Browser search finds Agent guides and renders version-neutral landing results.');
