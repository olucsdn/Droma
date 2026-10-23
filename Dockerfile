# Dockerfile para o Projeto Wert (WertFinancy)
# Base: PHP 8.4 com servidor Apache oficial
FROM php:8.4-apache

LABEL maintainer="Wert Financy Team"
LABEL description="Container de Produção para o Sistema Wert"

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
RUN a2enmod rewrite headers

# Aplica configuração otimizada de PHP
COPY docker/php/php.ini /usr/local/etc/php/conf.d/wert-custom.ini

# Define o DocumentRoot
WORKDIR /var/www/html

# Copia os arquivos do projeto para o container
COPY . /var/www/html/

# Configura permissões para o usuário do Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Inicia o Apache em foreground
CMD ["apache2-foreground"]
