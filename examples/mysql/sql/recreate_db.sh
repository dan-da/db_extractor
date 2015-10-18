# Script to drop the previous database and create a new one with all initial data.
source ./init_db_params.sh && \
mysql -u $DBUSER -e "drop database $DB" && \
./create_db.sh
