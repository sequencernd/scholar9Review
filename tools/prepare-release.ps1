param(
    [string] $OutputDirectory = '',
    [string] $OjsVersion = '3.5.0.5'
)
$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot
$readerSettings = [Xml.XmlReaderSettings]::new()
$readerSettings.DtdProcessing = [Xml.DtdProcessing]::Ignore
$readerSettings.XmlResolver = $null
$reader = [Xml.XmlReader]::Create((Join-Path $pluginRoot 'version.xml'), $readerSettings)
$versionXml = [Xml.XmlDocument]::new()
try { $versionXml.Load($reader) } finally { $reader.Dispose() }
$version = [string] $versionXml.version.release
$releaseDate = [string] $versionXml.version.date
if ($version -notmatch '^\d+\.\d+\.\d+\.\d+$') { throw 'Release version must contain four numbers.' }
if ($OjsVersion -notmatch '^\d+\.\d+\.\d+\.\d+$') { throw 'Provide an exact OJS version with four numbers.' }
if (!$OutputDirectory) { $OutputDirectory = Join-Path $pluginRoot '.release' }
[IO.Directory]::CreateDirectory($OutputDirectory) | Out-Null
$outputRoot = (Resolve-Path -LiteralPath $OutputDirectory).Path
$archiveName = "scholar9Review-$version.tar.gz"
$archive = Join-Path $outputRoot $archiveName
if (Test-Path -LiteralPath $archive) { throw "Archive already exists. Move it aside or choose a new output directory: $archive" }
$stagingRoot = Join-Path ([IO.Path]::GetTempPath()) ('scholar9Release-' + [guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($stagingRoot) | Out-Null
$packageRoot = Join-Path $stagingRoot 'scholar9Review'
[IO.Directory]::CreateDirectory($packageRoot) | Out-Null
try {
    # Explicit allow-list prevents local credentials, tests and tools entering the installer.
    $runtime = @('classes','css','js','locale','pages','templates','index.php','Scholar9ReviewPlugin.php','version.xml','composer.json','LICENSE','README.md','SECURITY.md')
    foreach ($entry in $runtime) {
        $source = Join-Path $pluginRoot $entry
        if (!(Test-Path -LiteralPath $source)) { throw "Missing required distribution item: $entry" }
        Copy-Item -LiteralPath $source -Destination $packageRoot -Recurse
    }
    & tar -czf $archive -C $stagingRoot scholar9Review
    if ($LASTEXITCODE -ne 0) { throw 'tar failed to build the package.' }
    $entries = @(& tar -tzf $archive)
    if ($LASTEXITCODE -ne 0 -or !$entries.Count) { throw 'Archive verification failed.' }
    foreach ($entry in $entries) {
        if ($entry -notmatch '^scholar9Review/' -or $entry -match '(^|/)\.\.?(/|$)' -or $entry -match '(^|/)(tests|stubs|tools|\.git|\.github|\.vscode|\.release)(/|$)') {
            throw "Unexpected archive entry: $entry"
        }
    }
    $md5 = (Get-FileHash -LiteralPath $archive -Algorithm MD5).Hash.ToLowerInvariant()
    $sha256 = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    $encoding = [Text.UTF8Encoding]::new($false)
    [IO.File]::WriteAllText((Join-Path $outputRoot 'CHECKSUMS.txt'), "MD5 $md5 $archiveName`nSHA256 $sha256 $archiveName`n", $encoding)
    $gallery = @"
<?xml version="1.0" encoding="UTF-8"?>
<plugins xmlns="http://pkp.sfu.ca">
  <plugin category="generic" product="scholar9Review">
    <name locale="en">Scholar9 Transparent Peer Review</name>
    <name locale="en_US">Scholar9 Transparent Peer Review</name>
    <homepage>https://github.com/sequencernd/scholar9Review</homepage>
    <summary locale="en">Connect an OJS journal to Scholar9 for reviewer discovery and peer-review communication.</summary>
    <summary locale="en_US">Connect an OJS journal to Scholar9 for reviewer discovery and peer-review communication.</summary>
    <description locale="en"><![CDATA[<p>Integrates journal API-access requests, reviewer selection, messages, file sharing, review updates, ratings and controlled public review links with Scholar9. An approved Scholar9 service account and API credentials are required. New access applications also send journal/contact details to inquiry@ojscloud.com. See the repository README for data disclosure, service requirements and setup.</p>]]></description>
    <description locale="en_US"><![CDATA[<p>Integrates journal API-access requests, reviewer selection, messages, file sharing, review updates, ratings and controlled public review links with Scholar9. An approved Scholar9 service account and API credentials are required. New access applications also send journal/contact details to inquiry@ojscloud.com. See the repository README for data disclosure, service requirements and setup.</p>]]></description>
    <maintainer>
      <name>SEQUENCE RESEARCH &amp; DEVELOPMENT PRIVATE LIMITED</name>
      <institution>SEQUENCE RESEARCH &amp; DEVELOPMENT PRIVATE LIMITED</institution>
      <email>inquiry@ojscloud.com</email>
    </maintainer>
    <release date="$releaseDate" version="$version" md5="$md5">
      <package>https://github.com/sequencernd/scholar9Review/releases/download/v$version/$archiveName</package>
      <compatibility application="ojs2"><version>$OjsVersion</version></compatibility>
      <description locale="en">Initial Gallery submission of the Scholar9 integration. GPL-2.0-or-later.</description>
    </release>
  </plugin>
</plugins>
"@
    [IO.File]::WriteAllText((Join-Path $outputRoot 'gallery-entry.xml'), $gallery + "`n", $encoding)
    Write-Output "Package: $archive"
    Write-Output "MD5: $md5"
    Write-Output "SHA256: $sha256"
    Write-Output 'Gallery entry generated. Confirm end-to-end compatibility before submitting to PKP.'
} finally {
    $resolvedTemp = [IO.Path]::GetFullPath($stagingRoot)
    $expectedTemp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if ($resolvedTemp.StartsWith($expectedTemp, [StringComparison]::OrdinalIgnoreCase) -and ([IO.Path]::GetFileName($resolvedTemp) -match '^scholar9Release-[a-f0-9]{32}$')) {
        Remove-Item -LiteralPath $resolvedTemp -Recurse -Force
    }
}
