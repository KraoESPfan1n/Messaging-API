module.exports = {
  apps: [
    {
      name: "sumee-api",
      cwd: "/workspaces/dev",
      script: "/usr/bin/php",
      args: "-S 0.0.0.0:8080 -t api/public",
      env: {
        APP_ENV: "dev",
      },
    },
    {
      name: "sumee-ws",
      cwd: "/workspaces/dev/ws",
      script: "/home/codespace/.bun/bin/bun",
      args: "run src/server.ts",
    },
    {
      name: "sumee-web",
      cwd: "/workspaces/dev/web",
      script: "npm",
      args: "run dev -- --host 0.0.0.0 --port 5173",
    },
  ],
};
