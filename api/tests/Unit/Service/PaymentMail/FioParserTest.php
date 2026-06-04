<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\PaymentMail;

use MyInvoice\Service\PaymentMail\EmailMessage;
use MyInvoice\Service\PaymentMail\Parser\FioParser;
use PHPUnit\Framework\TestCase;

final class FioParserTest extends TestCase
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

    private function makeMessage(string $bodyText, string $subject = 'Fio banka - prijem na konte'): EmailMessage
    {
        return new EmailMessage(
            uid: 1,
            messageId: '<test@fio.cz>',
            routeAddresses: ['payment-fio@uctostepanovi.cz'],
            fromAddress: 'automat@fio.cz',
            subject: $subject,
            bodyHtml: '',
            bodyText: $bodyText,
            receivedAt: new \DateTimeImmutable('2026-05-15 09:19:39'),
            rawHeaders: [],
        );
    }

    public function testSupportsFioSender(): void
    {
        $p = new FioParser();
        self::assertTrue($p->supports($this->makeMessage('', 'Fio banka - prijem na konte')));
        self::assertTrue($p->supports($this->makeMessage('', 'Fio banka - vydaj na konte')));
        self::assertFalse($p->supports(new EmailMessage(
            uid: 2, messageId: '', routeAddresses: [], fromAddress: 'noreply@csas.cz',
            subject: 'Přišla platba', bodyHtml: '', bodyText: '', rawHeaders: [],
            receivedAt: new \DateTimeImmutable(),
        )));
    }

    public function testSupportsViaSenderDomain(): void
    {
        $p = new FioParser();
        self::assertTrue($p->supports(new EmailMessage(
            uid: 3, messageId: '', routeAddresses: [], fromAddress: 'automat@fio.cz',
            subject: 'whatever', bodyHtml: '', bodyText: '', rawHeaders: [],
            receivedAt: new \DateTimeImmutable(),
        )));
    }

    public function testParsesIncomingPayment(): void
    {
        $msg = $this->makeMessage($this->fixture('fio_prijem.txt'), 'Fio banka - prijem na konte');
        $tx = (new FioParser())->parse($msg);

        self::assertSame('2010', $tx->bankCode);
        self::assertSame('2302394278', $tx->recipientAccount);
        self::assertSame('131-1909450227', $tx->counterpartyAccount);
        self::assertSame('0100', $tx->counterpartyBankCode);
        self::assertSame('7060234951', $tx->variableSymbol);
        self::assertSame('308', $tx->constantSymbol);
        self::assertNull($tx->specificSymbol);     // SS: 0 → null
        self::assertSame('3630.00', $tx->amount);
        self::assertSame('CZK', $tx->currency);
        self::assertSame('incoming', $tx->direction);
        self::assertSame('ÚČETNÍ ŠTĚPÁN', $tx->message);
        self::assertSame('2026-05-15 09:19:39', $tx->postedAt->format('Y-m-d H:i:s'));
    }

    public function testParsesOutgoingPaymentAsNegativeAmount(): void
    {
        $msg = $this->makeMessage($this->fixture('fio_vydaj.txt'), 'Fio banka - vydaj na konte');
        $tx = (new FioParser())->parse($msg);

        self::assertSame('2010', $tx->bankCode);
        self::assertSame('2302394278', $tx->recipientAccount);
        self::assertSame('9516410247', $tx->counterpartyAccount);
        self::assertSame('0100', $tx->counterpartyBankCode);
        self::assertSame('220260884', $tx->variableSymbol);
        self::assertSame('0308', $tx->constantSymbol);
        self::assertNull($tx->specificSymbol);     // SS: (prázdné) → null
        self::assertSame('-43.00', $tx->amount);
        self::assertSame('outgoing', $tx->direction);
        self::assertNull($tx->message);
    }

    public function testCounterpartyTextDescriptionYieldsEmpty(): void
    {
        $body = "Výdaj na kontě: 2302394278\nČástka: 960,23\nVS: \nAktuální zůstatek: 40 391,59\nProtiúčet: platba kartou\nSS: \nKS:";
        $tx = (new FioParser())->parse($this->makeMessage($body, 'Fio banka - vydaj na konte'));

        self::assertSame('', $tx->counterpartyAccount);
        self::assertNull($tx->counterpartyBankCode);
        self::assertNull($tx->variableSymbol);
        self::assertSame('-960.23', $tx->amount);
    }

    public function testThrowsWhenAmountMissing(): void
    {
        $body = "Příjem na kontě: 2302394278\nProtiúčet: 167705416/0300";
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/částku/');
        (new FioParser())->parse($this->makeMessage($body));
    }
}
