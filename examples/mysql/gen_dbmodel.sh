source ./sql/init_db_params.sh
../../db_extractor.php -m db_model -h "$DBHOST" -d "$DB" -u "$DBUSER" -p "$DBPASS" -a mysql
