from __future__ import annotations

import hmac
import io
import os
import re
from pathlib import Path
from statistics import mean
from typing import Literal

import fitz
import numpy as np
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, File, Header, HTTPException, UploadFile
from PIL import Image, UnidentifiedImageError
from pydantic import BaseModel

ROOT = Path(__file__).resolve().parents[2]
load_dotenv(ROOT / ".env")

MAX_UPLOAD_BYTES = 20 * 1024 * 1024
MAX_PDF_PAGES = 50
MAX_IMAGE_PIXELS = 40_000_000
RENDER_SCALE = 1.8
ALLOWED_EXTENSIONS = {".pdf", ".png", ".jpg", ".jpeg", ".tif", ".tiff"}
REFERENCE_PATTERN = re.compile(r"\b[A-Z]{2,8}-\d{4}-\d{3,}\b", re.IGNORECASE)
LABELLED_REFERENCE_PATTERN = re.compile(
    r"\b(?:ref(?:erence)?)(?:[ \t]*(?:no\.?|number))?[ \t]*[:\-][ \t]*"
    r"([A-Z0-9][A-Z0-9&._-]*(?:[ \t]*/[ \t]*[A-Z0-9][A-Z0-9&._-]*)*)",
    re.IGNORECASE,
)
DATE_PATTERN = re.compile(
    r"(?<![\w/])(?:\d{4}-\d{2}-\d{2}|\d{1,2}[/-]\d{1,2}[/-]\d{2,4}|"
    r"(?P<day>\d{1,2})(?:st|nd|rd|th)?[ \t]*"
    r"(?P<month>Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|"
    r"Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)"
    r"\.?[ \t]*(?P<year>\d{4}))(?!\w)",
    re.IGNORECASE,
)
SUBJECT_PATTERN = re.compile(
    r"(?i)\b(?:subject|re)\s*[:\-]\s*(.+?)(?=\s+(?:ref(?:erence)?|date|from|to)\s*[:\-]|\r?\n|$)"
)
HEADER_PATTERN = re.compile(r"^(?:to|from|ref(?:erence)?(?:\s+(?:no\.?|number))?|date)\s*[:\-]", re.IGNORECASE)
DATE_LABEL_PATTERN = re.compile(r"\b(?:document[ \t]+)?date[ \t]*[:\-][ \t]*", re.IGNORECASE)
DOCUMENT_LABELS = {"ZESCO", "ZESCO LIMITED", "ZESCO LTD", "MEMORANDUM", "INTERNAL MEMORANDUM", "MEMO"}

app = FastAPI(title="PDTS Document Intelligence", version="0.1.0")
_ocr_engine = None


class PageResult(BaseModel):
    page: int
    text: str
    source: Literal["embedded_text", "ocr"]
    confidence: float | None = None


class OcrSuggestions(BaseModel):
    reference_number: str | None = None
    dates: list[str]
    suggested_subject: str | None = None


class OcrResponse(BaseModel):
    filename: str
    page_count: int
    text: str
    pages: list[PageResult]
    suggestions: OcrSuggestions
    review_notice: str = "Extracted values are suggestions. Verify them before using them in PDTS."


def expected_service_token() -> str:
    return os.getenv("AI_SERVICE_TOKEN") or os.getenv("APP_KEY", "")


def require_service_token(x_pdts_ai_token: str | None = Header(default=None)) -> None:
    expected = expected_service_token()
    if not expected or not x_pdts_ai_token or not hmac.compare_digest(x_pdts_ai_token, expected):
        raise HTTPException(status_code=401, detail="Invalid service credentials.")


def get_ocr_engine():
    global _ocr_engine
    if _ocr_engine is None:
        from paddleocr import PaddleOCR

        _ocr_engine = PaddleOCR(use_angle_cls=True, lang="en", show_log=False)
    return _ocr_engine


def recognize_image(image: Image.Image) -> tuple[str, float | None]:
    result = get_ocr_engine().ocr(np.asarray(image.convert("RGB")), cls=True)
    lines: list[str] = []
    confidences: list[float] = []

    for page_result in result or []:
        for item in page_result or []:
            if len(item) < 2 or not item[1]:
                continue
            text, confidence = item[1]
            if text:
                lines.append(str(text).strip())
                confidences.append(float(confidence))

    return "\n".join(lines), mean(confidences) if confidences else None


def extract_image(payload: bytes) -> list[PageResult]:
    try:
        with Image.open(io.BytesIO(payload)) as image:
            if image.width * image.height > MAX_IMAGE_PIXELS:
                raise HTTPException(status_code=413, detail="Image dimensions exceed the OCR limit.")
            text, confidence = recognize_image(image)
    except HTTPException:
        raise
    except Image.DecompressionBombError as exc:
        raise HTTPException(status_code=413, detail="Image dimensions exceed the OCR limit.") from exc
    except (UnidentifiedImageError, OSError) as exc:
        raise HTTPException(status_code=422, detail="The uploaded image could not be decoded.") from exc

    return [PageResult(page=1, text=text, source="ocr", confidence=confidence)]


def extract_pdf(payload: bytes) -> list[PageResult]:
    try:
        document = fitz.open(stream=payload, filetype="pdf")
    except Exception as exc:
        raise HTTPException(status_code=422, detail="The uploaded PDF could not be opened.") from exc

    if document.is_encrypted:
        raise HTTPException(status_code=422, detail="Password-protected PDFs are not supported.")
    if len(document) > MAX_PDF_PAGES:
        raise HTTPException(status_code=413, detail=f"PDFs are limited to {MAX_PDF_PAGES} pages.")

    pages: list[PageResult] = []
    try:
        for index, page in enumerate(document, start=1):
            embedded_text = page.get_text("text").strip()
            if embedded_text:
                pages.append(PageResult(page=index, text=embedded_text, source="embedded_text"))
                continue

            render_width = page.rect.width * RENDER_SCALE
            render_height = page.rect.height * RENDER_SCALE
            if render_width * render_height > MAX_IMAGE_PIXELS:
                raise HTTPException(status_code=413, detail=f"Page {index} dimensions exceed the OCR limit.")

            pixmap = page.get_pixmap(matrix=fitz.Matrix(RENDER_SCALE, RENDER_SCALE), alpha=False)
            with Image.open(io.BytesIO(pixmap.tobytes("png"))) as image:
                text, confidence = recognize_image(image)
            pages.append(PageResult(page=index, text=text, source="ocr", confidence=confidence))
    finally:
        document.close()

    return pages


def suggest_subject(text: str) -> str | None:
    subject_match = SUBJECT_PATTERN.search(text)
    if subject_match:
        parts = [subject_match.group(1).strip()]
        remaining = text[subject_match.end():].splitlines()
    else:
        lines = [line.strip() for line in text.splitlines() if line.strip()][:40]
        header_positions = [index for index, line in enumerate(lines) if HEADER_PATTERN.match(line)]
        start = header_positions[-1] + 1 if header_positions else 0
        parts = []
        remaining = []
        for index in range(start, len(lines)):
            line = lines[index]
            if line.upper() in DOCUMENT_LABELS:
                continue
            if line.isupper() and len(line.split()) >= 3:
                parts = [line]
                remaining = lines[index + 1:]
                break
            if not line.isupper():
                break

    if not parts:
        return None

    for line in remaining:
        line = line.strip()
        if not line:
            continue
        if not line.isupper() or HEADER_PATTERN.match(line) or line in DOCUMENT_LABELS:
            break
        parts.append(line)

    return " ".join(parts)[:255]


def suggest_document_date(text: str) -> list[str]:
    subject = suggest_subject(text)
    header_lines = []
    for line in text.splitlines()[:30]:
        labelled_subject = SUBJECT_PATTERN.search(line)
        if labelled_subject:
            header_lines.append(line[:labelled_subject.start()].strip())
            break
        if subject and line.strip() and subject.startswith(line.strip()):
            break
        header_lines.append(line.strip())

    labelled_dates = []
    standalone_dates = []
    has_date_label = False
    for index, line in enumerate(header_lines):
        label = DATE_LABEL_PATTERN.search(line)
        if label:
            prefix = line[:label.start()].strip()
            if prefix and (
                not re.search(r"\b(?:ref(?:erence)?|from|to)\s*[:\-]", prefix, re.IGNORECASE)
                or re.search(r"\b(?:due|expiry|expiration|meeting|travel|delivery|return|closing|effective)\s*$", prefix, re.IGNORECASE)
            ):
                continue
            has_date_label = True
            value = line[label.end():].strip()
            if not value and index + 1 < len(header_lines):
                value = header_lines[index + 1]
            value = re.split(r"\s+(?:ref(?:erence)?|from|to)\s*[:\-]", value, maxsplit=1, flags=re.IGNORECASE)[0].strip()
            match = DATE_PATTERN.fullmatch(value)
            if not match:
                return []
            labelled_dates.append(match)
        elif index < 12:
            match = DATE_PATTERN.fullmatch(line)
            if match:
                standalone_dates.append(match)

    candidates = labelled_dates if has_date_label else standalone_dates
    dates = list(dict.fromkeys(
        f"{match.group('day')} {match.group('month')} {match.group('year')}"
        if match.group('month') else match.group(0)
        for match in candidates
    ))
    return dates if len(dates) == 1 else []


def make_suggestions(text: str) -> OcrSuggestions:
    labelled_reference = LABELLED_REFERENCE_PATTERN.search(text)
    reference_match = REFERENCE_PATTERN.search(text)
    reference = reference_match.group(0) if reference_match else None
    if labelled_reference and any(character.isdigit() for character in labelled_reference.group(1)):
        reference = re.sub(r"\s*/\s*", "/", labelled_reference.group(1))

    return OcrSuggestions(
        reference_number=reference,
        dates=suggest_document_date(text),
        suggested_subject=suggest_subject(text),
    )


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "service": "pdts-ai"}


@app.post("/api/ocr", response_model=OcrResponse, dependencies=[Depends(require_service_token)])
async def ocr_document(file: UploadFile = File(...)) -> OcrResponse:
    filename = Path(file.filename or "upload").name
    extension = Path(filename).suffix.lower()
    if extension not in ALLOWED_EXTENSIONS:
        raise HTTPException(status_code=415, detail="Upload a PDF, PNG, JPEG, or TIFF file.")

    payload = await file.read(MAX_UPLOAD_BYTES + 1)
    if len(payload) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="Files are limited to 20 MB.")
    if not payload:
        raise HTTPException(status_code=422, detail="The uploaded file is empty.")

    pages = extract_pdf(payload) if extension == ".pdf" else extract_image(payload)
    text = "\n\n".join(page.text for page in pages if page.text)

    return OcrResponse(
        filename=filename,
        page_count=len(pages),
        text=text,
        pages=pages,
        suggestions=make_suggestions(text),
    )
