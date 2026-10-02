ARG CORE_IMAGE
FROM ${CORE_IMAGE} AS core
ARG CLI_IMAGE
FROM ${CLI_IMAGE}
COPY --from=core /usr/src/wordpress/ /opt/wp-core/
COPY writeleash/ /opt/writeleash/
COPY release/ /opt/release/
COPY tests/ /opt/tests/
CMD ["sh", "/opt/tests/acceptance/in-container.sh"]
