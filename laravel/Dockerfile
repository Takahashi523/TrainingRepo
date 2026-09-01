# ---- Stage 1: Node 22 で React をビルド ----
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources/ resources/
COPY vite.config.ts ./
# ビルドに必要な設定ファイル（Issue #90）。
# - tsconfig.json  : npm run build は `tsc && vite build`。無いと tsc が usage を出して exit 1
# - postcss.config.js / tailwind.config.js
#                  : vite.config.ts は @tailwindcss/vite を使わず PostCSS 経由で Tailwind を通すため、
#                    無いと @tailwind ディレクティブが未処理のまま残り、スタイルの無い CSS が出力される
#                    （ビルドは成功してしまうので気づけない）
# ※ components.json は shadcn/ui の CLI 設定でビルドには不要
COPY tsconfig.json tailwind.config.js postcss.config.js ./
RUN npm run build

# ---- Stage 2: PHP 8.3.30 + Nginx ----
FROM php:8.3.30-fpm-alpine

# Nginx・必要ライブラリのインストール
RUN apk add --no-cache nginx bash \
    && docker-php-ext-install pdo pdo_mysql

# Composer 2.8 のインストール（バージョン固定）
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Nginx 設定を組み込む
# ⚠️ Alpine の nginx パッケージは conf.d/ を main コンテキストで、http.d/ を http ブロック内で
#    include する。server ブロックを conf.d/ に置くと
#    `nginx: [emerg] "server" directive is not allowed here` で設定テストが失敗し、
#    start.sh の `nginx -g "daemon off;"` が起動できない（Issue #90）。
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# アップロード上限を引き上げる（既定 2M ではアプリの 5MB 上限に届かないため）
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Laravel 本体をコピー
WORKDIR /var/www
COPY . .

# .dockerignore が効いていることをビルド時に検証する（Issue #90 #2）。
# .dockerignore はパターンの書き間違い（行末コメント等）を黙って無視するため、
# 秘匿ファイルの焼き込みが復活してもエラーにならない。ここで落として気づけるようにする。
RUN set -e; \
    for f in .env bootstrap/cache/config.php bootstrap/cache/services.php; do \
        if [ -e "$f" ]; then \
            echo "ERROR: $f がビルドコンテキストからイメージに入っています。laravel/.dockerignore を確認してください。"; \
            exit 1; \
        fi; \
    done; \
    echo "OK: 秘匿ファイルはイメージに含まれていません"

# React ビルド成果物をコピー
COPY --from=frontend /app/public/build public/build

# PHP 依存解決
RUN composer install --no-dev --optimize-autoloader

# 起動スクリプトのセットアップ
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
