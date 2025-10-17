<?php
require __DIR__.'/../api/config.php'; // moet session_start() doen!

// Bepaal de admin-basis-url dynamisch, bv. "/upgradekeuze/admin"
$adminBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

if (empty($_SESSION['uid'])) {
  header('Location: ' . $adminBase . '/login.php');
  exit;
}
