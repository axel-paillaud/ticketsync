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
function docker_exec(string $command, bool $useCurrentSymlink = false, ?string $user = null): string
{
    $targetPath = $useCurrentSymlink ? '{{deploy_path}}/current' : '{{release_path}}';
    $userFlag = $user ? "--user $user " : '';
    return "cd {{release_path}} && {{docker_compose_cmd}} exec -T $userFlag{{docker_service}} sh -c 'cd $targetPath && $command'";
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
    run(docker_exec('npm install --omit=dev --cache /tmp/.npm', false, 'www-data'));
    run(docker_exec('npm run sass', false, 'www-data'));
    run(docker_exec('php bin/console importmap:install', false, 'www-data'));
    run(docker_exec('php bin/console asset-map:compile', false, 'www-data'));
    run(docker_exec('rm -rf node_modules', false, 'www-data'));
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

// Pre-migration backups (run on the host over SSH, not inside the container).
// The db/ directory is itself archived by borg.
set('backup_dir', '/mnt/backup/ticketsync/db');
set('backup_keep', 10);

task('database:backup', function () {
    $dbPath = '{{deploy_path}}/shared/var/data_prod.db';
    $dir = get('backup_dir');

    if (!test("[ -s $dbPath ]")) {
        info('No database yet, skipping backup.');
        return;
    }

    // The directory lives on the backup disk: if it is missing, the disk is probably not mounted
    if (!test("[ -d $dir ] && [ -w $dir ]")) {
        throw error("Backup directory $dir is missing or not writable (is the backup disk mounted?).");
    }

    if (!test('command -v sqlite3 >/dev/null 2>&1')) {
        throw error('sqlite3 is required on the host to back up the database.');
    }

    $file = "$dir/data_prod_predeploy_release-{{release_name}}_" . date('Y-m-d_His') . '.db';

    // ".backup" uses SQLite's online backup API: the copy stays consistent even if the app writes meanwhile
    run("sqlite3 $dbPath \".backup '$file'\"");
    run("chmod 600 $file");

    if (trim(run("sqlite3 $file 'PRAGMA integrity_check'")) !== 'ok') {
        throw error("Backup $file failed the integrity check.");
    }

    // Keep only the most recent pre-deploy backups; manual backups are left untouched
    $keep = (int) get('backup_keep');
    run("ls -1t $dir/data_prod_predeploy_*.db | tail -n +" . ($keep + 1) . " | xargs -r rm -f --");

    info("Database backed up to $file");
})->desc('Back up the SQLite database before migrations');

task('database:migrate', function () {
    $dbPath = '{{deploy_path}}/shared/var/data_prod.db';

    if (test("[ -f $dbPath ] && [ -s $dbPath ]")) {
        run(docker_exec('php bin/console doctrine:migrations:migrate --no-interaction', false, 'www-data'));
    }
})->desc('Run migrations');

// Override Symfony recipe cache tasks to use Docker
task('deploy:cache:clear', function () {
    run(docker_exec('php bin/console cache:clear --env=prod --no-warmup', false, 'www-data'));
})->desc('Clear cache');

task('deploy:cache:warmup', function () {
    run(docker_exec('php bin/console cache:warmup --env=prod', false, 'www-data'));
})->desc('Warmup cache');

task('deploy:vendors', function () {
    run(docker_exec('composer install --no-dev --no-progress --no-interaction --prefer-dist --optimize-autoloader', false, 'www-data'));
})->desc('Install dependencies');

task('deploy:remove_var', function () {
    run('cd {{release_path}} && {{docker_compose_cmd}} exec -T {{docker_service}} rm -rf {{release_path}}/var || true');
})->desc('Remove var directory');

task('deploy:fix_permissions', function () {
    // Set ownership to axel:www-data for the entire release
    run('chown -R axel:www-data {{release_path}} || true');
    // Ensure group has write permissions on directories
    run('find {{release_path}} -type d -exec chmod 775 {} + || true');
})->desc('Fix file permissions for www-data');

// Hooks
after('deploy:failed', 'deploy:unlock');
after('deploy:update_code', 'docker:build');
after('docker:build', 'docker:up');
before('deploy:shared', 'deploy:remove_var');
after('deploy:shared', 'deploy:fix_permissions');
after('deploy:vendors', 'deploy:assets');
after('deploy:assets', 'database:prepare');
after('database:prepare', 'database:migrate');
before('database:migrate', 'database:backup');
after('deploy:symlink', 'docker:restart');
after('rollback', 'docker:restart');

// First deploy task (uploads .env then runs standard deploy)
task('deploy:first', [
    'deploy:upload_env',
    'deploy',
])->desc('First deploy (with .env upload)');
