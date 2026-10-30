(() => {
  'use strict';

  const body = document.body;
  const themeToggle = document.getElementById('theme-toggle');

  try {
    const savedTheme = localStorage.getItem('utilityhub-theme');
    if (savedTheme === 'dark' || savedTheme === 'light') body.dataset.theme = savedTheme;
  } catch (_) {
    // The tools remain usable if browser storage is disabled.
  }
  themeToggle?.addEventListener('click', () => {
    const nextTheme = body.dataset.theme === 'dark' ? 'light' : 'dark';
    body.dataset.theme = nextTheme;
    try { localStorage.setItem('utilityhub-theme', nextTheme); } catch (_) {}
  });

  // Tool directory search is entirely client-side.
  const search = document.getElementById('tool-search');
  const noTools = document.getElementById('no-tools-found');
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('.tool-card-wrap').forEach((card) => {
      const match = !query || card.dataset.toolName?.includes(query);
      card.classList.toggle('d-none', !match);
      if (match) visible++;
    });
    noTools?.classList.toggle('d-none', visible > 0);
  });
  if (search?.value) search.dispatchEvent(new Event('input'));

  const qrType = document.getElementById('qr-type');
  const syncQrType = () => {
    const isWifi = qrType?.value === 'wifi';
    document.getElementById('qr-general-fields')?.classList.toggle('d-none', isWifi);
    document.getElementById('qr-wifi-fields')?.classList.toggle('d-none', !isWifi);
  };
  qrType?.addEventListener('change', syncQrType);
  syncQrType();

  const addRow = (container, kind) => {
    if (!container || container.children.length >= (kind === 'invoice' ? 50 : 30)) return;
    const row = document.createElement('div');
    if (kind === 'invoice') {
      row.className = 'invoice-row';
      row.innerHTML = '<div class="row g-2 align-items-end"><div class="col-12 col-md-5"><label class="form-label">Description<input class="form-control" name="description[]" maxlength="240"></label></div><div class="col-4 col-md-2"><label class="form-label">Qty<input class="form-control" type="number" name="quantity[]" min="0.01" max="1000000" step="0.01" value="1"></label></div><div class="col-6 col-md-3"><label class="form-label">Unit price<input class="form-control" type="number" name="rate[]" min="0" step="0.01"></label></div><div class="col-2 col-md-2"><button class="btn btn-remove-row" type="button" aria-label="Remove item">×</button></div></div>';
    } else {
      row.className = 'expense-row row g-2 align-items-end mb-2';
      row.innerHTML = '<div class="col-7"><label class="form-label">Expense<input class="form-control" name="expense_name[]" maxlength="80"></label></div><div class="col-4"><label class="form-label">Amount<input class="form-control" name="expense_amount[]" type="number" min="0" step="0.01"></label></div><div class="col-1"><button type="button" class="btn btn-remove-row remove-expense-row" aria-label="Remove expense">×</button></div>';
    }
    container.appendChild(row);
    row.querySelector('input')?.focus();
  };

  document.getElementById('add-invoice-row')?.addEventListener('click', () => {
    addRow(document.getElementById('invoice-rows'), 'invoice');
    syncCurrencyPrecision();
  });
  document.getElementById('add-expense-row')?.addEventListener('click', () => {
    addRow(document.getElementById('expense-rows'), 'expense');
    syncCurrencyPrecision();
  });
  document.addEventListener('click', (event) => {
    const remove = event.target.closest('.btn-remove-row');
    if (!remove) return;
    const row = remove.closest('.invoice-row, .expense-row');
    const container = row?.parentElement;
    if (!row || !container) return;
    if (container.children.length <= 1) {
      row.querySelectorAll('input').forEach((input) => {
        input.value = input.name === 'quantity[]' ? '1' : '';
      });
    } else {
      row.remove();
    }
  });

  const discountType = document.getElementById('discount-type');
  const syncDiscount = () => {
    const fixed = discountType?.value === 'fixed';
    document.getElementById('discount-percent-wrap')?.classList.toggle('d-none', fixed);
    document.getElementById('discount-fixed-wrap')?.classList.toggle('d-none', !fixed);
  };
  discountType?.addEventListener('change', syncDiscount);
  syncDiscount();

  const currency = document.getElementById('currency');
  const syncCurrencyPrecision = () => {
    const step = currency?.value === 'UGX' ? '1' : '0.01';
    document.querySelectorAll('input[name="rate[]"], input[name="discount_amount"], input[name="income"], input[name="deductions"], input[name="expense_amount[]"]').forEach((input) => {
      input.step = step;
    });
  };
  currency?.addEventListener('change', syncCurrencyPrecision);
  syncCurrencyPrecision();

  const bmiUnit = document.getElementById('bmi-unit');
  const syncBmi = () => {
    const imperial = bmiUnit?.value === 'imperial';
    document.getElementById('bmi-metric')?.classList.toggle('d-none', imperial);
    document.getElementById('bmi-imperial')?.classList.toggle('d-none', !imperial);
  };
  bmiUnit?.addEventListener('change', syncBmi);
  syncBmi();

  const percentType = document.getElementById('calculation-type');
  const syncPercentageLabels = () => {
    const a = document.querySelector('.value-a-label');
    const b = document.querySelector('.value-b-label');
    if (!a || !b) return;
    const labels = {
      of: ['Number', 'Percentage (%)'],
      change: ['Original value', 'New value'],
      difference: ['First value', 'Second value'],
      reverse: ['Result value', 'Percentage (%)'],
      discount: ['Original price', 'Discount (%)']
    };
    const pair = labels[percentType.value] || labels.of;
    a.textContent = pair[0];
    b.textContent = pair[1];
  };
  percentType?.addEventListener('change', syncPercentageLabels);
  syncPercentageLabels();

  // Text is never submitted to PHP. Counting, sentence parsing, and reading
  // time all run locally in the visitor's browser.
  const textArea = document.getElementById('counter-text');
  const updateCounts = () => {
    if (!textArea) return;
    const value = textArea.value;
    const trimmed = value.trim();
    const words = trimmed ? trimmed.match(/[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/gu) || [] : [];
    const sentences = trimmed ? (trimmed.match(/[^.!?]+[.!?]+|[^.!?]+$/g) || []).filter((sentence) => sentence.trim() !== '') : [];
    const paragraphs = trimmed ? trimmed.split(/\n\s*\n/u).filter((paragraph) => paragraph.trim() !== '') : [];
    document.getElementById('count-words').textContent = words.length.toLocaleString();
    document.getElementById('count-chars').textContent = value.length.toLocaleString();
    document.getElementById('count-chars-no-space').textContent = value.replace(/\s/gu, '').length.toLocaleString();
    document.getElementById('count-sentences').textContent = sentences.length.toLocaleString();
    document.getElementById('count-paragraphs').textContent = paragraphs.length.toLocaleString();
    document.getElementById('count-reading').textContent = `${words.length ? Math.max(1, Math.ceil(words.length / 200)) : 0} min`;
  };
  textArea?.addEventListener('input', updateCounts);
  document.getElementById('clear-text')?.addEventListener('click', () => {
    if (!textArea) return;
    textArea.value = '';
    updateCounts();
    textArea.focus();
  });

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-text]');
    if (!button) return;
    const text = button.dataset.copyText || '';
    try {
      await navigator.clipboard.writeText(text);
      const original = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(() => { button.textContent = original; }, 1400);
    } catch (_) {
      button.textContent = 'Copy unavailable in this browser';
    }
  });

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-download-csv]');
    if (!button) return;
    try {
      const base64 = (button.dataset.downloadCsv || '').replace(/-/g, '+').replace(/_/g, '/');
      const bytes = Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
      const url = URL.createObjectURL(new Blob([bytes], { type: 'text/csv;charset=utf-8' }));
      const link = document.createElement('a');
      link.href = url;
      link.download = button.dataset.filename || 'download.csv';
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (_) {
      button.textContent = 'Download unavailable';
    }
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-print]')) return;
    body.classList.add('printing');
    window.setTimeout(() => window.print(), 80);
  });
  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm || 'Continue?')) event.preventDefault();
  });
  window.addEventListener('afterprint', () => body.classList.remove('printing'));
  if (body.dataset.autoprint === 'true') {
    window.addEventListener('load', () => window.setTimeout(() => window.print(), 300));
  }
})();
