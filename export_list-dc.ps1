Import-Module SateonCore
Get-SateonAccessControlBox | Sort-Object  | Out-File C:\log\dc-fault-list.txt;