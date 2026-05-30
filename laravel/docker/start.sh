#!/bin/sh
set -e

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

php-fpm -D

nginx -g "daemon off;"
