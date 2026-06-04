<?php

declare(strict_types=1);

namespace MyInvoice\Service\PaymentMail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\PaymentMail\Parser\BankParserInterface;
use MyInvoice\Service\PaymentMail\Parser\CsasParser;
use MyInvoice\Service\PaymentMail\Parser\FioParser;
use Psr\Log\LoggerInterface;

/**
 * Orchestruje pipeline: IMAP fetch → routing → parse → save → match → IMAP move.
 *
 * Pro každou UNSEEN zprávu:
 *   1. Resolve supplier_id z Delivered-To (supplier.payment_email_alias)
 *   2. Resolve parser (first parser.supports() wins)
 *   3. Dedupe podle SHA256(Message-ID) v bank_statements.file_hash
 *   4. Parse → ParsedTransaction
 *   5. Insert synthetic 1-transaction statement (source='email')
 *   6. StatementMatcher::match() — páruje na fakturu podle VS
 *   7. MOVE mailu (Processed při úspěchu, Errors při chybě)
 */
final class PaymentMailIngestor
{
    /** @var list<BankParserInterface> */
    private readonly array $parsers;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly ImapClient $imap,
        private readonly StatementMatcher $matcher,
        private readonly LoggerInterface $logger,
        CsasParser $csas,
        FioParser  $fio,
    ) {
        $this->parsers = [$csas, $fio];
    }

    /**
     * @return array{
     *   fetched:int, processed:int, matched:int, duplicate:int,
     *   errors:int, skipped_no_supplier:int, skipped_no_parser:int,
     *   by_bank:array<string,int>, by_supplier:array<int,int>
     * }
     */
    public function run(): array
    {
        $stats = [
            'fetched'             => 0,
            'processed'           => 0,
            'matched'             => 0,
            'duplicate'           => 0,
            'errors'              => 0,
            'skipped_no_supplier' => 0,
            'skipped_no_parser'   => 0,
            'by_bank'             => [],
            'by_supplier'         => [],
        ];

        if (!(bool) $this->config->get('payment_email_scan.enabled', false)) {
            $this->logger->info('payment_email_scan: disabled v cfg, skip');
            return $stats;
        }

        $errorFolder     = (string) $this->config->get('payment_email_scan.error_folder', 'INBOX.Errors');
        $processedFolder = (string) $this->config->get('payment_email_scan.processed_folder', 'INBOX.Processed');
        $maxPerRun       = (int)    $this->config->get('payment_email_scan.max_per_run', 50);

        $messages = $this->imap->fetchUnseen($maxPerRun);
        $stats['fetched'] = count($messages);

        foreach ($messages as $msg) {
            $supplierId = $this->resolveSupplier($msg);
            if ($supplierId === null) {
                $this->logger->warning('payment_email_scan: neznámý alias → INBOX.Errors', [
                    'uid' => $msg->uid, 'route_addresses' => $msg->routeAddresses,
                ]);
                $this->safeMove($msg->uid, $errorFolder);
                $stats['skipped_no_supplier']++;
                continue;
            }

            $parser = $this->resolveParser($msg);
            if ($parser === null) {
                $this->logger->warning('payment_email_scan: neznámá banka → INBOX.Errors', [
                    'uid' => $msg->uid, 'from' => $msg->fromAddress,
                ]);
                $this->safeMove($msg->uid, $errorFolder);
                $stats['skipped_no_parser']++;
                continue;
            }

            $bodyHash = hash('sha256', $msg->messageId !== '' ? $msg->messageId : $msg->bodyHtml);
            if ($this->isDuplicate($bodyHash)) {
                $this->logger->info('payment_email_scan: duplicate → INBOX.Processed', [
                    'uid' => $msg->uid, 'message_id' => $msg->messageId,
                ]);
                $this->safeMove($msg->uid, $processedFolder);
                $stats['duplicate']++;
                continue;
            }

            try {
                $parsed = $parser->parse($msg);
            } catch (\Throwable $e) {
                $this->logger->warning('payment_email_scan: parse failed → INBOX.Errors', [
                    'uid' => $msg->uid, 'parser' => $parser->name(), 'error' => $e->getMessage(),
                ]);
                $this->safeMove($msg->uid, $errorFolder);
                $stats['errors']++;
                continue;
            }

            try {
                $matchResult = $this->persistAndMatch($supplierId, $parser->name(), $msg, $bodyHash, $parsed);
            } catch (\Throwable $e) {
                // Persist crash je vážný — log error a NEMOVE, aby šlo zkoušet znovu po fixu.
                $this->logger->error('payment_email_scan: persist failed (NOT moved)', [
                    'uid' => $msg->uid, 'message_id' => $msg->messageId, 'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
                continue;
            }

            $this->safeMove($msg->uid, $processedFolder);
            $stats['processed']++;
            if ($matchResult['matched']) {
                $stats['matched']++;
            }
            $stats['by_bank'][$parser->name()]   = ($stats['by_bank'][$parser->name()] ?? 0) + 1;
            $stats['by_supplier'][$supplierId]   = ($stats['by_supplier'][$supplierId] ?? 0) + 1;
        }

        $this->imap->disconnect();
        return $stats;
    }

    private function resolveSupplier(EmailMessage $msg): ?int
    {
        if (empty($msg->routeAddresses)) return null;
        // Lookup proti všem adresám z mailu — vrátíme první, která sedí na nějaký alias.
        // Důležité: catch-all schránka má Delivered-To = mailbox owner (NE alias), alias bývá v To.
        $placeholders = implode(',', array_fill(0, count($msg->routeAddresses), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, payment_email_alias FROM supplier WHERE payment_email_alias IN ({$placeholders}) LIMIT 1"
        );
        $stmt->execute($msg->routeAddresses);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? (int) $row['id'] : null;
    }

    private function resolveParser(EmailMessage $msg): ?BankParserInterface
    {
        foreach ($this->parsers as $p) {
            if ($p->supports($msg)) return $p;
        }
        return null;
    }

    private function isDuplicate(string $hash): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM bank_statements WHERE file_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Vytvoří synthetic statement (1 řádek) + 1 transakci a zavolá matcher.
     *
     * @return array{statement_id:int, transaction_id:int, matched:bool}
     */
    private function persistAndMatch(
        int $supplierId,
        string $parserName,
        EmailMessage $msg,
        string $bodyHash,
        ParsedTransaction $tx,
    ): array {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $sourceMeta = json_encode([
                'parser'           => $parserName,
                'message_id'       => $msg->messageId,
                'route_addresses'  => $msg->routeAddresses,
                'from'             => $msg->fromAddress,
                'subject'          => $msg->subject,
                'received_at'      => $msg->receivedAt->format('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $statementDate = $tx->postedAt->format('Y-m-d');

            $pdo->prepare(
                'INSERT INTO bank_statements
                     (file_name, file_hash, source, supplier_id, source_meta,
                      account_number, bank_code, currency, statement_date,
                      transaction_count, matched_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)'
            )->execute([
                'email:' . substr($msg->messageId !== '' ? $msg->messageId : $bodyHash, 0, 240),
                $bodyHash,
                'email',
                $supplierId,
                $sourceMeta,
                $tx->recipientAccount,
                $tx->bankCode,
                $tx->currency,
                $statementDate,
            ]);
            $statementId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO bank_transactions
                     (statement_id, posted_at, amount, currency, variable_symbol, constant_symbol, specific_symbol,
                      counterparty_account, counterparty_bank, counterparty_name, description, bank_ref)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $statementId,
                $statementDate,
                $tx->amount,
                $tx->currency,
                $tx->variableSymbol,
                $tx->constantSymbol,
                $tx->specificSymbol,
                $tx->counterpartyAccount !== '' ? $tx->counterpartyAccount : null,
                $tx->counterpartyBankCode,
                null,
                $tx->message,
                null,
            ]);
            $transactionId = (int) $pdo->lastInsertId();

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Match běží MIMO transakci — jeho UPDATE i side-effect (proforma → final draft) má vlastní commit.
        $matchResult = $this->matcher->match($transactionId);
        $isMatched = in_array($matchResult['status'] ?? '', ['auto_exact', 'auto_partial'], true);
        if ($isMatched) {
            $pdo->prepare('UPDATE bank_statements SET matched_count = 1 WHERE id = ?')->execute([$statementId]);
        }

        return ['statement_id' => $statementId, 'transaction_id' => $transactionId, 'matched' => $isMatched];
    }

    private function safeMove(int $uid, string $folder): void
    {
        try {
            $this->imap->moveTo($uid, $folder);
        } catch (\Throwable $e) {
            $this->logger->warning('payment_email_scan: IMAP MOVE selhal', [
                'uid' => $uid, 'target' => $folder, 'error' => $e->getMessage(),
            ]);
        }
    }
}
