import { Preset } from './types'

// CTA (Call to Action) presets with modern futuristic designs
// Features: glassmorphism, gradients, animations, responsive layouts

export const ctaPresets: Preset[] = [
  // ============================================
  // 1. THE ORANGE RIPPLE
  // ============================================
  {
    id: 'cta-orange-ripple',
    label: 'Orange Ripple',
    category: 'CTAs',
    content: `<section data-gjs-name="CTA Section" class="cta-01">
      <style>
        .cta-01 { padding: 80px 20px; min-height: 50vh; display: flex; justify-content: center; align-items: center; background-color: #f0f0f0; font-family: 'Inter', sans-serif; }
        .cta-01 .container { max-width: 1200px; width: 100%; margin: 0 auto; }
        .cta-01 .orange-card { background: linear-gradient(135deg, #ff7e1d 0%, #ff5500 100%); border-radius: 40px; padding: 60px; display: flex; justify-content: space-between; align-items: center; position: relative; overflow: hidden; box-shadow: 0 20px 40px rgba(255, 85, 0, 0.2); }
        .cta-01 .orange-card::after { content: ''; position: absolute; right: -100px; top: 50%; transform: translateY(-50%); width: 600px; height: 600px; background: radial-gradient(circle, rgba(255,255,255,0.2) 20%, rgba(255,255,255,0.1) 40%, rgba(255,255,255,0.05) 60%, transparent 80%); border-radius: 50%; pointer-events: none; }
        .cta-01 .orange-content { z-index: 2; max-width: 500px; }
        .cta-01 .orange-content h2 { font-family: 'Space Grotesk', sans-serif; font-size: 3rem; color: white; line-height: 1.1; margin-bottom: 1.5rem; }
        .cta-01 .orange-content p { line-height: 1.6; color: rgba(255,255,255,0.9); margin-bottom: 2rem; }
        .cta-01 .btn-group { display: flex; gap: 15px; flex-wrap: wrap; }
        .cta-01 .btn-pill { padding: 15px 30px; border-radius: 50px; border: none; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 10px; transition: transform 0.2s; background: black; color: white; text-decoration: none; }
        .cta-01 .btn-pill:hover { transform: scale(1.05); }
        .cta-01 .circle-icon { width: 12px; height: 12px; background: #fff; border-radius: 50%; display: inline-block; }
        @media (max-width: 768px) { .cta-01 .orange-card { flex-direction: column; text-align: center; } .cta-01 .orange-card::after { display: none; } .cta-01 h2 { font-size: 2.5rem !important; } .cta-01 .btn-group { justify-content: center; } }
      </style>
      <div data-gjs-name="Container" class="container">
        <div data-gjs-name="Card" class="orange-card">
          <div data-gjs-name="Content" class="orange-content">
            <h2 data-gjs-name="Title">Let's Get In Touch.</h2>
            <p data-gjs-name="Description">Your laboratory instruments should serve you, not the other way around. We're happy to help you scale.</p>
            <div data-gjs-name="Button Group" class="btn-group">
              <a data-gjs-name="Button" href="#" class="btn-pill">Book a discovery call <span class="circle-icon"></span></a>
              <a data-gjs-name="Button" href="#" class="btn-pill">Test Your Samples <span class="circle-icon"></span></a>
            </div>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 2. THE DARK ARCH ANALYTICS
  // ============================================
  {
    id: 'cta-dark-arch',
    label: 'Dark Arch Stats',
    category: 'CTAs',
    content: `<section data-gjs-name="CTA Section" class="cta-02">
      <style>
        .cta-02 { padding: 80px 20px; min-height: 50vh; display: flex; justify-content: center; align-items: center; background-color: #050d08; font-family: 'Inter', sans-serif; color: #fff; }
        .cta-02 .container { max-width: 1200px; width: 100%; margin: 0 auto; }
        .cta-02 .green-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; align-items: center; }
        .cta-02 .green-content h2 { font-family: 'Space Grotesk', sans-serif; font-size: 3.5rem; color: white; line-height: 1.1; margin-bottom: 1.5rem; }
        .cta-02 .green-content span { color: #888; }
        .cta-02 .green-content p { line-height: 1.6; color: rgba(255,255,255,0.7); margin-bottom: 2rem; max-width: 600px; }
        .cta-02 .green-stats-arch { background: linear-gradient(180deg, #0a2e18 0%, #050d08 100%); border-top-left-radius: 200px; border-top-right-radius: 200px; padding: 80px 40px 40px 40px; border: 1px solid #144022; }
        .cta-02 .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; text-align: center; }
        .cta-02 .stat-item h3 { font-size: 2.5rem; margin-bottom: 5px; color: #fff; }
        .cta-02 .stat-item p { font-size: 0.9rem; margin: 0; color: #6ee7b7; }
        .cta-02 .btn-group { display: flex; gap: 15px; flex-wrap: wrap; }
        .cta-02 .btn-pill { padding: 15px 30px; border-radius: 50px; border: none; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 10px; transition: transform 0.2s; text-decoration: none; }
        .cta-02 .btn-green { background: #10b981; color: #000; }
        .cta-02 .btn-outline { background: transparent; border: 1px solid #333; color: #fff; }
        .cta-02 .btn-pill:hover { transform: scale(1.05); }
        @media (max-width: 768px) { .cta-02 .green-layout { grid-template-columns: 1fr; text-align: center; } .cta-02 .green-stats-arch { border-radius: 20px; width: 100%; } .cta-02 h2 { font-size: 2.5rem !important; } .cta-02 .btn-group { justify-content: center; } }
      </style>
      <div data-gjs-name="Container" class="container">
        <div data-gjs-name="Layout" class="green-layout">
          <div data-gjs-name="Content" class="green-content">
            <h2 data-gjs-name="Title">They Say Numbers<br>Don't Lie.</h2>
            <p data-gjs-name="Description">Our results speak louder than words, delivering measurable success and undeniable growth for your SaaS.</p>
            <div data-gjs-name="Button Group" class="btn-group">
              <a data-gjs-name="Button" href="#" class="btn-pill btn-green">Let's Collaborate</a>
              <a data-gjs-name="Button" href="#" class="btn-pill btn-outline">Book a Call ↗</a>
            </div>
          </div>
          <div data-gjs-name="Stats Arch" class="green-stats-arch">
            <div data-gjs-name="Stats Grid" class="stats-grid">
              <div data-gjs-name="Stat" class="stat-item">
                <h3>2x</h3>
                <p>more conversions</p>
              </div>
              <div data-gjs-name="Stat" class="stat-item">
                <h3>98%</h3>
                <p>client satisfaction</p>
              </div>
              <div data-gjs-name="Stat" class="stat-item">
                <h3>30%</h3>
                <p>lower bounce rates</p>
              </div>
              <div data-gjs-name="Stat" class="stat-item">
                <h3>5x</h3>
                <p>ROI for clients</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 3. GLASSMORPHISM GLOW
  // ============================================
  {
    id: 'cta-glass-glow',
    label: 'Glass Glow',
    category: 'CTAs',
    content: `<section data-gjs-name="CTA Section" class="cta-03">
      <style>
        .cta-03 { padding: 80px 20px; min-height: 50vh; display: flex; justify-content: center; align-items: center; background-color: #000; position: relative; overflow: hidden; font-family: 'Inter', sans-serif; color: #fff; }
        .cta-03 .glow-orb { position: absolute; width: 400px; height: 400px; background: #7b2cbf; filter: blur(120px); border-radius: 50%; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 1; opacity: 0.6; animation: pulse03 5s infinite alternate; }
        .cta-03 .container { max-width: 1200px; width: 100%; margin: 0 auto; }
        .cta-03 .glass-card { position: relative; z-index: 2; background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); padding: 80px; border-radius: 24px; text-align: center; max-width: 800px; margin: 0 auto; }
        .cta-03 .glass-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 4rem; background: linear-gradient(to right, #fff, #b8b8b8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1.1; margin-bottom: 1.5rem; }
        .cta-03 .glass-card p { line-height: 1.6; color: rgba(255,255,255,0.8); margin: 20px auto 2rem; max-width: 600px; }
        .cta-03 .btn-glass { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: white; padding: 15px 40px; border-radius: 12px; font-size: 1.1rem; transition: 0.3s; cursor: pointer; text-decoration: none; display: inline-block; }
        .cta-03 .btn-glass:hover { background: white; color: black; box-shadow: 0 0 20px rgba(255,255,255,0.4); }
        @keyframes pulse03 { 0% { transform: translate(-50%, -50%) scale(1); opacity: 0.6; } 100% { transform: translate(-50%, -50%) scale(1.2); opacity: 0.8; } }
        @media (max-width: 768px) { .cta-03 h2 { font-size: 2.5rem !important; } .cta-03 .glass-card { padding: 40px 20px; } }
      </style>
      <div data-gjs-name="Glow Orb" class="glow-orb"></div>
      <div data-gjs-name="Container" class="container">
        <div data-gjs-name="Glass Card" class="glass-card">
          <h2 data-gjs-name="Title">Experience the Clarity</h2>
          <p data-gjs-name="Description">Join thousands of designers building the future of the web with our advanced toolkit.</p>
          <a data-gjs-name="Button" href="#" class="btn-glass">Start Free Trial</a>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 4. CYBERPUNK NEON
  // ============================================
  {
    id: 'cta-cyberpunk',
    label: 'Cyberpunk Neon',
    category: 'CTAs',
    content: `<section data-gjs-name="CTA Section" class="cta-04">
      <style>
        .cta-04 { padding: 80px 20px; min-height: 50vh; display: flex; justify-content: center; align-items: center; background-color: #0b0b0b; background-image: linear-gradient(#1a1a1a 1px, transparent 1px), linear-gradient(90deg, #1a1a1a 1px, transparent 1px); background-size: 40px 40px; font-family: 'Inter', sans-serif; color: #fff; }
        .cta-04 .container { max-width: 1200px; width: 100%; margin: 0 auto; }
        .cta-04 .cyber-layout { border: 1px solid #00f3ff; padding: 60px; position: relative; background: #000; display: flex; align-items: center; justify-content: space-between; }
        .cta-04 .cyber-layout::before { content: 'SYSTEM_READY'; position: absolute; top: -12px; left: 20px; background: #000; color: #00f3ff; padding: 0 10px; font-family: monospace; font-size: 0.8rem; }
        .cta-04 .cyber-text h2 { font-family: 'Space Grotesk', sans-serif; text-transform: uppercase; font-size: 3rem; text-shadow: 2px 2px 0px #ff00ff; line-height: 1.1; margin-bottom: 1rem; }
        .cta-04 .cyber-text p { color: #888; line-height: 1.6; }
        .cta-04 .btn-glitch { background: #00f3ff; color: #000; padding: 20px 40px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; clip-path: polygon(10% 0, 100% 0, 100% 70%, 90% 100%, 0 100%, 0 30%); border: none; cursor: pointer; transition: 0.2s; text-decoration: none; display: inline-block; }
        .cta-04 .btn-glitch:hover { background: #ff00ff; color: white; transform: translate(-2px, -2px); box-shadow: 4px 4px 0 #fff; }
        @media (max-width: 768px) { .cta-04 .cyber-layout { flex-direction: column; text-align: center; gap: 30px; } .cta-04 h2 { font-size: 2.5rem !important; } }
      </style>
      <div data-gjs-name="Container" class="container">
        <div data-gjs-name="Cyber Layout" class="cyber-layout">
          <div data-gjs-name="Content" class="cyber-text">
            <h2 data-gjs-name="Title">Unlock<br>The Grid</h2>
            <p data-gjs-name="Description">Access restricted. Authorization required.</p>
          </div>
          <a data-gjs-name="Button" href="#" class="btn-glitch">Initialize</a>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 5. SWISS BENTO GRID
  // ============================================
  {
    id: 'cta-bento-grid',
    label: 'Bento Grid',
    category: 'CTAs',
    content: `<section data-gjs-name="CTA Section" class="cta-05">
      <style>
        .cta-05 { padding: 80px 20px; min-height: 50vh; display: flex; justify-content: center; align-items: center; background-color: #e0e0e0; font-family: 'Inter', sans-serif; color: #111; }
        .cta-05 .container { max-width: 1200px; width: 100%; margin: 0 auto; }
        .cta-05 .bento-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; grid-template-rows: 200px 200px; gap: 15px; }
        .cta-05 .bento-box { background: #fff; border-radius: 20px; padding: 30px; display: flex; flex-direction: column; justify-content: center; }
        .cta-05 .box-main { grid-column: 1 / 2; grid-row: 1 / 3; background: #111; color: #fff; }
        .cta-05 .box-main h2 { font-family: 'Space Grotesk', sans-serif; font-size: 3.5rem; margin-bottom: 20px; line-height: 1.1; }
        .cta-05 .box-main p { color: #999; line-height: 1.6; }
        .cta-05 .box-accent { background: #ff4757; color: white; align-items: center; text-align: center; }
        .cta-05 .box-accent h3 { margin-bottom: 5px; }
        .cta-05 .box-accent p { color: white; font-size: 0.8rem; margin: 0; }
        .cta-05 .box-neutral h3 { margin-bottom: 5px; }
        .cta-05 .box-neutral p { color: #666; font-size: 0.8rem; margin: 0; }
        .cta-05 .box-action { grid-column: 2 / 4; align-items: center; text-align: center; background: #f4f4f5; }
        .cta-05 .arrow-btn { font-size: 2rem; width: 60px; height: 60px; border-radius: 50%; background: #111; color: #fff; display: flex; align-items: center; justify-content: center; margin-top: 10px; cursor: pointer; text-decoration: none; transition: transform 0.2s; }
        .cta-05 .arrow-btn:hover { transform: scale(1.1); }
        @media (max-width: 768px) { .cta-05 .bento-grid { display: flex; flex-direction: column; } .cta-05 .bento-box { min-height: 150px; } .cta-05 h2 { font-size: 2.5rem !important; } }
      </style>
      <div data-gjs-name="Container" class="container">
        <div data-gjs-name="Bento Grid" class="bento-grid">
          <div data-gjs-name="Main Box" class="bento-box box-main">
            <h2 data-gjs-name="Title">Build Better.</h2>
            <p data-gjs-name="Description">A unified platform for your entire workflow.</p>
          </div>
          <div data-gjs-name="Accent Box" class="bento-box box-accent">
            <h3>Priority Support</h3>
            <p>24/7 Access</p>
          </div>
          <div data-gjs-name="Neutral Box" class="bento-box box-neutral">
            <h3>Community</h3>
            <p>Join 50k+ peers</p>
          </div>
          <div data-gjs-name="Action Box" class="bento-box box-action">
            <h3>Get Started</h3>
            <a data-gjs-name="Arrow Button" href="#" class="arrow-btn">→</a>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 6. GRADIENT BORDER CARD
  // ============================================
  {
    id: 'cta-gradient-border',
    label: 'Gradient Border',
    category: 'CTAs',
    content: `<section data-gjs-name="CTA Section" class="cta-06">
      <style>
        .cta-06 { padding: 80px 20px; min-height: 50vh; display: flex; justify-content: center; align-items: center; background-color: #000; font-family: 'Inter', sans-serif; color: #fff; }
        .cta-06 .container { max-width: 1200px; width: 100%; margin: 0 auto; }
        .cta-06 .gradient-border-card { position: relative; background: #111; padding: 4px; border-radius: 24px; max-width: 900px; margin: 0 auto; }
        .cta-06 .gradient-border-card::before { content: ""; position: absolute; inset: 0; border-radius: 24px; padding: 4px; background: linear-gradient(45deg, #ff00cc, #3333ff, #00ccff); -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }
        .cta-06 .card-inner { background: #111; padding: 80px; border-radius: 20px; text-align: center; }
        .cta-06 .text-gradient { background: linear-gradient(90deg, #ff00cc, #3333ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-family: 'Space Grotesk', sans-serif; font-size: 3.5rem; font-weight: 800; line-height: 1.1; margin-bottom: 1.5rem; }
        .cta-06 .card-inner p { line-height: 1.6; color: rgba(255,255,255,0.7); margin-bottom: 2rem; max-width: 600px; margin-left: auto; margin-right: auto; }
        .cta-06 .btn-pill { padding: 15px 30px; border-radius: 50px; border: none; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; transition: transform 0.2s; background: white; color: black; text-decoration: none; }
        .cta-06 .btn-pill:hover { transform: scale(1.05); }
        @media (max-width: 768px) { .cta-06 .text-gradient { font-size: 2.5rem !important; } .cta-06 .card-inner { padding: 40px 20px; } }
      </style>
      <div data-gjs-name="Container" class="container">
        <div data-gjs-name="Gradient Card" class="gradient-border-card">
          <div data-gjs-name="Card Inner" class="card-inner">
            <h2 data-gjs-name="Title" class="text-gradient">Ship Faster Today</h2>
            <p data-gjs-name="Description">Stop wrestling with infrastructure. Deploy your app in seconds with our edge network.</p>
            <a data-gjs-name="Button" href="#" class="btn-pill">Deploy Now</a>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 7. THE INPUT WIDGET (Newsletter)
  // ============================================
  {
    id: 'cta-newsletter',
    label: 'Newsletter Widget',
    category: 'CTAs',
    content: `<section data-gjs-name="CTA Section" class="cta-07">
      <style>
        .cta-07 { padding: 80px 20px; min-height: 50vh; display: flex; justify-content: center; align-items: center; background-color: #1e1e24; font-family: 'Inter', sans-serif; color: #fff; }
        .cta-07 .container { max-width: 1200px; width: 100%; margin: 0 auto; }
        .cta-07 .widget-layout { display: flex; flex-direction: column; align-items: center; text-align: center; }
        .cta-07 h2 { font-family: 'Space Grotesk', sans-serif; font-size: 3rem; line-height: 1.1; margin-bottom: 1.5rem; }
        .cta-07 p { line-height: 1.6; color: rgba(255,255,255,0.7); margin-bottom: 2rem; max-width: 600px; }
        .cta-07 .input-pill { background: #2b2b36; padding: 10px; border-radius: 100px; display: flex; width: 100%; max-width: 500px; margin-top: 30px; border: 1px solid #444; transition: border 0.3s, box-shadow 0.3s; }
        .cta-07 .input-pill:focus-within { border-color: #6c63ff; box-shadow: 0 0 15px rgba(108, 99, 255, 0.3); }
        .cta-07 .input-pill input { background: transparent; border: none; color: white; padding: 15px 20px; flex-grow: 1; outline: none; font-size: 1rem; }
        .cta-07 .input-pill input::placeholder { color: rgba(255,255,255,0.5); }
        .cta-07 .btn-subscribe { background: #6c63ff; color: white; border: none; padding: 15px 35px; border-radius: 50px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .cta-07 .btn-subscribe:hover { background: #5a52e0; }
        @media (max-width: 768px) { .cta-07 h2 { font-size: 2.5rem !important; } .cta-07 .input-pill { flex-direction: column; border-radius: 20px; } .cta-07 .btn-subscribe { width: 100%; border-radius: 50px; margin-top: 10px; } }
      </style>
      <div data-gjs-name="Container" class="container">
        <div data-gjs-name="Widget Layout" class="widget-layout">
          <h2 data-gjs-name="Title">Stay in the Loop</h2>
          <p data-gjs-name="Description">Get the latest design trends and code snippets delivered directly to your inbox weekly.</p>
          <form data-gjs-name="Input Form" class="input-pill">
            <input data-gjs-name="Email Input" type="email" placeholder="enter your email address...">
            <button data-gjs-name="Submit Button" type="submit" class="btn-subscribe">Subscribe</button>
          </form>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 8. TYPOGRAPHIC MARQUEE
  // ============================================
  {
    id: 'cta-marquee',
    label: 'Marquee Bold',
    category: 'CTAs',
    content: `<section data-gjs-name="CTA Section" class="cta-08">
      <style>
        .cta-08 { padding: 100px 0; display: flex; flex-direction: column; overflow: hidden; background-color: #ffc300; font-family: 'Inter', sans-serif; color: #000; }
        .cta-08 .marquee-container { width: 100%; overflow: hidden; white-space: nowrap; }
        .cta-08 .marquee-text { font-family: 'Space Grotesk', sans-serif; font-size: 8rem; font-weight: 900; display: inline-block; animation: scroll08 20s linear infinite; }
        .cta-08 .cta-actions { margin-top: 40px; display: flex; justify-content: center; }
        .cta-08 .btn-big-black { background: #000; color: #fff; font-size: 1.5rem; padding: 20px 60px; border: none; cursor: pointer; transition: transform 0.2s; text-decoration: none; display: inline-block; }
        .cta-08 .btn-big-black:hover { transform: rotate(-2deg) scale(1.1); }
        @keyframes scroll08 { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }
        @media (max-width: 768px) { .cta-08 .marquee-text { font-size: 4rem; } .cta-08 .btn-big-black { font-size: 1.2rem; padding: 15px 40px; } }
      </style>
      <div data-gjs-name="Marquee Container" class="marquee-container">
        <div data-gjs-name="Marquee Text" class="marquee-text">LET'S WORK TOGETHER — CREATE SOMETHING HUGE — LET'S WORK TOGETHER — CREATE SOMETHING HUGE —&nbsp;</div>
      </div>
      <div data-gjs-name="Actions" class="cta-actions">
        <a data-gjs-name="Button" href="#" class="btn-big-black">CONTACT US</a>
      </div>
    </section>`
  },

  // ============================================
  // 9. ABSTRACT 3D SHAPES
  // ============================================
  {
    id: 'cta-3d-shapes',
    label: '3D Shapes',
    category: 'CTAs',
    content: `<section data-gjs-name="CTA Section" class="cta-09">
      <style>
        .cta-09 { padding: 80px 20px; min-height: 50vh; display: flex; justify-content: center; align-items: center; background-color: #0f172a; position: relative; overflow: hidden; font-family: 'Inter', sans-serif; color: #fff; }
        .cta-09 .shape { position: absolute; background: linear-gradient(135deg, #3b82f6, #8b5cf6); border-radius: 40%; filter: blur(40px); opacity: 0.6; animation: float09 6s ease-in-out infinite; }
        .cta-09 .s1 { top: 10%; left: 10%; width: 200px; height: 200px; }
        .cta-09 .s2 { bottom: 10%; right: 10%; width: 300px; height: 300px; animation-delay: 1s; background: linear-gradient(135deg, #ec4899, #f43f5e); }
        .cta-09 .container { max-width: 1200px; width: 100%; margin: 0 auto; }
        .cta-09 .z-content { position: relative; z-index: 10; text-align: center; }
        .cta-09 h2 { font-family: 'Space Grotesk', sans-serif; font-size: 4rem; margin-bottom: 2rem; line-height: 1.1; }
        .cta-09 p { line-height: 1.6; color: rgba(255,255,255,0.7); margin-bottom: 2rem; max-width: 600px; margin-left: auto; margin-right: auto; }
        .cta-09 .btn-3d { background: linear-gradient(to bottom, #3b82f6, #2563eb); border: none; border-bottom: 4px solid #1d4ed8; color: white; padding: 15px 40px; border-radius: 8px; font-weight: bold; font-size: 1.2rem; cursor: pointer; transition: all 0.1s; text-decoration: none; display: inline-block; }
        .cta-09 .btn-3d:active { border-bottom: 0px; transform: translateY(4px); }
        .cta-09 .btn-3d:hover { background: linear-gradient(to bottom, #60a5fa, #3b82f6); }
        @keyframes float09 { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }
        @media (max-width: 768px) { .cta-09 h2 { font-size: 2.5rem !important; } }
      </style>
      <div data-gjs-name="Shape 1" class="shape s1"></div>
      <div data-gjs-name="Shape 2" class="shape s2"></div>
      <div data-gjs-name="Container" class="container">
        <div data-gjs-name="Content" class="z-content">
          <h2 data-gjs-name="Title">Ready to Launch?</h2>
          <p data-gjs-name="Description">Take your product to the next dimension with our comprehensive launchpad.</p>
          <a data-gjs-name="Button" href="#" class="btn-3d">Lift Off 🚀</a>
        </div>
      </div>
    </section>`
  },
]
