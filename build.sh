#!/usr/bin/env bash
set -euo pipefail

# Build script for packaging OpenCart OCMOD extension
# Steps:
# 1) Create 'upload' directory
# 2) Get the list of tracked files from git and copy them into 'upload'
# 3) Zip 'upload' into find_iq.3.x.ocmod.zip
# 4) Remove 'upload'

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

# Basic checks
if ! command -v git >/dev/null 2>&1; then
  echo "Error: git is not installed or not in PATH" >&2
  exit 1
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Error: current directory is not a git repository" >&2
  exit 1
fi

# Prepare upload directory
if [ -d upload ]; then
  rm -rf upload
fi
mkdir -p upload

# Ensure src exists
if [ ! -d src ]; then
  echo "Error: 'src' directory not found. Expected to copy its contents into 'upload/'." >&2
  exit 1
fi

echo "Collecting git-tracked files from 'src/' and copying into 'upload/' ..."

# Copy only files tracked by git under src/, preserving structure relative to src/
# Using NUL-separated output to safely handle filenames with spaces/newlines
while IFS= read -r -d '' file; do
  # Strip leading 'src/' to map into 'upload/'
  rel_path="${file#src/}"
  dest_dir="upload/$(dirname "$rel_path")"
  mkdir -p "$dest_dir"
  # Use rsync if available for speed and permissions preservation; fall back to cp
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --no-times --no-perms --no-owner --no-group "$file" "$dest_dir/"
  else
    cp -f "$file" "$dest_dir/"
  fi

done < <(git ls-files -z src)

# Create the zip archive
ARCHIVE_NAME="find_iq.3.x.ocmod.zip"
if [ -f "$ARCHIVE_NAME" ]; then
  rm -f "$ARCHIVE_NAME"
fi

echo "Creating zip archive: $ARCHIVE_NAME ..."
# Use zip if available, otherwise try '7z' if installed
if command -v zip >/dev/null 2>&1; then
  zip -r -q "$ARCHIVE_NAME" upload
elif command -v 7z >/dev/null 2>&1; then
  7z a -tzip -r "$ARCHIVE_NAME" upload >/dev/null
else
  echo "Error: neither 'zip' nor '7z' is installed to create the archive" >&2
  exit 1
fi

echo "Cleaning up temporary 'upload' directory..."
rm -rf upload

echo "Done. Created $ARCHIVE_NAME"
