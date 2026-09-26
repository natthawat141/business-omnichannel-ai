#!/usr/bin/env bash
# Docker-only S3 client so the VM does not need a host AWS or rclone package.
# Cloudflare R2 rejects the flexible checksums that newer AWS CLI builds send
# by default, so the client image stays on the last line that does not do that.

AWS_CLI_IMAGE="${AWS_CLI_IMAGE:-amazon/aws-cli:2.22.35}"

prepare_aws_config() {
  S3_CONFIG_DIR="$(mktemp -d)"
  cat > "${S3_CONFIG_DIR}/config" <<EOF
[default]
region = ${BACKUP_S3_REGION}
s3 =
    addressing_style = path
EOF
  chmod 700 "$S3_CONFIG_DIR"
  chmod 600 "${S3_CONFIG_DIR}/config"
  export AWS_ACCESS_KEY_ID="$BACKUP_S3_ACCESS_KEY_ID"
  export AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET_ACCESS_KEY"
  export AWS_DEFAULT_REGION="$BACKUP_S3_REGION"
  export AWS_REQUEST_CHECKSUM_CALCULATION=when_required
  export AWS_RESPONSE_CHECKSUM_VALIDATION=when_required
}

s3() {
  docker run --rm \
    -e AWS_ACCESS_KEY_ID \
    -e AWS_SECRET_ACCESS_KEY \
    -e AWS_DEFAULT_REGION \
    -e AWS_REQUEST_CHECKSUM_CALCULATION \
    -e AWS_RESPONSE_CHECKSUM_VALIDATION \
    -v "${S3_CONFIG_DIR}:/root/.aws:ro" \
    -v "${S3_WORKDIR}:/work" \
    "$AWS_CLI_IMAGE" \
    --endpoint-url "$BACKUP_S3_ENDPOINT" \
    "$@"
}
