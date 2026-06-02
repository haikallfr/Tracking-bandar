#!/usr/bin/env python3
"""Fetch and import IDX/KSEI public 1%+ ownership data.

The source is IDX's public announcement endpoint for:
"Pemegang Saham di atas 1% (KSEI)".

Requires PyMuPDF:
  python3 -m pip install --user pymupdf
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import sqlite3
import sys
from datetime import datetime
from pathlib import Path
from typing import Any
from urllib.parse import urlencode
from urllib.request import Request, urlopen

try:
    import fitz  # PyMuPDF
except ImportError:
    print(
        "PyMuPDF belum terpasang. Jalankan: python3 -m pip install --user pymupdf",
        file=sys.stderr,
    )
    raise


ROOT = Path(__file__).resolve().parents[1]
STORAGE_DIR = ROOT / "storage"
KSEI_DIR = STORAGE_DIR / "ksei"
RAW_DIR = KSEI_DIR / "raw"
PARSED_DIR = KSEI_DIR / "parsed"
DB_PATH = STORAGE_DIR / "app.sqlite"

IDX_ANNOUNCEMENT_ENDPOINT = "https://www.idx.id/primary/NewsAnnouncement/GetAllAnnouncement"
IDX_REFERER = "https://www.idx.id/id/berita/pengumuman/"
KSEI_KEYWORD = "Pemegang Saham di atas 1% (KSEI)"

DATE_RE = re.compile(r"^\d{2}-[A-Za-z]{3}-\d{4}$")
DATE_SYMBOL_RE = re.compile(r"^(\d{2}-[A-Za-z]{3}-\d{4})\s+([A-Z0-9]{4})$")
HEADER_LINES = {
    "[PUBLIK]",
    "DATE",
    "SHARE_CODE",
    "ISSUER_NAME",
    "INVESTOR_NAME",
    "INVESTOR_TYPE",
    "LOCAL_FOREIGN",
    "NATIONALITY",
    "DOMICILE",
    "HOLDINGS_SCRIPLESS",
    "HOLDINGS_SCRIP",
    "TOTAL_HOLDING_SHARES",
    "PERCENTAGE",
}
INVESTOR_TYPE_CODES = {
    "ASC",
    "BCP",
    "CP",
    "DCP",
    "IB",
    "ID",
    "IS",
    "MF",
    "OT",
    "PF",
    "SC",
    "SEMF",
    "TIB",
}
LOCAL_FOREIGN_CODES = {"D", "F", "L"}
MONTHS = {
    "Jan": "01",
    "Feb": "02",
    "Mar": "03",
    "Apr": "04",
    "May": "05",
    "Jun": "06",
    "Jul": "07",
    "Aug": "08",
    "Sep": "09",
    "Oct": "10",
    "Nov": "11",
    "Dec": "12",
}

COLUMNS = {
    "raw_date": (50.0, 75.0),
    "symbol": (74.0, 100.0),
    "issuer_name": (100.0, 158.0),
    "owner_name": (158.0, 216.8),
    "owner_type": (216.8, 240.0),
    "local_foreign": (240.0, 275.0),
    "nationality": (275.0, 318.0),
    "domicile": (318.0, 390.0),
    "holdings_scripless": (390.0, 437.0),
    "holdings_scrip": (437.0, 480.0),
    "total_holding_shares": (480.0, 530.0),
    "ownership_pct": (530.0, 570.0),
}


def main() -> int:
    parser = argparse.ArgumentParser(description="Ambil data KSEI kepemilikan saham di atas 1%.")
    parser.add_argument("--date", help="Filter tanggal publikasi IDX, format YYYYMMDD.")
    parser.add_argument("--index", type=int, default=0, help="Ambil item ke-N dari hasil IDX. Default: 0/latest.")
    parser.add_argument("--list", action="store_true", help="Tampilkan daftar publikasi tanpa download/import.")
    parser.add_argument("--no-import", action="store_true", help="Hanya download + parse CSV, tidak import SQLite.")
    args = parser.parse_args()

    RAW_DIR.mkdir(parents=True, exist_ok=True)
    PARSED_DIR.mkdir(parents=True, exist_ok=True)

    announcements = fetch_announcements()
    if args.date:
        announcements = [
            item for item in announcements
            if compact_date(str(item.get("PublishDate", ""))) == args.date
        ]

    if args.list:
        for idx, item in enumerate(announcements):
            print(f"{idx}: {item.get('PublishDate')} | {item.get('Title')} | {str(item.get('Code', '')).strip()}")
        return 0

    if not announcements:
        raise RuntimeError("Tidak ada publikasi KSEI yang cocok.")

    if args.index < 0 or args.index >= len(announcements):
        raise RuntimeError(f"Index {args.index} di luar range. Total publikasi: {len(announcements)}")

    announcement = announcements[args.index]
    attachment = choose_attachment(announcement)
    source_url = idx_id_url(str(attachment["FullSavePath"]))
    published_at = str(announcement.get("PublishDate", ""))
    publish_stamp = compact_date(published_at) or datetime.now().strftime("%Y%m%d")
    raw_pdf = RAW_DIR / f"{publish_stamp}_ksei_1pct_lamp1.pdf"

    download(source_url, raw_pdf)
    rows = parse_pdf(raw_pdf, source_url, published_at)
    if not rows:
        raise RuntimeError("PDF berhasil diunduh, tapi tidak ada baris kepemilikan yang terbaca.")

    positions = aggregate_positions(rows)
    effective_date = positions[0]["effective_date"]
    csv_path = PARSED_DIR / f"ksei_1pct_{effective_date.replace('-', '')}.csv"
    write_csv(csv_path, positions)

    imported = 0
    if not args.no_import:
        imported = import_sqlite(positions, raw_pdf.name)

    summary = {
        "published_at": published_at,
        "effective_date": effective_date,
        "parsed_rows": len(rows),
        "positions": len(positions),
        "symbols": len({row["symbol"] for row in positions}),
        "raw_pdf": str(raw_pdf),
        "csv": str(csv_path),
        "imported": imported,
        "source_url": source_url,
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


def fetch_announcements() -> list[dict[str, Any]]:
    query = urlencode({
        "keywords": KSEI_KEYWORD,
        "pageNumber": 1,
        "pageSize": 20,
        "lang": "id",
    })
    payload = json.loads(http_get(f"{IDX_ANNOUNCEMENT_ENDPOINT}?{query}").decode("utf-8"))
    return list(payload.get("Items", []))


def choose_attachment(announcement: dict[str, Any]) -> dict[str, Any]:
    attachments = [item for item in announcement.get("Attachments", []) if item]
    if not attachments:
        raise RuntimeError("Publikasi IDX tidak memiliki attachment.")

    for item in attachments:
        filename = str(item.get("OriginalFilename", "")).lower()
        if int(item.get("IsAttachment", 0)) == 1 and "lamp" in filename:
            return item

    for item in attachments:
        if int(item.get("IsAttachment", 0)) == 1:
            return item

    return attachments[-1]


def download(url: str, destination: Path) -> None:
    destination.write_bytes(http_get(url))


def parse_pdf(path: Path, source_url: str, published_at: str) -> list[dict[str, str]]:
    positioned = parse_pdf_by_position(path, source_url, published_at)
    lined = parse_pdf_by_lines(path, source_url, published_at)

    positioned_symbols = len({row["symbol"] for row in positioned})
    lined_symbols = len({row["symbol"] for row in lined})
    if lined_symbols > positioned_symbols or len(lined) > len(positioned) * 1.08:
        return lined

    return positioned


def parse_pdf_by_position(path: Path, source_url: str, published_at: str) -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    doc = fitz.open(path)

    for page in doc:
        grouped: dict[float, list[tuple[float, float, float, float, str]]] = {}
        for word in page.get_text("words"):
            x0, y0, x1, y1, text, *_ = word
            if y0 < 160 or text in {"[PUBLIK]", "DATE", "SHARE_CODE"}:
                continue
            grouped.setdefault(round(y0, 1), []).append((x0, y0, x1, y1, text))

        for _, words in sorted(grouped.items()):
            if not any(DATE_RE.match(word[4]) for word in words):
                continue

            row = {name: join_words(words_in_range(words, start, end)) for name, (start, end) in COLUMNS.items()}
            mixed_name = join_words(words_in_range(words, 100.0, 216.8))
            issuer_name, owner_name = split_issuer_owner(mixed_name)
            if owner_name == "":
                issuer_name = row["issuer_name"]
                owner_name = row["owner_name"]

            if (
                not row["symbol"]
                or not owner_name
                or not is_number_text(row["total_holding_shares"])
                or not is_number_text(row["ownership_pct"])
            ):
                continue

            rows.append({
                "effective_date": normalize_date(row["raw_date"]),
                "symbol": row["symbol"].upper(),
                "issuer_name": issuer_name,
                "owner_name": normalize_space(owner_name),
                "owner_type": row["owner_type"],
                "local_foreign": row["local_foreign"],
                "nationality": row["nationality"],
                "domicile": row["domicile"],
                "holdings_scripless": normalize_number(row["holdings_scripless"]),
                "holdings_scrip": normalize_number(row["holdings_scrip"]),
                "total_holding_shares": normalize_number(row["total_holding_shares"]),
                "ownership_shares": normalize_number(row["total_holding_shares"]),
                "ownership_pct": normalize_number(row["ownership_pct"]),
                "source_url": source_url,
                "published_at": published_at,
            })

    return rows


def parse_pdf_by_lines(path: Path, source_url: str, published_at: str) -> list[dict[str, str]]:
    lines: list[str] = []
    doc = fitz.open(path)

    for page in doc:
        for raw_line in (page.get_text("text") or "").splitlines():
            line = normalize_space(raw_line)
            if line and line not in HEADER_LINES:
                lines.append(line)

    rows: list[dict[str, str]] = []
    index = 0
    while index < len(lines):
        match = DATE_SYMBOL_RE.match(lines[index])
        if not match:
            index += 1
            continue

        next_index = index + 1
        while next_index < len(lines) and not DATE_SYMBOL_RE.match(lines[next_index]):
            next_index += 1

        block = lines[index:next_index]
        index = next_index
        if len(block) < 7:
            continue

        raw_date, symbol = match.groups()
        issuer_name, owner_prefix = split_issuer_owner(block[1])
        if issuer_name == "":
            issuer_name = block[1]

        if owner_prefix != "":
            owner_name = owner_prefix
            tail = block[2:]
        else:
            owner_name = block[2]
            tail = block[3:]
        if len(tail) < 4:
            continue

        holdings_scripless, holdings_scrip, total_holding_shares, ownership_pct = tail[-4:]
        if not is_number_text(total_holding_shares) or not is_number_text(ownership_pct):
            continue

        meta = tail[:-4]
        owner_type = ""
        owner_name, embedded_owner_type = split_owner_type_suffix(owner_name)
        if embedded_owner_type:
            owner_type = embedded_owner_type
        local_foreign = ""
        nationality = ""
        domicile = ""

        if owner_type == "" and meta and meta[0] in INVESTOR_TYPE_CODES:
            owner_type = meta.pop(0)
        if meta and meta[0] in LOCAL_FOREIGN_CODES:
            local_foreign = normalize_local_foreign(meta.pop(0))
        if meta and meta[0] == "INDONESIAN":
            nationality = meta.pop(0)
        if meta:
            domicile = " ".join(meta)

        rows.append({
            "effective_date": normalize_date(raw_date),
            "symbol": symbol.upper(),
            "issuer_name": issuer_name,
            "owner_name": owner_name,
            "owner_type": owner_type,
            "local_foreign": local_foreign,
            "nationality": nationality,
            "domicile": domicile,
            "holdings_scripless": normalize_number(holdings_scripless),
            "holdings_scrip": normalize_number(holdings_scrip),
            "total_holding_shares": normalize_number(total_holding_shares),
            "ownership_shares": normalize_number(total_holding_shares),
            "ownership_pct": normalize_number(ownership_pct),
            "source_url": source_url,
            "published_at": published_at,
        })

    return rows


def aggregate_positions(rows: list[dict[str, str]]) -> list[dict[str, str]]:
    grouped: dict[tuple[str, str, str], dict[str, str]] = {}
    numeric_fields = [
        "holdings_scripless",
        "holdings_scrip",
        "total_holding_shares",
        "ownership_shares",
        "ownership_pct",
    ]

    for row in rows:
        key = (row["effective_date"], row["symbol"], row["owner_name"])
        if key not in grouped:
            grouped[key] = row.copy()
            continue

        current = grouped[key]
        for field in numeric_fields:
            current[field] = format_float(float(current[field] or 0) + float(row[field] or 0))

        for field in ["issuer_name", "owner_type", "local_foreign", "nationality", "domicile", "source_url", "published_at"]:
            if current.get(field, "") == "" and row.get(field, "") != "":
                current[field] = row[field]

    return sorted(grouped.values(), key=lambda item: (item["symbol"], -float(item["ownership_pct"] or 0), item["owner_name"]))


def write_csv(path: Path, rows: list[dict[str, str]]) -> None:
    fieldnames = [
        "effective_date",
        "symbol",
        "issuer_name",
        "owner_name",
        "owner_type",
        "local_foreign",
        "nationality",
        "domicile",
        "holdings_scripless",
        "holdings_scrip",
        "total_holding_shares",
        "ownership_shares",
        "ownership_pct",
        "source_url",
        "published_at",
    ]
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def import_sqlite(rows: list[dict[str, str]], source_file: str) -> int:
    conn = sqlite3.connect(DB_PATH)
    conn.execute("PRAGMA busy_timeout = 5000")
    ensure_schema(conn)
    now = datetime.now().isoformat(timespec="seconds")

    with conn:
        effective_dates = sorted({row["effective_date"] for row in rows})
        for effective_date in effective_dates:
            conn.execute("DELETE FROM ownership_positions WHERE effective_date = ?", (effective_date,))
            conn.execute(
                "DELETE FROM ownership_reference WHERE effective_date = ? AND source = ?",
                (effective_date, "idx_ksei_1pct_pdf"),
            )

        for row in rows:
            conn.execute(
                """
                INSERT INTO ownership_positions(
                    symbol, owner_name, effective_date, issuer_name, owner_type, local_foreign,
                    nationality, domicile, holdings_scripless, holdings_scrip, total_holding_shares,
                    ownership_pct, source_url, source_file, published_at, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(symbol, owner_name, effective_date) DO UPDATE SET
                    issuer_name = excluded.issuer_name,
                    owner_type = excluded.owner_type,
                    local_foreign = excluded.local_foreign,
                    nationality = excluded.nationality,
                    domicile = excluded.domicile,
                    holdings_scripless = excluded.holdings_scripless,
                    holdings_scrip = excluded.holdings_scrip,
                    total_holding_shares = excluded.total_holding_shares,
                    ownership_pct = excluded.ownership_pct,
                    source_url = excluded.source_url,
                    source_file = excluded.source_file,
                    published_at = excluded.published_at,
                    updated_at = excluded.updated_at
                """,
                (
                    row["symbol"],
                    row["owner_name"],
                    row["effective_date"],
                    row["issuer_name"],
                    row["owner_type"],
                    row["local_foreign"],
                    row["nationality"],
                    row["domicile"],
                    float(row["holdings_scripless"] or 0),
                    float(row["holdings_scrip"] or 0),
                    float(row["total_holding_shares"] or 0),
                    float(row["ownership_pct"] or 0),
                    row["source_url"],
                    source_file,
                    row["published_at"],
                    now,
                ),
            )
            conn.execute(
                """
                INSERT INTO ownership_reference(
                    symbol, owner_name, owner_type, ownership_pct,
                    ownership_shares, effective_date, source, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(symbol, owner_name, effective_date) DO UPDATE SET
                    owner_type = excluded.owner_type,
                    ownership_pct = excluded.ownership_pct,
                    ownership_shares = excluded.ownership_shares,
                    source = excluded.source,
                    updated_at = excluded.updated_at
                """,
                (
                    row["symbol"],
                    row["owner_name"],
                    row["owner_type"],
                    float(row["ownership_pct"] or 0),
                    float(row["ownership_shares"] or 0),
                    row["effective_date"],
                    "idx_ksei_1pct_pdf",
                    now,
                ),
            )

    conn.close()
    return len(rows)


def ensure_schema(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS ownership_positions (
            symbol TEXT NOT NULL,
            owner_name TEXT NOT NULL,
            effective_date TEXT NOT NULL,
            issuer_name TEXT NOT NULL DEFAULT "",
            owner_type TEXT NOT NULL DEFAULT "",
            local_foreign TEXT NOT NULL DEFAULT "",
            nationality TEXT NOT NULL DEFAULT "",
            domicile TEXT NOT NULL DEFAULT "",
            holdings_scripless REAL NOT NULL DEFAULT 0,
            holdings_scrip REAL NOT NULL DEFAULT 0,
            total_holding_shares REAL NOT NULL DEFAULT 0,
            ownership_pct REAL NOT NULL DEFAULT 0,
            source_url TEXT NOT NULL DEFAULT "",
            source_file TEXT NOT NULL DEFAULT "",
            published_at TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL,
            PRIMARY KEY(symbol, owner_name, effective_date)
        )
        """
    )
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS ownership_reference (
            symbol TEXT NOT NULL,
            owner_name TEXT NOT NULL,
            owner_type TEXT NOT NULL DEFAULT "",
            ownership_pct REAL NOT NULL DEFAULT 0,
            ownership_shares REAL NOT NULL DEFAULT 0,
            effective_date TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL,
            PRIMARY KEY(symbol, owner_name, effective_date)
        )
        """
    )


def http_get(url: str) -> bytes:
    request = Request(
        url,
        headers={
            "User-Agent": "Mozilla/5.0",
            "Accept": "*/*",
            "Referer": IDX_REFERER,
        },
    )
    with urlopen(request, timeout=60) as response:
        return response.read()


def idx_id_url(url: str) -> str:
    return url.replace("https://www.idx.co.id/", "https://www.idx.id/")


def compact_date(value: str) -> str:
    if not value:
        return ""
    return re.sub(r"\D", "", value[:10])


def words_in_range(words: list[tuple[float, float, float, float, str]], start: float, end: float) -> list[tuple[float, float, float, float, str]]:
    return [word for word in words if start <= word[0] < end]


def join_words(words: list[tuple[float, float, float, float, str]]) -> str:
    return normalize_space(" ".join(word[4] for word in sorted(words, key=lambda item: item[0])))


def normalize_space(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def normalize_number(value: str) -> str:
    value = value.strip()
    if value == "":
        return "0"
    return value.replace(".", "").replace(",", ".")


def normalize_local_foreign(value: str) -> str:
    return "L" if value == "D" else value


def is_number_text(value: str) -> bool:
    value = value.strip()
    return bool(re.match(r"^\d{1,3}(?:\.\d{3})*(?:,\d+)?$|^\d+(?:,\d+)?$|^0$", value))


def format_float(value: float) -> str:
    if value.is_integer():
        return str(int(value))
    return f"{value:.8f}".rstrip("0").rstrip(".")


def split_issuer_owner(value: str) -> tuple[str, str]:
    value = normalize_space(value)
    matches = list(re.finditer(r"\bTbk\.?", value))
    if not matches:
        return "", ""

    match = matches[0]
    issuer = normalize_space(value[:match.end()])
    owner = normalize_space(value[match.end():])
    return issuer, owner


def split_owner_type_suffix(value: str) -> tuple[str, str]:
    parts = normalize_space(value).split(" ")
    if len(parts) < 2:
        return value, ""

    suffix = parts[-1].upper()
    if suffix not in INVESTOR_TYPE_CODES:
        return value, ""

    return " ".join(parts[:-1]).strip(), suffix


def normalize_date(value: str) -> str:
    value = value.strip()
    match = re.match(r"^(\d{2})-([A-Za-z]{3})-(\d{4})$", value)
    if not match:
        return value
    day, mon, year = match.groups()
    return f"{year}-{MONTHS.get(mon, mon)}-{day}"


if __name__ == "__main__":
    raise SystemExit(main())
