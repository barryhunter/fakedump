#!/usr/bin/php
<?php
 /*
Works like 'mysqldump' but for indexes in Manticore/Sphinx

... useful in theory for a logical backup of a ManticoreSearch or SphinxSearch Index. Most useful for RT indexes, but can in theory dump stored fields and attributes from even plain indexes.

Known Limiations
* Multibyte (UTF8 etc!) hasn't been tested!?
* doesn't deal propelly with locks, collations, timezones and version compatiblity etc
* can only dump ONE index at a time!
* does not support either extended or complete inserts (like mysqldump does), nor 'replace into'
* creating fake a 'CREATE TABLE' command for the index is rudimentry. does not correctly deal with all combinations
* if specing a limit to only dump some rows, then must be <=1000 (max_matches!), larger values doesn't work yet
* NOT tested with percolate indexes

***********************************************

 * This file copyright (C) 2022 Barry Hunter (github@barryhunter.co.uk)
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
	'schema'=>true, //or set to 'mysql' to get a 'fake' mysql-compatible CREATE TABLE! (to insert data into database)
	'data'=>true,
	'lock'=>0,

	'P'=> 9306,

		//query to run (can be something as simple as "select * from index")
		'select' => "select * from index1",

		//the table to CALL the output, doesnt have to exist (leave blank to use teh first table from the 'select')
		'table' => "index1",

	'limit'=>false, //only used if $select is changed
);

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
		$db = mysqli_connect($p['h'].':'.$p['P'],'','','') or die("unable to connect\n".mysqli_error($db)."\n");
	}
	if (!empty($s)) {
		$p['table'] = ''; //leave to be autodetected below!
		//$p['i'] =  //todo could do this automatically, look for indexes on the columns in $table (via describe etc) also check no groupby!
		while (!empty($s) && ($value = array_pop($s))) {
			if (preg_match('/^\w+$/',$value)) {
				$p['table'] = $value;
				$p['select'] = "select * from `{$p['table']}`"; // limit {$p['limit']}"; //limit added later!
			} else
				$p['select'] = $value;
		}
	}
} else {
	die("
Usage:
php indexdump.php [-hhost] [-uuser] [-ppass] [query] [table] [limit] [--data=0] [--schema=0] [--lock=1]

Examples:
php indexdump.php index 100
  # 100 rows from index
php indexdump.php -hmaster.domain.com \"select * from table where title != 'Other'\"
  # runs the full query against a specific index - dumping all rows. - you CAN use MATCH(..)
  # NOTE: should NOT add ORDER BY nor LIMIT to the query, as this script needs to add them to dump all rows (because of max_matches) 
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
	$db = mysqli_connect("localhost:{$p['P']}",'','','');
}

print "-- ".date('r')."\n\n";

$mvas = array();

######################################
# fake schema for mysql

if ($p['schema'] === 'mysql') {
	print "-- dumping mysql compatible schema\n\n";
        $postfix = " LIMIT 1000"; //not ideall, but to to get LOTS of rows so as get string lengths!

        $result = mysqli_query($db,$p['select'].$postfix) or die("unable to run {$p['select']}$postfix;\n".mysqli_error($db)."\n\n");

        $fields=mysqli_fetch_fields($result);

	print "CREATE TABLE `{$p['table']}` (\n"; $sep = '';
        foreach ($fields as $key => $obj) {
		print "$sep";
                switch($obj->type) {
			//we make assumptons that ints are unsigned (as they are in sphinx), except bigint
                        case MYSQLI_TYPE_INT24 :
				print "\t`{$obj->name}` mediumint unsigned not null"; break;
                        case MYSQLI_TYPE_LONG :
				print "\t`{$obj->name}` int unsigned not null"; break;
                        case MYSQLI_TYPE_LONGLONG :
				print "\t`{$obj->name}` bigint not null";
				if ($obj->name == 'id')
					print " primary key";
				break;
                        case MYSQLI_TYPE_SHORT :
				print "\t`{$obj->name}` smallint unsigned not null"; break;
                        case MYSQLI_TYPE_TINY :
				print "\t`{$obj->name}` tinyint unsigned not null"; break;
                        case MYSQLI_TYPE_FLOAT :
				print "\t`{$obj->name}` float not null"; break;
                        case MYSQLI_TYPE_DOUBLE :
                        case MYSQLI_TYPE_DECIMAL :
				print "\t`{$obj->name}` double not null"; break;
			case MYSQLI_TYPE_STRING :
				if ($obj->max_length <= 1024) { //if long, fall though and use TEXT
					print "\t`{$obj->name}` varchar({$obj->max_length}) not null"; break;
				}
                        default:
				print "\t`{$obj->name}` text not null"; break;
                }
		$sep = ",\n";
	}
	print "\n);\n\n";

######################################
# schema

} elseif (!empty($p['schema'])) {
	print "-- dumping schema (to create the table in RT mode)\n\n";

	$result = mysqli_query($db,"SHOW CREATE table `{$p['table']}`");

	if ($result) { //some versions of manticore will show create table (particully in RT mode)
		$row = mysqli_fetch_assoc($result);

		$create_table = $row['Create Table'];
	} else {
		//otehrise will have create one manually...
		$create_table = "CREATE TABLE `{$p['table']}` ("; $sep = "\n";
		$result = mysqli_query($db,"DESCRIBE `{$p['table']}`") or die("unable to describe\n".mysqli_error($db)."\n\n");

		$fields = array();
		while ($row = mysqli_fetch_assoc($result)) {

			if ($row['Type'] == 'local') { //its a distributed index!
				$result = mysqli_query($db,"DESCRIBE `{$row['Agent']}`") or die("unable to describe\n".mysqli_error($db)."\n\n");
				$row = mysqli_fetch_assoc($result);
			}

			if ($row['Field'] != 'id' &&  //the id column is automatic!
			$row['Type'] != 'field') { //fields are NOT exported (only text/stored type!)
				$create_table .= "$sep  `{$row['Field']}` ";
				if ($row['Type'] == 'text' && $row['Properties'] == 'indexed stored') //the new default!
					$create_table .= " text";
				elseif ($row['Type'] == 'text')
					$create_table .= " text {$row['Properties']}";
				elseif ($row['Type'] == 'uint')
					$create_table .= " integer";
				elseif ($row['Type'] == 'mva') {
					$create_table .= " multi";
					$mvas[$row['Field']] = 1;
				} elseif ($row['Type'] == 'string') {
					$create_table .= " string";
					if (isset($fields[$row['Field']])) {
						$create_table .= " indexed";
						unset($fields[$row['Field']]); //so that fields only include 'undumped' fields
					}
				} else
					$create_table .= " {$row['Type']}";

				$sep = ",\n";
			} elseif ($row['Type'] == 'field') {
				$fields[$row['Field']] = 1;
			}
		}
		$create_table .= "\n)";
	}

	print "$create_table;\n\n";

	if (!empty($fields)) { // stored fields show as type 'text' so not included here
		print "-- WARNING: the index contains the following fields that where NOT stored, so not included in output:\n";
		print "--  ".implode(', ',array_keys($fields))."\n";
	}
}

######################################
# data

if (!empty($p['data'])) {

	if (!empty($p['tsv'])) {
		$h = gzopen($p['tsv'],'w');
	}

	if (!empty($p['lock'])) mysqli_query($db,"LOCK TABLES `{$p['table']}` READ"); //todo this actully needs extact the list of tableS from $select!

	$lastid = 0;
	while(true) {

		if (!empty($p['limit']) && $p['limit']<=1000) { //TODO: if there is a limit, but over 1000, will have to just keep looping until get the right number!
			$postfix = " LIMIT ".$p['limit'];
		} else {
			//otherwise loop though 1000 rows at a time...
			if (preg_match('/\bWHERE\b/i',$p['select'])) {
				$postfix = ($lastid)?" AND id > $lastid":'';
			} else {
				$postfix = ($lastid)?" WHERE id > $lastid":'';
			}
			$postfix .= " ORDER BY id ASC LIMIT 1000"; //todo, autodetect what max_matches is set to!!
		}

		$result = mysqli_query($db,$p['select'].$postfix) or die("unable to run {$p['select']}$postfix;\n".mysqli_error($db)."\n\n");

	        if (!mysqli_num_rows($result)) //todo, use show meta instead?
        	        break;

		print "-- dumping ".mysqli_num_rows($result)." rows\n\n";

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
					if (isset($mvas[$obj->name]) && $p['schema'] !== 'mysql') {
						$types[] = 'mva'; break;
					}
					$types[] = 'other'; break; //we dont actully care about the exact type, other than knowing numeric
			}
		}

		if (!empty($p['tsv']) && !$lastid) {
			gzwrite($h,implode("\t",$names)."\n");
		}

		while($row = mysqli_fetch_row($result)) {
			//print "INSERT INTO `{$p['table']}` (".implode(",",$names).") VALUES ("; //todo - complete inserts
			print "INSERT INTO `{$p['table']}` VALUES (";
			$sep = '';
			foreach($row as $idx => $value) {
				if (is_null($value))
					$value = 'NULL';
				elseif ($types[$idx] == 'mva') //mva's need special treatment if importing into index
					$value = "(".mysqli_real_escape_string($db,$value).")";
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
			$lastid = $row[0];
		}

	        if (mysqli_num_rows($result) < 1000) //can exit as got all rows!
        	        break;

	}

	if (!empty($p['lock'])) mysqli_query($db,"UNLOCK TABLES");
}

######################################
$end = microtime(true);

printf("\n-- done in %0.3f seconds\n\n",$end-$start);



		//alas php doesnt have a single function for
		//   Newline, tab, NUL, and backslash are written as \n, \t, \0, and \\.
		function escape_tsv($in) {
			return addcslashes(str_replace("\r",'',$in),"\\\n\t\0");
		}
