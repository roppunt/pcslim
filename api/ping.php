<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'where'=>'/api/ping.php']);
