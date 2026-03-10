<?php

namespace MakeDev\Orca\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupScreenshots extends Command
{
    protected $signature = 'orca:cleanup-screenshots {--hours=24 : Delete files older than this many hours}';

    protected $description = 'Delete old Orca screenshot files';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $disk = config('orca.screenshots.disk', 'local');
        $directory = config('orca.screenshots.directory', 'orca/screenshots');
        $storage = Storage::disk($disk);

        $files = $storage->files($directory);
        $cutoff = now()->subHours($hours)->getTimestamp();
        $deleted = 0;

        foreach ($files as $file) {
            if ($storage->lastModified($file) < $cutoff) {
                $storage->delete($file);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} screenshot(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }
}
