export const heroPresets = [
  {
    id: 'hero-minimal-saas',
    label: 'Minimal SaaS',
    category: 'Hero',
    content: `
      <section class="relative bg-white pt-16 pb-32 overflow-hidden" id="hero-minimal-saas">
        <style>
          @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
          }
          @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
          }
          .animate-fade-up { animation: fadeInUp 0.8s ease-out forwards; }
          .animate-slide-right { animation: slideInRight 1s ease-out forwards; }
          .delay-1 { animation-delay: 0.2s; }
          .delay-2 { animation-delay: 0.4s; }
          .hero-btn-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(42, 157, 143, 0.4); }
        </style>
        <div class="relative">
          <div class="lg:mx-auto lg:max-w-7xl lg:px-8 lg:grid lg:grid-cols-2 lg:grid-flow-col-dense lg:gap-24">
            <div class="px-4 max-w-xl mx-auto sm:px-6 lg:py-16 lg:max-w-none lg:mx-0 lg:px-0">
              <div class="animate-fade-up">
                <span class="h-12 w-12 rounded-md flex items-center justify-center bg-teal-600 shadow-lg">
                  <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                  </svg>
                </span>
                <div class="mt-6">
                  <h2 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl animate-fade-up delay-1">
                    Transform your workflow with NoovaERP
                  </h2>
                  <p class="mt-4 text-lg text-gray-500 animate-fade-up delay-2">
                    Streamline your business operations with our all-in-one management platform. Built for modern teams who demand efficiency and clarity.
                  </p>
                  <div class="mt-6 animate-fade-up delay-2">
                    <a href="#" class="hero-btn-hover transition-all duration-300 inline-flex px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-teal-600 hover:bg-teal-700">
                      Get started
                    </a>
                  </div>
                </div>
              </div>
            </div>
            <div class="mt-12 sm:mt-16 lg:mt-0 animate-slide-right delay-1">
              <div class="pl-4 -mr-48 sm:pl-6 md:-mr-16 lg:px-0 lg:m-0 lg:relative lg:h-full">
                <img class="w-full rounded-xl shadow-2xl ring-1 ring-black ring-opacity-5 lg:absolute lg:left-0 lg:h-full lg:w-auto lg:max-w-none transform hover:scale-[1.02] transition-transform duration-500" src="https://images.unsplash.com/photo-1460925895917-afdab827c52f?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80" alt="SaaS Dashboard">
              </div>
            </div>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-split-image',
    label: 'Split Image',
    category: 'Hero',
    content: `
      <section class="relative bg-gray-50 overflow-hidden" id="hero-split-image">
        <style>
          @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-40px); }
            to { opacity: 1; transform: translateX(0); }
          }
          @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
          }
          .animate-slide-left { animation: slideInLeft 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
          .animate-fade-in { animation: fadeIn 1.2s ease-out forwards; }
          .btn-glow:hover { box-shadow: 0 0 20px rgba(42, 157, 143, 0.3); }
        </style>
        <div class="lg:relative">
          <div class="mx-auto max-w-7xl w-full pt-16 pb-20 text-center lg:py-48 lg:text-left">
            <div class="px-4 lg:w-1/2 sm:px-8 xl:pr-16 animate-slide-left">
              <h1 class="text-4xl tracking-tight font-extrabold text-gray-900 sm:text-5xl md:text-6xl lg:text-5xl xl:text-6xl">
                <span class="block xl:inline">Data to enrich your</span>
                <span class="block text-teal-600 xl:inline"> online business</span>
              </h1>
              <p class="mt-3 max-w-md mx-auto text-lg text-gray-500 sm:text-xl md:mt-5 md:max-w-3xl">
                Anim aute id magna aliqua ad ad non deserunt sunt. Qui irure qui lorem cupidatat commodo. Elit sunt amet fugiat veniam occaecat fugiat aliqua.
              </p>
              <div class="mt-10 sm:flex sm:justify-center lg:justify-start space-x-0 sm:space-x-4">
                <div class="rounded-md shadow">
                  <a href="#" class="btn-glow w-full flex items-center justify-center px-8 py-3 border border-transparent text-base font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 transition-all duration-300 transform hover:-translate-y-1 md:py-4 md:text-lg md:px-10">
                    Get started
                  </a>
                </div>
                <div class="mt-3 sm:mt-0 rounded-md shadow">
                  <a href="#" class="w-full flex items-center justify-center px-8 py-3 border border-transparent text-base font-medium rounded-md text-teal-600 bg-white hover:bg-gray-50 transition-all duration-300 transform hover:-translate-y-1 md:py-4 md:text-lg md:px-10">
                    Live demo
                  </a>
                </div>
              </div>
            </div>
          </div>
          <div class="relative w-full h-64 sm:h-72 md:h-96 lg:absolute lg:inset-y-0 lg:right-0 lg:w-1/2 lg:h-full overflow-hidden">
            <img class="animate-fade-in absolute inset-0 w-full h-full object-cover transform scale-110 hover:scale-100 transition-transform duration-[2000ms]" src="https://images.unsplash.com/photo-1551434678-e076c223a692?ixlib=rb-1.2.1&auto=format&fit=crop&w=2850&q=80" alt="Team working">
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-dark-modern',
    label: 'Dark Modern',
    category: 'Hero',
    content: `
      <section class="relative bg-gray-900 pt-32 pb-40 overflow-hidden" id="hero-dark-modern">
        <style>
          @keyframes zoomOut {
            from { transform: scale(1.2); opacity: 0; }
            to { transform: scale(1); opacity: 0.2; }
          }
          @keyframes revealUp {
            from { opacity: 0; transform: translateY(40px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
          }
          .animate-zoom-out { animation: zoomOut 2s ease-out forwards; }
          .animate-reveal { animation: revealUp 1s cubic-bezier(0.23, 1, 0.32, 1) forwards; }
          .dark-btn:hover { background: #3ec4b5; transform: scale(1.05); }
        </style>
        <div class="absolute inset-0 overflow-hidden">
          <img src="https://images.unsplash.com/photo-1557804506-669a67965ba0?ixlib=rb-1.2.1&auto=format&fit=crop&w=1567&q=80" class="animate-zoom-out w-full h-full object-cover" alt="Background">
          <div class="absolute inset-0 bg-gradient-to-b from-gray-900/50 via-gray-900/80 to-gray-900"></div>
        </div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center animate-reveal">
          <h1 class="text-4xl font-extrabold tracking-tight text-white sm:text-5xl lg:text-7xl">
            Building the future of <br/> <span class="text-transparent bg-clip-text bg-gradient-to-r from-teal-400 to-cyan-400">Digital Commerce</span>
          </h1>
          <p class="mt-8 text-xl text-gray-300 max-w-3xl mx-auto leading-relaxed">
            Experience the next generation of enterprise resource planning. Faster, smarter, and more intuitive than ever before.
          </p>
          <div class="mt-12 flex flex-col sm:flex-row justify-center gap-4 sm:gap-6">
            <a href="#" class="dark-btn rounded-full bg-teal-500 px-10 py-4 text-base font-semibold text-white shadow-xl transition-all duration-300">Start Building</a>
            <a href="#" class="rounded-full px-10 py-4 text-base font-semibold text-white ring-1 ring-inset ring-white/20 hover:bg-white/10 transition-all duration-300">Learn More</a>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-creative-agency',
    label: 'Creative Agency',
    category: 'Hero',
    content: `
      <section class="bg-white py-24 sm:py-32 overflow-hidden" id="hero-creative-agency">
        <style>
          @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
            100% { transform: translateY(0px); }
          }
          @keyframes staggerFade {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
          }
          .animate-float { animation: float 4s ease-in-out infinite; }
          .stagger-1 { animation: staggerFade 0.6s ease-out forwards; }
          .stagger-2 { animation: staggerFade 0.6s 0.2s ease-out forwards; opacity: 0; }
          .stagger-3 { animation: staggerFade 0.6s 0.4s ease-out forwards; opacity: 0; }
          .agency-img:hover { filter: grayscale(0%); transform: scale(1.03); }
        </style>
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
          <div class="grid grid-cols-1 items-center gap-x-8 gap-y-16 lg:grid-cols-2">
            <div>
              <nav class="flex mb-8 stagger-1" aria-label="Breadcrumb">
                <span class="inline-flex items-center rounded-full bg-teal-50 px-3 py-1 text-sm font-medium text-teal-700 ring-1 ring-inset ring-teal-600/20 animate-pulse">What's New</span>
                <span class="ml-4 text-sm font-medium text-gray-500">Just shipped version 2.0</span>
              </nav>
              <h1 class="text-4xl font-bold tracking-tight text-gray-900 sm:text-6xl stagger-2">
                Design that drives <br/> <span class="text-teal-600 underline decoration-teal-200 underline-offset-8">results</span>.
              </h1>
              <p class="mt-6 text-lg leading-8 text-gray-600 stagger-3">
                We combine strategy, design, and technology to help your business scale. Our data-driven approach ensures every pixel serves a purpose.
              </p>
              <div class="mt-10 flex items-center gap-x-6 stagger-3">
                <button class="bg-gray-900 text-white px-8 py-4 rounded-lg font-semibold hover:bg-teal-600 transition-colors duration-300 shadow-lg">View Projects</button>
                <button class="text-gray-900 font-semibold flex items-center group">
                  Our Process 
                  <svg class="ml-2 w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path d="M9 5l7 7-7 7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </button>
              </div>
            </div>
            <div class="relative stagger-2">
              <img src="https://images.unsplash.com/photo-1497215728101-856f4ea42174?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80" alt="Office space" class="rounded-2xl shadow-2xl agency-img grayscale transition-all duration-700">
              <div class="absolute -bottom-6 -left-6 bg-white p-6 rounded-xl shadow-2xl border border-gray-100 max-w-[220px] animate-float">
                <p class="text-4xl font-bold text-teal-600">99%</p>
                <p class="text-sm text-gray-500 mt-1">Client satisfaction rate across 500+ projects</p>
              </div>
            </div>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-product-focused',
    label: 'Product Showcase',
    category: 'Hero',
    content: `
      <section class="relative bg-teal-900 py-32 overflow-hidden" id="hero-product-focused">
        <style>
          @keyframes perspective {
            from { transform: perspective(1000px) rotateX(10deg) translateY(50px); opacity: 0; }
            to { transform: perspective(1000px) rotateX(0) translateY(0); opacity: 1; }
          }
          .animate-perspective { animation: perspective 1.2s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
          .pulse-teal { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4); animation: pulse-white 2s infinite; }
          @keyframes pulse-white {
            0% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4); }
            70% { box-shadow: 0 0 0 15px rgba(255, 255, 255, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0); }
          }
        </style>
        <div class="mx-auto max-w-7xl px-6 lg:px-8 text-center">
          <div class="animate-fade-up">
            <h1 class="text-4xl font-bold tracking-tight text-white sm:text-7xl">The tool you've been <br/> waiting for.</h1>
            <p class="mt-8 text-lg leading-8 text-teal-100 max-w-2xl mx-auto opacity-80">
              Master your inventory, sales, and customers in one place. No more switching between tabs or messy spreadsheets.
            </p>
            <div class="mt-12 flex items-center justify-center gap-x-6">
              <a href="#" class="pulse-teal rounded-md bg-white px-8 py-4 text-base font-semibold text-teal-900 shadow-xl hover:bg-teal-50 transition-colors">Start Free Trial</a>
              <a href="#" class="text-base font-semibold leading-6 text-white group">
                Book a Demo <span class="inline-block transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
              </a>
            </div>
          </div>
          <div class="mt-20 flow-root animate-perspective">
            <div class="-m-2 rounded-xl bg-white/10 p-3 ring-1 ring-inset ring-white/20 lg:-m-4 lg:rounded-2xl lg:p-4 backdrop-blur-sm">
              <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-1.2.1&auto=format&fit=crop&w=2850&q=80" alt="App screenshot" class="rounded-lg shadow-2xl ring-1 ring-gray-900/10">
            </div>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-app-landing',
    label: 'App Landing',
    category: 'Hero',
    content: `
      <section class="bg-white pt-10 pb-20 lg:pt-[120px] lg:pb-[110px] overflow-hidden" id="hero-app-landing">
        <style>
          .floating-phone { animation: float-phone 6s ease-in-out infinite; }
          @keyframes float-phone {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(2deg); }
          }
          .app-btn:hover { background: #1a1a1a; color: white; transform: scale(1.05); }
        </style>
        <div class="container mx-auto max-w-7xl px-4">
          <div class="flex flex-wrap -mx-4 items-center">
            <div class="w-full px-4 lg:w-5/12 animate-fade-up">
              <div class="hero-content">
                <h1 class="mb-6 text-5xl font-bold leading-tight text-gray-900 sm:text-[52px]">
                  Manage Your Business <br /> From Anywhere
                </h1>
                <p class="mb-10 max-w-[480px] text-lg text-gray-500 leading-relaxed">
                  Our mobile app puts the power of NoovaERP in your pocket. Track sales, respond to customers, and monitor your warehouse on the go.
                </p>
                <div class="flex flex-wrap items-center gap-4">
                  <a href="#" class="app-btn inline-flex items-center justify-center px-8 py-4 text-base font-medium text-center text-white rounded-xl bg-teal-600 shadow-lg transition-all duration-300">
                    Download Now
                  </a>
                  <a href="#" class="inline-flex items-center justify-center px-8 py-4 text-base font-medium text-center text-gray-900 border-2 border-gray-100 rounded-xl hover:border-teal-600 transition-all duration-300">
                    View Features
                  </a>
                </div>
                <div class="mt-12 flex items-center space-x-4 grayscale opacity-50">
                   <p class="text-sm font-semibold uppercase tracking-widest text-gray-400">Available on</p>
                   <div class="flex space-x-4">
                      <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M17.05 20.28c-.96.95-2.06 1.91-3.41 1.91s-1.7-.82-3.27-.82c-1.58 0-2.01.81-3.26.82-1.25.01-2.45-1.1-3.41-1.92-2.05-2.02-3.11-5.18-3.11-8.16 0-2.98 1.15-5.6 3.01-7.14 1.1-.92 2.31-1.35 3.51-1.35 1.19 0 2.21.37 3.01.93.81.56 1.41 1.25 1.8 1.76.1.13.18.25.25.34l.05.07.02.03.01.01.01.01s.01.01 0 0c.26-.35.82-1.01 1.55-1.57.8-.62 1.85-1.01 3.1-1.01 1.25 0 2.45.45 3.51 1.35 1.45 1.18 2.41 2.91 2.81 4.51-.15.05-.31.11-.46.18-1.05.47-1.81 1.4-1.81 2.51 0 1.11.76 2.04 1.81 2.51.15.07.31.13.46.18-.35 1.53-1.15 3.32-2.61 5.3zm-3.41-17.16c0-1.21.45-2.31 1.21-3.11.81-.85 1.95-1.41 3.11-1.41 0 1.21-.45 2.31-1.21 3.11-.81.85-1.95 1.41-3.11 1.41z"/></svg>
                      <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M3.609 1.814L13.792 12 3.61 22.186a2.317 2.317 0 0 1-.58-1.571V3.385c0-.608.214-1.152.579-1.571zm11.332 11.332l2.614-2.614-11.751-6.732 9.137 9.346zm3.322-3.322l3.322 1.906c.414.237.643.626.643 1.07s-.229.833-.643 1.07l-3.322 1.906-2.906-2.976 2.906-2.976zm-11.751 13.906l11.751-6.732-9.137 9.346c-.365.419-.909.633-1.517.633-.608 0-1.152-.214-1.571-.579l.474-.474z"/></svg>
                   </div>
                </div>
              </div>
            </div>
            <div class="hidden px-4 lg:block lg:w-1/12"></div>
            <div class="w-full px-4 lg:w-6/12 stagger-2">
              <div class="lg:text-right lg:ml-auto">
                <div class="relative z-10 inline-block pt-11 lg:pt-0 floating-phone">
                  <img src="https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80" alt="Mobile App" class="max-w-full lg:ml-auto rounded-[3rem] shadow-2xl border-[12px] border-gray-900" />
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-corporate-video',
    label: 'Corporate Video',
    category: 'Hero',
    content: `
      <section class="relative h-[85vh] flex items-center justify-center overflow-hidden bg-black" id="hero-corporate-video">
        <style>
          @keyframes ripple {
            0% { transform: scale(1); opacity: 0.5; }
            100% { transform: scale(1.5); opacity: 0; }
          }
          .play-ripple { position: absolute; border: 2px solid white; border-radius: 50%; inset: -10px; animation: ripple 2s linear infinite; }
          .video-bg-zoom { animation: slow-zoom 20s linear infinite; }
          @keyframes slow-zoom {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
          }
        </style>
        <div class="absolute inset-0 z-0">
          <img src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80" class="w-full h-full object-cover video-bg-zoom opacity-60" alt="Video Background">
          <div class="absolute inset-0 bg-gradient-to-t from-black via-transparent to-black/40"></div>
        </div>
        <div class="relative z-10 text-center px-4 animate-reveal">
          <div class="relative inline-block mb-10 group cursor-pointer">
            <div class="play-ripple"></div>
            <div class="play-ripple" style="animation-delay: 1s"></div>
            <button class="relative w-24 h-24 bg-white rounded-full flex items-center justify-center text-teal-600 transition-transform duration-500 group-hover:scale-110 shadow-2xl">
              <svg class="w-10 h-10 fill-current ml-1" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            </button>
          </div>
          <h1 class="text-5xl md:text-8xl font-black text-white mb-8 tracking-tighter">REDEFINING <br/> EXCELLENCE</h1>
          <p class="text-xl md:text-2xl text-gray-200 mb-12 max-w-3xl mx-auto font-light leading-relaxed">See how we're helping 5,000+ companies transform their digital landscape with cutting-edge technology.</p>
          <div class="flex flex-col sm:flex-row justify-center gap-6">
            <button class="bg-white text-gray-900 px-10 py-5 rounded-full font-bold hover:bg-teal-500 hover:text-white transition-all duration-300 shadow-xl transform hover:-translate-y-1">Get Started</button>
            <button class="bg-white/10 backdrop-blur-md text-white border border-white/30 px-10 py-5 rounded-full font-bold hover:bg-white/20 transition-all duration-300">Our Services</button>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-center-minimal',
    label: 'Centered Minimal',
    category: 'Hero',
    content: `
      <section class="bg-white py-32 sm:py-48 overflow-hidden" id="hero-center-minimal">
        <style>
          .text-reveal { background: linear-gradient(to right, #2a9d8f, #264653); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
          .scroll-indicator { animation: bounce 2s infinite; }
          @keyframes bounce { 0%, 20%, 50%, 80%, 100% {transform: translateY(0);} 40% {transform: translateY(-10px);} 60% {transform: translateY(-5px);} }
        </style>
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
          <div class="mx-auto max-w-4xl text-center animate-fade-up">
            <h2 class="text-base font-bold leading-7 text-teal-600 uppercase tracking-[0.3em] mb-6">Efficiency First</h2>
            <p class="text-5xl font-extrabold tracking-tight text-gray-900 sm:text-7xl mb-10">Simplify your <span class="text-reveal">complex business</span></p>
            <p class="text-xl leading-9 text-gray-600 max-w-2xl mx-auto mb-12 font-light">
              The only platform that connects your entire business. From supply chain to customer support, we've got you covered with intelligent automation.
            </p>
            <div class="mt-10 flex items-center justify-center gap-x-8">
              <a href="#" class="rounded-xl bg-gray-900 px-10 py-5 text-lg font-semibold text-white shadow-2xl hover:bg-teal-600 transition-all duration-300 transform hover:-translate-y-1">Get Started Today</a>
              <a href="#" class="text-lg font-semibold leading-6 text-gray-900 group">
                Read the Docs <span class="inline-block transition-transform group-hover:translate-x-2" aria-hidden="true">→</span>
              </a>
            </div>
            <div class="mt-24 scroll-indicator text-gray-300">
               <svg class="w-8 h-8 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M19 14l-7 7-7-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-ecommerce-fashion',
    label: 'Fashion E-commerce',
    category: 'Hero',
    content: `
      <section class="relative bg-white overflow-hidden" id="hero-ecommerce-fashion">
        <style>
          .fashion-reveal { transform: scaleX(0); transform-origin: left; transition: transform 1s cubic-bezier(0.77, 0, 0.175, 1); }
          .fashion-ready .fashion-reveal { transform: scaleX(1); }
          .img-pan { animation: pan 10s ease-in-out infinite alternate; }
          @keyframes pan { from { transform: scale(1) translateX(0); } to { transform: scale(1.1) translateX(-20px); } }
        </style>
        <div class="flex flex-col lg:flex-row min-h-[90vh]">
          <div class="w-full lg:w-1/2 p-12 lg:p-24 flex flex-col justify-center animate-slide-left">
            <h3 class="text-teal-600 font-bold mb-6 tracking-[0.4em] uppercase text-sm">New Collection 2024</h3>
            <h1 class="text-6xl lg:text-8xl font-serif text-gray-900 mb-10 leading-[0.9] italic">Elevate Your <br/> Everyday <br/> Style</h1>
            <p class="text-gray-500 mb-12 max-w-md text-lg font-light leading-relaxed">Discover curated pieces that blend comfort with high-fashion aesthetics. Hand-crafted for the modern individual.</p>
            <div>
              <button class="group relative bg-gray-900 text-white px-12 py-5 rounded-none overflow-hidden transition-all duration-300 hover:bg-teal-600 shadow-2xl">
                <span class="relative z-10">Shop Collection</span>
                <div class="absolute inset-0 h-full w-0 bg-teal-500 transition-all duration-300 group-hover:w-full"></div>
              </button>
            </div>
          </div>
          <div class="w-full lg:w-1/2 relative min-h-[500px] overflow-hidden">
            <img src="https://images.unsplash.com/photo-1490481651871-ab68de25d43d?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80" class="absolute inset-0 w-full h-full object-cover img-pan" alt="Fashion Model">
            <div class="absolute inset-0 bg-teal-900/10 mix-blend-multiply"></div>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-luxury-ecommerce',
    label: 'Luxury Showcase',
    category: 'Hero',
    content: `
      <section class="relative h-[80vh] flex items-center bg-stone-950 overflow-hidden" id="hero-luxury-ecommerce">
        <style>
          @keyframes slowReveal {
            0% { letter-spacing: 0.5em; opacity: 0; filter: blur(10px); }
            100% { letter-spacing: 0.1em; opacity: 1; filter: blur(0); }
          }
          .animate-slow-reveal { animation: slowReveal 2s cubic-bezier(0.2, 0, 0.2, 1) forwards; }
          .luxury-btn { position: relative; overflow: hidden; transition: all 0.3s ease; }
          .luxury-btn::after { content: ''; position: absolute; bottom: 0; left: 0; height: 1px; width: 0; background: #d4af37; transition: width 0.6s ease; }
          .luxury-btn:hover::after { width: 100%; }
          .luxury-img { transition: transform 6s ease-out; }
          #hero-luxury-ecommerce:hover .luxury-img { transform: scale(1.1); }
        </style>
        <div class="absolute inset-0">
          <img src="https://images.unsplash.com/photo-1523275335684-37898b6baf30?ixlib=rb-1.2.1&auto=format&fit=crop&w=1920&q=80" class="w-full h-full object-cover opacity-50 luxury-img" alt="Luxury Product">
          <div class="absolute inset-0 bg-gradient-to-r from-stone-950 via-stone-950/40 to-transparent"></div>
        </div>
        <div class="relative max-w-7xl mx-auto px-6 lg:px-8 w-full">
          <div class="max-w-2xl">
            <span class="text-stone-400 uppercase tracking-[0.3em] text-sm mb-4 block animate-fade-up">Timeless Precision</span>
            <h1 class="text-5xl md:text-7xl font-light text-white mb-8 animate-slow-reveal">THE ART OF <br/><span class="font-serif italic text-stone-300">Excellence</span></h1>
            <p class="text-stone-400 text-lg mb-12 max-w-md leading-relaxed animate-fade-up delay-1">Discover our exclusive collection of hand-crafted timepieces, where heritage meets modern innovation.</p>
            <div class="flex items-center gap-12 animate-fade-up delay-2">
              <a href="#" class="luxury-btn py-4 text-white uppercase tracking-widest text-sm font-medium">Explore Collection</a>
              <a href="#" class="luxury-btn py-4 text-white uppercase tracking-widest text-sm font-medium">Book Appointment</a>
            </div>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-streetwear-ecommerce',
    label: 'Streetwear Drop',
    category: 'Hero',
    content: `
      <section class="relative bg-yellow-400 py-20 min-h-[80vh] flex items-center overflow-hidden" id="hero-streetwear-ecommerce">
        <style>
          @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
          }
          @keyframes rotateBadge {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
          }
          .animate-marquee { animation: marquee 20s linear infinite; }
          .rotate-badge { animation: rotateBadge 10s linear infinite; }
          .street-btn:hover { background: black; color: #facc15; transform: skewX(-6deg) scale(1.05); }
          .street-img-container::before { content: ''; position: absolute; inset: 0; border: 4px solid black; transform: translate(15px, 15px); z-index: -1; }
        </style>
        <div class="absolute top-0 left-0 w-full bg-black py-3 overflow-hidden z-20">
          <div class="flex whitespace-nowrap animate-marquee">
            <span class="text-yellow-400 text-xl font-black px-4 uppercase italic">New Season Drop • Limited Stock • Free Worldwide Shipping • New Season Drop • Limited Stock • Free Worldwide Shipping • </span>
            <span class="text-yellow-400 text-xl font-black px-4 uppercase italic">New Season Drop • Limited Stock • Free Worldwide Shipping • New Season Drop • Limited Stock • Free Worldwide Shipping • </span>
          </div>
        </div>
        <div class="container mx-auto px-6 pt-12 grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
          <div class="relative z-10">
            <h2 class="text-6xl md:text-8xl font-black text-black leading-none mb-8 tracking-tighter uppercase italic animate-fade-up">URBAN <br/>REBEL</h2>
            <p class="text-xl font-bold text-black mb-10 max-w-lg animate-fade-up delay-1">Stop following. Start leading. The AW24 collection is here to disrupt the streets.</p>
            <div class="flex flex-wrap gap-6 animate-fade-up delay-2">
              <a href="#" class="street-btn bg-black text-yellow-400 px-8 py-4 text-lg font-black uppercase transition-all duration-300 transform shadow-[8px_8px_0px_0px_rgba(0,0,0,0.2)]">Shop The Drop</a>
              <a href="#" class="border-4 border-black text-black px-8 py-4 text-lg font-black uppercase hover:bg-black hover:text-yellow-400 transition-all duration-300 shadow-[8px_8px_0px_0px_rgba(0,0,0,0.1)]">Lookbook</a>
            </div>
          </div>
          <div class="relative">
            <div class="relative street-img-container">
              <img src="https://images.unsplash.com/photo-1552374196-1ab2a1c593e8?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80" class="w-full h-[500px] object-cover border-4 border-black grayscale hover:grayscale-0 transition-all duration-700" alt="Streetwear model">
            </div>
            <div class="absolute -top-6 -right-6 w-32 h-32 bg-white border-4 border-black rounded-full flex items-center justify-center text-center p-2 rotate-badge z-20 shadow-xl">
              <span class="text-black font-black text-xs uppercase">20% OFF <br/> FIRST ORDER</span>
            </div>
          </div>
        </div>
      </section>
    `
  },
  {
    id: 'hero-organic-ecommerce',
    label: 'Organic Decor',
    category: 'Hero',
    content: `
      <section class="relative bg-[#f8f5f0] py-24 overflow-hidden" id="hero-organic-ecommerce">
        <style>
          @keyframes floatSoft {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
          }
          .animate-float-soft { animation: floatSoft 6s ease-in-out infinite; }
          .organic-shape { border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%; transition: all 1.5s ease-in-out; }
          #hero-organic-ecommerce:hover .organic-shape { border-radius: 30% 60% 70% 40% / 50% 60% 30% 60%; }
        </style>
        <div class="container mx-auto px-6">
          <div class="flex flex-col lg:flex-row items-center gap-16">
            <div class="w-full lg:w-1/2 order-2 lg:order-1">
              <div class="relative">
                <div class="absolute -top-12 -left-12 w-64 h-64 bg-[#e9e3d8] rounded-full mix-blend-multiply filter blur-3xl opacity-60 animate-pulse"></div>
                <div class="organic-shape w-full h-[500px] overflow-hidden bg-[#e9e3d8] relative z-10 shadow-2xl animate-float-soft">
                  <img src="https://images.unsplash.com/photo-1583847268964-b28dc2f51ac9?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80" class="w-full h-full object-cover transform hover:scale-110 transition-transform duration-[3s]" alt="Sustainable Decor">
                </div>
              </div>
            </div>
            <div class="w-full lg:w-1/2 order-1 lg:order-2 text-center lg:text-left">
              <span class="text-[#8c7e6a] font-medium tracking-widest uppercase mb-4 block animate-fade-up">Sustainable Living</span>
              <h1 class="text-5xl md:text-6xl font-serif text-[#4a4238] mb-8 leading-tight animate-fade-up delay-1">Harmonize Your <br/>Space with Nature</h1>
              <p class="text-[#8c7e6a] text-lg mb-10 max-w-lg leading-relaxed animate-fade-up delay-2">Ethically sourced, beautifully crafted. Bring the serenity of the outdoors into your home with our eco-conscious collection.</p>
              <div class="flex flex-col sm:flex-row items-center gap-8 justify-center lg:justify-start animate-fade-up delay-2">
                <a href="#" class="bg-[#4a4238] text-white px-10 py-4 rounded-full font-medium hover:bg-[#63594b] transition-all duration-300 shadow-lg hover:shadow-2xl transform hover:-translate-y-1">Shop Collection</a>
                <a href="#" class="text-[#4a4238] font-medium flex items-center group">
                  Our Materials
                  <svg class="ml-2 w-5 h-5 group-hover:translate-x-2 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path d="M17 8l4 4m0 0l-4 4m4-4H3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </a>
              </div>
            </div>
          </div>
        </div>
      </section>
    `
  }
];
