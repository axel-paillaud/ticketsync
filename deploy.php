<?php

namespace Deployer;

require 'recipe/symfony.php';

// Configuration
set('application', 'TicketSync');
set('repository', 'git@github.com:axel-paillaud/ticketsync.git');
set('keep_releases', 3);
set('default_timeout', 300);

// Docker
set('docker_compose_cmd', 'docker compose -p ticketsync');
set('docker_service', 'app');

// Shared files/dirs between deploys
add('shared_files', [
    '.env.production.local',
    'var/data_prod.db',
]);

add('shared_dirs', [
    'var/log',
    'var/uploads',
]);

// Disable writable task (Docker handles permissions)
set('writable_dirs', []);
set('writable_mode', 'chmod');
set('writable_use_sudo', false);

// Host
host('production')
    ->set('hostname', 'ps-nas-wan')
    ->set('deploy_path', '/var/www/ticketsync')
    ->set('branch', 'main')
    ->set('http_user', 'www-data');

// Helper: run commands in Docker container
function docker_exec(string $command, bool $useCurrentSymlink = false): string
{
    $targetPath = $useCurrentSymlink ? '{{deploy_path}}/current' : '{{release_path}}';
    return "cd {{release_path}} && {{docker_compose_cmd}} exec -T {{docker_service}} sh -c 'cd $targetPath && $command'";
}

// Override writable task (Docker handles permissions)
task('deploy:writable', function () {});

// Tasks
task('deploy:upload_env', function () {
    upload('.env.production.local', '{{deploy_path}}/shared/.env.production.local');
})->once()->desc('Upload .env file');

task('docker:build', function () {
    run('cd {{release_path}} && {{docker_compose_cmd}} build --pull');
})->desc('Build Docker images');

task('docker:up', function () {
    run('cd {{release_path}} && {{docker_compose_cmd}} down 2>/dev/null || true');
    run('cd {{release_path}} && {{docker_compose_cmd}} up -d');
})->desc('Start Docker containers');

task('docker:restart', function () {
    $path = test('[ -L {{deploy_path}}/current ]') ? '{{deploy_path}}/current' : '{{release_path}}';
    run("cd $path && {{docker_compose_cmd}} restart");
})->desc('Restart Docker containers');

task('docker:down', function () {
    $path = test('[ -L {{deploy_path}}/current ]') ? '{{deploy_path}}/current' : '{{release_path}}';
    run("cd $path && {{docker_compose_cmd}} down");
})->desc('Stop Docker containers');

task('deploy:assets', function () {
    run(docker_exec('npm install --production'));
    run(docker_exec('npm run sass'));
    run(docker_exec('php bin/console importmap:install'));
    run(docker_exec('php bin/console asset-map:compile'));
    run(docker_exec('rm -rf node_modules'));
})->desc('Build assets');

task('database:prepare', function () {
    $dbPath = '{{deploy_path}}/shared/var/data_prod.db';
    run('mkdir -p {{deploy_path}}/shared/var');

    if (!test("[ -f $dbPath ]")) {
        writeln('<info>Empty database created. Create schema manually:</info>');
        writeln('<comment>  docker exec -it ticketsync_app bash</comment>');
        writeln('<comment>  cd release && php bin/console doctrine:schema:create --env=prod</comment>');
        writeln('<comment>  php bin/console doctrine:fixtures:load --group=prod --env=prod --no-interaction</comment>');
        writeln('<comment>  php bin/console doctrine:migrations:version --add --all --env=prod</comment>');
        run("touch $dbPath");
    }

    run("chmod 666 $dbPath");
})->desc('Prepare database file');

task('database:migrate', function () {
    $dbPath = '{{deploy_path}}/shared/var/data_prod.db';

    if (test("[ -f $dbPath ] && [ -s $dbPath ]")) {
        run(docker_exec('php bin/console doctrine:migrations:migrate --no-interaction'));
    }
})->desc('Run migrations');

task('deploy:cache', function () {
    run(docker_exec('php bin/console cache:clear --env=prod --no-warmup'));
    run(docker_exec('php bin/console cache:warmup --env=prod'));
})->desc('Clear and warmup cache');

task('deploy:vendors', function () {
    run(docker_exec('composer install --no-dev --no-progress --no-interaction --prefer-dist --optimize-autoloader'));
})->desc('Install dependencies');

task('deploy:remove_var', function () {
    run('cd {{release_path}} && {{docker_compose_cmd}} exec -T {{docker_service}} rm -rf {{release_path}}/var || true');
})->desc('Remove var directory');

task('deploy:fix_permissions', function () {
    run('chown -R axel:www-data {{release_path}} || true');
})->desc('Fix file permissions after Docker operations');

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
after('deploy:cache', 'deploy:fix_permissions');
after('deploy:symlink', 'docker:restart');
after('rollback', 'docker:restart');

// Deploy tasks
task('deploy', [
    'deploy:prepare',
    'deploy:update_code',
    'deploy:shared',
    'deploy:cache:clear',
    'deploy:publish',
])->desc('Deploy TicketSync');

task('deploy:first', [
    'deploy:prepare',
    'deploy:upload_env',
    'deploy:update_code',
    'deploy:shared',
    'deploy:cache:clear',
    'deploy:publish',
])->desc('First deploy (with .env upload)');
