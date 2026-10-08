# WHM Custom Domain Report

A small PHP report that:

- retrieves cPanel accounts with WHM API 1 `listaccts`
- calls cPanel UAPI `DomainInfo::list_domains` through WHM API 1 `uapi_cpanel`
- excludes `middcreate.net` and all `*.middcreate.net` domains
- identifies primary, addon, alias/parked, and subdomains outside `middcreate.net`
- shows owner, email, suspension status, and primary domain
- exports the current full report as CSV

## LocalWP setup

Create `app/whm-custom-domains-token.php` in the Local site folder, alongside `public/` and **not inside `public/`**. For this site, that is:

```php
<?php
return 'PASTE_YOUR_NEW_WHM_API_TOKEN_HERE';
```

Then open `whm-custom-domains-fixed.php` through the LocalWP site URL. The report reads that private token file because it is not served from the web root.

For other PHP setups, set `WHM_API_TOKEN` in the PHP process environment instead.

## WHM API token

Create a token in **WHM → Development → Manage API Tokens**. Use the narrowest privileges that permit the API calls below.

The API calls used are:

- `listaccts`
- `uapi_cpanel` → `DomainInfo::list_domains`

Use the narrowest privileges that permit those calls.

## Security

Do not publish this report openly. It contains account names, account email addresses,
and domain inventory information.

If an API token has ever been stored in a source or notes file, revoke it and create a
replacement token before deploying this report.

The script intentionally leaves TLS certificate validation enabled. Use a WHM hostname
whose certificate is valid for that hostname.

## PHP requirements

- PHP 8+
- cURL extension
