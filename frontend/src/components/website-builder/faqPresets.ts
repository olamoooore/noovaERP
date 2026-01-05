import { Preset } from './types'

// FAQ presets with smooth accordion animations
// All presets use the <details>/<summary> HTML elements for native accordion behavior
// The smooth-faq class enables CSS grid-based smooth animations
// Inline scripts ensure accordion functionality works in GrapesJS editor and preview

// Shared FAQ accordion script - handles details/summary toggle in GrapesJS
const FAQ_ACCORDION_SCRIPT = `
<script>
(function() {
  var section = document.currentScript.parentElement;
  if (!section) return;
  
  // Handle details toggle - GrapesJS may interfere with native behavior
  section.addEventListener('click', function(e) {
    var summary = e.target.closest('summary');
    if (!summary) return;
    
    var details = summary.parentElement;
    if (!details || details.tagName !== 'DETAILS') return;
    
    // Prevent default and manually toggle
    e.preventDefault();
    e.stopPropagation();
    
    if (details.hasAttribute('open')) {
      details.removeAttribute('open');
    } else {
      details.setAttribute('open', '');
    }
  }, true);
})();
</script>`;

export const faqPresets: Preset[] = [
  // ============================================
  // 1. THE NEXUS SPLIT (Sidebar CTA)
  // ============================================
  {
    id: 'faq-nexus-split',
    label: 'Nexus Split',
    category: 'FAQs',
    content: `<section data-gjs-name="FAQ Section" class="faq-01 smooth-faq">
      <style>
        .faq-01 { padding: 100px 20px; background-color: #fff; font-family: 'Outfit', sans-serif; }
        .faq-01 .f1-container { max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: 350px 1fr; gap: 60px; }
        .faq-01 .f1-cta-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 24px; padding: 40px; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.05); position: sticky; top: 100px; height: fit-content; }
        .faq-01 .f1-avatar { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; margin-bottom: 20px; }
        .faq-01 .f1-cta-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 10px; color: #0f172a; }
        .faq-01 .f1-cta-desc { font-size: 0.95rem; color: #64748b; margin-bottom: 30px; line-height: 1.5; }
        .faq-01 .f1-btn { background: #FF4F00; color: #fff; width: 100%; padding: 16px; border-radius: 12px; font-weight: 600; text-align: center; text-decoration: none; display: block; transition: 0.3s; box-shadow: 0 10px 20px rgba(255, 79, 0, 0.2); }
        .faq-01 .f1-btn:hover { background: #e64600; transform: translateY(-2px); }
        .faq-01 .f1-list { display: flex; flex-direction: column; gap: 16px; }
        .faq-01 .f1-item { background: #f8fafc; border-radius: 16px; border: 1px solid transparent; transition: 0.3s; }
        .faq-01 .f1-item[open] { background: #fff; border-color: #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .faq-01 .f1-summary { padding: 24px; display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 1.1rem; color: #334155; list-style: none; cursor: pointer; }
        .faq-01 .f1-summary::-webkit-details-marker { display: none; }
        .faq-01 .f1-item[open] .f1-summary { color: #0f172a; }
        .faq-01 .f1-icon { width: 20px; height: 20px; color: #94a3b8; transition: 0.3s; }
        .faq-01 .f1-item[open] .f1-icon { transform: rotate(180deg); color: #FF4F00; }
        .faq-01 .f1-answer { padding: 0 24px 24px 24px; color: #64748b; line-height: 1.6; }
        .faq-01 details .ans-wrapper { display: grid; grid-template-rows: 0fr; transition: 0.3s ease-out; }
        .faq-01 details[open] .ans-wrapper { grid-template-rows: 1fr; }
        .faq-01 .ans-inner { overflow: hidden; }
        @media(max-width: 900px) { .faq-01 .f1-container { grid-template-columns: 1fr; } .faq-01 .f1-cta-card { position: relative; top: 0; } }
      </style>
      <div data-gjs-name="Container" class="f1-container">
        <div data-gjs-name="CTA Card" class="f1-cta-card">
          <img data-gjs-name="Avatar" src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=100&auto=format&fit=crop" class="f1-avatar" alt="Support">
          <h3 data-gjs-name="CTA Title" class="f1-cta-title">Still have questions?</h3>
          <p data-gjs-name="CTA Description" class="f1-cta-desc">Can't find the answer you're looking for? Please chat to our friendly team.</p>
          <a data-gjs-name="CTA Button" href="#" class="f1-btn">Book a 15 min call</a>
        </div>
        <div data-gjs-name="FAQ List" class="f1-list">
          <details data-gjs-name="FAQ Item" class="f1-item" open>
            <summary data-gjs-name="Question" class="f1-summary">
              <span>What does your agency specialize in?</span>
              <svg class="f1-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </summary>
            <div class="ans-wrapper"><div class="ans-inner f1-answer">We specialize in helping startups design, build, and scale AI-powered products. Our expertise includes AI strategy, UX/UI design, and full-stack development.</div></div>
          </details>
          <details data-gjs-name="FAQ Item" class="f1-item">
            <summary data-gjs-name="Question" class="f1-summary">
              <span>How long does a typical project take?</span>
              <svg class="f1-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </summary>
            <div class="ans-wrapper"><div class="ans-inner f1-answer">Most MVP projects take between 4-8 weeks. Larger scale enterprise applications can take 3-6 months depending on complexity.</div></div>
          </details>
          <details data-gjs-name="FAQ Item" class="f1-item">
            <summary data-gjs-name="Question" class="f1-summary">
              <span>Do you work with early-stage startups?</span>
              <svg class="f1-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </summary>
            <div class="ans-wrapper"><div class="ans-inner f1-answer">Yes! We love working with founders to bring their initial vision to life. We offer specific packages tailored for seed-stage companies.</div></div>
          </details>
        </div>
      </div>
      ${FAQ_ACCORDION_SCRIPT}
    </section>`
  },

  // ============================================
  // 2. THE ESTATE (Visual Right)
  // ============================================
  {
    id: 'faq-estate',
    label: 'Estate Visual',
    category: 'FAQs',
    content: `<section data-gjs-name="FAQ Section" class="faq-02 smooth-faq">
      <style>
        .faq-02 { padding: 100px 20px; background-color: #fff; font-family: 'Manrope', sans-serif; }
        .faq-02 .f2-container { max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: 80px; align-items: start; }
        .faq-02 .f2-header { margin-bottom: 40px; }
        .faq-02 .f2-title { font-size: 3rem; font-weight: 800; letter-spacing: -1px; margin-bottom: 20px; font-family: 'Outfit', sans-serif; color: #0f172a; }
        .faq-02 .f2-subtitle { color: #64748b; }
        .faq-02 .f2-item { border-bottom: 1px solid #e2e8f0; }
        .faq-02 .f2-summary { padding: 24px 0; display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 1.1rem; list-style: none; cursor: pointer; color: #0f172a; }
        .faq-02 .f2-summary::-webkit-details-marker { display: none; }
        .faq-02 .f2-toggle { width: 32px; height: 32px; background: #0f172a; color: #fff; border-radius: 50%; display: grid; place-items: center; transition: 0.3s; font-size: 1.2rem; }
        .faq-02 .f2-item[open] .f2-toggle { transform: rotate(45deg); }
        .faq-02 .f2-answer { padding-bottom: 24px; color: #64748b; font-size: 1rem; line-height: 1.6; }
        .faq-02 .f2-visual { position: sticky; top: 100px; border-radius: 24px; overflow: hidden; box-shadow: 0 30px 60px -15px rgba(0,0,0,0.1); }
        .faq-02 .f2-img { width: 100%; height: auto; }
        .faq-02 details .ans-wrapper { display: grid; grid-template-rows: 0fr; transition: 0.3s ease-out; }
        .faq-02 details[open] .ans-wrapper { grid-template-rows: 1fr; }
        .faq-02 .ans-inner { overflow: hidden; }
        @media(max-width: 900px) { .faq-02 .f2-container { grid-template-columns: 1fr; } .faq-02 .f2-visual { display: none; } }
      </style>
      <div data-gjs-name="Container" class="f2-container">
        <div data-gjs-name="Content" class="f2-content">
          <div data-gjs-name="Header" class="f2-header">
            <h2 data-gjs-name="Title" class="f2-title">Frequently Asked Questions</h2>
            <p data-gjs-name="Subtitle" class="f2-subtitle">Everything you need to know about our luxury properties.</p>
          </div>
          <details data-gjs-name="FAQ Item" class="f2-item">
            <summary data-gjs-name="Question" class="f2-summary">
              <span>What's included in the service?</span>
              <div class="f2-toggle">+</div>
            </summary>
            <div class="ans-wrapper"><div class="ans-inner f2-answer">Our comprehensive service includes property valuation, professional photography, virtual tours, and legal handling of all documents.</div></div>
          </details>
          <details data-gjs-name="FAQ Item" class="f2-item">
            <summary data-gjs-name="Question" class="f2-summary">
              <span>How much does it cost?</span>
              <div class="f2-toggle">+</div>
            </summary>
            <div class="ans-wrapper"><div class="ans-inner f2-answer">We charge a flat 2% commission fee upon successful sale. There are no upfront listing fees or hidden charges.</div></div>
          </details>
          <details data-gjs-name="FAQ Item" class="f2-item">
            <summary data-gjs-name="Question" class="f2-summary">
              <span>Do you work with international buyers?</span>
              <div class="f2-toggle">+</div>
            </summary>
            <div class="ans-wrapper"><div class="ans-inner f2-answer">Yes, we have a dedicated team for international clients that handles visa requirements and cross-border transactions.</div></div>
          </details>
        </div>
        <div data-gjs-name="Visual" class="f2-visual">
          <img data-gjs-name="Image" src="https://images.unsplash.com/photo-1600596542815-2a4d9fdb242e?q=80&w=800&auto=format&fit=crop" class="f2-img" alt="Modern House">
        </div>
      </div>
      ${FAQ_ACCORDION_SCRIPT}
    </section>`
  },

  // ============================================
  // 3. THE FOCUS CARD (Centered Glass)
  // ============================================
  {
    id: 'faq-focus-card',
    label: 'Focus Card',
    category: 'FAQs',
    content: `<section data-gjs-name="FAQ Section" class="faq-03 smooth-faq">
      <style>
        .faq-03 { padding: 120px 20px; background-color: #f1f5f9; position: relative; overflow: hidden; font-family: 'Inter', sans-serif; }
        .faq-03 .f3-blob { position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.6; }
        .faq-03 .f3-blob-1 { width: 400px; height: 400px; background: #3b82f6; top: -100px; left: -100px; }
        .faq-03 .f3-blob-2 { width: 500px; height: 500px; background: #a855f7; bottom: -100px; right: -100px; }
        .faq-03 .f3-card { max-width: 900px; margin: 0 auto; background: rgba(255,255,255,0.8); backdrop-filter: blur(20px); border-radius: 30px; padding: 60px; box-shadow: 0 20px 50px -10px rgba(0,0,0,0.05); position: relative; z-index: 2; border: 1px solid #fff; }
        .faq-03 .f3-header { text-align: center; margin-bottom: 50px; }
        .faq-03 .f3-title { font-size: 2.5rem; font-weight: 800; margin-bottom: 10px; color: #0f172a; }
        .faq-03 .f3-pill { background: #0f172a; color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; display: inline-block; }
        .faq-03 .f3-item { background: #fff; border-radius: 12px; margin-bottom: 12px; border: 1px solid #e2e8f0; overflow: hidden; }
        .faq-03 .f3-summary { padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; font-weight: 600; transition: 0.2s; list-style: none; cursor: pointer; color: #0f172a; }
        .faq-03 .f3-summary::-webkit-details-marker { display: none; }
        .faq-03 .f3-item[open] .f3-summary { background: #f8fafc; }
        .faq-03 .f3-icon { width: 24px; height: 24px; border: 1px solid #cbd5e1; border-radius: 50%; display: grid; place-items: center; color: #64748b; transition: 0.3s; font-size: 0.8rem; }
        .faq-03 .f3-item[open] .f3-icon { background: #0f172a; border-color: #0f172a; color: #fff; transform: rotate(180deg); }
        .faq-03 .f3-answer { padding: 24px; color: #475569; border-top: 1px solid #e2e8f0; line-height: 1.6; }
        .faq-03 details .ans-wrapper { display: grid; grid-template-rows: 0fr; transition: 0.3s ease-out; }
        .faq-03 details[open] .ans-wrapper { grid-template-rows: 1fr; }
        .faq-03 .ans-inner { overflow: hidden; }
      </style>
      <div data-gjs-name="Blob 1" class="f3-blob f3-blob-1"></div>
      <div data-gjs-name="Blob 2" class="f3-blob f3-blob-2"></div>
      <div data-gjs-name="Card" class="f3-card">
        <div data-gjs-name="Header" class="f3-header">
          <span data-gjs-name="Pill" class="f3-pill">Support</span>
          <h2 data-gjs-name="Title" class="f3-title">We've got answers</h2>
        </div>
        <details data-gjs-name="FAQ Item" class="f3-item">
          <summary data-gjs-name="Question" class="f3-summary">
            <span>What is a digital business card?</span>
            <svg class="f3-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f3-answer">A digital business card is a virtual representation of your professional profile that can be shared instantly via QR code, NFC, or link.</div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f3-item">
          <summary data-gjs-name="Question" class="f3-summary">
            <span>Is it compatible with iPhone and Android?</span>
            <svg class="f3-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f3-answer">Yes, our cards work on any device with a web browser. No app installation is required for the recipient to view your card.</div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f3-item">
          <summary data-gjs-name="Question" class="f3-summary">
            <span>Can I update my details later?</span>
            <svg class="f3-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f3-answer">Absolutely. You can edit your contact information, photo, and links in real-time from your dashboard without needing to reshare your card.</div></div>
        </details>
      </div>
      ${FAQ_ACCORDION_SCRIPT}
    </section>`
  },

  // ============================================
  // 4. THE BENTO Q&A (Grid Layout - No Accordion)
  // ============================================
  {
    id: 'faq-bento',
    label: 'Bento Grid',
    category: 'FAQs',
    content: `<section data-gjs-name="FAQ Section" class="faq-04">
      <style>
        .faq-04 { padding: 100px 20px; background-color: #fff; font-family: 'Outfit', sans-serif; }
        .faq-04 .f4-container { max-width: 1200px; margin: 0 auto; }
        .faq-04 .f4-title { text-align: center; font-size: 3rem; font-weight: 800; margin-bottom: 60px; color: #0f172a; }
        .faq-04 .f4-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; }
        .faq-04 .f4-item { background: #f8fafc; padding: 32px; border-radius: 24px; border: 1px solid #f1f5f9; transition: 0.3s; }
        .faq-04 .f4-item:hover { background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-color: #e2e8f0; }
        .faq-04 .f4-question { font-size: 1.25rem; font-weight: 700; margin-bottom: 16px; color: #0f172a; display: block; }
        .faq-04 .f4-answer { color: #64748b; line-height: 1.6; font-size: 1rem; }
        .faq-04 .f4-link { display: inline-flex; align-items: center; gap: 5px; color: #3b82f6; font-weight: 600; font-size: 0.9rem; margin-top: 16px; text-decoration: none; }
        .faq-04 .f4-link:hover { text-decoration: underline; }
        @media(max-width: 768px) { .faq-04 .f4-grid { grid-template-columns: 1fr; } .faq-04 .f4-title { font-size: 2rem; } }
      </style>
      <div data-gjs-name="Container" class="f4-container">
        <h2 data-gjs-name="Title" class="f4-title">Help & Resources</h2>
        <div data-gjs-name="Grid" class="f4-grid">
          <div data-gjs-name="FAQ Item" class="f4-item">
            <span data-gjs-name="Question" class="f4-question">How do I integrate with Slack?</span>
            <p data-gjs-name="Answer" class="f4-answer">Go to Settings > Integrations and click "Connect Slack". You'll be redirected to authorize the app.</p>
            <a data-gjs-name="Link" href="#" class="f4-link">Read Guide →</a>
          </div>
          <div data-gjs-name="FAQ Item" class="f4-item">
            <span data-gjs-name="Question" class="f4-question">What is your refund policy?</span>
            <p data-gjs-name="Answer" class="f4-answer">We offer a 30-day money-back guarantee, no questions asked. Just email support.</p>
            <a data-gjs-name="Link" href="#" class="f4-link">View Policy →</a>
          </div>
          <div data-gjs-name="FAQ Item" class="f4-item">
            <span data-gjs-name="Question" class="f4-question">Can I invite guests?</span>
            <p data-gjs-name="Answer" class="f4-answer">Yes, guest accounts are free and unlimited on Pro and Enterprise plans.</p>
            <a data-gjs-name="Link" href="#" class="f4-link">Manage Users →</a>
          </div>
          <div data-gjs-name="FAQ Item" class="f4-item">
            <span data-gjs-name="Question" class="f4-question">Where is data stored?</span>
            <p data-gjs-name="Answer" class="f4-answer">All data is encrypted and stored on AWS servers located in Northern Virginia, USA.</p>
            <a data-gjs-name="Link" href="#" class="f4-link">Security Info →</a>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 5. THE NEON TERMINAL (Dark Tech)
  // ============================================
  {
    id: 'faq-neon-terminal',
    label: 'Neon Terminal',
    category: 'FAQs',
    content: `<section data-gjs-name="FAQ Section" class="faq-05 smooth-faq">
      <style>
        .faq-05 { padding: 100px 20px; background-color: #050505; color: #0f0; font-family: 'Space Grotesk', monospace; }
        .faq-05 .f5-container { max-width: 900px; margin: 0 auto; border: 1px solid #333; padding: 40px; border-radius: 8px; background: #0a0a0a; box-shadow: 0 0 40px rgba(0,255,0,0.05); }
        .faq-05 .f5-header { border-bottom: 1px solid #333; padding-bottom: 20px; margin-bottom: 30px; display: flex; gap: 10px; }
        .faq-05 .f5-dot { width: 12px; height: 12px; border-radius: 50%; }
        .faq-05 .f5-path { margin-left: auto; color: #555; font-size: 0.8rem; }
        .faq-05 .f5-item { margin-bottom: 20px; }
        .faq-05 .f5-summary { display: flex; gap: 10px; align-items: center; opacity: 0.8; transition: 0.2s; list-style: none; cursor: pointer; }
        .faq-05 .f5-summary::-webkit-details-marker { display: none; }
        .faq-05 .f5-summary:hover, .faq-05 .f5-item[open] .f5-summary { opacity: 1; text-shadow: 0 0 10px rgba(0,255,0,0.5); }
        .faq-05 .f5-prompt { color: #555; }
        .faq-05 .f5-q-text { font-size: 1.1rem; }
        .faq-05 .f5-answer { padding: 10px 0 10px 25px; color: #ccc; line-height: 1.6; border-left: 1px dashed #333; margin-left: 7px; margin-top: 10px; }
        .faq-05 details .ans-wrapper { display: grid; grid-template-rows: 0fr; transition: 0.3s ease-out; }
        .faq-05 details[open] .ans-wrapper { grid-template-rows: 1fr; }
        .faq-05 .ans-inner { overflow: hidden; }
      </style>
      <div data-gjs-name="Container" class="f5-container">
        <div data-gjs-name="Header" class="f5-header">
          <div class="f5-dot" style="background:#ff5f56"></div>
          <div class="f5-dot" style="background:#ffbd2e"></div>
          <div class="f5-dot" style="background:#27c93f"></div>
          <span data-gjs-name="Path" class="f5-path">user@system:~/help</span>
        </div>
        <details data-gjs-name="FAQ Item" class="f5-item" open>
          <summary data-gjs-name="Question" class="f5-summary">
            <span class="f5-prompt">></span>
            <span class="f5-q-text">sudo explain-pricing</span>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f5-answer">// Usage-based pricing.<br>// Pay only for the compute time you consume.<br>// First 100k requests are free.</div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f5-item">
          <summary data-gjs-name="Question" class="f5-summary">
            <span class="f5-prompt">></span>
            <span class="f5-q-text">check-compatibility --react</span>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f5-answer">// Status: Compatible<br>// Native React hooks available via npm install @pkg/react</div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f5-item">
          <summary data-gjs-name="Question" class="f5-summary">
            <span class="f5-prompt">></span>
            <span class="f5-q-text">locate --servers</span>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f5-answer">// 35 Regions Found.<br>// Nearest edge node will be automatically selected.</div></div>
        </details>
      </div>
      ${FAQ_ACCORDION_SCRIPT}
    </section>`
  },

  // ============================================
  // 6. THE SWISS MINIMAL (Big Type)
  // ============================================
  {
    id: 'faq-swiss-minimal',
    label: 'Swiss Minimal',
    category: 'FAQs',
    content: `<section data-gjs-name="FAQ Section" class="faq-06 smooth-faq">
      <style>
        .faq-06 { padding: 120px 20px; background-color: #fff; font-family: 'Inter', sans-serif; }
        .faq-06 .f6-container { max-width: 1000px; margin: 0 auto; }
        .faq-06 .f6-item { border-top: 1px solid #000; padding: 40px 0; display: grid; grid-template-columns: 50px 1fr 50px; gap: 20px; transition: 0.3s; }
        .faq-06 .f6-summary { display: contents; list-style: none; cursor: pointer; }
        .faq-06 .f6-summary::-webkit-details-marker { display: none; }
        .faq-06 .f6-num { font-size: 1rem; font-weight: 500; color: #94a3b8; padding-top: 5px; }
        .faq-06 .f6-content { padding-right: 40px; }
        .faq-06 .f6-question { font-size: 1.8rem; font-weight: 600; line-height: 1.2; margin-bottom: 0; transition: 0.3s; color: #0f172a; }
        .faq-06 .f6-item[open] .f6-question { margin-bottom: 20px; }
        .faq-06 .f6-icon { font-size: 2rem; font-weight: 300; display: flex; justify-content: flex-end; color: #0f172a; transition: 0.3s; }
        .faq-06 .f6-item[open] .f6-icon { transform: rotate(45deg); }
        .faq-06 .f6-answer { font-size: 1.1rem; color: #64748b; line-height: 1.6; max-width: 90%; }
        .faq-06 details .ans-wrapper { display: grid; grid-template-rows: 0fr; transition: 0.3s ease-out; }
        .faq-06 details[open] .ans-wrapper { grid-template-rows: 1fr; }
        .faq-06 .ans-inner { overflow: hidden; }
        @media(max-width: 768px) { .faq-06 .f6-item { grid-template-columns: 1fr 40px; } .faq-06 .f6-num { display: none; } .faq-06 .f6-question { font-size: 1.4rem; } }
      </style>
      <div data-gjs-name="Container" class="f6-container">
        <details data-gjs-name="FAQ Item" class="f6-item">
          <summary data-gjs-name="Question" class="f6-summary">
            <span class="f6-num">01</span>
            <div class="f6-content">
              <h3 class="f6-question">Is the license perpetual?</h3>
            </div>
            <div class="f6-icon">+</div>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner" style="display:grid; grid-template-columns: 50px 1fr 50px; gap:20px;"><div></div><p class="f6-answer">Yes. Once you purchase a license, you own it forever. You receive one year of free updates and support, which can be renewed optionally.</p></div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f6-item">
          <summary data-gjs-name="Question" class="f6-summary">
            <span class="f6-num">02</span>
            <div class="f6-content">
              <h3 class="f6-question">What file formats are included?</h3>
            </div>
            <div class="f6-icon">+</div>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner" style="display:grid; grid-template-columns: 50px 1fr 50px; gap:20px;"><div></div><p class="f6-answer">The download package includes source files for Figma, Sketch, and Adobe XD, as well as coded React and HTML/CSS versions.</p></div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f6-item">
          <summary data-gjs-name="Question" class="f6-summary">
            <span class="f6-num">03</span>
            <div class="f6-content">
              <h3 class="f6-question">Can I use it for client projects?</h3>
            </div>
            <div class="f6-icon">+</div>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner" style="display:grid; grid-template-columns: 50px 1fr 50px; gap:20px;"><div></div><p class="f6-answer">Absolutely! Our license allows unlimited use for both personal and commercial projects, including client work.</p></div></div>
        </details>
      </div>
      ${FAQ_ACCORDION_SCRIPT}
    </section>`
  },

  // ============================================
  // 7. THE GLASS STACK (Colorful Dark)
  // ============================================
  {
    id: 'faq-glass-stack',
    label: 'Glass Stack',
    category: 'FAQs',
    content: `<section data-gjs-name="FAQ Section" class="faq-07 smooth-faq">
      <style>
        .faq-07 { padding: 100px 20px; background-color: #000; position: relative; overflow: hidden; font-family: 'Outfit', sans-serif; }
        .faq-07 .f7-mesh { position: absolute; inset: 0; background: radial-gradient(circle at 50% 50%, #4f46e5 0%, #000 70%); opacity: 0.4; }
        .faq-07 .f7-container { max-width: 800px; margin: 0 auto; position: relative; z-index: 2; }
        .faq-07 .f7-item { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); backdrop-filter: blur(10px); border-radius: 20px; margin-bottom: 16px; color: #fff; transition: 0.3s; }
        .faq-07 .f7-item[open] { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.3); }
        .faq-07 .f7-summary { padding: 24px; display: flex; align-items: center; justify-content: space-between; list-style: none; cursor: pointer; }
        .faq-07 .f7-summary::-webkit-details-marker { display: none; }
        .faq-07 .f7-question { font-size: 1.1rem; font-weight: 600; }
        .faq-07 .f7-icon { width: 24px; height: 24px; background: rgba(255,255,255,0.1); border-radius: 50%; display: grid; place-items: center; transition: 0.3s; }
        .faq-07 .f7-item[open] .f7-icon { background: #fff; color: #000; transform: rotate(180deg); }
        .faq-07 .f7-answer { padding: 0 24px 24px; color: #94a3b8; line-height: 1.6; }
        .faq-07 details .ans-wrapper { display: grid; grid-template-rows: 0fr; transition: 0.3s ease-out; }
        .faq-07 details[open] .ans-wrapper { grid-template-rows: 1fr; }
        .faq-07 .ans-inner { overflow: hidden; }
      </style>
      <div data-gjs-name="Mesh Background" class="f7-mesh"></div>
      <div data-gjs-name="Container" class="f7-container">
        <details data-gjs-name="FAQ Item" class="f7-item" open>
          <summary data-gjs-name="Question" class="f7-summary">
            <span class="f7-question">How do I access the beta?</span>
            <div class="f7-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg></div>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f7-answer">Join our Discord server and link your account. Beta access is rolled out in waves every Friday to active community members.</div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f7-item">
          <summary data-gjs-name="Question" class="f7-summary">
            <span class="f7-question">Can I stream my gameplay?</span>
            <div class="f7-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg></div>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f7-answer">Yes! We encourage streaming. Please make sure to use the official asset kit available in your dashboard for overlays.</div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f7-item">
          <summary data-gjs-name="Question" class="f7-summary">
            <span class="f7-question">What platforms are supported?</span>
            <div class="f7-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg></div>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f7-answer">Currently available on PC (Windows/Mac/Linux) and PlayStation 5. Xbox and Nintendo Switch versions are in development.</div></div>
        </details>
      </div>
      ${FAQ_ACCORDION_SCRIPT}
    </section>`
  },

  // ============================================
  // 8. THE NEO-POP (Brutalist Color)
  // ============================================
  {
    id: 'faq-neo-pop',
    label: 'Neo Pop',
    category: 'FAQs',
    content: `<section data-gjs-name="FAQ Section" class="faq-08 smooth-faq">
      <style>
        .faq-08 { padding: 100px 20px; background-color: #FFD23F; font-family: 'Syne', sans-serif; }
        .faq-08 .f8-container { max-width: 900px; margin: 0 auto; }
        .faq-08 .f8-item { background: #fff; border: 3px solid #000; margin-bottom: 20px; box-shadow: 6px 6px 0px #000; transition: 0.2s; }
        .faq-08 .f8-item[open] { transform: translate(2px, 2px); box-shadow: 4px 4px 0px #000; }
        .faq-08 .f8-summary { padding: 24px; display: flex; justify-content: space-between; align-items: center; list-style: none; cursor: pointer; }
        .faq-08 .f8-summary::-webkit-details-marker { display: none; }
        .faq-08 .f8-question { font-size: 1.2rem; font-weight: 800; color: #000; text-transform: uppercase; }
        .faq-08 .f8-icon { font-size: 1.5rem; font-weight: 800; transition: 0.3s; }
        .faq-08 .f8-item[open] .f8-icon { transform: rotate(45deg); }
        .faq-08 .f8-answer { padding: 0 24px 24px; font-family: 'Inter', sans-serif; font-weight: 500; line-height: 1.5; color: #333; }
        .faq-08 details .ans-wrapper { display: grid; grid-template-rows: 0fr; transition: 0.3s ease-out; }
        .faq-08 details[open] .ans-wrapper { grid-template-rows: 1fr; }
        .faq-08 .ans-inner { overflow: hidden; }
      </style>
      <div data-gjs-name="Container" class="f8-container">
        <details data-gjs-name="FAQ Item" class="f8-item" open>
          <summary data-gjs-name="Question" class="f8-summary">
            <span class="f8-question">Is it fast?</span>
            <div class="f8-icon">+</div>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f8-answer">Lightning fast. We score 100/100 on Lighthouse performance metrics. Your site will load before you can blink.</div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f8-item">
          <summary data-gjs-name="Question" class="f8-summary">
            <span class="f8-question">Mobile Ready?</span>
            <div class="f8-icon">+</div>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f8-answer">100% Responsive. We use fluid typography and grid layouts that adapt perfectly to any screen size, from watches to huge monitors.</div></div>
        </details>
        <details data-gjs-name="FAQ Item" class="f8-item">
          <summary data-gjs-name="Question" class="f8-summary">
            <span class="f8-question">SEO Optimized?</span>
            <div class="f8-icon">+</div>
          </summary>
          <div class="ans-wrapper"><div class="ans-inner f8-answer">Built with semantic HTML and structured data. Your content will rank higher and be more accessible to everyone.</div></div>
        </details>
      </div>
      ${FAQ_ACCORDION_SCRIPT}
    </section>`
  },

  // ============================================
  // 9. THE VERTICAL TABS (Sidebar Nav)
  // ============================================
  {
    id: 'faq-vertical-tabs',
    label: 'Vertical Tabs',
    category: 'FAQs',
    content: `<section data-gjs-name="FAQ Section" class="faq-09 smooth-faq" data-faq-tabs="faq-tabs-1">
      <style>
        .faq-09 { padding: 100px 20px; background-color: #fff; font-family: 'Inter', sans-serif; }
        .faq-09 .f9-container { max-width: 1100px; margin: 0 auto; display: flex; gap: 60px; }
        .faq-09 .f9-nav { width: 250px; flex-shrink: 0; display: flex; flex-direction: column; gap: 5px; position: sticky; top: 100px; height: fit-content; }
        .faq-09 .f9-nav-item { padding: 12px 20px; border-radius: 8px; text-decoration: none; color: #64748b; font-weight: 500; transition: 0.2s; cursor: pointer; }
        .faq-09 .f9-nav-item:hover { background: #f1f5f9; color: #0f172a; }
        .faq-09 .f9-nav-item.active { background: #eff6ff; color: #3b82f6; font-weight: 600; }
        .faq-09 .f9-content { flex: 1; }
        .faq-09 .f9-tab-content { display: none; }
        .faq-09 .f9-tab-content.active { display: block; animation: fadeInFaq 0.3s ease; }
        @keyframes fadeInFaq { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .faq-09 .f9-group-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 20px; color: #0f172a; }
        .faq-09 .f9-item { border-bottom: 1px solid #e2e8f0; }
        .faq-09 .f9-summary { padding: 20px 0; font-weight: 600; color: #334155; display: flex; justify-content: space-between; list-style: none; cursor: pointer; }
        .faq-09 .f9-summary::-webkit-details-marker { display: none; }
        .faq-09 .f9-item[open] .f9-summary { color: #3b82f6; }
        .faq-09 .f9-answer { padding-bottom: 20px; color: #64748b; line-height: 1.6; }
        .faq-09 details .ans-wrapper { display: grid; grid-template-rows: 0fr; transition: 0.3s ease-out; }
        .faq-09 details[open] .ans-wrapper { grid-template-rows: 1fr; }
        .faq-09 .ans-inner { overflow: hidden; }
        @media(max-width: 900px) { .faq-09 .f9-container { flex-direction: column; } .faq-09 .f9-nav { width: 100%; flex-direction: row; overflow-x: auto; padding-bottom: 10px; position: relative; top: 0; } }
      </style>
      <div data-gjs-name="Container" class="f9-container">
        <div data-gjs-name="Navigation" class="f9-nav">
          <a data-gjs-name="Nav Item" href="#faq-general" class="f9-nav-item active">General</a>
          <a data-gjs-name="Nav Item" href="#faq-billing" class="f9-nav-item">Billing</a>
          <a data-gjs-name="Nav Item" href="#faq-technical" class="f9-nav-item">Technical</a>
          <a data-gjs-name="Nav Item" href="#faq-privacy" class="f9-nav-item">Privacy</a>
        </div>
        <div data-gjs-name="Content" class="f9-content">
          <!-- General Tab -->
          <div id="faq-general" class="f9-tab-content active">
            <h3 data-gjs-name="Group Title" class="f9-group-title">General Questions</h3>
            <details data-gjs-name="FAQ Item" class="f9-item" open>
              <summary data-gjs-name="Question" class="f9-summary">How many projects can I create?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">On the Free plan, you can create up to 3 projects. The Pro plan offers unlimited projects and folders.</div></div>
            </details>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">Can I collaborate with clients?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">Yes, you can generate shareable links for clients to view and comment on designs without them needing an account.</div></div>
            </details>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">Is there a desktop app?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">Currently, we are web-based only to ensure you always have the latest version. A dedicated desktop wrapper is on our roadmap.</div></div>
            </details>
          </div>
          <!-- Billing Tab -->
          <div id="faq-billing" class="f9-tab-content">
            <h3 data-gjs-name="Group Title" class="f9-group-title">Billing Questions</h3>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">What payment methods do you accept?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">We accept all major credit cards (Visa, MasterCard, American Express), PayPal, and bank transfers for annual plans.</div></div>
            </details>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">Can I cancel my subscription anytime?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">Yes, you can cancel your subscription at any time. Your access will continue until the end of your billing period.</div></div>
            </details>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">Do you offer refunds?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">We offer a 30-day money-back guarantee for all new subscriptions. Contact support for assistance.</div></div>
            </details>
          </div>
          <!-- Technical Tab -->
          <div id="faq-technical" class="f9-tab-content">
            <h3 data-gjs-name="Group Title" class="f9-group-title">Technical Questions</h3>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">What browsers are supported?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">We support all modern browsers including Chrome, Firefox, Safari, and Edge. Internet Explorer is not supported.</div></div>
            </details>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">Can I export my data?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">Yes, you can export all your data in various formats including JSON, CSV, and PDF from your account settings.</div></div>
            </details>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">Is there an API available?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">Yes, we offer a RESTful API for Pro and Enterprise plans. Documentation is available in your dashboard.</div></div>
            </details>
          </div>
          <!-- Privacy Tab -->
          <div id="faq-privacy" class="f9-tab-content">
            <h3 data-gjs-name="Group Title" class="f9-group-title">Privacy Questions</h3>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">How is my data protected?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">All data is encrypted at rest and in transit using AES-256 encryption. We follow industry best practices for security.</div></div>
            </details>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">Do you share data with third parties?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">We never sell your data. We only share data with service providers necessary to operate our platform, under strict agreements.</div></div>
            </details>
            <details data-gjs-name="FAQ Item" class="f9-item">
              <summary data-gjs-name="Question" class="f9-summary">Are you GDPR compliant?</summary>
              <div class="ans-wrapper"><div class="ans-inner f9-answer">Yes, we are fully GDPR compliant. You can request data deletion or export at any time from your account settings.</div></div>
            </details>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var section = document.currentScript.parentElement;
          if (!section) return;
          
          // Handle FAQ accordion toggle
          section.addEventListener('click', function(e) {
            var summary = e.target.closest('summary');
            if (summary) {
              var details = summary.parentElement;
              if (details && details.tagName === 'DETAILS') {
                e.preventDefault();
                e.stopPropagation();
                if (details.hasAttribute('open')) {
                  details.removeAttribute('open');
                } else {
                  details.setAttribute('open', '');
                }
                return;
              }
            }
            
            // Handle tab navigation
            var navItem = e.target.closest('.f9-nav-item');
            if (!navItem) return;
            
            e.preventDefault();
            e.stopPropagation();
            
            var href = navItem.getAttribute('href');
            if (!href || href === '#') return;
            
            var targetId = href.replace('#', '');
            
            // Remove active from all nav items
            section.querySelectorAll('.f9-nav-item').forEach(function(item) {
              item.classList.remove('active');
            });
            
            // Add active to clicked nav item
            navItem.classList.add('active');
            
            // Hide all tab content
            section.querySelectorAll('.f9-tab-content').forEach(function(content) {
              content.classList.remove('active');
            });
            
            // Show target tab content
            var targetContent = section.querySelector('#' + targetId);
            if (targetContent) {
              targetContent.classList.add('active');
            }
          }, true);
        })();
      </script>
    </section>`
  },
]
