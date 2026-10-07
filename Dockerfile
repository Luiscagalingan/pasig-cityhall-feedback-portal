FROM php:8.4-apache-bookworm

ENV VIRTUAL_ENV=/opt/pasig-ml-venv
ENV PATH="/opt/pasig-ml-venv/bin:${PATH}"
ENV PIP_DISABLE_PIP_VERSION_CHECK=1
ENV PIP_NO_CACHE_DIR=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
        libzip-dev \
        python3 \
        python3-venv \
    && docker-php-ext-install -j"$(nproc)" mbstring pdo_mysql zip \
    && a2dismod -f mpm_event mpm_worker \
    && a2enmod mpm_prefork \
    && a2enmod headers rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && python3 -m venv "$VIRTUAL_ENV" \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY ml/requirements.txt /tmp/pasig-ml-requirements.txt
RUN python -m pip install --upgrade pip \
    && python -m pip install -r /tmp/pasig-ml-requirements.txt \
    && rm /tmp/pasig-ml-requirements.txt

COPY . /var/www/html

RUN mkdir -p storage/import_previews storage/sessions uploads \
    && chown -R www-data:www-data storage uploads

# Fail the image build if either runtime or the real production model is broken.
RUN php -m \
    && php -r "foreach (['PDO', 'pdo_mysql', 'mbstring', 'zip', 'dom', 'fileinfo', 'iconv', 'json', 'libxml', 'openssl'] as \$extension) { if (!extension_loaded(\$extension)) { fwrite(STDERR, \"Missing PHP extension: {\$extension}\\n\"); exit(1); } }" \
    && apache2ctl -M \
    && test "$(apache2ctl -M 2>/dev/null | grep -c 'mpm_.*_module')" -eq 1 \
    && apache2ctl configtest \
    && python --version \
    && python -m pip check \
    && python -c "import cloudpickle, joblib, numpy, scipy, sklearn, threadpoolctl; print('ML packages:', 'cloudpickle=' + cloudpickle.__version__, 'joblib=' + joblib.__version__, 'numpy=' + numpy.__version__, 'scipy=' + scipy.__version__, 'scikit-learn=' + sklearn.__version__, 'threadpoolctl=' + threadpoolctl.__version__)" \
    && php tests/svm_bridge_test.php

EXPOSE 80


