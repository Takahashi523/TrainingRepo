import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.tsx"],
            refresh: true,
        }),
        react(),
    ],
    server: {
        host: "0.0.0.0",
        port: 5173,
        hmr: {
            host: "localhost",
        },
        watch: {
            usePolling: true, // ← これを追加
            interval: 1000, // ← 1秒ごとにファイル変更を確認
        },
    },
});
