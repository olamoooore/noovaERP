/**
 * Semantic HTML Utilities
 * Helper functions for generating proper ARIA attributes and semantic HTML
 */

export interface AriaAttributeConfig {
  label?: string;
  describedBy?: string;
  controls?: string;
  expanded?: boolean;
  pressed?: boolean | 'mixed';
  disabled?: boolean;
  current?: 'page' | 'step' | 'location' | 'date' | 'time' | 'true' | 'false';
  haspopup?: boolean | 'menu' | 'listbox' | 'tree' | 'grid' | 'dialog';
  live?: 'polite' | 'assertive' | 'off';
  atomic?: boolean;
  busy?: boolean;
  relevant?: 'additions' | 'removals' | 'text' | 'all';
  role?: string;
}

export type ElementType = 'button' | 'link' | 'input' | 'dialog' | 'menu' | 'tab' | 'listitem' | 'region';

/**
 * Generate ARIA attributes based on element type and configuration
 */
export function generateAriaAttributes(
  elementType: ElementType,
  config: AriaAttributeConfig = {}
): Record<string, string | boolean | undefined> {
  const attributes: Record<string, string | boolean | undefined> = {};

  // Common attributes
  if (config.label) {
    attributes['aria-label'] = config.label;
  }
  if (config.describedBy) {
    attributes['aria-describedby'] = config.describedBy;
  }
  if (config.disabled !== undefined) {
    attributes['aria-disabled'] = config.disabled;
  }

  // Element-specific attributes
  switch (elementType) {
    case 'button':
      if (config.controls) {
        attributes['aria-controls'] = config.controls;
      }
      if (config.expanded !== undefined) {
        attributes['aria-expanded'] = config.expanded;
      }
      if (config.pressed !== undefined) {
        attributes['aria-pressed'] = config.pressed;
      }
      if (config.haspopup !== undefined) {
        attributes['aria-haspopup'] = config.haspopup;
      }
      break;

    case 'link':
      if (config.current) {
        attributes['aria-current'] = config.current;
      }
      break;

    case 'input':
      if (config.describedBy) {
        attributes['aria-describedby'] = config.describedBy;
      }
      break;

    case 'dialog':
      attributes['role'] = 'dialog';
      attributes['aria-modal'] = true;
      if (config.label) {
        attributes['aria-labelledby'] = config.label;
      }
      break;

    case 'menu':
      attributes['role'] = 'menu';
      if (config.expanded !== undefined) {
        attributes['aria-expanded'] = config.expanded;
      }
      break;

    case 'tab':
      attributes['role'] = 'tab';
      if (config.controls) {
        attributes['aria-controls'] = config.controls;
      }
      break;

    case 'listitem':
      attributes['role'] = 'listitem';
      break;

    case 'region':
      if (config.live) {
        attributes['aria-live'] = config.live;
      }
      if (config.atomic !== undefined) {
        attributes['aria-atomic'] = config.atomic;
      }
      if (config.busy !== undefined) {
        attributes['aria-busy'] = config.busy;
      }
      if (config.relevant) {
        attributes['aria-relevant'] = config.relevant;
      }
      break;
  }

  // Custom role override
  if (config.role) {
    attributes['role'] = config.role;
  }

  return attributes;
}

/**
 * Generate semantic landmark structure
 */
export function getSemanticLandmark(
  type: 'main' | 'header' | 'footer' | 'nav' | 'aside' | 'section' | 'article' | 'form'
): { tag: string; role: string } {
  const landmarks: Record<string, { tag: string; role: string }> = {
    main: { tag: 'main', role: 'main' },
    header: { tag: 'header', role: 'banner' },
    footer: { tag: 'footer', role: 'contentinfo' },
    nav: { tag: 'nav', role: 'navigation' },
    aside: { tag: 'aside', role: 'complementary' },
    section: { tag: 'section', role: 'region' },
    article: { tag: 'article', role: 'article' },
    form: { tag: 'form', role: 'form' },
  };

  return landmarks[type];
}

/**
 * Generate heading hierarchy attributes
 */
export function getHeadingLevel(level: 1 | 2 | 3 | 4 | 5 | 6): {
  tag: string;
  ariaLevel: number;
} {
  return {
    tag: `h${level}`,
    ariaLevel: level,
  };
}

/**
 * Validate heading hierarchy
 */
export function validateHeadingHierarchy(levels: number[]): {
  isValid: boolean;
  issues: string[];
} {
  const issues: string[] = [];
  
  // Check if starts with h1
  if (levels[0] !== 1) {
    issues.push('Page should start with an h1 heading');
  }
  
  // Check for skipped levels
  for (let i = 1; i < levels.length; i++) {
    if (levels[i] > levels[i - 1] + 1) {
      issues.push(`Heading level skipped from h${levels[i - 1]} to h${levels[i]}`);
    }
  }
  
  return {
    isValid: issues.length === 0,
    issues,
  };
}

/**
 * Generate accessible name for elements
 */
export function getAccessibleName(options: {
  label?: string;
  labelledBy?: string;
  innerText?: string;
  title?: string;
  placeholder?: string;
}): string | undefined {
  // Priority order for accessible name computation
  return (
    options.label ||
    options.labelledBy ||
    options.innerText ||
    options.title ||
    options.placeholder
  );
}

/**
 * Generate description list structure
 */
export function getDescriptionListStructure(): {
  list: string;
  term: string;
  description: string;
} {
  return {
    list: 'dl',
    term: 'dt',
    description: 'dd',
  };
}
