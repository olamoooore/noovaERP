import { Preset } from './types'

// Banner presets with YouTube video backgrounds
// Note: YouTube videos may not autoplay in the GrapesJS editor canvas due to iframe nesting restrictions
// Videos will work properly when the page is published/exported
// 
// To change the video:
// 1. Use the Layers panel (left side) to select the "YouTube Video" iframe
// 2. In the Settings panel (gear icon on right), edit the "YouTube Video ID" field
// 3. Or select the entire "Video Banner" section to access video settings

export const bannerPresets: Preset[] = [
  // ============================================
  // 1. THE CINEMATIC (Dark Tunnel 4K)
  // ============================================
  {
    id: 'banner-cinematic',
    label: 'Cinematic',
    category: 'Banners',
    content: `<section data-gjs-name="Video Banner" class="vid-01">
      <style>
        .vid-01 { position: relative; height: 90vh; min-height: 600px; display: flex; align-items: center; justify-content: center; text-align: center; overflow: hidden; background-color: #000; }
        .vid-01 .video-background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: 0; }
        .vid-01 .video-foreground { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        .vid-01 .video-foreground iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none; }
        @media (min-aspect-ratio: 16/9) { .vid-01 .video-foreground { height: 300%; top: -100%; } }
        @media (max-aspect-ratio: 16/9) { .vid-01 .video-foreground { width: 300%; left: -100%; } }
        .vid-01 .v1-overlay { position: absolute; inset: 0; z-index: 1; background: linear-gradient(180deg, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0.8) 100%); pointer-events: none; }
        .vid-01 .v1-content { position: relative; z-index: 2; max-width: 800px; padding: 20px; }
        .vid-01 .v1-tag { display: inline-block; padding: 6px 16px; border: 1px solid rgba(255,255,255,0.3); border-radius: 50px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 24px; backdrop-filter: blur(5px); color: #fff; }
        .vid-01 .v1-title { font-family: 'Outfit', sans-serif; font-size: 4.5rem; font-weight: 800; line-height: 1.1; margin-bottom: 24px; letter-spacing: -1px; text-shadow: 0 10px 30px rgba(0,0,0,0.5); color: #fff; }
        .vid-01 .v1-desc { font-size: 1.2rem; color: rgba(255,255,255,0.9); margin-bottom: 40px; max-width: 600px; margin-left: auto; margin-right: auto; }
        .vid-01 .v1-btn-group { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
        .vid-01 .v1-btn { padding: 16px 32px; border-radius: 50px; font-weight: 600; text-decoration: none; transition: 0.3s; display: inline-block; }
        .vid-01 .v1-btn-primary { background: #fff; color: #000; }
        .vid-01 .v1-btn-primary:hover { background: #ddd; }
        .vid-01 .v1-btn-secondary { background: rgba(255,255,255,0.1); color: #fff; backdrop-filter: blur(10px); }
        .vid-01 .v1-btn-secondary:hover { background: rgba(255,255,255,0.2); }
        @media(max-width: 768px) { .vid-01 .v1-title { font-size: 2.5rem; } .vid-01 .v1-btn-group { flex-direction: column; align-items: center; } }
      </style>
      <div data-gjs-name="Video Background" class="video-background">
        <div data-gjs-name="Video Container" class="video-foreground">
          <iframe data-gjs-name="YouTube Video" data-video-id="9EPxUG8lWOM" src="https://www.youtube.com/embed/9EPxUG8lWOM?autoplay=1&mute=1&controls=0&loop=1&playlist=9EPxUG8lWOM&showinfo=0&rel=0&iv_load_policy=3&playsinline=1" frameborder="0" allowfullscreen></iframe>
        </div>
      </div>
      <div data-gjs-name="Overlay" class="v1-overlay"></div>
      <div data-gjs-name="Content" class="v1-content">
        <span data-gjs-name="Tag" class="v1-tag">New Collection 2026</span>
        <h1 data-gjs-name="Title" class="v1-title">Experience the future of digital design.</h1>
        <p data-gjs-name="Description" class="v1-desc">Immersive experiences crafted for the next generation of web users. Fast, fluid, and forward-thinking.</p>
        <div data-gjs-name="Button Group" class="v1-btn-group">
          <a data-gjs-name="Primary Button" href="#" class="v1-btn v1-btn-primary">Get Started</a>
          <a data-gjs-name="Secondary Button" href="#" class="v1-btn v1-btn-secondary">Watch Reel</a>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 2. THE GLASS HOUSE (Ink Drop)
  // ============================================
  {
    id: 'banner-glass-house',
    label: 'Glass House',
    category: 'Banners',
    content: `<section data-gjs-name="Video Banner" class="vid-02">
      <style>
        .vid-02 { position: relative; height: 80vh; min-height: 600px; display: flex; align-items: center; justify-content: flex-start; padding-left: 10%; overflow: hidden; background-color: #000; }
        .vid-02 .video-background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden; z-index: 0; }
        .vid-02 .video-foreground, .vid-02 .video-foreground iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        @media (min-aspect-ratio: 16/9) { .vid-02 .video-foreground { height: 300%; top: -100%; } }
        @media (max-aspect-ratio: 16/9) { .vid-02 .video-foreground { width: 300%; left: -100%; } }
        .vid-02 .v2-card { position: relative; z-index: 2; width: 100%; max-width: 500px; background: rgba(255, 255, 255, 0.1); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.2); padding: 50px; border-radius: 24px; box-shadow: 0 40px 80px -20px rgba(0,0,0,0.5); }
        .vid-02 .v2-title { font-family: 'Outfit', sans-serif; font-size: 3rem; margin-bottom: 20px; line-height: 1.1; color: #fff; }
        .vid-02 .v2-desc { color: rgba(255,255,255,0.8); margin-bottom: 30px; line-height: 1.6; }
        .vid-02 .v2-input-group { display: flex; background: rgba(255,255,255,0.9); padding: 6px; border-radius: 50px; }
        .vid-02 .v2-input { flex: 1; border: none; background: transparent; padding: 0 20px; outline: none; color: #111; font-size: 1rem; }
        .vid-02 .v2-submit { background: #000; color: #fff; border: none; padding: 12px 24px; border-radius: 40px; cursor: pointer; font-weight: 600; }
        @media(max-width: 768px) { .vid-02 { justify-content: center; padding: 20px; } .vid-02 .v2-card { padding: 30px; } .vid-02 .v2-title { font-size: 2rem; } }
      </style>
      <div data-gjs-name="Video Background" class="video-background">
        <div class="video-foreground">
          <iframe data-gjs-name="YouTube Video" data-video-id="zmTJsSnR770" src="https://www.youtube.com/embed/zmTJsSnR770?autoplay=1&mute=1&controls=0&loop=1&playlist=zmTJsSnR770&showinfo=0&rel=0&iv_load_policy=3&playsinline=1" frameborder="0" allowfullscreen></iframe>
        </div>
      </div>
      <div data-gjs-name="Glass Card" class="v2-card">
        <h2 data-gjs-name="Title" class="v2-title">Unlock your creative potential.</h2>
        <p data-gjs-name="Description" class="v2-desc">Join 50,000+ designers using our tools to build better products faster. Sign up for early access.</p>
        <form data-gjs-name="Form" class="v2-input-group">
          <input data-gjs-name="Email Input" type="email" class="v2-input" placeholder="Enter your email">
          <button data-gjs-name="Submit Button" class="v2-submit">Join</button>
        </form>
      </div>
    </section>`
  },

  // ============================================
  // 3. THE SPLIT FOCUS (Tech Loop)
  // ============================================
  {
    id: 'banner-split-focus',
    label: 'Split Focus',
    category: 'Banners',
    content: `<section data-gjs-name="Video Banner" class="vid-03">
      <style>
        .vid-03 { display: grid; grid-template-columns: 1fr 1fr; min-height: 600px; background-color: #fff; color: #111; }
        .vid-03 .v3-content { display: flex; flex-direction: column; justify-content: center; padding: 60px 80px; z-index: 2; background: #fff; }
        .vid-03 .v3-video-wrap { position: relative; width: 100%; height: 100%; min-height: 500px; overflow: hidden; background: #000; }
        .vid-03 .video-background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden; z-index: 0; }
        .vid-03 .video-foreground, .vid-03 .video-foreground iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        @media (min-aspect-ratio: 16/9) { .vid-03 .video-foreground { height: 300%; top: -100%; } }
        @media (max-aspect-ratio: 16/9) { .vid-03 .video-foreground { width: 300%; left: -100%; } }
        .vid-03 .v3-label { color: #2563eb; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 0.9rem; margin-bottom: 20px; }
        .vid-03 .v3-title { font-size: 3.5rem; font-weight: 800; letter-spacing: -1.5px; line-height: 1.1; margin-bottom: 30px; color: #111; }
        .vid-03 .v3-desc { font-size: 1.1rem; color: #475569; margin-bottom: 40px; line-height: 1.6; max-width: 500px; }
        .vid-03 .v3-btn { display: inline-flex; align-items: center; gap: 10px; background: #111; color: #fff; padding: 16px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; width: fit-content; }
        .vid-03 .v3-btn:hover { background: #333; }
        @media(max-width: 900px) { .vid-03 { grid-template-columns: 1fr; } .vid-03 .v3-video-wrap { height: 400px; order: -1; } .vid-03 .v3-content { padding: 40px 20px; } .vid-03 .v3-title { font-size: 2.5rem; } }
      </style>
      <div data-gjs-name="Content" class="v3-content">
        <span data-gjs-name="Label" class="v3-label">Modern Infrastructure</span>
        <h2 data-gjs-name="Title" class="v3-title">Scale without limits.</h2>
        <p data-gjs-name="Description" class="v3-desc">Deploy your applications to a global edge network in seconds. No configuration required.</p>
        <a data-gjs-name="Button" href="#" class="v3-btn">Start Deploying →</a>
      </div>
      <div data-gjs-name="Video Wrapper" class="v3-video-wrap">
        <div data-gjs-name="Video Background" class="video-background">
          <div class="video-foreground">
            <iframe data-gjs-name="YouTube Video" data-video-id="Hgg7M3kSqyE" src="https://www.youtube.com/embed/Hgg7M3kSqyE?autoplay=1&mute=1&controls=0&loop=1&playlist=Hgg7M3kSqyE&showinfo=0&rel=0&iv_load_policy=3&playsinline=1" frameborder="0" allowfullscreen></iframe>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 4. THE BRAND TINT (City Aerial)
  // ============================================
  {
    id: 'banner-brand-tint',
    label: 'Brand Tint',
    category: 'Banners',
    content: `<section data-gjs-name="Video Banner" class="vid-04">
      <style>
        .vid-04 { position: relative; height: 80vh; min-height: 500px; display: flex; align-items: center; justify-content: center; overflow: hidden; background-color: #000; }
        .vid-04 .video-background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden; z-index: 0; }
        .vid-04 .video-foreground, .vid-04 .video-foreground iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        @media (min-aspect-ratio: 16/9) { .vid-04 .video-foreground { height: 300%; top: -100%; } }
        @media (max-aspect-ratio: 16/9) { .vid-04 .video-foreground { width: 300%; left: -100%; } }
        .vid-04 .v4-overlay { position: absolute; inset: 0; z-index: 1; background-color: #4f46e5; mix-blend-mode: multiply; opacity: 0.9; }
        .vid-04 .v4-leak { position: absolute; inset: 0; z-index: 2; background: radial-gradient(circle at 10% 20%, rgba(255,255,255,0.2) 0%, transparent 50%); }
        .vid-04 .v4-content { position: relative; z-index: 3; text-align: center; max-width: 900px; padding: 20px; }
        .vid-04 .v4-title { font-size: 4rem; font-weight: 800; letter-spacing: -2px; margin-bottom: 20px; color: #fff; }
        .vid-04 .v4-play { width: 80px; height: 80px; border-radius: 50%; border: 2px solid #fff; display: grid; place-items: center; margin: 0 auto 40px; cursor: pointer; transition: 0.3s; color: #fff; background: transparent; }
        .vid-04 .v4-play:hover { background: #fff; color: #4f46e5; transform: scale(1.1); }
        .vid-04 .v4-play svg { width: 30px; height: 30px; fill: currentColor; }
        @media(max-width: 768px) { .vid-04 .v4-title { font-size: 2.5rem; } }
      </style>
      <div data-gjs-name="Video Background" class="video-background">
        <div class="video-foreground">
          <iframe data-gjs-name="YouTube Video" data-video-id="GXM838nBLuw" src="https://www.youtube.com/embed/GXM838nBLuw?autoplay=1&mute=1&controls=0&loop=1&playlist=GXM838nBLuw&showinfo=0&rel=0&iv_load_policy=3&playsinline=1" frameborder="0" allowfullscreen></iframe>
        </div>
      </div>
      <div data-gjs-name="Color Overlay" class="v4-overlay"></div>
      <div data-gjs-name="Light Leak" class="v4-leak"></div>
      <div data-gjs-name="Content" class="v4-content">
        <button data-gjs-name="Play Button" class="v4-play"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg></button>
        <h2 data-gjs-name="Title" class="v4-title">We move the world forward.</h2>
      </div>
    </section>`
  },

  // ============================================
  // 5. THE FASHION EDITORIAL (Runway)
  // ============================================
  {
    id: 'banner-fashion-editorial',
    label: 'Fashion Editorial',
    category: 'Banners',
    content: `<section data-gjs-name="Video Banner" class="vid-05">
      <style>
        .vid-05 { position: relative; height: 100vh; overflow: hidden; background-color: #000; }
        .vid-05 .video-background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden; z-index: 0; }
        .vid-05 .video-foreground, .vid-05 .video-foreground iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        @media (min-aspect-ratio: 16/9) { .vid-05 .video-foreground { height: 300%; top: -100%; } }
        @media (max-aspect-ratio: 16/9) { .vid-05 .video-foreground { width: 300%; left: -100%; } }
        .vid-05 .v5-overlay { position: absolute; inset: 0; z-index: 1; background: linear-gradient(to top, rgba(0,0,0,0.7) 0%, transparent 40%); }
        .vid-05 .v5-content { position: absolute; bottom: 60px; left: 60px; z-index: 2; }
        .vid-05 .v5-season { font-family: 'Inter', sans-serif; font-size: 0.9rem; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 10px; color: #fff; }
        .vid-05 .v5-title { font-family: 'Playfair Display', serif; font-size: 5rem; line-height: 1; font-style: italic; margin-bottom: 20px; color: #fff; }
        .vid-05 .v5-link { color: #fff; text-decoration: none; font-size: 1.1rem; border-bottom: 1px solid #fff; padding-bottom: 5px; transition: 0.3s; }
        .vid-05 .v5-link:hover { padding-bottom: 10px; }
        @media(max-width: 768px) { .vid-05 .v5-content { left: 20px; bottom: 40px; } .vid-05 .v5-title { font-size: 3rem; } }
      </style>
      <div data-gjs-name="Video Background" class="video-background">
        <div class="video-foreground">
          <iframe data-gjs-name="YouTube Video" data-video-id="M7lc1UVf-VE" src="https://www.youtube.com/embed/M7lc1UVf-VE?autoplay=1&mute=1&controls=0&loop=1&playlist=M7lc1UVf-VE&showinfo=0&rel=0&iv_load_policy=3&playsinline=1" frameborder="0" allowfullscreen></iframe>
        </div>
      </div>
      <div data-gjs-name="Overlay" class="v5-overlay"></div>
      <div data-gjs-name="Content" class="v5-content">
        <div data-gjs-name="Season" class="v5-season">Summer 2026</div>
        <h2 data-gjs-name="Title" class="v5-title">Ethereal<br>Motion.</h2>
        <a data-gjs-name="Link" href="#" class="v5-link">View Collection</a>
      </div>
    </section>`
  },

  // ============================================
  // 6. THE APP CONTEXT (Grid Abstract)
  // ============================================
  {
    id: 'banner-app-context',
    label: 'App Context',
    category: 'Banners',
    content: `<section data-gjs-name="Video Banner" class="vid-06">
      <style>
        .vid-06 { position: relative; padding: 120px 20px 0; overflow: hidden; display: flex; flex-direction: column; align-items: center; text-align: center; background-color: #000; min-height: 700px; }
        .vid-06 .video-background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden; z-index: 0; opacity: 0.6; }
        .vid-06 .video-foreground, .vid-06 .video-foreground iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        @media (min-aspect-ratio: 16/9) { .vid-06 .video-foreground { height: 300%; top: -100%; } }
        @media (max-aspect-ratio: 16/9) { .vid-06 .video-foreground { width: 300%; left: -100%; } }
        .vid-06 .v6-content { position: relative; z-index: 2; max-width: 700px; margin-bottom: 60px; }
        .vid-06 .v6-badge { color: #facc15; border: 1px solid #facc15; padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 24px; display: inline-block; }
        .vid-06 .v6-title { font-size: 3.5rem; font-weight: 800; margin-bottom: 20px; letter-spacing: -1px; color: #fff; }
        .vid-06 .v6-desc { font-size: 1.1rem; color: #a1a1aa; }
        .vid-06 .v6-mockup { position: relative; z-index: 2; width: 100%; max-width: 1000px; background: #111; border-radius: 24px 24px 0 0; border: 1px solid #333; border-bottom: none; box-shadow: 0 -20px 60px rgba(255,255,255,0.05); overflow: hidden; aspect-ratio: 16/9; }
        .vid-06 .v6-mockup img { width: 100%; height: 100%; object-fit: cover; display: block; opacity: 0.8; }
        @media(max-width: 768px) { .vid-06 .v6-title { font-size: 2.5rem; } .vid-06 { padding-top: 80px; } }
      </style>
      <div data-gjs-name="Video Background" class="video-background">
        <div class="video-foreground">
          <iframe data-gjs-name="YouTube Video" data-video-id="aQfARKcyu0w" src="https://www.youtube.com/embed/aQfARKcyu0w?autoplay=1&mute=1&controls=0&loop=1&playlist=aQfARKcyu0w&showinfo=0&rel=0&iv_load_policy=3&playsinline=1" frameborder="0" allowfullscreen></iframe>
        </div>
      </div>
      <div data-gjs-name="Content" class="v6-content">
        <span data-gjs-name="Badge" class="v6-badge">Version 2.0</span>
        <h2 data-gjs-name="Title" class="v6-title">The dashboard you've been waiting for.</h2>
        <p data-gjs-name="Description" class="v6-desc">Powerful analytics wrapped in a stunning interface.</p>
      </div>
      <div data-gjs-name="Mockup" class="v6-mockup">
        <img data-gjs-name="Dashboard Image" src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?q=80&w=1600&auto=format&fit=crop" alt="Dashboard UI">
      </div>
    </section>`
  },

  // ============================================
  // IMAGE-BASED CREATIVE BANNERS
  // ============================================

  // ============================================
  // 7. THE EDITORIAL OFFSET (Broken Grid)
  // ============================================
  {
    id: 'banner-editorial-offset',
    label: 'Editorial Offset',
    category: 'Banners',
    content: `<section data-gjs-name="Creative Banner" class="cb-01">
      <style>
        .cb-01 { display: grid; grid-template-columns: 1fr 1fr; align-items: center; padding: 100px 5%; background-color: #0f172a; min-height: 700px; position: relative; overflow: hidden; font-family: 'Inter', sans-serif; color: #fff; }
        .cb-01 .c1-image-wrap { position: relative; height: 600px; width: 100%; }
        .cb-01 .c1-img { width: 100%; height: 100%; object-fit: cover; border-radius: 24px; box-shadow: -20px 20px 60px rgba(0,0,0,0.3); }
        .cb-01 .c1-content { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); padding: 60px; border-radius: 24px; margin-left: -150px; position: relative; z-index: 2; box-shadow: 0 30px 60px -20px rgba(0,0,0,0.4); }
        .cb-01 .c1-title { font-family: 'Outfit', sans-serif; font-size: 4rem; line-height: 1; margin-bottom: 24px; }
        .cb-01 .c1-desc { font-size: 1.1rem; color: #cbd5e1; margin-bottom: 40px; max-width: 450px; line-height: 1.6; }
        .cb-01 .cb-btn { display: inline-block; padding: 16px 36px; background: #fff; color: #000; border-radius: 50px; font-weight: 700; text-decoration: none; transition: 0.3s; }
        .cb-01 .cb-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        @media(max-width: 900px) { .cb-01 { grid-template-columns: 1fr; padding: 60px 20px; } .cb-01 .c1-image-wrap { height: 400px; } .cb-01 .c1-content { margin-left: 0; margin-top: -100px; padding: 40px; } .cb-01 .c1-title { font-size: 2.5rem; } }
      </style>
      <div data-gjs-name="Image Wrapper" class="c1-image-wrap">
        <img data-gjs-name="Image" src="https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?q=80&w=1800&auto=format&fit=crop" class="c1-img" alt="Architecture">
      </div>
      <div data-gjs-name="Content" class="c1-content">
        <h1 data-gjs-name="Title" class="c1-title">Redefining Modern Living Spaces.</h1>
        <p data-gjs-name="Description" class="c1-desc">Award-winning architecture designed around the way you live today. Sustainable, functional, and undeniably beautiful.</p>
        <a data-gjs-name="Button" href="#" class="cb-btn">View Projects</a>
      </div>
    </section>`
  },

  // ============================================
  // 8. THE TYPOGRAPHY MASK (Clip Text)
  // ============================================
  {
    id: 'banner-typography-mask',
    label: 'Typography Mask',
    category: 'Banners',
    content: `<section data-gjs-name="Creative Banner" class="cb-02">
      <style>
        .cb-02 { display: flex; align-items: center; justify-content: center; background-color: #000; min-height: 700px; position: relative; overflow: hidden; font-family: 'Inter', sans-serif; color: #fff; }
        .cb-02 .c2-container { text-align: center; position: relative; z-index: 2; }
        .cb-02 .c2-masked-title { font-family: 'Outfit', sans-serif; font-size: 12vw; font-weight: 900; line-height: 0.85; text-transform: uppercase; background-image: url('https://images.unsplash.com/photo-1542202229-7d93c33f5d07?q=80&w=1800&auto=format&fit=crop'); background-size: cover; background-position: center; -webkit-background-clip: text; background-clip: text; color: transparent; -webkit-text-stroke: 1px rgba(255,255,255,0.1); margin-bottom: 40px; }
        .cb-02 .c2-subtitle { font-size: 1.5rem; letter-spacing: 2px; margin-bottom: 20px; color: #facc15; font-weight: 700; }
        .cb-02 .cb-btn { display: inline-block; padding: 16px 36px; background: #facc15; color: #000; border-radius: 50px; font-weight: 700; text-decoration: none; transition: 0.3s; }
        .cb-02 .cb-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
      </style>
      <div data-gjs-name="Container" class="c2-container">
        <div data-gjs-name="Subtitle" class="c2-subtitle">The World Awaits</div>
        <h1 data-gjs-name="Masked Title" class="c2-masked-title">Explore<br>Further</h1>
        <a data-gjs-name="Button" href="#" class="cb-btn">Start Journey</a>
      </div>
    </section>`
  },

  // ============================================
  // 9. THE ORGANIC FLOAT (Blob Shape)
  // ============================================
  {
    id: 'banner-organic-float',
    label: 'Organic Float',
    category: 'Banners',
    content: `<section data-gjs-name="Creative Banner" class="cb-03">
      <style>
        .cb-03 { background-color: #f8fafc; color: #0f172a; display: flex; align-items: center; justify-content: center; overflow: hidden; min-height: 700px; position: relative; font-family: 'Inter', sans-serif; }
        .cb-03 .c3-container { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; max-width: 1200px; padding: 20px; align-items: center; }
        .cb-03 .c3-blob-wrap { position: relative; width: 100%; height: 600px; border-radius: 40% 60% 70% 30% / 40% 50% 60% 50%; overflow: hidden; box-shadow: 20px 20px 60px rgba(0,0,0,0.1); animation: blobMorph03 15s infinite alternate ease-in-out; }
        .cb-03 .c3-img { width: 100%; height: 100%; object-fit: cover; transform: scale(1.1); }
        .cb-03 .c3-content { padding: 20px; }
        .cb-03 .c3-title { font-family: 'Playfair Display', serif; font-size: 4.5rem; font-style: italic; line-height: 1.1; margin-bottom: 24px; }
        .cb-03 .c3-desc { font-size: 1.2rem; color: #64748b; margin-bottom: 40px; max-width: 450px; }
        .cb-03 .cb-btn { display: inline-block; padding: 16px 36px; background: #0f172a; color: #fff; border-radius: 50px; font-weight: 700; text-decoration: none; transition: 0.3s; }
        .cb-03 .cb-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        @keyframes blobMorph03 { 0% { border-radius: 40% 60% 70% 30% / 40% 50% 60% 50%; } 100% { border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%; } }
        @media(max-width: 900px) { .cb-03 .c3-container { grid-template-columns: 1fr; } .cb-03 .c3-blob-wrap { height: 400px; order: -1; } .cb-03 .c3-title { font-size: 3rem; } }
      </style>
      <div data-gjs-name="Container" class="c3-container">
        <div data-gjs-name="Content" class="c3-content">
          <h1 data-gjs-name="Title" class="c3-title">Natural Beauty, Bottled via Science.</h1>
          <p data-gjs-name="Description" class="c3-desc">Our new skincare line combines organic botanicals with cutting-edge dermatological research for results you can see.</p>
          <a data-gjs-name="Button" href="#" class="cb-btn">Shop the Collection</a>
        </div>
        <div data-gjs-name="Blob Wrapper" class="c3-blob-wrap">
          <img data-gjs-name="Image" src="https://images.unsplash.com/photo-1556228578-8c89e6adf883?q=80&w=1800&auto=format&fit=crop" class="c3-img" alt="Skincare">
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 10. THE MONOCHROME REVEAL (Interactive Hover)
  // ============================================
  {
    id: 'banner-monochrome-reveal',
    label: 'Monochrome Reveal',
    category: 'Banners',
    content: `<section data-gjs-name="Creative Banner" class="cb-04">
      <style>
        .cb-04 { display: flex; align-items: center; justify-content: center; background-image: url('https://images.unsplash.com/photo-1536924940846-227afb31e2a5?q=80&w=1800&auto=format&fit=crop'); background-size: cover; background-position: center; position: relative; min-height: 700px; overflow: hidden; font-family: 'Inter', sans-serif; color: #fff; }
        .cb-04 .c4-overlay { position: absolute; inset: 0; background-color: #0f172a; mix-blend-mode: saturation; transition: 0.6s ease-in-out; }
        .cb-04 .c4-dimmer { position: absolute; inset: 0; background: rgba(0,0,0,0.6); transition: 0.6s; }
        .cb-04 .c4-content { position: relative; z-index: 2; text-align: center; max-width: 700px; border: 1px solid rgba(255,255,255,0.3); padding: 60px; backdrop-filter: blur(5px); transition: 0.3s; cursor: default; }
        .cb-04 .c4-title { font-family: 'Outfit', sans-serif; font-size: 4rem; text-transform: uppercase; letter-spacing: 4px; margin-bottom: 20px; }
        .cb-04 .c4-hint { font-size: 0.9rem; letter-spacing: 2px; text-transform: uppercase; color: #facc15; }
        .cb-04 .c4-desc { margin-top: 15px; color: rgba(255,255,255,0.8); }
        .cb-04:hover .c4-overlay { opacity: 0; }
        .cb-04:hover .c4-dimmer { background: rgba(0,0,0,0.3); }
        .cb-04:hover .c4-content { border-color: #fff; background: rgba(0,0,0,0.2); }
        @media(max-width: 768px) { .cb-04 .c4-title { font-size: 2.5rem; letter-spacing: 2px; } .cb-04 .c4-content { padding: 40px 20px; margin: 20px; } }
      </style>
      <div data-gjs-name="B&W Overlay" class="c4-overlay"></div>
      <div data-gjs-name="Dimmer" class="c4-dimmer"></div>
      <div data-gjs-name="Content" class="c4-content">
        <span data-gjs-name="Hint" class="c4-hint">Hover to bring to life</span>
        <h1 data-gjs-name="Title" class="c4-title">Ignite Your Senses</h1>
        <p data-gjs-name="Description" class="c4-desc">Experience sound like never before with our flagship series.</p>
      </div>
    </section>`
  },

  // ============================================
  // 11. THE 3D PERSPECTIVE (Floating Card)
  // ============================================
  {
    id: 'banner-3d-perspective',
    label: '3D Perspective',
    category: 'Banners',
    content: `<section data-gjs-name="Creative Banner" class="cb-05">
      <style>
        .cb-05 { background: radial-gradient(circle at center, #1e293b 0%, #0f172a 100%); display: flex; align-items: center; justify-content: center; perspective: 1500px; overflow: hidden; min-height: 700px; position: relative; font-family: 'Inter', sans-serif; color: #fff; }
        .cb-05 .c5-dec { position: absolute; border-radius: 50%; border: 1px solid rgba(255,255,255,0.05); }
        .cb-05 .c5-d1 { width: 800px; height: 800px; top: -20%; left: -10%; }
        .cb-05 .c5-d2 { width: 600px; height: 600px; bottom: -10%; right: -10%; }
        .cb-05 .c5-card-wrap { width: 80%; max-width: 1000px; height: 500px; position: relative; z-index: 2; transform-style: preserve-3d; transform: rotateX(5deg) rotateY(-10deg); transition: transform 0.5s ease-out; }
        .cb-05:hover .c5-card-wrap { transform: rotateX(0deg) rotateY(0deg) scale(1.02); }
        .cb-05 .c5-card-bg { position: absolute; inset: 0; background-image: url('https://images.unsplash.com/photo-1559080463-69736d250656?q=80&w=1800&auto=format&fit=crop'); background-size: cover; background-position: center; border-radius: 30px; box-shadow: -30px 30px 60px rgba(0,0,0,0.5), inset 0 0 0 2px rgba(255,255,255,0.1); }
        .cb-05 .c5-card-bg::after { content: ''; position: absolute; inset: 0; border-radius: 30px; background: linear-gradient(90deg, rgba(0,0,0,0.8) 0%, transparent 60%); }
        .cb-05 .c5-content { position: absolute; top: 50%; left: 60px; transform: translateY(-50%) translateZ(60px); max-width: 500px; }
        .cb-05 .c5-title { font-family: 'Outfit', sans-serif; font-size: 3.5rem; line-height: 1.1; margin-bottom: 24px; text-shadow: 0 5px 10px rgba(0,0,0,0.5); }
        .cb-05 .c5-desc { color: #ccc; margin-bottom: 30px; font-size: 1.1rem; }
        .cb-05 .cb-btn { display: inline-block; padding: 16px 36px; background: #3b82f6; color: #fff; border-radius: 50px; font-weight: 700; text-decoration: none; transition: 0.3s; }
        .cb-05 .cb-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        @media(max-width: 768px) { .cb-05 .c5-card-wrap { height: 600px; transform: none; } .cb-05:hover .c5-card-wrap { transform: none; } .cb-05 .c5-card-bg::after { background: linear-gradient(0deg, rgba(0,0,0,0.8) 0%, transparent 60%); } .cb-05 .c5-content { left: 30px; bottom: 30px; top: auto; transform: translateZ(60px); } .cb-05 .c5-title { font-size: 2.5rem; } }
      </style>
      <div data-gjs-name="Decoration 1" class="c5-dec c5-d1"></div>
      <div data-gjs-name="Decoration 2" class="c5-dec c5-d2"></div>
      <div data-gjs-name="Card Wrapper" class="c5-card-wrap">
        <div data-gjs-name="Card Background" class="c5-card-bg"></div>
        <div data-gjs-name="Content" class="c5-content">
          <h1 data-gjs-name="Title" class="c5-title">Virtual Reality<br>Real Results.</h1>
          <p data-gjs-name="Description" class="c5-desc">Training solutions for the enterprise metaverse.</p>
          <a data-gjs-name="Button" href="#" class="cb-btn">Enter Simulation</a>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 12. THE DIAGONAL DUOTONE (Split Screen)
  // ============================================
  {
    id: 'banner-diagonal-duotone',
    label: 'Diagonal Duotone',
    category: 'Banners',
    content: `<section data-gjs-name="Creative Banner" class="cb-06">
      <style>
        .cb-06 { background-image: url('https://images.unsplash.com/photo-1520333789090-1afc82db536a?q=80&w=1800&auto=format&fit=crop'); background-size: cover; background-position: center; position: relative; display: flex; align-items: center; min-height: 700px; overflow: hidden; font-family: 'Inter', sans-serif; color: #fff; }
        .cb-06 .c6-overlay { position: absolute; inset: 0; background: linear-gradient(115deg, #3b0764 50%, transparent 50%); mix-blend-mode: multiply; }
        .cb-06 .c6-dimmer { position: absolute; inset: 0; background: linear-gradient(115deg, rgba(0,0,0,0.6) 50%, rgba(0,0,0,0.3) 50%); }
        .cb-06 .c6-content { position: relative; z-index: 2; width: 50%; padding: 60px 10%; }
        .cb-06 .c6-label { color: #d8b4fe; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 20px; display: block; }
        .cb-06 .c6-title { font-family: 'Outfit', sans-serif; font-size: 4rem; line-height: 1.1; margin-bottom: 30px; }
        .cb-06 .c6-desc { font-size: 1.2rem; color: #e9d5ff; margin-bottom: 40px; line-height: 1.6; }
        .cb-06 .cb-btn { display: inline-block; padding: 16px 36px; background: #d8b4fe; color: #3b0764; border-radius: 50px; font-weight: 700; text-decoration: none; transition: 0.3s; }
        .cb-06 .cb-btn:hover { background: #fff; transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        @media(max-width: 900px) { .cb-06 .c6-overlay { background: linear-gradient(180deg, #3b0764 60%, transparent 60%); } .cb-06 .c6-dimmer { background: linear-gradient(180deg, rgba(0,0,0,0.6) 60%, rgba(0,0,0,0.3) 60%); } .cb-06 .c6-content { width: 100%; padding: 60px 20px; } .cb-06 .c6-title { font-size: 2.5rem; } }
      </style>
      <div data-gjs-name="Color Overlay" class="c6-overlay"></div>
      <div data-gjs-name="Dimmer" class="c6-dimmer"></div>
      <div data-gjs-name="Content" class="c6-content">
        <span data-gjs-name="Label" class="c6-label">Nightlife Evolved</span>
        <h1 data-gjs-name="Title" class="c6-title">Own the Night.</h1>
        <p data-gjs-name="Description" class="c6-desc">Exclusive access to the city's premier events, lounges, and experiences.</p>
        <a data-gjs-name="Button" href="#" class="cb-btn">Apply for Membership</a>
      </div>
    </section>`
  },
]
