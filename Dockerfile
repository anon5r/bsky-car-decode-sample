FROM php:8.3-fpm-bookworm

# Install system dependencies
RUN apt update && apt upgrade -y && apt install -y \
    curl \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    make bash \
    unzip \
    git

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd


# Copy golang
COPY --from=golang:1.22-alpine /usr/local/go /usr/local/go

ENV GOTOOLCHAIN=local
ENV GOLANG_VERSION=1.22.2
ENV GOROOT=/usr/local/go
ENV GOPATH=/go
ENV PATH=$GOPATH/bin:$GOROOT/bin:$PATH

WORKDIR /go/bin

RUN git clone https://github.com/bluesky-social/indigo /usr/local/indigo && \
    mkdir -p /go/bin && \
    cd /usr/local/indigo && \
    make all && \
    find . -maxdepth 1 -type f ! -name "*.*" -executable -exec mv {} /go/bin \;

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Change current user to www
USER www-data

# Set working directory
WORKDIR /app