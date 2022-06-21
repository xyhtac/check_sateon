# check_sateon
Nagios/Icinga plugin for checking SATEON field network device status

Max.Fischer <dev@monologic.ru>
Tested on CentOS GNU/Linux 6.5 with Icinga r2.6.3-1

![Icinga Plugin - Sateon Host Checks](/icinga-example.png?raw=true "Icinga Plugin - Sateon Host Checks")

Script fetches device_list and fault_list from SATEON server via http,
parses parameters and stores them to the local cache. Device status 
returned upon each run using standard Nagios/Icinga codes. 

supposed to be placed in nagios plugins directory, i.e.:
```
/usr/lib/nagios/plugins/check_sateon.php - CHMOD 755
```

Usage example:
```
./check_sateon.php --hostname 10.0.1.1 --status-dc dc-fault-list.txt --list-dc dc-list.txt --device DOOR-2.14
```

To run this script you need to make available for http request full 
list of field network devices and list of current hardware faults.

## Expected format of SATEON output

### Device list
Result of scheduled execution of 'export_list-dc.ps1'

```
Address                    : 8
BccsId                     : ff30a8ea-0162-7790-a091-67f84cc83b1c
BoxTypeId                  : dabe4449-4646-47fe-9fcc-97df5abd91f5
Comment                    : 
Description                : DOOR-2.16
Interlock                  : False
LineId                     : 6778da32-8730-4801-be5c-0e854407f14d
MainsFailurePeriod         : -1
Poll                       : True
TimeZoneId                 : Western Standard Time
Identity                   : AccessControl.Box:13f568a4-1423-4041-fd31-a6862dffc7af
LastModifiedDate           : 02.02.2020 13:47:16
CreationDate               : 02.02.2020 13:47:16
SecurityContextId          : 
SystemId                   : 00000000-0000-0000-0000-000000000000
PartitionId                : 
UpgradeId                  : f342168a5-cf30-5652-9a64-df686aafc7f2
IsLinkableOutsidePartition : False
IsSystem                   : False
```

### Fault List
Result of scheduled execution of 'export_status-dc.ps1'

```
Entity Type       : AccessControl.Box
Entity Id         : 13f568a4-1423-4041-fd31-a6862dffc7af
Property Name     : State
IsAbnormal        : True
Value             : OffLine
LastChanged       : 19.04.2020 10:33:45 +03:00
AbnormalTimestamp : 19.04.2020 8:33:45 +00:00
```

## Icinga Configuration Definitions
Use 'sateon-config-generator.php' to generate Icinga configuration

### Configure host
```
object Host "DOOR-2.16" {
    check_command = "check_sateon"
    host.vars.sateon == "True"
    vars.hostname = "10.0.0.10"
    vars.username = "spectator"
    vars.password = "secret_password"
    vars.status_log = "dc-fault-list.txt"
    vars.id_log = "dc-list.txt"
}
```

### Configure service
```
apply Service "sateon_device" {
    display_name = "Device status"
    import "generic-service"
    check_command = "check_sateon"
    assign where host.vars.sateon == "True"
    vars.hostname = "10.0.0.10"
    vars.username = "spectator"
    vars.password = "secret_password"
    vars.status_log = "dc-fault-list.txt"
    vars.id_log = "dc-list.txt"
    vars.device_id = host.name
}
```

### Configure Command
```
object CheckCommand "check_sateon" {
    import "plugin-check-command"
    command = [ PluginDir + "/check_sateon.php" ]
    arguments = {
        "--hostname" = "$hostname$"
    	"--username" = "$username$"
    	"--password" = "$password$"
    	"--status-dc" = "$status_log$"
    	"--list-dc" = "$id_log$"
    	"--device" = "$host.name$"
    }
}
```
## License

check_sateon is licensed under the [MIT](https://www.mit-license.org/) license for all open source applications.

## Bugs and feature requests

If you find a bug, please report it [here on Github](https://github.com/xyhtac/check_sateon/issues).

Guidelines for bug reports:

1. Use the GitHub issue search — check if the issue has already been reported.
2. Check if the issue has been fixed — try to reproduce it using the latest master or development branch in the repository.
3. Isolate the problem — create a reduced test case and a live example. 

A good bug report shouldn't leave others needing to chase you up for more information.
Please try to be as detailed as possible in your report.

Feature requests are welcome. Please look for existing ones and use GitHub's "reactions" feature to vote.
