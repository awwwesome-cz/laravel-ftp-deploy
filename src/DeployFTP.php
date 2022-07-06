<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deploy to FTP
 *
 * requirements:
 * - ZipArchive PHP extension on server
 * - zip command on device shell
 */
class DeployFTP extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:server {server} {--migrate} {--debug=1} ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy to server via FTP.';

    /**
     * @var string
     */
    protected string $disk = 'ftp';

    /**
     * Deploy path
     * @var string
     */
    protected string $deployPath = 'domains/test.foode.cz';

    /**
     * Domain for run final scripts
     * @var string
     */
    protected string $domain = 'test.foode.cz';

    /**
     * Random migration hash
     * @var string
     */
    protected string $migrationHash;

    /**
     * @var array
     */
    protected array $before = [];

    /**
     * @var array
     */
    protected array $excludes = [
        '.idea',
        '.phpunit.result.cache',
        '.DS_Store',
        '.editorconfig',
        '.gitattributes',
        '.git/*',
        '.github/*',
        '.scribe/*',
        '.env',
        'storage/app/images/*',
        'storage/app/public/*',
        'storage/logs/*',
        'storage/framework/cache/*',
        'storage/framework/sessions/*',
        'storage/framework/views/*',
        '.htaccess', // only for FTP shared hosting, original still exist
        'public/.htaccess', // only for FTP shared hosting, original still exist
        'public/index.php' // only for FTP shared hosting, original still exist
    ];

    /**
     * code archive name
     */
    const ARCHIVE_NAME = 'deploy.zip';

    /**
     * php deployment script name
     */
    const SCRIPT_NAME = 'deploy.php';

    /**
     * setup needed instances
     */
    private function setup()
    {
        // Additional setup
        $this->disk = $this->argument('server');
        if (env('ENV_DEPLOY', false)) {
            if (($key = array_search('.env', $this->excludes)) !== false) {
                unset($this->excludes[$key]);
            }
        }

        $this->migrationHash = Str::random(10); // generate random hash for client


    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // setup
        $this->setup();

        // run
        $this->info('------------------------------------------------------------------------');
        $this->info('deploy the project');
        $this->info('------------------------------------------------------------------------');
        $this->info("");
        $this->info("server:\t" . $this->argument('server'));
        $this->info("");

        $this->runBefore();
        $this->createArchive();
        $this->uploadFiles();
        $this->runDeploymentScript();
        // $this->cleanUpAfter();

        $this->info("");
        $this->info('------------------------------------------------------------------------');
        $this->info('deployment done!');
        $this->info('------------------------------------------------------------------------');
        return 0;
    }

    /**
     * Run exec commands before upload
     * @return void
     */
    private function runBefore()
    {
        $this->info('- Running commands before deployment.');

        foreach ($this->before as $cmd) {
            $this->info('- ' . $cmd);
            exec($cmd);
        }
    }

    /**
     * Run HTTP client GET method for unzip on server
     * @return void
     * @throws GuzzleException
     */
    private function runDeploymentScript()
    {
        $this->info('- Running deployment on server.');

        // call the deployment script
        $client = new Client();
        $response = $client->get($this->domain . "/" . static::SCRIPT_NAME);
        $responseMigrate = $client->get($this->domain . "/migrate?hash=$this->migrationHash&fresh&seed");

        if ($this->option('debug')) {
            $this->warn("\t- " . $response->getBody());
            $this->warn("\t- " . $responseMigrate->getBody());
        }

        // delete the script itself
        Storage::disk($this->disk)->delete($this->deployPath . "/public/" . static::SCRIPT_NAME);
    }

    /**
     * Upload ZIP to FTP
     * @return void
     */
    private function uploadFiles()
    {
        $this->info('- Uploading to server');

        Storage::disk($this->disk)->put($this->deployPath . "/" . static::ARCHIVE_NAME, Storage::get(static::ARCHIVE_NAME));
        Storage::disk($this->disk)->put($this->deployPath . "/public/" . static::SCRIPT_NAME, $this->getDeploymentCode());
    }

    /**
     * clean up after completion of the uploading
     */
    private function cleanUpAfter()
    {
        $this->info('- Cleaning up after uploading.');

        // delete created archive

        if (Storage::exists(static::ARCHIVE_NAME)) {
            Storage::delete(static::ARCHIVE_NAME);
            $this->info('- Cleaned');
        } else {
            $this->error('- Not cleared');
        }
    }

    /**
     * Replace string hash in .env file for deploy and migrate on FTP
     * @return void
     */
    private function createMigrationHashToEnvFile()
    {
        $env = file_get_contents(__DIR__ . "/../../../.env");
        if (preg_match("/^MIGRATION_HASH=[a-zA-Z0-9]*$/m", $env)) {
            $env = preg_replace("/^MIGRATION_HASH=[a-zA-Z0-9]*$/m", "MIGRATION_HASH=$this->migrationHash", $env);
        } else {
            $env = "$env\nMIGRATION_HASH=$this->migrationHash";
        }
        file_put_contents(__DIR__ . "/../../../.env", $env);
    }

    /**
     * create zip archive
     * @return $this
     */
    private function createArchive()
    {
        $this->info('- Building release zip.');

        // replace string hash in .env file for deploy and migrate on FTP
        $this->createMigrationHashToEnvFile();


        // delete old archive
        if (Storage::exists(static::ARCHIVE_NAME)) {
            Storage::delete(static::ARCHIVE_NAME);
        }

        // create new archive
        $this->createZipArchive();

        return $this;
    }

    /**
     * create the string to build the archive which will be uploaded
     *
     * @return void
     */
    private function createZipArchive()
    {
        $excludes = '';
        if (is_array($this->excludes)) {
            $excludes = "-x " . implode(" ", array_map(function ($v) {
                    return "\"$v\"";
                }, $this->excludes));
        }

        $rootPath = "./";
        $zip = exec('zip -r ' . static::ARCHIVE_NAME . ' ' . $rootPath . ' ' . $excludes);
        $mv = exec('mv ' . static::ARCHIVE_NAME . ' ' . storage_path('app'));

        if ($this->option('debug')) {
            $this->warn("\t- " . $zip);
            $this->warn("\t- " . $mv);
        }
    }


    /**
     * Replace content for WEDOS hosting
     * @return string
     */
    function replaceMinPHPVersionOnHosting(): string
    {
        return '
              // REPLACE content for WEDOS!
              $content = file_get_contents("../vendor/composer/platform_check.php");
              $content = str_replace("80002", \'80001\', $content);
              file_put_contents("../vendor/composer/platform_check.php", $content);
        ';
    }

    /**
     * return php deployment script code for unzipping archive and deleting old file
     *
     * Replacing content for WEDOS hosting
     * @return string
     */
    private function getDeploymentCode()
    {
        return '
            <?php
            // vars
            $archive = \'../' . static::ARCHIVE_NAME . '\';
            $zip = new ZipArchive;

            $res = $zip->open($archive);

            if ($res === TRUE) {
                $files = array();

                for($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    // if $filename not in destination / or whatever the logic is then
                        $files[] = $filename;
                }

                $zip->extractTo(\'../\', $files);
                $zip->close();

                // create storage folders
                if (!file_exists(\'../storage/framework/cache\') || !is_dir(\'../storage/framework/cache\')) {
                    mkdir(\'../storage/framework/cache\');
                }
                if (!file_exists(\'../storage/framework/sessions\') || !is_dir(\'../storage/framework/sessions\')) {
                    mkdir(\'../storage/framework/sessions\');
                }
                if (!file_exists(\'../storage/framework/testing\') || !is_dir(\'../storage/framework/testing\')) {
                    mkdir(\'../storage/framework/testing\');
                }
                if (!file_exists(\'../storage/framework/views\') || !is_dir(\'../storage/framework/views\')) {
                    mkdir(\'../storage/framework/views\');
                }


                // replace for hosting
                ' . $this->replaceMinPHPVersionOnHosting() . '

                echo "complete";
            } else {
                echo "error";
            }

';
    }
}
