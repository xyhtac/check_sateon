#!/usr/bin/php
<?php

# Icinga Plugin Script (Check Command). Check SATEON field device Status.
#
# Max.Fischer <dev@monologic.ru>
# Tested on CentOS GNU/Linux 6.5 with Icinga r2.6.3-1

# Script fetches device_list and fault_list from SATEON server via http,
# parses parameters and stores them to the local cache. Device status 
# returned upon each run using standard Nagios/Icinga codes. 

# supposed to be placed in nagios plugins directory, i.e.:
# /usr/lib/nagios/plugins/check_sateon.php - CHMOD 755

# Usage example:
# ./check_sateon.php --hostname 10.0.1.1 --status-dc dc-fault-list.txt --list-dc dc-list.txt --device DOOR-2.14

# To run this script you need to make available for http request full 
# list of field network devices and list of current hardware faults.

# EXPECTED FORMAT OF SATEON OUTPUT
#
# === Device list ===
#
# Address                    : 8
# BccsId                     : ff30a8ea-0162-7790-a091-67f84cc83b1c
# BoxTypeId                  : dabe4449-4646-47fe-9fcc-97df5abd91f5
# Comment                    : 
# Description                : DOOR-2.16
# Interlock                  : False
# LineId                     : 6778da32-8730-4801-be5c-0e854407f14d
# MainsFailurePeriod         : -1
# Poll                       : True
# TimeZoneId                 : Western Standard Time
# Identity                   : AccessControl.Box:13f568a4-1423-4041-fd31-a6862dffc7af
# LastModifiedDate           : 02.02.2020 13:47:16
# CreationDate               : 02.02.2020 13:47:16
# SecurityContextId          : 
# SystemId                   : 00000000-0000-0000-0000-000000000000
# PartitionId                : 
# UpgradeId                  : f342168a5-cf30-5652-9a64-df686aafc7f2
# IsLinkableOutsidePartition : False
# IsSystem                   : False

# === Fault List ===
# 
# Entity Type       : AccessControl.Box
# Entity Id         : 13f568a4-1423-4041-fd31-a6862dffc7af
# Property Name     : State
# IsAbnormal        : True
# Value             : OffLine
# LastChanged       : 19.04.2020 10:33:45 +03:00
# AbnormalTimestamp : 19.04.2020 8:33:45 +00:00


# ICINGA CONFIG DEFINITIONS:
# use 'sateon-config-generator.php' for automated config creation. 
#
# === Configure host template ===
# template Host "sateon-host" {
#        check_command = "check_sateon"
#	 host.vars.sateon = "True"
#        vars.hostname = "10.0.0.10"
#        vars.username = "spectator"
#        vars.password = "secret_password"
#        vars.status_log = "dc-fault-list.txt"
#        vars.id_log = "dc-list.txt"
# }
#
# === Configure host ===
# object Host "DOOR-2.16" {
#	 import "sateon-host"
#	 vars.description = "SATEON panel $dcname"
# }
#
# === Configure service (more options) ===
# apply Service "sateon_device" {
#	display_name = "Device status"
#	import "generic-service"
#	check_command = "check_sateon"
#	assign where host.vars.sateon == "True"
#       vars.hostname = "10.0.0.10"
#       vars.username = "spectator"
#       vars.password = "secret_password"
#       vars.status_log = "dc-fault-list.txt"
#       vars.id_log = "dc-list.txt"
#	vars.device_id = host.name
# }
#
# === Configure Command ===
# object CheckCommand "check_sateon" {
#	import "plugin-check-command"
#	command = [ PluginDir + "/check_sateon.php" ]
#	arguments = {
#		"--hostname" = "$hostname$"
#		"--username" = "$username$"
#		"--password" = "$password$"
#		"--status-dc" = "$status_log$"
#		"--list-dc" = "$id_log$"
#		"--device" = "$device_id$"
#	}
#}

# default values for externally definable parameters 

$cfg['cache'] = "/tmp";					# local cache storage. Must be readable and writable for nagios user.
$cfg['status-dc'] = "dc-fault-list.txt";		# filename of fault list on remote SATEON (http) server.
$cfg['list-dc'] = "dc-list.txt";			# filename of device list on remote SATEON (http).
$cfg['hostname'] = "10.0.0.10";				# remote SATEON (http) hostname or ip.
$cfg['cache-lifetime'] = 300;				# local cache lifetime in seconds.
$cfg['expected_size'] = 1000;				# expected size of device list in bytes.


# initial variables
define( "STATUS_OK", 0 );
define( "STATUS_WARNING", 1 );
define( "STATUS_CRITICAL", 2 );
define( "STATUS_UNKNOWN", 3 );
$dc_exists = 0;
$dc_fault = 0;
$n = 0;
$i = 0;
$cachedata = "";
$log['cache-id'] = 0;
$log['cache-fault'] = 0;




# Step 1. Extract vars from command line

# extract parameters from command line to $cfg
foreach ($argv as &$val) {
	if (preg_match("/\-\-/", $val) && $argv[$i+1] && !preg_match("/\-\-/", $argv[$i+1]) ) {
		$varvalue = $argv[$i+1];
		$varname = str_replace("--", "", $val);
		$cfg[$varname] = $varvalue;
	}
	$i++;
}

# Throw error if device ID was not specified in the input
if (!$cfg['device']) { 
		echo "No Device ID Specified. Can't run.";
		exit(STATUS_UNKNOWN);
}



# Step 2. Check local cache for device list. If cache Ok, use it.

# compose cache url
$deviceIDcache = $cfg['cache']."/".$cfg['list-dc'];

if ( file_exists($deviceIDcache) && time() - filemtime($deviceIDcache) < $cfg['cache-lifetime'] && filesize($deviceIDcache) > $cfg['expected_size']) {
	
	# read data from cache file and split it into lines
	$cachedata = explode("\n", file_get_contents($deviceIDcache));
	
	# set device list cache flag
	$log['cache-id'] = 1;
	
	# iterate through lines
	foreach ($cachedata as &$line) {
		# check if cache line is parsable
		if ( preg_match("/\=/", $line) ) {
			
			# read line into variable pairs
			list($idstr, $dcname) = explode("=", $line);
		
			# put id=name pair to $device hash
			$device[$idstr] = $dcname;
	
			# if device name equals to requested device, raise 'device found' flag
			if ($dcname == $cfg['device']) {
				$dc_exists = 1;
			}
		}
	}
} else {
	# Step 3. Not using cache. Get SystemID List from remote server.

	# get device list from remote server
	$devicelist = getContent( $cfg['hostname'], $cfg['list-dc'], $cfg['username'], $cfg['password']);

	# Throw error if SystemID list cannot be updated
	if (!$devicelist) { 
		echo "Can't get device list from server";
		exit(STATUS_UNKNOWN);
	}

	# fix encoding to UTF-8
	$devicelist = mb_convert_encoding($devicelist, 'UTF-8', 'UCS-2LE');

	# parse device list to array using pattern
	preg_match_all('/Description\s+:(.+?)\nInterlock\s+:(.+?)\nLineId\s+:(.+?)\nMainsFailurePeriod\s+:(.+?)\nPoll\s+:(.+?)\nTimeZoneId\s+:(.+?)\nIdentity\s+:(.+?)\n/', $devicelist, $lines, PREG_PATTERN_ORDER);

	# preserve subarray of device ids
	$identities = $lines[7];

	# iterate throught device ids
	foreach ($identities as &$idstr) {
	
		# retrieve device name from subarray 1
		$dcname = $lines[1][$n];
	
		# clean variables
		$dcname = cleanVar($dcname);
		$idstr = str_replace("AccessControl.Box:", "", $idstr);
		$idstr = cleanVar($idstr);

		# put id=name pair to $device hash
		$device[$idstr] = $dcname;
		
		# append cache output data
		$cachedata = $cachedata.$idstr."=".$dcname."\n";
	
		# if device name equals to requested device, raise 'device found' flag
		if ($dcname == $cfg['device']) {
			$dc_exists = 1;
		}
	
		# raise counter
		$n++;
	}
	
	# if remote data is defined, save it to cache
	if ($cachedata) {
		file_put_contents($deviceIDcache, $cachedata);
	}
}


# exit if requested device ID was not found in the server output
if (!$dc_exists) {
	echo "Device was not found on the server.";
	exit(STATUS_UNKNOWN);
}



# Step 4. Check local cache for fault list. If cache Ok, use it.

# compose cache url
$faultCache = $cfg['cache']."/".$cfg['status-dc'];

if ( file_exists($faultCache) && time() - filemtime($faultCache) < $cfg['cache-lifetime'] && filesize($deviceIDcache) > 1 ) {
	
	# read data from cache file and split it into lines
	$cachedata = explode("\n", file_get_contents($faultCache));
	
	# set fault list cache flag
	$log['cache-fault'] = 1;
	
	# iterate through lines
	foreach ($cachedata as &$line) {
		# check if cache line is parsable
		if ( preg_match("/\|/", $line) ) {
			# read line into variables
			list($idstr, $dcstatus, $faulttime) = explode("|", $line);
			
			# if device ID found in fault list
			if ($cfg['device'] == $device[$idstr]) {

				# print error contents				
				echo "Device status is $dcstatus. Event time $faulttime. SystemID: $idstr";
				
				# set fault flag
				$dc_fault = 1;
			}
		}
	}
} else { 

	# Step 5. Not using cache. Get Fault List from remote server.

	# reset variables and arrays
	$cachedata = "";
	$lines = '';
	$n = 0;

	# get fault list from remote server
	$faultlist = getContent( $cfg['hostname'], $cfg['status-dc'], $cfg['username'], $cfg['password']);
	
	
	#if (!$faultlist) { 
	#	echo "Can't get fault list from server";
	#	exit(STATUS_UNKNOWN);
	#}

	# fix encoding to UTF-8
	$faultlist = mb_convert_encoding($faultlist, 'UTF-8', 'UCS-2LE');

	# parse fault list to array using pattern
	preg_match_all('/Entity Id\s+:(.+?)\nProperty Name\s+:(.+?)\nIsAbnormal\s+:(.+?)\nValue\s+:(.+?)\nLastChanged\s+:(.+?)\n/', $faultlist, $lines, PREG_PATTERN_ORDER);

	# preserve subarray of faulted device IDs
	$identities = $lines[1];

	# iterate throught faulted ids
	foreach ($identities as &$idstr) {
		
		# clean current id
		$idstr = cleanVar($idstr);
		
		# if device ID found in fault list
		if ($cfg['device'] == $device[$idstr]) {
			
			# preserve and clean log data
			$dcstatus = cleanVar($lines[4][$n]);
			$faulttime = cleanVar($lines[5][$n]);
			
			# append cache output data
			$cachedata = $cachedata.$idstr."|".$dcstatus."|".$faulttime."\n";
			
			# print error contents
			echo "Device status is $dcstatus. Event time $faulttime. SystemID: $idstr";
			
			# set fault flag
			$dc_fault = 1;
		};
		$n++;
	}
	
	# if remote data is defined, save it to to cache
	if ($cachedata) {
		file_put_contents($faultCache, $cachedata);
	}
}


if ($dc_fault) {
	# report cache operations for debug purposes.
	# echo " C[".$log['cache-id']."-".$log['cache-fault']."] ";
	
	# die and report CRITICAL
	exit(STATUS_CRITICAL);
} else {
	# device ID hasn't been found in the fault log, report STATUS OK.
	echo "No faults found.";
	
	# report cache operations for debug purposes.
	# echo " C[".$log['cache-id']."-".$log['cache-fault']."] ";
	
	# die and report OK
	exit(STATUS_OK);
}


# remove line breaks and leading spaces
function cleanVar ($variable) {
	$variable = str_replace("\n", "", $variable);
	$variable = str_replace("\r", "", $variable);
	$variable = preg_replace("/^\s/", "", $variable);
	return $variable;
}

# fetch data from remote server
function getContent ($host, $path, $username, $password) {
	ini_set('default_socket_timeout', 3);
	$params = array(
       		'http' => array(
               		'method' => "GET",
               		'header' => "Authorization: Basic " . base64_encode("$username:$password")
       		)
	);
	$ctx = stream_context_create($params);
	$url = "http://$host/$path";
	$data = file_get_contents($url, false, $ctx);
	if ($data === false) {
		return 0;
	}
	return $data;
}


?>
