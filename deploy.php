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
set('docker_container', 'ticketsync_app');

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
add('writable_dirs', [
    'var',
    'var/cache',
    'var/log',
    'var/uploads',
]);

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
function docker_exec(string $command): string
{
    return "cd {{release_path}} && {{docker_compose_cmd}} exec -T {{docker_container}} sh -c 'cd /var/www/html && $command'";
}

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
    run('cd {{deploy_path}}/current && {{docker_compose_cmd}} restart');
});

desc('Stop Docker containers');
task('docker:down', function () {
    run('cd {{deploy_path}}/current && {{docker_compose_cmd}} down');
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

desc('Prepare SQLite database file');
task('database:prepare', function () {
    $dbPath = '{{deploy_path}}/shared/var/data_prod.db';

    // Create var directory if it doesn't exist
    run('mkdir -p {{deploy_path}}/shared/var');

    // Create database file if it doesn't exist
    run("test -f $dbPath || touch $dbPath");

    // Set proper permissions (readable/writable by container)
    run("chmod 666 $dbPath");
});

desc('Run database migrations');
task('database:migrate', function () {
    run(docker_exec('php bin/console doctrine:migrations:migrate --no-interaction'));
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

// Hooks
after('deploy:failed', 'deploy:unlock');
after('deploy:update_code', 'docker:build');
after('docker:build', 'docker:up');
after('docker:up', 'deploy:vendors');
after('deploy:vendors', 'deploy:assets');
after('deploy:cache:clear', 'deploy:cache');
before('database:migrate', 'database:prepare');
after('deploy:cache', 'database:migrate');
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
