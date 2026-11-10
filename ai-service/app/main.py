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
DATE_PATTERN = re.compile(r"\b(?:\d{4}-\d{2}-\d{2}|\d{1,2}[/-]\d{1,2}[/-]\d{2,4})\b")
SUBJECT_PATTERN = re.compile(
    r"(?i)\b(?:subject|re)\s*[:\-]\s*(.+?)(?=\s+(?:reference|date|from|to)\s*[:\-]|\r?\n|$)"
)

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


def make_suggestions(text: str) -> OcrSuggestions:
    reference_match = REFERENCE_PATTERN.search(text)
    subject_match = SUBJECT_PATTERN.search(text)
    first_line = next((line.strip() for line in text.splitlines() if line.strip()), None)
    dates = list(dict.fromkeys(DATE_PATTERN.findall(text)))

    return OcrSuggestions(
        reference_number=reference_match.group(0) if reference_match else None,
        dates=dates[:20],
        suggested_subject=(subject_match.group(1).strip() if subject_match else first_line[:240] if first_line else None),
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
