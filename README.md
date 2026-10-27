# ORBIT

ORBIT is a Symfony-based social discussion platform. Users can create accounts,
publish posts, organize them with categories, comment on discussions, vote on
content, follow other users, and receive notifications. The application also
includes profile pages, search, image uploads, authentication, and an
administrator area.

## Technology

- PHP 8.4+
- Symfony 8
- Doctrine ORM and PostgreSQL
- Twig
- Symfony UX Turbo and Stimulus
- PHPUnit

## Requirements

- PHP 8.4 or newer
- Composer
- PostgreSQL, or Docker with Docker Compose
- Node.js is optional; frontend assets are managed with Symfony AssetMapper

## Getting started

Install the PHP dependencies:

```bash
composer install
```

Configure the local environment in `.env.local` (or use the values already
provided by your development environment), then create the database and apply
the migrations:

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

Start the Symfony development server:

```bash
symfony server:start
```

Alternatively, start the PostgreSQL service with Docker Compose and run the
Symfony application using the local PHP installation:

```bash
docker compose up -d database
symfony server:start
```

The application is then available at `http://127.0.0.1:8000`.

## Useful commands

```bash
# Clear the application cache
php bin/console cache:clear

# List registered routes
php bin/console debug:router

# Create an administrator account
php bin/console app:create-admin

# Run the test suite
php bin/phpunit
```

## Project structure

- `src/Controller/` - HTTP controllers for authentication, posts, profiles,
  search, notifications, and administration
- `src/Entity/` - Doctrine entities and domain data
- `src/Form/` - Symfony form types
- `templates/` - Twig views
- `assets/` - JavaScript and stylesheet assets
- `migrations/` - Database migrations
- `config/` - Symfony application configuration
- `public/` - Public entry point and uploaded/static files
- `tests/` - PHPUnit bootstrap and application tests

## Testing

Run the complete PHPUnit suite with:

```bash
php bin/phpunit
```

The test environment is configured in `phpunit.dist.xml` and uses
`APP_ENV=test`.

## Git status

Check the current branch and working tree before committing:

```bash
git status --short --branch
```

