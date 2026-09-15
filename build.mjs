import { build } from 'esbuild';
import { copyFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
const root = dirname(fileURLToPath(import.meta.url));
await mkdir(join(root, 'dist'), { recursive: true });
await Promise.all([
    copyFile(join(root, 'resources/fonts/InterVariable.woff2'), join(root, 'dist/InterVariable.woff2')),
    copyFile(join(root, 'resources/fonts/LICENSE.txt'), join(root, 'dist/Inter-LICENSE.txt')),
    build({ entryPoints: [join(root, 'resources/js/runtime.js')], bundle: true, minify: true, format: 'iife', target: 'es2020', outfile: join(root, 'dist/editor.js') }),
    build({ entryPoints: [join(root, 'resources/js/alpine.js')], bundle: true, minify: true, format: 'esm', target: 'es2020', outfile: join(root, 'dist/alpine.js') }),
    copyFile(join(root, 'resources/css/page-editor.css'), join(root, 'dist/editor.css')),
]);
