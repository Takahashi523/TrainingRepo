import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        // ページ遷移時のトップ進捗バー（Inertia 標準の NProgress）。
        // ボタン・リンク・選択枠と揃えるため、プライマリ色（--primary: 214 80% 45%）の 16 進近似を用いる。
        // progress は起動時に静的な色文字列を要求するため CSS 変数は直接参照できない（ダークモードには追従しない）。
        color: '#1767CF',
    },
});
