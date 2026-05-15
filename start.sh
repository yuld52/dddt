#!/bin/bash

MYSQL_DATA=/tmp/mysql_data
MYSQL_RUN=/tmp/mysql_run
MYSQL_SOCKET=$MYSQL_RUN/mysql.sock

# Create directories
mkdir -p $MYSQL_DATA $MYSQL_RUN

# Initialize MySQL data directory if not already done
if [ ! -f "$MYSQL_DATA/mysql/user.MYD" ] && [ ! -d "$MYSQL_DATA/mysql" ]; then
    echo "Initializing MySQL data directory..."
    mysqld --initialize-insecure --datadir=$MYSQL_DATA --user=runner 2>&1
fi

# Clean up stale socket/lock files
rm -f $MYSQL_SOCKET $MYSQL_SOCKET.lock $MYSQL_RUN/mysql.pid 2>/dev/null

# Start MySQL in the background
echo "Starting MySQL..."
mysqld \
    --datadir=$MYSQL_DATA \
    --socket=$MYSQL_SOCKET \
    --pid-file=$MYSQL_RUN/mysql.pid \
    --port=3306 \
    --bind-address=127.0.0.1 \
    --mysqlx=0 \
    --log-error=$MYSQL_DATA/mysql_error.log \
    2>/dev/null &

MYSQL_PID=$!

# Wait for MySQL to be ready
echo "Waiting for MySQL to start..."
for i in $(seq 1 30); do
    if mysql -u root -S $MYSQL_SOCKET -e "SELECT 1;" > /dev/null 2>&1; then
        echo "MySQL is ready!"
        break
    fi
    sleep 1
done

# Setup database if it doesn't exist
mysql -u root -S $MYSQL_SOCKET -e "CREATE DATABASE IF NOT EXISTS dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
echo "Database 'dev' is ready."

# Apply schema (CREATE IF NOT EXISTS, safe to run repeatedly)
if [ -f "$(dirname "$0")/schema.sql" ]; then
    mysql -u root -S $MYSQL_SOCKET dev < "$(dirname "$0")/schema.sql" 2>/dev/null
    echo "Schema applied."
fi

# Start PHP built-in web server on port 5000
echo "Starting PHP web server on port 5000..."
php -S 0.0.0.0:5000 -t . router.php

# If PHP server exits, also stop MySQL
kill $MYSQL_PID 2>/dev/null
