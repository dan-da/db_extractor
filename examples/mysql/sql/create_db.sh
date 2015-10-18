# Script to create a new example database

# stop on any non-zero exit code
set -e

source ./init_db_params.sh
mysql -u $DBUSER -h $DBHOST -e "create database $DB" || { echo "failed"; exit 1; }
mysql -u $DBUSER -h $DBHOST < schema.sql $DB
