$shipardAgentVersion = "0.8.6"
$shipardAgentDir = "C:\shipard-agent\"


Function Get-InstalledSoftwareLM {
    Param(
        [Alias('Computer','ComputerName','HostName')]
        [Parameter(
            ValueFromPipeline=$True,
            ValueFromPipelineByPropertyName=$true,
            Position=1
        )]
        [string]$Name = $env:COMPUTERNAME
    )
    Begin{
        $lmKeys = "Software\Microsoft\Windows\CurrentVersion\Uninstall","SOFTWARE\Wow6432Node\Microsoft\Windows\CurrentVersion\Uninstall"
        $lmReg = [Microsoft.Win32.RegistryHive]::LocalMachine
    }
    Process{
        if (!(Test-Connection -ComputerName $Name -count 1 -quiet)) {
            Write-Error -Message "Unable to contact $Name. Please verify its network connectivity and try again." -Category ObjectNotFound -TargetObject $Computer
            Break
        }
        $masterKeys = @()
        $remoteLMRegKey = [Microsoft.Win32.RegistryKey]::OpenRemoteBaseKey($lmReg,$Name)
        foreach ($key in $lmKeys) {
            $regKey = $remoteLMRegKey.OpenSubkey($key)
            foreach ($subName in $regKey.GetSubkeyNames()) {
                foreach($sub in $regKey.OpenSubkey($subName)) {
                    $masterKeys += (New-Object PSObject -Property @{
                        "ComputerName" = $Name
                        "Name" = $sub.GetValue("displayname")
                        "SystemComponent" = $sub.GetValue("systemcomponent")
                        "ParentKeyName" = $sub.GetValue("parentkeyname")
                        "Version" = $sub.GetValue("DisplayVersion")
                        "UninstallCommand" = $sub.GetValue("UninstallString")
                        "InstallDate" = $sub.GetValue("InstallDate")
                        "Publisher" = $sub.GetValue("Publisher")
                        "EstimatedSize" = $sub.GetValue("EstimatedSize")
                        "URLUpdateInfo" = $sub.GetValue("URLUpdateInfo")
                        "URLInfoAbout" = $sub.GetValue("URLInfoAbout")
                        "RegPath" = $sub.ToString()
                    })
                }
            }
        }
        $woFilter = {$null -ne $_.name -AND $_.SystemComponent -ne "1" -AND $null -eq $_.ParentKeyName}
        $props = 'Name','Version','ComputerName','Installdate','UninstallCommand','RegPath','Publisher','EstimatedSize','URLUpdateInfo','URLInfoAbout'
        $masterKeys = ($masterKeys | Where-Object $woFilter | Select-Object $props | Sort-Object Name)
        $masterKeys | out-file -filepath $installedSwFile -Append
    }
    End{}
}

Function Get-InstalledSoftwareUsers {
    Begin{}
    Process{
        # Regex pattern for SIDs
        $PatternSID = 'S-1-5-21-\d+-\d+\-\d+\-\d+$'
        
        # Get Username, SID, and location of ntuser.dat for all users
        $ProfileList = gp 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\*' | Where-Object {$_.PSChildName -match $PatternSID} | 
            Select  @{name="SID";expression={$_.PSChildName}}, 
                    @{name="UserHive";expression={"$($_.ProfileImagePath)\ntuser.dat"}}, 
                    @{name="Username";expression={$_.ProfileImagePath -replace '^(.*[\\\/])', ''}}
        
        # Get all user SIDs found in HKEY_USERS (ntuder.dat files that are loaded)
        $LoadedHives = gci Registry::HKEY_USERS | ? {$_.PSChildname -match $PatternSID} | Select @{name="SID";expression={$_.PSChildName}}
        
        # Get all users that are not currently logged
        $UnloadedHives = Compare-Object $ProfileList.SID $LoadedHives.SID | Select @{name="SID";expression={$_.InputObject}}, UserHive, Username
        
        # Loop through each profile on the machine
        Foreach ($item in $ProfileList) {
            # Load User ntuser.dat if it's not already loaded
            IF ($item.SID -in $UnloadedHives.SID) {
                reg load HKU\$($Item.SID) $($Item.UserHive) | Out-Null
            }
        
            $userMark = ";;;shipard-agent-sw-user="
            $userMark += "{0}" -f $($item.Username)
            $userMark += "`n`n"

            $userMark | out-file -filepath $installedSwFile -Append

            Get-ItemProperty registry::HKEY_USERS\$($Item.SID)\Software\Microsoft\Windows\CurrentVersion\Uninstall\* | out-file -filepath $installedSwFile -Append
            Get-ItemProperty registry::HKEY_USERS\$($Item.SID)\Software\Wow6432Node\Microsoft\Windows\CurrentVersion\Uninstall\* | out-file -filepath $installedSwFile -Append
        
            # Unload ntuser.dat        
            IF ($item.SID -in $UnloadedHives.SID) {
                [gc]::Collect()
                reg unload HKU\$($Item.SID) | Out-Null
            }
        }

    }
    End{}
}





for($go = 1; $go -lt 2) # $go will always be less than 2, so this script will run until user intervention
{
    $now = Get-Date
    $nowStr = $now.ToString('yyyyMMddTHHmmss')
    $installedSwFile = $shipardAgentDir + "installedSw-"+$nowStr+".txt"
    $installedSwFileResult = $shipardAgentDir + "installedSw-"+$nowStr+"-result.txt" 

    Start-Sleep -Seconds 60

    $CONFIG = Get-Content 'c:\shipard-agent\config.ini' | ConvertFrom-StringData
    $URL = $CONFIG.dsUrl + "/" + "upload/mac.lan.lans" 
    $fileIntro = ";;;shipard-agent: " + $shipardAgentVersion + "`n;;;os: windows`n;;;deviceUid: "+$CONFIG.deviceUid+"`n;;;date: " + $nowStr + "`n`n"

    $fileIntro | out-file -filepath $installedSwFile

    $fileMark = ";;;shipard-agent-system-info`n`n"
    $fileMark | out-file -filepath $installedSwFile -Append

    Get-ComputerInfo -Property "Windows*", "Os*" | out-file -filepath $installedSwFile -Append

    $fileMark = ";;;shipard-agent-sw-lm`n`n"
    $fileMark | out-file -filepath $installedSwFile -Append

    Get-InstalledSoftwareLM
    Get-InstalledSoftwareUsers

    $result = ""
    $body = Get-Content -path  $installedSwFile -Raw
    $Response = try{Invoke-RestMethod -Body $body -Method 'POST' -Uri $URL -ContentType 'text/plain; charset=utf-8'} catch {$result += "StatusCode: "+$_.Exception.Response.StatusCode.value__ + "`n" + "StatusDescription: "+$_.Exception.Response.StatusDescription+ "`n"+$_.Exception.Message+"`n"}
    $result += $Response
    $result | out-file -filepath $installedSwFileResult

    $removePattern = $shipardAgentDir+"*.txt"
    Get-ChildItem $removePattern | Where-Object {$_.creationtime -le (Get-Date).AddDays(-15)} | Remove-Item -Force -Recurse -ErrorAction Stop

    Start-Sleep -Seconds 21600
}
