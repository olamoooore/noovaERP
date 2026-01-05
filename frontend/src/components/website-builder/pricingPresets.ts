// Pricing Presets - 6 Premium Pricing Table Designs
// Extracted from pricining-presets.html with full HTML/CSS/SVG support

export interface Preset {
  id: string
  label: string
  category: string
  content: string
}

export const pricingPresets: Preset[] = [
  // 1. The Linear Elite
  {
    id: 'pricing-linear-elite',
    label: 'Linear Elite',
    category: 'Pricing',
    content: `
      <section style="padding: 128px 24px; position: relative; overflow: hidden; background: #0a0a0a;">
        <div style="position: absolute; inset: 0;">
          <div style="position: absolute; top: 0; left: 25%; width: 500px; height: 500px; background: rgba(139, 92, 246, 0.15); border-radius: 50%; filter: blur(120px);"></div>
          <div style="position: absolute; bottom: 0; right: 25%; width: 400px; height: 400px; background: rgba(59, 130, 246, 0.1); border-radius: 50%; filter: blur(100px);"></div>
        </div>
        <div style="position: relative; z-index: 10; max-width: 1280px; margin: 0 auto;">
          <div style="text-align: center; margin-bottom: 80px;">
            <h2 style="font-size: 48px; font-weight: 700; margin-bottom: 24px; color: #ffffff; letter-spacing: -0.02em;">Plans that scale</h2>
            <p style="font-size: 20px; color: rgba(255,255,255,0.5); max-width: 672px; margin: 0 auto; font-weight: 300;">Transparent pricing with no hidden fees. Upgrade anytime.</p>
          </div>
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; max-width: 1120px; margin: 0 auto;">
            <div style="position: relative; background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 32px;">
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px;">
                <div>
                  <h3 style="font-size: 18px; font-weight: 500; color: #ffffff; margin-bottom: 4px;">Starter</h3>
                  <p style="font-size: 14px; color: rgba(255,255,255,0.4);">For individuals</p>
                </div>
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                </div>
              </div>
              <div style="display: flex; align-items: baseline; gap: 4px; margin-bottom: 8px;">
                <span style="font-size: 48px; font-weight: 600; color: #ffffff; letter-spacing: -0.02em;">$0</span>
                <span style="color: rgba(255,255,255,0.4); font-size: 18px;">/mo</span>
              </div>
              <p style="color: rgba(255,255,255,0.4); font-size: 14px; margin-bottom: 32px;">Forever free</p>
              <button style="width: 100%; padding: 14px 16px; border-radius: 12px; font-weight: 500; color: rgba(255,255,255,0.6); background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer; margin-bottom: 32px;">Get Started</button>
              <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.5); font-size: 14px;">Up to 3 projects</span></li>
                <li style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.5); font-size: 14px;">Basic analytics</span></li>
                <li style="display: flex; align-items: center; gap: 12px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.5); font-size: 14px;">Community support</span></li>
              </ul>
            </div>
            <div style="position: relative; background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 32px; transform: translateY(-16px); box-shadow: 0 0 40px rgba(124, 58, 237, 0.15), 0 0 80px rgba(124, 58, 237, 0.1);">
              <div style="position: absolute; top: -12px; left: 50%; transform: translateX(-50%); padding: 6px 16px; background: rgba(139, 92, 246, 0.2); color: #c4b5fd; font-size: 12px; font-weight: 500; border-radius: 9999px; border: 1px solid rgba(139, 92, 246, 0.3);">Most Popular</div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px;">
                <div>
                  <h3 style="font-size: 18px; font-weight: 500; color: #ffffff; margin-bottom: 4px;">Pro</h3>
                  <p style="font-size: 14px; color: rgba(255,255,255,0.4);">For growing teams</p>
                </div>
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(139, 92, 246, 0.2); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: #a78bfa;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
              </div>
              <div style="display: flex; align-items: baseline; gap: 4px; margin-bottom: 8px;">
                <span style="font-size: 48px; font-weight: 600; color: #ffffff; letter-spacing: -0.02em;">$29</span>
                <span style="color: rgba(255,255,255,0.4); font-size: 18px;">/mo</span>
              </div>
              <p style="color: rgba(255,255,255,0.4); font-size: 14px; margin-bottom: 32px;">Billed monthly</p>
              <button style="width: 100%; padding: 14px 16px; border-radius: 12px; font-weight: 600; color: #ffffff; background: linear-gradient(to right, #7c3aed, #9333ea); border: none; cursor: pointer; margin-bottom: 32px; box-shadow: 0 10px 25px rgba(139, 92, 246, 0.25);">Start Free Trial</button>
              <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #a78bfa;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: #ffffff; font-size: 14px; font-weight: 500;">Unlimited projects</span></li>
                <li style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #a78bfa;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.7); font-size: 14px;">Advanced analytics</span></li>
                <li style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #a78bfa;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.7); font-size: 14px;">Priority support</span></li>
                <li style="display: flex; align-items: center; gap: 12px;"><svg style="width: 18px; height: 18px; color: #a78bfa;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.7); font-size: 14px;">Custom domains</span></li>
              </ul>
            </div>
            <div style="position: relative; background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 32px;">
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px;">
                <div>
                  <h3 style="font-size: 18px; font-weight: 500; color: #ffffff; margin-bottom: 4px;">Enterprise</h3>
                  <p style="font-size: 14px; color: rgba(255,255,255,0.4);">For organizations</p>
                </div>
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
              </div>
              <div style="display: flex; align-items: baseline; gap: 4px; margin-bottom: 8px;">
                <span style="font-size: 48px; font-weight: 600; color: #ffffff; letter-spacing: -0.02em;">$99</span>
                <span style="color: rgba(255,255,255,0.4); font-size: 18px;">/mo</span>
              </div>
              <p style="color: rgba(255,255,255,0.4); font-size: 14px; margin-bottom: 32px;">Custom billing</p>
              <button style="width: 100%; padding: 14px 16px; border-radius: 12px; font-weight: 500; color: rgba(255,255,255,0.6); background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer; margin-bottom: 32px;">Contact Sales</button>
              <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.5); font-size: 14px;">Everything in Pro</span></li>
                <li style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.5); font-size: 14px;">SSO & SAML</span></li>
                <li style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.5); font-size: 14px;">Dedicated manager</span></li>
                <li style="display: flex; align-items: center; gap: 12px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="color: rgba(255,255,255,0.5); font-size: 14px;">SLA guarantee</span></li>
              </ul>
            </div>
          </div>
        </div>
      </section>
    `
  },

  // 2. The Luxury Split
  {
    id: 'pricing-luxury-split',
    label: 'Luxury Split',
    category: 'Pricing',
    content: `
      <section style="padding: 128px 24px; position: relative; overflow: hidden; background: #0d0d0d;">
        <div style="position: absolute; top: 50%; right: 0; width: 600px; height: 600px; background: rgba(16, 185, 129, 0.08); border-radius: 50%; filter: blur(150px);"></div>
        <div style="position: relative; z-index: 10; max-width: 1280px; margin: 0 auto;">
          <div style="text-align: center; margin-bottom: 80px;">
            <h2 style="font-size: 48px; font-weight: 700; margin-bottom: 24px; color: #ffffff; font-family: 'Playfair Display', serif; letter-spacing: -0.02em;">Editorial excellence</h2>
            <p style="font-size: 20px; color: rgba(255,255,255,0.4); max-width: 672px; margin: 0 auto; font-weight: 300;">Premium pricing for those who value quality.</p>
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 32px; max-width: 1024px; margin: 0 auto;">
            <div style="display: flex; flex-direction: column; justify-content: center; padding-right: 32px;">
              <div style="margin-bottom: 32px;">
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                  <div style="width: 48px; height: 48px; border-radius: 16px; background: rgba(16, 185, 129, 0.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg style="width: 24px; height: 24px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                  </div>
                  <div>
                    <h4 style="font-size: 20px; font-weight: 500; color: #ffffff; margin-bottom: 8px;">Lightning fast</h4>
                    <p style="color: rgba(255,255,255,0.4); line-height: 1.6;">Optimized infrastructure ensures your projects load instantly, anywhere in the world.</p>
                  </div>
                </div>
              </div>
              <div style="margin-bottom: 32px;">
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                  <div style="width: 48px; height: 48px; border-radius: 16px; background: rgba(16, 185, 129, 0.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg style="width: 24px; height: 24px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  </div>
                  <div>
                    <h4 style="font-size: 20px; font-weight: 500; color: #ffffff; margin-bottom: 8px;">Enterprise security</h4>
                    <p style="color: rgba(255,255,255,0.4); line-height: 1.6;">Bank-grade encryption and compliance certifications out of the box.</p>
                  </div>
                </div>
              </div>
              <div>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                  <div style="width: 48px; height: 48px; border-radius: 16px; background: rgba(16, 185, 129, 0.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg style="width: 24px; height: 24px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                  </div>
                  <div>
                    <h4 style="font-size: 20px; font-weight: 500; color: #ffffff; margin-bottom: 8px;">Unlimited scale</h4>
                    <p style="color: rgba(255,255,255,0.4); line-height: 1.6;">Grow from zero to millions without ever worrying about infrastructure limits.</p>
                  </div>
                </div>
              </div>
            </div>
            <div>
              <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 28px; padding: 32px; position: relative; overflow: hidden; margin-bottom: 16px;">
                <div style="position: absolute; inset: 0; background: linear-gradient(to right, rgba(16, 185, 129, 0.05), transparent);"></div>
                <div style="position: relative; display: flex; flex-direction: column;">
                  <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px;">
                    <div>
                      <h3 style="font-size: 24px; font-weight: 500; color: #ffffff; margin-bottom: 4px;">Pro</h3>
                      <p style="color: rgba(255,255,255,0.4);">For professionals</p>
                    </div>
                    <span style="padding: 4px 12px; background: rgba(16, 185, 129, 0.2); color: #6ee7b7; font-size: 12px; font-weight: 500; border-radius: 9999px;">Recommended</span>
                  </div>
                  <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                    <span style="font-size: 60px; font-weight: 600; color: #ffffff; letter-spacing: -0.02em;">$49</span>
                    <span style="color: rgba(255,255,255,0.4); font-size: 18px;">/mo</span>
                  </div>
                  <p style="color: rgba(255,255,255,0.4); font-size: 14px; margin-bottom: 32px;">Billed annually</p>
                  <button style="width: 100%; padding: 16px 24px; border-radius: 12px; font-weight: 600; color: #064e3b; background: #34d399; border: none; cursor: pointer; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.2);">Start Free Trial</button>
                  <div style="margin-top: 32px;">
                    <div style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.7); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>Unlimited everything</span></div>
                    <div style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.7); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>Advanced integrations</span></div>
                    <div style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.7);"><svg style="width: 18px; height: 18px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>24/7 priority support</span></div>
                  </div>
                </div>
              </div>
              <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 24px; opacity: 0.6;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                  <div>
                    <h3 style="font-size: 18px; font-weight: 500; color: #ffffff;">Basic</h3>
                    <p style="color: rgba(255,255,255,0.4); font-size: 14px;">For starters</p>
                  </div>
                  <div style="text-align: right;">
                    <span style="font-size: 30px; font-weight: 600; color: #ffffff;">$19</span>
                    <span style="color: rgba(255,255,255,0.4); font-size: 14px;">/mo</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    `
  },

  // 3. The Glass Bento
  {
    id: 'pricing-glass-bento',
    label: 'Glass Bento',
    category: 'Pricing',
    content: `
      <section style="padding: 128px 24px; position: relative; overflow: hidden; background: #0a0a0a;">
        <div style="position: absolute; inset: 0;">
          <div style="position: absolute; top: 0; left: 33%; width: 400px; height: 400px; background: rgba(139, 92, 246, 0.1); border-radius: 50%; filter: blur(100px);"></div>
          <div style="position: absolute; bottom: 0; right: 33%; width: 500px; height: 500px; background: rgba(236, 72, 153, 0.08); border-radius: 50%; filter: blur(120px);"></div>
        </div>
        <div style="position: relative; z-index: 10; max-width: 1280px; margin: 0 auto;">
          <div style="text-align: center; margin-bottom: 80px;">
            <h2 style="font-size: 48px; font-weight: 700; margin-bottom: 24px; color: #ffffff; letter-spacing: -0.02em;">Modular power</h2>
            <p style="font-size: 20px; color: rgba(255,255,255,0.4); max-width: 672px; margin: 0 auto; font-weight: 300;">Build your perfect setup with flexible modules.</p>
          </div>
          <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; max-width: 1024px; margin: 0 auto;">
            <div style="grid-column: span 2; background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 24px;">
              <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                  <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center;">
                    <svg style="width: 20px; height: 20px; color: rgba(255,255,255,0.6);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                  </div>
                  <div>
                    <h3 style="font-size: 18px; font-weight: 500; color: #ffffff;">Free</h3>
                    <p style="color: rgba(255,255,255,0.4); font-size: 12px;">Forever</p>
                  </div>
                </div>
                <span style="padding: 4px 8px; background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.5); font-size: 12px; border-radius: 8px;">Basic</span>
              </div>
              <div style="margin-bottom: 24px;"><span style="font-size: 36px; font-weight: 600; color: #ffffff;">$0</span></div>
              <button style="width: 100%; padding: 10px 16px; border-radius: 12px; font-weight: 500; color: rgba(255,255,255,0.6); background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer;">Get Started</button>
              <div style="margin-top: 24px;">
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.5); font-size: 12px; margin-bottom: 12px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.3);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>1 project</span></div>
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.5); font-size: 12px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.3);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>Community features</span></div>
              </div>
            </div>
            <div style="grid-column: span 2; background: linear-gradient(to bottom right, rgba(139, 92, 246, 0.1), transparent); backdrop-filter: blur(40px); border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 24px; padding: 24px; transform: translateY(-8px);">
              <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                  <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(139, 92, 246, 0.2); display: flex; align-items: center; justify-content: center;">
                    <svg style="width: 20px; height: 20px; color: #a78bfa;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                  </div>
                  <div>
                    <h3 style="font-size: 18px; font-weight: 500; color: #ffffff;">Pro</h3>
                    <p style="color: rgba(255,255,255,0.4); font-size: 12px;">Most popular</p>
                  </div>
                </div>
                <span style="padding: 4px 8px; background: rgba(139, 92, 246, 0.2); color: #c4b5fd; font-size: 12px; border-radius: 8px;">Popular</span>
              </div>
              <div style="margin-bottom: 24px;"><span style="font-size: 36px; font-weight: 600; color: #ffffff;">$29</span></div>
              <button style="width: 100%; padding: 10px 16px; border-radius: 12px; font-weight: 600; color: #ffffff; background: #7c3aed; border: none; cursor: pointer; box-shadow: 0 10px 25px rgba(139, 92, 246, 0.25);">Start Trial</button>
              <div style="margin-top: 24px;">
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.7); font-size: 12px; margin-bottom: 12px;"><svg style="width: 18px; height: 18px; color: #a78bfa;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>Unlimited projects</span></div>
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.7); font-size: 12px; margin-bottom: 12px;"><svg style="width: 18px; height: 18px; color: #a78bfa;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>Advanced analytics</span></div>
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.7); font-size: 12px;"><svg style="width: 18px; height: 18px; color: #a78bfa;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>Priority support</span></div>
              </div>
            </div>
            <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 20px;">
              <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(251, 191, 36, 0.1); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 16px; height: 16px; color: #fbbf24;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/></svg>
                </div>
                <span style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.8);">Team Seats</span>
              </div>
              <p style="color: rgba(255,255,255,0.4); font-size: 12px; margin-bottom: 16px;">Add more members</p>
              <div style="font-size: 24px; font-weight: 600; color: #ffffff; margin-bottom: 16px;">+$8<span style="font-size: 14px; font-weight: 400; color: rgba(255,255,255,0.4);">/seat</span></div>
              <button style="width: 100%; padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 500; color: rgba(255,255,255,0.6); background: rgba(255,255,255,0.05); border: none; cursor: pointer;">Add</button>
            </div>
            <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 20px;">
              <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(34, 211, 238, 0.1); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 16px; height: 16px; color: #22d3ee;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                </div>
                <span style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.8);">Storage</span>
              </div>
              <p style="color: rgba(255,255,255,0.4); font-size: 12px; margin-bottom: 16px;">Expand limits</p>
              <div style="font-size: 24px; font-weight: 600; color: #ffffff; margin-bottom: 16px;">+$5<span style="font-size: 14px; font-weight: 400; color: rgba(255,255,255,0.4);">/10GB</span></div>
              <button style="width: 100%; padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 500; color: rgba(255,255,255,0.6); background: rgba(255,255,255,0.05); border: none; cursor: pointer;">Add</button>
            </div>
            <div style="grid-column: span 2; grid-row: span 2; background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 24px;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: rgba(255,255,255,0.6);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div>
                  <h3 style="font-size: 18px; font-weight: 500; color: #ffffff;">Enterprise</h3>
                  <p style="color: rgba(255,255,255,0.4); font-size: 12px;">Custom</p>
                </div>
              </div>
              <p style="color: rgba(255,255,255,0.5); font-size: 14px; margin-bottom: 24px; line-height: 1.6;">For large organizations with advanced needs and compliance requirements.</p>
              <div style="margin-bottom: 24px;"><span style="font-size: 36px; font-weight: 600; color: #ffffff;">Custom</span></div>
              <button style="width: 100%; padding: 12px 16px; border-radius: 12px; font-weight: 500; color: #ffffff; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer; margin-bottom: 32px;">Contact Sales</button>
              <div>
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.6); font-size: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>SSO & SAML</span></div>
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.6); font-size: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>Dedicated support</span></div>
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.6); font-size: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>Custom SLAs</span></div>
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.6); font-size: 12px; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>On-premise</span></div>
                <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.6); font-size: 12px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span>Audit logs</span></div>
              </div>
            </div>
          </div>
        </div>
      </section>
    `
  },

  // 4. The Atmospheric Toggle
  {
    id: 'pricing-atmospheric-toggle',
    label: 'Atmospheric',
    category: 'Pricing',
    content: `
      <section style="padding: 128px 24px; position: relative; overflow: hidden; background: #0a0a0a;">
        <div style="position: absolute; inset: 0; overflow: hidden;">
          <div style="position: absolute; top: 25%; left: -128px; width: 384px; height: 384px; background: rgba(16, 185, 129, 0.2); border-radius: 50%; filter: blur(100px);"></div>
          <div style="position: absolute; bottom: 25%; right: -128px; width: 384px; height: 384px; background: rgba(34, 211, 238, 0.15); border-radius: 50%; filter: blur(100px);"></div>
          <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 600px; height: 600px; background: rgba(59, 130, 246, 0.1); border-radius: 50%; filter: blur(120px);"></div>
        </div>
        <div style="position: relative; z-index: 10; max-width: 1280px; margin: 0 auto;">
          <div style="text-align: center; margin-bottom: 80px;">
            <h2 style="font-size: 48px; font-weight: 700; margin-bottom: 24px; color: #ffffff; font-family: 'Playfair Display', serif; letter-spacing: -0.02em;">Invest in growth</h2>
            <p style="font-size: 20px; color: rgba(255,255,255,0.4); max-width: 672px; margin: 0 auto; font-weight: 300;">Flexible billing that adapts to your needs.</p>
          </div>
          <div style="display: flex; justify-content: center; margin-bottom: 64px;">
            <div style="position: relative; display: inline-flex; align-items: center; background: rgba(255,255,255,0.05); border-radius: 9999px; padding: 4px; border: 1px solid rgba(255,255,255,0.1);">
              <button style="padding: 10px 32px; border-radius: 9999px; font-size: 14px; font-weight: 500; background: rgba(255,255,255,0.1); color: #ffffff; border: none; cursor: pointer;">Monthly</button>
              <button style="padding: 10px 32px; border-radius: 9999px; font-size: 14px; font-weight: 500; background: transparent; color: rgba(255,255,255,0.5); border: none; cursor: pointer;">Yearly <span style="margin-left: 6px; padding: 2px 8px; background: rgba(16, 185, 129, 0.2); color: #6ee7b7; font-size: 12px; border-radius: 9999px;">Save 20%</span></button>
            </div>
          </div>
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; max-width: 1120px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 32px;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: rgba(255,255,255,0.5);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                </div>
                <h3 style="font-size: 18px; font-weight: 500; color: #ffffff;">Starter</h3>
              </div>
              <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 48px; font-weight: 600; color: #ffffff; letter-spacing: -0.02em;">$19</span>
                <span style="color: rgba(255,255,255,0.4); font-size: 18px;">/mo</span>
              </div>
              <p style="color: rgba(255,255,255,0.4); font-size: 14px; margin-bottom: 32px;">Essential features</p>
              <button style="width: 100%; padding: 14px 16px; border-radius: 12px; font-weight: 500; color: #ffffff; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer; margin-bottom: 32px;">Get Started</button>
              <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.6); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(16, 185, 129, 0.8);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Up to 5 projects</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.6); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(16, 185, 129, 0.8);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Basic analytics</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.6);"><svg style="width: 18px; height: 18px; color: rgba(16, 185, 129, 0.8);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Email support</span></li>
              </ul>
            </div>
            <div style="position: relative; background: linear-gradient(to bottom, rgba(16, 185, 129, 0.1), transparent); backdrop-filter: blur(40px); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 28px; padding: 32px; transform: translateY(-16px); box-shadow: 0 0 40px rgba(16, 185, 129, 0.15), 0 0 80px rgba(16, 185, 129, 0.1);">
              <div style="position: absolute; top: -12px; left: 50%; transform: translateX(-50%); padding: 6px 16px; background: rgba(16, 185, 129, 0.2); color: #6ee7b7; font-size: 12px; font-weight: 500; border-radius: 9999px; border: 1px solid rgba(16, 185, 129, 0.3);">Best Value</div>
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px; margin-top: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(16, 185, 129, 0.2); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <h3 style="font-size: 18px; font-weight: 500; color: #ffffff;">Professional</h3>
              </div>
              <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 48px; font-weight: 600; color: #ffffff; letter-spacing: -0.02em;">$49</span>
                <span style="color: rgba(255,255,255,0.4); font-size: 18px;">/mo</span>
              </div>
              <p style="color: rgba(255,255,255,0.4); font-size: 14px; margin-bottom: 32px;">For growing teams</p>
              <button style="width: 100%; padding: 14px 16px; border-radius: 12px; font-weight: 600; color: #064e3b; background: #34d399; border: none; cursor: pointer; margin-bottom: 32px; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.25);">Start Free Trial</button>
              <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="display: flex; align-items: center; gap: 12px; color: #ffffff; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px; font-weight: 500;">Unlimited projects</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.7); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Advanced analytics</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.7); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Priority support</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.7);"><svg style="width: 18px; height: 18px; color: #34d399;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Team collaboration</span></li>
              </ul>
            </div>
            <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 32px;">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: rgba(255,255,255,0.5);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <h3 style="font-size: 18px; font-weight: 500; color: #ffffff;">Enterprise</h3>
              </div>
              <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 48px; font-weight: 600; color: #ffffff; letter-spacing: -0.02em;">$99</span>
                <span style="color: rgba(255,255,255,0.4); font-size: 18px;">/mo</span>
              </div>
              <p style="color: rgba(255,255,255,0.4); font-size: 14px; margin-bottom: 32px;">For organizations</p>
              <button style="width: 100%; padding: 14px 16px; border-radius: 12px; font-weight: 500; color: #ffffff; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer; margin-bottom: 32px;">Contact Sales</button>
              <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.6); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Everything in Pro</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.6); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Custom integrations</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.6);"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Dedicated account manager</span></li>
              </ul>
            </div>
          </div>
        </div>
      </section>
    `
  },

  // 5. The Premium Stack
  {
    id: 'pricing-premium-stack',
    label: 'Premium Stack',
    category: 'Pricing',
    content: `
      <section style="padding: 128px 24px; position: relative; overflow: hidden; background: #0a0a0a;">
        <div style="max-width: 1280px; margin: 0 auto;">
          <div style="text-align: center; margin-bottom: 80px;">
            <h2 style="font-size: 48px; font-weight: 700; margin-bottom: 24px; color: #ffffff; letter-spacing: -0.02em;">Plans that grow</h2>
            <p style="font-size: 20px; color: rgba(255,255,255,0.4); max-width: 672px; margin: 0 auto; font-weight: 300;">Start small and scale as you need.</p>
          </div>
          <div style="max-width: 896px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 24px; margin-bottom: 16px;">
              <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 24px;">
                <div style="width: 224px; flex-shrink: 0;">
                  <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="width: 6px; height: 56px; background: linear-gradient(to bottom, #3b82f6, #2563eb); border-radius: 9999px;"></div>
                    <div>
                      <h3 style="font-size: 20px; font-weight: 500; color: #ffffff;">Starter</h3>
                      <p style="color: rgba(255,255,255,0.4); font-size: 14px;">For beginners</p>
                    </div>
                  </div>
                </div>
                <div style="flex: 1;">
                  <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                    <span style="padding: 4px 12px; background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.5); font-size: 12px; border-radius: 9999px; border: 1px solid rgba(255,255,255,0.1);">1 User</span>
                    <span style="padding: 4px 12px; background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.5); font-size: 12px; border-radius: 9999px; border: 1px solid rgba(255,255,255,0.1);">5 Projects</span>
                    <span style="padding: 4px 12px; background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.5); font-size: 12px; border-radius: 9999px; border: 1px solid rgba(255,255,255,0.1);">Basic Support</span>
                  </div>
                  <p style="color: rgba(255,255,255,0.5); font-size: 14px;">Perfect for individuals just getting started with their journey.</p>
                </div>
                <div style="width: 192px; text-align: right; flex-shrink: 0;">
                  <div style="margin-bottom: 16px;">
                    <span style="font-size: 30px; font-weight: 600; color: #ffffff;">$9</span>
                    <span style="color: rgba(255,255,255,0.4);">/mo</span>
                  </div>
                  <button style="padding: 10px 32px; border-radius: 12px; font-weight: 500; color: #ffffff; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer;">Choose</button>
                </div>
              </div>
            </div>
            <div style="background: linear-gradient(to right, rgba(251, 191, 36, 0.1), rgba(234, 179, 8, 0.05), transparent); backdrop-filter: blur(40px); border: 1px solid rgba(251, 191, 36, 0.2); border-radius: 24px; padding: 32px; margin-bottom: 16px; transform: scale(1.02);">
              <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 24px;">
                <div style="width: 224px; flex-shrink: 0;">
                  <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="width: 6px; height: 56px; background: linear-gradient(to bottom, #f59e0b, #f97316); border-radius: 9999px; box-shadow: 0 10px 25px rgba(251, 191, 36, 0.3);"></div>
                    <div>
                      <h3 style="font-size: 20px; font-weight: 500; color: #ffffff;">Professional</h3>
                      <p style="color: rgba(251, 191, 36, 0.7); font-size: 14px;">Most popular</p>
                    </div>
                  </div>
                </div>
                <div style="flex: 1;">
                  <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                    <span style="padding: 4px 12px; background: rgba(251, 191, 36, 0.1); color: #fcd34d; font-size: 12px; border-radius: 9999px; border: 1px solid rgba(251, 191, 36, 0.2);">5 Users</span>
                    <span style="padding: 4px 12px; background: rgba(251, 191, 36, 0.1); color: #fcd34d; font-size: 12px; border-radius: 9999px; border: 1px solid rgba(251, 191, 36, 0.2);">Unlimited Projects</span>
                    <span style="padding: 4px 12px; background: rgba(251, 191, 36, 0.1); color: #fcd34d; font-size: 12px; border-radius: 9999px; border: 1px solid rgba(251, 191, 36, 0.2);">Priority Support</span>
                  </div>
                  <p style="color: rgba(255,255,255,0.5); font-size: 14px;">Ideal for growing teams that need more power and flexibility.</p>
                </div>
                <div style="width: 192px; text-align: right; flex-shrink: 0;">
                  <div style="margin-bottom: 16px;">
                    <span style="font-size: 30px; font-weight: 600; color: #ffffff;">$39</span>
                    <span style="color: rgba(255,255,255,0.4);">/mo</span>
                  </div>
                  <button style="padding: 10px 32px; border-radius: 12px; font-weight: 600; color: #78350f; background: linear-gradient(to right, #fbbf24, #facc15); border: none; cursor: pointer; box-shadow: 0 10px 25px rgba(251, 191, 36, 0.25);">Choose</button>
                </div>
              </div>
            </div>
            <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 24px;">
              <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 24px;">
                <div style="width: 224px; flex-shrink: 0;">
                  <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="width: 6px; height: 56px; background: linear-gradient(to bottom, #22c55e, #059669); border-radius: 9999px;"></div>
                    <div>
                      <h3 style="font-size: 20px; font-weight: 500; color: #ffffff;">Enterprise</h3>
                      <p style="color: rgba(255,255,255,0.4); font-size: 14px;">For organizations</p>
                    </div>
                  </div>
                </div>
                <div style="flex: 1;">
                  <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                    <span style="padding: 4px 12px; background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.5); font-size: 12px; border-radius: 9999px; border: 1px solid rgba(255,255,255,0.1);">Unlimited Users</span>
                    <span style="padding: 4px 12px; background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.5); font-size: 12px; border-radius: 9999px; border: 1px solid rgba(255,255,255,0.1);">Everything Included</span>
                    <span style="padding: 4px 12px; background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.5); font-size: 12px; border-radius: 9999px; border: 1px solid rgba(255,255,255,0.1);">24/7 Support</span>
                  </div>
                  <p style="color: rgba(255,255,255,0.5); font-size: 14px;">Complete solution for large organizations with advanced requirements.</p>
                </div>
                <div style="width: 192px; text-align: right; flex-shrink: 0;">
                  <div style="margin-bottom: 16px;">
                    <span style="font-size: 30px; font-weight: 600; color: #ffffff;">$99</span>
                    <span style="color: rgba(255,255,255,0.4);">/mo</span>
                  </div>
                  <button style="padding: 10px 32px; border-radius: 12px; font-weight: 500; color: #ffffff; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer;">Contact Us</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    `
  },

  // 6. The Spotlight Hero
  {
    id: 'pricing-spotlight-hero',
    label: 'Spotlight Hero',
    category: 'Pricing',
    content: `
      <section style="padding: 128px 24px; position: relative; overflow: hidden; background: #050505;">
        <div style="position: absolute; inset: 0;">
          <div style="position: absolute; top: 0; left: 50%; transform: translateX(-50%); width: 800px; height: 600px; background: rgba(255,255,255,0.03); border-radius: 50%; filter: blur(150px);"></div>
        </div>
        <div style="position: relative; z-index: 10; max-width: 1280px; margin: 0 auto;">
          <div style="text-align: center; margin-bottom: 80px;">
            <h2 style="font-size: 48px; font-weight: 700; margin-bottom: 24px; color: #ffffff; letter-spacing: -0.02em;">Choose your path</h2>
            <p style="font-size: 20px; color: rgba(255,255,255,0.4); max-width: 672px; margin: 0 auto; font-weight: 300;">Premium options for every stage.</p>
          </div>
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; max-width: 1120px; margin: 0 auto; align-items: flex-start;">
            <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 32px; opacity: 0.4; transform: scale(0.95);">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 32px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                </div>
                <h3 style="font-size: 18px; font-weight: 500; color: rgba(255,255,255,0.6);">Standard</h3>
              </div>
              <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 48px; font-weight: 600; color: rgba(255,255,255,0.6); letter-spacing: -0.02em;">$29</span>
                <span style="color: rgba(255,255,255,0.3); font-size: 18px;">/mo</span>
              </div>
              <p style="color: rgba(255,255,255,0.3); font-size: 14px; margin-bottom: 32px;">Essential features for individuals</p>
              <button style="width: 100%; padding: 14px 16px; border-radius: 12px; font-weight: 500; color: rgba(255,255,255,0.4); background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer; margin-bottom: 32px;">Get Started</button>
              <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.4); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.2);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Up to 5 projects</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.4); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.2);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Basic analytics</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.4);"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.2);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Email support</span></li>
              </ul>
            </div>
            <div style="position: relative; background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(255,255,255,0.95) 100%); border-radius: 32px; padding: 32px; transform: translateY(-16px); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
              <div style="position: absolute; top: -12px; left: 50%; transform: translateX(-50%); padding: 6px 16px; background: #111827; color: #ffffff; font-size: 12px; font-weight: 500; border-radius: 9999px;">Recommended</div>
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 32px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: #f3f4f6; display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: #111827;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <h3 style="font-size: 18px; font-weight: 500; color: #111827;">Professional</h3>
              </div>
              <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 48px; font-weight: 600; color: #111827; letter-spacing: -0.02em;">$49</span>
                <span style="color: #9ca3af; font-size: 18px;">/mo</span>
              </div>
              <p style="color: #6b7280; font-size: 14px; margin-bottom: 32px;">For growing teams</p>
              <button style="width: 100%; padding: 14px 16px; border-radius: 12px; font-weight: 600; color: #ffffff; background: #111827; border: none; cursor: pointer; margin-bottom: 32px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);">Start Free Trial</button>
              <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="display: flex; align-items: center; gap: 12px; color: #374151; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #111827;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px; font-weight: 500;">Everything in Standard</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: #4b5563; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #9ca3af;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Unlimited projects</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: #4b5563; margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: #9ca3af;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Advanced analytics</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: #4b5563;"><svg style="width: 18px; height: 18px; color: #9ca3af;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Priority support</span></li>
              </ul>
            </div>
            <div style="background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); backdrop-filter: blur(40px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 32px; opacity: 0.4; transform: scale(0.95);">
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 32px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center;">
                  <svg style="width: 20px; height: 20px; color: rgba(255,255,255,0.4);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <h3 style="font-size: 18px; font-weight: 500; color: rgba(255,255,255,0.6);">Enterprise</h3>
              </div>
              <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 48px; font-weight: 600; color: rgba(255,255,255,0.6); letter-spacing: -0.02em;">Custom</span>
              </div>
              <p style="color: rgba(255,255,255,0.3); font-size: 14px; margin-bottom: 32px;">For large organizations</p>
              <button style="width: 100%; padding: 14px 16px; border-radius: 12px; font-weight: 500; color: rgba(255,255,255,0.4); background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer; margin-bottom: 32px;">Contact Sales</button>
              <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.4); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.2);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Everything in Pro</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.4); margin-bottom: 16px;"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.2);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">SSO & SAML</span></li>
                <li style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.4);"><svg style="width: 18px; height: 18px; color: rgba(255,255,255,0.2);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span style="font-size: 14px;">Dedicated manager</span></li>
              </ul>
            </div>
          </div>
        </div>
      </section>
    `
  },
  // 7. The Dual-Tone (High Contrast)
  {
    id: 'pricing-dual-tone',
    label: 'The Dual-Tone',
    category: 'Pricing',
    content: `
      <section style="padding: 60px 20px; display: flex; justify-content: center; font-family: 'Outfit', sans-serif; background: #f0f2f5;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; max-width: 900px; width: 100%;">
          
          <!-- Light Card -->
          <div style="background: #ffffff; color: #111111; border-radius: 24px; padding: 40px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 20px 40px rgba(0,0,0,0.05); transition: transform 0.3s ease;">
            <div>
              <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div style="font-size: 1.1rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                  <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> 
                  Personal
                </div>
              </div>
              <div style="font-size: 3.5rem; font-weight: 700; line-height: 1; margin-bottom: 5px;">$8<span style="font-size: 1rem; font-weight: 400; opacity: 0.6;">/mo</span></div>
              <p style="font-size: 0.9rem; color: #666666; margin-bottom: 30px;">Best value for solo creators starting out.</p>
              
              <ul style="list-style: none; margin-bottom: 40px; display: flex; flex-direction: column; gap: 20px; padding: 0;">
                <li style="display: flex; align-items: flex-start; gap: 16px;">
                  <div style="width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; padding: 10px; background: #f3f4f6; color: #111111;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                  </div>
                  <div style="flex: 1;">
                    <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 2px;">Massive Icon Collection</h4>
                    <p style="font-size: 0.8rem; opacity: 0.7; line-height: 1.4;">40K+ flat, 3D, animated icons.</p>
                  </div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 16px;">
                  <div style="width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; padding: 10px; background: #f3f4f6; color: #111111;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                  </div>
                  <div style="flex: 1;">
                    <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 2px;">Unlimited Access</h4>
                    <p style="font-size: 0.8rem; opacity: 0.7; line-height: 1.4;">Use anywhere, with free updates.</p>
                  </div>
                </li>
              </ul>
            </div>
            <div>
              <a href="#" style="display: block; width: 100%; padding: 16px; border-radius: 12px; text-align: center; text-decoration: none; font-weight: 700; transition: 0.2s; background: #000000; color: #ffffff;">Upgrade $99</a>
              <div style="text-align: center; font-size: 0.8rem; opacity: 0.5; margin-top: 15px;">Billed yearly</div>
            </div>
          </div>

          <!-- Dark Card -->
          <div style="background: #000000; color: #ffffff; border-radius: 24px; padding: 40px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 20px 40px rgba(0,0,0,0.3); transition: transform 0.3s ease; position: relative; overflow: hidden;">
            <div style="position: absolute; top: -50%; right: -50%; width: 300px; height: 300px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); pointer-events: none;"></div>
            <div>
              <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div style="font-size: 1.1rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                  <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                  Lifetime
                </div>
                <span style="padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: rgba(255,255,255,0.2); color: #ffffff; border: 1px solid rgba(255,255,255,0.2);">Limited</span>
              </div>
              <div style="font-size: 3.5rem; font-weight: 700; line-height: 1; margin-bottom: 5px;">$189</div>
              <p style="font-size: 0.9rem; color: #888888; margin-bottom: 30px;">One-time payment. Use forever.</p>

              <ul style="list-style: none; margin-bottom: 40px; display: flex; flex-direction: column; gap: 20px; padding: 0;">
                <li style="display: flex; align-items: flex-start; gap: 16px;">
                  <div style="width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; padding: 10px; background: rgba(255,255,255,0.1); color: #ffffff;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                  </div>
                  <div style="flex: 1;">
                    <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 2px;">Massive Icon Collection</h4>
                    <p style="font-size: 0.8rem; opacity: 0.7; line-height: 1.4;">40K+ flat, 3D, animated icons.</p>
                  </div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 16px;">
                  <div style="width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; padding: 10px; background: rgba(255,255,255,0.1); color: #ffffff;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                  </div>
                  <div style="flex: 1;">
                    <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 2px;">Unlimited Access</h4>
                    <p style="font-size: 0.8rem; opacity: 0.7; line-height: 1.4;">Use anywhere, free updates.</p>
                  </div>
                </li>
              </ul>
            </div>
            <div>
              <a href="#" style="display: block; width: 100%; padding: 16px; border-radius: 12px; text-align: center; text-decoration: none; font-weight: 700; transition: 0.2s; background: #ffffff; color: #000000;">Buy Lifetime</a>
              <div style="text-align: center; font-size: 0.8rem; opacity: 0.5; margin-top: 15px;">Available for limited time</div>
            </div>
          </div>

        </div>
      </section>
    `
  },
  // 8. The Neon Toggle (Clean SaaS)
  {
    id: 'pricing-neon-toggle',
    label: 'The Neon Toggle',
    category: 'Pricing',
    content: `
      <section style="padding: 80px 20px; background: #F3F4F1; font-family: 'Space Grotesk', sans-serif;">
        <div style="max-width: 1100px; margin: 0 auto;">
          <div style="text-align: center; margin-bottom: 50px;">
            <h2 style="font-size: 2.5rem; margin-bottom: 20px; color: #1a1a1a; letter-spacing: -1px;">Choose Your Plan</h2>
            <div style="display: inline-flex; background: #ffffff; padding: 5px; border-radius: 50px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); position: relative;">
              <div style="position: absolute; top: 5px; left: 5px; width: 50%; height: calc(100% - 10px); background: #ccff00; border-radius: 40px; transition: 0.3s cubic-bezier(0.4, 0.0, 0.2, 1);"></div>
              <div style="padding: 10px 24px; border-radius: 40px; font-weight: 600; font-size: 0.9rem; cursor: pointer; z-index: 2; color: #000000;">Monthly</div>
              <div style="padding: 10px 24px; border-radius: 40px; font-weight: 600; font-size: 0.9rem; cursor: pointer; z-index: 2; color: #666666;">Yearly</div>
            </div>
          </div>
          
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
            <!-- Starter Card -->
            <div style="background: #ffffff; border-radius: 20px; padding: 40px 30px; text-align: left; border: 1px solid transparent; transition: 0.3s; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
              <span style="font-size: 1rem; font-weight: 600; color: #666666; margin-bottom: 15px; display: block;">Starter</span>
              <p style="font-size: 0.9rem; color: #666666; line-height: 1.5; margin-bottom: 30px; min-height: 40px;">Perfect for beginners exploring AI image generation.</p>
              <div style="font-size: 2.5rem; font-weight: 700; color: #111111; margin-bottom: 20px; letter-spacing: -1px;">$0 <small style="font-size: 1rem; font-weight: 400; color: #999999;">/month</small></div>
              <a href="#" style="display: block; width: 100%; padding: 14px; border-radius: 50px; text-align: center; text-decoration: none; font-weight: 700; font-size: 0.9rem; border: 1px solid #dddddd; color: #111111; transition: 0.2s;">You're on Free</a>
              <ul style="list-style: none; margin-top: 30px; display: flex; flex-direction: column; gap: 12px; padding: 0;">
                <li style="display: flex; gap: 10px; font-size: 0.9rem; color: #444444; align-items: center;">
                  <svg style="width: 16px; height: 16px; color: #111111;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                  50 images/mo
                </li>
                <li style="display: flex; gap: 10px; font-size: 0.9rem; color: #444444; align-items: center;">
                  <svg style="width: 16px; height: 16px; color: #111111;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                  Basic AI Models
                </li>
                <li style="display: flex; gap: 10px; font-size: 0.9rem; color: #444444; align-items: center;">
                  <svg style="width: 16px; height: 16px; color: #111111;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                  Watermarked outputs
                </li>
              </ul>
            </div>

            <!-- Professional Card -->
            <div style="background: #ffffff; border-radius: 20px; padding: 40px 30px; text-align: left; border: 2px solid #ccff00; transition: 0.3s; box-shadow: 0 20px 40px rgba(204, 255, 0, 0.1);">
              <span style="font-size: 1rem; font-weight: 600; color: #666666; margin-bottom: 15px; display: block;">Professional</span>
              <p style="font-size: 0.9rem; color: #666666; line-height: 1.5; margin-bottom: 30px; min-height: 40px;">Best for indie creators and startups who need quality.</p>
              <div style="font-size: 2.5rem; font-weight: 700; color: #111111; margin-bottom: 20px; letter-spacing: -1px;">$19.99 <small style="font-size: 1rem; font-weight: 400; color: #999999;">/month</small></div>
              <a href="#" style="display: block; width: 100%; padding: 14px; border-radius: 50px; text-align: center; text-decoration: none; font-weight: 700; font-size: 0.9rem; border: 1px solid #ccff00; background: #ccff00; color: #111111; transition: 0.2s;">Active Plan</a>
              <ul style="list-style: none; margin-top: 30px; display: flex; flex-direction: column; gap: 12px; padding: 0;">
                <li style="display: flex; gap: 10px; font-size: 0.9rem; color: #444444; align-items: center;">
                  <svg style="width: 16px; height: 16px; color: #111111;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                  Unlimited Gen
                </li>
                <li style="display: flex; gap: 10px; font-size: 0.9rem; color: #444444; align-items: center;">
                  <svg style="width: 16px; height: 16px; color: #111111;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                  Priority Queue
                </li>
                <li style="display: flex; gap: 10px; font-size: 0.9rem; color: #444444; align-items: center;">
                  <svg style="width: 16px; height: 16px; color: #111111;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                  Commercial License
                </li>
              </ul>
            </div>

            <!-- Enterprise Card -->
            <div style="background: #ffffff; border-radius: 20px; padding: 40px 30px; text-align: left; border: 1px solid transparent; transition: 0.3s; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
              <span style="font-size: 1rem; font-weight: 600; color: #666666; margin-bottom: 15px; display: block;">Enterprise</span>
              <p style="font-size: 0.9rem; color: #666666; line-height: 1.5; margin-bottom: 30px; min-height: 40px;">Suitable for studios and high volume creators.</p>
              <div style="font-size: 2.5rem; font-weight: 700; color: #111111; margin-bottom: 20px; letter-spacing: -1px;">$39.99 <small style="font-size: 1rem; font-weight: 400; color: #999999;">/month</small></div>
              <a href="#" style="display: block; width: 100%; padding: 14px; border-radius: 50px; text-align: center; text-decoration: none; font-weight: 700; font-size: 0.9rem; border: 1px solid #dddddd; color: #111111; transition: 0.2s;">Get Advanced</a>
              <ul style="list-style: none; margin-top: 30px; display: flex; flex-direction: column; gap: 12px; padding: 0;">
                <li style="display: flex; gap: 10px; font-size: 0.9rem; color: #444444; align-items: center;">
                  <svg style="width: 16px; height: 16px; color: #111111;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                  Everything Free
                </li>
                <li style="display: flex; gap: 10px; font-size: 0.9rem; color: #444444; align-items: center;">
                  <svg style="width: 16px; height: 16px; color: #111111;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                  Highest Priority
                </li>
                <li style="display: flex; gap: 10px; font-size: 0.9rem; color: #444444; align-items: center;">
                  <svg style="width: 16px; height: 16px; color: #111111;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                  API Access
                </li>
              </ul>
            </div>
          </div>
        </div>
      </section>
    `
  },
  // 9. The Power Stack (Red Highlight)
  {
    id: 'pricing-power-stack',
    label: 'The Power Stack',
    category: 'Pricing',
    content: `
      <section style="padding: 80px 20px; background: #ffffff; font-family: 'Inter', sans-serif;">
        <div style="max-width: 1100px; margin: 0 auto; display: flex; align-items: center; gap: 20px; flex-wrap: wrap; justify-content: center;">
          
          <!-- Starter Card -->
          <div style="flex: 1; min-width: 300px; border: 1px solid #e5e5e5; border-radius: 16px; padding: 30px; background: #ffffff; display: flex; flex-direction: column; justify-content: flex-start; min-height: 600px;">
            <span style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #888888; letter-spacing: 1px; border: 1px solid #eeeeee; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-bottom: 20px; width: fit-content;">Starter</span>
            <div style="font-size: 3rem; font-weight: 800; color: #111111; letter-spacing: -1.5px; margin-bottom: 20px;">$29<span style="font-size: 1rem; color: #666666; font-weight: 500;">/mo</span></div>
            <p style="font-size: 0.95rem; color: #555555; margin-bottom: 30px; line-height: 1.5;">For solo users or small teams just getting started with basic automation.</p>
            <a href="#" style="display: block; width: 100%; padding: 14px; text-align: center; border-radius: 50px; font-weight: 600; text-decoration: none; border: 1px solid #eeeeee; color: #111111; transition: 0.2s; margin-bottom: 40px;">Get Started</a>
            <ul style="list-style: none; display: flex; flex-direction: column; gap: 12px; padding: 0;">
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                1 Active Agent
              </li>
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                Connect 2 Tools
              </li>
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                500 Tasks/mo
              </li>
            </ul>
            <div style="margin-top: auto; font-weight: 700; font-size: 0.9rem; background: #f9f9f9; padding: 8px 12px; border-radius: 6px; display: inline-block; width: fit-content;">15GB Storage</div>
          </div>

          <!-- Pro (Featured) Card -->
          <div style="flex: 1.1; min-width: 320px; border: 2px solid #FF4747; border-radius: 16px; padding: 30px; background: #ffffff; display: flex; flex-direction: column; justify-content: flex-start; min-height: 640px; box-shadow: 0 30px 60px rgba(255, 71, 71, 0.1); position: relative; z-index: 10;">
            <div style="background: #FF4747; color: white; text-align: center; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 8px; border-radius: 12px 12px 0 0; margin: -32px -32px 30px -32px;">Most Popular</div>
            <span style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #888888; letter-spacing: 1px; border: 1px solid #eeeeee; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-bottom: 20px; width: fit-content;">Pro</span>
            <div style="font-size: 3rem; font-weight: 800; color: #111111; letter-spacing: -1.5px; margin-bottom: 20px;">$49<span style="font-size: 1rem; color: #666666; font-weight: 500;">/mo</span></div>
            <p style="font-size: 0.95rem; color: #555555; margin-bottom: 30px; line-height: 1.5;">Built for teams automating multiple workflows across departments.</p>
            <a href="#" style="display: block; width: 100%; padding: 14px; text-align: center; border-radius: 50px; font-weight: 600; text-decoration: none; border: 1px solid #FF4747; background: #FF4747; color: #ffffff; transition: 0.2s; margin-bottom: 40px; box-shadow: 0 10px 20px rgba(255, 71, 71, 0.2);">Get Pro</a>
            <ul style="list-style: none; display: flex; flex-direction: column; gap: 12px; padding: 0;">
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                Up to 5 Active Agents
              </li>
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                Unlimited Tools
              </li>
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                10,000 Tasks/mo
              </li>
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                Priority Support
              </li>
            </ul>
            <div style="margin-top: auto; font-weight: 700; font-size: 0.9rem; background: #f9f9f9; padding: 8px 12px; border-radius: 6px; display: inline-block; width: fit-content;">50GB Storage</div>
          </div>

          <!-- Enterprise Card -->
          <div style="flex: 1; min-width: 300px; border: 1px solid #e5e5e5; border-radius: 16px; padding: 30px; background: #ffffff; display: flex; flex-direction: column; justify-content: flex-start; min-height: 600px;">
            <span style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #888888; letter-spacing: 1px; border: 1px solid #eeeeee; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-bottom: 20px; width: fit-content;">Enterprise</span>
            <div style="font-size: 3rem; font-weight: 800; color: #111111; letter-spacing: -1.5px; margin-bottom: 20px;">Custom</div>
            <p style="font-size: 0.95rem; color: #555555; margin-bottom: 30px; line-height: 1.5;">For organizations requiring SLA support, SSO, and custom compliance.</p>
            <a href="#" style="display: block; width: 100%; padding: 14px; text-align: center; border-radius: 50px; font-weight: 600; text-decoration: none; border: 1px solid #eeeeee; color: #111111; transition: 0.2s; margin-bottom: 40px;">Contact Us</a>
            <ul style="list-style: none; display: flex; flex-direction: column; gap: 12px; padding: 0;">
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                Unlimited Agents
              </li>
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                SLA-Backed Support
              </li>
              <li style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #444444;">
                <svg style="width: 18px; height: 18px; color: #111111; background: #f0f0f0; border-radius: 50%; padding: 3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 
                API Access & SSO
              </li>
            </ul>
            <div style="margin-top: auto; font-weight: 700; font-size: 0.9rem; background: #f9f9f9; padding: 8px 12px; border-radius: 6px; display: inline-block; width: fit-content;">100+GB Storage</div>
          </div>

        </div>
      </section>
    `
  },
]
