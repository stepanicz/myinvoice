<?php

declare(strict_types=1);

namespace MyInvoice\Action\Recurring;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Recurring\RecurringRunner;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * REST endpointy pro pravidelné faktury.
 * Custom feature — ne v upstreamu radekhulan/myinvoice.
 *
 * Routes:
 *   GET    /api/recurring-invoices               list
 *   POST   /api/recurring-invoices               create
 *   GET    /api/recurring-invoices/{id}          detail
 *   PUT    /api/recurring-invoices/{id}          update
 *   DELETE /api/recurring-invoices/{id}          delete
 *   POST   /api/recurring-invoices/{id}/pause    status → paused
 *   POST   /api/recurring-invoices/{id}/resume   status → active
 *   POST   /api/recurring-invoices/{id}/run-now  manuální spuštění (pro test)
 */
final class RecurringTemplateAction
{
    private const FREQUENCIES = ['monthly', 'quarterly', 'yearly'];
    private const TYPES       = ['invoice', 'proforma'];
    private const STATUSES    = ['active', 'paused', 'ended'];

    public function __construct(
        private readonly RecurringTemplateRepository $repo,
        private readonly ClientRepository $clients,
        private readonly RecurringRunner $runner,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $statusFilter = $request->getQueryParams()['status'] ?? null;
        if ($statusFilter !== null && !in_array($statusFilter, self::STATUSES, true)) {
            $statusFilter = null;
        }
        $rows = $this->repo->listForSupplier($supplierId, $statusFilter);
        return Json::ok($response, ['items' => $rows]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $tpl = $this->repo->find((int) $args['id']);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        return Json::ok($response, $tpl);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = $this->validate($body, true);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }
        $supplierId = SupplierGuard::currentId($request);
        if (!SupplierGuard::owns($request, $this->clients->find((int) $body['client_id']))) {
            return Json::error($response, 'client_not_found', 'Klient neexistuje.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        // next_run_date defaults na start_date pokud nezadáno
        if (empty($body['next_run_date'])) {
            $body['next_run_date'] = $body['start_date'];
        }

        $id = $this->repo->create($supplierId, $body, $userId);
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring.created', $userId, 'recurring_template', $id, [
            'name' => $body['name'], 'frequency' => $body['frequency'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = $this->validate($body, false);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }
        if (!empty($body['client_id']) && !SupplierGuard::owns($request, $this->clients->find((int) $body['client_id']))) {
            return Json::error($response, 'client_not_found', 'Klient neexistuje.', 400);
        }

        $this->repo->update($id, $body);
        if (array_key_exists('items', $body)) {
            $this->repo->replaceItems($id, (array) $body['items']);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring.updated', $userId, 'recurring_template', $id, [], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        $this->repo->delete($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring.deleted', $userId, 'recurring_template', $id, ['name' => $existing['name']], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    public function pause(Request $request, Response $response, array $args): Response
    {
        return $this->setStatus($request, $response, (int) $args['id'], 'paused');
    }

    public function resume(Request $request, Response $response, array $args): Response
    {
        return $this->setStatus($request, $response, (int) $args['id'], 'active');
    }

    private function setStatus(Request $request, Response $response, int $id, string $status): Response
    {
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        if ($existing['status'] === 'ended') {
            return Json::error($response, 'already_ended', 'Ukončenou šablonu nelze znovu aktivovat (vytvoř novou).', 409);
        }
        $this->repo->update($id, ['status' => $status]);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring.' . $status, $userId, 'recurring_template', $id, [], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }

    public function runNow(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        if ($existing['status'] === 'ended') {
            return Json::error($response, 'ended', 'Ukončenou šablonu nelze spustit.', 409);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        try {
            $result = $this->runner->run($id, null, $userId, $ip, $request->getHeaderLine('User-Agent'));
        } catch (\Throwable $e) {
            return Json::error($response, 'run_failed', $e->getMessage(), 500);
        }
        return Json::ok($response, $result);
    }

    /**
     * @return array<string,string>
     */
    private function validate(array $body, bool $isCreate): array
    {
        $errors = [];

        if ($isCreate || array_key_exists('name', $body)) {
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 190) {
                $errors['name'] = 'Název je povinný (max 190 znaků).';
            }
        }
        if ($isCreate || array_key_exists('client_id', $body)) {
            if ((int) ($body['client_id'] ?? 0) <= 0) {
                $errors['client_id'] = 'Klient je povinný.';
            }
        }
        if ($isCreate || array_key_exists('currency_id', $body)) {
            if ((int) ($body['currency_id'] ?? 0) <= 0) {
                $errors['currency_id'] = 'Měna je povinná.';
            }
        }
        if ($isCreate || array_key_exists('frequency', $body)) {
            $f = (string) ($body['frequency'] ?? '');
            if (!in_array($f, self::FREQUENCIES, true)) {
                $errors['frequency'] = 'Frekvence musí být monthly/quarterly/yearly.';
            }
        }
        if ($isCreate || array_key_exists('invoice_type', $body)) {
            $t = (string) ($body['invoice_type'] ?? 'invoice');
            if (!in_array($t, self::TYPES, true)) {
                $errors['invoice_type'] = 'Typ musí být invoice nebo proforma.';
            }
        }
        if ($isCreate || array_key_exists('start_date', $body)) {
            if (!$this->isValidDate((string) ($body['start_date'] ?? ''))) {
                $errors['start_date'] = 'Neplatné datum začátku.';
            }
        }
        if (!empty($body['end_date']) && !$this->isValidDate((string) $body['end_date'])) {
            $errors['end_date'] = 'Neplatné datum konce.';
        }
        if (!empty($body['end_date']) && !empty($body['start_date'])
            && $body['end_date'] < $body['start_date']
        ) {
            $errors['end_date'] = 'Konec nesmí být před začátkem.';
        }

        if ($isCreate && empty($body['items'])) {
            $errors['items'] = 'Šablona musí mít alespoň jednu položku.';
        }
        if (array_key_exists('items', $body) && is_array($body['items'])) {
            foreach ($body['items'] as $idx => $it) {
                if (trim((string) ($it['description'] ?? '')) === '') {
                    $errors["items.$idx.description"] = 'Popis položky je povinný.';
                }
                if ((int) ($it['vat_rate_id'] ?? 0) <= 0) {
                    $errors["items.$idx.vat_rate_id"] = 'Sazba DPH je povinná.';
                }
            }
        }

        return $errors;
    }

    private function isValidDate(string $s): bool
    {
        if ($s === '') return false;
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s;
    }
}
