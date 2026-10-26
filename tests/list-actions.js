// Execute the actual list formatter and delegated click handler without browser dependencies.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const handlers = [];
const forms = new Map();
const confirmations = [];
let confirmed = true;
let unsafeTranslation = false;
let checks = 0;
const document = {
  createElement(name) {
    if (name === 'option') { return {value: '', textContent: '', selected: false}; }
    assert.equal(name, 'span');
    return {
      textContent: '',
      get innerHTML() {
        return this.textContent.replace(/[&<>]/g, char => ({'&':'&amp;', '<':'&lt;', '>':'&gt;'}[char]));
      }
    };
  },
  getElementById(id) { return forms.get(id) || null; }
};
const context = vm.createContext({
  document,
  setTimeout(fn) { fn(); },
  window: {confirm(message) { confirmations.push(message); return confirmed; }},
  _(message) { return unsafeTranslation ? '<img src=x onerror="test">' : message; },
  $(value) {
    assert.equal(value, document);
    return {on(event, selector, handler) { handlers.push({event, selector, handler}); }};
  }
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/announcementtts.js'), 'utf8'), context);
function check(ok, message) {
  assert.ok(ok, message);
  checks++;
  console.log('PASS ' + message);
}
for (const id of [1, '42']) {
  const html = context.actionformatter(id, {});
  check(html.includes('fa-edit') && html.includes('announcementtts-delete') && html.includes('fa-trash')
    && html.includes('data-id="' + id + '"') && html.includes('title="Delete" aria-label="Delete"'), 'List row exposes both accessible icon actions for ID ' + id);
  check(html.replace(/<[^>]*>/g, '').trim() === '' && !html.includes('btn btn-link'), 'List actions contain only icons without a text-button appearance');
  check(html.includes('type="button"') && !html.includes('action=delete'), 'List deletion does not navigate to a mutating GET URL');
}
for (const id of [0, -1, '', null, undefined, '1" onclick="test', '1 OR 1=1']) {
  check(context.actionformatter(id, {}) === '', 'Formatter rejects malformed ID ' + JSON.stringify(id));
}
unsafeTranslation = true;
const translated = context.actionformatter(1, {});
check(!translated.includes('<img') && translated.includes('&lt;img'), 'Translated action labels are HTML-escaped');
check(!translated.includes('onerror="test"') && translated.includes('onerror=&quot;test&quot;'), 'Translated labels also escape quotes in title and aria-label attributes');
unsafeTranslation = false;
const click = handlers.find(item => item.event === 'click' && item.selector === '#table-all .announcementtts-delete');
check(Boolean(click), 'Delete handler is delegated to asynchronously loaded grid rows');
let submits = 0;
let prevented = 0;
let stopped = 0;
const form = {elements: {announcementtts_id: {value: ''}, announcementtts_csrf: {value: 'session-test-marker'}}, submit() { submits++; }};
forms.set('announcementtts-delete-form', form);
const button = {disabled: false, getAttribute(name) { assert.equal(name, 'data-id'); return '42'; }};
const event = {preventDefault() { prevented++; }, stopPropagation() { stopped++; }};
confirmed = false;
click.handler.call(button, event);
check(submits === 0 && form.elements.announcementtts_id.value === '' && !button.disabled, 'Cancelling confirmation neither submits nor changes the selected record');
confirmed = true;
click.handler.call(button, event);
check(submits === 1 && form.elements.announcementtts_id.value === '42' && button.disabled, 'Confirmation submits the selected row and disables its delete button');
check(form.elements.announcementtts_csrf.value === 'session-test-marker', 'List click preserves the server-rendered session token');
check(prevented === 2 && stopped === 2 && confirmations.every(message => message === 'Delete this announcement?'), 'Click prevents navigation and row propagation and asks for confirmation');
const beforeConfirmations = confirmations.length;
click.handler.call(button, event);
check(submits === 1 && confirmations.length === beforeConfirmations, 'Repeated activation cannot submit the same deletion twice');
click.handler.call({getAttribute() { return 'invalid'; }}, event);
check(submits === 1 && confirmations.length === beforeConfirmations, 'Malformed button IDs cannot submit a deletion');
forms.clear();
click.handler.call({getAttribute() { return '42'; }}, event);
check(submits === 1 && confirmations.length === beforeConfirmations, 'Missing POST form cannot submit a deletion');
const changeEngine = handlers.find(item => item.event === 'change' && item.selector === '#backend');
check(Boolean(changeEngine), 'Engine selector has a delegated change handler');
context.announcementttsVoices = {
  'tts-1': {voices: ['alloy', 'fable'], defaultVoice: 'fable'},
  realtime: {voices: ['alloy', 'marin', 'cedar'], defaultVoice: 'marin'}
};
const engine = {value: 'realtime'};
const voice = {value: 'fable', options: [], get firstChild() {return this.options[0];},
  removeChild() {this.options.shift();}, appendChild(option) {this.options.push(option);},
  getAttribute(name) {assert.equal(name, 'data-initial-voice'); return 'cedar';}};
forms.set('backend', engine); forms.set('voice', voice);
changeEngine.handler();
check(voice.value === 'marin' && voice.options.map(o => o.value).join(',') === 'alloy,marin,cedar',
  'Switching to Realtime replaces all options and selects Marin when the old voice is unsupported');
engine.value = 'tts-1'; changeEngine.handler();
check(voice.value === 'fable' && voice.options.map(o => o.value).join(',') === 'alloy,fable',
  'Switching to tts-1 removes Realtime voices and selects Fable');
voice.value = 'alloy'; engine.value = 'realtime'; changeEngine.handler();
check(voice.value === 'alloy', 'A voice supported by both engines remains selected');
handlers.find(item => item.event === 'reset').handler();
check(voice.value === 'cedar', 'Form reset rebuilds voice options and restores the saved voice');
engine.value = 'invalid'; changeEngine.handler();
check(voice.value === 'cedar', 'An unknown engine cannot populate arbitrary voices');
forms.clear(); changeEngine.handler();
check(true, 'Engine handler safely ignores pages without the form');
console.log(checks + ' JavaScript checks passed.');
