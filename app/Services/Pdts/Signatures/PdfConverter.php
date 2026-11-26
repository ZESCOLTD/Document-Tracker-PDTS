<?php

namespace App\Services\Pdts\Signatures;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a filled Word file into the PDF that is signed and kept.
 *
 * LibreOffice (free, runs without a screen on any server) is used when it is
 * installed. On a Windows computer with Microsoft Word, Word itself can do
 * it, which suits a developer's machine. Which one is used is set in
 * config/pdts.php (signatures.pdf_converter).
 */
class PdfConverter
{
    /** "libreoffice", "word", or null when this computer has neither. */
    public function engine(): ?string
    {
        $choice = (string) config('pdts.signatures.pdf_converter', 'auto');

        return match ($choice) {
            'libreoffice' => $this->sofficePath() ? 'libreoffice' : null,
            'word' => $this->wordAvailable() ? 'word' : null,
            'none' => null,
            default => $this->sofficePath() ? 'libreoffice' : ($this->wordAvailable() ? 'word' : null),
        };
    }

    public function available(): bool
    {
        return $this->engine() !== null;
    }

    public function convert(string $docx): string
    {
        // Word or LibreOffice can take longer than PHP's usual 30 seconds for a web
        // page (the first conversion starts the program): allow the conversion's
        // own time limit, plus a margin for the rest of the request.
        if (function_exists('set_time_limit')) {
            @set_time_limit((int) config('pdts.signatures.convert_timeout', 120) + 60);
        }

        $engine = $this->engine();
        if ($engine === null) {
            throw new RuntimeException('Signed PDFs cannot be made on this server yet: LibreOffice is not installed. Ask the system administrator.');
        }

        $directory = storage_path('app/tmp/pdf-'.Str::random(20));
        File::ensureDirectoryExists($directory);
        $source = $directory.DIRECTORY_SEPARATOR.'document.docx';
        $target = $directory.DIRECTORY_SEPARATOR.'document.pdf';
        file_put_contents($source, $docx);

        try {
            $engine === 'libreoffice'
                ? $this->withLibreOffice($source, $directory)
                : $this->withWord($source, $target);

            if (! is_file($target) || filesize($target) === 0) {
                throw new RuntimeException('The signed PDF could not be made. Try again, or ask the system administrator.');
            }

            return (string) file_get_contents($target);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function withLibreOffice(string $source, string $directory): void
    {
        // Its own profile folder, so a LibreOffice already open on the server does not get in the way.
        $profile = 'file:///'.ltrim(str_replace('\\', '/', $directory.DIRECTORY_SEPARATOR.'profile'), '/');

        $result = Process::timeout((int) config('pdts.signatures.convert_timeout', 120))->env($this->windowsEnvironment())->run([
            $this->sofficePath(),
            '-env:UserInstallation='.$profile,
            '--headless', '--norestore', '--nolockcheck',
            '--convert-to', 'pdf',
            '--outdir', $directory,
            $source,
        ]);

        if (! $result->successful()) {
            report(new RuntimeException('LibreOffice could not convert the document: '.$result->errorOutput()));
        }
    }

    private function withWord(string $source, string $target): void
    {
        $script = '$ErrorActionPreference = "Stop"; $w = New-Object -ComObject Word.Application; $w.Visible = $false; $w.DisplayAlerts = 0; '
            .'try { $d = $w.Documents.Open($env:PDTS_SRC, $false, $true); $d.ExportAsFixedFormat($env:PDTS_DST, 17); $d.Close($false) } finally { $w.Quit() }';

        $result = Process::timeout((int) config('pdts.signatures.convert_timeout', 120))
            ->env($this->windowsEnvironment() + ['PDTS_SRC' => $source, 'PDTS_DST' => $target])
            ->run([$this->windowsEnvironment()['SystemRoot'].'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script]);

        if (! $result->successful()) {
            report(new RuntimeException('Microsoft Word could not convert the document: '.$result->errorOutput()));
        }
    }

    /**
     * The Windows settings PowerShell, Word and LibreOffice need to start.
     * `php artisan serve` runs the site with only a few environment variables,
     * so they are passed on here, with Windows' usual values as a fallback.
     *
     * @return array<string, string>
     */
    private function windowsEnvironment(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $value = fn (string $name, string $fallback) => (string) (getenv($name) ?: ($_SERVER[$name] ?? $_ENV[$name] ?? $fallback));
        $root = $value('SystemRoot', $value('SYSTEMROOT', 'C:\\Windows'));
        // The profile folder of the account the website runs as.
        $profile = $value('USERPROFILE', $value('HOMEDRIVE', 'C:').$value('HOMEPATH', '\\Users\\'.get_current_user()));
        $temp = $value('TEMP', sys_get_temp_dir());

        return array_filter([
            'SystemRoot' => $root,
            'windir' => $value('windir', $root),
            'SystemDrive' => $value('SystemDrive', substr($root, 0, 2)),
            'ComSpec' => $value('ComSpec', $root.'\\System32\\cmd.exe'),
            'PATH' => $value('PATH', $root.'\\System32;'.$root.';'.$root.'\\System32\\WindowsPowerShell\\v1.0'),
            'PATHEXT' => $value('PATHEXT', '.COM;.EXE;.BAT;.CMD'),
            'TEMP' => $temp,
            'TMP' => $value('TMP', $temp),
            'USERPROFILE' => $profile,
            'APPDATA' => $value('APPDATA', $profile.'\\AppData\\Roaming'),
            'LOCALAPPDATA' => $value('LOCALAPPDATA', $profile.'\\AppData\\Local'),
            'ProgramData' => $value('ProgramData', 'C:\\ProgramData'),
            'ProgramFiles' => $value('ProgramFiles', 'C:\\Program Files'),
            'ProgramFiles(x86)' => $value('ProgramFiles(x86)', 'C:\\Program Files (x86)'),
            'CommonProgramFiles' => $value('CommonProgramFiles', 'C:\\Program Files\\Common Files'),
            'USERNAME' => $value('USERNAME', ''),
            'USERDOMAIN' => $value('USERDOMAIN', ''),
            'COMPUTERNAME' => $value('COMPUTERNAME', ''),
            'PSModulePath' => $value('PSModulePath', $root.'\\System32\\WindowsPowerShell\\v1.0\\Modules'),
        ], fn ($v) => $v !== '');
    }

    public function sofficePath(): ?string
    {
        $configured = config('pdts.signatures.soffice_path');
        if ($configured) {
            return is_file($configured) ? $configured : null;
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['C:\\Program Files\\LibreOffice\\program\\soffice.exe', 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe']
            : ['/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice', '/Applications/LibreOffice.app/Contents/MacOS/soffice'];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function wordAvailable(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        static $found = null;

        // Word registers this class when it is installed.
        return $found ??= Process::timeout(15)->env($this->windowsEnvironment())
            ->run([$this->windowsEnvironment()['SystemRoot'].'\\System32\\reg.exe', 'query', 'HKEY_CLASSES_ROOT\\Word.Application\\CLSID'])->successful();
    }
}
