#!/bin/bash

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
DATA_DIR="$PROJECT_DIR/data"

echo "Starting data fetch process..."
echo "Project directory: $PROJECT_DIR"
echo "Data directory: $DATA_DIR"

# Pull latest changes from data repository
echo "Pulling latest changes from data repository..."
cd "$DATA_DIR"
git pull

if [ $? -ne 0 ]; then
    echo "Warning: Failed to pull latest changes, continuing anyway..."
fi

# Execute the fetch script
cd "$PROJECT_DIR"
php "$SCRIPT_DIR/01_fetch.php"

if [ $? -ne 0 ]; then
    echo "Error: Fetch script failed"
    exit 1
fi

echo "Fetch completed successfully"

# Change to data directory and commit changes
cd "$DATA_DIR"

if [ ! -d .git ]; then
    echo "Error: Data directory is not a git repository"
    exit 1
fi

# Check if there are any changes
if [[ -z $(git status -s) ]]; then
    echo "No changes to commit"
    exit 0
fi

echo "Changes detected, committing and pushing..."

# Add all changes
git add .

# Commit with timestamp
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
git commit -m "Auto update: $TIMESTAMP"

# Push to remote
git push

if [ $? -eq 0 ]; then
    echo "Successfully pushed changes to remote"
else
    echo "Error: Failed to push changes"
    exit 1
fi

echo "Cron job completed successfully"
