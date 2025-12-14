<?php

namespace Deployer;

require 'recipe/symfony.php';

// Configuration
set('application', 'TicketSync');
set('repository', 'git@github.com:axel-paillaud/ticketsync.git');
set('keep_releases', 3);
set('default_timeout', 300);

// Docker configuration
set('docker_compose_cmd', 'docker compose -p ticketsync');
set('docker_service', 'app');  // Service name in compose.yaml

// Shared files/dirs between deploys
add('shared_files', [
    '.env.production.local',
    'var/data_prod.db',  // SQLite database file
]);

add('shared_dirs', [
    'var/log',
    'var/uploads',
]);

// Writable dirs by web server
// Note: With Docker, writable permissions are managed by the container (runs as root)
// We don't need to run chmod from the host - override with empty array to disable
set('writable_dirs', []);  // Use set() instead of add() to override Symfony recipe defaults

set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');
set('writable_use_sudo', false);

// Hosts
host('production')
    ->set('hostname', 'ps-nas-wan')
    ->set('deploy_path', '/var/www/ticketsync')
    ->set('branch', 'main')
    ->set('http_user', 'www-data');

// Helper function to run commands in Docker container
function docker_exec(string $command, bool $useReleaseSymlink = false): string
{
    // Deployer 7 uses 'release' symlink (not 'current' like Deployer 6)
    $targetPath = $useReleaseSymlink ? '{{deploy_path}}/release' : '{{release_path}}';
    return "cd {{release_path}} && {{docker_compose_cmd}} exec -T {{docker_service}} sh -c 'cd $targetPath && $command'";
}

// Override deploy:writable to do nothing (Docker handles permissions)
desc('Skip writable (Docker handles permissions)');
task('deploy:writable', function () {
    writeln('<comment>Skipping writable task - Docker container handles permissions</comment>');
});

// Tasks
desc('Upload .env.production.local file');
task('deploy:upload_env', function () {
    upload('.env.production.local', '{{deploy_path}}/shared/.env.production.local');
})->once();

desc('Build Docker images');
task('docker:build', function () {
    run('cd {{release_path}} && {{docker_compose_cmd}} build --pull');
});

desc('Start Docker containers');
task('docker:up', function () {
    // Stop existing containers (if any) before starting new ones
    run('cd {{release_path}} && {{docker_compose_cmd}} down 2>/dev/null || true');

    // Start containers from the new release
    run('cd {{release_path}} && {{docker_compose_cmd}} up -d');
});

desc('Restart Docker containers');
task('docker:restart', function () {
    // Deployer 7 uses 'release' symlink
    $path = test('[ -L {{deploy_path}}/release ]') ? '{{deploy_path}}/release' : '{{release_path}}';
    run("cd $path && {{docker_compose_cmd}} restart");
});

desc('Stop Docker containers');
task('docker:down', function () {
    // Deployer 7 uses 'release' symlink
    $path = test('[ -L {{deploy_path}}/release ]') ? '{{deploy_path}}/release' : '{{release_path}}';
    run("cd $path && {{docker_compose_cmd}} down");
});

desc('Build assets (SASS + importmap)');
task('deploy:assets', function () {
    // Install npm dependencies
    run(docker_exec('npm install --production'));

    // Compile SASS
    run(docker_exec('npm run sass'));

    // Install and compile importmap assets
    run(docker_exec('php bin/console importmap:install'));
    run(docker_exec('php bin/console asset-map:compile'));
});

desc('Ensure SQLite database file exists with correct permissions');
task('database:prepare', function () {
    $dbPath = '{{deploy_path}}/shared/var/data_prod.db';

    // Create var directory if it doesn't exist
    run('mkdir -p {{deploy_path}}/shared/var');

    // Create empty file if it doesn't exist (schema creation is manual)
    $dbExists = test("[ -f $dbPath ]");
    if (!$dbExists) {
        writeln('<info>Creating empty database file. You need to create the schema manually:</info>');
        writeln('<comment>docker exec -it ticketsync_app bash</comment>');
        writeln('<comment>cd release && php bin/console doctrine:schema:create --env=prod</comment>');
        writeln('<comment>php bin/console doctrine:fixtures:load --group=prod --env=prod --no-interaction</comment>');
        run("touch $dbPath");
    }

    // Ensure correct permissions
    run("chmod 666 $dbPath");
});

desc('Run database migrations');
task('database:migrate', function () {
    // Only run migrations if database has tables (skip for empty DB)
    $dbPath = '{{deploy_path}}/shared/var/data_prod.db';
    $dbHasContent = test("[ -f $dbPath ] && [ -s $dbPath ]");

    if ($dbHasContent) {
        writeln('<info>Running database migrations...</info>');
        run(docker_exec('php bin/console doctrine:migrations:migrate --no-interaction'));
    } else {
        writeln('<comment>Skipping migrations (empty database - create schema manually first)</comment>');
    }
});

desc('Clear and warmup cache');
task('deploy:cache', function () {
    run(docker_exec('php bin/console cache:clear --env=prod --no-warmup'));
    run(docker_exec('php bin/console cache:warmup --env=prod'));
});

desc('Override deploy:vendors to run composer in Docker');
task('deploy:vendors', function () {
    run(docker_exec('composer install --no-dev --no-progress --no-interaction --prefer-dist --optimize-autoloader'));
});

desc('Remove var directory before shared symlinks');
task('deploy:remove_var', function () {
    // Remove var/ if it exists (Docker creates it as root)
    // Use docker exec to remove it as root from inside the container
    // This allows deploy:shared to create symlinks to shared/var/
    // Database and uploads are safe in shared/, never in releases
    run('cd {{release_path}} && {{docker_compose_cmd}} exec -T {{docker_service}} rm -rf {{release_path}}/var || true');
});

// Hooks
after('deploy:failed', 'deploy:unlock');
after('deploy:update_code', 'docker:build');
after('docker:build', 'docker:up');
after('docker:up', 'deploy:vendors');
after('deploy:vendors', 'deploy:assets');
before('deploy:shared', 'deploy:remove_var');
after('deploy:shared', 'database:prepare');
after('database:prepare', 'database:migrate');
after('database:migrate', 'deploy:cache:clear');
after('deploy:cache:clear', 'deploy:cache');
// Note: Skip deploy:writable - Docker container handles permissions
after('deploy:symlink', 'docker:restart');
after('rollback', 'docker:restart');

// Main deploy task
desc('Deploy TicketSync');
task('deploy', [
    'deploy:prepare',
    'deploy:update_code',
    'deploy:shared',
    'deploy:cache:clear',
    'deploy:publish',
]);

// First deploy task (includes .env upload)
desc('First deploy with .env upload');
task('deploy:first', [
    'deploy:prepare',
    'deploy:upload_env',
    'deploy:update_code',
    'deploy:shared',
    'deploy:cache:clear',
    'deploy:publish',
]);
