import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      "/ws": {
        target: "ws://localhost:8787",
        ws: true,
        changeOrigin: true,
      },
      "/auth": "http://localhost:8080",
      "/me": "http://localhost:8080",
      "/users": "http://localhost:8080",
      "/friends": "http://localhost:8080",
      "/blocklist": "http://localhost:8080",
      "/conversations": "http://localhost:8080",
      "/messages": "http://localhost:8080",
      "/uploads": "http://localhost:8080",
      "/reports": "http://localhost:8080",
      "/health": "http://localhost:8080",
    },
  },
});
