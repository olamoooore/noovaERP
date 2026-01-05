// Hero Section Presets for GrapesJS Website Builder
// 7 Premium Hero Designs with complete inline styles, fonts, and icons

export const heroPresets = [
  // ============================================
  // HERO 1: Modern Finance (Dark Glass)
  // ============================================
  {
    id: 'hero-modern-finance',
    label: 'Modern Finance',
    category: 'Hero',
    content: `
      <section class="hero-section" id="hero-modern-finance">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,600;1,400&family=Space+Grotesk:wght@400;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        
        <style>
          #hero-modern-finance {
            --bg-color: #08080a;
            --accent-glow: #4338ca;
            --accent-secondary: #ec4899;
            --glass-surface: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --text-primary: #ffffff;
            --text-secondary: #9ca3af;
            --font-serif: 'Playfair Display', serif;
            --font-sans: 'Inter', sans-serif;
            --font-mono: 'Space Grotesk', monospace;
            
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 4rem 5%;
            background-color: var(--bg-color);
            color: var(--text-primary);
            font-family: var(--font-sans);
            -webkit-font-smoothing: antialiased;
          }
          
          #hero-modern-finance * { margin: 0; padding: 0; box-sizing: border-box; }
          
          #hero-modern-finance .ambient-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.4;
            z-index: 0;
            animation: orbFloat 10s ease-in-out infinite alternate;
          }
          #hero-modern-finance .orb-1 {
            width: 60vw; height: 60vw;
            background: radial-gradient(circle, var(--accent-glow), transparent 70%);
            top: -20%; left: -20%;
          }
          #hero-modern-finance .orb-2 {
            width: 50vw; height: 50vw;
            background: radial-gradient(circle, var(--accent-secondary), transparent 70%);
            bottom: -10%; right: -10%;
            animation-delay: -5s;
          }
          
          #hero-modern-finance .grid-overlay {
            position: absolute;
            inset: 0;
            background-image: linear-gradient(var(--glass-border) 1px, transparent 1px),
            linear-gradient(90deg, var(--glass-border) 1px, transparent 1px);
            background-size: 40px 40px;
            opacity: 0.2;
            z-index: 1;
            mask-image: radial-gradient(circle at center, black 40%, transparent 100%);
            -webkit-mask-image: radial-gradient(circle at center, black 40%, transparent 100%);
          }
          
          #hero-modern-finance .hero-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 1400px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
          }
          
          #hero-modern-finance .hero-content {
            display: flex;
            flex-direction: column;
            justify-content: center;
          }
          
          #hero-modern-finance .location-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: var(--glass-surface);
            border: 1px solid var(--glass-border);
            border-radius: 50px;
            width: fit-content;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 2rem;
            color: var(--text-secondary);
            backdrop-filter: blur(10px);
          }
          #hero-modern-finance .dot { width: 6px; height: 6px; background: #22c55e; border-radius: 50%; box-shadow: 0 0 8px #22c55e; }
          
          #hero-modern-finance h1 {
            font-family: var(--font-serif);
            font-size: 5.5rem;
            line-height: 1;
            font-weight: 400;
            margin-bottom: 1.5rem;
          }
          #hero-modern-finance h1 span {
            font-family: var(--font-sans);
            font-weight: 300;
            font-style: italic;
            color: #a5b4fc;
            display: block;
            font-size: 4rem;
            letter-spacing: -1px;
          }
          
          #hero-modern-finance p.hero-desc {
            font-size: 1.125rem;
            color: var(--text-secondary);
            line-height: 1.7;
            max-width: 90%;
            margin-bottom: 3rem;
            border-left: 1px solid var(--glass-border);
            padding-left: 20px;
          }
          
          #hero-modern-finance .action-group {
            display: flex;
            gap: 1.5rem;
            align-items: center;
          }
          
          #hero-modern-finance .btn-main {
            padding: 18px 40px;
            background: #fff;
            color: #000;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            letter-spacing: 0.5px;
            transition: transform 0.2s;
          }
          #hero-modern-finance .btn-main:hover { transform: translateY(-2px); }
          
          #hero-modern-finance .btn-link {
            color: #fff;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 400;
            transition: opacity 0.2s;
          }
          #hero-modern-finance .btn-link:hover { opacity: 0.8; }
          #hero-modern-finance .btn-link i { padding: 8px; border: 1px solid rgba(255,255,255,0.3); border-radius: 50%; }
          
          #hero-modern-finance .hero-visual {
            position: relative;
            height: 600px;
            display: flex;
            align-items: center;
            justify-content: center;
            perspective: 2000px;
          }
          
          #hero-modern-finance .composition-layer {
            position: relative;
            width: 400px;
            height: 550px;
            transform-style: preserve-3d;
            transform: rotateY(-15deg) rotateX(10deg);
            animation: subtleFloat 6s ease-in-out infinite;
          }
          
          #hero-modern-finance .interface-card {
            position: absolute;
            inset: 0;
            background: rgba(20, 20, 25, 0.7);
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(25px);
            border-radius: 30px;
            box-shadow: -30px 30px 60px rgba(0,0,0,0.5), inset 0 0 0 1px rgba(255,255,255,0.05);
            display: flex;
            flex-direction: column;
            padding: 2rem;
            z-index: 2;
          }
          
          #hero-modern-finance .float-element {
            position: absolute;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            backdrop-filter: blur(15px);
            border-radius: 20px;
            z-index: 3;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
          }
          
          #hero-modern-finance .fe-1 {
            top: 50px; right: -60px;
            transform: translateZ(50px);
            animation: floatDelay 5s ease-in-out infinite;
          }
          #hero-modern-finance .fe-2 {
            bottom: 80px; left: -60px;
            transform: translateZ(80px);
            animation: floatDelay 7s ease-in-out infinite reverse;
          }
          
          #hero-modern-finance .card-top { display: flex; justify-content: space-between; margin-bottom: 40px; }
          #hero-modern-finance .chip { width: 40px; height: 40px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.2); display: grid; place-items: center; }
          
          #hero-modern-finance .big-balance { font-family: var(--font-mono); font-size: 2.5rem; margin-bottom: 5px; font-weight: 700; }
          #hero-modern-finance .balance-sub { color: #aaa; font-size: 0.9rem; margin-bottom: 40px; }
          
          #hero-modern-finance .chart-visual {
            flex: 1;
            display: flex;
            align-items: flex-end;
            gap: 8px;
            margin-bottom: 30px;
          }
          #hero-modern-finance .chart-col {
            width: 100%;
            background: linear-gradient(to top, rgba(255,255,255,0.1), rgba(255,255,255,0.3));
            border-radius: 4px;
          }
          
          #hero-modern-finance .finance-ticker {
            position: absolute;
            bottom: 0; left: 0; width: 100%;
            background: rgba(255,255,255,0.02);
            border-top: 1px solid var(--glass-border);
            padding: 15px 0;
            overflow: hidden;
            display: flex;
            z-index: 5;
            backdrop-filter: blur(5px);
          }
          #hero-modern-finance .ticker-track {
            display: flex;
            gap: 3rem;
            animation: marquee 30s linear infinite;
            white-space: nowrap;
          }
          #hero-modern-finance .ticker-item {
            color: var(--text-secondary);
            font-family: var(--font-mono);
            font-size: 0.85rem;
            display: flex;
            gap: 10px;
          }
          #hero-modern-finance .t-val { color: #fff; font-weight: bold; }
          #hero-modern-finance .t-up { color: #4ade80; }
          #hero-modern-finance .t-down { color: #ef4444; }
          
          @keyframes orbFloat { 0% { transform: translate(0,0); } 100% { transform: translate(40px, 40px); } }
          @keyframes subtleFloat { 0%, 100% { transform: rotateY(-15deg) rotateX(10deg) translateY(0); } 50% { transform: rotateY(-15deg) rotateX(10deg) translateY(-20px); } }
          @keyframes floatDelay { 0%, 100% { transform: translateZ(50px) translateY(0); } 50% { transform: translateZ(50px) translateY(-15px); } }
          @keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }
          
          @media (max-width: 900px) {
            #hero-modern-finance .hero-container { grid-template-columns: 1fr; text-align: center; }
            #hero-modern-finance h1 { font-size: 3.5rem; }
            #hero-modern-finance p.hero-desc { border: none; padding: 0; margin: 0 auto 2rem auto; }
            #hero-modern-finance .action-group { justify-content: center; }
            #hero-modern-finance .hero-visual { height: 500px; margin-top: 40px; perspective: none; }
            #hero-modern-finance .composition-layer { transform: none; width: 100%; max-width: 350px; animation: none; }
            #hero-modern-finance .fe-1, #hero-modern-finance .fe-2 { display: none; }
          }
        </style>
        
        <div class="ambient-orb orb-1"></div>
        <div class="ambient-orb orb-2"></div>
        <div class="grid-overlay"></div>
        
        <div class="hero-container">
          <div class="hero-content">
            <div class="location-pill">
              <div class="dot"></div> London • Zürich • Singapore
            </div>
            
            <h1>
              Wealth Without
              <span>Borders.</span>
            </h1>
            
            <p class="hero-desc">
              The first private banking interface built for the digital nomad. 
              Manage multi-currency portfolios, tokenized assets, and global payments 
              with Swiss-grade privacy.
            </p>
            
            <div class="action-group">
              <a href="#" class="btn-main">Become a Member</a>
              <a href="#" class="btn-link">
                <i class="ri-play-fill"></i>
                Watch The Film
              </a>
            </div>
          </div>
          
          <div class="hero-visual">
            <div class="composition-layer">
              
              <div class="float-element fe-1">
                <div style="background:#2563eb; width:30px; height:30px; border-radius:50%; display:grid; place-items:center;">
                  <i class="ri-arrow-right-up-line" style="font-size:0.9rem;"></i>
                </div>
                <div>
                  <div style="font-size:0.7rem; color:#aaa;">RECEIVED</div>
                  <div style="font-weight:bold; font-size:0.9rem;">+ €42,000.00</div>
                </div>
              </div>
              
              <div class="float-element fe-2">
                <div style="background:#fff; color:#000; width:30px; height:30px; border-radius:50%; display:grid; place-items:center;">
                  <i class="ri-shield-star-line" style="font-size:0.9rem;"></i>
                </div>
                <div style="font-size:0.8rem; font-weight:600;">Assets Verified</div>
              </div>
              
              <div class="interface-card">
                <div class="card-top">
                  <div class="chip"><i class="ri-wireless-charging-line"></i></div>
                  <div style="text-align:right;">
                    <div style="font-size:0.7rem; color:#aaa;">STATUS</div>
                    <div style="font-size:0.8rem; color:#4ade80;">ACTIVE</div>
                  </div>
                </div>
                
                <div style="margin-top:auto;">
                  <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                    <div class="balance-sub">Current Portfolio</div>
                    <div style="font-size:1.5rem; margin-bottom:35px;"><i class="ri-more-fill"></i></div>
                  </div>
                  <div class="big-balance">$2,840,920</div>
                  
                  <div class="chart-visual">
                    <div class="chart-col" style="height: 30%"></div>
                    <div class="chart-col" style="height: 50%"></div>
                    <div class="chart-col" style="height: 40%"></div>
                    <div class="chart-col" style="height: 70%; background: #fff;"></div>
                    <div class="chart-col" style="height: 55%"></div>
                    <div class="chart-col" style="height: 85%"></div>
                    <div class="chart-col" style="height: 60%"></div>
                  </div>
                </div>
              </div>
              
            </div>
          </div>
        </div>
        
        <div class="finance-ticker">
          <div class="ticker-track">
            <div class="ticker-item">EUR/USD <span class="t-val">1.092</span> <span class="t-up">+0.4%</span></div>
            <div class="ticker-item">GBP/USD <span class="t-val">1.264</span> <span class="t-down">-0.1%</span></div>
            <div class="ticker-item">BTC/USD <span class="t-val">64,203</span> <span class="t-up">+2.4%</span></div>
            <div class="ticker-item">ETH/USD <span class="t-val">3,490</span> <span class="t-up">+1.2%</span></div>
            <div class="ticker-item">JPY/USD <span class="t-val">149.2</span> <span class="t-down">-0.5%</span></div>
            <div class="ticker-item">GOLD <span class="t-val">2,301</span> <span class="t-up">+0.8%</span></div>
            <div class="ticker-item">EUR/USD <span class="t-val">1.092</span> <span class="t-up">+0.4%</span></div>
            <div class="ticker-item">GBP/USD <span class="t-val">1.264</span> <span class="t-down">-0.1%</span></div>
            <div class="ticker-item">BTC/USD <span class="t-val">64,203</span> <span class="t-up">+2.4%</span></div>
            <div class="ticker-item">ETH/USD <span class="t-val">3,490</span> <span class="t-up">+1.2%</span></div>
            <div class="ticker-item">JPY/USD <span class="t-val">149.2</span> <span class="t-down">-0.5%</span></div>
            <div class="ticker-item">GOLD <span class="t-val">2,301</span> <span class="t-up">+0.8%</span></div>
          </div>
        </div>
      </section>
    `
  },


  // ============================================
  // HERO 2: Lumière Beauty (Elegant Arch)
  // ============================================
  {
    id: 'hero-lumiere-beauty',
    label: 'Lumière Beauty',
    category: 'Hero',
    content: `
      <section class="beauty-hero" id="hero-lumiere-beauty">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,500;0,700;1,400&family=Manrope:wght@300;500;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        
        <style>
          #hero-lumiere-beauty {
            --bg-bone: #F2F0EB;
            --text-dark: #1a1a1a;
            --accent-clay: #9A5B4F;
            --accent-sage: #8da399;
            --font-serif: 'Cormorant Garamond', serif;
            --font-sans: 'Manrope', sans-serif;
            --ease-lux: cubic-bezier(0.25, 1, 0.5, 1);
            
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 5%;
            overflow: hidden;
            background-color: var(--bg-bone);
            color: var(--text-dark);
            font-family: var(--font-sans);
            -webkit-font-smoothing: antialiased;
          }
          
          #hero-lumiere-beauty * { margin: 0; padding: 0; box-sizing: border-box; }
          
          #hero-lumiere-beauty .grain-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none;
            z-index: 999;
            opacity: 0.4;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
            mix-blend-mode: overlay;
          }
          
          #hero-lumiere-beauty .hero-wrapper {
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            gap: 4rem;
            max-width: 1400px;
            width: 100%;
            align-items: center;
            position: relative;
            z-index: 2;
          }
          
          #hero-lumiere-beauty .text-content {
            position: relative;
            z-index: 10;
          }
          
          #hero-lumiere-beauty .subtitle {
            font-family: var(--font-sans);
            text-transform: uppercase;
            letter-spacing: 3px;
            font-size: 0.75rem;
            color: var(--accent-clay);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 15px;
          }
          #hero-lumiere-beauty .subtitle::before { content: ''; width: 40px; height: 1px; background: var(--accent-clay); }
          
          #hero-lumiere-beauty h1 {
            font-family: var(--font-serif);
            font-size: 6rem;
            line-height: 0.9;
            font-weight: 400;
            margin-bottom: 2rem;
            color: var(--text-dark);
          }
          #hero-lumiere-beauty h1 i {
            font-style: italic;
            font-weight: 300;
            color: var(--accent-sage);
          }
          
          #hero-lumiere-beauty p.description {
            font-size: 1.1rem;
            line-height: 1.6;
            color: #555;
            max-width: 450px;
            margin-bottom: 3rem;
          }
          
          #hero-lumiere-beauty .btn-beauty {
            background: var(--text-dark);
            color: #fff;
            padding: 18px 45px;
            border-radius: 200px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            font-family: var(--font-sans);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 1px;
            transition: all 0.4s var(--ease-lux);
            overflow: hidden;
            position: relative;
          }
          #hero-lumiere-beauty .btn-beauty:hover {
            background: var(--accent-clay);
            padding-right: 60px;
          }
          #hero-lumiere-beauty .btn-beauty::after {
            content: '→';
            position: absolute;
            right: 30px;
            opacity: 0;
            transform: translateX(-10px);
            transition: 0.3s;
          }
          #hero-lumiere-beauty .btn-beauty:hover::after { opacity: 1; transform: translateX(0); }
          
          #hero-lumiere-beauty .review-snippet {
            margin-top: 3rem;
            display: flex;
            align-items: center;
            gap: 15px;
          }
          #hero-lumiere-beauty .avatars { display: flex; }
          #hero-lumiere-beauty .avatars img {
            width: 40px; height: 40px; border-radius: 50%; border: 2px solid var(--bg-bone);
            margin-left: -15px; object-fit: cover;
          }
          #hero-lumiere-beauty .avatars img:first-child { margin-left: 0; }
          
          #hero-lumiere-beauty .rating-text { font-size: 0.8rem; font-weight: 600; }
          #hero-lumiere-beauty .stars { color: #d4a373; font-size: 0.9rem; }
          
          #hero-lumiere-beauty .visual-container {
            position: relative;
            height: 80vh;
            display: flex;
            justify-content: center;
            align-items: center;
          }
          
          #hero-lumiere-beauty .arch-mask {
            width: 100%;
            height: 100%;
            border-radius: 300px 300px 0 0;
            overflow: hidden;
            position: relative;
            box-shadow: 20px 20px 0px rgba(0,0,0,0.05);
            animation: revealUp 1.5s var(--ease-lux);
          }
          
          #hero-lumiere-beauty .arch-mask img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 2s var(--ease-lux);
          }
          #hero-lumiere-beauty:hover .arch-mask img { transform: scale(1.05); }
          
          #hero-lumiere-beauty .spinning-seal {
            position: absolute;
            bottom: 50px;
            left: -60px;
            width: 140px;
            height: 140px;
            background: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            animation: spin 20s linear infinite;
            z-index: 5;
          }
          
          #hero-lumiere-beauty .float-card {
            position: absolute;
            top: 20%;
            right: -40px;
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.05);
            max-width: 200px;
            animation: float 6s ease-in-out infinite;
          }
          #hero-lumiere-beauty .ingredient-tag {
            font-size: 0.7rem; text-transform: uppercase; color: #888; letter-spacing: 1px;
            margin-bottom: 5px; display: block;
          }
          #hero-lumiere-beauty .ingredient-name {
            font-family: var(--font-serif); font-size: 1.4rem; line-height: 1; color: var(--text-dark);
          }
          
          #hero-lumiere-beauty .bg-flower {
            position: absolute;
            width: 600px;
            opacity: 0.08;
            top: -10%; right: -10%;
            z-index: 0;
            pointer-events: none;
            filter: grayscale(100%);
          }
          
          @keyframes revealUp { from { clip-path: inset(100% 0 0 0); } to { clip-path: inset(0 0 0 0); } }
          @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
          @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }
          
          @media (max-width: 1024px) {
            #hero-lumiere-beauty .hero-wrapper { grid-template-columns: 1fr; text-align: center; }
            #hero-lumiere-beauty .subtitle { justify-content: center; }
            #hero-lumiere-beauty .subtitle::before { display: none; }
            #hero-lumiere-beauty .text-content { display: flex; flex-direction: column; align-items: center; }
            #hero-lumiere-beauty h1 { font-size: 4rem; }
            #hero-lumiere-beauty .visual-container { height: 500px; width: 100%; max-width: 400px; margin: 0 auto; }
            #hero-lumiere-beauty .spinning-seal { bottom: -20px; left: -20px; width: 100px; height: 100px; }
            #hero-lumiere-beauty .float-card { display: none; }
          }
        </style>
        
        <div class="grain-overlay"></div>
        
        <img src="https://images.unsplash.com/photo-1490750967868-58cb75069ed6?q=80&w=1000&auto=format&fit=crop" class="bg-flower" alt="Texture">
        
        <div class="hero-wrapper">
          
          <div class="text-content">
            <div class="subtitle">Botanical Intelligence</div>
            <h1>
              Nature's <br>
              <i>Wisdom,</i> Refined.
            </h1>
            <p class="description">
              A collection of high-performance skincare rooted in biological science. 
              Clean formulas, clinically proven results, and 100% vegan.
            </p>
            
            <a href="#" class="btn-beauty">
              Shop The Collection
            </a>
            
            <div class="review-snippet">
              <div class="avatars">
                <img src="https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=100&auto=format&fit=crop" alt="User">
                <img src="https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=100&auto=format&fit=crop" alt="User">
                <img src="https://images.unsplash.com/photo-1554151228-14d9def656ec?w=100&auto=format&fit=crop" alt="User">
              </div>
              <div>
                <div class="stars">★★★★★</div>
                <div class="rating-text">4.9/5 from 2k+ Reviews</div>
              </div>
            </div>
          </div>
          
          <div class="visual-container">
            
            <div class="float-card">
              <span class="ingredient-tag">Key Ingredient</span>
              <span class="ingredient-name">Saffron &<br>Peptides</span>
            </div>
            
            <div class="spinning-seal">
              <svg viewBox="0 0 100 100" width="100" height="100">
                <path id="circlePath" d="M 50, 50 m -37, 0 a 37,37 0 1,1 74,0 a 37,37 0 1,1 -74,0" fill="transparent" />
                <text font-size="11.5" font-family="Manrope" font-weight="bold" fill="#000" letter-spacing="2">
                  <textPath href="#circlePath">
                    • PURE ORGANIC • EST 2024
                  </textPath>
                </text>
              </svg>
              <i class="ri-leaf-fill" style="position:absolute; font-size:1.5rem; color:#9A5B4F;"></i>
            </div>
            
            <div class="arch-mask">
              <img src="https://images.unsplash.com/photo-1552693673-1bf958298935?q=80&w=2073&auto=format&fit=crop" alt="Beauty Model">
            </div>
            
          </div>
          
        </div>
      </section>
    `
  },


  // ============================================
  // HERO 3: AGRI-X Precision Farming
  // ============================================
  {
    id: 'hero-agri-x',
    label: 'AGRI-X Farming',
    category: 'Hero',
    content: `
      <section class="agri-hero" id="hero-agri-x">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        
        <style>
          #hero-agri-x {
            --primary-green: #064e3b;
            --accent-lime: #bef264;
            --text-dark: #1f2937;
            --text-light: #f3f4f6;
            --font-heading: 'Outfit', sans-serif;
            --font-body: 'Plus Jakarta Sans', sans-serif;
            
            position: relative;
            height: 100vh;
            width: 100%;
            display: grid;
            grid-template-columns: 55% 45%;
            font-family: var(--font-body);
            color: var(--text-dark);
            overflow-x: hidden;
          }
          
          #hero-agri-x * { margin: 0; padding: 0; box-sizing: border-box; }
          
          #hero-agri-x .hero-visual {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: #1a1a1a;
          }
          
          #hero-agri-x .bg-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            z-index: 1;
            filter: brightness(0.85);
            transition: transform 10s ease;
          }
          
          #hero-agri-x .hero-visual:hover .bg-image { 
            transform: scale(1.05); 
          }
          
          #hero-agri-x .scan-overlay {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(to bottom, transparent 40%, rgba(190, 242, 100, 0.15) 50%, transparent 60%);
            background-size: 100% 200%;
            animation: scanField 4s linear infinite;
            pointer-events: none;
            z-index: 2;
          }
          
          #hero-agri-x .scan-line {
            position: absolute;
            top: 50%; left: 0; width: 100%; height: 2px;
            background: var(--accent-lime);
            box-shadow: 0 0 25px var(--accent-lime);
            animation: scanMove 4s linear infinite;
            z-index: 3;
            opacity: 0.8;
          }
          
          #hero-agri-x .widget-container {
            position: absolute;
            bottom: 40px; left: 40px;
            right: 40px;
            z-index: 5;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
          }
          
          #hero-agri-x .agri-widget {
            background: rgba(20, 20, 20, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.15);
            padding: 15px 20px;
            border-radius: 16px;
            color: white;
            flex: 1;
            min-width: 120px;
            animation: fadeUp 1s ease forwards;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
          }
          #hero-agri-x .agri-widget:nth-child(2) { animation-delay: 0.2s; }
          #hero-agri-x .agri-widget:nth-child(3) { animation-delay: 0.4s; }
          
          #hero-agri-x .w-icon { font-size: 1.4rem; margin-bottom: 5px; color: var(--accent-lime); }
          #hero-agri-x .w-label { font-size: 0.7rem; color: #aaa; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
          #hero-agri-x .w-value { font-size: 1.2rem; font-weight: 700; font-family: var(--font-heading); margin-top: 2px; }
          
          #hero-agri-x .hero-content {
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 4rem 5rem;
            position: relative;
            z-index: 10;
            height: 100%;
          }
          
          #hero-agri-x .leaf-pattern {
            position: absolute;
            top: 0; right: 0; bottom: 0; left: 0;
            background-image: radial-gradient(#dcfce7 1.5px, transparent 1.5px);
            background-size: 24px 24px;
            opacity: 0.6;
            z-index: 0;
            pointer-events: none;
          }
          
          #hero-agri-x .content-wrapper { position: relative; z-index: 2; }
          
          #hero-agri-x .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: #ecfdf5;
            color: var(--primary-green);
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 2rem;
            width: fit-content;
            border: 1px solid #a7f3d0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
          }
          #hero-agri-x .badge-dot { width: 8px; height: 8px; background: #10b981; border-radius: 50%; box-shadow: 0 0 0 2px #d1fae5; }
          
          #hero-agri-x h1 {
            font-family: var(--font-heading);
            font-size: 4rem;
            line-height: 1.05;
            color: var(--primary-green);
            margin-bottom: 1.5rem;
            font-weight: 700;
            letter-spacing: -1px;
          }
          #hero-agri-x h1 span {
            color: #4d7c0f; 
            position: relative;
            white-space: nowrap;
          }
          
          #hero-agri-x h1 span::after {
            content: '';
            position: absolute;
            bottom: 6px; left: 0;
            width: 100%; height: 16px;
            background: var(--accent-lime);
            z-index: -1;
            opacity: 0.5;
            transform: skewX(-10deg);
          }
          
          #hero-agri-x p {
            font-size: 1.125rem;
            color: #4b5563;
            line-height: 1.7;
            margin-bottom: 2.5rem;
            max-width: 480px;
          }
          
          #hero-agri-x .btn-group { display: flex; gap: 15px; flex-wrap: wrap; }
          
          #hero-agri-x .btn-agri {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--primary-green);
            color: white;
            padding: 16px 32px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
          }
          #hero-agri-x .btn-agri:hover {
            background: #022c22;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(6, 78, 59, 0.4);
          }
          
          #hero-agri-x .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-green);
            background: transparent;
            border: 1px solid #d1fae5;
            text-decoration: none;
            font-weight: 600;
            padding: 16px 32px;
            border-radius: 10px;
            transition: all 0.2s;
          }
          #hero-agri-x .btn-secondary:hover { background: #ecfdf5; border-color: #10b981; }
          
          #hero-agri-x .stats-row {
            display: flex;
            gap: 40px;
            margin-top: 4rem;
            border-top: 1px solid #e5e7eb;
            padding-top: 2rem;
          }
          #hero-agri-x .stat-item h3 { font-size: 2.2rem; color: var(--primary-green); font-family: var(--font-heading); margin-bottom: 0px; font-weight: 700; }
          #hero-agri-x .stat-item p { font-size: 0.85rem; margin: 0; color: #6b7280; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
          
          @keyframes scanMove { 0% { top: 0%; opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } 100% { top: 100%; opacity: 0; } }
          @keyframes scanField { 0% { background-position: 0% 0%; } 100% { background-position: 0% 100%; } }
          @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
          
          @media (max-width: 1024px) {
            #hero-agri-x { 
              grid-template-columns: 1fr; 
              height: auto;
            }
            #hero-agri-x .hero-visual { 
              height: 50vh;
              width: 100%;
              order: -1; 
            }
            #hero-agri-x .hero-content { 
              padding: 4rem 5%; 
              text-align: center; 
              min-height: 50vh;
            }
            #hero-agri-x .content-wrapper { display: flex; flex-direction: column; align-items: center; }
            #hero-agri-x .badge { margin: 0 auto 1.5rem auto; }
            #hero-agri-x h1 { font-size: 2.5rem; }
            #hero-agri-x .btn-group { justify-content: center; }
            #hero-agri-x .stats-row { justify-content: center; width: 100%; gap: 20px; }
            #hero-agri-x .stat-item h3 { font-size: 1.8rem; }
            
            #hero-agri-x .widget-container { width: 100%; left: 0; right: 0; bottom: 0; justify-content: center; padding: 20px; background: linear-gradient(to top, rgba(0,0,0,0.8), transparent); }
            #hero-agri-x .agri-widget { flex: 1; min-width: auto; padding: 10px; font-size: 0.8rem; }
            #hero-agri-x .w-value { font-size: 1rem; }
            #hero-agri-x .w-icon { font-size: 1.2rem; }
          }
        </style>
        
        <div class="hero-visual">
          <img 
            src="https://images.unsplash.com/photo-1500937386664-56d1dfef3854?q=80&w=2070&auto=format&fit=crop" 
            class="bg-image" 
            alt="Precision Agriculture Field"
          >
          
          <div class="scan-overlay"></div>
          <div class="scan-line"></div>
          
          <div class="widget-container">
            <div class="agri-widget">
              <div class="w-icon"><i class="ri-drop-fill"></i></div>
              <div class="w-label">Moisture</div>
              <div class="w-value">68% <span style="font-size:0.8rem; color:#4ade80;">Optimal</span></div>
            </div>
            
            <div class="agri-widget">
              <div class="w-icon"><i class="ri-sun-cloudy-line"></i></div>
              <div class="w-label">Forecast</div>
              <div class="w-value">24°C <span style="font-size:0.8rem;">Clear</span></div>
            </div>
            
            <div class="agri-widget" style="background: rgba(190, 242, 100, 0.9); color: #064e3b; border:none;">
              <div class="w-icon" style="color:#064e3b"><i class="ri-plant-fill"></i></div>
              <div class="w-label" style="color:#064e3b">Yield Est.</div>
              <div class="w-value">+12.4%</div>
            </div>
          </div>
        </div>
        
        <div class="hero-content">
          <div class="leaf-pattern"></div>
          
          <div class="content-wrapper">
            <div class="badge">
              <div class="badge-dot"></div> AI-Powered Cultivation
            </div>
            
            <h1>
              Farming intelligence <br>
              for a <span>greener earth.</span>
            </h1>
            
            <p>
              Monitor crop health, optimize irrigation, and predict yields with satellite precision. 
              Sustainable agriculture starts with smarter data.
            </p>
            
            <div class="btn-group">
              <a href="#" class="btn-agri">
                Start Scanning <i class="ri-arrow-right-line"></i>
              </a>
              <a href="#" class="btn-secondary">
                <i class="ri-play-circle-line" style="font-size: 1.2rem;"></i> View Demo
              </a>
            </div>
            
            <div class="stats-row">
              <div class="stat-item">
                <h3>2M+</h3>
                <p>Acres Monitored</p>
              </div>
              <div class="stat-item">
                <h3>30%</h3>
                <p>Water Saved</p>
              </div>
              <div class="stat-item">
                <h3>15k</h3>
                <p>Farmers</p>
              </div>
            </div>
          </div>
        </div>
      </section>
    `
  },


  // ============================================
  // HERO 4: INFINITI KPOP Event
  // ============================================
  {
    id: 'hero-infiniti-kpop',
    label: 'INFINITI KPOP',
    category: 'Hero',
    content: `
      <section class="kpop-hero" id="hero-infiniti-kpop">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;600;800&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        
        <style>
          #hero-infiniti-kpop {
            --neon-pink: #ff2e93;
            --neon-blue: #00f7ff;
            --neon-purple: #8a2be2;
            --cyber-black: #020205;
            --font-display: 'Anton', sans-serif;
            --font-tech: 'Syncopate', sans-serif;
            
            position: relative;
            height: 100vh;
            width: 100%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--cyber-black);
            color: #fff;
            font-family: 'Inter', sans-serif;
          }
          
          #hero-infiniti-kpop * { margin: 0; padding: 0; box-sizing: border-box; }
          
          #hero-infiniti-kpop .bg-layer {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            z-index: 0;
          }
          
          #hero-infiniti-kpop .bg-image {
            width: 100%; height: 100%;
            object-fit: cover;
            object-position: center;
            background: url('https://images.unsplash.com/photo-1470225620780-dba8ba36b745?q=80&w=2070&auto=format&fit=crop') no-repeat center center/cover;
            filter: brightness(0.5) contrast(1.1) saturate(1.3) hue-rotate(10deg);
            transform: scale(1.05);
            transition: transform 15s ease;
          }
          #hero-infiniti-kpop:hover .bg-image { transform: scale(1.0); }
          
          #hero-infiniti-kpop .gradient-overlay {
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at center, transparent 20%, var(--cyber-black) 100%),
                        linear-gradient(to right, var(--cyber-black) 10%, transparent 50%, var(--cyber-black) 90%);
            z-index: 1;
          }
          
          #hero-infiniti-kpop .bg-deco-text {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%) rotate(-5deg);
            font-size: 25vw;
            font-weight: 900;
            white-space: nowrap;
            color: transparent;
            -webkit-text-stroke: 2px rgba(255, 255, 255, 0.05);
            z-index: 1;
            font-family: var(--font-display);
            pointer-events: none;
          }
          
          #hero-infiniti-kpop .content-grid {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 1400px;
            padding: 0 5%;
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            align-items: center;
            height: 100%;
          }
          
          #hero-infiniti-kpop .hero-text {
            position: relative;
            padding-top: 40px;
          }
          
          #hero-infiniti-kpop .pre-title {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            color: #fff;
            font-family: var(--font-tech);
            font-weight: 700;
            font-size: 0.9rem;
            padding: 6px 16px;
            margin-bottom: 25px;
            clip-path: polygon(0 0, 100% 0, 95% 100%, 0% 100%);
          }
          
          #hero-infiniti-kpop .glitch-title {
            font-family: var(--font-display);
            font-size: 7.5rem;
            line-height: 0.9;
            text-transform: uppercase;
            position: relative;
            color: white;
            margin-bottom: 20px;
            letter-spacing: -1px;
          }
          
          #hero-infiniti-kpop .glitch-title span { display: block; }
          
          #hero-infiniti-kpop .glitch-layer {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            opacity: 0;
          }
          
          #hero-infiniti-kpop:hover .glitch-layer {
            opacity: 1;
            animation: glitch-anim 0.4s cubic-bezier(.25, .46, .45, .94) both infinite;
            color: var(--neon-blue);
            z-index: -1;
            mix-blend-mode: screen;
          }
          #hero-infiniti-kpop:hover .glitch-layer:nth-child(2) {
            color: var(--neon-pink);
            animation: glitch-anim-2 0.4s cubic-bezier(.25, .46, .45, .94) reverse both infinite;
            z-index: -2;
          }
          
          #hero-infiniti-kpop .tagline {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ccc;
            margin-bottom: 40px;
            letter-spacing: 1px;
            background: linear-gradient(to right, #fff, #aaa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
          }
          
          #hero-infiniti-kpop .cta-buttons {
            display: flex;
            gap: 20px;
          }
          
          #hero-infiniti-kpop .btn-ticket {
            background: var(--neon-blue);
            color: var(--cyber-black);
            padding: 18px 45px;
            font-family: var(--font-tech);
            font-weight: 700;
            text-decoration: none;
            clip-path: polygon(10% 0, 100% 0, 100% 70%, 90% 100%, 0 100%, 0 30%);
            transition: 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-transform: uppercase;
            display: flex; align-items: center; gap: 10px;
          }
          #hero-infiniti-kpop .btn-ticket:hover {
            background: var(--neon-pink);
            color: white;
            transform: translate(-4px, -4px);
            box-shadow: 8px 8px 0px rgba(138, 43, 226, 0.5);
          }
          
          #hero-infiniti-kpop .btn-lineup {
            background: transparent;
            color: white;
            padding: 18px 45px;
            font-family: var(--font-tech);
            font-weight: 700;
            text-decoration: none;
            border: 2px solid rgba(255,255,255,0.3);
            transition: 0.3s;
            text-transform: uppercase;
            display: flex; align-items: center; gap: 10px;
          }
          #hero-infiniti-kpop .btn-lineup:hover { border-color: var(--neon-blue); background: rgba(0, 247, 255, 0.1); }
          
          #hero-infiniti-kpop .visual-side {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            position: relative;
          }
          
          #hero-infiniti-kpop .cd-wrapper {
            position: relative;
            width: 380px;
            height: 380px;
            display: flex;
            align-items: center;
            justify-content: center;
          }
          
          #hero-infiniti-kpop .cd-disk {
            width: 100%; height: 100%;
            border-radius: 50%;
            background: repeating-radial-gradient(#111 0, #111 2px, #222 3px, #222 4px);
            box-shadow: 0 0 60px rgba(138, 43, 226, 0.5), 0 0 30px rgba(0, 247, 255, 0.3);
            position: absolute;
            animation: spin 8s linear infinite;
          }
          
          #hero-infiniti-kpop .cd-disk::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255,255,255,0.2), transparent 40%, rgba(255,46,147,0.2) 60%);
            filter: blur(2px);
          }
          
          #hero-infiniti-kpop .album-art {
            width: 180px; height: 180px;
            border-radius: 50%;
            background: url('https://images.unsplash.com/photo-1563089145-599997674d42?q=80&w=2070&auto=format&fit=crop') center/cover;
            position: relative;
            z-index: 2;
            border: 3px solid var(--cyber-black);
          }
          #hero-infiniti-kpop .cd-center-hole {
            position: absolute; width: 40px; height: 40px; background: var(--cyber-black); border-radius: 50%; z-index: 3;
            border: 2px solid rgba(255,255,255,0.1);
          }
          
          #hero-infiniti-kpop .event-countdown {
            margin-top: -50px;
            background: rgba(20, 20, 25, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1);
            border-top: 1px solid rgba(255,255,255,0.3);
            padding: 25px;
            border-radius: 16px;
            width: 340px;
            z-index: 5;
            position: relative;
            transform: rotate(-3deg);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
          }
          #hero-infiniti-kpop .event-countdown:hover { transform: rotate(0deg) scale(1.05); border-color: var(--neon-blue); }
          
          #hero-infiniti-kpop .countdown-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
            font-family: var(--font-tech); font-size: 0.9rem; color: var(--neon-blue);
          }
          
          #hero-infiniti-kpop .timer-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            text-align: center;
          }
          #hero-infiniti-kpop .time-box {
            background: rgba(255,255,255,0.05);
            padding: 10px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.1);
          }
          #hero-infiniti-kpop .time-val { font-family: var(--font-display); font-size: 2.5rem; line-height: 1; margin-bottom: 5px; }
          #hero-infiniti-kpop .time-label { font-size: 0.7rem; text-transform: uppercase; color: #aaa; letter-spacing: 1px; }
          
          #hero-infiniti-kpop .ticker-wrap {
            position: absolute;
            bottom: 20px;
            width: 100%;
            overflow: hidden;
            height: 50px;
            background: linear-gradient(90deg, var(--neon-purple), var(--neon-pink));
            display: flex;
            align-items: center;
            transform: rotate(2deg) scaleX(1.1);
            border: 1px solid rgba(255,255,255,0.5);
            z-index: 20;
            box-shadow: 0 0 30px rgba(255, 46, 147, 0.5);
          }
          
          #hero-infiniti-kpop .ticker {
            display: flex;
            white-space: nowrap;
            animation: marquee 20s linear infinite;
          }
          
          #hero-infiniti-kpop .ticker__item {
            color: #fff;
            font-family: var(--font-tech);
            font-weight: 700;
            font-size: 1.2rem;
            padding: 0 2rem;
            text-transform: uppercase;
            display: flex; align-items: center; gap: 10px;
          }
          #hero-infiniti-kpop .ticker__item i { color: var(--cyber-black); }
          
          @keyframes spin { 100% { transform: rotate(360deg); } }
          @keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }
          
          @keyframes glitch-anim {
            0% { transform: translate(0); clip-path: inset(0 0 0 0); }
            20% { transform: translate(-4px, 2px); clip-path: inset(10% 0 40% 0); }
            40% { transform: translate(-4px, -2px); clip-path: inset(40% 0 10% 0); }
            60% { transform: translate(4px, 2px); clip-path: inset(5% 0 60% 0); }
            80% { transform: translate(4px, -2px); clip-path: inset(60% 0 5% 0); }
            100% { transform: translate(0); clip-path: inset(0 0 0 0); }
          }
          @keyframes glitch-anim-2 {
            0% { transform: translate(0); clip-path: inset(0 0 0 0); }
            20% { transform: translate(4px, -2px); clip-path: inset(20% 0 30% 0); }
            40% { transform: translate(4px, 2px); clip-path: inset(30% 0 20% 0); }
            60% { transform: translate(-4px, -2px); clip-path: inset(15% 0 50% 0); }
            80% { transform: translate(-4px, 2px); clip-path: inset(50% 0 15% 0); }
            100% { transform: translate(0); clip-path: inset(0 0 0 0); }
          }
          
          @media (max-width: 1024px) {
            #hero-infiniti-kpop .content-grid { grid-template-columns: 1fr; text-align: center; justify-content: center; }
            #hero-infiniti-kpop .visual-side { display: none; }
            #hero-infiniti-kpop .glitch-title { font-size: 4.5rem; }
            #hero-infiniti-kpop .bg-deco-text { font-size: 40vw; }
            #hero-infiniti-kpop .cta-buttons { justify-content: center; }
            #hero-infiniti-kpop .ticker-wrap { bottom: 0; transform: none; }
            #hero-infiniti-kpop .tagline { font-size: 1.2rem; margin-bottom: 30px; }
            #hero-infiniti-kpop .pre-title { margin: 0 auto 20px auto; }
          }
        </style>
        
        <div class="bg-layer">
          <div class="bg-image"></div>
          <div class="gradient-overlay"></div>
        </div>
        
        <div class="bg-deco-text">INFINITI</div>
        
        <div class="content-grid">
          
          <div class="hero-text">
            <div class="pre-title"><i class="ri-global-line"></i> SEOUL 2026 KICKOFF</div>
            
            <h1 class="glitch-title">
              INFINITI<br>
              <span>KPOP</span>
              <span class="glitch-layer" aria-hidden="true">INFINITI<br>KPOP</span>
              <span class="glitch-layer" aria-hidden="true">INFINITI<br>KPOP</span>
            </h1>
            
            <div class="tagline">
              Your Premium K-Pop Destination.
            </div>
            
            <div class="cta-buttons">
              <a href="#" class="btn-ticket">
                <i class="ri-ticket-2-fill"></i> Get Tickets
              </a>
              <a href="#" class="btn-lineup">
                <i class="ri-user-star-fill"></i> View Lineup
              </a>
            </div>
          </div>
          
          <div class="visual-side">
            <div class="cd-wrapper">
              <div class="cd-disk"></div>
              <div class="album-art"></div>
              <div class="cd-center-hole"></div>
            </div>
            
            <div class="event-countdown">
              <div class="countdown-header">
                <span>NEXT EVENT: SHOWCASE STAGE</span>
                <i class="ri-timer-flash-line" style="font-size: 1.2rem;"></i>
              </div>
              
              <div class="timer-grid">
                <div class="time-box">
                  <div class="time-val">42</div>
                  <div class="time-label">Days</div>
                </div>
                <div class="time-box">
                  <div class="time-val">18</div>
                  <div class="time-label">Hours</div>
                </div>
                <div class="time-box" style="color:var(--neon-pink)">
                  <div class="time-val">09</div>
                  <div class="time-label">Mins</div>
                </div>
              </div>
              
              <div style="margin-top: 20px; display: flex; align-items: center; gap: 10px; font-size: 0.8rem; color: #aaa;">
                <i class="ri-map-pin-2-fill" style="color:var(--neon-purple)"></i> OLYMPIC STADIUM, SEOUL
              </div>
            </div>
          </div>
          
        </div>
        
        <div class="ticker-wrap">
          <div class="ticker">
            <div class="ticker__item">GLOBAL TOUR 2026</div>
            <div class="ticker__item"><i class="ri-star-fill"></i></div>
            <div class="ticker__item">SEOUL • TOKYO • LOS ANGELES • LONDON</div>
            <div class="ticker__item"><i class="ri-star-fill"></i></div>
            <div class="ticker__item" style="color:var(--cyber-black);">EARLY BIRD TICKETS SOLD OUT</div>
            <div class="ticker__item"><i class="ri-star-fill"></i></div>
            <div class="ticker__item">HEADLINERS ANNOUNCED SOON</div>
            <div class="ticker__item"><i class="ri-star-fill"></i></div>
            <div class="ticker__item">GLOBAL TOUR 2026</div>
            <div class="ticker__item"><i class="ri-star-fill"></i></div>
            <div class="ticker__item">SEOUL • TOKYO • LOS ANGELES • LONDON</div>
          </div>
        </div>
      </section>
    `
  },


  // ============================================
  // HERO 5: NEXUS Corporate Enterprise
  // ============================================
  {
    id: 'hero-nexus-corporate',
    label: 'NEXUS Corporate',
    category: 'Hero',
    content: `
      <section class="corp-hero" id="hero-nexus-corporate">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;600;800&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        
        <style>
          #hero-nexus-corporate {
            --corp-blue: #0f172a;
            --corp-accent: #3b82f6;
            --corp-gold: #d4af37;
            --text-white: #ffffff;
            --text-muted: #cbd5e1;
            --glass-surface: rgba(15, 23, 42, 0.7);
            --glass-border: rgba(255, 255, 255, 0.1);
            --font-main: 'Manrope', sans-serif;
            
            position: relative;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 1fr;
            align-items: center;
            overflow: hidden;
            padding: 0 6%;
            background-color: var(--corp-blue);
            color: var(--text-white);
            font-family: var(--font-main);
          }
          
          #hero-nexus-corporate * { margin: 0; padding: 0; box-sizing: border-box; }
          
          #hero-nexus-corporate .bg-container {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            z-index: 0;
            overflow: hidden;
          }
          
          #hero-nexus-corporate .bg-image {
            width: 100%; height: 100%;
            object-fit: cover;
            background: url('https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=2070&auto=format&fit=crop') center/cover;
            filter: brightness(0.4) saturate(0.8) contrast(1.1);
            transform: scale(1.05);
            transition: transform 20s ease;
          }
          #hero-nexus-corporate:hover .bg-image { transform: scale(1.0); }
          
          #hero-nexus-corporate .overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(105deg, var(--corp-blue) 40%, rgba(15, 23, 42, 0.85) 60%, rgba(15, 23, 42, 0.4) 100%);
            z-index: 1;
          }
          
          #hero-nexus-corporate .hero-content {
            position: relative;
            z-index: 10;
            max-width: 650px;
            animation: slideIn 1s ease-out;
          }
          
          #hero-nexus-corporate .label-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #60a5fa;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 25px;
          }
          #hero-nexus-corporate .pill-dot { width: 6px; height: 6px; background: #60a5fa; border-radius: 50%; box-shadow: 0 0 10px #60a5fa; }
          
          #hero-nexus-corporate h1 {
            font-size: 4.5rem;
            line-height: 1.1;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 1.5rem;
          }
          #hero-nexus-corporate h1 span {
            color: var(--corp-accent);
            position: relative;
            display: inline-block;
          }
          #hero-nexus-corporate h1 span::after {
            content: '';
            position: absolute;
            bottom: 8px; left: 0;
            width: 100%; height: 6px;
            background: var(--corp-accent);
            opacity: 0.3;
          }
          
          #hero-nexus-corporate p.lead {
            font-size: 1.25rem;
            line-height: 1.6;
            color: var(--text-muted);
            margin-bottom: 3rem;
            font-weight: 300;
            max-width: 90%;
          }
          
          #hero-nexus-corporate .btn-group { display: flex; gap: 15px; }
          
          #hero-nexus-corporate .btn-primary {
            background: var(--text-white);
            color: var(--corp-blue);
            padding: 18px 40px;
            font-weight: 700;
            text-decoration: none;
            border-radius: 2px;
            transition: all 0.3s;
            display: flex; align-items: center; gap: 10px;
          }
          #hero-nexus-corporate .btn-primary:hover {
            transform: translateY(-2px);
            background: #f8fafc;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
          }
          
          #hero-nexus-corporate .btn-outline {
            border: 1px solid rgba(255,255,255,0.3);
            color: var(--text-white);
            padding: 18px 40px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 2px;
            transition: all 0.3s;
          }
          #hero-nexus-corporate .btn-outline:hover {
            border-color: var(--text-white);
            background: rgba(255,255,255,0.05);
          }
          
          #hero-nexus-corporate .trust-bar {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            gap: 20px;
          }
          #hero-nexus-corporate .trust-label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-right: 10px; }
          #hero-nexus-corporate .partner-logo { opacity: 0.5; font-size: 1.5rem; transition: 0.3s; cursor: pointer; }
          #hero-nexus-corporate .partner-logo:hover { opacity: 1; color: var(--corp-accent); }
          
          #hero-nexus-corporate .hero-visual {
            position: relative;
            z-index: 10;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            perspective: 1000px;
          }
          
          #hero-nexus-corporate .radar-pulse {
            position: absolute;
            width: 500px; height: 500px;
            border: 1px solid rgba(59, 130, 246, 0.1);
            border-radius: 50%;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            z-index: 0;
          }
          #hero-nexus-corporate .radar-pulse::before, #hero-nexus-corporate .radar-pulse::after {
            content: ''; position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%); border-radius: 50%;
            border: 1px solid rgba(59, 130, 246, 0.2);
          }
          #hero-nexus-corporate .radar-pulse::before { width: 70%; height: 70%; animation: pulse 4s infinite; }
          #hero-nexus-corporate .radar-pulse::after { width: 40%; height: 40%; animation: pulse 4s infinite reverse; }
          
          #hero-nexus-corporate .strategy-card {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.95));
            width: 420px;
            padding: 35px;
            border-radius: 16px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 30px 60px -10px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 2;
            transform: rotateY(-15deg) rotateX(5deg);
            transition: transform 0.5s ease;
          }
          #hero-nexus-corporate .hero-visual:hover .strategy-card { transform: rotateY(0deg) rotateX(0deg); }
          
          #hero-nexus-corporate .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
          #hero-nexus-corporate .card-title { font-size: 0.9rem; font-weight: 700; color: var(--text-white); display: flex; align-items: center; gap: 8px; }
          #hero-nexus-corporate .status-badge { font-size: 0.7rem; background: rgba(16, 185, 129, 0.2); color: #34d399; padding: 4px 8px; border-radius: 4px; font-weight: 700; }
          
          #hero-nexus-corporate .metric-big { font-size: 3rem; font-weight: 800; margin-bottom: 5px; }
          #hero-nexus-corporate .metric-sub { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 30px; display: flex; gap: 10px; }
          #hero-nexus-corporate .gain { color: #34d399; display: flex; align-items: center; gap: 4px; }
          
          #hero-nexus-corporate .simple-chart {
            display: flex; align-items: flex-end; justify-content: space-between; height: 100px; gap: 8px;
            border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px;
          }
          #hero-nexus-corporate .bar { width: 100%; background: var(--corp-accent); opacity: 0.3; border-radius: 4px 4px 0 0; transition: 0.3s; position: relative; }
          #hero-nexus-corporate .bar:hover { opacity: 1; }
          #hero-nexus-corporate .bar.active { opacity: 1; background: linear-gradient(to top, var(--corp-accent), #60a5fa); }
          
          #hero-nexus-corporate .card-footer { margin-top: 25px; display: flex; align-items: center; justify-content: space-between; }
          #hero-nexus-corporate .team-stack { display: flex; }
          #hero-nexus-corporate .team-img { width: 35px; height: 35px; border-radius: 50%; border: 2px solid var(--corp-blue); margin-left: -10px; background: #334155; object-fit: cover; }
          #hero-nexus-corporate .team-img:first-child { margin-left: 0; }
          
          #hero-nexus-corporate .action-link { font-size: 0.8rem; color: #60a5fa; text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 5px; }
          
          @keyframes slideIn { from { opacity: 0; transform: translateX(-30px); } to { opacity: 1; transform: translateX(0); } }
          @keyframes pulse { 0% { transform: translate(-50%, -50%) scale(0.9); opacity: 0.5; } 100% { transform: translate(-50%, -50%) scale(1.1); opacity: 0; } }
          
          @media (max-width: 1024px) {
            #hero-nexus-corporate { grid-template-columns: 1fr; text-align: center; padding: 4rem 5%; height: auto; }
            #hero-nexus-corporate .overlay { background: linear-gradient(180deg, var(--corp-blue) 40%, rgba(15, 23, 42, 0.9) 100%); }
            #hero-nexus-corporate .hero-content { margin: 0 auto; }
            #hero-nexus-corporate h1 { font-size: 3rem; }
            #hero-nexus-corporate .btn-group { justify-content: center; }
            #hero-nexus-corporate .trust-bar { justify-content: center; }
            
            #hero-nexus-corporate .hero-visual { margin-top: 60px; height: auto; perspective: none; }
            #hero-nexus-corporate .strategy-card { transform: none; width: 100%; max-width: 400px; }
            #hero-nexus-corporate .radar-pulse { width: 300px; height: 300px; }
          }
        </style>
        
        <div class="bg-container">
          <div class="bg-image"></div>
          <div class="overlay"></div>
        </div>
        
        <div class="hero-content">
          <div class="label-pill">
            <div class="pill-dot"></div> Enterprise Ready
          </div>
          
          <h1>
            Scale your vision <br>
            <span>without limits.</span>
          </h1>
          
          <p class="lead">
            NEXUS provides the digital infrastructure for the world's most ambitious companies. 
            Streamline operations, automate logic, and drive revenue with our unified strategic platform.
          </p>
          
          <div class="btn-group">
            <a href="#" class="btn-primary">
              Start Now <i class="ri-arrow-right-line"></i>
            </a>
            <a href="#" class="btn-outline">
              <i class="ri-play-circle-line" style="margin-right: 8px;"></i> Case Studies
            </a>
          </div>
          
          <div class="trust-bar">
            <span class="trust-label">Trusted by industry leaders</span>
            <i class="ri-amazon-fill partner-logo"></i>
            <i class="ri-google-fill partner-logo"></i>
            <i class="ri-microsoft-fill partner-logo"></i>
            <i class="ri-netflix-fill partner-logo"></i>
            <i class="ri-spotify-fill partner-logo"></i>
          </div>
        </div>
        
        <div class="hero-visual">
          
          <div class="radar-pulse"></div>
          
          <div class="strategy-card">
            <div class="card-header">
              <div class="card-title">
                <i class="ri-pie-chart-2-fill" style="color:var(--corp-accent)"></i>
                Q3 Performance
              </div>
              <div class="status-badge">ON TRACK</div>
            </div>
            
            <div class="metric-big">$4.2B</div>
            <div class="metric-sub">
              <span>Total Revenue</span>
              <span class="gain"><i class="ri-arrow-up-circle-fill"></i> +12.4%</span>
            </div>
            
            <div class="simple-chart">
              <div class="bar" style="height: 30%"></div>
              <div class="bar" style="height: 45%"></div>
              <div class="bar" style="height: 35%"></div>
              <div class="bar" style="height: 60%"></div>
              <div class="bar" style="height: 50%"></div>
              <div class="bar active" style="height: 85%"></div>
              <div class="bar" style="height: 70%"></div>
            </div>
            
            <div class="card-footer">
              <div class="team-stack">
                <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100&auto=format&fit=crop" class="team-img" alt="User">
                <img src="https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=100&auto=format&fit=crop" class="team-img" alt="User">
                <img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=100&auto=format&fit=crop" class="team-img" alt="User">
                <div class="team-img" style="display:grid; place-items:center; background:#475569; color:white; font-size:0.7rem; font-weight:bold;">+4</div>
              </div>
              <a href="#" class="action-link">View Report <i class="ri-arrow-right-line"></i></a>
            </div>
          </div>
          
        </div>
      </section>
    `
  },


  // ============================================
  // HERO 6: ATELIER E-Commerce
  // ============================================
  {
    id: 'hero-atelier-ecommerce',
    label: 'ATELIER E-Commerce',
    category: 'Hero',
    content: `
      <section class="hero-section" id="hero-atelier-ecommerce">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        
        <style>
          #hero-atelier-ecommerce {
            --bg-color: #F7F5F2;
            --text-main: #1C1C1C;
            --text-muted: #6E6E6E;
            --accent: #D96C46;
            --shadow-soft: 0 25px 50px -12px rgba(0,0,0,0.15);
            --font-serif: 'Newsreader', serif;
            --font-sans: 'Inter', sans-serif;
            
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            align-items: center;
            position: relative;
            background-color: var(--bg-color);
            color: var(--text-main);
            font-family: var(--font-sans);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
          }
          
          #hero-atelier-ecommerce * { margin: 0; padding: 0; box-sizing: border-box; }
          
          #hero-atelier-ecommerce nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 5%;
            position: absolute;
            top: 0; left: 0; width: 100%;
            z-index: 100;
          }
          #hero-atelier-ecommerce .logo { font-family: var(--font-serif); font-size: 1.5rem; font-weight: 500; letter-spacing: -0.5px; }
          #hero-atelier-ecommerce .nav-actions { display: flex; gap: 20px; font-size: 1.2rem; cursor: pointer; }
          #hero-atelier-ecommerce .cart-icon { position: relative; }
          #hero-atelier-ecommerce .cart-count { position: absolute; top: -5px; right: -5px; background: var(--text-main); color: white; font-size: 0.6rem; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
          
          #hero-atelier-ecommerce .hero-narrative {
            padding: 0 8% 0 10%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            height: 100%;
            position: relative;
            z-index: 10;
          }
          
          #hero-atelier-ecommerce .new-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            color: var(--accent);
            font-weight: 600;
            margin-bottom: 24px;
          }
          #hero-atelier-ecommerce .new-pill::before { content: ''; width: 30px; height: 1px; background: var(--accent); }
          
          #hero-atelier-ecommerce h1 {
            font-family: var(--font-serif);
            font-size: 5rem;
            line-height: 0.95;
            font-weight: 400;
            margin-bottom: 24px;
            color: var(--text-main);
          }
          #hero-atelier-ecommerce h1 i { font-weight: 300; color: var(--text-muted); }
          
          #hero-atelier-ecommerce p.description {
            font-size: 1.05rem;
            line-height: 1.6;
            color: var(--text-muted);
            max-width: 420px;
            margin-bottom: 40px;
          }
          
          #hero-atelier-ecommerce .btn-shop {
            position: relative;
            display: inline-flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 32px;
            background: var(--text-main);
            color: white;
            text-decoration: none;
            overflow: hidden;
            width: fit-content;
            transition: 0.4s cubic-bezier(0.25, 1, 0.5, 1);
            gap: 30px;
          }
          #hero-atelier-ecommerce .btn-shop span { position: relative; z-index: 2; font-weight: 500; font-size: 0.95rem; }
          #hero-atelier-ecommerce .btn-shop::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: var(--accent);
            transform: translateX(-100%);
            transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
            z-index: 1;
          }
          #hero-atelier-ecommerce .btn-shop:hover::before { transform: translateX(0); }
          #hero-atelier-ecommerce .btn-shop:hover { padding-right: 40px; }
          
          #hero-atelier-ecommerce .hero-visual {
            position: relative;
            height: 100%;
            width: 100%;
            z-index: 20;
          }
          
          #hero-atelier-ecommerce .img-wrapper {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 1;
          }
          
          #hero-atelier-ecommerce .bg-img {
            width: 100%; height: 100%;
            object-fit: cover;
            object-position: center;
            background: url('https://images.unsplash.com/photo-1567538096630-e0c55bd6374c?q=80&w=2787&auto=format&fit=crop') center/cover;
            transition: transform 1.5s ease-out;
          }
          #hero-atelier-ecommerce:hover .bg-img { transform: scale(1.03); }
          
          #hero-atelier-ecommerce .product-card {
            position: absolute;
            bottom: 80px;
            left: -80px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 24px;
            width: 340px;
            box-shadow: var(--shadow-soft);
            animation: floatUp 1s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
            border: 1px solid rgba(255,255,255,0.8);
            z-index: 50;
          }
          
          #hero-atelier-ecommerce .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
          #hero-atelier-ecommerce .prod-title { font-family: var(--font-serif); font-size: 1.5rem; line-height: 1; margin-bottom: 4px; }
          #hero-atelier-ecommerce .prod-price { font-weight: 600; font-size: 1rem; }
          #hero-atelier-ecommerce .prod-sub { font-size: 0.8rem; color: var(--text-muted); }
          
          #hero-atelier-ecommerce .swatches { display: flex; gap: 10px; margin-top: 20px; margin-bottom: 24px; }
          #hero-atelier-ecommerce .swatch { width: 24px; height: 24px; border-radius: 50%; cursor: pointer; position: relative; border: 1px solid rgba(0,0,0,0.1); transition: transform 0.2s; }
          #hero-atelier-ecommerce .swatch:hover { transform: scale(1.1); }
          #hero-atelier-ecommerce .swatch.active::after { content: ''; position: absolute; top: -4px; left: -4px; right: -4px; bottom: -4px; border: 1px solid var(--text-main); border-radius: 50%; }
          
          #hero-atelier-ecommerce .add-btn {
            width: 100%; padding: 14px; background: transparent; border: 1px solid var(--text-main); color: var(--text-main); font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: all 0.2s; text-transform: uppercase; letter-spacing: 0.5px;
          }
          #hero-atelier-ecommerce .add-btn:hover { background: var(--text-main); color: white; }
          
          #hero-atelier-ecommerce .stats-pill {
            position: absolute;
            top: 40px; right: 40px;
            background: white;
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex; align-items: center; gap: 6px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            z-index: 50;
          }
          #hero-atelier-ecommerce .dot { width: 6px; height: 6px; background: #22c55e; border-radius: 50%; animation: pulse 2s infinite; }
          
          @keyframes floatUp { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }
          @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); } 70% { box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); } 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }
          
          @media (max-width: 1024px) {
            #hero-atelier-ecommerce { grid-template-columns: 1fr; height: auto; display: block; }
            #hero-atelier-ecommerce .hero-visual { height: 500px; width: 100%; }
            #hero-atelier-ecommerce .hero-narrative { padding: 4rem 5%; }
            #hero-atelier-ecommerce h1 { font-size: 3.5rem; }
            #hero-atelier-ecommerce .product-card { position: relative; bottom: auto; left: auto; margin-top: -60px; margin-left: auto; margin-right: auto; width: 90%; max-width: 350px; }
            #hero-atelier-ecommerce nav { position: relative; }
          }
        </style>
        
        <nav>
          <div class="nav-actions">
            <i class="ri-menu-line"></i>
          </div>
          <div class="logo">ATELIER.</div>
          <div class="nav-actions">
            <i class="ri-search-line"></i>
            <div class="cart-icon">
              <i class="ri-shopping-bag-line"></i>
              <div class="cart-count">2</div>
            </div>
          </div>
        </nav>
        
        <div class="hero-narrative">
          <div class="new-pill">Autumn Collection '25</div>
          <h1>
            Comfort,<br>
            <i>Curated.</i>
          </h1>
          <p class="description">
            The Lounge Chair 02 combines mid-century silhouette with modern ergonomic foam. 
            Sustainably sourced walnut and Italian leather.
          </p>
          
          <a href="#" class="btn-shop">
            <span>Explore Collection</span>
            <i class="ri-arrow-right-line" style="position:relative; z-index:2"></i>
          </a>
          
          <div style="margin-top: 60px; display: flex; gap: 40px; border-top: 1px solid #e5e5e5; padding-top: 20px;">
            <div>
              <div style="font-weight:600; font-size:1.2rem;">5yr</div>
              <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">Warranty</div>
            </div>
            <div>
              <div style="font-weight:600; font-size:1.2rem;">Free</div>
              <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">Shipping</div>
            </div>
          </div>
        </div>
        
        <div class="hero-visual">
          
          <div class="img-wrapper">
            <div class="bg-img"></div>
          </div>
          
          <div class="stats-pill">
            <div class="dot"></div> 24 people viewing this
          </div>
          
          <div class="product-card">
            <div class="card-header">
              <div>
                <div class="prod-title">Lounge Chair 02</div>
                <div class="prod-sub">Mid-Century Modern</div>
              </div>
              <div class="prod-price">$895</div>
            </div>
            
            <div class="swatches">
              <div class="swatch active" style="background: #ffffff;"></div>
              <div class="swatch" style="background: #3d3d3d;"></div>
              <div class="swatch" style="background: #8b5a2b;"></div>
            </div>
            
            <button class="add-btn">Add to Cart - $895</button>
          </div>
        </div>
      </section>
    `
  },


  // ============================================
  // HERO 7: Glow Theory Beauty (Interactive)
  // ============================================
  {
    id: 'hero-glow-theory',
    label: 'Glow Theory Beauty',
    category: 'Hero',
    content: `
      <section class="beauty-hero-glow" id="hero-glow-theory">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,600&family=Manrope:wght@300;400;600;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        
        <style>
          #hero-glow-theory {
            --bg-color: #F9F8F6;
            --text-dark: #27272a;
            --text-light: #71717a;
            --accent: #be185d;
            --accent-soft: #fce7f3;
            --white: #ffffff;
            --font-display: 'Fraunces', serif;
            --font-body: 'Manrope', sans-serif;
            --radius-lg: 24px;
            --shadow-float: 0 20px 50px -10px rgba(0,0,0,0.12);
            
            position: relative;
            background-color: var(--bg-color);
            color: var(--text-dark);
            font-family: var(--font-body);
            overflow-x: hidden;
            min-height: 100vh;
          }
          
          #hero-glow-theory * { margin: 0; padding: 0; box-sizing: border-box; }
          
          #hero-glow-theory .promo-bar {
            background: var(--text-dark);
            color: white;
            text-align: center;
            padding: 8px;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
          }
          
          #hero-glow-theory nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 5%;
            background: transparent;
          }
          
          #hero-glow-theory .nav-links { display: flex; gap: 30px; }
          #hero-glow-theory .nav-links a { text-decoration: none; color: var(--text-dark); font-weight: 600; font-size: 0.9rem; }
          
          #hero-glow-theory .brand { font-family: var(--font-display); font-size: 1.8rem; font-weight: 600; letter-spacing: -1px; }
          
          #hero-glow-theory .nav-icons { display: flex; gap: 20px; font-size: 1.2rem; cursor: pointer; }
          
          #hero-glow-theory .hero-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: calc(100vh - 100px);
            padding: 0 5% 4rem 5%;
            align-items: center;
            gap: 4rem;
          }
          
          #hero-glow-theory .hero-text {
            max-width: 550px;
            animation: glowFadeIn 1s ease-out;
          }
          
          #hero-glow-theory .science-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            padding: 6px 12px;
            border-radius: 50px;
            border: 1px solid #e5e5e5;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 24px;
          }
          #hero-glow-theory .beaker-icon { font-size: 1rem; }
          
          #hero-glow-theory h1 {
            font-family: var(--font-display);
            font-size: 4.5rem;
            line-height: 1.05;
            color: var(--text-dark);
            margin-bottom: 24px;
            font-weight: 400;
          }
          #hero-glow-theory h1 em { font-style: italic; color: var(--accent); }
          
          #hero-glow-theory p.hero-desc {
            font-size: 1.1rem;
            line-height: 1.7;
            color: var(--text-light);
            margin-bottom: 40px;
          }
          
          #hero-glow-theory .btn-beauty-primary {
            background: var(--text-dark);
            color: white;
            padding: 18px 40px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: transform 0.2s;
          }
          #hero-glow-theory .btn-beauty-primary:hover { transform: translateY(-2px); background: black; }
          
          #hero-glow-theory .trust-badges {
            margin-top: 4rem;
            display: flex;
            gap: 30px;
            border-top: 1px solid #e5e5e5;
            padding-top: 20px;
          }
          #hero-glow-theory .badge-item { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; font-weight: 600; color: var(--text-dark); }
          #hero-glow-theory .badge-icon { width: 24px; height: 24px; background: #e5e5e5; border-radius: 50%; display: grid; place-items: center; }
          
          #hero-glow-theory .hero-visual {
            position: relative;
            height: 750px;
            width: 100%;
            border-radius: var(--radius-lg);
            overflow: visible;
          }
          
          #hero-glow-theory .img-container {
            width: 100%;
            height: 100%;
            border-radius: var(--radius-lg);
            overflow: hidden;
            position: relative;
          }
          
          #hero-glow-theory .model-img {
            width: 100%; height: 100%;
            object-fit: cover;
            background: url('https://images.unsplash.com/photo-1616683693504-3ea7e9ad6fec?q=80&w=1974&auto=format&fit=crop') center top/cover;
            transition: transform 1s ease;
          }
          #hero-glow-theory .hero-visual:hover .model-img { transform: scale(1.02); }
          
          #hero-glow-theory .hotspot {
            position: absolute;
            width: 24px;
            height: 24px;
            background: rgba(255,255,255,0.3);
            border: 2px solid white;
            border-radius: 50%;
            cursor: pointer;
            z-index: 20;
            display: grid;
            place-items: center;
            transition: all 0.3s ease;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
          }
          #hero-glow-theory .hotspot::after {
            content: ''; width: 8px; height: 8px; background: white; border-radius: 50%;
          }
          #hero-glow-theory .hotspot:hover { transform: scale(1.2); background: white; }
          #hero-glow-theory .hotspot:hover::after { background: var(--accent); }
          
          #hero-glow-theory .hs-cheek { top: 42%; left: 45%; }
          #hero-glow-theory .hs-lips { top: 58%; left: 52%; }
          
          #hero-glow-theory .product-tooltip {
            position: absolute;
            background: white;
            padding: 16px;
            border-radius: 16px;
            width: 240px;
            box-shadow: var(--shadow-float);
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px) scale(0.95);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 30;
          }
          
          #hero-glow-theory .hs-cheek .product-tooltip { top: -140px; left: -108px; }
          #hero-glow-theory .hs-lips .product-tooltip { top: 40px; left: -108px; }
          
          #hero-glow-theory .hotspot:hover .product-tooltip {
            opacity: 1; visibility: visible; transform: translateY(0) scale(1);
          }
          
          #hero-glow-theory .tt-header { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; }
          #hero-glow-theory .tt-img { width: 40px; height: 40px; border-radius: 8px; background: #f0f0f0; object-fit: cover; }
          #hero-glow-theory .tt-info h4 { font-family: var(--font-display); font-size: 1rem; line-height: 1; margin-bottom: 4px; }
          #hero-glow-theory .tt-info span { font-size: 0.8rem; color: var(--text-light); }
          
          #hero-glow-theory .tt-price { font-weight: 700; font-size: 0.9rem; display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
          #hero-glow-theory .btn-add-mini {
            background: var(--text-dark); color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; cursor: pointer;
          }
          #hero-glow-theory .btn-add-mini:hover { background: var(--accent); }
          
          #hero-glow-theory .hotspot::before {
            content: ''; position: absolute; inset: -10px; border-radius: 50%;
            border: 1px solid white; opacity: 0;
            animation: glowPulseRing 2s infinite;
          }
          
          @keyframes glowPulseRing { 0% { transform: scale(0.5); opacity: 1; } 100% { transform: scale(1.5); opacity: 0; } }
          @keyframes glowFadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
          
          @media (max-width: 1024px) {
            #hero-glow-theory .hero-content { grid-template-columns: 1fr; gap: 2rem; padding-bottom: 2rem; }
            #hero-glow-theory .hero-visual { height: 500px; }
            #hero-glow-theory h1 { font-size: 3.5rem; }
            #hero-glow-theory .nav-links { display: none; }
          }
        </style>
        
        <div class="promo-bar">
          Free Shipping on all orders over $75 — Use code: GLOW25
        </div>
        
        <nav>
          <div class="brand">Glow Theory.</div>
          <div class="nav-links">
            <a href="#">Skincare</a>
            <a href="#">Suncare</a>
            <a href="#">Sets</a>
            <a href="#">Journal</a>
          </div>
          <div class="nav-icons">
            <i class="ri-search-line"></i>
            <i class="ri-shopping-bag-3-line"></i>
          </div>
        </nav>
        
        <div class="hero-content">
          
          <div class="hero-text">
            <div class="science-pill">
              <i class="ri-flask-line beaker-icon"></i> Clinically Proven
            </div>
            
            <h1>
              The Science of <br>
              <em>Glass Skin.</em>
            </h1>
            
            <p class="hero-desc">
              Achieve the dewy, reflective look without the grease. 
              Our 3-step ritual hydrates, plumps, and seals moisture 
              using our patented Hyaluron-B™ complex.
            </p>
            
            <a href="#" class="btn-beauty-primary">
              Shop The Ritual <i class="ri-arrow-right-line"></i>
            </a>
            
            <div class="trust-badges">
              <div class="badge-item">
                <div class="badge-icon"><i class="ri-plant-line"></i></div>
                <span>100% Vegan</span>
              </div>
              <div class="badge-item">
                <div class="badge-icon"><i class="ri-drop-line"></i></div>
                <span>Cruelty Free</span>
              </div>
              <div class="badge-item">
                <div class="badge-icon"><i class="ri-recycle-line"></i></div>
                <span>Recyclable</span>
              </div>
            </div>
          </div>
          
          <div class="hero-visual">
            <div class="img-container">
              <div class="model-img"></div>
            </div>
            
            <div class="hotspot hs-cheek">
              <div class="product-tooltip">
                <div class="tt-header">
                  <img src="https://images.unsplash.com/photo-1620916566398-39f1143ab7be?q=80&w=1887&auto=format&fit=crop" class="tt-img" alt="Serum">
                  <div class="tt-info">
                    <h4>Dew Drops Serum</h4>
                    <span>Hyaluronic + Niacinamide</span>
                  </div>
                </div>
                <div class="tt-price">
                  $34.00
                  <button class="btn-add-mini">Add</button>
                </div>
              </div>
            </div>
            
            <div class="hotspot hs-lips">
              <div class="product-tooltip">
                <div class="tt-header">
                  <img src="https://images.unsplash.com/photo-1629198688000-71f23e745b6e?q=80&w=1780&auto=format&fit=crop" class="tt-img" alt="Gloss">
                  <div class="tt-info">
                    <h4>Petal Lip Oil</h4>
                    <span>Non-sticky high shine</span>
                  </div>
                </div>
                <div class="tt-price">
                  $22.00
                  <button class="btn-add-mini">Add</button>
                </div>
              </div>
            </div>
            
          </div>
          
        </div>
      </section>
    `
  },


  // ============================================
  // HERO 8: Purillas Grocery Delivery
  // ============================================
  {
    id: 'hero-purillas-grocery',
    label: 'Purillas Grocery',
    category: 'Hero',
    content: `
      <section class="grocery-hero" id="hero-purillas-grocery">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        
        <style>
          #hero-purillas-grocery {
            --bg-body: #E6E6E3;
            --bg-card: #F4F4F2;
            --lime-pop: #D4F842;
            --black: #111111;
            --text-grey: #666666;
            --white: #ffffff;
            --radius-xl: 32px;
            --radius-md: 16px;
            --font-display: 'Space Grotesk', sans-serif;
            --font-ui: 'Inter', sans-serif;
            
            background-color: var(--bg-body);
            color: var(--black);
            font-family: var(--font-ui);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
          }
          
          #hero-purillas-grocery * { margin: 0; padding: 0; box-sizing: border-box; }
          
          #hero-purillas-grocery .app-container {
            background: var(--bg-card);
            width: 100%;
            max-width: 1300px;
            border-radius: 40px;
            padding: 60px;
            box-shadow: 0 40px 80px -20px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
          }
          
          #hero-purillas-grocery .brand-logo {
            font-family: var(--font-display);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 40px;
            display: inline-block;
          }
          
          #hero-purillas-grocery .hero-grid {
            display: grid;
            grid-template-columns: 1fr 1.3fr;
            gap: 60px;
            align-items: start;
          }
          
          #hero-purillas-grocery .hero-left { padding-top: 20px; }
          
          #hero-purillas-grocery .hero-headline {
            font-family: var(--font-display);
            font-size: 5rem;
            line-height: 0.95;
            letter-spacing: -2px;
            margin-bottom: 30px;
            color: var(--black);
          }
          
          #hero-purillas-grocery .app-buttons { display: flex; gap: 15px; margin-bottom: 50px; }
          
          #hero-purillas-grocery .store-btn {
            background: var(--lime-pop);
            padding: 12px 28px;
            border-radius: 50px;
            display: flex; align-items: center; gap: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            border: 1px solid transparent;
            transition: 0.2s;
          }
          #hero-purillas-grocery .store-btn:hover { background: #c2e630; transform: translateY(-2px); }
          #hero-purillas-grocery .store-btn.secondary { background: transparent; border: 1px solid #ccc; }
          #hero-purillas-grocery .store-btn.secondary:hover { background: #fff; border-color: #bbb; }
          
          #hero-purillas-grocery .product-widget {
            background: var(--white);
            padding: 24px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 400px;
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.05);
            margin-bottom: 50px;
            position: relative;
          }
          #hero-purillas-grocery .prod-info h4 { font-family: var(--font-display); font-size: 1.3rem; margin-bottom: 4px; }
          #hero-purillas-grocery .prod-info span { color: var(--text-grey); font-size: 0.9rem; }
          #hero-purillas-grocery .prod-price { font-weight: 700; margin-top: 5px; display: block; font-size: 1.1rem; }
          
          #hero-purillas-grocery .prod-img-box {
            width: 100px; height: 100px;
            background: #F9F9F9;
            border-radius: 16px;
            display: grid; place-items: center;
            position: relative;
            overflow: hidden;
          }
          #hero-purillas-grocery .prod-img-box img { width: 90%; height: auto; filter: drop-shadow(0 5px 10px rgba(0,0,0,0.1)); }
          #hero-purillas-grocery .tag-new { position: absolute; top: -8px; right: -8px; background: var(--white); border: 1px solid #eee; font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; text-transform: uppercase; }
          
          #hero-purillas-grocery .btn-add {
            margin-top: 15px;
            background: var(--lime-pop);
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.2s;
          }
          #hero-purillas-grocery .btn-add:hover { background: #c2e630; }
          
          #hero-purillas-grocery .features-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
            border-top: 1px solid #e0e0e0;
            padding-top: 30px;
          }
          #hero-purillas-grocery .feat h3 { font-family: var(--font-display); font-size: 1.4rem; margin-bottom: 8px; line-height: 1; font-weight: 700; }
          #hero-purillas-grocery .feat p { font-size: 0.9rem; color: var(--text-grey); line-height: 1.5; }
          
          #hero-purillas-grocery .social-proof { display: flex; align-items: center; gap: 20px; }
          #hero-purillas-grocery .user-avatars { display: flex; }
          #hero-purillas-grocery .avatar { width: 44px; height: 44px; border-radius: 50%; border: 3px solid var(--bg-card); margin-left: -15px; object-fit: cover; }
          #hero-purillas-grocery .avatar:first-child { margin-left: 0; }
          
          #hero-purillas-grocery .user-stat { font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
          #hero-purillas-grocery .stat-icon { width: 36px; height: 36px; background: var(--lime-pop); border-radius: 50%; display: grid; place-items: center; font-size: 1.1rem; }
          
          #hero-purillas-grocery .hero-right { position: relative; }
          
          #hero-purillas-grocery .cta-floating {
            position: absolute;
            top: 0; right: 0;
            text-align: right;
            z-index: 10;
          }
          #hero-purillas-grocery .cta-text { font-size: 0.85rem; color: #999; margin-bottom: 15px; max-width: 220px; margin-left: auto; line-height: 1.4; }
          #hero-purillas-grocery .cta-btn-black {
            background: var(--black);
            color: white;
            padding: 14px 28px;
            border-radius: 50px;
            display: inline-flex; align-items: center; gap: 8px;
            text-decoration: none; font-weight: 600;
            transition: 0.2s;
          }
          #hero-purillas-grocery .cta-btn-black:hover { transform: scale(1.05); }
          
          #hero-purillas-grocery .visual-wrapper {
            position: relative;
            height: 700px;
            border-radius: var(--radius-xl);
            z-index: 1;
          }
          
          #hero-purillas-grocery .lime-shape {
            position: absolute;
            top: -60px; right: 40px;
            width: 350px; height: 350px;
            background: var(--lime-pop);
            border-radius: 50%;
            z-index: -1;
            background-image: repeating-linear-gradient(90deg, transparent, transparent 20px, #fff 20px, #fff 40px);
            opacity: 1;
          }
          
          #hero-purillas-grocery .img-container {
            width: 100%;
            height: 100%;
            border-radius: var(--radius-xl);
            position: relative;
            overflow: hidden;
            background: linear-gradient(to bottom, 
              transparent 18%, rgba(244, 244, 242, 0.6) 18%, rgba(244, 244, 242, 0.6) 19%, 
              transparent 19%, transparent 38%, rgba(244, 244, 242, 0.6) 38%, rgba(244, 244, 242, 0.6) 39%,
              transparent 39%, transparent 58%, rgba(244, 244, 242, 0.6) 58%, rgba(244, 244, 242, 0.6) 59%,
              transparent 59%, transparent 78%, rgba(244, 244, 242, 0.6) 78%, rgba(244, 244, 242, 0.6) 79%, transparent 79%
            );
            pointer-events: none;
            z-index: 2;
            margin-top: 80px;
          }
          
          #hero-purillas-grocery .real-img {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            z-index: -1;
            background: url('https://images.unsplash.com/photo-1542838132-92c53300491e?q=80&w=2574&auto=format&fit=crop') center/cover;
            animation: purillasZoomIn 1s ease-out;
          }
          
          #hero-purillas-grocery .toggle-pill {
            position: absolute;
            top: 110px; left: 30px;
            background: var(--white);
            padding: 6px;
            border-radius: 50px;
            display: flex;
            z-index: 5;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
          }
          #hero-purillas-grocery .toggle-opt { padding: 10px 24px; border-radius: 40px; font-weight: 600; font-size: 0.9rem; cursor: pointer; color: var(--text-grey); }
          #hero-purillas-grocery .toggle-opt.active { background: #EFEFEF; color: var(--black); }
          
          @keyframes purillasZoomIn { from { transform: scale(1.1); } to { transform: scale(1); } }
          
          @media (max-width: 1024px) {
            #hero-purillas-grocery .app-container { padding: 30px; border-radius: 20px; }
            #hero-purillas-grocery .hero-grid { grid-template-columns: 1fr; gap: 40px; }
            #hero-purillas-grocery .hero-headline { font-size: 3.5rem; }
            #hero-purillas-grocery .visual-wrapper { height: 450px; }
            #hero-purillas-grocery .lime-shape { top: 0; right: 0; width: 200px; height: 200px; }
            #hero-purillas-grocery .img-container { margin-top: 60px; }
            #hero-purillas-grocery .toggle-pill { top: 80px; }
          }
        </style>
        
        <div class="app-container">
          
          <div class="brand-logo">Purillas®</div>
          
          <div class="hero-grid">
            
            <div class="hero-left">
              <h1 class="hero-headline">
                Groceries at Your Door <br>
                in Minutes
              </h1>
              
              <div class="app-buttons">
                <div class="store-btn">
                  <i class="ri-apple-fill" style="font-size:1.2rem"></i> App Store
                </div>
                <div class="store-btn secondary">
                  <i class="ri-google-play-fill" style="font-size:1.2rem"></i> Google Play
                </div>
              </div>
              
              <div class="product-widget">
                <div class="prod-info">
                  <h4>Organic Spinach</h4>
                  <span>250 g bunch</span>
                  <span class="prod-price">$2.50</span>
                  <button class="btn-add">Add to Cart</button>
                </div>
                <div class="prod-img-box">
                  <div class="tag-new">fresh</div>
                  <img src="https://images.unsplash.com/photo-1576045057995-568f588f82fb?q=80&w=1000&auto=format&fit=crop" alt="Spinach">
                </div>
              </div>
              
              <div class="features-row">
                <div class="feat">
                  <h3>1000s<br>of Products</h3>
                  <p>Browse our selection of retail-priced items and add whatever you need.</p>
                </div>
                <div class="feat">
                  <h3>Delivered<br>in Minutes</h3>
                  <p>By our legendary rider crew. Get everything you need delivered quickly.</p>
                </div>
              </div>
              
              <div class="social-proof">
                <div class="user-stat">
                  <div class="stat-icon"><i class="ri-user-smile-fill"></i></div>
                  2M+ Active Users
                </div>
                <div class="user-avatars">
                  <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=200&auto=format&fit=crop" class="avatar" alt="User 1">
                  <img src="https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?q=80&w=200&auto=format&fit=crop" class="avatar" alt="User 2">
                  <img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?q=80&w=200&auto=format&fit=crop" class="avatar" alt="User 3">
                </div>
              </div>
            </div>
            
            <div class="hero-right">
              
              <div class="cta-floating">
                <div class="cta-text">Download the app and get all your essentials delivered fast.</div>
                <a href="#" class="cta-btn-black">Get started <i class="ri-arrow-right-up-line"></i></a>
              </div>
              
              <div class="visual-wrapper">
                <div class="lime-shape"></div>
                
                <div class="toggle-pill">
                  <div class="toggle-opt active">Delivery</div>
                  <div class="toggle-opt">Pickup</div>
                </div>
                
                <div class="img-container">
                  <div class="real-img"></div>
                </div>
              </div>
            </div>
            
          </div>
        </div>
      </section>
    `
  },


  // ============================================
  // HERO 9: Aerix Smart Home Interface
  // ============================================
  {
    id: 'hero-aerix-smarthome',
    label: 'Aerix Smart Home',
    category: 'Hero',
    content: `
      <section class="smarthome-hero" id="hero-aerix-smarthome">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        
        <style>
          #hero-aerix-smarthome {
            --bg-canvas: #EBE5DA;
            --glass-panel: rgba(255, 255, 255, 0.45);
            --glass-card: rgba(255, 255, 255, 0.65);
            --glass-border: rgba(255, 255, 255, 0.4);
            --text-main: #4A453E;
            --text-sub: #8C867D;
            --accent-glow: #FFB86C;
            --accent-solid: #F29F4B;
            --danger: #FF6B6B;
            --success: #6BCB77;
            --shadow-diffuse: 0 40px 80px -20px rgba(74, 69, 62, 0.15);
            --shadow-card: 0 10px 20px -5px rgba(74, 69, 62, 0.05);
            --radius-card: 24px;
            --font-ui: 'Inter', sans-serif;
            
            background-color: #D6D1C9;
            color: var(--text-main);
            font-family: var(--font-ui);
            height: 100vh;
            width: 100%;
            overflow: hidden;
            margin: 0;
          }
          
          #hero-aerix-smarthome * { margin: 0; padding: 0; box-sizing: border-box; }
          
          #hero-aerix-smarthome .interface-wrapper {
            width: 100%;
            height: 100%;
            background: url('https://images.unsplash.com/photo-1586023492125-27b2c045efd7?q=80&w=2400&auto=format&fit=crop') center center/cover;
            position: relative;
            display: grid;
            grid-template-columns: 90px 1fr 380px;
            padding: 0;
          }
          
          #hero-aerix-smarthome .interface-wrapper::before {
            content: ''; position: absolute; inset: 0;
            background: rgba(235, 229, 218, 0.75);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            z-index: 0;
          }
          
          #hero-aerix-smarthome .sidebar,
          #hero-aerix-smarthome .main-view,
          #hero-aerix-smarthome .control-panel { position: relative; z-index: 2; }
          
          #hero-aerix-smarthome .sidebar {
            display: flex; flex-direction: column; align-items: center; padding-top: 40px;
            border-right: 1px solid rgba(255,255,255,0.3); height: 100%; background: rgba(255,255,255,0.1);
          }
          #hero-aerix-smarthome .app-logo { font-weight: 800; font-size: 1.2rem; margin-bottom: 60px; letter-spacing: -0.5px; color: var(--text-main); }
          #hero-aerix-smarthome .nav-icon { width: 50px; height: 50px; border-radius: 16px; display: grid; place-items: center; color: var(--text-sub); font-size: 1.4rem; margin-bottom: 12px; cursor: pointer; transition: 0.2s; }
          #hero-aerix-smarthome .nav-icon.active { background: var(--text-main); color: white; box-shadow: 0 10px 25px -5px rgba(74, 69, 62, 0.3); }
          #hero-aerix-smarthome .nav-icon:hover:not(.active) { background: rgba(255,255,255,0.5); color: var(--text-main); }
          #hero-aerix-smarthome .user-profile { margin-top: auto; margin-bottom: 40px; width: 48px; height: 48px; border-radius: 16px; background: url('https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=200&auto=format&fit=crop') center/cover; border: 2px solid white; box-shadow: var(--shadow-card); }
          
          #hero-aerix-smarthome .main-view {
            display: flex; flex-direction: column; justify-content: center; padding: 40px 40px 40px 60px;
            overflow: hidden;
          }
          
          #hero-aerix-smarthome .header-section { margin-bottom: 20px; z-index: 10; }
          #hero-aerix-smarthome .breadcrumbs { font-size: 0.85rem; color: var(--text-sub); margin-bottom: 10px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; }
          #hero-aerix-smarthome .headline { font-size: 4.5rem; font-weight: 600; letter-spacing: -2px; color: var(--text-main); line-height: 1; margin-bottom: 10px; }
          #hero-aerix-smarthome .sub-headline { font-size: 1.1rem; color: var(--text-sub); font-weight: 400; max-width: 400px; }
          
          #hero-aerix-smarthome .product-stage {
            flex: 1;
            display: grid;
            grid-template-columns: 280px 1fr;
            align-items: center;
            gap: 40px;
            height: 100%;
          }
          
          #hero-aerix-smarthome .floating-stats {
            display: flex; flex-direction: column; gap: 20px; width: 100%; z-index: 5;
          }
          
          #hero-aerix-smarthome .product-image-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
          }
          
          #hero-aerix-smarthome .product-img {
            max-height: 70vh;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            filter: drop-shadow(0 50px 80px rgba(0,0,0,0.2));
            z-index: 1;
            animation: aerixFadeIn 1s ease-out;
          }
          
          #hero-aerix-smarthome .glass-card { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(40px); -webkit-backdrop-filter: blur(40px); border: 1px solid var(--glass-border); padding: 24px; border-radius: var(--radius-card); box-shadow: var(--shadow-card); }
          #hero-aerix-smarthome .aqi-card { position: relative; overflow: hidden; }
          #hero-aerix-smarthome .label-sm { font-size: 0.75rem; color: var(--text-sub); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
          #hero-aerix-smarthome .wave-container { height: 60px; background: linear-gradient(180deg, rgba(242, 159, 75, 0.2) 0%, rgba(255, 255, 255, 0) 100%); margin: 15px -24px 0 -24px; position: relative; border-top: 2px solid var(--accent-solid); }
          #hero-aerix-smarthome .status-row { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
          #hero-aerix-smarthome .status-pill { font-size: 1.5rem; font-weight: 700; color: var(--text-main); }
          #hero-aerix-smarthome .icon-check { width: 24px; height: 24px; border-radius: 50%; background: var(--text-main); color: white; display: grid; place-items: center; font-size: 0.9rem; }
          #hero-aerix-smarthome .voc-chart { display: flex; align-items: flex-end; gap: 6px; height: 60px; margin-top: 20px; }
          #hero-aerix-smarthome .bar { flex: 1; background: rgba(74, 69, 62, 0.1); border-radius: 4px; transition: height 0.3s; }
          #hero-aerix-smarthome .bar.active { background: var(--accent-solid); box-shadow: 0 4px 12px rgba(242, 159, 75, 0.4); }
          #hero-aerix-smarthome .stat-footer { display: flex; justify-content: space-between; margin-top: 20px; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 10px; font-size: 0.8rem; }
          #hero-aerix-smarthome .stat-val { font-weight: 700; font-size: 1.1rem; display: block; color: var(--text-main); }
          
          #hero-aerix-smarthome .control-panel {
            background: rgba(255, 255, 255, 0.5); backdrop-filter: blur(50px); -webkit-backdrop-filter: blur(50px); padding: 40px; display: flex; flex-direction: column; gap: 20px; border-left: 1px solid var(--glass-border); height: 100%; justify-content: center;
          }
          #hero-aerix-smarthome .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
          #hero-aerix-smarthome .btn-add { background: var(--text-main); padding: 10px 20px; border-radius: 50px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: 0.2s; color: white; }
          #hero-aerix-smarthome .widget { background: white; border-radius: 24px; padding: 24px; box-shadow: 0 5px 15px rgba(0,0,0,0.02); }
          #hero-aerix-smarthome .w-header { display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 0.95rem; font-weight: 700; color: var(--text-main); }
          #hero-aerix-smarthome .big-percent { font-size: 2.5rem; font-weight: 700; letter-spacing: -1px; margin-bottom: 10px; color: var(--text-main); line-height: 1; }
          #hero-aerix-smarthome .progress-track { width: 100%; height: 8px; background: #eee; border-radius: 4px; overflow: hidden; margin-bottom: 10px; }
          #hero-aerix-smarthome .progress-fill { width: 82%; height: 100%; background: var(--accent-solid); }
          #hero-aerix-smarthome .w-footer { display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-sub); font-weight: 500; }
          #hero-aerix-smarthome .circle-progress-container { display: flex; align-items: center; justify-content: center; position: relative; height: 140px; margin-top: 15px; }
          #hero-aerix-smarthome .circle-chart { width: 120px; height: 120px; transform: rotate(-90deg); }
          #hero-aerix-smarthome .circle-bg { fill: none; stroke: #eee; stroke-width: 8; }
          #hero-aerix-smarthome .circle-val { fill: none; stroke: var(--accent-solid); stroke-width: 8; stroke-dasharray: 300; stroke-dashoffset: 75; stroke-linecap: round; }
          #hero-aerix-smarthome .circle-inner { position: absolute; text-align: center; }
          #hero-aerix-smarthome .c-val { font-size: 1.8rem; font-weight: 700; color: var(--text-main); line-height: 1; }
          #hero-aerix-smarthome .c-label { font-size: 0.75rem; color: var(--text-sub); display: block; font-weight: 600; }
          #hero-aerix-smarthome .clean-info { display: flex; justify-content: space-between; margin-top: 15px; font-size: 0.8rem; font-weight: 600; color: var(--text-sub); }
          #hero-aerix-smarthome .badge-gray { background: #f0f0f0; padding: 4px 10px; border-radius: 6px; color: var(--text-main); }
          #hero-aerix-smarthome .slider-widget { margin-top: auto; background: rgba(255,255,255,0.8); }
          #hero-aerix-smarthome .slider-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
          #hero-aerix-smarthome .dot-live { width: 8px; height: 8px; background: var(--success); border-radius: 50%; display: inline-block; margin-right: 6px; box-shadow: 0 0 8px var(--success); }
          #hero-aerix-smarthome .custom-slider { width: 100%; position: relative; height: 30px; display: flex; align-items: center; }
          #hero-aerix-smarthome .slider-line { width: 100%; height: 2px; background: #ddd; position: relative; }
          #hero-aerix-smarthome .slider-line::after { content: '|||||||||||||||||||||||'; position: absolute; top: -7px; left: 0; width: 100%; letter-spacing: 11px; font-size: 9px; color: #ccc; overflow: hidden; white-space: nowrap; font-weight: 700; }
          #hero-aerix-smarthome .slider-knob { width: 24px; height: 24px; background: var(--text-main); border-radius: 50%; position: absolute; left: 60%; top: 50%; transform: translate(-50%, -50%); border: 3px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.15); cursor: pointer; }
          
          @keyframes aerixFadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
          
          @media (max-width: 1200px) {
            #hero-aerix-smarthome .interface-wrapper { grid-template-columns: 70px 1fr 320px; }
            #hero-aerix-smarthome .headline { font-size: 3.5rem; }
            #hero-aerix-smarthome .product-stage { grid-template-columns: 240px 1fr; gap: 20px; }
          }
          
          @media (max-width: 1024px) {
            #hero-aerix-smarthome .interface-wrapper { grid-template-columns: 1fr; height: auto; min-height: 100vh; }
            #hero-aerix-smarthome .sidebar { display: none; }
            #hero-aerix-smarthome .control-panel { display: none; }
            #hero-aerix-smarthome .main-view { padding: 40px 20px; }
            #hero-aerix-smarthome .headline { font-size: 3rem; }
            #hero-aerix-smarthome .product-stage { grid-template-columns: 1fr; }
            #hero-aerix-smarthome .floating-stats { flex-direction: row; flex-wrap: wrap; }
            #hero-aerix-smarthome .glass-card { flex: 1; min-width: 200px; }
            #hero-aerix-smarthome .product-image-wrapper { order: -1; height: 400px; }
          }
        </style>
        
        <div class="interface-wrapper">
          
          <div class="sidebar">
            <div class="app-logo">Aerix</div>
            <div class="nav-icon active"><i class="ri-home-5-fill"></i></div>
            <div class="nav-icon"><i class="ri-bar-chart-grouped-fill"></i></div>
            <div class="nav-icon"><i class="ri-equalizer-line"></i></div>
            <div class="nav-icon"><i class="ri-settings-3-fill"></i></div>
            <div class="user-profile"></div>
          </div>
          
          <div class="main-view">
            <div class="header-section">
              <div class="breadcrumbs">Overview  /  Rooms  /  Master Bedroom</div>
              <h1 class="headline">Air5 Model</h1>
              <div class="sub-headline">Active Monitoring & Purification</div>
            </div>
            
            <div class="product-stage">
              
              <div class="floating-stats">
                <div class="glass-card aqi-card">
                  <div class="label-sm">Air Quality</div>
                  <div style="font-size: 0.8rem; color: #888;">Real-Time Index</div>
                  <div class="status-row">
                    <span class="status-pill">Good</span>
                    <div class="icon-check"><i class="ri-check-line"></i></div>
                  </div>
                  <div class="wave-container"></div>
                </div>
                
                <div class="glass-card">
                  <div class="label-sm">VOC Dynamics</div>
                  <div style="font-size: 0.8rem; color: #888; margin-bottom: 5px;">Comparison</div>
                  <div style="font-size: 0.9rem; font-weight: 700; color: var(--success);"><i class="ri-arrow-down-circle-fill"></i> 18% Better</div>
                  <div class="voc-chart">
                    <div class="bar" style="height: 40%"></div>
                    <div class="bar active" style="height: 75%"></div>
                    <div class="bar" style="height: 55%"></div>
                    <div class="bar" style="height: 35%"></div>
                    <div class="bar" style="height: 65%"></div>
                  </div>
                  <div class="stat-footer">
                    <div><span style="display:block; color:#999; margin-bottom:2px;">Yesterday</span><span class="stat-val">418</span></div>
                    <div><span style="display:block; color:#999; margin-bottom:2px; text-align:right;">Today</span><span class="stat-val" style="color:var(--accent-solid); text-align:right;">342</span></div>
                  </div>
                </div>
              </div>
              
              <div class="product-image-wrapper">
                <img src="https://i0.wp.com/sharpi.in/wp-content/uploads/2025/10/318471_7_Y2fgB6Zi3.webp?fit=1600%2C1600&ssl=1" class="product-img" alt="Dyson Air Purifier">
              </div>
            </div>
          </div>
          
          <div class="control-panel">
            <div class="panel-header">
              <span style="font-weight: 700; font-size: 1.1rem; color: var(--text-main);">Controls</span>
              <button class="btn-add">Add <i class="ri-add-line"></i></button>
            </div>
            
            <div class="widget">
              <div class="w-header">
                <span>Filter Health</span>
                <span class="badge-gray">HEPA 13</span>
              </div>
              <div class="big-percent">82%</div>
              <div class="progress-track"><div class="progress-fill"></div></div>
              <div class="w-footer">
                <span>Est. replacement</span>
                <span><strong>8 days</strong></span>
              </div>
            </div>
            
            <div class="widget">
              <div class="w-header"><span>Auto Cleaning</span></div>
              <div class="circle-progress-container">
                <svg class="circle-chart" viewBox="0 0 120 120">
                  <circle class="circle-bg" cx="60" cy="60" r="54"></circle>
                  <circle class="circle-val" cx="60" cy="60" r="54"></circle>
                </svg>
                <div class="circle-inner">
                  <div class="c-val">76%</div>
                  <span class="c-label">Cycle</span>
                </div>
              </div>
              <div class="clean-info">
                <span>Next cycle</span>
                <span class="badge-gray">in 3h</span>
              </div>
            </div>
            
            <div class="widget slider-widget">
              <div class="slider-header">
                <span style="font-weight:700">Mode</span>
                <span style="font-size:0.8rem; font-weight:600;"><span class="dot-live"></span> 241W</span>
              </div>
              <div class="custom-slider">
                <div class="slider-line"></div>
                <div class="slider-knob"></div>
              </div>
              <div style="display:flex; justify-content:space-between; font-size:0.7rem; font-weight:700; color:#aaa; margin-top:5px;">
                <span>QUIET</span>
                <span>BOOST</span>
              </div>
            </div>
          </div>
          
        </div>
      </section>
    `
  }
];
