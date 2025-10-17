<?php
require __DIR__.'/../api/config.php';
session_destroy();
header('Location: /admin/login.php');
