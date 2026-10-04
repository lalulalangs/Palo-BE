import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
            /*
             * Font dari Bunny DIHAPUS.
             *
             * Sebelumnya `bunny('Instrument Sans')` mengunduh 6 berkas
             * woff/woff2 dan menyuntik @font-face. Panel admin memakai
             * BlinkMacSystemFont — font yang sudah ada di setiap perangkat,
             * nol unduhan, nol request jaringan. Ribuan baris backend PHP
             * juga tidak butuh font web.
             *
             * Di sisi Filament, `->font(..., provider: LocalFontProvider::class)`
             * memastikan tidak ada <link> ke fonts.bunny.net yang
             * disuntikkan ke <head>. Tanpa `provider` eksplisit itu,
             * Filament otomatis memilih BunnyFontProvider dan tries
             * memuat "BlinkMacSystemFont" dari CDN — pasti 404.
             */
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
