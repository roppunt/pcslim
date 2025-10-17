#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Scrape laptopmodellen (vóór 2021) uit openbare bronnen naar CSV voor UpgradeKeuze.
Output: models_pre2021.csv met kolommen:
brand,display_model,model_regex_json,max_ram_gb,supports_w11,storage,cpu_arch,notes,active
"""

import csv
import json
import re
import sys
import time
from dataclasses import dataclass
from typing import List, Dict, Optional, Tuple
from urllib.parse import urljoin

import requests
from bs4 import BeautifulSoup

UA = "UpgradeKeuzeBot/1.0 (+contact: admin@jouwdomein.nl) requests/BS4 (respect robots/no spam)"
SESSION = requests.Session()
SESSION.headers.update({"User-Agent": UA, "Accept-Language": "nl,en;q=0.8"})

# --------- Hulpfuncties ---------

YEAR_RX = re.compile(r"\b(19[6-9]\d|20[0-2]\d)\b")  # 1960..2029 (we filter later < 2021)
CLEAN_WS = re.compile(r"\s+")

def fetch(url: str, tries: int = 3, sleep: float = 1.2) -> Optional[str]:
    for i in range(tries):
        try:
            r = SESSION.get(url, timeout=20)
            if r.status_code == 200 and r.text:
                return r.text
        except requests.RequestException:
            pass
        time.sleep(sleep * (i + 1))
    return None

def clean_text(s: str) -> str:
    return CLEAN_WS.sub(" ", (s or "").strip(" \t\r\n\u00a0"))

def earliest_year(text: str) -> Optional[int]:
    yrs = [int(y) for y in YEAR_RX.findall(text or "")]
    return min(yrs) if yrs else None

def build_model_regex(display_model: str) -> List[str]:
    """
    Maak een tolerante regex:
    - spaties en koppeltekens optioneel/uitwisselbaar
    - case-insensitive (de import gebruikt /i)
    Voorbeeld: "EliteBook 840 G1" -> ["elitebook\\s*-?\\s*840\\s*-?\\s*g1"]
    """
    base = display_model.lower().strip()
    # escape regexp specials behalve spatie en '-'
    base = re.sub(r"([.^$+(){}\[\]|\\])", r"\\\1", base)
    parts = re.split(r"[\s\-]+", base)
    pattern = r"\s*-?\s*".join([re.escape(p) for p in parts if p])
    return [pattern]

@dataclass
class ModelRow:
    brand: str
    display_model: str
    year: Optional[int]
    notes: str = ""

    def to_csv_row(self) -> Dict[str, str]:
        return {
            "brand": self.brand,
            "display_model": self.display_model,
            "model_regex_json": json.dumps(build_model_regex(self.display_model), ensure_ascii=False),
            "max_ram_gb": "",
            "supports_w11": "",
            "storage": "",
            "cpu_arch": "",
            "notes": self.notes,
            "active": "1",
        }

# --------- Wikipedia scrapers ---------

def scrape_wikipedia_table(url: str, brand: str, model_col_candidates=("Model", "Name", "Model name", "Series"), year_cols=("Released", "Release date", "Launch", "Introduced")) -> List[ModelRow]:
    """
    Algemene tabel-scraper voor Wikipedia-pagina's met 'wikitable' tabellen.
    Probeert kolomnamen te vinden voor model en releasejaar.
    """
    html = fetch(url)
    if not html:
        return []
    soup = BeautifulSoup(html, "html.parser")
    out: List[ModelRow] = []

    tables = soup.select("table.wikitable")
    for table in tables:
        # header
        headers = [clean_text(th.get_text()) for th in table.select("tr th")]
        if not headers:
            continue

        # kolomindexen bepalen
        def find_index(cands):
            for c in cands:
                if c in headers:
                    return headers.index(c)
            # fallback: fuzzy
            for idx, h in enumerate(headers):
                for c in cands:
                    if c.lower() in h.lower():
                        return idx
            return None

        idx_model = find_index(model_col_candidates)
        idx_year  = find_index(year_cols)
        # Soms staat jaar in een "Notes" of "Comments" kolom
        idx_notes = find_index(("Notes", "Remarks", "Comments", "Description"))

        # Zonder modelkolom heeft het weinig zin
        if idx_model is None:
            continue

        # data-rijen
        for tr in table.select("tr")[1:]:
            tds = tr.find_all(["td", "th"])
            if len(tds) < (idx_model + 1):
                continue
            model = clean_text(tds[idx_model].get_text())
            if not model or len(model) < 2:
                continue

            text_for_year = ""
            if idx_year is not None and len(tds) > idx_year:
                text_for_year += " " + tds[idx_year].get_text(separator=" ")
            if idx_notes is not None and len(tds) > idx_notes:
                text_for_year += " " + tds[idx_notes].get_text(separator=" ")

            y = earliest_year(text_for_year)
            # Filter hier nog niet; verzamelen eerst
            notes = clean_text(text_for_year)
            out.append(ModelRow(brand=brand, display_model=model, year=y, notes=notes))
    return out

def scrape_lenovo_thinkpad() -> List[ModelRow]:
    # Overzicht ThinkPads (lange pagina met meerdere tabellen)
    url = "https://en.wikipedia.org/wiki/List_of_IBM_and_Lenovo_ThinkPad_laptops"
    rows = scrape_wikipedia_table(url, "Lenovo", model_col_candidates=("Model", "Name", "Model name", "Series"),
                                  year_cols=("Released", "Release date", "Introduced", "Launch"))
    # Veel ThinkPad tabellen hebben geen jaarkolom; probeer uit tekst
    # Als nog geen jaar, probeer uit hele rijtekst
    fixed = []
    for r in rows:
        if r.year is None:
            r.year = earliest_year(r.notes)
        fixed.append(r)
    return fixed

def scrape_hp_elitebook_probook() -> List[ModelRow]:
    rows: List[ModelRow] = []
    # EliteBook (meestal een eigen lijst of pagina)
    elite_url = "https://en.wikipedia.org/wiki/HP_EliteBook"
    rows += scrape_wikipedia_table(elite_url, "HP", model_col_candidates=("Model", "Model name", "Series"),
                                   year_cols=("Released", "Release date", "Introduced"))
    # ProBook
    probook_url = "https://en.wikipedia.org/wiki/HP_ProBook"
    rows += scrape_wikipedia_table(probook_url, "HP", model_col_candidates=("Model", "Model name", "Series"),
                                   year_cols=("Released", "Release date", "Introduced"))
    return rows

def scrape_dell_latitude() -> List[ModelRow]:
    url = "https://en.wikipedia.org/wiki/Dell_Latitude"
    return scrape_wikipedia_table(url, "Dell", model_col_candidates=("Model", "Model name", "Series"),
                                  year_cols=("Released", "Release date", "Introduced"))

def scrape_acer_aspire() -> List[ModelRow]:
    url = "https://en.wikipedia.org/wiki/Acer_Aspire"
    return scrape_wikipedia_table(url, "Acer", model_col_candidates=("Model", "Model name", "Series"),
                                  year_cols=("Released", "Release date", "Introduced"))

def scrape_asus_vivobook() -> List[ModelRow]:
    url = "https://en.wikipedia.org/wiki/Asus_VivoBook"
    return scrape_wikipedia_table(url, "ASUS", model_col_candidates=("Model", "Model name", "Series"),
                                  year_cols=("Released", "Release date", "Introduced"))

# --------- Post-processing: filteren en dedupliceren ---------

def filter_pre2021(rows: List[ModelRow]) -> List[ModelRow]:
    out = []
    for r in rows:
        # als jaar onbekend: laat voorlopig toe, maar we proberen heuristiek:
        # - Als in notes toch een jaar ≥2021 staat, skippen
        if r.year is None:
            alt_year = earliest_year(r.notes)
            if alt_year is not None and alt_year >= 2021:
                continue
            out.append(r)
        else:
            if r.year < 2021:
                out.append(r)
    return out

def dedupe(rows: List[ModelRow]) -> List[ModelRow]:
    seen = set()
    out = []
    for r in rows:
        key = (r.brand.lower().strip(), r.display_model.lower().strip())
        if key in seen:
            continue
        seen.add(key)
        out.append(r)
    return out

# --------- Main ---------

def main():
    print("Scrape gestart… (dit kan even duren)")
    all_rows: List[ModelRow] = []

    # Merk-scrapers
    tasks = [
        ("Lenovo ThinkPad", scrape_lenovo_thinkpad),
        ("HP EliteBook/ProBook", scrape_hp_elitebook_probook),
        ("Dell Latitude", scrape_dell_latitude),
        ("Acer Aspire", scrape_acer_aspire),
        ("ASUS VivoBook", scrape_asus_vivobook),
    ]

    for name, fn in tasks:
        try:
            print(f" > {name}…", flush=True)
            part = fn()
            print(f"   Gevonden: {len(part)}", flush=True)
            all_rows.extend(part)
            time.sleep(1.2)  # beleefd wachten
        except Exception as e:
            print(f"   Fout bij {name}: {e}", file=sys.stderr)

    print(f"Totaal onbewerkt: {len(all_rows)}")
    pre = filter_pre2021(all_rows)
    print(f"Na filter <2021: {len(pre)}")
    ded = dedupe(pre)
    print(f"Na dedupe: {len(ded)}")

    # Schrijf CSV
    out_fn = "models_pre2021.csv"
    with open(out_fn, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=[
            "brand","display_model","model_regex_json","max_ram_gb","supports_w11","storage","cpu_arch","notes","active"
        ])
        w.writeheader()
        for r in ded:
            w.writerow(r.to_csv_row())

    print(f"Klaar. CSV geschreven: {out_fn}")
    print("Upload via /admin/import_models.php (eerst Dry-run).")

if __name__ == "__main__":
    main()
