#!/usr/bin/env bash
# Upload media files to Bunny CDN
# Usage: upload-to-bunny.sh <source-dir> <media-prefix>
#
# Required env vars: BUNNY_STORAGE_HOST, BUNNY_STORAGE_ZONE,
#                    BUNNY_STORAGE_PASSWORD, BUNNY_CDN_URL
# Outputs to $GITHUB_OUTPUT: screenshot_files, video_files, media_url_prefix, has_media

set -euo pipefail

SOURCE_DIR="$1"
MEDIA_PREFIX="$2"
UPLOAD_OK=true

for file in "${SOURCE_DIR}"/*; do
  [ -f "$file" ] || continue
  filename=$(basename "$file")
  HTTP_CODE=$(curl --fail --silent --output /dev/null --write-out "%{http_code}" \
    --request PUT \
    --url "https://${BUNNY_STORAGE_HOST}/${BUNNY_STORAGE_ZONE}/${MEDIA_PREFIX}/${filename}" \
    --header "AccessKey: ${BUNNY_STORAGE_PASSWORD}" \
    --header "Content-Type: application/octet-stream" \
    --data-binary "@${file}")
  if [ "$HTTP_CODE" -ge 200 ] && [ "$HTTP_CODE" -lt 300 ]; then
    echo "Uploaded: ${filename} (HTTP ${HTTP_CODE})"
  else
    echo "Failed: ${filename} (HTTP ${HTTP_CODE})"
    UPLOAD_OK=false
  fi
done

SCREENSHOT_FILES=$(ls "${SOURCE_DIR}"/*.png 2>/dev/null | xargs -I{} basename {} | tr '\n' ',' || echo "")
VIDEO_FILES=$(ls "${SOURCE_DIR}"/*.webm 2>/dev/null | xargs -I{} basename {} | tr '\n' ',' || echo "")

echo "screenshot_files=${SCREENSHOT_FILES}" >> "$GITHUB_OUTPUT"
echo "video_files=${VIDEO_FILES}" >> "$GITHUB_OUTPUT"
echo "media_url_prefix=${BUNNY_CDN_URL}/${MEDIA_PREFIX}" >> "$GITHUB_OUTPUT"
echo "has_media=${UPLOAD_OK}" >> "$GITHUB_OUTPUT"
