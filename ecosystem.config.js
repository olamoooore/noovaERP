module.exports = {
  apps: [
    {
      name: 'noovaerp-backend',
      cwd: './backend',
      script: 'php',
      args: 'artisan serve --host=127.0.0.1 --port=8000',
      interpreter: 'none',
      watch: false,
    },
    {
      name: 'noovaerp-frontend',
      cwd: './frontend',
      script: 'npm',
      args: 'run dev',
      interpreter: 'none',
      watch: false,
    },
  ],
};
