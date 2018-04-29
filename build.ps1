$version = "2.0.0"

$dist = "$pwd\dist"
$outputFile = "$dist\BTCPay-WHMCS-Plugin-$version.zip"

$modules = "$pwd/modules"
Remove-Item $dist -Force -Recurse
if(!(Test-Path -Path $dist )){
    New-Item -ItemType directory -Path $dist
    New-Item -ItemType directory -Path "$dist\tmp"
}

Copy-Item "$modules\*" "$dist\tmp\modules" -Force -Recurse
Add-Type -assembly "system.io.compression.filesystem"
[io.compression.zipfile]::CreateFromDirectory("$dist\tmp", $outputFile) 
Remove-Item "$dist\tmp" -Force -Recurse

Write-Output "Output available in $outputFile"

