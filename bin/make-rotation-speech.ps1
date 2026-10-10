param([Parameter(Mandatory=$true)][string]$WavePath,[Parameter(Mandatory=$true)][string]$OutputPath)
$ErrorActionPreference='Stop'
# This copies supplied synthetic audio; no model, microphone or external account request.
$bytes=[IO.File]::ReadAllBytes((Resolve-Path -LiteralPath $WavePath))
if([Text.Encoding]::ASCII.GetString($bytes,0,4) -ne 'RIFF' -or [Text.Encoding]::ASCII.GetString($bytes,8,4) -ne 'WAVE'){throw 'WAV fixture required'}
$position=12;$fmt=$null;$data=$null
while($position+8 -le $bytes.Length){$name=[Text.Encoding]::ASCII.GetString($bytes,$position,4);$length=[BitConverter]::ToUInt32($bytes,$position+4);if($position+8+$length -gt $bytes.Length){throw 'Invalid WAV chunk'};if($name -eq 'fmt '){$fmt=New-Object byte[] 16;[Array]::Copy($bytes,$position+8,$fmt,0,16)};if($name -eq 'data'){$data=New-Object byte[] $length;[Array]::Copy($bytes,$position+8,$data,0,$length)};$position+=8+$length+($length%2)}
if($null -eq $fmt -or $null -eq $data -or [BitConverter]::ToUInt16($fmt,0) -ne 1 -or [BitConverter]::ToUInt16($fmt,2) -ne 1 -or [BitConverter]::ToUInt32($fmt,4) -ne 16000 -or [BitConverter]::ToUInt16($fmt,14) -ne 16){throw '16 kHz mono PCM16 WAV required'}
$silence=New-Object byte[] (8*32000)
$payload=New-Object byte[] ($data.Length*3+$silence.Length*2)
$offset=0;for($repeat=0;$repeat -lt 3;$repeat++){[Array]::Copy($data,0,$payload,$offset,$data.Length);$offset+=$data.Length;if($repeat -lt 2){[Array]::Copy($silence,0,$payload,$offset,$silence.Length);$offset+=$silence.Length}}
$target=[IO.Path]::GetFullPath($OutputPath)
$stream=[IO.File]::Open($target,[IO.FileMode]::Create,[IO.FileAccess]::Write)
$writer=New-Object IO.BinaryWriter($stream)
try{$writer.Write([Text.Encoding]::ASCII.GetBytes('RIFF'));$writer.Write([uint32]($payload.Length+36));$writer.Write([Text.Encoding]::ASCII.GetBytes('WAVEfmt '));$writer.Write([uint32]16);$writer.Write($fmt);$writer.Write([Text.Encoding]::ASCII.GetBytes('data'));$writer.Write([uint32]$payload.Length);$writer.Write($payload)}finally{$writer.Dispose();$stream.Dispose()}
Write-Output ('Synthetic rotation WAV seconds: '+($payload.Length/32000))
