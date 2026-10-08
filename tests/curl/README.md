# Curl suite

Standalone checks for `\CitOmni\Infrastructure\Service\Curl`. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/curl/run.php
```

Expected result:

```
41 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service runs on the kernel doubles in `tests/support/doubles.php`, with the shipped `curl` cfg baseline from `Registry::CFG_HTTP` plus short timeouts. Requests go to PHP's built-in web server on a free local port, started with the same PHP binary and php.ini as the suite.

| File | Role |
|---|---|
| `run.php` | Starts the server, runs the checks, stops the server. |
| `router.php` | Router for the built-in server. Appends every request it receives (method, target, headers, body) to `requests.jsonl` in the document root and picks the response by path. |

The document root is a fresh `citomni_infrastructure_curl_test_<random>` directory under `sys_get_temp_dir()`. It holds the request log, the server output and the cookie jars of the checks, and is removed afterwards. Logging goes to a recording double, so no log files are written.

Checks that a request is rejected also compare the request log before and after, so "rejected" always means "rejected before anything was sent".

## Checks

All checks pin existing behavior.

| Check | Covers |
|---|---|
| unknown request keys are rejected before transport | Typo protection for request keys |
| service option defaults with unknown keys are rejected at construction | Same for the `defaults` service option |
| a missing or empty url is rejected | `url` |
| CR, LF and NUL are rejected in header lines, referer, user_agent and bearer_token | Header injection |
| header lines need a field name before a colon | Header line shape |
| verify_peer and verify_host accept only their documented values | No lenient casts that disable TLS verification; every accepted form still works |
| negative, non-finite and non-numeric timeouts are rejected | `timeout`, `connect_timeout`, `max_redirects` |
| authentication sources cannot be combined | `basic_auth`, `bearer_token`, explicit `Authorization` |
| malformed basic_auth and bearer_token are rejected | Auth input shape |
| curl_options must be keyed by CURLOPT_* integers | `curl_options` keys |
| invalid sensitive_query_keys fail before transport | `sensitive_query_keys` shape |
| GET, HEAD and POST use native modes; other methods and GET with a body are sent as custom requests | Method mapping as seen by the server, `Content-Length: 0` for POST without body, method token validation |
| a followed 302 turns a POST into a GET, while a custom method is kept | Native modes versus `CURLOPT_CUSTOMREQUEST` on redirects |
| query is appended after an existing query and before the fragment | URL building, RFC 3986 encoding |
| 4xx and 5xx responses are returned with their status, not thrown | HTTP errors are not transport errors |
| final response headers have lowercase names and repeated fields as lists | Header parsing |
| a followed redirect keeps every head in headers_raw and only the final head in headers | Redirect hops |
| without follow_redirects the redirect is returned as is | Default redirect policy |
| max_redirects 0 refuses to follow a redirect | `CURLE_TOO_MANY_REDIRECTS` |
| return_headers and capture_info can be switched off | Response shape |
| basic_auth and bearer_token reach the server as Authorization headers | Auth on the wire |
| an explicit User-Agent header wins over user_agent, and curl_options apply last | User-Agent precedence |
| cookie_store creates its directory and file, stores the cookie and sends it back | Cookie persistence across requests |
| a cookie_file that is not a readable file is rejected | Cookie source validation |
| a fractional timeout is enforced in milliseconds | `CURLOPT_TIMEOUT_MS` mapping |
| success logging writes method, url, status and log_context to the cfg log_file | `curl.success` |
| log_file per request overrides the cfg log_file | Per-request log file |
| log_success false writes nothing for completed transfers, including 5xx | Success logging policy |
| a transport failure is logged as curl.error unless log_errors is false | `curl.error` |
| without a log service requests and failures are not logged and still work | `hasService('log')` |
| without sensitive_query_keys the URL is sent and reported unchanged | Redaction is opt-in |
| a sensitive value is sent unchanged but redacted from returned URLs and the success log | Redaction of request, effective and info URLs |
| a sensitive top-level key also redacts nested bracket parameters | `key[a][b]` notation |
| duplicate and multiple sensitive parameters are all redacted in order | Repeated parameters |
| encoded parameter names match decoded sensitive keys without re-encoding the rest | Percent-encoded names |
| an absent sensitive key changes nothing | No-op redaction |
| the request line in CURLINFO_HEADER_OUT is redacted | `request_header` |
| a Referer URL in CURLINFO_HEADER_OUT is redacted | `Referer` in `request_header` |
| the redirect_url of a pending redirect is redacted | `redirect_url` |
| the effective URL after a followed redirect is redacted | Followed redirects |
| a transport failure exposes only redacted URLs in the exception and the error log | `CurlExecException` and `curl.error` |

`php tests/run.php` runs every suite in the package.
