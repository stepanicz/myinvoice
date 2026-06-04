<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\PaymentMail;

use MyInvoice\Service\PaymentMail\EmailMessage;
use MyInvoice\Service\PaymentMail\Parser\CsasParser;
use PHPUnit\Framework\TestCase;

final class CsasParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/fixtures/' . $name;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Fixture not readable: {$path}");
        }
        return $content;
    }

    private function makeMessage(string $html, string $from = 'ceskasporitelna@csas.cz'): EmailMessage
    {
        return new EmailMessage(
            uid: 1,
            messageId: '<test@csas.cz>',
            routeAddresses: ['payment-2441e2e361@uctostepanovi.cz'],
            fromAddress: $from,
            subject: 'Přišla platba',
            bodyHtml: $html,
            bodyText: '',
            receivedAt: new \DateTimeImmutable('2026-05-06 10:28:14'),
            rawHeaders: [],
        );
    }

    public function testSupportsCsasSender(): void
    {
        $p = new CsasParser();
        self::assertTrue($p->supports($this->makeMessage('', 'ceskasporitelna@csas.cz')));
        self::assertTrue($p->supports($this->makeMessage('', 'noreply@csas.cz')));
        self::assertFalse($p->supports($this->makeMessage('', 'noreply@fio.cz')));
    }

    public function testParsesIncomingPaymentNotification(): void
    {
        $msg = $this->makeMessage($this->fixture('csas_payment_received.html'));
        $tx = (new CsasParser())->parse($msg);

        self::assertSame('0800', $tx->bankCode);
        self::assertSame('7055602369', $tx->recipientAccount);
        self::assertSame('2403144606', $tx->counterpartyAccount);
        self::assertSame('2010', $tx->counterpartyBankCode);
        self::assertSame('2026169', $tx->variableSymbol);
        self::assertSame('0308', $tx->constantSymbol);
        self::assertNull($tx->specificSymbol);
        self::assertSame('2500.00', $tx->amount);
        self::assertSame('CZK', $tx->currency);
        self::assertSame('incoming', $tx->direction);
        self::assertSame('Faktura 2026169', $tx->message);
        self::assertSame('2026-05-06 10:28:14', $tx->postedAt->format('Y-m-d H:i:s'));
    }

    public function testParsesWithoutVariableSymbolAsNull(): void
    {
        $html = '<div>na účet 7055602369/0800 právě dorazila platba ve výši 2&nbsp;500,00 Kč.</div>'
              . '<div>Číslo účtu: 7055602369/0800</div>'
              . '<div>Částka v měně účtu: 2&nbsp;500,00 Kč</div>';
        $tx = (new CsasParser())->parse($this->makeMessage($html));
        self::assertNull($tx->variableSymbol);
        self::assertSame('2500.00', $tx->amount);
    }

    public function testThrowsWhenAmountMissing(): void
    {
        $html = '<div>Číslo účtu: 7055602369/0800</div>'
              . '<div>Variabilní symbol: 2026169</div>';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/částku/');
        (new CsasParser())->parse($this->makeMessage($html));
    }

    public function testParsesOutgoingDirectionAsNegativeAmount(): void
    {
        $html = '<div>Číslo účtu: 7055602369/0800</div>'
              . '<div>Směr platby: odchozí</div>'
              . '<div>Částka v měně účtu: 1&nbsp;234,56 Kč</div>'
              . '<div>Variabilní symbol: 999</div>';
        $tx = (new CsasParser())->parse($this->makeMessage($html));
        self::assertSame('outgoing', $tx->direction);
        self::assertSame('-1234.56', $tx->amount);
    }
}
