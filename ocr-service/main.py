"""
OCR Bookkeeping Service - FastAPI Application

Extracts structured financial data (invoices, receipts) from uploaded documents
using Tesseract OCR or PaddleOCR + regex-based field extraction.

Endpoints:
  POST /api/bookkeeping/upload - Extract data from uploaded file
  GET  /health                 - Health check

Environment variables:
    OCR_ENGINE  - "tesseract" (default) or "paddle"
"""

# Must be set before PaddlePaddle is imported.
import os as _os

_os.environ.setdefault("FLAGS_enable_pir_api", "0")
_os.environ.setdefault("FLAGS_use_mkldnn", "0")
_os.environ.setdefault("PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK", "True")

# Help Tesseract find its language data when it is installed in a non-standard path.
if not _os.environ.get("TESSDATA_PREFIX"):
        _candidate = _os.path.join(_os.environ.get("HOME", ""), "tessdata")
        if _os.path.isfile(_os.path.join(_candidate, "eng.traineddata")):
                _os.environ["TESSDATA_PREFIX"] = _candidate

import io
import os
import re
import tempfile
from datetime import datetime
from pathlib import Path

import pytesseract
from fastapi import FastAPI, File, UploadFile, HTTPException
from PIL import Image

OCR_ENGINE = os.environ.get("OCR_ENGINE", "tesseract").lower()
_paddle_ocr = None
_paddle_patched = False

app = FastAPI(title="OCR Bookkeeping Service", version="1.0.0")


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def extract_text_from_image(image_bytes: bytes, filename: str) -> str:
    """Run Tesseract OCR on an image or PDF and return the raw text."""
    lower = filename.lower()

    if lower.endswith(".pdf"):
        try:
            from pdf2image import convert_from_bytes
            pages = convert_from_bytes(image_bytes, dpi=300)
            texts = [pytesseract.image_to_string(page) for page in pages]
            return "\n".join(texts)
        except Exception as e:
            raise HTTPException(status_code=422, detail=f"PDF conversion failed: {e}")

    image = Image.open(io.BytesIO(image_bytes))
    return pytesseract.image_to_string(image)


def _patch_paddle_inference() -> None:
    """Patch Paddle inference settings to avoid known runtime issues."""
    global _paddle_patched

    if _paddle_patched:
        return

    _paddle_patched = True

    try:
        import importlib

        paddle = importlib.import_module("paddle")
        paddle_inference = importlib.import_module("paddle.inference")

        paddle.set_flags({
            "FLAGS_enable_pir_api": False,
            "FLAGS_enable_pir_in_executor": False,
            "FLAGS_use_mkldnn": False,
        })

        original_create = paddle_inference.create_predictor

        def safe_create(config):
            if hasattr(config, "enable_new_ir"):
                config.enable_new_ir(False)
            if hasattr(config, "disable_mkldnn"):
                config.disable_mkldnn()
            return original_create(config)

        paddle_inference.create_predictor = safe_create
    except Exception:
        pass


def _get_paddle_ocr():
    """Return a singleton PaddleOCR instance, created on first call."""
    global _paddle_ocr

    if _paddle_ocr is None:
        _patch_paddle_inference()

        import importlib.metadata as metadata

        original_requires = metadata.requires

        def safe_requires(package_name: str):
            requirements = original_requires(package_name)
            return requirements if requirements is not None else []

        metadata.requires = safe_requires

        import importlib

        PaddleOCR = importlib.import_module("paddleocr").PaddleOCR

        try:
            _paddle_ocr = PaddleOCR(use_angle_cls=True, lang="en")
        except TypeError:
            _paddle_ocr = PaddleOCR(lang="en")

    return _paddle_ocr


def extract_text_with_paddle(image_bytes: bytes, filename: str) -> tuple[str, float]:
    """Run PaddleOCR on an image or PDF and return raw text with average confidence."""
    ocr = _get_paddle_ocr()
    lower = filename.lower()

    def ocr_image(image: Image.Image) -> list[tuple[str, float]]:
        import tempfile as tempfiles

        with tempfiles.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
            image.save(tmp, format="PNG")
            tmp_path = tmp.name

        lines: list[tuple[float, str, float]] = []

        try:
            for result in ocr.predict(tmp_path):
                rec_texts = result.get("rec_texts", []) if isinstance(result, dict) else getattr(result, "rec_texts", [])
                rec_scores = result.get("rec_scores", []) if isinstance(result, dict) else getattr(result, "rec_scores", [])
                dt_polys = result.get("dt_polys", []) if isinstance(result, dict) else getattr(result, "dt_polys", [])

                for index, text in enumerate(rec_texts):
                    confidence = float(rec_scores[index]) if index < len(rec_scores) else 0.0
                    if index < len(dt_polys) and len(dt_polys[index]) > 0:
                        y_top = float(min(point[1] for point in dt_polys[index]))
                    else:
                        y_top = float(index)
                    lines.append((y_top, text, confidence))
        except Exception:
            pass
        finally:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass

        lines.sort(key=lambda item: item[0])
        return [(text, confidence) for _, text, confidence in lines]

    all_lines: list[tuple[str, float]] = []

    if lower.endswith(".pdf"):
        try:
            from pdf2image import convert_from_bytes

            pages = convert_from_bytes(image_bytes, dpi=300)
            for page in pages:
                all_lines.extend(ocr_image(page))
        except Exception as e:
            raise HTTPException(status_code=422, detail=f"PDF conversion failed: {e}")
    else:
        image = Image.open(io.BytesIO(image_bytes))
        all_lines = ocr_image(image)

    if not all_lines:
        return "", 0.0

    raw_text = "\n".join(text for text, _ in all_lines)
    avg_confidence = sum(confidence for _, confidence in all_lines) / len(all_lines) * 100
    return raw_text, round(avg_confidence, 1)


def run_ocr(image_bytes: bytes, filename: str, engine: str | None = None) -> tuple[str, float]:
    """Unified OCR entry point returning raw text and a 0-100 confidence score."""
    selected_engine = (engine or OCR_ENGINE).lower()

    if selected_engine == "paddle":
        return extract_text_with_paddle(image_bytes, filename)

    raw_text = extract_text_from_image(image_bytes, filename)
    avg_confidence = 75.0

    if not filename.lower().endswith(".pdf"):
        try:
            image = Image.open(io.BytesIO(image_bytes))
            data = pytesseract.image_to_data(image, output_type=pytesseract.Output.DICT)
            confidences = [int(confidence) for confidence in data["conf"] if int(confidence) > 0]
            if confidences:
                avg_confidence = sum(confidences) / len(confidences)
        except Exception:
            avg_confidence = 50.0

    return raw_text, round(avg_confidence, 1)


def parse_financial_data(raw_text: str) -> dict:
    """Parse structured financial fields from raw OCR text using regexes."""

    lines = raw_text.split("\n")
    text_lower = raw_text.lower()

    # --- Vendor / Customer Name ---
    vendor_name = None
    # Common heuristic: first non-empty line that isn't a date or number
    for line in lines:
        stripped = line.strip()
        if not stripped:
            continue
        # skip lines that look like dates or pure numbers
        if re.match(r"^\d{1,4}[/\-]\d{1,2}[/\-]\d{1,4}$", stripped):
            continue
        if re.match(r"^[\d\s.,$]+$", stripped):
            continue
        vendor_name = stripped
        break

    # Try explicit patterns
    for pattern in [
        r"(?:from|vendor|supplier|company|bill\s*to)[:\s]*(.+)",
        r"^([A-Z][A-Za-z &.,]+(?:Ltd|LLC|Inc|Corp|Co|Pty|Pvt|Limited|Sdn|Bhd)?\.?)\s*$",
    ]:
        m = re.search(pattern, raw_text, re.IGNORECASE | re.MULTILINE)
        if m:
            vendor_name = m.group(1).strip()
            break

    # --- Invoice Number ---
    invoice_number = None
    for pattern in [
        r"(?:invoice|inv|bill|receipt)\s*(?:#|no\.?|number)?[:\s]*([A-Za-z0-9\-/]+)",
        r"(?:ref(?:erence)?|doc)\s*(?:#|no\.?)?[:\s]*([A-Za-z0-9\-/]+)",
    ]:
        m = re.search(pattern, raw_text, re.IGNORECASE)
        if m:
            invoice_number = m.group(1).strip()
            break

    # --- Invoice Date ---
    invoice_date = None
    for pattern in [
        r"(?:date|dated|invoice\s*date|bill\s*date)[:\s]*([\d]{1,4}[/\-.][\d]{1,2}[/\-.][\d]{1,4})",
        r"(\d{1,2}[/\-]\d{1,2}[/\-]\d{2,4})",
        r"(\d{4}[/\-]\d{1,2}[/\-]\d{1,2})",
        r"(\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{2,4})",
    ]:
        m = re.search(pattern, raw_text, re.IGNORECASE)
        if m:
            raw_date = m.group(1).strip()
            for fmt in (
                "%d/%m/%Y", "%m/%d/%Y", "%Y-%m-%d", "%d-%m-%Y",
                "%m-%d-%Y", "%d.%m.%Y", "%d %B %Y", "%d %b %Y",
                "%d/%m/%y", "%m/%d/%y",
            ):
                try:
                    invoice_date = datetime.strptime(raw_date, fmt).strftime("%Y-%m-%d")
                    break
                except ValueError:
                    continue
            if invoice_date:
                break

    # --- Currency ---
    currency = "USD"
    currency_map = {
        "rm": "MYR", "myr": "MYR",
        "sgd": "SGD", "s$": "SGD",
        "$": "USD", "usd": "USD",
        "€": "EUR", "eur": "EUR",
        "£": "GBP", "gbp": "GBP",
        "¥": "JPY", "jpy": "JPY",
        "₹": "INR", "inr": "INR",
        "php": "PHP", "₱": "PHP",
    }
    for token, code in currency_map.items():
        if token in text_lower:
            currency = code
            break

    # --- Amounts ---
    def find_amount(keywords: list[str]) -> float | None:
        for kw in keywords:
            pattern = rf"{kw}\s*[:\s]*[\$€£¥₹RM]*\s*([\d,]+\.?\d*)"
            m = re.search(pattern, raw_text, re.IGNORECASE)
            if m:
                try:
                    return float(m.group(1).replace(",", ""))
                except ValueError:
                    continue
        return None

    total_amount = find_amount(["total", "amount due", "grand total", "balance due", "total due"])
    subtotal = find_amount(["subtotal", "sub-total", "sub total"])
    tax_amount = find_amount(["tax", "vat", "gst", "sst", "sales tax"])

    # Fallback: find the largest currency amount in the text
    if total_amount is None:
        amounts = re.findall(r"[\$€£¥₹]?\s*([\d,]+\.\d{2})", raw_text)
        if amounts:
            parsed = [float(a.replace(",", "")) for a in amounts]
            total_amount = max(parsed)

    # --- Line Items ---
    line_items = []
    # Pattern: description  qty  unit_price  amount  (flexible)
    item_pattern = re.compile(
        r"^(.{3,50}?)\s+(\d+)\s+[\$€£¥₹RM]*\s*([\d,]+\.?\d*)\s+[\$€£¥₹RM]*\s*([\d,]+\.?\d*)\s*$",
        re.MULTILINE,
    )
    for m in item_pattern.finditer(raw_text):
        description = m.group(1).strip()
        # Skip header rows
        if any(h in description.lower() for h in ("description", "item", "qty", "quantity", "price", "amount")):
            continue
        try:
            line_items.append({
                "description": description,
                "quantity": int(m.group(2)),
                "unit_price": float(m.group(3).replace(",", "")),
                "amount": float(m.group(4).replace(",", "")),
            })
        except ValueError:
            continue

    # If no line items found, create a single catch-all item
    if not line_items and total_amount:
        line_items.append({
            "description": "Item from document",
            "quantity": 1,
            "unit_price": total_amount,
            "amount": total_amount,
        })

    return {
        "vendor_customer_name": vendor_name or "Unknown",
        "invoice_date": invoice_date,
        "invoice_number": invoice_number,
        "currency": currency,
        "line_items": line_items,
        "subtotal": subtotal or (total_amount - (tax_amount or 0) if total_amount else None),
        "tax_amount": tax_amount or 0,
        "total_amount": total_amount or 0,
    }


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@app.get("/health")
async def health():
    """Health check endpoint."""
    return {"status": "healthy", "service": "ocr-bookkeeping", "version": "1.0.0"}


@app.post("/api/bookkeeping/upload")
async def upload_document(file: UploadFile = File(...)):
    """
    Accept a document upload (image or PDF), run OCR, and return
    structured financial data.
    """
    allowed_types = {
        "image/jpeg", "image/png", "image/jpg",
        "application/pdf",
    }
    if file.content_type and file.content_type not in allowed_types:
        raise HTTPException(
            status_code=400,
            detail=f"Unsupported file type: {file.content_type}. Allowed: JPG, PNG, PDF",
        )

    contents = await file.read()
    if not contents:
        raise HTTPException(status_code=400, detail="Empty file")

    # Run OCR
    raw_text, avg_confidence = run_ocr(contents, file.filename or "document.jpg")

    if not raw_text.strip():
        return {
            "status": "success",
            "extracted_data": {
                "vendor_customer_name": "Unknown",
                "invoice_date": None,
                "invoice_number": None,
                "currency": "USD",
                "line_items": [],
                "subtotal": 0,
                "tax_amount": 0,
                "total_amount": 0,
            },
            "processing_details": {
                "ocr_confidence": 0,
                "raw_text": "",
                "processing_time_ms": 0,
            },
        }

    # Parse structured data
    extracted = parse_financial_data(raw_text)

    return {
        "status": "success",
        "extracted_data": extracted,
        "processing_details": {
            "ocr_confidence": avg_confidence,
            "raw_text": raw_text[:2000],  # truncate for response size
            "char_count": len(raw_text),
            "engine": OCR_ENGINE,
        },
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=5050)
