# Same supported Woo #111 core/CLI fixture, with independent sibling sources.
FROM wordpress:7.1.2-php8.2-apache@sha256:da4c3e7b0051b70835b0a188ecdd5bce5ebf6f83d1f3df41553f1a97d237fd19 AS core
FROM wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89
COPY --from=core /usr/src/wordpress/ /opt/wp-core/
COPY writeleash/ /opt/writeleash/
COPY writeleash-price-history/ /opt/writeleash-price-history/
COPY writeleash-price-campaigns/ /opt/writeleash-price-campaigns/
COPY release/ /opt/release/
COPY tests/ /opt/tests/
CMD ["sh", "/opt/tests/portfolio/in-container.sh"]
