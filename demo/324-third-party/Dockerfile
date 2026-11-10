# Disposable demo runtime for issue #324.
# WordPress core files come from the same pinned image as wordpress/tests/admin.
# PHP is the pinned WP-CLI image, which is what serves the site. The plugin is
# mounted at runtime and staged with the public allowlist; it is not baked here.
ARG CORE_IMAGE=wordpress:7.1.2-php8.2-apache@sha256:da4c3e7b0051b70835b0a188ecdd5bce5ebf6f83d1f3df41553f1a97d237fd19
FROM ${CORE_IMAGE} AS core
FROM wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89
COPY --from=core /usr/src/wordpress/ /opt/wp-core/
WORKDIR /tmp
EXPOSE 8080
CMD ["sleep", "infinity"]
