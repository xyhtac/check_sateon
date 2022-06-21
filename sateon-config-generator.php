#!/usr/bin/php
<?php
# check_sateon Icinga Config Generator
#
# Generate Icinga for all sateon devices using cached device list.
#
# Run check_sateon.php once before executing this script.
# Example: 
# ./check_sateon.php --hostname 10.0.1.1 --status-dc dc-fault-list.txt --list-dc dc-list.txt --device DOOR-2.14

$cfg['cache'] = "/tmp";
$cfg['config-file'] = "sateon-field-network.conf";
$cfg_list_dc = "DC.txt";
$cfg_status_dc = "dc-fault-list.txt";
$cfg_hostname = "10.0.0.1";
$cfg_username = "spectator";
$cfg_password = "secret_password";



# reset parameters
$n = 0;
$i = 0;
$cachedata = "";
$hostlist = "";
$config = <<<EOL
object CheckCommand "check_sateon" {
	import "plugin-check-command"
	command = [ PluginDir + "/check_sateon.php" ]
	arguments = {
		"--hostname" = "\$hostname\$"
		"--username" = "\$username\$"
		"--password" = "\$password\$"
		"--status-dc" = "\$status_log\$"
		"--list-dc" = "\$id_log\$"
		"--device" = "\$host.name\$"
	}
}

template Host "sateon-host" {
	check_command = "check_sateon"
	vars.hostname = "$cfg_hostname"
	vars.username = "$cfg_username"
	vars.password = "$cfg_password"
	vars.status_dc = "$cfg_status_dc"
	vars.list_dc = "$cfg_list_dc"
	vars.sateon = "True"
	vars.type = "RS485-Controller"
	vars.owner = "Customer-AccessControl"
}

object HostGroup "RS485 Controllers" {
  display_name = "RS485-Controllers"
  assign where host.vars.type == "RS485-Controller"
}

apply Dependency "sateon-server" to Host {
  parent_host_name = "SATEON"
  disable_checks = true
  disable_notifications = true
  assign where host.vars.type == "RS485-Controller"
}


EOL;



# get device list from remote server
$devicelist = getContent( $cfg_hostname, $cfg_list_dc, $cfg_username, $cfg_password);

# Throw error if SystemID list cannot be updated
if (!$devicelist) { 
	echo "Can't get device list from server";
	exit(STATUS_UNKNOWN);
}

# fix encoding to UTF-8
$devicelist = mb_convert_encoding($devicelist, 'UTF-8', 'UCS-2LE');

# parse device list to array using pattern
preg_match_all('/Comment\s+:(.+?)\nDescription\s+:(.+?)\nInterlock\s+:(.+?)\nLineId\s+:(.+?)\nMainsFailurePeriod\s+:(.+?)\nPoll\s+:(.+?)\nTimeZoneId\s+:(.+?)\nIdentity\s+:(.+?)\n/', $devicelist, $lines, PREG_PATTERN_ORDER);

# preserve subarray of device ids
$identities = $lines[8];

# iterate throught device ids
foreach ($identities as &$idstr) {
	
	# retrieve device name from subarray 2
	$dcname = $lines[2][$n];
	
	# retrieve LH name from subarray 1
	$lhname = $lines[1][$n];
	
	# clean variables
	$dcname = cleanVar($dcname);
	$lhname = cleanVar($lhname);
	$idstr = str_replace("AccessControl.Box:", "", $idstr);
	$idstr = cleanVar($idstr);

	# put id=name pair to $device hash
	$lhline[$lhname] = <<<ELH

object HostGroup "$lhname" {
	display_name = "$lhname"
	assign where host.vars.lineheader == "$lhname"
}

apply Dependency "sateon-$lhname" to Host {
  parent_host_name = "SATEON-$lhname"
  disable_checks = true
  disable_notifications = true
  assign where host.vars.lineheader == "$lhname"
}
ELH;
		
	
	$config_line = <<<EOL

object Host "$dcname" {
	import "sateon-host"
	vars.description = "SATEON panel $dcname"
	vars.lineheader = "$lhname"
	vars.systemid = "$idstr"
}
EOL;
	
	$hostlist = $hostlist.$config_line;

	# raise counter
	$n++;
}

$lhlist = implode("\n", $lhline);

$config = $config.$lhlist.$hostlist;


# compose config url
$configname = $cfg['cache']."/".$cfg['config-file'];

file_put_contents($configname, $config);


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
