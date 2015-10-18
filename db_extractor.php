#!/usr/bin/env php

<?php

// inspired from http://www.odi.ch/prog/design/php/guide.php  ( DAO section )

exit ( main() );

function main() {
    
    /**
     * This call makes three things happen:
     *
     *   1) a global error handler for php errors that causes an exception to be
     *      thrown instead of standard php error handling.
     *
     *   2) a global exception handler for any exceptions that are somehow not
     *      caught by the application code.
     *
     *   3) error_reporting is set to E_STRICT, so that even notices cause an
     *      exception to be thrown.  This way we are forced to deal with even
     *      the minor issues during development, and hopefully fewer issues
      make it out into the world.
     */
    
    init_strict_mode();
    

   $opt = getopt("D:d:h:u:p:o:v:m:n:c:t:s:xa:?");
   if( @$opt['?'] || !@$opt['d'] ){ 
      print_help();
      return -1;
   }

   $config = array( );
   $config['db'] = get_option( $opt, 'd' );
   $config['host'] = get_option( $opt, 'h', 'localhost' );
   $config['user'] = get_option( $opt, 'u', '' );
   $config['pass'] = get_option( $opt, 'p', '' );
   $config['namespace_prefix'] = get_option( $opt, 'n', '' );
   $config['verbosity'] = get_option( $opt, 'v', 1 );
   $config['model_dir'] = get_option( $opt, 'm', './db_model' );
   $config['crud_dir'] = get_option( $opt, 'c', './db_crud' );
   $config['sort_cols'] = get_option( $opt, 's', 'none' );
   $config['tables'] = get_option( $opt, 't', null );
   $config['no_test_msg'] = get_option( $opt, 'x', null );
   $config['stdout'] = STDOUT;
   $config['db_type'] = get_option( $opt, 'a', 'postgres' );

   if( isset( $opt['o'] ) ) {
      $fh = fopen( $opt['o'], 'w' );
      if( !$fh ) {
         die( "Could not open {$opt['o']} for writing" );
      }
      $config['stdout'] = $fh;
   }

    $extractor = new db_extractor( $config );
    $success = $extractor->run();
   
    fclose( $config['stdout'] );
    return $success ? 0 : -1;
}

function get_option($opt, $key, $default=null) {
    return @$opt[$key ] ?: $default;
}

function print_help() {
   
   echo <<< END

   db_extractor.php [Options] -d <db> [-h <host> -d <db> -u <user> -p <pass> -c <crud_dir>]
   
   Required:
   -d db              database name
   
   Options:
   
   -h HOST            hostname of machine running db.     default = localhost
   -u USER            db username                         default = searchrev
   -p PASS            db password                         default = password
   
   -a dbtype          mysql | postgres                    default = postgres

   -m model_dir       path to write db model files.       default = ./db_model.
                                                          specify "=NONE=" to omit.
                                                          
   -c crud_dir        path to write crud files.           default = ./db_crud.
                                                          specify "=NONE=" to omit.
                                                          
   -s sort_cols       column ordering.                    default = original
                        original     = use ordering from database.
                        keys_alpha   = sort by key type desc, then alphabetical asc
                        alpha        = sort by alphabetical asc
                        
   -t tables         comma separated list of tables.      default = generate for all tables
   
   -n namespace      a prefix that will be pre-pended to all classnames.  This makes it
                     possible to use the generated classes multiple times in the same project.
                                                          default = no prefix.

   -x                omit message about running phpunit
   
   -o file            Send all output to FILE
   -v <number>        Verbosity level.  default = 1
                        0 = silent except fatal error.
                        1 = silent except warnings.
                        2 = informational
                        3 = debug. ( includes extra logging and source line numbers )
                        
    -?                Print this help.


END;

}




class db_extractor {

    private $host;
    private $user;
    private $pass;
    private $db;
    
    private $verbosity = 1;
    private $stdout = STDOUT;

    /**
     * Set this value to prefix all classes/tables/files.  This
     * helps avoid namespace collisions when using multiple
     * db_model in a single app.  I recommend using the form
     * 'xxx_' as the prefix.
     */
    private $namespace_prefix = '';
    private $tests_dirname = 'phpunit';
    
    private $output_dir = './db_model';
    private $crud_dir = './db_crud';
    private $dto_dir;
    private $dto_base_dir;
    private $dto_custom_dir;
    private $dao_dir;
    private $dao_base_dir;
    private $dao_custom_dir;
    
    private $dao_base_class_file;
    
    private $sys_quote_char = '"';
    
    private $db_type;  // mysql | postgres
    
    const log_error = 0;
    const log_warning = 1;
    const log_info = 2;
    const log_debug = 3;
    
    function __construct( $config ) {
        
        $this->host = $config['host'];
        $this->user = $config['user'];
        $this->pass = $config['pass'];
        $this->db = $config['db'];
        $this->db_type = $config['db_type'];
        $this->output_dir = $config['model_dir'];
        $this->sort_cols = $config['sort_cols'];
        $this->namespace_prefix = $config['namespace_prefix'];
        $this->no_test_msg = isset( $config['no_test_msg'] );

         $this->tables = array();

        $tables = @$config['tables'] ? explode( ',', @$config['tables'] ) : null;
        if( $tables ) {
            foreach( $tables as $t ) {
                $this->tables[trim($t)] = 1;
            }
        }
        
        $this->verbosity = (int)$config['verbosity'];
        $this->stdout = $config['stdout'];
        
        $this->dto_dir = $this->output_dir . '/dto/';
        $this->dto_base_dir = $this->output_dir . '/dto/base/';
        $this->dto_custom_dir = $this->output_dir . '/dto/custom/';
        $this->dao_dir = $this->output_dir . '/dao/';
        $this->dao_tests_dir = $this->dao_dir . '/' . $this->tests_dirname;
        $this->dao_base_dir = $this->output_dir . '/dao/base/';
        $this->dao_custom_dir = $this->output_dir . '/dao/custom/';
        $this->dao_criteria_dir = $this->output_dir . '/dao/criteria/';
        $this->dao_criteria_tests_dir = $this->dao_criteria_dir . '/' . $this->tests_dirname;
        $this->model_test_harness_dir = $this->output_dir . '/' . $this->tests_dirname;
        $this->model_test_harness_filename = 'test_runner.php';
        
        $this->dao_base_class_file = $this->dao_base_dir . '/' . $this->namespace_prefix . 'daoBase.php';
        $this->db_factory_class_file = $this->output_dir . '/' . $this->db_model_factory_filename();
        $this->dao_criteria_class_file = $this->dao_criteria_dir . $this->dao_criteria_filename();
                
        $jqgrid_dirname = 'jqgrid-3.5.6.min';
        $jqgrid_cdn_base_url = 'https://cdnjs.cloudflare.com/ajax/libs/jqgrid/4.6.0/';
        $this->crud_dir = $config['crud_dir'];
        $this->crud_server_dir = $this->crud_dir . '/server/';
        $this->crud_server_conf_dir = $this->crud_server_dir . '/config/';
        $this->crud_client_dir = $this->crud_dir . '/client/';
        $this->crud_test_harness_dir = $this->crud_server_dir . '/' . $this->tests_dirname;
        $this->crud_test_harness_filename = 'test_runner.php';
        
//        $this->crud_jqgrid_src_dir = dirname(__FILE__) . '/crud/' . $jqgrid_dirname .'/';
//        $this->crud_jqgrid_target_dir = $this->crud_client_dir . '/' . $jqgrid_dirname . '/';
        
//        $this->crud_jqgrid_target_dir_rel = './' . $jqgrid_dirname;
        $this->crud_jquery_ui_theme = 'cupertino';
//        
    }

   function run() {
      
      $success = true;
      if( $this->output_dir && $this->output_dir != '=NONE=' ) {
         $success = $this->create_db_model();
      }
      
      if( $this->crud_dir && $this->crud_dir != '=NONE=' && $success) {
         $success = $this->create_db_crud();
      }
      
      return $success;
   }
      
    function create_db_crud() {

        try {
        
            $this->ensure_dir_exists( $this->crud_dir );

            $this->ensure_dir_exists( $this->crud_server_dir );
            $this->ensure_dir_exists( $this->crud_server_conf_dir );
            $this->ensure_dir_exists( $this->crud_client_dir );
            $this->ensure_dir_exists( $this->crud_test_harness_dir );
            
            $this->log( sprintf( 'deleting CRUD server files in %s', $this->crud_server_dir ),  __FILE__, __LINE__, self::log_debug );
            $files = glob( $this->crud_server_dir . '*.base.php') + glob( $this->crud_server_dir . '*.ctl.php' );
            foreach( $files as $file ) {
                
                if( count( $this->tables ) ) {
                    $tname = explode( '.', $file);  $tname = $tname[0];
                    if( !@$this->tables[$tname] ) {
                            continue;
                    }
                }
                
                $rc = @unlink( $file );
                if( !$rc ) {
                    throw new Exception( "Cannot unlink old file " . $file );
                }
                $this->log( sprintf( 'deleted %s', $file ),  __FILE__, __LINE__, self::log_debug );
            }
            $this->log( sprintf( 'deleted CRUD server base files in %s', $this->crud_server_dir ),  __FILE__, __LINE__, self::log_info );

            $this->log( sprintf( 'deleting CRUD client files in %s', $this->crud_client_dir ),  __FILE__, __LINE__, self::log_debug );
            $files = glob( $this->crud_client_dir . '*.html');
            foreach( $files as $file ) {

                if( count( $this->tables ) ) {
                    $tname = explode( '.', $file);  $tname = $tname[0];
                    if( !@$this->tables[$tname] ) {
                        continue;
                    }
                }
                
                $rc = @unlink( $file );
                if( !$rc ) {
                    throw new Exception( "Cannot unlink old file " . $file );
                }
                $this->log( sprintf( 'deleted %s', $file ),  __FILE__, __LINE__, self::log_debug );
            }
            $this->log( sprintf( 'deleted CRUD client files in %s', $this->crud_server_dir ),  __FILE__, __LINE__, self::log_info );

            $tables = $this->get_tables();
            
            foreach( $tables as $table => $data ) {
                $this->write_table_crud( $table, $data );
            }

            $this->write_crud_php_config();
            $this->write_crud_test_harness();
            
            $this->log( sprintf( 'Successfully Generated DB Crud in %s', $this->crud_dir ),  __FILE__, __LINE__, self::log_warning );
            
            if( !$this->no_test_msg ) {
                $cmd = sprintf( "phpunit --colors %s", realpath( $this->crud_test_harness_dir . '/' . $this->crud_test_harness_filename ) );
                $this->log( sprintf( "\nYou may now run test cases be executing\n   %s", $cmd ),  __FILE__, __LINE__, self::log_warning );
            }
        }
        catch( Exception $e ) {
            $this->log( $e->getMessage(), $e->getFile(), $e->getLine(), self::log_error );
            return false;
        }
        return true;
    }
    
    function pdo_connect() {
        
        $dsn = null;
        $options = null;
        switch( $this->db_type ) {
           case 'postgres':
              $this->sys_quote_char = '"';
              $dsn = sprintf( 'pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s', $this->host, 5432, $this->db, $this->user, $this->pass );
              break;
           case 'mysql':
              $this->sys_quote_char = '`';
              $options = array( PDO::MYSQL_ATTR_INIT_COMMAND => 'set names utf8',
                                PDO::ATTR_TIMEOUT => 86400 * 365,    // 365 days = long time.
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                              );                  
              $dsn = sprintf( 'mysql:host=%s;dbname=%s;', $this->host, $this->db, $this->user, $this->pass );
              break;
           
        }
        
        // Connect to the database server
        $this->log( sprintf( 'Connecting to %s database. db = %s, host = %s, user = %s, pass = %s ', $this->db_type, $this->db, $this->host, $this->user, $this->pass ),  __FILE__, __LINE__, self::log_debug );
        $pdo = new PDO( $dsn, $this->user, $this->pass, $options );
        return $pdo;
    }
    
    function get_tables() {
        
        $pdo = $this->pdo_connect();
        $tables = array();

        switch( $this->db_type ) {
          case 'postgres':
             
             // Get all tables
             $dbname = $this->db;
             $result = $pdo->query("select table_name from INFORMATION_SCHEMA.TABLES where TABLE_CATALOG='$dbname' and table_schema = 'public'");
             foreach( $result as $row ) {
                 // Get table name
                 $table = $row['table_name'];
                 
                 if( count( $this->tables ) && !@$this->tables[$table] ) {
                     $this->log( sprintf( 'Found table %s.  Not in output list.  skipping', $table ),  __FILE__, __LINE__, self::log_info );
                     continue;
                 }
                 
                 // Get table info
                 $sort_clause = '';  // default is unsorted.
                 if( $this->sort_cols == 'keys_alpha' ) {
                     $sort_clause = 'order by Column_key desc, Column_Name asc';
                 }
                 else if( $this->sort_cols == 'alpha' ) {
                     $sort_clause = 'order by Column_Name asc';
                 }
                 $sql = "select Column_name as \"Field\", Data_Type as \"Type\", Is_Nullable as \"Null\", null as \"Key\", Column_Default as \"Default\", null as \"Extra\", null as \"Comment\" from INFORMATION_SCHEMA.COLUMNS where TABLE_CATALOG = '{$this->db}' and TABLE_NAME = '$table' $sort_clause";
                 $struct = $pdo->query( $sql, PDO::FETCH_ASSOC );

                 // get primary key list.
                $query = "SELECT  t.table_catalog,         t.table_schema,         t.table_name,         kcu.constraint_name,         kcu.column_name,         kcu.ordinal_position FROM    INFORMATION_SCHEMA.TABLES t         LEFT JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc                 ON tc.table_catalog = t.table_catalog                 AND tc.table_schema = t.table_schema                 AND tc.table_name = t.table_name                 AND tc.constraint_type = 'PRIMARY KEY'         LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu                 ON kcu.table_catalog = tc.table_catalog                 AND kcu.table_schema = tc.table_schema                 AND kcu.table_name = tc.table_name                 AND kcu.constraint_name = tc.constraint_name WHERE   t.table_schema NOT IN ('pg_catalog', 'information_schema') and t.table_name = '$table' ORDER BY t.table_catalog,         t.table_schema,         t.table_name,         kcu.constraint_name,         kcu.ordinal_position";
                $result2 = $pdo->query( $query );
                $col_pks = array();
                foreach( $result2 as $row ) {
                   $col_pks[$row['column_name']] = 1; 
                }
                
                $query = "SELECT c.column_name as col,pgd.description as comment FROM pg_catalog.pg_statio_all_tables as st inner join pg_catalog.pg_description pgd on (pgd.objoid=st.relid) inner join information_schema.columns c on (pgd.objsubid=c.ordinal_position and c.table_schema=st.schemaname and c.table_name=st.relname) where c.table_name = '$table'";
                $comment_rows = $pdo->query( $query, PDO::FETCH_OBJ );
                $comments = array();
                foreach( $comment_rows as $r ) {
                   $comments[$r->col] = $r->comment;
                }

                 $data = array();
                 foreach ($struct as $row2 ) {
                     if( @$col_pks[$row2['Field']] ) {
                        $row2['Key'] = 'PRI';  // emulate mysql method of marking primary key, that later code expects.
                     }

                     $colname = $row2['Field'];
                     $row2['Comment'] = @$comments[trim($row2['Field'])];
                     $data[$colname] = $row2;
                 }
                 

                $tables[$table] = $data;
             }             
             
             break;

          case 'mysql':
                                  
             // Get all tables
             $results = $pdo->query('SHOW TABLES', PDO::FETCH_NUM );
             foreach( $results as $row ) {
                 // Get table name
                 $table = $row[0]  ;
                 
                 if( count( $this->tables ) && !@$this->tables[$table] ) {
                     $this->log( sprintf( 'Found table %s.  Not in output list.  skipping', $table ),  __FILE__, __LINE__, self::log_info );
                     continue;
                 }
                 
                 // Get table info
                 $sort_clause = '';  // default is unsorted.
                 if( $this->sort_cols == 'keys_alpha' ) {
                     $sort_clause = 'order by Column_key desc, Column_Name asc';
                 }
                 else if( $this->sort_cols == 'alpha' ) {
                     $sort_clause = 'order by Column_Name asc';
                 }
                 $query = "select Column_name as Field, Column_Type as Type, Is_Nullable as `Null`, Column_Key as `Key`, Column_Default as `Default`, Extra, Column_Comment as Comment from INFORMATION_SCHEMA.COLUMNS where TABLE_SCHEMA = '{$this->db}' and TABLE_NAME = '$table' $sort_clause";

                 $struct = $pdo->query( $query, PDO::FETCH_ASSOC);

                 $data = array();
                 foreach( $struct as $row2 ) {
                     $colname = $row2['Field'];
                     $data[$colname] = $row2;
                 }
                 $tables[$table] = $data;
             }
             
             break;
        }
        return $tables;
    }
    
    function create_db_model() {
        
        try {
        
            $this->ensure_dir_exists( $this->output_dir );
            $this->ensure_dir_exists( $this->model_test_harness_dir );
            $this->ensure_dir_exists( $this->dto_dir );
            $this->ensure_dir_exists( $this->dto_base_dir );
            $this->ensure_dir_exists( $this->dto_custom_dir );
            $this->ensure_dir_exists( $this->dao_dir );
            $this->ensure_dir_exists( $this->dao_tests_dir );
            $this->ensure_dir_exists( $this->dao_criteria_dir );
            $this->ensure_dir_exists( $this->dao_criteria_tests_dir );
            $this->ensure_dir_exists( $this->dao_base_dir );
            $this->ensure_dir_exists( $this->dao_custom_dir );
            
            $this->log( sprintf( 'deleting DTO base files in %s', $this->dto_base_dir ),  __FILE__, __LINE__, self::log_debug );
            $files = glob( $this->dto_base_dir . '*.dto.base.php');
            foreach( $files as $file ) {
                
                if( count( $this->tables ) ) {
                    $tname = explode( '.', $file);  $tname = $tname[0];
                    if( !@$this->tables[$tname] ) {
                        continue;
                    }
                }
                
                $rc = @unlink( $file );
                if( !$rc ) {
                    throw new Exception( "Cannot unlink old file " . $file );
                }
                $this->log( sprintf( 'deleted %s', $file ),  __FILE__, __LINE__, self::log_debug );
            }
            $this->log( sprintf( 'deleted DTO base files in %s', $this->dto_base_dir ),  __FILE__, __LINE__, self::log_info );


            $this->log( sprintf( 'deleting DAO base files in %s', $this->dao_base_dir ),  __FILE__, __LINE__, self::log_debug );
            $files = glob( $this->dao_base_dir . '*.dao.base.php');
            foreach( $files as $file ) {

                if( count( $this->tables ) ) {
                    $tname = explode( '.', $file);  $tname = $tname[0];
                    if( !@$this->tables[$tname] ) {
                        continue;
                    }
                }

                $rc = unlink( $file );
                if( !$rc ) {
                    throw new Exception( "Cannot unlink old file " . $file );
                }
                $this->log( sprintf( 'deleted %s', $file ),  __FILE__, __LINE__, self::log_debug );
            }
            $this->log( sprintf( 'deleted DAO base files in %s', $this->dao_base_dir ),  __FILE__, __LINE__, self::log_info );

            // Remove dbmodel test files
            $this->log( 'deleting db_model test files',  __FILE__, __LINE__, self::log_debug );
            $files1 = glob( $this->dao_tests_dir . '/*.phpunit.php');
            $files2 = glob( $this->dao_criteria_tests_dir . '/*.phpunit.php');
            $files = array_merge( $files1, $files2 );
            foreach( $files as $file ) {
                $rc = @unlink( $file );
                if( !$rc ) {
                    throw new Exception( "Cannot unlink old file " . $file );
                }
                $this->log( sprintf( 'deleted %s', $file ),  __FILE__, __LINE__, self::log_debug );
            }
            $this->log( 'deleted db_model test files',  __FILE__, __LINE__, self::log_info );
            
            $tables = $this->get_tables();
    
            if (count($tables)) {
                $this->write_dao_base_file();
                $this->write_db_model_factory_file();
                $this->write_db_factory_file();
                $this->write_dao_criteria_class_file();
                $this->write_dao_criteria_class_test_file();
                $this->write_dbmodel_test_harness();
                
                $this->log( 'Writing table access classes.',  __FILE__, __LINE__, self::log_info );
                
                $tables = $this->get_tables();
                foreach( $tables as $table => $data ) {
                    $this->write_table( $table, $data );                    
                }

                $this->log( sprintf( 'Successfully Generated DB Model in %s', $this->output_dir ),  __FILE__, __LINE__, self::log_warning );
            }
        }
        catch( Exception $e ) {
            $this->log( $e->getMessage(), $e->getFile(), $e->getLine(), self::log_error );
            return false;
        }
        return true;
    }
    
    function log( $msg, $file, $line, $level ) {
        if( in_array( $level, array(self::log_error, self::log_debug)) && $level <= $this->verbosity ) {
            fprintf( $this->stdout, "%s  -- %s : %s\n", $msg, $file, $line );            
        }
        else if( $level <= $this->verbosity ) {
            fprintf( $this->stdout, "%s\n", $msg );
        }
    }
    
    function ensure_dir_exists( $path ) {
        $this->log( sprintf( 'checking if path exists: %s', $path ), __FILE__, __LINE__, self::log_debug );
        if( !is_dir( $path )) {
            $this->log( sprintf( 'path does not exist.  creating: %s', $path ), __FILE__, __LINE__, self::log_debug );
            $rc = @mkdir( $path );
            if( !$rc ) {
                throw new Exception( "Cannot mkdir " . $path );
            }
            $this->log( sprintf( 'path created: %s', $path ), __FILE__, __LINE__, self::log_info );
        }
    }
    
    function write_table( $table, $info ) {
        
            $this->log( sprintf( "Processing table %s", $table ),  __FILE__, __LINE__, self::log_info );
        
            $this->write_dto_file( $table, $info );
            $this->write_dao_file( $table, $info );
            $this->write_dao_test_file( $table, $info );
             
    }

    function write_table_crud( $table, $info ) {
        
            $this->log( sprintf( "Generating CRUD for table %s", $table ),  __FILE__, __LINE__, self::log_info );
        
            $this->write_crud_html( $table, $info );
            $this->write_crud_php_processor( $table, $info );
            $this->write_crud_php_controller( $table, $info );
    }

    function write_db_factory_file() {

      $mask =
'<?php

/**
 * USER-MODIFIABLE SETUP FILE
 *
 * This file is automatically generated by db_extractor
 * if not already existing, but ignored if already existing.
 * Thus your changes will be preserved.
 *
 */

/**
 * This class is responsible for two things:
 *
 *  1) Obtain (or define) the configuration needed for db_model layer.
 *  2) Provide access to a fully configured %1$sdbModelFactory
 *
 * The auto generated class is a skeleton only.  It will throw
 * exceptions until you define all the required variables
 * and/or implement the accessor methods to suit your needs.
 *
 */
class %1$sdbFactory {

    /*
     * Modify these variables directly according to your environment.
     * Alternatively, you can modify the accessor methods in order to
     * do more complicated things such as reading from a separate
     * config file, pre-loaded config class, etc.
     */
    
    /**
     * Database server hostname. required.
     */
    static protected $dbHost = \'%2$s\';
    
    /**
     * Database name. required.
     */
    static protected $dbName = \'%3$s\';
    
    /**
     * Database username. required.
     */   
    static protected $dbUser = \'%4$s\';
    
    /**
     * Database password. required.
     */   
    static protected $dbPass = \'%5$s\';

    /**
     * path to db_model directory
     */
    static protected $dbModelPath;
    
    /**
     * Path to phpUnit installation.  optional.
     * If present and valid, phpUnit will be used for unit tests.
     */
    static protected $phpUnitPath = \'\';
    
    /**
     * Path to firePHP installation.  optional.
     * If present and valid, firePHP will be used to send debugging
     * info to fireBug
     *
     * If set to empty string or null, standard PEAR paths will be checked.
     * If you do not want that, set firePHPPAth to false.
     */
    static protected $firePHPPath = \'\';
    
    /**
     * Retrieves database server hostname. 
     */
    static public function dbHost() {
        if( !self::$dbHost ) { throw new Exception( "dbHost undefined in CrudConf" ); }
        return self::$dbHost;
    }
    
    /**
     * Retrieves database name.
     */
    static public function dbName() {
        if( !self::$dbName ) { throw new Exception( "dbName undefined in CrudConf" ); }
        return self::$dbName;
    }
    
    /**
     * Retrieves database user.
     */
    static public function dbUser() {
        if( !self::$dbUser ) { throw new Exception( "dbUser undefined in CrudConf" ); }
        return self::$dbUser;
    }
    
    /**
     * Retrieves database password
     */
    static public function dbPass() {
        return self::$dbPass;
    }
    
    /**
     * Retrieves db_model path
     */
    static public function dbModelPath() {
        if( !self::$dbModelPath ) { 
            self::$dbModelPath = dirname(__FILE__) . \'/%6$s\';
        }
        
        if( !self::$dbModelPath ) { throw new Exception( "dbModelPath undefined in CrudConf" ); }
        return self::$dbModelPath;
    }
    
    /**
     * Retrieves phpUnit path
     */
    static public function phpUnitPath() {
         if( !self::$phpUnitPath ) {
            $pear_path = \'/usr/share/php/PHPUnit/\';
            if( is_dir( $pear_path ) ) {
                self::$phpUnitPath = $pear_path;
            }
         }
         return self::$phpUnitPath;
    }    
    
    /**
     * Retrieves firePHP path.
     *
     * Return false if you do  not wish to use FirePHP.
     */
    static public function firePHPPath() {
         if( PHP_SAPI == "cli" ) {
            return false;  // never want to use firePHP for CLI.
         }
         if( !self::$firePHPPath && self::$firePHPPath !== false ) {
            $pear_path = \'/usr/share/php/FirePHPCore/\';   // Ubuntu location
            if( is_dir( $pear_path ) ) {
                self::$firePHPPath = $pear_path;
            }
            else {
                $pear_path = \'/usr/share/PEAR/FirePHPCore/\';  // CentOS location
                if( is_dir( $pear_path ) ) {
                    self::$firePHPPath = $pear_path;
                }
            }
         }
         return self::$firePHPPath;
    }


    /**
     * Instantiates and returns a ready to use %1$sdbModelFactory
     */
    static public function dsn() {
    
        return sprintf( "%7$s:dbname=%%s;host=%%s", self::dbName(), self::dbHost() );
    
    }

    
    /**
     * Instantiates and returns a ready to use %1$sdbModelFactory
     */
    static public function dbModelFactory() {
        require_once( self::dbModelPath() . \'/%1$sdbModelFactory.php\' );
        
        if( self::firePHPPath() ) {
            require_once( self::firePHPPath() . \'/fb.php\' );
        }
        
        $pdo = new PDO( self::dsn(), self::dbUser(), self::dbPass() );
        return new %1$sdbModelFactory( $pdo );
    }

    /**
     * returns an existing dbmf if available, or instantiates.
     */
    static public function dbmf() {
        static $dbmf = null;
        if( $dbmf ) {
            return $dbmf;
        }
        
        $dbmf = self::dbModelFactory();
        return $dbmf;
    }
    
    
}

';

      $dsn_db_type = $this->db_type;
      switch( $this->db_type ) {
        case 'postgres': $dsn_db_type = 'pgsql'; break;
      }

      $buf = sprintf( $mask, $this->namespace_prefix, $this->host, $this->db, $this->user, $this->pass, '', $dsn_db_type );

      $filename_db_factory_php = $this->db_factory_filename();
      $pathname_db_factory_php = $this->output_dir . '/' . $filename_db_factory_php;

      if( file_exists( $pathname_db_factory_php ) ) {
         $this->log( sprintf( "Ignoring existing modifiable file %s\n", $pathname_db_factory_php ),  __FILE__, __LINE__, self::log_warning );
         return;
      }
      
      $this->log( sprintf( "Writing user-modifiable setup file %s", $pathname_db_factory_php ),  __FILE__, __LINE__, self::log_info );
      $rc = @file_put_contents( $pathname_db_factory_php, $buf );
      if( !$rc ) {
          throw new Exception( "Error writing file " . $pathname_db_factory_php );
      }
    }
    
    
    
    function write_db_model_factory_file( ) {
$buf = '<?php

/**
 * db_extractor factory class for all db_model classes.
 *
 * This class provides an easy and efficient way to access dao and dto objects
 *
 * !!! DO NOT MODIFY THIS FILE !!!
 *
 * This is an automatically [re-]generated file.
 * Changes will NOT be preserved.
 *
 * If desired, your application can extend from this class
 * and modify that file instead.
 *
 * For convenience, you may wish to create a static class that contains
 * a single reference to a dbModelFactory instance and wrappers these methods
 *
 */

class %4$s {
    protected $daoCache = array();
    protected $pdo = null;
    protected $dao_query_logfile = null;
    protected $dao_query_logfile_minlog = 2;  // log warnings + exceptions by default.
    
    public function __construct(PDO $pdo) {
        if( !$pdo ) {
            throw new Exception(\'PDO instance is required.\');
        }
        $this->pdo = $pdo;
        
        // we want PDO to throw exceptions.
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Sets the query logfile path used by DAO instances.
     *
     * Set $filepath to null to disable query logging.
     * may also specify STDOUT or STDERR
     */
    public function set_dao_query_logfile( $filepath ) {
      $this->dao_query_logfile = $filepath;
    }

    /**
     * Retrieves the query logfile path used by DAO instances.
     */
    public function get_dao_query_logfile() {
      return $this->dao_query_logfile;
    }
    
    /**
     * Sets the minlog level used by DAO instances.
     */
    public function set_dao_query_logfile_minlog( $level ) {
      $this->dao_query_logfile_minlog = self::minlog_int( $level );
    }

    /**
     * Get integer value corresponding to minlog string identifier.
     */
    static public function minlog_int( $str ) {
      if( is_int( $str ) ) {
         return $str;
      }
      switch( $str ) {
         case "query": return 1;
         case "warning": return 2;
         case "exception": return 3;
      }
      return null;
    }

    /**
     * Retrieves the minlog used by DAO instances.
     */
    public function get_dao_query_logfile_minlog() {
      return $this->dao_query_logfile_minlog;
    }

    
    /**
     *  Returns a new or existing instance of a dao object for a single table.
     *
     *  Dao (data access object) objects handle insert, update, delete, select queries for each table.
     *  They are code generated.
     *
     *  @param string $table  name of table
     *  @return object dao corressponding to table.
     */
    public function daoInstance($table) {
        $key = $this->getKey( $table );
        if( isset( $this->daoCache[$key] ) ) {
            return $this->daoCache[$key];
        }
        
        $class = $key . \'_dao\';
        $filename = dirname(__FILE__) . \'/dao/\' . $key . \'.dao.php\';
        require_once( $filename );
        return $this->daoCache[$key] = new $class( $this );
    }

    /**
     *  Returns a new or existing instance of a custom dao object
     *
     *  custom Dao (data access object) objects handle custom/advanced
     *  operations beyond basic CRUD for a single table.  They are
     *  never code generated.
     *
     *  @param string $name  name of custom dao
     *  @return object custom dao corresponding to $name
     */
    public function daoCustomInstance($name) {
        $key = $this->getKey( $name );
        if( isset( $this->daoCache[$key] ) ) {
            return $this->daoCache[$key];
        }
        
        $class = $key . \'_custom_dao\';
        $filename = dirname(__FILE__) . \'/dao/custom/\' . $key . \'.custom.dao.php\';
        require_once( $filename );
        return $this->daoCache[$key] = new $class( $this );
    }

    /**
     *  Returns a new daoCriteria object
     *
     *  daoCriteria objects provide a code-based mechanism for specifying SQL where-clause conditions.
     *
     *  @return daoCriteria
     */
    public function daoCriteriaNew() {
        require_once( dirname(__FILE__) . \'/dao/criteria/\' . \'%2$s\' );
    
        return new %3$s( $this->pdo );
    }


    /**
     *  Returns a new dto object for a single table.
     *
     *  DTO (data transfer object) objects represent a single row in a db table, with an attribute for each column.
     *  They are code generated.
     *
     *  @param string $table.  name of table
     *  @return object dto object corresponding to table.
     */  
    public function dtoNew($table) {
        $key = $this->getKey( $table );
        $class = $key . \'_dto\';
        $filename = dirname(__FILE__) . \'/dto/\' . $key . \'.dto.php\';
        require_once( $filename );
        return new $class();
    }

    /**
     *  Returns a new dto object for a custom query
     *
     *  custom DTO (data transfer object) objects represent a single row in a custom query result
     *  with an attribute for each column.  They are never code generated.
     *
     *  @param string $name.  name of custom DTO
     *  @return object dto object
     */  
    public function dtoCustomNew($name) {
        $key = $this->getKey( $name );
        $class = $key . \'_custom_dto\';
        $filename = dirname(__FILE__) . \'/dto/custom/\' . $key . \'.custom.dto.php\';
        require_once( $filename );
        return new $class();
    }
    
    private function getKey($id) {
        return \'%1$s\' . $id;
    }
    
    /**
     * change to another PDO (db conn).
     *
     * @param $pdo a previously instantiated PDO object.
     */
    public function setPDO( PDO $pdo ) {
        if( !$pdo ) {
            throw new Exception( "pdo cannot be null" );
        }
        $this->pdo = null;  // unset/close prev connection, if any.  possibly redundant.
        $this->pdo = $pdo;
    }

    /**
     * get PDO
     *
     * @return PDO database object.
     */
    public function getPDO() {
        return $this->pdo;
    }
    
    
}


';
        $buf = sprintf( $buf, $this->namespace_prefix, $this->dao_criteria_filename(), $this->dao_criteria_classname(), $this->db_model_factory_classname() );

        $filename = $this->db_factory_class_file;
        $this->log( sprintf( "Writing %s", $filename ),  __FILE__, __LINE__, self::log_info );
        $rc = file_put_contents( $filename, $buf );
        if( !$rc ) {
            throw new Exception( "Error writing file " . $filename );
        }
    }
    
    
    function write_dao_base_file( ) {
$buf = '<?php

require_once( dirname(__FILE__) . \'/../../%1$sdbModelFactory.php\' );

class %1$sDaoSqlException extends Exception {
   protected $driver_code;
   
   function __construct( $msg = null, $code = null, $driver_code = null, Exception $e = null ) {
      parent::__construct( $msg, null, $e );
      
      // Set code ourselves.  Because SQL code may  be a string, but our parent
      // constructor throws fatal error if it is not an int.
      $this->code = $code;
      $this->driver_code = $driver_code;
   }
   
   public function getDriverCode() {
      return $this->driver_code;
   }
}

/**
 * db_extractor base class for all Data Access Objects.  (DAO)
 *
 * !!! DO NOT MODIFY !!!
 *
 * This is an automatically [re-]generated file.
 * Changes will NOT be preserved.
 *
 * DAO architecture, from lowest to highest looks like:
 *
 *  0.  DB itself.
 *  1.  PDO.  PHP Data Object.  A single class connection manager.  http://php.net/PDO
 *  2.  daoBase = DAO Base Class    <-- this class
 *  3.  <Table>_dao_base   = code generated class for each table, inherits from daoBase
 *  4.  <Table>_dao        = user editable class for each table, inherits from <Table>_dao_base
 */
abstract class %sdaoBase {
    
    protected $dmf;  // reference to %1$sdbModelFactory instance
    protected $pdo;  // PDO instance, initially from %1$sdbModelFactory
    protected $fetch_style = null;
    protected $transaction_mode = null;
    protected $timestamptz_as_epoch = true;  // return postgres timestamptz columns in epoch format
    protected $timestamp_as_epoch = true;  // return mysql/postgres timestamp columns in epoch format
    protected $last_query = \'\';
    
    const DBNULL = \'***DBNULL***\';
    
    // Log levels.
    const log_query = 1;
    const log_warning = 2;
    const log_exception = 3;

    /**
     * constructor
     *
     * @param $dmf a previously instantiated %1$sdbModelFactory object.
     *
     * @note: defaults to serializable read/write transactions.
     *        call ::set_transaction_mode() if other behavior desired.
     */
    function __construct( %1$sdbModelFactory $dmf ) {
        $this->dmf = $dmf;
        $this->pdo = $dmf->getPDO();
        
        // We need to store the transaction nesting level with the connection
        // not with this particular DAO because multiple DAOs may be used by
        // each transaction.  However, the tx *are* tied to a single connection.
        // The PDO represents the connection, so we store it there.
        // ( Kind of messy, but it works )
        if( !isset( $this->pdo->trans_level ) ) {
            $this->pdo->trans_level = 0;
        }
        
        $this->set_transaction_mode_serial_readwrite();
    }

    /**
     * get dbModelFactory
     *
     * @return %1$sdbModelFactory database object.
     */
    public function getDbModelFactory() {
        return $this->dmf;
    }

    /**
     * get PDO currently in use by this DAO
     *
     * @return PDO database object.
     */
    function getPDO() {
        return $this->pdo;
    }


    /**
     * set PDO to be used by this DAO
     *
     * @return PDO database object.
     */
    function setPDO( PDO $pdo ) {
        $this->pdo = $pdo;
    }


    /**
     * Execute a query, and optionally log it.
     *
     * @return PDO database object.
     */    
    protected function querypdo( $sql ) {
    
      $this->_log( $sql, self::log_query );
      
      $num_tries = 1;
      $max_tries = PHP_INT_MAX;  // todo: make configurable.

      // If we are not already in a transaction then we will retry queries
      // that give deadlock or lockwait timeout errors.  If in a transaction
      // then the transaction handler should rollback/retry entire transaction.
      //
      // This retry logic enables us to support serializable transaction isolation level.
      
      while( $num_tries++ < $max_tries ) {
         try {
            $tstart = microtime(true);
            $result = $this->pdo->query( $sql );
            $this->last_query = $sql;
            
            $tend = microtime(true);
            $buf = sprintf( "Query start: %%17f, end: %%17f, took: %%s", $tstart, $tend, $tend - $tstart );
            $this->_log( $buf, self::log_query );
      
            if( $result === false ) {
               list($sqlstate_code, $driver_code, $driver_msg) = $this->pdo->errorInfo();
               $msg = sprintf( "database error. sqlstate code: %%s.  driver code: %%s.\n\nQuery was: %%s\n", $sqlstate_code, $driver_code, $driver_msg, $sql );
               $this->_log( $msg, self::log_warning );
               if( $this->pdo->trans_level > 0 || !$this->should_retry( $sqlstate_code, $driver_code ) ) {
                  throw new %1$sDaoSqlException( $msg, $sqlstate_code, $driver_code );
               }
               usleep(10000);  // give system a little rest.
               continue;
            }
            break;            
         }
         catch( PDOException $e) {
            $msg = sprintf( "Caught PDO Exception.  Code: %%s.  %%s\nin %%s:%%s\n\nQuery was: %%s", $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine(), $sql );
            $logmsg = $msg . sprintf("\n\nTrace:\n%%s", $e->getTraceAsString() );
            $this->_log( $logmsg, self::log_exception );
            
            list($sqlstate_code, $driver_code, $driver_msg) = $e->errorInfo;
            if( $this->pdo->trans_level > 0 || !$this->should_retry( $sqlstate_code, $driver_code ) ) {
               throw new %1$sDaoSqlException( $msg, $sqlstate_code, $driver_code, $e );
            }
            usleep(10000);  // give system a little rest.
         }
      }
      
      return $result;
    }
    
    /**
     * This method is for executing arbitrary queries.
     *
     * It returns the results as an array of rows, where each row is a
     *   stdClass object, or an object of supplied $type.
     *
     * The returned array may be empty but will never be null.
     */
    public function query( $query, $type = "stdClass" ) {
      $stmt = $this->querypdo( $query );
      if( !$stmt ) {
         throw new Exception( "PDO::query() did not return a PDOStatement where one is expected." );
      }
      if( $stmt->columnCount() == 0 ) {
         return array();
      }
      return $stmt->fetchAll( PDO::FETCH_CLASS, $type );
    }

    /**
     * Perform query and retrieves one result row.
     * The result is an object of type stdClass.
     * throws exception if query returns any other number of results, or none.
     */
     public function query_one(  $query ) {
        $results = $this->query( $query );
        if( count( $results) != 1 ) {
            throw new Exception( sprintf( "Got %%s results from query. Expected one.", count( $results ) ) );
        }
        return array_pop($results);
    }
    
    /**
     * Perform query and retrieve one or zero results.
     * The result is an object of type stdClass or null.
     * throws exception if query returns any other number of results.
     */
     public function query_one_or_none(  $query ) {
        $results = $this->query( $query );

        if( count( $results) > 1 ) {
            throw new Exception( sprintf( "Got %%s results from query. Expected one or none.", count( $results ) ) );
        }
        return @array_pop( $results );
    }

    /**
     * Perform query and retrieves one result scalar, or null.
     * The result is a scalar string/int/float.
     * throws exception if query returns any other number of results.
     */
     public function query_one_scalar(  $query ) {
        $row = $this->query_one( $query );
        $row = get_object_vars( $row );
        if( @count( $row ) != 1 ) {
            throw new Exception( "Expected a single column row" );
        }
        return array_pop( $row );
    }    
    
    /**
     * Perform query and retrieves one result scalar, or null.
     * The result is a scalar string/int/float.
     * throws exception if query returns any other number of results.
     */
     public function query_one_or_none_scalar( $query ) {
        $row = $this->query_one_or_none( $query );
    
        if( $row ) {
            $row = get_object_vars( $row );
            if( @count( $row ) != 1 ) {
                throw new Exception( "Expected a single column row" );
            }
        }
        return @array_pop( $row );
    }

    /**
     * returns the last executed SQL query.
     */
    public function last_query() {
        return $this->last_query;
    }
    
    function _log( $msg, $level = self::log_query ) {
      $logfile = $this->dmf->get_dao_query_logfile();
      $minlog = $this->dmf->get_dao_query_logfile_minlog();
      if( !$logfile || $level < $minlog ) {
         return;
      }
      
      $pid = getmypid();
      $logline = date("c") . " (PID: $pid) == " . $msg . "\n";
      
      if( $logfile == STDOUT || $logfile == STDERR ) {
         fwrite( $logfile, $logline );
      }
      else {
         $fh = fopen( $logfile, "a" );
         if( $fh ) {
            fwrite( $fh, $logline );
            fclose( $fh );
         }
      }
    }

    protected function server_version() {
      return $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    }    

    protected function server_version_num() {
      return (double)$this->server_version();
    }    
    
    protected function nestable() {
      return in_array($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                      array("pgsql", "mysql" ));
    }
    
    public function set_transaction_mode( $isolation_level, $is_write, $deferrable ) {
      $this->transaction_mode = array( "isolation_level" => $isolation_level,
                                       "is_write" => $is_write,
                                       "deferrable" => $deferrable );
    }

    public function set_transaction_mode_serial_readonly( $deferrable = true ) {
      $this->set_transaction_mode( "SERIALIZABLE", false, $deferrable );
    }

    public function set_transaction_mode_serial_readwrite() {
      // note: deferrable only applies to read-only transactions.
      $this->set_transaction_mode( "SERIALIZABLE", true, $deferrable = false );
    }
    
    protected function begin_transaction() {
      $trans_mode_buf = null;
      if($this->pdo->trans_level == 0 || !$this->nestable()) {
         $buf = "START TRANSACTION";
      }
      else {
         $buf = "SAVEPOINT LEVEL{$this->pdo->trans_level};";
      }
      
      $need_second_query = false;
      $level_query = null;
      if( is_array($this->transaction_mode) && $this->pdo->trans_level == 0 ) {
      
         $il = $this->transaction_mode["isolation_level"];
         $iw = "";
         $d = "";

         $iw = $this->transaction_mode["is_write"] ? "READ WRITE" : "READ ONLY";
         
         if( "%3$s" == "mysql" ) {
            // mysql does not support READ WRITE/ONLY Attrs until version 5.6
            // mysql requires a preceding comma in SET TRANSACTION
            $iw = $this->server_version_num() >= 5.6 ? (", " . $iw) : "";
         }
         
         if( "%3$s" == "postgres" ) {
            $d = $this->transaction_mode["deferrable"] ? "DEFERRABLE" : "";
         }
         
         $level_buf = "ISOLATION LEVEL $il $iw $d";
         $level_query = "set transaction $level_buf";
         
         // optimization: avoid 2nd query if possible.
         if( "%3$s" == "postgres" ) {
            $buf .= " " . $level_buf; 
         }
         else {
            $need_second_query = true;
         }

      }
      if( "%3$s" == "mysql" ) {
         // in mysql SET TRANSACTION must be called before starting the transaction.
         if( $need_second_query ) {
            $this->query( $level_query );
         }
         $this->query( $buf );
      }
      else {
         // in postgres SET TRANSACTION must be called as very first statement of the transaction.
         $this->query( $buf );
         if( $need_second_query ) {
            $this->query( $level_query );
         }
      }
      $this->pdo->trans_level++;
    }
    
    protected function commit() {
      if( $this->pdo->trans_level > 0 ) {
         $this->pdo->trans_level --;
      }
      
      if($this->pdo->trans_level == 0 || !$this->nestable()) {
         $this->query( "COMMIT" );
      }
      else {
         $this->query("RELEASE SAVEPOINT LEVEL{$this->pdo->trans_level}");
      }      
    }
    
    protected function rollback() {
      if( $this->pdo->trans_level > 0 ) {
         $this->pdo->trans_level --;
      }
      
      if($this->pdo->trans_level == 0 || !$this->nestable()) {
         $this->_log( "Rolling back", self::log_warning );
         $this->query( "ROLLBACK" );
      } else {
         $this->_log( "Rolling back to SAVEPOINT LEVEL{$this->pdo->trans_level}", self::log_warning );
         $this->query("ROLLBACK TO SAVEPOINT LEVEL{$this->pdo->trans_level}");
      }
    }
    

   /**
    * Executes a function in a serializable transaction, even if the
    * transaction level is not presently set to serializable.
    *
    * Using this method makes it obvious in the calling code that a given
    * transaction will be serializable.
    *
    * The function gets passed this DAO instance as an (optional) parameter.
    
    * If an exception occurs during execution of the function or transaction commit,
    * the transaction is rolled back.  If the exception was caused by a serialization
    * error, the transaction is retried, up to a max limit of tries.
    *
    * @param callable $func The function to execute transactionally.
    *
    * This method accepts variable argument list.  The first argument to
    * your function will be this DAO object ( $this ).  Any additional arguments
    * you pass will be forwarded to your function.
    * 
    */
   public function serializable( $func )
   {
      $old_mode = $this->transaction_mode;
      $this->set_transaction_mode_serial_readwrite();
      
      $func = array( $this, "transactional" );
      $rc = call_user_func_array( $func, func_get_args() );
      
      $this->transaction_mode = $old_mode;  // restore original trans mode.      
      return $rc;    
   }

   public function serializable_readonly( $func )
   {
      $old_mode = $this->transaction_mode;
      $this->set_transaction_mode_serial_readonly( $deferrable = true );
      
      $func = array( $this, "transactional" );
      $rc = call_user_func_array( $func, func_get_args() );
      
      $this->transaction_mode = $old_mode;  // restore original trans mode.      
      return $rc;    
   }   
   
   /**
    * Executes a function in a serializable transaction.
    *
    * The function gets passed this DAO instance as an (optional) parameter.
    *
    * If an exception occurs during execution of the function or transaction commit,
    * the transaction is rolled back.  If the exception was caused by a serialization
    * error, the transaction is retried, up to a max limit of tries.
    *
    * This method does not itself set the transaction serializable.  It just handles
    * the retry logic.  To do that, you can issue a statement, or some DB
    * support setting a default transaction isolation level for the session
    * or the database.
    *
    * @param callable $func The function to execute transactionally.
    *
    * This method accepts variable argument list.  The first argument to
    * your function will be this DAO object ( $this ).  Any additional arguments
    * you pass will be forwarded to your function.
    */
   public function transactional($func)
   {
      $in_arg_list = func_get_args();
      $out_arg_list = array( $this );  // pass this dao object as first param.
      
      if( count($in_arg_list) > 1 ) {
         $out_arg_list = array_merge( $out_arg_list, array_slice( $in_arg_list, 1, count( $in_arg_list ) - 1 ) );
      }
      
      $max_tries = null;  // retry indefinitely.  todo: make configurable.
   
      $num_try = 1;
      while( !$max_tries || $num_try <= $max_tries ) {
         if( $num_try > 1 ) {
            $this->_log( "Retrying transaction.  Try #$num_try", self::log_warning );
         }
         
         $this->begin_transaction();
         try {
            $result = call_user_func_array( $func, $out_arg_list);
            $this->commit();
            return $result;
         }
         catch (%1$sDaoSqlException $e) {
            $this->_log( sprintf( "Caught %1$sDaoSqlException in daoBase::serializable(). code is %%s.  message is: %%s", $e->getCode(), $e->getMessage() ), self::log_exception );
            $this->rollback();
            
            if( !$this->should_retry( $e->getCode(), $e->getDriverCode() ) ) {
               $this->_log( "Rethrowing", self::log_exception );
               throw $e;
            }
         }
         catch (Exception $e) {
            $this->_log( sprintf( "Caught Exception in daoBase::serializable(). code is %%s. Rethrowing.", $e->getCode() ), self::log_exception ); 
            $this->rollback();
            throw $e;
         }
         
         usleep(10000);  // give system a little rest.
         $num_try++;
      }
    }
    
    /**
     * returns true if transaction/query should be retried based on db error code.
     */
    protected function should_retry( $sqlstate_code, $driver_code ) {
    
         // In postgresql (and apparently also mysql), 40001 indicates transaction failed because it
         // could not be serialized, retry necessary.
         static $sqlstate_retry_codes = array(40001, "40001", "40P01" );

         // in mysql:
         //   1205 = ER_LOCK_WAIT_TIMEOUT. "Lock wait timeout exceeded; try restarting transaction"
         //   1213 = ER_LOCK_DEADLOCK
         //   
         static $driver_retry_codes = array( 1205, 1213 );
         
         return in_array( $sqlstate_code, $sqlstate_retry_codes ) ||
                in_array( $driver_code, $driver_retry_codes );      
    }
    

    /**
     * generates column list for use in select statement, with proper escaping.
     * When an array is passed in, all column names will be escaped.
     * When a string is passed in, it will be returned unmodified. This allows
     * for "raw" unescaped expressions to be specified by the caller.
     *
     * Intended for use by *_dao_base sub-classes.
     *
     * @param mixed $cols.  Array of columns, or a single string that will not be modified.
     *
     * @return string The X,Y,Z portion of "select (X,Y,Z) from A".
     */
    protected function gen_select_col_buf( $cols ) {
        $col_buf = $cols;
        if(is_array($cols) && count($cols)) {
            
            $col_buf = \'\';
            $cnt = 0;
            foreach($cols as $k => $col) {
               $raw_expr = null;
               if( !is_numeric( $k ) ) {
                  $raw_expr = $k;
               }
                if( $cnt++ > 0 ) {
                    $col_buf .= \', \';
                }
                if( $this->timestamptz_as_epoch && @$this->field_types[$col] == \'timestamp with time zone\' ) {
                    // postgres specific hack to return epoch time from timestamptz
                    $col_buf .= "extract( epoch from \%2$s$col\%2$s ) as \%2$s$col\%2$s";
                }
                if( $this->timestamp_as_epoch &&
                     ( @$this->field_types[$col] == \'timestamp\' ||
                       @$this->field_types[$col] == \'datetime\' ) ) {
                    if( "%3$s" == "postgres" ) {
                       // postgres specific hack to return epoch time from timestamp
                       $col_buf .= "extract( epoch from \%2$s$col\%2$s ) as \%2$s$col\%2$s";
                    }
                    if( "%3$s" == "mysql" ) {
                       // postgres specific hack to return epoch time from timestamp
                       $col_buf .= "unix_timestamp( \%2$s$col\%2$s ) as \%2$s$col\%2$s";
                    }
                    
                }
                else {
                    if( $raw_expr ) {
                        $col_buf .= $raw_expr . " as " . "\\%2$s" . $col . "\\%2$s";
                    }
                    else {
                        $col_buf .= "\\%2$s" . $col . "\\%2$s";
                    }
                }
            }
        }
        return $col_buf;
    }

    /**
     * Set how rows are retrieved when fetch_dto() is called.
     *
     * @param string $style any PDO::* style, or null to return a DTO object.
     *
     * @return null
     */
    public function set_fetch_style($style) {
       $this->fetch_style = $style;
    }
    
    
    /**
     * Returns a single dto object given a PDOStatement
     *
     * Should be implemented by *_dao_base sub-classes
     * to return a suitable DTO object according to
     * fetch_type pref.
     *
     * @param PDOStatement $result.  
     *
     * @return DTO object corresponding to table/view.
     */
    protected function fetch_dto(PDOStatement $result) {
       return $result->fetchObject(  );
    }

    /**
     * Returns an array of dto objects given a PDOStatement
     *
     * Intended for use by *_dao_base sub-classes.
     *
     * @param PDOStatement $result.  
     *
     * @return array of DTO objects corresponding to table/view.
     */
    protected function fetch_all_dto(PDOStatement $result) {
        $rows = array();
        while( $dto = $this->fetch_dto( $result ) ) {
            $rows[] = $dto;
        }
        return $rows;
    }

    
    static public function escape( $value, $pdo, $sql_type = null, $depth = 0 )
    {
        // Prevent infinite recursion.
        if( ++ $depth > 10 ) return \'\';
        
        // Recursively escape arrays.
        if( is_array( $value ) ) {
            foreach( $value as &$val ) $val = $pdo->escape( $val, $pdo, $sql_type, $depth );
            return $value;
        }

        if( $value === \'CURRENT_TIMESTAMP\' || $value === \'CURRENT_DATE\' || $value === \'CURRENT_TIME\' ) {
            return $value;
        }
        if( $sql_type ) {
            if( stristr( $sql_type, \'double\' ) || stristr( $sql_type, \'int\' )
               && is_numeric( $value ) ) {   // is_numeric() is important to avoid SQL injection.
                return $value;
            }
            else if( is_numeric($value) &&
                       ( stristr( $sql_type , \'timestamp\' ) ||
                         stristr( $sql_type , \'datetime\' ) ) ) {
                if( "%3$s" == "postgres" ) {
                   return "to_timestamp($value)";  // simplify epoch conversion in postgres
                }
                if( "%3$s" == "mysql" ) {
                   return "from_unixtime($value)";  // simplify epoch conversion in mysql
                }                
            }
            else if( $sql_type == \'NULL\' ) {
                return \'NULL\';
            }
            else if( stristr($sql_type, \'bool\') ) {
                return $value ? \'TRUE\' : \'FALSE\';
            }
            return $pdo->quote($value);
        }
        if(is_numeric( $value ) ) return $pdo->quote( $value );   // numeric values could be uuid or other type that requires quoting.
        if(is_bool( $value ) ) return $value ? \'TRUE\' : \'FALSE\';
        if( $value === null || $value == self::DBNULL ) return \'NULL\';
                
        return $pdo->quote($value);
    }
    
}
';

        $buf = sprintf( $buf, $this->namespace_prefix, $this->sys_quote_char, $this->db_type );

        $filename = $this->dao_base_class_file;
        $this->log( sprintf( "Writing %s", $filename ),  __FILE__, __LINE__, self::log_info );
        $rc = @file_put_contents( $filename, $buf );
        if( !$rc ) {
            throw new Exception( "Error writing file " . $filename );
        }
    }
    
    function write_dto_file( $table, $info ) {
        

            $mask = '<?php

/**
 * db_extractor dto base class for table (%1$s).
 *
 * !!! DO NOT MODIFY THIS FILE MANUALLY !!!
 *
 * DTO = data transfer object (design pattern)
 *
 * DTO map directly to database rows, with one attribute per column.
 * See http://en.wikipedia.org/wiki/Data_transfer_object
 *
 * This file is auto-generated and is NOT intended
 * for manual modifications/extensions.
 *
 * Additionally functionality can instead be placed
 * in ../%2$s
 *
 */
';
            $buf = sprintf( $mask, $table, $this->dto_filename( $table ));

            $var_mask = '
        /**
         * field:   %1$s
         * comment: %2$s
         * type:    %3$s
         * null:    %4$s
         * key:     %5$s
         * default: %6$s
         * extra:   %7$s
         */
         public $%8$s;
         
         /**
          * Programmatically retrieve meta information about field %1$s
          * 
          * @param $key   one of \'type\', \'dto_type\', \'null\', \'pkey\', \'default\'
          * @return mixed  requested information.
          */
         static public function %8$s__meta( $key ) {
             static $meta = array(
               \'field\' => "%1$s",
               \'sql_type\' => "%3$s",
               \'dto_type\' => \'%9$s\',
               \'null\' => %11$s,
               \'pkey\'  => %12$s,
               \'default\' => %10$s,
               \'auto_incr\' => %13$s,
               \'comment\' => \'%14$s\',
               \'size\' => %15$s,
             );
             return @$meta[ $key ];
         }
';

            $table_body = <<< END
            
        /**
         * Returns an associative array of all field names and values.
         */
         public function getFields() {
             return get_object_vars( \$this );
         }
         
END;

            foreach( $info as $table_column ) {

                $php_safe_field = $this->col_safe_for_php( $table_column['Field'] );
                $dto_type = $this->db_type_to_dto_type($table_column['Type'], $numeric_min, $numeric_max, $first_val);
                $size = $this->get_col_size( $table_column['Type'] );

                $table_body .= sprintf( $var_mask,
                                        $table_column['Field'],
                                        $table_column['Comment'],
                                        $table_column['Type'],
                                        $table_column['Null'],
                                        $table_column['Key'],
                                        $table_column['Default'],
                                        $table_column['Extra'],
                                        $php_safe_field,
                                        $dto_type,
                                        $this->db_default_to_dto_default($table_column['Default'], $dto_type),
                                        $table_column['Null'] == 'YES' ? 'true' : 'false',
                                        $table_column['Key'] == 'PRI' ? 'true' : 'false',
                                        stristr( $table_column['Extra'], 'auto_increment' ) ? 'true' : 'false',
                                        str_replace( "'", "\\'", $table_column['Comment'] ),
                                        $size ?: 'null'
                                      );
                
            }
            
            $dto_base_class = $this->dto_base_classname( $table); 
            $buf .= sprintf( "abstract class %s {\n%s\n}\n", $dto_base_class, $table_body );
            
            $filename_dto_base = $this->dto_base_filename( $table );
            $pathname_dto_base = $this->dto_base_dir . $filename_dto_base;
            $this->log( sprintf( "Writing %s", $pathname_dto_base ),  __FILE__, __LINE__, self::log_info );
            $rc = @file_put_contents( $pathname_dto_base, $buf );
            if( !$rc ) {
                throw new Exception( "Error writing file " . $pathname_dto_base );
            }
            
            $dto_mask = '<?php

/**
 * db_extractor dto wrapper for table (%4$s).
 *
 * This file was auto-generated (once) and is intended
 * for manual modifications/extensions.
 *
 * MANUAL CHANGES IN THIS FILE WILL BE PRESERVED DURING SUBSQUENT RUNS.
 *
 */

require_once( dirname(__FILE__) . \'/base/%1$s\' );

class %2$s extends %3$s {

    // Add additional functionality here.
    
}
';
            $classname_dto = $this->dto_classname( $table );
            $buf = sprintf( $dto_mask, $filename_dto_base,  $classname_dto, $dto_base_class, $table);
            $filename_dto = $this->dto_dir . $this->dto_filename( $table );
            if( file_exists( $filename_dto )) {
                $this->log( sprintf( "Ignoring existing modifiable file %s", $filename_dto ),  __FILE__, __LINE__, self::log_warning );
                return;
            }
            $this->log( sprintf( "Writing %s", $filename_dto ),  __FILE__, __LINE__, self::log_info );
            $rc = @file_put_contents( $filename_dto, $buf );
            if( !$rc ) {
                throw new Exception( "Error writing file " . $filename_dto );
            }
    }
    
   private function get_col_size( $col_type ) {
      $pattern = '/.*\((.*)\)/';
      if( preg_match( $pattern, $col_type, $matches ) ) {
         return $matches[1];
      }
      return null;
   }
    
    private function db_type_to_dto_type( $mysql_type, &$numeric_min, &$numeric_max, &$first_val ) {
        $first_val = $numeric_min = $numeric_max = null;
        $dto_type = 'string';
        $type = $mysql_type;
        if( stristr( $type, 'enum' ) ) {
          $dto_type = 'string';
          preg_match( "/enum\('([^']*)'/i", $type, $matches);
          $first_val = $matches[1];
        }
        else if( stristr($type, 'tinyint' )) {
          $dto_type = 'int';
          $numeric_min = stristr($type, 'unsigned' ) ? 0 : -128;
          $numeric_max = stristr($type, 'unsigned' ) ? 255: 127;
        }
        else if( stristr($type, 'smallint' )) {
          $dto_type = 'int';
          $numeric_min = stristr($type, 'unsigned' ) ? 0 : -32768;
          $numeric_max = stristr($type, 'unsigned' ) ? 65535 : 32767;
        }
        else if( stristr($type, 'mediumint' )) {
          $dto_type = 'int';
          $numeric_min = stristr($type, 'unsigned' ) ? 0 : -8388608;
          $numeric_max = stristr($type, 'unsigned' ) ? 16777215 : 8388608;
        }
        else if( stristr($type, 'int' )) {
          $dto_type = 'int';
          $numeric_min = stristr($type, 'unsigned' ) ? 0 : -2147483648;
          $numeric_max = stristr($type, 'unsigned' ) ? 4294967295 : 2147483647;
        }
        else if( stristr($type, 'bigint' )) {
          $dto_type = 'int';
          $numeric_min = stristr($type, 'unsigned' ) ? 0 : -9223372036854775808;
          $numeric_max = stristr($type, 'unsigned' ) ? 18446744073709551615 : 9223372036854775807;
        }
        else if( stristr( $type, 'bool' ) ) {
          $dto_type = 'bool';
        }
        else if( stristr( $type, 'double' ) ) {
          $dto_type = 'double';
        }
        else if( stristr( $type, 'decimal' ) ) {
          $dto_type = 'double';
        }
        else if( stristr( $type, 'date' ) ) {
          $dto_type = 'date';
        }
        else if( stristr( $type, 'time' ) ) {
          $dto_type = 'datetime';
        }
        else if( stristr( $type, 'year' ) ) {
          $dto_type = 'int';
          $numeric_min = 1901;
          $numeric_max = 2155;
        }
        else if( stristr( $type, 'binary' ) ) {
          $dto_type = 'string';
          $first_val = 'DDDD';
        }
        
        return $dto_type;
    }

    private function db_default_to_dto_default( $db_default, $dto_type ) {
        $php_default = $db_default;
        if( $php_default == 'NULL' ) {
          $php_default = 'PDO::PARAM_NULL';
        }
        else if( is_bool( $php_default ) || is_numeric( $php_default ) || is_null( $php_default ) ) {
          // leave unmodified.
        }
        else {
          $php_default = "'" . str_replace( "'", "\\'", $php_default ) . "'";
        }
        if( !$php_default ) {
          $php_default = 'null';
        }
        return $php_default;
    }


    function write_dao_test_file( $table, $info ) {
        
        $dto1_field_buf = '';
        $dto2_field_buf = '';
        foreach( $info as $table_column ) {
            $var_mask = "            \$dto->%s = %s;\n";
            
            $type = $this->db_type_to_dto_type($table_column['Type'], $numeric_min, $numeric_max, $first_val);
            $value = $first_val ? "'$first_val'" : "'a'";
            $value2 = $first_val ? "'$first_val'" : "'b'";
            switch( $type ) {
                case 'int': $value = (int)$numeric_max-1; $value2 = $value + 1; break;
                case 'bool': $value = (bool)true; $value2 ='false'; break;
                case 'double': $value = 2.1; $value2 = $value+1; break;
                case 'datetime': $value = date('"Y-m-d H:i:s"', strtotime("2012-12-20 23:59:59")); $value2 = date('"Y-m-d H:i:s"', strtotime("2012-12-21 00:00:59")); break;
                case 'date': $value = date('"Y-m-d"', strtotime("2012-12-20")); $value2 = date('"Y-m-d"', strtotime("2012-12-21")); break;
                case 'string': break;
            }

            $php_safe_field = $this->col_safe_for_php( $table_column['Field'] );
            
            $dto1_field_buf .= sprintf( $var_mask,
                                        $php_safe_field,
                                        $value );

            $dto2_field_buf .= sprintf( $var_mask,
                                        $php_safe_field,
                                        $value2 );
            
        }
        
        
      $pk_list = array();
      $crit_buf = '';
      $cnt = 0;
      $indent = '            ';
      foreach( $info as $table_column ) {
          if( $table_column['Key'] == 'PRI' ) {
              $pk_list[] = $this->col_safe_for_php( $table_column['Field'] );
              if( $cnt++ ) {
                $crit_buf .= $indent . "\$criteria->and_();\n";
              }
              $crit_buf .= $indent . sprintf( "\$criteria->equal( '%s', \$dto->%s );\n", $table_column['Field'], $this->col_safe_for_php( $table_column['Field'] ) );
          }
      }
      $pk_id = count($pk_list) ? '$dto->' . implode( ", \$dto->", $pk_list ) : '';
        

        
$mask = '<?php
/**
 * db_extractor DAO unit test class for table (%1$s).
 *
 * !!! DO NOT MODIFY THIS FILE MANUALLY !!!
 *
 * This file is auto-generated and is NOT intended
 * for manual modifications/extensions.
 *
 * This test case is intended to be run as part of
 * the db_model test harness, and tests methods
 * in %2$s.
 *
 *
 */
 
// This test case assumes that dbModelFactory_phpunit and UnitTestCase
// have already been included by the master test suite.

class %5$s%1$s_phpunit extends PHPUnit_Framework_TestCase {
    
    function testCRUD() {
        $has_pk = %7$s;
        if( !$has_pk ) {
            // CRUD tests not yet implemented for tables without primary keys.
            return;
        }

        // we will do our query tests in a transaction and roll it back at the end.
        // so in case anything fails, we should leave DB in a consistent state.
        // assuming DB supports transactions.  :-)
        %5$sdbModelFactory_phpunit::dbmf()->getPDO()->beginTransaction();
    
        try {

            $dao = %5$sdbModelFactory_phpunit::dbmf()->daoInstance(\'%1$s\');

            // Obtain a count of rows before we modify anything.            
            $countStart = $dao->countAll();
            
            // Instantiate a DTO object and insert new row.
            $dto = %5$sdbModelFactory_phpunit::dbmf()->dtoNew(\'%1$s\');
%3$s
        
            $dao->insert( $dto );
            $test = \'$dao->countAll();\';
            $buf = eval( "return " . $test );
            $expect = $countStart + 1;
            $this->assertEquals( $buf, $expect, $test );

            // Store record\'s PK into criteria object before we modify the PK of the DTO.
            $criteria = %5$sdbModelFactory_phpunit::dbmf()->daoCriteriaNew();
%8$s            

            
            // Now we update all fields in the row, including primary key.
%4$s
            $dao->updateByCriteria( $dto, $criteria );
            
            $dto2 = $dao->getByPk( %6$s );
            
            $test = \'$dto2 == $dto;\';
            $buf = eval( "return " .$test );
            $expect = true;
            $this->assertEquals( $buf, $expect, $test );
            
            // finally we delete the new record.  should be back to original state.
            $dao->delete( $dto );
            $test = \'$dao->countAll();\';
            $buf = eval( "return " . $test );
            $expect = $countStart;
            $this->assertEquals( $buf, $expect, $test );
            
        }
        catch( Exception $e ) {
            %5$sdbModelFactory_phpunit::dbmf()->getPDO()->rollback();
            $this->assertContains( "foreign key", $e->getMessage() );
        }
        
        // rollback all the above.  just in case.
        %5$sdbModelFactory_phpunit::dbmf()->getPDO()->rollback();
        
    }
    
}
 
';   

        $buf = sprintf( $mask,
                        $table,
                        $this->dao_base_filename( $table ),
                        $dto1_field_buf,
                        $dto2_field_buf,
                        $this->namespace_prefix,
                        $pk_id,
                        count($pk_list) > 0 ? 'true' : 'false',
                        $crit_buf);
        
        $filename = $this->dao_test_filename( $table );
        $pathname = $this->dao_tests_dir . '/' . $filename;
        $this->log( sprintf( "Writing %s", $pathname ),  __FILE__, __LINE__, self::log_info );
        $rc = @file_put_contents( $pathname, $buf );
        if( !$rc ) {
            throw new Exception( "Error writing file " . $pathname);
        }
    }




/*
 
 
 class PersonDAO {
   var $conn;

   function PersonDAO(&$conn) {
     $this->conn =& $conn;
   }

   function save(&$dto) {
     if ($v->id == 0) {
       $this->insert($dto);
     } else {
       $this->update($dto);
     }
   }


   function get($id) {
     #execute select statement
     #create new dto and call fetch_dto
     #return dto
   }

   function delete(&$dto) {
     #execute delete statement
     #set id on dto to 0
   }

   #-- private functions

   function fetch_dto(&dto, $result) {
     #fill dto from the database result set
   }

   function update(&$dto) {
      #execute update statement here
   }

   function insert(&$dto) {
     #generate id (from Oracle sequence or automatically)
     #insert record into db
     #set id on dto
   }
 }

 
*/
    
    function write_dao_file( $table, $info ) {
            $buf = '';
            $class_buf = '';

            $dao_base_class = $this->dao_base_classname( $table );
            
            $mask = '<?php

/**
 * db_extractor dao base class for table (%1$s).
 *
 * !!! DO NOT MODIFY THIS FILE MANUALLY !!!
 * 
 * DAO = data access object (design pattern)
 *
 * DAO handle access to the database (insert, update, delete, select)
 * See http://en.wikipedia.org/wiki/Data_access_object
 *
 * This file is auto-generated and is NOT intended
 * for manual modifications/extensions.
 *
 * Additionally functionality can instead be placed
 * in ../%2$s
 *
 */
';
            $buf .= sprintf( $mask, $table, $this->dao_filename( $table ) );

            $pk_list = array();
            $pk_where = '';
            $cnt = 0;
            $typ_attr_buf = '';
            foreach( $info as $table_column ) {
                if( $table_column['Key'] == 'PRI' ) {
                    $pk_list[] = $table_column['Field'];
                }
                $typ_attr_buf .= sprintf( "'%s' => \"%s\",", $table_column['Field'], str_replace( '"', "\\\"", $table_column['Type'] ) );
            }
            
            $get_method_buf = $this->gen_dao_get( $table, $pk_list, $info );
            $del_method_buf = $this->gen_dao_delete( $table, $pk_list, $info );
            $ins_method_buf = $this->gen_dao_insert( $table, $pk_list, $info );
            $upd_method_buf = $this->gen_dao_update( $table, $pk_list, $info );
            $sav_method_buf = $this->gen_dao_save( $table, $pk_list, $info );
            $gfr_method_buf = $this->gen_dao_fetch_dto( $table, $pk_list, $info );
            
            $table_body = '';        
                
                $class_mask = '

require_once( dirname(__FILE__) . \'/%1$sdaoBase.php\' );
require_once( dirname(__FILE__) . \'%2$s\' );
                
abstract class %3$s extends %1$sdaoBase {
    protected $field_types = array( %10$s );
    %4$s
    %5$s
    %6$s
    %7$s
    %8$s
    %9$s
}         
';
                $class_buf .= sprintf( $class_mask,
                                        $this->namespace_prefix,
                                        sprintf( '/../../dto/%s', $this->dto_filename( $table ) ),
                                        $dao_base_class,
                                        $sav_method_buf,
                                        $get_method_buf,
                                        $del_method_buf,
                                        $ins_method_buf,
                                        $upd_method_buf,
                                        $gfr_method_buf,
                                        $typ_attr_buf
                                      );
                
            
            $filename_dao_base = $this->dao_base_filename( $table );
            $pathname_dao_base = $this->dao_base_dir . $filename_dao_base;
            $buf .= $class_buf;
            
            if( !count( $pk_list ) ) {
//                $buf = "<?php\n // No primary keys detected. Class not generated.";
            }
            
            $this->log( sprintf( "Writing %s", $pathname_dao_base ),  __FILE__, __LINE__, self::log_info );
            $rc = @file_put_contents( $pathname_dao_base, $buf );
            if( !$rc ) {
                throw new Exception( "Error writing file " . $pathname_dao_base );
            }
            
            
            $dao_mask = '<?php

/**
 * db_extractor dao wrapper for table (%4$s).
 *
 * This file was auto-generated (once) and is intended
 * for manual modifications/extensions.
 *
 * MANUAL CHANGES IN THIS FILE WILL BE PRESERVED DURING SUBSQUENT RUNS.
 * 
 */
 
require_once( dirname(__FILE__) . \'/base/%1$s\' );

class %2$s extends %3$s {

    // Add additional functionality here.
    
}
';
            $classname_dao = $this->dao_classname( $table );
            $buf = sprintf( $dao_mask, $filename_dao_base,  $classname_dao, $dao_base_class, $table);
            $filename_dao = $this->dao_dir . $this->dao_filename( $table );
            if( file_exists( $filename_dao )) {
                $this->log( sprintf( "Ignoring existing modifiable file %s\n", $filename_dao ),  __FILE__, __LINE__, self::log_warning );
                return;
            }
            
            $this->log( sprintf( "Writing %s", $filename_dao ),  __FILE__, __LINE__, self::log_info );
            $rc = @file_put_contents( $filename_dao, $buf );
            if( !$rc ) {
                throw new Exception( "Error writing file " . $filename_dao );
            }
            
            
    }
    
    function gen_dao_delete( $table, $pk_list, $info ) {

        list( $params_buf, $where_clause, $from_and_where_clause, $sprintf_where_args ) = $this->get_params_and_where( $table, $pk_list );
        list( $dummy, $dummy, $dummy, $dummy, $args_buf ) = $this->get_params_and_where( $table, $pk_list, 'dto' );
        $query = sprintf( 'sprintf( \'delete %s\', %s );', $from_and_where_clause, $sprintf_where_args );        
     
     $method_mask_pk = '
    /**
     * delete a row given primary key
     */
    public function deleteByPk(%1$s) {
        $query = %2$s;
        $result = $this->query( $query );
        return $result != false;
    }
    
    /**
     * delete a row given DTO
     */
    public function delete(%3$s $dto) {
        $this->deleteByPk( %4$s );
    }    
';

    $method_mask = count($pk_list) ? $method_mask_pk : '';
    $method_mask .= "
    /**
     * delete 1 or more rows given %5\$s object
     *
     * @param %5\$s \$criteria  Criteria used to specify where clause.
     */
    public function deleteByCriteria(%5\$s \$criteria) {
        \$query = 'delete from {$this->sys_quote_char}%6\$s{$this->sys_quote_char} ' . \$criteria->whereSql();
        \$result = \$this->query( \$query );
        return \$result != false;
    }
    
    /**
     * delete all rows
     *
     */
    public function deleteAll() {
        \$query = 'delete from {$this->sys_quote_char}%6\$s{$this->sys_quote_char} ';
        \$result = \$this->query( \$query );
        return \$result != false;
    }

    /**
     * truncate table.  may be faster than deleteAll.  less portable.
     *
     */
    public function truncate() {
        \$query = 'truncate {$this->sys_quote_char}%6\$s{$this->sys_quote_char} ';
        \$result = \$this->query( \$query );
        return \$result != false;
    }

    
";

        return sprintf( $method_mask,
                $params_buf,
                $query,
                $this->dto_classname( $table ),
                $args_buf,
                $this->dao_criteria_classname(),
                $table
                );

   
   }

    function gen_dao_save( $table, $pk_list, $info ) {
        
        if( !count( $pk_list ) ) {
            return '';
        }
        
        list( $params_buf, $where_clause, $from_and_where_clause, $sprintf_where_args, $args_buf, $pk_complete_buf ) = $this->get_params_and_where( $table, $pk_list, 'dto' );

     $method_mask = '
    /**
     * save a row to database.
     *
     * If primary key is supplied, an update will be performed, otherwise an insert.
     *
     * If caller requires the ID for autoincrement column, then ::insert() should
     * be called instead of ::save()
     *
     * @return bool true on success.
     */
    public function save(%1$s $dto) {
        // if pk is complete, then we update.  else insert.
        if (%2$s) {
          return $this->update($dto);
        } else {
          return $this->insert($dto) != 0;
        }
    }
';

        return sprintf( $method_mask, $this->dto_classname( $table ), $pk_complete_buf );
   }

    function gen_dao_fetch_dto( $table, $pk_list, $info ) {

        $col_names = $this->get_column_list( $info );
        $all_cols_match = true;
        $buf = '';
        $timestamp_types = array( 'timestamp', 'timestamptz', 'datetime' );
      
        foreach( $col_names as $col ) {
            $type = @$info[$col]['Type'];
            $php_col_name = $this->col_safe_for_php( $col );
            if( $php_col_name != $col ) {
                $all_cols_match = false;
            }
            
            if( in_array($type, $timestamp_types ) ) {
               $buf .= sprintf( '        $dto->%1$s = strtotime( @$row["%2$s"] );', $this->col_safe_for_php( $col ), $col ) . "\n";
               $all_cols_match = false;
            }
            else {
               $buf .= sprintf( '        $dto->%1$s = @$row["%2$s"];', $this->col_safe_for_php( $col ), $col ) . "\n";
            }
        }

    if( $all_cols_match ) {
        $method_mask = '
    protected function fetch_dto(PDOStatement $result) {
        switch( $this->fetch_style ) {
           case null: return $result->fetchObject( \'%1$s\' );
           default: return $result->fetch( $this->fetch_style ); ; 
        }
    }
';
    } else {
        $method_mask = '
    protected function fetch_dto(PDOStatement $result) {
        
        $row = $result->fetch( PDO::FETCH_ASSOC );
        if( !$row ) {
            return null;
        }

        // equivalent to new %1$s(), but a little cleaner.
        $dto = $this->getDbModelFactory()->dtoNew(\'%3$s\');
         
        //fill dto from the database result set
%2$s

        return $dto;
    }    
';
    }

        return sprintf( $method_mask, $this->dto_classname($table), $buf, $table );
   }

   
    function get_params_and_where( $table, $pk_list, $target_obj = null ) {
     
        $where_clause = '';
        $params_buf = '';
        $args_buf = '';
        $pk_complete_buf = '';
        $cnt = 0;
        foreach( $pk_list as $pk ) {
            if( $cnt ++ ) {
                $params_buf .= ', ';
                $where_clause .= ' and ';
                $args_buf .= ', ';
                $pk_complete_buf .= ' && ';
            }
            
            $pk_php = $this->col_safe_for_php( $pk );
            $params_buf .= sprintf( '$%s', $pk_php );
            $where_clause .= sprintf( '%s = %%s', $pk );
            $args_buf .= $target_obj ? sprintf( '$%s->%s', $target_obj, $pk_php ) : $pk_php;
            $pk_complete_buf .= $target_obj ? sprintf( '$%s->%s', $target_obj, $pk_php ) : $pk_php;
        }
        
        $cnt = 0;
        $from_and_where_clause = sprintf( 'from %s%s%s where %s', $this->sys_quote_char, $table, $this->sys_quote_char, $where_clause );
        $sprintf_where_args = '';
        foreach( $pk_list as $pk ) {
           if( $cnt ++ ) {
               $sprintf_where_args .= ', ';
           }
           $pk = $this->col_safe_for_php( $pk );
           $pk_var = $target_obj ? sprintf('%s->%s', $target_obj, $pk) : $pk; 
           $sprintf_where_args .= sprintf( '$this->escape($%s, $this->pdo)', $pk_var );
        }
        
        return array( $params_buf, $where_clause, $from_and_where_clause, $sprintf_where_args, $args_buf, $pk_complete_buf );
    }
    
    
    function gen_dao_get( $table, $pk_list, $info ) {

        list( $params_buf, $where_clause, $from_and_where_clause, $sprintf_where_args ) = $this->get_params_and_where( $table, $pk_list );
        $query = sprintf( 'sprintf( \'select %%s %s\', $col_buf, %s )', $from_and_where_clause, $sprintf_where_args );
     
        $method_mask_pk = '
    /**
     * retrieve a row, given primary key
     *
     * @param mixed %1$s      primary key
     * @param mixed $cols     string or array. columns in the select.  array of column names is best-practice for safety.
     *
     * @return %3%s selected row.
     */
    public function getByPk(%1$s, $cols=\'*\') {
        $col_buf = $this->gen_select_col_buf( $cols );
        $query = %2$s;
        $result = $this->querypdo( $query );
        $dto = $this->fetch_dto( $result );
        return $dto;
    }
    
    /**
     * retrieve a row, given primary key. throws exception if row not found.
     *
     * @param mixed %1$s      primary key
     * @param mixed $cols     string or array. columns in the select.  array of column names is best-practice for safety.
     *
     * @return %3%s selected row.
     */
    public function getOneByPk(%1$s, $cols=\'*\') {
        $col_buf = $this->gen_select_col_buf( $cols );
        $query = %2$s;
        $result = $this->querypdo( $query );
        $dto = $this->fetch_dto( $result );
        if( !$dto ) {
            throw new Exception( "Expected to find one row and found zero instead." );
        }
        return $dto;
    }    
';
        $method_mask = count($pk_list) ? $method_mask_pk : '';
        $method_mask .= '
    /**
     * retrieve an array of rows, given a %4$s object.
     *
     * @param %4$s $criteria  Criteria used to specify where, group by, order by, offset, and limit clauses.
     * @param mixed $cols     string or array. columns in the select.  array of column names is best-practice for safety.
     * @param mixed $cb       a callable function or method, as documented for call_user_func().  return "cancel" to stop retrieval.
     * @param mixed $cbdata   user data.  pass whatever data you like to the callback function.
     *
     * @return array %3%s selected rows.  if $cb is non-null, result will be null.
     */
    public function getByCriteria(%4$s $criteria, $cols=\'*\', $cb = null, $cbdata = null) {

        $col_buf = $this->gen_select_col_buf( $cols );
        $query_mask = \'select %%s from %6$s%5$s%6$s %%s %%s %%s %%s %%s\';
        $query = sprintf( $query_mask, $col_buf, $criteria->whereSql(), $criteria->groupBySql(), $criteria->orderBySql(), $criteria->limitSql(), $criteria->offsetSql() );
        $result = $this->querypdo( $query );
        
        if( !$result ) {
            $error_info = $this->pdo->errorInfo();
            throw new Exception( \'PDO query error: \' . $error_info[2] );
        }

        if( is_callable( $cb ) ) {
            while( $dto = $this->fetch_dto( $result ) ) {
                if( "cancel" == call_user_func( $cb, $dto, $cbdata ) ) {
                    break;
                }
            }
        }
        else {
            $rows = array();
            while( $dto = $this->fetch_dto( $result ) ) {
                $rows[] = $dto;
            }
            return $rows;
        }
        $result->closeCursor();
        return null;
    }

    /**
     * retrieve a single DTO, given a %4$s object.
     *
     * This method will make two calls to the DB.  One for a count(*), and a second to
     * get the results, if within desired range.
     *
     * @param %4$s $criteria  Criteria used to specify where, group by, order by, offset, and limit clauses.
     * @param int $expect_min_rows  Minimum number of rows that are expected to be returned.
     * @param int $expect_max_rows  Maximum number of rows that are expected to be returned.
     * @param mixed $cols     string or array. columns in the select.  array of column names is best-practice for safety.
     *
     * @return %3$s.  returns null if # of matching rows is not within desired range.
     */
    public function getByCriteriaExpect(%4$s $criteria, $expect_min_rows, $expect_max_rows, $cols=\'*\') {
        $cnt = $this->countByCriteria( $criteria );
        if( $cnt >= $expect_min_rows && $cnt <= $expect_max_rows ) {
            return $this->getByCriteria( $criteria, $cols );
        }
        return null;
    }

    /**
     * retrieve a single DTO, given a %4$s object.
     *
     * @param %4$s $criteria  Criteria used to specify where, group by, order by, offset, and limit clauses.
     * @param mixed $cols     string or array. columns in the select.  array of column names is best-practice for safety.
     *
     * @return %3$s.  returns null if # of matching rows is 0 or greater than 1.
     */
    public function getOneByCriteria(%4$s $criteria, $cols=\'*\') {
        $results = $this->getByCriteria( $criteria, $cols );
        return count( $results ) == 1 ? $results[0] : null;
    }

    /**
     * retrieve a single DTO, given a %4$s object.  Throws an exception if number of matching rows != 1.
     *
     * @param %4$s $criteria  Criteria used to specify where, group by, order by, offset, and limit clauses.
     * @param mixed $cols     string or array. columns in the select.  array of column names is best-practice for safety.
     *
     * @return %3$s.
     */
    public function getExactlyOneByCriteria(%4$s $criteria, $cols=\'*\') {
        $results = $this->getByCriteria( $criteria, $cols );
        if( count( $results ) != 1 ) {
            throw new Exception( "Found wrong number of rows" );
        }
        return $results[0];
    }

    /**
     * retrieve count of rows, given a %4$s object.
     *
     * @param %4$s $criteria  Criteria used to specify where, group by, order by, offset, and limit clauses.
     *
     * @return array %3%s selected rows.
     */
    public function countByCriteria(%4$s $criteria) {
        $query_mask = \'select count(*) as count from %6$s%5$s%6$s %%s %%s %%s %%s\';
        $query = sprintf( $query_mask, $criteria->whereSql(), $criteria->groupBySql(), $criteria->limitSql(), $criteria->offsetSql() );
        $result = $this->querypdo( $query );
        
        $count = 0;
        while( $row = $result->fetch( PDO::FETCH_NUM ) ) {
            $count = $row[0];
        }
        $result->closeCursor();
        return $count;
    }


    /**
     * retrieve count of rows, given a %4$s object.  Ignores limit and offset to give a total count.
     *
     * @param %4$s $criteria  Criteria used to specify where, group by, order by, offset, and limit clauses.
     *
     * @return array %3%s selected rows.
     */
    public function countByCriteriaIgnoreLimitAndOffset(%4$s $criteria) {
        $query_mask = \'select count(*) as count from %6$s%5$s%6$s %%s %%s\';
        $query = sprintf( $query_mask, $criteria->whereSql(), $criteria->groupBySql() );
        $result = $this->querypdo( $query );
        
        $count = 0;
        while( $row = $result->fetch( PDO::FETCH_NUM ) ) {
            $count = $row[0];
        }
        $result->closeCursor();
        return $count;
    }


    /**
     * retrieve all rows from table %5$s.
     *
     * @param mixed $cols     string or array. columns in the select.  array of column names is best-practice for safety.
     * @param mixed $cb       a callable function or method, as documented for call_user_func()
     * @param mixed $cbdata   user data.  pass whatever data you like to the callback function.
     *
     * @return array %3%s selected rows.  if $cb is non-null, return will be null.
     */
    public function getAll($cols=\'*\', $cb = null, $cbdata = null) {
        $col_buf = $this->gen_select_col_buf( $cols );
        $query_mask = \'select %%s from %6$s%5$s%6$s\';
        $query = sprintf( $query_mask, $col_buf );
        $result = $this->querypdo( $query );
        
        if( is_callable( $cb ) ) {
            while( $dto = $this->fetch_dto( $result ) ) {
                call_user_func( $cb, $dto, $cbdata );
            }
        }
        else {
            $rows = array();
            while( $dto = $this->fetch_dto( $result ) ) {
                $rows[] = $dto;
            }
            return $rows;
        }
        $result->closeCursor();
        return null;
    }
    
    /**
     * retrieve count of all rows in table %5$s.
     *
     * @return int count of rows in table.
     */
    public function countAll() {
        $query = \'select count(*) as count from %6$s%5$s%6$s\';
        $result = $this->querypdo( $query );
        
        $count = 0;
        while( $row = $result->fetch( PDO::FETCH_NUM ) ) {
            $count = (int)$row[0];
            break;
        }
        $result->closeCursor();
        return $count;
    }
    
';

        return sprintf( $method_mask,
                $params_buf,
                $query,
                $this->dto_classname( $table ),
                $this->dao_criteria_classname(),
                $table,
                $this->sys_quote_char
                );

   
   }


   function gen_dao_insert( $table, $pk_list, $info ) {
   
      $col_names = $this->get_column_list( $info );
      $insert_buf = '';
            
      $pk_buf = '';
      if( count($pk_list) == 1 ) {
         $sequence_name_str = '';
         switch( $this->db_type ) {
            case 'postgres';
               $sequence_name_str = sprintf( '"%1$s_%2$s_seq"', $table, $pk_list[0] );
               break;
            case 'mysql';
               // not needed.
               break;
         }
         
         $pk_buf .= sprintf(
'
      // This should make things a little easier for our caller.
      if( !$dto->%1$s ) {
         $lastInsertId = $dto->%1$s = $this->pdo->lastInsertId(%2$s);
      }
      else {
         $lastInsertId = $dto->%1$s;
      }
',       $this->col_safe_for_php( $pk_list[0] ), $sequence_name_str );
      }      
      
      $cnt = 0;
      foreach( $col_names as $name ) {
         $insert_buf .= sprintf(
'
      if( $dto->%1$s !== null && ( $dto->%1$s !== \'\' || $dto->%1$s__meta(\'dto_type\') == \'string\' ) ) {
         $col_buf .= (strlen($col_buf) ? \', \' : \'\') . \'%3$s%2$s%3$s\';
         $val_buf .=  (strlen($val_buf) ? \', \' : \'\') . $this->escape($dto->%1$s, $this->pdo, $dto->%1$s__meta(\'sql_type\'));
      }'           , $this->col_safe_for_php( $name ), $name, $this->sys_quote_char );
      }
      
      $method_mask = '
   /**
   * insert a row, given DTO
   *
   * @param %1$s $dto.  Will be modified with pdo::lastInsertId() if primary key exists and is unset.
   *
   * Any DTO fields that contain null (php) will be ignored in the insert.
   * To specify a database NULL, use daoBase::DBNULL
   */
   public function insert(%1$s $dto) {
      
      $query_mask = \'insert into %5$s%2$s%5$s (%%s) values (%%s)\';
      $lastInsertId = null;
      
      $col_buf = \'\';
      $val_buf = \'\';
%3$s
      
      $query = sprintf( $query_mask, $col_buf, $val_buf );
     
      $result = $this->querypdo( $query );
%4$s
      return $lastInsertId;
   }
     ';
     
      return sprintf( $method_mask,
              $this->dto_classname( $table ),
              $table,
              $insert_buf,
              $pk_buf,
              $this->sys_quote_char
              );
     
   }







    function gen_dao_update( $table, $pk_list, $info ) {

        $col_names = $this->get_column_list( $info );
        $set_buf = '';
        $cnt = 0;
        foreach( $col_names as $name ) {
            if( in_array( $this->col_safe_for_php( $name ), $pk_list ) ) {
               continue;  // never update a pk column
            }
            $set_buf .= sprintf(
'        if( $dto->%1$s !== null && ( $dto->%1$s !== \'\' || $dto->%1$s__meta(\'dto_type\') == \'string\' ) ) {
            $set_buf .= sprintf(\'%%s%3$s%2$s%3$s = %%s\', $set_buf ? ", " : "", $this->escape($dto->%1$s, $this->pdo, $dto->%1$s__meta(\'sql_type\') ) );
        }
',          $this->col_safe_for_php( $name ), $name, $this->sys_quote_char ) . "\n";
            
        }

        list( $params_buf, $where_clause, $from_and_where_clause, $sprintf_where_args ) = $this->get_params_and_where( $table, $pk_list, 'dto' );
        $quoted_table = $this->sys_quote_char . $table . $this->sys_quote_char;
        $query = sprintf( 'sprintf( \'update %s set %%s where %s\', $set_buf, %s )', $quoted_table, $where_clause, $sprintf_where_args );

        $query_dao_criteria_mask = sprintf( 'sprintf( \'update %s set %%s %%s\', $set_buf, $criteria->whereSql() )', $table );
        
        $method_mask_pk = '
    /**
     * update a row, given DTO
     *
     * The primary key of the DTO object will be used to determine which row to update.
     * Non null fields in the DTO object will be updated.
     
     * Any DTO fields that contain null (php) will be ignored in the update.
     * To specify a database NULL, use daoBase::DBNULL
     */
    public function update(%1$s $dto) {

        $set_buf = \'\';
%3$s

        if( !$set_buf ) {
            // nothing to update.  we are done.
            return true;
        }

        $query = %2$s;
     
        $result = $this->querypdo( $query );
        return $result != false;
    }
';

    $method_mask = count($pk_list) ? $method_mask_pk : '';
    $method_mask .= '
    /**
     * update multiple rows, given a DTO object and a %4$s object
     *
     * The %4$s object will be used to determine which rows to update.
     * Non null fields in the DTO object will be updated.
     *
     * Any DTO fields that contain null (php) will be ignored in the update.
     * To specify a database NULL, use daoBase::DBNULL
     *
     * @param %4$s $criteria  Criteria used to specify where clause.
     */
    public function updateByCriteria(%1$s $dto, %4$s $criteria) {

        $set_buf = \'\';
%3$s

        if( !$set_buf ) {
            // nothing to update.  we are done.
            return true;
        }

        $query = %5$s;
     
        $result = $this->querypdo( $query );
        return $result != false;
    }
';

        return sprintf( $method_mask,
                $this->dto_classname( $table ),
                $query,
                $set_buf,
                $this->dao_criteria_classname(),
                $query_dao_criteria_mask
                );
   
   }

   
   function get_column_list( $info ) {
        $col_names = array();
        foreach( $info as $col ) {
            $col_names[] = $col['Field'];
        }
        return $col_names;
   }
   
    function col_safe_for_php( $col_name ) {
        // FIXME: other php unsafe chars?
        return str_replace( array('-', '.', ' '), '_', $col_name );
    }

    function dao_base_classname( $table ) {
        return sprintf( "%s%s_dao_base", $this->namespace_prefix, $table );
    }

    function dao_base_filename( $table ) {
        return sprintf( "%s%s.dao.base.php", $this->namespace_prefix, $table );
    }

    function dao_classname( $table ) {
        return sprintf( "%s%s_dao", $this->namespace_prefix, $table );
    }

    function dao_filename( $table ) {
        return sprintf( "%s%s.dao.php", $this->namespace_prefix, $table );
    }

    function dao_test_filename( $table ) {
        return sprintf( "%s%s.phpunit.php", $this->namespace_prefix, $table );
    }

    function dto_base_classname( $table ) {
        return sprintf( "%s%s_dto_base", $this->namespace_prefix, $table );
    }

    function dto_base_filename( $table ) {
        return sprintf( "%s%s.dto.base.php", $this->namespace_prefix, $table );
    }
    
    function dto_classname( $table ) {
        return sprintf( "%s%s_dto", $this->namespace_prefix, $table );
    }
    
    function dto_filename( $table ) {
        return sprintf( "%s%s.dto.php", $this->namespace_prefix, $table );
    }

    function dao_criteria_classname() {
        return sprintf( "%sdaoCriteria", $this->namespace_prefix );
    }

    function dao_criteria_filename() {
        return sprintf( "%sdaoCriteria.php", $this->namespace_prefix );
    }

    function dao_criteria_test_filename() {
        return sprintf( "%sdaoCriteria.phpunit.php", $this->namespace_prefix );
    }

    function dao_criteria_test_classname() {
        return sprintf( "%sdaoCriteria_phpunit", $this->namespace_prefix );
    }

    function db_factory_classname() {
        return sprintf( "%sdbFactory", $this->namespace_prefix );
    }
    
    function db_factory_filename( ) {
        return sprintf( "%sdbFactory.php", $this->namespace_prefix );
    }

    function db_model_factory_classname() {
        return sprintf( "%sdbModelFactory", $this->namespace_prefix );
    }    
        
    function db_model_factory_filename( ) {
        return sprintf( "%sdbModelFactory.php", $this->namespace_prefix );
    }    

    function crud_php_processor_classname( $table ) {
        return sprintf( "%s%sProc", $this->namespace_prefix, $table );
    }

    function crud_php_processor_base_classname( $table ) {
        return sprintf( "%s%sProcBase", $this->namespace_prefix, $table );
    }

    function crud_php_setup_filename() {
        return sprintf( "%scrudSetup.php", $this->namespace_prefix );
    }

    function crud_php_processor_filename( $table ) {
        return sprintf( "%s%s.proc.php", $this->namespace_prefix, $table );
    }

    function crud_php_processor_base_filename( $table ) {
        return sprintf( "%s%s.proc.base.php", $this->namespace_prefix, $table );
    }

    function crud_php_controller_filename( $table ) {
        return sprintf( "%s%s.ctl.php", $this->namespace_prefix, $table );
    }
    
    function crud_html_filename( $table ) {
        return sprintf( "%s%s.html", $this->namespace_prefix, $table );
    }


    function write_dao_criteria_class_file( ) {
$buf = '<?php

/**
 * %1$sdaoCriteria class to build the WHERE clause in a SQL query.
 *
 * !!! DO NOT MODIFY THIS FILE !!!
 *
 * This is an automatically [re-]generated file.
 * Changes will NOT be preserved.
 *
 * If desired, your application can extend from this class
 * and modify that file instead.
 *
 */

require_once( dirname(__FILE__) . \'/../base/%1$sdaoBase.php\' );

/**
 * This class can be used to generate where-clause for a simple
 * (single-table, no joins) SQL query.
 *
 * Example usage:
 *
 *    $c = $dbModelFactory->daoCriteriaNew();
 *    
 *    $c->equal( \'creative_id\', 5)->
 *              and_()->
 *              ne( \'stuff\', \'creative_id\', daoCriteria::col, daoCriteria::col)->
 *              and_()->
 *              lparen()->
 *               lessthan(\'date_start\', \'2008-05-10\')->
 *               or_()->
 *               col( \'creative_type\' )->
 *               in( range(1,8) )->
 *              rparen();
 *  
 *  echo $c->whereSql() . "\n";
 *
 *  generates:
 *    
 */
 
class %1$sdaoCriteria {
    
    protected $filters = array();
    protected $order_by_cols = array();
    protected $group_by_cols = array();
    protected $limit = null;
    protected $offset = null;
    protected $having_filters = null;
    protected $in_having = false;
    
    const col = \'col\';
    const val = \'val\';
    const expr = \'expr\';

    /**
     * Constructor
     *
     * @param PDO $pdo  A PDO db connection is required in order to properly escape values.
     */
    public function __construct( PDO $pdo ) {
        $this->pdo = $pdo;
    }

    /**
     * enter "having" mode.
     *
     * When in "having" mode, subsequent filter criteria will be applied to the "group by having" clause
     * instead of the "where" clause.  To return to "where" clause mode, just call "where".
     *
     * @return %1$sdaoCriteria
     */
    public function having() {
        $this->in_having = true;
    }

    /**
     * enter "where" mode.
     *
     * When in "where" mode (the default), subsequent filter criteria will be applied to the "where" clause
     * instead of the "group by having" clause.  To enter the "group by having" clause mode, just call "having".
     *
     * @return %1$sdaoCriteria
     */
    public function where() {
        $this->in_having = false;
    }
    
    /**
     * add an "and" conditional
     *
     * The trailing underscore_ is necessary to avoid conflicting with PHP reserved word "and"
     *
     * @param $auto  boolean.  if true, and_() can be called even if no previous expression exists.
     *
     * @return %1$sdaoCriteria
     */
    public function and_( $auto = true ) {
        if( !count( $this->filters ) ) {
           if( $auto ) {
              return $this;
           }
           throw new Exception( "Invalid \'and\' condition.  Nothing to follow.");
        }
        $this->add_filter( \'and\' );
        return $this;
    }
    
    /**
     * add an "or" conditional
     *
     * The trailing underscore_ is necessary to avoid conflicting with PHP reserved word "or"
     *
     * @param $auto  boolean.  if true, and_() can be called even if no previous expression exists.
     *
     * @return %1$sdaoCriteria
     */
    public function or_( $auto = true ) {
        if( !count( $this->filters ) ) {
           if( $auto ) {
              return $this;
           }
           throw new Exception( "Invalid \'or\' condition.  Nothing to follow.");
        }
        $this->add_filter( \'or\' );
        return $this;
    }

    /**
     * add a "not" negation operator
     *
     * @return %1$sdaoCriteria
     */
    public function not() {
        $this->add_filter( \'not\' );
        return $this;
    }

    /**
     * add an "col in(...)" clause
     *
     * @param string $left.  left side of operator.  If empty, only "in(...)"
     * @param array $values.  these are the values inside the in().  If empty, only "in" will be generated, without parens.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function in( $left = null, $values = array(), $ltype = self::col ) {
        if( !count( $this->filters ) && !$left ) {
            throw new Exception( "Invalid in condition.  Nothing to follow.");
        }
        $buf = \'\';
        
        
        $cnt = 0;
        foreach( $values as $val ) {
            if( $cnt ++ != 0 ) {
                $buf .= \', \';
            }
            $buf .= $this->_e( $val, self::val );
        }
        
        $fbuf = \'\';
        if( $left ) {
            $fbuf .= $this->_e( $left, $ltype ) . \' \';
        }
        
        $fbuf .= \'in\';
        if( $buf ) {
            $fbuf .= sprintf(\' (%%s)\', $buf );
        }
        
        $this->add_filter( $fbuf );
        return $this;
    }

    /**
     * add a string, date, null, bool, or numeric value.
     *
     * @param mixed $val.  
     *
     * @return %1$sdaoCriteria
     */
    public function value($val) {
        $this->add_filter( $this->_e( $val, self::val ) );
        return $this;
    }

    /**
     * add a column name
     *
     * @param string $name.  
     *
     * @return %1$sdaoCriteria
     */
    public function col($name) {
        $this->add_filter( $this->_e( $name, self::col ) );
        return $this;
    }

    /**
     * add an "is" keyword
     *
     * @return %1$sdaoCriteria
     */
    public function is() {
        $this->add_filter( \'is\' );
        return $this;
    }

    /**
     * add a null
     *
     * @return %1$sdaoCriteria
     */
    public function null() {
        $this->add_filter( \'null\' );
        return $this;
    }


    /**
     * escape a value or a column name.
     *
     * @return %1$sdaoCriteria
     */
    protected function _e( $val, $type ) {
        switch( $type ) {
            case self::val:  return %1$sdaoBase::escape( $val, $this->pdo );
            case self::col:  return sprintf( \'%2$s%%s%2$s\', $val );
            case self::expr: return $val;
            default:
                throw new Exception( "Unsupported type" );
        }
    }

    /**
     * add an "=" operator
     *
     * Adds a clause like $left = $right
     * If both $left and $right are null, then a bare "=" will be added.
     *
     * left and right sides may each be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of = operator.
     * @param mixed $right.  right side of = operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     * @param const $rtype.  type of right side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function equal($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->binaryOperator( \'=\', \'equal\', $left, $right, $ltype, $rtype );
    }

    /**
     * Shorthand synonym for ::equal()
     *
     * @see self::equal
     */
    public function eq($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->equal( $left, $right, $ltype, $rtype);
    }

    /**
     * add a "!=" operator
     *
     * Adds a clause like $left != $right
     * If both $left and $right are null, then a bare "!=" will be added.
     *
     * left and right sides may each be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of = operator.
     * @param mixed $right.  right side of = operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     * @param const $rtype.  type of right side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function notEqual($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->binaryOperator( \'!=\', \'notEqual\', $left, $right, $ltype, $rtype );
    }

    /**
     * Shorthand synonym for ::notEqual()
     *
     * @see self::notEqual
     */
    public function ne($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->notEqual( $left, $right, $ltype, $rtype);
    }

    /**
     * add a "is null" operator
     *
     * Adds a clause like $left is null
     *
     * left may be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function isNull($left = null, $ltype = self::col) {
        return $this->leftUnaryOperator( \'is null\', \'isNull\', $left, $ltype, false );
    }

    /**
     * add a "is not null" operator
     *
     * Adds a clause like $left is not null
     *
     * left may be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function isNotNull($left = null, $ltype = self::col) {
        return $this->leftUnaryOperator( \'is not null\', \'isNotNull\', $left, $ltype, false );
    }
    
    /**
     * add an "<" operator
     *
     * Adds a clause like $left < $right
     * If both $left and $right are null, then a bare "<" will be added.
     *
     * left and right sides may each be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of < operator.
     * @param mixed $right.  right side of < operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     * @param const $rtype.  type of right side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function lessThan($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->binaryOperator( \'<\', \'lessThan\', $left, $right, $ltype, $rtype );
    }

    /**
     * Shorthand synonym for ::lessThan()
     *
     * @see self::lessThan
     */
    public function lt($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->lessThan( $left, $right, $ltype, $rtype);
    }

    /**
     * add an ">" operator
     *
     * Adds a clause like $left > $right
     * If both $left and $right are null, then a bare ">" will be added.
     *
     * left and right sides may each be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of > operator.
     * @param mixed $right.  right side of > operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     * @param const $rtype.  type of right side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function greaterThan($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->binaryOperator( \'>\', \'greaterThan\', $left, $right, $ltype, $rtype );
    }

    /**
     * Shorthand synonym for ::greaterThan()
     *
     * @see self::greaterThan
     */
    public function gt($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->greaterThan( $left, $right, $ltype, $rtype);
    }

    /**
     * add an ">=" operator
     *
     * Adds a clause like $left >= $right
     * If both $left and $right are null, then a bare ">=" will be added.
     *
     * left and right sides may each be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of >= operator.
     * @param mixed $right.  right side of >= operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     * @param const $rtype.  type of right side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function greaterThanEqual($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->binaryOperator( \'>=\', \'greaterThanEqual\', $left, $right, $ltype, $rtype );
    }

    /**
     * Shorthand synonym for ::greaterThanEqual()
     *
     * @see self::greaterThanEqual
     */
    public function gte($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->greaterThanEqual( $left, $right, $ltype, $rtype);
    }

    /**
     * add an "<=" operator
     *
     * Adds a clause like $left <= $right
     * If both $left and $right are null, then a bare "<=" will be added.
     *
     * left and right sides may each be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of <= operator.
     * @param mixed $right.  right side of <= operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     * @param const $rtype.  type of right side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function lessThanEqual($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->binaryOperator( \'<=\', \'lessThanEqual\', $left, $right, $ltype, $rtype );
    }

    /**
     * Shorthand synonym for ::lessThanEqual()
     *
     * @see self::lessThanEqual
     */
    public function lte($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->lessThanEqual( $left, $right, $ltype, $rtype);
    }

    protected function add_filter( $filter_buf ) {
        if( $this->in_having ) {
            $this->having_filters[] = $filter_buf;
        }
        else {
            $this->filters[] = $filter_buf;
        }
    }

    /**
     * Returns current filter count.  Handy for checking if and_() is necessary
     * or not when building criteria dynamically.
     */
    public function cnt() {
        return $this->in_having ? count( $this->having_filters ) : count( $this->filters );
    }

    protected function binaryOperator($op, $opname, $left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        
        if( $left !== null && $right !== null ) {
            $this->add_filter( sprintf( \'%%s %%s %%s\', $this->_e($left, $ltype), $op, $this->_e( $right, $rtype ) ) );
        }
        else if( $left === null && $right === null ) {
            $this->add_filter( $op );
        }
        else {
            throw new Exception( "Incomplete $opname expression" );
        }
        return $this;
    }

    protected function rightUnaryOperator($op, $opname, $right = null, $rtype = self::col, $required = true) {

        if( $right !== null ) {
            $this->add_filter( sprintf( \'%%s %%s\', $op, $this->_e($right, $rtype) ) );        
        }
        else if( !$required ) {
            $this->add_filter( $op );
        }
        else {
            throw new Exception( "Incomplete $opname expression" );
        }
        
        return $this;
    }

    protected function leftUnaryOperator($op, $opname, $left = null, $ltype = self::col, $required = true) {

        if( $left !== null ) {
            $this->add_filter( sprintf( \'%%s %%s\', $this->_e($left, $ltype), $op ) );
        }
        else if( !$required ) {
            $this->add_filter( $op );
        }        
        else {
            throw new Exception( "Incomplete $opname expression" );
        }
        
        return $this;
    }


    /**
     * add a "like" operator
     *
     * Adds a clause like $left like $right
     * If both $left and $right are null, then a bare "like" will be added.
     *
     * left and right sides may each be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of like operator.
     * @param mixed $right.  right side of like operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     * @param const $rtype.  type of right side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function like($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->binaryOperator( \'like\', \'like\', $left, $right, $ltype, $rtype );
    }

    /**
     * add a "not like" operator
     *
     * Adds a clause like $left not like $right
     * If both $left and $right are null, then a bare "not like" will be added.
     *
     * left and right sides may each be one of:  a column name, a value, or an expression.
     * column names and values will be escaped. expressions will not be.
     *
     * @param mixed $left.  left side of not like operator.
     * @param mixed $right.  right side of not like operator.
     * @param const $ltype.  type of left side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     * @param const $rtype.  type of right side. ( %1$sdaoCriteria::col, %1$sdaoCriteria::val, %1$sdaoCriteria::expr )
     *
     * @return %1$sdaoCriteria
     */
    public function notLike($left = null, $right = null, $ltype = self::col, $rtype = self::val) {
        return $this->binaryOperator( \'not like\', \'notLike\', $left, $right, $ltype, $rtype );
    }


    /**
     * add a "(" to where clause
     */
    public function lparen() {
        $this->add_filter( \'(\' );
        return $this;
    }
    
    /**
     * add a ")" to where clause
     */
    public function rparen() {
        $this->add_filter( \')\' );
        return $this;
    }

    /**
     * add raw (unescaped) string to where clause.
     *
     * Use this method very carefully, as you must take care to escape
     * all values yourself. See daoBase::escape()
     */
    public function raw( $buf ) {
        $this->add_filter( $buf );
        return $this;
    }
    
    /**
     * Specify columns to use in orderBy clause.  Appends to existing orderBy, if any.
     *
     * @param mixed $cols  list of columns. A string indicates a single column, ascending order.
     *                     An array indicates 1 or more columns, in the form "colname" => "asc" or "colname" => "desc"
     */
    public function orderBy( $cols ) {
        if( is_string( $cols ) ) {
            $this->order_by_cols[$cols] = "asc";
        }
        else if( is_array( $cols ) ) {
            foreach( $cols as $key => $val ) {
                if( !is_string( $key ) || !in_array( strtolower($val), array("asc", "desc") ) ) {
                    throw new Exception( "Unsupported value in orderBy column list" );
                }
               $this->order_by_cols[$key] = $val;
            }
        }
        else {
            throw new Exception( "Unsupported orderBy type" );
        }
        return $this;
    }

    /**
     * Specify expression to use in orderBy clause.  Appends to existing orderBy, if any.
     *
     * @param mixed $expr    expression.  dangerous.  will not be escaped.
     */
    public function orderByExpr( $expr ) {

         $this->order_by_cols[$expr] = "raw";
    
        return $this;
    }
    
    /**
     * retrieve orderBy SQL
     *
     * @param bool $omit_keyword.  if true, the "order by" keyword will be omitted.  default = false
     */
    public function orderBySql( $omit_keyword = false ) {
        
        $col_buf = \'\';
        if( count( $this->order_by_cols ) ) {
            if( !$omit_keyword ) {
                $col_buf .= \'order by \';
            }
            $cnt = 0;
            foreach( $this->order_by_cols as $col => $dir ) {
                if( $cnt++ > 0 ) {
                    $col_buf .= \', \';
                }
                if( $dir == "raw" ) {
                  $col_buf .= $col;
                }
                else {
                  $col_buf .= sprintf( \'%2$s%%s%2$s %%s\', $col, $dir );
                }
            }
        }
        return $col_buf;
    }

    /**
     * Specify columns to use in groupBy clause.
     *
     * @param mixed $cols  list of columns. A string indicates a single column.  An array indicates 1 or more columns.
     */
    public function groupBy( $cols ) {
        if( is_string( $cols ) ) {
            $this->group_by_cols = array( $cols );
        }
        else if( is_array( $cols ) ) {
            $this->group_by_cols = $cols;
        }
        else {
            throw new Exception( "Unsupported groupBy type" );
        }
        return $this;
    }

    /**
     * Specify expression to use in groupBy clause.  Appends to existing groupBy, if any.
     *
     * @param mixed $expr    expression.  dangerous.  will not be escaped.
     */
    public function groupByExpr( $expr ) {

         $this->group_by_cols[] = "**RAW**" . $expr;
    
        return $this;
    }

    /**
     * retrieve groupBy SQL
     *
     * @param bool $omit_keyword.  if true, the "group by" keyword will be omitted.  default = false
     */
    public function groupBySql( $omit_keyword = false ) {
        
        $col_buf = \'\';
        if( count( $this->group_by_cols ) ) {
            if( !$omit_keyword ) {
                $col_buf .= \'group by \';
            }
            $raw_tok = "**RAW**";
            $cnt = 0;
            foreach( $this->group_by_cols as $col ) {
                if( $cnt++ > 0 ) {
                    $col_buf .= \', \';
                }
                if( substr( $col, 0, strlen($raw_tok)) == $raw_tok ) {
                  $col_buf .= substr( $col, strlen($raw_tok) );
                }
                else {

                  $colparts = explode( \'.\', $col );
                  if( count($colparts) == 2 ) {
                      $col_buf .= sprintf( \'%2$s%%s%2$s.%2$s%%s%2$s\', $colparts[0], $colparts[1] );
                  }
                  else {
                      $col_buf .= sprintf( \'%2$s%%s%2$s\', $col );
                  }
                }
            }
        }
        return $col_buf;
    }

    /**
     * Specify a limit for limit clause.
     *
     * @param int $max  maximum # of rows to return.
     */
    public function limit( $max ) {
        if( !is_numeric( $max ) ) {
            throw new Exception( "Unsupported limit type" );
        }
        $this->limit = $max;
        
        return $this;
    }

    /**
     * retrieve limit SQL
     *
     * @param bool $omit_keyword.  if true, the "limit" keyword will be omitted.  default = false
     */
    public function limitSql( $omit_keyword = false ) {
        
        $col_buf = \'\';
        if( $this->limit !== null ) {
            if( !$omit_keyword ) {
                $col_buf .= \'limit \';
            }
            $col_buf .= $this->limit;
        }
        return $col_buf;
    }

    /**
     * Specify an offset for offset clause.
     *
     * @param int $offset  row # at which to begin returning results
     */
    public function offset( $offset ) {
        if( !is_numeric( $offset ) ) {
            throw new Exception( "Unsupported offset type" );
        }
        $this->offset = $offset;
        
        return $this;
    }
    
    /**
     * Specify expression to use in offset clause.  Overwrites existing offset, if any.
     *
     * @param mixed $expr    expression.  dangerous.  will not be escaped.
     */
    public function offsetExpr( $expr ) {

        $this->offset = $expr;
    
        return $this;
    }    

    /**
     * retrieve offset SQL
     *
     * @param bool $omit_keyword.  if true, the "offset" keyword will be omitted.  default = false
     */
    public function offsetSql( $omit_keyword = false ) {
        
        $col_buf = \'\';
        if( $this->offset !== null ) {
            if( !$omit_keyword ) {
                $col_buf .= \'offset \';
            }
            $col_buf .= $this->offset;
        }
        return $col_buf;
    }


    /**
     * retrieve where SQL
     *
     * @param bool $omit_keyword.  if true, the "where" keyword will be omitted.  default = false
     */
    public function whereSql( $omit_keyword = false ) {
        $buf = $omit_keyword || !count( $this->filters) ? \'\' : \'where \';
        return $buf . (count( $this->filters ) ? implode( \' \', $this->filters ) : ( $omit_keyword ? \'1 = 1\' : \'\' ));
    }
    
    /**
     * retrieve having SQL
     *
     * @param bool $omit_keyword.  if true, the "having" keyword will be omitted.  default = false
     */
    public function havingSql( $omit_keyword = false ) {
        $buf = $omit_keyword || !count( $this->having_filters) ? \'\' : \'having \';
        return $buf . (count( $this->having_filters ) ? implode( \' \', $this->having_filters ) : \'\' );
    }    
    
    
}


';
        $buf = sprintf( $buf, $this->namespace_prefix, $this->sys_quote_char );

        $filename = $this->dao_criteria_class_file;
        $this->log( sprintf( "Writing %s", $filename ),  __FILE__, __LINE__, self::log_info );
        $rc = @file_put_contents( $filename, $buf );
        if( !$rc ) {
            throw new Exception( "Error writing file " . $filename );
        }
    }


    function write_dao_criteria_class_test_file( ) {
$buf = '<?php

/**
 * db_extractor DAO unit test class for class daoCriteria.
 *
 * !!! DO NOT MODIFY THIS FILE MANUALLY !!!
 *
 * This file is auto-generated and is NOT intended
 * for manual modifications/extensions.
 *
 * This test case is intended to be run as part of
 * the db_model test harness, and tests methods
 * in daoCriteria.php
 *
 */

class %1$sdaoCriteria_phpunit extends PHPUnit_Framework_TestCase {

    /**
     * Tests "where" clause conditions
     */
    function testWhere() {

        $test = \'$criteria->value( true )->and_()->value( true )\';
        $expect = \'TRUE and TRUE\';
        $this->tWhere( $test, $expect );        

        $test = \'$criteria->value( true )->or_()->value( false )\';
        $expect = \'TRUE or FALSE\';
        $this->tWhere( $test, $expect );        

        $test = \'$criteria->col("foo")->is()->not()->null()\';
        $expect = \'%4$sfoo%4$s is not null\';
        $this->tWhere( $test, $expect );        

        $test = \'$criteria->col("foo")->isNotNull()\';
        $expect = \'%4$sfoo%4$s is not null\';
        $this->tWhere( $test, $expect );

        $test = \'$criteria->isNotNull("foo")\';
        $expect = \'%4$sfoo%4$s is not null\';
        $this->tWhere( $test, $expect );

        $test = \'$criteria->isNull("foo")\';
        $expect = \'%4$sfoo%4$s is null\';
        $this->tWhere( $test, $expect );

        $test = \'$criteria->col("foo")->isNull()\';
        $expect = \'%4$sfoo%4$s is null\';
        $this->tWhere( $test, $expect );

        $test = \'$criteria->in(1, array(1,2,3), %1$sdaoCriteria::val)\';
        $expect = "1 in (1, 2, 3)";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->in("colname", array(1,2,3), %1$sdaoCriteria::col)\';
        $expect = "\%4$scolname\%4$s in (1, 2, 3)";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->value(1)->in(null, array(1,2,3) )\';
        $expect = "1 in (1, 2, 3)";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->col("colname")\';
        $expect = "\%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->is()\';
        $expect = "is";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->null()\';
        $expect = "null";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->null()\';
        $expect = "null";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->equal()\';
        $expect = "=";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->equal("colname", 3)\';
        $expect = "\%4$scolname\%4$s = 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->equal(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 = \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->eq("colname", 3)\';
        $expect = "\%4$scolname\%4$s = 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->eq(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 = \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->notEqual("colname", 3)\';
        $expect = "\%4$scolname\%4$s != 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->notEqual(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 != \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->ne("colname", 3)\';
        $expect = "\%4$scolname\%4$s != 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->ne(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 != \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->lessThan("colname", 3)\';
        $expect = "\%4$scolname\%4$s < 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->lessThan(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 < \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->lt("colname", 3)\';
        $expect = "\%4$scolname\%4$s < 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->lt(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 < \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->lessThanEqual("colname", 3)\';
        $expect = "\%4$scolname\%4$s <= 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->lessThanEqual(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 <= \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->lte("colname", 3)\';
        $expect = "\%4$scolname\%4$s <= 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->lte(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 <= \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->greaterThan("colname", 3)\';
        $expect = "\%4$scolname\%4$s > 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->greaterThan(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 > \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->gt("colname", 3)\';
        $expect = "\%4$scolname\%4$s > 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->gt(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 > \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->greaterThanEqual("colname", 3)\';
        $expect = "\%4$scolname\%4$s >= 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->greaterThanEqual(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = "3 >= \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->gte("colname", 3)\';
        $expect = "\%4$scolname\%4$s >= 3";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->gte(3, "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col)\';
        $expect = \'3 >= \%4$scolname\%4$s\';
        $this->tWhere( $test, $expect );

        $test = \'$criteria->like();\';
        $expect = "like";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->like("colname", "a string");\';
        $expect = "\%4$scolname\%4$s like \'a string\'";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->like("a string", "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col);\';
        $expect = "\'a string\' like \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );
        
        $test = \'$criteria->notLike();\';
        $expect = "not like";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->notLike("colname", "a string");\';
        $expect = "\%4$scolname\%4$s not like \'a string\'";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->notLike("a string", "colname", %1$sdaoCriteria::val, %1$sdaoCriteria::col);\';
        $expect = "\'a string\' not like \%4$scolname\%4$s";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->lparen();\';
        $expect = "(";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->rparen();\';
        $expect = ")";
        $this->tWhere( $test, $expect );

        $test = \'$criteria->raw("%4$scolname%4$s = %4$scol2%4$s and table2.foo = 3");\';
        $expect = "\%4$scolname\%4$s = \%4$scol2\%4$s and table2.foo = 3";
        $this->tWhere( $test, $expect );


    }

    /**
     * Tests "limit" clause
     */
    function testLimit() {

        $test = \'$criteria->limit(3);\';
        $expect = "3";
        $this->tLimit( $test, $expect );

        $test = \'$criteria->limit(3);\';
        $expect = "limit 3";
        $this->tLimit( $test, $expect, false );
        
    }

    /**
     * Tests "offset x" clause
     */
    function testOffset() {

        $test = \'$criteria->offset(3);\';
        $expect = "3";
        $this->tOffset( $test, $expect );

        $test = \'$criteria->offset(3);\';
        $expect = "offset 3";
        $this->tOffset( $test, $expect, false );
        
    }

    /**
     * Tests "order by" clause
     */
    function testOrderBy() {

        $test = \'$criteria->orderBy("col1");\';
        $expect = "\%4$scol1\%4$s asc";
        $this->tOrderBy( $test, $expect );

        $test = \'$criteria->orderBy(array( "col1" => "desc", "col2" => "asc", "col3" => "asc") );\';
        $expect = "\%4$scol1\%4$s desc, \%4$scol2\%4$s asc, \%4$scol3\%4$s asc";
        $this->tOrderBy( $test, $expect );

        $test = \'$criteria->orderBy("col1");\';
        $expect = "order by \%4$scol1\%4$s asc";
        $this->tOrderBy( $test, $expect, false );
        
    }

    /**
     * Tests "group by" clause
     */
    function testGroupBy() {

        $test = \'$criteria->groupBy("col1");\';
        $expect = "\%4$scol1\%4$s";
        $this->tGroupBy( $test, $expect );

        $test = \'$criteria->groupBy(array( "col1", "col2", "col3") );\';
        $expect = "\%4$scol1\%4$s, \%4$scol2\%4$s, \%4$scol3\%4$s";
        $this->tGroupBy( $test, $expect );

        $test = \'$criteria->groupBy("col1");\';
        $expect = "group by \%4$scol1\%4$s";
        $this->tGroupBy( $test, $expect, false );
        
    }
    
    private function tWhere( $test, $expect, $omit_keyword = true ) {
        $criteria = %1$sdbModelFactory_phpunit::dbmf()->daoCriteriaNew();
        eval($test . \';\');
        $buf = $criteria->whereSql($omit_keyword);
        $this->assertEquals( $buf, $expect, $test );
    }
    
    private function tLimit( $test, $expect, $omit_keyword = true ) {
        $criteria = %1$sdbModelFactory_phpunit::dbmf()->daoCriteriaNew();
        eval($test . \';\');
        $buf = $criteria->limitSql($omit_keyword);
        $this->assertEquals( $buf, $expect, $test );
    }

    private function tOffset( $test, $expect, $omit_keyword = true ) {
        $criteria = %1$sdbModelFactory_phpunit::dbmf()->daoCriteriaNew();
        eval($test . \';\');
        $buf = $criteria->offsetSql($omit_keyword);
        $this->assertEquals( $buf, $expect, $test );
    }

    private function tOrderBy( $test, $expect, $omit_keyword = true ) {
        $criteria = %1$sdbModelFactory_phpunit::dbmf()->daoCriteriaNew();
        eval($test . \';\');
        $buf = $criteria->orderBySql($omit_keyword);
        $this->assertEquals( $buf, $expect, $test );
    }

    private function tGroupBy( $test, $expect, $omit_keyword = true ) {
        $criteria = %1$sdbModelFactory_phpunit::dbmf()->daoCriteriaNew();
        eval($test . \';\');
        $buf = $criteria->groupBySql($omit_keyword);
        $this->assertEquals( $buf, $expect, $test );
    }

    
}
';
        $buf = sprintf( $buf, $this->namespace_prefix, $this->dao_criteria_test_classname(), $this->dao_criteria_filename(), $this->sys_quote_char );

        $filename = $this->dao_criteria_tests_dir . '/' . $this->dao_criteria_test_filename();
        $this->log( sprintf( "Writing %s", $filename ),  __FILE__, __LINE__, self::log_info );
        $rc = @file_put_contents( $filename, $buf );
        if( !$rc ) {
            throw new Exception( "Error writing file " . $filename );
        }
    }

    

    function write_crud_html( $table, $info ) {
      
      $pk_list = array();
      foreach( $info as $table_column ) {
          if( $table_column['Key'] == 'PRI' ) {
              $pk_list[] = $table_column['Field'];
          }
      }
      
            $mask = '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>%3$s CRUD jqGrid</title>
 
<link rel="stylesheet" type="text/css" media="screen" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.css" />
<link rel="stylesheet" type="text/css" media="screen" href="https://cdnjs.cloudflare.com/ajax/libs/jqgrid/4.6.0/css/ui.jqgrid.css" />
 
<style>
html, body {
    margin: 0;
    padding: 0;
    font-size: 75%;
}
</style>
 
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.6.2/jquery.min.js" type="text/javascript"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqgrid/4.6.0/js/i18n/grid.locale-en.js" type="text/javascript"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqgrid/4.6.0/js/jquery.jqGrid.min.js" type="text/javascript"></script>
 
<script type="text/javascript">

// get named url param
function urlget( name )
{
  name = name.replace(/[\[]/,"\\\[").replace(/[\]]/,"\\\]");
  var regexS = "[\\?&]"+name+"=([^&#]*)";
  var regex = new RegExp( regexS );
  var results = regex.exec( window.location.href );
  if( results == null )
    return "";
  else
    return results[1];
}

// After form submit, check for server errors.
function handleAfterSubmit(p1, p2) {
    var ret = [true];

    // console.log(p1);
    var ud = null;
    if(p1.responseText) {
        var response = eval( "(" + p1.responseText + ")" );
        if( response.userdata.error ) {
            //alert( response.userdata.error.message );
            ret[0] = false;
            ret[1] = "Server error: " + response.userdata.error.message;
        }
    }
    return ret;
}
// After data load request, check for server errors.
function handleLoadComplete() {
    var ud = jQuery("#editgrid").getGridParam("userData");
    if( ud.error ) {
        alert( ud.error.message );
    }
}



jQuery(document).ready(function(){

var can_edit = %9$s;
var show_caption = urlget("caption") == "N" ? false : true;

jQuery("#editgrid").jqGrid({
   	url:\'%4$s?q=1\',
	datatype: "json",
   	colNames:[],
   	colModel:[%6$s],
   	rowNum:50,
   	rowList:[10,50,100,500,1000],
   	pager: \'#pagergrid\',
   	sortname: \'%7$s\',
        cellEdit: false,
        cellurl: "%8$s",
    viewrecords: true,
    scrollrows: true,
    sortorder: "desc",
    caption: show_caption ? "%3$s Table" : null,
    editurl: can_edit ? "%8$s" : null,
    height: window.innerHeight - (show_caption ? 74 : 51),
    autowidth: true,
//    viewsortcols: true,   // broken in 3.5.6?  Causes columns to be unsortable.
    loadComplete : handleLoadComplete,
    loadError : function(xhr,st,err) { alert("Server Error: " +xhr.statusText); } // handle http level errors. 404, etc.
}).navGrid(\'#pagergrid\',
{view:true, edit: can_edit, add: can_edit, del: can_edit}, //options
{jqModal:true,checkOnUpdate:true,savekey: [true,13], navkeys: [true,38,40], checkOnSubmit : true, reloadAfterSubmit:true, closeOnEscape:true, closeAfterEdit: true, afterSubmit: handleAfterSubmit, bottominfo:"Fields marked with (*) are required"}, // edit options
{jqModal:true,checkOnUpdate:true,savekey: [true,13], navkeys: [true,38,40], checkOnSubmit : true, reloadAfterSubmit:true, closeOnEscape:true, closeAfterAdd: true, afterSubmit: handleAfterSubmit, bottominfo:"Fields marked with (*) are required"}, // add options
{reloadAfterSubmit:true,jqModal:false, closeOnEscape:true, afterSubmit: handleAfterSubmit}, // del options
{closeOnEscape:true, multipleSearch:true}, // search options
{navkeys: [true,38,40], height:250,jqModal:false,closeOnEscape:true} // view options
).navButtonAdd(\'#pagergrid\',{caption: "", title:"Show/Hide Columns", onClickButton:function(){ jQuery("#editgrid").setColumns(); } } );

});
</script>
 
</head>
<body>
<table id="editgrid" class="scroll" cellpadding="0" cellspacing="0"></table>
<div id="pagergrid" class="scroll" style="text-align:center;"></div>

</body>
</html>
            
';

$colmodel_mask = '
   	       { label: "%1$s",  // human readable string.
                 name: "%1$s",
                 index: "%1$s",
                 width:200,
                 editable:%5$s,
                 editoptions:{readonly:%6$s,size:20},
                 formoptions:{ rowpos:%4$s, elmprefix:"%2$s",elmsuffix:"" },
                 editrules:{required:%3$s}               
               }
';

      $colnames = array();
      $colmodel_bufs = array();
      $first_field = null;
      $cnt = 1;
      foreach( $info as $table_column ) {

         $php_safe_field = $this->col_safe_for_php( $table_column['Field'] );
         $js_safe_field = str_replace('"', '\"', $table_column['Field'] );
         $first_field = $first_field ? $first_field : $js_safe_field;
         
         $colnames[] = sprintf( '"%s"', $js_safe_field );
         $is_required = $table_column['Null'] == 'NO';
         $can_edit = count($pk_list) > 0;
         
         $colmodel_bufs[] = sprintf( $colmodel_mask,
                                     $js_safe_field,
                                     $is_required ? '(*)' : '',
                                     $is_required ? 'true' : 'false',
                                     $cnt ++,
                                     $can_edit ? 'true' : 'false',
                                     $can_edit ? 'false' : 'true');
      }
      
      $colnames_buf = implode( ', ', $colnames);
      $colmodels_buf = implode( ', ', $colmodel_bufs);
      
      $buf = sprintf( $mask,
                      "",
                      "",
                      $table,
                     '../server/' . $this->crud_php_controller_filename( $table ),
                     $colnames_buf,
                     $colmodels_buf,
                     $first_field,
                     '../server/' . $this->crud_php_controller_filename( $table ) . '?save=1',
                     $can_edit ? 'true' : 'false'
                     );
      
      $filename_crud_html = $this->crud_html_filename( $table );
      $pathname_crud_html = $this->crud_client_dir . $filename_crud_html;
      $this->log( sprintf( "Writing %s", $pathname_crud_html ),  __FILE__, __LINE__, self::log_info );
      $rc = @file_put_contents( $pathname_crud_html, $buf );
      if( !$rc ) {
         throw new Exception( "Error writing file " . $pathname_crud_html );
      }
   }

    function write_crud_php_config() {
      $mask =
'<?php

/**
 * USER-MODIFIABLE SETUP FILE
 *
 * This file is automatically generated by db_extractor
 * if not already existing, but ignored if already existing.
 * Thus your changes will be preserved.
 *
 */

/**
 * This class is responsible for two things:
 *
 *  1) Obtain (or define) the configuration needed for db_crud layer.
 *  2) Instantiate a fully configured %1$sdbModelFactory
 *
 * The auto generated class is a skeleton only.  It will throw
 * exceptions until you define all the required variables
 * and/or implement the accessor methods to suit your needs.
 *
 */
class %1$sCrudSetup {

    /*
     * Modify these variables directly according to your environment.
     * Alternatively, you can modify the accessor methods in order to
     * do more complicated things such as reading from a separate
     * config file, pre-loaded config class, etc.
     */
    
    /**
     * Database server hostname. required.
     */
    static protected $dbHost = \'%2$s\';
    
    /**
     * Database name. required.
     */
    static protected $dbName = \'%3$s\';
    
    /**
     * Database username. required.
     */   
    static protected $dbUser = \'%4$s\';
    
    /**
     * Database password. required.
     */   
    static protected $dbPass = \'%5$s\';
    
    /**
     * Path to db_model. required.
     * Often this will be dirname(__FILE__) . \'/../../../db_model\'
     * but must be set in accessor method due to dirname() call
     */
    static protected $dbModelPath = \'\';

    /**
     * Path to phpUnit installation.  optional.
     * If present and valid, phpUnit will be used for unit tests.
     */
    static protected $phpUnitPath = \'\';
    
    /**
     * Path to firePHP installation.  optional.
     * If present and valid, firePHP will be used to send debugging
     * info to fireBug
     *
     * If set to empty string or null, standard PEAR paths will be checked.
     * If you do not want that, set firePHPPAth to false.
     */
    static protected $firePHPPath = \'\';
    
    /**
     * Retrieves database server hostname. 
     */
    static public function dbHost() {
        if( !self::$dbHost ) { throw new Exception( "dbHost undefined in CrudConf" ); }
        return self::$dbHost;
    }
    
    /**
     * Retrieves database name.
     */
    static public function dbName() {
        if( !self::$dbName ) { throw new Exception( "dbName undefined in CrudConf" ); }
        return self::$dbName;
    }
    
    /**
     * Retrieves database user.
     */
    static public function dbUser() {
        if( !self::$dbUser ) { throw new Exception( "dbUser undefined in CrudConf" ); }
        return self::$dbUser;
    }
    
    /**
     * Retrieves database password
     */
    static public function dbPass() {
        return self::$dbPass;
    }
    
    /**
     * Retrieves db_model path
     */
    static public function dbModelPath() {
        if( !self::$dbModelPath ) { 
            self::$dbModelPath = dirname(__FILE__) . \'/%6$s\';
        }
        
        if( !self::$dbModelPath ) { throw new Exception( "dbModelPath undefined in CrudConf" ); }
        return self::$dbModelPath;
    }
    
    /**
     * Retrieves phpUnit path
     */
    static public function phpUnitPath() {
         if( !self::$phpUnitPath ) {
            $pear_path = \'/usr/share/php/PHPUnit/\';
            if( is_dir( $pear_path ) ) {
                self::$phpUnitPath = $pear_path;
            }
         }
         return self::$phpUnitPath;
    }    
    
    /**
     * Retrieves firePHP path.
     *
     * Return false if you do  not wish to use FirePHP.
     */
    static public function firePHPPath() {
         if( PHP_SAPI == "cli" ) {
            return false;  // never want to use firePHP for CLI.
         }
         if( !self::$firePHPPath && self::$firePHPPath !== false ) {
            $pear_path = \'/usr/share/php/FirePHPCore/\';   // Ubuntu location
            if( is_dir( $pear_path ) ) {
                self::$firePHPPath = $pear_path;
            }
            else {
                $pear_path = \'/usr/share/PEAR/FirePHPCore/\';  // CentOS location
                if( is_dir( $pear_path ) ) {
                    self::$firePHPPath = $pear_path;
                }
            }
         }
         return self::$firePHPPath;
    }


    /**
     * Instantiates and returns a ready to use %1$sdbModelFactory
     */
    static public function dsn() {
    
        return sprintf( "%7$s:dbname=%%s;host=%%s", self::dbName(), self::dbHost() );
    
    }

    
    /**
     * Instantiates and returns a ready to use %1$sdbModelFactory
     */
    static public function dbModelFactory() {
        require_once( self::dbModelPath() . \'/%1$sdbModelFactory.php\' );
        
        if( self::firePHPPath() ) {
            require_once( self::firePHPPath() . \'/fb.php\' );
        }
        
        $pdo = new PDO( self::dsn(), self::dbUser(), self::dbPass() );
        return new %1$sdbModelFactory( $pdo );
    }
}

';

      $dsn_db_type = $this->db_type;
      switch( $this->db_type ) {
        case 'postgres': $dsn_db_type = 'pgsql'; break;
      }

      //$db_model_path = $this->output_dir{0} == '.' ? dirname(__FILE__) . '/' . $this->output_dir : $this->output_dir;
      $db_model_path = $this->make_relpath( $this->crud_server_conf_dir, $this->output_dir );
      $buf = sprintf( $mask, $this->namespace_prefix, $this->host, $this->db, $this->user, $this->pass, $db_model_path, $dsn_db_type );

      $filename_crud_php = $this->crud_php_setup_filename();
      $pathname_crud_php = $this->crud_server_conf_dir . $filename_crud_php;

      if( file_exists( $pathname_crud_php ) ) {
         $this->log( sprintf( "%s (user-modifiable) already exists.  Leaving unmodified.  Move away and re-run to generate a new file", $pathname_crud_php ),  __FILE__, __LINE__, self::log_info );
         return;
      }
      
      $this->log( sprintf( "Writing user-modifiable setup file %s", $pathname_crud_php ),  __FILE__, __LINE__, self::log_info );
      $rc = @file_put_contents( $pathname_crud_php, $buf );
      if( !$rc ) {
          throw new Exception( "Error writing file " . $pathname_crud_php );
      }

    }

    function write_crud_test_harness() {
      $mask = '<?php
      
if( PHP_SAPI != "cli" ) {
    ob_start();
}

class crud_suite {
    public static function suite() {
        ini_set(\'memory_limit\', -1);
        ini_set( \'ERROR_REPORTING\', 0);
        require_once( dirname(__FILE__) . \'/../config/%1$scrudSetup.php\' );
        
        if( !%1$scrudSetup::phpUnitPath() ) {
            throw new Exception( "phpUnit not available.  Check crudSetup.php" );
        }
        
        set_include_path( get_include_path() . \':\' . dirname( %1$scrudSetup::phpUnitPath() ) );
        
        require_once( %1$scrudSetup::phpUnitPath() . \'/Framework.php\');
        require_once( %1$scrudSetup::dbModelPath() . \'/%2$s/%3$s\' );
        
        $dbmf = %1$sCrudSetup::dbModelFactory();
        
        return %1$sdbmodel_test_runner::suite( $dbmf );
    }
}
';

      $buf = sprintf( $mask, $this->namespace_prefix, $this->tests_dirname, $this->model_test_harness_filename );

      $pathname_crud_php = $this->crud_test_harness_dir . '/' . $this->crud_test_harness_filename;

      $this->log( sprintf( "Writing crud test harness %s", $pathname_crud_php ),  __FILE__, __LINE__, self::log_info );
      $rc = @file_put_contents( $pathname_crud_php, $buf );
      if( !$rc ) {
          throw new Exception( "Error writing file " . $pathname_crud_php );
      }

    }
    
    function write_dbmodel_test_harness() {
      $mask = '<?php

class %1$sdbModelFactory_phpunit {
    
    static function dbmf( %1$sdbModelFactory $dbmf = null ) {
        static $_dbmf = null;
        
        if( $dbmf ) {
            $_dbmf = $dbmf;
        }
        return $_dbmf;
    }
}

class %1$sdbmodel_test_runner {
    public static function find_test_files() {
        
        $model_root = realpath( dirname( __FILE__ ). \'/../\' );
        
        $cmd = sprintf( \'find %%s -wholename \*/phpunit/\*.phpunit.php\', escapeshellarg( $model_root ) );
        $file_list = trim( $cmd );
        $test_files = explode( "\n", $file_list );
        
        return $test_files;
    }
    
    public static function suite( %1$sdbModelFactory $dbmf) {
        $files = self::find_test_files();
        $suite = new PHPUnit_Framework_TestSuite(\'DB Model\');
        
        %1$sdbModelFactory_phpunit::dbmf( $dbmf );
        
        $suite->addTestFiles( $files );
        
        return $suite;
    }
}
';

      $buf = sprintf( $mask, $this->namespace_prefix, $this->sys_quote_char );

      $pathname = $this->model_test_harness_dir . '/' . $this->model_test_harness_filename;

      $this->log( sprintf( "Writing db_model test harness %s", $pathname ),  __FILE__, __LINE__, self::log_info );
      $rc = @file_put_contents( $pathname, $buf );
      if( !$rc ) {
          throw new Exception( "Error writing file " . $pathname );
      }
    }
    

    function write_crud_php_controller( $table, $info ) {
      
      $mask =
'<?php

/**
 * AUTO-GENERATED FILE.  YOUR CHANGES WILL OVERWRITTEN.
 *
 * This file implements a simple AJAX controller
 * for the table %7$S%6$s%7$S.  This is the top-level URL
 * accessible file for CRUD operations.
 *
 * Most of the work is done by the processor class. ( *.proc.php )
 *
 * Together, the processor and controller are suitable
 * for a simple db admin interface to the table.
 *
 * For a more polished end-user interface, you are encouraged
 * to extend from the processor class and create a new controller
 * using this controller as a template.  Your new files
 * should be located in a separate directory of your choosing.
 */

crud_main();
exit();

/**
 * The "main" logic for this controller.
 */
function crud_main() {

   // Ensure browser uses UTF-8
   header( "Content-Type: text/html; charset=utf-8" );

   ob_start();

   require_once( dirname(__FILE__) . \'/%1$s/%3$s\' );
   require_once( dirname(__FILE__) . \'/%4$s\' );
   
   try {
   
        $djqg = new %2$s( %5$sCrudSetup::dbModelFactory() );
        
        ob_end_clean();
        
        $djqg->processRequest();
        
    }
    catch( Exception $e ) {

        $firePHP = %5$sCrudSetup::firePHPPath();
        if( $firePHP ) {
             require_once( $firePHP . \'/fb.php\' );
             FB::log( $e );
        }
        else { throw $e; }
    }
}
';

      $buf = sprintf( $mask,
                     $this->relpath( $this->crud_server_conf_dir, $this->crud_server_dir ),
                     $this->crud_php_processor_classname( $table ),
                     $this->crud_php_setup_filename( $table ),
                     $this->crud_php_processor_filename( $table ),
                     $this->namespace_prefix,
                     $table,
                     $this->sys_quote_char
                     );

      $filename_crud_php = $this->crud_php_controller_filename( $table );
      $pathname_crud_php = $this->crud_server_dir . $filename_crud_php;
      $this->log( sprintf( "Writing %s", $pathname_crud_php ),  __FILE__, __LINE__, self::log_info );
      $rc = @file_put_contents( $pathname_crud_php, $buf );
      if( !$rc ) {
          throw new Exception( "Error writing file " . $pathname_crud_php );
      }
   }

    function write_crud_php_processor( $table, $info ) {
        

            $mask = '<?php

/**
 * AUTO-GENERATED FILE.  YOUR CHANGES WILL OVERWRITTEN.
 *
 * This file implements an AJAX processor
 * for the table %10$s%6$s%10$s.  This class receives
 * parameters from the http request and queries
 * the database via PDO.
 *
 * This class is intended to be used as a base class
 * for a user-modifiable processor class that is instantiated
 * and called by a top-level controller class.  ( *.ctl.php )
 *
 * Together, the processor and controller are suitable
 * for a simple db admin interface to the table.
 *
 * For a more polished end-user interface, you are encouraged
 * to modify the sub-class and create custom functionality.
 */

/**
 * AJAX CRUD processor base class for the table %10$s%6$s%10$s.
 */
class %6$s
{
    protected $dbmf;
    protected $dao;  // shortcut to $this->dbmf->daoInstance( "..." );

   /**
    * Constructor.
    *
    * @param %9$sdbModelFactory
    */
   function __construct(%9$sdbModelFactory $dbmf) {
       $this->dbmf = $dbmf;
       $this->dao = $this->dbmf->daoInstance( \'%1$s\' );
   }

   /**
    * process a read (select) query and return dataset in ajax format.
    *
    * @param $send_json  if true, the function will output json string.
    * @return a php array containing the results.
    */
    function processRead( $send_json = true )
    {
        // which page.
        $page = @$_REQUEST[\'page\'];
        if( !$page ) { $page = 1; }
        
        // get how many rows we want to have into the grid - rowNum parameter in the grid
        $limit = @$_REQUEST[\'rows\'];
        if( !$limit ) { $limit = 10; }
        
        // get index row - i.e. user click to sort. At first time sortname parameter -
        // after that the index from colModel
        $sidx = @$_REQUEST[\'sidx\'];
        
        // sorting order - at first time sortorder
        $sord = @$_REQUEST[\'sord\'];
        if( !$sord ) { $sord = \'ASC\'; }

        // filter criteria
        $filters = @$_REQUEST[\'filters\'];
        
        // if we not pass at first time index use the first column for the index or what you want
        if(!$sidx) {
            $sidx = \'%7$s\';
        }
        
        // calculate the number of rows for the query. We need this for paging the result
        $criteria = $this->_constructCriteria( $filters );
        $count = $this->dao->countByCriteriaIgnoreLimitAndOffset( $criteria );
        
        // calculate the total pages for the query
        if( $count > 0 ) {
            $total_pages = ceil($count/$limit);
        } else {
            $total_pages = 0;
        }
        
        // if for some reasons the requested page is greater than the total
        // set the requested page to total page
        if ($page > $total_pages) {
            $page=$total_pages;
        }
        
        // calculate the starting position of the rows
        $start = $limit*$page - $limit;
        
        // if for some reasons start position is negative set it to 0
        // typical case is that the user type 0 for the requested page
        if($start <0) {
            $start = 0;
        }

        // add paging and sorting criteria.
        $criteria->limit($limit)->
                   offset( $start )->
                   orderBy( array( $sidx => $sord ) );
        
        // Perform data retrieval query
        $rows = $this->dao->getByCriteria( $criteria );

        // setup response object.
        $response = new stdClass();
        $response->page = $page;
        $response->total = $total_pages;
        $response->records = $count;
        $response->rows = array();
        
        // fill response with result data.
        foreach( $rows as $row ) {
            $js_row = array();
%2$s
            $response->rows[] = $js_row;
        }

        if( $send_json ) {
            // json encode the result object.
            echo json_encode($response);
        }
        return $response;
    }
    
    protected function _constructCriteria($search){
        
        $criteria = $this->dbmf->daoCriteriaNew();
        
        if ($search) {
            $jsona = json_decode($search,true);
            if(is_array($jsona)){
                $gopr = $jsona[\'groupOp\'];
                $rules = $jsona[\'rules\'];
                $i =0;
                                
                foreach($rules as $key=>$val) {
                    $field = $val[\'field\'];
                    $op = $val[\'op\'];
                    $v = $val[\'data\'];
                    if($v && $op) {

                        if( $i > 0 ) {
                            if( $gopr == \'OR\' ) {
                                $criteria->or_();
                            }
                            else {
                                $criteria->and_();
                            }
                        }

                        $i++;

                        switch ($op) {
                            
                        case \'eq\': $criteria->equal( $field, $v ); break;
                        case \'ne\': $criteria->ne( $field, $v ); break;
                        case \'lt\': $criteria->lt( $field, $v ); break;
                        case \'le\': $criteria->lte( $field, $v ); break;
                        case \'gt\': $criteria->gt( $field, $v ); break;
                        case \'ge\': $criteria->gte( $field, $v ); break;
                        case \'bw\': $criteria->like( $field, $v . \'%%\' ); break;
                        case \'bn\': $criteria->not()->like( $field, $v .\'%%\' ); break;
                        case \'in\': $criteria->in( $field, explode( \',\', $v ) ); break;
                        case \'ni\': $criteria->not()->in( $field, explode( \',\', $v ) ); break;
                        case \'ew\': $criteria->like( $field, \'%%\' . $v ); break;
                        case \'en\': $criteria->not()->like( $field, \'%%\' . $v ); break;
                        case \'cn\': $criteria->like( $field, \'%%\' . $v . \'%%\'); break;
                        case \'nc\': $criteria->not()->like( $field, \'%%\' . $v . \'%%\' ); break;
                            
                        }
                    }
                }
            }
        }
        
        return $criteria;
    }

    /**
     * process an AJAX request.
     *
     * @param $send_json  if true, the function will output json string.
     * @return a php array containing the results.
     */
    public function processRequest( $send_json = true ) {
    
        try {
    
            if( @$_REQUEST[\'save\'] == 1) {
                $result = $this->processCRUD( $send_json );
            }
            else {
                $result = $this->processRead( $send_json );
            }
        }
        catch( Exception $e ) {
            $result = $this->sendError( $e, $send_json );
        }
        
        return $result;
    }

   /**
    * Sends an error message back to the client via json.
    *
    * This can be used for a caller to send unauthed user message, for example.
    *
    * @param Exception $e  an exception containing error string.
    */
    public function sendError( Exception $e, $send_json = true ) {

        // Send an error response.
        $response = new stdClass();
        $response->page = 0;
        $response->total = 0;
        $response->records = 0;
        $response->rows = array();
        $response->userdata = array( "error" => array( "code" => $e->getCode(), "message" => $e->getMessage() ) );

        if( $send_json ) {        
            // json encode the result object.
            echo json_encode($response);
        }
        
        return $response;
    }
    
   /**
    * process a CRUD (Create, Update, or delete) query
    *
    * @return bool    true, or throws an exception on error.
    */
    public function processCRUD()
    {
        // We do not currently support writing to tables that do
        // not have a primary key.
        $has_primary_key = %8$s;
        if( !$has_primary_key ) {
            throw new Exception( "Cannot modify table %1$s." );
        }
    
        // Determine requested operation.
        $action = @$_REQUEST["oper"];
        
        // Special case for cell editing.
        if( !$action && @$_REQUEST[\'id\'] ) {
            $action = \'edit\';
        }

        // handle edit action.
        if($action=="edit")
        {
            $dto = $this->dbmf->dtoNew( \'%1$s\' );
            $id = @$_REQUEST[\'id\'];
            
            $criteria = $this->dbmf->daoCriteriaNew();
             
%3$s
            $this->dao->updateByCriteria( $dto, $criteria );
        }

        // handle delete action.
        else if($action=="del")
        {
            //For Multiple Deletion
            $exploded_id = explode(\',\', @$_REQUEST[\'id\']);
 
            $dto = $this->dbmf->dtoNew( \'%1$s\' );
            
            foreach( $exploded_id as $id ) {
%4$s
                $this->dao->delete( $dto );
            }
        }

        // handle add action.
        else if($action =="add")
        {
            $dto = $this->dbmf->dtoNew( \'%1$s\' );
%5$s
            $this->dao->insert( $dto );
        }
        
        return true;
    }
}
';

      $pk_sep = "_-_";  // separates pk cols.  May consist of characters that are valid in an XML id attribute.

      $first_field = null;
      $pk_list = array();
      foreach( $info as $table_column ) {
          $first_field = $first_field ? $first_field : $this->col_safe_for_php( $table_column['Field'] );
          if( $table_column['Key'] == 'PRI' ) {
              $pk_list[] = $table_column['Field'];
          }
      }
      $pk_buf = sprintf( ' . "%s" . $row->', $pk_sep );
      $pk_id = '$row->' . implode( $pk_buf, $pk_list );


      $read_loop_mask = <<< END
            \$js_row['cell'][] = \$row->%1\$s;

END;

      $edit_mask = <<< END
            \$dto->%1\$s = @\$_REQUEST["%1\$s"];

END;

      $add_mask = <<< END
            \$dto->%1\$s = @\$_REQUEST["%1\$s"];

END;


      $read_loop_buf = count($pk_list) == 0 ? '' : "
            \$js_row['id'] = $pk_id;
";
      $edit_buf = $add_buf = $del_buf = $id_buf = '';


      if( count($pk_list) == 1 ) {
         $edit_buf = sprintf( '#INDENT#$criteria->equal(\'%s\', $id);', $pk_list[0] ) . "\n";
         $id_buf = sprintf( '#INDENT#$dto->%s = $id;', $this->col_safe_for_php( $pk_list[0] ) ) . "\n";
      }
      else if( count($pk_list) > 1 ) {
         $id_buf = $edit_buf = '#INDENT#$pk_cols = explode("_-_", $id);' . "\n";
         $cnt = 0;
         foreach( $pk_list as $pk_col ) {
            if( $cnt ) {
               $edit_buf .= "#INDENT#\$criteria->and_();\n";
            }
            $edit_buf .= sprintf( '#INDENT#$criteria->equal(\'%s\', $pk_cols[%s]);', $pk_col, $cnt ) . "\n";
            $id_buf .= sprintf( '#INDENT#$dto->%s = $pk_cols[%s];', $this->col_safe_for_php( $pk_col ), $cnt ++ ) . "\n";
         }
      }
      
      // fix indent for edit_buf and del_buf;
      $del_buf = str_replace( '#INDENT#', '                ', $id_buf );
      $edit_buf = str_replace( '#INDENT#', '            ', $edit_buf );

      foreach( $info as $table_column ) {

         $php_safe_field = $this->col_safe_for_php( $table_column['Field'] );
          
         $read_loop_buf .= sprintf( $read_loop_mask, $php_safe_field );
         
         if( count( $pk_list ) ) {
            $edit_buf .= sprintf( $edit_mask, $php_safe_field );
            $add_buf .= sprintf( $add_mask, $php_safe_field );
         }
      }                     

      $buf = sprintf( $mask, $table, $read_loop_buf, $edit_buf, $del_buf, $add_buf, $this->crud_php_processor_base_classname( $table ), $first_field, count($pk_list) > 0 ? 'true' : 'false', $this->namespace_prefix, $this->sys_quote_char );

      $filename_crud_php = $this->crud_php_processor_base_filename( $table );
      $pathname_crud_php = $this->crud_server_dir . $filename_crud_php;
      $this->log( sprintf( "Writing %s", $pathname_crud_php ),  __FILE__, __LINE__, self::log_info );
      $rc = @file_put_contents( $pathname_crud_php, $buf );
      if( !$rc ) {
          throw new Exception( "Error writing file " . $pathname_crud_php );
      }
      

      $mask = <<< END
      
END;
      // Write user-modifable processor class.
      
      $mask = '<?php

/**
 * db_extractor crud processor wrapper for table (%1$s).
 *
 * This file was auto-generated (once) and is intended
 * for manual modifications/extensions.
 *
 * MANUAL CHANGES IN THIS FILE WILL BE PRESERVED DURING SUBSQUENT RUNS.
 * 
 */
 
require_once( dirname(__FILE__) . \'/%4$s\' );

class %2$s extends %3$s {

    // Add additional functionality here.
    
}
';
      
      $buf = sprintf( $mask, $table, $this->crud_php_processor_classname( $table ), $this->crud_php_processor_base_classname( $table ), $this->crud_php_processor_base_filename( $table ), $this->namespace_prefix );

      $filename_crud_php = $this->crud_php_processor_filename( $table );
        $pathname_crud_php = $this->crud_server_dir . $filename_crud_php;

      if( file_exists( $pathname_crud_php )) {
        $this->log( sprintf( "Ignoring existing modifiable file %s", $pathname_crud_php),  __FILE__, __LINE__, self::log_warning );
      }
      else  {
        $this->log( sprintf( "Writing %s", $pathname_crud_php ),  __FILE__, __LINE__, self::log_info );
        $rc = @file_put_contents( $pathname_crud_php, $buf );
        if( !$rc ) {
            throw new Exception( "Error writing file " . $pathname_crud_php );
        }
      }
      
   }

   function relpath( $full_path, $root_path ) {
      $full_path = realpath( $full_path );
      $root = realpath( $root_path ) . '/';
      
      return str_replace( $root, '', $full_path );
   }

   function make_relpath( $from_path, $to_path ) {
      $from_parts = explode( '/', realpath( $from_path ) );
      $to_parts = explode( '/', realpath( $to_path ) );
      $num_to = count($to_parts);
      $cnt = 0;
      $chop_parts = array();
      foreach( $from_parts as $f_part ) {
        if( $cnt == $num_to || $f_part != $to_parts[$cnt] ) {
            break;
        }
        $chop_parts[] = $f_part;
        
        $cnt ++;
      }
      $buf = '';
      $depth = count($from_parts) - $cnt;
      for( $i = 0; $i < $depth; $i++ ) {
        $buf .= '../';
      }
      
      $chop_str = '/' . implode( '/', $chop_parts );
      $buf .= substr( realpath($to_path), strlen( $chop_str ) );
      
      return $buf;
   }


}

 
/**
 * This will initialize strict mode.  It is safe to be called multiple times per process
 * eg in the event that a 3rd party lib overrides an error or exception handler.
 *
 * It is called in this file; the parent php file(s) should use require_once and do
 * not need to make any call.
 */
function init_strict_mode() {

    // these are safe to call multiple times per process without dups.
    error_reporting( E_ALL | E_STRICT );
    restore_strict_error_handler();
    restore_strict_exception_handler();
   
    // register_shutdown_function should only be called once per process to avoid dups.
    static $called = false;
    if( !$called ) {
    
        register_shutdown_function( "shutdown_handler" );
        $called = true;
    }
}

/**
 * This function restores the error handler if it should get overridden
 * eg by a 3rd party lib.  Any error handlers that were registered after
 * ours are removed.
 */
function restore_strict_error_handler() {
    
    $e_handler_name = function() {
        $name = set_error_handler('restore_strict_error_handler');  // will never be used.
        restore_error_handler();
        return $name;
    };
    
    while( !in_array( $e_handler_name(), array( '_global_error_handler', null ) ) ) {
        restore_error_handler();
    }
    if( !$e_handler_name() ) {
        set_error_handler( '_global_error_handler' );
    }
}

/**
 * This function restores the exception handler if it should get overridden
 * eg by a 3rd party lib.  Any error handlers that were registered after
 * ours are removed.
 */
function restore_strict_exception_handler() {

    $exc_handler_name = function() {
        $name = set_exception_handler('restore_strict_exception_handler'); // will never be used.
        restore_exception_handler();
        return $name;
    };
    
    while( !in_array( $exc_handler_name(), array( '_global_exception_handler', null ) ) ) {
        restore_exception_handler();
    }
    if( !$exc_handler_name() ) {
        set_exception_handler( '_global_exception_handler' );
    }
}

/***
 * This error handler callback will be called for every type of PHP notice/warning/error.
 * 
 * We aspire to write solid code. everything is an exception, even minor warnings.
 *
 * However, we allow the @operator in the code to override.
 */
function _global_error_handler($errno, $errstr, $errfile, $errline ) {
    
    /* from php.net
     *  error_reporting() settings will have no effect and your error handler will
     *  be called regardless - however you are still able to read the current value of
     *  error_reporting and act appropriately. Of particular note is that this value will
     *  be 0 if the statement that caused the error was prepended by the @ error-control operator.
     */
    if( !error_reporting() ) {
        return;
    }

    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}


/***
 * This exception handler callback will be called for any exceptions that the application code does not catch.
 */
function _global_exception_handler( Exception $e ) {
    $msg = sprintf( "\nUncaught Exception. code: %s, message: %s\n%s : %s\n\nStack Trace:\n%s\n", $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString() );
    while( ( $e = $e->getPrevious() ) ) {
        $msg .= sprintf( "\nPrevious Exception. code: %s, message: %s\n%s : %s\n\nStack Trace:\n%s\n", $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString() );
    }
    echo $msg;
    // error_log( $msg );
    strict_mode_mail_admin( 'Uncaught exception!', $msg );
    echo "\n\nNow exiting.  Please report this problem to the software author\n\n";
    exit(1);
}

/**
 * This shutdown handler callback prints a message and sends email on any PHP fatal error
 */
function shutdown_handler() {

  $error = error_get_last();

  $ignore = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_STRICT | E_DEPRECATED | E_USER_DEPRECATED;
  if ( $error && ($error['type'] & $ignore) == 0) {

    // error keys: type, file, line, message
    $msg = "Ouch! Encountered PHP Fatal Error.  Shutting down.\n" . print_r( $error, true );
    echo $msg;
    strict_mode_mail_admin( 'PHP Fatal Error!', $msg );
  }
}

/**
 * email admin if defined
 */
function strict_mode_mail_admin( $subject, $msg ) {
    $subject = sprintf( '[%s] [%s] %s [pid: %s]', gethostname(), basename($_SERVER['PHP_SELF']), $subject, getmypid() );
    if( defined('ALERTS_MAIL_TO') ) {
       mail( ALERTS_MAIL_TO, $subject, $msg );
    }
    else {
        echo "WARNING: ALERTS_MAIL_TO not defined in config.  alert not sent with subject: $subject\n";
    }
}


?> 
