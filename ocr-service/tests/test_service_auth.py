"""
Issue #174 regression tests — the OCR microservice must authenticate every
data request, cap upload size independently of the network, and fail closed
when no shared secret is configured.

Run with:
    .venv/bin/pip install -r requirements.txt -r requirements-dev.txt
    .venv/bin/python -m pytest tests/ -q
"""

import os
import sys

import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import main  # noqa: E402

SERVICE_KEY = "test-ocr-shared-secret"


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv("OCR_SERVICE_KEY", SERVICE_KEY)
    # Keep the test independent of a real OCR engine.
    monkeypatch.setattr(main, "run_ocr", lambda contents, filename: ("Total 10.00", 95.0))
    return TestClient(main.app)


def _post(client, headers=None, content=b"fake-image-bytes"):
    return client.post(
        "/api/bookkeeping/upload",
        files={"file": ("doc.jpg", content, "image/jpeg")},
        headers=headers or {},
    )


def test_health_is_open_without_a_key(client):
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json()["status"] == "healthy"


def test_upload_without_key_is_rejected(client):
    response = _post(client)
    assert response.status_code == 401
    assert "X-OCR-Service-Key" in response.json()["detail"]


def test_upload_with_wrong_key_is_rejected(client):
    response = _post(client, headers={"X-OCR-Service-Key": "wrong-key"})
    assert response.status_code == 401


def test_upload_with_correct_key_succeeds(client):
    response = _post(client, headers={"X-OCR-Service-Key": SERVICE_KEY})
    assert response.status_code == 200
    assert response.json()["status"] == "success"


def test_upload_fails_closed_when_service_key_is_unset(client, monkeypatch):
    monkeypatch.delenv("OCR_SERVICE_KEY", raising=False)

    response = _post(client, headers={"X-OCR-Service-Key": SERVICE_KEY})

    assert response.status_code == 503
    assert "not configured" in response.json()["detail"]


def test_upload_over_the_size_cap_is_rejected(client, monkeypatch):
    monkeypatch.setattr(main, "OCR_MAX_UPLOAD_BYTES", 16)

    response = _post(
        client,
        headers={"X-OCR-Service-Key": SERVICE_KEY},
        content=b"x" * 1024,
    )

    assert response.status_code == 413
    assert "maximum size" in response.json()["detail"]


def test_default_size_cap_is_configured():
    assert main.OCR_MAX_UPLOAD_BYTES == 10 * 1024 * 1024


def test_default_bind_address_is_loopback():
    assert main.OCR_HOST == "127.0.0.1"
