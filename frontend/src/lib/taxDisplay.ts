export interface StructuredAdvice {
  summary?: string;
  risks?: string[];
  actions?: string[];
}

export const SOURCE_LABELS: Record<string, string> = {
  product_custom: 'Product override',
  product: 'Product tax',
  tenant_default: 'Default rule',
  localized_rule: 'Configured rule',
  none: 'No rule found',
};
