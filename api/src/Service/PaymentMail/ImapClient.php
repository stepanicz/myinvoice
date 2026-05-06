<?php

declare(strict_types=1);

namespace MyInvoice\Service\PaymentMail;

use MyInvoice\Infrastructure\Config\Config;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

/**
 * Tenký wrapper nad webklex/php-imap. Drží jen API, které ingestor potřebuje:
 *   connect → fetchUnseen → moveTo(Processed|Errors) → disconnect.
 *
 * Konfigurace v cfg.payment_email_scan.*.
 */
final class ImapClient
{
    private ?Client $client = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function connect(): void
    {
        if ($this->client !== null && $this->client->isConnected()) {
            return;
        }
        $cm = new ClientManager();
        $client = $cm->make([
            'host'           => (string) $this->config->get('payment_email_scan.imap_host'),
            'port'           => (int)    $this->config->get('payment_email_scan.imap_port', 993),
            'encryption'     => (string) $this->config->get('payment_email_scan.imap_encryption', 'ssl'),
            'validate_cert'  => (bool)   $this->config->get('payment_email_scan.imap_validate_cert', true),
            'username'       => (string) $this->config->get('payment_email_scan.imap_user'),
            'password'       => (string) $this->config->get('payment_email_scan.imap_pass'),
            'protocol'       => 'imap',
            'authentication' => null,
            // svethostingu používá '.' jako hierarchy separator (cyrus / dovecot styl).
            // Webklex default je '/' (Gmail-styl), což u nás láme `getFolderByPath('INBOX.Errors')`.
            'options' => ['delimiter' => '.'],
        ]);
        $client->connect();
        $this->client = $client;
    }

    /**
     * Vytáhne UNSEEN zprávy z INBOX (max $limit ks). Ingestor sám rozhodne, kam zprávu
     * po zpracování přesune; my jen markujeme \Seen IMAP příznak až MOVE.
     *
     * @return list<EmailMessage>
     */
    public function fetchUnseen(int $limit): array
    {
        $this->connect();
        $inbox = (string) $this->config->get('payment_email_scan.inbox', 'INBOX');
        $folder = $this->client->getFolderByPath($inbox, soft_fail: true);
        if ($folder === null) {
            throw new \RuntimeException("IMAP folder neexistuje: {$inbox}");
        }

        // Záměrně leaveUnread() — \Seen flag nastavíme až po MOVE, ať při crashi
        // mezi fetch a save nevypadne mail z UNSEEN listu (znovu se vyřeší příště).
        $messages = $folder->query()->unseen()->leaveUnread()->limit($limit)->get();

        $out = [];
        foreach ($messages as $msg) {
            try {
                $out[] = $this->toDto($msg);
            } catch (\Throwable $e) {
                $this->logger->warning('payment_email_scan: nečitelná zpráva', [
                    'uid'   => method_exists($msg, 'getUid') ? $msg->getUid() : null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $out;
    }

    public function moveTo(int $uid, string $folderName): void
    {
        $this->connect();
        $inbox = (string) $this->config->get('payment_email_scan.inbox', 'INBOX');
        $folder = $this->client->getFolderByPath($inbox, soft_fail: true);
        if ($folder === null) {
            throw new \RuntimeException("IMAP folder neexistuje: {$inbox}");
        }

        // getMessage(uid) v 6.x: použij query()->getMessageByUid()
        $msg = $folder->query()->getMessageByUid($uid);
        if ($msg === null) {
            $this->logger->warning('payment_email_scan: zpráva pro MOVE nenalezena', [
                'uid' => $uid, 'target' => $folderName,
            ]);
            return;
        }

        // Zajistit, že cílová složka existuje (idempotent — IMAP server vrátí "ALREADY EXISTS").
        $this->ensureFolder($folderName);

        $msg->move($folderName, expunge: true);
    }

    public function disconnect(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->disconnect();
            } catch (\Throwable $e) {
                $this->logger->debug('IMAP disconnect warning: ' . $e->getMessage());
            }
            $this->client = null;
        }
    }

    private function ensureFolder(string $name): void
    {
        try {
            $existing = $this->client->getFolderByPath($name, soft_fail: true);
            if ($existing !== null) {
                return;
            }
        } catch (\Throwable) {
            // pokračuj na CREATE
        }
        try {
            // Webklex 6.x: createFolder($path, $expunge = true). Některé servery vrací
            // "ALREADY EXISTS" když složka existuje, jiné vrací OK. Obojí je úspěch — log info,
            // ne debug, ať to v produkci uvidíme když selže ze závažného důvodu.
            $this->client->createFolder($name, false);
            $this->logger->info("IMAP: vytvořena složka {$name}");
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'already') !== false || stripos($msg, 'exist') !== false) {
                return;
            }
            $this->logger->warning("IMAP createFolder({$name}) selhal: " . $msg);
        }
    }

    private function toDto(Message $msg): EmailMessage
    {
        $header = $msg->getHeader();

        // Sbíráme VŠECHNY adresy kandidující na supplier alias matching. Reálné maily přicházejí
        // přes řetězec relays (banka → gmail → svethostingu), kde alias zůstává schovaný v různých
        // headers. Webklex sám multi-occurrence Delivered-To neparsuje, tak parsneme raw sami:
        //   - každý `Delivered-To:` (Gmail forward přidá svůj, svethostingu LDA přidá mailbox owner)
        //   - `X-Forwarded-To:` / `X-Original-To:` / `Envelope-To:` (různé MTA)
        //   - `Return-Path: <localpart+caf_=ALIAS_LOCAL=ALIAS_DOMAIN@gmail.com>` (Gmail VERP)
        //   - `To:` headeru (banka napsala přímo)
        $routeAddresses = $this->extractRouteAddresses($header->raw ?? '', $msg);

        // From: webklex getFrom() vrací array of Address (nullable u některých zpráv).
        $fromList = $msg->getFrom();
        $fromAddress = '';
        if (is_array($fromList) && !empty($fromList)) {
            $first = $fromList[0];
            $fromAddress = strtolower((string) ($first->mail ?? ''));
        }
        if ($fromAddress === '') {
            // Fallback: vytáhnout z raw From: hlavičky
            $fromAttr = $header->get('from');
            if ($fromAttr !== null) {
                $raw = is_object($fromAttr) && method_exists($fromAttr, 'toString') ? (string) $fromAttr->toString() : (string) $fromAttr;
                $fromAddress = strtolower($this->extractEmail($raw));
            }
        }

        $messageIdAttr = $header->get('message-id');
        $messageId = $messageIdAttr !== null
            ? trim((string) (is_object($messageIdAttr) && method_exists($messageIdAttr, 'toString') ? $messageIdAttr->toString() : $messageIdAttr))
            : '';

        $subject = (string) $msg->getSubject();

        $date = $msg->getDate();
        if ($date === null) {
            $receivedAt = new \DateTimeImmutable('now');
        } elseif ($date instanceof \DateTimeInterface) {
            $receivedAt = \DateTimeImmutable::createFromInterface($date);
        } else {
            // webklex Attribute → toDate() vrací Carbon
            $dateStr = is_object($date) && method_exists($date, 'toString') ? (string) $date->toString() : (string) $date;
            try {
                $receivedAt = new \DateTimeImmutable($dateStr);
            } catch (\Throwable) {
                $receivedAt = new \DateTimeImmutable('now');
            }
        }

        // Flat dump headers pro audit (jen běžné klíče — ne celé raw, kvůli velikosti).
        $rawHeaders = [];
        foreach (['delivered-to', 'x-original-to', 'envelope-to', 'to', 'from', 'subject', 'message-id', 'date', 'return-path'] as $k) {
            $attr = $header->get($k);
            if ($attr === null) continue;
            $val = is_object($attr) && method_exists($attr, 'toString') ? (string) $attr->toString() : (string) $attr;
            $rawHeaders[$k] = trim($val);
        }

        return new EmailMessage(
            uid:            (int) $msg->getUid(),
            messageId:      $messageId,
            routeAddresses: $routeAddresses,
            fromAddress:    $fromAddress,
            subject:        $subject,
            bodyHtml:       (string) $msg->getHTMLBody(),
            bodyText:       (string) $msg->getTextBody(),
            receivedAt:     $receivedAt,
            rawHeaders:     $rawHeaders,
        );
    }

    /** Vytáhne email z formátu "Display Name <a@b.cz>" nebo z holého "a@b.cz". */
    private function extractEmail(string $raw): string
    {
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            return trim($m[1]);
        }
        return trim($raw);
    }

    /**
     * Z raw headerů vytáhne všechny adresy, které kandidují na alias matching.
     * Vrací list lowercase emailů, deduplikovaný, bez prázdných.
     *
     * @return list<string>
     */
    private function extractRouteAddresses(string $rawHeaders, Message $msg): array
    {
        $out = [];

        // Multi-occurrence headers (jeden header se může opakovat). Webklex je sloučí, raw je drží.
        // POZOR: hlavička může pokračovat přes víc řádků (folding) — header value končí prvním
        // řádkem, který nezačíná whitespace. Pro náš účel (jednořádkové emaily) tohle stačí.
        if (preg_match_all('/^(?:Delivered-To|X-Original-To|X-Forwarded-To|Envelope-To):\s*(.+)$/im', $rawHeaders, $m)) {
            foreach ($m[1] as $line) {
                $email = strtolower($this->extractEmail(trim($line)));
                if ($email !== '') $out[] = $email;
            }
        }

        // Gmail VERP v Return-Path: <localpart+caf_=ALIAS_LOCAL=ALIAS_DOMAIN@gmail.com>
        // Gmail tento formát používá pro forward — alias je zakódovaný v lokální části.
        if (preg_match('/^Return-Path:\s*<?([^>\r\n]+)>?\s*$/im', $rawHeaders, $rp)) {
            $verp = trim($rp[1]);
            if (preg_match('/\+caf_=([^=]+)=([^@]+)@/i', $verp, $vm)) {
                $out[] = strtolower($vm[1] . '@' . $vm[2]);
            }
        }

        // To: header — webklex parsuje address list, vyzobeme přes ->getTo()
        $toList = $msg->getTo();
        if (is_array($toList)) {
            foreach ($toList as $addr) {
                if (is_object($addr) && !empty($addr->mail)) {
                    $out[] = strtolower((string) $addr->mail);
                }
            }
        }

        // Cc: bonus (banky občas pošlou cc na alias)
        $ccList = $msg->getCc();
        if (is_array($ccList)) {
            foreach ($ccList as $addr) {
                if (is_object($addr) && !empty($addr->mail)) {
                    $out[] = strtolower((string) $addr->mail);
                }
            }
        }

        return array_values(array_unique(array_filter($out, fn(string $a) => $a !== '')));
    }
}
