import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// Standalone build for the Twill AI admin chat. Deliberately separate from
// the host app's Vite setup: outputs a self-contained IIFE + css committed
// to public/vendor/twill-ai so adopters don't need a JS build step.
//
// Build with: npm run build:twill-ai
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
        outDir: resolve(__dirname, '../../../public/vendor/twill-ai'),
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
