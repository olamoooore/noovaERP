// Footer Presets - 12 Diverse Footer Designs

export interface Preset {
  id: string
  label: string
  category: string
  content: string
}

export const footerPresets: Preset[] = [

  // 1. AURA Studio - Dark Glass with Newsletter
  {
    id: 'footer-aura-studio',
    label: 'AURA Studio',
    category: 'Footers',
    content: `
      <div style="position: relative; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; margin-bottom: 32px; color: #ffffff; background: #09090b;">
        <div style="position: absolute; inset: 0; background: linear-gradient(to bottom right, rgba(255,255,255,0.05), transparent, rgba(255,255,255,0.1));"></div>

        <div style="position: relative; padding: 48px 56px;">
          <!-- Top -->
          <div style="display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 40px;">
            <div style="max-width: 448px;">
              <a href="#" style="display: inline-flex; align-items: center; gap: 12px; text-decoration: none; color: #ffffff;">
                <span style="display: inline-flex; height: 36px; width: 36px; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,0.1); border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,0.05); color: #18181b; background: #ffffff;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle></svg>
                </span>
                <span style="font-size: 16px; font-weight: 500; letter-spacing: -0.025em;">AURA</span>
              </a>
              <p style="margin-top: 16px; font-size: 14px; line-height: 1.6; color: rgba(255,255,255,0.7);">
                We design brands and products that move people to act. Strategy, systems, and craft—delivered with clarity.
              </p>

              <form style="margin-top: 24px; display: flex; align-items: center; gap: 8px;">
                <div style="flex: 1;">
                  <input type="email" placeholder="Your email" style="width: 100%; border-radius: 9999px; padding: 12px 16px; font-size: 14px; border: 1px solid rgba(255,255,255,0.1); outline: none; background: rgba(255,255,255,0.05); color: #ffffff;">
                </div>
                <button type="submit" style="display: inline-flex; align-items: center; gap: 8px; border-radius: 9999px; padding: 12px 16px; font-size: 14px; border: 1px solid rgba(255,255,255,0.1); background: #ffffff; color: #18181b; cursor: pointer;">
                  Subscribe
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"></path><path d="m21.854 2.147-10.94 10.939"></path></svg>
                </button>
              </form>
              <p style="margin-top: 8px; font-size: 12px; color: rgba(255,255,255,0.6);">Monthly updates. No spam.</p>
            </div>

            <!-- Links -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px;">
              <div>
                <p style="font-size: 14px; font-weight: 500; letter-spacing: -0.025em; color: rgba(255,255,255,0.8); margin: 0;">Work</p>
                <ul style="margin-top: 12px; list-style: none; padding: 0; display: flex; flex-direction: column; gap: 8px; font-size: 14px; color: rgba(255,255,255,0.6);">
                  <li><a href="#" style="color: rgba(255,255,255,0.6); text-decoration: none;">Case Studies</a></li>
                  <li><a href="#" style="color: rgba(255,255,255,0.6); text-decoration: none;">Awards</a></li>
                  <li><a href="#" style="color: rgba(255,255,255,0.6); text-decoration: none;">Clients</a></li>
                </ul>
              </div>
              <div>
                <p style="font-size: 14px; font-weight: 500; letter-spacing: -0.025em; color: rgba(255,255,255,0.8); margin: 0;">Services</p>
                <ul style="margin-top: 12px; list-style: none; padding: 0; display: flex; flex-direction: column; gap: 8px; font-size: 14px; color: rgba(255,255,255,0.6);">
                  <li><a href="#" style="color: rgba(255,255,255,0.6); text-decoration: none;">Brand &amp; Identity</a></li>
                  <li><a href="#" style="color: rgba(255,255,255,0.6); text-decoration: none;">Product Design</a></li>
                  <li><a href="#" style="color: rgba(255,255,255,0.6); text-decoration: none;">Motion &amp; Launch</a></li>
                </ul>
              </div>
              <div>
                <p style="font-size: 14px; font-weight: 500; letter-spacing: -0.025em; color: rgba(255,255,255,0.8); margin: 0;">Resources</p>
                <ul style="margin-top: 12px; list-style: none; padding: 0; display: flex; flex-direction: column; gap: 8px; font-size: 14px; color: rgba(255,255,255,0.6);">
                  <li><a href="#" style="color: rgba(255,255,255,0.6); text-decoration: none;">Newsletter</a></li>
                  <li><a href="#" style="color: rgba(255,255,255,0.6); text-decoration: none;">Playbook</a></li>
                  <li><a href="#" style="color: rgba(255,255,255,0.6); text-decoration: none;">Contact</a></li>
                </ul>
              </div>
            </div>
          </div>

          <!-- Bottom -->
          <div style="margin-top: 40px; padding-top: 24px; border-top: 1px solid rgba(255,255,255,0.1); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
            <p style="font-size: 12px; color: rgba(255,255,255,0.6); margin: 0;">© 2025 AURA Studio. All rights reserved.</p>
            <div style="display: flex; align-items: center; gap: 8px;">
              <a href="#" style="display: inline-flex; height: 32px; width: 32px; align-items: center; justify-content: center; border-radius: 50%; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05); text-decoration: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect width="20" height="20" x="2" y="2" rx="5" ry="5"></rect>
                  <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                  <line x1="17.5" x2="17.51" y1="6.5" y2="6.5"></line>
                </svg>
              </a>
              <a href="#" style="display: inline-flex; height: 32px; width: 32px; align-items: center; justify-content: center; border-radius: 50%; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05); text-decoration: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z"></path>
                </svg>
              </a>
              <a href="#" style="display: inline-flex; height: 32px; width: 32px; align-items: center; justify-content: center; border-radius: 50%; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05); text-decoration: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"></path>
                  <rect width="4" height="12" x="2" y="9"></rect>
                  <circle cx="4" cy="4" r="2"></circle>
                </svg>
              </a>
            </div>
          </div>
        </div>
      </div>
    `
  },

  

  // 2. Studio Meridian - Dark with Big Typography CTA
  {
    id: 'footer-studio-meridian',
    label: 'Studio Meridian',
    category: 'Footers',
    content: `
      <div style="max-width: 1280px; margin: 32px auto 64px auto; padding: 0 16px;">
        <div style="position: relative; overflow: hidden; border-radius: 40px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 8px 30px rgba(0,0,0,0.18); padding: 32px; background: #0a0a0a; color: #ffffff;">
          <div style="position: absolute; inset: 0; pointer-events: none;">
            <div style="position: absolute; inset: 0; background: radial-gradient(1200px 600px at 20% -20%, rgba(255,255,255,0.06), transparent 60%);"></div>
            <div style="position: absolute; inset: 0; background: radial-gradient(1200px 600px at 80% 120%, rgba(255,255,255,0.05), transparent 60%);"></div>
          </div>

          <div style="position: relative;">
            <h2 style="font-size: 72px; line-height: 0.9; font-weight: 600; letter-spacing: -0.05em; margin: 0;">
              <span style="display: block;">Ready to build</span>
              <span style="display: block; color: rgba(255,255,255,0.6);">something extraordinary?</span>
            </h2>

            <div style="margin-top: 32px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px;">
              <!-- Email -->
              <div>
                <p style="font-size: 14px; color: rgba(255,255,255,0.6); margin: 0;">Get Started</p>
                <a href="mailto:hello@studiomeridian.co" style="margin-top: 8px; display: inline-flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 500; letter-spacing: -0.025em; color: #ffffff; text-decoration: none;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"></path>
                    <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                  </svg>
                  <span>hello@studiomeridian.co</span>
                </a>
              </div>

              <!-- Call -->
              <div>
                <p style="font-size: 14px; color: rgba(255,255,255,0.6); margin: 0;">Schedule a Call</p>
                <a href="#contact" style="display: inline-flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; letter-spacing: -0.025em; border: 1px solid rgba(255,255,255,0.1); border-radius: 9999px; margin-top: 8px; padding: 12px 20px; color: #111827; background: #ffffff; text-decoration: none;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 2v4"></path>
                    <path d="M16 2v4"></path>
                    <rect width="18" height="18" x="3" y="4" rx="2"></rect>
                    <path d="M3 10h18"></path>
                  </svg>
                  <span>Book a Meeting</span>
                </a>
              </div>

              <!-- Social -->
              <div>
                <p style="font-size: 14px; color: rgba(255,255,255,0.6); margin: 0;">Follow Along</p>
                <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 8px; align-items: center;">
                  <a href="#" style="display: inline-flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; letter-spacing: -0.025em; border: 1px solid rgba(255,255,255,0.1); border-radius: 9999px; padding: 12px 16px; color: #111827; background: #ffffff; text-decoration: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z"></path>
                    </svg>
                    <span>2,843</span>
                  </a>
                  <a href="#" style="display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.1); background: #ffffff; color: #111827; text-decoration: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                      <rect width="20" height="20" x="2" y="2" rx="5" ry="5"></rect>
                      <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                      <line x1="17.5" x2="17.51" y1="6.5" y2="6.5"></line>
                    </svg>
                  </a>
                  <a href="#" style="display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.1); background: #ffffff; color: #111827; text-decoration: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                      <circle cx="12" cy="12" r="10"></circle>
                      <path d="M19.13 5.09C15.22 9.14 10 10.44 2.25 10.94"></path>
                      <path d="M21.75 12.84c-6.62-1.41-12.14 1-16.38 6.32"></path>
                      <path d="M8.56 2.75c4.37 6 6 9.42 8 17.72"></path>
                    </svg>
                  </a>
                  <a href="#" style="display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.1); background: #ffffff; color: #111827; text-decoration: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"></path>
                      <rect width="4" height="12" x="2" y="9"></rect>
                      <circle cx="4" cy="4" r="2"></circle>
                    </svg>
                  </a>
                </div>
              </div>
            </div>

            <div style="margin-top: 32px; border-top: 1px solid rgba(255,255,255,0.1);"></div>

            <div style="margin-top: 24px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px;">
              <!-- Menu -->
              <div>
                <p style="font-size: 14px; color: rgba(255,255,255,0.6); margin: 0;">Explore</p>
                <div style="margin-top: 12px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; font-size: 14px;">
                  <a href="#work" style="font-weight: 500; letter-spacing: -0.025em; color: #ffffff; text-decoration: none;">Our Work</a>
                  <a href="#services" style="font-weight: 500; letter-spacing: -0.025em; color: #ffffff; text-decoration: none;">What We Do</a>
                  <a href="#pricing" style="font-weight: 500; letter-spacing: -0.025em; color: #ffffff; text-decoration: none;">Investment</a>
                  <a href="#blog" style="font-weight: 500; letter-spacing: -0.025em; color: #ffffff; text-decoration: none;">Insights</a>
                </div>
              </div>

              <!-- Legal -->
              <div>
                <p style="font-size: 14px; color: rgba(255,255,255,0.6); margin: 0;">Fine Print</p>
                <div style="margin-top: 12px; display: grid; grid-template-columns: 1fr; gap: 8px; font-size: 14px;">
                  <a href="#" style="font-weight: 500; letter-spacing: -0.025em; color: #ffffff; text-decoration: none;">Terms &amp; Conditions</a>
                  <a href="#" style="font-weight: 500; letter-spacing: -0.025em; color: #ffffff; text-decoration: none;">Privacy Notice</a>
                </div>
              </div>
            </div>

            <p style="margin-top: 24px; text-align: center; font-size: 12px; color: rgba(255,255,255,0.7);">© 2025 Studio Meridian — Crafted with care in San Francisco</p>
          </div>
        </div>
      </div>
    `
  },

  
  // 3. Kreona Studio - Light with Big Typography
  {
    id: 'footer-kreona-studio',
    label: 'Kreona Studio',
    category: 'Footers',
    content: `
      <footer style="border-top: 1px solid rgba(0,0,0,0.05); padding: 80px 0; background: #ffffff;">
        <div style="max-width: 1280px; margin: 0 auto; padding: 0 24px;">
          <div style="display: grid; grid-template-columns: 1fr; gap: 48px; margin-bottom: 64px; width: 100%;">
            <!-- Branding Header -->
            <div style="display: flex; gap: 20px; padding-bottom: 16px; align-items: baseline;">
              <h1 style="font-size: 144px; line-height: 0.8; letter-spacing: -0.05em; font-weight: 500; color: #000000; margin: 0;">
                kreona
              </h1>
              <span style="font-size: 24px; font-weight: 400; letter-spacing: -0.025em; position: relative; top: -12px; color: #52525b;">
                studio
              </span>
            </div>

            <!-- Divider -->
            <div style="width: 100%; height: 1px; background: rgba(0,0,0,0.1);"></div>

            <!-- Content Area -->
            <div style="display: grid; grid-template-columns: 5fr 7fr; gap: 48px; margin-top: 8px;">
              <!-- CTA Section -->
              <div style="display: flex; flex-direction: column; align-items: flex-start; justify-content: space-between; gap: 40px;">
                <p style="font-size: 18px; font-weight: 300; line-height: 1.6; max-width: 448px; color: #52525b; margin: 0;">
                  Let's build something great together — whether you have a project in mind or just want to connect, we'd love to hear from you.
                </p>

                <div style="display: flex; align-items: center; gap: 16px;">
                  <button style="padding: 16px 32px; border-radius: 8px; font-size: 14px; font-weight: 500; background: #3f3f46; color: #fafafa; border: none; cursor: pointer;">
                    Book a 15-min call
                  </button>
                  <button style="width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #3f3f46; color: #fafafa; border: none; cursor: pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M7 7h10v10"></path>
                      <path d="M7 17 17 7"></path>
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Links Grid -->
              <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 40px; padding-left: 40px; width: 100%;">
                <!-- Column 1 -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                  <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.2em; color: #71717a; font-weight: 500;">
                    Sitemap
                  </span>
                  <ul style="display: flex; flex-direction: column; gap: 16px; list-style: none; padding: 0; margin: 0;">
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">Home</a></li>
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">About</a></li>
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">Works</a></li>
                  </ul>
                </div>

                <!-- Column 2 -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                  <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.2em; color: #71717a; font-weight: 500;">
                    Recent Works
                  </span>
                  <ul style="display: flex; flex-direction: column; gap: 16px; list-style: none; padding: 0; margin: 0;">
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">Space</a></li>
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">Botic</a></li>
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">Run</a></li>
                  </ul>
                </div>

                <!-- Column 3 -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                  <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.2em; color: #71717a; font-weight: 500;">
                    Social
                  </span>
                  <ul style="display: flex; flex-direction: column; gap: 16px; list-style: none; padding: 0; margin: 0;">
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">Instagram</a></li>
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">LinkedIn</a></li>
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">Behance</a></li>
                  </ul>
                </div>

                <!-- Column 4 -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                  <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.2em; color: #71717a; font-weight: 500;">
                    Other
                  </span>
                  <ul style="display: flex; flex-direction: column; gap: 16px; list-style: none; padding: 0; margin: 0;">
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">Buy template</a></li>
                    <li><a href="#" style="font-size: 14px; font-weight: 400; color: #52525b; text-decoration: none;">View More</a></li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <div style="display: flex; flex-wrap: wrap; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 32px; gap: 16px; align-items: center; justify-content: space-between;">
            <p style="font-size: 12px; font-weight: 500; color: #a1a1aa; margin: 0;">
              © 2024 Kreona Studio. All rights reserved.
            </p>
            <div style="display: flex; gap: 24px;">
              <a href="#" style="color: #71717a; text-decoration: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"></path>
                </svg>
              </a>
              <a href="#" style="color: #71717a; text-decoration: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"></path>
                </svg>
              </a>
              <a href="#" style="color: #71717a; text-decoration: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073z"></path>
                </svg>
              </a>
            </div>
          </div>
        </div>
      </footer>
    `
  },


  // 4. StackFoundry - Dark Glass with Newsletter
  {
    id: 'footer-stackfoundry',
    label: 'StackFoundry',
    category: 'Footers',
    content: `
      <footer style="max-width: 1280px; margin: 0 auto; padding-bottom: 48px; background: #0a0a0a;">
        <div style="padding: 48px 64px; border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; background: rgba(255,255,255,0.03); backdrop-filter: blur(24px);">
          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 48px; margin-bottom: 64px;">
            <div>
              <div style="display: flex; align-items: center; margin-bottom: 24px;">
                <span style="display: inline-flex; height: 32px; width: 32px; align-items: center; justify-content: center; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); margin-right: 12px; background: #262626;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d4d4d4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="3" rx="2"></rect>
                    <path d="M3 9h18"></path>
                    <path d="M3 15h18"></path>
                    <path d="M9 3v18"></path>
                    <path d="M15 3v18"></path>
                  </svg>
                </span>
                <span style="font-size: 20px; font-weight: 600; letter-spacing: -0.025em; color: #ffffff;">StackFoundry</span>
              </div>
              <p style="font-size: 14px; line-height: 1.6; margin-bottom: 32px; color: rgba(255,255,255,0.6);">
                Experiment, explore, and choose your next tool stack. We help designers discover the best modern tools through hands-on trials and expert curation.
              </p>
              <div style="display: flex; align-items: center; gap: 16px;">
                <a href="#" style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.03); text-decoration: none;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z"></path>
                  </svg>
                </a>
                <a href="#" style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.03); text-decoration: none;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"></path>
                    <rect width="4" height="12" x="2" y="9"></rect>
                    <circle cx="4" cy="4" r="2"></circle>
                  </svg>
                </a>
                <a href="#" style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.03); text-decoration: none;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"></path>
                    <path d="M9 18c-4.51 2-5-2-7-2"></path>
                  </svg>
                </a>
              </div>
            </div>
            <div>
              <h4 style="font-size: 14px; font-weight: 500; margin: 0 0 24px 0; text-transform: uppercase; letter-spacing: 0.05em; color: #ffffff;">Resources</h4>
              <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 16px;">
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Design Kits</a></li>
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">AI Workflows</a></li>
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Tutorials</a></li>
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Guides</a></li>
              </ul>
            </div>
            <div>
              <h4 style="font-size: 14px; font-weight: 500; margin: 0 0 24px 0; text-transform: uppercase; letter-spacing: 0.05em; color: #ffffff;">Content</h4>
              <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 16px;">
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Articles</a></li>
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Episodes</a></li>
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Tool Reviews</a></li>
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Case Studies</a></li>
              </ul>
            </div>
            <div>
              <h4 style="font-size: 14px; font-weight: 500; margin: 0 0 24px 0; text-transform: uppercase; letter-spacing: 0.05em; color: #ffffff;">Support</h4>
              <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 16px;">
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Help Center</a></li>
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Contact Us</a></li>
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Bundle Support</a></li>
                <li><a href="#" style="font-size: 14px; color: rgba(255,255,255,0.6); text-decoration: none;">Terms &amp; Conditions</a></li>
              </ul>
            </div>
          </div>
          <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 48px; margin-bottom: 48px;">
            <div style="max-width: 672px; margin: 0 auto; text-align: center;">
              <h4 style="font-size: 18px; font-weight: 500; margin: 0 0 16px 0; color: #ffffff;">Stay in the Loop</h4>
              <p style="font-size: 14px; margin: 0 0 24px 0; color: rgba(255,255,255,0.6);">Get notified about new tool bundles, exclusive deals, and design workflow insights.</p>
              <div style="display: flex; flex-wrap: wrap; gap: 16px; justify-content: center;">
                <input type="email" placeholder="Enter your email address" style="flex: 1; max-width: 448px; padding: 12px 16px; border-radius: 12px; font-size: 14px; border: 1px solid rgba(255,255,255,0.1); outline: none; color: #ffffff; background: rgba(255,255,255,0.05);">
                <button style="padding: 12px 32px; border-radius: 12px; font-size: 14px; font-weight: 500; color: #171717; background: #e879f9; border: none; cursor: pointer;">Subscribe</button>
              </div>
            </div>
          </div>
        </div>
        <div style="padding-top: 32px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 24px;">
          <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 24px; font-size: 12px; color: rgba(255,255,255,0.4);">
            <span>© 2024 StackFoundry. All rights reserved.</span>
            <a href="#" style="color: rgba(255,255,255,0.4); text-decoration: none;">Privacy Policy</a>
            <a href="#" style="color: rgba(255,255,255,0.4); text-decoration: none;">Terms of Service</a>
          </div>
          <div style="display: flex; align-items: center; gap: 16px; font-size: 12px; color: rgba(255,255,255,0.4);">
            <span style="display: flex; align-items: center; gap: 8px;">
              Bundle Limited
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#e879f9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="m9 12 2 2 4-4"></path>
              </svg>
            </span>
            <span style="display: flex; align-items: center; gap: 8px;">
              Trusted Partners
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path>
                <path d="m9 12 2 2 4-4"></path>
              </svg>
            </span>
          </div>
        </div>
      </footer>
    `
  },
  // 13. The Titan (Branding Focused)
  {
    id: 'footer-titan-branding',
    label: 'The Titan',
    category: 'Footers',
    content: `
      <footer style="background-color: #050505; color: #fff; padding: 80px 40px 40px; font-family: 'Manrope', sans-serif; overflow: hidden;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 100px; max-width: 1400px; margin: 0 auto 100px;">
          <div style="display: flex; gap: 60px;">
            <div style="list-style: none;">
              <h4 style="font-size: 0.9rem; color: #666; margin-bottom: 20px; text-transform: uppercase;">Product</h4>
              <ul style="list-style: none; padding: 0;">
                <li style="margin-bottom: 12px;"><a href="#" style="color: #ccc; text-decoration: none; font-size: 1.1rem; transition: 0.2s;">Features</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #ccc; text-decoration: none; font-size: 1.1rem; transition: 0.2s;">Pricing</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #ccc; text-decoration: none; font-size: 1.1rem; transition: 0.2s;">Integrations</a></li>
              </ul>
            </div>
            <div style="list-style: none;">
              <h4 style="font-size: 0.9rem; color: #666; margin-bottom: 20px; text-transform: uppercase;">Company</h4>
              <ul style="list-style: none; padding: 0;">
                <li style="margin-bottom: 12px;"><a href="#" style="color: #ccc; text-decoration: none; font-size: 1.1rem; transition: 0.2s;">About</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #ccc; text-decoration: none; font-size: 1.1rem; transition: 0.2s;">Careers</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #ccc; text-decoration: none; font-size: 1.1rem; transition: 0.2s;">Blog</a></li>
              </ul>
            </div>
          </div>
          <div style="max-width: 400px;">
            <h3 style="font-size: 2rem; font-weight: 400; margin-bottom: 20px; color: #e5e5e5;">Ready to scale?</h3>
            <a href="#" style="color: #fff; text-decoration: none; font-size: 1.5rem; border-bottom: 1px solid #333; padding-bottom: 5px; transition: 0.3s;">Get in touch &rarr;</a>
          </div>
        </div>
        <span style="font-size: 16vw; font-weight: 800; line-height: 0.8; letter-spacing: -0.04em; text-align: center; color: #fff; margin-bottom: 40px; display: block;">DATAWIZZ</span>
        <div style="border-top: 1px solid #222; padding-top: 30px; display: flex; justify-content: space-between; color: #666; font-size: 0.9rem; max-width: 1400px; margin: 0 auto;">
          <span>&copy; 2024 Datawizz Inc.</span>
          <div style="display: flex; gap: 20px;">
            <a href="#" style="color: #666; text-decoration: none; transition: 0.2s;">Twitter</a>
            <a href="#" style="color: #666; text-decoration: none; transition: 0.2s;">LinkedIn</a>
            <a href="#" style="color: #666; text-decoration: none; transition: 0.2s;">Instagram</a>
          </div>
        </div>
      </footer>
    `
  },
  // 14. The Orbital (Newsletter Magnet)
  {
    id: 'footer-orbital-newsletter',
    label: 'The Orbital',
    category: 'Footers',
    content: `
      <footer style="background-color: #0f172a; color: #fff; font-family: 'Outfit', sans-serif; position: relative; overflow: hidden; padding: 80px 24px;">
        <div style="position: absolute; top: -50%; right: -20%; width: 800px; height: 800px; background: radial-gradient(circle, rgba(56, 189, 248, 0.1) 0%, transparent 70%); border-radius: 50%; pointer-events: none;"></div>
        <div style="max-width: 1200px; margin: 0 auto; position: relative; z-index: 2;">
          <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 60px; text-align: center; margin-bottom: 80px; backdrop-filter: blur(10px);">
            <h2 style="font-size: 2.5rem; margin-bottom: 10px;">Subscribe to our intelligence</h2>
            <p style="color: #94a3b8; margin-bottom: 30px;">Get the latest skip tracing data points directly to your inbox.</p>
            <form style="display: flex; gap: 10px; justify-content: center; max-width: 500px; margin: 0 auto;">
              <input type="email" placeholder="Enter your email address" style="background: rgba(0,0,0,0.3); border: 1px solid #334155; padding: 16px 24px; border-radius: 50px; color: #fff; width: 100%; outline: none;">
              <button type="button" style="background: #fff; color: #000; border: none; padding: 16px 32px; border-radius: 50px; font-weight: 700; cursor: pointer; transition: 0.2s; white-space: nowrap;">Get Started</button>
            </form>
          </div>
          
          <div style="display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr; gap: 40px;">
            <div>
              <h3 style="font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 10px; margin-bottom: 20px;"><i class="ri-radar-fill"></i> SKIPMATRIX</h3>
              <p style="color: #94a3b8; line-height: 1.6; max-width: 300px; font-size: 0.9rem;">Experience superior data tracing with 150+ data points per search. Built for high-volume enterprise needs.</p>
            </div>
            <div>
              <h4 style="color: #fff; margin-bottom: 20px; font-size: 1rem;">Platform</h4>
              <ul style="list-style: none; padding: 0;">
                <li style="margin-bottom: 12px;"><a href="#" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.2s;">Database</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.2s;">API Docs</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.2s;">Security</a></li>
              </ul>
            </div>
            <div>
              <h4 style="color: #fff; margin-bottom: 20px; font-size: 1rem;">Company</h4>
              <ul style="list-style: none; padding: 0;">
                <li style="margin-bottom: 12px;"><a href="#" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.2s;">About Us</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.2s;">Legal</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.2s;">Contact</a></li>
              </ul>
            </div>
            <div>
              <h4 style="color: #fff; margin-bottom: 20px; font-size: 1rem;">Legal</h4>
              <ul style="list-style: none; padding: 0;">
                <li style="margin-bottom: 12px;"><a href="#" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.2s;">Privacy</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.2s;">Terms</a></li>
                <li style="margin-bottom: 12px;"><a href="#" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.2s;">Cookies</a></li>
              </ul>
            </div>
          </div>
        </div>
      </footer>
    `
  },
  // 15. The Playground (Retro Pop)
  {
    id: 'footer-playground-retro',
    label: 'The Playground',
    category: 'Footers',
    content: `
      <footer style="background: #FF6B6B; border-top: 4px solid #000; font-family: 'Syne', sans-serif; color: #000; overflow: hidden;">
        <div style="background: #FFD93D; border-bottom: 4px solid #000; padding: 15px 0; overflow: hidden; white-space: nowrap; display: flex;">
          <div style="display: flex; gap: 40px; font-weight: 800; text-transform: uppercase; font-size: 1.2rem;">
            <span>Book a Call • Sale Now On • Fresh Designs • Book a Call • Sale Now On • Fresh Designs • Book a Call • Sale Now On • Fresh Designs •</span>
          </div>
        </div>
        <div style="max-width: 1200px; margin: 0 auto; padding: 80px 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 60px;">
          <div style="background: #fff; border: 4px solid #000; border-radius: 20px; padding: 40px; box-shadow: 10px 10px 0px #000;">
            <h2 style="font-size: 2.5rem; margin-bottom: 20px; font-weight: 800; line-height: 1;">Ready to take a bite?</h2>
            <p>From smashed patties to glam burgers, we have it all.</p>
            <a href="#" style="display: inline-block; background: #000; color: #fff; text-decoration: none; padding: 15px 30px; font-weight: 700; border-radius: 10px; margin-top: 20px; transition: 0.2s;">Reserve a Seat</a>
          </div>
          <div>
            <div style="font-size:3rem; font-weight:800; margin-bottom:20px;">bitesized®</div>
            <div style="display: flex; gap: 40px; margin-top: 40px;">
              <div>
                <a href="#" style="display: block; color: #000; font-weight: 700; text-decoration: none; font-size: 1.1rem; margin-bottom: 10px;">Menu</a>
                <a href="#" style="display: block; color: #000; font-weight: 700; text-decoration: none; font-size: 1.1rem; margin-bottom: 10px;">Locations</a>
                <a href="#" style="display: block; color: #000; font-weight: 700; text-decoration: none; font-size: 1.1rem; margin-bottom: 10px;">Catering</a>
              </div>
              <div>
                <a href="#" style="display: block; color: #000; font-weight: 700; text-decoration: none; font-size: 1.1rem; margin-bottom: 10px;">Instagram</a>
                <a href="#" style="display: block; color: #000; font-weight: 700; text-decoration: none; font-size: 1.1rem; margin-bottom: 10px;">TikTok</a>
                <a href="#" style="display: block; color: #000; font-weight: 700; text-decoration: none; font-size: 1.1rem; margin-bottom: 10px;">Twitter</a>
              </div>
            </div>
          </div>
        </div>
      </footer>
    `
  },
  // 16. The Luminous (SaaS Gradient)
  {
    id: 'footer-luminous-saas',
    label: 'The Luminous',
    category: 'Footers',
    content: `
      <footer style="background: #000; color: #fff; font-family: 'Inter', sans-serif; position: relative; padding: 80px 24px; overflow: hidden;">
        <div style="position: absolute; width: 600px; height: 600px; background: radial-gradient(circle, #7c3aed 0%, transparent 70%); opacity: 0.2; top: -200px; left: -200px;"></div>
        <div style="position: absolute; width: 500px; height: 500px; background: radial-gradient(circle, #ec4899 0%, transparent 70%); opacity: 0.2; bottom: -100px; right: -100px;"></div>
        <div style="max-width: 1200px; margin: 0 auto; position: relative; z-index: 2;">
          <div style="display: flex; justify-content: space-between; align-items: flex-end; padding-bottom: 60px; border-bottom: 1px solid rgba(255,255,255,0.1);">
            <div>
              <div style="font-size: 2rem; font-weight: 700; letter-spacing: -1px; background: linear-gradient(to right, #fff, #999); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Aura.</div>
              <p style="color:#888; max-width:300px; margin-top:10px;">Building beautiful digital experiences that captivate and convert.</p>
            </div>
            <div style="display: flex; gap: 15px;">
              <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.1); border-radius: 12px; display: grid; place-items: center; font-size: 1.2rem; color: #fff;"><i class="ri-slack-fill"></i></div>
              <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.1); border-radius: 12px; display: grid; place-items: center; font-size: 1.2rem; color: #fff;"><i class="ri-notion-fill"></i></div>
              <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.1); border-radius: 12px; display: grid; place-items: center; font-size: 1.2rem; color: #fff;"><i class="ri-figma-fill"></i></div>
              <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.1); border-radius: 12px; display: grid; place-items: center; font-size: 1.2rem; color: #fff;"><i class="ri-google-fill"></i></div>
            </div>
          </div>
          <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 40px; margin-top: 60px;">
            <div>
              <div style="color: #fff; font-weight: 600; margin-bottom: 20px;">Product</div>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">Features</a>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">Integrations</a>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">Pricing</a>
            </div>
            <div>
              <div style="color: #fff; font-weight: 600; margin-bottom: 20px;">Resources</div>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">Documentation</a>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">API Reference</a>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">Community</a>
            </div>
            <div>
              <div style="color: #fff; font-weight: 600; margin-bottom: 20px;">Company</div>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">About</a>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">Blog</a>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">Careers</a>
            </div>
            <div>
              <div style="color: #fff; font-weight: 600; margin-bottom: 20px;">Legal</div>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">Privacy Policy</a>
              <a href="#" style="display: block; color: #888; text-decoration: none; margin-bottom: 12px; font-size: 0.95rem;">Terms of Service</a>
            </div>
          </div>
        </div>
      </footer>
    `
  },
  // 17. The Ecosystem (Clean App)
  {
    id: 'footer-ecosystem-app',
    label: 'The Ecosystem',
    category: 'Footers',
    content: `
      <footer style="background: #F0FDF4; color: #166534; padding: 80px 24px; font-family: 'Outfit', sans-serif;">
        <div style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr; gap: 40px;">
          <div style="padding-right: 40px;">
            <div style="font-size: 1.8rem; font-weight: 700; display: flex; align-items: center; gap: 8px; margin-bottom: 20px;"><i class="ri-leaf-fill" style="color: #22c55e;"></i> heilsa</div>
            <p style="color: #15803d; line-height: 1.6; margin-bottom: 30px; font-size: 0.95rem;">Try our Healthy app for free for 14 days. The first step to a healthier you takes less than a minute.</p>
            <div style="display: flex; gap: 10px;">
              <a href="#" style="background: #166534; color: #fff; padding: 10px 20px; border-radius: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; font-size: 0.8rem;">
                <i class="ri-apple-fill" style="font-size: 1.5rem;"></i>
                <div style="display: flex; flex-direction: column;"><span style="font-size: 0.6rem; opacity: 0.8;">Download on the</span><b style="font-size: 0.9rem;">App Store</b></div>
              </a>
              <a href="#" style="background: #166534; color: #fff; padding: 10px 20px; border-radius: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; font-size: 0.8rem;">
                <i class="ri-google-play-fill" style="font-size: 1.5rem;"></i>
                <div style="display: flex; flex-direction: column;"><span style="font-size: 0.6rem; opacity: 0.8;">GET IT ON</span><b style="font-size: 0.9rem;">Google Play</b></div>
              </a>
            </div>
          </div>
          <div>
            <h4 style="margin-bottom: 20px; font-size: 1.1rem;">Products</h4>
            <ul style="list-style: none; padding: 0;">
              <li style="margin-bottom: 12px;"><a href="#" style="color: #15803d; text-decoration: none; font-weight: 500;">Features</a></li>
              <li style="margin-bottom: 12px;"><a href="#" style="color: #15803d; text-decoration: none; font-weight: 500;">Pricing</a></li>
              <li style="margin-bottom: 12px;"><a href="#" style="color: #15803d; text-decoration: none; font-weight: 500;">Login</a></li>
            </ul>
          </div>
          <div>
            <h4 style="margin-bottom: 20px; font-size: 1.1rem;">About Us</h4>
            <ul style="list-style: none; padding: 0;">
              <li style="margin-bottom: 12px;"><a href="#" style="color: #15803d; text-decoration: none; font-weight: 500;">Our Story</a></li>
              <li style="margin-bottom: 12px;"><a href="#" style="color: #15803d; text-decoration: none; font-weight: 500;">Contact</a></li>
              <li style="margin-bottom: 12px;"><a href="#" style="color: #15803d; text-decoration: none; font-weight: 500;">Careers</a></li>
            </ul>
          </div>
          <div>
            <h4 style="margin-bottom: 20px; font-size: 1.1rem;">Resources</h4>
            <ul style="list-style: none; padding: 0;">
              <li style="margin-bottom: 12px;"><a href="#" style="color: #15803d; text-decoration: none; font-weight: 500;">Help Center</a></li>
              <li style="margin-bottom: 12px;"><a href="#" style="color: #15803d; text-decoration: none; font-weight: 500;">Blog</a></li>
              <li style="margin-bottom: 12px;"><a href="#" style="color: #15803d; text-decoration: none; font-weight: 500;">Status</a></li>
            </ul>
          </div>
        </div>
      </footer>
    `
  },
  // 18. The Blueprint (Swiss Grid)
  {
    id: 'footer-blueprint-grid',
    label: 'The Blueprint',
    category: 'Footers',
    content: `
      <footer style="background: #fff; font-family: 'Space Grotesk', monospace; border-top: 1px solid #000;">
        <div style="display: grid; grid-template-columns: repeat(4, 1fr);">
          <div style="padding: 40px; border-right: 1px solid #000; min-height: 250px; display: flex; flex-direction: column; justify-content: space-between;">
            <span style="font-weight: 700; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px; margin-bottom: 20px; display: block;">Navigation</span>
            <div>
              <a href="#" style="display: block; color: #000; text-decoration: none; margin-bottom: 8px; font-size: 0.95rem;">Index</a>
              <a href="#" style="display: block; color: #000; text-decoration: none; margin-bottom: 8px; font-size: 0.95rem;">Projects</a>
              <a href="#" style="display: block; color: #000; text-decoration: none; margin-bottom: 8px; font-size: 0.95rem;">Studio</a>
              <a href="#" style="display: block; color: #000; text-decoration: none; margin-bottom: 8px; font-size: 0.95rem;">News</a>
            </div>
          </div>
          <div style="padding: 40px; border-right: 1px solid #000; min-height: 250px; display: flex; flex-direction: column; justify-content: space-between;">
            <span style="font-weight: 700; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px; margin-bottom: 20px; display: block;">Social</span>
            <div>
              <a href="#" style="display: block; color: #000; text-decoration: none; margin-bottom: 8px; font-size: 0.95rem;">Instagram</a>
              <a href="#" style="display: block; color: #000; text-decoration: none; margin-bottom: 8px; font-size: 0.95rem;">Twitter</a>
              <a href="#" style="display: block; color: #000; text-decoration: none; margin-bottom: 8px; font-size: 0.95rem;">LinkedIn</a>
            </div>
          </div>
          <div style="padding: 40px; border-right: 1px solid #000; min-height: 250px; display: flex; flex-direction: column; justify-content: space-between;">
            <span style="font-weight: 700; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px; margin-bottom: 20px; display: block;">Contact</span>
            <div style="font-size: 1.5rem; font-weight: 700; line-height: 1.2; margin-top: auto;">hello@<br>aerix.com</div>
          </div>
          <div style="padding: 40px; min-height: 250px; display: flex; flex-direction: column; justify-content: space-between;">
            <span style="font-weight: 700; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px; margin-bottom: 20px; display: block;">Newsletter</span>
            <p style="margin-bottom:20px; font-size:0.9rem;">Join our weekly digest.</p>
            <div style="display: flex; border-bottom: 1px solid #000; padding-bottom: 5px; margin-top: auto;">
              <input type="email" placeholder="Email Address" style="border: none; outline: none; width: 100%; font-family: inherit; font-size: 1rem;">
              <i class="ri-arrow-right-up-line" style="cursor: pointer; font-size: 1.2rem;"></i>
            </div>
          </div>
        </div>
        <div style="border-top: 1px solid #000; padding: 20px 40px; display: flex; justify-content: space-between; font-size: 0.8rem; text-transform: uppercase;">
          <span>&copy; 2026 Aerix Systems</span>
          <span>All Rights Reserved</span>
        </div>
      </footer>
    `
  },
  // 19. The Prism (Mesh Gradient & Glass)
  {
    id: 'footer-prism-glass',
    label: 'The Prism',
    category: 'Footers',
    content: `
      <footer style="position: relative; background: #0f0f1a; overflow: hidden; color: #fff; font-family: 'Outfit', sans-serif; padding: 80px 20px;">
        <!-- The colorful mesh background -->
        <div style="position: absolute; inset: 0; pointer-events: none;">
          <div style="position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.6; top: -10%; left: -10%; width: 600px; height: 600px; background: #4f46e5;"></div>
          <div style="position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.6; bottom: -20%; right: -10%; width: 700px; height: 700px; background: #ec4899;"></div>
          <div style="position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.6; top: 40%; left: 30%; width: 400px; height: 400px; background: #06b6d4;"></div>
        </div>
        
        <div style="position: relative; z-index: 2; max-width: 1200px; margin: 0 auto; background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(30px); -webkit-backdrop-filter: blur(30px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 30px; padding: 60px; box-shadow: 0 20px 40px rgba(0,0,0,0.2);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 60px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 40px;">
            <div style="font-size: 2.5rem; font-weight: 800; letter-spacing: -1px; background: linear-gradient(to right, #fff, #ccc); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">CHROMATIC.</div>
            <a href="#" style="padding: 16px 32px; background: #fff; color: #000; border-radius: 50px; text-decoration: none; font-weight: 700; transition: 0.3s; box-shadow: 0 10px 20px -5px rgba(255,255,255,0.3);">Start Creating Now</a>
          </div>
          
          <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px;">
            <div>
              <h4 style="font-size: 1.1rem; margin-bottom: 24px; font-weight: 700;">Join the collective</h4>
              <p style="color: rgba(255,255,255,0.7); margin-bottom: 20px;">Receive weekly inspiration and resources for digital creators.</p>
              <div style="display: flex; background: rgba(0,0,0,0.2); border-radius: 50px; padding: 5px; border: 1px solid rgba(255,255,255,0.1);">
                <input type="email" placeholder="Email address" style="flex: 1; background: none; border: none; padding: 12px 20px; color: #fff; outline: none;">
                <button style="background: linear-gradient(45deg, #4f46e5, #ec4899); border: none; width: 50px; height: 50px; border-radius: 50%; color: #fff; cursor: pointer; transition: 0.2s; display: grid; place-items: center;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </button>
              </div>
            </div>
            
            <div>
              <h4 style="font-size: 1.1rem; margin-bottom: 24px; font-weight: 700;">Explore</h4>
              <ul style="list-style: none; padding: 0;">
                <li style="margin-bottom: 16px;"><a href="#" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: 0.2s; font-weight: 500;">Templates</a></li>
                <li style="margin-bottom: 16px;"><a href="#" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: 0.2s; font-weight: 500;">Assets</a></li>
                <li style="margin-bottom: 16px;"><a href="#" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: 0.2s; font-weight: 500;">Tutorials</a></li>
              </ul>
            </div>
            
            <div>
              <h4 style="font-size: 1.1rem; margin-bottom: 24px; font-weight: 700;">Company</h4>
              <ul style="list-style: none; padding: 0;">
                <li style="margin-bottom: 16px;"><a href="#" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: 0.2s; font-weight: 500;">About Us</a></li>
                <li style="margin-bottom: 16px;"><a href="#" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: 0.2s; font-weight: 500;">Careers</a></li>
                <li style="margin-bottom: 16px;"><a href="#" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: 0.2s; font-weight: 500;">Press Kit</a></li>
              </ul>
            </div>
            
            <div>
              <h4 style="font-size: 1.1rem; margin-bottom: 24px; font-weight: 700;">Connect</h4>
              <ul style="list-style: none; padding: 0;">
                <li style="margin-bottom: 16px;"><a href="#" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: 0.2s; font-weight: 500;">Discord</a></li>
                <li style="margin-bottom: 16px;"><a href="#" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: 0.2s; font-weight: 500;">Twitter</a></li>
                <li style="margin-bottom: 16px;"><a href="#" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: 0.2s; font-weight: 500;">Instagram</a></li>
              </ul>
            </div>
          </div>
        </div>
      </footer>
    `
  },
  // 20. The Chromatic (Neo-Brutalist Color Block)
  {
    id: 'footer-chromatic-brutalist',
    label: 'The Chromatic',
    category: 'Footers',
    content: `
      <footer style="font-family: 'Syne', sans-serif; background: #000000; padding: 40px;">
        <div style="max-width: 1400px; margin: 0 auto; display: grid; grid-template-columns: 1.5fr 1fr 1fr; grid-template-rows: auto auto; gap: 0; border: 4px solid #000000;">
          <!-- Brand Block (Big Blue Area) -->
          <div style="background: #0047FF; color: #ffffff; padding: 60px; grid-row: span 2; display: flex; flex-direction: column; justify-content: space-between; border-right: 4px solid #000000;">
            <div>
              <div style="font-size: 4rem; font-weight: 800; line-height: 1; margin-bottom: 20px;">BLOKK.</div>
              <div style="font-size: 1.5rem; font-weight: 700; max-width: 400px;">WE BUILD LOUD DIGITAL EXPERIENCES.</div>
            </div>
            <p style="margin-top: 40px; opacity: 0.8;">© 2024 Blokk Agency.</p>
          </div>

          <!-- Links Block (Yellow Area) -->
          <div style="background: #FFD700; padding: 40px; border-bottom: 4px solid #000000; border-right: 4px solid #000000;">
            <h4 style="font-size: 1.2rem; margin-bottom: 20px; text-transform: uppercase; color: #000000;">Navigation</h4>
            <ul style="list-style: none; padding: 0; display: flex; flex-direction: column; gap: 15px;">
              <li>
                <a href="#" style="text-decoration: none; color: #000000; font-weight: 800; font-size: 1.1rem; text-transform: uppercase; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000000; padding-bottom: 5px; transition: 0.1s;">
                  Work 
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
                </a>
              </li>
              <li>
                <a href="#" style="text-decoration: none; color: #000000; font-weight: 800; font-size: 1.1rem; text-transform: uppercase; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000000; padding-bottom: 5px; transition: 0.1s;">
                  Services 
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
                </a>
              </li>
              <li>
                <a href="#" style="text-decoration: none; color: #000000; font-weight: 800; font-size: 1.1rem; text-transform: uppercase; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000000; padding-bottom: 5px; transition: 0.1s;">
                  Studio 
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
                </a>
              </li>
            </ul>
          </div>

          <!-- Contact Block (White Area) -->
          <div style="background: #ffffff; padding: 40px; border-bottom: 4px solid #000000;">
            <h4 style="font-size: 1.2rem; margin-bottom: 20px; text-transform: uppercase; color: #000000;">Say Hello</h4>
            <a href="mailto:hi@blokk.com" style="font-size: 1.5rem; font-weight: 800; text-decoration: none; color: #000000; display: block; margin-bottom: 20px;">hi@blokk.com</a>
            <div style="display: flex; gap: 20px;">
              <a href="#" style="color: #000000; text-decoration: none; transition: 0.2s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
              </a>
              <a href="#" style="color: #000000; text-decoration: none; transition: 0.2s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect width="4" height="12" x="2" y="9"/><circle cx="4" cy="4" r="2"/></svg>
              </a>
              <a href="#" style="color: #000000; text-decoration: none; transition: 0.2s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M19.13 5.09C15.22 9.14 10 10.44 2.25 10.94"/><path d="M21.75 12.84c-6.62-1.41-12.14 1-16.38 6.32"/><path d="M8.56 2.75c4.37 6 6 9.42 8 13.23"/><path d="M16.33 22.85c-.87-4.6-6.16-7-11.58-1.56"/></svg>
              </a>
            </div>
          </div>

          <!-- CTA Block (Orange Area) -->
          <div style="grid-column: span 2; background: #FF5733; padding: 40px; display: flex; justify-content: space-between; align-items: center;">
            <div style="font-size: 2rem; font-weight: 800; color: #000000; text-transform: uppercase;">HAVE A PROJECT IN MIND? LET'S TALK.</div>
            <a href="#" style="background: #000000; color: #ffffff; padding: 20px 40px; font-weight: 800; text-transform: uppercase; text-decoration: none; box-shadow: 8px 8px 0 #ffffff; transition: 0.1s;">BOOK A CALL</a>
          </div>
        </div>
      </footer>
    `
  },
]
