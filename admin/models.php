<?php require __DIR__.'/guard.php'; ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Modellen beheren</title>
  <style>
    table { border-collapse: collapse; width: 100%; }
    td, th { border: 1px solid #ccc; padding: 4px; }
    input[type=text] { width: 100%; }
  </style>
</head>
<body>
  <h1>Modellen beheren</h1>
  Zoek, bewerk of voeg nieuwe modellen toe. ← <a href="prijzen.php">prijzen</a> · <a href="import_models.php">CSV import</a> · <a href="logout.php">Uitloggen</a>
  <br><br>
  <form id="searchForm">
    Merk: <input type="text" name="brand" placeholder="bv. Lenovo">
    Zoek: <input type="text" name="q" placeholder="model/notes">
    <button type="submit">Zoeken</button>
    <button type="button" onclick="resetSearch()">Reset</button>
  </form>
  <br>
  <table id="modelsTable">
    <thead>
      <tr>
        <th>ID</th><th>Merk</th><th>Model</th><th>Regex (JSON)</th><th>Max RAM</th><th>W11</th><th>Opslag</th><th>CPU-arch</th><th>Opmerkingen</th><th>Actief</th><th>Opslaan</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>

  <script>
    const tableBody = document.querySelector("#modelsTable tbody");
    const searchForm = document.getElementById("searchForm");

    window.resetSearch = () => { searchForm.reset(); loadModels(); };

    function formatModelRegex(value) {
      if (value == null || value === '') return '';
      if (Array.isArray(value)) return JSON.stringify(value);
      if (typeof value === 'string') {
        try {
          const parsed = JSON.parse(value);
          if (Array.isArray(parsed)) {
            return JSON.stringify(parsed);
          }
        } catch (e) {
          // val terug op oorspronkelijke string
        }
        return value;
      }
      return JSON.stringify(value);
    }

    function normalizeSupportsValue(value) {
      if (value == null || value === '') return '';
      const str = String(value);
      if (str === '1' || str === 'true') return '1';
      if (str === '0' || str === 'false') return '0';
      return '';
    }

    function makeTextCell(text) {
      const td = document.createElement('td');
      td.textContent = text ?? '';
      return td;
    }

    function makeInputCell(value, field, options = {}) {
      const td = document.createElement('td');
      const input = document.createElement('input');
      input.type = options.type || 'text';
      input.value = value ?? '';
      input.dataset.field = field;
      if (options.placeholder) input.placeholder = options.placeholder;
      if (options.defaultValue && !input.value) input.value = options.defaultValue;
      td.appendChild(input);
      return td;
    }

    function makeSelectCell(value, field) {
      const td = document.createElement('td');
      const select = document.createElement('select');
      select.dataset.field = field;
      const options = [
        { value: '', label: '?' },
        { value: '1', label: 'Ja' },
        { value: '0', label: 'Nee' }
      ];
      options.forEach(opt => {
        const optionEl = document.createElement('option');
        optionEl.value = opt.value;
        optionEl.textContent = opt.label;
        select.appendChild(optionEl);
      });
      select.value = normalizeSupportsValue(value);
      td.appendChild(select);
      return td;
    }

    function makeCheckboxCell(checked, field) {
      const td = document.createElement('td');
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.dataset.field = field;
      input.checked = Boolean(checked);
      td.appendChild(input);
      return td;
    }

    function makeButtonCell(label, onClick) {
      const td = document.createElement('td');
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      button.addEventListener('click', onClick);
      td.appendChild(button);
      return td;
    }

    async function loadModels() {
      const params = new URLSearchParams(new FormData(searchForm));
      try {
        const res = await fetch('../api/models_admin.php?' + params.toString(), { credentials: 'include' });
        if (!res.ok) {
          tableBody.innerHTML = `<tr><td colspan="11">Mislukt: ${res.status}</td></tr>`;
          return;
        }
        const data = await res.json();
        if (!data.ok) {
          tableBody.innerHTML = `<tr><td colspan="11">Fout: ${data.error || 'onbekend'}</td></tr>`;
          return;
        }
        renderRows(data.models);
      } catch (err) {
        tableBody.innerHTML = `<tr><td colspan="11">Fout: ${err.message}</td></tr>`;
      }
    }

    function collectRowData(tr) {
      const field = name => tr.querySelector(`[data-field="${name}"]`);
      const brandEl = field('brand');
      const modelEl = field('display_model');
      const regexEl = field('model_regex');
      const maxRamEl = field('max_ram_gb');
      const supportsEl = field('supports_w11');
      const storageEl = field('storage');
      const cpuEl = field('cpu_arch');
      const notesEl = field('notes');
      const activeEl = field('active');

      const brand = brandEl.value.trim();
      const displayModel = modelEl.value.trim();
      if (!brand) {
        alert('Merk is verplicht.');
        brandEl.focus();
        return null;
      }
      if (!displayModel) {
        alert('Model is verplicht.');
        modelEl.focus();
        return null;
      }

      const regexRaw = regexEl.value.trim();
      let modelRegex = null;
      if (regexRaw !== '') {
        try {
          const parsed = JSON.parse(regexRaw);
          if (!Array.isArray(parsed)) {
            throw new Error('Model regex moet een JSON-array zijn.');
          }
          modelRegex = parsed;
        } catch (err) {
          alert('model_regex moet een geldige JSON-array zijn.');
          regexEl.focus();
          return null;
        }
      }

      const maxRamRaw = maxRamEl.value.trim();
      let maxRam = null;
      if (maxRamRaw !== '') {
        const parsed = Number(maxRamRaw);
        if (!Number.isFinite(parsed)) {
          alert('Max RAM moet een getal zijn.');
          maxRamEl.focus();
          return null;
        }
        maxRam = Math.round(parsed);
      }

      const supportsValue = supportsEl.value;
      let supports = null;
      if (supportsValue === '1' || supportsValue === '0') {
        supports = Number(supportsValue);
      }

      const storage = storageEl.value.trim();
      const cpuArch = cpuEl.value.trim();
      const notes = notesEl.value.trim();

      return {
        brand,
        display_model: displayModel,
        model_regex: modelRegex,
        max_ram_gb: maxRam,
        supports_w11: supports,
        storage: storage === '' ? null : storage,
        cpu_arch: cpuArch === '' ? null : cpuArch,
        notes: notes === '' ? null : notes,
        active: activeEl.checked
      };
    }

    async function saveRow(id, tr) {
      const payload = collectRowData(tr);
      if (!payload) {
        return;
      }

      const opts = {
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      };

      try {
        let res;
        if (id) {
          const url = new URL('../api/models_admin.php?id=' + id, location.href);
          res = await fetch(url, { ...opts, method: 'PUT' });
        } else {
          res = await fetch('../api/models_admin.php', { ...opts, method: 'POST' });
        }
        const text = await res.text();
        let data;
        try {
          data = JSON.parse(text);
        } catch (err) {
          alert('Onverwachte response: ' + text);
          return;
        }
        if (!res.ok || !data.ok) {
          alert('Fout: ' + (data.error || res.status));
          return;
        }
        alert(id ? 'Model bijgewerkt.' : 'Model toegevoegd.');
        loadModels();
      } catch (err) {
        alert('Request mislukt: ' + err.message);
      }
    }

    function renderRows(models) {
      tableBody.innerHTML = '';
      for (const m of models) {
        const tr = document.createElement('tr');
        tr.dataset.id = m.id;
        tr.appendChild(makeTextCell(m.id));
        tr.appendChild(makeInputCell(m.brand || '', 'brand'));
        tr.appendChild(makeInputCell(m.display_model || '', 'display_model'));
        tr.appendChild(makeInputCell(formatModelRegex(m.model_regex), 'model_regex', { placeholder: '["regex"]' }));
        tr.appendChild(makeInputCell(m.max_ram_gb != null ? m.max_ram_gb : '', 'max_ram_gb'));
        tr.appendChild(makeSelectCell(m.supports_w11, 'supports_w11'));
        tr.appendChild(makeInputCell(m.storage || '', 'storage'));
        tr.appendChild(makeInputCell(m.cpu_arch || '', 'cpu_arch'));
        tr.appendChild(makeInputCell(m.notes || '', 'notes'));
        tr.appendChild(makeCheckboxCell(m.active, 'active'));
        tr.appendChild(makeButtonCell('Opslaan', () => saveRow(m.id, tr)));
        tableBody.appendChild(tr);
      }

      const newRow = document.createElement('tr');
      newRow.appendChild(makeTextCell(''));
      newRow.appendChild(makeInputCell('', 'brand'));
      newRow.appendChild(makeInputCell('', 'display_model', { placeholder: 'display_model' }));
      newRow.appendChild(makeInputCell('', 'model_regex', { placeholder: '["regex"]' }));
      newRow.appendChild(makeInputCell('', 'max_ram_gb'));
      newRow.appendChild(makeSelectCell('', 'supports_w11'));
      newRow.appendChild(makeInputCell('', 'storage', { placeholder: 'Opslag' }));
      newRow.appendChild(makeInputCell('x86-64', 'cpu_arch', { defaultValue: 'x86-64' }));
      newRow.appendChild(makeInputCell('', 'notes'));
      newRow.appendChild(makeCheckboxCell(true, 'active'));
      newRow.appendChild(makeButtonCell('Toevoegen', () => saveRow(null, newRow)));
      tableBody.appendChild(newRow);
    }

    searchForm.addEventListener('submit', ev => { ev.preventDefault(); loadModels(); });
    loadModels();
  </script>
</body>
</html>
