import { defineConfig } from 'vite';

export default defineConfig({
    publicDir: false,
    build: {
        emptyOutDir: true,
        lib: {
            entry: 'resources/js/checkout/tamara-checkout.ts',
            formats: ['iife'],
            name: 'TamaraCheckout',
            fileName: () => 'tamara-checkout.js',
        },
        outDir: 'public/build/checkout',
        sourcemap: true,
    },
});
