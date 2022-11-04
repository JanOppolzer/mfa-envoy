@include('vendor/autoload.php')

@setup
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, 'config');

    try {
        $dotenv->load();
        $dotenv->required([
            'TARGET_SERVER', 'TARGET_USER', 'TARGET_DIR',
            'REPOSITORY',
            'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL',
        ])->notEmpty();
    } catch(Exception $e) {
        echo "Something went wrong:\n\n";
        echo "{$e->getMessage()} \n\n";
        exit;
    }

    $server = $_ENV['TARGET_SERVER'];
    $user = $_ENV['TARGET_USER'];
    $dir = $_ENV['TARGET_DIR'];

    $repository = $_ENV['REPOSITORY'];
    $branch = $branch ?? 'main';

    $app_name = $_ENV['APP_NAME'];
    $app_env = $_ENV['APP_ENV'];
    $app_debug = $_ENV['APP_DEBUG'];
    $app_url = $_ENV['APP_URL'];

    $destination = (new DateTime)->format('YmdHis');
    $symlink = 'current';
@endsetup

@servers(['web' => "$user@$server"])

@task('deploy', ['confirm' => true])
    echo "=> Install {{ $app_name }} into ~/{{ $dir }}/ at {{ $user }}"@"{{ $server }}..."

    echo "Check ~/{{ $dir }}/"
        if [ ! -d {{ $dir }} ]; then
            mkdir -p {{ $dir }}
        fi

    cd {{ $dir }}

    echo "Clone '{{ $branch }}' branch of {{ $repository }} into ~/{{ $dir }}/{{ $destination }}/"
        git clone {{ $repository }} --branch={{ $branch }} --depth=1 -q ~/{{ $dir }}/{{ $destination }}

    echo "Backup existing ~/{{ $dir }}/.env"
        if [ -f .env ]; then
            mv .env .env-{{ $destination }}.bak
        fi

    echo "Prepare new ~/{{ $dir }}/.env"
        if [ ! -f .env ]; then
            cp {{ $destination }}/.env.example .env
        fi

    echo "Update ~/{{ $dir }}/.env"
        sed -i "s%APP_NAME=.*%APP_NAME={{ $app_name }}%; \
        s%APP_ENV=.*%APP_ENV={{ $app_env }}%; \
        s%APP_DEBUG=.*%APP_DEBUG={{ $app_debug }}%; \
        s%APP_URL=.*%APP_URL={{ $app_url }}%" .env

    echo "Symlink ~/{{ $dir }}/.env"
        ln -s ../.env ~/{{ $dir }}/{{ $destination }}/.env

    echo "Check ~/{{ $dir }}/storage/ and fix permissions if necessary"
        if [ ! -d storage ]; then
            mv {{ $destination }}/storage .
            setfacl -Rm g:www-data:rwx,d:g:www-data:rwx storage
        else
            rm -rf {{ $destination }}/storage
        fi

    echo "Fix permissions to ~/{{ $dir }}/bootstrap/cache"
        setfacl -Rm g:www-data:rwx,d:g:www-data:rwx ~/{{ $dir }}/{{ $destination }}/bootstrap/cache

    echo "Symlink ~/{{ $dir }}/storage/"
        ln -s ../storage {{ $destination }}/storage

    echo "Unlink ~/{{ $dir }}/{{ $symlink }}"
        if [ -h {{ $symlink }} ]; then
            rm {{ $symlink }}
        fi

    echo "Symlink ~/{{ $dir }}/{{ $destination }} to ~/{{ $dir }}/{{ $symlink }}"
        ln -s {{ $destination }} {{ $symlink }}

    echo "Install composer dependencies"
        cd current
        composer install -q --no-dev --optimize-autoloader --no-ansi --no-interaction --no-progress --prefer-dist
        cd ..

    echo "Generate key"
        if [ `grep '^APP_KEY=' .env | grep 'base64:' | wc -l` -eq 0 ]; then
            cd current
            php artisan key:generate -q --no-ansi --no-interaction
            cd ..
        fi

    cd {{ $destination }}

    echo "Optimize"
        php artisan optimize:clear -q --no-ansi --no-interaction

    echo "Cache config"
        php artisan config:cache -q --no-ansi --no-interaction

    echo "Cache routes"
        php artisan route:cache -q --no-ansi --no-interaction

    echo "Cache views"
        php artisan view:cache -q --no-ansi --no-interaction

    echo "Reload PHP-FPM"
        sudo systemctl reload php8.1-fpm
@endtask

