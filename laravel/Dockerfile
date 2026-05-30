# ---- Stage 1: Node 22 で React をビルド ----
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources/ resources/
COPY vite.config.ts ./
RUN npm run build

# ---- Stage 2: PHP 8.3.30 + Nginx ----
FROM php:8.3.30-fpm-alpine

# Nginx・必要ライブラリのインストール
RUN apk add --no-cache nginx bash \
    && docker-php-ext-install pdo pdo_mysql

# Composer 2.8 のインストール（バージョン固定）
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Nginx 設定を組み込む
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Laravel 本体をコピー
WORKDIR /var/www
COPY . .

# React ビルド成果物をコピー
COPY --from=frontend /app/public/build public/build

# PHP 依存解決
RUN composer install --no-dev --optimize-autoloader

# 起動スクリプトのセットアップ
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
