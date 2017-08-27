# fakedump

Rather rudimentry 'mysqldump' replacement, that takes an arbitary query, so can dump virtual tables, even views (with their data!)

... great for filtering (by rows OR by columns!) or even creating new virtual tables from joins and with group-by's etc.

**Basically it's for dumping tables that don't *really* exist!**

## Known Limitations

* Multibyte (UTF8 etc!) hasnt been tested!?
* doesnt deal propelly with locks, collations, timezones and version compatiblity etc that mysqldump does.
* can only dump ONE table at a time!
* does not support either extended or complete inserts (like mysqldump does), nor 'replace into'
* becauase uses a temp table to create schema:
  * the ENGINE will be the database default - but can use [-e myisam] to override
  * this does mean will be no indexes included, but can use [-i 'primary(table_id)'] to override
  * also means no AUTO_INCREMENT=x


## Usage

    php fakedump.php [-hhost] [-uuser] [-ppass] [database] [query] [table] [limit] [-i indexes] [--data=0] [--schema=0] [--lock=1] [-e=myisam]

* [-hhost] [-uuser] [-ppass] [database] - speciy datbase to connect to, somewhat mimicing mysqldump syntax

* [query] is the main workhorse, specify a full mysql query like "SELECT * FROM table" - but importantly its a full query, so can select what columns, rows etc to include. 
(ie can SELECT id,title,SUBSTRING(created,1,10),... FROM, use JOINS, a WHERE clause, even GROUP BY and ORDER BY)

  (if leave undefined, will run a "SELECT * FROM [table] LIMIT [limit]" as a quick shortcut)

* [table] is what to call the table in the final dump - it can be a name that doesnt exist in source databaes!

  (if leave undefined, will attempt to use the name of hte first table in the [select])

* [limit] if just specify a table (no query) will automatically add this limit!

* [-i indexes] allows adding new indexes to the CREATE in the dump an example would be [-i 'primary key(table_id)']

* [--data=0] [--schema=0] can optionally turn off dumping of data and/or schema seperatly

* [--lock=1] can enable locking during dump, but not really tested. for dumping a single table probably doent make sence anyway

* [-e=myisam] can optionally specify an engine to use in output, normally will be the source databases 'default' engine. 


## Examples

quick 100 rows from table (in no particular order!)

    php fakedump.php -utest database table 100

runs the full query against a specific database (no auto limit!)

    php fakedump.php -hmaster.domain.com -utest -psecret database "select * from table where title != 'Other'" output

## See also

https://github.com/hgfischer/mysqlsuperdump

* kinda similar, but found after started on this. And also have more complex requirements, want to omit columns, and even create tables via JOINs

http://data.geograph.org.uk/dumps/

* The reason this script was created!


