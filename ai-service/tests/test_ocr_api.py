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

    def make_pdf(self, text: str | None = None) -> bytes:
        document = fitz.open()
        page = document.new_page()
        page.insert_text(
            (72, 72),
            text or "ZESCO MEMORANDUM\nReference: OMTS-2026-0001\nDate: 2026-01-15\nSubject: Request for office records",
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

    def test_extracts_unlabelled_memo_heading_and_printed_metadata(self) -> None:
        suggestions = make_suggestions(
            "ZESCO\nMEMORANDUM\nTO:Chief Operating Officer\nFROMHead-S&BD\n"
            "REF:S&BD/465/2026\nDATE:19 August2026\n"
            "AUTHORITY TO TRAVEL TO LIVINGSTONE FOR A BREAKAWAY MEETING FOR\n"
            "THE DEVELOPMENT OF THE STRATEGIC IMPLEMENTATION MANAGEMENT\n"
            "SYSTEM\nThe above subject matter refers.\n"
            "The Strategy Unit is mandated to report quarterly."
        )
        self.assertEqual(suggestions.reference_number, "S&BD/465/2026")
        self.assertEqual(suggestions.dates, ["19 August 2026"])
        self.assertEqual(
            suggestions.suggested_subject,
            "AUTHORITY TO TRAVEL TO LIVINGSTONE FOR A BREAKAWAY MEETING FOR "
            "THE DEVELOPMENT OF THE STRATEGIC IMPLEMENTATION MANAGEMENT SYSTEM",
        )

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

    def test_handles_reference_labels_and_spacing(self) -> None:
        for label in ["REF:", "Ref No.:", "Reference number:"]:
            with self.subTest(label=label):
                result = make_suggestions(f"{label} S&BD / 465 / 2026\nSubject: Field visit")
                self.assertEqual(result.reference_number, "S&BD/465/2026")
                self.assertEqual(result.suggested_subject, "Field visit")

    def test_handles_written_dates_without_losing_numeric_dates(self) -> None:
        for value, expected in [
            ("19 August2026", "19 August 2026"), ("17Nov2026", "17 Nov 2026"),
            ("1st September 2026", "1 September 2026"),
            ("2026-11-17", "2026-11-17"), ("17/11/2026", "17/11/2026"),
        ]:
            with self.subTest(value=value):
                result = make_suggestions(f"DATE:{value}\nSubject: Travel request\nMeeting on 25/11/2026.")
                self.assertEqual(result.dates, [expected])

    def test_selects_document_date_instead_of_dates_in_the_body(self) -> None:
        result = make_suggestions(
            "ZESCO\nREF:S&BD/465/2026\nDATE:19 August2026\n"
            "AUTHORITY TO TRAVEL\nThe meeting runs from 20/08/2026 to 24/08/2026.\n"
            "Please respond by 18/08/2026."
        )
        self.assertEqual(result.dates, ["19 August 2026"])

    def test_does_not_guess_from_body_dates_or_due_dates(self) -> None:
        for text in [
            "Subject: Travel request\nThe meeting is on 20/08/2026.",
            "Due date: 20/08/2026\nSubject: Travel request",
            "DATE: unreadable\nSubject: Travel request\n20/08/2026",
        ]:
            with self.subTest(text=text):
                self.assertEqual(make_suggestions(text).dates, [])

    def test_handles_header_date_on_next_line_and_flattened_headers(self) -> None:
        for text in [
            "ZESCO\nDocument date:\n19 August2026\nSubject: Travel request",
            "ZESCO MEMORANDUM Reference: OMTS-2026-0001 Date: 19 August2026 Subject: Travel on 20/08/2026",
            "ZESCO\n19 August2026\nSubject: Travel request\nMeeting on 20/08/2026.",
        ]:
            with self.subTest(text=text):
                self.assertEqual(make_suggestions(text).dates, ["19 August 2026"])

    def test_conflicting_header_dates_require_manual_review(self) -> None:
        self.assertEqual(make_suggestions("Date: 19 August2026\nDate: 20 August2026\nSubject: Travel").dates, [])

    def test_does_not_use_letterhead_or_body_prose_as_a_subject(self) -> None:
        for text in ["", "ZESCO\nMEMORANDUM", "ZESCO\nDATE:19 August2026\nPlease review the attached papers."]:
            with self.subTest(text=text):
                self.assertIsNone(make_suggestions(text).suggested_subject)

    def test_explicit_subject_takes_priority_and_keeps_wrapped_heading(self) -> None:
        result = make_suggestions(
            "AN UNRELATED HEADING\nSUBJECT: AUTHORITY TO TRAVEL\n"
            "FOR THE ANNUAL REVIEW\nThe above subject matter refers."
        )
        self.assertEqual(result.suggested_subject, "AUTHORITY TO TRAVEL FOR THE ANNUAL REVIEW")

    def test_api_returns_printed_reference_date_and_heading(self) -> None:
        response = self.client.post(
            "/api/ocr",
            headers={"X-Pdts-Ai-Token": expected_service_token()},
            files={"file": ("memo.pdf", self.make_pdf(
                "ZESCO\nMEMORANDUM\nREF:S&BD/465/2026\nDATE:19 August2026\n"
                "AUTHORITY TO TRAVEL\nFOR THE ANNUAL REVIEW\nThe above subject matter refers."
            ), "application/pdf")},
        )
        self.assertEqual(response.status_code, 200)
        suggestions = response.json()["suggestions"]
        self.assertEqual(suggestions["reference_number"], "S&BD/465/2026")
        self.assertEqual(suggestions["dates"], ["19 August 2026"])
        self.assertEqual(suggestions["suggested_subject"], "AUTHORITY TO TRAVEL FOR THE ANNUAL REVIEW")


if __name__ == "__main__":
    unittest.main()
