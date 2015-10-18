# Script to drop the previous database and create a new one with all initial data.
source ./init_db_params.sh && \
dropdb -U $DBUSER $DB && \
./create_db.sh
