from __future__ import annotations

import re
import unicodedata

# Common Filipino/Taglish shortcuts and informal spellings seen in typed feedback.
# Exact-token replacement avoids changing letters inside ordinary words.
TOKEN_MAP: dict[str, str] = {
    "nmn": "naman",
    "dba": "di ba",
    "db": "di ba",
    "di": "hindi",
    "ndi": "hindi",
    "hndi": "hindi",
    "hnd": "hindi",
    "kc": "kasi",
    "kse": "kasi",
    "ksa": "kasi",
    "lng": "lang",
    "kht": "kahit",
    "mdami": "marami",
    "dmi": "dami",
    "aq": "ako",
    "q": "ko",
    "sna": "sana",
    "pls": "please",
    "plz": "please",
    "tnx": "thanks",
    "thx": "thanks",
    "ty": "thank you",
    "tyvm": "thank you very much",
    "oks": "okay",
    "okey": "okay",
    "goods": "maganda maayos",
    "solid": "napakaganda maayos",
    "angas": "maganda mahusay",
    "ambait": "ang bait",
    "ambilis": "ang bilis",
    "ambagal": "ang bagal",
    "waley": "wala hindi maganda",
    "badtrip": "nakakainis",
    "dedma": "hindi pinapansin",
    "meh": "katamtaman",
    "mid": "katamtaman",
    "keri": "kaya okay",
    "fr": "for real",
}

PHRASE_MAP: tuple[tuple[str, str], ...] = (
    (r"\bno\s+hassle\b", "walang abala"),
    (r"\bpabalik[\s-]*balik\b", "paulit ulit na pagbalik"),
    (r"\bpaulit[\s-]*ulit\b", "paulit ulit"),
    (r"\bso[\s-]*so\b", "katamtaman"),
    (r"\bnot\s+bad\b", "hindi masama"),
    (r"\bpwede\s+na\b", "katamtaman sapat"),
    (r"\bsakto\s+lang\b", "katamtaman"),
)

# Frequent elongated forms. These are normalized before the generic repeat rule.
ELONGATED_MAP: dict[str, str] = {
    "bagaaal": "bagal",
    "bagaaaal": "bagal",
    "tagaaal": "tagal",
    "tagaaaal": "tagal",
    "sobranggg": "sobrang",
    "bilisss": "bilis",
    "ayooos": "ayos",
    "gandaaa": "ganda",
    "pangettt": "pangit",
}


def normalize_text(text: str) -> str:
    """Normalize informal Filipino/Taglish typing while preserving meaning."""
    value = unicodedata.normalize("NFKC", str(text)).lower().strip()
    value = value.replace("’", "'")

    for pattern, replacement in PHRASE_MAP:
        value = re.sub(pattern, replacement, value, flags=re.IGNORECASE)

    tokens = re.findall(r"[a-z0-9ñ']+|[^a-z0-9ñ'\s]+", value, flags=re.IGNORECASE)
    normalized_tokens: list[str] = []
    for token in tokens:
        key = token.lower()
        if key in ELONGATED_MAP:
            normalized_tokens.append(ELONGATED_MAP[key])
            continue
        if re.fullmatch(r"[a-z0-9ñ']+", key, flags=re.IGNORECASE):
            key = TOKEN_MAP.get(key, key)
            # Reduce excessive repeated characters while keeping natural doubles.
            key = re.sub(r"([a-zñ])\1{2,}", r"\1\1", key)
        normalized_tokens.append(key)

    value = " ".join(normalized_tokens)
    value = re.sub(r"\s+([,.!?;:])", r"\1", value)
    value = re.sub(r"\s+", " ", value).strip()
    return value


def normalize_for_model(text: str) -> str:
    """Keep raw slang signals and append a normalized interpretation."""
    raw = unicodedata.normalize("NFKC", str(text)).lower().strip()
    normalized = normalize_text(raw)
    if not normalized or normalized == raw:
        return raw
    return f"{raw} normalized {normalized}"
