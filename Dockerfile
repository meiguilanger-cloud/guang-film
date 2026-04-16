# Dockerfile for new_music_project
FROM php:8.2-apache

# Set working directory inside container
WORKDIR /var/www/html/new_music_project

# Copy project files
COPY . .

# Install any needed extensions (SQLite is included by default)
# (Add additional dependencies here if required)

# Allow single-file uploads up to 100MB inside PHP/Apache.
RUN { \
        echo 'upload_max_filesize=100M'; \
        echo 'post_max_size=120M'; \
        echo 'max_execution_time=300'; \
        echo 'max_input_time=300'; \
        echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini \
    && mkdir -p backend/uploads logs \
    && chown -R www-data:www-data backend/uploads logs \
    && chmod -R 755 backend/uploads logs

# Expose port 80 (Apache default)
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]
