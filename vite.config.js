import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

/**
 * Wrap each JS chunk in an IIFE so multiple entry points
 * don't collide on minified variable names in the global scope.
 */
function wrapIife() {
    return {
        name: 'wrap-iife',
        generateBundle(_, bundle) {
            for (const chunk of Object.values(bundle)) {
                if (chunk.type === 'chunk' && chunk.fileName.endsWith('.js')) {
                    chunk.code = `(function(){${chunk.code}})();\n`;
                }
            }
        },
    };
}

export default defineConfig({
    plugins: [tailwindcss(), wrapIife()],
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        rollupOptions: {
            input: {
                orca: 'resources/css/orca.css',
                'orca-annotator': 'resources/assets/js/orca-annotator.js',
                'orca-webterm': 'resources/assets/js/orca-webterm.js',
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: '[name].js',
                assetFileNames: '[name].[ext]',
            },
        },
    },
});
