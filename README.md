# Gateway

Gateway is an HTTP server that can be used to replace the built-in PHP web server for development purposes. It's not suitable for production environments.

## Usage

```bash
php -dzend.assertions=-1 bin/gateway -d $PWD/public -s $PWD/public/index.php -e development
```

## Requirements

 - PHP 7.0
 - `php-cgi`, as requests are currently processed via `php-cgi`

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
