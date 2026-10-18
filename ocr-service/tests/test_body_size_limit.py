"""
Issue #224 regression tests — the upload cap must be enforced at the ASGI
layer, while the body is still streaming in, not after Starlette has already
parsed and spooled the whole multipart upload.

These tests drive the middleware directly with a fake ASGI app, so they prove
the ordering (rejection before the parser/handler finishes) rather than only
the status code.

Run with:
    .venv/bin/pip install -r requirements.txt -r requirements-dev.txt
    .venv/bin/python -m pytest tests/ -q
"""

import asyncio
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import main  # noqa: E402

UPLOAD_PATH = "/api/bookkeeping/upload"


class RecordingApp:
    """Stand-in for Starlette's parser+handler: drains the body like a parser."""

    def __init__(self):
        self.received = 0
        self.completed = False
        self.status = 200

    async def __call__(self, scope, receive, send):
        while True:
            message = await receive()
            self.received += len(message.get("body", b""))
            if not message.get("more_body", False):
                break
        self.completed = True
        await send({"type": "http.response.start", "status": self.status, "headers": []})
        await send({"type": "http.response.body", "body": b"ok"})


def drive(middleware, chunks, headers=(), path=UPLOAD_PATH):
    sent = []
    messages = [
        {"type": "http.request", "body": chunk, "more_body": index < len(chunks) - 1}
        for index, chunk in enumerate(chunks)
    ]
    index = 0

    async def receive():
        nonlocal index
        if index < len(messages):
            message = messages[index]
            index += 1
            return message
        return {"type": "http.request", "body": b"", "more_body": False}

    async def send(message):
        sent.append(message)

    scope = {
        "type": "http",
        "method": "POST",
        "path": path,
        "headers": [(b"content-length", str(value).encode()) for value in headers],
    }

    asyncio.run(middleware(scope, receive, send))
    return sent


def status_of(sent):
    return sent[0]["status"] if sent else None


def test_declared_content_length_over_cap_is_rejected_before_any_read():
    app = RecordingApp()
    middleware = main.BodySizeLimitMiddleware(app, max_bytes=16)

    sent = drive(middleware, [b"x" * 1024], headers=[999999])

    assert status_of(sent) == 413
    assert app.received == 0, "No body byte may be read when Content-Length declares oversize"
    assert app.completed is False


def test_lying_small_content_length_is_caught_mid_stream():
    app = RecordingApp()
    middleware = main.BodySizeLimitMiddleware(app, max_bytes=16)

    # Declares 10 bytes but streams 20 in two chunks.
    sent = drive(middleware, [b"x" * 10, b"x" * 10], headers=[10])

    assert status_of(sent) == 413
    assert app.completed is False, "The parser must not finish spooling an oversized body"
    assert app.received <= 16, "At most one chunk past the cap may be read"


def test_missing_content_length_is_caught_mid_stream():
    app = RecordingApp()
    middleware = main.BodySizeLimitMiddleware(app, max_bytes=16)

    sent = drive(middleware, [b"x" * 8, b"x" * 8, b"x" * 8])

    assert status_of(sent) == 413
    assert app.completed is False


def test_body_within_cap_passes_through():
    app = RecordingApp()
    middleware = main.BodySizeLimitMiddleware(app, max_bytes=1024)

    sent = drive(middleware, [b"abc"])

    assert status_of(sent) == 200
    assert app.completed is True
    assert app.received == 3


def test_other_paths_are_not_limited():
    app = RecordingApp()
    middleware = main.BodySizeLimitMiddleware(app, max_bytes=4)

    sent = drive(middleware, [b"x" * 1024], path="/health")

    assert status_of(sent) == 200
    assert app.completed is True


def test_app_has_the_middleware_installed_with_the_configured_cap():
    installed = [
        middleware
        for middleware in main.app.user_middleware
        if middleware.cls is main.BodySizeLimitMiddleware
    ]

    assert installed, "BodySizeLimitMiddleware must be installed on the app"
    assert installed[0].kwargs.get("max_bytes") == main.OCR_MAX_UPLOAD_BYTES
