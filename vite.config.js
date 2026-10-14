import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import fs from 'node:fs';

// Laragon sirve el sitio por https (https://ferretero-soft.test). Para que `npm run dev` no sea
// bloqueado por "contenido mixto", el servidor de Vite usa los mismos certificados de Laragon.
const host = 'ferretero-soft.test';
const laragonSsl = process.env.LARAGON_SSL_DIR || 'C:/laragon/etc/ssl';
const key = `${laragonSsl}/laragon.key`;
const cert = `${laragonSsl}/laragon.crt`;
const https = fs.existsSync(key) && fs.existsSync(cert) ? { key: fs.readFileSync(key), cert: fs.readFileSync(cert) } : undefined;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: { transformAssetUrls: { base: null, includeAbsolute: false } },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: { '@': path.resolve(__dirname, 'resources/js') },
    },
    server: {
        ...(https ? { https, host, hmr: { host } } : {}),
        cors: true,
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
