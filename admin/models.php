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
  Zoek, bewerk of voeg nieuwe modellen toe. ← <a href="prijzen.php">prijzen</a> · <a href="csv_import.php">CSV import</a> · <a href="logout.php">Uitloggen</a>
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

    async function loadModels() {
      const params = new URLSearchParams(new FormData(searchForm));
      const res = await fetch('../api/models_admin.php?' + params.toString(), { credentials: 'include' });
      if (!res.ok) {
        tableBody.innerHTML = "<tr><td colspan='11'>Mislukt: " + res.status + "</td></tr>";
        return;
      }
      const data = await res.json();
      if (!data.ok) {
        tableBody.innerHTML = "<tr><td colspan='11'>Fout: " + (data.error || "onbekend") + "</td></tr>";
        return;
      }
      renderRows(data.models);
    }

    function renderRows(models) {
      tableBody.innerHTML = "";
      for (const m of models) {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${m.id}</td>
          <td><input type="text" value="${m.brand||""}"></td>
          <td><input type="text" value="${m.display_model||""}"></td>
          <td><input type="text" value='${JSON.stringify(m.model_regex)||""}'></td>
          <td><input type="text" value="${m.max_ram_gb||""}"></td>
          <td>
            <select>
              <option value="">?</option>
              <option value="1" ${m.supports_w11=="1"?"selected":""}>Ja</option>
              <option value="0" ${m.supports_w11=="0"?"selected":""}>Nee</option>
            </select>
          </td>
          <td><input type="text" value="${m.storage||""}"></td>
          <td><input type="text" value="${m.cpu_arch||""}"></td>
          <td><input type="text" value="${m.notes||""}"></td>
          <td><input type="checkbox" ${m.active?"checked":""}></td>
          <td><button>Opslaan</button></td>
        `;
        tr.querySelector("button").onclick = () => saveRow(m.id, tr);
        tableBody.appendChild(tr);
      }

      // lege rij voor nieuw model
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td></td>
        <td><input type="text"></td>
        <td><input type="text" placeholder="display_model"></td>
        <td><input type="text" placeholder='["regex"]'></td>
        <td><input type="text"></td>
        <td>
          <select>
            <option value="">?</option>
            <option value="1">Ja</option>
            <option value="0">Nee</option>
          </select>
        </td>
        <td><input type="text" placeholder="Opslag"></td>
        <td><input type="text" value="x86-64"></td>
        <td><input type="text"></td>
        <td><input type="checkbox" checked></td>
        <td><button>Toevoegen</button></td>
      `;
      tr.querySelector("button").onclick = () => saveRow(null, tr);
      tableBody.appendChild(tr);
    }

    async function saveRow(id, tr) {
      const [brand, display_model, model_regex, max_ram_gb, w11, storage, cpu_arch, notes, active] =
        Array.from(tr.querySelectorAll("input,select")).map(el => el.type==="checkbox" ? el.checked : el.value);

      const body = JSON.stringify({ brand, display_model, model_regex, max_ram_gb, supports_w11:w11, storage, cpu_arch, notes, active });

      if (id) {
        const url = new URL('../api/models_admin.php?id='+id, location.href);
        const res = await fetch(url, { method:'PUT', credentials:'include', headers:{'Content-Type':'application/json'}, body });
        alert(await res.text());
      } else {
        const res = await fetch('../api/models_admin.php', { method:'POST', credentials:'include', headers:{'Content-Type':'application/json'}, body });
        alert(await res.text());
      }
      loadModels();
    }

    searchForm.onsubmit = ev => { ev.preventDefault(); loadModels(); };
    function resetSearch() { searchForm.reset(); loadModels(); }
    loadModels();
  </script>
</body>
</html>
