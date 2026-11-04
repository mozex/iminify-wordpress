# Iminify Image Optimizer

The WordPress plugin for [Iminify](https://www.iminify.com): it sends the images in your media library to Iminify, which compresses them and makes WebP and AVIF copies, and writes the smaller files back to your site.

The plugin's description for WordPress users is in [readme.txt](readme.txt).

## Development

Requirements: PHP 7.4 or later, Composer, Node.js 22.13 or later, and Docker for wp-env.

```bash
composer install
npm install
npm run env start                 # the development site at http://localhost:8888
npm run env:test start            # the test site at http://localhost:8889

composer lint                     # coding standards and PHP 7.4 compatibility
composer types                    # PHPStan
composer test                     # unit tests
npm run test:integration          # integration tests on WordPress's test library
php bin/build.php                 # the plugin as it ships, in build/
```

## License

GPLv2 or later. See [license.txt](license.txt).
