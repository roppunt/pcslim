<?php

if (!function_exists('pcslim_get_pdo')) {
    function pcslim_get_pdo(): PDO
    {
        static $pdoInstance = null;

        if ($pdoInstance instanceof PDO) {
            return $pdoInstance;
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdoInstance = $GLOBALS['pdo'];
            return $pdoInstance;
        }

        require_once __DIR__ . '/config.php';
        global $pdo, $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

        if ($pdo instanceof PDO) {
            $pdoInstance = $pdo;
            return $pdoInstance;
        }

        if (isset($DB_HOST, $DB_NAME, $DB_USER, $DB_PASS)) {
            $pdoInstance = new PDO(
                "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
                $DB_USER,
                $DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            return $pdoInstance;
        }

        throw new RuntimeException('Databaseconfiguratie ontbreekt voor pcslim_get_pdo');
    }
}

if (!function_exists('lookup_model')) {
    /**
     * Zoek model op merk+model met fuzzy LIKE; kiest beste match.
     * Verwacht tabel 'pc_models' of 'models' met minimaal kolommen:
     *  brand, model, year, win11_supported, max_ram_gb (of max_ram)
     */
    function lookup_model(string $brand, string $model): ?array
    {
        $pdo = pcslim_get_pdo();

        // Detecteer tabelnaam dynamisch
        $table = null;
        foreach (['pc_models', 'models'] as $t) {
            $q = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t));
            if ($q && $q->fetchColumn()) {
                $table = $t;
                break;
            }
        }
        if (!$table) {
            return null;
        }

        $brandLike = '%' . preg_replace('/\s+/', '%', $brand) . '%';
        $modelLike = '%' . preg_replace('/\s+/', '%', $model) . '%';

        // Probeer relevante kolomnamen te mappen
        $cols = [
            'brand' => 'brand',
            'model' => 'model',
            'year'  => 'year',
            'win11' => 'win11_supported',
            'maxram' => 'max_ram_gb',
        ];

        // Haal kolommen op en map ze (voor het geval jouw schema andere namen gebruikt)
        $desc = $pdo->query("DESCRIBE {$table}")->fetchAll();
        $available = array_column($desc, 'Field');

        // fallback aliasing
        if (!in_array('win11_supported', $available, true)) {
            if (in_array('win11', $available, true)) {
                $cols['win11'] = 'win11';
            } elseif (in_array('w11', $available, true)) {
                $cols['win11'] = 'w11';
            }
        }
        if (!in_array('max_ram_gb', $available, true)) {
            if (in_array('max_ram', $available, true)) {
                $cols['maxram'] = 'max_ram';
            } elseif (in_array('ram_max', $available, true)) {
                $cols['maxram'] = 'ram_max';
            }
        }

        // Score-based match
        $sql = "SELECT
              {$cols['brand']} AS brand,
              {$cols['model']} AS model,
              " . (in_array($cols['year'], $available, true) ? "{$cols['year']} AS year," : "NULL AS year,") . "
              " . (in_array($cols['win11'], $available, true) ? "{$cols['win11']} AS win11_supported," : "0 AS win11_supported,") . "
              " . (in_array($cols['maxram'], $available, true) ? "{$cols['maxram']} AS max_ram," : "NULL AS max_ram,") . "
              (
                (CASE WHEN {$cols['brand']} = :brand_exact THEN 2 ELSE 0 END) +
                (CASE WHEN {$cols['model']} = :model_exact THEN 2 ELSE 0 END) +
                (CASE WHEN {$cols['brand']} LIKE :brand_like THEN 1 ELSE 0 END) +
                (CASE WHEN {$cols['model']} LIKE :model_like THEN 1 ELSE 0 END)
              ) AS score
            FROM {$table}
            WHERE {$cols['brand']} LIKE :brand_like2
              AND {$cols['model']} LIKE :model_like2
            ORDER BY score DESC, win11_supported DESC, " . (in_array($cols['year'], $available, true) ? 'year DESC' : 'score DESC') . "
            LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':brand_exact' => $brand,
            ':model_exact' => $model,
            ':brand_like'  => $brandLike,
            ':model_like'  => $modelLike,
            ':brand_like2' => $brandLike,
            ':model_like2' => $modelLike,
        ]);
        $row = $st->fetch();
        return $row ?: null;
    }
}

$shouldHandleHttp = false;
if (PHP_SAPI !== 'cli') {
    if (isset($_SERVER['SCRIPT_FILENAME'])) {
        $shouldHandleHttp = realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__;
    } elseif (empty(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))) {
        $shouldHandleHttp = true;
    }
}

if ($shouldHandleHttp) {
    require __DIR__ . '/config.php';

    $brand = isset($_GET['brand']) ? trim((string) $_GET['brand']) : '';

    if ($brand !== '') {
        $stmt = $pdo->prepare('SELECT * FROM models WHERE active=1 AND brand=? ORDER BY display_model ASC');
        $stmt->execute([$brand]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM models WHERE active=1 ORDER BY brand ASC, display_model ASC')->fetchAll();
    }

    json_out(['ok' => true, 'models' => $rows]);
}
