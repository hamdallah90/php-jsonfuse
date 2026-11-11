# Benchmark image: the real JsonFuse extension plus every baseline, on the official PHP image.
# build-arg PHP_TAG selects the PHP line (8.3-cli, 8.4-cli, 8.5-cli). The base digest is recorded in each results file.
ARG PHP_TAG=8.3-cli
FROM php:${PHP_TAG}

ARG SIMDJSON_VERSION=4.0.0
ARG FASTJSON_COMMIT=cca9aca8fea685586c3259594ea0eb3ecd56cb94
ARG JSON_MACHINE_TAG=1.2.6

RUN apt-get update \
 && apt-get install -y --no-install-recommends time git ca-certificates $PHPIZE_DEPS \
 && rm -rf /var/lib/apt/lists/*

RUN printf '\n' | pecl install simdjson-${SIMDJSON_VERSION} || { echo "simdjson-${SIMDJSON_VERSION} did not build for ${PHP_TAG}" > /simdjson-unavailable; }

# iliaal/fastjson tag 0.10.2, from GitHub at a pinned commit.
RUN git clone -q https://github.com/iliaal/fastjson.git /fastjson \
 && cd /fastjson && git checkout -q ${FASTJSON_COMMIT} \
 && phpize >/dev/null \
 && ./configure --enable-fastjson >/dev/null \
 && make -j"$(nproc)" >/dev/null \
 && make install >/dev/null

# halaxa/json-machine at a pinned tag: autoloaded by path, no composer step.
RUN git clone -q --depth 1 --branch ${JSON_MACHINE_TAG} https://github.com/halaxa/json-machine.git /json-machine

COPY . /jsonfuse
# Build from a copy so no phpize output lands in the source tree.
# The flags PHP itself was built with (the image's PHP_CFLAGS: -O2 and hardening), no -march=native, no LTO.
RUN cp -r /jsonfuse /build && cd /build \
 && phpize >/dev/null \
 && CFLAGS="$PHP_CFLAGS -Wall -Wextra" ./configure --enable-jsonfuse >/dev/null \
 && make -j"$(nproc)" 2>&1 | tee /build/make.log \
 && cp modules/jsonfuse.so "$(php-config --extension-dir)/" \
 && { ! grep -E 'warning:' /build/make.log | grep -E 'src/' || { echo "COMPILER WARNINGS IN src/"; exit 1; }; }

WORKDIR /jsonfuse/benchmarks
