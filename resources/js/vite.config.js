import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// Standalone build for the Twill AI admin chat. Deliberately separate from a
// host app's Vite setup: outputs a self-contained IIFE + CSS committed to
// resources/dist, which AssetController serves from a route, so adopters need
// no JS build step at all.
//
// Build with: npm install && npm run build (from the package root).
export default defineConfig({
    plugins: [vue()],
    // Never copy the host app's public/ dir into our output.
    publicDir: false,
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
        __VUE_OPTIONS_API__: 'false',
        __VUE_PROD_DEVTOOLS__: 'false',
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
    },
    build: {
        // resources/dist — where AssetController reads from. This used to point
        // at the host application's public/vendor, a leftover from before the
        // code was a package.
        outDir: resolve(__dirname, '../dist'),
        emptyOutDir: true,
        cssCodeSplit: false,
        lib: {
            entry: resolve(__dirname, 'main.js'),
            name: 'TwillAi',
            formats: ['iife'],
            fileName: () => 'twill-ai.iife.js',
            cssFileName: 'twill-ai',
        },
        rollupOptions: {
            output: {
                assetFileNames: (assetInfo) =>
                    assetInfo.name && assetInfo.name.endsWith('.css') ? 'twill-ai.css' : '[name][extname]',
            },
        },
    },
});
