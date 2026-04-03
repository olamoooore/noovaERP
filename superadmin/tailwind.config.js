/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        // NoovaERP brand palette for the control plane
        brand: {
          50:  '#f0f4ff',
          100: '#dce7fd',
          200: '#c0d2fb',
          300: '#93b2f8',
          400: '#6089f3',
          500: '#3d63ed',
          600: '#2848e1',
          700: '#2038cc',
          800: '#2032a5',
          900: '#1f2f82',
          950: '#171e50',
        },
      },
    },
  },
  plugins: [],
}
