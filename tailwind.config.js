/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './**/*.{php,html,js}',
    '!./node_modules/**',
    '!./vendor/**',
    '!./.cursor/**',
  ],
  theme: {
    extend: {
      colors: {
        tnc: {
          orange: '#ea580c',
          'orange-dark': '#c2410c',
          'orange-deep': '#9a3412',
          soft: '#ffedd5',
          border: '#fdba74',
          surface: '#f6f7f9',
          ink: '#0f172a',
          muted: '#64748b',
        },
      },
      fontFamily: {
        sans: ['Sarabun', 'Noto Sans Thai', 'Leelawadee UI', 'system-ui', 'sans-serif'],
      },
      borderRadius: {
        tnc: '0.875rem',
      },
      boxShadow: {
        'tnc-md': '0 8px 28px rgba(15, 23, 42, 0.08)',
        'tnc-orange': '0 10px 32px rgba(234, 88, 12, 0.18)',
      },
    },
  },
  plugins: [],
};
