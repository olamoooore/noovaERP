import { Preset } from './types'

// Tab presets use simple HTML structure with custom JavaScript
// Background colors are in CSS classes (not inline) so style manager can override them

export const tabPresets: Preset[] = [
  // ============================================
  // 1. THE SLIDING CAPSULE (Apple/iOS Style)
  // ============================================
  {
    id: 'tabs-sliding-capsule',
    label: 'Sliding Capsule',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-s1" style="padding: 80px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; font-family: 'Inter', sans-serif;">
      <style>
        .tabs-s1 { background-color: #111111; }
        .tabs-s1 .capsule-container { background: #27272a; padding: 6px; border-radius: 50px; display: inline-flex; position: relative; box-shadow: inset 0 2px 5px rgba(0,0,0,0.2); }
        .tabs-s1 .capsule-btn { background: transparent; border: none; color: #999; padding: 10px 30px; border-radius: 40px; font-weight: 600; cursor: pointer; position: relative; z-index: 2; transition: color 0.3s, background 0.3s; text-decoration: none; display: inline-block; }
        .tabs-s1 .capsule-btn:hover { color: #ccc; }
        .tabs-s1 .capsule-btn.active { color: #000 !important; background: #fff !important; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .tabs-s1 .content-box { margin-top: 40px; text-align: center; max-width: 500px; color: #fff; width: 100%; }
        .tabs-s1 .tab-pane { display: none; width: 100%; padding: 20px; }
        .tabs-s1 .tab-pane.active { display: block; animation: fadeInTabs 0.4s ease forwards; }
        @keyframes fadeInTabs { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="capsule1" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <nav data-gjs-name="Tab Navigation" class="capsule-container">
          <a data-gjs-name="Tab Button" href="#capsule1-a" class="capsule-btn active">Overview</a>
          <a data-gjs-name="Tab Button" href="#capsule1-b" class="capsule-btn">Details</a>
          <a data-gjs-name="Tab Button" href="#capsule1-c" class="capsule-btn">Reviews</a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="content-box">
          <div data-gjs-name="Tab Panel" id="capsule1-a" class="tab-pane active">
            <h3 style="font-size: 24px; margin-bottom: 10px;">Overview Panel</h3>
            <p style="color: rgba(255,255,255,0.6); line-height: 1.6;">Smooth, simple, and clean. The standard for modern SaaS dashboards.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="capsule1-b" class="tab-pane">
            <h3 style="font-size: 24px; margin-bottom: 10px;">Details Panel</h3>
            <p style="color: rgba(255,255,255,0.6); line-height: 1.6;">Specific technical details go here. Hidden until requested.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="capsule1-c" class="tab-pane">
            <h3 style="font-size: 24px; margin-bottom: 10px;">Reviews Panel</h3>
            <p style="color: rgba(255,255,255,0.6); line-height: 1.6;">★★★★★ - "Best UI kit I've ever used."</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="capsule1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.capsule-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 2. NEON CYBERPUNK
  // ============================================
  {
    id: 'tabs-neon-cyberpunk',
    label: 'Neon Cyberpunk',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-s2" style="padding: 80px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; font-family: 'Inter', sans-serif;">
      <style>
        .tabs-s2 { background-color: #050505; }
        .tabs-s2 .neon-nav { display: flex; gap: 20px; border-bottom: 2px solid #333; padding-bottom: 0; }
        .tabs-s2 .neon-btn { background: transparent; border: none; color: #fff; padding: 15px 25px; font-family: 'Space Grotesk', sans-serif; text-transform: uppercase; font-size: 1.2rem; cursor: pointer; position: relative; opacity: 0.5; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .tabs-s2 .neon-btn::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0%; height: 2px; background: #0ff; box-shadow: 0 0 10px #0ff; transition: width 0.3s ease; }
        .tabs-s2 .neon-btn:hover { opacity: 0.8; }
        .tabs-s2 .neon-btn.active { opacity: 1; text-shadow: 0 0 10px rgba(0,255,255,0.5); }
        .tabs-s2 .neon-btn.active::after { width: 100%; }
        .tabs-s2 .content-box { border: 1px solid #333; padding: 40px; margin-top: 30px; background: linear-gradient(180deg, rgba(0,255,255,0.05) 0%, transparent 100%); border-top: 1px solid #0ff; max-width: 600px; color: #fff; width: 100%; border-radius: 4px; }
        .tabs-s2 .tab-pane { display: none; width: 100%; }
        .tabs-s2 .tab-pane.active { display: block; animation: fadeInTabs 0.4s ease forwards; }
        @keyframes fadeInTabs { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="neon1" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <nav data-gjs-name="Tab Navigation" class="neon-nav">
          <a data-gjs-name="Tab Button" href="#neon1-a" class="neon-btn active">Grid</a>
          <a data-gjs-name="Tab Button" href="#neon1-b" class="neon-btn">Net</a>
          <a data-gjs-name="Tab Button" href="#neon1-c" class="neon-btn">Sys</a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="content-box">
          <div data-gjs-name="Tab Panel" id="neon1-a" class="tab-pane active">
            <h3 style="color: #fff; margin-bottom: 10px;">GRID SYSTEM ONLINE</h3>
            <p style="color: #0ff; font-family: 'Space Grotesk', sans-serif;">&gt; Connection established.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="neon1-b" class="tab-pane">
            <h3 style="color: #fff; margin-bottom: 10px;">NETWORK STATUS</h3>
            <p style="color: #0ff; font-family: 'Space Grotesk', sans-serif;">&gt; Secure channel active.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="neon1-c" class="tab-pane">
            <h3 style="color: #fff; margin-bottom: 10px;">SYSTEM DIAGNOSTICS</h3>
            <p style="color: #0ff; font-family: 'Space Grotesk', sans-serif;">&gt; All systems operational.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="neon1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.neon-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 3. VERTICAL GLASSMORPHISM
  // ============================================
  {
    id: 'tabs-vertical-glass',
    label: 'Vertical Glass',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-s3" style="padding: 80px 20px; display: flex; justify-content: center; align-items: center; min-height: 500px; font-family: 'Inter', sans-serif;">
      <style>
        .tabs-s3 { background: radial-gradient(circle at top right, #4338ca 0%, #0f0f11 60%); }
        .tabs-s3 .glass-wrapper { display: flex; background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; overflow: hidden; width: 100%; max-width: 800px; min-height: 400px; }
        .tabs-s3 .glass-sidebar { width: 30%; background: rgba(0,0,0,0.2); padding: 30px 0; display: flex; flex-direction: column; }
        .tabs-s3 .glass-btn { background: transparent; border: none; color: rgba(255,255,255,0.6); padding: 20px 30px; text-align: left; cursor: pointer; border-left: 3px solid transparent; transition: 0.3s; text-decoration: none; display: block; }
        .tabs-s3 .glass-btn:hover { background: rgba(255,255,255,0.05); }
        .tabs-s3 .glass-btn.active { color: #fff; background: rgba(255,255,255,0.1); border-left-color: #6366f1; }
        .tabs-s3 .glass-content { flex: 1; display: flex; }
        .tabs-s3 .glass-panel { padding: 40px; flex: 1; color: #fff; display: none; }
        .tabs-s3 .glass-panel.active { display: block; animation: fadeInTabs 0.4s ease forwards; }
        @keyframes fadeInTabs { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="glass1" class="glass-wrapper">
        <nav data-gjs-name="Tab Navigation" class="glass-sidebar">
          <a data-gjs-name="Tab Button" href="#glass1-a" class="glass-btn active">Account</a>
          <a data-gjs-name="Tab Button" href="#glass1-b" class="glass-btn">Security</a>
          <a data-gjs-name="Tab Button" href="#glass1-c" class="glass-btn">Billing</a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="glass-content">
          <div data-gjs-name="Tab Panel" id="glass1-a" class="glass-panel active">
            <h3 style="font-size: 2rem; margin-bottom: 20px;">Account Settings</h3>
            <p>Manage your profile information and preferences here.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="glass1-b" class="glass-panel">
            <h3 style="font-size: 2rem; margin-bottom: 20px;">Security</h3>
            <p>Update your password and enable two-factor authentication.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="glass1-c" class="glass-panel">
            <h3 style="font-size: 2rem; margin-bottom: 20px;">Billing</h3>
            <p>View your invoice history and manage payment methods.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="glass1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.glass-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.glass-panel').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 4. NEO-BRUTALIST FOLDER
  // ============================================
  {
    id: 'tabs-neo-brutalist',
    label: 'Neo-Brutalist',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-s4" style="padding: 80px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 500px; font-family: 'Inter', sans-serif; color: #000;">
      <style>
        .tabs-s4 { background-color: #f4d03f; }
        .tabs-s4 .folder-nav { display: flex; gap: 10px; margin-bottom: -3px; z-index: 2; position: relative; }
        .tabs-s4 .folder-btn { background: #fff; border: 3px solid #000; padding: 15px 30px; font-weight: 800; cursor: pointer; box-shadow: 4px 4px 0 #000; transition: 0.2s; text-decoration: none; color: #000; display: inline-block; }
        .tabs-s4 .folder-btn:hover { transform: translateY(-2px); }
        .tabs-s4 .folder-btn.active { background: #000; color: #f4d03f; transform: translateY(-5px); box-shadow: 6px 6px 0 rgba(0,0,0,0.2); }
        .tabs-s4 .folder-body { background: #fff; border: 3px solid #000; padding: 40px; box-shadow: 8px 8px 0 #000; width: 100%; max-width: 700px; position: relative; z-index: 1; }
        .tabs-s4 .tab-pane { display: none; width: 100%; }
        .tabs-s4 .tab-pane.active { display: block; animation: fadeInTabs 0.4s ease forwards; }
        @keyframes fadeInTabs { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="brutal1" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <nav data-gjs-name="Tab Navigation" class="folder-nav">
          <a data-gjs-name="Tab Button" href="#brutal1-a" class="folder-btn active">Work</a>
          <a data-gjs-name="Tab Button" href="#brutal1-b" class="folder-btn">Play</a>
          <a data-gjs-name="Tab Button" href="#brutal1-c" class="folder-btn">Sleep</a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="folder-body">
          <div data-gjs-name="Tab Panel" id="brutal1-a" class="tab-pane active">
            <h3 style="font-size: 2rem; margin-bottom: 10px;">Work Mode</h3>
            <p>High contrast for high productivity. No distractions.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="brutal1-b" class="tab-pane">
            <h3 style="font-size: 2rem; margin-bottom: 10px;">Play Mode</h3>
            <p>Time to relax. Games, movies, and leisure.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="brutal1-c" class="tab-pane">
            <h3 style="font-size: 2rem; margin-bottom: 10px;">Sleep Mode</h3>
            <p>Recharge. Do not disturb is active.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="brutal1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.folder-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 5. MINIMALIST DOT INDICATOR
  // ============================================
  {
    id: 'tabs-minimal-dot',
    label: 'Minimal Dot',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-s5" style="padding: 80px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; font-family: 'Inter', sans-serif; color: #000;">
      <style>
        .tabs-s5 { background-color: #ffffff; }
        .tabs-s5 .clean-nav { display: flex; gap: 40px; margin-bottom: 40px; }
        .tabs-s5 .clean-btn { background: none; border: none; font-size: 1.1rem; color: #999; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 8px; transition: 0.3s; text-decoration: none; }
        .tabs-s5 .clean-btn:hover { color: #666; }
        .tabs-s5 .clean-btn .dot { width: 6px; height: 6px; background: #000; border-radius: 50%; opacity: 0; transform: translateY(10px); transition: 0.3s; }
        .tabs-s5 .clean-btn.active { color: #000; font-weight: 600; }
        .tabs-s5 .clean-btn.active .dot { opacity: 1; transform: translateY(0); }
        .tabs-s5 .clean-content { max-width: 600px; text-align: center; width: 100%; }
        .tabs-s5 .tab-pane { display: none; width: 100%; }
        .tabs-s5 .tab-pane.active { display: block; animation: fadeInTabs 0.4s ease forwards; }
        @keyframes fadeInTabs { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="minimal1" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <nav data-gjs-name="Tab Navigation" class="clean-nav">
          <a data-gjs-name="Tab Button" href="#minimal1-a" class="clean-btn active"><span>Design</span><span class="dot"></span></a>
          <a data-gjs-name="Tab Button" href="#minimal1-b" class="clean-btn"><span>Code</span><span class="dot"></span></a>
          <a data-gjs-name="Tab Button" href="#minimal1-c" class="clean-btn"><span>Ship</span><span class="dot"></span></a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="clean-content">
          <div data-gjs-name="Tab Panel" id="minimal1-a" class="tab-pane active">
            <h3 style="font-size: 24px; margin-bottom: 15px;">Design First</h3>
            <p style="color: rgba(0,0,0,0.6); line-height: 1.6;">We start with a blank canvas and iterate until perfection. Less is more.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="minimal1-b" class="tab-pane">
            <h3 style="font-size: 24px; margin-bottom: 15px;">Clean Code</h3>
            <p style="color: rgba(0,0,0,0.6); line-height: 1.6;">Written for humans to read, and machines to execute.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="minimal1-c" class="tab-pane">
            <h3 style="font-size: 24px; margin-bottom: 15px;">Ship Daily</h3>
            <p style="color: rgba(0,0,0,0.6); line-height: 1.6;">Continuous integration means your users get value faster.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="minimal1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.clean-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 6. GRADIENT BORDER CARD
  // ============================================
  {
    id: 'tabs-gradient-border',
    label: 'Gradient Border',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-s6" style="padding: 80px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; font-family: 'Inter', sans-serif;">
      <style>
        .tabs-s6 { background-color: #111111; }
        .tabs-s6 .grad-card-wrapper { width: 100%; max-width: 700px; }
        .tabs-s6 .grad-nav { display: grid; grid-template-columns: repeat(3, 1fr); background: #1a1a1a; border-radius: 12px 12px 0 0; overflow: hidden; }
        .tabs-s6 .grad-btn { background: #1a1a1a; border: none; padding: 20px; color: #666; cursor: pointer; font-weight: 600; border-bottom: 2px solid #333; transition: 0.3s; text-decoration: none; display: block; text-align: center; }
        .tabs-s6 .grad-btn:hover { background: #222; color: #999; }
        .tabs-s6 .grad-btn.active { background: #222; color: #fff; border-bottom: 2px solid transparent; background-image: linear-gradient(#222, #222), linear-gradient(90deg, #ff00cc, #3333ff); background-origin: border-box; background-clip: padding-box, border-box; }
        .tabs-s6 .grad-body { background: #222; padding: 40px; border-radius: 0 0 12px 12px; border: 1px solid #333; border-top: none; color: #fff; }
        .tabs-s6 .tab-pane { display: none; width: 100%; }
        .tabs-s6 .tab-pane.active { display: block; animation: fadeInTabs 0.4s ease forwards; }
        @keyframes fadeInTabs { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="grad1" class="grad-card-wrapper">
        <nav data-gjs-name="Tab Navigation" class="grad-nav">
          <a data-gjs-name="Tab Button" href="#grad1-a" class="grad-btn active">Monthly</a>
          <a data-gjs-name="Tab Button" href="#grad1-b" class="grad-btn">Yearly</a>
          <a data-gjs-name="Tab Button" href="#grad1-c" class="grad-btn">Lifetime</a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="grad-body">
          <div data-gjs-name="Tab Panel" id="grad1-a" class="tab-pane active">
            <h3 style="font-size: 1.5rem; margin-bottom: 10px;">$29 / month</h3>
            <p style="color: #888;">Great for short term projects.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="grad1-b" class="tab-pane">
            <h3 style="font-size: 1.5rem; margin-bottom: 10px;">$290 / year</h3>
            <p style="color: #888;">Save 20% with annual billing.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="grad1-c" class="tab-pane">
            <h3 style="font-size: 1.5rem; margin-bottom: 10px;">$999 One-time</h3>
            <p style="color: #888;">Pay once, own it forever.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="grad1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.grad-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 7. DEV TERMINAL / CODE
  // ============================================
  {
    id: 'tabs-dev-terminal',
    label: 'Dev Terminal',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-s7" style="padding: 80px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; font-family: 'JetBrains Mono', 'Fira Code', monospace;">
      <style>
        .tabs-s7 { background-color: #0d1117; }
        .tabs-s7 .terminal-window { border: 1px solid #30363d; border-radius: 6px; width: 100%; max-width: 600px; overflow: hidden; background: #0d1117; }
        .tabs-s7 .terminal-header { background: #161b22; padding: 10px; display: flex; gap: 1px; border-bottom: 1px solid #30363d; }
        .tabs-s7 .term-btn { background: transparent; border: none; color: #8b949e; padding: 8px 15px; font-family: 'JetBrains Mono', monospace; font-size: 0.9rem; cursor: pointer; border-radius: 4px; text-decoration: none; display: inline-block; }
        .tabs-s7 .term-btn:hover { background: #21262d; }
        .tabs-s7 .term-btn.active { background: #21262d; color: #58a6ff; border-bottom: 2px solid #f78166; border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
        .tabs-s7 .term-body { padding: 30px; color: #c9d1d9; }
        .tabs-s7 .tab-pane { display: none; width: 100%; }
        .tabs-s7 .tab-pane.active { display: block; animation: fadeInTabs 0.4s ease forwards; }
        .tabs-s7 .blink { animation: blinker 1s linear infinite; }
        @keyframes blinker { 50% { opacity: 0; } }
        @keyframes fadeInTabs { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="term1" class="terminal-window">
        <nav data-gjs-name="Tab Navigation" class="terminal-header">
          <a data-gjs-name="Tab Button" href="#term1-a" class="term-btn active">main.js</a>
          <a data-gjs-name="Tab Button" href="#term1-b" class="term-btn">style.css</a>
          <a data-gjs-name="Tab Button" href="#term1-c" class="term-btn">index.html</a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="term-body">
          <div data-gjs-name="Tab Panel" id="term1-a" class="tab-pane active">
            <p><span style="color:#ff7b72">function</span> <span style="color:#d2a8ff">init</span>() {</p>
            <p style="padding-left:20px"><span style="color:#79c0ff">console</span>.log(<span style="color:#a5d6ff">"Hello World"</span>); <span class="blink">|</span></p>
            <p>}</p>
          </div>
          <div data-gjs-name="Tab Panel" id="term1-b" class="tab-pane">
            <p><span style="color:#7ee787">body</span> {</p>
            <p style="padding-left:20px">background: <span style="color:#79c0ff">#000</span>;</p>
            <p>}</p>
          </div>
          <div data-gjs-name="Tab Panel" id="term1-c" class="tab-pane">
            <p>&lt;<span style="color:#7ee787">div</span> class=<span style="color:#a5d6ff">"hero"</span>&gt;</p>
            <p style="padding-left:20px">Welcome</p>
            <p>&lt;/<span style="color:#7ee787">div</span>&gt;</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="term1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.term-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 8. FLOATING 3D CARDS
  // ============================================
  {
    id: 'tabs-floating-3d',
    label: 'Floating 3D',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-s8" style="padding: 80px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; font-family: 'Inter', sans-serif; color: #333; perspective: 1000px;">
      <style>
        .tabs-s8 { background-color: #eef2f6; }
        .tabs-s8 .float-nav { display: flex; justify-content: center; gap: 20px; margin-bottom: 30px; }
        .tabs-s8 .float-btn { background: #fff; border: none; padding: 15px 30px; border-radius: 12px; color: #777; font-weight: 600; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); text-decoration: none; display: inline-block; }
        .tabs-s8 .float-btn:hover { transform: translateY(-2px); }
        .tabs-s8 .float-btn.active { background: #3b82f6; color: white; transform: translateY(-5px) scale(1.05); box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3); }
        .tabs-s8 .float-content { background: #fff; padding: 40px; border-radius: 20px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); max-width: 600px; width: 100%; }
        .tabs-s8 .tab-pane { display: none; width: 100%; }
        .tabs-s8 .tab-pane.active { display: block; animation: fadeInTabs 0.4s ease forwards; }
        @keyframes fadeInTabs { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="float1" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <nav data-gjs-name="Tab Navigation" class="float-nav">
          <a data-gjs-name="Tab Button" href="#float1-a" class="float-btn active">Fast</a>
          <a data-gjs-name="Tab Button" href="#float1-b" class="float-btn">Secure</a>
          <a data-gjs-name="Tab Button" href="#float1-c" class="float-btn">Global</a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="float-content">
          <div data-gjs-name="Tab Panel" id="float1-a" class="tab-pane active">
            <h3 style="font-size: 1.5rem; margin-bottom: 10px;">Lightning Speed</h3>
            <p style="color: #666;">Optimized for performance metrics that matter to Google.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="float1-b" class="tab-pane">
            <h3 style="font-size: 1.5rem; margin-bottom: 10px;">Bank-Grade Security</h3>
            <p style="color: #666;">End-to-end encryption for all your data transactions.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="float1-c" class="tab-pane">
            <h3 style="font-size: 1.5rem; margin-bottom: 10px;">Global CDN</h3>
            <p style="color: #666;">Servers in 30+ regions ensure low latency everywhere.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="float1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.float-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 9. GRADIENT MESH UNDERLINE
  // ============================================
  {
    id: 'tabs-gradient-mesh',
    label: 'Gradient Mesh',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-s9" style="padding: 80px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; font-family: 'Inter', sans-serif; color: #fff;">
      <style>
        .tabs-s9 { background-color: #000000; }
        .tabs-s9 .mesh-nav { position: relative; display: flex; gap: 40px; margin-bottom: 40px; }
        .tabs-s9 .mesh-btn { background: none; border: none; color: #666; font-size: 1.5rem; font-weight: 700; cursor: pointer; padding-bottom: 10px; position: relative; transition: 0.3s; text-decoration: none; display: inline-block; }
        .tabs-s9 .mesh-btn.active { background: linear-gradient(to right, #ec4899, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .tabs-s9 .mesh-btn::after { content: ''; position: absolute; bottom: 0; left: 0; width: 0; height: 4px; background: linear-gradient(to right, #ec4899, #8b5cf6); transition: width 0.3s ease; }
        .tabs-s9 .mesh-btn.active::after { width: 100%; }
        .tabs-s9 .mesh-content { max-width: 600px; text-align: left; width: 100%; }
        .tabs-s9 .tab-pane { display: none; width: 100%; }
        .tabs-s9 .tab-pane.active { display: block; animation: fadeInTabs 0.4s ease forwards; }
        @keyframes fadeInTabs { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="mesh1" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <nav data-gjs-name="Tab Navigation" class="mesh-nav">
          <a data-gjs-name="Tab Button" href="#mesh1-a" class="mesh-btn active">Create</a>
          <a data-gjs-name="Tab Button" href="#mesh1-b" class="mesh-btn">Iterate</a>
          <a data-gjs-name="Tab Button" href="#mesh1-c" class="mesh-btn">Scale</a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="mesh-content">
          <div data-gjs-name="Tab Panel" id="mesh1-a" class="tab-pane active">
            <h3 style="font-size: 2rem; margin-bottom: 15px;">Start creating today.</h3>
            <p style="color: #888;">Use our tools to build the foundation of your next big idea.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="mesh1-b" class="tab-pane">
            <h3 style="font-size: 2rem; margin-bottom: 15px;">Refine your process.</h3>
            <p style="color: #888;">Data-driven insights help you tweak and improve every step.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="mesh1-c" class="tab-pane">
            <h3 style="font-size: 2rem; margin-bottom: 15px;">Scale without limits.</h3>
            <p style="color: #888;">Infrastructure that grows with you, from 1 user to 1 billion.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="mesh1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.mesh-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 10. BASIC CLASSIC BORDER TABS
  // ============================================
  {
    id: 'tabs-basic-classic',
    label: 'Classic Border',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-basic-classic" style="padding: 40px 20px; display: flex; flex-direction: column; min-height: 300px; font-family: 'Inter', sans-serif;">
      <style>
        .tabs-basic-classic { background-color: #ffffff; }
        .tabs-basic-classic .tabs-classic-nav { display: flex; border-bottom: 2px solid #e5e7eb; gap: 20px; margin-bottom: 20px; }
        .tabs-basic-classic .tab-btn-classic { background: none; border: none; padding: 10px 0; font-size: 1rem; color: #666; cursor: pointer; position: relative; margin-bottom: -2px; border-bottom: 2px solid transparent; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .tabs-basic-classic .tab-btn-classic:hover { color: #000; }
        .tabs-basic-classic .tab-btn-classic.active { color: #000; font-weight: bold; border-bottom: 2px solid #3b82f6; }
        .tabs-basic-classic .tab-content { display: none; }
        .tabs-basic-classic .tab-content.active { display: block; animation: fadeIn 0.2s ease-in; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="classic1" class="tab-wrapper">
        <nav data-gjs-name="Tab Navigation" class="tabs-classic-nav">
          <a data-gjs-name="Tab Button" href="#classic1-a" class="tab-btn-classic active">Profile</a>
          <a data-gjs-name="Tab Button" href="#classic1-b" class="tab-btn-classic">Settings</a>
          <a data-gjs-name="Tab Button" href="#classic1-c" class="tab-btn-classic">Messages</a>
        </nav>
        <div data-gjs-name="Tab Content Area">
          <div data-gjs-name="Tab Panel" id="classic1-a" class="tab-content active">
            <p><strong>Profile Content:</strong> This is the default tab style used on most websites. It's clean and unobtrusive.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="classic1-b" class="tab-content">
            <p><strong>Settings Content:</strong> You can easily change the bottom border color in the CSS.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="classic1-c" class="tab-content">
            <p><strong>Messages Content:</strong> Minimal structure makes this easy to integrate.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="classic1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.tab-btn-classic').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-content').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 11. BASIC PILL / SEGMENTED TABS
  // ============================================
  {
    id: 'tabs-basic-pill',
    label: 'Pill Toggle',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-basic-pill" style="padding: 40px 20px; display: flex; flex-direction: column; min-height: 300px; font-family: 'Inter', sans-serif;">
      <style>
        .tabs-basic-pill { background-color: #ffffff; }
        .tabs-basic-pill .tabs-pill-nav { display: inline-flex; background: #f3f4f6; padding: 4px; border-radius: 8px; margin-bottom: 20px; }
        .tabs-basic-pill .tab-btn-pill { background: transparent; border: none; padding: 8px 24px; border-radius: 6px; font-size: 0.9rem; font-weight: 500; color: #666; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .tabs-basic-pill .tab-btn-pill.active { background: #ffffff; color: #000; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .tabs-basic-pill .tab-content { display: none; margin-top: 20px; }
        .tabs-basic-pill .tab-content.active { display: block; animation: fadeIn 0.2s ease-in; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="pill1" class="tab-wrapper">
        <nav data-gjs-name="Tab Navigation" class="tabs-pill-nav">
          <a data-gjs-name="Tab Button" href="#pill1-a" class="tab-btn-pill active">Monthly</a>
          <a data-gjs-name="Tab Button" href="#pill1-b" class="tab-btn-pill">Yearly</a>
        </nav>
        <div data-gjs-name="Tab Content Area">
          <div data-gjs-name="Tab Panel" id="pill1-a" class="tab-content active">
            <p><strong>Monthly View:</strong> This style is perfect for pricing toggles or small UI filters.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="pill1-b" class="tab-content">
            <p><strong>Yearly View:</strong> The rounded corners and background color can be fully customized.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="pill1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.tab-btn-pill').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-content').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },

  // ============================================
  // 12. BASIC VERTICAL SIDEBAR TABS
  // ============================================
  {
    id: 'tabs-basic-vertical',
    label: 'Vertical Sidebar',
    category: 'Tabs',
    content: `<section data-gjs-name="Tab Section" class="tabs-basic-vertical" style="padding: 40px 20px; display: flex; flex-direction: column; min-height: 400px; font-family: 'Inter', sans-serif;">
      <style>
        .tabs-basic-vertical { background-color: #ffffff; }
        .tabs-basic-vertical .tabs-vertical-wrapper { display: flex; gap: 40px; }
        .tabs-basic-vertical .tabs-vertical-nav { display: flex; flex-direction: column; width: 200px; border-right: 1px solid #e5e7eb; padding-right: 20px; }
        .tabs-basic-vertical .tab-btn-vertical { text-align: left; background: none; border: none; padding: 12px 15px; font-size: 1rem; color: #666; cursor: pointer; border-radius: 6px; transition: background 0.2s; text-decoration: none; display: block; }
        .tabs-basic-vertical .tab-btn-vertical:hover { background: #f9fafb; }
        .tabs-basic-vertical .tab-btn-vertical.active { background: #f3f4f6; color: #000; font-weight: 600; }
        .tabs-basic-vertical .tabs-vertical-content { flex: 1; }
        .tabs-basic-vertical .tab-content { display: none; }
        .tabs-basic-vertical .tab-content.active { display: block; animation: fadeIn 0.2s ease-in; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
      </style>
      <div data-gjs-name="Tabs Container" data-tab-group="vertical1" class="tab-wrapper tabs-vertical-wrapper">
        <nav data-gjs-name="Tab Navigation" class="tabs-vertical-nav">
          <a data-gjs-name="Tab Button" href="#vertical1-a" class="tab-btn-vertical active">General</a>
          <a data-gjs-name="Tab Button" href="#vertical1-b" class="tab-btn-vertical">Password</a>
          <a data-gjs-name="Tab Button" href="#vertical1-c" class="tab-btn-vertical">Team</a>
          <a data-gjs-name="Tab Button" href="#vertical1-d" class="tab-btn-vertical">Billing</a>
        </nav>
        <div data-gjs-name="Tab Content Area" class="tabs-vertical-content">
          <div data-gjs-name="Tab Panel" id="vertical1-a" class="tab-content active">
            <h3 style="margin-top: 0;">General Settings</h3>
            <p>This layout is ideal for dashboards, documentation, or complex settings pages where horizontal space is limited.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="vertical1-b" class="tab-content">
            <h3 style="margin-top: 0;">Password</h3>
            <p>Content changes on the right while navigation stays on the left.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="vertical1-c" class="tab-content">
            <h3 style="margin-top: 0;">Team Members</h3>
            <p>Add a list of team members here.</p>
          </div>
          <div data-gjs-name="Tab Panel" id="vertical1-d" class="tab-content">
            <h3 style="margin-top: 0;">Billing</h3>
            <p>Manage your invoices and payment methods.</p>
          </div>
        </div>
      </div>
      <script>
        (function() {
          var container = document.querySelector('[data-tab-group="vertical1"]');
          if (!container) return;
          container.addEventListener('click', function(e) {
            var btn = e.target.closest('a[href^="#"]');
            if (!btn) return;
            e.preventDefault();
            var targetId = btn.getAttribute('href').replace('#', '');
            container.querySelectorAll('.tab-btn-vertical').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            container.querySelectorAll('.tab-content').forEach(function(p) { p.classList.remove('active'); });
            var target = container.querySelector('#' + targetId);
            if (target) target.classList.add('active');
          });
        })();
      </script>
    </section>`
  },
]