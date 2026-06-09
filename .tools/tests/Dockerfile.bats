# Test image for install.sh — bats + the few host tools the script uses.
FROM bats/bats:1.10.0
RUN apk add --no-cache openssl coreutils sed grep curl
