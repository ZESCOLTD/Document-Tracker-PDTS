from __future__ import annotations

import sys
import unittest
from pathlib import Path

import fitz
from dotenv import load_dotenv
from fastapi.testclient import TestClient

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai-service"))
load_dotenv(ROOT / ".env")

from app.main import app, expected_service_token, make_suggestions


class OcrApiTests(unittest.TestCase):
    def setUp(self) -> None:
        self.client = TestClient(app)

    def make_pdf(self) -> bytes:
        document = fitz.open()
        page = document.new_page()
        page.insert_text(
            (72, 72),
            "ZESCO MEMORANDUM\nReference: OMTS-2026-0001\nDate: 2026-01-15\nSubject: Request for office records",
        )
        content = document.tobytes()
        document.close()
        return content

    def test_requires_service_token(self) -> None:
        response = self.client.post(
            "/api/ocr",
            files={"file": ("sample.pdf", self.make_pdf(), "application/pdf")},
        )
        self.assertEqual(response.status_code, 401)

    def test_suggested_subject_handles_flattened_ocr_text(self) -> None:
        suggestions = make_suggestions(
            "ZESCO MEMORANDUM Reference: OMTS-2026-0001 Date: 2026-11-09 Subject: Field inspection report"
        )
        self.assertEqual(suggestions.suggested_subject, "Field inspection report")

    def test_extracts_embedded_pdf_text_and_suggestions(self) -> None:
        token = expected_service_token()
        self.assertTrue(token, "Set APP_KEY or AI_SERVICE_TOKEN in the project .env")

        response = self.client.post(
            "/api/ocr",
            headers={"X-Pdts-Ai-Token": token},
            files={"file": ("sample.pdf", self.make_pdf(), "application/pdf")},
        )

        self.assertEqual(response.status_code, 200, response.text)
        result = response.json()
        self.assertIn("Request for office records", result["text"])
        self.assertEqual(result["suggestions"]["reference_number"], "OMTS-2026-0001")
        self.assertEqual(result["suggestions"]["dates"], ["2026-01-15"])
        self.assertEqual(result["suggestions"]["suggested_subject"], "Request for office records")
        self.assertEqual(result["pages"][0]["source"], "embedded_text")


if __name__ == "__main__":
    unittest.main()
