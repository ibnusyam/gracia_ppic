// PM2 config — Reporting MRP Gracia
// Backend hanya bind ke localhost; akses publik lewat nginx port 5000
// (lihat /etc/nginx/sites-available/gracia-mrp).
//
//   pm2 start ecosystem.config.cjs && pm2 save

module.exports = {
  apps: [
    {
      name: 'mrp-app',
      cwd: '/home/ibnu/Projects/gracia_mrp',
      script: 'php',
      args: 'artisan serve --host=127.0.0.1 --port=8000',
      interpreter: 'none',
      exec_mode: 'fork',
      instances: 1,
      autorestart: true,
      max_restarts: 10,
      max_memory_restart: '512M',
      env: {
        // artisan serve default-nya single-threaded; ini bikin request paralel
        // (Livewire polling, download export) tidak saling blokir.
        PHP_CLI_SERVER_WORKERS: '8',
      },
      out_file: '/home/ibnu/.pm2/logs/mrp-app-out.log',
      error_file: '/home/ibnu/.pm2/logs/mrp-app-error.log',
      merge_logs: true,
      time: true,
    },
  ],
};
