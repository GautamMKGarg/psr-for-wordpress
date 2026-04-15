# PSR for WordPress

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

A PSR-18 HTTP Client implementation for WordPress that uses the WordPress HTTP API (`wp_remote_request`) as the underlying transport. Falls back to `WpOrg\Requests` when WordPress functions are not available.

---

## Why This Package Exists

WordPress bundles its own HTTP transport (`wp_remote_request`). It handles proxies, SSL, redirects, and other platform-specific concerns automatically.

Libraries like `omnipay/common`, and others use `php-http/discovery` to auto-detect a PSR-18 HTTP client. Without this package, they'd pull in Guzzle — adding a heavy dependency and bypassing WordPress's HTTP layer.

This package gives you:
- WordPress HTTP API as the transport inside WordPress
- `WpOrg\Requests` as fallback outside WordPress
- Zero-argument constructor for `php-http/discovery` auto-detection
- PSR-18 compliance — works wherever `ClientInterface` is expected

---

## Requirements

- PHP 8.1+
- `rmccue/requests: ^2.0`
- WordPress 5.0+ when used inside WordPress

---

## Installation

```bash
composer require gautammkgarg/psr-for-wordpress
```

---

## Usage

### Auto-discovery (Omnipay, php-http/discovery)

Add to your project's `composer.json`:

```json
{
    "extra": {
        "discovery": {
            "psr/http-client-implementation": "GautamMKGarg\\PsrForWordPress\\Http\\Psr18Client"
        }
    }
}
```

That's it. `php-http/discovery` will find `Psr18Client` automatically when any library calls `Psr18ClientDiscovery::find()`.

### Manual Usage

```php
use GautamMKGarg\PsrForWordPress\Http\Psr18Client;
use Nyholm\Psr7\Factory\Psr17Factory;

$client = new Psr18Client();
$factory = new Psr17Factory();

$request = $factory->createRequest('GET', 'https://api.example.com/endpoint');
$response = $client->sendRequest($request);

echo $response->getStatusCode();    // 200
echo (string) $response->getBody(); // JSON response body
```

### Custom Options

```php
$client = new Psr18Client([
    'timeout'     => 30,   // seconds (default: 10)
    'redirection' => 0,    // disable redirects
    'sslverify'   => false, // disable SSL verification (not recommended in production)
]);
```

### Global Timeout Override via WordPress Filter

Instead of manually configuring the client, use WordPress's native hook:

```php
add_filter('http_request_args', function(array $args, string $url): array {
    // Increase timeout for payment gateway API calls
    $args['timeout'] = 30;
    return $args;
}, 10, 2);
```

---

## How It Works

### Inside WordPress
When `wp_remote_request()` is available, the client calls it directly:

```
PSR-7 Request → Build WP args → wp_remote_request() → Parse WP response → PSR-7 Response
                                        ↓ on WP_Error
                               throw NetworkException
```

### Outside WordPress
When `wp_remote_request()` is not available:

```
PSR-7 Request → Build Requests options → WpOrg\Requests\Requests::request() → Parse response → PSR-7 Response
                                                    ↓ on exception
                                          throw NetworkException
```

---

## Exceptions

| Exception | Interface | When Thrown |
|-----------|-----------|-------------|
| `NetworkException` | `Psr\Http\Client\NetworkExceptionInterface` | `WP_Error` or transport exception |
| `RequestException` | `Psr\Http\Client\RequestExceptionInterface` | Invalid request (e.g., empty URI) |

Both exceptions implement `getRequest()` to retrieve the original PSR-7 request.

---

## Guzzle vs This Package — Timeout Difference

Guzzle uses `0` as the default timeout, meaning **no timeout at all**. That's why Guzzle never fails for slow APIs. This package defaults to **10 seconds** — which is more appropriate for production WordPress sites than WordPress's built-in 5-second default. Increase it as needed.

---

## License

GPL-3.0-or-later. See [LICENSE](LICENSE) for full text.
