<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Customer;
use Cleat\Http\CheckoutPage;
use Cleat\Http\InvoicePayPage;
use Cleat\Mail\Messages;
use Cleat\PaymentLink;
use Cleat\Tests\Support\TestCase;
use DOMDocument;
use DOMXPath;

/**
 * Self-check 17 (the automated half): render every template in every state
 * and confirm each class used is a public class in the installed Deck
 * package, or a .cleat-* class defined in Cleat's own scoped styles.
 */
final class TemplatesTest extends TestCase
{
    public function test_every_class_exists_in_the_installed_deck_package(): void
    {
        $deck = $this->deckPublicClasses();
        $this->assertGreaterThan(800, count($deck), 'read the real Deck API list');

        $unknown = [];
        foreach ($this->renderEverything() as $name => $html) {
            foreach ($this->classesIn($html) as $class) {
                if (!isset($deck[$class]) && !str_starts_with($class, 'cleat-')) {
                    $unknown[] = "$name: .$class";
                }
            }
        }
        $this->assertSame([], array_values(array_unique($unknown)));
    }

    public function test_every_cleat_class_is_defined_and_every_css_variable_exists_in_deck(): void
    {
        $deckCss = (string) file_get_contents(dirname(__DIR__, 2) . '/vendor/echodial/deck/dist/deck.css');
        preg_match_all('/(--[a-z0-9-]+)\s*:/', $deckCss, $defined);
        $tokens = array_flip($defined[1]);

        foreach ($this->renderEverything() as $name => $html) {
            preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $html, $styles);
            $css = implode("\n", $styles[1]);
            preg_match_all('/var\((--[a-z0-9-]+)/', $css, $used);
            foreach (array_unique($used[1]) as $var) {
                $this->assertArrayHasKey($var, $tokens, "$name uses $var, which Deck does not define");
            }
            foreach ($this->classesIn($html) as $class) {
                if (str_starts_with($class, 'cleat-') && !in_array($class, ['cleat-pad', 'cleat-big'], true)) {
                    $this->assertStringContainsString('.' . $class, $css, "$name uses .$class without defining it");
                }
            }
        }
    }

    public function test_pages_parse_as_html_and_carry_a_viewport(): void
    {
        foreach ($this->renderEverything() as $name => $html) {
            $doc = new DOMDocument();
            $this->assertTrue(@$doc->loadHTML($html), $name);
            if (!str_starts_with($name, 'email')) {
                $this->assertStringContainsString('width=device-width, initial-scale=1', $html, $name);
            }
            $this->assertStringNotContainsString('<?', $html, "$name leaked PHP");
        }
    }

    public function test_rendered_card_form_only_posts_the_token(): void
    {
        $this->reconfigure(['gateway' => 'authorizenet', 'authorizenet' => ['transaction_key' => 'SECRET-TXN-KEY-123', 'login_id' => 'LOGIN-ID-1', 'client_key' => 'PUBLIC-CLIENT-KEY-1', 'sandbox' => true]]);
        $html = $this->renderEverything()['pay_invoice:pay'];
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xp = new DOMXPath($doc);
        $named = [];
        foreach ($xp->query('//form[@id="cleat-pay-form"]//*[@name]') as $el) {
            $named[] = $el->getAttribute('name');
        }
        sort($named);
        $this->assertSame(['_csrf', 'dataDescriptor', 'dataValue', 'payment_method', 'payment_method', 'save_card'], $named);
        foreach ($xp->query('//*[@data-cleat-card]') as $el) {
            $this->assertFalse($el->hasAttribute('name'));
        }
        $this->assertSame(4, $xp->query('//*[@data-cleat-card]')->length);
        $this->assertStringContainsString('https://jstest.authorize.net/v1/Accept.js', $html);
        $this->assertStringContainsString('"apiLoginID":"LOGIN-ID-1"', $html);
        $this->assertStringContainsString('"clientKey":"PUBLIC-CLIENT-KEY-1"', $html);
        $this->assertStringNotContainsString('SECRET-TXN-KEY-123', $html);
    }

    /** @return array<string, string> */
    private function renderEverything(): array
    {
        static $cache = [];
        $mode = (string) \Cleat\Cleat::config('gateway');
        if (isset($cache[$mode])) {
            return $cache[$mode];
        }
        TestDatabaseReset::run($this->pdo);
        $this->monthlyPrice('team', 2900, trialDays: 14, product: $this->product('Team plan'));
        $this->oneTimePrice('ebook', 1900, $this->product('Field guide'));
        $customer = Customer::create(['email' => 'sam@example.com', 'name' => 'Sam Rivera']);
        $customer->updatePaymentMethod(self::opaque('tok_visa'));
        $invoice = $customer->newInvoice()->addItem('Website redesign', 240000)->addItem('Hosting', 2500, 12)->memo('Net 14.')->tax(19600)->dueIn(14)->finalize();

        $pay = new InvoicePayPage('s');
        $out = [];
        $out['pay_invoice:pay'] = $pay->show($invoice->public_token)->body;
        $out['pay_invoice:error'] = $pay->submit($invoice->public_token, ['_csrf' => (new \Cleat\Http\Csrf(self::APP_KEY))->issue($invoice->public_token, 's', $this->clock->now()), 'payment_method' => 'new', 'dataDescriptor' => 'x', 'dataValue' => 'tok_decline'], '1.1.1.1')->body;
        $out['invoice:open'] = $invoice->render();

        $held = Customer::create(['email' => 'held@example.com'])->newInvoice()->addItem('Order', 5000)->finalize();
        $out['pay_invoice:review'] = $pay->submit($held->public_token, [
            '_csrf' => (new \Cleat\Http\Csrf(self::APP_KEY))->issue($held->public_token, 's', $this->clock->now()),
            'payment_method' => 'new',
        ] + self::opaque('tok_held'), '1.1.1.2')->body;
        $this->assertStringContainsString('Payment received for review', $out['pay_invoice:review']);
        $out['pay_invoice:receipt'] = $pay->submit($invoice->public_token, ['_csrf' => (new \Cleat\Http\Csrf(self::APP_KEY))->issue($invoice->public_token, 's', $this->clock->now()), 'payment_method' => 'saved'], '1.1.1.3')->body;
        $out['pay_invoice:paid'] = $pay->show($invoice->public_token)->body;
        $out['invoice:paid'] = $invoice->refresh()->render();
        $out['error:404'] = $pay->show(str_repeat('0', 64))->body;

        $checkout = new CheckoutPage('s');
        $trialLink = PaymentLink::create('team', ['allow_quantity_change' => true, 'collect_phone' => true]);
        $onceLink = PaymentLink::create('ebook');
        $out['checkout:trial'] = $checkout->show($trialLink->public_token)->body;
        $out['checkout:once'] = $checkout->show($onceLink->public_token)->body;
        $csrf = (new \Cleat\Http\Csrf(self::APP_KEY))->issue($onceLink->public_token, 's', $this->clock->now());
        $out['checkout:invalid'] = $checkout->submit($onceLink->public_token, ['_csrf' => $csrf, 'email' => 'bad'], '1.1.1.4')->body;
        $out['checkout_success:paid'] = $checkout->submit($onceLink->public_token, ['_csrf' => $csrf, 'email' => 'buyer@example.com', 'name' => 'B'] + self::opaque('tok_visa'), '1.1.1.5')->body;
        $csrfT = (new \Cleat\Http\Csrf(self::APP_KEY))->issue($trialLink->public_token, 's', $this->clock->now());
        $out['checkout_success:trial'] = $checkout->submit($trialLink->public_token, ['_csrf' => $csrfT, 'email' => 'trial@example.com', 'name' => 'T', 'phone' => '555 010 0000', 'quantity' => '2'] + self::opaque('tok_visa'), '1.1.1.6')->body;

        $this->mailer->clear();
        $open = $customer->newInvoice()->addItem('Retainer', 90000)->finalize();
        Messages::invoice($open, $customer);
        Messages::paymentFailed($open, $customer, $this->clock->now()->modify('+1 day'));
        Messages::receipt($invoice, $customer, $invoice->charges()[0] ?? null);
        $trial = \Cleat\Subscription::fromRow($this->pdo->query('SELECT * FROM cleat_subscriptions ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC));
        Messages::trialStarted($trial, $customer);
        foreach ($this->mailer->sent as $i => $mail) {
            $out['email:' . $i] = $mail['html'];
        }
        return $cache[$mode] = $out;
    }

    /** @return list<string> */
    private function classesIn(string $html): array
    {
        preg_match_all('/\bclass="([^"]*)"/', $html, $m);
        $classes = [];
        foreach ($m[1] as $attr) {
            foreach (preg_split('/\s+/', trim($attr)) ?: [] as $class) {
                if ($class !== '') {
                    $classes[$class] = true;
                }
            }
        }
        return array_keys($classes);
    }

    /** @return array<string, true> */
    private function deckPublicClasses(): array
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vendor/echodial/deck/dist/api-buckets.json'), true);
        return array_fill_keys($json['buckets']['public'], true);
    }
}

/** @internal */
final class TestDatabaseReset
{
    public static function run(\PDO $pdo): void
    {
        \Cleat\Tests\Support\TestDatabase::truncate($pdo);
    }
}
