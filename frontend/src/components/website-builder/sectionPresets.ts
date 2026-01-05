// Section Presets for GrapesJS Editor
// 4 Premium Feature Section Designs with inline styles

export interface SectionPreset {
  id: string
  name: string
  thumbnail: string
  content: string
}

export const sectionPresets: SectionPreset[] = [
  // ============================================
  // PRESET 1: CYBER-GLASS GRID (Dark Mode)
  // ============================================
  {
    id: 'section-cyber-glass',
    name: 'Cyber Glass Grid',
    thumbnail: `<div style="width: 100%; height: 100%; background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%); border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 8px;">
      <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; width: 100%;">
        <div style="height: 20px; background: rgba(139, 92, 246, 0.3); border-radius: 4px; border: 1px solid rgba(139, 92, 246, 0.5);"></div>
        <div style="height: 20px; background: rgba(236, 72, 153, 0.3); border-radius: 4px; border: 1px solid rgba(236, 72, 153, 0.5);"></div>
        <div style="height: 20px; background: rgba(6, 182, 212, 0.3); border-radius: 4px; border: 1px solid rgba(6, 182, 212, 0.5);"></div>
      </div>
      <div style="font-size: 6px; color: #a78bfa; margin-top: 6px;">Glass Cards</div>
    </div>`,
    content: `<section style="position: relative; padding: 96px 24px; overflow: hidden; background: linear-gradient(180deg, #020617 0%, #0f172a 50%, #020617 100%);">
      <!-- Background Grid Pattern -->
      <div style="position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px); background-size: 50px 50px;"></div>
      
      <!-- Ambient Glow Orbs -->
      <div style="position: absolute; top: 80px; left: 25%; width: 384px; height: 384px; background: rgba(139, 92, 246, 0.2); border-radius: 50%; filter: blur(64px);"></div>
      <div style="position: absolute; bottom: 80px; right: 25%; width: 384px; height: 384px; background: rgba(217, 70, 239, 0.2); border-radius: 50%; filter: blur(64px);"></div>
      
      <div style="position: relative; z-index: 10; max-width: 1280px; margin: 0 auto;">
        <!-- Section Header -->
        <div style="text-align: center; margin-bottom: 64px;">
          <div style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: rgba(139, 92, 246, 0.1); border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 9999px; margin-bottom: 24px;">
            <span style="width: 8px; height: 8px; background: #a78bfa; border-radius: 50%;"></span>
            <span style="font-size: 14px; color: #c4b5fd; font-weight: 500;">Powerful Features</span>
          </div>
          <h2 style="font-size: 48px; font-weight: 700; letter-spacing: -0.025em; margin-bottom: 16px; background: linear-gradient(to right, #ffffff, #ddd6fe, #f5d0fe); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Build Faster, Scale Smarter</h2>
          <p style="font-size: 18px; color: #94a3b8; max-width: 672px; margin: 0 auto;">Everything you need to ship world-class products in record time.</p>
        </div>
        
        <!-- Features Grid -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px;">
          
          <!-- Card 1: Analytics -->
          <div style="position: relative; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 24px; overflow: hidden; transition: all 0.3s;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
            </div>
            <h3 style="font-size: 20px; font-weight: 600; color: #ffffff; margin-bottom: 8px;">Real-time Analytics</h3>
            <p style="font-size: 14px; color: #94a3b8; margin-bottom: 24px;">Track every metric that matters with live dashboards and custom reports.</p>
            <div style="background: rgba(30, 41, 59, 0.5); border-radius: 12px; padding: 16px;">
              <div style="display: flex; align-items: flex-end; gap: 4px; height: 64px;">
                <div style="flex: 1; background: rgba(139, 92, 246, 0.6); border-radius: 4px 4px 0 0; height: 40%;"></div>
                <div style="flex: 1; background: rgba(139, 92, 246, 0.6); border-radius: 4px 4px 0 0; height: 65%;"></div>
                <div style="flex: 1; background: rgba(139, 92, 246, 0.6); border-radius: 4px 4px 0 0; height: 55%;"></div>
                <div style="flex: 1; background: rgba(139, 92, 246, 0.6); border-radius: 4px 4px 0 0; height: 80%;"></div>
                <div style="flex: 1; background: rgba(139, 92, 246, 0.6); border-radius: 4px 4px 0 0; height: 70%;"></div>
                <div style="flex: 1; background: rgba(139, 92, 246, 0.6); border-radius: 4px 4px 0 0; height: 90%;"></div>
                <div style="flex: 1; background: rgba(139, 92, 246, 0.6); border-radius: 4px 4px 0 0; height: 75%;"></div>
              </div>
            </div>
          </div>
          
          <!-- Card 2: Team Collaboration -->
          <div style="position: relative; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 24px; overflow: hidden; transition: all 0.3s;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #d946ef, #ec4899); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <h3 style="font-size: 20px; font-weight: 600; color: #ffffff; margin-bottom: 8px;">Team Collaboration</h3>
            <p style="font-size: 14px; color: #94a3b8; margin-bottom: 24px;">Work together seamlessly with real-time editing and instant feedback.</p>
            <div style="display: flex; align-items: center; justify-content: space-between;">
              <div style="display: flex; margin-left: -12px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #fb923c, #ef4444); border: 2px solid #0f172a;"></div>
                <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #60a5fa, #6366f1); border: 2px solid #0f172a; margin-left: -12px;"></div>
                <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #4ade80, #10b981); border: 2px solid #0f172a; margin-left: -12px;"></div>
                <div style="width: 40px; height: 40px; border-radius: 50%; background: #334155; border: 2px solid #0f172a; margin-left: -12px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #ffffff; font-weight: 500;">+3</div>
              </div>
              <span style="font-size: 14px; color: #94a3b8;">Active now</span>
            </div>
          </div>
          
          <!-- Card 3: Automation -->
          <div style="position: relative; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 24px; overflow: hidden; transition: all 0.3s;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #06b6d4, #3b82f6); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="8" x="3" y="3" rx="2"/><path d="M7 11v4a2 2 0 0 0 2 2h4"/><rect width="8" height="8" x="13" y="13" rx="2"/></svg>
            </div>
            <h3 style="font-size: 20px; font-weight: 600; color: #ffffff; margin-bottom: 8px;">Smart Automation</h3>
            <p style="font-size: 14px; color: #94a3b8; margin-bottom: 24px;">Automate repetitive tasks and focus on what really matters.</p>
            <div style="display: flex; align-items: center; gap: 8px;">
              <div style="padding: 6px 12px; background: #1e293b; border-radius: 8px; font-size: 12px; color: #cbd5e1;">Trigger</div>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
              <div style="padding: 6px 12px; background: #1e293b; border-radius: 8px; font-size: 12px; color: #cbd5e1;">Action</div>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
              <div style="padding: 6px 12px; background: rgba(34, 197, 94, 0.2); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px; font-size: 12px; color: #4ade80;">Done</div>
            </div>
          </div>
          
          <!-- Card 4: Security -->
          <div style="position: relative; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 24px; overflow: hidden; transition: all 0.3s;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #10b981, #14b8a6); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
            </div>
            <h3 style="font-size: 20px; font-weight: 600; color: #ffffff; margin-bottom: 8px;">Enterprise Security</h3>
            <p style="font-size: 14px; color: #94a3b8; margin-bottom: 24px;">Bank-grade encryption and compliance with SOC2, GDPR, and HIPAA.</p>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
              <span style="padding: 4px 8px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 4px; font-size: 12px; color: #34d399;">SOC 2</span>
              <span style="padding: 4px 8px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 4px; font-size: 12px; color: #34d399;">GDPR</span>
              <span style="padding: 4px 8px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 4px; font-size: 12px; color: #34d399;">HIPAA</span>
            </div>
          </div>
          
          <!-- Card 5: API Access -->
          <div style="position: relative; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 24px; overflow: hidden; transition: all 0.3s;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #f97316, #f59e0b); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8"/><path d="M3.27 6.96L12 12.01l8.73-5.05"/><path d="M12 22.08V12"/></svg>
            </div>
            <h3 style="font-size: 20px; font-weight: 600; color: #ffffff; margin-bottom: 8px;">Powerful API</h3>
            <p style="font-size: 14px; color: #94a3b8; margin-bottom: 24px;">REST and GraphQL APIs with comprehensive documentation.</p>
            <div style="background: #020617; border-radius: 8px; padding: 12px; font-family: monospace; font-size: 12px;">
              <div style="color: #94a3b8;">curl -X POST api.dev/</div>
              <div style="color: #34d399;">\ -d '{"status": "ok"}'</div>
            </div>
          </div>
          
          <!-- Card 6: 24/7 Support -->
          <div style="position: relative; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 24px; overflow: hidden; transition: all 0.3s;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #f43f5e, #dc2626); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
            </div>
            <h3 style="font-size: 20px; font-weight: 600; color: #ffffff; margin-bottom: 8px;">24/7 Support</h3>
            <p style="font-size: 14px; color: #94a3b8; margin-bottom: 24px;">Our team is always here to help you succeed, day or night.</p>
            <div style="display: flex; align-items: center; gap: 12px;">
              <div style="flex: 1;">
                <div style="height: 8px; background: #1e293b; border-radius: 9999px; overflow: hidden;">
                  <div style="height: 100%; background: linear-gradient(to right, #f43f5e, #dc2626); border-radius: 9999px; width: 95%;"></div>
                </div>
              </div>
              <span style="font-size: 14px; color: #94a3b8;">&lt; 2 min</span>
            </div>
          </div>
        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 2: CONNECTED TIMELINE (Process)
  // ============================================
  {
    id: 'section-timeline',
    name: 'Connected Timeline',
    thumbnail: `<div style="width: 100%; height: 100%; background: #f8fafc; border-radius: 8px; display: flex; align-items: center; justify-content: center; padding: 8px;">
      <div style="display: flex; align-items: center; gap: 4px;">
        <div style="width: 8px; height: 8px; background: #10b981; border-radius: 50%;"></div>
        <div style="width: 20px; height: 2px; background: linear-gradient(to right, #10b981, #06b6d4);"></div>
        <div style="width: 8px; height: 8px; background: #06b6d4; border-radius: 50%;"></div>
        <div style="width: 20px; height: 2px; background: linear-gradient(to right, #06b6d4, #8b5cf6);"></div>
        <div style="width: 8px; height: 8px; background: #8b5cf6; border-radius: 50%;"></div>
      </div>
    </div>`,
    content: `<section style="position: relative; padding: 96px 24px; background: #f8fafc;">
      <div style="max-width: 1280px; margin: 0 auto;">
        <!-- Section Header -->
        <div style="text-align: center; margin-bottom: 64px;">
          <div style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: #d1fae5; border: 1px solid #a7f3d0; border-radius: 9999px; margin-bottom: 24px;">
            <span style="width: 8px; height: 8px; background: #10b981; border-radius: 50%;"></span>
            <span style="font-size: 14px; color: #047857; font-weight: 500;">How It Works</span>
          </div>
          <h2 style="font-size: 48px; font-weight: 700; letter-spacing: -0.025em; color: #0f172a; margin-bottom: 16px;">From Idea to Launch</h2>
          <p style="font-size: 18px; color: #475569; max-width: 672px; margin: 0 auto;">A simple, straightforward process to bring your vision to life.</p>
        </div>
        
        <!-- Two Column Layout -->
        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 48px;">
          
          <!-- Left: Timeline -->
          <div style="position: relative;">
            <!-- Connecting Line -->
            <div style="position: absolute; left: 16px; top: 0; bottom: 0; width: 2px; background: linear-gradient(180deg, #10b981 0%, #06b6d4 50%, #8b5cf6 100%);"></div>
            
            <!-- Timeline Items -->
            <div style="display: flex; flex-direction: column; gap: 48px;">
              <!-- Step 1 -->
              <div style="position: relative; padding-left: 40px;">
                <div style="position: absolute; left: 10px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #10b981; border: 4px solid #f8fafc; z-index: 10;"></div>
                <h3 style="font-size: 20px; font-weight: 600; color: #0f172a; margin-bottom: 8px;">Connect Your Data</h3>
                <p style="font-size: 14px; color: #475569;">Integrate with 100+ data sources in minutes, not hours.</p>
              </div>
              
              <!-- Step 2 -->
              <div style="position: relative; padding-left: 40px;">
                <div style="position: absolute; left: 10px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #06b6d4; border: 4px solid #f8fafc; z-index: 10;"></div>
                <h3 style="font-size: 20px; font-weight: 600; color: #0f172a; margin-bottom: 8px;">Build Workflows</h3>
                <p style="font-size: 14px; color: #475569;">Create powerful automations with our visual workflow builder.</p>
              </div>
              
              <!-- Step 3 -->
              <div style="position: relative; padding-left: 40px;">
                <div style="position: absolute; left: 10px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #8b5cf6; border: 4px solid #f8fafc; z-index: 10;"></div>
                <h3 style="font-size: 20px; font-weight: 600; color: #0f172a; margin-bottom: 8px;">Analyze Results</h3>
                <p style="font-size: 14px; color: #475569;">Get actionable insights with our advanced analytics dashboard.</p>
              </div>
              
              <!-- Step 4 -->
              <div style="position: relative; padding-left: 40px;">
                <div style="position: absolute; left: 10px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #f59e0b; border: 4px solid #f8fafc; z-index: 10;"></div>
                <h3 style="font-size: 20px; font-weight: 600; color: #0f172a; margin-bottom: 8px;">Scale Up</h3>
                <p style="font-size: 14px; color: #475569;">Grow your operations with enterprise-grade infrastructure.</p>
              </div>
            </div>
          </div>
          
          <!-- Right: Visual Cards -->
          <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Card 1: Integrations -->
            <div style="background: #ffffff; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1); padding: 24px;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="width: 40px; height: 40px; background: #d1fae5; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                </div>
                <h4 style="font-weight: 600; color: #0f172a;">Connected Integrations</h4>
              </div>
              <div style="display: flex; align-items: center; gap: 32px; overflow: hidden;">
                <div style="display: flex; align-items: center; gap: 8px; color: #475569; font-weight: 500;">
                  <div style="width: 32px; height: 32px; background: #dbeafe; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px;">G</div>
                  Google
                </div>
                <div style="display: flex; align-items: center; gap: 8px; color: #475569; font-weight: 500;">
                  <div style="width: 32px; height: 32px; background: #2563eb; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #ffffff;">S</div>
                  Slack
                </div>
                <div style="display: flex; align-items: center; gap: 8px; color: #475569; font-weight: 500;">
                  <div style="width: 32px; height: 32px; background: #f97316; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #ffffff;">N</div>
                  Notion
                </div>
                <div style="display: flex; align-items: center; gap: 8px; color: #475569; font-weight: 500;">
                  <div style="width: 32px; height: 32px; background: #7c3aed; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #ffffff;">J</div>
                  Jira
                </div>
              </div>
            </div>
            
            <!-- Card 2: Workflow Builder -->
            <div style="background: #ffffff; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1); padding: 24px;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="width: 40px; height: 40px; background: #cffafe; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0891b2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="8" x="3" y="3" rx="2"/><path d="M7 11v4a2 2 0 0 0 2 2h4"/><rect width="8" height="8" x="13" y="13" rx="2"/></svg>
                </div>
                <h4 style="font-weight: 600; color: #0f172a;">Visual Workflow Builder</h4>
              </div>
              <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <div style="padding: 8px 16px; background: #f1f5f9; border-radius: 8px; font-size: 14px; color: #334155;">Trigger</div>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                <div style="padding: 8px 16px; background: #dbeafe; border-radius: 8px; font-size: 14px; color: #1d4ed8; font-weight: 500;">Condition</div>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                <div style="padding: 8px 16px; background: #ede9fe; border-radius: 8px; font-size: 14px; color: #6d28d9; font-weight: 500;">Action</div>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                <div style="padding: 8px 16px; background: #dcfce7; border-radius: 8px; font-size: 14px; color: #15803d; font-weight: 500;">Notify</div>
              </div>
            </div>
            
            <!-- Card 3: Analytics -->
            <div style="background: #ffffff; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1); padding: 24px;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="width: 40px; height: 40px; background: #ede9fe; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
                </div>
                <h4 style="font-weight: 600; color: #0f172a;">Real-time Analytics</h4>
              </div>
              <div style="display: flex; align-items: flex-end; gap: 8px; height: 96px;">
                <div style="flex: 1; background: linear-gradient(to top, #8b5cf6, #a78bfa); border-radius: 4px 4px 0 0; height: 40%;"></div>
                <div style="flex: 1; background: linear-gradient(to top, #8b5cf6, #a78bfa); border-radius: 4px 4px 0 0; height: 65%;"></div>
                <div style="flex: 1; background: linear-gradient(to top, #8b5cf6, #a78bfa); border-radius: 4px 4px 0 0; height: 55%;"></div>
                <div style="flex: 1; background: linear-gradient(to top, #8b5cf6, #a78bfa); border-radius: 4px 4px 0 0; height: 80%;"></div>
                <div style="flex: 1; background: linear-gradient(to top, #8b5cf6, #a78bfa); border-radius: 4px 4px 0 0; height: 70%;"></div>
                <div style="flex: 1; background: linear-gradient(to top, #8b5cf6, #a78bfa); border-radius: 4px 4px 0 0; height: 95%;"></div>
              </div>
              <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 12px; color: #64748b;">
                <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
              </div>
            </div>
            
            <!-- Card 4: Scale -->
            <div style="background: #ffffff; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1); padding: 24px;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="width: 40px; height: 40px; background: #fef3c7; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                </div>
                <h4 style="font-weight: 600; color: #0f172a;">Enterprise Scale</h4>
              </div>
              <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">
                <div style="text-align: center; padding: 16px; background: #f8fafc; border-radius: 12px;">
                  <div style="font-size: 24px; font-weight: 700; color: #0f172a;">99.9%</div>
                  <div style="font-size: 12px; color: #64748b;">Uptime SLA</div>
                </div>
                <div style="text-align: center; padding: 16px; background: #f8fafc; border-radius: 12px;">
                  <div style="font-size: 24px; font-weight: 700; color: #0f172a;">10M+</div>
                  <div style="font-size: 12px; color: #64748b;">API Calls/Day</div>
                </div>
                <div style="text-align: center; padding: 16px; background: #f8fafc; border-radius: 12px;">
                  <div style="font-size: 24px; font-weight: 700; color: #0f172a;">50+</div>
                  <div style="font-size: 12px; color: #64748b;">Regions</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 3: BENTO DASHBOARD (FinTech Style)
  // ============================================
  {
    id: 'section-bento-dashboard',
    name: 'Bento Dashboard',
    thumbnail: `<div style="width: 100%; height: 100%; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 8px; display: grid; grid-template-columns: 2fr 1fr; gap: 4px; padding: 8px;">
      <div style="background: rgba(59, 130, 246, 0.2); border-radius: 4px; border: 1px solid rgba(59, 130, 246, 0.3); grid-row: span 2;"></div>
      <div style="background: rgba(139, 92, 246, 0.2); border-radius: 4px; border: 1px solid rgba(139, 92, 246, 0.3);"></div>
      <div style="background: rgba(16, 185, 129, 0.2); border-radius: 4px; border: 1px solid rgba(16, 185, 129, 0.3);"></div>
    </div>`,
    content: `<section style="position: relative; padding: 96px 24px; overflow: hidden; background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);">
      <!-- Decorative elements -->
      <div style="position: absolute; top: 0; left: 0; width: 100%; height: 1px; background: linear-gradient(to right, transparent, rgba(100, 116, 139, 0.3), transparent);"></div>
      <div style="position: absolute; top: -160px; right: -160px; width: 320px; height: 320px; background: rgba(59, 130, 246, 0.1); border-radius: 50%; filter: blur(64px);"></div>
      <div style="position: absolute; bottom: -160px; left: -160px; width: 320px; height: 320px; background: rgba(139, 92, 246, 0.1); border-radius: 50%; filter: blur(64px);"></div>
      
      <div style="position: relative; z-index: 10; max-width: 1280px; margin: 0 auto;">
        <!-- Section Header -->
        <div style="text-align: center; margin-bottom: 64px;">
          <div style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 9999px; margin-bottom: 24px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
            <span style="font-size: 14px; color: #93c5fd; font-weight: 500;">All-in-One Platform</span>
          </div>
          <h2 style="font-size: 48px; font-weight: 700; letter-spacing: -0.025em; color: #ffffff; margin-bottom: 16px;">Everything You Need</h2>
          <p style="font-size: 18px; color: #94a3b8; max-width: 672px; margin: 0 auto;">A comprehensive suite of tools designed for modern teams.</p>
        </div>
        
        <!-- Bento Grid -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;">
          
          <!-- Large Card: Dashboard Preview (spans 2 cols, 2 rows) -->
          <div style="grid-column: span 2; grid-row: span 2; background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; padding: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
              <h3 style="font-size: 20px; font-weight: 600; color: #ffffff;">Dashboard Overview</h3>
              <div style="display: flex; align-items: center; gap: 8px; padding: 4px 12px; background: rgba(34, 197, 94, 0.2); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 9999px;">
                <span style="width: 8px; height: 8px; background: #4ade80; border-radius: 50%;"></span>
                <span style="font-size: 12px; color: #4ade80;">Live</span>
              </div>
            </div>
            
            <!-- Browser Window Mockup -->
            <div style="background: #0f172a; border-radius: 12px; overflow: hidden; border: 1px solid rgba(255, 255, 255, 0.1);">
              <!-- Window Controls -->
              <div style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                <div style="width: 12px; height: 12px; border-radius: 50%; background: #ef4444;"></div>
                <div style="width: 12px; height: 12px; border-radius: 50%; background: #eab308;"></div>
                <div style="width: 12px; height: 12px; border-radius: 50%; background: #22c55e;"></div>
              </div>
              
              <!-- Window Content -->
              <div style="padding: 16px;">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px;">
                  <div style="padding: 12px; background: rgba(30, 41, 59, 0.5); border-radius: 8px;">
                    <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">Revenue</div>
                    <div style="font-size: 18px; font-weight: 600; color: #ffffff;">$124.5K</div>
                    <div style="font-size: 12px; color: #4ade80;">+12.5%</div>
                  </div>
                  <div style="padding: 12px; background: rgba(30, 41, 59, 0.5); border-radius: 8px;">
                    <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">Users</div>
                    <div style="font-size: 18px; font-weight: 600; color: #ffffff;">8,432</div>
                    <div style="font-size: 12px; color: #4ade80;">+8.2%</div>
                  </div>
                  <div style="padding: 12px; background: rgba(30, 41, 59, 0.5); border-radius: 8px;">
                    <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">Conversion</div>
                    <div style="font-size: 18px; font-weight: 600; color: #ffffff;">3.24%</div>
                    <div style="font-size: 12px; color: #f87171;">-0.5%</div>
                  </div>
                </div>
                
                <!-- Mini Chart -->
                <div style="display: flex; align-items: flex-end; gap: 4px; height: 80px;">
                  <div style="flex: 1; background: rgba(59, 130, 246, 0.6); border-radius: 4px 4px 0 0; height: 50%;"></div>
                  <div style="flex: 1; background: rgba(59, 130, 246, 0.6); border-radius: 4px 4px 0 0; height: 70%;"></div>
                  <div style="flex: 1; background: rgba(59, 130, 246, 0.6); border-radius: 4px 4px 0 0; height: 45%;"></div>
                  <div style="flex: 1; background: rgba(59, 130, 246, 0.6); border-radius: 4px 4px 0 0; height: 80%;"></div>
                  <div style="flex: 1; background: rgba(59, 130, 246, 0.6); border-radius: 4px 4px 0 0; height: 60%;"></div>
                  <div style="flex: 1; background: rgba(59, 130, 246, 0.6); border-radius: 4px 4px 0 0; height: 90%;"></div>
                  <div style="flex: 1; background: rgba(59, 130, 246, 0.6); border-radius: 4px 4px 0 0; height: 75%;"></div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Card: Quick Actions -->
          <div style="background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; padding: 24px;">
            <h3 style="font-size: 18px; font-weight: 600; color: #ffffff; margin-bottom: 16px;">Quick Actions</h3>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
              <button style="padding: 12px; background: rgba(59, 130, 246, 0.2); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 12px; text-align: left; cursor: pointer;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" style="margin-bottom: 8px;"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                <div style="font-size: 12px; color: #cbd5e1;">New Project</div>
              </button>
              <button style="padding: 12px; background: rgba(139, 92, 246, 0.2); border: 1px solid rgba(139, 92, 246, 0.3); border-radius: 12px; text-align: left; cursor: pointer;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a78bfa" stroke-width="2" style="margin-bottom: 8px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <div style="font-size: 12px; color: #cbd5e1;">Add Member</div>
              </button>
              <button style="padding: 12px; background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 12px; text-align: left; cursor: pointer;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2" style="margin-bottom: 8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <div style="font-size: 12px; color: #cbd5e1;">Upload</div>
              </button>
              <button style="padding: 12px; background: rgba(245, 158, 11, 0.2); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 12px; text-align: left; cursor: pointer;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2" style="margin-bottom: 8px;"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>
                <div style="font-size: 12px; color: #cbd5e1;">Help</div>
              </button>
            </div>
          </div>
          
          <!-- Card: Notifications -->
          <div style="background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; padding: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
              <h3 style="font-size: 18px; font-weight: 600; color: #ffffff;">Notifications</h3>
              <span style="width: 24px; height: 24px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #ffffff; font-weight: 500;">3</span>
            </div>
            <div style="display: flex; flex-direction: column; gap: 12px;">
              <div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px; background: rgba(51, 65, 85, 0.3); border-radius: 12px;">
                <div style="width: 32px; height: 32px; background: rgba(59, 130, 246, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07"/></svg>
                </div>
                <div>
                  <div style="font-size: 12px; color: #ffffff;">New subscriber</div>
                  <div style="font-size: 12px; color: #64748b;">2 minutes ago</div>
                </div>
              </div>
              <div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px; background: rgba(51, 65, 85, 0.3); border-radius: 12px;">
                <div style="width: 32px; height: 32px; background: rgba(34, 197, 94, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2"><path d="m9 12 2 2 4-4"/></svg>
                </div>
                <div>
                  <div style="font-size: 12px; color: #ffffff;">Task completed</div>
                  <div style="font-size: 12px; color: #64748b;">1 hour ago</div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Card: Activity Feed (spans 2 cols) -->
          <div style="grid-column: span 2; background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; padding: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
              <h3 style="font-size: 18px; font-weight: 600; color: #ffffff;">Recent Activity</h3>
              <button style="font-size: 12px; color: #60a5fa; background: none; border: none; cursor: pointer;">View All</button>
            </div>
            <div style="display: flex; flex-direction: column; gap: 16px;">
              <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #8b5cf6); display: flex; align-items: center; justify-content: center; color: #ffffff; font-size: 14px; font-weight: 500;">JD</div>
                <div style="flex: 1;">
                  <div style="font-size: 14px; color: #ffffff;"><span style="font-weight: 500;">John Doe</span> created a new project</div>
                  <div style="font-size: 12px; color: #64748b;">Design System v2.0</div>
                </div>
                <div style="font-size: 12px; color: #64748b;">5m ago</div>
              </div>
              <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #14b8a6); display: flex; align-items: center; justify-content: center; color: #ffffff; font-size: 14px; font-weight: 500;">AS</div>
                <div style="flex: 1;">
                  <div style="font-size: 14px; color: #ffffff;"><span style="font-weight: 500;">Alice Smith</span> uploaded 12 files</div>
                  <div style="font-size: 12px; color: #64748b;">Marketing assets</div>
                </div>
                <div style="font-size: 12px; color: #64748b;">23m ago</div>
              </div>
              <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #f97316, #ef4444); display: flex; align-items: center; justify-content: center; color: #ffffff; font-size: 14px; font-weight: 500;">BW</div>
                <div style="flex: 1;">
                  <div style="font-size: 14px; color: #ffffff;"><span style="font-weight: 500;">Bob Wilson</span> commented on task</div>
                  <div style="font-size: 12px; color: #64748b;">API Integration</div>
                </div>
                <div style="font-size: 12px; color: #64748b;">1h ago</div>
              </div>
            </div>
          </div>
          
          <!-- Card: Storage -->
          <div style="background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; padding: 24px;">
            <h3 style="font-size: 18px; font-weight: 600; color: #ffffff; margin-bottom: 16px;">Storage</h3>
            <div style="margin-bottom: 16px;">
              <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 8px;">
                <span style="color: #94a3b8;">Used</span>
                <span style="color: #ffffff;">45.2 GB / 100 GB</span>
              </div>
              <div style="height: 8px; background: #334155; border-radius: 9999px; overflow: hidden;">
                <div style="height: 100%; background: linear-gradient(to right, #3b82f6, #06b6d4); border-radius: 9999px; width: 45%;"></div>
              </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 14px;">
              <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 8px;">
                  <div style="width: 12px; height: 12px; background: #3b82f6; border-radius: 2px;"></div>
                  <span style="color: #cbd5e1;">Documents</span>
                </div>
                <span style="color: #64748b;">18.2 GB</span>
              </div>
              <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 8px;">
                  <div style="width: 12px; height: 12px; background: #8b5cf6; border-radius: 2px;"></div>
                  <span style="color: #cbd5e1;">Media</span>
                </div>
                <span style="color: #64748b;">15.8 GB</span>
              </div>
              <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 8px;">
                  <div style="width: 12px; height: 12px; background: #06b6d4; border-radius: 2px;"></div>
                  <span style="color: #cbd5e1;">Other</span>
                </div>
                <span style="color: #64748b;">11.2 GB</span>
              </div>
            </div>
          </div>
          
          <!-- Card: Calendar -->
          <div style="background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; padding: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
              <h3 style="font-size: 18px; font-weight: 600; color: #ffffff;">Calendar</h3>
              <div style="font-size: 12px; color: #64748b;">December 2024</div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; font-size: 11px; margin-bottom: 12px;">
              <div style="color: #64748b; padding: 4px;">S</div>
              <div style="color: #64748b; padding: 4px;">M</div>
              <div style="color: #64748b; padding: 4px;">T</div>
              <div style="color: #64748b; padding: 4px;">W</div>
              <div style="color: #64748b; padding: 4px;">T</div>
              <div style="color: #64748b; padding: 4px;">F</div>
              <div style="color: #64748b; padding: 4px;">S</div>
              <div style="color: #475569; padding: 4px;">1</div>
              <div style="color: #475569; padding: 4px;">2</div>
              <div style="color: #475569; padding: 4px;">3</div>
              <div style="color: #475569; padding: 4px;">4</div>
              <div style="color: #475569; padding: 4px;">5</div>
              <div style="color: #475569; padding: 4px;">6</div>
              <div style="color: #475569; padding: 4px;">7</div>
              <div style="color: #475569; padding: 4px;">8</div>
              <div style="color: #475569; padding: 4px;">9</div>
              <div style="color: #475569; padding: 4px;">10</div>
              <div style="color: #475569; padding: 4px;">11</div>
              <div style="color: #475569; padding: 4px;">12</div>
              <div style="color: #475569; padding: 4px;">13</div>
              <div style="color: #475569; padding: 4px;">14</div>
              <div style="color: #475569; padding: 4px;">15</div>
              <div style="color: #475569; padding: 4px;">16</div>
              <div style="color: #475569; padding: 4px;">17</div>
              <div style="color: #475569; padding: 4px;">18</div>
              <div style="color: #475569; padding: 4px;">19</div>
              <div style="color: #475569; padding: 4px;">20</div>
              <div style="background: #3b82f6; color: #ffffff; border-radius: 50%; padding: 4px;">21</div>
            </div>
            <div style="padding: 12px; background: rgba(59, 130, 246, 0.2); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px;">
              <div style="font-size: 12px; color: #60a5fa; font-weight: 500;">Team Meeting</div>
              <div style="font-size: 12px; color: #94a3b8;">Today at 2:00 PM</div>
            </div>
          </div>
        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 4: INTERACTIVE ACCORDION (Light Mode)
  // ============================================
  {
    id: 'section-accordion',
    name: 'Interactive Accordion',
    thumbnail: `<div style="width: 100%; height: 100%; background: #f8fafc; border-radius: 8px; display: flex; gap: 8px; padding: 8px;">
      <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
        <div style="height: 12px; background: #e2e8f0; border-radius: 4px;"></div>
        <div style="height: 12px; background: #6366f1; border-radius: 4px;"></div>
        <div style="height: 12px; background: #e2e8f0; border-radius: 4px;"></div>
      </div>
      <div style="flex: 1; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px;"></div>
    </div>`,
    content: `<section style="position: relative; padding: 128px 24px; background: #f8fafc;">
      <div style="max-width: 1280px; margin: 0 auto;">
        <div style="display: flex; flex-direction: row; gap: 64px;">
          
          <!-- Left: Accordion -->
          <div style="flex: 1;">
            <div>
              <span style="display: inline-block; padding: 8px 16px; background: #e0e7ff; color: #4338ca; border-radius: 9999px; font-size: 14px; font-weight: 500; margin-bottom: 24px;">Interactive</span>
              <h2 style="font-size: 48px; font-weight: 700; letter-spacing: -0.025em; margin-bottom: 24px; color: #0f172a;">Explore Features</h2>
              <p style="font-size: 20px; color: #475569; margin-bottom: 40px;">Click on any feature to learn more about what makes our platform unique.</p>
            </div>
            
            <div style="display: flex; flex-direction: column;">
              
              <!-- Item 1 -->
              <div style="border-bottom: 1px solid #e2e8f0;">
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 24px 0; cursor: pointer;">
                  <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="width: 40px; height: 40px; background: #e0e7ff; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <div style="text-align: left;">
                      <h3 style="font-size: 18px; font-weight: 600; color: #0f172a;">Lightning Fast Performance</h3>
                      <p style="font-size: 14px; color: #64748b;">Click to expand</p>
                    </div>
                  </div>
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                </div>
                <div style="padding-bottom: 32px; padding-left: 56px; padding-right: 24px;">
                  <p style="color: #475569; margin-bottom: 16px;">Our platform is optimized for speed with edge caching, intelligent prefetching, and a globally distributed network that ensures sub-100ms response times anywhere in the world.</p>
                  <ul style="display: flex; flex-direction: column; gap: 8px; list-style: none; padding: 0; margin: 0;">
                    <li style="display: flex; align-items: center; gap: 8px; color: #475569;">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                      Edge caching in 200+ locations
                    </li>
                    <li style="display: flex; align-items: center; gap: 8px; color: #475569;">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                      Automatic image optimization
                    </li>
                    <li style="display: flex; align-items: center; gap: 8px; color: #475569;">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                      Code splitting & lazy loading
                    </li>
                  </ul>
                </div>
              </div>
              
              <!-- Item 2 -->
              <div style="border-bottom: 1px solid #e2e8f0;">
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 24px 0; cursor: pointer;">
                  <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="width: 40px; height: 40px; background: #d1fae5; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div style="text-align: left;">
                      <h3 style="font-size: 18px; font-weight: 600; color: #0f172a;">Enterprise-Grade Security</h3>
                      <p style="font-size: 14px; color: #64748b;">Click to expand</p>
                    </div>
                  </div>
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                </div>
              </div>
              
              <!-- Item 3 -->
              <div style="border-bottom: 1px solid #e2e8f0;">
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 24px 0; cursor: pointer;">
                  <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="width: 40px; height: 40px; background: #ede9fe; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18.7 8l-5.1 5.2-2.8-2.7L7 14.3"/></svg>
                    </div>
                    <div style="text-align: left;">
                      <h3 style="font-size: 18px; font-weight: 600; color: #0f172a;">Advanced Analytics</h3>
                      <p style="font-size: 14px; color: #64748b;">Click to expand</p>
                    </div>
                  </div>
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                </div>
              </div>
              
              <!-- Item 4 -->
              <div style="border-bottom: 1px solid #e2e8f0;">
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 24px 0; cursor: pointer;">
                  <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="width: 40px; height: 40px; background: #fef3c7; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div style="text-align: left;">
                      <h3 style="font-size: 18px; font-weight: 600; color: #0f172a;">Team Collaboration</h3>
                      <p style="font-size: 14px; color: #64748b;">Click to expand</p>
                    </div>
                  </div>
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                </div>
              </div>
              
            </div>
          </div>
          
          <!-- Right: Dynamic Preview -->
          <div style="flex: 1;">
            <div style="position: sticky; top: 128px;">
              <div style="background: #ffffff; border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); padding: 32px;">
                
                <!-- Preview Content -->
                <div style="width: 100%; height: 256px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 16px; margin-bottom: 24px; display: flex; align-items: center; justify-content: center;">
                  <svg width="96" height="96" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <h3 style="font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 12px;">Lightning Fast</h3>
                <p style="color: #475569;">Sub-100ms response times globally with our edge network.</p>
                <div style="margin-top: 24px; display: flex; align-items: center; gap: 16px;">
                  <div style="display: flex; margin-left: -8px;">
                    <div style="width: 32px; height: 32px; background: #c7d2fe; border-radius: 50%; border: 2px solid #ffffff;"></div>
                    <div style="width: 32px; height: 32px; background: #ddd6fe; border-radius: 50%; border: 2px solid #ffffff; margin-left: -8px;"></div>
                    <div style="width: 32px; height: 32px; background: #fbcfe8; border-radius: 50%; border: 2px solid #ffffff; margin-left: -8px;"></div>
                  </div>
                  <span style="font-size: 14px; color: #64748b;">2M+ requests/day</span>
                </div>
                
              </div>
            </div>
          </div>
          
        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 5: THE FEATURE DECK (Interactive Tabs)
  // ============================================
  {
    id: 'section-feature-deck',
    name: 'The Feature Deck',
    thumbnail: `<div style="width: 100%; height: 100%; background: #ffffff; border-radius: 8px; display: flex; flex-direction: column; padding: 8px; gap: 4px;">
      <div style="display: flex; justify-content: center; gap: 4px;">
        <div style="width: 20px; height: 8px; background: #0f172a; border-radius: 4px;"></div>
        <div style="width: 20px; height: 8px; background: #f1f5f9; border-radius: 4px;"></div>
        <div style="width: 20px; height: 8px; background: #f1f5f9; border-radius: 4px;"></div>
      </div>
      <div style="flex: 1; background: #0f172a; border-radius: 6px; display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px;">
        <div style="display: flex; flex-direction: column; gap: 2px;">
          <div style="width: 10px; height: 2px; background: #38bdf8;"></div>
          <div style="width: 100%; height: 4px; background: #ffffff;"></div>
          <div style="width: 80%; height: 2px; background: #94a3b8;"></div>
        </div>
        <div style="background: #1e293b; border-radius: 4px;"></div>
      </div>
    </div>`,
    content: `<section style="padding: 100px 20px; background: #fff; border-bottom: 1px solid #e2e8f0; font-family: 'Inter', sans-serif;">
      <div style="max-width: 1200px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 60px;">
          <h2 style="font-size: 40px; font-weight: 800; margin-bottom: 15px; letter-spacing: -1px; color: #0f172a;">Everything you need to ship.</h2>
          <p style="color: #64748b; font-size: 18px;">A unified platform for the modern web.</p>
        </div>

        <div style="display: flex; justify-content: center; gap: 10px; margin-bottom: 40px; flex-wrap: wrap;">
          <div style="padding: 12px 24px; border-radius: 50px; background: #0f172a; color: #fff; font-weight: 600; cursor: pointer; transition: 0.3s; box-shadow: 0 10px 20px -5px rgba(15, 23, 42, 0.2);">Analytics</div>
          <div style="padding: 12px 24px; border-radius: 50px; background: #f1f5f9; color: #64748b; font-weight: 600; cursor: pointer; transition: 0.3s;">Automation</div>
          <div style="padding: 12px 24px; border-radius: 50px; background: #f1f5f9; color: #64748b; font-weight: 600; cursor: pointer; transition: 0.3s;">Security</div>
        </div>

        <div style="background: #0f172a; border-radius: 24px; overflow: hidden; color: #fff; position: relative; min-height: 500px; display: grid; grid-template-columns: 1fr 1.2fr;">
          <div style="padding: 60px; display: flex; flex-direction: column; justify-content: center; z-index: 2;">
            <span style="color: #38bdf8; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 13px; margin-bottom: 15px; display: block;">Real-time Insights</span>
            <h3 style="font-size: 40px; margin-bottom: 20px; line-height: 1.1; font-family: 'Outfit', sans-serif;">Data that drives decisions.</h3>
            <p style="color: #94a3b8; line-height: 1.6; margin-bottom: 30px; font-size: 17px;">Monitor your application performance in real-time. Identify bottlenecks, track user journeys, and optimize conversion rates instantly.</p>
            <a href="#" style="color: #fff; font-weight: 600; text-decoration: none; border-bottom: 2px solid #38bdf8; width: fit-content; padding-bottom: 2px;">Explore Analytics &rarr;</a>
          </div>
          <div style="position: relative; background: #1e293b; overflow: hidden;">
            <div style="position: absolute; inset: 0; background: linear-gradient(90deg, #0f172a 0%, transparent 100%);"></div>
            <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?q=80&w=1000&auto=format&fit=crop" alt="Analytics" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.8; mix-blend-mode: overlay;">
          </div>
        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 6: THE BENCHMARK (Comparison)
  // ============================================
  {
    id: 'section-benchmark',
    name: 'The Benchmark',
    thumbnail: `<div style="width: 100%; height: 100%; background: #0b0b0f; border-radius: 8px; display: flex; flex-direction: column; padding: 8px; gap: 4px;">
      <div style="height: 10px; border-bottom: 1px solid #333; display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 2px;">
        <div></div><div style="background: #222;"></div><div style="background: #10b981; opacity: 0.3;"></div>
      </div>
      <div style="height: 8px; border-bottom: 1px solid #222; display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 2px;">
        <div style="background: #222;"></div><div style="background: #333;"></div><div style="background: #10b981; opacity: 0.2;"></div>
      </div>
      <div style="height: 8px; border-bottom: 1px solid #222; display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 2px;">
        <div style="background: #222;"></div><div style="background: #333;"></div><div style="background: #10b981; opacity: 0.2;"></div>
      </div>
    </div>`,
    content: `<section style="background: #0b0b0f; padding: 120px 20px; color: #fff; font-family: 'Inter', sans-serif;">
      <div style="max-width: 1000px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 60px;">
          <h2 style="font-size: 48px; font-weight: 700; margin-bottom: 20px;">Why choose <span style="color: #10b981;">Aerix</span>?</h2>
          <p style="color: #888; font-size: 18px;">See how we stack up against the legacy competition.</p>
        </div>
        
        <div style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; min-width: 600px;">
            <thead>
              <tr>
                <th style="padding: 20px; text-align: left; font-size: 19px; font-weight: 700; border-bottom: 1px solid #333; color: #888;">Feature</th>
                <th style="padding: 20px; text-align: center; font-size: 19px; font-weight: 700; border-bottom: 1px solid #333; color: #888;">Others</th>
                <th style="padding: 20px; text-align: center; font-size: 24px; font-weight: 700; border-bottom: 4px solid #10b981; background: rgba(16, 185, 129, 0.05); color: #fff;">Aerix</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td style="padding: 25px 0; border-bottom: 1px solid #222; font-weight: 600; color: #ccc;">Setup Time</td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle;">Weeks</td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle; background: rgba(16, 185, 129, 0.02); border-left: 1px solid #222; border-right: 1px solid #222;">Minutes</td>
              </tr>
              <tr>
                <td style="padding: 25px 0; border-bottom: 1px solid #222; font-weight: 600; color: #ccc;">Global CDN</td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle;">
                  <div style="color: #ef4444; width: 24px; height: 24px; margin: 0 auto; opacity: 0.5;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                  </div>
                </td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle; background: rgba(16, 185, 129, 0.02); border-left: 1px solid #222; border-right: 1px solid #222;">
                  <div style="color: #10b981; width: 24px; height: 24px; margin: 0 auto;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  </div>
                </td>
              </tr>
              <tr>
                <td style="padding: 25px 0; border-bottom: 1px solid #222; font-weight: 600; color: #ccc;">Uptime SLA</td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle;">99.0%</td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle; background: rgba(16, 185, 129, 0.02); border-left: 1px solid #222; border-right: 1px solid #222;">99.99%</td>
              </tr>
              <tr>
                <td style="padding: 25px 0; border-bottom: 1px solid #222; font-weight: 600; color: #ccc;">Support</td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle;">Email Only</td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle; background: rgba(16, 185, 129, 0.02); border-left: 1px solid #222; border-right: 1px solid #222;">24/7 Live Chat</td>
              </tr>
              <tr>
                <td style="padding: 25px 0; border-bottom: 1px solid #222; font-weight: 600; color: #ccc;">Auto-Scaling</td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle;"><span style="color: #555; font-weight: 700;">Add-on</span></td>
                <td style="text-align: center; border-bottom: 1px solid #222; padding: 20px; vertical-align: middle; background: rgba(16, 185, 129, 0.02); border-left: 1px solid #222; border-right: 1px solid #222;">
                  <div style="color: #10b981; width: 24px; height: 24px; margin: 0 auto;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 7: THE FLOW STATE (Zig Zag Steps)
  // ============================================
  {
    id: 'section-flow-state',
    name: 'The Flow State',
    thumbnail: `<div style="width: 100%; height: 100%; background: #ffffff; border-radius: 8px; display: flex; flex-direction: column; padding: 8px; gap: 8px; position: relative;">
      <div style="position: absolute; left: 50%; top: 0; bottom: 0; width: 1px; background: #f1f5f9; transform: translateX(-50%);"></div>
      <div style="display: flex; align-items: center; gap: 4px; z-index: 1;">
        <div style="flex: 1; height: 10px; background: #f1f5f9; border-radius: 2px;"></div>
        <div style="width: 30px; height: 20px; background: #3b82f6; opacity: 0.2; border-radius: 4px; transform: rotate(-2deg);"></div>
      </div>
      <div style="display: flex; flex-direction: row-reverse; align-items: center; gap: 4px; z-index: 1;">
        <div style="flex: 1; height: 10px; background: #f1f5f9; border-radius: 2px;"></div>
        <div style="width: 30px; height: 20px; background: #3b82f6; opacity: 0.2; border-radius: 4px; transform: rotate(2deg);"></div>
      </div>
    </div>`,
    content: `<section style="padding: 120px 20px; background: #fff; position: relative; overflow: hidden; font-family: 'Inter', sans-serif;">
      <div style="position: absolute; left: 50%; top: 0; bottom: 0; width: 2px; background: #f1f5f9; transform: translateX(-50%); z-index: 0;"></div>
      <div style="max-width: 1200px; margin: 0 auto; position: relative; z-index: 2;">
        
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 80px; margin-bottom: 100px;">
          <div style="flex: 1;">
            <span style="font-size: 13px; font-weight: 700; text-transform: uppercase; color: #3b82f6; background: #eff6ff; padding: 6px 12px; border-radius: 20px; display: inline-block; margin-bottom: 20px;">Phase 01</span>
            <h3 style="font-size: 35px; font-weight: 800; margin-bottom: 20px; line-height: 1.1; letter-spacing: -0.5px; color: #0f172a;">Connect your data sources.</h3>
            <p style="color: #64748b; font-size: 18px; line-height: 1.6;">Instantly sync with over 50+ integrations including Google Sheets, Airtable, and PostgreSQL. No coding required.</p>
          </div>
          <div style="flex: 1; position: relative;">
            <div style="background: #fff; box-shadow: 0 20px 50px -10px rgba(0,0,0,0.1); border-radius: 20px; overflow: hidden; border: 1px solid #f1f5f9; transform: rotate(-2deg);">
              <img src="https://images.unsplash.com/photo-1460925895917-afdab827c52f?q=80&w=800&auto=format&fit=crop" style="width: 100%; height: auto; display: block;" alt="Data">
            </div>
          </div>
        </div>

        <div style="display: flex; flex-direction: row-reverse; align-items: center; justify-content: space-between; gap: 80px; margin-bottom: 100px;">
          <div style="flex: 1;">
            <span style="font-size: 13px; font-weight: 700; text-transform: uppercase; color: #3b82f6; background: #eff6ff; padding: 6px 12px; border-radius: 20px; display: inline-block; margin-bottom: 20px;">Phase 02</span>
            <h3 style="font-size: 35px; font-weight: 800; margin-bottom: 20px; line-height: 1.1; letter-spacing: -0.5px; color: #0f172a;">Build your workflow.</h3>
            <p style="color: #64748b; font-size: 18px; line-height: 1.6;">Drag and drop logic blocks to create complex automation flows. Visualize your data path before you deploy.</p>
          </div>
          <div style="flex: 1; position: relative;">
            <div style="background: #fff; box-shadow: 0 20px 50px -10px rgba(0,0,0,0.1); border-radius: 20px; overflow: hidden; border: 1px solid #f1f5f9; transform: rotate(2deg);">
              <img src="https://images.unsplash.com/photo-1550751827-4bd374c3f58b?q=80&w=800&auto=format&fit=crop" style="width: 100%; height: auto; display: block;" alt="Workflow">
            </div>
          </div>
        </div>

        <div style="display: flex; align-items: center; justify-content: space-between; gap: 80px;">
          <div style="flex: 1;">
            <span style="font-size: 13px; font-weight: 700; text-transform: uppercase; color: #3b82f6; background: #eff6ff; padding: 6px 12px; border-radius: 20px; display: inline-block; margin-bottom: 20px;">Phase 03</span>
            <h3 style="font-size: 35px; font-weight: 800; margin-bottom: 20px; line-height: 1.1; letter-spacing: -0.5px; color: #0f172a;">Deploy and scale.</h3>
            <p style="color: #64748b; font-size: 18px; line-height: 1.6;">Push to production with a single click. Our serverless infrastructure handles the load so you can focus on growth.</p>
          </div>
          <div style="flex: 1; position: relative;">
            <div style="background: #fff; box-shadow: 0 20px 50px -10px rgba(0,0,0,0.1); border-radius: 20px; overflow: hidden; border: 1px solid #f1f5f9; transform: rotate(-2deg);">
              <img src="https://images.unsplash.com/photo-1451187580459-43490279c0fa?q=80&w=800&auto=format&fit=crop" style="width: 100%; height: auto; display: block;" alt="Scale">
            </div>
          </div>
        </div>

      </div>
    </section>`
  },


  // ============================================
  // PRESET 8: THE SYNAPSE (Integration Hub)
  // ============================================
  {
    id: 'section-synapse',
    name: 'The Synapse',
    thumbnail: `<div style="width: 100%; height: 100%; background: radial-gradient(circle, #f8fafc 0%, #e2e8f0 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden;">
      <div style="width: 40px; height: 40px; background: #0f172a; border-radius: 12px; z-index: 2;"></div>
      <div style="position: absolute; width: 80px; height: 80px; border: 1px dashed #cbd5e1; border-radius: 50%;"></div>
      <div style="position: absolute; top: 10px; right: 10px; width: 12px; height: 12px; background: #fff; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"></div>
      <div style="position: absolute; bottom: 10px; left: 10px; width: 12px; height: 12px; background: #fff; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"></div>
    </div>`,
    content: `<section style="padding: 140px 20px; background: radial-gradient(circle at center, #f8fafc 0%, #e2e8f0 100%); font-family: 'Space Grotesk', sans-serif; overflow: hidden; position: relative;">
      <style>
        @keyframes spin-synapse { from { transform: translate(-50%, -50%) rotate(0deg); } to { transform: translate(-50%, -50%) rotate(360deg); } }
        @keyframes spin-synapse-rev { from { transform: translate(-50%, -50%) rotate(0deg); } to { transform: translate(-50%, -50%) rotate(-360deg); } }
      </style>
      
      <div style="max-width: 1000px; margin: 0 auto; text-align: center; position: relative; min-height: 500px; display: flex; align-items: center; justify-content: center;">
        <!-- Floating Orbits -->
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 800px; height: 800px; border: 1px dashed #cbd5e1; border-radius: 50%; z-index: 1; animation: spin-synapse 60s linear infinite;">
          <div style="width: 60px; height: 60px; background: #fff; border-radius: 50%; display: grid; place-items: center; position: absolute; top: -30px; left: 50%; transform: translateX(-50%); box-shadow: 0 10px 20px rgba(0,0,0,0.05); color: #475569;">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
          </div>
          <div style="width: 60px; height: 60px; background: #fff; border-radius: 50%; display: grid; place-items: center; position: absolute; bottom: -30px; left: 50%; transform: translateX(-50%); box-shadow: 0 10px 20px rgba(0,0,0,0.05); color: #475569;">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle></svg>
          </div>
        </div>

        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 500px; height: 500px; border: 1px dashed #cbd5e1; border-radius: 50%; z-index: 1; animation: spin-synapse-rev 40s linear infinite;">
          <div style="width: 60px; height: 60px; background: #fff; border-radius: 50%; display: grid; place-items: center; position: absolute; top: 10%; right: 10%; box-shadow: 0 10px 20px rgba(0,0,0,0.05); color: #475569;">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path><polyline points="16 6 12 2 8 6"></polyline><line x1="12" y1="2" x2="12" y2="15"></line></svg>
          </div>
          <div style="width: 60px; height: 60px; background: #fff; border-radius: 50%; display: grid; place-items: center; position: absolute; bottom: 10%; left: 10%; box-shadow: 0 10px 20px rgba(0,0,0,0.05); color: #475569;">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          </div>
        </div>

        <!-- Center Piece -->
        <div style="position: relative; z-index: 10; max-width: 600px;">
          <div style="width: 100px; height: 100px; background: #0f172a; color: #fff; border-radius: 30px; margin: 0 auto 30px; display: grid; place-items: center; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);">
            <svg style="width:40px; height:40px;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
          </div>
          <h2 style="font-size: 48px; font-weight: 700; margin-bottom: 20px; letter-spacing: -1px; color: #0f172a;">Plays well with others.</h2>
          <p style="color: #64748b; font-size: 18px;">Connect Aerix to the tools you already use. Over 500+ native integrations available.</p>
        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 9: SUPERCHARGE WORKFLOW (Grid Cards)
  // ============================================
  {
    id: 'section-supercharge',
    name: 'Supercharge Workflow',
    thumbnail: `<div style="width: 100%; height: 100%; background: #ffffff; border-radius: 8px; display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 8px;">
      <div style="background: #FFF6E9; border-radius: 4px; display: flex; flex-direction: column; padding: 4px; gap: 2px;">
        <div style="width: 10px; height: 4px; background: #fff; border-radius: 2px;"></div>
        <div style="width: 100%; height: 6px; background: #000; opacity: 0.1;"></div>
      </div>
      <div style="background: #EAF6FF; border-radius: 4px; display: flex; flex-direction: column; padding: 4px; gap: 2px;">
        <div style="width: 10px; height: 4px; background: #fff; border-radius: 2px;"></div>
        <div style="width: 100%; height: 6px; background: #000; opacity: 0.1;"></div>
      </div>
      <div style="background: #F6F0FF; border-radius: 4px; display: flex; flex-direction: column; padding: 4px; gap: 2px;">
        <div style="width: 10px; height: 4px; background: #fff; border-radius: 2px;"></div>
        <div style="width: 100%; height: 6px; background: #000; opacity: 0.1;"></div>
      </div>
      <div style="background: #F0FDF4; border-radius: 4px; display: flex; flex-direction: column; padding: 4px; gap: 2px;">
        <div style="width: 10px; height: 4px; background: #fff; border-radius: 2px;"></div>
        <div style="width: 100%; height: 6px; background: #000; opacity: 0.1;"></div>
      </div>
    </div>`,
    content: `<section style="padding: 80px 20px; background: #fff; font-family: 'Inter', sans-serif;">
      <div style="max-width: 1200px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 60px;">
          <h2 style="font-size: 40px; font-weight: 700; color: #111; letter-spacing: -1px;">Supercharge Your Workflow</h2>
        </div>

        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 30px;">
          
          <!-- Card 1: Orange -->
          <div style="background: #FFF6E9; border-radius: 24px; padding: 40px; position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: flex-start; min-height: 380px;">
            <div style="position: absolute; top: 0; right: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1;">
              <div style="position: absolute; right: -50px; top: 50px; width: 250px; height: 250px; background: #FDEBD2; border-radius: 40px; transform: rotate(45deg); opacity: 0.6;"></div>
              <div style="position: absolute; right: 80px; top: 150px; width: 150px; height: 150px; background: #FCE0C5; border-radius: 30px; transform: rotate(45deg); opacity: 0.5;"></div>
            </div>
            <div style="position: relative; z-index: 2; height: 100%; display: flex; flex-direction: column;">
              <span style="background: #fff; padding: 8px 16px; border-radius: 50px; font-size: 12px; font-weight: 600; color: #333; margin-bottom: 24px; display: inline-block; box-shadow: 0 2px 5px rgba(0,0,0,0.05); width: fit-content;">Automate Tasks</span>
              <h3 style="font-size: 24px; font-weight: 700; line-height: 1.2; color: #111; margin-bottom: 16px;">Save Hours with AI-Powered Automation</h3>
              <p style="font-size: 16px; line-height: 1.6; color: #555; margin-bottom: 30px; flex-grow: 1;">Reduce manual work with smart automation that streamlines repetitive tasks, freeing up time for what matters most.</p>
              <a href="#" style="text-decoration: none; color: #111; font-weight: 600; font-size: 15px; border-bottom: 2px solid #111; padding-bottom: 2px; display: inline-flex; align-items: center; gap: 6px;">Get Started <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
            </div>
          </div>

          <!-- Card 2: Blue -->
          <div style="background: #EAF6FF; border-radius: 24px; padding: 40px; position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: flex-start; min-height: 380px;">
            <div style="position: absolute; top: 0; right: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1;">
              <div style="position: absolute; right: -20px; top: 20px; width: 200px; height: 200px; background: #CFE5FF; border-radius: 50%; opacity: 0.7;"></div>
              <div style="position: absolute; right: 80px; bottom: -50px; width: 220px; height: 220px; background: #DDF0FF; border-radius: 0 0 100px 100px; transform: rotate(45deg); opacity: 0.6;"></div>
            </div>
            <div style="position: relative; z-index: 2; height: 100%; display: flex; flex-direction: column;">
              <span style="background: #fff; padding: 8px 16px; border-radius: 50px; font-size: 12px; font-weight: 600; color: #333; margin-bottom: 24px; display: inline-block; box-shadow: 0 2px 5px rgba(0,0,0,0.05); width: fit-content;">Real-Time Collaboration</span>
              <h3 style="font-size: 24px; font-weight: 700; line-height: 1.2; color: #111; margin-bottom: 16px;">Work Seamlessly with Your Team</h3>
              <p style="font-size: 16px; line-height: 1.6; color: #555; margin-bottom: 30px; flex-grow: 1;">Bring your team together with live collaboration, instant feedback, and version control—no more messy email threads.</p>
              <a href="#" style="text-decoration: none; color: #111; font-weight: 600; font-size: 15px; border-bottom: 2 solid #111; padding-bottom: 2px; display: inline-flex; align-items: center; gap: 6px;">Try It Now <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
            </div>
          </div>

          <!-- Card 3: Purple -->
          <div style="background: #F6F0FF; border-radius: 24px; padding: 40px; position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: flex-start; min-height: 380px;">
            <div style="position: absolute; top: 0; right: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1;">
              <div style="position: absolute; right: 0; bottom: 0; width: 250px; height: 250px; background: linear-gradient(135deg, #EAD6FF, transparent); clip-path: polygon(100% 0, 0% 100%, 100% 100%); opacity: 0.6;"></div>
              <div style="position: absolute; right: 50px; bottom: 50px; width: 150px; height: 150px; background: #DEC0FF; border-radius: 50%; filter: blur(40px); opacity: 0.4;"></div>
            </div>
            <div style="position: relative; z-index: 2; height: 100%; display: flex; flex-direction: column;">
              <span style="background: #fff; padding: 8px 16px; border-radius: 50px; font-size: 12px; font-weight: 600; color: #333; margin-bottom: 24px; display: inline-block; box-shadow: 0 2px 5px rgba(0,0,0,0.05); width: fit-content;">Powerful Integrations</span>
              <h3 style="font-size: 24px; font-weight: 700; line-height: 1.2; color: #111; margin-bottom: 16px;">Connect with Your Favorite Tools</h3>
              <p style="font-size: 16px; line-height: 1.6; color: #555; margin-bottom: 30px; flex-grow: 1;">Integrate with Slack, Notion, Zapier, and more to keep your workflow efficient and connected across all platforms.</p>
              <a href="#" style="text-decoration: none; color: #111; font-weight: 600; font-size: 15px; border-bottom: 2 solid #111; padding-bottom: 2px; display: inline-flex; align-items: center; gap: 6px;">Explore Integrations <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
            </div>
          </div>

          <!-- Card 4: Green -->
          <div style="background: #F0FDF4; border-radius: 24px; padding: 40px; position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: flex-start; min-height: 380px;">
            <div style="position: absolute; top: 0; right: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1;">
              <div style="position: absolute; right: -40px; bottom: 20px; width: 200px; height: 200px; background: #DCFCE7; border-radius: 40px; transform: rotate(15deg); opacity: 0.8;"></div>
              <div style="position: absolute; right: 60px; bottom: 120px; width: 120px; height: 120px; background: #BBF7D0; border-radius: 50%; opacity: 0.5; filter: blur(10px);"></div>
            </div>
            <div style="position: relative; z-index: 2; height: 100%; display: flex; flex-direction: column;">
              <span style="background: #fff; padding: 8px 16px; border-radius: 50px; font-size: 12px; font-weight: 600; color: #333; margin-bottom: 24px; display: inline-block; box-shadow: 0 2px 5px rgba(0,0,0,0.05); width: fit-content;">Advanced Analytics</span>
              <h3 style="font-size: 24px; font-weight: 700; line-height: 1.2; color: #111; margin-bottom: 16px;">Make Data-Driven Decisions</h3>
              <p style="font-size: 16px; line-height: 1.6; color: #555; margin-bottom: 30px; flex-grow: 1;">Gain insights with real-time analytics and reports, helping you optimize performance and drive better results.</p>
              <a href="#" style="text-decoration: none; color: #111; font-weight: 600; font-size: 15px; border-bottom: 2 solid #111; padding-bottom: 2px; display: inline-flex; align-items: center; gap: 6px;">View Insights <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
            </div>
          </div>

        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 10: FUTURE OF WORK (Mixed Grid)
  // ============================================
  {
    id: 'section-future-work',
    name: 'Future of Work',
    thumbnail: `<div style="width: 100%; height: 100%; background: #ffffff; border-radius: 8px; display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 4px; padding: 8px;">
      <div style="background: linear-gradient(135deg, #1a1a1a, #000); border-radius: 6px; position: relative; overflow: hidden;">
        <div style="position: absolute; top: -10px; right: -10px; width: 30px; height: 30px; background: radial-gradient(circle, #d4a373, transparent); opacity: 0.4; filter: blur(10px);"></div>
      </div>
      <div style="background: #f8f9fa; border-radius: 6px; border: 1px solid #eee;"></div>
      <div style="background: #f8f9fa; border-radius: 6px; border: 1px solid #eee;"></div>
    </div>`,
    content: `<section style="padding: 80px 20px; background: #fff; font-family: 'Inter', sans-serif;">
      <div style="max-width: 1300px; margin: 0 auto;">
        <div style="max-width: 700px; margin-bottom: 80px;">
          <h2 style="font-size: 56px; font-weight: 600; line-height: 1.1; margin-bottom: 24px; letter-spacing: -1.5px; color: #111;">The Co-Operating System™ for the Future of Work.</h2>
          <p style="font-size: 18px; line-height: 1.6; color: #555;">Turn your people operations data into precise feedback, upskilling, and support your people need to do their best work.</p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px;">
          
          <!-- Card 1: Dark -->
          <div style="background: linear-gradient(135deg, #1a1a1a 0%, #000 100%); border-radius: 24px; padding: 40px; color: #fff; position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; min-height: 500px;">
            <div style="position: absolute; top: -50px; right: -50px; width: 300px; height: 300px; background: radial-gradient(circle, #d4a373 0%, transparent 70%); opacity: 0.4; filter: blur(60px); pointer-events: none;"></div>
            <div style="position: relative; z-index: 2;">
              <h3 style="font-size: 22px; font-weight: 600; margin-bottom: 12px;">AI-Empowered Analytics</h3>
              <p style="font-size: 15px; color: rgba(255,255,255,0.7); line-height: 1.5;">Unlock insight into your operational efficiencies and talent development opportunities.</p>
            </div>
            
            <div style="background: rgba(255,255,255,0.1); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.15); border-radius: 16px; padding: 24px; margin-top: 40px; position: relative; z-index: 2;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                <div style="width: 32px; height: 32px; background: #fff; border-radius: 8px; display: grid; place-items: center; color: #000;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                </div>
                <div style="font-size: 13px; color: rgba(255,255,255,0.8);">MatterMore AI<br>Here's how your team did this week:</div>
              </div>
              
              <div style="display: flex; align-items: baseline; gap: 10px; margin-bottom: 16px;">
                <span style="font-size: 28px; font-weight: 600;">↑ 82%</span>
                <span style="font-size: 13px; color: rgba(255,255,255,0.6);">Team Engagement</span>
              </div>
              <div style="display: flex; align-items: baseline; gap: 10px; margin-bottom: 16px;">
                <span style="font-size: 28px; font-weight: 600;">↑ 62%</span>
                <span style="font-size: 13px; color: rgba(255,255,255,0.6);">Recognition Delivery</span>
              </div>
              <div style="display: flex; align-items: baseline; gap: 10px;">
                <span style="font-size: 28px; font-weight: 600; opacity: 0.8;">+ 2%</span>
                <span style="font-size: 13px; color: rgba(255,255,255,0.6);">Leadership Trust Index</span>
              </div>
            </div>
          </div>

          <!-- Card 2: Light -->
          <div style="background: #f8f9fa; border-radius: 24px; padding: 40px; border: 1px solid #f0f0f0; display: flex; flex-direction: column; justify-content: flex-start;">
            <h3 style="font-size: 22px; font-weight: 600; margin-bottom: 12px; color: #111;">Leadership Training</h3>
            <p style="font-size: 15px; color: #666; line-height: 1.5; margin-bottom: 40px;">Skill development that's human-led, AI assisted, and driven by advanced behavior science.</p>
            
            <div style="position: relative; min-height: 200px;">
              <div style="background: #fff; border: 1px solid #e5e5e5; padding: 8px 16px; border-radius: 50px; font-size: 13px; font-weight: 500; color: #333; position: absolute; top: 0; right: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.03);">Manager Effectiveness</div>
              <div style="background: #e6fcf5; color: #0ca678; border: 1px solid #c3fae8; padding: 8px 16px; border-radius: 50px; font-size: 13px; font-weight: 500; position: absolute; top: 40px; left: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.03);">Leadership Style</div>
              <div style="background: #e3fafc; color: #1098ad; border: 1px solid #c5f6fa; padding: 8px 16px; border-radius: 50px; font-size: 13px; font-weight: 500; position: absolute; top: 90px; right: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.03);">Goals</div>
              <div style="background: #fff; border: 1px solid #e5e5e5; padding: 8px 16px; border-radius: 50px; font-size: 13px; font-weight: 500; color: #333; position: absolute; bottom: 20px; left: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.03);">Preferred Approach</div>
              <div style="background: #fff; border: 1px solid #e5e5e5; padding: 8px 16px; border-radius: 50px; font-size: 13px; font-weight: 600; color: #333; position: absolute; bottom: 60px; right: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.03);">Core Values</div>
            </div>
            
            <div style="background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #eee; margin-top: auto;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="width: 32px; height: 32px; background: #111; border-radius: 8px; display: grid; place-items: center; color: #fff;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zM12 14c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"></path></svg>
                </div>
                <span style="font-weight: 600; font-size: 14px;">MatterMore AI</span>
              </div>
              <p style="font-size: 13px; color: #555;">How do you hope your team will describe you 6 months from now as a leader?</p>
            </div>
          </div>

          <!-- Card 3: Light (Operations) -->
          <div style="background: #f8f9fa; border-radius: 24px; padding: 40px; border: 1px solid #f0f0f0; display: flex; flex-direction: column; justify-content: flex-start;">
            <h3 style="font-size: 22px; font-weight: 600; margin-bottom: 12px; color: #111;">Regenerative Operations</h3>
            <p style="font-size: 15px; color: #666; line-height: 1.5; margin-bottom: 40px;">Upgrade systems for collaboration, onboarding, and management to drive results and employee well-being.</p>
            
            <div style="background: #fff; border-radius: 12px; padding: 16px; margin-bottom: 30px; border: 1px solid #eee; font-size: 13px; color: #555;">
              <span style="color: #0ca678; font-weight: 600;">@MatterMoreAI</span> Remind me what was covered in our last month's Manager Training?
            </div>

            <div style="background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #eee;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="width: 32px; height: 32px; background: #111; border-radius: 8px; display: grid; place-items: center; color: #fff;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.674M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>
                </div>
                <span style="font-weight: 600; font-size: 14px;">MatterMore AI</span>
              </div>
              <p style="margin-bottom: 10px; font-weight: 600; font-size: 14px; color: #111;">How to give better feedback</p>
              <div style="height: 8px; background: #f0f0f0; border-radius: 4px; margin-bottom: 8px; width: 100%;"></div>
              <div style="height: 8px; background: #f0f0f0; border-radius: 4px; margin-bottom: 8px; width: 100%;"></div>
              <div style="height: 8px; background: #f0f0f0; border-radius: 4px; margin-bottom: 8px; width: 60%;"></div>
              
              <div style="margin-top: 20px; font-size: 12px; color: #888;">Interview Compliance & Bias</div>
            </div>
          </div>

        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 11: DARK CYCLE MANAGEMENT (Animated)
  // ============================================
  {
    id: 'section-dark-cycle',
    name: 'Dark Cycle Management',
    thumbnail: `<div style="width: 100%; height: 100%; background: #020408; border-radius: 8px; display: flex; align-items: center; justify-content: center; padding: 8px; position: relative; overflow: hidden;">
      <div style="position: absolute; top: 10%; right: -10%; width: 50%; height: 50%; background: #0d9488; opacity: 0.3; filter: blur(15px); border-radius: 50%;"></div>
      <div style="width: 100%; height: 100%; background: rgba(15, 20, 28, 0.8); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 6px; display: flex; flex-direction: column; gap: 4px;">
        <div style="width: 20px; height: 2px; background: #fff; opacity: 0.5;"></div>
        <div style="width: 100%; height: 20px; border-bottom: 1px solid rgba(255,255,255,0.05);"></div>
        <div style="width: 100%; height: 10px; background: #2dd4bf; opacity: 0.2;"></div>
      </div>
    </div>`,
    content: `<section style="position: relative; padding: 100px 20px; background: #020408; overflow: hidden; font-family: 'Inter', sans-serif; color: #fff;">
      <style>
        @keyframes float-dc {
          0% { transform: translate(0, 0) scale(1); }
          100% { transform: translate(50px, 50px) scale(1.05); }
        }
        @keyframes drawLine-dc {
          to { stroke-dashoffset: 0; }
        }
        @keyframes fadeIn-dc {
          from { opacity: 0; }
          to { opacity: 1; }
        }
        @keyframes fadeUp-dc {
          from { opacity: 0; transform: translateY(30px); }
          to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulse-dc {
          0% { transform: scale(1); opacity: 1; }
          100% { transform: scale(1.3); opacity: 0; }
        }
        .dc-blob-1 { animation: float-dc 20s ease-in-out infinite alternate; }
        .dc-blob-2 { animation: float-dc 25s ease-in-out infinite alternate-reverse; }
        .db-chart-line { 
          stroke-dasharray: 1000; stroke-dashoffset: 1000; 
          animation: drawLine-dc 2.5s ease-out forwards 0.3s;
        }
        .db-chart-area { animation: fadeIn-dc 1s ease-out forwards 1.5s; opacity: 0; }
        .dc-dashboard { animation: fadeUp-dc 1s ease-out backwards; }
        .db-status-pulse::before {
          content: ''; position: absolute; inset: -2px; border-radius: 20px; border: 2px solid #f59e0b;
          animation: pulse-dc 2s cubic-bezier(0, 0, 0.2, 1) infinite;
        }
      </style>

      <!-- Background Blobs -->
      <div style="position: absolute; inset: 0; pointer-events: none; z-index: 0;">
        <div class="dc-blob-1" style="position: absolute; top: 20%; right: -10%; width: 600px; height: 600px; background: #0d9488; border-radius: 50%; filter: blur(120px); opacity: 0.5;"></div>
        <div class="dc-blob-2" style="position: absolute; bottom: -20%; left: 10%; width: 800px; height: 800px; background: #1e3a8a; border-radius: 50%; filter: blur(120px); opacity: 0.5;"></div>
      </div>

      <div style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1.2fr; gap: 80px; align-items: center; position: relative; z-index: 2;">
        
        <!-- Left Content -->
        <div style="max-width: 500px;">
          <div style="display: flex; gap: 6px; margin-bottom: 25px; color: #d97706;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="m12 1 3.22 8.22L23 12.44l-7.24 3.22L12.54 23l-3.22-8.22L1.87 11.56l7.24-3.22L12 1z"/></svg>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="m12 1 3.22 8.22L23 12.44l-7.24 3.22L12.54 23l-3.22-8.22L1.87 11.56l7.24-3.22L12 1z"/></svg>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="m12 1 3.22 8.22L23 12.44l-7.24 3.22L12.54 23l-3.22-8.22L1.87 11.56l7.24-3.22L12 1z"/></svg>
          </div>
          <h2 style="font-size: 56px; font-weight: 700; line-height: 1.1; margin-bottom: 24px; letter-spacing: -1.5px; background: linear-gradient(180deg, #ffffff 0%, #cce6ff 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Manage customers across the Lifetime of the product cycle</h2>
          <p style="font-size: 18px; line-height: 1.6; color: #9ca3af; margin-bottom: 50px;">Build rich, unified profiles with purchase history, preferences, support interactions, and more so you can personalize experiences and boost retention at every touchpoint.</p>
          
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 50px;">
            <div>
              <h4 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 12px; color: #fff; display: flex; align-items: center; gap: 10px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Historical Data
              </h4>
              <p style="font-size: 0.95rem; color: #6b7280; line-height: 1.5;">See customers purchase patterns across product cycles to predict future needs.</p>
            </div>
            <div>
              <h4 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 12px; color: #fff; display: flex; align-items: center; gap: 10px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
                Leverage Trends
              </h4>
              <p style="font-size: 0.95rem; color: #6b7280; line-height: 1.5;">Use real time analytics to suggest upgrades and filter asset funnels effectively.</p>
            </div>
          </div>

          <a href="#" style="display: inline-block; padding: 16px 32px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff; text-decoration: none; border-radius: 50px; font-size: 1rem; font-weight: 600; transition: all 0.3s ease; backdrop-filter: blur(5px);">Learn More</a>
        </div>

        <!-- Right Dashboard -->
        <div class="dc-dashboard" style="background: rgba(15, 20, 28, 0.8); border: 1px solid rgba(255, 255, 255, 0.08); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-radius: 24px; padding: 36px; box-shadow: 0 50px 100px -20px rgba(0,0,0,0.5); position: relative;">
          <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 32px;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #222; border: 2px solid rgba(255,255,255,0.1); overflow: hidden;">
              <img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=150&auto=format&fit=crop" alt="User" style="width: 100%; height: 100%; object-fit: cover;">
            </div>
            <div>
              <h5 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 4px; color: #fff;">Roberta Pouros</h5>
              <span style="font-size: 0.85rem; color: #6b7280; display: flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Los Angeles, California
              </span>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; padding-bottom: 24px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 24px;">
            <div>
              <span style="font-size: 0.8rem; color: #6b7280; margin-bottom: 8px; display: block; font-weight: 500;">Email</span>
              <span style="font-size: 1rem; color: #e5e7eb; font-weight: 500;">roberta@example.com</span>
            </div>
            <div>
              <span style="font-size: 0.8rem; color: #6b7280; margin-bottom: 8px; display: block; font-weight: 500;">Phone</span>
              <span style="font-size: 1rem; color: #e5e7eb; font-weight: 500;">+1 (515) 464-6864</span>
            </div>
            <div style="grid-column: span 2; margin-top: 8px;">
              <span style="font-size: 0.8rem; color: #6b7280; margin-bottom: 8px; display: block; font-weight: 500;">Total Lifetime Value</span>
              <span style="font-size: 1rem; color: #e5e7eb; font-weight: 500;">$1,524.00 
                <span style="color: #34d399; font-weight: 600; font-size: 0.85rem; margin-left: 8px; inline-flex: ; align-items: center; gap: 4px;">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="m7 7 10 10"/><path d="M17 7v10H7"/></svg>
                  3.25%
                </span>
              </span>
            </div>
          </div>

          <div style="margin-bottom: 30px; position: relative; height: 120px;">
            <div class="db-chart-area" style="position: absolute; bottom: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to top, rgba(45, 212, 191, 0.15), transparent); clip-path: polygon(0 100%, 100% 100%, 100% 30%, 80% 50%, 60% 40%, 40% 60%, 20% 50%, 0 70%);"></div>
            <div style="width: 100%; height: 100%;">
              <svg viewBox="0 0 300 100" preserveAspectRatio="none" style="width: 100%; height: 100%; overflow: visible;">
                <path class="db-chart-line" d="M0,70 Q30,60 60,80 Q90,30 120,50 Q150,20 180,40 Q210,10 240,30 Q270,5 300,20" fill="none" stroke="#2dd4bf" stroke-width="3" stroke-linecap="round" style="vector-effect: non-scaling-stroke; filter: drop-shadow(0 4px 6px rgba(45, 212, 191, 0.3));" />
              </svg>
            </div>
          </div>
          <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #555; margin-bottom: 36px;">
            <span>Jan 1, 2025</span>
            <span>Live View</span>
          </div>

          <div style="background: #0f1218; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
            <div style="display: flex; align-items: center; gap: 12px;">
              <span style="color:#fff; font-weight:600;">Order #45678</span>
              <span class="db-status-pulse" style="color: #f59e0b; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; position: relative;">Active</span>
            </div>
            <div style="display: flex; gap: 12px;">
              <div style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 16px; border-radius: 30px; color: #6b7280; font-weight: 600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
                Archive
              </div>
              <div style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 16px; border-radius: 30px; color: #6b7280; font-weight: 600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Escalate
              </div>
              <div style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 16px; border-radius: 30px; background: #2dd4bf; color: #000; font-weight: 600; box-shadow: 0 4px 12px rgba(45, 212, 191, 0.3);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/><polyline points="20 12 9 23 4 18"/></svg>
                Resolve
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>`
  },


  // ============================================
  // PRESET 12: THE CENTRAL INTELLIGENCE (Dark)
  // ============================================
  {
    id: 'section-central-intel',
    name: 'The Central Intelligence',
    thumbnail: `<div style="width: 100%; height: 100%; background: #0B0F19; border-radius: 8px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 4px; padding: 8px; align-items: center;">
      <div style="display: flex; flex-direction: column; gap: 4px;">
        <div style="height: 15px; background: rgba(255,255,255,0.05); border-radius: 2px;"></div>
        <div style="height: 15px; background: rgba(255,255,255,0.05); border-radius: 2px;"></div>
      </div>
      <div style="height: 60px; background: #000; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);"></div>
      <div style="display: flex; flex-direction: column; gap: 4px;">
        <div style="height: 15px; background: rgba(255,255,255,0.05); border-radius: 2px;"></div>
        <div style="height: 15px; background: rgba(255,255,255,0.05); border-radius: 2px;"></div>
      </div>
    </div>`,
    content: `<section style="background: #0B0F19; padding: 120px 20px; color: #fff; position: relative; overflow: hidden; font-family: 'Inter', sans-serif;">
      <style>
        .s18-line { fill: none; stroke: url(#lineGradient); stroke-width: 2; stroke-linecap: round; opacity: 0.6; }
      </style>
      <div style="position: absolute; inset: 0; opacity: 0.05; background-image: linear-gradient(rgba(255, 255, 255, 0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.1) 1px, transparent 1px); background-size: 20px 20px; pointer-events: none;"></div>
      
      <div style="max-width: 1200px; margin: 0 auto; position: relative; z-index: 2;">
        <div style="text-align: center; margin-bottom: 80px; max-width: 700px; margin-left: auto; margin-right: auto;">
          <h2 style="font-size: 48px; font-weight: 800; letter-spacing: -1px; margin-bottom: 20px; font-family: 'Outfit', sans-serif;">The central hub for your digital life.</h2>
          <p style="font-size: 18px; color: #94a3b8; line-height: 1.6;">Connect all your tools, manage your data, and automate your workflows from a single, powerful interface.</p>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 340px 1fr; gap: 40px; align-items: center; position: relative;">
          
          <!-- Left Column -->
          <div style="display: flex; flex-direction: column; gap: 30px;">
            <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); padding: 30px; border-radius: 20px;">
              <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #3b82f6, #9333ea); border-radius: 12px; display: grid; place-items: center; color: #fff; margin-bottom: 15px;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/></svg>
              </div>
              <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 8px; color: #3b82f6;">Smart Categorization</h3>
              <p style="font-size: 0.9rem; color: #94a3b8; line-height: 1.5;">AI automatically organizes your incoming data into structured formats.</p>
            </div>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); padding: 30px; border-radius: 20px;">
              <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.1); border-radius: 12px; display: grid; place-items: center; color: #94a3b8; margin-bottom: 15px;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              </div>
              <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 8px;">Instant Sync</h3>
              <p style="font-size: 0.9rem; color: #94a3b8; line-height: 1.5;">Changes reflect across all your devices and integrated apps in milliseconds.</p>
            </div>
          </div>

          <!-- Center Mockup -->
          <div style="position: relative; z-index: 2; border-radius: 40px; box-shadow: 0 0 0 10px rgba(255,255,255,0.05), 0 20px 50px -10px rgba(0,0,0,0.5); overflow: hidden; background: #000; aspect-ratio: 9/19.5;">
            <img src="https://images.unsplash.com/photo-1616469829581-73993eb86b02?q=80&w=600&auto=format&fit=crop" alt="App Interface" style="width: 100%; height: 100%; object-fit: cover;">
          </div>

          <!-- Right Column -->
          <div style="display: flex; flex-direction: column; gap: 30px;">
            <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); padding: 30px; border-radius: 20px;">
              <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.1); border-radius: 12px; display: grid; place-items: center; color: #94a3b8; margin-bottom: 15px;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
              </div>
              <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 8px;">Media Assistant</h3>
              <p style="font-size: 0.9rem; color: #94a3b8; line-height: 1.5;">Organize photos, videos, and documents with intelligent tagging.</p>
            </div>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); padding: 30px; border-radius: 20px;">
              <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.1); border-radius: 12px; display: grid; place-items: center; color: #94a3b8; margin-bottom: 15px;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              </div>
              <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 8px;">Unified Inbox</h3>
              <p style="font-size: 0.9rem; color: #94a3b8; line-height: 1.5;">Manage emails, chats, and notifications from one clean feed.</p>
            </div>
          </div>

          <!-- Connectors Overlay -->
          <svg style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1;" viewBox="0 0 1200 600" fill="none">
            <defs>
              <linearGradient id="lineGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#3b82f6" />
                <stop offset="100%" stop-color="#9333ea" />
              </linearGradient>
            </defs>
            <path class="s18-line" d="M 350 150 C 450 150, 500 300, 600 300" />
            <path class="s18-line" d="M 850 150 C 750 150, 700 300, 600 300" />
          </svg>

        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 13: THE LINEAR PROGRESSION (Light)
  // ============================================
  {
    id: 'section-linear-progression',
    name: 'The Linear Progression',
    thumbnail: `<div style="width: 100%; height: 100%; background: #ffffff; border-radius: 8px; display: grid; grid-template-columns: 1fr 1.5fr; gap: 8px; padding: 8px; align-items: start;">
      <div style="display: flex; flex-direction: column; gap: 4px;">
        <div style="width: 20px; height: 6px; background: #3b82f6; border-radius: 10px;"></div>
        <div style="height: 10px; background: #0f172a; border-radius: 2px;"></div>
        <div style="height: 6px; background: #64748b; border-radius: 2px;"></div>
      </div>
      <div style="display: flex; flex-direction: column; gap: 8px; border-left: 1px dashed #e2e8f0; padding-left: 8px;">
        <div style="display: flex; align-items: center; gap: 4px;">
          <div style="width: 6px; height: 6px; background: #3b82f6; border-radius: 50%;"></div>
          <div style="width: 30px; height: 4px; background: #eee;"></div>
        </div>
        <div style="display: flex; align-items: center; gap: 4px;">
          <div style="width: 6px; height: 6px; background: #3b82f6; border-radius: 50%;"></div>
          <div style="width: 30px; height: 4px; background: #eee;"></div>
        </div>
      </div>
    </div>`,
    content: `<section style="padding: 120px 20px; background: #fff; font-family: 'Inter', sans-serif;">
      <div style="max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1.5fr; gap: 80px;">
        
        <!-- Left Column (Sticky Header) -->
        <div style="position: sticky; top: 100px; height: fit-content;">
          <span style="display: inline-block; background: #eff6ff; color: #3b82f6; padding: 6px 12px; border-radius: 30px; font-size: 13px; font-weight: 700; text-transform: uppercase; margin-bottom: 20px;">How it works</span>
          <h2 style="font-size: 48px; font-weight: 800; line-height: 1.1; margin-bottom: 24px; color: #0f172a; letter-spacing: -1px;">Three simple steps to regain control.</h2>
          <p style="font-size: 18px; color: #64748b; line-height: 1.6; margin-bottom: 40px;">Our process is designed for speed and simplicity. No complex setups or steep learning curves. Just connect and go.</p>
          <a href="#" style="display: inline-flex; align-items: center; gap: 8px; background: #3b82f6; color: #fff; padding: 14px 28px; border-radius: 50px; font-weight: 600; text-decoration: none;">Start your journey <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></a>
        </div>

        <!-- Right Column (Timeline) -->
        <div style="position: relative; display: flex; flex-direction: column; gap: 60px; padding-left: 30px;">
          <div style="position: absolute; left: 24px; top: 0; height: 100%; width: 2px; background-image: linear-gradient(to bottom, #e2e8f0 60%, transparent 40%); background-size: 2px 10px; background-repeat: repeat-y; z-index: 0;"></div>
          
          <!-- Step 1 -->
          <div style="position: relative; z-index: 2;">
            <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 16px;">
              <div style="width: 48px; height: 48px; background: #3b82f6; border-radius: 50%; display: grid; place-items: center; color: #fff; flex-shrink: 0; box-shadow: 0 0 0 8px #fff;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
              </div>
              <h3 style="font-size: 24px; font-weight: 700; color: #0f172a;">Connect your sources</h3>
            </div>
            <p style="padding-left: 68px; font-size: 17px; color: #64748b; line-height: 1.6;">Securely link your existing apps and databases in just a few clicks. We support over 50+ native integrations.</p>
          </div>

          <!-- Step 2 -->
          <div style="position: relative; z-index: 2;">
            <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 16px;">
              <div style="width: 48px; height: 48px; background: #3b82f6; border-radius: 50%; display: grid; place-items: center; color: #fff; flex-shrink: 0; box-shadow: 0 0 0 8px #fff;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              </div>
              <h3 style="font-size: 24px; font-weight: 700; color: #0f172a;">Define your rules</h3>
            </div>
            <p style="padding-left: 68px; font-size: 17px; color: #64748b; line-height: 1.6;">Tell our AI how you want your data organized. Set up custom triggers, filters, and automation logic easily.</p>
          </div>

          <!-- Step 3 -->
          <div style="position: relative; z-index: 2;">
            <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 16px;">
              <div style="width: 48px; height: 48px; background: #3b82f6; border-radius: 50%; display: grid; place-items: center; color: #fff; flex-shrink: 0; box-shrink: 0; box-shadow: 0 0 0 8px #fff;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              </div>
              <h3 style="font-size: 24px; font-weight: 700; color: #0f172a;">Automate & relax</h3>
            </div>
            <p style="padding-left: 68px; font-size: 17px; color: #64748b; line-height: 1.6;">Watch as your workflows run automatically. Get notified only when something needs your specific attention.</p>
          </div>

        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 14: THE HOLOGRAPHIC GRID (Dark)
  // ============================================
  {
    id: 'section-holographic-grid',
    name: 'The Holographic Grid',
    thumbnail: `<div style="width: 100%; height: 100%; background: #09090b; border-radius: 8px; display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 8px; position: relative; overflow: hidden;">
      <div style="position: absolute; top: -10px; right: -10px; width: 30px; height: 30px; background: radial-gradient(circle, rgba(124, 58, 237, 0.4), transparent); filter: blur(10px);"></div>
      <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px;"></div>
      <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px;"></div>
      <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px;"></div>
      <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px;"></div>
    </div>`,
    content: `<section style="padding: 120px 20px; background: #09090b; color: #fff; overflow: hidden; position: relative; font-family: 'Inter', sans-serif;">
      <style>
        .s20-card::before {
          content: ""; position: absolute; inset: 0; border-radius: 24px; padding: 2px;
          background: conic-gradient(from 180deg at 50% 50%, #10b981, #3b82f6, #8b5cf6, #ec4899, #10b981);
          -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
          mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
          -webkit-mask-composite: xor; mask-composite: exclude;
          opacity: 0.5; transition: opacity 0.3s; pointer-events: none;
        }
        .s20-card:hover { transform: translateY(-10px); background: rgba(255, 255, 255, 0.07); }
        .s20-card:hover::before { opacity: 1; }
      </style>
      
      <!-- Background Glows -->
      <div style="position: absolute; top: -20%; right: -10%; width: 600px; height: 600px; background: radial-gradient(circle, rgba(124, 58, 237, 0.3) 0%, transparent 70%); filter: blur(80px); pointer-events: none;"></div>
      <div style="position: absolute; bottom: -20%; left: -10%; width: 500px; height: 500px; background: radial-gradient(circle, rgba(16, 185, 129, 0.2) 0%, transparent 70%); filter: blur(80px); pointer-events: none;"></div>

      <div style="max-width: 1200px; margin: 0 auto; position: relative; z-index: 2;">
        <div style="text-align: center; margin-bottom: 80px;">
          <h2 style="font-size: 48px; font-weight: 800; letter-spacing: -1px; margin-bottom: 20px; font-family: 'Outfit', sans-serif; background: linear-gradient(to right, #fff, #aaa); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Next-generation capabilities.</h2>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px;">
          
          <!-- Card 1 -->
          <div class="s20-card" style="position: relative; border-radius: 24px; padding: 40px; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); overflow: hidden; transition: 0.3s;">
            <svg style="width: 50px; height: 50px; margin-bottom: 24px;" viewBox="0 0 24 24" fill="none" stroke="url(#iconGrad1)" stroke-width="1.5">
              <defs><linearGradient id="iconGrad1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#ec4899"/></linearGradient></defs>
              <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
            <h3 style="font-size: 24px; font-weight: 700; margin-bottom: 12px; font-family: 'Outfit', sans-serif;">Multi-Dimensional Data</h3>
            <p style="color: #a1a1aa; line-height: 1.6; font-size: 16px;">Visualize complex datasets in a structured, easy-to-understand 3D space.</p>
          </div>

          <!-- Card 2 -->
          <div class="s20-card" style="position: relative; border-radius: 24px; padding: 40px; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); overflow: hidden; transition: 0.3s;">
            <svg style="width: 50px; height: 50px; margin-bottom: 24px;" viewBox="0 0 24 24" fill="none" stroke="url(#iconGrad2)" stroke-width="1.5">
              <defs><linearGradient id="iconGrad2" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#ec4899"/></linearGradient></defs>
              <circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>
            </svg>
            <h3 style="font-size: 24px; font-weight: 700; margin-bottom: 12px; font-family: 'Outfit', sans-serif;">Sentiment Engine</h3>
            <p style="color: #a1a1aa; line-height: 1.6; font-size: 16px;">AI that understands the tone and intent behind customer communications.</p>
          </div>

          <!-- Card 3 -->
          <div class="s20-card" style="position: relative; border-radius: 24px; padding: 40px; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); overflow: hidden; transition: 0.3s;">
            <svg style="width: 50px; height: 50px; margin-bottom: 24px;" viewBox="0 0 24 24" fill="none" stroke="url(#iconGrad3)" stroke-width="1.5">
              <defs><linearGradient id="iconGrad3" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#ec4899"/></linearGradient></defs>
              <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
            </svg>
            <h3 style="font-size: 24px; font-weight: 700; margin-bottom: 12px; font-family: 'Outfit', sans-serif;">Hyper-Speed Query</h3>
            <p style="color: #a1a1aa; line-height: 1.6; font-size: 16px;">Get answers from billions of data points in milliseconds, not minutes.</p>
          </div>

          <!-- Card 4 -->
          <div class="s20-card" style="position: relative; border-radius: 24px; padding: 40px; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); overflow: hidden; transition: 0.3s;">
            <svg style="width: 50px; height: 50px; margin-bottom: 24px;" viewBox="0 0 24 24" fill="none" stroke="url(#iconGrad4)" stroke-width="1.5">
              <defs><linearGradient id="iconGrad4" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#ec4899"/></linearGradient></defs>
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
            <h3 style="font-size: 24px; font-weight: 700; margin-bottom: 12px; font-family: 'Outfit', sans-serif;">Blockchain Ledger</h3>
            <p style="color: #a1a1aa; line-height: 1.6; font-size: 16px;">Immutable record keeping for audit trails and compliance requirements.</p>
          </div>

          <!-- Card 5 -->
          <div class="s20-card" style="position: relative; border-radius: 24px; padding: 40px; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); overflow: hidden; transition: 0.3s;">
            <svg style="width: 50px; height: 50px; margin-bottom: 24px;" viewBox="0 0 24 24" fill="none" stroke="url(#iconGrad5)" stroke-width="1.5">
              <defs><linearGradient id="iconGrad5" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#ec4899"/></linearGradient></defs>
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <h3 style="font-size: 24px; font-weight: 700; margin-bottom: 12px; font-family: 'Outfit', sans-serif;">Team Neural Net</h3>
            <p style="color: #a1a1aa; line-height: 1.6; font-size: 16px;">Shared knowledge base that learns from every team member's actions.</p>
          </div>

          <!-- Card 6 -->
          <div class="s20-card" style="position: relative; border-radius: 24px; padding: 40px; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); overflow: hidden; transition: 0.3s;">
            <svg style="width: 50px; height: 50px; margin-bottom: 24px;" viewBox="0 0 24 24" fill="none" stroke="url(#iconGrad6)" stroke-width="1.5">
              <defs><linearGradient id="iconGrad6" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#ec4899"/></linearGradient></defs>
              <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
            <h3 style="font-size: 24px; font-weight: 700; margin-bottom: 12px; font-family: 'Outfit', sans-serif;">Edge Deployment</h3>
            <p style="color: #a1a1aa; line-height: 1.6; font-size: 16px;">Run your models locally on devices for zero-latency decision making.</p>
          </div>

        </div>
      </div>
    </section>`
  },


  // ============================================
  // PRESET 15: THE FLUID ACCORDION (FAQ)
  // ============================================
  {
    id: 'section-fluid-accordion',
    name: 'The Fluid Accordion',
    thumbnail: `<div style="width: 100%; height: 100%; background: #f8fafc; border-radius: 8px; display: flex; flex-direction: column; gap: 4px; padding: 12px; justify-content: center;">
      <div style="height: 12px; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; display: flex; align-items: center; padding: 0 4px;">
        <div style="width: 40px; height: 4px; background: #3b82f6;"></div>
      </div>
      <div style="height: 25px; background: #fff; border: 1px solid #3b82f6; border-radius: 4px; display: flex; flex-direction: column; padding: 4px; gap: 4px;">
        <div style="width: 50px; height: 4px; background: #3b82f6;"></div>
        <div style="width: 100%; height: 8px; background: #f8fafc;"></div>
      </div>
      <div style="height: 12px; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; display: flex; align-items: center; padding: 0 4px;">
        <div style="width: 40px; height: 4px; background: #eee;"></div>
      </div>
    </div>`,
    content: `<section style="padding: 120px 20px; background: #f8fafc; font-family: 'Inter', sans-serif;">
      <style>
        .faq-item { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; transition: all 0.3s ease; margin-bottom: 16px; }
        .faq-item[open] { border-color: #3b82f6; box-shadow: 0 10px 30px -5px rgba(59, 130, 246, 0.15); }
        .faq-summary { list-style: none; cursor: pointer; padding: 24px; display: flex; justify-content: space-between; align-items: center; }
        .faq-summary::-webkit-details-marker { display: none; }
        .faq-question { font-size: 1.2rem; font-weight: 600; color: #0f172a; transition: 0.2s; }
        .faq-item[open] .faq-question { color: #3b82f6; }
        .faq-toggle { width: 32px; height: 32px; background: #f1f5f9; border-radius: 50%; display: grid; place-items: center; color: #64748b; transition: 0.4s cubic-bezier(0.4, 0.0, 0.2, 1); }
        .faq-item[open] .faq-toggle { transform: rotate(180deg); background: #3b82f6; color: #fff; }
        .faq-wrapper { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.4s cubic-bezier(0.4, 0.0, 0.2, 1); }
        .faq-item[open] .faq-wrapper { grid-template-rows: 1fr; }
        .faq-content { overflow: hidden; }
        .faq-text { padding: 0 24px 30px 24px; color: #64748b; line-height: 1.6; font-size: 1.05rem; opacity: 0; transform: translateY(10px); transition: 0.3s 0.1s; }
        .faq-item[open] .faq-text { opacity: 1; transform: translateY(0); }
      </style>

      <div style="max-width: 900px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 60px;">
          <h2 style="font-size: 40px; font-weight: 800; margin-bottom: 15px; color: #0f172a; letter-spacing: -1px;">Frequently asked questions.</h2>
          <p style="color: #64748b; font-size: 18px; max-width: 600px; margin: 0 auto;">Everything you need to know about the product and billing. Can't find the answer you're looking for?</p>
        </div>
        
        <div style="display: flex; flex-direction: column;">
          
          <details class="faq-item" open>
            <summary class="faq-summary">
              <span class="faq-question">Is there a free trial available?</span>
              <div class="faq-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></div>
            </summary>
            <div class="faq-wrapper">
              <div class="faq-content">
                <p class="faq-text">Yes, you can try us for free for 14 days. If you want, we'll provide you with a free, personalized 30-minute onboarding call to get you up and running as soon as possible.</p>
              </div>
            </div>
          </details>

          <details class="faq-item">
            <summary class="faq-summary">
              <span class="faq-question">Can I change my plan later?</span>
              <div class="faq-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></div>
            </summary>
            <div class="faq-wrapper">
              <div class="faq-content">
                <p class="faq-text">Of course. You can upgrade or downgrade your plan from your billing settings at any time. Prorated adjustments will be applied automatically to your next billing cycle.</p>
              </div>
            </div>
          </details>

          <details class="faq-item">
            <summary class="faq-summary">
              <span class="faq-question">What is your cancellation policy?</span>
              <div class="faq-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></div>
            </summary>
            <div class="faq-wrapper">
              <div class="faq-content">
                <p class="faq-text">You can cancel your subscription at any time. You will continue to have access to the features you paid for until the end of your current billing period, after which your account will be downgraded to free.</p>
              </div>
            </div>
          </details>

          <details class="faq-item">
            <summary class="faq-summary">
              <span class="faq-question">How does data security work?</span>
              <div class="faq-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></div>
            </summary>
            <div class="faq-wrapper">
              <div class="faq-content">
                <p class="faq-text">We take data security seriously. All your data is encrypted at rest and in transit using AES-256 encryption. We are SOC 2 Type II compliant and undergo regular security audits.</p>
              </div>
            </div>
          </details>

        </div>
      </div>
    </section>`
  },

  // ============================================
  // 404 ERROR PAGE - Monster Theme
  // ============================================
  {
    id: 'section-404-monster',
    name: '404 Page - Monster',
    thumbnail: `<svg viewBox="0 0 200 100" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect width="200" height="100" fill="#bfaee3"/>
      <text x="100" y="55" text-anchor="middle" fill="white" font-size="32" font-weight="bold" opacity="0.4">404</text>
      <circle cx="100" cy="50" r="20" fill="#6b5b95"/>
      <circle cx="95" cy="45" r="4" fill="white"/>
      <circle cx="105" cy="45" r="4" fill="white"/>
    </svg>`,
    content: `<section data-gjs-name="404 Section" class="section-404-monster">
      <style>
        .section-404-monster {
          --bg-gradient-start: #bfaee3;
          --bg-gradient-end: #e6e9f0;
          --text-color: #1a1b25;
          --text-muted: #6b6b75;
          --btn-color: #4b4b65;
          --white: #ffffff;
          font-family: 'Nunito', 'Poppins', sans-serif;
          background: linear-gradient(135deg, var(--bg-gradient-start) 0%, var(--bg-gradient-end) 100%);
          min-height: 100vh;
          width: 100%;
          overflow: hidden;
          display: flex;
          align-items: center;
          justify-content: center;
          color: var(--text-color);
          position: relative;
          padding: 40px 20px;
        }
        .section-404-monster .bg-404 { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 35vw; font-weight: 800; color: var(--white); opacity: 0.4; line-height: 1; z-index: 1; pointer-events: none; user-select: none; }
        .section-404-monster .hero-container { position: relative; z-index: 2; display: flex; align-items: center; justify-content: center; width: 90%; max-width: 1400px; gap: 2rem; }
        .section-404-monster .character-wrapper { flex: 1; display: flex; justify-content: flex-end; padding-right: 2rem; }
        .section-404-monster .monster-img { max-width: 480px; width: 100%; height: auto; animation: float404m 6s ease-in-out infinite; filter: drop-shadow(0 20px 30px rgba(0,0,0,0.15)); }
        .section-404-monster .text-content { flex: 1; max-width: 600px; text-align: left; padding-left: 2rem; }
        .section-404-monster h1 { font-size: 3.5rem; line-height: 1.1; margin-bottom: 1.5rem; font-weight: 800; }
        .section-404-monster p { font-size: 1.15rem; line-height: 1.6; color: var(--text-muted); margin-bottom: 2.5rem; max-width: 90%; }
        .section-404-monster .cta-btn { display: inline-flex; align-items: center; background-color: rgba(255, 255, 255, 0.3); border: 1px solid var(--white); color: var(--btn-color); padding: 0.5rem 0.5rem 0.5rem 1.5rem; border-radius: 50px; font-weight: 700; text-decoration: none; transition: all 0.3s ease; backdrop-filter: blur(5px); gap: 15px; }
        .section-404-monster .icon-circle { background-color: var(--btn-color); color: var(--white); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: transform 0.3s ease; }
        .section-404-monster .cta-btn:hover { background-color: var(--white); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
        .section-404-monster .cta-btn:hover .icon-circle { transform: rotate(-45deg); }
        @keyframes float404m { 0% { transform: translateY(0px); } 50% { transform: translateY(-20px); } 100% { transform: translateY(0px); } }
        @media (max-width: 1024px) { .section-404-monster .bg-404 { font-size: 40vw; } .section-404-monster h1 { font-size: 2.8rem; } .section-404-monster .monster-img { max-width: 380px; } }
        @media (max-width: 768px) { .section-404-monster .hero-container { flex-direction: column; text-align: center; } .section-404-monster .character-wrapper { justify-content: center; padding-right: 0; margin-bottom: 2rem; } .section-404-monster .text-content { padding-left: 0; align-items: center; display: flex; flex-direction: column; text-align: center; } .section-404-monster h1 { font-size: 2.2rem; } .section-404-monster .bg-404 { top: 40%; font-size: 50vw; } .section-404-monster p { max-width: 100%; } }
      </style>
      <div class="bg-404">404</div>
      <div data-gjs-name="Hero Container" class="hero-container">
        <div data-gjs-name="Character" class="character-wrapper">
          <img src="https://cdn3d.iconscout.com/3d/premium/thumb/monster-reading-book-5665672-4721473.png" alt="Cute monster reading a book" class="monster-img">
        </div>
        <div data-gjs-name="Text Content" class="text-content">
          <h1>Whoops! Looks Like This Page Went on Vacation!</h1>
          <p>Uh oh! Our little cartoon friends might have accidentally scribbled out this address. We can't seem to find the page you're looking for.</p>
          <a href="#" class="cta-btn">
            <span class="icon-circle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg></span>
            Return to Base Camp
          </a>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // 404 ERROR PAGE - Dark Minimal
  // ============================================
  {
    id: 'section-404-dark',
    name: '404 Page - Dark',
    thumbnail: `<svg viewBox="0 0 200 100" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect width="200" height="100" fill="#000000"/>
      <ellipse cx="100" cy="95" rx="80" ry="25" fill="#1a1a1a"/>
      <text x="100" y="55" text-anchor="middle" fill="white" font-size="36" font-weight="500">404</text>
      <circle cx="100" cy="75" r="8" fill="none" stroke="#666" stroke-width="1"/>
    </svg>`,
    content: `<section data-gjs-name="404 Dark Section" class="section-404-dark">
      <style>
        .section-404-dark {
          font-family: 'Inter', 'Poppins', sans-serif;
          background-color: #000000;
          color: #ffffff;
          min-height: 100vh;
          display: flex;
          flex-direction: column;
          overflow-x: hidden;
          position: relative;
        }
        .section-404-dark a { text-decoration: none; color: inherit; transition: all 0.3s ease; }
        .section-404-dark .background-glow { position: absolute; bottom: -15vh; left: 50%; transform: translateX(-50%); width: 140vw; height: 60vh; background: radial-gradient(50% 100% at 50% 100%, rgba(50, 50, 50, 0.9) 0%, rgba(0, 0, 0, 0) 100%); border-radius: 50% 50% 0 0; z-index: 1; pointer-events: none; opacity: 0.8; }
        .section-404-dark .main-content { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; z-index: 10; padding: 40px 20px; }
        .section-404-dark .error-code { font-size: clamp(8rem, 25vw, 16rem); font-weight: 500; line-height: 1; letter-spacing: -0.02em; color: #ffffff; }
        .section-404-dark .error-subtitle { font-size: 1.25rem; margin-top: 1rem; margin-bottom: 5rem; font-weight: 300; color: rgba(255, 255, 255, 0.9); }
        .section-404-dark .home-action { display: flex; flex-direction: column; align-items: center; gap: 15px; cursor: pointer; color: #ffffff; }
        .section-404-dark .fingerprint-icon { width: 45px; height: 45px; fill: none; stroke: #ffffff; stroke-width: 1.5; transition: transform 0.3s ease; }
        .section-404-dark .home-text { font-size: 1rem; font-weight: 400; opacity: 0.8; transition: opacity 0.3s ease; }
        .section-404-dark .home-action:hover .fingerprint-icon { transform: scale(1.1); }
        .section-404-dark .home-action:hover .home-text { opacity: 1; text-decoration: underline; text-underline-offset: 5px; }
        .section-404-dark .footer-section { display: flex; justify-content: space-between; align-items: flex-end; padding: 3rem 4rem; font-size: 0.85rem; z-index: 10; flex-wrap: wrap; gap: 2rem; width: 100%; }
        .section-404-dark .footer-column { display: flex; flex-direction: column; gap: 1rem; }
        .section-404-dark .footer-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.5; margin-bottom: 0.2rem; }
        .section-404-dark .footer-email { font-size: 1.2rem; font-weight: 500; }
        .section-404-dark .copyright { margin-top: 2.5rem; opacity: 0.5; font-size: 0.75rem; }
        .section-404-dark .footer-links { display: flex; gap: 0.5rem; flex-direction: column; }
        .section-404-dark .footer-links a { border-bottom: 1px solid rgba(255,255,255,0.3); width: fit-content; padding-bottom: 2px; }
        .section-404-dark .footer-links a:hover { border-color: #fff; }
        .section-404-dark .social-row { display: flex; gap: 1rem; flex-direction: row; }
        .section-404-dark .footer-right { display: flex; flex-direction: column; align-items: flex-end; gap: 3rem; margin-left: auto; }
        .section-404-dark .back-to-top { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; }
        .section-404-dark .legal-links { display: flex; gap: 2rem; opacity: 0.7; }
        @media (max-width: 900px) { .section-404-dark .footer-section { padding: 2rem; flex-direction: column; align-items: flex-start; } .section-404-dark .footer-right { margin-left: 0; align-items: flex-start; flex-direction: row; width: 100%; justify-content: space-between; } }
      </style>
      <div class="background-glow"></div>
      <main data-gjs-name="Main Content" class="main-content">
        <h1 class="error-code">404</h1>
        <p class="error-subtitle">It seems you got a little bit lost</p>
        <a href="#" class="home-action">
          <svg class="fingerprint-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12" stroke-linecap="round"/>
            <path d="M12 5C8.13 5 5 8.13 5 12" stroke-linecap="round"/>
            <path d="M19 12C19 8.13 15.87 5 12 5" stroke-linecap="round"/>
            <path d="M8 12C8 14.21 9.79 16 12 16" stroke-linecap="round"/>
            <path d="M16 12C16 14.21 14.21 16 12 16" stroke-linecap="round"/>
            <path d="M12 8C10.5 8 9.5 9 9.5 10.5" stroke-linecap="round"/>
            <path d="M14.5 10.5C14.5 9 13.5 8 12 8" stroke-linecap="round"/>
            <path d="M12 19V16" stroke-linecap="round"/>
          </svg>
          <span class="home-text">Go back to homepage</span>
        </a>
      </main>
      <footer data-gjs-name="Footer" class="footer-section">
        <div class="footer-column">
          <span class="footer-label">Hey, drop us a line</span>
          <a href="mailto:info@company.com" class="footer-email">info@company.com</a>
          <div class="copyright">© 2026 - Company. All rights reserved.</div>
        </div>
        <div class="footer-column">
          <span class="footer-label">Where we are located?</span>
          <div class="footer-links">
            <a href="#">New York, USA</a>
            <a href="#">London, UK</a>
          </div>
        </div>
        <div class="footer-column">
          <span class="footer-label">Stay in the loop</span>
          <div class="social-row footer-links">
            <a href="#">LinkedIn</a>
            <a href="#">Instagram</a>
            <a href="#">Dribbble</a>
          </div>
        </div>
        <div class="footer-right">
          <div class="back-to-top"><span style="font-size: 1.2rem;">↑</span> Back to top</div>
          <div class="legal-links">
            <a href="#">Legal info</a>
            <a href="#">Privacy Policy</a>
          </div>
        </div>
      </footer>
    </section>`
  },

  // ============================================
  // COMING SOON PAGE - Gradient Glow
  // ============================================
  {
    id: 'section-coming-soon',
    name: 'Coming Soon',
    thumbnail: `<svg viewBox="0 0 200 100" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect width="200" height="100" fill="#050505"/>
      <ellipse cx="100" cy="30" rx="60" ry="30" fill="url(#grad1)" opacity="0.3"/>
      <text x="100" y="45" text-anchor="middle" fill="url(#grad2)" font-size="14" font-weight="bold">COMING SOON</text>
      <text x="100" y="65" text-anchor="middle" fill="white" font-size="10">30 : 12 : 45 : 08</text>
      <defs>
        <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4facfe"/><stop offset="100%" stop-color="#00f2fe"/></linearGradient>
        <linearGradient id="grad2" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4facfe"/><stop offset="50%" stop-color="#00f2fe"/><stop offset="100%" stop-color="#a726eb"/></linearGradient>
      </defs>
    </svg>`,
    content: `<section data-gjs-name="Coming Soon" class="section-coming-soon">
      <style>
        .section-coming-soon {
          font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
          background-color: #050505;
          color: #ffffff;
          display: flex;
          justify-content: center;
          align-items: center;
          min-height: 100vh;
          overflow: hidden;
          background-image: radial-gradient(circle at 50% 30%, rgba(79, 172, 254, 0.15) 0%, rgba(0, 242, 254, 0.1) 25%, rgba(5, 5, 5, 1) 70%);
          padding: 40px 20px;
        }
        .section-coming-soon .container { text-align: center; z-index: 1; max-width: 800px; width: 100%; }
        .section-coming-soon h1.main-title { font-size: 5rem; font-weight: 800; margin-bottom: 1.5rem; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 50%, #a726eb 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; text-transform: uppercase; letter-spacing: -1px; line-height: 1.1; }
        .section-coming-soon p.subtext { font-size: 1.25rem; color: #b0b0b0; margin-bottom: 3rem; line-height: 1.6; font-weight: 300; max-width: 600px; margin-left: auto; margin-right: auto; }
        .section-coming-soon .countdown { display: flex; justify-content: center; gap: 2rem; margin-bottom: 3.5rem; }
        .section-coming-soon .time-unit { display: flex; flex-direction: column; align-items: center; }
        .section-coming-soon .number { font-size: 4rem; font-weight: 700; color: #ffffff; line-height: 1; text-shadow: 0 0 15px rgba(0, 242, 254, 0.5); }
        .section-coming-soon .label { font-size: 0.9rem; text-transform: uppercase; color: #888888; margin-top: 0.8rem; letter-spacing: 1px; font-weight: 500; }
        .section-coming-soon .newsletter-form { display: flex; justify-content: center; gap: 1rem; max-width: 500px; margin: 0 auto; position: relative; flex-wrap: wrap; }
        .section-coming-soon .newsletter-form input[type="email"] { flex-grow: 1; min-width: 200px; padding: 1rem 1.5rem; border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 50px; background: rgba(255, 255, 255, 0.03); color: #ffffff; font-size: 1rem; outline: none; transition: all 0.3s ease; backdrop-filter: blur(10px); }
        .section-coming-soon .newsletter-form input[type="email"]:focus { border-color: #4facfe; background: rgba(255, 255, 255, 0.07); box-shadow: 0 0 15px rgba(79, 172, 254, 0.3); }
        .section-coming-soon .newsletter-form input[type="email"]::placeholder { color: #777777; }
        .section-coming-soon .newsletter-form button { padding: 1rem 2.5rem; border: none; border-radius: 50px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: #ffffff; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 15px rgba(0, 242, 254, 0.3); }
        .section-coming-soon .newsletter-form button:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 242, 254, 0.5); }
        @media (max-width: 768px) { .section-coming-soon h1.main-title { font-size: 3rem; } .section-coming-soon p.subtext { font-size: 1.1rem; } .section-coming-soon .countdown { gap: 1rem; } .section-coming-soon .number { font-size: 2.5rem; } .section-coming-soon .newsletter-form { flex-direction: column; } .section-coming-soon .newsletter-form input[type="email"], .section-coming-soon .newsletter-form button { width: 100%; text-align: center; } }
      </style>
      <div data-gjs-name="Container" class="container">
        <h1 class="main-title">Coming Soon</h1>
        <p class="subtext">From automation of people processes to creating an engaged and driven culture.</p>
        <div data-gjs-name="Countdown" class="countdown">
          <div class="time-unit"><span class="number">30</span><span class="label">Days</span></div>
          <div class="time-unit"><span class="number">12</span><span class="label">Hours</span></div>
          <div class="time-unit"><span class="number">45</span><span class="label">Minutes</span></div>
          <div class="time-unit"><span class="number">08</span><span class="label">Seconds</span></div>
        </div>
        <form data-gjs-name="Newsletter Form" class="newsletter-form">
          <input type="email" placeholder="Enter your email address">
          <button type="submit">Notify Me</button>
        </form>
      </div>
    </section>`
  },

  // ============================================
  // COMING SOON PAGE - Eclipse (Nova AI)
  // ============================================
  {
    id: 'section-coming-soon-eclipse',
    name: 'Coming Soon - Eclipse',
    thumbnail: `<svg viewBox="0 0 200 100" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect width="200" height="100" fill="#000000"/>
      <circle cx="100" cy="50" r="35" fill="none" stroke="url(#eclipseGrad)" stroke-width="2" opacity="0.8"/>
      <ellipse cx="100" cy="25" rx="50" ry="15" fill="url(#glowGrad)" opacity="0.3"/>
      <text x="100" y="55" text-anchor="middle" fill="url(#textGrad)" font-size="8" font-weight="600">NOVA AI</text>
      <text x="100" y="75" text-anchor="middle" fill="white" font-size="6" opacity="0.6">10 : 00 : 00 : 00</text>
      <defs>
        <linearGradient id="eclipseGrad" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="#FFD700" stop-opacity="0.6"/><stop offset="100%" stop-color="#FFD700" stop-opacity="0.2"/></linearGradient>
        <radialGradient id="glowGrad" cx="50%" cy="50%" r="50%"><stop offset="0%" stop-color="#FFDF00" stop-opacity="0.4"/><stop offset="100%" stop-color="#000000" stop-opacity="0"/></radialGradient>
        <linearGradient id="textGrad" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#FFFFFF"/><stop offset="100%" stop-color="#AAAAAA"/></linearGradient>
      </defs>
    </svg>`,
    content: `<section data-gjs-name="Coming Soon Eclipse" class="section-coming-soon-eclipse">
      <style>
        .section-coming-soon-eclipse {
          font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
          background-color: #000000;
          color: #ffffff;
          min-height: 100vh;
          width: 100%;
          overflow: hidden;
          display: flex;
          flex-direction: column;
          position: relative;
        }
        .section-coming-soon-eclipse .eclipse-container {
          position: absolute;
          top: 50%;
          left: 50%;
          transform: translate(-50%, -50%);
          width: 80vh;
          height: 80vh;
          z-index: 0;
          pointer-events: none;
        }
        .section-coming-soon-eclipse .eclipse-ring {
          width: 100%;
          height: 100%;
          border-radius: 50%;
          background: transparent;
          box-shadow: 0 -10px 80px rgba(255, 215, 0, 0.2), inset 0 20px 60px rgba(255, 215, 0, 0.4);
          border-top: 2px solid rgba(255, 215, 0, 0.6);
          filter: blur(2px);
          opacity: 0.8;
        }
        .section-coming-soon-eclipse .eclipse-glow {
          position: absolute;
          top: -10%;
          left: 50%;
          transform: translateX(-50%);
          width: 120%;
          height: 50%;
          background: radial-gradient(ellipse at center, rgba(255, 223, 0, 0.15) 0%, rgba(0,0,0,0) 70%);
          z-index: -1;
          filter: blur(40px);
        }
        .section-coming-soon-eclipse nav {
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding: 2rem 3rem;
          width: 100%;
          z-index: 10;
        }
        .section-coming-soon-eclipse .logo {
          font-weight: 500;
          font-size: 1.1rem;
          letter-spacing: -0.02em;
        }
        .section-coming-soon-eclipse .nav-icon {
          width: 24px;
          height: 24px;
          opacity: 0.8;
          cursor: pointer;
        }
        .section-coming-soon-eclipse main {
          flex: 1;
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          text-align: center;
          z-index: 10;
          position: relative;
          padding: 0 20px 5vh 20px;
        }
        .section-coming-soon-eclipse .countdown-label {
          font-size: 0.75rem;
          text-transform: uppercase;
          letter-spacing: 0.3em;
          color: rgba(255, 255, 255, 0.5);
          margin-bottom: 1.5rem;
        }
        .section-coming-soon-eclipse .countdown-container {
          display: flex;
          align-items: center;
          gap: 1.5rem;
          background: rgba(255, 255, 255, 0.03);
          padding: 1rem 2rem;
          border-radius: 20px;
          border: 1px solid rgba(255, 255, 255, 0.08);
          backdrop-filter: blur(12px);
          -webkit-backdrop-filter: blur(12px);
          box-shadow: 0 20px 40px rgba(0,0,0,0.6);
          margin-bottom: 3rem;
        }
        .section-coming-soon-eclipse .time-segment {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
        }
        .section-coming-soon-eclipse .digit-box {
          font-size: 2.5rem;
          font-weight: 600;
          font-variant-numeric: tabular-nums;
          line-height: 1;
          text-align: center;
          text-shadow: 0 0 20px rgba(255,255,255,0.1);
          min-width: 1.8ch;
        }
        .section-coming-soon-eclipse .unit-label {
          font-size: 0.7rem;
          text-transform: uppercase;
          letter-spacing: 0.1em;
          color: rgba(255, 255, 255, 0.4);
          margin-top: 0.4rem;
        }
        .section-coming-soon-eclipse .separator {
          font-size: 2rem;
          font-weight: 300;
          color: rgba(255, 255, 255, 0.2);
          margin-bottom: 1.2rem;
        }
        .section-coming-soon-eclipse h1 {
          font-size: clamp(2.5rem, 5vw, 4.5rem);
          font-weight: 500;
          line-height: 1.1;
          letter-spacing: -0.03em;
          margin-bottom: 1.5rem;
          max-width: 800px;
          background: linear-gradient(180deg, #FFFFFF 0%, rgba(255, 255, 255, 0.7) 100%);
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
          background-clip: text;
        }
        .section-coming-soon-eclipse p.subtitle {
          font-size: 1.1rem;
          line-height: 1.6;
          color: rgba(255, 255, 255, 0.6);
          max-width: 500px;
          margin-bottom: 3rem;
          font-weight: 300;
        }
        .section-coming-soon-eclipse .input-wrapper {
          position: relative;
          width: 100%;
          max-width: 480px;
          display: flex;
          align-items: center;
          background: rgba(255, 255, 255, 0.05);
          border: 1px solid rgba(255, 255, 255, 0.1);
          border-radius: 50px;
          padding: 0.35rem;
          transition: all 0.3s ease;
        }
        .section-coming-soon-eclipse .input-wrapper:focus-within {
          border-color: rgba(255, 255, 255, 0.3);
          background: rgba(255, 255, 255, 0.1);
          box-shadow: 0 0 20px rgba(255, 255, 255, 0.05);
        }
        .section-coming-soon-eclipse .input-wrapper input {
          flex: 1;
          background: transparent;
          border: none;
          padding: 0.8rem 1.5rem;
          color: #fff;
          font-size: 1rem;
          font-family: inherit;
          outline: none;
        }
        .section-coming-soon-eclipse .input-wrapper input::placeholder {
          color: rgba(255, 255, 255, 0.3);
        }
        .section-coming-soon-eclipse .input-wrapper button {
          background-color: #ffffff;
          color: #000000;
          border: none;
          padding: 0.8rem 1.8rem;
          border-radius: 50px;
          font-weight: 600;
          font-size: 0.9rem;
          cursor: pointer;
          transition: transform 0.2s ease, box-shadow 0.2s ease;
          white-space: nowrap;
        }
        .section-coming-soon-eclipse .input-wrapper button:hover {
          transform: scale(1.02);
          box-shadow: 0 0 15px rgba(255, 255, 255, 0.4);
        }
        @media (max-width: 768px) {
          .section-coming-soon-eclipse nav { padding: 1.5rem; }
          .section-coming-soon-eclipse h1 { font-size: 2.2rem; margin-bottom: 1rem; }
          .section-coming-soon-eclipse .eclipse-container { width: 90vw; height: 90vw; }
          .section-coming-soon-eclipse .input-wrapper { flex-direction: column; background: transparent; border: none; padding: 0; gap: 1rem; }
          .section-coming-soon-eclipse .input-wrapper input { width: 100%; background: rgba(255,255,255,0.08); border-radius: 50px; border: 1px solid rgba(255,255,255,0.1); text-align: center; padding: 1rem; }
          .section-coming-soon-eclipse .input-wrapper button { width: 100%; padding: 1rem; }
          .section-coming-soon-eclipse .countdown-container { gap: 0.8rem; padding: 1rem; margin-bottom: 2rem; }
          .section-coming-soon-eclipse .digit-box { font-size: 1.8rem; }
          .section-coming-soon-eclipse .separator { font-size: 1.5rem; margin-bottom: 1rem; }
          .section-coming-soon-eclipse .unit-label { font-size: 0.6rem; }
        }
      </style>
      <div class="eclipse-container">
        <div class="eclipse-glow"></div>
        <div class="eclipse-ring"></div>
      </div>
      <nav>
        <div class="logo">NovaAI</div>
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M12 2L14.5 9.5H22L16 14.5L18.5 22L12 17L5.5 22L8 14.5L2 9.5H9.5L12 2Z" stroke="white" stroke-width="1.5" stroke-linejoin="round"/>
        </svg>
      </nav>
      <main>
        <div class="countdown-label">Launch Countdown</div>
        <div data-gjs-name="Countdown Container" class="countdown-container">
          <div class="time-segment">
            <div class="digit-box">10</div>
            <div class="unit-label">Days</div>
          </div>
          <div class="separator">:</div>
          <div class="time-segment">
            <div class="digit-box">00</div>
            <div class="unit-label">Hrs</div>
          </div>
          <div class="separator">:</div>
          <div class="time-segment">
            <div class="digit-box">00</div>
            <div class="unit-label">Min</div>
          </div>
          <div class="separator">:</div>
          <div class="time-segment">
            <div class="digit-box">00</div>
            <div class="unit-label">Sec</div>
          </div>
        </div>
        <h1>Nova. The AI SaaS<br>revolution coming soon</h1>
        <p class="subtitle">We're reimagining enterprise software with adaptive AI systems that think, automate, and evolve alongside your business.</p>
        <form data-gjs-name="Email Form" class="input-wrapper">
          <input type="email" placeholder="Enter your email" required>
          <button type="submit">Get early access</button>
        </form>
      </main>
    </section>`
  }
]

// Helper function to create section preset item for the panel
export function createSectionPresetItem(preset: SectionPreset): string {
  return `
    <div class="preset-item section-preset-item" data-preset-id="${preset.id}" title="${preset.name}" style="cursor: pointer; border-radius: 8px; overflow: hidden; border: 1px solid #3f3f46; transition: all 0.2s; background: #27272a;">
      <div class="preset-thumbnail" style="width: 100%; height: 80px; overflow: hidden;">
        ${preset.thumbnail}
      </div>
      <div style="padding: 8px; text-align: center;">
        <span style="font-size: 11px; color: #a1a1aa; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${preset.name}</span>
      </div>
    </div>
  `
}
