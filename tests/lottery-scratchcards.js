const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public_html/media/js/zywiec.js'), 'utf8');

class Element {
  constructor() {
    this.hidden = false; this.disabled = false; this.textContent = ''; this.dataset = {}; this.style = {};
    this.events = {}; this.children = {};
    this.classes = new Set();
    this.classList = { add: (...names) => names.forEach(n => this.classes.add(n)), remove: (...names) => names.forEach(n => this.classes.delete(n)), contains: n => this.classes.has(n) };
  }
  querySelector(name) { return this.children[name] || null; }
  querySelectorAll(name) { return this.children[name] || []; }
  addEventListener(name, fn) { this.events[name] = fn; }
  setAttribute() {} removeAttribute() {} focus() {} scrollIntoView() {}
}
const panel = new Element();
const modal = new Element();
const open = new Element(); const next = new Element(); const close = new Element();
for (const name of ['data-scratch-count', 'data-scratch-heading', 'data-scratch-link']) panel.children[`[${name}]`] = new Element();
panel.children['[data-open-scratch]'] = open;
for (const name of ['data-scratch-outcome', 'data-scratch-result', 'data-scratch-remaining']) modal.children[`[${name}]`] = new Element();
modal.children['[data-next-scratch]'] = next;
modal.children['[data-close-scratch]'] = close;
const cells = Array.from({ length: 9 }, () => {
  const cell = new Element();
  cell.children['.scratch-prize-img'] = new Element(); cell.children['.scratch-no-win'] = new Element();
  return cell;
});
modal.children['.scratch-cell'] = cells[0];
const modalQueryAll = modal.querySelectorAll.bind(modal);
modal.querySelectorAll = name => name === '.scratch-cell' ? cells : name === '[data-close-scratch]' ? [close] : modalQueryAll(name);
const errors = [new Element(), new Element()];
const storage = new Map();
const requests = [];
let respond;
const initial = { scratch_token: 'token-alice', scratch_link: '/loteria/zdrapki/token-alice', ticket_entries: [{ ticket_id: '1-01', submission_id: 1 }, { ticket_id: '1-02', submission_id: 1 }] };
panel.dataset.scratchState = JSON.stringify(initial);
const context = {
  body: new Element(), sessionStorage: { getItem: k => storage.get(k), setItem: (k, v) => storage.set(k, v), removeItem: k => storage.delete(k) },
  document: {
    querySelector: name => name === '[data-scratch-modal]' ? modal : panel,
    querySelectorAll: name => name === '[data-scratch-error]' ? errors : [open, next],
  },
  fetch: (url, options) => { requests.push({ url, payload: JSON.parse(options.body) }); return new Promise(resolve => { respond = resolve; }); },
};
const start = source.indexOf('  const modal = document.querySelector("[data-scratch-modal]");');
const end = source.indexOf('  const escapeSelector =', start);
vm.runInNewContext(source.slice(start, end) + '\nthis.configure = configureScratchQueue;', context);

(async () => {
  assert.equal(requests.length, 0, 'Wejście w link nie sprawdza wyników');
  assert.equal(modal.classList.contains('open'), false, 'Okno czeka na kliknięcie');
  assert.equal(open.hidden, false);
  const first = open.events.click();
  open.events.click();
  assert.equal(requests.length, 1, 'Podwójne kliknięcie wysyła jedno żądanie');
  assert.equal(requests[0].payload.scratch_token, 'token-alice');
  assert.equal(requests[0].payload.ticket_id, '1-01');
  respond({ ok: true, json: async () => ({ success: true, is_winner: true, prize_name: 'Nagroda testowa', ticket_entries: [initial.ticket_entries[1]] }) });
  await first;
  assert.equal(modal.classList.contains('open'), true);
  assert.equal(modal.children['[data-scratch-outcome]'].hidden, true, 'Wynik ustalony, lecz jeszcze przykryty');
  assert.equal(next.hidden, true, 'Kolejna zdrapka dostępna po odsłonięciu');
  cells[0].events.click();
  assert.equal(requests.length, 1, 'Zdrapywanie nie losuje ponownie');
  assert.equal(next.hidden, false);
  assert.match(modal.children['[data-scratch-result]'].textContent, /Nagroda testowa/);
  assert.equal(storage.size, 0, 'Odsłonięcie kończy oczekującą aktywację');
  const second = next.events.click();
  assert.equal(requests[1].payload.ticket_id, '1-02');
  respond({ ok: false, json: async () => ({ success: false, message: 'Błąd połączenia' }) });
  await second;
  assert.equal(errors[0].textContent, 'Błąd połączenia');
  const retry = next.events.click();
  assert.equal(requests[2].payload.ticket_id, '1-02', 'Ponowienie dotyczy tej samej zdrapki');
  respond({ ok: true, json: async () => ({ success: true, is_winner: false, ticket_entries: [] }) });
  await retry;
  cells[1].events.click();
  assert.equal(modal.children['[data-scratch-result]'].textContent, 'Tym razem bez wygranej.');
  assert.equal(next.hidden, true);
  assert.equal(open.hidden, true);
  assert.match(panel.children['[data-scratch-count]'].textContent, /Wszystkie/);
  close.events.click();
  assert.equal(modal.classList.contains('open'), false);
  context.configure({ scratchcards_enabled: false, scratch_token: 'token-alice' });
  assert.equal(panel.hidden, true, 'Brak przyznanych zdrapek nie otwiera kolejki');
  // Restore an activation whose response was lost before reloading the personal page.
  storage.set('lottery-scratch-active:token-alice', JSON.stringify(initial.ticket_entries[0]));
  context.configure({ ...initial, ticket_entries: [] });
  assert.equal(open.hidden, false);
  assert.equal(requests.length, 3, 'Odtworzenie strony nie aktywuje zdrapki');
  const resume = open.events.click();
  assert.equal(requests[3].payload.ticket_id, '1-01');
  respond({ ok: true, json: async () => ({ success: true, already_checked: true, is_winner: true, ticket_entries: [] }) });
  await resume;
  assert.equal(modal.classList.contains('open'), true, 'Zapisany wynik można ponownie odsłonić');
  console.log('OK: kolejka JS, brak automatycznego losowania, kolejna zdrapka po odsłonięciu, blokada kliknięć, retry i powrót po odświeżeniu.');
})().catch(error => { console.error(error); process.exitCode = 1; });
