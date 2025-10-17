<?php
require_once __DIR__.'/guard.php';
require_once __DIR__.'/../api/config.php';

$error = null;
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = isset($_POST['key']) ? trim($_POST['key']) : '';
    $price = isset($_POST['price']) ? trim($_POST['price']) : '';
    if ($key !== '' && $price !== '') {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            $error = 'Ongeldige sleutel (alleen kleine letters, cijfers en underscore)';
        } else {
            // Convert price to float (euro), replace comma with dot
            $value = floatval(str_replace(',', '.', $price));
            try {
                $stmt = $pdo->prepare("INSERT INTO price_matrix (`key`, price_eur) VALUES (:key, :price) ON DUPLICATE KEY UPDATE price_eur = VALUES(price_eur)");
                $stmt->execute([':key'=>$key, ':price'=>$value]);
                $success = 'Prijs opgeslagen';
            } catch (Exception $e) {
                $error = 'Kon prijs niet opslaan: '.$e->getMessage();
            }
        }
    } else {
        $error = 'Vul zowel sleutel als prijs in.';
    }
}

$rows = [];
try {
    $stmt = $pdo->query("SELECT `key`, price_eur FROM price_matrix ORDER BY `key` ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Kan price_matrix niet lezen: '.$e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<title>Prijsbeheer</title>
<style>
table { border-collapse: collapse; }
table th, table td { border: 1px solid #ccc; padding: 6px 8px; }
</style>
</head>
<body>
<h1>Prijsbeheer</h1>
<?php if ($error): ?>
<p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if ($success): ?>
<p style="color:green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<table>
<thead>
<tr><th>Sleutel</th><th>Prijs (€)</th><th>Actie</th></tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <form method="post">
    <td><input type="text" name="key" value="<?php echo htmlspecialchars($r['key']); ?>" readonly></td>
    <td><input type="text" name="price" value="<?php echo number_format($r['price_eur'], 2, ',', ''); ?>"></td>
    <td><button type="submit">Opslaan</button></td>
    </form>
</tr>
<?php endforeach; ?>
<tr>
    <form method="post">
    <td><input type="text" name="key" placeholder="nieuw_key"></td>
    <td><input type="text" name="price" placeholder="0.00"></td>
    <td><button type="submit">Toevoegen</button></td>
    </form>
</tr>
</tbody>
</table>
</body>
</html>
