# pc_slim_noteb_pipeline_v2.py
# PC-Slim noteb.com -> MySQL pipeline (V2)
# - Scrapedata -> staging (noteb_models_raw)
# - Python parsing: storage_gb, resolution_w/h
# - Sterke Windows 11-heuristiek (CPU + jaar)
# - Mapt naar 'models' en 'pc_slim_model_specs' zonder DB-specifieke regexfuncties
#
# OS filter: slaat records over met OS dat matcht op (ChromeOS/Chromebook/iOS/iPadOS/Windows 11)

import re, time, sys
from urllib.parse import urljoin
from datetime import datetime
import requests
from bs4 import BeautifulSoup
import pymysql

# ========= CONFIG =========
MYSQL = dict(
    host="127.0.0.1",
    user="root",
    password="yourpassword",
    database="upgradekeuze",
    charset="utf8mb4",
    autocommit=True,
)

# Brede ingang; je kunt hier extra gefilterde zoek-URL's aan toevoegen
START_URLS = [
    "https://noteb.com/?search/search.php?type=99&sort_by=value"
]

PAUSE = 1.0  # beleefdheid (sekonden tussen requests)
UA = "Mozilla/5.0 (PC-Slim Noteb Scraper v2)"

# Filter OS die we NIET willen opslaan
OS_EXCLUDE = re.compile(r"(chrome ?os|chromebook|ios|ipad ?os|windows 11)", re.I)

# ========= HELPERS =========
def clean(x):
    return re.sub(r"\s+", " ", x).strip() if x else x

def to_int_first_number(x):
    if not x: return None
    m = re.search(r"(\d+)", x.replace(",", ""))
    return int(m.group(1)) if m else None

def to_float_first_number(x):
    if not x: return None
    m = re.search(r"(\d+(?:[.,]\d+)?)", x)
    return float(m.group(1).replace(",", ".")) if m else None

def parse_storage_gb(storage_text: str|None):
    """
    Haalt grootste opslaggrootte uit tekst en zet om naar GB.
    Herkent GB/TB, pakt de hoogste gevonden waarde.
    Voorbeelden: "SSD NVMe 256GB", "1TB + 128GB", "512 GB SSD"
    """
    if not storage_text:
        return None
    nums = []
    # Zoek tokens met getal + (GB/TB) of alleen getal (dan aannemen GB)
    for m in re.finditer(r"(\d+(?:[.,]\d+)?)\s*(T|TB|G|GB)\b", storage_text, re.I):
        val = float(m.group(1).replace(",", "."))
        unit = m.group(2).upper()
        if unit.startswith("T"):  # T/TB
            nums.append(int(round(val * 1024)))
        else:
            nums.append(int(round(val)))
    if not nums:
        # fallback: eerste "los" getal interpreteren als GB
        m = re.search(r"(\d+)", storage_text)
        if m:
            return int(m.group(1))
        return None
    return max(nums)

def parse_resolution(res_text: str|None):
    """
    Herkent W x H (ook met '×') en retourneert (w, h).
    Voorbeelden: "1920x1080", "2160 × 1440"
    """
    if not res_text:
        return (None, None)
    t = res_text.lower().replace("×", "x").replace(" ", "")
    m = re.search(r"(\d{3,5})x(\d{3,5})", t)
    if m:
        w = int(m.group(1))
        h = int(m.group(2))
        return (w, h)
    return (None, None)

def cpu_brand_and_arch(cpu_text: str|None):
    """
    Zet grof CPU-merk / arch: 'Intel' of 'AMD' of None.
    (Apple, ARM e.d. slaan we niet expliciet op; ons project focust op Win/Linux laptops.)
    """
    if not cpu_text:
        return None
    t = cpu_text.lower()
    if "intel" in t:
        return "Intel"
    if "ryzen" in t or "amd" in t:
        return "AMD"
    return None

def guess_supports_w11(cpu_text: str|None, release_year: int|None):
    """
    Sterke, maar veilige heuristiek voor Windows 11-geschiktheid:
    - Intel 8th gen Core i3/i5/i7/i9 of nieuwer → 1
    - AMD Ryzen 2000 of nieuwer → 1
    - Celeron/Pentium/Athlon/A-serie → 0
    - Anders: fallback op jaar >= 2018 → 1 (optioneel, kan je uitzetten)
    """
    if not cpu_text:
        # geen CPU-info → alleen op jaar
        return 1 if (release_year and release_year >= 2018) else 0

    t = cpu_text.lower()

    # duidelijk NIET ondersteund (meestal)
    if any(x in t for x in ["celeron", "pentium", "atom", "athlon", "a4-", "a6-", "a8-", "a10-", "fx-"]):
        return 0

    # Intel Core i3/i5/i7/i9 generaties 8xxx, 9xxx, 10xxx, 11xxx, ... (ook U/H varianten)
    if re.search(r"\b(i[3-9]-)(8|9|\d{2})\d{2}\b", t):
        return 1

    # Nieuwere Intel zonder i-prefix (Core 7 155U e.d.) – lastig; gebruik jaar fallback
    if "intel" in t and ("core" in t or "ultra" in t or "n100" in t):
        # n100 etc. is tricky: officieel unsupported; we zetten die op 0
        if "n100" in t or re.search(r"\bn\d{3}\b", t):
            return 0
        # Anders jaar-check
        if release_year and release_year >= 2018:
            return 1

    # AMD Ryzen 2xxx of hoger
    if "ryzen" in t:
        # pak eerste cijfer na 'ryzen '
        m = re.search(r"ryzen\s*[3-9]?\s*([1-9])\d{3}", t)
        if m:
            series = int(m.group(1))
            if series >= 2:
                return 1
        # Zonder duidelijke serie → fallback op jaar
        if release_year and release_year >= 2018:
            return 1

    # laatste fallback: jaar
    if release_year and release_year >= 2018:
        return 1
    return 0

# ========= SCRAPE =========
S = requests.Session()
S.headers.update({"User-Agent": UA})

def spec_lookup(soup, labels):
    """
    labels = lijstje mogelijke labelstrings (case-insensitief).
    We zoeken in tabellen naar een rij waar de linker cel gelijk is aan een van de labels.
    """
    want = {lbl.lower() for lbl in labels}
    for tr in soup.select("tr"):
        th = tr.find("th") or tr.find("td")
        if not th:
            continue
        l = clean(th.get_text(" ", strip=True)).lower()
        if l in want:
            tds = tr.find_all("td")
            if tds:
                return clean(tds[-1].get_text(" ", strip=True))
    return None

def parse_detail(url):
    r = S.get(url, timeout=25)
    r.raise_for_status()
    soup = BeautifulSoup(r.text, "html.parser")

    # Titel -> merk + model
    brand = model = None
    h1 = soup.find("h1")
    if h1:
        title = clean(h1.get_text())
        if " " in title:
            brand = title.split()[0]
            model = title[len(brand):].strip()

    # Pak kernspecs (NL en EN varianten)
    cpu = spec_lookup(soup, ["Model", "CPU"])
    cpu_cores = to_int_first_number(spec_lookup(soup, ["Aantal kernen", "Cores"]))
    ram_inst = to_int_first_number(spec_lookup(soup, ["Capaciteit", "Memory"]))
    ram_max = to_int_first_number(spec_lookup(soup, ["Geheugenlimiet", "Max memory"]))
    storage_text = spec_lookup(soup, ["Model/Capaciteit", "Storage"])
    screen_in = to_float_first_number(spec_lookup(soup, ["Maat", "Size"]))
    resolution_txt = spec_lookup(soup, ["Oplossing", "Resolution"])
    panel = spec_lookup(soup, ["Technologie", "Panel"])
    os = spec_lookup(soup, ["Systeem", "OS", "Operating system"])
    year_txt = spec_lookup(soup, ["Jaren", "Launch", "Year"])
    release_year = to_int_first_number(year_txt)
    weight_kg = to_float_first_number(spec_lookup(soup, ["Gewicht", "Weight"]))
    battery_wh = to_float_first_number(spec_lookup(soup, ["Capaciteit:", "Battery"]))

    # Parsed fields
    storage_gb = parse_storage_gb(storage_text)
    res_w, res_h = parse_resolution(resolution_txt)
    cpu_arch = cpu_brand_and_arch(cpu)
    supports = guess_supports_w11(cpu, release_year)

    return dict(
        source_url=url,
        brand=brand,
        model_name=model,
        cpu=cpu,
        cpu_cores=cpu_cores,
        ram_installed_gb=ram_inst,
        ram_max_gb=ram_max,
        storage_text=storage_text,
        storage_gb=storage_gb,
        screen_size_in=screen_in,
        resolution=resolution_txt,
        res_w=res_w,
        res_h=res_h,
        panel_type=panel,
        os=os,
        release_year=release_year,
        weight_kg=weight_kg,
        battery_wh=battery_wh,
        cpu_arch=cpu_arch,
        supports_w11=supports,
        notes=None,
        scraped_at=datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S"),
    )

def iter_results(start_url):
    url = start_url
    seen = set()
    while url:
        resp = S.get(url, timeout=25); resp.raise_for_status()
        soup = BeautifulSoup(resp.text, "html.parser")

        # Haal productlinks (robuust generiek)
        for a in soup.select("a[href]"):
            href = a["href"]
            if re.search(r"/(product|notebook|search/detail)", href):
                full = urljoin(url, href)
                if full not in seen:
                    seen.add(full)
                    yield full

        # Volgende pagina-knop
        nxt = soup.find("a", string=re.compile(r"Next|Volgende|>"))
        url = urljoin(url, nxt["href"]) if nxt and nxt.get("href") else None
        time.sleep(PAUSE)

# ========= DB =========
DDL_RAW = """
CREATE TABLE IF NOT EXISTS noteb_models_raw (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  source_url VARCHAR(255) NOT NULL,
  brand VARCHAR(120),
  model_name VARCHAR(190),
  cpu VARCHAR(190),
  cpu_cores INT NULL,
  ram_installed_gb INT NULL,
  ram_max_gb INT NULL,
  storage_text VARCHAR(190),
  storage_gb INT NULL,
  screen_size_in DECIMAL(4,1) NULL,
  resolution VARCHAR(32),
  res_w INT NULL,
  res_h INT NULL,
  panel_type VARCHAR(64),
  os VARCHAR(64),
  release_year INT NULL,
  weight_kg DECIMAL(4,2) NULL,
  battery_wh DECIMAL(5,1) NULL,
  cpu_arch VARCHAR(32),
  supports_w11 TINYINT(1),
  notes TEXT,
  scraped_at DATETIME NOT NULL,
  UNIQUE KEY uniq_bm (brand, model_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""

UPSERT_RAW = """
INSERT INTO noteb_models_raw
(source_url,brand,model_name,cpu,cpu_cores,ram_installed_gb,ram_max_gb,storage_text,storage_gb,screen_size_in,
 resolution,res_w,res_h,panel_type,os,release_year,weight_kg,battery_wh,cpu_arch,supports_w11,notes,scraped_at)
VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
ON DUPLICATE KEY UPDATE
 cpu=VALUES(cpu),
 cpu_cores=VALUES(cpu_cores),
 ram_installed_gb=VALUES(ram_installed_gb),
 ram_max_gb=VALUES(ram_max_gb),
 storage_text=VALUES(storage_text),
 storage_gb=VALUES(storage_gb),
 screen_size_in=VALUES(screen_size_in),
 resolution=VALUES(resolution),
 res_w=VALUES(res_w),
 res_h=VALUES(res_h),
 panel_type=VALUES(panel_type),
 os=VALUES(os),
 release_year=VALUES(release_year),
 weight_kg=VALUES(weight_kg),
 battery_wh=VALUES(battery_wh),
 cpu_arch=VALUES(cpu_arch),
 supports_w11=VALUES(supports_w11),
 notes=VALUES(notes),
 scraped_at=VALUES(scraped_at);
"""

def upsert_model(cur, r):
    """
    Zorgt dat 'models' een rij heeft met dit brand+model + velden die we nodig hebben.
    Overschrijft bestaande rijen NIET (alleen velden die leeg zijn).
    """
    # Bestaat model al?
    cur.execute("SELECT id, supports_w11 FROM models WHERE brand=%s AND display_model=%s;", (r["brand"], r["model_name"]))
    row = cur.fetchone()
    if not row:
        # Insert
        model_regex = "^" + re.sub(r"([(){}\[\].+*?^$|\\])", r"\\\1", r["model_name"]) + "$" if r["model_name"] else None
        storage_str = (f"{r['storage_gb']} GB" if r.get("storage_gb") else (r.get("storage_text") or ""))[:190]
        cur.execute("""
            INSERT INTO models (brand, display_model, model_regex, max_ram_gb, supports_w11, storage, cpu_arch, notes, active)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,1);
        """, (
            r["brand"], r["model_name"], model_regex,
            r.get("ram_max_gb") or r.get("ram_installed_gb"),
            r.get("supports_w11"),
            storage_str,
            r.get("cpu_arch"),
            f"Bron: noteb.com • OS: {r.get('os') or ''}"
        ))
        cur.execute("SELECT LAST_INSERT_ID();")
        model_id = cur.fetchone()[0]
        return model_id
    else:
        model_id, sup = row
        # Eventueel supports_w11 aanvullen als NULL
        if sup is None and r.get("supports_w11") is not None:
            cur.execute("UPDATE models SET supports_w11=%s WHERE id=%s;", (r["supports_w11"], model_id))
        return model_id

def ensure_specs(cur, model_id, r):
    """
    Maakt pc_slim_model_specs aan voor dit model als die nog niet bestaat.
    We vullen alleen wat we betrouwbaar hebben (CPU-cores, RAM, storage_gb, scherm, resolutiehoogte).
    """
    cur.execute("SELECT model_id FROM pc_slim_model_specs WHERE model_id=%s;", (model_id,))
    if cur.fetchone():
        return
    display_height_px = r["res_h"] if r.get("res_h") else None
    storage_gb = r.get("storage_gb")
    cur.execute("""
        INSERT INTO pc_slim_model_specs (
            model_id, device_type, cpu_ghz, cpu_cores, cpu_arch, ram_gb, storage_gb,
            tpm_version, has_uefi, secure_boot_enabled, gpu_supports_dx12, wddm_major,
            display_inches, display_height_px, display_effective_8bit, has_bluetooth, has_wifi, has_ethernet
        ) VALUES (%s,'Notebook',NULL,%s,%s,%s,%s,NULL,NULL,NULL,NULL,NULL,%s,%s,NULL,NULL,NULL,NULL);
    """, (
        model_id,
        r.get("cpu_cores"),
        r.get("cpu_arch"),
        r.get("ram_installed_gb"),
        storage_gb,
        r.get("screen_size_in"),
        display_height_px
    ))

def run():
    conn = pymysql.connect(**MYSQL)
    cur = conn.cursor()
    cur.execute(DDL_RAW)

    # 1) SCRAPE -> RAW
    for start in START_URLS:
        for detail_url in iter_results(start):
            try:
                time.sleep(PAUSE)
                rec = parse_detail(detail_url)
                # OS-filter: sla over als expliciet uitgesloten OS
                os_txt = rec.get("os") or ""
                if OS_EXCLUDE.search(os_txt):
                    print("SKIP OS:", os_txt, rec["brand"], rec["model_name"])
                    continue
                cur.execute(UPSERT_RAW, (
                    rec["source_url"], rec["brand"], rec["model_name"], rec["cpu"], rec["cpu_cores"],
                    rec["ram_installed_gb"], rec["ram_max_gb"], rec["storage_text"], rec["storage_gb"],
                    rec["screen_size_in"], rec["resolution"], rec["res_w"], rec["res_h"], rec["panel_type"],
                    rec["os"], rec["release_year"], rec["weight_kg"], rec["battery_wh"], rec["cpu_arch"],
                    rec["supports_w11"], rec["notes"], rec["scraped_at"]
                ))
                print("RAW:", rec["brand"], rec["model_name"])
            except Exception as e:
                print("ERR detail:", detail_url, e)

    # 2) RAW -> MODELS + SPECS (alle records die niet uitgesloten OS hebben)
    cur.execute("""
        SELECT brand, model_name, cpu, cpu_cores, ram_installed_gb, ram_max_gb, storage_text, storage_gb,
               screen_size_in, resolution, res_w, res_h, panel_type, os, release_year, weight_kg, battery_wh,
               cpu_arch, supports_w11
        FROM noteb_models_raw
        WHERE (os IS NULL OR os NOT REGEXP '(?i)windows 11|chrome ?os|chromebook|ios|ipad ?os');
    """)
    rows = cur.fetchall()

    cols = ["brand","model_name","cpu","cpu_cores","ram_installed_gb","ram_max_gb","storage_text","storage_gb",
            "screen_size_in","resolution","res_w","res_h","panel_type","os","release_year","weight_kg",
            "battery_wh","cpu_arch","supports_w11"]

    for row in rows:
        r = dict(zip(cols, row))
        # safety: brand+model must exist
        if not r["brand"] or not r["model_name"]:
            continue
        # upsert into models
        model_id = upsert_model(cur, r)
        # ensure specs
        ensure_specs(cur, model_id, r)

    cur.close()
    conn.close()
    print("Done.")

if __name__ == "__main__":
    try:
        run()
    except KeyboardInterrupt:
        sys.exit(1)
