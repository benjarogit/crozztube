[CmdletBinding()]
param(
    [string] $SourceRoot = (Split-Path -Parent $PSScriptRoot),
    [string] $Destination = '',
    [string] $VendorDirectory = ''
)

# Fresh-install SOURCE packaging only: never reset a database, remove uploads,
# clear an application's caches, stop services, or produce an update delta here.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-NoReparseAncestors {
    param([string] $Path)
    $cursor = [IO.Path]::GetFullPath($Path)
    while ($cursor) {
        if ([IO.File]::Exists($cursor) -or [IO.Directory]::Exists($cursor)) {
            if (([IO.File]::GetAttributes($cursor) -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                throw "Symbolic links and junctions are not supported: $cursor"
            }
        }
        $parent = [IO.Path]::GetDirectoryName($cursor)
        if (-not $parent -or $parent -eq $cursor) { break }
        $cursor = $parent
    }
}

function Get-SafeRelativePath {
    param([string] $Path)
    $normalized = $Path.Replace('\', '/')
    if (-not $normalized -or [IO.Path]::IsPathRooted($normalized) -or
        $normalized -match '[:*?\[\]\x00-\x1f]' -or
        @($normalized.Split('/') | Where-Object {
            $_ -in @('', '.', '..') -or $_ -match '[. ]$|^(?i:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)'
        }).Count -gt 0) {
        throw "Unsafe package-relative path: $Path"
    }
    return $normalized
}

function Assert-PackageFile {
    param([string] $InputPath, [string] $RelativePath, [bool] $IsVendor = $false)
    Assert-NoReparseAncestors $InputPath
    if (-not (Test-Path -LiteralPath $InputPath -PathType Leaf)) {
        throw "Required package file is missing: $RelativePath"
    }
    $name = [IO.Path]::GetFileName($RelativePath)
    $extension = [IO.Path]::GetExtension($name).ToLowerInvariant()
    if (($name -like '.env*' -and $RelativePath -ne '.env.example') -or
        $name -match '(?i)(firebase.*service.account|service.account.*\.json|google-services\.json|GoogleService-Info\.plist|id_rsa|id_ed25519|credentials\.json)$' -or
        $extension -in @('.key', '.p12', '.pfx', '.p8', '.jks', '.keystore') -or
        ((-not $IsVendor) -and $extension -in @('.cer', '.crt', '.cert', '.der')) -or
        ((-not $IsVendor) -and $extension -eq '.pem' -and $RelativePath -ne 'resources/keys/playhub-update-public.pem')) {
        throw "Credential or certificate file is not allowed in this package: $RelativePath"
    }
    if ($extension -in @('.php', '.js', '.jsx', '.ts', '.tsx', '.json', '.yaml', '.yml', '.pem', '.md', '.txt', '.env', '.example', '.sh', '.ps1')) {
        $content = [IO.File]::ReadAllText($InputPath)
        if ($content -match '(?s)-----BEGIN (?:RSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----\s*[A-Za-z0-9+/=\r\n]{80,}-----END (?:RSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----' -or
            $content -match '"type"\s*:\s*"service_account"') {
            throw "Private credential material detected; review and remove it before packaging: $RelativePath"
        }
        if ($RelativePath -eq '.env.example') {
            foreach ($match in [regex]::Matches($content, '(?im)^[ \t]*([A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|CREDENTIALS|PRIVATE_KEY)|APP_KEY|PLAYHUB_NATIVE_HASH_KEY|AWS_ACCESS_KEY_ID|LIVEKIT_API_KEY)[ \t]*=[ \t]*([^\r\n]*)')) {
                $value = $match.Groups[2].Value.Trim().Trim('"').Trim("'")
                if ($value -and $value -ne 'null') { throw "Environment template contains a configured credential: $($match.Groups[1].Value)" }
            }
        }
    }
}

$SourceRoot = [IO.Path]::GetFullPath($SourceRoot).TrimEnd('\', '/')
Assert-NoReparseAncestors $SourceRoot
if (-not (Test-Path -LiteralPath $SourceRoot -PathType Container)) { throw 'SourceRoot must be an existing directory.' }
$versionPath = Join-Path $SourceRoot 'VERSION'
Assert-NoReparseAncestors $versionPath
$version = [IO.File]::ReadAllText($versionPath).Trim()
if ($version -notmatch '^\d+\.\d+\.\d+(?:-[A-Za-z0-9]+(?:[.-][A-Za-z0-9]+)*)?(?:\+[A-Za-z0-9]+(?:[.-][A-Za-z0-9]+)*)?$') {
    throw 'VERSION must contain a semantic version.'
}
$releaseRoot = [IO.Path]::GetFullPath((Join-Path $SourceRoot 'releases'))
Assert-NoReparseAncestors $releaseRoot
if (-not $Destination) { $Destination = Join-Path $releaseRoot "playhub-web-$version-clean" }
$Destination = [IO.Path]::GetFullPath($Destination).TrimEnd('\', '/')
[void] (Get-SafeRelativePath ([IO.Path]::GetFileName($Destination)))
if (-not [string]::Equals([IO.Path]::GetDirectoryName($Destination), $releaseRoot, [StringComparison]::OrdinalIgnoreCase) -or
    [IO.Path]::GetFileName($Destination) -notmatch '^[A-Za-z0-9][A-Za-z0-9._+-]*$') {
    throw 'Destination must be a named, immediate child of SourceRoot/releases.'
}
Assert-NoReparseAncestors $Destination
if (Test-Path -LiteralPath $Destination) { throw 'Destination already exists; choose a fresh destination. Nothing was overwritten.' }

$planned = [Collections.Generic.Dictionary[string, string]]::new([StringComparer]::OrdinalIgnoreCase)
$vendorPaths = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
function Add-PackageFile {
    param([string] $InputPath, [string] $RelativePath, [bool] $IsVendor = $false)
    $relative = Get-SafeRelativePath $RelativePath
    Assert-PackageFile $InputPath $relative $IsVendor
    if ($planned.ContainsKey($relative)) {
        if ($planned[$relative] -ne $InputPath) { throw "Conflicting package file: $relative" }
        return
    }
    $planned.Add($relative, $InputPath)
    if ($IsVendor) { [void] $vendorPaths.Add($relative) }
}

function Add-VendorLegalFiles {
    param([string] $Directory, [string] $RelativePath)
    # Some distributions place their required notices in a documentation tree.
    # Keep legal files without shipping examples, fixtures or documentation keys.
    Assert-NoReparseAncestors $Directory
    foreach ($entry in Get-ChildItem -LiteralPath $Directory -Force) {
        if (($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Vendor legal-file tree contains a symbolic link or junction: $RelativePath/$($entry.Name)" }
        $relative = "$RelativePath/$($entry.Name)"
        if ($entry.PSIsContainer) {
            if ($entry.Name -notin @('.git', '.github', 'node_modules')) { Add-VendorLegalFiles $entry.FullName $relative }
        } elseif ($entry.Name -match '^(?i:LICENSE|LICENCE|NOTICE|COPYING)(?:$|[._-])') {
            Add-PackageFile $entry.FullName $relative $true
        }
    }
}

function Add-PackageTree {
    param([string] $Directory, [string] $RelativePath, [bool] $IsVendor = $false)
    Assert-NoReparseAncestors $Directory
    if (-not (Test-Path -LiteralPath $Directory -PathType Container)) { throw "Required source directory is missing: $RelativePath" }
    # Explicit traversal rejects junctions before entering them. Do not replace
    # this with a recursive copy that can silently follow redirected directories.
    foreach ($entry in Get-ChildItem -LiteralPath $Directory -Force) {
        if (($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Package contains a symbolic link or junction: $RelativePath/$($entry.Name)" }
        $relative = "$RelativePath/$($entry.Name)"
        if ($entry.PSIsContainer) {
            if ($IsVendor -and $entry.Name -in @('test', 'tests', 'doc', 'docs', 'example', 'examples', '.github', '.git')) {
                if ($entry.Name -notin @('.git', '.github')) { Add-VendorLegalFiles $entry.FullName $relative }
                continue
            }
            if ($entry.Name -in @('.git', '.cache', 'node_modules')) { throw "Unexpected runtime/dependency directory in source allowlist: $relative" }
            Add-PackageTree $entry.FullName $relative $IsVendor
        } else {
            if ($IsVendor -and ($entry.Name -like 'README*' -or $entry.Name -like 'CHANGELOG*')) { continue }
            Add-PackageFile $entry.FullName $relative $IsVendor
        }
    }
}

$rootFiles = @(
    '.env.example', '.htaccess', '.npmrc', 'index.php', 'install.php', 'artisan',
    'app.json', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'vite.config.js', 'VERSION', 'CHANGELOG.md', 'CLEAN-RELEASE.md'
)
foreach ($relative in $rootFiles) { Add-PackageFile (Join-Path $SourceRoot $relative) $relative }
foreach ($relative in @('app', 'config', 'routes', 'lang', 'resources', 'database/migrations', 'database/seeders', 'database/factories', 'gifts', 'server-configs')) {
    Add-PackageTree (Join-Path $SourceRoot $relative) $relative
}
foreach ($relative in @(
    'bootstrap/app.php', 'resources/keys/playhub-update-public.pem',
    'branding/logo.svg', 'branding/favicon.svg', 'branding/playhub-search-logo.png',
    'livekit/docker-compose.yml', 'livekit/entrypoint.sh', 'livekit/install.sh',
    'livekit/status-exporter.sh', 'livekit/playhub-livekit-manager@.service',
    'tools/start-queue-worker.ps1', 'tools/start-scheduler.ps1',
    'tools/live-health.cjs', 'tools/live-status-exporter.cjs',
    'tools/package-clean-website.ps1',
    'docs/live-server-installation.md', 'docs/web-version-update-2026-10-10.md',
    'docs/manual-update-0.9.12-to-0.9.13.md', 'docs/web-0.9.13-hardening-2026-10-16.md',
    'docs/one-click-update-publishing.md',
    'docs/openapi/playhub-v1.yaml'
)) { Add-PackageFile (Join-Path $SourceRoot $relative) $relative }

# Traverse only entries reachable from the current manifest's application entry
# points. Old hashed files in build/assets are intentionally not copied.
$manifestPath = Join-Path $SourceRoot 'build/manifest.json'
Add-PackageFile $manifestPath 'build/manifest.json'
$manifest = [IO.File]::ReadAllText($manifestPath) | ConvertFrom-Json
$entries = @{}
foreach ($property in $manifest.PSObject.Properties) { $entries[$property.Name] = $property.Value }
$pending = [Collections.Generic.Queue[string]]::new()
foreach ($entry in @('resources/js/main.jsx', 'resources/js/embed-player.jsx', 'resources/js/livekitRecordingTemplate.js')) {
    if (-not $entries.ContainsKey($entry)) { throw "Vite application entry missing: $entry" }
    $pending.Enqueue($entry)
}
$visited = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
while ($pending.Count -gt 0) {
    $key = $pending.Dequeue()
    if (-not $visited.Add($key)) { continue }
    if (-not $entries.ContainsKey($key)) { throw "Vite manifest dependency is missing: $key" }
    $entry = $entries[$key]
    $entryFile = $entry.PSObject.Properties['file']
    if ($null -eq $entryFile -or $entryFile.Value -isnot [string]) { throw "Invalid Vite file entry: $key" }
    $assets = [Collections.Generic.List[string]]::new()
    $assets.Add($entryFile.Value)
    foreach ($field in @('css', 'assets')) {
        $property = $entry.PSObject.Properties[$field]
        if ($null -ne $property) {
            if ($property.Value -isnot [array]) { throw "Vite $field must be an array: $key" }
            foreach ($asset in $property.Value) {
                if ($asset -isnot [string]) { throw "Invalid Vite asset: $key" }
                $assets.Add($asset)
            }
        }
    }
    foreach ($asset in $assets) {
        $relativeAsset = Get-SafeRelativePath $asset
        Add-PackageFile (Join-Path $SourceRoot "build/$relativeAsset") "build/$relativeAsset"
    }
    foreach ($field in @('imports', 'dynamicImports')) {
        $property = $entry.PSObject.Properties[$field]
        if ($null -ne $property) {
            if ($property.Value -isnot [array]) { throw "Vite $field must be an array: $key" }
            foreach ($dependency in $property.Value) {
                if ($dependency -isnot [string]) { throw "Invalid Vite dependency: $key" }
                $pending.Enqueue($dependency)
            }
        }
    }
}

$vendorIncluded = $false
if ($VendorDirectory) {
    $VendorDirectory = [IO.Path]::GetFullPath($VendorDirectory).TrimEnd('\', '/')
    Assert-NoReparseAncestors $VendorDirectory
    if ($VendorDirectory.StartsWith($Destination + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or
        [string]::Equals($VendorDirectory, $Destination, [StringComparison]::OrdinalIgnoreCase)) { throw 'VendorDirectory cannot be inside the package destination.' }
    foreach ($relative in @('autoload.php', 'composer/installed.json')) {
        Assert-PackageFile (Join-Path $VendorDirectory $relative) "vendor/$relative" $true
    }
    $installed = [IO.File]::ReadAllText((Join-Path $VendorDirectory 'composer/installed.json')) | ConvertFrom-Json
    if ($null -eq $installed.PSObject.Properties['dev'] -or $installed.dev -ne $false -or
        $null -eq $installed.PSObject.Properties['packages']) { throw 'VendorDirectory must be installed with composer install --no-dev (Composer 2 installed.json metadata required).' }
    $installedNames = @($installed.packages | ForEach-Object { $_.name })
    $developmentNames = @()
    if ($null -ne $installed.PSObject.Properties['dev-package-names']) { $developmentNames = @($installed.'dev-package-names') }
    $lock = [IO.File]::ReadAllText((Join-Path $SourceRoot 'composer.lock')) | ConvertFrom-Json
    $developmentNames += @($lock.'packages-dev' | ForEach-Object { $_.name })
    foreach ($name in $developmentNames) {
        if ($installedNames -contains $name) { throw "Development dependency is installed in VendorDirectory: $name" }
    }
    foreach ($package in $lock.packages) {
        $matches = @($installed.packages | Where-Object { $_.name -eq $package.name -and $_.version -eq $package.version })
        if ($matches.Count -ne 1) { throw "VendorDirectory does not match the production composer.lock: $($package.name)" }
    }
    if ($installedNames.Count -ne @($lock.packages).Count) { throw 'VendorDirectory contains unexpected dependencies not present in production composer.lock.' }
    Add-PackageTree $VendorDirectory 'vendor' $true
    $vendorIncluded = $true
}

# All validation above is read-only. Only a NEW, scoped destination is created.
if (-not (Test-Path -LiteralPath $releaseRoot)) { [void] (New-Item -ItemType Directory -Path $releaseRoot) }
Assert-NoReparseAncestors $Destination
if (Test-Path -LiteralPath $Destination) { throw 'Destination appeared during validation; refusing to overwrite it.' }
[void] (New-Item -ItemType Directory -Path $Destination)
$fileRecords = [Collections.Generic.List[object]]::new()
foreach ($relative in @($planned.Keys | Sort-Object)) {
    $inputPath = $planned[$relative]
    Assert-PackageFile $inputPath $relative ($vendorPaths.Contains($relative))
    $target = [IO.Path]::GetFullPath((Join-Path $Destination $relative))
    if (-not $target.StartsWith($Destination + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Package target escaped its destination.' }
    Assert-NoReparseAncestors $target
    [void] [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target))
    [IO.File]::Copy($inputPath, $target, $false)
    $fileRecords.Add([ordered]@{ path = $relative; size_bytes = (Get-Item -LiteralPath $target).Length; sha256 = (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant() })
}
foreach ($relative in @(
    'uploads', 'plugins', 'public', 'storage/app', 'storage/app/private', 'storage/app/public',
    'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views',
    'storage/logs', 'bootstrap/cache', 'livekit/runtime/certs', 'livekit/status'
)) {
    $targetDirectory = Join-Path $Destination $relative
    Assert-NoReparseAncestors $targetDirectory
    [void] [IO.Directory]::CreateDirectory($targetDirectory)
    $marker = Join-Path $targetDirectory '.gitkeep'
    [IO.File]::WriteAllBytes($marker, [byte[]]@())
    $fileRecords.Add([ordered]@{ path = "$relative/.gitkeep"; size_bytes = 0; sha256 = (Get-FileHash -LiteralPath $marker -Algorithm SHA256).Hash.ToLowerInvariant() })
}
$report = [ordered]@{
    schema_version = 1
    product = 'playhub'
    version = $version
    package_type = 'fresh-install-source'
    created_at = [DateTime]::UtcNow.ToString('o')
    existing_installation_modified = $false
    database_included = $false
    customer_uploads_included = $false
    installation_settings_included = $false
    private_credentials_included = $false
    public_update_verification_key_included = $true
    production_vendor_included = $vendorIncluded
    composer_install_required = (-not $vendorIncluded)
    composer_command = 'composer install --no-dev --prefer-dist --optimize-autoloader'
    vite_manifest_entries = $visited.Count
    file_count = $fileRecords.Count
    files = @($fileRecords | Sort-Object { $_.path })
    limitations = @(
        'This is a fresh installation source package, not a signed update for existing customer sites.',
        'Use the installer with an empty database and new owner credentials; no local database was reset.',
        'Configure your own deployment secrets, HTTPS, storage, queues and push credentials after installation.',
        'Source-file screening is defense in depth, not a substitute for a security review of release contents.'
    )
}
$reportPath = Join-Path $Destination 'release-manifest.json'
$json = $report | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($reportPath, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
Write-Output "Fresh website source package created: $Destination"
Write-Output "Files: $($fileRecords.Count); current Vite entries: $($visited.Count); production vendor: $vendorIncluded"
if (-not $vendorIncluded) { Write-Warning 'Composer dependencies are NOT bundled. Run composer install --no-dev --prefer-dist --optimize-autoloader before opening install.php.' }
Write-Output 'No database, uploads, credentials, existing files or services were modified.'
