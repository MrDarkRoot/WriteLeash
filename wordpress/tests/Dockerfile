ARG CORE_IMAGE=wordpress:6.8.3-php8.2-apache@sha256:1e6215749283955d5c9ffea6c297651ed23cdfdbb91677ad7abd705b2682f2cf
FROM ${CORE_IMAGE} AS core
FROM wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89
COPY --from=core /usr/src/wordpress/ /opt/wp-core/
COPY writeleash/ /opt/writeleash/
COPY release/ /opt/release/
COPY tests/ /opt/tests/
CMD ["sh", "/opt/tests/in-container.sh"]
