#!/bin/sh
# Released Cbox tools only. No OS packages or mutable release selection.
set -eu
arch=${1:?Expected amd64 or arm64}
out=${2:?Expected output directory}
case "$arch" in
  amd64)
    init_sha=98cd5c05e39f1786f0e19af7189f2e188da94a1bfaedeaa4fa3dac776d5734eb
    exporter_sha=69b78e1ffbb6f1e9bc31b84a257d0379f1c58b039d6bcb9c3473e9c225237940
    ;;
  arm64)
    init_sha=548483875a65b2d8d37527a04eec4904e4bd8425642cf5e87c3c666b97859942
    exporter_sha=b6e53628030d63bd5503faa7f8227de7fc1762f3b13b81fbad7879f4c9738b9d
    ;;
  *) echo 'Unsupported runtime architecture' >&2; exit 1 ;;
esac
case "$arch" in
  amd64) init_asset=760188932; exporter_asset=659447671 ;;
  arm64) init_asset=760188930; exporter_asset=659447700 ;;
esac
mkdir -p "$out"
# The public release URL sometimes answers 504 while the API serves the same asset;
# the pinned SHA-256 below decides integrity whichever source answered.
fetch() {
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 180 \
    --retry 4 --retry-all-errors --retry-delay 5 "$2" -o "$1" \
  || curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 180 \
    --retry 4 --retry-all-errors --retry-delay 5 -H 'Accept: application/octet-stream' "$3" -o "$1"
}
fetch "$out/cbox-init" "https://github.com/cboxdk/init/releases/download/v3.10.0/cbox-init-linux-$arch" \
  "https://api.github.com/repos/cboxdk/init/releases/assets/$init_asset"
fetch "$out/fpm-exporter" "https://github.com/cboxdk/fpm-exporter/releases/download/v3.1.2/fpm-exporter-linux-$arch" \
  "https://api.github.com/repos/cboxdk/fpm-exporter/releases/assets/$exporter_asset"
printf '%s  %s\n' "$init_sha" "$out/cbox-init" "$exporter_sha" "$out/fpm-exporter" | sha256sum -c -
chmod 0755 "$out/cbox-init" "$out/fpm-exporter"
