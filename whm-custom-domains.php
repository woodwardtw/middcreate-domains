<?php
declare(strict_types=1);

/*
 * WHM Custom Domain Report
 *
 * Configure the values below. For LocalWP, the token can be returned from
 * app/whm-custom-domains-token.php, outside the public document root.
 */

const WHM_HOST = 'middcreate1.reclaimhosting.com';
const WHM_USER = 'mlkksbaadmin';
const BASE_DOMAIN = 'middcreate.net';

$apiToken = getenv('WHM_API_TOKEN') ?: '';

if (trim($apiToken) === '') {
    $tokenFile = dirname(__DIR__, 3) . '/whm-custom-domains-token.php';
    if (is_file($tokenFile)) {
        $configuredToken = require $tokenFile;
        if (is_string($configuredToken)) {
            $apiToken = trim($configuredToken);
        }
    }
}

if (trim($apiToken) === '') {
    http_response_code(500);
    exit('Set WHM_API_TOKEN or create app/whm-custom-domains-token.php outside the public document root.');
}

/**
 * Make a WHM API 1 request.
 */
function whmApi(string $function, array $params = []): array
{
    global $apiToken;

    $query = array_merge(['api.version' => 1], $params);
    $url = 'https://' . WHM_HOST . ':2087/json-api/' . rawurlencode($function)
         . '?' . http_build_query($query);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Authorization: whm ' . WHM_USER . ':' . $apiToken,
            'Accept: application/json',
        ],
        // Keep certificate validation ON. WHM_HOST should match the SSL certificate.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('WHM connection failed: ' . $message);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new RuntimeException("WHM returned HTTP {$status}.");
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException('WHM returned invalid JSON.');
    }

    if (isset($data['metadata']['result']) && (int) $data['metadata']['result'] !== 1) {
        $reason = $data['metadata']['reason'] ?? 'Unknown WHM API error';
        throw new RuntimeException((string) $reason);
    }

    return $data;
}

/**
 * Return all cPanel accounts.
 */
function getAccounts(): array
{
    $response = whmApi('listaccts', [
        'api.columns.enable' => 1,
        'api.columns.a' => 'user',
        'api.columns.b' => 'domain',
        'api.columns.c' => 'owner',
        'api.columns.d' => 'email',
        'api.columns.e' => 'suspended',
    ]);

    return $response['data']['acct'] ?? [];
}

/**
 * Return DomainInfo::list_domains for one cPanel user through WHM API 1.
 */
function getDomains(string $username): array
{
    $response = whmApi('uapi_cpanel', [
        'cpanel.user' => strtolower($username),
        'cpanel.module' => 'DomainInfo',
        'cpanel.function' => 'list_domains',
    ]);

    // uapi_cpanel wraps the UAPI response under data.uapi.result.
    $uapi = $response['data']['uapi'] ?? [];
    $result = $uapi['result'] ?? $uapi;

    if (isset($result['status']) && (int) $result['status'] !== 1) {
        $errors = $result['errors'] ?? ['Unknown UAPI error'];
        throw new RuntimeException(implode('; ', array_map('strval', (array) $errors)));
    }

    return $result['data'] ?? [];
}

function isCustomDomain(string $domain): bool
{
    $domain = strtolower(trim($domain, ". \t\n\r\0\x0B"));
    $base = strtolower(BASE_DOMAIN);

    if ($domain === '') {
        return false;
    }

    // Exclude both the base domain itself and anything below it.
    return $domain !== $base && !str_ends_with($domain, '.' . $base);
}

function addDomainRow(
    array &$rows,
    array $account,
    string $primary,
    string $domain,
    string $type
): void {
    $domain = trim($domain);

    if ($domain === '' || !isCustomDomain($domain)) {
        return;
    }

    $rows[] = [
        'account' => $account['user'] ?? '',
        'owner' => $account['owner'] ?? '',
        'email' => $account['email'] ?? '',
        'primary_domain' => $primary,
        'custom_domain' => $domain,
        'domain_type' => $type,
        'suspended' => !empty($account['suspended']) ? 'Yes' : 'No',
    ];
}

function buildReport(): array
{
    $rows = [];
    $errors = [];
    $accounts = getAccounts();

    foreach ($accounts as $account) {
        $username = (string) ($account['user'] ?? '');
        $primary = (string) ($account['domain'] ?? '');

        if ($username === '') {
            continue;
        }

        try {
            $domains = getDomains($username);

            // Prefer DomainInfo's main_domain, but retain listaccts as a fallback.
            $mainDomain = (string) ($domains['main_domain'] ?? $primary);

            addDomainRow($rows, $account, $mainDomain, $mainDomain, 'primary');

            foreach (($domains['addon_domains'] ?? []) as $domain) {
                addDomainRow($rows, $account, $mainDomain, (string) $domain, 'addon');
            }

            foreach (($domains['parked_domains'] ?? []) as $domain) {
                addDomainRow($rows, $account, $mainDomain, (string) $domain, 'alias');
            }

            foreach (($domains['sub_domains'] ?? []) as $domain) {
                addDomainRow($rows, $account, $mainDomain, (string) $domain, 'subdomain');
            }

        } catch (Throwable $e) {
            $errors[] = $username . ': ' . $e->getMessage();
        }
    }

    usort($rows, static fn(array $a, array $b): int =>
        [$a['account'], $a['custom_domain']] <=> [$b['account'], $b['custom_domain']]
    );

    return [$rows, $errors, count($accounts)];
}

function csvEscape(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

try {
    [$rows, $errors, $accountsScanned] = buildReport();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to build report: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// CSV download.
if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="whm-custom-domains-' . date('Y-m-d') . '.csv"');

    $headers = [
        'account',
        'owner',
        'email',
        'primary_domain',
        'custom_domain',
        'domain_type',
        'suspended',
    ];

    echo implode(',', array_map('csvEscape', $headers)) . "\r\n";

    foreach ($rows as $row) {
        echo implode(',', array_map(
            static fn(string $key): string => csvEscape((string) ($row[$key] ?? '')),
            $headers
        )) . "\r\n";
    }

    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$generated = date('Y-m-d H:i:s T');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WHM Custom Domain Report</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous">
</head>
<body>
<main class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h2 mb-1">WHM Custom Domain Report</h1>
            <p class="text-body-secondary mb-0">
                Domains outside <code><?= h(BASE_DOMAIN) ?></code>.
                Generated <?= h($generated) ?>.
            </p>
        </div>
        <div>
            <a class="btn btn-primary" href="?format=csv">Download CSV</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-body-secondary">Custom domains</div>
                    <div class="fs-3 fw-semibold"><?= count($rows) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-body-secondary">Accounts represented</div>
                    <div class="fs-3 fw-semibold">
                        <?= count(array_unique(array_column($rows, 'account'))) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-body-secondary">WHM accounts scanned</div>
                    <div class="fs-3 fw-semibold"><?= $accountsScanned ?></div>
                    <div class="small text-body-secondary">Visible to this WHM user</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-warning" role="alert">
            <h2 class="h5">Some accounts could not be read</h2>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="mb-3">
        <label for="domainFilter" class="form-label">Filter report</label>
        <input
            id="domainFilter"
            class="form-control"
            type="search"
            placeholder="Account, domain, owner, email, or type"
            aria-describedby="filterHelp">
        <div id="filterHelp" class="form-text">
            The table updates as you type.
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle" id="domainTable">
            <caption>
                <?= count($rows) ?> custom-domain records found.
            </caption>
            <thead>
            <tr>
                <th scope="col">Account</th>
                <th scope="col">Owner</th>
                <th scope="col">Email</th>
                <th scope="col">Primary domain</th>
                <th scope="col">Custom domain</th>
                <th scope="col">Type</th>
                <th scope="col">Suspended</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['account']) ?></td>
                    <td><?= h($row['owner']) ?></td>
                    <td><?= h($row['email']) ?></td>
                    <td><?= h($row['primary_domain']) ?></td>
                    <td>
                        <a
                            href="https://<?= h($row['custom_domain']) ?>"
                            target="_blank"
                            rel="noopener noreferrer">
                            <?= h($row['custom_domain']) ?>
                            <span class="visually-hidden">(opens in a new tab)</span>
                        </a>
                    </td>
                    <td><?= h($row['domain_type']) ?></td>
                    <td><?= h($row['suspended']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!$rows): ?>
        <div class="alert alert-info" role="status">
            No custom domains were found.
        </div>
    <?php endif; ?>
</main>

<script>
const filter = document.querySelector('#domainFilter');
const rows = document.querySelectorAll('#domainTable tbody tr');

filter.addEventListener('input', () => {
    const query = filter.value.trim().toLowerCase();

    rows.forEach((row) => {
        const matches = row.textContent.toLowerCase().includes(query);
        row.hidden = !matches;
    });
});
</script>
</body>
</html>
