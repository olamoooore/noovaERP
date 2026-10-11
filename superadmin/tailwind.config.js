/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        // NoovaERP brand palette for the control plane
        brand: {
          50:  '#effaf6',
          100: '#d6f1e7',
          200: '#afe3d2',
          300: '#7fceb8',
          400: '#52b39b',
          500: '#2e8b74',
          600: '#25735f',
          700: '#1e5c4c',
          800: '#194a3e',
          900: '#153d33',
          950: '#0a231d',
        },
      },
    },
  },
  plugins: [],
}
