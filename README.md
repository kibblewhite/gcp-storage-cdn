# gcp-storage-cdn

This plugin allows media to be imported into the WordPress media library. These media items will originate from a paid for service within GCP where images are stored in a cloud storage bucket and displayed via the cloudflare caching then via the GCP load balancer cache.

Ignore the readme.txt as this is used for publishing the plugin externally.

## Notes for Unit Testing

Installation Prerequisites:
```text
- wp-cli
- phpunit (v7)
- subversion
- mariadb-client
```

```bash
wp scaffold plugin gcp-storage-cdn
wp scaffold plugin-tests gcp-storage-cdn
```

```bash
cd wp-content/plugins/gcp-storage-cdn
rm -Rf /tmp/wordpress* &&  bin/install-wp-tests.sh wordpress_test root 'you-password-here' localhost latest
phpunit
```

## PHPUnit v7 Install

➜ wget -O phpunit https://phar.phpunit.de/phpunit-7.phar

➜ chmod +x phpunit

➜ ./phpunit --version

➜ mv ./phpunit /usr/local/bin/
