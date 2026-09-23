FROM postgres:16.4-alpine AS build

RUN apk add --no-cache build-base

WORKDIR /build
COPY Makefile commitcap_native_tx_state.c commitcap_native_tx_state.control commitcap_native_tx_state--0.0.1.sql ./
RUN make with_llvm=no && make with_llvm=no install

FROM postgres:16.4-alpine

COPY --from=build /usr/local/lib/postgresql/commitcap_native_tx_state.so /usr/local/lib/postgresql/
COPY --from=build /usr/local/share/postgresql/extension/commitcap_native_tx_state* /usr/local/share/postgresql/extension/
