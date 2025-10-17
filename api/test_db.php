echo "<?php
try {
  new PDO('mysql:host=pcslim-db;dbname=pcslimupgradekeuze;charset=utf8mb4','root','');
  echo 'OK';
} catch (Throwable \$e) { echo 'ERR: '.\$e->getMessage(); }" \
> /var/www/html/test_db.php
