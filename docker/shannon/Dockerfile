# The Shannon worker of make pentest: it accepts the self-signed certificate of compose.pentest.yaml (F122).
FROM keygraph/shannon:3.5.0@sha256:7824cff7c54d63836d321a057bdc59c54d85dc0dde7fe486f837592a36a58c4e

# @playwright/cli merges this after the cli.config.json that Shannon writes. The worker's calls to the model provider keep TLS verification.
ENV PLAYWRIGHT_MCP_IGNORE_HTTPS_ERRORS=true \
    CURL_HOME=/etc/shannon

RUN mkdir -p /etc/shannon && echo insecure > /etc/shannon/.curlrc
