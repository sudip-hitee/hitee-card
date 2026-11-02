<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ParkingCsvImportService;
use App\Services\ParkingImportService;
use Illuminate\Console\Command;

class ImportParkingCsv extends Command
{
    protected $signature = 'parking:import-csv
                            {file : Path to the parking lots CSV export (Google or OSM format)}
                            {--skip-images : Import data only, don\'t download pictures}
                            {--merchant= : Email of the merchant to assign (defaults to Hitee Solution)}';

    protected $description = 'Import parking lots from a CSV export (Google places or OSM format, one image per lot)';

    public function handle(ParkingCsvImportService $importer, ParkingImportService $parkingImporter): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $file = $this->argument('file');

        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $rows = $this->readRows($file);
        if ($rows === null) {
            $this->error('Invalid CSV — expected a header row and one parking lot per row.');
            return self::FAILURE;
        }

        $merchant = $this->resolveMerchant($parkingImporter);
        if (!$merchant) {
            return self::FAILURE;
        }

        $withImages = !$this->option('skip-images');
        $this->info('Importing ' . count($rows) . " parking lots for merchant {$merchant->name}…");

        $result = $importer->import($rows, $merchant, $withImages);

        $this->info("Created: {$result['created']}  Updated: {$result['updated']}  Images: {$result['images']}  Failed: {$result['failed']}");

        foreach ($result['errors'] as $error) {
            $this->warn("  • {$error}");
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string|null>>|null
     */
    private function readRows(string $file): ?array
    {
        $handle = fopen($file, 'r');
        if (!$handle) {
            return null;
        }

        $headers = fgetcsv($handle);
        if ($headers === false || $headers === []) {
            fclose($handle);
            return null;
        }

        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($headers)) {
                fclose($handle);
                return null;
            }

            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $rows;
    }

    private function resolveMerchant(ParkingImportService $parkingImporter): ?User
    {
        if ($email = $this->option('merchant')) {
            $merchant = User::where('email', $email)->first();
            if (!$merchant) {
                $this->error("No user found with email {$email}.");
            }
            return $merchant;
        }

        return $parkingImporter->defaultMerchant();
    }
}
