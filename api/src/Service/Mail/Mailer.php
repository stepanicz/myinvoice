<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailTemplateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Wrapper nad Symfony Mailer + Twig pro renderování šablon.
 *
 * Použití:
 *   $mailer->sendTemplate('password_reset', 'cs', ['user@example.com'], ['name' => 'Jan Novák', 'resetLink' => '...']);
 *
 * Šablony jsou v api/templates/email/<code>.<lang>.{html,txt}.twig.
 */
final class Mailer
{
    private ?SymfonyMailer $mailer = null;
    private ?Environment $twig = null;
    private ?array $supplierFooter = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly Connection $db,
        private readonly EmailTemplateRepository $templates,
    ) {}

    /**
     * @param string[]      $to
     * @param array<string,mixed> $vars
     * @param string[]      $cc
     * @param string[]      $bcc
     * @param array<int,array{path:string,name:string,contentType:string}> $attachments
     */
    public function sendTemplate(
        string $code,
        string $locale,
        array $to,
        array $vars,
        ?string $subjectOverride = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
    ): void {
        $twig = $this->twig();

        $vars['locale'] = $locale;
        if (!isset($vars['supplier'])) {
            $vars['supplier'] = $this->loadSupplierFooter();
        }

        // Pokud je v DB override, vyrenderuj přímo ze stringu (vyšší priorita než file).
        $dbTpl = $this->templates->find($code, $locale)
              ?? $this->templates->find($code, 'cs');

        if ($dbTpl !== null) {
            // DB šablona je editovatelná adminem — sandboxujeme proti SSTI
            $sandbox = $this->sandboxedTwig();
            $rawSubject = $subjectOverride ?? $dbTpl['subject'];
            $vars['subject'] = $sandbox->createTemplate($rawSubject)->render($vars);
            $html = $sandbox->createTemplate($dbTpl['body_html'])->render($vars);
            $text = $sandbox->createTemplate($dbTpl['body_text'])->render($vars);
        } else {
            $htmlTemplate = "{$code}.{$locale}.html.twig";
            $textTemplate = "{$code}.{$locale}.txt.twig";
            if (!$twig->getLoader()->exists($htmlTemplate)) {
                $htmlTemplate = "{$code}.cs.html.twig";
                $textTemplate = "{$code}.cs.txt.twig";
            }
            if (!isset($vars['subject'])) {
                $vars['subject'] = $subjectOverride ?? $this->defaultSubject($code, $locale);
            }
            // Subject z defaultSubject() i subject_override z API může obsahovat Twig syntax
            // (např. "Faktura {{ invoice.varsymbol }} — {{ supplier.display_name }}").
            $rawSubject = (string) $vars['subject'];
            if (str_contains($rawSubject, '{{') || str_contains($rawSubject, '{%')) {
                $vars['subject'] = $this->sandboxedTwig()->createTemplate($rawSubject)->render($vars);
            }
            $html = $twig->render($htmlTemplate, $vars);
            $text = $twig->render($textTemplate, $vars);
        }

        // From: per-supplier override (vars['supplier'].email + display_name) > globální cfg
        $globalFromEmail = (string) $this->config->get('smtp.from_email');
        $globalFromName  = (string) $this->config->get('smtp.from_name');
        $supplier = is_array($vars['supplier'] ?? null) ? $vars['supplier'] : null;
        $fromName = $globalFromName;
        if ($supplier !== null) {
            $supName = (string) ($supplier['display_name'] ?? $supplier['company_name'] ?? '');
            if ($supName !== '') $fromName = $supName;
        }

        $email = (new Email())
            ->from(new Address($globalFromEmail, $fromName))
            ->subject((string) $vars['subject'])
            ->html($html)
            ->text($text);

        foreach ($to as $addr)  $email->addTo($addr);
        foreach ($cc as $addr)  $email->addCc($addr);
        foreach ($bcc as $addr) $email->addBcc($addr);

        // Reply-To: per-supplier override (supplier.email) > globální cfg.smtp.reply_to_email
        $replyEmail = '';
        $replyName  = '';
        if ($supplier !== null && !empty($supplier['email']) && filter_var($supplier['email'], FILTER_VALIDATE_EMAIL)) {
            $replyEmail = (string) $supplier['email'];
            $replyName  = (string) ($supplier['display_name'] ?? $supplier['company_name'] ?? '');
        } else {
            $replyEmail = (string) $this->config->get('smtp.reply_to_email', '');
            $replyName  = (string) $this->config->get('smtp.reply_to_name', '');
        }
        if ($replyEmail !== '') {
            $email->replyTo(new Address($replyEmail, $replyName));
        }

        foreach ($attachments as $att) {
            $email->attachFromPath($att['path'], $att['name'], $att['contentType']);
        }

        // DKIM signer
        if ($this->config->get('smtp.dkim.enabled', false)) {
            $keyPath = (string) $this->config->get('smtp.dkim.private_key_path', '');
            if (is_file($keyPath)) {
                $signer = new DkimSigner(
                    'file://' . $keyPath,
                    (string) $this->config->get('smtp.dkim.domain'),
                    (string) $this->config->get('smtp.dkim.selector'),
                    [],
                    (string) $this->config->get('smtp.dkim.passphrase', ''),
                );
                $email = $signer->sign($email);
            } else {
                $this->logger->warning('DKIM enabled, ale private key neexistuje: ' . $keyPath);
            }
        }

        $this->mailer()->send($email);
    }

    private function mailer(): SymfonyMailer
    {
        if ($this->mailer === null) {
            $dsn = $this->buildDsn();
            $transport = Transport::fromDsn($dsn);
            $this->mailer = new SymfonyMailer($transport);
        }
        return $this->mailer;
    }

    private function buildDsn(): string
    {
        $host = (string) $this->config->get('smtp.host');
        $port = (int) $this->config->get('smtp.port', 25);
        $authEnabled = (bool) $this->config->get('smtp.auth_enabled', false);
        $user = (string) $this->config->get('smtp.user', '');
        $pass = (string) $this->config->get('smtp.pass', '');
        $encryption = (string) $this->config->get('smtp.encryption', '');
        $verifyPeer = (bool) $this->config->get('smtp.verify_peer', true);

        $userPart = '';
        if ($authEnabled && $user !== '') {
            $userPart = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
        }

        $params = [];
        // encryption: ssl (port 465 implicit TLS), tls (STARTTLS), '' = plain
        if ($encryption === 'tls') {
            // STARTTLS — Symfony to defaultně udělá pro port 587
        }
        if ($encryption === '') {
            // Plain — disable peer verify implicitly
            $verifyPeer = false;
        }
        if (!$verifyPeer) {
            $params[] = 'verify_peer=0';
        }

        $query = $params ? '?' . implode('&', $params) : '';

        return sprintf('smtp://%s%s:%d%s', $userPart, $host, $port, $query);
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email');
            $this->twig = new Environment($loader, [
                'autoescape' => 'html',
                'cache'      => false,
                'strict_variables' => false,
            ]);
        }
        return $this->twig;
    }

    private ?Environment $sandboxTwig = null;

    /**
     * Sandboxovaný Twig pro renderování DB šablon — chrání proti SSTI:
     * povoleny jen základní tagy, filtry a accessory na safe variables.
     * Bez funkcí (range, dump, attribute) a bez method calls mimo allow-list.
     */
    private function sandboxedTwig(): Environment
    {
        if ($this->sandboxTwig === null) {
            $allowedTags = ['if', 'for', 'set', 'spaceless', 'extends', 'block', 'include', 'apply', 'verbatim'];
            $allowedFilters = [
                'escape', 'e', 'raw', 'default', 'date', 'number_format',
                'upper', 'lower', 'capitalize', 'title', 'trim', 'replace',
                'length', 'first', 'last', 'join', 'split', 'nl2br',
                'abs', 'round', 'format',
            ];
            $allowedFunctions = ['date', 'min', 'max'];
            $allowedMethods = []; // žádné method calls na objektech
            $allowedProperties = []; // všechny array klíče OK, jen property accesy zakázané
            $policy = new SecurityPolicy($allowedTags, $allowedFilters, $allowedMethods, $allowedProperties, $allowedFunctions);

            $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email');
            $this->sandboxTwig = new Environment($loader, [
                'autoescape' => 'html',
                'cache'      => false,
                'strict_variables' => false,
            ]);
            $this->sandboxTwig->addExtension(new SandboxExtension($policy, true)); // sandboxed=true
        }
        return $this->sandboxTwig;
    }

    /**
     * Načte data pro patičku emailu — fallback pro non-invoice templates (password_reset apod).
     * Použije MIN(id) supplier — primární / „system default" branding.
     *
     * Pro invoice/reminder emaily caller (InvoiceEmailVarsBuilder) předává
     * `vars['supplier']` z konkrétní faktury (přes invoice.supplier_id) — Mailer pak nevolá tuto metodu.
     * Cached na instance lifetime.
     */
    private function loadSupplierFooter(): ?array
    {
        if ($this->supplierFooter !== null) {
            return $this->supplierFooter ?: null;
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                        s.email, s.phone, s.web, co.name_cs AS country
                   FROM supplier s
              LEFT JOIN countries co ON co.id = s.country_id
                  WHERE s.id = (SELECT MIN(id) FROM supplier)'
            );
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->supplierFooter = $row !== false ? $row : [];
            return $this->supplierFooter ?: null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load supplier footer: ' . $e->getMessage());
            $this->supplierFooter = [];
            return null;
        }
    }

    private function defaultSubject(string $code, string $locale): string
    {
        $subjects = [
            'cs' => [
                'password_reset'    => 'Obnova hesla — MyInvoice.cz',
                'invoice_send'      => 'Faktura — MyInvoice.cz',
                'invoice_reminder'  => 'Upomínka — MyInvoice.cz',
                'proforma_reminder' => 'Připomínka zálohy — MyInvoice.cz',
            ],
            'en' => [
                'password_reset'    => 'Password reset — MyInvoice.cz',
                'invoice_send'      => 'Invoice — MyInvoice.cz',
                'invoice_reminder'  => 'Reminder — MyInvoice.cz',
                'proforma_reminder' => 'Advance payment reminder — MyInvoice.cz',
            ],
        ];
        return $subjects[$locale][$code] ?? ($subjects['cs'][$code] ?? 'MyInvoice.cz');
    }
}
