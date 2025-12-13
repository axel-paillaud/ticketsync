# Deployment Guide - TicketSync (Docker)

## Prerequisites

### On your local machine
- Deployer installed globally (`composer global require deployer/deployer`)
- SSH access to production server
- Git configured with repository access

### On production server
- Docker and Docker Compose
- Git
- SSH access

**Note**: This project runs in a Docker container, so you don't need to install PHP, Composer, or Node.js directly on the host.

## Initial Configuration

### 1. Configure deploy.php

Edit the `deploy.php` file at project root and verify these values:

```php
set('repository', 'git@github.com:YOUR_USERNAME/ticketsync.git'); // Already configured

host('production')
    ->set('hostname', 'your-ssh-alias')         // Your SSH config alias
    ->set('deploy_path', '/var/www/ticketsync') // Deployment path on host
    ->set('branch', 'main')
    ->set('http_user', 'www-data');             // User for file permissions
```

### 2. Prepare the server

#### Install Docker and Docker Compose
```bash
# Install Docker
curl -fsSL https://get.docker.com | sh

# Add your user to docker group (optional, to run docker without sudo)
sudo usermod -aG docker $USER

# Install Docker Compose
sudo apt-get update
sudo apt-get install docker-compose-plugin

# Verify installation
docker --version
docker compose version
```

#### Create deployment directory
```bash
sudo mkdir -p /var/www/ticketsync
sudo chown $USER:$USER /var/www/ticketsync
```

#### Configure SSH access
From your local machine, verify SSH access works:
```bash
ssh your-ssh-alias  # Should connect successfully
```

### 3. Create .env.production.local file

Copy the example file and configure it:
```bash
cp .env.production.local.example .env.production.local
```

Edit `.env.production.local` with your actual values:
- `APP_SECRET`: Generate a secure random string (minimum 32 characters)
- `DATABASE_URL`: SQLite will use `var/data_prod.db` by default
- `DEFAULT_URI`: Your application URL (e.g., https://ticketsync.axelweb.fr)
- `MAILER_DSN`: Your SMTP server configuration

**IMPORTANT**: NEVER commit this file to Git!

### 4. Configure reverse proxy (optional but recommended)

If you want HTTPS and a custom domain, set up a reverse proxy on the host.

#### Using Nginx on host
Create `/etc/nginx/sites-available/ticketsync`:

```nginx
server {
    listen 80;
    server_name ticketsync.axelweb.fr;

    location / {
        proxy_pass http://localhost:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/ticketsync /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

Then configure SSL with Let's Encrypt:
```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d ticketsync.axelweb.fr
```

## Deployment

### First deployment
```bash
dep deploy:first production
```

This command will:
1. Upload your `.env.production.local` file
2. Clone the repository
3. Build the Docker image (Apache + PHP + Node.js)
4. Start the Docker container
5. Install Composer dependencies (inside container)
6. Install npm dependencies and compile assets (inside container)
7. Prepare SQLite database file
8. Run database migrations (inside container)
9. Activate the new version
10. Restart Docker container

**Note**: The first deployment takes longer because it builds the Docker image.

### Subsequent deployments
```bash
dep deploy production
```

This will:
- Pull latest code
- Rebuild Docker image if Dockerfile changed
- Update dependencies
- Compile assets
- Run migrations
- Restart containers

### Rollback in case of issues
```bash
dep rollback production
```

This will revert to the previous release and restart containers.

## Docker Container Structure

The application runs in a single Docker container (`ticketsync_app`) that includes:
- Apache 2.4
- PHP 8.2 with required extensions
- Composer
- Node.js 20 and npm (for SASS compilation)

Files are mounted from the host using bind mounts:
- Host: `/var/www/ticketsync/current/`
- Container: `/var/www/html/`

This allows you to access files directly on the host while the app runs in Docker.

## Available Deployer tasks

- `dep deploy production`: Deploy the application
- `dep rollback production`: Rollback to previous version
- `dep docker:build production`: Rebuild Docker image
- `dep docker:up production`: Start containers
- `dep docker:restart production`: Restart containers
- `dep docker:down production`: Stop containers
- `dep ssh production`: SSH into the server

## Managing the application

### Execute commands inside the container

From the server, you can run commands in the container:

```bash
cd /var/www/ticketsync/current

# Symfony console commands
docker compose exec ticketsync_app php bin/console cache:clear

# Database migrations
docker compose exec ticketsync_app php bin/console doctrine:migrations:migrate

# Compile SASS
docker compose exec ticketsync_app npm run sass

# Access container shell
docker compose exec ticketsync_app bash
```

### View logs

```bash
# Docker container logs
docker logs ticketsync_app

# Symfony logs (from host)
tail -f /var/www/ticketsync/shared/var/log/prod.log

# Apache logs (inside container)
docker compose exec ticketsync_app tail -f /var/log/apache2/error.log
```

### Restart the application

```bash
cd /var/www/ticketsync/current
docker compose restart
```

## Troubleshooting

### Permission issues with SQLite

```bash
# From the server
cd /var/www/ticketsync/shared/var
chmod 666 data_prod.db
```

### Container won't start

```bash
# Check logs
docker logs ticketsync_app

# Rebuild image
cd /var/www/ticketsync/current
docker compose build --no-cache
docker compose up -d
```

### Cache not cleared

```bash
dep docker:restart production
# Or from server:
cd /var/www/ticketsync/current
docker compose exec ticketsync_app php bin/console cache:clear --env=prod
```

### Port 80 already in use

If another service uses port 80, edit `docker-compose.yml` to use a different port:
```yaml
ports:
  - "8080:80"  # Use port 8080 on host instead
```

Then adjust your reverse proxy configuration accordingly.

## Maintenance

### View deployed releases
```bash
dep releases production
```

### Clean old releases (keeps only the last 3)
```bash
dep cleanup production
```

### Backup SQLite database

```bash
# From the server
cp /var/www/ticketsync/shared/var/data_prod.db ~/backup-$(date +%Y%m%d).db
```

## Development vs Production

**Development** (local machine):
- Use `.env.local` or `.env.dev`
- SQLite database in `var/data_dev.db`
- Run directly without Docker or use Docker for consistency

**Production** (server):
- Uses `.env.production.local` (uploaded once, stored in `shared/`)
- SQLite database in `shared/var/data_prod.db`
- Runs in Docker container
- Managed by Deployer

## Upgrading to PostgreSQL/MySQL (recommended for production)

While SQLite works for ~300 users, consider migrating to PostgreSQL or MySQL for better performance and concurrent access.

To migrate:
1. Add a database service to `docker-compose.yml`
2. Update `DATABASE_URL` in `.env.production.local`
3. Deploy and run migrations
4. Import existing data if needed
