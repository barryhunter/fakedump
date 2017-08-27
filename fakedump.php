<?php
 /*
Rather rudimentry 'mysqldump' replacement, that takes an arbitary query, so can dump virtual tables, even views (with their data!)

... great for filtering (by rows OR by columns!) or even creating new virtual tabels from joins/group-by's etc.

Known Limiations
* Multibyte (UTF8 etc!) hasnt been tested!?
* doesnt deal propelly with locks, collations, timezones and version compatiblity etc that mysqldump does.
* can only dump ONE table at a time!
* does not support either extended or complete inserts (like mysqldump does), nor 'replace into'
* becauase uses a temp table to create schema:
  * the ENGINE will be the database default - but can use [-e myisam] to override
  * this does mean will be no indexes included, but can use [-i 'primary(table_id)'] to override
  * also means no AUTO_INCREMENT=x


 * This file copyright (C) 2017 Barry Hunter (github@barryhunter.co.uk)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

$start = microtime(true);

######################################
# defaults

$p = array(
	'schema'=>true,
	'data'=>true,
	'lock'=>false,

		//query to run (can be something as simple as "select * from aview")
		'select' => "select gridimage_id,user_id,realname,title from gridimage_search limit 100",

		//the table to CALL the output, doesnt have to exist (leave blank to use teh first table from the 'select')
		'table' => "gridimage_base",

		//optionally define any indexes want (in CREATE TABLE syntax)
		//'i' => " PRIMARY KEY(gridimage_id) ",

	'limit'=>10, //only used if $select is changed
);

//what type of query to run!
$func = (true)?'mysql_query':'mysql_unbuffered_query';

######################################
# basic argument parser! (somewhat mimic mysqldump, unnamed params are 'magic')

if (count($argv) > 1) {
	$s=array();
	for($i=1;$i<count($argv);$i++) {
		if (strpos($argv[$i],'-') === 0) {
			if (preg_match('/^-+(\w+)=(.+)/',$argv[$i],$m) || preg_match('/^-(\w)(.+)/',$argv[$i],$m)) {
				$key = $m[1];
				$value = $m[2];
			} else {
				$key = trim($argv[$i],' -');
				$value = $argv[++$i];
			}
			$p[$key] = $value;
		} elseif (is_numeric($argv[$i])) {
			$p['limit'] = $argv[$i];
		} else {
			$s[] = $argv[$i];
		}
	}
	if (!empty($p['h']) || !empty($p['u'])) {
		$db = mysql_connect($p['h'],$p['u'],$p['p']) or die("unable to connect\n".mysql_error()."\n");
		if (count($s))
			mysql_select_db(array_shift($s),$db);
	}
	if (!empty($s)) {
		$p['table'] = ''; //leave to be autodetected below!
		//$p['i'] =  //todo could do this automatically, look for indexes on the columns in $table (via describe etc) also check no groupby!
		while (!empty($s) && ($value = array_pop($s))) {
			if (preg_match('/^\w+$/',$value)) {
				$p['table'] = $value;
				$p['select'] = "select * from `{$p['table']}` limit {$p['limit']}";
			} else
				$p['select'] = $value;
		}
	}
} else {
	die("
Usage:
php fakedump.php [-hhost] [-uuser] [-ppass] [database] [query] [table] [limit] [-i 'primary key(table_id)'] [--data=0] [--schema=0] [--lock=1] [-e=myisam]

Examples:
php fakedump.php -utest database table 100
  # 100 rows from table
php fakedump.php -hmaster.domain.com -utest -psecret database \"select * from table where title != 'Other'\" output
  # runs the full query against a specific database (no auto limit!)

");
}

if (empty($p['table']))
	if (preg_match('/\sfrom\s+(`?\w+`?\.)?`?(\w+)`?\s+/i',$p['select'],$m))
		$p['table'] = $m[2];

if (!empty($p['d'])) {
	print_r($p);
	exit;
}

######################################

if (empty($db)) {
	$db = mysql_connect("localhost",'root','');
	mysql_select_db('test',$db);
}

print "-- ".date('r')."\n\n";

######################################
# schema

if (!empty($p['schema'])) {
	print "-- dumping schema --\n\n";

	print "DROP TABLE IF EXISTS `{$p['table']}`;\n";

	// use a trick of a temporally table with limit 0 - so mysql creates the right schema automatically!

	$extra = '';
	$select0 = preg_replace('/(\s+limit\s+\d+\s*,?\s*\d*|\s+$)/i',' limit 0',$p['select']);
	if (!empty($p['i'])) $extra = "({$p['i']})";
	if (!empty($p['e'])) $extra .= " ENGINE={$p['e']}";
	$create = "create TEMPORARY table `{$p['table']}` $extra $select0";

	$result = mysql_query($create) or die("unable to run $create;\n".mysql_error()."\n\n");

	$result = mysql_query("SHOW CREATE table `{$p['table']}`") or die("unable to run $create;\n".mysql_error()."\n\n");
	$row = mysql_fetch_assoc($result);
	$create_table = str_replace('CREATE TEMPORARY TABLE','CREATE TABLE',$row['Create Table']);

	print "$create_table;\n\n";

	$result = mysql_query("drop TEMPORARY table `{$p['table']}`");
}

######################################
# data

if (!empty($p['data'])) {

	if (!empty($p['lock'])) mysql_query("LOCK TABLES `{$p['table']}` READ"); //todo this actully needs extact the list of tableS from $select!

	$result = $func($p['select']) or die("unable to run {$p['select']};\n".mysql_error()."\n\n");

	print "-- dumping ".mysql_num_rows($result)." rows\n\n";

	$types=array();
	$fields = mysql_num_fields($result);
	for ($i=0; $i < $fields; $i++) {
		$types[$i] = mysql_field_type($result,$i);
	}
	//print_r($types);
	while($row = mysql_fetch_row($result)) {
		print "INSERT INTO `{$p['table']}` VALUES (";
		$sep = '';
		foreach($row as $idx => $value) {
			if (is_null($value))
				$value = 'NULL';
			elseif ($types[$idx] != 'int' && $types[$idx] != 'real') //todo maybe add is_numeric to this criteria?
				$value = "'".mysql_real_escape_string($value)."'";
			print "$sep$value";
			$sep = ',';
		}
		print ");\n";
	}

	if (!empty($p['lock'])) mysql_query("UNLOCK TABLES");
}

######################################
$end = microtime(true);

printf("\n-- done in %0.3f seconds\n\n",$end-$start);
