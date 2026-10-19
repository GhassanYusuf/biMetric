<#
.SYNOPSIS
    Extracts a ZKAccess backup (.mdb) into the canonical zkaccess-export.json
    consumed by `php artisan zkaccess:import`.

.USAGE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/export-zkaccess.ps1 `
        -Mdb "C:\path\10-06-2026.mdb" -Out "C:\path\zkaccess-export.json"

.DEPENDENCIES
    Windows with the "Microsoft Access Driver (*.mdb, *.accdb)" ODBC driver.
    No mdbtools/mono/java required.
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$Mdb,
    [string]$Out = (Join-Path $PSScriptRoot "..\storage\app\zkaccess-export.json")
)

Add-Type -AssemblyName System.Data

if (-not (Test-Path -LiteralPath $Mdb)) {
    throw "MDB not found: $Mdb"
}

$connString = "Driver={Microsoft Access Driver (*.mdb, *.accdb)};DBQ=$Mdb;"
$conn = New-Object System.Data.Odbc.OdbcConnection($connString)
$conn.Open()

function QueryToTable([string]$sql) {
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = $sql
    $da = New-Object System.Data.Odbc.OdbcDataAdapter($cmd)
    $ds = New-Object System.Data.DataSet
    [void]$da.Fill($ds)
    return , $ds.Tables[0]
}

function Str([object]$v) {
    if ($v -is [DBNull]) { return $null }
    return [string]$v
}

function Dh([object]$v) {
    if ($v -is [DBNull]) { return $null }
    if ($v -is [datetime]) { return $v.ToString('yyyy-MM-dd') }
    return $null
}

try {
    $t = QueryToTable "SELECT * FROM DEPARTMENTS ORDER BY DEPTID"
    $departments = New-Object System.Collections.ArrayList
    foreach ($r in $t.Rows) {
        [void]$departments.Add([pscustomobject]@{
            id        = Str $r['DEPTID']
            name      = Str $r['DEPTNAME']
            parent_id = Str $r['SUPDEPTID']
            code      = Str $r['code']
        })
    }

    $t = QueryToTable "SELECT * FROM Machines ORDER BY ID"
    $machines = New-Object System.Collections.ArrayList
    foreach ($r in $t.Rows) {
        [void]$machines.Add([pscustomobject]@{
            id             = Str $r['ID']
            name           = Str $r['name']
            ip             = Str $r['ip']
            serial         = Str $r['sn']
            product        = Str $r['model']
            firmware       = Str $r['firmwareversion']
            machine_number = Str $r['machineNumber']
        })
    }

    $t = QueryToTable @"
SELECT [Badgenumber], [USERID], [name], [lastname], [Gender], [CardNo],
       [HIREDDAY], [BIRTHDAY], [DefaultDeptID], [privilege], [PASSWORD],
       [EMAIL], [OPHONE], [FPHONE], [homeaddress], [street], [CITY],
       [contry], [Education], [Political], [TITLE], [emptype], [status], [DelTag]
FROM [USERINFO] ORDER BY [USERID]
"@
    $users = New-Object System.Collections.ArrayList
    foreach ($r in $t.Rows) {
        [void]$users.Add([pscustomobject]@{
            user_id        = Str $r['USERID']
            pin            = Str $r['Badgenumber']
            first_name     = Str $r['name']
            last_name      = Str $r['lastname']
            gender         = Str $r['Gender']
            card           = Str $r['CardNo']
            hire_date      = Dh $r['HIREDDAY']
            birth_date     = Dh $r['BIRTHDAY']
            department_id  = Str $r['DefaultDeptID']
            privilege      = Str $r['privilege']
            password       = Str $r['PASSWORD']
            email          = Str $r['EMAIL']
            mobile_phone   = Str $r['OPHONE']
            home_phone     = Str $r['FPHONE']
            home_address   = Str $r['homeaddress']
            street         = Str $r['street']
            city           = Str $r['CITY']
            country        = Str $r['contry']
            education      = Str $r['Education']
            political      = Str $r['Political']
            job_title      = Str $r['TITLE']
            emptype        = Str $r['emptype']
            status         = Str $r['status']
            deleted        = Str $r['DelTag']
        })
    }
    Write-Host "users: $($users.Count)"

    $t = QueryToTable @"
SELECT c.[USERID], c.[CHECKTIME], c.[CHECKTYPE], c.[VERIFYCODE], c.[sn],
       u.[Badgenumber]
FROM [CHECKINOUT] c
LEFT JOIN [USERINFO] u ON u.[USERID] = c.[USERID]
ORDER BY c.[LOGID]
"@
    $punches = New-Object System.Collections.ArrayList
    foreach ($r in $t.Rows) {
        $ct = Str $r['CHECKTIME']
        if ($ct) {
            # local wall-clock time as recorded by the terminals (Iraq, UTC+3)
            $ct = ([datetime]$ct).ToString('yyyy-MM-dd HH:mm:ss')
        }
        [void]$punches.Add([pscustomobject]@{
            user_id  = Str $r['USERID']
            pin      = Str $r['Badgenumber']
            serial   = Str $r['sn']
            at       = $ct
            type     = Str $r['CHECKTYPE']
            verify   = Str $r['VERIFYCODE']
        })
    }
    Write-Host "punches: $($punches.Count)"

    $export = [ordered]@{
        source    = (Split-Path -Leaf $Mdb)
        exported_at = (Get-Date).ToString('u')
        departments = @($departments)
        machines    = @($machines)
        users       = @($users)
        punches     = @($punches)
    }

    $json = ConvertTo-Json -InputObject $export -Depth 5 -Compress
    $dir = Split-Path -Parent $Out
    if (-not (Test-Path -LiteralPath $dir)) {
        [void](New-Item -ItemType Directory -Path $dir)
    }
    [System.IO.File]::WriteAllText($Out, $json, [System.Text.UTF8Encoding]::new($false))
    Write-Host "wrote $((Get-Item $Out).Length / 1MB) MB -> $Out"
}
finally {
    $conn.Close()
}