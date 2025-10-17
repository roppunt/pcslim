<?php
require __DIR__.'/../api/config.php';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = trim($_POST['password'] ?? '');

  $stmt = $pdo->prepare("SELECT id, pass_hash FROM users WHERE email=?");
  $stmt->execute([$email]);
  $u = $stmt->fetch();
  if ($u && password_verify($pass, $u['pass_hash'])) {
    $_SESSION['uid'] = (int)$u['id'];
    $adminBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // bv. /upgradekeuze/admin
header('Location: ' . $adminBase . '/'); 
exit;

  }
  $err = "Onjuist e-mailadres of wachtwoord";
}
?>
<!doctype html><html lang="nl"><meta charset="utf-8">
<title>Admin Login</title>
<style>body{font-family:sans-serif;max-width:420px;margin:40px auto}</style>
<h1>Admin login</h1>
<?php if(!empty($err)) echo "<p style='color:#b00;'>$err</p>"; ?>
<form method="post">
  <label>E-mail<br><input name="email" type="email" required></label><br><br>
  <label>Wachtwoord<br><input name="password" type="password" required></label><br><br>
  <button>Inloggen</button>
</form>
