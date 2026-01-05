// Testimonial Presets for GrapesJS Editor
// 9 Premium Testimonial Designs with inline styles

export interface Preset {
  id: string
  label: string
  category: string
  content: string
}

export const testimonialPresets: Preset[] = [
  // ============================================
  // PRESET 1: THE CLASSIC CLEAN
  // ============================================
  {
    id: 'testi-classic-clean',
    label: 'Classic Clean',
    category: 'Testimonials',
    content: `<section style="padding: 100px 20px; background: #fff; font-family: 'Inter', sans-serif;">
      <div style="max-width: 1200px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 60px; max-width: 600px; margin-left: auto; margin-right: auto;">
          <h2 style="font-size: 2.5rem; font-weight: 800; letter-spacing: -1px; margin-bottom: 16px; color: #0f172a;">Customer Favorites</h2>
          <p style="color:#64748b">See what our community has to say about their experience.</p>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px;">
          <!-- Card 1 -->
          <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px; box-shadow: 0 10px 30px -10px rgba(0,0,0,0.05);">
            <div style="display: flex; gap: 4px; color: #f59e0b; margin-bottom: 20px;">
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </div>
            <p style="font-size: 1rem; line-height: 1.6; color: #475569; margin-bottom: 24px;">"This platform has completely transformed how we handle our workflows. The interface is intuitive and the speed is unmatched."</p>
            <div style="display: flex; align-items: center; gap: 12px;">
              <img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=100&auto=format&fit=crop" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; background: #f1f5f9;" alt="User">
              <div>
                <h4 style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin: 0;">Sarah Jenkins</h4>
                <span style="font-size: 0.85rem; color: #64748b;">CEO at Apex</span>
              </div>
            </div>
          </div>
          <!-- Card 2 -->
          <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px; box-shadow: 0 10px 30px -10px rgba(0,0,0,0.05);">
            <div style="display: flex; gap: 4px; color: #f59e0b; margin-bottom: 20px;">
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </div>
            <p style="font-size: 1rem; line-height: 1.6; color: #475569; margin-bottom: 24px;">"Customer support is top-notch. I had an issue at 2 AM and it was resolved in minutes. Highly recommended!"</p>
            <div style="display: flex; align-items: center; gap: 12px;">
              <img src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=100&auto=format&fit=crop" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; background: #f1f5f9;" alt="User">
              <div>
                <h4 style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin: 0;">David Chen</h4>
                <span style="font-size: 0.85rem; color: #64748b;">Developer</span>
              </div>
            </div>
          </div>
          <!-- Card 3 -->
          <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px; box-shadow: 0 10px 30px -10px rgba(0,0,0,0.05);">
            <div style="display: flex; gap: 4px; color: #f59e0b; margin-bottom: 20px;">
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </div>
            <p style="font-size: 1rem; line-height: 1.6; color: #475569; margin-bottom: 24px;">"The best investment we made this year. It paid for itself within the first month of usage. Truly incredible."</p>
            <div style="display: flex; align-items: center; gap: 12px;">
              <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100&auto=format&fit=crop" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; background: #f1f5f9;" alt="User">
              <div>
                <h4 style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin: 0;">Emily Davis</h4>
                <span style="font-size: 0.85rem; color: #64748b;">Founder</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // PRESET 2: THE MASONRY WALL
  // ============================================
  {
    id: 'testi-masonry-wall',
    label: 'Masonry Wall',
    category: 'Testimonials',
    content: `<section style="padding: 100px 20px; background: #f8fafc; font-family: 'Manrope', sans-serif;">
      <div style="text-align: center; margin-bottom: 60px;">
        <h2 style="font-size: 3rem; font-weight: 800; color: #0f172a; letter-spacing: -1.5px;">Loved by thousands</h2>
      </div>
      <div style="max-width: 1200px; margin: 0 auto;">
        <style>
          .t2-columns { column-count: 3; column-gap: 24px; }
          @media(max-width: 1024px) { .t2-columns { column-count: 2; } }
          @media(max-width: 600px) { .t2-columns { column-count: 1; } }
          .t2-card { break-inside: avoid; margin-bottom: 24px; background: #fff; border-radius: 20px; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        </style>
        <div class="t2-columns">
          <div class="t2-card">
            <svg style="width: 32px; height: 32px; color: #3b82f6; margin-bottom: 16px; opacity: 0.2;" viewBox="0 0 24 24" fill="currentColor"><path d="M14.017 21L14.017 18C14.017 16.8954 14.9124 16 16.017 16H19.017C19.5693 16 20.017 15.5523 20.017 15V9C20.017 8.44772 19.5693 8 19.017 8H15.017C14.4647 8 14.017 8.44772 14.017 9V11C14.017 11.5523 13.5693 12 13.017 12H12.017V5H22.017V15C22.017 18.3137 19.3307 21 16.017 21H14.017ZM5.0166 21L5.0166 18C5.0166 16.8954 5.91203 16 7.0166 16H10.0166C10.5689 16 11.0166 15.5523 11.0166 15V9C11.0166 8.44772 10.5689 8 10.0166 8H6.0166C5.46432 8 5.0166 8.44772 5.0166 9V11C5.0166 11.5523 4.56889 12 4.0166 12H3.0166V5H13.0166V15C13.0166 18.3137 10.3303 21 7.0166 21H5.0166Z"/></svg>
            <p style="font-size: 1.05rem; color: #334155; margin-bottom: 20px; line-height: 1.5; font-weight: 500;">"I can't imagine running my business without this. It's simply essential."</p>
            <div style="display: flex; align-items: center; gap: 10px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
              <img src="https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=100&auto=format&fit=crop" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" alt="User">
              <div style="font-size: 0.9rem; font-weight: 700; color: #0f172a;">Mark T. <span style="display: block; font-size: 0.8rem; font-weight: 500; color: #94a3b8;">Product Designer</span></div>
            </div>
          </div>
          <div class="t2-card">
            <svg style="width: 32px; height: 32px; color: #3b82f6; margin-bottom: 16px; opacity: 0.2;" viewBox="0 0 24 24" fill="currentColor"><path d="M14.017 21L14.017 18C14.017 16.8954 14.9124 16 16.017 16H19.017C19.5693 16 20.017 15.5523 20.017 15V9C20.017 8.44772 19.5693 8 19.017 8H15.017C14.4647 8 14.017 8.44772 14.017 9V11C14.017 11.5523 13.5693 12 13.017 12H12.017V5H22.017V15C22.017 18.3137 19.3307 21 16.017 21H14.017ZM5.0166 21L5.0166 18C5.0166 16.8954 5.91203 16 7.0166 16H10.0166C10.5689 16 11.0166 15.5523 11.0166 15V9C11.0166 8.44772 10.5689 8 10.0166 8H6.0166C5.46432 8 5.0166 8.44772 5.0166 9V11C5.0166 11.5523 4.56889 12 4.0166 12H3.0166V5H13.0166V15C13.0166 18.3137 10.3303 21 7.0166 21H5.0166Z"/></svg>
            <p style="font-size: 1.05rem; color: #334155; margin-bottom: 20px; line-height: 1.5; font-weight: 500;">"The clean design and user experience are unmatched. The team clearly cares about the details."</p>
            <div style="display: flex; align-items: center; gap: 10px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
              <img src="https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=100&auto=format&fit=crop" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" alt="User">
              <div style="font-size: 0.9rem; font-weight: 700; color: #0f172a;">Anna K. <span style="display: block; font-size: 0.8rem; font-weight: 500; color: #94a3b8;">Creative Dir</span></div>
            </div>
          </div>
          <div class="t2-card">
            <svg style="width: 32px; height: 32px; color: #3b82f6; margin-bottom: 16px; opacity: 0.2;" viewBox="0 0 24 24" fill="currentColor"><path d="M14.017 21L14.017 18C14.017 16.8954 14.9124 16 16.017 16H19.017C19.5693 16 20.017 15.5523 20.017 15V9C20.017 8.44772 19.5693 8 19.017 8H15.017C14.4647 8 14.017 8.44772 14.017 9V11C14.017 11.5523 13.5693 12 13.017 12H12.017V5H22.017V15C22.017 18.3137 19.3307 21 16.017 21H14.017ZM5.0166 21L5.0166 18C5.0166 16.8954 5.91203 16 7.0166 16H10.0166C10.5689 16 11.0166 15.5523 11.0166 15V9C11.0166 8.44772 10.5689 8 10.0166 8H6.0166C5.46432 8 5.0166 8.44772 5.0166 9V11C5.0166 11.5523 4.56889 12 4.0166 12H3.0166V5H13.0166V15C13.0166 18.3137 10.3303 21 7.0166 21H5.0166Z"/></svg>
            <p style="font-size: 1.05rem; color: #334155; margin-bottom: 20px; line-height: 1.5; font-weight: 500;">"Blazing fast. My team's productivity has doubled since we switched."</p>
            <div style="display: flex; align-items: center; gap: 10px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
              <img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=100&auto=format&fit=crop" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" alt="User">
              <div style="font-size: 0.9rem; font-weight: 700; color: #0f172a;">James L. <span style="display: block; font-size: 0.8rem; font-weight: 500; color: #94a3b8;">Engineer</span></div>
            </div>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // PRESET 3: THE SOLO SPOTLIGHT
  // ============================================
  {
    id: 'testi-solo-spotlight',
    label: 'Solo Spotlight',
    category: 'Testimonials',
    content: `<section style="padding: 140px 20px; background: #0f172a; color: #fff; font-family: 'Playfair Display', serif; text-align: center;">
      <div style="max-width: 900px; margin: 0 auto;">
        <svg style="width: 60px; height: 60px; color: #38bdf8; margin: 0 auto 40px; opacity: 0.8;" viewBox="0 0 24 24" fill="currentColor"><path d="M14.017 21L14.017 18C14.017 16.8954 14.9124 16 16.017 16H19.017C19.5693 16 20.017 15.5523 20.017 15V9C20.017 8.44772 19.5693 8 19.017 8H15.017C14.4647 8 14.017 8.44772 14.017 9V11C14.017 11.5523 13.5693 12 13.017 12H12.017V5H22.017V15C22.017 18.3137 19.3307 21 16.017 21H14.017ZM5.0166 21L5.0166 18C5.0166 16.8954 5.91203 16 7.0166 16H10.0166C10.5689 16 11.0166 15.5523 11.0166 15V9C11.0166 8.44772 10.5689 8 10.0166 8H6.0166C5.46432 8 5.0166 8.44772 5.0166 9V11C5.0166 11.5523 4.56889 12 4.0166 12H3.0166V5H13.0166V15C13.0166 18.3137 10.3303 21 7.0166 21H5.0166Z"/></svg>
        <h2 style="font-size: 3.5rem; line-height: 1.2; font-weight: 600; margin-bottom: 50px; background: linear-gradient(180deg, #fff 0%, #cbd5e1 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">"We've used every tool in the book, but nothing comes close to this level of polish and performance. It's in a league of its own."</h2>
        <div style="font-family: 'Inter', sans-serif; display: flex; flex-direction: column; align-items: center; gap: 15px;">
          <img src="https://images.unsplash.com/photo-1580489944761-15a19d654956?w=150&auto=format&fit=crop" style="width: 80px; height: 80px; border-radius: 50%; border: 3px solid #38bdf8; object-fit: cover;" alt="User">
          <span style="font-size: 1.2rem; font-weight: 700;">Elena Rodriguez</span>
          <span style="font-size: 0.9rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 2px;">VP of Product at Stripe</span>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // PRESET 4: THE DARK GLOW
  // ============================================
  {
    id: 'testi-dark-glow',
    label: 'Dark Glow',
    category: 'Testimonials',
    content: `<section style="padding: 100px 20px; background: #050505; color: #fff; font-family: 'Outfit', sans-serif;">
      <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px; max-width: 1200px; margin: 0 auto;">
        <!-- Card 1 -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 40px; position: relative; overflow: hidden;">
          <div style="position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, #8b5cf6, transparent);"></div>
          <svg style="position: absolute; top: 30px; right: 30px; color: #8b5cf6; width: 24px; height: 24px; opacity: 0.5;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          <p style="font-size: 1.1rem; line-height: 1.6; color: #cbd5e1; margin-bottom: 30px;">"The API is a dream to work with. Well documented and extremely reliable."</p>
          <div style="display: flex; align-items: center; gap: 12px;">
            <img src="https://images.unsplash.com/photo-1633332755192-727a05c4013d?w=100&auto=format&fit=crop" style="width: 48px; height: 48px; border-radius: 12px; object-fit: cover; background: #333;" alt="User">
            <div><div style="font-weight: 700; font-size: 1rem; color: #fff;">Tom Cook</div><div style="font-size: 0.85rem; color: #64748b;">@tomcook</div></div>
          </div>
        </div>
        <!-- Card 2 -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 40px; position: relative; overflow: hidden;">
          <div style="position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, #8b5cf6, transparent);"></div>
          <svg style="position: absolute; top: 30px; right: 30px; color: #8b5cf6; width: 24px; height: 24px; opacity: 0.5;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          <p style="font-size: 1.1rem; line-height: 1.6; color: #cbd5e1; margin-bottom: 30px;">"Scaled our infrastructure in minutes. The dashboard gives us total visibility."</p>
          <div style="display: flex; align-items: center; gap: 12px;">
            <img src="https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&auto=format&fit=crop" style="width: 48px; height: 48px; border-radius: 12px; object-fit: cover; background: #333;" alt="User">
            <div><div style="font-weight: 700; font-size: 1rem; color: #fff;">John Doe</div><div style="font-size: 0.85rem; color: #64748b;">@johndoe</div></div>
          </div>
        </div>
        <!-- Card 3 -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 40px; position: relative; overflow: hidden;">
          <div style="position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, #8b5cf6, transparent);"></div>
          <svg style="position: absolute; top: 30px; right: 30px; color: #8b5cf6; width: 24px; height: 24px; opacity: 0.5;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          <p style="font-size: 1.1rem; line-height: 1.6; color: #cbd5e1; margin-bottom: 30px;">"Dark mode by default? Say no more. The design system is beautiful."</p>
          <div style="display: flex; align-items: center; gap: 12px;">
            <img src="https://images.unsplash.com/photo-1527980965255-d3b416303d12?w=100&auto=format&fit=crop" style="width: 48px; height: 48px; border-radius: 12px; object-fit: cover; background: #333;" alt="User">
            <div><div style="font-weight: 700; font-size: 1rem; color: #fff;">Mike Smith</div><div style="font-size: 0.85rem; color: #64748b;">@mikesmith</div></div>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // PRESET 5: THE INFINITE STREAM (Marquee)
  // ============================================
  {
    id: 'testi-infinite-stream',
    label: 'Infinite Stream',
    category: 'Testimonials',
    content: `<section style="padding: 100px 0; background: #fff; overflow: hidden; font-family: 'Inter', sans-serif;">
      <style>
        .t5-track { display: flex; gap: 30px; width: max-content; animation: t5-scroll 40s linear infinite; }
        @keyframes t5-scroll { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }
        .t5-card { width: 400px; background: #f8fafc; padding: 30px; border-radius: 16px; border: 1px solid #e2e8f0; flex-shrink: 0; }
      </style>
      <div class="t5-track">
        <!-- Original set -->
        <div class="t5-card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="display: flex; color: #10b981; gap: 2px;">★★★★★</div>
            <span style="font-size: 0.8rem; color: #94a3b8;">2 days ago</span>
          </div>
          <p style="font-size: 0.95rem; color: #334155; line-height: 1.6; margin-bottom: 20px;">"Absolutely game-changing for our team workflow."</p>
          <div style="font-weight: 700; font-size: 0.9rem; color: #0f172a;">Chris Evans</div>
        </div>
        <div class="t5-card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="display: flex; color: #10b981; gap: 2px;">★★★★★</div>
            <span style="font-size: 0.8rem; color: #94a3b8;">1 week ago</span>
          </div>
          <p style="font-size: 0.95rem; color: #334155; line-height: 1.6; margin-bottom: 20px;">"Support team is incredibly responsive and helpful."</p>
          <div style="font-weight: 700; font-size: 0.9rem; color: #0f172a;">Sarah M.</div>
        </div>
        <div class="t5-card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="display: flex; color: #10b981; gap: 2px;">★★★★★</div>
            <span style="font-size: 0.8rem; color: #94a3b8;">2 weeks ago</span>
          </div>
          <p style="font-size: 0.95rem; color: #334155; line-height: 1.6; margin-bottom: 20px;">"Best purchase I've made for my business this year."</p>
          <div style="font-weight: 700; font-size: 0.9rem; color: #0f172a;">Jessica L.</div>
        </div>
        <!-- Duplicated set for seamless loop -->
        <div class="t5-card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="display: flex; color: #10b981; gap: 2px;">★★★★★</div>
            <span style="font-size: 0.8rem; color: #94a3b8;">2 days ago</span>
          </div>
          <p style="font-size: 0.95rem; color: #334155; line-height: 1.6; margin-bottom: 20px;">"Absolutely game-changing for our team workflow."</p>
          <div style="font-weight: 700; font-size: 0.9rem; color: #0f172a;">Chris Evans</div>
        </div>
        <div class="t5-card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="display: flex; color: #10b981; gap: 2px;">★★★★★</div>
            <span style="font-size: 0.8rem; color: #94a3b8;">1 week ago</span>
          </div>
          <p style="font-size: 0.95rem; color: #334155; line-height: 1.6; margin-bottom: 20px;">"Support team is incredibly responsive and helpful."</p>
          <div style="font-weight: 700; font-size: 0.9rem; color: #0f172a;">Sarah M.</div>
        </div>
        <div class="t5-card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="display: flex; color: #10b981; gap: 2px;">★★★★★</div>
            <span style="font-size: 0.8rem; color: #94a3b8;">2 weeks ago</span>
          </div>
          <p style="font-size: 0.95rem; color: #334155; line-height: 1.6; margin-bottom: 20px;">"Best purchase I've made for my business this year."</p>
          <div style="font-weight: 700; font-size: 0.9rem; color: #0f172a;">Jessica L.</div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // PRESET 6: THE EDITORIAL LIST
  // ============================================
  {
    id: 'testi-editorial-list',
    label: 'Editorial List',
    category: 'Testimonials',
    content: `<section style="padding: 100px 20px; background: #fff; font-family: 'Space Grotesk', sans-serif;">
      <div style="max-width: 900px; margin: 0 auto;">
        <style>
          .t6-item { display: flex; gap: 40px; padding: 60px 0; border-bottom: 1px solid #e2e8f0; align-items: flex-start; transition: 0.3s; }
          .t6-item:first-child { border-top: 1px solid #e2e8f0; }
          .t6-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; flex-shrink: 0; filter: grayscale(100%); transition: 0.3s; }
          .t6-item:hover .t6-avatar { filter: grayscale(0%); transform: scale(1.1); }
          @media(max-width: 600px) { .t6-item { flex-direction: column; gap: 20px; } }
        </style>
        <div class="t6-item">
          <img src="https://images.unsplash.com/photo-1531123897727-8f129e1688ce?w=100&auto=format&fit=crop" class="t6-avatar" alt="User">
          <div style="flex: 1;">
            <h3 style="font-size: 1.5rem; font-weight: 500; line-height: 1.4; margin-bottom: 20px; color: #0f172a; margin-top: 0;">"A masterpiece of digital engineering. It bridges the gap between complex functionality and ease of use."</h3>
            <div style="font-size: 0.9rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px;">Julia S. — Head of Design</div>
          </div>
        </div>
        <div class="t6-item">
          <img src="https://images.unsplash.com/photo-1517841905240-472988babdf9?w=100&auto=format&fit=crop" class="t6-avatar" alt="User">
          <div style="flex: 1;">
            <h3 style="font-size: 1.5rem; font-weight: 500; line-height: 1.4; margin-bottom: 20px; color: #0f172a; margin-top: 0;">"We replaced three different tools with just this one platform. The ROI has been immediate and substantial."</h3>
            <div style="font-size: 0.9rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px;">Marcus B. — CTO</div>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // PRESET 7: THE CASE STUDY
  // ============================================
  {
    id: 'testi-case-study',
    label: 'Case Study',
    category: 'Testimonials',
    content: `<section style="padding: 100px 20px; background: #f1f5f9; font-family: 'Inter', sans-serif;">
      <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 40px; max-width: 1200px; margin: 0 auto;">
        <style>
          .t7-card { background: #fff; border-radius: 24px; overflow: hidden; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.05); transition: 0.3s; }
          .t7-card:hover { transform: translateY(-5px); box-shadow: 0 30px 60px -15px rgba(0,0,0,0.1); }
          .t7-media { height: 300px; position: relative; background: #000; overflow: hidden; }
          .t7-media img { width: 100%; height: 100%; object-fit: cover; opacity: 0.8; transition: 0.3s; }
          .t7-card:hover .t7-media img { opacity: 0.6; }
          .t7-play { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 64px; height: 64px; background: rgba(255,255,255,0.2); backdrop-filter: blur(10px); border-radius: 50%; display: grid; place-items: center; color: #fff; border: 1px solid rgba(255,255,255,0.4); transition: 0.3s; cursor: pointer; }
          .t7-play:hover { background: #fff; color: #000; transform: translate(-50%, -50%) scale(1.1); }
          @media(max-width: 900px) { .t7-grid { grid-template-columns: 1fr; } }
        </style>
        <!-- Card 1 -->
        <div class="t7-card">
          <div class="t7-media">
            <img src="https://images.unsplash.com/photo-1552664730-d307ca884978?w=800&auto=format&fit=crop" alt="Case Study">
            <div class="t7-play"><svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg></div>
          </div>
          <div style="padding: 40px;">
            <span style="display: inline-block; background: #ecfdf5; color: #059669; font-weight: 700; font-size: 0.8rem; padding: 4px 10px; border-radius: 4px; margin-bottom: 15px;">+240% Growth</span>
            <p style="font-size: 1.25rem; font-weight: 600; line-height: 1.5; margin-bottom: 20px; color: #0f172a;">"How StartupX scaled their operations globally in under 6 months."</p>
            <span style="font-size: 0.9rem; color: #64748b; font-weight: 500;">Featuring Jane Doe, CEO</span>
          </div>
        </div>
        <!-- Card 2 -->
        <div class="t7-card">
          <div class="t7-media">
            <img src="https://images.unsplash.com/photo-1522071820081-009f0129c71c?w=800&auto=format&fit=crop" alt="Case Study">
            <div class="t7-play"><svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg></div>
          </div>
          <div style="padding: 40px;">
            <span style="display: inline-block; background: #ecfdf5; color: #059669; font-weight: 700; font-size: 0.8rem; padding: 4px 10px; border-radius: 4px; margin-bottom: 15px;">10x Efficiency</span>
            <p style="font-size: 1.25rem; font-weight: 600; line-height: 1.5; margin-bottom: 20px; color: #0f172a;">"Streamlining the creative process for remote-first teams."</p>
            <span style="font-size: 0.9rem; color: #64748b; font-weight: 500;">Featuring The Design Co.</span>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // PRESET 8: THE GLASS PANE
  // ============================================
  {
    id: 'testi-glass-pane',
    label: 'Glass Pane',
    category: 'Testimonials',
    content: `<section style="padding: 120px 20px; position: relative; overflow: hidden; font-family: 'Outfit', sans-serif; background: #0f172a; color: #fff;">
      <style>
        .t8-blob { position: absolute; border-radius: 50%; filter: blur(100px); opacity: 0.6; }
        .t8-blob-1 { width: 600px; height: 600px; background: #7c3aed; top: -10%; left: -10%; }
        .t8-blob-2 { width: 500px; height: 500px; background: #ec4899; bottom: -10%; right: -10%; }
        .t8-card { background: rgba(255,255,255,0.1); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.2); border-radius: 24px; padding: 40px; transition: 0.3s; }
        .t8-card:hover { background: rgba(255,255,255,0.15); transform: translateY(-5px); border-color: rgba(255,255,255,0.4); }
      </style>
      <div class="t8-blob t8-blob-1"></div>
      <div class="t8-blob t8-blob-2"></div>
      <div style="max-width: 1200px; margin: 0 auto; position: relative; z-index: 2;">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px;">
          <!-- Card 1 -->
          <div class="t8-card">
            <p style="font-size: 1.1rem; line-height: 1.6; margin-bottom: 30px; font-weight: 300;">"The visual fidelity is stunning. It feels less like software and more like an extension of my thought process."</p>
            <div style="display: flex; align-items: center; gap: 15px;">
              <img src="https://images.unsplash.com/photo-1619895862022-09114b41f16f?w=100&auto=format&fit=crop" style="width: 50px; height: 50px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.2); object-fit: cover;" alt="User">
              <div><div style="font-weight: 700; font-size: 1rem;">Alice Yu</div><div style="font-size: 0.8rem; opacity: 0.7;">Architect</div></div>
            </div>
          </div>
          <!-- Card 2 -->
          <div class="t8-card">
            <p style="font-size: 1.1rem; line-height: 1.6; margin-bottom: 30px; font-weight: 300;">"Finally, a tool that respects my time. Fast, keyboard-centric, and distraction-free."</p>
            <div style="display: flex; align-items: center; gap: 15px;">
              <img src="https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=100&auto=format&fit=crop" style="width: 50px; height: 50px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.2); object-fit: cover;" alt="User">
              <div><div style="font-weight: 700; font-size: 1rem;">Ben Ford</div><div style="font-size: 0.8rem; opacity: 0.7;">Writer</div></div>
            </div>
          </div>
          <!-- Card 3 -->
          <div class="t8-card">
            <p style="font-size: 1.1rem; line-height: 1.6; margin-bottom: 30px; font-weight: 300;">"The collaboration features are seamless. It feels like we are in the same room."</p>
            <div style="display: flex; align-items: center; gap: 15px;">
              <img src="https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=100&auto=format&fit=crop" style="width: 50px; height: 50px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.2); object-fit: cover;" alt="User">
              <div><div style="font-weight: 700; font-size: 1rem;">Chloe O.</div><div style="font-size: 0.8rem; opacity: 0.7;">Manager</div></div>
            </div>
          </div>
        </div>
      </div>
    </section>`
  },

  // ============================================
  // PRESET 9: THE SPLIT SCROLL
  // ============================================
  {
    id: 'testi-split-scroll',
    label: 'Split Scroll',
    category: 'Testimonials',
    content: `<section style="padding: 120px 20px; background: #fff; font-family: 'Inter', sans-serif;">
      <div style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1.5fr; gap: 80px;">
        <div style="position: sticky; top: 100px; height: fit-content;">
          <h2 style="font-size: 3rem; font-weight: 800; letter-spacing: -1.5px; line-height: 1; margin-bottom: 20px; color: #0f172a;">What our<br>customers say.</h2>
          <p style="font-size: 1.1rem; color: #64748b; margin-bottom: 40px;">Rated 4.9/5 by over 10,000 happy users on G2 and Capterra.</p>
          <div style="display: flex; align-items: center; gap: 15px; font-weight: 700; font-size: 1.2rem;">
            <span>4.9</span>
            <div style="display: flex; color: #f59e0b;">★★★★★</div>
          </div>
        </div>
        <div style="display: flex; flex-direction: column; gap: 30px;">
          <div style="background: #f8fafc; padding: 40px; border-radius: 20px; border: 1px solid #e2e8f0;">
            <p style="font-size: 1.1rem; color: #334155; margin-bottom: 20px; line-height: 1.6;">"I was skeptical at first, but the onboarding process was incredibly smooth. We were up and running in less than an hour. The ROI was evident from week one."</p>
            <div style="font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px;">
              <div style="width: 20px; height: 2px; background: #cbd5e1;"></div> Michael R.
            </div>
          </div>
          <div style="background: #f8fafc; padding: 40px; border-radius: 20px; border: 1px solid #e2e8f0;">
            <p style="font-size: 1.1rem; color: #334155; margin-bottom: 20px; line-height: 1.6;">"The best feature release cycle I have ever seen. They actually listen to user feedback and implement it. It keeps getting better every month."</p>
            <div style="font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px;">
              <div style="width: 20px; height: 2px; background: #cbd5e1;"></div> Sarah L.
            </div>
          </div>
          <div style="background: #f8fafc; padding: 40px; border-radius: 20px; border: 1px solid #e2e8f0;">
            <p style="font-size: 1.1rem; color: #334155; margin-bottom: 20px; line-height: 1.6;">"Security was our main concern, and they exceeded all expectations with their compliance standards. Enterprise ready from day one."</p>
            <div style="font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px;">
              <div style="width: 20px; height: 2px; background: #cbd5e1;"></div> James K.
            </div>
          </div>
        </div>
      </div>
    </section>`
  }
]
