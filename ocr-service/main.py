"""
OCR Bookkeeping Service - FastAPI Application

Extracts structured financial data (invoices, receipts) from uploaded documents
using Tesseract OCR + regex-based field extraction.

Endpoints:
  POST /api/bookkeeping/upload - Extract data from uploaded file
  GET  /health                 - Health check
"""

import io
import re
import tempfile
from datetime import datetime
from pathlib import Path

import pytesseract
from fastapi import FastAPI, File, UploadFile, HTTPException
from PIL import Image

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
    raw_text = extract_text_from_image(contents, file.filename or "document.jpg")

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

    # Get OCR confidence from Tesseract
    try:
        image = Image.open(io.BytesIO(contents)) if not (file.filename or "").lower().endswith(".pdf") else None
        if image:
            data = pytesseract.image_to_data(image, output_type=pytesseract.Output.DICT)
            confidences = [int(c) for c in data["conf"] if int(c) > 0]
            avg_confidence = sum(confidences) / len(confidences) if confidences else 50
        else:
            avg_confidence = 75  # default for PDFs
    except Exception:
        avg_confidence = 50

    # Parse structured data
    extracted = parse_financial_data(raw_text)

    return {
        "status": "success",
        "extracted_data": extracted,
        "processing_details": {
            "ocr_confidence": round(avg_confidence, 1),
            "raw_text": raw_text[:2000],  # truncate for response size
            "char_count": len(raw_text),
        },
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=5050)
