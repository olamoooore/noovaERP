#!/usr/bin/env python3
"""
Generate screenshots of HTML theme templates using Playwright.
This script captures full-page screenshots with JavaScript execution.
"""

import asyncio
import os
from pathlib import Path
import http.server
import socketserver
import threading
import random

# Start a simple HTTP server
class QuietHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, format, *args):
        pass  # Suppress logging

def start_server(port, directory):
    os.chdir(directory)
    handler = QuietHandler
    with socketserver.TCPServer(("", port), handler) as httpd:
        httpd.serve_forever()

async def generate_screenshots():
    from playwright.async_api import async_playwright
    
    # Paths
    script_dir = Path(__file__).parent
    project_root = script_dir.parent
    templates_dir = project_root / "frontend" / "public" / "Web-Templates"
    output_dir = project_root / "frontend" / "public" / "theme-screenshots"
    
    # Create output directory
    output_dir.mkdir(parents=True, exist_ok=True)
    
    # Use random port to avoid conflicts
    port = random.randint(8700, 8799)
    
    # Start HTTP server in background
    server_thread = threading.Thread(
        target=start_server, 
        args=(port, str(project_root)),
        daemon=True
    )
    server_thread.start()
    print(f"Started local server on port {port}")
    await asyncio.sleep(1)
    
    # Screenshot settings - DESKTOP view (wide)
    viewport_width = 1440
    viewport_height = 900
    
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        
        # Get all theme HTML files
        theme_files = sorted(templates_dir.glob("theme-*.html"))
        print(f"Found {len(theme_files)} theme files")
        
        for theme_file in theme_files:
            theme_id = theme_file.stem
            output_path = output_dir / f"{theme_id}.png"
            
            print(f"Processing {theme_id}...", flush=True)
            
            page = await browser.new_page(
                viewport={"width": viewport_width, "height": viewport_height}
            )
            
            url = f"http://localhost:{port}/frontend/public/Web-Templates/{theme_id}.html"
            try:
                await page.goto(url, wait_until="networkidle", timeout=30000)
                await asyncio.sleep(2)
                
                await page.screenshot(
                    path=str(output_path),
                    full_page=False,
                    type="png"
                )
                
                print(f"  ✓ Saved {output_path.name} ({output_path.stat().st_size // 1024}KB)", flush=True)
            except Exception as e:
                print(f"  ✗ Error: {e}", flush=True)
            
            await page.close()
        
        await browser.close()
    
    print(f"\n✅ Done! Screenshots in {output_dir}")

if __name__ == "__main__":
    asyncio.run(generate_screenshots())
