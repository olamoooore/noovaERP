// Product Page Presets for GrapesJS Website Builder
// Acme-styled product page section presets with inline styles
// Based on the acme-concept-a-main design system

import { Preset } from './types'

// =============================================================================
// DESIGN TOKENS (Acme)
// =============================================================================
const ACME = {
  red: '#C8102E',
  redDark: '#a50d24',
  orange: '#F5A623',
  black: '#111111',
  gray1: '#555555',
  gray2: '#666666',
  gray3: '#777777',
  grayMuted: '#999999',
  light: '#F7F6F3',
  white: '#FFFFFF',
  border: '#E8E8E4',
  border2: '#E0DED8',
  fontSerif: "'Playfair Display', Georgia, serif",
  fontSans: "'DM Sans', system-ui, -apple-system, sans-serif",
}

// Placeholder image SVG for product images (gray box with camera icon)
const placeholderImg = `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='300' viewBox='0 0 400 300'%3E%3Crect fill='%23F7F6F3' width='400' height='300'/%3E%3Crect fill='%23E8E8E4' x='140' y='90' width='120' height='90' rx='4'/%3E%3Ccircle fill='none' stroke='%23999' stroke-width='2' cx='175' cy='125' r='12'/%3E%3Cpath fill='none' stroke='%23999' stroke-width='2' d='M155 155l15-15 20 20 10-10 25 25'/%3E%3Ctext fill='%23999' font-family='sans-serif' font-size='11' text-anchor='middle' x='200' y='210'%3EProduct Image%3C/text%3E%3C/svg%3E`

const placeholderImg2 = `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='300' viewBox='0 0 400 300'%3E%3Crect fill='%23F7F6F3' width='400' height='300'/%3E%3Crect fill='%23E8E8E4' x='140' y='90' width='120' height='90' rx='4'/%3E%3Ccircle fill='none' stroke='%23999' stroke-width='2' cx='175' cy='125' r='12'/%3E%3Cpath fill='none' stroke='%23999' stroke-width='2' d='M155 155l15-15 20 20 10-10 25 25'/%3E%3Ctext fill='%23999' font-family='sans-serif' font-size='11' text-anchor='middle' x='200' y='210'%3EProduct Image 2%3C/text%3E%3C/svg%3E`

const placeholderImg3 = `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='300' viewBox='0 0 400 300'%3E%3Crect fill='%23F7F6F3' width='400' height='300'/%3E%3Crect fill='%23E8E8E4' x='140' y='90' width='120' height='90' rx='4'/%3E%3Ccircle fill='none' stroke='%23999' stroke-width='2' cx='175' cy='125' r='12'/%3E%3Cpath fill='none' stroke='%23999' stroke-width='2' d='M155 155l15-15 20 20 10-10 25 25'/%3E%3Ctext fill='%23999' font-family='sans-serif' font-size='11' text-anchor='middle' x='200' y='210'%3EProduct Image 3%3C/text%3E%3C/svg%3E`

const placeholderImg4 = `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='300' viewBox='0 0 400 300'%3E%3Crect fill='%23F7F6F3' width='400' height='300'/%3E%3Crect fill='%23E8E8E4' x='140' y='90' width='120' height='90' rx='4'/%3E%3Ccircle fill='none' stroke='%23999' stroke-width='2' cx='175' cy='125' r='12'/%3E%3Cpath fill='none' stroke='%23999' stroke-width='2' d='M155 155l15-15 20 20 10-10 25 25'/%3E%3Ctext fill='%23999' font-family='sans-serif' font-size='11' text-anchor='middle' x='200' y='210'%3EProduct Image 4%3C/text%3E%3C/svg%3E`

// =============================================================================
// PRODUCT PRESETS
// =============================================================================
export const productPresets: Preset[] = [

  // ===========================================================================
  // PRESET 1: PRODUCT HERO
  // The hero banner at the top of a product category page
  // ===========================================================================
  {
    id: 'product-hero',
    label: 'Product Hero',
    category: 'Products',
    content: `
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

      <section style="position: relative; padding-top: 80px; background: ${ACME.light}; overflow: hidden; font-family: ${ACME.fontSans}; box-sizing: border-box;">
        <!-- Left gradient bar -->
        <div style="position: absolute; left: 0; top: 0; width: 3px; height: 100%; background: linear-gradient(to bottom, ${ACME.red}, ${ACME.orange}, transparent);"></div>

        <div style="max-width: 1280px; margin: 0 auto; padding: 64px 24px; position: relative;">
          <!-- Breadcrumb -->
          <nav style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin-bottom: 40px;">
            <a href="/" style="color: ${ACME.grayMuted}; text-decoration: none;">Home</a>
            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="${ACME.grayMuted}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            <span style="color: ${ACME.grayMuted};">Products</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="${ACME.grayMuted}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            <span style="color: ${ACME.red};">Category Name</span>
          </nav>

          <!-- Section label -->
          <p style="font-family: ${ACME.fontSans}; font-size: 11px; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; color: ${ACME.red}; margin-bottom: 24px;">Products / Category</p>

          <!-- Heading -->
          <h1 style="font-family: ${ACME.fontSerif}; font-weight: 700; font-size: 60px; line-height: 1.1; color: ${ACME.black}; margin-bottom: 24px;">
            Category <em style="font-style: normal; color: ${ACME.red};">Products</em>
          </h1>

          <!-- Gradient underline -->
          <div style="height: 3px; width: 64px; background: linear-gradient(to right, ${ACME.red}, ${ACME.orange}); margin-bottom: 24px;"></div>

          <!-- Lead text -->
          <p style="font-size: 16px; color: ${ACME.gray2}; line-height: 1.7; max-width: 40rem;">
            Advanced medical solutions engineered for precision clinical applications. FDA and EU CE certified products trusted by healthcare professionals across APAC.
          </p>
        </div>
      </section>
    `,
  },

  // ===========================================================================
  // PRESET 2: PRODUCT FEATURE (Single Product)
  // 2-column layout: text + specs grid on left, product image on right
  // ===========================================================================
  {
    id: 'product-feature-single',
    label: 'Single Product Feature',
    category: 'Products',
    content: `
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

      <section style="padding: 112px 0; background: ${ACME.white}; font-family: ${ACME.fontSans}; box-sizing: border-box;">
        <div style="max-width: 1280px; margin: 0 auto; padding: 0 48px;">
          <div style="display: grid; gap: 64px; align-items: center; grid-template-columns: 1fr 1fr;">

            <!-- Left: Text + Specs -->
            <div>
              <!-- Brand label -->
              <p style="font-family: ${ACME.fontSans}; font-size: 11px; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; color: ${ACME.red}; margin-bottom: 16px;">Principal Name</p>

              <!-- Product title -->
              <h2 style="font-family: ${ACME.fontSerif}; font-weight: 700; font-size: 36px; line-height: 1.14; color: ${ACME.black}; margin-bottom: 16px;">
                Product Name <span style="color: ${ACME.red};">Model</span>
              </h2>

              <!-- Gradient underline -->
              <div style="height: 3px; width: 48px; background: linear-gradient(to right, ${ACME.red}, ${ACME.orange}); margin-bottom: 24px;"></div>

              <!-- Size tags -->
              <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 24px;">
                <span style="font-size: 12px; color: ${ACME.gray1}; background: ${ACME.light}; border: 1px solid ${ACME.border2}; padding: 4px 12px; display: inline-block;">Size A</span>
                <span style="font-size: 12px; color: ${ACME.gray1}; background: ${ACME.light}; border: 1px solid ${ACME.border2}; padding: 4px 12px; display: inline-block;">Size B</span>
              </div>

              <!-- Description -->
              <p style="font-size: 16px; color: ${ACME.gray1}; line-height: 1.7; margin-bottom: 24px;">
                Medical-grade biomaterial designed for post-surgical recovery. Clinically validated for effectiveness in reducing adhesions and supporting tissue healing.
              </p>
              <p style="font-size: 16px; color: ${ACME.gray1}; line-height: 1.7; margin-bottom: 32px;">
                Recommended by leading medical associations for its proven efficacy and safety profile in clinical practice.
              </p>

              <!-- Specs grid -->
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; padding-top: 32px; border-top: 1px solid ${ACME.border};">
                <div>
                  <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin-bottom: 4px;">Material</p>
                  <p style="font-size: 14px; font-weight: 500; color: ${ACME.black};">Cross-Linked HA Gel</p>
                </div>
                <div>
                  <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin-bottom: 4px;">Certifications</p>
                  <p style="font-size: 14px; font-weight: 500; color: ${ACME.black};">FDA &amp; EU CE</p>
                </div>
                <div>
                  <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin-bottom: 4px;">Indication</p>
                  <p style="font-size: 14px; font-weight: 500; color: ${ACME.black};">Anti-Adhesion</p>
                </div>
                <div>
                  <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin-bottom: 4px;">Principal</p>
                  <p style="font-size: 14px; font-weight: 500; color: ${ACME.black};">Company Name</p>
                </div>
              </div>
            </div>

            <!-- Right: Product Image -->
            <div style="position: relative; width: 100%; aspect-ratio: 4/3; background: ${ACME.light}; overflow: hidden; border: 1px solid ${ACME.border}; display: flex; align-items: center; justify-content: center;">
              <img src="${placeholderImg}" alt="Product Name" style="width: 100%; height: 100%; object-fit: contain; padding: 32px;">
            </div>

          </div>
        </div>
      </section>
    `,
  },

  // ===========================================================================
  // PRESET 3: PRODUCT CAROUSEL
  // Stacked-card carousel for multiple products from a brand
  // ===========================================================================
  {
    id: 'product-carousel',
    label: 'Product Carousel',
    category: 'Products',
    content: `
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
      <link href="https://cdn.jsdelivr.net/npm/lucide-static@0.263.1/font/lucide.min.css" rel="stylesheet">

      <section style="padding: 112px 0; background: ${ACME.light}; font-family: ${ACME.fontSans}; box-sizing: border-box;">
        <div style="max-width: 1280px; margin: 0 auto; padding: 0 48px;">

          <!-- Section header -->
          <div style="margin-bottom: 56px;">
            <p style="font-family: ${ACME.fontSans}; font-size: 11px; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; color: ${ACME.red}; margin-bottom: 12px;">Brand Name</p>
            <h2 style="font-family: ${ACME.fontSerif}; font-weight: 700; font-size: 36px; line-height: 1.14; color: ${ACME.black};">Product Range</h2>
            <div style="height: 3px; width: 48px; margin-top: 16px; background: linear-gradient(to right, ${ACME.red}, ${ACME.orange});"></div>
          </div>

          <!-- Carousel container -->
          <div style="display: grid; gap: 48px; align-items: center; grid-template-columns: 1fr 1fr;">

            <!-- Left: Image stack -->
            <div style="position: relative; width: 100%; height: 384px; perspective: 1000px;">
              <!-- Active card -->
              <div style="position: absolute; inset: 0; z-index: 3;">
                <div style="position: relative; width: 100%; height: 100%; background: ${ACME.white}; border-radius: 16px; overflow: hidden; border: 1px solid ${ACME.border}; box-shadow: 0 8px 32px rgba(0,0,0,0.10); display: flex; align-items: center; justify-content: center;">
                  <img src="${placeholderImg}" alt="Product 1" style="width: 100%; height: 100%; object-fit: contain; padding: 32px;">
                </div>
              </div>
              <!-- Stacked card behind (left) -->
              <div style="position: absolute; top: 0; left: -40px; width: calc(100% - 40px); height: 85%; z-index: 2; transform: rotateY(15deg) translateY(-8px); opacity: 0.7;">
                <div style="position: relative; width: 100%; height: 100%; background: ${ACME.white}; border-radius: 16px; overflow: hidden; border: 1px solid ${ACME.border}; box-shadow: 0 4px 16px rgba(0,0,0,0.06); display: flex; align-items: center; justify-content: center;">
                  <img src="${placeholderImg2}" alt="Product 2" style="width: 100%; height: 100%; object-fit: contain; padding: 24px;">
                </div>
              </div>
              <!-- Stacked card behind (right) -->
              <div style="position: absolute; top: 0; right: -40px; width: calc(100% - 40px); height: 85%; z-index: 1; transform: rotateY(-15deg) translateY(-8px); opacity: 0.5;">
                <div style="position: relative; width: 100%; height: 100%; background: ${ACME.white}; border-radius: 16px; overflow: hidden; border: 1px solid ${ACME.border}; box-shadow: 0 4px 16px rgba(0,0,0,0.06); display: flex; align-items: center; justify-content: center;">
                  <img src="${placeholderImg3}" alt="Product 3" style="width: 100%; height: 100%; object-fit: contain; padding: 24px;">
                </div>
              </div>
            </div>

            <!-- Right: Product info -->
            <div style="display: flex; flex-direction: column; justify-content: space-between; min-height: 260px;">
              <div>
                <!-- Brand label -->
                <span style="font-size: 10px; font-weight: 600; letter-spacing: 0.18em; color: ${ACME.red}; text-transform: uppercase; display: block; margin-bottom: 12px;">Brand Name</span>

                <!-- Product name -->
                <h3 style="font-family: ${ACME.fontSerif}; font-weight: 700; font-size: 28px; color: ${ACME.black}; line-height: 1.3; margin-bottom: 12px;">Product Name with Cannula</h3>

                <!-- Small underline -->
                <div style="height: 3px; width: 40px; background: linear-gradient(to right, ${ACME.red}, ${ACME.orange}); margin-bottom: 20px;"></div>

                <!-- Size tags -->
                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px;">
                  <span style="font-size: 12px; color: ${ACME.gray1}; background: ${ACME.light}; border: 1px solid ${ACME.border2}; padding: 4px 12px; display: inline-block;">10ml</span>
                  <span style="font-size: 12px; color: ${ACME.gray1}; background: ${ACME.light}; border: 1px solid ${ACME.border2}; padding: 4px 12px; display: inline-block;">MDD</span>
                </div>

                <!-- Description -->
                <p style="font-size: 15px; color: ${ACME.gray1}; line-height: 1.7;">
                  Hyaluronic acid-based anti-adhesion gel for post-operative recovery. Supplied with applicator cannula for precise placement. FDA and EU CE certified.
                </p>
              </div>

              <!-- Controls -->
              <div style="display: flex; align-items: center; gap: 12px; margin-top: 32px;">
                <button style="width: 40px; height: 40px; border-radius: 50%; background: ${ACME.black}; color: ${ACME.white}; display: flex; align-items: center; justify-content: center; border: none; cursor: pointer; transition: background-color 0.2s ease;" onmouseover="this.style.background='${ACME.red}'" onmouseout="this.style.background='${ACME.black}'">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <button style="width: 40px; height: 40px; border-radius: 50%; background: ${ACME.black}; color: ${ACME.white}; display: flex; align-items: center; justify-content: center; border: none; cursor: pointer; transition: background-color 0.2s ease;" onmouseover="this.style.background='${ACME.red}'" onmouseout="this.style.background='${ACME.black}'">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </button>
                <div style="display: flex; gap: 8px; margin-left: 8px;">
                  <button style="height: 6px; width: 24px; border-radius: 999px; background: ${ACME.red}; padding: 0; border: none; cursor: pointer;"></button>
                  <button style="height: 6px; width: 6px; border-radius: 999px; background: #D0CEC8; padding: 0; border: none; cursor: pointer;"></button>
                  <button style="height: 6px; width: 6px; border-radius: 999px; background: #D0CEC8; padding: 0; border: none; cursor: pointer;"></button>
                </div>
              </div>
            </div>

          </div>
        </div>
      </section>
    `,
  },

  // ===========================================================================
  // PRESET 4: PRODUCT GALLERY
  // 4-image responsive grid with captions
  // ===========================================================================
  {
    id: 'product-gallery',
    label: 'Product Gallery',
    category: 'Products',
    content: `
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

      <section style="padding: 112px 0; background: ${ACME.white}; font-family: ${ACME.fontSans}; box-sizing: border-box;">
        <div style="max-width: 1280px; margin: 0 auto; padding: 0 48px;">

          <!-- Section header -->
          <div style="margin-bottom: 32px;">
            <p style="font-family: ${ACME.fontSans}; font-size: 11px; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; color: ${ACME.red}; margin-bottom: 8px;">Product Gallery</p>
            <h3 style="font-family: ${ACME.fontSerif}; font-weight: 700; font-size: 24px; color: ${ACME.black};">Product Views</h3>
            <div style="height: 2px; width: 40px; margin-top: 12px; background: linear-gradient(to right, ${ACME.red}, ${ACME.orange});"></div>
          </div>

          <!-- Gallery grid: 4 columns on desktop -->
          <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px;">

            <!-- Gallery card 1 -->
            <div style="background: ${ACME.white}; border: 1px solid ${ACME.border}; transition: all 0.3s ease; cursor: pointer;" onmouseover="this.style.borderColor='${ACME.red}'; this.style.boxShadow='0 12px 28px rgba(0,0,0,0.08)'" onmouseout="this.style.borderColor='${ACME.border}'; this.style.boxShadow='none'">
              <div style="position: relative; width: 100%; aspect-ratio: 1/1; background: ${ACME.light}; overflow: hidden;">
                <img src="${placeholderImg}" alt="Front View" style="width: 100%; height: 100%; object-fit: contain; padding: 16px; transition: transform 0.5s ease;">
              </div>
              <div style="padding: 12px 16px; border-top: 1px solid ${ACME.border};">
                <p style="font-size: 12px; color: ${ACME.gray3}; margin: 0;">Front View</p>
              </div>
            </div>

            <!-- Gallery card 2 -->
            <div style="background: ${ACME.white}; border: 1px solid ${ACME.border}; transition: all 0.3s ease; cursor: pointer;" onmouseover="this.style.borderColor='${ACME.red}'; this.style.boxShadow='0 12px 28px rgba(0,0,0,0.08)'" onmouseout="this.style.borderColor='${ACME.border}'; this.style.boxShadow='none'">
              <div style="position: relative; width: 100%; aspect-ratio: 1/1; background: ${ACME.light}; overflow: hidden;">
                <img src="${placeholderImg2}" alt="Display Panel" style="width: 100%; height: 100%; object-fit: contain; padding: 16px; transition: transform 0.5s ease;">
              </div>
              <div style="padding: 12px 16px; border-top: 1px solid ${ACME.border};">
                <p style="font-size: 12px; color: ${ACME.gray3}; margin: 0;">Display Panel</p>
              </div>
            </div>

            <!-- Gallery card 3 -->
            <div style="background: ${ACME.white}; border: 1px solid ${ACME.border}; transition: all 0.3s ease; cursor: pointer;" onmouseover="this.style.borderColor='${ACME.red}'; this.style.boxShadow='0 12px 28px rgba(0,0,0,0.08)'" onmouseout="this.style.borderColor='${ACME.border}'; this.style.boxShadow='none'">
              <div style="position: relative; width: 100%; aspect-ratio: 1/1; background: ${ACME.light}; overflow: hidden;">
                <img src="${placeholderImg3}" alt="Side View" style="width: 100%; height: 100%; object-fit: contain; padding: 16px; transition: transform 0.5s ease;">
              </div>
              <div style="padding: 12px 16px; border-top: 1px solid ${ACME.border};">
                <p style="font-size: 12px; color: ${ACME.gray3}; margin: 0;">Side View</p>
              </div>
            </div>

            <!-- Gallery card 4 -->
            <div style="background: ${ACME.white}; border: 1px solid ${ACME.border}; transition: all 0.3s ease; cursor: pointer;" onmouseover="this.style.borderColor='${ACME.red}'; this.style.boxShadow='0 12px 28px rgba(0,0,0,0.08)'" onmouseout="this.style.borderColor='${ACME.border}'; this.style.boxShadow='none'">
              <div style="position: relative; width: 100%; aspect-ratio: 1/1; background: ${ACME.light}; overflow: hidden;">
                <img src="${placeholderImg4}" alt="Detail View" style="width: 100%; height: 100%; object-fit: contain; padding: 16px; transition: transform 0.5s ease;">
              </div>
              <div style="padding: 12px 16px; border-top: 1px solid ${ACME.border};">
                <p style="font-size: 12px; color: ${ACME.gray3}; margin: 0;">Detail View</p>
              </div>
            </div>

          </div>
        </div>
      </section>
    `,
  },

  // ===========================================================================
  // PRESET 5: PRODUCT SPECS TABLE
  // Detailed specifications in a clean table layout
  // ===========================================================================
  {
    id: 'product-specs',
    label: 'Product Specs Table',
    category: 'Products',
    content: `
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

      <section style="padding: 112px 0; background: ${ACME.light}; font-family: ${ACME.fontSans}; box-sizing: border-box;">
        <div style="max-width: 1280px; margin: 0 auto; padding: 0 48px;">

          <!-- Section header -->
          <div style="text-align: center; margin-bottom: 64px;">
            <p style="font-family: ${ACME.fontSans}; font-size: 11px; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; color: ${ACME.red}; margin-bottom: 12px;">Technical Details</p>
            <h2 style="font-family: ${ACME.fontSerif}; font-weight: 700; font-size: 36px; line-height: 1.14; color: ${ACME.black};">Product Specifications</h2>
            <div style="height: 3px; width: 64px; margin: 16px auto 0; background: linear-gradient(to right, ${ACME.red}, ${ACME.orange});"></div>
          </div>

          <!-- Specs table -->
          <div style="max-width: 800px; margin: 0 auto; background: ${ACME.white}; border: 1px solid ${ACME.border}; overflow: hidden;">
            <!-- Row 1 -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; border-bottom: 1px solid ${ACME.border};">
              <div style="padding: 20px 32px; background: ${ACME.light};">
                <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin: 0 0 4px 0;">Product Name</p>
                <p style="font-size: 15px; font-weight: 500; color: ${ACME.black}; margin: 0;">Product Name Model</p>
              </div>
              <div style="padding: 20px 32px;">
                <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin: 0 0 4px 0;">Manufacturer</p>
                <p style="font-size: 15px; font-weight: 500; color: ${ACME.black}; margin: 0;">Company Name</p>
              </div>
            </div>
            <!-- Row 2 -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; border-bottom: 1px solid ${ACME.border};">
              <div style="padding: 20px 32px; background: ${ACME.light};">
                <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin: 0 0 4px 0;">Material</p>
                <p style="font-size: 15px; font-weight: 500; color: ${ACME.black}; margin: 0;">Cross-Linked Sodium Hyaluronate</p>
              </div>
              <div style="padding: 20px 32px;">
                <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin: 0 0 4px 0;">Available Sizes</p>
                <p style="font-size: 15px; font-weight: 500; color: ${ACME.black}; margin: 0;">3ml, 5ml, 10ml</p>
              </div>
            </div>
            <!-- Row 3 -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; border-bottom: 1px solid ${ACME.border};">
              <div style="padding: 20px 32px; background: ${ACME.light};">
                <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin: 0 0 4px 0;">Certifications</p>
                <p style="font-size: 15px; font-weight: 500; color: ${ACME.black}; margin: 0;">FDA &amp; EU CE</p>
              </div>
              <div style="padding: 20px 32px;">
                <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin: 0 0 4px 0;">Indication</p>
                <p style="font-size: 15px; font-weight: 500; color: ${ACME.black}; margin: 0;">Post-Surgical Anti-Adhesion</p>
              </div>
            </div>
            <!-- Row 4 -->
            <div style="display: grid; grid-template-columns: 1fr 1fr;">
              <div style="padding: 20px 32px; background: ${ACME.light};">
                <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin: 0 0 4px 0;">Storage</p>
                <p style="font-size: 15px; font-weight: 500; color: ${ACME.black}; margin: 0;">Room Temperature (15–25°C)</p>
              </div>
              <div style="padding: 20px 32px;">
                <p style="font-size: 11px; color: ${ACME.grayMuted}; text-transform: uppercase; letter-spacing: 0.18em; margin: 0 0 4px 0;">Region</p>
                <p style="font-size: 15px; font-weight: 500; color: ${ACME.black}; margin: 0;">APAC Distribution</p>
              </div>
            </div>
          </div>

        </div>
      </section>
    `,
  },

  // ===========================================================================
  // PRESET 6: PRODUCT CTA BANNER
  // Call-to-action banner for product inquiries
  // ===========================================================================
  {
    id: 'product-cta',
    label: 'Product CTA Banner',
    category: 'Products',
    content: `
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

      <section style="padding: 80px 0; background: ${ACME.black}; font-family: ${ACME.fontSans}; box-sizing: border-box;">
        <div style="max-width: 1280px; margin: 0 auto; padding: 0 48px; text-align: center;">
          <p style="font-size: 11px; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; color: ${ACME.red}; margin-bottom: 16px;">Get in Touch</p>
          <h2 style="font-family: ${ACME.fontSerif}; font-weight: 700; font-size: 36px; line-height: 1.14; color: ${ACME.white}; margin-bottom: 16px;">Interested in This Product?</h2>
          <div style="height: 3px; width: 64px; margin: 0 auto 24px; background: linear-gradient(to right, ${ACME.red}, ${ACME.orange});"></div>
          <p style="font-size: 16px; color: #777; line-height: 1.7; max-width: 32rem; margin: 0 auto 32px;">
            Contact our team for product information, pricing, or to arrange a demonstration for your clinical team.
          </p>
          <a href="/#contact" style="display: inline-flex; align-items: center; gap: 8px; background: ${ACME.red}; color: ${ACME.white}; font-weight: 500; font-size: 15px; padding: 16px 28px; text-decoration: none; transition: background 0.2s ease;" onmouseover="this.style.background='${ACME.redDark}'" onmouseout="this.style.background='${ACME.red}'">
            Contact Us
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
          </a>
        </div>
      </section>
    `,
  },

]

export default productPresets
