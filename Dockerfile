# Dockerfile para o Projeto Droma (DromaFinancy)
# Base: PHP 8.4 com servidor Apache oficial
FROM php:8.4-apache

LABEL maintainer="Droma Financy Team"
LABEL description="Container de Produção para o Sistema Droma"

# Instala bibliotecas do sistema e extensões nativas do PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
    libonig-dev \
    libcurl4-openssl-dev \
    curl \
    ca-certificates \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mbstring \
    opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Habilita módulos essenciais do Apache (rewrite e headers para PWA / REST API)
RUN a2enmod rewrite headers \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Aplica configuração otimizada de PHP
COPY docker/php/php.ini /usr/local/etc/php/conf.d/wert-custom.ini

# Script de entrada para porta dinâmica (Render, Nuvem e Docker Local)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

# Define o DocumentRoot
WORKDIR /var/www/html

# Copia os arquivos do projeto para o container
COPY . /var/www/html/

# Configura permissões para o usuário do Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80 10000

# Inicia o servidor com adaptação automática de porta
CMD ["/usr/local/bin/entrypoint.sh"]
