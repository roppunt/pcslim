<?php require __DIR__.'/guard.php'; ?>
<?php
$rows = $pdo->query("SELECT pkey, value_cents, description FROM prices ORDER BY pkey")->fetchAll();
?>
<!doctype html><html lang="nl"><meta charset="utf-8">
<title>Admin � Prijzen</title>
<style>
body{font-family:sans-serif;max-width:760px;margin:40px auto}
table{border-collapse:collapse;width:100%}
td,th{border:1px solid #ddd;padding:8px}
input[type=number]{width:120px}
</style>
<h1>Prijzen beheren</h1>
<p><a href="/admin/logout.php">Uitloggen</a></p>

<table>
  <tr><th>Sleutel</th><th>Beschrijving</th><th>Waarde (cent)</th><th>Opslaan</th></tr>
  <?php foreach($rows as $r): ?>
  <tr>
    <td><?=htmlspecialchars($r['pkey'])?></td>
    <td><?=htmlspecialchars($r['description'] ?? '')?></td>
    <td><input type="number" min="0" step="1" value="<?= (int)$r['value_cents'] ?>" id="v-<?=htmlspecialchars($r['pkey'])?>"></td>
    <td><button onclick="savePrice('<?=htmlspecialchars($r['pkey'])?>')">Opslaan</button></td>
  </tr>
  <?php endforeach; ?>
</table>

<script>
async function savePrice(pkey){
  const val = document.getElementById('v-'+pkey).value;
  const res = await fetch('/api/prices.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({pkey, value_cents: Number(val)})
  });
  const json = await res.json();
  alert(json.ok ? 'Opgeslagen' : ('Mislukt: '+(json.error||res.status)));
}
</script>
