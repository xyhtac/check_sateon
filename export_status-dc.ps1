Import-Module SateonUIGateway; Import-Module SateonCore; 
$timestamp = Get-Date -Format o | foreach {$_ -replace ":", "."} ;
Start-Sleep -Seconds 5;
Get-SateonUiGatewayStatecache | Where-Object {$_.EntityType -eq "AccessControl.Box" -and $_.IsAbnormal -and $_.PropertyName -eq "State"} | Sort-Object  | Out-File C:\log\dc-fault-list.txt; 