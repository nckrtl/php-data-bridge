const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const events = [];
let nextResponse;
class XHR {
  listeners = [];
  addEventListener(name, fn) { this.listeners.push(fn); }
  send() { this.listeners.forEach(fn => fn()); }
  getResponseHeader() { return this.value; }
}
const window = { location: { origin: 'https://app.test' }, dispatchEvent: event => events.push(event.detail.data), fetch: async () => nextResponse };
vm.runInNewContext(fs.readFileSync('bridge/client.js', 'utf8'), { window, XMLHttpRequest: XHR, URL, atob, CustomEvent: class { constructor(name, options) { this.detail = options.detail; } } });
(async () => {
  const value = btoa(JSON.stringify({ request_id: '123' }));
  for (const [url, header, accepted] of [
    ['https://app.test/api', value, true],
    ['https://foreign.test/api', value, false],
    ['https://app.test/_toolbar/requests/123', value, false],
    ['https://app.test/api', 'x'.repeat(32769), false],
    ['https://app.test/api', 'invalid', false],
    ['', value, false],
  ]) {
    const count = events.length;
    nextResponse = { url, headers: { get: name => name === 'x-toolbar' ? header : null } };
    assert.equal(await window.fetch('https://app.test/original'), nextResponse);
    assert.equal(events.length - count, Number(accepted));
    const xhr = new XHR(); xhr.responseURL = url; xhr.value = header; xhr.send();
    assert.equal(events.length - count, Number(accepted) * 2);
  }
  nextResponse = { url: 'https://app.test/api', headers: { get: name => name === 'x-toolbar-summary' ? btoa(JSON.stringify({ request_id: 'summary', history_available: false })) : null } };
  await window.fetch('/api');
  assert.equal(events.at(-1).history_available, false);
  console.log('PASS: fetch/XHR origin, redirects, history exclusion, size limits, malformed headers');
})();
