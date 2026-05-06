<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Supplier\PaymentAliasGenerator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * First-run setup. Funguje **jen pokud users je prázdná** (race-safe přes UNIQUE constraint).
 */
final class SetupAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SessionManager $sessions,
        private readonly Config $config,
        private readonly PaymentAliasGenerator $aliasGen,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $admin = (array) ($body['admin'] ?? []);
        $supplier = isset($body['supplier']) && is_array($body['supplier']) ? $body['supplier'] : null;

        $errors = $this->validate($admin, $supplier);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        $pdo = $this->db->pdo();

        try {
            $passwordHash = $this->hasher->hash((string) $admin['password']);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400, [
                'fields' => ['admin.password' => [$e->getMessage()]],
            ]);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        // Race-safe: jedna transakce s SELECT FOR UPDATE — dva souběžné setup requesty
        // se serializují, druhý vidí prvního usera a odmítne setup.
        $pdo->beginTransaction();
        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM users FOR UPDATE')->fetchColumn();
            if ($count > 0) {
                $pdo->rollBack();
                return Json::error($response, 'setup_already_done', 'Setup již proběhl.', 409);
            }
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, locale, is_active)
                                   VALUES (?, ?, ?, "admin", "cs", 1)');
            $stmt->execute([
                trim((string) $admin['email']),
                $passwordHash,
                trim((string) $admin['name']),
            ]);
            $userId = (int) $pdo->lastInsertId();

            // Volitelně dodavatel
            if ($supplier !== null) {
                $this->insertSupplier($pdo, $supplier);
            }

            $this->logger->log('setup.completed', $userId, 'user', $userId, [
                'email' => $admin['email'],
                'has_supplier' => $supplier !== null,
            ], $ip, $request->getHeaderLine('User-Agent'));

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            return Json::error($response, 'setup_failed', $e->getMessage(), 500);
        }

        // Auto-login: vytvoř session pro nově vzniknklého admina (eliminuje public window pro setup-sample)
        $userAgent = $request->getHeaderLine('User-Agent');
        $session = $this->sessions->create($userId, $ip, $userAgent);

        $cookieName     = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $cookieSecure   = (bool)   $this->config->get('session.cookie_secure', true);
        $cookieSameSite = (string) $this->config->get('session.cookie_samesite', 'Lax');
        $maxAge = max(0, $session['expires_at'] - time());
        $cookie = sprintf(
            '%s=%s; HttpOnly; Path=/; Max-Age=%d; SameSite=%s%s',
            $cookieName,
            $session['token'],
            $maxAge,
            $cookieSameSite,
            $cookieSecure ? '; Secure' : '',
        );

        $response = $response->withHeader('Set-Cookie', $cookie);
        return Json::ok($response, [
            'user' => [
                'id'    => $userId,
                'email' => $admin['email'],
                'name'  => $admin['name'],
                'role'  => 'admin',
            ],
            'csrf_token' => $session['csrf_token'],
            'next' => '/login',
        ], 201);
    }

    private function insertSupplier(\PDO $pdo, array $supplier): void
    {
        // Najdi country_id z iso2
        $iso2 = strtoupper((string) ($supplier['country_iso2'] ?? 'CZ'));
        $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $stmtCountry->execute([$iso2]);
        $countryId = (int) ($stmtCountry->fetchColumn() ?: 0);
        if ($countryId === 0) {
            $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        }

        $defaultCurrencyCode = strtoupper((string) ($supplier['default_currency'] ?? 'CZK'));
        $vatRateId = (int) $pdo->query("SELECT id FROM vat_rates WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn()
            ?: (int) $pdo->query("SELECT id FROM vat_rates ORDER BY id LIMIT 1")->fetchColumn();
        if ($vatRateId === 0) {
            throw new \RuntimeException('Tabulka vat_rates je prázdná.');
        }

        // Multi-supplier bootstrap — supplier nemá ještě default_currency_id a currencies vyžadují supplier_id (cyklický FK).
        // Trick: SET FOREIGN_KEY_CHECKS=0, INSERT supplier s placeholder default_currency_id=0,
        // INSERT currencies (CZK + EUR) pro nový supplier, UPDATE supplier.default_currency_id, FK_CHECKS=1.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $stmt = $pdo->prepare(
            'INSERT INTO supplier
            (company_name, display_name, street, city, zip, country_id, ic, dic, is_vat_payer,
             email, phone, web, default_currency_id, default_vat_rate_id, default_payment_due_days, default_hourly_rate,
             payment_email_alias)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string) ($supplier['company_name'] ?? ''),
            (string) ($supplier['display_name'] ?? '') ?: null,
            (string) ($supplier['street'] ?? ''),
            (string) ($supplier['city'] ?? ''),
            (string) ($supplier['zip'] ?? ''),
            $countryId,
            (string) ($supplier['ic'] ?? '') ?: null,
            (string) ($supplier['dic'] ?? '') ?: null,
            !empty($supplier['is_vat_payer']) ? 1 : 0,
            (string) ($supplier['email'] ?? ''),
            (string) ($supplier['phone'] ?? '') ?: null,
            (string) ($supplier['web'] ?? '') ?: null,
            $vatRateId,
            (int) ($supplier['default_payment_due_days'] ?? 7),
            (string) ($supplier['default_hourly_rate'] ?? '1500.00'),
            $this->aliasGen->generateUnique($pdo),
        ]);
        $supplierId = (int) $pdo->lastInsertId();

        // Seed default currencies (CZK + EUR) pro tohoto supplier
        $bank = isset($supplier['bank_account']) && is_array($supplier['bank_account']) ? $supplier['bank_account'] : null;
        $bankCurrency = $bank !== null ? strtoupper((string) ($bank['currency'] ?? $defaultCurrencyCode)) : null;

        $insertCur = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                                     account_number, bank_code, bank_name, iban, bic)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1, ?, ?, ?, ?, ?)'
        );

        $seedCurrencies = [
            ['CZK', 'CZK — výchozí', 'Kč', 'Česká koruna', 'Czech Koruna'],
            ['EUR', 'EUR — výchozí', '€',  'Euro',          'Euro'],
        ];
        $defaultCurrencyId = 0;
        foreach ($seedCurrencies as [$code, $label, $symbol, $nameCs, $nameEn]) {
            $isThisBank = $bank !== null && $bankCurrency === $code;
            $insertCur->execute([
                $supplierId, $code, $label, $symbol, $nameCs, $nameEn,
                $isThisBank ? ((string) ($bank['account_number'] ?? '') ?: null) : null,
                $isThisBank ? ((string) ($bank['bank_code'] ?? '') ?: null) : null,
                $isThisBank ? ((string) ($bank['bank_name'] ?? '') ?: null) : null,
                $isThisBank ? ((string) ($bank['iban'] ?? '') ?: null) : null,
                $isThisBank ? ((string) ($bank['bic'] ?? '') ?: null) : null,
            ]);
            $newCurId = (int) $pdo->lastInsertId();
            if ($code === $defaultCurrencyCode) $defaultCurrencyId = $newCurId;
        }

        if ($defaultCurrencyId === 0) {
            // Fallback: prvni currency
            $stmtCur = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? LIMIT 1');
            $stmtCur->execute([$supplierId]);
            $defaultCurrencyId = (int) $stmtCur->fetchColumn();
        }

        // Doplň supplier.default_currency_id, obnov FK
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
            ->execute([$defaultCurrencyId, $supplierId]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function validate(array $admin, ?array $supplier): array
    {
        $errors = [];

        if (empty($admin['name']) || !is_string($admin['name'])) {
            $errors['admin.name'][] = 'Jméno je povinné';
        }
        if (empty($admin['email']) || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin.email'][] = 'Platný email je povinný';
        }
        if (empty($admin['password']) || !is_string($admin['password'])) {
            $errors['admin.password'][] = 'Heslo je povinné';
        }

        if ($supplier !== null) {
            $required = ['company_name', 'street', 'city', 'zip', 'email'];
            foreach ($required as $field) {
                if (empty($supplier[$field]) || !is_string($supplier[$field])) {
                    $errors["supplier.$field"][] = 'Povinné pole';
                }
            }
            if (!empty($supplier['email']) && !filter_var($supplier['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['supplier.email'][] = 'Neplatný email';
            }
        }

        return $errors;
    }
}
