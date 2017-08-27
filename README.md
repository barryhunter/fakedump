== fakedump == 

Rather rudimentry 'mysqldump' replacement, that takes an arbitary query, so can dump virtual tables, even views (with their data!)

... great for filtering (by rows OR by columns!) or even creating new virtual tabels from joins/group-by's etc.


=== Known Limitations ===

* Multibyte (UTF8 etc!) hasnt been tested!?
* doesnt deal propelly with locks, collations, timezones and version compatiblity etc that mysqldump does.
* can only dump ONE table at a time!
* does not support either extended or complete inserts (like mysqldump does), nor 'replace into'
* becauase uses a temp table to create schema:
  * the ENGINE will be the database default - but can use [-e myisam] to override
  * this does mean will be no indexes included, but can use [-i 'primary(table_id)'] to override
  * also means no AUTO_INCREMENT=x


== Usage == 

php fakedump.php [-hhost] [-uuser] [-ppass] [database] [query] [table] [limit] [-i 'primary key(table_id)'] [--data=0] [--schema=0] [--lock=1] [-e=myisam]

Examples:
php fakedump.php -utest database table 100
  # 100 rows from table
php fakedump.php -hmaster.domain.com -utest -psecret database "select * from table where title != 'Other'" output
  # runs the full query against a specific database (no auto limit!)

