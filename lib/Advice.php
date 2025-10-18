<?php
/**
 * PC-Slim advieslogica (runtime).
 *
 * Eén kolom als oordeel: `supports_w11`
 *  1  = ja
 * -1  = na RAM-upgrade haalbaar
 *  0  = nee
 * null= onbekend / nog niet beoordeeld (onvolledige gegevens)
 *
 * Criteria (PC-Slim):
 *  - CPU: Intel 8e gen of hoger, of AMD Ryzen 2e gen of hoger
 *  - TPM: 'yes'
 *  - Secure Boot: 'yes'
 *  - RAM: >= 8 GB
 *  - Als alleen RAM tekort is en ram_max_gb >= 8 → na RAM-upgrade haalbaar
 *
 * Output (array):
 *  [
 *    'pcslim_label'         => 'ja' | 'na_ram_upgrade' | 'nee',
 *    'requires_ram_upgrade' => 0|1,
 *    'supports_w11_value'   => 1 | -1 | 0 | null,   // te schrijven naar DB
 *    'advice_text'          => '... Nederlands advies ...'
 *  ]
 */

final class Advice
{
    public static function build(array $row): array
    {
        // Kernsignalen
        $cpuOk = self::cpuOk(
            (string)($row['cpu_brand']  ?? ''),
            (string)($row['cpu_family'] ?? ''),
            isset($row['cpu_gen']) ? (int)$row['cpu_gen'] : null
        );

        $tpmOk = self::yn((string)($row['tpm'] ?? ''));
        $sbOk  = self::yn((string)($row['uefi_secureboot'] ?? ''));

        $ramInstalled = isset($row['ram_installed_gb']) ? (int)$row['ram_installed_gb'] : null;
        $ramMax       = isset($row['ram_max_gb'])       ? (int)$row['ram_max_gb']       : null;
        $minRam       = 8;

        $missing = [];
        if ($cpuOk === null)        $missing[] = 'CPU-generatie';
        if ($tpmOk === null)        $missing[] = 'TPM';
        if ($sbOk === null)         $missing[] = 'Secure Boot';
        if ($ramInstalled === null) $missing[] = 'RAM';

        $hardNo = [];
        if ($cpuOk === false) $hardNo[] = 'CPU-generatie te oud (min. Intel 8e gen of Ryzen 2e gen)';
        if ($tpmOk === false) $hardNo[] = 'TPM 2.0 ontbreekt';
        if ($sbOk === false)  $hardNo[] = 'Secure Boot uit/ontbreekt';

        $ramOk = null;
        if ($ramInstalled !== null) $ramOk = ($ramInstalled >= $minRam);

        // Beslislogica
        $label    = 'nee';
        $reqUp    = 0;
        $supports = 0;   // 1 | -1 | 0 | null
        $reasons  = [];

        if (empty($hardNo)) {
            if ($cpuOk === true && $tpmOk === true && $sbOk === true) {
                if ($ramOk === true) {
                    $label    = 'ja';
                    $supports = 1;
                } elseif ($ramOk === false && $ramMax !== null && $ramMax >= $minRam) {
                    $label    = 'na_ram_upgrade';
                    $reqUp    = 1;
                    $supports = -1;
                } else {
                    // RAM onbekend of te laag zonder upgradepad
                    $label    = 'nee';
                    $supports = 0;
                    $reasons[] = $ramOk === null ? 'RAM onbekend'
                                                 : 'RAM te laag en geen zinvol upgradepad';
                }
            } else {
                // Onvolledige info: geef geen definitieve DB-waarde
                if (!empty($missing)) {
                    $label    = 'nee';
                    $supports = null; // niet wegschrijven
                    $reasons[] = 'Onvolledige gegevens: ' . implode(', ', $missing);
                }
            }
        } else {
            $label    = 'nee';
            $supports = 0;
            $reasons  = array_merge($reasons, $hardNo);
        }

        $advice = self::composeText($row, $label, $reqUp, $reasons, $minRam);

        return [
            'pcslim_label'         => $label,
            'requires_ram_upgrade' => $reqUp,
            'supports_w11_value'   => $supports,   // 1 | -1 | 0 | null
            'advice_text'          => $advice,
        ];
    }

    /* ====== Helpers ====== */

    private static function cpuOk(string $brand, string $family, ?int $gen): ?bool
    {
        if ($gen === null) return null;
        $brandU = strtoupper($brand);
        $famU   = strtoupper($family);

        // AMD Ryzen
        if ($brandU === 'AMD' || str_contains($famU, 'RYZEN')) {
            return $gen >= 2; // Ryzen 2e gen of hoger
        }
        // Default: Intel
        return $gen >= 8;     // Intel 8e gen of hoger
    }

    private static function yn(string $v): ?bool
    {
        $s = strtolower(trim($v));
        if ($s === 'yes') return true;
        if ($s === 'no')  return false;
        return null; // unknown
    }

    /**
     * Context-gevoelige opslag-tip, zónder NVMe-verwijzing.
     * - Als HDD én SSD aanwezig: adviseer HDD→SATA-SSD vervanging.
     * - Als alleen HDD: adviseer HDD→SSD.
     * - Als alleen SSD: geen opslag-advies.
     */
    public static function storageUpgradeTips(array $row): array {

        $tips = [];

        $type  = strtolower((string)($row['storage_type']      ?? ''));
        $bays  = strtolower((string)($row['storage_bays']      ?? ''));
        $iface = strtolower((string)($row['storage_interface'] ?? ''));

        // Simpele signalen naar aan/afwezigheid
        $hasHdd = str_contains($type, 'hdd') || str_contains($bays, 'hdd');
        $hasSsd = str_contains($type, 'ssd') || str_contains($bays, 'ssd') ||
                  str_contains($iface, 'sata') || str_contains($iface, 'm.2') || str_contains($iface, 'nvme');

        if ($hasHdd && $hasSsd) {
            $tips[] = "Overweeg de HDD te vervangen door een SATA-SSD voor extra snelheid en stilte.";
        } elseif ($hasHdd && !$hasSsd) {
            $tips[] = "Vervang de HDD door een SSD voor flinke snelheidswinst.";
        }
        // Geen NVMe-tip geven tenzij we 100% zeker weten dat er een vrije M.2-sleuf is — bewust weggelaten.

        return $tips;
    }

    private static function composeText(array $row, string $label, int $needsUpgrade, array $reasons, int $minRam): string
    {
        $brand   = $row['brand'] ?? 'Onbekend merk';
        $model   = $row['display_model'] ?? 'Onbekend model';

        // Mooie RAM-weergave, zonder “-/–”
        $ramStr  = isset($row['ram_installed_gb']) ? ((int)$row['ram_installed_gb']).'GB' : 'onbekend';
        $ramMax  = isset($row['ram_max_gb'])       ? ((int)$row['ram_max_gb']).'GB'       : 'onbekend';
        $ramType = $row['ram_type'] ?? null;
        $slots   = isset($row['ram_slots']) ? (int)$row['ram_slots'] : null;

        $head = "Model: {$brand} {$model}. RAM: {$ramStr}" . ($ramType ? " ({$ramType})" : "") . " (max {$ramMax})";

        $extraTips = self::storageUpgradeTips($row);

        if ($label === 'ja') {
            $txt = $head . ". Windows 11 is **haalbaar**. Controleer dat TPM 2.0 en Secure Boot aan staan.";
            if ($extraTips) $txt .= " Tip: " . implode(' ', $extraTips);
            return $txt;
        }

        if ($label === 'na_ram_upgrade') {
            $bits = [];
            if ($ramType) $bits[] = $ramType;
            if ($slots !== null) $bits[] = ($slots === 1 ? '1 slot' : "{$slots} slots");
            $tail = $bits ? ' (' . implode(', ', $bits) . ')' : '';
            $txt  = $head . ". **Nu niet haalbaar**, maar **met een RAM-upgrade naar ≥{$minRam}GB** lukt het wél{$tail}.";
            if ($extraTips) $txt .= " " . implode(' ', $extraTips);
            return $txt;
        }

        // 'nee'
        $why = $reasons ? ' Reden: ' . implode('; ', $reasons) . '.' : '';
        $txt = $head . ". **Windows 11 is niet haalbaar.**" . $why . " Advies: kies Linux (bijv. Zorin OS of Linux Mint).";
        if ($extraTips) $txt .= " " . implode(' ', $extraTips);
        return $txt;
    }
}
