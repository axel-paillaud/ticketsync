# TicketSync

Multi-tenant ticket management system built with Symfony 7.3. Personal learning project used in production for ~15 clients.

## Stack

- **Framework**: Symfony 7.3
- **PHP**: 8.2+
- **Database**: SQLite (dev) / PostgreSQL (prod)
- **Frontend**: Twig + Bootstrap 5 + AssetMapper
- **Deployment**: Deployer + Docker

## Features

- Multi-tenant architecture with organization-based routing (`/{slug}/tickets`)
- User authentication with roles (USER, ADMIN)
- Ticket management (CRUD, status, priority, assignment)
- Comments with threaded discussions
- File attachments (images, PDFs, documents)
- Email notifications (ticket creation, comments, status changes)
- Time tracking
- User invitations

## Project Structure

```
ticketsync/
├── assets/                  # Frontend assets (JS, SCSS)
│   ├── controllers/        # Stimulus controllers
│   └── styles/             # SCSS files
├── config/                  # Symfony configuration
│   ├── packages/           # Bundle configs
│   └── routes/             # Routing configs
├── migrations/              # Database migrations
├── public/                  # Web root
│   └── build/              # Compiled assets
├── src/
│   ├── Command/            # Console commands
│   ├── Controller/         # HTTP controllers
│   ├── Entity/             # Doctrine entities
│   ├── EventSubscriber/    # Event subscribers
│   ├── Form/               # Form types
│   ├── Repository/         # Doctrine repositories
│   ├── Resolver/           # Value resolvers
│   ├── Security/           # Security voters
│   ├── Service/            # Business services
│   └── Twig/               # Twig extensions
├── templates/               # Twig templates
│   ├── admin/              # Admin views
│   ├── emails/             # Email templates
│   ├── ticket/             # Ticket views
│   └── base.html.twig      # Base layout
├── compose.yaml             # Docker Compose config
├── deploy.php               # Deployer configuration
└── Dockerfile               # Docker image definition
```

## Installation

### Development (DDEV)

```bash
# Install dependencies
composer install
npm install

# Start DDEV
ddev start

# Setup database
ddev exec php bin/console doctrine:migrations:migrate
ddev exec php bin/console doctrine:fixtures:load

# Build assets
npm run sass
php bin/console importmap:install
php bin/console asset-map:compile

# Access the app
ddev launch
```

### Development (Docker)

```bash
# Start containers
docker compose up -d

# Install dependencies
docker compose exec app composer install
docker compose exec app npm install

# Setup database
docker compose exec app php bin/console doctrine:migrations:migrate
docker compose exec app php bin/console doctrine:fixtures:load

# Build assets
docker compose exec app npm run sass
docker compose exec app php bin/console importmap:install
docker compose exec app php bin/console asset-map:compile
```

## Deployment

This project uses [Deployer](https://deployer.org/) for zero-downtime deployments with Docker.

### Prerequisites

- Deployer installed: `composer global require deployer/deployer`
- SSH access to production server
- `.env.production.local` file configured

### Deploy Commands

```bash
# First deployment (uploads .env file)
dep deploy:first production

# Standard deployment
dep deploy production

# Rollback to previous release
dep rollback production

# Docker management
dep docker:restart production
dep docker:down production
```

### Deployment Flow

The deployment process (`deploy.php`):

1. **Git**: Clones repository and checks out main branch
2. **Docker**: Builds and starts containers from new release
3. **Dependencies**: Installs composer packages (`--no-dev --optimize-autoloader`)
4. **Assets**: Compiles frontend assets (npm + importmap + asset-map)
5. **Database**: Runs migrations if database exists
6. **Cache**: Clears and warms up Symfony cache
7. **Symlink**: Switches symlink to new release (zero-downtime)
8. **Docker**: Restarts containers with new code
9. **Cleanup**: Keeps last 3 releases, removes old ones

### Shared Files (Persisted Between Deploys)

- `.env.production.local` - Environment variables
- `var/data_prod.db` - SQLite database
- `var/log/` - Application logs
- `var/uploads/` - User uploaded files

### First Deployment

On first deploy, you'll need to create the database schema manually:

```bash
docker exec -it ticketsync_app bash
cd current
php bin/console doctrine:schema:create --env=prod
php bin/console doctrine:fixtures:load --group=prod --env=prod
php bin/console doctrine:migrations:version --add --all --env=prod
```

## Configuration

Copy `.env.production.local.example` to `.env.production.local` and configure:

```env
APP_ENV=prod
APP_SECRET=your-secret-key
MAILER_DSN=smtp://user:pass@host:port
```

## License

MIT License - Free to use, modify, and distribute.
