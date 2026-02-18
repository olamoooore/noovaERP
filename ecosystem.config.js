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
    {
      name: 'noovaerp-queue',
      cwd: './backend',
      script: 'php',
      args: 'artisan queue:work --tries=3 --timeout=60 --sleep=3',
      interpreter: 'none',
      watch: false,
      autorestart: true,
    },
  ],
};
