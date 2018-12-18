# Gateway

Gateway is an HTTP server that can be used to replace the built-in PHP web server for development purposes. It's not suitable for production environments.

## Usage

```bash
php  -dzend.assertions=-1 bin/server.php -d $PWD/public -s $PWD/public/index.php -e development
```

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.