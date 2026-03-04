import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            // Desactiva el WebSocket de live reload para evitar "ws://localhost:8081 failed"
            // cuando no se usa "npm run dev" o se accede desde otro dominio (ej. mayoreo.cloud).
            refresh: false,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
