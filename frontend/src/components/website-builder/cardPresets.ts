// Card Presets for GrapesJS Website Builder
// 18 Premium Card UI Presets with inline styles

export interface CardPreset {
  id: string
  name: string
  category: string
  html: string
}

export const cardPresets: CardPreset[] = [
  // ============================================
  // CARD 1: ANALYTICS DASHBOARD
  // ============================================
  {
    id: 'card-analytics',
    name: 'Analytics Dashboard',
    category: 'Stats',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <span style="font-size: 14px; font-weight: 500; color: #a1a1aa;">Analytics Overview</span>
        <span style="padding: 4px 8px; background: rgba(132, 204, 22, 0.1); color: #84cc16; font-size: 12px; font-weight: 500; border-radius: 9999px;">Live</span>
      </div>
      <div style="display: flex; align-items: flex-end; gap: 12px; margin-bottom: 24px;">
        <span style="font-size: 48px; font-weight: 700; background: linear-gradient(135deg, #fff 0%, #a1a1aa 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">$127,348</span>
        <span style="display: flex; align-items: center; gap: 4px; color: #84cc16; font-size: 14px; font-weight: 500; margin-bottom: 8px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#84cc16" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
          +12.5%
        </span>
      </div>
      <div style="display: flex; align-items: flex-end; gap: 4px; height: 64px;">
        <div style="flex: 1; background: #27272a; border-radius: 2px 2px 0 0; height: 40%; position: relative;"><div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(132,204,22,0.3), #84cc16); border-radius: 2px 2px 0 0; height: 100%;"></div></div>
        <div style="flex: 1; background: #27272a; border-radius: 2px 2px 0 0; height: 55%; position: relative;"><div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(132,204,22,0.3), #84cc16); border-radius: 2px 2px 0 0; height: 100%;"></div></div>
        <div style="flex: 1; background: #27272a; border-radius: 2px 2px 0 0; height: 45%; position: relative;"><div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(132,204,22,0.3), #84cc16); border-radius: 2px 2px 0 0; height: 100%;"></div></div>
        <div style="flex: 1; background: #27272a; border-radius: 2px 2px 0 0; height: 70%; position: relative;"><div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(132,204,22,0.3), #84cc16); border-radius: 2px 2px 0 0; height: 100%;"></div></div>
        <div style="flex: 1; background: #27272a; border-radius: 2px 2px 0 0; height: 60%; position: relative;"><div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(132,204,22,0.3), #84cc16); border-radius: 2px 2px 0 0; height: 100%;"></div></div>
        <div style="flex: 1; background: #27272a; border-radius: 2px 2px 0 0; height: 85%; position: relative;"><div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(132,204,22,0.3), #84cc16); border-radius: 2px 2px 0 0; height: 100%;"></div></div>
        <div style="flex: 1; background: #27272a; border-radius: 2px 2px 0 0; height: 75%; position: relative;"><div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(132,204,22,0.3), #84cc16); border-radius: 2px 2px 0 0; height: 100%;"></div></div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 2: USER PROFILE
  // ============================================
  {
    id: 'card-profile',
    name: 'User Profile',
    category: 'Profile',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
        <div style="position: relative; margin-bottom: 16px;">
          <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #ec4899, #8b5cf6); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 24px; font-weight: 700;">AJ</div>
          <span style="position: absolute; bottom: 0; right: 0; width: 24px; height: 24px; background: #84cc16; border-radius: 50%; border: 2px solid #18181b;"></span>
        </div>
        <h3 style="font-size: 18px; font-weight: 600; color: #fff; margin: 0 0 4px 0;">Ariana Woods</h3>
        <p style="font-size: 14px; color: #71717a; margin: 0 0 16px 0;">Senior Product Designer</p>
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 24px;">
          <span style="padding: 4px 12px; background: rgba(132, 204, 22, 0.1); color: #84cc16; font-size: 12px; font-weight: 500; border-radius: 9999px;">Pro Member</span>
          <span style="padding: 4px 12px; background: rgba(59, 130, 246, 0.1); color: #3b82f6; font-size: 12px; font-weight: 500; border-radius: 9999px;">Verified</span>
        </div>
        <div style="display: flex; gap: 12px; width: 100%;">
          <button style="flex: 1; padding: 10px 16px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; font-size: 14px; font-weight: 500; color: #fff; cursor: pointer;">Follow</button>
          <button style="flex: 1; padding: 10px 16px; background: #fff; border: none; border-radius: 12px; font-size: 14px; font-weight: 500; color: #000; cursor: pointer;">Message</button>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 3: PROJECT TASK
  // ============================================
  {
    id: 'card-project',
    name: 'Project Task',
    category: 'Task',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px;">
        <div>
          <span style="font-size: 12px; font-weight: 500; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px;">Project</span>
          <h3 style="font-size: 18px; font-weight: 600; color: #fff; margin: 4px 0 0 0;">NovaSprint Launch</h3>
        </div>
        <button style="padding: 8px; background: transparent; border: none; cursor: pointer;">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#71717a" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
        </button>
      </div>
      <div style="margin-bottom: 16px;">
        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 14px; margin-bottom: 8px;">
          <span style="color: #a1a1aa;">Progress</span>
          <span style="color: #fff; font-weight: 500;">61%</span>
        </div>
        <div style="height: 8px; background: #27272a; border-radius: 9999px; overflow: hidden;">
          <div style="height: 100%; width: 61%; background: linear-gradient(90deg, #3b82f6, #22d3ee); border-radius: 9999px;"></div>
        </div>
      </div>
      <div style="display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex;">
          <div style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #f97316, #ec4899); border: 2px solid #18181b; margin-right: -8px;"></div>
          <div style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #6366f1); border: 2px solid #18181b; margin-right: -8px;"></div>
          <div style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #22c55e, #10b981); border: 2px solid #18181b; margin-right: -8px;"></div>
          <div style="width: 32px; height: 32px; border-radius: 50%; background: #3f3f46; border: 2px solid #18181b; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #fff; font-weight: 500;">+2</div>
        </div>
        <span style="font-size: 12px; color: #71717a;">Due tomorrow</span>
      </div>
    </div>`
  },

  // ============================================
  // CARD 4: LIFESTYLE / WELLNESS
  // ============================================
  {
    id: 'card-lifestyle',
    name: 'Lifestyle Card',
    category: 'Feature',
    html: `<div style="position: relative; background: linear-gradient(135deg, #064e3b, #0f766e, #18181b); border-radius: 24px; overflow: hidden; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif; height: 320px;">
      <div style="position: absolute; inset: 0; background: radial-gradient(ellipse at center, rgba(16, 185, 129, 0.2), transparent);"></div>
      <div style="position: relative; height: 100%; display: flex; flex-direction: column; justify-content: space-between; padding: 24px;">
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="padding: 6px 12px; background: rgba(255,255,255,0.1); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.2); border-radius: 9999px; font-size: 12px; font-weight: 500; color: #fff;">Wellness</span>
          <span style="padding: 6px 12px; background: rgba(255,255,255,0.1); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.2); border-radius: 9999px; font-size: 12px; font-weight: 500; color: #fff;">5 min</span>
        </div>
        <div>
          <h3 style="font-size: 24px; font-weight: 600; color: #fff; margin: 0 0 8px 0;">Morning Meditation</h3>
          <p style="color: #d1d5db; font-size: 14px; margin: 0 0 16px 0;">Start your day with calm and clarity</p>
          <button style="display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: #fff; color: #000; border: none; border-radius: 9999px; font-size: 14px; font-weight: 500; cursor: pointer;">
            <span>Start Session</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
        </div>
      </div>
      <div style="position: absolute; bottom: -40px; right: -40px; width: 160px; height: 160px; background: rgba(132, 204, 22, 0.1); border-radius: 50%; filter: blur(48px);"></div>
    </div>`
  },

  // ============================================
  // CARD 5: TEAM MEMBER
  // ============================================
  {
    id: 'card-team',
    name: 'Team Member',
    category: 'Profile',
    html: `<div style="position: relative; background: linear-gradient(135deg, #4c1d95, #7c3aed, #18181b); border-radius: 24px; overflow: hidden; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif; height: 320px;">
      <div style="position: absolute; inset: 0; background: radial-gradient(ellipse at top right, rgba(236, 72, 153, 0.2), transparent);"></div>
      <div style="position: absolute; bottom: 0; left: 0; right: 0; padding: 24px;">
        <div style="background: rgba(255,255,255,0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 20px;">
          <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #facc15, #f97316); display: flex; align-items: center; justify-content: center; color: #000; font-weight: 700;">MK</div>
            <div style="flex: 1;">
              <h4 style="font-weight: 600; color: #fff; margin: 0;">Marcus Kim</h4>
              <p style="font-size: 14px; color: #a1a1aa; margin: 0;">Lead Developer</p>
            </div>
          </div>
          <div style="display: flex; align-items: center; gap: 8px; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.1);">
            <a href="#" style="padding: 8px; border-radius: 8px; color: #a1a1aa; text-decoration: none;">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#a1a1aa"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
            </a>
            <a href="#" style="padding: 8px; border-radius: 8px; color: #a1a1aa; text-decoration: none;">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#a1a1aa"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
            </a>
            <a href="#" style="padding: 8px; border-radius: 8px; color: #a1a1aa; text-decoration: none;">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#a1a1aa"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg>
            </a>
          </div>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 6: TESTIMONIAL
  // ============================================
  {
    id: 'card-testimonial',
    name: 'Testimonial',
    category: 'Social',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="position: absolute; top: 24px; right: 24px; opacity: 0.1;">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="#fff"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
      </div>
      <div style="position: relative;">
        <div style="display: flex; gap: 4px; margin-bottom: 16px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#eab308"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#eab308"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#eab308"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#eab308"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#eab308"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
        </div>
        <p style="color: #d1d5db; margin: 0 0 24px 0; line-height: 1.6;">"The interface design is absolutely stunning. It has completely transformed how our team collaborates and ship products faster than ever before."</p>
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #22d3ee, #3b82f6);"></div>
          <div>
            <p style="font-weight: 500; color: #fff; margin: 0;">Sarah Chen</p>
            <p style="font-size: 14px; color: #71717a; margin: 0;">CTO at TechFlow</p>
          </div>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 7: CHAT MESSAGE
  // ============================================
  {
    id: 'card-chat',
    name: 'Chat Message',
    category: 'Social',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif; height: 320px;">
      <div style="display: flex; flex-direction: column; height: 100%;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.05);">
          <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #ec4899, #f43f5e);"></div>
          <div style="flex: 1;">
            <h4 style="font-weight: 500; color: #fff; margin: 0;">Emma Wilson</h4>
            <p style="font-size: 12px; color: #84cc16; margin: 0;">Online</p>
          </div>
          <button style="padding: 8px; background: transparent; border: none; cursor: pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
          </button>
        </div>
        <div style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px;">
          <div style="display: flex; justify-content: flex-end;">
            <div style="max-width: 80%; padding: 10px 16px; background: #fff; color: #000; border-radius: 16px 16px 4px 16px;">
              <p style="font-size: 14px; margin: 0;">Hey! Did you see the new design?</p>
            </div>
          </div>
          <div style="display: flex; justify-content: flex-start;">
            <div style="max-width: 80%; padding: 10px 16px; background: #27272a; color: #fff; border-radius: 16px 16px 16px 4px;">
              <p style="font-size: 14px; margin: 0;">Not yet, send it over! 🚀</p>
            </div>
          </div>
          <div style="display: flex; justify-content: flex-end;">
            <div style="max-width: 80%; padding: 10px 16px; background: linear-gradient(90deg, #84cc16, #10b981); color: #000; border-radius: 16px 16px 4px 16px;">
              <p style="font-size: 14px; font-weight: 500; margin: 0;">Just dropped the new card designs!</p>
            </div>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.05);">
          <button style="padding: 8px; background: transparent; border: none; cursor: pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
          </button>
          <input type="text" placeholder="Type a message..." style="flex: 1; padding: 10px 16px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; font-size: 14px; color: #fff; outline: none;">
          <button style="padding: 10px; background: #84cc16; border: none; border-radius: 12px; cursor: pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
          </button>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 8: MUSIC PLAYER
  // ============================================
  {
    id: 'card-music',
    name: 'Music Player',
    category: 'Media',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
        <div style="position: relative; margin-bottom: 24px;">
          <div style="width: 160px; height: 160px; border-radius: 16px; background: linear-gradient(135deg, #7c3aed, #4f46e5); box-shadow: 0 25px 50px -12px rgba(139, 92, 246, 0.25); display: flex; align-items: center; justify-content: center;">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="rgba(255,255,255,0.5)"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
          </div>
          <div style="position: absolute; bottom: -12px; right: -12px; width: 48px; height: 48px; background: #84cc16; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 25px rgba(132, 204, 22, 0.3); cursor: pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="#000"><path d="M8 5v14l11-7z"/></svg>
          </div>
        </div>
        <h3 style="font-size: 18px; font-weight: 600; color: #fff; margin: 0 0 4px 0;">Midnight Dreams</h3>
        <p style="font-size: 14px; color: #71717a; margin: 0 0 24px 0;">Luna Eclipse</p>
        <div style="width: 100%; margin-bottom: 16px;">
          <div style="display: flex; align-items: center; gap: 12px; font-size: 12px; color: #71717a; margin-bottom: 8px;">
            <span>1:24</span>
            <div style="flex: 1; height: 4px; background: #27272a; border-radius: 9999px; overflow: hidden; cursor: pointer;">
              <div style="height: 100%; width: 35%; background: linear-gradient(90deg, #84cc16, #10b981); border-radius: 9999px;"></div>
            </div>
            <span>3:45</span>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 24px;">
          <button style="padding: 8px; background: transparent; border: none; color: #a1a1aa; cursor: pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 20L9 12l10-8v16zM5 19V5h2v14H5z"/></svg>
          </button>
          <button style="padding: 16px; background: #fff; border: none; border-radius: 50%; color: #000; cursor: pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
          </button>
          <button style="padding: 8px; background: transparent; border: none; color: #a1a1aa; cursor: pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 4l10 8-10 8V4zm10 8V4h2v14h-2z"/></svg>
          </button>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 9: TIMELINE FEED
  // ============================================
  {
    id: 'card-timeline',
    name: 'Activity Timeline',
    category: 'Feed',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif; height: 320px;">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <h3 style="font-weight: 600; color: #fff; margin: 0;">Activity Feed</h3>
        <span style="font-size: 12px; color: #71717a;">Today</span>
      </div>
      <div style="position: relative; padding-left: 16px;">
        <div style="position: absolute; left: 7px; top: 8px; bottom: 8px; width: 1px; background: linear-gradient(to bottom, #84cc16, transparent);"></div>
        <div style="position: relative; display: flex; gap: 16px; margin-bottom: 20px;">
          <div style="position: relative; z-index: 1; width: 16px; height: 16px; border-radius: 50%; background: #84cc16; border: 4px solid #18181b;"></div>
          <div style="flex: 1;">
            <p style="font-size: 14px; color: #fff; margin: 0;">New project created</p>
            <p style="font-size: 12px; color: #71717a; margin: 4px 0 0 0;">2 hours ago</p>
          </div>
        </div>
        <div style="position: relative; display: flex; gap: 16px; margin-bottom: 20px;">
          <div style="position: relative; z-index: 1; width: 16px; height: 16px; border-radius: 50%; background: #3b82f6; border: 4px solid #18181b;"></div>
          <div style="flex: 1;">
            <p style="font-size: 14px; color: #fff; margin: 0;">Sarah joined the team</p>
            <p style="font-size: 12px; color: #71717a; margin: 4px 0 0 0;">4 hours ago</p>
          </div>
        </div>
        <div style="position: relative; display: flex; gap: 16px; margin-bottom: 20px;">
          <div style="position: relative; z-index: 1; width: 16px; height: 16px; border-radius: 50%; background: #52525b; border: 4px solid #18181b;"></div>
          <div style="flex: 1;">
            <p style="font-size: 14px; color: #fff; margin: 0;">Deployment completed</p>
            <p style="font-size: 12px; color: #71717a; margin: 4px 0 0 0;">6 hours ago</p>
          </div>
        </div>
        <div style="position: relative; display: flex; gap: 16px;">
          <div style="position: relative; z-index: 1; width: 16px; height: 16px; border-radius: 50%; background: #ec4899; border: 4px solid #18181b;"></div>
          <div style="flex: 1;">
            <p style="font-size: 14px; color: #fff; margin: 0;">Design review scheduled</p>
            <p style="font-size: 12px; color: #71717a; margin: 4px 0 0 0;">Yesterday</p>
          </div>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 10: PRODUCT CARD
  // ============================================
  {
    id: 'card-product',
    name: 'Product Card',
    category: 'E-Commerce',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="position: relative; height: 160px; margin: -24px -24px 16px -24px; background: linear-gradient(135deg, #27272a, #18181b); border-radius: 24px 24px 0 0; overflow: hidden;">
        <div style="position: absolute; inset: 0; background: radial-gradient(circle at 30% 30%, rgba(139, 92, 246, 0.3), transparent);"></div>
        <div style="position: absolute; top: 16px; right: 16px;">
          <button style="padding: 8px; background: rgba(255,255,255,0.1); backdrop-filter: blur(12px); border: none; border-radius: 50%; cursor: pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
          </button>
        </div>
        <div style="position: absolute; bottom: -16px; left: 50%; transform: translateX(-50%);">
          <div style="width: 96px; height: 96px; background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); transform: rotate(12deg);"></div>
        </div>
      </div>
      <h3 style="font-weight: 600; color: #fff; margin: 0 0 4px 0;">Premium Headphones</h3>
      <p style="font-size: 14px; color: #71717a; margin: 0 0 16px 0;">Wireless noise cancelling</p>
      <div style="display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="font-size: 20px; font-weight: 700; color: #fff;">$299</span>
          <span style="font-size: 14px; color: #71717a; text-decoration: line-through;">$399</span>
        </div>
        <button style="padding: 10px 16px; background: #fff; border: none; border-radius: 12px; font-size: 14px; font-weight: 500; color: #000; cursor: pointer;">Add to Cart</button>
      </div>
    </div>`
  },

  // ============================================
  // CARD 11: PRICING PLAN
  // ============================================
  {
    id: 'card-pricing',
    name: 'Pricing Plan',
    category: 'Pricing',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #84cc16, #10b981, #22d3ee); border-radius: 24px 24px 0 0;"></div>
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
        <span style="font-size: 14px; font-weight: 500; color: #a1a1aa;">Pro Plan</span>
        <span style="padding: 4px 8px; background: rgba(132, 204, 22, 0.1); color: #84cc16; font-size: 12px; font-weight: 500; border-radius: 9999px;">Most Popular</span>
      </div>
      <div style="display: flex; align-items: baseline; gap: 4px; margin-bottom: 24px;">
        <span style="font-size: 36px; font-weight: 700; color: #fff;">$29</span>
        <span style="color: #71717a;">/month</span>
      </div>
      <ul style="list-style: none; padding: 0; margin: 0 0 24px 0;">
        <li style="display: flex; align-items: center; gap: 12px; font-size: 14px; margin-bottom: 12px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#84cc16" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span style="color: #d1d5db;">Unlimited projects</span>
        </li>
        <li style="display: flex; align-items: center; gap: 12px; font-size: 14px; margin-bottom: 12px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#84cc16" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span style="color: #d1d5db;">Advanced analytics</span>
        </li>
        <li style="display: flex; align-items: center; gap: 12px; font-size: 14px; margin-bottom: 12px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#84cc16" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span style="color: #d1d5db;">Priority support</span>
        </li>
        <li style="display: flex; align-items: center; gap: 12px; font-size: 14px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#84cc16" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span style="color: #d1d5db;">Team collaboration</span>
        </li>
      </ul>
      <button style="width: 100%; padding: 12px; background: linear-gradient(90deg, #84cc16, #10b981); border: none; border-radius: 12px; font-size: 14px; font-weight: 600; color: #000; cursor: pointer;">Get Started</button>
    </div>`
  },

  // ============================================
  // CARD 12: CHECKOUT FORM
  // ============================================
  {
    id: 'card-checkout',
    name: 'Checkout Form',
    category: 'E-Commerce',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <h3 style="font-weight: 600; color: #fff; margin: 0 0 24px 0;">Payment Details</h3>
      <div style="margin-bottom: 24px;">
        <div style="margin-bottom: 16px;">
          <label style="display: block; font-size: 12px; color: #71717a; margin-bottom: 8px;">Card Number</label>
          <input type="text" placeholder="1234 5678 9012 3456" style="width: 100%; padding: 12px 16px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; font-size: 14px; color: #fff; outline: none; box-sizing: border-box;">
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
          <div>
            <label style="display: block; font-size: 12px; color: #71717a; margin-bottom: 8px;">Expiry Date</label>
            <input type="text" placeholder="MM/YY" style="width: 100%; padding: 12px 16px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; font-size: 14px; color: #fff; outline: none; box-sizing: border-box;">
          </div>
          <div>
            <label style="display: block; font-size: 12px; color: #71717a; margin-bottom: 8px;">CVC</label>
            <input type="text" placeholder="123" style="width: 100%; padding: 12px 16px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; font-size: 14px; color: #fff; outline: none; box-sizing: border-box;">
          </div>
        </div>
        <div>
          <label style="display: block; font-size: 12px; color: #71717a; margin-bottom: 8px;">Cardholder Name</label>
          <input type="text" placeholder="John Doe" style="width: 100%; padding: 12px 16px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; font-size: 14px; color: #fff; outline: none; box-sizing: border-box;">
        </div>
      </div>
      <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 0; border-top: 1px solid rgba(255,255,255,0.05); margin-bottom: 24px;">
        <span style="font-size: 14px; color: #a1a1aa;">Total</span>
        <span style="font-size: 20px; font-weight: 700; color: #fff;">$129.00</span>
      </div>
      <button style="width: 100%; padding: 12px; background: #fff; border: none; border-radius: 12px; font-size: 14px; font-weight: 600; color: #000; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Pay $129.00
      </button>
    </div>`
  },

  // ============================================
  // CARD 13: NOTIFICATION
  // ============================================
  {
    id: 'card-notification',
    name: 'Notification',
    category: 'Alert',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="display: flex; align-items: flex-start; gap: 16px;">
        <div style="position: relative;">
          <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(59, 130, 246, 0.2); display: flex; align-items: center; justify-content: center;">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          </div>
          <span style="position: absolute; top: -4px; right: -4px; width: 20px; height: 20px; background: #ef4444; border-radius: 50%; border: 2px solid #18181b;"></span>
        </div>
        <div style="flex: 1;">
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
            <h4 style="font-weight: 500; color: #fff; margin: 0;">New Message</h4>
            <span style="font-size: 12px; color: #71717a;">2m ago</span>
          </div>
          <p style="font-size: 14px; color: #a1a1aa; margin: 0 0 12px 0;">Sarah sent you a file in "Project Alpha"</p>
          <div style="display: flex; align-items: center; gap: 8px;">
            <button style="padding: 6px 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; font-size: 12px; font-weight: 500; color: #fff; cursor: pointer;">View</button>
            <button style="padding: 6px 12px; background: transparent; border: none; border-radius: 8px; font-size: 12px; font-weight: 500; color: #a1a1aa; cursor: pointer;">Dismiss</button>
          </div>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 14: CALENDAR EVENT
  // ============================================
  {
    id: 'card-calendar',
    name: 'Calendar Event',
    category: 'Schedule',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="display: flex; gap: 16px;">
        <div style="flex-shrink: 0; width: 64px; height: 64px; background: rgba(239, 68, 68, 0.2); border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #ef4444;">
          <span style="font-size: 12px; font-weight: 500; text-transform: uppercase;">Dec</span>
          <span style="font-size: 20px; font-weight: 700;">15</span>
        </div>
        <div style="flex: 1;">
          <h3 style="font-weight: 600; color: #fff; margin: 0 0 4px 0;">Design Review Meeting</h3>
          <p style="font-size: 14px; color: #71717a; margin: 0 0 12px 0;">10:00 AM - 11:30 AM</p>
          <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #a1a1aa;">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <span>Zoom</span>
            </div>
          </div>
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex;">
              <div style="width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, #ec4899, #f43f5e); border: 2px solid #18181b; margin-right: -8px;"></div>
              <div style="width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, #22d3ee, #3b82f6); border: 2px solid #18181b; margin-right: -8px;"></div>
              <div style="width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, #fbbf24, #f97316); border: 2px solid #18181b;"></div>
            </div>
            <button style="padding: 6px 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; cursor: pointer;">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </button>
          </div>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 15: SETTINGS TOGGLE
  // ============================================
  {
    id: 'card-settings',
    name: 'Settings Toggle',
    category: 'Settings',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <h3 style="font-weight: 600; color: #fff; margin: 0 0 24px 0;">Settings</h3>
      <div>
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
          <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #27272a; display: flex; align-items: center; justify-content: center;">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
            </div>
            <div>
              <p style="font-size: 14px; font-weight: 500; color: #fff; margin: 0;">Dark Mode</p>
              <p style="font-size: 12px; color: #71717a; margin: 0;">Use dark theme</p>
            </div>
          </div>
          <div style="position: relative; width: 48px; height: 24px; background: #84cc16; border-radius: 9999px; cursor: pointer;">
            <div style="position: absolute; top: 2px; right: 2px; width: 20px; height: 20px; background: #fff; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></div>
          </div>
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
          <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #27272a; display: flex; align-items: center; justify-content: center;">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </div>
            <div>
              <p style="font-size: 14px; font-weight: 500; color: #fff; margin: 0;">Notifications</p>
              <p style="font-size: 12px; color: #71717a; margin: 0;">Push notifications</p>
            </div>
          </div>
          <div style="position: relative; width: 48px; height: 24px; background: #84cc16; border-radius: 9999px; cursor: pointer;">
            <div style="position: absolute; top: 2px; right: 2px; width: 20px; height: 20px; background: #fff; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></div>
          </div>
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 0;">
          <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #27272a; display: flex; align-items: center; justify-content: center;">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </div>
            <div>
              <p style="font-size: 14px; font-weight: 500; color: #fff; margin: 0;">Public Profile</p>
              <p style="font-size: 12px; color: #71717a; margin: 0;">Visible to everyone</p>
            </div>
          </div>
          <div style="position: relative; width: 48px; height: 24px; background: #3f3f46; border-radius: 9999px; cursor: pointer;">
            <div style="position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; background: #fff; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></div>
          </div>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 16: ACHIEVEMENT BADGE
  // ============================================
  {
    id: 'card-achievement',
    name: 'Achievement Badge',
    category: 'Gamification',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
        <div style="position: relative; margin-bottom: 16px;">
          <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #fbbf24, #f97316); display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 25px rgba(251, 191, 36, 0.3);">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
          </div>
          <div style="position: absolute; bottom: -4px; right: -4px; width: 32px; height: 32px; background: #84cc16; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 4px solid #18181b;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
          </div>
        </div>
        <h3 style="font-size: 18px; font-weight: 600; color: #fff; margin: 0 0 4px 0;">Power User</h3>
        <p style="font-size: 14px; color: #71717a; margin: 0 0 16px 0;">Unlocked 50+ features</p>
        <div style="width: 100%; margin-bottom: 16px;">
          <div style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; margin-bottom: 8px;">
            <span style="color: #71717a;">Next milestone</span>
            <span style="color: #fbbf24;">85%</span>
          </div>
          <div style="height: 8px; background: #27272a; border-radius: 9999px; overflow: hidden;">
            <div style="height: 100%; width: 85%; background: linear-gradient(90deg, #fbbf24, #f97316); border-radius: 9999px;"></div>
          </div>
        </div>
        <p style="font-size: 12px; color: #71717a; margin: 0;">15 more to reach "Master"</p>
      </div>
    </div>`
  },

  // ============================================
  // CARD 17: WEATHER WIDGET
  // ============================================
  {
    id: 'card-weather',
    name: 'Weather Widget',
    category: 'Widget',
    html: `<div style="position: relative; background: linear-gradient(135deg, #1e3a8a, #312e81, #18181b); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif; overflow: hidden;">
      <div style="position: absolute; top: 0; right: 0; width: 160px; height: 160px; background: rgba(59, 130, 246, 0.2); border-radius: 50%; filter: blur(48px);"></div>
      <div style="position: absolute; bottom: 0; left: 0; width: 128px; height: 128px; background: rgba(34, 211, 238, 0.2); border-radius: 50%; filter: blur(32px);"></div>
      <div style="position: relative;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
          <div>
            <p style="font-size: 14px; color: #a1a1aa; margin: 0;">San Francisco</p>
            <p style="font-size: 12px; color: #71717a; margin: 0;">Monday, Dec 15</p>
          </div>
          <button style="padding: 8px; background: transparent; border: none; cursor: pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
          </button>
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
          <div style="display: flex; align-items: center; gap: 16px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="1.5"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
            <div>
              <p style="font-size: 48px; font-weight: 700; color: #fff; margin: 0;">68°</p>
              <p style="font-size: 14px; color: #93c5fd; margin: 0;">Partly Cloudy</p>
            </div>
          </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: rgba(255,255,255,0.05); border-radius: 12px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>
            <span style="font-size: 14px; color: #fff;">62%</span>
          </div>
          <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: rgba(255,255,255,0.05); border-radius: 12px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
            <span style="font-size: 14px; color: #fff;">12 mph</span>
          </div>
        </div>
      </div>
    </div>`
  },

  // ============================================
  // CARD 18: STATS CARD
  // ============================================
  {
    id: 'card-stats',
    name: 'Stats Revenue',
    category: 'Stats',
    html: `<div style="position: relative; background: rgba(24, 24, 27, 0.6); border-radius: 24px; padding: 24px; border: 1px solid rgba(255,255,255,0.05); font-family: Inter, system-ui, sans-serif;">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
        <span style="font-size: 14px; font-weight: 500; color: #a1a1aa;">Total Revenue</span>
        <div style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; background: rgba(132, 204, 22, 0.1); border-radius: 9999px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#84cc16" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
          <span style="font-size: 12px; color: #84cc16; font-weight: 500;">+24%</span>
        </div>
      </div>
      <div style="display: flex; align-items: flex-end; gap: 8px; margin-bottom: 16px;">
        <span style="font-size: 36px; font-weight: 700; background: linear-gradient(135deg, #fff 0%, #a1a1aa 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">$847,291</span>
        <span style="font-size: 14px; color: #71717a; margin-bottom: 4px;">this month</span>
      </div>
      <div style="position: relative; height: 48px; margin-bottom: 16px;">
        <svg style="width: 100%; height: 100%;" viewBox="0 0 200 50" preserveAspectRatio="none">
          <defs>
            <linearGradient id="sparkGradient" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stop-color="#84cc16"/>
              <stop offset="100%" stop-color="#22d3ee"/>
            </linearGradient>
          </defs>
          <path d="M0,40 Q20,35 40,38 T80,30 T120,25 T160,15 T200,10" fill="none" stroke="url(#sparkGradient)" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <div style="position: absolute; bottom: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, rgba(132, 204, 22, 0.5), rgba(34, 211, 238, 0.5));"></div>
      </div>
      <div style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: #71717a; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.05);">
        <span>Last 30 days</span>
        <span>Dec 15, 2024</span>
      </div>
    </div>`
  },
]

// Export card categories for filtering
export const cardCategories = [
  'All',
  'Stats',
  'Profile',
  'Task',
  'Feature',
  'Social',
  'Media',
  'Feed',
  'E-Commerce',
  'Pricing',
  'Alert',
  'Schedule',
  'Settings',
  'Gamification',
  'Widget',
]
