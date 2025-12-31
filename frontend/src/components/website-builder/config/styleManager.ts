// Style Manager Configuration

export const styleManagerConfig = {
  sectors: [
    {
      name: 'General',
      open: false,
      buildProps: ['float', 'display', 'position', 'top', 'right', 'left', 'bottom'],
    },
    {
      name: 'Flex',
      open: false,
      buildProps: ['flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content', 'order', 'flex-basis', 'flex-grow', 'flex-shrink', 'align-self'],
    },
    {
      name: 'Dimension',
      open: false,
      buildProps: ['width', 'height', 'max-width', 'min-height', 'margin', 'padding'],
    },
    {
      name: 'Typography',
      open: false,
      buildProps: ['font-family', 'font-size', 'font-weight', 'letter-spacing', 'color', 'line-height', 'text-align', 'text-decoration', 'text-shadow'],
      properties: [
        {
          name: 'Font Family',
          property: 'font-family',
          type: 'select',
          defaults: 'Inter, sans-serif',
          options: [
            { id: 'Inter, sans-serif', label: 'Inter' },
            { id: 'Arial, Helvetica, sans-serif', label: 'Arial' },
            { id: 'Georgia, serif', label: 'Georgia' },
            { id: 'Times New Roman, serif', label: 'Times New Roman' },
            { id: 'Courier New, monospace', label: 'Courier New' },
            { id: 'Verdana, sans-serif', label: 'Verdana' },
            { id: 'Trebuchet MS, sans-serif', label: 'Trebuchet MS' },
            { id: 'Roboto, sans-serif', label: 'Roboto' },
            { id: 'Open Sans, sans-serif', label: 'Open Sans' },
            { id: 'Lato, sans-serif', label: 'Lato' },
            { id: 'Montserrat, sans-serif', label: 'Montserrat' },
            { id: 'Poppins, sans-serif', label: 'Poppins' },
            { id: 'Raleway, sans-serif', label: 'Raleway' },
            { id: 'Nunito, sans-serif', label: 'Nunito' },
            { id: 'Playfair Display, serif', label: 'Playfair Display' },
            { id: 'Merriweather, serif', label: 'Merriweather' },
            { id: 'Source Sans Pro, sans-serif', label: 'Source Sans Pro' },
            { id: 'Ubuntu, sans-serif', label: 'Ubuntu' },
            { id: 'Oswald, sans-serif', label: 'Oswald' },
            { id: 'Quicksand, sans-serif', label: 'Quicksand' },
            { id: 'Work Sans, sans-serif', label: 'Work Sans' },
            { id: 'DM Sans, sans-serif', label: 'DM Sans' },
            { id: 'Space Grotesk, sans-serif', label: 'Space Grotesk' },
            { id: 'Manrope, sans-serif', label: 'Manrope' },
            { id: 'Plus Jakarta Sans, sans-serif', label: 'Plus Jakarta Sans' },
          ],
        },
      ],
    },
    {
      name: 'Decorations',
      open: false,
      buildProps: ['background-color', 'border-radius', 'border', 'box-shadow', 'background'],
    },
    {
      name: 'Extra',
      open: false,
      buildProps: ['opacity', 'transition', 'transform', 'cursor', 'overflow'],
    },
  ],
}
