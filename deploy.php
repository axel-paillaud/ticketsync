<?php

namespace Deployer;

require 'recipe/symfony.php';

// Configuration
set('application', 'TicketSync');
set('repository', 'git@github.com:axel-paillaud/ticketsync.git');
set('keep_releases', 3);
set('default_timeout', 300);

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

// Tasks
desc('Upload .env.production.local file');
task('deploy:upload_env', function () {
    upload('.env.production.local', '{{deploy_path}}/shared/.env.production.local');
})->once();

desc('Build assets (SASS + importmap)');
task('deploy:assets', function () {
    // Install npm dependencies
    run('cd {{release_path}} && npm install');

    // Compile SASS
    run('cd {{release_path}} && npm run sass');

    // Install and compile importmap assets
    run('cd {{release_path}} && {{bin/php}} bin/console importmap:install');
    run('cd {{release_path}} && {{bin/php}} bin/console asset-map:compile');
});

desc('Prepare SQLite database file');
task('database:prepare', function () {
    $dbPath = '{{deploy_path}}/shared/var/data_prod.db';

    // Create var directory if it doesn't exist
    run('mkdir -p {{deploy_path}}/shared/var');

    // Create database file if it doesn't exist
    run("test -f $dbPath || touch $dbPath");

    // Set proper permissions
    run("chmod 664 $dbPath");
    run("chown {{remote_user}}:{{http_user}} $dbPath");
});

desc('Run database migrations');
task('database:migrate', function () {
    run('cd {{release_path}} && {{bin/php}} bin/console doctrine:migrations:migrate --no-interaction');
});

desc('Clear and warmup cache');
task('deploy:cache', function () {
    run('cd {{release_path}} && {{bin/php}} bin/console cache:clear --env=prod --no-warmup');
    run('cd {{release_path}} && {{bin/php}} bin/console cache:warmup --env=prod');
});

// Hooks
after('deploy:failed', 'deploy:unlock');
after('deploy:vendors', 'deploy:assets');
after('deploy:cache:clear', 'deploy:cache');
before('database:migrate', 'database:prepare');
after('deploy:cache', 'database:migrate');

// Main deploy task
desc('Deploy TicketSync');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:cache:clear',
    'deploy:publish',
]);

// Rollback task
desc('Rollback to previous release');
task('rollback', [
    'rollback:rollback',
]);

// First deploy task (includes .env upload)
desc('First deploy with .env upload');
task('deploy:first', [
    'deploy:upload_env',
    'deploy',
]);
