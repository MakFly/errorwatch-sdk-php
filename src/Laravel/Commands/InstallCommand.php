<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'errorwatch:install
                            {--force : Overwrite existing config file}';

    protected $description = 'Install the ErrorWatch SDK and publish configuration';

    public function handle(): int
    {
        $this->info('Installing ErrorWatch SDK...');

        // Check if config already exists
        $configPath = config_path('errorwatch.php');
        $configExists = file_exists($configPath);

        if ($configExists && !$this->option('force')) {
            $this->warn('Configuration file already exists. Use --force to overwrite.');
            $this->line('');
            $this->line('To publish the config manually, run:');
            $this->line('  php artisan vendor:publish --tag=errorwatch-config --force');
            return self::SUCCESS;
        }

        // Publish the config
        $this->call('vendor:publish', [
            '--tag' => 'errorwatch-config',
            '--force' => $this->option('force'),
        ]);

        $this->line('');
        $this->info('✓ Configuration file published to config/errorwatch.php');

        // Show next steps
        $this->line('');
        $this->comment('Next steps:');
        $this->line('');
        $this->line('1. Add the following to your .env file:');
        $this->line('');
        $this->line('   ERRORWATCH_ENABLED=true');
        $this->line('   ERRORWATCH_ENDPOINT=https://api.errorwatch.io');
        $this->line('   ERRORWATCH_API_KEY=your-api-key-here');
        $this->line('');
        $this->line('2. Optionally configure additional settings in config/errorwatch.php');
        $this->line('');
        $this->line('3. Test the installation:');
        $this->line('   php artisan errorwatch:test');
        $this->line('');

        return self::SUCCESS;
    }
}
