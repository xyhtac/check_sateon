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
$cfg['list-dc'] = "DC.txt";
$cfg['status-dc'] = "dc-fault-list.txt";
$cfg['config-file'] = "sateon-field-network.conf";
$cfg['hostname'] = "10.0.0.1";
$cfg['username'] = "spectator";
$cfg['password'] = "secret_password";


# reset parameters
$n = 0;
$i = 0;
$cachedata = "";
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
		"--device" = "\$device_id\$"
	}
}

template Host "sateon-host" {
	check_command = "check_sateon"
	vars.hostname = "$cfg['hostname']"
	vars.username = "$cfg['username']"
	vars.password = "$cfg['password']"
	vars.status_dc = "$cfg['status-dc']"
	vars.list_dc = "$cfg['list-dc']"
	host.vars.sateon = "True"
	vars.type = "Controller"
}

EOL;

# compose cache url
$deviceIDcache = $cfg['cache']."/".$cfg['list-dc'];

# read data from cache file and split it into lines
$cachedata = explode("\n", file_get_contents($deviceIDcache));

# iterate through lines
foreach ($cachedata as &$line) {
	# check if cache line is parsable
	if ( preg_match("/\=/", $line) ) {
		# read line into variable pairs
		list($idstr, $dcname) = explode("=", $line);
		
		$config_line = <<<EOL
object Host "$dcname" {
	import "sateon-host"
	vars.description = "SATEON panel $dcname"
}
EOL;
		$config = $config.$config_line;
	}
}

# compose config url
$configname = $cfg['cache']."/".$cfg['config-file'];

file_put_contents($configname, $config);

?>
