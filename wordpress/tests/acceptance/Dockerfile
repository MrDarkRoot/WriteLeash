ARG CORE_IMAGE
ARG CLI_IMAGE
FROM ${CORE_IMAGE} AS core
FROM ${CLI_IMAGE}
COPY --from=core /usr/src/wordpress/ /opt/wp-core/
COPY writeleash/ /opt/writeleash/
COPY release/ /opt/release/
COPY tests/ /opt/tests/
CMD ["sh", "/opt/tests/acceptance/in-container.sh"]
