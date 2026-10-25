const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const assert = require("node:assert/strict");

const source = fs.readFileSync(path.join(__dirname, "../public_html/media/js/zywiec.js"), "utf8");
const start = source.indexOf("  const getPhoneDigits =");
const end = source.indexOf("  const validateWinnerForm =", start);
assert(start >= 0 && end > start, "Nie znaleziono walidacji formularza");
const context = {
  document: { querySelectorAll: () => [] },
  clearFieldErrors: (form) => { form.errors = {}; },
  setFieldError: (form, name, message) => { form.errors[name] = message; },
};
vm.runInNewContext(source.slice(start, end) + "\nthis.validate = validatePromoForm; this.phoneDigits = getPhoneDigits;", context);

const makeForm = () => {
  const fields = Object.fromEntries(Object.entries({
    seller_code: "kod", phone: "+48 501 - 234 - 567", email: "uczestnik@example.com",
    purchase_document_number: "123/2026", promoted_products_count: "1",
    other_products_count: "0", store_chain: "7", store_nip: "526-025-09-95",
  }).map(([name, value]) => [name, { name, value, checkValidity: () => true }]));
  fields.purchase_receipt = { name: "purchase_receipt", files: [{ name: "paragon.PDF", type: "application/pdf", size: 15 * 1024 * 1024 }] };
  const checkboxes = ["not_excluded", "rules_accepted"].map((name) => ({ name, checked: true }));
  return { fields, checkboxes, elements: { namedItem: (name) => fields[name] }, querySelectorAll: () => checkboxes };
};

assert.equal(context.phoneDigits("+48 501 - 234 - 567"), "501234567");
assert.equal(context.phoneDigits("0048 501234567"), "501234567");
assert.equal(context.phoneDigits("481234567"), "481234567");
assert.equal(context.phoneDigits("5012345678"), "5012345678", "Zbyt długi numer nie może zostać skrócony");
let form = makeForm();
assert.equal(context.validate(form), true, "PDF dokładnie 15 MB i zero pozostałych produktów są poprawne");
assert.equal(form.fields.store_nip.value, "5260250995");
form = makeForm();
form.fields.promoted_products_count.value = "0";
form.fields.other_products_count.value = "2";
assert.equal(context.validate(form), true, "Można zgłosić same pozostałe produkty");
form.dataset = { dailyProductLimit: "4" };
form.fields.other_products_count.value = "5";
assert.equal(context.validate(form), true, "Większa deklaracja jest dozwolona; limit stosuje serwer przy naliczaniu");
form.fields.promoted_products_count.value = "2";
form.fields.other_products_count.value = "2";
assert.equal(context.validate(form), true, "Dokładnie 4 produkty są dozwolone");
form.dataset.dailyProductLimit = "0";
form.fields.other_products_count.value = "5";
assert.equal(context.validate(form), true, "0 wyłącza sprawdzenie dziennego limitu produktów");
for (const [field, value] of [
  ["phone", "221234567"], ["phone", "+49 501234567"], ["phone", "5012345678"],
  ["purchase_document_number", ""], ["promoted_products_count", "0"],
  ["promoted_products_count", "1.5"], ["other_products_count", ""],
  ["other_products_count", "-1"], ["store_chain", ""], ["store_nip", "5260250994"],
]) {
  form = makeForm();
  form.fields[field].value = value;
  assert.equal(context.validate(form), false, `${field}: ${value}`);
  assert(form.errors[field], `Brak błędu przy ${field}`);
}
for (const file of [
  undefined,
  { name: "paragon.pdf", type: "application/pdf", size: 15 * 1024 * 1024 + 1 },
  { name: "paragon.pdf", type: "image/png", size: 100 },
  { name: "paragon.gif", type: "image/gif", size: 100 },
  { name: "paragon.pdf", type: "application/pdf", size: 0 },
]) {
  form = makeForm();
  form.fields.purchase_receipt.files = file ? [file] : [];
  assert.equal(context.validate(form), false);
  assert(form.errors.purchase_receipt);
}
for (const extension of ["jpg", "png", "pdf"]) {
  form = makeForm();
  form.fields.purchase_receipt.files = [{ name: `paragon.${extension}`, type: "", size: 100 }];
  assert.equal(context.validate(form), true, "Przeglądarka może nie podać typu MIME");
}
form = makeForm();
form.checkboxes[0].checked = false;
assert.equal(context.validate(form), false);
assert(form.errors.not_excluded);
console.log("OK: walidacja JS, telefon, liczby, NIP, wymagane dane, checkboxy oraz format i granica rozmiaru pliku.");

const resultStart = source.indexOf("        form.reset();");
const resultEnd = source.indexOf("      } catch (error) {", resultStart);
assert(resultStart >= 0 && resultEnd > resultStart);
const renderResult = (submission) => {
  const state = {
    payload: { submission },
    form: { reset: () => {}, querySelectorAll: () => [] },
    successMessage: { hidden: true, textContent: "" },
    queued: null,
    scratchPanel: null,
  };
  state.configureScratchQueue = (scratch) => { state.queued = scratch; };
  vm.runInNewContext(`function finish() { ${source.slice(resultStart, resultEnd)} } finish();`, state);
  return state;
};
let state = renderResult({ scratchcards_enabled: false, message: "Przyznano 2 losy i 0 e-zdrapek." });
assert.equal(state.successMessage.hidden, false);
assert.equal(state.successMessage.textContent, "Przyznano 2 losy i 0 e-zdrapek.");
assert.equal(state.queued.scratchcards_enabled, false, "Wyczerpane zdrapki nie mogą powodować błędu po przyznaniu losów");
state = renderResult({ scratchcards_enabled: true, message: "Przyznano 0 losów i 1 e-zdrapkę.", ticket_entries: [{ ticket_id: "2-01", submission_id: 2 }] });
assert.equal(state.successMessage.hidden, false);
assert.equal(state.queued.ticket_entries[0].ticket_id, "2-01");
state = renderResult({ scratchcards_enabled: false, message: "" });
assert.equal(state.successMessage.textContent, "Dziękujemy! Twoje zgłoszenie zostało przyjęte.");
console.log("OK: komunikaty po częściowym przydziale i brak zdrapki po wyczerpaniu jej limitu.");
