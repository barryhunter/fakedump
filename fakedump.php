#!/usr/bin/php
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

//using unbuffered_query means the app is less likely to be killed oom!
$options = MYSQLI_USE_RESULT;

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
		$dbname = (count($s))?array_shift($s):null;
		$db = mysqli_connect($p['h'],$p['u'],$p['p'],$dbname) or die("unable to connect\n".mysqli_error($db)."\n");
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
	$db = mysqli_connect("localhost",'root','','test');
}

print "-- ".date('r')."\n\n";

######################################
# maker

if (!empty($p['make'])) {

	$select0 = preg_replace('/(\s+limit\s+\d+\s*,?\s*\d*|\s+$)/i',' limit 1',$p['select']." ");

        $result = mysqli_query($db,$select0) or die("unable to run {$p['select']};\n".mysqli_error($db)."\n\n");

	$names= array();
        $fields = mysqli_fetch_fields($result);
	foreach ($fields as $key => $obj) {
		$names[] = $obj->name;
print_r($fields);

	print "\n\nSELECT ".implode(',',$names))." FROM {$p['table']}\n\n";

	exit;
}

######################################
# schema

if (!empty($p['schema'])) {
	print "-- dumping schema --\n\n";

//TODO - bodge (really should be a config option, its used here for the 'by myriad' breakdown, so can import multiple myriads
if (strpos($p['select'],'grid_reference LIKE')) {
} else {
	print "DROP TABLE IF EXISTS `{$p['table']}`;\n";
}

	// use a trick of a temporally table with limit 0 - so mysql creates the right schema automatically!

	$extra = '';
	$select0 = preg_replace('/(\s+limit\s+\d+\s*,?\s*\d*|\s+$)/i',' limit 0',$p['select']." ");
	if (!empty($p['i'])) $extra = "({$p['i']})";
	if (!empty($p['e'])) $extra .= " ENGINE={$p['e']}";
	$create = "create TEMPORARY table `{$p['table']}` $extra $select0";

	//small test, to to be able to dump enums as numeric
	$create = preg_replace("/(\w+)\+0/",'$1',$create);

	$result = mysqli_query($db,$create) or die("unable to run $create;\n".mysqli_error($db)."\n\n");

	$result = mysqli_query($db,"SHOW CREATE table `{$p['table']}`") or die("unable to run $create;\n".mysqli_error($db)."\n\n");
	$row = mysqli_fetch_assoc($result);
//TODO - bodge
if (strpos($p['select'],'grid_reference LIKE')) {
	$create_table = str_replace('CREATE TEMPORARY TABLE','CREATE TABLE IF NOT EXISTS',$row['Create Table']);
} else {
	$create_table = str_replace('CREATE TEMPORARY TABLE','CREATE TABLE',$row['Create Table']);
}
	print "$create_table;\n\n";

	$result = mysqli_query($db,"drop TEMPORARY table `{$p['table']}`");
}

######################################
# data

if (!empty($p['data'])) {

	if (!empty($p['tsv'])) {
		$h = gzopen($p['tsv'],'w');
	}


	if (!empty($p['lock'])) mysqli_query($db,"LOCK TABLES `{$p['table']}` READ"); //todo this actully needs extact the list of tableS from $select!

	$result = mysqli_query($db,$p['select'],$options) or die("unable to run {$p['select']};\n".mysqli_error($db)."\n\n");

	print "-- dumping ".(empty($options)?mysqli_num_rows($result):'all')." rows\n\n";

	$names=array();
	$types=array();
	$fields=mysqli_fetch_fields($result);
	foreach ($fields as $key => $obj) {
		$names[] = $obj->name;
		switch($obj->type) {
	                case MYSQLI_TYPE_INT24 :
        	        case MYSQLI_TYPE_LONG :
                	case MYSQLI_TYPE_LONGLONG :
	                case MYSQLI_TYPE_SHORT :
        	        case MYSQLI_TYPE_TINY :
				$types[] = 'int'; break;
			case MYSQLI_TYPE_FLOAT :
			case MYSQLI_TYPE_DOUBLE :
			case MYSQLI_TYPE_DECIMAL :
				$types[] = 'real'; break;
			default:
				$types[] = 'other'; break; //we dont actully care about the exact type, other than knowing numeric
		}
	}

	if (!empty($p['tsv'])) {
		gzwrite($h,implode("\t",$names)."\n");

		//alas php doesnt have a single function for
		//   Newline, tab, NUL, and backslash are written as \n, \t, \0, and \\.
		function escape_tsv($in) {
			return addcslashes(str_replace("\r",'',$in),"\\\n\t\0");
		}
	}

	while($row = mysqli_fetch_row($result)) {
		print "INSERT INTO `{$p['table']}` VALUES (";
		$sep = '';
		foreach($row as $idx => $value) {
			if (is_null($value))
				$value = 'NULL';
			elseif ($types[$idx] != 'int' && $types[$idx] != 'real') //todo maybe add is_numeric to this criteria?
				$value = "'".mysqli_real_escape_string($db,$value)."'";
			print "$sep$value";
			$sep = ',';
		}
		print ");\n";
		if (!empty($p['tsv'])) {
			$row = array_map('escape_tsv',$row); //mimik what mysql client does, by escaping these chars, works with mysqlimport!
         	        gzwrite($h,implode("\t",$row)."\n");
	        }
	}

	if (!empty($p['lock'])) mysqli_query($db,"UNLOCK TABLES");
}

######################################
$end = microtime(true);

printf("\n-- done in %0.3f seconds\n\n",$end-$start);
