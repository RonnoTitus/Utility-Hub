/*
 * UtilityHub client-side tools.
 * All input stays in the visitor's browser. Pure logic lives in UH.logic so it
 * can be unit-tested without a DOM; UI mounting only runs when a
 * [data-client-tool] element is present.
 */
(function (root) {
  'use strict';

  // ---------------------------------------------------------------- logic
  const logic = {};

  /** Cryptographically random integer in [0, max). Rejection sampling avoids modulo bias. */
  logic.randomInt = function (max, cryptoObj) {
    const c = cryptoObj || root.crypto;
    if (!c || !c.getRandomValues) throw new Error('Secure random numbers are not available in this browser.');
    const limit = Math.floor(0x100000000 / max) * max;
    const buf = new Uint32Array(1);
    do { c.getRandomValues(buf); } while (buf[0] >= limit);
    return buf[0] % max;
  };

  logic.generatePassword = function (opts, cryptoObj) {
    const sets = {
      lower: 'abcdefghijkmnopqrstuvwxyz',
      upper: 'ABCDEFGHJKLMNPQRSTUVWXYZ',
      digits: '23456789',
      symbols: '!@#$%^&*()-_=+[]{};:,.?',
    };
    const full = { lower: 'abcdefghijklmnopqrstuvwxyz', upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', digits: '0123456789', symbols: sets.symbols };
    const source = opts.avoidAmbiguous ? sets : full;
    const active = ['lower', 'upper', 'digits', 'symbols'].filter((k) => opts[k]);
    const length = Math.min(128, Math.max(4, Math.floor(Number(opts.length) || 16)));
    if (!active.length) throw new Error('Choose at least one character type.');
    const pool = active.map((k) => source[k]).join('');
    const out = active.map((k) => source[k][logic.randomInt(source[k].length, cryptoObj)]);
    while (out.length < length) out.push(pool[logic.randomInt(pool.length, cryptoObj)]);
    for (let i = out.length - 1; i > 0; i--) {
      const j = logic.randomInt(i + 1, cryptoObj);
      [out[i], out[j]] = [out[j], out[i]];
    }
    return out.join('');
  };

  logic.passwordStrength = function (pw) {
    let pool = 0;
    if (/[a-z]/.test(pw)) pool += 26;
    if (/[A-Z]/.test(pw)) pool += 26;
    if (/\d/.test(pw)) pool += 10;
    if (/[^A-Za-z0-9]/.test(pw)) pool += 32;
    const bits = pw.length && pool ? Math.round(pw.length * Math.log2(pool)) : 0;
    const label = bits < 40 ? 'Weak' : bits < 60 ? 'Fair' : bits < 80 ? 'Strong' : 'Very strong';
    return { bits, label };
  };

  const pad = (n) => String(n).padStart(2, '0');
  const parseISO = (s) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    if (!m) return null;
    const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    return d.getUTCFullYear() === +m[1] && d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3] ? d : null;
  };
  logic.parseISO = parseISO;

  /** Calendar difference (years, months, days) between two UTC dates, from <= to. */
  logic.dateDiff = function (fromStr, toStr) {
    const a = parseISO(fromStr), b = parseISO(toStr);
    if (!a || !b) throw new Error('Enter two valid dates.');
    let from = a, to = b, negative = false;
    if (from > to) { [from, to] = [to, from]; negative = true; }
    let totalMonths = (to.getUTCFullYear() - from.getUTCFullYear()) * 12 + (to.getUTCMonth() - from.getUTCMonth());
    if (to.getUTCDate() < from.getUTCDate()) totalMonths -= 1;
    // Anchor = start date + whole months, clamped to the month's last day (31 Jan + 1 month = 29 Feb).
    const ay = from.getUTCFullYear() + Math.floor((from.getUTCMonth() + totalMonths) / 12);
    const am = (from.getUTCMonth() + totalMonths) % 12;
    const lastDay = new Date(Date.UTC(ay, am + 1, 0)).getUTCDate();
    const anchor = Date.UTC(ay, am, Math.min(from.getUTCDate(), lastDay));
    const days = Math.round((to.getTime() - anchor) / 86400000);
    const years = Math.floor(totalMonths / 12);
    const months = totalMonths % 12;
    const totalDays = Math.round((to - from) / 86400000);
    return { years, months, days, totalDays, weeks: Math.floor(totalDays / 7), negative };
  };

  logic.age = function (birthStr, onStr) {
    const d = logic.dateDiff(birthStr, onStr);
    if (d.negative) throw new Error('Date of birth must be before the comparison date.');
    const birth = parseISO(birthStr), on = parseISO(onStr);
    let next = Date.UTC(on.getUTCFullYear(), birth.getUTCMonth(), birth.getUTCDate());
    if (next < on.getTime()) next = Date.UTC(on.getUTCFullYear() + 1, birth.getUTCMonth(), birth.getUTCDate());
    const nd = new Date(next);
    // Feb 29 birthdays roll to Mar 1 in non-leap years via Date.UTC.
    const daysToBirthday = Math.round((next - on.getTime()) / 86400000);
    return Object.assign(d, {
      daysToBirthday,
      nextBirthday: `${nd.getUTCFullYear()}-${pad(nd.getUTCMonth() + 1)}-${pad(nd.getUTCDate())}`,
      weekday: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][birth.getUTCDay()],
    });
  };

  /** Standard amortizing loan. rate is annual percent; months is the term. */
  logic.loan = function (principal, annualRate, months) {
    principal = Number(principal); annualRate = Number(annualRate); months = Math.floor(Number(months));
    if (!(principal > 0) || !(annualRate >= 0) || !(months > 0) || months > 1200) {
      throw new Error('Enter a positive amount and term, and a rate of 0 or more.');
    }
    const r = annualRate / 100 / 12;
    const payment = r === 0 ? principal / months : (principal * r) / (1 - Math.pow(1 + r, -months));
    let balance = principal;
    const schedule = [];
    let interestTotal = 0;
    for (let i = 1; i <= months; i++) {
      const interest = balance * r;
      let principalPart = payment - interest;
      if (i === months) principalPart = balance;
      balance = Math.max(0, balance - principalPart);
      interestTotal += interest;
      schedule.push({ n: i, payment: principalPart + interest, interest, principal: principalPart, balance });
    }
    return { payment, interestTotal, total: principal + interestTotal, schedule };
  };

  logic.compound = function (principal, annualRate, years, perYear, monthly) {
    principal = Number(principal); annualRate = Number(annualRate); years = Number(years);
    perYear = Math.floor(Number(perYear)); monthly = Number(monthly) || 0;
    if (!(principal >= 0) || !(annualRate >= 0) || !(years > 0) || years > 100 || !(perYear > 0) || monthly < 0) {
      throw new Error('Enter valid amounts, a rate of 0 or more, and a term up to 100 years.');
    }
    if (principal === 0 && monthly === 0) throw new Error('Enter a starting amount or monthly contribution.');
    // Month-by-month: contributions are added at month end; interest accrues at each compounding date.
    const rate = annualRate / 100;
    const totalMonths = Math.round(years * 12);
    const yearly = [];
    let balance = principal, contributed = principal;
    const stepsPerMonth = perYear / 12;
    let accrued = 0;
    for (let m = 1; m <= totalMonths; m++) {
      accrued += stepsPerMonth;
      while (accrued >= 1 - 1e-9) { balance *= 1 + rate / perYear; accrued -= 1; }
      balance += monthly; contributed += monthly;
      if (m % 12 === 0 || m === totalMonths) {
        yearly.push({ year: Math.ceil(m / 12), balance, contributed, interest: balance - contributed });
      }
    }
    return { balance, contributed, interest: balance - contributed, yearly };
  };

  // Units are stored as factor to a base unit; temperature is handled separately.
  logic.UNITS = {
    Length: { base: 'm', units: { Millimetre: 0.001, Centimetre: 0.01, Metre: 1, Kilometre: 1000, Inch: 0.0254, Foot: 0.3048, Yard: 0.9144, Mile: 1609.344 } },
    Weight: { base: 'kg', units: { Milligram: 1e-6, Gram: 0.001, Kilogram: 1, Tonne: 1000, Ounce: 0.028349523125, Pound: 0.45359237, Stone: 6.35029318 } },
    Volume: { base: 'L', units: { Millilitre: 0.001, Litre: 1, 'US teaspoon': 0.00492892159375, 'US tablespoon': 0.01478676478125, 'US cup': 0.2365882365, 'US pint': 0.473176473, 'US gallon': 3.785411784, 'UK gallon': 4.54609 } },
    Area: { base: 'm²', units: { 'Square metre': 1, 'Square kilometre': 1e6, 'Square foot': 0.09290304, 'Square yard': 0.83612736, Acre: 4046.8564224, Hectare: 10000, 'Square mile': 2589988.110336 } },
    Speed: { base: 'm/s', units: { 'Metre/second': 1, 'Kilometre/hour': 1 / 3.6, 'Mile/hour': 0.44704, Knot: 0.514444444444 } },
    Data: { base: 'B', units: { Byte: 1, Kilobyte: 1e3, Megabyte: 1e6, Gigabyte: 1e9, Terabyte: 1e12, Kibibyte: 1024, Mebibyte: 1048576, Gibibyte: 1073741824 } },
    Temperature: { base: 'K', units: { Celsius: 1, Fahrenheit: 1, Kelvin: 1 } },
  };

  logic.convertUnit = function (category, from, to, value) {
    const v = Number(value);
    if (!Number.isFinite(v)) throw new Error('Enter a number to convert.');
    const cat = logic.UNITS[category];
    if (!cat || !(from in cat.units) || !(to in cat.units)) throw new Error('Unknown unit.');
    if (category === 'Temperature') {
      const toK = { Celsius: (x) => x + 273.15, Fahrenheit: (x) => (x - 32) * 5 / 9 + 273.15, Kelvin: (x) => x };
      const fromK = { Celsius: (k) => k - 273.15, Fahrenheit: (k) => (k - 273.15) * 9 / 5 + 32, Kelvin: (k) => k };
      const k = toK[from](v);
      if (k < 0) throw new Error('That is below absolute zero.');
      return fromK[to](k);
    }
    return (v * cat.units[from]) / cat.units[to];
  };

  logic.formatNumber = function (n, maxDigits) {
    if (!Number.isFinite(n)) return '—';
    const abs = Math.abs(n);
    if (abs !== 0 && (abs >= 1e12 || abs < 1e-6)) return n.toExponential(6).replace(/\.?0+e/, 'e');
    return Number(n.toPrecision(maxDigits || 10)).toLocaleString('en-US', { maximumFractionDigits: 10 });
  };

  logic.formatJson = function (text, indent) {
    if (!String(text).trim()) throw new Error('Paste some JSON first.');
    let parsed;
    try { parsed = JSON.parse(text); } catch (e) {
      const m = /position (\d+)/.exec(e.message);
      let hint = e.message;
      if (m) {
        const pos = +m[1];
        const upTo = text.slice(0, pos);
        const line = upTo.split('\n').length;
        const col = pos - upTo.lastIndexOf('\n');
        hint = `${e.message} (line ${line}, column ${col})`;
      }
      throw new Error(hint);
    }
    return indent === 'min' ? JSON.stringify(parsed) : JSON.stringify(parsed, null, indent === 'tab' ? '\t' : Number(indent) || 2);
  };

  logic.base64Encode = function (text) {
    const bytes = new TextEncoder().encode(text);
    let bin = '';
    for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return root.btoa(bin);
  };
  logic.base64Decode = function (text) {
    const clean = String(text).replace(/\s+/g, '').replace(/-/g, '+').replace(/_/g, '/');
    if (!/^[A-Za-z0-9+/]*={0,2}$/.test(clean) || clean.length % 4 === 1) throw new Error('That is not valid Base64.');
    const bin = root.atob(clean + '='.repeat((4 - (clean.length % 4)) % 4));
    const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0));
    try { return new TextDecoder('utf-8', { fatal: true }).decode(bytes); } catch (_) {
      throw new Error('Decoded data is not valid UTF-8 text (it may be a binary file).');
    }
  };

  logic.parseColor = function (input) {
    const s = String(input).trim().toLowerCase();
    let m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/.exec(s);
    if (m) {
      let h = m[1];
      if (h.length === 3) h = h.split('').map((c) => c + c).join('');
      return { r: parseInt(h.slice(0, 2), 16), g: parseInt(h.slice(2, 4), 16), b: parseInt(h.slice(4, 6), 16) };
    }
    m = /^rgb\(\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*\)$/.exec(s);
    if (m && [m[1], m[2], m[3]].every((x) => +x <= 255)) return { r: +m[1], g: +m[2], b: +m[3] };
    m = /^hsl\(\s*(\d{1,3}(?:\.\d+)?)\s*[, ]\s*(\d{1,3}(?:\.\d+)?)%\s*[, ]\s*(\d{1,3}(?:\.\d+)?)%\s*\)$/.exec(s);
    if (m && +m[1] <= 360 && +m[2] <= 100 && +m[3] <= 100) return logic.hslToRgb(+m[1], +m[2], +m[3]);
    throw new Error('Use a HEX (#1a2b3c), rgb(26, 43, 60) or hsl(210, 40%, 18%) value.');
  };
  logic.hslToRgb = function (h, s, l) {
    s /= 100; l /= 100;
    const k = (n) => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = (n) => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
    return { r: Math.round(f(0) * 255), g: Math.round(f(8) * 255), b: Math.round(f(4) * 255) };
  };
  logic.rgbToHsl = function ({ r, g, b }) {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
    let h = 0;
    const l = (max + min) / 2;
    const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
    if (d !== 0) {
      if (max === r) h = ((g - b) / d) % 6; else if (max === g) h = (b - r) / d + 2; else h = (r - g) / d + 4;
      h *= 60; if (h < 0) h += 360;
    }
    return { h: Math.round(h), s: Math.round(s * 100), l: Math.round(l * 100) };
  };
  logic.rgbToHex = ({ r, g, b }) => '#' + [r, g, b].map((x) => x.toString(16).padStart(2, '0')).join('').toUpperCase();
  /** WCAG relative luminance contrast ratio against white and black. */
  logic.contrast = function ({ r, g, b }) {
    const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    const L = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    return { white: 1.05 / (L + 0.05), black: (L + 0.05) / 0.05 };
  };

  logic.convertCase = function (text, mode) {
    const words = () => String(text).match(/[\p{L}\p{N}]+/gu) || [];
    switch (mode) {
      case 'upper': return text.toUpperCase();
      case 'lower': return text.toLowerCase();
      case 'title': return text.toLowerCase().replace(/(^|[\s\-(\["'“])(\p{L})/gu, (_, a, b) => a + b.toUpperCase());
      case 'sentence': return text.toLowerCase().replace(/(^\s*|[.!?]\s+)(\p{L})/gu, (_, a, b) => a + b.toUpperCase());
      case 'camel': return words().map((w, i) => (i ? w[0].toUpperCase() + w.slice(1).toLowerCase() : w.toLowerCase())).join('');
      case 'pascal': return words().map((w) => w[0].toUpperCase() + w.slice(1).toLowerCase()).join('');
      case 'snake': return words().map((w) => w.toLowerCase()).join('_');
      case 'kebab': return words().map((w) => w.toLowerCase()).join('-');
      case 'slug': return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
      case 'reverse': return Array.from(text).reverse().join('');
      case 'trim': return text.split('\n').map((l) => l.trim().replace(/\s+/g, ' ')).join('\n').trim();
      default: throw new Error('Unknown mode.');
    }
  };

  // ------------------------------------------------------------------ UI
  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n, cur) => {
    try { return new Intl.NumberFormat('en-US', { style: 'currency', currency: cur, maximumFractionDigits: cur === 'UGX' ? 0 : 2 }).format(n); }
    catch (_) { return n.toFixed(2); }
  };
  const num = (n) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
  const detail = (k, v) => `<div class="result-detail"><span>${esc(k)}</span><strong>${esc(v)}</strong></div>`;
  const placeholder = '<div class="aside-empty"><span class="aside-icon" aria-hidden="true">✦</span><h2>Your result appears here</h2><p>Fill in the fields. Everything is calculated in your browser and never uploaded.</p></div>';
  const todayISO = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };
  const currencyOptions = ['UGX', 'USD', 'EUR', 'GBP', 'KES'].map((c) => `<option>${c}</option>`).join('');
  const field = (id, label, input, hint) => `<div class="mb-3"><label class="form-label" for="${id}">${label}</label>${input}${hint ? `<small class="form-hint">${hint}</small>` : ''}</div>`;
  const copyBtn = (text, label) => `<button type="button" class="btn btn-outline-brand btn-sm" data-copy-text="${esc(text)}">${label || 'Copy result'}</button>`;

  const tools = {};

  tools['password-generator'] = {
    form: () => `
      <div class="form-section"><h2>Password options</h2>
      ${field('pw-length', 'Length: <output id="pw-length-out">16</output>', '<input class="form-range" type="range" id="pw-length" min="8" max="64" value="16">')}
      <div class="row g-2">
        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="pw-lower" checked><label class="form-check-label" for="pw-lower">Lowercase</label></div></div>
        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="pw-upper" checked><label class="form-check-label" for="pw-upper">Uppercase</label></div></div>
        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="pw-digits" checked><label class="form-check-label" for="pw-digits">Numbers</label></div></div>
        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="pw-symbols" checked><label class="form-check-label" for="pw-symbols">Symbols</label></div></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="pw-ambiguous"><label class="form-check-label" for="pw-ambiguous">Avoid look-alike characters (0/O, 1/l/I)</label></div></div>
      </div></div>
      <button class="btn btn-brand w-100" type="button" id="pw-go">Generate password</button>`,
    mount(app, show) {
      const len = $('#pw-length', app);
      len.addEventListener('input', () => { $('#pw-length-out', app).textContent = len.value; });
      const run = () => {
        try {
          const pw = logic.generatePassword({
            length: len.value, lower: $('#pw-lower', app).checked, upper: $('#pw-upper', app).checked,
            digits: $('#pw-digits', app).checked, symbols: $('#pw-symbols', app).checked, avoidAmbiguous: $('#pw-ambiguous', app).checked,
          });
          const s = logic.passwordStrength(pw);
          show(`<div class="result-card"><p class="eyebrow">Your password</p><p class="password-output" id="pw-value">${esc(pw)}</p>
            ${detail('Strength', `${s.label} (~${s.bits} bits)`)}
            <div class="mt-3 d-flex gap-2">${copyBtn(pw, 'Copy password')}<button type="button" class="btn btn-outline-brand btn-sm" id="pw-again">Generate another</button></div>
            <p class="mt-3 mb-0">Generated with your browser’s secure random number generator. It is not sent or stored anywhere.</p></div>`);
          $('#pw-again').addEventListener('click', run);
        } catch (e) { show(error(e)); }
      };
      $('#pw-go', app).addEventListener('click', run);
      run();
    },
  };

  const error = (e) => `<div class="alert alert-danger mb-0" role="alert">${esc(e.message || e)}</div>`;

  tools['age-calculator'] = {
    form: () => `<div class="form-section"><h2>Dates</h2>
      ${field('age-dob', 'Date of birth', '<input class="form-control" type="date" id="age-dob" max="' + todayISO() + '">')}
      ${field('age-on', 'Age at date', '<input class="form-control" type="date" id="age-on" value="' + todayISO() + '">')}</div>`,
    mount(app, show) {
      const run = () => {
        const dob = $('#age-dob', app).value;
        if (!dob) return show(placeholder);
        try {
          const a = logic.age(dob, $('#age-on', app).value);
          show(`<div class="result-card"><p class="eyebrow">Age</p><div class="result-number">${a.years}</div>
            <h2>${a.years} years, ${a.months} months, ${a.days} days</h2>
            ${detail('Total days lived', num(a.totalDays))}${detail('Total weeks', num(a.weeks))}
            ${detail('Born on a', a.weekday)}${detail('Next birthday', `${a.nextBirthday} (in ${num(a.daysToBirthday)} days)`)}</div>`);
        } catch (e) { show(error(e)); }
      };
      app.addEventListener('input', run);
      run();
    },
  };

  tools['date-calculator'] = {
    form: () => `<div class="form-section"><h2>Days between two dates</h2>
      ${field('dc-from', 'Start date', '<input class="form-control" type="date" id="dc-from" value="' + todayISO() + '">')}
      ${field('dc-to', 'End date', '<input class="form-control" type="date" id="dc-to">')}</div>`,
    mount(app, show) {
      const run = () => {
        if (!$('#dc-from', app).value || !$('#dc-to', app).value) return show(placeholder);
        try {
          const d = logic.dateDiff($('#dc-from', app).value, $('#dc-to', app).value);
          show(`<div class="result-card"><p class="eyebrow">${d.negative ? 'End date is earlier' : 'Difference'}</p><div class="result-number">${num(d.totalDays)}</div><h2>days</h2>
            ${detail('Years, months, days', `${d.years}y ${d.months}m ${d.days}d`)}${detail('Weeks', `${num(d.weeks)} weeks, ${d.totalDays % 7} days`)}</div>`);
        } catch (e) { show(error(e)); }
      };
      app.addEventListener('input', run);
      run();
    },
  };

  tools['loan-calculator'] = {
    form: () => `<div class="form-section"><h2>Loan details</h2>
      <div class="row g-3">
      <div class="col-sm-4">${field('ln-cur', 'Currency', '<select class="form-select" id="ln-cur">' + currencyOptions + '</select>')}</div>
      <div class="col-sm-8">${field('ln-amt', 'Loan amount', '<input class="form-control" type="number" id="ln-amt" min="1" step="any" value="10000000" inputmode="decimal">')}</div>
      <div class="col-6">${field('ln-rate', 'Interest rate (% per year)', '<input class="form-control" type="number" id="ln-rate" min="0" step="0.01" value="18" inputmode="decimal">')}</div>
      <div class="col-6">${field('ln-term', 'Term', '<div class="input-group"><input class="form-control" type="number" id="ln-term" min="1" step="1" value="24"><select class="form-select" id="ln-unit"><option value="m">months</option><option value="y">years</option></select></div>')}</div>
      </div></div>`,
    mount(app, show) {
      const run = () => {
        try {
          const unit = $('#ln-unit', app).value;
          const months = Number($('#ln-term', app).value) * (unit === 'y' ? 12 : 1);
          const cur = $('#ln-cur', app).value;
          const r = logic.loan($('#ln-amt', app).value, $('#ln-rate', app).value, months);
          const rows = r.schedule.slice(0, 360).map((x) => `<tr><td>${x.n}</td><td>${money(x.payment, cur)}</td><td>${money(x.principal, cur)}</td><td>${money(x.interest, cur)}</td><td>${money(x.balance, cur)}</td></tr>`).join('');
          const csv = 'Month,Payment,Principal,Interest,Balance\n' + r.schedule.map((x) => [x.n, x.payment, x.principal, x.interest, x.balance].map((v, i) => (i ? v.toFixed(2) : v)).join(',')).join('\n');
          show(`<div class="result-card"><p class="eyebrow">Monthly payment</p><div class="result-number result-number-sm">${money(r.payment, cur)}</div>
            ${detail('Total interest', money(r.interestTotal, cur))}${detail('Total repaid', money(r.total, cur))}
            <div class="table-responsive mt-3 schedule-wrap"><table class="table table-sm schedule-table"><thead><tr><th>#</th><th>Payment</th><th>Principal</th><th>Interest</th><th>Balance</th></tr></thead><tbody>${rows}</tbody></table></div>
            <div class="mt-3 d-flex gap-2 no-print"><button type="button" class="btn btn-outline-brand btn-sm" id="ln-csv">Download schedule (CSV)</button></div>
            <p class="mt-3 mb-0">Estimate using a standard fixed-rate, monthly-payment loan. Fees, insurance and lender rounding are not included.</p></div>`);
          $('#ln-csv').addEventListener('click', () => download('loan-schedule.csv', csv, 'text/csv'));
        } catch (e) { show(error(e)); }
      };
      app.addEventListener('input', run);
      run();
    },
  };

  tools['compound-interest-calculator'] = {
    form: () => `<div class="form-section"><h2>Investment details</h2>
      <div class="row g-3">
      <div class="col-sm-4">${field('ci-cur', 'Currency', '<select class="form-select" id="ci-cur">' + currencyOptions + '</select>')}</div>
      <div class="col-sm-8">${field('ci-p', 'Starting amount', '<input class="form-control" type="number" id="ci-p" min="0" step="any" value="1000000" inputmode="decimal">')}</div>
      <div class="col-6">${field('ci-m', 'Monthly contribution', '<input class="form-control" type="number" id="ci-m" min="0" step="any" value="100000" inputmode="decimal">')}</div>
      <div class="col-6">${field('ci-r', 'Annual return (%)', '<input class="form-control" type="number" id="ci-r" min="0" step="0.01" value="8" inputmode="decimal">')}</div>
      <div class="col-6">${field('ci-y', 'Years', '<input class="form-control" type="number" id="ci-y" min="1" max="100" step="1" value="10">')}</div>
      <div class="col-6">${field('ci-n', 'Compounding', '<select class="form-select" id="ci-n"><option value="12" selected>Monthly</option><option value="4">Quarterly</option><option value="2">Half-yearly</option><option value="1">Yearly</option><option value="365">Daily</option></select>')}</div>
      </div></div>`,
    mount(app, show) {
      const run = () => {
        try {
          const cur = $('#ci-cur', app).value;
          const r = logic.compound($('#ci-p', app).value, $('#ci-r', app).value, $('#ci-y', app).value, $('#ci-n', app).value, $('#ci-m', app).value);
          const max = Math.max(...r.yearly.map((y) => y.balance));
          const bars = r.yearly.map((y) => `<div class="budget-bar-row"><div><span>Year ${y.year}</span><strong>${money(y.balance, cur)}</strong></div><span class="budget-bar-track"><span style="width:${Math.max(2, Math.round((y.balance / max) * 100))}%"></span></span></div>`).join('');
          show(`<div class="result-card"><p class="eyebrow">Future value</p><div class="result-number result-number-sm">${money(r.balance, cur)}</div>
            ${detail('You contributed', money(r.contributed, cur))}${detail('Interest earned', money(r.interest, cur))}
            <div class="budget-bars mt-3">${bars}</div>
            <p class="mt-3 mb-0">Projection only. Real returns vary and are not guaranteed; inflation, fees and tax are not included.</p></div>`);
        } catch (e) { show(error(e)); }
      };
      app.addEventListener('input', run);
      run();
    },
  };

  tools['unit-converter'] = {
    form: () => `<div class="form-section"><h2>Convert</h2>
      ${field('uc-cat', 'Category', '<select class="form-select" id="uc-cat">' + Object.keys(logic.UNITS).map((c) => `<option>${c}</option>`).join('') + '</select>')}
      <div class="row g-3 align-items-end">
        <div class="col-12">${field('uc-val', 'Value', '<input class="form-control" type="number" id="uc-val" step="any" value="1" inputmode="decimal">')}</div>
        <div class="col-5">${field('uc-from', 'From', '<select class="form-select" id="uc-from"></select>')}</div>
        <div class="col-2 text-center"><button type="button" class="btn btn-outline-brand mb-3" id="uc-swap" aria-label="Swap units">⇄</button></div>
        <div class="col-5">${field('uc-to', 'To', '<select class="form-select" id="uc-to"></select>')}</div>
      </div></div>`,
    mount(app, show) {
      const cat = $('#uc-cat', app), from = $('#uc-from', app), to = $('#uc-to', app);
      const fill = () => {
        const names = Object.keys(logic.UNITS[cat.value].units);
        const opts = names.map((n) => `<option>${esc(n)}</option>`).join('');
        from.innerHTML = opts; to.innerHTML = opts;
        to.selectedIndex = Math.min(1, names.length - 1);
      };
      const run = () => {
        try {
          const out = logic.convertUnit(cat.value, from.value, to.value, $('#uc-val', app).value);
          const text = `${logic.formatNumber(Number($('#uc-val', app).value))} ${from.value} = ${logic.formatNumber(out)} ${to.value}`;
          show(`<div class="result-card"><p class="eyebrow">Result</p><div class="result-number result-number-sm">${esc(logic.formatNumber(out))}</div><h2>${esc(to.value)}</h2>${detail('Conversion', text)}<div class="mt-3">${copyBtn(logic.formatNumber(out))}</div></div>`);
        } catch (e) { show(error(e)); }
      };
      cat.addEventListener('change', () => { fill(); run(); });
      $('#uc-swap', app).addEventListener('click', () => { const t = from.value; from.value = to.value; to.value = t; run(); });
      app.addEventListener('input', run);
      fill(); run();
    },
  };

  tools['json-formatter'] = {
    wide: true,
    form: () => `<div class="form-section"><h2>JSON</h2>
      ${field('js-in', 'Paste JSON', '<textarea class="form-control code-input" id="js-in" rows="12" spellcheck="false" placeholder=\'{"name":"UtilityHub","tools":16}\'></textarea>')}
      <div class="row g-2 align-items-end"><div class="col-6">${field('js-indent', 'Format', '<select class="form-select" id="js-indent"><option value="2">2 spaces</option><option value="4">4 spaces</option><option value="tab">Tab</option><option value="min">Minify</option></select>')}</div>
      <div class="col-6"><button type="button" class="btn btn-brand w-100 mb-3" id="js-go">Format &amp; validate</button></div></div></div>`,
    mount(app, show) {
      const run = () => {
        const text = $('#js-in', app).value;
        if (!text.trim()) return show(placeholder);
        try {
          const out = logic.formatJson(text, $('#js-indent', app).value);
          show(`<div class="result-card"><p class="eyebrow">Valid JSON ✓</p><pre class="code-output" id="js-out">${esc(out)}</pre><div class="mt-3 d-flex gap-2">${copyBtn(out)}<button type="button" class="btn btn-outline-brand btn-sm" id="js-dl">Download .json</button></div></div>`);
          $('#js-dl').addEventListener('click', () => download('formatted.json', out, 'application/json'));
        } catch (e) { show(error('Invalid JSON: ' + e.message)); }
      };
      $('#js-go', app).addEventListener('click', run);
      $('#js-indent', app).addEventListener('change', run);
    },
  };

  tools['base64-encoder'] = {
    form: () => `<div class="form-section"><h2>Text</h2>
      ${field('b6-in', 'Input', '<textarea class="form-control code-input" id="b6-in" rows="8" spellcheck="false"></textarea>')}
      <div class="d-flex gap-2"><button type="button" class="btn btn-brand flex-fill" id="b6-enc">Encode</button><button type="button" class="btn btn-outline-brand flex-fill" id="b6-dec">Decode</button></div></div>`,
    mount(app, show) {
      const go = (fn, label) => () => {
        try {
          const out = fn($('#b6-in', app).value);
          show(`<div class="result-card"><p class="eyebrow">${label}</p><pre class="code-output" id="b6-out">${esc(out)}</pre><div class="mt-3">${copyBtn(out)}</div></div>`);
        } catch (e) { show(error(e)); }
      };
      $('#b6-enc', app).addEventListener('click', go(logic.base64Encode, 'Base64 output'));
      $('#b6-dec', app).addEventListener('click', go(logic.base64Decode, 'Decoded text'));
    },
  };

  tools['color-converter'] = {
    form: () => `<div class="form-section"><h2>Colour</h2>
      <div class="row g-3 align-items-end"><div class="col-8">${field('co-in', 'HEX, RGB or HSL', '<input class="form-control" id="co-in" value="#145B4A" autocomplete="off" spellcheck="false">')}</div>
      <div class="col-4">${field('co-pick', 'Pick', '<input class="form-control form-control-color w-100" type="color" id="co-pick" value="#145b4a">')}</div></div></div>`,
    mount(app, show) {
      const input = $('#co-in', app), pick = $('#co-pick', app);
      const run = () => {
        try {
          const rgb = logic.parseColor(input.value);
          const hex = logic.rgbToHex(rgb), hsl = logic.rgbToHsl(rgb), c = logic.contrast(rgb);
          const rgbS = `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`, hslS = `hsl(${hsl.h}, ${hsl.s}%, ${hsl.l}%)`;
          const grade = (r) => (r >= 7 ? 'AAA' : r >= 4.5 ? 'AA' : r >= 3 ? 'AA large text only' : 'Fails');
          show(`<div class="result-card"><div class="color-swatch" style="background:${hex}" role="img" aria-label="Colour preview ${hex}"></div>
            ${detail('HEX', hex)}${detail('RGB', rgbS)}${detail('HSL', hslS)}
            ${detail('White text contrast', `${c.white.toFixed(2)}:1 · ${grade(c.white)}`)}${detail('Black text contrast', `${c.black.toFixed(2)}:1 · ${grade(c.black)}`)}
            <div class="mt-3 d-flex gap-2 flex-wrap">${copyBtn(hex, 'Copy HEX')}${copyBtn(rgbS, 'Copy RGB')}${copyBtn(hslS, 'Copy HSL')}</div></div>`);
          if (document.activeElement !== pick) pick.value = hex.toLowerCase();
        } catch (e) { show(error(e)); }
      };
      input.addEventListener('input', run);
      pick.addEventListener('input', () => { input.value = pick.value.toUpperCase(); run(); });
      run();
    },
  };

  tools['text-case-converter'] = {
    form: () => `<div class="form-section"><h2>Your text</h2>
      ${field('tc-in', 'Type or paste text', '<textarea class="form-control" id="tc-in" rows="8"></textarea>')}
      <div class="d-flex flex-wrap gap-2" id="tc-buttons">${[['upper', 'UPPER'], ['lower', 'lower'], ['title', 'Title Case'], ['sentence', 'Sentence case'], ['camel', 'camelCase'], ['pascal', 'PascalCase'], ['snake', 'snake_case'], ['kebab', 'kebab-case'], ['slug', 'URL slug'], ['reverse', 'Reverse'], ['trim', 'Tidy spaces']].map(([m, l]) => `<button type="button" class="btn btn-outline-brand btn-sm" data-mode="${m}">${l}</button>`).join('')}</div></div>`,
    mount(app, show) {
      $('#tc-buttons', app).addEventListener('click', (e) => {
        const b = e.target.closest('[data-mode]');
        if (!b) return;
        const text = $('#tc-in', app).value;
        if (!text.trim()) return show(placeholder);
        const out = logic.convertCase(text, b.dataset.mode);
        show(`<div class="result-card"><p class="eyebrow">${esc(b.textContent)}</p><pre class="code-output" id="tc-out">${esc(out)}</pre><div class="mt-3">${copyBtn(out)}</div></div>`);
      });
    },
  };

  function download(name, content, type) {
    const url = URL.createObjectURL(new Blob([content], { type: type + ';charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function boot() {
    const host = document.querySelector('[data-client-tool]');
    if (!host) return;
    const tool = tools[host.dataset.clientTool];
    const out = document.getElementById('client-tool-result');
    if (!tool || !out) return;
    host.innerHTML = tool.form();
    const show = (html) => { out.innerHTML = html; };
    show(placeholder);
    tool.mount(host, show);
  }

  const api = { logic, tools };
  root.UH = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
  }
})(typeof window !== 'undefined' ? window : globalThis);
