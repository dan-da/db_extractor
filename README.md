# db_extractor

The DB extractor is a standalone script that can extract schema information from
any mysql or postgresql DB to generate PHP data model classes. It can also
generate HTML UI pages with a jquery grid for full CRUD operations.

Usage is pretty simple. Normally you will just specify the database parameters,
and possibly output directories.

By default, the model classes will go into ./db_model and the crud (ui) classes
will go into ./db_crud. These directories will be created if not already
existing.

The extractor will overwrite *.dao.base.php, *.dto.base.php, daoBase.php, and
daoCriteria.php. It will also overwrite all files in db_crud.

These files inherit from the above and will NOT be touched if pre-existing:

 *.dao.php, *.dto.php, *.custom.dao.php, *.custom.dto.php.
 
This allows for application customizations to be placed in the inherited files
and it will not be overwritten when the script is run and the model is
refreshed.

# What's the point?

The point is to simplify database access and create a consistent methodology for
accessing any table in the database.

Here's a simple code example to demonstrate how an application would use these
classes.

```php
<?php

require_once( dirname(__FILE__) . "/db_model/dbFactory.php" );

// Let's create, update, retrieve, and then delete a User row.

$dto_user = dbFactory::dbmf()->dtoNew( 'user' );

// populate the new record.
$dto_user->user_id = null;    // leave the PK blank. it will be auto-generated
$dto_user->username = 'santaclaus';
$dto_user->email = 'nick@northpole.com';
$dto_user->fname = 'Santa';
$dto_user->lname = 'Claus';
$dto_user->password_hash = md5( 'some secret' );

$dao_user = dbFactory::dbmf()->daoInstance( 'user' );

// 1. Create.  note: $dto_user->user_id will be set here.
$dao_user->save( $dto_user );

// 2. Update.  (It will be an update because PK user_id is specified.)
$dto_user->email = 'santaclaus@northpole.com';
$dao_user->save( $dto_user );

// 3. Query the record.
$dto_user = $dao_user->getByPk( $id );
print_r( $dto_user );

// 4. Delete. 
$dao_user->delete( $dto_user );

```

Output should look similar to:

```
user_dto Object
(
    [user_id] => 3
    [username] => santaclaus
    [email] => santaclaus@northpole.com
    [password_hash] => 689f843c767642541d62a2289f764524
    [fname] => Santa
    [lname] => Claus
)
```

Hopefully that's enough to whet your appetite.  See the examples directory
to get started and for examples of using the DaoCriteria object to
programatically construct select ... from ... where queries.


# Running the Script

Whenever a database table is added or altered, the db_extractor script should
be run to update the associated db_model classes.

It is recommended to create a wrapper shell script for convenience that calls
db_extractor.php with appropriate params for accessing your DB, etc.

```
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

```

# Using the generated classes.

## dbFactory

This abstract/static factory class should be provided by your application.
It is not necessary, but it makes it much more convenient to access the
database model from anywhere in the code with requiring object instantiation
or config parameters.

An example dbFactory class is provided, intended for customization.

This class provides efficient, convenient access to the dbModelFactory via
a static method.  Most commonly used are:

   // return a DAO.  ( represents a table )
   dbFactory::dbmf()->daoInstance($table_name)
   
   // instantiate a new DTO ( represents a table row )
   dbFactory::dbmf()->dtoNew( $table_name )
   

## dbModelFactory

This factory class is auto-generated and used internally by dbFactory.  Or it
can be instantiated manually within your app.

## Data Access Objects (DAO )

DAO objects contain the SQL queries.  Auto generated classes are created
for each table. Additionally, custom user classes can be added that
perform queries that are not necessarily tied to a given table, eg complex
joins or stored proc calls.

The structure looks like:

 dao          <---- one file per table.  user extensible.
   /base      <---- one file per table.  do not touch.
   /custom    <---- whatever random queries you like.


Any queries that fit into single-table model should be placed directly
into the appropriate DAO class (db_model/dao).  A query fits into the
single-table model if it is a single-table query, or is a join query that is
mostly "about" a single table.

All other queries should be placed into a custom DAO. (dao/custom)

All DAO objects should accept and return DTO objects as params and
results.

A DAO object representing a single table can easily be instantiated from
anywhere by calling:
   dbFactory::dbmf()->daoInstance( $table )

A custom DAO object can easily be instantiated from anywhere by calling:
   dbFactory::dbmf()->daoCustomNew( $dtoName )    


## Data Transfer Objects (DTO)

DTO are the primary method of encapsulating data in this db_model.
They are used to represent SQL result rows where we would have used
associative arrays in legacy code.

Both db_adapters and DAO should instantiate DTO objects for
each row in a resultset.  If an appropriate DTO object does not
exist for the query, then it should be created.

A DTO object representing a single table can easily be instantiated from
anywhere by calling:
   dbFactory::dtoNew( $table )

A custom DTO object can easily be instantiated from anywhere by calling:
   dbFactory::dtoCustomNew( $dtoName )
   

# Description of generated classes

## Auto Generated DB Classes

### dbModelFactory

This factory class provides a simplified way to access or instantiate DAO and DTO objects.

(In PV, this class is not used directly, but rather via a static wrapper, dbFactory.)

### [table]_dto_base

These abstract classes are automatically generated and should never be manually modified, and cannot be directly instantiated.  They provide a public attribute for each column in a table, and each object can contain data for one DB row.

### [table]_dto

These classes are automatically generated and are intended to be manually modified. If the class file already exists, it will be ignored by the generator.  These classes inherit from <table>_dto_base, and it is these wrapper classes that are intended to be instantiated.  

### [table]_custom_dto

These classes are not generated. Rather they are intended to be written by hand and placed in the dto/custom directory.  These classes provide a place for custom types that match an SQL query, but do NOT match any table.


### daoBase

This is a common base class that all dao classes inherit from.  It manages the reference to the Dal db connection and provides some common dao methods.

### [table]_dao_base

These abstract classes are automatically generated and should never be manually modified, and cannot be directly instantiated.  They provide basic methods for SQL insert, delete, update, and select operations.

### [table]_dao

These classes are automatically generated and are intended to be manually modified. If the class file already exists, it will be ignored by the generator.  These classes inherit from [table]_dao_base, and it is these wrapper classes that are intended to be instantiated.

### [table]_custom_dao

These classes are not generated. Rather they are intended to be written by hand and placed in the dao/custom directory.  These classes provide a place for custom dao containing queries that do not fit the single-table model well. examples include calls to stored procedures or queries with complex joins, eg reports.

### daoCriteria

This class is used to programatically define SQL clauses including WHERE, ORDER BY, GROUP BY, LIMIT and OFFSET.  In particular, the WHERE clause support is nice, because it provides support for all the major SQL operators and conditionals eg:  or_(), and_(), equals(), greaterthan(), etc.

The class supports single table queries only.  It does not currently support queries that involve joins.  For this, you might consider defining a view inside the database, or creating a custom query in your [table]_dao class.

The daoCriteria class can be passed to the following methods of every dao object:

* ::getByCriteria()
* ::updateByCriteria()
* ::deleteByCriteria()

In the case of update or delete, only the WHERE clause criteria will be used.  In the case of select, all specified criteria are used.

The recommended way to instantiate daoCriteria is with dbModelFactory()->daoCriteriaNew(), or in PV with dbFactory::daoCriteriaNew().

See example below.

## Auto Generated CRUD Classes

### crudSetup

Implemented in db_crud/server/config/crudSetup.php

The crudSetup.php file is auto-generated by the extractor with the db params used to generated the model. So everything should work out-of-the box after generation.  However, this file should be modified to suit the individual application.  The key requirement is that it must return a useable dbModelFactory object (and corresponding DAL).

For example, in PV we have modified it to use the already instantiated dbModelFactory and DAL objects.

By default, this file will also look for [http://www.firephp.org/ FirePHP] in its PEAR location, and the crud and the CRUD and DAL layers will use it if available.


### [table]Proc

Implemented in db_crud/server/[table].proc.php.  

These classes implement an ajax processor for a single table.  The classes are intended for re-use and can be used from any top-level controller.

### [table].ctl.php

Implemented in db_crud/server/[table].ctl.php

These files implement a very simple ajax controller for a single table. These controllers instantiate the appropriate processor class, send utf-8 headers, and not much more.

### [table].html

Implemented in db_crud/client/[table].html

These files implement a simple ajax client that displays a jqGrid for a single table.  These files are intended for use as:

* simple DB administrative interface for developers.  Note: the db_crud directory should be .htaccess protected in production.
* templates for creating more interesting user-facing features.  (in a separate directory!)


## Quick Start and Code Examples

See the examples directory for step-by-step instructions to create a simple
database for mysql or postgresql and sample code to query and manipulate it.


## Advice and Tips

### Joining multiple tables

Perhaps the biggest question that the author of a new dbAdapter may have is: "where do I put queries that join multiple tables?"

#### Easiest Solution

If you only need read-only access to the query, and performance is not critical, consider adding a mysql view for the query.  Then you can simply re-run the db_extractor and you will have a full set of classes (even jqGrid UI) for the query.  You just won't be able to update it.

Just be aware that mysql's view implementation is pretty inefficient and you will likely have lousy performance for any complex joins.  Check the mysql manual about views.

#### Manual Solution

If the query is *MOSTLY* about a single table, then put it in the DAO for that table. But be sure to create a custom DTO for the results.  Otherwise, create a custom DAO and a custom DTO.

For example, when working with Creatives, I may need to return information from Adgroup, Campaign, and Product tables.  But the bulk of the information is still about a Creative.  so it goes in the creative DAO.

If there is a many-to-many join, then there may already be an intermediate table that represents the relationship, so the query could go there.

Also keep in mind that it is easy for a particular application controller to mix and match multiple DB adapters.  So there's no need to group unrelated queries together into the same DAO.

### Bulding new features that use the CRUD files

When building a new feature, it would be nice to re-use the CRUD processor(s).  But where do the HTML file and controller go?

#### Answer

Somewhere outside the db_crud (durc) directory.


