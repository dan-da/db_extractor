# Script to create a new example database

# stop on any non-zero exit code
set -e

source ./init_db_params.sh
createdb -U $DBUSER -h $DBHOST --encoding=unicode $DB || { echo "failed"; exit 1; }
psql -U $DBUSER -h $DBHOST --set ON_ERROR_STOP=1 -c "ALTER DATABASE $DB SET default_transaction_isolation = serializable"
psql -U $DBUSER -h $DBHOST --set ON_ERROR_STOP=1 -1 -f schema.sql $DB
