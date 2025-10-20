FROM php:8.2-apache

# Установка расширений, необходимых для Composer, SSL и Google API Client
RUN apt-get update && apt-get install -y \
    libzip-dev \
    git \
    unzip \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo opcache mbstring zip

# Примечание: pdo_mysql не нужен, если вы не используете MySQL. Я убрал его для чистоты.
# Если вы используете MySQL, верните его: docker-php-ext-install pdo pdo_mysql opcache mbstring zip

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копирование всех файлов приложения (включая .env и PHP-скрипты)
COPY . /var/www/html/

# Установка зависимостей Composer
# Render выполнит эту команду
RUN composer install --no-dev

# Команда запуска (по умолчанию для образа php-apache). Render сам запустит Apache.
CMD ["apache2-foreground"]