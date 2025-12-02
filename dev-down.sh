#!/bin/bash
#
# Stop the development environment for EVA Gift Wrap.
#
# Usage: ./dev-down.sh [--clean]
#
# Options:
#   --clean   Remove all data (database, WordPress files) and start fresh next time.
#

set -e

echo "🛑 Stopping EVA Gift Wrap development environment..."

# Check for --clean flag.
if [ "$1" = "--clean" ]; then
    echo "🧹 Removing all data (database, WordPress files)..."
    docker compose down -v --remove-orphans
    echo ""
    echo "✅ Environment stopped and all data removed."
    echo "   Run ./dev-up.sh to start fresh."
else
    docker compose down --remove-orphans
    echo ""
    echo "✅ Environment stopped. Data preserved."
    echo "   Run ./dev-up.sh to resume."
    echo "   Run ./dev-down.sh --clean to remove all data."
fi

