# Deployment Guide - TicketSync

## Prerequisites

### On your local machine
- Deployer installed globally (`composer global require deployer/deployer`)
- SSH access to production server
- Git configured with repository access

### On production server
- PHP 8.2 or higher
- Composer
- Node.js and npm (for SASS compilation)
- Database (PostgreSQL or MySQL recommended, SQLite supported)
- Web server (Nginx or Apache)
- Git

## Initial Configuration

### 1. Configure deploy.php

Edit the `deploy.php` file at project root and modify these values:

```php
set('repository', 'git@github.com:YOUR_USERNAME/ticketsync.git'); // Your Git repo

host('production')
    ->set('remote_user', 'deploy')              // Your SSH user
    ->set('hostname', 'your-server.com')        // Your server
    ->set('deploy_path', '/var/www/ticketsync') // Deployment path
    ->set('http_user', 'www-data');             // Web server user
```

### 2. Prepare the server

#### Create deployment user (optional but recommended)
```bash
sudo adduser deploy
sudo usermod -aG www-data deploy
```

#### Create deployment directory
```bash
sudo mkdir -p /var/www/ticketsync
sudo chown deploy:www-data /var/www/ticketsync
```

#### Configure SSH access
From your local machine, copy your SSH key:
```bash
ssh-copy-id deploy@your-server.com
```

#### Install dependencies on the server
```bash
# PHP 8.2+
sudo apt update
sudo apt install php8.2 php8.2-cli php8.2-fpm php8.2-mysql php8.2-pgsql \
  php8.2-sqlite3 php8.2-xml php8.2-mbstring php8.2-curl php8.2-zip \
  php8.2-intl php8.2-gd

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js and npm (for SASS compilation)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# PostgreSQL (recommended)
sudo apt install postgresql postgresql-contrib

# OR MySQL
sudo apt install mysql-server
```

### 3. Configure the database

#### For PostgreSQL
```bash
sudo -u postgres psql
```

```sql
CREATE DATABASE ticketsync;
CREATE USER ticketsync_user WITH ENCRYPTED PASSWORD 'your_password';
GRANT ALL PRIVILEGES ON DATABASE ticketsync TO ticketsync_user;
\q
```

#### For MySQL
```bash
sudo mysql
```

```sql
CREATE DATABASE ticketsync;
CREATE USER 'ticketsync_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON ticketsync.* TO 'ticketsync_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 4. Create .env.production.local file

Copy the example file and configure it:
```bash
cp .env.production.local.example .env.production.local
```

Edit `.env.production.local` with your actual values:
- `APP_SECRET`: Generate a secure random string (minimum 32 characters)
- `DATABASE_URL`: Your database credentials
- `DEFAULT_URI`: Your application URL
- `MAILER_DSN`: Your SMTP server configuration

**IMPORTANT**: NEVER commit this file to Git!

### 5. Configure web server

#### Nginx
Create `/etc/nginx/sites-available/ticketsync`:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/ticketsync/current/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    error_log /var/log/nginx/ticketsync_error.log;
    access_log /var/log/nginx/ticketsync_access.log;
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/ticketsync /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

#### Apache
Create `/etc/apache2/sites-available/ticketsync.conf`:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/ticketsync/current/public

    <Directory /var/www/ticketsync/current/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/ticketsync_error.log
    CustomLog ${APACHE_LOG_DIR}/ticketsync_access.log combined
</VirtualHost>
```

Enable the site:
```bash
sudo a2ensite ticketsync
sudo a2enmod rewrite
sudo systemctl reload apache2
```

## Deployment

### First deployment
```bash
dep deploy:first production
```

This command will:
1. Upload your `.env.production.local` file
2. Clone the repository
3. Install Composer dependencies
4. Install npm dependencies and compile assets (SASS + importmap)
5. Prepare SQLite database file (if using SQLite)
6. Run database migrations
7. Activate the new version

### Subsequent deployments
```bash
dep deploy production
```

### Rollback in case of issues
```bash
dep rollback production
```

## Available Deployer tasks

- `dep deploy production`: Deploy the application
- `dep rollback production`: Rollback to previous version
- `dep ssh production`: SSH into the server
- `dep logs production`: View deployment logs

## Post-deployment

### SSL Configuration (Let's Encrypt)
```bash
sudo apt install certbot python3-certbot-nginx  # For Nginx
# OR
sudo apt install certbot python3-certbot-apache  # For Apache

sudo certbot --nginx -d your-domain.com  # For Nginx
# OR
sudo certbot --apache -d your-domain.com  # For Apache
```

### Verification checklist
1. Access your domain in a browser
2. Verify the application works
3. Test login/registration
4. Verify email sending
5. Test file uploads

## Maintenance

### View deployed releases
```bash
dep releases production
```

### Clean old releases (keeps only the last 3)
```bash
dep cleanup production
```

## Troubleshooting

### Permission issues
```bash
# On the server
sudo chown -R deploy:www-data /var/www/ticketsync
sudo chmod -R 775 /var/www/ticketsync/shared/var
```

### Cache not cleared
```bash
dep deploy:cache production
```

### Migrations not executed
```bash
dep database:migrate production
```

### View logs
```bash
# On the server
tail -f /var/www/ticketsync/shared/var/log/prod.log
```
