FROM php:8.4-cli-alpine

# Install required PHP extensions
RUN apk add --no-cache \
    curl-dev \
    && docker-php-ext-install curl

# Set working directory
WORKDIR /var/www

# Copy application files (but we will mount in docker compose)
# COPY . .

# Expose port 8080
EXPOSE 8080


# To finally build, Test this image and Publish this image 
# docker build -t phpmychroma .
# docker run -p 8080:8080 phpmychroma
# docker tag phpmychroma centerlimit/phpmychroma:v0.1
# docker push centerlimit/phpmychroma:v0.1

# Start PHP built-in server
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]