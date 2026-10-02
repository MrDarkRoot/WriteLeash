FROM postgres:16.4-alpine@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c AS build

RUN apk add --no-cache build-base

WORKDIR /build
COPY Makefile writeleash_native_tx_state.c writeleash_native_tx_state.control writeleash_native_tx_state--0.0.1.sql ./
RUN make with_llvm=no && make with_llvm=no install

FROM postgres:16.4-alpine@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c

COPY --from=build /usr/local/lib/postgresql/writeleash_native_tx_state.so /usr/local/lib/postgresql/
COPY --from=build /usr/local/share/postgresql/extension/writeleash_native_tx_state* /usr/local/share/postgresql/extension/
