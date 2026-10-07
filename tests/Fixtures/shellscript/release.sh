#!/usr/bin/env bash
# Prints the version.
# It reads the VERSION file.
version() {
    cat VERSION
}

function build {
    log() { echo "$1"; }
    log building
}

function publish() {
    version
}

set -euo pipefail
build
publish
