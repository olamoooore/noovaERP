// GrapesJS Plugins Configuration
import gjsPresetWebpage from 'grapesjs-preset-webpage'
import gjsBlocksBasic from 'grapesjs-blocks-basic'
import gjsPluginForms from 'grapesjs-plugin-forms'
import gjsComponentCountdown from 'grapesjs-component-countdown'
import gjsTabs from 'grapesjs-tabs'
import gjsCustomCode from 'grapesjs-custom-code'
import gjsTouch from 'grapesjs-touch'
import gjsParserPostcss from 'grapesjs-parser-postcss'
import gjsTooltip from 'grapesjs-tooltip'
import gjsTyped from 'grapesjs-typed'
import gjsStyleBg from 'grapesjs-style-bg'
import gjsPluginExport from 'grapesjs-plugin-export'
import gjsNavbar from 'grapesjs-navbar'
import gjsStyleGradient from 'grapesjs-style-gradient'
import gjsStyleFilter from 'grapesjs-style-filter'
import gjsBlocksFlexbox from 'grapesjs-blocks-flexbox'
// @ts-expect-error - no types available
import gjsUiSuggestClasses from 'grapesjs-ui-suggest-classes'
import gjsClick from 'grapesjs-click'
import gjsFloat from 'grapesjs-float'
import { Editor } from 'grapesjs'

// All plugins including grapesjs-tabs
export const plugins = [
  gjsPresetWebpage,
  gjsBlocksBasic,
  gjsPluginForms,
  gjsComponentCountdown,
  gjsTabs,
  gjsCustomCode,
  gjsTouch,
  gjsParserPostcss,
  gjsTooltip,
  gjsTyped,
  gjsStyleBg,
  gjsPluginExport,
  gjsNavbar,
  gjsStyleGradient,
  gjsStyleFilter,
  gjsBlocksFlexbox,
  gjsUiSuggestClasses,
  gjsClick,
  gjsFloat,
]

// Plugins without grapesjs-tabs - use this to avoid conflicts with custom tab presets
export const getPluginsWithoutTabs = () => [
  gjsPresetWebpage,
  gjsBlocksBasic,
  gjsPluginForms,
  gjsComponentCountdown,
  // gjsTabs removed to prevent conflicts with custom tab presets
  gjsCustomCode,
  gjsTouch,
  gjsParserPostcss,
  gjsTooltip,
  gjsTyped,
  gjsStyleBg,
  gjsPluginExport,
  gjsNavbar,
  gjsStyleGradient,
  gjsStyleFilter,
  gjsBlocksFlexbox,
  gjsUiSuggestClasses,
  gjsClick,
  gjsFloat,
]

export const getPluginsOpts = (_editor: Editor) => ({
  [gjsPresetWebpage as any]: {
    modalImportTitle: 'Import Template',
    modalImportLabel: '<div style="margin-bottom: 10px;">Paste your HTML/CSS here</div>',
    modalImportContent: (ed: Editor) => {
      return ed.getHtml() + '<style>' + ed.getCss() + '</style>'
    },
  },
  [gjsBlocksBasic as any]: {
    flexGrid: true,
  },
  [gjsPluginForms as any]: {
    blocks: ['form', 'input', 'textarea', 'select', 'button', 'label', 'checkbox', 'radio'],
  },
  [gjsTabs as any]: {
    tabsBlock: { category: 'Extra' },
  },
  [gjsNavbar as any]: {
    block: { category: 'Extra' },
  },
  [gjsBlocksFlexbox as any]: {
    flexboxBlock: { category: 'Layout' },
  },
  [gjsPluginExport as any]: {
    addExportBtn: true,
  },
  [gjsStyleGradient as any]: {
    colorPicker: 'default',
  },
})
