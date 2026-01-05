// Tab Functionality Utilities for GrapesJS Editor
import { Editor } from 'grapesjs'

/**
 * The handleTabClick function that will be injected into the canvas/preview
 * This handles tab switching when users click on tab buttons
 */
const TAB_CLICK_SCRIPT = `
(function() {
  // Define handleTabClick globally
  window.handleTabClick = function(clickedTab) {
    // Prevent default link behavior
    if (window.event) {
      window.event.preventDefault();
      window.event.stopPropagation();
    }
    
    // Get the target pane ID from href
    var href = clickedTab.getAttribute('href') || '';
    var targetId = href.replace('#', '');
    if (!targetId) return false;
    
    // Find the parent tabs container
    var tabsContainer = clickedTab.closest('[data-tab-group]') || 
                        clickedTab.closest('[data-gjs-type="tabs"]') ||
                        clickedTab.closest('.tabs-wrapper') ||
                        clickedTab.closest('section');
    
    if (!tabsContainer) {
      // Fallback: search the whole document
      tabsContainer = document;
    }
    
    // Get all tab buttons in this container
    var tabNav = clickedTab.parentElement;
    if (tabNav) {
      var allTabs = tabNav.querySelectorAll('a[href^="#"], button[data-target]');
      allTabs.forEach(function(tab) {
        tab.classList.remove('active');
      });
    }
    
    // Add active class to clicked tab
    clickedTab.classList.add('active');
    
    // Find all tab panes in this container
    var allPanes = tabsContainer.querySelectorAll('.tab-content, .tab-pane, [data-gjs-type="tab-pane"]');
    allPanes.forEach(function(pane) {
      pane.classList.remove('active');
      pane.style.display = 'none';
    });
    
    // Show the target pane
    var targetPane = tabsContainer.querySelector('#' + targetId) || document.getElementById(targetId);
    if (targetPane) {
      targetPane.classList.add('active');
      targetPane.style.display = 'block';
    }
    
    return false;
  };

  // Event delegation for tabs - handles clicks on tab links
  document.addEventListener('click', function(e) {
    var target = e.target;
    
    // Check if clicked element or its parent is a tab link
    var tabLink = target.closest('a[href^="#"]');
    if (!tabLink) return;
    
    // Check if this is actually a tab (has tab-related classes or onclick handler)
    var isTab = tabLink.getAttribute('onclick') && tabLink.getAttribute('onclick').includes('handleTabClick') ||
                tabLink.classList.contains('tab-btn-classic') ||
                tabLink.classList.contains('tab-btn-pill') ||
                tabLink.classList.contains('tab-btn-vertical') ||
                tabLink.classList.contains('capsule-btn') ||
                tabLink.classList.contains('neon-btn') ||
                tabLink.classList.contains('glass-btn') ||
                tabLink.classList.contains('folder-btn') ||
                tabLink.classList.contains('clean-btn') ||
                tabLink.classList.contains('grad-btn') ||
                tabLink.classList.contains('term-btn') ||
                tabLink.classList.contains('float-btn') ||
                tabLink.classList.contains('mesh-btn') ||
                tabLink.classList.contains('f9-nav-item') ||
                tabLink.getAttribute('data-gjs-type') === 'tab' ||
                tabLink.closest('[data-tab-group]') ||
                tabLink.closest('[data-faq-tabs]') ||
                tabLink.closest('[data-gjs-type="tabs"]');
    
    if (isTab) {
      e.preventDefault();
      e.stopPropagation();
      window.handleTabClick(tabLink);
    }
  }, true);
  
  // Handle FAQ accordion toggle - GrapesJS may interfere with native details/summary behavior
  document.addEventListener('click', function(e) {
    var summary = e.target.closest('summary');
    if (!summary) return;
    
    var details = summary.parentElement;
    if (!details || details.tagName !== 'DETAILS') return;
    
    // Prevent default and manually toggle
    e.preventDefault();
    e.stopPropagation();
    
    if (details.hasAttribute('open')) {
      details.removeAttribute('open');
    } else {
      details.setAttribute('open', '');
    }
  }, true);
  
  // Handle FAQ vertical tabs navigation (f9-nav-item)
  document.addEventListener('click', function(e) {
    var navItem = e.target.closest('.f9-nav-item');
    if (!navItem) return;
    
    e.preventDefault();
    e.stopPropagation();
    
    var href = navItem.getAttribute('href');
    if (!href || href === '#') return;
    
    var targetId = href.replace('#', '');
    var section = navItem.closest('.faq-09') || navItem.closest('section');
    if (!section) return;
    
    // Remove active from all nav items
    section.querySelectorAll('.f9-nav-item').forEach(function(item) {
      item.classList.remove('active');
    });
    
    // Add active to clicked nav item
    navItem.classList.add('active');
    
    // Hide all tab content
    section.querySelectorAll('.f9-tab-content').forEach(function(content) {
      content.classList.remove('active');
    });
    
    // Show target tab content
    var targetContent = section.querySelector('#' + targetId);
    if (targetContent) {
      targetContent.classList.add('active');
    }
  }, true);
  
  // Mark as initialized
  window.__tabsInitialized = true;
})();
`;

/**
 * Initialize tab functionality in the GrapesJS canvas
 */
export function initTabFunctionality(editor: Editor) {
  const frame = editor.Canvas.getFrameEl()
  if (!frame || !frame.contentWindow || !frame.contentDocument) return

  const win = frame.contentWindow as any
  const doc = frame.contentDocument

  // Check if already initialized
  if (win.__tabsInitialized) return

  // Inject the script
  const script = doc.createElement('script')
  script.textContent = TAB_CLICK_SCRIPT
  script.id = 'tab-functionality-script'
  
  // Remove existing script if present
  const existingScript = doc.getElementById('tab-functionality-script')
  if (existingScript) {
    existingScript.remove()
  }
  
  doc.head.appendChild(script)
}

/**
 * Setup tab functionality listeners for the editor
 */
export function setupTabListeners(editor: Editor) {
  const initTabs = () => initTabFunctionality(editor)

  // Initialize on component additions (when presets are loaded)
  editor.on('component:add', () => {
    setTimeout(initTabs, 50)
    setTimeout(initTabs, 200)
  })

  // Listen for preview mode toggle
  editor.on('run:preview', () => {
    setTimeout(initTabs, 100)
    setTimeout(initTabs, 300)
  })

  editor.on('stop:preview', () => {
    setTimeout(initTabs, 100)
  })

  // Also init on canvas load
  editor.on('canvas:frame:load', () => {
    setTimeout(initTabs, 100)
    setTimeout(initTabs, 300)
    setTimeout(initTabs, 600)
  })

  // Also init when editor is ready
  editor.on('load', () => {
    setTimeout(initTabs, 200)
    setTimeout(initTabs, 500)
    injectTabScriptToWrapper(editor)
  })

  // Periodic check to ensure tab script is present (every 2 seconds)
  const tabCheckInterval = setInterval(() => {
    const frame = editor.Canvas.getFrameEl()
    if (frame && frame.contentWindow) {
      const win = frame.contentWindow as any
      if (typeof win.handleTabClick !== 'function') {
        initTabs()
      }
    }
  }, 2000)

  // Return cleanup function
  return () => {
    clearInterval(tabCheckInterval)
  }
}

/**
 * Inject tab script into the wrapper content so it persists in Preview/Export
 */
function injectTabScriptToWrapper(editor: Editor) {
  const wrapper = editor.getWrapper()
  if (!wrapper) return

  // Check if script is already present to avoid duplicates
  const hasScript = wrapper.find('script').some((c: any) => {
    const content = c.get('content') || ''
    return content.includes('handleTabClick')
  })

  if (!hasScript) {
    wrapper.append({
      tagName: 'script',
      content: TAB_CLICK_SCRIPT,
      layerable: false,
      selectable: false,
      droppable: false,
      draggable: false,
      removable: false,
    })
  }
}
