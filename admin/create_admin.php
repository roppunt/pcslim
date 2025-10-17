<?php
require __DIR__.'/../api/config.php';
// Stel je admin aan:
$email = 'robert.nillesen@gmail.com';
$pass  = 'Seh39nT2';
$hash  = password_hash($pass, PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (email, pass_hash) VALUES (?, ?)")->execute([$email, $hash]);
echo "Admin aangemaakt: $email\nVerwijder dit bestand nu aub.";
